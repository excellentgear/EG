<?php
/**
 * qa_abnormal_list.php — 品質異常處理單 清單（2-QA-01-01）
 * 建立：2026-09-18
 *
 * 這一頁是異常單的入口：查詢／開新單（客退 IR 來源、製程 BOM 來源）／進入處理頁／列印／管理員設定。
 * 單張的填寫與決策都在 qa_abnormal_form.php；不合格品管制記錄表(2-QA-01-03) 只唯讀顯示本模組的狀態。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/QA/qa_abnormal_list.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/qa_abnormal_lib.php';
include_once '../../src/common/asdoc_lib.php';

$db = (new DBConnection())->getPDO();
qab_ensure_schema($db);
$uid   = (int)($_SESSION['id'] ?? 0);
$perms = qab_perms($db, $uid);
if (empty($_SESSION['qab_csrf'])) $_SESSION['qab_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['qab_csrf'];
$asDoc = eg_asdoc_get($db, QAB_ASDOC_MODULE);
$asNo  = $asDoc ? eg_asdoc_no($asDoc) : '2-QA-01-01';
$roleLabel = $perms['isAdmin'] ? '系統管理者' : ($perms['canAdmin'] ? '異常單管理員'
            : ($perms['canGm'] ? '最終決策者' : ($perms['canDecide'] ? '決策主管'
            : ($perms['canCreate'] ? '開單／填寫' : ($perms['canView'] ? '檢閱' : '無權限')))));
$thisYear = (int)date('Y');
$years = qab_years($db);               // 年度下拉只列真的有資料的年度
if (!in_array($thisYear, $years, true)) array_unshift($years, $thisYear);
$backfillDays = qab_backfill_days($db);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>品質異常處理單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        :root{ --ink:#4A3524; --ink2:#6B4423; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B;
               --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; }
        body { background:#F6F1EA; }
        .right_col .page-title { margin:8px 0 6px; overflow:hidden; clear:both; }
        .page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:20px; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                         border-radius:15px; background:#fff; color:var(--amber-d); }
        .page-help-btn:hover { background:var(--amber-d); color:#fff; }
        @media print { .page-help-btn, .toolbar { display:none !important; } }
        .as-tag,.role-tag { font-size:12px; background:var(--sand); color:var(--ink2); border-radius:10px; padding:2px 10px; font-weight:normal; }
        .role-tag { background:#EFE3CF; }
        .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
        .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
        .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
        .btn-warm-o:hover { background:var(--sand); color:var(--ink); }
        .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:10px 12px; margin-bottom:10px; }
        .toolbar { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
        .fg label { display:block; font-size:12px; color:#8a7560; margin-bottom:2px; }
        .fg input, .fg select { border:1px solid var(--line); border-radius:4px; padding:3px 6px; font-size:13px; height:30px; }
        .muted-help { font-size:12px; color:#8a7560; }
        .note-box { font-size:12px; color:var(--ink2); background:var(--cream); border:1px solid var(--line);
                    border-radius:6px; padding:7px 10px; margin-bottom:8px; line-height:1.7; }
        table.lst { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff; table-layout:fixed; }
        table.lst th, table.lst td { border:1px solid var(--line); padding:4px 6px; vertical-align:top;
                                     overflow-wrap:break-word; word-break:break-word; }
        table.lst thead th { background:var(--sand); color:var(--ink2); text-align:center; position:sticky; top:0; z-index:2; }
        table.lst td.c { text-align:center; }
        table.lst tbody tr:hover { background:#FFFDF8; }
        .lst-wrap { max-height:64vh; overflow:auto; border:1px solid var(--line); border-radius:6px; }
        .st { border-radius:11px; padding:1px 9px; font-size:11.5px; display:inline-block; line-height:18px; white-space:nowrap; }
        .st-closed { background:#EFE3CF; color:var(--ink2); }
        .st-reply  { background:var(--sand); color:var(--ink2); }
        .st-decide { background:var(--amber); color:#3b2a18; }
        .st-gm     { background:var(--coral); color:#fff; }
        .st-deduct { background:#F0A24B; color:#3b2a18; }
        .st-ready  { background:#DDEBD6; color:#2c5c2c; }
        .src { font-size:10.5px; border-radius:8px; padding:0 6px; line-height:17px; display:inline-block; }
        .src-IR  { background:var(--coral); color:#fff; }
        .src-BOM { background:var(--sand); color:var(--ink2); }
        .src-QC  { background:#EFE3CF; color:var(--ink2); }
        .m-mask { position:fixed; inset:0; background:rgba(74,53,36,.45); z-index:10300; display:none; }
        .m-box { position:absolute; left:50%; top:5vh; transform:translateX(-50%); background:#fff; border-radius:8px;
                 box-shadow:0 10px 30px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:90vh; }
        .m-hd { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:bold; color:var(--ink2);
                display:flex; align-items:center; gap:10px; }
        .m-hd .x { margin-left:auto; cursor:pointer; color:#8a7560; }
        .m-bd { padding:12px 14px; overflow:auto; }
        .m-ft { padding:9px 14px; border-top:1px solid var(--line); text-align:right; }
        .fld label { display:block; font-size:12px; color:#8a7560; margin:0 0 2px; font-weight:normal; }
        .fld input, .fld select { width:100%; border:1px solid var(--line); border-radius:4px; padding:3px 6px; font-size:13px; }
        .fgrid { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:8px 12px; }
        .ac-list { position:fixed; z-index:10400; background:#fff; border:1px solid var(--line); border-radius:4px;
                   box-shadow:0 4px 14px rgba(120,90,50,.22); max-height:240px; overflow:auto; display:none; min-width:260px; }
        .ac-list div { padding:5px 10px; font-size:13px; cursor:pointer; border-bottom:1px solid #F3EADC; }
        .ac-list div:hover { background:var(--cream); }
        .ac-list .hit { color:var(--amber-d); font-weight:bold; }
        .tabs { display:flex; gap:6px; border-bottom:2px solid var(--line); margin-bottom:10px; flex-wrap:wrap; }
        .tabs button { border:1px solid var(--line); border-bottom:0; background:#F3EADC; color:var(--ink2);
                       border-radius:6px 6px 0 0; padding:5px 14px; font-size:13px; }
        .tabs button.on { background:#fff; color:var(--amber-d); font-weight:bold; }
        table.cfg { width:100%; border-collapse:collapse; font-size:12.5px; }
.cfg td.drag{cursor:grab;color:#c8a882;text-align:center;font-size:14px;user-select:none;}
.cfg tr.dragging{opacity:.45;}
.cfg tr.drop-before td{box-shadow:inset 0 2px 0 var(--amber-d);}
.cfg tr.drop-after td{box-shadow:inset 0 -2px 0 var(--amber-d);}
#cfgSaved{color:#7a8f5a;font-size:12px;margin-right:auto;}
.ask-pos{display:flex;flex-wrap:wrap;gap:4px 10px;}
.ask-pos label{font-weight:normal;margin:0;font-size:12px;}
        table.cfg th, table.cfg td { border:1px solid var(--line); padding:3px 6px; }
        table.cfg th { background:var(--cream); color:var(--ink2); font-weight:normal; text-align:center; }
        table.cfg input[type=text], table.cfg input[type=number], table.cfg select { width:100%; border:1px solid var(--line);
                       border-radius:3px; padding:1px 4px; font-size:12.5px; }
        table.cfg td.c { text-align:center; }
        .lv-in { padding-left:0; } .lv-in2 { padding-left:22px; } .lv-in3 { padding-left:44px; }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .err { color:var(--coral); font-size:12px; margin-top:2px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">

        <div class="page-title">
            <h3><i class="fa fa-exclamation-triangle" style="color:var(--coral);"></i> 品質異常處理單
                <span class="as-tag"><?= htmlspecialchars($asNo) ?></span>
                <span class="role-tag">目前身分：<?= htmlspecialchars($roleLabel) ?></span>
                <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
            </h3>
        </div>

        <?php if (!$perms['canView']): ?>
        <div class="warm-panel">您沒有品質異常處理單的檢視權限，請洽系統管理員指派角色。</div>
        <?php else: ?>

        <div class="warm-panel">
            <div class="toolbar">
                <div class="fg"><label>年度</label>
                    <select id="fYear">
                        <option value="">全部</option>
                        <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $thisYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="fg"><label>月份</label>
                    <select id="fMonth"><option value="">全部</option>
                        <?php for ($m = 1; $m <= 12; $m++) echo '<option value="' . $m . '">' . $m . '月</option>'; ?>
                    </select></div>
                <div class="fg"><label>狀態</label>
                    <select id="fClosed"><option value="">全部</option><option value="0">未結案</option><option value="1">已結案</option></select></div>
                <div class="fg"><label>來源</label>
                    <select id="fSource"><option value="">全部</option>
                        <option value="IR">客退 (IR)</option><option value="BOM">製程 (製令)</option><option value="QC">檢驗單</option></select></div>
                <div class="fg" style="flex:1;min-width:200px;"><label>關鍵字（單號／製令／客退單／料號／客戶／現象／責任單位／報廢單號）</label>
                    <input type="text" id="fKw" style="width:100%;"></div>
                <button class="btn btn-warm btn-sm" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
                <?php if ($perms['canCreate']): ?>
                <button class="btn btn-warm btn-sm" id="btnNew"><i class="fa fa-plus"></i> 開立異常單</button>
                <?php endif; ?>
                <?php if ($perms['canAdmin']): ?>
                <label class="fg" style="flex-direction:row;align-items:center;gap:4px;margin-bottom:0;">
                    <input type="checkbox" id="fDeleted"> 顯示已刪除</label>
                <button class="btn btn-warm-o btn-sm" id="btnCfg"><i class="fa fa-cog"></i> 設定</button>
                <?php endif; ?>
            </div>
        </div>

        <div id="sumBox" class="note-box"></div>
        <div class="lst-wrap">
            <table class="lst">
                <colgroup>
                    <col style="width:108px"><col style="width:76px"><col style="width:96px"><col style="width:120px">
                    <col style="width:118px"><col><col style="width:130px"><col style="width:120px">
                    <col style="width:104px"><col style="width:96px">
                </colgroup>
                <thead><tr>
                    <th>異常單號</th><th>日期</th><th>客戶</th><th>料號</th>
                    <th>製令／客退單</th><th>異常現象</th><th>責任單位</th><th>最終處置</th>
                    <th>狀態</th><th>操作</th>
                </tr></thead>
                <tbody id="lstBody"><tr><td colspan="10" class="c">載入中…</td></tr></tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 開新單 -->
<div class="m-mask" id="newMask">
    <div class="m-box" style="width:640px;">
        <div class="m-hd"><i class="fa fa-plus"></i> 開立品質異常處理單<span class="x" data-close="newMask">&times;</span></div>
        <div class="m-bd">
            <div class="note-box">兩種來源：<b>客退</b>＝由退貨單(IR)開立，可另外綁製令也可以不綁；<b>製程中</b>＝直接綁製令編號開立。<br>
                有綁製令時，扣款區才能自動帶入該製令的製程移轉金額。</div>
            <div style="display:flex;gap:16px;margin-bottom:10px;">
                <label style="font-weight:normal;"><input type="radio" name="nkind" value="bom" checked> 製程中（綁製令）</label>
                <label style="font-weight:normal;"><input type="radio" name="nkind" value="ir"> 客退（IR 單）</label>
            </div>
            <div class="fgrid">
                <div class="fld"><label>填寫日期</label><input type="date" id="n_date"></div>
                <div class="fld" id="nIrBox" style="display:none;"><label>客退單號 (IR) <span style="color:var(--coral)">*</span></label>
                    <input type="text" id="n_ir" autocomplete="off" placeholder="輸入單號／客戶／料號搜尋">
                    <input type="hidden" id="n_ir_id"></div>
                <div class="fld" id="nBomBox"><label>製令編號 <span style="color:var(--coral)" id="nBomReq">*</span>
                        <span class="muted-help">（要從清單選）</span></label>
                    <input type="text" id="n_bom" autocomplete="off" placeholder="輸入製令／料號／客戶後從清單選"></div>
                <div class="fld"><label>客戶 <span class="muted-help">（由來源自動綁定）</span></label>
                    <input type="text" id="n_client" readonly style="background:#F5F0E8;"></div>
                <div class="fld"><label>料號 <span class="muted-help">（由來源自動綁定）</span></label>
                    <input type="text" id="n_part" readonly style="background:#F5F0E8;"></div>
                <div class="fld"><label>批量</label><input type="number" id="n_batch"></div>
                <div class="fld"><label>檢驗數 <span class="muted-help" id="nSampleHint"></span></label><input type="number" id="n_insp"></div>
                <div class="fld"><label>不良數</label><input type="number" id="n_ng"></div>
            </div>
            <div class="fld" style="margin-top:8px;"><label>異常現象（可之後再補）</label>
                <textarea id="n_phe" rows="3" style="width:100%;border:1px solid var(--line);border-radius:4px;padding:4px 6px;"></textarea></div>
            <div id="newBf" class="note-box" style="display:none;border-color:var(--amber-d);background:#FFF6E8;"></div>
            <div class="err" id="newErr"></div>
        </div>
        <div class="m-ft">
            <button class="btn btn-default btn-sm" data-close="newMask">取消</button>
            <button class="btn btn-warm btn-sm" id="btnNewGo"><i class="fa fa-check"></i> 建立並開始填寫</button>
        </div>
    </div>
</div>

<?php if ($perms['canAdmin']): ?>
<!-- 設定 -->
<div class="m-mask" id="cfgMask">
    <div class="m-box" style="width:900px;">
        <div class="m-hd"><i class="fa fa-cog"></i> 品質異常處理單 設定<span class="x" data-close="cfgMask">&times;</span></div>
        <div class="m-bd">
            <div class="tabs">
                <button data-tab="cause" class="on">異常原因分類</button>
                <button data-tab="disp">異常處置方式</button>
                <button data-tab="gm">總經理裁示</button>
                <button data-tab="dec">決策者</button>
                <button data-tab="ask">相關單位意見</button>
                <button data-tab="etc">其他設定</button>
            </div>

            <div class="tabp" id="tab-cause">
                <div class="note-box">最多三層（例：<b>人 → 方法 → 程式</b>）。這一欄會延伸到之後的異常分析與報告，所以<b>沒有「其他」這個選項</b>；
                    已經被異常單選過的分類不可刪除，請改成「停用」（既有單仍看得到，新單不再出現）。</div>
                <div style="margin:6px 0;display:flex;gap:6px;align-items:center;">
                    <button class="btn btn-warm-o btn-xs" id="btnCauseExpand">全部展開</button>
                    <button class="btn btn-warm-o btn-xs" id="btnCauseCollapse">全部收合</button>
                    <span class="muted-help">點第一層的 ▸ 可以只看那一類；拖曳左側 ⠿ 調整順序（同一層之內）。</span>
                </div>
                <table class="cfg"><thead><tr>
                    <th style="width:28px"></th>
                    <th style="width:46%">分類名稱</th><th style="width:24%">上層</th>
                    <th style="width:10%">啟用</th><th style="width:12%">操作</th></tr></thead>
                    <tbody id="cfgCause" data-sortgrp="cause"></tbody></table>
                <div style="margin-top:8px;text-align:right;">
                    <button class="btn btn-warm btn-sm" data-saveall="cause"><i class="fa fa-save"></i> 一鍵存檔（本頁全部）</button>
                </div>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="fld" style="width:220px;"><label>新增分類名稱</label>
                        <input type="text" id="nc_name" placeholder="打完按 Enter 就直接新增"></div>
                    <div class="fld" style="width:260px;"><label>上層（留空＝第一層）</label><select id="nc_parent"></select></div>
                    <button class="btn btn-warm btn-sm" id="btnCauseAdd"><i class="fa fa-plus"></i> 新增</button>
                </div>
            </div>

            <div class="tabp" id="tab-disp" style="display:none;">
                <div class="note-box">紙本的「異常處置方式」勾選框。<b>「是報廢」「轉總經理」不是比對名稱而是這兩個旗標</b>——改名不會讓判定失效，但旗標一定要勾對：
                    勾「是報廢」的選項會讓這張單在結案時配發報廢單號；勾「轉總經理」的選項會在存檔時通知最終決策者。</div>
                <table class="cfg"><thead><tr><th style="width:28px"></th><th style="width:36%">名稱</th><th>是報廢</th><th>轉總經理</th><th>需矯正</th>
                    <th style="width:9%">啟用</th><th style="width:11%">操作</th></tr></thead>
                    <tbody id="cfgDisp" data-sortgrp="disp"></tbody></table>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="btn btn-warm-o btn-sm" data-optadd="disp"><i class="fa fa-plus"></i> 新增一個選項</button>
                    <span style="margin-left:auto;"></span>
                    <button class="btn btn-warm btn-sm" data-saveall="disp"><i class="fa fa-save"></i> 一鍵存檔（本頁全部）</button>
                </div>
            </div>

            <div class="tabp" id="tab-gm" style="display:none;">
                <div class="note-box">紙本的「總經理裁示」勾選框。<b>有裁示時以裁示為最終決策</b>（優先於主管的處置方式）。</div>
                <table class="cfg"><thead><tr><th style="width:28px"></th><th style="width:36%">名稱</th><th>是報廢</th><th>轉總經理</th><th>需矯正</th>
                    <th style="width:9%">啟用</th><th style="width:11%">操作</th></tr></thead>
                    <tbody id="cfgGm" data-sortgrp="gm"></tbody></table>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="btn btn-warm-o btn-sm" data-optadd="gm"><i class="fa fa-plus"></i> 新增一個選項</button>
                    <span style="margin-left:auto;"></span>
                    <button class="btn btn-warm btn-sm" data-saveall="gm"><i class="fa fa-save"></i> 一鍵存檔（本頁全部）</button>
                </div>
            </div>

            <div class="tabp" id="tab-dec" style="display:none;">
                <div class="note-box"><b>決策主管</b>＝填表人可以選來做處置判定的範圍（業務主管／品管主管…）。設定的是「部門＋職稱」，人員異動不必回來改。</div>
                <div class="note-box" id="cfgGmBox" style="border-color:var(--amber-d);background:#FFF6E8;"></div>
                <table class="cfg"><thead><tr><th style="width:28px"></th><th style="width:12%">類別</th><th style="width:21%">顯示名稱<br><span class="muted-help" style="font-weight:normal;">（自動＝部門＋職稱）</span></th><th style="width:19%">部門</th>
                    <th style="width:17%">職稱</th><th style="width:8%">含下轄</th><th style="width:7%">啟用</th><th style="width:10%">操作</th></tr></thead>
                    <tbody id="cfgDec" data-sortgrp="decider"></tbody></table>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="btn btn-warm-o btn-sm" data-decadd="decider"><i class="fa fa-plus"></i> 新增決策主管範圍</button>
                    <span style="margin-left:auto;"></span>
                    <button class="btn btn-warm btn-sm" data-saveall="decider"><i class="fa fa-save"></i> 一鍵存檔（本頁全部）</button>
                </div>
                <div id="decPeople" class="muted-help" style="margin-top:6px;"></div>
            </div>

            <div class="tabp" id="tab-ask" style="display:none;">
                <div class="note-box">處理頁的「相關單位意見」左側會列出這裡設定的部門，<b>勾起來就自動帶入該部門的預設回覆職稱</b>。
                    同一個部門可以設好幾個職稱（例：課長＋組長），<b>系統會通知這些人，其中一位回覆並簽章即可</b>。
                    沒有設定職稱的部門＝通知整個部門。</div>
                <table class="cfg"><thead><tr><th style="width:30%">部門</th><th>預設回覆職稱（可多選）</th><th style="width:12%">操作</th></tr></thead>
                    <tbody id="cfgAsk"></tbody></table>
                <div style="margin-top:8px;"><button class="btn btn-warm-o btn-sm" id="btnAskAdd"><i class="fa fa-plus"></i> 新增一個部門</button></div>
            </div>

            <div class="tabp" id="tab-etc" style="display:none;">
                <div class="fgrid">
                    <div class="fld"><label>扣款「加成」預設值（1.1＝總金額×110%）</label><input type="number" step="0.01" id="cfgRate"></div>
                    <div class="fld"><label>補資料天數（今日往前幾天以前算補資料）</label><input type="number" id="cfgBfDays"></div>
                </div>
                <div class="muted-help" style="margin-top:4px;">加成：新開的單會帶這個值，單張仍可自行修改。<br>
                    補資料天數：填寫日期在「今天往前這麼多天」以前的單，會多出「補登簽章」區，
                    由<b>異常單管理員</b>指定當時的簽章人員與印章日期（預設 10 天）。</div>
                <div style="margin-top:12px;border-top:1px dashed var(--line);padding-top:10px;">
                    <div class="fld"><label>AS 文件綁定（表頭表單名稱與頁尾編號由此推導）</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span id="asShow" class="as-tag"><?= htmlspecialchars($asNo) ?></span>
                            <button class="btn btn-warm-o btn-xs" id="btnAsPick"><i class="fa fa-link"></i> 選擇 AS 文件</button>
                        </div>
                    </div>
                </div>
                <div style="margin-top:10px;"><button class="btn btn-warm btn-sm" id="btnSaveEtc"><i class="fa fa-save"></i> 儲存其他設定</button></div>
            </div>
        </div>
        <div class="m-ft"><span id="cfgSaved"></span>
            <button class="btn btn-default btn-sm" data-close="cfgMask">完成，關閉</button></div>
    </div>
</div>
<?php endif; ?>

<!-- 刪除／還原（只有異常單管理員；軟刪除，資料留著可查可還原） -->
<div class="m-mask" id="delMask">
    <div class="m-box" style="width:520px;">
        <div class="m-hd"><i class="fa fa-trash-o"></i> <span id="delTitle">刪除異常單</span><span class="x" data-close="delMask">&times;</span></div>
        <div class="m-bd">
            <div class="note-box" id="delNote"></div>
            <div class="fld"><label>原因 <span style="color:var(--coral)" id="delReq">*</span></label>
                <textarea id="delReason" rows="3" placeholder="例：重複開單／料號填錯，已重開一張"></textarea></div>
            <div class="err" id="delErr"></div>
        </div>
        <div class="m-ft">
            <button class="btn btn-default btn-sm" data-close="delMask">取消</button>
            <button class="btn btn-warm btn-sm" id="btnDelGo"><i class="fa fa-check"></i> 確定</button>
        </div>
    </div>
</div>

<!-- 使用說明（鐵律7） -->
<div class="m-mask" id="helpUseMask">
    <div class="m-box" style="width:820px;">
        <div class="m-hd"><i class="fa fa-question-circle"></i> 使用說明－品質異常處理單<span class="x" data-close="helpUseMask">&times;</span></div>
        <div class="m-bd help-doc">
            <h4>這一頁在做什麼</h4>
            <p>紙本 <b>2-QA-01-01 品質異常處理單</b> 的清單與入口。查詢舊單、開立新單、進入單張處理頁或直接列印。</p>
            <h4>操作步驟</h4>
            <ul>
                <li><b>開立異常單</b>：選來源——<b>客退</b>（選 IR 單）或<b>製程中</b>（選製令）。
                    <b>兩者都一定要從清單選到既有的單據</b>，只打字不選會被擋下（客戶、料號與扣款金額都是靠這個綁定帶出來的）；
                    客戶與料號會自動帶、不給手打，<b>檢驗數</b>則依線上檢驗的抽樣規則自動建議。建立後自動跳到處理頁填其餘內容。</li>
                <li><b>進入處理</b>：點該列「處理」。填寫、徵詢相關單位意見、決策、總經理裁示、扣款確認、結案都在那一頁。</li>
                <li><b>列印</b>：點「列印」開出照紙本版面的正式表單（公司全名、表單名稱、AS 編號與版次都自動帶）。</li>
            </ul>
            <h4>補舊資料</h4>
            <ul>
                <li>填寫日期在<b>今天往前 N 天（預設 10 天，可在設定調整）以前</b>的單，系統自動視為「補資料」。</li>
                <li>補資料的單在處理頁會多出<b>「補登簽章」</b>區：<b>只有「異常單管理員」</b>可以逐格指定
                    <b>當時是誰簽的、印章蓋哪一天</b>；人員清單依印章日期回推當時在職者（當時在職、現已離職的人也選得到）。</li>
                <li>補資料時的「相關單位意見」改成直接補登（填回覆人、回覆日期與內容），<b>不會發通知</b>。</li>
            </ul>
            <h4>狀態怎麼判讀</h4>
            <ul>
                <li><b>等待單位回覆</b>：已送出徵詢、對方還沒回。<b>待決策</b>：還沒勾處置方式也沒有裁示。</li>
                <li><b>待總經理裁示</b>：處置方式勾了「轉總經理裁示」但還沒裁示。<b>扣款確認中</b>：要扣款但還沒核准。<b>可結案</b>：該做的都做完了。</li>
            </ul>
            <h4>設定（限管理員）</h4>
            <ul>
                <li><b>異常原因分類</b>：最多三層，可停用；已被單子選過的不可刪除。</li>
                <li><b>異常處置方式／總經理裁示</b>：選項可增修；「是報廢」旗標決定結案時要不要配發報廢單號，「轉總經理」旗標決定要不要通知最終決策者。</li>
                <li><b>決策者</b>：設定可以做處置判定的「部門＋職稱」範圍。
                    <b>最高決策者（總經理裁示）不在這裡設定</b>——自動套用全站「組織角色綁定 → 最高核准人員」，
                    要換人請到<a href="../admin/org_role_setting.php" target="_blank" style="color:#b5762a;">組織角色綁定設定</a>改一次，全站表單一起跟著換。</li>
                <li><b>其他設定</b>：扣款加成預設值、<b>補資料天數</b>、AS 文件綁定。</li>
                <li>每個設定分頁右下角都有<b>「一鍵存檔（本頁全部）」</b>，不必一列一列按「存」；
                    有任何一列填錯會整批不儲存並告訴你是第幾列（不會只存一半）。
                    決策者的<b>顯示名稱是自動的</b>＝「部門＋職稱」，部門或職稱改名時跟著變，不會留舊名稱。</li>
            </ul>
            <h4>權限角色</h4>
            <ul>
                <li><b>開單／填寫</b>：品管或業務部門成員，或指派 <code>qab_fill</code>。<b>檢閱</b>：<code>qab_view</code>。</li>
                <li><b>決策主管</b>：落在設定的決策者範圍內，或 <code>qab_decide</code>。<b>最終決策者</b>：最高核准人員／設定範圍／<code>qab_gm</code>。</li>
                <li><b>扣款填寫</b>：生管或業務部門，或 <code>qab_deduct_fill</code>；<b>扣款核准</b>：會計部門，或 <code>qab_deduct_approve</code>。</li>
                <li><b>管理員</b>：<code>qab_admin</code>（代碼表與設定）。管理者固定擁有全部權限。</li>
            </ul>
        </div>
        <div class="m-ft"><button class="btn btn-default btn-sm" data-close="helpUseMask">關閉</button></div>
    </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
var API = '../../src/store/QaAbnormal_API.php';
var CSRF = '<?= $CSRF ?>';
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var BF_DAYS = <?= (int)$backfillDays ?>;
var N_BOM_OK = false;           // 開新單的製令欄位現在的值是不是「從清單選到的」
function nSuggestSample(){
    var q = parseInt($('#n_batch').val(), 10);
    if (!(q > 0)) { $('#nSampleHint').text(''); return; }
    $.get(API, { action:'suggest_sample', qty:q }, function(res){
        if (!res || !res.success) return;
        var sug = Number(res.sample) || 0;
        $('#nSampleHint').text(sug ? ('（抽樣規則建議 ' + sug + ' 件）') : '');
        if (sug && !$('#n_insp').val()) $('#n_insp').val(sug);   // 空的才自動帶，不蓋掉人填的
    }, 'json');
}
$(document).on('change', '#n_batch', nSuggestSample);
$(document).on('input', '#n_bom', function(){ N_BOM_OK = false; });
var CFG = null, DEPTS = [], POSITIONS = [];

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
function dispDate(s){ try { return window.egFmtDate ? egFmtDate(s) : (s || ''); } catch(e){ return s || ''; } }
function openMask(id){ $('#' + id).show(); }
function closeMask(id){ $('#' + id).hide(); }
$(document).on('click', '[data-close]', function(){ closeMask($(this).data('close')); });

function post(action, data, cb, errCb){
    data = data || {}; data.action = action; data.csrf = CSRF;
    $.post(API, data, function(res){
        if (!res || !res.success) {
            var m = (res && res.message) || '操作失敗';
            if (errCb) errCb(m); else alert(m);
            return;
        }
        if (cb) cb(res);
    }, 'json').fail(function(){ if (errCb) errCb('連線失敗'); else alert('連線失敗'); });
}

/* ───────── 清單 ───────── */
function load(){
    $('#lstBody').html('<tr><td colspan="10" class="c">載入中…</td></tr>');
    $.get(API, { action:'list', year:$('#fYear').val(), month:$('#fMonth').val(),
                 closed:$('#fClosed').val(), source:$('#fSource').val(), kw:$('#fKw').val(),
                 deleted:$('#fDeleted').prop('checked') ? 1 : '' }, function(res){
        if (!res || !res.success) { $('#lstBody').html('<tr><td colspan="10" class="c">' + esc((res && res.message) || '載入失敗') + '</td></tr>'); return; }
        var rows = res.rows || [];
        syncYears(res.years);
        var del = $('#fDeleted').prop('checked');
        var open = rows.filter(function(r){ return !Number(r.is_closed); }).length;
        var scrap = rows.filter(function(r){ return r.scrap_no; }).length;
        $('#sumBox').html(del
            ? ('已刪除 <b>' + rows.length + '</b> 張（資料仍留著，可還原）')
            : ('共 <b>' + rows.length + '</b> 張　未結案 <b>' + open + '</b> 張　已配發報廢單號 <b>' + scrap + '</b> 張'));
        if (!rows.length) { $('#lstBody').html('<tr><td colspan="10" class="c">沒有符合條件的異常單</td></tr>'); return; }
        $('#lstBody').html(rows.map(function(r){
            var bomIr = (r.bom_no ? esc(r.bom_no) : '') + (r.ir_no ? ((r.bom_no ? '<br>' : '') + 'IR ' + esc(r.ir_no)) : '');
            return '<tr>'
                + '<td class="c"><b>' + esc(r.abnormal_order_no) + '</b><br><span class="src src-' + esc(r.source_type) + '">'
                    + (r.source_type === 'IR' ? '客退' : (r.source_type === 'BOM' ? '製程' : '檢驗')) + '</span></td>'
                + '<td class="c">' + esc(dispDate(r.fill_date || r.occurrence_date)) + '</td>'
                + '<td>' + esc(r.client_name) + '</td>'
                + '<td>' + esc(r.part_no) + '</td>'
                + '<td>' + bomIr + '</td>'
                + '<td>' + esc((r.abnormal_phenomenon || '').substring(0, 60)) + '</td>'
                + '<td>' + esc(r.responsible_unit) + '</td>'
                + '<td>' + esc(r.final_label) + (r.scrap_no ? '<br><span class="st st-gm">報廢單 ' + esc(r.scrap_no) + '</span>' : '') + '</td>'
                + '<td class="c"><span class="st st-' + r.status.code + '">' + esc(r.status.label) + '</span></td>'
                + '<td class="c">'
                + (r.deleted_at
                    ? ('<span class="muted-help">' + esc(dispDate(r.deleted_at)) + ' 由 ' + esc(r.deleted_name || '') + ' 刪除</span>'
                       + (CAN_ADMIN ? ' <button class="btn btn-warm btn-xs act-restore" data-id="' + r.id + '" data-no="' + esc(r.abnormal_order_no) + '">還原</button>' : ''))
                    : ('<a href="qa_abnormal_form.php?id=' + r.id + '" class="btn btn-warm-o btn-xs">處理</a> '
                       + '<a href="qa_abnormal_print.php?id=' + r.id + '" target="_blank" class="btn btn-warm-o btn-xs"><i class="fa fa-print"></i></a>'
                       + (CAN_ADMIN ? ' <button class="btn btn-warm-o btn-xs act-del" data-id="' + r.id + '" data-no="' + esc(r.abnormal_order_no) + '"><i class="fa fa-trash-o"></i></button>' : '')))
                + '</td></tr>';
        }).join(''));
    }, 'json').fail(function(){ $('#lstBody').html('<tr><td colspan="10" class="c">連線失敗</td></tr>'); });
}
/* 年度下拉只列真的有資料的年度；後端每次都回最新的一份，這裡只在內容不同時重畫 */
function syncYears(years){
    if (!years || !years.length) return;
    var cur = $('#fYear').val();
    var have = $('#fYear option').map(function(){ return this.value; }).get().filter(function(v){ return v !== ''; }).join(',');
    if (have === years.join(',')) return;
    $('#fYear').html('<option value="">全部</option>' + years.map(function(y){
        return '<option value="' + y + '"' + (String(y) === String(cur) ? ' selected' : '') + '>' + y + '</option>'; }).join(''));
}
$('#btnSearch').on('click', load);
$('#fYear,#fMonth,#fClosed,#fSource,#fDeleted').on('change', load);

