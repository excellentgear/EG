<?php
/**
 * _update_qcps.php — 回報／修改某一製程（bom_ing）的容器資訊
 * 寫入 bom_ing.QC_ps / QC_ps2，格式與 QC 允收跳窗完全一致（唯一實作見 qc_container_lib.php）
 * 呼叫端：views/pm/OreadyReply_ForPm_BaseOfTime.php（發單日欄的容器鈕）
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

$resp = array('success' => false, 'message' => '');

if (!isset($_SESSION['id'])) {
    echo json_encode(array('success' => false, 'message' => '未登入或連線已逾時，請重新整理頁面後再試'));
    exit;
}

include_once __DIR__ . '/../common/DBConnection.php';
include_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/qc_container_lib.php';

if (!isset($db)) {
    echo json_encode(array('success' => false, 'message' => '資料庫連線失敗'));
    exit;
}

$uid = $_SESSION['id'];

// 鐵律8：前端擋一次，後端同規則再擋一次
if (!eg_qc_container_can_edit($db, $uid)) {
    echo json_encode(array('success' => false, 'message' => '無權限回報容器'));
    exit;
}

$fid = isset($_POST['bom_ing_fid']) ? trim($_POST['bom_ing_fid']) : '';
if ($fid === '' || !ctype_digit($fid)) {
    echo json_encode(array('success' => false, 'message' => '缺少或不合法的製程識別碼'));
    exit;
}

$containers = isset($_POST['container']) ? (array)$_POST['container'] : array();
$quantities = isset($_POST['quantity'])  ? (array)$_POST['quantity']  : array();

foreach ($containers as $i => $c) {
    $c = trim((string)$c);
    if ($c === '') continue;
    if (!eg_qc_container_valid_code($c)) {
        echo json_encode(array('success' => false, 'message' => '容器種類不正確'));
        exit;
    }
    $q = trim((string)(isset($quantities[$i]) ? $quantities[$i] : ''));
    if ($q !== '' && (!ctype_digit($q) || (int)$q > 99999)) {
        echo json_encode(array('success' => false, 'message' => '箱數必須是 0~99999 的整數'));
        exit;
    }
}

list($qcPs, $qcPs2) = eg_qc_container_pack($containers, $quantities);

try {
    eg_qc_container_ensure_schema($db);
    $chk = $db->prepare("SELECT bom, bom_sn, IFNULL(QC_ps,'') qc1, IFNULL(QC_ps2,'') qc2 FROM bom_ing WHERE bom_ing_fid = ? LIMIT 1");
    $chk->execute(array($fid));
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(array('success' => false, 'message' => '找不到指定的製程'));
        exit;
    }

    $st = $db->prepare("UPDATE bom_ing SET pm_ps = :p1, pm_ps2 = :p2, Modified_At = NOW(), Modified_By = :uid WHERE bom_ing_fid = :fid LIMIT 1");
    $st->execute(array(':p1' => $qcPs, ':p2' => $qcPs2, ':uid' => $uid, ':fid' => $fid));

    list($dispText, $dispDiff) = eg_qc_container_display($row['qc1'], $row['qc2'], $qcPs, $qcPs2);
    echo json_encode(array(
        'success'   => true,
        'message'   => ($qcPs === '' && $qcPs2 === '') ? '容器已清除' : '容器已更新',
        'pm_ps'     => $qcPs,
        'pm_ps2'    => $qcPs2,
        'QC_ps'     => $row['qc1'],
        'QC_ps2'    => $row['qc2'],
        'disp_text' => $dispText,
        'disp_diff' => $dispDiff,
        'bom'       => $row['bom'],
        'bom_sn'    => $row['bom_sn'],
    ));
} catch (PDOException $e) {
    error_log('[_update_qcps] ' . $e->getMessage());
    echo json_encode(array('success' => false, 'message' => '資料庫錯誤，容器未更新'));
}
