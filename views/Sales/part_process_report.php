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
require_once __DIR__ . '/../../src/common/date_fmt_lib.php';   // 顯示日期一律 YYYY.MM.DD（ai-rules/20）
require_once __DIR__ . '/../../src/common/gear_spec_lib.php';  // 齒輪規格顯示字串，唯一實作不自刻

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

/** 顯示用日期（ai-rules/20：一律 YYYY.MM.DD；空值回 '—' 讓表格不會出現空格） */
function ppr_d($d, string $empty = '—'): string {
    $d = substr((string)$d, 0, 10);
    if ($d === '' || $d === '0000-00-00') return $empty;
    $s = eg_fmt_date($d);
    return $s !== '' ? $s : $empty;
}

/**
 * 單價／金額顯示：小數點後只剩 0 的一律不顯示小數點與 0（使用者明確要求，2026-09-17）。
 * 1005.0000→1,005、240.7750→240.775、0→0；null/空字串→'—'。
 */
function ppr_num($v, int $dec = 4, string $empty = '—'): string {
    if ($v === null || $v === '' || !is_numeric($v)) return $empty;
    $s = number_format((float)$v, $dec, '.', ',');
    if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

/** 製令建立～結案日期顯示字串：未結案一律寫成「2026.08.25～未結案」（使用者指定格式） */
function ppr_period_text(array $bomRow): string {
    $p = ppr_bom_period($bomRow);
    $from = ppr_d($p['from'], '—');
    if (!$p['closed'])       return $from . '～未結案';
    if ($p['no_close_date']) return $from . '～已結案（無結案日期紀錄）';
    return $from . '～' . ppr_d($p['to']);
}

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

/**
 * 流程總覽（2026-09-17 依使用者要求改簡約版）：一列由左到右的製程名稱＋狀態徽章，
 * 中間用箭頭銜接；**刻意不再顯示 1234 數字標籤**（順序看箭頭就知道，數字只是多一層雜訊）。
 * 廠內／外包與廠商留在下方「製程詳細資料」卡片，總覽只回答「做了哪幾站、各站過了沒」。
 */
function ppr_render_flow_bar(array $processes): string {
    if (empty($processes)) return '<div class="ppr-muted">此製令尚無製程資料。</div>';
    $chips = [];
    foreach ($processes as $p) {
        $st = ppr_group_status($p['batches']);
        $split = count($p['batches']) > 1 ? '<span class="sp">拆'.count($p['batches']).'批</span>' : '';
        $chips[] = '<span class="ppr-chip">'
            .'<span class="nm">'.h($p['ProcessName'] ?: ('製程#'.$p['process_no'])).'</span>'.$split
            .'<span class="st" style="background:'.$st['color'].';">'.h($st['label']).'</span></span>';
    }
    return '<div class="ppr-stepper">'.implode('<span class="ppr-arrow" aria-hidden="true">→</span>', $chips).'</div>';
}

/**
 * 該製令各製程站實際用了哪台機台（bom_ing.machine_id 全站 83,571 列只有 1,173 列有值＝幾乎沒人填，
 * 現場真正留下機台的地方是報工紀錄）。一張製令一次查完，避免每個批次各打一次 SQL。
 * 回傳 bom_ing_fid => 機台顯示名稱（優先現場編號 field_no，比照 process_schedule_NOW.php）。
 */
function ppr_bom_report_machines(PDO $pdo, string $bomNo): array {
    try {
        $st = $pdo->prepare("
            SELECT bi.bom_ing_fid,
                   GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(mc.field_no),''), mc.machine) SEPARATOR '、') AS machines
            FROM pm_process_daily_report r
            JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
            LEFT JOIN machine_list mc ON mc.machine_id = r.machine_id
            WHERE bi.bom = ? AND r.machine_id IS NOT NULL
            GROUP BY bi.bom_ing_fid");
        $st->execute([$bomNo]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['bom_ing_fid']] = (string)$r['machines'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * 一個批次列的「廠內／外包・廠商・機台」文字。
 * 三個修正（2026-09-17 使用者回報）：
 *  ①**廠商一律帶出來**——原本只有外包(is_internal=0)才顯示廠商，可是像「超正齒研」「客戶」在 maker_list
 *    是 internal=1，於是畫面只印「廠內」，使用者看到的就是「廠商都沒有正確帶入」。
 *  ②機台顯示**現場編號**（field_no），bom_ing 沒填就退回報工紀錄裡實際報的那台。
 *  ③**拿掉「未指定機台」**——全站沒有任何地方可以在製令上指定機台，印一個永遠指不了的欄位只會誤導。
 */
function ppr_batch_kind_text(array $b, array $reportMachines): string {
    $parts = [((int)$b['is_internal'] === 1) ? '廠內' : '外包'];
    $maker = trim((string)($b['maker_name'] ?? ''));
    // 廠商是生管在製令上指定得了的欄位，沒指定就要講明白（跟機台不同，機台根本沒地方可以指定）
    $parts[] = ($maker !== '') ? $maker : '未指定廠商';
    $mach = ppr_machine_label($b['machine_name'] ?? null, $b['machine_field_no'] ?? null);
    if ($mach === '') $mach = (string)($reportMachines[(int)$b['bom_ing_fid']] ?? '');
    if ($mach !== '') $parts[] = '機台 ' . $mach;
    return implode('／', array_map('h', $parts));
}

/** 單一輪檢驗的量測明細（項目/標準/實測值/判定），沒有明細資料就不顯示表格 */
function ppr_render_qc_measurements(PDO $pdo, int $qcFormId): string {
    $meas = ppr_qc_measurements($pdo, $qcFormId);
    if (empty($meas)) return '';
    $out = '<table class="ppr-meas-table"><thead><tr><th>檢驗項目</th><th>標準</th><th>抽樣</th><th>實測值</th><th>判定</th></tr></thead><tbody>';
    foreach ($meas as $m) {
        $mv = $m['item_verdict'] ?: $m['result'];
        $mColor = $mv==='OK' ? '#8a6d2f' : ($mv==='NG' ? '#DD5138' : ($mv==='AOD' ? '#F0A24B' : '#999'));
        $std = $m['standard_text'] ?: (($m['min_value']!==null || $m['max_value']!==null) ? (h($m['min_value']).'~'.h($m['max_value'])) : '—');
        $out .= '<tr><td>'.h($m['item_name']).'</td><td>'.$std.'</td><td>#'.h($m['sample_no']).'</td><td>'.h($m['measured_value']).'</td>'
              . '<td style="color:'.$mColor.';font-weight:600;">'.h($mv ?: '—').'</td></tr>';
    }
    return $out . '</tbody></table>';
}

/** 一個批次的檢驗歷程（依批次分組、輪次序列），含量測明細 */
function ppr_render_qc_history(PDO $pdo, array $qcBatches): string {
    if (empty($qcBatches)) return '<div class="ppr-muted">尚無檢驗紀錄。</div>';
    $out = '';
    foreach ($qcBatches as $qb) {
        $out .= '<div class="ppr-qc-batch"><div class="ppr-qc-batch-title">到貨批次 '.h($qb['batch_no']).'</div>';
        foreach ($qb['rounds'] as $r) {
            $result = $r['check_result'];
            $resLabel = $result === 'OK' ? '合格' : ($result === 'NG' ? ('不良'.($r['ng_qty']>0?' x'.$r['ng_qty']:'')) : '待判定');
            $color = $result === 'OK' ? '#8a6d2f' : ($result === 'NG' ? '#DD5138' : '#999');
            $out .= '<div class="ppr-qc-round">'
                . '<span class="ppr-qc-round-tag">第'.$r['round_no'].'次</span> '
                . '<span class="ppr-qc-round-date">'.h($r['date']).'</span> '
                . '<span style="color:'.$color.';font-weight:600;">'.$resLabel.'</span>'
                . ($r['is_aod'] ? ' <span class="ppr-aod-tag">特採</span>' : '');
            $out .= ppr_render_qc_measurements($pdo, (int)$r['qc_form_id']);
            $out .= '</div>';
        }
        $out .= '</div>';
    }
    return $out;
}

function ppr_render_work_summary(?array $w, ?string $batchLabel = null): string {
    if (!$w) return '';
    $eff = $w['rel_efficiency'] !== null ? ($w['rel_efficiency'].'%（與歷史平均相對值，非官方標準工時）') : '無比較基準';
    $title = $batchLabel ? ('報工簡表（批次 '.h($batchLabel).'）') : '報工簡表';
    $days = array_filter(array_map('trim', explode('、', (string)$w['actual_dates'])));
    $daysTxt = implode('、', array_map(function ($d) { return ppr_d($d); }, $days));
    return '<div class="ppr-work-title">'.$title.'</div><table class="ppr-work-table"><tr>'
        .'<th>機台</th><td>'.h($w['machines'] ?: '—').'</td>'
        .'<th>人員</th><td>'.h($w['operators'] ?: '—').'</td></tr><tr>'
        .'<th>日期區間</th><td>'.ppr_d($w['date_from']).' ~ '.ppr_d($w['date_to']).'</td>'
        .'<th>實際加工日</th><td>'.h($daysTxt ?: '—').'</td></tr><tr>'
        .'<th>總工時</th><td>'.h($w['total_hr']).' 小時</td>'
        .'<th>產出數量</th><td>'.h($w['produced_qty']).'</td></tr><tr>'
        .'<th>單顆加工時間</th><td>'.($w['pc_min']!==null ? h($w['pc_min']).' 分/顆' : '—').'</td>'
        .'<th>相對效率</th><td>'.h($eff).'</td></tr></table>';
}

/**
 * 同料號歷史報工（選配，掛在該製程的報工簡表底下）。使用者指定的呈現方式：
 * 「直接以資料呈現，然後提供標頭就好，不需一直重複出現標題中文」＝一個表頭＋最多 5 列純資料。
 * 相同機台的排前面（做對照最有意義），不同機台也照列（使用者明確要求）。
 */
function ppr_render_work_history(array $hist): string {
    if (empty($hist)) return '';
    $out = '<div class="ppr-work-title">同料號近期報工（最多 5 筆，相同機台優先）</div>'
         . '<table class="ppr-work-hist"><thead><tr>'
         . '<th>機台</th><th>日期區間</th><th>總工時</th><th>單顆加工時間</th><th>實際加工日</th><th>產出數量</th></tr></thead><tbody>';
    foreach ($hist as $r) {
        $span = ppr_d($r['date_from']) . ($r['date_to'] !== $r['date_from'] ? ' ~ '.ppr_d($r['date_to']) : '');
        $out .= '<tr'.($r['same_machine'] ? ' class="same-m"' : '').'>'
            . '<td>'.h($r['machines'] ?: '—').'</td>'
            . '<td>'.h($span).'</td>'
            . '<td>'.h($r['total_hr']).' 小時</td>'
            . '<td>'.($r['pc_min'] !== null ? h($r['pc_min']).' 分/顆' : '—').'</td>'
            . '<td>'.h($r['day_cnt']).' 天</td>'
            . '<td>'.h($r['qty']).'</td></tr>';
    }
    return $out . '</tbody></table>';
}

/** 製程詳細卡片：每張卡＝一個製程站(bom_sn)，卡內依批次(拆批時多筆)分別列出廠內外/機台廠商/狀態/檢驗歷程/報工 */
function ppr_render_process_cards(PDO $pdo, array $processes, string $workReport, bool $showQc,
                                  string $bomNo = '', ?array $part = null, bool $showWorkHist = false): string {
    if (empty($processes)) return '';
    $reportMachines = $bomNo !== '' ? ppr_bom_report_machines($pdo, $bomNo) : [];
    $out = '';
    $i = 0;
    foreach ($processes as $p) {
        $i++;
        $gst = ppr_group_status($p['batches']);
        $out .= '<div class="ppr-proc-card" style="border-left-color:'.$gst['color'].';">';
        $out .= '<div class="ppr-proc-head"><span class="ppr-proc-idx">'.$i.'</span> '
              . '<b>'.h($p['ProcessName'] ?: ('製程#'.$p['process_no'])).'</b>'
              . '<span class="ppr-proc-status" style="background:'.$gst['color'].';">'.h($gst['label']).'</span></div>';
        $out .= '<div class="ppr-proc-body">';
        foreach ($p['batches'] as $b) {
            $kind = ppr_batch_kind_text($b, $reportMachines);
            [$label, $color] = ppr_qc_badge($b['QC_check'], $b['qc_completed']);
            $batchTag = $b['batch_label'] ? ('<b>批次 '.h($b['batch_label']).'</b>　') : '';
            $consumedNote = ((int)$b['is_consumed'] === 1) ? '<span class="ppr-consumed-tag">歷史批次（已拆分/合併）</span>' : '';
            $out .= '<div class="ppr-batch-row">';
            $out .= '<div class="ppr-batch-head">'.$batchTag.$kind.($b['sqty']?('　數量 '.h($b['sqty'])):'').' '
                  . '<span style="color:'.$color.';font-weight:600;">'.h($label).'</span> '.$consumedNote.'</div>';
            if ($showQc) $out .= ppr_render_qc_history($pdo, ppr_qc_history($pdo, (int)$b['bom_ing_fid']));
            if ($workReport !== 'none') {
                $out .= ppr_render_work_summary(ppr_report_work_summary($pdo, (int)$b['bom_ing_fid'], (int)$p['process_no']), $b['batch_label']);
                if ($showWorkHist && $part) {
                    $prefer = ppr_machine_label($b['machine_name'] ?? null, $b['machine_field_no'] ?? null);
                    if ($prefer === '') $prefer = (string)($reportMachines[(int)$b['bom_ing_fid']] ?? '');
                    $out .= ppr_render_work_history(
                        ppr_part_work_history($pdo, $part, (int)$p['process_no'], (int)$b['bom_ing_fid'], $prefer, 5));
                }
            }
            $out .= '</div>';
        }
        $out .= '</div></div>';
    }
    return $out;
}

/**
 * 成本與毛利。兩項 2026-09-17 新增（使用者要求）：
 *  ①明細表多一欄**廠商**（原本完全看不出這個製程是誰做的）。
 *  ②選配欄位**歷史加工價格**＝此廠商×此料號×此製程過去的實際發包單價，用來當場判斷這次的價格合不合理；
 *    同廠商查無紀錄時自動放寬到所有廠商並在欄位裡標明，避免出現一整欄空白卻不知道是「沒資料」還是「壞了」。
 * 所有單價一律走 ppr_num()：小數點後只剩 0 的不顯示小數點與 0。
 */
function ppr_render_cost_block(PDO $pdo, array $bomRow, array $processes = [], ?array $part = null, bool $showPriceHist = false): string {
    $costMap = ppc_bom_cost($pdo, [$bomRow['bom']]);
    $c = $costMap[$bomRow['bom']] ?? null;
    if (!$c || $c['cost_pc'] === null) {
        return '<div class="ppr-section"><h4>成本與毛利</h4><div class="ppr-muted">此製令尚無足夠資料可推算成本（無外包實價、無報工紀錄、亦無固定單價設定）。</div></div>';
    }
    // bom_sn => 該製程站的廠商（含廠內自有單位，如「超正齒研」「客戶」）
    $makerBySn = [];
    foreach ($processes as $p) {
        $b0 = $p['batches'][0] ?? null;
        if (!$b0) continue;
        $names = [];
        foreach ($p['batches'] as $b) { $n = trim((string)($b['maker_name'] ?? '')); if ($n !== '') $names[$n] = true; }
        $makerBySn[(string)$p['bom_sn']] = [
            'name'  => implode('、', array_keys($names)),
            'id_no' => (string)($b0['maker_id_no'] ?? ''),
        ];
    }

    $order = ppc_bom_order($pdo, $bomRow);
    $margin = ppc_margin($c['cost_pc'], $order);
    $statusLabel = ['full'=>'完整（全部製程皆有成本資料）','partial'=>'部分（尚有製程無成本資料）','none'=>'無資料'][$c['status']] ?? $c['status'];
    $out = '<div class="ppr-section"><h4>成本與毛利</h4><table class="ppr-cost-table">';
    $out .= '<tr><th>單顆成本</th><td>'.ppr_num($c['cost_pc']).'</td><th>成本涵蓋度</th><td>'.h($statusLabel).'</td></tr>';
    if ($order) {
        $out .= '<tr><th>綁定訂單</th><td>'.h($order['Order_oo']).'</td><th>訂單單價</th><td>'.ppr_num($margin['unit_price']).'</td></tr>';
        $out .= '<tr><th>單顆毛利</th><td>'.ppr_num($margin['margin_pc']).'</td><th>毛利率</th><td>'.($margin['margin_rate']!==null?h($margin['margin_rate']).'%':'—').'</td></tr>';
    } else {
        $out .= '<tr><th colspan="4" style="text-align:left;font-weight:normal;color:#999;">此製令查無綁定訂單，無法比對毛利。</th></tr>';
    }
    $out .= '</table>';
    $out .= '<table class="ppr-cost-detail"><thead><tr><th>製程</th><th>廠商</th><th>成本來源</th><th>單價</th>'
          . ($showPriceHist ? '<th style="width:26%;">歷史加工價格</th>' : '') . '<th>說明</th></tr></thead><tbody>';
    foreach ($c['process_detail'] as $key => $d) {
        $sn = substr((string)$key, strrpos((string)$key, '|') + 1);
        $mk = $makerBySn[$sn] ?? ['name'=>'', 'id_no'=>''];
        $srcLabel = ['outsource'=>'外包實價','inhouse'=>'廠內推算','fixed'=>'固定單價','kg'=>'客供料','none'=>'無資料'][$d['source']] ?? $d['source'];
        $out .= '<tr><td>'.h($d['process_name'] ?: $d['process_no']).'</td>'
              . '<td>'.h($mk['name'] ?: '—').'</td>'
              . '<td>'.h($srcLabel).'</td><td>'.ppr_num($d['price']).'</td>';
        if ($showPriceHist) {
            $out .= '<td>'.ppr_render_price_history($pdo, $part, (int)$d['process_no'], $mk['id_no']).'</td>';
        }
        $out .= '<td style="font-size:11px;color:#8a6d45;">'.h($d['note']).'</td></tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

/** 歷史加工價格儲存格：最近幾筆「日期 單價」，同廠商沒有才放寬並標示 */
function ppr_render_price_history(PDO $pdo, ?array $part, int $processNo, string $makerIdNo): string {
    if (!$part || $processNo <= 0) return '<span class="ppr-muted">—</span>';
    $hist = ppr_process_price_history($pdo, (string)$part['D_Setting_Id'], $processNo, $makerIdNo, 4);
    if (empty($hist['rows'])) return '<span class="ppr-muted">無歷史紀錄</span>';
    // 顯示順序為使用者指定：日期 → 數量 → 金額（金額要帶 $ 符號），例「2025.11.13 x118pcs $31」
    $lines = [];
    foreach ($hist['rows'] as $r) {
        $lines[] = '<span class="ph-row"><span class="d">'.ppr_d($r['transfer_date']).'</span>'
                 . ($r['qty'] !== null ? '<span class="q">x'.h((int)$r['qty']).'pcs</span>' : '')
                 . '<span class="p">$'.ppr_num($r['unit_price'], 4, '—').'</span>'
                 . ($hist['scope'] === 'any' ? '<span class="m">'.h($r['maker_name']).'</span>' : '')
                 . '</span>';
    }
    $note = $hist['scope'] === 'any' ? '<div class="ppr-ph-note">同廠商無紀錄，改列其他廠商</div>' : '';
    return '<div class="ppr-ph">'.$note.implode('', $lines).'</div>';
}

/**
 * 歷史訂單／出貨表。**一定要有「製程」欄**（2026-09-17 使用者要求）：同一個料號常常有「只做齒研」與
 * 「代料到成品」等不同加工範圍的單，少了這一欄，單價差好幾倍的兩列看起來就像同一種東西在亂跳價。
 * 訂單的製程取 order_track.Processing_items；出貨取該出貨所綁訂單的同一欄位，沒綁訂單就留白。
 */
function ppr_render_freq_table(array $stat, string $priceKey): string {
    if ($stat['count'] === 0) return '<div class="ppr-muted">無歷史紀錄。</div>';
    $out = '<div class="ppr-freq-meta">共 '.$stat['count'].' 筆　平均數量 '.($stat['avg_qty']??'—').'　平均間隔 '.($stat['avg_interval']!==null?$stat['avg_interval'].' 天':'—').'</div>';
    // 刻意不放「對象」欄：本報告只列這個料號的單，對象必定等於表頭那個客戶，印出來只是每一列重複同一個名字
    $out .= '<table class="ppr-freq-table"><thead><tr><th>日期</th><th>製程</th><th>數量</th><th>單價</th></tr></thead><tbody>';
    foreach (array_slice($stat['rows'], 0, 20) as $r) {
        $proc = trim((string)($r['Processing_items'] ?? ''));
        if ($proc !== '') {
            $procCell = h($proc);
        } else {
            // 出貨幾乎都沒綁訂單（99.6% 的 is_list.Order_id 是 NULL），而 ERP 是把製程混寫在規格欄裡，
            // 所以退回顯示規格欄內容；用虛線底標明「這不是製程欄位，是規格欄」，避免被當成正式製程讀
            $spec = trim((string)($r['Specification'] ?? ''));
            $procCell = $spec !== ''
                ? '<span class="ppr-spec-fb" title="此筆未綁訂單，改顯示出貨單的「規格」欄內容（ERP 常把製程與品名混寫在這一欄）">'.h($spec).'</span>'
                : '<span class="ppr-muted">—</span>';
        }
        $out .= '<tr><td>'.ppr_d($r['Order_date'] ?? '').'</td>'
              . '<td>'.$procCell.'</td>'
              . '<td>'.h($r['Qty'] ?? '').'</td>'
              . '<td>'.ppr_num($r[$priceKey] ?? null).'</td></tr>';
    }
    $out .= '</tbody></table>';
    if ($stat['count'] > 20) $out .= '<div class="ppr-muted">僅列最近 20 筆，共 '.$stat['count'].' 筆。</div>';
    return $out;
}

/**
 * 料號附件（選配）——**各自獨立成頁附在該筆製令報告後面**（使用者的用語是「另外列入」）。
 * 刻意不塞進報告內文的小格子裡：這些是圖面／規格書，擠在角落等於印了也看不懂；
 * 一張一頁、圖片撐滿整頁才是可用的。同一個標籤只印最新一份（使用者明確要求），
 * 每頁頁首標明**標籤名稱與備註**（還有料號、製令、發行日期，單獨抽出來看也知道是誰的）。
 * 圖片內嵌，PDF 用 iframe（與圖面同一套做法），其餘格式只列檔名（印不出來的東西不假裝有預覽）。
 */
function ppr_render_attach_pages(PDO $pdo, array $part, string $bomLabel, array $catIds): string {
    $picks = ppr_part_attach_pick($pdo, (int)$part['d_id'], $catIds);
    if (empty($picks)) return '';
    $company = vendor_audit_company_name($pdo);
    $out = '';
    foreach ($picks as $p) {
        $r    = $p['row'];
        $name = implode('・', $p['labels']);
        $ext  = strtolower(pathinfo((string)$r['filename'], PATHINFO_EXTENSION));
        $url  = '../../src/store/Part_Attachment_API.php?action=download&id=' . (int)$r['id'];
        $note = trim((string)($r['note'] ?? ''));
        $rev  = trim((string)($r['revision'] ?? ''));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true)) {
            $body = '<img src="'.h($url).'" class="ppr-attach-img" alt="'.h($name).'">';
        } elseif ($ext === 'pdf') {
            $body = '<iframe src="'.h($url).'" class="ppr-attach-frame"></iframe>';
        } else {
            $body = '<div class="ppr-attach-none">'.h($r['original_name'] ?: $r['filename']).'（'.h($ext ?: '未知格式').'，無法內嵌預覽，請至料號主檔下載）</div>';
        }
        $out .= '<div class="ppr-page ppr-attach-page"><div class="ppr-page-inner">'
              . '<div class="ppr-doc-head"><div class="ppr-company">'.h($company).'</div>'
              . '<div class="ppr-doctitle">料號附件　'.h($part['D_Setting_Id']).($bomLabel !== '' ? '　'.h($bomLabel) : '').'</div></div>'
              . '<div class="ppr-attach-head"><span class="lb">'.h($name).'</span>'
              . ($rev !== '' ? '<span class="rv">版次 '.h($rev).'</span>' : '')
              . '<span class="fn">'.h($r['original_name'] ?: $r['filename']).'</span>'
              . '<span class="dt">'.ppr_d($r['eff_date']).'</span></div>'
              . ($note !== '' ? '<div class="ppr-attach-note"><b>備註：</b>'.h($note).'</div>' : '')
              . '<div class="ppr-attach-body">'.$body.'</div>'
              . '</div></div>';
    }
    return $out;
}

function ppr_render_freq_block(PDO $pdo, array $part): string {
    $orderStat = ppr_order_history($pdo, $part);
    $shipStat  = ppr_ship_history($pdo, $part);
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

    // 齒輪規格：走共用 gear_spec_lib（與訂單追蹤/PFMEA 同一套樣板），查無資料就不顯示這一格
    $gearSpec = eg_gear_spec_for_part($pdo, (int)$partInfo['d_id']);

    $out = '<div class="ppr-page"><div class="ppr-page-inner">';
    $out .= '<div class="ppr-head-block">';
    $out .= '<div class="ppr-doc-head"><div class="ppr-company">'.h($company).'</div><div class="ppr-doctitle">'.h($docTitle).'</div></div>';
    // 欄位順序為使用者指定：第一列 客戶／料號／規格，第二列 製令／數量／製令建立～結案日期
    $out .= '<div class="ppr-info-grid">'
        .'<div class="ppr-info-item"><span class="k">客戶</span><span class="v">'.h($bomRow['Client_Name'] ?: ($partInfo['customer_name'] ?? '')).'</span></div>'
        .'<div class="ppr-info-item"><span class="k">料號</span><span class="v">'.h($partInfo['D_Setting_Id']).'</span></div>'
        .'<div class="ppr-info-item"><span class="k">規格</span><span class="v">'.h($partInfo['Spec_No'] ?: '—').'</span></div>'
        .'<div class="ppr-info-item"><span class="k">製令</span><span class="v">'.h($bomRow['bom']).'</span></div>'
        .'<div class="ppr-info-item"><span class="k">數量</span><span class="v">'.h($bomRow['sqty']).'</span></div>'
        .'<div class="ppr-info-item"><span class="k">製令建立～結案日期</span><span class="v">'.h(ppr_period_text($bomRow)).'</span></div>'
        .($gearSpec !== null ? '<div class="ppr-info-item ppr-info-wide"><span class="k">齒輪規格</span><span class="v">'.h($gearSpec).'</span></div>' : '')
        .'</div>';

    $out .= '<div class="ppr-body" style="flex-direction:'.$flexDir.';">';
    $out .= '<div class="ppr-drawing-box">'.$drawingHtml.'</div>';
    $out .= '<div class="ppr-flow-box"><h4>製程流程總覽</h4>'.ppr_render_flow_bar($processes).'</div>';
    $out .= '</div>';
    $out .= '</div>'; // .ppr-head-block（表頭+圖面+流程總覽不可跨頁截斷）

    // .ppr-page-main：A3 橫式時這一段會排成雙欄，讓整份報告收在同一張紙內
    $out .= '<div class="ppr-page-main">';
    $out .= '<div class="ppr-section"><h4>製程詳細資料</h4><div class="ppr-proc-cards">'
          . ppr_render_process_cards($pdo, $processes, $opts['work_report'], !empty($opts['show_qc']),
                                     (string)$bomRow['bom'], $partInfo, !empty($opts['show_work_hist'])) . '</div></div>';

    if (!empty($opts['show_cost'])) {
        $out .= ppr_render_cost_block($pdo, $bomRow, $processes, $partInfo, !empty($opts['show_price_hist']));
    }
    if (!$isBatch && !empty($opts['show_freq'])) {
        $out .= ppr_render_freq_block($pdo, $partInfo);
    } elseif ($isBatch && !empty($opts['show_cost'])) {
        // 批次模式：不顯示完整頻率分析，只保留上面成本毛利小結（已含在 ppr_render_cost_block）
    }

    $out .= '</div>';           // .ppr-page-main
    $out .= '</div></div>';     // .ppr-page-inner / .ppr-page
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
            'period' => ppr_period_text($b),
            'bom'    => $b['bom'],
            'qty'    => (int)$b['sqty'],
            'cost'   => $c['cost_pc'] ?? null,
            'price'  => $margin['unit_price'],
            'margin_rate' => $margin['margin_rate'],
        ];
    }
    usort($trend, function($a,$b){ return strcmp($a['date'], $b['date']); });

    $orderStat = ppr_order_history($pdo, $partInfo);
    $shipStat  = ppr_ship_history($pdo, $partInfo);

    $out = '<div class="ppr-page ppr-summary-page"><div class="ppr-page-inner">';
    $out .= '<div class="ppr-doc-head"><div class="ppr-company">'.h(vendor_audit_company_name($pdo)).'</div><div class="ppr-doctitle">總體分析（'.h($partInfo['D_Setting_Id']).'，共 '.count($bomRows).' 筆製令）</div></div>';
    $out .= '<div class="ppr-summary-charts">';
    $out .= '<div class="ppr-chart-box"><h4>加工價格 / 成本趨勢</h4><canvas class="ppr-chart" data-chart="cost" data-points=\''.h(json_encode($trend, JSON_UNESCAPED_UNICODE)).'\'></canvas></div>';
    $out .= '<div class="ppr-chart-box"><h4>毛利率趨勢</h4><canvas class="ppr-chart" data-chart="margin" data-points=\''.h(json_encode($trend, JSON_UNESCAPED_UNICODE)).'\'></canvas></div>';
    $out .= '<div class="ppr-chart-box"><h4>訂單 / 出貨數量趨勢</h4><canvas class="ppr-chart" data-chart="freq" data-orders=\''.h(json_encode($orderStat['rows'], JSON_UNESCAPED_UNICODE)).'\' data-ships=\''.h(json_encode($shipStat['rows'], JSON_UNESCAPED_UNICODE)).'\'></canvas></div>';
    $out .= '</div>';
    $out .= '<div class="ppr-section"><h4>各筆製令小結</h4><table class="ppr-cost-detail"><thead><tr><th>製令</th><th>製令建立～結案日期</th><th>數量</th><th>單顆成本</th><th>訂單單價</th><th>毛利率</th></tr></thead><tbody>';
    foreach ($trend as $t) {
        $out .= '<tr><td>'.h($t['bom']).'</td><td>'.h($t['period']).'</td><td>'.h($t['qty']).'</td>'
            .'<td>'.ppr_num($t['cost']).'</td>'
            .'<td>'.ppr_num($t['price']).'</td>'
            .'<td>'.($t['margin_rate']!==null?h($t['margin_rate']).'%':'—').'</td></tr>';
    }
    $out .= '</tbody></table></div>';
    $out .= '<div class="ppr-freq-cols"><div><b>訂單/出貨頻率彙總</b>'
        .'<div class="ppr-freq-meta">訂單：共 '.$orderStat['count'].' 筆，平均間隔 '.($orderStat['avg_interval']!==null?$orderStat['avg_interval'].' 天':'—').'</div>'
        .'<div class="ppr-freq-meta">出貨：共 '.$shipStat['count'].' 筆，平均間隔 '.($shipStat['avg_interval']!==null?$shipStat['avg_interval'].' 天':'—').'</div>'
        .'</div></div>';
    $out .= '</div></div>';
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
            $from = trim($_POST['date_from'] ?? '');
            $to   = trim($_POST['date_to'] ?? '');
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
                $pRow = ppr_part_row($pdo, (int)$r['d_id']);
                $cnt = $pRow ? ppr_bom_count_in_range($pdo, $pRow, $from, $to) : 0;
                $cntTxt = $cnt > 0 ? ('期間內 '.$cnt.' 筆BOM') : '期間內無BOM';
                $items[] = ['id'=>(int)$r['d_id'], 'text'=>$label.' — '.$cust,
                    'html'=>h($label).' <small style="color:#8a6d45;">'.h($cust).'　'.($cnt>0?'<b style="color:#8a6d2f;">':'<span style="color:#999;">').h($cntTxt).($cnt>0?'</b>':'</span>').'</small>'];
            }
            echo json_encode(['success'=>true, 'items'=>$items]);
            exit;
        }

        if ($action === 'search_boms') {
            $term = trim($_POST['term'] ?? '');
            if ($term === '') { echo json_encode(['success'=>true, 'items'=>[]]); exit; }
            $kw = '%'.$term.'%';
            // d_setting 用「主鍵對得上就用主鍵，對不上才用料號文字＋客戶」兩段解析：bom.d_setting_id 八成是 NULL，
            // 只 JOIN 主鍵的話搜到的 BOM 會帶不出料號（id=0），前端就會跳「請先選擇料號」然後整個清單消失。
            $st = $pdo->prepare("
                SELECT b.bom, b.d_setting_id, b.d_id, b.Created_At, b.sqty, b.Client_Name
                FROM bom b WHERE b.bom LIKE ? ORDER BY b.Created_At DESC LIMIT 20");
            $st->execute([$kw]);
            $items = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pk = (int)(ppr_resolve_bom_part($pdo, $r) ?? 0);
                $partNo = $r['d_id'] ?: '未知料號';
                $items[] = ['id'=>$pk, 'bom'=>$r['bom'], 'created'=>substr((string)$r['Created_At'],0,10),
                    'text'=>$partNo.' — '.$r['Client_Name'],
                    'html'=>'<b>'.h($r['bom']).'</b> '.h($partNo)
                        .' <small style="color:#8a6d45;">'.h($r['Client_Name']).'　'.h(eg_fmt_date($r['Created_At'])).'</small>'
                        .($pk <= 0 ? ' <small style="color:#DD5138;">此製令的料號在主檔查不到，無法產生報告</small>' : '')];
            }
            echo json_encode(['success'=>true, 'items'=>$items]);
            exit;
        }

        if ($action === 'browse_customer_boms') {
            $clientId = trim($_POST['customer_id'] ?? '');
            $from = trim($_POST['date_from'] ?? '');
            $to   = trim($_POST['date_to'] ?? '');
            if ($clientId === '') { echo json_encode(['success'=>false,'error'=>'請先選擇客戶']); exit; }
            $cst = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id=? LIMIT 1");
            $cst->execute([$clientId]);
            $clientName = (string)($cst->fetchColumn() ?: '');

            // 抓這個客戶的 BOM：主鍵對得上的（JOIN d_setting）＋只有料號文字的（比對客戶簡稱）兩路都要，
            // 只走前者會漏掉八成的製令（bom.d_setting_id 大量為 NULL）。
            $where = ["(d.Customer_Id = ? OR (b.d_setting_id IS NULL AND b.Client_Name = ?))"];
            $params = [$clientId, $clientName];
            if ($from !== '') { $where[] = "b.Created_At >= ?"; $params[] = $from.' 00:00:00'; }
            if ($to   !== '') { $where[] = "b.Created_At <= ?"; $params[] = $to.' 23:59:59'; }
            $st = $pdo->prepare("
                SELECT b.bom, b.d_setting_id, b.d_id, b.sqty, b.Created_At, b.Client_Name,
                       b.processing_state, b.closed_at
                FROM bom b LEFT JOIN d_setting d ON d.d_id = b.d_setting_id
                WHERE ".implode(' AND ', $where)."
                ORDER BY b.Created_At DESC LIMIT 400");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // 依料號分組回傳：使用者回報「列出料號的方式很難去選擇」——原本是一長串平鋪的 BOM 列，
            // 同一個料號的好幾張製令散在各處，要一筆一筆用眼睛找。改成一個料號一組、組內列製令。
            $groups = [];
            foreach ($rows as $r) {
                $pk = (int)(ppr_resolve_bom_part($pdo, $r) ?? 0);
                $partNo = (string)($r['d_id'] ?: '未知料號');
                $key = $pk > 0 ? ('p'.$pk) : ('t'.$partNo);
                if (!isset($groups[$key])) {
                    $spec = '';
                    if ($pk > 0) { $pr = ppr_part_row($pdo, $pk); $spec = (string)($pr['Spec_No'] ?? ''); }
                    $groups[$key] = ['d_id'=>$pk, 'part_no'=>$partNo, 'spec'=>$spec, 'boms'=>[]];
                }
                $groups[$key]['boms'][] = [
                    'bom'     => $r['bom'],
                    'sqty'    => $r['sqty'],
                    'created' => eg_fmt_date($r['Created_At']),
                    'period'  => ppr_period_text($r),
                ];
            }
            $out = array_values($groups);
            usort($out, function ($a, $b) {
                $c = count($b['boms']) <=> count($a['boms']);
                return $c !== 0 ? $c : strcmp($a['part_no'], $b['part_no']);
            });
            echo json_encode(['success'=>true, 'groups'=>$out, 'total'=>count($rows), 'capped'=>(count($rows) >= 400)]);
            exit;
        }

        if ($action === 'list_boms') {
            $did = (int)($_POST['d_id'] ?? 0);
            $from = trim($_POST['date_from'] ?? '');
            $to   = trim($_POST['date_to'] ?? '');
            $ignoreRange = !empty($_POST['ignore_range']);   // 直接指定製令號時不受期間限制（見下方說明）
            if ($did <= 0) { echo json_encode(['success'=>false,'error'=>'請選擇料號']); exit; }
            $part = ppr_part_row($pdo, $did);
            if (!$part) { echo json_encode(['success'=>false,'error'=>'找不到料號']); exit; }

            [$cond, $params] = ppr_part_match_cond($part, 'b', 'd_setting_id', 'd_id', 'Client_Name');
            $where = [$cond];
            if (!$ignoreRange) {
                if ($from !== '') { $where[] = "b.Created_At >= ?"; $params[] = $from.' 00:00:00'; }
                if ($to   !== '') { $where[] = "b.Created_At <= ?"; $params[] = $to.' 23:59:59'; }
            }
            $st = $pdo->prepare("SELECT b.bom, b.sqty, b.state, b.Created_At, b.Client_Name,
                       b.processing_state, b.closed_at
                FROM bom b WHERE ".implode(' AND ', $where)." ORDER BY b.Created_At DESC LIMIT 500");
            $st->execute($params);
            $boms = $st->fetchAll(PDO::FETCH_ASSOC);

            $drawings = ppr_resolve_drawings(array_column($boms, 'bom'));
            $rows = [];
            foreach ($boms as $b) {
                $rows[] = [
                    'bom'         => $b['bom'],
                    'created_at'  => eg_fmt_date($b['Created_At']),
                    'period'      => ppr_period_text($b),
                    'sqty'        => $b['sqty'],
                    'client'      => $b['Client_Name'],
                    'state'       => $b['state'],
                    'drawing'     => $drawings[$b['bom']] ?? ['status'=>'none','candidates'=>[]],
                ];
            }
            // 這個料號有哪些附件標籤可勾選帶進報告（每個標籤只會印最新一份）
            $attachCats = [];
            foreach (ppr_part_attach_cats($pdo, $did) as $c) {
                $attachCats[] = ['id'=>$c['id'], 'name'=>$c['name'], 'count'=>$c['count'],
                    'latest'=> $c['latest'] ? eg_fmt_date($c['latest']['eff_date']) : ''];
            }
            echo json_encode(['success'=>true, 'part'=>$part, 'rows'=>$rows, 'attach_cats'=>$attachCats,
                'ignore_range'=>$ignoreRange ? 1 : 0, 'max_batch'=>PPR_MAX_BATCH_COUNT]);
            exit;
        }

        if ($action === 'render_report') {
            $did = (int)($_POST['d_id'] ?? 0);
            $bomList = json_decode($_POST['boms'] ?? '[]', true) ?: [];
            $drawingChoice = json_decode($_POST['drawing_choice'] ?? '{}', true) ?: [];
            $opts = [
                'work_report'    => in_array($_POST['work_report'] ?? '', ['simple'], true) ? 'simple' : 'none',
                'show_cost'      => !empty($_POST['show_cost']) ? 1 : 0,
                'show_freq'      => !empty($_POST['show_freq']) ? 1 : 0,
                'show_qc'        => !empty($_POST['show_qc']) ? 1 : 0,
                'show_work_hist' => !empty($_POST['show_work_hist']) ? 1 : 0,
                'show_price_hist'=> !empty($_POST['show_price_hist']) ? 1 : 0,
                'attach_cats'    => array_values(array_filter(array_map('intval',
                                      json_decode($_POST['attach_cats'] ?? '[]', true) ?: []))),
            ];
            if (!$did || empty($bomList)) { echo json_encode(['success'=>false,'error'=>'缺少料號或製令']); exit; }
            if (count($bomList) > PPR_MAX_BATCH_COUNT) {
                echo json_encode(['success'=>false,'error'=>'單次最多產生 '.PPR_MAX_BATCH_COUNT.' 筆，請縮小期間或減少勾選（目前 '.count($bomList).' 筆）']); exit;
            }
            $part = ppr_part_row($pdo, $did);
            if (!$part) { echo json_encode(['success'=>false,'error'=>'找不到料號']); exit; }

            [$cond, $condParams] = ppr_part_match_cond($part, 'b', 'd_setting_id', 'd_id', 'Client_Name');
            $ph = implode(',', array_fill(0, count($bomList), '?'));
            $st = $pdo->prepare("SELECT b.bom, b.sqty, b.state, b.Created_At, b.Client_Name, b.o_order_id,
                    b.processing_state, b.closed_at
                FROM bom b WHERE $cond AND b.bom IN ($ph) ORDER BY b.Created_At ASC");
            $st->execute(array_merge($condParams, $bomList));
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
            // 料號附件是**掛在料號上**不是掛在製令上，所以整份報告只附一次（放在全部製令頁之後）。
            // 逐筆製令各附一次的話，勾 3 個標籤又選 30 筆製令就會印出 90 張重複的圖。
            if (!empty($opts['attach_cats'])) {
                // 單筆時頁首帶製令號（單獨抽出來看也知道是哪一張單的附件）；多筆時不帶，免得寫了其中一張造成誤會
                $html .= ppr_render_attach_pages($pdo, $part,
                    ($isBatch ? '' : (string)$bomRows[0]['bom']), $opts['attach_cats']);
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
        @media print { .page-help-btn, .ppr-toolbar, .nav_menu, .left_col, footer, .ppr-back-top { display:none !important; } }
        .ppr-back-top { display:none; position:fixed; right:26px; bottom:26px; width:46px; height:46px; border-radius:50%;
            background:#F0A24B; color:#fff; border:1px solid #d98a33; box-shadow:0 3px 12px rgba(0,0,0,.25);
            font-size:18px; cursor:pointer; z-index:500; }
        .ppr-back-top:hover { background:#d98a33; }
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
        #pprPartInput { width:260px; }
        #pprBomInput { width:200px; }
        .ppr-paper-toggle { display:inline-flex; border:1px solid #D8BE93; border-radius:4px; overflow:hidden; }
        .ppr-paper-btn { height:28px; border:none; border-radius:0; background:#fff; color:#5b3a1e; padding:0 12px; cursor:pointer; }
        .ppr-paper-btn + .ppr-paper-btn { border-left:1px solid #D8BE93; }
        .ppr-paper-btn.active { background:#F0A24B; color:#fff; }
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
        /* 料號附件標籤勾選列 */
        .ppr-attach-pick { display:flex; flex-wrap:wrap; gap:6px; }
        .ppr-attach-chk { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:normal;
            border:1px solid #D8BE93; border-radius:14px; padding:3px 11px; cursor:pointer; background:#fff; margin:0; }
        .ppr-attach-chk:hover { background:#FBF0DD; }
        .ppr-attach-chk small { color:#a3865c; }
        /* 客戶 BOM 瀏覽：一個料號一組，可展開看該料號的製令（原本平鋪幾百列很難挑） */
        .ppr-browse-group { border-bottom:1px solid #F3E9D6; }
        .ppr-browse-group:last-child { border-bottom:none; }
        .ppr-browse-head { display:flex; align-items:center; gap:8px; padding:6px 10px; font-size:13px; cursor:pointer; color:#5b3a1e; }
        .ppr-browse-head:hover { background:#FBF0DD; }
        .ppr-browse-head .caret { color:#b5762a; width:10px; }
        .ppr-browse-head .spec { color:#8a6d45; font-size:12px; }
        .ppr-browse-head .cnt { margin-left:auto; font-size:11px; color:#8a6d45; background:#F7E0BD; border-radius:9px; padding:1px 9px; }
        .ppr-browse-body { background:#FDFBF6; padding:2px 0 4px; }
        .ppr-browse-bom { display:flex; align-items:center; gap:12px; padding:4px 10px 4px 30px; font-size:12px; cursor:pointer; color:#5b3a1e; }
        .ppr-browse-bom:hover { background:#FBF0DD; }
        .ppr-browse-bom .q { color:#8a6d45; }
        .ppr-browse-bom .p { margin-left:auto; color:#a3865c; font-size:11px; }

        /* ══════ 報告版面（螢幕預覽用陰影卡片；列印時去邊框改用 @page 分頁） ══════
         * 螢幕上：報告區塊本身就是「選A4就長得像A4、選A3就長得像A3」的實際版面（非另外算的排版），
         * 列印鍵＝原樣把這個畫面轉成印表機輸出，沒有另一套「列印專用排版」，所見即所印。
         * A4 模式螢幕上額外用 flex-wrap 讓多頁可以兩頁併排顯示，避免長報告要一直往下捲；
         * 這只是「螢幕預覽排法」，列印時 @media print 一律強制改回單欄、一張接一張分頁。 */
        .ppr-report-area { background:#EDE6D8; padding:16px 0 60px; }
        .ppr-report-area:not(.ppr-paper-a3) { display:flex; flex-wrap:wrap; justify-content:center; align-items:flex-start; gap:20px; }
        /* 【版面口徑】白邊一律由 `.ppr-page` 自己的 padding 給（8mm），**@page 的 margin 固定 0**。
         * 這樣螢幕上的白色方塊就是整張紙、內容區也與列印時完全相同，「螢幕上排得下」＝「印出來排得下」，
         * A3 自動收成一張才會真的成立（先前螢幕用全紙寬、列印又被 @page 邊界再縮一次，
         * 量到的高度根本不是列印時的高度，會變成畫面收成一張、印出來卻兩張）。
         * @page margin:0 同時去掉瀏覽器自己的頁首頁尾網址列（同 ai-rules/16 的既有做法）。
         * 白邊總量從原本的 @page 12mm ＋ padding 16mm＝28mm 降為 8mm，
         * 對應使用者回報的「邊界留白太多、文字定位點有問題」。 */
        .ppr-page { width:210mm; min-height:297mm; margin:0 auto 20px; padding:8mm; background:#fff;
            box-shadow:0 2px 10px rgba(90,60,20,.18); box-sizing:border-box; font-size:12.5px; color:#382a1a; }
        .ppr-page-inner { box-sizing:border-box; }
        .ppr-report-area:not(.ppr-paper-a3) .ppr-page { margin:0; }

        /* ── A3＝橫式，整份報告收在同一張紙內（使用者明確要求）──
         * 420×297mm 橫放扣掉 8mm 內距＝404×281mm 內容區；表頭/圖面/流程總覽橫跨整頁，其餘段落多欄由上往下流；
         * 仍然放不下時由 JS 自動加欄數再等比縮小（pprFitPages），到極限還是放不下就明講會跨頁，不偷偷裁掉內容。 */
        /* 寬高刻意比紙張各小 1mm：等於紙張時只要有一點點捨入誤差就會多吐一張空白頁（使用者回報） */
        .ppr-report-area.ppr-paper-a3 .ppr-page { width:419mm; min-height:296mm; height:296mm; overflow:hidden; font-size:12px; }
        .ppr-report-area.ppr-paper-a3 .ppr-page-main { column-count:2; column-gap:8mm; }
        /* A3 表頭：圖面只佔**左上約 1/4**，右側放基本資料與流程總覽——圖面沒有大到需要佔滿整列，
         * 佔整列等於白白吃掉半張 A3（使用者回報）。`.ppr-body` 用 display:contents 讓它的兩個子元素
         * 直接變成這個 grid 的成員，不必為了版面再改一次 HTML 結構。 */
        /* 第 4 列 1fr 是**刻意留的鬆弛列**：圖面跨列且比右欄內容高，若只有三列，多出來的高度會被平均
         * 分配到資訊表與流程總覽那兩列之間，右欄中間就多出一塊百餘 px 的空白（實測 116px）。 */
        .ppr-report-area.ppr-paper-a3 .ppr-head-block { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,2.1fr);
            grid-template-rows:auto auto auto 1fr; column-gap:6mm; align-items:start; }
        .ppr-report-area.ppr-paper-a3 .ppr-doc-head { grid-column:1 / -1; grid-row:1; }
        .ppr-report-area.ppr-paper-a3 .ppr-body { display:contents; }
        .ppr-report-area.ppr-paper-a3 .ppr-info-grid { grid-column:2; grid-row:2; margin-bottom:8px; }
        .ppr-report-area.ppr-paper-a3 .ppr-flow-box { grid-column:2; grid-row:3; }
        .ppr-report-area.ppr-paper-a3 .ppr-drawing-box { grid-column:1; grid-row:2 / span 3; margin:0; align-self:start; }
        /* 只有一張圖時壓在 1/4 高度內；本 BOM 另外帶了料號附件圖時放寬（多張圖本來就需要空間） */
        .ppr-report-area.ppr-paper-a3 .ppr-drawing-img { max-height:120mm; }
        .ppr-report-area.ppr-paper-a3 .ppr-drawing-frame { height:120mm; }
        .ppr-report-area.ppr-paper-a3 .ppr-page-main .ppr-section { break-inside:avoid-column; margin:0 0 10px; }
        .ppr-report-area.ppr-paper-a3 .ppr-proc-cards { display:flex; flex-direction:column; gap:8px; }

        @media print {
            /* 頁面本身的標題列不進列印版（使用者要求：不要印出「料號製程履歷報告 圖面／製程／…一次整合」那一行） */
            .page-title, .ppr-toolbar, .nav_menu, .left_col, footer { display:none !important; }
            /* custom.min.js（Gentelella）會在載入時把 .right_col 的 min-height 設成整個視窗高度，
             * 那段空白會接在最後一張報告後面，讓列印多吐一張空白頁——必須在列印時清掉。 */
            .right_col { margin:0 !important; padding:0 !important; min-height:0 !important; height:auto !important; }
            html, body, .container.body, .main_container { margin:0 !important; padding:0 !important;
                min-height:0 !important; height:auto !important; }
            .ppr-report-area { background:none; padding:0; display:block !important; }
            /* 寬高與內距**刻意維持與螢幕完全相同**，只拿掉陰影與外距；改成 auto 會讓版面在列印時重排，
             * 螢幕上量好的 A3 收頁結果就會失效（實測會變成兩頁）。 */
            .ppr-page { box-shadow:none; margin:0 !important; page-break-after:always; }
            .ppr-page:last-child { page-break-after:auto; }
            /* 保證背景色/徽章色列印跟畫面上一致，不被瀏覽器「省墨」預設值吃掉 */
            .ppr-page, .ppr-page * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; color-adjust:exact !important; }
        }
        .ppr-fit-warn { margin:4px 0 0; font-size:11px; color:#DD5138; }
        @media print { .ppr-fit-warn { display:none; } }

        .ppr-head-block { page-break-inside:avoid; }
        .ppr-doc-head { display:flex; justify-content:space-between; align-items:baseline; border-bottom:3px solid #8A5A2B; padding-bottom:8px; margin-bottom:10px; }
        .ppr-company { font-size:20px; font-weight:bold; color:#4a2f16; letter-spacing:.5px; }
        .ppr-doctitle { font-size:13px; color:#8a6d45; }

        .ppr-info-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:1px; background:#EADFC8;
            border:1px solid #EADFC8; border-radius:6px; overflow:hidden; margin-bottom:14px; }
        .ppr-info-item { background:#fff; padding:6px 12px; display:flex; flex-direction:column; gap:1px; }
        .ppr-info-item .k { font-size:10.5px; color:#a3865c; }
        .ppr-info-item .v { font-size:13.5px; color:#382a1a; font-weight:600; word-break:break-word; }
        .ppr-info-item.ppr-info-wide { grid-column:1 / -1; }

        .ppr-body { display:flex; gap:16px; margin-bottom:16px; }
        .ppr-drawing-box { flex:1 1 46%; border:1px solid #EADFC8; border-radius:8px; min-height:220px; display:flex;
            align-items:center; justify-content:center; background:#FBFAF7; padding:8px; }
        .ppr-drawing-img { max-width:100%; max-height:380px; object-fit:contain; }
        .ppr-drawing-frame { width:100%; height:380px; border:none; }
        .ppr-drawing-empty { color:#b0a68f; font-size:13px; }
        .ppr-flow-box { flex:1 1 54%; }
        .ppr-flow-box h4, .ppr-section h4 { color:#8A5A2B; font-size:13.5px; font-weight:700; letter-spacing:.5px;
            margin:0 0 8px; padding-bottom:5px; border-bottom:2px solid #F7E0BD; }
        /* 【分頁】區塊本身**允許**跨頁：整段 avoid 時，只要剩餘空間放不下一整段（製程詳細資料常有 1500px 以上），
         * 瀏覽器就把整段推到下一頁，於是上一頁下半部整片空白——使用者回報「A4 列印畫面很糟糕」就是這個。
         * 改成只讓「最小不可切割單位」avoid：製程卡片、表格列、標題不可與內容分離。 */
        .ppr-section { margin:16px 0; page-break-inside:auto; break-inside:auto; }
        .ppr-section > h4, .ppr-flow-box > h4 { page-break-after:avoid; break-after:avoid; }
        table.ppr-meas-table, table.ppr-work-table, table.ppr-work-hist,
        table.ppr-cost-detail, table.ppr-freq-table { page-break-inside:auto; break-inside:auto; }
        table.ppr-cost-table { page-break-inside:avoid; break-inside:avoid; }
        .ppr-page tr, .ppr-page thead { page-break-inside:avoid; break-inside:avoid; }
        .ppr-page thead { display:table-header-group; }

        /* 流程總覽：簡約橫排（製程名稱＋狀態徽章，中間以箭頭銜接；刻意不放 1234 數字標籤） */
        .ppr-stepper { display:flex; align-items:center; flex-wrap:wrap; gap:4px 2px; }
        .ppr-chip { display:inline-flex; align-items:center; gap:5px; border:1px solid #EADFC8; background:#FDF8EF;
            border-radius:13px; padding:2px 4px 2px 10px; white-space:nowrap; }
        .ppr-chip .nm { font-size:12px; color:#382a1a; font-weight:600; line-height:1.4; }
        .ppr-chip .sp { font-size:9.5px; color:#8a6d45; background:#F3EDE1; border-radius:7px; padding:0 5px; line-height:15px; }
        .ppr-chip .st { font-size:10px; color:#fff; border-radius:10px; padding:1px 8px; line-height:14px; }
        .ppr-arrow { color:#C9A97A; font-size:13px; padding:0 3px; line-height:1; }

        /* 製程詳細卡片 */
        .ppr-proc-cards { display:flex; flex-direction:column; gap:10px; }
        .ppr-report-area.ppr-paper-a3 .ppr-proc-cards { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .ppr-proc-card { border:1px solid #EADFC8; border-left:4px solid #999; border-radius:6px; page-break-inside:avoid; overflow:hidden; }
        .ppr-proc-head { background:#FDF8EF; padding:7px 12px; display:flex; align-items:center; gap:8px; font-size:13px; }
        .ppr-proc-idx { background:#8A5A2B; color:#fff; border-radius:50%; width:19px; height:19px; flex:0 0 auto;
            display:inline-flex; align-items:center; justify-content:center; font-size:10.5px; }
        .ppr-proc-status { margin-left:auto; color:#fff; border-radius:10px; padding:2px 10px; font-size:11px; white-space:nowrap; }
        .ppr-proc-body { padding:8px 12px; }
        .ppr-batch-row { border-top:1px dashed #EADFC8; padding:6px 0; font-size:12px; }
        .ppr-batch-row:first-child { border-top:none; padding-top:0; }
        .ppr-batch-head { color:#5b3a1e; margin-bottom:3px; }
        .ppr-consumed-tag { font-size:10px; color:#a3865c; background:#F3EDE1; border-radius:8px; padding:1px 7px; margin-left:4px; }
        .ppr-qc-batch { margin:4px 0 4px 4px; }
        .ppr-qc-batch-title { font-size:11px; color:#8a6d45; font-weight:600; margin-bottom:2px; }
        .ppr-qc-round { font-size:11.5px; margin:2px 0 2px 8px; }
        .ppr-qc-round-tag { background:#F7E0BD; color:#5b3a1e; border-radius:4px; padding:0 6px; font-size:10.5px; }
        .ppr-qc-round-date { color:#a3865c; }
        .ppr-aod-tag { background:#F0A24B; color:#fff; border-radius:8px; padding:0 6px; font-size:10px; }
        .ppr-work-title { font-size:11px; color:#8a6d45; font-weight:600; margin:4px 0 2px; }

        table.ppr-meas-table, table.ppr-work-table, table.ppr-work-hist, table.ppr-cost-table, table.ppr-cost-detail, table.ppr-freq-table {
            width:100%; border-collapse:collapse; font-size:11px; margin:3px 0; }
        table.ppr-meas-table th, table.ppr-meas-table td,
        table.ppr-work-table th, table.ppr-work-table td,
        table.ppr-work-hist th, table.ppr-work-hist td,
        table.ppr-cost-table th, table.ppr-cost-table td,
        table.ppr-cost-detail th, table.ppr-cost-detail td,
        table.ppr-freq-table th, table.ppr-freq-table td { border:1px solid #EFE7D8; padding:3px 7px; text-align:left; }
        table.ppr-meas-table th, table.ppr-work-table th, table.ppr-work-hist th, table.ppr-cost-detail th, table.ppr-freq-table th { background:#FAF3E4; color:#8a6d45; font-weight:600; }
        /* 同料號歷史報工：只有一列表頭，其餘純資料（使用者要求「不需一直重複出現標題中文」） */
        table.ppr-work-hist { font-size:10.5px; }
        table.ppr-work-hist th, table.ppr-work-hist td { padding:2px 6px; white-space:nowrap; }
        table.ppr-work-hist tr.same-m td { background:#FBF0DD; }
        table.ppr-work-hist tr.same-m td:first-child { font-weight:600; }
        /* 歷史加工價格儲存格：一行一筆「日期 單價 ×數量」，窄欄也讀得清楚 */
        .ppr-ph { display:flex; flex-direction:column; gap:1px; }
        .ppr-ph .ph-row { display:flex; gap:6px; align-items:baseline; font-size:10.5px; white-space:nowrap; }
        .ppr-ph .ph-row .d { color:#a3865c; }
        .ppr-ph .ph-row .p { color:#382a1a; font-weight:600; }
        .ppr-ph .ph-row .q, .ppr-ph .ph-row .m { color:#a3865c; font-size:10px; }
        .ppr-ph-note { font-size:10px; color:#DD5138; margin-bottom:1px; }
        table.ppr-cost-table th { background:#F7E0BD; width:110px; color:#5b3a1e; }
        table.ppr-work-table th { width:80px; }
        table.ppr-meas-table tbody tr:nth-child(even), table.ppr-freq-table tbody tr:nth-child(even) { background:#FCFAF5; }

        /* 料號附件頁：一份附件一整頁，頁首標籤名稱＋備註，圖撐滿剩下的空間。
         * 高度必須釘死（不能只有 min-height）：`height:100%` 的內層要有確定的父層高度才算得出來，
         * 否則圖片會用原始尺寸輸出、直接溢出紙張。A3 由 .ppr-paper-a3 .ppr-page 覆寫成 296mm。 */
        .ppr-attach-page { height:297mm; overflow:hidden; }
        .ppr-attach-page .ppr-page-inner { display:flex; flex-direction:column; height:100%; }
        .ppr-attach-head { display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; font-size:13px; margin:4px 0 2px; }
        .ppr-attach-head .lb { font-weight:700; color:#5b3a1e; font-size:15px; }
        .ppr-attach-head .rv { font-size:11px; color:#8a6d45; background:#F7E0BD; border-radius:8px; padding:1px 8px; }
        .ppr-attach-head .fn { font-size:11px; color:#a3865c; }
        .ppr-attach-head .dt { margin-left:auto; font-size:12px; color:#8a6d45; }
        .ppr-attach-note { font-size:12px; color:#5b3a1e; background:#FDF8EF; border:1px solid #EADFC8;
            border-radius:5px; padding:4px 9px; margin-bottom:6px; white-space:pre-wrap; }
        .ppr-attach-body { flex:1 1 auto; min-height:0; display:flex; align-items:center; justify-content:center; }
        .ppr-attach-img { display:block; max-width:100%; max-height:100%; object-fit:contain; }
        .ppr-attach-frame { width:100%; height:100%; min-height:200mm; border:none; }
        .ppr-attach-none { font-size:12px; color:#b0a68f; }

        .ppr-freq-cols { display:flex; gap:16px; align-items:flex-start; }
        .ppr-freq-cols > div { flex:1; min-width:0; }
        .ppr-freq-cols b { font-size:12.5px; color:#5b3a1e; }
        .ppr-freq-meta { font-size:11px; color:#8a6d45; margin:4px 0; }
        /* 出貨沒綁訂單時，製程欄改顯示出貨單的「規格」欄內容：虛線底標明來源不同，列印也看得出來 */
        .ppr-spec-fb { border-bottom:1px dotted #C9A97A; color:#6b5030; }
        .ppr-muted { color:#b0a68f; font-size:12px; }

        .ppr-summary-charts { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:14px; }
        .ppr-chart-box { flex:1 1 30%; min-width:260px; border:1px solid #EADFC8; border-radius:8px; padding:10px; page-break-inside:avoid; }
        .ppr-chart-box h4 { margin:0 0 6px; font-size:12.5px; color:#8A5A2B; font-weight:700; }
        canvas.ppr-chart { width:100% !important; height:200px !important; }
    </style>
    <style id="pprPageSizeStyle">@page { size:A4 portrait; margin:0; }</style>
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
                <label>或製令號</label>
                <span class="ppr-typeahead">
                    <input type="text" id="pprBomInput" placeholder="輸入製令(BOM)號碼…" autocomplete="off">
                    <input type="hidden" id="pprBomHidden">
                    <div class="ppr-suggest" id="pprBomSuggest"></div>
                </span>
            </div>
            <div class="row2">
                <label>期間</label>
                <input type="date" id="pprDateFrom" value="<?= date('Y-m-01') ?>">～<input type="date" id="pprDateTo" value="<?= date('Y-m-d') ?>">
                <button id="pprSearchBtn"><i class="fa fa-search"></i> 查詢此料號筆數</button>
                <button id="pprBrowseClientBtn" style="display:none;"><i class="fa fa-list"></i> 瀏覽此客戶期間內所有BOM</button>
            </div>
            <div class="row2">
                <label><input type="checkbox" id="pprOptQc" value="1" checked> 顯示QC檢驗內容</label>
                <label><input type="checkbox" id="pprOptWork" value="1"> 帶入報工簡表</label>
                <label title="在每個製程的報工簡表下方，加列同一料號同一製程最近 5 筆報工（相同機台優先）"><input type="checkbox" id="pprOptWorkHist" value="1"> 顯示同料號歷史報工</label>
                <label><input type="checkbox" id="pprOptCost" value="1" checked> 顯示成本毛利</label>
                <label title="在成本明細右側加列此廠商×此料號×此製程過去的實際發包單價"><input type="checkbox" id="pprOptPriceHist" value="1"> 顯示歷史加工價格</label>
                <label><input type="checkbox" id="pprOptFreq" value="1" checked> 顯示訂單/出貨頻率（僅單筆模式）</label>
                <label>紙張</label>
                <span class="ppr-paper-toggle">
                    <button type="button" class="ppr-paper-btn active" data-size="A4">A4 直式</button>
                    <button type="button" class="ppr-paper-btn" data-size="A3">A3 橫式（整份收在一張）</button>
                </span>
            </div>
            <div id="pprClientBrowseWrap" style="display:none;">
                <div class="ppr-count-bar" id="pprClientBrowseCount"></div>
                <div style="margin:4px 0 6px;"><input type="text" id="pprBrowseFilter" placeholder="在結果中篩選料號/規格…" style="width:260px;"></div>
                <div class="ppr-bom-list" id="pprClientBrowseList"></div>
            </div>
            <div id="pprAttachWrap" style="display:none;">
                <div class="ppr-count-bar">料號附件（勾選要一併印進報告的標籤；<b>每個標籤只印最新的一份</b>，附件上會標明標籤名稱與備註）</div>
                <div class="ppr-attach-pick" id="pprAttachCats"></div>
            </div>
            <div id="pprBomListWrap" style="display:none;">
                <div class="ppr-count-bar"><b id="pprBomListTitle"></b>　<label><input type="checkbox" id="pprSelAll"> 全選</label>　已選 <span id="pprSelCount">0</span> / 上限 <span id="pprMaxCount">30</span> 筆</div>
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
        <h4>二、操作步驟（三種找到料號的方式，任選一種）</h4>
        <ul>
            <li><b>直接打料號</b>：輸入框下方會即時跳出符合的料號建議清單（含所屬客戶名稱、目前選定期間內有幾筆BOM，避免同料號不同客戶混淆或打了半天沒資料）。</li>
            <li><b>先選客戶</b>：客戶欄一樣打字模糊搜尋（同名客戶會自動標示縣市/區，甚至到路名區分）；選定後按「瀏覽此客戶期間內所有BOM」，結果<b>依料號分組</b>（一個料號一列，右側顯示它有幾筆製令），點料號展開該料號的製令、點製令直接選定；上方另有篩選框可再輸入料號或規格縮小範圍。</li>
            <li><b>直接打製令(BOM)號碼</b>：右側「或製令號」欄可直接搜尋 BOM 號碼，選到後會自動帶入對應料號並查詢，該筆也會自動勾選。<b>用這條路進來時會忽略上方的「期間」</b>（期間預設本月，而直接打進來的製令多半是舊單，照期間篩會變成查不到），清單標題會標示「（不限期間）」。</li>
            <li>找到料號後按「查詢此料號筆數」列出期間內的製令(BOM)清單（清單標題會顯示共有幾筆）。若某製令的圖面在 Z:/BOM/ 有多個副檔名的精確匹配檔，會列出候選清單，需先選定要用哪一個才能產生報告；找不到精確匹配檔則顯示「找不到圖面」。</li>
            <li>期間內只有 1 筆 → 直接產生單筆報告（可另外顯示訂單/出貨頻率分析）。多筆 → 勾選要產生的製令（可全選，上限 <?= PPR_MAX_BATCH_COUNT ?> 筆），按「產生報告」；同一份文件內連續呈現，最後加一頁總體趨勢分析。</li>
            <li><b>紙張大小</b>：報告產生後可隨時點「A4 直式」／「A3 橫式」按鈕即時切換排版，選好再按「列印/產生PDF」。<b>A3 一律橫式並把整份報告收在同一張紙內</b>：圖面放左上角、段落自動改成多欄流排（2→3→4 欄），仍放不下才等比縮小字級（縮放比例是實測出來的，會用放得下的最大比例，不會縮過頭）；欄數加滿又縮到下限還是放不下時，畫面上會出現紅字提醒「列印會分成兩頁」，<b>內容不會被裁掉</b>。A4 則交給瀏覽器原生分頁，段落可跨頁、只有製程卡片與表格列不切開。</li>
            <li><b>料號附件（選配）</b>：選定料號後，工具列會列出這個料號有哪些附件標籤，勾起來就會<b>各自獨立成頁附在該筆製令報告後面</b>（不是塞進報告角落——圖面擠在小格子裡等於印了也看不懂）。<b>同一個標籤只印最新的一份</b>，每一頁頁首標明標籤名稱、版次、檔名、發行日期與備註。</li>
        </ul>
        <h4>三、重要行為 / 常見疑問</h4>
        <div class="tip">
            <b>製程順序</b>：一律依 bom_sn（10/20/30/40…）排，與生管的 BOM 總表／已完工BOM查詢完全一致。<br>
            <b>廠商與機台</b>：廠商一律顯示（含「超正齒研」「客戶」這類廠內自有單位）；機台顯示<b>現場編號</b>，製令上沒填機台時自動改抓報工紀錄裡實際報的那一台，兩邊都查不到就不顯示（全站沒有「在製令上指定機台」這個功能，所以不再印「未指定機台」）。<br>
            <b>製令建立～結案日期</b>：結案與否看 processing_state，結案日取 closed_at。2026-05-22「手動結案」功能上線前的舊製令沒有結案時間可查，會顯示「已結案（無結案日期紀錄）」——<b>不會拿 BOM 編號回推的日期硬湊</b>（那個推算值其實是建立日，湊出來會變成結案早於建立）。<br>
            <b>同料號歷史報工</b>（選配）：掛在該製程的報工簡表下方，列同一料號同一製程最近 5 筆，相同機台的排前面並以底色標示，不同機台也會列出來當參考。需一併勾選「帶入報工簡表」（勾了會自動幫你勾）。<br>
            <b>歷史加工價格</b>（選配）：在成本明細加一欄，列此廠商×此料號×此製程過去的實際發包單價，格式為「日期　x數量pcs　$單價」。同一廠商查無紀錄時會自動放寬列出其他廠商的，並在欄位內標明「同廠商無紀錄，改列其他廠商」。<br>
            <b>為什麼歷史訂單／出貨沒有「對象」欄</b>：本報告只列這個料號的單，對象必定就是表頭那個客戶，印出來每一列都是同一個名字而已。<br>
            <b>料號附件只取最新一份</b>：判定用發行章日期優先、沒有才用上傳時間（與 ai-rules/15「圖面變更看發行章日期」一致）。批圖編輯器的工作檔與其輸出圖一律不列（那是暫存圖不是正式圖面）。同一份檔案同時是兩個被勾標籤的最新版時只會印一次，標籤名以「・」合併。<br>
            <b>歷史訂單／出貨的「製程」欄</b>：同一個料號常有「只做齒研」與「代料到成品」等不同加工範圍的單，單價自然差很多，所以一定要對照這一欄再看單價。訂單取自訂單的加工項目；出貨取自它所綁訂單的同一欄位，<b>但出貨單絕大多數沒有綁訂單（全站 99.6% 的出貨沒有訂單編號），此時改顯示出貨單的「規格」欄內容</b>——ERP 轉進來的資料本來就把製程與品名混寫在那一欄（例「齒輪／齒研」「馬達齒輪-代料至齒研」）。這種退路顯示的文字會加<span class="ppr-spec-fb">虛線底</span>，提醒你那是規格欄不是正式的製程欄位；兩邊都沒有才顯示「—」。<br>
            <b>找得到 BOM 卻產不出報告？</b>：全站約八成的製令沒有填料號的整數外鍵（只有料號文字），本頁已同時用兩種方式歸戶，所以舊製令也查得到；若某張製令的料號在料號主檔完全查不到，建議清單會直接標紅說明無法產生報告。<br>
            <b>圖面判定</b>：只認「檔名去副檔名恰好等於製令號碼」的檔案，任何帶後綴的變體檔名一律不算候選。<br>
            <b>拆批/複驗歷程</b>：製程若曾被拆成多批（A/B/C），卡片內會列出每個批次各自的檢驗歷程與判定，即使該批次後續已被合併消耗（歷史批次仍標示「已拆分/合併」但檢驗紀錄不會被隱藏）。<br>
            <b>QC檢驗內容</b>：勾選「顯示QC檢驗內容」會列出每輪檢驗的批次/輪次判定，若該輪有逐項量測資料（項目/標準/實測值/判定）也會一併列出。<br>
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

<button id="pprBackTop" class="ppr-back-top" title="回頂端"><i class="fa fa-arrow-up"></i></button>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/Chart.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
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

$(window).on('scroll', function(){
    if ($(window).scrollTop() > 400) $('#pprBackTop').fadeIn(150); else $('#pprBackTop').fadeOut(150);
});
$('#pprBackTop').on('click', function(){ $('html,body').animate({scrollTop:0}, 350); });

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
    onClear:function(){ $('#pprPartInput').val(''); $('#pprPartId').val(''); $('#pprBrowseClientBtn').hide(); $('#pprClientBrowseWrap').hide(); },
    onPick:function(){ $('#pprBrowseClientBtn').show(); }
});
pprSetupTypeahead({
    inputSel:'#pprPartInput', hiddenSel:'#pprPartId', boxSel:'#pprPartSuggest', action:'search_parts',
    extraParams:function(){ return {customer_id: $('#pprClientId').val(), date_from: $('#pprDateFrom').val(), date_to: $('#pprDateTo').val()}; }
});
pprSetupTypeahead({
    inputSel:'#pprBomInput', hiddenSel:'#pprBomHidden', boxSel:'#pprBomSuggest', action:'search_boms',
    onPick:function(it){
        if (!it.id) { alert('這張製令的料號在料號主檔查不到，無法產生報告。'); return; }
        $('#pprPartInput').val(it.text); $('#pprPartId').val(it.id);
        $('#pprBomInput').val('');
        // 直接指定製令號時一律忽略上方的「期間」：期間預設是本月，而使用者打進來的製令多半是舊單
        // （例 B-1140807011 是 2025 年的），照期間篩就會變成「跳一個提示然後清單整個不見」＝使用者回報的症狀。
        pprDoSearch(it.bom, true);
    }
});

var PPR_HIGHLIGHT_BOM = null;

function pprDoSearch(highlightBom, ignoreRange){
    var did = $('#pprPartId').val();
    if (!did) { alert('請先從建議清單選擇一個料號'); return; }
    PPR_HIGHLIGHT_BOM = highlightBom || null;
    var from = $('#pprDateFrom').val(), to = $('#pprDateTo').val();
    $.post(PPR_API, {action:'list_boms', d_id:did, date_from:from, date_to:to, ignore_range: ignoreRange ? 1 : 0}, function(res){
        if (!res.success) { alert(res.error||'查詢失敗'); return; }
        PPR_ROWS = res.rows; PPR_DRAWING_CHOICE = {};
        pprRenderAttachCats(res.attach_cats || []);
        $('#pprMaxCount').text(res.max_batch);
        var scope = res.ignore_range ? '（不限期間）' : '期間內';
        $('#pprBomListTitle').text('「'+$('#pprPartInput').val()+'」'+scope+'共 '+PPR_ROWS.length+' 筆製令');
        pprRenderBomList();
        $('#pprBomListWrap').show();
        $('#pprClientBrowseWrap').hide();
        $('#pprReportArea').empty(); $('#pprPrintBtn').hide();
        if (PPR_HIGHLIGHT_BOM) {
            var $hit = $('.ppr-bom-chk[data-bom="'+PPR_HIGHLIGHT_BOM+'"]').closest('.ppr-bom-row');
            if ($hit.length) $('html,body').animate({scrollTop: $hit.offset().top - 120}, 250);
        }
    }, 'json');
}
$('#pprSearchBtn').on('click', function(){ pprDoSearch(); });

/** 料號附件標籤勾選列：沒有附件就整塊不顯示（不要留一塊空的說明文字） */
function pprRenderAttachCats(cats){
    var $w = $('#pprAttachCats').empty();
    if (!cats.length) { $('#pprAttachWrap').hide(); return; }
    cats.forEach(function(c){
        var $l = $('<label class="ppr-attach-chk"><input type="checkbox" class="ppr-att-cat" value="'+c.id+'"> '
            + '<b>'+egEsc(c.name)+'</b> <small>'+c.count+' 份'+(c.latest?('・最新 '+egEsc(c.latest)):'')+'</small></label>');
        $w.append($l);
    });
    $('#pprAttachWrap').show();
}
// 勾「同料號歷史報工」但沒勾「帶入報工簡表」＝什麼都不會出現，直接替使用者一起勾起來
$('#pprOptWorkHist').on('change', function(){ if ($(this).is(':checked')) $('#pprOptWork').prop('checked', true); });
$('#pprOptPriceHist').on('change', function(){ if ($(this).is(':checked')) $('#pprOptCost').prop('checked', true); });

$('#pprBrowseClientBtn').on('click', function(){
    var cid = $('#pprClientId').val();
    if (!cid) { alert('請先從建議清單選擇一個客戶'); return; }
    var from = $('#pprDateFrom').val(), to = $('#pprDateTo').val();
    $.post(PPR_API, {action:'browse_customer_boms', customer_id:cid, date_from:from, date_to:to}, function(res){
        if (!res.success) { alert(res.error||'查詢失敗'); return; }
        PPR_BROWSE_GROUPS = res.groups || [];
        $('#pprClientBrowseCount').html('此客戶期間內共 <b>'+res.total+'</b> 筆製令，分屬 <b>'+PPR_BROWSE_GROUPS.length
            +'</b> 個料號（點料號＝列出它的全部製令；點某一張製令＝直接選定該筆）'
            + (res.capped ? '　<span style="color:#DD5138;">資料量過大僅取最近 400 筆，請縮小期間</span>' : ''));
        $('#pprBrowseFilter').val('');
        pprRenderBrowseGroups('');
        $('#pprClientBrowseWrap').show();
        $('#pprBomListWrap').hide();
    }, 'json');
});

/**
 * 客戶 BOM 瀏覽：一個料號一組（可展開看該料號底下的製令）。
 * 原本是把幾百筆製令平鋪成一長串，同一個料號的製令散落各處，使用者回報「列出料號的方式很難去選擇」。
 */
var PPR_BROWSE_GROUPS = [];
function pprRenderBrowseGroups(kw){
    var $wrap = $('#pprClientBrowseList').empty();
    kw = (kw||'').trim().toLowerCase();
    var shown = 0;
    PPR_BROWSE_GROUPS.forEach(function(g, gi){
        var hay = (g.part_no+' '+(g.spec||'')).toLowerCase();
        if (kw && hay.indexOf(kw) === -1) return;
        shown++;
        var $g = $('<div class="ppr-browse-group"></div>');
        var $head = $('<div class="ppr-browse-head">'
            + '<span class="caret">▸</span><b>'+egEsc(g.part_no)+'</b>'
            + (g.spec ? ' <span class="spec">'+egEsc(g.spec)+'</span>' : '')
            + '<span class="cnt">'+g.boms.length+' 筆製令</span></div>');
        var $body = $('<div class="ppr-browse-body" style="display:none;"></div>');
        g.boms.forEach(function(b){
            var $r = $('<div class="ppr-browse-bom"><b>'+egEsc(b.bom)+'</b>'
                + '<span class="q">數量 '+egEsc(b.sqty)+'</span>'
                + '<span class="p">'+egEsc(b.period)+'</span></div>');
            $r.on('click', function(e){
                e.stopPropagation();
                if (!g.d_id) { alert('這個料號在料號主檔查不到，無法產生報告。'); return; }
                $('#pprPartInput').val(g.part_no); $('#pprPartId').val(g.d_id);
                pprDoSearch(b.bom, true);
            });
            $body.append($r);
        });
        $head.on('click', function(){
            var open = $body.is(':visible');
            $body.toggle(!open);
            $head.find('.caret').text(open ? '▸' : '▾');
            if (!open && g.d_id) { $('#pprPartInput').val(g.part_no); $('#pprPartId').val(g.d_id); }
        });
        $g.append($head).append($body);
        $wrap.append($g);
    });
    if (!shown) $wrap.html('<div class="ppr-bom-row">'+(kw ? '沒有符合「'+egEsc(kw)+'」的料號。' : '此期間查無資料。')+'</div>');
}
function egEsc(s){ return $('<div>').text(s === null || s === undefined ? '' : s).html(); }
$('#pprBrowseFilter').on('input', function(){ pprRenderBrowseGroups($(this).val()); });

function pprRenderBomList(){
    var $wrap = $('#pprBomList').empty();
    if (!PPR_ROWS.length) { $wrap.html('<div class="ppr-bom-row">此期間查無製令資料。</div>'); pprUpdateCount(); return; }
    PPR_ROWS.forEach(function(r, idx){
        var dwStatus = r.drawing.status;
        var dwText = dwStatus==='none' ? '<span class="dw-status-none">找不到圖面</span>'
                   : dwStatus==='single' ? '<span class="dw-status-single">圖面：'+r.drawing.candidates[0].filename+'</span>'
                   : '<span class="dw-status-multiple">圖面：'+r.drawing.candidates.length+' 個候選，請選擇 →</span>';
        var checked = (PPR_ROWS.length===1 || r.bom===PPR_HIGHLIGHT_BOM) ? 'checked' : '';
        var $row = $('<div class="ppr-bom-row">'
            + '<input type="checkbox" class="ppr-bom-chk" data-bom="'+r.bom+'" '+checked+'>'
            + '<b>'+egEsc(r.bom)+'</b> <span style="color:#a3865c;">'+egEsc(r.period||r.created_at)+'</span>'
            + ' 數量'+egEsc(r.sqty)+' '+egEsc(r.client||'')+' '+dwText
            + '</div>');
        if (r.bom === PPR_HIGHLIGHT_BOM) $row.css({background:'#FFF7E8'});
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
        show_qc: $('#pprOptQc').is(':checked') ? 1 : 0,
        show_work_hist: $('#pprOptWorkHist').is(':checked') ? 1 : 0,
        show_price_hist: $('#pprOptPriceHist').is(':checked') ? 1 : 0,
        attach_cats: JSON.stringify($('.ppr-att-cat:checked').map(function(){ return parseInt(this.value,10); }).get())
    }, function(res){
        if (!res.success) { alert(res.error||'產生失敗'); return; }
        $('#pprReportArea').html(res.html);
        pprApplyPaperState();
        pprInitCharts();
        $('#pprPrintBtn').show();
        $('html,body').animate({scrollTop: $('#pprReportArea').offset().top - 60}, 300);
    }, 'json');
});

/* 紙張大小：純前端切換（不需重新向後端要資料），點了立即重排版。
 * A3 一律**橫式**且整份報告收在同一張紙（使用者明確要求）：段落改雙欄流排，放不下再由 pprFitPages 等比縮小。 */
function pprApplyPaperState(){
    var size = $('.ppr-paper-btn.active').data('size') || 'A4';
    var css = size === 'A3' ? '@page { size:A3 landscape; margin:0; }' : '@page { size:A4 portrait; margin:0; }';
    $('#pprPageSizeStyle').text(css);
    $('#pprReportArea').toggleClass('ppr-paper-a3', size === 'A3');
    pprFitPages();
}

/**
 * A3 模式把每一頁收進 297mm 高（一張 A3 一筆製令）。順序刻意是「先加欄數，不夠才縮字級」：
 * A3 橫放有 420mm 寬，排到 3～4 欄每欄仍有 140/105mm，比把字縮到看不清楚好讀得多。
 * 刻意**不裁切內容**：欄數加滿又縮到下限仍放不下時，維持原樣並在畫面上（列印時隱藏）標明會跨頁，
 * 讓使用者自己決定是取消幾個選項還是改用 A4。A4 模式一律還原，交給瀏覽器原生分頁。
 */
function pprFitPages(){
    var isA3 = $('#pprReportArea').hasClass('ppr-paper-a3');
    $('.ppr-fit-warn').remove();
    $('.ppr-page').each(function(){
        var $p = $(this), $in = $p.find('> .ppr-page-inner'), $main = $in.find('> .ppr-page-main');
        if (!$in.length) return;
        $in.css({zoom:'', width:''});
        $main.css('column-count', '');
        $p.css({height:'', overflow:''});
        if (!isA3) return;
        if ($p.hasClass('ppr-attach-page')) return;   // 附件頁本來就是「一張圖撐滿一頁」，不需要也不該縮放
        var availH = $p[0].clientHeight - parseFloat($p.css('padding-top')) - parseFloat($p.css('padding-bottom'));
        if (availH <= 0 || $in[0].scrollHeight <= availH) return;
        var cols = [2, 3, 4];
        for (var i = 0; i < cols.length && $main.length; i++) {
            $main.css('column-count', cols[i]);
            if ($in[0].scrollHeight <= availH) return;
        }
        // 縮放要**用二分搜尋實測**，不能用一次比例算完就套：套上 zoom 的同時寬度也放大回 (100/z)%，
        // 多欄版面會重新分佈、內容高度跟著縮短，所以「用縮放前的高度算出來的比例」一定縮過頭
        // （實測會縮到 0.58，但其實 0.79 就放得下，等於白白浪費四分之一張紙）。
        var lo = 0.5, hi = 1, best = 0, z;
        var apply = function (v) { $in.css({zoom:v, width:(100 / v) + '%'}); return $in[0].scrollHeight * v; };
        for (var k = 0; k < 7; k++) {
            var mid = (lo + hi) / 2;
            if (apply(mid) <= availH) { best = mid; lo = mid; } else { hi = mid; }
        }
        z = best || 0.5;
        apply(z);
        if ($in[0].scrollHeight * z > availH + 2) {
            // 放不下就**放掉固定高度與裁切**，寧可多印一頁也不可以把內容默默切掉（A3 版面本來是 overflow:hidden）
            $p.css({height:'auto', overflow:'visible'});
            $p.append('<div class="ppr-fit-warn">此筆內容過多，欄數加到 4 欄又縮到下限仍放不下一張 A3，列印時會分成兩頁（內容不會被裁掉）；'
                + '可取消部分選項（例如「顯示QC檢驗內容」或「同料號歷史報工」）或改用 A4。</div>');
        }
    });
}
$(window).on('resize', pprDebounce(pprFitPages, 200));
$('.ppr-paper-btn').on('click', function(){
    $('.ppr-paper-btn').removeClass('active');
    $(this).addClass('active');
    pprApplyPaperState();
});

$('#pprPrintBtn').on('click', function(){ window.print(); });

function pprInitCharts(){
    $('canvas.ppr-chart').each(function(){
        var $c = $(this);
        var type = $c.data('chart');
        var ctx = this.getContext('2d');
        if (type === 'cost') {
            var pts = JSON.parse($c.attr('data-points'));
            new Chart(ctx, { type:'line', data:{ labels: pts.map(function(p){return egFmtDate(p.date);}),
                datasets:[
                    { label:'單顆成本', data: pts.map(function(p){return p.cost;}), borderColor:'#DD5138', fill:false },
                    { label:'訂單單價', data: pts.map(function(p){return p.price;}), borderColor:'#F0A24B', fill:false }
                ]}, options:{ responsive:true, maintainAspectRatio:false } });
        } else if (type === 'margin') {
            var pts2 = JSON.parse($c.attr('data-points'));
            new Chart(ctx, { type:'bar', data:{ labels: pts2.map(function(p){return egFmtDate(p.date);}),
                datasets:[{ label:'毛利率(%)', data: pts2.map(function(p){return p.margin_rate;}), backgroundColor:'#C9A227' }] },
                options:{ responsive:true, maintainAspectRatio:false } });
        } else if (type === 'freq') {
            var orders = JSON.parse($c.attr('data-orders')||'[]');
            var ships = JSON.parse($c.attr('data-ships')||'[]');
            new Chart(ctx, { type:'line', data:{
                labels: orders.map(function(o){return egFmtDate((o.Order_date||'').substring(0,10));}).reverse(),
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