/* ───────── 刪除／還原（軟刪除，一律留紀錄） ───────── */
var DEL = { id:0, act:'delete' };
$(document).on('click', '.act-del', function(){
    DEL = { id:Number($(this).data('id')), act:'delete' };
    $('#delTitle').text('刪除異常單 ' + $(this).data('no'));
    $('#delNote').html('這張單會從清單上移除，<b>資料仍然留著</b>（勾「顯示已刪除」可以看到，必要時還原）。'
        + '刪除一定會記下是誰、什麼時候、為什麼刪的。');
    $('#delReq').show(); $('#delReason').val(''); $('#delErr').text('');
    openMask('delMask');
});
$(document).on('click', '.act-restore', function(){
    DEL = { id:Number($(this).data('id')), act:'restore' };
    $('#delTitle').text('還原異常單 ' + $(this).data('no'));
    $('#delNote').html('把這張單放回清單。原因可留空。');
    $('#delReq').hide(); $('#delReason').val(''); $('#delErr').text('');
    openMask('delMask');
});
$('#btnDelGo').on('click', function(){
    var reason = $('#delReason').val().trim();
    if (DEL.act === 'delete' && !reason) { $('#delErr').text('請填寫刪除原因'); return; }
    post(DEL.act === 'delete' ? 'order_delete' : 'order_restore', { id:DEL.id, reason:reason }, function(){
        closeMask('delMask'); load();
    }, function(msg){ $('#delErr').text(msg); });
});
$('#fKw').on('keydown', function(e){ if (e.key === 'Enter') load(); });

