<?php
if (!function_exists('rf_load_user_features')) {
    function rf_load_user_features($pdo, $user_id) {
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
