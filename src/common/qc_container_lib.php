<?php
/**
 * QC 容器（包裝容器）唯一實作
 * ------------------------------------------------------------------
 * 選項來源：system_parameters(param_group='QC_CONTAINER', param_key='options')
 *           維護入口＝views/pm/OreadyReply_ForPm_BaseOfTime.php 的「容器設定」跳窗
 *           （2026-09-01 之前是寫死在各頁面的 <option>，已全部改由這裡供應）
 * 儲存位置：bom_ing.QC_ps / QC_ps2（品管）、bom_ing.pm_ps / pm_ps2（生管）
 *           每個製程各自一份，不跨製程共用
 * 儲存格式：<箱數><容器代碼>，例如 "3P" = 3 個 PP箱
 *
 * 消費端（一律只讀這裡，不可再自己寫一份 option 清單）：
 *   views/pm/OreadyReply_ForPm_BaseOfTime.php  容器回報跳窗、QR 標籤跳窗、容器設定
 *   views/QC/QC_check_list.php                 允收跳窗、箱數計算跳窗、清單容器欄
 *   views/QC/inspection_entry_v2.php           線上檢驗的容器 1 / 容器 2
 *   src/store/_update_qcps.php                 生管寫入端點
 *   src/store/_updateQC_check_list_ok.php      品管允收寫入端點
 *   src/store/QC_Container_API.php             設定讀寫端點
 */

define('EG_QC_CONTAINER_GROUP', 'QC_CONTAINER');
define('EG_QC_CONTAINER_KEY',   'options');

/**
 * 內建預設值：只有「資料庫還沒設定過」或「讀取失敗」時才用。
 * 用途是保底——資料庫讀不到時下拉不可以變成空的，否則現場當場無法回報容器。
 */
function eg_qc_container_defaults() {
    return array(
        array('code' => 'P',  'name' => 'PP箱',   'active' => 1),
        array('code' => 'E',  'name' => '蝴蝶籠', 'active' => 1),
        array('code' => 'T',  'name' => '鐵桶',   'active' => 1),
        array('code' => '板', 'name' => '棧板',   'active' => 1),
    );
}

/**
 * 取得可用的 PDO：優先用呼叫端傳進來的，其次沿用上次傳進來的，最後退回全域 $db
 * （站上頁面與 API 一律用這個變數名）。所以既有呼叫端不帶參數也讀得到資料庫。
 */
function eg_qc_container_db($db = null) {
    static $held = null;
    if ($db instanceof PDO) { $held = $db; return $held; }
    if ($held instanceof PDO) return $held;
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) { $held = $GLOBALS['db']; return $held; }
    return null;
}

/** 全部容器（含已停用），依設定的順序回傳；每筆 array('code','name','active') */
function eg_qc_container_all($db = null, $flush = false) {
    static $cache = null;
    if ($flush) { $cache = null; return array(); }
    if ($cache !== null) return $cache;

    $pdo  = eg_qc_container_db($db);
    $list = null;
    if ($pdo) {
        try {
            $st = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
            $st->execute(array(EG_QC_CONTAINER_GROUP, EG_QC_CONTAINER_KEY));
            $raw = $st->fetchColumn();
            if ($raw !== false && $raw !== null && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $list = $decoded;
            }
        } catch (PDOException $e) {
            error_log('[qc_container all] ' . $e->getMessage());
        }
    }
    if (!is_array($list) || !count($list)) $list = eg_qc_container_defaults();

    $out = array();
    foreach ($list as $o) {
        if (!is_array($o)) continue;
        $code = isset($o['code']) ? trim((string)$o['code']) : '';
        $name = isset($o['name']) ? trim((string)$o['name']) : '';
        if ($code === '' || $name === '') continue;
        $out[] = array(
            'code'   => $code,
            'name'   => $name,
            'active' => (isset($o['active']) && !$o['active']) ? 0 : 1,
        );
    }
    if (!count($out)) $out = eg_qc_container_defaults();
    $cache = $out;
    return $cache;
}

/** 清掉本次請求的快取（存檔後呼叫） */
function eg_qc_container_flush_cache() { eg_qc_container_all(null, true); }

/** 容器選項唯一來源（畫面下拉用：只給啟用中的） */
function eg_qc_container_options($db = null) {
    $out = array();
    foreach (eg_qc_container_all($db) as $o) {
        if (!$o['active']) continue;
        $out[] = array('code' => $o['code'], 'name' => $o['name']);
    }
    // 萬一全部被停用，退回預設，避免現場整排下拉變空的
    return count($out) ? $out : eg_qc_container_defaults();
}

