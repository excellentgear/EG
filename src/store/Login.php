<?php
session_start();
include('../../src/common/DBConnection.php');
include('../../src/common/homepage.php');
@include_once('../../src/common/login_log.php');   // 登入紀錄（靜默，失敗不影響登入）
require_once('../../src/common/user_active_lib.php'); // 在職狀態封鎖（離職/留停）

    if (isset($_SESSION['status']) && !isset($_POST['login'])) {
        // 既有 session 也要重驗在職狀態：登入後才被改成離職/留停的人，不可靠舊 session 繼續進來
        eg_guard_active_session((new DBConnection())->getPDO());
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
    // 2026-07-30：改用 prepared statement。原本把 $_POST 直接串進 SQL，
    // 可用 UNION 偽造一列密碼已知的資料而以任意人身分登入 —— 那會讓下方所有防線（含離職封鎖）失效。
    $stmt = $conn->getPDO()->prepare('SELECT * FROM `user` WHERE `user_uname` = ?');
    $stmt->execute([(string)($_POST['userName'] ?? '')]);
    $restlt = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 預設空陣列：原本是空字串，帳號不存在時 $admin['user_uname'] 會丟 TypeError 變成 500 白畫面，
    // 使用者看不到「使用者名稱或密碼錯誤」（PHP 8 起的行為，順手修掉）
    $admin = [];
    foreach ($restlt as $row){
        $admin = $row;
    }

    $userName = $admin['user_uname']   ?? null;
    $status   = $admin['user_status']  ?? null;
    $id       = $admin['id']           ?? null;

    // 注意：$_SESSION 一律等到「密碼驗證通過且在職狀態允許」之後才寫入（見下方）。
    // 2026-07-30 修正：原本這裡就無條件寫好 session，密碼打錯照樣拿到有效 session，
    // 側欄守門只看 isset($_SESSION['id']) 就放行 → 只要知道帳號就能進系統，離職封鎖也一併被繞過。

    if (isset($_POST['login'])){

        // $admin_name = trim($_POST['userName']);
        // $user_password = trim($_POST['password']);

        if (isset($admin['user_uname'], $admin['user_password'])
            && $_POST['userName'] == $admin['user_uname']
            && $_POST['password'] == $admin['user_password']) {
            // 在職狀態封鎖（離職/留職停薪/育嬰留停），清單見 src/common/user_active_lib.php
            if ($admin['state'] !== null && in_array((int)$admin['state'], eg_blocked_state_list(), true)) {
                $stateLabel = eg_user_state_label($admin['state']);
                if (function_exists('eg_login_log')) eg_login_log($conn->getPDO(), (int)$admin['id'], (string)$_POST['userName'], false, '帳號停用（' . $stateLabel . '）');
                header("Location:../../index.php?msg=" . urlencode('此帳號目前為「' . $stateLabel . '」狀態，無法使用系統'));
                exit();
            }
            // 驗證全部通過，這時才建立 session
            $_SESSION['userName'] = $admin['user_uname'];
            $_SESSION['password'] = $admin['user_password'];
            $_SESSION['status']   = $admin['user_status'];
            $_SESSION['id']       = $admin['id'];

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
            exit();
        }

    }

$cl = $_COOKIE["lastPage"] ?? '';
    
?>
<html>
    <body>
        <P><?=$userName?></P>
        <P><?=$id?></P>
        <P><?=$status?></P>
        <p><?=$cl?></p>
    </body>
</html>

    
 