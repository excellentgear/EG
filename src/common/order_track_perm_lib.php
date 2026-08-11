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
if (!function_exists('ot_is_admin')) {
    function ot_is_admin(PDO $pdo, int $uid): bool {
        if (in_array((int)($_SESSION['status'] ?? 0), [9, 90], true)) return true;
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
// 審圖/轉生管操作門檻：管理員可操作任何訂單；一般設計人員（需具備對應功能碼）只能操作自己被指定的訂單
if (!function_exists('ot_can_operate_design')) {
    function ot_can_operate_design(PDO $pdo, int $uid, int $orderId, string $feature): bool {
        if (ot_is_admin($pdo, $uid)) return true;
        if (!ot_has_feature($pdo, $uid, $feature)) return false;
        return ot_is_assigned_designer($pdo, $uid, $orderId);
    }
}
