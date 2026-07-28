<?php
// c:\MAMP\htdocs\EGsystem\src\common\imgedit_permission.php
// 批圖編輯器（imgedit 模組）使用權限判定──供「要不要顯示批圖按鈕」「能不能進入編輯器」共用，
// 確保按鈕顯示條件與 image_editor.php 進入時的閘門完全一致（避免按鈕看得到卻進不去、或反之）。
//
// 規則（與 views/Sales/image_editor.php 內的 RBAC 區塊同一套）：
//   1. 管理者（user_status 9/90，或系統 admin 角色）固定可用。
//   2. 其他人：若已有任何人被指派 imgedit 模組角色 → 只有被指派者可用；
//              若整個系統尚無任何 imgedit 角色指派 → 暫時開放全部登入者
//              （沿用本系統「DB 未設定頁面權限時暫時允許」的既有慣例）。
//   3. 查詢失敗 → 回傳 true 暫時開放（與 image_editor.php 相同的容錯，寧可放行不誤擋）。

if (!function_exists('imgedit_can_use')) {
    function imgedit_can_use(PDO $pdo, int $uid, bool $isAdmin): bool {
        if ($isAdmin)   return true;
        if ($uid <= 0)  return false;
        try {
            // 系統 admin 角色也視為管理者
            $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                                 WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1");
            $st->execute([$uid]);
            if ((int)$st->fetchColumn() > 0) return true;

            // 是否已有人被指派 imgedit 模組角色
            $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                                 WHERE r.module = 'imgedit'");
            $st->execute();
            if ((int)$st->fetchColumn() === 0) return true;   // 尚無人指派 → 暫時全開

            // 已有人指派 → 只有被指派者可用
            $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                                 WHERE ur.user_id = ? AND r.module = 'imgedit'");
            $st->execute([$uid]);
            return (int)$st->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log('imgedit_can_use error: ' . $e->getMessage());
            return true;   // 查詢失敗沿用系統慣例：暫時開放
        }
    }
}
