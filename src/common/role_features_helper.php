<?php
// role_features_helper.php — 全站「這個人有哪些功能碼」的唯一解析點
//
// 在職狀態封鎖（2026-07-30）：離職/留停者一律視為無任何權限。
// 掛在解析層而不是只在頁面守門，是為了讓不渲染側欄的 API（src/store/*_API.php）也擋得住。
//
// ── 解析順序（2026-08-27 使用者拍板）──────────────────────────────────────
//   1. 個人指派（user_roles）           ← 同一個模組有設定就以個人為準
//   2. 部門＋職稱指派（position_roles）  ← 個人在該模組沒設定時自動套用
//   3. 代理「完整承接權限」              ← 另外併入（只有 *_all 系列才算）
//   系統角色（roles.is_system=1，例如管理員 'all'）與沒有歸模組的角色一律無條件併入。
//
//   「優先」是**逐模組**判斷，不是整個人二選一：某人自己被指派了報價單角色，
//   不會因此失去「部門＋職稱」帶來的訂單追蹤角色。
//
// ── 為什麼職稱一定要帶部門 ────────────────────────────────────────────────
//   職稱是跨部門共用的：實測「組員」橫跨 7 個部門 22 人、「組長」橫跨 7 個部門、
//   「課長」橫跨 5 個部門。只綁職稱＝品管組員會拿到業務組員的權限。
//   position_roles.department_id：0＝該職稱所有部門通用（這張表原本的語意，既有資料都是 0）、
//   >0＝只有該部門的該職稱適用。**所有 JOIN position_roles 的地方都要帶
//   `AND (pr.department_id=0 OR pr.department_id=m.department_id)`**，否則就是跨部門越權。
require_once __DIR__ . '/user_active_lib.php';

// position_roles 的部門條件（唯一寫法，呼叫端一律用這個常數拼 SQL，不要各自手寫）
if (!defined('RF_PR_DEPT_COND')) {
    define('RF_PR_DEPT_COND', '(pr.department_id = 0 OR pr.department_id = m.department_id)');
}