/* ───────── 開新單 ───────── */
function bfHint(){
    var d = $('#n_date').val();
    if (!d) { $('#newBf').hide(); return; }
    var cut = new Date(); cut.setDate(cut.getDate() - BF_DAYS);
    var isBf = d < cut.toISOString().slice(0, 10);
    $('#newBf').toggle(isBf).html(!isBf ? '' :
        ('<b><i class="fa fa-clock-o"></i> 這張會被視為補資料</b>（填寫日期在 ' + BF_DAYS
         + ' 天以前）：建立後可以在處理頁的「補登簽章」逐格指定當時的簽章人員與印章日期，'
         + '相關單位意見也會改成直接補登、不發通知。'));
}
$(document).on('change', '#n_date', bfHint);
$('#btnNew').on('click', function(){
    $('#newErr').text('');
    $('#n_date').val(new Date().toISOString().slice(0, 10));
    $('#newBf').hide();
    $('#n_ir,#n_ir_id,#n_bom,#n_client,#n_part,#n_batch,#n_insp,#n_ng,#n_phe').val('');
    $('#nSampleHint').text('');
    N_BOM_OK = false;
    openMask('newMask');
});
$(document).on('change', 'input[name=nkind]', function(){
    var ir = $('input[name=nkind]:checked').val() === 'ir';
    $('#nIrBox').toggle(ir);
    $('#nBomReq').toggle(!ir);
});
$('#btnNewGo').on('click', function(){
    var kind = $('input[name=nkind]:checked').val();
    if (kind === 'ir' && !$('#n_ir_id').val()) { $('#newErr').text('請從清單中選擇客退單(IR)——同一個單號可能有好幾筆，一定要選到是哪一筆'); return; }
    if (kind === 'bom' && !$('#n_bom').val().trim()) { $('#newErr').text('請選擇製令編號'); return; }
    if (kind === 'bom' && !N_BOM_OK) { $('#newErr').text('製令編號請從清單中選擇（只打字不選，客戶、料號與扣款金額都帶不出來）'); return; }
    post('create', { kind:kind, fill_date:$('#n_date').val(), ir_id:$('#n_ir_id').val(), bom_no:$('#n_bom').val(),
                     client_name:$('#n_client').val(), part_no:$('#n_part').val(), batch_qty:$('#n_batch').val(),
                     insp_qty:$('#n_insp').val(), ng_qty:$('#n_ng').val(), abnormal_phenomenon:$('#n_phe').val() },
        function(res){ location.href = 'qa_abnormal_form.php?id=' + res.id; });
});

