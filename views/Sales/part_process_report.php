<?php
/**
 * 料號製程履歷合併報告（試作版，2026-08-03 新建）
 *
 * 把單一料號底下的製令(bom)依製程順序(bom_ing.processing_sequence)、圖面(Z:/BOM/ 精確檔名比對)、
 * 檢驗(qc_check_form 批次/複驗歷程)、報工(pm_process_daily_report)、成本毛利(比照
 * Order_Profit_Analysis.php 口徑，見 src/common/part_cost_lib.php)、訂單/出貨頻率整合成一張
 * A4/A3 可列印報告；可單筆查看，也可勾選期間內多筆製令一次產生（同一份文件內用分頁符號接續，
 * 不開多個視窗，捲動可看全部、按一次列印鍵印完，最後加一頁總體趨勢分析）。
 *
 * 權限：RBAC module='part_process_report'（rf_has_module_role，整頁單一權限，管理者固定可用）。
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Taipei'); // 期間預設值用本地時區（PHP 預設 UTC 會差 8 小時，跨日邊界會抓錯天）

session_start();

require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/role_features_helper.php';
require_once __DIR__ . '/../../src/common/vendor_audit_lib.php';   // vendor_audit_company_name() 重用
require_once __DIR__ . '/../../src/common/asdoc_lib.php';
require_once __DIR__ . '/../../src/common/ppr_lib.php';
require_once __DIR__ . '/../../src/common/part_cost_lib.php';

$isAjax = isset($_GET['action']) || isset($_POST['action']);

if (!isset($_SESSION['userName'])) {
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>'未登入']); exit; }
    $_SESSION['lastpage'] = "../../views/Sales/part_process_report.php";
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$my_id = (int)$_SESSION['id'];
ppr_ensure_schema($pdo);
$has_access = rf_has_module_role($pdo, $my_id, 'part_process_report');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ══════════════════════════ 客戶地址 → 地區（同名客戶用來區分） ══════════════════════════ */

/** 縣/市＋區/鄉/鎮/市（例：台中市西屯區） */
function ppr_addr_region(string $address): string {
    if (trim($address) === '') return '';
    if (!preg_match('/^(.{2,4}[縣市])/u', $address, $m1)) return '';
    $region = $m1[1];
    $rest = substr($address, strlen($m1[1]));
    if (preg_match('/^(.{1,4}[區鄉鎮市])/u', $rest, $m2)) $region .= $m2[1];
    return $region;
}

/** 縣/市＋區/鄉/鎮/市＋路/街（同名同區時進一步區分用） */
function ppr_addr_region_ext(string $address): string {
    $region = ppr_addr_region($address);
    if ($region === '') return '';
    $rest = substr($address, strlen($region));
    if (preg_match('/^(.{1,8}[路街])/u', $rest, $m3)) return $region . $m3[1];
    return $region;
}

/** 客戶搜尋結果去混淆：同名同區者才進一步顯示到路/街 */
function ppr_disambiguate_clients(array $rows): array {
    $groups = [];
    foreach ($rows as $i => $r) {
        $region = ppr_addr_region((string)($r['customer_address'] ?? ''));
        $groups[$r['customer'] . '|' . $region][] = $i;
    }
    $out = [];
    foreach ($rows as $i => $r) {
        $region = ppr_addr_region((string)($r['customer_address'] ?? ''));
        $key = $r['customer'] . '|' . $region;
        if (count($groups[$key]) > 1) $region = ppr_addr_region_ext((string)($r['customer_address'] ?? ''));
        $out[] = ['id'=>$r['customer_id'], 'name'=>$r['customer'], 'region'=>$region];
    }
    return $out;
}

/* ══════════════════════════ 渲染輔助 ══════════════════════════ */

function ppr_qc_badge(?string $qcCheck, $qcCompleted): array {
    $map = ['ok'=>['合格','#8a6d2f'], 'ng'=>['驗退','#DD5138'], 'QQ'=>['異常','#DD5138'], 'AOD'=>['特採','#F0A24B']];
    if ($qcCheck && isset($map[$qcCheck])) return $map[$qcCheck];
    if ((int)$qcCompleted === 1) return ['已完工','#8a6d2f'];
    return ['待驗','#999'];
}

function ppr_render_flow_bar(array $processes): string {
    if (empty($processes)) return '<div class="ppr-muted">此製令尚無製程資料。</div>';
    $chips = [];
    $i = 0;
    foreach ($processes as $p) {
        $i++;
        [$label, $color] = ppr_qc_badge($p['QC_check'], $p['qc_completed']);
        $kind = ((int)$p['is_internal'] === 1) ? '廠內' : '外包';
        $chips[] = '<div class="ppr-flow-chip" style="border-color:'.$color.';">'
            .'<span class="ppr-flow-no">'.$i.'</span>'
            .'<span class="ppr-flow-name">'.h($p['ProcessName'] ?: ('製程#'.$p['process_no'])).'</span>'
            .'<span class="ppr-flow-kind">'.$kind.'</span>'
            .'<span class="ppr-flow-status" style="color:'.$color.';">'.$label.'</span>'
            .'</div>';
    }
    return '<div class="ppr-flow-bar">'.implode('<span class="ppr-flow-arrow">→</span>', $chips).'</div>';
}

function ppr_render_qc_history(array $batches): string {
    if (empty($batches)) return '<div class="ppr-muted">尚無檢驗紀錄。</div>';
    $out = '<div class="ppr-qc-hist">';
    foreach ($batches as $b) {
        $out .= '<div class="ppr-qc-batch"><b>批次 '.h($b['batch_no']).'</b>：';
        $rounds = [];
        foreach ($b['rounds'] as $r) {
            $result = $r['check_result'];
            $resLabel = $result === 'OK' ? '合格' : ($result === 'NG' ? ('不良'.($r['ng_qty']>0?' x'.$r['ng_qty']:'')) : '待判定');
            $color = $result === 'OK' ? '#8a6d2f' : ($result === 'NG' ? '#DD5138' : '#999');
            $txt = '第'.$r['round_no'].'次'.($r['is_aod'] ? '<span style="color:#F0A24B;">(特採)</span>' : '').'：'
                 .'<span style="color:'.$color.';">'.$resLabel.'</span>';
            $rounds[] = $txt;
        }
        $out .= implode(' <span class="ppr-flow-arrow">→</span> ', $rounds) . '</div>';
    }
    return $out . '</div>';
}

