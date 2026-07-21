<?php
/**
 * 批圖編輯器：把原本硬寫在 image_editor.php 的「內建常用標籤」種進資料庫，
 * 成為「技術部（department.id = 1）」的部門標籤（owner_type='dept', owner_dept_id=1）。
 *
 * 由來：使用者要求內建標籤不再全體可見，改成技術課（技術部 id=1）的部門標籤，
 *       由技術部成員在標籤庫「管理」跳窗維護。此檔即這批標籤的正式來源（canonical seed），
 *       可安全重複執行（依 label_name + owner_dept_id 判斷，已存在就跳過，不會重複種）。
 *
 * 用法：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\imgedit_seed_builtin_labels.php
 *
 * 注意：這些標籤是向量規格（spec_json），沒有內嵌圖片，所以不需要在 NAS 的 \標籤\D1 放任何實體檔；
 *       D1 只有在技術部標籤含圖片時才會用到（imgedit_label_sub 產生 D<部門id> 子資料夾）。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');

const TECH_DEPT_ID = 1;            // 技術部
const SEED_CREATED_BY = '系統內建';

// 與 image_editor.php 內 PRESET_LABELS 完全對應（spec 會被 makeLabelFromSpec() 還原成可編輯標籤）
$PRESETS = [
    ['name' => '齒研附P40報告',   'cat' => '齒研',     'spec' => ['kind' => 'box',   'text' => '齒研附 P40 報告']],
    ['name' => '齒底徑說明',       'cat' => '齒研',     'spec' => ['kind' => 'box',   'text' => "齒底徑 Ø\n(齒底確定有磨到此深度即可)", 'align' => 'left']],
    ['name' => '注意隆齒設定',     'cat' => '注意事項', 'spec' => ['kind' => 'box',   'text' => '注意隆齒設定']],
    ['name' => '注意結合要壓到底', 'cat' => '注意事項', 'spec' => ['kind' => 'box',   'text' => '注意結合要壓到底']],
    ['name' => '粗滾圖面',         'cat' => '滾齒',     'spec' => ['kind' => 'box',   'text' => '粗滾圖面']],
    ['name' => '鎖螺絲',           'cat' => '組裝',     'spec' => ['kind' => 'box',   'text' => '鎖螺絲']],
    ['name' => '攻牙用一般絲攻',   'cat' => '攻牙',     'spec' => ['kind' => 'inline', 'segs' => [['t' => '攻牙用', 'box' => false], ['t' => '一般', 'box' => true], ['t' => '絲攻', 'box' => false]]]],
    ['name' => '研磨記號 G＋▽▽▽', 'cat' => '加工符號', 'spec' => ['kind' => 'grind3', 'text' => 'G', 'bg' => 'transparent']],
    ['name' => '粗糙度記號 0.8＋G', 'cat' => '加工符號', 'spec' => ['kind' => 'rough', 'text' => 'G', 'val' => '0.8', 'bg' => 'transparent']],
    ['name' => '±0.02',            'cat' => '公差',     'spec' => ['kind' => 'plain', 'text' => '±0.02']],
    ['name' => 'JIS 2',            'cat' => '公差',     'spec' => ['kind' => 'plain', 'text' => 'JIS 2']],
    ['name' => '(  )齒研 滾/磨',   'cat' => '製程表格', 'spec' => ['kind' => 'table', 'title' => '(  )齒研', 'rows' => ['滾', '磨']]],
    ['name' => '(  )滾齒 滾',      'cat' => '製程表格', 'spec' => ['kind' => 'table', 'title' => '(  )滾齒', 'rows' => ['滾']]],
];

try {
    $db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
                  "EG-TS2024", "excell30367593",
                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB CONNECT ERROR: " . $e->getMessage() . "\n"); exit(3);
}

$chk = $db->prepare("SELECT label_id FROM imgedit_labels
                     WHERE label_name = ? AND owner_type = 'dept' AND owner_dept_id = ? LIMIT 1");
$ins = $db->prepare("INSERT INTO imgedit_labels
                     (label_name, category, tags, owner_type, owner_user_id, owner_dept_id, spec_json, created_by, created_at)
                     VALUES (?, ?, NULL, 'dept', NULL, ?, ?, ?, NOW())");

$inserted = 0; $skipped = 0; $log = [];
foreach ($PRESETS as $p) {
    $chk->execute([$p['name'], TECH_DEPT_ID]);
    if ($chk->fetchColumn()) { $skipped++; $log[] = "skip  ｜{$p['name']}（已存在）"; continue; }
    $spec = json_encode($p['spec'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ins->execute([$p['name'], $p['cat'], TECH_DEPT_ID, $spec, SEED_CREATED_BY]);
    $inserted++; $log[] = "add   ｜{$p['name']}（{$p['cat']}）id=" . $db->lastInsertId();
}

echo json_encode([
    'dept_id'  => TECH_DEPT_ID,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'total'    => count($PRESETS),
    'detail'   => $log,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
