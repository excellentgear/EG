<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}
include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

// ── 圖面獨立視窗模式 ──────────────────────────────────────
if (isset($_GET['viewer'])) {
    $vp = htmlspecialchars(trim($_GET['part_no'] ?? ''), ENT_QUOTES, 'UTF-8');
    ?><!DOCTYPE html>
<html lang="zh-TW"><head>
<meta charset="utf-8">
<title>圖面 – <?= $vp ?></title>
<link rel="stylesheet" href="../../resource/css/font-awesome.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:sans-serif;background:#f0f0f0;overflow:hidden;}
#fl{width:200px;height:100vh;overflow-y:auto;background:#fff;border-right:1px solid #ddd;position:fixed;left:0;top:0;padding:8px;}
#fl h5{font-size:12px;font-weight:700;padding:4px 2px 8px;border-bottom:1px solid #eee;margin-bottom:6px;color:#333;}
.fi{padding:6px 8px;border-radius:4px;cursor:pointer;font-size:11px;margin-bottom:3px;word-break:break-all;}
.fi:hover{background:#f5f5f5;}
.fi.active{background:#e3f2fd;color:#1565c0;}
.fi i{margin-right:4px;}
#vw{margin-left:200px;height:100vh;overflow:auto;display:flex;align-items:center;justify-content:center;position:relative;}
#hint{color:#aaa;text-align:center;font-size:13px;}
</style>
</head><body>
<div id="fl">
    <h5><i class="fa fa-file-image-o"></i> <?= $vp ?></h5>
    <div id="fl-inner" style="color:#aaa;text-align:center;padding:16px;"><i class="fa fa-spinner fa-spin"></i></div>
</div>
<div id="vw"><div id="hint"><i class="fa fa-file-o" style="font-size:40px;"></i><br>請從左側選擇檔案</div></div>
<script src="../../resource/js/jquery.min.js"></script>
<script>
var scale=1, SELF='ERP_Cost_Analysis-NEW.php', pn='<?= addslashes($vp) ?>';
function aj(d,cb){$.post(SELF,d,cb,'json').fail(function(){alert('連線錯誤');});}
aj({action:'get_product_files',part_no:pn},function(res){
    if(!res.success||!res.files||!res.files.length){$('#fl-inner').html('<span style="color:red;">查無圖面</span>');return;}
    var h='';
    res.files.forEach(function(f){
        var ico=f.type==='pdf'?'fa-file-pdf-o':'fa-file-image-o';
        h+='<div class="fi" data-path="'+f.path.replace(/"/g,'&quot;')+'" data-type="'+f.type+'"><i class="fa '+ico+'"></i>'+f.name+'</div>';
    });
    $('#fl-inner').html(h);
});
$(document).on('click','.fi',function(){
    $('.fi').removeClass('active');$(this).addClass('active');
    var path=$(this).data('path'),type=$(this).data('type');
    scale=1;
    document.title='圖面: '+path.split('/').pop();
    var $vw=$('#vw');
    if(type==='pdf'){
        $vw.css({overflow:'hidden',alignItems:'stretch',justifyContent:'stretch'});
        $vw.html('<iframe src="'+path+'" style="width:100%;height:100vh;border:none;margin-left:-0px;"></iframe>');
    } else {
        $vw.css({overflow:'auto',alignItems:'flex-start',justifyContent:'center'});
        $vw.html('<div style="padding:16px;text-align:center;min-width:100%;"><img id="vi" src="'+path+'" style="max-width:100%;transform-origin:top center;" draggable="false"><div style="font-size:11px;color:#bbb;margin-top:6px;">滾輪 放大/縮小</div></div>');
    }
});
document.getElementById('vw').addEventListener('wheel',function(e){
    var img=document.getElementById('vi');
    if(!img)return;
    e.preventDefault();
    scale+=e.deltaY>0?-0.12:0.12;
    scale=Math.min(Math.max(0.1,scale),8);
    img.style.transform='scale('+scale+')';
},{passive:false});
</script>
</body></html><?php
    exit;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 共用：廠內成本計算 (KPI公式) — 支援工時/固定/自訂公式
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function _erpParseFactor(array &$t, int &$p): float {
    if ($p >= count($t)) return 0.0;
    $tok = $t[$p];
    if ($tok[0] === 'n') { $p++; return $tok[1]; }
    if ($tok[0] === 'o' && $tok[1] === '(') {
        $p++;
        $v = _erpParseCompare($t, $p);
        if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===')') $p++;
        return $v;
    }
    if ($tok[0] === 'o' && $tok[1] === '-') { $p++; return -_erpParseFactor($t, $p); }
    if ($tok[0] === 'f') {
        $fn = $tok[1]; $p++;
        if (!($p < count($t) && $t[$p][0]==='o' && $t[$p][1]==='(')) return 0.0;
        $p++;
        if ($fn === 'IF') {
            $cond = _erpParseCompare($t, $p);
            if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            $tv = _erpParseCompare($t, $p);
            if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            $fv = _erpParseCompare($t, $p);
            if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===')') $p++;
            return $cond != 0.0 ? $tv : $fv;
        }
        if ($fn === 'OR') {
            $r = 0.0;
            while ($p < count($t) && !($t[$p][0]==='o' && $t[$p][1]===')')) {
                if (_erpParseCompare($t, $p) != 0.0) $r = 1.0;
                if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            }
            if ($p < count($t)) $p++;
            return $r;
        }
        if ($fn === 'AND') {
            $r = 1.0; $has = false;
            while ($p < count($t) && !($t[$p][0]==='o' && $t[$p][1]===')')) {
                if (_erpParseCompare($t, $p) == 0.0) $r = 0.0;
                $has = true;
                if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            }
            if ($p < count($t)) $p++;
            return $has ? $r : 0.0;
        }
        if ($fn === 'MAX') {
            $m = null;
            while ($p < count($t) && !($t[$p][0]==='o' && $t[$p][1]===')')) {
                $v = _erpParseCompare($t, $p);
                if ($m === null || $v > $m) $m = $v;
                if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            }
            if ($p < count($t)) $p++;
            return $m ?? 0.0;
        }
        if ($fn === 'MIN') {
            $m = null;
            while ($p < count($t) && !($t[$p][0]==='o' && $t[$p][1]===')')) {
                $v = _erpParseCompare($t, $p);
                if ($m === null || $v < $m) $m = $v;
                if ($p < count($t) && $t[$p][0]==='o' && $t[$p][1]===',') $p++;
            }
            if ($p < count($t)) $p++;
            return $m ?? 0.0;
        }
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
function _erpParseTerm(array &$t, int &$p): float {
    $v = _erpParseFactor($t, $p);
    while ($p < count($t) && $t[$p][0]==='o' && in_array($t[$p][1],['*','/'])) {
        $op=$t[$p++][1]; $r=_erpParseFactor($t,$p);
        $v = $op==='*' ? $v*$r : ($r!=0 ? $v/$r : 0.0);
    }
    return $v;
}
function _erpParseExpr(array &$t, int &$p): float {
    $v = _erpParseTerm($t, $p);
    while ($p < count($t) && $t[$p][0]==='o' && in_array($t[$p][1],['+','-'])) {
        $op=$t[$p++][1]; $r=_erpParseTerm($t,$p);
        $v = $op==='+' ? $v+$r : $v-$r;
    }
    return $v;
}
function _erpParseCompare(array &$t, int &$p): float {
    $l = _erpParseExpr($t, $p);
    if ($p < count($t) && $t[$p][0] === 'c') {
        $op = $t[$p++][1]; $r = _erpParseExpr($t, $p);
        if ($op==='>=') return ($l>=$r)?1.0:0.0;
        if ($op==='<=') return ($l<=$r)?1.0:0.0;
        if ($op==='>') return ($l>$r)?1.0:0.0;
        if ($op==='<') return ($l<$r)?1.0:0.0;
        if ($op==='='||$op==='==') return (abs($l-$r)<1e-9)?1.0:0.0;
        if ($op==='!='||$op==='<>') return (abs($l-$r)>=1e-9)?1.0:0.0;
    }
    return $l;
}
function erpEvalArithmetic(string $expr): ?float {
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
    $result = _erpParseCompare($tokens, $p);
    return is_numeric($result) ? floatval($result) : null;
}
function erpComputeWeight(PDO $pdo, int $dsId, array $labelValMap, array $gearValMap, ?array &$ruleTrace = null): float {
    try {
        // ① 優先：料號直接儲存的重量（d_setting.Weight_Kg 已是 kg）
        try {
            $wkSt = $pdo->prepare("SELECT COALESCE(Weight_Kg, 0) AS w FROM d_setting WHERE d_id=? LIMIT 1");
            $wkSt->execute([$dsId]);
            $stored = floatval($wkSt->fetchColumn());
            if ($stored > 0) {
                if ($ruleTrace !== null) $ruleTrace = ['source' => 'Weight_Kg', 'weight_kg' => $stored, 'rules' => []];
                return round($stored, 6);
            }
        } catch (\Throwable $e) {}

        // ② 直接查 d_setting_gear 計算齒部外徑 da（同 master_data_management.php 邏輯）
        try {
            $gvSt = $pdo->prepare("SELECT Module, Teeth, Face_Width, Helix_Angle, Profile_Shift_X, Workpiece_Length FROM d_setting_gear WHERE d_setting_id=? ORDER BY gear_id LIMIT 1");
            $gvSt->execute([$dsId]);
            $gv = $gvSt->fetch(PDO::FETCH_ASSOC);
            if ($gv) {
                $m    = floatval(str_ireplace('m', '', $gv['Module'] ?? ''));
                $z    = floatval($gv['Teeth'] ?? 0);
                $beta = floatval($gv['Helix_Angle'] ?? 0);
                $x    = floatval($gv['Profile_Shift_X'] ?? 0);
                $gearValMap[$dsId.'_Module']           = $m;
                $gearValMap[$dsId.'_Teeth']            = $z;
                $gearValMap[$dsId.'_Face_Width']       = floatval($gv['Face_Width'] ?? 0);
                $gearValMap[$dsId.'_Helix_Angle']      = $beta;
                $gearValMap[$dsId.'_Profile_Shift_X']  = $x;
                $gearValMap[$dsId.'_Workpiece_Length'] = floatval($gv['Workpiece_Length'] ?? 0);
                if ($m > 0 && $z > 0)
                    $gearValMap[$dsId.'_da'] = round($m * $z / cos(deg2rad($beta)) + 2 * $m * (1 + $x), 2);
            }
        } catch (\Throwable $e) {}

        $rows = $pdo->query("SELECT * FROM kpi_weight_calc_rule WHERE is_active=1 ORDER BY sort_order,rule_id")->fetchAll(PDO::FETCH_ASSOC);
        $rules = [];
        foreach ($rows as $r) {
            $r['cond_label_ids']    = json_decode($r['cond_label_ids']    ?? '[]', true) ?: [];
            $r['cond_or_label_ids'] = json_decode($r['cond_or_label_ids'] ?? '[]', true) ?: [];
            $dSources = json_decode($r['d_sources'] ?? '[]', true) ?: [];
            if (empty($dSources) && !empty($r['d_type'])) {
                $dSources = [['type' => $r['d_type'] ?? 'label', 'label_id' => intval($r['d_label_id'] ?? 0),
                               'sub_id' => intval($r['d_sub_id'] ?? 0), 'gear_field' => $r['d_gear_field'] ?? '']];
            }
            $r['d_sources']  = $dSources;
            $r['cond_logic'] = !empty($r['cond_logic']) ? $r['cond_logic'] : 'AND';
            $rules[] = $r;
        }
        $densities = $pdo->query("SELECT keyword, density_g, COALESCE(bound_sub_id,0) AS bound_sub_id FROM kpi_material_density ORDER BY sort_order, density_id")->fetchAll(PDO::FETCH_ASSOC);
        $cfg = $pdo->query("SELECT config_key, config_val FROM kpi_weight_config")->fetchAll(PDO::FETCH_KEY_PAIR);
        $keywordLabelId = intval($cfg['keyword_label_id'] ?? 0);

        if ($ruleTrace !== null) $ruleTrace = ['da' => floatval($gearValMap[$dsId.'_da'] ?? 0), 'keyword_label_id' => $keywordLabelId, 'rules' => []];

        $maxWeight = null;
        foreach ($rules as $rule) {
            $tr = $ruleTrace !== null ? ['rule_id' => $rule['rule_id'], 'rule_name' => $rule['rule_name']] : null;

            // AND 條件
            $condIds = $rule['cond_label_ids'];
            if (!empty($condIds)) {
                $missing = [];
                foreach ($condIds as $cid) { if (!isset($labelValMap[$dsId.'_'.intval($cid)])) $missing[] = $cid; }
                if (!empty($missing)) {
                    if ($tr !== null) { $tr['skip'] = 'AND條件未符合 label_id='.implode(',',$missing); $ruleTrace['rules'][] = $tr; }
                    continue;
                }
            }
            // OR 條件
            $condOrIds = $rule['cond_or_label_ids'];
            if (!empty($condOrIds)) {
                $anyMet = false;
                foreach ($condOrIds as $cid) {
                    if (intval($cid) === -1) { if (floatval($gearValMap[$dsId.'_da'] ?? 0) > 0) { $anyMet = true; break; } }
                    elseif (isset($labelValMap[$dsId.'_'.intval($cid)])) { $anyMet = true; break; }
                }
                if (!$anyMet) {
                    if ($tr !== null) { $tr['skip'] = 'OR條件未符合 (da='.round(floatval($gearValMap[$dsId.'_da']??0),2).')'; $ruleTrace['rules'][] = $tr; }
                    continue;
                }
            }
            // 直徑 D
            $D = 0.0;
            $dSrcDbg = [];
            foreach ($rule['d_sources'] as $ds) {
                $dVal = 0.0;
                if (($ds['type'] ?? '') === 'gear') {
                    $dVal = floatval($gearValMap[$dsId.'_'.($ds['gear_field'] ?? '')] ?? 0);
                    if ($tr !== null) $dSrcDbg[] = 'gear:'.$ds['gear_field'].'='.$dVal;
                } else {
                    $lId = intval($ds['label_id'] ?? 0);
                    if ($lId) {
                        $sId = intval($ds['sub_id'] ?? 0);
                        $key = $dsId.'_'.$lId.($sId ? '_'.$sId : '');
                        $dVal = (isset($labelValMap[$key.'_max']) && floatval($labelValMap[$key.'_max']) > 0)
                            ? floatval($labelValMap[$key.'_max']) : floatval($labelValMap[$key] ?? 0);
                        if ($tr !== null) $dSrcDbg[] = 'label:'.$lId.($sId?'_'.$sId:'').'='.$dVal;
                    }
                }
                if ($dVal > $D) $D = $dVal;
            }
            // 長度 L
            $L = 0.0;
            if (($rule['l_type'] ?? '') === 'gear') {
                $L = floatval($gearValMap[$dsId.'_'.($rule['l_gear_field'] ?? '')] ?? 0);
                if ($tr !== null) $tr['L_src'] = 'gear:'.$rule['l_gear_field'].'='.$L;
            } else {
                $lId2 = intval($rule['l_label_id'] ?? 0);
                if ($lId2) {
                    $sId2 = intval($rule['l_sub_id'] ?? 0);
                    $key2 = $dsId.'_'.$lId2.($sId2 ? '_'.$sId2 : '');
                    $L = (isset($labelValMap[$key2.'_max']) && floatval($labelValMap[$key2.'_max']) > 0)
                        ? floatval($labelValMap[$key2.'_max']) : floatval($labelValMap[$key2] ?? 0);
                    if ($tr !== null) $tr['L_src'] = 'label:'.$lId2.($sId2?'_'.$sId2:'').'='.$L;
                }
            }
            if ($tr !== null) { $tr['D'] = $D; $tr['D_sources'] = $dSrcDbg; $tr['L'] = $L; }
            if ($D <= 0 || $L <= 0) {
                if ($tr !== null) { $tr['skip'] = 'D='.$D.' 或 L='.$L.' 為0'; $ruleTrace['rules'][] = $tr; }
                continue;
            }
            // 密度 ρ
            $rho = null; $rhoSrc = '';
            if (($rule['density_src'] ?? '') === 'fixed') {
                $rho = floatval($rule['fixed_density_g'] ?? 0) ?: null;
                $rhoSrc = 'fixed='.$rho;
            } else {
                $matLblId = intval($rule['material_label_id'] ?? 0);
                $srcLblId = $keywordLabelId > 0 ? $keywordLabelId : $matLblId;
                $matVals = [];
                if ($srcLblId > 0) {
                    $matVals[] = (string)($labelValMap[$dsId.'_'.$srcLblId] ?? '');
                    $prefix = $dsId.'_'.$srcLblId.'_'; $prefixLen = strlen($prefix);
                    foreach ($labelValMap as $k => $v) {
                        if (strncmp((string)$k, $prefix, $prefixLen) === 0 && ctype_digit(substr((string)$k, $prefixLen)))
                            $matVals[] = (string)$v;
                    }
                }
                foreach ($densities as $d) {
                    $bSid = intval($d['bound_sub_id'] ?? 0);
                    if ($bSid > 0) {
                        $specificVal = trim((string)($labelValMap[$dsId.'_'.$srcLblId.'_'.$bSid] ?? ''));
                        if ($specificVal !== '') { $rho = floatval($d['density_g']); $rhoSrc = 'sub_id:'.$bSid.'='.$rho; break; }
                    } else {
                        $kw = mb_strtolower(trim($d['keyword']), 'UTF-8'); if ($kw === '') continue;
                        foreach ($matVals as $mv) {
                            if (mb_strpos(mb_strtolower(trim($mv), 'UTF-8'), $kw) !== false) { $rho = floatval($d['density_g']); $rhoSrc = 'keyword:'.$kw.'='.$rho; break 2; }
                        }
                    }
                }
                if ($rho === null && $tr !== null) $rhoSrc = '密度未匹配 srcLblId='.$srcLblId.' matVals=['.implode(',', $matVals).']';
            }
            if (!$rho || $rho <= 0) {
                if ($tr !== null) { $tr['skip'] = $rhoSrc ?: '密度為0'; $ruleTrace['rules'][] = $tr; }
                continue;
            }
            $weight = M_PI / 4.0 * $D * $D * $L * $rho / 1000000.0;
            if ($tr !== null) { $tr['rho'] = $rho; $tr['rho_src'] = $rhoSrc; $tr['weight'] = round($weight, 6); $ruleTrace['rules'][] = $tr; }
            if ($maxWeight === null || $weight > $maxWeight) $maxWeight = $weight;
        }
        return $maxWeight !== null ? round($maxWeight, 6) : 0.0;
    } catch (\Throwable $e) { return 0.0; }
}
function erpCalcFormula(array $formulaDef, int $dsId, array $labelValMap, array $kpiParams, array $gearValMap = [], int $qty = 1, array $setupCostMap = [], array $weightMap = [], ?array &$debugVars = null): ?float {
    if (empty($formulaDef['expr'])) return null;
    $expr = preg_replace(['/[×✕✖×]/u', '/x/', '/[÷]/u', '/[−–—]/u'], ['*', '*', '/', '-'], $formulaDef['expr']) ?? $formulaDef['expr'];
    $vars = $formulaDef['vars'] ?? [];
    $replacements = [];
    $kpiParamNames = ['coeff'=>'難易係數','base_price'=>'基準金額(元/秒)','base_time'=>'基礎工時(秒)','base_amount'=>'基準金額(元)'];
    $dimLabels = ['qty'=>'(數量)','min'=>'(最小值)','max'=>'(最大值)'];
    foreach ($vars as $vc) {
        $varName = $vc['var'] ?? ''; if (!$varName) continue;
        if ($vc['type'] === 'label') {
            $labelId = intval($vc['label_id']); $subId = intval($vc['sub_id'] ?? 0);
            $baseKey = $dsId.'_'.$labelId.($subId ? '_'.$subId : '');
            $dim = $vc['dim_field'] ?? '';
            if ($dim === 'qty') {
                $val = floatval($labelValMap[$baseKey.'_qty'] ?? 0);
            } elseif ($dim === 'dim' || $dim === 'dim_div') {
                $minVal = floatval($labelValMap[$baseKey.'_min'] ?? 0);
                $maxVal = floatval($labelValMap[$baseKey.'_max'] ?? 0);
                $val = ($dim === 'dim_div') ? ($minVal != 0 ? $maxVal / $minVal : 0.0) : $minVal * $maxVal;
            } else {
                $val = floatval($labelValMap[$baseKey] ?? 0);
            }
            $desc = ($vc['label_name'] ?? '?') . (!empty($vc['sub_id']) && !empty($vc['sub_name']) ? '›'.$vc['sub_name'] : '') . ($dim ? ($dimLabels[$dim] ?? '('.$dim.')') : '');
        } elseif ($vc['type'] === 'param') {
            $val = (isset($vc['param_value']) && $vc['param_value'] !== '' && $vc['param_value'] !== null)
                ? floatval($vc['param_value']) : floatval($kpiParams[$vc['param_key'] ?? ''] ?? 0);
            $desc = $kpiParamNames[$vc['param_key'] ?? ''] ?? ($vc['param_key'] ?? '?');
        } elseif ($vc['type'] === 'gear') {
            $val = floatval($gearValMap[$dsId.'_'.($vc['gear_field'] ?? '')] ?? 0);
            $desc = '齒輪.'.($vc['gear_field'] ?? '?');
        } elseif ($vc['type'] === 'qty') {
            $val = floatval($qty);
            $desc = '數量';
        } elseif ($vc['type'] === 'base_cost') {
            $costDesc = $vc['cost_desc'] ?? '';
            if ($costDesc !== '' && isset($setupCostMap[$costDesc])) {
                $val = floatval($setupCostMap[$costDesc]);
            } elseif ($costDesc === '' && count($setupCostMap) === 1) {
                $val = floatval(reset($setupCostMap));
            } else {
                $val = 0.0;
            }
            $desc = $costDesc ?: '基本費用';
        } elseif ($vc['type'] === 'calc_weight') {
            $val = floatval($weightMap[$dsId] ?? 0);
            $desc = '自動重量(kg)';
        } else { $val = 0.0; $desc = $vc['type'] ?? '?'; }
        $replacements[$varName] = $val;
        if ($debugVars !== null) $debugVars[] = ['var' => $varName, 'desc' => $desc, 'value' => $val];
    }
    uksort($replacements, function($a,$b){ return strlen($b)-strlen($a); });
    foreach ($replacements as $varName => $val) {
        $expr = str_replace($varName, number_format($val, 10, '.', ''), $expr);
    }
    $result = erpEvalArithmetic($expr);
    return (is_numeric($result) && $result >= 0) ? floatval($result) : null;
}

function calcInhouseCostPerPc($pdo, $process_no, $d_setting_id_int, $part_type, $gear_factor) {
    // 1. 找製程群組
    $stmt = $pdo->prepare("SELECT group_id FROM kpi_process_group_map WHERE process_no = ? LIMIT 1");
    $stmt->execute([$process_no]);
    $group_id = $stmt->fetchColumn();
    if (!$group_id) return null;

    // 2. 料號專屬設定優先 → 工時計費
    $kps = $pdo->prepare("SELECT coefficient, base_time_sec, base_price, multiplier FROM kpi_part_standard WHERE d_setting_id = ? AND group_id = ? LIMIT 1");
    $kps->execute([$d_setting_id_int, $group_id]);
    $kps_row = $kps->fetch(PDO::FETCH_ASSOC);
    if ($kps_row && $kps_row['base_time_sec'] !== null && $kps_row['base_price'] !== null) {
        $base_t = floatval($kps_row['base_time_sec']);
        $base_p = floatval($kps_row['base_price']);
        $coeff  = floatval($kps_row['coefficient'] ?? 1.0);
        $multi  = floatval($kps_row['multiplier'] ?? 1.0);
        $is_gear = $part_type === 'G' && $gear_factor > 0;
        $cost   = $is_gear ? round($base_t * $gear_factor * $coeff * $base_p, 4)
                           : round($base_t * $coeff * $multi * $base_p, 4);
        return ['cost' => $cost, 'mode' => 'time',
                'debug' => ['base_t'=>$base_t,'base_p'=>$base_p,'coeff'=>$coeff,'multi'=>$multi,
                            'is_gear'=>$is_gear,'gear_factor'=>$gear_factor,'result'=>$cost]];
    }

    // 3. 取群組預設設定
    $ksd = $pdo->prepare("SELECT base_time_sec, base_price, fixed_price_per_pcs, base_amount, COALESCE(fallback_mode,'formula') AS fallback_mode FROM kpi_std_time_default WHERE group_id = ? LIMIT 1");
    $ksd->execute([$group_id]);
    $ksd_row = $ksd->fetch(PDO::FETCH_ASSOC);
    $kdd = $pdo->prepare("SELECT default_coefficient FROM kpi_difficulty_default WHERE group_id = ? LIMIT 1");
    $kdd->execute([$group_id]);
    $coeff = floatval($kdd->fetchColumn() ?: 1.0);
    $fallbackMode = $ksd_row['fallback_mode'] ?? 'formula';

    // 3b. 有實際報工紀錄時優先採用（直接查 pm_process_daily_report，不依賴快取）
    // 此優先權高於公式，與 kpi_main.php「有報工紀錄時優先採用」設定一致
    if ($d_setting_id_int > 0 && floatval($ksd_row['base_price'] ?? 0) > 0) {
        try {
            $stAvg = $pdo->prepare("
                SELECT SUM(pdr.produced_qty) AS total_qty,
                       SUM(TIMESTAMPDIFF(SECOND, pdr.production_start_time, pdr.production_end_time)) AS total_sec
                FROM pm_process_daily_report pdr
                JOIN bom_ing bi ON bi.bom_ing_fid = pdr.bom_ing_fid
                JOIN bom b      ON b.bom = bi.bom
                JOIN d_setting ds ON ds.D_Setting_Id = b.d_id
                WHERE ds.d_id = ?
                  AND bi.process_no  = ?
                  AND pdr.produced_qty > 0
                  AND pdr.production_start_time IS NOT NULL
                  AND pdr.production_end_time   IS NOT NULL
                  AND pdr.production_end_time   > pdr.production_start_time
            ");
            $stAvg->execute([$d_setting_id_int, $process_no]);
            $avgRow   = $stAvg->fetch(PDO::FETCH_ASSOC);
            $totalQty = intval($avgRow['total_qty']  ?? 0);
            $totalSec = floatval($avgRow['total_sec'] ?? 0);
            if ($totalQty > 0 && $totalSec > 0) {
                $avg_sec = $totalSec / $totalQty;
                $base_p  = floatval($ksd_row['base_price']);
                $cost    = round($avg_sec * $base_p * $coeff, 4);
                return ['cost' => $cost, 'mode' => 'time_cache',
                        'debug' => [
                            'source'          => 'pm_process_daily_report',
                            'total_qty'       => $totalQty,
                            'total_sec'       => round($totalSec, 2),
                            'avg_sec_per_pc'  => round($avg_sec, 4),
                            'avg_min_per_pc'  => round($avg_sec / 60, 4),
                            'base_price_per_sec' => $base_p,
                            'coeff'           => $coeff,
                            'result'          => $cost,
                        ]];
            }
        } catch (\Throwable $e) {}
    }

    // 4. 無工時時備用：固定金額
    if ($fallbackMode === 'fixed') {
        $fixed_p = floatval($ksd_row['fixed_price_per_pcs'] ?? 0);
        if ($fixed_p > 0) {
            return ['cost' => round($fixed_p, 4), 'mode' => 'fixed',
                    'debug' => ['fixed_price' => $fixed_p, 'fallback_mode' => 'fixed']];
        }
    }

    // 5. 無工時時備用：自訂公式
    $fq = $pdo->prepare("SELECT formula_expr, var_config FROM kpi_group_formula WHERE group_id = ? AND is_active = 1 LIMIT 1");
    $fq->execute([$group_id]);
    $formula_row = $fq->fetch(PDO::FETCH_ASSOC);
    if ($formula_row && !empty($formula_row['formula_expr'])) {
        $formulaDef = ['expr' => $formula_row['formula_expr'], 'vars' => json_decode($formula_row['var_config'] ?? '[]', true) ?? []];
        // 正規化 dim_field：DB 舊資料可能未儲存 dim_field，依 dict_label_sub.is_qty_dim / is_dimension 補上預設值
        foreach ($formulaDef['vars'] as &$_vc) {
            if (($_vc['type'] ?? '') !== 'label') continue;
            if (!empty($_vc['dim_field'])) continue;
            $subId = intval($_vc['sub_id'] ?? 0);
            if ($subId > 0) {
                try {
                    $dcSt = $pdo->prepare("SELECT COALESCE(is_qty_dim,0) AS is_qty_dim, COALESCE(is_dimension,0) AS is_dimension FROM dict_label_sub WHERE sub_id = ? LIMIT 1");
                    $dcSt->execute([$subId]);
                    $dcRow = $dcSt->fetch(PDO::FETCH_ASSOC);
                    if ($dcRow) {
                        if (intval($dcRow['is_qty_dim']) === 1)   $_vc['dim_field'] = 'qty';
                        elseif (intval($dcRow['is_dimension']) === 1) $_vc['dim_field'] = 'dim';
                    }
                } catch (\Throwable $e) {}
            }
        }
        unset($_vc);
        $kpiParams = ['coeff' => $coeff, 'base_price' => floatval($ksd_row['base_price'] ?? 0),
                      'base_time' => floatval($ksd_row['base_time_sec'] ?? 0), 'base_amount' => floatval($ksd_row['base_amount'] ?? 0)];
        $labelValMap = [];
        if ($d_setting_id_int > 0) {
            try {
                // 主標籤：input_value / value_min / value_max
                $lmSt = $pdo->prepare("SELECT label_id, COALESCE(input_value,'0') AS input_value, COALESCE(value_min,0) AS value_min, COALESCE(value_max,0) AS value_max FROM item_label_map WHERE d_id = ?");
                $lmSt->execute([$d_setting_id_int]);
                foreach ($lmSt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                    $k = $d_setting_id_int.'_'.intval($lr['label_id']);
                    $labelValMap[$k]       = $lr['input_value'];
                    $labelValMap[$k.'_min'] = strval($lr['value_min']);
                    $labelValMap[$k.'_max'] = strval($lr['value_max']);
                }
                // 子標籤：input_value / value_min / value_max
                $lmSt2 = $pdo->prepare("SELECT ilm.label_id, islm.sub_id, COALESCE(islm.input_value,'0') AS input_value, COALESCE(islm.value_min,0) AS value_min, COALESCE(islm.value_max,0) AS value_max FROM item_sub_label_map islm JOIN item_label_map ilm ON ilm.map_id=islm.parent_map_id WHERE ilm.d_id=?");
                $lmSt2->execute([$d_setting_id_int]);
                foreach ($lmSt2->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                    $k = $d_setting_id_int.'_'.intval($sr['label_id']).'_'.intval($sr['sub_id']);
                    $labelValMap[$k]       = $sr['input_value'];
                    $labelValMap[$k.'_min'] = strval($sr['value_min']);
                    $labelValMap[$k.'_max'] = strval($sr['value_max']);
                }
                // 子標籤數量：is_qty_dim 用 islm.qty 欄位，is_repeatable 用 row 數
                // SUM(COALESCE(islm.qty,1)) 同時適用兩種場景
                $lmSt3 = $pdo->prepare("SELECT ilm.label_id, islm.sub_id, SUM(COALESCE(islm.qty,1)) AS qty FROM item_sub_label_map islm JOIN item_label_map ilm ON ilm.map_id=islm.parent_map_id WHERE ilm.d_id=? GROUP BY ilm.label_id, islm.sub_id");
                $lmSt3->execute([$d_setting_id_int]);
                foreach ($lmSt3->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                    $k = $d_setting_id_int.'_'.intval($cr['label_id']).'_'.intval($cr['sub_id']);
                    $labelValMap[$k.'_qty'] = strval($cr['qty']);
                }
            } catch (\Throwable $e) {}
        }
        $gearValMap = [];
        if ($d_setting_id_int > 0 && $part_type === 'G') {
            try {
                $gvSt = $pdo->prepare("SELECT Module,Teeth,Face_Width,Helix_Angle,Profile_Shift_X,Workpiece_Length FROM d_setting_gear WHERE d_setting_id=?");
                $gvSt->execute([$d_setting_id_int]);
                $gvRow = $gvSt->fetch(PDO::FETCH_ASSOC);
                if ($gvRow) {
                    // Module 欄位可能存 "M1.5" 格式，需去除字母前綴再轉數字
                    $m = floatval(str_ireplace('m', '', $gvRow['Module'] ?? ''));
                    foreach (['Teeth','Face_Width','Helix_Angle','Profile_Shift_X','Workpiece_Length'] as $f)
                        $gearValMap[$d_setting_id_int.'_'.$f] = floatval($gvRow[$f] ?? 0);
                    $gearValMap[$d_setting_id_int.'_Module'] = $m; // 正確數值（已去除 M 前綴）
                    $z = floatval($gvRow['Teeth'] ?? 0);
                    $beta = floatval($gvRow['Helix_Angle'] ?? 0);
                    $x    = floatval($gvRow['Profile_Shift_X'] ?? 0);
                    $gearValMap[$d_setting_id_int.'_da'] = ($m > 0 && $z > 0)
                        ? round($m * $z / cos(deg2rad($beta)) + 2 * $m * (1 + $x), 2) : 0.0;
                }
            } catch (\Throwable $e) {}
        }
        $weightMap = []; $weightTrace = null;
        if ($d_setting_id_int > 0) {
            $weightTrace = [];
            $wt = erpComputeWeight($pdo, $d_setting_id_int, $labelValMap, $gearValMap, $weightTrace);
            if ($wt > 0) $weightMap[$d_setting_id_int] = $wt;
        }
        $setupCostMap = [];
        try {
            $scSt = $pdo->prepare("SELECT cost_desc, cost_amount FROM kpi_group_setup_cost WHERE group_id=? AND is_active=1");
            $scSt->execute([$group_id]);
            foreach ($scSt->fetchAll(PDO::FETCH_ASSOC) as $sc)
                $setupCostMap[$sc['cost_desc']] = floatval($sc['cost_amount']);
        } catch (\Throwable $e) {}
        $dbgVars = [];
        $result = erpCalcFormula($formulaDef, $d_setting_id_int, $labelValMap, $kpiParams, $gearValMap, 1, $setupCostMap, $weightMap, $dbgVars);
        if ($result !== null) return ['cost' => round($result, 4), 'mode' => 'formula',
                'debug' => [
                    'expr'           => $formulaDef['expr'],
                    'vars'           => $dbgVars,
                    'result'         => round($result, 4),
                    'd_setting_id'   => $d_setting_id_int,
                    'group_id'       => $group_id,
                    'var_config'     => $formulaDef['vars'],
                    'setup_cost_map' => $setupCostMap,
                    'kpi_params'     => $kpiParams,
                    'label_val_map'  => $labelValMap,
                    'gear_val_map'   => $gearValMap,
                    'weight_map'     => $weightMap,
                    'weight_trace'   => $weightTrace,
                    'sql_group'      => "SELECT group_id FROM kpi_process_group_map WHERE process_no = {$process_no} LIMIT 1",
                    'sql_formula'    => "SELECT formula_expr, var_config FROM kpi_group_formula WHERE group_id = {$group_id} AND is_active = 1 LIMIT 1",
                    'sql_label'      => "SELECT label_id, COALESCE(input_value,'0') AS input_value, COALESCE(value_min,0) AS value_min, COALESCE(value_max,0) AS value_max FROM item_label_map WHERE d_id = {$d_setting_id_int}",
                    'sql_sub_label'  => "SELECT ilm.label_id, islm.sub_id, COALESCE(islm.input_value,'0') AS input_value, COALESCE(islm.value_min,0) AS value_min, COALESCE(islm.value_max,0) AS value_max FROM item_sub_label_map islm JOIN item_label_map ilm ON ilm.map_id=islm.parent_map_id WHERE ilm.d_id={$d_setting_id_int}",
                    'sql_qty'        => "SELECT ilm.label_id, islm.sub_id, SUM(COALESCE(islm.qty,1)) AS qty FROM item_sub_label_map islm JOIN item_label_map ilm ON ilm.map_id=islm.parent_map_id WHERE ilm.d_id={$d_setting_id_int} GROUP BY ilm.label_id, islm.sub_id",
                ]];
    }

    // 6. 工時計費（群組預設）
    if (!$ksd_row || !$ksd_row['base_time_sec']) return null;
    $base_t = floatval($ksd_row['base_time_sec']);
    $base_p = floatval($ksd_row['base_price']);
    $is_gear = $part_type === 'G' && $gear_factor > 0;
    $cost = $is_gear ? round($base_t * $gear_factor * $coeff * $base_p, 4)
                     : round($base_t * $coeff * $base_p, 4);
    return ['cost' => $cost, 'mode' => 'time',
            'debug' => ['base_t'=>$base_t,'base_p'=>$base_p,'coeff'=>$coeff,'multi'=>1.0,
                        'is_gear'=>$is_gear,'gear_factor'=>$gear_factor,'result'=>$cost]];
}

// 計算一個 BOM 的完整單件成本（外包 + 廠內KPI），用於毛利分析
// $hasMissingCost = true 表示有製程無單價（成本低估，不應顯示毛利）
function calcFullBomCostPerPc(PDO $pdo, string $bom_no, int $d_setting_id_int, string $part_type, float $gear_factor, ?bool &$hasMissingCost = null): float {
    if ($hasMissingCost !== null) $hasMissingCost = false;
    $st = $pdo->prepare("
        SELECT bi.bom_sn, bi.process_no,
               COALESCE(ml.internal, 0) AS maker_internal,
               COALESCE(ext.avg_price, 0)     AS ext_price,
               COALESCE(mod_any.any_mod_avg, 0) AS manual_avg
        FROM bom_ing bi
        LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
        LEFT JOIN (
            SELECT t.bom, t.bom_sn, AVG(t.price) AS avg_price
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list ml2 ON t.maker_from = ml2.maker_id_no
            WHERE (t.price > 0 OR t.modified_unit_price > 0)
              AND (ml2.internal IS NULL OR ml2.internal != 1)
            GROUP BY t.bom, t.bom_sn
        ) ext ON bi.bom = ext.bom AND bi.bom_sn = ext.bom_sn
        LEFT JOIN (
            SELECT t.bom, t.bom_sn, AVG(t.modified_unit_price) AS any_mod_avg
            FROM bom_ing_transfer_log t
            WHERE t.modified_unit_price > 0
            GROUP BY t.bom, t.bom_sn
        ) mod_any ON bi.bom = mod_any.bom AND bi.bom_sn = mod_any.bom_sn
        WHERE bi.bom = ?
        ORDER BY bi.bom_sn
    ");
    $st->execute([$bom_no]);
    $procs = $st->fetchAll(PDO::FETCH_ASSOC);

    $total = 0.0;
    foreach ($procs as $p) {
        $manual = floatval($p['manual_avg']);
        $ext    = floatval($p['ext_price']);
        $is_internal = intval($p['maker_internal']) === 1;

        if ($manual > 0) {
            $total += $manual;
        } elseif ($ext > 0) {
            $total += $ext;
        } elseif ($is_internal) {
            $kpi = calcInhouseCostPerPc($pdo, $p['process_no'], $d_setting_id_int, $part_type, $gear_factor);
            if ($kpi !== null) {
                $c = $kpi['cost'];
                if ($c > 0 && $c < 1) $c = 1.0;
                $total += $c;
                if ($c <= 0 && $hasMissingCost !== null) $hasMissingCost = true;
            } else {
                if ($hasMissingCost !== null) $hasMissingCost = true;
            }
        } else {
            // 外包製程但無任何單價紀錄
            if ($hasMissingCost !== null) $hasMissingCost = true;
        }
    }
    return round($total, 2);
}


// ── BOM 成本快取（避免 get_margins_batch 每次重算 KPI 公式）──────────
function ensureBomCostCacheTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_cost_cache (
        bom          VARCHAR(30) NOT NULL PRIMARY KEY,
        cost_per_pc  DECIMAL(12,4) NOT NULL DEFAULT 0,
        has_missing  TINYINT(1) NOT NULL DEFAULT 0,
        cached_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getOrCalcBomCost(PDO $pdo, string $bom_no, int $d_id, string $part_type, float $gear_factor, ?bool &$hasMissing = null): float {
    // 讀快取：若快取比最新加工紀錄新，直接回傳
    try {
        $stc = $pdo->prepare("
            SELECT c.cost_per_pc, c.has_missing
            FROM bom_cost_cache c
            WHERE c.bom = ?
              AND c.cached_at >= COALESCE(
                    (SELECT MAX(t.created_at) FROM bom_ing_transfer_log t WHERE t.bom = ?),
                    '2000-01-01')
              AND c.cached_at >= COALESCE(
                    (SELECT MAX(updated_at) FROM kpi_std_time_default LIMIT 1),
                    '2000-01-01')
        ");
        $stc->execute([$bom_no, $bom_no]);
        $row = $stc->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($hasMissing !== null) $hasMissing = (bool)$row['has_missing'];
            return floatval($row['cost_per_pc']);
        }
    } catch (\Throwable $e) { /* 快取表不存在時靜默繼續 */ }

    // 快取未命中：重新計算並寫入
    $miss = false;
    $cost = calcFullBomCostPerPc($pdo, $bom_no, $d_id, $part_type, $gear_factor, $miss);
    if ($hasMissing !== null) $hasMissing = $miss;
    try {
        $pdo->prepare("INSERT INTO bom_cost_cache (bom, cost_per_pc, has_missing) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE cost_per_pc=VALUES(cost_per_pc), has_missing=VALUES(has_missing), cached_at=NOW()")
            ->execute([$bom_no, $cost, $miss ? 1 : 0]);
    } catch (\Throwable $e) {}
    return $cost;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// AJAX 路由
// ── 齒輪規格字串 SQL 子查詢（與 master_data_management.php 共用邏輯）────
function build_gear_spec_sql(string $id_expr): string {
    $tr = [
        '{Module}'            => "COALESCE(NULLIF(g.module_display,''), IF(g.Module IS NOT NULL AND g.Module<>'', IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M',g.Module)), ''))",
        '{Teeth}'             => "COALESCE(CAST(NULLIF(g.Teeth,0) AS CHAR),'')",
        '{Face_Width}'        => "IF(g.Face_Width IS NOT NULL AND g.Face_Width>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR))), '')",
        '{Pressure_Angle}'    => "TRIM(TRAILING '°' FROM TRIM(COALESCE(g.Pressure_Angle,'')))",
        '{Helix_Direction}'   => "COALESCE(NULLIF(g.Helix_Direction,''),'')",
        '{Helix_Angle_Str}'   => "COALESCE(NULLIF(g.Helix_Angle_Str,''), IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR))), ''))",
        '{spec_starts}'       => "COALESCE(CAST(NULLIF(g.spec_starts,0) AS CHAR),'')",
        '{X_PART}'            => "IF(g.Profile_Shift_X IS NOT NULL AND g.Profile_Shift_X<>0, CONCAT('X',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Profile_Shift_X AS CHAR)))), '')",
        '{GRADE}'             => "IF(g.gear_quality_std IS NOT NULL AND g.gear_quality_std<>'', CONCAT(g.gear_quality_std,COALESCE(CAST(g.gear_quality_grade AS CHAR),'')), '')",
        '{spec_chain_size}'   => "COALESCE(g.spec_chain_size,'')",
        '{spec_pulley_profile}' => "COALESCE(g.spec_pulley_profile,'')",
        '{spec_spline_type}'  => "COALESCE(g.spec_spline_type,'')",
        '{spec_spline_major_dia}' => "IF(g.spec_spline_major_dia IS NOT NULL AND g.spec_spline_major_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_major_dia AS CHAR))), '')",
        '{spec_spline_minor_dia}' => "IF(g.spec_spline_minor_dia IS NOT NULL AND g.spec_spline_minor_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_minor_dia AS CHAR))), '')",
        '{spec_spline_width}' => "IF(g.spec_spline_width IS NOT NULL AND g.spec_spline_width>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_width AS CHAR))), '')",
        '{Remark_Gear}'       => "COALESCE(NULLIF(g.Remark_Gear,''),'')",
    ];
    $tmpl = 'dt.display_template';
    foreach ($tr as $token => $expr) $tmpl = "REPLACE($tmpl, '$token', $expr)";
    return "(SELECT GROUP_CONCAT(
        CASE
          WHEN dt.display_template IS NOT NULL AND dt.display_template<>'' THEN $tmpl
          WHEN dt.spec_category='spline' AND g.spec_spline_type='矩形' THEN
            CONCAT(IF(g.Teeth>0,CONCAT(g.Teeth,'鍵 '),''),COALESCE(CAST(g.spec_spline_minor_dia AS CHAR),'?'),' × ',COALESCE(CAST(g.spec_spline_major_dia AS CHAR),'?'),' × ',COALESCE(CAST(g.spec_spline_width AS CHAR),'?'))
          ELSE
            CONCAT(
              IF(g.Module IS NOT NULL AND g.Module<>'',IF(LEFT(UPPER(g.Module),1)='M',g.Module,CONCAT('M',g.Module)),''),
              IF(dt.spec_category='worm_gear' AND g.spec_starts IS NOT NULL AND g.spec_starts>0,CONCAT('×',g.spec_starts,'條'),IF(g.Teeth IS NOT NULL AND g.Teeth>0,CONCAT('×',g.Teeth,'T'),'')),
              IF(g.Face_Width IS NOT NULL AND g.Face_Width>0,CONCAT(' W',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR)))),''),
              IF(g.Pressure_Angle IS NOT NULL AND g.Pressure_Angle<>'',CONCAT(' PA',g.Pressure_Angle,'°'),''),
              IF(g.Helix_Direction IS NOT NULL AND g.Helix_Direction<>'',CONCAT(' ',g.Helix_Direction),''),
              IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle>0,CONCAT(' ',COALESCE(NULLIF(g.Helix_Angle_Str,''),TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR)))),'°'),''),
              IF(g.Profile_Shift_X IS NOT NULL AND g.Profile_Shift_X<>0,CONCAT(' X',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Profile_Shift_X AS CHAR)))),'')
            )
        END
        ORDER BY g.gear_id SEPARATOR ' / ')
     FROM d_setting_gear g LEFT JOIN dict_gear_type dt ON dt.gear_type_id=g.Gear_Type
     WHERE g.d_setting_id=$id_expr)";
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    $conn = new DBConnection();
    $pdo  = $conn->getPDO();
    $pdo->exec("SET NAMES utf8mb4");
    ensureBomCostCacheTable($pdo);

    // ── 自動完成搜尋料號 ──────────────────────────────────────
    if ($action === 'search_parts') {
        $kw = trim($_POST['keyword'] ?? '');
        try {
            $like = "%$kw%";
            $st = $pdo->prepare("
                SELECT ds.D_Setting_Id AS part_no, ds.Drawing_No, ds.Spec_No,
                       COALESCE(cl.customer, '') AS client_name
                FROM d_setting ds
                LEFT JOIN customer_list cl ON ds.Customer_Id = cl.customer_id
                WHERE ds.D_Setting_Id LIKE ? OR cl.customer LIKE ? OR ds.Drawing_No LIKE ?
                ORDER BY ds.D_Setting_Id
                LIMIT 20
            ");
            $st->execute([$like, $like, $like]);
            echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 料號活動日期快取（避免每次查詢跑大量子查詢）────────────
    function ensurePartDateCache($pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS part_date_cache (
                part_no     VARCHAR(50) NOT NULL PRIMARY KEY,
                latest_date DATE        NOT NULL,
                rebuilt_at  DATETIME    NOT NULL,
                INDEX idx_latest (latest_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $row = $pdo->query("SELECT COUNT(*), MIN(rebuilt_at) FROM part_date_cache")->fetch(PDO::FETCH_NUM);
        // 快取有效期 10 分鐘，有資料且未過期則直接返回
        if ($row[0] > 0 && $row[1] && (time() - strtotime($row[1])) < 600) return;

        $pdo->exec("TRUNCATE TABLE part_date_cache");
        $pdo->exec("
            INSERT INTO part_date_cache (part_no, latest_date, rebuilt_at)
            SELECT part_no, MAX(d) AS latest_date, NOW()
            FROM (
                SELECT Product_id AS part_no, MAX(DATE(Order_date)) AS d
                FROM is_list WHERE Order_date IS NOT NULL GROUP BY Product_id
                UNION ALL
                SELECT d_id, MAX(DATE(Order_date))
                FROM order_track WHERE Order_date IS NOT NULL GROUP BY d_id
                UNION ALL
                SELECT b.d_id, MAX(DATE(tl.transfer_date))
                FROM bom b
                JOIN bom_ing bi ON bi.bom = b.bom
                JOIN bom_ing_transfer_log tl ON tl.bom = bi.bom AND tl.bom_sn = bi.bom_sn
                WHERE tl.transfer_date IS NOT NULL
                GROUP BY b.d_id
            ) sub
            GROUP BY part_no
        ");
    }

    // ── 料號清單（分頁，含成本/售價摘要）─────────────────────
    if ($action === 'get_part_list') {
        $page    = max(1, intval($_POST['page'] ?? 1));
        $pp      = min(100, max(5, intval($_POST['per_page'] ?? 20)));
        $offset  = ($page - 1) * $pp;
        $kw      = trim($_POST['keyword'] ?? '');
        $client  = trim($_POST['client'] ?? '');
        $f_margin  = $_POST['filter_margin'] ?? ''; // 'loss','low','mid','ok'
        $low_thr   = max(0, min(99, floatval($_POST['low_threshold'] ?? 10)));
        $ok_thr    = max(0, min(99, floatval($_POST['ok_threshold']  ?? 20)));
        $date_from = trim($_POST['date_from'] ?? '');
        $date_to   = trim($_POST['date_to']   ?? '');

        try {
            $where = "1=1";
            $params = [];
            if ($kw) {
                $where .= " AND (ds.D_Setting_Id LIKE ? OR ds.Drawing_No LIKE ?)";
                $like = "%$kw%";
                $params[] = $like; $params[] = $like;
            }
            if ($client) {
                $where .= " AND cl.customer LIKE ?";
                $params[] = "%$client%";
            }
            // 日期區間篩選：從快取表撈符合的料號，單次 indexed 查詢極快
            if ($date_from || $date_to) {
                $df = $date_from ?: '1900-01-01';
                $dt = $date_to   ?: '2099-12-31';
                ensurePartDateCache($pdo);
                $st_cache = $pdo->prepare(
                    "SELECT part_no FROM part_date_cache WHERE latest_date BETWEEN ? AND ?"
                );
                $st_cache->execute([$df, $dt]);
                $active_ids = $st_cache->fetchAll(PDO::FETCH_COLUMN);
                if ($active_ids) {
                    $ph = implode(',', array_fill(0, count($active_ids), '?'));
                    $where .= " AND ds.D_Setting_Id IN ($ph)";
                    foreach ($active_ids as $aid) $params[] = $aid;
                } else {
                    $where .= " AND 1=0";
                }
            }

            $count_sql = "SELECT COUNT(DISTINCT ds.d_id) FROM d_setting ds LEFT JOIN customer_list cl ON ds.Customer_Id = cl.customer_id WHERE $where";
            $cnt = $pdo->prepare($count_sql); $cnt->execute($params);
            $total = intval($cnt->fetchColumn());

            // ② 主查詢：只取基本欄位，齒輪規格用 display_template subquery
            $gear_spec_subq = build_gear_spec_sql('ds.d_id');
            $sql = "
                SELECT ds.d_id, ds.D_Setting_Id AS part_no, ds.Drawing_No, ds.Spec_No, ds.Type AS part_type,
                       COALESCE(cl.customer, '') AS client_name,
                       $gear_spec_subq AS gear_spec_str
                FROM d_setting ds
                LEFT JOIN customer_list cl ON ds.Customer_Id = cl.customer_id
                WHERE $where
                ORDER BY ds.D_Setting_Id ASC
                LIMIT ? OFFSET ?
            ";
            $plist = $pdo->prepare($sql);
            foreach ($params as $i => $v) $plist->bindValue($i + 1, $v);
            $plist->bindValue(count($params) + 1, $pp, PDO::PARAM_INT);
            $plist->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
            $plist->execute();
            $rows = $plist->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                echo json_encode(['success' => true, 'data' => [], 'total' => $total, 'page' => $page, 'per_page' => $pp]);
                exit;
            }

            // 本頁的 part_no 清單（varchar）
            $part_nos = array_column($rows, 'part_no');
            $ph_p     = implode(',', array_fill(0, count($part_nos), '?'));

            // ③ 批量：is_list 出貨統計（排除 is_count=0 不納入計算的性質，與 Shipping_Analysis 一致）
            $st_ship = $pdo->prepare("
                SELECT il.Product_id,
                       ROUND(AVG(CASE WHEN il.Unit_price > 0 THEN il.Unit_price ELSE NULL END), 2) AS avg_sell_price,
                       MAX(CASE WHEN il.Unit_price > 0 THEN il.Order_date ELSE NULL END)           AS last_ship_date,
                       COUNT(*) AS ship_count
                FROM is_list il
                LEFT JOIN is_sale_type ist ON il.sale_type = ist.sale_type_id
                WHERE il.Product_id IN ($ph_p)
                  AND (ist.is_count IS NULL OR ist.is_count != 0)
                GROUP BY il.Product_id
            ");
            $st_ship->execute($part_nos);
            $ship_map = [];
            foreach ($st_ship->fetchAll(PDO::FETCH_ASSOC) as $s) $ship_map[$s['Product_id']] = $s;

            // ③b 批量：order_track 訂單平均單價（is_list 無資料時的備用售價）
            $st_ord_price = $pdo->prepare("
                SELECT d_id,
                       ROUND(AVG(CASE WHEN unit_price > 0 THEN unit_price ELSE NULL END), 2) AS avg_order_price
                FROM order_track
                WHERE d_id IN ($ph_p) AND unit_price > 0
                GROUP BY d_id
            ");
            $st_ord_price->execute($part_nos);
            $ord_price_map = [];
            foreach ($st_ord_price->fetchAll(PDO::FETCH_ASSOC) as $o) $ord_price_map[$o['d_id']] = floatval($o['avg_order_price']);

            // ④ 批量：bom 數量
            $st_bc = $pdo->prepare("SELECT d_id AS part_no, COUNT(*) AS bom_count FROM bom WHERE d_id IN ($ph_p) GROUP BY d_id");
            $st_bc->execute($part_nos);
            $bom_cnt_map = [];
            foreach ($st_bc->fetchAll(PDO::FETCH_ASSOC) as $b) $bom_cnt_map[$b['part_no']] = intval($b['bom_count']);

            // ⑤ 批量：每個料號的最新 BOM（依最新 transfer_date 排序，PHP 取第一筆）
            $st_lb = $pdo->prepare("
                SELECT b.d_id AS part_no, b.bom, b.sqty,
                       COALESCE(MAX(tl.transfer_date), '1900-01-01') AS max_tl_date
                FROM bom b
                LEFT JOIN bom_ing_transfer_log tl ON tl.bom = b.bom
                WHERE b.d_id IN ($ph_p)
                GROUP BY b.d_id, b.bom, b.sqty
                ORDER BY b.d_id, max_tl_date DESC
            ");
            $st_lb->execute($part_nos);
            $latest_bom_map = []; // part_no => {bom, sqty}
            foreach ($st_lb->fetchAll(PDO::FETCH_ASSOC) as $b) {
                if (!isset($latest_bom_map[$b['part_no']])) {
                    $latest_bom_map[$b['part_no']] = ['bom' => $b['bom'], 'sqty' => $b['sqty']];
                }
            }

            // ⑥ 批量成本估算（單次 SQL）
            // 優先順序：手動修改單價 > 外包均價。廠內製程若無手動單價則標記 has_missing_cost，
            // 由後續 get_margins_batch（非同步）補跑 KPI 公式更新正確值。
            $bom_nos  = array_unique(array_column(array_values($latest_bom_map), 'bom'));
            $cost_map    = []; // bom => float
            $missing_map = []; // bom => bool
            if (!empty($bom_nos)) {
                $ph_b    = implode(',', array_fill(0, count($bom_nos), '?'));
                $st_cost = $pdo->prepare("
                    SELECT bi.bom,
                           SUM(COALESCE(
                               NULLIF(mod_any.any_mod_avg, 0),
                               CASE WHEN ml.internal IS NULL OR ml.internal != 1
                                    THEN ext.avg_price ELSE NULL END
                           )) AS unit_cost,
                           SUM(CASE WHEN ml.internal = 1
                                         AND (mod_any.any_mod_avg IS NULL OR mod_any.any_mod_avg = 0)
                                    THEN 1 ELSE 0 END) AS inhouse_no_price
                    FROM bom_ing bi
                    LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
                    LEFT JOIN (
                        SELECT t.bom, t.bom_sn, AVG(t.modified_unit_price) AS any_mod_avg
                        FROM bom_ing_transfer_log t
                        WHERE t.modified_unit_price > 0
                        GROUP BY t.bom, t.bom_sn
                    ) mod_any ON bi.bom = mod_any.bom AND bi.bom_sn = mod_any.bom_sn
                    LEFT JOIN (
                        SELECT t.bom, t.bom_sn, AVG(t.price) AS avg_price
                        FROM bom_ing_transfer_log t
                        LEFT JOIN maker_list ml2 ON t.maker_from = ml2.maker_id_no
                        WHERE t.price > 0 AND t.paid_qty > 0
                          AND (ml2.internal IS NULL OR ml2.internal != 1)
                        GROUP BY t.bom, t.bom_sn
                    ) ext ON bi.bom = ext.bom AND bi.bom_sn = ext.bom_sn
                    WHERE bi.bom IN ($ph_b)
                    GROUP BY bi.bom
                ");
                $st_cost->execute($bom_nos);
                foreach ($st_cost->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $cost_map[$c['bom']]    = floatval($c['unit_cost']);
                    $missing_map[$c['bom']] = intval($c['inhouse_no_price']) > 0;
                }
            }

            // ⑦ 合併所有批量結果
            foreach ($rows as &$r) {
                $pno  = $r['part_no'];
                $ship = $ship_map[$pno] ?? null;
                $lbom = $latest_bom_map[$pno] ?? null;
                $bom_key    = $lbom ? $lbom['bom'] : null;
                $full_cost  = $bom_key ? ($cost_map[$bom_key] ?? 0) : 0;
                $hasMissing = $bom_key ? ($missing_map[$bom_key] ?? false) : false;

                $avg_ship_price = $ship ? floatval($ship['avg_sell_price']) : 0;
                $avg_ord_price  = $ord_price_map[$pno] ?? 0;
                if ($avg_ship_price > 0) {
                    $r['avg_sell_price']      = round($avg_ship_price, 2);
                    $r['sell_price_from_order'] = false;
                } elseif ($avg_ord_price > 0) {
                    $r['avg_sell_price']      = round($avg_ord_price, 2);
                    $r['sell_price_from_order'] = true;
                } else {
                    $r['avg_sell_price']      = null;
                    $r['sell_price_from_order'] = false;
                }
                $r['last_ship_date']   = $ship ? $ship['last_ship_date']  : null;
                $r['ship_count']       = $ship ? intval($ship['ship_count']) : 0;
                $r['bom_count']        = $bom_cnt_map[$pno] ?? 0;
                $r['latest_bom']       = $lbom ? $lbom['bom']  : null;
                $r['latest_bom_qty']   = $lbom ? intval($lbom['sqty']) : 0;
                $r['latest_unit_cost']  = $full_cost > 0 ? round($full_cost, 2) : null;
                $r['has_missing_cost']  = $hasMissing; // 有製程成本無法取得（KPI 未設定等）

                $sell = floatval($r['avg_sell_price']);
                $cost = floatval($r['latest_unit_cost']);
                $r['margin_pct'] = ($sell > 0 && $cost > 0) ? round(($sell - $cost) / $sell * 100, 1) : null;
            }
            unset($r);

            // 套用利潤篩選
            if ($f_margin !== '') {
                if ($f_margin === 'loss') {
                    $rows = array_values(array_filter($rows, fn($r) => $r['margin_pct'] !== null && $r['margin_pct'] < 0));
                } elseif ($f_margin === 'low') {
                    $rows = array_values(array_filter($rows, fn($r) => $r['margin_pct'] !== null && $r['margin_pct'] >= 0 && $r['margin_pct'] < $low_thr));
                } elseif ($f_margin === 'mid') {
                    $rows = array_values(array_filter($rows, fn($r) => $r['margin_pct'] !== null && $r['margin_pct'] >= $low_thr && $r['margin_pct'] < $ok_thr));
                } elseif ($f_margin === 'ok') {
                    $rows = array_values(array_filter($rows, fn($r) => $r['margin_pct'] !== null && $r['margin_pct'] >= $ok_thr));
                } elseif ($f_margin === 'no_price') {
                    $rows = array_values(array_filter($rows, fn($r) => floatval($r['avg_sell_price']) == 0));
                } elseif ($f_margin === 'no_cost') {
                    // 外包製程有但無轉帳紀錄（成本未齊）：latest_unit_cost=null 且有 BOM
                    $rows = array_values(array_filter($rows, fn($r) => ($r['latest_unit_cost'] === null || floatval($r['latest_unit_cost']) == 0) && intval($r['bom_count']) > 0));
                }
                $total = count($rows);
            }

            echo json_encode(['success' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $pp]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 取得料號的所有 BOM 清單 ─────────────────────────────
    if ($action === 'get_bom_list') {
        $part_no   = trim($_POST['part_no']   ?? '');
        $date_from = trim($_POST['date_from'] ?? '');
        $date_to   = trim($_POST['date_to']   ?? '');
        if (!$part_no) { echo json_encode(['success' => false, 'message' => '缺少料號']); exit; }
        try {
            $has_date = ($date_from || $date_to);
            $df = $date_from ?: '1900-01-01';
            $dt = $date_to ? $date_to . ' 23:59:59' : '2099-12-31 23:59:59';
            $tl_date_cond = $has_date ? "AND transfer_date BETWEEN ? AND ?" : "";
            $bom_where    = $has_date ? "AND (tl.last_date IS NOT NULL OR b.bom IS NULL)" : "";
            $bom_params = [];
            if ($has_date) { $bom_params[] = $df; $bom_params[] = $dt; }
            $bom_params[] = $part_no;
            $st = $pdo->prepare("
                SELECT b.bom, b.sqty AS bom_qty, b.Client_Name,
                    b.processing_state AS bom_closed,
                    tl.first_date, tl.last_date, tl.trans_count,
                    (SELECT GROUP_CONCAT(ot.Order_oo ORDER BY ot.Order_oo SEPARATOR ',')
                     FROM bom_order_process_map bopm
                     JOIN order_track ot ON ot.Order_id = bopm.order_id
                     WHERE bopm.bom = b.bom) AS bound_orders
                FROM bom b
                LEFT JOIN (
                    SELECT bom,
                           MIN(transfer_date) AS first_date,
                           MAX(transfer_date) AS last_date,
                           COUNT(DISTINCT transfer_date) AS trans_count
                    FROM bom_ing_transfer_log
                    WHERE price > 0 $tl_date_cond
                    GROUP BY bom
                ) tl ON b.bom = tl.bom
                WHERE b.d_id = ? $bom_where
                ORDER BY CAST(SUBSTRING(b.bom, 3) AS UNSIGNED) DESC
            ");
            $st->execute($bom_params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            // 若日期篩選後無BOM，回退顯示全部（避免選不到BOM）
            if ($has_date && !count($rows)) {
                $st2 = $pdo->prepare("
                    SELECT b.bom, b.sqty AS bom_qty, b.Client_Name,
                        b.processing_state AS bom_closed,
                        tl.first_date, tl.last_date, tl.trans_count,
                        (SELECT GROUP_CONCAT(ot.Order_oo ORDER BY ot.Order_oo SEPARATOR ',')
                         FROM bom_order_process_map bopm
                         JOIN order_track ot ON ot.Order_id = bopm.order_id
                         WHERE bopm.bom = b.bom) AS bound_orders
                    FROM bom b
                    LEFT JOIN (
                        SELECT bom, MIN(transfer_date) AS first_date,
                               MAX(transfer_date) AS last_date,
                               COUNT(DISTINCT transfer_date) AS trans_count
                        FROM bom_ing_transfer_log WHERE price > 0 GROUP BY bom
                    ) tl ON b.bom = tl.bom
                    WHERE b.d_id = ?
                    ORDER BY CAST(SUBSTRING(b.bom, 3) AS UNSIGNED) DESC
                ");
                $st2->execute([$part_no]);
                $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 取得單一 BOM 的製程成本明細 ──────────────────────────
    if ($action === 'get_bom_cost') {
        $bom_no  = trim($_POST['bom'] ?? '');
        $part_no = trim($_POST['part_no'] ?? '');
        if (!$bom_no) { echo json_encode(['success' => false, 'message' => '缺少BOM']); exit; }
        try {
            // 取料號基本資訊（型別、齒輪參數）
            $ds_row = null; $part_type = 'N'; $gear_factor = 0; $d_setting_id_int = 0;
            if ($part_no) {
                $st_ds = $pdo->prepare("SELECT d_id, Type FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
                $st_ds->execute([$part_no]);
                $ds_row = $st_ds->fetch(PDO::FETCH_ASSOC);
                if ($ds_row) {
                    $d_setting_id_int = intval($ds_row['d_id']);
                    $part_type = $ds_row['Type'] ?? 'N';
                    if ($part_type === 'G') {
                        $st_g = $pdo->prepare("SELECT Module, Teeth, Face_Width FROM d_setting_gear WHERE d_setting_id = ? LIMIT 1");
                        $st_g->execute([$d_setting_id_int]);
                        $gr = $st_g->fetch(PDO::FETCH_ASSOC);
                        if ($gr && floatval($gr['Module']) > 0) {
                            $gear_factor = floatval($gr['Module']) * floatval($gr['Teeth']) * floatval($gr['Face_Width']);
                        }
                    }
                }
            }

            // 取 BOM 基本資訊
            $st_b = $pdo->prepare("SELECT bom, sqty AS bom_qty, Client_Name FROM bom WHERE bom = ? LIMIT 1");
            $st_b->execute([$bom_no]);
            $bom_info = $st_b->fetch(PDO::FETCH_ASSOC);
            // 取結案狀態（欄位較新，獨立 try 避免欄位不存在時中斷主流程）
            try {
                $st_close = $pdo->prepare("SELECT processing_state AS bom_ps, bom_ing_id AS bom_manual_close FROM bom WHERE bom = ? LIMIT 1");
                $st_close->execute([$bom_no]);
                $close_row = $st_close->fetch(PDO::FETCH_ASSOC);
                if ($close_row && $bom_info) {
                    $bom_info['bom_ps']           = $close_row['bom_ps'];
                    $bom_info['bom_manual_close']  = $close_row['bom_manual_close'];
                }
            } catch (\Throwable $e) { /* 欄位不存在時靜默忽略 */ }

            // 取各製程及其外包均價（含 fid / bom_ing_id / 加工順序 / 狀態）
            $st = $pdo->prepare("
                SELECT
                    bi.bom_ing_fid, bi.bom_ing_id, bi.bom_sn,
                    bi.process_no, bi.processing_sequence, bi.processing_state,
                    pn.ProcessName,
                    pt.process_type,
                    ml.maker_id, ml.internal AS maker_internal,
                    COALESCE(ext.avg_price, 0)   AS ext_avg_price,
                    COALESCE(ext.mod_avg, 0)     AS ext_mod_avg,
                    ext.trans_count,
                    ext.min_price, ext.max_price, ext.last_date,
                    COALESCE(mod_any.any_mod_avg, 0) AS manual_avg
                FROM bom_ing bi
                LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                LEFT JOIN process_type pt ON pn.process_type_id = pt.process_type_id
                LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
                LEFT JOIN (
                    SELECT t.bom, t.bom_sn,
                           AVG(t.price)              AS avg_price,
                           AVG(t.modified_unit_price) AS mod_avg,
                           MIN(LEAST(COALESCE(t.modified_unit_price,t.price), COALESCE(t.price,t.modified_unit_price))) AS min_price,
                           MAX(GREATEST(COALESCE(t.modified_unit_price,t.price), COALESCE(t.price,t.modified_unit_price))) AS max_price,
                           COUNT(*) AS trans_count,
                           MAX(t.transfer_date) AS last_date
                    FROM bom_ing_transfer_log t
                    LEFT JOIN maker_list ml2 ON t.maker_from = ml2.maker_id_no
                    WHERE (t.price > 0 OR t.modified_unit_price > 0)
                      AND (ml2.internal IS NULL OR ml2.internal != 1)
                    GROUP BY t.bom, t.bom_sn
                ) ext ON bi.bom = ext.bom AND bi.bom_sn = ext.bom_sn
                LEFT JOIN (
                    SELECT t.bom, t.bom_sn,
                           AVG(t.modified_unit_price) AS any_mod_avg
                    FROM bom_ing_transfer_log t
                    WHERE t.modified_unit_price > 0
                    GROUP BY t.bom, t.bom_sn
                ) mod_any ON bi.bom = mod_any.bom AND bi.bom_sn = mod_any.bom_sn
                WHERE bi.bom = ?
                ORDER BY bi.bom_sn ASC
            ");
            $st->execute([$bom_no]);
            $processes = $st->fetchAll(PDO::FETCH_ASSOC);

            // 偵測重複 bom_sn 群組，優先保留有轉帳紀錄且狀態正常者
            $valid_states = ['N', 'P', 'Q', 'ing', 'E'];

            // 1. 依 bom_sn 分組
            $sn_groups = [];
            foreach ($processes as $idx => $p) {
                $sn_groups[$p['bom_sn']][] = $idx;
            }

            // 2. 每組選出最佳保留索引：優先 has_log_data + valid_state，次選 valid_state，再選第一筆
            $keep_set = [];
            foreach ($sn_groups as $sn => $indices) {
                $best = null;
                foreach ($indices as $idx) {
                    $r = $processes[$idx];
                    if (intval($r['trans_count']) > 0 && in_array($r['processing_state'], $valid_states, true)) {
                        $best = $idx; break;
                    }
                }
                if ($best === null) {
                    foreach ($indices as $idx) {
                        if (in_array($processes[$idx]['processing_state'], $valid_states, true)) {
                            $best = $idx; break;
                        }
                    }
                }
                $keep_set[$best ?? $indices[0]] = true;
            }

            // 3. 標記每筆
            foreach ($processes as $idx => &$p) {
                $is_dup_group        = count($sn_groups[$p['bom_sn']]) > 1;
                $p['is_duplicate']   = $is_dup_group;
                $p['suggest_delete'] = $is_dup_group && !isset($keep_set[$idx]);
                $p['is_bad_state']   = (!in_array($p['processing_state'], $valid_states, true));
                $p['has_log_data']   = (intval($p['trans_count']) > 0);
            }
            unset($p);

            $total_ext  = 0;
            $total_kpi  = 0;
            $total_cost = 0;

            foreach ($processes as &$p) {
                $is_inhouse   = intval($p['maker_internal']) === 1;
                $ext_price    = floatval($p['ext_avg_price']);
                $manual_price = floatval($p['manual_avg'] ?? 0);
                $p['cost_per_pc']   = 0;
                $p['cost_source']   = '';
                $p['inhouse_cost']  = null;

                // 外包歷史均價：優先 modified_unit_price（非廠內maker），再用 price 均值
                if ($ext_price == 0 && floatval($p['ext_mod_avg']) > 0) {
                    $ext_price = floatval($p['ext_mod_avg']);
                }

                // 優先順序：① 手動填入單價 > ② 外包歷史均價 > ③ 廠內KPI計算
                if ($manual_price > 0) {
                    // 使用者手動填入的加工單價，優先於一切
                    $p['cost_per_pc'] = $manual_price;
                    $p['cost_source'] = 'manual';
                    $total_ext += $manual_price;
                    $total_cost += $manual_price;
                } elseif ($ext_price > 0) {
                    // 有外包歷史單價（外包製程 或 廠內但有外部轉帳紀錄）
                    $p['cost_per_pc'] = $ext_price;
                    $p['cost_source'] = $is_inhouse ? 'manual' : 'external';
                    $total_ext += $ext_price;
                    $total_cost += $ext_price;
                } elseif ($is_inhouse) {
                    // 廠內製程且無手動/外部單價：用 KPI 計算
                    $kpi_result = calcInhouseCostPerPc($pdo, $p['process_no'], $d_setting_id_int, $part_type, $gear_factor);
                    if ($kpi_result !== null) {
                        $inhouseCost = $kpi_result['cost'];
                        if ($inhouseCost > 0 && $inhouseCost < 1) $inhouseCost = 1.0;
                        $p['cost_per_pc']  = $inhouseCost;
                        $p['inhouse_cost'] = $inhouseCost;
                        $p['cost_source']  = 'kpi';
                        $p['calc_mode']    = $kpi_result['mode'];
                        $p['calc_debug']   = $kpi_result['debug'] ?? null;
                        $total_kpi  += $inhouseCost;
                        $total_cost += $inhouseCost;
                    } else {
                        $p['cost_source'] = 'inhouse_no_data';
                        $p['calc_mode']   = null;
                        $p['calc_debug']  = null;
                    }
                } else {
                    $p['cost_source'] = 'no_data';
                }
            }
            unset($p);

            // 取齒輪規格字串（使用 display_template，與 master_data_management.php 邏輯一致）
            $gear_info = null;
            if ($part_type === 'G' && $d_setting_id_int > 0) {
                $gs_sql  = build_gear_spec_sql('?');
                $stg     = $pdo->prepare("SELECT $gs_sql AS gear_spec_str");
                $stg->execute([$d_setting_id_int]);
                $spec_str = $stg->fetchColumn();
                if ($spec_str) $gear_info = ['gear_spec_str' => $spec_str];
            }

            $has_missing_cost = !empty(array_filter($processes, fn($p) => in_array($p['cost_source'] ?? '', ['no_data', 'inhouse_no_data'])));

            echo json_encode([
                'success'          => true,
                'has_missing_cost' => $has_missing_cost,
                'bom_info'         => $bom_info,
                'processes'        => $processes,
                'total_ext'  => round($total_ext, 2),
                'total_kpi'  => round($total_kpi, 2),
                'total_cost' => round($total_cost, 2),
                'gear_info'  => $gear_info,
            ]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 取得料號出貨售價歷史（直接用 Product_id，不依賴綁定）─
    if ($action === 'get_part_shipments') {
        $part_no  = trim($_POST['part_no'] ?? '');
        $date_from = $_POST['date_from'] ?? '';
        $date_to   = $_POST['date_to'] ?? '';
        if (!$part_no) { echo json_encode(['success' => false, 'message' => '缺少料號']); exit; }
        try {
            // ── 出貨紀錄 (is_list) ─────────────────────────────
            $ship_where = "il.Product_id = ?";
            $ship_params = [$part_no];
            if ($date_from) { $ship_where .= " AND il.Order_date >= ?"; $ship_params[] = $date_from; }
            if ($date_to)   { $ship_where .= " AND il.Order_date <= ?"; $ship_params[] = $date_to . ' 23:59:59'; }

            $st_ship = $pdo->prepare("
                SELECT 'shipment' AS row_type,
                       il.IS_id AS row_id, il.IS_number AS doc_no,
                       il.Order_date AS row_date, il.Qty, il.Unit_price AS price,
                       il.Client_name, il.Specification,
                       COALESCE(il.Content,'') AS Content,
                       il.Note,
                       il.sale_type,
                       COALESCE(ot_direct.Order_oo, ot_map.Order_oo) AS Order_oo,
                       NULL AS Open_Qty, NULL AS Order_status, 0 AS has_ship,
                       COALESCE(ist.sale_type_name,'') AS sale_type_name,
                       COALESCE(ist.is_count,1) AS is_count
                FROM is_list il
                LEFT JOIN order_track ot_direct ON il.Order_id = ot_direct.Order_id
                LEFT JOIN shipment_order_map som ON som.IS_id = il.IS_id
                LEFT JOIN order_track ot_map ON ot_map.Order_id = som.Order_id
                LEFT JOIN is_sale_type ist ON il.sale_type = ist.sale_type_id
                WHERE $ship_where
                ORDER BY il.Order_date DESC
                LIMIT 300
            ");
            $st_ship->execute($ship_params);
            $shipments = $st_ship->fetchAll(PDO::FETCH_ASSOC);

            // ── 訂單紀錄 (order_track) ─────────────────────────
            $ord_where = "ot.d_id = ?";
            $ord_params = [$part_no];
            if ($date_from) { $ord_where .= " AND ot.Order_date >= ?"; $ord_params[] = $date_from; }
            if ($date_to)   { $ord_where .= " AND ot.Order_date <= ?"; $ord_params[] = $date_to . ' 23:59:59'; }

            $st_ord = $pdo->prepare("
                SELECT 'order' AS row_type,
                       ot.Order_id AS row_id, ot.Order_oo AS doc_no,
                       ot.Order_date AS row_date, ot.Qty, ot.unit_price AS price,
                       ot.Client_name, ot.Specification, ot.Order_ps AS Note,
                       ot.Order_oo, ot.Open_Qty, ot.Order_status,
                       (SELECT COUNT(*) FROM is_list WHERE Order_id = ot.Order_id) AS has_ship,
                       (SELECT GROUP_CONCAT(il2.IS_number ORDER BY il2.IS_id SEPARATOR ', ')
                        FROM shipment_order_map som2
                        JOIN is_list il2 ON il2.IS_id = som2.IS_id
                        WHERE som2.Order_id = ot.Order_id) AS bound_ship_nos
                FROM order_track ot
                WHERE $ord_where
                ORDER BY ot.Order_date DESC
                LIMIT 300
            ");
            $st_ord->execute($ord_params);
            $orders = $st_ord->fetchAll(PDO::FETCH_ASSOC);

            // ── 合併並依日期降序排列 ────────────────────────────
            $all_rows = array_merge($shipments, $orders);
            usort($all_rows, function($a, $b) {
                return strcmp($b['row_date'] ?? '', $a['row_date'] ?? '');
            });

            // ── 摘要統計（排除 is_count=0 不納入計算的性質）─────
            $counted_ships = array_filter($shipments, fn($s) => intval($s['is_count']) != 0);
            $prices = array_filter(array_column(array_values($counted_ships), 'price'), fn($v) => floatval($v) > 0);
            $summary = [
                'count'       => count($shipments),
                'order_count' => count($orders),
                'avg_price'   => count($prices) ? round(array_sum($prices) / count($prices), 2) : 0,
                'min_price'   => count($prices) ? round(min($prices), 2) : 0,
                'max_price'   => count($prices) ? round(max($prices), 2) : 0,
                'total_qty'   => array_sum(array_column($shipments, 'Qty')),
            ];

            echo json_encode(['success' => true, 'rows' => $all_rows, 'summary' => $summary]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 月度趨勢：成本 vs 售價 ───────────────────────────────
    if ($action === 'get_part_trend') {
        $part_no = trim($_POST['part_no'] ?? '');
        $months  = max(6, min(36, intval($_POST['months'] ?? 12)));
        if (!$part_no) { echo json_encode(['success' => false, 'message' => '缺少料號']); exit; }
        try {
            // 月度出貨售價（排除 is_count=0 不納入計算的性質）
            $st_price = $pdo->prepare("
                SELECT DATE_FORMAT(il.Order_date,'%Y-%m') AS ym,
                       ROUND(AVG(il.Unit_price), 2) AS avg_price,
                       SUM(il.Qty) AS qty
                FROM is_list il
                LEFT JOIN is_sale_type ist ON il.sale_type = ist.sale_type_id
                WHERE il.Product_id = ? AND il.Unit_price > 0
                  AND il.Order_date >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                  AND (ist.is_count IS NULL OR ist.is_count != 0)
                GROUP BY ym ORDER BY ym ASC
            ");
            $st_price->execute([$part_no, $months]);
            $price_rows = $st_price->fetchAll(PDO::FETCH_ASSOC);

            // 月度外包加工成本（透過 bom.d_id → bom_ing → transfer_log）
            $st_cost = $pdo->prepare("
                SELECT DATE_FORMAT(t.transfer_date,'%Y-%m') AS ym,
                       ROUND(AVG(t.price), 2) AS avg_unit_cost,
                       COUNT(*) AS trans_count
                FROM bom_ing_transfer_log t
                JOIN bom_ing bi ON t.bom = bi.bom AND t.bom_sn = bi.bom_sn
                JOIN bom b ON bi.bom = b.bom
                LEFT JOIN maker_list ml ON t.maker_from = ml.maker_id_no
                WHERE b.d_id = ? AND t.price > 0 AND t.paid_qty > 0
                  AND (ml.internal IS NULL OR ml.internal != 1)
                  AND t.transfer_date >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                GROUP BY ym ORDER BY ym ASC
            ");
            $st_cost->execute([$part_no, $months]);
            $cost_rows = $st_cost->fetchAll(PDO::FETCH_ASSOC);

            // 合併成月份陣列
            $all_ym = array_unique(array_merge(
                array_column($price_rows, 'ym'),
                array_column($cost_rows, 'ym')
            ));
            sort($all_ym);

            $price_map = array_column($price_rows, null, 'ym');
            $cost_map  = array_column($cost_rows, null, 'ym');

            $trend = [];
            foreach ($all_ym as $ym) {
                $price = floatval($price_map[$ym]['avg_price'] ?? 0);
                $cost  = floatval($cost_map[$ym]['avg_unit_cost'] ?? 0);
                $trend[] = [
                    'ym'     => $ym,
                    'price'  => $price,
                    'cost'   => $cost,
                    'margin' => ($price > 0 && $cost > 0) ? round(($price - $cost) / $price * 100, 1) : null,
                ];
            }

            echo json_encode(['success' => true, 'data' => $trend]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── CSV 匯出 ─────────────────────────────────────────────
    if ($action === 'export_csv') {
        $part_no = trim($_POST['part_no'] ?? '');
        $type    = $_POST['export_type'] ?? 'shipments'; // 'shipments' | 'bom_cost'
        try {
            header('Content-Type: text/csv; charset=utf-8');
            $filename = 'cost_analysis_' . ($part_no ?: 'all') . '_' . date('Ymd_His') . '.csv';
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

            if ($type === 'shipments' && $part_no) {
                echo implode(',', ['出貨單號','日期','數量','售價','客戶','規格','對應訂單']) . "\n";
                $st = $pdo->prepare("
                    SELECT il.IS_number, il.Order_date, il.Qty, il.Unit_price,
                           il.Client_name, il.Specification, ot.Order_oo
                    FROM is_list il
                    LEFT JOIN order_track ot ON il.Order_id = ot.Order_id
                    WHERE il.Product_id = ?
                    ORDER BY il.Order_date DESC
                    LIMIT 1000
                ");
                $st->execute([$part_no]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    echo implode(',', array_map(fn($v) => '"' . str_replace('"','""',$v ?? '') . '"', array_values($r))) . "\n";
                }
            } elseif ($type === 'list') {
                echo implode(',', ['料號','品名','規格','客戶','最新出貨均價','最新BOM','估計單位成本','利潤率%','出貨次數','最近出貨日']) . "\n";
                $st = $pdo->prepare("
                    SELECT ds.D_Setting_Id, ds.Drawing_No, ds.Spec_No,
                           COALESCE(cl.customer,'') AS client_name,
                           ROUND(AVG(NULLIF(il.Unit_price,0)),2) AS avg_price,
                           (SELECT MAX(Order_date) FROM is_list WHERE Product_id = ds.D_Setting_Id AND Unit_price > 0) AS last_ship
                    FROM d_setting ds
                    LEFT JOIN customer_list cl ON ds.Customer_Id = cl.customer_id
                    LEFT JOIN is_list il ON il.Product_id = ds.D_Setting_Id AND il.Unit_price > 0
                    GROUP BY ds.d_id
                    ORDER BY ds.D_Setting_Id
                ");
                $st->execute();
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    echo implode(',', array_map(fn($v) => '"' . str_replace('"','""',$v ?? '') . '"', array_values($r))) . "\n";
                }
            }
            exit;
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 取出貨性質清單 ─────────────────────────────────────
    if ($action === 'get_sale_types') {
        try {
            $st = $pdo->query("SELECT sale_type_id, sale_type_name, is_count, count_for_order, description FROM is_sale_type WHERE is_active=1 ORDER BY sort_order, sale_type_id");
            echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 更新出貨性質的 is_count / count_for_order ────────────
    if ($action === 'save_sale_type_is_count') {
        $updates = json_decode(trim($_POST['updates'] ?? '[]'), true) ?: [];
        try {
            $st = $pdo->prepare("UPDATE is_sale_type SET is_count=?, count_for_order=? WHERE sale_type_id=?");
            foreach ($updates as $u) {
                $st->execute([intval($u['is_count']), intval($u['count_for_order'] ?? 1), intval($u['sale_type_id'])]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 取毛利率閾值設定（system_parameters）────────────────
    if ($action === 'get_margin_settings') {
        try {
            $st = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='COST_ANALYSIS' AND param_key='margin_thresholds' LIMIT 1");
            $st->execute();
            $row = $st->fetchColumn();
            $val = $row ? json_decode($row, true) : ['low' => 10, 'ok' => 20];
            echo json_encode(['success' => true, 'low' => floatval($val['low'] ?? 10), 'ok' => floatval($val['ok'] ?? 20)]);
        } catch (Exception $e) { echo json_encode(['success' => true, 'low' => 10, 'ok' => 20]); }
        exit;
    }

    // ── 儲存毛利率閾值設定 ────────────────────────────────────
    if ($action === 'save_margin_settings') {
        $low = max(0, min(99, floatval($_POST['low'] ?? 10)));
        $ok  = max(0, min(99, floatval($_POST['ok']  ?? 20)));
        try {
            $pdo->prepare("
                INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
                VALUES ('COST_ANALYSIS','margin_thresholds',?,?,?,NOW())
                ON DUPLICATE KEY UPDATE param_value=VALUES(param_value), updated_by=VALUES(updated_by), updated_at=NOW()
            ")->execute([json_encode(['low' => $low, 'ok' => $ok]), '料號成本分析毛利率分界', $_SESSION['userName'] ?? '']);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 刪除 bom_ing 製程 ────────────────────────────────────
    if ($action === 'delete_bom_ing') {
        $fid = intval($_POST['bom_ing_fid'] ?? 0);
        if ($fid <= 0) { echo json_encode(['success' => false, 'message' => '無效的 fid']); exit; }
        try {
            $pdo->prepare("DELETE FROM bom_ing WHERE bom_ing_fid = ?")->execute([$fid]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 新增/更新加工單價記錄到 bom_ing_transfer_log ──────────────
    if ($action === 'add_transfer_log') {
        $bom_no   = trim($_POST['bom']   ?? '');
        $bom_sn   = intval($_POST['bom_sn'] ?? 0);
        $bom_qty  = intval($_POST['bom_qty'] ?? 0);
        $price_in = floatval($_POST['unit_price'] ?? 0);
        $note_in  = trim($_POST['note'] ?? '');
        $part_no  = trim($_POST['part_no'] ?? '');
        $has_log    = intval($_POST['has_log'] ?? 0);
        $maker_from = trim($_POST['maker'] ?? '');
        if (!$bom_no || $bom_sn <= 0 || $price_in <= 0) {
            echo json_encode(['success' => false, 'message' => '缺少必要欄位']); exit;
        }
        try {
            $proc_amt  = round($bom_qty * $price_in, 2);
            $tax_amt   = round($proc_amt * 0.05, 2);
            $user_id   = $_SESSION['id'] ?? '';
            $affected  = 0;
            if ($has_log) {
                // 已有記錄 → UPDATE 最近一筆的修改後單價、金額、備註、異動人員、異動時間
                $upd = $pdo->prepare("
                    UPDATE bom_ing_transfer_log
                    SET modified_unit_price = ?,
                        process_amount      = ?,
                        tax_amount          = ?,
                        note                = ?,
                        modified_at         = NOW(),
                        changed_by          = ?
                    WHERE bom = ? AND bom_sn = ?
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $upd->execute([$price_in, $proc_amt, $tax_amt, $note_in, $user_id, $bom_no, $bom_sn]);
                $affected = $upd->rowCount();
            }
            if (!$affected) {
                // 尚無記錄，或 UPDATE 未命中 → INSERT 新紀錄
                $pdo->prepare("
                    INSERT INTO bom_ing_transfer_log
                        (bom, bom_sn, sqty, transfer_date, transfer_qty, loss_qty,
                         modified_unit_price, process_amount, tax_amount,
                         product_id, note, note2, maker_from, changed_by, created_at)
                    VALUES (?, ?, ?, CURDATE(), ?, 0, ?, ?, ?, ?, ?, '人工建檔', ?, ?, NOW())
                ")->execute([$bom_no, $bom_sn, $bom_qty, $bom_qty, $price_in, $proc_amt, $tax_amt, $part_no, $note_in, $maker_from, $user_id]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 取料號圖面檔案（對應 Z:/BOM/ 目錄）─────────────────────
    if ($action === 'get_product_files') {
        $pid = trim($_POST['part_no'] ?? '');
        if (!$pid) { echo json_encode(['success' => true, 'files' => []]); exit; }
        try {
            $st = $pdo->prepare("SELECT bom, sqty FROM bom WHERE d_id = ? ORDER BY Created_At DESC");
            $st->execute([$pid]);
            $bom_rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $files = [];
            $scan_dir = 'Z:/BOM/';
            $url_dir  = '/nas/';
            if (is_dir($scan_dir)) {
                $allFiles = scandir($scan_dir);
                foreach ($bom_rows as $row) {
                    $bom = $row['bom']; $qty = $row['sqty'];
                    foreach ($allFiles as $f) {
                        if ($f === '.' || $f === '..') continue;
                        if (strpos($f, $bom) === 0) {
                            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                            if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                                $files[] = ['bom' => $bom . ' (Qty:' . ($qty ?? '?') . ')', 'name' => $f, 'path' => $url_dir . $f, 'type' => $ext, 'mtime' => filemtime($scan_dir . $f)];
                            }
                        }
                    }
                }
                usort($files, fn($a,$b) => $b['mtime'] - $a['mtime']);
            }
            echo json_encode(['success' => true, 'files' => $files]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 客戶年度趨勢 ──────────────────────────────────────────
    if ($action === 'get_customer_yearly_trend') {
        $client_name  = trim($_POST['client_name'] ?? '');
        if (!$client_name) { echo json_encode(['success' => false, 'message' => '缺少客戶名稱']); exit; }
        $is_no_client = ($client_name === '（無客戶）');
        try {
            if ($is_no_client) {
                $st = $pdo->prepare("
                    SELECT YEAR(il.Order_date) AS yr, COUNT(*) AS ship_count,
                           COUNT(DISTINCT il.Product_id) AS part_count, SUM(il.Qty) AS total_qty,
                           ROUND(AVG(NULLIF(il.Unit_price,0)),2) AS avg_price,
                           ROUND(MIN(NULLIF(il.Unit_price,0)),2) AS min_price,
                           ROUND(MAX(NULLIF(il.Unit_price,0)),2) AS max_price
                    FROM is_list il
                    WHERE il.Unit_price > 0 AND (il.Client_name IS NULL OR TRIM(il.Client_name)='')
                    GROUP BY YEAR(il.Order_date) ORDER BY yr ASC
                ");
                $st->execute([]);
            } else {
                $st = $pdo->prepare("
                    SELECT YEAR(il.Order_date) AS yr, COUNT(*) AS ship_count,
                           COUNT(DISTINCT il.Product_id) AS part_count, SUM(il.Qty) AS total_qty,
                           ROUND(AVG(NULLIF(il.Unit_price,0)),2) AS avg_price,
                           ROUND(MIN(NULLIF(il.Unit_price,0)),2) AS min_price,
                           ROUND(MAX(NULLIF(il.Unit_price,0)),2) AS max_price
                    FROM is_list il
                    WHERE il.Unit_price > 0 AND il.Client_name = ?
                    GROUP BY YEAR(il.Order_date) ORDER BY yr ASC
                ");
                $st->execute([$client_name]);
            }
            echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 客戶獲利分析 ──────────────────────────────────────────
    if ($action === 'get_customer_analysis') {
        $date_from = trim($_POST['date_from'] ?? '');
        $date_to   = trim($_POST['date_to']   ?? '');
        try {
            $where = "il.Unit_price > 0";
            $params = [];
            if ($date_from) { $where .= " AND il.Order_date >= ?"; $params[] = $date_from; }
            if ($date_to)   { $where .= " AND il.Order_date <= ?"; $params[] = $date_to . ' 23:59:59'; }

            // 取每筆出貨的料號、客戶、售價
            $st = $pdo->prepare("
                SELECT
                    COALESCE(NULLIF(TRIM(il.Client_name),''), '（無客戶）') AS client_name,
                    COALESCE(NULLIF(TRIM(il.Client_id),''), '') AS client_id,
                    il.Product_id AS part_no,
                    il.Unit_price AS sell_price,
                    il.Qty
                FROM is_list il
                WHERE $where
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // 取每個料號的估計成本（最新BOM的外包均價）
            $cost_map = [];
            if (count($rows)) {
                // array_values 重置 key，避免 array_unique 留非連續 key 導致 PDO 綁定錯誤
                $part_nos = array_values(array_unique(array_column($rows, 'part_no')));
                $in_ph    = implode(',', array_fill(0, count($part_nos), '?'));
                $st_cost  = $pdo->prepare("
                    SELECT b.d_id AS part_no, SUM(COALESCE(ext.avg_price, 0)) AS unit_cost
                    FROM bom b
                    JOIN bom_ing bi ON bi.bom = b.bom
                    LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
                    LEFT JOIN (
                        SELECT t.bom, t.bom_sn, AVG(t.price) AS avg_price
                        FROM bom_ing_transfer_log t
                        LEFT JOIN maker_list ml2 ON t.maker_from = ml2.maker_id_no
                        WHERE t.price > 0 AND t.paid_qty > 0
                          AND (ml2.internal IS NULL OR ml2.internal != 1)
                        GROUP BY t.bom, t.bom_sn
                    ) ext ON bi.bom = ext.bom AND bi.bom_sn = ext.bom_sn
                    WHERE b.d_id IN ($in_ph)
                      AND (ml.internal IS NULL OR ml.internal != 1)
                    GROUP BY b.d_id, b.bom
                    ORDER BY b.d_id, b.bom DESC
                ");
                $st_cost->execute($part_nos);
                // 每個料號只保留最新BOM（GROUP BY d_id 取第一筆=最新bom desc）
                foreach ($st_cost->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                    if (!isset($cost_map[$cr['part_no']])) {
                        $cost_map[$cr['part_no']] = floatval($cr['unit_cost']);
                    }
                }
            }

            // 依客戶聚合
            $clients = [];
            foreach ($rows as $r) {
                $c    = $r['client_name'];
                $pn   = $r['part_no'];
                $sell = floatval($r['sell_price']);
                $cost = $cost_map[$pn] ?? 0;
                if (!isset($clients[$c])) {
                    $clients[$c] = ['client_name' => $c, 'client_id' => $r['client_id'],
                        'ship_count' => 0, 'total_qty' => 0,
                        'sell_sum' => 0, 'sell_cnt' => 0, 'margin_sum' => 0, 'margin_cnt' => 0,
                        'parts' => [], 'min_price' => PHP_INT_MAX, 'max_price' => 0];
                } elseif (empty($clients[$c]['client_id']) && !empty($r['client_id'])) {
                    $clients[$c]['client_id'] = $r['client_id'];
                }
                $clients[$c]['ship_count']++;
                $clients[$c]['total_qty'] += intval($r['Qty']);
                if ($sell > 0) {
                    $clients[$c]['sell_sum'] += $sell;
                    $clients[$c]['sell_cnt']++;
                    $clients[$c]['min_price'] = min($clients[$c]['min_price'], $sell);
                    $clients[$c]['max_price'] = max($clients[$c]['max_price'], $sell);
                }
                if ($sell > 0 && $cost > 0) {
                    $margin = ($sell - $cost) / $sell * 100;
                    $clients[$c]['margin_sum'] += $margin;
                    $clients[$c]['margin_cnt']++;
                }
                $clients[$c]['parts'][$pn] = true;
            }

            $result = [];
            foreach ($clients as $c => $d) {
                $result[] = [
                    'client_name' => $d['client_name'],
                    'client_id'   => $d['client_id'] ?? '',
                    'part_count'  => count($d['parts']),
                    'ship_count'  => $d['ship_count'],
                    'total_qty'   => $d['total_qty'],
                    'avg_price'   => $d['sell_cnt'] > 0 ? round($d['sell_sum'] / $d['sell_cnt'], 2) : 0,
                    'min_price'   => $d['min_price'] < PHP_INT_MAX ? round($d['min_price'], 2) : 0,
                    'max_price'   => round($d['max_price'], 2),
                    'avg_margin'  => $d['margin_cnt'] > 0 ? round($d['margin_sum'] / $d['margin_cnt'], 1) : null,
                    'margin_cnt'  => $d['margin_cnt'],
                ];
            }
            // 依平均利潤率降序排列（無資料者排後）
            usort($result, function($a, $b) {
                if ($a['avg_margin'] === null && $b['avg_margin'] === null) return $b['ship_count'] - $a['ship_count'];
                if ($a['avg_margin'] === null) return 1;
                if ($b['avg_margin'] === null) return -1;
                return $b['avg_margin'] <=> $a['avg_margin'];
            });

            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 客戶自動完成清單（用於搜尋框建議）─────────────────────
    if ($action === 'get_client_list') {
        try {
            $st = $pdo->query("
                SELECT customer_id AS client_id,
                       COALESCE(customer, '') AS client_name
                FROM customer_list
                WHERE is_inactive = 0
                  AND customer IS NOT NULL AND TRIM(customer) != ''
                ORDER BY customer ASC
            ");
            echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) { echo json_encode(['success' => false]); }
        exit;
    }

    // ── 齒輪規格字串 SQL 子查詢（與 master_data_management.php 共用邏輯）────
    // ── BOM 出貨自動結案輔助：訂單結案後，找 BOM qty === 訂單 qty 的未結案 BOM 自動結案 ──
    function auto_close_boms_for_order(\PDO $pdo, int $order_id, int $order_qty): array {
        $auto_closed = [];
        try {
            $st = $pdo->prepare("
                SELECT bopm.bom FROM bom_order_process_map bopm
                JOIN bom b ON b.bom = bopm.bom
                WHERE bopm.order_id = ? AND b.sqty = ?
                  AND (b.processing_state IS NULL OR b.processing_state != '1')
            ");
            $st->execute([$order_id, $order_qty]);
            $boms = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($boms)) {
                $upd = $pdo->prepare("UPDATE bom SET processing_state='1', bom_ing_id='1', closed_by=NULL, closed_at=NOW(), close_type='auto_shipment', Modified_At=NOW(), Modified_By=NULL WHERE bom=? AND (processing_state IS NULL OR processing_state != '1')");
                $log = $pdo->prepare("INSERT INTO bom_operation_log (bom, operation_type, operator_id, details_json) VALUES (?, 'auto_shipment_close', NULL, ?)");
                foreach ($boms as $bom_no) {
                    $upd->execute([$bom_no]);
                    if ($upd->rowCount() > 0) {
                        $log->execute([$bom_no, json_encode(['操作' => '出貨自動結案', 'order_id' => $order_id])]);
                        $auto_closed[] = $bom_no;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("BOM auto-close error: " . $e->getMessage());
        }
        return $auto_closed;
    }

    // ── 手動立即結案訂單（出貨不足但確認完成）─────────────────────
    if ($action === 'close_order_now') {
        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) { echo json_encode(['success' => false, 'message' => '缺少訂單ID']); exit; }
        try {
            $stOrd = $pdo->prepare("SELECT Qty, Order_status FROM order_track WHERE Order_id = ?");
            $stOrd->execute([$order_id]);
            $ordRow = $stOrd->fetch(PDO::FETCH_ASSOC);
            if (!$ordRow) { echo json_encode(['success' => false, 'message' => '找不到訂單']); exit; }
            if (intval($ordRow['Order_status']) === 9) { echo json_encode(['success' => true, 'already_closed' => true, 'auto_closed_boms' => []]); exit; }
            $uid = $_SESSION['id'] ?? 0;
            $pdo->prepare("UPDATE order_track SET Order_status = 9, Modified_By = ?, Modified_At = NOW() WHERE Order_id = ?")
                ->execute([$uid, $order_id]);
            $auto_closed_boms = auto_close_boms_for_order($pdo, $order_id, intval($ordRow['Qty']));
            echo json_encode(['success' => true, 'auto_closed_boms' => $auto_closed_boms]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 取得可綁定到訂單的出貨單清單 ──────────────────────────────
    if ($action === 'get_shipments_for_order_bind') {
        $part_no  = trim($_POST['part_no']  ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$part_no || !$order_id) { echo json_encode(['success' => false, 'message' => '缺少參數']); exit; }
        try {
            // 取已綁定到此訂單的出貨單
            $st_bound = $pdo->prepare("SELECT IS_id, shipped_qty FROM shipment_order_map WHERE Order_id = ?");
            $st_bound->execute([$order_id]);
            $bound_map = [];
            foreach ($st_bound->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $bound_map[intval($b['IS_id'])] = intval($b['shipped_qty']);
            }

            // 取此料號所有出貨單（排除 is_count=0 備註類型，不應列入成本計算）
            $st_ship = $pdo->prepare("
                SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
                       il.Qty, il.Unit_price, il.Client_name,
                       COALESCE(il.Specification,'') AS Specification,
                       COALESCE(il.Content,'') AS Content,
                       COALESCE(il.Note,'') AS Note,
                       COALESCE(il.Order_id, 0) AS direct_order_id,
                       COALESCE(ist.sale_type_name,'出貨') AS sale_type_name,
                       COALESCE(ist.is_count,1) AS is_count,
                       COALESCE(ist.count_for_order,1) AS count_for_order
                FROM is_list il
                LEFT JOIN is_sale_type ist ON il.sale_type = ist.sale_type_id
                WHERE il.Product_id = ?
                  AND (ist.count_for_order IS NULL OR ist.count_for_order != 0)
                ORDER BY il.Order_date DESC
                LIMIT 200
            ");
            $st_ship->execute([$part_no]);
            $ships = $st_ship->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ships as &$s) {
                $sid = intval($s['IS_id']);
                $s['is_bound']    = isset($bound_map[$sid]) ? 1 : 0;
                $s['shipped_qty'] = $bound_map[$sid] ?? $s['Qty'];
            }
            unset($s);
            echo json_encode(['success' => true, 'ships' => $ships]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 儲存出貨單-訂單綁定 ───────────────────────────────────────
    if ($action === 'save_shipment_order_bind') {
        $order_id  = intval($_POST['order_id']       ?? 0);
        $ship_json = trim($_POST['ship_pcs_json']    ?? '[]');
        if (!$order_id) { echo json_encode(['success' => false, 'message' => '缺少訂單ID']); exit; }
        $ships = json_decode($ship_json, true) ?: [];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM shipment_order_map WHERE Order_id = ?")->execute([$order_id]);
            if (!empty($ships)) {
                $ins = $pdo->prepare("INSERT INTO shipment_order_map (IS_id, Order_id, shipped_qty) VALUES (?, ?, ?)");
                foreach ($ships as $s) {
                    $is_id = intval($s['IS_id']      ?? 0);
                    $qty   = intval($s['shipped_qty'] ?? 0);
                    if ($is_id > 0 && $qty > 0) $ins->execute([$is_id, $order_id, $qty]);
                }
            }
            // 自動結案：出貨總量 >= 訂單數量 → 結案；已結案但出貨不足 → 取消結案
            $stOrd = $pdo->prepare("SELECT Qty, Order_status FROM order_track WHERE Order_id = ?");
            $stOrd->execute([$order_id]);
            $ordRow = $stOrd->fetch(PDO::FETCH_ASSOC);
            $status_changed = null;
            if ($ordRow) {
                $order_qty  = intval($ordRow['Qty']);
                $cur_status = $ordRow['Order_status'];  // null / 6 / 9
                $stTot = $pdo->prepare("SELECT COALESCE(SUM(shipped_qty), 0) FROM shipment_order_map WHERE Order_id = ?");
                $stTot->execute([$order_id]);
                $total_shipped = intval($stTot->fetchColumn());
                $uid = $_SESSION['id'] ?? 0;
                if ($order_qty > 0 && $total_shipped >= $order_qty && $cur_status === null) {
                    $pdo->prepare("UPDATE order_track SET Order_status = 9, Modified_By = ?, Modified_At = NOW() WHERE Order_id = ?")
                        ->execute([$uid, $order_id]);
                    $status_changed = 'closed';
                } elseif ($total_shipped < $order_qty && intval($cur_status) === 9) {
                    $pdo->prepare("UPDATE order_track SET Order_status = NULL, Modified_By = ?, Modified_At = NOW() WHERE Order_id = ?")
                        ->execute([$uid, $order_id]);
                    $status_changed = 'reopened';
                }
            }
            $pdo->commit();
            $auto_closed_boms = [];
            if ($status_changed === 'closed') {
                $auto_closed_boms = auto_close_boms_for_order($pdo, $order_id, $order_qty ?? 0);
            }
            echo json_encode(['success' => true, 'status_changed' => $status_changed, 'auto_closed_boms' => $auto_closed_boms]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 取得 BOM 可綁定的訂單清單（含已結案，供歷史成本分析）──────
    if ($action === 'get_orders_for_bom_bind') {
        $bom_no  = trim($_POST['bom']     ?? '');
        $part_no = trim($_POST['part_no'] ?? '');
        if (!$bom_no || !$part_no) { echo json_encode(['success' => false, 'message' => '缺少參數']); exit; }
        try {
            $st_ds = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
            $st_ds->execute([$part_no]);
            $ds_row = $st_ds->fetch(PDO::FETCH_ASSOC);
            $d_setting_id = $ds_row ? intval($ds_row['d_id']) : 0;
            if (!$d_setting_id) { echo json_encode(['success' => false, 'message' => '找不到料號設定']); exit; }

            $st_bom = $pdo->prepare("SELECT sqty FROM bom WHERE bom = ? LIMIT 1");
            $st_bom->execute([$bom_no]);
            $bom_row = $st_bom->fetch(PDO::FETCH_ASSOC);
            $bom_qty = $bom_row ? intval($bom_row['sqty']) : 0;

            $sql = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name,
                        (CASE WHEN ot.split_seq = 1
                             THEN ot.Qty - COALESCE((SELECT SUM(c.Qty) FROM order_track c WHERE c.parent_order_id = ot.Order_id AND c.split_seq > 1),0)
                             ELSE ot.Qty END) AS Qty,
                        ot.Open_Qty,
                        DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                        COALESCE(ot.Specification,'') AS Specification,
                        COALESCE(ot.Order_ps,'') AS Order_ps,
                        COALESCE(ot.Processing_items,'') AS Processing_items,
                        ot.Order_status,
                        COALESCE(SUM(CASE WHEN bopm.bom != ? THEN bopm.allocated_qty ELSE 0 END),0) AS allocated_by_others,
                        COALESCE(MAX(CASE WHEN bopm.bom = ? THEN bopm.allocated_qty ELSE NULL END),0) AS my_allocated,
                        CASE WHEN MAX(CASE WHEN bopm.bom = ? THEN 1 ELSE 0 END) = 1 THEN 1 ELSE 0 END AS is_bound
                    FROM order_track ot
                    LEFT JOIN bom_order_process_map bopm ON bopm.order_id = ot.Order_id
                    WHERE ot.d_id_ID = ?
                    GROUP BY ot.Order_id, ot.Order_oo, ot.Client_name, ot.split_seq, ot.Qty, ot.Open_Qty,
                             ot.Delivery_date, ot.Specification, ot.Order_ps, ot.Processing_items, ot.Order_status
                    ORDER BY ot.Order_oo DESC
                    LIMIT 100";
            $st_ord = $pdo->prepare($sql);
            $st_ord->execute([$bom_no, $bom_no, $bom_no, $d_setting_id]);
            $orders = $st_ord->fetchAll(PDO::FETCH_ASSOC);
            foreach ($orders as &$o) {
                $o['available_qty_for_bind'] = max(0, (int)$o['Qty'] - (int)$o['allocated_by_others']);
            }
            unset($o);
            echo json_encode(['success' => true, 'orders' => $orders, 'bom_qty' => $bom_qty]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 儲存 BOM-訂單綁定 ─────────────────────────────────────────
    if ($action === 'save_bom_order_bind') {
        $bom_no     = trim($_POST['bom']            ?? '');
        $order_json = trim($_POST['order_pcs_json'] ?? '[]');
        if (!$bom_no) { echo json_encode(['success' => false, 'message' => '缺少BOM']); exit; }
        $orders = json_decode($order_json, true) ?: [];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM bom_order_process_map WHERE bom = ?")->execute([$bom_no]);
            if (!empty($orders)) {
                $ins = $pdo->prepare("INSERT INTO bom_order_process_map (bom, order_id, allocated_qty) VALUES (?, ?, ?)");
                foreach ($orders as $o) {
                    $oid = intval($o['order_id'] ?? 0);
                    $pcs = intval($o['pcs']      ?? 0);
                    if ($oid > 0 && $pcs > 0) $ins->execute([$bom_no, $oid, $pcs]);
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── 毛利分析：BOM → 訂單 → 出貨鏈 ───────────────────────────
    if ($action === 'get_margin_analysis') {
        $part_no = trim($_POST['part_no'] ?? '');
        if (!$part_no) { echo json_encode(['success' => false, 'message' => '缺少料號']); exit; }
        try {
            // 查 d_setting 以取得 KPI 計算所需參數
            $d_setting_id_int = 0;
            $part_type   = 'N';
            $gear_factor = 1.0;
            try {
                $st_ds = $pdo->prepare("SELECT d_id, COALESCE(Type,'N') AS part_type FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
                $st_ds->execute([$part_no]);
                $ds_row = $st_ds->fetch(PDO::FETCH_ASSOC);
                if ($ds_row) {
                    $d_setting_id_int = intval($ds_row['d_id']);
                    $part_type = $ds_row['part_type'];
                }
                if ($part_type === 'G' && $d_setting_id_int > 0) {
                    $st_gf = $pdo->prepare("SELECT COALESCE(Module,1)*COALESCE(Teeth,1)*COALESCE(Face_Width,1) AS gf FROM d_setting_gear WHERE d_setting_id = ? LIMIT 1");
                    $st_gf->execute([$d_setting_id_int]);
                    $gf_row = $st_gf->fetch(PDO::FETCH_ASSOC);
                    $gear_factor = $gf_row ? floatval($gf_row['gf']) : 1.0;
                }
            } catch (\Throwable $e) {}

            $st_boms = $pdo->prepare("
                SELECT b.bom, b.sqty AS bom_qty, b.Client_Name,
                       DATE_FORMAT(b.Created_At,'%Y-%m-%d') AS created_at,
                       b.processing_state, b.bom_ing_id,
                       GROUP_CONCAT(bopm.order_id   ORDER BY bopm.order_id SEPARATOR ',') AS order_ids,
                       GROUP_CONCAT(bopm.allocated_qty ORDER BY bopm.order_id SEPARATOR ',') AS alloc_qtys
                FROM bom b
                LEFT JOIN bom_order_process_map bopm ON bopm.bom = b.bom
                WHERE b.d_id = ?
                GROUP BY b.bom, b.sqty, b.Client_Name, b.Created_At, b.processing_state, b.bom_ing_id
                ORDER BY b.Created_At DESC
            ");
            $st_boms->execute([$part_no]);
            $boms_raw = $st_boms->fetchAll(PDO::FETCH_ASSOC);

            $st_order = $pdo->prepare("
                SELECT ot.Order_id, ot.Order_oo, ot.Client_name,
                       ot.Qty, DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') AS Delivery_date,
                       ot.unit_price, ot.currency, COALESCE(ot.exchange_rate,1) AS exchange_rate,
                       ot.Order_status, COALESCE(ot.Specification,'') AS Specification,
                       COALESCE(ot.Order_ps,'') AS Order_ps
                FROM order_track ot WHERE ot.Order_id = ? LIMIT 1
            ");
            $st_ship = $pdo->prepare("
                SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
                       il.Qty, il.Unit_price, il.Client_name,
                       COALESCE(ist.sale_type_name,'') AS sale_type_name,
                       COALESCE(ist.is_count,1) AS is_count
                FROM is_list il
                LEFT JOIN is_sale_type ist ON il.sale_type = ist.sale_type_id
                WHERE il.Order_id = ? AND il.Product_id = ?
                  AND (ist.is_count IS NULL OR ist.is_count != 0)
                UNION
                SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS ship_date,
                       il.Qty, il.Unit_price, il.Client_name,
                       COALESCE(ist.sale_type_name,'') AS sale_type_name,
                       COALESCE(ist.is_count,1) AS is_count
                FROM is_list il
                LEFT JOIN is_sale_type ist ON il.sale_type = ist.sale_type_id
                JOIN shipment_order_map som ON som.IS_id = il.IS_id AND som.Order_id = ?
                WHERE il.Product_id = ?
                  AND (ist.is_count IS NULL OR ist.is_count != 0)
                ORDER BY ship_date DESC
            ");

            // 前端已快取的 BOM 單件成本（與 BOM製程成本分頁使用相同數字）
            $bom_costs_json = trim($_POST['bom_costs'] ?? '{}');
            $bom_costs_map  = json_decode($bom_costs_json, true) ?: [];

            $result = [];
            foreach ($boms_raw as $bom) {
                $bom_no = $bom['bom'];
                // 優先使用前端已計算的成本（與BOM製程成本分頁一致），否則重新計算
                if (isset($bom_costs_map[$bom_no]) && floatval($bom_costs_map[$bom_no]) > 0) {
                    $total_cost = floatval($bom_costs_map[$bom_no]);
                } else {
                    $total_cost = calcFullBomCostPerPc($pdo, $bom_no, $d_setting_id_int, $part_type, $gear_factor);
                }

                $order_ids  = $bom['order_ids']  ? explode(',', $bom['order_ids'])  : [];
                $alloc_qtys = $bom['alloc_qtys'] ? explode(',', $bom['alloc_qtys']) : [];
                $bom_qty_n  = intval($bom['bom_qty']) ?: 1;

                $orders_out = [];
                foreach ($order_ids as $k => $oid) {
                    $oid   = intval($oid);
                    $alloc = intval($alloc_qtys[$k] ?? 0);
                    if (!$oid) continue;
                    $st_order->execute([$oid]);
                    $ord = $st_order->fetch(PDO::FETCH_ASSOC);
                    if (!$ord) continue;

                    $st_ship->execute([$oid, $part_no, $oid, $part_no]);
                    $ships = $st_ship->fetchAll(PDO::FETCH_ASSOC);
                    $ex_rate = max(1, floatval($ord['exchange_rate']));
                    $total_rev = 0; $total_ship_qty = 0;
                    foreach ($ships as &$s) {
                        $rev = floatval($s['Qty']) * floatval($s['Unit_price']) * $ex_rate;
                        $s['revenue'] = round($rev, 2);
                        $total_rev += $rev;
                        $total_ship_qty += intval($s['Qty']);
                    }
                    unset($s);

                    // 無出貨記錄但訂單有單價：以訂單單價×分配數量估算收入
                    $order_unit_price  = floatval($ord['unit_price'] ?? 0);
                    $revenue_is_estimate = false;
                    if ($total_ship_qty === 0 && $order_unit_price > 0) {
                        $est_qty = $alloc > 0 ? $alloc : intval($ord['Qty']);
                        $total_rev = $order_unit_price * $est_qty * $ex_rate;
                        $revenue_is_estimate = true;
                    }

                    // 單件成本 × 分配數量 = 此訂單分攤成本
                    $calc_qty       = $alloc > 0 ? $alloc : $bom_qty_n;
                    $allocated_cost = round($total_cost * $calc_qty, 2);
                    $gross_profit   = $total_rev - $allocated_cost;
                    $margin_pct     = $total_rev > 0 ? round($gross_profit / $total_rev * 100, 1) : null;

                    $orders_out[] = [
                        'order_id'           => $oid,
                        'order_no'           => $ord['Order_oo'],
                        'client_name'        => $ord['Client_name'],
                        'order_qty'          => $ord['Qty'],
                        'alloc_qty'          => $alloc,
                        'delivery_date'      => $ord['Delivery_date'],
                        'order_status'       => $ord['Order_status'],
                        'spec'               => $ord['Specification'],
                        'order_ps'           => $ord['Order_ps'],
                        'order_unit_price'   => $order_unit_price,
                        'order_currency'     => $ord['currency'] ?? 'TWD',
                        'unit_cost_per_pc'   => $total_cost,
                        'revenue_is_estimate'=> $revenue_is_estimate,
                        'shipments'          => $ships,
                        'total_ship_qty'     => $total_ship_qty,
                        'total_revenue'      => round($total_rev, 2),
                        'allocated_cost'     => $allocated_cost,
                        'gross_profit'       => round($gross_profit, 2),
                        'margin_pct'         => $margin_pct,
                    ];
                }

                $result[] = [
                    'bom'        => $bom_no,
                    'bom_qty'    => intval($bom['bom_qty']),
                    'client_name'=> $bom['Client_Name'],
                    'created_at' => $bom['created_at'],
                    'is_closed'  => ($bom['processing_state'] === '1' || !empty($bom['bom_ing_id'])),
                    'total_cost' => $total_cost,
                    'orders'     => $orders_out,
                ];
            }
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 批次毛利率計算（與詳細頁邏輯一致，20 料號 1 次請求）────────
    if ($action === 'get_margins_batch') {
        $part_nos = array_values(array_filter(array_map('strval',
            json_decode(trim($_POST['part_nos'] ?? '[]'), true) ?: [])));
        if (empty($part_nos)) { echo json_encode(['success' => true, 'data' => []]); exit; }
        try {
            $ph = implode(',', array_fill(0, count($part_nos), '?'));

            // ① 取 d_setting 資訊
            $stDs = $pdo->prepare("SELECT D_Setting_Id AS part_no, d_id, COALESCE(Type,'N') AS part_type FROM d_setting WHERE D_Setting_Id IN ($ph)");
            $stDs->execute($part_nos);
            $ds_map = []; // part_no => row
            foreach ($stDs->fetchAll(PDO::FETCH_ASSOC) as $r) $ds_map[$r['part_no']] = $r;

            // ② 齒輪因子（批次）— key 為 d_setting.d_id 整數
            $gear_d_ids = array_values(array_filter(array_map(
                fn($r) => $r['part_type'] === 'G' ? intval($r['d_id']) : null, $ds_map)));
            $gf_map = []; // int d_id => gear_factor
            if (!empty($gear_d_ids)) {
                $phg  = implode(',', array_fill(0, count($gear_d_ids), '?'));
                $stGf = $pdo->prepare("SELECT d_setting_id, COALESCE(Module,1)*COALESCE(Teeth,1)*COALESCE(Face_Width,1) AS gf FROM d_setting_gear WHERE d_setting_id IN ($phg)");
                $stGf->execute($gear_d_ids);
                foreach ($stGf->fetchAll(PDO::FETCH_ASSOC) as $gr) $gf_map[intval($gr['d_setting_id'])] = floatval($gr['gf']);
            }

            // ③ 取所有 BOM + 綁定訂單（批次）
            // bom.d_id 存的是料號字串（D_Setting_Id），不是整數，用 $part_nos
            $stBom = $pdo->prepare("
                SELECT b.d_id AS part_no, b.bom, b.sqty AS bom_qty,
                       GROUP_CONCAT(bopm.order_id    ORDER BY bopm.order_id SEPARATOR ',') AS order_ids,
                       GROUP_CONCAT(bopm.allocated_qty ORDER BY bopm.order_id SEPARATOR ',') AS alloc_qtys
                FROM bom b
                LEFT JOIN bom_order_process_map bopm ON bopm.bom = b.bom
                WHERE b.d_id IN ($ph)
                GROUP BY b.d_id, b.bom, b.sqty
            ");
            $stBom->execute($part_nos);
            $bom_rows = $stBom->fetchAll(PDO::FETCH_ASSOC);

            // ④ 收集所有 order_id 和料號，批次取出訂單資訊
            $all_order_ids = [];
            foreach ($bom_rows as $b) {
                if ($b['order_ids']) foreach (explode(',', $b['order_ids']) as $oid) if (intval($oid)) $all_order_ids[] = intval($oid);
            }
            $all_order_ids = array_values(array_unique($all_order_ids));
            $order_map = []; // order_id => row
            if (!empty($all_order_ids)) {
                $pho = implode(',', array_fill(0, count($all_order_ids), '?'));
                $stOrd = $pdo->prepare("SELECT Order_id, unit_price, COALESCE(exchange_rate,1) AS ex_rate, Qty FROM order_track WHERE Order_id IN ($pho)");
                $stOrd->execute($all_order_ids);
                foreach ($stOrd->fetchAll(PDO::FETCH_ASSOC) as $o) $order_map[intval($o['Order_id'])] = $o;
            }

            // ⑤ 批次取出所有綁定出貨收入（兩種來源 UNION）
            $rev_map = []; // "$order_id|$product_id" => {rev, ship_qty}
            if (!empty($all_order_ids) && !empty($part_nos)) {
                // 建立 d_id → part_no 對照
                $did2pno = [];
                foreach ($ds_map as $pno => $r) $did2pno[intval($r['d_id'])] = $pno;
                // 建立 d_id → part_no 對應 bom 的 product_id，以 d_id 找出 is_list 的 Product_id
                $pho2  = implode(',', array_fill(0, count($all_order_ids), '?'));
                $php2  = implode(',', array_fill(0, count($part_nos), '?'));
                $stRev = $pdo->prepare("
                    SELECT il.Order_id, il.Product_id,
                           SUM(il.Qty * il.Unit_price * COALESCE((SELECT exchange_rate FROM order_track WHERE Order_id=il.Order_id LIMIT 1),1)) AS rev,
                           SUM(il.Qty) AS ship_qty
                    FROM is_list il
                    LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
                    WHERE il.Order_id IN ($pho2) AND il.Product_id IN ($php2)
                      AND (ist.is_count IS NULL OR ist.is_count != 0)
                    GROUP BY il.Order_id, il.Product_id
                    UNION
                    SELECT som.Order_id, il.Product_id,
                           SUM(il.Qty * il.Unit_price * COALESCE((SELECT exchange_rate FROM order_track WHERE Order_id=som.Order_id LIMIT 1),1)) AS rev,
                           SUM(il.Qty) AS ship_qty
                    FROM is_list il
                    JOIN shipment_order_map som ON som.IS_id = il.IS_id
                    LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
                    WHERE som.Order_id IN ($pho2) AND il.Product_id IN ($php2)
                      AND (ist.is_count IS NULL OR ist.is_count != 0)
                    GROUP BY som.Order_id, il.Product_id
                ");
                $rev_params = array_merge($all_order_ids, $part_nos, $all_order_ids, $part_nos);
                $stRev->execute($rev_params);
                foreach ($stRev->fetchAll(PDO::FETCH_ASSOC) as $rv) {
                    $key = intval($rv['Order_id']).'|'.$rv['Product_id'];
                    $existing = $rev_map[$key] ?? ['rev' => 0, 'ship_qty' => 0];
                    // UNION 可能有重複（同一出貨同時符合兩個條件），取較大收入
                    if (floatval($rv['rev']) > $existing['rev']) {
                        $rev_map[$key] = ['rev' => floatval($rv['rev']), 'ship_qty' => intval($rv['ship_qty'])];
                    }
                }
            }

            // ⑥ 整理 bom_rows：按料號字串分組
            $boms_by_pno = []; // part_no(string) => [bom_row, ...]
            foreach ($bom_rows as $b) $boms_by_pno[$b['part_no']][] = $b;

            // ⑦ 逐料號計算毛利率
            $out = []; // part_no => {margin_pct, has_missing_cost}
            foreach ($ds_map as $pno => $ds) {
                $d_id = intval($ds['d_id']);
                $boms = $boms_by_pno[$pno] ?? [];
                if (empty($boms)) { $out[$pno] = ['margin_pct' => null, 'has_missing_cost' => false]; continue; }
                $gear_factor = $gf_map[$d_id] ?? 1.0;
                $wRevSum = 0; $wMpSum = 0; $anyMissing = false;
                foreach ($boms as $bom) {
                    $has_missing = false;
                    $tc = getOrCalcBomCost($pdo, $bom['bom'], $d_id, $ds['part_type'], $gear_factor, $has_missing);
                    if ($has_missing) $anyMissing = true;
                    $oids   = $bom['order_ids']  ? explode(',', $bom['order_ids'])  : [];
                    $allocs = $bom['alloc_qtys'] ? explode(',', $bom['alloc_qtys']) : [];
                    $bqty   = max(1, intval($bom['bom_qty']));
                    foreach ($oids as $k => $raw_oid) {
                        $oid   = intval($raw_oid);
                        $alloc = intval($allocs[$k] ?? 0);
                        if (!$oid) continue;
                        $ord = $order_map[$oid] ?? null;
                        $key = $oid.'|'.$pno;
                        $rv  = $rev_map[$key] ?? null;
                        $total_rev = $rv ? floatval($rv['rev']) : 0;
                        $ship_qty  = $rv ? intval($rv['ship_qty']) : 0;
                        // 無出貨時用訂單單價估算
                        if ($ship_qty === 0 && $ord && floatval($ord['unit_price']) > 0) {
                            $qty = $alloc > 0 ? $alloc : intval($ord['Qty'] ?? $bqty);
                            $total_rev = floatval($ord['unit_price']) * $qty * floatval($ord['ex_rate'] ?? 1);
                        }
                        if ($total_rev <= 0) continue;
                        $calc_qty   = $alloc > 0 ? $alloc : $bqty;
                        $alloc_cost = $tc * $calc_qty;
                        $gp  = $total_rev - $alloc_cost;
                        $mp  = $total_rev > 0 ? $gp / $total_rev * 100 : null;
                        if ($mp !== null) { $wRevSum += $total_rev; $wMpSum += $mp * $total_rev; }
                    }
                }
                $margin = $wRevSum > 0 ? round($wMpSum / $wRevSum, 1) : null;
                $out[$pno] = ['margin_pct' => $margin, 'has_missing_cost' => $anyMissing];
            }
            echo json_encode(['success' => true, 'data' => $out]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 批次偵測料號是否有外包製程單價缺失（純 SQL，不計算完整成本）──
    if ($action === 'get_margins_for_list') {
        $part_nos_raw = json_decode(trim($_POST['part_nos'] ?? '[]'), true) ?: [];
        $part_nos_raw = array_values(array_filter(array_map('strval', $part_nos_raw)));
        if (empty($part_nos_raw)) { echo json_encode(['success' => true, 'data' => []]); exit; }
        $part_nos_raw = array_slice($part_nos_raw, 0, 50);
        try {
            $ph = implode(',', array_fill(0, count($part_nos_raw), '?'));
            // 找出：有外包製程（non-internal maker）但無任何轉帳價格記錄的料號
            $st = $pdo->prepare("
                SELECT DISTINCT b.d_id AS part_no
                FROM bom b
                JOIN bom_ing bi ON bi.bom = b.bom
                LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
                LEFT JOIN (
                    SELECT t.bom, t.bom_sn
                    FROM bom_ing_transfer_log t
                    WHERE t.price > 0 OR t.modified_unit_price > 0
                    GROUP BY t.bom, t.bom_sn
                ) has_price ON has_price.bom = bi.bom AND has_price.bom_sn = bi.bom_sn
                WHERE b.d_id IN ($ph)
                  AND (ml.internal IS NULL OR ml.internal != 1)
                  AND has_price.bom IS NULL
            ");
            $st->execute($part_nos_raw);
            $missingSet = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'part_no'));
            $result = [];
            foreach ($part_nos_raw as $pn) {
                $result[$pn] = ['margin' => null, 'has_missing_cost' => isset($missingSet[$pn])];
            }
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // ── 快速單料號毛利估算（單件成本 vs 平均售價，不跑完整訂單/出貨分析）──
    if ($action === 'get_quick_margin') {
        $part_no = trim($_POST['part_no'] ?? '');
        if (!$part_no) { echo json_encode(['success' => false]); exit; }
        try {
            // d_setting
            $stDs = $pdo->prepare("SELECT d_id, COALESCE(Type,'N') AS part_type FROM d_setting WHERE D_Setting_Id = ? LIMIT 1");
            $stDs->execute([$part_no]);
            $dsRow = $stDs->fetch(PDO::FETCH_ASSOC);
            if (!$dsRow) { echo json_encode(['success' => true, 'margin' => null, 'has_missing_cost' => false]); exit; }
            $dsId = intval($dsRow['d_id']); $partType = $dsRow['part_type'];
            $gearFactor = 1.0;
            if ($partType === 'G' && $dsId > 0) {
                $stGf = $pdo->prepare("SELECT COALESCE(Module,1)*COALESCE(Teeth,1)*COALESCE(Face_Width,1) AS gf FROM d_setting_gear WHERE d_setting_id=? LIMIT 1");
                $stGf->execute([$dsId]);
                $gfRow = $stGf->fetch(PDO::FETCH_ASSOC);
                if ($gfRow) $gearFactor = floatval($gfRow['gf']);
            }
            // 取最新 BOM
            $stBom = $pdo->prepare("SELECT bom FROM bom WHERE d_id=? ORDER BY Created_At DESC LIMIT 1");
            $stBom->execute([$part_no]);
            $latestBom = $stBom->fetchColumn();
            if (!$latestBom) { echo json_encode(['success' => true, 'margin' => null, 'has_missing_cost' => false]); exit; }
            $hasMissing = false;
            $unitCost = calcFullBomCostPerPc($pdo, $latestBom, $dsId, $partType, $gearFactor, $hasMissing);
            // 平均售價：先 is_list，再 order_track
            $stPrice = $pdo->prepare("
                SELECT ROUND(AVG(CASE WHEN il.Unit_price>0 THEN il.Unit_price ELSE NULL END),2) AS avg_price
                FROM is_list il LEFT JOIN is_sale_type ist ON il.sale_type=ist.sale_type_id
                WHERE il.Product_id=? AND (ist.is_count IS NULL OR ist.is_count!=0)
            ");
            $stPrice->execute([$part_no]);
            $avgPrice = floatval($stPrice->fetchColumn());
            if ($avgPrice <= 0) {
                $stOrdP = $pdo->prepare("SELECT ROUND(AVG(CASE WHEN unit_price>0 THEN unit_price ELSE NULL END),2) FROM order_track WHERE d_id=? AND unit_price>0");
                $stOrdP->execute([$part_no]);
                $avgPrice = floatval($stOrdP->fetchColumn());
            }
            $margin = ($avgPrice > 0 && $unitCost > 0) ? round(($avgPrice - $unitCost) / $avgPrice * 100, 1) : null;
            echo json_encode(['success' => true, 'margin' => $margin, 'unit_cost' => round($unitCost, 2), 'avg_price' => round($avgPrice, 2), 'has_missing_cost' => $hasMissing]);
        } catch (Exception $e) { echo json_encode(['success' => false]); }
        exit;
    }

    if ($action === 'rebuild_part_cache') {
        try {
            ensurePartDateCache($pdo);
            $pdo->exec("TRUNCATE TABLE part_date_cache");
            ensurePartDateCache($pdo);
            // 同步清除 BOM 成本快取，使下次批次毛利率重新計算
            try { $pdo->exec("TRUNCATE TABLE bom_cost_cache"); } catch (\Throwable $e) {}
            $cnt = $pdo->query("SELECT COUNT(*) FROM part_date_cache")->fetchColumn();
            echo json_encode(['success' => true, 'count' => intval($cnt)]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    echo json_encode(['success' => false, 'message' => '未知的 action']);
    exit;
}

// 取登入用戶中文名稱（供列印頁首使用）
$print_user_display = '';
try {
    $conn2 = new DBConnection();
    $pdo2  = $conn2->getPDO();
    $pdo2->exec("SET NAMES utf8mb4");
    $su = $pdo2->prepare("SELECT user_cname FROM `user` WHERE id = ? LIMIT 1");
    $su->execute([$_SESSION['id'] ?? 0]);
    $print_user_display = $su->fetchColumn() ?: ($_SESSION['userName'] ?? '');
} catch (\Throwable $e) {
    $print_user_display = $_SESSION['userName'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>料號成本分析</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
    :root {
        --primary: #2c5aa0; --primary-light: #e8f0fb;
        --success: #27ae60; --danger: #e74c3c; --warning: #f39c12; --info: #2980b9;
        --bg: #f0f3f8; --card: #fff; --border: #dde4ed;
        --text: #2d3748; --muted: #718096;
        --shadow: 0 2px 8px rgba(44,90,160,0.08);
        --panel-left: 320px;
    }
    body { background: #f0f3f8; color: #2d3748; }
    /* 完全跟隨 kpi_main.php 的 right_col 處理方式 */
    .right_col { background: #f0f3f8 !important; overflow-x: hidden !important; overflow-y: visible !important;
                 max-width: 100%; box-sizing: border-box; }

    /* ── 頂部工具列 ── */
    .top-bar {
        background: #fff; border: 1px solid var(--border); border-radius: 8px;
        padding: 8px 14px; display: flex; align-items: center; gap: 8px;
        flex-wrap: wrap; box-shadow: var(--shadow); margin-bottom: 12px;
    }
    .top-bar h4 { margin: 0; font-size: 15px; font-weight: 700; color: var(--primary); white-space: nowrap; }
    .top-bar .form-control { height: 30px; font-size: 12px; padding: 3px 8px; }
    .top-bar .btn { height: 30px; padding: 0 12px; font-size: 12px; line-height: 28px; }
    .filter-badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 9px; border-radius: 12px; font-size: 11px; cursor: pointer;
        border: 1px solid transparent; white-space: nowrap; transition: all .15s;
    }
    .filter-badge:hover, .filter-badge.active { filter: brightness(.92); box-shadow: 0 0 0 2px var(--primary); }
    .fb-loss   { background: #fdecea; color: #c62828; border-color: #ef9a9a; }
    .fb-low    { background: #fff8e1; color: #e65100; border-color: #ffcc80; }
    .fb-ok     { background: #e8f5e9; color: #2e7d32; border-color: #a5d6a7; }
    .fb-noprice{ background: #f3e5f5; color: #6a1b9a; border-color: #ce93d8; }

    /* ── 左欄（料號清單） ── */
    .left-panel {
        background: var(--card); border-radius: 8px; border: 1px solid var(--border);
        box-shadow: var(--shadow); overflow: hidden;
        display: flex; flex-direction: column;
        max-height: calc(100vh - 120px); /* 不固定，最高到視窗底 */
    }
    .left-panel .panel-header {
        padding: 6px 12px; border-bottom: 1px solid var(--border);
        display: flex; align-items: center; gap: 6px;
        background: #f7f9fc; font-size: 12px; color: var(--muted);
        flex-shrink: 0; flex-wrap: wrap;
    }
    #pg-mini { display: inline-flex; align-items: center; gap: 3px; margin-left: auto; }
    .part-list { overflow-y: auto; flex: 1; }
    .part-item {
        padding: 9px 12px; border-bottom: 1px solid #f0f3f7;
        cursor: pointer; transition: background .12s;
        display: flex; align-items: center; gap: 8px;
    }
    .part-item:hover { background: #f0f6ff; }
    .part-item.active { background: var(--primary-light); border-left: 3px solid var(--primary); }
    .part-item .pi-no { font-size: 13px; font-weight: 700; color: var(--primary); line-height: 1.2; }
    .part-item .pi-name { font-size: 11px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }
    .part-item .pi-client { font-size: 10px; color: var(--muted); }
    .pi-meta { flex: 1; overflow: hidden; }
    .margin-dot {
        width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
    }
    .margin-dot.loss   { background: var(--danger); }
    .margin-dot.low    { background: var(--warning); }
    .margin-dot.ok     { background: var(--success); }
    .margin-dot.nodata { background: #ccc; }
    .pg-mini { font-size: 11px; }

    /* ── 右側詳情面板 ── */
    .right-panel { /* 正常 block，隨頁面捲動 */ }
    .detail-empty {
        background: #fff; border-radius: 8px; border: 1px solid var(--border);
        padding: 60px 20px; text-align: center; color: var(--muted);
    }
    .detail-empty i { font-size: 48px; opacity: .3; display: block; margin-bottom: 12px; }
    .detail-wrap { /* 無需額外 padding，由右欄決定 */ }

    /* 摘要卡片列 */
    .summary-cards {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap: 10px;
        margin-bottom: 14px;
    }
    .s-card {
        background: var(--card); border-radius: 10px; padding: 12px 14px;
        box-shadow: var(--shadow); border-top: 3px solid var(--border);
    }
    .s-card.sc-primary { border-top-color: var(--primary); }
    .s-card.sc-success { border-top-color: var(--success); }
    .s-card.sc-danger  { border-top-color: var(--danger); }
    .s-card.sc-warn    { border-top-color: var(--warning); }
    .s-card.sc-info    { border-top-color: var(--info); }
    .s-label { font-size: 10px; color: var(--muted); margin-bottom: 3px; text-transform: uppercase; }
    .s-val   { font-size: 20px; font-weight: 700; line-height: 1.2; }
    .s-val small { font-size: 11px; font-weight: 400; color: var(--muted); }
    .s-sub   { font-size: 10px; color: var(--muted); margin-top: 2px; }

    /* 分頁標籤 */
    .detail-tabs { display: flex; gap: 2px; margin-bottom: 12px; border-bottom: 2px solid var(--border); }
    .d-tab {
        padding: 7px 16px; font-size: 13px; cursor: pointer;
        border-bottom: 3px solid transparent; margin-bottom: -2px;
        color: var(--muted); white-space: nowrap; transition: all .15s;
    }
    .d-tab:hover { color: var(--primary); }
    .d-tab.active { color: var(--primary); font-weight: 700; border-bottom-color: var(--primary); }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    /* BOM 選擇器 */
    .bom-selector {
        background: #f7f9fc; border: 1px solid var(--border); border-radius: 8px;
        padding: 10px 14px; margin-bottom: 12px;
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    }
    .bom-selector label { font-size: 12px; color: var(--muted); margin: 0; white-space: nowrap; }
    .bom-selector select { font-size: 12px; height: 30px; }

    /* 製程成本表 */
    .cost-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .cost-table th {
        background: #f7f9fc; padding: 7px 10px; text-align: left;
        font-size: 11px; font-weight: 600; color: var(--muted);
        border-bottom: 2px solid var(--border); white-space: nowrap;
    }
    .cost-table td { padding: 7px 10px; border-bottom: 1px solid #f0f3f7; vertical-align: middle; }
    .cost-table tr:hover td { background: #f8fbff; }
    .cost-table .total-row td { font-weight: 700; background: #f0f6ff; border-top: 2px solid var(--primary); }
    .badge-ext   { background: #e3f2fd; color: #1565c0; padding: 2px 7px; border-radius: 10px; font-size: 10px; }
    .badge-kpi   { background: #f3e5f5; color: #6a1b9a; padding: 2px 7px; border-radius: 10px; font-size: 10px; }
    .badge-kpi.clickable { cursor:pointer; text-decoration:underline dotted #9b59b6; }
    .badge-kpi.clickable:hover { background:#e1bee7; }
    .badge-nodata  { background: #fafafa; color: #9e9e9e; padding: 2px 7px; border-radius: 10px; font-size: 10px; }
    .badge-manual  { background: #fff3e0; color: #e65100; padding: 2px 7px; border-radius: 10px; font-size: 10px; font-weight: 600; }
    .td-kpi-cost   { background: #f9f0ff !important; color: #5b2d8e !important; }
    .td-manual-cost{ background: #fff8e1 !important; color: #bf360c !important; }
    .price-inp-has-data { border: 2px solid #f39c12 !important; background: #fffde7 !important; }
    .calc-debug-table { width:100%; border-collapse:collapse; font-size:12px; margin:8px 0; }
    .calc-debug-table th { background:#f3e5f5; color:#6a1b9a; padding:5px 8px; text-align:left; }
    .calc-debug-table td { padding:5px 8px; border-bottom:1px solid #f0e8f7; }
    .calc-debug-result { margin-top:10px; padding:8px 12px; background:#f9f0ff; border-radius:6px; font-size:13px; color:#5b2d8e; font-weight:600; }

    /* 出貨記錄表 */
    .ship-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .ship-table th {
        background: #f7f9fc; padding: 7px 10px;
        font-size: 11px; font-weight: 600; color: var(--muted);
        border-bottom: 2px solid var(--border); white-space: nowrap; text-align: left;
    }
    .ship-table td { padding: 6px 10px; border-bottom: 1px solid #f0f3f7; }
    .ship-table tr:hover td { background: #f8fbff; }
    .price-ok   { color: var(--success); font-weight: 600; }
    .price-loss { color: var(--danger);  font-weight: 600; }
    .price-warn { color: var(--warning); font-weight: 600; }

    /* 趨勢圖 */
    .chart-wrap { background: var(--card); border-radius: 10px; padding: 14px; box-shadow: var(--shadow); margin-bottom: 14px; }
    .chart-title-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .chart-title-row h5 { margin: 0; font-size: 13px; font-weight: 700; color: var(--text); }

    /* 訊息 */
    .info-box { padding: 16px; text-align: center; color: var(--muted); font-size: 13px; }
    .loading-spinner { text-align: center; padding: 24px; color: var(--muted); }

    /* BOM 異常行 */
    .bom-row-anomaly td { background: #fde8ea !important; }
    .bom-row-anomaly:hover td { background: #fcd0d4 !important; }
    .badge-anomaly { background:#f44336; color:#fff; padding:1px 6px; border-radius:8px; font-size:9px; }
    .badge-dup     { background:#ff9800; color:#fff; padding:1px 6px; border-radius:8px; font-size:9px; }

    /* 加工單價輸入 */
    .price-inp { width:70px; height:22px; font-size:11px; padding:0 4px; border:1px solid var(--border); border-radius:3px; }
    .note-inp  { width:80px; height:22px; font-size:11px; padding:0 4px; border:1px solid var(--border); border-radius:3px; }

    /* 圖面 modal file list */
    .dm-file-item { padding:7px 10px; border-bottom:1px solid #eee; cursor:pointer; font-size:12px; }
    .dm-file-item:hover { background:#f0f6ff; }
    .dm-file-item.active { background:#e8f0fb; font-weight:700; border-left:3px solid var(--primary); }

    /* margin dot 'mid' class */
    .margin-dot.mid { background: #ffc107; }

    /* 捲動凍結標頭 */
    .cost-table-scroll {
        overflow-x: auto; overflow-y: auto; max-height: 340px;
    }
    .cost-table-scroll .cost-table thead th {
        position: sticky; top: 0; z-index: 2; background: #f7f9fc;
    }
    .ship-table-scroll {
        overflow-x: auto; overflow-y: auto; max-height: 380px;
    }
    .ship-table-scroll .ship-table thead th {
        position: sticky; top: 0; z-index: 2; background: #f7f9fc;
    }

    /* 搜尋自動完成下拉 */
    #client-dropdown-wrap { position: relative; display: inline-block; }
    #client-dropdown {
        display: none; position: absolute; left: 0; top: 100%; margin-top: 2px;
        min-width: 220px; max-height: 240px; overflow-y: auto;
        background: #fff; border: 1px solid var(--border); border-radius: 6px;
        box-shadow: 0 6px 18px rgba(44,90,160,0.13); z-index: 9999;
    }
    .date-preset {
        height: 24px !important; padding: 0 7px !important; font-size: 11px !important;
        line-height: 22px !important; border-radius: 3px; flex-shrink: 0;
    }
    .date-preset.active { background: var(--primary); color: #fff; border-color: var(--primary); }
    .kw-suggest-hdr {
        padding: 5px 10px 3px; font-size: 10px; color: var(--muted);
        border-bottom: 1px solid #f0f3f7; text-transform: uppercase; letter-spacing: .4px;
    }
    .kw-suggest-item {
        padding: 7px 12px; cursor: pointer; font-size: 12px;
        border-bottom: 1px solid #f5f7fb; display: flex; align-items: center; gap: 6px;
    }
    .kw-suggest-item:last-child { border-bottom: none; }
    .kw-suggest-item:hover { background: #f0f6ff; }
    .kw-suggest-id { color: var(--muted); font-size: 10px; flex-shrink: 0; }

    /* 毛利分析 tab */
    .margin-bom-card { border:1px solid var(--border); border-radius:6px; margin-bottom:12px; overflow:hidden; }
    .margin-bom-header { background:#f0f6ff; padding:8px 12px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; cursor:pointer; border-bottom:1px solid var(--border); }
    .margin-bom-header:hover { background:#e4efff; }
    .margin-bom-title { font-weight:700; font-size:13px; flex:0 0 auto; }
    .margin-bom-body { padding:10px 12px; }
    .margin-order-row { border:1px solid #e8edf3; border-radius:5px; margin-bottom:8px; overflow:hidden; }
    .margin-order-header { background:#f8f9fc; padding:6px 10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12px; cursor:pointer; }
    .margin-order-header:hover { background:#eef3fa; }
    .margin-order-ships { display:none; padding:8px 10px; }
    .margin-ship-table { width:100%; border-collapse:collapse; font-size:11px; margin-top:4px; }
    .margin-ship-table th { background:#f5f5f5; padding:4px 8px; border-bottom:1px solid #ddd; }
    .margin-ship-table td { padding:4px 8px; border-bottom:1px solid #f0f0f0; }
    .margin-profit-ok  { color:var(--success); font-weight:700; }
    .margin-profit-low { color:#f39c12; font-weight:700; }
    .margin-profit-bad { color:var(--danger); font-weight:700; }
    .margin-tag-closed { background:#f5f5f5; color:#999; border-radius:3px; padding:1px 6px; font-size:10px; white-space:nowrap; }
    .margin-tag-active { background:#e8f5e9; color:var(--success); border-radius:3px; padding:1px 6px; font-size:10px; white-space:nowrap; }

    /* 列印頁首（螢幕隱藏，列印顯示） */
    #print-header { display: none; }

    /* 印列 */
    @page { margin: 0; } /* 清除瀏覽器原生頁首/頁尾（含底部網址列） */
    @media print {
        /* ① 隱藏不需列印的元素 */
        .col-md-3, .left-panel, .top-bar, .top_nav, .detail-tabs, .no-print,
        .bom-selector select, .modal, .modal-backdrop,
        .btn-save-log, .btn-edit-toggle, .btn-del-ing,
        #client-analysis-panel, .tab-pane:not(.active) { display: none !important; }

        /* ② 版面：@page margin:0 後改用 body padding 留白；底部設 0 避免多出空白第二頁 */
        html, body { width: 100% !important; margin: 0 !important; padding: 1cm 1.2cm 0 1.2cm !important; }

        /* 自訂列印頁首：左側大標題「料號成本分析」，右側日期+用戶 */
        #print-header {
            display: flex !important; justify-content: space-between; align-items: flex-end;
            border-bottom: 2px solid #222; padding-bottom: 6px; margin-bottom: 14px;
        }
        #ph-title {
            font-size: 20px; font-weight: 700; color: #111; letter-spacing: 1px;
        }
        #ph-meta {
            text-align: right; font-size: 11px; color: #444; line-height: 1.7;
        }
        #ph-meta span { display: block; }

        /* 料號標題加大 */
        #dc-part-no { font-size: 26px !important; }
        .container.body, .main_container { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .right_col { margin-left: 0 !important; width: 100% !important; padding: 4px !important; }
        .col-md-9 { width: 100% !important; padding: 0 !important; float: none !important; }
        .row { margin: 0 !important; }

        /* ③ 移除所有背景色與陰影（省墨） */
        * { background: transparent !important; background-color: transparent !important;
            background-image: none !important; box-shadow: none !important; }

        /* ③-b 覆蓋高特異性 !important 背景（同特異性後者優先）
           .right_col{background:#f0f3f8!important} 特異性 0,1,0 > * 的 0,0,0 → 必須明確覆蓋 */
        body { background: transparent !important; }
        .right_col { background: transparent !important; min-height: 0 !important; height: auto !important; }
        .bom-row-anomaly td { background: transparent !important; }
        .bom-row-anomaly:hover td { background: transparent !important; }
        .cost-table th, .cost-table td, .cost-table .total-row td { background: transparent !important; }
        .ship-table th, .ship-table td { background: transparent !important; }
        .s-card, .chart-wrap, .bom-selector { background: transparent !important; }

        /* ④ 表格填滿頁寬，欄位不換列 */
        .cost-table, .ship-table { width: 100% !important; }
        .ship-table th, .ship-table td,
        .cost-table th, .cost-table td { white-space: nowrap !important; }

        /* ⑤ 摘要卡片橫排不換列、縮小間距 */
        .summary-cards { display: flex !important; flex-wrap: nowrap !important; gap: 4px !important; margin-bottom: 8px !important; }
        #ship-summary-row { flex-wrap: nowrap !important; }
        .s-card { min-width: auto !important; flex: 0 1 auto !important; padding: 4px 8px !important; }

        /* ⑥ 解除捲動容器限制，讓全部列印出來 */
        .cost-table-scroll, .ship-table-scroll { max-height: none !important; overflow: visible !important; }

        /* ⑦ 避免空白頁：重置所有容器高度，防止多餘空白頁 */
        html, body, .right_col, .main_container, .container.body,
        .right-panel, #right-panel, #main-grid, .col-md-9,
        .detail-content, .detail-wrap, #detail-content,
        .tab-pane.active { height: auto !important; min-height: 0 !important; overflow: visible !important; }
        /* 禁止在內容後插入分頁 */
        #detail-content, .tab-pane.active, .detail-wrap { page-break-after: avoid !important; }
    }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">

<!-- 列印頁首（螢幕隱藏，@media print 顯示） -->
<div id="print-header">
    <div id="ph-title"><i class="fa fa-bar-chart"></i> 料號成本分析</div>
    <div id="ph-meta">
        <span id="ph-date"></span>
        <span id="ph-user">列印者：<?= htmlspecialchars($print_user_display, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</div>

<!-- ── 頂部工具列 ── -->
<div class="top-bar no-print">
    <div class="btn-group btn-group-sm" style="flex-shrink:0;">
        <button id="mode-btn-parts" class="btn btn-primary" onclick="switchMode('parts')" title="料號成本分析">
            <i class="fa fa-bar-chart"></i> 料號成本分析
        </button>
        <button id="mode-btn-clients" class="btn btn-default" onclick="switchMode('clients')" title="客戶獲利分析">
            <i class="fa fa-users"></i> 客戶分析
        </button>
    </div>
    <input type="text" id="tb-keyword" class="form-control" placeholder="料號 / 品名…" style="width:160px;"
        oninput="onKwInput()"
        onkeydown="if(event.key==='Enter'){clearTimeout(searchTimer);doSearch();}"
        ondblclick="if(this.value){this.value='';clearTimeout(searchTimer);doSearch();}">
    <div id="client-dropdown-wrap">
        <input type="text" id="tb-client" class="form-control" placeholder="篩選客戶…" style="width:150px;"
            oninput="onClientInput()"
            onkeydown="if(event.key==='Enter'){clearTimeout(searchTimer);hideClientDropdown();doSearch();}else if(event.key==='Escape'){hideClientDropdown();}"
            ondblclick="if(this.value){this.value='';hideClientDropdown();clearTimeout(searchTimer);doSearch();}"
            onblur="setTimeout(hideClientDropdown,150)">
        <div id="client-dropdown"></div>
    </div>
    <div style="display:flex;align-items:center;gap:4px;flex-shrink:0;">
        <span style="font-size:11px;color:var(--muted);white-space:nowrap;">有記錄的期間：</span>
        <button class="btn btn-default date-preset active" data-m="3"  onclick="setDatePreset(3)"  title="僅顯示近3個月內有訂單/BOM/出貨記錄的料號">近3月</button>
        <button class="btn btn-default date-preset"        data-m="6"  onclick="setDatePreset(6)"  title="僅顯示近6個月內有訂單/BOM/出貨記錄的料號">近6月</button>
        <button class="btn btn-default date-preset"        data-m="12" onclick="setDatePreset(12)" title="僅顯示近1年內有訂單/BOM/出貨記錄的料號">近1年</button>
        <button class="btn btn-default date-preset"        data-m="0"  onclick="setDatePreset(0)"  title="顯示所有料號，不限時間">全部</button>
        <span style="font-size:11px;color:var(--muted);">或自訂：</span>
        <input type="date" id="tb-date-from" class="form-control" style="width:120px;" title="有記錄的起始日"
            oninput="$('.date-preset').removeClass('active');debounceSearch();">
        <span style="font-size:12px;color:var(--muted);">~</span>
        <input type="date" id="tb-date-to" class="form-control" style="width:120px;" title="有記錄的結束日"
            oninput="$('.date-preset').removeClass('active');debounceSearch();">
    </div>
    <button class="btn btn-primary btn-sm" onclick="if(validateDateRange())loadPartList(1)"><i class="fa fa-search"></i> 查詢</button>
    <button class="btn btn-default btn-sm" onclick="clearFilters()" title="清除所有篩選條件並關閉料號資料"><i class="fa fa-times"></i> 清除篩選</button>

    <div style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">
        <span class="filter-badge fb-loss" id="fb-loss" onclick="toggleMarginFilter('loss',this)"><i class="fa fa-minus-circle"></i> 虧損</span>
        <span class="filter-badge fb-low"  id="fb-low"  onclick="toggleMarginFilter('low',this)"><i class="fa fa-arrow-down"></i> 低利&lt;<span id="low-thr-display">10</span>%</span>
        <span class="filter-badge fb-ok"   id="fb-ok"   onclick="toggleMarginFilter('ok',this)"><i class="fa fa-check-circle"></i> 正常≥<span id="ok-thr-display">20</span>%</span>
        <span class="filter-badge fb-noprice" id="fb-noprice" onclick="toggleMarginFilter('no_price',this)"><i class="fa fa-tag"></i> 無售價</span>
        <span class="filter-badge" id="fb-no-cost" style="background:#fff3cd;color:#856404;border:1px solid #ffc107;" onclick="toggleMarginFilter('no_cost',this)" title="有製程無單價，成本低估"><i class="fa fa-exclamation-triangle"></i> 成本未齊</span>
        <!-- 隱藏 inputs 供 JS 讀寫 -->
        <input type="hidden" id="low-thr" value="10">
        <input type="hidden" id="ok-thr"  value="20">
    </div>

    <div style="margin-left:auto; display:flex; gap:6px; align-items:center;">
        <button class="btn btn-default btn-sm" onclick="exportListCsv()" title="匯出左側料號清單 CSV"><i class="fa fa-download"></i> 清單CSV</button>
        <button class="btn btn-default btn-sm" onclick="window.print()" title="列印頁面"><i class="fa fa-print"></i></button>
        <button class="btn btn-warning btn-sm no-print" id="btn-rebuild-cache" onclick="rebuildPartCache()" title="強制重建料號日期快取（資料有變動時使用）"><i class="fa fa-refresh"></i> 更新快取</button>
        <button class="btn btn-info btn-sm" onclick="openSettingsModal()" title="系統設定"><i class="fa fa-cog"></i> 設定</button>
    </div>
</div>

<!-- ── 客戶分析面板（mode=clients 時顯示）── -->
<div id="client-analysis-panel" style="display:none; margin-bottom:14px;">
    <div style="background:#fff; border-radius:8px; padding:16px; border:1px solid var(--border); box-shadow:var(--shadow);">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px; flex-wrap:wrap;">
            <h5 style="margin:0; font-size:14px; font-weight:700; color:var(--primary);">
                <i class="fa fa-users"></i> 客戶分析
            </h5>
            <button class="btn btn-primary btn-sm" onclick="loadCustomerAnalysis()" style="margin-left:auto;">
                <i class="fa fa-refresh"></i> 重新整理
            </button>
        </div>
        <div id="client-analysis-content">
            <div class="info-box"><i class="fa fa-arrow-up"></i> 點擊「客戶分析」按鈕後載入</div>
        </div>
    </div>
</div>

<!-- ── 主要版面（Bootstrap Grid） ── -->
<div class="row" id="main-grid" style="margin:0;">

    <!-- ── 左：料號清單 (col-md-3) ── -->
    <div class="col-md-3" style="padding-right:8px;">
        <div class="left-panel">
            <div class="panel-header">
                <span id="list-stat">載入中…</span>
                <select id="pp-sel" style="height:24px;font-size:11px;border:1px solid var(--border);border-radius:4px;padding:0 4px;" onchange="loadPartList(1)">
                    <option value="20">20筆</option>
                    <option value="50">50筆</option>
                    <option value="100">100筆</option>
                </select>
                <div class="pg-mini" id="pg-mini"></div>
            </div>
            <div class="part-list" id="part-list">
                <div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>
            </div>
        </div>
    </div>

    <!-- ── 右：詳情面板 (col-md-9) ── -->
    <div class="col-md-9" style="padding-left:8px;">
    <div class="right-panel" id="right-panel">
        <div class="detail-empty" id="detail-empty">
            <i class="fa fa-search"></i>
            <div>請從左側選擇料號</div>
            <div style="font-size:11px;">點擊料號後可查看成本、售價與趨勢分析</div>
        </div>
        <div id="detail-content" style="display:none;">
            <div class="detail-wrap">
                <!-- 料號標題列 -->
                <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                    <div>
                        <h4 style="margin:0; font-size:16px; font-weight:700; color:var(--primary); display:inline-flex; align-items:center; gap:6px;">
                            <span id="dc-part-no" style="cursor:pointer; text-decoration-style:dotted;" onclick="openDrawingModal()" title="點擊查看料號圖面">–</span>
                            <button id="btn-open-part-edit" class="btn btn-default btn-xs no-print" onclick="openPartEditPage()" title="在料號管理開啟此料號編輯" style="padding:2px 6px; font-size:11px; vertical-align:middle; display:none;"><i class="fa fa-cog"></i></button>
                        </h4>
                        <div style="font-size:12px; color:var(--muted); margin-top:2px;" id="dc-drawing-no"></div>
                        <div style="font-size:12px; color:var(--text); margin-top:1px; font-style:italic;" id="dc-spec-no"></div>
                        <div id="dc-gear-spec" style="display:none; font-size:11px; color:var(--info); margin-top:3px;">
                            <i class="fa fa-cog"></i> <span id="dc-gear-text"></span>
                        </div>
                    </div>
                    <div class="no-print" style="display:flex; gap:6px; align-items:flex-start;">
                        <button class="btn btn-default btn-xs" onclick="exportShipmentCsv()"><i class="fa fa-download"></i> 出貨CSV</button>
                        <button class="btn btn-default btn-xs" onclick="window.print()"><i class="fa fa-print"></i></button>
                    </div>
                </div>

                <!-- 摘要卡片 -->
                <div class="summary-cards" id="summary-cards">
                    <div class="s-card sc-primary">
                        <div class="s-label">估計單位成本</div>
                        <div class="s-val" id="sc-cost">–</div>
                        <div class="s-sub" id="sc-cost-sub">–</div>
                    </div>
                    <div class="s-card sc-success">
                        <div class="s-label">平均出貨售價</div>
                        <div class="s-val" id="sc-price">–</div>
                        <div class="s-sub" id="sc-price-sub">–</div>
                    </div>
                    <div class="s-card" id="sc-margin-card">
                        <div class="s-label">毛利率</div>
                        <div class="s-val" id="sc-margin">–</div>
                        <div class="s-sub" id="sc-margin-sub">–</div>
                    </div>
                    <div class="s-card sc-info">
                        <div class="s-label">出貨次數</div>
                        <div class="s-val" id="sc-ship-count">–</div>
                        <div class="s-sub" id="sc-ship-sub">–</div>
                    </div>
                    <div class="s-card">
                        <div class="s-label">BOM 數量</div>
                        <div class="s-val" id="sc-bom-count">–</div>
                        <div class="s-sub" id="sc-bom-sub">–</div>
                    </div>
                </div>

                <!-- 分頁標籤 -->
                <div class="detail-tabs">
                    <div class="d-tab active" data-tab="cost"><i class="fa fa-cogs"></i> BOM製程成本</div>
                    <div class="d-tab" data-tab="ship"><i class="fa fa-truck"></i> 出貨與訂單</div>
                    <div class="d-tab" data-tab="trend"><i class="fa fa-line-chart"></i> 成本vs售價趨勢</div>
                    <div class="d-tab" data-tab="margin"><i class="fa fa-calculator"></i> 毛利分析</div>
                </div>

                <!-- Tab: BOM製程成本 -->
                <div class="tab-pane active" id="tab-cost">
                    <div class="bom-selector">
                        <label><i class="fa fa-list-alt"></i> 選擇生產製令(BOM)：</label>
                        <select id="bom-select" class="form-control" style="width:auto; min-width:200px;" onchange="loadBomCost()">
                            <option value="">— 請選擇 BOM —</option>
                        </select>
                        <span id="bom-info-badge" style="font-size:11px; color:var(--muted);"></span>
                        <button class="btn btn-xs btn-default no-print" style="margin-left:4px;" title="重新計算廠內成本（公式更新後請點此）" onclick="if(currentBom){delete bomCostCache[currentBom]; loadBomCost();}"><i class="fa fa-refresh"></i></button>
                        <button id="btn-bind-order" class="btn btn-xs btn-default no-print" style="margin-left:8px; display:none;" onclick="openBomOrderBind()">
                            <i class="fa fa-link"></i> 綁定訂單 <span id="bind-order-badge" style="color:var(--primary);"></span>
                        </button>
                    </div>
                    <div id="cost-content">
                        <div class="info-box"><i class="fa fa-arrow-up"></i> 請先選擇 BOM</div>
                    </div>
                </div>

                <!-- Tab: 出貨售價記錄 -->
                <div class="tab-pane" id="tab-ship">
                    <div id="ship-summary-row" style="display:flex; gap:10px; margin-bottom:12px; flex-wrap:wrap;"></div>
                    <div id="ship-content">
                        <div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i></div>
                    </div>
                </div>

                <!-- Tab: 趨勢分析 -->
                <div class="tab-pane" id="tab-trend">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                        <label style="font-size:12px; color:var(--muted); margin:0;">顯示近</label>
                        <select id="trend-months" class="form-control" style="width:90px; height:28px; font-size:12px;" onchange="loadTrend()">
                            <option value="12">12個月</option>
                            <option value="24">24個月</option>
                            <option value="36">36個月</option>
                        </select>
                    </div>
                    <div class="chart-wrap">
                        <div class="chart-title-row">
                            <h5><i class="fa fa-line-chart"></i> 月度售價 vs 外包成本</h5>
                        </div>
                        <div style="position:relative; height:220px;"><canvas id="trend-chart"></canvas></div>
                    </div>
                    <div class="chart-wrap">
                        <div class="chart-title-row">
                            <h5><i class="fa fa-bar-chart"></i> 月度毛利率趨勢</h5>
                        </div>
                        <div style="position:relative; height:160px;"><canvas id="margin-chart"></canvas></div>
                    </div>
                </div>

                <!-- Tab: 毛利分析 -->
                <div class="tab-pane" id="tab-margin">
                    <div id="margin-content">
                        <div class="info-box"><i class="fa fa-arrow-up"></i> 請先選擇料號</div>
                    </div>
                </div>

            </div><!-- /detail-wrap -->
        </div><!-- /detail-content -->
    </div><!-- /right-panel -->
    </div><!-- /col-md-9 -->

</div><!-- /row -->

<!-- ── 出貨單-訂單綁定 Modal ── -->
<div class="modal fade" id="bindShipModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:720px; max-width:95%;" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#27ae60; color:#fff; padding:10px 15px;">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff; opacity:.8;">&times;</button>
                <h4 class="modal-title" style="font-size:14px; line-height:1.5;">
                    <i class="fa fa-truck"></i> 出貨單綁定：<span id="ship-bind-client-name"></span> <span id="ship-bind-order-no"></span>
                    <span style="font-size:12px; font-weight:normal; margin-left:6px; opacity:.9;">料號：<span id="ship-bind-part-no"></span></span>
                </h4>
                <div id="ship-bind-order-ps" style="font-size:11px; margin-top:2px; opacity:.85;"></div>
            </div>
            <div class="modal-body" style="padding:16px;">
                <div style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px; padding:6px 12px; margin-bottom:10px; font-size:12px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    訂單數量：<strong id="ship-bind-order-qty">-</strong>
                    &nbsp;已綁定出貨：<strong id="ship-bind-total-qty" style="color:gray;">0</strong> 件
                    &nbsp;<span id="ship-bind-warn" style="color:orange; font-size:11px; display:none;"></span>
                    <span style="margin-left:auto; display:flex; gap:6px;">
                        <button class="btn btn-xs btn-default" onclick="shipBindSelectAll(true)">全選</button>
                        <button class="btn btn-xs btn-default" onclick="shipBindSelectAll(false)">取消全選</button>
                    </span>
                </div>
                <div id="ship-bind-list" style="border:1px solid #dee2e6; border-radius:4px; max-height:400px; overflow-y:auto;">
                    <div style="padding:20px; text-align:center; color:#999;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>
                </div>
                <div style="margin-top:8px; font-size:11px; color:#999;"><i class="fa fa-info-circle"></i> 選取出貨單後可調整「綁定出貨量」（預設帶入出貨單全量）。</div>
            </div>
            <div class="modal-footer" style="padding:8px 15px;">
                <button class="btn btn-success" id="ship-bind-save-btn" onclick="saveShipOrderBind()"><i class="fa fa-save"></i> 儲存綁定</button>
                <button class="btn btn-default" data-dismiss="modal">取消</button>
            </div>
        </div>
    </div>
</div>

<!-- ── BOM 訂單綁定 Modal ── -->
<div class="modal fade" id="bindOrderModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:700px; max-width:95%;" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--primary); color:#fff; padding:10px 15px;">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff; opacity:.8;">&times;</button>
                <h4 class="modal-title" style="font-size:14px;"><i class="fa fa-link"></i> BOM 訂單綁定：<span id="bom-bind-title"></span></h4>
            </div>
            <div class="modal-body" style="padding:16px;">
                <div style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px; padding:6px 12px; margin-bottom:10px; font-size:12px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                    BOM總數：<strong id="bom-bind-qty">-</strong>
                    &nbsp;已分配：<strong id="bom-bind-allocated" style="color:gray;">0</strong>
                    &nbsp;<span id="bom-bind-warn" style="color:orange; font-size:11px; display:none;"></span>
                    <label style="margin:0 0 0 10px; display:flex; align-items:center; gap:5px; cursor:pointer; font-weight:normal;">
                        <input type="checkbox" id="bom-bind-stock-cb"> <span style="color:#555;">備庫（無訂單）</span>
                    </label>
                </div>
                <div id="bom-bind-order-list" style="border:1px solid #dee2e6; border-radius:4px; max-height:380px; overflow-y:auto;">
                    <div style="padding:20px; text-align:center; color:#999;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>
                </div>
                <div style="margin-top:8px; font-size:11px; color:#999;"><i class="fa fa-info-circle"></i> 顯示此料號所有訂單（含已結案），已結案訂單以灰底顯示。</div>
            </div>
            <div class="modal-footer" style="padding:8px 15px;">
                <button class="btn btn-primary" id="bom-bind-save-btn" onclick="saveBomOrderBind()"><i class="fa fa-save"></i> 儲存綁定</button>
                <button class="btn btn-default" data-dismiss="modal">取消</button>
            </div>
        </div>
    </div>
</div>

<!-- ── 刪除製程確認 Modal ── -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#d32f2f;color:#fff;padding:10px 15px;">
                <h4 class="modal-title" style="font-size:14px;"><i class="fa fa-trash"></i> 確認刪除製程</h4>
            </div>
            <div class="modal-body" style="padding:16px;">
                <p style="font-size:13px; margin-bottom:10px;">此操作<strong>無法還原</strong>！<br>請輸入大寫 <strong style="color:#d32f2f;">Y</strong> 確認：</p>
                <input type="text" id="del-confirm-input" maxlength="1"
                    autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                    style="width:70px;height:36px;font-size:22px;font-weight:700;text-align:center;border:2px solid #ccc;border-radius:4px;display:block;margin:0 auto;"
                    placeholder="Y">
                <p id="del-confirm-err" style="color:#d32f2f;font-size:12px;text-align:center;margin-top:6px;display:none;">請輸入大寫 Y</p>
            </div>
            <div class="modal-footer" style="padding:8px 15px;">
                <button class="btn btn-danger" id="del-confirm-btn">確認刪除</button>
                <button class="btn btn-default" data-dismiss="modal">取消</button>
            </div>
        </div>
    </div>
</div>

<!-- ── 系統設定 Modal ── -->
<div class="modal fade" id="settingsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:760px; max-width:95%;" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--primary);color:#fff;padding:10px 16px;">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;">&times;</button>
                <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-cog"></i> 系統設定</h4>
            </div>
            <div class="modal-body" style="padding:0;">
                <!-- Tab 按鈕列 -->
                <ul class="nav nav-tabs" style="margin:0; padding:0 16px; background:#f7f9fc; border-bottom:1px solid var(--border);">
                    <li class="active">
                        <a href="#" class="setting-tab-btn" data-stab="margin" onclick="switchSettingTab('margin');return false;">
                            <i class="fa fa-percent"></i> 利潤率設定
                        </a>
                    </li>
                    <li>
                        <a href="#" class="setting-tab-btn" data-stab="saletype" onclick="switchSettingTab('saletype');return false;">
                            <i class="fa fa-tag"></i> 出貨性質
                        </a>
                    </li>
                </ul>
                <div style="padding:16px;">
                    <!-- 利潤率設定 Tab -->
                    <div class="setting-tab-pane" id="stab-margin" style="display:none;">
                        <p style="font-size:13px; color:var(--text); margin-bottom:16px;">設定料號利潤率的分界點，用於左側篩選器色標顯示：</p>
                        <div style="display:flex; gap:32px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;">
                            <div>
                                <label style="font-size:12px; color:var(--muted); display:block; margin-bottom:4px;">低利率上限</label>
                                <div class="input-group input-group-sm" style="width:150px;">
                                    <span class="input-group-addon" style="background:#fff8e1; border-color:#ffcc80; color:#e65100;"><i class="fa fa-arrow-down"></i></span>
                                    <input type="number" id="modal-low-thr" class="form-control" value="10" min="0" max="99" step="1" style="text-align:right;">
                                    <span class="input-group-addon">%</span>
                                </div>
                                <div style="font-size:11px; color:var(--muted); margin-top:4px;">低利：0% ≤ 利潤率 &lt; 此值</div>
                            </div>
                            <div>
                                <label style="font-size:12px; color:var(--muted); display:block; margin-bottom:4px;">正常利率下限</label>
                                <div class="input-group input-group-sm" style="width:150px;">
                                    <span class="input-group-addon" style="background:#e8f5e9; border-color:#a5d6a7; color:#2e7d32;"><i class="fa fa-check-circle"></i></span>
                                    <input type="number" id="modal-ok-thr" class="form-control" value="20" min="0" max="99" step="1" style="text-align:right;">
                                    <span class="input-group-addon">%</span>
                                </div>
                                <div style="font-size:11px; color:var(--muted); margin-top:4px;">正常：利潤率 ≥ 此值</div>
                            </div>
                        </div>
                        <div style="font-size:12px; color:var(--muted); margin-bottom:14px; padding:8px 12px; background:#f7f9fc; border-radius:6px; border:1px solid var(--border);">
                            <i class="fa fa-info-circle"></i>
                            虧損（&lt;0%）／ 低利（0%~低利上限）／ 中間值（低利上限~正常下限）／ 正常（≥正常下限）
                        </div>
                        <button class="btn btn-primary btn-sm" onclick="saveMarginSettings()"><i class="fa fa-save"></i> 儲存利潤率設定</button>
                        <span id="margin-save-msg" style="font-size:12px; margin-left:10px; display:none;"></span>
                    </div>
                </div>
            </div>
                    <!-- 出貨性質設定 Tab -->
                    <div class="setting-tab-pane" id="stab-saletype" style="display:none;">
                        <p style="font-size:12px;color:var(--muted);margin-bottom:12px;">設定各出貨性質是否列入<strong>平均出貨售價</strong>計算（勾選＝列入）。修改後影響全系統的售價統計。</p>
                        <div id="saletype-setting-list">
                            <div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i></div>
                        </div>
                        <div style="margin-top:12px;">
                            <button class="btn btn-primary btn-sm" onclick="saveSaleTypeSettings()"><i class="fa fa-save"></i> 儲存設定</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding:8px 15px;">
                <a href="kpi_main.php" target="_blank" class="btn btn-default btn-sm"><i class="fa fa-external-link"></i> 進階設定（KPI管理）</a>
                <button class="btn btn-default" data-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>

<!-- ── 圖面檢視 Modal ── -->
<div class="modal fade" id="drawingModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:90%; max-width:1100px;" role="document">
        <div class="modal-content">
            <div class="modal-header" style="padding:8px 15px; cursor:move; user-select:none;" id="dm-modal-header">
                <button type="button" class="close" data-dismiss="modal" style="cursor:pointer;">&times;</button>
                <h4 class="modal-title" style="font-size:14px; display:inline-block; margin-right:10px;">
                    <i class="fa fa-file-image-o"></i> 料號圖面：<span id="dm-part-title"></span>
                </h4>
                <button type="button" class="btn btn-xs btn-default" id="dm-new-window-btn" style="vertical-align:middle;" title="在新視窗開啟目前檔案">
                    <i class="fa fa-external-link"></i> 新視窗
                </button>
            </div>
            <div class="modal-body" style="padding:0; height:70vh;">
                <div class="row" style="margin:0; height:100%;">
                    <div class="col-md-3" style="border-right:1px solid #eee; padding:10px; overflow-y:auto; height:100%;">
                        <div id="dm-file-list"><div class="text-muted text-center" style="padding:20px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div></div>
                    </div>
                    <div class="col-md-9" style="padding:0; height:100%; overflow:auto; display:flex; align-items:center; justify-content:center; background:#f8f8f8; position:relative;" id="dm-viewer">
                        <div class="text-muted text-center"><i class="fa fa-file-o fa-3x"></i><br>請從左側選擇檔案</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── 客戶年度趨勢 Modal ── -->
<div class="modal fade" id="clientTrendModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:820px; max-width:95%;" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--primary);color:#fff;padding:10px 16px;">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;">&times;</button>
                <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-line-chart"></i> 年度趨勢：<span id="ct-client-name"></span></h4>
            </div>
            <div class="modal-body" style="padding:16px;">
                <div style="position:relative; height:220px; margin-bottom:16px;"><canvas id="ct-chart"></canvas></div>
                <div id="ct-table-content"></div>
            </div>
            <div class="modal-footer" style="padding:8px 15px;">
                <button class="btn btn-default" data-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="calcDebugModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:520px; max-width:96%;" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--primary);color:#fff;padding:10px 16px;">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;">&times;</button>
                <h4 class="modal-title" id="calc-debug-title" style="font-size:14px;"><i class="fa fa-calculator"></i> 廠內計算公式</h4>
            </div>
            <div class="modal-body" id="calc-debug-body" style="padding:16px; font-size:13px;"></div>
            <div class="modal-footer" style="padding:8px 15px;">
                <button class="btn btn-default" data-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>

</div><!-- /right_col -->
</div><!-- /main_container -->
</div><!-- /container body -->

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<!-- Chart.js 必須在 custom.min.js 之後載入，避免舊版覆蓋 -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
if (typeof Chart !== 'undefined') {
    if (!Chart.defaults.global) Chart.defaults.global = {};
    if (!Chart.defaults.global.legend) Chart.defaults.global.legend = {};
}
</script>
<script>
var SELF = 'ERP_Cost_Analysis-NEW.php';
var currentPart = null;
var currentBom  = null;
var currentBomProcs = [];
var currentMarginFilter = '';
var listPage = 1;
var listTotal = 0;
var trendChartObj = null;
var marginChartObj = null;
var searchTimer = null;
var partDataCache = {}; // part_no => row data
var bomCostCache     = {}; // bom_no  => AJAX result (避免重複查詢)
var bomUnitCostCache = {}; // bom_no  => 單件全成本（含KPI），持久保存供毛利分析使用
var clientSuggestions  = null; // null=未載入；[] = 已載入（懶載入）
var partListToken = 0;          // request token，丟棄過期的 AJAX 回應
var marginLowPct  = parseFloat(localStorage.getItem('ca_low_pct') || '10');
var marginOkPct   = parseFloat(localStorage.getItem('ca_ok_pct')  || '20');
var saleTypesList = []; // 出貨性質清單（頁面載入後初始化）

// ── 工具函式 ──────────────────────────────────────────────
function ajax(data, cb) {
    data.action = data.action;
    $.post(SELF, data, function(res) { cb(res); }, 'json').fail(function() {
        cb({ success: false, message: '連線失敗' });
    });
}
function fmt(v, dec) {
    if (v === null || v === '' || v === undefined) return '–';
    var n = parseFloat(v);
    if (isNaN(n)) return '–';
    if (dec !== undefined) {
        return n.toLocaleString('zh-TW', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }
    // 自動省略尾部零 (最多2位小數)
    return n.toLocaleString('zh-TW', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}
function fmtDate(s) { return s ? s.substring(0,10) : '–'; }
function marginClass(pct) {
    if (pct === null || pct === undefined) return 'nodata';
    pct = parseFloat(pct);
    if (pct < 0) return 'loss';
    if (pct < marginLowPct) return 'low';
    if (pct < marginOkPct) return 'mid';
    return 'ok';
}
function marginBadge(pct) {
    if (pct === null || pct === undefined) return '<span style="color:var(--muted);font-size:11px;">無資料</span>';
    pct = parseFloat(pct);
    var cls = pct < 0 ? 'danger' : pct < marginLowPct ? 'warning' : pct < marginOkPct ? 'default' : 'success';
    return '<span class="label label-' + cls + '">' + pct.toFixed(1) + '%</span>';
}
function updateThreshold() {
    var lo = parseFloat($('#low-thr').val()) || 0;
    var ok = parseFloat($('#ok-thr').val())  || 0;
    lo = Math.min(Math.max(lo, 0), 99);
    ok = Math.min(Math.max(ok, 0), 99);
    marginLowPct = lo;
    marginOkPct  = ok;
    localStorage.setItem('ca_low_pct', lo);
    localStorage.setItem('ca_ok_pct',  ok);
    // 存入 DB（節流：300ms）
    clearTimeout(updateThreshold._t);
    updateThreshold._t = setTimeout(function() {
        $.post(SELF, { action: 'save_margin_settings', low: lo, ok: ok });
    }, 300);
    // 無論是否有篩選，一律從伺服器重新載入（閾值改變影響所有色標與篩選結果）
    loadPartList(listPage);
}
function doSearch() {
    if (!validateDateRange()) return;
    if ($('#client-analysis-panel').is(':visible')) {
        clientPage = 1;
        renderCustomerAnalysis(clientAnalysisData);
    } else {
        loadPartList(1);
    }
}
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(doSearch, 450);
}

// ── 料號搜尋框輸入（僅搜料號/品名）──────────────────────
function onKwInput() {
    debounceSearch();
}

// ── 客戶篩選自動完成 ──────────────────────────────────────
function loadClientSuggestions(cb) {
    if (clientSuggestions !== null) { cb(); return; }
    clientSuggestions = [];
    ajax({ action: 'get_client_list' }, function(res) {
        clientSuggestions = (res && res.success && res.data) ? res.data : [];
        cb();
    });
}
function onClientInput() {
    clearTimeout(searchTimer);
    showClientDropdown();
    searchTimer = setTimeout(doSearch, 450);
}
function showClientDropdown() {
    var kw = ($('#tb-client').val() || '').trim().toLowerCase();
    if (!kw) { hideClientDropdown(); return; }
    loadClientSuggestions(function() {
        var matches = clientSuggestions.filter(function(c) {
            return c.client_name.toLowerCase().indexOf(kw) >= 0 ||
                   (c.client_id && c.client_id.toLowerCase().indexOf(kw) >= 0);
        }).slice(0, 10);
        if (!matches.length) { hideClientDropdown(); return; }
        var html = '<div class="kw-suggest-hdr"><i class="fa fa-user"></i> 客戶建議</div>';
        html += matches.map(function(c) {
            return '<div class="kw-suggest-item" onmousedown="selectClientFilter(' + JSON.stringify(c.client_name).replace(/"/g,'&quot;') + ')">' +
                   esc(c.client_name) +
                   (c.client_id ? '<span class="kw-suggest-id">(' + esc(c.client_id) + ')</span>' : '') +
                   '</div>';
        }).join('');
        $('#client-dropdown').html(html).show();
    });
}
function hideClientDropdown() { $('#client-dropdown').hide(); }
function selectClientFilter(clientName) {
    $('#tb-client').val(clientName);
    hideClientDropdown();
    clearTimeout(searchTimer);
    if ($('#client-analysis-panel').is(':visible')) {
        clientPage = 1;
        renderCustomerAnalysis(clientAnalysisData);
    } else {
        loadPartList(1);
    }
}

function clearFilters() {
    $('#tb-keyword').val('');
    $('#tb-client').val('');
    hideClientDropdown();
    $('#tb-date-from').val('');
    $('#tb-date-to').val('');
    $('.date-preset').removeClass('active');
    currentMarginFilter = '';
    $('.filter-badge').removeClass('active');
    // 清除中間料號詳情
    currentPart = null;
    currentBom  = null;
    $('#detail-content').hide();
    $('#detail-empty').show();
    $('.part-item').removeClass('active');
    if ($('#client-analysis-panel').is(':visible')) {
        clientPage = 1;
        renderCustomerAnalysis(clientAnalysisData);
    } else {
        loadPartList(1);
    }
}
function setDatePreset(months) {
    if (months === 0) {
        $('#tb-date-from').val('');
        $('#tb-date-to').val('');
    } else {
        var now = new Date();
        var from = new Date(now);
        from.setMonth(from.getMonth() - months);
        $('#tb-date-from').val(from.toISOString().slice(0, 10));
        $('#tb-date-to').val(now.toISOString().slice(0, 10));
    }
    $('.date-preset').removeClass('active');
    $('.date-preset[data-m="' + months + '"]').addClass('active');
    loadPartList(1);
    // 已開啟料號：同步更新日期相關分頁資料
    if (currentPart) {
        loadShipments(currentPart.part_no);
        if ($('#tab-margin').hasClass('active')) loadMarginAnalysis(currentPart.part_no);
    }
}
function validateDateRange() {
    var from = $('#tb-date-from').val();
    var to   = $('#tb-date-to').val();
    if (!from || !to) return true;
    var diff = (new Date(to) - new Date(from)) / 86400000;
    if (diff < 0) { alert('起始日期不得大於結束日期'); return false; }
    if (diff > 366) { alert('日期區間最多 1 年，請重新選擇'); return false; }
    return true;
}
function toggleMarginFilter(type, el) {
    if (currentMarginFilter === type) {
        currentMarginFilter = '';
        $(el).removeClass('active');
    } else {
        currentMarginFilter = type;
        $('.filter-badge').removeClass('active');
        $(el).addClass('active');
    }
    if ($('#client-analysis-panel').is(':visible')) {
        clientPage = 1;
        renderCustomerAnalysis(clientAnalysisData);
    } else {
        loadPartList(1);
    }
}

// ── 料號清單 ──────────────────────────────────────────────
function loadPartList(page) {
    listPage = page || 1;
    var myToken = ++partListToken; // 每次遞增，回調時比對是否仍是最新請求
    $('#part-list').html('<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i></div>');
    ajax({
        action: 'get_part_list',
        page: listPage,
        per_page: $('#pp-sel').val(),
        keyword: $('#tb-keyword').val(),
        client: $('#tb-client').val(),
        filter_margin: currentMarginFilter,
        low_threshold: marginLowPct,
        ok_threshold:  marginOkPct,
        date_from: $('#tb-date-from').val(),
        date_to:   $('#tb-date-to').val()
    }, function(res) {
        if (myToken !== partListToken) return; // 已有更新的請求，丟棄此回應
        if (!res.success) { $('#part-list').html('<div class="info-box text-danger">' + res.message + '</div>'); return; }
        listTotal = res.total;
        renderPartList(res.data);
        renderPagination(res.total, res.per_page, listPage);
        $('#list-stat').text('共 ' + res.total + ' 筆');
    });
}

function renderPartList(rows) {
    if (!rows.length) {
        $('#part-list').html('<div class="info-box">無符合條件的料號</div>');
        return;
    }
    // 存入快取，避免 inline onclick 傳遞特殊字元問題
    rows.forEach(function(r) { partDataCache[r.part_no] = r; });
    // 若已開啟某料號，且此批次含該料號，以新日期範圍統計更新摘要卡片
    if (currentPart && partDataCache[currentPart.part_no]) {
        updateSummaryCards(partDataCache[currentPart.part_no]);
    }

    var html = '';
    rows.forEach(function(r) {
        var isActive = currentPart && currentPart.part_no === r.part_no ? ' active' : '';
        var priceStr;
        if (r.avg_sell_price > 0) {
            var orderTag = r.sell_price_from_order ? ' <span style="font-size:9px;color:#e67e22;font-weight:600;">(訂)</span>' : '';
            priceStr = 'NT$' + fmt(r.avg_sell_price) + orderTag;
        } else {
            priceStr = '無售價';
        }
        var esc = r.part_no.replace(/'/g, "\\'");
        html += '<div class="part-item' + isActive + '" data-part="' + r.part_no + '" onclick="selectPart(\'' + esc + '\')">';
        html += '  <div class="margin-dot nodata" title="計算中…"></div>';
        html += '  <div class="pi-meta">';
        html += '    <div class="pi-no">' + r.part_no + '</div>';
        var showDrawing = r.Drawing_No && r.Drawing_No !== r.part_no;
        if (showDrawing || r.Spec_No) {
            var meta = [];
            if (showDrawing) meta.push(r.Drawing_No);
            if (r.Spec_No)   meta.push(r.Spec_No);
            html += '    <div class="pi-name">' + meta.join(' / ') + '</div>';
        }
        if (r.gear_spec_str) {
            html += '    <div style="font-size:10px;color:var(--info);"><i class="fa fa-cog"></i> ' + r.gear_spec_str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
        }
        html += '    <div class="pi-client">' + (r.client_name || '<span style="color:#ccc;">無客戶</span>') + '</div>';
        html += '  </div>';
        html += '  <div style="text-align:right; flex-shrink:0; font-size:11px;">';
        html += '    <div style="color:var(--muted);font-size:10px;" class="margin-loading-pct">…</div>';
        html += '    <div style="color:var(--muted);">' + priceStr + '</div>';
        html += '  </div>';
        html += '</div>';
    });
    $('#part-list').html(html);

    // 一次批次請求取得所有料號的正確毛利率（與詳細頁邏輯一致）
    var allPartNos = rows.map(function(r) { return r.part_no; });
    ajax({ action: 'get_margins_batch', part_nos: JSON.stringify(allPartNos) }, function(res) {
        if (!res.success || !res.data) return;
        Object.keys(res.data).forEach(function(pn) {
            var entry = res.data[pn];
            if (!entry) return;
            var mp   = (entry.margin_pct !== null && entry.margin_pct !== undefined) ? parseFloat(entry.margin_pct) : null;
            var miss = !!entry.has_missing_cost;
            if (partDataCache[pn]) {
                if (mp !== null) partDataCache[pn].margin_pct = mp;
                partDataCache[pn].has_missing_cost = miss;
            }
            _updateListItemMargin(pn, mp, miss);
        });
    });
}

function renderPagination(total, pp, page) {
    var pages = Math.ceil(total / pp);
    if (pages <= 1) { $('#pg-mini').html(''); return; }
    var html = '<button class="btn btn-xs btn-default" onclick="loadPartList(' + Math.max(1,page-1) + ')" ' + (page<=1?'disabled':'') + '><i class="fa fa-chevron-left"></i></button>';
    html += '<span style="color:var(--muted);">' + page + ' / ' + pages + '</span>';
    html += '<button class="btn btn-xs btn-default" onclick="loadPartList(' + Math.min(pages,page+1) + ')" ' + (page>=pages?'disabled':'') + '><i class="fa fa-chevron-right"></i></button>';
    $('#pg-mini').html(html);
}

// ── 選擇料號 ──────────────────────────────────────────────
function selectPart(partNo) {
    var partData = partDataCache[partNo];
    if (!partData) return;
    currentPart = partData;
    currentBom  = null;
    // 更新清單高亮
    $('.part-item').removeClass('active');
    $('.part-item[data-part="' + partData.part_no + '"]').addClass('active');
    // 顯示詳情區
    $('#detail-empty').hide();
    $('#detail-content').show();
    // 填入標題（圖號不重複顯示料號）
    $('#dc-part-no').text(partData.part_no);
    // 顯示齒輪編輯按鈕（有 d_id 才顯示）
    if (partData.d_id) {
        $('#btn-open-part-edit').data('did', partData.d_id).show();
    } else {
        $('#btn-open-part-edit').hide();
    }

    var drawingParts = [];
    if (partData.Drawing_No && partData.Drawing_No !== partData.part_no) drawingParts.push(partData.Drawing_No);
    if (partData.client_name) drawingParts.push(partData.client_name);
    $('#dc-drawing-no').text(drawingParts.join('  |  '));
    $('#dc-spec-no').text(partData.Spec_No || '');
    $('#dc-gear-spec').hide();
    // 摘要卡片
    updateSummaryCards(partData);
    // 換料號：清除BOM快取，避免 switchTab 重抓舊料號的BOM
    bomCostCache = {};
    currentBom = null;
    // 載入 BOM 清單
    loadBomList(partData.part_no);
    // 載入出貨記錄
    loadShipments(partData.part_no);
    // 背景預載毛利分析以更新摘要卡片毛利率（不切換分頁）
    loadMarginSummary(partData.part_no);
    // 切到 cost tab（此時 currentBom=null，不觸發 loadBomCost，由 loadBomList 負責）
    switchTab('cost');
}

function updateSummaryCards(r) {
    var cost  = parseFloat(r.latest_unit_cost) || 0;
    var price = parseFloat(r.avg_sell_price)   || 0;
    var pct   = r.margin_pct !== null ? parseFloat(r.margin_pct) : null;
    var costIncomplete = !!(r.has_missing_cost || r.cost_incomplete);

    var costHtml = cost > 0
        ? 'NT$' + fmt(cost) + (costIncomplete ? ' <span title="部分製程成本無法取得（KPI未設定），成本可能低估" style="color:#e67e22;cursor:help;">⚠</span>' : '')
        : '<span style="color:var(--muted);">待計算</span>';
    $('#sc-cost').html(costHtml);
    $('#sc-cost-sub').text(r.latest_bom ? 'BOM: ' + r.latest_bom : '尚無BOM資料');
    var priceHtml = price > 0
        ? 'NT$' + fmt(price) + (r.sell_price_from_order ? ' <span style="font-size:10px;color:#e67e22;">(訂單)</span>' : '')
        : '<span style="color:var(--muted);">無記錄</span>';
    $('#sc-price').html(priceHtml);
    var priceSubText = r.sell_price_from_order ? '訂單平均單價' : (r.last_ship_date ? '最近: ' + fmtDate(r.last_ship_date) : '尚無出貨');
    $('#sc-price-sub').text(priceSubText);
    $('#sc-ship-count').text(r.ship_count || '0');
    $('#sc-ship-sub').text('筆出貨記錄');
    $('#sc-bom-count').text(r.bom_count || '0');
    $('#sc-bom-sub').text('個生產製令');

    if (pct !== null) {
        var cls = pct < 0 ? 'sc-danger' : pct < marginOkPct ? 'sc-warn' : 'sc-success';
        var color = pct < 0 ? 'var(--danger)' : pct < marginOkPct ? 'var(--warning)' : 'var(--success)';
        var label = pct < 0 ? '虧損！' : pct < marginLowPct ? '低利' : pct < marginOkPct ? '中間值' : '正常';
        $('#sc-margin-card').attr('class', 's-card ' + cls);
        $('#sc-margin').html('<span style="color:' + color + ';">' + pct.toFixed(1) + '%</span>');
        $('#sc-margin-sub').text(label);
    } else {
        $('#sc-margin-card').attr('class', 's-card');
        $('#sc-margin').html('<span style="color:var(--muted);">–</span>');
        var _noMarginReason = !cost ? '尚無成本計算' : !price ? '尚無出貨售價' : '成本或售價缺一';
        $('#sc-margin-sub').html('<span title="此為整體平均售價vs成本比較，需有出貨紀錄(is_list)及BOM成本才能計算" style="cursor:help;">' + _noMarginReason + ' <i class=\'fa fa-question-circle\' style=\'opacity:.5\'></i></span>');
    }
}

// ── BOM 清單 & 成本 ───────────────────────────────────────
function loadBomList(partNo) {
    $('#bom-select').html('<option value="">載入中…</option>');
    $('#cost-content').html('<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i></div>');
    ajax({ action: 'get_bom_list', part_no: partNo }, function(res) {
        if (!res.success || !res.data.length) {
            $('#bom-select').html('<option value="">— 無 BOM 資料 —</option>');
            $('#cost-content').html('<div class="info-box">此料號尚無生產製令(BOM)</div>');
            return;
        }
        var opts = '<option value="">— 請選擇 BOM —</option>';
        res.data.forEach(function(b) {
            var label = b.bom + '  (批量:' + b.bom_qty + '件';
            if (b.last_date) label += ', 最近加工:' + fmtDate(b.last_date);
            label += ')';
            // 結案標記
            if (b.bom_closed === '1') label += '  [已結案]';
            // 綁定訂單
            if (b.bound_orders) {
                var oos = b.bound_orders.split(',');
                if (oos.length <= 3) {
                    label += '  ✓ ' + oos.join(', ');
                } else {
                    label += '  ✓ ' + oos.slice(0, 2).join(', ') + ' …共' + oos.length + '筆';
                }
            } else {
                label += '  — 未綁訂單';
            }
            opts += '<option value="' + b.bom + '">' + label + '</option>';
        });
        $('#bom-select').html(opts);
        // 預設選第一個
        if (res.data[0]) {
            $('#bom-select').val(res.data[0].bom);
            loadBomCost();
        }
    });
}

function loadBomCost() {
    var bom = $('#bom-select').val();
    if (!bom) {
        $('#cost-content').html('<div class="info-box">請選擇 BOM</div>');
        $('#btn-bind-order').hide();
        return;
    }
    currentBom = bom;
    $('#btn-bind-order').show();
    refreshBindBadge(bom);
    if (bomCostCache[bom]) { renderBomCost(bomCostCache[bom]); return; }
    $('#cost-content').html('<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i></div>');
    ajax({ action: 'get_bom_cost', bom: bom, part_no: currentPart ? currentPart.part_no : '' }, function(res) {
        if (!res.success) { $('#cost-content').html('<div class="info-box text-danger">' + res.message + '</div>'); return; }
        bomCostCache[bom] = res;
        // 儲存全成本供毛利分析使用（持久快取）
        if (res.total_cost > 0) bomUnitCostCache[bom] = parseFloat(res.total_cost);
        renderBomCost(res);
    });
}

function renderBomCost(res) {
    var info = res.bom_info || {};
    var procs = res.processes || [];
    currentBomProcs = procs;
    var totalCost = parseFloat(res.total_cost) || 0;
    var totalExt  = parseFloat(res.total_ext) || 0;
    var totalKpi  = parseFloat(res.total_kpi) || 0;
    var sellPrice = currentPart ? parseFloat(currentPart.avg_sell_price) || 0 : 0;

    // 更新齒輪規格區塊
    if (res.gear_info && res.gear_info.gear_spec_str) {
        $('#dc-gear-text').text(res.gear_info.gear_spec_str);
        $('#dc-gear-spec').show();
    } else {
        $('#dc-gear-spec').hide();
    }

    var html = '';
    // BOM 資訊條
    html += '<div style="font-size:11px; color:var(--muted); margin-bottom:8px; padding:6px 10px; background:#f7f9fc; border-radius:6px; border:1px solid var(--border);">';
    html += '<i class="fa fa-info-circle"></i> BOM: <strong>' + (info.bom||'–') + '</strong>';
    html += ' &nbsp;|&nbsp; 批量: <strong>' + (info.bom_qty||'–') + ' 件</strong>';
    if (info.Client_Name) html += ' &nbsp;|&nbsp; 客戶: <strong>' + info.Client_Name + '</strong>';
    html += '</div>';

    if (!procs.length) {
        html += '<div class="info-box">此 BOM 無製程資料</div>';
        $('#cost-content').html(html);
        return;
    }

    // 成本未齊警告
    var hasMissingCost = !!res.has_missing_cost;
    if (hasMissingCost) {
        html += '<div style="margin-bottom:8px;font-size:12px;padding:8px 12px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;color:#5c3a00;font-weight:500;">';
        html += '<i class="fa fa-exclamation-triangle" style="color:#e67e22;margin-right:5px;"></i><strong style="color:#5c3a00;">成本單價設定未齊全</strong>：部分製程（外包或廠內）無單價資料，合計成本偏低，毛利率<strong style="color:#c0392b;">不可信</strong>，請先補充各製程加工單價。';
        html += '</div>';
    }

    // 異常計數提示（只計建議刪除的筆數，不含保留的最佳那筆）
    var anomalyCount = procs.filter(function(p) { return p.suggest_delete || p.is_bad_state; }).length;
    if (anomalyCount > 0) {
        html += '<div class="alert alert-danger alert-sm" style="margin-bottom:8px; font-size:12px; padding:7px 12px;">';
        html += '<i class="fa fa-exclamation-triangle"></i> 偵測到 <strong>' + anomalyCount + '</strong> 筆建議刪除的製程（粉紅色列），請確認後刪除';
        html += '</div>';
    }

    html += '<div class="' + (procs.length > 6 ? 'cost-table-scroll' : '') + '" style="' + (procs.length <= 6 ? 'overflow-x:auto;' : '') + '">';
    html += '<table class="cost-table">';
    html += '<thead><tr>';
    html += '<th style="white-space:nowrap;">序 / 狀態</th>';
    html += '<th>製程名稱</th>';
    html += '<th style="white-space:nowrap;">類別</th>';
    html += '<th style="white-space:nowrap;">廠商</th>';
    html += '<th style="white-space:nowrap;">性質</th>';
    html += '<th style="white-space:nowrap; text-align:right;">單位成本</th>';
    html += '<th style="white-space:nowrap; text-align:right;">最低/最高</th>';
    html += '<th style="white-space:nowrap;">最近日</th>';
    html += '<th style="white-space:nowrap;">來源</th>';
    html += '<th style="white-space:nowrap;">加工單價</th>';
    html += '<th style="white-space:nowrap;">操作</th>';
    html += '</tr></thead><tbody>';

    var stateColorMap  = { 'N':'#aaa', 'P':'#3498db', 'Q':'#e67e22', 'ing':'#27ae60', 'E':'#9b59b6' };
    var stateNameMap   = { 'N':'新建製程', 'P':'生管待移轉', 'Q':'QC待驗', 'ing':'已發加工中', 'E':'生管已移轉' };
    var stateShortMap  = { 'N':'新建', 'P':'待移轉', 'Q':'QC驗', 'ing':'加工中', 'E':'已移轉' };

    // ── 智慧判斷目前製程（仿 OreadyReply 邏輯）──────────────────
    var bomClosed = (info.bom_ps === '1' || info.bom_manual_close === '1');
    // 優先找 ing/Q（最活躍），再找 E/P（已移轉待處理），bom_sn 最大者為目前製程
    var currentBomSn = null;
    if (!bomClosed) {
        var ingQ = procs.filter(function(p) { return p.processing_state === 'ing' || p.processing_state === 'Q'; });
        if (ingQ.length > 0) {
            currentBomSn = Math.max.apply(null, ingQ.map(function(p) { return parseInt(p.bom_sn) || 0; }));
        } else {
            var ep = procs.filter(function(p) { return p.processing_state === 'E' || p.processing_state === 'P'; });
            if (ep.length > 0) {
                currentBomSn = Math.max.apply(null, ep.map(function(p) { return parseInt(p.bom_sn) || 0; }));
            }
        }
    }

    procs.forEach(function(p, i) {
        var srcBadge = '';
        if (p.cost_source === 'manual')              srcBadge = '<span class="badge-manual">手動單價</span>';
        else if (p.cost_source === 'external')       srcBadge = '<span class="badge-ext">外包實績</span>';
        else if (p.cost_source === 'kpi') {
            var modeText = p.calc_mode === 'fixed' ? '固定金額' : p.calc_mode === 'formula' ? '自訂公式' : p.calc_mode === 'time_cache' ? '實際工時(優先)' : '工時計費';
            srcBadge = '<span class="badge-kpi clickable" data-idx="' + i + '" title="點擊查看計算公式">廠內自動計算</span><br><small style="color:var(--muted);">' + modeText + '</small>';
        }
        else if (p.cost_source === 'inhouse_no_data') srcBadge = '<span class="badge-nodata">廠內(無標準)</span>';
        else srcBadge = '<span class="badge-nodata">無資料</span>';

        var isAnomaly = p.suggest_delete || p.is_bad_state;
        var trClass   = isAnomaly ? ' class="bom-row-anomaly"' : '';

        var internalStr = parseInt(p.maker_internal) === 1 ? '<span style="color:var(--info);">廠內</span>' : '<span style="color:var(--muted);">外包</span>';
        var costStr = p.cost_per_pc > 0 ? 'NT$' + fmt(p.cost_per_pc) : '–';
        var minStr  = p.min_price ? 'NT$' + fmt(p.min_price) : '–';
        var maxStr  = p.max_price ? 'NT$' + fmt(p.max_price) : '–';

        // 轉帳紀錄指示器：有紀錄顯示勾號，無則不顯示
        var logBadge = p.has_log_data
            ? ' <i class="fa fa-check-circle" style="color:var(--success);font-size:11px;" title="已有轉帳紀錄"></i>'
            : '';

        // processing_state badge（智慧判斷：目前製程顯示實際狀態，之前製程顯示已完工）
        var pSn = parseInt(p.bom_sn) || 0;
        var isCurrentProc  = (currentBomSn !== null && pSn === currentBomSn);
        var isBeforeCurrent = (currentBomSn !== null && pSn < currentBomSn);
        var stateBadge;
        var _badgeBase = 'display:inline-block;padding:0 4px;border-radius:2px;font-size:9px;line-height:15px;color:#fff;';
        if (bomClosed) {
            stateBadge = '<span style="' + _badgeBase + 'background:#7f8c8d;" title="已結案">已結案</span>';
        } else if (isBeforeCurrent) {
            stateBadge = '<span style="' + _badgeBase + 'background:#7f8c8d;" title="製程已完工">已完工</span>';
        } else {
            var sc        = stateColorMap[p.processing_state] || '#e74c3c';
            var stateName  = stateNameMap[p.processing_state]  || (p.processing_state || '?');
            var stateShort = stateShortMap[p.processing_state] || (p.processing_state || '?');
            var prefix    = isCurrentProc ? '▶ ' : '';
            var border    = isCurrentProc ? 'outline:1px solid rgba(0,0,0,.25);' : '';
            stateBadge = '<span style="' + _badgeBase + 'background:' + sc + ';' + border + '" title="' + (isCurrentProc ? '目前製程：' : '') + stateName + '">' + prefix + stateShort + '</span>';
            if (p.is_bad_state) stateBadge += ' <span class="badge-anomaly" style="font-size:9px;">異常</span>';
        }

        // 序號欄（bom_sn）+ 重複警告
        var snCell = (p.bom_sn !== undefined ? p.bom_sn : i+1) + '';
        if (p.suggest_delete) snCell += '<br><span class="badge-dup">重複</span>';

        var fid = p.bom_ing_fid || '';

        html += '<tr' + trClass + '>';
        html += '<td style="text-align:center; white-space:nowrap; vertical-align:top; font-size:11px;">' + snCell + '<br>' + stateBadge + '</td>';
        html += '<td style="vertical-align:top;"><span style="font-size:10px; color:var(--muted); margin-right:4px;">' + (p.process_no||'') + '</span><strong>' + (p.ProcessName||'–') + '</strong>' + logBadge + '</td>';
        html += '<td style="font-size:11px; color:var(--muted); white-space:nowrap; vertical-align:top;">' + (p.process_type||'–') + '</td>';
        html += '<td style="font-size:11px; white-space:nowrap; vertical-align:top;">' + (p.maker_id||'–') + '</td>';
        html += '<td style="white-space:nowrap; vertical-align:top;">' + internalStr + '</td>';
        var costTdExtra = p.cost_source === 'kpi' ? ' class="td-kpi-cost"' : (p.cost_source === 'manual' ? ' class="td-manual-cost"' : '');
        html += '<td' + costTdExtra + ' style="text-align:right; font-weight:600; white-space:nowrap; vertical-align:top;">' + costStr + '</td>';
        html += '<td style="text-align:right; color:var(--muted); font-size:11px; white-space:nowrap; vertical-align:top;">' + minStr + '<br>' + maxStr + '</td>';
        html += '<td style="font-size:11px; color:var(--muted); white-space:nowrap; vertical-align:top;">' + fmtDate(p.last_date) + '</td>';
        html += '<td style="vertical-align:top;">' + srcBadge + '</td>';
        var hasManual = p.cost_source === 'manual';
        html += '<td style="vertical-align:top; white-space:nowrap;">';
        if (hasManual) html += '<div style="font-size:9px;color:#e65100;margin-bottom:2px;"><i class="fa fa-exclamation-circle"></i> 已有手動單價</div>';
        html += '<input type="number" class="price-inp' + (hasManual ? ' price-inp-has-data' : '') + '" data-fid="' + fid + '" placeholder="' + (hasManual ? '重新輸入覆蓋' : '單價') + '" min="0" step="0.01">';
        html += '<br><input type="text" class="note-inp" data-fid="' + fid + '" placeholder="備註" style="margin-top:2px;">';
        html += '</td>';
        html += '<td style="vertical-align:top; white-space:nowrap;">';
        html += '<button class="btn btn-xs btn-success btn-save-log" data-fid="' + fid + '" data-bom="' + (info.bom||'') + '" data-sn="' + (p.bom_sn||'') + '" data-qty="' + (info.bom_qty||0) + '" data-has-log="' + (p.has_log_data ? 1 : 0) + '" data-maker="' + (p.maker_id||'') + '" title="儲存單價"><i class="fa fa-save"></i></button>';
        html += ' <button class="btn btn-xs ' + (isAnomaly ? 'btn-warning' : 'btn-default') + ' btn-edit-toggle" title="展開/收合編輯"><i class="fa fa-pencil"></i></button>';
        html += ' <button class="btn btn-xs btn-danger btn-del-ing"' + (isAnomaly ? '' : ' style="display:none;"') + ' data-fid="' + fid + '" title="刪除此製程"><i class="fa fa-trash"></i></button>';
        html += '</td>';
        html += '</tr>';
    });

    // 合計列（移除 FID 欄後共 11 欄，成本欄前有 5 欄）
    html += '<tr class="total-row">';
    html += '<td colspan="5" style="text-align:right; color:var(--muted);">合計單位成本</td>';
    html += '<td style="text-align:right; font-size:15px;">NT$' + fmt(totalCost) + '</td>';
    html += '<td colspan="5" style="font-size:11px; color:var(--muted);">';
    if (totalExt > 0) html += '外包: NT$' + fmt(totalExt);
    if (totalKpi > 0) html += (totalExt > 0 ? ' + ' : '') + '廠內自動計算: NT$' + fmt(totalKpi);
    html += '</td></tr>';
    html += '</tbody></table></div>';

    // 更新摘要卡片的估計單位成本
    if (totalCost > 0) {
        $('#sc-cost').html('NT$' + fmt(totalCost));
        $('#sc-cost-sub').text('BOM: ' + (info.bom || '–'));
    }

    // 與售價比較
    if (hasMissingCost) {
        html += '<div style="margin-top:12px;font-size:12px;padding:8px 14px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;color:#5c3a00;font-weight:500;">';
        html += '<i class="fa fa-exclamation-triangle" style="color:#e67e22;margin-right:5px;"></i>成本單價未齊全，無法計算可信毛利率。請補齊所有製程加工單價後重新查詢。';
        html += '</div>';
    } else if (sellPrice > 0 && totalCost > 0) {
        var margin = (sellPrice - totalCost) / sellPrice * 100;
        var cls = margin < 0 ? 'danger' : margin < marginOkPct ? 'warning' : 'success';
        var marginLabel = margin < 0 ? '⚠ 虧損' : margin < marginLowPct ? '低利' : margin < marginOkPct ? '中間值' : '';
        html += '<div class="alert alert-' + cls + ' alert-sm" style="margin-top:12px; font-size:12px; padding:8px 14px;">';
        html += '<i class="fa fa-calculator"></i> ';
        html += '以此 BOM 成本 (NT$' + fmt(totalCost) + ') 對比平均售價 (NT$' + fmt(sellPrice) + ')：';
        html += '<strong style="margin-left:6px;">毛利率 ' + margin.toFixed(1) + '%</strong>';
        if (marginLabel) html += ' <span class="' + (margin < 0 ? 'text-danger' : 'text-warning') + '">' + marginLabel + '</span>';
        html += '</div>';
    }

    // 注意事項
    var hasNoData = procs.some(function(p) { return p.cost_source === 'no_data' || p.cost_source === 'inhouse_no_data'; });
    if (hasNoData) {
        html += '<div style="font-size:11px; color:var(--muted); margin-top:8px;">';
        html += '<i class="fa fa-exclamation-triangle" style="color:var(--warning);"></i> 部分製程無成本資料（外包尚無轉帳紀錄，廠內尚未設定KPI標準），實際成本可能偏低。';
        html += '</div>';
    }

    $('#cost-content').html(html);
    if (totalCost > 0) {
        var isGear = res.gear_info && res.gear_info.gear_spec_str;
        var formulaHint = totalKpi > 0
            ? ' <span style="font-size:10px;color:var(--muted);">（' + (isGear ? '齒輪：工時×模數×齒數×齒寬×係數×基準金額/秒' : '一般：工時×係數×倍數×基準金額/秒') + '）</span>'
            : '';
        $('#bom-info-badge').html('估計成本: NT$' + fmt(totalCost) + '/件' + formulaHint);
    } else {
        $('#bom-info-badge').html('');
    }
}

// ── BOM製程編輯toggle ─────────────────────────────────────
$(document).on('click', '.btn-edit-toggle', function() {
    var $row = $(this).closest('tr');
    var $del = $row.find('.btn-del-ing');
    var isOpen = $del.is(':visible');
    $del.toggle(!isOpen);
    $(this).toggleClass('btn-default btn-warning');
});

// ── BOM製程刪除 ───────────────────────────────────────────
var _delFid = null, _delBtn = null;

$(document).on('click', '.btn-del-ing', function() {
    _delFid = $(this).data('fid');
    _delBtn = this;
    if (!_delFid) return;
    $('#del-confirm-input').val('');
    $('#del-confirm-err').hide();
    $('#del-confirm-input').css('border-color', '#ccc');
    $('#deleteConfirmModal').modal('show');
    setTimeout(function() { $('#del-confirm-input').focus(); }, 400);
});

$('#del-confirm-input').on('keydown', function(e) {
    if (e.key === 'Enter') $('#del-confirm-btn').click();
});

$('#del-confirm-btn').on('click', function() {
    if ($('#del-confirm-input').val() !== 'Y') {
        $('#del-confirm-input').css('border-color', '#d32f2f').focus();
        $('#del-confirm-err').show();
        return;
    }
    $('#deleteConfirmModal').modal('hide');
    var $btn = $(_delBtn);
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
    ajax({ action: 'delete_bom_ing', bom_ing_fid: _delFid }, function(res) {
        if (!res.success) {
            alert('刪除失敗: ' + (res.message || '未知錯誤'));
            $btn.prop('disabled', false).html('<i class="fa fa-trash"></i>');
            return;
        }
        $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
        delete bomCostCache[currentBom];
    });
});

// ── 儲存加工單價 → bom_ing_transfer_log ──────────────────
$(document).on('click', '.btn-save-log', function() {
    var $btn  = $(this);
    var fid   = $btn.data('fid');
    var bom   = $btn.data('bom');
    var sn    = $btn.data('sn');
    var qty   = $btn.data('qty');
    var price = parseFloat($('.price-inp[data-fid="' + fid + '"]').val());
    var note  = $('.note-inp[data-fid="' + fid + '"]').val() || '';
    if (!price || price <= 0) { alert('請輸入有效的加工單價'); return; }
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
    ajax({ action: 'add_transfer_log', fid: fid, bom: bom, bom_sn: sn, bom_qty: qty, unit_price: price, note: note, has_log: $btn.data('has-log') || 0, part_no: currentPart ? currentPart.part_no : '', maker: $btn.data('maker') || '' }, function(res) {
        if (!res.success) {
            alert('儲存失敗: ' + (res.message || '未知錯誤'));
            $btn.prop('disabled', false).html('<i class="fa fa-save"></i>');
            return;
        }
        $btn.prop('disabled', false).html('<i class="fa fa-check"></i>');
        setTimeout(function() { $btn.html('<i class="fa fa-save"></i>'); }, 1500);
        delete bomCostCache[currentBom];
        loadBomCost();
    });
});

// ── 圖面查看（獨立視窗，可拖移、可貼齊其他螢幕）────────────
function openDrawingModal() {
    if (!currentPart) return;
    var url = SELF + '?viewer=1&part_no=' + encodeURIComponent(currentPart.part_no);
    // 使用 window.open 開啟獨立視窗，使用者可拖移至任意位置或其他螢幕
    window.open(url, 'drawing_' + currentPart.part_no,
        'width=1280,height=900,resizable=yes,scrollbars=yes,toolbar=no,menubar=no');
}

// ── 出貨售價記錄 ─────────────────────────────────────────
function loadShipments(partNo) {
    $('#ship-content').html('<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#ship-summary-row').html('');
    ajax({
        action: 'get_part_shipments',
        part_no: partNo,
        date_from: $('#tb-date-from').val(),
        date_to:   $('#tb-date-to').val()
    }, function(res) {
        if (!res.success) { $('#ship-content').html('<div class="info-box text-danger">' + res.message + '</div>'); return; }
        renderShipments(res.rows || res.data || [], res.summary);
    });
}

function renderShipments(rows, summary) {
    // 摘要欄
    var sumHtml = '';
    sumHtml += '<div class="s-card sc-success" style="padding:8px 12px; flex:1; min-width:120px;"><div class="s-label">出貨次數</div><div class="s-val" style="font-size:16px;">' + summary.count + '</div></div>';
    sumHtml += '<div class="s-card" style="padding:8px 12px; flex:1; min-width:120px;"><div class="s-label">平均售價</div><div class="s-val" style="font-size:16px;">NT$' + fmt(summary.avg_price) + '</div></div>';
    sumHtml += '<div class="s-card" style="padding:8px 12px; flex:1; min-width:120px;"><div class="s-label">售價範圍</div><div class="s-val" style="font-size:14px;">NT$' + fmt(summary.min_price) + ' ~ NT$' + fmt(summary.max_price) + '</div></div>';
    sumHtml += '<div class="s-card" style="padding:8px 12px; flex:1; min-width:120px;"><div class="s-label">總出貨量</div><div class="s-val" style="font-size:16px;">' + fmt(summary.total_qty, 0) + '<small>件</small></div></div>';
    if (summary.order_count > 0) {
        sumHtml += '<div class="s-card" style="padding:8px 12px; flex:1; min-width:120px;"><div class="s-label">訂單筆數</div><div class="s-val" style="font-size:16px;">' + summary.order_count + '</div></div>';
    }
    $('#ship-summary-row').html(sumHtml);

    if (!rows.length) {
        $('#ship-content').html('<div class="info-box">此料號目前無出貨或訂單記錄</div>');
        return;
    }

    var currentCost = 0;
    if (currentPart) currentCost = parseFloat(currentPart.latest_unit_cost) || 0;

    var html = '<div class="ship-table-scroll"><table class="ship-table"><thead><tr>';
    html += '<th>類型</th><th>單號</th><th>日期</th><th>數量</th><th>售價/單價</th>';
    if (currentCost > 0) html += '<th>毛利率</th>';
    html += '<th>客戶</th><th>規格／備註</th><th style="white-space:nowrap;">對應訂單/出貨</th>';
    html += '</tr></thead><tbody>';

    rows.forEach(function(r) {
        var isOrder    = r.row_type === 'order';
        var price      = parseFloat(r.price) || 0;
        var priceCls   = '';
        var rowStyle   = isOrder ? 'background:#f0f4ff;' : '';

        if (price > 0 && currentCost > 0) {
            var m = (price - currentCost) / price * 100;
            priceCls = m < 0 ? 'price-loss' : m < 10 ? 'price-warn' : 'price-ok';
        }

        // 類型欄
        var typeBadge = '';
        var saleTypeSelect = '';
        if (isOrder) {
            var isClosed   = parseInt(r.Order_status) === 9;
            var isShipped  = parseInt(r.has_ship) > 0;
            var openQty    = r.Open_Qty !== null ? parseInt(r.Open_Qty) : null;
            typeBadge = '<span style="padding:1px 5px;border-radius:3px;font-size:10px;background:#3498db;color:#fff;">訂單</span>';
            if (isClosed || (openQty !== null && openQty <= 0)) {
                typeBadge += ' <i class="fa fa-check-circle" style="color:var(--success);" title="已結案"></i>';
            } else if (isShipped) {
                typeBadge += ' <i class="fa fa-truck" style="color:var(--info);" title="部分已出貨"></i>';
            }
        } else {
            var isExcluded = parseInt(r.is_count) === 0;
            typeBadge = '<span style="padding:1px 5px;border-radius:3px;font-size:10px;background:' + (isExcluded ? '#aaa' : '#27ae60') + ';color:#fff;">出貨</span>';
            // 出貨性質下拉
            var stOpts = '<option value="NULL"' + (!r.sale_type ? ' selected' : '') + '>一般產品</option>';
            saleTypesList.forEach(function(st) {
                stOpts += '<option value="' + st.sale_type_id + '"' + (r.sale_type == st.sale_type_id ? ' selected' : '') + '>' + esc(st.sale_type_name) + '</option>';
            });
            saleTypeSelect = '<select class="ship-sale-type-sel" data-isid="' + r.row_id + '" style="font-size:10px;padding:1px 3px;border:1px solid #ddd;border-radius:3px;max-width:90px;cursor:pointer;" title="設定出貨性質">' + stOpts + '</select>';
        }

        var isExcludedRow = !isOrder && (parseInt(r.is_count) === 0);
        if (isExcludedRow) rowStyle += 'opacity:0.65;';

        // 規格／備註：合併 Specification, Content, Note
        var specParts = [];
        if (r.Specification) specParts.push('<span>' + esc(r.Specification) + '</span>');
        if (r.Content)       specParts.push('<span style="color:#555;">' + esc(r.Content) + '</span>');
        if (r.Note)          specParts.push('<span style="color:#999;font-size:10px;">' + esc(r.Note) + '</span>');
        var specCell = specParts.length ? specParts.join('<br>') : '<span style="color:var(--muted);">–</span>';

        html += '<tr style="' + rowStyle + '">';
        html += '<td style="font-size:11px; white-space:nowrap;">' + typeBadge + (saleTypeSelect ? '<br>' + saleTypeSelect : '') + '</td>';
        html += '<td style="font-size:11px;">' + esc(r.doc_no||'–') + '</td>';
        html += '<td style="font-size:11px;">' + fmtDate(r.row_date) + '</td>';
        html += '<td style="text-align:right;">' + fmt(r.Qty, 0) + '</td>';
        html += '<td class="' + priceCls + '" style="text-align:right;">' + (price > 0 ? 'NT$' + fmt(price) : '<span style="color:var(--muted);">–</span>') + (isExcludedRow ? ' <span style="font-size:9px;color:#aaa;">(不計)</span>' : '') + '</td>';
        if (currentCost > 0) {
            if (price > 0 && !isOrder) {
                var mg2 = (price - currentCost) / price * 100;
                html += '<td class="' + priceCls + '" style="text-align:right;">' + mg2.toFixed(1) + '%</td>';
            } else {
                html += '<td style="color:var(--muted); text-align:right;">–</td>';
            }
        }
        html += '<td style="font-size:11px;">' + esc(r.Client_name||'–') + '</td>';
        html += '<td style="font-size:11px; max-width:180px;">' + specCell + '</td>';
        // 對應欄：出貨單顯示綁定訂單號，訂單顯示綁定出貨單號
        var linkedCell;
        if (isOrder) {
            linkedCell = r.bound_ship_nos
                ? '<span style="color:var(--success);font-size:10px;"><i class="fa fa-link"></i> ' + esc(r.bound_ship_nos) + '</span>'
                : '<span style="color:#ccc;font-size:10px;">未綁定出貨單</span>';
        } else {
            linkedCell = r.Order_oo
                ? '<span style="color:var(--info);font-size:10px;"><i class="fa fa-file-text-o"></i> ' + esc(r.Order_oo) + '</span>'
                : '–';
        }
        html += '<td style="font-size:11px;">' + linkedCell + '</td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    $('#ship-content').html(html);

    // 出貨性質下拉變更 → 儲存後重載
    $('#ship-content').off('change.saletype').on('change.saletype', '.ship-sale-type-sel', function() {
        var isId = parseInt($(this).data('isid'));
        var stVal = $(this).val();
        var $sel = $(this);
        $sel.prop('disabled', true);
        $.post('../../src/store/batch_update_sale_type.php',
            { 'ids[]': isId, sale_type: stVal },
            function(res) {
                $sel.prop('disabled', false);
                if (res && res.success) {
                    if (currentPart) loadShipments(currentPart.part_no);
                } else {
                    alert('更新失敗');
                    // 復原選項（重載）
                    if (currentPart) loadShipments(currentPart.part_no);
                }
            }, 'json'
        ).fail(function() { $sel.prop('disabled', false); alert('連線失敗'); });
    });
}

// ── 趨勢圖 ────────────────────────────────────────────────
function loadTrend() {
    if (!currentPart) return;
    ajax({
        action: 'get_part_trend',
        part_no: currentPart.part_no,
        months: $('#trend-months').val()
    }, function(res) {
        if (!res.success || !res.data.length) {
            if (trendChartObj) trendChartObj.destroy();
            if (marginChartObj) marginChartObj.destroy();
            return;
        }
        renderTrendCharts(res.data);
    });
}

function renderTrendCharts(data) {
    var labels = data.map(function(d) { return d.ym; });
    var prices = data.map(function(d) { return d.price > 0 ? d.price : null; });
    var costs  = data.map(function(d) { return d.cost > 0 ? d.cost : null; });
    var margins = data.map(function(d) { return d.margin; });

    // 成本 vs 售價折線圖
    if (trendChartObj) trendChartObj.destroy();
    trendChartObj = new Chart(document.getElementById('trend-chart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: '出貨售價', data: prices, borderColor: '#27ae60', backgroundColor: 'rgba(39,174,96,.1)', tension: .3, pointRadius: 4 },
                { label: '外包成本', data: costs,  borderColor: '#2980b9', backgroundColor: 'rgba(41,128,185,.1)', tension: .3, pointRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } },
            scales: {
                y: { beginAtZero: false, ticks: { font: { size: 11 }, callback: function(v) { return 'NT$' + v; } } },
                x: { ticks: { font: { size: 10 } } }
            }
        }
    });

    // 毛利率長條圖
    if (marginChartObj) marginChartObj.destroy();
    marginChartObj = new Chart(document.getElementById('margin-chart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: '毛利率 (%)',
                data: margins,
                backgroundColor: margins.map(function(m) {
                    if (m === null) return 'rgba(200,200,200,.3)';
                    return m < 0 ? 'rgba(231,76,60,.7)' : m < 10 ? 'rgba(243,156,18,.7)' : 'rgba(39,174,96,.7)';
                }),
                borderRadius: 3
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { ticks: { font: { size: 11 }, callback: function(v) { return v + '%'; } } },
                x: { ticks: { font: { size: 10 } } }
            }
        }
    });
}

// ── 分頁標籤切換 ─────────────────────────────────────────
$(document).on('click', '.d-tab', function() {
    var tabId = $(this).data('tab');
    // 手動切換到成本頁時，清除當前BOM快取以強制重新計算
    if (tabId === 'cost' && currentBom) delete bomCostCache[currentBom];
    switchTab(tabId);
});
function switchTab(tabId) {
    $('.d-tab').removeClass('active');
    $('.d-tab[data-tab="' + tabId + '"]').addClass('active');
    $('.tab-pane').removeClass('active');
    $('#tab-' + tabId).addClass('active');
    if (tabId === 'cost'   && currentBom)  loadBomCost();
    if (tabId === 'trend'  && currentPart) loadTrend();
    if (tabId === 'margin' && currentPart) loadMarginAnalysis(currentPart.part_no);
}

// ── 系統設定 Modal ────────────────────────────────────────
function openSettingsModal(tab) {
    tab = tab || 'margin';
    $('#modal-low-thr').val(marginLowPct);
    $('#modal-ok-thr').val(marginOkPct);
    $('#settingsModal').modal('show');
    switchSettingTab(tab);
}
function switchSettingTab(tab) {
    $('.setting-tab-pane').hide();
    $('#stab-' + tab).show();
    $('.setting-tab-btn').parent().removeClass('active');
    $('.setting-tab-btn[data-stab="' + tab + '"]').parent().addClass('active');
    if (tab === 'saletype') renderSaleTypeSettings();
}
function renderSaleTypeSettings() {
    if (!saleTypesList.length) {
        ajax({ action: 'get_sale_types' }, function(res) {
            if (res.success) { saleTypesList = res.data || []; _renderSaleTypeRows(); }
        });
    } else { _renderSaleTypeRows(); }
}
function _renderSaleTypeRows() {
    var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
    html += '<thead><tr style="background:#f7f9fc;">'
          + '<th style="padding:6px 10px;border-bottom:2px solid #eee;text-align:left;">性質名稱</th>'
          + '<th style="padding:6px 10px;border-bottom:2px solid #eee;">說明</th>'
          + '<th style="padding:6px 10px;border-bottom:2px solid #eee;text-align:center;">列入平均售價計算</th>'
          + '<th style="padding:6px 10px;border-bottom:2px solid #eee;text-align:center;">列入訂單數量綁定</th>'
          + '</tr></thead><tbody>';
    saleTypesList.forEach(function(st) {
        html += '<tr style="border-bottom:1px solid #f0f3f7;">';
        html += '<td style="padding:7px 10px;font-weight:600;">' + esc(st.sale_type_name) + '</td>';
        html += '<td style="padding:7px 10px;font-size:12px;color:var(--muted);">' + esc(st.description || '') + '</td>';
        html += '<td style="padding:7px 10px;text-align:center;"><input type="checkbox" class="st-is-count" data-stid="' + st.sale_type_id + '"' + (parseInt(st.is_count) ? ' checked' : '') + '></td>';
        html += '<td style="padding:7px 10px;text-align:center;"><input type="checkbox" class="st-count-for-order" data-stid="' + st.sale_type_id + '"' + (parseInt(st.count_for_order) !== 0 ? ' checked' : '') + '></td>';
        html += '</tr>';
    });
    html += '<tr style="border-bottom:1px solid #f0f3f7;">';
    html += '<td style="padding:7px 10px;font-weight:600;color:#555;">一般產品（無性質）</td>';
    html += '<td style="padding:7px 10px;font-size:12px;color:var(--muted);">sale_type 為 NULL 的出貨單</td>';
    html += '<td style="padding:7px 10px;text-align:center;"><span style="color:#888;font-size:11px;">固定列入</span></td>';
    html += '<td style="padding:7px 10px;text-align:center;"><span style="color:#888;font-size:11px;">固定列入</span></td>';
    html += '</tr>';
    html += '</tbody></table>';
    $('#saletype-setting-list').html(html);
}
function saveSaleTypeSettings() {
    var updates = [];
    $('#saletype-setting-list .st-is-count').each(function() {
        var stid = $(this).data('stid');
        var cfo  = $('#saletype-setting-list .st-count-for-order[data-stid="' + stid + '"]').is(':checked') ? 1 : 0;
        updates.push({ sale_type_id: stid, is_count: $(this).is(':checked') ? 1 : 0, count_for_order: cfo });
    });
    ajax({ action: 'save_sale_type_is_count', updates: JSON.stringify(updates) }, function(res) {
        if (res.success) {
            updates.forEach(function(u) {
                var st = saleTypesList.find(function(s) { return s.sale_type_id == u.sale_type_id; });
                if (st) { st.is_count = u.is_count; st.count_for_order = u.count_for_order; }
            });
            alert('出貨性質設定已儲存。下次查詢時生效。');
        } else {
            alert('儲存失敗：' + (res.message || ''));
        }
    });
}
function saveMarginSettings() {
    var lo = parseFloat($('#modal-low-thr').val()) || 0;
    var ok = parseFloat($('#modal-ok-thr').val()) || 0;
    lo = Math.min(Math.max(lo, 0), 99);
    ok = Math.min(Math.max(ok, 0), 99);
    $('#low-thr').val(lo);
    $('#ok-thr').val(ok);
    $('#low-thr-display').text(lo);
    $('#ok-thr-display').text(ok);
    updateThreshold();
    var $msg = $('#margin-save-msg');
    $msg.text('已儲存').css('color', 'var(--success)').show();
    setTimeout(function() { $msg.hide(); }, 2000);
}

// ── 廠內計算公式跳窗 ────────────────────────────────────────
$(document).on('click', '.badge-kpi.clickable', function() {
    var idx = parseInt($(this).data('idx'));
    var p = currentBomProcs[idx];
    if (!p) return;
    showCalcDebugModal(p);
});
function showCalcDebugModal(p) {
    var d = p.calc_debug;
    var title = '廠內計算公式 — ' + (p.ProcessName || '');

    // ── Console DEBUG ──────────────────────────────────────────
    console.group('%c🔍 廠內計算 DEBUG — ' + (p.ProcessName||'') + '  (process_no=' + (p.process_no||'?') + ')', 'color:#2563eb;font-weight:700;font-size:13px;');
    console.log('%c calc_mode', 'color:#888;', p.calc_mode || '無');
    if (d) {
        if (d.d_setting_id !== undefined)
            console.log('%c d_setting_id', 'color:#888;', d.d_setting_id, '  group_id =', d.group_id);
        if (d.expr) {
            var exprHex = Array.from(d.expr).map(function(c){ return 'U+'+c.codePointAt(0).toString(16).toUpperCase().padStart(4,'0')+' ('+c+')'; }).join(' ');
            console.log('%c formula_expr（原始）', 'color:#c026d3;font-weight:600;', d.expr);
            console.log('%c formula_expr（字元碼）', 'color:#c026d3;', exprHex);
        }
        if (d.kpi_params)
            console.log('%c kpi_params', 'color:#888;', d.kpi_params);
        if (d.var_config && d.var_config.length) {
            console.group('%c var_config（DB 公式變數定義）', 'color:#7c3aed;font-weight:600;');
            d.var_config.forEach(function(v) {
                var typeLabel = v.type === 'calc_weight' ? '自動重量' : v.type;
                var dimInfo   = v.type === 'calc_weight' ? '' :
                    (v.dim_field !== undefined ? '| dim_field="'+v.dim_field+'"' : '(dim_field 缺失)');
                console.log('  變數', v.var, '| type='+typeLabel,
                    v.label_id  ? '| label_id='+v.label_id  : '',
                    v.sub_id    ? '| sub_id='+v.sub_id      : '',
                    dimInfo,
                    v.label_name ? '| '+v.label_name : '',
                    v.sub_name   ? '>'+v.sub_name   : '');
            });
            console.groupEnd();
        }
        if (d.label_val_map && Object.keys(d.label_val_map).length) {
            console.group('%c labelValMap（料號標籤資料 from DB）', 'color:#0a7c58;font-weight:600;');
            var rows = Object.keys(d.label_val_map).sort().map(function(k){ return {key:k, value:d.label_val_map[k]}; });
            console.table(rows);
            console.groupEnd();
        } else {
            console.warn('labelValMap 是空的 — 料號可能無標籤資料');
        }
        if (d.gear_val_map && Object.keys(d.gear_val_map).length) {
            console.group('%c gearValMap（齒輪資料 from d_setting_gear）', 'color:#b45309;font-weight:600;');
            var gvRows = Object.keys(d.gear_val_map).sort().map(function(k){ return {key:k, value:d.gear_val_map[k]}; });
            console.table(gvRows);
            console.groupEnd();
        } else {
            console.warn('gearValMap 是空的 — 此料號無齒輪資料（d_setting_gear）');
        }
        if (d.setup_cost_map && Object.keys(d.setup_cost_map).length) {
            console.group('%c 基本費用表 (kpi_group_setup_cost)', 'color:#0f766e;font-weight:600;');
            Object.keys(d.setup_cost_map).forEach(function(k){ console.log('  ', k, '=', d.setup_cost_map[k], '元'); });
            console.groupEnd();
        } else {
            console.warn('基本費用表為空 — kpi_group_setup_cost 此群組無設定');
        }
        if (d.weight_map && Object.keys(d.weight_map).length) {
            console.log('%c 自動重量計算結果', 'color:#16a34a;font-weight:600;', d.weight_map);
        } else {
            console.warn('自動重量 = 0 — 重量規則未匹配（見下方規則追蹤）');
        }
        if (d.weight_trace) {
            var wtTitle = d.weight_trace.source === 'Weight_Kg'
                ? '⚖️ 自動重量 → 直接使用 d_setting.Weight_Kg = ' + d.weight_trace.weight_kg + ' kg'
                : '⚖️ 自動重量規則追蹤 (da=' + (d.weight_trace.da||0) + ', keyword_label_id=' + (d.weight_trace.keyword_label_id||0) + ')';
            console.group('%c ' + wtTitle, 'color:#9333ea;font-weight:600;');
            if (d.weight_trace.source === 'Weight_Kg') {
                console.log('  已由 d_setting.Weight_Kg 取值，跳過規則計算。');
            } else if (d.weight_trace.rules && d.weight_trace.rules.length) {
                d.weight_trace.rules.forEach(function(r) {
                    if (r.skip) {
                        console.warn('  規則', r.rule_id, r.rule_name, '→ 跳過:', r.skip,
                            r.D !== undefined ? '  D='+r.D : '', r.L !== undefined ? 'L='+r.L : '',
                            r.D_sources ? r.D_sources.join(', ') : '', r.L_src || '');
                    } else {
                        console.log('%c  規則', 'color:#16a34a;', r.rule_id, r.rule_name,
                            '→ D='+r.D+' L='+r.L+' ρ='+r.rho+' → '+r.weight+'kg');
                    }
                });
            } else {
                console.warn('  無符合條件的規則（kpi_weight_calc_rule is_active=1 有幾筆？）');
            }
            console.groupEnd();
        }
        if (d.vars && d.vars.length) {
            console.group('%c 公式變數實際代入值', 'color:#b45309;font-weight:600;');
            console.table(d.vars.map(function(v){ return {var:v.var, desc:v.desc, value:v.value}; }));
            console.groupEnd();
        }
        console.group('%c SQL 查詢（可複製到 phpMyAdmin 驗證）', 'color:#555;font-weight:600;');
        if (d.sql_group)     console.log('── 製程群組\n' + d.sql_group);
        if (d.sql_formula)   console.log('── 公式設定\n' + d.sql_formula);
        if (d.sql_label)     console.log('── 主標籤\n' + d.sql_label);
        if (d.sql_sub_label) console.log('── 子標籤\n' + d.sql_sub_label);
        if (d.sql_qty)       console.log('── 子標籤數量\n' + d.sql_qty);
        console.groupEnd();
    } else {
        console.warn('calc_debug 為空 — 此製程無法計算（廠內無標準）');
    }
    console.groupEnd();
    // ──────────────────────────────────────────────────────────

    var html = '';
    if (!d) {
        html = '<p style="color:var(--muted);">無計算詳情資料。</p>';
    } else if (p.calc_mode === 'fixed') {
        html += '<p><span class="badge-kpi">固定金額</span></p>';
        html += '<div class="calc-debug-result">固定金額：<strong>NT$' + fmt(d.fixed_price) + '</strong> 元/PCS</div>';
    } else if (p.calc_mode === 'formula') {
        html += '<p><span class="badge-kpi">自訂公式</span></p>';
        html += '<div style="margin:8px 0; padding:8px 12px; background:#f9f0ff; border-radius:6px; font-family:monospace; font-size:13px; color:#5b2d8e;">' + (d.expr || '') + '</div>';
        if (d.vars && d.vars.length) {
            html += '<table class="calc-debug-table"><thead><tr><th>變數</th><th>定義</th><th style="text-align:right;">實際值</th></tr></thead><tbody>';
            d.vars.forEach(function(v) {
                html += '<tr><td><strong>' + v.var + '</strong></td><td>' + v.desc + '</td><td style="text-align:right;">' + (parseFloat(v.value) % 1 === 0 ? parseInt(v.value) : parseFloat(v.value)) + '</td></tr>';
            });
            html += '</tbody></table>';
            // Build substituted expression（用 split/join 避免 regex \b 與多位元組 × 的相容問題）
            var subExpr = d.expr;
            d.vars.forEach(function(v) {
                var dispVal = String(parseFloat(v.value) % 1 === 0 ? parseInt(v.value) : parseFloat(v.value));
                subExpr = subExpr.split(v.var).join(dispVal);
            });
            html += '<div class="calc-debug-result">套用計算：<code>' + subExpr + '</code> = NT$<strong>' + fmt(d.result) + '</strong></div>';
        } else {
            html += '<div class="calc-debug-result">計算結果：NT$<strong>' + fmt(d.result) + '</strong></div>';
        }
    } else if (p.calc_mode === 'time_cache') { // 實際報工工時優先
        html += '<p><span class="badge-kpi" style="background:#0e7c47;">實際工時(優先)</span></p>';
        html += '<div style="margin:8px 0; padding:8px 12px; background:#edfaf1; border-radius:6px; font-size:12px; color:#0e7c47;">平均工時(秒/件) × 基準金額(元/秒) × 難易係數</div>';
        html += '<table class="calc-debug-table"><tbody>';
        html += '<tr><td>資料來源</td><td style="text-align:right;">報工紀錄（' + (d.total_qty || 0) + ' 件）</td></tr>';
        html += '<tr><td>總加工時間</td><td style="text-align:right;">' + parseFloat(d.total_sec || 0).toFixed(1) + ' 秒（' + parseFloat((d.total_sec||0)/60).toFixed(2) + ' 分）</td></tr>';
        html += '<tr><td>平均工時/件</td><td style="text-align:right;">' + parseFloat(d.avg_sec_per_pc || 0).toFixed(2) + ' 秒（' + parseFloat(d.avg_min_per_pc || 0).toFixed(2) + ' 分）</td></tr>';
        html += '<tr><td>基準金額</td><td style="text-align:right;">' + d.base_price_per_sec + ' 元/秒</td></tr>';
        html += '<tr><td>難易係數</td><td style="text-align:right;">' + d.coeff + '</td></tr>';
        html += '</tbody></table>';
        html += '<div class="calc-debug-result">套用計算：<code>' + parseFloat(d.avg_sec_per_pc||0).toFixed(2) + ' × ' + d.base_price_per_sec + ' × ' + d.coeff + '</code> = NT$<strong>' + fmt(d.result) + '</strong></div>';
    } else { // time
        html += '<p><span class="badge-kpi">工時計費</span></p>';
        var formula = d.is_gear
            ? '基礎工時 × 齒輪係數（模數×齒數×齒寬） × 難易係數 × 基準金額/秒'
            : '基礎工時 × 難易係數 × 倍數 × 基準金額/秒';
        html += '<div style="margin:8px 0; padding:8px 12px; background:#f9f0ff; border-radius:6px; font-size:12px; color:#5b2d8e;">' + formula + '</div>';
        html += '<table class="calc-debug-table"><tbody>';
        html += '<tr><td>基礎工時</td><td style="text-align:right;">' + d.base_t + ' 秒/PCS</td></tr>';
        if (d.is_gear) {
            html += '<tr><td>齒輪係數（模數×齒數×齒寬）</td><td style="text-align:right;">' + parseFloat(d.gear_factor).toFixed(4) + '</td></tr>';
        } else if (parseFloat(d.multi || 1) !== 1) {
            html += '<tr><td>倍數</td><td style="text-align:right;">' + d.multi + '</td></tr>';
        }
        html += '<tr><td>難易係數</td><td style="text-align:right;">' + d.coeff + '</td></tr>';
        html += '<tr><td>基準金額</td><td style="text-align:right;">' + d.base_p + ' 元/秒</td></tr>';
        html += '</tbody></table>';
        var subParts = d.is_gear
            ? [d.base_t, parseFloat(d.gear_factor).toFixed(4), d.coeff, d.base_p]
            : [d.base_t, d.coeff, d.multi, d.base_p];
        html += '<div class="calc-debug-result">套用計算：<code>' + subParts.join(' × ') + '</code> = NT$<strong>' + fmt(d.result) + '</strong></div>';
    }
    $('#calc-debug-title').html('<i class="fa fa-calculator"></i> ' + title);
    $('#calc-debug-body').html(html);
    $('#calcDebugModal').modal('show');
}

// ── CSV 匯出 ──────────────────────────────────────────────
function exportShipmentCsv() {
    if (!currentPart) return;
    var form = $('<form method="POST" action="' + SELF + '"><input name="action" value="export_csv"><input name="part_no" value="' + currentPart.part_no + '"><input name="export_type" value="shipments"></form>');
    $('body').append(form); form.submit(); form.remove();
}
function exportListCsv() {
    var form = $('<form method="POST" action="' + SELF + '"><input name="action" value="export_csv"><input name="export_type" value="list"></form>');
    $('body').append(form); form.submit(); form.remove();
}
function rebuildPartCache() {
    var $btn = $('#btn-rebuild-cache').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 重建中...');
    ajax({ action: 'rebuild_part_cache' }, function(res) {
        $btn.prop('disabled', false).html('<i class="fa fa-refresh"></i> 更新快取');
        if (res.success) {
            loadPartList(listPage);
        } else {
            alert('重建失敗：' + (res.message || ''));
        }
    });
}

// ── 模式切換（料號分析 / 客戶分析）──────────────────────────
var currentMode = 'parts';
function switchMode(mode) {
    currentMode = mode;
    if (mode === 'clients') {
        $('#mode-btn-parts').removeClass('btn-primary').addClass('btn-default');
        $('#mode-btn-clients').removeClass('btn-default').addClass('btn-primary');
        $('#client-analysis-panel').show();
        $('#main-grid').hide();
        loadCustomerAnalysis();
    } else {
        $('#mode-btn-clients').removeClass('btn-primary').addClass('btn-default');
        $('#mode-btn-parts').removeClass('btn-default').addClass('btn-primary');
        $('#client-analysis-panel').hide();
        $('#main-grid').show();
    }
}

// ── 客戶分析 ──────────────────────────────────────────────
var clientSortCol = 'avg_margin', clientSortDir = -1;
var clientAnalysisData = [];
var clientPage = 1, clientPerPage = 15;

function loadCustomerAnalysis() {
    $('#client-analysis-content').html('<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> 計算中，請稍候…</div>');
    ajax({
        action: 'get_customer_analysis',
        date_from: $('#tb-date-from').val(),
        date_to:   $('#tb-date-to').val()
    }, function(res) {
        if (!res.success) { $('#client-analysis-content').html('<div class="info-box text-danger">' + (res.message||'載入失敗') + '</div>'); return; }
        clientAnalysisData = res.data;
        clientPage = 1;
        renderCustomerAnalysis(clientAnalysisData);
    });
}

function renderCustomerAnalysis(data) {
    if (!data.length) {
        $('#client-analysis-content').html('<div class="info-box">無出貨資料</div>');
        return;
    }
    // 依客戶篩選 + 利潤率篩選（AND 邏輯）
    var kw = ($('#tb-client').val() || '').trim().toLowerCase();
    var filtered = data.filter(function(r) {
        if (kw) {
            var nameHit = r.client_name.toLowerCase().indexOf(kw) >= 0;
            var idHit   = (r.client_id || '').toLowerCase().indexOf(kw) >= 0;
            if (!nameHit && !idHit) return false;
        }
        if (!currentMarginFilter) return true;
        var mg = r.avg_margin !== null ? parseFloat(r.avg_margin) : null;
        if (currentMarginFilter === 'loss')     return mg !== null && mg < 0;
        if (currentMarginFilter === 'low')      return mg !== null && mg >= 0 && mg < marginLowPct;
        if (currentMarginFilter === 'ok')       return mg !== null && mg >= marginOkPct;
        if (currentMarginFilter === 'no_price') return mg === null;
        return true;
    });
    // 排序
    var sorted = filtered.slice().sort(function(a, b) {
        var av = a[clientSortCol], bv = b[clientSortCol];
        if (av === null && bv === null) return 0;
        if (av === null) return 1;
        if (bv === null) return -1;
        return clientSortDir * ((bv > av) ? 1 : (av > bv) ? -1 : 0);
    });

    // 分頁
    var total = sorted.length;
    var totalPages = Math.max(1, Math.ceil(total / clientPerPage));
    clientPage = Math.min(Math.max(1, clientPage), totalPages);
    var pageData = sorted.slice((clientPage - 1) * clientPerPage, clientPage * clientPerPage);

    var sortIcon = function(col) {
        if (col !== clientSortCol) return '<i class="fa fa-sort" style="opacity:.3;"></i>';
        return clientSortDir === -1 ? '<i class="fa fa-sort-desc"></i>' : '<i class="fa fa-sort-asc"></i>';
    };
    var thS = 'style="cursor:pointer;white-space:nowrap;user-select:none;"';

    // 分頁控制（右上角）
    var pgHtml = '<div style="display:flex;align-items:center;gap:6px;font-size:12px;flex-shrink:0;">';
    pgHtml += '<span style="color:var(--muted);">共 ' + total + ' 筆</span>';
    if (totalPages > 1) {
        pgHtml += '<button class="btn btn-xs btn-default" onclick="clientGoPage(' + Math.max(1, clientPage-1) + ')" ' + (clientPage<=1?'disabled':'') + '><i class="fa fa-chevron-left"></i></button>';
        pgHtml += '<span style="color:var(--muted);">' + clientPage + '/' + totalPages + '</span>';
        pgHtml += '<button class="btn btn-xs btn-default" onclick="clientGoPage(' + Math.min(totalPages, clientPage+1) + ')" ' + (clientPage>=totalPages?'disabled':'') + '><i class="fa fa-chevron-right"></i></button>';
    }
    pgHtml += '</div>';

    var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;">';
    html += '<small style="color:var(--muted);">點欄位標題排序；點「年度趨勢」查看歷年走勢</small>';
    html += pgHtml;
    html += '</div>';

    html += '<div style="overflow-x:auto;"><table class="ship-table">';
    html += '<thead><tr>';
    html += '<th>客戶名稱</th>';
    html += '<th ' + thS + ' onclick="sortClient(\'part_count\')">料號數 ' + sortIcon('part_count') + '</th>';
    html += '<th ' + thS + ' onclick="sortClient(\'ship_count\')">出貨筆數 ' + sortIcon('ship_count') + '</th>';
    html += '<th ' + thS + ' onclick="sortClient(\'total_qty\')" style="text-align:right;cursor:pointer;white-space:nowrap;user-select:none;">總出貨量 ' + sortIcon('total_qty') + '</th>';
    html += '<th ' + thS + ' onclick="sortClient(\'avg_price\')" style="text-align:right;cursor:pointer;white-space:nowrap;user-select:none;">平均售價 ' + sortIcon('avg_price') + '</th>';
    html += '<th style="text-align:right;white-space:nowrap;">售價範圍</th>';
    html += '<th ' + thS + ' onclick="sortClient(\'avg_margin\')" style="text-align:right;cursor:pointer;white-space:nowrap;user-select:none;">平均毛利率 ' + sortIcon('avg_margin') + '</th>';
    html += '<th style="white-space:nowrap;">操作</th>';
    html += '</tr></thead><tbody>';

    pageData.forEach(function(r) {
        var mg = r.avg_margin;
        var mgColor = mg === null ? 'var(--muted)' : mg < 0 ? 'var(--danger)' : mg < marginLowPct ? 'var(--warning)' : mg < marginOkPct ? '#f39c12' : 'var(--success)';
        var mgText  = mg === null
            ? '<span style="color:var(--muted);">無資料</span>'
            : '<span style="color:' + mgColor + ';font-weight:700;">' + mg.toFixed(1) + '%</span>' +
              '<br><small style="color:var(--muted);">(' + r.margin_cnt + '/' + r.part_count + ' 件)</small>';
        var barPct   = mg !== null ? Math.min(100, Math.max(0, mg)) : 0;
        var barColor = mg === null ? '#ddd' : mg < 0 ? '#ef5350' : mg < marginLowPct ? '#ffa726' : mg < marginOkPct ? '#ffee58' : '#66bb6a';
        var bar = mg !== null ? '<div style="height:4px;background:#f0f0f0;border-radius:2px;margin-top:4px;"><div style="width:' + barPct + '%;height:4px;background:' + barColor + ';border-radius:2px;"></div></div>' : '';

        html += '<tr>';
        html += '<td style="font-weight:600;white-space:nowrap;">' + r.client_name + '</td>';
        html += '<td style="text-align:center;">' + r.part_count + '</td>';
        html += '<td style="text-align:center;">' + r.ship_count + '</td>';
        html += '<td style="text-align:right;">' + fmt(r.total_qty, 0) + ' 件</td>';
        html += '<td style="text-align:right;font-weight:600;">NT$' + fmt(r.avg_price) + '</td>';
        html += '<td style="text-align:right;font-size:11px;color:var(--muted);">NT$' + fmt(r.min_price) + '<br>NT$' + fmt(r.max_price) + '</td>';
        html += '<td style="text-align:right;">' + mgText + bar + '</td>';
        html += '<td style="white-space:nowrap;">';
        html += '<button class="btn btn-xs btn-default" onclick="openClientTrend(' + JSON.stringify(r.client_name).replace(/"/g, '&quot;') + ')" title="年度出貨趨勢"><i class="fa fa-line-chart"></i> 年度趨勢</button> ';
        html += '<button class="btn btn-xs btn-info" onclick="filterByClient(' + JSON.stringify(r.client_name).replace(/"/g, '&quot;') + ')" title="查看此客戶料號"><i class="fa fa-list"></i> 查看料號</button>';
        html += '</td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    $('#client-analysis-content').html(html);
}

function clientGoPage(p) {
    clientPage = p;
    renderCustomerAnalysis(clientAnalysisData);
}
function sortClient(col) {
    clientSortDir = (clientSortCol === col) ? clientSortDir * -1 : -1;
    clientSortCol = col;
    clientPage = 1;
    renderCustomerAnalysis(clientAnalysisData);
}

// ── 客戶年度趨勢 ──────────────────────────────────────────
var clientTrendChart = null;
function openClientTrend(clientName) {
    $('#ct-client-name').text(clientName);
    $('#ct-table-content').html('<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i></div>');
    if (clientTrendChart) { clientTrendChart.destroy(); clientTrendChart = null; }
    $('#clientTrendModal').modal('show');
    ajax({ action: 'get_customer_yearly_trend', client_name: clientName }, function(res) {
        if (!res.success || !res.data.length) {
            $('#ct-table-content').html('<div class="info-box">此客戶無出貨資料</div>');
            return;
        }
        renderClientTrend(res.data);
    });
}
function renderClientTrend(data) {
    var years  = data.map(function(d) { return d.yr + '年'; });
    var prices = data.map(function(d) { return parseFloat(d.avg_price) || 0; });
    var counts = data.map(function(d) { return parseInt(d.ship_count) || 0; });
    var qtys   = data.map(function(d) { return parseInt(d.total_qty) || 0; });

    // 年度明細表（新到舊）
    var rows = data.slice().reverse();
    var tbl = '<div style="overflow-x:auto;"><table class="ship-table">';
    tbl += '<thead><tr><th>年度</th><th style="text-align:center;">出貨筆數</th><th style="text-align:center;">料號數</th><th style="text-align:right;">總出貨量</th><th style="text-align:right;">平均售價</th><th style="text-align:right;">最低/最高</th></tr></thead><tbody>';
    rows.forEach(function(d) {
        tbl += '<tr>';
        tbl += '<td style="font-weight:700;">' + d.yr + ' 年</td>';
        tbl += '<td style="text-align:center;">' + d.ship_count + '</td>';
        tbl += '<td style="text-align:center;">' + d.part_count + '</td>';
        tbl += '<td style="text-align:right;">' + fmt(d.total_qty, 0) + ' 件</td>';
        tbl += '<td style="text-align:right;font-weight:600;">NT$' + fmt(d.avg_price) + '</td>';
        tbl += '<td style="text-align:right;font-size:11px;color:var(--muted);">NT$' + fmt(d.min_price) + '<br>NT$' + fmt(d.max_price) + '</td>';
        tbl += '</tr>';
    });
    tbl += '</tbody></table></div>';
    $('#ct-table-content').html(tbl);

    // 折線圖
    clientTrendChart = new Chart(document.getElementById('ct-chart'), {
        type: 'line',
        data: {
            labels: years,
            datasets: [
                { label: '平均售價 (NT$)', data: prices, borderColor: '#27ae60', backgroundColor: 'rgba(39,174,96,.1)', tension: .3, pointRadius: 5, yAxisID: 'y' },
                { label: '出貨筆數', data: counts, borderColor: '#2980b9', backgroundColor: 'rgba(41,128,185,.1)', tension: .3, pointRadius: 5, yAxisID: 'y2', borderDash: [5,3] }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } },
            scales: {
                y:  { type: 'linear', position: 'left',  ticks: { font: { size: 11 }, callback: function(v) { return 'NT$' + v; } } },
                y2: { type: 'linear', position: 'right', ticks: { font: { size: 11 } }, grid: { drawOnChartArea: false } },
                x:  { ticks: { font: { size: 11 } } }
            }
        }
    });
}

function filterByClient(clientName) {
    switchMode('parts');
    $('#tb-client').val(clientName);
    hideClientDropdown();
    loadPartList(1);
}

// ── HTML 跳脫工具 ──────────────────────────────────────────
function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── BOM-訂單綁定 ──────────────────────────────────────────
var _bindBomNo  = '';
var _bindBomQty = 0;

function refreshBindBadge(bomNo) {
    ajax({ action: 'get_orders_for_bom_bind', bom: bomNo, part_no: currentPart ? currentPart.part_no : '' }, function(res) {
        if (!res.success) return;
        var cnt = (res.orders || []).filter(function(o) { return o.is_bound; }).length;
        $('#bind-order-badge').text(cnt > 0 ? '(' + cnt + ')' : '');
    });
}

function openBomOrderBind() {
    if (!currentBom || !currentPart) return;
    _bindBomNo  = currentBom;
    _bindBomQty = 0;
    $('#bom-bind-title').text(currentBom);
    $('#bom-bind-qty').text('-');
    $('#bom-bind-allocated').text('0').css('color','gray');
    $('#bom-bind-warn').hide();
    $('#bom-bind-stock-cb').prop('checked', false);
    $('#bom-bind-order-list').html('<div style="padding:20px;text-align:center;color:#999;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>');
    $('#bindOrderModal').modal('show');
    ajax({ action: 'get_orders_for_bom_bind', bom: currentBom, part_no: currentPart.part_no }, function(res) {
        if (!res.success) {
            $('#bom-bind-order-list').html('<div style="padding:12px;color:red;">載入失敗：' + esc(res.message || '') + '</div>');
            return;
        }
        _bindBomQty = parseInt(res.bom_qty) || 0;
        $('#bom-bind-qty').text(_bindBomQty);
        renderBindOrderList(res.orders);
    });
}

function renderBindOrderList(orders) {
    if (!orders || !orders.length) {
        $('#bom-bind-order-list').html('<div style="padding:12px;text-align:center;color:#999;">此料號無訂單記錄。</div>');
        return;
    }
    var html = '<table class="table table-condensed table-bordered" style="margin-bottom:0;font-size:12px;">';
    html += '<thead><tr style="background:#f5f5f5;">' +
            '<th width="28" class="text-center">✓</th>' +
            '<th>訂單號</th><th style="white-space:nowrap;min-width:90px;">交期</th>' +
            '<th width="46" class="text-center">總數</th>' +
            '<th width="62" class="text-center" style="color:#1a6a1a;">可綁餘量</th>' +
            '<th width="76" class="text-center">分配數量</th>' +
            '</tr></thead><tbody>';
    orders.forEach(function(o) {
        var isClosed  = (o.Order_status == 9);
        var isChecked = o.is_bound ? 'checked' : '';
        var inputVal  = (o.is_bound && o.my_allocated > 0) ? o.my_allocated : '';
        var avail     = parseInt(o.available_qty_for_bind) || 0;
        var disabled  = (!o.is_bound && avail <= 0) ? 'disabled' : '';
        var delDate   = o.Delivery_date || '-';
        var availColor = avail > 0 ? '#1a7a1a' : '#dc3545';
        var rowStyle  = isClosed ? 'style="background:#f8f8f8;opacity:.8;"' :
                        (!o.is_bound && avail <= 0 ? 'style="opacity:.5;"' : '');
        html += '<tr ' + rowStyle + '>';
        html += '<td class="text-center"><input type="checkbox" class="bom-bind-cb" value="' + parseInt(o.Order_id) + '" ' + isChecked + ' ' + disabled +
                ' data-available="' + avail + '" data-order-qty="' + (o.Qty||0) + '"></td>';
        html += '<td style="white-space:nowrap;">' + esc(o.Order_oo || '') +
                (isClosed ? ' <span style="font-size:10px;color:#999;">[結案]</span>' : '') +
                (o.Processing_items ? '<br><span style="color:#888;font-size:10px;">' + esc(o.Processing_items) + '</span>' : '') + '</td>';
        html += '<td class="text-center" style="white-space:nowrap;">' + delDate + '</td>';
        html += '<td class="text-center">' + (o.Qty || 0) + '</td>';
        html += '<td class="text-center"><strong style="color:' + availColor + ';">' + avail + '</strong></td>';
        html += '<td><input type="number" class="form-control input-sm bom-bind-pcs" value="' + inputVal +
                '" style="height:22px;padding:2px 4px;width:62px;" placeholder="數量" min="0">' +
                '<div class="bom-bind-over-warn" style="color:red;font-size:10px;display:none;"></div></td>';
        html += '</tr>';
    });
    html += '</tbody></table>';
    $('#bom-bind-order-list').html(html);

    $('#bom-bind-order-list').off('change.bind input.bind').on('change.bind', '.bom-bind-cb', function() {
        var $row  = $(this).closest('tr');
        var $inp  = $row.find('.bom-bind-pcs');
        var avail = parseInt($(this).data('available')) || 0;
        if ($(this).is(':checked')) {
            var current = getBomBindTotal();
            var needed  = Math.max(0, _bindBomQty - current);
            var fill    = needed > 0 ? Math.min(avail, needed) : avail;
            $inp.val(fill > 0 ? fill : avail);
            $('#bom-bind-stock-cb').prop('checked', false);
        } else {
            $inp.val('');
        }
        updateBomBindQty();
    }).on('input.bind', '.bom-bind-pcs', function() {
        updateBomBindQty();
    });

    $('#bom-bind-stock-cb').off('change.bind').on('change.bind', function() {
        if ($(this).is(':checked')) {
            $('#bom-bind-order-list .bom-bind-cb:checked').each(function() {
                $(this).prop('checked', false).closest('tr').find('.bom-bind-pcs').val('');
            });
            updateBomBindQty();
        }
    });
    updateBomBindQty();
}

function getBomBindTotal() {
    var total = 0;
    $('#bom-bind-order-list .bom-bind-pcs').each(function() {
        if ($(this).closest('tr').find('.bom-bind-cb').is(':checked')) {
            total += parseInt($(this).val()) || 0;
        }
    });
    return total;
}

function updateBomBindQty() {
    var total = getBomBindTotal();
    var color = total > _bindBomQty ? '#e74c3c' : total === _bindBomQty ? '#27ae60' : 'gray';
    $('#bom-bind-allocated').text(total).css('color', color);
    if (total > _bindBomQty) {
        $('#bom-bind-warn').text('⚠ 分配量超過BOM總數').show();
    } else if (total > 0 && total < _bindBomQty) {
        $('#bom-bind-warn').text('尚有 ' + (_bindBomQty - total) + ' 件未分配').show();
    } else {
        $('#bom-bind-warn').hide();
    }
}

function saveBomOrderBind() {
    var orders = [];
    $('#bom-bind-order-list .bom-bind-cb:checked').each(function() {
        var oid = parseInt($(this).val());
        var pcs = parseInt($(this).closest('tr').find('.bom-bind-pcs').val()) || 0;
        if (oid > 0) orders.push({ order_id: oid, pcs: pcs });
    });
    var $btn = $('#bom-bind-save-btn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中...');
    ajax({ action: 'save_bom_order_bind', bom: _bindBomNo, order_pcs_json: JSON.stringify(orders) }, function(res) {
        $btn.prop('disabled', false).html('<i class="fa fa-save"></i> 儲存綁定');
        if (res.success) {
            $('#bindOrderModal').modal('hide');
            $('#bind-order-badge').text(orders.length > 0 ? '(' + orders.length + ')' : '');
            if ($('#tab-margin').hasClass('active') && currentPart) loadMarginAnalysis(currentPart.part_no);
        } else {
            alert('儲存失敗：' + (res.message || '未知錯誤'));
        }
    });
}

// 在料號管理頁以新視窗開啟當前料號編輯跳窗
function openPartEditPage() {
    var did = parseInt($('#btn-open-part-edit').data('did') || 0);
    if (!did) return;
    var partNo = (currentPart && currentPart.part_no) ? encodeURIComponent(currentPart.part_no) : '';
    var url = '../../views/pages/master_data_management.php?open_part=' + did;
    if (partNo) url += '&part_search=' + partNo;
    window.open(url, '_blank');
}

// 更新左側清單單一料號的 dot 顏色及百分比（共用）
function _updateListItemMargin(pn, mp, hasMissing) {
    var $item = $('#part-list .part-item[data-part="' + pn.replace(/"/g,'&quot;') + '"]');
    if (!$item.length) return;
    var $right = $item.find('div[style*="text-align:right"]');
    // 找現有的百分比 div（font-weight:700 或初始佔位 .margin-loading-pct）
    var $pctDiv = $right.find('div[style*="font-weight:700"], .margin-loading-pct');
    if (hasMissing) {
        $item.find('.margin-dot').attr('class', 'margin-dot nodata').attr('title', '成本單價設定未齊全，毛利率僅供參考');
        var warnHtml = (mp !== null)
            ? '<div style="color:#e67e22;font-weight:700;font-size:10px;" title="成本未齊，僅供參考">⚠ ' + mp.toFixed(1) + '%</div>'
            : '<div style="color:#e67e22;font-size:10px;" title="成本單價設定未齊全">⚠ 成本未齊</div>';
        if ($pctDiv.length) $pctDiv.replaceWith(warnHtml);
        else $right.prepend(warnHtml);
        return;
    }
    if (mp === null) {
        // 無法計算（無綁定出貨）：清除佔位符
        $pctDiv.filter('.margin-loading-pct').remove();
        return;
    }
    var dotCls   = mp < 0 ? 'loss' : mp < marginLowPct ? 'low' : 'ok';
    var pctColor = mp < 0 ? 'var(--danger)' : mp < marginOkPct ? 'var(--warning)' : 'var(--success)';
    $item.find('.margin-dot').attr('class', 'margin-dot ' + dotCls).attr('title', '利潤率 ' + mp.toFixed(1) + '%');
    var newHtml = '<div style="color:' + pctColor + ';font-weight:700;">' + mp.toFixed(1) + '%</div>';
    if ($pctDiv.length) $pctDiv.replaceWith(newHtml);
    else $right.prepend(newHtml);
}

// ── 毛利摘要（背景，更新摘要卡片 + 左側清單該料號的百分比）────
function loadMarginSummary(partNo) {
    if (!partNo) return;
    ajax({ action: 'get_margin_analysis', part_no: partNo, bom_costs: '{}' }, function(res) {
        if (!res.success || !res.data || !res.data.length) return;
        // 以各訂單 margin_pct 按 total_revenue 加權平均
        var wRevSum = 0, wMpSum = 0;
        res.data.forEach(function(bom) {
            (bom.orders || []).forEach(function(ord) {
                var r  = parseFloat(ord.total_revenue) || 0;
                var mp = (ord.margin_pct !== null && ord.margin_pct !== undefined) ? parseFloat(ord.margin_pct) : null;
                if (r > 0 && mp !== null) { wRevSum += r; wMpSum += mp * r; }
            });
        });
        if (wRevSum <= 0) return;
        var mp   = wMpSum / wRevSum;
        // 讀取 has_missing_cost（由 get_margins_for_list 的第一筆提供，這裡從 res.data 取）
        var miss = !!(partDataCache[partNo] && partDataCache[partNo].has_missing_cost);
        if (partDataCache[partNo]) partDataCache[partNo].margin_pct = mp;
        _updateListItemMargin(partNo, mp, miss);
        if (miss) {
            // 成本未齊：摘要卡片不顯示或顯示警告
            $('#sc-margin-card').attr('class', 's-card');
            $('#sc-margin').html('<span style="color:#e67e22;">⚠ ' + mp.toFixed(1) + '%</span>');
            $('#sc-margin-sub').html('<span title="部分製程無單價，成本低估，毛利率僅供參考" style="cursor:help;">成本未齊，僅供參考 <i class=\'fa fa-question-circle\' style=\'opacity:.5\'></i></span>');
        } else {
            var cls = mp < 0 ? 'margin-profit-bad' : mp < marginLowPct ? 'margin-profit-low' : 'margin-profit-ok';
            $('#sc-margin-card').attr('class', 's-card ' + cls);
            $('#sc-margin').html('<span class="' + cls + '">' + mp.toFixed(1) + '%</span>');
            $('#sc-margin-sub').text('依綁定出貨計算');
        }
    });
}

// ── 毛利分析 ──────────────────────────────────────────────
function loadMarginAnalysis(partNo) {
    if (!partNo) {
        $('#margin-content').html('<div class="info-box"><i class="fa fa-arrow-left"></i> 請先選擇料號</div>');
        return;
    }
    $('#margin-content').html('<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>');
    ajax({ action: 'get_margin_analysis', part_no: partNo, bom_costs: JSON.stringify(bomUnitCostCache) }, function(res) {
        if (!res.success) {
            $('#margin-content').html('<div class="info-box text-danger">載入失敗：' + esc(res.message || '') + '</div>');
            return;
        }
        renderMarginAnalysis(res.data);
    });
}

function renderMarginAnalysis(data) {
    if (!data || !data.length) {
        $('#margin-content').html('<div class="info-box">此料號無 BOM 紀錄。</div>');
        return;
    }
    var html = '';
    data.forEach(function(bom) {
        var closedTag = bom.is_closed
            ? '<span class="margin-tag-closed">已結案</span>'
            : '<span class="margin-tag-active">進行中</span>';
        var costFmt = bom.total_cost > 0
            ? 'NT$' + fmt(bom.total_cost)
            : '<span style="color:#aaa;">無成本資料</span>';
        var bindCount = bom.orders.length;

        html += '<div class="margin-bom-card">';
        html += '<div class="margin-bom-header" onclick="toggleMarginBom(this)">';
        html += '<i class="fa fa-caret-down" style="width:14px;"></i>';
        html += '<span class="margin-bom-title"><i class="fa fa-cogs" style="color:var(--primary);"></i> ' + esc(bom.bom) + '</span>';
        html += closedTag;
        html += '<span style="font-size:11px;color:var(--muted);">生產數：' + (bom.bom_qty||0) + '&nbsp;&nbsp;估計成本/件：' + costFmt + '</span>';
        html += '<span style="font-size:11px;color:var(--muted);">綁定訂單：' + bindCount + ' 筆</span>';
        if (bom.created_at) html += '<span style="font-size:10px;color:var(--muted);">建立：' + bom.created_at + '</span>';
        html += '</div>';

        html += '<div class="margin-bom-body">';
        if (!bindCount) {
            html += '<div style="padding:8px;color:#aaa;font-size:12px;text-align:center;">' +
                    '<i class="fa fa-chain-broken"></i> 尚未綁定訂單 — 切換到「BOM製程成本」頁籤選取此BOM後按「綁定訂單」</div>';
        } else {
            bom.orders.forEach(function(ord) {
                var sLabel = ord.order_status == 9
                    ? '<span style="color:#999;font-size:10px;">[結案]</span>'
                    : '<span style="color:var(--success);font-size:10px;">[進行中]</span>';
                var mp = ord.margin_pct;
                var mgClass = mp === null ? '' : mp < 0 ? 'margin-profit-bad' : mp < marginLowPct ? 'margin-profit-low' : 'margin-profit-ok';
                var mgText  = mp === null ? '–' : '<span class="' + mgClass + '">' + mp.toFixed(1) + '%</span>';

                html += '<div class="margin-order-row">';
                var bindShipArgs = JSON.stringify({ order_id: ord.order_id, order_no: ord.order_no, order_qty: ord.order_qty }).replace(/"/g,'&quot;');
                html += '<div class="margin-order-header" onclick="toggleMarginOrder(this)">';
                html += '<i class="fa fa-caret-right" style="width:12px;"></i>';
                html += '<strong style="font-size:12px;">' + esc(ord.order_no) + '</strong> ' + sLabel;
                html += '<span style="color:var(--muted);font-size:11px;">' + esc(ord.client_name) + '</span>';
                html += '<span style="font-size:11px;">訂單 ' + (ord.order_qty||0) + ' | 分配 ' + (ord.alloc_qty||0) + ' | 出貨 ' + (ord.total_ship_qty||0) + '</span>';
                var revHtml;
                if (ord.revenue_is_estimate) {
                    revHtml = 'NT$' + fmt(ord.total_revenue) + ' <span style="font-size:10px;color:#e67e22;font-weight:normal;white-space:nowrap;">(訂單預估)</span>';
                } else {
                    revHtml = 'NT$' + fmt(ord.total_revenue);
                }
                var unitCostPc   = parseFloat(ord.unit_cost_per_pc)  || 0;
                var unitPriceOrd = parseFloat(ord.order_unit_price) || 0;
                var calcQty      = parseInt(ord.alloc_qty) || parseInt(ord.order_qty) || 1;
                if (unitPriceOrd > 0 && unitCostPc > 0) {
                    // 主要：顯示單PC金額與毛利率
                    var unitMp    = (unitPriceOrd - unitCostPc) / unitPriceOrd * 100;
                    var unitMpCls = unitMp < 0 ? 'color:var(--danger)' : unitMp < marginLowPct ? 'color:var(--warning)' : 'color:var(--success)';
                    html += '<span style="font-size:11px;margin-left:auto;">單價 <strong>NT$' + fmt(unitPriceOrd) + '</strong></span>';
                    html += '<span style="font-size:11px;">成本/件 NT$' + fmt(unitCostPc) + '</span>';
                    html += '<span style="font-size:11px;font-weight:700;' + unitMpCls + '">毛利率 ' + unitMp.toFixed(1) + '%</span>';
                    // 次要：總金額（較小字）
                    var totalRevNote = ord.revenue_is_estimate ? '(預估)' : '';
                    html += '<span style="font-size:10px;color:var(--muted);">× ' + calcQty + '件 → 收入 NT$' + fmt(ord.total_revenue) + totalRevNote + ' / 成本 NT$' + fmt(ord.allocated_cost) + '</span>';
                } else {
                    // fallback：無單價時顯示總金額
                    html += '<span style="font-size:11px;margin-left:auto;">收入 <strong>' + revHtml + '</strong></span>';
                    html += '<span style="font-size:11px;">成本 NT$' + fmt(ord.allocated_cost) + '</span>';
                    html += '<span style="font-size:11px;">毛利率 ' + mgText + '</span>';
                }
                var _hasSh = ord.shipments && ord.shipments.length > 0;
                var _bindBtnStyle = _hasSh
                    ? 'margin-left:4px;flex-shrink:0;background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7;'
                    : 'margin-left:4px;flex-shrink:0;';
                var _bindBtnCls = _hasSh ? 'btn btn-xs btn-default no-print' : 'btn btn-xs btn-success no-print';
                var _bindBtnTxt = _hasSh
                    ? '<i class="fa fa-check-circle" style="color:#2e7d32;"></i> 已綁定 (' + ord.shipments.length + ' 筆) <i class="fa fa-pencil" style="font-size:10px;opacity:.6;"></i>'
                    : '<i class="fa fa-truck"></i> 綁定出貨單';
                html += '<button class="' + _bindBtnCls + '" style="' + _bindBtnStyle + '" onclick="event.stopPropagation();openShipOrderBind(' + ord.order_id + ',' + JSON.stringify(ord.order_no).replace(/"/g,'&quot;') + ',' + (ord.order_qty||0) + ',' + JSON.stringify(ord.order_ps||'').replace(/"/g,'&quot;') + ',' + JSON.stringify(ord.client_name||'').replace(/"/g,'&quot;') + ')" title="' + (_hasSh ? '點擊重新綁定出貨單' : '綁定出貨單') + '">' + _bindBtnTxt + '</button>';
                // 出貨不足且訂單未結案：顯示「立即結案」按鈕
                if (_hasSh && (ord.total_ship_qty||0) < (ord.order_qty||0) && parseInt(ord.order_status) !== 9) {
                    html += '<button class="btn btn-xs btn-warning no-print" style="margin-left:4px;flex-shrink:0;" onclick="event.stopPropagation();forceCloseOrder(' + ord.order_id + ',' + JSON.stringify(ord.order_no).replace(/"/g,'&quot;') + ')" title="出貨量未達訂單量，強制結案"><i class="fa fa-lock"></i> 立即結案</button>';
                }
                html += '</div>';

                html += '<div class="margin-order-ships">';
                if (!ord.shipments || !ord.shipments.length) {
                    var noShipMsg = '此訂單尚無出貨記錄';
                    if (ord.order_unit_price > 0) {
                        var currLabel = (ord.order_currency && ord.order_currency !== 'TWD') ? ' ' + ord.order_currency : '';
                        noShipMsg += ' · <span style="color:#e67e22;">訂單單價 NT$' + fmt(ord.order_unit_price) + currLabel + '</span>';
                    }
                    html += '<div style="color:#aaa;font-size:11px;text-align:center;padding:4px;">' + noShipMsg + '</div>';
                } else {
                    html += '<table class="margin-ship-table">';
                    html += '<thead><tr><th>出貨單號</th><th>日期</th><th style="text-align:right;">數量</th><th style="text-align:right;">單價</th><th style="text-align:right;">金額</th></tr></thead><tbody>';
                    ord.shipments.forEach(function(s) {
                        html += '<tr>';
                        html += '<td>' + esc(s.IS_number||'') + '</td>';
                        html += '<td>' + esc(s.ship_date||'') + '</td>';
                        html += '<td style="text-align:right;">' + fmt(s.Qty,0) + '</td>';
                        html += '<td style="text-align:right;">NT$' + fmt(s.Unit_price) + '</td>';
                        html += '<td style="text-align:right;font-weight:600;">NT$' + fmt(s.revenue) + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                }
                html += '</div></div>';
            });
        }
        html += '</div></div>';
    });
    $('#margin-content').html(html);

    // 毛利分析載入完成後，以正確成本（含 bom_costs 快取）更新摘要卡片毛利率
    _updateSummaryMarginFromData(data);
}

function _updateSummaryMarginFromData(data) {
    if (!data || !data.length) return;
    var wRevSum = 0, wMpSum = 0;
    data.forEach(function(bom) {
        (bom.orders || []).forEach(function(ord) {
            var r  = parseFloat(ord.total_revenue) || 0;
            var mp = (ord.margin_pct !== null && ord.margin_pct !== undefined) ? parseFloat(ord.margin_pct) : null;
            if (r > 0 && mp !== null) { wRevSum += r; wMpSum += mp * r; }
        });
    });
    if (wRevSum <= 0) return;
    var mp  = wMpSum / wRevSum;
    var cls = mp < 0 ? 'margin-profit-bad' : mp < marginLowPct ? 'margin-profit-low' : 'margin-profit-ok';
    $('#sc-margin-card').attr('class', 's-card ' + cls);
    $('#sc-margin').html('<span class="' + cls + '">' + mp.toFixed(1) + '%</span>');
    $('#sc-margin-sub').text('依綁定出貨計算');
    // 同步更新快取與清單項目
    if (currentPart) {
        if (partDataCache[currentPart.part_no]) partDataCache[currentPart.part_no].margin_pct = mp;
        _updateListItemMargin(currentPart.part_no, mp, false);
    }
}

function toggleMarginBom(header) {
    var $h    = $(header);
    var $body = $h.next('.margin-bom-body');
    var $icon = $h.find('.fa-caret-down, .fa-caret-up');
    if ($body.is(':visible')) {
        $body.hide();
        $icon.removeClass('fa-caret-up').addClass('fa-caret-down');
    } else {
        $body.show();
        $icon.removeClass('fa-caret-down').addClass('fa-caret-up');
    }
}

function toggleMarginOrder(header) {
    var $h     = $(header);
    var $ships = $h.next('.margin-order-ships');
    var $icon  = $h.find('.fa-caret-right, .fa-caret-down');
    if ($ships.is(':visible')) {
        $ships.hide();
        $icon.removeClass('fa-caret-down').addClass('fa-caret-right');
    } else {
        $ships.show();
        $icon.removeClass('fa-caret-right').addClass('fa-caret-down');
    }
}

// ── 出貨單-訂單綁定 ───────────────────────────────────────
var _shipBindOrderId  = 0;
var _shipBindOrderQty = 0;

function openShipOrderBind(orderId, orderNo, orderQty, orderPs, clientName) {
    if (!currentPart) return;
    _shipBindOrderId  = orderId;
    _shipBindOrderQty = orderQty;
    $('#ship-bind-client-name').text(clientName || '');
    $('#ship-bind-order-no').text(orderNo);
    $('#ship-bind-part-no').text(currentPart.part_no);
    $('#ship-bind-order-ps').text(orderPs ? '備註：' + orderPs : '');
    $('#ship-bind-order-qty').text(orderQty);
    $('#ship-bind-total-qty').text('0').css('color','gray');
    $('#ship-bind-warn').hide();
    $('#ship-bind-list').html('<div style="padding:20px;text-align:center;color:#999;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>');
    $('#bindShipModal').modal('show');
    ajax({ action: 'get_shipments_for_order_bind', part_no: currentPart.part_no, order_id: orderId }, function(res) {
        if (!res.success) {
            $('#ship-bind-list').html('<div style="padding:12px;color:red;">載入失敗：' + esc(res.message || '') + '</div>');
            return;
        }
        renderBindShipList(res.ships);
    });
}

function renderBindShipList(ships) {
    if (!ships || !ships.length) {
        $('#ship-bind-list').html('<div style="padding:12px;text-align:center;color:#999;">此料號無出貨記錄。</div>');
        return;
    }
    // 若訂單無客戶名稱，從出貨記錄補填
    if (!$('#ship-bind-client-name').text().trim()) {
        var fallback = ships.find(function(s) { return s.Client_name; });
        if (fallback) $('#ship-bind-client-name').text(fallback.Client_name);
    }
    var html = '<table class="table table-condensed table-bordered" style="margin-bottom:0;font-size:12px;">';
    html += '<thead><tr style="background:#f5f5f5;">' +
            '<th width="28" class="text-center">✓</th>' +
            '<th style="white-space:nowrap;">出貨單號</th>' +
            '<th style="white-space:nowrap; min-width:82px;">日期</th>' +
            '<th style="white-space:nowrap;">規格／備註</th>' +
            '<th width="46" class="text-center" style="white-space:nowrap;">出貨量</th>' +
            '<th width="56" class="text-center" style="white-space:nowrap;">單價</th>' +
            '<th width="76" class="text-center" style="white-space:nowrap;">綁定出貨量</th>' +
            '</tr></thead><tbody>';
    ships.forEach(function(s) {
        var isChecked = s.is_bound ? 'checked' : '';
        var shipQty   = parseInt(s.Qty) || 0;
        var bindQty   = s.is_bound ? (parseInt(s.shipped_qty) || shipQty) : '';
        var rowBg     = s.is_bound ? 'background:#f0fff4;' : '';
        html += '<tr style="' + rowBg + '">';
        html += '<td class="text-center"><input type="checkbox" class="ship-bind-cb" value="' + parseInt(s.IS_id) + '" ' + isChecked +
                ' data-ship-qty="' + shipQty + '"></td>';
        var stBadge = s.sale_type_name && s.sale_type_name !== '出貨'
            ? '<span style="display:inline-block;background:#e3f2fd;color:#1565c0;border-radius:3px;padding:0 5px;font-size:10px;font-weight:600;margin-right:4px;">' + esc(s.sale_type_name) + '</span>'
            : '';
        html += '<td style="white-space:nowrap;">' + stBadge + esc(s.IS_number || '') + '</td>';
        html += '<td style="white-space:nowrap;">' + esc(s.ship_date || '') + '</td>';
        var specParts = [s.Specification, s.Content].filter(function(v){ return v && v.trim(); });
        var specText  = specParts.join(' / ') || '–';
        html += '<td style="font-size:11px;">' +
                '<div style="color:#666;word-break:break-word;">' + esc(specText) + '</div>' +
                (s.Note ? '<div style="color:#888;font-size:10px;word-break:break-word;">' + esc(s.Note) + '</div>' : '') +
                '</td>';
        html += '<td class="text-center">' + shipQty + '</td>';
        html += '<td class="text-center" style="white-space:nowrap;">NT$' + fmt(s.Unit_price) + '</td>';
        html += '<td><input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control input-sm ship-bind-qty" value="' + bindQty +
                '" style="height:22px;padding:2px 4px;width:62px;" placeholder="數量"></td>';
        html += '</tr>';
    });
    html += '</tbody></table>';
    $('#ship-bind-list').html(html);

    $('#ship-bind-list').off('change.ship input.ship')
        .on('change.ship', '.ship-bind-cb', function() {
            var $row    = $(this).closest('tr');
            var $inp    = $row.find('.ship-bind-qty');
            var shipQty = parseInt($(this).data('ship-qty')) || 0;
            if ($(this).is(':checked')) {
                $inp.val(shipQty);  // 勾選直接填入出貨全量
            } else {
                $inp.val('');
            }
            updateShipBindQty();
        })
        .on('input.ship', '.ship-bind-qty', function() {
            updateShipBindQty();
        });
    updateShipBindQty();
}

function shipBindSelectAll(checked) {
    $('#ship-bind-list .ship-bind-cb').each(function() {
        var $cb     = $(this);
        var $inp    = $cb.closest('tr').find('.ship-bind-qty');
        var shipQty = parseInt($cb.data('ship-qty')) || 0;
        $cb.prop('checked', checked);
        $inp.val(checked ? shipQty : '');
    });
    updateShipBindQty();
}

function updateShipBindQty() {
    var total = 0;
    $('#ship-bind-list .ship-bind-cb:checked').each(function() {
        var qty = parseInt($(this).closest('tr').find('.ship-bind-qty').val()) || 0;
        total += qty;
    });
    var color = total > _shipBindOrderQty ? '#e74c3c' : total === _shipBindOrderQty ? '#27ae60' : 'gray';
    $('#ship-bind-total-qty').text(total).css('color', color);
    if (total > _shipBindOrderQty) {
        $('#ship-bind-warn').text('⚠ 綁定量超過訂單數量').show();
    } else if (total > 0 && total < _shipBindOrderQty) {
        $('#ship-bind-warn').text('尚有 ' + (_shipBindOrderQty - total) + ' 件未綁定').show();
    } else {
        $('#ship-bind-warn').hide();
    }
}

function saveShipOrderBind() {
    var ships = [];
    $('#ship-bind-list .ship-bind-cb:checked').each(function() {
        var is_id = parseInt($(this).val());
        var qty   = parseInt($(this).closest('tr').find('.ship-bind-qty').val()) || 0;
        if (is_id > 0) ships.push({ IS_id: is_id, shipped_qty: qty });
    });
    var $btn = $('#ship-bind-save-btn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中...');
    ajax({ action: 'save_shipment_order_bind', order_id: _shipBindOrderId, ship_pcs_json: JSON.stringify(ships) }, function(res) {
        $btn.prop('disabled', false).html('<i class="fa fa-save"></i> 儲存綁定');
        if (res.success) {
            $('#bindShipModal').modal('hide');
            if (res.status_changed === 'closed') {
                var _msg = '訂單已自動結案';
                if (res.auto_closed_boms && res.auto_closed_boms.length) {
                    _msg += '，BOM 同步結案：' + res.auto_closed_boms.join('、');
                }
                showBindStatusMsg(_msg, '#27ae60');
            } else if (res.status_changed === 'reopened') {
                showBindStatusMsg('出貨不足，訂單已取消結案', '#e67e22');
            }
            // 重新載入毛利分析以反映新綁定
            if (currentPart) loadMarginAnalysis(currentPart.part_no);
        } else {
            alert('儲存失敗：' + (res.message || '未知錯誤'));
        }
    });
}

function showBindStatusMsg(msg, color) {
    var $n = $('<div style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:' + color + ';color:#fff;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:600;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,.25);">' + msg + '</div>');
    $('body').append($n);
    setTimeout(function(){ $n.fadeOut(400, function(){ $n.remove(); }); }, 2500);
}

function forceCloseOrder(orderId, orderNo) {
    if (!confirm('確定要結案訂單 ' + orderNo + ' 嗎？\n（出貨量未達訂單數量，仍可手動結案）')) return;
    ajax({ action: 'close_order_now', order_id: orderId }, function(res) {
        if (res.success) {
            var _msg = '訂單 ' + orderNo + ' 已結案';
            if (res.auto_closed_boms && res.auto_closed_boms.length) {
                _msg += '，BOM 同步結案：' + res.auto_closed_boms.join('、');
            }
            showBindStatusMsg(_msg, '#27ae60');
            if (currentPart) loadMarginAnalysis(currentPart.part_no);
        } else {
            alert('結案失敗：' + (res.message || '未知錯誤'));
        }
    });
}

// ── 初始化 ────────────────────────────────────────────────
$(function() {
    // 先從 localStorage 還原，避免閃爍
    $('#low-thr').val(marginLowPct);
    $('#ok-thr').val(marginOkPct);
    $('#low-thr-display').text(marginLowPct);
    $('#ok-thr-display').text(marginOkPct);
    // 再從 DB 取最新設定值
    ajax({ action: 'get_margin_settings' }, function(res) {
        if (res.success && res.low !== undefined) {
            marginLowPct = parseFloat(res.low) || 0;
            marginOkPct  = parseFloat(res.ok)  || 0;
            localStorage.setItem('ca_low_pct', marginLowPct);
            localStorage.setItem('ca_ok_pct',  marginOkPct);
            $('#low-thr').val(marginLowPct);
            $('#ok-thr').val(marginOkPct);
            $('#low-thr-display').text(marginLowPct);
            $('#ok-thr-display').text(marginOkPct);
        }
    });
    // 預設顯示近 3 個月有紀錄的料號
    (function() {
        var now = new Date();
        var from = new Date(now);
        from.setMonth(from.getMonth() - 3);
        $('#tb-date-from').val(from.toISOString().slice(0, 10));
        $('#tb-date-to').val(now.toISOString().slice(0, 10));
        $('.date-preset[data-m="3"]').addClass('active');
    })();
    // 載入出貨性質清單（供出貨行下拉及設定頁使用）
    ajax({ action: 'get_sale_types' }, function(res) {
        if (res.success) { saleTypesList = res.data || []; }
    });
    loadPartList(1);

    // 列印前填入日期（YYYY/MM/DD HH:MM）
    window.addEventListener('beforeprint', function() {
        var d = new Date();
        var ds = d.getFullYear() + '/' +
                 String(d.getMonth() + 1).padStart(2, '0') + '/' +
                 String(d.getDate()).padStart(2, '0') + '  ' +
                 String(d.getHours()).padStart(2, '0') + ':' +
                 String(d.getMinutes()).padStart(2, '0');
        document.getElementById('ph-date').textContent = '列印日期：' + ds;
    });
});
</script>
</body>
</html>
