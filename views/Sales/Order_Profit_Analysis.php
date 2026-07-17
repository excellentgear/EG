<?php
/**
 * Order_Profit_Analysis.php — 訂單毛利分析（試作版）
 *
 * 成本口徑（由上往下回推，零人工建檔）：
 *   單顆成本 = 該訂單綁定製令(bom.o_order_id=order_track.Order_id) 的
 *              bom_ing_transfer_log 各製程(bom_sn)實際發包單價加權平均後加總
 *              （modified_unit_price 優先，權重 paid_qty→transfer_qty→sqty；排除廠內加工商）
 *   廠內製程不計成本（報工時間未必準確，使用者定案先不計），以「部分」狀態標示。
 * 營收 = 訂單數量 × 單價 ×（匯率>0 則乘匯率）。
 * 加工類別：製令 bom_ing 中 bom_sn 最小的製程若為客供料(ProcessNo 138 或名稱含「客供料」)＝單製，否則全製。
 * 檢視：訂單明細 / 料號彙總（依 料號×加工類別 分組；含 ABC 柏拉圖分級：查詢範圍內營收由大到小
 *       累計佔比 ≤80%=A、≤95%=B、其餘=C）。
 * 依 UI 規範：後端算完全部資料才分頁/排序/總計；分頁 5/10/20/50；CSV 匯出＋列印(PDF)。
 * 權限：RBAC module='order_profit'（rf_has_module_role），管理者固定可用。
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/role_features_helper.php';

$isAjax = isset($_GET['action']);

if (!isset($_SESSION['userName'])) {
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>'未登入']); exit; }
    $_SESSION['lastpage'] = "../../views/Sales/Order_Profit_Analysis.php";
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$my_id = (int)$_SESSION['id'];
$has_access = rf_has_module_role($pdo, $my_id, 'order_profit');

/* ══════════════════════ 資料計算（後端算完全部再分頁） ══════════════════════ */

function opa_chunked_in(PDO $pdo, string $sqlTpl, array $ids, int $chunk = 500): array {
    // $sqlTpl 內以 {IN} 佔位；回傳所有 chunk 的列合併
    $out = [];
    foreach (array_chunk($ids, $chunk) as $part) {
        $ph = implode(',', array_fill(0, count($part), '?'));
        $st = $pdo->prepare(str_replace('{IN}', $ph, $sqlTpl));
        $st->execute($part);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r;
    }
    return $out;
}

function opa_kg_set(PDO $pdo): array {
    // 客供料製程集合：ProcessNo 138 或名稱含「客供料」
    $kg = [];
    try { $kg = $pdo->query("SELECT ProcessNo FROM process_no WHERE ProcessNo=138 OR ProcessName LIKE '%客供料%'")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch (Exception $e) {}
    $kg = array_map('intval', $kg);
    return empty($kg) ? [138] : $kg;
}

function opa_gear_map(PDO $pdo, array $dSettingIds): array {
    // 齒輪規格字串（邏輯同 NewOrder_Track.php / master_data_management.php 的批次查詢）
    if (empty($dSettingIds)) return [];
    $map = [];
    try {
        $tmpl_replacements = [
            '{Module}'               => "COALESCE(NULLIF(g.module_display,''), IF(g.Module IS NOT NULL AND g.Module<>'', IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M',g.Module)), ''))",
            '{Teeth}'                => "COALESCE(CAST(NULLIF(g.Teeth,0) AS CHAR),'')",
            '{Face_Width}'           => "IF(g.Face_Width IS NOT NULL AND g.Face_Width>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR))), '')",
            '{Pressure_Angle}'       => "TRIM(TRAILING '°' FROM TRIM(COALESCE(g.Pressure_Angle,'')))",
            '{Helix_Direction}'      => "COALESCE(NULLIF(g.Helix_Direction,''),'')",
            '{Helix_Angle_Str}'      => "COALESCE(NULLIF(g.Helix_Angle_Str,''), IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR))), ''))",
            '{spec_starts}'          => "COALESCE(CAST(NULLIF(g.spec_starts,0) AS CHAR),'')",
            '{X_PART}'               => "IF(g.Profile_Shift_X IS NOT NULL AND g.Profile_Shift_X<>0, CONCAT('X',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Profile_Shift_X AS CHAR)))), '')",
            '{GRADE}'                => "IF(g.gear_quality_std IS NOT NULL AND g.gear_quality_std<>'', CONCAT(g.gear_quality_std,COALESCE(CAST(g.gear_quality_grade AS CHAR),'')), '')",
            '{spec_chain_size}'      => "COALESCE(g.spec_chain_size,'')",
            '{spec_pitch}'           => "IF(g.spec_pitch IS NOT NULL AND g.spec_pitch>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_pitch AS CHAR))), '')",
            '{spec_roller_dia}'      => "IF(g.spec_roller_dia IS NOT NULL AND g.spec_roller_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_roller_dia AS CHAR))), '')",
            '{spec_spline_type}'     => "COALESCE(g.spec_spline_type,'')",
            '{spec_spline_major_dia}'=> "IF(g.spec_spline_major_dia IS NOT NULL AND g.spec_spline_major_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_major_dia AS CHAR))), '')",
            '{spec_spline_minor_dia}'=> "IF(g.spec_spline_minor_dia IS NOT NULL AND g.spec_spline_minor_dia>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_minor_dia AS CHAR))), '')",
            '{spec_spline_width}'    => "IF(g.spec_spline_width IS NOT NULL AND g.spec_spline_width>0, TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.spec_spline_width AS CHAR))), '')",
            '{spec_pulley_profile}'  => "COALESCE(g.spec_pulley_profile,'')",
            '{Remark_Gear}'          => "COALESCE(NULLIF(g.Remark_Gear,''),'')",
        ];
        $tmpl_expr = 'dt.display_template';
        foreach ($tmpl_replacements as $token => $expr) {
            $tmpl_expr = "REPLACE($tmpl_expr, '$token', $expr)";
        }
        foreach (array_chunk($dSettingIds, 500) as $part) {
            $ph = implode(',', array_fill(0, count($part), '?'));
            $gq = $pdo->prepare("SELECT g.d_setting_id,
                GROUP_CONCAT(
                    CASE
                      WHEN dt.display_template IS NOT NULL AND dt.display_template<>'' THEN
                        $tmpl_expr
                      WHEN dt.spec_category='spline' AND g.spec_spline_type='矩形' THEN
                        CONCAT(IF(g.Teeth>0, CONCAT(g.Teeth,'鍵 '),''), COALESCE(CAST(g.spec_spline_minor_dia AS CHAR),'?'), ' × ', COALESCE(CAST(g.spec_spline_major_dia AS CHAR),'?'), ' × ', COALESCE(CAST(g.spec_spline_width AS CHAR),'?'))
                      ELSE
                        CONCAT(
                            IF(g.Module IS NOT NULL AND g.Module != '',
                               IF(LEFT(UPPER(g.Module),1)='M', g.Module, CONCAT('M', g.Module)), ''),
                            IF(dt.spec_category='worm_gear' AND g.spec_starts IS NOT NULL AND g.spec_starts > 0,
                               CONCAT('×', g.spec_starts, '條'),
                               IF(g.Teeth IS NOT NULL AND g.Teeth > 0, CONCAT('×', g.Teeth, 'T'), '')),
                            IF(g.Face_Width IS NOT NULL AND g.Face_Width > 0,
                               CONCAT(' W', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Face_Width AS CHAR)))), ''),
                            IF(g.Pressure_Angle IS NOT NULL AND g.Pressure_Angle != '',
                               CONCAT(' PA', g.Pressure_Angle, '°'), ''),
                            IF(g.Helix_Direction IS NOT NULL AND g.Helix_Direction != '',
                               CONCAT(' ', g.Helix_Direction), ''),
                            IF(g.Helix_Angle IS NOT NULL AND g.Helix_Angle > 0,
                               CONCAT(' ', COALESCE(NULLIF(g.Helix_Angle_Str,''),
                               TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Helix_Angle AS CHAR)))), '°'), ''),
                            IF(g.Profile_Shift_X IS NOT NULL AND g.Profile_Shift_X != 0,
                               CONCAT(' X', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(g.Profile_Shift_X AS CHAR)))), '')
                        )
                    END
                    ORDER BY g.gear_id SEPARATOR ' / '
                ) AS gear_str
                FROM d_setting_gear g
                LEFT JOIN dict_gear_type dt ON dt.gear_type_id = g.Gear_Type
                WHERE g.d_setting_id IN ($ph)
                GROUP BY g.d_setting_id");
            $gq->execute($part);
            foreach ($gq->fetchAll(PDO::FETCH_ASSOC) as $gr) {
                $map[(int)$gr['d_setting_id']] = $gr['gear_str'];
            }
        }
    } catch (Exception $e) {}
    return $map;
}

