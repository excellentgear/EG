<?php
// =============================================================================
// views/QC/inspection_print_multi.php   本張製令(BOM)所有製程合併列印
// -----------------------------------------------------------------------------
// 為什麼有這一頁：inspection_entry_v2.php 的「列印」只印目前這一個製程；
// 現場想要一張紙(或幾張)看到整批貨從第一個製程到最後一個製程的檢驗狀態，
// 並自動帶入該圖號的最新工程圖，一眼看出檢驗順序與流程（2026-08 使用者需求）。
//
// 圖面判定：沿用 views/pm/bom_viewer.php 既有邏輯——掃描 Z:/BOM/，檔名開頭比對
// BOM 號碼，純 BOM 號碼檔名視為最新版排最前；若同時有多個候選檔，交由使用者選。
// 這條路線刻意不走 src/common/attach_lib.php（那是給一般附件模組用的，圖面本來
// 就不在那套系統裡，全站都是這樣抓圖面，這裡沿用一致）。
//
// 製程順序：bom_ing.bom_sn（不是 process_no，那是製程「種類」代碼，不是順序）。
// 重驗/複驗：qc_check_form 以 (batch_no, round_no) 表示——batch=送驗批次，
// round=同一批次內的重驗次數。摘要模式列出每批每輪的日期+判定當作「流程小標籤」；
// 完整模式在此之外，展開最後一批最後一輪的完整實測表格。
// =============================================================================
include_once '../../src/common/_config.php';
include_once '../../src/common/asdoc_lib.php';
if (empty($_SESSION['id'])) { http_response_code(403); exit('請先登入'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');
    include_once '../../src/common/DBConnection.php';
    $pdo = (new DBConnection())->getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        $bom = trim($_POST['bom'] ?? '');
        if (!preg_match('/^B-\d{10}$/', $bom)) throw new Exception('BOM 格式錯誤，應為 B- 後接10位數字');
        $mode = ($_POST['mode'] ?? 'summary') === 'full' ? 'full' : 'summary';
        $chosenDrawing = trim($_POST['drawing'] ?? '');

        $base = $pdo->prepare("SELECT Client_Name, d_id, sqty FROM bom WHERE bom=? LIMIT 1");
        $base->execute([$bom]);
        $baseRow = $base->fetch(PDO::FETCH_ASSOC);
        if (!$baseRow) throw new Exception('查無此 BOM，請確認單號是否正確');

        // ── 製程清單（依 bom_sn 排序＝實際製程順序）──────────────────────
        $procs = $pdo->prepare("
            SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, pn.ProcessName, bi.sqty AS proc_qty, bi.maker_id
            FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
            WHERE bi.bom = ?
            ORDER BY bi.bom_sn ASC
        ");
        $procs->execute([$bom]);
        $procRows = $procs->fetchAll(PDO::FETCH_ASSOC);

        $fids = array_map('intval', array_column($procRows, 'bom_ing_fid'));
        $formsByFid = [];
        if ($fids) {
            $ph = implode(',', array_fill(0, count($fids), '?'));
            $fs = $pdo->prepare("
                SELECT qc_form_id, bom_ing_fid, batch_no, round_no, incoming_qty, sample_qty, ng_qty,
                       check_result, main_remark, check_date, created_by, created_at
                FROM qc_check_form
                WHERE bom_ing_fid IN ($ph) AND status <> 'DRAFT'
                ORDER BY bom_ing_fid ASC, batch_no ASC, round_no ASC
            ");
            $fs->execute($fids);
            foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $formsByFid[(int)$f['bom_ing_fid']][] = $f;
            }
        }

        // 檢驗人姓名一次查完（people_lib 只列在職會篩掉離職者名字，這裡單純顯示歷史紀錄的人名，不做在職判定）
        $uidSet = [];
        foreach ($formsByFid as $rows) foreach ($rows as $r) if (!empty($r['created_by'])) $uidSet[$r['created_by']] = true;
        $nameMap = [];
        if ($uidSet) {
            $ph2 = implode(',', array_fill(0, count($uidSet), '?'));
            $un = $pdo->prepare("SELECT id, COALESCE(NULLIF(user_cname,''), user_uname) AS nm FROM user WHERE id IN ($ph2)");
            $un->execute(array_keys($uidSet));
            foreach ($un->fetchAll(PDO::FETCH_ASSOC) as $u) $nameMap[$u['id']] = $u['nm'];
        }

        // ── 完整模式：每個製程取「最後一批、最後一輪」的完整實測明細 ──────
        $itemsByFid = [];
        if ($mode === 'full') {
            $fmt = function ($v) { if ($v === null) return ''; $s = rtrim(rtrim((string)$v, '0'), '.'); return ($s === '' || $s === '-') ? '0' : $s; };
            foreach ($formsByFid as $fid => $rows) {
                $last = end($rows);
                $qid = (int)$last['qc_form_id'];
                $sampleN = max(1, (int)$last['sample_qty']);
                $mq = $pdo->prepare("
                    SELECT m.item_id, m.sample_no, m.measured_value, m.result, m.item_verdict,
                           m.measure_method, m.tool_id, t.Tool_No,
                           i.item_name, i.standard_text, i.plus_tolerance, i.minus_tolerance, i.sort_order,
                           (SELECT tl.QC_Tool FROM qc_inspection_item_tool_type itt JOIN qc_tool_list tl ON itt.QC_Tool_List_id=tl.QC_Tool_List_id WHERE itt.item_id=i.item_id ORDER BY itt.is_primary DESC LIMIT 1) AS tool_name
                    FROM qc_measurement m JOIN qc_inspection_item i ON m.item_id=i.item_id
                    LEFT JOIN qc_tool t ON m.tool_id=t.Tool_id
                    WHERE m.qc_form_id=?
                    ORDER BY i.sort_order ASC, m.item_id ASC, m.measurement_id ASC
                ");
                $mq->execute([$qid]);
                $byItem = [];
                foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $iid = (int)$r['item_id'];
                    if (!isset($byItem[$iid])) {
                        $byItem[$iid] = [
                            'name' => $r['item_name'], 'std' => $r['standard_text'],
                            'up' => $fmt($r['plus_tolerance']), 'lo' => $fmt($r['minus_tolerance']),
                            'tool' => $r['tool_name'] ?: ($r['measure_method'] ?: ''),
                            'verdict' => $r['item_verdict'] ?: 'OK',
                            'samples' => array_fill(0, $sampleN, ['v' => '', 'r' => 'OK']),
                        ];
                    }
                    $pos = (int)$r['sample_no'] - 1;
                    if ($pos >= 0 && $pos < $sampleN) $byItem[$iid]['samples'][$pos] = ['v' => $r['measured_value'], 'r' => $r['result']];
                }
                $itemsByFid[$fid] = ['sample_n' => $sampleN, 'items' => array_values($byItem)];
            }
        }

        // ── 圖面：沿用 bom_viewer.php 的掃描/排序邏輯 ──────────────────────
        require_once __DIR__ . '/../../src/common/bom_dir_lib.php';   // 資料夾位置走設定鍵 bom_scan_dir，不再寫死 Z: 磁碟機代號
        $scanDir = eg_bom_scan_dir_auto(); $urlDir = '/nas/';
        $candidates = [];
        if (is_dir($scanDir)) {
            foreach (scandir($scanDir) as $fn) {
                if ($fn === '.' || $fn === '..') continue;
                if (strpos($fn, $bom) === 0) {
                    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) $candidates[] = $fn; // 列印用先只處理圖片，PDF不易內嵌
                }
            }
            usort($candidates, function ($a, $b) use ($bom, $scanDir) {
                $aPlain = (pathinfo($a, PATHINFO_FILENAME) === $bom) ? 0 : 1;
                $bPlain = (pathinfo($b, PATHINFO_FILENAME) === $bom) ? 0 : 1;
                if ($aPlain !== $bPlain) return $aPlain - $bPlain;
                $ta = @filemtime($scanDir . $a) ?: 0; $tb = @filemtime($scanDir . $b) ?: 0;
                return $tb <=> $ta;
            });
        }
        if ($chosenDrawing !== '' && !in_array($chosenDrawing, $candidates, true)) $chosenDrawing = '';
        if ($chosenDrawing === '' && count($candidates) === 1) $chosenDrawing = $candidates[0];

        $drawing = ['url' => '', 'orient' => 'landscape', 'ambiguous' => count($candidates) > 1, 'candidates' => $candidates, 'chosen' => $chosenDrawing];
        if ($chosenDrawing !== '') {
            $drawing['url'] = $urlDir . rawurlencode($chosenDrawing);
            $size = @getimagesize($scanDir . $chosenDrawing);
            if ($size && $size[0] && $size[1]) $drawing['orient'] = ($size[1] > $size[0]) ? 'portrait' : 'landscape';
        }

        // ── 公司全名／綁定 AS 文件名稱（與單製程列印同一組設定，表頭一致）──
        $company = '';
        $r = $pdo->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) $company = trim((string)($r['customer_full'] ?: $r['customer']));
        $docName = '製程檢驗總覽';
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='qc_inspection_as_doc_id' LIMIT 1");
        $s->execute();
        $docId = (int)($s->fetchColumn() ?: 0);
        $asDocNo = '';
        if ($docId) {
            // 本頁合併多製程、每製程各自的檢驗日期不同，版次以「這批合印資料中最新一筆檢驗日期」回推
            // （ai-rules/16 第三之二節；單一製程列印才用該筆自己的檢驗日期，見 inspection_entry_v2.php）
            $latestCheckDate = null;
            foreach ($formsByFid as $rows) foreach ($rows as $fr) {
                if (!empty($fr['check_date'])) {
                    $cd = substr((string)$fr['check_date'], 0, 10);
                    if ($latestCheckDate === null || $cd > $latestCheckDate) $latestCheckDate = $cd;
                }
            }
            $asDocNo = eg_asdoc_no_asof_id($pdo, $docId, $latestCheckDate);
            $d = $pdo->prepare("SELECT doc_name FROM as_document WHERE id=?");
            $d->execute([$docId]);
            if ($dn = $d->fetchColumn()) $docName = $dn;
        }

        $processes = [];
        foreach ($procRows as $p) {
            $fid = (int)$p['bom_ing_fid'];
            $forms = $formsByFid[$fid] ?? [];
            $batches = [];
            foreach ($forms as $f) {
                $bn = (int)$f['batch_no'];
                if (!isset($batches[$bn])) $batches[$bn] = ['batch_no' => $bn, 'rounds' => []];
                $batches[$bn]['rounds'][] = [
                    'round_no' => (int)$f['round_no'], 'check_result' => $f['check_result'],
                    'ng_qty' => (int)$f['ng_qty'], 'date' => substr((string)($f['check_date'] ?: $f['created_at']), 0, 10),
                    'creator' => $nameMap[$f['created_by']] ?? '',
                ];
            }
            $processes[] = [
                'bom_ing_fid' => $fid, 'bom_sn' => $p['bom_sn'], 'process_name' => $p['ProcessName'] ?: ('製程' . $p['process_no']),
                'proc_qty' => $p['proc_qty'], 'maker_id' => $p['maker_id'],
                'batches' => array_values($batches),
                'last_form' => $forms ? end($forms) : null,
                'detail' => $itemsByFid[$fid] ?? null,
            ];
        }

        echo json_encode(['success' => true, 'bom' => $bom, 'client' => $baseRow['Client_Name'], 'd_id' => $baseRow['d_id'],
            'total_qty' => (int)$baseRow['sqty'], 'company' => $company, 'doc_name' => $docName, 'as_doc_no' => $asDocNo,
            'drawing' => $drawing, 'processes' => $processes], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$bomParam = trim($_GET['bom'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>全製程合併列印</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<style>
:root{ --ink:#4A3524; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B; --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; }
body{ background:#F6F1EA; }
.warm-panel{ background:#fff; border:1px solid var(--line); border-radius:8px; padding:14px; margin:16px; }
.btn-warm{ background:var(--amber); border:1px solid var(--amber-d); color:#4A3524; font-weight:bold; }
.btn-warm:hover{ background:var(--amber-d); color:#fff; }
.muted-help{ color:#8a6a45; font-size:12px; }
.batch-chip{ display:inline-block; padding:4px 10px; margin:0 4px 4px 0; border-radius:14px; border:1px solid var(--line); background:#fff; font-size:12px; }
.st-ok{ color:#3c763d; font-weight:bold; } .st-ng{ color:var(--coral); font-weight:bold; }
</style>
</head>
<body>
<div class="warm-panel">
    <h3 style="margin-top:0;color:var(--ink);"><i class="fa fa-files-o"></i> 全製程合併列印</h3>
    <div class="muted-help" style="margin-bottom:10px;">依 BOM 號碼列出這批製令所有製程的檢驗狀態，自動帶入該圖號最新工程圖後合併列印。</div>
    <div class="form-inline" style="margin-bottom:10px;">
        <div class="form-group" style="margin-right:14px;">
            <label>BOM 號碼</label>
            <input type="text" class="form-control input-sm" id="inp-bom" value="<?= htmlspecialchars($bomParam, ENT_QUOTES, 'UTF-8') ?>" placeholder="B-1234567890" style="width:160px;">
            <button class="btn btn-default btn-sm" id="btn-load"><i class="fa fa-search"></i> 查詢</button>
        </div>
    </div>
    <div id="info-area" style="display:none;">
        <div id="info-bar" class="muted-help" style="margin-bottom:8px;"></div>
        <div class="form-inline" style="margin-bottom:10px;">
            <div class="form-group" style="margin-right:18px;">
                <label>詳細度</label>
                <label class="radio-inline"><input type="radio" name="mode" value="summary" checked> 概覽（每製程一行狀態）</label>
                <label class="radio-inline"><input type="radio" name="mode" value="full"> 完整（展開最後一輪實測數值）</label>
            </div>
            <div class="form-group" style="margin-right:18px;">
                <label>紙張</label>
                <label class="radio-inline"><input type="radio" name="paper" value="A4"> A4</label>
                <label class="radio-inline"><input type="radio" name="paper" value="A3" checked> A3（建議，圖面較不會被縮太小）</label>
            </div>
        </div>
        <div id="drawing-pick-wrap" style="display:none;margin-bottom:10px;">
            <label>找到多張候選圖面，請選擇要用哪一張：</label>
            <select class="form-control input-sm" id="sel-drawing" style="max-width:320px;"></select>
        </div>
        <div id="no-drawing-hint" class="text-muted" style="display:none;margin-bottom:10px;"><i class="fa fa-exclamation-circle"></i> 找不到此 BOM 的圖面檔（Z:/BOM/ 內無檔名以此 BOM 號碼開頭的圖片），列印版將不含圖面。</div>
        <button class="btn btn-warm" id="btn-print"><i class="fa fa-print"></i> 列印 / 產生 PDF</button>
    </div>
</div>
<script src="../../resource/js/jquery.min.js"></script>
<script>
var esc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
var DATA=null;

function loadData(mode, drawing, cb){
    var bom=$('#inp-bom').val().trim();
    if(!/^B-\d{10}$/.test(bom)){ alert('請輸入格式正確的 BOM：B-後接10位數字'); return; }
    $.post('', { action:'get_data', bom:bom, mode:(mode||'summary'), drawing:(drawing||'') }, function(res){
        if(!res.success){ alert(res.message||'查詢失敗'); return; }
        DATA=res;
        renderInfoBar();
        renderDrawingPicker();
        $('#info-area').show();
        if(cb) cb();
    }, 'json').fail(function(){ alert('伺服器錯誤，請稍後再試'); });
}
function renderInfoBar(){
    var okN=0, ngN=0, waitN=0;
    DATA.processes.forEach(function(p){
        if(!p.last_form){ waitN++; return; }
        if(p.last_form.check_result==='NG') ngN++; else okN++;
    });
    $('#info-bar').html('料號 <b>'+esc(DATA.d_id)+'</b>　客戶 <b>'+esc(DATA.client)+'</b>　BOM <b>'+esc(DATA.bom)+'</b>　總數 '+DATA.total_qty
        +'　共 '+DATA.processes.length+' 個製程（<span class="st-ok">合格 '+okN+'</span>　<span class="st-ng">不良 '+ngN+'</span>　尚未檢驗 '+waitN+'）');
}
function renderDrawingPicker(){
    var d=DATA.drawing;
    $('#no-drawing-hint').toggle(!d.candidates.length);
    if(d.ambiguous){
        var $sel=$('#sel-drawing').empty();
        d.candidates.forEach(function(fn){ $sel.append($('<option>').val(fn).text(fn+(fn===d.chosen?'（目前選用）':''))); });
        if(d.chosen) $sel.val(d.chosen);
        $('#drawing-pick-wrap').show();
    } else {
        $('#drawing-pick-wrap').hide();
    }
}
$('#btn-load').on('click', function(){ loadData('summary',''); });
$(document).on('keydown', '#inp-bom', function(e){ if(e.which===13){ e.preventDefault(); $('#btn-load').click(); } });
$(document).on('change', '#sel-drawing', function(){
    var mode=$('input[name=mode]:checked').val();
    loadData(mode, $(this).val());
});
$(document).on('change', 'input[name=mode]', function(){
    if(!DATA) return;
    loadData($(this).val(), DATA.drawing.chosen);
});
<?php if ($bomParam !== ''): ?>
$(function(){ loadData('summary',''); });
<?php endif; ?>

// ===================== 組列印 HTML（沿用 external_doc_list.php 的作法：開新視窗寫入，交瀏覽器原生分頁）=====================
function batchTrailHtml(p){
    if(!p.batches.length) return '<span class="muted-help">尚未檢驗</span>';
    return p.batches.map(function(b){
        return b.rounds.map(function(r,ri){
            var cls=(r.check_result==='NG')?'pm-ng':'pm-ok';
            var lbl=(r.check_result==='NG')?'不良':(r.check_result==='HOLD'?'審核中':'合格');
            var tag = ri===0 ? ('第'+b.batch_no+'批') : '重驗';
            return '<span class="pm-chip '+cls+'">'+tag+' '+esc(r.date)+' '+lbl+'</span>';
        }).join('<span class="pm-arrow">→</span>');
    }).join('　');
}
function toolLinesHtml(t){
    if(!t) return '';
    var no=String(t||'').trim();
    return '<span>'+esc(no)+'</span>';
}
function buildProcessSummaryRow(p, idx){
    var last=p.last_form;
    var judge = !last ? '<span class="muted-help">—</span>' : (last.check_result==='NG' ? '<span class="pm-ng">✘ 不良</span>' : '<span class="pm-ok">✔ 合格</span>');
    return '<tr><td>'+(idx+1)+'</td><td class="tl">'+esc(p.process_name)+'</td>'
        + '<td>'+esc(p.proc_qty||'')+'</td><td>'+esc(p.maker_id||'')+'</td>'
        + '<td class="tl">'+batchTrailHtml(p)+'</td>'
        + '<td>'+judge+'</td>'
        + '<td>'+(last?esc(last.creator||''):'')+'</td></tr>';
}
function buildProcessFullBlock(p, idx){
    var head='<div class="pm-proc-head">['+(idx+1)+'] '+esc(p.process_name)
        + '　送驗:'+esc(p.proc_qty||'')+'　廠商:'+esc(p.maker_id||'')+'</div>'
        + '<div class="pm-trail">'+batchTrailHtml(p)+'</div>';
    if(!p.detail || !p.detail.items || !p.detail.items.length){
        return head + '<div class="muted-help" style="margin:4px 0 14px;">尚無實測資料</div>';
    }
    var n=p.detail.sample_n;
    var pcsHead=''; for(var i=1;i<=n;i++) pcsHead+='<th>'+i+'</th>';
    var body='<table class="pm-items"><thead><tr><th class="c-no">項次</th><th>檢驗項目</th><th>標準</th><th class="c-tol">上差</th><th class="c-tol">下差</th><th class="c-tool">量具</th>'+pcsHead+'<th>判定</th></tr></thead><tbody>';
    p.detail.items.forEach(function(it,i2){
        var code=String.fromCharCode(65+(i2%26));
        var cells=''; (it.samples||[]).forEach(function(sv){
            var v=(sv&&sv.v!=null&&sv.v!=='')?sv.v:'';
            cells+='<td'+((sv&&sv.r==='NG'&&v!=='')?' class="pm-ng-cell"':'')+'>'+esc(v)+'</td>';
        });
        body+='<tr><td>'+code+'</td><td class="tl">'+esc(it.name)+'</td><td>'+esc(it.std||'')+'</td>'
            + '<td>'+esc(it.up||'')+'</td><td>'+esc(it.lo||'')+'</td><td>'+toolLinesHtml(it.tool)+'</td>'
            + cells + '<td>'+(it.verdict==='NG'?'<span class="pm-ng">NG</span>':(it.verdict==='AOD'?'特採':'OK'))+'</td></tr>';
    });
    body+='</tbody></table>';
    return head+body;
}
$('#btn-print').on('click', function(){
    if(!DATA){ alert('請先查詢'); return; }
    var mode=$('input[name=mode]:checked').val();
    var paper=$('input[name=paper]:checked').val();
    loadData(mode, DATA.drawing.chosen, function(){ doPrint(mode, paper); });
});
function doPrint(mode, paper){
    var d=DATA.drawing;
    var drawingHtml = d.url ? '<img class="pm-drawing-img" src="'+esc(d.url)+'">' : '<div class="pm-no-drawing">（無圖面）</div>';
    var orient = d.url ? d.orient : 'landscape';

    var head = '<div class="pm-co">'+esc(DATA.company)+'</div>'
        + '<div class="pm-title">'+esc(DATA.doc_name)+'</div>'
        + '<table class="pm-meta"><tr><td class="k">料號</td><td>'+esc(DATA.d_id)+'</td><td class="k">客戶</td><td>'+esc(DATA.client)+'</td>'
        + '<td class="k">BOM</td><td>'+esc(DATA.bom)+'</td><td class="k">總數</td><td>'+DATA.total_qty+'</td></tr></table>';

    var layoutClass = (orient==='portrait') ? 'pm-layout-side' : 'pm-layout-top';
    var header = '<div class="'+layoutClass+'"><div class="pm-drawing">'+drawingHtml+'</div><div class="pm-headinfo">'+head+'</div></div>';

    var body='';
    if(mode==='full'){
        DATA.processes.forEach(function(p,idx){ body += '<div class="pm-proc-block">'+buildProcessFullBlock(p, idx)+'</div>'; });
    } else {
        body = '<table class="pm-sumtable"><thead><tr><th>#</th><th>製程</th><th>數量</th><th>廠商</th><th>批次/重驗歷程</th><th>最終判定</th><th>檢驗人</th></tr></thead><tbody>';
        DATA.processes.forEach(function(p,idx){ body += buildProcessSummaryRow(p, idx); });
        body += '</tbody></table>';
    }

    var asTxt = DATA.as_doc_no ? String(DATA.as_doc_no).replace(/['\\]/g,'') : '';
    var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:11px;line-height:1.2;}'
        + '.pm-co{font-size:20px;font-weight:bold;text-align:center;}'
        + '.pm-title{font-size:15px;font-weight:bold;text-align:center;margin:2px 0 6px;}'
        + '.pm-meta{width:100%;border-collapse:collapse;margin-bottom:4px;}'
        + '.pm-meta td{border:1px solid #000;padding:3px 6px;}'
        + '.pm-meta .k{background:#f0f0f0;font-weight:bold;white-space:nowrap;}'
        + '.pm-layout-top{display:flex;flex-direction:column;}'
        + '.pm-layout-top .pm-drawing{text-align:center;margin-bottom:6px;}'
        + '.pm-layout-top .pm-drawing-img{max-width:100%;max-height:110mm;}'
        + '.pm-layout-side{display:flex;flex-direction:row;gap:8mm;align-items:flex-start;}'
        + '.pm-layout-side .pm-drawing{flex:0 0 38%;text-align:center;}'
        + '.pm-layout-side .pm-drawing-img{max-width:100%;max-height:150mm;}'
        + '.pm-layout-side .pm-headinfo{flex:1 1 auto;}'
        + '.pm-no-drawing{color:#999;border:1px dashed #ccc;padding:20px;text-align:center;}'
        + 'table.pm-sumtable{width:100%;border-collapse:collapse;margin-top:6px;}'
        + 'table.pm-sumtable th,table.pm-sumtable td{border:1px solid #666;padding:4px 6px;text-align:center;}'
        + 'table.pm-sumtable thead th{background:#f3ead6;}'
        + 'table.pm-sumtable td.tl{text-align:left;}'
        + 'table.pm-sumtable thead{display:table-header-group;}'
        + 'table.pm-sumtable tr{break-inside:avoid;}'
        + '.pm-proc-block{break-inside:avoid-page;margin-top:10px;}'
        + '.pm-proc-head{font-size:13px;font-weight:bold;border-left:4px solid #F0A24B;padding-left:6px;margin-bottom:2px;}'
        + '.pm-trail{margin-bottom:4px;}'
        + '.pm-chip{display:inline-block;border:1px solid #ccc;border-radius:10px;padding:1px 8px;font-size:10px;margin-right:2px;}'
        + '.pm-arrow{margin:0 3px;color:#999;}'
        + '.pm-ok{color:#3c763d;font-weight:bold;} .pm-ng{color:#b9401f;font-weight:bold;}'
        + 'table.pm-items{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:4px;}'
        + 'table.pm-items th,table.pm-items td{border:1px solid #666;padding:2px 4px;text-align:center;}'
        + 'table.pm-items thead th{background:#eee;}'
        + 'table.pm-items thead{display:table-header-group;}'
        + 'table.pm-items td.tl{text-align:left;}'
        + '.pm-ng-cell{color:#000;font-weight:bold;text-decoration:underline;}'
        + '@page{size:'+paper+' portrait;margin:12mm 10mm 18mm;'
        + (asTxt ? " @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
        + '}';

    var w=window.open('','_blank');
    w.document.write('<html><head><meta charset="utf-8"><title>全製程合併列印 - '+esc(DATA.bom)+'</title><style>'+css+'</style></head><body>'
        + header + body
        + '<scr'+'ipt>window.onload=function(){'
        + 'var onePage=(297-30)*96/25.4;'
        + 'if(document.body.scrollHeight>onePage*0.9){'
        + 'var st=document.createElement(\'style\');'
        + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
        + 'document.head.appendChild(st);}'
        + 'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
    w.document.close();
}
</script>
</body>
</html>
