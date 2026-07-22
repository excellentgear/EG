<?php
session_start();
include('../../src/common/DBConnection.php');
include('../../src/common/homepage.php');
@include_once('../../src/common/login_log.php');   // 登入紀錄（靜默，失敗不影響登入）

    if (isset($_SESSION['status']) && !isset($_POST['login'])) {
        $status = (int)$_SESSION['status']; // 強制轉型為整數，避免字串比較問題
        $url = "../../views/admin/dashboard.php";
        // 優先採用「個人 → 部門」首頁設定
        $home = hp_resolve_home_page((new DBConnection())->getPDO(), (int)($_SESSION['id'] ?? 0));
        if ($home) {
            $url = "../../" . $home;
        } elseif ($status === 9) {
            $url = "../../views/admin/index.php";
        } elseif ($status === 63) {
            $url = "../../views/admin/NN_dashboard.php";
        }
        if (isset($_GET['msg'])) {
            $url .= "?msg=" . urlencode($_GET['msg']);
        }
        header("Location:" . $url);
        exit();
    }

    $conn = new DBConnection();
    $restlt = $conn->getAll('SELECT * FROM `user` where `user_uname`='."'$_POST[userName]'");   

    $admin ="";
    foreach ($restlt as $row){
        $admin = $row;
    }

    @$userName = $admin['user_uname'];
    @$status   = $admin['user_status'];
    @$keyin    = $_session['userName'];
    @$id       = $admin['id'];

    $_SESSION['userName'] = $admin['user_uname'];
    $_SESSION['password'] = $admin['user_password'];
    $_SESSION['status']   = $admin['user_status'];
    $_SESSION['id']       = $admin['id'];

    if (isset($_POST['login'])){
    
        // $admin_name = trim($_POST['userName']);
        // $user_password = trim($_POST['password']);
    
        if ($_POST['userName'] == $admin['user_uname'] && $_POST['password'] == $admin['user_password']) {
            if ($admin['state'] == 0) {
                if (function_exists('eg_login_log')) eg_login_log($conn->getPDO(), (int)$admin['id'], (string)$_POST['userName'], false, '帳號停用');
                header("Location:../../index.php?msg=此使用者已離職，帳號無法使用");
                exit();
            }
            if (function_exists('eg_login_log')) eg_login_log($conn->getPDO(), (int)$admin['id'], (string)$_POST['userName'], true);
            //測試掃條碼用 1/1
            setcookie("userName", $userName);
            if (isset($_SESSION['lastpage'])) //若前頁已設定，則返回前頁
            {
                $lastpage = $_SESSION['lastpage'];
                unset($_SESSION['lastpage']); // 只使用一次；若導回的頁面再踢回登入頁，下次登入不會又導回同頁造成無限循環
                header("Location:".$lastpage);
                exit();
            }//測試掃條碼用 1/1--end
            else {
                // 依「個人 → 部門」首頁設定導向；未設定則退回下方舊的 switch 邏輯
                $home = hp_resolve_home_page($conn->getPDO(), (int)$id);
                if ($home) {
                    header("Location:../../" . $home);
                    exit();
                }
                switch($status){
                    case 9: //超級管理員
                        header("Location:../../views/admin/index.php");
                    break;
                    case 1: //生管
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 10: //生管
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 11: //生管
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 12: //生管
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 13: //生管
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 2: //製造部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 20: //製造部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 21: //製造部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 22: //製造部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 23: //製造部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 3: //管理部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 30: //管理部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 31: //管理部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 32: //管理部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 33: //管理部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 4: //業務部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 40: //業務部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 41: //業務部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 42: //業務部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 43: //業務部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 49: //業務部
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 5: //品管課
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 50: //品管課
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 51: //品管課
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 52: //品管課
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 53: //品管課
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 60: //技術課
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 61: //技術課
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 62: //技術課
                        header("Location:../../views/admin/dashboard.php");
                    break;
                    case 63: //技術課
                        header("Location:../../views/admin/NN_dashboard.php");
                    break;
                    case 90: //超級管理員
                        header("Location:../../views/admin/NN_dashboard2.php");
                    break;
                    default: //未設定身分或未列在上方 → 導向一般儀表板，避免出現空白頁
                        header("Location:../../views/admin/dashboard.php");
                    break;
                }
            }

        } else {
            if (function_exists('eg_login_log')) {
                $isRealUser = is_array($admin) && isset($admin['id']);
                eg_login_log($conn->getPDO(),
                    $isRealUser ? (int)$admin['id'] : null,
                    (string)($_POST['userName'] ?? ''),
                    false,
                    $isRealUser ? '密碼錯誤' : '帳號不存在');
            }
            header("Location:../../index.php?msg=使用者名稱或密碼錯誤");
        }

    }

$cl=$_COOKIE["lastPage"];
    
?>
<html>
    <body>
        <P><?=$userName?></P>
        <P><?=$id?></P>
        <P><?=$status?></P>
        <p><?=$cl?></p>
    </body>
</html>

    
 