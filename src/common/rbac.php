<?php
// rbac.php — 全域角色權限判定（與 Roles_API.php / quotation_list_NEW.php 同一套 RBAC）
// 使用：
//   require_once '.../src/common/rbac.php';
//   $features = rbac_user_features($pdo, $userId);
//   if (rbac_has($features, 'notice_view')) { ... }
//
// 規則：
//   - 使用者的功能 = 其所有角色(user_roles)對應的 role_features 的聯集。
//   - 'all' 代表全部功能（系統管理員角色）。
//   - **已被指派角色的使用者：一律以其角色功能為準**（即使該角色沒有任何功能＝無權限），
//     不會因為「系統尚無管理員」而被放寬成全權。
//   - 安全裝置(bootstrap)：**僅對「完全未被指派任何角色」的使用者生效**——
//     若此人沒有任何角色，且系統目前也還沒有任何管理員(all)，視為尚未設定完成、暫時全權，
//     讓最初的管理者能進入頁面設定角色。一旦有人被指派管理員，未指派者即無權限。
//   - 資料表不存在/查詢失敗時，回傳全權，避免鎖死。

if (!function_exists('rbac_user_features')) {
    function rbac_user_features(PDO $pdo, int $uid): array {
        try {
            // 此使用者是否已被指派任何角色
            $chk = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id = ? LIMIT 1");
            $chk->execute([$uid]);
            $hasRoles = (bool)$chk->fetchColumn();

            if ($hasRoles) {
                // 已指派角色 → 嚴格以角色功能聯集為準（不再 bootstrap 覆蓋）
                $stmt = $pdo->prepare("
                    SELECT DISTINCT rf.feature_code
                    FROM user_roles ur
                    JOIN role_features rf ON rf.role_id = ur.role_id
                    WHERE ur.user_id = ?");
                $stmt->execute([$uid]);
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // 未指派任何角色 → bootstrap：系統尚無管理員則暫時全權，否則無權限
            $anyAdmin = (int)$pdo->query("
                SELECT COUNT(*) FROM user_roles ur
                JOIN role_features rf ON rf.role_id = ur.role_id
                WHERE rf.feature_code = 'all'")->fetchColumn();
            return ($anyAdmin === 0) ? ['all'] : [];
        } catch (Exception $e) {
            // 資料表尚未建立等情況 → 全權，避免鎖死
            return ['all'];
        }
    }
}

if (!function_exists('rbac_has')) {
    function rbac_has(array $features, string $feature): bool {
        return in_array('all', $features, true) || in_array($feature, $features, true);
    }
}