/* 自動完成 */
function acSetup(inputSel, action, fmt, pick){
    var $in = $(inputSel), tmr = null, $list = $('<div class="ac-list"></div>').appendTo('body');
    function place(){ var r = $in[0].getBoundingClientRect();
        $list.css({ left:r.left + 'px', top:(r.bottom + 2) + 'px', width:Math.max(r.width, 260) + 'px' }); }
    $in.on('input focus', function(){
        var kw = $in.val().trim();
        clearTimeout(tmr);
        tmr = setTimeout(function(){
            $.get(API, { action:action, kw:kw }, function(res){
                if (!res || !res.success || !res.rows.length) { $list.hide(); return; }
                $list.html(res.rows.map(function(r, i){ return '<div data-i="' + i + '">' + fmt(r) + '</div>'; }).join(''));
                $list.data('rows', res.rows); place(); $list.show();
            }, 'json');
        }, 220);
    });
    $list.on('mousedown', 'div', function(e){ e.preventDefault(); pick(($list.data('rows') || [])[$(this).data('i')]); $list.hide(); });
    $in.on('blur', function(){ setTimeout(function(){ $list.hide(); }, 180); });
    $(window).on('scroll resize', function(){ if ($list.is(':visible')) place(); });
}

/* ───────── 設定 ───────── */
<?php if ($perms['canAdmin']): ?>
$('#btnCfg').on('click', function(){ loadCfg(); openMask('cfgMask'); });
$(document).on('click', '.tabs button', function(){
    $('.tabs button').removeClass('on'); $(this).addClass('on');
    $('.tabp').hide(); $('#tab-' + $(this).data('tab')).show();
});
function savedTick(){
    var t = new Date();
    $('#cfgSaved').text('已自動儲存 ' + ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2) + ':' + ('0' + t.getSeconds()).slice(-2));
}

