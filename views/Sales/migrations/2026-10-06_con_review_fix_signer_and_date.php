<?php
/**
 * 2026-10-06_con_review_fix_signer_and_date.php
 *
 * 使用者回報（合約訂單審查表 2-SM-01-06）兩個既有 bug，連同舊資料一起修正：
 *
 * ①「管理員本人不在部門池內卻手動按『本課確認』」會把自己記成該部門的簽核人，不是該部門
 *    真正的人員——cnrv_dept_sign() 已補上跟 cnrv_decide()/cnrv_approve() 同一套「代簽記真人、
 *    管理員本人只留 proxy 欄位」規則，這支腳本把既有資料裡已經踩到這個 bug 的紀錄回頭修正：
 *    逐筆檢查 con_review_dept_sign 裡 is_auto_sign=0 AND is_backfill=0 的列，若紀錄的
 *    signed_by 不是該部門真正的人員（cnrv_can_fill_dept 判false）且是管理員身分，就改成
 *    這個部門真正的人員（cnrv_dept_pool 第一位），原本的 signed_by/signed_by_name 搬進
 *    is_proxy/proxy_uid/proxy_name。非管理員、或部門池本來就是空的（無法判斷真人是誰）
 *    一律跳過不動，印出來請人工確認。
 *
 * ②簽章日期原本是按下按鈕當下的真實時間（signed_at/sales_decided_at/gm_approved_at 的
 *    NOW()），應該一律是接單日期（業務日期，ai-rules/18 第4條／ai-rules/21）——三個寫入
 *    函式已補上 sign_date/sales_decided_date/gm_approved_date 三個新欄位，這支腳本把既有
 *    資料的這三欄回填成各自單據的 business_date（不論原本是 NULL 還是已經寫過其他值，
 *    一律覆蓋成 business_date，因為規則就是「永遠=接單日期」，沒有例外）。
 *    signed_at/sales_decided_at/gm_approved_at 本身完全不動，繼續留著「真的是什麼時候按的」
 *    這個稽核軌跡。
 *
 * 預設只試算不寫入；要真的寫請加 --run。可重複執行（已處理過的部分第二次會顯示「已正確」）。
 *
 *   php views/Sales/migrations/2026-10-06_con_review_fix_signer_and_date.php
 *   php views/Sales/migrations/2026-10-06_con_review_fix_signer_and_date.php --run
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/common/_config.php';
require_once $root . '/src/common/DBConnection.php';
require_once $root . '/src/common/con_review_lib.php';

$run = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
cnrv_ensure_schema($db);

echo "模式：" . ($run ? '真的寫入' : '只試算，不寫入（加 --run 才會真的改資料）') . "\n\n";

/* ========== ①部門簽核：代簽記真人 ========== */
echo "========== ① 部門簽核：修正「管理員代簽卻記成自己」 ==========\n";
$rows = $db->query(
    "SELECT s.id, s.doc_id, s.dept_id, s.signed_by, s.signed_by_name, dep.name AS dept_name, d.doc_no
     FROM con_review_dept_sign s
     JOIN con_review_doc d ON d.id=s.doc_id
     LEFT JOIN department dep ON dep.id=s.dept_id
     WHERE s.is_auto_sign=0 AND s.is_backfill=0 AND s.signed_by IS NOT NULL
     ORDER BY s.id"
)->fetchAll(PDO::FETCH_ASSOC);