function opa_auto_match(PDO $pdo, array $orders, array $bomsByOrder): array {
    // 未綁製令訂單的自動批次比對（僅供計算顯示，不寫入任何綁定）：
    // 同料號之下，訂單由新到舊、未綁定製令批次由新到舊，數量逐批往前分配（由最後出貨往前比對）。
    // 已綁定的訂單與已被綁定的製令都不參與比對，避免與現有綁定機制混淆。
    $needDids = [];
    foreach ($orders as $o) {
        if (empty($bomsByOrder[intval($o['Order_id'])])) $needDids[$o['d_id']] = true;
    }
    $needDids = array_keys($needDids);
    if (empty($needDids)) return [];

    // 該料號「全部歷史」訂單與製令（不限查詢區間，分配結果才不會隨查詢範圍漂移）
    $allOrd = opa_chunked_in($pdo,
        "SELECT Order_id, d_id, Qty, Order_date, parent_order_id, assembly_parent_order_id
         FROM order_track WHERE Qty > 0 AND d_id IN ({IN})",
        $needDids);
    $allBom = opa_chunked_in($pdo,
        "SELECT bom, d_id, sqty, o_order_id, Created_At FROM bom WHERE d_id IN ({IN})",
        $needDids);

    $boundOrderIds = [];
    $poolByDid = [];
    foreach ($allBom as $b) {
        if (!empty($b['o_order_id'])) { $boundOrderIds[intval($b['o_order_id'])] = true; continue; }
        $poolByDid[$b['d_id']][] = $b;
    }
    // 拆批子單有綁製令 → 視同母單已綁定（母單不再參與自動比對）
    foreach ($allOrd as $o) {
        if (!empty($o['parent_order_id']) && isset($boundOrderIds[intval($o['Order_id'])])) {
            $boundOrderIds[intval($o['parent_order_id'])] = true;
        }
    }
    $ordByDid = [];
    foreach ($allOrd as $o) {
        // 只有頂層訂單參與分配：拆批/組合件子單的數量已由母單代表，納入會重複扣批次
        if (!empty($o['parent_order_id']) || !empty($o['assembly_parent_order_id'])) continue;
        if (isset($boundOrderIds[intval($o['Order_id'])])) continue;
        $ordByDid[$o['d_id']][] = $o;
    }

    $result = [];   // order_id => [ ['bom'=>製令,'sqty'=>分配數量], ... ]
    foreach ($ordByDid as $did => $ords) {
        $pool = $poolByDid[$did] ?? [];
        if (empty($pool)) continue;
        usort($ords, function($a, $b) {
            $c = strcmp($b['Order_date'], $a['Order_date']);
            return $c !== 0 ? $c : (intval($b['Order_id']) <=> intval($a['Order_id']));
        });
        usort($pool, function($a, $b) {
            $c = strcmp(strval($b['Created_At'] ?? ''), strval($a['Created_At'] ?? ''));
            return $c !== 0 ? $c : strcmp($b['bom'], $a['bom']);
        });
        $pi = 0;
        $remain = max(0, intval($pool[0]['sqty']));
        foreach ($ords as $o) {
            $need = intval($o['Qty']);
            $alloc = [];
            while ($need > 0 && $pi < count($pool)) {
                if ($remain <= 0) {
                    $pi++;
                    if ($pi >= count($pool)) break;
                    $remain = max(0, intval($pool[$pi]['sqty']));
                    continue;
                }
                $take = min($need, $remain);
                $alloc[] = ['bom' => $pool[$pi]['bom'], 'sqty' => $take];
                $need -= $take;
                $remain -= $take;
            }
            if (!empty($alloc)) $result[intval($o['Order_id'])] = $alloc;
        }
    }
    return $result;
}

