<?php
// order_track_perm_lib.php — 訂單追蹤頁（NewOrder_Track222.php）獨立 API 檔共用的權限判斷
// 沿用 Order_Attachment_API.php 既有的 _oaPermCode/_oaCanEdit/_oaIsAdmin 邏輯（舊制 session 快取為主、
// 找不到快取才退回 RBAC），不要各檔案各自重寫一套；$OT_USE_RBAC 尚未啟用前，此處行為需與頁面現行按鈕
// 顯示邏輯一致（$can_batch_draw/$can_to_pm = $can_update && $permission_code==='A'）。
require_once __DIR__ . '/rbac.php';

if (!function_exists('ot_perm_code')) {
    function ot_perm_code(int $uid): string {
        $key = 'perm_code_newordertrack_' . $uid;
        return (isset($_SESSION[$key]) && is_string($_SESSION[$key])) ? $_SESSION[$key] : '';
    }
}
if (!function_exists('ot_is_admin')) {
    function ot_is_admin(PDO $pdo, int $uid): bool {
        if (ot_perm_code($uid) === 'A') return true;
        if (in_array((int)($_SESSION['status'] ?? 0), [9, 90], true)) return true;
        try { return rbac_has(rbac_user_features($pdo, $uid), 'all'); }
        catch (Exception $e) { return true; } // 查詢失敗一律放行，避免鎖死（沿用 rbac.php 既有原則）
    }
}
// 是否具備某項本頁細部功能碼（審圖/轉生管等；舊制沒有細分，只有 'A' 權限才有這些按鈕，比照頁面現行邏輯）
if (!function_exists('ot_has_feature')) {
    function ot_has_feature(PDO $pdo, int $uid, string $feature): bool {
        if (ot_is_admin($pdo, $uid)) return true;
        $code = ot_perm_code($uid);
        if ($code !== '') return $code === 'A';
        try { return rbac_has(rbac_user_features($pdo, $uid), $feature); }
        catch (Exception $e) { return true; }
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
