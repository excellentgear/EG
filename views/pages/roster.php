<?php
/**
 * roster.php — 通用輪值排班表（掃地/值日/現場班別皆共用）
 * 後端：src/store/store_Roster_API.php ｜ 引擎：src/common/roster_lib.php
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_set_cookie_params(43200);
session_start();

require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/rbac.php';
require_once __DIR__ . '/../../src/common/roster_lib.php';

if (!isset($_SESSION['userName']) && !isset($_SESSION['id'])) {
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$me   = roster_current_user($pdo);
$MYID = (int)$me['id'];
$features = rbac_user_features($pdo, $MYID);

$CAN_VIEW   = rbac_has($features, 'roster_view');
$CAN_CREATE = rbac_has($features, 'roster_create');
$IS_ADMIN   = rbac_has($features, 'roster_admin') || rbac_has($features, 'all');
$hasAccess  = $CAN_VIEW || $IS_ADMIN;

$permParts = [];
if ($CAN_VIEW)   $permParts[] = '檢閱';
if ($CAN_CREATE) $permParts[] = '建立';
if (rbac_has($features, 'roster_edit'))   $permParts[] = '修改';
if (rbac_has($features, 'roster_delete')) $permParts[] = '刪除';
if ($IS_ADMIN)   $permParts[] = '管理';
$permBadge = $permParts ? implode('+', $permParts) : '無';

// 公開對象 / 職務欄 / 成員 選單資料
$pickers = roster_load_pickers($pdo);
// 部門階層路徑
$deptMap = [];
foreach ($pickers['departments'] as $d) $deptMap[$d['id']] = $d;
function rosterDeptPath($id, $map) {
    $path = []; $guard = 0;
    while ($id && isset($map[$id]) && $guard++ < 10) { array_unshift($path, $map[$id]['name']); $id = $map[$id]['parent_id']; }
    return implode(' / ', $path);
}
// 暖色系職務欄調色盤（鐵律：暖色，禁冷暖混雜）
$lanePalette = ['#DD5138', '#F0A24B', '#C0762C', '#E6B566', '#B5651D', '#D98C5F', '#8C5A3C', '#A64B2A'];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>輪值排班表 | EGsystem</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        :root{ --warm-line:#e4d8c6; --warm-bg:#faf6ef; --warm-head:#8c5a3c; --amber:#C0762C; --coral:#DD5138; }
        .roster-wrap{ padding:0 14px; }
        .rst-flex{ display:flex; gap:14px; align-items:flex-start; flex-wrap:wrap; }
        .rst-left{ flex:0 0 300px; max-width:320px; }
        .rst-right{ flex:1 1 620px; min-width:520px; }
        .rst-panel{ background:#fff; border:1px solid var(--warm-line); border-radius:6px; }
        .rst-panel-h{ background:var(--warm-bg); border-bottom:1px solid var(--warm-line); padding:8px 12px; font-weight:bold; color:var(--warm-head); display:flex; justify-content:space-between; align-items:center; }
        .rst-tabs{ display:flex; border-bottom:1px solid var(--warm-line); }
        .rst-tab{ flex:1; text-align:center; padding:7px; cursor:pointer; color:#8a7a63; }
        .rst-tab.active{ color:var(--amber); border-bottom:2px solid var(--amber); font-weight:bold; }
        .board-item{ padding:9px 12px; border-bottom:1px solid #f0e9dc; cursor:pointer; }
        .board-item:hover{ background:var(--warm-bg); }
        .board-item.sel{ background:#f6ead8; border-left:3px solid var(--amber); }
        .board-item .bname{ font-weight:bold; color:#5a4632; }
        .board-item .bmeta{ font-size:12px; color:#a08c72; margin-top:2px; }
        .board-item .bnext{ font-size:12px; color:var(--coral); margin-top:2px; }
        .rst-cal-head{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
        .cal-months{ display:flex; gap:16px; flex-wrap:wrap; }
        .cal-month{ flex:1 1 340px; min-width:300px; }
        .cal-month h4{ text-align:center; color:var(--warm-head); margin:4px 0 8px; }
        table.cal{ width:100%; border-collapse:collapse; table-layout:fixed; }
        table.cal th{ background:var(--warm-bg); color:var(--warm-head); font-size:12px; padding:4px 0; border:1px solid var(--warm-line); font-weight:normal; }
        table.cal td{ border:1px solid var(--warm-line); vertical-align:top; height:74px; padding:2px 3px; cursor:pointer; }
        table.cal td.empty{ background:#fbfbfb; cursor:default; }
        table.cal td.holiday{ background:#efe6d8; }
        table.cal td.makeup{ background:#fff6e8; }
        table.cal td.today{ box-shadow:inset 0 0 0 2px var(--amber); }
        table.cal td.sun .dnum{ color:#c0562c; }
        .dnum{ font-size:12px; color:#7a6a52; }
        .dnum .tag{ font-size:10px; color:#b08a5a; margin-left:2px; }
        .chip{ display:block; font-size:11px; margin-top:2px; padding:1px 4px; border-radius:3px; color:#4a3a28; background:#f3ead9;
               white-space:nowrap; overflow:hidden; text-overflow:ellipsis; border-left:4px solid #ccc; }
        .chip.signed{ background:var(--amber); color:#fff; border-left-color:#8c5320; }
        .chip.left{ text-decoration:line-through; opacity:.6; }
        .chip.adj::after{ content:'調'; font-size:9px; background:#fff; color:var(--coral); border-radius:2px; padding:0 2px; margin-left:3px; }
        .chip.dim{ opacity:.2; }
        .legend{ font-size:12px; color:#a08c72; }
        .legend b{ display:inline-block; width:12px; height:12px; border-radius:2px; vertical-align:middle; margin:0 3px 0 8px; }
        /* 列表 */
        table.rst-list{ width:100%; border-collapse:collapse; }
        table.rst-list th{ background:var(--warm-bg); color:var(--warm-head); padding:6px 8px; border:1px solid var(--warm-line); font-size:13px; }
        table.rst-list td{ padding:5px 8px; border:1px solid var(--warm-line); font-size:13px; }
        /* 編輯器 */
        .lane-row{ display:flex; gap:6px; align-items:flex-start; margin-bottom:8px; padding:8px; background:var(--warm-bg); border-radius:5px; }
        .picker ul{ list-style:none; margin:4px 0 0; padding:0; max-height:150px; overflow:auto; border:1px solid var(--warm-line); border-radius:4px; }
        .picker li{ display:flex; align-items:center; gap:6px; padding:3px 6px; border-bottom:1px solid #f0e9dc; font-size:13px; }
        .picker li .nm{ flex:1; }
        .picker li .idx{ color:#b09; color:#a08c72; width:20px; text-align:right; }
        .vis-box{ max-height:190px; overflow:auto; border:1px solid var(--warm-line); border-radius:5px; padding:6px; }
        .vis-box label{ display:block; font-weight:normal; margin:1px 0; font-size:13px; }
        .vis-grp{ font-weight:bold; color:var(--warm-head); margin:6px 0 2px; font-size:12px; }
        input[type=number].no-spin::-webkit-outer-spin-button,
        input[type=number].no-spin::-webkit-inner-spin-button{ -webkit-appearance:none; margin:0; }
        input[type=number].no-spin{ -moz-appearance:textfield; }
        .swatch{ width:20px; height:20px; border-radius:3px; display:inline-block; border:1px solid #0002; cursor:pointer; margin:1px; vertical-align:middle; }
        .swatch.on{ box-shadow:0 0 0 2px var(--warm-head); }
    </style>
</head>
<body class="nav-md">
<div class="container body"><div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>
    <div class="right_col" role="main">
        <div class="roster-wrap">
            <div class="page-title">
                <div class="title_left">
                    <h3>輪值排班表
                        <small>（權限：<?= htmlspecialchars($permBadge) ?>）
                            <a href="#" id="btn-perm-help" title="各角色權限說明"><i class="fa fa-question-circle"></i></a>
                        </small>
                    </h3>
                </div>
            </div>
            <div class="clearfix"></div>

            <?php if (!$hasAccess): ?>
                <div class="alert alert-warning" style="margin-top:20px;">您沒有檢閱此頁的權限，請洽管理者於「使用者權限設定」指派「輪值排班」角色。</div>
            <?php else: ?>
            <div class="rst-flex">
                <!-- 左：表清單 -->
                <div class="rst-left">
                    <div class="rst-panel">
                        <div class="rst-panel-h">排班表
                            <?php if ($CAN_CREATE || $IS_ADMIN): ?>
                            <button class="btn btn-xs btn-warning" style="margin:0" onclick="R.openEditor(0)"><i class="fa fa-plus"></i> 新增</button>
                            <?php endif; ?>
                        </div>
                        <div class="rst-tabs">
                            <div class="rst-tab active" data-scope="all" onclick="R.switchScope(this)">全部</div>
                            <div class="rst-tab" data-scope="mine" onclick="R.switchScope(this)">我建立</div>
                            <div class="rst-tab" data-scope="shared" onclick="R.switchScope(this)">分享給我</div>
                        </div>
                        <div id="boardList"><div style="padding:14px;color:#a08c72;">載入中…</div></div>
                    </div>
                </div>

                <!-- 右：月曆 / 列表 -->
                <div class="rst-right">
                    <div class="rst-panel" style="padding:12px;">
                        <div id="calEmpty" style="color:#a08c72;padding:20px;text-align:center;">← 請從左側選擇一張排班表</div>
                        <div id="calArea" style="display:none;">
                            <div class="rst-cal-head">
                                <button class="btn btn-default btn-sm" onclick="R.moveMonth(-1)"><i class="fa fa-chevron-left"></i></button>
                                <button class="btn btn-default btn-sm" onclick="R.goToday()">本月</button>
                                <button class="btn btn-default btn-sm" onclick="R.moveMonth(1)"><i class="fa fa-chevron-right"></i></button>
                                <span style="font-weight:bold;color:#5a4632;" id="calBoardName"></span>
                                <span style="flex:1"></span>
                                <select id="filterPerson" class="form-control input-sm" style="width:130px" onchange="R.loadCalendar()">
                                    <option value="0">全部人員</option>
                                </select>
                                <div class="btn-group" data-toggle="buttons">
                                    <button class="btn btn-sm btn-warning" id="viewCal" onclick="R.setView('cal')">月曆</button>
                                    <button class="btn btn-sm btn-default" id="viewList" onclick="R.setView('list')">列表</button>
                                </div>
                                <span id="ownerTools" style="display:none">
                                    <button class="btn btn-sm btn-default" onclick="R.openEditorCurrent()" title="編輯設定"><i class="fa fa-cog"></i></button>
                                    <button class="btn btn-sm btn-default" onclick="R.openRange()" title="區間調班"><i class="fa fa-random"></i> 區間調班</button>
                                </span>
                            </div>
                            <div class="legend" style="margin-bottom:8px;">
                                圖例：<b style="background:#efe6d8"></b>休假 <b style="background:#fff6e8"></b>補班 <b style="background:var(--amber)"></b>已簽核 <b style="background:#f3ead9"></b>未簽核 ・「調」＝已調班
                            </div>
                            <div id="calView"><div class="cal-months" id="calMonths"></div></div>
                            <div id="listView" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include '../partPage/footer.html'; ?>
</div></div>

<!-- 權限說明 modal -->
<div class="modal fade" id="permHelp" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">輪值排班・角色權限</h4></div>
    <div class="modal-body">
        <ul>
            <li><b>檢閱</b>：可開啟本頁，看到「自己建立」與「被設為公開對象」的排班表。</li>
            <li><b>建立</b>：可新增自己的排班表（每人各自建立、互不干擾）。</li>
            <li><b>修改 / 刪除</b>：對自己建立的表可編輯 / 刪除。</li>
            <li><b>管理</b>：可檢視所有表、代替他人補簽、對任何表調班。</li>
            <li>值勤本人可對自己的班別點選「簽核」。公開對象名單內的人才看得到該表內容。</li>
        </ul>
    </div>
    <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>
</div></div></div>

<!-- 表編輯器 modal -->
<div class="modal fade" id="editorModal" tabindex="-1" data-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="edTitle">新增排班表</h4></div>
    <div class="modal-body">
        <input type="hidden" id="ed_id">
        <div class="row">
            <div class="col-sm-6 form-group"><label>表名稱 *</label><input type="text" id="ed_name" class="form-control" placeholder="例：一樓廁所打掃"></div>
            <div class="col-sm-6 form-group"><label>用途說明</label><input type="text" id="ed_purpose" class="form-control" placeholder="選填"></div>
        </div>
        <div class="row">
            <div class="col-sm-4 form-group"><label>起始日 *</label><input type="date" id="ed_start" class="form-control"></div>
            <div class="col-sm-4 form-group"><label>人員歸屬</label>
                <select id="ed_member_mode" class="form-control" onchange="R.onModeChange()">
                    <option value="per_lane">各職務欄各自一份名單</option>
                    <option value="shared_pool">全表共用一池・輪流換到不同欄</option>
                </select>
            </div>
            <div class="col-sm-4 form-group"><label>換手頻率</label>
                <select id="ed_rotate" class="form-control">
                    <option value="each">每次執行就換下一人</option>
                    <option value="weekly">每週換一次</option>
                    <option value="monthly">每月換一次</option>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-4 form-group"><label>執行週期</label>
                <select id="ed_cadence" class="form-control" onchange="R.onCadenceChange()">
                    <option value="daily">每個工作天</option>
                    <option value="weekly">每週</option>
                    <option value="monthly">每月</option>
                </select>
            </div>
            <div class="col-sm-8" id="cadenceExtra"></div>
        </div>
        <div class="checkbox"><label><input type="checkbox" id="ed_sign" checked> 需要值勤本人簽核（掃完/做完點選）</label></div>

        <hr>
        <div id="sharedPoolBox" style="display:none;">
            <label>共用人員名單（依序輪值）</label>
            <div id="sharedPicker" class="picker"></div>
        </div>

        <label>職務欄（男廁/女廁、早班/晚班…可多欄）</label>
        <div id="lanesBox"></div>
        <button class="btn btn-default btn-sm" onclick="R.addLane()"><i class="fa fa-plus"></i> 新增職務欄</button>

        <hr>
        <label>公開對象（只有名單內的人看得到此表）</label>
        <input type="text" class="form-control input-sm" id="visSearch" placeholder="搜尋部門/身分/姓名…" oninput="R.filterVis()" style="margin-bottom:6px;">
        <div class="vis-box" id="visBox"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-warning" onclick="R.saveBoard()"><i class="fa fa-save"></i> 儲存</button>
    </div>
</div></div></div>

<!-- 當日排班 modal -->
<div class="modal fade" id="dayModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="dayTitle"></h4></div>
    <div class="modal-body" id="dayBody"></div>
    <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>
</div></div></div>

<!-- 區間調班 modal -->
<div class="modal fade" id="rangeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">區間調班</h4></div>
    <div class="modal-body">
        <div class="row">
            <div class="col-sm-6 form-group"><label>起</label><input type="date" id="rg_from" class="form-control"></div>
            <div class="col-sm-6 form-group"><label>迄</label><input type="date" id="rg_to" class="form-control"></div>
        </div>
        <div class="form-group"><label>職務欄</label><select id="rg_lane" class="form-control"></select></div>
        <div class="form-group"><label>原負責人（可留空＝該欄全部）</label><select id="rg_from_user" class="form-control"><option value="0">— 不限 —</option></select></div>
        <div class="form-group"><label>改由誰負責 *</label><select id="rg_to_user" class="form-control"></select></div>
        <div class="form-group"><label>備註</label><input type="text" id="rg_note" class="form-control" placeholder="例：出差代班"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">取消</button><button class="btn btn-warning" onclick="R.submitRange()">套用</button></div>
</div></div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script>
var RD = {
    api: '../../src/store/store_Roster_API.php',
    users: <?= json_encode($pickers['users'], JSON_UNESCAPED_UNICODE) ?>,
    shifts: <?= json_encode($pickers['shift_types'], JSON_UNESCAPED_UNICODE) ?>,
    palette: <?= json_encode($lanePalette) ?>,
    myid: <?= $MYID ?>,
    canCreate: <?= ($CAN_CREATE || $IS_ADMIN) ? 'true' : 'false' ?>
};
var R = (function(){
    var scope='all', boards=[], curBoard=null, curYm=null, view='cal', calData=null;

    function post(action,data){ data=data||{}; data.action=action; return $.post(RD.api,data,null,'json'); }
    function esc(s){ return $('<div>').text(s==null?'':s).html(); }
    function ym(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'); }

    /* ── 表清單 ── */
    function loadBoards(){
        post('list_boards',{scope:scope}).done(function(r){
            if(!r.success){ $('#boardList').html('<div style="padding:14px;color:#c0392b">'+esc(r.message)+'</div>'); return; }
            boards=r.boards; renderBoards();
        });
    }
    function renderBoards(){
        if(!boards.length){ $('#boardList').html('<div style="padding:14px;color:#a08c72;">尚無排班表'+(RD.canCreate?'，點右上「新增」建立':'')+'</div>'); return; }
        var h='';
        boards.forEach(function(b){
            h+='<div class="board-item'+(curBoard&&curBoard.id===b.id?' sel':'')+'" onclick="R.selectBoard('+b.id+')">'
              +'<div class="bname">'+esc(b.name)+(b.status==='archived'?' <span class="label label-default">已封存</span>':'')+'</div>'
              +'<div class="bmeta">'+(b.is_mine?'我建立':'由 '+esc(b.owner_name))+' ・ '+b.lane_count+'欄 / '+b.member_count+'人</div>'
              +(b.next_my_duty?'<div class="bnext"><i class="fa fa-bell"></i> 我的下次值勤：'+b.next_my_duty+'</div>':'')
              +'</div>';
        });
        $('#boardList').html(h);
    }
    function switchScope(el){ $('.rst-tab').removeClass('active'); $(el).addClass('active'); scope=$(el).data('scope'); loadBoards(); }
    function selectBoard(id){
        curBoard=boards.find(function(b){return b.id===id;});
        curYm=curYm||ym(new Date());
        $('.board-item').removeClass('sel'); renderBoards();
        $('#calEmpty').hide(); $('#calArea').show();
        $('#ownerTools').toggle(!!(curBoard&&curBoard.can_edit));
        loadCalendar();
    }

    /* ── 月曆 ── */
    function loadCalendar(){
        if(!curBoard) return;
        var fu=$('#filterPerson').val()||0;
        post('get_calendar',{id:curBoard.id, ym:curYm, filter_user:fu}).done(function(r){
            if(!r.success){ alert(r.message); return; }
            calData=r; $('#calBoardName').text(r.board.name);
            // 人員篩選下拉
            var cur=$('#filterPerson').val();
            var opt='<option value="0">全部人員</option>';
            r.people.forEach(function(p){ opt+='<option value="'+p.id+'">'+esc(p.name)+(p.left?'（已離職）':'')+'</option>'; });
            $('#filterPerson').html(opt).val(cur);
            if(view==='cal') renderCalendar(); else renderList();
        });
    }
    function laneMap(){ var m={}; (calData.lanes||[]).forEach(function(l){ m[l.id]=l; }); return m; }
    function laneColor(l,i){ return (l&&l.color)||RD.palette[i%RD.palette.length]; }

    function renderCalendar(){
        $('#calView').show(); $('#listView').hide();
        var lm=laneMap(), hol={}, mk={};
        (calData.holidays||[]).forEach(function(d){hol[d]=1;}); (calData.makeup||[]).forEach(function(d){mk[d]=1;});
        var html='';
        calData.months.forEach(function(mo){ html+=monthGrid(mo,lm,hol,mk); });
        $('#calMonths').html(html);
    }
    function monthGrid(mo,lm,hol,mk){
        var y=+mo.slice(0,4), m=+mo.slice(5,7);
        var first=new Date(y,m-1,1), start=first.getDay(), dim=new Date(y,m,0).getDate();
        var wd=['日','一','二','三','四','五','六'];
        var h='<div class="cal-month"><h4>'+y+' 年 '+m+' 月</h4><table class="cal"><tr>';
        wd.forEach(function(w,i){ h+='<th'+(i===0?' style="color:#c0562c"':'')+'>'+w+'</th>'; });
        h+='</tr><tr>';
        var col=0;
        for(var i=0;i<start;i++){ h+='<td class="empty"></td>'; col++; }
        for(var day=1;day<=dim;day++){
            var ds=mo+'-'+String(day).padStart(2,'0');
            var dow=new Date(y,m-1,day).getDay();
            var cls='';
            if(hol[ds]) cls+=' holiday'; if(mk[ds]) cls+=' makeup'; if(dow===0) cls+=' sun';
            if(ds===calData.today) cls+=' today';
            var tag=hol[ds]?'<span class="tag">休</span>':(mk[ds]?'<span class="tag">補</span>':'');
            h+='<td class="'+cls.trim()+'" onclick="R.openDay(\''+ds+'\')"><div class="dnum">'+day+tag+'</div>';
            var cells=(calData.cells[ds]||[]);
            cells.forEach(function(c){
                var l=lm[c.lane_id], idx=(calData.lanes||[]).indexOf(l);
                var lcol=laneColor(l,idx);
                h+='<div class="chip'+(c.sign?' signed':'')+(c.left?' left':'')+(c.adjusted?' adj':'')+'" style="border-left-color:'+lcol+'" title="'+esc((l?l.lane_name:'')+'：'+c.name)+'">'
                  +(c.sign?'<i class="fa fa-check"></i> ':'')+esc(c.name)+'</div>';
            });
            h+='</td>'; col++;
            if(col%7===0 && day<dim) h+='</tr><tr>';
        }
        while(col%7!==0){ h+='<td class="empty"></td>'; col++; }
        h+='</tr></table></div>';
        return h;
    }

    function renderList(){
        $('#calView').hide(); $('#listView').show();
        var lm=laneMap(), rows=[];
        Object.keys(calData.cells).sort().forEach(function(d){
            calData.cells[d].forEach(function(c){ rows.push({d:d,c:c}); });
        });
        if(!rows.length){ $('#listView').html('<div style="color:#a08c72;padding:16px">此區間無排班</div>'); return; }
        var h='<table class="rst-list"><tr><th>日期</th><th>職務欄</th><th>負責人</th><th>狀態</th><th>操作</th></tr>';
        rows.forEach(function(x){
            var l=lm[x.c.lane_id];
            h+='<tr><td>'+x.d+'</td><td>'+esc(l?l.lane_name:'')+'</td>'
              +'<td>'+esc(x.c.name)+(x.c.left?' <span class="label label-default">離職</span>':'')+(x.c.adjusted?' <span class="label label-warning">調</span>':'')+'</td>'
              +'<td>'+(x.c.sign?'<span style="color:#C0762C"><i class="fa fa-check"></i> 已簽</span>':'<span style="color:#a08c72">未簽</span>')+'</td>'
              +'<td>'+actionBtns(x.c,x.d)+'</td></tr>';
        });
        h+='</table>';
        $('#listView').html(h);
    }
    function actionBtns(c,d){
        var b='';
        if(calData.board.sign_required){
            if(c.can_sign||calData.board.is_admin||calData.board.can_edit){
                b+= c.sign? '<button class="btn btn-xs btn-default" onclick="R.sign('+c.aid+',0)">取消簽核</button>'
                          : '<button class="btn btn-xs btn-warning" onclick="R.sign('+c.aid+',1)">簽核</button> ';
            }
        }
        if(calData.board.can_edit) b+=' <button class="btn btn-xs btn-default" onclick="R.adjustSingle('+c.aid+',\''+esc(c.name)+'\')">調班</button>';
        return b||'—';
    }

    /* ── 當日 modal ── */
    function openDay(ds){
        if(!calData) return;
        var cells=calData.cells[ds]||[], lm=laneMap();
        $('#dayTitle').text(ds+' 排班');
        if(!cells.length){ $('#dayBody').html('<div style="color:#a08c72">當天無排班</div>'); }
        else{
            var h='<table class="rst-list" style="width:100%"><tr><th>職務欄</th><th>負責人</th><th>狀態</th><th>操作</th></tr>';
            cells.forEach(function(c){ var l=lm[c.lane_id];
                h+='<tr><td>'+esc(l?l.lane_name:'')+'</td><td>'+esc(c.name)+(c.left?'（離職）':'')+'</td>'
                  +'<td>'+(c.sign?'<span style="color:#C0762C">已簽</span>':'未簽')+'</td><td>'+actionBtns(c,ds)+'</td></tr>';
            });
            h+='</table>'; $('#dayBody').html(h);
        }
        $('#dayModal').modal('show');
    }
    function sign(aid,to){
        post(to?'sign':'unsign',{aid:aid}).done(function(r){ if(!r.success){alert(r.message);return;} loadCalendar(); refreshDay(); });
    }
    function refreshDay(){ if($('#dayModal').is(':visible')){ var t=$('#dayTitle').text().slice(0,10); setTimeout(function(){openDay(t);},200);} }
    function adjustSingle(aid,cur){
        var opt=userOptions(0);
        var html='<div style="margin-bottom:8px">將「'+esc(cur)+'」這一天的班改由：</div>'
                +'<select id="adjUser" class="form-control">'+opt+'</select>'
                +'<input id="adjNote" class="form-control" placeholder="備註（選填）" style="margin-top:8px">';
        showPrompt('單次調班',html,function(){
            post('adjust_single',{aid:aid,new_user_id:$('#adjUser').val(),note:$('#adjNote').val()}).done(function(r){
                if(!r.success){alert(r.message);return;} $('#promptModal').modal('hide'); loadCalendar(); refreshDay();
            });
        });
    }

    /* ── 區間調班 ── */
    function openRange(){
        if(!curBoard) return;
        var lo='<option value="">全部職務欄</option>'; (calData.lanes||[]).forEach(function(l){ lo+='<option value="'+l.id+'">'+esc(l.lane_name)+'</option>'; });
        $('#rg_lane').html(lo);
        $('#rg_from_user').html('<option value="0">— 不限 —</option>'+userOptions(-1));
        $('#rg_to_user').html(userOptions(0));
        $('#rg_from').val(calData.today); $('#rg_to').val(calData.today); $('#rg_note').val('');
        $('#rangeModal').modal('show');
    }
    function submitRange(){
        post('adjust_range',{board_id:curBoard.id,lane_id:$('#rg_lane').val(),date_from:$('#rg_from').val(),date_to:$('#rg_to').val(),
            from_user_id:$('#rg_from_user').val(),to_user_id:$('#rg_to_user').val(),note:$('#rg_note').val()}).done(function(r){
            if(!r.success){alert(r.message);return;} $('#rangeModal').modal('hide'); alert('已調整 '+r.affected+' 筆'); loadCalendar();
        });
    }
    function userOptions(mode){ // mode -1: 不含空; 0: 一般
        var o=''; RD.users.forEach(function(u){ o+='<option value="'+u.id+'">'+esc(u.user_cname)+(u.department_name?'（'+esc(u.department_name)+'）':'')+'</option>'; }); return o;
    }

    /* ── 通用 prompt modal（動態） ── */
    function showPrompt(title,bodyHtml,onOk){
        if(!$('#promptModal').length){
            $('body').append('<div class="modal fade" id="promptModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">'
              +'<div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="pmTitle"></h4></div>'
              +'<div class="modal-body" id="pmBody"></div>'
              +'<div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">取消</button><button class="btn btn-warning" id="pmOk">確定</button></div>'
              +'</div></div></div>');
        }
        $('#pmTitle').text(title); $('#pmBody').html(bodyHtml); $('#pmOk').off('click').on('click',onOk); $('#promptModal').modal('show');
    }

    /* ── 編輯器 ── */
    function openEditor(id){
        resetEditor();
        if(id){ post('get_board',{id:id}).done(function(r){ if(!r.success){alert(r.message);return;} fillEditor(r); $('#editorModal').modal('show'); }); }
        else { $('#edTitle').text('新增排班表'); $('#ed_start').val(calData?calData.today:new Date().toISOString().slice(0,10)); addLane(); onModeChange(); onCadenceChange(); renderVis([]); $('#editorModal').modal('show'); }
    }
    function openEditorCurrent(){ if(curBoard) openEditor(curBoard.id); }
    function resetEditor(){ $('#ed_id,#ed_name,#ed_purpose').val(''); $('#lanesBox').empty(); $('#ed_member_mode').val('per_lane'); $('#ed_cadence').val('daily'); $('#ed_rotate').val('each'); $('#ed_sign').prop('checked',true); }
    function fillEditor(r){
        var b=r.board;
        $('#edTitle').text('編輯：'+b.name);
        $('#ed_id').val(b.id); $('#ed_name').val(b.name); $('#ed_purpose').val(b.purpose); $('#ed_start').val(b.start_date);
        $('#ed_member_mode').val(b.member_mode); $('#ed_cadence').val(b.exec_cadence); $('#ed_rotate').val(b.rotate_unit); $('#ed_sign').prop('checked',b.sign_required==1);
        $('#lanesBox').empty();
        (r.lanes||[]).forEach(function(l){ addLane(l); });
        onModeChange(); onCadenceChange(b);
        if(b.member_mode==='shared_pool') buildPicker($('#sharedPicker'), r.shared_members);
        renderVis(r.visibility||[]);
    }
    function onModeChange(){
        var per = $('#ed_member_mode').val()==='per_lane';
        $('#sharedPoolBox').toggle(!per);
        $('.lane-members').toggle(per);
        if(!per && !$('#sharedPicker').data('built')) buildPicker($('#sharedPicker'), []);
    }
    function onCadenceChange(b){
        var c=$('#ed_cadence').val(), h='';
        if(c==='weekly'){
            h='<label>每週方式</label><div style="margin-bottom:6px"><label style="font-weight:normal"><input type="radio" name="wkmode" value="days" checked onchange="R.toggleWk()"> 指定星期幾</label> &nbsp;'
             +'<label style="font-weight:normal"><input type="radio" name="wkmode" value="auto" onchange="R.toggleWk()"> 自動平均分散</label></div>'
             +'<div id="wkDays">'+['一','二','三','四','五','六','日'].map(function(w,i){return '<label class="checkbox-inline"><input type="checkbox" class="wkd" value="'+(i+1)+'">'+w+'</label>';}).join('')+'</div>'
             +'<div id="wkAuto" style="display:none">每週 <input type="number" id="ed_count" class="no-spin form-control" style="width:70px;display:inline-block" min="1" value="1"> 次</div>'
             +holidayPolicy();
        } else if(c==='monthly'){
            h='<label>每月方式</label><div style="margin-bottom:6px"><label style="font-weight:normal"><input type="radio" name="momode" value="days" checked onchange="R.toggleMo()"> 指定每月幾號</label> &nbsp;'
             +'<label style="font-weight:normal"><input type="radio" name="momode" value="auto" onchange="R.toggleMo()"> 自動平均分散</label></div>'
             +'<div id="moDays"><input type="text" id="ed_monthdays" class="form-control" placeholder="例：1,15,28（逗號分隔）"></div>'
             +'<div id="moAuto" style="display:none">每月 <input type="number" id="ed_count" class="no-spin form-control" style="width:70px;display:inline-block" min="1" value="1"> 次</div>'
             +holidayPolicy();
        } else { h='<div style="color:#a08c72;padding-top:24px">每個工作天都排班（依行事曆自動略過假日）。</div>'; }
        $('#cadenceExtra').html(h);
        if(b){ // 回填
            if(c==='weekly'){
                if(b.exec_weekdays){ $('[name=wkmode][value=days]').prop('checked',true); b.exec_weekdays.split(',').forEach(function(d){ $('.wkd[value="'+d+'"]').prop('checked',true); }); }
                else { $('[name=wkmode][value=auto]').prop('checked',true); $('#ed_count').val(b.exec_count); } toggleWk();
            } else if(c==='monthly'){
                if(b.exec_monthdays){ $('[name=momode][value=days]').prop('checked',true); $('#ed_monthdays').val(b.exec_monthdays); }
                else { $('[name=momode][value=auto]').prop('checked',true); $('#ed_count').val(b.exec_count); } toggleMo();
            }
            $('#ed_policy').val(b.holiday_policy);
        }
    }
    function holidayPolicy(){ return '<div style="margin-top:8px"><label>遇假日</label> <select id="ed_policy" class="form-control input-sm" style="width:150px;display:inline-block">'
        +'<option value="skip">跳過不排</option><option value="postpone">順延到下個工作天</option><option value="advance">提前到上個工作天</option></select></div>'; }
    function toggleWk(){ var a=$('[name=wkmode]:checked').val()==='auto'; $('#wkAuto').toggle(a); $('#wkDays').toggle(!a); }
    function toggleMo(){ var a=$('[name=momode]:checked').val()==='auto'; $('#moAuto').toggle(a); $('#moDays').toggle(!a); }

    // 職務欄
    function addLane(l){
        l=l||{}; var i=$('#lanesBox .lane-row').length;
        var color=l.color||RD.palette[i%RD.palette.length];
        var sw=RD.palette.map(function(c){ return '<span class="swatch'+(c===color?' on':'')+'" style="background:'+c+'" data-c="'+c+'" onclick="R.pickColor(this)"></span>'; }).join('');
        var shiftOpt='<option value="">（不綁班別）</option>'+RD.shifts.map(function(s){ return '<option value="'+s.shift_type_id+'"'+(l.shift_type_id==s.shift_type_id?' selected':'')+'>'+esc(s.shift_name)+'</option>'; }).join('');
        var $row=$('<div class="lane-row">'
            +'<div style="flex:1">'
              +'<input type="hidden" class="lane-id" value="'+(l.id||'')+'">'
              +'<div class="row"><div class="col-sm-5"><input type="text" class="form-control input-sm lane-name" placeholder="職務欄名稱(男廁/早班…)" value="'+esc(l.lane_name||'')+'"></div>'
              +'<div class="col-sm-4"><div class="lane-color" data-color="'+color+'">'+sw+'</div></div>'
              +'<div class="col-sm-3"><select class="form-control input-sm lane-shift">'+shiftOpt+'</select></div></div>'
              +'<div class="lane-members" style="margin-top:6px"><small>此欄人員（依序輪值）</small><div class="picker lane-picker"></div></div>'
            +'</div>'
            +'<button class="btn btn-xs btn-default" onclick="R.delLane(this)"><i class="fa fa-trash"></i></button>'
            +'</div>');
        $('#lanesBox').append($row);
        buildPicker($row.find('.lane-picker'), l.members||[]);
        onModeChange();
    }
    function delLane(btn){ $(btn).closest('.lane-row').remove(); }
    function pickColor(sp){ var $w=$(sp).closest('.lane-color'); $w.find('.swatch').removeClass('on'); $(sp).addClass('on'); $w.attr('data-color',$(sp).data('c')); }

    // 人員 picker widget
    function buildPicker($box, ids){
        $box.data('built',true);
        var sel='<select class="form-control input-sm pk-sel" style="display:inline-block;width:70%">'+userOptions(0)+'</select>'
              +' <button class="btn btn-xs btn-warning pk-add">加入</button><ul></ul>';
        $box.html(sel);
        $box.find('.pk-add').on('click',function(){ var v=$box.find('.pk-sel').val(); addToPicker($box,v); });
        (ids||[]).forEach(function(id){ addToPicker($box,id); });
    }
    function addToPicker($box,id){
        id=String(id); if($box.find('li[data-id="'+id+'"]').length) return;
        var u=RD.users.find(function(x){return String(x.id)===id;});
        var nm=u?u.user_cname:('#'+id);
        var $li=$('<li data-id="'+id+'"><span class="idx"></span><span class="nm">'+esc(nm)+'</span>'
            +'<a href="#" class="pk-up" title="上移">▲</a> <a href="#" class="pk-dn" title="下移">▼</a> <a href="#" class="pk-rm" title="移除">✕</a></li>');
        $box.find('ul').append($li); renumber($box);
        $li.find('.pk-up').on('click',function(e){e.preventDefault();$li.prev().length&&$li.insertBefore($li.prev());renumber($box);});
        $li.find('.pk-dn').on('click',function(e){e.preventDefault();$li.next().length&&$li.insertAfter($li.next());renumber($box);});
        $li.find('.pk-rm').on('click',function(e){e.preventDefault();$li.remove();renumber($box);});
    }
    function renumber($box){ $box.find('li').each(function(i){ $(this).find('.idx').text((i+1)); }); }
    function pickerIds($box){ return $box.find('li').map(function(){return +$(this).data('id');}).get(); }

    // 公開對象
    function renderVis(selected){
        var set={}; (selected||[]).forEach(function(v){set[v]=1;});
        var h='<label><input type="checkbox" class="vischk" value="all"'+(set['all']?' checked':'')+' onchange="R.onAllVis(this)"> <b>全體（所有人可見）</b></label>';
        h+='<div class="vis-grp">部門</div>';
        <?php foreach ($pickers['departments'] as $d): ?>
        h+='<label class="visitem"><input type="checkbox" class="vischk" value="dept-<?= $d['id'] ?>"'+(set['dept-<?= $d['id'] ?>']?' checked':'')+'> <?= htmlspecialchars(addslashes(rosterDeptPath($d['id'], $deptMap))) ?></label>';
        <?php endforeach; ?>
        h+='<div class="vis-grp">身分別</div>';
        <?php foreach ($pickers['statuses'] as $s): ?>
        h+='<label class="visitem"><input type="checkbox" class="vischk" value="status-<?= $s['id'] ?>"'+(set['status-<?= $s['id'] ?>']?' checked':'')+'> <?= htmlspecialchars(addslashes($s['title'])) ?></label>';
        <?php endforeach; ?>
        h+='<div class="vis-grp">人員</div>';
        <?php foreach ($pickers['users'] as $u): ?>
        h+='<label class="visitem"><input type="checkbox" class="vischk" value="user-<?= $u['id'] ?>"'+(set['user-<?= $u['id'] ?>']?' checked':'')+'> <?= htmlspecialchars(addslashes($u['user_cname'])) ?></label>';
        <?php endforeach; ?>
        $('#visBox').html(h); onAllVisState();
    }
    function onAllVis(el){ onAllVisState(); }
    function onAllVisState(){ var all=$('.vischk[value=all]').is(':checked'); $('.visitem input').prop('disabled',all); }
    function filterVis(){ var q=$('#visSearch').val().toLowerCase(); $('#visBox .visitem').each(function(){ $(this).toggle($(this).text().toLowerCase().indexOf(q)>=0); }); }

    function collectVis(){ return $('.vischk:checked').map(function(){return $(this).val();}).get(); }

    function saveBoard(){
        var mode=$('#ed_member_mode').val();
        var lanes=[];
        $('#lanesBox .lane-row').each(function(){
            var $r=$(this);
            lanes.push({ id:$r.find('.lane-id').val()||0, lane_name:$r.find('.lane-name').val(), color:$r.find('.lane-color').attr('data-color'),
                shift_type_id:$r.find('.lane-shift').val(), members: mode==='per_lane'?pickerIds($r.find('.lane-picker')):[] });
        });
        if(!lanes.length){ alert('至少要有一個職務欄'); return; }
        var cadence=$('#ed_cadence').val(), payload={
            id:+($('#ed_id').val()||0), name:$('#ed_name').val(), purpose:$('#ed_purpose').val(),
            member_mode:mode, rotate_unit:$('#ed_rotate').val(), exec_cadence:cadence,
            holiday_policy: $('#ed_policy').length?$('#ed_policy').val():'skip',
            start_date:$('#ed_start').val(), sign_required:$('#ed_sign').is(':checked')?1:0,
            lanes:lanes, shared_members: mode==='shared_pool'?pickerIds($('#sharedPicker')):[],
            visibility: collectVis(), exec_weekdays:[], exec_monthdays:[], exec_count:1
        };
        if(cadence==='weekly'){
            if($('[name=wkmode]:checked').val()==='auto') payload.exec_count=+$('#ed_count').val()||1;
            else payload.exec_weekdays=$('.wkd:checked').map(function(){return +this.value;}).get();
        } else if(cadence==='monthly'){
            if($('[name=momode]:checked').val()==='auto') payload.exec_count=+$('#ed_count').val()||1;
            else payload.exec_monthdays=($('#ed_monthdays').val()||'').split(',').map(function(x){return +x.trim();}).filter(Boolean);
        }
        if(!payload.name.trim()){ alert('請輸入表名稱'); return; }
        post('save_board',{payload:JSON.stringify(payload)}).done(function(r){
            if(!r.success){ alert(r.message); return; }
            $('#editorModal').modal('hide');
            loadBoards();
            curYm=curYm||ym(new Date());
            setTimeout(function(){ loadBoards(); if(!curBoard||curBoard.id===r.id){ selectBoardById(r.id);} },200);
        });
    }
    function selectBoardById(id){ post('list_boards',{scope:'all'}).done(function(r){ boards=r.boards||[]; renderBoards(); selectBoard(id); }); }

    function deleteBoard(){ if(!curBoard||!confirm('確定刪除「'+curBoard.name+'」？此表所有排班紀錄將一併刪除。'))return;
        post('delete_board',{id:curBoard.id}).done(function(r){ if(!r.success){alert(r.message);return;} curBoard=null; $('#calArea').hide(); $('#calEmpty').show(); loadBoards(); }); }

    function moveMonth(n){ var d=new Date(curYm+'-01'); d.setMonth(d.getMonth()+n); curYm=ym(d); loadCalendar(); }
    function goToday(){ curYm=ym(new Date()); loadCalendar(); }
    function setView(v){ view=v; $('#viewCal').toggleClass('btn-warning',v==='cal').toggleClass('btn-default',v!=='cal');
        $('#viewList').toggleClass('btn-warning',v==='list').toggleClass('btn-default',v!=='list'); if(calData){ v==='cal'?renderCalendar():renderList(); } }

    $(function(){
        $('#btn-perm-help').on('click',function(e){e.preventDefault();$('#permHelp').modal('show');});
        <?php if ($hasAccess): ?>loadBoards();<?php endif; ?>
    });

    return { loadBoards:loadBoards, switchScope:switchScope, selectBoard:selectBoard, loadCalendar:loadCalendar,
        openDay:openDay, sign:sign, adjustSingle:adjustSingle, openRange:openRange, submitRange:submitRange,
        openEditor:openEditor, openEditorCurrent:openEditorCurrent, saveBoard:saveBoard, deleteBoard:deleteBoard,
        addLane:addLane, delLane:delLane, pickColor:pickColor, onModeChange:onModeChange, onCadenceChange:onCadenceChange,
        toggleWk:toggleWk, toggleMo:toggleMo, onAllVis:onAllVis, filterVis:filterVis, moveMonth:moveMonth, goToday:goToday, setView:setView };
})();
</script>
</body>
</html>
