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

/* ══════════════════════════════════════════════════════════════════════════
   砂輪尺寸預設（2026-10-13 使用者交辦：「請提供砂輪相關尺寸預設功能(包含退刀量)」）

   **刻意存在資料庫而不是瀏覽器 localStorage**：砂輪是現場共用的設備，一支砂輪的
   外徑／刃寬／側面角／刃高／傾角／退刀量是全廠共同的事實，存 localStorage 會變成
   每個人、每台電腦都要自己重打一次，而且改了別人不知道。

   權限：看得到這個工具的人都讀得到預設；**新增／修改／刪除限管理員**（比照齒輪
   計算工具的「預留量管理」——那也是全廠共用的對照表，由管理員維護）。

   存放：system_parameters（param_group='GRIND3D', param_key='presets'）一列 JSON，
   不另開資料表：這是一份幾十筆的設定清單，不是業務資料，開一張表只是多一個要
   維護的東西。
   ══════════════════════════════════════════════════════════════════════════ */

/** 預設值的欄位白名單與夾範圍（與前端 norm() 同一套上下限） */
function grind3d_preset_fields(): array {
    return [
        'Dw'   => [1,   2000],   // 砂輪外徑
        'Tw'   => [0.1,  500],   // 砂輪刃寬
        'pa'   => [0,     60],   // 砂輪側面角
        'Hf'   => [0,    500],   // 刃高
        'tilt' => [-75,   75],   // 砂輪傾角
        'U'    => [0,    500],   // 退刀量
    ];
}

/** 讀出全部預設（陣列，依名稱排序）。永遠回陣列，壞掉的資料一律當成空的。 */
/**
 * 計算結果要顯示哪幾列（管理員可設定，全廠共用）。
 * **存的是「要隱藏的」不是「要顯示的」**：之後程式新增的結果列預設一律看得到，
 * 不會因為設定檔沒提到它就整列消失（新欄位被舊設定吃掉是最難查的那種問題）。
 */
function grind3d_kv_keys(): array {
    return ['cx', 'work', 'margin', 'limit', 'tail', 'tailu',
            'd2d', 'd2ddiff', 'cy', 'hh', 'tb', 'obs'];
}

function grind3d_kv_hidden(PDO $pdo): array {
    try {
        $st = $pdo->prepare("SELECT param_value FROM system_parameters
                             WHERE param_group='GRIND3D' AND param_key='kv_hidden' LIMIT 1");
        $st->execute();
        $raw = $st->fetchColumn();
        $arr = $raw ? json_decode((string)$raw, true) : [];
        if (!is_array($arr)) $arr = [];
    } catch (Throwable $e) { $arr = []; }
    $ok = grind3d_kv_keys();
    $out = [];
    foreach ($arr as $k) { if (in_array($k, $ok, true)) $out[] = $k; }
    return array_values(array_unique($out));
}

function grind3d_kv_save(PDO $pdo, array $hidden, int $userId): array {
    $ok = grind3d_kv_keys();
    $clean = [];
    foreach ($hidden as $k) {
        $k = (string)$k;
        if (in_array($k, $ok, true) && !in_array($k, $clean, true)) $clean[] = $k;
    }
    if (count($clean) >= count($ok)) throw new Exception('至少要保留一列計算結果');
    $st = $pdo->prepare("INSERT INTO system_parameters
            (param_group, param_key, param_value, description, updated_by, updated_at)
        VALUES ('GRIND3D', 'kv_hidden', ?, '砂輪/刀具干涉3D：計算結果要隱藏的列', ?, NOW())
        ON DUPLICATE KEY UPDATE param_value=VALUES(param_value),
            updated_by=VALUES(updated_by), updated_at=NOW()");
    $st->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $userId]);
    return grind3d_kv_hidden($pdo);
}

function grind3d_presets_get(PDO $pdo): array {
    try {
        $st = $pdo->prepare("SELECT param_value FROM system_parameters
                             WHERE param_group='GRIND3D' AND param_key='presets' LIMIT 1");
        $st->execute();
        $raw = $st->fetchColumn();
        $arr = $raw ? json_decode((string)$raw, true) : [];
        if (!is_array($arr)) $arr = [];
    } catch (Throwable $e) { $arr = []; }

    $out = [];
    foreach ($arr as $r) {
        if (!is_array($r)) continue;
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') continue;
        $row = ['name' => mb_substr($name, 0, 40), 'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 60)];
        /* auto＝頁面載入時要自動套用的那一組（全廠只會有一組） */
        $row['auto'] = empty($r['auto']) ? 0 : 1;
        foreach (grind3d_preset_fields() as $k => $lim) {
            if (!isset($r[$k]) || !is_numeric($r[$k])) continue;
            $v = (float)$r[$k];
            $row[$k] = max($lim[0], min($lim[1], $v));
        }
        $out[] = $row;
    }
    usort($out, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return $out;
}

/**
 * 整份覆寫（新增／改名／刪除都走這一支，前端送完整清單）。
 * 一次送整份而不是逐筆增刪：這份清單只有幾十筆，整份覆寫不會有「刪到一半失敗」
 * 的中間狀態，也不必另外處理 id 與併發。
 * @throws Exception 名稱空白、重複、或筆數超過上限時
 */
function grind3d_presets_save(PDO $pdo, array $rows, int $userId): array {
    $clean    = [];
    $seen     = [];
    $autoSeen = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') throw new Exception('每一筆預設都要有名稱');
        $name = mb_substr($name, 0, 40);
        $key  = mb_strtolower($name);
        if (isset($seen[$key])) throw new Exception('預設名稱重複：' . $name);
        $seen[$key] = 1;

        $row = ['name' => $name, 'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 60)];
        /* 自動套用：只能有一組。前端是用下拉選的，後端同規則再擋一次（鐵律8）。 */
        $row['auto'] = (!empty($r['auto']) && $r['auto'] !== '0') ? 1 : 0;
        if ($row['auto']) {
            if (!empty($autoSeen)) throw new Exception('「載入時自動套用」只能指定一組');
            $autoSeen = 1;
        }
        foreach (grind3d_preset_fields() as $k => $lim) {
            if (!isset($r[$k]) || $r[$k] === '' || !is_numeric($r[$k])) continue;
            $row[$k] = max($lim[0], min($lim[1], (float)$r[$k]));
        }
        if (!isset($row['Dw'])) throw new Exception('「' . $name . '」至少要填砂輪外徑');
        $clean[] = $row;
    }
    if (count($clean) > 100) throw new Exception('預設最多 100 筆');

    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare("INSERT INTO system_parameters
            (param_group, param_key, param_value, description, updated_by, updated_at)
        VALUES ('GRIND3D', 'presets', ?, '砂輪干涉3D：砂輪尺寸預設', ?, NOW())
        ON DUPLICATE KEY UPDATE param_value=VALUES(param_value),
            updated_by=VALUES(updated_by), updated_at=NOW()");
    $st->execute([$json, $userId]);
    return grind3d_presets_get($pdo);
}
