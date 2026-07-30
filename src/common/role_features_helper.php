<?php
// 在職狀態封鎖（2026-07-30）：離職/留停者一律視為無任何權限。
// 掛在解析層而不是只在頁面守門，是為了讓不渲染側欄的 API（src/store/*_API.php）也擋得住。
require_once __DIR__ . '/user_active_lib.php';

if (!function_exists('rf_load_user_features')) {
    function rf_load_user_features($pdo, $user_id) {
        if (!eg_user_is_active($pdo, $user_id)) return [];   // 非在職 → 無功能碼
        try {
            $chk = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? LIMIT 1");
            $chk->execute([$user_id]);
            if ((bool)$chk->fetchColumn()) {
                $st = $pdo->prepare("SELECT DISTINCT rf.feature_code FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id WHERE ur.user_id=?");
                $st->execute([$user_id]);
                return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
        } catch (Exception $e) {}
        // 未指派任何角色者回傳空陣列（不像QC頁bootstrap成['all']），
        // 因為呼叫端一律採「舊CRUD規則 OR 新功能碼」並存，未指派角色者仍靠舊規則運作
        return [];
    }
}

if (!function_exists('rf_has_feature')) {
    function rf_has_feature($features, $code) {
        return in_array('all', $features, true) || in_array($code, $features, true);
    }
}

if (!function_exists('rf_load_user_features_all')) {
    // 取得使用者的全部功能碼：個人指派(user_roles) ∪ 職稱指派(position_roles，經 user_department_position_map)
    // 職稱指派＝該職稱所有在職人員自動擁有該角色功能（AS9100文件管理起用；其他模組未指派職稱時結果與舊函式相同）
    function rf_load_user_features_all($pdo, $user_id) {
        if (!eg_user_is_active($pdo, $user_id)) return [];   // 非在職 → 連職稱指派的功能碼也不給
        $features = rf_load_user_features($pdo, $user_id);
        try {
            $st = $pdo->prepare("
                SELECT DISTINCT rf.feature_code
                FROM user_department_position_map m
                JOIN position_roles pr ON pr.position_id = m.position_id
                JOIN role_features rf ON rf.role_id = pr.role_id
                WHERE m.user_id = ?");
            $st->execute([$user_id]);
            $posFeatures = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $features = array_values(array_unique(array_merge($features, $posFeatures)));
        } catch (Exception $e) {}
        return $features;
    }
}

if (!function_exists('rf_load_user_features_override')) {
    // 模組內「職稱為主、個人優先」規則（AS9100 文件管理 2026-07-16 定案）：
    //   1. 使用者在該模組有「個人指派」角色 → 只用個人角色（職稱指派不再套用，個人設定覆蓋職稱）
    //   2. 否則 → 套用其職稱（含兼任）被指派的該模組角色
    //   3. 系統角色（管理員 'all'）永遠併入
    function rf_load_user_features_override($pdo, $user_id, $module) {
        if (!eg_user_is_active($pdo, $user_id)) return [];   // 非在職 → 無功能碼（含管理員角色）
        $features = [];
        try {
            // 系統角色（全域）
            $st = $pdo->prepare("
                SELECT DISTINCT rf.feature_code
                FROM user_roles ur
                JOIN roles r ON r.role_id = ur.role_id AND r.is_system = 1
                JOIN role_features rf ON rf.role_id = r.role_id
                WHERE ur.user_id = ?");
            $st->execute([$user_id]);
            $features = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

            // 個人在該模組的角色
            $st = $pdo->prepare("
                SELECT DISTINCT rf.feature_code
                FROM user_roles ur
                JOIN roles r ON r.role_id = ur.role_id AND r.is_system = 0 AND r.module = ?
                JOIN role_features rf ON rf.role_id = r.role_id
                WHERE ur.user_id = ?");
            $st->execute([$module, $user_id]);
            $personal = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

            if (!empty($personal)) {
                // 個人有設定 → 以個人為準
                return array_values(array_unique(array_merge($features, $personal)));
            }
            // 個人無設定 → 套用職稱指派
            $st = $pdo->prepare("
                SELECT DISTINCT rf.feature_code
                FROM user_department_position_map m
                JOIN position_roles pr ON pr.position_id = m.position_id
                JOIN roles r ON r.role_id = pr.role_id AND r.module = ?
                JOIN role_features rf ON rf.role_id = pr.role_id
                WHERE m.user_id = ?");
            $st->execute([$module, $user_id]);
            $pos = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_values(array_unique(array_merge($features, $pos)));
        } catch (Exception $e) { return $features; }
    }
}

if (!function_exists('rf_has_module_role_all')) {
    // 二元權限判斷（含職稱指派版）：個人被指派該 module 角色、或其任一職稱被指派該 module 角色、或系統管理員
    function rf_has_module_role_all($pdo, $user_id, $module) {
        if (!eg_user_is_active($pdo, $user_id)) return false;   // 非在職 → 無使用資格
        if (rf_has_module_role($pdo, $user_id, $module)) return true;
        try {
            $st = $pdo->prepare("
                SELECT 1
                FROM user_department_position_map m
                JOIN position_roles pr ON pr.position_id = m.position_id
                JOIN roles r ON r.role_id = pr.role_id
                WHERE m.user_id = ? AND r.module = ?
                LIMIT 1");
            $st->execute([$user_id, $module]);
            return (bool)$st->fetchColumn();
        } catch (Exception $e) { return false; }
    }
}

if (!function_exists('rf_has_module_role')) {
    // 二元權限判斷：使用者是否被指派了該 module 底下的任一角色，或本身是系統管理員(is_system=1)
    // 用於不需要細分功能碼、只要「有沒有這個功能的使用資格」的場景（例如 BOM追蹤）
    function rf_has_module_role($pdo, $user_id, $module) {
        if (!eg_user_is_active($pdo, $user_id)) return false;   // 非在職 → 無使用資格
        try {
            $st = $pdo->prepare("
                SELECT 1 FROM user_roles ur
                JOIN roles r ON r.role_id = ur.role_id
                WHERE ur.user_id = ? AND (r.module = ? OR r.is_system = 1)
                LIMIT 1
            ");
            $st->execute([$user_id, $module]);
            return (bool)$st->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
}
