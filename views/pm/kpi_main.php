<?php
// EGsystem/views/pm/kpi_main.php  v3
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

include_once '../../src/common/DBConnection.php';
$conn   = new DBConnection();
$pdo    = $conn->getPDO();
$userId = intval($_SESSION['id'] ?? 0);

$PAGE_PERM = 'A';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $pc = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='kpi' LIMIT 1");
        $pc->execute([$userId]);
        $pr = $pc->fetch(PDO::FETCH_ASSOC);
        if ($pr && !empty($pr['permission'])) $PAGE_PERM = $pr['permission'];
    } catch(Exception $e) { $PAGE_PERM = 'A'; }
}
$is_admin = (strpos($PAGE_PERM,'A') !== false);

function safe($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// ── 安全算術運算式解析（支援 +−×÷ 括號 IF/OR/AND/MAX/MIN 及比較運算子）
function _kpiParseFactor(array &$t, int &$p): float {
    if ($p >= count($t)) return 0.0;
    $tok = $t[$p];
    if ($tok[0] === 'n') { $p++; return $tok[1]; }
    if ($tok[0] === 'o' && $tok[1] === '(') {
        $p++;
        $v = _kpiParseCompare($t, $p);
        if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===')') $p++;
        return $v;
    }
    if ($tok[0] === 'o' && $tok[1] === '-') { $p++; return -_kpiParseFactor($t, $p); }
    if ($tok[0] === 'f') {
        $fn = $tok[1]; $p++;
        if (!($p < count($t) && $t[$p][0]==='o' && $t[$p][1]==='(')) return 0.0;
        $p++; // consume '('
        if ($fn === 'IF') {
            $cond = _kpiParseCompare($t, $p);
            if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            $tv = _kpiParseCompare($t, $p);
            if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            $fv = _kpiParseCompare($t, $p);
            if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===')') $p++;
            return $cond != 0.0 ? $tv : $fv;
        }
        if ($fn === 'OR') {
            $r = 0.0;
            while ($p < count($t) && !($t[$p][0]==='o' && $t[$p][1]===')')) {
                if (_kpiParseCompare($t, $p) != 0.0) $r = 1.0;
                if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            }
            if ($p < count($t)) $p++;
            return $r;
        }
        if ($fn === 'AND') {
            $r = 1.0; $has = false;
            while ($p < count($t) && !($t[$p][0]==='o' && $t[$p][1]===')')) {
                if (_kpiParseCompare($t, $p) == 0.0) $r = 0.0;
                $has = true;
                if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            }
            if ($p < count($t)) $p++;
            return $has ? $r : 0.0;
        }
        if ($fn === 'MAX') {
            $m = null;
            while ($p < count($t) && !($t[$p][0]==='o' && $t[$p][1]===')')) {
                $v = _kpiParseCompare($t, $p);
                if ($m === null || $v > $m) $m = $v;
                if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            }
            if ($p < count($t)) $p++;
            return $m ?? 0.0;
        }
        if ($fn === 'MIN') {
            $m = null;
            while ($p < count($t) && !($t[$p][0]==='o' && $t[$p][1]===')')) {
                $v = _kpiParseCompare($t, $p);
                if ($m === null || $v < $m) $m = $v;
                if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            }
            if ($p < count($t)) $p++;
            return $m ?? 0.0;
        }
        // 未知函式：略過至 )
        $d = 1;
        while ($p < count($t) && $d > 0) {
            if ($t[$p][0]==='o' && $t[$p][1]==='(') $d++;
            elseif ($t[$p][0]==='o' && $t[$p][1]===')') $d--;
            $p++;
        }
        return 0.0;
    }
    return 0.0;
}
function _kpiParseTerm(array &$t, int &$p): float {
    $v = _kpiParseFactor($t, $p);
    while ($p < count($t) && $t[$p][0]==='o' && in_array($t[$p][1],['*','/'])) {
        $op=$t[$p++][1]; $r=_kpiParseFactor($t,$p);
        $v = $op==='*' ? $v*$r : ($r!=0 ? $v/$r : 0.0);
    }
    return $v;
}
function _kpiParseExpr(array &$t, int &$p): float {
    $v = _kpiParseTerm($t, $p);
    while ($p < count($t) && $t[$p][0]==='o' && in_array($t[$p][1],['+','-'])) {
        $op=$t[$p++][1]; $r=_kpiParseTerm($t,$p);
        $v = $op==='+' ? $v+$r : $v-$r;
    }
    return $v;
}
function _kpiParseCompare(array &$t, int &$p): float {
    $l = _kpiParseExpr($t, $p);
    if ($p < count($t) && $t[$p][0] === 'c') {
        $op = $t[$p++][1]; $r = _kpiParseExpr($t, $p);
        if ($op==='>=') return ($l>=$r)?1.0:0.0;
        if ($op==='<=') return ($l<=$r)?1.0:0.0;
        if ($op==='>') return ($l>$r)?1.0:0.0;
        if ($op==='<') return ($l<$r)?1.0:0.0;
        if ($op==='='||$op==='==') return (abs($l-$r)<1e-9)?1.0:0.0;
        if ($op==='!='||$op==='<>') return (abs($l-$r)>=1e-9)?1.0:0.0;
    }
    return $l;
}
function kpiEvalArithmetic(string $expr): ?float {
    $expr = preg_replace(['/[×✕✖×]/u', '/x/', '/[÷]/u', '/[−–—]/u'], ['*', '*', '/', '-'], $expr) ?? $expr;
    $tokens = []; $i = 0; $n = strlen($expr);
    while ($i < $n) {
        $c = $expr[$i];
        if (ctype_space($c)) { $i++; continue; }
        if (ctype_digit($c) || ($c==='.' && $i+1<$n && ctype_digit($expr[$i+1]))) {
            $s = '';
            while ($i < $n && (ctype_digit($expr[$i]) || $expr[$i]==='.')) $s.=$expr[$i++];
            $tokens[] = ['n', floatval($s)];
        } elseif ($i+1<$n && in_array(substr($expr,$i,2),['>=','<=','!=','==','<>'])) {
            $two=substr($expr,$i,2);
            $tokens[] = ['c', in_array($two,['!=','<>'])?'!=':$two]; $i+=2;
        } elseif (in_array($c,['>','<'])) {
            $tokens[] = ['c', $c]; $i++;
        } elseif ($c==='=') {
            $tokens[] = ['c', '=']; $i++;
        } elseif (in_array($c,['+','-','*','/','(',')'])) {
            $tokens[] = ['o', $c]; $i++;
        } elseif ($c===',') {
            $tokens[] = ['o', ',']; $i++;
        } elseif (ctype_alpha($c)) {
            $s = '';
            while ($i < $n && ctype_alpha($expr[$i])) $s.=strtoupper($expr[$i++]);
            $tokens[] = ['f', $s];
        } else { $i++; }
    }
    $p = 0;
    return _kpiParseCompare($tokens, $p);
}
function kpiLoadFormulaMap(PDO $pdo): array {
    $map = [];
    try {
        $st = $pdo->prepare("SELECT group_id,formula_expr,var_config FROM kpi_group_formula WHERE is_active=1");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fr) {
            $map[intval($fr['group_id'])] = ['expr'=>$fr['formula_expr'],'vars'=>json_decode($fr['var_config'],true)??[]];
        }
    } catch(Throwable) {}
    return $map;
}
function kpiLoadLabelValMap(PDO $pdo, array $dsIds): array {
    $map = [];
    if (empty($dsIds)) return $map;
    $ids = implode(',', array_map('intval', array_unique($dsIds)));
    try {
        // Parent label values: key = "dsId_labelId", also _min/_max/_qty variants
        $st = $pdo->prepare("SELECT d_id, label_id, COALESCE(input_value,'0') AS input_value, COALESCE(value_min,0) AS value_min, COALESCE(value_max,0) AS value_max, COALESCE(qty,0) AS qty FROM item_label_map WHERE d_id IN ($ids)");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $lr) {
            $did = intval($lr['d_id']); $lid = intval($lr['label_id']);
            $map[$did.'_'.$lid]        = $lr['input_value'];
            $map[$did.'_'.$lid.'_min'] = $lr['value_min'];
            $map[$did.'_'.$lid.'_max'] = $lr['value_max'];
            $map[$did.'_'.$lid.'_qty'] = $lr['qty'];
        }
        // Sub-label values: key = "dsId_labelId_subId", also _min/_max/_draw/_lathe variants
        $st2 = $pdo->prepare(
            "SELECT ilm.d_id, ilm.label_id, islm.sub_id, COALESCE(islm.input_value,'0') AS input_value,
             COALESCE(islm.value_min,0) AS value_min, COALESCE(islm.value_max,0) AS value_max,
             COALESCE(islm.draw_dim,0) AS draw_dim, COALESCE(islm.lathe_dim,0) AS lathe_dim
             FROM item_sub_label_map islm
             JOIN item_label_map ilm ON ilm.map_id=islm.parent_map_id
             WHERE ilm.d_id IN ($ids)"
        );
        $st2->execute();
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $did = intval($sr['d_id']); $lid = intval($sr['label_id']); $sid = intval($sr['sub_id']);
            $map[$did.'_'.$lid.'_'.$sid]          = $sr['input_value'];
            $map[$did.'_'.$lid.'_'.$sid.'_min']   = $sr['value_min'];
            $map[$did.'_'.$lid.'_'.$sid.'_max']   = $sr['value_max'];
            $map[$did.'_'.$lid.'_'.$sid.'_draw']  = $sr['draw_dim'];
            $map[$did.'_'.$lid.'_'.$sid.'_lathe'] = $sr['lathe_dim'];
        }
    } catch(Throwable) {}
    return $map;
}
// 從 labelValMap 取指定欄位值（dim_field: ''=智慧預設 draw=圖面 lathe=車床 max=最大值 min=最小值）
function _kpiDimVal(array $lvm, int $did, int $lid, int $sid, string $df = ''): float {
    if (!$lid) return 0.0;
    $k = $did.'_'.$lid.($sid ? '_'.$sid : '');
    if ($df === 'draw')  return floatval($lvm[$k.'_draw']  ?? 0);
    if ($df === 'lathe') return floatval($lvm[$k.'_lathe'] ?? 0);
    if ($df === 'max')   return floatval($lvm[$k.'_max']   ?? 0);
    if ($df === 'min')   return floatval($lvm[$k.'_min']   ?? 0);
    // 預設：有 value_max 用 value_max，否則用 input_value
    return (floatval($lvm[$k.'_max']??0) > 0) ? floatval($lvm[$k.'_max']) : floatval($lvm[$k]??0);
}
function kpiLoadGearValMap(PDO $pdo, array $dsIds): array {
    $map = [];
    if (empty($dsIds)) return $map;
    $ids = implode(',', array_map('intval', array_unique($dsIds)));
    $fields = ['Teeth','Face_Width','Helix_Angle','Profile_Shift_X','Workpiece_Length'];
    try {
        $st = $pdo->prepare("SELECT d_setting_id,Module,Teeth,Face_Width,Helix_Angle,Profile_Shift_X,Workpiece_Length FROM d_setting_gear WHERE d_setting_id IN ($ids)");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $gr) {
            $did = intval($gr['d_setting_id']);
            // Module 存成 'M2.5' 或 '2.5'，需去除 M 前綴再轉數字
            $m = floatval(str_ireplace('m', '', $gr['Module'] ?? ''));
            $map[$did.'_Module'] = $m;
            foreach ($fields as $f) { $map[$did.'_'.$f] = ($gr[$f] !== null) ? floatval($gr[$f]) : 0.0; }
            // 計算齒部外徑 da = m×z/cos(β) + 2m(1+x)
            $z    = floatval($gr['Teeth'] ?? 0);
            $beta = floatval($gr['Helix_Angle'] ?? 0);
            $x    = floatval($gr['Profile_Shift_X'] ?? 0);
            $map[$did.'_da'] = ($m > 0 && $z > 0)
                ? round($m * $z / cos(deg2rad($beta)) + 2 * $m * (1 + $x), 2)
                : 0.0;
        }
        // 補查 d_setting.Weight_Kg（已是 kg 單位）
        $stW = $pdo->prepare("SELECT d_id, COALESCE(Weight_Kg,0) AS Weight_Kg FROM d_setting WHERE d_id IN ($ids)");
        $stW->execute();
        foreach ($stW->fetchAll(PDO::FETCH_ASSOC) as $wr) {
            $map[intval($wr['d_id']).'_Weight_Kg'] = floatval($wr['Weight_Kg']);
        }
    } catch(Throwable) {}
    return $map;
}
function kpiCalcFormula(array $formulaDef, int $dsId, array $labelValMap, array $kpiParams, array $gearValMap = [], int $qty = 1, array $setupCostMap = [], array $weightMap = []): ?float {
    if (empty($formulaDef['expr'])) return null;
    $expr = preg_replace(['/[×✕✖×]/u', '/x/', '/[÷]/u', '/[−–—]/u'], ['*', '*', '/', '-'], $formulaDef['expr']) ?? $formulaDef['expr'];
    $vars = $formulaDef['vars'] ?? [];
    $replacements = [];
    foreach ($vars as $vc) {
        $varName = $vc['var'] ?? ''; if (!$varName) continue;
        if ($vc['type'] === 'label') {
            $labelId  = intval($vc['label_id']);
            $subId    = intval($vc['sub_id'] ?? 0);
            $dimField = $vc['dim_field'] ?? '';
            $baseKey  = $dsId.'_'.$labelId.($subId ? '_'.$subId : '');
            if ($dimField === 'qty') {
                $val = floatval($labelValMap[$baseKey.'_qty'] ?? 0);
            } elseif ($dimField === 'dim' || $dimField === 'dim_div') {
                $minVal = floatval($labelValMap[$baseKey.'_min'] ?? 0);
                $maxVal = floatval($labelValMap[$baseKey.'_max'] ?? 0);
                $val = ($dimField === 'dim_div')
                    ? ($minVal != 0 ? $maxVal / $minVal : 0.0)
                    : $minVal * $maxVal;
            } else {
                $val = floatval($labelValMap[$baseKey] ?? 0);
            }
            // 若標籤無值 (0)，且設有備援，則改用備援值
            if ($val == 0.0) {
                if (!empty($vc['fallback_gear'])) {
                    $fbVal = floatval($gearValMap[$dsId.'_'.($vc['fallback_gear'])] ?? 0);
                    if ($fbVal != 0.0) $val = $fbVal;
                } elseif (!empty($vc['fallback_label_id'])) {
                    $fbVal = floatval($labelValMap[$dsId.'_'.intval($vc['fallback_label_id'])] ?? 0);
                    if ($fbVal != 0.0) $val = $fbVal;
                }
            }
        } elseif ($vc['type'] === 'param') {
            // 優先使用公式中手動填寫的 param_value，否則從群組設定取值
            if (isset($vc['param_value']) && $vc['param_value'] !== '' && $vc['param_value'] !== null) {
                $val = floatval($vc['param_value']);
            } else {
                $val = floatval($kpiParams[$vc['param_key'] ?? ''] ?? 0);
            }
        } elseif ($vc['type'] === 'gear') {
            $val = floatval($gearValMap[$dsId.'_'.($vc['gear_field'] ?? '')] ?? 0);
        } elseif ($vc['type'] === 'qty') {
            $val = floatval($qty);
        } elseif ($vc['type'] === 'base_cost') {
            $val = floatval($setupCostMap[$vc['cost_desc'] ?? ''] ?? 0);
        } elseif ($vc['type'] === 'calc_weight') {
            $val = floatval($weightMap[$dsId] ?? 0);
        } else { $val = 0.0; }
        $replacements[$varName] = $val;
    }
    // Replace longest names first to avoid partial replacement
    uksort($replacements, function($a,$b){ return strlen($b)-strlen($a); });
    foreach ($replacements as $varName => $val) {
        $expr = preg_replace('/\b'.preg_quote($varName,'/').'\b/', number_format($val, 10, '.', ''), $expr);
    }
    $result = kpiEvalArithmetic($expr);
    return (is_numeric($result)) ? floatval($result) : null;
}

// ══════════════════════════════════════════════════════════════
//  自動重量計算 - 相關函數
// ══════════════════════════════════════════════════════════════
function kpiLoadWeightRules(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT * FROM kpi_weight_calc_rule WHERE is_active=1 ORDER BY sort_order,rule_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['cond_label_ids']    = json_decode($r['cond_label_ids']    ?? '[]', true) ?: [];
            $r['cond_or_label_ids'] = json_decode($r['cond_or_label_ids'] ?? '[]', true) ?: [];
            $dSources = json_decode($r['d_sources'] ?? '[]', true) ?: [];
            // 向下相容：舊規則只有 d_label_id/d_type，自動轉換
            if (empty($dSources) && !empty($r['d_type'])) {
                $dSources = [[
                    'type'       => $r['d_type'] ?? 'label',
                    'label_id'   => intval($r['d_label_id'] ?? 0),
                    'sub_id'     => intval($r['d_sub_id'] ?? 0),
                    'gear_field' => $r['d_gear_field'] ?? '',
                ]];
            }
            $r['d_sources']          = $dSources;
            $r['deduction_sources']  = json_decode($r['deduction_sources'] ?? '[]', true) ?: [];
            $r['body_sections']      = json_decode($r['body_sections']     ?? '[]', true) ?: [];
            $r['cond_logic']         = !empty($r['cond_logic']) ? $r['cond_logic'] : 'AND';
        }
        return $rows;
    } catch(Throwable) { return []; }
}
function kpiLoadMaterialDensities(PDO $pdo): array {
    try {
        return $pdo->query("SELECT density_id, keyword, density_g, sort_order, COALESCE(bound_label_id,0) AS bound_label_id, COALESCE(bound_sub_id,0) AS bound_sub_id FROM kpi_material_density ORDER BY sort_order, density_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable) { return []; }
}
function kpiLoadWeightConfig(PDO $pdo): array {
    try {
        return $pdo->query("SELECT config_key, config_val FROM kpi_weight_config")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch(Throwable) { return []; }
}
function kpiGetMaterialDensity(string $material, array $densities): ?float {
    $mat = mb_strtolower(trim($material), 'UTF-8');
    foreach ($densities as $d) {
        $kw = mb_strtolower(trim($d['keyword']), 'UTF-8');
        if ($kw !== '' && mb_strpos($mat, $kw) !== false) return floatval($d['density_g']);
    }
    return null;
}
// 圓柱重量自動計算：對每個料號找所有符合條件的規則，取最重的結果
// W(kg) = π/4 × D²(mm²) × L(mm) × ρ(g/cm³) / 1,000,000
function kpiComputeWeightMap(array $dsIds, array $rules, array $densities, array $labelValMap, array $gearValMap, int $keywordLabelId = 0): array {
    $weightMap = [];
    foreach ($dsIds as $dsId) {
        $maxWeight = null;
        foreach ($rules as $rule) {
            // AND 組：全部標籤都需存在
            $condIds = is_array($rule['cond_label_ids']) ? $rule['cond_label_ids'] : [];
            if (!empty($condIds)) {
                foreach ($condIds as $condLabelId) {
                    if (!isset($labelValMap[$dsId.'_'.intval($condLabelId)])) { continue 2; }
                }
            }
            // OR 組：至少一個條件成立（-1 = 齒部外徑計算值 da > 0）
            $condOrIds = is_array($rule['cond_or_label_ids']) ? $rule['cond_or_label_ids'] : [];
            if (!empty($condOrIds)) {
                $anyMet = false;
                foreach ($condOrIds as $condLabelId) {
                    if (intval($condLabelId) === -1) {
                        if (floatval($gearValMap[$dsId.'_da'] ?? 0) > 0) { $anyMet = true; break; }
                    } elseif (isset($labelValMap[$dsId.'_'.intval($condLabelId)])) {
                        $anyMet = true; break;
                    }
                }
                if (!$anyMet) continue;
            }
            // ── 主體截面計算 ─────────────────────────────────────────
            $bodySections = is_array($rule['body_sections'] ?? null) ? $rule['body_sections'] : [];
            $mainVol = 0.0;
            if (!empty($bodySections)) {
                // 多段截面模式：每段取 volume 加總
                foreach ($bodySections as $sec) {
                    $secType = $sec['type'] ?? 'cylinder';
                    $dV  = _kpiDimVal($labelValMap, $dsId, intval($sec['d_label_id']??0),  intval($sec['d_sub_id']??0),  $sec['d_dim_field']??'');
                    $lV  = _kpiDimVal($labelValMap, $dsId, intval($sec['l_label_id']??0),  intval($sec['l_sub_id']??0),  $sec['l_dim_field']??'');
                    if ($dV <= 0 || $lV <= 0) continue;
                    if ($secType === 'annulus') {
                        $d2V = _kpiDimVal($labelValMap, $dsId, intval($sec['d2_label_id']??0), intval($sec['d2_sub_id']??0), $sec['d2_dim_field']??'');
                        $mainVol += M_PI / 4.0 * max(0.0, $dV*$dV - $d2V*$d2V) * $lV;
                    } else {
                        $mainVol += M_PI / 4.0 * $dV * $dV * $lV;
                    }
                }
                if ($mainVol <= 0) continue;
            } else {
                // 舊式單 D×L 模式（向下相容）
                $D = 0.0;
                $dSources = is_array($rule['d_sources'] ?? null) ? $rule['d_sources'] : [];
                foreach ($dSources as $ds) {
                    $dVal = 0.0;
                    if (($ds['type'] ?? '') === 'gear') {
                        $dVal = floatval($gearValMap[$dsId.'_'.($ds['gear_field'] ?? '')] ?? 0);
                    } else {
                        $dVal = _kpiDimVal($labelValMap, $dsId, intval($ds['label_id']??0), intval($ds['sub_id']??0), $ds['dim_field']??'');
                    }
                    if ($dVal > $D) $D = $dVal;
                }
                $L = 0.0;
                if (($rule['l_type'] ?? '') === 'gear') {
                    $L = floatval($gearValMap[$dsId.'_'.($rule['l_gear_field'] ?? '')] ?? 0);
                } else {
                    $L = _kpiDimVal($labelValMap, $dsId, intval($rule['l_label_id']??0), intval($rule['l_sub_id']??0), $rule['l_dim_field']??'');
                }
                if ($D <= 0 || $L <= 0) continue;
                $mainVol = M_PI / 4.0 * $D * $D * $L;
            }
            // 取密度 ρ (g/cm³)
            $rho = null;
            if (($rule['density_src'] ?? '') === 'fixed') {
                $rho = floatval($rule['fixed_density_g'] ?? 0) ?: null;
            } else {
                $matLblId = intval($rule['material_label_id'] ?? 0);
                // 關鍵字來源：優先用全域 keywordLabelId，否則用規則的 material_label_id
                $srcLblId = $keywordLabelId > 0 ? $keywordLabelId : $matLblId;
                // 收集此料號在來源標籤下的所有值（父標籤 + 全部子標籤）
                $matVals = [];
                if ($srcLblId > 0) {
                    $matVals[] = (string)($labelValMap[$dsId.'_'.$srcLblId] ?? '');
                    $prefix = $dsId.'_'.$srcLblId.'_';
                    $prefixLen = strlen($prefix);
                    foreach ($labelValMap as $k => $v) {
                        if (strncmp((string)$k, $prefix, $prefixLen) === 0 &&
                            ctype_digit(substr((string)$k, $prefixLen))) {
                            $matVals[] = (string)$v;
                        }
                    }
                }
                foreach ($densities as $d) {
                    $bSid = intval($d['bound_sub_id'] ?? 0);
                    if ($bSid > 0) {
                        // 子標籤直接匹配：該子標籤有值即命中
                        $specificVal = trim((string)($labelValMap[$dsId.'_'.$srcLblId.'_'.$bSid] ?? ''));
                        if ($specificVal !== '') { $rho = floatval($d['density_g']); break; }
                    } else {
                        // 舊式關鍵字匹配（向下相容）
                        $kw = mb_strtolower(trim($d['keyword']), 'UTF-8');
                        if ($kw === '') continue;
                        foreach ($matVals as $matVal) {
                            $mat = mb_strtolower(trim($matVal), 'UTF-8');
                            if (mb_strpos($mat, $kw) !== false) { $rho = floatval($d['density_g']); break 2; }
                        }
                    }
                }
            }
            if (!$rho || $rho <= 0) continue;
            // 減項體積
            $dedVol = 0.0;
            foreach ($rule['deduction_sources'] ?? [] as $ded) {
                $dLid  = intval($ded['d_label_id']  ?? 0); $dSid  = intval($ded['d_sub_id']  ?? 0);
                $lLid  = intval($ded['l_label_id']  ?? 0); $lSid  = intval($ded['l_sub_id']  ?? 0);
                if (!$dLid || !$lLid) continue;
                $dV = _kpiDimVal($labelValMap, $dsId, $dLid, $dSid, $ded['d_dim_field']??'');
                $lV = _kpiDimVal($labelValMap, $dsId, $lLid, $lSid, $ded['l_dim_field']??'');
                if ($dV <= 0 || $lV <= 0) continue;
                if (($ded['type']??'cylinder') === 'annulus') {
                    $d2Lid = intval($ded['d2_label_id']??0); $d2Sid = intval($ded['d2_sub_id']??0);
                    $d2V = $d2Lid ? _kpiDimVal($labelValMap, $dsId, $d2Lid, $d2Sid, $ded['d2_dim_field']??'') : 0.0;
                    $dedVol += M_PI / 4.0 * max(0.0, $dV*$dV - $d2V*$d2V) * $lV;
                } else { $dedVol += M_PI / 4.0 * $dV * $dV * $lV; }
            }
            $weight = max(0.0, $mainVol - $dedVol) * $rho / 1000000.0;
            if ($maxWeight === null || $weight > $maxWeight) $maxWeight = $weight;
        }
        $weightMap[$dsId] = $maxWeight !== null ? round($maxWeight, 6) : 0.0;
    }
    return $weightMap;
}

// ── 自動建立重量計算相關資料表 ──────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_weight_calc_rule (
        rule_id INT AUTO_INCREMENT PRIMARY KEY,
        rule_name VARCHAR(100) NOT NULL DEFAULT '',
        cond_label_ids TEXT NULL,
        d_type VARCHAR(10) NOT NULL DEFAULT 'label',
        d_label_id INT NULL,
        d_sub_id INT NULL,
        d_gear_field VARCHAR(50) NULL,
        l_type VARCHAR(10) NOT NULL DEFAULT 'label',
        l_label_id INT NULL,
        l_sub_id INT NULL,
        l_gear_field VARCHAR(50) NULL,
        density_src VARCHAR(10) NOT NULL DEFAULT 'material',
        material_label_id INT NULL,
        fixed_density_g DECIMAL(8,4) NULL,
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_material_density (
        density_id INT AUTO_INCREMENT PRIMARY KEY,
        keyword VARCHAR(100) NOT NULL,
        density_g DECIMAL(8,4) NOT NULL,
        sort_order INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 寫入預設密度（若資料表為空）
    if (intval($pdo->query("SELECT COUNT(*) FROM kpi_material_density")->fetchColumn()) === 0) {
        $pdo->exec("INSERT INTO kpi_material_density (keyword, density_g, sort_order) VALUES
            ('不鏽鋼', 7.93, 10),('SUS', 7.93, 11),('SS', 7.93, 12),
            ('鐵', 7.85, 20),('鋼', 7.85, 21),
            ('鋁', 2.70, 30),('AL', 2.70, 31),
            ('銅', 8.90, 40),('Cu', 8.90, 41),
            ('POM', 1.42, 50),('ABS', 1.05, 60)");
    }
    // 新增密度表綁定標籤欄位（若不存在，保留向下相容）
    foreach (['bound_label_id INT NULL','bound_sub_id INT NULL'] as $colDef) {
        try { $pdo->exec("ALTER TABLE kpi_material_density ADD COLUMN $colDef"); } catch(Throwable) {}
    }
    // 新增重量規則欄位：多來源 D + 條件邏輯
    foreach (["d_sources TEXT NULL", "cond_logic VARCHAR(3) NOT NULL DEFAULT 'AND'", "cond_or_label_ids TEXT NULL"] as $colDef) {
        try { $pdo->exec("ALTER TABLE kpi_weight_calc_rule ADD COLUMN $colDef"); } catch(Throwable) {}
    }
    // 計費模式：fallback_mode（工時計費為主，無工時時備用 formula/fixed）
    try { $pdo->exec("ALTER TABLE kpi_std_time_default ADD COLUMN fallback_mode VARCHAR(10) NOT NULL DEFAULT 'formula'"); } catch(Throwable) {}
    // 重量計算規則：減項體積（內孔/外徑差）
    try { $pdo->exec("ALTER TABLE kpi_weight_calc_rule ADD COLUMN deduction_sources TEXT NULL"); } catch(Throwable) {}
    // 重量計算規則：多段截面主體（取代單一 D×L）
    try { $pdo->exec("ALTER TABLE kpi_weight_calc_rule ADD COLUMN body_sections TEXT NULL"); } catch(Throwable) {}
    try { $pdo->exec("ALTER TABLE kpi_weight_calc_rule ADD COLUMN l_dim_field VARCHAR(10) NULL"); } catch(Throwable) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_weight_config (
        config_key VARCHAR(50) NOT NULL PRIMARY KEY,
        config_val TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Throwable $e) { error_log('[KPI weight tables] '.$e->getMessage()); }

// ══════════════════════════════════════════════════════════════
//  AJAX 路由
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    // ── 料號搜尋 ──────────────────────────────────────────────
    if ($action === 'search_did') {
        $term = trim($_POST['term'] ?? '');
        $only_unset = intval($_POST['only_unset'] ?? 0);
        try {
            $like = "%$term%";
            $sql = "SELECT ds.d_id, ds.D_Setting_Id AS part_no, ds.Type,
                    MAX(b.Client_Name) AS Client_Name,
                    MAX(dsg.Module) AS Module, MAX(dsg.Teeth) AS Teeth,
                    MAX(dsg.Face_Width) AS Face_Width, MAX(dsg.Helix_Angle_Str) AS Helix_Angle_Str,
                    MAX(dsg.Helix_Direction) AS Helix_Direction, MAX(dsg.Pressure_Angle) AS Pressure_Angle,
                    MAX(dsg.Profile_Shift_X) AS Profile_Shift_X, MAX(dsg.Workpiece_Length) AS Workpiece_Length,
                    MAX(dsg.Gear_Type) AS Gear_Type, MAX(dsg.Remark_Gear) AS Remark_Gear,
                    COUNT(DISTINCT kps.std_id) AS std_count
                    FROM d_setting ds
                    LEFT JOIN bom b ON b.d_setting_id=ds.d_id
                    LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id=ds.d_id
                    LEFT JOIN kpi_part_standard kps ON kps.d_setting_id=ds.d_id
                    WHERE (ds.D_Setting_Id LIKE ? OR b.Client_Name LIKE ?)
                    GROUP BY ds.d_id, ds.D_Setting_Id, ds.Type";
            if ($only_unset) $sql .= " HAVING std_count=0";
            $sql .= " ORDER BY ds.D_Setting_Id LIMIT 80";
            $st = $pdo->prepare($sql); $st->execute([$like,$like]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 製程群組列表 ──────────────────────────────────────────
    if ($action === 'get_groups') {
        try {
            $rows = $pdo->query("SELECT g.*, gd.default_coefficient,
                std.base_time_sec AS default_base_time, std.base_price AS default_base_price,
                std.fixed_price_per_pcs AS default_fixed_price,
                COALESCE(std.base_amount, NULL) AS default_base_amount,
                COALESCE(std.fallback_mode,'formula') AS default_fallback_mode,
                CASE WHEN kgf.formula_id IS NOT NULL THEN 1 ELSE 0 END AS has_formula,
                kgf.formula_expr, kgf.var_config,
                GROUP_CONCAT(DISTINCT pgm.process_no ORDER BY pgm.process_no SEPARATOR ',') AS process_nos,
                GROUP_CONCAT(DISTINCT pn.ProcessName ORDER BY pgm.process_no SEPARATOR ', ') AS process_names
                FROM kpi_process_group g
                LEFT JOIN kpi_difficulty_default gd ON gd.group_id=g.group_id
                LEFT JOIN kpi_std_time_default std ON std.group_id=g.group_id
                LEFT JOIN kpi_group_formula kgf ON kgf.group_id=g.group_id AND kgf.is_active=1
                LEFT JOIN kpi_process_group_map pgm ON pgm.group_id=g.group_id
                LEFT JOIN process_no pn ON pn.ProcessNo=pgm.process_no
                WHERE g.is_active=1 GROUP BY g.group_id ORDER BY g.sort_order")->fetchAll(PDO::FETCH_ASSOC);
            // 附加各群組的基本費用
            $gids = array_column($rows, 'group_id');
            $setupCostMap = [];
            if (!empty($gids)) {
                $inG = implode(',', array_map('intval', $gids));
                $scSt = $pdo->query("SELECT cost_id,group_id,cost_desc,cost_amount FROM kpi_group_setup_cost WHERE group_id IN ($inG) AND is_active=1 ORDER BY group_id,sort_order");
                foreach ($scSt->fetchAll(PDO::FETCH_ASSOC) as $sc) {
                    $setupCostMap[intval($sc['group_id'])][] = ['cost_id'=>intval($sc['cost_id']),'cost_desc'=>$sc['cost_desc'],'cost_amount'=>floatval($sc['cost_amount'])];
                }
            }
            foreach ($rows as &$r) {
                $r['setup_costs'] = $setupCostMap[intval($r['group_id'])] ?? [];
            }
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 群組基本費用列表 ──────────────────────────────────────
    if ($action === 'get_group_setup_costs') {
        $gid = intval($_POST['group_id'] ?? 0);
        try {
            $st = $pdo->prepare("SELECT cost_id,cost_desc,cost_amount,sort_order FROM kpi_group_setup_cost WHERE group_id=? AND is_active=1 ORDER BY sort_order,cost_id");
            $st->execute([$gid]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 儲存群組基本費用 ──────────────────────────────────────
    if ($action === 'save_group_setup_costs') {
        $gid   = intval($_POST['group_id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items)) $items = [];
        if (!$gid) { echo json_encode(['success'=>false,'message'=>'group_id 必填']); exit; }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE kpi_group_setup_cost SET is_active=0 WHERE group_id=?")->execute([$gid]);
            foreach ($items as $idx => $it) {
                $desc = trim($it['cost_desc'] ?? ''); if ($desc === '') continue;
                $amt  = round(floatval($it['cost_amount'] ?? 0), 4);
                $cid  = intval($it['cost_id'] ?? 0);
                if ($cid > 0) {
                    $pdo->prepare("UPDATE kpi_group_setup_cost SET cost_desc=?,cost_amount=?,sort_order=?,is_active=1 WHERE cost_id=? AND group_id=?")
                        ->execute([$desc,$amt,$idx,$cid,$gid]);
                } else {
                    $pdo->prepare("INSERT INTO kpi_group_setup_cost (group_id,cost_desc,cost_amount,sort_order,is_active) VALUES (?,?,?,?,1)")
                        ->execute([$gid,$desc,$amt,$idx]);
                }
            }
            $pdo->commit();
            echo json_encode(['success'=>true]);
        } catch(Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── 料號係數與標準設定 ────────────────────────────────────
    if ($action === 'get_part_standards') {
        $d_id = intval($_POST['d_setting_id'] ?? 0);
        try {
            // 步驟1：從 BOM_ING 取此料號實際有的製程群組（先跑兩個小查詢，再用 IN 過濾主查詢）
            $bomGids = [];
            $bgSt = $pdo->prepare(
                "SELECT DISTINCT pgm.group_id
                 FROM kpi_process_group_map pgm
                 JOIN BOM_ING bi ON bi.process_no = pgm.process_no
                 JOIN bom b ON b.bom = bi.bom AND b.d_setting_id = ?"
            );
            $bgSt->execute([$d_id]);
            foreach ($bgSt->fetchAll(PDO::FETCH_COLUMN) as $gid) { $bomGids[] = intval($gid); }

            // 步驟2：已有個別設定的群組也保留（避免 BOM 變動後設定不見）
            $custGids = [];
            $cgSt = $pdo->prepare("SELECT DISTINCT group_id FROM kpi_part_standard WHERE d_setting_id=?");
            $cgSt->execute([$d_id]);
            foreach ($cgSt->fetchAll(PDO::FETCH_COLUMN) as $gid) { $custGids[] = intval($gid); }

            $allGids = array_unique(array_merge($bomGids, $custGids));
            if (empty($allGids)) { echo json_encode(['success'=>true,'data'=>[]]); exit; }

            $inGids = implode(',', $allGids);
            $st = $pdo->prepare("SELECT g.group_id, g.group_name, g.group_code,
                COALESCE(kps.coefficient, gd.default_coefficient, 1.0) AS coefficient,
                COALESCE(kps.base_time_sec, std.base_time_sec) AS base_time_sec,
                COALESCE(kps.base_price, std.base_price) AS base_price,
                kps.multiplier,
                kps.std_id, kps.remark,
                CASE WHEN kps.std_id IS NOT NULL THEN 1 ELSE 0 END AS is_custom,
                std.base_time_sec AS default_base_time,
                std.base_price AS default_base_price,
                std.fixed_price_per_pcs AS grp_fixed_price,
                gd.default_coefficient,
                CASE WHEN kgf.formula_id IS NOT NULL THEN 1 ELSE 0 END AS has_formula,
                GROUP_CONCAT(DISTINCT pn.ProcessName ORDER BY pgm.process_no SEPARATOR ', ') AS process_names
                FROM kpi_process_group g
                LEFT JOIN kpi_difficulty_default gd ON gd.group_id=g.group_id
                LEFT JOIN kpi_std_time_default std ON std.group_id=g.group_id
                LEFT JOIN kpi_part_standard kps ON kps.group_id=g.group_id AND kps.d_setting_id=?
                LEFT JOIN kpi_group_formula kgf ON kgf.group_id=g.group_id AND kgf.is_active=1
                LEFT JOIN kpi_process_group_map pgm ON pgm.group_id=g.group_id
                LEFT JOIN process_no pn ON pn.ProcessNo=pgm.process_no
                WHERE g.is_active=1 AND g.group_id IN ($inGids)
                GROUP BY g.group_id ORDER BY g.sort_order");
            $st->execute([$d_id]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 儲存料號標準（整合係數+工時+金額） ────────────────────
    if ($action === 'save_part_standard') {
        $d_id     = intval($_POST['d_setting_id'] ?? 0);
        $group_id = intval($_POST['group_id'] ?? 0);
        $coeff    = max(1.0, min(10.0, round(floatval($_POST['coefficient'] ?? 1.0), 1)));
        $base_t   = trim($_POST['base_time_sec'] ?? '') !== '' ? round(floatval($_POST['base_time_sec']), 2) : null;
        $base_p   = trim($_POST['base_price'] ?? '')    !== '' ? round(floatval($_POST['base_price']), 4)    : null;
        $multi    = round(floatval($_POST['multiplier'] ?? 1.0), 4);
        $remark   = trim($_POST['remark'] ?? '');
        // 全部清空（使用預設值）時刪除記錄，讓 BOM_ING 篩選器決定是否顯示
        $is_cleared = ($base_t === null && $base_p === null && $remark === '' && $coeff == 1.0 && $multi == 1.0);
        try {
            if ($is_cleared) {
                $pdo->prepare("DELETE FROM kpi_part_standard WHERE d_setting_id=? AND group_id=?")
                    ->execute([$d_id, $group_id]);
            } else {
                $pdo->prepare("INSERT INTO kpi_part_standard
                    (d_setting_id,group_id,coefficient,base_time_sec,base_price,multiplier,remark,created_by,updated_by)
                    VALUES (?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                    coefficient=VALUES(coefficient),base_time_sec=VALUES(base_time_sec),
                    base_price=VALUES(base_price),multiplier=VALUES(multiplier),
                    remark=VALUES(remark),updated_by=VALUES(updated_by)")
                    ->execute([$d_id,$group_id,$coeff,$base_t,$base_p,$multi,$remark,$userId,$userId]);
            }
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 刪除料號個別設定 ──────────────────────────────────────
    if ($action === 'delete_part_standard') {
        $d_id     = intval($_POST['d_setting_id'] ?? 0);
        $group_id = intval($_POST['group_id'] ?? 0);
        if (!$d_id || !$group_id) { echo json_encode(['success'=>false,'message'=>'參數錯誤']); exit; }
        try {
            $pdo->prepare("DELETE FROM kpi_part_standard WHERE d_setting_id=? AND group_id=?")
                ->execute([$d_id, $group_id]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 儲存製程群組預設工時與金額 ────────────────────────────
    if ($action === 'save_std_time_default') {
        $gid          = intval($_POST['group_id'] ?? 0);
        $fallbackMode = in_array(trim($_POST['fallback_mode'] ?? ''), ['formula','fixed']) ? trim($_POST['fallback_mode']) : 'formula';
        $base_t       = round(floatval($_POST['base_time_sec'] ?? 0), 2);
        $base_p       = round(floatval($_POST['base_price'] ?? 0), 4);
        $fp_raw       = trim($_POST['fixed_price_per_pcs'] ?? '');
        $fixed_p      = ($fallbackMode === 'fixed' && $fp_raw !== '' && floatval($fp_raw) > 0) ? round(floatval($fp_raw), 4) : null;
        $ba_raw       = trim($_POST['base_amount'] ?? '');
        $base_amount  = ($ba_raw !== '') ? round(floatval($ba_raw), 4) : null;
        try {
            $pdo->prepare("INSERT INTO kpi_std_time_default (group_id,base_time_sec,base_price,fixed_price_per_pcs,base_amount,fallback_mode,updated_by) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE base_time_sec=VALUES(base_time_sec),base_price=VALUES(base_price),
                fixed_price_per_pcs=VALUES(fixed_price_per_pcs),base_amount=VALUES(base_amount),
                fallback_mode=VALUES(fallback_mode),updated_by=VALUES(updated_by)")
                ->execute([$gid,$base_t,$base_p,$fixed_p,$base_amount,$fallbackMode,$userId]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 儲存製程群組預設係數 ──────────────────────────────────
    if ($action === 'save_default_coeff') {
        $gid   = intval($_POST['group_id'] ?? 0);
        $coeff = max(1.0, min(10.0, round(floatval($_POST['coefficient'] ?? 1.0), 1)));
        try {
            $pdo->prepare("INSERT INTO kpi_difficulty_default (group_id,default_coefficient,updated_by) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE default_coefficient=VALUES(default_coefficient),updated_by=VALUES(updated_by)")
                ->execute([$gid,$coeff,$userId]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 製程群組新增/編輯 ─────────────────────────────────────
    if ($action === 'save_group') {
        $gid  = intval($_POST['group_id'] ?? 0);
        $name = trim($_POST['group_name'] ?? '');
        $code = trim($_POST['group_code'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $sort = intval($_POST['sort_order'] ?? 0);
        $pnos = array_filter(array_map('intval', explode(',', $_POST['process_nos'] ?? '')));
        if (!$name || !$code) { echo json_encode(['success'=>false,'message'=>'名稱與代碼必填']); exit; }
        try {
            $pdo->beginTransaction();
            if ($gid) {
                $pdo->prepare("UPDATE kpi_process_group SET group_name=?,group_code=?,description=?,sort_order=?,updated_at=NOW() WHERE group_id=?")
                    ->execute([$name,$code,$desc,$sort,$gid]);
            } else {
                $pdo->prepare("INSERT INTO kpi_process_group (group_name,group_code,description,sort_order) VALUES (?,?,?,?)")
                    ->execute([$name,$code,$desc,$sort]);
                $gid = $pdo->lastInsertId();
            }
            $pdo->prepare("DELETE FROM kpi_process_group_map WHERE group_id=?")->execute([$gid]);
            if ($pnos) {
                $ins = $pdo->prepare("INSERT IGNORE INTO kpi_process_group_map (group_id,process_no) VALUES (?,?)");
                foreach ($pnos as $p) $ins->execute([$gid,$p]);
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'group_id'=>$gid]);
        } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 圖面檔案 ──────────────────────────────────────────────
    if ($action === 'get_product_files') {
        $pid  = trim($_POST['product_id'] ?? '');
        $scan_dir = 'Z:/BOM/';
        $url_dir  = '/nas/';
        $files    = [];
        try {
            // 從 bom 表查出此料號對應的 BOM 號碼（Created_At DESC 即為顯示順序）
            $bomSt = $pdo->prepare("SELECT bom, sqty FROM bom WHERE d_id = ? ORDER BY Created_At DESC");
            $bomSt->execute([$pid]);
            $bomRows = $bomSt->fetchAll(PDO::FETCH_ASSOC);

            if ($bomRows && is_dir($scan_dir)) {
                $allFiles = scandir($scan_dir);
                // bomRows 已按 Created_At DESC 排序（最新 BOM 在前）
                // 相同 BOM 號碼的多個檔案，再按 filemtime DESC 排序（只對匹配檔案呼叫，數量少）
                foreach ($bomRows as $br) {
                    $matched = [];
                    foreach ($allFiles as $fn) {
                        if ($fn === '.' || $fn === '..') continue;
                        if (strpos($fn, $br['bom']) === 0) {
                            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                            if (in_array($ext, ['jpg','jpeg','png','pdf']))
                                $matched[] = [
                                    'bom'   => $br['bom'].($br['sqty']!==null?' (Qty:'.$br['sqty'].')':''),
                                    'name'  => $fn,
                                    'path'  => $url_dir.$fn,
                                    'type'  => $ext,
                                    'mtime' => filemtime($scan_dir.$fn)
                                ];
                        }
                    }
                    usort($matched, fn($a,$b) => $b['mtime'] - $a['mtime']);
                    foreach ($matched as &$m) { unset($m['mtime']); }
                    $files = array_merge($files, $matched);
                }
            }
            echo json_encode(['success'=>true,'files'=>$files]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── KPI 摘要統計卡 ────────────────────────────────────────
    if ($action === 'get_kpi_summary') {
        $df  = $_POST['date_from'] ?? date('Y-m-01');
        $dt  = $_POST['date_to']   ?? date('Y-m-d');
        $uid = intval($_POST['user_id']   ?? 0);
        $mid = intval($_POST['machine_id'] ?? 0);
        try {
            $where = "pdr.report_date BETWEEN ? AND ?";
            $p = [$df, $dt];
            if ($uid) { $where .= " AND (pdr.setup_user_id=? OR pdr.production_user_id=?)"; $p[]=$uid; $p[]=$uid; }
            if ($mid) { $where .= " AND pdr.machine_id=?"; $p[]=$mid; }
            $st = $pdo->prepare("SELECT COUNT(DISTINCT pdr.report_id) AS report_count,
                SUM(pdr.produced_qty) AS total_ok,
                COALESCE((SELECT SUM(ng_qty) FROM pm_process_daily_ng ng
                    JOIN pm_process_daily_report r2 ON ng.report_id=r2.report_id
                    WHERE r2.report_date BETWEEN ? AND ?),0) AS total_ng,
                ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time))/3600,2) AS total_prod_hrs,
                COUNT(DISTINCT pdr.machine_id) AS machine_count,
                COUNT(DISTINCT COALESCE(pdr.production_user_id,pdr.setup_user_id)) AS user_count
                FROM pm_process_daily_report pdr WHERE $where
                AND (pdr.production_start_time IS NOT NULL OR pdr.setup_start_time IS NOT NULL)");
            $st->execute(array_merge([$df,$dt],$p));
            echo json_encode(['success'=>true,'data'=>$st->fetch(PDO::FETCH_ASSOC)]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── KPI 人員報工明細（逐筆） ──────────────────────────────
    if ($action === 'get_kpi_user_detail') {
        set_time_limit(60); // 給予充裕執行時間
        $df   = $_POST['date_from']  ?? date('Y-m-01');
        $dt   = $_POST['date_to']    ?? date('Y-m-d');
        $uid  = intval($_POST['user_id']    ?? 0);
        $mid  = intval($_POST['machine_id'] ?? 0);
        $page = max(1, intval($_POST['page'] ?? 1));
        $pp   = intval($_POST['per_page'] ?? 10);
        if (!in_array($pp,[10,20,30])) $pp = 10;
        $off  = ($page-1)*$pp;

        // 工時差異警示閾值
        try {
            $apRow = $pdo->query("SELECT setting_value FROM kpi_standard_setting WHERE setting_key='time_diff_alert_pct' LIMIT 1")->fetchColumn();
            $alert_pct = ($apRow !== false && is_numeric($apRow)) ? floatval($apRow) : 20;
        } catch(Exception $e) { $alert_pct = 20; }
        // 讀取標準工時寬放率（%）
        try { $arRow2=$pdo->query("SELECT setting_value FROM kpi_standard_setting WHERE setting_key='allowance_rate' LIMIT 1")->fetchColumn(); $allowance_rate2=($arRow2!==false&&is_numeric($arRow2))?floatval($arRow2)/100:0; } catch(Exception $ex2){$allowance_rate2=0;}

        try {
            $where = "pdr.report_date BETWEEN ? AND ?";
            $p = [$df,$dt];
            if ($uid) { $where .= " AND (pdr.production_user_id=? OR pdr.setup_user_id=?)"; $p[]=$uid; $p[]=$uid; }
            if ($mid) { $where .= " AND pdr.machine_id=?"; $p[]=$mid; }
            $where .= " AND (pdr.production_start_time IS NOT NULL OR pdr.setup_start_time IS NOT NULL)";

            // 主查詢：每筆報工明細，JOIN 料號標準算出標準工時
            // ✅ 改用 LEFT JOIN 取代 8 個 correlated subquery，大幅提升查詢效能
            $sql = "SELECT
                pdr.report_id, pdr.report_date, pdr.report_source,
                pdr.machine_id, ml.machine,
                pdr.produced_qty,
                pdr.setup_start_time, pdr.setup_end_time,
                pdr.production_start_time, pdr.production_end_time,
                COALESCE(u1.user_cname,'—') AS prod_user,
                COALESCE(u2.user_cname,'—') AS setup_user,
                COALESCE(udpm1.dept_name,'') AS prod_dept,
                COALESCE(udpm1.pos_name,'') AS prod_pos,
                (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id=pdr.report_id) AS ng_qty,
                ROUND(TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time)/3600,2) AS prod_hrs,
                ROUND(TIMESTAMPDIFF(SECOND,pdr.setup_start_time,pdr.setup_end_time)/3600,2) AS setup_hrs,
                bi.bom_ing_fid, b.d_id AS part_no, b.Client_Name,
                pn.ProcessName, pn.process_type_id,
                kpi_j.coefficient,
                kpi_j.part_base_t,
                kpi_j.part_base_p,
                kpi_j.multiplier,
                kpi_j.grp_base_t,
                kpi_j.grp_base_p,
                kpi_j.grp_def_coeff,
                kpi_j.has_custom_std,
                kpi_j.group_id,
                dsg.Module, dsg.Teeth, dsg.Face_Width, ds.Type AS part_type
                FROM pm_process_daily_report pdr
                LEFT JOIN machine_list ml ON ml.machine_id=pdr.machine_id
                LEFT JOIN user u1 ON u1.id=pdr.production_user_id
                LEFT JOIN user u2 ON u2.id=pdr.setup_user_id
                LEFT JOIN (
                    SELECT udm.user_id, d.name AS dept_name, pos.name AS pos_name
                    FROM user_department_position_map udm
                    LEFT JOIN department d ON d.id=udm.department_id
                    LEFT JOIN position pos ON pos.id=udm.position_id
                    WHERE udm.is_main=1
                ) udpm1 ON udpm1.user_id=pdr.production_user_id
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN d_setting ds ON ds.D_Setting_Id=b.d_id
                LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id=ds.d_id
                LEFT JOIN process_no pn ON pn.ProcessNo=pdr.process_no
                LEFT JOIN (
                    SELECT
                        pgm2.process_no,
                        kps2.d_setting_id,
                        kps2.coefficient,
                        kps2.base_time_sec AS part_base_t,
                        kps2.base_price    AS part_base_p,
                        kps2.multiplier,
                        kps2.std_id        AS has_custom_std,
                        pgm2.group_id,
                        std2.base_time_sec AS grp_base_t,
                        std2.base_price    AS grp_base_p,
                        std2.fixed_price_per_pcs AS grp_fixed_price,
                        COALESCE(kd2.default_coefficient,1.0) AS grp_def_coeff,
                        CASE WHEN kgf2.formula_id IS NOT NULL THEN 1 ELSE 0 END AS grp_has_formula,
                        ROW_NUMBER() OVER (PARTITION BY pgm2.process_no, COALESCE(kps2.d_setting_id,0) ORDER BY pgm2.group_id) AS rn
                    FROM kpi_process_group_map pgm2
                    LEFT JOIN kpi_part_standard kps2 ON kps2.group_id=pgm2.group_id
                    LEFT JOIN kpi_std_time_default std2 ON std2.group_id=pgm2.group_id
                    LEFT JOIN kpi_difficulty_default kd2 ON kd2.group_id=pgm2.group_id
                    LEFT JOIN kpi_group_formula kgf2 ON kgf2.group_id=pgm2.group_id AND kgf2.is_active=1
                ) kpi_j ON kpi_j.process_no=pdr.process_no
                    AND kpi_j.rn=1
                    AND (kpi_j.d_setting_id=ds.d_id OR kpi_j.d_setting_id IS NULL)
                WHERE $where
                ORDER BY pdr.report_date DESC, pdr.report_id DESC
                LIMIT ? OFFSET ?";

            $params = array_merge($p, [intval($pp), intval($off)]);
            $st = $pdo->prepare($sql);
            // 明確綁定 LIMIT/OFFSET 為 int，避免 PDO 傳字串造成 MySQL syntax error
            foreach ($p as $i => $v) { $st->bindValue($i+1, $v); }
            $st->bindValue(count($p)+1, intval($pp), PDO::PARAM_INT);
            $st->bindValue(count($p)+2, intval($off), PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // 計算標準工時
            foreach ($rows as &$r) {
                $coeff    = floatval($r['coefficient']   ?: $r['grp_def_coeff'] ?: 1.0);
                $base_t   = floatval($r['part_base_t']   ?: $r['grp_base_t']    ?: 0);
                $qty      = intval($r['produced_qty']);
                $ng       = intval($r['ng_qty']);
                $multi    = floatval($r['multiplier']    ?: 1.0);

                if (!empty($r['part_base_t'])) {
                    // 1. 優先使用料號個別設定的「每PCS工時」 -> 直接使用 (不再乘齒輪參數)
                    $std_sec = floatval($r['part_base_t']) * $coeff * $multi * $qty;
                } else {
                    // 2. 使用群組預設工時
                    $base_t = floatval($r['grp_base_t'] ?: 0);
                    if ($r['part_type'] === 'G' && floatval($r['Module']) > 0) {
                        $gear_factor = floatval($r['Module']) * floatval($r['Teeth']) * floatval($r['Face_Width']);
                        $std_sec = $base_t * $gear_factor * $coeff * $qty;
                    } else {
                        $std_sec = $base_t * $coeff * $multi * $qty;
                    }
                }

                $actual_sec = floatval($r['prod_hrs']) * 3600;
                // 寬放後標準工時 = std_sec × (1 + allowance_rate)
                $std_sec_allowed = $std_sec * (1 + $allowance_rate2);
                $r['std_hrs']      = $std_sec_allowed > 0 ? round($std_sec_allowed / 3600, 2) : null;
                $r['std_hrs_pure'] = $std_sec > 0 ? round($std_sec / 3600, 2) : null;
                $r['allowance_pct'] = $allowance_rate2 > 0 ? round($allowance_rate2 * 100) : 0;
                $r['efficiency'] = ($std_sec_allowed > 0 && $actual_sec > 0)
                    ? round($std_sec_allowed / $actual_sec * 100, 1)
                    : null;
                // 差異警示
                $r['time_alert'] = false;
                if ($actual_sec > 0 && $std_sec_allowed > 0) {
                    $diff_pct = abs($actual_sec - $std_sec_allowed) / $std_sec_allowed * 100;
                    $r['time_alert'] = $diff_pct > $alert_pct;
                }
                // 良品率
                $total = $qty + $ng;
                $r['yield_rate'] = $total > 0 ? round($qty / $total * 100, 1) : null;
                $fixed_p_grp = floatval($r['grp_fixed_price'] ?? 0);
                $base_p = floatval($r['part_base_p'] ?: $r['grp_base_p'] ?: 0);
                if ($fixed_p_grp > 0 && empty($r['part_base_t'])) {
                    $r['est_price_per_pc'] = $fixed_p_grp;
                } elseif ($base_p > 0) {
                    if (!empty($r['part_base_t'])) {
                        $r['est_price_per_pc'] = round(floatval($r['part_base_t']) * $coeff * $multi * $base_p, 4);
                    } else {
                        $base_t = floatval($r['grp_base_t'] ?: 0);
                        if ($r['part_type'] === 'G' && floatval($r['Module']) > 0) {
                            $gear_factor = floatval($r['Module']) * floatval($r['Teeth']) * floatval($r['Face_Width']);
                            $r['est_price_per_pc'] = round($base_t * $gear_factor * $coeff * $base_p, 4);
                        } else {
                            $r['est_price_per_pc'] = round($base_t * $coeff * $multi * $base_p, 4);
                        }
                    }
                } else {
                    $r['est_price_per_pc'] = null;
                }
            }

            // 總筆數
            $cntSql = "SELECT COUNT(*) FROM pm_process_daily_report pdr
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                WHERE $where";
            $cnt = $pdo->prepare($cntSql); $cnt->execute($p);

            echo json_encode([
                'success'    => true,
                'data'       => $rows,
                'total'      => $cnt->fetchColumn(),
                'page'       => $page,
                'per_page'   => $pp,
                'alert_pct'  => $alert_pct
            ]);
        } catch(Exception $e) { error_log('[KPI user_detail] '.$e->getMessage()); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }


    // ── KPI 機台稼動彙總（每台機台一列） ─────────────────────
    if ($action === 'get_kpi_machine_agg') {
        $df=$_POST['date_from']??date('Y-m-01'); $dt=$_POST['date_to']??date('Y-m-d');
        $uid=intval($_POST['user_id']??0); $mid=intval($_POST['machine_id']??0);
        $mtid=intval($_POST['machine_type_id']??0); $partno=trim($_POST['part_no']??'');
        try {
            try{$tR=$pdo->query("SELECT setting_value FROM kpi_standard_setting WHERE setting_key='target_utilization' LIMIT 1")->fetchColumn();$tgt=($tR!==false&&is_numeric($tR))?floatval($tR):80;}catch(Exception $e2){$tgt=80;}
            $EXISTS_CLAUSE="AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)";
            $where="pdr.report_date BETWEEN ? AND ? AND pdr.production_start_time IS NOT NULL"; $p=[$df,$dt];
            if($uid){$where.=" AND (pdr.production_user_id=? OR pdr.setup_user_id=?)";$p[]=$uid;$p[]=$uid;}
            if($mid){$where.=" AND pdr.machine_id=?";$p[]=$mid;}
            if($mtid){$where.=" AND ml.machine_type_id=?";$p[]=$mtid;}
            if($partno){$where.=" $EXISTS_CLAUSE";$p[]="%$partno%";}
            $sql="SELECT ml.machine_id, ml.machine AS label, ml.machine_type_id, pt.process_type AS machine_type,
                COUNT(DISTINCT pdr.report_id) AS report_count,
                SUM(pdr.produced_qty) AS total_ok,
                COALESCE(SUM(ng.ng_sum),0) AS total_ng,
                ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time))/3600,2) AS prod_hrs,
                ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.setup_start_time,pdr.setup_end_time))/3600,2) AS setup_hrs,
                COUNT(DISTINCT pdr.report_date) AS work_days
                FROM pm_process_daily_report pdr
                JOIN machine_list ml ON ml.machine_id=pdr.machine_id
                LEFT JOIN process_type pt ON pt.process_type_id=ml.machine_type_id
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN (SELECT n.report_id,SUM(n.ng_qty) AS ng_sum FROM pm_process_daily_ng n GROUP BY n.report_id) ng ON ng.report_id=pdr.report_id
                WHERE $where
                GROUP BY ml.machine_id,ml.machine,ml.machine_type_id,pt.process_type
                ORDER BY ml.machine_type_id ASC,ml.machine ASC,ml.machine_id ASC LIMIT 200";
            $st=$pdo->prepare($sql);$st->execute($p);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);
            // kps map
            $kpsRows=$pdo->query("SELECT pgm.process_no,pgm.group_id,COALESCE(kps.d_setting_id,0) AS ds_id,COALESCE(kps.coefficient,gd.default_coefficient,1.0) AS coeff,COALESCE(kps.base_time_sec,std.base_time_sec) AS base_t,COALESCE(kps.base_price,std.base_price) AS base_p,kps.multiplier,std.fixed_price_per_pcs AS fixed_price FROM kpi_process_group_map pgm LEFT JOIN kpi_part_standard kps ON kps.group_id=pgm.group_id LEFT JOIN kpi_difficulty_default gd ON gd.group_id=pgm.group_id LEFT JOIN kpi_std_time_default std ON std.group_id=pgm.group_id ORDER BY pgm.process_no,kps.d_setting_id DESC")->fetchAll(PDO::FETCH_ASSOC);
            $kpsMap=[];
            foreach($kpsRows as $kr){$k2=$kr['process_no'].'_'.$kr['ds_id'];if(!isset($kpsMap[$k2]))$kpsMap[$k2]=$kr;$dk=$kr['process_no'].'_0';if(!isset($kpsMap[$dk]))$kpsMap[$dk]=$kr;}
            foreach($rows as &$r){
                $machId=$r['machine_id']; $total=($r['total_ok']??0)+($r['total_ng']??0);
                $r['yield_rate']=$total>0?round($r['total_ok']/$total*100,1):null;
                // utilization via shift schedule
                $theoryMin=0;$hasSchedule=false;
                $dwS=$pdo->prepare("SELECT report_date,GROUP_CONCAT(DISTINCT production_user_id) AS user_ids FROM pm_process_daily_report WHERE machine_id=? AND report_date BETWEEN ? AND ? AND production_start_time IS NOT NULL AND production_user_id IS NOT NULL GROUP BY report_date");
                $dwS->execute([$machId,$df,$dt]);
                foreach($dwS->fetchAll(PDO::FETCH_ASSOC) as $dw){
                    $date2=$dw['report_date'];$dow2=date('N',strtotime($date2));
                    foreach(array_filter(explode(',',$dw['user_ids'])) as $wid2){
                        $wid2=intval($wid2);
                        $eS=$pdo->prepare("SELECT se.shift_type_id,se.custom_start,se.custom_end,se.custom_break,st.total_minutes,st.break_minutes,st.is_overnight FROM shift_exception se LEFT JOIN shift_type st ON st.shift_type_id=se.shift_type_id WHERE se.user_id=? AND se.exception_date=? LIMIT 1");
                        $eS->execute([$wid2,$date2]);$exc=$eS->fetch(PDO::FETCH_ASSOC);
                        if($exc){if($exc['shift_type_id']===null)continue;if($exc['custom_start']&&$exc['custom_end']){$s2=strtotime($date2.' '.$exc['custom_start']);$e2t=strtotime($date2.' '.$exc['custom_end']);if($exc['is_overnight'])$e2t+=86400;$theoryMin+=max(0,round(($e2t-$s2)/60)-intval($exc['custom_break']??$exc['break_minutes']??0));}else{$theoryMin+=intval($exc['total_minutes']??0);}$hasSchedule=true;}
                        else{$sS=$pdo->prepare("SELECT ss.cycle_type,ss.weekdays,ss.month_days,st.total_minutes FROM shift_schedule ss JOIN shift_type st ON st.shift_type_id=ss.shift_type_id WHERE ss.user_id=? AND ss.effective_from<=? AND (ss.effective_to IS NULL OR ss.effective_to>=?) AND st.is_active=1 ORDER BY ss.effective_from DESC LIMIT 5");$sS->execute([$wid2,$date2,$date2]);
                        foreach($sS->fetchAll(PDO::FETCH_ASSOC) as $sc){
                            if($sc['cycle_type']==='weekly'){$wds=array_filter(array_map('trim',explode(',',$sc['weekdays']??'')));if(in_array($dow2,$wds)||in_array(strval($dow2),$wds)){$theoryMin+=intval($sc['total_minutes']??0);$hasSchedule=true;break;}}
                            elseif($sc['cycle_type']==='monthly'){$mds=array_filter(array_map('trim',explode(',',$sc['month_days']??'')));$dom=intval(date('j',strtotime($date2)));if(in_array($dom,$mds)||in_array(strval($dom),$mds)){$theoryMin+=intval($sc['total_minutes']??0);$hasSchedule=true;break;}}
                            elseif($sc['cycle_type']==='range'){$theoryMin+=intval($sc['total_minutes']??0);$hasSchedule=true;break;}
                        }}
                    }
                }
                $r['utilization']=($theoryMin>0)?round(floatval($r['prod_hrs'])/($theoryMin/60)*100,1):null;
                $r['utilization_warn']=($r['utilization']!==null&&$r['utilization']>100);
                $r['vs_target']=$r['utilization']!==null?round($r['utilization']-$tgt,1):null;
                $r['target']=$tgt;
                // amount
                $amtP2=[$machId,$df,$dt]; $amtSQL2="SELECT pdr.process_no,pdr.produced_qty,ds.d_id AS d_setting_id,ds.Type AS part_type,dsg.Module,dsg.Teeth,dsg.Face_Width FROM pm_process_daily_report pdr LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid LEFT JOIN bom b ON b.bom=bi.bom LEFT JOIN d_setting ds ON ds.D_Setting_Id=b.d_id LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id=ds.d_id WHERE pdr.machine_id=? AND pdr.report_date BETWEEN ? AND ? AND pdr.production_start_time IS NOT NULL";
                if($partno){$amtSQL2.=" $EXISTS_CLAUSE";$amtP2[]="%$partno%";}
                $amtS=$pdo->prepare($amtSQL2);$amtS->execute($amtP2);$amtTotal=0;
                foreach($amtS->fetchAll(PDO::FETCH_ASSOC) as $ar){
                    $di=intval($ar['d_setting_id']??0);$pn=$ar['process_no'];$qy=intval($ar['produced_qty']);
                    $kp=$kpsMap[$pn.'_'.$di]??$kpsMap[$pn.'_0']??null;if(!$kp||$qy<=0)continue;
                    $fp=floatval($kp['fixed_price']??0);$c=floatval($kp['coeff']??1);$bT=floatval($kp['base_t']??0);$bP=floatval($kp['base_p']??0);$mu=floatval($kp['multiplier']??1);
                    if($fp>0) $amtTotal+=$fp*$qy;
                    elseif(($ar['part_type']??'')==='G'&&floatval($ar['Module']??0)>0) $amtTotal+=$bT*floatval($ar['Module'])*floatval($ar['Teeth'])*floatval($ar['Face_Width'])*$c*$bP*$qy;
                    else $amtTotal+=$bT*$c*$mu*$bP*$qy;
                }
                $r['amount']=$amtTotal;
            }
            unset($r);
            echo json_encode(['success'=>true,'data'=>$rows,'target_util'=>$tgt]);
        } catch(Exception $e){error_log('[KPI mc_agg] '.$e->getMessage());echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── KPI 機台稼動明細（逐筆） ──────────────────────────────
    if ($action === 'get_kpi_machine_detail') {
        $df   = $_POST['date_from']  ?? date('Y-m-01');
        $dt   = $_POST['date_to']    ?? date('Y-m-d');
        $uid  = intval($_POST['user_id']    ?? 0);
        $mid  = intval($_POST['machine_id'] ?? 0);
        $mtid = intval($_POST['machine_type_id'] ?? 0);
        $partno = trim($_POST['part_no'] ?? '');
        $page = max(1, intval($_POST['page'] ?? 1));
        $pp   = intval($_POST['per_page'] ?? 10);
        if (!in_array($pp,[10,20,30])) $pp = 10;
        $off  = ($page-1)*$pp;

        try {
            $where = "pdr.report_date BETWEEN ? AND ? AND pdr.production_start_time IS NOT NULL";
            $p = [$df,$dt];
            if ($uid)    { $where .= " AND (pdr.production_user_id=? OR pdr.setup_user_id=?)"; $p[]=$uid; $p[]=$uid; }
            if ($mid)    { $where .= " AND pdr.machine_id=?"; $p[]=$mid; }
            if ($mtid)   { $where .= " AND ml.machine_type_id=?"; $p[]=$mtid; }
            if ($partno) { $where .= " AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)"; $p[]="%$partno%"; }

            try {
                $tgtRow = $pdo->query("SELECT setting_value FROM kpi_standard_setting WHERE setting_key='target_utilization' LIMIT 1")->fetchColumn();
                $tgt = ($tgtRow !== false && is_numeric($tgtRow)) ? floatval($tgtRow) : 80;
            } catch(Exception $e2) { $tgt = 80; }

            // 主查詢：加入報工人員班別工時（反推法）
            // 稼動率分母 = 報工當天，在此機台有報工的所有不重複人員的班別工時合計
            $sql = "SELECT pdr.report_id, pdr.report_date, pdr.process_no,
                ml.machine_id, ml.machine, ml.machine_type_id,
                pt.process_type AS machine_type,
                pdr.produced_qty,
                (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id=pdr.report_id) AS ng_qty,
                pdr.production_start_time, pdr.production_end_time,
                pdr.setup_start_time, pdr.setup_end_time,
                ROUND(TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time)/3600,2) AS prod_hrs,
                ROUND(TIMESTAMPDIFF(SECOND,pdr.setup_start_time,pdr.setup_end_time)/3600,2) AS setup_hrs,
                COALESCE(u1.user_cname,'—') AS prod_user,
                COALESCE(udpm.dept_name,'') AS prod_dept,
                COALESCE(udpm.pos_name,'') AS prod_pos,
                b.d_id AS part_no, b.bom AS bom_no, b.Client_Name, pn.ProcessName, pn.process_type_id,
                ds.d_id AS d_setting_id, ds.Type AS part_type,
                dsg.Module, dsg.Teeth, dsg.Face_Width,
                pdr.production_user_id,
                pdr.bom_ing_fid,
                (SELECT pgm2.group_id FROM kpi_process_group_map pgm2 WHERE pgm2.process_no=pdr.process_no LIMIT 1) AS group_id,
                (SELECT kps2.std_id FROM kpi_process_group_map pgm3 LEFT JOIN kpi_part_standard kps2 ON kps2.group_id=pgm3.group_id AND kps2.d_setting_id=ds.d_id WHERE pgm3.process_no=pdr.process_no LIMIT 1) AS has_custom_std
                FROM pm_process_daily_report pdr
                JOIN machine_list ml ON ml.machine_id=pdr.machine_id
                LEFT JOIN process_type pt ON pt.process_type_id=ml.machine_type_id
                LEFT JOIN user u1 ON u1.id=pdr.production_user_id
                LEFT JOIN (
                    SELECT udm.user_id, d.name AS dept_name, pos.name AS pos_name
                    FROM user_department_position_map udm
                    LEFT JOIN department d ON d.id=udm.department_id
                    LEFT JOIN position pos ON pos.id=udm.position_id
                    WHERE udm.is_main=1
                ) udpm ON udpm.user_id=pdr.production_user_id
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN d_setting ds ON ds.D_Setting_Id=b.d_id
                LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id=ds.d_id
                LEFT JOIN process_no pn ON pn.ProcessNo=pdr.process_no
                WHERE $where
                ORDER BY pdr.report_date DESC, pdr.machine_id, pdr.report_id DESC
                LIMIT ? OFFSET ?";

            $st = $pdo->prepare($sql);
            foreach ($p as $i => $v) { $st->bindValue($i+1, $v); }
            $st->bindValue(count($p)+1, intval($pp), PDO::PARAM_INT);
            $st->bindValue(count($p)+2, intval($off), PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // 預先查詢區間內每台機台每天的班別工時合計（報工反推法）
            // 邏輯：找出每天在同一機台有報工的所有不重複人員，加總其班別工時
            // shift_schedule + shift_exception 決定當天班別，取 total_minutes
            $utilizationCache = []; // key = "machine_id_date"
            if (!empty($rows)) {
                // 收集需要的 machine_id + date 組合
                $machDatePairs = [];
                foreach ($rows as $r) {
                    $key = $r['machine_id'].'_'.$r['report_date'];
                    if (!isset($machDatePairs[$key])) {
                        $machDatePairs[$key] = ['machine_id'=>$r['machine_id'], 'date'=>$r['report_date']];
                    }
                }

                foreach ($machDatePairs as $key => $pair) {
                    $machId = $pair['machine_id'];
                    $date   = $pair['date'];
                    $dow    = date('N', strtotime($date)); // 1=週一 7=週日

                    // 找出當天在此機台有報工的不重複人員
                    $workerStmt = $pdo->prepare(
                        "SELECT DISTINCT production_user_id FROM pm_process_daily_report
                         WHERE machine_id=? AND report_date=? AND production_user_id IS NOT NULL AND production_start_time IS NOT NULL"
                    );
                    $workerStmt->execute([$machId, $date]);
                    $workerIds = $workerStmt->fetchAll(PDO::FETCH_COLUMN);

                    $totalMinutes = 0;
                    foreach ($workerIds as $wid) {
                        // 先查例外日（優先）
                        $excStmt = $pdo->prepare(
                            "SELECT se.shift_type_id, se.custom_start, se.custom_end, se.custom_break, se.exception_type,
                                    st.total_minutes, st.break_minutes, st.start_time, st.end_time, st.is_overnight
                             FROM shift_exception se
                             LEFT JOIN shift_type st ON st.shift_type_id=se.shift_type_id
                             WHERE se.user_id=? AND se.exception_date=? LIMIT 1"
                        );
                        $excStmt->execute([$wid, $date]);
                        $exc = $excStmt->fetch(PDO::FETCH_ASSOC);

                        if ($exc) {
                            if ($exc['shift_type_id'] === null) {
                                // 休假，不出勤，工時=0
                                continue;
                            }
                            // 有客製化時間時計算
                            if ($exc['custom_start'] && $exc['custom_end']) {
                                $s = strtotime($date.' '.$exc['custom_start']);
                                $e = strtotime($date.' '.$exc['custom_end']);
                                if ($exc['is_overnight'] ?? false) $e += 86400;
                                $brk = intval($exc['custom_break'] ?? $exc['break_minutes'] ?? 0);
                                $mins = max(0, round(($e-$s)/60) - $brk);
                            } else {
                                $mins = intval($exc['total_minutes'] ?? 0);
                            }
                            $totalMinutes += $mins;
                        } else {
                            // 查週期排班
                            $schStmt = $pdo->prepare(
                                "SELECT ss.shift_type_id, ss.cycle_type, ss.weekdays, ss.month_days,
                                        st.total_minutes
                                 FROM shift_schedule ss
                                 JOIN shift_type st ON st.shift_type_id=ss.shift_type_id
                                 WHERE ss.user_id=? AND ss.effective_from<=? AND (ss.effective_to IS NULL OR ss.effective_to>=?)
                                   AND st.is_active=1
                                 ORDER BY ss.effective_from DESC LIMIT 5"
                            );
                            $schStmt->execute([$wid, $date, $date]);
                            $schedules = $schStmt->fetchAll(PDO::FETCH_ASSOC);

                            $matched = false;
                            foreach ($schedules as $sch) {
                                if ($sch['cycle_type']==='weekly') {
                                    $wdays = array_filter(array_map('trim', explode(',', $sch['weekdays']??'')));
                                    if (in_array($dow, $wdays) || in_array(strval($dow), $wdays)) {
                                        $totalMinutes += intval($sch['total_minutes']??0);
                                        $matched = true; break;
                                    }
                                } elseif ($sch['cycle_type']==='monthly') {
                                    $mdays = array_filter(array_map('trim', explode(',', $sch['month_days']??'')));
                                    $dom = intval(date('j', strtotime($date)));
                                    if (in_array($dom, $mdays) || in_array(strval($dom), $mdays)) {
                                        $totalMinutes += intval($sch['total_minutes']??0);
                                        $matched = true; break;
                                    }
                                } elseif ($sch['cycle_type']==='range') {
                                    $totalMinutes += intval($sch['total_minutes']??0);
                                    $matched = true; break;
                                }
                            }
                            // 若無排班，預設 0（前端會顯示「需設定排班」）
                        }
                    }
                    $utilizationCache[$key] = $totalMinutes; // 分鐘
                }
            }

            // ── 批次預載 KPI 標準（避免 machine_detail 的 N+1）──
            $mcKpsMap = [];
            try {
                $mcKpsSt = $pdo->prepare(
                    "SELECT pgm.process_no, COALESCE(kps.d_setting_id,0) AS ds_id,
                        COALESCE(kps.coefficient,gd.default_coefficient,1.0) AS coeff,
                        COALESCE(kps.base_time_sec,std.base_time_sec) AS base_t,
                        COALESCE(kps.base_price,std.base_price) AS base_p,
                        COALESCE(kps.multiplier,1.0) AS multiplier,
                        std.fixed_price_per_pcs AS fixed_price
                     FROM kpi_process_group_map pgm
                     LEFT JOIN kpi_part_standard kps ON kps.group_id=pgm.group_id
                     LEFT JOIN kpi_difficulty_default gd ON gd.group_id=pgm.group_id
                     LEFT JOIN kpi_std_time_default std ON std.group_id=pgm.group_id
                     ORDER BY pgm.process_no, kps.d_setting_id DESC"
                );
                $mcKpsSt->execute();
                $mcKpsRows = $mcKpsSt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($mcKpsRows as $kr) {
                    $k2 = $kr['process_no'].'_'.$kr['ds_id'];
                    if (!isset($mcKpsMap[$k2])) $mcKpsMap[$k2] = $kr;
                    $dk = $kr['process_no'].'_0';
                    if (!isset($mcKpsMap[$dk])) $mcKpsMap[$dk] = $kr;
                }
            } catch(Exception $e3) {}

            foreach ($rows as &$r) {
                $prod_hrs  = floatval($r['prod_hrs']);
                $setup_hrs = floatval($r['setup_hrs']);
                $produced  = intval($r['produced_qty']);
                $ng        = intval($r['ng_qty']);
                $total_qty = $produced + $ng;

                // 稼動率（報工反推法）
                $cacheKey = $r['machine_id'].'_'.$r['report_date'];
                $theoryMin = $utilizationCache[$cacheKey] ?? 0;
                $r['theory_hrs'] = $theoryMin > 0 ? round($theoryMin/60, 2) : null;
                $r['has_schedule'] = ($theoryMin > 0);

                if ($theoryMin > 0) {
                    $r['utilization'] = round($prod_hrs / ($theoryMin/60) * 100, 1);
                    $r['utilization_warn'] = ($r['utilization'] > 100);
                } else {
                    $r['utilization'] = null;
                    $r['utilization_warn'] = false;
                }
                $r['vs_target'] = $r['utilization'] !== null ? round($r['utilization'] - $tgt, 1) : null;
                $r['yield_rate'] = $total_qty > 0 ? round($produced / $total_qty * 100, 1) : null;
                $r['avg_min_per_pc'] = ($produced > 0 && $prod_hrs > 0) ? round($prod_hrs * 60 / $produced, 2) : null;

                // 生產金額（從批次 Map 取值）
                $r['amount'] = 0;
                if (!empty($r['process_no']) && $produced > 0) {
                    $dsId2 = intval($r['d_setting_id'] ?? 0);
                    $kp = $mcKpsMap[$r['process_no'].'_'.$dsId2] ?? $mcKpsMap[$r['process_no'].'_0'] ?? null;
                    if ($kp) {
                        $fp2=floatval($kp['fixed_price']??0);$c=floatval($kp['coeff']??1);$bT=floatval($kp['base_t']??0);$bP=floatval($kp['base_p']??0);$mu=floatval($kp['multiplier']??1);
                        if ($fp2>0) $r['amount']=$fp2*$produced;
                        elseif ($bT>0&&$bP>0) {
                            if (($r['part_type']??'')==='G'&&floatval($r['Module']??0)>0)
                                $r['amount']=$bT*floatval($r['Module'])*floatval($r['Teeth'])*floatval($r['Face_Width'])*$c*$bP*$produced;
                            else
                                $r['amount']=$bT*$c*$mu*$bP*$produced;
                        }
                    }
                }

                // 架機時間區間
                $r['setup_time_range'] = '';
                if (!empty($r['setup_start_time'])) {
                    $ss = substr($r['setup_start_time'], 11, 5);
                    $se = !empty($r['setup_end_time']) ? substr($r['setup_end_time'], 11, 5) : '未完';
                    $r['setup_time_range'] = $ss.'~'.$se;
                }
                // 生產時間區間
                $r['prod_time_range'] = '';
                if (!empty($r['production_start_time'])) {
                    $ps2 = substr($r['production_start_time'], 11, 5);
                    $pe2 = !empty($r['production_end_time']) ? substr($r['production_end_time'], 11, 5) : '未完';
                    $r['prod_time_range'] = $ps2.'~'.$pe2;
                }
            }

            $cntWhere = $where;
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM pm_process_daily_report pdr JOIN machine_list ml ON ml.machine_id=pdr.machine_id LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid LEFT JOIN bom b ON b.bom=bi.bom WHERE $cntWhere");
            $cnt->execute($p);

            echo json_encode([
                'success'     => true,
                'data'        => $rows,
                'total'       => $cnt->fetchColumn(),
                'page'        => $page,
                'per_page'    => $pp,
                'target_util' => $tgt
            ]);
        } catch(Exception $e) { error_log('[KPI machine_detail] '.$e->getMessage()); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── KPI 統計摘要 ──────────────────────────────────────────
    if ($action === 'get_kpi_summary_agg') {
        $df   = $_POST['date_from']  ?? date('Y-m-01');
        $dt   = $_POST['date_to']    ?? date('Y-m-d');
        $uid  = intval($_POST['user_id']    ?? 0);
        $mid  = intval($_POST['machine_id'] ?? 0);
        $mtid = intval($_POST['machine_type_id'] ?? 0);
        $partno = trim($_POST['part_no'] ?? '');
        $view = $_POST['view'] ?? 'user';
        try {
            $where = "pdr.report_date BETWEEN ? AND ?";
            $p = [$df,$dt];
            if ($uid)    { $where .= " AND (pdr.production_user_id=? OR pdr.setup_user_id=?)"; $p[]=$uid; $p[]=$uid; }
            if ($mid)    { $where .= " AND pdr.machine_id=?"; $p[]=$mid; }
            if ($mtid && $view==='machine') { $where .= " AND ml.machine_type_id=?"; $p[]=$mtid; }
            if ($partno) { $where .= " AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)"; $p[]="%$partno%"; }

            try {
                $tgtRow2 = $pdo->query("SELECT setting_value FROM kpi_standard_setting WHERE setting_key='target_utilization' LIMIT 1")->fetchColumn();
                $tgt = ($tgtRow2 !== false && is_numeric($tgtRow2)) ? floatval($tgtRow2) : 80;
            } catch(Exception $e2) { $tgt = 80; }

            if ($view === 'machine') {
                $where .= " AND pdr.production_start_time IS NOT NULL";
                // 機台彙總：稼動率用報工反推法
                // 分子=區間總生產秒數；分母=區間每日不重複人員班別工時合計（各日加總後再加總）
                $sql = "SELECT ml.machine_id, ml.machine AS label,
                    COUNT(DISTINCT pdr.report_id) AS report_count,
                    SUM(pdr.produced_qty) AS total_ok,
                    COALESCE(SUM(ng.ng_sum),0) AS total_ng,
                    ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time))/3600,2) AS prod_hrs,
                    ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.setup_start_time,pdr.setup_end_time))/3600,2) AS setup_hrs,
                    COUNT(DISTINCT pdr.report_date) AS work_days
                    FROM pm_process_daily_report pdr
                    JOIN machine_list ml ON ml.machine_id=pdr.machine_id
                    LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                    LEFT JOIN bom b ON b.bom=bi.bom
                    LEFT JOIN (SELECT n.report_id,SUM(n.ng_qty) AS ng_sum FROM pm_process_daily_ng n GROUP BY n.report_id) ng ON ng.report_id=pdr.report_id
                    WHERE $where GROUP BY ml.machine_id ORDER BY prod_hrs DESC LIMIT 50";

                $st = $pdo->prepare($sql); $st->execute($p);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);

                // 對每台機台，計算區間內各有報工日的班別工時合計（報工反推法）
                foreach ($rows as &$r) {
                    $total = ($r['total_ok']??0)+($r['total_ng']??0);
                    $r['yield_rate'] = $total>0 ? round($r['total_ok']/$total*100,1) : null;

                    // 查詢此機台在區間內各有報工日的報工人員班別工時合計
                    $theoryTotalMin = 0;
                    $hasSchedule = false;

                    // 取得此機台在區間內有報工的日期與人員組合
                    $dayWorkerStmt = $pdo->prepare(
                        "SELECT report_date, GROUP_CONCAT(DISTINCT production_user_id) AS user_ids
                         FROM pm_process_daily_report
                         WHERE machine_id=? AND report_date BETWEEN ? AND ?
                           AND production_start_time IS NOT NULL AND production_user_id IS NOT NULL
                         GROUP BY report_date"
                    );
                    $dayWorkerStmt->execute([$r['machine_id'], $df, $dt]);
                    $dayWorkers = $dayWorkerStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($dayWorkers as $dw) {
                        $date2 = $dw['report_date'];
                        $dow2  = date('N', strtotime($date2));
                        $uids  = array_filter(explode(',', $dw['user_ids']));
                        foreach ($uids as $wid2) {
                            $wid2 = intval($wid2);
                            // 例外日
                            $excS2 = $pdo->prepare("SELECT se.shift_type_id,se.custom_start,se.custom_end,se.custom_break,st.total_minutes,st.break_minutes,st.is_overnight FROM shift_exception se LEFT JOIN shift_type st ON st.shift_type_id=se.shift_type_id WHERE se.user_id=? AND se.exception_date=? LIMIT 1");
                            $excS2->execute([$wid2,$date2]);
                            $exc2 = $excS2->fetch(PDO::FETCH_ASSOC);
                            if ($exc2) {
                                if ($exc2['shift_type_id']===null) continue;
                                if ($exc2['custom_start']&&$exc2['custom_end']) {
                                    $s2=strtotime($date2.' '.$exc2['custom_start']);$e2t=strtotime($date2.' '.$exc2['custom_end']);
                                    if($exc2['is_overnight'])$e2t+=86400;
                                    $theoryTotalMin+=max(0,round(($e2t-$s2)/60)-intval($exc2['custom_break']??$exc2['break_minutes']??0));
                                } else { $theoryTotalMin+=intval($exc2['total_minutes']??0); }
                                $hasSchedule=true;
                            } else {
                                $schS2=$pdo->prepare("SELECT ss.cycle_type,ss.weekdays,ss.month_days,st.total_minutes FROM shift_schedule ss JOIN shift_type st ON st.shift_type_id=ss.shift_type_id WHERE ss.user_id=? AND ss.effective_from<=? AND (ss.effective_to IS NULL OR ss.effective_to>=?) AND st.is_active=1 ORDER BY ss.effective_from DESC LIMIT 5");
                                $schS2->execute([$wid2,$date2,$date2]);
                                $schs2=$schS2->fetchAll(PDO::FETCH_ASSOC);
                                foreach($schs2 as $sc2){
                                    if($sc2['cycle_type']==='weekly'){$wds2=array_filter(array_map('trim',explode(',',$sc2['weekdays']??'')));if(in_array($dow2,$wds2)||in_array(strval($dow2),$wds2)){$theoryTotalMin+=intval($sc2['total_minutes']??0);$hasSchedule=true;break;}}
                                    elseif($sc2['cycle_type']==='monthly'){$mds2=array_filter(array_map('trim',explode(',',$sc2['month_days']??'')));$dom2=intval(date('j',strtotime($date2)));if(in_array($dom2,$mds2)||in_array(strval($dom2),$mds2)){$theoryTotalMin+=intval($sc2['total_minutes']??0);$hasSchedule=true;break;}}
                                    elseif($sc2['cycle_type']==='range'){$theoryTotalMin+=intval($sc2['total_minutes']??0);$hasSchedule=true;break;}
                                }
                            }
                        }
                    }

                    $r['theory_hrs']  = $hasSchedule ? round($theoryTotalMin/60,2) : null;
                    $r['has_schedule']= $hasSchedule;
                    if ($theoryTotalMin > 0) {
                        $r['utilization'] = round(floatval($r['prod_hrs']) / ($theoryTotalMin/60) * 100, 1);
                    } else {
                        $r['utilization'] = null;
                    }
                    $r['vs_target'] = $r['utilization']!==null ? round($r['utilization']-$tgt,1) : null;
                    $r['target']    = $tgt;
                }

            } else {
                // 人員彙總：維持原邏輯
                $where .= " AND (pdr.production_start_time IS NOT NULL OR pdr.setup_start_time IS NOT NULL)";
                $sql = "SELECT u.id AS user_id, u.user_cname AS label,
                    MAX(COALESCE(d.name,'')) AS dept_name, MAX(COALESCE(pos.name,'')) AS pos_name,
                    COUNT(DISTINCT pdr.report_id) AS report_count,
                    SUM(pdr.produced_qty) AS total_ok,
                    COALESCE(SUM(ng.ng_sum),0) AS total_ng,
                    ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time))/3600,2) AS prod_hrs,
                    ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.setup_start_time,pdr.setup_end_time))/3600,2) AS setup_hrs,
                    COUNT(DISTINCT pdr.machine_id) AS machine_count
                    FROM pm_process_daily_report pdr
                    JOIN user u ON u.id=COALESCE(pdr.production_user_id,pdr.setup_user_id)
                    LEFT JOIN user_department_position_map udm ON udm.user_id=u.id AND udm.is_main=1
                    LEFT JOIN department d ON d.id=udm.department_id
                    LEFT JOIN position pos ON pos.id=udm.position_id
                    LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                    LEFT JOIN bom b ON b.bom=bi.bom
                    LEFT JOIN (SELECT n.report_id,SUM(n.ng_qty) AS ng_sum FROM pm_process_daily_ng n GROUP BY n.report_id) ng ON ng.report_id=pdr.report_id
                    WHERE $where GROUP BY u.id, u.user_cname ORDER BY total_ok DESC LIMIT 50";
                $st = $pdo->prepare($sql); $st->execute($p);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);

                $days = (int)((strtotime($dt)-strtotime($df))/86400)+1;
                $theory_hrs = $days * 8;
                foreach ($rows as &$r) {
                    $total = ($r['total_ok']??0)+($r['total_ng']??0);
                    $r['yield_rate']  = $total>0 ? round($r['total_ok']/$total*100,1) : null;
                    $r['utilization'] = $theory_hrs>0 ? round(($r['prod_hrs']??0)/$theory_hrs*100,1) : 0;
                    $r['vs_target']   = round($r['utilization']-$tgt,1);
                    $r['target']      = $tgt;
                }
            }

            echo json_encode(['success'=>true,'data'=>$rows,'target_util'=>$tgt]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── KPI 標準值 ────────────────────────────────────────────
    if ($action === 'get_kpi_settings') {
        try {
            // 讀取 KPI 標準值（排除 prod_dept_ids，因為該 key 已移至 system_parameters）
            $rows = $pdo->query("SELECT * FROM kpi_standard_setting WHERE setting_key != 'prod_dept_ids' ORDER BY setting_id")->fetchAll(PDO::FETCH_ASSOC);
            // 從 system_parameters 補上 prod_dept_ids（param_value 為 json 型別，可安全存 JSON 陣列）
            try {
                $pdVal = $pdo->query("SELECT param_value FROM system_parameters WHERE param_group='KPI' AND param_key='prod_dept_ids' LIMIT 1")->fetchColumn();
                if ($pdVal !== false) {
                    $rows[] = [
                        'setting_key'   => 'prod_dept_ids',
                        'setting_value' => $pdVal,
                        'setting_label' => '生產單位部門 IDs',
                        'setting_unit'  => 'JSON',
                        'description'   => '勾選的生產部門ID清單',
                    ];
                }
        } catch(Exception $e2) { /* system_parameters 不存在時忽略 */ }
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'save_kpi_settings') {
        $s = $_POST['settings'] ?? [];
        try {
            $stUpd = $pdo->prepare("UPDATE kpi_standard_setting SET setting_value=?,updated_by=? WHERE setting_key=?");
            $stIns = $pdo->prepare("INSERT IGNORE INTO kpi_standard_setting (setting_key,setting_value,setting_label,setting_unit,description,updated_by) VALUES (?,?,?,?,?,?)");
            foreach ($s as $k=>$v) {
                if ($k === 'prod_dept_ids') continue;
                $val = round(floatval($v), 2);
                $affected = $stUpd->execute([$val, $userId, $k]);
                // 若 UPDATE 沒有影響任何列（該 key 不存在），則 INSERT
                if ($stUpd->rowCount() === 0) {
                    $labels = [
                        'allowance_rate' => ['標準工時寬放率', '%', '計算效率前，標準工時×(1+寬放率%)'],
                    ];
                    $label = $labels[$k][0] ?? $k;
                    $unit  = $labels[$k][1] ?? '%';
                    $desc  = $labels[$k][2] ?? '';
                    $stIns->execute([$k, $val, $label, $unit, $desc, $userId]);
                }
            }
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 製程清單（含大類） ────────────────────────────────────
    if ($action === 'get_process_list') {
        try { echo json_encode(['success'=>true,'data'=>$pdo->query(
            "SELECT pn.ProcessNo, pn.ProcessName, pn.process_type_id, pt.process_type
             FROM process_no pn LEFT JOIN process_type pt ON pt.process_type_id=pn.process_type_id
             ORDER BY pn.process_type_id, pn.ProcessNo"
        )->fetchAll(PDO::FETCH_ASSOC)]); }
        catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 標籤字典（供公式建立器選用） ─────────────────────────
    if ($action === 'get_labels') {
        try {
            // Include labels with direct numeric value OR with numeric sub-labels
            $lblSt = $pdo->prepare(
                "SELECT DISTINCT dl.label_id, dl.label_name, dl.input_type, dl.sort_order,
                 COALESCE(dl.is_dimension,0) AS is_dimension,
                 COALESCE(dl.is_qty_dim,0) AS is_qty_dim,
                 COALESCE(dl.is_repeatable,0) AS is_repeatable
                 FROM dict_label dl
                 WHERE dl.is_active=1 AND COALESCE(dl.is_exclude_calc,0)=0
                   AND (
                       dl.input_type IN ('number','text')
                       OR EXISTS (SELECT 1 FROM dict_label_sub ds WHERE ds.label_id=dl.label_id AND ds.input_type IN ('number','text'))
                   )
                 ORDER BY dl.sort_order, dl.label_id"
            );
            $lblSt->execute();
            $labels = $lblSt->fetchAll(PDO::FETCH_ASSOC);
            // Load sub-labels
            $labelIds = array_column($labels, 'label_id');
            $subsMap = [];
            if (!empty($labelIds)) {
                $inIds = implode(',', array_map('intval', $labelIds));
                $subSt = $pdo->prepare(
                    "SELECT sub_id, label_id, sub_name, input_type,
                     COALESCE(is_dimension,0) AS is_dimension, COALESCE(is_qty_dim,0) AS is_qty_dim
                     FROM dict_label_sub
                     WHERE label_id IN ($inIds) AND input_type IN ('number','text') ORDER BY sub_id"
                );
                $subSt->execute();
                foreach ($subSt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    $subsMap[intval($s['label_id'])][] = $s;
                }
            }
            foreach ($labels as &$lbl) { $lbl['subs'] = $subsMap[intval($lbl['label_id'])] ?? []; }
            echo json_encode(['success'=>true,'data'=>$labels]);
        }
        catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 全部標籤（供重量規則選用，不限 input_type） ──────────
    if ($action === 'get_all_labels') {
        try {
            $lblSt = $pdo->query(
                "SELECT dl.label_id, dl.label_name, dl.input_type, dl.sort_order
                 FROM dict_label dl
                 WHERE dl.is_active=1
                 ORDER BY dl.sort_order, dl.label_id"
            );
            $labels = $lblSt->fetchAll(PDO::FETCH_ASSOC);
            $labelIds = array_column($labels, 'label_id');
            $subsMap = [];
            if (!empty($labelIds)) {
                $inIds = implode(',', array_map('intval', $labelIds));
                $subSt = $pdo->query(
                    "SELECT sub_id, label_id, sub_name, input_type,
                     COALESCE(is_range,0) AS is_range,
                     COALESCE(is_dimension,0) AS is_dimension,
                     COALESCE(is_triple_dim,0) AS is_triple_dim,
                     COALESCE(prefix_char,'') AS prefix_char,
                     COALESCE(suffix_char,'') AS suffix_char
                     FROM dict_label_sub WHERE label_id IN ($inIds) ORDER BY sort_order, sub_id"
                );
                foreach ($subSt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    $subsMap[intval($s['label_id'])][] = $s;
                }
            }
            foreach ($labels as &$lbl) { $lbl['subs'] = $subsMap[intval($lbl['label_id'])] ?? []; }
            echo json_encode(['success'=>true,'data'=>$labels]);
        }
        catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得群組公式 ─────────────────────────────────────────
    if ($action === 'get_group_formula') {
        $gid = intval($_POST['group_id'] ?? 0);
        try {
            $fqSt = $pdo->prepare("SELECT formula_expr, var_config FROM kpi_group_formula WHERE group_id=? AND is_active=1 LIMIT 1");
            if (!($fqSt instanceof PDOStatement)) throw new Exception('prepare error');
            $fqSt->execute([$gid]);
            $row = $fqSt->fetch(PDO::FETCH_ASSOC) ?: null;
            echo json_encode(['success'=>true,'data'=>$row ?: null]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 儲存群組公式 ─────────────────────────────────────────
    if ($action === 'save_group_formula') {
        $gid  = intval($_POST['group_id'] ?? 0);
        $expr = trim($_POST['formula_expr'] ?? '');
        $vars = trim($_POST['var_config'] ?? '[]');
        // validate JSON
        $varArr = json_decode($vars, true);
        if (!is_array($varArr)) $varArr = [];
        try {
            if ($expr === '') {
                $pdo->prepare("DELETE FROM kpi_group_formula WHERE group_id=?")->execute([$gid]);
            } else {
                $pdo->prepare("INSERT INTO kpi_group_formula (group_id,formula_expr,var_config,updated_by) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE formula_expr=VALUES(formula_expr),var_config=VALUES(var_config),updated_by=VALUES(updated_by)")
                    ->execute([$gid, $expr, json_encode($varArr), $userId]);
            }
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 生產部門設定 讀取 ──────────────────────────────────────
    if ($action === 'get_prod_dept_setting') {
        try {
            // 生產部門一律讀全站「組織角色綁定設定」的 prod_dept（含子部門），本頁不再自設一份（2026-08-03）；
            // 舊 system_parameters KPI/prod_dept_ids 只在統一設定未綁定時當回退值。
            require_once __DIR__ . '/../../src/common/org_role_lib.php';
            $ids = eg_org_dept_ids($pdo, 'prod_dept');
            if (!$ids) {
                $val = $pdo->query("SELECT param_value FROM system_parameters WHERE param_group='KPI' AND param_key='prod_dept_ids' LIMIT 1")->fetchColumn();
                if ($val !== false) {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) $ids = $decoded;
                }
            }
            echo json_encode(['success'=>true,'ids'=>$ids,'unified'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage(),'ids'=>[]]); }
        exit;
    }

    // ── 生產部門設定 upsert ──────────────────────────────────
    // 注意：prod_dept_ids 為 JSON 陣列，不可存入 kpi_standard_setting.setting_value（decimal 型別）
    // 改存至 system_parameters（param_value 為 json 型別，updated_by 為 varchar(50)）
    if ($action === 'upsert_prod_dept_setting') {
        // 2026-08-03 起生產部門統一在「組織角色綁定設定」維護，本端點停用（避免兩處設定打架）
        echo json_encode(['success'=>false,'message'=>'生產單位已改由「組織角色綁定設定」統一維護，請至該頁設定「生產部門」']);
        exit;
        $ids = trim($_POST['ids'] ?? '[]');
        $updatedBy = strval($userId); // system_parameters.updated_by 是 varchar(50)
        try {
            $pdo->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by)
                VALUES ('KPI','prod_dept_ids',?,'勾選的生產部門ID清單',?)
                ON DUPLICATE KEY UPDATE param_value=VALUES(param_value),updated_by=VALUES(updated_by)")
                ->execute([$ids, $updatedBy]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 人員報工彙總（每人一列，加權平均效率） ────────────────
    if ($action === 'get_kpi_user_agg') {
        $df   = $_POST['date_from'] ?? date('Y-m-01');
        $dt   = $_POST['date_to']   ?? date('Y-m-d');
        $uid  = intval($_POST['user_id']  ?? 0);
        $did  = intval($_POST['dept_id']  ?? 0);
        $mtid = intval($_POST['machine_type_id'] ?? 0);
        $partno = trim($_POST['part_no'] ?? '');
        try {
            $where = "pdr.report_date BETWEEN ? AND ? AND (pdr.production_start_time IS NOT NULL OR pdr.setup_start_time IS NOT NULL)";
            $p = [$df,$dt];
            if ($uid)    { $where .= " AND u.id=?"; $p[]=$uid; }
            if ($did)    { $where .= " AND udm.department_id=?"; $p[]=$did; }
            if ($mtid)   { $where .= " AND ml.machine_type_id=?"; $p[]=$mtid; }
            if ($partno) { $where .= " AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)"; $p[]="%$partno%"; }

            // GROUP BY 含所有非聚合欄位，避免 only_full_group_by 錯誤
            $sql = "SELECT
                u.id AS user_id,
                u.user_cname AS label,
                MAX(COALESCE(d.name,'')) AS dept_name,
                MAX(COALESCE(pos.name,'')) AS pos_name,
                COUNT(DISTINCT pdr.report_id) AS report_count,
                SUM(pdr.produced_qty) AS total_ok,
                COALESCE(SUM(ng.ng_sum),0) AS total_ng,
                ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time))/3600,4) AS prod_hrs,
                ROUND(SUM(TIMESTAMPDIFF(SECOND,pdr.setup_start_time,pdr.setup_end_time))/3600,4) AS setup_hrs,
                COUNT(DISTINCT pdr.machine_id) AS machine_count,
                COUNT(DISTINCT pdr.report_date) AS work_days
                FROM pm_process_daily_report pdr
                JOIN user u ON u.id=COALESCE(pdr.production_user_id,pdr.setup_user_id)
                LEFT JOIN machine_list ml ON ml.machine_id=pdr.machine_id
                LEFT JOIN user_department_position_map udm ON udm.user_id=u.id AND udm.is_main=1
                LEFT JOIN department d ON d.id=udm.department_id
                LEFT JOIN position pos ON pos.id=udm.position_id
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN (SELECT n.report_id,SUM(n.ng_qty) AS ng_sum FROM pm_process_daily_ng n GROUP BY n.report_id) ng ON ng.report_id=pdr.report_id
                WHERE $where
                GROUP BY u.id, u.user_cname
                ORDER BY total_ok DESC LIMIT 100";
            $st=$pdo->prepare($sql);$st->execute($p);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);

            // 讀取 KPI 設定
            try { $apRow=$pdo->query("SELECT setting_value FROM kpi_standard_setting WHERE setting_key='time_diff_alert_pct' LIMIT 1")->fetchColumn(); $alert_pct=($apRow!==false&&is_numeric($apRow))?floatval($apRow):20; } catch(Exception $e2){$alert_pct=20;}

            // 對每位人員計算標準工時（需要逐筆報工計算後加總）
            foreach ($rows as &$r) {
                $total=($r['total_ok']??0)+($r['total_ng']??0);
                $r['yield_rate']=$total>0?round($r['total_ok']/$total*100,1):null;

                // 取此人此區間所有報工，計算總標準工時
                $detWhere = "(pdr.production_user_id=? OR pdr.setup_user_id=?) AND pdr.report_date BETWEEN ? AND ? AND pdr.production_start_time IS NOT NULL";
                $detP = [$r['user_id'],$r['user_id'],$df,$dt];
                if ($partno) {
                    $detWhere .= " AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)";
                    $detP[] = "%$partno%";
                }
                $detStmt=$pdo->prepare(
                    "SELECT pdr.process_no, pdr.produced_qty,
                     TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time) AS prod_sec,
                     b.d_id AS part_no,
                     ds.d_id AS d_setting_id, ds.Type AS part_type,
                     dsg.Module, dsg.Teeth, dsg.Face_Width
                     FROM pm_process_daily_report pdr
                     LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                     LEFT JOIN bom b ON b.bom=bi.bom
                     LEFT JOIN d_setting ds ON ds.D_Setting_Id=b.d_id
                     LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id=ds.d_id
                     WHERE (pdr.production_user_id=? OR pdr.setup_user_id=?)
                       AND pdr.report_date BETWEEN ? AND ? AND pdr.production_start_time IS NOT NULL " . ($partno ? " AND EXISTS (SELECT 1 FROM bom_ing bi_pn2 JOIN bom b_pn2 ON b_pn2.bom=bi_pn2.bom WHERE bi_pn2.bom_ing_fid=pdr.bom_ing_fid AND b_pn2.d_id LIKE ?)" : "")
                );
                $detStmt->execute($detP);
                $details=$detStmt->fetchAll(PDO::FETCH_ASSOC);
                $totalStdSec=0; $totalActSec=0;
                foreach ($details as $det) {
                    $qty=intval($det['produced_qty']); $actSec=floatval($det['prod_sec']);
                    // 取製程群組設定 (加入 kps.std_id 以判斷是否為個別設定)
                    $kpsStmt=$pdo->prepare("SELECT COALESCE(kps.coefficient,gd.default_coefficient,1.0) AS coeff, 
                        COALESCE(kps.base_time_sec,std.base_time_sec) AS base_t, 
                        kps.multiplier, kps.std_id
                        FROM kpi_process_group_map pgm 
                        LEFT JOIN kpi_part_standard kps ON kps.group_id=pgm.group_id AND kps.d_setting_id=? 
                        LEFT JOIN kpi_difficulty_default gd ON gd.group_id=pgm.group_id 
                        LEFT JOIN kpi_std_time_default std ON std.group_id=pgm.group_id 
                        WHERE pgm.process_no=? LIMIT 1");
                    $kpsStmt->execute([$det['d_setting_id'],$det['process_no']]);
                    $kps=$kpsStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$kps) continue;
                    $coeff=floatval($kps['coeff']??1); $baseT=floatval($kps['base_t']??0); $multi=floatval($kps['multiplier']??1);
                    if (!empty($kps['std_id'])) {
                        $stdSec = $baseT * $coeff * $multi * $qty;
                    } else {
                        if ($det['part_type']==='G'&&floatval($det['Module'])>0)
                            $stdSec=$baseT*floatval($det['Module'])*floatval($det['Teeth'])*floatval($det['Face_Width'])*$coeff*$qty;
                        else
                            $stdSec=$baseT*$coeff*$multi*$qty;
                    }
                    $totalStdSec+=$stdSec; $totalActSec+=$actSec;
                }
                $r['total_std_hrs'] = $totalStdSec>0?round($totalStdSec/3600,2):null;
                $r['efficiency']    = ($totalStdSec>0&&$totalActSec>0)?round($totalStdSec/$totalActSec*100,1):null;
                $r['avg_min_per_pc']= ($r['total_ok']>0&&floatval($r['prod_hrs'])>0)?round(floatval($r['prod_hrs'])*60/$r['total_ok'],2):null;
            }
            echo json_encode(['success'=>true,'data'=>$rows,'alert_pct'=>$alert_pct]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 人員報工展開明細（指定人員逐筆） ─────────────────────
    if ($action === 'get_kpi_user_detail_expand') {
        set_time_limit(60); // 給予充裕執行時間，避免大日期範圍查詢逾時
        $df  = $_POST['date_from'] ?? date('Y-m-01');
        $dt  = $_POST['date_to']   ?? date('Y-m-d');
        $uid = intval($_POST['user_id'] ?? 0);
        $mid = intval($_POST['machine_id'] ?? 0);
        $partno = trim($_POST['part_no'] ?? '');
        $page= max(1,intval($_POST['page']??1));
        $pp  = 10; $off=($page-1)*$pp;
        if (!$uid && !$mid) { echo json_encode(['success'=>false,'message'=>'需指定人員或機台']); exit; }
        try {
            $apRow=$pdo->query("SELECT setting_value FROM kpi_standard_setting WHERE setting_key='time_diff_alert_pct' LIMIT 1")->fetchColumn();
            $alert_pct=($apRow!==false&&is_numeric($apRow))?floatval($apRow):20;
            // 讀取標準工時寬放率（%）
            try { $arRow=$pdo->query("SELECT setting_value FROM kpi_standard_setting WHERE setting_key='allowance_rate' LIMIT 1")->fetchColumn(); $allowance_rate=($arRow!==false&&is_numeric($arRow))?floatval($arRow)/100:0; } catch(Exception $ex){$allowance_rate=0;}

            // ✅ 先批次預載所有 KPI 標準對應表，避免主查詢內 N 個 correlated subquery
            $expandKpsMap = [];
            try {
                $expandKpsSt = $pdo->prepare(
                    "SELECT pgm.process_no,
                        COALESCE(kps.d_setting_id, 0) AS ds_id,
                        kps.coefficient,
                        kps.base_time_sec  AS part_base_t,
                        kps.base_price     AS part_base_p,
                        kps.multiplier,
                        kps.std_id         AS has_custom_std,
                        pgm.group_id,
                        std.base_time_sec  AS grp_base_t,
                        std.base_price     AS grp_base_p,
                        std.fixed_price_per_pcs AS fixed_price,
                        COALESCE(kd.default_coefficient, 1.0) AS grp_def_coeff
                     FROM kpi_process_group_map pgm
                     LEFT JOIN kpi_part_standard kps ON kps.group_id = pgm.group_id
                     LEFT JOIN kpi_std_time_default std ON std.group_id = pgm.group_id
                     LEFT JOIN kpi_difficulty_default kd ON kd.group_id = pgm.group_id
                     ORDER BY pgm.process_no, kps.d_setting_id DESC"
                );
                $expandKpsSt->execute();
                $expandKpsRows = $expandKpsSt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($expandKpsRows as $kr) {
                    $k2 = $kr['process_no'] . '_' . $kr['ds_id'];
                    if (!isset($expandKpsMap[$k2])) $expandKpsMap[$k2] = $kr;
                    $dk = $kr['process_no'] . '_0';
                    if (!isset($expandKpsMap[$dk])) $expandKpsMap[$dk] = $kr;
                }
            } catch(Exception $e2) {}

            // 主查詢：移除所有 correlated subquery，改為乾淨的 JOIN
            $sql="SELECT pdr.report_id, pdr.report_date, pdr.process_no, pdr.remark,
                ml.machine, pdr.produced_qty,
                COALESCE(u1.user_cname,'—') AS prod_user,
                COALESCE(u2.user_cname,'—') AS setup_user,
                ROUND(TIMESTAMPDIFF(SECOND,pdr.production_start_time,pdr.production_end_time)/3600,4) AS prod_hrs,
                ROUND(TIMESTAMPDIFF(SECOND,pdr.setup_start_time,pdr.setup_end_time)/3600,4) AS setup_hrs,
                pdr.production_start_time, pdr.production_end_time,
                pdr.setup_start_time, pdr.setup_end_time,
                (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id=pdr.report_id) AS ng_qty,
                b.d_id AS part_no, b.bom AS bom_no, pn.ProcessName, pn.process_type_id,
                ds.d_id AS d_setting_id, ds.Type AS part_type,
                dsg.Module, dsg.Teeth, dsg.Face_Width
                FROM pm_process_daily_report pdr
                LEFT JOIN machine_list ml ON ml.machine_id=pdr.machine_id
                LEFT JOIN user u1 ON u1.id=pdr.production_user_id
                LEFT JOIN user u2 ON u2.id=pdr.setup_user_id
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN d_setting ds ON ds.D_Setting_Id=b.d_id
                LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id=ds.d_id
                LEFT JOIN process_no pn ON pn.ProcessNo=pdr.process_no
                WHERE 1=1 AND pdr.report_date BETWEEN ? AND ? AND (pdr.production_start_time IS NOT NULL OR pdr.setup_start_time IS NOT NULL)";
            $p_vals = [$df, $dt];
            if ($uid) {
                $sql .= " AND (pdr.production_user_id=? OR pdr.setup_user_id=?)"; array_push($p_vals, $uid, $uid);
            }
            if ($mid) { $sql .= " AND pdr.machine_id=?"; $p_vals[] = $mid; }
            if ($partno) {
                $sql .= " AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)"; $p_vals[] = "%$partno%";
            }
            $sql .= " ORDER BY pdr.report_date DESC, pdr.report_id DESC
                LIMIT ? OFFSET ?";
            $st=$pdo->prepare($sql);
            foreach ($p_vals as $i => $v) { $st->bindValue($i+1, $v); }
            $st->bindValue(count($p_vals)+1, $pp, PDO::PARAM_INT);
            $st->bindValue(count($p_vals)+2, $off, PDO::PARAM_INT);
            $st->execute();
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);

            // ── 批次查詢歷史平均工時快取（避免 N+1 查詢）──────────
            // 先收集所有需要的 (d_setting_id, process_type_id) 組合
            $cacheKeyPairs = [];
            foreach ($rows as $r) {
                $dsId = intval($r['d_setting_id'] ?? 0);
                $ptId = intval($r['process_type_id'] ?? 0);
                if ($dsId > 0 && $ptId > 0) {
                    $cacheKeyPairs[$dsId.'_'.$ptId] = [$dsId, $ptId];
                }
            }
            // 一次撈出所有快取紀錄（含最短/最長工時）
            $cacheMap = [];
            if (!empty($cacheKeyPairs)) {
                $inParts = implode(',', array_map(function($pair) {
                    return '(' . $pair[0] . ',' . $pair[1] . ')';
                }, $cacheKeyPairs));
                try {
                    $cacheRows = $pdo->query(
                        "SELECT d_setting_id, process_type_id, avg_min_per_pc, sample_count,
                                min_min_per_pc, max_min_per_pc
                         FROM kpi_avg_time_cache
                         WHERE (d_setting_id, process_type_id) IN ($inParts)"
                    )->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($cacheRows as $c) {
                        $cacheMap[intval($c['d_setting_id']).'_'.intval($c['process_type_id'])] = $c;
                    }
                } catch(Exception $e2) {
                    // 若欄位不存在，降級查詢（不含 min/max）
                    try {
                        $cacheRows2 = $pdo->query(
                            "SELECT d_setting_id, process_type_id, avg_min_per_pc, sample_count
                             FROM kpi_avg_time_cache
                             WHERE (d_setting_id, process_type_id) IN ($inParts)"
                        )->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($cacheRows2 as $c) {
                            $cacheMap[intval($c['d_setting_id']).'_'.intval($c['process_type_id'])] = $c;
                        }
                    } catch(Exception $e3) { /* 快取表不存在時忽略 */ }
                }
            }

            // 歷史平均工時（從批次快取 Map 取值，不再逐筆查 DB）
            foreach ($rows as &$r) {
                $qty = intval($r['produced_qty']);
                $ng  = intval($r['ng_qty']);
                $dsId3 = intval($r['d_setting_id'] ?? 0);
                $procNo3 = $r['process_no'] ?? '';

                // 從批次 KPS Map 取標準設定
                // 精確匹配 → 有個別設定；只有 fallback _0 → 套用群組預設
                $kp3exact = $expandKpsMap[$procNo3.'_'.$dsId3] ?? null;
                $kp3def   = $expandKpsMap[$procNo3.'_0'] ?? null;
                $kp3      = $kp3exact ?? $kp3def;
                $coeff   = floatval($kp3['coefficient']   ?? $kp3['grp_def_coeff'] ?? 1.0);
                $multi   = floatval($kp3['multiplier']    ?? 1.0);
                $base_p  = floatval($kp3['part_base_p']   ?? $kp3['grp_base_p'] ?? 0);
                $r['coefficient']    = $kp3['coefficient']    ?? null;
                $r['part_base_t']    = $kp3['part_base_t']    ?? null;
                $r['part_base_p']    = $kp3['part_base_p']    ?? null;
                $r['multiplier']     = $kp3['multiplier']     ?? null;
                $r['grp_base_t']     = $kp3['grp_base_t']     ?? null;
                $r['grp_def_coeff']  = $kp3['grp_def_coeff']  ?? 1.0;
                // has_custom_std 只在精確匹配到該料號且 std_id 不為 null 時才為 true
                $r['has_custom_std'] = ($kp3exact && !empty($kp3exact['has_custom_std'])) ? $kp3exact['has_custom_std'] : null;
                $r['group_id']       = $kp3['group_id']       ?? null;

                if (!empty($kp3['part_base_t'])) {
                    $stdSec = floatval($kp3['part_base_t']) * $coeff * $multi * $qty;
                } else {
                    $baseT = floatval($kp3['grp_base_t'] ?? 0);
                    if (($r['part_type'] ?? '') === 'G' && floatval($r['Module'] ?? 0) > 0)
                        $stdSec = $baseT * floatval($r['Module']) * floatval($r['Teeth']) * floatval($r['Face_Width']) * $coeff * $qty;
                    else
                        $stdSec = $baseT * $coeff * $multi * $qty;
                }

                $actSec = floatval($r['prod_hrs']) * 3600;
                // 寬放後標準工時 = stdSec × (1 + allowance_rate)
                $stdSecAllowed = $stdSec * (1 + $allowance_rate);
                $r['std_hrs']      = $stdSecAllowed > 0 ? round($stdSecAllowed / 3600, 2) : null;
                $r['std_hrs_pure'] = $stdSec > 0 ? round($stdSec / 3600, 2) : null;
                $r['allowance_pct'] = $allowance_rate > 0 ? round($allowance_rate * 100) : 0;
                $r['efficiency']   = ($stdSecAllowed > 0 && $actSec > 0) ? round($stdSecAllowed / $actSec * 100, 1) : null;
                $total3            = $qty + $ng;
                $r['yield_rate']   = $total3 > 0 ? round($qty / $total3 * 100, 1) : null;
                $r['avg_min_per_pc'] = ($qty > 0 && floatval($r['prod_hrs']) > 0) ? round(floatval($r['prod_hrs']) * 60 / $qty, 2) : null;
                $fixedP = floatval($kp3['fixed_price'] ?? 0);
                $r['amount'] = $fixedP > 0 ? $fixedP * $qty : ($base_p > 0 ? ($stdSec * $base_p) : 0);
                $r['time_alert']   = ($actSec > 0 && $stdSecAllowed > 0) ? (abs($actSec - $stdSecAllowed) / $stdSecAllowed * 100 > $alert_pct) : false;

                // 生產/架機時段
                $r['prod_time_range'] = '';
                if (!empty($r['production_start_time'])) {
                    $ps = substr($r['production_start_time'], 11, 5);
                    $pe = !empty($r['production_end_time']) ? substr($r['production_end_time'], 11, 5) : '未完';
                    $r['prod_time_range'] = $ps . '~' . $pe;
                }
                $r['setup_time_range'] = '';
                if (!empty($r['setup_start_time'])) {
                    $ss = substr($r['setup_start_time'], 11, 5);
                    $se = !empty($r['setup_end_time']) ? substr($r['setup_end_time'], 11, 5) : '未完';
                    $r['setup_time_range'] = $ss . '~' . $se;
                }

                // 歷史平均工時（從批次快取 Map 取值）
                $cacheKey2 = intval($r['d_setting_id']) . '_' . intval($r['process_type_id']);
                $cache     = $cacheMap[$cacheKey2] ?? null;
                $r['hist_avg_min'] = $cache ? floatval($cache['avg_min_per_pc']) : null;
                $r['hist_sample']  = $cache ? intval($cache['sample_count'])     : 0;
                $r['hist_min_min'] = ($cache && isset($cache['min_min_per_pc'])) ? floatval($cache['min_min_per_pc']) : null;
                $r['hist_max_min'] = ($cache && isset($cache['max_min_per_pc'])) ? floatval($cache['max_min_per_pc']) : null;
            }
            $cntSql = "SELECT COUNT(*) FROM pm_process_daily_report pdr LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid LEFT JOIN bom b ON b.bom=bi.bom WHERE 1=1 AND pdr.report_date BETWEEN ? AND ? AND (pdr.production_start_time IS NOT NULL OR pdr.setup_start_time IS NOT NULL)";
            $cntP = [$df, $dt];
            if ($uid) { $cntSql .= " AND (pdr.production_user_id=? OR pdr.setup_user_id=?)"; array_push($cntP, $uid, $uid); }
            if ($mid) { $cntSql .= " AND pdr.machine_id=?"; $cntP[] = $mid; }
            if ($partno) {
                $cntSql .= " AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)"; $cntP[] = "%$partno%";
            }
            $cnt=$pdo->prepare($cntSql);
            $cnt->execute($cntP);
            echo json_encode(['success'=>true,'data'=>$rows,'total'=>$cnt->fetchColumn(),'page'=>$page,'per_page'=>$pp,'alert_pct'=>$alert_pct]);
        } catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 生產金額查詢 ──────────────────────────────────────────
    if ($action === 'get_production_amount') {
        $df   = $_POST['date_from'] ?? date('Y-m-01');
        $dt   = $_POST['date_to']   ?? date('Y-m-d');
        $view = $_POST['view'] ?? 'user'; // user / machine / machine_type / area
        $uid  = intval($_POST['user_id']??0);
        $mid  = intval($_POST['machine_id']??0);
        $mtid = intval($_POST['machine_type_id']??0);
        $did  = intval($_POST['dept_id']??0);
        $partno = trim($_POST['part_no']??'');
        try {
            $where="pdr.report_date BETWEEN ? AND ? AND pdr.production_start_time IS NOT NULL AND pdr.produced_qty>0";
            $p=[$df,$dt];
            if($uid){$where.=" AND pdr.production_user_id=?";$p[]=$uid;}
            if($mid){$where.=" AND pdr.machine_id=?";$p[]=$mid;}
            if($mtid){$where.=" AND ml.machine_type_id=?";$p[]=$mtid;}
            if($did){$where.=" AND udm2.department_id=?";$p[]=$did;}
            if($partno){$where.=" AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)";$p[]="%$partno%";}

            $sql="SELECT pdr.report_id, pdr.report_date, pdr.produced_qty, pdr.process_no,
                pdr.production_user_id, u.user_cname,
                ml.machine_id, ml.machine, ml.machine_type_id, ml.position AS area_id,
                pt.process_type AS machine_type,
                sa.area_name,
                b.d_id AS part_no, b.bom AS bom_no, ds.d_id AS d_setting_id, ds.Type AS part_type,
                dsg.Module, dsg.Teeth, dsg.Face_Width,
                pn.process_type_id
                FROM pm_process_daily_report pdr
                JOIN machine_list ml ON ml.machine_id=pdr.machine_id
                LEFT JOIN process_type pt ON pt.process_type_id=ml.machine_type_id
                LEFT JOIN stock_areas sa ON sa.area_id=ml.position AND sa.is_active=1
                LEFT JOIN user u ON u.id=pdr.production_user_id
                LEFT JOIN user_department_position_map udm2 ON udm2.user_id=pdr.production_user_id AND udm2.is_main=1
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN d_setting ds ON ds.D_Setting_Id=b.d_id
                LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id=ds.d_id
                LEFT JOIN process_no pn ON pn.ProcessNo=pdr.process_no
                WHERE $where";
            $st=$pdo->prepare($sql);$st->execute($p);
            $allRows=$st->fetchAll(PDO::FETCH_ASSOC);

            // 批次查詢 KPI 標準（避免 N+1）
            $kpsMap=[];
            if(!empty($allRows)){
                $kpsSql="SELECT pgm.process_no, pgm.group_id,
                    COALESCE(kps.d_setting_id,0) AS ds_id,
                    COALESCE(kps.coefficient,gd.default_coefficient,1.0) AS coeff,
                    COALESCE(kps.base_time_sec,std.base_time_sec) AS base_t,
                    COALESCE(kps.base_price,std.base_price) AS base_p,
                    kps.multiplier,
                    std.fixed_price_per_pcs AS fixed_price
                    FROM kpi_process_group_map pgm
                    LEFT JOIN kpi_part_standard kps ON kps.group_id=pgm.group_id
                    LEFT JOIN kpi_difficulty_default gd ON gd.group_id=pgm.group_id
                    LEFT JOIN kpi_std_time_default std ON std.group_id=pgm.group_id
                    ORDER BY pgm.process_no, kps.d_setting_id DESC";
                $kpsSt=$pdo->prepare($kpsSql);$kpsSt->execute();
                $kpsRows=$kpsSt->fetchAll(PDO::FETCH_ASSOC);
                foreach($kpsRows as $kr){
                    $key=$kr['process_no'].'_'.$kr['ds_id'];
                    if(!isset($kpsMap[$key]))$kpsMap[$key]=$kr;
                    // 也存 process_no 預設（ds_id=0）
                    $dk=$kr['process_no'].'_0';
                    if(!isset($kpsMap[$dk]))$kpsMap[$dk]=$kr;
                }
            }

            // 計算每筆金額，再依維度聚合
            $aggData=[];
            foreach($allRows as $r){
                $qty=intval($r['produced_qty']);
                $dsId=intval($r['d_setting_id']??0);
                $procNo=$r['process_no'];

                // 取 KPI 設定（先找料號個別，再找預設）
                $kps=$kpsMap[$procNo.'_'.$dsId]??$kpsMap[$procNo.'_0']??null;
                if(!$kps){$amount=0;}else{
                    $fpA=floatval($kps['fixed_price']??0);
                    $coeff=floatval($kps['coeff']??1);
                    $baseT=floatval($kps['base_t']??0);
                    $baseP=floatval($kps['base_p']??0);
                    $multi=floatval($kps['multiplier']??1);
                    if($fpA>0){
                        $amount=$fpA*$qty;
                    } elseif($kps['ds_id'] > 0) {
                        $amount = $baseT * $coeff * $multi * $baseP * $qty;
                    } else {
                        if($r['part_type']==='G'&&floatval($r['Module']??0)>0)
                            $amount=$baseT*floatval($r['Module'])*floatval($r['Teeth'])*floatval($r['Face_Width'])*$coeff*$baseP*$qty;
                        else
                            $amount=$baseT*$coeff*$multi*$baseP*$qty;
                    }
                }

                switch($view){
                    case 'machine':      $key=$r['machine_id'];    $label=$r['machine']??'—';     $sub=$r['machine_type']??''; break;
                    case 'machine_type': $key=$r['machine_type_id'];$label=$r['machine_type']??'—';$sub=''; break;
                    case 'area':         $key=$r['area_id']??0;    $label=$r['area_name']??'未分廠別';$sub=''; break;
                    default:             $key=$r['production_user_id']??0;$label=$r['user_cname']??'—';$sub=''; break;
                }
                if(!isset($aggData[$key])){$aggData[$key]=['key'=>$key,'label'=>$label,'sub'=>$sub,'amount'=>0,'qty'=>0,'report_count'=>0,'dates'=>[]];}
                $aggData[$key]['amount']+=$amount;
                $aggData[$key]['qty']+=$qty;
                $aggData[$key]['report_count']++;
                $aggData[$key]['dates'][$r['report_date']]=true;
            }
            $result=array_values($aggData);
            foreach($result as &$row){$row['work_days']=count($row['dates']);unset($row['dates']);}
            usort($result,function($a,$b){return $b['amount']<=>$a['amount'];});
            echo json_encode(['success'=>true,'data'=>$result,'view'=>$view]);
        } catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 生產金額雙擊展開明細 ──────────────────────────────────
    if ($action === 'get_production_amount_detail') {
        $df   = $_POST['date_from'] ?? date('Y-m-01');
        $dt   = $_POST['date_to']   ?? date('Y-m-d');
        $view = $_POST['view'] ?? 'user';
        $key  = $_POST['key'] ?? '';
        $partno = trim($_POST['part_no'] ?? '');
        try {
            $where="pdr.report_date BETWEEN ? AND ? AND pdr.production_start_time IS NOT NULL AND pdr.produced_qty>0";
            $p=[$df,$dt];
            if ($partno) {
                $where .= " AND EXISTS (SELECT 1 FROM bom_ing bi_pn JOIN bom b_pn ON b_pn.bom=bi_pn.bom WHERE bi_pn.bom_ing_fid=pdr.bom_ing_fid AND b_pn.d_id LIKE ?)";
                $p[] = "%$partno%";
            }
            switch($view){
                case 'machine':      $where.=" AND pdr.machine_id=?"; $p[]=intval($key); break;
                case 'machine_type': $where.=" AND ml.machine_type_id=?"; $p[]=intval($key); break;
                case 'area':         $where.=" AND ml.position=?"; $p[]=intval($key); break;
                default:             $where.=" AND pdr.production_user_id=?"; $p[]=intval($key); break;
            }
            $sql="SELECT pdr.report_date, pdr.produced_qty, pdr.process_no,
                u.user_cname, ml.machine, b.d_id AS part_no, b.bom AS bom_no,
                ds.d_id AS d_setting_id, ds.Type AS part_type,
                dsg.Module, dsg.Teeth, dsg.Face_Width,
                pn.ProcessName, pn.process_type_id,
                (SELECT kps2.std_id FROM kpi_process_group_map pgm2 LEFT JOIN kpi_part_standard kps2 ON kps2.group_id=pgm2.group_id AND kps2.d_setting_id=ds.d_id WHERE pgm2.process_no=pdr.process_no LIMIT 1) AS has_custom_std,
                (SELECT pgm3.group_id FROM kpi_process_group_map pgm3 WHERE pgm3.process_no=pdr.process_no LIMIT 1) AS group_id
                FROM pm_process_daily_report pdr
                JOIN machine_list ml ON ml.machine_id=pdr.machine_id
                LEFT JOIN user u ON u.id=pdr.production_user_id
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=pdr.bom_ing_fid
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN d_setting ds ON ds.D_Setting_Id=b.d_id
                LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id=ds.d_id
                LEFT JOIN process_no pn ON pn.ProcessNo=pdr.process_no
                WHERE $where ORDER BY pdr.report_date DESC LIMIT 50";
            $st=$pdo->prepare($sql);$st->execute($p);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);

            // 計算每筆金額
            $kpsRows=$pdo->query("SELECT pgm.process_no,pgm.group_id,COALESCE(kps.d_setting_id,0) AS ds_id,COALESCE(kps.coefficient,gd.default_coefficient,1.0) AS coeff,COALESCE(kps.base_time_sec,std.base_time_sec) AS base_t,COALESCE(kps.base_price,std.base_price) AS base_p,kps.multiplier FROM kpi_process_group_map pgm LEFT JOIN kpi_part_standard kps ON kps.group_id=pgm.group_id LEFT JOIN kpi_difficulty_default gd ON gd.group_id=pgm.group_id LEFT JOIN kpi_std_time_default std ON std.group_id=pgm.group_id ORDER BY pgm.process_no,kps.d_setting_id DESC")->fetchAll(PDO::FETCH_ASSOC);
            $kpsMap=[];
            foreach($kpsRows as $kr){$k2=$kr['process_no'].'_'.$kr['ds_id'];if(!isset($kpsMap[$k2]))$kpsMap[$k2]=$kr;$dk=$kr['process_no'].'_0';if(!isset($kpsMap[$dk]))$kpsMap[$dk]=$kr;}

            // ── 批次查詢平均工時快取（避免 N+1）──────────────────
            $amtCacheMap = [];
            $amtCachePairs = [];
            foreach ($rows as $r) {
                $dsId2 = intval($r['d_setting_id'] ?? 0);
                $ptId2 = intval($r['process_type_id'] ?? 0);
                if ($dsId2 > 0 && $ptId2 > 0) {
                    $amtCachePairs[$dsId2.'_'.$ptId2] = [$dsId2, $ptId2];
                }
            }
            if (!empty($amtCachePairs)) {
                $amtInParts = implode(',', array_map(function($pair) {
                    return '(' . $pair[0] . ',' . $pair[1] . ')';
                }, $amtCachePairs));
                try {
                    $amtCacheRows = $pdo->query(
                        "SELECT d_setting_id, process_type_id, avg_min_per_pc
                         FROM kpi_avg_time_cache
                         WHERE (d_setting_id, process_type_id) IN ($amtInParts)"
                    )->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($amtCacheRows as $c) {
                        $amtCacheMap[intval($c['d_setting_id']).'_'.intval($c['process_type_id'])] = floatval($c['avg_min_per_pc']);
                    }
                } catch(Exception $e2) { /* 快取表不存在時忽略 */ }
            }

            foreach($rows as &$r){
                $qty=intval($r['produced_qty']);$dsId=intval($r['d_setting_id']??0);$procNo=$r['process_no'];
                $kps=$kpsMap[$procNo.'_'.$dsId]??$kpsMap[$procNo.'_0']??null;
                if(!$kps){$r['amount']=0;}else{
                    $coeff=floatval($kps['coeff']??1);$baseT=floatval($kps['base_t']??0);$baseP=floatval($kps['base_p']??0);$multi=floatval($kps['multiplier']??1);
                    if($r['part_type']==='G'&&floatval($r['Module']??0)>0)
                        $r['amount']=$baseT*floatval($r['Module'])*floatval($r['Teeth'])*floatval($r['Face_Width'])*$coeff*$baseP*$qty;
                    else $r['amount']=$baseT*$coeff*$multi*$baseP*$qty;
                }
                // 平均工時（從批次 Map 取值，不再個別查 DB）
                $amtCk = $dsId.'_'.intval($r['process_type_id'] ?? 0);
                $r['avg_min_per_pc'] = isset($amtCacheMap[$amtCk]) ? $amtCacheMap[$amtCk] : null;
            }
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 機台資產列表 ──────────────────────────────────────────
    if ($action === 'get_machine_assets') {
        $filterMtid = intval($_POST['machine_type_id'] ?? 0);
        try {
            $sql = "SELECT ml.machine_id,ml.machine,ml.machine_type_id,ml.position,ml.state,ml.disabled_date,ml.need_setup,
                ml.machine_model,ml.asset_no,ml.field_no,ml.spec,ml.note,
                pt.process_type AS machine_type,
                kma.asset_id,kma.purchase_date,kma.purchase_amount,kma.residual_value,
                kma.depreciation_years,kma.depreciation_method,kma.monthly_work_hours,kma.remark
                FROM machine_list ml
                LEFT JOIN process_type pt ON pt.process_type_id=ml.machine_type_id
                LEFT JOIN kpi_machine_asset kma ON kma.machine_id=ml.machine_id";
            $params=[];
            if($filterMtid){$sql.=" WHERE ml.machine_type_id=?";$params[]=$filterMtid;}
            $sql.=" ORDER BY pt.process_type, ml.machine";
            $st=$pdo->prepare($sql);$st->execute($params);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);

            // 計算每台機台每小時成本（以24h/天計）
            $now=new DateTime();
            foreach($rows as &$r){
                if(!$r['purchase_date']||!$r['purchase_amount']){$r['hourly_cost']=null;continue;}
                $pAmt=floatval($r['purchase_amount']);
                $resVal=floatval($r['residual_value']??0);
                $years=intval($r['depreciation_years']??5);
                $method=$r['depreciation_method']??'straight';
                $purchaseDate=new DateTime($r['purchase_date']);
                $elapsedYears=($now->getTimestamp()-$purchaseDate->getTimestamp())/(365.25*86400);

                if($method==='straight'){
                    // 直線法：每年折舊 = (購入-殘值) / 年限
                    $annualDep=($pAmt-$resVal)/max(1,$years);
                    $monthlyDep=$annualDep/12;
                } elseif($method==='double_declining'){
                    // 雙倍餘額遞減法
                    $rate=2/$years;
                    $bookValue=$pAmt;
                    $yearInt=min(floor($elapsedYears),$years-1);
                    for($i=0;$i<$yearInt;$i++){$dep=max($bookValue*$rate,($bookValue-$resVal)/max(1,$years-$i));$bookValue-=$dep;}
                    $curYearDep=$bookValue*$rate;
                    $straightDep=($bookValue-$resVal)/max(1,$years-$yearInt);
                    $annualDep=max($curYearDep,$straightDep);
                    $monthlyDep=$annualDep/12;
                } elseif($method==='sum_of_years'){
                    // 年數合計法
                    $sumYears=$years*($years+1)/2;
                    $yearInt=min(floor($elapsedYears),$years);
                    $remYears=max(0,$years-$yearInt);
                    $annualDep=($pAmt-$resVal)*$remYears/$sumYears;
                    $monthlyDep=$annualDep/12;
                } else {$monthlyDep=0;}

                // 每月工時 = 24h×30天（以24h計）
                $monthlyHours=24*30;
                $r['hourly_cost']=$monthlyHours>0?round($monthlyDep/$monthlyHours,4):null;
                $r['monthly_dep']=round($monthlyDep,2);
                $r['elapsed_years']=round($elapsedYears,1);
                $r['is_fully_depreciated']=($elapsedYears>=$years);
            }
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 儲存機台資產設定 ──────────────────────────────────────
    if ($action === 'save_machine_asset') {
        if (!$is_admin){echo json_encode(['success'=>false,'message'=>'無權限']);exit;}
        $mid   = intval($_POST['machine_id']??0);
        $pDate = trim($_POST['purchase_date']??'');
        $pAmt  = round(floatval($_POST['purchase_amount']??0),2);
        $resV  = round(floatval($_POST['residual_value']??0),2);
        $years = intval($_POST['depreciation_years']??5);
        $meth  = trim($_POST['depreciation_method']??'straight');
        $mHrs  = round(floatval($_POST['monthly_work_hours']??160),2);
        $rem   = trim($_POST['remark']??'');
        if(!$mid||!$pDate){echo json_encode(['success'=>false,'message'=>'機台與購入日期為必填']);exit;}
        if(!in_array($meth,['straight','double_declining','sum_of_years']))$meth='straight';
        try {
            $pdo->prepare("INSERT INTO kpi_machine_asset (machine_id,purchase_date,purchase_amount,residual_value,depreciation_years,depreciation_method,monthly_work_hours,remark,created_by,updated_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE purchase_date=VALUES(purchase_date),purchase_amount=VALUES(purchase_amount),residual_value=VALUES(residual_value),depreciation_years=VALUES(depreciation_years),depreciation_method=VALUES(depreciation_method),monthly_work_hours=VALUES(monthly_work_hours),remark=VALUES(remark),updated_by=VALUES(updated_by)")
                ->execute([$mid,$pDate,$pAmt,$resV,$years,$meth,$mHrs,$rem,$userId,$userId]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 新增/編輯機台本身 (與 process_schedule 頁共用 machine_list，兩邊即時同步) ──
    if ($action === 'save_machine_info') {
        if (!$is_admin){echo json_encode(['success'=>false,'message'=>'無權限']);exit;}
        $mid = intval($_POST['machine_id'] ?? 0);
        $name = trim($_POST['machine'] ?? '');
        $typeId = intval($_POST['machine_type_id'] ?? 0);
        $position = trim($_POST['position'] ?? '');
        $needSetup = intval($_POST['need_setup'] ?? 0);
        $model = trim($_POST['machine_model'] ?? '');
        $assetNo = trim($_POST['asset_no'] ?? '');
        $fieldNo = trim($_POST['field_no'] ?? '');
        $spec = trim($_POST['spec'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $state = !empty($_POST['disabled']) ? '1' : null;
        $disabledDate = $state ? (trim($_POST['disabled_date'] ?? '') ?: date('Y-m-d')) : null;
        if (!$name){echo json_encode(['success'=>false,'message'=>'機台名稱不可為空']);exit;}
        if (!$typeId){echo json_encode(['success'=>false,'message'=>'請選擇機台類型']);exit;}
        try {
            if ($mid) {
                $pdo->prepare("UPDATE machine_list SET machine=?, machine_type_id=?, need_setup=?, position=?, machine_model=?, asset_no=?, field_no=?, spec=?, note=?, state=?, disabled_date=? WHERE machine_id=?")
                    ->execute([$name,$typeId,$needSetup,$position,$model,$assetNo,$fieldNo,$spec,$note,$state,$disabledDate,$mid]);
            } else {
                $pdo->prepare("INSERT INTO machine_list (machine, machine_type_id, need_setup, position, machine_model, asset_no, field_no, spec, note, state, disabled_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$name,$typeId,$needSetup,$position,$model,$assetNo,$fieldNo,$spec,$note,$state,$disabledDate]);
            }
            echo json_encode(['success'=>true]);
        } catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 刪除機台 (軟刪除 state=1) ─────────────────────────────
    if ($action === 'delete_machine_info') {
        if (!$is_admin){echo json_encode(['success'=>false,'message'=>'無權限']);exit;}
        $mid = intval($_POST['machine_id'] ?? 0);
        if (!$mid){echo json_encode(['success'=>false,'message'=>'缺少機台']);exit;}
        try {
            $pdo->prepare("UPDATE machine_list SET state='1', disabled_date=COALESCE(disabled_date, CURDATE()) WHERE machine_id=?")->execute([$mid]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 快速設定：寫入料號每PCS工時 ──────────────────────────
    if ($action === 'quick_set_base_time') {
        $dSettingId = intval($_POST['d_setting_id']??0);
        $groupId    = intval($_POST['group_id']??0);
        $avgMin     = floatval($_POST['avg_min_per_pc']??0);
        $coeff      = max(1.0,min(10.0,round(floatval($_POST['coefficient']??1.0),1)));
        $baseP      = trim($_POST['base_price']??'')!==''?round(floatval($_POST['base_price']),4):null;
        $multi      = round(floatval($_POST['multiplier']??1.0),4);
        $remark     = trim($_POST['remark']??'');
        if(!$dSettingId||!$groupId||$avgMin<=0){echo json_encode(['success'=>false,'message'=>'參數錯誤']);exit;}
        $baseSec = round($avgMin*60,2); // 分鐘轉秒
        try {
            $pdo->prepare("INSERT INTO kpi_part_standard (d_setting_id,group_id,coefficient,base_time_sec,base_price,multiplier,remark,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE coefficient=VALUES(coefficient),base_time_sec=VALUES(base_time_sec),base_price=COALESCE(VALUES(base_price),base_price),multiplier=VALUES(multiplier),remark=VALUES(remark),updated_by=VALUES(updated_by)")
                ->execute([$dSettingId,$groupId,$coeff,$baseSec,$baseP,$multi,$remark,$userId,$userId]);
            echo json_encode(['success'=>true,'base_sec'=>$baseSec]);
        } catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 重量計算規則 讀取 ────────────────────────────────────────
    if ($action === 'get_weight_rules') {
        try {
            $rules = kpiLoadWeightRules($pdo);
            $densities = kpiLoadMaterialDensities($pdo);
            $weightCfg = kpiLoadWeightConfig($pdo);
            $keywordLabelId = intval($weightCfg['keyword_label_id'] ?? 0);
            $labelNameMap = [];
            try {
                foreach ($pdo->query("SELECT label_id, label_name FROM dict_label WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $ln)
                    $labelNameMap[$ln['label_id']] = $ln['label_name'];
            } catch(Throwable) {}
            foreach ($rules as &$r) {
                $r['d_label_name']        = $labelNameMap[intval($r['d_label_id'] ?? 0)] ?? '';
                $r['l_label_name']        = $labelNameMap[intval($r['l_label_id'] ?? 0)] ?? '';
                $r['material_label_name'] = $labelNameMap[intval($r['material_label_id'] ?? 0)] ?? '';
                $condNames = [];
                foreach ($r['cond_label_ids'] as $cid)
                    $condNames[] = $labelNameMap[intval($cid)] ?? ('#'.$cid);
                $r['cond_label_names'] = $condNames;
                $condOrNames = [];
                foreach ($r['cond_or_label_ids'] as $cid) {
                    if (intval($cid) === -1) $condOrNames[] = '齒部外徑(計算值)';
                    else $condOrNames[] = $labelNameMap[intval($cid)] ?? ('#'.$cid);
                }
                $r['cond_or_label_names'] = $condOrNames;
            }
            $subLabels = [];
            if ($keywordLabelId > 0) {
                try {
                    $sl = $pdo->prepare("SELECT sub_id, sub_name FROM dict_label_sub WHERE label_id=? AND is_active=1 ORDER BY sort_order, sub_id");
                    $sl->execute([$keywordLabelId]);
                    $subLabels = $sl->fetchAll(PDO::FETCH_ASSOC);
                } catch(Throwable) {}
            }
            echo json_encode(['success'=>true,'rules'=>$rules,'densities'=>$densities,'keyword_label_id'=>$keywordLabelId,'sub_labels'=>$subLabels]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    // ── 重量試算 ─────────────────────────────────────────────────
    if ($action === 'calc_weight_preview') {
        $partNo = trim($_POST['part_no'] ?? '');
        $dId    = intval($_POST['d_id'] ?? 0);
        if (!$dId && $partNo) {
            try {
                $r = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? LIMIT 1");
                $r->execute([$partNo]);
                $row = $r->fetch(PDO::FETCH_ASSOC);
                $dId = $row ? intval($row['d_id']) : 0;
            } catch(Throwable) {}
        }
        if (!$dId) { echo json_encode(['success'=>false,'message'=>'找不到料號']); exit; }
        $partInfo = [];
        try {
            $pi = $pdo->prepare("SELECT d_id, D_Setting_Id, Type, COALESCE(Weight_Kg,0) AS Weight_Kg FROM d_setting WHERE d_id=?");
            $pi->execute([$dId]);
            $partInfo = $pi->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch(Throwable) {}
        if (empty($partInfo)) { echo json_encode(['success'=>false,'message'=>'找不到料號']); exit; }
        $ownWeightKg = floatval($partInfo['Weight_Kg'] ?? 0);
        $rules       = kpiLoadWeightRules($pdo);
        $densities   = kpiLoadMaterialDensities($pdo);
        $weightCfg   = kpiLoadWeightConfig($pdo);
        $kwLblId     = intval($weightCfg['keyword_label_id'] ?? 0);
        $labelValMap = kpiLoadLabelValMap($pdo, [$dId]);
        $gearValMap  = kpiLoadGearValMap($pdo, [$dId]);
        $lnMap = []; $snMap = [];
        try {
            foreach ($pdo->query("SELECT label_id,label_name FROM dict_label WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $ln)
                $lnMap[intval($ln['label_id'])] = $ln['label_name'];
            foreach ($pdo->query("SELECT sub_id,sub_name FROM dict_label_sub")->fetchAll(PDO::FETCH_ASSOC) as $sn)
                $snMap[intval($sn['sub_id'])] = $sn['sub_name'];
        } catch(Throwable) {}
        $ruleResults = []; $finalWeight = null;
        foreach ($rules as $rule) {
            $rr = ['rule_id'=>intval($rule['rule_id']),'rule_name'=>$rule['rule_name']];
            // AND 條件
            $andChecks = []; $andMet = true;
            foreach ($rule['cond_label_ids'] as $lid) {
                $lid = intval($lid); $exists = isset($labelValMap[$dId.'_'.$lid]);
                $andChecks[] = ['label_id'=>$lid,'label_name'=>$lnMap[$lid]??'#'.$lid,'met'=>$exists];
                if (!$exists) $andMet = false;
            }
            $rr['and_checks'] = $andChecks; $rr['and_met'] = $andMet;
            // OR 條件
            $orChecks = []; $orMet = empty($rule['cond_or_label_ids']);
            foreach ($rule['cond_or_label_ids'] as $lid) {
                $lid = intval($lid);
                if ($lid === -1) {
                    $daVal = floatval($gearValMap[$dId.'_da'] ?? 0); $met = $daVal > 0;
                    $orChecks[] = ['label_id'=>-1,'label_name'=>'齒部外徑(計算值)','value'=>$daVal,'met'=>$met];
                } else {
                    $exists = isset($labelValMap[$dId.'_'.$lid]);
                    $orChecks[] = ['label_id'=>$lid,'label_name'=>$lnMap[$lid]??'#'.$lid,'met'=>$exists,'value'=>$exists?$labelValMap[$dId.'_'.$lid]:''];
                    if ($exists) $orMet = true;
                }
                if (end($orChecks)['met']) $orMet = true;
            }
            $rr['or_checks'] = $orChecks; $rr['or_met'] = $orMet;
            $rr['triggered'] = $andMet && $orMet;
            if ($rr['triggered']) {
                // D 多來源
                $dDetails = []; $maxD = 0.0;
                foreach ($rule['d_sources'] as $ds) {
                    $dEntry = ['type'=>$ds['type']]; $dVal = 0.0;
                    if ($ds['type'] === 'gear') {
                        $field = $ds['gear_field'] ?? '';
                        $dVal = floatval($gearValMap[$dId.'_'.$field] ?? 0);
                        $dEntry['gear_field'] = $field;
                        $dEntry['display'] = '齒輪欄位 '.$field.' = '.$dVal.' mm';
                    } else {
                        $lid = intval($ds['label_id']); $sid = intval($ds['sub_id'] ?? 0);
                        $key = $dId.'_'.$lid.($sid ? '_'.$sid : '');
                        $rMax = floatval($labelValMap[$key.'_max'] ?? 0);
                        $rVal = floatval($labelValMap[$key] ?? 0);
                        $dVal = $rMax > 0 ? $rMax : $rVal;
                        $lname = $lnMap[$lid] ?? '(未設定)';
                        if ($sid) $lname .= ' › '.($snMap[$sid] ?? '#'.$sid);
                        $dEntry['label_name'] = $lname; $dEntry['input_value'] = $rVal; $dEntry['value_max'] = $rMax;
                        $dEntry['display'] = $lname.' = '.($dVal ?: '(無值)').' mm'.($rMax > 0 ? '（取value_max）' : '');
                    }
                    $dEntry['value'] = $dVal; $dEntry['is_max'] = false;
                    if ($dVal > $maxD) $maxD = $dVal;
                    $dDetails[] = $dEntry;
                }
                foreach ($dDetails as &$de) { $de['is_max'] = ($de['value'] == $maxD && $maxD > 0); }
                $rr['d_details'] = $dDetails; $rr['D'] = $maxD;
                // L
                $L = 0.0; $lDet = [];
                if (($rule['l_type'] ?? '') === 'gear') {
                    $field = $rule['l_gear_field'] ?? '';
                    $L = floatval($gearValMap[$dId.'_'.$field] ?? 0);
                    $lDet = ['type'=>'gear','display'=>'齒輪欄位 '.$field.' = '.$L.' mm'];
                } else {
                    $lid = intval($rule['l_label_id'] ?? 0); $sid = intval($rule['l_sub_id'] ?? 0);
                    $key = $dId.'_'.$lid.($sid ? '_'.$sid : '');
                    $rMax = floatval($labelValMap[$key.'_max'] ?? 0);
                    $rVal = floatval($labelValMap[$key] ?? 0);
                    $L = $rMax > 0 ? $rMax : $rVal;
                    $lname = $lnMap[$lid] ?? '(未設定)'; if ($sid) $lname .= ' › '.($snMap[$sid] ?? '#'.$sid);
                    $lDet = ['type'=>'label','label_name'=>$lname,'input_value'=>$rVal,'value_max'=>$rMax,
                             'display'=>$lname.' = '.($L ?: '(無值)').' mm'.($rMax > 0 ? '（取value_max）' : '')];
                }
                $rr['L_detail'] = $lDet; $rr['L'] = $L;
                // 密度
                $rho = null; $rhoDet = [];
                if (($rule['density_src'] ?? '') === 'fixed') {
                    $rho = floatval($rule['fixed_density_g'] ?? 0);
                    $rhoDet = ['src'=>'fixed','display'=>'固定密度 = '.$rho.' g/cm³'];
                } else {
                    $srcLbl = $kwLblId > 0 ? $kwLblId : intval($rule['material_label_id'] ?? 0);
                    $matVals = [];
                    if ($srcLbl > 0) {
                        $matVals[] = ['sub_name'=>'（父標籤）','value'=>(string)($labelValMap[$dId.'_'.$srcLbl] ?? '')];
                        $prefix = $dId.'_'.$srcLbl.'_'; $pfxLen = strlen($prefix);
                        foreach ($labelValMap as $k => $v) {
                            if (strncmp((string)$k, $prefix, $pfxLen) === 0 && ctype_digit(substr((string)$k, $pfxLen)))
                                $matVals[] = ['sub_name'=>$snMap[intval(substr((string)$k,$pfxLen))]??'#'.substr((string)$k,$pfxLen),'value'=>(string)$v];
                        }
                    }
                    $matched = null;
                    foreach ($densities as $d) {
                        $bSid = intval($d['bound_sub_id'] ?? 0);
                        if ($bSid > 0) {
                            // 子標籤直接匹配：該子標籤有值即命中
                            $specificKey = $dId.'_'.$srcLbl.'_'.$bSid;
                            $specificVal = trim((string)($labelValMap[$specificKey] ?? ''));
                            if ($specificVal !== '') {
                                $sName = $snMap[$bSid] ?? '#'.$bSid;
                                $matched = ['keyword'=>$sName,'density_g'=>floatval($d['density_g']),'matched_in'=>$sName.' = '.$specificVal];
                                $rho = floatval($d['density_g']); break;
                            }
                        } else {
                            // 舊式關鍵字匹配（向下相容）
                            $kw = mb_strtolower(trim($d['keyword']),'UTF-8'); if ($kw==='') continue;
                            foreach ($matVals as $mv) {
                                $mat = mb_strtolower(trim($mv['value']),'UTF-8');
                                if ($mat!=='' && mb_strpos($mat,$kw)!==false) {
                                    $matched = ['keyword'=>$d['keyword'],'density_g'=>floatval($d['density_g']),'matched_in'=>$mv['sub_name'].' = '.$mv['value']];
                                    $rho = floatval($d['density_g']); break 2;
                                }
                            }
                        }
                    }
                    $rhoDet = ['src'=>'material','kw_label_name'=>$lnMap[$srcLbl]??'(未設定)',
                               'mat_vals'=>$matVals,'matched'=>$matched,
                               'display'=>$matched?'關鍵字「'.$matched['keyword'].'」命中 → '.$matched['density_g'].' g/cm³（'.$matched['matched_in'].'）':'未命中任何密度關鍵字'];
                }
                $rr['rho'] = $rho; $rr['rho_detail'] = $rhoDet;
                if ($maxD > 0 && $L > 0 && $rho !== null && $rho > 0) {
                    $w = M_PI / 4.0 * $maxD * $maxD * $L * $rho / 1000000.0;
                    $rr['weight_kg'] = round($w, 6);
                    $rr['formula_text'] = 'π/4 × '.$maxD.'² × '.$L.' × '.$rho.' ÷ 1,000,000 = '.round($w,6).' kg';
                    if ($finalWeight === null || $w > $finalWeight) $finalWeight = round($w, 6);
                } else {
                    $rr['weight_kg'] = null;
                    $reasons = [];
                    if ($maxD <= 0) $reasons[] = 'D=0（來源無值）';
                    if ($L <= 0)    $reasons[] = 'L=0（來源無值）';
                    if ($rho === null || $rho <= 0) $reasons[] = '密度未命中';
                    $rr['skip_reason'] = implode('、',$reasons);
                }
            }
            $ruleResults[] = $rr;
        }
        echo json_encode(['success'=>true,'d_id'=>$dId,'part_no'=>$partInfo['D_Setting_Id'],'part_type'=>$partInfo['Type']??'',
            'own_weight_kg'=>$ownWeightKg,'use_own_weight'=>$ownWeightKg>0,
            'final_weight_kg'=>$ownWeightKg>0?$ownWeightKg:$finalWeight,
            'rules'=>$ruleResults,'kw_label_name'=>$lnMap[$kwLblId]??'（未設定）']);
        exit;
    }
    // ── 重量計算規則 儲存 ────────────────────────────────────────
    if ($action === 'save_weight_rule') {
        $ruleId     = intval($_POST['rule_id'] ?? 0);
        $ruleName   = trim($_POST['rule_name'] ?? '');
        $condIds    = json_decode(trim($_POST['cond_label_ids']    ?? '[]'), true) ?: [];
        $condOrIds  = json_decode(trim($_POST['cond_or_label_ids'] ?? '[]'), true) ?: [];
        // D 多來源
        $dSourcesRaw = json_decode(trim($_POST['d_sources'] ?? '[]'), true) ?: [];
        $dSources = [];
        foreach ($dSourcesRaw as $ds) {
            $type = ($ds['type'] ?? '') === 'gear' ? 'gear' : 'label';
            $dSources[] = [
                'type'       => $type,
                'label_id'   => intval($ds['label_id'] ?? 0),
                'sub_id'     => intval($ds['sub_id'] ?? 0),
                'gear_field' => trim($ds['gear_field'] ?? ''),
            ];
        }
        $lType      = in_array(trim($_POST['l_type'] ?? ''), ['label','gear']) ? trim($_POST['l_type']) : 'label';
        $lLabelId   = intval($_POST['l_label_id'] ?? 0) ?: null;
        $lSubId     = intval($_POST['l_sub_id'] ?? 0) ?: null;
        $lGearField = trim($_POST['l_gear_field'] ?? '') ?: null;
        $densitySrc = in_array(trim($_POST['density_src'] ?? ''), ['material','fixed']) ? trim($_POST['density_src']) : 'material';
        $matLblId   = intval($_POST['material_label_id'] ?? 0) ?: null;
        $fixedDens  = trim($_POST['fixed_density_g'] ?? '') !== '' ? round(floatval($_POST['fixed_density_g']), 4) : null;
        $sortOrder  = intval($_POST['sort_order'] ?? 0);
        if (!$ruleName) { echo json_encode(['success'=>false,'message'=>'規則名稱必填']); exit; }
        // D sources with dim_field
        foreach ($dSources as &$ds) { $ds['dim_field'] = trim($ds['dim_field'] ?? ''); } unset($ds);
        $dSourcesJson = json_encode($dSources);
        // L dim_field
        $lDimField = trim($_POST['l_dim_field'] ?? '');
        // Body sections (multi-step)
        $bodySecRaw = json_decode(trim($_POST['body_sections'] ?? '[]'), true) ?: [];
        $bodySections = [];
        foreach ($bodySecRaw as $sec) {
            if (!intval($sec['d_label_id']??0) || !intval($sec['l_label_id']??0)) continue;
            $bodySections[] = [
                'type'         => ($sec['type']??'') === 'annulus' ? 'annulus' : 'cylinder',
                'd_label_id'   => intval($sec['d_label_id']??0),
                'd_sub_id'     => intval($sec['d_sub_id']??0),
                'd_dim_field'  => trim($sec['d_dim_field']??''),
                'd2_label_id'  => intval($sec['d2_label_id']??0),
                'd2_sub_id'    => intval($sec['d2_sub_id']??0),
                'd2_dim_field' => trim($sec['d2_dim_field']??''),
                'l_label_id'   => intval($sec['l_label_id']??0),
                'l_sub_id'     => intval($sec['l_sub_id']??0),
                'l_dim_field'  => trim($sec['l_dim_field']??''),
            ];
        }
        $bodySecJson = json_encode($bodySections);
        // Deductions with dim_field
        $deductionsRaw = json_decode(trim($_POST['deduction_sources'] ?? '[]'), true) ?: [];
        $deductions = [];
        foreach ($deductionsRaw as $ded) {
            if (!intval($ded['d_label_id']??0) || !intval($ded['l_label_id']??0)) continue;
            $deductions[] = [
                'type'         => ($ded['type']??'') === 'annulus' ? 'annulus' : 'cylinder',
                'desc'         => trim($ded['desc'] ?? ''),
                'd_label_id'   => intval($ded['d_label_id'] ?? 0),
                'd_sub_id'     => intval($ded['d_sub_id'] ?? 0),
                'd_dim_field'  => trim($ded['d_dim_field'] ?? ''),
                'd2_label_id'  => intval($ded['d2_label_id'] ?? 0),
                'd2_sub_id'    => intval($ded['d2_sub_id'] ?? 0),
                'd2_dim_field' => trim($ded['d2_dim_field'] ?? ''),
                'l_label_id'   => intval($ded['l_label_id'] ?? 0),
                'l_sub_id'     => intval($ded['l_sub_id'] ?? 0),
                'l_dim_field'  => trim($ded['l_dim_field'] ?? ''),
            ];
        }
        $deductionsJson = json_encode($deductions);
        try {
            $condJson   = json_encode(array_map('intval', $condIds));
            $condOrJson = json_encode(array_map('intval', $condOrIds));
            if ($ruleId > 0) {
                $pdo->prepare("UPDATE kpi_weight_calc_rule SET rule_name=?,cond_label_ids=?,cond_or_label_ids=?,d_sources=?,l_type=?,l_label_id=?,l_sub_id=?,l_gear_field=?,l_dim_field=?,density_src=?,material_label_id=?,fixed_density_g=?,body_sections=?,deduction_sources=?,sort_order=? WHERE rule_id=?")
                    ->execute([$ruleName,$condJson,$condOrJson,$dSourcesJson,$lType,$lLabelId,$lSubId,$lGearField,$lDimField,$densitySrc,$matLblId,$fixedDens,$bodySecJson,$deductionsJson,$sortOrder,$ruleId]);
            } else {
                $ins = $pdo->prepare("INSERT INTO kpi_weight_calc_rule (rule_name,cond_label_ids,cond_or_label_ids,d_sources,l_type,l_label_id,l_sub_id,l_gear_field,l_dim_field,density_src,material_label_id,fixed_density_g,body_sections,deduction_sources,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([$ruleName,$condJson,$condOrJson,$dSourcesJson,$lType,$lLabelId,$lSubId,$lGearField,$lDimField,$densitySrc,$matLblId,$fixedDens,$bodySecJson,$deductionsJson,$sortOrder]);
                $ruleId = intval($pdo->lastInsertId());
            }
            echo json_encode(['success'=>true,'rule_id'=>$ruleId]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    // ── 重量計算規則 刪除 ────────────────────────────────────────
    if ($action === 'delete_weight_rule') {
        $ruleId = intval($_POST['rule_id'] ?? 0);
        if (!$ruleId) { echo json_encode(['success'=>false,'message'=>'rule_id 必填']); exit; }
        try {
            $pdo->prepare("DELETE FROM kpi_weight_calc_rule WHERE rule_id=?")->execute([$ruleId]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    // ── 材質密度表 儲存 ──────────────────────────────────────────
    if ($action === 'save_material_densities') {
        $items          = json_decode(trim($_POST['items'] ?? '[]'), true) ?: [];
        $keywordLabelId = intval($_POST['keyword_label_id'] ?? 0) ?: null;
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM kpi_material_density");
            $ins = $pdo->prepare("INSERT INTO kpi_material_density (keyword,density_g,bound_sub_id,sort_order) VALUES (?,?,?,?)");
            foreach ($items as $i => $it) {
                $dg   = round(floatval($it['density_g'] ?? 0), 4);
                $bSid = intval($it['bound_sub_id'] ?? 0) ?: null;
                if ($dg <= 0 || !$bSid) continue;
                $ins->execute(['', $dg, $bSid, $i * 10]);
            }
            $pdo->prepare("INSERT INTO kpi_weight_config (config_key,config_val) VALUES ('keyword_label_id',?) ON DUPLICATE KEY UPDATE config_val=VALUES(config_val)")
                ->execute([$keywordLabelId]);
            $pdo->commit();
            echo json_encode(['success'=>true]);
        } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 取得標籤的子標籤清單 ─────────────────────────────────────
    if ($action === 'get_sub_labels') {
        $labelId = intval($_POST['label_id'] ?? 0);
        if (!$labelId) { echo json_encode(['success'=>true,'sub_labels'=>[]]); exit; }
        try {
            $sl = $pdo->prepare("SELECT sub_id, sub_name FROM dict_label_sub WHERE label_id=? AND is_active=1 ORDER BY sort_order, sub_id");
            $sl->execute([$labelId]);
            echo json_encode(['success'=>true,'sub_labels'=>$sl->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ══ 頁面初始資料 ══════════════════════════════════════════════
$user_list = $pdo->query("
    SELECT u.id, u.user_cname, u.state,
        COALESCE(d.name,'') AS dept_name,
        COALESCE(pos.name,'') AS pos_name,
        COALESCE(udm.department_id, 0) AS dept_id
    FROM user u
    LEFT JOIN user_department_position_map udm ON udm.user_id=u.id AND udm.is_main=1
    LEFT JOIN department d ON d.id=udm.department_id
    LEFT JOIN position pos ON pos.id=udm.position_id
    WHERE u.state=1 ORDER BY d.name, u.user_cname
")->fetchAll(PDO::FETCH_ASSOC);

$machine_list = $pdo->query("SELECT ml.machine_id, ml.machine, ml.machine_type_id, ml.position FROM machine_list ml WHERE (ml.state IS NULL OR ml.state!='1') ORDER BY ml.machine_type_id, ml.position, ml.machine_id")->fetchAll(PDO::FETCH_ASSOC);

// 機台本月報工筆數
$machine_report_count_map = [];
try {
    $mcCR = $pdo->query("SELECT pdr.machine_id, COUNT(*) AS cnt FROM pm_process_daily_report pdr JOIN machine_list ml ON ml.machine_id=pdr.machine_id WHERE (ml.state IS NULL OR ml.state!='1') AND pdr.report_date BETWEEN DATE_FORMAT(NOW(),'%Y-%m-01') AND CURDATE() GROUP BY pdr.machine_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mcCR as $mc) { $machine_report_count_map[$mc['machine_id']] = intval($mc['cnt']); }
} catch(Exception $e) {}

$machine_type_list = [];
try { $machine_type_list = $pdo->query("SELECT process_type_id AS machine_type_id, process_type AS machine_type FROM process_type ORDER BY process_type_id")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}

// ══ 頁面載入時更新平均工時快取（kpi_avg_time_cache） ═════════
// 只在 GET 請求（頁面載入）時執行，避免 AJAX POST 時重複觸發
try {
    // 重新計算所有料號×製程類別的歷史平均工時
    // 來源：pm_process_daily_report（有生產工時且有良品數）
    // JOIN 到 process_no.process_type_id 取得製程類別
    // JOIN 到 bom_ing > bom > d_setting 取得 d_setting_id
    $pdo->exec("
        INSERT INTO kpi_avg_time_cache
            (d_setting_id, process_type_id, avg_min_per_pc, sample_count, total_prod_min, total_qty)
        SELECT
            ds.d_id AS d_setting_id,
            pn.process_type_id,
            ROUND(
                SUM(TIMESTAMPDIFF(SECOND, pdr.production_start_time, pdr.production_end_time)/60)
                / NULLIF(SUM(pdr.produced_qty), 0)
            , 4) AS avg_min_per_pc,
            COUNT(pdr.report_id) AS sample_count,
            ROUND(SUM(TIMESTAMPDIFF(SECOND, pdr.production_start_time, pdr.production_end_time)/60), 4) AS total_prod_min,
            SUM(pdr.produced_qty) AS total_qty
        FROM pm_process_daily_report pdr
        JOIN process_no pn ON pn.ProcessNo = pdr.process_no
        LEFT JOIN bom_ing bi ON bi.bom_ing_fid = pdr.bom_ing_fid
        LEFT JOIN bom b ON b.bom = bi.bom
        LEFT JOIN d_setting ds ON ds.D_Setting_Id = b.d_id
        WHERE pdr.production_start_time IS NOT NULL
          AND pdr.production_end_time IS NOT NULL
          AND pdr.produced_qty > 0
          AND ds.d_id IS NOT NULL
          AND pn.process_type_id IS NOT NULL
        GROUP BY ds.d_id, pn.process_type_id
        ON DUPLICATE KEY UPDATE
            avg_min_per_pc = VALUES(avg_min_per_pc),
            sample_count   = VALUES(sample_count),
            total_prod_min = VALUES(total_prod_min),
            total_qty      = VALUES(total_qty),
            updated_at     = NOW()
    ");
} catch(Exception $e) { error_log('[KPI avg_cache] '.$e->getMessage()); }

// 廠別清單（用於生產金額頁廠別維度）
$area_list = [];
try { $area_list = $pdo->query("SELECT area_id, area_name FROM stock_areas WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}

// 機台完整清單含狀態（用於機台資產頁）
$machine_list_all = [];
try { $machine_list_all = $pdo->query("SELECT ml.machine_id, ml.machine, ml.machine_type_id, ml.position, ml.state, pt.process_type AS machine_type FROM machine_list ml LEFT JOIN process_type pt ON pt.process_type_id=ml.machine_type_id ORDER BY pt.process_type, ml.machine")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}

$dept_list = [];
try { $dept_list = $pdo->query("SELECT id,name FROM department WHERE parent_id IS NOT NULL ORDER BY level,sort_order,name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>KPI 生產效率分析</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--primary:#2A3F54;--accent:#1ABB9C;--warn:#F39C12;--danger:#E74C3C;--info:#3498DB;--purple:#9B59B6;--bg:#F4F7FC;--card:#fff;--border:#E6E9ED;--text:#495057}
body{background:var(--bg);font-family:"Segoe UI","Roboto",Arial,sans-serif;color:var(--text)}
.right_col{background:var(--bg)!important;overflow-x:hidden!important;overflow-y:visible!important;max-width:100%;box-sizing:border-box;}
.pg-header{display:flex;align-items:center;justify-content:space-between;background:var(--card);border-radius:10px;padding:13px 20px;margin-bottom:14px;box-shadow:0 2px 6px rgba(0,0,0,.06);flex-wrap:wrap;gap:8px;}
.pg-header h3{margin:0;font-size:19px;font-weight:700;color:var(--primary)}
.tab-sw{display:flex;gap:4px;background:#eef1f5;border-radius:8px;padding:4px;flex-wrap:wrap;}
.tab-btn{border:none;background:transparent;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;color:#888;cursor:pointer;transition:all .2s}
.tab-btn.active{background:var(--card);color:var(--primary);box-shadow:0 2px 5px rgba(0,0,0,.1)}
.tab-pane{display:none}.tab-pane.active{display:block}
.main-pane{display:none}.main-pane.active{display:block}
.sub-pane{display:none}.sub-pane.active{display:block}
.fbar{background:var(--card);border-radius:10px;padding:10px 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;box-shadow:0 2px 6px rgba(0,0,0,.05);margin-bottom:14px}
.fbar .form-control,.fbar .btn{height:33px;font-size:13px}
.fbar .fg{display:flex;flex-direction:column;}
.fbar label{font-size:11px;font-weight:700;color:var(--primary);margin-bottom:2px;text-transform:uppercase;}
.qd-btn{padding:3px 10px;font-size:12px;border-radius:4px;border:1px solid var(--border);background:#fff;cursor:pointer;font-weight:600;color:#555;transition:.15s;}
.qd-btn:hover,.qd-btn.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.stats-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.sc{flex:1;min-width:130px;background:var(--card);border-radius:10px;padding:13px 16px;box-shadow:0 2px 6px rgba(0,0,0,.05);border-left:4px solid transparent;position:relative;overflow:hidden;}
.sc-icon{position:absolute;right:10px;top:10px;font-size:32px;opacity:.07}
.sc-val{font-size:24px;font-weight:800;color:var(--primary);line-height:1}
.sc-label{font-size:11px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.7px;margin-top:3px}
.mc{background:var(--card);border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,.05);overflow:hidden;margin-bottom:14px;}
.mc-head{padding:10px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:6px;}
.mc-head h5{margin:0;font-size:14px;font-weight:700;color:var(--primary);}
.mc table{width:100%;border-collapse:collapse;font-size:12px;}
.mc table thead th{background:#f8f9fa;color:#555;font-weight:700;padding:8px 8px;border-bottom:2px solid var(--border);white-space:nowrap;vertical-align:middle}
.mc table tbody td{padding:6px 8px;vertical-align:middle;border-bottom:1px solid #f0f2f5}
.mc table tbody tr:hover{background:#FAFBFF!important}
.tr-alert td{background:#fff8e1!important;}
.tr-alert:hover td{background:#fff3cd!important;}
.pager{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;border-top:1px solid var(--border);font-size:12px;flex-wrap:wrap;gap:4px;}
.pager-btns{display:flex;gap:3px;flex-wrap:wrap;}
.pager-btns button{padding:3px 9px;font-size:12px;border-radius:4px;border:1px solid var(--border);background:#fff;cursor:pointer}
.pager-btns button:hover{background:#f0f4ff}
.pager-btns button.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.pp-sel{padding:2px 6px;font-size:12px;border:1px solid var(--border);border-radius:4px;cursor:pointer;}
.util-bar-wrap{width:60px;height:6px;background:#e9ecef;border-radius:4px;display:inline-block;vertical-align:middle;overflow:hidden;}
.util-bar{height:6px;border-radius:4px;}
.cbadge{display:inline-block;padding:2px 7px;border-radius:20px;font-size:11px;font-weight:700}
.cbadge-ok{background:#d4f5ed;color:#0e7a5e}.cbadge-warn{background:#fef3e2;color:#a06000}.cbadge-ng{background:#fde8e8;color:#a52020}
.dept-pos{font-size:10px;color:#888;}
/* 難易係數 */
.did-list{border:1px solid var(--border);border-radius:8px;overflow:hidden;max-height:55vh;overflow-y:auto;}
.did-item{padding:9px 12px;cursor:pointer;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;justify-content:space-between;font-size:13px;transition:.12s;}
.did-item:last-child{border-bottom:none}
.did-item:hover{background:#f0fbf8}
.did-item.selected{background:#e8f8f5;border-left:3px solid var(--accent)}
.did-part{font-weight:700;color:var(--primary)}
.did-client{font-size:11px;color:#888}
.gear-tag{display:inline-block;font-size:10px;background:#d4f5ed;color:#0e7a5e;border-radius:3px;padding:1px 5px;margin-left:4px;font-weight:700}
.unset-tag{display:inline-block;font-size:10px;background:#fef3e2;color:#a06000;border-radius:3px;padding:1px 5px;margin-left:4px;font-weight:700}
.std-row{background:#f8f9fc;border-radius:8px;padding:10px 12px;margin-bottom:8px;border:1px solid var(--border);}
.std-row-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;}
.std-row-head .gname{font-weight:700;font-size:13px;color:var(--primary);min-width:60px}
.std-row-head .gproc{font-size:11px;color:#888;flex:1}
.std-row-body{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.std-field{display:flex;flex-direction:column;gap:2px;}
.std-field label{font-size:10px;font-weight:700;color:#888;text-transform:uppercase;}
.std-inp{width:90px;border:1px solid var(--border);border-radius:5px;padding:4px 7px;font-size:12px;font-weight:700;text-align:center;background:#fff;}
.std-inp:focus{outline:none;border-color:var(--accent);}
.std-inp.wide{width:120px;}
.gear-info-box{background:#fffde7;border:1px solid #f5e54c;border-radius:8px;padding:10px 12px;font-size:12px;margin-bottom:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:3px 10px;}
.gear-info-box span{color:#888}.gear-info-box strong{color:var(--primary)}
.setting-card{background:var(--card);border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,.05);padding:16px;margin-bottom:14px}
.setting-card h5{font-weight:700;color:var(--primary);margin-bottom:12px;font-size:14px;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid var(--accent);padding-bottom:8px;}
.proc-tag{display:inline-block;background:#eef1f5;color:#555;border-radius:4px;padding:2px 8px;font-size:11px;margin:2px;cursor:pointer;border:1px solid transparent;font-weight:600;transition:.12s;}
.proc-tag:hover{border-color:var(--accent)}.proc-tag.selected{background:#d4f5ed;color:#0e7a5e;border-color:#aee8d3}
.modal-header{background:var(--primary);color:#fff;border-radius:6px 6px 0 0}
.modal-header .modal-title{font-weight:700}.modal-header .close{color:#fff;opacity:1}
.modal-content{display:flex!important;flex-direction:column!important;max-height:90vh!important;}
.modal-body{overflow-y:auto!important;flex:1 1 auto!important;}
.modal-footer,.modal-header{flex-shrink:0!important;}
label{font-size:13px;font-weight:600;color:var(--primary);margin-bottom:3px}.form-control{font-size:13px}
#drawing-modal .modal-dialog{width:82vw;max-width:1100px;}
.dw-body{height:74vh;display:flex;gap:0;padding:0;overflow:hidden;}
.dw-sidebar{width:180px;flex-shrink:0;border-right:1px solid var(--border);overflow-y:auto;padding:8px;}
.dw-file-item{padding:7px 9px;cursor:pointer;border-radius:5px;margin-bottom:3px;font-size:12px;border:1px solid transparent;transition:.12s;}
.dw-file-item:hover{border-color:var(--accent);background:#e8f8f5}.dw-file-item.active{background:#d4f5ed;border-color:var(--accent);font-weight:700}
.dw-viewer{flex:1;overflow:hidden;background:#2c2c2c;display:flex;align-items:center;justify-content:center;}
.dw-viewer iframe{width:100%;height:100%;border:none;}
#dw-img{max-width:90%;max-height:90%;cursor:grab;user-select:none;transform-origin:center center;}
#toast-wrap{position:fixed;bottom:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px}
.toast-msg{padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.2);color:#fff;animation:toastIn .2s ease}
.toast-msg.success{background:var(--accent)}.toast-msg.error{background:var(--danger)}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:768px){.stats-row{flex-direction:column}.fbar{flex-direction:column;align-items:stretch}}
/* 二層 tab sub-btn */
.sub-btn{border:none;background:transparent;padding:5px 12px;border-radius:5px;font-size:12px;font-weight:600;color:#888;cursor:pointer;transition:all .2s}
.sub-btn.active{background:var(--card);color:var(--primary);box-shadow:0 1px 4px rgba(0,0,0,.1)}
/* 生產金額 */
.amt-tab-sw{display:flex;gap:4px;background:#f0f2f5;border-radius:6px;padding:3px;margin-bottom:12px;flex-wrap:wrap;}
.amt-btn{border:none;background:transparent;padding:5px 14px;border-radius:5px;font-size:12px;font-weight:600;color:#888;cursor:pointer;transition:.15s}
.amt-btn.active{background:#fff;color:var(--primary);box-shadow:0 1px 4px rgba(0,0,0,.1)}
/* 機台資產 */
.asset-state-ok{color:var(--accent);font-weight:700}
.asset-state-bad{color:#aaa;text-decoration:line-through;font-size:11px}
.tooltip-th{cursor:help;border-bottom:1px dashed #aaa;}
/* 快速設定按鈕 */
.btn-quick-set{padding:1px 6px;font-size:10px;border-radius:3px;border:1px solid var(--accent);background:#e8f8f5;color:var(--accent);cursor:pointer;white-space:nowrap;transition:.12s;}
.btn-quick-set:hover{background:var(--accent);color:#fff;}
/* 人員彙總展開行 */
.expand-row td{padding:0!important;background:#f8fffe;}
.expand-inner{padding:10px 16px;overflow-x:auto;}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

<!-- 頁頭 -->
<div class="pg-header">
  <h3><i class="fa fa-bar-chart" style="color:var(--accent);margin-right:8px;"></i>KPI 生產效率分析</h3>
  <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">
    <!-- 第一層主 tab -->
    <div class="tab-sw" id="main-tabs">
      <button class="tab-btn active" data-main="kpi" onclick="switchMain('kpi',this)">📊 KPI分析</button>
      <button class="tab-btn" data-main="user" onclick="switchMain('user',this)">👤 人員報工明細</button>
      <button class="tab-btn" data-main="mc" onclick="switchMain('mc',this)">🏭 機台稼動明細</button>
      <button class="tab-btn" data-main="amount" onclick="switchMain('amount',this)">💰 生產金額</button>
      <?php if($is_admin): ?>
      <button class="tab-btn" data-main="setting" onclick="switchMain('setting',this)">⚙️ 設定</button>
      <?php endif; ?>
    </div>
    <!-- 第二層設定子 tab（只有設定時顯示） -->
    <div class="tab-sw" id="sub-tabs-setting" style="display:none;">
      <button class="sub-btn active" data-sub="proddept" onclick="switchSub('proddept',this)">🏢 生產設定</button>
      <button class="sub-btn" data-sub="machine-asset" onclick="switchSub('machine-asset',this)">🔧 機台資產</button>
      <button class="sub-btn" data-sub="coeff" onclick="switchSub('coeff',this)">⚙️ 難易係數</button>
      <button class="sub-btn" data-sub="groups" onclick="switchSub('groups',this)">🔩 製程群組</button>
      <button class="sub-btn" data-sub="kpisetting" onclick="switchSub('kpisetting',this)">🎯 KPI標準值</button>
      <button class="sub-btn" data-sub="weightrule" onclick="switchSub('weightrule',this)">⚖️ 自動重量</button>
    </div>
  </div>
</div>

<!-- ══ 共用篩選列（所有 tab 共用） ══════════════════════════ -->
<div class="fbar" id="global-fbar">
  <div class="fg">
    <label>快速區間</label>
    <div style="display:flex;gap:4px;flex-wrap:wrap;">
      <button class="qd-btn active" data-qdate="month">本月</button>
      <button class="qd-btn" data-qdate="week">本週</button>
      <button class="qd-btn" data-qdate="quarter">本季</button>
      <button class="qd-btn" data-qdate="year">今年</button>
      <button class="qd-btn" data-qdate="custom">自訂</button>
    </div>
  </div>
  <div class="fg" id="fg-from" style="display:none;"><label>開始</label><input type="date" id="kpi-df" class="form-control" style="width:130px;"></div>
  <div class="fg" id="fg-to"   style="display:none;"><label>結束</label><input type="date" id="kpi-dt" class="form-control" style="width:130px;"></div>
  <div class="fg" id="fg-dept-wrap"><label>部門</label>
    <select id="kpi-dept" class="form-control" style="width:110px;"><option value="">全部</option></select>
  </div>
  <div class="fg" id="fg-user-wrap"><label>人員</label>
    <select id="kpi-user" class="form-control" style="width:130px;"><option value="">全部人員</option></select>
  </div>
  <div class="fg" id="fg-mc-type-wrap"><label>機台種類</label>
    <select id="kpi-mc-type" class="form-control" style="width:110px;">
      <option value="">全部種類</option>
      <?php foreach($machine_type_list as $mt): ?><option value="<?=safe($mt['machine_type_id'])?>"><?=safe($mt['machine_type'])?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="fg" id="fg-machine-wrap"><label>機台</label>
    <select id="kpi-machine" class="form-control" style="width:140px;">
      <option value="">全部</option>
      <?php foreach($machine_list as $m): $cnt=$machine_report_count_map[$m['machine_id']]??0; $cL=$cnt>0?' ('.$cnt.')':' (-)'; ?><option value="<?=safe($m['machine_id'])?>"><?=safe($m['machine'])?><?=safe($cL)?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="fg" id="fg-partno-wrap" style="display:none;"><label>料號</label>
    <div style="display:flex;align-items:center;">
      <input type="text" id="kpi-partno" class="form-control" style="width:120px;border-radius:4px 0 0 4px;" placeholder="料號關鍵字">
      <button type="button" id="btn-clear-partno" class="btn btn-default btn-sm" style="border-radius:0 4px 4px 0;border-left:0;display:none;padding:5px 7px;" onclick="clearPartnoFilter()" title="清除料號"><i class="fa fa-times" style="color:#c00;"></i></button>
    </div>
  </div>
  <div class="fg" style="justify-content:flex-end;"><label style="opacity:0;">.</label>
    <div style="display:flex;gap:5px;">
      <button class="btn btn-success btn-sm" id="btn-search" style="font-weight:600;"><i class="fa fa-search"></i> 查詢</button>
      <button class="btn btn-warning btn-sm" id="btn-reset" onclick="resetFilters()" style="font-weight:600;"><i class="fa fa-refresh"></i> 重置</button>
      <button class="btn btn-default btn-sm" onclick="window.print()" title="列印/PDF"><i class="fa fa-print"></i></button>
      <a class="btn btn-default btn-sm" href="kpi_shift_setting.php" title="班別排班"><i class="fa fa-calendar"></i></a>
    </div>
  </div>
</div>

<!-- ══ 主分頁容器 ═════════════════════════════════════════════ -->

<!-- ── KPI 儀表板 ─────────────────────────────────────────── -->
<div id="main-kpi" class="main-pane">
  <div class="stats-row">
    <div class="sc" style="border-left-color:var(--accent)"><i class="fa fa-file-text-o sc-icon"></i><div class="sc-val" id="ks-report">—</div><div class="sc-label">報工筆數</div></div>
    <div class="sc" style="border-left-color:var(--info)"><i class="fa fa-check-circle sc-icon"></i><div class="sc-val" id="ks-ok">—</div><div class="sc-label">良品數</div></div>
    <div class="sc" style="border-left-color:var(--danger)"><i class="fa fa-times-circle sc-icon"></i><div class="sc-val" id="ks-ng">—</div><div class="sc-label">NG 數</div></div>
    <div class="sc" style="border-left-color:var(--warn)"><i class="fa fa-percent sc-icon"></i><div class="sc-val" id="ks-yield">—</div><div class="sc-label">良品率</div></div>
    <div class="sc" style="border-left-color:var(--purple)"><i class="fa fa-clock-o sc-icon"></i><div class="sc-val" id="ks-hrs">—</div><div class="sc-label">生產工時 (h)</div></div>
    <div class="sc" style="border-left-color:var(--primary)"><i class="fa fa-cogs sc-icon"></i><div class="sc-val" id="ks-mc">—</div><div class="sc-label">使用機台</div></div>
  </div>
  <div style="background:#dbeafe;border-left:3px solid var(--info);border-radius:0 6px 6px 0;padding:6px 12px;font-size:12px;color:#1e40af;margin-bottom:12px;">
    <i class="fa fa-info-circle"></i> <strong>稼動率</strong>＝生產工時 ÷ 當日報工人員班別工時合計（報工反推法）× 100%。若顯示「⚠ 需設定排班」請至 <a href="kpi_shift_setting.php" style="color:var(--info);font-weight:700;">班別排班設定</a> 建立該人員的排班。超過100%表示生產工時超過排班工時。<strong>平均工時/件</strong>＝生產工時（分鐘）÷ 良品數。
  </div>
  <div class="mc">
    <div class="mc-head"><h5><i class="fa fa-users" style="color:var(--accent);margin-right:6px;"></i>人員彙總</h5>
      <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:12px;color:#888;">每頁</span>
        <select class="pp-sel" id="kpi-user-pp"><option value="10" selected>10筆</option><option value="20">20筆</option><option value="50">50筆</option></select>
        <span class="text-muted" style="font-size:11px;">雙擊展開明細</span>
      </div>
    </div>
    <div style="overflow-x:auto;"><table><thead id="agg-user-thead"></thead><tbody id="agg-user-tbody"><tr><td colspan="11" style="text-align:center;padding:30px;color:#bbb;"><i class="fa fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:.3;"></i>請按「查詢」</td></tr></tbody></table></div>
    <div class="pager"><span></span><div class="pager-btns" id="kpi-user-agg-pager"></div></div>
  </div>
  <div class="mc">
    <div class="mc-head"><h5><i class="fa fa-cogs" style="color:var(--accent);margin-right:6px;"></i>機台稼動彙總</h5>
      <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:12px;color:#888;">每頁</span>
        <select class="pp-sel" id="kpi-mc-pp"><option value="10" selected>10筆</option><option value="20">20筆</option><option value="50">50筆</option></select>
        <span class="text-muted" style="font-size:11px;">雙擊展開明細</span>
      </div>
    </div>
    <div style="overflow-x:auto;"><table><thead id="agg-mc-thead"></thead><tbody id="agg-mc-tbody"><tr><td colspan="9" style="text-align:center;padding:30px;color:#bbb;"><i class="fa fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:.3;"></i>請按「查詢」</td></tr></tbody></table></div>
    <div class="pager"><span></span><div class="pager-btns" id="kpi-mc-agg-pager"></div></div>
  </div>
</div>

<!-- ── 人員報工明細 ────────────────────────────────────────── -->
<div id="main-user" class="main-pane" style="display:none;">
  <div class="mc">
    <div class="mc-head">
      <h5><i class="fa fa-user" style="color:var(--accent);margin-right:6px;"></i>人員報工明細 <span id="user-agg-note" style="font-size:11px;font-weight:400;color:#888;margin-left:8px;"></span></h5>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <span style="font-size:12px;color:#888;">每頁</span>
        <select class="pp-sel" id="user-pp"><option value="10" selected>10筆</option><option value="20">20筆</option><option value="30">30筆</option></select>
        <span class="text-muted" style="font-size:12px;" id="user-pager-info"></span>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table>
        <thead><tr>
          <th>人員</th><th>部門/職稱</th><th>報工筆</th><th>良品</th><th>NG</th><th>良品率</th>
          <th class="tooltip-th" title="區間總生產工時(h)">生產工時(h)</th>
          <th class="tooltip-th" title="區間總架機工時(h)">架機工時(h)</th>
          <th class="tooltip-th" title="生產金額(元)">生產金額</th>
          <th class="tooltip-th" title="加權效率 = 總標準工時 ÷ 總實際生產工時 × 100%&#10;&#10;將區間內所有製程的標準工時（件數×每件標準秒）全部加總，&#10;再除以全部實際【生產】工時（不含架機時間）。&#10;&#10;與單筆效率簡單平均不同——&#10;工時多的製程對結果影響更大（依時間加權），&#10;更能反映人員的整體生產力。&#10;&#10;100% = 完全符合標準　&gt;100% = 快於標準　&lt;100% = 慢於標準">加權效率</th>
          <th class="tooltip-th" title="生產工時(min)÷良品數">平均工時(min/件)</th>
        </tr></thead>
        <tbody id="user-agg-tbody">
          <tr><td colspan="10" style="text-align:center;padding:30px;color:#bbb;"><i class="fa fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:.3;"></i>請按「查詢」</td></tr>
        </tbody>
      </table>
    </div>
    <div class="pager">
      <span style="font-size:12px;color:#888;"><i class="fa fa-exclamation-triangle" style="color:var(--warn);"></i> 點擊列展開詳細報工 ｜ 黃底=工時差異超過警示</span>
      <div style="display:flex;align-items:center;gap:8px;"><span class="text-muted" style="font-size:12px;" id="user-pager-info2"></span><div class="pager-btns" id="user-pager"></div></div>
    </div>
  </div>
</div>

<!-- ── 機台稼動明細 ────────────────────────────────────────── -->
<div id="main-mc" class="main-pane" style="display:none;">
  <div class="mc">
    <div class="mc-head">
      <h5><i class="fa fa-cog" style="color:var(--accent);margin-right:6px;"></i>機台稼動明細 <span id="mc-detail-note" style="font-size:11px;font-weight:400;color:#888;margin-left:8px;"></span></h5>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:12px;color:#888;">每頁</span>
        <select class="pp-sel" id="mc-pp"><option value="10" selected>10筆</option><option value="20">20筆</option><option value="30">30筆</option></select>
        <span class="text-muted" style="font-size:12px;" id="mc-pager-info"></span>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table>
        <thead><tr>
          <th>機台</th><th>報工筆</th>
          <th>良品</th><th>NG</th><th>良品率</th>
          <th class="tooltip-th" title="區間總生產工時(h)">生產工時(h)</th>
          <th class="tooltip-th" title="區間總架機工時(h)">架機工時(h)</th>
          <th class="tooltip-th" title="生產金額(元)">生產金額</th>
          <th class="tooltip-th" title="生產工時÷班別工時×100%（報工反推法）">稼動率</th>
          <th>vs 目標</th>
        </tr></thead>
        <tbody id="mc-detail-tbody">
          <tr><td colspan="10" style="text-align:center;padding:30px;color:#bbb;"><i class="fa fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:.3;"></i>請按「查詢」載入資料</td></tr>
        </tbody>
      </table>
    </div>
    <div class="pager">
      <span style="font-size:12px;color:#888;"><i class="fa fa-mouse-pointer" style="color:var(--info);"></i> 點擊列展開各料號明細</span>
      <div style="display:flex;align-items:center;gap:8px;"><span class="text-muted" style="font-size:12px;" id="mc-pager-info2"></span><div class="pager-btns" id="mc-pager"></div></div>
    </div>
  </div>
</div>
<!-- ── 生產金額 ────────────────────────────────────────────── -->
<div id="main-amount" class="main-pane" style="display:none;">
  <div class="mc">
    <div class="mc-head">
      <h5><i class="fa fa-money" style="color:var(--accent);margin-right:6px;"></i>生產金額分析</h5>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:11px;color:#888;">金額＝基礎工時×係數×倍數×基準金額/秒×良品數（齒輪乘模數×齒數×齒寬）</span>
        <span style="font-size:12px;color:#888;">每頁</span>
        <select class="pp-sel" id="amt-pp"><option value="10" selected>10筆</option><option value="20">20筆</option><option value="30">30筆</option><option value="50">50筆</option></select>
        <span class="text-muted" style="font-size:12px;" id="amt-pager-info"></span>
      </div>
    </div>
    <div style="padding:10px 14px 0;">
      <div class="amt-tab-sw">
        <button class="amt-btn active" data-amt="user" onclick="switchAmt('user',this)">👤 人員</button>
        <button class="amt-btn" data-amt="machine" onclick="switchAmt('machine',this)">🏭 機台</button>
        <button class="amt-btn" data-amt="machine_type" onclick="switchAmt('machine_type',this)">🔧 機台種類</button>
        <button class="amt-btn" data-amt="area" onclick="switchAmt('area',this)">🏭 廠別</button>
      </div>
    </div>
    <div style="overflow-x:auto;padding:0 0 14px;">
      <table id="amt-table" style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead id="amt-thead"></thead>
        <tbody id="amt-tbody"><tr><td colspan="6" style="text-align:center;padding:30px;color:#bbb;">請按「查詢」</td></tr></tbody>
      </table>
    </div>
    <div style="padding:8px 14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
      <span class="text-muted" style="font-size:12px;" id="amt-note"></span>
      <div style="display:flex;align-items:center;gap:8px;">
        <div class="pager-btns" id="amt-pager"></div>
        <button class="btn btn-default btn-sm" onclick="exportAmt()"><i class="fa fa-download"></i> 匯出 CSV</button>
      </div>
    </div>
  </div>
</div>

<!-- ── 設定分頁（子 tab 內容） ────────────────────────────── -->
<?php if($is_admin): ?>
<div id="main-setting" class="main-pane" style="display:none;">

  <div id="sub-proddept" class="sub-pane">
    <div class="setting-card" style="max-width:600px;">
      <h5><i class="fa fa-building" style="color:var(--accent);margin-right:6px;"></i>生產單位設定
        <small class="text-muted" style="font-size:12px;font-weight:400;">KPI 人員彙總與篩選只顯示這些部門的成員；部門由「組織角色綁定設定」統一維護</small>
      </h5>
      <div id="prod-dept-list" style="min-height:100px;"><div style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i></div></div>
    </div>
  </div>

  <div id="sub-machine-asset" class="sub-pane" style="display:none;">
    <div class="setting-card">
      <h5><i class="fa fa-wrench" style="color:var(--accent);margin-right:6px;"></i>機台資產設定
        <small class="text-muted" style="font-size:12px;font-weight:400;">每小時成本以 24h/天 × 30天/月計算</small>
        <button class="btn btn-xs btn-success" style="float:right;" onclick="openMachineInfoModal(null)"><i class="fa fa-plus"></i> 新增機台</button>
      </h5>
      <!-- 機台種類切換 -->
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;" id="asset-type-btns">
        <button class="btn btn-sm btn-primary" onclick="loadAssets(0,this)">全部</button>
        <?php foreach($machine_type_list as $mt): ?>
        <button class="btn btn-sm btn-default" onclick="loadAssets(<?=intval($mt['machine_type_id'])?>,this)"><?=safe($mt['machine_type'])?></button>
        <?php endforeach; ?>
      </div>
      <div style="overflow-x:auto;"><table class="table table-bordered" style="font-size:12px;margin:0;">
        <thead><tr style="background:#f8f9fa;">
          <th>機台種類</th><th>機台名稱</th><th>機台編號</th><th>現場編號</th><th>機型</th><th>狀態</th><th>停用日期(出售日期)</th>
          <th>購入日期</th><th>購入金額</th><th>殘值</th><th>年限</th><th>折舊方式</th>
          <th class="tooltip-th" title="每月折舊÷(24×30)">每小時成本</th>
          <th>已折舊年數</th><th width="100"></th>
        </tr></thead>
        <tbody id="asset-tbody"><tr><td colspan="14" style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i></td></tr></tbody>
      </table></div>
    </div>
  </div>

  <div id="sub-coeff" class="sub-pane" style="display:none;">
    <div class="row">
      <div class="col-md-4">
        <div class="setting-card">
          <h5><i class="fa fa-list" style="color:var(--accent);margin-right:6px;"></i>料號列表</h5>
          <div style="display:flex;gap:6px;margin-bottom:8px;">
            <input type="text" id="coeff-search" class="form-control input-sm" placeholder="料號 / 客戶名稱..." oninput="debSearch()">
            <button class="btn btn-default btn-sm" id="btn-unset">未設</button>
          </div>
          <div class="did-list" id="did-list"><div class="did-item" style="justify-content:center;color:#bbb;cursor:default;font-size:12px;">輸入關鍵字搜尋</div></div>
        </div>
      </div>
      <div class="col-md-8" id="coeff-panel">
        <div class="setting-card" style="min-height:200px;display:flex;align-items:center;justify-content:center;">
          <div style="text-align:center;color:#ccc;"><i class="fa fa-hand-o-left fa-2x" style="display:block;margin-bottom:10px;"></i>請從左側選擇料號</div>
        </div>
      </div>
    </div>
  </div>

  <div id="sub-groups" class="sub-pane" style="display:none;">
    <div class="setting-card">
      <h5><i class="fa fa-sitemap" style="color:var(--accent);margin-right:6px;"></i>製程群組管理
        <button class="btn btn-success btn-sm" id="btn-new-group" style="font-weight:600;"><i class="fa fa-plus"></i> 新增群組</button>
      </h5>
      <div style="overflow-x:auto;">
        <table class="table table-bordered" style="font-size:12px;margin:0;">
          <thead><tr style="background:#f8f9fa;"><th>群組名稱</th><th>代碼</th><th>對應製程</th><th>預設係數</th><th>預設工時(秒/pc)</th><th>預設金額(元/秒)</th><th>基準金額（元）</th><th>說明</th><th>計費模式</th><th width="80"></th></tr></thead>
          <tbody id="groups-tbody"><tr><td colspan="10" style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <div id="sub-kpisetting" class="sub-pane" style="display:none;">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
      <div class="setting-card" style="flex:1;min-width:260px;">
        <h5><i class="fa fa-sliders" style="color:var(--accent);margin-right:6px;"></i>KPI 標準目標值設定</h5>
        <div id="kpi-settings-form"><div style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i></div></div>
        <button class="btn btn-success" id="btn-save-kpi-settings" style="margin-top:10px;font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
      <div class="setting-card" style="flex:1;min-width:260px;">
        <h5><i class="fa fa-cog" style="color:var(--accent);margin-right:6px;"></i>相關設定</h5>
        <div id="kpi-related-settings-form"><div style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i></div></div>
        <button class="btn btn-success" id="btn-save-kpi-related-settings" style="margin-top:10px;font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
    </div>
  </div>

  <!-- ── 自動重量計算設定 ─────────────────────────────────── -->
  <div id="sub-weightrule" class="sub-pane" style="display:none;">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
      <!-- 材質密度表 -->
      <div class="setting-card" style="flex:0 0 360px;min-width:280px;">
        <h5><span><i class="fa fa-flask" style="color:var(--accent);margin-right:6px;"></i>材質密度對照表</span>
          <small class="text-muted" style="font-size:11px;font-weight:400;">（關鍵字比對，單位 g/cm³）</small>
        </h5>
        <div style="margin-bottom:10px;display:flex;align-items:center;gap:8px;">
          <label style="font-size:12px;white-space:nowrap;margin:0;font-weight:600;">關鍵字來源標籤：</label>
          <select class="form-control input-sm" id="density-keyword-label" style="max-width:200px;">
            <option value="">（不指定，使用規則材質標籤）</option>
          </select>
        </div>
        <p style="font-size:11px;color:#888;margin-bottom:8px;">系統會自動比對該標籤下所有子標籤的值。優先匹配順序由上至下。</p>
        <table class="table table-bordered" style="font-size:12px;margin:0;" id="density-table">
          <thead><tr style="background:#f8f9fa;"><th>子標籤</th><th style="width:110px;">密度 (g/cm³)</th><th style="width:34px;"></th></tr></thead>
          <tbody id="density-tbody"></tbody>
        </table>
        <div style="margin-top:8px;display:flex;gap:6px;">
          <button class="btn btn-default btn-sm" onclick="addDensityRow()"><i class="fa fa-plus"></i> 新增</button>
          <button class="btn btn-success btn-sm" onclick="saveDensities()"><i class="fa fa-save"></i> 儲存密度表</button>
        </div>
      </div>
      <!-- 重量計算規則 + 試算面板（同欄） -->
      <div style="flex:1;min-width:320px;display:flex;flex-direction:column;gap:14px;">
        <div class="setting-card">
          <h5 style="display:flex;align-items:center;justify-content:space-between;"><span><i class="fa fa-balance-scale" style="color:var(--accent);margin-right:6px;"></i>重量計算規則</span>
            <span style="display:flex;gap:6px;flex-shrink:0;">
              <button class="btn btn-info btn-sm" onclick="toggleWeightPreview()" style="font-weight:600;"><i class="fa fa-calculator"></i> 試算重量</button>
              <button class="btn btn-success btn-sm" onclick="openWeightRuleModal(0)" style="font-weight:600;"><i class="fa fa-plus"></i> 新增規則</button>
            </span>
          </h5>
          <p style="font-size:11px;color:#888;margin-bottom:8px;">
            圓柱公式：W(kg) = π/4 × D²(mm²) × L(mm) × ρ(g/cm³) ÷ 1,000,000<br>
            若多條規則同時符合，取計算結果最重者。
          </p>
          <table class="table table-bordered" style="font-size:12px;margin:0;">
            <thead><tr style="background:#f8f9fa;"><th>規則名稱</th><th>條件標籤</th><th>直徑 D</th><th>長度 L</th><th>密度來源</th><th width="80"></th></tr></thead>
            <tbody id="weightrule-tbody"><tr><td colspan="6" style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i></td></tr></tbody>
          </table>
        </div>

        <!-- ── 重量試算面板 ──────────────────────────────────── -->
        <div id="weight-preview-panel" style="display:none;">
          <div class="setting-card" style="box-sizing:border-box;">
            <h5 style="margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;">
              <span><i class="fa fa-calculator" style="color:var(--accent);margin-right:6px;"></i>重量試算</span>
              <button type="button" class="btn btn-xs btn-default" onclick="$('#weight-preview-panel').hide();">✕ 關閉</button>
            </h5>
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
              <div style="position:relative;">
                <input type="text" id="wp-part-input" class="form-control" placeholder="輸入料號搜尋..." style="width:260px;" autocomplete="off">
              </div>
              <button class="btn btn-primary" onclick="doWeightPreview()"><i class="fa fa-search"></i> 試算</button>
              <span style="font-size:11px;color:#888;">料號本身有重量資料（d_setting.Weight_Kg）者優先採用</span>
            </div>
            <div id="wp-result"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>
<?php endif; ?>


</div><!-- /right_col -->
</div></div><!-- /main_container /container body -->

<!-- ══ 圖面 Modal ══════════════════════════════════════════ -->
<div class="modal fade" id="drawing-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content" style="height:88vh;">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title" id="dw-title"><i class="fa fa-image"></i> 圖面檢視</h4>
      </div>
      <div class="modal-body dw-body">
        <div class="dw-sidebar" id="dw-file-list"><div style="text-align:center;padding:20px;color:#aaa;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div></div>
        <div class="dw-viewer" id="dw-viewer"><div style="color:#aaa;text-align:center;font-size:12px;"><i class="fa fa-file-image-o fa-3x" style="display:block;margin-bottom:10px;"></i>選擇檔案預覽</div></div>
      </div>
    </div>
  </div>
</div>

<!-- ══ 製程群組 Modal ══════════════════════════════════════ -->
<div class="modal fade" id="group-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">製程群組設定</h4></div>
      <div class="modal-body">
        <input type="hidden" id="gm-id">
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label>群組名稱 <span class="text-danger">*</span></label><input type="text" class="form-control" id="gm-name" placeholder="例：滾齒"></div></div>
          <div class="col-sm-3"><div class="form-group"><label>代碼 <span class="text-danger">*</span></label><input type="text" class="form-control" id="gm-code" placeholder="例：hobbing"></div></div>
          <div class="col-sm-2"><div class="form-group"><label>排序</label><input type="number" class="form-control" id="gm-sort" value="0" min="0"></div></div>
          <div class="col-sm-3"><div class="form-group"><label>說明</label><input type="text" class="form-control" id="gm-desc"></div></div>
        </div>
        <!-- 預設難易係數 + 基準金額（共用） -->
        <div class="row" id="gm-coeff-row">
          <div class="col-sm-4"><div class="form-group"><label>預設難易係數（1~10）</label><input type="number" class="form-control" id="gm-def-coeff" min="1" max="10" step="0.1" placeholder="1.0"></div></div>
          <div class="col-sm-4"><div class="form-group"><label>基準金額（元）<small class="text-muted">（供公式/KPI參數使用）</small></label><input type="number" class="form-control" id="gm-base-amount" min="0" step="0.0001" placeholder="例：500"></div></div>
        </div>
        <!-- 工時計費（主要，有報工紀錄時優先採用） -->
        <div style="background:#f0f8f0;border:1px solid #b7dbb7;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
          <div style="font-weight:700;font-size:12px;color:#1a6b1a;margin-bottom:8px;"><i class="fa fa-clock-o"></i> 工時計費設定 <small style="font-weight:400;color:#555;">（有報工紀錄時優先採用）</small></div>
          <div class="row" style="margin:0;">
            <div class="col-sm-6"><div class="form-group" style="margin-bottom:6px;"><label style="font-size:12px;">每PCS基礎工時（秒）</label><input type="number" class="form-control" id="gm-base-time" min="0" step="0.01" placeholder="例：120"></div></div>
            <div class="col-sm-6"><div class="form-group" style="margin-bottom:6px;"><label style="font-size:12px;">基準金額（元/秒）</label><input type="number" class="form-control" id="gm-base-price" min="0" step="0.0001" placeholder="例：0.5"></div></div>
          </div>
        </div>
        <!-- 無工時時備用 -->
        <div style="background:#f8f0ff;border:1px solid #d4b0f0;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
          <div style="font-weight:700;font-size:12px;color:#5b21b6;margin-bottom:8px;"><i class="fa fa-calculator"></i> 無工時紀錄時採用</div>
          <div style="display:flex;gap:20px;margin-bottom:8px;">
            <label style="font-weight:400;cursor:pointer;margin:0;font-size:13px;"><input type="radio" name="gm-fallback" value="formula" onchange="switchFallbackMode()"> 自訂公式</label>
            <label style="font-weight:400;cursor:pointer;margin:0;font-size:13px;"><input type="radio" name="gm-fallback" value="fixed" onchange="switchFallbackMode()"> 固定金額（元/PCS）</label>
          </div>
          <!-- Section: 固定金額 -->
          <div id="gm-section-fixed" style="display:none;">
            <div class="row" style="margin:0;">
              <div class="col-sm-5"><div class="form-group" style="margin-bottom:6px;"><label style="font-size:12px;">固定金額（元/PCS）<span class="text-danger"> *</span></label><input type="number" class="form-control" id="gm-fixed-price" min="0" step="0.01" placeholder="例：15.00"></div></div>
            </div>
          </div>
          <!-- Section: 自訂公式 -->
          <div id="gm-section-formula">
          <div style="background:#f0f8ff;border:1px solid #c8e0f8;border-radius:6px;padding:12px;margin-bottom:12px;">
            <label style="font-weight:700;color:#1a5c9e;margin-bottom:8px;display:block;"><i class="fa fa-calculator"></i> 公式建立器</label>
            <div style="font-size:12px;color:#555;margin-bottom:6px;">步驟 1：定義公式變數，選擇對應的料號標籤或KPI系統參數</div>
            <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:6px;" id="formula-vars-table">
              <thead><tr style="background:#e8f0fb;">
                <th style="padding:4px 8px;width:40px;">變數</th>
                <th style="padding:4px 8px;">類型</th>
                <th style="padding:4px 8px;">對應欄位</th>
                <th style="padding:4px 8px;width:36px;"></th>
              </tr></thead>
              <tbody id="formula-vars-tbody"><tr><td colspan="4" style="text-align:center;padding:8px;color:#aaa;font-size:11px;">尚無變數，請點選「新增變數」</td></tr></tbody>
            </table>
            <button class="btn btn-xs btn-default" onclick="addFormulaVar()"><i class="fa fa-plus"></i> 新增變數</button>
            <hr style="margin:10px 0;">
            <div style="font-size:12px;color:#555;margin-bottom:6px;">步驟 2：輸入計算公式（使用上方變數名稱，支援四則運算及 IF/OR/AND 條件）</div>
            <input type="text" class="form-control" id="gm-formula-expr" placeholder="例：A×B÷C+D　或　IF(A>=500,A×B,A×B+C)" oninput="updateFormulaPreview()" autocomplete="off" style="font-family:monospace;margin-bottom:6px;">
            <div id="gm-formula-preview" style="min-height:24px;font-size:12px;padding:6px 10px;border-radius:4px;background:#f8f9fa;color:#333;"></div>
            <div style="margin-top:6px;font-size:11px;color:#888;line-height:1.7;">
              <i class="fa fa-info-circle"></i> <strong>四則運算：</strong>+ − × ÷ ( )，× 和 * 均可，÷ 和 / 均可<br>
              <i class="fa fa-code" style="visibility:hidden;"></i> <strong>條件函式：</strong>
              <code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;">IF(條件, 成立值, 不成立值)</code>
              <code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;">OR(條件1, 條件2)</code>
              <code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;">AND(條件1, 條件2)</code><br>
              <i class="fa fa-code" style="visibility:hidden;"></i> <strong>其他函式：</strong>
              <code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;">MAX(值1, 值2)</code>
              <code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;">MIN(值1, 值2)</code><br>
              <i class="fa fa-code" style="visibility:hidden;"></i> <strong>比較符號：</strong>&gt;= &lt;= &gt; &lt; = !=　範例：<code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;">IF(A&gt;=500, A×B, A×B+C)</code>
            </div>
          </div>
        </div>
        </div><!-- /無工時備用 box -->
        <!-- 基本費用 -->
        <div class="form-group" style="margin-bottom:10px;">
          <label style="font-weight:700;margin-bottom:6px;display:block;">基本費用 <small class="text-muted" style="font-weight:400;">（可設定多筆，例如架機費用）</small></label>
          <div id="gm-setup-costs" style="margin-bottom:6px;"></div>
          <button type="button" class="btn btn-xs btn-default" onclick="addSetupCostRow()"><i class="fa fa-plus"></i> 新增費用</button>
        </div>
        <!-- 對應製程 -->
        <div class="form-group">
          <label>對應製程（點選切換）</label>
          <div style="margin-bottom:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            <input type="text" id="gm-proc-search" class="form-control" style="width:150px;height:28px;padding:2px 8px;font-size:12px;display:inline-block;" placeholder="搜尋製程..." oninput="filterProcTags()">
            <div id="gm-proc-type-filters" style="display:inline-flex;gap:4px;flex-wrap:wrap;"></div>
          </div>
          <div id="gm-proc-tags" style="min-height:40px;border:1px solid var(--border);border-radius:6px;padding:8px;background:#fafbfc;"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-success" id="btn-save-group" style="font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 機台資產 Modal ══════════════════════════════════════ -->
<div class="modal fade" id="asset-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-wrench"></i> 機台資產設定 — <span id="am-name"></span></h4></div>
      <div class="modal-body">
        <input type="hidden" id="am-mid">
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>購入日期 <span class="text-danger">*</span></label><input type="date" class="form-control" id="am-pdate" oninput="calcHourlyCost()"></div></div>
          <div class="col-sm-6"><div class="form-group"><label>購入金額（元）</label><input type="number" class="form-control" id="am-pamt" min="0" step="1" oninput="calcHourlyCost()"></div></div>
        </div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>殘值（元）</label><input type="number" class="form-control" id="am-rval" min="0" step="1" value="0" oninput="calcHourlyCost()"></div></div>
          <div class="col-sm-6"><div class="form-group"><label>攤提年限（年）</label><input type="number" class="form-control" id="am-years" min="1" max="30" step="1" value="5" oninput="calcHourlyCost()"></div></div>
        </div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>折舊方式</label>
            <select class="form-control" id="am-meth" onchange="calcHourlyCost()">
              <option value="straight">直線法（每年均攤）</option>
              <option value="double_declining">雙倍餘額遞減法</option>
              <option value="sum_of_years">年數合計法</option>
            </select></div></div>
          <div class="col-sm-6"><div class="form-group"><label>每月工作小時（參考用）</label><input type="number" class="form-control" id="am-mhrs" min="1" value="720"></div></div>
        </div>
        <div class="form-group"><label>備註</label><input type="text" class="form-control" id="am-rem"></div>
        <div style="background:#f0fbf8;padding:8px 12px;border-radius:6px;font-size:12px;color:#0e7a5e;" id="am-cost-preview">（請先填入購入金額）</div>
        <div style="margin-top:6px;font-size:11px;color:#888;">※ 每小時成本 = 每月折舊 ÷ (24 × 30)，以全天候24小時計算</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-success" onclick="saveAsset()" style="font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 機台資料 Modal (機台管理：名稱/類型/機台編號/現場編號/機型/規格/備註) ══════ -->
<div class="modal fade" id="machine-info-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-cog"></i> <span id="mi-title">新增機台</span></h4></div>
      <div class="modal-body">
        <input type="hidden" id="mi-mid">
        <div class="form-group"><label>機台名稱 <span class="text-danger">*</span></label><input type="text" class="form-control" id="mi-name"></div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>機台編號 <span class="text-muted">(公司財產編號)</span></label><input type="text" class="form-control" id="mi-asset-no"></div></div>
          <div class="col-sm-6"><div class="form-group"><label>現場自訂編號</label><input type="text" class="form-control" id="mi-field-no"></div></div>
        </div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>機型</label><input type="text" class="form-control" id="mi-model"></div></div>
          <div class="col-sm-6"><div class="form-group"><label>機台類型 <span class="text-danger">*</span></label>
            <select class="form-control" id="mi-type">
              <option value="">-- 請選擇 --</option>
              <?php foreach($machine_type_list as $mt): ?><option value="<?=safe($mt['machine_type_id'])?>"><?=safe($mt['machine_type'])?></option><?php endforeach; ?>
            </select></div></div>
        </div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>位置 (廠別)</label><input type="text" class="form-control" id="mi-position" placeholder="例如: 1"></div></div>
          <div class="col-sm-6"><div class="form-group"><label>是否需要架機</label>
            <select class="form-control" id="mi-need-setup"><option value="1">需要 (1)</option><option value="0">不需要 (0)</option></select></div></div>
        </div>
        <div class="form-group"><label>規格</label><input type="text" class="form-control" id="mi-spec"></div>
        <div class="form-group"><label>備註</label><textarea class="form-control" id="mi-note" rows="2"></textarea></div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label><input type="checkbox" id="mi-state" onchange="$('#mi-disabled-date-grp').toggle(this.checked); if(this.checked && !$('#mi-disabled-date').val()) $('#mi-disabled-date').val(new Date().toISOString().slice(0,10));"> 停用</label></div></div>
          <div class="col-sm-6" id="mi-disabled-date-grp" style="display:none;"><div class="form-group"><label>停用日期(出售日期)</label><input type="date" class="form-control" id="mi-disabled-date"></div></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger" id="mi-delete-btn" style="float:left;" onclick="deleteMachineInfo()"><i class="fa fa-trash"></i> 刪除機台</button>
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-success" onclick="saveMachineInfo()" style="font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 快速設定 Modal ══════════════════════════════════════ -->
<div class="modal fade" id="qs-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-bolt"></i> 快速設定每PCS工時</h4></div>
      <div class="modal-body">
        <div style="background:#f0fbf8;padding:10px 12px;border-radius:6px;margin-bottom:12px;font-size:13px;">
          <strong>料號：</strong><span id="qs-part"></span> &nbsp;|&nbsp;
          <strong>製程：</strong><span id="qs-proc"></span>
        </div>
        <!-- 工時參考值選擇區 -->
        <div style="margin-bottom:12px;">
          <label style="font-size:12px;font-weight:700;color:var(--primary);display:block;margin-bottom:6px;">選擇參考工時（點選自動帶入）</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;" id="qs-ref-btns">
            <!-- 動態產生 -->
          </div>
        </div>
        <div class="row">
          <div class="col-sm-6">
            <div class="form-group">
              <label>每PCS基礎工時（秒） <span class="text-danger">*</span></label>
              <input type="number" class="form-control" id="qs-base-sec" min="0" step="0.01" placeholder="秒/件">
              <small class="text-muted" id="qs-sec-hint"></small>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="form-group">
              <label>難易係數 (1~10)</label>
              <input type="number" class="form-control" id="qs-coeff" min="1" max="10" step="0.1" value="1.0">
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>倍數（複雜度）</label><input type="number" class="form-control" id="qs-multi" min="0" step="0.0001" value="1.0000"></div></div>
          <div class="col-sm-6"><div class="form-group"><label>基準金額（元/秒，空=保留原值）</label><input type="number" class="form-control" id="qs-price" min="0" step="0.0001" placeholder="空=使用群組預設"></div></div>
        </div>
        <div class="form-group"><label>備註</label><input type="text" class="form-control" id="qs-rem"></div>
        <div style="background:#fff8e1;padding:8px 12px;border-radius:6px;font-size:12px;color:#a06000;">
          <i class="fa fa-info-circle"></i> 儲存後將把選定工時（秒）寫入此料號×此製程群組的每PCS基礎工時。
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-success" onclick="saveQuickSet()" style="font-weight:600;"><i class="fa fa-bolt"></i> 確認設定</button>
      </div>
    </div>

  </div>
</div>

<!-- ══ 重量計算規則 Modal ══════════════════════════════════ -->
<div class="modal fade" id="weightrule-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-balance-scale"></i> 重量計算規則</h4></div>
      <div class="modal-body">
        <input type="hidden" id="wr-id">
        <div class="form-group">
          <label>規則名稱 <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="wr-name" placeholder="例：軸件重量計算">
        </div>
        <div class="form-group">
          <label>觸發條件標籤 <small class="text-muted">（兩組都需符合才觸發；不選代表不限）</small></label>
          <div style="font-size:11px;color:#1a56db;font-weight:600;margin-bottom:3px;">全部符合 (AND)：</div>
          <div id="wr-cond-and-checkboxes" style="max-height:90px;overflow-y:auto;border:1px solid #c3d4f0;border-radius:4px;padding:5px 6px;background:#f5f8ff;margin-bottom:8px;">
            <div style="color:#aaa;font-size:12px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>
          </div>
          <div style="font-size:11px;color:#b45309;font-weight:600;margin-bottom:3px;">任一符合 (OR)：</div>
          <div id="wr-cond-or-checkboxes" style="max-height:90px;overflow-y:auto;border:1px solid #f0d9b0;border-radius:4px;padding:5px 6px;background:#fffbf0;">
            <div style="color:#aaa;font-size:12px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>
          </div>
        </div>
        <!-- 主體計算模式 -->
        <div class="form-group" style="margin-bottom:8px;">
          <label style="font-weight:700;">主體截面模式</label>
          <div style="display:flex;gap:20px;">
            <label style="font-weight:400;cursor:pointer;margin:0;font-size:13px;"><input type="radio" name="wr-body-mode" value="simple" onchange="switchWrBodyMode()"> 簡單（單一 D × L，取最大 D）</label>
            <label style="font-weight:400;cursor:pointer;margin:0;font-size:13px;"><input type="radio" name="wr-body-mode" value="multi" onchange="switchWrBodyMode()"> 多段截面（各段 D × L 加總）</label>
          </div>
        </div>
        <!-- 簡單模式 -->
        <div id="wr-body-simple">
          <div class="form-group">
            <label style="font-size:12px;color:#555;">直徑 D 來源 <small>（多個來源取最大值）</small></label>
            <div id="wr-d-sources"></div>
            <button type="button" class="btn btn-xs btn-default" onclick="addWrDSource()" style="margin-top:4px;"><i class="fa fa-plus"></i> 新增來源</button>
          </div>
          <div class="row">
            <div class="col-sm-3"><div class="form-group"><label style="font-size:12px;color:#555;">長度 L 來源</label>
              <select class="form-control" id="wr-l-type" onchange="toggleWrFields()">
                <option value="label">標籤</option><option value="gear">齒輪規格</option>
              </select></div></div>
            <div class="col-sm-6" id="wr-l-label-wrap"><div class="form-group"><label style="font-size:12px;color:#555;">長度標籤</label>
              <select class="form-control" id="wr-l-label" onchange="updateWrSubLabels('l')"><option value="">(選擇標籤)</option></select>
              <div style="display:flex;gap:4px;margin-top:4px;">
                <select class="form-control" id="wr-l-sub" style="display:none;"><option value="">(父標籤本身)</option></select>
                <select class="form-control input-sm" id="wr-l-dim" style="width:88px;height:34px;padding:2px 4px;">
                  <option value="">輸入值</option><option value="draw">圖面尺寸</option>
                  <option value="lathe">車床尺寸</option><option value="max">最大值</option>
                </select>
              </div>
            </div></div>
            <div class="col-sm-3" id="wr-l-gear-wrap" style="display:none;"><div class="form-group"><label style="font-size:12px;color:#555;">長度齒輪欄位</label>
              <select class="form-control" id="wr-l-gear"></select>
            </div></div>
          </div>
        </div>
        <!-- 多段截面模式 -->
        <div id="wr-body-multi" style="display:none;">
          <div style="font-size:12px;color:#555;margin-bottom:6px;">每段截面單獨計算體積後加總。圓柱：π/4×D²×L；環形柱：π/4×(D1²−D2²)×L</div>
          <div id="wr-body-sections"></div>
          <button type="button" class="btn btn-xs btn-default" onclick="addWrBodySection()"><i class="fa fa-plus"></i> 新增截面</button>
        </div>
        <!-- 減項設定 -->
        <div class="form-group" style="margin-top:10px;">
          <label style="font-weight:700;">減項設定 <small class="text-muted" style="font-weight:400;">（從主體體積中扣除，如內孔、外徑車除差）</small></label>
          <div id="wr-deductions" style="margin-bottom:6px;"></div>
          <button type="button" class="btn btn-xs btn-default" onclick="addWrDeduction()"><i class="fa fa-plus"></i> 新增減項</button>
        </div>
        <div class="form-group">
          <label>密度來源</label>
          <div style="display:flex;gap:20px;">
            <label style="font-weight:400;cursor:pointer;margin:0;"><input type="radio" name="wr-density-src" value="material" onchange="toggleWrFields()"> 自動查密度表（依左側關鍵字來源標籤）</label>
            <label style="font-weight:400;cursor:pointer;margin:0;"><input type="radio" name="wr-density-src" value="fixed" onchange="toggleWrFields()"> 固定密度</label>
          </div>
        </div>
        <div id="wr-fixed-wrap" class="form-group" style="display:none;">
          <label>固定密度 (g/cm³) <span class="text-danger">*</span></label>
          <input type="number" class="form-control" id="wr-fixed-density" min="0" step="0.0001" placeholder="例：7.85">
        </div>
        <div class="form-group">
          <label>排序</label>
          <input type="number" class="form-control" id="wr-sort" value="0" min="0" style="width:100px;">
        </div>
        <div style="background:#f0fbf4;border:1px solid #b8e6d0;border-radius:6px;padding:8px 12px;font-size:11px;color:#0a7c58;">
          <i class="fa fa-info-circle"></i> 公式：W(kg) = π/4 × D²(mm) × L(mm) × ρ(g/cm³) ÷ 1,000,000<br>
          若標籤為多值標籤（圓×深），自動取較大值作為 D（外徑）
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-success" onclick="saveWeightRule()" style="font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
    </div>
  </div>
</div>

<div id="toast-wrap"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
// ── 全域工具 ──────────────────────────────────────────────────
function showToast(msg,ok){var $t=$('<div class="toast-msg '+(ok===false?'error':'success')+'">'+msg+'</div>');$('#toast-wrap').append($t);setTimeout(function(){$t.fadeOut(300,function(){$t.remove();});},2800);}
// post()：統一 AJAX 封裝，含 30 秒逾時與友善錯誤訊息
function post(data,cb){
    $.ajax({
        url: 'kpi_main.php',
        type: 'POST',
        data: data,
        dataType: 'json',
        timeout: 30000,
        success: cb,
        error: function(xhr, status, err){
            var msg = (status === 'timeout')
                ? '查詢逾時（超過30秒），請縮短日期範圍後再試'
                : '連線失敗，請重新整理頁面後再試';
            showToast(msg, false);
        }
    });
}
function fmtN(n){return(n===null||n===undefined)?'—':Number(n).toLocaleString();}
function fmtH(h){return(h===null||h===undefined||h==='')?'—':parseFloat(h).toFixed(2)+'h';}
function fmtPct(p){return(p===null||p===undefined)?'—':p+'%';}

// ── 二層分頁切換 ─────────────────────────────────────────────
var curMain = 'kpi';
var curSub  = 'proddept';
var amtView = 'user';

function switchMain(id, btn){
    curMain = id;
    $('.main-pane').hide().removeClass('active');
    $('#main-'+id).show().addClass('active');
    $('.tab-btn[data-main]').removeClass('active');
    $(btn).addClass('active');

    // 子 tab 列只在設定時顯示
    $('#sub-tabs-setting').toggle(id==='setting');

    // 篩選列控制
    var noFbar = ['setting'];
    if(noFbar.indexOf(id)>=0){
        $('#global-fbar').hide();
    } else {
        $('#global-fbar').show();
        if(id==='user'){
            // 部門 > 人員 > 料號
            $('#fg-mc-type-wrap,#fg-machine-wrap').hide();
            $('#fg-dept-wrap,#fg-user-wrap,#fg-partno-wrap').show();
        } else if(id==='mc'){
            // 機台種類 > 機台 > 料號
            $('#fg-dept-wrap,#fg-user-wrap').hide();
            $('#fg-mc-type-wrap,#fg-machine-wrap,#fg-partno-wrap').show();
        } else {
            // KPI/生產金額：部門 > 人員 > 機台種類 > 機台 > 料號
            $('#fg-dept-wrap,#fg-user-wrap,#fg-mc-type-wrap,#fg-machine-wrap,#fg-partno-wrap').show();
        }
        var _hp=$('#kpi-partno').val().trim().length>0;
        $("#btn-clear-partno").toggle(_hp);
    }

    // 切換時自動觸發載入
    if(id==='kpi')    loadKpiDashboard();
    if(id==='user')   loadUserAgg(1);
    if(id==='mc')     loadMcAgg(1);
    if(id==='amount') loadAmtPage(1);
    if(id==='setting')switchSub(curSub, document.querySelector('.sub-btn.active')||document.querySelector('.sub-btn'));
}

function switchSub(id, btn){
    curSub = id;
    $('.sub-pane').hide().removeClass('active');
    $('#sub-'+id).show().addClass('active');
    $('.sub-btn').removeClass('active');
    if(btn)$(btn).addClass('active');

    if(id==='proddept')      loadProdDepts();
    if(id==='machine-asset') loadAssets();
    if(id==='groups')        loadGroups();
    if(id==='kpisetting')    loadKpiSettings();
    if(id==='weightrule')    loadWeightRules();
}

// 舊 switchTab 保留相容性（避免其他呼叫炸掉）
function switchTab(id,btn){ switchMain(id,btn); }
function switchTabDirect(id){ var $b=$('.tab-btn[data-main="'+id+'"]'); switchMain(id,$b.length?$b[0]:document.createElement('button')); }
function detailByUser(uid){ $('#kpi-user').val(uid); switchMain('user',document.querySelector('[data-main="user"]')); }
function detailByMc(mid){  $('#kpi-machine').val(mid); switchMain('mc',document.querySelector('[data-main="mc"]')); }
function switchAmt(v,btn){ amtView=v; $('.amt-btn').removeClass('active');$(btn).addClass('active'); loadAmtPage(1); }

// ── 日期工具 ─────────────────────────────────────────────────
function localDateStr(d){
    // 用本地時間格式化，避免 UTC toISOString 因時區差造成跨日
    var y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),dd=String(d.getDate()).padStart(2,'0');
    return y+'-'+m+'-'+dd;
}
function setQDate(type,btn){
    $('.qd-btn').removeClass('active');$(btn).addClass('active');
    var now=new Date(),y=now.getFullYear(),m=now.getMonth(),from,to=localDateStr(now);
    if(type==='month'){from=localDateStr(new Date(y,m,1));}
    else if(type==='week'){var wd=now.getDay()||7;var mon=new Date(now);mon.setDate(now.getDate()-(wd-1));from=localDateStr(mon);}
    else if(type==='quarter'){var q=Math.floor(m/3);from=localDateStr(new Date(y,q*3,1));}
    else if(type==='year'){from=y+'-01-01';}
    else{$('#fg-from,#fg-to').show();return;}
    $('#fg-from,#fg-to').hide();$('#kpi-df').val(from);$('#kpi-dt').val(to);
}
function getDates(){
    var now=new Date();
    return {
        df: $('#kpi-df').val()||localDateStr(new Date(now.getFullYear(),now.getMonth(),1)),
        dt: $('#kpi-dt').val()||localDateStr(now)
    };
}

// 部門篩選 → 篩選人員 select
var userListAll = <?php echo json_encode(array_map(function($u){return ['id'=>$u['id'],'name'=>$u['user_cname'],'dept'=>$u['dept_name'],'dept_id'=>intval($u['dept_id'])];}, $user_list)); ?>;
var deptListAll = <?php echo json_encode($dept_list); ?>;
var machineListAll = <?php echo json_encode(array_map(function($m){return ['id'=>$m['machine_id'],'name'=>$m['machine'],'type_id'=>intval($m['machine_type_id']??0)];}, $machine_list)); ?>;

// 機台種類 → 過濾機台下拉
function filterMachineByType(){
    var typeId = parseInt($('#kpi-mc-type').val())||0;
    var $sel = $('#kpi-machine');
    var cur = $sel.val();
    $sel.find('option:not(:first)').remove();
    machineListAll.forEach(function(m){
        if(typeId && m.type_id !== typeId) return;
        $sel.append('<option value="'+m.id+'">'+m.name+'</option>');
    });
    if(cur) $sel.val(cur);
}

// 依 prodDeptIds 決定人員/部門下拉是否過濾
function applyProdFilter(){
    var $deptSel = $('#kpi-dept');
    var curDept = $deptSel.val();
    $deptSel.find('option:not(:first)').remove();
    deptListAll.forEach(function(d){
        // 若 prodDeptIds 有設定，只顯示設定內的部門
        if(prodDeptIds.length>0 && prodDeptIds.indexOf(d.id)<0 && prodDeptIds.indexOf(parseInt(d.id))<0) return;
        $deptSel.append('<option value="'+d.id+'">'+d.name+'</option>');
    });
    if(curDept) $deptSel.val(curDept);
    filterUserByDept();
}
function filterUserByDept(){
    var deptId = parseInt($('#kpi-dept').val())||0;
    var $sel = $('#kpi-user');
    var cur = $sel.val();
    $sel.find('option:not(:first)').remove();
    userListAll.forEach(function(u){
        // 若 prodDeptIds 有設定，過濾非生產部門人員
        if(prodDeptIds.length>0 && prodDeptIds.indexOf(u.dept_id)<0 && prodDeptIds.indexOf(String(u.dept_id))<0) return;
        // 若有選部門，只顯示該部門（精確 dept_id 比對）
        if(deptId && u.dept_id !== deptId) return;
        $sel.append('<option value="'+u.id+'">'+u.name+(u.dept?' ('+u.dept+')':'')+'</option>');
    });
    // 恢復原本選中值（若還在列表中）
    if(cur && $sel.find('option[value="'+cur+'"]').length) $sel.val(cur);
    else $sel.val('');
}

// ── 查詢主入口 ───────────────────────────────────────────────
function onSearch(){
    if(curMain==='kpi')    loadKpiDashboard();
    if(curMain==='user')   loadUserAgg(1);
    if(curMain==='mc')     loadMcAgg(1);
    if(curMain==='amount') loadAmtPage(1);
}

// ══ 人員報工彙總（每人一列） ══════════════════════════════════
function loadUserAgg(page){
    var d=getDates(),uid=$('#kpi-user').val(),did=$('#kpi-dept').val(),mtid=$('#kpi-mc-type').val(),pp=parseInt($('#user-pp').val())||10;
    var partno=$('#kpi-partno').val();
    var off=(page-1)*pp;
    var aggDone=false, amtDone=false;
    var aggData=null, amtMap={};

    $('#user-agg-tbody').html('<tr><td colspan="10" style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    
    function render(){
        if(!aggDone || !amtDone) return;
        var total=aggData.length;
        var paged=aggData.slice(off,off+pp);
        var tb='';
        paged.forEach(function(r){
            var yr=(r.yield_rate!==null&&r.yield_rate!==undefined)?r.yield_rate+'%':'—';
            var ybc=r.yield_rate===null?'':r.yield_rate>=98?'cbadge-ok':r.yield_rate>=90?'cbadge-warn':'cbadge-ng';
            var eff=(r.efficiency!==null&&r.efficiency!==undefined)?r.efficiency+'%':'—';
            var effBc=r.efficiency===null?'':r.efficiency>=100?'cbadge-ok':r.efficiency>=80?'cbadge-warn':'cbadge-ng';
            var avg=(r.avg_min_per_pc!==null&&r.avg_min_per_pc!==undefined)?parseFloat(r.avg_min_per_pc).toFixed(2)+' min':'—';
            var amt = amtMap[r.user_id] !== undefined ? '<strong style="color:var(--primary);">' + fmtN(Math.round(amtMap[r.user_id])) + '</strong>' : '<span style="color:#ccc;">—</span>';
            tb+='<tr class="user-agg-row" data-uid="'+r.user_id+'" data-partno="'+partno+'" style="cursor:pointer;transition:.1s;" title="點擊展開報工明細">'
              +'<td><strong>'+(r.label||'—')+'</strong></td>'
              +'<td class="dept-pos">'+(r.dept_name||'')+(r.pos_name?' · '+r.pos_name:'')+'</td>'
              +'<td>'+fmtN(r.report_count)+'</td>'
              +'<td>'+fmtN(r.total_ok)+'</td>'
              +'<td>'+(parseInt(r.total_ng)>0?'<span class="cbadge cbadge-ng">'+fmtN(r.total_ng)+'</span>':'0')+'</td>'
              +'<td>'+(ybc?'<span class="cbadge '+ybc+'">'+yr+'</span>':yr)+'</td>'
              +'<td>'+fmtH(r.prod_hrs)+'</td>'
              +'<td>'+fmtH(r.setup_hrs)+'</td>'
              +'<td>'+amt+'</td>'
              +'<td>'+(effBc?'<span class="cbadge '+effBc+'">'+eff+'</span>':eff)+'</td>'
              +'<td><strong>'+avg+'</strong></td>'
              +'</tr>';
        });
        $('#user-agg-tbody').html(tb||'<tr><td colspan="10" style="text-align:center;padding:20px;color:#aaa;">無資料</td></tr>');
        $('#user-agg-note').text('(共 '+total+' 人)');
        $('#user-pager-info,#user-pager-info2').text('共 '+total+' 人');
        renderPager('user-pager',page,total,pp,loadUserAgg);
    }
    $('#kpi-partno').off('dblclick').on('dblclick', function(){ $(this).val('').trigger('keypress'); resetFilters(); });
    post({action:'get_kpi_user_agg',date_from:d.df,date_to:d.dt,user_id:uid,dept_id:did,machine_type_id:mtid,part_no:partno},function(res){
        if(!res.success){$('#user-agg-tbody').html('<tr><td colspan="11" style="text-align:center;color:var(--danger);padding:20px;">載入失敗</td></tr>');return;}
        aggData=res.data; aggDone=true; render();
    });
    post({action:'get_production_amount',date_from:d.df,date_to:d.dt,user_id:uid,dept_id:did,machine_type_id:mtid,part_no:partno,view:'user'},function(res){
        if(res.success) res.data.forEach(function(r){ amtMap[r.key]=r.amount; });
        amtDone=true; render();
    });
}

// 點擊人員彙總列展開明細
$(document).on('click','.user-agg-row',function(){
    var uid=$(this).data('uid');
    var expandId='expand-user-'+uid;
    var $exist=$('#'+expandId);
    if($exist.length){$exist.toggle();return;}
    var d=getDates();
    var partno = $(this).data('partno') || '';
    var $tr=$('<tr id="'+expandId+'" class="expand-row"><td colspan="11"><div class="expand-inner"><i class="fa fa-spinner fa-spin"></i> 載入明細...</div></td></tr>');
    $(this).after($tr);
    loadUserExpandDetail(uid,d.df,d.dt,1,$tr,partno);
});
function loadUserExpandDetail(uid,df,dt,page,$tr,partno){
    post({action:'get_kpi_user_detail_expand',user_id:uid,date_from:df,date_to:dt,page:page,part_no:partno},function(res){
        if(!res.success){$tr.find('.expand-inner').html('<span class="text-danger">載入失敗：'+(res.message||'未知錯誤')+'</span>');return;}
        var pp=res.per_page||10;
        var th='<thead><tr style="background:#e8f8f5;">'
          +'<th style="padding:5px 7px;">日期</th><th>料號</th><th>製程</th><th>機台</th>'
          +'<th>良品</th><th>NG</th><th>良品率</th>'
          +'<th>生產時段</th>'
          +'<th class="tooltip-th" title="TIMESTAMPDIFF(production_end,production_start)">生產工時(h)</th>'
          +'<th class="tooltip-th" title="生產工時(min)÷良品數">平均工時(min/件)</th>'
          +'<th class="tooltip-th" title="同料號同製程類別歷史全部報工平均">歷史均值</th>'
          +'<th class="tooltip-th" title="標準工時由KPI設定計算（含寬放率）">標準工時(h)</th>'
          +'<th class="tooltip-th" title="標準工時÷實際工時×100%">效率</th>'
          +'<th>生產金額</th>'
          +'<th>架機時段</th><th>架機工時(h)</th>'
          +'<th>快速設定</th>'
          +'</tr></thead>';
        var tb='<tbody>';
        res.data.forEach(function(r){
            var ng=parseInt(r.ng_qty)||0;
            var yr=(r.yield_rate!==null)?r.yield_rate+'%':'—';
            var ybc=r.yield_rate===null?'':r.yield_rate>=98?'cbadge-ok':r.yield_rate>=90?'cbadge-warn':'cbadge-ng';
            var avg=(r.avg_min_per_pc!==null)?parseFloat(r.avg_min_per_pc).toFixed(2)+' min':'—';
            var hist=(r.hist_avg_min!==null)?'<span title="基於'+r.hist_sample+'筆報工">'+parseFloat(r.hist_avg_min).toFixed(2)+' min</span>':'—';
            var stdH='—';
            if(r.std_hrs!==null){
                var _sh=parseFloat(r.std_hrs).toFixed(2)+'h';
                if(r.allowance_pct>0&&r.std_hrs_pure!==null&&r.std_hrs_pure!==r.std_hrs){
                    var _sp=parseFloat(r.std_hrs_pure).toFixed(2);
                    stdH='<span title="含'+r.allowance_pct+'%寬放（純標準：'+_sp+'h）" style="cursor:default;">'+_sh+'<sup style="font-size:9px;color:#b45309;margin-left:1px;">+'+r.allowance_pct+'%</sup></span>';
                } else { stdH=_sh; }
            }
            var eff=(r.efficiency!==null)?r.efficiency+'%':'—';
            var effBc=r.efficiency===null?'':r.efficiency>=100?'cbadge-ok':r.efficiency>=80?'cbadge-warn':'cbadge-ng';
            var alertCls=r.time_alert?'tr-alert':'';

            // 快速設定欄位邏輯
            var qsBtn;
            if(r.has_custom_std){
                // 已有個別設定工時 → 顯示打勾（可點擊重新設定）
                var qsData2=JSON.stringify({d_setting_id:r.d_setting_id,group_id:r.group_id,avg_min_per_pc:r.avg_min_per_pc,hist_avg_min:r.hist_avg_min,hist_min_min:r.hist_min_min,hist_max_min:r.hist_max_min,process_type_id:r.process_type_id,part_no:r.part_no,ProcessName:r.ProcessName});
                qsBtn='<button class="btn-quick-set" style="background:#d4f5ed;border-color:#aee8d3;color:#0e7a5e;" onclick=\'openQuickSet('+qsData2.replace(/'/g,"&#39;")+')\' title="已設定個別工時，點擊可重新設定"><i class="fa fa-check-circle"></i> 已設定</button>';
            } else if(r.d_setting_id && r.group_id){
                // 套用預設工時 → 顯示快速設定按鈕（需有 avg_min_per_pc 或 hist 資料）
                var hasRefTime = r.avg_min_per_pc || r.hist_avg_min;
                if(hasRefTime){
                    var qsData3=JSON.stringify({d_setting_id:r.d_setting_id,group_id:r.group_id,avg_min_per_pc:r.avg_min_per_pc,hist_avg_min:r.hist_avg_min,hist_min_min:r.hist_min_min,hist_max_min:r.hist_max_min,process_type_id:r.process_type_id,part_no:r.part_no,ProcessName:r.ProcessName});
                    qsBtn='<button class="btn-quick-set" onclick=\'openQuickSet('+qsData3.replace(/'/g,"&#39;")+')\'><i class="fa fa-bolt"></i> 快速設定</button>';
                } else {
                    qsBtn='<span style="color:#ccc;font-size:10px;">無工時資料</span>';
                }
            } else {
                qsBtn='—';
            }

            tb+='<tr class="'+alertCls+'" style="border-bottom:1px solid #e0f0ec;">'
              +'<td style="padding:4px 7px;">'+r.report_date+'</td>'
              +'<td><span style="cursor:pointer;color:var(--info);text-decoration:underline;" onclick="openBomDrawing(\''+r.bom_no+'\')">'+( r.part_no||'—')+'</span></td>'
              +'<td>'+(r.ProcessName||'—')+'</td><td>'+(r.machine||'—')+'</td>'
              +'<td>'+fmtN(r.produced_qty)+'</td>'
              +'<td>'+(ng>0?'<span style="color:var(--danger);">'+ng+'</span>':'0')+'</td>'
              +'<td>'+(ybc?'<span class="cbadge '+ybc+'">'+yr+'</span>':yr)+'</td>'
              +'<td style="font-size:11px;white-space:nowrap;">'+(r.prod_time_range||'—')+'</td>'
              +'<td>'+fmtH(r.prod_hrs)+'</td>'
              +'<td><strong>'+avg+'</strong></td>'
              +'<td style="color:#888;">'+hist+'</td>'
              +'<td>'+stdH+'</td>'
              +'<td>'+(effBc?'<span class="cbadge '+effBc+'">'+eff+'</span>':eff)+'</td>'
              +'<td><strong>'+fmtN(Math.round(r.amount))+'</strong></td>'
              +'<td style="font-size:11px;white-space:nowrap;color:#888;">'+(r.setup_time_range||'—')+'</td>'
              +'<td>'+fmtH(r.setup_hrs)+'</td>'
              +'<td>'+qsBtn+'</td>'
              +'</tr>';
        });
        tb+='</tbody>';
        var pagerHtml='';
        if(res.total>pp){
            var pages=Math.ceil(res.total/pp),curP2=res.page||1;
            var s2=Math.max(1,curP2-3),e2=Math.min(pages,curP2+3);
            var _uid=uid,_df=df,_dt=dt,_pn=partno,_eid='expand-user-'+uid;
            function _uBtn(pg,lbl,active){
                return '<button style="padding:2px 7px;font-size:11px;margin:0 2px;border:1px solid var(--border);border-radius:3px;cursor:pointer;'+(active?'background:var(--primary);color:#fff;':'')+'" onclick="loadUserExpandDetail('+_uid+',\''+_df+'\',\''+_dt+'\','+pg+',$(\'#'+_eid+'\'),\''+_pn+'\')">'+lbl+'</button>';
            }
            if(s2>1){pagerHtml+=_uBtn(1,'1',false)+'<span style="font-size:11px;padding:0 2px;">…</span>';}
            for(var i=s2;i<=e2;i++){pagerHtml+=_uBtn(i,i,i===curP2);}
            if(e2<pages){pagerHtml+='<span style="font-size:11px;padding:0 2px;">…</span>'+_uBtn(pages,pages,false);}
        }
        $tr.find('.expand-inner').html('<div style="overflow-x:auto;"><table style="width:100%;font-size:11px;border-collapse:collapse;">'+th+tb+'</table></div>'+(pagerHtml?'<div style="padding:4px 8px;">'+pagerHtml+'</div>':''));
    });
}

// ══ 生產金額頁 ════════════════════════════════════════════════
var amtData=[], amtPage=1;
function loadAmtPage(pg){
    pg=pg||1; amtPage=pg;
    var d=getDates(),uid=$('#kpi-user').val(),mid=$('#kpi-machine').val(),mtid=$('#kpi-mc-type').val(),did=$('#kpi-dept').val();
    var partno=$('#kpi-partno').val();
    var pp=parseInt($('#amt-pp').val())||10;
    $('#amt-tbody').html('<tr><td colspan="6" style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i> 計算中...</td></tr>');
    post({action:'get_production_amount',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid,machine_type_id:mtid,dept_id:did,part_no:partno,view:amtView},function(res){
        if(!res.success){$('#amt-tbody').html('<tr><td colspan="6" style="color:var(--danger);padding:20px;text-align:center;">載入失敗：'+(res.message||'')+'</td></tr>');return;}
        amtData=res.data;
        var off=(pg-1)*pp, paged=amtData.slice(off,off+pp);
        var th='<tr style="background:#f8f9fa;">';
        var viewLabels={user:'人員',machine:'機台',machine_type:'機台種類',area:'廠別'};
        var colLabel=viewLabels[amtView]||amtView;
        th+='<th>'+colLabel+'</th>';
        if(amtView==='machine')th+='<th>種類</th>';
        th+='<th class="tooltip-th" title="基礎工時×係數×倍數×基準金額/秒×良品數">生產金額(元)</th><th>良品數</th><th>報工筆數</th><th>有效工作天</th></tr>';
        var tb='',total=0;
        amtData.forEach(function(r){total+=parseFloat(r.amount||0);});
        paged.forEach(function(r,i){
            tb+='<tr class="amt-row" data-key="'+(r.key||0)+'" style="border-bottom:1px solid #f0f2f5;'+(i%2?'background:#fafbfc;':'')+'" title="雙擊展開明細" ondblclick="loadAmtDetail(this)">'
              +'<td><strong>'+(r.label||'—')+'</strong></td>';
            if(amtView==='machine')tb+='<td style="font-size:11px;color:#888;">'+(r.sub||'—')+'</td>';
            tb+='<td><strong style="color:var(--primary);">'+fmtN(Math.round(r.amount))+'</strong></td>'
              +'<td>'+fmtN(r.qty)+'</td>'
              +'<td>'+fmtN(r.report_count)+'</td>'
              +'<td>'+fmtN(r.work_days)+'</td>'
              +'</tr>';
        });
        tb+='<tr style="background:#f0fbf8;font-weight:700;"><td>合計</td>'+(amtView==='machine'?'<td></td>':'')
          +'<td>'+fmtN(Math.round(total))+'</td><td colspan="3"></td></tr>';
        $('#amt-thead').html(th);
        $('#amt-tbody').html(tb||'<tr><td colspan="6" style="text-align:center;padding:20px;color:#aaa;">無資料</td></tr>');
        $('#amt-note').text('共 '+amtData.length+' 筆 | 合計 '+fmtN(Math.round(total))+' 元（雙擊列展開明細）');
        $('#amt-pager-info').text('共 '+amtData.length+' 筆');
        renderPager('amt-pager',pg,amtData.length,pp,loadAmtPage);
    });
}
function loadAmtDetail(trEl){
    var key=$(trEl).data('key');
    var expandId='amt-expand-'+key;
    var $exist=$('#'+expandId);
    if($exist.length){$exist.toggle();return;}
    var d=getDates(),did=$('#kpi-dept').val();
    var partno=$('#kpi-partno').val();
    var cols=(amtView==='machine')?7:6;
    var $tr=$('<tr id="'+expandId+'" style="background:#f8fffe;"><td colspan="'+cols+'" style="padding:0;"><div style="padding:10px;"><i class="fa fa-spinner fa-spin"></i> 載入明細...</div></td></tr>');
    $(trEl).after($tr);
    post({action:'get_production_amount_detail',date_from:d.df,date_to:d.dt,view:amtView,key:key,part_no:partno},function(res){
        if(!res.success){$tr.find('div').html('<span class="text-danger">'+res.message+'</span>');return;}
        var h='<div style="overflow-x:auto;padding:8px;"><table style="width:100%;font-size:11px;border-collapse:collapse;">'
          +'<thead><tr style="background:#e8f8f5;">'
          +'<th style="padding:4px 6px;">日期</th><th>料號</th><th>製程</th><th>人員</th><th>機台</th><th>良品數</th><th>生產金額(元)</th><th>平均工時</th><th>快速設定</th>'
          +'</tr></thead><tbody>';
        res.data.forEach(function(r){
            var qsBtn='—';
            if(!r.has_custom_std&&r.d_setting_id&&r.group_id&&r.avg_min_per_pc){
                var qsd={d_setting_id:r.d_setting_id,group_id:r.group_id,avg_min_per_pc:r.avg_min_per_pc,process_type_id:r.process_type_id,part_no:r.part_no,ProcessName:r.ProcessName};
                qsBtn='<button class="btn-quick-set" onclick=\'openQuickSet('+JSON.stringify(qsd).replace(/'/g,"&#39;")+')\'>⚡ 快速設定</button>';
            }
            h+='<tr style="border-bottom:1px solid #e0f0ec;">'
              +'<td style="padding:3px 6px;">'+r.report_date+'</td>'
              +'<td><span style="cursor:pointer;color:var(--info);" onclick="openBomDrawing(\''+r.bom_no+'\')">'+(r.part_no||'—')+'</span></td>'
              +'<td>'+(r.ProcessName||'—')+'</td>'
              +'<td>'+(r.user_cname||'—')+'</td>'
              +'<td>'+(r.machine||'—')+'</td>'
              +'<td>'+fmtN(r.produced_qty)+'</td>'
              +'<td><strong>'+fmtN(Math.round(r.amount||0))+'</strong></td>'
              +'<td>'+(r.avg_min_per_pc?parseFloat(r.avg_min_per_pc).toFixed(2)+' min':'—')+'</td>'
              +'<td>'+qsBtn+'</td>'
              +'</tr>';
        });
        h+='</tbody></table></div>';
        $tr.find('div').html(h);
    });
}
function exportAmt(){
    if(!amtData.length){showToast('無資料可匯出',false);return;}
    var viewLabels={user:'人員',machine:'機台',machine_type:'機台種類',area:'廠別'};
    var col=viewLabels[amtView]||amtView;
    var rows=[col+(amtView==='machine'?',種類':'')+',生產金額(元),良品數,報工筆數,有效工作天'];
    amtData.forEach(function(r){
        rows.push([r.label||'',amtView==='machine'?r.sub||'':'',Math.round(r.amount),r.qty,r.report_count,r.work_days].filter((_,i)=>amtView==='machine'||i!==1).join(','));
    });
    var blob=new Blob(['\uFEFF'+rows.join('\n')],{type:'text/csv;charset=utf-8'});
    var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='production_amount.csv';a.click();
}

// ══ 機台資產設定 ══════════════════════════════════════════════
var methLabels={straight:'直線法',double_declining:'雙倍餘額遞減',sum_of_years:'年數合計法'};
function loadAssets(mtid, btn){
    mtid = mtid || 0;
    if(btn){ $('#asset-type-btns button').removeClass('btn-primary').addClass('btn-default'); $(btn).removeClass('btn-default').addClass('btn-primary'); }
    post({action:'get_machine_assets', machine_type_id:mtid},function(res){
        if(!res.success){$('#asset-tbody').html('<tr><td colspan="11" style="color:var(--danger);padding:15px;text-align:center;">'+res.message+'</td></tr>');return;}
        var h='';
        res.data.forEach(function(m){
            var stateCls=String(m.state)==='1'?'asset-state-bad':'asset-state-ok';
            var stateLabel=String(m.state)==='1'?'淘汰/賣出':'正常';
            var costStr=m.hourly_cost!==null?'<strong>'+parseFloat(m.hourly_cost).toFixed(2)+'</strong> 元/h':'<span style="color:#aaa;">未設定</span>';
            var depStr=m.is_fully_depreciated?'<span class="cbadge cbadge-warn">已折舊完畢</span>':(m.elapsed_years||'—')+'年';
            h+='<tr>'
              +'<td style="font-size:11px;color:#888;">'+(m.machine_type||'—')+'</td>'
              +'<td><strong class="'+stateCls+'">'+m.machine+'</strong></td>'
              +'<td style="font-size:11px;">'+(m.asset_no||'—')+'</td>'
              +'<td style="font-size:11px;">'+(m.field_no||'—')+'</td>'
              +'<td style="font-size:11px;">'+(m.machine_model||'—')+'</td>'
              +'<td><span class="'+stateCls+'">'+stateLabel+'</span></td>'
              +'<td style="font-size:11px;">'+(m.disabled_date||'—')+'</td>'
              +'<td>'+(m.purchase_date||'<span style="color:#aaa;">未設定</span>')+'</td>'
              +'<td>'+(m.purchase_amount?fmtN(parseFloat(m.purchase_amount).toFixed(0)):'<span style="color:#aaa;">—</span>')+'</td>'
              +'<td>'+(m.residual_value?fmtN(parseFloat(m.residual_value).toFixed(0)):'0')+'</td>'
              +'<td>'+(m.depreciation_years||'—')+'年</td>'
              +'<td style="font-size:11px;">'+(methLabels[m.depreciation_method]||m.depreciation_method||'—')+'</td>'
              +'<td>'+costStr+'</td>'
              +'<td>'+depStr+'</td>'
              +'<td><button class="btn btn-xs btn-info" title="機台資料" onclick=\'openMachineInfoModal('+JSON.stringify(m)+')\'><i class="fa fa-cog"></i></button> '
              +'<button class="btn btn-xs btn-default" title="資產折舊" onclick=\'openAssetModal('+JSON.stringify(m)+')\' ><i class="fa fa-edit"></i></button></td>'
              +'</tr>';
        });
        $('#asset-tbody').html(h||'<tr><td colspan="11" style="text-align:center;padding:20px;color:#aaa;">無機台資料</td></tr>');
    });
}
function openAssetModal(m){
    $('#am-mid').val(m.machine_id);$('#am-name').text(m.machine);
    $('#am-pdate').val(m.purchase_date||'');$('#am-pamt').val(m.purchase_amount||'');
    $('#am-rval').val(m.residual_value||0);$('#am-years').val(m.depreciation_years||5);
    $('#am-meth').val(m.depreciation_method||'straight');
    $('#am-mhrs').val(m.monthly_work_hours||720);$('#am-rem').val(m.remark||'');
    calcHourlyCost();
    $('#asset-modal').modal('show');
}
function calcHourlyCost(){
    var pAmt=parseFloat($('#am-pamt').val())||0,rVal=parseFloat($('#am-rval').val())||0;
    var years=parseInt($('#am-years').val())||5,meth=$('#am-meth').val();
    var annualDep=0;
    if(meth==='straight') annualDep=(pAmt-rVal)/Math.max(1,years);
    else if(meth==='double_declining') annualDep=(pAmt-rVal)*2/Math.max(1,years)*0.8; // 簡估
    else if(meth==='sum_of_years') annualDep=(pAmt-rVal)*years/(years*(years+1)/2);
    var monthlyDep=annualDep/12;
    var monthlyHrs=24*30; // 24h計
    var hCost=monthlyHrs>0?monthlyDep/monthlyHrs:0;
    $('#am-cost-preview').text(hCost>0?'每小時成本約：'+hCost.toFixed(4)+' 元':'（請先填入購入金額）');
}
function saveAsset(){
    post({action:'save_machine_asset',machine_id:$('#am-mid').val(),purchase_date:$('#am-pdate').val(),
        purchase_amount:$('#am-pamt').val(),residual_value:$('#am-rval').val(),depreciation_years:$('#am-years').val(),
        depreciation_method:$('#am-meth').val(),monthly_work_hours:$('#am-mhrs').val(),remark:$('#am-rem').val()},
    function(res){
        res.success?(showToast('已儲存'),$('#asset-modal').modal('hide'),loadAssets()):showToast(res.message||'儲存失敗',false);
    });
}

// ══ 機台資料管理 (新增/編輯/刪除機台本身，與 process_schedule 頁共用 machine_list) ══
function openMachineInfoModal(m){
    $('#mi-mid').val(m?m.machine_id:'');
    $('#mi-title').text(m?'編輯機台':'新增機台');
    $('#mi-name').val(m?m.machine:'');
    $('#mi-asset-no').val(m?(m.asset_no||''):'');
    $('#mi-field-no').val(m?(m.field_no||''):'');
    $('#mi-model').val(m?(m.machine_model||''):'');
    $('#mi-type').val(m?m.machine_type_id:'');
    $('#mi-position').val(m?m.position:'');
    $('#mi-need-setup').val(m?(m.need_setup||0):1);
    $('#mi-spec').val(m?(m.spec||''):'');
    $('#mi-note').val(m?(m.note||''):'');
    var disabled=!!(m&&String(m.state)==='1');
    $('#mi-state').prop('checked',disabled);
    $('#mi-disabled-date').val(m?(m.disabled_date||''):'');
    $('#mi-disabled-date-grp').toggle(disabled);
    $('#mi-delete-btn').toggle(!!m);
    $('#machine-info-modal').modal('show');
}
function saveMachineInfo(){
    var name=$('#mi-name').val().trim(), typeId=$('#mi-type').val();
    if(!name){showToast('機台名稱不可為空',false);return;}
    if(!typeId){showToast('請選擇機台類型',false);return;}
    post({action:'save_machine_info',machine_id:$('#mi-mid').val(),machine:name,machine_type_id:typeId,
        position:$('#mi-position').val(),need_setup:$('#mi-need-setup').val(),
        machine_model:$('#mi-model').val(),asset_no:$('#mi-asset-no').val(),field_no:$('#mi-field-no').val(),
        spec:$('#mi-spec').val(),note:$('#mi-note').val(),
        disabled:$('#mi-state').is(':checked')?1:0,disabled_date:$('#mi-disabled-date').val()},
    function(res){
        res.success?(showToast('已儲存'),$('#machine-info-modal').modal('hide'),loadAssets()):showToast(res.message||'儲存失敗',false);
    });
}
function deleteMachineInfo(){
    var mid=$('#mi-mid').val();
    if(!mid) return;
    if(!confirm('確定要刪除此機台？(軟刪除，可由管理員還原)')) return;
    post({action:'delete_machine_info',machine_id:mid},function(res){
        res.success?(showToast('已刪除'),$('#machine-info-modal').modal('hide'),loadAssets()):showToast(res.message||'刪除失敗',false);
    });
}

// ══ 快速設定 Modal ════════════════════════════════════════════
var qsRow=null;
function openQuickSet(data){
    qsRow=data;
    $('#qs-part').text(data.part_no||'—');
    $('#qs-proc').text(data.ProcessName||'—');
    $('#qs-coeff').val(1.0);
    $('#qs-price').val('');
    $('#qs-multi').val(1.0000);
    $('#qs-rem').val('');
    $('#qs-base-sec').val('');
    $('#qs-sec-hint').text('');

    // 建立參考工時按鈕
    var btns='';
    function refBtn(label, minVal, style){
        if(minVal===null||minVal===undefined) return '';
        var sec=Math.round(parseFloat(minVal)*60);
        return '<button type="button" class="btn btn-sm" style="border:1px solid var(--border);border-radius:5px;padding:4px 10px;font-size:12px;background:#f8f9fc;'+style+'" '
            +'onclick="setQsSec('+sec+',\''+label+'\')">'
            +'<strong>'+label+'</strong><br><span style="font-size:11px;color:#555;">'+parseFloat(minVal).toFixed(2)+' min / '+sec+' 秒</span>'
            +'</button>';
    }
    btns += refBtn('本筆平均', data.avg_min_per_pc, '');
    btns += refBtn('歷史均值 ('+data.hist_sample+'筆)', data.hist_avg_min, '');
    btns += refBtn('最短工時', data.hist_min_min, 'border-color:#aee8d3;');
    btns += refBtn('最長工時', data.hist_max_min, 'border-color:#f5c6cb;');
    $('#qs-ref-btns').html(btns||'<span style="color:#aaa;font-size:12px;">無歷史工時資料</span>');

    // 預設帶入本筆平均（若有）
    if(data.avg_min_per_pc){
        setQsSec(Math.round(parseFloat(data.avg_min_per_pc)*60),'本筆平均');
    }
    $('#qs-modal').modal('show');
}
function setQsSec(sec, label){
    $('#qs-base-sec').val(sec);
    $('#qs-sec-hint').text('已選擇：'+label+' → '+sec+' 秒/件');
    // 高亮選中按鈕
    $('#qs-ref-btns button').css('background','#f8f9fc');
    $('#qs-ref-btns button').each(function(){
        if($(this).find('strong').text()===label) $(this).css('background','#d4f5ed');
    });
}
function saveQuickSet(){
    if(!qsRow) return;
    var secVal = parseFloat($('#qs-base-sec').val());
    if(isNaN(secVal)||secVal<=0){ showToast('請先選擇或輸入每PCS工時（秒）',false); return; }
    post({action:'save_part_standard',
        d_setting_id: qsRow.d_setting_id,
        group_id:     qsRow.group_id,
        base_time_sec: secVal,
        coefficient:  parseFloat($('#qs-coeff').val()||1).toFixed(1),
        base_price:   $('#qs-price').val(),
        multiplier:   parseFloat($('#qs-multi').val()||1).toFixed(4),
        remark:       $('#qs-rem').val()
    }, function(res){
        if(res.success){ showToast('已設定每PCS工時 '+secVal+' 秒'); $('#qs-modal').modal('hide'); }
        else showToast(res.message||'儲存失敗',false);
    });
}

// 料號點擊開圖面（用 BOM 號碼比對）
function openBomDrawing(bomNo,e){
    if(e)e.stopPropagation();
    if(!bomNo){showToast('無BOM編號',false);return;}
    $('#dw-title').html('<i class="fa fa-image"></i> 圖面：'+bomNo);
    $('#dw-file-list').html('<div style="text-align:center;padding:20px;color:#aaa;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#dw-viewer').html('<div style="color:#aaa;text-align:center;font-size:12px;"><i class="fa fa-file-image-o fa-3x" style="display:block;margin-bottom:8px;"></i>選擇檔案</div>');
    $('#drawing-modal').modal('show');
    post({action:'get_product_files',product_id:bomNo},function(res){
        var $l=$('#dw-file-list');$l.empty();
        if(!res.success||!res.files.length){$l.html('<div style="text-align:center;padding:20px;color:#aaa;font-size:12px;">無圖面（BOM：'+bomNo+'）</div>');return;}
        res.files.forEach(function(f,i){
            var ic=f.type==='pdf'?'fa-file-pdf-o text-danger':'fa-file-image-o text-info';
            $l.append($('<div class="dw-file-item'+(i===0?' active':'')+'"></div>').html('<i class="fa '+ic+'"></i> '+f.name).on('click',function(){$('.dw-file-item').removeClass('active');$(this).addClass('active');showDwFile(f.path,f.type);}));
        });
        showDwFile(res.files[0].path,res.files[0].type);
    });
}

function loadKpiDashboard(){
    var d=getDates(),uid=$('#kpi-user').val(),mid=$('#kpi-machine').val(),mtid=$('#kpi-mc-type').val();
    var partno=$('#kpi-partno').val();

    // 摘要卡
    post({action:'get_kpi_summary',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid},function(res){
        if(!res.success)return;var r=res.data;
        var tot=(parseInt(r.total_ok)||0)+(parseInt(r.total_ng)||0);
        $('#ks-report').text(fmtN(r.report_count));$('#ks-ok').text(fmtN(r.total_ok));$('#ks-ng').text(fmtN(r.total_ng));
        $('#ks-yield').text(tot>0?(r.total_ok/tot*100).toFixed(1)+'%':'—');
        $('#ks-hrs').text(r.total_prod_hrs?parseFloat(r.total_prod_hrs).toFixed(1):'—');$('#ks-mc').text(r.machine_count||'—');
    });

    // ── 人員彙總：並行取 summary_agg + production_amount(user)，merge 後渲染
    var userAggDone=false, userAmtDone=false;
    var userAggData=null, userAmtMap={};
    var kpiUserPage=1;
    function renderUserAgg(){
        if(!userAggDone||!userAmtDone)return;
        var pp=parseInt($('#kpi-user-pp').val())||10;
        var off=(kpiUserPage-1)*pp, paged=userAggData.slice(off,off+pp);
        var th='<tr><th>人員</th><th>部門/職稱</th><th>報工筆</th><th>良品</th><th>NG</th><th>良品率</th>'
          +'<th>生產工時(h)</th><th>架機工時(h)</th>'
          +'<th class="tooltip-th" title="生產金額(元)">生產金額</th>'
          +'<th>稼動率</th><th>vs目標</th></tr>';
        var tb='';
        paged.forEach(function(r){
            var yr=r.yield_rate!==null?r.yield_rate+'%':'—';
            var ybc=r.yield_rate===null?'':r.yield_rate>=98?'cbadge-ok':r.yield_rate>=90?'cbadge-warn':'cbadge-ng';
            var util=parseFloat(r.utilization)||0;
            var c=util>=r.target?'var(--accent)':util>=r.target*.8?'var(--warn)':'var(--danger)';
            var diff=r.vs_target>0?'<span class="cbadge cbadge-ok">+'+r.vs_target.toFixed(1)+'%</span>':r.vs_target<0?'<span class="cbadge cbadge-ng">'+r.vs_target.toFixed(1)+'%</span>':'<span class="cbadge cbadge-warn">0%</span>';
            var amt=userAmtMap[r.user_id]!==undefined?'<strong style="color:var(--primary);">'+fmtN(Math.round(userAmtMap[r.user_id]))+'</strong>':'<span style="color:#ccc;">—</span>';
            tb+='<tr data-uid="'+(r.user_id||'')+'" style="cursor:pointer;" title="雙擊展開明細">'
              +'<td><strong>'+(r.label||'—')+'</strong></td>'
              +'<td class="dept-pos">'+(r.dept_name||'')+(r.pos_name?' · '+r.pos_name:'')+'</td>'
              +'<td>'+fmtN(r.report_count)+'</td><td>'+fmtN(r.total_ok)+'</td>'
              +'<td>'+(r.total_ng>0?'<span class="cbadge cbadge-ng">'+fmtN(r.total_ng)+'</span>':'0')+'</td>'
              +'<td>'+(ybc?'<span class="cbadge '+ybc+'">'+yr+'</span>':yr)+'</td>'
              +'<td>'+fmtH(r.prod_hrs)+'</td><td>'+fmtH(r.setup_hrs)+'</td>'
              +'<td>'+amt+'</td>'
              +'<td><div style="display:flex;align-items:center;gap:5px;"><div class="util-bar-wrap"><div class="util-bar" style="width:'+Math.min(util,100)+'%;background:'+c+';"></div></div><span style="color:'+c+';font-weight:700;">'+util+'%</span></div></td>'
              +'<td>'+diff+'</td></tr>';
        });
        $('#agg-user-thead').html(th);
        $('#agg-user-tbody').html(tb||'<tr><td colspan="11" style="text-align:center;padding:20px;color:#aaa;">無資料</td></tr>');
        renderPager('kpi-user-agg-pager',kpiUserPage,userAggData.length,pp,function(pg){kpiUserPage=pg;renderUserAgg();});
    }
    post({action:'get_kpi_summary_agg',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid,machine_type_id:mtid,part_no:partno,view:'user'},function(res){
        if(!res.success){$('#agg-user-tbody').html('<tr><td colspan="11" style="color:var(--danger);padding:15px;text-align:center;">載入失敗</td></tr>');return;}
        userAggData=res.data; userAggDone=true; renderUserAgg();
    });
    post({action:'get_production_amount',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid,machine_type_id:mtid,part_no:partno,view:'user'},function(res){
        if(res.success) res.data.forEach(function(r){ userAmtMap[r.key]=r.amount; });
        userAmtDone=true; renderUserAgg();
    });

    // ── 機台彙總：並行取 summary_agg + production_amount(machine)，merge 後渲染
    var mcAggDone=false, mcAmtDone=false;
    var mcAggRes=null, mcAmtMap={};
    var kpiMcPage=1;
    function renderMcAgg(){
        if(!mcAggDone||!mcAmtDone)return;
        var pp=parseInt($('#kpi-mc-pp').val())||10;
        var off=(kpiMcPage-1)*pp, paged=mcAggRes.data.slice(off,off+pp);
        var th='<tr><th>機台</th><th>報工筆</th><th>良品</th><th>NG</th><th>良品率</th>'
          +'<th>生產工時(h)</th>'
          +'<th class="tooltip-th" title="生產金額(元)">生產金額</th>'
          +'<th title="各日報工人員班別工時合計反推">稼動率 ⓘ</th>'
          +'<th>vs 目標 ('+mcAggRes.target_util+'%)</th></tr>';
        var tb='';
        paged.forEach(function(r){
            var util=(r.utilization!==null&&r.utilization!==undefined)?parseFloat(r.utilization):null;
            var vsT=(r.vs_target!==null&&r.vs_target!==undefined)?parseFloat(r.vs_target):null;
            var total=(parseInt(r.total_ok)||0)+(parseInt(r.total_ng)||0);
            var yr=total>0?((r.total_ok/total*100).toFixed(1)+'%'):'—';
            var ybc=total===0?'':r.total_ok/total>=0.98?'cbadge-ok':r.total_ok/total>=0.90?'cbadge-warn':'cbadge-ng';
            var utilCell,diffCell;
            if(util===null){
                utilCell='<span style="color:#aaa;font-size:11px;" title="請先設定班別排班">⚠ 需設定排班</span>';
                diffCell='—';
            } else {
                var c=util>=mcAggRes.target_util?'var(--accent)':util>=mcAggRes.target_util*.8?'var(--warn)':'var(--danger)';
                var warnIcon = r.utilization_warn ? '<i class="fa fa-exclamation-circle" style="color:var(--danger);margin-left:3px;"></i>' : '';
                utilCell = '<div style="display:flex;align-items:center;gap:5px;"><div class="util-bar-wrap"><div class="util-bar" style="width:'+Math.min(util,100)+'%;background:'+c+';"></div></div><span style="color:'+c+';font-weight:700;">'+util+'%'+warnIcon+'</span></div>';
                diffCell=vsT!==null?(vsT>0?'<span class="cbadge cbadge-ok">+'+vsT.toFixed(1)+'%</span>':vsT<0?'<span class="cbadge cbadge-ng">'+vsT.toFixed(1)+'%</span>':'<span class="cbadge cbadge-warn">0%</span>'):'—';
            }
            var amt=mcAmtMap[r.machine_id]!==undefined?'<strong style="color:var(--primary);">'+fmtN(Math.round(mcAmtMap[r.machine_id]))+'</strong>':'<span style="color:#ccc;">—</span>';
            tb+='<tr data-mid="'+(r.machine_id||'')+'" style="cursor:pointer;" title="雙擊展開明細">'
              +'<td><strong>'+(r.label||'—')+'</strong></td>'
              +'<td>'+fmtN(r.report_count)+'</td><td>'+fmtN(r.total_ok)+'</td>'
              +'<td>'+(r.total_ng>0?'<span class="cbadge cbadge-ng">'+fmtN(r.total_ng)+'</span>':'0')+'</td>'
              +'<td>'+(ybc?'<span class="cbadge '+ybc+'">'+yr+'</span>':yr)+'</td>'
              +'<td>'+fmtH(r.prod_hrs)+'</td>'
              +'<td>'+amt+'</td>'
              +'<td>'+utilCell+'</td>'
              +'<td>'+diffCell+'</td></tr>';
        });
        $('#agg-mc-thead').html(th);
        $('#agg-mc-tbody').html(tb||'<tr><td colspan="9" style="text-align:center;padding:20px;color:#aaa;">無資料</td></tr>');
        renderPager('kpi-mc-agg-pager',kpiMcPage,mcAggRes.data.length,pp,function(pg){kpiMcPage=pg;renderMcAgg();});
    }
    post({action:'get_kpi_summary_agg',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid,machine_type_id:mtid,part_no:partno,view:'machine'},function(res){
        if(!res.success){$('#agg-mc-tbody').html('<tr><td colspan="9" style="color:var(--danger);padding:15px;text-align:center;">載入失敗</td></tr>');return;}
        mcAggRes=res; mcAggDone=true; renderMcAgg();
    });
    post({action:'get_production_amount',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid,machine_type_id:mtid,part_no:partno,view:'machine'},function(res){
        if(res.success) res.data.forEach(function(r){ mcAmtMap[r.key]=r.amount; });
        mcAmtDone=true; renderMcAgg();
    });
}

// ══ 人員報工明細（逐筆）══════════════════════════════════════
function loadUserDetail(page){
    var d=getDates(),uid=$('#kpi-user').val(),mid=$('#kpi-machine').val(),pp=$('#user-pp').val();
    $('#user-detail-tbody').html('<tr><td colspan="13" style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    post({action:'get_kpi_user_detail',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid,page:page,per_page:pp},function(res){
        if(!res.success){$('#user-detail-tbody').html('<tr><td colspan="13" style="text-align:center;color:var(--danger);padding:20px;">載入失敗：'+(res.message||'未知錯誤')+'</td></tr>');return;}
        var tb='';
        res.data.forEach(function(r){
            var ng=parseInt(r.ng_qty)||0;
            var yieldVal=(r.yield_rate!==null&&r.yield_rate!==undefined&&r.yield_rate!=='')?parseFloat(r.yield_rate):null;
            var yr=yieldVal!==null?yieldVal+'%':'—';
            var ybc=yieldVal===null?'':yieldVal>=98?'cbadge-ok':yieldVal>=90?'cbadge-warn':'cbadge-ng';
            // 效率
            var effStr='—',effBc='';
            var effVal=(r.efficiency!==null&&r.efficiency!==undefined&&r.efficiency!=='')?parseFloat(r.efficiency):null;
            if(effVal!==null){
                effStr=effVal+'%';
                effBc=effVal>=100?'cbadge-ok':effVal>=80?'cbadge-warn':'cbadge-ng';
            }
            var alertCls=r.time_alert?'tr-alert':'';
            var alertIcon=r.time_alert?'<i class="fa fa-exclamation-triangle" style="color:var(--warn);margin-left:4px;" title="工時差異超過警示閾值"></i>':'';
            var stdHrsVal=(r.std_hrs!==null&&r.std_hrs!==undefined&&r.std_hrs!=='')?parseFloat(r.std_hrs):null;
            tb+='<tr class="'+alertCls+'">'
              +'<td>'+r.report_date+'</td>'
              +'<td><strong>'+(r.prod_user||r.setup_user||'—')+'</strong></td>'
              +'<td class="dept-pos">'+(r.prod_dept||'')+(r.prod_pos?' · '+r.prod_pos:'')+'</td>'
              +'<td style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+(r.part_no||'')+'">'+(r.part_no||'—')+'</td>'
              +'<td>'+(r.ProcessName||'—')+'</td>'
              +'<td>'+(r.machine||'—')+'</td>'
              +'<td>'+fmtN(r.produced_qty)+'</td>'
              +'<td>'+(ng>0?'<span class="cbadge cbadge-ng">'+fmtN(ng)+'</span>':'0')+'</td>'
              +'<td>'+(ybc?'<span class="cbadge '+ybc+'">'+yr+'</span>':yr)+'</td>'
              +'<td>'+fmtH(r.prod_hrs)+alertIcon+'</td>'
              +'<td>'+(stdHrsVal!==null?stdHrsVal.toFixed(2)+'h':'<span style="color:#aaa;">—</span>')+'</td>'
              +'<td>'+(effBc?'<span class="cbadge '+effBc+'">'+effStr+'</span>':effStr)+'</td>'
              +'<td>'+fmtH(r.setup_hrs)+'</td>'
              +'</tr>';
        });
        $('#user-detail-tbody').html(tb||'<tr><td colspan="13" style="text-align:center;padding:20px;color:#aaa;">無資料</td></tr>');
        var total=res.total;
        $('#user-detail-note').text('(共 '+total+' 筆，警示閾值 '+res.alert_pct+'%)');
        $('#user-pager-info').text('共 '+total+' 筆');
        $('#user-pager-info2').text('共 '+total+' 筆');
        renderPager('user-pager', res.page, total, parseInt(pp), loadUserDetail);
    });
}

// ══ 機台稼動明細（彙總，每台機台一列）══════════════════════
function loadMcAgg(page){
    var d=getDates(),uid=$('#kpi-user').val(),mid=$('#kpi-machine').val(),pp=parseInt($('#mc-pp').val())||10,mtid=$('#kpi-mc-type').val();
    var partno=$('#kpi-partno').val(),off=(page-1)*pp,aggDone=false,amtDone=false,aggData=null,amtMap={};
    $('#mc-detail-tbody').html('<tr><td colspan="10" style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    function render(){
        if(!aggDone||!amtDone) return;
        var tgt=aggData.length?(aggData[0].target||80):80,total=aggData.length,paged=aggData.slice(off,off+pp),tb='';
        paged.forEach(function(r){
            var tqty=(parseInt(r.total_ok)||0)+(parseInt(r.total_ng)||0);
            var yr=tqty>0?((r.total_ok/tqty*100).toFixed(1)+'%'):'—';
            var ybc=tqty===0?'':r.total_ok/tqty>=0.98?'cbadge-ok':r.total_ok/tqty>=0.90?'cbadge-warn':'cbadge-ng';
            var util=(r.utilization!==null&&r.utilization!==undefined)?parseFloat(r.utilization):null;
            var vsT=(r.vs_target!==null&&r.vs_target!==undefined)?parseFloat(r.vs_target):null;
            var utilCell,diffCell;
            if(util===null){utilCell='<span style="color:#aaa;font-size:11px;">⚠ 需設定排班</span>';diffCell='—';}
            else{
                var c=util>=tgt?'var(--accent)':util>=tgt*.8?'var(--warn)':'var(--danger)';
                var wi=r.utilization_warn?'<i class="fa fa-exclamation-circle" style="color:var(--danger);margin-left:3px;"></i>':'';
                utilCell='<div style="display:flex;align-items:center;gap:5px;"><div class="util-bar-wrap"><div class="util-bar" style="width:'+Math.min(util,100)+'%;background:'+c+';"></div></div><span style="color:'+c+';font-weight:700;">'+util+'%'+wi+'</span></div>';
                diffCell=vsT!==null?(vsT>0?'<span class="cbadge cbadge-ok">+'+vsT.toFixed(1)+'%</span>':vsT<0?'<span class="cbadge cbadge-ng">'+vsT.toFixed(1)+'%</span>':'<span class="cbadge cbadge-warn">0%</span>'):'—';
            }
            var amtVal=amtMap[r.machine_id]!==undefined?amtMap[r.machine_id]:(parseFloat(r.amount)||0);
            var amtCell=amtVal>0?'<strong style="color:var(--primary);">'+fmtN(Math.round(amtVal))+'</strong>':'<span style="color:#ccc;">—</span>';
            tb+='<tr class="mc-agg-row" data-mid="'+r.machine_id+'" data-partno="'+(partno||'')+'" style="cursor:pointer;transition:.1s;" title="點擊展開明細">'
              +'<td><strong>'+(r.label||'—')+'</strong><small style="color:#888;font-size:10px;margin-left:4px;">'+(r.machine_type||'')+'</small></td>'
              +'<td>'+fmtN(r.report_count)+'</td>'
              +'<td>'+fmtN(r.total_ok)+'</td>'
              +'<td>'+(parseInt(r.total_ng)>0?'<span class="cbadge cbadge-ng">'+fmtN(r.total_ng)+'</span>':'0')+'</td>'
              +'<td>'+(ybc?'<span class="cbadge '+ybc+'">'+yr+'</span>':yr)+'</td>'
              +'<td>'+fmtH(r.prod_hrs)+'</td>'
              +'<td>'+fmtH(r.setup_hrs)+'</td>'
              +'<td>'+amtCell+'</td>'
              +'<td>'+utilCell+'</td>'
              +'<td>'+diffCell+'</td>'
              +'</tr>';
        });
        $('#mc-detail-tbody').html(tb||'<tr><td colspan="10" style="text-align:center;padding:20px;color:#aaa;">無資料</td></tr>');
        $('#mc-detail-note').text('(共 '+total+' 台)');
        $('#mc-pager-info,#mc-pager-info2').text('共 '+total+' 台');
        renderPager('mc-pager',page,total,pp,loadMcAgg);
    }
    post({action:'get_kpi_machine_agg',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid,machine_type_id:mtid,part_no:partno},function(res){
        if(!res.success){$('#mc-detail-tbody').html('<tr><td colspan="10" style="text-align:center;color:var(--danger);padding:20px;">載入失敗：'+(res.message||'')+'</td></tr>');return;}
        aggData=res.data;aggDone=true;render();
    });
    post({action:'get_production_amount',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:mid,machine_type_id:mtid,part_no:partno,view:'machine'},function(res){
        if(res.success) res.data.forEach(function(r){ amtMap[r.key]=r.amount; });
        amtDone=true;render();
    });
}
$(document).on('click','.mc-agg-row',function(){
    var mid=$(this).data('mid'),expandId='expand-mc-'+mid,$exist=$('#'+expandId);
    if($exist.length){$exist.toggle();return;}
    var d=getDates(),partno=$(this).data('partno')||'';
    var $tr=$('<tr id="'+expandId+'" class="expand-row"><td colspan="10"><div class="expand-inner"><i class="fa fa-spinner fa-spin"></i> 載入明細...</div></td></tr>');
    $(this).after($tr);
    loadMcExpandDetail(mid,d.df,d.dt,1,$tr,partno);
});
function loadMcExpandDetail(mid,df,dt,page,$tr,partno){
    post({action:'get_kpi_machine_detail',machine_id:mid,date_from:df,date_to:dt,page:page,per_page:10,part_no:partno},function(res){
        if(!res.success){$tr.find('.expand-inner').html('<span class="text-danger">載入失敗：'+(res.message||'')+'</span>');return;}
        var tgt=parseFloat(res.target_util)||80,pp=res.per_page||10;
        var th='<thead><tr style="background:#eef1f5;"><th style="padding:5px 7px;">日期</th><th>料號</th><th>製程</th><th>生產人員</th><th>良品</th><th>NG</th><th>良品率</th><th>生產時段</th><th>工時(h)</th><th>均值(min)</th><th>生產金額</th><th>架機時段</th><th>架機(h)</th><th>稼動率</th></tr></thead>';
        var tb='<tbody>';
        res.data.forEach(function(r){
            var ng=parseInt(r.ng_qty)||0,yr=r.yield_rate!==null?r.yield_rate+'%':'—';
            var ybc=r.yield_rate===null?'':r.yield_rate>=98?'cbadge-ok':r.yield_rate>=90?'cbadge-warn':'cbadge-ng';
            var util=(r.utilization!==null&&r.utilization!==undefined)?parseFloat(r.utilization):null;
            var c2=util!==null?(util>=tgt?'var(--accent)':util>=tgt*.8?'var(--warn)':'var(--danger)'):'#aaa';
            var utilCell=util===null?'<span style="color:#aaa;font-size:10px;">⚠ 需排班</span>':'<span style="color:'+c2+';font-weight:700;">'+util+'%</span>';
            var amtVal=parseFloat(r.amount)||0;
            var partLink=r.bom_no?'<span style="cursor:pointer;color:var(--info);text-decoration:underline;" onclick="openBomDrawing(\''+r.bom_no+'\')">'+(r.part_no||'—')+'</span>':(r.part_no||'—');
            tb+='<tr style="border-bottom:1px solid #e8edf5;">'
              +'<td style="padding:4px 7px;white-space:nowrap;">'+r.report_date+'</td>'
              +'<td>'+partLink+'</td><td>'+(r.ProcessName||'—')+'</td><td>'+(r.prod_user||'—')+'</td>'
              +'<td>'+fmtN(r.produced_qty)+'</td><td>'+(ng>0?'<span style="color:var(--danger);">'+ng+'</span>':'0')+'</td>'
              +'<td>'+(ybc?'<span class="cbadge '+ybc+'">'+yr+'</span>':yr)+'</td>'
              +'<td style="font-size:11px;white-space:nowrap;">'+(r.prod_time_range||'—')+'</td>'
              +'<td>'+fmtH(r.prod_hrs)+'</td><td>'+(r.avg_min_per_pc?r.avg_min_per_pc+' min':'—')+'</td>'
              +'<td>'+(amtVal>0?'<strong>'+fmtN(Math.round(amtVal))+'</strong>':'—')+'</td>'
              +'<td style="font-size:11px;white-space:nowrap;color:#888;">'+(r.setup_time_range||'—')+'</td>'
              +'<td>'+fmtH(r.setup_hrs)+'</td><td>'+utilCell+'</td>'
              +'</tr>';
        });
        tb+='</tbody>';
        var pHtml='';
        if(res.total>pp){
            var pages=Math.ceil(res.total/pp),curP=res.page||1;
            var s=Math.max(1,curP-3),e=Math.min(pages,curP+3);
            var _mid=mid,_df2=df,_dt2=dt,_pn2=partno,_eid2='expand-mc-'+mid;
            function _mBtn(pg,lbl,active){
                return '<button style="padding:2px 7px;font-size:11px;margin:0 2px;border:1px solid var(--border);border-radius:3px;cursor:pointer;'+(active?'background:var(--primary);color:#fff;':'')+'" onclick="loadMcExpandDetail('+_mid+',\''+_df2+'\',\''+_dt2+'\','+pg+',$(\'#'+_eid2+'\'),\''+_pn2+'\')">'+lbl+'</button>';
            }
            if(s>1){pHtml+=_mBtn(1,'1',false)+'<span style="font-size:11px;padding:0 2px;">…</span>';}
            for(var i=s;i<=e;i++){pHtml+=_mBtn(i,i,i===curP);}
            if(e<pages){pHtml+='<span style="font-size:11px;padding:0 2px;">…</span>'+_mBtn(pages,pages,false);}
        }
        $tr.find('.expand-inner').html('<div style="overflow-x:auto;"><table style="width:100%;font-size:11px;border-collapse:collapse;">'+th+tb+'</table></div>'+(pHtml?'<div style="padding:4px 8px;">'+pHtml+'</div>':'')+'<div style="padding:2px 8px;font-size:11px;color:#aaa;">共 '+res.total+' 筆</div>');
    });
}


function formatTimeRange(s,e){
    if(!s) return '—';
    var ss=s.substr(11,5),ee=e?e.substr(11,5):'未完';
    return ss+'~'+ee;
}

function renderPager(id, page, total, pp, cb){
    var pages=Math.ceil(total/pp);
    if(pages<=1){$('#'+id).empty();return;}
    var h='',s=Math.max(1,page-3),e=Math.min(pages,page+3);
    if(s>1)h+='<button onclick="'+cb.name+'(1)">1</button><span style="padding:0 3px;">…</span>';
    for(var i=s;i<=e;i++)h+='<button '+(i===page?'class="active"':'')+' onclick="'+cb.name+'('+i+')">'+i+'</button>';
    if(e<pages)h+='<span style="padding:0 3px;">…</span><button onclick="'+cb.name+'('+pages+')">'+pages+'</button>';
    $('#'+id).html(h);
}

// ══ 難易係數設定 ══════════════════════════════════════════════
var selDid=null, showUnset=false, srTimer=null;
function debSearch(){clearTimeout(srTimer);srTimer=setTimeout(doDidSearch,350);}
function toggleUnset(){showUnset=!showUnset;$('#btn-unset').toggleClass('btn-warning',showUnset).toggleClass('btn-default',!showUnset);doDidSearch();}
function doDidSearch(){
    var term=$('#coeff-search').val().trim();
    $('#did-list').html('<div class="did-item" style="justify-content:center;color:#aaa;cursor:default;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>');
    post({action:'search_did',term:term,only_unset:showUnset?1:0},function(res){
        var $l=$('#did-list');$l.empty();
        if(!res.success||!res.data.length){$l.html('<div class="did-item" style="justify-content:center;color:#bbb;cursor:default;font-size:12px;">無資料</div>');return;}
        res.data.forEach(function(d){
            var gt=d.Type==='G'?'<span class="gear-tag"><i class="fa fa-cog"></i> 齒輪</span>':'';
            var ut=(d.std_count==='0'||d.std_count===0)?'<span class="unset-tag">未設定</span>':'';
            var $el=$('<div class="did-item"></div>')
                .attr('data-json',JSON.stringify(d))
                .html('<div><div class="did-part">'+d.part_no+gt+ut+'</div><div class="did-client">'+(d.Client_Name||'')+'</div></div>'
                     +'<button class="btn btn-xs btn-default" onclick="openDrawing(\''+d.part_no+'\',event)" title="圖面"><i class="fa fa-image"></i></button>')
                .on('click',function(e){if($(e.target).closest('button').length)return;selectDid(d,$(this));});
            $l.append($el);
        });
    });
}
function selectDid(d,$el){selDid=d;$('.did-item').removeClass('selected');$el.addClass('selected');renderCoeffPanel(d);}

function renderCoeffPanel(d){
    var $p=$('#coeff-panel'), gi='';
    if(d.Type==='G'&&d.Module){
        gi='<div class="gear-info-box">'
          +(d.Module?'<div><span>模數：</span><strong>'+d.Module+'</strong></div>':'')
          +(d.Teeth?'<div><span>齒數：</span><strong>'+d.Teeth+'</strong></div>':'')
          +(d.Face_Width?'<div><span>齒寬：</span><strong>'+d.Face_Width+' mm</strong></div>':'')
          +(d.Helix_Angle_Str?'<div><span>螺旋角：</span><strong>'+d.Helix_Angle_Str+' '+(d.Helix_Direction||'')+'</strong></div>':'')
          +(d.Pressure_Angle?'<div><span>壓力角：</span><strong>'+d.Pressure_Angle+'</strong></div>':'')
          +(d.Profile_Shift_X?'<div><span>轉位係數：</span><strong>'+d.Profile_Shift_X+'</strong></div>':'')
          +(d.Workpiece_Length?'<div><span>工件長：</span><strong>'+d.Workpiece_Length+' mm</strong></div>':'')
          +(d.Gear_Type?'<div><span>型式：</span><strong>'+d.Gear_Type+'</strong></div>':'')
          +(d.Remark_Gear?'<div style="grid-column:1/-1"><span>備註：</span><strong>'+d.Remark_Gear+'</strong></div>':'')+'</div>';
    }
    var isGear = d.Type==='G';
    $p.html('<div class="setting-card"><h5><i class="fa fa-sliders" style="color:var(--accent);margin-right:6px;"></i>'+d.part_no+' — '+(d.Client_Name||'')
           +'<button class="btn btn-default btn-sm" onclick="openDrawing(\''+d.part_no+'\',event)"><i class="fa fa-image"></i> 圖面</button></h5>'
           +gi+'<div id="std-cards"><div style="text-align:center;padding:20px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i></div></div>'
           +'<div style="margin-top:12px;">'
           +'<button class="btn btn-success" onclick="saveAllStd()" style="font-weight:600;"><i class="fa fa-save"></i> 儲存所有設定</button>'
           +(isGear?'<small class="text-muted" style="margin-left:10px;">※ 齒輪料號：售價=基礎工時×模數×齒數×齒寬×難易係數×基準金額/秒</small>'
                   :'<small class="text-muted" style="margin-left:10px;">※ 一般料號：售價=基礎工時×難易係數×倍數×基準金額/秒</small>')
           +'</div></div>');

    post({action:'get_part_standards',d_setting_id:d.d_id},function(res){
        if(!res.success)return;
        var h='';
        res.data.forEach(function(g){
            var defT=g.default_base_time?parseFloat(g.default_base_time).toFixed(2):'未設定';
            var defP=g.default_base_price?parseFloat(g.default_base_price).toFixed(4):'未設定';
            var defC=g.default_coefficient?parseFloat(g.default_coefficient).toFixed(1):'1.0';
            var delBtn=g.is_custom
              ?'<button class="btn btn-xs btn-danger" style="margin-left:auto;" onclick="deletePartStd('+selDid.d_id+','+g.group_id+',this)" title="刪除此群組個別設定"><i class="fa fa-trash"></i> 刪除設定</button>'
              :'';
            h+='<div class="std-row" data-gid="'+g.group_id+'">'
              +'<div class="std-row-head">'
              +'<div class="gname">'+g.group_name+'</div>'
              +(g.is_custom?'<span class="cbadge cbadge-ok" style="font-size:10px;">✓ 已個別設定</span>':'<span style="font-size:10px;color:#aaa;">套用群組預設</span>')
              +delBtn
              +'<div class="gproc">'+(g.process_names||'<span style="color:#aaa;">尚未設定製程</span>')+'</div>'
              +'</div>'
              +'<div class="std-row-body">'
              +'<div class="std-field"><label>難易係數 (1~10)</label>'
              +'<input type="number" class="std-inp std-coeff" min="1" max="10" step="0.1" data-gid="'+g.group_id+'" value="'+parseFloat(g.coefficient||defC).toFixed(1)+'" placeholder="預設'+defC+'"></div>'
              +'<div class="std-field"><label>每PCS工時(秒) <span style="color:#aaa;font-weight:400;">預設:'+defT+'</span></label>'
              +'<input type="number" class="std-inp wide std-time" min="0" step="0.01" data-gid="'+g.group_id+'" value="'+(g.base_time_sec!==null?parseFloat(g.base_time_sec).toFixed(2):'')+'" placeholder="空=用預設'+defT+'"></div>'
              +'<div class="std-field"><label>基準金額(元/秒) <span style="color:#aaa;font-weight:400;">預設:'+defP+'</span></label>'
              +'<input type="number" class="std-inp wide std-price" min="0" step="0.0001" data-gid="'+g.group_id+'" value="'+(g.base_price!==null?parseFloat(g.base_price).toFixed(4):'')+'" placeholder="空=用預設'+defP+'"></div>'
              +(isGear?''
                :'<div class="std-field"><label>倍數（複雜度）</label>'
                +'<input type="number" class="std-inp std-multi" min="0" step="0.0001" data-gid="'+g.group_id+'" value="'+parseFloat(g.multiplier||1).toFixed(4)+'" placeholder="1.0000"></div>')
              +'<div class="std-field"><label>備註</label>'
              +'<input type="text" style="border:1px solid var(--border);border-radius:5px;padding:4px 7px;font-size:12px;width:100px;" class="std-remark" data-gid="'+g.group_id+'" value="'+(g.remark||'')+'"></div>'
              +'</div></div>';
        });
        $('#std-cards').html(h||'<div style="color:#aaa;font-size:12px;padding:10px;">尚無製程群組，請至「🔧 製程群組」頁建立</div>');
    });
}

function deletePartStd(dId,gId,btn){
    if(!confirm('確定刪除此製程的個別設定？（將恢復套用群組預設值）'))return;
    $(btn).prop('disabled',true);
    post({action:'delete_part_standard',d_setting_id:dId,group_id:gId},function(res){
        if(res.success){showToast('已刪除個別設定');selectDid(selDid,$('.did-item.selected'));}
        else{showToast('刪除失敗：'+(res.message||''),false);$(btn).prop('disabled',false);}
    });
}

function saveAllStd(){
    if(!selDid)return;
    var dId=selDid.d_id, ps=[];
    $('.std-row').each(function(){
        var gid=$(this).data('gid');
        var c=parseFloat($(this).find('.std-coeff').val()||1);
        var t=$(this).find('.std-time').val();
        var p=$(this).find('.std-price').val();
        var m=$(this).find('.std-multi').val()||1;
        var r=$(this).find('.std-remark').val();
        ps.push(new Promise(function(ok){
            post({action:'save_part_standard',d_setting_id:dId,group_id:gid,
                coefficient:isNaN(c)?1:c.toFixed(1),
                base_time_sec:t,base_price:p,multiplier:parseFloat(m).toFixed(4),remark:r},ok);
        }));
    });
    Promise.all(ps).then(function(rs){
        rs.every(function(r){return r.success;})?(showToast('設定已儲存'),doDidSearch()):showToast('部分儲存失敗',false);
    });
}

// ══ 圖面 ══════════════════════════════════════════════════════
var dw={s:1,x:0,y:0,drag:false,sx:0,sy:0};
function openDrawing(pid,e){
    if(e)e.stopPropagation();
    $('#dw-title').html('<i class="fa fa-image"></i> 圖面：'+pid);
    $('#dw-file-list').html('<div style="text-align:center;padding:20px;color:#aaa;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#dw-viewer').html('<div style="color:#aaa;text-align:center;font-size:12px;"><i class="fa fa-file-image-o fa-3x" style="display:block;margin-bottom:8px;"></i>選擇檔案</div>');
    $('#drawing-modal').modal('show');
    post({action:'get_product_files',product_id:pid},function(res){
        var $l=$('#dw-file-list');$l.empty();
        if(!res.success||!res.files.length){$l.html('<div style="text-align:center;padding:20px;color:#aaa;font-size:12px;">無圖面</div>');return;}
        res.files.forEach(function(f,i){
            var ic=f.type==='pdf'?'fa-file-pdf-o text-danger':'fa-file-image-o text-info';
            var label=(f.bom?'<div style="font-size:11px;font-weight:600;color:#333;margin-bottom:2px;">'+f.bom+'</div>':'')
                +'<div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><i class="fa '+ic+'"></i> '+f.name+'</div>';
            $l.append($('<div class="dw-file-item'+(i===0?' active':'')+'" style="padding:8px 10px;border-bottom:1px solid #e9ecef;cursor:pointer;"></div>').html(label).on('click',function(){$('.dw-file-item').removeClass('active');$(this).addClass('active');showDwFile(f.path,f.type);}));
        });
        showDwFile(res.files[0].path,res.files[0].type);
    });
}
function showDwFile(path,type){
    var $v=$('#dw-viewer');$v.off('wheel');$(document).off('.dw');
    if(type==='pdf'){$v.html('<iframe src="'+path+'" style="width:100%;height:100%;border:none;"></iframe>');return;}
    dw={s:1,x:0,y:0,drag:false,sx:0,sy:0};
    var img=new Image();img.id='dw-img';img.src=path;
    img.style.cssText='max-width:90%;max-height:90%;cursor:grab;user-select:none;transform-origin:center center;';
    img.ondragstart=function(){return false;};
    $v.css({display:'flex',alignItems:'center',justifyContent:'center'}).html('').append(img);
    $v.on('wheel',function(e){e.preventDefault();dw.s=Math.max(0.1,dw.s+(e.originalEvent.deltaY<0?.1:-.1));applyDw();});
    $v.on('mousedown','#dw-img',function(e){dw.drag=true;dw.sx=e.clientX-dw.x;dw.sy=e.clientY-dw.y;$(this).css('cursor','grabbing');});
    $(document).on('mousemove.dw',function(e){if(!dw.drag)return;dw.x=e.clientX-dw.sx;dw.y=e.clientY-dw.sy;applyDw();}).on('mouseup.dw',function(){dw.drag=false;$('#dw-img').css('cursor','grab');});
}
function applyDw(){$('#dw-img').css('transform','translate('+dw.x+'px,'+dw.y+'px) scale('+dw.s+')');}
$('#drawing-modal').on('hidden.bs.modal',function(){$(document).off('.dw');$('#dw-viewer').off('wheel');});

function resetFilters(){
    $('.qd-btn').removeClass('active'); $('.qd-btn[data-qdate="month"]').addClass('active');
    setQDate('month', $('.qd-btn[data-qdate="month"]')[0]);
    $('#kpi-user,#kpi-dept,#kpi-mc-type,#kpi-machine').val('');
    $('#kpi-partno').val('');
    $("#btn-clear-partno").hide();
    onSearch();
}
function clearPartnoFilter(){
    $('#kpi-partno').val('');
    $('#btn-clear-partno').hide();
    onSearch();
}

// ══ 製程群組 ══════════════════════════════════════════════════
var allProcs=[];
var selectedProcNos=new Set();
var activeProcTypeFilter=null;
var allLabels=null;
var formulaVars=[];
var KPI_PARAMS=[{key:'coeff',name:'難易係數'},{key:'base_amount',name:'基準金額（元）'},{key:'base_price',name:'基準金額(元/秒)'},{key:'base_time',name:'每PCS工時(秒)'}];
var currentGroupSetupCosts=[];
var GEAR_FIELDS=[
    {key:'Module',name:'模數'},
    {key:'Teeth',name:'齒數'},
    {key:'Face_Width',name:'齒寬(mm)'},
    {key:'Helix_Angle',name:'螺旋角(度)'},
    {key:'Profile_Shift_X',name:'轉位係數X'},
    {key:'Workpiece_Length',name:'工件總長(mm)'},
    {key:'da',name:'齒部外徑(計算值)'},
    {key:'Weight_Kg',name:'重量(kg)'}
];
var VAR_NAMES='ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

function loadGroups(){
    post({action:'get_groups'},function(res){
        if(!res.success)return;
        var h='';
        res.data.forEach(function(g){
            var fp=parseFloat(g.default_fixed_price||0);
            var bt=parseFloat(g.default_base_time||0);
            var bp=parseFloat(g.default_base_price||0);
            var ba=g.default_base_amount!==null&&g.default_base_amount!==''?parseFloat(g.default_base_amount):null;
            var dc=parseFloat(g.default_coefficient||1);
            // 基本費用
            var scList=Array.isArray(g.setup_costs)?g.setup_costs:[];
            // 計算方式說明
            var calcHint='';
            if(fp>0){
                calcHint='<div style="margin-top:3px;font-size:11px;color:#c05000;"><i class="fa fa-tag"></i> 固定 <strong>'+fp.toFixed(2)+'</strong> 元/PCS</div>';
            } else if(parseInt(g.has_formula||0)){
                var expr=g.formula_expr||'（未設定）';
                // 解析 var_config 顯示對應欄位
                var vars=[];
                try{vars=JSON.parse(g.var_config||'[]');}catch(e){vars=[];}
                var varParts=vars.map(function(v){
                    var field='';
                    if(v.type==='label'){
                        field=v.label_name||'?';
                        if(v.sub_id&&v.sub_name) field+='›'+v.sub_name;
                    } else if(v.type==='param'){
                        var p=(KPI_PARAMS.filter(function(p){return p.key===v.param_key;})[0]||{name:v.param_key||'?'});
                        field=p.name;
                    } else if(v.type==='gear'){
                        field='齒輪.'+(v.gear_field_name||v.gear_field||'?');
                    } else if(v.type==='qty'){
                        field='數量';
                    } else if(v.type==='base_cost'){
                        field=v.cost_desc||'基本費用';
                    } else if(v.type==='calc_weight'){
                        field='自動重量';
                    } else {
                        field=v.type||'?';
                    }
                    return '<strong>'+v.var+'</strong>='+field;
                });
                calcHint='<div style="margin-top:3px;font-size:11px;color:#0a7c58;">'
                    +'<i class="fa fa-calculator"></i> <code style="font-size:11px;">'+expr+'</code>'
                    +(varParts.length?'<br><span style="color:#555;">'+varParts.join('，')+'</span>':'')
                    +'</div>';
            } else if(bt>0||bp>0){
                var parts=[];
                if(bt>0) parts.push('工時 <strong>'+bt.toFixed(2)+'</strong> 秒/PCS');
                if(bp>0) parts.push('基準 <strong>'+bp.toFixed(4)+'</strong> 元/秒');
                if(dc&&dc!==1) parts.push('係數 <strong>'+dc.toFixed(1)+'</strong>');
                calcHint='<div style="margin-top:3px;font-size:11px;color:#555;"><i class="fa fa-clock-o"></i> '+parts.join(' × ')+'</div>';
            }
            // 基本費用說明
            var scHint=scList.length
                ?'<div style="margin-top:3px;font-size:11px;color:#6a4c00;">'+scList.map(function(sc){return '<i class="fa fa-money"></i> '+sc.cost_desc+' '+parseFloat(sc.cost_amount).toFixed(2)+'元';}).join('　')+'</div>'
                :'';
            var modeLabel=fp>0
                ?'<span style="color:#c05000;font-weight:600;">固定金額</span>'
                :parseInt(g.has_formula||0)
                    ?'<span style="color:#0a7c58;font-weight:600;">自訂公式</span>'
                    :'<span style="color:#888;">工時計費</span>';
            var baCell=ba!==null?ba.toFixed(2):'<span style="color:#aaa;">—</span>';
            h+='<tr>'
              +'<td><strong>'+g.group_name+'</strong></td>'
              +'<td><code>'+g.group_code+'</code></td>'
              +'<td style="font-size:11px;max-width:160px;">'+(g.process_names||'<span style="color:#aaa;">尚未設定</span>')+'</td>'
              +'<td>'+(g.default_coefficient||'1.0')+'</td>'
              +'<td>'+(bt>0?bt.toFixed(2):'<span style="color:#aaa;">—</span>')+'</td>'
              +'<td>'+(bp>0?bp.toFixed(4):'<span style="color:#aaa;">—</span>')+'</td>'
              +'<td>'+baCell+'</td>'
              +'<td>'+((g.description||'')+calcHint+scHint||'—')+'</td>'
              +'<td>'+modeLabel+'</td>'
              +'<td><button class="btn btn-xs btn-default" onclick=\'openGroupModal('+JSON.stringify(g)+')\' ><i class="fa fa-edit"></i></button></td>'
              +'</tr>';
        });
        $('#groups-tbody').html(h||'<tr><td colspan="10" style="text-align:center;padding:20px;color:#aaa;">尚無群組</td></tr>');
    });
}

function openGroupModal(g){
    var n=!g||g===0;
    $('#gm-id').val(n?'':g.group_id);
    $('#gm-name').val(n?'':g.group_name);
    $('#gm-code').val(n?'':g.group_code);
    $('#gm-sort').val(n?0:g.sort_order);
    $('#gm-desc').val(n?'':g.description||'');
    $('#gm-base-time').val(n||!g.default_base_time?'':parseFloat(g.default_base_time).toFixed(2));
    $('#gm-base-price').val(n||!g.default_base_price?'':parseFloat(g.default_base_price).toFixed(4));
    $('#gm-def-coeff').val(n||!g.default_coefficient?'':parseFloat(g.default_coefficient).toFixed(1));
    $('#gm-fixed-price').val(n||!g.default_fixed_price?'':parseFloat(g.default_fixed_price).toFixed(2));
    var baVal=n||g.default_base_amount===null||g.default_base_amount===''?'':parseFloat(g.default_base_amount).toFixed(4);
    $('#gm-base-amount').val(baVal);
    // 載入基本費用
    currentGroupSetupCosts=Array.isArray(g.setup_costs)?g.setup_costs:[];
    renderSetupCosts();
    // 決定 fallback 模式（新群組預設 formula）
    var fallbackMode='formula';
    if(!n){
        if(g.default_fallback_mode) fallbackMode=g.default_fallback_mode;
        else if(parseFloat(g.default_fixed_price||0)>0) fallbackMode='fixed'; // 舊資料相容
    }
    $('[name="gm-fallback"][value="'+fallbackMode+'"]').prop('checked',true);
    $('#gm-section-fixed').toggle(fallbackMode==='fixed');
    $('#gm-section-formula').toggle(fallbackMode==='formula');
    // Reset formula builder
    formulaVars=[];$('#gm-formula-expr').val('');$('#gm-formula-preview').html('');
    renderFormulaVars();
    // 載入公式資料（如果有）
    if(!n&&g.group_id&&parseInt(g.has_formula||0)){
        post({action:'get_group_formula',group_id:g.group_id},function(res){
            if(res.success&&res.data){
                $('#gm-formula-expr').val(res.data.formula_expr||'');
                try{formulaVars=JSON.parse(res.data.var_config||'[]');}catch(e){formulaVars=[];}
                var _dbgExpr=res.data.formula_expr||'';
                var _dbgVars=formulaVars.slice();
                if(allLabels===null) loadFormulaLabels(function(){normFormulaVarsDimFields();normFormulaVarsBaseCost();renderFormulaVars();updateFormulaPreview();_logFormulaDebug(g.group_id,g.group_name||'',_dbgExpr,_dbgVars);});
                else{normFormulaVarsDimFields();normFormulaVarsBaseCost();renderFormulaVars();updateFormulaPreview();_logFormulaDebug(g.group_id,g.group_name||'',_dbgExpr,_dbgVars);}
            }
        });
    }
    switchFallbackMode();
    // Load process tags
    var sel=n?[]:(g.process_nos||'').split(',').filter(Boolean);
    if(!allProcs.length){post({action:'get_process_list'},function(res){allProcs=res.data||[];renderProcTags(sel);});}
    else renderProcTags(sel);
    $('#group-modal').modal('show');
}

function switchFallbackMode(){
    var m=$('[name="gm-fallback"]:checked').val()||'formula';
    $('#gm-section-fixed').toggle(m==='fixed');
    $('#gm-section-formula').toggle(m==='formula');
    if(m==='formula'&&allLabels===null) loadFormulaLabels(function(){renderFormulaVars();});
}

function renderProcTags(sel){
    selectedProcNos=new Set(sel.map(String));
    activeProcTypeFilter=null;
    $('#gm-proc-search').val('');
    // Build type filter buttons
    var typeMap={};
    allProcs.forEach(function(p){
        var tid=String(p.process_type_id||0);
        if(!typeMap[tid]) typeMap[tid]={name:p.process_type||'未分類',tid:tid};
    });
    var tf='<button class="btn btn-xs btn-primary" data-tid="all" style="margin-bottom:2px;" onclick="setProctypeFilter(null)">全部</button> ';
    Object.keys(typeMap).forEach(function(tid){
        tf+='<button class="btn btn-xs btn-default" data-tid="'+tid+'" style="margin-bottom:2px;" onclick="setProctypeFilter('+tid+')">'+typeMap[tid].name+'</button> ';
    });
    $('#gm-proc-type-filters').html(tf);
    drawProcTags();
}

function setProctypeFilter(tid){
    // Sync selections before switching filter
    $('.proc-tag').each(function(){
        var pno=String($(this).data('pno'));
        if($(this).hasClass('selected')) selectedProcNos.add(pno); else selectedProcNos.delete(pno);
    });
    activeProcTypeFilter=(tid===null||tid===undefined)?null:String(tid);
    $('#gm-proc-type-filters button').removeClass('btn-primary').addClass('btn-default');
    if(activeProcTypeFilter===null) $('#gm-proc-type-filters button[data-tid="all"]').addClass('btn-primary').removeClass('btn-default');
    else $('#gm-proc-type-filters button[data-tid="'+activeProcTypeFilter+'"]').addClass('btn-primary').removeClass('btn-default');
    drawProcTags();
}

function filterProcTags(){
    // Sync from DOM then redraw
    $('.proc-tag').each(function(){
        var pno=String($(this).data('pno'));
        if($(this).hasClass('selected')) selectedProcNos.add(pno); else selectedProcNos.delete(pno);
    });
    drawProcTags();
}

function drawProcTags(){
    var q=$('#gm-proc-search').val().toLowerCase();
    var h='',lastType=null;
    allProcs.forEach(function(p){
        var tid=String(p.process_type_id||0);
        if(activeProcTypeFilter!==null&&tid!==activeProcTypeFilter) return;
        var label=p.ProcessNo+' '+(p.ProcessName||'');
        if(q&&label.toLowerCase().indexOf(q)===-1) return;
        var tn=p.process_type||'未分類';
        if(activeProcTypeFilter===null&&tn!==lastType){
            h+='<div style="font-size:10px;font-weight:700;color:#888;margin:4px 0 2px;width:100%;">'+tn+'</div>';
            lastType=tn;
        }
        var sel=selectedProcNos.has(String(p.ProcessNo));
        h+='<span class="proc-tag'+(sel?' selected':'')+'\" data-pno="'+p.ProcessNo+'" onclick="$(this).toggleClass(\'selected\')">'+p.ProcessNo+' '+(p.ProcessName||'')+'</span>';
    });
    $('#gm-proc-tags').html(h||'<span style="color:#aaa;font-size:12px;">無符合製程</span>');
}

function saveGroup(){
    // Sync proc selections from DOM (respects hidden-by-filter items via selectedProcNos)
    $('.proc-tag').each(function(){
        var pno=String($(this).data('pno'));
        if($(this).hasClass('selected')) selectedProcNos.add(pno); else selectedProcNos.delete(pno);
    });
    var nos=Array.from(selectedProcNos).join(',');
    var fallbackMode=$('[name="gm-fallback"]:checked').val()||'formula';
    var gid=$('#gm-id').val()||0;
    var bt=$('#gm-base-time').val();
    var bp=$('#gm-base-price').val();
    var fp=fallbackMode==='fixed'?$('#gm-fixed-price').val():'';
    var dc=$('#gm-def-coeff').val();
    var ba=$('#gm-base-amount').val();
    // 收集基本費用列表
    var scItems=[];
    $('#gm-setup-costs .sc-row').each(function(){
        var desc=$(this).find('.sc-desc').val().trim();
        var amt=$(this).find('.sc-amt').val();
        var cid=parseInt($(this).data('costid')||0);
        if(desc!=='') scItems.push({cost_id:cid,cost_desc:desc,cost_amount:parseFloat(amt)||0});
    });
    post({action:'save_group',group_id:gid,group_name:$('#gm-name').val(),group_code:$('#gm-code').val(),
        sort_order:$('#gm-sort').val(),description:$('#gm-desc').val(),process_nos:nos},function(res){
        if(!res.success){showToast(res.message||'儲存失敗',false);return;}
        var newGid=res.group_id;
        var ps=[];
        ps.push(new Promise(function(ok){post({action:'save_std_time_default',group_id:newGid,
            base_time_sec:bt||0,base_price:bp||0,fixed_price_per_pcs:fp||'',
            fallback_mode:fallbackMode,base_amount:ba||''},ok);}));
        if(dc!==''){var f=parseFloat(dc);if(!isNaN(f)&&f>=1&&f<=10){
            ps.push(new Promise(function(ok){post({action:'save_default_coeff',group_id:newGid,coefficient:f.toFixed(1)},ok);}));
        }}
        ps.push(new Promise(function(ok){post({action:'save_group_setup_costs',group_id:newGid,items:JSON.stringify(scItems)},ok);}));
        // 公式永遠儲存（無論 fallback_mode，保留設定）
        var expr=$('#gm-formula-expr').val().trim();
        if(expr){
            ps.push(new Promise(function(ok){post({action:'save_group_formula',group_id:newGid,
                formula_expr:expr,var_config:JSON.stringify(formulaVars)},ok);}));
        }
        Promise.all(ps).then(function(){showToast('群組已儲存');$('#group-modal').modal('hide');loadGroups();});
    });
}

// ── 基本費用管理 ────────────────────────────────────────────
function renderSetupCosts(){
    var h='';
    currentGroupSetupCosts.forEach(function(sc,i){
        h+='<div class="sc-row" data-costid="'+(sc.cost_id||0)+'" style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">'
            +'<input type="text" class="form-control input-sm sc-desc" placeholder="費用說明（如架機費用）" style="flex:1;" value="'+$('<div>').text(sc.cost_desc||'').html()+'">'
            +'<input type="number" class="form-control input-sm sc-amt" placeholder="金額（元）" step="0.01" min="0" style="width:120px;" value="'+(sc.cost_amount||'')+'">'
            +'<button type="button" class="btn btn-xs btn-danger" onclick="removeSetupCostRow(this)"><i class="fa fa-times"></i></button>'
            +'</div>';
    });
    $('#gm-setup-costs').html(h);
}
function addSetupCostRow(){
    currentGroupSetupCosts.push({cost_id:0,cost_desc:'',cost_amount:''});
    renderSetupCosts();
    $('#gm-setup-costs .sc-row:last .sc-desc').focus();
}
function removeSetupCostRow(btn){
    var idx=$(btn).closest('.sc-row').index();
    currentGroupSetupCosts.splice(idx,1);
    renderSetupCosts();
}

// ── 公式建立器 DEBUG ─────────────────────────────────────────
function _logFormulaDebug(groupId, groupName, expr, vars){
    if(!vars||!vars.length){return;}
    var gLabel='%c🔍 公式 DEBUG — '+groupName+' (group_id='+groupId+')';
    console.group(gLabel,'color:#1a5c9e;font-weight:bold;font-size:13px;');
    console.log('%c公式：'+expr,'font-family:monospace;background:#f0f4ff;padding:2px 6px;border-radius:3px;');

    var sqlLines=[
        '-- ===================================================',
        '-- KPI 公式 DEBUG — 群組：'+groupName+' (group_id='+groupId+')',
        '-- 請把下面 @d_id 的值換成要查的料號 d_setting_id',
        '-- ===================================================',
        'SET @d_id = 0;  -- ← 填入料號的 d_setting_id',
        ''
    ];

    vars.forEach(function(v){
        var varLabel='%c  變數 '+v.var+' ('+v.type+')';
        console.group(varLabel,'color:#0a7c58;font-weight:600;');

        if(v.type==='label'){
            var lname=(v.label_name||'label#'+v.label_id)+(v.sub_name?' › '+v.sub_name:'');
            var baseKey='@d_id_'+v.label_id+(v.sub_id?'_'+v.sub_id:'');
            console.log('標籤：',lname,'  label_id='+v.label_id+(v.sub_id?' sub_id='+v.sub_id:''));
            console.log('labelValMap key：',baseKey.replace('@d_id','[d_id]'));
            if(v.dim_field) console.log('dim_field：',v.dim_field,(v.dim_field==='dim'?'→ value_min × value_max':v.dim_field==='dim_div'?'→ value_max ÷ value_min（長÷寬）':'→ qty 欄位'));
            if(v.fallback_gear) console.log('備援（標籤=0時改用）：',v.fallback_gear);

            sqlLines.push('-- 變數 '+v.var+'：標籤「'+lname+'」 label_id='+v.label_id+(v.sub_id?' sub_id='+v.sub_id:''));
            if(v.sub_id){
                sqlLines.push(
                    'SELECT ilm.d_id, ilm.label_id, islm.sub_id, islm.input_value, islm.value_min, islm.value_max\n'+
                    'FROM item_sub_label_map islm\n'+
                    'JOIN item_label_map ilm ON ilm.map_id = islm.parent_map_id\n'+
                    'WHERE ilm.d_id = @d_id AND ilm.label_id = '+v.label_id+' AND islm.sub_id = '+v.sub_id+';'
                );
            } else {
                sqlLines.push(
                    'SELECT d_id, label_id, input_value, value_min, value_max, qty\n'+
                    'FROM item_label_map\n'+
                    'WHERE d_id = @d_id AND label_id = '+v.label_id+';'
                );
            }
            if(v.dim_field==='dim') sqlLines.push('-- 取值：value_min × value_max');
            else if(v.dim_field==='dim_div') sqlLines.push('-- 取值：value_max ÷ value_min（長÷寬）');
            else if(v.dim_field==='qty') sqlLines.push('-- 取值：qty 欄位');
            if(v.fallback_label_id){
                var flbl=(allLabels||[]).filter(function(l){return l.label_id==v.fallback_label_id;})[0];
                console.log('備援（標籤=0時）→ 標籤 label_id='+v.fallback_label_id+(flbl?' ('+flbl.label_name+')':''));
                sqlLines.push('-- 備援（標籤=0時）→ 標籤 label_id='+v.fallback_label_id+(flbl?' ('+flbl.label_name+')':''));
                sqlLines.push('SELECT d_id, label_id, input_value FROM item_label_map WHERE d_id = @d_id AND label_id = '+v.fallback_label_id+';');
            }
            if(v.fallback_gear){
                sqlLines.push('-- 備援（標籤=0時）→ 齒輪規格 '+v.fallback_gear);
                if(v.fallback_gear==='da'){
                    sqlLines.push(
                        'SELECT d_setting_id, Module, Teeth, Helix_Angle, Profile_Shift_X,\n'+
                        '  ROUND(CAST(REPLACE(Module,\'M\',\'\') AS DECIMAL(20,8)) * Teeth\n'+
                        '        / COS(RADIANS(COALESCE(Helix_Angle,0)))\n'+
                        '        + 2 * CAST(REPLACE(Module,\'M\',\'\') AS DECIMAL(20,8)) * (1 + COALESCE(Profile_Shift_X,0)), 2) AS da\n'+
                        'FROM d_setting_gear WHERE d_setting_id = @d_id;'
                    );
                } else {
                    sqlLines.push('SELECT d_setting_id, '+v.fallback_gear+' FROM d_setting_gear WHERE d_setting_id = @d_id;');
                }
            }

        } else if(v.type==='gear'){
            console.log('齒輪規格 field：',v.gear_field,'(',v.gear_field_name||'',')','\ngearValMap key：[d_id]_'+v.gear_field);
            sqlLines.push('-- 變數 '+v.var+'：齒輪規格 '+v.gear_field+'（'+( v.gear_field_name||'')+'）');
            if(v.gear_field==='da'){
                sqlLines.push(
                    'SELECT d_setting_id, Module, Teeth, Helix_Angle, Profile_Shift_X,\n'+
                    '  ROUND(CAST(REPLACE(Module,\'M\',\'\') AS DECIMAL(20,8)) * Teeth\n'+
                    '        / COS(RADIANS(COALESCE(Helix_Angle,0)))\n'+
                    '        + 2 * CAST(REPLACE(Module,\'M\',\'\') AS DECIMAL(20,8)) * (1 + COALESCE(Profile_Shift_X,0)), 2) AS da\n'+
                    'FROM d_setting_gear WHERE d_setting_id = @d_id;'
                );
            } else {
                sqlLines.push('SELECT d_setting_id, '+v.gear_field+' FROM d_setting_gear WHERE d_setting_id = @d_id;');
            }

        } else if(v.type==='param'){
            var fixedNote=(v.param_value!==undefined&&v.param_value!==null&&String(v.param_value)!=='')?'固定值='+v.param_value:'自動取群組設定';
            console.log('KPI參數 key：',v.param_key,'  ('+fixedNote+')');
            sqlLines.push('-- 變數 '+v.var+'：KPI參數 '+v.param_key+' ('+fixedNote+')');
            if(v.param_value!==undefined&&v.param_value!==null&&String(v.param_value)!==''){
                sqlLines.push('-- 使用固定值 '+v.param_value+'，不查 DB');
            } else {
                sqlLines.push(
                    '-- 群組預設值：\n'+
                    'SELECT std.base_time_sec, std.base_price, std.fixed_price_per_pcs\n'+
                    'FROM kpi_std_time_default std WHERE std.group_id = '+groupId+';\n'+
                    '-- 全域 KPI 設定：\n'+
                    'SELECT setting_key, setting_value FROM kpi_settings WHERE setting_key = \''+v.param_key+'\';'
                );
            }

        } else if(v.type==='qty'){
            console.log('生產數量（來自生產單）');
            sqlLines.push('-- 變數 '+v.var+'：生產數量（來自生產單批量，非 DB 標籤）');

        } else if(v.type==='base_cost'){
            console.log('基本費用 cost_desc：',v.cost_desc);
            sqlLines.push('-- 變數 '+v.var+'：基本費用「'+v.cost_desc+'」');
            sqlLines.push('SELECT cost_desc, cost_amount FROM kpi_group_setup_cost\nWHERE group_id = '+groupId+" AND cost_desc = '"+(v.cost_desc||'').replace(/'/g,"\\'")+"';");
        } else if(v.type==='calc_weight'){
            console.log('自動計算重量（依 kpi_weight_calc_rule 規則，圓柱公式）');
            sqlLines.push('-- 變數 '+v.var+'：自動計算重量 (kg)，圓柱公式 π/4×D²×L×ρ÷1,000,000');
            sqlLines.push('SELECT * FROM kpi_weight_calc_rule WHERE is_active=1 ORDER BY sort_order;');
            sqlLines.push('SELECT * FROM kpi_material_density ORDER BY sort_order;');
        }

        sqlLines.push('');
        console.groupEnd();
    });

    // 一次查全部標籤
    var lbIds=vars.filter(function(v){return v.type==='label';}).map(function(v){return v.label_id;}).filter(Boolean);
    if(lbIds.length){
        sqlLines.push('-- ── 一次查所有用到的標籤值 ──────────────────────');
        sqlLines.push(
            'SELECT ilm.label_id, dl.label_name, ilm.input_value, ilm.value_min, ilm.value_max, ilm.qty\n'+
            'FROM item_label_map ilm\n'+
            'JOIN dict_label dl ON dl.label_id = ilm.label_id\n'+
            'WHERE ilm.d_id = @d_id AND ilm.label_id IN ('+lbIds.join(',')+');'
        );
        var subVars=vars.filter(function(v){return v.type==='label'&&v.sub_id;});
        if(subVars.length){
            sqlLines.push('');
            sqlLines.push('-- ── 子標籤 ──────────────────────────────────────');
            sqlLines.push(
                'SELECT ilm.label_id, islm.sub_id, dls.sub_name, islm.input_value, islm.value_min, islm.value_max\n'+
                'FROM item_sub_label_map islm\n'+
                'JOIN item_label_map ilm ON ilm.map_id = islm.parent_map_id\n'+
                'JOIN dict_label_sub dls ON dls.sub_id = islm.sub_id\n'+
                'WHERE ilm.d_id = @d_id AND islm.sub_id IN ('+subVars.map(function(v){return v.sub_id;}).join(',')+');'
            );
        }
    }

    console.log('%c\n📋 完整 SQL（複製到 phpMyAdmin）：','color:#1a5c9e;font-weight:bold;');
    console.log(sqlLines.join('\n'));
    console.groupEnd();
}

// ── 公式建立器 ──────────────────────────────────────────────
// 取得某個變數指向的標籤/子標籤的 dimension flags
function _dimFlags(v,labels){
    var isDim=false,isQtyDim=false;
    var selLbl=(labels||[]).filter(function(l){return l.label_id==v.label_id;})[0];
    if(v.sub_id&&selLbl){
        var selSub=(selLbl.subs||[]).filter(function(s){return s.sub_id==v.sub_id;})[0];
        if(selSub){isDim=parseInt(selSub.is_dimension||0)===1;isQtyDim=parseInt(selSub.is_qty_dim||0)===1;}
    } else if(selLbl){
        isDim=parseInt(selLbl.is_dimension||0)===1;isQtyDim=parseInt(selLbl.is_qty_dim||0)===1;
    }
    return {isDim:isDim,isQtyDim:isQtyDim};
}
// 切換標籤/子標籤後自動設定預設 dim_field（多值標籤預設 'dim' 即長×寬乘積，無多值則清除）
function _initDimField(i){
    delete formulaVars[i].dim_field;
    var flags=_dimFlags(formulaVars[i],allLabels||[]);
    if(flags.isQtyDim) formulaVars[i].dim_field='qty';
    else if(flags.isDim) formulaVars[i].dim_field='dim';
}
function normFormulaVarsDimFields(){
    if(!allLabels) return;
    formulaVars.forEach(function(v,i){
        if(v.type!=='label') return;
        if(v.dim_field!==''&&v.dim_field!==undefined&&v.dim_field!==null) return;
        var flags=_dimFlags(v,allLabels||[]);
        if(flags.isQtyDim) formulaVars[i].dim_field='qty';
        else if(flags.isDim) formulaVars[i].dim_field='dim';
    });
}
function normFormulaVarsBaseCost(){
    if(!currentGroupSetupCosts.length) return;
    formulaVars.forEach(function(v,i){
        if(v.type!=='base_cost') return;
        if(v.cost_desc) return;
        formulaVars[i].cost_desc=currentGroupSetupCosts[0].cost_desc;
    });
}
function setFormulaVarDimField(i,val){
    formulaVars[i].dim_field=val;
    renderFormulaVars(); updateFormulaPreview();
}
// checkbox 切換乘法/除法（dim ↔ dim_div）
function setFormulaVarDimDiv(i,checked){
    formulaVars[i].dim_field=checked?'dim_div':'dim';
    updateFormulaPreview();
}
function loadFormulaLabels(cb){
    post({action:'get_labels'},function(res){
        allLabels=res.success?(res.data||[]):null;
        if(cb) cb();
    });
}

function addFormulaVar(){
    if(allLabels===null){loadFormulaLabels(function(){addFormulaVar();});return;}
    if(formulaVars.length>=VAR_NAMES.length) return;
    var defL=allLabels.length?allLabels[0]:{label_id:0,label_name:'',subs:[]};
    var entry={var:VAR_NAMES[formulaVars.length],type:'label',label_id:defL.label_id,label_name:defL.label_name};
    if(defL.subs&&defL.subs.length){entry.sub_id=parseInt(defL.subs[0].sub_id);entry.sub_name=defL.subs[0].sub_name;}
    formulaVars.push(entry);
    _initDimField(formulaVars.length-1);
    renderFormulaVars();
}

function removeFormulaVar(i){
    formulaVars.splice(i,1);
    formulaVars.forEach(function(v,idx){v.var=VAR_NAMES[idx];});
    renderFormulaVars();updateFormulaPreview();
}

function setFormulaVarType(i,type){
    formulaVars[i].type=type;
    delete formulaVars[i].label_id; delete formulaVars[i].label_name;
    delete formulaVars[i].sub_id; delete formulaVars[i].sub_name;
    delete formulaVars[i].param_key; delete formulaVars[i].param_value;
    delete formulaVars[i].gear_field; delete formulaVars[i].gear_field_name;
    delete formulaVars[i].cost_desc; delete formulaVars[i].dim_field;
    if(type==='label'){
        var defL=allLabels&&allLabels.length?allLabels[0]:{label_id:0,label_name:'',subs:[]};
        formulaVars[i].label_id=defL.label_id; formulaVars[i].label_name=defL.label_name;
        if(defL.subs&&defL.subs.length){formulaVars[i].sub_id=parseInt(defL.subs[0].sub_id);formulaVars[i].sub_name=defL.subs[0].sub_name;}
        _initDimField(i);
    } else if(type==='param'){
        formulaVars[i].param_key=KPI_PARAMS[0].key;
        // param_value starts empty (user fills in or leaves blank for auto)
    } else if(type==='gear'){
        formulaVars[i].gear_field=GEAR_FIELDS[0].key; formulaVars[i].gear_field_name=GEAR_FIELDS[0].name;
    } else if(type==='base_cost'){
        _syncSetupCostsFromDOM();
        if(currentGroupSetupCosts.length) formulaVars[i].cost_desc=currentGroupSetupCosts[0].cost_desc;
    }
    // type==='qty' and type==='calc_weight' need no extra fields
    renderFormulaVars(); updateFormulaPreview();
}

function setFormulaVarVal(i,val){
    var t=formulaVars[i].type;
    if(t==='label'){
        formulaVars[i].label_id=parseInt(val);
        var lbl=(allLabels||[]).filter(function(l){return l.label_id==val;})[0];
        formulaVars[i].label_name=lbl?lbl.label_name:'';
        // Reset sub-label and dim_field: auto-select first sub if available
        delete formulaVars[i].sub_id; delete formulaVars[i].sub_name; delete formulaVars[i].dim_field;
        if(lbl&&lbl.subs&&lbl.subs.length){
            formulaVars[i].sub_id=parseInt(lbl.subs[0].sub_id);
            formulaVars[i].sub_name=lbl.subs[0].sub_name;
        }
        _initDimField(i);
        renderFormulaVars(); // re-render to show/hide sub selector
    } else if(t==='param'){
        formulaVars[i].param_key=val;
    } else if(t==='gear'){
        formulaVars[i].gear_field=val;
        var gf=GEAR_FIELDS.filter(function(f){return f.key===val;})[0];
        formulaVars[i].gear_field_name=gf?gf.name:val;
    } else if(t==='base_cost'){
        formulaVars[i].cost_desc=val;
    }
    updateFormulaPreview();
}
function setFormulaVarSubId(i,val){
    delete formulaVars[i].dim_field;
    if(val===''||val===null){delete formulaVars[i].sub_id;delete formulaVars[i].sub_name;}
    else{
        formulaVars[i].sub_id=parseInt(val);
        var lbl=(allLabels||[]).filter(function(l){return l.label_id==formulaVars[i].label_id;})[0];
        var sub=lbl?(lbl.subs||[]).filter(function(s){return s.sub_id==val;})[0]:null;
        formulaVars[i].sub_name=sub?sub.sub_name:'';
    }
    _initDimField(i);
    updateFormulaPreview();
}
function setFormulaVarParamValue(i,val){
    if(val===''||val===null){delete formulaVars[i].param_value;}
    else{formulaVars[i].param_value=parseFloat(val);}
    updateFormulaPreview();
}
function setFormulaVarFallback(i,val){
    delete formulaVars[i].fallback_gear;
    delete formulaVars[i].fallback_label_id;
    if(!val) return;
    if(val.indexOf('gear:')=== 0) formulaVars[i].fallback_gear=val.slice(5);
    else if(val.indexOf('label:')=== 0) formulaVars[i].fallback_label_id=parseInt(val.slice(6));
    updateFormulaPreview();
}
// 相容舊名（預防外部呼叫）
var setFormulaVarFallbackGear=setFormulaVarFallback;

function _syncSetupCostsFromDOM(){
    var synced=[];
    $('#gm-setup-costs .sc-row').each(function(){
        var desc=$(this).find('.sc-desc').val().trim();
        var amt=parseFloat($(this).find('.sc-amt').val()||0)||0;
        var cid=parseInt($(this).data('costid')||0);
        if(desc!=='') synced.push({cost_id:cid,cost_desc:desc,cost_amount:amt});
    });
    if(synced.length) currentGroupSetupCosts=synced;
}

function renderFormulaVars(){
    _syncSetupCostsFromDOM();
    var labels=allLabels||[];
    var h='';
    formulaVars.forEach(function(v,i){
        var typeOpts='<option value="label"'+(v.type==='label'?' selected':'')+'>標籤（料號參數）</option>'
            +'<option value="param"'+(v.type==='param'?' selected':'')+'>KPI系統參數</option>'
            +'<option value="gear"'+(v.type==='gear'?' selected':'')+'>齒輪規格</option>'
            +'<option value="qty"'+(v.type==='qty'?' selected':'')+'>數量</option>'
            +'<option value="base_cost"'+(v.type==='base_cost'?' selected':'')+'>基本費用</option>'
            +'<option value="calc_weight"'+(v.type==='calc_weight'?' selected':'')+'>自動計算重量</option>';
        var valCell='';
        if(v.type==='label'){
            var lblOpts=labels.length
                ?labels.map(function(l){return '<option value="'+l.label_id+'"'+(l.label_id==v.label_id?' selected':'')+'>'+l.label_name+'</option>';}).join('')
                :'<option value="">（無可用標籤）</option>';
            valCell='<select class="form-control input-sm" style="height:26px;padding:1px 4px;" onchange="setFormulaVarVal('+i+',this.value)">'+lblOpts+'</select>';
            // Sub-label selector: show if selected label has subs
            var selLbl=labels.filter(function(l){return l.label_id==v.label_id;})[0];
            var subs=selLbl&&selLbl.subs?selLbl.subs:[];
            if(subs.length){
                var subOpts=subs.map(function(s){return '<option value="'+s.sub_id+'"'+(s.sub_id==v.sub_id?' selected':'')+'>'+s.sub_name+'</option>';}).join('');
                // Allow blank only if parent label has direct input_type (number/text)
                var allowBlank=selLbl&&selLbl.input_type!=='none';
                if(allowBlank) subOpts='<option value=""'+(v.sub_id?'':' selected')+'>（父標籤本身）</option>'+subOpts;
                valCell+='<select class="form-control input-sm" style="height:26px;padding:1px 4px;margin-top:2px;" onchange="setFormulaVarSubId('+i+',this.value)">'+subOpts+'</select>';
            }
            // Dimension picker: show if is_dimension or is_qty_dim
            var flags=_dimFlags(v,labels);
            if(flags.isDim||flags.isQtyDim){
                var isDimSel=(v.dim_field==='dim'||v.dim_field==='dim_div'||(!v.dim_field&&!flags.isQtyDim));
                if(flags.isQtyDim){
                    // is_qty_dim: dropdown 數量 | 長×寬(圓×深)
                    var dimOpts='<option value="qty"'+(v.dim_field==='qty'?' selected':'')+'>數量</option>';
                    dimOpts+='<option value="dim"'+(isDimSel?' selected':'')+'>長×寬(圓×深)</option>';
                    valCell+='<select class="form-control input-sm" style="height:26px;padding:1px 4px;margin-top:2px;" onchange="setFormulaVarDimField('+i+',this.value)">'+dimOpts+'</select>';
                } else {
                    // is_dimension: 固定長×寬(圓×深)，不需要 dropdown
                    valCell+='<span style="display:inline-block;margin-top:3px;font-size:11px;color:#555;background:#f0f4f8;border:1px solid #d0dae6;border-radius:3px;padding:2px 7px;">長×寬(圓×深)</span>';
                }
                // checkbox 改為除法（當選取長×寬時才顯示）
                if(isDimSel){
                    var isDivChk=(v.dim_field==='dim_div');
                    valCell+='<label style="font-size:11px;font-weight:400;margin:3px 0 0;display:inline-flex;align-items:center;gap:4px;cursor:pointer;">'
                        +'<input type="checkbox"'+(isDivChk?' checked':'')+' onchange="setFormulaVarDimDiv('+i+',this.checked)"> 改為除法 寬÷長(深÷圓)</label>';
                }
            }
            // 備援：標籤無值(0)時改用指定齒輪規格或另一個標籤
            var _fbCurGear=v.fallback_gear||'';
            var _fbCurLbl=v.fallback_label_id?String(v.fallback_label_id):'';
            var _fbCurVal=_fbCurGear?'gear:'+_fbCurGear:(_fbCurLbl?'label:'+_fbCurLbl:'');
            var fbOpts='<option value="">(不設備援)</option>';
            fbOpts+='<optgroup label="── 齒輪規格 ──">';
            GEAR_FIELDS.forEach(function(f){
                fbOpts+='<option value="gear:'+f.key+'"'+(_fbCurVal==='gear:'+f.key?' selected':'')+'>'+f.name+'</option>';
            });
            fbOpts+='</optgroup>';
            if((allLabels||[]).length){
                fbOpts+='<optgroup label="── 料號標籤 ──">';
                (allLabels||[]).forEach(function(l){
                    fbOpts+='<option value="label:'+l.label_id+'"'+(_fbCurVal==='label:'+l.label_id?' selected':'')+'>'+l.label_name+'</option>';
                });
                fbOpts+='</optgroup>';
            }
            valCell+='<div style="margin-top:3px;display:flex;align-items:center;gap:4px;">'
                +'<span style="font-size:11px;color:#777;white-space:nowrap;">無值時改用：</span>'
                +'<select class="form-control input-sm" style="height:22px;padding:0 4px;font-size:11px;" onchange="setFormulaVarFallback('+i+',this.value)">'+fbOpts+'</select>'
                +'</div>';
        } else if(v.type==='param'){
            var pOpts=KPI_PARAMS.map(function(p){return '<option value="'+p.key+'"'+(p.key===v.param_key?' selected':'')+'>'+p.name+'</option>';}).join('');
            var pvStr=(v.param_value!==undefined&&v.param_value!==null&&String(v.param_value)!=='')?String(v.param_value):'';
            valCell='<select class="form-control input-sm" style="height:26px;padding:1px 4px;" onchange="setFormulaVarVal('+i+',this.value)">'+pOpts+'</select>'
                +'<input type="number" class="form-control input-sm" style="height:26px;padding:1px 6px;margin-top:2px;" placeholder="數值（空白=自動取群組設定）" step="any" value="'+pvStr+'" oninput="setFormulaVarParamValue('+i+',this.value)">';
        } else if(v.type==='gear'){
            var gOpts=GEAR_FIELDS.map(function(f){return '<option value="'+f.key+'"'+(f.key===v.gear_field?' selected':'')+'>'+f.name+'</option>';}).join('');
            valCell='<select class="form-control input-sm" style="height:26px;padding:1px 4px;" onchange="setFormulaVarVal('+i+',this.value)">'+gOpts+'</select>';
        } else if(v.type==='qty'){
            valCell='<span style="color:#555;font-size:12px;padding:4px 0;display:inline-block;">生產批量（件）</span>';
        } else if(v.type==='base_cost'){
            if(currentGroupSetupCosts.length){
                var bcOpts=currentGroupSetupCosts.map(function(sc){return '<option value="'+$('<div>').text(sc.cost_desc).html()+'"'+(sc.cost_desc===v.cost_desc?' selected':'')+'>'+sc.cost_desc+'（'+parseFloat(sc.cost_amount||0).toFixed(2)+'元）</option>';}).join('');
                valCell='<select class="form-control input-sm" style="height:26px;padding:1px 4px;" onchange="setFormulaVarVal('+i+',this.value)">'+bcOpts+'</select>';
            } else {
                valCell='<span style="color:#aaa;font-size:12px;">（尚未設定基本費用）</span>';
            }
        } else if(v.type==='calc_weight'){
            valCell='<span style="color:#0a7c58;font-size:12px;padding:4px 0;display:inline-block;">'
                +'<i class="fa fa-balance-scale"></i> 依全域重量規則自動計算 (kg)'
                +'<br><small style="color:#888;">圓柱公式：π/4×D²×L×ρ÷1,000,000</small></span>';
        }
        h+='<tr>'
          +'<td style="padding:3px 8px;text-align:center;font-weight:700;font-family:monospace;font-size:14px;">'+v.var+'</td>'
          +'<td style="padding:3px 4px;"><select class="form-control input-sm" style="height:26px;padding:1px 4px;" onchange="setFormulaVarType('+i+',this.value)">'+typeOpts+'</select></td>'
          +'<td style="padding:3px 4px;">'+(valCell||'<span style="color:#aaa;">（無資料）</span>')+'</td>'
          +'<td style="padding:3px 4px;text-align:center;"><button class="btn btn-xs btn-danger" onclick="removeFormulaVar('+i+')"><i class="fa fa-times"></i></button></td>'
          +'</tr>';
    });
    $('#formula-vars-tbody').html(h||'<tr><td colspan="4" style="text-align:center;padding:8px;color:#aaa;font-size:11px;">尚無變數，請點選「新增變數」</td></tr>');
}

// ── 公式語法驗證器 ──────────────────────────────────────────
function _fmlTokenize(e){
    var t=[],i=0,n=e.length;
    while(i<n){
        var c=e[i];
        if(/\s/.test(c)){i++;continue;}
        if(/\d/.test(c)||(c==='.'&&i+1<n&&/\d/.test(e[i+1]))){
            var s='';while(i<n&&(/\d/.test(e[i])||e[i]==='.')){s+=e[i++];}t.push(['n',parseFloat(s)]);
        }else if(i+1<n&&['>=','<=','!=','==','<>'].indexOf(e.slice(i,i+2))>=0){
            t.push(['c',e.slice(i,i+2)]);i+=2;
        }else if(c==='>'||c==='<'){t.push(['c',c]);i++;}
        else if(c==='='){t.push(['c','=']);i++;}
        else if('+-*/(),'.indexOf(c)>=0){t.push(['o',c]);i++;}
        else if(/[a-zA-Z]/.test(c)){var s2='';while(i<n&&/[a-zA-Z]/.test(e[i])){s2+=e[i++].toUpperCase();}t.push(['f',s2]);}
        else i++;
    }
    return t;
}
var _VALID_FNS=['IF','OR','AND','MAX','MIN'];
function _fmlAtom(t,p){
    if(p[0]>=t.length) throw new Error('公式未完整，缺少數值或運算子');
    var tok=t[p[0]];
    if(tok[0]==='n'){p[0]++;return tok[1];}
    if(tok[0]==='o'&&tok[1]==='('){
        p[0]++;var v=_fmlCmp(t,p);
        if(p[0]>=t.length||t[p[0]][1]!==')')throw new Error('缺少「)」');
        p[0]++;return v;
    }
    if(tok[0]==='o'&&tok[1]==='-'){p[0]++;return -_fmlAtom(t,p);}
    if(tok[0]==='f'){
        var fn=tok[1];p[0]++;
        if(_VALID_FNS.indexOf(fn)<0) throw new Error('未知函式「'+fn+'」，支援：'+_VALID_FNS.join('、'));
        if(p[0]>=t.length||t[p[0]][1]!=='(') throw new Error('函式「'+fn+'」後缺少「(」');
        p[0]++;
        var args=[];
        while(p[0]<t.length&&!(t[p[0]][0]==='o'&&t[p[0]][1]===')')){
            args.push(_fmlCmp(t,p));
            if(p[0]<t.length&&t[p[0]][0]==='o'&&t[p[0]][1]===',')p[0]++;
        }
        if(p[0]>=t.length)throw new Error('函式「'+fn+'」缺少右括號「)」');
        p[0]++;
        if(fn==='IF'&&args.length!==3)throw new Error('IF 需要 3 個參數（條件, 成立值, 不成立值），目前是 '+args.length+' 個');
        if((fn==='OR'||fn==='AND')&&args.length<2)throw new Error(fn+' 至少需要 2 個參數');
        if((fn==='MAX'||fn==='MIN')&&args.length<1)throw new Error(fn+' 至少需要 1 個參數');
        if(fn==='IF')return args[0]!==0?args[1]:args[2];
        if(fn==='OR')return args.some(function(a){return a!==0;})?1:0;
        if(fn==='AND')return args.every(function(a){return a!==0;})?1:0;
        if(fn==='MAX')return Math.max.apply(null,args);
        if(fn==='MIN')return Math.min.apply(null,args);
    }
    throw new Error('無法解析「'+(tok[1]||tok[0])+'」，可能缺少運算子或括號');
}
function _fmlMul(t,p){
    var v=_fmlAtom(t,p);
    while(p[0]<t.length&&t[p[0]][0]==='o'&&(t[p[0]][1]==='*'||t[p[0]][1]==='/')){
        var op=t[p[0]++][1],r=_fmlAtom(t,p);
        v=op==='*'?v*r:(r!==0?v/r:0);
    }
    return v;
}
function _fmlAdd(t,p){
    var v=_fmlMul(t,p);
    while(p[0]<t.length&&t[p[0]][0]==='o'&&(t[p[0]][1]==='+'||t[p[0]][1]==='-')){
        var op=t[p[0]++][1],r=_fmlMul(t,p);
        v=op==='+'?v+r:v-r;
    }
    return v;
}
function _fmlCmp(t,p){
    var l=_fmlAdd(t,p);
    if(p[0]<t.length&&t[p[0]][0]==='c'){
        var op=t[p[0]++][1],r=_fmlAdd(t,p);
        return(op==='>='?l>=r:op==='<='?l<=r:op==='>'?l>r:op==='<'?l<r:op==='='||op==='=='?l===r:l!==r)?1:0;
    }
    return l;
}
function validateFormula(rawExpr){
    // 標準化（與 PHP kpiEvalArithmetic 一致：小寫 x 也視為乘號）
    var e=rawExpr.replace(/[×✕✖×]/g,'*').replace(/x/g,'*').replace(/÷/g,'/').replace(/[−–—]/g,'-');
    // 變數全部替換成 1（只做語法測試）
    e=e.replace(/\b([A-Z])\b/g,'1');
    // 快速括號檢查
    var depth=0;
    for(var i=0;i<e.length;i++){
        if(e[i]==='(')depth++;
        else if(e[i]===')'){depth--;if(depth<0)return '括號不對稱：第 '+(i+1)+' 個字元有多餘的「)」';}
    }
    if(depth>0)return '括號不對稱：缺少 '+depth+' 個「)」';
    if(/,,/.test(e))return '逗號之間缺少參數';
    // 完整解析
    try{
        var t=_fmlTokenize(e),p=[0];
        _fmlCmp(t,p);
        if(p[0]<t.length)return '第 '+(p[0]+1)+' 個位置有多餘的內容：「'+t[p[0]][1]+'」';
        return null; // 合法
    }catch(ex){return ex.message||'語法錯誤';}
}

function updateFormulaPreview(){
    var expr=$('#gm-formula-expr').val().trim();
    if(!expr){$('#gm-formula-preview').html('<span style="color:#aaa;">（請輸入公式）</span>');return;}

    // 找出公式中所有大寫字母 token（變數名稱）
    var definedVars=formulaVars.map(function(v){return v.var;});
    var usedVars=[]; var re=/\b([A-Z])\b/g; var m;
    while((m=re.exec(expr))!==null){if(usedVars.indexOf(m[1])<0)usedVars.push(m[1]);}
    var unknownVars=usedVars.filter(function(v){return definedVars.indexOf(v)<0;});

    // 公式顯示：未定義的變數標紅底線
    var exprHtml=$('<div>').text(expr).html();
    if(unknownVars.length){
        unknownVars.forEach(function(uv){
            exprHtml=exprHtml.replace(new RegExp('\\b'+uv+'\\b','g'),
                '<span style="color:#c0392b;font-weight:700;text-decoration:underline dotted;">'+uv+'</span>');
        });
    }

    var varDesc=formulaVars.map(function(v){
        var name;
        if(v.type==='label'){
            name=v.label_name||'?';
            if(v.sub_id) name+=' › '+(v.sub_name||'子標籤#'+v.sub_id);
            if(v.dim_field==='qty') name+=' [數量]';
            else if(v.dim_field==='dim') name+=' [長×寬]';
            else if(v.dim_field==='dim_div') name+=' [寬÷長]';
            if(v.fallback_gear){var fg=GEAR_FIELDS.filter(function(f){return f.key===v.fallback_gear;})[0];name+=' (備援齒輪:'+(fg?fg.name:v.fallback_gear)+')';}
            else if(v.fallback_label_id){var fl=(allLabels||[]).filter(function(l){return l.label_id==v.fallback_label_id;})[0];name+=' (備援標籤:'+(fl?fl.label_name:'label#'+v.fallback_label_id)+')';}
        } else if(v.type==='param'){
            var p=(KPI_PARAMS.filter(function(p){return p.key===v.param_key;})[0]||{name:v.param_key||'?'});
            name=p.name;
            if(v.param_value!==undefined&&v.param_value!==null&&String(v.param_value)!=='')
                name+=' [='+v.param_value+']';
            else name+=' [自動]';
        } else if(v.type==='gear'){
            name='齒輪.'+(v.gear_field_name||v.gear_field||'?');
        } else if(v.type==='qty'){
            name='生產數量';
        } else if(v.type==='base_cost'){
            name=v.cost_desc||'基本費用';
        } else if(v.type==='calc_weight'){
            name='自動計算重量(kg)';
        } else { name='?'; }
        return '<strong>'+v.var+'</strong> = '+name;
    }).join('，');

    // 語法驗證
    var syntaxErr = unknownVars.length ? null : validateFormula(expr);

    var html='公式：<code style="font-size:12px;">'+exprHtml+'</code>';
    if(varDesc) html+='<br><small style="color:#555;">其中 '+varDesc+'</small>';

    if(unknownVars.length){
        html+='<br><span style="color:#c0392b;font-size:11px;">'
            +'<i class="fa fa-exclamation-triangle"></i> 未定義的變數：'
            +'<strong>'+unknownVars.join('、')+'</strong>，請新增對應變數</span>';
    } else if(syntaxErr){
        html+='<br><span style="color:#c0392b;font-size:11px;">'
            +'<i class="fa fa-times-circle"></i> <strong>語法錯誤：</strong>'+$('<div>').text(syntaxErr).html()+'</span>';
    } else {
        html+='<br><span style="color:#16a34a;font-size:11px;">'
            +'<i class="fa fa-check-circle"></i> 公式語法正確</span>';
    }
    $('#gm-formula-preview').html(html);
}

// ══ KPI 標準值 ════════════════════════════════════════════════
var kpiRelatedKeys = ['time_diff_alert_pct','allowance_rate']; // 屬於「相關設定」區塊的 key
function loadKpiSettings(){
    post({action:'get_kpi_settings'},function(res){
        if(!res.success)return;
        var hMain='', hRelated='';
        // 確保有 allowance_rate 欄位（若 DB 沒有，補預設值 0）
        var hasAllowance = res.data.some(function(s){return s.setting_key==='allowance_rate';});
        if(!hasAllowance){
            res.data.push({setting_key:'allowance_rate',setting_value:0,setting_label:'標準工時寬放率',setting_unit:'%',description:'計算效率前，標準工時×(1+寬放率%)，補償非生產時間'});
        }
        res.data.forEach(function(s){
            if(s.setting_key==='prod_dept_ids') return;
            var val = isNaN(parseFloat(s.setting_value)) ? 0 : parseFloat(s.setting_value);
            var field = '<div class="form-group"><label>'+s.setting_label
              +'<small class="text-muted" style="font-weight:400;"> '+(s.description||'')+'</small></label>'
              +'<div class="input-group" style="max-width:200px;"><input type="number" class="form-control" min="0" step="0.01" name="ks_'+s.setting_key+'" value="'+val.toFixed(2)+'">'
              +'<span class="input-group-addon">'+(s.setting_unit||'%')+'</span></div></div>';
            if(kpiRelatedKeys.indexOf(s.setting_key)>=0) hRelated+=field;
            else hMain+=field;
        });
        $('#kpi-settings-form').html(hMain||'<div style="color:#aaa;font-size:12px;padding:10px;">無資料</div>');
        $('#kpi-related-settings-form').html(hRelated||'<div style="color:#aaa;font-size:12px;padding:10px;">無資料</div>');
    });
}
function saveKpiSettings(){
    var s={};$('[name^="ks_"]').each(function(){s[$(this).attr('name').replace('ks_','')]=$(this).val();});
    post({action:'save_kpi_settings',settings:s},function(res){res.success?showToast('標準值已儲存'):showToast(res.message||'失敗',false);});
}
// 相關設定儲存（共用 save_kpi_settings action）
$(document).on('click','#btn-save-kpi-related-settings',function(){ saveKpiSettings(); });

// ══ 雙擊彙總列 展開/收合明細 ════════════════════════════════
$(document).on('dblclick','#agg-user-tbody tr[data-uid]',function(){
    var uid=$(this).data('uid');
    var $detail=$('#expand-user-'+uid);
    if($detail.length){$detail.toggle();return;}
    var d=getDates(),pp=10;
    var $tr=$('<tr id="expand-user-'+uid+'"><td colspan="10" style="padding:0;background:#f8fffe;"><div style="padding:10px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div></td></tr>');
    $(this).after($tr);
    post({action:'get_kpi_user_detail',date_from:d.df,date_to:d.dt,user_id:uid,machine_id:'',page:1,per_page:pp},function(res){
        if(!res.success){$tr.find('div').html('<span class="text-danger">載入失敗</span>');return;}
        var tb='<div style="overflow-x:auto;padding:8px;"><table style="width:100%;font-size:11px;border-collapse:collapse;">'
            +'<thead><tr style="background:#e8f8f5;"><th style="padding:5px 7px;">日期</th><th>料號</th><th>製程</th><th>機台</th><th>良品</th><th>NG</th><th>良品率</th><th>生產工時(h)</th><th>標準工時(h)</th><th>效率</th></tr></thead><tbody>';
        res.data.forEach(function(r){
            var ng=parseInt(r.ng_qty)||0;
            var yr=r.yield_rate!==null?r.yield_rate+'%':'—';
            var eff=r.efficiency!==null?r.efficiency+'%':'—';
            var alertStyle=r.time_alert?'background:#fff8e1;':'';
            var partLink=r.bom_no?'<span style="cursor:pointer;color:var(--info);text-decoration:underline;" onclick="openBomDrawing(\''+r.bom_no+'\')">'+(r.part_no||'—')+'</span>':(r.part_no||'—');
            tb+='<tr style="border-bottom:1px solid #e0f0ec;'+alertStyle+'">'
              +'<td style="padding:4px 7px;">'+r.report_date+'</td>'
              +'<td>'+partLink+'</td><td>'+(r.ProcessName||'—')+'</td><td>'+(r.machine||'—')+'</td>'
              +'<td>'+fmtN(r.produced_qty)+'</td><td>'+(ng>0?'<span style="color:#c0392b;">'+ng+'</span>':'0')+'</td>'
              +'<td>'+yr+'</td><td>'+fmtH(r.prod_hrs)+'</td>'
              +'<td>'+(r.std_hrs!==null?parseFloat(r.std_hrs).toFixed(2)+'h':'—')+'</td>'
              +'<td>'+eff+'</td></tr>';
        });
        tb+='</tbody></table>'+(res.total>pp?'<div style="padding:4px 8px;font-size:11px;color:#888;">顯示前'+pp+'筆，共'+res.total+'筆 | <a href="#" onclick="detailByUser('+uid+');return false;">查看全部</a></div>':'')+'</div>';
        $tr.find('td').html(tb);
    });
});
$(document).on('dblclick','#agg-mc-tbody tr[data-mid]',function(){
    var mid=$(this).data('mid');
    var $detail=$('#expand-mc-'+mid);
    if($detail.length){$detail.toggle();return;}
    var d=getDates(),pp=10;
    var $tr=$('<tr id="expand-mc-'+mid+'"><td colspan="8" style="padding:0;background:#f8fffe;"><div style="padding:10px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div></td></tr>');
    $(this).after($tr);
    post({action:'get_kpi_machine_detail',date_from:d.df,date_to:d.dt,user_id:'',machine_id:mid,page:1,per_page:pp},function(res){
        if(!res.success){$tr.find('div').html('<span class="text-danger">載入失敗</span>');return;}
        var tgtUtil=parseFloat(res.target_util)||80;
        var tb='<div style="overflow-x:auto;padding:8px;"><table style="width:100%;font-size:11px;border-collapse:collapse;">'
            +'<thead><tr style="background:#e8f8f5;"><th style="padding:5px 7px;">日期</th><th>料號</th><th>製程</th><th>生產人員</th><th>良品</th><th>NG</th><th>良品率</th><th>生產工時(h)</th><th>稼動率</th></tr></thead><tbody>';
        res.data.forEach(function(r){
            var ng=parseInt(r.ng_qty)||0;
            var util=(r.utilization!==null&&r.utilization!==undefined)?parseFloat(r.utilization):null;
            var utilStr=util===null?'<span style="color:#aaa;font-size:10px;">需設定排班</span>'
                :'<span style="color:'+(util>=tgtUtil?'var(--accent)':util>=tgtUtil*.8?'var(--warn)':'var(--danger)')+';font-weight:700;">'+util+'%</span>';
            var partLink=r.bom_no?'<span style="cursor:pointer;color:var(--info);text-decoration:underline;" onclick="openBomDrawing(\''+r.bom_no+'\')">'+(r.part_no||'—')+'</span>':(r.part_no||'—');
            tb+='<tr style="border-bottom:1px solid #e0f0ec;">'
              +'<td style="padding:4px 7px;">'+r.report_date+'</td>'
              +'<td>'+partLink+'</td><td>'+(r.ProcessName||'—')+'</td><td>'+(r.prod_user||'—')+'</td>'
              +'<td>'+fmtN(r.produced_qty)+'</td><td>'+(ng>0?'<span style="color:#c0392b;">'+ng+'</span>':'0')+'</td>'
              +'<td>'+(r.yield_rate!==null?r.yield_rate+'%':'—')+'</td>'
              +'<td>'+fmtH(r.prod_hrs)+'</td>'
              +'<td>'+utilStr+'</td></tr>';
        });
        tb+='</tbody></table>'+(res.total>pp?'<div style="padding:4px 8px;font-size:11px;color:#888;">顯示前'+pp+'筆，共'+res.total+'筆 | <a href="#" onclick="detailByMc('+mid+');return false;">查看全部</a></div>':'')+'</div>';
        $tr.find('td').html(tb);
    });
});

// ══ 生產部門設定 ══════════════════════════════════════════════
var prodDeptIds = [];
function loadProdDepts(){
    post({action:'get_prod_dept_setting'},function(res){
        if(res.success){ prodDeptIds = res.ids || []; } else { prodDeptIds = []; }
        renderProdDeptList();
    });
}
function renderProdDeptList(){
    // 2026-08-03 起生產部門由全站「組織角色綁定設定」決定（含子部門）→ 本頁一律反灰唯讀
    var depts = <?php echo json_encode($dept_list); ?>;
    var h='<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;">';
    depts.forEach(function(d){
        var dId = parseInt(d.id);
        var chk = prodDeptIds.some(function(v){ return parseInt(v)===dId; });
        h+='<label style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#f1f1f1;border-radius:6px;'
          +'border:1px solid var(--border);cursor:not-allowed;font-weight:600;font-size:13px;color:#999;">'
          +'<input type="checkbox" value="'+d.id+'" '+(chk?'checked ':'')+'disabled style="cursor:not-allowed;">'
          +d.name+'</label>';
    });
    h+='</div><div style="font-size:12px;color:#8a6d45;margin-top:8px;">此項目已統一由'
      +'<a href="../admin/org_role_setting.php" target="_blank"><b>組織角色綁定設定</b></a>'
      +'的「生產部門」決定（含其子部門，例：生產部＋生產1/2/3廠），僅能在該頁修改。</div>';
    $('#prod-dept-list').html(h);
    applyProdFilter();
}
function saveProdDepts(){
    var ids=$('#prod-dept-list input:checked').map(function(){return parseInt($(this).val());}).get();
    $.post('kpi_main.php',{action:'upsert_prod_dept_setting',ids:JSON.stringify(ids)},function(res){
        res.success ? showToast('生產單位設定已儲存') : showToast(res.message||'儲存失敗',false);
        if(res.success) prodDeptIds=ids;
    },'json');
}

// ══ 自動重量計算規則管理 ══════════════════════════════════════
var allLabelsForWeight = null; // 全部標籤（含 sub is_range/is_dimension，供重量規則用）

function loadAllLabelsForWeight(cb){
    if(allLabelsForWeight){ if(cb)cb(); return; }
    post({action:'get_all_labels'}, function(res){
        allLabelsForWeight = res.success ? (res.data||[]) : [];
        if(cb) cb();
    });
}

function _logWeightRuleDebug(){
    var s='color:%s;font-weight:bold;font-size:13px;';
    console.group('%c⚖️ 自動重量計算規則 — 系統說明','color:#0a7c58;font-weight:bold;font-size:14px;');

    console.group('%c📐 圓柱重量公式','color:#1a5c9e;font-weight:bold;');
    console.log('%cW (kg) = π/4 × D²(mm) × L(mm) × ρ(g/cm³) ÷ 1,000,000','font-family:monospace;background:#f0f4ff;padding:4px 10px;border-radius:4px;font-size:13px;');
    console.log('  D = 直徑 (mm)，多個來源取最大值');
    console.log('  L = 長度 (mm)');
    console.log('  ρ = 材質密度 (g/cm³)，由密度表關鍵字比對');
    console.log('若多條規則同時符合，取計算結果最重者');
    console.groupEnd();

    console.group('%c🗄️ 相關資料表','color:#7c3aed;font-weight:bold;');
    console.log('%c-- 1. 重量計算規則表\nSELECT * FROM kpi_weight_calc_rule WHERE is_active=1 ORDER BY sort_order, rule_id;',
        'font-family:monospace;background:#faf5ff;padding:4px 8px;border-radius:4px;white-space:pre;');
    console.log('%c欄位說明：\n  rule_id          主鍵\n  rule_name        規則名稱\n  cond_label_ids   AND條件標籤陣列 (JSON)\n  cond_or_label_ids OR條件標籤陣列 (JSON，-1=齒部外徑計算值)\n  d_sources        直徑來源陣列 (JSON)\n  l_type           長度來源類型 label/gear\n  l_label_id       長度標籤ID\n  l_sub_id         長度子標籤ID\n  l_gear_field     長度齒輪欄位名稱\n  density_src      密度來源 material/fixed\n  fixed_density_g  固定密度值\n  sort_order       排序',
        'font-family:monospace;color:#555;white-space:pre;');
    console.log('%c-- d_sources JSON 結構範例\n[\n  {"type":"label",  "label_id":5, "sub_id":0, "gear_field":""},\n  {"type":"gear",   "label_id":0, "sub_id":0, "gear_field":"da"}\n]',
        'font-family:monospace;background:#faf5ff;padding:4px 8px;border-radius:4px;white-space:pre;');

    console.log('%c-- 2. 材質密度表\nSELECT * FROM kpi_material_density ORDER BY sort_order, density_id;',
        'font-family:monospace;background:#f5fff8;padding:4px 8px;border-radius:4px;white-space:pre;');

    console.log('%c-- 3. 全域設定（關鍵字來源標籤）\nSELECT config_key, config_val FROM kpi_weight_config;\n-- config_key=\'keyword_label_id\'  → 要搜尋哪個標籤下的子標籤值',
        'font-family:monospace;background:#f5fff8;padding:4px 8px;border-radius:4px;white-space:pre;');
    console.groupEnd();

    console.group('%c🔍 觸發條件判斷邏輯','color:#b45309;font-weight:bold;');
    console.log('AND 組 + OR 組 兩者皆需通過，規則才套用：');
    console.log('  AND 組：cond_label_ids  → 所有勾選標籤都必須存在於料號 (item_label_map)');
    console.log('  OR  組：cond_or_label_ids → 至少一個成立，-1 代表齒部外徑計算值(da)>0');
    console.log('  任一組留空 = 不限');
    console.log('%c-- 查料號擁有的標籤\nSET @d_id = 0;  -- ← 填入 d_setting_id\nSELECT label_id FROM item_label_map WHERE d_id = @d_id;',
        'font-family:monospace;background:#fff8f0;padding:4px 8px;border-radius:4px;white-space:pre;');
    console.log('%c-- 查齒部外徑 da\nSELECT da FROM D_Setting_Gear WHERE d_setting_id = @d_id;',
        'font-family:monospace;background:#fff8f0;padding:4px 8px;border-radius:4px;white-space:pre;');
    console.groupEnd();

    console.group('%c📏 取值方式（D / L）','color:#1a5c9e;font-weight:bold;');
    console.log('D 多來源：依序計算每個來源的值，取所有來源中的最大值');
    console.log('%c-- 標籤來源：優先取 value_max（外徑/大徑），否則取 input_value\nSELECT input_value, value_min, value_max\nFROM item_label_map\nWHERE d_id=@d_id AND label_id=@label_id;\n\n-- 子標籤來源\nSELECT islm.input_value, islm.value_min, islm.value_max\nFROM item_sub_label_map islm\nJOIN item_label_map ilm ON ilm.map_id = islm.parent_map_id\nWHERE ilm.d_id=@d_id AND ilm.label_id=@label_id AND islm.sub_id=@sub_id;\n\n-- 齒輪欄位來源 (例如 da)\nSELECT da FROM D_Setting_Gear WHERE d_setting_id=@d_id;',
        'font-family:monospace;background:#f0f4ff;padding:4px 8px;border-radius:4px;white-space:pre;');
    console.groupEnd();

    console.group('%c🧪 密度比對邏輯','color:#0a7c58;font-weight:bold;');
    console.log('1. 取全域關鍵字來源標籤 (kpi_weight_config.keyword_label_id)');
    console.log('2. 找出該料號在此標籤下所有子標籤的值（含父標籤本身）');
    console.log('3. 逐筆比對 kpi_material_density，關鍵字包含在任一值內 → 取其密度');
    console.log('4. 若 keyword_label_id 未設定 → fallback 使用規則的 material_label_id');
    console.log('%c-- 查關鍵字來源標籤的所有子標籤值\nSELECT ilm.input_value AS parent_val,\n       islm.sub_id, islm.input_value AS sub_val\nFROM item_label_map ilm\nLEFT JOIN item_sub_label_map islm ON islm.parent_map_id = ilm.map_id\nWHERE ilm.d_id=@d_id AND ilm.label_id=@keyword_label_id;\n\n-- 密度關鍵字表\nSELECT keyword, density_g FROM kpi_material_density ORDER BY sort_order;',
        'font-family:monospace;background:#f5fff8;padding:4px 8px;border-radius:4px;white-space:pre;');
    console.groupEnd();

    console.groupEnd();
}

function loadWeightRules(){
    _logWeightRuleDebug();
    loadAllLabelsForWeight(function(){
        post({action:'get_weight_rules'}, function(res){
            if(!res.success){ showToast('載入失敗：'+(res.message||''), false); return; }
            renderDensityTable(res.densities||[], res.keyword_label_id||0, res.sub_labels||[]);
            renderWeightRuleTable(res.rules||[]);
        });
    });
}

var _densitySubLabels = [];

function _densitySubOpts(selectedSubId){
    var h = '<option value="0">（任意）</option>';
    (_densitySubLabels||[]).forEach(function(sl){
        var sel = parseInt(sl.sub_id) === parseInt(selectedSubId||0) ? ' selected' : '';
        h += '<option value="'+sl.sub_id+'"'+sel+'>'+$('<div>').text(sl.sub_name).html()+'</option>';
    });
    return h;
}

function _densityRowHtml(d){
    return '<tr>'
        +'<td><select class="form-control input-sm wr-den-sub" style="height:26px;padding:2px 4px;">'+_densitySubOpts(d.bound_sub_id||0)+'</select></td>'
        +'<td><input type="text" class="form-control input-sm wr-den-g" value="'+(parseFloat(d.density_g)||'').toString()+'" placeholder="g/cm³" style="height:26px;padding:2px 6px;"></td>'
        +'<td style="text-align:center;"><button type="button" class="btn btn-xs btn-danger" onclick="$(this).closest(\'tr\').remove()"><i class="fa fa-times"></i></button></td>'
        +'</tr>';
}

function _refreshDensitySubSelects(){
    $('#density-tbody .wr-den-sub').each(function(){
        var curVal = $(this).val();
        $(this).html(_densitySubOpts(curVal));
        if (curVal && $(this).find('option[value="'+curVal+'"]').length) $(this).val(curVal);
    });
}

function loadDensitySubLabels(labelId, callback){
    if (!labelId) {
        _densitySubLabels = [];
        _refreshDensitySubSelects();
        if(callback) callback();
        return;
    }
    post({action:'get_sub_labels', label_id:labelId}, function(res){
        _densitySubLabels = res.sub_labels || [];
        _refreshDensitySubSelects();
        if(callback) callback();
    });
}

function renderDensityTable(densities, keywordLabelId, subLabels){
    _densitySubLabels = subLabels || [];
    // 填充全域關鍵字來源標籤選單
    var opts='<option value="">（不指定，使用規則材質標籤）</option>';
    (allLabelsForWeight||[]).forEach(function(l){
        var sel=parseInt(l.label_id)===parseInt(keywordLabelId||0)?' selected':'';
        opts+='<option value="'+l.label_id+'"'+sel+'>'+$('<div>').text(l.label_name).html()+'</option>';
    });
    $('#density-keyword-label').html(opts);
    // 填充密度行
    var h='';
    densities.forEach(function(d){ h+=_densityRowHtml(d); });
    $('#density-tbody').html(h);
}

function addDensityRow(){
    $('#density-tbody').append(_densityRowHtml({keyword:'',density_g:'',bound_sub_id:0}));
}

function saveDensities(){
    var items=[];
    $('#density-tbody tr').each(function(){
        var subId=parseInt($(this).find('.wr-den-sub').val()||0)||0;
        var dg=parseFloat($(this).find('.wr-den-g').val()||0);
        if(subId>0&&dg>0) items.push({density_g:dg, bound_sub_id:subId});
    });
    var kwLblId=parseInt($('#density-keyword-label').val()||0)||0;
    post({action:'save_material_densities', items:JSON.stringify(items), keyword_label_id:kwLblId}, function(res){
        res.success ? showToast('密度表已儲存') : showToast(res.message||'儲存失敗', false);
    });
}

function renderWeightRuleTable(rules){
    if(!rules.length){
        $('#weightrule-tbody').html('<tr><td colspan="6" style="text-align:center;padding:20px;color:#aaa;">尚無規則，請點選「新增規則」</td></tr>');
        return;
    }
    var h='';
    rules.forEach(function(r){
        var andNames=(r.cond_label_names||[]);
        var orNames=(r.cond_or_label_names||[]);
        var condParts=[];
        if(andNames.length) condParts.push('<span style="color:#1a56db;">AND:</span>'+andNames.join('、'));
        if(orNames.length)  condParts.push('<span style="color:#b45309;">OR:</span>'+orNames.join('、'));
        var condStr=condParts.join(' ＋ ')||'<span style="color:#aaa;font-size:11px;">（全部料號）</span>';
        // D 多來源：顯示每個來源
        var dSources=r.d_sources||[];
        var dParts=dSources.map(function(ds){
            if(ds.type==='gear') return '齒輪:'+ds.gear_field;
            var lbl=(allLabelsForWeight||[]).filter(function(l){return parseInt(l.label_id)===parseInt(ds.label_id);})[0];
            var name=lbl?lbl.label_name:('#'+ds.label_id);
            if(ds.sub_id){var sub=(lbl?lbl.subs||[]:[]).filter(function(s){return parseInt(s.sub_id)===parseInt(ds.sub_id);})[0];if(sub)name+='>'+sub.sub_name;}
            return name;
        });
        var dStr=dParts.length?dParts.join(' / '):'—';
        var lStr=r.l_type==='gear'?('齒輪:'+r.l_gear_field):(r.l_label_name||'—');
        var denStr=r.density_src==='fixed'
            ?(parseFloat(r.fixed_density_g).toFixed(4)+' g/cm³')
            :'自動查密度表';
        h+='<tr>'
            +'<td><strong>'+$('<div>').text(r.rule_name||'').html()+'</strong></td>'
            +'<td style="font-size:11px;">'+condStr+'</td>'
            +'<td style="font-size:11px;">'+$('<div>').text(dStr).html()+'</td>'
            +'<td style="font-size:11px;">'+$('<div>').text(lStr).html()+'</td>'
            +'<td style="font-size:11px;">'+$('<div>').text(denStr).html()+'</td>'
            +'<td style="white-space:nowrap;">'
                +'<button class="btn btn-xs btn-primary" onclick="openWeightRuleModal('+r.rule_id+')" style="margin-right:3px;"><i class="fa fa-pencil"></i></button>'
                +'<button class="btn btn-xs btn-danger" onclick="deleteWeightRule('+r.rule_id+',this)"><i class="fa fa-trash"></i></button>'
            +'</td>'
            +'</tr>';
    });
    $('#weightrule-tbody').html(h);
}

// ── 減項設定 ─────────────────────────────────────────────────
function _wrDedRowHtml(ded){
    var type=(ded&&ded.type)||'cylinder';
    var desc=(ded&&ded.desc)||'';
    var dLblId=parseInt((ded&&ded.d_label_id)||0);
    var dSubId=parseInt((ded&&ded.d_sub_id)||0);
    var d2LblId=parseInt((ded&&ded.d2_label_id)||0);
    var d2SubId=parseInt((ded&&ded.d2_sub_id)||0);
    var lLblId=parseInt((ded&&ded.l_label_id)||0);
    var lSubId=parseInt((ded&&ded.l_sub_id)||0);
    var dSubHtml=_wrDSubOpts(dLblId,dSubId);
    var d2SubHtml=_wrDSubOpts(d2LblId,d2SubId);
    var lSubHtml=_wrDSubOpts(lLblId,lSubId);
    var dDf=(ded&&ded.d_dim_field)||'', d2Df=(ded&&ded.d2_dim_field)||'', lDf=(ded&&ded.l_dim_field)||'';
    return '<div class="wr-ded-row" style="border:1px solid #d4b0f0;border-radius:6px;padding:8px;margin-bottom:6px;background:#faf5ff;">'
        +'<div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">'
            +'<select class="form-control input-sm wr-ded-type" style="width:160px;height:26px;padding:2px 4px;" onchange="wrDedTypeChange(this)">'
                +'<option value="cylinder"'+(type==='cylinder'?' selected':'')+'>圓柱（內孔 / 孔加工）</option>'
                +'<option value="annulus"'+(type==='annulus'?' selected':'')+'>環形柱（外徑差）</option>'
            +'</select>'
            +'<input type="text" class="form-control input-sm wr-ded-desc" value="'+$('<div>').text(desc).html()+'" placeholder="說明（如：內孔）" style="width:110px;height:26px;padding:2px 6px;">'
            +'<button type="button" class="btn btn-xs btn-danger" onclick="$(this).closest(\'.wr-ded-row\').remove()"><i class="fa fa-times"></i></button>'
        +'</div>'
        +'<div style="display:flex;gap:4px;align-items:center;margin-bottom:3px;">'
            +'<span class="wr-ded-d-lbl-txt" style="width:26px;font-size:11px;font-weight:700;color:#5b21b6;flex-shrink:0;">'+(type==='annulus'?'D1':'D')+'</span>'
            +'<select class="form-control input-sm wr-ded-d-lbl" style="height:26px;padding:2px 4px;" onchange="wrDedLblChange(this,\'d\')">'+_wrDLblOpts(dLblId)+'</select>'
            +'<select class="form-control input-sm wr-ded-d-sub" style="height:26px;padding:2px 4px;'+(dSubHtml&&dLblId>0?'':'display:none;')+'" onchange="wrDedSubChange(this,\'d\')">'+dSubHtml+'</select>'
            +_wrDimFieldSel('wr-ded-d-dimf',dDf,dLblId,dSubId)
        +'</div>'
        +'<div class="wr-ded-d2-wrap" style="display:'+(type==='annulus'?'flex':'none')+';gap:4px;align-items:center;margin-bottom:3px;">'
            +'<span style="width:26px;font-size:11px;font-weight:700;color:#5b21b6;flex-shrink:0;">D2</span>'
            +'<select class="form-control input-sm wr-ded-d2-lbl" style="height:26px;padding:2px 4px;" onchange="wrDedLblChange(this,\'d2\')">'+_wrDLblOpts(d2LblId)+'</select>'
            +'<select class="form-control input-sm wr-ded-d2-sub" style="height:26px;padding:2px 4px;'+(d2SubHtml&&d2LblId>0?'':'display:none;')+'" onchange="wrDedSubChange(this,\'d2\')">'+d2SubHtml+'</select>'
            +_wrDimFieldSel('wr-ded-d2-dimf',d2Df,d2LblId,d2SubId)
        +'</div>'
        +'<div style="display:flex;gap:4px;align-items:center;">'
            +'<span style="width:26px;font-size:11px;font-weight:700;color:#1a56db;flex-shrink:0;">L</span>'
            +'<select class="form-control input-sm wr-ded-l-lbl" style="height:26px;padding:2px 4px;" onchange="wrDedLblChange(this,\'l\')">'+_wrDLblOpts(lLblId)+'</select>'
            +'<select class="form-control input-sm wr-ded-l-sub" style="height:26px;padding:2px 4px;'+(lSubHtml&&lLblId>0?'':'display:none;')+'" onchange="wrDedSubChange(this,\'l\')">'+lSubHtml+'</select>'
            +_wrDimFieldSel('wr-ded-l-dimf',lDf,lLblId,lSubId)
        +'</div>'
        +'</div>';
}
function renderWrDeductions(deds){
    var h='';
    (deds||[]).forEach(function(d){ h+=_wrDedRowHtml(d); });
    $('#wr-deductions').html(h||'<div style="color:#aaa;font-size:12px;padding:2px 0;">（無減項，體積不扣除）</div>');
}
function addWrDeduction(){
    $('#wr-deductions .wr-ded-row').length===0 && $('#wr-deductions').html('');
    $('#wr-deductions').append(_wrDedRowHtml(null));
}
function wrDedTypeChange(sel){
    var $row=$(sel).closest('.wr-ded-row');
    var t=$(sel).val();
    $row.find('.wr-ded-d2-wrap').css('display',t==='annulus'?'flex':'none');
    $row.find('.wr-ded-d-lbl-txt').text(t==='annulus'?'D1':'D');
}
function wrDedLblChange(sel,which){
    var $row=$(sel).closest('.wr-ded-row');
    var lblId=parseInt($(sel).val()||0);
    var $sub=$row.find('.wr-ded-'+which+'-sub');
    var subHtml=_wrDSubOpts(lblId,0);
    if(subHtml&&lblId>0){ $sub.html(subHtml).show(); }
    else { $sub.html('<option value="">(父標籤本身)</option>').hide(); }
    _wrRebuildDimSel($row.find('.wr-ded-'+which+'-dimf'),lblId,0,'');
}
function wrDedSubChange(sel,which){
    var $row=$(sel).closest('.wr-ded-row');
    var lblId=parseInt($row.find('.wr-ded-'+which+'-lbl').val()||0);
    var subId=parseInt($(sel).val()||0);
    _wrRebuildDimSel($row.find('.wr-ded-'+which+'-dimf'),lblId,subId,$row.find('.wr-ded-'+which+'-dimf').val());
}

function updateWrSubLabels(which){
    // which = 'l'
    var $sel=$('#wr-'+which+'-label');
    var $sub=$('#wr-'+which+'-sub');
    var selLblId=parseInt($sel.val()||0);
    var lbl=(allLabelsForWeight||[]).filter(function(l){return parseInt(l.label_id)===selLblId;})[0];
    var subs=lbl?(lbl.subs||[]):[];
    var subHtml='<option value="">(父標籤本身)</option>';
    subs.forEach(function(s){subHtml+='<option value="'+s.sub_id+'">'+$('<div>').text(s.sub_name).html()+'</option>';});
    $sub.html(subHtml).toggle(subs.length>0&&selLblId>0);
    _wrRebuildDimSel($('#wr-l-dim'),selLblId,0,'');
}
// L sub 選完後更新 dim_field
$(document).on('change','#wr-l-sub',function(){
    var lblId=parseInt($('#wr-l-label').val()||0);
    var subId=parseInt($(this).val()||0);
    _wrRebuildDimSel($('#wr-l-dim'),lblId,subId,$('#wr-l-dim').val());
});

function _wrDLblOpts(selLblId){
    var h='<option value="">(選擇標籤)</option>';
    (allLabelsForWeight||[]).forEach(function(l){
        h+='<option value="'+l.label_id+'"'+(parseInt(l.label_id)===parseInt(selLblId)?' selected':'')+'>'+$('<div>').text(l.label_name).html()+'</option>';
    });
    return h;
}
function _wrDGearOpts(selField){
    return GEAR_FIELDS.map(function(f){return '<option value="'+f.key+'"'+(f.key===selField?' selected':'')+'>'+f.name+'</option>';}).join('');
}
function _wrDSubOpts(lblId, selSubId){
    var lbl=(allLabelsForWeight||[]).filter(function(l){return parseInt(l.label_id)===parseInt(lblId);})[0];
    var subs=lbl?(lbl.subs||[]):[];
    if(!subs.length) return '';
    var h='<option value="">(父標籤本身)</option>';
    subs.forEach(function(s){h+='<option value="'+s.sub_id+'"'+(parseInt(s.sub_id)===parseInt(selSubId)?' selected':'')+'>'+$('<div>').text(s.sub_name).html()+'</option>';});
    return h;
}
// 根據子標籤旗標決定可用的 dim_field 選項
function _wrDimOpts(lblId, subId){
    var opts=[{val:'',label:'輸入值'}];
    if(subId>0){
        var lbl=(allLabelsForWeight||[]).filter(function(l){return parseInt(l.label_id)===parseInt(lblId);})[0];
        var sub=lbl?(lbl.subs||[]).filter(function(s){return parseInt(s.sub_id)===parseInt(subId);})[0]:null;
        if(sub){
            var pfx=sub.prefix_char?('['+sub.prefix_char+']'):'';
            if(parseInt(sub.is_triple_dim)){
                // 三值型：value_max=大圓, value_min=小圓, draw_dim=深度
                opts=[
                    {val:'max',  label: pfx+'大圓(外徑)'},
                    {val:'min',  label: pfx+'小圓(內徑)'},
                    {val:'draw', label:'深度'}
                ];
            } else if(parseInt(sub.is_dimension)){
                // 圖面/車床型：draw_dim=圖面, lathe_dim=車床
                opts=[{val:'draw',label:'圖面尺寸'},{val:'lathe',label:'車床尺寸'},{val:'',label:'長度(輸入值)'}];
            } else if(parseInt(sub.is_range)){
                // 範圍型：value_min/value_max
                opts=[{val:'',label:'輸入值'},{val:'max',label:'最大值'},{val:'min',label:'最小值'}];
            }
        }
    }
    return opts;
}
// dim_field 下拉（根據標籤/子標籤動態生成）
function _wrDimFieldSel(cls, val, lblId, subId){
    var opts=_wrDimOpts(lblId||0, subId||0);
    var v=val||'';
    if(opts.length<=1) return '<select class="form-control input-sm '+cls+'" style="width:80px;height:26px;padding:2px 3px;display:none;"><option value="">輸入值</option></select>';
    var h='<select class="form-control input-sm '+cls+'" style="min-width:96px;max-width:130px;height:26px;padding:2px 3px;" title="數值類型">';
    opts.forEach(function(o){h+='<option value="'+o.val+'"'+(o.val===v?' selected':'')+'>'+o.label+'</option>';});
    return h+'</select>';
}
// sub-label 變更時更新 dim_field 選單（事件委派）
function _wrRebuildDimSel($dimSel, lblId, subId, curVal){
    var opts=_wrDimOpts(parseInt(lblId)||0, parseInt(subId)||0);
    if(opts.length<=1){
        $dimSel.html('<option value="">輸入值</option>').val('').hide();
    } else {
        var h='';
        opts.forEach(function(o){h+='<option value="'+o.val+'">'+o.label+'</option>';});
        $dimSel.html(h).show();
        // 嘗試保留原值
        if(curVal && $dimSel.find('option[value="'+curVal+'"]').length) $dimSel.val(curVal);
        else $dimSel.val(opts[0].val);
    }
}

function _wrDSourceRowHtml(src){
    var type=(src&&src.type)||'label';
    var lblId=parseInt((src&&src.label_id)||0);
    var subId=parseInt((src&&src.sub_id)||0);
    var dimF=(src&&src.dim_field)||'';
    var gearField=(src&&src.gear_field)||'';
    var subHtml=_wrDSubOpts(lblId,subId);
    var showSub=(subHtml!==''&&lblId>0);
    return '<div class="wr-d-src-row" style="display:flex;gap:4px;align-items:flex-start;margin-bottom:4px;">'
        +'<select class="form-control input-sm wr-d-src-type" style="width:90px;flex-shrink:0;" onchange="wrDSrcTypeChange(this)">'
            +'<option value="label"'+(type==='label'?' selected':'')+'>標籤</option>'
            +'<option value="gear"'+(type==='gear'?' selected':'')+'>齒輪欄位</option>'
        +'</select>'
        +'<div class="wr-d-src-label-wrap" style="display:'+(type==='gear'?'none':'flex')+';flex-direction:column;flex:1;gap:3px;">'
            +'<select class="form-control input-sm wr-d-src-lbl" onchange="wrDSrcLblChange(this)">'+_wrDLblOpts(lblId)+'</select>'
            +'<div style="display:flex;gap:3px;">'
                +'<select class="form-control input-sm wr-d-src-sub" style="flex:1;'+(showSub?'':'display:none;')+'" onchange="wrDSrcSubChange(this)">'+subHtml+'</select>'
                +_wrDimFieldSel('wr-d-src-dimf',dimF,lblId,subId)
            +'</div>'
        +'</div>'
        +'<div class="wr-d-src-gear-wrap" style="flex:1;'+(type!=='gear'?'display:none;':'')+'">'
            +'<select class="form-control input-sm wr-d-src-gear">'+_wrDGearOpts(gearField)+'</select>'
        +'</div>'
        +'<button type="button" class="btn btn-xs btn-danger" style="flex-shrink:0;margin-top:3px;" onclick="$(this).closest(\'.wr-d-src-row\').remove()"><i class="fa fa-times"></i></button>'
        +'</div>';
}
function wrDSrcTypeChange(sel){
    var $row=$(sel).closest('.wr-d-src-row');
    var isGear=$(sel).val()==='gear';
    $row.find('.wr-d-src-label-wrap').toggle(!isGear);
    $row.find('.wr-d-src-gear-wrap').toggle(isGear);
}
function wrDSrcLblChange(sel){
    var $row=$(sel).closest('.wr-d-src-row');
    var lblId=parseInt($(sel).val()||0);
    var subHtml=_wrDSubOpts(lblId,0);
    var $sub=$row.find('.wr-d-src-sub');
    $sub.html(subHtml).toggle(subHtml!==''&&lblId>0);
    _wrRebuildDimSel($row.find('.wr-d-src-dimf'),lblId,0,'');
}
function wrDSrcSubChange(sel){
    var $row=$(sel).closest('.wr-d-src-row');
    var lblId=parseInt($row.find('.wr-d-src-lbl').val()||0);
    var subId=parseInt($(sel).val()||0);
    _wrRebuildDimSel($row.find('.wr-d-src-dimf'),lblId,subId,$row.find('.wr-d-src-dimf').val());
}
function addWrDSource(){
    $('#wr-d-sources').append(_wrDSourceRowHtml(null));
}
function renderWrDSources(sources){
    var h='';
    (sources||[]).forEach(function(s){h+=_wrDSourceRowHtml(s);});
    if(!h) h=_wrDSourceRowHtml(null);
    $('#wr-d-sources').html(h);
}

// ── 主體截面（多段模式）─────────────────────────────────────
function _wrBodySecRowHtml(sec){
    var type=(sec&&sec.type)||'cylinder';
    var dLblId=parseInt((sec&&sec.d_label_id)||0),  dSubId=parseInt((sec&&sec.d_sub_id)||0);
    var d2LblId=parseInt((sec&&sec.d2_label_id)||0), d2SubId=parseInt((sec&&sec.d2_sub_id)||0);
    var lLblId=parseInt((sec&&sec.l_label_id)||0),  lSubId=parseInt((sec&&sec.l_sub_id)||0);
    var dDf=(sec&&sec.d_dim_field)||'', d2Df=(sec&&sec.d2_dim_field)||'', lDf=(sec&&sec.l_dim_field)||'';
    var dSub=_wrDSubOpts(dLblId,dSubId), d2Sub=_wrDSubOpts(d2LblId,d2SubId), lSub=_wrDSubOpts(lLblId,lSubId);
    return '<div class="wr-bsec-row" style="border:1px solid #b7dbb7;border-radius:6px;padding:8px;margin-bottom:6px;background:#f0f8f0;">'
        +'<div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">'
            +'<select class="form-control input-sm wr-bsec-type" style="width:160px;height:26px;padding:2px 4px;" onchange="wrBSecTypeChange(this)">'
                +'<option value="cylinder"'+(type==='cylinder'?' selected':'')+'>圓柱（實心）</option>'
                +'<option value="annulus"'+(type==='annulus'?' selected':'')+'>環形柱（空心/外徑差）</option>'
            +'</select>'
            +'<button type="button" class="btn btn-xs btn-danger" onclick="$(this).closest(\'.wr-bsec-row\').remove()"><i class="fa fa-times"></i></button>'
        +'</div>'
        +'<div style="display:flex;gap:4px;align-items:center;margin-bottom:3px;">'
            +'<span class="wr-bsec-d-lbl-txt" style="width:26px;font-size:11px;font-weight:700;color:#1a6b1a;flex-shrink:0;">'+(type==='annulus'?'D1':'D')+'</span>'
            +'<select class="form-control input-sm wr-bsec-d-lbl" style="height:26px;padding:2px 4px;" onchange="wrBSecLblChange(this,\'d\')">'+_wrDLblOpts(dLblId)+'</select>'
            +'<select class="form-control input-sm wr-bsec-d-sub" style="height:26px;padding:2px 4px;'+(dSub&&dLblId>0?'':'display:none;')+'" onchange="wrBSecSubChange(this,\'d\')">'+dSub+'</select>'
            +_wrDimFieldSel('wr-bsec-d-dimf',dDf,dLblId,dSubId)
        +'</div>'
        +'<div class="wr-bsec-d2-wrap" style="display:'+(type==='annulus'?'flex':'none')+';gap:4px;align-items:center;margin-bottom:3px;">'
            +'<span style="width:26px;font-size:11px;font-weight:700;color:#1a6b1a;flex-shrink:0;">D2</span>'
            +'<select class="form-control input-sm wr-bsec-d2-lbl" style="height:26px;padding:2px 4px;" onchange="wrBSecLblChange(this,\'d2\')">'+_wrDLblOpts(d2LblId)+'</select>'
            +'<select class="form-control input-sm wr-bsec-d2-sub" style="height:26px;padding:2px 4px;'+(d2Sub&&d2LblId>0?'':'display:none;')+'" onchange="wrBSecSubChange(this,\'d2\')">'+d2Sub+'</select>'
            +_wrDimFieldSel('wr-bsec-d2-dimf',d2Df,d2LblId,d2SubId)
        +'</div>'
        +'<div style="display:flex;gap:4px;align-items:center;">'
            +'<span style="width:26px;font-size:11px;font-weight:700;color:#0a4a8a;flex-shrink:0;">L</span>'
            +'<select class="form-control input-sm wr-bsec-l-lbl" style="height:26px;padding:2px 4px;" onchange="wrBSecLblChange(this,\'l\')">'+_wrDLblOpts(lLblId)+'</select>'
            +'<select class="form-control input-sm wr-bsec-l-sub" style="height:26px;padding:2px 4px;'+(lSub&&lLblId>0?'':'display:none;')+'" onchange="wrBSecSubChange(this,\'l\')">'+lSub+'</select>'
            +_wrDimFieldSel('wr-bsec-l-dimf',lDf,lLblId,lSubId)
        +'</div>'
        +'</div>';
}
function renderWrBodySections(secs){
    var h='';
    (secs||[]).forEach(function(s){h+=_wrBodySecRowHtml(s);});
    $('#wr-body-sections').html(h||'<div style="color:#aaa;font-size:12px;padding:2px 0;">（無截面，請新增）</div>');
}
function addWrBodySection(){ $('#wr-body-sections').append(_wrBodySecRowHtml(null)); }
function wrBSecTypeChange(sel){
    var $r=$(sel).closest('.wr-bsec-row');
    var t=$(sel).val();
    $r.find('.wr-bsec-d2-wrap').css('display',t==='annulus'?'flex':'none');
    $r.find('.wr-bsec-d-lbl-txt').text(t==='annulus'?'D1':'D');
}
function wrBSecLblChange(sel,which){
    var $r=$(sel).closest('.wr-bsec-row');
    var lblId=parseInt($(sel).val()||0);
    var $sub=$r.find('.wr-bsec-'+which+'-sub');
    var subHtml=_wrDSubOpts(lblId,0);
    if(subHtml&&lblId>0){$sub.html(subHtml).show();}else{$sub.html('<option value="">(父標籤本身)</option>').hide();}
    _wrRebuildDimSel($r.find('.wr-bsec-'+which+'-dimf'),lblId,0,'');
}
function wrBSecSubChange(sel,which){
    var $r=$(sel).closest('.wr-bsec-row');
    var lblId=parseInt($r.find('.wr-bsec-'+which+'-lbl').val()||0);
    var subId=parseInt($(sel).val()||0);
    _wrRebuildDimSel($r.find('.wr-bsec-'+which+'-dimf'),lblId,subId,$r.find('.wr-bsec-'+which+'-dimf').val());
}
function switchWrBodyMode(){
    var m=$('[name="wr-body-mode"]:checked').val()||'simple';
    $('#wr-body-simple').toggle(m==='simple');
    $('#wr-body-multi').toggle(m==='multi');
}

function openWeightRuleModal(ruleId){
    loadAllLabelsForWeight(function(){
        var lblOpts='<option value="">(選擇標籤)</option>';
        (allLabelsForWeight||[]).forEach(function(l){ lblOpts+='<option value="'+l.label_id+'">'+$('<div>').text(l.label_name).html()+'</option>'; });
        $('#wr-l-label').html(lblOpts);
        $('#wr-l-sub').html('<option value="">(父標籤本身)</option>').hide();
        var gearOpts=GEAR_FIELDS.map(function(f){ return '<option value="'+f.key+'">'+f.name+'</option>'; }).join('');
        $('#wr-l-gear').html(gearOpts);
        // 條件標籤 checkboxes（AND 組 / OR 組各一份）
        var cbAndH='', cbOrH='';
        // OR 組：固定加入「齒部外徑(計算值)」特殊選項（value=-1）
        cbOrH='<label style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px;margin:2px;border-radius:4px;background:#e8f4e8;border:1px solid #b8ddb8;font-weight:400;font-size:12px;cursor:pointer;">'
            +'<input type="checkbox" class="wr-cond-or-cb" value="-1" style="cursor:pointer;">齒部外徑(計算值)</label>';
        (allLabelsForWeight||[]).forEach(function(l){
            var lbl=$('<div>').text(l.label_name).html();
            cbAndH+='<label style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px;margin:2px;border-radius:4px;background:#e8f0fe;font-weight:400;font-size:12px;cursor:pointer;">'
                +'<input type="checkbox" class="wr-cond-and-cb" value="'+l.label_id+'" style="cursor:pointer;">'+lbl+'</label>';
            cbOrH+='<label style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px;margin:2px;border-radius:4px;background:#fff3e0;font-weight:400;font-size:12px;cursor:pointer;">'
                +'<input type="checkbox" class="wr-cond-or-cb" value="'+l.label_id+'" style="cursor:pointer;">'+lbl+'</label>';
        });
        var empty='<span style="color:#aaa;font-size:12px;">（無可用標籤）</span>';
        $('#wr-cond-and-checkboxes').html(cbAndH||empty);
        $('#wr-cond-or-checkboxes').html(cbOrH||empty);

        // 預設值（新增時）
        $('#wr-id').val('');
        $('#wr-name').val('');
        $('#wr-sort').val(0);
        $('[name="wr-density-src"][value="material"]').prop('checked', true);
        $('#wr-l-type').val('label');
        $('[name="wr-body-mode"][value="simple"]').prop('checked',true);
        switchWrBodyMode();
        renderWrDSources([]);
        renderWrBodySections([]);
        renderWrDeductions([]);
        $('#wr-l-dim').val('');
        toggleWrFields();

        function _fillRule(r){
            $('#wr-id').val(r.rule_id);
            $('#wr-name').val(r.rule_name||'');
            $('#wr-sort').val(r.sort_order||0);
            // AND 條件標籤
            var cids=(r.cond_label_ids||[]).map(String);
            $('.wr-cond-and-cb').each(function(){ $(this).prop('checked', cids.indexOf(String($(this).val()))>=0); });
            // OR 條件標籤
            var orids=(r.cond_or_label_ids||[]).map(String);
            $('.wr-cond-or-cb').each(function(){ $(this).prop('checked', orids.indexOf(String($(this).val()))>=0); });
            // 主體模式
            var hasMulti=r.body_sections&&r.body_sections.length>0;
            $('[name="wr-body-mode"][value="'+(hasMulti?'multi':'simple')+'"]').prop('checked',true);
            switchWrBodyMode();
            // 簡單模式: D sources + L
            renderWrDSources(r.d_sources||[]);
            $('#wr-l-type').val(r.l_type||'label');
            if(r.l_type==='gear'){ $('#wr-l-gear').val(r.l_gear_field||''); }
            else {
                $('#wr-l-label').val(r.l_label_id||'');
                updateWrSubLabels('l');
                if(r.l_sub_id) $('#wr-l-sub').val(r.l_sub_id);
            }
            $('#wr-l-dim').val(r.l_dim_field||'');
            // 多段截面模式
            renderWrBodySections(r.body_sections||[]);
            // 減項
            renderWrDeductions(r.deduction_sources||[]);
            // 密度
            var src=r.density_src||'material';
            $('[name="wr-density-src"][value="'+src+'"]').prop('checked', true);
            if(src==='fixed') $('#wr-fixed-density').val(r.fixed_density_g||'');
            toggleWrFields();
        }

        if(ruleId > 0){
            post({action:'get_weight_rules'}, function(res){
                if(!res.success) return;
                var r=(res.rules||[]).filter(function(x){ return parseInt(x.rule_id)===ruleId; })[0];
                if(r) _fillRule(r);
            });
        }
        $('#weightrule-modal').modal('show');
    });
}

function toggleWrFields(){
    var lt=$('#wr-l-type').val();
    $('#wr-l-label-wrap').toggle(lt!=='gear');
    $('#wr-l-gear-wrap').toggle(lt==='gear');
    var ds=$('[name="wr-density-src"]:checked').val();
    $('#wr-fixed-wrap').toggle(ds==='fixed');
}

function saveWeightRule(){
    var ruleId=parseInt($('#wr-id').val()||0);
    var ruleName=$('#wr-name').val().trim();
    if(!ruleName){ showToast('請輸入規則名稱', false); return; }
    var condIds=[];
    $('.wr-cond-and-cb:checked').each(function(){ condIds.push(parseInt($(this).val())); });
    var condOrIds=[];
    $('.wr-cond-or-cb:checked').each(function(){ condOrIds.push(parseInt($(this).val())); });
    // 收集 D sources with dim_field
    var dSources=[];
    $('#wr-d-sources .wr-d-src-row').each(function(){
        var type=$(this).find('.wr-d-src-type').val();
        if(type==='gear'){
            dSources.push({type:'gear',gear_field:$(this).find('.wr-d-src-gear').val()||'',dim_field:''});
        } else {
            var lblId=parseInt($(this).find('.wr-d-src-lbl').val()||0)||0;
            var subId=parseInt($(this).find('.wr-d-src-sub').val()||0)||0;
            var dimF=$(this).find('.wr-d-src-dimf').val()||'';
            if(lblId) dSources.push({type:'label',label_id:lblId,sub_id:subId,dim_field:dimF});
        }
    });
    // 收集 body sections
    var bodySecs=[];
    $('#wr-body-sections .wr-bsec-row').each(function(){
        var t=$(this).find('.wr-bsec-type').val();
        var dLblId=parseInt($(this).find('.wr-bsec-d-lbl').val()||0)||0;
        var dSubId=parseInt($(this).find('.wr-bsec-d-sub').val()||0)||0;
        var dDimF=$(this).find('.wr-bsec-d-dimf').val()||'';
        var d2LblId=parseInt($(this).find('.wr-bsec-d2-lbl').val()||0)||0;
        var d2SubId=parseInt($(this).find('.wr-bsec-d2-sub').val()||0)||0;
        var d2DimF=$(this).find('.wr-bsec-d2-dimf').val()||'';
        var lLblId=parseInt($(this).find('.wr-bsec-l-lbl').val()||0)||0;
        var lSubId=parseInt($(this).find('.wr-bsec-l-sub').val()||0)||0;
        var lDimF=$(this).find('.wr-bsec-l-dimf').val()||'';
        if(dLblId&&lLblId) bodySecs.push({type:t,d_label_id:dLblId,d_sub_id:dSubId,d_dim_field:dDimF,d2_label_id:d2LblId,d2_sub_id:d2SubId,d2_dim_field:d2DimF,l_label_id:lLblId,l_sub_id:lSubId,l_dim_field:lDimF});
    });
    // 收集減項 with dim_field
    var deds=[];
    $('#wr-deductions .wr-ded-row').each(function(){
        var dedType=$(this).find('.wr-ded-type').val();
        var dLblId=parseInt($(this).find('.wr-ded-d-lbl').val()||0)||0;
        var dSubId=parseInt($(this).find('.wr-ded-d-sub').val()||0)||0;
        var dDimF=$(this).find('.wr-ded-d-dimf').val()||'';
        var d2LblId=parseInt($(this).find('.wr-ded-d2-lbl').val()||0)||0;
        var d2SubId=parseInt($(this).find('.wr-ded-d2-sub').val()||0)||0;
        var d2DimF=$(this).find('.wr-ded-d2-dimf').val()||'';
        var lLblId=parseInt($(this).find('.wr-ded-l-lbl').val()||0)||0;
        var lSubId=parseInt($(this).find('.wr-ded-l-sub').val()||0)||0;
        var lDimF=$(this).find('.wr-ded-l-dimf').val()||'';
        var desc=$(this).find('.wr-ded-desc').val().trim();
        if(dLblId&&lLblId) deds.push({type:dedType,desc:desc,d_label_id:dLblId,d_sub_id:dSubId,d_dim_field:dDimF,d2_label_id:d2LblId,d2_sub_id:d2SubId,d2_dim_field:d2DimF,l_label_id:lLblId,l_sub_id:lSubId,l_dim_field:lDimF});
    });
    var bodyMode=$('[name="wr-body-mode"]:checked').val()||'simple';
    var lType=$('#wr-l-type').val();
    var denSrc=$('[name="wr-density-src"]:checked').val();
    var data={
        action:'save_weight_rule',
        rule_id:ruleId,
        rule_name:ruleName,
        cond_label_ids:JSON.stringify(condIds),
        cond_or_label_ids:JSON.stringify(condOrIds),
        d_sources:JSON.stringify(dSources),
        l_type:lType,
        l_label_id:lType==='label'?($('#wr-l-label').val()||0):0,
        l_sub_id:lType==='label'?(parseInt($('#wr-l-sub').val()||0)||0):0,
        l_gear_field:lType==='gear'?$('#wr-l-gear').val():'',
        l_dim_field:lType==='label'?($('#wr-l-dim').val()||''):'',
        body_sections:JSON.stringify(bodyMode==='multi'?bodySecs:[]),
        density_src:denSrc,
        fixed_density_g:denSrc==='fixed'?$('#wr-fixed-density').val():'',
        deduction_sources:JSON.stringify(deds),
        sort_order:$('#wr-sort').val()||0
    };
    post(data, function(res){
        if(!res.success){ showToast(res.message||'儲存失敗', false); return; }
        showToast('規則已儲存');
        $('#weightrule-modal').modal('hide');
        loadWeightRules();
    });
}

function deleteWeightRule(ruleId, btn){
    if(!confirm('確認刪除此重量計算規則？')) return;
    post({action:'delete_weight_rule', rule_id:ruleId}, function(res){
        if(!res.success){ showToast(res.message||'刪除失敗', false); return; }
        showToast('已刪除');
        loadWeightRules();
    });
}

// 關鍵字來源標籤變更 → 更新各密度行的子標籤下拉
$(document).on('change','#density-keyword-label', function(){
    loadDensitySubLabels(parseInt($(this).val()||0)||0);
});

// ── 重量試算 ──────────────────────────────────────────────────
var _wpDId = 0, _wpSearchTimer = null;

function toggleWeightPreview(){
    var $p = $('#weight-preview-panel');
    $p.toggle();
    if($p.is(':visible')){ $('#wp-part-input').focus(); }
}

function _wpX(str){ return $('<div>').text(str||'').html(); }
function _wpFmtW(w){
    if(w===null||w===undefined) return '—';
    var n=parseFloat(w);
    if(isNaN(n)) return '—';
    if(n===0) return '0';
    var s=n.toFixed(6).replace(/\.?0+$/,'');
    return s;
}

$(document).on('input','#wp-part-input', function(){
    clearTimeout(_wpSearchTimer);
    _wpDId = 0;
    var val = $(this).val().trim();
    $('#wp-autocomplete').remove();
    if(!val) return;
    _wpSearchTimer = setTimeout(function(){
        post({action:'search_did', term:val}, function(res){
            if(!res.success||!res.data||!res.data.length){ $('#wp-autocomplete').remove(); return; }
            var h='';
            res.data.slice(0,12).forEach(function(item){
                h+='<div class="wp-ac-item" data-did="'+item.d_id+'" style="padding:5px 10px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
                  +_wpX(item.part_no||'')
                  +(item.Client_Name?' <span style="color:#888;">'+_wpX(item.Client_Name)+'</span>':'')
                  +'</div>';
            });
            $('#wp-autocomplete').remove();
            var $inp=$('#wp-part-input');
            var $ac=$('<div id="wp-autocomplete" style="position:absolute;z-index:9999;background:#fff;border:1px solid #ccc;border-radius:4px;width:'+Math.max($inp.outerWidth(),260)+'px;box-shadow:0 3px 10px rgba(0,0,0,.15);max-height:220px;overflow-y:auto;">'+h+'</div>');
            $inp.after($ac);
            $ac.on('mousedown','.wp-ac-item',function(e){
                e.preventDefault();
                _wpDId=parseInt($(this).data('did'));
                // 取第一個文字節點（即料號，不含 Client_Name span）
                var pno=$(this).contents().filter(function(){return this.nodeType===3;}).first().text().trim();
                $('#wp-part-input').val(pno||$(this).data('did'));
                $('#wp-autocomplete').remove();
            });
        });
    }, 280);
});
$(document).on('click', function(e){
    if(!$(e.target).closest('#weight-preview-panel').length) $('#wp-autocomplete').remove();
});

function doWeightPreview(){
    var dId=_wpDId;
    var partNo=$('#wp-part-input').val().trim();
    if(!dId && !partNo){ showToast('請先輸入料號',false); return; }
    $('#wp-result').html('<div style="text-align:center;padding:24px;color:#888;"><i class="fa fa-spinner fa-spin fa-2x"></i><br>試算中...</div>');
    post({action:'calc_weight_preview', d_id:dId, part_no:partNo}, function(res){
        if(!res.success){ showToast(res.message||'查詢失敗',false); $('#wp-result').html(''); return; }
        _renderWeightPreview(res);
    });
}

function _renderWeightPreview(data){
    var h='';
    // 料號標題
    h+='<div style="background:#f0f4f8;border-radius:6px;padding:8px 14px;margin-bottom:10px;font-size:13px;">'
      +'<strong>料號：</strong>'+_wpX(data.part_no||'')
      +(data.part_type?' &nbsp;<span style="color:#888;font-size:11px;">'+_wpX(data.part_type)+'</span>':'')
      +' &nbsp;<span style="font-size:11px;color:#555;">關鍵字來源標籤：<strong>'+_wpX(data.kw_label_name||'')+'</strong></span>'
      +'</div>';

    // 料號自身重量（優先）
    if(data.use_own_weight){
        h+='<div style="background:#e8f5e9;border:2px solid #66bb6a;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:14px;font-weight:700;">'
          +'<i class="fa fa-star" style="color:#2e7d32;"></i> 料號自身重量（d_setting.Weight_Kg，優先採用）：'+_wpFmtW(data.own_weight_kg)+' kg'
          +'<div style="font-size:11px;font-weight:400;color:#555;margin-top:3px;">Weight_Kg 欄位有值，直接採用，不套用計算規則。</div>'
          +'</div>';
    }

    // 規則列表
    (data.rules||[]).forEach(function(r){
        var ok=r.triggered;
        h+='<div style="border:1px solid '+(ok?'#a5d6a7':'#e0e0e0')+';border-radius:7px;padding:10px 14px;margin-bottom:8px;background:'+(ok?'#f1fdf3':'#fafafa')+';">';
        h+='<div style="font-weight:700;font-size:12px;margin-bottom:5px;">'+(ok?'✅':'⬛')+' 規則：'+_wpX(r.rule_name||'')+(ok?'':' <span style="color:#aaa;font-weight:400;">（條件未符合，跳過）</span>')+'</div>';

        // AND 條件
        h+='<div style="font-size:11px;margin-bottom:3px;display:flex;align-items:baseline;flex-wrap:wrap;gap:2px;">'
          +'<span style="color:#1a56db;font-weight:700;margin-right:4px;">AND：</span>';
        if(r.and_checks&&r.and_checks.length){
            r.and_checks.forEach(function(c){
                h+='<span style="color:'+(c.met?'#2e7d32':'#c62828')+';">'+(c.met?'✔':'✘')+' '+_wpX(c.label_name)+'</span> ';
            });
            h+='→ <strong style="color:'+(r.and_met?'#2e7d32':'#c62828')+'">'+(r.and_met?'通過':'未通過')+'</strong>';
        } else { h+='<span style="color:#aaa;">（不限）</span>'; }
        h+='</div>';

        // OR 條件
        h+='<div style="font-size:11px;margin-bottom:5px;display:flex;align-items:baseline;flex-wrap:wrap;gap:2px;">'
          +'<span style="color:#b45309;font-weight:700;margin-right:4px;">OR：</span>';
        if(r.or_checks&&r.or_checks.length){
            r.or_checks.forEach(function(c){
                var extra=c.value!==undefined&&c.value!==''?' ('+_wpX(String(c.value))+')':'';
                h+='<span style="color:'+(c.met?'#2e7d32':'#c62828')+';">'+(c.met?'✔':'✘')+' '+_wpX(c.label_name)+extra+'</span> ';
            });
            h+='→ <strong style="color:'+(r.or_met?'#2e7d32':'#c62828')+'">'+(r.or_met?'通過':'未通過')+'</strong>';
        } else { h+='<span style="color:#aaa;">（不限）</span>'; }
        h+='</div>';

        if(ok){
            h+='<div style="border-top:1px solid #ddd;margin:6px 0;"></div>';
            // D
            h+='<div style="font-size:11px;margin-bottom:4px;"><span style="font-weight:700;color:#444;">直徑 D 來源：</span><br>';
            (r.d_details||[]).forEach(function(d){
                var badge=d.is_max?'<span style="background:#fff3cd;border:1px solid #e0c060;font-size:10px;border-radius:2px;padding:0 4px;margin-left:4px;">✦ 採用</span>':'';
                h+='&nbsp;&nbsp;→ '+_wpX(d.display||'')+badge+'<br>';
            });
            h+='&nbsp;&nbsp;<strong style="color:#1a5c9e;">D = '+_wpFmtW(r.D)+' mm</strong></div>';
            // L
            h+='<div style="font-size:11px;margin-bottom:4px;"><span style="font-weight:700;color:#444;">長度 L 來源：</span> '
              +_wpX((r.L_detail&&r.L_detail.display)||'')
              +' <strong style="color:#1a5c9e;">L = '+_wpFmtW(r.L)+' mm</strong></div>';
            // 密度
            h+='<div style="font-size:11px;margin-bottom:6px;"><span style="font-weight:700;color:#444;">密度 ρ：</span> ';
            if(r.rho_detail){
                h+=_wpX(r.rho_detail.display||'');
                if(r.rho_detail.src==='material'&&r.rho_detail.mat_vals&&r.rho_detail.mat_vals.length){
                    h+='<br>&nbsp;&nbsp;搜尋標籤「'+_wpX(r.rho_detail.kw_label_name||'')+'」底下各子標籤：';
                    r.rho_detail.mat_vals.forEach(function(mv){
                        if(mv.value) h+='<br>&nbsp;&nbsp;&nbsp;&nbsp;'+_wpX(mv.sub_name)+' = <em>'+_wpX(mv.value)+'</em>';
                    });
                }
            }
            h+=' <strong style="color:#1a5c9e;">ρ = '+(r.rho!==null?r.rho:'—')+' g/cm³</strong></div>';
            // 公式結果
            if(r.weight_kg!==null){
                h+='<div style="background:#e3f2fd;border:1px solid #90caf9;border-radius:5px;padding:7px 12px;font-size:12px;font-family:monospace;">'
                  +'<i class="fa fa-calculator"></i> '+_wpX(r.formula_text||'')
                  +'</div>';
            } else {
                h+='<div style="color:#e65100;font-size:11px;font-weight:600;"><i class="fa fa-exclamation-triangle"></i> 無法計算：'+_wpX(r.skip_reason||'')+'</div>';
            }
        }
        h+='</div>';
    });

    // 最終結果（非自身重量才顯示）
    if(!data.use_own_weight){
        h+='<div style="border:2px solid #1976d2;background:#e3f2fd;border-radius:8px;padding:12px 16px;text-align:center;font-size:15px;font-weight:700;margin-top:4px;">';
        if(data.final_weight_kg!==null){
            h+='<i class="fa fa-balance-scale" style="color:#1976d2;"></i> 試算結果：'+_wpFmtW(data.final_weight_kg)+' kg'
              +'<div style="font-size:11px;font-weight:400;color:#555;margin-top:4px;">取所有符合規則中計算結果最重者</div>';
        } else {
            h+='<i class="fa fa-exclamation-triangle" style="color:#f57c00;"></i> 無法計算（無符合規則或資料不足）'
              +'<div style="font-size:11px;font-weight:400;color:#888;margin-top:4px;">請確認規則條件、D/L 標籤值、及密度關鍵字設定</div>';
        }
        h+='</div>';
    }

    $('#wp-result').html(h);
}

// ── 初始化 ────────────────────────────────────────────────────
$(function(){
    var now=new Date();
    $('#kpi-df').val(localDateStr(new Date(now.getFullYear(),now.getMonth(),1))); 
    $('#kpi-dt').val(localDateStr(now));

    // 顯示預設分頁（KPI儀表板）
    $('.main-pane').hide().removeClass('active');
    $('#main-kpi').show().addClass('active');
    $('.sub-pane').hide().removeClass('active');
    $('#sub-proddept').show().addClass('active');
    $('#sub-tabs-setting').hide();
    // KPI分析 tab 預設 active
    $('.tab-btn[data-main="kpi"]').addClass('active');

    // 難易係數搜尋 Enter 觸發
    $('#coeff-search').on('keypress',function(e){if(e.which===13)doDidSearch();});
    // 料號篩選 Enter 觸發
    $('#kpi-partno').on('keypress',function(e){if(e.which===13)onSearch();});
    // 料號雙擊清除
    $('#kpi-partno').on('dblclick',function(){ clearPartnoFilter(); });
    // 料號輸入時顯示/隱藏清除按鈕
    $('#kpi-partno').on('input',function(){
        var has=$(this).val().trim().length>0;
        $("#btn-clear-partno").toggle(has);
    });

    // 快速日期按鈕
    $(document).on('click','.qd-btn[data-qdate]',function(){
        setQDate($(this).data('qdate'), this);
    });

    // 製程群組新增
    $(document).on('click','#btn-new-group',function(){ openGroupModal(0); });

    // 查詢按鈕
    $(document).on('click','#btn-search',function(){ onSearch(); });

    // 篩選器
    $(document).on('change','#kpi-dept',function(){ filterUserByDept(); });
    $(document).on('change','#kpi-mc-type',function(){ filterMachineByType(); });
    $(document).on('change','#user-pp',function(){
        if(curMain==='user') loadUserAgg(1);
    });
    $(document).on('change','#mc-pp',function(){ if(curMain==='mc') loadMcAgg(1); });
    $(document).on('change','#amt-pp',function(){ loadAmtPage(1); });
    $(document).on('change','#kpi-user-pp',function(){ if(userAggData){kpiUserPage=1;renderUserAgg();} });
    $(document).on('change','#kpi-mc-pp',function(){ if(mcAggRes){kpiMcPage=1;renderMcAgg();} });

    // 未設定篩選（難易係數頁）
    $(document).on('click','#btn-unset',function(){ toggleUnset(); });

    // 儲存按鈕
    $(document).on('click','#btn-save-kpi-settings',function(){ saveKpiSettings(); });
    $(document).on('click','#btn-save-prod-depts',function(){ saveProdDepts(); });
    $(document).on('click','#btn-save-group',function(){ saveGroup(); });

    // 折舊計算即時預覽
    $(document).on('input change','#am-pamt,#am-rval,#am-years,#am-meth',function(){ calcHourlyCost(); });

    // 初始載入：儀表板 + 生產部門（人員下拉用）
    loadKpiDashboard();
    loadProdDepts();
});
</script>
</body>
</html>

<!-- 生產金額的核心公式
根據程式碼（特別是 get_production_amount 這一區塊），生產金額的計算分為「一般料號」與「齒輪料號」兩種邏輯：

1. 一般料號 (Part Type != 'G')
$$金額 = 基礎工時(秒) \times 難易係數 \times 倍數 \times 基準金額(元/秒) \times 良品數$$

2. 齒輪料號 (Part Type = 'G')
當料號種類為齒輪且有模數資料時，會引入齒輪參數作為加權： $$金額 = 基礎工時(秒) \times (模數 \times 齒數 \times 齒寬) \times 難易係數 \times 基準金額(元/秒) \times 良品數$$

詳細資料來源對照表
計算過程涉及多張資料表的關聯，以下是詳細的欄位來源：

A. 報工基礎資料 (來自 pm_process_daily_report 及其關聯)
數據項目	資料表	欄位名稱	說明
良品數	pm_process_daily_report	produced_qty	該筆報工單錄入的完成數量。
製程代號	pm_process_daily_report	process_no	用於關聯製程群組。
料號類型	d_setting	Type	判斷是否為 'G' (齒輪)。
齒輪模數	d_setting_gear	Module	僅齒輪料號使用。
齒輪齒數	d_setting_gear	Teeth	僅齒輪料號使用。
齒輪齒寬	d_setting_gear	Face_Width	僅齒輪料號使用。
B. KPI 標準設定 (優先權 1：料號個別設定 kpi_part_standard)
系統會先根據 d_setting_id 與 group_id 找尋有無特定設定。

數據項目	資料表	欄位名稱	說明
基礎工時	kpi_part_standard	base_time_sec	該料號在該群組的每 PCS 基礎秒數。
難易係數	kpi_part_standard	coefficient	數值範圍通常為 1.0 ~ 10.0。
基準金額	kpi_part_standard	base_price	每秒價值（元）。
倍數	kpi_part_standard	multiplier	複雜度倍數，預設為 1.0。
C. KPI 預設設定 (優先權 2：群組預設值 kpi_std_time_default 等)
若上述個別設定為空，則抓取製程所屬群組的預設值。

數據項目	資料表	欄位名稱	說明
預設工時	kpi_std_time_default	base_time_sec	群組通用的基礎工時。
預設金額	kpi_std_time_default	base_price	群組通用的每秒金額。
預設係數	kpi_difficulty_default	default_coefficient	群組通用的係數。
程式碼邏輯實作片段 (PHP)
在 get_production_amount (約 485 行) 與 get_kpi_user_agg (約 830 行) 中，邏輯如下實作：

php
 Show full code block 
// 1. 取得該製程對應的 KPI 標準 (Map 查詢)
$kps = $kpsMap[$procNo.'_'.$dsId] ?? $kpsMap[$procNo.'_0'] ?? null;

if ($kps) {
    $coeff  = floatval($kps['coeff'] ?? 1);
    $baseT  = floatval($kps['base_t'] ?? 0);
    $baseP  = floatval($kps['base_p'] ?? 0);
    $multi  = floatval($kps['multiplier'] ?? 1);

    // 2. 判斷是否為齒輪計算模式
    if ($r['part_type'] === 'G' && floatval($r['Module'] ?? 0) > 0) {
        // 齒輪模式：需乘上 (Module * Teeth * Face_Width)
        $amount = $baseT * floatval($r['Module']) * floatval($r['Teeth']) * floatval($r['Face_Width']) * $coeff * $baseP * $qty;
    } else {
        // 一般模式
        $amount = $baseT * $coeff * $multi * $baseP * $qty;
    }
}
總結計算流程
定位群組：透過報工的 process_no 找到所屬的 kpi_process_group。
抓取參數：
檢查 kpi_part_standard 是否有針對該料號設定。
若無，則抓取 kpi_std_time_default 與 kpi_difficulty_default 的群組預設值。
判斷規格：檢查 d_setting.Type，若是齒輪則去 d_setting_gear 抓取模數、齒數等參數。
產出結果：代入公式，算出該筆報工的「生產金額」。 -->