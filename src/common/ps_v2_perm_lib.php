<?php
// ps_v2_perm_lib.php — 待加工排程 V2.0（views/pm/process_schedule_V2.php）的角色功能碼（唯一實作）
//
// ── 設計原則（2026-10-29 使用者要求「嚴禁影響現有使用者」）────────────────────────────
//   · 沒被指派任何「待加工排程V2」模組角色的人：完全維持原本 A/C/R/U/D 推導，一個字都不變。
//   · 只有被指派了本模組角色（個人指派或部門＋職稱指派）的人，才改走下面的功能碼。
//   · 全站管理員（系統角色 'all'）一律視同原本的 A 權限，不走角色模式。
//   · 預設角色的功能碼＝照原本 A/CRU/CR/RU 各自能做的事逐項勾好（見 ps2_seed_roles()），
//     所以把人從舊制換成對應角色，能做的事不變；之後管理員再依需要增減。
//
// 功能碼前綴 psv_；模組代碼 ps_v2（roles.module）。

if (!defined('PS2_MODULE')) define('PS2_MODULE', 'ps_v2');

if (!function_exists('ps2_features')) {
    /** 功能清單（分門別類）：[group, code, label]。新增功能只要在這裡加一列＋V2 頁面接上 $P['code'] 即可。 */
    function ps2_features(): array {
        return [
            ['group' => '頁面存取',     'code' => 'psv_view',               'label' => '開啟本頁（沒有這項＝進不來）'],
            ['group' => '畫面模式',     'code' => 'psv_ui_prodctrl',        'label' => '生管模式：報工視窗只更新備註／到料，不填生產結果'],
            ['group' => '畫面模式',     'code' => 'psv_ui_production',      'label' => '生產模式：看板不顯示客戶、隱藏車床備註、不可改到料'],

            ['group' => '排程與拖移',   'code' => 'psv_drag',               'label' => '拖移任務卡片（看板指派機台）'],
            ['group' => '排程與拖移',   'code' => 'psv_assign',             'label' => '只更新機台指派（跨機台拖曳，不改順序）'],
            ['group' => '排程與拖移',   'code' => 'psv_reorder',            'label' => '調整機台排程順序（排序視窗／機台列表重排）'],
            ['group' => '排程與拖移',   'code' => 'psv_unassigned_order',   'label' => '調整未指派順序（存生管排好的順序、手動急件、排序紀錄）'],
            ['group' => '排程與拖移',   'code' => 'psv_preplan',            'label' => '生管預排（加入預排／取消預排）'],

            ['group' => '拆分工單',     'code' => 'psv_split',              'label' => '拆分製程（拆分工單）'],
            ['group' => '拆分工單',     'code' => 'psv_split_delete',       'label' => '刪除拆分工單'],

            ['group' => '報工',         'code' => 'psv_report',             'label' => '報工（新增／修改本日完成、本站完工、NG）'],
            ['group' => '報工',         'code' => 'psv_report_delete',      'label' => '刪除報工紀錄'],
            ['group' => '報工',         'code' => 'psv_report_change_bom',  'label' => '修改補加工 BOM（把報工改掛到別張製令）'],
            ['group' => '報工',         'code' => 'psv_gear_info',          'label' => '修改料號齒輪資訊'],

            ['group' => '備註與到料',   'code' => 'psv_note_prodctrl',      'label' => '生管備註（更新任務備註）'],
            ['group' => '備註與到料',   'code' => 'psv_note_lathe',         'label' => '編輯車床備註'],
            ['group' => '備註與到料',   'code' => 'psv_note_field',         'label' => '編輯現場備註'],
            ['group' => '備註與到料',   'code' => 'psv_material',           'label' => '物料管理（已到料標記）'],

            ['group' => '機台異常',     'code' => 'psv_abnormal_report',    'label' => '回報機台異常'],
            ['group' => '機台異常',     'code' => 'psv_abnormal_delete',    'label' => '刪除機台異常紀錄'],
            ['group' => '機台異常',     'code' => 'psv_abnormal_type',      'label' => '管理異常類型選項'],

            ['group' => '機台設定',     'code' => 'psv_machine_manage',     'label' => '開啟「設定機台」（沒有這項，下面三項都用不到）'],
            ['group' => '機台設定',     'code' => 'psv_machine_add',        'label' => '新增機台'],
            ['group' => '機台設定',     'code' => 'psv_machine_edit',       'label' => '修改機台'],
            ['group' => '機台設定',     'code' => 'psv_machine_delete',     'label' => '刪除機台'],

            ['group' => '系統設定',     'code' => 'psv_settings',           'label' => '齒輪顯示設定等系統設定'],
            ['group' => '系統設定',     'code' => 'psv_dept_setting',       'label' => '設定生產部門'],
            ['group' => '系統設定',     'code' => 'psv_process_category',   'label' => '製程分類設定（開啟並儲存）'],
        ];
    }
}

