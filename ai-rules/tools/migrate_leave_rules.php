<?php
/**
 * 請假：假別規則欄位＋喪假親等表＋提前結束欄位 的一次性遷移（2026-07-31）
 *
 * 全部是「新增型」DDL：ADD COLUMN（可為 NULL 或有預設值）與 CREATE TABLE，
 * 不改既有欄位型態、不刪任何東西。可重複執行（先查 information_schema，已存在就跳過）。
 *
 * 為什麼不走 ai-rules/tools/sql.php：那支工具刻意拒絕 ALTER/CREATE，要人先問過使用者。
 * 使用者已於 2026-07-31 確認（並先做了整庫備份 EGsystem_20260731_010536.sql）。
 *
 * 用法：& C:\MAMP\bin\php\php8.3.1\php.exe ai-rules\tools\migrate_leave_rules.php [--dry]
 */
mb_internal_encoding('UTF-8');
if (PHP_SAPI !== 'cli') exit('CLI only');
$DRY = in_array('--dry', $argv, true);

$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4", "EG-TS2024", "excell30367593",
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function hasCol(PDO $db, string $t, string $c): bool {
    $st = $db->prepare("SELECT 1 FROM information_schema.columns
                        WHERE table_schema='EGsystem' AND table_name=? AND column_name=? LIMIT 1");
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
}
function hasTable(PDO $db, string $t): bool {
    $st = $db->prepare("SELECT 1 FROM information_schema.tables
                        WHERE table_schema='EGsystem' AND table_name=? LIMIT 1");
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}
function run(PDO $db, string $label, string $sql, bool $dry): void {
    if ($dry) { echo "  [DRY] $label\n       $sql\n"; return; }
    $db->exec($sql);
    echo "  [OK ] $label\n";
}

echo "== 1. leave_type：假別規則參數（每個假別各自設定，不用全域設定）==\n";
$typeCols = [
    // 規則種類：空＝無特殊規則。bereavement=喪假、parental=育嬰類（留停與時假共用同一套判定）
    'rule_kind' => "ALTER TABLE leave_type ADD COLUMN rule_kind VARCHAR(20) NOT NULL DEFAULT ''
        COMMENT '特殊規則種類：空=無；bereavement=喪假(依親等上限+事件日起N日內請畢)；parental=育嬰類(子女滿N歲前+每一子女上限)'",
    // 上限值與單位分開存：留停講「2 年」、育嬰假講「幾天/幾小時」，硬塞成同一單位人事看不懂
    'rule_max_value' => "ALTER TABLE leave_type ADD COLUMN rule_max_value DECIMAL(8,2) NULL
        COMMENT '每一事件/每一子女的請假上限值；NULL=不限。喪假留 NULL 改依 leave_bereavement_grade 的親等天數'",
    'rule_max_unit' => "ALTER TABLE leave_type ADD COLUMN rule_max_unit VARCHAR(10) NOT NULL DEFAULT 'day'
        COMMENT 'rule_max_value 的單位：day/month/hour'",
    'rule_deadline_days' => "ALTER TABLE leave_type ADD COLUMN rule_deadline_days INT NULL
        COMMENT '自事件日(死亡日)起 N 日內須請畢；NULL=不限。喪假預設 100'",
    'rule_child_age_years' => "ALTER TABLE leave_type ADD COLUMN rule_child_age_years DECIMAL(3,1) NULL
        COMMENT '子女滿 N 歲前才可請；NULL=不限。育嬰類預設 3'",
    'rule_min_days' => "ALTER TABLE leave_type ADD COLUMN rule_min_days DECIMAL(6,1) NULL
        COMMENT '單次請假不得低於 N 日；NULL=不限。育嬰留停預設 30'",
    'rule_note' => "ALTER TABLE leave_type ADD COLUMN rule_note VARCHAR(255) NOT NULL DEFAULT ''
        COMMENT '顯示在申請頁的規則說明（人事自行維護）'",
];
foreach ($typeCols as $c => $sql) {
    if (hasCol($db, 'leave_type', $c)) { echo "  [SKIP] leave_type.$c 已存在\n"; continue; }
    run($db, "leave_type.$c", $sql, $DRY);
}

echo "== 2. leave_bereavement_grade：喪假親等對照表 ==\n";
if (hasTable($db, 'leave_bereavement_grade')) {
    echo "  [SKIP] leave_bereavement_grade 已存在\n";
} else {
    run($db, 'CREATE TABLE leave_bereavement_grade', "
        CREATE TABLE leave_bereavement_grade (
          id INT AUTO_INCREMENT PRIMARY KEY,
          grade_name VARCHAR(150) NOT NULL COMMENT '亡故親屬關係（例：父母、養父母、繼父母、配偶）',
          max_days DECIMAL(4,1) NOT NULL DEFAULT 0 COMMENT '該親等可請的喪假上限（日）',
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=停用（不出現在申請頁下拉，舊單仍讀得到名稱）',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='喪假親等與天數上限；預設值依勞工請假規則第3條，人事可自行調整'", $DRY);
    if (!$DRY) {
        $ins = $db->prepare("INSERT INTO leave_bereavement_grade (grade_name, max_days, sort_order) VALUES (?,?,?)");
        foreach ([
            ['父母、養父母、繼父母、配偶', 8, 10],
            ['祖父母、子女、配偶之父母、配偶之養父母或繼父母', 6, 20],
            ['曾祖父母、兄弟姊妹、配偶之祖父母', 3, 30],
        ] as $g) $ins->execute($g);
        echo "  [OK ] 帶入 3 筆親等預設（8/6/3 天，依勞工請假規則第 3 條）\n";
    }
}

echo "== 3. leave_request：規則相關欄位 ==\n";
$reqCols = [
    'rel_grade_id' => "ALTER TABLE leave_request ADD COLUMN rel_grade_id INT NULL
        COMMENT '喪假：亡故親屬關係，對應 leave_bereavement_grade.id'",
    'deceased_date' => "ALTER TABLE leave_request ADD COLUMN deceased_date DATE NULL
        COMMENT '喪假：死亡日期。用來算 N 日內請畢的期限，並把同一次治喪的多張單歸戶累計'",
    'child_birthday' => "ALTER TABLE leave_request ADD COLUMN child_birthday DATE NULL
        COMMENT '育嬰類：子女出生日期。同一子女的累計上限依此歸戶（使用者指定不必填子女姓名）'",
];
foreach ($reqCols as $c => $sql) {
    if (hasCol($db, 'leave_request', $c)) { echo "  [SKIP] leave_request.$c 已存在\n"; continue; }
    run($db, "leave_request.$c", $sql, $DRY);
}

echo "== 4. leave_request：提前結束（留停提早復職）欄位 ==\n";
$earlyCols = [
    'orig_end_datetime' => "ALTER TABLE leave_request ADD COLUMN orig_end_datetime DATETIME NULL
        COMMENT '提前結束前的原結束時間；有值即代表這張單被提前結束過（原訂到X、實際到Y）'",
    'early_end_at' => "ALTER TABLE leave_request ADD COLUMN early_end_at DATETIME NULL COMMENT '提前結束的操作時間'",
    'early_end_by' => "ALTER TABLE leave_request ADD COLUMN early_end_by INT NULL COMMENT '提前結束的操作人 user.id'",
    'early_end_reason' => "ALTER TABLE leave_request ADD COLUMN early_end_reason TEXT NULL COMMENT '提前結束原因（例：提早復職）'",
];
foreach ($earlyCols as $c => $sql) {
    if (hasCol($db, 'leave_request', $c)) { echo "  [SKIP] leave_request.$c 已存在\n"; continue; }
    run($db, "leave_request.$c", $sql, $DRY);
}

echo "\n== 完成後現況 ==\n";
foreach (['leave_type', 'leave_request', 'leave_bereavement_grade'] as $t) {
    if (!hasTable($db, $t)) { echo "  {$t}：不存在\n"; continue; }
    $cols = $db->query("SELECT column_name FROM information_schema.columns
                        WHERE table_schema='EGsystem' AND table_name='$t' ORDER BY ordinal_position")
               ->fetchAll(PDO::FETCH_COLUMN);
    echo "  {$t}（" . count($cols) . " 欄）：" . implode(', ', $cols) . "\n";
}
if (hasTable($db, 'leave_bereavement_grade')) {
    foreach ($db->query("SELECT id, grade_name, max_days FROM leave_bereavement_grade ORDER BY sort_order")
                ->fetchAll(PDO::FETCH_ASSOC) as $g) {
        echo "    #{$g['id']} {$g['grade_name']} → {$g['max_days']} 天\n";
    }
}
echo $DRY ? "\n（乾跑，未實際變更）\n" : "\n遷移完成。\n";
