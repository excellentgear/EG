<?php
/**
 * QC 容器（包裝容器）唯一實作
 * ------------------------------------------------------------------
 * 儲存位置：bom_ing.QC_ps / bom_ing.QC_ps2（每個製程各自一份，不跨製程共用）
 * 儲存格式：<箱數><容器代碼>，例如 "3P" = 3 個 PP箱（沿用既有 QC 允收跳窗的格式）
 *
 * 消費端：
 *   views/QC/QC_check_list.php        「容器」欄、允收跳窗
 *   views/pm/OreadyReply_ForPm_BaseOfTime.php 發單日欄的容器回報
 *   src/store/_update_qcps.php        寫入端點
 */

/** 容器選項唯一來源（新增/改名只改這裡） */
function eg_qc_container_options() {
    return array(
        array('code' => 'P',  'name' => 'PP箱'),
        array('code' => 'E',  'name' => '蝴蝶籠'),
        array('code' => 'T',  'name' => '鐵桶'),
        array('code' => '板', 'name' => '棧板'),
    );
}

/** 代碼 → 名稱（查不到就原樣回傳，舊資料才不會消失） */
function eg_qc_container_name($code) {
    foreach (eg_qc_container_options() as $o) {
        if ($o['code'] === $code) return $o['name'];
    }
    return $code;
}

/** 代碼是否合法（後端把關用，擋直打 API 塞任意字串） */
function eg_qc_container_valid_code($code) {
    foreach (eg_qc_container_options() as $o) {
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
