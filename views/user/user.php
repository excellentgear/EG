<?php
session_start();

include '../../src/common/DBConnection.php';
require_once '../../src/common/confirm_password_lib.php';

$db_connection = new DBConnection();
$conn_pdo = $db_connection->getPDO();

// --- 登入檢查 ---
if (!isset($_SESSION['id']) || !isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

// 取得目前登入者資料
$stmt = $conn_pdo->prepare("SELECT id, user_cname, user_uname, user_password FROM `user` WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    header("Location:../../index.php");
    exit;
}

$myUid = (int)$currentUser['id'];
$isSuperadmin = ($myUid === 1);
$canSetConfirmPw = eg_confirm_password_allowed($conn_pdo, $myUid);

$msg = '';
$msgType = '';   // success | danger
$cpMsg = '';
$cpMsgType = '';
$grantMsg = '';
$grantMsgType = '';

// --- 處理變更密碼 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['changePassword'])) {
    $oldPwd     = isset($_POST['old_password']) ? $_POST['old_password'] : '';
    $newPwd     = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPwd = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    // 共用帳號可鎖密碼（ai-rules/13）：避免現場有人隨手改掉害全廠登不進去
    require_once __DIR__ . '/../../src/common/shared_account_lib.php';
    if (eg_shared_password_locked($conn_pdo, (int)$currentUser['id'])) {
        $msg = '此帳號已鎖定密碼，請洽管理員';
        $msgType = 'danger';
    } elseif ($oldPwd === '' || $newPwd === '' || $confirmPwd === '') {
        $msg = '請完整填寫所有欄位';
        $msgType = 'danger';
    } elseif ($oldPwd !== $currentUser['user_password']) {
        // 與 Login.php 一致：明碼直接比對
        $msg = '舊密碼不正確';
        $msgType = 'danger';
    } elseif ($newPwd !== $confirmPwd) {
        $msg = '新密碼與確認密碼不一致';
        $msgType = 'danger';
    } elseif (mb_strlen($newPwd) > 20) {
        // user_password 欄位為 varchar(20)
        $msg = '新密碼長度不可超過 20 個字元';
        $msgType = 'danger';
    } elseif ($newPwd === $currentUser['user_password']) {
        $msg = '新密碼不可與舊密碼相同';
        $msgType = 'danger';
    } else {
        try {
            $upd = $conn_pdo->prepare("UPDATE `user` SET user_password = ? WHERE id = ?");
            $upd->execute([$newPwd, $currentUser['id']]);

            // 同步更新 session 中的密碼，避免與 Login 設定的值不一致
            $_SESSION['password'] = $newPwd;
            $currentUser['user_password'] = $newPwd;

            $msg = '密碼變更成功';
            $msgType = 'success';

            // 寫入修改紀錄
            try {
                $log = $conn_pdo->prepare(
                    "INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                     VALUES (?, ?, ?, NOW(), ?)"
                );
                $log->execute([
                    'views/user/user.php',
                    '使用者自行變更個人密碼',
                    '帳號「' . $currentUser['user_uname'] . '」於個人資料頁變更登入密碼',
                    'User'
                ]);
            } catch (Exception $e) {
                // 紀錄寫入失敗不影響主要流程
            }
        } catch (Exception $e) {
            $msg = '密碼變更失敗，請稍後再試';
            $msgType = 'danger';
        }
    }
}

// --- 處理設定/變更「操作確認密碼」（與登入密碼分開存放、雜湊，見 confirm_password_lib.php） ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setConfirmPassword'])) {
    $cpNew = isset($_POST['cp_new_password']) ? $_POST['cp_new_password'] : '';
    $cpConfirm = isset($_POST['cp_confirm_password']) ? $_POST['cp_confirm_password'] : '';
    if (!$canSetConfirmPw) {
        $cpMsg = '您目前沒有設定操作確認密碼的權限';
        $cpMsgType = 'danger';
    } elseif ($cpNew === '' || $cpConfirm === '') {
        $cpMsg = '請完整填寫兩個欄位';
        $cpMsgType = 'danger';
    } elseif ($cpNew !== $cpConfirm) {
        $cpMsg = '新密碼與確認密碼不一致';
        $cpMsgType = 'danger';
    } else {
        $r = eg_confirm_password_set($conn_pdo, $myUid, $cpNew, $currentUser['user_uname']);
        $cpMsg = $r['ok'] ? '操作確認密碼設定成功' : $r['msg'];
        $cpMsgType = $r['ok'] ? 'success' : 'danger';
        if ($r['ok']) {
            try {
                $conn_pdo->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                                    VALUES ('views/user/user.php', '使用者設定操作確認密碼', ?, NOW(), ?)")
                         ->execute(['帳號「' . $currentUser['user_uname'] . '」設定/變更操作確認密碼', 'User']);
            } catch (Exception $e) {}
        }
    }
}