/** 代碼 → 名稱（查不到就原樣回傳，舊資料才不會消失） */
function eg_qc_container_name($code, $db = null) {
    foreach (eg_qc_container_all($db) as $o) {
        if ($o['code'] === $code) return $o['name'];
    }
    return $code;
}

/**
 * 代碼是否合法（後端把關用，擋直打 API 塞任意字串）
 * 刻意連「已停用」的也算合法：停用只是不再出現在新下拉，
 * 使用者頁面還開著舊清單時仍要存得進去，不可以突然存檔失敗。
 */
function eg_qc_container_valid_code($code, $db = null) {
    foreach (eg_qc_container_all($db) as $o) {
        if ($o['code'] === $code) return true;
    }
    return false;
}

/** 解析 "3P" → array('qty'=>3,'code'=>'P','name'=>'PP箱')；解析不出來回 null */
function eg_qc_container_parse($val) {
    $val = trim((string)$val);
    if ($val === '') return null;
    if (!preg_match('/^(\d+)\s*(.+)$/u', $val, $m)) return null;
    $code = trim($m[2]);
    return array('qty' => (int)$m[1], 'code' => $code, 'name' => eg_qc_container_name($code));
}

/**
 * 依 container[] / quantity[] 組出 array(QC_ps, QC_ps2)
 * 規則完全沿用 src/store/_updateQC_check_list_ok.php，避免兩邊寫入格式走鐘
 */
function eg_qc_container_pack($containers, $quantities) {
    $containers = is_array($containers) ? array_values($containers) : array();
    $quantities = is_array($quantities) ? array_values($quantities) : array();
    $pairs = array();
    for ($i = 0; $i < count($containers); $i++) {
        $c = trim((string)$containers[$i]);
        $q = trim((string)(isset($quantities[$i]) ? $quantities[$i] : ''));
        if ($c === '') continue;
        if ($i === 1 && $q === '') continue; // 第二筆若未填箱數則略過（既有規則）
        $pairs[] = ($q === '' ? '0' : $q) . $c;
    }
    return array(
        isset($pairs[0]) ? $pairs[0] : '',
        isset($pairs[1]) ? $pairs[1] : '',
    );
}

/**
 * 各容器代碼目前被幾筆製程資料使用（QC 與生管四個欄位都算）
 * 供設定畫面顯示「用 N」與刪除保護；回傳 array(code => 筆數)
 */
function eg_qc_container_usage($db = null) {
    $pdo = eg_qc_container_db($db);
    $out = array();
    if (!$pdo) return $out;
    try {
        $sql = "SELECT TRIM(REGEXP_REPLACE(v, '^[0-9]+[ ]*', '')) code, COUNT(*) c FROM (
                          SELECT TRIM(QC_ps)  v FROM bom_ing WHERE QC_ps  IS NOT NULL AND QC_ps  <> ''
                UNION ALL SELECT TRIM(QC_ps2)   FROM bom_ing WHERE QC_ps2 IS NOT NULL AND QC_ps2 <> ''
                UNION ALL SELECT TRIM(pm_ps)    FROM bom_ing WHERE pm_ps  IS NOT NULL AND pm_ps  <> ''
                UNION ALL SELECT TRIM(pm_ps2)   FROM bom_ing WHERE pm_ps2 IS NOT NULL AND pm_ps2 <> ''
                ) t GROUP BY code";
        foreach ($pdo->query($sql) as $r) {
            $c = trim((string)$r['code']);
            if ($c !== '') $out[$c] = (int)$r['c'];
        }
    } catch (PDOException $e) {
        error_log('[qc_container usage] ' . $e->getMessage());
    }
    return $out;
}

/**
 * 檢查設定清單是否合法（前端擋一次、這裡再擋一次＝鐵律8）
 * 回傳 array(ok, message, normalized)
 */