$fixSigner = 0; $skipAdminButEmptyPool = 0; $skipNotAdmin = 0; $ok = 0;
foreach ($rows as $r) {
    $signedBy = (int)$r['signed_by'];
    $deptId = (int)$r['dept_id'];
    $canFillSelf = cnrv_can_fill_dept($db, $signedBy, $deptId, false);
    if ($canFillSelf) { $ok++; continue; }   // 本來就是部門真正的人，不是 bug

    $isAdmin = cnrv_uid_is_super_admin($db, $signedBy) || cnrv_has_role($db, $signedBy, ['con_review_admin']);
    if (!$isAdmin) {
        // 不是管理員、卻也不在部門池內——可能是部門主管後來換人，不是這次要修的 bug，列出來
        // 供人工確認，不自動動它。
        $skipNotAdmin++;
        echo "  [略過-非管理員] doc {$r['doc_no']} / {$r['dept_name']}：目前記 {$r['signed_by_name']}（非該部門現任人員、也非管理員，需人工確認）\n";
        continue;
    }
    $pool = cnrv_dept_pool($db, $deptId);
    if (!$pool) {
        $skipAdminButEmptyPool++;
        echo "  [略過-部門無人員] doc {$r['doc_no']} / {$r['dept_name']}：目前記管理員 {$r['signed_by_name']}，但該部門目前沒有任何人員可代，無法判斷真人是誰\n";
        continue;
    }
    $realId = (int)$pool[0]['id'];
    $realName = $pool[0]['user_cname'];
    echo "  [修正] doc {$r['doc_no']} / {$r['dept_name']}：{$r['signed_by_name']}（管理員代簽）→ {$realName}（該部門真正人員）\n";
    $fixSigner++;
    if ($run) {
        $db->prepare("UPDATE con_review_dept_sign SET signed_by=?,signed_by_name=?,is_proxy=1,proxy_uid=?,proxy_name=? WHERE id=?")
           ->execute([$realId, $realName, $signedBy, $r['signed_by_name'], $r['id']]);
    }
}
echo "小計：本來就正確 {$ok} 筆／需修正 {$fixSigner} 筆／部門無人員無法判斷 {$skipAdminButEmptyPool} 筆／非管理員需人工確認 {$skipNotAdmin} 筆\n\n";

/* ========== ②簽章日期：一律回填成業務日期（接單日期） ========== */
echo "========== ② 簽章日期：回填成各單據的業務日期（接單日期） ==========\n";

$dsRows = $db->query(
    "SELECT s.id, s.sign_date, d.business_date, d.doc_no, dep.name AS dept_name
     FROM con_review_dept_sign s
     JOIN con_review_doc d ON d.id=s.doc_id
     LEFT JOIN department dep ON dep.id=s.dept_id
     WHERE s.signed_by IS NOT NULL
     ORDER BY s.id"
)->fetchAll(PDO::FETCH_ASSOC);
$dsFix = 0; $dsOk = 0;
foreach ($dsRows as $r) {
    if ((string)$r['sign_date'] === (string)$r['business_date']) { $dsOk++; continue; }
    echo "  [部門簽核] doc {$r['doc_no']} / {$r['dept_name']}：sign_date " . ($r['sign_date'] ?: '(空)') . " → {$r['business_date']}\n";
    $dsFix++;
    if ($run) $db->prepare("UPDATE con_review_dept_sign SET sign_date=? WHERE id=?")->execute([$r['business_date'], $r['id']]);
}
echo "部門簽核小計：已正確 {$dsOk} 筆／修正 {$dsFix} 筆\n\n";

$docRows = $db->query(
    "SELECT id, doc_no, business_date, decision, sales_decided_date, gm_approved_by, gm_approved_date
     FROM con_review_doc WHERE decision IS NOT NULL OR gm_approved_by IS NOT NULL ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
$decFix = 0; $decOk = 0; $gmFix = 0; $gmOk = 0;
foreach ($docRows as $r) {
    if ($r['decision'] !== null) {
        if ((string)$r['sales_decided_date'] === (string)$r['business_date']) { $decOk++; }
        else {
            echo "  [業務課決行] doc {$r['doc_no']}：sales_decided_date " . ($r['sales_decided_date'] ?: '(空)') . " → {$r['business_date']}\n";
            $decFix++;
            if ($run) $db->prepare("UPDATE con_review_doc SET sales_decided_date=? WHERE id=?")->execute([$r['business_date'], $r['id']]);
        }
    }
    if ($r['gm_approved_by'] !== null) {
        if ((string)$r['gm_approved_date'] === (string)$r['business_date']) { $gmOk++; }
        else {
            echo "  [總經理核准] doc {$r['doc_no']}：gm_approved_date " . ($r['gm_approved_date'] ?: '(空)') . " → {$r['business_date']}\n";
            $gmFix++;
            if ($run) $db->prepare("UPDATE con_review_doc SET gm_approved_date=? WHERE id=?")->execute([$r['business_date'], $r['id']]);
        }
    }
}
echo "業務課決行小計：已正確 {$decOk} 筆／修正 {$decFix} 筆\n";
echo "總經理核准小計：已正確 {$gmOk} 筆／修正 {$gmFix} 筆\n\n";

if (!$run) {
    echo "以上僅為試算，尚未寫入。確認無誤後請加 --run 重跑一次。\n";
} else {
    echo "done\n";
}
