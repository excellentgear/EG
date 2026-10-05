<?php
/**
 * 2026-10-05_sip_pack_tpl.php
 *
 * 使用者交辦：把「包裝 通用檢驗指導書」(doc_id=117) 的內容建立成製程大類「包裝」
 * 的「檢驗項目預設值範本」（ss_item_tpl，tpl_kind='proc'）。
 *
 * ss_item_tpl 跟 ss_doc 一樣只能綁單一 process_no（使用者實測發現畫面上的「製程」
 * 欄位無法同時勾 168 跟 169），所以範本一樣只建在 process_no=168（用量較大的代號）；
 * sopsip_lib.php 的 ss_default_items()／control_plan_lib.php 的 cp_sip_items()
 * 都已改成「精準 process_no 比對找不到時，退回同一個製程大類（process_type_id）
 * 底下別的代號」，169（與 168 同屬 process_type_id=16「雷刻與包裝」）會自動退回
 * 找到這份範本，不必也不能各建一份。
 *
 * 內容取自 doc_id=117 目前版次的全部項目（包裝兩項＋外觀兩項，含上一輪已改好的
 * 擔當者=品管／頻率=依3-QA-03規範）。
 *
 * 預設只試算不寫入；要真的寫請加 --run。可重複執行（ss_tpl_replace 本身是整批覆寫）。
 *
 *   php views/QA/migrations/2026-10-05_sip_pack_tpl.php
 *   php views/QA/migrations/2026-10-05_sip_pack_tpl.php --run
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/common/_config.php';
require_once $root . '/src/common/DBConnection.php';
require_once $root . '/src/common/sopsip_lib.php';
require_once $root . '/src/common/control_plan_lib.php';

$run = in_array('--run', $argv ?? [], true);
$db  = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const PACK_PROCESS_NO = 168;
const SUPER_UID = 1;

echo ($run ? "=== 正式寫入模式 ===\n\n" : "=== 試算模式（加 --run 才會真的寫入）===\n\n");

$st = $db->prepare("SELECT doc_id, cur_ver_id FROM ss_doc WHERE kind='sip' AND scope='general'
                     AND process_no=? AND is_deleted=0 LIMIT 1");
$st->execute([PACK_PROCESS_NO]);
$packDoc = $st->fetch(PDO::FETCH_ASSOC);
if (!$packDoc) { echo "找不到通用包裝 SIP（process_no=" . PACK_PROCESS_NO . "），請先建立。\n"; exit(1); }

$rows = ss_item_rows($db, (int)$packDoc['cur_ver_id']);
echo "取自 doc_id={$packDoc['doc_id']}（ver_id={$packDoc['cur_ver_id']}）共 " . count($rows) . " 項：\n";
foreach ($rows as $r) echo "  - {$r['ctrl_point']} / {$r['q_char']}　擔當者={$r['owner']}　頻率={$r['freq']}\n";

if ($run) {
    ss_tpl_replace($db, 'proc', PACK_PROCESS_NO, $rows, SUPER_UID);
    echo "\n已寫入 ss_item_tpl（tpl_kind=proc, process_no=" . PACK_PROCESS_NO . "）\n";
} else {
    echo "\n（試算完畢，確認無誤後加 --run 真的寫入）\n";
}
