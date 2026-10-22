<?php
/**
 * OrderErpImport_API.php — ERP 訂購單補資料（Upload_List.php「補資料」分頁專用）
 * 僅系統管理員（role_features 'all'）可用；只新增 order_track，不覆蓋既有訂單。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';
include_once $document_root . '/EGsystem/src/common/order_erp_import_lib.php';
require_once $document_root . '/EGsystem/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});
function oeiOut(array $a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$uid = (int)($_SESSION['id'] ?? 0);
if (!$uid) { http_response_code(401); oeiOut(['ok' => false, 'error' => '尚未登入']); }
if (!rf_has_feature(rf_load_user_features($db, $uid), 'all')) { http_response_code(403); oeiOut(['ok' => false, 'error' => '僅管理員可使用']); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); oeiOut(['ok' => false, 'error' => '方法不允許']); }
oei_ensure_schema($db);

$action = $_GET['action'] ?? '';
$ids = function () { $a = json_decode($_POST['ids'] ?? '[]', true); return array_values(array_filter(array_map('intval', is_array($a) ? $a : []))); };

switch ($action) {
case 'preview': {
    set_time_limit(120); ini_set('memory_limit', '512M');
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) oeiOut(['ok' => false, 'error' => '請選擇檔案']);
    $all = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['file']['tmp_name'])->getActiveSheet()->toArray(null, true, true, false);
    $err = [];
    $rows = oei_parse($all, $err);
    if (!$rows) oeiOut(['ok' => false, 'error' => implode('；', $err) ?: '未找到有效資料列']);
    $rows = oei_match($db, $rows);
    $_SESSION['oei_rows'] = $rows; $_SESSION['oei_ts'] = time();
    $cnt = []; foreach ($rows as $r) $cnt[$r['match_status']] = ($cnt[$r['match_status']] ?? 0) + 1;
    oeiOut(['ok' => true, 'total' => count($rows), 'counts' => $cnt, 'warnings' => $err,
            'orders' => count(array_unique(array_column($rows, 'order_oo'))), 'rows' => $rows]);
}
case 'stage': {   // 確認後存入暫存區（不動 order_track）
    if (empty($_SESSION['oei_rows']) || time() - (int)$_SESSION['oei_ts'] > 1800) oeiOut(['ok' => false, 'error' => '預覽已過期，請重新選檔']);
    $rows = $_SESSION['oei_rows']; unset($_SESSION['oei_rows'], $_SESSION['oei_ts']);
    $batch = date('ymdHis');
    $chk = $db->prepare("SELECT 1 FROM order_erp_import_stage WHERE state='pending' AND order_oo=? AND part_no=? AND qty=? AND process_text<=>? AND unit_price<=>? AND batch_id<>? LIMIT 1");
    $ins = $db->prepare("INSERT INTO order_erp_import_stage
        (batch_id,line_no,order_oo,order_date,delivery_date,client_id,client_name,part_no,d_id_id,spec_text,process_text,qty,unit_price,amount,currency,exchange_rate,match_status,match_note,existing_order_id,part_total,created_by,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $n = 0; $dup = 0; $db->beginTransaction();
    $exact = 0;
    foreach ($rows as $r) {
        if ($r['match_status'] === 'exact') { $exact++; continue; }   // 訂單內已有相同明細，不進暫存區
        // 重複上傳同一份檔案：暫存區已有相同待處理明細就不再放第二份
        // （只比對「其他批次」：同一檔內相同明細多筆是正常的）
        $chk->execute([$r['order_oo'], $r['part_no'], $r['qty'], $r['process_text'], $r['unit_price'], $batch]);
        if ($chk->fetchColumn()) { $dup++; continue; }
        $ins->execute([$batch, $r['line_no'], $r['order_oo'], $r['order_date'], $r['delivery_date'], $r['client_id'], $r['client_name'],
            $r['part_no'], $r['d_id_id'], $r['spec_text'], $r['process_text'], $r['qty'], $r['unit_price'], $r['amount'], $r['currency'],
            $r['exchange_rate'], $r['match_status'], $r['match_note'], $r['existing_order_id'], $r['part_total'], $uid]);
        $n++;
    }
    $db->commit();
    oeiOut(['ok' => true, 'staged' => $n, 'dup' => $dup, 'exact' => $exact, 'batch' => $batch]);
}
case 'list': {
    $state = in_array($_POST['state'] ?? '', ['pending', 'converted', 'skipped'], true) ? $_POST['state'] : 'pending';
    $q = trim($_POST['q'] ?? '');
    $sql = "SELECT * FROM order_erp_import_stage WHERE state=?"; $p = [$state];
    if ($q !== '') { $sql .= " AND (order_oo LIKE ? OR part_no LIKE ? OR client_name LIKE ?)"; $l = "%$q%"; array_push($p, $l, $l, $l); }
    $sql .= " ORDER BY order_oo, line_no, id LIMIT 2000";
    $s = $db->prepare($sql); $s->execute($p);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $c = $db->query("SELECT state, COUNT(*) n FROM order_erp_import_stage GROUP BY state")->fetchAll(PDO::FETCH_KEY_PAIR);
    oeiOut(['ok' => true, 'rows' => $rows, 'counts' => $c]);
}
case 'convert': {  // 轉成正式訂單：每筆各自交易，失敗不影響其他筆
    $done = 0; $fail = [];
    foreach ($ids() as $id) {
        try {
            $db->beginTransaction();
            [$ok, $msg] = oei_convert_one($db, $id, $uid);
            $db->commit();
            if ($ok) $done++; else $fail[] = "#{$id}：{$msg}";
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); $fail[] = "#{$id}：" . $e->getMessage(); }
    }
    oeiOut(['ok' => true, 'converted' => $done, 'failed' => $fail]);
}
case 'cust_unmapped': {   // 待轉入裡「客戶對不上主檔」的報表客戶名稱＋建議＋已設定的對應
    oei_resolve_customer($db, '__reset__', null);
    $g0 = $db->query("SELECT client_name, MAX(client_id) client_id, COUNT(*) n FROM order_erp_import_stage
                     WHERE state='pending' AND match_status='no_part' GROUP BY client_name ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
    $sg = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE (customer LIKE ? OR ? LIKE CONCAT('%', customer, '%')) AND customer<>'' LIMIT 3");
    $g = [];
    foreach ($g0 as $x) {
        if (oei_resolve_customer($db, $x['client_id'], $x['client_name']) !== null) continue;   // 客戶已對上，不是客戶問題
        $nm = trim((string)$x['client_name']);
        $x['suggest'] = [];
        if ($nm !== '') { $sg->execute(['%' . $nm . '%', $nm]); $x['suggest'] = $sg->fetchAll(PDO::FETCH_ASSOC); }
        $g[] = $x;
    }
    // 客戶已對上、料號仍無法對應的原因（與客戶對應無關，需到料號主檔處理）
    $other = $db->query("SELECT client_name, part_no, match_note FROM order_erp_import_stage WHERE state='pending' AND match_status='no_part' ORDER BY client_name, part_no")->fetchAll(PDO::FETCH_ASSOC);
    $oth = [];
    foreach ($other as $o) {
        $isUnres = false; foreach ($g as $gg) if ($gg['client_name'] === $o['client_name']) { $isUnres = true; break; }
        if ($isUnres) continue;
        $k = preg_replace('/，(請|且).*$/u', '', $o['match_note']);
        $oth[$k]['note'] = $k; $oth[$k]['n'] = ($oth[$k]['n'] ?? 0) + 1;
        if (count($oth[$k]['parts'] ?? []) < 6 && !in_array($o['client_name'] . ' ' . $o['part_no'], $oth[$k]['parts'] ?? [], true)) $oth[$k]['parts'][] = $o['client_name'] . ' ' . $o['part_no'];
    }
    $cust = $db->query("SELECT customer_id, customer FROM customer_list WHERE customer<>'' ORDER BY customer")->fetchAll(PDO::FETCH_ASSOC);
    $map = $db->query("SELECT m.erp_name, m.customer_id, c.customer FROM order_erp_customer_map m LEFT JOIN customer_list c ON c.customer_id=m.customer_id ORDER BY m.erp_name")->fetchAll(PDO::FETCH_ASSOC);
    oeiOut(['ok' => true, 'groups' => $g, 'other' => array_values($oth), 'customers' => $cust, 'map' => $map]);
}
case 'cust_map_save': {   // 一次存多筆：maps=[{erp_name,customer_id}]；customer_id 空白＝取消該對應
    $maps = json_decode($_POST['maps'] ?? '[]', true); if (!is_array($maps)) $maps = [];
    $ck = $db->prepare("SELECT 1 FROM customer_list WHERE customer_id=?");
    $up = $db->prepare("INSERT INTO order_erp_customer_map (erp_name,customer_id,created_by,created_at) VALUES (?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id), created_by=VALUES(created_by), created_at=NOW()");
    $de = $db->prepare("DELETE FROM order_erp_customer_map WHERE erp_name=?");
    $saved = 0;
    foreach ($maps as $m) {
        $nm = trim((string)($m['erp_name'] ?? '')); $cid = trim((string)($m['customer_id'] ?? ''));
        if ($nm === '') continue;
        if ($cid === '') { $de->execute([$nm]); continue; }
        $ck->execute([$cid]);
        if (!$ck->fetchColumn()) oeiOut(['ok' => false, 'error' => "客戶主檔查無編號 {$cid}（{$nm}）"]);
        $up->execute([$nm, $cid, $uid]); $saved++;
    }
    $n = oei_rematch_pending($db);
    oeiOut(['ok' => true, 'saved' => $saved, 'rematched' => $n]);
}
case 'rematch': {
    oeiOut(['ok' => true, 'rematched' => oei_rematch_pending($db)]);
}
case 'skip': {
    $n = 0; $s = $db->prepare("UPDATE order_erp_import_stage SET state='skipped' WHERE id=? AND state='pending'");
    foreach ($ids() as $id) { $s->execute([$id]); $n += $s->rowCount(); }
    oeiOut(['ok' => true, 'n' => $n]);
}
case 'restore': {
    $n = 0; $s = $db->prepare("UPDATE order_erp_import_stage SET state='pending' WHERE id=? AND state='skipped'");
    foreach ($ids() as $id) { $s->execute([$id]); $n += $s->rowCount(); }
    oeiOut(['ok' => true, 'n' => $n]);
}
case 'delete': {   // 只刪暫存區的待處理／略過列，已轉入的紀錄不可刪
    $n = 0; $s = $db->prepare("DELETE FROM order_erp_import_stage WHERE id=? AND state IN ('pending','skipped')");
    foreach ($ids() as $id) { $s->execute([$id]); $n += $s->rowCount(); }
    oeiOut(['ok' => true, 'n' => $n]);
}
default: oeiOut(['ok' => false, 'error' => '無效的操作']);
}
