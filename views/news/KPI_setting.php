<?php
/**
 * AS9100 關鍵績效指標 設定頁（僅 KPI 管理者）
 * 指標主檔/年度版本(目標/擔當者/資料來源/參數含開放前端)、權限規則(部門×主管階級/指定人員)、
 * NAS附件路徑與每月上限、年度複製、變更歷史
 * 資料一律走 src/store/KpiAs_Setting_API.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/news/KPI_setting.php?in=999";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/kpi_as_lib.php';

$db = (new DBConnection())->getPDO();
kpi_as_ensure_schema($db);
$kpiUser = kpi_as_current_user($db);
$kpiPerms = kpi_as_perms($db, $kpiUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KPI 設定</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .right_col .page-title h2 { margin:6px 0; }
        .ks-toolbar { clear:both; margin-top:6px; }
        #ksToast { position:fixed; top:70px; left:50%; transform:translateX(-50%); z-index:2000;
            background:#7a9c3f; color:#fff; padding:8px 20px; border-radius:20px; font-size:14px;
            box-shadow:0 3px 10px rgba(0,0,0,.25); display:none; }
        table.ks-table tbody tr.row-dirty { background:#FDEFD6 !important; }
        table.ks-table tbody tr.row-dirty td:first-child::before { content:'●'; color:#F0A24B; margin-right:3px; }
        .ks-btn.f-save.dirty { background:#DD5138; border-color:#b53c28; animation:kspulse 1.2s infinite; }
        @keyframes kspulse { 0%,100%{opacity:1;} 50%{opacity:.55;} }
        #btnSaveAll { background:#DD5138; color:#fff; border-color:#b53c28; }
        .ks-panel { border:1.5px solid #E8D5B5; border-radius:8px; background:#fff; margin-bottom:14px; }
        .ks-panel .p-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:8px 12px;
            border-radius:8px 8px 0 0; font-size:14px; }
        .ks-panel .p-body { padding:10px 12px; }
        .ks-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .ks-toolbar select, .ks-toolbar button, .ks-toolbar a.btn { height:30px; font-size:13px;
            padding:0 10px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ks-toolbar button:hover { background:#F7E0BD; }
        table.ks-table { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.ks-table th, table.ks-table td { border:1px solid #EADFC8; padding:4px 6px; }
        table.ks-table thead th { background:#FBF0DD; color:#5b3a1e; white-space:nowrap; }
        table.ks-table tbody tr:hover { background:#FBF6EC; }
        table.ks-table input[type=text], table.ks-table input[type=number], table.ks-table select {
            border:1px solid #D8BE93; border-radius:3px; font-size:12.5px; padding:2px 4px; box-sizing:border-box; }
        .ks-btn { height:26px; font-size:12px; border:1px solid #d98a33; background:#F0A24B; color:#fff;
            border-radius:4px; cursor:pointer; padding:0 10px; }
        .ks-btn.gray { background:#fff; color:#5b3a1e; border-color:#D8BE93; }
        .ks-btn.red { background:#DD5138; border-color:#b53c28; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button
            { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; appearance:textfield; }
        .kpi-modal-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; overflow:auto; }
        .kpi-modal { background:#fff; border-radius:8px; max-width:640px; margin:50px auto;
            box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:85vh; display:flex; flex-direction:column; }
        .kpi-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px;
            border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .kpi-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .kpi-modal .m-body { padding:15px; overflow-y:auto; }
        .kpi-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:8px 0 3px; font-weight:bold; }
        .kpi-modal .m-body input[type=text], .kpi-modal .m-body input[type=number],
        .kpi-modal .m-body select, .kpi-modal .m-body textarea { width:100%; border:1px solid #D8BE93;
            border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .kpi-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .param-hint { font-size:11px; color:#8a6d45; margin-top:2px; }
        .param-fe { font-size:11px; color:#b5762a; white-space:nowrap; }
        .months-grid { display:grid; grid-template-columns:repeat(6, 1fr); gap:4px; }
        .months-grid input { width:100%; }
        .chk-list { display:flex; flex-wrap:wrap; gap:4px 12px; font-size:12px; font-weight:normal; }
        .chk-list label { font-weight:normal; margin:0; display:inline; }
        .ks-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5;
            border-radius:10px; padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .rule-tag { display:inline-block; background:#F7E0BD; color:#5b3a1e; border-radius:10px;
            padding:1px 8px; font-size:11px; margin-right:4px; }
        #logTable td { font-size:11.5px; }
    </style>
</head>
<body class="nav-sm">
<div id="ksToast"></div>
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title">
            <h2>KPI 設定 <small style="color:#8a6d45;">2-GM-04-01 指標／資料來源／權限／附件</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$kpiPerms['canAdmin']): ?>
        <div class="ks-noperm">
            <h4><i class="fa fa-lock"></i> 僅 KPI 管理員可使用設定頁</h4>
            <p>請洽管理者指派「KPI管理員」角色或於本頁權限規則指定人員。</p>
            <a href="KPI.php" class="ks-btn" style="text-decoration:none;display:inline-block;line-height:26px;">回 KPI 總覽</a>
        </div>
<?php else: ?>
        <div class="ks-toolbar">
            <a class="btn" href="KPI.php" style="line-height:28px;"><i class="fa fa-arrow-left"></i> 回 KPI 總覽</a>
            <label style="margin:0;font-size:13px;color:#5b3a1e;">年度</label>
            <select id="yearSel"></select>
            <button id="btnCopyYear" title="把某年度的目標/公式/擔當者設定複製到另一年度(僅補缺漏)">
                <i class="fa fa-copy"></i> 年度設定複製</button>
            <button id="btnAddInd"><i class="fa fa-plus"></i> 新增指標</button>
            <button id="btnSaveAll" style="display:none;"><i class="fa fa-save"></i> 全部儲存（<span class="cnt">0</span>）</button>
            <button id="btnCatalog" style="display:none;"><i class="fa fa-database"></i> 資料來源目錄</button>
            <span style="margin-left:auto;font-size:12px;color:#8a6d45;">
                每年目標/公式獨立儲存；所有修改自動寫入變更歷史</span>
        </div>

        <!-- 指標清單 -->
        <div class="ks-panel">
            <div class="p-head"><i class="fa fa-list-ol"></i> 指標與資料來源（<span id="yearLabel"></span> 年度）</div>
            <div class="p-body" style="overflow-x:auto;">
                <table class="ks-table" id="indTable">
                    <thead><tr>
                        <th>項次</th><th>指標內容</th><th>頻率/型態</th><th>判定目標</th>
                        <th>擔當者</th><th>來源</th><th>資料來源(頁面)</th><th>參數</th><th>啟用</th><th></th>
                    </tr></thead>
                    <tbody id="indBody"><tr><td colspan="10" style="padding:15px;color:#8a6d45;">載入中…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- 權限規則 -->
        <div class="ks-panel">
            <div class="p-head"><i class="fa fa-users"></i> 權限規則（與「使用者權限設定」頁的 KPI 角色指派為聯集）</div>
            <div class="p-body">
                <div style="font-size:12px;color:#8a6d45;margin-bottom:6px;">
                    部門×主管階級：指定部門（或全部部門）中，主管階級「N階(含)以上」者自動取得能力（1=一階主管最高）。
                    個人/職稱的 KPI 角色請至 <a href="../user/user_permissions.php" target="_blank">使用者權限設定</a> 指派。
                </div>
                <table class="ks-table" style="max-width:760px;">
                    <thead><tr><th>能力</th><th>規則</th><th>內容</th><th></th></tr></thead>
                    <tbody id="ruleBody"></tbody>
                </table>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:8px;font-size:13px;">
                    <select id="rPerm" style="height:28px;border:1px solid #D8BE93;border-radius:4px;">
                        <option value="view">檢閱</option><option value="fill">填報</option><option value="admin">管理員</option>
                    </select>
                    <select id="rType" style="height:28px;border:1px solid #D8BE93;border-radius:4px;">
                        <option value="dept_level">部門×主管階級</option><option value="user">指定人員</option>
                    </select>
                    <select id="rDept" style="height:28px;border:1px solid #D8BE93;border-radius:4px;"></select>
                    <select id="rLevel" style="height:28px;border:1px solid #D8BE93;border-radius:4px;">
                        <option value="1">一階主管</option><option value="2">二階(含)以上主管</option>
                        <option value="3">三階(含)以上主管</option>
                    </select>
                    <select id="rUser" style="height:28px;border:1px solid #D8BE93;border-radius:4px;display:none;"></select>
                    <button class="ks-btn" id="btnRuleAdd">新增規則</button>
                </div>
            </div>
        </div>

        <!-- 附件設定 -->
        <div class="ks-panel">
            <div class="p-head"><i class="fa fa-paperclip"></i> 佐證附件設定</div>
            <div class="p-body" style="max-width:760px;">
                <label style="font-size:13px;color:#5b3a1e;">NAS 存放根路徑（DB 只存檔名，路徑讀取當下即時組；子資料夾=年度自動建立）</label>
                <input type="text" id="setBase" placeholder="例：\\\\NAS\\share\\KPI佐證  或  Z:\\BOM\\ERP\\KPI佐證（留空=系統內 uploads/kpi_attach）"
                    style="width:100%;border:1px solid #D8BE93;border-radius:4px;padding:5px 8px;font-size:13px;box-sizing:border-box;">
                <div class="param-hint" id="baseStatus"></div>
                <label style="font-size:13px;color:#5b3a1e;margin-top:8px;display:block;">每月每項 KPI 附件上限（件）</label>
                <input type="number" id="setMax" min="1" max="50"
                    style="width:100px;border:1px solid #D8BE93;border-radius:4px;padding:5px 8px;font-size:13px;">
                <div style="margin-top:8px;">
                    <button class="ks-btn" id="btnSaveSettings">儲存附件設定</button>
                </div>
            </div>
        </div>

        <!-- 變更歷史 -->
        <div class="ks-panel">
            <div class="p-head"><i class="fa fa-history"></i> 變更歷史
                <button class="ks-btn gray" style="float:right;height:22px;font-size:11px;" id="btnLogReload">重新整理</button>
            </div>
            <div class="p-body" style="overflow-x:auto;max-height:360px;overflow-y:auto;">
                <table class="ks-table" id="logTable">
                    <thead><tr><th>時間</th><th>指標</th><th>年</th><th>月</th><th>動作</th><th>欄位</th>
                        <th>修改前</th><th>修改後</th><th>備註</th><th>操作者</th></tr></thead>
                    <tbody id="logBody"></tbody>
                </table>
            </div>
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 參數編輯 modal -->
<div class="kpi-modal-mask" id="pMask"><div class="kpi-modal">
    <div class="m-head"><span id="pTitle">計算參數</span><span class="m-close" onclick="$('#pMask').hide()">✕</span></div>
    <div class="m-body" id="pBody"></div>
    <div class="m-foot">
        <button class="ks-btn gray" onclick="$('#pMask').hide()">取消</button>
        <button class="ks-btn" onclick="saveParams()">儲存參數</button>
    </div>
</div></div>

<!-- 自訂公式 builder modal -->
<div class="kpi-modal-mask" id="bMask"><div class="kpi-modal" style="max-width:720px;">
    <div class="m-head"><span id="bTitle">自訂公式</span><span class="m-close" onclick="$('#bMask').hide()">✕</span></div>
    <div class="m-body" id="bBody">
        <div class="param-hint" style="margin-bottom:8px;">用「資料來源目錄」自組公式，全程下拉、不需寫程式。分子÷分母；只設分子＝直接算筆數/加總。</div>
        <div style="border:1px solid #EADFC8;border-radius:6px;padding:10px;margin-bottom:10px;">
            <div style="font-weight:bold;color:#5b3a1e;margin-bottom:6px;">分子（要計算的對象）</div>
            <div id="bNum"></div>
        </div>
        <label style="font-weight:bold;"><input type="checkbox" id="bUseDen" style="width:auto;" onchange="toggleDen()"> 除以分母（做成比率/百分比）</label>
        <div id="bDenBox" style="border:1px solid #EADFC8;border-radius:6px;padding:10px;margin:6px 0;display:none;">
            <div style="font-weight:bold;color:#5b3a1e;margin-bottom:6px;">分母（拿來除的總數）</div>
            <div id="bDen"></div>
            <label style="margin-top:6px;"><input type="checkbox" id="bMul100" style="width:auto;"> 結果 ×100（顯示為百分比）</label>
        </div>
        <div style="background:#FFF7E8;border:1px dashed #F0A24B;border-radius:6px;padding:8px;margin-top:8px;">
            <button class="ks-btn gray" onclick="previewBuilder()"><i class="fa fa-calculator"></i> 試算本月</button>
            <span id="bPreview" style="margin-left:10px;font-size:13px;color:#5b3a1e;"></span>
        </div>
    </div>
    <div class="m-foot">
        <button class="ks-btn gray" onclick="$('#bMask').hide()">取消</button>
        <button class="ks-btn" onclick="saveBuilder()">儲存公式</button>
    </div>
</div></div>

<!-- 資料來源目錄管理 modal（僅系統管理者） -->
<div class="kpi-modal-mask" id="cMask"><div class="kpi-modal" style="max-width:780px;">
    <div class="m-head"><span>資料來源目錄管理（系統管理者）</span><span class="m-close" onclick="$('#cMask').hide()">✕</span></div>
    <div class="m-body" id="cBody">
        <div class="param-hint" style="margin-bottom:8px;">在這裡把「資料表」用中文登記成積木，KPI 管理員之後就能用下拉自組公式、不必知道資料表名稱。此區僅系統管理者可用。</div>
        <div id="cList"></div>
        <div style="border-top:1px dashed #EADFC8;margin-top:10px;padding-top:8px;">
            <div style="font-weight:bold;color:#5b3a1e;">新增資料來源</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;">
                <input type="text" id="ncLabel" placeholder="中文名稱(例:出貨單)" style="width:150px;">
                <input type="text" id="ncTable" placeholder="資料表名(is_list)" style="width:150px;">
                <input type="text" id="ncDate" placeholder="日期欄(Order_date)" style="width:150px;">
                <button class="ks-btn" onclick="saveCatalog(0)">新增</button>
            </div>
            <div class="param-hint">資料表與日期欄需為系統實際存在的名稱（會自動驗證）。</div>
        </div>
    </div>
</div></div>

<!-- 指標主檔 modal -->
<div class="kpi-modal-mask" id="iMask"><div class="kpi-modal">
    <div class="m-head"><span id="iTitle">指標主檔</span><span class="m-close" onclick="$('#iMask').hide()">✕</span></div>
    <div class="m-body">
        <label>項次</label><input type="number" id="iItemNo">
        <label>指標內容</label><input type="text" id="iName" maxlength="100">
        <label>對應條文</label><input type="text" id="iClause" maxlength="200">
        <label>統計方式(文字說明)</label><input type="text" id="iStat" maxlength="200">
        <label>頻率</label>
        <select id="iFreq"><option value="monthly">每月</option><option value="quarterly">每季</option>
            <option value="halfyear">半年</option><option value="yearly">每年</option></select>
        <label>數值型態</label>
        <select id="iVt"><option value="percent">百分比%</option><option value="count">件數</option>
            <option value="score">分數</option><option value="rate">比率(顆/小時等)</option><option value="yesno">Yes/No</option></select>
        <label>排序</label><input type="number" id="iSort">
        <label><input type="checkbox" id="iActive" style="width:auto;"> 啟用</label>
    </div>
    <div class="m-foot">
        <button class="ks-btn gray" onclick="$('#iMask').hide()">取消</button>
        <button class="ks-btn" onclick="saveIndicator()">儲存</button>
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
$(document).ready(function(){
    var $activeMenu = $('#sidebar-menu .nav.side-menu > li.active');
    if ($activeMenu.length) {
        $activeMenu.removeClass('active').find('ul.child_menu').hide();
        $activeMenu.find('li.current-page').removeClass('current-page');
    }
    $('#sidebar-menu').css('visibility', 'visible');
});
var API = '../../src/store/KpiAs_Setting_API.php';
var canAdmin = <?= $kpiPerms['canAdmin'] ? 'true' : 'false' ?>;
var DATA = null, YEAR = null;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function freqName(f){ return {monthly:'每月',quarterly:'每季',halfyear:'半年',yearly:'每年'}[f] || f; }
function vtName(v){ return {percent:'%',count:'件',score:'分',rate:'比率',yesno:'Y/N'}[v] || v; }
function toast(msg){ var $t=$('#ksToast').text(msg).fadeIn(120); clearTimeout(window._kst);
    window._kst=setTimeout(function(){ $t.fadeOut(300); }, 1600); }

/* 擔當者：先選部門→帶出部門內人員(含職稱) */
var DEPT_OF_USER = {}, DEPT_NAME = {};
function buildOwnerMaps(){
    DEPT_OF_USER = {}; DEPT_NAME = {};
    (DATA.dicts.departments||[]).forEach(function(d){ DEPT_NAME[d.id] = d.name; });
    var dm = DATA.dicts.dept_members || {};
    // 兼任者優先顯示其「主要」部門(is_main=1)，其次才用第一個出現的部門
    Object.keys(dm).forEach(function(did){
        dm[did].forEach(function(m){ if (m.is_main === 1) DEPT_OF_USER[m.user_id] = +did; });
    });
    Object.keys(dm).forEach(function(did){
        dm[did].forEach(function(m){ if (DEPT_OF_USER[m.user_id] === undefined) DEPT_OF_USER[m.user_id] = +did; });
    });
}
function deptOptsHtml(){
    return (DATA.dicts.departments||[]).map(function(d){
        return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
}
function fillOwnerPeople(sel){
    var did = +$(sel).val();
    var $p = $(sel).closest('td').find('.f-owner').empty().append('<option value="0">（未指定）</option>');
    ((DATA.dicts.dept_members||{})[did] || []).forEach(function(m){
        $p.append('<option value="'+m.user_id+'" data-pos="'+(m.position_id||'')+'">'+esc(m.cname)+(m.position_name?'（'+esc(m.position_name)+'）':'')+'</option>');
    });
}
function ownerChanged(sel){
    var $td = $(sel).closest('td');
    var uid = $(sel).val();
    var deptName = $td.find('.f-odept option:selected').text();
    var personName = ($(sel).find('option:selected').text() || '').replace(/（.*$/, '');
    var disp = (uid && uid !== '0') ? (personName + (deptName && deptName !== '選部門' ? '/' + deptName : '')) : '';
    $td.find('.f-ownerdisp').val(disp);
}
function setOwners(){
    DATA.indicators.forEach(function(r, i){
        var $tr = $('tr[data-i="'+i+'"]');
        // 精準還原：優先用已存的部門(兼任者才不會跑掉)；舊資料無 owner_dept_id 才回退推斷
        var did = r.owner_dept_id || DEPT_OF_USER[r.owner_user_id] || 0;
        var $dept = $tr.find('.f-odept'), $own = $tr.find('.f-owner');
        $dept.val(did);
        fillOwnerPeople($dept[0]);
        // 若擔當者所屬部門查不到，仍補一個單獨選項避免掉資料
        if ((!did || $own.find('option[value="'+r.owner_user_id+'"]').length===0) && r.owner_user_id) {
            var nm = (r.owner_display||'').split('/')[0] || ('user#'+r.owner_user_id);
            $own.append('<option value="'+r.owner_user_id+'" data-pos="'+(r.owner_position_id||'')+'">'+esc(nm)+'</option>');
        }
        $own.val(r.owner_user_id || 0);
    });
}
function hasConfiguredParams(r){
    try {
        var pj = JSON.parse(r.params_json || '{}');
        return Object.keys(pj).some(function(k){
            var x = pj[k]; var v = (x && typeof x==='object' && 'v' in x) ? x.v : x;
            if (v === null || v === undefined || v === '') return false;
            if (Array.isArray(v)) return v.length > 0;
            if (typeof v === 'object') return Object.keys(v).length > 0;
            return true;
        });
    } catch(e){ return false; }
}

function loadAll(){
    NProgress.start();
    $.getJSON(API, {action:'get_all', year: YEAR || undefined}, function(res){
        NProgress.done();
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        DATA = res; YEAR = res.year;
        $('#yearLabel').text(YEAR);
        var $y = $('#yearSel').empty();
        res.years.forEach(function(y){ $y.append('<option value="'+y+'"'+(y===YEAR?' selected':'')+'>'+y+'</option>'); });
        buildOwnerMaps();
        renderIndicators();
        setOwners();
        renderRules();
        renderDicts();
        $('#setBase').val(res.settings.attach_base);
        $('#setMax').val(res.settings.attach_max);
        $('#baseStatus').html('實際生效路徑：' + esc(res.settings.attach_base_effective) +
            (res.settings.attach_base_ok ? ' <span style="color:#7a9c3f;">✔ 可存取</span>'
                                         : ' <span style="color:#DD5138;">✘ 目前無法存取(請確認NAS)</span>'));
        loadLog();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 指標清單 ---------- */
function renderIndicators(){
    var h = '';
    DATA.indicators.forEach(function(r, i){
        var reg = r.calculator_key && DATA.registry[r.calculator_key];
        h += '<tr data-i="'+i+'">';
        h += '<td style="text-align:center;">'+r.item_no+'</td>';
        h += '<td><a href="javascript:void(0)" onclick="openIndModal('+i+')" title="編輯主檔(名稱/頻率/型態)" style="color:#b5762a;">'+esc(r.name)+'</a>'
           + (r.ind_active==1?'':' <span class="rule-tag" style="background:#eee;">主檔停用</span>')+'</td>';
        h += '<td style="white-space:nowrap;">'
           + '<select class="f-freq" style="width:62px;">'
           + '<option value="monthly"'+(r.freq==='monthly'?' selected':'')+'>每月</option>'
           + '<option value="quarterly"'+(r.freq==='quarterly'?' selected':'')+'>每季</option>'
           + '<option value="halfyear"'+(r.freq==='halfyear'?' selected':'')+'>半年</option>'
           + '<option value="yearly"'+(r.freq==='yearly'?' selected':'')+'>每年</option></select>'
           + '<select class="f-vt" style="width:72px;">'
           + '<option value="percent"'+(r.value_type==='percent'?' selected':'')+'>百分比%</option>'
           + '<option value="count"'+(r.value_type==='count'?' selected':'')+'>件數</option>'
           + '<option value="score"'+(r.value_type==='score'?' selected':'')+'>分數</option>'
           + '<option value="rate"'+(r.value_type==='rate'?' selected':'')+'>比率</option>'
           + '<option value="yesno"'+(r.value_type==='yesno'?' selected':'')+'>Yes/No</option></select></td>';
        h += '<td style="white-space:nowrap;">'
           + '<select class="f-dir" style="width:58px;">'
           + '<option value="gte"'+(r.target_direction==='gte'?' selected':'')+'>≥達標</option>'
           + '<option value="lte"'+(r.target_direction==='lte'?' selected':'')+'>≤達標</option>'
           + '<option value="yes"'+(r.target_direction==='yes'?' selected':'')+'>Yes</option></select> '
           + '<input type="number" class="f-tval" style="width:60px;" step="any" value="'+(r.target_value===null?'':+r.target_value)+'">'
           + '<input type="text" class="f-tunit" style="width:44px;" placeholder="單位" value="'+esc(r.target_unit||'')+'">'
           + '<input type="text" class="f-ttext" style="width:100px;" placeholder="目標原文" value="'+esc(r.target_text||'')+'">'
           + '</td>';
        h += '<td style="white-space:nowrap;">'
           + '<select class="f-odept" style="width:86px;" onchange="fillOwnerPeople(this)"><option value="0">選部門</option>'+deptOptsHtml()+'</select>'
           + '<select class="f-owner" style="width:118px;" onchange="ownerChanged(this)"></select>'
           + '<input type="hidden" class="f-ownerdisp" value="'+esc(r.owner_display||'')+'"></td>';
        h += '<td style="text-align:center;"><select class="f-mode" onchange="modeChanged(this,'+i+')">'
           + '<option value="manual"'+(r.source_mode==='manual'?' selected':'')+'>手動</option>'
           + '<option value="auto"'+(r.source_mode==='auto'?' selected':'')+'>自動</option></select></td>';
        h += '<td><select class="f-calc" style="max-width:230px;"'+(r.source_mode==='manual'?' disabled':'')+'><option value="">（選擇資料來源）</option>';
        h += '<optgroup label="內建計算">';
        Object.keys(DATA.registry).forEach(function(k){
            h += '<option value="'+k+'"'+(r.calculator_key===k?' selected':'')+'>'+esc(DATA.registry[k].name)+'</option>';
        });
        h += '</optgroup><optgroup label="自訂"><option value="__builder__"'+(r.calculator_key==='__builder__'?' selected':'')+'>🔧 自訂公式（資料來源目錄）</option></optgroup>';
        h += '</select>'+(reg?'<div class="param-hint">'+esc(reg.page)+'</div>'
              :(r.calculator_key==='__builder__'?'<div class="param-hint">用資料來源目錄自組公式</div>':''))+'</td>';
        h += '<td style="text-align:center;"><button class="ks-btn gray f-parambtn" onclick="openParams('+i+')"'
           + (r.source_mode==='manual'?' disabled style="opacity:.4;"':'')+'>參數'
           + (hasConfiguredParams(r)?' <span style="color:#7a9c3f;" title="已設定參數">●</span>':'')+'</button></td>';
        h += '<td style="text-align:center;"><input type="checkbox" class="f-active"'+(r.year_active==1?' checked':'')+'></td>';
        h += '<td style="text-align:center;"><button class="ks-btn f-save" onclick="saveRow('+i+')">儲存</button></td>';
        h += '</tr>';
    });
    $('#indBody').html(h || '<tr><td colspan="10">無資料</td></tr>');
}
function modeChanged(sel, i){
    var $tr = $(sel).closest('tr');
    $tr.find('.f-calc').prop('disabled', sel.value === 'manual');
    $tr.find('td button.ks-btn.gray').prop('disabled', sel.value === 'manual').css('opacity', sel.value === 'manual' ? .4 : 1);
}
// 儲存單列：不整頁 reload（保留其他列未存編輯）、就地更新 DATA、回傳 promise 供「全部儲存」串接
function saveRow(i, silent){
    var d = $.Deferred();
    var r = DATA.indicators[i];
    var $tr = $('tr[data-i="'+i+'"]');
    var freq = $tr.find('.f-freq').val(), vt = $tr.find('.f-vt').val();
    var $ownSel = $tr.find('.f-owner');
    var ownerId = $ownSel.val() || 0;
    var ownerDeptId = $tr.find('.f-odept').val() || 0;
    var ownerPosId = $ownSel.find('option:selected').attr('data-pos') || 0;
    var ownerDisp = $tr.find('.f-ownerdisp').val();
    var post = {
        action:'save_iy', indicator_id:r.indicator_id, year:YEAR,
        source_mode: $tr.find('.f-mode').val(),
        calculator_key: $tr.find('.f-calc').val(),
        params_json: r.params_json || '',
        target_direction: $tr.find('.f-dir').val(),
        target_value: $tr.find('.f-tval').val(),
        target_unit: $tr.find('.f-tunit').val(),
        target_text: $tr.find('.f-ttext').val(),
        owner_user_id: ownerId,
        owner_dept_id: ownerDeptId,
        owner_position_id: ownerPosId,
        owner_display: ownerDisp,
        is_active: $tr.find('.f-active').is(':checked') ? 1 : 0
    };
    if (post.source_mode==='auto' && !post.calculator_key) {
        if (!silent) alert('第 '+r.item_no+' 項：自動模式請選擇資料來源');
        d.reject(); return d.promise();
    }
    var mpost = {
        action:'save_indicator', indicator_id:r.indicator_id,
        name: r.name, clause: r.clause || '', stat_desc: r.stat_desc || '',
        freq: freq, value_type: vt, sort_order: r.sort_order, is_active: (r.ind_active==1?1:0)
    };
    $.post(API, mpost, function(mr){
        if (!mr.ok) { if (!silent) alert('第 '+r.item_no+' 項主檔儲存失敗：'+(mr.error||'')); d.reject(); return; }
        $.post(API, post, function(res){
            if (!res.ok) { if (!silent) alert('第 '+r.item_no+' 項儲存失敗：'+(res.error||'')); d.reject(); return; }
            // 就地更新 DATA（不 reload，避免清掉其他列未存的編輯 / 兼任擔當者被重新推斷）
            r.freq = freq; r.value_type = vt;
            r.source_mode = post.source_mode; r.calculator_key = post.calculator_key;
            r.owner_user_id = +ownerId || 0; r.owner_dept_id = +ownerDeptId || 0;
            r.owner_position_id = +ownerPosId || 0; r.owner_display = ownerDisp;
            r.target_direction = post.target_direction; r.target_value = post.target_value;
            r.target_unit = post.target_unit; r.target_text = post.target_text;
            r.year_active = post.is_active;
            // 只刷新該列的參數●標記，不動使用者已挑好的下拉
            $tr.find('.f-parambtn').html('參數' + (hasConfiguredParams(r) ? ' <span style="color:#7a9c3f;" title="已設定參數">●</span>' : ''));
            clearDirty(i);
            if (!silent) toast('第 '+r.item_no+' 項已儲存');
            d.resolve();
        }, 'json').fail(function(){ if (!silent) alert('第 '+r.item_no+' 項儲存失敗'); d.reject(); });
    }, 'json').fail(function(){ if (!silent) alert('第 '+r.item_no+' 項主檔儲存失敗'); d.reject(); });
    return d.promise();
}

/* ---- 變異偵測 + 全部儲存 ---- */
function markDirty(i){ $('tr[data-i="'+i+'"]').addClass('row-dirty').find('.f-save').addClass('dirty'); updateSaveAll(); }
function clearDirty(i){ $('tr[data-i="'+i+'"]').removeClass('row-dirty').find('.f-save').removeClass('dirty'); updateSaveAll(); }
function updateSaveAll(){ var n = $('#indBody tr.row-dirty').length; $('#btnSaveAll').toggle(n>0).find('.cnt').text(n); }
// 使用者手動改動列內任一控制項才標記（程式化 .val() 不觸發 change，故 setOwners 不會誤標）
$(document).on('change', '#indBody tr[data-i] select, #indBody tr[data-i] input', function(){
    var i = $(this).closest('tr[data-i]').data('i');
    if (i !== undefined && i !== null) markDirty(i);
});
$('#btnSaveAll').on('click', function(){
    var dirty = $('#indBody tr.row-dirty').map(function(){ return $(this).data('i'); }).get();
    if (!dirty.length) return;
    NProgress.start();
    var okc = 0;
    (function next(k){
        if (k >= dirty.length) { NProgress.done(); toast('已儲存 '+okc+' / '+dirty.length+' 項'); loadLog(); return; }
        saveRow(dirty[k], true).done(function(){ okc++; }).always(function(){ next(k+1); });
    })(0);
});

/* ---------- 參數 modal（依 registry schema 動態欄位＋開放前端勾選） ---------- */
var pCtx = null;
function openParams(i){
    var r = DATA.indicators[i];
    var calcKey = $('tr[data-i="'+i+'"]').find('.f-calc').val() || r.calculator_key;
    if (calcKey === '__builder__') { openBuilder(i); return; }
    if (!calcKey || !DATA.registry[calcKey]) { alert('請先選擇資料來源'); return; }
    var reg = DATA.registry[calcKey];
    var params = {};
    try { params = JSON.parse(r.params_json || '{}') || {}; } catch(e) {}
    pCtx = {i:i, calcKey:calcKey};
    $('#pTitle').text('計算參數：' + r.item_no + '. ' + r.name + '（' + reg.name + '）');
    var h = '<div class="param-hint" style="margin-bottom:8px;">' + esc(reg.desc) + '<br>對應頁面：' + esc(reg.page) + '</div>';
    if (!reg.params.length) h += '<div style="color:#8a6d45;font-size:13px;">此資料來源無可調參數。</div>';
    reg.params.forEach(function(pm, pi){
        var cur = params[pm.key];
        var v = (cur && typeof cur === 'object' && 'v' in cur) ? cur.v : cur;
        var fe = cur && typeof cur === 'object' && +cur.fe === 1;
        h += '<label>' + esc(pm.label);
        if (+pm.fe === 1) h += ' <span class="param-fe"><input type="checkbox" class="p-fe" data-key="'+esc(pm.key)+'"'
            + (fe?' checked':'')+' style="width:auto;"> 開放前端試算</span>';
        h += '</label>';
        h += renderParamInput(pm, v, pi);
    });
    $('#pBody').html(h);
    $('#pMask').show();
}
function renderParamInput(pm, v, pi){
    var id = 'pv'+pi;
    var listVal = Array.isArray(v) ? v.join(',') : (v===null||v===undefined ? '' : (typeof v==='object' ? '' : v));
    switch (pm.type) {
        case 'int': case 'num':
            return '<input type="number" id="'+id+'" class="p-in" data-key="'+esc(pm.key)+'" data-type="'+pm.type+'" step="any" value="'+esc(v===undefined||v===null?'':v)+'">';
        case 'bool':
            return '<select id="'+id+'" class="p-in" data-key="'+esc(pm.key)+'" data-type="bool">'
                 + '<option value="1"'+(+v===1?' selected':'')+'>是</option><option value="0"'+(+v!==1?' selected':'')+'>否</option></select>';
        case 'months_map': {
            var mm = (v && typeof v === 'object') ? v : {};
            var h = '<div class="months-grid" data-key="'+esc(pm.key)+'">';
            for (var m=1; m<=12; m++){
                h += '<input type="number" class="p-month" data-m="'+m+'" placeholder="'+m+'月" title="'+m+'月" step="any" value="'+(mm[m]!==undefined?mm[m]:(mm[String(m)]!==undefined?mm[String(m)]:''))+'">';
            }
            return h + '</div><div class="param-hint">留空月份=未設定</div>';
        }
        case 'typedays_map': {
            var mp = (v && typeof v === 'object' && !Array.isArray(v)) ? v : {};
            var h = '<div class="typedays" data-key="'+esc(pm.key)+'" style="max-height:230px;overflow:auto;border:1px solid #EADFC8;border-radius:4px;padding:6px;">';
            (DATA.dicts.process_types||[]).forEach(function(p){
                var dv = mp[p.process_type_id]!==undefined ? mp[p.process_type_id]
                       : (mp[String(p.process_type_id)]!==undefined ? mp[String(p.process_type_id)] : '');
                h += '<div style="display:flex;gap:8px;align-items:center;margin:2px 0;">'
                   + '<span style="flex:1;">'+esc(p.process_type)+'</span>'
                   + '<input type="number" class="p-td" data-tid="'+p.process_type_id+'" style="width:80px;" min="0" placeholder="天" value="'+dv+'"></div>';
            });
            return h + '</div><div class="param-hint">只填需要「不同於預設天數」的製程；留空=用上方預設約定工作天數</div>';
        }
        case 'process_type_ids': case 'machine_type_ids': {
            var sel = Array.isArray(v) ? v.map(Number) : [];
            var h = '<div class="chk-list" data-key="'+esc(pm.key)+'" data-type="idlist" style="max-height:200px;overflow:auto;border:1px solid #EADFC8;border-radius:4px;padding:6px;">';
            (DATA.dicts.process_types||[]).forEach(function(p){
                h += '<label><input type="checkbox" class="p-chk" value="'+p.process_type_id+'"'
                   + (sel.indexOf(+p.process_type_id)>=0?' checked':'')+' style="width:auto;"> '
                   + esc(p.process_type) + '</label>';
            });
            return h + '</div><div class="param-hint">勾選要納入計算的' + (pm.type==='machine_type_ids'?'機台種類':'製程類別') + '</div>';
        }
        case 'machine_ids': {
            var selM = Array.isArray(v) ? v.map(Number) : [];
            var byType = {};
            (DATA.dicts.machines||[]).forEach(function(m2){ (byType[m2.machine_type_id] = byType[m2.machine_type_id] || []).push(m2); });
            var typeName = {};
            (DATA.dicts.process_types||[]).forEach(function(p){ typeName[p.process_type_id] = p.process_type; });
            var h = '<div class="chk-list" data-key="'+esc(pm.key)+'" data-type="idlist" style="display:block;max-height:220px;overflow:auto;border:1px solid #EADFC8;border-radius:4px;padding:6px;">';
            Object.keys(byType).forEach(function(t){
                h += '<div style="font-weight:bold;color:#8a6d45;margin:4px 0 2px;">'+esc(typeName[t]||('種類'+t))+'</div><div style="display:flex;flex-wrap:wrap;gap:2px 12px;">';
                byType[t].forEach(function(m2){
                    h += '<label style="font-weight:normal;"><input type="checkbox" class="p-chk" value="'+m2.machine_id+'"'
                       + (selM.indexOf(+m2.machine_id)>=0?' checked':'')+' style="width:auto;"> '+esc(m2.machine)+'</label>';
                });
                h += '</div>';
            });
            return h + '</div><div class="param-hint">直接點選要納入的機台（可留空＝用上方機台種類全部）</div>';
        }
        case 'statuslist':
            return '<input type="text" id="'+id+'" class="p-in" data-key="'+esc(pm.key)+'" data-type="textlist" value="'+esc(listVal)+'" placeholder="ng,QQ,AOD">'
                 + '<div class="param-hint">ng=驗退、QQ=異常、AOD=特採</div>';
        case 'intlist':
            return '<input type="text" id="'+id+'" class="p-in" data-key="'+esc(pm.key)+'" data-type="intlist" value="'+esc(listVal)+'">';
        default: // textlist / text
            return '<input type="text" id="'+id+'" class="p-in" data-key="'+esc(pm.key)+'" data-type="textlist" value="'+esc(listVal)+'">';
    }
}
function saveParams(){
    var r = DATA.indicators[pCtx.i];
    var reg = DATA.registry[pCtx.calcKey];
    var out = {};
    reg.params.forEach(function(pm){
        var fe = $('.p-fe[data-key="'+pm.key+'"]').is(':checked') ? 1 : 0;
        var v = null;
        if (pm.type === 'months_map') {
            v = {};
            $('.months-grid[data-key="'+pm.key+'"] .p-month').each(function(){
                if ($.trim(this.value) !== '') v[$(this).data('m')] = +this.value;
            });
        } else if (pm.type === 'process_type_ids' || pm.type === 'machine_type_ids' || pm.type === 'machine_ids') {
            v = [];
            $('.chk-list[data-key="'+pm.key+'"] .p-chk:checked').each(function(){ v.push(+this.value); });
        } else if (pm.type === 'typedays_map') {
            v = {};
            $('.typedays[data-key="'+pm.key+'"] .p-td').each(function(){
                if ($.trim(this.value) !== '') v[$(this).data('tid')] = +this.value;
            });
        } else {
            var $in = $('.p-in[data-key="'+pm.key+'"]');
            var raw = $.trim($in.val());
            var t = $in.data('type');
            if (t === 'int' || t === 'num') v = raw === '' ? null : +raw;
            else if (t === 'bool') v = +raw;
            else if (t === 'intlist') v = raw === '' ? [] : raw.split(/[,，]+/).map(function(x){ return +$.trim(x); }).filter(function(x){ return !isNaN(x); });
            else v = raw === '' ? [] : raw.split(/[,，]+/).map(function(x){ return $.trim(x); }).filter(String);
        }
        out[pm.key] = {v: v, fe: fe};
    });
    r.params_json = JSON.stringify(out);
    $('#pMask').hide();
    saveRow(pCtx.i);
}

/* ---------- 指標主檔 modal ---------- */
var iCtx = null;
function openIndModal(i){
    var r = i === null ? null : DATA.indicators[i];
    iCtx = r ? r.indicator_id : 0;
    $('#iTitle').text(r ? '編輯指標主檔' : '新增指標');
    $('#iItemNo').val(r ? r.item_no : '').prop('disabled', !!r);
    $('#iName').val(r ? r.name : '');
    $('#iClause').val(r ? (r.clause||'') : '');
    $('#iStat').val(r ? (r.stat_desc||'') : '');
    $('#iFreq').val(r ? r.freq : 'monthly');
    $('#iVt').val(r ? r.value_type : 'percent');
    $('#iSort').val(r ? r.sort_order : '');
    $('#iActive').prop('checked', r ? r.ind_active==1 : true);
    $('#iMask').show();
}
$('#btnAddInd').on('click', function(){ openIndModal(null); });
function saveIndicator(){
    var post = {
        name: $('#iName').val(), clause: $('#iClause').val(), stat_desc: $('#iStat').val(),
        freq: $('#iFreq').val(), value_type: $('#iVt').val(),
        sort_order: $('#iSort').val() || 0,
        is_active: $('#iActive').is(':checked') ? 1 : 0
    };
    if (iCtx) { post.action = 'save_indicator'; post.indicator_id = iCtx; }
    else { post.action = 'add_indicator'; post.item_no = $('#iItemNo').val() || 0; post.year = YEAR; }
    $.post(API, post, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        $('#iMask').hide(); loadAll();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 權限規則 ---------- */
function renderRules(){
    var h = '';
    var pn = {view:'檢閱', fill:'填報', admin:'管理員'};
    DATA.rules.forEach(function(r){
        var body = r.rule_type === 'user'
            ? '指定人員：' + esc(r.user_name || ('user#'+r.user_id))
            : (r.dept_id ? '部門「'+esc(r.dept_name||r.dept_id)+'」' : '全部部門') + '，' + r.min_level + '階(含)以上主管';
        h += '<tr><td style="text-align:center;"><span class="rule-tag">'+pn[r.perm_type]+'</span></td>'
           + '<td style="text-align:center;">'+(r.rule_type==='user'?'指定人員':'部門×階級')+'</td>'
           + '<td>'+body+'</td>'
           + '<td style="text-align:center;"><button class="ks-btn red" onclick="delRule('+r.rule_id+')">刪除</button></td></tr>';
    });
    $('#ruleBody').html(h || '<tr><td colspan="4" style="color:#8a6d45;">尚無規則（僅靠角色指派與系統管理者）</td></tr>');
}
function renderDicts(){
    var $d = $('#rDept').empty().append('<option value="0">全部部門</option>');
    DATA.dicts.departments.forEach(function(d){ $d.append('<option value="'+d.id+'">'+esc(d.name)+'</option>'); });
    var $u2 = $('#rUser').empty();
    DATA.dicts.users.forEach(function(u2){ $u2.append('<option value="'+u2.id+'">'+esc(u2.user_cname)+'</option>'); });
}
$('#rType').on('change', function(){
    var isUser = this.value === 'user';
    $('#rDept, #rLevel').toggle(!isUser);
    $('#rUser').toggle(isUser);
});
$('#btnRuleAdd').on('click', function(){
    var post = {action:'perm_add', perm_type:$('#rPerm').val(), rule_type:$('#rType').val()};
    if (post.rule_type === 'dept_level') { post.dept_id = $('#rDept').val(); post.min_level = $('#rLevel').val(); }
    else post.user_id = $('#rUser').val();
    $.post(API, post, function(res){
        if (!res.ok) { alert(res.error||'新增失敗'); return; }
        loadAll();
    }, 'json');
});
function delRule(rid){
    if (!confirm('刪除此權限規則？')) return;
    $.post(API, {action:'perm_del', rule_id:rid}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadAll();
    }, 'json');
}

/* ---------- 附件設定 / 年度複製 / 變更歷史 ---------- */
$('#btnSaveSettings').on('click', function(){
    $.post(API, {action:'save_settings', attach_base:$('#setBase').val(), attach_max:$('#setMax').val()}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert('已儲存。實際生效路徑：' + res.attach_base_effective + (res.attach_base_ok ? '（可存取）' : '（目前無法存取，請確認NAS）'));
        loadAll();
    }, 'json');
});
$('#btnCopyYear').on('click', function(){
    var from = prompt('從哪一年度複製設定？（目標年度＝目前選擇的 ' + YEAR + '）', YEAR - 1);
    if (!from) return;
    $.post(API, {action:'copy_year', from:+from, to:YEAR}, function(res){
        if (!res.ok) { alert(res.error||'複製失敗'); return; }
        alert('已複製 ' + res.copied + ' 筆（僅補目前年度缺漏的指標設定）');
        loadAll();
    }, 'json');
});
function loadLog(){
    $.getJSON(API, {action:'log_list', limit:100}, function(res){
        if (!res.ok) return;
        var h = '';
        res.list.forEach(function(l){
            h += '<tr><td style="white-space:nowrap;">'+esc((l.changed_at||'').substr(0,16))+'</td>'
               + '<td>'+(l.item_no ? l.item_no+'.'+esc(l.indicator_name||'') : '')+'</td>'
               + '<td>'+(l.year||'')+'</td><td>'+(l.month||'')+'</td>'
               + '<td>'+esc(l.action)+'</td><td>'+esc(l.field||'')+'</td>'
               + '<td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(l.old_value||'')+'">'+esc(l.old_value||'')+'</td>'
               + '<td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(l.new_value||'')+'">'+esc(l.new_value||'')+'</td>'
               + '<td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(l.note||'')+'">'+esc(l.note||'')+'</td>'
               + '<td>'+esc(l.changed_by_name||'')+'</td></tr>';
        });
        $('#logBody').html(h || '<tr><td colspan="10" style="color:#8a6d45;">尚無紀錄</td></tr>');
    });
}
$('#btnLogReload').on('click', loadLog);
$('#yearSel').on('change', function(){ YEAR = +this.value; loadAll(); });
// 資料輸入視窗只用 ✕／取消／儲存 關閉；不因點背景或複製(Ctrl+C)誤關
$(document).on('focus', 'input[type=text], input[type=number]', function(){ this.select(); });
// Enter 跳下一欄；最後一欄 Enter＝送出
$(document).on('keydown', '#pBody input', function(e){
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var ins = $('#pBody input:visible');
    var idx = ins.index(this);
    if (idx > -1 && idx < ins.length - 1) ins.eq(idx + 1).focus().select();
    else saveParams();
});
$(document).on('keydown', '#iMask input', function(e){
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var ins = $('#iMask input:visible, #iMask select');
    var idx = ins.index(this);
    if (idx > -1 && idx < ins.length - 1) ins.eq(idx + 1).focus();
    else saveIndicator();
});

/* ================= 自訂公式 builder + 資料來源目錄 ================= */
var CATALOG = null, CAT_OPS = null, IS_SYSADMIN = false, bCtx = null;
function loadCatalog(cb){
    $.getJSON(API, {action:'get_catalog'}, function(res){
        if (!res.ok) { alert(res.error||'目錄載入失敗'); return; }
        CATALOG = res.catalog; CAT_OPS = res.ops; IS_SYSADMIN = res.is_sysadmin;
        $('#btnCatalog').toggle(!!IS_SYSADMIN);
        if (cb) cb();
    });
}
function dsById(id){ return (CATALOG||[]).find(function(c){ return +c.ds_id === +id; }); }
function catOptsHtml(sel){
    return '<option value="0">（選資料來源）</option>' + (CATALOG||[]).filter(function(c){return +c.is_active===1;}).map(function(c){
        return '<option value="'+c.ds_id+'"'+(+sel===+c.ds_id?' selected':'')+'>'+esc(c.ds_label)+'</option>'; }).join('');
}

/* ---- builder ---- */
function openBuilder(i){
    var r = DATA.indicators[i];
    bCtx = {i:i};
    loadCatalog(function(){
        if (!CATALOG.length) { alert('尚無資料來源目錄。請先由系統管理者點「資料來源目錄」登記資料表。'); return; }
        var spec = {};
        try { spec = JSON.parse(r.params_json || '{}') || {}; } catch(e){}
        if (!spec.num) spec = {num:{ds_id:0,agg:'count',filters:[]}, den:null, multiply_100:(r.value_type==='percent')};
        $('#bTitle').text('自訂公式：' + r.item_no + '. ' + r.name);
        renderBuilderSide('bNum', spec.num || {ds_id:0,agg:'count',filters:[]});
        var useDen = !!(spec.den && spec.den.ds_id);
        $('#bUseDen').prop('checked', useDen);
        $('#bDenBox').toggle(useDen);
        renderBuilderSide('bDen', spec.den || {ds_id:0,agg:'count',filters:[]});
        $('#bMul100').prop('checked', spec.multiply_100 !== false && (r.value_type==='percent' || !!spec.multiply_100));
        $('#bPreview').text('');
        $('#bMask').show();
    });
}
function toggleDen(){ $('#bDenBox').toggle($('#bUseDen').is(':checked')); }
function renderBuilderSide(boxId, side){
    var h = '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">';
    h += '<span>資料來源</span><select class="b-ds" onchange="onDsChange(this,\''+boxId+'\')" style="width:150px;">'+catOptsHtml(side.ds_id)+'</select>';
    h += '<span>計算</span><select class="b-agg" onchange="onAggChange(this,\''+boxId+'\')" style="width:100px;"><option value="count"'+(side.agg!=='sum'?' selected':'')+'>筆數</option><option value="sum"'+(side.agg==='sum'?' selected':'')+'>數值加總</option></select>';
    h += '<select class="b-measure" style="width:130px;'+(side.agg==='sum'?'':'display:none;')+'">'+measureOptsHtml(side.ds_id, side.measure_field_id)+'</select>';
    h += '</div>';
    h += '<div style="margin-top:6px;"><span style="color:#8a6d45;font-size:12px;">篩選條件（可不設）：</span> <button class="ks-btn gray" style="height:22px;font-size:11px;" onclick="addFilter(\''+boxId+'\')">＋條件</button></div>';
    h += '<div class="b-filters"></div>';
    $('#'+boxId).html(h);
    (side.filters||[]).forEach(function(f){ addFilter(boxId, f); });
}
function measureOptsHtml(dsId, sel){
    var ds = dsById(dsId);
    var ms = ds ? ds.fields.filter(function(f){ return f.role==='measure' && +f.is_active===1; }) : [];
    if (!ms.length) return '<option value="0">（此來源無可加總欄）</option>';
    return '<option value="0">（選數值欄）</option>' + ms.map(function(f){
        return '<option value="'+f.field_id+'"'+(+sel===+f.field_id?' selected':'')+'>'+esc(f.field_label)+'</option>'; }).join('');
}
function filterFieldOptsHtml(dsId, sel){
    var ds = dsById(dsId);
    var fs = ds ? ds.fields.filter(function(f){ return f.role==='filter' && +f.is_active===1; }) : [];
    return '<option value="0">（選欄位）</option>' + fs.map(function(f){
        return '<option value="'+f.field_id+'"'+(+sel===+f.field_id?' selected':'')+'>'+esc(f.field_label)+'</option>'; }).join('');
}
function onDsChange(sel, boxId){
    var dsId = $(sel).val();
    var $box = $('#'+boxId);
    $box.find('.b-measure').html(measureOptsHtml(dsId, 0));
    $box.find('.b-filters').empty();
    $box.find('.f-fieldsel').html(filterFieldOptsHtml(dsId, 0));
}
function onAggChange(sel, boxId){
    $('#'+boxId).find('.b-measure').toggle($(sel).val()==='sum');
}
function addFilter(boxId, f){
    f = f || {field_id:0, op:'in', values:[]};
    var dsId = $('#'+boxId).find('.b-ds').val();
    var opsHtml = Object.keys(CAT_OPS).map(function(k){
        return '<option value="'+k+'"'+(f.op===k?' selected':'')+'>'+esc(CAT_OPS[k])+'</option>'; }).join('');
    var valStr = Array.isArray(f.values) ? f.values.join(',') : (f.values==null?'':f.values);
    var h = '<div class="b-filter" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-top:4px;background:#FBF6EC;padding:4px;border-radius:4px;">'
          + '<select class="f-fieldsel" style="width:120px;">'+filterFieldOptsHtml(dsId, f.field_id)+'</select>'
          + '<select class="f-op" style="width:90px;">'+opsHtml+'</select>'
          + '<input type="text" class="f-vals" style="width:150px;" placeholder="值(可多個,逗號分隔)" value="'+esc(valStr)+'">'
          + '<button class="ks-btn gray" style="height:22px;font-size:11px;" onclick="loadFieldVals(this)">選現有值</button>'
          + '<button class="ks-btn red" style="height:22px;font-size:11px;" onclick="$(this).closest(\'.b-filter\').remove()">✕</button>'
          + '</div>';
    $('#'+boxId+' .b-filters').append(h);
}
function loadFieldVals(btn){
    var $f = $(btn).closest('.b-filter');
    var fid = $f.find('.f-fieldsel').val();
    if (!fid || fid==='0') { alert('請先選欄位'); return; }
    $.getJSON(API, {action:'get_field_values', field_id:fid}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        if (!res.values.length) { alert('此欄目前無資料值'); return; }
        var cur = $f.find('.f-vals').val().split(/[,，]+/).map(function(s){return s.trim();}).filter(String);
        var pick = prompt('現有值（勾選請直接編輯下方，逗號分隔）：\n' + res.values.slice(0,80).join('、'), cur.join(','));
        if (pick !== null) $f.find('.f-vals').val(pick);
    });
}
function collectSide(boxId){
    var $box = $('#'+boxId);
    var dsId = +$box.find('.b-ds').val();
    if (!dsId) return null;
    var agg = $box.find('.b-agg').val();
    var side = {ds_id:dsId, agg:agg, filters:[]};
    if (agg === 'sum') side.measure_field_id = +$box.find('.b-measure').val() || 0;
    $box.find('.b-filter').each(function(){
        var fid = +$(this).find('.f-fieldsel').val();
        if (!fid) return;
        var op = $(this).find('.f-op').val();
        var vals = $(this).find('.f-vals').val().split(/[,，]+/).map(function(s){return s.trim();}).filter(String);
        side.filters.push({field_id:fid, op:op, values:vals});
    });
    return side;
}
function collectSpec(){
    var num = collectSide('bNum');
    if (!num) { alert('請選擇分子的資料來源'); return null; }
    if (num.agg==='sum' && !num.measure_field_id) { alert('分子選了「數值加總」請選數值欄'); return null; }
    var spec = {num:num, den:null, multiply_100:false};
    if ($('#bUseDen').is(':checked')) {
        var den = collectSide('bDen');
        if (!den) { alert('已勾除以分母，請選擇分母的資料來源'); return null; }
        if (den.agg==='sum' && !den.measure_field_id) { alert('分母選了「數值加總」請選數值欄'); return null; }
        spec.den = den;
        spec.multiply_100 = $('#bMul100').is(':checked');
    }
    return spec;
}
function previewBuilder(){
    var spec = collectSpec(); if (!spec) return;
    $('#bPreview').text('計算中…');
    $.post(API, {action:'preview_builder', spec:JSON.stringify(spec), year:YEAR, month:''}, function(res){
        if (!res.ok) { $('#bPreview').text(res.error||'試算失敗'); return; }
        var r = res.result;
        if (!r) { $('#bPreview').html('<span style="color:#DD5138;">無法計算（來源/欄位設定不完整）</span>'); return; }
        var txt = '分子='+(+r.num) + (r.den!==null?' ／ 分母='+(+r.den):'') + ' → 結果=' + (r.value===null?'—':(Math.round(r.value*100)/100));
        $('#bPreview').html('<b>'+esc(txt)+'</b>（'+YEAR+'年本月）');
    }, 'json').fail(function(x){ $('#bPreview').text('試算失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function saveBuilder(){
    var spec = collectSpec(); if (!spec) return;
    var r = DATA.indicators[bCtx.i];
    r.params_json = JSON.stringify(spec);
    // 確保該列來源=自動+__builder__
    var $tr = $('tr[data-i="'+bCtx.i+'"]');
    $tr.find('.f-mode').val('auto').prop('disabled', false);
    $tr.find('.f-calc').prop('disabled', false).val('__builder__');
    $('#bMask').hide();
    saveRow(bCtx.i);
}

/* ---- 資料來源目錄管理 ---- */
$('#btnCatalog').on('click', function(){ loadCatalog(renderCatalogManager); });
function renderCatalogManager(){
    var h = '';
    (CATALOG||[]).forEach(function(c){
        h += '<div style="border:1px solid #EADFC8;border-radius:6px;padding:8px;margin-bottom:8px;">';
        h += '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">'
           + '<b style="color:#5b3a1e;">'+esc(c.ds_label)+'</b>'
           + '<span class="param-hint" style="margin:0;">表：'+esc(c.table_name)+'｜日期：'+esc(c.date_column)+'</span>'
           + (+c.is_active===1?'':'<span class="rule-tag" style="background:#eee;">停用</span>')
           + '<button class="ks-btn red" style="height:22px;font-size:11px;margin-left:auto;" onclick="delCatalog('+c.ds_id+')">刪來源</button></div>';
        h += '<table class="ks-table" style="margin-top:6px;"><thead><tr><th>中文欄位</th><th>欄位名</th><th>用途</th><th>型態</th><th></th></tr></thead><tbody>';
        (c.fields||[]).forEach(function(f){
            h += '<tr><td>'+esc(f.field_label)+'</td><td>'+esc(f.column_name)+'</td>'
               + '<td>'+(f.role==='measure'?'可加總':'可篩選')+'</td><td>'+esc(f.data_type)+'</td>'
               + '<td style="text-align:center;"><button class="ks-btn red" style="height:20px;font-size:11px;" onclick="delField('+f.field_id+')">刪</button></td></tr>';
        });
        h += '<tr style="background:#FBF6EC;"><td><input type="text" class="nf-label" placeholder="中文欄位" style="width:100%;"></td>'
           + '<td><input type="text" class="nf-col" placeholder="欄位名" style="width:100%;"></td>'
           + '<td><select class="nf-role"><option value="filter">可篩選</option><option value="measure">可加總</option></select></td>'
           + '<td><select class="nf-type"><option value="text">文字</option><option value="number">數字</option><option value="date">日期</option></select></td>'
           + '<td style="text-align:center;"><button class="ks-btn" style="height:20px;font-size:11px;" onclick="saveField('+c.ds_id+',this)">加欄</button></td></tr>';
        h += '</tbody></table></div>';
    });
    $('#cList').html(h || '<div class="param-hint">尚無資料來源</div>');
    $('#cMask').show();
}
function saveCatalog(dsId){
    $.post(API, {action:'catalog_save', ds_id:dsId, ds_label:$('#ncLabel').val(), table_name:$('#ncTable').val(), date_column:$('#ncDate').val(), is_active:1}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        $('#ncLabel,#ncTable,#ncDate').val('');
        loadCatalog(renderCatalogManager);
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function delCatalog(dsId){
    if (!confirm('刪除此資料來源及其欄位？（用到它的自訂公式將算不出來）')) return;
    $.post(API, {action:'catalog_del', ds_id:dsId}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadCatalog(renderCatalogManager);
    }, 'json');
}
function saveField(dsId, btn){
    var $tr = $(btn).closest('tr');
    $.post(API, {action:'field_save', ds_id:dsId, field_label:$tr.find('.nf-label').val(), column_name:$tr.find('.nf-col').val(), role:$tr.find('.nf-role').val(), data_type:$tr.find('.nf-type').val(), is_active:1}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        loadCatalog(renderCatalogManager);
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function delField(fid){
    if (!confirm('刪除此欄位？')) return;
    $.post(API, {action:'field_del', field_id:fid}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadCatalog(renderCatalogManager);
    }, 'json');
}

if (canAdmin) { loadAll(); loadCatalog(); }
</script>
</body>
</html>
