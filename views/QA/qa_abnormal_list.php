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
                        <?php for ($y = $thisYear + 1; $y >= $thisYear - 4; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $thisYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
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
                <div class="fld" id="nBomBox"><label>製令編號 <span style="color:var(--coral)" id="nBomReq">*</span></label>
                    <input type="text" id="n_bom" autocomplete="off" placeholder="輸入製令／料號／客戶搜尋"></div>
                <div class="fld"><label>客戶 <span class="muted-help">（由來源自動綁定）</span></label>
                    <input type="text" id="n_client" readonly style="background:#F5F0E8;"></div>
                <div class="fld"><label>料號</label><input type="text" id="n_part"></div>
                <div class="fld"><label>批量</label><input type="number" id="n_batch"></div>
                <div class="fld"><label>檢驗數</label><input type="number" id="n_insp"></div>
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
                <button data-tab="etc">其他設定</button>
            </div>

            <div class="tabp" id="tab-cause">
                <div class="note-box">最多三層（例：<b>人 → 方法 → 程式</b>）。這一欄會延伸到之後的異常分析與報告，所以<b>沒有「其他」這個選項</b>；
                    已經被異常單選過的分類不可刪除，請改成「停用」（既有單仍看得到，新單不再出現）。</div>
                <table class="cfg"><thead><tr>
                    <th style="width:44%">分類名稱</th><th style="width:22%">上層</th><th style="width:10%">排序</th>
                    <th style="width:10%">啟用</th><th style="width:14%">操作</th></tr></thead>
                    <tbody id="cfgCause"></tbody></table>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="fld" style="width:200px;"><label>新增分類名稱</label><input type="text" id="nc_name"></div>
                    <div class="fld" style="width:240px;"><label>上層（留空＝第一層）</label><select id="nc_parent"></select></div>
                    <div class="fld" style="width:90px;"><label>排序</label><input type="number" id="nc_sort" value="0"></div>
                    <button class="btn btn-warm btn-sm" id="btnCauseAdd"><i class="fa fa-plus"></i> 新增</button>
                </div>
            </div>

            <div class="tabp" id="tab-disp" style="display:none;">
                <div class="note-box">紙本的「異常處置方式」勾選框。<b>「是報廢」「轉總經理」不是比對名稱而是這兩個旗標</b>——改名不會讓判定失效，但旗標一定要勾對：
                    勾「是報廢」的選項會讓這張單在結案時配發報廢單號；勾「轉總經理」的選項會在存檔時通知最終決策者。</div>
                <table class="cfg"><thead><tr><th style="width:34%">名稱</th><th>是報廢</th><th>轉總經理</th><th>需矯正</th>
                    <th style="width:10%">排序</th><th style="width:9%">啟用</th><th style="width:13%">操作</th></tr></thead>
                    <tbody id="cfgDisp"></tbody></table>
                <div style="margin-top:8px;"><button class="btn btn-warm-o btn-sm" data-optadd="disp"><i class="fa fa-plus"></i> 新增一個選項</button></div>
            </div>

            <div class="tabp" id="tab-gm" style="display:none;">
                <div class="note-box">紙本的「總經理裁示」勾選框。<b>有裁示時以裁示為最終決策</b>（優先於主管的處置方式）。</div>
                <table class="cfg"><thead><tr><th style="width:34%">名稱</th><th>是報廢</th><th>轉總經理</th><th>需矯正</th>
                    <th style="width:10%">排序</th><th style="width:9%">啟用</th><th style="width:13%">操作</th></tr></thead>
                    <tbody id="cfgGm"></tbody></table>
                <div style="margin-top:8px;"><button class="btn btn-warm-o btn-sm" data-optadd="gm"><i class="fa fa-plus"></i> 新增一個選項</button></div>
            </div>

            <div class="tabp" id="tab-dec" style="display:none;">
                <div class="note-box"><b>決策主管</b>＝填表人可以選來做處置判定的範圍（業務主管／品管主管…）；<b>最高決策者</b>＝勾「轉總經理裁示」之後要通知與裁示的人。
                    設定的是「部門＋職稱」，人員異動不必回來改。最高決策者若留空，系統會用組織角色設定的「最高核准人員」。</div>
                <table class="cfg"><thead><tr><th style="width:13%">類別</th><th style="width:17%">顯示名稱</th><th style="width:20%">部門</th>
                    <th style="width:16%">職稱</th><th style="width:9%">含下轄</th><th style="width:8%">排序</th><th style="width:7%">啟用</th><th style="width:10%">操作</th></tr></thead>
                    <tbody id="cfgDec"></tbody></table>
                <div style="margin-top:8px;">
                    <button class="btn btn-warm-o btn-sm" data-decadd="decider"><i class="fa fa-plus"></i> 新增決策主管範圍</button>
                    <button class="btn btn-warm-o btn-sm" data-decadd="top"><i class="fa fa-plus"></i> 新增最高決策者範圍</button>
                </div>
                <div id="decPeople" class="muted-help" style="margin-top:6px;"></div>
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
        <div class="m-ft"><button class="btn btn-default btn-sm" data-close="cfgMask">完成，關閉</button></div>
    </div>
</div>
<?php endif; ?>

<!-- 使用說明（鐵律7） -->
<div class="m-mask" id="helpUseMask">
    <div class="m-box" style="width:820px;">
        <div class="m-hd"><i class="fa fa-question-circle"></i> 使用說明－品質異常處理單<span class="x" data-close="helpUseMask">&times;</span></div>
        <div class="m-bd help-doc">
            <h4>這一頁在做什麼</h4>
            <p>紙本 <b>2-QA-01-01 品質異常處理單</b> 的清單與入口。查詢舊單、開立新單、進入單張處理頁或直接列印。</p>
            <h4>操作步驟</h4>
            <ul>
                <li><b>開立異常單</b>：選來源——<b>客退</b>（填 IR 單號，可另外綁製令、也可不綁）或<b>製程中</b>（直接綁製令）。建立後自動跳到處理頁填其餘內容。</li>
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
                <li><b>決策者</b>：設定可以做處置判定的「部門＋職稱」範圍，以及最高決策者的範圍。</li>
                <li><b>其他設定</b>：扣款加成預設值、<b>補資料天數</b>、AS 文件綁定。</li>
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
var CFG = null, DEPTS = [], POSITIONS = [];

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
function dispDate(s){ try { return window.egFmtDate ? egFmtDate(s) : (s || ''); } catch(e){ return s || ''; } }
function openMask(id){ $('#' + id).show(); }
function closeMask(id){ $('#' + id).hide(); }
$(document).on('click', '[data-close]', function(){ closeMask($(this).data('close')); });

function post(action, data, cb){
    data = data || {}; data.action = action; data.csrf = CSRF;
    $.post(API, data, function(res){
        if (!res || !res.success) { alert((res && res.message) || '操作失敗'); return; }
        if (cb) cb(res);
    }, 'json').fail(function(){ alert('連線失敗'); });
}

/* ───────── 清單 ───────── */
function load(){
    $('#lstBody').html('<tr><td colspan="10" class="c">載入中…</td></tr>');
    $.get(API, { action:'list', year:$('#fYear').val(), month:$('#fMonth').val(),
                 closed:$('#fClosed').val(), source:$('#fSource').val(), kw:$('#fKw').val() }, function(res){
        if (!res || !res.success) { $('#lstBody').html('<tr><td colspan="10" class="c">' + esc((res && res.message) || '載入失敗') + '</td></tr>'); return; }
        var rows = res.rows || [];
        var open = rows.filter(function(r){ return !Number(r.is_closed); }).length;
        var scrap = rows.filter(function(r){ return r.scrap_no; }).length;
        $('#sumBox').html('共 <b>' + rows.length + '</b> 張　未結案 <b>' + open + '</b> 張　已配發報廢單號 <b>' + scrap + '</b> 張');
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
                + '<td class="c"><a href="qa_abnormal_form.php?id=' + r.id + '" class="btn btn-warm-o btn-xs">處理</a> '
                + '<a href="qa_abnormal_print.php?id=' + r.id + '" target="_blank" class="btn btn-warm-o btn-xs"><i class="fa fa-print"></i></a></td>'
                + '</tr>';
        }).join(''));
    }, 'json').fail(function(){ $('#lstBody').html('<tr><td colspan="10" class="c">連線失敗</td></tr>'); });
}
$('#btnSearch').on('click', load);
$('#fYear,#fMonth,#fClosed,#fSource').on('change', load);
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
    openMask('newMask');
});
$(document).on('change', 'input[name=nkind]', function(){
    var ir = $('input[name=nkind]:checked').val() === 'ir';
    $('#nIrBox').toggle(ir);
    $('#nBomReq').toggle(!ir);
});
$('#btnNewGo').on('click', function(){
    var kind = $('input[name=nkind]:checked').val();
    if (kind === 'ir' && !$('#n_ir_id').val()) { $('#newErr').text('請從清單中選擇客退單(IR)'); return; }
    if (kind === 'bom' && !$('#n_bom').val().trim()) { $('#newErr').text('請選擇製令編號'); return; }
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
function loadCfg(){
    $.get(API, { action:'settings_get' }, function(res){
        if (!res || !res.success) { alert('載入設定失敗'); return; }
        CFG = res;
        $('#cfgRate').val(res.rate);
        $('#cfgBfDays').val(res.backfill_days);
        renderCause(); renderOpts(); renderDec();
    }, 'json');
    if (!DEPTS.length) $.get(API, { action:'depts' }, function(res){ if (res && res.success) { DEPTS = res.rows; renderDec(); } }, 'json');
    // 職稱清單要先載好，否則已存的那幾列會顯示成「不限職稱」（看起來像設定不見了）
    if (!POSITIONS.length) $.get(API, { action:'positions' }, function(res){ if (res && res.success) { POSITIONS = res.rows; renderDec(); } }, 'json');
}
function flatCause(){
    var out = [];
    (function walk(ns, lv){ (ns || []).forEach(function(n){ n._lv = lv; out.push(n); walk(n.children, lv + 1); }); })(CFG.causes, 1);
    return out;
}
function renderCause(){
    var rows = flatCause();
    $('#cfgCause').html(rows.map(function(n){
        return '<tr data-cat="' + n.cat_id + '">'
            + '<td><div class="lv-in' + (n._lv > 1 ? n._lv : '') + '"><input type="text" class="c-name" value="' + esc(n.name) + '"></div></td>'
            + '<td class="c">' + esc(n.parent_id ? (rows.filter(function(x){ return x.cat_id === n.parent_id; })[0] || {}).name || '' : '（第一層）') + '</td>'
            + '<td><input type="number" class="c-sort" value="' + n.sort_order + '"></td>'
            + '<td class="c"><input type="checkbox" class="c-act" ' + (Number(n.is_active) ? 'checked' : '') + '></td>'
            + '<td class="c"><button class="btn btn-warm btn-xs c-save">存</button> '
            + '<button class="btn btn-warm-o btn-xs c-del">刪</button></td></tr>';
    }).join('') || '<tr><td colspan="5" class="c">尚未建立</td></tr>');
    $('#nc_parent').html('<option value="">（第一層）</option>' + rows.filter(function(n){ return n._lv < 3; }).map(function(n){
        return '<option value="' + n.cat_id + '">' + esc('　'.repeat(n._lv - 1) + n.name) + '</option>';
    }).join(''));
}
$(document).on('click', '.c-save', function(){
    var $tr = $(this).closest('tr');
    post('cause_save', { cat_id:$tr.data('cat'), name:$tr.find('.c-name').val(),
        parent_id:(flatCause().filter(function(x){ return x.cat_id === Number($tr.data('cat')); })[0] || {}).parent_id || '',
        sort_order:$tr.find('.c-sort').val(), is_active:$tr.find('.c-act').prop('checked') ? 1 : '' },
        function(res){ CFG.causes = res.causes; renderCause(); });
});
$(document).on('click', '.c-del', function(){
    if (!confirm('刪除這個分類？')) return;
    post('cause_del', { cat_id:$(this).closest('tr').data('cat') }, function(res){ CFG.causes = res.causes; renderCause(); });
});
$('#btnCauseAdd').on('click', function(){
    if (!$('#nc_name').val().trim()) { alert('請填分類名稱'); return; }
    post('cause_save', { name:$('#nc_name').val(), parent_id:$('#nc_parent').val(), sort_order:$('#nc_sort').val(), is_active:1 },
        function(res){ CFG.causes = res.causes; $('#nc_name').val(''); renderCause(); });
});

function optRow(o, kind){
    return '<tr data-opt="' + o.opt_id + '" data-kind="' + kind + '">'
        + '<td><input type="text" class="o-name" value="' + esc(o.name) + '"></td>'
        + '<td class="c"><input type="checkbox" class="o-scrap" ' + (Number(o.is_scrap) ? 'checked' : '') + '></td>'
        + '<td class="c"><input type="checkbox" class="o-esc" ' + (Number(o.is_escalate) ? 'checked' : '') + '></td>'
        + '<td class="c"><input type="checkbox" class="o-capa" ' + (Number(o.need_capa) ? 'checked' : '') + '></td>'
        + '<td><input type="number" class="o-sort" value="' + o.sort_order + '"></td>'
        + '<td class="c"><input type="checkbox" class="o-act" ' + (Number(o.is_active) ? 'checked' : '') + '></td>'
        + '<td class="c"><button class="btn btn-warm btn-xs o-save">存</button> <button class="btn btn-warm-o btn-xs o-del">刪</button></td></tr>';
}
function renderOpts(){
    $('#cfgDisp').html((CFG.disp_opts || []).map(function(o){ return optRow(o, 'disp'); }).join('') || '<tr><td colspan="7" class="c">尚未建立</td></tr>');
    $('#cfgGm').html((CFG.gm_opts || []).map(function(o){ return optRow(o, 'gm'); }).join('') || '<tr><td colspan="7" class="c">尚未建立</td></tr>');
}
$(document).on('click', '[data-optadd]', function(){
    var kind = $(this).data('optadd');
    var $tb = $(kind === 'gm' ? '#cfgGm' : '#cfgDisp');
    if ($tb.find('td[colspan]').length) $tb.empty();
    $tb.append(optRow({ opt_id:0, name:'', is_scrap:0, is_escalate:0, need_capa:0, sort_order:99, is_active:1 }, kind));
});
$(document).on('click', '.o-save', function(){
    var $tr = $(this).closest('tr');
    if (!$tr.find('.o-name').val().trim()) { alert('請填名稱'); return; }
    post('opt_save', { opt_id:$tr.data('opt'), kind:$tr.data('kind'), name:$tr.find('.o-name').val(),
        is_scrap:$tr.find('.o-scrap').prop('checked') ? 1 : '', is_escalate:$tr.find('.o-esc').prop('checked') ? 1 : '',
        need_capa:$tr.find('.o-capa').prop('checked') ? 1 : '', sort_order:$tr.find('.o-sort').val(),
        is_active:$tr.find('.o-act').prop('checked') ? 1 : '' },
        function(res){ CFG.disp_opts = res.disp_opts; CFG.gm_opts = res.gm_opts; renderOpts(); });
});
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
        + '<td class="c"><select class="d-kind"><option value="decider"' + (c.kind === 'decider' ? ' selected' : '') + '>決策主管</option>'
            + '<option value="top"' + (c.kind === 'top' ? ' selected' : '') + '>最高決策者</option></select></td>'
        + '<td><input type="text" class="d-label" value="' + esc(c.label || '') + '" placeholder="例：業務主管"></td>'
        + '<td><select class="d-dept" data-eg-skip><option value="">請選擇…</option>' + dopt + '</select></td>'
        + '<td><select class="d-pos" data-eg-skip>' + popt + '</select></td>'
        + '<td class="c"><input type="checkbox" class="d-sub" ' + (Number(c.include_sub) ? 'checked' : '') + '></td>'
        + '<td><input type="number" class="d-sort" value="' + (c.sort_order || 0) + '"></td>'
        + '<td class="c"><input type="checkbox" class="d-act" ' + (Number(c.is_active) ? 'checked' : '') + '></td>'
        + '<td class="c"><button class="btn btn-warm btn-xs d-save">存</button> <button class="btn btn-warm-o btn-xs d-del">刪</button>'
        + (c.cfg_id ? ' <button class="btn btn-warm-o btn-xs d-who" title="看這一列目前涵蓋誰"><i class="fa fa-users"></i></button>' : '') + '</td></tr>';
}
function renderDec(){
    if (!CFG) return;
    var all = (CFG.deciders || []).concat(CFG.tops || []);
    $('#cfgDec').html(all.length ? all.map(decRow).join('') : '<tr><td colspan="8" class="c">尚未設定（決策與裁示會退回角色判定）</td></tr>');
}
$(document).on('click', '[data-decadd]', function(){
    var kind = $(this).data('decadd');
    if ($('#cfgDec').find('td[colspan]').length) $('#cfgDec').empty();
    $('#cfgDec').append(decRow({ cfg_id:0, kind:kind, label:'', dept_id:0, position_id:0, include_sub:0, sort_order:0, is_active:1 }));
});
$(document).on('change', '.d-dept', function(){
    var $tr = $(this).closest('tr'), d = $(this).val();
    if (!d) return;
    $.get(API, { action:'dept_positions', dept_id:d }, function(res){
        var cur = $tr.find('.d-pos').val();
        var h = '<option value="">不限職稱</option>' + ((res && res.rows) || []).map(function(p){
            return '<option value="' + p.id + '"' + (String(p.id) === String(cur) ? ' selected' : '') + '>' + esc(p.position_name) + '</option>'; }).join('');
        $tr.find('.d-pos').html(h);
    }, 'json');
});
$(document).on('click', '.d-save', function(){
    var $tr = $(this).closest('tr');
    if (!$tr.find('.d-dept').val()) { alert('請選部門'); return; }
    post('decider_save', { cfg_id:$tr.data('cfg'), kind:$tr.find('.d-kind').val(), label:$tr.find('.d-label').val(),
        dept_id:$tr.find('.d-dept').val(), position_id:$tr.find('.d-pos').val(),
        include_sub:$tr.find('.d-sub').prop('checked') ? 1 : '', sort_order:$tr.find('.d-sort').val(),
        is_active:$tr.find('.d-act').prop('checked') ? 1 : '' },
        function(res){ CFG.deciders = res.deciders; CFG.tops = res.tops; renderDec(); });
});
$(document).on('click', '.d-del', function(){
    var $tr = $(this).closest('tr');
    if (!$tr.data('cfg')) { $tr.remove(); return; }
    if (!confirm('刪除這一列設定？')) return;
    post('decider_del', { cfg_id:$tr.data('cfg') }, function(res){ CFG.deciders = res.deciders; CFG.tops = res.tops; renderDec(); });
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
            $('#n_client').val(r.Client_name || ''); $('#n_part').val(r.d_id || ''); $('#n_batch').val(r.Qty || ''); });
    acSetup('#n_bom', 'search_bom',
        function(r){ return '<span class="hit">' + esc(r.bom) + '</span>　' + esc(r.d_id) + '　' + esc(r.Client_Name); },
        function(r){ $('#n_bom').val(r.bom);
            $('#n_client').val(r.Client_Name || ''); $('#n_part').val(r.d_id || ''); $('#n_batch').val(r.sqty || ''); });
    <?php if ($perms['canView']): ?>load();<?php endif; ?>
});
</script>
</body>
</html>