function eg_qc_container_validate_list($list) {
    if (!is_array($list) || !count($list)) {
        return array(false, '至少要保留一種容器', array());
    }
    if (count($list) > 50) {
        return array(false, '容器最多 50 種', array());
    }
    $out = array(); $seen = array(); $activeCnt = 0;
    foreach ($list as $i => $o) {
        $no   = $i + 1;
        $code = isset($o['code']) ? trim((string)$o['code']) : '';
        $name = isset($o['name']) ? trim((string)$o['name']) : '';
        $act  = (isset($o['active']) && !$o['active']) ? 0 : 1;

        if ($name === '') return array(false, "第 {$no} 列：顯示名稱不可空白", array());
        if (mb_strlen($name, 'UTF-8') > 20) return array(false, "第 {$no} 列：顯示名稱最多 20 個字", array());
        if ($code === '') return array(false, "第 {$no} 列：儲存代碼不可空白", array());
        if (mb_strlen($code, 'UTF-8') > 10) return array(false, "第 {$no} 列：儲存代碼最多 10 個字", array());
        // 代碼開頭是數字會與「箱數＋代碼」的存法混淆（"3P" 的 3 是箱數）
        if (preg_match('/^\d/u', $code)) {
            return array(false, "第 {$no} 列：儲存代碼不可以數字開頭（存檔格式是「箱數＋代碼」，例 3P）", array());
        }
        // "+" 是兩格容器合併顯示用的分隔字元；空白與逗號會讓解析不穩定
        if (preg_match('/[\s\+,]/u', $code)) {
            return array(false, "第 {$no} 列：儲存代碼不可含空白、加號或逗號", array());
        }
        $key = mb_strtoupper($code, 'UTF-8');
        if (isset($seen[$key])) return array(false, "儲存代碼「{$code}」重複了，每種容器要不一樣", array());
        $seen[$key] = true;
        if ($act) $activeCnt++;
        $out[] = array('code' => $code, 'name' => $name, 'active' => $act);
    }
    if ($activeCnt < 1) return array(false, '至少要有一種容器維持啟用', array());
    return array(true, '', $out);
}

/**
 * 存檔（含刪除保護：已經有資料在用的代碼不可以直接刪掉，只能停用）
 * 回傳 array(ok, message)
 */
function eg_qc_container_save($db, $list, $uid) {
    $pdo = eg_qc_container_db($db);
    if (!$pdo) return array(false, '資料庫連線失敗');

    list($ok, $msg, $norm) = eg_qc_container_validate_list($list);
    if (!$ok) return array(false, $msg);

    $old   = eg_qc_container_all($pdo);
    $newCd = array();
    foreach ($norm as $o) $newCd[$o['code']] = true;

    $usage = eg_qc_container_usage($pdo);
    foreach ($old as $o) {
        if (isset($newCd[$o['code']])) continue;
        $n = isset($usage[$o['code']]) ? $usage[$o['code']] : 0;
        if ($n > 0) {
            return array(false, "「{$o['name']}（{$o['code']}）」已經有 {$n} 筆製程資料在使用，不可以刪除；請改成「停用」——停用之後新的回報不會再出現這個選項，舊資料仍然看得到名稱。");
        }
    }

    try {
        $st = $pdo->prepare(
            "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
             VALUES (?, ?, CAST(? AS JSON), ?, ?, NOW())
             ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), description = VALUES(description),
                                     updated_by = VALUES(updated_by), updated_at = NOW()"
        );
        $st->execute(array(
            EG_QC_CONTAINER_GROUP,
            EG_QC_CONTAINER_KEY,
            json_encode(array_values($norm), JSON_UNESCAPED_UNICODE),
            'QC/生管 容器選項（設定入口：BOM總表 → 容器設定）',
            (string)$uid,
        ));
        eg_qc_container_flush_cache();
        return array(true, '容器設定已儲存');
    } catch (PDOException $e) {
        error_log('[qc_container save] ' . $e->getMessage());
        return array(false, '資料庫錯誤，容器設定未儲存');
    }
}

/**
 * 取某頁面對使用者的權限碼（page scope 優先，其次 group scope）
 * 與各頁面開頭的判定同一套邏輯，供 API 端做鐵律8 的後端把關
 */
