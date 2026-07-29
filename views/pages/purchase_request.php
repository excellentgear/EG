<?php
/**
 * 申請採購（含採購入帳與入庫）
 * 流程：申請(金額可留白) → 採購詢價填實際金額 → 依總額判定簽核 → 核准 → 下單
 *       → 到貨(入庫待領/直接交付請購人/不列管) → 記帳(發票、付款) → 結案
 * 採購品主檔三層：類別 → 品項 → 規格變體(=採購料號)，另有標籤與依類別自訂的規格屬性
 * 資料一律走 src/store/Purchase_API.php；權限 src/common/purchase_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pages/purchase_request.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/purchase_lib.php';

$db = (new DBConnection())->getPDO();
purchase_ensure_schema($db);
$pqUser = purchase_current_user($db);
$perms  = purchase_perms($db, $pqUser);
$roleLabel = purchase_role_label($perms);
$av = @filemtime(__FILE__) ?: time();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>申請採購</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* ── 暖色系調色盤（ai-rules/10）：淺底深棕字、深底白字 ── */
:root{
  --pq-line:#E8D5B5; --pq-bg:#FDF8EF; --pq-bg2:#FFF7E8; --pq-ink:#5b3a1e; --pq-ink2:#8a6d45;
  --pq-warm:#F0A24B; --pq-warm-d:#d98a33; --pq-soft:#F7E0BD; --pq-deep:#8A5A2B; --pq-red:#DD5138;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}
.pq-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 10px;border-bottom:2px solid var(--pq-line);padding-bottom:6px;}
.pq-tab{border:1.5px solid var(--pq-line);background:#fff;color:var(--pq-ink);border-radius:6px 6px 0 0;
  padding:6px 14px;font-size:14px;cursor:pointer;position:relative;}