function ppr_render_work_summary(?array $w): string {
    if (!$w) return '';
    $eff = $w['rel_efficiency'] !== null ? ($w['rel_efficiency'].'%（與歷史平均比較之相對值，非官方標準工時）') : '無比較基準';
    return '<table class="ppr-work-table"><tr>'
        .'<th>機台</th><td>'.h($w['machines'] ?: '—').'</td>'
        .'<th>人員</th><td>'.h($w['operators'] ?: '—').'</td></tr><tr>'
        .'<th>日期區間</th><td>'.h($w['date_from']).' ~ '.h($w['date_to']).'</td>'
        .'<th>實際加工日</th><td>'.h($w['actual_dates']).'</td></tr><tr>'
        .'<th>總工時</th><td>'.h($w['total_hr']).' 小時</td>'
        .'<th>產出數量</th><td>'.h($w['produced_qty']).'</td></tr><tr>'
        .'<th>單顆加工時間</th><td>'.($w['pc_min']!==null ? h($w['pc_min']).' 分/顆' : '—').'</td>'
        .'<th>相對效率</th><td>'.h($eff).'</td></tr></table>';
}

function ppr_render_process_cards(PDO $pdo, array $processes, string $workReport): string {
    if (empty($processes)) return '';
    $out = '';
    $i = 0;
    foreach ($processes as $p) {
        $i++;
        [$label, $color] = ppr_qc_badge($p['QC_check'], $p['qc_completed']);
        $kind = ((int)$p['is_internal'] === 1) ? ('廠內／'.h($p['machine_name'] ?: '未指定機台')) : ('外包／'.h($p['maker_name'] ?: '未指定廠商'));
        $out .= '<div class="ppr-proc-card">';
        $out .= '<div class="ppr-proc-head"><span class="ppr-proc-idx">'.$i.'</span> '
              . '<b>'.h($p['ProcessName'] ?: ('製程#'.$p['process_no'])).'</b>'
              . '<span class="ppr-proc-kind">'.$kind.'</span>'
              . '<span class="ppr-proc-status" style="background:'.$color.';">'.$label.'</span></div>';
        $history = ppr_qc_history($pdo, (int)$p['bom_ing_fid']);
        $out .= '<div class="ppr-proc-body">' . ppr_render_qc_history($history);
        if ($workReport !== 'none') {
            $w = ppr_report_work_summary($pdo, (int)$p['bom_ing_fid'], (int)$p['process_no']);
            $out .= ppr_render_work_summary($w);
        }
        $out .= '</div></div>';
    }
    return $out;
}

