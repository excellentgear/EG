<?php
session_start();

include '../../src/common/DBConnection.php';

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

$msg = '';
$msgType = '';   // success | danger

// --- 處理變更密碼 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['changePassword'])) {
    $oldPwd     = isset($_POST['old_password']) ? $_POST['old_password'] : '';
    $newPwd     = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPwd = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    if ($oldPwd === '' || $newPwd === '' || $confirmPwd === '') {
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
        });
    </script>
</body>

</html>