if (!function_exists('ps2_codes')) {
    function ps2_codes(): array { return array_column(ps2_features(), 'code'); }
}

if (!function_exists('ps2_default_roles')) {
    /**
     * 預設角色（role_code => [名稱, 說明, 功能碼]）。功能碼逐項對照原本 A／CRU／CR／RU 能做的事：
     *   本頁管理員（暫時＝原 A）→ 全部
     *   生產主管（原 CRU）、生管（原 CR）、生產組員（原 RU）→ 見下
     */
    function ps2_default_roles(): array {
        $all = ps2_codes();
        $common = ['psv_view', 'psv_drag', 'psv_assign', 'psv_split', 'psv_report', 'psv_abnormal_report',
                   'psv_note_prodctrl', 'psv_note_field'];
        return [
            'psv_page_admin' => ['待加工排程V2 本頁管理員', '暫時與原 A 權限相同（全部功能）', $all],
            'psv_prod_mgr'   => ['待加工排程V2 生產主管', '對應原 C+R+U',
                array_merge($common, ['psv_reorder', 'psv_unassigned_order', 'psv_preplan', 'psv_note_lathe',
                    'psv_machine_manage', 'psv_machine_add', 'psv_machine_edit', 'psv_gear_info', 'psv_ui_production'])],
            'psv_prod_ctrl'  => ['待加工排程V2 生管', '對應原 C+R',
                array_merge($common, ['psv_reorder', 'psv_unassigned_order', 'psv_preplan', 'psv_material',
                    'psv_process_category', 'psv_gear_info', 'psv_ui_prodctrl'])],
            'psv_worker'     => ['待加工排程V2 生產組員', '對應原 R+U',
                array_merge($common, ['psv_note_lathe', 'psv_gear_info', 'psv_ui_production'])],
        ];
    }
}

if (!function_exists('ps2_seed_roles')) {
    /** 建立預設角色（已存在的角色一律不動，管理員改過的功能碼不會被蓋掉）。可重複執行。 */
    function ps2_seed_roles(PDO $pdo): int {
        $made = 0;
        $chk = $pdo->prepare("SELECT role_id FROM roles WHERE role_code=? LIMIT 1");
        foreach (ps2_default_roles() as $code => $d) {
            $chk->execute([$code]);
            if ($chk->fetchColumn()) continue;
            $pdo->prepare("INSERT INTO roles (role_code, role_name, module, note, is_system) VALUES (?,?,?,?,0)")
                ->execute([$code, $d[0], PS2_MODULE, $d[1]]);
            $rid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?,?)");
            foreach ($d[2] as $f) $ins->execute([$rid, $f]);
            $made++;
        }
        return $made;
    }
}

if (!function_exists('ps2_role_features')) {
    /**
     * 這個人是否走「角色模式」。
     * @return array|null null＝沿用原本權限（沒有任何本模組角色、或是全站管理員）；陣列＝本模組功能碼
     */
    function ps2_role_features(PDO $pdo, int $uid): ?array {
        if ($uid <= 0) return null;
        require_once __DIR__ . '/role_features_helper.php';
        $feat = rf_resolve_features($pdo, $uid, PS2_MODULE);
        if (in_array('all', $feat, true)) return null;            // 全站管理員＝原 A
        $mine = array_values(array_filter($feat, function ($c) { return strpos((string)$c, 'psv_') === 0; }));
        return $mine ? $mine : null;
    }
}

if (!function_exists('ps2_role_names')) {
    /** 僅供畫面顯示：這個人被指派的本模組角色名稱 */
    function ps2_role_names(PDO $pdo, int $uid): array {
        $names = [];
        try {
            $st = $pdo->prepare("SELECT DISTINCT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.module=?");
            $st->execute([$uid, PS2_MODULE]);
            $names = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!$names) {
                $st = $pdo->prepare("SELECT DISTINCT r.role_name FROM user_department_position_map m
                    JOIN position_roles pr ON pr.position_id=m.position_id AND (pr.department_id=0 OR pr.department_id=m.department_id)
                    JOIN roles r ON r.role_id=pr.role_id WHERE m.user_id=? AND r.module=?");
                $st->execute([$uid, PS2_MODULE]);
                $names = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
        } catch (Exception $e) {}
        return $names;
    }
}
