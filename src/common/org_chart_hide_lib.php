<?php
/**
 * 組織圖「AS認證範圍」隱藏設定（部門 / 人員）。
 * 存 system_parameters(ORG_CHART_AS, hide)，值為 JSON {"depts":[id..],"users":[id..]}。
 * 只影響 views/user/organization_chart_vw.php（get_organization_data 帶 scope=as 時才套用），
 * 其他呼叫端（員工管理、ADM 組織圖）完全不受影響。
 */
function och_get(PDO $db): array {
    $out = ['depts' => [], 'users' => []];
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='ORG_CHART_AS' AND param_key='hide' LIMIT 1");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) {
            $out['depts'] = array_values(array_unique(array_map('intval', (array)($j['depts'] ?? []))));
            $out['users'] = array_values(array_unique(array_map('intval', (array)($j['users'] ?? []))));
        }
    } catch (Throwable $e) {}
    return $out;
}

function och_save(PDO $db, array $depts, array $users, string $by): void {
    $depts = array_values(array_unique(array_filter(array_map('intval', $depts))));
    $users = array_values(array_unique(array_filter(array_map('intval', $users))));
    $json  = json_encode(['depts' => $depts, 'users' => $users]);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group='ORG_CHART_AS' AND param_key='hide' LIMIT 1");
    $st->execute();
    $rid = $st->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$json, $by, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,updated_by,updated_at) VALUES ('ORG_CHART_AS','hide',?,?,NOW())")->execute([$json, $by]);
    }
}
