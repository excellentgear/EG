<?php
/**
 * 2026-10-05_sip_appearance_owner_qc.php
 *
 * 承接 2026-10-05_sip_packaging_split.php。使用者看畫面複核時追加交辦：
 *   ①「外觀」兩項（表面無刮傷/壓傷、齒面不能有凹坑/黑皮）**兩邊都要有**——
 *     留在各自的製程 SIP 裡（這是這個料號自己的品質特性），同時也複製一份進
 *     通用包裝 SIP（包裝人員出貨前本來就會一併目視檢查）。
 *   ②這兩項的擔當者從「包裝」改成「品管」、檢驗頻率從「每顆」改成跟「成品檢驗
 *     報告」那一項一樣的「依 3-QA-03規範」——使用者拍板連其他現有 SIP 裡相同的
 *     外觀項目一起改，不只改畫面上那一份。
 *   ③文字維持原樣不通用化（「齒面不能有凹坑/黑皮」照搬進包裝通用 SIP，即使它
 *     會套用到非齒輪料號）——使用者明確選擇不改文字。
 *
 * 全庫目前只有 5 份 SIP 文件有這兩項完全相同文字的「外觀」項目（doc 18/31/35/93/113）：
 *   - doc 31 現行版次本來就是 draft，直接改。
 *   - doc 18/35/93/113 現行版次是 approved，但上一支腳本已經各自開了一個移除包裝
 *     項目用的草稿版次（ver_id 130/131/132/133）——這裡在同一個草稿版次裡改，
 *     不再另開新版次（同一輪審核把兩件事一次送審）。approved 版次本身完全不動。
 *
 * 預設只試算不寫入；要真的寫請加 --run。可重複執行（已處理過的第二次會是 0 筆）。
 *
 *   php views/QA/migrations/2026-10-05_sip_appearance_owner_qc.php
 *   php views/QA/migrations/2026-10-05_sip_appearance_owner_qc.php --run
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/common/_config.php';
require_once $root . '/src/common/DBConnection.php';
require_once $root . '/src/common/sopsip_lib.php';

$run = in_array('--run', $argv ?? [], true);
$db  = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const NEW_OWNER = '品管';
const NEW_FREQ  = '依 3-QA-03規範';
const Q_CHARS   = ['表面無刮傷/壓傷', '齒面不能有凹坑/黑皮'];
const PACK_PROCESS_NO = 168;

echo ($run ? "=== 正式寫入模式 ===\n\n" : "=== 試算模式（加 --run 才會真的寫入）===\n\n");

/* ---------- ①②：既有「外觀」項目的擔當者/頻率改到位——approved 文件改在已存在的草稿版次上 ---------- */
// 對每一份文件：approved 的現行版次 -> 找它名下最新的那個草稿版次（上一支腳本建立的）；draft 的現行版次 -> 就是它自己
$st = $db->query("SELECT d.doc_id, d.title, d.cur_ver_id, v.status
                     FROM ss_doc d JOIN ss_ver v ON v.ver_id=d.cur_ver_id
                    WHERE d.kind='sip' AND d.is_deleted=0
                      AND EXISTS (SELECT 1 FROM ss_item i WHERE i.ver_id=d.cur_ver_id
                                   AND i.ctrl_point='外觀' AND i.q_char IN ('" . implode("','", Q_CHARS) . "'))
                    ORDER BY d.doc_id");
$docs = $st->fetchAll(PDO::FETCH_ASSOC);

$targets = [];   // doc_id => ver_id 要改的那個版次
foreach ($docs as $d) {
    if ((string)$d['status'] === 'draft') {
        $targets[(int)$d['doc_id']] = ['ver_id' => (int)$d['cur_ver_id'], 'title' => $d['title'], 'via' => '現行草稿'];
        continue;
    }
    // approved：找名下「最新的草稿版次」（上一支腳本 ss_ver_clone 建的那一份）
    $st2 = $db->prepare("SELECT ver_id FROM ss_ver WHERE doc_id=? AND status='draft' ORDER BY ver_id DESC LIMIT 1");
    $st2->execute([(int)$d['doc_id']]);
    $draftVerId = $st2->fetchColumn();
    if (!$draftVerId) {
        echo "！doc_id={$d['doc_id']}　{$d['title']}：現行版次是 approved 卻找不到既有草稿版次，略過（請人工確認）\n";
        continue;
    }
    $targets[(int)$d['doc_id']] = ['ver_id' => (int)$draftVerId, 'title' => $d['title'], 'via' => "approved 底下的草稿 ver_id={$draftVerId}"];
}

echo "①找到 " . count($targets) . " 份文件有外觀項目要改擔當者/頻率\n";
foreach ($targets as $docId => $t) {
    $st3 = $db->prepare("SELECT item_id, q_char, owner, freq FROM ss_item WHERE ver_id=? AND ctrl_point='外觀'
                           AND q_char IN ('" . implode("','", Q_CHARS) . "')");
    $st3->execute([$t['ver_id']]);
    $rows = $st3->fetchAll(PDO::FETCH_ASSOC);
    $need = array_values(array_filter($rows, fn($r) => $r['owner'] !== NEW_OWNER || $r['freq'] !== NEW_FREQ));
    echo "  doc_id={$docId}　{$t['title']}（{$t['via']}）：{$t['via']}裡 " . count($rows) . " 列，需要改 " . count($need) . " 列\n";
    if ($run && $need) {
        $ids = array_column($need, 'item_id');
        $in2 = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE ss_item SET owner=?, freq=? WHERE item_id IN ($in2)")
           ->execute(array_merge([NEW_OWNER, NEW_FREQ], $ids));
    }
}

/* ---------- ③：通用包裝 SIP（doc 117）補上這兩項外觀檢驗（文字照搬，擔當者/頻率用新值） ---------- */
echo "\n②通用包裝 SIP（process_no=" . PACK_PROCESS_NO . "）補上外觀兩項\n";
$st = $db->prepare("SELECT doc_id, cur_ver_id FROM ss_doc WHERE kind='sip' AND scope='general'
                     AND process_no=? AND is_deleted=0 LIMIT 1");
$st->execute([PACK_PROCESS_NO]);
$packDoc = $st->fetch(PDO::FETCH_ASSOC);
if (!$packDoc) {
    echo "  ！找不到通用包裝 SIP，請先跑 2026-10-05_sip_packaging_split.php\n";
} else {
    $packVerId = (int)$packDoc['cur_ver_id'];
    $st2 = $db->prepare("SELECT MAX(seq) FROM ss_item WHERE ver_id=?");
    $st2->execute([$packVerId]);
    $maxSeq = (int)$st2->fetchColumn();

    $st3 = $db->prepare("SELECT q_char FROM ss_item WHERE ver_id=? AND ctrl_point='外觀' AND q_char IN ('"
        . implode("','", Q_CHARS) . "')");
    $st3->execute([$packVerId]);
    $already = $st3->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff(Q_CHARS, $already));

    echo "  doc_id={$packDoc['doc_id']}（ver_id={$packVerId}）已有 " . count($already) . " 項，要補 " . count($missing) . " 項\n";
    if ($run) {
        $seq = $maxSeq;
        foreach ($missing as $qc) {
            $seq++;
            $db->prepare("INSERT INTO ss_item (ver_id, seq, ctrl_point, q_char, owner, method, tool_no, freq)
                          VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$packVerId, $seq, '外觀', $qc, NEW_OWNER, '目視', 'N/A', NEW_FREQ]);
            echo "    + 新增：外觀 / {$qc}\n";
        }
    }
}

if (!$run) echo "\n（試算完畢，確認無誤後加 --run 真的寫入）\n";
else echo "\n完成。approved 的原版次完全未被動到，相關草稿版次仍待人工審核核准。\n";
