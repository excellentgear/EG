<?php
// order_track_perm_lib.php — 訂單追蹤頁（NewOrder_Track.php/222.php）獨立 API 檔共用的權限判斷
// 2026-08-11：$OT_USE_RBAC 已於頁面端正式切換為 true（角色制生效），此處改為 RBAC 為主、
// 舊制 session 快取（perm_code_newordertrack_*）僅在使用者完全未被指派任何角色時才退回當備援——
// 舊寫法反過來（快取非空就直接用快取判斷、忽略 RBAC）會讓已在角色設定頁勾選好功能碼的使用者，
// 只要 session 裡還留著舊值就整批被擋 403（快取值只有等於 'A' 才放行、其餘一律視為無權限，
// 連請求的是哪個 feature 都沒比對），與頁面本身（改走 rf_load_user_features_all）行為不一致。
// 角色查詢一律走全站共用 helper（個人指派 ∪ 職稱指派 ∪ 請假完整承接代理），不要自己重寫一套。
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/role_features_helper.php';

if (!function_exists('ot_perm_code')) {
    function ot_perm_code(int $uid): string {
        $key = 'perm_code_newordertrack_' . $uid;
        return (isset($_SESSION[$key]) && is_string($_SESSION[$key])) ? $_SESSION[$key] : '';
    }
}
// 是否為超級管理員（帳號 e，id=1）。2026-08-20 使用者拍板：審圖/轉生管的「只有本人（該訂單指定的
// 設計人員）才能操作」限制，只有超級管理員可以豁免，其他任何管理員都不行——見 ot_can_operate_design()。
if (!function_exists('ot_is_super_admin')) {
    function ot_is_super_admin(int $uid): bool { return $uid === 1; }
}
if (!function_exists('ot_is_admin')) {
    function ot_is_admin(PDO $pdo, int $uid): bool {
        // 2026-08-20 移除原本的 `$_SESSION['status'] IN (9,90) 就當管理員` 捷徑（使用者拍板一併收掉）：
        // user_status=90 的共用帳號（生管公用/報工公用/製造課長）畫面上本來就看不到這些按鈕，
        // 卻能直接打 API 繞過「只有本人」限制。改為一律走 RBAC 角色判定。
        try {
            $features = rf_load_user_features_all($pdo, $uid);
            if (!empty($features)) return rf_has_feature($features, 'all');
        } catch (Exception $e) { return true; } // 查詢失敗一律放行，避免鎖死（沿用既有原則）
        // 完全未被指派任何角色 → 退回舊制 perm_code（過渡期尚未遷移的使用者）
        return ot_perm_code($uid) === 'A';
    }
}
// 是否具備某項本頁細部功能碼（審圖/轉生管等）：RBAC 為主，比照頁面現行邏輯（rf_load_user_features_all）
if (!function_exists('ot_has_feature')) {
    function ot_has_feature(PDO $pdo, int $uid, string $feature): bool {
        if (ot_is_admin($pdo, $uid)) return true;
        try {
            $features = rf_load_user_features_all($pdo, $uid);
            if (!empty($features)) return rf_has_feature($features, $feature);
        } catch (Exception $e) { return true; }
        // 完全未被指派任何角色 → 退回舊制 perm_code（過渡期尚未遷移的使用者）
        $code = ot_perm_code($uid);
        if ($code !== '') return $code === 'A';
        return true; // 兩者皆無資料，避免鎖死
    }
}
// 是否為該訂單目前指定的設計人員（order_track.ate）
if (!function_exists('ot_is_assigned_designer')) {
    function ot_is_assigned_designer(PDO $pdo, int $uid, int $orderId): bool {
        try {
            $st = $pdo->prepare("SELECT ate FROM order_track WHERE Order_id = ?");
            $st->execute([$orderId]);
            return (int)$st->fetchColumn() === $uid;
        } catch (Exception $e) { return false; }
    }
}
// 審圖/轉生管操作門檻：只有超級管理員可操作任何訂單；其餘所有人（含持有系統「管理員」角色者）
// 都必須具備對應功能碼，且只能操作自己被指定(order_track.ate)的那些訂單。
// 2026-08-20 使用者拍板改成這樣（原本是 ot_is_admin() 就放行）：本頁 2026-08-11 切換 RBAC 後，
// 「管理員」的認定基準從舊制頁面權限碼 'A' 變成全站系統角色（功能碼 all），使得原本沒有 'A'、
// order_track 角色又刻意沒勾 ot_batch_draw/ot_to_pm 的人，因為另外掛著系統管理員角色而每一列都能操作。
// 前端同規則（views/Sales/NewOrder_Track.php 的 $OT_IS_ADMIN_ANY），兩邊要一起改。
if (!function_exists('ot_can_operate_design')) {
    function ot_can_operate_design(PDO $pdo, int $uid, int $orderId, string $feature): bool {
        if (ot_is_super_admin($uid)) return true;
        if (!ot_has_feature($pdo, $uid, $feature)) return false;
        return ot_is_assigned_designer($pdo, $uid, $orderId);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 更改「已綁定料號訂單」的客戶（2026-09-03 使用者要求）—— 唯一實作
// ──────────────────────────────────────────────────────────────────────────────
// 背景：訂單一旦綁定料號，客戶就由料號決定（src/store/_NewOrder_Track.php 會在存檔時
// 用 d_setting.Customer_Id 覆蓋 Client_name_ID），畫面上客戶欄因此是唯讀反灰的。
// 現在要讓「角色勾了 ot_order_change_client」的人可以改：點客戶欄的鎖頭 → 輸入本人登入
// 密碼 → 解鎖後重新指定客戶與料號。
//
// 三個刻意這樣做的地方：
//  1. ot_can_change_client() **不 fail-open**。ot_has_feature() 對「完全沒有被指派角色」
//     的人是回 true 的（過渡期相容），那對這種解鎖動作不適用——沒指派角色的人在畫面上
//     根本看不到鎖頭，後端卻放行的話等於直接打 API 就能改，前後端規則會不一致。
//  2. 解鎖狀態記在 session、**逐張訂單各自解鎖**且有有效期，不是一個全域旗標——否則解鎖
//     一次之後這個人當天改任何一張訂單的客戶都不必再驗密碼。
//  3. 密碼比對的是**本人的登入密碼**（user.user_password，與 Login.php 同一套明碼比對），
//     不是 confirm_password_lib 的操作確認密碼——後者只有超級管理員與被授權的管理員才有，
//     業務人員拿到這個角色也會一輩子解不開。
// ══════════════════════════════════════════════════════════════════════════════
if (!defined('OT_CLIENT_UNLOCK_TTL'))      define('OT_CLIENT_UNLOCK_TTL', 1800);  // 解鎖有效秒數（30 分鐘）
if (!defined('OT_CLIENT_UNLOCK_MAX_FAIL')) define('OT_CLIENT_UNLOCK_MAX_FAIL', 5); // 密碼連續錯幾次暫停嘗試
if (!defined('OT_CLIENT_UNLOCK_FAIL_WAIT'))define('OT_CLIENT_UNLOCK_FAIL_WAIT', 900); // 達上限後暫停秒數（15 分鐘）

// 是否具備「更改已建立訂單客戶」的功能碼（管理員 all 亦可）。查詢失敗或無角色一律回 false。
if (!function_exists('ot_can_change_client')) {
    function ot_can_change_client(PDO $pdo, int $uid): bool {
        if ($uid <= 0) return false;
        try {
            $features = rf_load_user_features_all($pdo, $uid);
            if (empty($features)) return false;
            return rf_has_feature($features, 'ot_order_change_client');
        } catch (Exception $e) { return false; }
    }
}

// 驗證本人登入密碼（比照 src/store/Login.php：user.user_password 明碼比對）
if (!function_exists('ot_verify_own_password')) {
    function ot_verify_own_password(PDO $pdo, int $uid, string $password): array {
        if ($password === '') return ['ok' => false, 'msg' => '請輸入本人密碼'];
        try {
            $st = $pdo->prepare("SELECT user_password FROM `user` WHERE id = ? LIMIT 1");
            $st->execute([$uid]);
            $real = $st->fetchColumn();
            if ($real === false) return ['ok' => false, 'msg' => '查無登入帳號，請重新登入後再試'];
            if (!hash_equals((string)$real, $password)) {
                return ['ok' => false, 'msg' => '密碼錯誤，請輸入您自己的登入密碼'];
            }
            return ['ok' => true, 'msg' => ''];
        } catch (Exception $e) {
            return ['ok' => false, 'msg' => '密碼驗證失敗，請稍後再試'];
        }
    }
}

// 密碼連錯上限：達上限後暫停一段時間才能再試（同一個 session 內計數，成功即歸零）
if (!function_exists('ot_client_unlock_fail_wait')) {
    /** 還要等幾秒才能再試；0＝現在就可以試 */
    function ot_client_unlock_fail_wait(): int {
        $f = $_SESSION['ot_client_unlock_fail'] ?? null;
        if (!is_array($f) || (int)($f['count'] ?? 0) < OT_CLIENT_UNLOCK_MAX_FAIL) return 0;
        $left = (int)($f['at'] ?? 0) + OT_CLIENT_UNLOCK_FAIL_WAIT - time();
        if ($left <= 0) { unset($_SESSION['ot_client_unlock_fail']); return 0; }
        return $left;
    }
}
if (!function_exists('ot_client_unlock_fail_add')) {
    function ot_client_unlock_fail_add(): void {
        $f = $_SESSION['ot_client_unlock_fail'] ?? ['count' => 0, 'at' => 0];
        $_SESSION['ot_client_unlock_fail'] = ['count' => (int)($f['count'] ?? 0) + 1, 'at' => time()];
    }
}
if (!function_exists('ot_client_unlock_fail_reset')) {
    function ot_client_unlock_fail_reset(): void { unset($_SESSION['ot_client_unlock_fail']); }
}

// 解鎖狀態（逐張訂單、逐人、有有效期）
if (!function_exists('ot_client_unlock_mark')) {
    function ot_client_unlock_mark(int $uid, int $orderId): void {
        if (!isset($_SESSION['ot_client_unlock']) || !is_array($_SESSION['ot_client_unlock'])) {
            $_SESSION['ot_client_unlock'] = [];
        }
        $_SESSION['ot_client_unlock'][$uid . ':' . $orderId] = time();
    }
}
if (!function_exists('ot_client_unlock_valid')) {
    function ot_client_unlock_valid(int $uid, int $orderId): bool {
        $k = $uid . ':' . $orderId;
        $t = $_SESSION['ot_client_unlock'][$k] ?? 0;
        if (!$t) return false;
        if (time() - (int)$t > OT_CLIENT_UNLOCK_TTL) { unset($_SESSION['ot_client_unlock'][$k]); return false; }
        return true;
    }
}
if (!function_exists('ot_client_unlock_clear')) {
    function ot_client_unlock_clear(int $uid, int $orderId): void {
        unset($_SESSION['ot_client_unlock'][$uid . ':' . $orderId]);
    }
}

/**
 * 存檔前的守門：這次更新有沒有在「不該改」的情況下改掉客戶。
 * 只有「原本客戶與料號都已綁定，而這次要換成不同客戶」才需要權限＋解鎖；
 * 其餘情況（原本沒綁客戶要補綁、原本沒綁料號、客戶沒變）一律照舊放行，
 * 既有的自動補綁客戶／快速綁定流程完全不受影響。
 *
 * @return array ['ok'=>bool, 'msg'=>string, 'changed'=>bool]
 */
if (!function_exists('ot_client_change_guard')) {
    function ot_client_change_guard(PDO $pdo, int $uid, int $orderId, $oldClientId, $oldPartId, $newClientId): array {
        $old = trim((string)$oldClientId);
        $new = trim((string)$newClientId);
        if ($old === '' || $new === '' || $old === $new) return ['ok' => true, 'msg' => '', 'changed' => false];
        if (empty($oldPartId))                            return ['ok' => true, 'msg' => '', 'changed' => false];
        if (!ot_can_change_client($pdo, $uid)) {
            return ['ok' => false, 'changed' => true,
                    'msg' => '本訂單已綁定料號，客戶由料號決定，您沒有更改客戶的權限（需角色勾選「更改已建立訂單的客戶」）。'];
        }
        if (!ot_client_unlock_valid($uid, $orderId)) {
            return ['ok' => false, 'changed' => true,
                    'msg' => '更改客戶前請先點客戶欄的鎖頭並輸入本人密碼解鎖（解鎖逾時請重新解鎖）。'];
        }
        return ['ok' => true, 'msg' => '', 'changed' => true];
    }
}

/** 客戶更改留稽核（audit_log 全站共用表；寫入失敗不影響主要作業） */
if (!function_exists('ot_client_change_audit')) {
    function ot_client_change_audit(PDO $pdo, int $uid, int $orderId, string $orderNo, $oldClientId, $newClientId, $oldPartId, $newPartId): void {
        try {
            $name = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? (string)$uid);
            $chg  = [['field' => '客戶ID', 'old' => (string)$oldClientId, 'new' => (string)$newClientId]];
            if ((string)$oldPartId !== (string)$newPartId) {
                $chg[] = ['field' => '料號ID', 'old' => (string)$oldPartId, 'new' => (string)$newPartId];
            }
            $pdo->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                           VALUES ('update','order_client',?,?,?,?,?,NOW())")
                ->execute([(string)$orderId, $orderNo, json_encode($chg, JSON_UNESCAPED_UNICODE), $uid, $name]);
        } catch (Exception $e) { /* 稽核寫入失敗不擋主要作業 */ }
    }
}
