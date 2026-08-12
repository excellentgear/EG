<?php
// 圖章管理 — 清冊登記（AS9100 圖章管理紀錄）＋ 掃描實體章上傳（去背＋日期帶框選）
// 印模：resource/js/eg_stamp.js（報價單/CAR/AS表單簽核同一套章）；掃描章上傳後三處簽核顯示自動改壓真章。
// 權限：檢閱=所有登入者；登記/上傳/停用/刪除=管理者 or「圖章管理員」角色（user_permissions.php 指派，module=stamp）。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
$db = (new DBConnection())->getPDO();
$ownCompany = '';
try {
    $r = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($r) $ownCompany = $r['customer_full'] ?: ($r['customer'] ?? '');
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>圖章管理 | 清冊登記與掃描章</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
  html{overflow-x:hidden;}
  .right_col{background:#efe7da;font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif;color:#3a2a17;padding:16px;min-height:100vh;}
  .panel-warm{max-width:1100px;margin:0 auto 16px;background:#fff;border:1px solid #d8c19a;border-radius:6px;padding:14px 18px;box-shadow:0 2px 8px rgba(90,61,30,.12);}
  .panel-warm h4{margin:0 0 10px;font-size:15px;color:#7a4e17;border-bottom:2px solid #f0a24b;padding-bottom:6px;}
  table.list{width:100%;border-collapse:collapse;font-size:13px;}
  table.list th{background:#f7e0bd;color:#5a3d1e;padding:6px 8px;border:1px solid #e0cba0;}
  table.list td{padding:5px 8px;border:1px solid #e8d9b8;vertical-align:middle;}
  .status-chip{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;font-weight:bold;}
  .st-active{background:#f7e0bd;color:#5a3d1e;} .st-revoked{background:#e8dcc3;color:#8a7455;}
  input[type=number]::-webkit-inner-spin-button,input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0;}
  input[type=number]{-moz-appearance:textfield;appearance:textfield;}
  .pager{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;}
  .pager .btn{min-width:30px;}
  /* 日期帶框選 */
  #bandBox{position:relative;width:300px;height:300px;border:1px solid #d8c19a;background:
      repeating-conic-gradient(#f3ece0 0 25%, #fff 0 50%) 0 0/20px 20px;user-select:none;margin:0 auto;}
  #bandBox img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none;}
  .band-line{position:absolute;left:0;right:0;height:0;border-top:2px dashed #dd5138;cursor:ns-resize;}
  .band-line::after{content:attr(data-lb);position:absolute;right:2px;top:-16px;font-size:11px;color:#dd5138;background:rgba(255,255,255,.85);padding:0 3px;border-radius:3px;}
  .band-shade{position:absolute;left:0;right:0;background:rgba(240,162,75,.18);pointer-events:none;}
  #roleBadge{float:right;font-size:12px;color:#7a4e17;font-weight:normal;}
  #roleBadge .fa-question-circle{cursor:pointer;color:#f0a24b;font-size:14px;}
  /* 內頁頁籤（暖色系） */
  .warm-tabs{list-style:none;margin:0 0 12px;padding:0;display:flex;gap:4px;border-bottom:2px solid #f0a24b;}
  .warm-tabs li{padding:6px 16px;font-size:13.5px;font-weight:bold;color:#8a7455;background:#f3ece0;border:1px solid #e0cba0;border-bottom:none;border-radius:6px 6px 0 0;cursor:pointer;user-select:none;}
  .warm-tabs li.active{background:#f0a24b;color:#4a2c0a;}
  .warm-tabs li:hover:not(.active){background:#f7e0bd;color:#5a3d1e;}
  /* 新增登記＋篩選列凍結（捲動清冊時固定在上方） */
  #stickyBar{position:sticky;top:0;z-index:30;background:#fff;padding-top:2px;box-shadow:0 4px 6px -4px rgba(90,61,30,.25);}
  /* 模板設計器分割列表格：欄位窄，縮小格內留白讓數字完整顯示，不被截斷 */
  #tplRowsTbl td{padding:3px 4px;}
  #tplRowsTbl input[type=number]{padding:3px 2px;text-align:center;font-size:12px;}
  #tplRowsTbl select{padding:3px 2px;font-size:12px;}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">

<div class="panel-warm">
  <h4><i class="fa fa-certificate"></i> 圖章管理
    <span id="roleBadge">
      <button class="btn btn-info btn-xs" id="btnBatchAdd" style="display:none;" title="多選部門全選成員，可跨模板一次建立多種章"><i class="fa fa-object-group"></i> 批次建立</button>
      <button class="btn btn-default btn-xs" id="btnSettings" style="display:none;" data-toggle="modal" data-target="#settingsModal"><i class="fa fa-cog"></i> 設定</button>
      　目前角色：<strong id="myRole">…</strong>　<i class="fa fa-question-circle" data-toggle="modal" data-target="#permModal" title="權限說明"></i></span>
  </h4>
  <div id="noPermMsg" style="display:none;padding:18px;text-align:center;color:#8a7455;font-size:14px;">
    <i class="fa fa-lock" style="font-size:26px;color:#f0a24b;"></i><br>
    您沒有圖章清冊的檢閱權限。<br>
    <span style="font-size:12px;">為避免圖章被瀏覽轉存，清冊僅開放「圖章檢閱」「圖章管理員」角色與管理者。如有需要請洽管理者至「人員權限設定 → 圖章管理」指派。</span>
  </div>
  <div id="mainArea">
  <ul class="warm-tabs">
    <li class="active" data-tab="reg"><i class="fa fa-list-alt"></i> 圖章清冊（核發/停用登記）</li>
    <li data-tab="tpl"><i class="fa fa-magic"></i> 線上圖章設計（模板）</li>
  </ul>
  <div id="tab-reg">
  <div id="stickyBar">
  <div id="addBar" style="display:none;margin-bottom:10px;padding:8px;background:#fdf6ea;border:1px solid #e8d9b8;border-radius:4px;">
    <strong style="color:#7a4e17;"><i class="fa fa-plus-circle"></i> 新增登記：</strong>
    模板 <select id="addTpl" class="form-control input-sm" style="width:150px;display:inline-block;" title="選模板，種類自動帶入（模板請到「線上圖章設計」頁建立）"></select>
    種類：<span id="addTypeShow" class="text-muted" style="font-size:12.5px;">（請先選模板）</span>
    <select id="addType" style="display:none;"></select>
    <span id="addKindWrap">
      對象別 <select id="addKind" class="form-control input-sm" style="width:96px;display:inline-block;" title="此種類允許的持有對象"></select>
    </span>
    <select id="addUser" class="form-control input-sm" style="width:140px;display:none;"></select>
    <select id="addDept" class="form-control input-sm" style="width:140px;display:none;"></select>
    <select id="addDeptUser" class="form-control input-sm" style="width:140px;display:none;" title="僅列出該部門的在職人員"></select>
    <select id="addPosition" class="form-control input-sm" style="width:120px;display:none;" title="該部門的職稱（現任者異動蓋章自動帶新任者）"></select>
    <input type="date" id="addDate" class="form-control input-sm" style="width:150px;display:inline-block;" max="9999-12-31">
    <input type="text" id="addNote" class="form-control input-sm" style="width:180px;display:inline-block;" placeholder="備註（選填）">
    <button class="btn btn-primary btn-sm" id="btnAdd"><i class="fa fa-plus"></i> 登記核發</button>
    <div class="text-muted" style="font-size:12px;margin-top:4px;">同一持有對象＋同一種類，同時只能有一筆「使用中」。各種類可綁定的持有對象請按右上「設定」維護。</div>
  </div>
  <div class="pager">
    <span style="font-size:12.5px;">
      篩選：<input type="text" id="fltName" class="form-control input-sm" style="width:120px;display:inline-block;" placeholder="持有人姓名">
      <select id="fltType" class="form-control input-sm" style="width:130px;display:inline-block;"></select>
      <select id="fltStatus" class="form-control input-sm" style="width:110px;display:inline-block;">
        <option value="">全部狀態</option><option value="active">使用中</option><option value="revoked">已停用</option>
      </select>
      　<span id="sumInfo" class="text-muted"></span>
    </span>
    <span>
      <button class="btn btn-default btn-xs" id="btnCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
      <button class="btn btn-default btn-xs" id="btnPrint"><i class="fa fa-print"></i> 列印/PDF</button>
      每頁 <select id="perSel" class="form-control input-sm" style="width:62px;display:inline-block;">
        <option>5</option><option selected>10</option><option>20</option><option>50</option></select> 筆
      <span id="pageBtns"></span>
    </span>
  </div>
  </div><!-- /stickyBar -->
  <table class="list"><thead><tr>
    <th style="width:90px;">印模</th><th style="width:100px;">持有人</th><th style="width:100px;">種類</th><th style="width:95px;">核發日期</th>
    <th style="width:100px;">停用/繳回日</th><th style="width:75px;">狀態</th><th>備註</th>
    <th style="width:85px;">掃描實體章</th><th style="width:190px;">操作</th>
  </tr></thead><tbody id="regBody"></tbody></table>
  </div><!-- /tab-reg -->

  <div id="tab-tpl" style="display:none;">
    <div style="margin-bottom:8px;">
      <span class="text-muted" style="font-size:12px;">設計參數化圖章（外框/分割/固定字樣/變數/日期/編號）。批圖編輯器的「蓋章」功能會依<strong>種類</strong>列出模板，變數（部門/職稱/姓名）帶入被登記者資料，編號依跳號規則自動遞增。</span>
      <button class="btn btn-primary btn-sm" id="btnTplNew" style="display:none;float:right;"><i class="fa fa-plus"></i> 新增模板</button>
      <div style="clear:both;"></div>
    </div>
    <table class="list"><thead><tr>
      <th style="width:120px;">預覽</th><th>模板名稱</th><th style="width:110px;">種類</th><th style="width:170px;">編號規則</th>
      <th style="width:70px;">狀態</th><th style="width:150px;">操作</th>
    </tr></thead><tbody id="tplBody"></tbody></table>
  </div><!-- /tab-tpl -->
  </div><!-- /mainArea -->
</div>

<!-- 設定 Modal（種類管理／掃描章儲存路徑，跳窗內分頁） -->
<div class="modal fade" id="settingsModal" tabindex="-1"><div class="modal-dialog" style="width:540px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-cog"></i> 圖章管理設定</h4></div>
  <div class="modal-body">
    <ul class="warm-tabs">
      <li class="active" data-stab="type"><i class="fa fa-tags"></i> 印章種類管理</li>
      <li data-stab="base" id="stabBase" style="display:none;"><i class="fa fa-folder-open-o"></i> 掃描章儲存路徑</li>
    </ul>
    <div id="stab-type">
      <p class="text-muted" style="font-size:12px;">種類做成主檔統一下拉選擇，避免備註各打各的；停用後新登記選不到、舊資料照常顯示。<br>
        <strong>綁定對象</strong>：勾選此種類的登記只能綁哪些持有對象（個人/課室/職稱可複選）；<strong>都不勾＝不綁定（不限制，登記時三種都可選）</strong>。</p>
      <table class="list"><thead><tr><th>種類名稱</th><th style="width:150px;">綁定對象</th><th style="width:60px;">狀態</th><th style="width:150px;">操作</th></tr></thead>
        <tbody id="typeBody"></tbody></table>
      <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="text" id="newTypeName" class="form-control input-sm" style="flex:1;min-width:140px;" placeholder="新種類名稱（如：檢驗章）">
        <label style="font-size:12px;font-weight:normal;"><input type="checkbox" class="newType-bt" value="user"> 個人</label>
        <label style="font-size:12px;font-weight:normal;"><input type="checkbox" class="newType-bt" value="dept"> 課室</label>
        <label style="font-size:12px;font-weight:normal;"><input type="checkbox" class="newType-bt" value="position"> 職稱</label>
        <button class="btn btn-primary btn-sm" id="btnTypeAdd" style="white-space:nowrap;"><i class="fa fa-plus"></i> 新增</button>
      </div>
    </div>
    <div id="stab-base" style="display:none;">
      <div style="display:flex;gap:8px;align-items:center;">
        <input type="text" id="baseDir" class="form-control input-sm" style="flex:1;font-family:Consolas,monospace;" placeholder="\\excellentnas\...\圖章（留空＝專案內 uploads\stamps）">
        <button class="btn btn-primary btn-sm" id="btnBaseSave"><i class="fa fa-save"></i> 儲存</button>
      </div>
      <p id="baseState" style="font-size:11.5px;margin:6px 0 0;"></p>
      <p class="text-muted" style="font-size:11.5px;margin:4px 0 0;">DB 只存檔名，路徑讀取時即時組出（鐵律5）；更換路徑後請自行把既有 PNG 檔搬到新資料夾。僅管理者可見此分頁。</p>
    </div>
  </div>
</div></div></div>

<!-- 掃描章管理 Modal -->
<div class="modal fade" id="scanModal" tabindex="-1"><div class="modal-dialog" style="width:720px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">掃描實體章：<span id="scanUserName"></span></h4></div>
  <div class="modal-body">
    <p class="text-muted" style="font-size:12px;">上傳實際蓋章的掃描圖（JPG/PNG，紅色印泥、建議 300dpi），系統自動抽取紅色去背。上傳後拖拉兩條虛線框選「日期帶」位置——簽核顯示時該區域會鋪白蓋掉舊日期、改壓當次簽核日期。</p>
    <div style="margin-bottom:10px;">
      <input type="file" id="scanFile" accept=".jpg,.jpeg,.png" style="display:inline-block;">
      <button class="btn btn-primary btn-sm" id="btnUpload"><i class="fa fa-upload"></i> 上傳並去背</button>
      <button class="btn btn-danger btn-sm" id="btnAssetDel" style="display:none;"><i class="fa fa-trash"></i> 刪除掃描章（回退電子章）</button>
    </div>
    <div id="bandArea" style="display:none;">
      <div style="display:flex;gap:18px;justify-content:center;flex-wrap:wrap;">
        <div>
          <div id="bandBox">
            <img id="bandImg" alt="去背後掃描章">
            <div class="band-shade" id="bandShade"></div>
            <div class="band-line" id="lineTop" data-lb="日期帶上緣"></div>
            <div class="band-line" id="lineBot" data-lb="日期帶下緣"></div>
          </div>
          <p style="text-align:center;font-size:12px;margin-top:4px;">上緣 <span id="pctTop"></span>%　下緣 <span id="pctBot"></span>%</p>
        </div>
        <div style="text-align:center;">
          <p style="font-size:12px;color:#7a4e17;margin-bottom:4px;">簽核顯示預覽（壓今日日期）</p>
          <div id="scanPreview"></div>
          <button class="btn btn-primary btn-sm" id="btnBandSave" style="margin-top:10px;"><i class="fa fa-check"></i> 儲存日期帶位置</button>
        </div>
      </div>
    </div>
  </div>
</div></div></div>

<!-- 編輯登記 Modal -->
<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog" style="width:420px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">編輯登記：<span id="editUserName"></span></h4></div>
  <div class="modal-body">
    <div class="form-group"><label style="font-size:12.5px;">印章種類</label>
      <select id="editType" class="form-control input-sm"></select></div>
    <div class="form-group"><label style="font-size:12.5px;">核發日期</label>
      <input type="date" id="editDate" class="form-control input-sm" max="9999-12-31"></div>
    <div class="form-group"><label style="font-size:12.5px;">備註</label>
      <input type="text" id="editNote" class="form-control input-sm"></div>
    <div style="text-align:right;"><button class="btn btn-primary btn-sm" id="btnEditSave"><i class="fa fa-check"></i> 儲存</button></div>
  </div>
</div></div></div>

<!-- 批次建立登記 Modal（僅超級管理員 員工ID=1）-->
<div class="modal fade" id="batchModal" tabindex="-1"><div class="modal-dialog" style="width:760px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-object-group"></i> 批次建立登記</h4></div>
  <div class="modal-body">
    <p class="text-muted" style="font-size:12px;">先多選部門與圖章模板（可複選多種），按「產生名單」列出候選清單，可再手動取消勾選不需要的人。
      <strong>個人章</strong>（種類綁定對象為個人、或未限制）同一人只會出現一次；<strong>部門所屬人員章</strong>（種類同時綁定個人＋課室）同一人若身兼多部門主/兼任職位，主職位部門、兼任職位部門各自一筆。</p>
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:10px;">
      <div style="flex:1;min-width:200px;">
        <strong style="color:#7a4e17;font-size:13px;">部門（可複選）</strong>
        <div id="batchDeptWrap" style="max-height:160px;overflow-y:auto;border:1px solid #e8d9b8;border-radius:4px;padding:6px 8px;margin-top:4px;"></div>
      </div>
      <div style="flex:1;min-width:220px;">
        <strong style="color:#7a4e17;font-size:13px;">圖章模板（可複選）</strong>
        <div id="batchTplWrap" style="max-height:160px;overflow-y:auto;border:1px solid #e8d9b8;border-radius:4px;padding:6px 8px;margin-top:4px;"></div>
      </div>
    </div>
    <div style="margin-bottom:8px;">
      核發日期 <input type="date" id="batchDate" class="form-control input-sm" style="width:150px;display:inline-block;" max="9999-12-31">
      <button class="btn btn-default btn-sm" id="btnBatchGen" style="margin-left:8px;"><i class="fa fa-list"></i> 產生名單</button>
    </div>
    <div style="max-height:320px;overflow-y:auto;border:1px solid #e8d9b8;">
      <table class="list"><thead><tr>
        <th style="width:36px;"><input type="checkbox" id="batchChkAll" checked title="全選/全不選"></th>
        <th>圖章模板／種類</th><th style="width:150px;">部門</th><th style="width:120px;">持有人</th>
      </tr></thead><tbody id="batchPreviewBody"><tr><td colspan="4" class="text-muted">請先選部門與模板後按「產生名單」。</td></tr></tbody></table>
    </div>
    <div style="text-align:right;margin-top:10px;">
      <span id="batchResult" style="color:#7a4e17;font-size:12.5px;margin-right:10px;"></span>
      <button class="btn btn-primary btn-sm" id="btnBatchSave"><i class="fa fa-check"></i> 確認批次建立</button>
    </div>
  </div>
</div></div></div>

<!-- 圖章模板設計 Modal -->
<div class="modal fade" id="tplModal" tabindex="-1" data-backdrop="static"><div class="modal-dialog" style="width:860px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-magic"></i> 圖章模板設計</h4></div>
  <div class="modal-body">
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
      <div style="flex:1;min-width:430px;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:2px;">
          <span>名稱 <input type="text" id="tplName" class="form-control input-sm" style="width:150px;display:inline-block;" title="僅供你在模板清單中辨識/挑選，不會印在章上"></span>
          <span>種類 <select id="tplType" class="form-control input-sm" style="width:120px;display:inline-block;"></select></span>
          <span>外框 <select id="tplShape" class="form-control input-sm" style="width:100px;display:inline-block;">
            <option value="circle">圓形</option><option value="ellipse">橢圓</option>
            <option value="rect">方形</option><option value="roundrect">圓角方形</option></select></span>
        </div>
        <p class="text-muted" style="font-size:11px;margin:0 0 8px;"><i class="fa fa-info-circle"></i> 「名稱」只是方便你在清單中辨識、挑選模板用，<strong>不會印在圖章上</strong>；章上實際印出的文字，要靠下方每一列自己輸入固定字樣或插入變數。</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
          <span>顏色 <input type="color" id="tplColor" value="#cf3a2b" style="width:44px;height:28px;vertical-align:middle;border:1px solid #d8c19a;">
            <button class="btn btn-default btn-xs tpl-color" data-c="#cf3a2b">紅</button>
            <button class="btn btn-default btn-xs tpl-color" data-c="#1a4f9c">藍</button></span>
          <span>大小(px) <input type="number" id="tplSize" class="form-control input-sm" style="width:70px;display:inline-block;" value="100" min="24" max="600"></span>
          <span>高/寬比 <input type="number" id="tplRatio" class="form-control input-sm" style="width:64px;display:inline-block;" value="1" min="0.3" max="3" step="0.1"></span>
          <span>線寬 <input type="number" id="tplStroke" class="form-control input-sm" style="width:60px;display:inline-block;" value="2.6" min="0.5" max="8" step="0.1"></span>
          <span>字體 <select id="tplFont" class="form-control input-sm" style="width:80px;display:inline-block;">
            <option value="kai">楷體</option><option value="ming">明體</option><option value="hei">黑體</option></select></span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
          <span title="蓋在有固定列高的表格/欄位裡時(如簽到表)，自動縮放成填滿該格高度的比例；不影響本設計器預覽與有充足空間的簽核欄位">
            填滿列高比例 <input type="number" id="tplFillRatio" class="form-control input-sm" style="width:60px;display:inline-block;" value="90" min="10" max="100" step="1">%</span>
          <label style="font-weight:normal;margin:0;" title="勾選後蓋章一律用本模板設計的實際大小(大小(px)×高/寬比)，即使蓋出超過欄位高度也不縮小">
            <input type="checkbox" id="tplNoScale"> 不可縮放（可蓋出格子欄位外）</label>
        </div>
        <div style="margin-bottom:6px;">
          <strong style="color:#7a4e17;font-size:13px;">分割列（由上而下；高度為比例）</strong>
          <span class="text-muted" style="font-size:11.5px;">　點內容框後按變數鈕插入：</span>
          <span id="tokenBtns">
            <button class="btn btn-default btn-xs tok" data-t="{公司}">公司</button>
            <button class="btn btn-default btn-xs tok" data-t="{部門}">部門</button>
            <button class="btn btn-default btn-xs tok" data-t="{部門簡稱}" title="去掉部門名尾端「部/組/課/室/廠/中心」等後綴，例：生管組→生管、設計開發課→設計開發">部門簡稱</button>
            <button class="btn btn-default btn-xs tok" data-t="{職稱}">職稱</button>
            <button class="btn btn-default btn-xs tok" data-t="{姓名}">姓名</button>
            <button class="btn btn-default btn-xs tok" data-t="{日期}">日期</button>
            <button class="btn btn-default btn-xs tok" data-t="{編號}">編號</button>
          </span>
        </div>
        <table class="list" id="tplRowsTbl"><thead><tr>
          <th style="width:64px;">高度比</th><th>內容（固定字樣＋變數）</th>
          <th style="width:60px;" title="字的大小（相對單位，章寬=100）；留空=依列高自動放大填滿">字級</th>
          <th style="width:66px;" title="文字超過章寬時的處理：縮小=壓縮字距擠在一行；換列=分成多行">超寬時</th>
          <th style="width:56px;" title="換列模式專用：第一列固定放前N個字，其餘自動放第二列；留空=改依可用寬度自動折行">首列字數</th>
          <th style="width:28px;"></th></tr></thead>
          <tbody id="tplRows"></tbody></table>
        <button class="btn btn-default btn-xs" id="btnTplRowAdd" style="margin-top:4px;"><i class="fa fa-plus"></i> 加一列</button>
        <div style="margin-top:10px;padding:8px;background:#fdf6ea;border:1px solid #e8d9b8;border-radius:4px;">
          <strong style="color:#7a4e17;font-size:13px;">編號跳號規則</strong>（內容有 {編號} 才會用到）<br>
          前綴 <input type="text" id="serPrefix" class="form-control input-sm" style="width:150px;display:inline-block;" placeholder="如 B-{民國年}{月}{日}">
          <span id="serTokenBtns">
            <button class="btn btn-default btn-xs ser-tok" data-t="{民國年}" title="民國年3碼，如2026→115">民國年</button>
            <button class="btn btn-default btn-xs ser-tok" data-t="{西元年}" title="西元年4碼，如2026">西元年</button>
            <button class="btn btn-default btn-xs ser-tok" data-t="{月}" title="月份2碼補零">月</button>
            <button class="btn btn-default btn-xs ser-tok" data-t="{日}" title="日期2碼補零">日</button>
          </span><br>
          位數 <input type="number" id="serDigits" class="form-control input-sm" style="width:56px;display:inline-block;" value="3" min="1" max="10">
          起始 <input type="number" id="serStart" class="form-control input-sm" style="width:64px;display:inline-block;" value="1" min="0">
          間隔 <input type="number" id="serStep" class="form-control input-sm" style="width:56px;display:inline-block;" value="1" min="1">
          歸零 <select id="serReset" class="form-control input-sm" style="width:90px;display:inline-block;">
            <option value="none">不歸零</option><option value="day">每日</option><option value="month">每月</option><option value="year">每年</option></select>
          <div id="serExplain" style="font-size:12.5px;color:#7a4e17;margin-top:6px;"></div>
          <div class="text-muted" style="font-size:11.5px;margin-top:3px;line-height:1.6;">
            前綴可插入日期變數，取號當下即時展開成實際日期，常見於 BOM 單號／訂單編號格式：<br>
            例：<code>B-{民國年}{月}{日}</code>＋位數3＋歸零<strong>每日</strong> → 今天(115年7月28日)蓋出 <code>B-1150728001、B-1150728002…</code>，明天自動變 <code>B-1150729001…</code>；<br>
            <code>{西元年}{月}{日}</code>＋歸零每日 → <code>20260728001、20260728002…</code>。<br>
            範例（無日期變數）：前綴「QA-」＋位數 3＋起始 1＋間隔 1 → <code>QA-001、QA-002、QA-003…</code>；間隔 2 → <code>QA-001、QA-003、QA-005…</code>（跳號）。
            「歸零」＝編號重新起算的週期：<strong>每日</strong>／每月／每年 到期第一顆章重新從起始值開始；<strong>不歸零</strong>則一直累加。含{月}{日}的前綴通常要搭配「每日」歸零，否則每日開頭日期變了但流水號不會跟著歸零。
          </div>
        </div>
      </div>
      <div style="width:280px;text-align:center;">
        <p style="font-size:12px;color:#7a4e17;margin-bottom:4px;">即時預覽（示意資料）</p>
        <div id="tplPreview" style="padding:10px;border:1px dashed #d8c19a;border-radius:4px;min-height:140px;background:#fff;"></div>
        <p class="text-muted" style="font-size:11.5px;margin-top:4px;">示意：品保課／課長／王小明／今日／編號依規則</p>
        <button class="btn btn-primary btn-sm" id="btnTplSave" style="margin-top:8px;"><i class="fa fa-check"></i> 儲存模板</button>
      </div>
    </div>
  </div>
</div></div></div>

<!-- 停用/繳回 Modal -->
<div class="modal fade" id="revModal" tabindex="-1"><div class="modal-dialog" style="width:380px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">停用/繳回：<span id="revUserName"></span></h4></div>
  <div class="modal-body">
    <div class="form-group"><label style="font-size:12.5px;">停用/繳回日期</label>
      <input type="date" id="revDate" class="form-control input-sm" max="9999-12-31"></div>
    <div style="text-align:right;"><button class="btn btn-warning btn-sm" id="btnRevSave"><i class="fa fa-ban"></i> 確認停用</button></div>
  </div>
</div></div></div>

<!-- 權限說明 Modal -->
<div class="modal fade" id="permModal" tabindex="-1"><div class="modal-dialog" style="width:460px;"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-question-circle"></i> 圖章管理權限說明</h4></div>
  <div class="modal-body" style="font-size:13px;">
    <table class="list">
      <tr><th style="width:110px;">角色</th><th>可做的事</th></tr>
      <tr><td>未指派角色者</td><td><strong>看不到清冊內容</strong>（避免圖章被瀏覽轉存惡意複製）</td></tr>
      <tr><td>圖章檢閱</td><td>唯讀：檢閱清冊、匯出 CSV / 列印</td></tr>
      <tr><td>圖章管理員</td><td>檢閱＋登記核發（個人章/部門章）、編輯、停用/繳回、刪除；種類管理；上傳/刪除掃描實體章、調整日期帶</td></tr>
      <tr><td>管理者</td><td>以上全部＋設定掃描章儲存路徑</td></tr>
    </table>
    <p class="text-muted" style="font-size:12px;margin-top:8px;">「圖章檢閱」「圖章管理員」角色請至 <strong>人員權限設定（user_permissions）→ 圖章管理</strong> 區塊指派。簽核單據上的印章顯示不受此限（看得到單據就看得到章）。</p>
  </div>
</div></div></div>

</div><!-- /right_col -->
</div><!-- /main_container -->
</div><!-- /container body -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>window.__ownCompany = <?= json_encode($ownCompany, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../../resource/js/eg_stamp.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/eg_stamp.js'); ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js'); ?>"></script>
<script>
const API='../../src/store/store_Stamp_API.php';
let canManage=false, isAdmin=false, USERS=[], page=1, per=10, total=0, curScanUid=0, curScanName='', curAsset=null, curEditId=0;
function esc(s){return $('<div>').text(s==null?'':s).html();}
function dot(d){return (d||'').substring(0,10).replace(/-/g,'.');}
function today(){const t=new Date();return t.getFullYear()+'-'+String(t.getMonth()+1).padStart(2,'0')+'-'+String(t.getDate()).padStart(2,'0');}
const KIND_LABEL={user:'個人持有',dept:'課室持有',position:'職稱持有',user_dept:'部門所屬人員'};

// ── 通用輸入互動（雙擊清空/聚焦全選/Enter 逐欄）──
$(document).on('dblclick','input[type=text],input[type=date]',function(){ $(this).val(''); if(this.id==='fltName'){loadList(1);} });
$(document).on('focus','input[type=text]',function(){ this.select(); });
$('#addTpl,#addKind,#addUser,#addDept,#addDeptUser,#addPosition,#addDate,#addNote').on('keydown',function(e){
  if(e.key!=='Enter') return; e.preventDefault();
  const order=['addTpl','addKind','addUser','addDept','addDeptUser','addPosition','addDate','addNote'];
  const visible=order.filter(id=>$('#'+id).is(':visible'));
  const i=visible.indexOf(this.id);
  if(i>-1&&i<visible.length-1) $('#'+visible[i+1]).focus(); else $('#btnAdd').click();
});

let TYPES=[], DEPTS=[], POSITIONS=[], DEPT_POSITIONS={};
// 依印章種類的 bind_targets 決定新增登記可選的持有對象別；未選種類或無限制＝三種都可選（沿用舊行為）
// 種類同時綁定「個人」＋「課室」時，合併成一種組合模式：先選部門、再選該部門所屬人員（而非各自獨立可選）
function allowedKinds(){
  const tid=$('#addType').val();
  const t=TYPES.find(x=>String(x.id)===String(tid));
  const bt=t&&t.bind_targets?t.bind_targets.split(',').filter(Boolean):[];
  if(!bt.length) return ['user','dept','position'];
  const hasU=bt.includes('user'), hasD=bt.includes('dept'), hasP=bt.includes('position');
  const kinds=[];
  if(hasU&&hasD) kinds.push('user_dept'); else { if(hasU) kinds.push('user'); if(hasD) kinds.push('dept'); }
  if(hasP) kinds.push('position');
  return kinds.length?kinds:['user','dept','position'];
}
function updateAddKindUI(){
  const kinds=allowedKinds();
  const cur=$('#addKind').val();
  $('#addKind').html(kinds.map(k=>`<option value="${k}">${KIND_LABEL[k]}</option>`).join(''));
  $('#addKind').val(kinds.includes(cur)?cur:kinds[0]);
  $('#addKindWrap').toggle(kinds.length>1);
  updateAddTargetSelects();
}
function updateAddTargetSelects(){
  const k=$('#addKind').val();
  $('#addUser').toggle(k==='user');
  $('#addDept').toggle(k==='dept'||k==='position'||k==='user_dept');
  $('#addDeptUser').toggle(k==='user_dept');
  $('#addPosition').toggle(k==='position');
  if(k==='user_dept'){ $('#addDept').val(''); populateDeptUserOptions(); }
  if(k==='position'){ $('#addDept').val(''); populatePositionOptions(); }
}
// 部門→篩選人員：只列出主要部門＝所選部門的在職人員（部門所屬人員組合登記用）
function populateDeptUserOptions(){
  const did=$('#addDept').val();
  if(!did){ $('#addDeptUser').html('<option value="">（請先選部門）</option>'); return; }
  const list=USERS.filter(u=>String(u.dept_id||'')===String(did));
  $('#addDeptUser').html(list.length
    ? '<option value="">— 選擇人員 —</option>'+list.map(u=>`<option value="${u.id}">${esc(u.user_cname)}</option>`).join('')
    : '<option value="">（此部門查無在職人員）</option>');
}
// 部門→篩選職稱：只列出該部門在「部門/職稱設定」實際有設定的職稱（department_position 主檔，非人員實際指派）
function populatePositionOptions(){
  const did=$('#addDept').val();
  if(!did){ $('#addPosition').html('<option value="">（請先選部門）</option>'); return; }
  const allowed=(DEPT_POSITIONS[did]||[]).map(String);
  const list=POSITIONS.filter(p=>allowed.includes(String(p.id)));
  $('#addPosition').html(list.length
    ? '<option value="">— 選擇職稱 —</option>'+list.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('')
    : '<option value="">（此部門未設定職稱）</option>');
}
$('#addType').on('change',updateAddKindUI);
$('#addKind').on('change',updateAddTargetSelects);
$('#addDept').on('change',function(){
  const k=$('#addKind').val();
  if(k==='user_dept') populateDeptUserOptions();
  else if(k==='position') populatePositionOptions();
});
// 新增登記先選「模板」，種類由模板自動帶入（唯讀顯示，不再讓使用者另外選種類）
$('#addTpl').on('change',function(){
  const t=(TPLS||[]).find(x=>String(x.id)===String($(this).val()));
  $('#addTypeShow').text(t?(t.type_name||'（未分類）'):'（請先選模板）');
  $('#addType').val(t?(t.type_id||''):'').trigger('change');
});
function populateAddTpl(){
  const act=(TPLS||[]).filter(t=>+t.is_active===1);
  $('#addTpl').html(act.length
    ? act.map(t=>`<option value="${t.id}">${t.type_name?esc(t.type_name)+'｜':''}${esc(t.tpl_name)}</option>`).join('')
    : '<option value="">（尚無啟用模板，請先到下方「線上圖章設計」頁籤建立）</option>');
  $('#addTpl').trigger('change');
}
function typeOpts(sel,withAll){
  const act=TYPES.filter(t=>+t.is_active===1);
  return (withAll?'<option value="">全部種類</option>':'<option value="">（未分類）</option>')
    +act.map(t=>`<option value="${t.id}" ${String(sel)===String(t.id)?'selected':''}>${esc(t.type_name)}</option>`).join('');
}
function loadMeta(){
  $.getJSON(API+'?action=meta',m=>{
    if(!m.ok){alert(m.error||'載入失敗');return;}
    canManage=m.canManage; isAdmin=m.isAdmin; USERS=m.users||[]; TYPES=m.types||[];
    $('#myRole').text(isAdmin?'管理者':(canManage?'圖章管理員':(m.canView?'圖章檢閱（唯讀）':'無權限')));
    if(m.canView===false){ $('#mainArea').hide(); $('#noPermMsg').show(); return; }
    $('#fltType').html(typeOpts('',true));
    if(canManage){
      $('#addBar').show();
      DEPTS=m.depts||[]; POSITIONS=m.positions||[]; DEPT_POSITIONS=m.dept_positions||{};
      $('#addUser').html('<option value="">— 選擇人員 —</option>'+USERS.map(u=>`<option value="${u.id}">${esc(u.user_cname)}</option>`).join(''));
      $('#addDept').html('<option value="">— 選擇部門 —</option>'+DEPTS.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join(''));
      $('#addPosition').html('<option value="">（請先選部門）</option>');
      $('#addType').html(typeOpts('',false));
      $('#addDate').val(today());
      updateAddKindUI();
      renderTypeMng();
    }
    if(isAdmin){ $('#stabBase').show(); $('#baseDir').val(m.base||''); baseState(m.base,m.base_ok); }
    if(canManage){ $('#btnSettings').show(); $('#btnTplNew').show(); }
    if(m.canBatch){ $('#btnBatchAdd').show(); }
    loadTpls(()=>loadList(1));   // 清冊印模預覽要用模板資料(TPLS)渲染，等模板載完再載清冊，避免競速下第一次顯示退回舊版簡易章
  });
}

// ── 內頁頁籤（清冊/模板）與設定跳窗分頁 ──
$('.warm-tabs [data-tab]').on('click',function(){
  $(this).addClass('active').siblings().removeClass('active');
  const t=$(this).data('tab');
  $('#tab-reg').toggle(t==='reg'); $('#tab-tpl').toggle(t==='tpl');
});
$('#settingsModal .warm-tabs [data-stab]').on('click',function(){
  $(this).addClass('active').siblings().removeClass('active');
  const t=$(this).data('stab');
  $('#stab-type').toggle(t==='type'); $('#stab-base').toggle(t==='base');
});
function baseState(base,ok){
  $('#baseState').html(base?('目前路徑：<code>'+esc(base)+'</code> '+(ok?'<span style="color:#26b99a;">✔ 可存取</span>':'<span style="color:#dd5138;">✘ 資料夾不存在（上傳時會嘗試自動建立）</span>')):'');
}
$('#btnBaseSave').on('click',function(){
  $.post(API+'?action=set_base',{base:$('#baseDir').val().trim()},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    $('#baseDir').val(r.base); baseState(r.base,r.base_ok); alert('已儲存');
  },'json');
});

// 找出這筆登記「實際該用哪顆模板」：優先用登記當時選的 template_id（後端已回傳，僅在該模板仍啟用時才有值）；
// 若未指定或已停用/刪除，才退回「同種類任一啟用模板」當備援——同種類若有多顆模板，備援結果不保證是哪一顆，
// 所以正常情況都應該有 template_id，備援只是舊資料/模板被停用時的優雅降級，不是常態。
function resolveTplFor(x){
  let tpl=null;
  if(x.template_id) tpl=(TPLS||[]).find(t=>String(t.id)===String(x.template_id)&&+t.is_active===1);
  if(!tpl) tpl=(TPLS||[]).find(t=>+t.is_active===1 && String(t.type_id||'')===String(x.type_id||''));
  return tpl||null;
}
// 清冊印模預覽：用 resolveTplFor 解析出的模板渲染，查無對應模板時（尚未設計）才退回舊版簡易章當佔位預覽。
function renderRegStamp(x){
  const tpl=resolveTplFor(x);
  if(tpl){
    let sc={}; try{sc=JSON.parse(tpl.schema_json||'{}');}catch(e){sc={};}
    const ctx={company:window.__ownCompany||'', dept:x.dept_name||'', position:x.position_name||'', name:x.user_cname||'', date:dot(x.issue_date)};
    return EGStampTpl.render(Object.assign({},sc,{size:76}), ctx);
  }
  return EGStamp.stamp(x.holder_name, dot(x.issue_date), false);
}

// ── 清冊列表（後端分頁＋彙總）──
function loadList(p){
  page=p||page;
  const q=$('#fltName').val().trim(), st=$('#fltStatus').val(), tid=$('#fltType').val()||'';
  $.getJSON(API,{action:'list',q,status:st,type_id:tid,page,per},r=>{
    if(!r.ok){alert(r.error||'載入失敗');return;}
    total=r.total;
    $('#sumInfo').text(`使用中 ${r.summary.active} 顆／已停用 ${r.summary.revoked} 顆，共 ${total} 筆`);
    $('#regBody').html(r.rows.map(x=>{
      const stampHtml=renderRegStamp(x);
      const kindBadge=x.holder_kind==='position'?' <span class="status-chip" style="background:#dcd0e8;color:#3a2c4a;">職稱章</span>'
        :x.holder_kind==='user_dept'?' <span class="status-chip" style="background:#f0dfc3;color:#4a3a20;">部門人員章</span>'
        :x.holder_kind==='dept'?' <span class="status-chip" style="background:#e8dcc3;color:#4a3a20;">課室章</span>':'';
      return `<tr>
      <td style="text-align:center;">${stampHtml}</td>
      <td>${esc(x.holder_name)}${kindBadge}</td>
      <td>${x.type_name?esc(x.type_name):'<span class="text-muted">（未分類）</span>'}${x.tpl_name?'<br><span class="text-muted" style="font-size:11px;">模板：'+esc(x.tpl_name)+'</span>':''}</td>
      <td>${esc(x.issue_date||'')}</td>
      <td>${esc(x.revoke_date||'—')}</td>
      <td><span class="status-chip st-${x.status}">${x.status==='active'?'使用中':'已停用'}</span></td>
      <td>${esc(x.note||'')}</td>
      <td style="text-align:center;">${+x.has_asset?'<span style="color:#7a4e17;font-weight:bold;">已上傳</span>':'<span class="text-muted">—</span>'}</td>
      <td>${canManage?`
        ${x.user_id?`<button class="btn btn-default btn-xs scan-btn" data-uid="${x.user_id}" data-name="${esc(x.holder_name)}"><i class="fa fa-image"></i> 掃描章</button>`:''}
        <button class="btn btn-default btn-xs edit-btn" data-id="${x.id}" data-name="${esc(x.holder_name)}" data-date="${x.issue_date}" data-note="${esc(x.note||'')}" data-type="${x.type_id||''}"><i class="fa fa-pencil"></i></button>
        ${x.status==='active'?`<button class="btn btn-warning btn-xs rev-btn" data-id="${x.id}" data-name="${esc(x.holder_name)}"><i class="fa fa-ban"></i> 停用</button>`:''}
        <button class="btn btn-danger btn-xs del-btn" data-id="${x.id}" data-name="${esc(x.holder_name)}"><i class="fa fa-trash"></i></button>`:'<span class="text-muted">—</span>'}
      </td></tr>`;}).join('')||'<tr><td colspan="9" class="text-muted">尚無登記資料。</td></tr>');
    renderPager();
  });
}
function renderPager(){
  const pages=Math.max(1,Math.ceil(total/per)); if(page>pages)page=pages;
  let h='';
  h+=`<button class="btn btn-default btn-xs pg-btn" data-p="${page-1}" ${page<=1?'disabled':''}>‹</button> `;
  for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++)
    h+=`<button class="btn ${i===page?'btn-primary':'btn-default'} btn-xs pg-btn" data-p="${i}">${i}</button> `;
  h+=`<button class="btn btn-default btn-xs pg-btn" data-p="${page+1}" ${page>=pages?'disabled':''}>›</button>`;
  $('#pageBtns').html(h);
}
$('#pageBtns').on('click','.pg-btn',function(){ loadList(+$(this).data('p')); });
$('#perSel').on('change',function(){ per=+this.value; loadList(1); });
$('#fltName').on('input',function(){ clearTimeout(this._t); this._t=setTimeout(()=>loadList(1),400); });
$('#fltStatus,#fltType').on('change',()=>loadList(1));

// ── 新增/編輯/停用/刪除 ──
$('#btnAdd').on('click',function(){
  const kind=$('#addKind').val();
  const p={holder_kind:kind,issue_date:$('#addDate').val(),type_id:$('#addType').val()||'',template_id:$('#addTpl').val()||'',note:$('#addNote').val().trim()};
  if(kind==='user'){
    if(!$('#addUser').val()){alert('請選擇人員');return;}
    p.user_id=$('#addUser').val();
  }else if(kind==='dept'){
    if(!$('#addDept').val()){alert('請選擇部門');return;}
    p.dept_id=$('#addDept').val();
  }else if(kind==='position'){ // 該部門的該職稱
    if(!$('#addDept').val()||!$('#addPosition').val()){alert('請選擇部門與職稱');return;}
    p.dept_id=$('#addDept').val(); p.position_id=$('#addPosition').val();
  }else{ // user_dept：部門所屬人員（先選部門篩出人員，再選人員）
    if(!$('#addDept').val()||!$('#addDeptUser').val()){alert('請選擇部門與人員');return;}
    p.dept_id=$('#addDept').val(); p.user_id=$('#addDeptUser').val();
  }
  $.post(API+'?action=add',p,r=>{
    if(!r.ok){alert(r.error||'登記失敗');return;}
    $('#addUser,#addDept,#addDeptUser,#addPosition').val(''); $('#addNote').val(''); $('#addDate').val(today());
    populateDeptUserOptions(); loadList(1);
  },'json');
});
$('#regBody').on('click','.edit-btn',function(){
  curEditId=$(this).data('id');
  $('#editUserName').text($(this).data('name'));
  $('#editType').html(typeOpts($(this).data('type'),false));
  $('#editDate').val($(this).data('date')); $('#editNote').val($(this).data('note'));
  $('#editModal').modal('show');
});
$('#btnEditSave').on('click',function(){
  $.post(API+'?action=update',{id:curEditId,type_id:$('#editType').val()||'',issue_date:$('#editDate').val(),note:$('#editNote').val().trim()},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    $('#editModal').modal('hide'); loadList();
  },'json');
});

// ── 種類管理 ──
function bindChecks(cls,bt){
  const on=(bt||'').split(',');
  return ['user','dept','position'].map(k=>
    `<label style="font-size:12px;font-weight:normal;margin-right:6px;"><input type="checkbox" class="${cls}" value="${k}" ${on.includes(k)?'checked':''}> ${KIND_LABEL[k].replace('持有','')}</label>`
  ).join('');
}
function renderTypeMng(){
  $('#typeBody').html(TYPES.map(t=>`<tr>
    <td><input type="text" class="form-control input-sm type-name" data-id="${t.id}" value="${esc(t.type_name)}"></td>
    <td>${bindChecks('type-bt',t.bind_targets)}</td>
    <td style="text-align:center;">${+t.is_active?'<span style="color:#3c763d;">啟用</span>':'<span class="text-muted">已停用</span>'}</td>
    <td>
      <span id="type-flash-${t.id}" class="text-success" style="display:none;font-size:11.5px;margin-right:4px;"><i class="fa fa-check"></i> 已儲存</span>
      <button class="btn btn-warning btn-xs type-toggle" data-id="${t.id}">${+t.is_active?'停用':'啟用'}</button>
      <button class="btn btn-danger btn-xs type-del" data-id="${t.id}"><i class="fa fa-trash"></i></button>
    </td></tr>`).join('')||'<tr><td colspan="4" class="text-muted">尚無種類，請於下方新增。</td></tr>');
}
function reloadTypes(cb){
  $.getJSON(API+'?action=meta',m=>{
    if(!m.ok) return;
    TYPES=m.types||[];
    $('#fltType').html(typeOpts($('#fltType').val(),true));
    $('#addType').html(typeOpts($('#addType').val(),false));
    updateAddKindUI();
    renderTypeMng();
    if(cb)cb();
  });
}
$('#btnTypeAdd').on('click',function(){
  const name=$('#newTypeName').val().trim();
  if(!name){alert('請輸入種類名稱');return;}
  const bt=$('.newType-bt:checked').map((i,el)=>el.value).get().join(',');
  $.post(API+'?action=type_save',{id:0,type_name:name,bind_targets:bt},r=>{
    if(!r.ok){alert(r.error||'新增失敗');return;}
    $('#newTypeName').val(''); $('.newType-bt').prop('checked',false); reloadTypes();
  },'json');
});
// 修改即自動儲存（名稱失焦時／勾選綁定對象當下）：不需另按「存」，改後立刻生效並閃示「已儲存」
function saveTypeRow(id){
  const row=$('.type-name[data-id="'+id+'"]').closest('tr');
  const name=row.find('.type-name').val().trim();
  if(!name){alert('請輸入種類名稱');return;}
  const bt=row.find('.type-bt:checked').map((i,el)=>el.value).get().join(',');
  $.post(API+'?action=type_save',{id,type_name:name,bind_targets:bt},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    reloadTypes(()=>{
      loadList();
      $('#type-flash-'+id).stop(true,true).show().delay(1200).fadeOut(400);
    });
  },'json');
}
$('#typeBody').on('change','.type-bt',function(){ saveTypeRow($(this).closest('tr').find('.type-name').data('id')); });
$('#typeBody').on('blur','.type-name',function(){ saveTypeRow($(this).data('id')); });
$('#typeBody').on('click','.type-toggle',function(){
  $.post(API+'?action=type_toggle',{id:$(this).data('id')},r=>{
    if(!r.ok){alert(r.error||'切換失敗');return;}
    reloadTypes();
  },'json');
});
$('#typeBody').on('click','.type-del',function(){
  if(!confirm('確定刪除此種類？（已被登記使用的種類無法刪除，可改停用）')) return;
  $.post(API+'?action=type_delete',{id:$(this).data('id')},r=>{
    if(!r.ok){alert(r.error||'刪除失敗');return;}
    reloadTypes();
  },'json');
});
let curRevId=0;
$('#regBody').on('click','.rev-btn',function(){
  curRevId=$(this).data('id');
  $('#revUserName').text($(this).data('name'));
  $('#revDate').val(today());
  $('#revModal').modal('show');
});
$('#btnRevSave').on('click',function(){
  const d=$('#revDate').val();
  if(!/^\d{4}-\d{2}-\d{2}$/.test(d)){alert('請選擇停用/繳回日期');return;}
  $.post(API+'?action=revoke',{id:curRevId,revoke_date:d},r=>{
    if(!r.ok){alert(r.error||'停用失敗');return;}
    $('#revModal').modal('hide'); loadList();
  },'json');
});
$('#regBody').on('click','.del-btn',function(){
  const id=$(this).data('id'), name=$(this).data('name');
  if(!confirm(`確定刪除「${name}」這筆登記？（僅供誤登記時使用，刪除不可復原）`)) return;
  $.post(API+'?action=delete',{id},r=>{ if(!r.ok){alert(r.error||'刪除失敗');return;} loadList(); },'json');
});

// ── CSV / 列印 ──
$('#btnCsv').on('click',function(){
  location.href=API+'?action=csv&q='+encodeURIComponent($('#fltName').val().trim())+'&status='+encodeURIComponent($('#fltStatus').val())+'&type_id='+encodeURIComponent($('#fltType').val()||'');
});
$('#btnPrint').on('click',function(){
  const q=$('#fltName').val().trim(), st=$('#fltStatus').val(), tid=$('#fltType').val()||'';
  $.getJSON(API,{action:'list',q,status:st,type_id:tid,all:1},r=>{
    if(!r.ok){alert(r.error||'載入失敗');return;}
    const rows=r.rows.map(x=>`<tr>
      <td style="text-align:center;">${renderRegStamp(x)}</td>
      <td>${esc(x.holder_name)}${x.holder_kind==='position'?'（職稱章）':x.holder_kind==='user_dept'?'（部門人員章）':x.holder_kind==='dept'?'（部門章）':''}</td>
      <td>${x.type_name?esc(x.type_name):'（未分類）'}${x.tpl_name?'／'+esc(x.tpl_name):''}</td>
      <td>${esc(x.issue_date||'')}</td><td>${esc(x.revoke_date||'—')}</td>
      <td>${x.status==='active'?'使用中':'已停用'}</td><td>${esc(x.note||'')}</td>
      <td style="text-align:center;">${+x.has_asset?'已上傳':'—'}</td></tr>`).join('');
    // 單一表格交給瀏覽器原生分頁（列印分頁鐵則，禁止 JS 量高度自算）
    const w=window.open('','_blank');
    w.document.write(`<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>圖章清冊</title><style>
      body{font-family:"Microsoft JhengHei",sans-serif;color:#3a2a17;font-size:12px;}
      h3{margin:0 0 4px;} .sub{color:#8a7455;font-size:11px;margin-bottom:8px;}
      table{width:100%;border-collapse:collapse;} th,td{border:1px solid #b09468;padding:4px 6px;}
      th{background:#f7e0bd;} thead{display:table-header-group;} tr{page-break-inside:avoid;}
      svg{width:56px;height:56px;}
    </style></head><body>
      <h3>圖章清冊</h3><div class="sub">使用中 ${r.summary.active} 顆／已停用 ${r.summary.revoked} 顆，共 ${r.total} 筆　列印日期：${today()}</div>
      <table><thead><tr><th style="width:70px;">印模</th><th>持有對象</th><th>種類</th><th>核發日期</th><th>停用/繳回日</th><th>狀態</th><th>備註</th><th>掃描章</th></tr></thead>
      <tbody>${rows}</tbody></table></body></html>`);
    w.document.close();
    setTimeout(()=>{w.print();},400);   // 等掃描章圖載入
  });
});

// ── 掃描章 Modal ──
$('#regBody').on('click','.scan-btn',function(){
  curScanUid=$(this).data('uid'); curScanName=$(this).data('name');
  $('#scanUserName').text(curScanName);
  $('#scanFile').val('');
  refreshScanUI();
  $('#scanModal').modal('show');
});
function refreshScanUI(){
  // 重新抓對照表確認此人是否已有掃描章
  $.getJSON(API+'?action=asset_map',r=>{
    curAsset=(r.ok&&r.map&&r.map[curScanName])?r.map[curScanName]:null;
    if(curAsset&&curAsset.uid!==curScanUid) curAsset=null;   // 同名不同人保護
    if(curAsset){
      EGStamp.setAssets(r.map);
      $('#btnAssetDel').show(); $('#bandArea').show();
      $('#bandImg').attr('src',API+'?action=asset_img&user_id='+curScanUid+'&t='+curAsset.t);
      setBand(curAsset.top,curAsset.bot);
    }else{
      $('#btnAssetDel').hide(); $('#bandArea').hide();
    }
  });
}
$('#btnUpload').on('click',function(){
  const f=$('#scanFile')[0].files[0];
  if(!f){alert('請先選擇掃描圖檔');return;}
  const fd=new FormData();
  fd.append('user_id',curScanUid); fd.append('file',f);
  $(this).prop('disabled',true).text('處理中…');
  $.ajax({url:API+'?action=asset_upload',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
    .done(r=>{ if(!r.ok){alert(r.error||'上傳失敗');return;} refreshScanUI(); loadList(); })
    .fail(x=>alert((x.responseJSON&&x.responseJSON.error)||'上傳失敗'))
    .always(()=>$('#btnUpload').prop('disabled',false).html('<i class="fa fa-upload"></i> 上傳並去背'));
});
$('#btnAssetDel').on('click',function(){
  if(!confirm(`確定刪除「${curScanName}」的掃描章？簽核顯示將回退為電子章（純SVG）。`)) return;
  $.post(API+'?action=asset_delete',{user_id:curScanUid},r=>{
    if(!r.ok){alert(r.error||'刪除失敗');return;}
    refreshScanUI(); loadList();
  },'json');
});

// ── 日期帶拖拉框選 ──
let bandTop=32, bandBot=66, dragLine=null;
function setBand(t,b){
  bandTop=+t; bandBot=+b;
  $('#lineTop').css('top',bandTop+'%'); $('#lineBot').css('top',bandBot+'%');
  $('#bandShade').css({top:bandTop+'%',height:(bandBot-bandTop)+'%'});
  $('#pctTop').text(bandTop.toFixed(1)); $('#pctBot').text(bandBot.toFixed(1));
  $('#scanPreview').html(EGStamp.scan(curScanName, dot(today()), false, {uid:curScanUid, top:bandTop, bot:bandBot, t:(curAsset?curAsset.t:0)}));
  $('#scanPreview svg').attr({width:120,height:120});
}
$('#lineTop,#lineBot').on('mousedown',function(e){ dragLine=this.id; e.preventDefault(); });
$(document).on('mousemove',function(e){
  if(!dragLine) return;
  const box=$('#bandBox')[0].getBoundingClientRect();
  let pct=Math.max(0,Math.min(100,(e.clientY-box.top)/box.height*100));
  if(dragLine==='lineTop') setBand(Math.min(pct,bandBot-4),bandBot);
  else setBand(bandTop,Math.max(pct,bandTop+4));
}).on('mouseup',function(){ dragLine=null; });
$('#btnBandSave').on('click',function(){
  $.post(API+'?action=asset_band',{user_id:curScanUid,band_top:bandTop.toFixed(2),band_bottom:bandBot.toFixed(2)},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    alert('已儲存日期帶位置'); refreshScanUI(); loadList();
  },'json');
});

// ── 線上圖章模板（設計器）──
let TPLS=[], curTplId=0, lastTokInput=null;
// 前綴內的日期變數即時展開成「今天」的實際日期，供各處預覽/試算使用；真正蓋章時後端 next_serial 用同一套規則、當下日期重算
function resolveSerialPrefix(prefix){
  const d=new Date(), y=d.getFullYear();
  const roc=String(y-1911).padStart(3,'0'), mm=String(d.getMonth()+1).padStart(2,'0'), dd=String(d.getDate()).padStart(2,'0');
  return (prefix||'').replace(/\{民國年\}/g,roc).replace(/\{西元年\}/g,String(y)).replace(/\{月\}/g,mm).replace(/\{日\}/g,dd);
}
function sampleCtx(){
  return {company:window.__ownCompany||'公司全名',dept:'品保課',position:'課長',name:'王小明',date:dot(today()),
          serial:resolveSerialPrefix($('#serPrefix').val())+String(+$('#serStart').val()||1).padStart(+$('#serDigits').val()||3,'0')};
}
function tplRowHtml(h,text,fs,mode,wrapn){
  const isWrap=mode==='wrap';
  return `<tr><td><input type="number" class="form-control input-sm tpl-h" value="${h}" min="1" max="100" step="1"></td>
    <td><input type="text" class="form-control input-sm tpl-text" value="${esc(text)}"></td>
    <td><input type="number" class="form-control input-sm tpl-fs" value="${fs>0?fs:''}" placeholder="自動" min="4" max="60" step="0.5"></td>
    <td><select class="form-control input-sm tpl-mode">
      <option value="shrink" ${!isWrap?'selected':''}>縮小</option>
      <option value="wrap" ${isWrap?'selected':''}>換列</option></select></td>
    <td><input type="number" class="form-control input-sm tpl-wrapn" value="${wrapn>0?wrapn:''}" placeholder="自動" min="1" max="20" step="1" ${isWrap?'':'disabled'}></td>
    <td style="text-align:center;"><button class="btn btn-danger btn-xs tpl-row-del"><i class="fa fa-times"></i></button></td></tr>`;
}
$('#tplRows').on('change','.tpl-mode',function(){
  $(this).closest('tr').find('.tpl-wrapn').prop('disabled', $(this).val()!=='wrap').val('');
});
function tplSchemaFromUI(){
  const rows=[];
  $('#tplRows tr').each(function(){
    rows.push({h:+$(this).find('.tpl-h').val()||1, text:$(this).find('.tpl-text').val()||'',
               fs:+$(this).find('.tpl-fs').val()||0, mode:$(this).find('.tpl-mode').val()||'shrink',
               wrapn:+$(this).find('.tpl-wrapn').val()||0});
  });
  return {shape:$('#tplShape').val(), color:$('#tplColor').val(), size:+$('#tplSize').val()||100,
          ratio:+$('#tplRatio').val()||1, stroke:+$('#tplStroke').val()||2.6, font:$('#tplFont').val(), rows,
          fillRatio:Math.min(100,Math.max(10,+$('#tplFillRatio').val()||90))/100, noScale:$('#tplNoScale').is(':checked')};
}
function tplPreview(){
  $('#tplPreview').html(EGStampTpl.render(tplSchemaFromUI(), sampleCtx()));
  updateSerExplain();
}
// 跳號規則即時試算說明：依目前設定列出前三個號與歸零說明，設定當下就看得懂會怎麼跳
function updateSerExplain(){
  const pre=resolveSerialPrefix($('#serPrefix').val()), dg=+$('#serDigits').val()||3;
  const st=+$('#serStart').val()||1, sp=+$('#serStep').val()||1;
  const pad=n=>pre+String(n).padStart(dg,'0');
  const seq=[st,st+sp,st+sp*2].map(pad).join('　→　');
  const rs={none:'不歸零：編號一直連續累加',
            day:'每日歸零：每天第一次蓋章重新從 '+pad(st)+' 開始（跨日自動變號，各日流水獨立）',
            year:'每年歸零：每年第一次蓋章重新從 '+pad(st)+' 開始（各年度流水獨立）',
            month:'每月歸零：每月第一次蓋章重新從 '+pad(st)+' 開始'}[$('#serReset').val()]||'';
  $('#serExplain').html('目前規則試算（今天）：<strong>'+esc(seq)+'　→　…</strong>｜'+esc(rs));
}
$('#tplModal').on('input change','input,select',tplPreview);
$('#tplModal').on('focusin','.tpl-text',function(){ lastTokInput=this; });
$('#tokenBtns').on('click','.tok',function(e){
  e.preventDefault();
  if(!lastTokInput){alert('請先點選要插入的內容框');return;}
  const t=$(this).data('t'), el=lastTokInput, s=el.selectionStart||el.value.length;
  el.value=el.value.slice(0,s)+t+el.value.slice(el.selectionEnd||s);
  el.focus(); el.selectionStart=el.selectionEnd=s+t.length;
  tplPreview();
});
$('#serTokenBtns').on('click','.ser-tok',function(e){
  e.preventDefault();
  const t=$(this).data('t'), el=$('#serPrefix')[0], s=el.selectionStart!=null?el.selectionStart:el.value.length;
  const e2=el.selectionEnd!=null?el.selectionEnd:s;
  el.value=el.value.slice(0,s)+t+el.value.slice(e2);
  el.focus(); el.selectionStart=el.selectionEnd=s+t.length;
  tplPreview();
});
$('#tplModal').on('click','.tpl-row-del',function(){
  if($('#tplRows tr').length<=1){alert('至少保留一列');return;}
  $(this).closest('tr').remove(); tplPreview();
});
$('#btnTplRowAdd').on('click',function(){ $('#tplRows').append(tplRowHtml(30,'',0,'shrink',0)); tplPreview(); });
$('#tplModal').on('click','.tpl-color',function(e){ e.preventDefault(); $('#tplColor').val($(this).data('c')); tplPreview(); });

function openTplModal(t){
  curTplId=t?t.id:0;
  $('#tplType').html(typeOpts(t?t.type_id:'',false).replace('（未分類）','（不綁種類）'));
  $('#tplName').val(t?t.tpl_name:'');
  const sc=t?JSON.parse(t.schema_json||'{}'):{shape:'circle',color:'#cf3a2b',size:100,ratio:1,stroke:2.6,font:'kai',
    rows:[{h:30,text:'{部門}'},{h:40,text:'{日期}'},{h:30,text:'{姓名}'}]};
  $('#tplShape').val(sc.shape||'circle'); $('#tplColor').val(sc.color||'#cf3a2b');
  $('#tplSize').val(sc.size||100); $('#tplRatio').val(sc.ratio||1);
  $('#tplStroke').val(sc.stroke||2.6); $('#tplFont').val(sc.font||'kai');
  $('#tplFillRatio').val(Math.round((sc.fillRatio!=null?sc.fillRatio:0.9)*100)); $('#tplNoScale').prop('checked', !!sc.noScale);
  $('#tplRows').html((sc.rows&&sc.rows.length?sc.rows:[{h:100,text:''}]).map(r=>tplRowHtml(r.h,r.text,+r.fs||0,r.mode||'shrink',+r.wrapn||0)).join(''));
  $('#serPrefix').val(t?t.serial_prefix:''); $('#serDigits').val(t?t.serial_digits:3);
  $('#serStart').val(t?t.serial_start:1); $('#serStep').val(t?t.serial_step:1);
  $('#serReset').val(t?t.serial_reset:'none');
  tplPreview();
  $('#tplModal').modal('show');
}
$('#btnTplNew').on('click',()=>openTplModal(null));
$('#btnTplSave').on('click',function(){
  const name=$('#tplName').val().trim();
  if(!name){alert('請輸入模板名稱');return;}
  $.post(API+'?action=tpl_save',{id:curTplId,tpl_name:name,type_id:$('#tplType').val()||'',
    schema:JSON.stringify(tplSchemaFromUI()),
    serial_prefix:$('#serPrefix').val().trim(),serial_digits:$('#serDigits').val(),
    serial_start:$('#serStart').val(),serial_step:$('#serStep').val(),serial_reset:$('#serReset').val()},r=>{
    if(!r.ok){alert(r.error||'儲存失敗');return;}
    $('#tplModal').modal('hide'); loadTpls();
  },'json');
});
function serialRuleText(t){
  return esc(resolveSerialPrefix(t.serial_prefix))+'#'.repeat(Math.min(10,+t.serial_digits||3))
    +'　起'+t.serial_start+' 跳'+t.serial_step
    +({none:'',day:' 每日歸零',year:' 每年歸零',month:' 每月歸零'}[t.serial_reset]||'');
}
function loadTpls(cb){
  $.getJSON(API+'?action=tpl_list',r=>{
    if(!r.ok) return;
    TPLS=r.rows||[];
    $('#tplBody').html(TPLS.map(t=>{
      let sc={}; try{sc=JSON.parse(t.schema_json||'{}');}catch(e){}
      const prev=EGStampTpl.render(Object.assign({},sc,{size:76}),
        {company:window.__ownCompany||'公司全名',dept:'品保課',position:'課長',name:'王小明',date:dot(today()),
         serial:resolveSerialPrefix(t.serial_prefix)+String(t.serial_start||1).padStart(+t.serial_digits||3,'0')});
      return `<tr>
        <td style="text-align:center;">${prev}</td>
        <td>${esc(t.tpl_name)}</td>
        <td>${t.type_name?esc(t.type_name):'<span class="text-muted">（不綁種類）</span>'}</td>
        <td style="font-size:12px;">${serialRuleText(t)}</td>
        <td>${+t.is_active?'<span style="color:#3c763d;">啟用</span>':'<span class="text-muted">停用</span>'}</td>
        <td>${canManage?`
          <button class="btn btn-default btn-xs tplrow-edit" data-id="${t.id}"><i class="fa fa-pencil"></i> 設計</button>
          <button class="btn btn-default btn-xs tplrow-dup" data-id="${t.id}" title="以此模板為底稿另存一份新模板，方便微調外框/顏色/種類等設定"><i class="fa fa-copy"></i> 複製</button>
          <button class="btn btn-warning btn-xs tplrow-toggle" data-id="${t.id}">${+t.is_active?'停用':'啟用'}</button>
          <button class="btn btn-danger btn-xs tplrow-del" data-id="${t.id}"><i class="fa fa-trash"></i></button>`:'<span class="text-muted">—</span>'}
        </td></tr>`;}).join('')||'<tr><td colspan="6" class="text-muted">尚無模板，請新增。</td></tr>');
    if(canManage) populateAddTpl();   // 新增登記的「模板」下拉同步更新（啟用/停用/刪除/改種類都會影響可選清單）
    if(cb) cb();
  });
}
$('#tplBody').on('click','.tplrow-edit',function(){
  const t=TPLS.find(x=>String(x.id)===String($(this).data('id')));
  if(t) openTplModal(t);
});
$('#tplBody').on('click','.tplrow-dup',function(){
  const t=TPLS.find(x=>String(x.id)===String($(this).data('id')));
  if(!t) return;
  openTplModal(t);           // 先把來源模板的設定整組帶入設計器
  curTplId=0;                // 存檔時新增一筆，不覆蓋原本
  $('#tplName').val(t.tpl_name+'（複製）');
});
$('#tplBody').on('click','.tplrow-toggle',function(){
  $.post(API+'?action=tpl_toggle',{id:$(this).data('id')},r=>{ if(!r.ok){alert(r.error||'失敗');return;} loadTpls(); },'json');
});
$('#tplBody').on('click','.tplrow-del',function(){
  if(!confirm('確定刪除此模板？（編號流水紀錄將一併刪除，不可復原）')) return;
  $.post(API+'?action=tpl_delete',{id:$(this).data('id')},r=>{ if(!r.ok){alert(r.error||'失敗');return;} loadTpls(); },'json');
});

// ── 批次建立登記（僅超級管理員 員工ID=1）──
let batchMembers=[];
$('#btnBatchAdd').on('click',function(){
  $('#batchDeptWrap').html(DEPTS.map(d=>`<label style="display:block;font-weight:normal;margin:2px 0;"><input type="checkbox" class="batch-dept" value="${d.id}"> ${esc(d.name)}</label>`).join('')||'<span class="text-muted">（無部門資料）</span>');
  $('#batchTplWrap').html((TPLS||[]).filter(t=>+t.is_active===1).map(t=>`<label style="display:block;font-weight:normal;margin:2px 0;"><input type="checkbox" class="batch-tpl" value="${t.id}"> ${t.type_name?esc(t.type_name)+'｜':''}${esc(t.tpl_name)}</label>`).join('')||'<span class="text-muted">（尚無啟用模板）</span>');
  $('#batchDate').val(today());
  $('#batchPreviewBody').html('<tr><td colspan="4" class="text-muted">請先選部門與模板後按「產生名單」。</td></tr>');
  $('#batchResult').text(''); $('#batchChkAll').prop('checked',true);
  $('#batchModal').modal('show');
});
// 依印章種類 bind_targets 判斷此模板屬於批次的哪種模式：個人(不綁部門，同人只列一次)／部門所屬人員(dept_id+user_id組合，主/兼任部門各一筆)；純部門或純職稱綁定不涉及挑人，批次不適用
function batchTplKind(tpl){
  const t=TYPES.find(x=>String(x.id)===String(tpl.type_id));
  const bt=t&&t.bind_targets?t.bind_targets.split(',').filter(Boolean):[];
  if(!bt.length) return 'user';
  const hasU=bt.includes('user'), hasD=bt.includes('dept');
  if(hasU&&hasD) return 'user_dept';
  if(hasU) return 'user';
  return null;
}
function batchRowHtml(tpl,kind,uid,uname,deptId,deptLabel){
  return `<tr><td><input type="checkbox" class="batch-row-chk" checked
      data-tpl="${tpl.id}" data-type="${tpl.type_id||''}" data-kind="${kind}" data-uid="${uid}" data-dept="${deptId||''}"></td>
    <td>${tpl.type_name?esc(tpl.type_name)+'｜':''}${esc(tpl.tpl_name)}</td>
    <td>${deptLabel?esc(deptLabel):'<span class="text-muted">—</span>'}</td>
    <td>${esc(uname)}</td></tr>`;
}
$('#btnBatchGen').on('click',function(){
  const deptIds=$('.batch-dept:checked').map((i,el)=>el.value).get();
  const tplIds=$('.batch-tpl:checked').map((i,el)=>el.value).get();
  if(!deptIds.length){alert('請至少選一個部門');return;}
  if(!tplIds.length){alert('請至少選一種圖章模板');return;}
  $.getJSON(API,{action:'batch_members',dept_ids:deptIds.join(',')},r=>{
    if(!r.ok){alert(r.error||'載入名單失敗');return;}
    batchMembers=r.rows||[];
    const tpls=tplIds.map(id=>(TPLS||[]).find(t=>String(t.id)===String(id))).filter(Boolean);
    const rowsHtml=[];
    tpls.forEach(tpl=>{
      const kind=batchTplKind(tpl);
      if(!kind){ rowsHtml.push(`<tr><td colspan="4" class="text-muted">「${esc(tpl.tpl_name)}」種類非個人／部門所屬人員綁定，不適用批次建立，已略過。</td></tr>`); return; }
      if(kind==='user'){
        const seen=new Set();
        batchMembers.forEach(m=>{
          if(seen.has(m.user_id)) return; seen.add(m.user_id);
          rowsHtml.push(batchRowHtml(tpl,kind,m.user_id,m.user_cname,null,''));
        });
      }else{
        batchMembers.forEach(m=>{
          rowsHtml.push(batchRowHtml(tpl,kind,m.user_id,m.user_cname,m.department_id,m.dept_name+(+m.is_main?'':'（兼）')));
        });
      }
    });
    $('#batchPreviewBody').html(rowsHtml.join('')||'<tr><td colspan="4" class="text-muted">選定部門內查無在職成員。</td></tr>');
    $('#batchResult').text(''); $('#batchChkAll').prop('checked',true);
  });
});
$('#batchChkAll').on('change',function(){ $('.batch-row-chk').prop('checked',this.checked); });
$('#btnBatchSave').on('click',function(){
  const items=$('.batch-row-chk:checked').map((i,el)=>{
    const $e=$(el);
    return {template_id:$e.data('tpl'),type_id:$e.data('type'),kind:$e.data('kind'),user_id:$e.data('uid'),dept_id:$e.data('dept')||''};
  }).get();
  if(!items.length){alert('請至少勾選一筆');return;}
  const issue=$('#batchDate').val();
  if(!/^\d{4}-\d{2}-\d{2}$/.test(issue)){alert('請選擇核發日期');return;}
  $(this).prop('disabled',true);
  $.post(API+'?action=batch_add',{issue_date:issue,items:JSON.stringify(items)},r=>{
    $('#btnBatchSave').prop('disabled',false);
    if(!r.ok){alert(r.error||'批次建立失敗');return;}
    $('#batchResult').html(`已建立 ${r.created} 筆，略過(已存在使用中／模板已停用) ${r.skipped} 筆。`);
    loadList(1);
  },'json').fail(()=>{ $('#btnBatchSave').prop('disabled',false); alert('批次建立失敗'); });
});

loadMeta();
</script>
</body>
</html>