/* ───────── 拖曳排序：放開之後重新編號（10,20,30…）再整批存 ─────────
   排序號畫面上不顯示，使用者只管順序；同一層之內才可以互換（分類是三層樹，
   跨層互換等於改上層，那要另外挑「上層」欄位）。 */
var DRAG_TR = null;
$(document).on('dragstart', '.cfg td.drag', function(e){
    DRAG_TR = $(this).closest('tr')[0];
    $(DRAG_TR).addClass('dragging');
    try { e.originalEvent.dataTransfer.effectAllowed = 'move'; e.originalEvent.dataTransfer.setData('text/plain', 'row'); } catch (err) {}
});
$(document).on('dragend', '.cfg td.drag', function(){ $('.cfg tr').removeClass('dragging drop-before drop-after'); DRAG_TR = null; });
$(document).on('dragover', '.cfg tbody tr', function(e){
    if (!DRAG_TR || this === DRAG_TR) return;
    if ($(this).closest('tbody')[0] !== $(DRAG_TR).closest('tbody')[0]) return;
    e.preventDefault();
    var r = this.getBoundingClientRect();
    var after = (e.originalEvent.clientY - r.top) > r.height / 2;
    $('.cfg tr').removeClass('drop-before drop-after');
    $(this).addClass(after ? 'drop-after' : 'drop-before');
});
$(document).on('drop', '.cfg tbody tr', function(e){
    if (!DRAG_TR || this === DRAG_TR) return;
    e.preventDefault();
    var $tb = $(this).closest('tbody');
    if ($tb[0] !== $(DRAG_TR).closest('tbody')[0]) return;
    var grp = $tb.data('sortgrp');
    if (grp === 'cause' && String($(this).data('parent')) !== String($(DRAG_TR).data('parent'))) {
        alert('只能在同一層（同一個上層）之間調整順序；要換到別的上層請用「上層」欄位。');
        $('.cfg tr').removeClass('drop-before drop-after');
        return;
    }
    if ($(this).hasClass('drop-after')) $(this).after(DRAG_TR); else $(this).before(DRAG_TR);
    $('.cfg tr').removeClass('drop-before drop-after');
    renumberAndSave(grp, $tb);
});
function renumberAndSave(grp, $tb){
    var sortCls = grp === 'cause' ? '.c-sort' : (grp === 'decider' ? '.d-sort' : '.o-sort');
    var seen = {};
    $tb.find('tr').each(function(){
        var key = grp === 'cause' ? String($(this).data('parent') || 0) : 'x';
        seen[key] = (seen[key] || 0) + 10;
        $(this).find(sortCls).val(seen[key]);
    });
    post('cfg_save_all', { what:grp, rows:JSON.stringify(collectCfgRows(grp)) }, function(res){
        if (res.causes) CFG.causes = res.causes;
        if (res.disp_opts) { CFG.disp_opts = res.disp_opts; CFG.gm_opts = res.gm_opts; }
        if (res.deciders) CFG.deciders = res.deciders;
        if (grp === 'cause') renderCause(); else if (grp === 'decider') renderDec(); else renderOpts();
        savedTick();
    });
}

