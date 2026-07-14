<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php");
require_once __DIR__ . '/../common/rbac.php';

    $cmd = $db->prepare("SELECT * FROM live_event where id =" . (int)($_GET['eventid'] ?? 0));
    $cmd->execute();
    $row = $cmd->fetch();

if (!$row) {
    header("location:../../views/liveEvent/createEvent.php");
    exit();
}

// 鎖定：來源『訂單變更』的通知禁止載入編輯（單一真相來源為 order_change_log）
if (($row['source'] ?? '') === '訂單變更') {
    header("location:../../views/liveEvent/createEvent.php?locked=1");
    exit();
}

// 編輯權限：只有系統角色（管理員）可編輯任何公告；
// 其他人僅能編輯「本人建立」或「本人為共同編輯者(含本人部門)」的公告
$__uid = (int)($_SESSION['id'] ?? 0);
$__features = rbac_user_features($db, $__uid);
$__isAdmin  = rbac_has($__features, 'all');
$__isCreator = ((int)($row['created_by'] ?? 0) === $__uid);
$__isCoEditor = false;
try {
    $__deptIds = array_map('intval', $db->query("SELECT department_id FROM user_department_position_map WHERE user_id = $__uid")->fetchAll(PDO::FETCH_COLUMN));
    $__deptIn = $__deptIds ? implode(',', $__deptIds) : '-1';
    $__ck = $db->prepare("SELECT 1 FROM live_event_editor WHERE live_event_id = ? AND ((editor_type='user' AND editor_id = ?) OR (editor_type='dept' AND editor_id IN ($__deptIn))) LIMIT 1");
    $__ck->execute([(int)$row['id'], $__uid]);
    $__isCoEditor = (bool)$__ck->fetchColumn();
} catch (Throwable $e) { /* 表不存在時視為非共同編輯者 */ }

if (!$__isAdmin && !$__isCreator && !$__isCoEditor) {
    header("location:../../views/liveEvent/createEvent.php?denied=1");
    exit();
}

// 來源=品質異常單(ref_type='QA')：修改內容的單一真相來源是異常單本身，
// 導向品管合併檢驗頁的異常單修改畫面（該頁會檢查異常單修改權限，無權限時引導向主管提出修改請求）
if (($row['ref_type'] ?? '') === 'QA' && (int)($row['ref_id'] ?? 0) > 0) {
    header("location:../../views/QC/inspection_combined_prototype.php?edit_abnormal=" . (int)$row['ref_id']);
    exit();
}

$_SESSION['eventid']=$row['id'];
$_SESSION['eventdate']=$row['eventdate'];
$_SESSION['enddate']=$row['enddate'];
$_SESSION['title']=$row['title'];
$_SESSION['content']=$row['content'];
$_SESSION['eventstatus']=$row['status'];

header("location:../../views/liveEvent/createEvent.php");
