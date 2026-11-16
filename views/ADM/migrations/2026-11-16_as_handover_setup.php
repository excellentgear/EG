<?php
/**
 * 2026-11-16 AS負責人交接頁：登記選單頁面＋建立三張 AS 流程地圖（白話圖說＋頁面連結）。
 * 可重複執行（頁面與地圖名稱已存在就略過）。只用 CLI 執行：php 本檔
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/flow_map_lib.php';
$db = (new DBConnection())->getPDO();
fmap_ensure_schema($db);

// 1) 選單頁面（測試功能群組）
$st = $db->prepare("SELECT page_id FROM system_module_pages WHERE page_url LIKE '%/as_handover.php'");
$st->execute();
if (!$st->fetchColumn()) {
    $gid = (int)$db->query("SELECT group_id FROM system_module_groups WHERE group_name='測試功能'")->fetchColumn();
    $so  = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM system_module_pages")->fetchColumn();
    $db->prepare("INSERT INTO system_module_pages (page_name, page_url, sort_order, group_id) VALUES ('AS負責人交接與權限','/EGsystem/views/ADM/as_handover.php',?,?)")
       ->execute([$so, $gid]);
    echo "已登記選單頁面\n";
}

function pid(PDO $db, string $file): ?int {
    $st = $db->prepare("SELECT page_id FROM system_module_pages WHERE page_url LIKE ? ORDER BY page_id LIMIT 1");
    $st->execute(['%/' . $file]);
    $v = $st->fetchColumn();
    return $v ? (int)$v : null;
}

/** nodes: [key=>[file|null, label, x, y, w, h, color]]；edges: [[fromKey,toKey,label]] */
function mkMap(PDO $db, string $name, array $nodes, array $edges, int $sort): void {
    $st = $db->prepare("SELECT map_id FROM flow_map WHERE map_name=?");
    $st->execute([$name]);
    if ($st->fetchColumn()) { echo "略過（已存在）：$name\n"; return; }
    $W = 1400; $H = 700;
    foreach ($nodes as $n) { $W = max($W, $n[2] + $n[4] + 60); $H = max($H, $n[3] + $n[5] + 60); }
    $db->prepare("INSERT INTO flow_map (map_name, sort_order, canvas_w, canvas_h, created_at) VALUES (?,?,?,?,NOW())")->execute([$name, $sort, $W, $H]);
    $mid = (int)$db->lastInsertId();
    $ids = [];
    foreach ($nodes as $k => $n) {
        $page = $n[0] ? pid($db, $n[0]) : null;
        $db->prepare("INSERT INTO flow_map_node (map_id, page_id, label, x, y, w, h, color) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$mid, $page, $n[1], $n[2], $n[3], $n[4], $n[5], $n[6]]);
        $ids[$k] = (int)$db->lastInsertId();
    }
    foreach ($edges as $e) {
        $db->prepare("INSERT INTO flow_map_edge (map_id, from_node, to_node, label) VALUES (?,?,?,?)")->execute([$mid, $ids[$e[0]], $ids[$e[1]], $e[2] ?? '']);
    }
    echo "已建立：$name\n";
}

$T = 0; // 說明方塊用 c2、步驟 c0、重點 c1
mkMap($db, 'AS文件制修訂流程（負責人日常）', [
    'a' => [null, "① 先決定要做什麼\n新文件／改版／廢止", 40, 40, 230, 70, 1],
    'b' => ['doc_apply.php', "② 填「文件制、修申請單」", 330, 40, 230, 70, 0],
    'c' => [null, "③ 會簽＋四格簽核\n申請人→單位主管→管理代表→核准", 620, 40, 250, 70, 1],
    'd' => ['as_document_management.php', "④ AS文件管理\n上傳新版或線上編輯", 930, 40, 230, 70, 0],
    'e' => [null, "⑤ 線上版：送簽→發行\n（上傳檔案版：申請單核准即可）", 930, 330, 250, 70, 1],
    'f' => ['as_document_management.php', "⑥ 結構總覽：確認編號、版次、日期", 620, 330, 250, 70, 0],
    'g' => ['as_flow_guide.php', "⑦ 不確定時：先看流程說明手冊", 330, 330, 230, 70, 0],
    'na' => [null, "編號與版次系統會自動帶，不要自己編。母文件選錯，編號就會跟著錯。", 40, 130, 230, 100, 2],
    'nb' => [null, "必填欄位沒填會紅字擋下。「制訂」的表單沒有版次；之後依序 A→B→C。", 330, 130, 230, 100, 2],
    'nc' => [null, "簽核人由系統依組織自動找。找不到人、或要換人，請找 AS 管理員，不要請別人代簽。", 620, 130, 250, 100, 2],
    'nd' => [null, "文件制修申請單一定要附。新版檔案不可拿舊版重傳。", 930, 130, 230, 100, 2],
    'ne' => [null, "送簽通過後要按「發行」，版次才正式生效。簽核流程與自動簽核是管理員的設定，負責人不能改。", 930, 420, 250, 110, 2],
    'nf' => [null, "發行後到結構總覽看一眼：編號、版次、日期對不對，再列印給稽核看。", 620, 420, 250, 100, 2],
    'ng' => [null, "遇到「不能退回／不能刪」的情況，先問 AS 管理員，不要自己想辦法繞。", 330, 420, 230, 100, 2],
], [['a','b',''], ['b','c','送出'], ['c','d','核准後'], ['d','e',''], ['e','f','發行'], ['f','g','']], 10);

mkMap($db, 'AS文件排程與內部稽核', [
    'a' => ['as_schedule.php', "① AS文件排程\n看這個月該做什麼、哪些逾期", 40, 40, 250, 70, 0],
    'b' => [null, "② 負責課室依頻率\n填寫對應的表單（各模組頁面）", 350, 40, 250, 70, 1],
    'c' => ['internal_audit.php', "③ 內部稽核\n年度計畫→通知單→查檢表", 660, 40, 250, 70, 0],
    'd' => [null, "④ 發現不符合\n開「不符合通知單」", 970, 40, 230, 70, 1],
    'e' => ['meeting_record.php', "會議紀錄（事前／結束會議）", 660, 330, 250, 70, 0],
    'na' => [null, "紅色逾期要處理；「無法判定」是系統查不到紀錄，不等於沒做，請補登記。", 40, 130, 250, 100, 2],
    'nb' => [null, "頻率與負責課室在 AS文件管理「⚙」設定，排程頁也能就地改。", 350, 130, 250, 100, 2],
    'nc' => [null, "每個稽核期間事前會議、結束會議各一份就夠。", 660, 130, 250, 100, 2],
    'nd' => [null, "受稽單位回覆改善後，稽核員驗證才能結案。", 970, 130, 230, 100, 2],
], [['a','b',''], ['b','c','年度稽核'], ['c','d','不合格'], ['c','e','開會']], 11);

mkMap($db, 'AS權限與交接（管理員用）', [
    'a' => ['as_handover.php', "① AS負責人交接與權限\n換人、暫停、還原", 40, 40, 260, 70, 0],
    'b' => ['print_sign_log.php', "② 列印與簽核紀錄\n查誰簽了什麼", 380, 40, 250, 70, 0],
    'c' => ['user_permissions.php', "③ 權限設定（進階）\n特殊個案才用", 710, 40, 250, 70, 0],
    'na' => [null, "AS管理員：完整權限，可多人。AS負責人：日常維護，拿不到自動簽核、補登、管理員功能，只能一人。", 40, 130, 260, 120, 2],
    'nb' => [null, "懷疑有人亂簽核：到本頁「活動紀錄」看；紅旗標＝自己送自己簽／短時間連簽／退回沒寫原因。", 380, 130, 250, 120, 2],
    'nc' => [null, "一般交接請用①，不要直接到③逐項勾，免得權限散落各處、事後查不出來。", 710, 130, 250, 120, 2],
], [['a','b','查紀錄'], ['b','c','特殊個案']], 12);
echo "完成\n";
