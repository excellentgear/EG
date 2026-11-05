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
        ];
    }
}

if (!function_exists('eg_role_page_r_ensure')) {
    /**
     * 該使用者在這個角色所屬模組的頁面還沒有任何權限列時，補一列頁面 R。
     * @return bool 有新增＝true
     */
    function eg_role_page_r_ensure(PDO $pdo, int $uid, string $module): bool {
        $map = eg_role_page_r_modules();
        if (!isset($map[$module]) || $uid <= 0) return false;
        try {
            $file = $map[$module];
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
