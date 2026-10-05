<?php
/**
 * 2026-10-05_sip_packaging_split.php
 *
 * 使用者交辦：SIP 內混著的包裝檢驗項目（管理重點=包裝）要能在 CP（管制計畫）被歸到
 * 包裝那一列，而不是跟著整份 SIP 被算進它綁定的製程（如齒研）那一列。
 *
 * 做法（已與使用者討論定案，見對話紀錄）：不改 ss_item 加欄位，而是把包裝檢驗項目
 * 搬進一份獨立的「通用包裝 SIP」（scope='general'，kind='sip'，綁 process_no=168）；
 * control_plan_lib.php 的 cp_sip_items()／cp_ss_doc_exists() 已改成「精準 process_no
 * 比對找不到時，退回同一個製程大類（process_type_id）底下的通用 SIP」，所以 169（同屬
 * process_type_id=16「雷刻與包裝」）會自動退回這份文件，不必各自建一份、也不必寫死
 * 168/169 這兩個代號——以後現場再加新的包裝製程代號，只要掛進同一個 process_type 就會
 * 自動抓到，不必回頭改這份文件或任何程式。
 *
 * 三件事：
 *   ①建立這份通用包裝 SIP（找得到就沿用，不重複建）——刻意留 status='draft'，
 *     不可由程式自動核准，需要使用者本人走正常簽核流程。
 *   ②既有 SIP 裡混的包裝項目（ctrl_point='包裝' 且 q_char 命中兩種固定文字）：
 *     現有版次是 draft 的直接刪除那幾列；是 approved 的要先用 ss_ver_clone() 另開一個
 *     新草稿版次（舊的已核准版次完全不動、cur_ver_id 仍指向它，不影響現行 CP 內容），
 *     在新草稿版次裡才刪，新版次同樣留著等人工審核——**本腳本絕不自動核准任何版次**。
 *
 * 預設只試算不寫入；要真的寫請加 --run。可重複執行（已處理過的第二次會是 0 筆）。
 *
 *   php views/QA/migrations/2026-10-05_sip_packaging_split.php
 *   php views/QA/migrations/2026-10-05_sip_packaging_split.php --run
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/common/_config.php';
require_once $root . '/src/common/DBConnection.php';
require_once $root . '/src/common/sopsip_lib.php';
require_once $root . '/src/common/control_plan_lib.php';

$run = in_array('--run', $argv ?? [], true);
$db  = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const PACK_PROCESS_NO = 168;   // 新通用包裝 SIP 要綁的製程代號（169 靠製程大類自動退回找到它）
const SUPER_UID = 1;
const SUPER_UNAME = 'e';

// 要搬出去的兩列固定內容（21 份舊文件逐字相同，見討論時的 SQL 查證）
$PACK_ITEMS = [
    ['ctrl_point' => '包裝', 'q_char' => '防鏽、防撞', 'owner' => '包裝',
     'method' => '依包裝指導書要求', 'tool_no' => 'N/A', 'freq' => '每顆',
     'note' => '若客戶有特殊要求則依客戶需求操作'],
    ['ctrl_point' => '包裝', 'q_char' => '包裝外箱貼出貨吊卡', 'owner' => '包裝',
     'method' => '目視', 'tool_no' => 'N/A', 'freq' => '每箱',
     'note' => '若客戶有提供則使用客戶的吊卡'],
];

echo ($run ? "=== 正式寫入模式 ===\n\n" : "=== 試算模式（加 --run 才會真的寫入）===\n\n");

/* ---------- ① 建立通用包裝 SIP（找得到就沿用） ---------- */
$st = $db->prepare("SELECT doc_id, cur_ver_id FROM ss_doc
                     WHERE kind='sip' AND scope='general' AND process_no=? AND is_deleted=0 LIMIT 1");
$st->execute([PACK_PROCESS_NO]);
$packDoc = $st->fetch(PDO::FETCH_ASSOC);

if ($packDoc) {
    echo "①通用包裝 SIP 已存在：doc_id={$packDoc['doc_id']}，不重複建立\n\n";
    $packDocId = (int)$packDoc['doc_id'];
} else {
    echo "①要建立新的通用包裝 SIP（process_no=" . PACK_PROCESS_NO . "）\n";
    if ($run) {
        $packDocId = ss_doc_save($db, [
            'kind' => 'sip', 'scope' => 'general', 'process_no' => PACK_PROCESS_NO,
            'title' => '包裝 通用檢驗指導書',
        ], SUPER_UID, SUPER_UNAME);
        $verId = ss_ver_create($db, $packDocId, ['form_date' => date('Y-m-d'), 'ver_no' => '01',
            'rev_note' => '初訂（由齒研等製程 SIP 內重複的包裝通用項目搬入合併）'], SUPER_UID);
        ss_items_replace($db, $verId, $PACK_ITEMS);
        echo "  已建立 doc_id={$packDocId}，ver_id={$verId}（status=draft，尚待人工審核核准）\n\n";
    } else {
        echo "  （試算模式不建立，--run 才會真的寫入）\n\n";
        $packDocId = 0;
    }
}

/* ---------- ② 找出所有仍混著這兩列包裝項目的 SIP 文件（含 part/general 任何 scope） ---------- */
$sql = "SELECT d.doc_id, d.title, d.scope, d.process_no, v.ver_id, v.status, v.ver_no,
               i.item_id, i.q_char
          FROM ss_doc d
          JOIN ss_ver v ON v.ver_id = d.cur_ver_id
          JOIN ss_item i ON i.ver_id = v.ver_id
         WHERE d.kind='sip' AND d.is_deleted=0 AND d.process_no<>" . PACK_PROCESS_NO . "
           AND i.ctrl_point='包裝'
           AND i.q_char IN ('防鏽、防撞','包裝外箱貼出貨吊卡')
         ORDER BY d.doc_id";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$byDoc = [];
foreach ($rows as $r) $byDoc[(int)$r['doc_id']][] = $r;

echo "②找到 " . count($byDoc) . " 份文件、共 " . count($rows) . " 列要搬出的包裝項目\n\n";

$draftN = 0; $approvedN = 0;
foreach ($byDoc as $docId => $items) {
    $one = $items[0];
    $isApproved = ((string)$one['status'] === 'approved');
    echo "  doc_id={$docId}　{$one['title']}　(process_no={$one['process_no']}, ver {$one['ver_no']}, "
        . ($isApproved ? 'approved' : 'draft') . ")，" . count($items) . " 列\n";

    if ($isApproved) {
        $approvedN++;
        if (!$run) continue;
        $newVerId = ss_ver_clone($db, (int)$one['ver_id'], [
            'rev_note' => '移除通用包裝檢驗項目（已改綁通用包裝 SIP），其餘內容未變。本版次尚待審核。',
        ], SUPER_UID);
        $delIds = $db->prepare("SELECT item_id FROM ss_item WHERE ver_id=? AND ctrl_point='包裝'
                                 AND q_char IN ('防鏽、防撞','包裝外箱貼出貨吊卡')");
        $delIds->execute([$newVerId]);
        $ids = $delIds->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $in2 = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM ss_item WHERE item_id IN ($in2)")->execute($ids);
        }
        echo "    -> 已複製成新草稿版次 ver_id={$newVerId}（舊版 approved 不動、cur_ver_id 仍指舊版），"
            . "刪除新版次裡的 " . count($ids) . " 列包裝項目\n";
    } else {
        $draftN++;
        if (!$run) continue;
        $ids = array_column($items, 'item_id');
        $in2 = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM ss_item WHERE item_id IN ($in2)")->execute($ids);
        echo "    -> 直接刪除 " . count($ids) . " 列（原版次本來就是 draft）\n";
    }
}

echo "\n統計：draft 直接刪除 {$draftN} 份、approved 另開草稿版次 {$approvedN} 份\n";
if (!$run) echo "\n（試算完畢，確認無誤後加 --run 真的寫入）\n";
else {
    echo "\n=== 完成，請注意以下尚待人工處理的事 ===\n";
    echo "1. 新建的通用包裝 SIP（doc_id={$packDocId}）目前是草稿，要到 sop_sip.php 走正常簽核流程核准，\n";
    echo "   CP（管制計畫）才會真的開始自動帶入包裝列的檢驗項目。\n";
    if ($approvedN > 0) {
        echo "2. 以上 {$approvedN} 份文件新增了草稿版次（移除包裝通用項目），原本已核准的版次完全沒有被動到、\n";
        echo "   現行 CP 內容不受影響；要讓包裝項目真的從這些文件消失，需要本人另外審核核准新版次。\n";
    }
}
