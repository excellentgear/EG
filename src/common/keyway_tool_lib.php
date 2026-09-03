<?php
// c:\MAMP\htdocs\EGsystem\src\common\keyway_tool_lib.php
// ── 鍵槽計算工具：共用庫（唯一實作，禁止各頁自刻）────────────────────────────
// 2026-09-03 由 views/Sales/NewOrder_Track.php 抽出，供多頁共用（比照齒輪計算工具的做法）。
//   UI（CSS＋浮動視窗 HTML＋JS）：views/Sales/_keyway_tool_ui.php
//   使用端：views/Sales/NewOrder_Track.php（訂單追蹤）、views/Sales/image_editor.php（批圖編輯器）
// 本工具是純前端計算（軸件／片狀鍵槽的公差與極限值），沒有後端 API、不讀寫任何資料表，
// 所以這支庫只負責「誰看得到這顆按鈕」。要再加一個頁面用這個工具：
//   include _keyway_tool_ui.php 並自行放一顆呼叫 openKwTool() 的按鈕即可，不要複製 CSS/HTML/JS（鐵律4）。

/** 是否為系統管理員 */
function keyway_tool_is_admin(): bool {
    return in_array(intval($_SESSION['status'] ?? 0), [9, 90], true);
}

/**
 * 誰可以使用鍵槽計算工具（唯一判定點）。
 * 沿用 NewOrder_Track 既有的 RBAC 規則：系統管理員（功能碼 all）或持有 ot_keyway_calc 的角色。
 * 註：訂單追蹤頁在 $OT_USE_RBAC 尚未啟用時是「一律可見」（舊制從未限制過），
 *     該頁自己算好的 $can_keyway_calc 會被 UI 檔沿用，不會被這支覆寫。
 * @param PDO $pdo
 * @param int $userId 登入者 user.id
 */
function keyway_tool_can_use($pdo, int $userId): bool {
    if (keyway_tool_is_admin()) return true;
    if ($userId <= 0) return false;
    try {
        require_once __DIR__ . '/role_features_helper.php';
        $f = rf_load_user_features_all($pdo, $userId);
        return in_array('all', $f, true) || in_array('ot_keyway_calc', $f, true);
    } catch (Exception $e) {
        return false;
    }
}
