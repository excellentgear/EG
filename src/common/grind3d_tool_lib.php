<?php
// c:\MAMP\htdocs\EGsystem\src\common\grind3d_tool_lib.php
// ── 砂輪干涉 3D 模擬工具：共用庫（唯一實作，禁止各頁自刻）─────────────────────
// 2026-10-12 新建。使用者需求：「輸入砂輪尺寸、工件齒底徑、肩部尺寸，自動畫出 3D
// 快速看出會不會干涉」＝齒輪計算工具「出尾計算」的 3D 版本（可旋轉、含砂輪角度）。
//   UI（CSS＋浮動／停靠視窗 HTML＋JS）：views/Sales/_grind3d_tool_ui.php
//   獨立頁面（目前的使用入口）：views/TD/grind_interference_3d.php
//
// 這支工具是純前端計算（幾何建模＋干涉判定全部在瀏覽器算），沒有後端 API、不讀寫任何
// 資料表，所以這支庫只負責「誰看得到這顆按鈕」。要再掛一個頁面：
//   $GRIND3D_TOOL_PDO = $pdo; include __DIR__.'/_grind3d_tool_ui.php';
//   再自行放一顆呼叫 openGrind3D() 的按鈕即可，不要複製 CSS/HTML/JS（鐵律4）。
//
// ── 權限為什麼沿用 order_track 模組的功能碼，而不另開一個模組 ──────────────────
// 齒輪計算（ot_gear_calc）與鍵槽計算（ot_keyway_calc）這兩個同性質的計算工具，功能碼
// 本來就登記在 views/Sales/NewOrder_Track.php 的 $OT_PAGE_FEATURES「計算工具」群組裡，
// 由訂單追蹤的角色設定跳窗統一指派。本工具是「出尾計算」的延伸，使用者完全重疊，
// 另開一套模組與角色只會讓管理員要在兩個地方各指派一次（既有決定，見 CLAUDE.md
// 訂單分析那一輪「不另開模組與角色」的同一個理由），故新增功能碼 ot_grind3d 掛在
// 同一個群組，加一列即自動出現在該頁既有的角色設定跳窗。

/** 是否為系統管理員 */
function grind3d_tool_is_admin(): bool {
    return in_array(intval($_SESSION['status'] ?? 0), [9, 90], true);
}

/**
 * 誰可以使用砂輪干涉 3D 模擬工具（唯一判定點）。
 * 系統管理員，或持有 ot_grind3d（砂輪干涉3D）、ot_gear_calc（齒輪計算）功能碼者。
 * 刻意一併放行 ot_gear_calc：本工具就是齒輪計算工具「出尾計算」的 3D 版本，已經在用
 * 出尾計算的人不該為了看同一件事的 3D 再被指派一次權限。
 * @param PDO|null $pdo
 * @param int      $userId 登入者 user.id
 */
function grind3d_tool_can_use($pdo, int $userId): bool {
    if (grind3d_tool_is_admin()) return true;
    if ($userId <= 0 || !($pdo instanceof PDO)) return false;
    try {
        require_once __DIR__ . '/role_features_helper.php';
        $f = rf_load_user_features_all($pdo, $userId);
        return in_array('all', $f, true)
            || in_array('ot_grind3d', $f, true)
            || in_array('ot_gear_calc', $f, true);
    } catch (Exception $e) {
        return false;
    }
}
