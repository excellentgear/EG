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

// 公開對象 / 輪值項目 / 成員 選單資料
$pickers = roster_load_pickers($pdo);
// 部門階層路徑
$deptMap = [];
foreach ($pickers['departments'] as $d) $deptMap[$d['id']] = $d;
function rosterDeptPath($id, $map) {
    $path = []; $guard = 0;
    while ($id && isset($map[$id]) && $guard++ < 10) { array_unshift($path, $map[$id]['name']); $id = $map[$id]['parent_id']; }
    return implode(' / ', $path);
}
// 暖色系輪值項目調色盤（鐵律：暖色，禁冷暖混雜）
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
        .chip.mine{ box-shadow:inset 0 0 0 2px #8c5320; font-weight:bold; }
        .chip.pending{ background:repeating-linear-gradient(45deg,#f6e2c2,#f6e2c2 6px,#efd3a6 6px,#efd3a6 12px); color:#7a4a1a; }
        .swap-item{ padding:4px 0; border-bottom:1px dashed #e4d8c6; font-size:13px; }
        .swap-item:last-child{ border-bottom:none; }
        .rst-logtabs{ display:flex; border-bottom:1px solid var(--warm-line); margin-bottom:8px; }
        .rst-logtabs div{ padding:6px 14px; cursor:pointer; color:#8a7a63; }
        .rst-logtabs div.active{ color:var(--amber); border-bottom:2px solid var(--amber); font-weight:bold; }
        .chip .stamp{ display:inline-block; min-width:15px; height:15px; line-height:13px; text-align:center; border:1px solid #c0392b; color:#c0392b; background:#fff; border-radius:50%; font-size:9px; transform:rotate(-8deg); margin-right:3px; vertical-align:middle; }
        .chip.signed .stamp{ border-color:#fff; color:#fff; background:transparent; }
        #editorModal .modal-body{ max-height:72vh; overflow-y:auto; }
        .legend{ font-size:12px; color:#a08c72; }
        .legend b{ display:inline-block; width:12px; height:12px; border-radius:2px; vertical-align:middle; margin:0 3px 0 8px; }
        /* 列表 */
        table.rst-list{ width:100%; border-collapse:collapse; }
        table.rst-list th{ background:var(--warm-bg); color:var(--warm-head); padding:6px 8px; border:1px solid var(--warm-line); font-size:13px; }
        table.rst-list td{ padding:5px 8px; border:1px solid var(--warm-line); font-size:13px; }
        /* 編輯器 */
        .lane-row{ display:flex; gap:6px; align-items:flex-start; margin-bottom:8px; padding:8px; background:var(--warm-bg); border-radius:5px; }
        .pk-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:4px; margin-top:6px; }
        .pk-item{ display:flex; align-items:center; gap:4px; background:#fff; border:1px solid var(--warm-line); border-radius:4px; padding:3px 6px; font-size:12px; cursor:grab; }
        .pk-item.sortable-ghost{ opacity:.4; background:#f6ead8; }
        .pk-item .nm{ flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pk-item .pos{ color:#a08c72; }
        .pk-item .idx{ color:#b5651d; font-weight:bold; }
        .pk-item .pk-rm{ color:#c0392b; text-decoration:none; }
        .pk-empty{ color:#b7a789; font-size:12px; grid-column:1/-1; padding:4px; }
        .vis-box{ max-height:230px; overflow:auto; border:1px solid var(--warm-line); border-radius:5px; padding:6px; }
        .vis-items{ display:grid; grid-template-columns:repeat(3,1fr); gap:1px 8px; }
        .vis-box label{ font-weight:normal; margin:1px 0; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vis-grp{ font-weight:bold; color:var(--warm-head); margin:6px 0 2px; font-size:12px; }
        input[type=number].no-spin::-webkit-outer-spin-button,
        input[type=number].no-spin::-webkit-inner-spin-button{ -webkit-appearance:none; margin:0; }
        input[type=number].no-spin{ -moz-appearance:textfield; }
        .swatch{ width:20px; height:20px; border-radius:3px; display:inline-block; border:1px solid #0002; cursor:pointer; margin:1px; vertical-align:middle; }
        .swatch.on{ box-shadow:0 0 0 2px var(--warm-head); }
    </style>
</head>
<body class="nav-sm">
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
            <div id="swapBar" style="display:none;margin:6px 0;padding:8px 12px;background:#fff6e8;border:1px solid #e4d8c6;border-radius:6px;">
                <b style="color:#b5651d"><i class="fa fa-exchange"></i> 調班申請</b>
                <div id="swapInboxBox" style="margin-top:4px;"></div>
            </div>
            <div class="rst-flex">
                <!-- 左：表清單 -->
                <div class="rst-left">
                    <div class="rst-panel">
                        <div class="rst-panel-h">排班表
                            <span>
                                <button class="btn btn-xs btn-default" style="margin:0" onclick="R.openLogs()"><i class="fa fa-history"></i> 紀錄</button>
                                <?php if ($CAN_CREATE || $IS_ADMIN): ?>
                                <button class="btn btn-xs btn-warning" style="margin:0" onclick="R.openEditor(0)"><i class="fa fa-plus"></i> 新增</button>
                                <?php endif; ?>
                            </span>
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
                                    <button class="btn btn-sm btn-default" onclick="R.openRange()" title="區間調班"><i class="fa fa-random"></i> 區間調班</button>
                                </span>
                            </div>
                            <div class="legend" style="margin-bottom:8px;">
                                圖例：<b style="background:#efe6d8"></b>休假 <b style="background:#fff6e8"></b>補班 <b style="background:var(--amber)"></b>已簽核 <b style="background:#f3ead9"></b>未簽核 <b style="background:#fff;box-shadow:inset 0 0 0 2px #8c5320"></b>我的班 ・「調」＝已調班
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
                    <option value="per_lane">各輪值項目各自一份名單</option>
                    <option value="shared_pool">全表共用一池・輪流換到不同欄</option>
                </select>
            </div>
            <div class="col-sm-4 form-group"><label>換手頻率</label>
                <div style="display:flex;gap:4px;align-items:center;">
                    <span id="rotEvery" style="display:none;color:#8a7a63;">每</span>
                    <input type="number" id="ed_rotate_n" class="no-spin form-control" style="width:56px;display:none;" min="1" value="1" oninput="R.onRotateChange()">
                    <select id="ed_rotate" class="form-control" onchange="R.onRotateChange()">
                        <option value="each">每次執行就換</option>
                        <option value="week"><?= $IS_ADMIN ? '週換手' : '每週換' ?></option>
                        <option value="month"><?= $IS_ADMIN ? '月換手' : '每月換' ?></option>
                        <?php if ($IS_ADMIN): ?>
                        <option value="day">個工作天換手</option>
                        <?php endif; ?>
                    </select>
                </div>
                <small id="rotHint" style="color:#a08c72;"></small>
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

        <label>輪值項目（男廁/女廁、早班/晚班…可多欄）</label>
        <div id="lanesBox"></div>
        <button class="btn btn-default btn-sm" onclick="R.addLane()"><i class="fa fa-plus"></i> 新增輪值項目</button>

        <hr>
        <label>公開對象（只有名單內的人看得到此表）</label>
        <div style="display:flex;gap:6px;margin-bottom:6px;">
            <input type="text" class="form-control input-sm" id="visSearch" placeholder="搜尋部門/身分/姓名…" oninput="R.filterVis()" style="flex:1">
            <button type="button" class="btn btn-default btn-xs" onclick="R.selectAllRostered()"><i class="fa fa-users"></i> 全選被排班者</button>
        </div>
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
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">區間對調（建立者/管理員）</h4></div>
    <div class="modal-body">
        <p style="color:#8a7a63;font-size:12px;">把「甲」和「乙」在這段期間內的班全部互換（免對方同意）。</p>
        <div class="row">
            <div class="col-sm-6 form-group"><label>起</label><input type="date" id="rg_from" class="form-control"></div>
            <div class="col-sm-6 form-group"><label>迄</label><input type="date" id="rg_to" class="form-control"></div>
        </div>
        <div class="form-group"><label>輪值項目</label><select id="rg_lane" class="form-control" onchange="R.rgLaneChange()"></select></div>
        <div class="form-group"><label>甲（原負責人）*</label><select id="rg_from_user" class="form-control"></select></div>
        <div class="form-group"><label>乙（對調對象）*</label><select id="rg_to_user" class="form-control"></select></div>
        <div class="form-group"><label>備註</label><input type="text" id="rg_note" class="form-control" placeholder="例：兩人互調本週"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">取消</button><button class="btn btn-warning" onclick="R.submitRange()">套用</button></div>
</div></div></div>

<!-- 紀錄 modal -->
<div class="modal fade" id="logModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">紀錄</h4></div>
    <div class="modal-body">
        <div class="rst-logtabs">
            <div class="active" data-tab="board" onclick="R.logTab(this)">建立 / 刪除紀錄</div>
            <div data-tab="adjust" onclick="R.logTab(this)">調班紀錄（目前選取的表）</div>
        </div>
        <div id="logBoard"></div>
        <div id="logAdjust" style="display:none;"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>
</div></div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/Sortable.min.js"></script>
<script>
var RD = {
    api: '../../src/store/store_Roster_API.php',
    users: <?= json_encode($pickers['users'], JSON_UNESCAPED_UNICODE) ?>,
    shifts: <?= json_encode($pickers['shift_types'], JSON_UNESCAPED_UNICODE) ?>,
    palette: <?= json_encode($lanePalette) ?>,
    myid: <?= $MYID ?>,
    canCreate: <?= ($CAN_CREATE || $IS_ADMIN) ? 'true' : 'false' ?>,
    isAdmin: <?= $IS_ADMIN ? 'true' : 'false' ?>
};
var R = (function(){
    var scope='all', boards=[], curBoard=null, curYm=null, view='cal', calData=null;

    function post(action,data){ data=data||{}; data.action=action;
        return $.post(RD.api,data,null,'json').fail(function(x){
            var m='操作失敗（'+x.status+'）'; try{ m=(JSON.parse(x.responseText).message)||m; }catch(e){}
            alert(m);
        });
    }
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
            var tools = b.can_edit
              ? '<div style="margin-top:5px">'
                +'<button class="btn btn-xs btn-default" onclick="event.stopPropagation();R.openEditor('+b.id+')"><i class="fa fa-cog"></i> 編輯</button> '
                +'<button class="btn btn-xs btn-default" style="color:#c0392b" onclick="event.stopPropagation();R.deleteBoard('+b.id+',\''+esc(b.name).replace(/'/g,"\\\x27")+'\')"><i class="fa fa-trash"></i> 刪除</button>'
                +'</div>' : '';
            h+='<div class="board-item'+(curBoard&&curBoard.id===b.id?' sel':'')+'" onclick="R.selectBoard('+b.id+')">'
              +'<div class="bname">'+esc(b.name)+(b.status==='archived'?' <span class="label label-default">已封存</span>':'')+'</div>'
              +'<div class="bmeta">'+(b.is_mine?'我建立':'由 '+esc(b.owner_name))+' ・ '+b.lane_count+'欄 / '+b.member_count+'人</div>'
              +(b.next_my_duty?'<div class="bnext"><i class="fa fa-bell"></i> 我的下次值勤：'+b.next_my_duty+'</div>':'')
              +tools
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
                var tip=(l?l.lane_name:'')+'：'+c.name+(c.pending?'（申請調班中）':'')+(c.sign&&c.signed_at?'（已簽 '+c.signed_at+'）':'')+(c.mine?'（我）':'');
                h+='<div class="chip'+(c.sign?' signed':'')+(c.left?' left':'')+(c.adjusted?' adj':'')+(c.mine?' mine':'')+(c.pending?' pending':'')+'" style="border-left-color:'+lcol+'" title="'+esc(tip)+'">'
                  +(c.pending?'<i class="fa fa-clock-o"></i> ':(c.sign?'<span class="stamp">簽</span>':''))+esc(c.name)+'</div>';
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
        var h='<table class="rst-list"><tr><th>日期</th><th>輪值項目</th><th>負責人</th><th>狀態</th><th>操作</th></tr>';
        rows.forEach(function(x){
            var l=lm[x.c.lane_id];
            h+='<tr><td>'+x.d+'</td><td>'+esc(l?l.lane_name:'')+'</td>'
              +'<td>'+esc(x.c.name)+(x.c.left?' <span class="label label-default">離職</span>':'')+(x.c.adjusted?' <span class="label label-warning">調</span>':'')+'</td>'
              +'<td>'+(x.c.sign?'<span style="color:#C0762C"><span class="stamp" style="color:#c0392b;border-color:#c0392b">簽</span> 已簽 '+(x.c.signed_at||'')+'</span>':'<span style="color:#a08c72">未簽</span>')+'</td>'
              +'<td>'+actionBtns(x.c,x.d)+'</td></tr>';
        });
        h+='</table>';
        $('#listView').html(h);
    }
    function actionBtns(c,d){
        if(c.pending){
            var pb='<span class="label" style="background:#b5651d">申請調班中</span>';
            if(c.mine || calData.board.can_edit) pb+=' <button class="btn btn-xs btn-default" onclick="R.cancelSwap('+c.pending+')">取消申請</button>';
            return pb;
        }
        var b='';
        if(calData.board.sign_required && c.can_sign){   // 只能簽自己負責的
            b+= c.sign? '<button class="btn btn-xs btn-default" onclick="R.sign('+c.aid+',0)">取消簽核</button>'
                      : '<button class="btn btn-xs btn-warning" onclick="R.sign('+c.aid+',1)">簽核</button> ';
        }
        // 只能對調自己的班；管理員/建立者也可對任何格發起（免對方同意）
        if(c.mine || calData.board.swap_bypass) b+=' <button class="btn btn-xs btn-default" onclick="R.openSwap('+c.aid+')">對調</button>';
        return b||'—';
    }

    /* ── 當日 modal ── */
    function openDay(ds){
        if(!calData) return;
        var cells=calData.cells[ds]||[], lm=laneMap();
        $('#dayTitle').text(ds+' 排班');
        if(!cells.length){ $('#dayBody').html('<div style="color:#a08c72">當天無排班</div>'); }
        else{
            var h='<table class="rst-list" style="width:100%"><tr><th>輪值項目</th><th>負責人</th><th>狀態</th><th>操作</th></tr>';
            cells.forEach(function(c){ var l=lm[c.lane_id];
                h+='<tr'+(c.mine?' style="background:#fbf3e6"':'')+'><td>'+esc(l?l.lane_name:'')+'</td><td>'+esc(c.name)+(c.mine?' <span class="label label-warning">我</span>':'')+(c.left?'（離職）':'')+'</td>'
                  +'<td>'+(c.sign?'<span style="color:#C0762C">已簽 '+(c.signed_at||'')+'</span>':'未簽')+'</td><td>'+actionBtns(c,ds)+'</td></tr>';
            });
            h+='</table>'; $('#dayBody').html(h);
        }
        $('#dayModal').modal('show');
    }
    function sign(aid,to){
        post(to?'sign':'unsign',{aid:aid}).done(function(r){ if(!r.success){alert(r.message);return;} loadCalendar(); refreshDay(); });
    }
    function refreshDay(){ if($('#dayModal').is(':visible')){ var t=$('#dayTitle').text().slice(0,10); setTimeout(function(){openDay(t);},200);} }
    function findCell(aid){ var f=null; Object.keys(calData.cells).forEach(function(d){ calData.cells[d].forEach(function(c){ if(c.aid===aid){f=c;f.__d=d;} }); }); return f; }
    function laneName(id){ var l=laneMap()[id]; return l?l.lane_name:''; }
    // 找出「這一格所屬的換手單位（連續同一人的整段）」的起訖
    function blockRange(laneId, userId, date){
        var arr=[];
        Object.keys(calData.cells).forEach(function(d){ (calData.cells[d]||[]).forEach(function(c){ if(c.lane_id===laneId) arr.push({d:d,u:c.user_id}); }); });
        arr.sort(function(a,b){return a.d<b.d?-1:1;});
        var idx=-1; for(var k=0;k<arr.length;k++){ if(arr[k].d===date){ idx=k; break; } }
        if(idx<0) return {from:date,to:date};
        var i=idx,j=idx;
        while(i>0 && arr[i-1].u===userId) i--;
        while(j<arr.length-1 && arr[j+1].u===userId) j++;
        return {from:arr[i].d, to:arr[j].d};
    }
    function openSwap(aid){
        var c=findCell(aid); if(!c) return;
        var mem=(calData.lane_members&&calData.lane_members[c.lane_id])||[];
        var others=mem.filter(function(m){return m.id!==c.user_id;});
        if(!others.length){ alert('此輪值項目沒有其他同組人員可對調'); return; }
        var blk=blockRange(c.lane_id, c.user_id, c.__d);   // 該換手單位整段（如整週）
        window.__sw={aid:aid, lane:c.lane_id, myUser:c.user_id};
        var opts=others.map(function(m){return '<option value="'+m.id+'">'+esc(m.name)+'</option>';}).join('');
        var bypass=calData.board.swap_bypass;
        var html='<div style="margin-bottom:6px">'+(c.mine?'你':esc(c.name))+'的班：<b>'+c.__d+'</b>（'+esc(laneName(c.lane_id))+'）</div>'
            +'<label>跟哪位同組人員對調</label><select id="swU" class="form-control" onchange="R.swDays()">'+opts+'</select>'
            +'<div style="margin-top:8px"><label style="font-weight:400"><input type="radio" name="swScope" value="single" checked onchange="R.swScope()"> 只換這一天，選對方的某一天對調</label></div>'
            +'<div><label style="font-weight:400"><input type="radio" name="swScope" value="range" onchange="R.swScope()"> 整段對調（換手單位 '+blk.from+' ~ '+blk.to+' 整段互換）</label></div>'
            +'<div id="swSingle" style="margin-top:4px"><label>對方的哪一天</label><select id="swDay" class="form-control"></select></div>'
            +'<div id="swRange" style="display:none;margin-top:4px"><div class="row"><div class="col-xs-6"><label>起</label><input type="date" id="swFrom" class="form-control" value="'+blk.from+'"></div><div class="col-xs-6"><label>迄</label><input type="date" id="swTo" class="form-control" value="'+blk.to+'"></div></div></div>'
            +'<input id="swNote" class="form-control" placeholder="備註（選填）" style="margin-top:8px">'
            +(bypass?'<div style="color:#8a7a63;font-size:12px;margin-top:6px"><i class="fa fa-info-circle"></i> 你是管理員/建立者，將直接對調，免對方同意。</div>':'<div style="color:#8a7a63;font-size:12px;margin-top:6px"><i class="fa fa-info-circle"></i> 送出後需對方按「同意」才會生效。</div>');
        showPrompt('申請對調', html, submitSwap);
        swDays();
    }
    function swScope(){ var r=$('[name=swScope]:checked').val()==='range'; $('#swRange').toggle(r); $('#swSingle').toggle(!r); }
    function swDays(){
        var u=+$('#swU').val(), lane=window.__sw.lane, opts='';
        Object.keys(calData.cells).sort().forEach(function(d){
            if(d<calData.today) return;
            calData.cells[d].forEach(function(cc){ if(cc.lane_id===lane && cc.user_id===u && !cc.pending) opts+='<option value="'+cc.aid+'">'+d+'</option>'; });
        });
        $('#swDay').html(opts||'<option value="">（此人目前月份無可對調的班，改用整段對調）</option>');
    }
    function submitSwap(){
        var scope=$('[name=swScope]:checked').val(), note=$('#swNote').val(), u=+$('#swU').val(), sw=window.__sw;
        if(scope==='single'){
            var toAid=+$('#swDay').val(); if(!toAid){ alert('請選對方的一天，或改用整段對調'); return; }
            post('request_swap',{from_aid:sw.aid,to_aid:toAid,note:note}).done(afterSwap);
        } else {
            var f=$('#swFrom').val(), t=$('#swTo').val(); if(!f||!t){ alert('請選起訖日'); return; }
            post('request_swap_range',{board_id:curBoard.id,lane_id:sw.lane,date_from:f,date_to:t,counterpart_id:u,from_user_id:sw.myUser,note:note}).done(afterSwap);
        }
    }
    function afterSwap(r){ if(!r.success){ alert(r.message); return; } $('#promptModal').modal('hide');
        alert(r.mode==='done'?('已對調'+(r.affected?('（'+r.affected+' 筆）'):'')):'已送出對調申請，待對方同意'); loadCalendar(); refreshDay(); loadMySwaps(); }
    function cancelSwap(id){ post('cancel_swap',{req_id:id}).done(function(r){ if(!r.success){alert(r.message);return;} loadCalendar(); refreshDay(); loadMySwaps(); }); }
    function respondSwap(id,agree){ post('respond_swap',{req_id:id,decision:agree?'agree':'reject'}).done(function(r){ if(!r.success){alert(r.message);return;} loadMySwaps(); if(curBoard) loadCalendar(); }); }
    function loadMySwaps(){
        post('list_my_swaps',{}).done(function(r){ if(!r.success) return;
            var inbox=r.inbox||[], sent=r.sent||[];
            if(!inbox.length && !sent.length){ $('#swapBar').hide(); return; }
            var h='';
            inbox.forEach(function(s){ h+='<div class="swap-item"><i class="fa fa-inbox"></i> <b>'+esc(s.requester)+'</b> 想跟你對調 '+(s.scope==='range'?(s.date_from+'~'+s.date_to):s.date_from)+'（'+esc(s.board_name)+(s.lane_name?' / '+esc(s.lane_name):'')+'）'+(s.note?'　備註：'+esc(s.note):'')+' <button class="btn btn-xs btn-warning" onclick="R.respondSwap('+s.id+',1)">同意</button> <button class="btn btn-xs btn-default" onclick="R.respondSwap('+s.id+',0)">不同意</button></div>'; });
            sent.forEach(function(s){ h+='<div class="swap-item" style="color:#8a7a63"><i class="fa fa-paper-plane-o"></i> 你申請跟 '+esc(s.counterpart)+' 對調 '+(s.scope==='range'?(s.date_from+'~'+s.date_to):s.date_from)+'（等待同意）<button class="btn btn-xs btn-default" onclick="R.cancelSwap('+s.id+')">取消</button></div>'; });
            $('#swapInboxBox').html(h); $('#swapBar').show();
        });
    }

    /* ── 區間對調（建立者/管理員：兩人在區間內互換，免同意）── */
    function openRange(){
        if(!curBoard) return;
        var lo=''; (calData.lanes||[]).forEach(function(l){ lo+='<option value="'+l.id+'">'+esc(l.lane_name)+'</option>'; });
        $('#rg_lane').html(lo);
        $('#rg_from').val(calData.today); $('#rg_to').val(calData.today); $('#rg_note').val('');
        rgLaneChange();
        $('#rangeModal').modal('show');
    }
    function rgLaneChange(){
        var lid=+$('#rg_lane').val()||0;
        var list=(calData.lane_members&&calData.lane_members[lid])||[];
        var opt=list.map(function(m){return '<option value="'+m.id+'">'+esc(m.name)+'</option>';}).join('');
        $('#rg_from_user,#rg_to_user').html(opt||'<option value="">（此項目尚未設定人員）</option>');
    }
    function submitRange(){
        var a=$('#rg_from_user').val(), b=$('#rg_to_user').val();
        if(a===b){ alert('甲乙不能是同一人'); return; }
        post('request_swap_range',{board_id:curBoard.id,lane_id:$('#rg_lane').val(),date_from:$('#rg_from').val(),date_to:$('#rg_to').val(),
            from_user_id:a,counterpart_id:b,note:$('#rg_note').val()}).done(function(r){
            if(!r.success){alert(r.message);return;} $('#rangeModal').modal('hide');
            alert(r.mode==='done'?('已對調 '+(r.affected||0)+' 筆'):'已送出對調申請'); loadCalendar();
        });
    }
    function posText(u){ var p=[u.position_name,u.department_name].filter(Boolean).join('・'); return p?'（'+p+'）':''; }
    function posLabel(u){ var t=posText(u); return t?' <span class="pos">'+esc(t)+'</span>':''; }
    function userOptions(mode){ // mode -1: 不含空; 0: 一般
        var o=''; RD.users.forEach(function(u){ o+='<option value="'+u.id+'">'+esc(u.user_cname+posText(u))+'</option>'; }); return o;
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
        else { $('#edTitle').text('新增排班表'); $('#ed_start').val(calData?calData.today:new Date().toISOString().slice(0,10)); addLane(); onModeChange(); onCadenceChange(); onRotateChange(); renderVis([]); $('#editorModal').modal('show'); }
    }
    function openEditorCurrent(){ if(curBoard) openEditor(curBoard.id); }
    function resetEditor(){ $('#ed_id,#ed_name,#ed_purpose').val(''); $('#lanesBox').empty(); $('#ed_member_mode').val('per_lane'); $('#ed_cadence').val('daily'); $('#ed_rotate').val('each'); $('#ed_rotate_n').val(1); $('#ed_sign').prop('checked',true); }
    function fillEditor(r){
        var b=r.board;
        var rot=b.rotate_unit; if(rot==='weekly')rot='week'; if(rot==='monthly')rot='month'; // 舊值相容
        $('#edTitle').text('編輯：'+b.name);
        $('#ed_id').val(b.id); $('#ed_name').val(b.name); $('#ed_purpose').val(b.purpose); $('#ed_start').val(b.start_date);
        $('#ed_member_mode').val(b.member_mode); $('#ed_cadence').val(b.exec_cadence);
        $('#ed_rotate').val(rot); $('#ed_rotate_n').val(b.rotate_n||1); $('#ed_sign').prop('checked',b.sign_required==1);
        $('#lanesBox').empty();
        (r.lanes||[]).forEach(function(l){ addLane(l); });
        onModeChange(); onCadenceChange(b); onRotateChange();
        if(b.member_mode==='shared_pool') buildPicker($('#sharedPicker'), r.shared_members);
        renderVis(r.visibility||[]);
    }
    function onRotateChange(){
        var v=$('#ed_rotate').val(), showN=(v!=='each')&&RD.isAdmin;
        $('#rotEvery').toggle(showN); $('#ed_rotate_n').toggle(showN);
        var n=RD.isAdmin?($('#ed_rotate_n').val()||1):1;
        var unit={week:'週',month:'月',day:'個工作天'}[v]||'';
        $('#rotHint').text(v==='each'?'每個排班日就輪下一人。':('同一人連續 '+n+' '+unit+' 後換下一人。'));
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

    // 輪值項目
    function addLane(l){
        l=l||{}; var i=$('#lanesBox .lane-row').length;
        var color=l.color||RD.palette[i%RD.palette.length];
        var sw=RD.palette.map(function(c){ return '<span class="swatch'+(c===color?' on':'')+'" style="background:'+c+'" data-c="'+c+'" onclick="R.pickColor(this)"></span>'; }).join('');
        var shiftOpt='<option value="">（不綁班別）</option>'+RD.shifts.map(function(s){ return '<option value="'+s.shift_type_id+'"'+(l.shift_type_id==s.shift_type_id?' selected':'')+'>'+esc(s.shift_name)+'</option>'; }).join('');
        var $row=$('<div class="lane-row">'
            +'<div style="flex:1">'
              +'<input type="hidden" class="lane-id" value="'+(l.id||'')+'">'
              +'<div class="row"><div class="col-sm-5"><input type="text" class="form-control input-sm lane-name" placeholder="輪值項目名稱(男廁/早班…)" value="'+esc(l.lane_name||'')+'"></div>'
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

    // 人員 picker widget（可多選加入、拖拉排序、三欄、顯示職稱）
    function buildPicker($box, ids){
        $box.data('built',true);
        $box.html('<button type="button" class="btn btn-xs btn-warning pk-addbtn"><i class="fa fa-user-plus"></i> 加入人員（可多選）</button><div class="pk-grid"></div>');
        $box.find('.pk-addbtn').on('click',function(){ openAddMembers($box); });
        var grid=$box.find('.pk-grid')[0];
        if(window.Sortable) Sortable.create(grid,{animation:150,ghostClass:'sortable-ghost',onEnd:function(){renumber($box);}});
        (ids||[]).forEach(function(id){ addToPicker($box,id); });
        renumber($box);
    }
    function openAddMembers($box){
        var have={}; pickerIds($box).forEach(function(id){have[id]=1;});
        var list=RD.users.filter(function(u){return !have[u.id];});
        if(!list.length){ alert('沒有可加入的人員（都已加入或無在職人員）'); return; }
        var body='<input class="form-control input-sm" id="msSearch" placeholder="搜尋姓名 / 部門 / 職稱" oninput="R.msFilter()" style="margin-bottom:6px">'
            +'<div style="margin-bottom:4px"><a href="#" onclick="R.msAll(true);return false;">全選</a> ｜ <a href="#" onclick="R.msAll(false);return false;">全不選</a></div>'
            +'<div style="max-height:320px;overflow:auto;border:1px solid #e4d8c6;border-radius:4px;padding:4px" id="msList">'
            + list.map(function(u){ return '<label class="msitem" style="display:block;font-weight:normal;margin:1px 0"><input type="checkbox" value="'+u.id+'"> '+esc(u.user_cname)+posLabel(u)+'</label>'; }).join('')
            +'</div>';
        showPrompt('加入人員（可多選）', body, function(){
            $('#msList input:checked').each(function(){ addToPicker($box, this.value); });
            renumber($box); $('#promptModal').modal('hide');
        });
    }
    function msFilter(){ var q=$('#msSearch').val().toLowerCase(); $('#msList .msitem').each(function(){ $(this).toggle($(this).text().toLowerCase().indexOf(q)>=0); }); }
    function msAll(v){ $('#msList .msitem:visible input').prop('checked',v); }
    function addToPicker($box,id){
        id=String(id); if($box.find('.pk-item[data-id="'+id+'"]').length) return;
        var u=RD.users.find(function(x){return String(x.id)===id;});
        var nm=u?u.user_cname:('#'+id);
        var $it=$('<div class="pk-item" data-id="'+id+'"><span class="idx"></span><span class="nm">'+esc(nm)+posLabel(u||{})+'</span><a href="#" class="pk-rm" title="移除">✕</a></div>');
        $box.find('.pk-grid').append($it); renumber($box);
        $it.find('.pk-rm').on('click',function(e){e.preventDefault();$it.remove();renumber($box);});
    }
    function renumber($box){
        var $g=$box.find('.pk-grid'); $g.find('.pk-empty').remove();
        var items=$g.find('.pk-item');
        items.each(function(i){ $(this).find('.idx').text((i+1)+'.'); });
        if(!items.length) $g.append('<div class="pk-empty">尚未加入人員，點上方按鈕加入；可拖拉調整順序</div>');
    }
    function pickerIds($box){ return $box.find('.pk-item').map(function(){return +$(this).attr('data-id');}).get(); }

    // 公開對象
    function renderVis(selected){
        var set={}; (selected||[]).forEach(function(v){set[v]=1;});
        var h='<label><input type="checkbox" class="vischk" value="all"'+(set['all']?' checked':'')+' onchange="R.onAllVis(this)"> <b>全體（所有人可見）</b></label>';
        <?php if ($IS_ADMIN): ?>
        h+='<div class="vis-grp">測試（僅管理員可見）</div><div class="vis-items">'
          +'<label class="visitem"><input type="checkbox" class="vischk" value="user-1"'+(set['user-1']?' checked':'')+'> 超級管理員（此選項僅供測試使用）</label></div>';
        <?php endif; ?>
        h+='<div class="vis-grp">部門</div><div class="vis-items">';
        <?php foreach ($pickers['departments'] as $d): ?>
        h+='<label class="visitem"><input type="checkbox" class="vischk" value="dept-<?= $d['id'] ?>"'+(set['dept-<?= $d['id'] ?>']?' checked':'')+'> <?= htmlspecialchars(addslashes($d['name'])) ?></label>';
        <?php endforeach; ?>
        h+='</div><div class="vis-grp">身分別</div><div class="vis-items">';
        <?php foreach ($pickers['statuses'] as $s): ?>
        h+='<label class="visitem"><input type="checkbox" class="vischk" value="status-<?= $s['id'] ?>"'+(set['status-<?= $s['id'] ?>']?' checked':'')+'> <?= htmlspecialchars(addslashes($s['title'])) ?></label>';
        <?php endforeach; ?>
        h+='</div><div class="vis-grp">人員</div><div class="vis-items">';
        <?php foreach ($pickers['users'] as $u): ?>
        h+='<label class="visitem"><input type="checkbox" class="vischk" value="user-<?= $u['id'] ?>"'+(set['user-<?= $u['id'] ?>']?' checked':'')+'> <?= htmlspecialchars(addslashes($u['user_cname'])) ?></label>';
        <?php endforeach; ?>
        h+='</div>';
        $('#visBox').html(h); onAllVisState();
    }
    function onAllVis(el){ onAllVisState(); }
    function onAllVisState(){ var all=$('.vischk[value=all]').is(':checked'); $('.visitem input').prop('disabled',all); }
    function filterVis(){ var q=$('#visSearch').val().toLowerCase(); $('#visBox .visitem').each(function(){ $(this).toggle($(this).text().toLowerCase().indexOf(q)>=0); }); }
    function selectAllRostered(){
        var s={};
        if($('#ed_member_mode').val()==='shared_pool'){ pickerIds($('#sharedPicker')).forEach(function(id){s[id]=1;}); }
        else { $('#lanesBox .lane-picker').each(function(){ pickerIds($(this)).forEach(function(id){s[id]=1;}); }); }
        var ids=Object.keys(s);
        if(!ids.length){ alert('目前尚未加入任何排班人員'); return; }
        if($('.vischk[value=all]').is(':checked')){ alert('已勾「全體」，請先取消才能指定個人'); return; }
        ids.forEach(function(id){ $('.vischk[value="user-'+id+'"]').prop('checked',true); });
    }

    function collectVis(){ var seen={},out=[]; $('.vischk:checked').each(function(){ var v=$(this).val(); if(!seen[v]){seen[v]=1;out.push(v);} }); return out; }

    function saveBoard(){
        var mode=$('#ed_member_mode').val();
        var lanes=[];
        $('#lanesBox .lane-row').each(function(){
            var $r=$(this);
            lanes.push({ id:$r.find('.lane-id').val()||0, lane_name:$r.find('.lane-name').val(), color:$r.find('.lane-color').attr('data-color'),
                shift_type_id:$r.find('.lane-shift').val(), members: mode==='per_lane'?pickerIds($r.find('.lane-picker')):[] });
        });
        if(!lanes.length){ alert('至少要有一個輪值項目'); return; }
        var cadence=$('#ed_cadence').val(), payload={
            id:+($('#ed_id').val()||0), name:$('#ed_name').val(), purpose:$('#ed_purpose').val(),
            member_mode:mode, rotate_unit:$('#ed_rotate').val(), rotate_n:(RD.isAdmin?(+$('#ed_rotate_n').val()||1):1), exec_cadence:cadence,
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
            curYm = curYm || ym(new Date());
            var savedId = r.id;
            post('list_boards',{scope:scope}).done(function(rr){
                if(!rr.success) return;
                boards = rr.boards || []; renderBoards();
                var b = boards.find(function(x){return x.id===savedId;});
                if(b) selectBoard(savedId);   // 重新載入最新排班到月曆
                else if(curBoard) loadCalendar();
            });
        });
    }

    function deleteBoard(id,name){
        var t=prompt('刪除「'+name+'」會一併刪除此表所有排班與紀錄，無法復原。\n\n請輸入大寫「Y」確認刪除：');
        if(t===null) return;
        if(t!=='Y'){ alert('未輸入大寫 Y，已取消刪除'); return; }
        post('delete_board',{id:id}).done(function(r){ if(!r.success){alert(r.message);return;}
            if(curBoard&&curBoard.id===id){ curBoard=null; $('#calArea').hide(); $('#calEmpty').show(); }
            loadBoards();
        });
    }

    function moveMonth(n){ var d=new Date(curYm+'-01'); d.setMonth(d.getMonth()+n); curYm=ym(d); loadCalendar(); }
    function goToday(){ curYm=ym(new Date()); loadCalendar(); }
    function setView(v){ view=v; $('#viewCal').toggleClass('btn-warning',v==='cal').toggleClass('btn-default',v!=='cal');
        $('#viewList').toggleClass('btn-warning',v==='list').toggleClass('btn-default',v!=='list'); if(calData){ v==='cal'?renderCalendar():renderList(); } }

    /* ── 紀錄 ── */
    function openLogs(){ $('#logModal').modal('show'); $('.rst-logtabs div').removeClass('active').filter('[data-tab=board]').addClass('active'); $('#logBoard').show(); $('#logAdjust').hide(); loadBoardLog(); loadAdjustLog(); }
    function logTab(el){ $('.rst-logtabs div').removeClass('active'); $(el).addClass('active'); var t=$(el).data('tab'); $('#logBoard').toggle(t==='board'); $('#logAdjust').toggle(t==='adjust'); }
    function loadBoardLog(){
        post('list_board_log',{}).done(function(r){ if(!r.success)return;
            var rows=r.rows||[]; if(!rows.length){ $('#logBoard').html('<div style="color:#a08c72;padding:8px">尚無建立/刪除紀錄</div>'); return; }
            var h='<table class="rst-list"><tr><th>時間</th><th>動作</th><th>排班表</th><th>操作人</th></tr>';
            rows.forEach(function(x){ h+='<tr><td>'+x.created_at.substring(0,16)+'</td><td>'+(x.action==='create'?'<span class="label label-success">建立</span>':'<span class="label label-danger">刪除</span>')+'</td><td>'+esc(x.board_name)+'</td><td>'+esc(x.operator_name)+'</td></tr>'; });
            $('#logBoard').html(h+'</table>');
        });
    }
    function loadAdjustLog(){
        if(!curBoard){ $('#logAdjust').html('<div style="color:#a08c72;padding:8px">請先在左側選一張表，再看它的調班紀錄</div>'); return; }
        post('list_adjust_log',{id:curBoard.id}).done(function(r){ if(!r.success)return;
            var rows=r.rows||[]; if(!rows.length){ $('#logAdjust').html('<div style="color:#a08c72;padding:8px">此表尚無調班紀錄</div>'); return; }
            var h='<table class="rst-list"><tr><th>時間</th><th>類型</th><th>輪值項目</th><th>期間</th><th>對調</th><th>操作人</th><th>備註</th></tr>';
            var typ={swap:'對調',swap_range:'區間對調',single:'調班',range:'區間調班'};
            rows.forEach(function(x){ h+='<tr><td>'+x.created_at.substring(0,16)+'</td><td>'+(typ[x.scope]||x.scope)+'</td><td>'+esc(x.lane_name||'')+'</td><td>'+x.date_from+(x.date_to&&x.date_to!==x.date_from?'~'+x.date_to:'')+'</td><td>'+esc(x.from_name)+' ⇄ '+esc(x.to_name)+'</td><td>'+esc(x.op_name)+'</td><td>'+esc(x.note||'')+'</td></tr>'; });
            $('#logAdjust').html(h+'</table>');
        });
    }

    $(function(){
        $('#btn-perm-help').on('click',function(e){e.preventDefault();$('#permHelp').modal('show');});
        <?php if ($hasAccess): ?>loadBoards(); loadMySwaps();<?php endif; ?>
    });

    return { loadBoards:loadBoards, switchScope:switchScope, selectBoard:selectBoard, loadCalendar:loadCalendar,
        openDay:openDay, sign:sign, openSwap:openSwap, swScope:swScope, swDays:swDays, cancelSwap:cancelSwap, respondSwap:respondSwap,
        openRange:openRange, rgLaneChange:rgLaneChange, submitRange:submitRange, openEditor:openEditor, openEditorCurrent:openEditorCurrent, saveBoard:saveBoard, deleteBoard:deleteBoard,
        addLane:addLane, delLane:delLane, pickColor:pickColor, onModeChange:onModeChange, onCadenceChange:onCadenceChange, onRotateChange:onRotateChange,
        toggleWk:toggleWk, toggleMo:toggleMo, onAllVis:onAllVis, filterVis:filterVis, moveMonth:moveMonth, goToday:goToday, setView:setView,
        msFilter:msFilter, msAll:msAll, selectAllRostered:selectAllRostered, openLogs:openLogs, logTab:logTab };
})();
</script>
</body>
</html>