// --- 超級管理員：授權/取消授權其他管理員可設定操作確認密碼 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grantConfirmPw']) && $isSuperadmin) {
    $targetUid = (int)($_POST['target_uid'] ?? 0);
    if ($targetUid > 0 && $targetUid !== 1) {
        eg_confirm_password_grant($conn_pdo, $targetUid, $currentUser['user_uname']);
        $grantMsg = '已授權，該管理員可自行設定操作確認密碼';
        $grantMsgType = 'success';
        try {
            $conn_pdo->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                                VALUES ('views/user/user.php', '授權操作確認密碼', ?, NOW(), ?)")
                     ->execute(['超級管理員授權 user_id=' . $targetUid . ' 可設定操作確認密碼', 'User']);
        } catch (Exception $e) {}
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revokeConfirmPw']) && $isSuperadmin) {
    $targetUid = (int)($_POST['target_uid'] ?? 0);
    if ($targetUid > 0) {
        eg_confirm_password_revoke($conn_pdo, $targetUid);
        $grantMsg = '已取消授權';
        $grantMsgType = 'success';
        try {
            $conn_pdo->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
                                VALUES ('views/user/user.php', '取消操作確認密碼授權', ?, NOW(), ?)")
                     ->execute(['超級管理員取消 user_id=' . $targetUid . ' 的操作確認密碼授權', 'User']);
        } catch (Exception $e) {}
    }
}