.pq-tab:hover{background:var(--pq-soft);}
.pq-tab.on{background:var(--pq-warm);color:#fff;border-color:var(--pq-warm-d);font-weight:bold;}
.pq-tab .badge-n{background:var(--pq-red);color:#fff;border-radius:9px;font-size:11px;padding:0 6px;margin-left:5px;}
.pq-tab.on .badge-n{background:#fff;color:var(--pq-red);}
.pq-toolbar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1.5px solid var(--pq-line);
  border-radius:8px;padding:8px 10px;margin-bottom:10px;background:var(--pq-bg);}
.pq-toolbar label{margin:0;font-size:13px;color:var(--pq-ink);font-weight:normal;}
.pq-toolbar select,.pq-toolbar input,.pq-btn{height:30px;font-size:13px;line-height:1;padding:0 10px;
  border:1px solid #D8BE93;border-radius:4px;background:#fff;color:var(--pq-ink);}
.pq-btn{cursor:pointer;}
.pq-btn:hover{background:var(--pq-soft);}
.pq-btn.warm{background:var(--pq-warm);color:#fff;border-color:var(--pq-warm-d);}
.pq-btn.warm:hover{background:var(--pq-warm-d);}
.pq-btn.danger{background:var(--pq-red);color:#fff;border-color:#b8412c;}
.pq-btn:disabled{opacity:.5;cursor:not-allowed;}
.pq-role{margin-left:auto;font-size:13px;color:var(--pq-ink);background:var(--pq-soft);border-radius:12px;padding:4px 12px;}
.pq-role .fa-question-circle{cursor:pointer;color:#b5762a;margin-left:5px;}
.pq-stat{display:flex;flex-wrap:wrap;gap:20px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--pq-line);border-radius:8px;padding:10px 14px;background:var(--pq-bg2);}
.pq-stat .n{font-size:20px;font-weight:bold;color:var(--pq-deep);}
.pq-stat .l{font-size:12px;color:var(--pq-ink2);}
.pq-pager{display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-bottom:6px;font-size:13px;color:var(--pq-ink);}
.pq-pager button{border:1px solid #D8BE93;background:#fff;border-radius:4px;height:26px;min-width:28px;cursor:pointer;color:var(--pq-ink);}
.pq-pager button.on{background:var(--pq-warm);color:#fff;border-color:var(--pq-warm-d);}
.pq-pager button:disabled{opacity:.4;cursor:not-allowed;}
.pq-wrap{overflow-x:auto;border:1px solid var(--pq-line);border-radius:6px;background:#fff;}
table.pq-table{width:100%;border-collapse:collapse;font-size:13px;}
table.pq-table th,table.pq-table td{border:1px solid #EADFC8;padding:5px 8px;text-align:center;white-space:nowrap;}
table.pq-table th{background:var(--pq-soft);color:var(--pq-ink);font-weight:bold;}
table.pq-table tbody tr:hover{background:#FFFBF3;}
table.pq-table td.l{text-align:left;} table.pq-table td.r{text-align:right;}
.pq-empty{padding:22px;color:var(--pq-ink2);text-align:center;}
/* 狀態 pill：顏色不是唯一資訊，一律配文字 */
.pill{display:inline-block;border-radius:10px;padding:1px 9px;font-size:12px;border:1px solid transparent;}
.pill.submitted{background:#FFF3E0;color:#8A5A2B;border-color:#E8D5B5;}
.pill.quoted{background:var(--pq-soft);color:#7a4a12;border-color:#dcbf8c;}
.pill.approved{background:#F3E2C7;color:#6b4415;border-color:#dcbf8c;}
.pill.ordered{background:var(--pq-warm);color:#fff;}
.pill.partial{background:#E8A87C;color:#fff;}
.pill.received{background:var(--pq-deep);color:#fff;}
.pill.closed{background:#EFE6D7;color:#7b6448;border-color:#ddd0b8;}
.pill.rejected{background:var(--pq-red);color:#fff;}
.pill.canceled{background:#CFC3B0;color:#4a3c2a;}
.pill.paid{background:var(--pq-deep);color:#fff;} .pill.unpaid{background:#FFF3E0;color:#8A5A2B;border-color:#E8D5B5;}
.urg{background:var(--pq-warm);color:#fff;border-radius:8px;padding:0 6px;font-size:11px;}
.tag-chip{display:inline-block;background:var(--pq-soft);color:var(--pq-ink);border:1px solid #dcbf8c;
  border-radius:9px;padding:0 7px;font-size:11px;margin:1px;}
/* 遮罩 / 跳窗 */
.pq-mask{display:none;position:fixed;inset:0;background:rgba(60,40,20,.45);z-index:2000;overflow:auto;padding:24px 10px;}
.pq-mask.show{display:block;}
.pq-modal{background:#fff;max-width:820px;margin:0 auto;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.3);}
.pq-modal.wide{max-width:1140px;}
.pq-modal .m-head{background:var(--pq-deep);color:#fff;padding:9px 14px;border-radius:8px 8px 0 0;
  font-size:15px;display:flex;align-items:center;}
.pq-modal .m-close{margin-left:auto;cursor:pointer;font-size:18px;line-height:1;}
.pq-modal .m-body{padding:14px 16px;max-height:74vh;overflow:auto;}
.pq-modal .m-foot{padding:10px 16px;border-top:1px solid var(--pq-line);text-align:right;background:var(--pq-bg);border-radius:0 0 8px 8px;}
.pq-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:10px;}
.pq-fld label{display:block;font-size:12px;color:var(--pq-ink2);margin-bottom:2px;}
.pq-fld input,.pq-fld select,.pq-fld textarea{width:100%;border:1px solid #D8BE93;border-radius:4px;
  padding:4px 8px;font-size:13px;color:var(--pq-ink);background:#fff;}
.pq-fld textarea{min-height:56px;}
.pq-sec{border:1px solid var(--pq-line);border-radius:6px;padding:10px;margin-bottom:12px;background:var(--pq-bg);}
.pq-sec h5{margin:0 0 8px;font-size:14px;color:var(--pq-deep);font-weight:bold;}
/* 數字欄：不要上下增減鈕 */
input[type=number]{-moz-appearance:textfield;}
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.money{font-variant-numeric:tabular-nums;}
.flow{display:flex;flex-wrap:wrap;gap:4px;align-items:center;font-size:12px;color:var(--pq-ink2);margin-bottom:8px;}
.flow i{color:#c9ad80;}
.flow .cur{background:var(--pq-warm);color:#fff;border-radius:9px;padding:1px 8px;}
.flow .dn{background:var(--pq-soft);color:var(--pq-ink);border-radius:9px;padding:1px 8px;}
.hint{font-size:12px;color:var(--pq-ink2);}
.sug{border:1px solid var(--pq-line);border-radius:4px;background:#FFFBF3;padding:6px 8px;font-size:12px;color:#8a5a1e;margin-top:4px;}
.print-only{display:none;}
@media print{
  .pq-tabs,.pq-toolbar,.nav_menu,.left_col,footer,.pq-pager,.pq-role,.no-print{display:none !important;}
  .right_col{margin-left:0 !important;padding:0 !important;}
  .print-only{display:block;}
  table.pq-table{font-size:11px;} .pq-wrap{border:none;}
  body{background:#fff;}
}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">申請採購
                <small style="color:#8a6d45;">申請 → 採購詢價 → 依金額簽核 → 下單 → 到貨入帳入庫</small></h2>
        </div>
        <div class="clearfix"></div>
        <div class="print-only" id="printHead" style="text-align:center;font-size:16px;font-weight:bold;margin-bottom:6px;"></div>

<?php if (!$perms['canView']): ?>
        <div class="pq-sec" style="text-align:center;padding:30px;">
            <h4 style="color:#8A5A2B;"><i class="fa fa-lock"></i> 無申請採購權限</h4>
            <p class="hint">請洽管理者於「使用者權限設定」指派「申請採購／採購作業／到貨入庫／採購檢閱」等角色。</p>
        </div>
<?php else: ?>
        <div class="pq-tabs" id="tabs">
            <button class="pq-tab on" data-tab="mine">📋 我的申請 <span class="badge-n" id="bg-mine" style="display:none;"></span></button>
            <?php if ($perms['canBuy']): ?>
            <button class="pq-tab" data-tab="buy">🛒 採購作業 <span class="badge-n" id="bg-buy" style="display:none;"></span></button>
            <?php endif; ?>
            <button class="pq-tab" data-tab="sign">✍️ 待我簽核 <span class="badge-n" id="bg-sign" style="display:none;"></span></button>
            <?php if ($perms['canBuy']): ?>
            <button class="pq-tab" data-tab="unpaid">💰 未付款 <span class="badge-n" id="bg-unpaid" style="display:none;"></span></button>
            <?php endif; ?>
            <button class="pq-tab" data-tab="all">📚 全部單據</button>
            <button class="pq-tab" data-tab="master">🏷️ 採購品主檔</button>
            <?php if ($perms['canAdmin']): ?>
            <button class="pq-tab" data-tab="setting">⚙️ 設定</button>
            <?php endif; ?>
        </div>

        <!-- ═══ 單據清單（我的/採購/簽核/未付款/全部 共用）═══ -->
        <div id="view-list">
            <div class="pq-toolbar">
                <?php if ($perms['canApply']): ?>
                <button class="pq-btn warm" id="btnNew"><i class="fa fa-plus"></i> 提出採購申請</button>
                <?php endif; ?>
                <label>狀態</label>
                <select id="fStatus"><option value="">全部</option></select>
                <label>付款</label>
                <select id="fPay"><option value="">全部</option><option value="unpaid">未付</option><option value="paid">已付</option></select>
                <label>申請日</label>
                <input type="date" id="fFrom" title="起日"> ~ <input type="date" id="fTo" title="迄日">
                <input type="text" id="fKw" placeholder="單號/品名/廠商/發票號" style="width:190px;">
                <button class="pq-btn" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
                <button class="pq-btn" id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
                <button class="pq-btn" onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
                <span class="pq-role">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                    <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
            </div>

            <div class="pq-stat">
                <div><span class="n" id="stCnt">—</span> <span class="l">筆數</span></div>
                <div><span class="n money" id="stSum">—</span> <span class="l">含稅總額合計</span></div>
                <div><span class="n money" id="stUnpaid">—</span> <span class="l">其中未付款</span></div>
                <div class="l" style="margin-left:auto;" id="stHint"></div>
            </div>

            <div class="pq-pager">
                <span>每頁</span>
                <select id="pageSize" style="height:26px;border:1px solid #D8BE93;border-radius:4px;">
                    <option>5</option><option selected>10</option><option>20</option><option>50</option>
                </select>
                <span id="pgInfo"></span>
                <span id="pgBtns"></span>
            </div>

            <div class="pq-wrap">
                <table class="pq-table" id="listTable">
                    <thead><tr>
                        <th>單號</th><th>標題</th><th>申請人</th><th>部門</th><th>品項</th>
                        <th>廠商</th><th>含稅總額</th><th>狀態</th><th>付款</th><th>申請日</th><th class="no-print">操作</th>
                    </tr></thead>
                    <tbody id="listBody"><tr><td colspan="11" class="pq-empty">載入中…</td></tr></tbody>
                </table>
            </div>
            <div class="hint" style="margin-top:5px;" id="listFoot"></div>
        </div>

        <!-- ═══ 採購品主檔 ═══ -->
        <div id="view-master" style="display:none;">
            <div class="pq-toolbar">
                <?php if ($perms['canBuy']): ?>
                <button class="pq-btn warm" id="btnNewItem"><i class="fa fa-plus"></i> 新增品項</button>
                <?php endif; ?>
                <?php if ($perms['canAdmin']): ?>
                <button class="pq-btn" id="btnTags"><i class="fa fa-tags"></i> 標籤管理</button>
                <button class="pq-btn" id="btnAttrs"><i class="fa fa-list-ul"></i> 規格屬性設定</button>
                <?php endif; ?>
                <label>類別</label><select id="mCat"><option value="">全部</option></select>
                <label>標籤</label><select id="mTag"><option value="">全部</option></select>
                <input type="text" id="mKw" placeholder="品名/編碼/規格" style="width:180px;">
                <button class="pq-btn" id="mSearch"><i class="fa fa-search"></i> 查詢</button>
                <span class="hint" style="margin-left:auto;">同一個「鑽頭」只建一次品項，不同尺寸＝底下多個規格，不必重複建料號</span>
            </div>
            <div class="pq-pager">
                <span>每頁</span>
                <select id="mPageSize" style="height:26px;border:1px solid #D8BE93;border-radius:4px;">
                    <option>5</option><option>10</option><option selected>20</option><option>50</option>
                </select>
                <span id="mPgInfo"></span><span id="mPgBtns"></span>
            </div>
            <div class="pq-wrap">
                <table class="pq-table" id="itemTable">
                    <thead><tr><th>品項編碼</th><th>類別</th><th>品名</th><th>標籤</th>
                        <th>規格數</th><th>目前庫存</th><th>預設廠商</th><th class="no-print">操作</th></tr></thead>
                    <tbody id="itemBody"><tr><td colspan="8" class="pq-empty">載入中…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ═══ 設定 ═══ -->
        <?php if ($perms['canAdmin']): ?>
        <div id="view-setting" style="display:none;">
            <div class="pq-sec" style="max-width:760px;">
                <h5>簽核金額門檻（採購詢完價、填入實際金額後判定）</h5>
                <div class="pq-grid">
                    <div class="pq-fld"><label>免簽上限（含稅總額 ≤ 此金額免簽核）</label>
                        <input type="number" id="cfgL1" step="1"></div>
                    <div class="pq-fld"><label>一層簽核上限（超過此金額需主管＋高階核准兩層）</label>
                        <input type="number" id="cfgL2" step="1"></div>
                </div>
                <p class="hint">第一層＝申請人的部門主管（主管不在時自動走代理人／權責分離規則）；
                   第二層＝具「高階核准」角色者。門檻改動只影響之後才送簽的單。</p>
            </div>
            <div class="pq-sec" style="max-width:760px;">
                <h5>附件儲存路徑（只存檔名，完整路徑由此設定即時組出）</h5>
                <div class="pq-grid">
                    <div class="pq-fld"><label>實體存放路徑（NAS）</label><input type="text" id="cfgNas"></div>
                    <div class="pq-fld"><label>網頁讀取路徑（URL）</label><input type="text" id="cfgUrl"></div>
                </div>
                <p class="hint">換 NAS 或搬資料夾時，把資料夾原封不動複製過去、改這裡即可，舊附件立刻讀得到。</p>
            </div>
            <div class="pq-sec" style="max-width:760px;">
                <h5>列印表頭／表尾</h5>
                <div class="pq-grid">
                    <div class="pq-fld"><label>表頭</label><input type="text" id="cfgPh"></div>
                    <div class="pq-fld"><label>表尾</label><input type="text" id="cfgPf"></div>
                </div>
                <button class="pq-btn warm" id="btnSaveCfg"><i class="fa fa-save"></i> 儲存設定</button>
            </div>
        </div>
        <?php endif; ?>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- ══ 申請單編輯 ══ -->
<div class="pq-mask" id="mReq"><div class="pq-modal wide">
    <div class="m-head"><span id="reqTitle">提出採購申請</span><span class="m-close" onclick="closeMask('mReq')">✕</span></div>
    <div class="m-body">
        <div class="pq-grid">
            <div class="pq-fld"><label>標題</label><input type="text" id="rTitle" placeholder="例：三廠鑽頭補貨"></div>
            <div class="pq-fld"><label>希望到貨日</label><input type="date" id="rNeed"></div>
            <div class="pq-fld"><label>申請人／部門</label><input type="text" id="rWho" readonly style="background:#F5EEE2;"></div>
        </div>
        <div class="pq-fld" style="margin-bottom:10px;"><label>申請事由</label><textarea id="rReason"></textarea></div>

        <div class="pq-sec">
            <h5>品項（申請時單價可以留白，價錢由採購詢價後填）</h5>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
                <select id="pkCat" style="height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">全部類別</option></select>
                <select id="pkTag" style="height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">全部標籤</option></select>
                <input type="text" id="pkKw" placeholder="搜採購品（例：鑽頭 5）" style="height:28px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;width:220px;">
                <button class="pq-btn" id="pkGo"><i class="fa fa-search"></i> 找採購品</button>
                <button class="pq-btn" id="pkFree"><i class="fa fa-pencil"></i> 主檔沒有，直接手打</button>
            </div>
            <div id="pkResult" class="hint">在上方搜尋採購品後點選加入；主檔沒有的東西可直接手打，採購到貨前再建檔。</div>
            <div class="pq-wrap" style="margin-top:8px;">
                <table class="pq-table" id="reqItemTable">
                    <thead><tr><th style="width:26%;">品名</th><th style="width:20%;">規格</th><th style="width:9%;">數量</th>
                        <th style="width:8%;">單位</th><th style="width:11%;">預估單價</th><th style="width:12%;">到貨處理</th>
                        <th>備註</th><th style="width:6%;">急件</th><th style="width:5%;"></th></tr></thead>
                    <tbody id="reqItemBody"></tbody>
                </table>
            </div>
            <p class="hint" style="margin-top:4px;">到貨處理：<b>入庫待領</b>＝進儲位列管；<b>直接交付請購人</b>＝到貨自動一進一出、庫存淨值 0 但留有領用紀錄；<b>不列管</b>＝純費用不進庫存。</p>
        </div>

        <div class="pq-sec">
            <h5>附件（估價單／發票／收據，新增中就能上傳）</h5>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <select id="attType" style="height:28px;border:1px solid #D8BE93;border-radius:4px;">
                    <option value="quote">估價單</option><option value="invoice">發票</option>
                    <option value="receipt">收據</option><option value="other" selected>其他</option>
                </select>
                <input type="file" id="attFile" style="font-size:12px;">
                <button class="pq-btn" id="attUp"><i class="fa fa-upload"></i> 上傳</button>
                <span class="hint">支援圖片／PDF／Office，單檔 20MB 內</span>
            </div>
            <div id="attList" style="margin-top:6px;"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mReq')">取消</button>
        <button class="pq-btn warm" id="btnSaveReq"><i class="fa fa-save"></i> 送出申請</button>
    </div>
</div></div>

<!-- ══ 單據詳情 ══ -->
<div class="pq-mask" id="mDetail"><div class="pq-modal wide">
    <div class="m-head"><span id="dtTitle">採購單</span><span class="m-close" onclick="closeMask('mDetail')">✕</span></div>
    <div class="m-body" id="dtBody"></div>
    <div class="m-foot" id="dtFoot"></div>
</div></div>

<!-- ══ 採購詢價填價 ══ -->
<div class="pq-mask" id="mQuote"><div class="pq-modal wide">
    <div class="m-head"><span>採購詢價／填入實際金額</span><span class="m-close" onclick="closeMask('mQuote')">✕</span></div>
    <div class="m-body">
        <div class="pq-grid">
            <div class="pq-fld"><label>廠商</label>
                <input type="text" id="qVendor" placeholder="輸入廠商名稱搜尋" autocomplete="off">
                <div id="qVendorList"></div></div>
            <div class="pq-fld"><label>稅別</label><select id="qTax"></select></div>
            <div class="pq-fld"><label>付款方式</label><input type="text" id="qPayMethod" placeholder="匯款／月結／現金"></div>
            <div class="pq-fld"><label>預計到貨日</label><input type="date" id="qExpect"></div>
        </div>
        <div class="pq-wrap">
            <table class="pq-table" id="quoteTable">
                <thead><tr><th>品名</th><th>規格</th><th>數量</th><th>單位</th><th style="width:13%;">實際單價</th>
                    <th style="width:14%;">到貨處理</th><th style="width:16%;">入庫儲位</th><th>小計</th></tr></thead>
                <tbody id="quoteBody"></tbody>
            </table>
        </div>
        <div class="pq-stat" style="margin-top:10px;">
            <div><span class="n money" id="qSub">0</span> <span class="l">未稅小計</span></div>
            <div><span class="n money" id="qTaxAmt">0</span> <span class="l">稅額</span></div>
            <div><span class="n money" id="qGrand">0</span> <span class="l">含稅總額</span></div>
            <div class="l" id="qLevelHint" style="margin-left:auto;"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mQuote')">取消</button>
        <button class="pq-btn warm" id="btnSaveQuote"><i class="fa fa-check"></i> 送出核價</button>
    </div>
</div></div>

<!-- ══ 到貨 ══ -->
<div class="pq-mask" id="mRecv"><div class="pq-modal wide">
    <div class="m-head"><span>登錄到貨</span><span class="m-close" onclick="closeMask('mRecv')">✕</span></div>
    <div class="m-body">
        <div class="pq-grid"><div class="pq-fld"><label>到貨日期</label><input type="date" id="rcDate"></div></div>
        <div class="pq-wrap">
            <table class="pq-table" id="recvTable">
                <thead><tr><th>品名</th><th>規格</th><th>已到／應到</th><th style="width:10%;">本次到貨</th>
                    <th style="width:14%;">到貨處理</th><th style="width:15%;">入庫儲位</th><th style="width:15%;">交付對象</th><th>備註</th></tr></thead>
                <tbody id="recvBody"></tbody>
            </table>
        </div>
        <p class="hint" style="margin-top:6px;">未在主檔建檔的品項無法入庫，請先按該列的「建檔」把它掛到採購品主檔（或選「不列管」）。</p>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mRecv')">取消</button>
        <button class="pq-btn warm" id="btnSaveRecv"><i class="fa fa-check"></i> 確認到貨</button>
    </div>
</div></div>

<!-- ══ 記帳 ══ -->
<div class="pq-mask" id="mAcct"><div class="pq-modal">
    <div class="m-head"><span>發票與付款</span><span class="m-close" onclick="closeMask('mAcct')">✕</span></div>
    <div class="m-body">
        <div class="pq-grid">
            <div class="pq-fld"><label>發票號碼</label><input type="text" id="aInvNo" placeholder="AB12345678"></div>
            <div class="pq-fld"><label>發票日期</label><input type="date" id="aInvDate"></div>
            <div class="pq-fld"><label>付款狀態</label><select id="aPayStatus"><option value="unpaid">未付</option><option value="paid">已付</option></select></div>
            <div class="pq-fld"><label>付款日</label><input type="date" id="aPayDate"></div>
            <div class="pq-fld"><label>付款方式</label><input type="text" id="aPayMethod"></div>
        </div>
        <p class="hint">發票與付款可以事後補；未付款的單會集中在「未付款」分頁。</p>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mAcct')">取消</button>
        <button class="pq-btn warm" id="btnSaveAcct"><i class="fa fa-save"></i> 儲存</button>
    </div>
</div></div>

<!-- ══ 簽核 ══ -->
<div class="pq-mask" id="mSign"><div class="pq-modal">
    <div class="m-head"><span>採購簽核</span><span class="m-close" onclick="closeMask('mSign')">✕</span></div>
    <div class="m-body" id="signBody"></div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mSign')">取消</button>
        <button class="pq-btn danger" id="btnReject"><i class="fa fa-times"></i> 駁回</button>
        <button class="pq-btn warm" id="btnApprove"><i class="fa fa-check"></i> 核准</button>
    </div>
</div></div>

<!-- ══ 品項編輯 ══ -->
<div class="pq-mask" id="mItem"><div class="pq-modal wide">
    <div class="m-head"><span id="itTitle">新增品項</span><span class="m-close" onclick="closeMask('mItem')">✕</span></div>
    <div class="m-body">
        <div class="pq-grid">
            <div class="pq-fld"><label>類別 <span style="color:#DD5138;">*</span></label><select id="itCat"></select></div>
            <div class="pq-fld"><label>品名 <span style="color:#DD5138;">*</span></label>
                <input type="text" id="itName" placeholder="例：鑽頭（尺寸請放在下方規格）" autocomplete="off">
                <div id="itDup"></div></div>
            <div class="pq-fld"><label>預設單位</label><select id="itUnit"></select></div>
            <div class="pq-fld"><label>預設廠商</label><input type="text" id="itVendor" placeholder="輸入搜尋" autocomplete="off">
                <div id="itVendorList"></div></div>
        </div>
        <div class="pq-fld" style="margin-bottom:10px;"><label>標籤（可複選，之後可依標籤篩選）</label>
            <div id="itTags" style="border:1px solid #D8BE93;border-radius:4px;padding:6px;min-height:32px;"></div></div>
        <div class="pq-fld" style="margin-bottom:10px;"><label>備註</label><input type="text" id="itNote"></div>

        <div class="pq-sec" id="specSec">
            <h5>規格變體（＝實際採購料號；同一品項的不同尺寸放這裡，不用另建品項）</h5>
            <div class="pq-wrap">
                <table class="pq-table" id="specTable">
                    <thead><tr><th>採購料號</th><th>規格</th><th>單位</th><th>預設儲位</th>
                        <th>安全存量</th><th>目前庫存</th><th>最近採購價</th><th class="no-print">操作</th></tr></thead>
                    <tbody id="specBody"></tbody>
                </table>
            </div>
            <button class="pq-btn warm" id="btnAddSpec" style="margin-top:8px;"><i class="fa fa-plus"></i> 新增規格</button>
            <span class="hint" id="specHint" style="margin-left:8px;"></span>
        </div>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mItem')">關閉</button>
        <button class="pq-btn warm" id="btnSaveItem"><i class="fa fa-save"></i> 儲存品項</button>
    </div>
</div></div>

<!-- ══ 規格編輯 ══ -->
<div class="pq-mask" id="mSpec"><div class="pq-modal">
    <div class="m-head"><span id="spTitle">新增規格</span><span class="m-close" onclick="closeMask('mSpec')">✕</span></div>
    <div class="m-body">
        <div id="spAttrs" class="pq-grid"></div>
        <div class="pq-grid">
            <div class="pq-fld"><label>規格說明（留白則由上方屬性自動組出）</label><input type="text" id="spText"></div>
            <div class="pq-fld"><label>單位</label><select id="spUnit"></select></div>
            <div class="pq-fld"><label>預設儲位</label><select id="spLoc"></select></div>
            <div class="pq-fld"><label>安全存量</label><input type="number" id="spSafe" step="0.01"></div>
        </div>
        <p class="hint">屬性欄位是依「類別」設定的（設定 → 規格屬性設定），這樣同類別的規格命名才會一致、之後也能依屬性篩選。</p>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mSpec')">取消</button>
        <button class="pq-btn warm" id="btnSaveSpec"><i class="fa fa-save"></i> 儲存規格</button>
    </div>
</div></div>

<!-- ══ 標籤管理 ══ -->
<div class="pq-mask" id="mTagMgr"><div class="pq-modal">
    <div class="m-head"><span>標籤管理</span><span class="m-close" onclick="closeMask('mTagMgr')">✕</span></div>
    <div class="m-body">
        <div style="display:flex;gap:6px;margin-bottom:8px;">
            <input type="text" id="tgName" placeholder="新標籤名稱（例：常備品、危險品）" style="flex:1;border:1px solid #D8BE93;border-radius:4px;padding:4px 8px;">
            <button class="pq-btn warm" id="tgAdd"><i class="fa fa-plus"></i> 新增</button>
        </div>
        <div class="pq-wrap"><table class="pq-table"><thead><tr><th>標籤</th><th>色碼</th><th>操作</th></tr></thead>
            <tbody id="tgBody"></tbody></table></div>
    </div>
    <div class="m-foot"><button class="pq-btn" onclick="closeMask('mTagMgr')">關閉</button></div>
</div></div>

<!-- ══ 規格屬性設定 ══ -->
<div class="pq-mask" id="mAttrMgr"><div class="pq-modal">
    <div class="m-head"><span>規格屬性設定（依類別）</span><span class="m-close" onclick="closeMask('mAttrMgr')">✕</span></div>
    <div class="m-body">
        <div class="pq-grid">
            <div class="pq-fld"><label>類別</label><select id="amCat"></select></div>
        </div>
        <div class="pq-wrap"><table class="pq-table"><thead><tr><th>屬性名稱</th><th>型別</th><th>選項</th><th>單位</th><th>操作</th></tr></thead>
            <tbody id="amBody"></tbody></table></div>
        <div class="pq-grid" style="margin-top:10px;">
            <div class="pq-fld"><label>新屬性名稱</label><input type="text" id="amName" placeholder="例：直徑"></div>
            <div class="pq-fld"><label>型別</label><select id="amType">
                <option value="text">文字</option><option value="number">數值</option><option value="select">下拉選項</option></select></div>
            <div class="pq-fld"><label>選項（下拉用，逗號分隔）</label><input type="text" id="amOpts" placeholder="HSS,鎢鋼"></div>
            <div class="pq-fld"><label>單位提示</label><input type="text" id="amUnit" placeholder="mm"></div>
        </div>
        <button class="pq-btn warm" id="amAdd"><i class="fa fa-plus"></i> 新增屬性</button>
    </div>
    <div class="m-foot"><button class="pq-btn" onclick="closeMask('mAttrMgr')">關閉</button></div>
</div></div>

<!-- ══ 刪除原因 ══ -->
<div class="pq-mask" id="mDel"><div class="pq-modal" style="max-width:520px;">
    <div class="m-head"><span>刪除採購單</span><span class="m-close" onclick="closeMask('mDel')">✕</span></div>
    <div class="m-body">
        <p id="delWho" class="hint"></p>
        <div class="pq-fld"><label>刪除原因（必填）</label><textarea id="delReason"></textarea></div>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mDel')">取消</button>
        <button class="pq-btn danger" id="btnDoDel"><i class="fa fa-trash"></i> 確認刪除</button>
    </div>
</div></div>

<!-- ══ 角色說明 ══ -->
<div class="pq-mask" id="mRole"><div class="pq-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('mRole')">✕</span></div>
    <div class="m-body">
        <table class="pq-table"><thead><tr><th>角色</th><th>可做的事</th></tr></thead><tbody>
        <tr><td>申請採購</td><td class="l">提出／修改自己的申請單、上傳附件、查看自己的單</td></tr>
        <tr><td>到貨入庫</td><td class="l">上列全部，加上登錄到貨（入庫／直接交付／不列管）</td></tr>
        <tr><td>採購作業</td><td class="l">上列全部，加上詢價填金額、下單、記帳、結案、維護採購品主檔</td></tr>
        <tr><td>採購管理員</td><td class="l">上列全部，加上標籤／規格屬性設定、簽核門檻與附件路徑設定、刪除任何單據</td></tr>
        <tr><td>高階核准</td><td class="l">金額超過第二層門檻時的第二關簽核人</td></tr>
        <tr><td>採購檢閱</td><td class="l">唯讀查看全部單據與統計</td></tr>
        <tr><td>管理者</td><td class="l">固定擁有全部權限</td></tr>
        </tbody></table>
        <p class="hint" style="margin-top:8px;">第一層簽核人＝申請人的部門主管，由系統依代理人設定自動解析（主管當日有行程時交代理人；代理人就是申請人時自動升一級迴避）。</p>
    </div>
    <div class="m-foot"><button class="pq-btn" onclick="closeMask('mRole')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
/* 左側欄：上方 CSS 先把 #sidebar-menu 設 visibility:hidden 以避免子選單全展開的閃爍，
   custom.min.js 收合完成後必須在這裡手動還原 visible——這兩段是成套的，
   只抄 hidden 沒抄還原，整個左側欄會消失（2026-07-29 本頁踩過）。 */
$(document).ready(function () {
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility', 'visible');
});
</script>
<script src="../../resource/js/purchase_request.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/purchase_request.js') ?: time() ?>"></script>
</body>
</html>