/* ───────── 相關單位意見：各部門的預設回覆職稱 ───────── */
var ASK_POS = {};        // dept_id => [職稱清單]（逐部門向後端要一次就好）
function askRow(deptId, posIds){
    var dopt = DEPTS.map(function(d){ return '<option value="' + d.id + '"' + (Number(d.id) === Number(deptId) ? ' selected' : '') + '>' + esc(d.department_name) + '</option>'; }).join('');
    return '<tr data-dept="' + (deptId || 0) + '">'
        + '<td><select class="a-dept" data-eg-filter="輸入部門名稱篩選…"><option value="">請選擇…</option>' + dopt + '</select></td>'
        + '<td class="a-pos-td" data-sel="' + (posIds || []).join(',') + '"><span class="muted-help">請先選部門</span></td>'
        + '<td class="c"><button class="btn btn-warm-o btn-xs a-del">刪</button></td></tr>';
}
function renderAsk(){
    var cfg = (CFG && CFG.ask_cfg) || {};
    var keys = Object.keys(cfg);
    $('#cfgAsk').html(keys.length ? keys.map(function(d){
        return askRow(d, cfg[d].map(function(x){ return x.position_id; }));
    }).join('') : '');
    if (!keys.length) $('#cfgAsk').html('<tr><td colspan="3" class="c">尚未設定（沒設定的部門＝通知整個部門）</td></tr>');
    $('#cfgAsk tr[data-dept]').each(function(){ loadAskPos($(this)); });
}
function loadAskPos($tr){
    var d = $tr.find('.a-dept').val();
    var sel = String($tr.find('.a-pos-td').data('sel') || '').split(',').filter(Boolean);
    if (!d) { $tr.find('.a-pos-td').html('<span class="muted-help">請先選部門</span>'); return; }
    $.get(API, { action:'dept_positions', dept_id:d }, function(res){
        var rows = (res && res.rows) || [];
        $tr.find('.a-pos-td').html(rows.length ? ('<div class="ask-pos">' + rows.map(function(p){
            return '<label><input type="checkbox" class="a-pos" value="' + p.id + '"'
                 + (sel.indexOf(String(p.id)) >= 0 ? ' checked' : '') + '> ' + esc(p.position_name) + '</label>';
        }).join('') + '</div>') : '<span class="muted-help">這個部門目前沒有在職人員</span>');
    }, 'json');
}
function saveAsk(){
    var rows = [];
    $('#cfgAsk tr[data-dept]').each(function(){
        var d = $(this).find('.a-dept').val();
        if (!d) return;
        var ps = $(this).find('.a-pos:checked').map(function(){ return Number(this.value); }).get();
        rows.push({ dept_id:Number(d), position_ids:ps });
    });
    post('ask_cfg_save', { rows:JSON.stringify(rows) }, function(res){ CFG.ask_cfg = res.cfg; savedTick(); });
}
$('#btnAskAdd').on('click', function(){
    if ($('#cfgAsk').find('td[colspan]').length) $('#cfgAsk').empty();
    $('#cfgAsk').append(askRow(0, []));
});
$(document).on('change', '#cfgAsk .a-dept', function(){
    var $tr = $(this).closest('tr');
    $tr.attr('data-dept', $(this).val() || 0).find('.a-pos-td').attr('data-sel', '').data('sel', '');
    loadAskPos($tr);
    saveAsk();
});
$(document).on('change', '#cfgAsk .a-pos', saveAsk);
$(document).on('click', '#cfgAsk .a-del', function(){ $(this).closest('tr').remove(); saveAsk(); });