if (!function_exists('rf_features_by_module')) {
    /**
     * 取得某一層的功能碼，依模組分組。
     * @param string $src 'user'＝個人指派(user_roles)／'position'＝部門+職稱指派(position_roles)
     * @return array [module => [feature_code,...]]；key '' 代表系統角色或未歸模組的角色（一律無條件併入）
     */
    function rf_features_by_module(PDO $pdo, string $src, int $uid): array {
        $out = [];
        try {
            if ($src === 'user') {
                $sql = "SELECT r.module, r.is_system, rf.feature_code
                        FROM user_roles ur
                        JOIN roles r ON r.role_id = ur.role_id
                        JOIN role_features rf ON rf.role_id = r.role_id
                        WHERE ur.user_id = ?";
            } else {
                $sql = "SELECT r.module, r.is_system, rf.feature_code
                        FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id = m.position_id AND " . RF_PR_DEPT_COND . "
                        JOIN roles r ON r.role_id = pr.role_id
                        JOIN role_features rf ON rf.role_id = r.role_id
                        WHERE m.user_id = ?";
            }
            $st = $pdo->prepare($sql);
            $st->execute([$uid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // 系統角色與未歸模組的角色放進 '' 這一組＝不參與「個人覆蓋職稱」的比較，永遠併入
                $key = ((int)$r['is_system'] === 1 || $r['module'] === null || $r['module'] === '') ? '' : $r['module'];
                $out[$key][] = $r['feature_code'];
            }
            foreach ($out as $k => $v) $out[$k] = array_values(array_unique($v));
        } catch (Exception $e) {}
        return $out;
    }
}

if (!function_exists('rf_resolve_features')) {
    /**
     * 依「個人 → 部門+職稱」的優先序解析功能碼（不含代理承接）。
     * @param string|null $only_module 只要某一個模組的結果時傳模組代碼；null＝全部模組
     */
    function rf_resolve_features(PDO $pdo, int $uid, ?string $only_module = null): array {
        if (!eg_user_is_active($pdo, $uid)) return [];   // 非在職 → 無功能碼（含管理員角色）
        $personal = rf_features_by_module($pdo, 'user', $uid);
        $bypos    = rf_features_by_module($pdo, 'position', $uid);

        // 系統角色／未歸模組：兩層都無條件併入
        $out = array_merge($personal[''] ?? [], $bypos[''] ?? []);

        $modules = ($only_module !== null && $only_module !== '')
            ? [$only_module]
            : array_unique(array_merge(array_keys($personal), array_keys($bypos)));
        foreach ($modules as $m) {
            if ($m === '') continue;
            if (!empty($personal[$m]))   $out = array_merge($out, $personal[$m]);   // 個人有設定＝以個人為準
            elseif (!empty($bypos[$m]))  $out = array_merge($out, $bypos[$m]);      // 個人沒設定＝套用部門職稱
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('rf_load_user_features')) {
    // 個人指派 ⊕ 部門+職稱指派（逐模組個人優先）。不含代理承接——要含代理請用 rf_load_user_features_all()。
    // 2026-08-27 起本函式已納入「部門+職稱」層：原本只認 user_roles，導致同部門同職稱的人每個都要手動設一次。
    function rf_load_user_features($pdo, $user_id) {
        return rf_resolve_features($pdo, (int)$user_id);
    }
}

if (!function_exists('rf_has_feature')) {
    function rf_has_feature($features, $code) {
        return in_array('all', $features, true) || in_array($code, $features, true);
    }
}

if (!function_exists('rf_load_full_inherit_delegate_features')) {
    /**
     * 代理系統「完整承接權限」（2026-08-06 新增）：留職停薪/育嬰留停等假別若在 leave_type.full_inherit_permission
     * 勾選了此項，代理人在核准生效期間，於被代理人「該職務身分」範圍內現場借用其 position_roles 功能碼——
     * 不只是簽核，連頁面/設定操作權限都一併承接。與一般假別（只走 eg_resolve_signer() 找 signer_id 簽核、
     * 不動 RBAC）明確區隔，見 ai-rules/11 第 12 節。
     *
     * 現場查詢、不靠排程：假期自然到期、或人事用 eg_leave_early_end() 提前結束（會縮短 end_datetime）、
     * 或整單被銷假（status 離開 approved），都會讓下一次查詢立刻不再命中，權限即時收回。
     *
     * scope_position_id 為 NULL（全域代理，通常是該員工只有一個職務身分時）則回退承接其目前掛的所有職務身分。
     * 2026-08-27：position_roles 帶部門後，這裡的 m 一律接被代理人「該職務身分那一列」，才有部門可比對。
     */
    function rf_load_full_inherit_delegate_features($pdo, $user_id) {
        $features = [];
        try {
            $st = $pdo->prepare("
                SELECT DISTINCT rf.feature_code
                FROM leave_request_agent ra
                JOIN leave_request lr ON lr.id = ra.leave_request_id
                JOIN leave_type lt ON lt.id = lr.leave_type_id
                LEFT JOIN user_department_position_map m
                       ON m.user_id = lr.employee_id
                      AND (ra.scope_position_id IS NULL OR m.position_id = ra.scope_position_id)
                JOIN position_roles pr ON pr.position_id = COALESCE(ra.scope_position_id, m.position_id)
                                      AND (pr.department_id = 0 OR pr.department_id = m.department_id)
                JOIN role_features rf ON rf.role_id = pr.role_id
                WHERE ra.agent_user_id = ?
                  AND lt.full_inherit_permission = 1
                  AND lr.status = 'approved'
                  AND NOW() BETWEEN lr.start_datetime AND lr.end_datetime");
            $st->execute([$user_id]);
            $features = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) {}
        return $features;
    }
}

if (!function_exists('rf_load_user_features_all')) {
    // 個人指派 ⊕ 部門+職稱指派（逐模組個人優先）∪ 代理完整承接
    function rf_load_user_features_all($pdo, $user_id) {
        if (!eg_user_is_active($pdo, $user_id)) return [];   // 非在職 → 連職稱指派的功能碼也不給
        $features = rf_resolve_features($pdo, (int)$user_id);
        return array_values(array_unique(array_merge($features, rf_load_full_inherit_delegate_features($pdo, $user_id))));
    }
}

if (!function_exists('rf_load_user_features_override')) {
    // 模組內「職稱為主、個人優先」規則（AS9100 文件管理 2026-07-16 定案；2026-08-27 起與全站同一套解析）：
    //   1. 使用者在該模組有「個人指派」角色 → 只用個人角色（部門職稱不再套用，個人設定覆蓋）
    //   2. 否則 → 套用其「部門＋職稱」（含兼任）被指派的該模組角色
    //   3. 系統角色（管理員 'all'）永遠併入
    function rf_load_user_features_override($pdo, $user_id, $module) {
        return rf_resolve_features($pdo, (int)$user_id, (string)$module);
    }
}

if (!function_exists('rf_has_module_role_all')) {
    // 二元權限判斷（含代理版）：個人被指派該 module 角色、或其「部門＋職稱」被指派該 module 角色、
    // 或透過「完整承接權限」代理借到該 module 角色、或系統管理員
    function rf_has_module_role_all($pdo, $user_id, $module) {
        if (!eg_user_is_active($pdo, $user_id)) return false;   // 非在職 → 無使用資格
        if (rf_has_module_role($pdo, $user_id, $module)) return true;
        try {
            $st = $pdo->prepare("
                SELECT 1
                FROM leave_request_agent ra
                JOIN leave_request lr ON lr.id = ra.leave_request_id
                JOIN leave_type lt ON lt.id = lr.leave_type_id
                LEFT JOIN user_department_position_map m
                       ON m.user_id = lr.employee_id
                      AND (ra.scope_position_id IS NULL OR m.position_id = ra.scope_position_id)
                JOIN position_roles pr ON pr.position_id = COALESCE(ra.scope_position_id, m.position_id)
                                      AND (pr.department_id = 0 OR pr.department_id = m.department_id)
                JOIN roles r ON r.role_id = pr.role_id
                WHERE ra.agent_user_id = ? AND r.module = ?
                  AND lt.full_inherit_permission = 1 AND lr.status = 'approved'
                  AND NOW() BETWEEN lr.start_datetime AND lr.end_datetime
                LIMIT 1");
            $st->execute([$user_id, $module]);
            return (bool)$st->fetchColumn();
        } catch (Exception $e) { return false; }
    }
}

if (!function_exists('oready_resolve_can_transfer')) {
    // OreadyReply_ForPm_BaseOfTime2 專用：後端重新驗證「移轉/取消移轉/快速同步移轉/直接標記已移轉」
    // 這四個高風險寫入動作是否真的有權限，避免只靠前端按鈕隱藏就被繞過（鐵律8：前端擋+後端同規則再擋一次）。
    // 判斷規則需與主檔案 OreadyReply_ForPm_BaseOfTime2.php 開頭的權限判斷邏輯（page/group CRUD組合
    // + oready_transfer/oready_readonly 角色功能碼）保持一致，任一邊改了排除規則另一邊要同步改。
    function oready_resolve_can_transfer($pdo, $user_id, $script_path) {
        if ($user_id <= 0 || !eg_user_is_active($pdo, $user_id)) return false;
        try {
            $st = $pdo->prepare("
                SELECT smp.page_id, smp.group_id
                FROM system_module_pages smp
                WHERE (:script LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
                   OR (:script LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
                LIMIT 1
            ");
            $st->execute([':script' => $script_path]);
            $page = $st->fetch(PDO::FETCH_ASSOC);
            if (!$page) return false;

            $group_module_code = null;
            if (!empty($page['group_id'])) {
                $st2 = $pdo->prepare("SELECT module_code FROM system_modules WHERE group_id = :gid LIMIT 1");
                $st2->execute([':gid' => $page['group_id']]);
                $group_module_code = $st2->fetchColumn();
            }

            $st3 = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:uid AND scope='page' AND module_code=:pid");
            $st3->execute([':uid' => $user_id, ':pid' => $page['page_id']]);
            $perms = array_filter($st3->fetchAll(PDO::FETCH_COLUMN));
            if (!$perms && !empty($group_module_code)) {
                $st4 = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:uid AND scope='group' AND module_code=:mc");
                $st4->execute([':uid' => $user_id, ':mc' => $group_module_code]);
                $perms = array_filter($st4->fetchAll(PDO::FETCH_COLUMN));
            }

            $chars = [];
            foreach ($perms as $p) { $chars = array_merge($chars, str_split($p)); }
            $chars = array_unique($chars);

            $features = rf_load_user_features($pdo, $user_id);
            // 注意：唯讀判斷刻意不用 rf_has_feature()（萬用碼 'all' 會被它視為符合任何功能碼，
            // 若在這裡用 rf_has_feature 會把擁有 'all' 的管理員角色也誤判成唯讀鎖死）。
            if (in_array('oready_readonly', $features, true)) return false; // 唯讀覆蓋，一律不可移轉
            if (rf_has_feature($features, 'oready_transfer')) return true; // 功能碼明確授權（移轉/取消移轉，'all' 亦視為授權）

            if (in_array('A', $chars, true)) return true; // 管理者
            sort($chars);
            $display = implode('+', $chars);
            if ($display === 'D+R' || $display === 'R') return false; // 受限業務/純檢視：無移轉權限
            $sales_codes = ['R+U', 'C+R+U', 'C+D+R+U'];
            if (in_array($display, $sales_codes, true)) return false; // 業務類：無移轉權限（除非上面 featTransfer 已授權）
            return !empty($chars); // 其餘有任何頁面權限組合（如生管 C+R）維持既有預設可移轉
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('rf_has_module_role')) {
    // 二元權限判斷：使用者是否有該 module 底下的任一角色（個人指派、或其「部門＋職稱」指派），
    // 或本身是系統管理員(is_system=1)。用於不需要細分功能碼、只要「有沒有這個功能的使用資格」的場景。
    // 2026-08-27 起納入「部門＋職稱」層，同部門同職稱不必逐人重設。
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
            if ((bool)$st->fetchColumn()) return true;

            $st = $pdo->prepare("
                SELECT 1
                FROM user_department_position_map m
                JOIN position_roles pr ON pr.position_id = m.position_id AND " . RF_PR_DEPT_COND . "
                JOIN roles r ON r.role_id = pr.role_id
                WHERE m.user_id = ? AND (r.module = ? OR r.is_system = 1)
                LIMIT 1
            ");
            $st->execute([$user_id, $module]);
            return (bool)$st->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('oready_resolve_can_view_price')) {
    /**
     * BOM 總表（OreadyReply_ForPm_BaseOfTime.php）的「查看加工單價」資格。
     *
     * 判斷規則必須與該頁開頭的權限判斷保持一致（任一邊改了另一邊要同步改）：
     *   canSeePrice = displayPermissionCode==='A' || displayPermissionCode==='C+D+R' || featSeePrice
     * 其中 featSeePrice ＝ 角色功能碼 oready_view_price（rf_has_feature 會把萬用碼 'all' 視為符合）。
     *
     * 注意「唯讀角色覆蓋」（oready_readonly）在該頁是把 display_permission_code 強制改成 'R'，
     * 但 **不會** 清掉 $oready_feat_view_price——所以唯讀角色只要明確勾了 oready_view_price
     * 仍然看得到單價，這裡照樣還原同一個行為（把 readonly 當成 display='R'，功能碼照舊生效）。
     *
     * 用途：把「只有看得到加工單價的人才能看」的資料（例：優選附件裡的 BOSS 批製程價格）
     * 帶到 BOM 總表以外的頁面時，一律呼叫這一支，不要各頁自己拼一份判斷式。
     *
     * @param string $script_path 要比對 system_module_pages 的頁面路徑；
     *                            預設 BOM 總表本身（權限來源就是那一頁）。
     */
    function oready_resolve_can_view_price($pdo, $user_id, $script_path = '/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php') {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !eg_user_is_active($pdo, $user_id)) return false;
        try {
            // 功能碼優先：勾了就是有（含管理員角色的萬用碼 'all'），與唯讀覆蓋無關
            $features = rf_load_user_features($pdo, $user_id);
            if (rf_has_feature($features, 'oready_view_price')) return true;
            // 唯讀覆蓋：該頁會把 display code 壓成 'R'，等於下面兩種舊制權限都不成立
            if (in_array('oready_readonly', $features, true)) return false;

            $st = $pdo->prepare("
                SELECT smp.page_id, smp.group_id
                FROM system_module_pages smp
                WHERE (:script LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
                   OR (:script LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
                LIMIT 1
            ");
            $st->execute([':script' => $script_path]);
            $page = $st->fetch(PDO::FETCH_ASSOC);
            if (!$page) return false;

            $group_module_code = null;
            if (!empty($page['group_id'])) {
                $st2 = $pdo->prepare("SELECT module_code FROM system_modules WHERE group_id = :gid LIMIT 1");
                $st2->execute([':gid' => $page['group_id']]);
                $group_module_code = $st2->fetchColumn();
            }

            // page scope 優先，沒有才退 group scope（與該頁 Step 3a/3b 相同）
            $st3 = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:uid AND scope='page' AND module_code=:pid");
            $st3->execute([':uid' => $user_id, ':pid' => $page['page_id']]);
            $perms = array_filter($st3->fetchAll(PDO::FETCH_COLUMN));
            if (!$perms && !empty($group_module_code)) {
                $st4 = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:uid AND scope='group' AND module_code=:mc");
                $st4->execute([':uid' => $user_id, ':mc' => $group_module_code]);
                $perms = array_filter($st4->fetchAll(PDO::FETCH_COLUMN));
            }
            $chars = [];
            foreach ($perms as $p) { $chars = array_merge($chars, str_split($p)); }
            $chars = array_unique($chars);
            if (!$chars) return false;
            if (in_array('A', $chars, true)) return true;   // 管理者
            sort($chars);
            return implode('+', $chars) === 'C+D+R';        // 生管（含刪除）才看得到單價
        } catch (Exception $e) {
            return false;   // fail-closed：判不出來一律當作沒有權限（這是會外洩價格的資料）
        }
    }
}