function ppr_render_cost_block(PDO $pdo, array $bomRow): string {
    $costMap = ppc_bom_cost($pdo, [$bomRow['bom']]);
    $c = $costMap[$bomRow['bom']] ?? null;
    if (!$c || $c['cost_pc'] === null) {
        return '<div class="ppr-section"><h4>成本與毛利</h4><div class="ppr-muted">此製令尚無足夠資料可推算成本（無外包實價、無報工紀錄、亦無固定單價設定）。</div></div>';
    }
    $order = ppc_bom_order($pdo, $bomRow);
    $margin = ppc_margin($c['cost_pc'], $order);
    $statusLabel = ['full'=>'完整（全部製程皆有成本資料）','partial'=>'部分（尚有製程無成本資料）','none'=>'無資料'][$c['status']] ?? $c['status'];
    $out = '<div class="ppr-section"><h4>成本與毛利</h4><table class="ppr-cost-table">';
    $out .= '<tr><th>單顆成本</th><td>'.number_format($c['cost_pc'],4).'</td><th>成本涵蓋度</th><td>'.h($statusLabel).'</td></tr>';
    if ($order) {
        $out .= '<tr><th>綁定訂單</th><td>'.h($order['Order_oo']).'</td><th>訂單單價</th><td>'.($margin['unit_price']!==null?number_format($margin['unit_price'],4):'—').'</td></tr>';
        $out .= '<tr><th>單顆毛利</th><td>'.($margin['margin_pc']!==null?number_format($margin['margin_pc'],4):'—').'</td><th>毛利率</th><td>'.($margin['margin_rate']!==null?h($margin['margin_rate']).'%':'—').'</td></tr>';
    } else {
        $out .= '<tr><th colspan="4" style="text-align:left;font-weight:normal;color:#999;">此製令查無綁定訂單，無法比對毛利。</th></tr>';
    }
    $out .= '</table>';
    $out .= '<table class="ppr-cost-detail"><thead><tr><th>製程</th><th>成本來源</th><th>單價</th><th>說明</th></tr></thead><tbody>';
    foreach ($c['process_detail'] as $d) {
        $srcLabel = ['outsource'=>'外包實價','inhouse'=>'廠內推算','fixed'=>'固定單價','kg'=>'客供料','none'=>'無資料'][$d['source']] ?? $d['source'];
        $out .= '<tr><td>'.h($d['process_name'] ?: $d['process_no']).'</td><td>'.h($srcLabel).'</td><td>'.($d['price']!==null?number_format($d['price'],4):'—').'</td><td style="font-size:11px;color:#8a6d45;">'.h($d['note']).'</td></tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

function ppr_render_freq_table(array $stat, string $priceKey): string {
    if ($stat['count'] === 0) return '<div class="ppr-muted">無歷史紀錄。</div>';
    $out = '<div class="ppr-freq-meta">共 '.$stat['count'].' 筆　平均數量 '.($stat['avg_qty']??'—').'　平均間隔 '.($stat['avg_interval']!==null?$stat['avg_interval'].' 天':'—').'</div>';
    $out .= '<table class="ppr-freq-table"><thead><tr><th>日期</th><th>對象</th><th>數量</th><th>單價</th></tr></thead><tbody>';
    foreach (array_slice($stat['rows'], 0, 20) as $r) {
        $date = $r['Order_date'] ?? '';
        $who  = $r['Client_name'] ?? '';
        $qty  = $r['Qty'] ?? '';
        $price = $r[$priceKey] ?? '';
        $out .= '<tr><td>'.h(substr((string)$date,0,10)).'</td><td>'.h($who).'</td><td>'.h($qty).'</td><td>'.($price!==''&&$price!==null?number_format((float)$price,2):'—').'</td></tr>';
    }
    $out .= '</tbody></table>';
    if ($stat['count'] > 20) $out .= '<div class="ppr-muted">僅列最近 20 筆，共 '.$stat['count'].' 筆。</div>';
    return $out;
}

function ppr_render_freq_block(PDO $pdo, int $dSettingId): string {
    $orderStat = ppr_order_history($pdo, $dSettingId);
    $shipStat  = ppr_ship_history($pdo, $dSettingId);
    $out = '<div class="ppr-section"><h4>訂單 / 出貨頻率分析</h4>';
    $out .= '<div class="ppr-freq-cols"><div><b>歷史訂單</b>'.ppr_render_freq_table($orderStat, 'unit_price').'</div>';
    $out .= '<div><b>歷史出貨</b>'.ppr_render_freq_table($shipStat, 'Unit_price').'</div></div></div>';
    return $out;
}

/** 單筆製令報告（回傳一個 .ppr-page 區塊） */
function ppr_render_bom_page(PDO $pdo, array $bomRow, array $partInfo, ?array $drawing, array $opts, bool $isBatch): string {
    $company = vendor_audit_company_name($pdo);
    $doc = eg_asdoc_get($pdo, 'part_process_report');
    $docTitle = $doc ? $doc['doc_name'] : '料號製程履歷報告';

    $processes = ppr_bom_processes($pdo, $bomRow['bom']);

    // 圖面方向決定版面 flex-direction
    $orientation = $drawing['orientation'] ?? 'portrait';
    $flexDir = $orientation === 'landscape' ? 'column' : 'row';

    $drawingHtml = '<div class="ppr-drawing-empty">找不到圖面</div>';
    if ($drawing) {
        $url = $drawing['url'];
        if ($drawing['ext'] === 'pdf') {
            $drawingHtml = '<iframe src="'.h($url).'" class="ppr-drawing-frame"></iframe>';
        } else {
            $drawingHtml = '<img src="'.h($url).'" class="ppr-drawing-img" alt="圖面">';
        }
    }

    $out = '<div class="ppr-page">';
    $out .= '<div class="ppr-doc-head"><div class="ppr-company">'.h($company).'</div><div class="ppr-doctitle">'.h($docTitle).'</div></div>';
    $out .= '<table class="ppr-basic"><tr>'
        .'<th>料號</th><td>'.h($partInfo['D_Setting_Id']).'</td>'
        .'<th>規格</th><td>'.h($partInfo['Spec_No']).'</td>'
        .'<th>製令</th><td>'.h($bomRow['bom']).'</td></tr><tr>'
        .'<th>客戶</th><td>'.h($bomRow['Client_Name']).'</td>'
        .'<th>數量</th><td>'.h($bomRow['sqty']).'</td>'
        .'<th>製令建立日</th><td>'.h(substr((string)$bomRow['Created_At'],0,10)).'</td></tr></table>';

    $out .= '<div class="ppr-body" style="flex-direction:'.$flexDir.';">';
    $out .= '<div class="ppr-drawing-box">'.$drawingHtml.'</div>';
    $out .= '<div class="ppr-flow-box"><h4>製程流程總覽</h4>'.ppr_render_flow_bar($processes).'</div>';
    $out .= '</div>';

    $out .= '<div class="ppr-section"><h4>製程詳細資料</h4>'.ppr_render_process_cards($pdo, $processes, $opts['work_report']).'</div>';

    if (!empty($opts['show_cost'])) {
        $out .= ppr_render_cost_block($pdo, $bomRow);
    }
    if (!$isBatch && !empty($opts['show_freq'])) {
        $out .= ppr_render_freq_block($pdo, (int)$partInfo['d_id']);
    } elseif ($isBatch && !empty($opts['show_cost'])) {
        // 批次模式：不顯示完整頻率分析，只保留上面成本毛利小結（已含在 ppr_render_cost_block）
    }

    $out .= '</div>'; // .ppr-page
    return $out;
}

/** 總體分析頁（批次模式附加在最後） */
function ppr_render_summary_page(PDO $pdo, array $bomRows, array $partInfo): string {
    $costMap = ppc_bom_cost($pdo, array_column($bomRows, 'bom'));
    $trend = [];
    foreach ($bomRows as $b) {
        $c = $costMap[$b['bom']] ?? null;
        $order = ppc_bom_order($pdo, $b);
        $margin = $c && $c['cost_pc']!==null ? ppc_margin($c['cost_pc'], $order) : ['unit_price'=>null,'margin_rate'=>null];
        $trend[] = [
            'date'   => substr((string)$b['Created_At'], 0, 10),
            'bom'    => $b['bom'],
            'qty'    => (int)$b['sqty'],
            'cost'   => $c['cost_pc'] ?? null,
            'price'  => $margin['unit_price'],
            'margin_rate' => $margin['margin_rate'],
        ];
    }
    usort($trend, function($a,$b){ return strcmp($a['date'], $b['date']); });

    $orderStat = ppr_order_history($pdo, (int)$partInfo['d_id']);
    $shipStat  = ppr_ship_history($pdo, (int)$partInfo['d_id']);

    $paperClass = count($bomRows) > 15 ? 'ppr-a3-landscape' : '';
    $out = '<div class="ppr-page '.$paperClass.'">';
    $out .= '<div class="ppr-doc-head"><div class="ppr-company">'.h(vendor_audit_company_name($pdo)).'</div><div class="ppr-doctitle">總體分析（'.h($partInfo['D_Setting_Id']).'，共 '.count($bomRows).' 筆製令）</div></div>';
    $out .= '<div class="ppr-summary-charts">';
    $out .= '<div class="ppr-chart-box"><h4>加工價格 / 成本趨勢</h4><canvas class="ppr-chart" data-chart="cost" data-points=\''.h(json_encode($trend, JSON_UNESCAPED_UNICODE)).'\'></canvas></div>';
    $out .= '<div class="ppr-chart-box"><h4>毛利率趨勢</h4><canvas class="ppr-chart" data-chart="margin" data-points=\''.h(json_encode($trend, JSON_UNESCAPED_UNICODE)).'\'></canvas></div>';
    $out .= '<div class="ppr-chart-box"><h4>訂單 / 出貨數量趨勢</h4><canvas class="ppr-chart" data-chart="freq" data-orders=\''.h(json_encode($orderStat['rows'], JSON_UNESCAPED_UNICODE)).'\' data-ships=\''.h(json_encode($shipStat['rows'], JSON_UNESCAPED_UNICODE)).'\'></canvas></div>';
    $out .= '</div>';
    $out .= '<div class="ppr-section"><h4>各筆製令小結</h4><table class="ppr-cost-detail"><thead><tr><th>製令</th><th>建立日</th><th>數量</th><th>單顆成本</th><th>訂單單價</th><th>毛利率</th></tr></thead><tbody>';
    foreach ($trend as $t) {
        $out .= '<tr><td>'.h($t['bom']).'</td><td>'.h($t['date']).'</td><td>'.h($t['qty']).'</td>'
            .'<td>'.($t['cost']!==null?number_format($t['cost'],4):'—').'</td>'
            .'<td>'.($t['price']!==null?number_format($t['price'],4):'—').'</td>'
            .'<td>'.($t['margin_rate']!==null?h($t['margin_rate']).'%':'—').'</td></tr>';
    }
    $out .= '</tbody></table></div>';
    $out .= '<div class="ppr-freq-cols"><div><b>訂單/出貨頻率彙總</b>'
        .'<div class="ppr-freq-meta">訂單：共 '.$orderStat['count'].' 筆，平均間隔 '.($orderStat['avg_interval']!==null?$orderStat['avg_interval'].' 天':'—').'</div>'
        .'<div class="ppr-freq-meta">出貨：共 '.$shipStat['count'].' 筆，平均間隔 '.($shipStat['avg_interval']!==null?$shipStat['avg_interval'].' 天':'—').'</div>'
        .'</div></div>';
    $out .= '</div>';
    return $out;
}

/* ══════════════════════════ AJAX ══════════════════════════ */
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    if (!$has_access) { echo json_encode(['success'=>false,'error'=>'無此頁面使用權限']); exit; }

    try {
        if ($action === 'search_clients') {
            $term = trim($_POST['term'] ?? '');
            if ($term === '') { echo json_encode(['success'=>true, 'items'=>[]]); exit; }
            $kw = '%'.$term.'%';
            $st = $pdo->prepare("SELECT customer_id, customer, customer_address FROM customer_list
                WHERE is_inactive=0 AND (customer_id LIKE ? OR customer LIKE ? OR customer_full LIKE ?)
                ORDER BY customer ASC LIMIT 20");
            $st->execute([$kw, $kw, $kw]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $items = [];
            foreach (ppr_disambiguate_clients($rows) as $c) {
                $text = $c['name'] . ($c['region'] !== '' ? '（'.$c['region'].'）' : '');
                $items[] = ['id'=>$c['id'], 'text'=>$text, 'html'=>h($c['name']).' <small style="color:#8a6d45;">'.h($c['id']).($c['region']!==''?' ／ '.h($c['region']):'').'</small>'];
            }
            echo json_encode(['success'=>true, 'items'=>$items]);
            exit;
        }

        if ($action === 'search_parts') {
            $term = trim($_POST['term'] ?? '');
            $clientId = trim($_POST['customer_id'] ?? '');
            if ($term === '') { echo json_encode(['success'=>true, 'items'=>[]]); exit; }
            $kw = '%'.$term.'%';
            $where = ["(d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ? OR d.Spec_No LIKE ?)"];
            $params = [$kw, $kw, $kw];
            if ($clientId !== '') { $where[] = "d.Customer_Id = ?"; $params[] = $clientId; }
            $st = $pdo->prepare("
                SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, c.customer AS customer_name
                FROM d_setting d
                LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                WHERE ".implode(' AND ', $where)."
                ORDER BY d.D_Setting_Id ASC LIMIT 20");
            $st->execute($params);
            $items = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $label = $r['D_Setting_Id'];
                if ($r['Drawing_No']) $label .= ' / '.$r['Drawing_No'];
                if ($r['Spec_No']) $label .= '（'.$r['Spec_No'].'）';
                $cust = $r['customer_name'] ?: '未指定客戶';
                $items[] = ['id'=>(int)$r['d_id'], 'text'=>$label.' — '.$cust, 'html'=>h($label).' <small style="color:#8a6d45;">'.h($cust).'</small>'];
            }
            echo json_encode(['success'=>true, 'items'=>$items]);
            exit;
        }

        if ($action === 'list_boms') {
            $did = (int)($_POST['d_id'] ?? 0);
            $from = trim($_POST['date_from'] ?? '');
            $to   = trim($_POST['date_to'] ?? '');
            if ($did <= 0) { echo json_encode(['success'=>false,'error'=>'請選擇料號']); exit; }
            $st = $pdo->prepare("SELECT d_id, D_Setting_Id, Drawing_No, Spec_No, Type FROM d_setting WHERE d_id=? LIMIT 1");
            $st->execute([$did]);
            $part = $st->fetch(PDO::FETCH_ASSOC);
            if (!$part) { echo json_encode(['success'=>false,'error'=>'找不到料號']); exit; }

            $where = ["b.d_setting_id = ?"]; $params = [$did];
            if ($from !== '') { $where[] = "b.Created_At >= ?"; $params[] = $from.' 00:00:00'; }
            if ($to   !== '') { $where[] = "b.Created_At <= ?"; $params[] = $to.' 23:59:59'; }
            $st = $pdo->prepare("SELECT b.bom, b.sqty, b.state, b.Created_At, b.Client_Name
                FROM bom b WHERE ".implode(' AND ', $where)." ORDER BY b.Created_At DESC");
            $st->execute($params);
            $boms = $st->fetchAll(PDO::FETCH_ASSOC);

            $drawings = ppr_resolve_drawings(array_column($boms, 'bom'));
            $rows = [];
            foreach ($boms as $b) {
                $rows[] = [
                    'bom'         => $b['bom'],
                    'created_at'  => substr((string)$b['Created_At'], 0, 10),
                    'sqty'        => $b['sqty'],
                    'client'      => $b['Client_Name'],
                    'state'       => $b['state'],
                    'drawing'     => $drawings[$b['bom']] ?? ['status'=>'none','candidates'=>[]],
                ];
            }
            echo json_encode(['success'=>true, 'part'=>$part, 'rows'=>$rows, 'max_batch'=>PPR_MAX_BATCH_COUNT]);
            exit;
        }

        if ($action === 'render_report') {
            $did = (int)($_POST['d_id'] ?? 0);
            $bomList = json_decode($_POST['boms'] ?? '[]', true) ?: [];
            $drawingChoice = json_decode($_POST['drawing_choice'] ?? '{}', true) ?: [];
            $opts = [
                'work_report' => in_array($_POST['work_report'] ?? '', ['simple'], true) ? 'simple' : 'none',
                'show_cost'   => !empty($_POST['show_cost']) ? 1 : 0,
                'show_freq'   => !empty($_POST['show_freq']) ? 1 : 0,
                'paper'       => ($_POST['paper'] ?? 'A4') === 'A3' ? 'A3' : 'A4',
            ];
            if (!$did || empty($bomList)) { echo json_encode(['success'=>false,'error'=>'缺少料號或製令']); exit; }
            if (count($bomList) > PPR_MAX_BATCH_COUNT) {
                echo json_encode(['success'=>false,'error'=>'單次最多產生 '.PPR_MAX_BATCH_COUNT.' 筆，請縮小期間或減少勾選（目前 '.count($bomList).' 筆）']); exit;
            }
            $st = $pdo->prepare("SELECT d_id, D_Setting_Id, Drawing_No, Spec_No, Type FROM d_setting WHERE d_id=? LIMIT 1");
            $st->execute([$did]);
            $part = $st->fetch(PDO::FETCH_ASSOC);
            if (!$part) { echo json_encode(['success'=>false,'error'=>'找不到料號']); exit; }

            $ph = implode(',', array_fill(0, count($bomList), '?'));
            $st = $pdo->prepare("SELECT bom, sqty, state, Created_At, Client_Name, o_order_id FROM bom WHERE d_setting_id=? AND bom IN ($ph) ORDER BY Created_At ASC");
            $st->execute(array_merge([$did], $bomList));
            $bomRows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (empty($bomRows)) { echo json_encode(['success'=>false,'error'=>'查無製令資料']); exit; }

            $drawings = ppr_resolve_drawings(array_column($bomRows, 'bom'));
            $isBatch = count($bomRows) > 1;
            $html = '';
            foreach ($bomRows as $b) {
                $d = $drawings[$b['bom']] ?? ['status'=>'none','candidates'=>[]];
                $chosen = null;
                if ($d['status'] === 'single') $chosen = $d['candidates'][0];
                elseif ($d['status'] === 'multiple') {
                    $pick = $drawingChoice[$b['bom']] ?? '';
                    foreach ($d['candidates'] as $cand) if ($cand['filename'] === $pick) { $chosen = $cand; break; }
                }
                $html .= ppr_render_bom_page($pdo, $b, $part, $chosen, $opts, $isBatch);
            }
            if ($isBatch) {
                $html .= ppr_render_summary_page($pdo, $bomRows, $part);
            }
            echo json_encode(['success'=>true, 'html'=>$html, 'is_batch'=>$isBatch]);
            exit;
        }

        echo json_encode(['success'=>false, 'error'=>'未知動作']);
    } catch (Throwable $e) {
        echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>料號製程履歷報告</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col > .page-title { clear:both; overflow:hidden; margin:8px 0 4px; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn, .ppr-toolbar, .nav_menu, .left_col, footer { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }

        .ppr-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px; padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .va-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .va-modal { background:#fff; border-radius:8px; max-width:560px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:88vh; display:flex; flex-direction:column; }
        .va-modal.xwide { max-width:920px; }
        .va-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .va-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .va-modal .m-body { padding:15px; overflow-y:auto; }
        .va-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .va-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .va-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .ppr-toolbar { border:1.5px solid #E8D5B5; border-radius:8px; padding:10px 12px; margin-bottom:12px; background:#FDF8EF; }
        .ppr-toolbar .row2 { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:8px; }
        .ppr-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .ppr-toolbar select, .ppr-toolbar input[type=date], .ppr-toolbar input[type=text], .ppr-toolbar button { height:30px; font-size:13px; padding:0 10px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; }
        .ppr-toolbar button { background:#F0A24B; color:#fff; border-color:#d98a33; cursor:pointer; }
        .ppr-toolbar button:hover { background:#d98a33; }
        .ppr-typeahead { position:relative; }
        #pprClientInput { width:200px; }
        #pprPartInput { width:280px; }
        .ppr-suggest { display:none; position:absolute; top:100%; left:0; z-index:80; background:#fff; border:1px solid #D8BE93; border-radius:4px;
            max-height:260px; overflow-y:auto; min-width:260px; box-shadow:0 3px 12px rgba(0,0,0,.18); margin-top:2px; }
        .ppr-suggest .item { padding:5px 10px; font-size:12px; cursor:pointer; border-bottom:1px solid #F3E9D6; }
        .ppr-suggest .item:last-child { border-bottom:none; }
        .ppr-suggest .item:hover { background:#FBF0DD; }
        .ppr-suggest .empty { padding:6px 10px; font-size:12px; color:#999; }

        .ppr-bom-list { border:1px solid #EADFC8; border-radius:6px; }
        .ppr-bom-row { display:flex; align-items:center; gap:10px; padding:6px 10px; border-bottom:1px solid #F3E9D6; font-size:13px; }
        .ppr-bom-row:last-child { border-bottom:none; }
        .ppr-bom-row .dw-status-none { color:#DD5138; }
        .ppr-bom-row .dw-status-single { color:#8a6d2f; }
        .ppr-bom-row .dw-status-multiple { color:#F0A24B; }
        .ppr-dw-pick { display:flex; gap:8px; flex-wrap:wrap; margin-left:26px; }
        .ppr-dw-pick label { display:flex; align-items:center; gap:4px; font-size:12px; border:1px solid #D8BE93; border-radius:4px; padding:3px 6px; cursor:pointer; }
        .ppr-count-bar { font-size:12px; color:#8a6d45; margin:6px 0; }

        .ppr-report-area { background:#fff; }
        .ppr-page { padding:14mm; border:1px solid #eee; margin-bottom:14px; }
        @media print { .ppr-page { border:none; margin:0; page-break-after:always; } }
        .ppr-doc-head { display:flex; justify-content:space-between; align-items:baseline; border-bottom:2px solid #8A5A2B; padding-bottom:6px; margin-bottom:8px; }
        .ppr-company { font-size:18px; font-weight:bold; color:#5b3a1e; }
        .ppr-doctitle { font-size:14px; color:#8a6d45; }
        table.ppr-basic { width:100%; border-collapse:collapse; font-size:12px; margin-bottom:10px; }
        table.ppr-basic th, table.ppr-basic td { border:1px solid #EADFC8; padding:4px 8px; text-align:left; }
        table.ppr-basic th { background:#F7E0BD; color:#5b3a1e; width:70px; }
        .ppr-body { display:flex; gap:12px; margin-bottom:10px; }
        .ppr-drawing-box { flex:1 1 45%; border:1px solid #EADFC8; border-radius:6px; min-height:200px; display:flex; align-items:center; justify-content:center; background:#FAFAFA; }
        .ppr-drawing-img { max-width:100%; max-height:360px; object-fit:contain; }
        .ppr-drawing-frame { width:100%; height:360px; border:none; }
        .ppr-drawing-empty { color:#999; padding:20px; }
        .ppr-flow-box { flex:1 1 55%; }
        .ppr-flow-bar { display:flex; flex-wrap:wrap; align-items:center; gap:4px; }
        .ppr-flow-chip { border:2px solid #ccc; border-radius:6px; padding:4px 8px; font-size:11px; display:flex; flex-direction:column; gap:2px; background:#fff; min-width:70px; }
        .ppr-flow-no { font-weight:bold; color:#8A5A2B; }
        .ppr-flow-kind { color:#8a6d45; }
        .ppr-flow-arrow { color:#D8BE93; font-weight:bold; }
        .ppr-section { margin:12px 0; }
        .ppr-section h4 { color:#8A5A2B; border-bottom:1px solid #F7E0BD; padding-bottom:3px; font-size:14px; }
        .ppr-proc-card { border:1px solid #EADFC8; border-radius:6px; margin-bottom:8px; page-break-inside:avoid; }
        .ppr-proc-head { background:#FDF8EF; padding:6px 10px; display:flex; align-items:center; gap:10px; font-size:13px; }
        .ppr-proc-idx { background:#8A5A2B; color:#fff; border-radius:50%; width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; font-size:11px; }
        .ppr-proc-kind { color:#8a6d45; font-size:12px; }
        .ppr-proc-status { margin-left:auto; color:#fff; border-radius:10px; padding:1px 10px; font-size:12px; }
        .ppr-proc-body { padding:8px 10px; font-size:12px; }
        .ppr-qc-hist { margin-bottom:6px; }
        .ppr-qc-batch { margin-bottom:3px; }
        table.ppr-work-table { width:100%; border-collapse:collapse; font-size:11px; margin-top:4px; }
        table.ppr-work-table th, table.ppr-work-table td { border:1px solid #EADFC8; padding:3px 6px; text-align:left; }
        table.ppr-work-table th { background:#FAF3E4; width:90px; color:#5b3a1e; }
        table.ppr-cost-table { width:100%; border-collapse:collapse; font-size:12px; margin-bottom:6px; }
        table.ppr-cost-table th, table.ppr-cost-table td { border:1px solid #EADFC8; padding:4px 8px; }
        table.ppr-cost-table th { background:#F7E0BD; width:110px; text-align:left; }
        table.ppr-cost-detail { width:100%; border-collapse:collapse; font-size:11px; }
        table.ppr-cost-detail th, table.ppr-cost-detail td { border:1px solid #EADFC8; padding:3px 6px; text-align:left; }
        table.ppr-cost-detail th { background:#FAF3E4; }
        .ppr-freq-cols { display:flex; gap:16px; }
        .ppr-freq-cols > div { flex:1; }
        .ppr-freq-meta { font-size:11px; color:#8a6d45; margin:4px 0; }
        table.ppr-freq-table { width:100%; border-collapse:collapse; font-size:11px; }
        table.ppr-freq-table th, table.ppr-freq-table td { border:1px solid #EADFC8; padding:3px 6px; }
        table.ppr-freq-table th { background:#FAF3E4; }
        .ppr-muted { color:#999; font-size:12px; }
        .ppr-summary-charts { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:10px; }
        .ppr-chart-box { flex:1 1 30%; min-width:260px; border:1px solid #EADFC8; border-radius:6px; padding:8px; }
        .ppr-chart-box h4 { margin-top:0; font-size:13px; color:#8A5A2B; }
        canvas.ppr-chart { width:100% !important; height:200px !important; }
        @media print { .ppr-summary-charts { page-break-inside:avoid; } }
    </style>
    <style id="pprPageSizeStyle"></style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">料號製程履歷報告
                <small style="color:#8a6d45;">圖面／製程／檢驗／報工／成本毛利／訂單出貨 一次整合</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$has_access): ?>
        <div class="ppr-noperm">
            <h4><i class="fa fa-lock"></i> 無本頁使用權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「料號製程履歷報告-檢視」角色。</p>
        </div>
<?php else: ?>
        <div class="ppr-toolbar">
            <div class="row2">
                <label>客戶</label>
                <span class="ppr-typeahead">
                    <input type="text" id="pprClientInput" placeholder="輸入客戶ID/名稱…" autocomplete="off">
                    <input type="hidden" id="pprClientId">
                    <div class="ppr-suggest" id="pprClientSuggest"></div>
                </span>
                <label>料號</label>
                <span class="ppr-typeahead">
                    <input type="text" id="pprPartInput" placeholder="輸入料號/圖號/規格…" autocomplete="off">
                    <input type="hidden" id="pprPartId">
                    <div class="ppr-suggest" id="pprPartSuggest"></div>
                </span>
                <label>期間</label>
                <input type="date" id="pprDateFrom" value="<?= date('Y-m-01') ?>">～<input type="date" id="pprDateTo" value="<?= date('Y-m-d') ?>">
                <button id="pprSearchBtn"><i class="fa fa-search"></i> 查詢筆數</button>
            </div>
            <div class="row2">
                <label><input type="checkbox" id="pprOptWork" value="1"> 帶入報工簡表</label>
                <label><input type="checkbox" id="pprOptCost" value="1" checked> 顯示成本毛利</label>
                <label><input type="checkbox" id="pprOptFreq" value="1" checked> 顯示訂單/出貨頻率（僅單筆模式）</label>
                <label>紙張</label>
                <select id="pprPaper"><option value="A4">A4</option><option value="A3">A3（批次多筆建議）</option></select>
            </div>
            <div id="pprBomListWrap" style="display:none;">
                <div class="ppr-count-bar"><label><input type="checkbox" id="pprSelAll"> 全選</label>　已選 <span id="pprSelCount">0</span> / 上限 <span id="pprMaxCount">30</span> 筆</div>
                <div class="ppr-bom-list" id="pprBomList"></div>
                <div style="margin-top:8px;"><button id="pprGenBtn"><i class="fa fa-file-text-o"></i> 產生報告</button>
                    <button id="pprPrintBtn" style="display:none;"><i class="fa fa-print"></i> 列印 / 產生PDF</button></div>
            </div>
        </div>

        <div class="ppr-report-area" id="pprReportArea"></div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 頁面使用說明 modal -->
<div class="va-mask" id="helpUseMask"><div class="va-modal xwide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 料號製程履歷報告 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>一、功能說明</h4>
        <p>把單一料號的圖面、製程順序、檢驗（含複驗）、報工、成本毛利、訂單/出貨歷史整合成一張可列印的 A4/A3 履歷報告，供品質追溯與成本檢視使用。</p>
        <h4>二、操作步驟</h4>
        <ul>
            <li>選擇料號＋期間，按「查詢筆數」列出該期間內的製令(BOM)清單。</li>
            <li>若某製令的圖面在 Z:/BOM/ 有多個副檔名的精確匹配檔（檔名去副檔名剛好等於製令號碼者），會列出候選清單，需先選定要用哪一個才能產生報告；找不到精確匹配檔則顯示「找不到圖面」。</li>
            <li>期間內只有 1 筆 → 直接產生單筆報告（可另外顯示訂單/出貨頻率分析）。多筆 → 勾選要產生的製令（可全選），按「產生報告」；同一份文件內連續呈現，最後加一頁總體趨勢分析，按「列印」一次印完全部。</li>
        </ul>
        <h4>三、重要行為 / 常見疑問</h4>
        <div class="tip">
            <b>圖面判定</b>：只認「檔名去副檔名恰好等於製令號碼」的檔案，任何帶後綴的變體檔名一律不算候選。<br>
            <b>批次上限</b>：單次最多產生 <?= PPR_MAX_BATCH_COUNT ?> 筆，超過請縮小期間或減少勾選。<br>
            <b>成本口徑</b>：與「訂單毛利分析」頁完全相同（外包實價優先→廠內報工推算→固定單價設定），客供料製程不計成本。<br>
            <b>報工效率</b>：因全站無官方標準工時可比，效率為「與該製程歷史平均單顆工時比較」的相對值，非絕對標準。
        </div>
        <h4>四、設定入口</h4>
        <p>AS 文件編號綁定（列印表頭/頁尾）：請洽管理者於 AS 文件管理設定本頁對應文件。</p>
        <h4>五、權限角色</h4>
        <p>「料號製程履歷報告-檢視」：可使用本頁全部功能（含成本毛利，整頁單一權限，未分層）。管理者固定可用。</p>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/Chart.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }

var PPR_API = 'part_process_report.php';
var PPR_ROWS = [];
var PPR_DRAWING_CHOICE = {};

function pprDebounce(fn, wait){
    var t;
    return function(){
        var args = arguments, ctx = this;
        clearTimeout(t);
        t = setTimeout(function(){ fn.apply(ctx, args); }, wait);
    };
}

/** 輸入框下方浮動建議清單（打字模糊篩選，點選才帶入值；不使用下拉選單） */
function pprSetupTypeahead(opt){
    var $input = $(opt.inputSel), $hidden = $(opt.hiddenSel), $box = $(opt.boxSel);
    var items = [];
    function close(){ $box.hide().empty(); }
    var doSearch = pprDebounce(function(){
        var term = $input.val().trim();
        if (!term) { close(); return; }
        var params = $.extend({action: opt.action, term: term}, opt.extraParams ? opt.extraParams() : {});
        $.post(PPR_API, params, function(res){
            if (!res.success || !res.items.length) { $box.html('<div class="empty">查無符合項目</div>').show(); items = []; return; }
            items = res.items;
            var html = '';
            items.forEach(function(it, i){ html += '<div class="item" data-i="'+i+'">'+it.html+'</div>'; });
            $box.html(html).show();
        }, 'json');
    }, 250);
    $input.on('input', function(){ $hidden.val(''); if (opt.onClear) opt.onClear(); doSearch(); });
    $input.on('focus', function(){ if ($input.val().trim() && items.length) $box.show(); });
    $box.on('click', '.item', function(){
        var it = items[$(this).data('i')];
        $input.val(it.text); $hidden.val(it.id);
        close();
        if (opt.onPick) opt.onPick(it);
    });
    $(document).on('click', function(e){
        if (!$(e.target).closest($input).length && !$(e.target).closest($box).length) close();
    });
}

pprSetupTypeahead({
    inputSel:'#pprClientInput', hiddenSel:'#pprClientId', boxSel:'#pprClientSuggest', action:'search_clients',
    onClear:function(){ $('#pprPartInput').val(''); $('#pprPartId').val(''); }
});
pprSetupTypeahead({
    inputSel:'#pprPartInput', hiddenSel:'#pprPartId', boxSel:'#pprPartSuggest', action:'search_parts',
    extraParams:function(){ return {customer_id: $('#pprClientId').val()}; }
});

$('#pprSearchBtn').on('click', function(){
    var did = $('#pprPartId').val();
    if (!did) { alert('請先從建議清單選擇一個料號'); return; }
    var from = $('#pprDateFrom').val(), to = $('#pprDateTo').val();
    $.post(PPR_API, {action:'list_boms', d_id:did, date_from:from, date_to:to}, function(res){
        if (!res.success) { alert(res.error||'查詢失敗'); return; }
        PPR_ROWS = res.rows; PPR_DRAWING_CHOICE = {};
        $('#pprMaxCount').text(res.max_batch);
        pprRenderBomList();
        $('#pprBomListWrap').show();
        $('#pprReportArea').empty(); $('#pprPrintBtn').hide();
    }, 'json');
});

function pprRenderBomList(){
    var $wrap = $('#pprBomList').empty();
    if (!PPR_ROWS.length) { $wrap.html('<div class="ppr-bom-row">此期間查無製令資料。</div>'); pprUpdateCount(); return; }
    PPR_ROWS.forEach(function(r, idx){
        var dwStatus = r.drawing.status;
        var dwText = dwStatus==='none' ? '<span class="dw-status-none">找不到圖面</span>'
                   : dwStatus==='single' ? '<span class="dw-status-single">圖面：'+r.drawing.candidates[0].filename+'</span>'
                   : '<span class="dw-status-multiple">圖面：'+r.drawing.candidates.length+' 個候選，請選擇 →</span>';
        var checked = PPR_ROWS.length===1 ? 'checked' : '';
        var $row = $('<div class="ppr-bom-row">'
            + '<input type="checkbox" class="ppr-bom-chk" data-bom="'+r.bom+'" '+checked+'>'
            + '<b>'+r.bom+'</b> '+r.created_at+' 數量'+r.sqty+' '+(r.client||'')+' '+dwText
            + '</div>');
        $wrap.append($row);
        if (dwStatus === 'multiple') {
            var $pick = $('<div class="ppr-dw-pick"></div>');
            r.drawing.candidates.forEach(function(c, ci){
                var $lab = $('<label><input type="radio" name="dw-'+idx+'" value="'+c.filename+'"> '+c.filename+' <a href="'+c.url+'" target="_blank">(預覽)</a></label>');
                $lab.find('input').on('change', function(){ PPR_DRAWING_CHOICE[r.bom] = c.filename; });
                $pick.append($lab);
            });
            $wrap.append($pick);
        }
    });
    $('.ppr-bom-chk').on('change', pprUpdateCount);
    pprUpdateCount();
}

function pprUpdateCount(){
    var n = $('.ppr-bom-chk:checked').length;
    $('#pprSelCount').text(n);
}
$('#pprSelAll').on('change', function(){
    $('.ppr-bom-chk').prop('checked', $(this).is(':checked'));
    pprUpdateCount();
});

$('#pprGenBtn').on('click', function(){
    var boms = $('.ppr-bom-chk:checked').map(function(){ return $(this).data('bom').toString(); }).get();
    if (!boms.length) { alert('請至少勾選一筆製令'); return; }
    var maxN = parseInt($('#pprMaxCount').text(), 10);
    if (boms.length > maxN) { alert('單次最多產生 '+maxN+' 筆，請減少勾選'); return; }
    // 檢查有多重候選但尚未選擇圖面者
    var missing = [];
    PPR_ROWS.forEach(function(r){
        if (boms.indexOf(r.bom) === -1) return;
        if (r.drawing.status === 'multiple' && !PPR_DRAWING_CHOICE[r.bom]) missing.push(r.bom);
    });
    if (missing.length) { alert('以下製令有多個圖面候選，請先選擇要使用哪一份：\n'+missing.join('、')); return; }

    var did = $('#pprPartId').val();
    $.post(PPR_API, {
        action:'render_report', d_id:did, boms: JSON.stringify(boms),
        drawing_choice: JSON.stringify(PPR_DRAWING_CHOICE),
        work_report: $('#pprOptWork').is(':checked') ? 'simple' : 'none',
        show_cost: $('#pprOptCost').is(':checked') ? 1 : 0,
        show_freq: $('#pprOptFreq').is(':checked') ? 1 : 0,
        paper: $('#pprPaper').val()
    }, function(res){
        if (!res.success) { alert(res.error||'產生失敗'); return; }
        $('#pprReportArea').html(res.html);
        pprSetPaperSize($('#pprPaper').val());
        pprInitCharts();
        $('#pprPrintBtn').show();
        $('html,body').animate({scrollTop: $('#pprReportArea').offset().top - 60}, 300);
    }, 'json');
});

function pprSetPaperSize(size){
    var css = size === 'A3' ? '@page{size:A3 portrait;}' : '@page{size:A4 portrait;}';
    $('#pprPageSizeStyle').text(css);
}
$('#pprPaper').on('change', function(){ pprSetPaperSize($(this).val()); });

$('#pprPrintBtn').on('click', function(){ window.print(); });

function pprInitCharts(){
    $('canvas.ppr-chart').each(function(){
        var $c = $(this);
        var type = $c.data('chart');
        var ctx = this.getContext('2d');
        if (type === 'cost') {
            var pts = JSON.parse($c.attr('data-points'));
            new Chart(ctx, { type:'line', data:{ labels: pts.map(function(p){return p.date;}),
                datasets:[
                    { label:'單顆成本', data: pts.map(function(p){return p.cost;}), borderColor:'#DD5138', fill:false },
                    { label:'訂單單價', data: pts.map(function(p){return p.price;}), borderColor:'#F0A24B', fill:false }
                ]}, options:{ responsive:true, maintainAspectRatio:false } });
        } else if (type === 'margin') {
            var pts2 = JSON.parse($c.attr('data-points'));
            new Chart(ctx, { type:'bar', data:{ labels: pts2.map(function(p){return p.date;}),
                datasets:[{ label:'毛利率(%)', data: pts2.map(function(p){return p.margin_rate;}), backgroundColor:'#C9A227' }] },
                options:{ responsive:true, maintainAspectRatio:false } });
        } else if (type === 'freq') {
            var orders = JSON.parse($c.attr('data-orders')||'[]');
            var ships = JSON.parse($c.attr('data-ships')||'[]');
            new Chart(ctx, { type:'line', data:{
                labels: orders.map(function(o){return (o.Order_date||'').substring(0,10);}).reverse(),
                datasets:[
                    { label:'訂單數量', data: orders.map(function(o){return o.Qty;}).reverse(), borderColor:'#F0A24B', fill:false },
                    { label:'出貨數量', data: ships.map(function(s){return s.Qty;}).reverse(), borderColor:'#8a6d2f', fill:false }
                ]}, options:{ responsive:true, maintainAspectRatio:false } });
        }
    });
}
</script>
</body>
</html>
