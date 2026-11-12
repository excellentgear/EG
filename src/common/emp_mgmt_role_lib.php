<?php
/**
 * emp_mgmt_role_lib.php — 員工資料管理（views/ADM/employee_management.php）的角色權限（2026-11-12）
 *
 * 固定四種身分（不可自訂增加）：
 *   全站管理員（系統角色 admin，功能碼 all）＝ A 完整權限
 *   HR管理員（emp_hr_admin）               ＝ A 完整權限（含一鍵登出所有員工等）
 *   HR（emp_hr）                           ＝ C+U 可新增、修改，不含 A／D（不可強制登出全部、不可刪除）
 *   唯讀（emp_view）                       ＝ R 只能看
 *
 * 角色只「加成」：原本在「人員權限設定」設好的頁面權限（user_module_permissions）一律照舊，
 * 頁面與 API 都把兩邊的權限字母聯集，所以現有使用者的權限不會因此變少。
 * 側欄選單：指派角色時由 role_page_r_lib.php 補頁面 R（只新增、不改既有）。
 */
require_once __DIR__ . '/role_features_helper.php';

if (!function_exists('emp_mgmt_roles_ensure')) {
    /** 建立三個角色與功能碼（已存在就不動；每個請求最多查一次） */
    function emp_mgmt_roles_ensure(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $defs = [
                ['emp_hr_admin', 'HR管理員', 'emp_admin'],
                ['emp_hr',       'HR',       'emp_edit'],
                ['emp_view',     '唯讀',     'emp_view'],
            ];
            foreach ($defs as [$code, $name, $feat]) {
                $st = $pdo->prepare("SELECT role_id FROM roles WHERE role_code=? AND module='emp_mgmt' LIMIT 1");
                $st->execute([$code]);
                $rid = (int)$st->fetchColumn();
                if (!$rid) {
                    $pdo->prepare("INSERT INTO roles (role_code, role_name, is_system, module) VALUES (?,?,0,'emp_mgmt')")
                        ->execute([$code, $name]);
                    $rid = (int)$pdo->lastInsertId();
                }
                if ($rid) {
                    $pdo->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?,?)")
                        ->execute([$rid, $feat]);
                }
            }
        } catch (Throwable $e) { error_log('[emp_mgmt_roles_ensure] ' . $e->getMessage()); }
    }
}

if (!function_exists('emp_mgmt_role_chars')) {
    /**
     * 該使用者由「角色」取得的頁面權限字母（'' ＝沒有任何相關角色）。
     * 全站管理員（features 含 all）＝A。
     */
    function emp_mgmt_role_chars(PDO $pdo, int $uid): string {
        if ($uid <= 0) return '';
        try {
            $f = rf_resolve_features($pdo, $uid);
            if (in_array('all', $f, true) || in_array('emp_admin', $f, true)) return 'A';
            $c = '';
            if (in_array('emp_edit', $f, true)) $c .= 'CU';
            if (in_array('emp_view', $f, true)) $c .= 'R';
            return $c;
        } catch (Throwable $e) { return ''; }
    }
}

if (!function_exists('emp_mgmt_merge_perm')) {
    /** 把原本的頁面權限字串與角色權限聯集；有 A 就回 'A'，否則字母排序（與原本頁面邏輯一致） */
    function emp_mgmt_merge_perm(?string $pagePerm, string $roleChars): string {
        $all = array_merge(str_split((string)$pagePerm), str_split($roleChars));
        $all = array_values(array_unique(array_filter($all, 'strlen')));
        if (in_array('A', $all, true)) return 'A';
        sort($all);
        return implode('', $all);
    }
}

if (!function_exists('emp_mgmt_has_role')) {
    /** 是否被指派 HR／唯讀（非管理級）角色。有 → 強制登出與異動紀錄寫入一律只看 A；沒有 → 維持原本（舊制）判定 */
    function emp_mgmt_has_role(PDO $pdo, int $uid): bool {
        $c = emp_mgmt_role_chars($pdo, $uid);
        return $c !== '' && $c !== 'A';
    }
}