// 超級管理員的授權名單管理需要候選人清單＋目前已授權者。
// 候選人不限「管理員角色」——超級管理員應該可以直接打姓名篩選、指定任何在職人員，人數也不限制；
// 名單一律走共用的 people_lib.php（ai-rules/08 第五節人員列表鐵則，禁止各頁自己拼人員 SQL）。
$grantCandidates = [];
$grantedList = [];
if ($isSuperadmin) {
    require_once '../../src/common/people_lib.php';
    $grantedList = eg_confirm_password_grant_list($conn_pdo);
    try {
        $grantCandidates = array_values(array_filter(
            eg_people_list($conn_pdo, ['multi_dept' => true]),
            function ($p) { return (int)$p['id'] !== 1; }
        ));
    } catch (Exception $e) { $grantCandidates = []; }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>修改個人密碼 - Excellentgear 超正齒輪</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">

            <!-- side and top bar include -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>
            <!-- /side and top bar include -->

            <!-- page content -->
            <div class="right_col" role="main">
                <div class="">
                    <div class="row">
                        <div class="col-md-6 col-md-offset-3 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-key" style="margin-right:7px;"></i>修改個人密碼</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">

                                    <p class="text-muted">帳號：<strong><?= htmlspecialchars($currentUser['user_uname']) ?></strong>
                                        （<?= htmlspecialchars($currentUser['user_cname']) ?>）</p>

                                    <?php if ($msg !== ''): ?>
                                        <div class="alert alert-<?= $msgType ?> alert-dismissable fade in">
                                            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                                            <?= htmlspecialchars($msg) ?>
                                        </div>
                                    <?php endif; ?>

                                    <form class="form-horizontal form-label-left" method="POST"
                                          action="user.php" autocomplete="off" id="changePwdForm">

                                        <div class="form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">舊密碼 <span class="required">*</span></label>
                                            <div class="col-md-9 col-sm-9 col-xs-12">
                                                <input type="password" name="old_password" class="form-control"
                                                       maxlength="20" required>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">新密碼 <span class="required">*</span></label>
                                            <div class="col-md-9 col-sm-9 col-xs-12">
                                                <input type="password" name="new_password" id="new_password"
                                                       class="form-control" maxlength="20" required>
                                                <span class="help-block" style="margin-bottom:0;">最多 20 個字元</span>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">確認新密碼 <span class="required">*</span></label>
                                            <div class="col-md-9 col-sm-9 col-xs-12">
                                                <input type="password" name="confirm_password" id="confirm_password"
                                                       class="form-control" maxlength="20" required>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <div class="col-md-9 col-sm-9 col-xs-12 col-md-offset-3">
                                                <button type="submit" name="changePassword" class="btn btn-primary">
                                                    <i class="fa fa-save"></i> 儲存變更
                                                </button>
                                                <button type="reset" class="btn btn-default">清除</button>
                                            </div>
                                        </div>
                                    </form>

                                </div>
                            </div>
                        </div>
                    </div>

<?php if ($canSetConfirmPw): ?>
                    <div class="row">
                        <div class="col-md-6 col-md-offset-3 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-shield" style="margin-right:7px;"></i>操作確認密碼</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <p class="text-muted">用於「需要輸入密碼確認才能執行」的特例操作（補資料、取消送出計畫…），跟登入密碼分開存放，不會因為到處輸入登入密碼而增加外洩風險。<?= $isSuperadmin ? '' : '（此權限由超級管理員授權）' ?></p>

                                    <?php if ($cpMsg !== ''): ?>
                                        <div class="alert alert-<?= $cpMsgType ?> alert-dismissable fade in">
                                            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                                            <?= htmlspecialchars($cpMsg) ?>
                                        </div>
                                    <?php endif; ?>

                                    <form class="form-horizontal form-label-left" method="POST" action="user.php" autocomplete="off" id="confirmPwdForm">
                                        <div class="form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">新的操作確認密碼 <span class="required">*</span></label>
                                            <div class="col-md-9 col-sm-9 col-xs-12">
                                                <input type="password" name="cp_new_password" id="cp_new_password" class="form-control" required>
                                                <span class="help-block" style="margin-bottom:0;">至少 6 個字元</span>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">確認密碼 <span class="required">*</span></label>
                                            <div class="col-md-9 col-sm-9 col-xs-12">
                                                <input type="password" name="cp_confirm_password" id="cp_confirm_password" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <div class="col-md-9 col-sm-9 col-xs-12 col-md-offset-3">
                                                <button type="submit" name="setConfirmPassword" class="btn btn-primary">
                                                    <i class="fa fa-save"></i> 儲存
                                                </button>
                                                <button type="reset" class="btn btn-default">清除</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
<?php endif; ?>

<?php if ($isSuperadmin): ?>
                    <div class="row">
                        <div class="col-md-6 col-md-offset-3 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-users" style="margin-right:7px;"></i>操作確認密碼授權管理</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <p class="text-muted">授權其他管理員可以設定「自己的」操作確認密碼，之後那位管理員就能用自己的密碼執行原本只有超級管理員能做的特例操作，不需要知道超級管理員的登入密碼。</p>

                                    <?php if ($grantMsg !== ''): ?>
                                        <div class="alert alert-<?= $grantMsgType ?> alert-dismissable fade in">
                                            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                                            <?= htmlspecialchars($grantMsg) ?>
                                        </div>
                                    <?php endif; ?>

                                    <table class="table table-striped">
                                        <thead><tr><th>已授權管理員</th><th>是否已設定密碼</th><th>授權人／時間</th><th></th></tr></thead>
                                        <tbody>
                                        <?php if (!$grantedList): ?>
                                            <tr><td colspan="4" class="text-muted">目前尚未授權任何人</td></tr>
                                        <?php else: foreach ($grantedList as $g): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($g['user_cname']) ?></td>
                                                <td><?= $g['has_password'] ? '<span class="text-success">已設定</span>' : '<span class="text-muted">尚未設定</span>' ?></td>
                                                <td class="text-muted"><?= htmlspecialchars((string)$g['granted_by']) ?>　<?= htmlspecialchars((string)$g['granted_at']) ?></td>
                                                <td>
                                                    <form method="POST" action="user.php" style="display:inline;" onsubmit="return confirm('確定要取消此人的操作確認密碼授權嗎？');">
                                                        <input type="hidden" name="target_uid" value="<?= (int)$g['user_id'] ?>">
                                                        <button type="submit" name="revokeConfirmPw" class="btn btn-xs btn-danger">取消授權</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>

                                    <form class="form-inline" method="POST" action="user.php">
                                        <select name="target_uid" class="form-control" data-eg-filter="輸入姓名篩選…" required>
                                            <option value="">選擇要授權的人員…</option>
                                            <?php foreach ($grantCandidates as $c):
                                                $label = $c['user_cname']
                                                    . ($c['position_name'] ? '（' . $c['position_name'] . '）' : '')
                                                    . ($c['dept_name'] ? '／' . $c['dept_name'] : '')
                                                    . (!empty($c['leave_note']) ? '　' . $c['leave_note'] : '');
                                            ?>
                                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="grantConfirmPw" class="btn btn-primary">
                                            <i class="fa fa-plus"></i> 新增授權
                                        </button>
                                    </form>
                                    <p class="text-muted" style="margin-top:6px;">候選名單為全公司在職人員（可直接打姓名篩選），不限「管理員」角色；授權人數沒有上限。</p>
                                </div>
                            </div>
                        </div>
                    </div>
<?php endif; ?>
                </div>
            </div>
            <!-- /page content -->

            <!-- footer content include -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content include -->
        </div>
    </div>

    <!-- jQuery -->
    <script src="../../resource/js/jquery.min.js"></script>
    <!-- Bootstrap -->
    <script src="../../resource/js/bootstrap.min.js"></script>
    <!-- Custom Theme Scripts -->
    <script src="../../resource/js/custom.min.js"></script>
    <script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>

    <script>
        // 前端即時檢查：新密碼與確認密碼是否一致
        $(function () {
            $('#changePwdForm').on('submit', function (e) {
                var np = $('#new_password').val();
                var cp = $('#confirm_password').val();
                if (np !== cp) {
                    e.preventDefault();
                    alert('新密碼與確認密碼不一致');
                    $('#confirm_password').focus();
                }
            });
            $('#confirmPwdForm').on('submit', function (e) {
                var np = $('#cp_new_password').val();
                var cp = $('#cp_confirm_password').val();
                if (np !== cp) {
                    e.preventDefault();
                    alert('新密碼與確認密碼不一致');
                    $('#cp_confirm_password').focus();
                }
            });
        });
    </script>
</body>

</html>