function eg_qc_page_perm_code($db, $uid, $pageUrl) {
    $st = $db->prepare("SELECT page_id, group_id FROM system_module_pages WHERE page_url = ? LIMIT 1");
    $st->execute(array($pageUrl));
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;

    $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
    $st->execute(array($uid, $p['page_id']));
    $perms = array_filter($st->fetchAll(PDO::FETCH_COLUMN));

    if (empty($perms) && !empty($p['group_id'])) {
        $st = $db->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
        $st->execute(array($p['group_id']));
        $gm = $st->fetchColumn();
        if ($gm) {
            $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
            $st->execute(array($uid, $gm));
            $perms = array_filter($st->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    return !empty($perms) ? implode('', $perms) : null;
}

/**
 * 是否可以回報容器：BOM總表 或 QC待驗清單 其中之一有 A/C/U 權限即可
 * （容器是現場回報資料，生管與 QC 兩邊都會填）
 */
function eg_qc_container_can_edit($db, $uid) {
    $readonly = false;
    $helper = __DIR__ . '/role_features_helper.php';
    if (is_file($helper)) {
        require_once $helper;
        if (function_exists('rf_load_user_features')) {
            $feats = rf_load_user_features($db, $uid);
            // 唯讀角色一律擋掉（比照 BOM總表：不用 rf_has_feature，'all' 不算數）
            $readonly = is_array($feats) && in_array('oready_readonly', $feats, true);
            if (!$readonly && is_array($feats) &&
                (in_array('oready_update', $feats, true) || in_array('oready_mark_returned', $feats, true))) {
                return true;
            }
        }
    }
    $urls = array('/EGsystem/views/QC/QC_check_list.php');
    if (!$readonly) $urls[] = '/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php';
    foreach ($urls as $u) {
        $code = eg_qc_page_perm_code($db, $uid, $u);
        if ($code && preg_match('/[ACU]/', $code)) return true;
    }
    return false;
}

/**
 * 是否可以「設定」容器種類
 * 比照 BOM總表的製程設定：權限碼含 A，或持有功能碼 oready_process_settings；唯讀角色一律不可
 */
function eg_qc_container_can_settings($db, $uid) {
    $pdo = eg_qc_container_db($db);
    if (!$pdo || !$uid) return false;
    $helper = __DIR__ . '/role_features_helper.php';
    if (is_file($helper)) {
        require_once $helper;
        if (function_exists('rf_load_user_features')) {
            $feats = rf_load_user_features($pdo, $uid);
            if (is_array($feats) && in_array('oready_readonly', $feats, true)) return false;
            if (is_array($feats) && in_array('oready_process_settings', $feats, true)) return true;
        }
    }
    $code = eg_qc_page_perm_code($pdo, $uid, '/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php');
    return ($code && strpos($code, 'A') !== false);
}

/** 設定用 CSRF（只有「容器設定」存檔會驗，現場回報流程完全不受影響） */
function eg_qc_container_csrf() {
    if (empty($_SESSION['qc_ctn_csrf'])) $_SESSION['qc_ctn_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['qc_ctn_csrf'];
}
function eg_qc_container_csrf_ok($t) {
    return $t !== null && $t !== '' && hash_equals((string)(isset($_SESSION['qc_ctn_csrf']) ? $_SESSION['qc_ctn_csrf'] : ''), (string)$t);
}

/**
 * 確保 bom_ing 有「生管回報容器」欄位（可重複執行）
 * pm_ps / pm_ps2 ＝ 生管在 BOM總覽回報的容器；QC_ps / QC_ps2 ＝ 品管在允收跳窗填的容器
 * 兩邊分開存，不一致時畫面才能同時顯示（使用者要求：QC：1P / 生管：2P）
 */
function eg_qc_container_ensure_schema($db) {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $has = $db->query("SHOW COLUMNS FROM bom_ing LIKE 'pm_ps'")->fetch(PDO::FETCH_ASSOC);
        if (!$has) {
            $db->exec("ALTER TABLE bom_ing
                ADD COLUMN pm_ps  VARCHAR(100) NULL COMMENT '生管回報容器',
                ADD COLUMN pm_ps2 VARCHAR(100) NULL COMMENT '生管回報容器2'");
        }
        $done = true;
    } catch (PDOException $e) {
        error_log('[qc_container ensure_schema] ' . $e->getMessage());
        $done = false;
    }
    return $done;
}

/**
 * 組出畫面上的容器顯示文字
 * 兩邊相同或只有一邊 → 直接顯示該值；兩邊都有且不同 → 「QC：1P / 生管：2P」
 * 回傳 array(text, diff)；diff=true 代表兩邊對不起來，畫面要用警示色
 */
function eg_qc_container_display($qc1, $qc2, $pm1, $pm2) {
    $join = function ($a, $b) {
        $o = array();
        foreach (array($a, $b) as $v) { $v = trim((string)$v); if ($v !== '') $o[] = $v; }
        return implode('+', $o);
    };
    $q = $join($qc1, $qc2);
    $p = $join($pm1, $pm2);
    if ($q === '' && $p === '') return array('', false);
    if ($q === '' || $p === '') return array($q !== '' ? $q : $p, false);
    if ($q === $p) return array($q, false);
    return array('QC：' . $q . ' / 生管：' . $p, true);
}
