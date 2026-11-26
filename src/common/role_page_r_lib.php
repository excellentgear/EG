<?php
/**
 * role_page_r_lib.php — 指派角色時，替「只勾 R、細部看角色」的頁面自動補上頁面 R（2026-11-05）
 *
 * 背景：選單與頁面是否開得起來，仍由 user_module_permissions 決定（側欄、各頁自己讀 R/C/U/D）。
 * 角色指派畫面合併後，指派角色的同時由這裡補上頁面 R，使用者不必再到「人員權限設定」另外勾一次。
 * 這裡只「新增」，不修改、不刪除任何既有權限列；所有讀取權限的程式一律不動。
 *
 * 只對下列模組動作：這些頁面在 user_permissions.php 的 $rbacManagedPageIds 內，
 * 本來就規定「人員權限只勾 R，細部功能由角色決定」，補 R 不會改變它們的判定。
 * 其他模組（如 BOM 總表的 R-only＝不可移轉、KPI、報工…）一律不碰，維持手動設定。
 */
if (!function_exists('eg_role_page_r_modules')) {
    /** 角色模組 → 頁面檔名（唯一登記處）；要擴充只加一列，且該頁必須已是「只勾 R」慣例 */
    function eg_role_page_r_modules(): array {
        return [
            'quotation'      => 'quotation_list_NEW.php',
            'notice'         => 'createEvent.php',
            'homepage'       => 'home_page_setting.php',
            'qc'             => 'inspection_entry_v2.php',
            'car'            => 'correction_order.php',
            'drawing_rename' => 'drawing_rename.php',
            'bom_rename'     => 'bom_rename.php',
            'personal_task'  => 'personal_task.php',
            'order_profit'   => 'Order_Profit_Analysis.php',
            'as_doc'         => 'as_document_management.php',
            'review_form'    => 'review_form.php',
            'hr_form'        => 'hr_position_forms.php',
            'form_signer'    => 'form_signer.php',
            'sheet_form'     => 'sheet_form.php',
            'ir_track'       => 'IR_Track.php',
            // 員工資料管理（2026-11-12）：角色與原頁面權限取聯集，此處只在「完全沒有頁面／群組權限」時補 R 讓側欄出現
            'emp_mgmt'       => 'employee_management.php',
            // 品質異常處理單（2026-11-16）：頁面權限全走 qab_perms()（角色＋組織綁定），不讀頁面 R/C/U/D 字母
            'qa_abnormal'    => 'qa_abnormal_list.php',
            // 折讓單（2026-11-17）：頁面權限全走 aca_perms()（角色），不讀頁面 R/C/U/D 字母
            'acc_allowance'  => 'allowance.php',
        ];
    }
}

if (!function_exists('eg_role_page_r_all')) {
    /**
     * 角色模組 → 頁面檔名（含自動判定）。2026-11-18：不再只靠上面那張手動清單。
     * 原因：每個新模組都得記得回來登記一列，漏了就是「角色指派了、側欄卻沒有選項」（詢價單、
     * 好幾個頁面都這樣）。現在凡是 role_modules_lib.php 登記過、有對應頁面的模組，
     * 只要該頁面檔本身沒有讀 user_module_permissions 的 R/C/U/D 字母（讀字母的頁面補 R 會改變行為），
     * 就一律視為「角色決定細部權限」，指派角色時自動補頁面 R。
     */
    function eg_role_page_r_all(): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $map = eg_role_page_r_modules();
        try {
            require_once __DIR__ . '/role_modules_lib.php';
            $root = realpath(__DIR__ . '/../../');
            foreach (eg_role_modules() as $mod => $def) {
                $page = (string)($def['page'] ?? '');
                if ($page === '' || isset($map[$mod])) continue;
                $hit = null;
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/views', FilesystemIterator::SKIP_DOTS)) as $fi) {
                    if ($fi->getFilename() === $page) { $hit = $fi->getPathname(); break; }
                }
                if ($hit === null) continue;
                $src = @file_get_contents($hit);
                if ($src === false || strpos($src, 'user_module_permissions') !== false) continue;   // 讀字母的頁面不碰
                $map[$mod] = $page;
            }
        } catch (Throwable $e) {}
        return $cache = $map;
    }
}

if (!function_exists('eg_role_page_r_extra')) {
    /** 同一角色模組涵蓋的其他頁面（這些頁面權限全走 acc_perms() 角色，不讀頁面 R/C/U/D 字母） */
    function eg_role_page_r_extra(): array {
        return [
            'accounting' => ['customer_invoice_data.php', 'ar_statement.php', 'invoice_export.php', 'receipt.php',
                             'ap_statement.php', 'reconcile.php', 'payment.php', 'erp_stmt_import.php', 'voucher.php'],
        ];
    }
}

if (!function_exists('eg_role_page_r_ensure')) {
    /**
     * 該使用者在這個角色所屬模組的頁面還沒有任何權限列時，補一列頁面 R。
     * @return bool 有新增＝true
     */
    function eg_role_page_r_ensure(PDO $pdo, int $uid, string $module): bool {
        $map = eg_role_page_r_all();
        if ($uid <= 0) return false;
        $any = false;
        // 一個角色模組涵蓋多個頁面者（會計：對帳／發票／收款／傳票…），每一頁都補 R，側欄才會全部出現
        foreach (eg_role_page_r_extra()[$module] ?? [] as $f) {
            if (eg_role_page_r_ensure_file($pdo, $uid, $f)) $any = true;
        }
        if (!isset($map[$module])) return $any;
        return eg_role_page_r_ensure_file($pdo, $uid, $map[$module]) || $any;
    }
}

if (!function_exists('eg_role_page_r_ensure_file')) {
    /** 同上，但直接指定頁面檔名（例如 as_schedule.php）。AS 負責人交接頁用它補頁面 R。 */
    function eg_role_page_r_ensure_file(PDO $pdo, int $uid, string $file): bool {
        if ($uid <= 0 || $file === '') return false;
        try {
            $st = $pdo->prepare("SELECT page_id, group_id FROM system_module_pages
                                 WHERE page_url LIKE ? ORDER BY page_id ASC LIMIT 1");
            $st->execute(['%/' . $file]);
            $pg = $st->fetch(PDO::FETCH_ASSOC);
            if (!$pg) return false;

            // 已有頁面權限，或所屬群組已有權限（側欄的 checkPagePerm 也是這兩種）→ 不動
            $q = $pdo->prepare("SELECT 1 FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=? LIMIT 1");
            $q->execute([$uid, (string)$pg['page_id']]);
            if ($q->fetchColumn()) return false;
            if (!empty($pg['group_id'])) {
                $g = $pdo->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
                $g->execute([$pg['group_id']]);
                $mc = $g->fetchColumn();
                if ($mc) {
                    $q2 = $pdo->prepare("SELECT 1 FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=? LIMIT 1");
                    $q2->execute([$uid, (string)$mc]);
                    if ($q2->fetchColumn()) return false;
                }
            }
            $pdo->prepare("INSERT INTO user_module_permissions (user_id, module_code, permission, scope, created_at, updated_at)
                           VALUES (?,?, 'R','page', NOW(), NOW())")->execute([$uid, (string)$pg['page_id']]);
            return true;
        } catch (Exception $e) { return false; }
    }
}
