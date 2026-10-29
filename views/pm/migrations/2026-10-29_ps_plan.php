<?php
// =============================================================================
// 待加工排程「生管預排／排序紀錄」資料表
//  - bom_ing.plan_seq     生管在「調整順序-未指派」排定的加工順序。與 processing_sequence（機台上的排序、
//                         現場拉到機台就會被清掉／重編）分開存放，所以現場人員怎麼拖卡片都不會動到生管的順序。
//  - bom_ing.plan_urgent  生管手動設定的急件（原有的急件設定來自 bom.priority_type／訂單，不動）。
//  - bom_ing.pre_plan     預排：生管還沒移轉到這一關之前，先把這張 BOM 的這道製程排進未指派清單。
//  - ps_plan_log          每次「實際有變動」的順序存檔留一筆快照（誰、何時、哪個製程大類、BOM＋製程編號＋順序）。
//  純新增欄位／資料表，可重複執行。MySQL 沒有 ADD COLUMN IF NOT EXISTS，先 SHOW COLUMNS 再 ALTER。
//  執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\pm\migrations\2026-10-29_ps_plan.php
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$have = [];
foreach ($pdo->query("SHOW COLUMNS FROM bom_ing")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = 1;
$cols = [
    'plan_seq'    => "INT NULL DEFAULT NULL COMMENT '生管排定的加工順序（與 processing_sequence 分開）'",
    'plan_urgent' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '生管手動設定急件'",
    'pre_plan'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '預排：尚未移轉到此關卡就先排進未指派'",
];
foreach ($cols as $name => $def) {
    if (isset($have[$name])) { echo "$name 已存在，略過\n"; continue; }
    $pdo->exec("ALTER TABLE bom_ing ADD COLUMN `$name` $def");
    echo "已新增欄位 $name\n";
}

$pdo->exec("CREATE TABLE IF NOT EXISTS ps_plan_log (
    log_id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    process_type_id INT NOT NULL COMMENT '製程大類',
    saved_at        DATETIME NOT NULL COMMENT '存檔時間',
    saved_by        INT NULL COMMENT '修改人 user.id',
    saved_by_name   VARCHAR(60) NULL,
    n_items         INT NOT NULL DEFAULT 0,
    items           MEDIUMTEXT NOT NULL COMMENT 'JSON：[[bom_ing_fid, bom, process_no, 急件0/1, 預排0/1], ...] 依排定順序',
    KEY idx_type_time (process_type_id, saved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='待加工排程：生管排序紀錄（每次實際有變動才留一筆）'");
echo "ps_plan_log OK\n";

// 既有資料回填：目前在未指派（沒有機台）而且已有人工序號的，視為生管已排好的順序
$n = $pdo->exec("UPDATE bom_ing SET plan_seq = processing_sequence
                  WHERE plan_seq IS NULL AND machine_id IS NULL AND processing_sequence IS NOT NULL AND processing_sequence > 0");
echo "回填 plan_seq：$n 列\n";