function loadCfg(){
    $.get(API, { action:'settings_get' }, function(res){
        if (!res || !res.success) { alert('載入設定失敗'); return; }
        CFG = res;
        $('#cfgRate').val(res.rate);
        $('#cfgBfDays').val(res.backfill_days);
        renderCause(); renderOpts(); renderDec(); renderAsk();
    }, 'json');
    if (!DEPTS.length) $.get(API, { action:'depts' }, function(res){ if (res && res.success) { DEPTS = res.rows; renderDec(); renderAsk(); } }, 'json');
    // 職稱清單要先載好，否則已存的那幾列會顯示成「不限職稱」（看起來像設定不見了）
    if (!POSITIONS.length) $.get(API, { action:'positions' }, function(res){ if (res && res.success) { POSITIONS = res.rows; renderDec(); } }, 'json');
}
function flatCause(){
    var out = [];
    (function walk(ns, lv){ (ns || []).forEach(function(n){ n._lv = lv; out.push(n); walk(n.children, lv + 1); }); })(CFG.causes, 1);
    return out;
}
var CAUSE_OPEN = {};                 // cat_id => 是否展開（分類一多，全部攤開看不完）
function renderCause(){
    var rows = flatCause();
    rows.forEach(function(n){ n._kids = rows.some(function(x){ return Number(x.parent_id) === Number(n.cat_id); }); });
    $('#cfgCause').html(rows.map(function(n){
        return '<tr data-cat="' + n.cat_id + '" data-parent="' + (n.parent_id || 0) + '">'
            + '<td class="drag" draggable="true" title="按住拖曳可以調整順序">&#x2822;</td>'
            + '<td><div class="lv-in' + (n._lv > 1 ? n._lv : '') + '">'
                + (n._kids ? ('<span class="c-tog" data-cat="' + n.cat_id + '">' + (CAUSE_OPEN[n.cat_id] ? '&#9662;' : '&#9656;') + '</span>') : '<span class="c-tog-x"></span>')
                + '<input type="text" class="c-name" value="' + esc(n.name) + '"></div></td>'
            + '<td class="c">' + esc(n.parent_id ? (rows.filter(function(x){ return x.cat_id === n.parent_id; })[0] || {}).name || '' : '（第一層）') + '</td>'
            + '<td class="c"><input type="checkbox" class="c-act" ' + (Number(n.is_active) ? 'checked' : '') + '></td>'
            + '<td class="c"><input type="hidden" class="c-sort" value="' + n.sort_order + '">'
            + '<button class="btn btn-warm-o btn-xs c-del">刪</button></td></tr>';
    }).join('') || '<tr><td colspan="5" class="c">尚未建立</td></tr>');
    $('#nc_parent').html('<option value="">（第一層）</option>' + rows.filter(function(n){ return n._lv < 3; }).map(function(n){
        return '<option value="' + n.cat_id + '">' + esc('　'.repeat(n._lv - 1) + n.name) + '</option>';
    }).join(''));
    applyCauseFold(rows);
}
/* 收合：只要祖先有一個是收起來的，這一列就不顯示 */
function applyCauseFold(rows){
    var byId = {};
    (rows || flatCause()).forEach(function(n){ byId[n.cat_id] = n; });
    $('#cfgCause tr[data-cat]').each(function(){
        var n = byId[Number($(this).data('cat'))];
        var show = true, p = n && n.parent_id;
        while (p) { if (!CAUSE_OPEN[p]) { show = false; break; } p = (byId[p] || {}).parent_id; }
        $(this).toggle(show);
    });
}
$(document).on('click', '.c-tog', function(){
    var id = Number($(this).data('cat'));
    CAUSE_OPEN[id] = !CAUSE_OPEN[id];
    $(this).html(CAUSE_OPEN[id] ? '&#9662;' : '&#9656;');
    applyCauseFold();
});
$('#btnCauseExpand').on('click', function(){ flatCause().forEach(function(n){ CAUSE_OPEN[n.cat_id] = true; }); renderCause(); });
$('#btnCauseCollapse').on('click', function(){ CAUSE_OPEN = {}; renderCause(); });
function saveCauseRow($tr, silent){
    if (!$tr.data('cat')) return;
    post('cause_save', { cat_id:$tr.data('cat'), name:$tr.find('.c-name').val(),
        parent_id:(flatCause().filter(function(x){ return x.cat_id === Number($tr.data('cat')); })[0] || {}).parent_id || '',
        sort_order:$tr.find('.c-sort').val(), is_active:$tr.find('.c-act').prop('checked') ? 1 : '' },
        function(res){ CFG.causes = res.causes; if (!silent) { renderCause(); } savedTick(); });
}
$(document).on('change', '#cfgCause .c-name, #cfgCause .c-act', function(){ saveCauseRow($(this).closest('tr'), true); });
$(document).on('click', '.c-del', function(){
    if (!confirm('刪除這個分類？')) return;
    post('cause_del', { cat_id:$(this).closest('tr').data('cat') }, function(res){ CFG.causes = res.causes; renderCause(); });
});
function nextSort(list, parentId){
    var mx = 0;
    (list || []).forEach(function(n){
        if (parentId !== undefined && Number(n.parent_id || 0) !== Number(parentId || 0)) return;
        mx = Math.max(mx, Number(n.sort_order) || 0);
    });
    return mx + 10;                       // 排序號自動跳，畫面上不必顯示
}
function addCause(){
    var nm = $('#nc_name').val().trim();
    if (!nm) { alert('請填分類名稱'); return; }
    var pid = $('#nc_parent').val();
    post('cause_save', { name:nm, parent_id:pid, sort_order:nextSort(flatCause(), pid), is_active:1 },
        function(res){ CFG.causes = res.causes; $('#nc_name').val('').focus(); renderCause(); savedTick(); });
}
$('#btnCauseAdd').on('click', addCause);
$('#nc_name').on('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); addCause(); } });

function optRow(o, kind){
    return '<tr data-opt="' + o.opt_id + '" data-kind="' + kind + '">'
        + '<td class="drag" draggable="true" title="按住拖曳可以調整順序">&#x2822;</td>'
        + '<td><input type="text" class="o-name" value="' + esc(o.name) + '"></td>'
        + '<td class="c"><input type="checkbox" class="o-scrap" ' + (Number(o.is_scrap) ? 'checked' : '') + '></td>'
        + '<td class="c"><input type="checkbox" class="o-esc" ' + (Number(o.is_escalate) ? 'checked' : '') + '></td>'
        + '<td class="c"><input type="checkbox" class="o-capa" ' + (Number(o.need_capa) ? 'checked' : '') + '></td>'
        + '<td class="c"><input type="checkbox" class="o-act" ' + (Number(o.is_active) ? 'checked' : '') + '></td>'
        + '<td class="c"><input type="hidden" class="o-sort" value="' + o.sort_order + '">'
        + '<button class="btn btn-warm-o btn-xs o-del">刪</button></td></tr>';
}
function renderOpts(){
    $('#cfgDisp').html((CFG.disp_opts || []).map(function(o){ return optRow(o, 'disp'); }).join('') || '<tr><td colspan="7" class="c">尚未建立</td></tr>');
    $('#cfgGm').html((CFG.gm_opts || []).map(function(o){ return optRow(o, 'gm'); }).join('') || '<tr><td colspan="7" class="c">尚未建立</td></tr>');
}
$(document).on('click', '[data-optadd]', function(){
    var kind = $(this).data('optadd');
    var $tb = $(kind === 'gm' ? '#cfgGm' : '#cfgDisp');
    if ($tb.find('td[colspan]').length) $tb.empty();
    $tb.append(optRow({ opt_id:0, name:'', is_scrap:0, is_escalate:0, need_capa:0,
                        sort_order:nextSort(kind === 'gm' ? CFG.gm_opts : CFG.disp_opts), is_active:1 }, kind));
    $tb.find('tr:last .o-name').focus();
});
/* 打完名稱（或改了任何一個勾選）就自動存檔，不必再按「存」 */
function saveOptRow($tr, silent){
    if (!$tr.find('.o-name').val().trim()) return;
    post('opt_save', { opt_id:$tr.data('opt'), kind:$tr.data('kind'), name:$tr.find('.o-name').val(),
        is_scrap:$tr.find('.o-scrap').prop('checked') ? 1 : '', is_escalate:$tr.find('.o-esc').prop('checked') ? 1 : '',
        need_capa:$tr.find('.o-capa').prop('checked') ? 1 : '', sort_order:$tr.find('.o-sort').val(),
        is_active:$tr.find('.o-act').prop('checked') ? 1 : '' },
        function(res){ CFG.disp_opts = res.disp_opts; CFG.gm_opts = res.gm_opts;
                       if (!silent || !$tr.data('opt')) renderOpts(); savedTick(); });
}
$(document).on('change', '#cfgDisp input, #cfgGm input', function(){ saveOptRow($(this).closest('tr'), true); });
$(document).on('click', '.o-del', function(){
    var $tr = $(this).closest('tr');
    if (!$tr.data('opt')) { $tr.remove(); return; }
    if (!confirm('刪除這個選項？')) return;
    post('opt_del', { opt_id:$tr.data('opt') }, function(res){ CFG.disp_opts = res.disp_opts; CFG.gm_opts = res.gm_opts; renderOpts(); });
});

function decRow(c){
    var dopt = DEPTS.map(function(d){ return '<option value="' + d.id + '"' + (Number(d.id) === Number(c.dept_id) ? ' selected' : '') + '>' + esc(d.department_name) + '</option>'; }).join('');
    var popt = '<option value="">不限職稱</option>' + POSITIONS.map(function(p){
        return '<option value="' + p.id + '"' + (Number(p.id) === Number(c.position_id) ? ' selected' : '') + '>' + esc(p.position_name) + '</option>'; }).join('');
    return '<tr data-cfg="' + c.cfg_id + '">'
        + '<td class="drag" draggable="true" title="按住拖曳可以調整順序">&#x2822;</td>'
        + '<td class="c"><select class="d-kind" data-eg-skip><option value="decider" selected>決策主管</option></select></td>'
        + '<td class="d-show muted-help">' + esc(c.show_name || '（選好部門與職稱後自動帶出）') + '</td>'
        + '<td><select class="d-dept" data-eg-skip><option value="">請選擇…</option>' + dopt + '</select></td>'
        + '<td><select class="d-pos" data-eg-skip>' + popt + '</select></td>'
        + '<td class="c"><input type="checkbox" class="d-sub" ' + (Number(c.include_sub) ? 'checked' : '') + '></td>'
        + '<td class="c"><input type="checkbox" class="d-act" ' + (Number(c.is_active) ? 'checked' : '') + '></td>'
        + '<td class="c"><input type="hidden" class="d-sort" value="' + (c.sort_order || 0) + '">'
        + '<button class="btn btn-warm-o btn-xs d-del">刪</button>'
        + (c.cfg_id ? ' <button class="btn btn-warm-o btn-xs d-who" title="看這一列目前涵蓋誰"><i class="fa fa-users"></i></button>' : '') + '</td></tr>';
}
function renderDec(){
    if (!CFG) return;
    var all = (CFG.deciders || []);
    $('#cfgDec').html(all.length ? all.map(decRow).join('') : '<tr><td colspan="8" class="c">尚未設定（沒設定時由 qab_decide 角色判定）</td></tr>');
    var g = CFG.gm_person || {};
    $('#cfgGmBox').html('<b>最高決策者（總經理裁示）</b>：'
        + (g.bound ? ('<b>' + esc(g.name || '') + '</b>'
              + (g.is_delegated ? '（' + esc(g.base_name || '') + ' 目前不在，由代理人簽）' : ''))
            : '<span style="color:var(--coral);">尚未設定</span>')
        + '　—　<b>自動套用全站統一設定</b>，本模組不另外設定。要換人請到 '
        + '<a href="../admin/org_role_setting.php" target="_blank" style="color:#b5762a;">組織角色綁定設定</a>'
        + ' 改「最高核准人員」，全站表單會一起跟著換。');
}
$(document).on('click', '[data-decadd]', function(){
    var kind = $(this).data('decadd');
    if ($('#cfgDec').find('td[colspan]').length) $('#cfgDec').empty();
    $('#cfgDec').append(decRow({ cfg_id:0, kind:kind, label:'', dept_id:0, position_id:0, include_sub:0,
                                 sort_order:nextSort(CFG.deciders), is_active:1 }));
    refreshDecShow($('#cfgDec tr').last());
});
/* 顯示名稱＝部門＋職稱，選到什麼就即時顯示什麼（存檔時後端也是即時組，不存文字） */
function refreshDecShow($tr){
    var d = $tr.find('.d-dept option:selected').text().trim();
    var p = $tr.find('.d-pos option:selected').text().trim();
    if (!p || p === '不限職稱') p = '不限職稱';
    $tr.find('.d-show').text(d ? (d + ' ' + p) : '（選好部門與職稱後自動帶出）');
}
$(document).on('change', '.d-dept, .d-pos', function(){ refreshDecShow($(this).closest('tr')); });
$(document).on('change', '.d-dept', function(){
    var $tr = $(this).closest('tr'), d = $(this).val();
    if (!d) return;
    $.get(API, { action:'dept_positions', dept_id:d }, function(res){
        var cur = $tr.find('.d-pos').val();
        var h = '<option value="">不限職稱</option>' + ((res && res.rows) || []).map(function(p){
            return '<option value="' + p.id + '"' + (String(p.id) === String(cur) ? ' selected' : '') + '>' + esc(p.position_name) + '</option>'; }).join('');
        $tr.find('.d-pos').html(h);
        refreshDecShow($tr);
    }, 'json');
});
function saveDecRow($tr, silent){
    if (!$tr.find('.d-dept').val()) return;
    post('decider_save', { cfg_id:$tr.data('cfg'), kind:$tr.find('.d-kind').val(),
        dept_id:$tr.find('.d-dept').val(), position_id:$tr.find('.d-pos').val(),
        include_sub:$tr.find('.d-sub').prop('checked') ? 1 : '', sort_order:$tr.find('.d-sort').val(),
        is_active:$tr.find('.d-act').prop('checked') ? 1 : '' },
        function(res){ CFG.deciders = res.deciders; if (!silent || !$tr.data('cfg')) renderDec(); savedTick(); });
}
/* 部門的 change 另有一支「重抓該部門職稱」的處理，所以這裡延後一點再存，
   免得存到還沒換好的舊職稱 */
$(document).on('change', '#cfgDec .d-dept', function(){
    var $tr = $(this).closest('tr');
    setTimeout(function(){ saveDecRow($tr, true); }, 350);
});
$(document).on('change', '#cfgDec .d-pos, #cfgDec .d-sub, #cfgDec .d-act', function(){ saveDecRow($(this).closest('tr'), true); });
$(document).on('click', '.d-del', function(){
    var $tr = $(this).closest('tr');
    if (!$tr.data('cfg')) { $tr.remove(); return; }
    if (!confirm('刪除這一列設定？')) return;
    post('decider_del', { cfg_id:$tr.data('cfg') }, function(res){ CFG.deciders = res.deciders; renderDec(); });
});
$(document).on('click', '.d-who', function(){
    var id = $(this).closest('tr').data('cfg');
    $.get(API, { action:'decider_people', cfg_id:id }, function(res){
        var rows = (res && res.rows) || [];
        $('#decPeople').html(rows.length
            ? ('這一列目前涵蓋 ' + rows.length + ' 人：' + rows.map(function(u){ return esc(u.name + '（' + (u.dept_name || '') + ' ' + (u.position_name || '') + '）'); }).join('、'))
            : '這一列目前沒有涵蓋任何在職人員（部門或職稱可能沒有人）。');
    }, 'json');
});
/* 一鍵存檔：把該分頁每一列的值收成一包送出，後端逐列套用與單列存檔相同的規則。
   任何一列不合法就整批不寫入並指出是第幾列——存一半會讓畫面與資料庫對不起來。 */
function collectCfgRows(what){
    var rows = [];
    if (what === 'cause') {
        var flat = flatCause();
        $('#cfgCause tr[data-cat]').each(function(){
            var $tr = $(this), id = Number($tr.data('cat'));
            var cur = flat.filter(function(x){ return Number(x.cat_id) === id; })[0] || {};
            rows.push({ cat_id:id, name:$tr.find('.c-name').val(), parent_id:cur.parent_id || '',
                        sort_order:$tr.find('.c-sort').val(), is_active:$tr.find('.c-act').prop('checked') ? 1 : 0 });
        });
    } else if (what === 'decider') {
        $('#cfgDec tr[data-cfg]').each(function(){
            var $tr = $(this);
            rows.push({ cfg_id:Number($tr.data('cfg')), kind:'decider',
                        dept_id:$tr.find('.d-dept').val(), position_id:$tr.find('.d-pos').val(),
                        include_sub:$tr.find('.d-sub').prop('checked') ? 1 : 0,
                        sort_order:$tr.find('.d-sort').val(),
                        is_active:$tr.find('.d-act').prop('checked') ? 1 : 0 });
        });
    } else {
        $((what === 'gm' ? '#cfgGm' : '#cfgDisp') + ' tr[data-opt]').each(function(){
            var $tr = $(this);
            rows.push({ opt_id:Number($tr.data('opt')), name:$tr.find('.o-name').val(),
                        is_scrap:$tr.find('.o-scrap').prop('checked') ? 1 : 0,
                        is_escalate:$tr.find('.o-esc').prop('checked') ? 1 : 0,
                        need_capa:$tr.find('.o-capa').prop('checked') ? 1 : 0,
                        sort_order:$tr.find('.o-sort').val(),
                        is_active:$tr.find('.o-act').prop('checked') ? 1 : 0 });
        });
    }
    return rows;
}
$(document).on('click', '[data-saveall]', function(){
    var what = $(this).data('saveall');
    var rows = collectCfgRows(what);
    if (!rows.length) { alert('這一頁沒有可以儲存的列'); return; }
    post('cfg_save_all', { what:what, rows:JSON.stringify(rows) }, function(res){
        CFG.causes = res.causes; CFG.disp_opts = res.disp_opts; CFG.gm_opts = res.gm_opts;
        CFG.deciders = res.deciders; CFG.gm_person = res.gm_person;
        renderCause(); renderOpts(); renderDec();
        alert('已儲存 ' + res.saved + ' 列');
    });
});

$('#btnSaveEtc').on('click', function(){
    post('setting_save', { surcharge_rate:$('#cfgRate').val(), backfill_days:$('#cfgBfDays').val() }, function(res){
        BF_DAYS = Number(res.backfill_days);
        alert('已儲存');
    });
});
$('#btnAsPick').on('click', function(){
    if (!window.EGAsDoc) { alert('AS 文件挑選器未載入'); return; }
    $.get(API, { action:'asdoc_list' }, function(res){
        if (!res || !res.success) { alert('載入 AS 文件清單失敗'); return; }
        EGAsDoc.open({ docs:res.docs, current:res.current, title:'品質異常處理單－AS 文件編號綁定',
            onSave:function(id){
                post('asdoc_save', { doc_id:id }, function(r){ $('#asShow').text(r.doc_no || '未綁定'); });
            }});
    }, 'json');
});
<?php endif; ?>

$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$(function(){
    $('#sidebar-menu').css('visibility', 'visible');
    acSetup('#n_ir', 'search_ir',
        function(r){ return '<span class="hit">' + esc(r.IR_no) + '</span>　' + esc(r.Client_name) + '　' + esc(r.d_id); },
        function(r){ $('#n_ir').val(r.IR_no); $('#n_ir_id').val(r.IR_id);
            $('#n_client').val(r.Client_name || ''); $('#n_part').val(r.d_id || '');
            $('#n_batch').val(r.Qty || ''); $('#newErr').text(''); nSuggestSample(); });
    acSetup('#n_bom', 'search_bom',
        function(r){ return '<span class="hit">' + esc(r.bom) + '</span>　' + esc(r.d_id) + '　' + esc(r.Client_Name); },
        function(r){ $('#n_bom').val(r.bom); N_BOM_OK = true;
            $('#n_client').val(r.Client_Name || ''); $('#n_part').val(r.d_id || '');
            $('#n_batch').val(r.sqty || ''); $('#newErr').text(''); nSuggestSample(); });
    <?php if ($perms['canView']): ?>load();<?php endif; ?>
});
</script>
</body>
</html>
