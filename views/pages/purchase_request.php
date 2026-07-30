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
$roleLabel = purchase_role_names($db, (int)($pqUser['id'] ?? 0), $perms);   // 顯示管理員自訂的角色名稱
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
/* 角色權限設定：三欄（角色清單／角色內容／擁有者） */
.pq-role-mgr{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;}
.pq-role-col{border:1px solid var(--pq-line);border-radius:6px;background:#fff;min-width:200px;}
.pq-role-hd{background:var(--pq-soft);color:var(--pq-deep);font-size:12px;font-weight:bold;
  padding:5px 10px;border-radius:6px 6px 0 0;display:flex;justify-content:space-between;align-items:center;}
.pq-role-item{padding:6px 10px;border-bottom:1px solid var(--pq-line);cursor:pointer;font-size:13px;}
.pq-role-item:hover{background:#FFF3E0;}
.pq-role-item.on{background:#F0A24B;color:#fff;font-weight:bold;}
.pq-role-item.sys{color:#a5866a;cursor:not-allowed;}
.pq-feat{display:block;font-size:13px;font-weight:normal;padding:2px 0;cursor:pointer;}
.pq-feat input{width:auto;margin:0 6px 0 0;}
/* 附件類別：改成標籤直接點，比下拉少一次操作 */
.att-tag{display:inline-block;border:1px solid #D8BE93;background:#fff;color:var(--pq-ink);
  border-radius:12px;padding:2px 12px;font-size:12px;cursor:pointer;margin-right:4px;}
.att-tag.on{background:#F0A24B;border-color:#F0A24B;color:#fff;font-weight:bold;}
/* 用途選擇是「開在申請單之上」的第二層 modal，明確墊高避免被蓋住 */
#mPurpose{z-index:2100;}
/* 用途歸屬：申請單最重要的一格，給它獨立區塊 */
.pq-purpose{border:1px solid #E2C58F;background:#FFF7EA;border-radius:6px;padding:10px 12px;margin-bottom:12px;}
.pq-purpose-lb{display:block;font-size:13px;font-weight:bold;color:var(--pq-deep);margin-bottom:6px;}
.pq-purpose-box{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.pq-purpose-box .pq-purpose-none{color:#a5866a;font-size:13px;}
.pq-purpose-tag{display:inline-flex;align-items:center;gap:6px;background:#F0A24B;color:#fff;
  border-radius:12px;padding:3px 12px;font-size:13px;}
.pq-purpose-tag .k{background:rgba(255,255,255,.28);border-radius:8px;padding:0 7px;font-size:11px;}
/* 逐列覆寫用的小標籤 */
.pq-pp-cell{display:flex;align-items:center;gap:4px;flex-wrap:wrap;font-size:12px;}
.pq-pp-same{color:#a5866a;cursor:pointer;text-decoration:underline dotted;}
.pq-pp-set{background:#F7E0BD;color:#6b4415;border-radius:10px;padding:1px 8px;cursor:pointer;}
.pq-pp-clr{color:#DD5138;cursor:pointer;font-weight:bold;}
/* 用途選擇 modal 的搜尋結果 */
.pp-row{padding:5px 8px;border-bottom:1px solid var(--pq-line);cursor:pointer;font-size:13px;}
.pp-row:hover{background:#FFF3E0;}
.pp-row .m{font-weight:bold;color:var(--pq-deep);}
.pp-row .s{color:var(--pq-ink);}
.pp-row .x{color:var(--pq-ink2);font-size:12px;}
#ppList{max-height:300px;overflow:auto;border:1px solid var(--pq-line);border-radius:4px;background:#fff;margin-top:6px;}
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

<?php if (!$perms['canEnter']): ?>
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
            <?php if ($perms['canView']): ?>
            <button class="pq-tab" data-tab="all">📚 全部單據</button>
            <?php endif; ?>
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
                        <th>單號</th><th>標題</th><th>用途</th><th>申請人</th><th>部門</th><th>品項</th>
                        <th>廠商</th><th>含稅總額</th><th>狀態</th><th>付款</th><th>申請日</th><th class="no-print">操作</th>
                    </tr></thead>
                    <tbody id="listBody"><tr><td colspan="12" class="pq-empty">載入中…</td></tr></tbody>
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
                <h5>附件儲存路徑（DB 只存檔名，完整路徑由此設定即時組出）</h5>
                <div class="pq-fld">
                    <label>實體存放路徑（磁碟或網路資料夾）</label>
                    <input type="text" id="cfgNas" placeholder="例：Z:\BOM\ERP\採購　或　\\excellentnas\AS9100維護\ERP AS9100文件(勿刪)\採購">
                </div>
                <div id="cfgNasState" class="hint" style="margin-top:6px;"></div>
                <p class="hint" style="margin-top:6px;">
                    只需要填這一個。附件下載一律由系統讀檔後送出（會檢查權限），
                    <b>所以網路磁碟代號（Z:\…）和 UNC 路徑（\\\\主機\\分享\\…）都可以直接填</b>，不必是能被瀏覽器直接開的位置。<br>
                    換 NAS 或搬資料夾時，把資料夾原封不動複製過去、改這裡即可，舊附件立刻讀得到。
                    每張單會在底下自動開一個以單號命名的子資料夾（新增中還沒存檔的附件先放 <code>_temp</code>）。
                </p>
            </div>
            <div class="pq-sec" style="max-width:760px;">
                <h5>列印表頭／表尾</h5>
                <div class="pq-grid">
                    <div class="pq-fld"><label>表頭</label><input type="text" id="cfgPh"></div>
                    <div class="pq-fld"><label>表尾</label><input type="text" id="cfgPf"></div>
                </div>
                <button class="pq-btn warm" id="btnSaveCfg"><i class="fa fa-save"></i> 儲存設定</button>
            </div>

            <?php if ($perms['isAdmin']): ?>
            <!-- 角色權限：名稱與可操作/可視內容都由管理員自訂（沿用全站 Roles_API + role_features） -->
            <div class="pq-sec">
                <h5>角色權限設定（角色名稱與能做／看得到什麼，都由你自己定）</h5>
                <p class="hint" style="margin-bottom:8px;">
                    左邊選或新增角色 → 右邊改名稱、勾這個角色能看到什麼／能做什麼。
                    <b>權限由上而下包含</b>：勾了「詢價下單」就自動含到貨入庫、申請、檢閱，不必逐個勾。
                    此處為本頁專用角色，與其他頁面不連動；「管理者」固定擁有全部權限、不可修改。
                </p>
                <p class="hint" style="margin-bottom:8px;padding:6px 10px;background:#FFF7EA;border:1px solid #E2C58F;border-radius:4px;">
                    <i class="fa fa-info-circle"></i> <b>「誰擁有這個角色」不在這裡設定</b>——人員對應角色全站統一在
                    <a href="../user/user_permissions.php#purc-role-section" target="_blank"><b>人員權限設定頁</b></a>
                    的「申請採購 角色指派」區塊，才不會兩個地方各改一半。這裡只負責定義角色的名稱與內容。
                </p>
                <div class="pq-role-mgr">
                    <div class="pq-role-col" style="flex:0 0 210px;">
                        <div class="pq-role-hd">角色
                            <button class="pq-btn warm" id="btnRoleAdd" style="padding:1px 8px;">＋ 新增</button></div>
                        <div id="roleList"></div>
                    </div>
                    <div class="pq-role-col" style="flex:1;">
                        <div class="pq-role-hd">角色內容</div>
                        <div id="roleEdit" style="display:none;padding:10px;">
                            <div class="pq-fld" style="margin-bottom:8px;"><label>角色名稱</label>
                                <div style="display:flex;gap:6px;">
                                    <input type="text" id="roleName" style="flex:1;">
                                    <button class="pq-btn" id="btnRoleRename">改名</button>
                                    <button class="pq-btn danger" id="btnRoleDel">刪除</button>
                                </div>
                            </div>
                            <div style="font-size:12px;font-weight:bold;color:var(--pq-deep);margin:8px 0 4px;">可視內容（看得到什麼）</div>
                            <div id="featView"></div>
                            <div style="font-size:12px;font-weight:bold;color:var(--pq-deep);margin:10px 0 4px;">可操作（能做什麼）</div>
                            <div id="featOp"></div>
                            <button class="pq-btn warm" id="btnRoleFeatSave" style="margin-top:10px;">
                                <i class="fa fa-save"></i> 儲存功能</button>
                            <p class="hint" style="margin-top:6px;">「看得到金額／廠商」沒勾的人，連 API 回應裡都拿不到這些欄位；
                               但<b>自己提的單</b>與<b>輪到自己簽核的單</b>一律看得到（不然沒辦法簽）。</p>
                        </div>
                        <div id="roleEditHint" class="hint" style="padding:24px;text-align:center;">請在左側選一個角色，或按「＋ 新增」</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
        <div class="pq-purpose">
            <label class="pq-purpose-lb">這筆採購是為了什麼？ <span style="color:#DD5138;">*</span></label>
            <div class="pq-purpose-box">
                <span id="rPurposeShow" class="pq-purpose-none">尚未選擇</span>
                <button class="pq-btn warm" id="btnPickPurpose"><i class="fa fa-crosshairs"></i> 選擇用途</button>
            </div>
            <p class="hint" style="margin:4px 0 0;">選了訂單／BOM／料號，這筆花費才算得進該筆的成本；耗材、辦公用品請選「常備品補貨」。</p>
        </div>
        <div class="pq-grid">
            <div class="pq-fld"><label>希望到貨日</label><input type="date" id="rNeed"></div>
            <div class="pq-fld"><label>申請人／部門</label><input type="text" id="rWho" readonly style="background:#F5EEE2;"></div>
            <div class="pq-fld"><label>急件</label>
                <label style="display:flex;align-items:center;gap:6px;height:30px;font-weight:normal;cursor:pointer;">
                    <input type="checkbox" id="rUrgent" style="width:auto;margin:0;"> 整張單都是急件
                </label>
                <span class="hint">也可以只勾下面某幾項</span></div>
        </div>
        <div class="pq-fld" style="margin-bottom:10px;"><label>補充說明（選填）</label><textarea id="rReason" placeholder="有特別要求再寫，例：要同一廠牌、附發票"></textarea></div>
        <!-- 標題由後端自動組（用途＋品名），一般使用者看不到也不用填；採購版才開放手改 -->
        <div class="pq-fld pq-full-only" style="margin-bottom:10px;"><label>標題（留白＝自動帶入用途＋品名）</label><input type="text" id="rTitle" placeholder="留白就好"></div>

        <div class="pq-sec">
            <h5 id="reqItemSecTitle">要買什麼</h5>
            <!-- 找採購品：一般使用者不必先查主檔，直接打品名就好；採購版才需要綁採購料號 -->
            <div class="pq-full-only" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
                <select id="pkCat" style="height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">全部類別</option></select>
                <select id="pkTag" style="height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">全部標籤</option></select>
                <input type="text" id="pkKw" placeholder="搜採購品（例：鑽頭 5）" style="height:28px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;width:220px;">
                <button class="pq-btn" id="pkGo"><i class="fa fa-search"></i> 找採購品</button>
                <button class="pq-btn" id="pkFree"><i class="fa fa-pencil"></i> 直接手打</button>
            </div>
            <div id="pkResult" class="hint pq-full-only">在上方搜尋採購品後點選加入；主檔沒有的東西可直接手打。</div>
            <div class="pq-wrap" style="margin-top:8px;">
                <table class="pq-table" id="reqItemTable">
                    <thead><tr id="reqItemHead"></tr></thead>
                    <tbody id="reqItemBody"></tbody>
                </table>
            </div>
            <div style="margin-top:6px;">
                <button class="pq-btn" id="btnAddRow"><i class="fa fa-plus"></i> 再加一項</button>
            </div>
            <p class="hint" style="margin-top:4px;">「用途」預設沿用上面選的，整張單同一個用途就不用理它；某一項是為了別的訂單／料號才點開改。「急」可以只勾其中幾項。</p>
        </div>

        <div class="pq-sec">
            <h5>附件（選填，現在就能上傳）</h5>
            <!-- 附件分類改成標籤直接點（不用下拉）；一般使用者連分類都不必選，一律歸「其他」 -->
            <div class="pq-full-only" id="attTagWrap" style="margin-bottom:6px;">
                <span class="hint" style="margin-right:6px;">類別</span>
                <span class="att-tag" data-v="quote">估價單</span>
                <span class="att-tag" data-v="invoice">發票</span>
                <span class="att-tag" data-v="receipt">收據</span>
                <span class="att-tag on" data-v="other">其他</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <!-- 選檔與上傳合成一個動作：選好就自動送，不必再按一次「上傳」 -->
                <label class="pq-btn warm" id="attPick" style="margin:0;display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                    <i class="fa fa-paperclip"></i> 選擇檔案（可多選）
                    <input type="file" id="attFile" multiple style="display:none;">
                </label>
                <span class="hint" id="attHint">選好就自動上傳．支援圖片／PDF／Office，單檔 20MB 內</span>
            </div>
            <div id="attList" style="margin-top:6px;"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mReq')">取消</button>
        <button class="pq-btn warm" id="btnSaveReq"><i class="fa fa-save"></i> 送出申請</button>
    </div>
</div></div>

<!-- ══ 用途歸屬選擇（單頭與逐列共用同一個） ══ -->
<div class="pq-mask" id="mPurpose"><div class="pq-modal">
    <div class="m-head"><span id="ppTitle">選擇用途</span><span class="m-close" onclick="closeMask('mPurpose')">✕</span></div>
    <div class="m-body">
        <div class="pq-fld" style="margin-bottom:10px;">
            <label>用途類別</label>
            <select id="ppType"></select>
        </div>
        <div id="ppPickWrap" style="display:none;">
            <div class="pq-fld">
                <label id="ppKwLabel">搜尋</label>
                <input type="text" id="ppKw" placeholder="邊打邊找，不必按按鈕" autocomplete="off">
            </div>
            <div id="ppList"></div>
            <p class="hint" style="margin-top:4px;" id="ppPickHint"></p>
        </div>
        <div class="pq-fld" id="ppNoteWrap" style="display:none;margin-top:10px;">
            <label id="ppNoteLabel">說明</label>
            <input type="text" id="ppNote" placeholder="簡單寫一下是為了什麼">
        </div>
        <div style="margin-top:10px;padding:8px 10px;background:var(--pq-bg);border-radius:4px;">
            目前選擇：<span id="ppPreview" class="hint">尚未選擇</span>
        </div>
    </div>
    <div class="m-foot">
        <button class="pq-btn" id="ppClear" style="float:left;">改回沿用單頭</button>
        <button class="pq-btn" onclick="closeMask('mPurpose')">取消</button>
        <button class="pq-btn warm" id="ppOk"><i class="fa fa-check"></i> 確定</button>
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
                <thead>
                    <tr>
                        <th colspan="4" style="background:#F3E2C7;">申請內容（唯讀，不可修改）</th>
                        <th colspan="9" style="background:var(--pq-soft);">採購實際登錄（品名／規格／數量留白＝同申請）</th>
                    </tr>
                    <tr><th>品名</th><th>規格</th><th>數量</th><th>單位</th>
                        <th>採購料號</th><th>實際品名</th><th>實際規格</th><th>數量</th>
                        <th>實際單價</th><th>到貨處理</th><th>入庫儲位</th><th>採購備註</th><th>小計</th></tr>
                </thead>
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
                <thead><tr><th>品名</th><th>規格</th><th style="width:13%;">採購料號</th><th>已到／應到</th><th style="width:9%;">本次到貨</th>
                    <th style="width:12%;">到貨處理</th><th style="width:13%;">入庫儲位</th><th style="width:13%;">交付對象</th><th>備註</th></tr></thead>
                <tbody id="recvBody"></tbody>
            </table>
        </div>
        <p class="hint" style="margin-top:6px;">沒有採購料號的品項無法入庫：請按該列「綁定料號」，把它綁到既有採購料號、或當場建一支新的（或把該列改成「不列管」）。</p>
    </div>
    <div class="m-foot">
        <button class="pq-btn" onclick="closeMask('mRecv')">取消</button>
        <button class="pq-btn warm" id="btnSaveRecv"><i class="fa fa-check"></i> 確認到貨</button>
    </div>
</div></div>

<!-- ══ 綁定採購料號（採購在詢價／到貨時用；可綁既有料號，也可以真的建一個新料號） ══ -->
<div class="pq-mask" id="mBind"><div class="pq-modal wide">
    <div class="m-head"><span>採購料號</span><span class="m-close" onclick="closeMask('mBind')">✕</span></div>
    <div class="m-body">
        <div id="bdInfo" style="background:var(--pq-bg);border:1px solid var(--pq-line);border-radius:4px;padding:8px 10px;margin-bottom:8px;"></div>
        <div class="pq-tabs" id="bdTabs" style="margin:0 0 10px;">
            <button class="pq-tab on" data-bd="exist"><i class="fa fa-search"></i> 綁定現有採購料號</button>
            <button class="pq-tab" data-bd="new"><i class="fa fa-plus"></i> 建立新採購料號</button>
        </div>

        <!-- ── 綁既有 ── -->
        <div id="bdExist">
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
                <select id="bdFCat" style="height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">全部類別</option></select>
                <select id="bdFTag" style="height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">全部標籤</option></select>
                <input type="text" id="bdFKw" placeholder="料號／品名／規格（邊打邊找）" autocomplete="off"
                       style="height:28px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;width:260px;">
                <button class="pq-btn" id="bdFGo"><i class="fa fa-search"></i> 查詢</button>
            </div>
            <div id="bdResult" class="hint">預設帶入申請的品名去找；找不到就切到「建立新採購料號」。</div>
        </div>

        <!-- ── 建新的 ── -->
        <div id="bdNew" style="display:none;">
            <div class="pq-grid">
                <div class="pq-fld"><label>類別 <span style="color:#DD5138;">*</span></label><select id="bdCat"></select></div>
                <div class="pq-fld"><label>品項要掛哪裡 <span style="color:#DD5138;">*</span></label>
                    <div style="display:flex;gap:12px;align-items:center;height:30px;font-weight:normal;">
                        <label style="margin:0;font-weight:normal;cursor:pointer;"><input type="radio" name="bdItemMode" value="exist" style="width:auto;margin:0 4px 0 0;" checked> 掛在既有品項</label>
                        <label style="margin:0;font-weight:normal;cursor:pointer;"><input type="radio" name="bdItemMode" value="new" style="width:auto;margin:0 4px 0 0;"> 建立新品項</label>
                    </div>
                </div>
            </div>
            <div class="pq-grid" id="bdItemExistWrap">
                <div class="pq-fld" style="grid-column:1/-1;"><label>選既有品項（同一種東西的不同尺寸請掛在同一個品項下）</label>
                    <select id="bdItemSel"></select>
                    <div id="bdItemSpecs" class="hint" style="margin-top:4px;"></div>
                </div>
            </div>
            <div class="pq-grid" id="bdItemNewWrap" style="display:none;">
                <div class="pq-fld"><label>新品項品名 <span style="color:#DD5138;">*</span></label>
                    <input type="text" id="bdItemName" placeholder="例：鑽頭（尺寸放在下面規格，不要寫在品名裡）" autocomplete="off">
                    <div id="bdItemDup"></div></div>
            </div>

            <div class="pq-sec" style="margin-top:4px;">
                <h5>規格（＝這一筆採購料號代表什麼）</h5>
                <div id="bdAttrs" class="pq-grid"></div>
                <div class="pq-grid">
                    <div class="pq-fld"><label>規格說明 <span style="color:#DD5138;">*</span></label>
                        <input type="text" id="bdSpecText" placeholder="留白則由上方屬性自動組出">
                        <span class="hint" id="bdSpecHint"></span></div>
                    <div class="pq-fld"><label>採購料號</label>
                        <div style="display:flex;gap:4px;">
                            <input type="text" id="bdSpecCode" placeholder="留白＝系統自動編號" maxlength="40">
                            <button class="pq-btn" id="bdCodeAuto" title="重新取得系統建議編號" style="white-space:nowrap;">建議號</button>
                        </div>
                        <span class="hint" id="bdCodeHint">可自行輸入公司慣用編號，全系統不可重複。</span></div>
                    <div class="pq-fld"><label>單位</label><select id="bdUnit"></select></div>
                    <div class="pq-fld"><label>預設儲位</label><select id="bdLoc"></select></div>
                    <div class="pq-fld"><label>安全存量</label><input type="number" id="bdSafe" step="0.01"></div>
                </div>
                <div id="bdErr" style="display:none;color:#DD5138;font-size:13px;margin-top:4px;"></div>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button class="pq-btn danger" id="bdClear" style="float:left;display:none;"><i class="fa fa-unlink"></i> 解除綁定</button>
        <button class="pq-btn" onclick="closeMask('mBind')">取消</button>
        <button class="pq-btn warm" id="bdSave" style="display:none;"><i class="fa fa-save"></i> 建立並綁定</button>
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
            <div class="pq-fld"><label>採購料號（留白＝自動編號，可自行輸入公司慣用編號）</label>
                <input type="text" id="spCode" maxlength="40" placeholder="留白＝自動編號"></div>
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
        <p class="hint" style="margin-bottom:8px;">角色名稱與內容都是管理員自己設定的，以下是<b>目前實際的設定</b>。
            管理員可在「設定」分頁的「角色權限設定」改角色名稱與內容；
            <b>人員對應角色</b>則統一在<a href="../user/user_permissions.php#purc-role-section" target="_blank">人員權限設定頁</a>指派。</p>
        <div class="pq-wrap">
            <table class="pq-table"><thead><tr><th style="width:24%;">角色</th><th>看得到什麼</th><th>能做什麼</th></tr></thead>
                <tbody id="roleHelpBody"><tr><td colspan="3" class="pq-empty">載入中…</td></tr></tbody>
            </table>
        </div>
        <p class="hint" style="margin-top:8px;">
            權限<b>由上而下包含</b>：勾了「詢價下單」就自動含到貨入庫、申請、檢閱。<br>
            <b>沒有任何角色</b>的人進不了本頁；<b>管理者</b>固定擁有全部權限。<br>
            「看得到金額／廠商」沒勾的人，<b>自己提的單</b>與<b>輪到自己簽核的單</b>仍然看得到（不然沒辦法簽）。<br>
            簽核第一層＝申請人的部門主管，由系統依代理人設定自動解析（主管當日有行程時交代理人；代理人就是申請人時自動升一級迴避）。
        </p>
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