function opa_build_dataset(PDO $pdo, array $f): array {
    // 1. 訂單（有單價者才有毛利可算）
    // 排除拆批子單(parent_order_id)與組合件展開子單(assembly_parent_order_id)，避免營收重複計算：
    // 拆批母單保留原始總量(如6300)，子單(3×2100)只是交期拆分；子單綁的製令會併回母單算成本。
    $where  = ["ot.unit_price > 0", "ot.Qty > 0", "ot.parent_order_id IS NULL", "ot.assembly_parent_order_id IS NULL"];
    $params = [];
    if (!empty($f['date_from'])) { $where[] = "ot.Order_date >= ?"; $params[] = $f['date_from']; }
    if (!empty($f['date_to']))   { $where[] = "ot.Order_date <= ?"; $params[] = $f['date_to']; }
    if (!empty($f['client'])) {   // 客戶：中文名稱或客戶ID皆可模糊比對
        $where[] = "(ot.Client_name LIKE ? OR ot.Client_name_ID LIKE ?)";
        $ck = '%'.$f['client'].'%';
        array_push($params, $ck, $ck);
    }
    if (!empty($f['kw'])) {
        $where[] = "(ot.d_id LIKE ? OR ot.Order_oo LIKE ? OR ot.Specification LIKE ?)";
        $kw = '%'.$f['kw'].'%';
        array_push($params, $kw, $kw, $kw);
    }
    $st = $pdo->prepare("
        SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.d_id_ID, ot.Specification, ot.Client_name,
               ot.Qty, ot.Order_date, ot.unit_price, ot.currency, ot.exchange_rate
        FROM order_track ot
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ot.Order_date DESC, ot.Order_id DESC");
    $st->execute($params);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($orders)) return ['rows'=>[]];

    $orderIds = array_column($orders, 'Order_id');

    // 2. 綁定製令（含拆批子單所綁製令 → 併回母單）
    $childToParent = [];   // 拆批子單 Order_id => 母單 Order_id
    $childRows = opa_chunked_in($pdo,
        "SELECT Order_id, parent_order_id FROM order_track WHERE parent_order_id IN ({IN})",
        $orderIds);
    foreach ($childRows as $c) $childToParent[intval($c['Order_id'])] = intval($c['parent_order_id']);

    $lookupIds = array_merge($orderIds, array_keys($childToParent));
    $bomRows = opa_chunked_in($pdo,
        "SELECT bom, o_order_id, sqty FROM bom WHERE o_order_id IN ({IN})",
        array_map('strval', $lookupIds));
    $bomsByOrder = [];   // order_id => [ [bom, sqty], ... ]
    $allBoms = [];
    foreach ($bomRows as $b) {
        $boid = intval($b['o_order_id']);
        $boid = $childToParent[$boid] ?? $boid;   // 子單綁的製令歸到母單
        $bomsByOrder[$boid][] = $b;
        $allBoms[$b['bom']] = true;
    }
    // 2b. 未綁製令訂單 → 自動批次比對（僅計算，不寫入綁定）
    $autoMatch = opa_auto_match($pdo, $orders, $bomsByOrder);
    $autoByOrder = [];   // 僅保留本次查詢範圍內、未綁定訂單的比對結果
    foreach ($orders as $o) {
        $oid = intval($o['Order_id']);
        if (empty($bomsByOrder[$oid]) && isset($autoMatch[$oid])) {
            $autoByOrder[$oid] = $autoMatch[$oid];
            foreach ($autoMatch[$oid] as $ab) $allBoms[$ab['bom']] = true;
        }
    }
    $allBoms = array_keys($allBoms);

    // 3. 廠內加工商清單
    $inhouse = [];
    try { $inhouse = $pdo->query("SELECT maker_id_no FROM maker_list WHERE internal=1")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch (Exception $e) {}

    // 4. 各製令製程數（總數 / 廠內數）
    $procInfo = [];      // bom => ['total'=>n,'inhouse'=>n]
    if (!empty($allBoms)) {
        $rows = opa_chunked_in($pdo,
            "SELECT bi.bom,
                    COUNT(DISTINCT bi.bom_sn) AS total_proc,
                    COUNT(DISTINCT CASE WHEN COALESCE(m.internal,0)=1 THEN bi.bom_sn END) AS inhouse_proc
             FROM bom_ing bi
             LEFT JOIN maker_list m ON m.maker_id_no = bi.maker_id_no
             WHERE bi.bom IN ({IN})
             GROUP BY bi.bom", $allBoms);
        foreach ($rows as $r) $procInfo[$r['bom']] = ['total'=>intval($r['total_proc']), 'inhouse'=>intval($r['inhouse_proc'])];
    }

    // 5. 各製令加工類別：bom_sn 最小的製程為客供料 → 單製，否則全製
    $bomCat = [];        // bom => '單製'|'全製'
    if (!empty($allBoms)) {
        $kgSet = opa_kg_set($pdo);
        $rows = opa_chunked_in($pdo,
            "SELECT bom, process_no FROM (
                SELECT bi.bom, bi.process_no,
                       ROW_NUMBER() OVER (PARTITION BY bi.bom ORDER BY bi.bom_sn ASC, bi.bom_ing_fid ASC) AS rn
                FROM bom_ing bi WHERE bi.bom IN ({IN})
             ) t WHERE t.rn = 1", $allBoms);
        foreach ($rows as $r) $bomCat[$r['bom']] = in_array(intval($r['process_no']), $kgSet, true) ? '單製' : '全製';
    }

    // 6. 各製令實際外包成本：各製程(bom_sn)加權平均單價 → 加總
    $costInfo = [];      // bom => ['cost'=>x,'priced'=>n]
    if (!empty($allBoms)) {
        $notIn = '';
        if (!empty($inhouse)) {
            $notIn = " AND (t.maker_from IS NULL OR t.maker_from NOT IN (" . implode(',', array_fill(0, count($inhouse), '?')) . "))";
        }
        foreach (array_chunk($allBoms, 500) as $part) {
            $ph = implode(',', array_fill(0, count($part), '?'));
            $sql = "
                SELECT s.bom, SUM(s.avg_p) AS cost_per_pc, COUNT(*) AS priced_proc
                FROM (
                    SELECT t.bom, t.bom_sn,
                           SUM( IF(t.modified_unit_price>0, t.modified_unit_price, t.price)
                                * COALESCE(NULLIF(t.paid_qty,0), NULLIF(t.transfer_qty,0), NULLIF(t.sqty,0), 1) )
                         / SUM( COALESCE(NULLIF(t.paid_qty,0), NULLIF(t.transfer_qty,0), NULLIF(t.sqty,0), 1) ) AS avg_p
                    FROM bom_ing_transfer_log t
                    WHERE t.bom IN ($ph)
                      AND IF(t.modified_unit_price>0, t.modified_unit_price, COALESCE(t.price,0)) > 0
                      $notIn
                    GROUP BY t.bom, t.bom_sn
                ) s GROUP BY s.bom";
            $st2 = $pdo->prepare($sql);
            $st2->execute(array_merge($part, $inhouse));
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $costInfo[$r['bom']] = ['cost'=>floatval($r['cost_per_pc']), 'priced'=>intval($r['priced_proc'])];
            }
        }
    }

    // 7. 齒輪規格（依料號主檔 d_setting_id）
    $didIds = array_values(array_unique(array_filter(array_map(function($o){ return intval($o['d_id_ID'] ?? 0); }, $orders))));
    $gearMap = opa_gear_map($pdo, $didIds);

    // 8. 組每筆訂單列
    $rows = [];
    foreach ($orders as $o) {
        $oid  = intval($o['Order_id']);
        $qty  = intval($o['Qty']);
        $rate = floatval($o['exchange_rate'] ?? 0);
        if ($rate <= 0) $rate = 1;
        $unitPrice = floatval($o['unit_price']);
        $revenue   = $qty * $unitPrice * $rate;

        $boms = $bomsByOrder[$oid] ?? [];
        $isAuto = false;
        if (empty($boms) && !empty($autoByOrder[$oid])) {   // 自動比對批次（sqty=分配數量，作成本加權）
            $boms = $autoByOrder[$oid];
            $isAuto = true;
        }
        $bomNames = array_column($boms, 'bom');

        // 加工類別：全部製令同類別→該類別；不同→混合；無製令→未綁製令
        if (empty($boms)) {
            $category = '未綁製令';
        } else {
            $cats = array_values(array_unique(array_map(function($b) use ($bomCat) { return $bomCat[$b['bom']] ?? '全製'; }, $boms)));
            $category = count($cats) === 1 ? $cats[0] : '混合';
        }

        // 成本：多製令以 bom.sqty 加權平均單顆成本
        $wSum = 0; $wCost = 0; $priced = 0; $total = 0; $inh = 0; $hasAnyCost = false;
        foreach ($boms as $b) {
            $bn = $b['bom'];
            $w  = max(1, intval($b['sqty']));
            $total += $procInfo[$bn]['total']   ?? 0;
            $inh   += $procInfo[$bn]['inhouse'] ?? 0;
            if (isset($costInfo[$bn])) {
                $hasAnyCost = true;
                $wCost += $costInfo[$bn]['cost'] * $w;
                $wSum  += $w;
                $priced += $costInfo[$bn]['priced'];
            }
        }
        $costPc = ($hasAnyCost && $wSum > 0) ? $wCost / $wSum : null;

        $external = max(0, $total - $inh);
        if ($costPc === null) {
            $status = 'none';       // 無製令或無任何計價資料
        } elseif ($inh > 0 || $priced < $external) {
            $status = 'partial';    // 有成本但不完整（廠內未計 或 外包尚有製程未計價）
        } else {
            $status = 'full';
        }

        $costTotal = $costPc !== null ? $costPc * $qty : null;
        $margin    = $costTotal !== null ? $revenue - $costTotal : null;
        $marginRate = ($margin !== null && $revenue > 0) ? $margin / $revenue * 100 : null;

        $rows[] = [
            'order_id'    => $oid,
            'order_oo'    => $o['Order_oo'],
            'order_date'  => $o['Order_date'],
            'client'      => $o['Client_name'],
            'd_id'        => $o['d_id'],
            'spec'        => $o['Specification'],
            'gear'        => $gearMap[intval($o['d_id_ID'] ?? 0)] ?? '',
            'category'    => $category,
            'qty'         => $qty,
            'currency'    => $o['currency'],
            'unit_price'  => $unitPrice,
            'revenue'     => round($revenue, 2),
            'cost_pc'     => $costPc !== null ? round($costPc, 4) : null,
            'cost_total'  => $costTotal !== null ? round($costTotal, 2) : null,
            'margin'      => $margin !== null ? round($margin, 2) : null,
            'margin_rate' => $marginRate !== null ? round($marginRate, 2) : null,
            'cost_status' => $status,
            'auto_matched'=> $isAuto,
            'boms'        => $bomNames,
            'proc_total'  => $total,
            'proc_priced' => $priced,
            'proc_inhouse'=> $inh,
        ];
    }
    return ['rows'=>$rows];
}

function opa_summary(array $rows): array {
    $s = ['orders'=>0,'revenue'=>0,'full'=>0,'partial'=>0,'none'=>0,
          'costed_orders'=>0,'costed_revenue'=>0,'costed_cost'=>0,'costed_margin'=>0,'avg_margin_rate'=>null,'loss_orders'=>0];
    foreach ($rows as $r) {
        $s['orders']++;
        $s['revenue'] += $r['revenue'];
        $s[$r['cost_status']]++;
        if ($r['cost_status'] !== 'none') {
            $s['costed_orders']++;
            $s['costed_revenue'] += $r['revenue'];
            $s['costed_cost']    += $r['cost_total'];
            $s['costed_margin']  += $r['margin'];
            if ($r['margin'] < 0) $s['loss_orders']++;
        }
    }
    if ($s['costed_revenue'] > 0) $s['avg_margin_rate'] = round($s['costed_margin'] / $s['costed_revenue'] * 100, 2);
    foreach (['revenue','costed_revenue','costed_cost','costed_margin'] as $k) $s[$k] = round($s[$k], 2);
    return $s;
}

function opa_part_view(array $rows): array {
    // 料號×加工類別 彙總 + ABC 柏拉圖分級（以全部符合條件資料計算；同料號單製/全製分開列）
    $parts = [];
    foreach ($rows as $r) {
        $k = $r['d_id'] . '|' . $r['category'];
        if (!isset($parts[$k])) {
            $parts[$k] = ['d_id'=>$r['d_id'],'category'=>$r['category'],'spec'=>$r['spec'],'gear'=>$r['gear'],
                          'clients'=>[],'orders'=>0,'qty'=>0,'revenue'=>0,
                          'costed_orders'=>0,'costed_revenue'=>0,'costed_cost'=>0];
        }
        $p = &$parts[$k];
        $p['orders']++;
        $p['qty']     += $r['qty'];
        $p['revenue'] += $r['revenue'];
        if ($r['gear'] && !$p['gear']) $p['gear'] = $r['gear'];
        if ($r['client'] && !in_array($r['client'], $p['clients'], true)) $p['clients'][] = $r['client'];
        if ($r['cost_status'] !== 'none') {
            $p['costed_orders']++;
            $p['costed_revenue'] += $r['revenue'];
            $p['costed_cost']    += $r['cost_total'];
        }
        unset($p);
    }
    $parts = array_values($parts);
    // ABC：依營收由大到小累計
    usort($parts, function($a,$b){ return $b['revenue'] <=> $a['revenue']; });
    $totalRev = array_sum(array_column($parts, 'revenue'));
    $cum = 0;
    foreach ($parts as $i => &$p) {
        $cum += $p['revenue'];
        $p['cum_pct'] = $totalRev > 0 ? round($cum / $totalRev * 100, 2) : 0;
        $p['abc'] = $p['cum_pct'] <= 80 ? 'A' : ($p['cum_pct'] <= 95 ? 'B' : 'C');
        $p['rank'] = $i + 1;
        $p['avg_price'] = $p['qty'] > 0 ? round($p['revenue'] / $p['qty'], 4) : null;
        $p['margin'] = $p['costed_revenue'] > 0 ? round($p['costed_revenue'] - $p['costed_cost'], 2) : null;
        $p['margin_rate'] = $p['costed_revenue'] > 0 ? round(($p['costed_revenue'] - $p['costed_cost']) / $p['costed_revenue'] * 100, 2) : null;
        $p['revenue'] = round($p['revenue'], 2);
        $p['costed_revenue'] = round($p['costed_revenue'], 2);
        $p['costed_cost'] = round($p['costed_cost'], 2);
        $p['clients'] = implode('、', array_slice($p['clients'], 0, 3)) . (count($p['clients']) > 3 ? '…' : '');
    }
    unset($p);
    return $parts;
}

function opa_sort(array &$rows, string $sort, string $dir): void {
    $desc = ($dir === 'desc');
    usort($rows, function($a, $b) use ($sort, $desc) {
        $va = $a[$sort] ?? null; $vb = $b[$sort] ?? null;
        if ($va === null && $vb === null) return 0;
        if ($va === null) return 1;   // null 一律排最後
        if ($vb === null) return -1;
        $c = is_numeric($va) && is_numeric($vb) ? ($va <=> $vb) : strcmp(strval($va), strval($vb));
        return $desc ? -$c : $c;
    });
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$has_access) { echo json_encode(['success'=>false,'error'=>'無權限']); exit; }

    $action = $_GET['action'];
    $f = [
        'date_from' => trim($_GET['date_from'] ?? ''),
        'date_to'   => trim($_GET['date_to'] ?? ''),
        'client'    => trim($_GET['client'] ?? ''),
        'kw'        => trim($_GET['kw'] ?? ''),
    ];
    $view       = ($_GET['view'] ?? 'order') === 'part' ? 'part' : 'order';
    $costFilter = $_GET['cost_filter'] ?? 'all';

    try {
        /* ── 成本明細（單一訂單各製程計價狀況） ── */
        if ($action === 'cost_detail') {
            $oid = intval($_GET['order_id'] ?? 0);
            $st = $pdo->prepare("SELECT Order_id, Order_oo, d_id, Qty, unit_price FROM order_track WHERE Order_id=?");
            $st->execute([$oid]);
            $order = $st->fetch(PDO::FETCH_ASSOC);
            if (!$order) { echo json_encode(['success'=>false,'error'=>'找不到訂單']); exit; }

            // 綁定製令：含母單本身與拆批子單所綁的製令
            $st = $pdo->prepare("
                SELECT b.bom, b.sqty FROM bom b
                WHERE b.o_order_id = ?
                   OR b.o_order_id IN (SELECT CAST(Order_id AS CHAR) FROM order_track WHERE parent_order_id = ?)");
            $st->execute([strval($oid), $oid]);
            $boms = $st->fetchAll(PDO::FETCH_ASSOC);

            // 未綁定訂單：改用前端傳來的自動比對製令清單
            $isAutoDetail = false;
            $bomsParam = trim($_GET['boms'] ?? '');
            if (empty($boms) && $bomsParam !== '') {
                $names = array_values(array_filter(array_map('trim', explode(',', $bomsParam))));
                if (!empty($names)) {
                    $ph = implode(',', array_fill(0, count($names), '?'));
                    $st = $pdo->prepare("SELECT bom, sqty FROM bom WHERE bom IN ($ph)");
                    $st->execute($names);
                    $boms = $st->fetchAll(PDO::FETCH_ASSOC);
                    $isAutoDetail = true;
                }
            }

            $inhouse = [];
            try { $inhouse = $pdo->query("SELECT maker_id_no FROM maker_list WHERE internal=1")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch (Exception $e) {}
            $kgSet = opa_kg_set($pdo);

            $detail = [];
            foreach ($boms as $b) {
                $bn = $b['bom'];
                // 製程清單（依 bom_sn 分組）
                $ps = $pdo->prepare("
                    SELECT bi.bom_sn,
                           MIN(bi.process_no) AS process_no,
                           MIN(pn.ProcessName) AS process_name,
                           GROUP_CONCAT(DISTINCT COALESCE(NULLIF(bi.maker_id,''),'?') SEPARATOR '、') AS makers,
                           MAX(COALESCE(m.internal,0)) AS internal
                    FROM bom_ing bi
                    LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                    LEFT JOIN maker_list m ON m.maker_id_no = bi.maker_id_no
                    WHERE bi.bom = ?
                    GROUP BY bi.bom_sn
                    ORDER BY bi.bom_sn ASC");
                $ps->execute([$bn]);
                $procs = $ps->fetchAll(PDO::FETCH_ASSOC);

                // 外包計價彙總
                $notIn = '';
                $extra = [];
                if (!empty($inhouse)) {
                    $notIn = " AND (t.maker_from IS NULL OR t.maker_from NOT IN (" . implode(',', array_fill(0, count($inhouse), '?')) . "))";
                    $extra = $inhouse;
                }
                $ts = $pdo->prepare("
                    SELECT t.bom_sn,
                           SUM( IF(t.modified_unit_price>0, t.modified_unit_price, t.price)
                                * COALESCE(NULLIF(t.paid_qty,0), NULLIF(t.transfer_qty,0), NULLIF(t.sqty,0), 1) )
                         / SUM( COALESCE(NULLIF(t.paid_qty,0), NULLIF(t.transfer_qty,0), NULLIF(t.sqty,0), 1) ) AS avg_p,
                           SUM( COALESCE(NULLIF(t.paid_qty,0), NULLIF(t.transfer_qty,0), NULLIF(t.sqty,0), 1) ) AS qty_sum,
                           COUNT(*) AS cnt
                    FROM bom_ing_transfer_log t
                    WHERE t.bom = ?
                      AND IF(t.modified_unit_price>0, t.modified_unit_price, COALESCE(t.price,0)) > 0
                      $notIn
                    GROUP BY t.bom_sn");
                $ts->execute(array_merge([$bn], $extra));
                $tMap = [];
                foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $tr) $tMap[$tr['bom_sn']] = $tr;

                $plist = [];
                $costSum = 0;
                foreach ($procs as $p) {
                    $sn = $p['bom_sn'];
                    $t  = $tMap[$sn] ?? null;
                    $avg = $t ? round(floatval($t['avg_p']), 4) : null;
                    if ($avg !== null) $costSum += $avg;
                    $plist[] = [
                        'bom_sn'      => intval($sn),
                        'process_no'  => intval($p['process_no']),
                        'process_name'=> $p['process_name'] ?: ('#' . $p['process_no']),
                        'makers'      => $p['makers'],
                        'internal'    => intval($p['internal']) === 1,
                        'is_kg'       => in_array(intval($p['process_no']), $kgSet, true),
                        'avg_price'   => $avg,
                        'qty_sum'     => $t ? intval($t['qty_sum']) : null,
                        'cnt'         => $t ? intval($t['cnt']) : 0,
                    ];
                }
                $detail[] = ['bom'=>$bn, 'sqty'=>intval($b['sqty']), 'cost_per_pc'=>round($costSum, 4), 'processes'=>$plist];
            }
            echo json_encode(['success'=>true, 'order'=>$order, 'boms'=>$detail, 'auto'=>$isAutoDetail]);
            exit;
        }

        $ds   = opa_build_dataset($pdo, $f);
        $rows = $ds['rows'];

        if ($action === 'list' || $action === 'export_csv') {
            $summary = opa_summary($rows);   // 統計卡永遠以「全部符合日期/客戶/關鍵字」為準

            if ($view === 'part') {
                $data = opa_part_view($rows);
                $sort = $_GET['sort'] ?? 'revenue';
                $dir  = $_GET['dir']  ?? 'desc';
                $allowed = ['rank','d_id','category','orders','qty','revenue','cum_pct','avg_price','margin','margin_rate','abc','costed_orders'];
                if (!in_array($sort, $allowed, true)) $sort = 'revenue';
                if (!($sort === 'revenue' && $dir === 'desc')) opa_sort($data, $sort, $dir === 'asc' ? 'asc' : 'desc');
            } else {
                if ($costFilter !== 'all') {
                    if ($costFilter === 'costed') {
                        $rows = array_values(array_filter($rows, fn($r) => $r['cost_status'] !== 'none'));
                    } else {
                        $rows = array_values(array_filter($rows, fn($r) => $r['cost_status'] === $costFilter));
                    }
                }
                $sort = $_GET['sort'] ?? 'order_date';
                $dir  = $_GET['dir']  ?? 'desc';
                $allowed = ['order_date','order_oo','client','d_id','category','qty','unit_price','revenue','cost_pc','cost_total','margin','margin_rate','cost_status'];
                if (!in_array($sort, $allowed, true)) $sort = 'order_date';
                opa_sort($rows, $sort, $dir === 'asc' ? 'asc' : 'desc');
                $data = $rows;
            }

            if ($action === 'export_csv') {
                // 匯出：全部符合條件資料（不分頁）
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="order_profit_' . ($view === 'part' ? 'part_' : '') . date('Ymd_His') . '.csv"');
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM 讓 Excel 正確顯示中文
                if ($view === 'part') {
                    fputcsv($out, ['排名','ABC','料號','類別','齒輪規格','主要客戶','訂單數','總數量','營收合計','累計營收佔比%','平均售價','有成本訂單數','涵蓋營收','涵蓋成本','毛利','毛利率%']);
                    foreach ($data as $p) {
                        fputcsv($out, [$p['rank'],$p['abc'],$p['d_id'],$p['category'],$p['gear'],$p['clients'],$p['orders'],$p['qty'],$p['revenue'],$p['cum_pct'],
                                       $p['avg_price'],$p['costed_orders'],$p['costed_revenue'],$p['costed_cost'],$p['margin'],$p['margin_rate']]);
                    }
                } else {
                    fputcsv($out, ['訂單日期','訂單號','客戶','料號','規格','齒輪規格','類別','數量','幣別','單價','營收','單顆成本','成本合計','毛利','毛利率%','成本狀態','製令','製令來源','總製程','已計價製程','廠內製程']);
                    $stMap = ['full'=>'完整','partial'=>'部分','none'=>'無'];
                    foreach ($data as $r) {
                        $src = $r['auto_matched'] ? '自動比對' : (!empty($r['boms']) ? '綁定' : '無');
                        fputcsv($out, [$r['order_date'],$r['order_oo'],$r['client'],$r['d_id'],$r['spec'],$r['gear'],$r['category'],$r['qty'],
                                       $r['currency'] ?: 'NTD',$r['unit_price'],$r['revenue'],$r['cost_pc'],$r['cost_total'],
                                       $r['margin'],$r['margin_rate'],$stMap[$r['cost_status']],implode(' ', $r['boms']),$src,
                                       $r['proc_total'],$r['proc_priced'],$r['proc_inhouse']]);
                    }
                }
                fclose($out);
                exit;
            }

            // 分頁（後端切頁；統計已於上方以全量算完）
            $page = max(1, intval($_GET['page'] ?? 1));
            $size = intval($_GET['size'] ?? 10);
            if (!in_array($size, [5,10,20,50,100000], true)) $size = 10;
            $totalRows = count($data);
            $paged = array_slice($data, ($page - 1) * $size, $size);

            echo json_encode([
                'success' => true,
                'view'    => $view,
                'summary' => $summary,
                'total'   => $totalRows,
                'page'    => $page,
                'size'    => $size,
                'rows'    => $paged,
            ]);
            exit;
        }

        echo json_encode(['success'=>false,'error'=>'未知動作']);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// 客戶下拉清單（供篩選欄 datalist）
$clientList = [];
if ($has_access) {
    try { $clientList = $pdo->query("SELECT DISTINCT Client_name FROM order_track WHERE Client_name IS NOT NULL AND Client_name<>'' ORDER BY Client_name")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>訂單毛利分析</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number]{ -moz-appearance:textfield; appearance:textfield; }

        :root { --primary-color:#2A3F54; --accent-color:#1ABB9C; --bg-color:#F4F7FC; --card-bg:#FFF; }
        body { background-color:var(--bg-color); font-family:"Segoe UI","Roboto",Arial,sans-serif; color:#495057; }
        .right_col { background-color:var(--bg-color) !important; }

        .stats-container { display:flex; gap:12px; margin-bottom:15px; flex-wrap:wrap; }
        .stat-card { flex:1; min-width:150px; background:var(--card-bg); border-radius:8px; padding:13px 15px;
            box-shadow:0 2px 5px rgba(0,0,0,.05); border-left:4px solid transparent; cursor:pointer;
            transition:transform .1s, box-shadow .1s; position:relative; }
        .stat-card:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(0,0,0,.1); }
        .stat-card.active { transform:scale(1.02); z-index:1; }
        .stat-card .stat-value { font-size:21px; font-weight:800; color:var(--primary-color); white-space:nowrap; }
        .stat-card .stat-label { font-size:12px; color:#888; font-weight:600; }
        .stat-card .stat-sub { font-size:11px; color:#aaa; }
        .stat-card.c-all    { border-left-color:#5B8DEF; }
        .stat-card.c-all.active { box-shadow:0 0 0 3px #5B8DEF; }
        .stat-card.c-full   { border-left-color:#1ABB9C; }
        .stat-card.c-full.active { box-shadow:0 0 0 3px #1ABB9C; }
        .stat-card.c-part   { border-left-color:#F39C12; }
        .stat-card.c-part.active { box-shadow:0 0 0 3px #F39C12; }
        .stat-card.c-none   { border-left-color:#95A5A6; }
        .stat-card.c-none.active { box-shadow:0 0 0 3px #95A5A6; }
        .stat-card.c-rate   { border-left-color:#8e44ad; cursor:default; }

        .filter-bar { background:#fff; padding:10px; border-radius:8px; margin-bottom:15px;
            display:flex; gap:8px; align-items:center; flex-wrap:wrap; box-shadow:0 2px 5px rgba(0,0,0,.05); }
        .main-card { background:var(--card-bg); border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.05); padding:15px; }
        .table-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }

        table.opa-table thead th { background:#F8F9FA; color:#555; font-weight:700; border-bottom:2px solid #E9ECEF;
            padding:9px 6px; font-size:13px; white-space:nowrap; cursor:pointer; user-select:none; }
        table.opa-table thead th.no-sort { cursor:default; }
        table.opa-table thead th .sort-ind { color:#5B8DEF; margin-left:2px; }
        table.opa-table tbody td { padding:7px 6px; vertical-align:middle; border-bottom:1px solid #F1F3F5; font-size:13px; }
        table.opa-table tbody tr:hover { background:#FAFBFE; }
        td.num, th.num { text-align:right; }

        .part-link { color:#1a7abf; cursor:pointer; }
        .part-link:hover { text-decoration:underline; }
        .gear-line { font-size:10px; color:#8e44ad; line-height:1.2; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; }
        .cost-link { cursor:pointer; border-bottom:1px dashed #5B8DEF; }
        .cost-link:hover { color:#5B8DEF; }
        .drill-link { cursor:pointer; border-bottom:1px dashed #5B8DEF; }
        .drill-link:hover { color:#5B8DEF; }

        .badge-st { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; cursor:help; }
        .st-full    { background:#E8F8F3; color:#0e8c73; }
        .st-partial { background:#FEF5E7; color:#b9770e; }
        .st-none    { background:#F0F2F4; color:#8a94a0; }
        .mr-pos  { color:#0e8c73; font-weight:700; }
        .mr-warn { color:#d68910; font-weight:700; }
        .mr-neg  { color:#c0392b; font-weight:700; }
        .abc-badge { display:inline-block; width:22px; height:22px; line-height:22px; text-align:center;
            border-radius:50%; color:#fff; font-weight:800; font-size:12px; }
        .abc-A { background:#E74C3C; } .abc-B { background:#F39C12; } .abc-C { background:#95A5A6; }

        .cat-badge { display:inline-block; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:700; white-space:nowrap; }
        .cat-full   { background:#EAF2FD; color:#1a5cb0; }      /* 全製 */
        .cat-single { background:#FDF0E7; color:#c05f10; }      /* 單製 */
        .cat-mix    { background:#F3EAFD; color:#7d3cb5; }      /* 混合 */
        .cat-none   { background:#F0F2F4; color:#8a94a0; }      /* 未綁製令 */

        .view-toggle .btn.active { background:var(--primary-color); color:#fff; }
        .role-hint { color:#888; font-size:12px; cursor:pointer; }
        .no-access-box { background:#fff; border-radius:8px; padding:60px 20px; text-align:center; margin-top:40px; }
        .note-box { font-size:12px; color:#8a94a0; margin-top:8px; line-height:1.7; }

        table.cd-table { width:100%; border-collapse:collapse; margin-bottom:12px; }
        table.cd-table th { background:#F8F9FA; font-size:12px; padding:5px 6px; border-bottom:2px solid #E9ECEF; white-space:nowrap; }
        table.cd-table td { font-size:12px; padding:4px 6px; border-bottom:1px solid #F1F3F5; }
        table.cd-table tr.cd-skip td { color:#b0b8c0; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
<?php if (!$has_access): ?>
      <div class="no-access-box">
          <i class="fa fa-lock" style="font-size:40px;color:#e74c3c;"></i>
          <h3 style="margin-top:15px;">請先申請權限</h3>
          <p style="color:#888;">您目前沒有「訂單毛利分析」功能的使用權限，請聯絡管理者至「使用者權限管理」頁面指派角色。</p>
      </div>
<?php else: ?>
      <div class="page-title">
        <div class="title_left"><h3>訂單毛利分析 <small style="font-size:13px;color:#aaa;">試作版</small></h3></div>
        <div class="title_right" style="text-align:right; padding-top:12px;">
          <span class="role-hint" id="roleHint">
            <i class="fa fa-user"></i> 訂單毛利分析使用者
            <i class="fa fa-question-circle" style="margin-left:4px;"></i>
          </span>
        </div>
      </div>
      <div class="clearfix"></div>

      <div class="stats-container">
        <div class="stat-card c-all active" data-costfilter="all">
          <div class="stat-value" id="cardOrders">–</div>
          <div class="stat-label">訂單數（全部）</div>
          <div class="stat-sub" id="cardRevenue">營收 –</div>
        </div>
        <div class="stat-card c-full" data-costfilter="full">
          <div class="stat-value" id="cardFull">–</div>
          <div class="stat-label">成本完整</div>
          <div class="stat-sub">外包製程全數已計價</div>
        </div>
        <div class="stat-card c-part" data-costfilter="partial">
          <div class="stat-value" id="cardPartial">–</div>
          <div class="stat-label">成本部分</div>
          <div class="stat-sub">尚有製程未計價/廠內未計</div>
        </div>
        <div class="stat-card c-none" data-costfilter="none">
          <div class="stat-value" id="cardNone">–</div>
          <div class="stat-label">無成本資料</div>
          <div class="stat-sub">未綁製令或尚未請款</div>
        </div>
        <div class="stat-card c-rate">
          <div class="stat-value" id="cardMarginRate">–</div>
          <div class="stat-label">平均毛利率（有成本訂單）</div>
          <div class="stat-sub" id="cardLoss">虧損訂單 –</div>
        </div>
      </div>

      <div class="filter-bar">
        <label style="margin:0;font-size:12px;color:#888;">訂單日期</label>
        <input type="date" id="fDateFrom" class="form-control input-sm eg-in" style="width:135px;" max="9999-12-31">
        <span style="color:#aaa;">～</span>
        <input type="date" id="fDateTo" class="form-control input-sm eg-in" style="width:135px;" max="9999-12-31">
        <input type="text" id="fClient" list="clientList" class="form-control input-sm eg-in eg-live" placeholder="客戶名稱/ID（即時篩選）" style="width:160px;">
        <datalist id="clientList">
          <?php foreach ($clientList as $c): ?><option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"></option><?php endforeach; ?>
        </datalist>
        <input type="text" id="fKw" class="form-control input-sm eg-in eg-live" placeholder="料號 / 訂單號 / 規格（即時篩選）" style="width:190px;">
        <button class="btn btn-primary btn-sm" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
        <div class="btn-group view-toggle" style="margin-left:6px;">
          <button class="btn btn-default btn-sm active" data-view="order">訂單明細</button>
          <button class="btn btn-default btn-sm" data-view="part">料號彙總（ABC）</button>
        </div>
        <div style="margin-left:auto; display:flex; gap:8px;">
          <button class="btn btn-info btn-sm" id="btnExportCsv"><i class="fa fa-file-excel-o"></i> 轉 CSV</button>
          <button class="btn btn-info btn-sm" id="btnPrint"><i class="fa fa-print"></i> 列印 / PDF</button>
        </div>
      </div>

      <div class="main-card">
        <div class="table-toolbar">
          <div style="color:#888;font-size:12px;" id="viewHint">
            成本口徑：各製程<b>實際發包單價</b>加權平均加總（外包實績）；廠內製程不計成本、以「部分」標示。
            點<b>料號</b>開圖面、點<b>單顆成本</b>看各製程計價明細、點欄位標題排序。
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <div id="pageInfo" style="color:#888;font-size:12px;"></div>
            <select id="pageSizeSelect" class="form-control input-sm" style="width:80px;">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="20">20</option>
              <option value="50">50</option>
            </select>
            <button class="btn btn-default btn-xs" id="btnPrevPage"><i class="fa fa-chevron-left"></i></button>
            <span id="pageNum">1</span>
            <button class="btn btn-default btn-xs" id="btnNextPage"><i class="fa fa-chevron-right"></i></button>
          </div>
        </div>
        <div style="overflow-x:auto;width:100%;">
          <table class="table opa-table" id="opaTable">
            <thead id="opaThead"></thead>
            <tbody id="opaTbody"><tr><td colspan="14" class="text-center text-muted">載入中...</td></tr></tbody>
          </table>
        </div>
        <div class="note-box">
          ．單顆成本＝該訂單綁定製令的各製程(bom_sn)發包單價（修改後單價優先）以請款數量加權平均後加總；排除廠內加工商。<br>
          ．「無成本資料」多為：製令仍在進行中尚未請款、或該料號無任何可比對批次。「部分」表示尚有外包製程未計價或含廠內製程（廠內暫不計成本）。<br>
          ．未綁定製令的訂單會<b>自動比對批次</b>（標「≈」）：同料號之下，訂單由新到舊、未綁定製令由新到舊，依數量逐批往前分配；僅供計算顯示，<b>不寫入任何綁定</b>。<br>
          ．<b>拆批訂單只列母單一筆</b>（營收以母單總量計，不重複）；拆批子單綁定的製令自動併回母單算成本。組合件展開子單亦不列出。<br>
          ．加工類別：製令第一道製程（bom_sn 最小）為「客供料」＝<b>單製</b>，否則＝<b>全製</b>；同一料號單製/全製資料在料號彙總分開列。<br>
          ．累計佔比＝料號彙總依「查詢日期範圍內」營收由大到小排序後的累計營收百分比（柏拉圖），≤80%＝A、≤95%＝B、其餘＝C。<br>
          ．統計卡與彙總一律以「全部符合條件資料」於後端計算，非僅當前頁。
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
</div>

<!-- 權限說明 Modal -->
<div class="modal fade" id="roleModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:520px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-question-circle"></i> 訂單毛利分析 — 角色權限說明</h4></div>
      <div class="modal-body" style="font-size:13px;line-height:1.9;">
        <p><b>訂單毛利分析使用者</b>：可檢視毛利分析、切換檢視、匯出 CSV / 列印。</p>
        <p><b>管理者</b>：固定擁有全部權限。</p>
        <p style="color:#888;">毛利屬敏感資料，未被指派角色者無法開啟本頁。角色指派請至「權限設定」頁的「訂單毛利分析」區塊。</p>
      </div>
    </div>
  </div>
</div>

<!-- 成本明細 Modal -->
<div class="modal fade" id="costModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:720px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-calculator"></i> 成本明細 <small id="cdSub" style="color:#888;"></small></h4></div>
      <div class="modal-body" id="cdBody" style="max-height:70vh;overflow-y:auto;">載入中...</div>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<?php if ($has_access): ?>
<script>
(function(){
    var state = {
        view: 'order', costFilter: 'all',
        sort: 'order_date', dir: 'desc',
        page: 1, size: 10,
        lastSummary: null
    };

    // 預設查詢近 12 個月
    function fmtD(d){ return d.toISOString().slice(0,10); }
    var today = new Date(), yearAgo = new Date();
    yearAgo.setFullYear(yearAgo.getFullYear() - 1);
    $('#fDateFrom').val(fmtD(yearAgo));
    $('#fDateTo').val(fmtD(today));

    function nfmt(v, dec){
        if (v === null || v === undefined || v === '' || isNaN(v)) return '–';
        var n = parseFloat(v);
        var s = n.toLocaleString('zh-TW', {minimumFractionDigits:0, maximumFractionDigits:(dec===undefined?2:dec)});
        return s;
    }
    function esc(s){ return $('<span>').text(s == null ? '' : s).html(); }
    function mrClass(r){ if (r === null || r === undefined) return ''; return r < 0 ? 'mr-neg' : (r < 15 ? 'mr-warn' : 'mr-pos'); }

    var CAT_CLS = {'全製':'cat-full','單製':'cat-single','混合':'cat-mix','未綁製令':'cat-none'};
    function catBadge(c){
        return '<span class="cat-badge ' + (CAT_CLS[c] || 'cat-none') + '">' + esc(c || '–') + '</span>';
    }

    function params(extra){
        var p = {
            date_from: $('#fDateFrom').val(), date_to: $('#fDateTo').val(),
            client: $.trim($('#fClient').val()), kw: $.trim($('#fKw').val()),
            view: state.view, cost_filter: state.costFilter,
            sort: state.sort, dir: state.dir, page: state.page, size: state.size
        };
        return $.extend(p, extra || {});
    }

    var HEADS = {
        order: [
            ['order_date','訂單日期'], ['order_oo','訂單號'], ['client','客戶'], ['d_id','料號'],
            ['category','類別'],
            ['qty','數量','num'], ['unit_price','單價','num'], ['revenue','營收','num'],
            ['cost_pc','單顆成本','num'], ['cost_total','成本合計','num'], ['margin','毛利','num'], ['margin_rate','毛利率','num'],
            ['cost_status','成本狀態'], [null,'製令']
        ],
        part: [
            ['rank','#','num'], ['abc','ABC'], ['d_id','料號'], ['category','類別'], [null,'主要客戶'],
            ['orders','訂單數','num'], ['qty','總數量','num'], ['revenue','營收合計','num'],
            ['cum_pct','累計佔比','num'], ['avg_price','平均售價','num'],
            ['margin','毛利(涵蓋)','num'], ['margin_rate','毛利率','num'], ['costed_orders','成本資料','num']
        ]
    };

    function renderHead(){
        var h = '<tr>';
        HEADS[state.view].forEach(function(c){
            var cls = (c[2] ? c[2] : '') + (c[0] ? '' : ' no-sort');
            var ind = (c[0] && c[0] === state.sort) ? ' <span class="sort-ind">' + (state.dir === 'asc' ? '▲' : '▼') + '</span>' : '';
            h += '<th class="' + cls + '" data-sort="' + (c[0] || '') + '">' + c[1] + ind + '</th>';
        });
        $('#opaThead').html(h + '</tr>');
    }

    function statusBadge(r){
        var tip = '外包製程 ' + r.proc_priced + '/' + Math.max(0, r.proc_total - r.proc_inhouse) + ' 已計價'
                + (r.proc_inhouse > 0 ? '；廠內 ' + r.proc_inhouse + ' 製程未計成本' : '');
        if (r.cost_status === 'full')    return '<span class="badge-st st-full" title="' + tip + '">完整</span>';
        if (r.cost_status === 'partial') return '<span class="badge-st st-partial" title="' + tip + '">部分</span>';
        var tip2 = r.boms.length ? '製令進行中，尚無計價資料' : '訂單未綁定製令，且無可自動比對的批次';
        return '<span class="badge-st st-none" title="' + tip2 + '">無</span>';
    }

    function partCell(d_id, spec, gear){
        // 料號：點擊開圖面跳窗（bom_viewer）；下方顯示齒輪規格
        return '<td title="' + esc(spec || '') + '" style="max-width:230px;">'
             + '<span class="part-link" data-did="' + esc(d_id) + '" title="點擊開啟圖面">' + esc(d_id) + '</span>'
             + (gear ? '<div class="gear-line" title="' + esc(gear) + '"><i class="fa fa-gear" style="font-size:9px;"></i> ' + esc(gear) + '</div>' : '')
             + '</td>';
    }

    function renderRows(rows){
        var h = '';
        if (!rows.length) {
            $('#opaTbody').html('<tr><td colspan="14" class="text-center text-muted">查無資料</td></tr>');
            return;
        }
        if (state.view === 'order') {
            rows.forEach(function(r){
                var costCell;
                if (r.boms.length) {
                    costCell = '<span class="cost-link" data-oid="' + r.order_id + '" data-oo="' + esc(r.order_oo) + '" data-did="' + esc(r.d_id) + '"'
                             + ' data-boms="' + esc(r.boms.join(',')) + '" data-auto="' + (r.auto_matched ? 1 : 0) + '"'
                             + ' title="點擊看各製程計價明細">' + nfmt(r.cost_pc) + '</span>';
                } else {
                    costCell = '–';
                }
                var bomsCell = r.boms.length
                    ? (r.auto_matched ? '<span title="自動比對批次（未綁定，由最新出貨往前依數量分配）" style="color:#b9770e;">≈ ' + esc(r.boms.join(' ')) + '</span>'
                                      : esc(r.boms.join(' ')))
                    : '';
                var catCell = r.auto_matched
                    ? '<span title="類別依自動比對批次判定">' + catBadge(r.category) + '<small style="color:#b9770e;">≈</small></span>'
                    : catBadge(r.category);
                h += '<tr>'
                  + '<td>' + esc(r.order_date) + '</td>'
                  + '<td>' + esc(r.order_oo) + '</td>'
                  + '<td>' + esc(r.client) + '</td>'
                  + partCell(r.d_id, r.spec, r.gear)
                  + '<td>' + catCell + '</td>'
                  + '<td class="num">' + nfmt(r.qty, 0) + '</td>'
                  + '<td class="num">' + nfmt(r.unit_price) + (r.currency ? ' <small style="color:#aaa;">' + esc(r.currency) + '</small>' : '') + '</td>'
                  + '<td class="num">' + nfmt(r.revenue) + '</td>'
                  + '<td class="num">' + costCell + '</td>'
                  + '<td class="num">' + nfmt(r.cost_total) + '</td>'
                  + '<td class="num ' + mrClass(r.margin_rate) + '">' + nfmt(r.margin) + '</td>'
                  + '<td class="num ' + mrClass(r.margin_rate) + '">' + (r.margin_rate === null ? '–' : nfmt(r.margin_rate) + '%') + '</td>'
                  + '<td>' + statusBadge(r) + '</td>'
                  + '<td style="font-size:11px;color:#8a94a0;">' + bomsCell + '</td>'
                  + '</tr>';
            });
        } else {
            rows.forEach(function(p){
                h += '<tr>'
                  + '<td class="num">' + p.rank + '</td>'
                  + '<td><span class="abc-badge abc-' + p.abc + '">' + p.abc + '</span></td>'
                  + partCell(p.d_id, p.spec, p.gear)
                  + '<td>' + catBadge(p.category) + '</td>'
                  + '<td style="font-size:12px;">' + esc(p.clients) + '</td>'
                  + '<td class="num">' + nfmt(p.orders, 0) + '</td>'
                  + '<td class="num">' + nfmt(p.qty, 0) + '</td>'
                  + '<td class="num">' + nfmt(p.revenue) + '</td>'
                  + '<td class="num">' + nfmt(p.cum_pct) + '%</td>'
                  + '<td class="num">' + nfmt(p.avg_price) + '</td>'
                  + '<td class="num ' + mrClass(p.margin_rate) + '">' + nfmt(p.margin) + '</td>'
                  + '<td class="num ' + mrClass(p.margin_rate) + '">' + (p.margin_rate === null ? '–' : nfmt(p.margin_rate) + '%') + '</td>'
                  + '<td class="num"><span class="drill-link" data-did="' + esc(p.d_id) + '" title="點擊切到訂單明細看這個料號的訂單">' + p.costed_orders + '/' + p.orders + '</span></td>'
                  + '</tr>';
            });
        }
        $('#opaTbody').html(h);
    }

    function renderSummary(s){
        state.lastSummary = s;
        $('#cardOrders').text(nfmt(s.orders, 0));
        $('#cardRevenue').text('營收 ' + nfmt(s.revenue));
        $('#cardFull').text(nfmt(s.full, 0));
        $('#cardPartial').text(nfmt(s.partial, 0));
        $('#cardNone').text(nfmt(s.none, 0));
        $('#cardMarginRate').text(s.avg_margin_rate === null ? '–' : nfmt(s.avg_margin_rate) + '%')
            .attr('class', 'stat-value ' + mrClass(s.avg_margin_rate));
        $('#cardLoss').text('虧損訂單 ' + nfmt(s.loss_orders, 0) + '｜涵蓋營收 ' + nfmt(s.costed_revenue));
    }

    var loading = false;
    function load(){
        if (loading) return;
        loading = true;
        $('#opaTbody').html('<tr><td colspan="14" class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> 計算中...</td></tr>');
        $.getJSON('Order_Profit_Analysis.php', params({action:'list'}))
        .done(function(res){
            if (!res.success) { alert(res.error || '載入失敗'); return; }
            renderHead();
            renderRows(res.rows);
            renderSummary(res.summary);
            var totalPages = Math.max(1, Math.ceil(res.total / state.size));
            if (state.page > totalPages) state.page = totalPages;
            $('#pageNum').text(state.page + ' / ' + totalPages);
            $('#pageInfo').text('共 ' + res.total + ' 筆');
        })
        .fail(function(){ $('#opaTbody').html('<tr><td colspan="14" class="text-center text-danger">載入失敗</td></tr>'); })
        .always(function(){ loading = false; });
    }

    /* ── 圖面跳窗（同 NewOrder_Track.php 的 openPartDrawing）── */
    function openPartDrawing(pid){
        if (!pid) return;
        var w = screen.availWidth, h = screen.availHeight;
        var pw = Math.min(1400, Math.round(w * 0.85));
        var ph = Math.min(900,  Math.round(h * 0.88));
        var pl = Math.round((w - pw) / 2);
        var pt = Math.round((h - ph) / 2);
        window.open(
            '../../views/pm/bom_viewer.php?d_id=' + encodeURIComponent(pid),
            'drawing_' + pid,
            'width=' + pw + ',height=' + ph + ',left=' + pl + ',top=' + pt
                + ',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no'
        );
    }

    /* ── 成本明細跳窗 ── */
    function openCostDetail(oid, oo, did, boms, isAuto){
        $('#cdSub').text(oo + '｜' + did);
        $('#cdBody').html('<i class="fa fa-spinner fa-spin"></i> 載入中...');
        $('#costModal').modal('show');
        $.getJSON('Order_Profit_Analysis.php', {action:'cost_detail', order_id: oid, boms: (boms || '')})
        .done(function(res){
            if (!res.success) { $('#cdBody').html('<span class="text-danger">' + esc(res.error || '載入失敗') + '</span>'); return; }
            if (!res.boms.length) { $('#cdBody').html('<span class="text-muted">此訂單未綁定製令，也沒有可自動比對的批次。</span>'); return; }
            var h = '';
            if (res.auto || isAuto) {
                h += '<div style="background:#FEF5E7;color:#b9770e;border-radius:4px;padding:6px 10px;font-size:12px;margin-bottom:8px;">'
                   + '<i class="fa fa-info-circle"></i> 以下製令為<b>自動比對批次</b>（未綁定）：同料號由最新出貨往前依數量分配，僅供成本估算參考。</div>';
            }
            res.boms.forEach(function(b){
                h += '<div style="font-weight:700;margin:6px 0 4px;">製令 ' + esc(b.bom)
                   + ' <small style="color:#888;">數量 ' + nfmt(b.sqty, 0) + '｜已計單顆成本合計 <b>' + nfmt(b.cost_per_pc) + '</b></small></div>';
                h += '<table class="cd-table"><thead><tr>'
                   + '<th style="width:36px;">序</th><th>製程</th><th>加工商</th>'
                   + '<th class="num" style="text-align:right;">加權平均單價</th>'
                   + '<th class="num" style="text-align:right;">計價數量</th>'
                   + '<th class="num" style="text-align:right;">筆數</th><th>採計</th>'
                   + '</tr></thead><tbody>';
                b.processes.forEach(function(p){
                    var counted = p.avg_price !== null;
                    var why;
                    if (counted) why = '<span style="color:#0e8c73;font-weight:700;">✓</span>';
                    else if (p.is_kg) why = '<span style="color:#8a94a0;">客供料</span>';
                    else if (p.internal) why = '<span style="color:#b9770e;">廠內未計</span>';
                    else why = '<span style="color:#8a94a0;">未計價</span>';
                    h += '<tr class="' + (counted ? '' : 'cd-skip') + '">'
                       + '<td>' + p.bom_sn + '</td>'
                       + '<td>' + esc(p.process_name) + ' <small style="color:#aaa;">#' + p.process_no + '</small></td>'
                       + '<td>' + esc(p.makers || '') + '</td>'
                       + '<td style="text-align:right;">' + nfmt(p.avg_price) + '</td>'
                       + '<td style="text-align:right;">' + nfmt(p.qty_sum, 0) + '</td>'
                       + '<td style="text-align:right;">' + nfmt(p.cnt, 0) + '</td>'
                       + '<td>' + why + '</td>'
                       + '</tr>';
                });
                h += '</tbody></table>';
            });
            h += '<div style="font-size:11px;color:#8a94a0;">單價為該製程所有外包移轉紀錄的加權平均（修改後單價優先、以請款數量加權）；「廠內未計」與「未計價」製程不列入單顆成本。</div>';
            $('#cdBody').html(h);
        })
        .fail(function(){ $('#cdBody').html('<span class="text-danger">載入失敗</span>'); });
    }

    /* ── 事件 ── */
    $('#btnSearch').on('click', function(){ state.page = 1; load(); });

    $('#opaTbody').on('click', '.part-link', function(){ openPartDrawing($(this).data('did')); });
    $('#opaTbody').on('click', '.cost-link', function(){
        openCostDetail($(this).data('oid'), $(this).data('oo'), $(this).data('did'),
                       String($(this).data('boms') || ''), $(this).data('auto') == 1);
    });
    $('#opaTbody').on('click', '.drill-link', function(){
        // 料號彙總 → 點成本資料鑽取到該料號的訂單明細
        $('#fKw').val($(this).data('did'));
        state.view = 'order'; state.costFilter = 'all';
        state.sort = 'order_date'; state.dir = 'desc'; state.page = 1;
        $('.view-toggle .btn').removeClass('active').filter('[data-view=order]').addClass('active');
        $('.stat-card[data-costfilter]').removeClass('active').filter('[data-costfilter=all]').addClass('active');
        load();
    });

    $('.stat-card[data-costfilter]').on('click', function(){
        $('.stat-card[data-costfilter]').removeClass('active');
        $(this).addClass('active');
        state.costFilter = $(this).data('costfilter');
        state.page = 1;
        if (state.view !== 'order') {   // 料號彙總不分成本狀態，切回訂單明細
            state.view = 'order'; state.sort = 'order_date'; state.dir = 'desc';
            $('.view-toggle .btn').removeClass('active').filter('[data-view=order]').addClass('active');
        }
        load();
    });

    $('.view-toggle .btn').on('click', function(){
        $('.view-toggle .btn').removeClass('active');
        $(this).addClass('active');
        state.view = $(this).data('view');
        state.page = 1;
        if (state.view === 'part') { state.sort = 'revenue'; state.dir = 'desc'; }
        else { state.sort = 'order_date'; state.dir = 'desc'; }
        load();
    });

    $('#opaThead').on('click', 'th', function(){
        var s = $(this).data('sort');
        if (!s) return;
        if (state.sort === s) state.dir = (state.dir === 'asc' ? 'desc' : 'asc');
        else { state.sort = s; state.dir = (s === 'd_id' || s === 'client' || s === 'order_oo' || s === 'abc' || s === 'category') ? 'asc' : 'desc'; }
        state.page = 1;
        load();
    });

    $('#pageSizeSelect').on('change', function(){ state.size = parseInt(this.value, 10); state.page = 1; load(); });
    $('#btnPrevPage').on('click', function(){ if (state.page > 1) { state.page--; load(); } });
    $('#btnNextPage').on('click', function(){ state.page++; load(); });

    $('#btnExportCsv').on('click', function(){
        window.location = 'Order_Profit_Analysis.php?' + $.param(params({action:'export_csv'}));
    });

    $('#btnPrint').on('click', function(){
        // 抓全量資料開列印視窗（瀏覽器另存 PDF）
        $.getJSON('Order_Profit_Analysis.php', params({action:'list', page:1, size:100000}))
        .done(function(res){
            if (!res.success) { alert(res.error || '載入失敗'); return; }
            var w = window.open('', '_blank');
            var title = '訂單毛利分析（' + (state.view === 'part' ? '料號彙總' : '訂單明細') + '）';
            var range = ($('#fDateFrom').val() || '') + ' ～ ' + ($('#fDateTo').val() || '');
            var head = HEADS[state.view].map(function(c){ return '<th>' + c[1] + '</th>'; }).join('');
            var body = '';
            if (state.view === 'order') {
                var stMap = {full:'完整', partial:'部分', none:'無'};
                res.rows.forEach(function(r){
                    body += '<tr><td>' + [r.order_date, r.order_oo, r.client].map(esc).join('</td><td>') + '</td>'
                          + '<td>' + esc(r.d_id) + (r.gear ? '<br><small>' + esc(r.gear) + '</small>' : '') + '</td>'
                          + '<td>' + esc(r.category) + '</td>'
                          + '<td class="n">' + [nfmt(r.qty,0), nfmt(r.unit_price), nfmt(r.revenue), nfmt(r.cost_pc), nfmt(r.cost_total), nfmt(r.margin),
                              (r.margin_rate===null?'–':nfmt(r.margin_rate)+'%')].join('</td><td class="n">') + '</td>'
                          + '<td>' + stMap[r.cost_status] + '</td><td>' + (r.auto_matched ? '≈' : '') + esc(r.boms.join(' ')) + '</td></tr>';
                });
            } else {
                res.rows.forEach(function(p){
                    body += '<tr><td class="n">' + p.rank + '</td><td>' + p.abc + '</td>'
                          + '<td>' + esc(p.d_id) + (p.gear ? '<br><small>' + esc(p.gear) + '</small>' : '') + '</td>'
                          + '<td>' + esc(p.category) + '</td><td>' + esc(p.clients) + '</td>'
                          + '<td class="n">' + [nfmt(p.orders,0), nfmt(p.qty,0), nfmt(p.revenue), nfmt(p.cum_pct)+'%', nfmt(p.avg_price),
                              nfmt(p.margin), (p.margin_rate===null?'–':nfmt(p.margin_rate)+'%')].join('</td><td class="n">') + '</td>'
                          + '<td class="n">' + p.costed_orders + '/' + p.orders + '</td></tr>';
                });
            }
            var s = res.summary;
            w.document.write('<html><head><title>' + title + '</title><meta charset="utf-8"><style>'
                + 'body{font-family:"Microsoft JhengHei",Arial;font-size:11px;margin:20px;}'
                + 'h3{margin:0 0 4px;} .sub{color:#666;margin-bottom:10px;}'
                + 'table{border-collapse:collapse;width:100%;} th,td{border:1px solid #999;padding:3px 5px;} th{background:#eee;} td.n{text-align:right;}'
                + '</style></head><body><h3>' + title + '</h3>'
                + '<div class="sub">訂單日期：' + range + '｜訂單 ' + s.orders + ' 筆｜營收 ' + nfmt(s.revenue)
                + '｜平均毛利率(有成本) ' + (s.avg_margin_rate===null?'–':nfmt(s.avg_margin_rate)+'%') + '｜列印時間：' + new Date().toLocaleString('zh-TW') + '</div>'
                + '<table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></body></html>');
            w.document.close();
            setTimeout(function(){ w.print(); }, 300);
        });
    });

    $('#roleHint').on('click', function(){ $('#roleModal').modal('show'); });

    /* ── 即時篩選：輸入停頓 400ms 自動查詢（客戶/料號/訂單號/規格）；日期變更即查 ── */
    var liveTimer = null;
    $(document).on('input', '.eg-live', function(){
        clearTimeout(liveTimer);
        liveTimer = setTimeout(function(){ state.page = 1; load(); }, 400);
    });
    $('#fDateFrom, #fDateTo').on('change', function(){ state.page = 1; load(); });

    /* ── UI 規範：雙擊清空 / 聚焦全選 / Enter 逐欄與末欄送出 ── */
    $(document).on('focus', '.eg-in', function(){ var el = this; setTimeout(function(){ try { el.select(); } catch(e){} }, 0); });
    $(document).on('dblclick', '.eg-in', function(){
        if (this.value !== '') { this.value = ''; state.page = 1; load(); }   // 篩選欄雙擊＝清空並解除該欄篩選
    });
    $(document).on('keydown', '.eg-in', function(e){
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var ins = $('.eg-in:visible');
        var idx = ins.index(this);
        if (idx >= 0 && idx < ins.length - 1) ins.eq(idx + 1).focus();
        else { state.page = 1; load(); }   // 最後一欄 Enter＝送出查詢
    });

    load();
})();
</script>
<?php endif; ?>
</body>
</html>
