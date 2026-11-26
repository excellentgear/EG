<?php
// upload_list_perm_lib.php — 「上傳 List（views/pm/Upload_List.php）」誰能用哪個分頁／哪個匯入功能（唯一實作）
//
// 使用者身分分五種（2026-11-26 使用者指定）：
//   全站管理員（功能碼 all）／本站管理員（角色 upload_list_admin）＝一律全開，不受設定影響
//   生管／業務／品管＝依「全站組織角色綁定」的部門判定（含下轄、含兼任），可由管理員逐項勾選
// 設定存 system_parameters(UPLOAD_LIST / access)，內容 {功能碼:[pm,sales,qc]}。
// 沒設定過的功能一律用 ulp_defaults()：原本就開放給非管理員的 → 生管＋業務（使用者拍板）。

require_once __DIR__ . '/role_features_helper.php';
require_once __DIR__ . '/org_role_lib.php';

const ULP_GROUP = 'UPLOAD_LIST';
const ULP_ROLE_ADMIN = 'upload_list_admin';

/** 可勾選的身分（鍵 → [顯示名稱, 組織角色綁定鍵]） */
function ulp_groups(): array {
    return ['pm' => ['生管', 'pm_dept'], 'sales' => ['業務', 'sales_dept'], 'qc' => ['品管', 'qc_dept']];
}

/**
 * 功能登錄表。type=tab 是分頁、func 是 ERP 匯入分頁內的單一匯入功能。
 * but＝_upload_For_List.php 的 ?but= 值（前綴比對），api＝其他匯入 API 的代號。
 */
function ulp_features(): array {
    return [
        'tab_erp'      => ['type' => 'tab',  'label' => 'ERP 匯入 分頁',        'but' => []],
        'tab_backfill' => ['type' => 'tab',  'label' => '補資料 分頁',          'but' => ['Quotation_ERP', 'OrderErp']],
        'tab_isdiff'   => ['type' => 'tab',  'label' => 'ERP 差異確認 分頁',    'but' => ['IsErpDiff']],
        'f_bom'        => ['type' => 'func', 'label' => 'BOM／BOM製程 ERP匯入', 'but' => ['BOM_ERP', 'BOM_HIST']],
        'f_transfer'   => ['type' => 'func', 'label' => '移轉紀錄 ERP匯入',     'but' => ['Transfer_ERP']],
        'f_closeout'   => ['type' => 'func', 'label' => 'BOM 結案 ERP匯入',     'but' => ['nb_ok']],
        'f_is'         => ['type' => 'func', 'label' => '出貨單 ERP匯入',       'but' => ['IS_List_ERP']],
        'f_ir'         => ['type' => 'func', 'label' => '退貨單 ERP匯入',       'but' => ['IR_List_ERP']],
        'f_price'      => ['type' => 'func', 'label' => '加工單價 ERP原始檔匯入', 'but' => ['transfer_log_raw']],
        'f_attach'     => ['type' => 'func', 'label' => 'BOM 附件批次上傳',     'but' => ['BomAttach']],
    ];
}

/** 預設：原本非管理員就能用的 → 生管＋業務；原本管理員專用的分頁 → 只有管理員 */
function ulp_defaults(): array {
    $d = [];
    foreach (ulp_features() as $k => $f) {
        $d[$k] = in_array($k, ['tab_backfill', 'tab_isdiff'], true) ? [] : ['pm', 'sales'];
    }
    return $d;
}

function ulp_settings(PDO $db): array {
    $out = ulp_defaults();
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key='access' LIMIT 1");
        $st->execute([ULP_GROUP]);
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) {
            foreach ($out as $k => $_) {
                if (array_key_exists($k, $j) && is_array($j[$k])) {
                    $out[$k] = array_values(array_intersect(array_keys(ulp_groups()), $j[$k]));
                }
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

function ulp_settings_save(PDO $db, array $in, string $by): array {
    $cur = ulp_settings($db);
    foreach (ulp_features() as $k => $_) {
        if (array_key_exists($k, $in)) {
            $cur[$k] = array_values(array_intersect(array_keys(ulp_groups()), (array)$in[$k]));
        }
    }
    $json = json_encode($cur, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key='access' LIMIT 1");
    $st->execute([ULP_GROUP]);
    if ($id = $st->fetchColumn()) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$json, $by, $id]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at) VALUES (?,?,?,?,?,NOW())")
           ->execute([ULP_GROUP, 'access', $json, '上傳 List：各身分可用的分頁與匯入功能', $by]);
    }
    return $cur;
}

/** 這個人屬於哪些身分（pm/sales/qc），含兼任部門 */
function ulp_user_groups(PDO $db, int $uid): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id=?");
        $st->execute([$uid]);
        $depts = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        foreach (ulp_groups() as $g => $info) {
            foreach ($depts as $d) {
                if ($d > 0 && eg_org_in_dept($db, $info[1], $d)) { $out[] = $g; break; }
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 管理員（全站管理員或本站管理員）一律全開 */
function ulp_is_admin(array $features): bool {
    return rf_has_feature($features, 'all') || rf_has_feature($features, ULP_ROLE_ADMIN);
}

function ulp_can(PDO $db, int $uid, string $feature, ?array $features = null): bool {
    if (!isset(ulp_features()[$feature])) return false;
    $features = $features ?? rf_load_user_features($db, $uid);
    if (ulp_is_admin($features)) return true;
    $allowed = ulp_settings($db)[$feature] ?? [];
    return (bool)array_intersect($allowed, ulp_user_groups($db, $uid));
}

/** ?but= 值屬於哪個功能；登錄表沒有的（舊的隱藏匯入）回 null＝只有管理員 */
function ulp_feature_of_but(string $but): ?string {
    foreach (ulp_features() as $k => $f) {
        foreach ($f['but'] as $p) {
            if ($but === $p || strpos($but, $p) === 0) return $k;
        }
    }
    return null;
}

/** 一次算出本人所有功能的可用旗標（頁面渲染用） */
function ulp_can_map(PDO $db, int $uid): array {
    $features = rf_load_user_features($db, $uid);
    $out = [];
    foreach (ulp_features() as $k => $_) $out[$k] = ulp_can($db, $uid, $k, $features);
    $out['_admin'] = ulp_is_admin($features);
    $out['_site_admin'] = rf_has_feature($features, 'all');
    return $out;
}
