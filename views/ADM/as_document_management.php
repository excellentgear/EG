<?php
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
$db_connection = new DBConnection();
$conn = $db_connection->getPDO();

$stmt = $conn->prepare("SELECT id FROM user WHERE user_uname = ?");
$stmt->execute([$_SESSION['userName']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) { header("Location:../../index.php"); exit; }

// --- 系統頁面權限判斷（同 department_job_title_settings.php 慣例）---
$id = $currentUser['id'];
$current_script_path = $_SERVER['PHP_SELF'];
$deptPerm = null;
$page_url_editable = '';
$page_url_readonly = '';
try {
    $sql_page_info = "SELECT smp.page_id, smp.page_url, smp.page_url_readonly, smp.group_id
        FROM system_module_pages smp
        WHERE (:script LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
           OR (:script LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
        LIMIT 1";
    $stmt_page_info = $conn->prepare($sql_page_info);
    $stmt_page_info->execute([':script' => $current_script_path]);
    $page_info = $stmt_page_info->fetch(PDO::FETCH_ASSOC);
    if ($page_info) {
        $page_url_editable = $page_info['page_url'];
        $page_url_readonly = $page_info['page_url_readonly'];
        $page_id  = $page_info['page_id'];
        $group_id = $page_info['group_id'];
        $sql_page_perm = "SELECT permission FROM user_module_permissions WHERE user_id = ? AND scope = 'page' AND module_code = ?";
        $stmt_page_perm = $conn->prepare($sql_page_perm);
        $stmt_page_perm->execute([$id, $page_id]);
        $perms_found = $stmt_page_perm->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms_found) && !empty($group_id)) {
            $sql_group_module = "SELECT module_code FROM system_modules WHERE group_id = ? LIMIT 1";
            $stmt_group_module = $conn->prepare($sql_group_module);
            $stmt_group_module->execute([$group_id]);
            $group_module_code = $stmt_group_module->fetchColumn();
            if ($group_module_code) {
                $sql_group_perm = "SELECT permission FROM user_module_permissions WHERE user_id = ? AND scope = 'group' AND module_code = ?";
                $stmt_group_perm = $conn->prepare($sql_group_perm);
                $stmt_group_perm->execute([$id, $group_module_code]);
                $perms_found = $stmt_group_perm->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $all_chars = [];
        foreach ($perms_found as $pStr) { $all_chars = array_merge($all_chars, str_split($pStr)); }
        $unique_chars = array_unique($all_chars);
        if (in_array('A', $unique_chars)) { $deptPerm = 'A'; }
        else { sort($unique_chars); $deptPerm = implode('', $unique_chars); }
    }
} catch (Exception $e) { error_log("Permission check error: " . $e->getMessage()); }

if (empty($deptPerm)) {
    header("Location:../../src/store/Login.php?msg=" . urlencode("無權限檢視頁面")); exit;
}
if ($deptPerm === 'R') {
    if (!empty($page_url_editable) && substr($current_script_path, -strlen($page_url_editable)) === $page_url_editable) {
        if (!empty($page_url_readonly)) { header("Location: " . $page_url_readonly); exit; }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AS9100 文件管理 | Excellentgear</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        .tag-chip{display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:12px;margin:1px 2px;white-space:nowrap;}
        .tag-filter{cursor:pointer;border:1px solid transparent;opacity:.55;}
        .tag-filter.active{opacity:1;border-color:#333;box-shadow:0 0 0 2px rgba(0,0,0,.15);}
        .perm-row td{vertical-align:middle;}
        .doc-table td{vertical-align:middle;}
        .req-note{color:#a94442;font-size:12px;}
        .scroll-to-top{position:fixed;bottom:20px;right:20px;width:50px;height:50px;background:rgba(255,255,255,.6);color:#000;border:none;border-radius:50%;text-align:center;line-height:50px;cursor:pointer;font-size:12px;font-weight:bold;box-shadow:0 4px 8px rgba(0,0,0,.2);z-index:1000;}
        .apply-alert{background:#fcf8e3;border:1px solid #faebcc;color:#8a6d3b;padding:10px;border-radius:4px;margin-bottom:12px;}
    </style>
</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
      <div class="page-title">
        <div class="title_left">
          <h3>AS9100 文件管理
            <small>(權限：<?php echo htmlspecialchars($deptPerm); ?>)</small>
            <a href="#" id="rbacHelp" title="權限說明"><i class="fa fa-question-circle"></i></a>
          </h3>
        </div>
      </div>
      <div class="clearfix"></div>

      <div class="row">
        <div class="col-md-12">
          <div class="x_panel">
            <div class="x_title">
              <h2>文件清單</h2>
              <ul class="nav navbar-right panel_toolbox">
                <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
              </ul>
              <div class="clearfix"></div>
            </div>
            <div class="x_content">

              <!-- 工具列 -->
              <div class="row" style="margin-bottom:10px;">
                <div class="col-md-8">
                  <?php if (strpos($deptPerm,'A')!==false || strpos($deptPerm,'C')!==false): ?>
                  <button class="btn btn-primary btn-sm" id="btnAddDoc"><i class="fa fa-plus"></i> 新增文件</button>
                  <?php endif; ?>
                  <button class="btn btn-default btn-sm" id="btnTags"><i class="fa fa-tags"></i> 標籤 / 分類管理</button>
                  <?php if (strpos($deptPerm,'A')!==false): ?>
                  <button class="btn btn-warning btn-sm" id="btnSettings"><i class="fa fa-cog"></i> 系統設定（負責人 / 路徑）</button>
                  <?php endif; ?>
                </div>
                <div class="col-md-4 text-right">
                  <label style="font-weight:normal;"><input type="checkbox" id="incDeleted"> 顯示已刪除</label>
                </div>
              </div>

              <!-- 搜尋 / 篩選 -->
              <div class="row" style="margin-bottom:8px;">
                <div class="col-md-4">
                  <input type="text" class="form-control" id="searchKw" placeholder="搜尋 文件編號 / 文件名稱…">
                </div>
                <div class="col-md-3">
                  <select class="form-control" id="filterLevel">
                    <option value="">全部階級</option>
                    <option value="一階">一階（品質手冊）</option>
                    <option value="二階">二階（程序書）</option>
                    <option value="三階">三階（標準書/作業辦法）</option>
                    <option value="四階">四階（表單/記錄）</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <select class="form-control" id="filterDept"><option value="">全部部門</option></select>
                </div>
                <div class="col-md-2">
                  <button class="btn btn-default btn-block" id="btnClearFilter"><i class="fa fa-eraser"></i> 清除</button>
                </div>
              </div>
              <div class="row" style="margin-bottom:12px;">
                <div class="col-md-12">
                  <span class="text-muted" style="font-size:12px;">標籤篩選：</span>
                  <span id="tagFilterBar"></span>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table table-striped table-hover doc-table">
                  <thead>
                    <tr>
                      <th>文件編號</th><th>文件名稱</th><th>類別</th><th>階級</th><th>部門</th>
                      <th>目前版本</th><th>修訂日期</th><th>標籤</th><th style="min-width:230px;">操作</th>
                    </tr>
                  </thead>
                  <tbody id="docTableBody"></tbody>
                </table>
              </div>

              <!-- 分頁 -->
              <div class="row">
                <div class="col-md-6">
                  每頁
                  <select id="pageSize" style="width:auto;display:inline-block;" class="form-control input-sm">
                    <option>5</option><option selected>10</option><option>20</option><option>50</option>
                  </select> 筆
                  <span class="text-muted" id="totalInfo" style="margin-left:10px;"></span>
                </div>
                <div class="col-md-6 text-right">
                  <ul class="pagination" id="pager" style="margin:0;"></ul>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<button class="scroll-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})">回頂端</button>

<!-- ═════════ 新增 / 編輯 文件 Modal ═════════ -->
<div class="modal fade" id="docModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form id="docForm" enctype="multipart/form-data">
        <input type="hidden" name="id" id="doc_id">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal">&times;</button>
          <h4 class="modal-title" id="docModalTitle">新增文件</h4>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="form-group col-md-6"><label>文件編號 *</label><input type="text" class="form-control" name="doc_no" id="doc_no" required></div>
            <div class="form-group col-md-6"><label>文件名稱 *</label><input type="text" class="form-control" name="doc_name" id="doc_name" required></div>
          </div>
          <div class="row">
            <div class="form-group col-md-4"><label>文件類別</label>
              <select class="form-control" name="doc_type" id="doc_type">
                <option value="">--</option><option>手冊</option><option>程序</option><option>標準書</option><option>表單</option>
              </select>
            </div>
            <div class="form-group col-md-4"><label>文件階級</label>
              <select class="form-control" name="doc_level" id="doc_level">
                <option value="">--</option><option value="一階">一階（品質手冊）</option><option value="二階">二階（程序書）</option><option value="三階">三階（標準書/作業辦法）</option><option value="四階">四階（表單/記錄）</option>
              </select>
            </div>
            <div class="form-group col-md-4"><label>所屬部門</label>
              <select class="form-control" name="department_id" id="doc_department_id"><option value="">跨部門 / 未指定</option></select>
            </div>
          </div>
          <div class="form-group">
            <label>標籤 / 分類</label>
            <div id="docTagPicker" style="border:1px solid #ddd;border-radius:4px;padding:8px;min-height:40px;"></div>
            <input type="hidden" name="tag_ids" id="doc_tag_ids">
          </div>

          <hr>
          <div id="firstVersionBlock">
            <h4 style="margin-top:0;">首版資訊</h4>
            <div class="row">
              <div class="form-group col-md-3"><label>版本號 *</label><input type="text" class="form-control" name="version" id="doc_version" placeholder="如 1.0" required></div>
              <div class="form-group col-md-3"><label>文件狀況</label>
                <select class="form-control" name="change_status" id="doc_change_status">
                  <option>制訂</option><option>修正</option><option>廢止</option><option>增發</option><option>補發</option>
                </select>
              </div>
              <div class="form-group col-md-3"><label>修訂日期</label><input type="date" class="form-control" name="revised_date" id="doc_revised_date"></div>
              <div class="form-group col-md-3"><label>制修訂頁次</label><input type="text" class="form-control" name="revised_pages" id="doc_revised_pages" placeholder="如 全冊 / 1-2"></div>
            </div>
            <div class="form-group"><label>制修訂摘要</label><textarea class="form-control" name="revised_summary" id="doc_revised_summary" rows="2"></textarea></div>
            <div class="row">
              <div class="form-group col-md-6"><label>文件檔 *</label><input type="file" name="file" id="doc_file"></div>
              <div class="form-group col-md-6"><label>文件制修申請單（附件一，首版可選）</label><input type="file" name="apply_form" id="doc_apply_form"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-primary">儲存</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═════════ 改版 Modal ═════════ -->
<div class="modal fade" id="versionModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form id="versionForm" enctype="multipart/form-data">
        <input type="hidden" name="doc_id" id="ver_doc_id">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal">&times;</button>
          <h4 class="modal-title">文件改版 － <span id="ver_doc_name"></span></h4>
        </div>
        <div class="modal-body">
          <div class="apply-alert">
            <i class="fa fa-exclamation-triangle"></i>
            文件改版依規定須檢附「<strong>文件制修申請單（附件一）</strong>」。
            <a href="#" id="dlTplBtn" class="btn btn-xs btn-default"><i class="fa fa-download"></i> 下載申請單範本</a>
            填妥後於下方一併上傳。
          </div>
          <div class="row">
            <div class="form-group col-md-3"><label>新版本號 *</label><input type="text" class="form-control" name="version" id="ver_version" required></div>
            <div class="form-group col-md-3"><label>文件狀況</label>
              <select class="form-control" name="change_status" id="ver_change_status">
                <option>修正</option><option>制訂</option><option>廢止</option><option>增發</option><option>補發</option>
              </select>
            </div>
            <div class="form-group col-md-3"><label>修訂日期</label><input type="date" class="form-control" name="revised_date" id="ver_revised_date"></div>
            <div class="form-group col-md-3"><label>制修訂頁次</label><input type="text" class="form-control" name="revised_pages" id="ver_revised_pages"></div>
          </div>
          <div class="form-group"><label>制修訂摘要</label><textarea class="form-control" name="revised_summary" id="ver_revised_summary" rows="2"></textarea></div>
          <div class="row">
            <div class="form-group col-md-6"><label>新版文件檔 *</label><input type="file" name="file" id="ver_file" required></div>
            <div class="form-group col-md-6"><label>文件制修申請單（附件一）* <span class="req-note">改版必附</span></label><input type="file" name="apply_form" id="ver_apply_form" required></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-primary">送出改版</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═════════ 歷史版本 Modal ═════════ -->
<div class="modal fade" id="historyModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">歷史版本 － <span id="his_doc_name"></span></h4>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-bordered table-condensed">
            <thead><tr>
              <th>版本</th><th>狀況</th><th>階級</th><th>部門</th><th>修訂日期</th><th>制修訂頁次</th><th>制修訂摘要</th><th>上傳者</th><th>文件</th><th>申請單</th>
            </tr></thead>
            <tbody id="historyBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div>
  </div>
</div>

<!-- ═════════ 權限設定 Modal ═════════ -->
<div class="modal fade" id="permModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">開啟權限設定 － <span id="perm_doc_name"></span></h4>
      </div>
      <div class="modal-body">
        <p class="text-muted" style="font-size:13px;">
          每一列為一組條件（多列＝多部門多職稱）。類型可選「指定職稱」或「職稱層級以上」（如：品保課 二階主管以上）。
        </p>
        <input type="hidden" id="perm_doc_id">
        <div class="table-responsive">
          <table class="table table-bordered table-condensed">
            <thead><tr>
              <th style="width:110px;">類型</th><th>部門</th><th>職稱 / 層級</th>
              <th style="width:60px;">讀取</th><th style="width:60px;">下載</th><th style="width:60px;">更新</th><th style="width:60px;">刪除</th><th style="width:50px;"></th>
            </tr></thead>
            <tbody id="permBody"></tbody>
          </table>
        </div>
        <button class="btn btn-sm btn-success" id="permAddRow"><i class="fa fa-plus"></i> 新增條件</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="permSave">儲存權限</button>
      </div>
    </div>
  </div>
</div>

<!-- ═════════ 標籤管理 Modal ═════════ -->
<div class="modal fade" id="tagModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">標籤 / 分類管理</h4>
      </div>
      <div class="modal-body">
        <div class="row" style="margin-bottom:10px;">
          <div class="col-md-5"><input type="text" class="form-control input-sm" id="tag_name" placeholder="標籤名稱"></div>
          <div class="col-md-3"><input type="color" class="form-control input-sm" id="tag_color" value="#1ABB9C"></div>
          <div class="col-md-2"><input type="number" class="form-control input-sm" id="tag_sort" value="0" title="排序"></div>
          <div class="col-md-2"><button class="btn btn-sm btn-success btn-block" id="tagAdd">新增</button></div>
        </div>
        <table class="table table-condensed"><tbody id="tagList"></tbody></table>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div>
  </div>
</div>

<!-- ═════════ 系統設定 Modal ═════════ -->
<div class="modal fade" id="settingsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">系統設定</h4>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>NAS 儲存根路徑</label>
          <input type="text" class="form-control" id="set_nas_dir">
          <span class="text-muted" style="font-size:12px;">預設 \\excellentnas\as9100\ERP測試。僅存根路徑，實際檔案路徑於讀取時現場組出（不寫死於資料庫）。</span>
        </div>
        <div class="form-group">
          <label>AS 負責人（指定人員）</label>
          <select class="form-control" id="set_owner"><option value="">-- 未指定 --</option></select>
        </div>
        <div class="form-group">
          <label>代理人（可不設定）</label>
          <select class="form-control" id="set_deputy"><option value="">-- 無 --</option></select>
        </div>
        <hr>
        <div class="form-group">
          <label>文件制修申請單（附件一）範本</label>
          <div>目前範本：<span id="tplStatus" class="text-muted">未上傳</span>
            <a href="#" id="tplDownload" class="btn btn-xs btn-default" style="display:none;"><i class="fa fa-download"></i> 下載</a>
          </div>
          <input type="file" id="tplFile" style="margin-top:6px;">
          <button class="btn btn-sm btn-info" id="tplUpload" style="margin-top:6px;">上傳範本</button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        <button type="button" class="btn btn-primary" id="settingsSave">儲存設定</button>
      </div>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
window.deptPerm = "<?php echo $deptPerm ? $deptPerm : ''; ?>";
$(function(){
  const API = '../../src/store/AS_Document_API.php';
  const perm = window.deptPerm || '';
  const canC = perm.includes('A') || perm.includes('C');
  const canU = perm.includes('A') || perm.includes('U');
  const canD = perm.includes('A') || perm.includes('D');
  let META = {departments:[],positions:[],tags:[],users:[]};
  let DOCS = [], FILTERED = [], activeTagId = 0, curPage = 1;

  function esc(t){ if(t===null||t===undefined) return ''; return String(t).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

  function loadMeta(cb){
    $.getJSON(API+'?action=meta', r=>{
      if(r.status!=='success'){ alert('載入基礎資料失敗'); return; }
      META = r;
      // 部門下拉
      const dOpts = '<option value="">全部部門</option>' + META.departments.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join('');
      $('#filterDept').html(dOpts);
      $('#doc_department_id').html('<option value="">跨部門 / 未指定</option>' + META.departments.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join(''));
      // 使用者下拉（負責人/代理人）
      const uOpts = META.users.map(u=>`<option value="${u.id}">${esc(u.user_cname)}</option>`).join('');
      $('#set_owner').html('<option value="">-- 未指定 --</option>'+uOpts);
      $('#set_deputy').html('<option value="">-- 無 --</option>'+uOpts);
      renderTagFilter();
      if(cb) cb();
    });
  }

  function renderTagFilter(){
    const bar = $('#tagFilterBar').empty();
    bar.append(`<span class="tag-chip tag-filter ${activeTagId===0?'active':''}" data-id="0" style="background:#777;">全部</span>`);
    META.tags.forEach(t=>{
      bar.append(`<span class="tag-chip tag-filter ${activeTagId==t.id?'active':''}" data-id="${t.id}" style="background:${esc(t.color)};">${esc(t.name)}</span>`);
    });
  }

  function loadDocs(){
    const p = {
      keyword: $('#searchKw').val().trim(),
      level: $('#filterLevel').val(),
      department_id: $('#filterDept').val(),
      tag_id: activeTagId,
      include_deleted: $('#incDeleted').is(':checked') ? '1':'0'
    };
    $.getJSON(API+'?action=list_documents', p, r=>{
      if(r.status!=='success'){ alert(r.message||'讀取失敗'); return; }
      DOCS = r.data; curPage = 1; renderDocs();
    });
  }

  function renderDocs(){
    const size = parseInt($('#pageSize').val())||10;
    const total = DOCS.length;
    const pages = Math.max(1, Math.ceil(total/size));
    if(curPage>pages) curPage=pages;
    const start = (curPage-1)*size;
    const rows = DOCS.slice(start, start+size);
    const tb = $('#docTableBody').empty();
    if(rows.length===0){ tb.append('<tr><td colspan="9" class="text-center text-muted">無資料</td></tr>'); }
    rows.forEach(d=>{
      const tags = (d.tags||[]).map(t=>`<span class="tag-chip" style="background:${esc(t.color)};">${esc(t.name)}</span>`).join(' ');
      let ops = '';
      const curVer = d.current_version_id;
      ops += `<button class="btn btn-xs btn-default op-hist" data-id="${d.id}" data-name="${esc(d.doc_name)}">歷史版本</button> `;
      if(curVer) ops += `<a class="btn btn-xs btn-info" href="${API}?action=download&which=file&version_id=${curVer}">下載</a> `;
      if(canU){
        ops += `<button class="btn btn-xs btn-warning op-ver" data-id="${d.id}" data-name="${esc(d.doc_name)}">改版</button> `;
        ops += `<button class="btn btn-xs btn-default op-edit" data-id="${d.id}">編輯</button> `;
        ops += `<button class="btn btn-xs btn-primary op-perm" data-id="${d.id}" data-name="${esc(d.doc_name)}">權限</button> `;
      }
      if(canD){
        if(d.is_deleted==1) ops += `<button class="btn btn-xs btn-success op-restore" data-id="${d.id}">還原</button>`;
        else ops += `<button class="btn btn-xs btn-danger op-del" data-id="${d.id}">刪除</button>`;
      }
      const delMark = d.is_deleted==1 ? ' <span class="label label-default">已刪除</span>' : '';
      tb.append(`<tr>
        <td>${esc(d.doc_no)}${delMark}</td>
        <td>${esc(d.doc_name)}</td>
        <td>${esc(d.doc_type)||'-'}</td>
        <td>${esc(d.doc_level)||'-'}</td>
        <td>${esc(d.dept_name)||'<span class="text-muted">跨部門</span>'}</td>
        <td><span class="label label-info">${esc(d.current_version)||'-'}</span></td>
        <td>${esc(d.revised_date)||'-'}</td>
        <td>${tags||'-'}</td>
        <td class="text-nowrap">${ops}</td>
      </tr>`);
    });
    $('#totalInfo').text(`共 ${total} 筆`);
    // 分頁列
    const pg = $('#pager').empty();
    function pi(label,page,dis,act){ return `<li class="${dis?'disabled':''} ${act?'active':''}"><a href="#" data-page="${page}">${label}</a></li>`; }
    pg.append(pi('«', curPage-1, curPage===1, false));
    for(let i=1;i<=pages;i++){ if(i===1||i===pages||Math.abs(i-curPage)<=2){ pg.append(pi(i,i,false,i===curPage)); } else if(Math.abs(i-curPage)===3){ pg.append('<li class="disabled"><a>…</a></li>'); } }
    pg.append(pi('»', curPage+1, curPage===pages, false));
  }

  $('#pager').on('click','a',function(e){ e.preventDefault(); const p=parseInt($(this).data('page')); if(!isNaN(p)&&p>=1){ curPage=p; renderDocs(); }});
  $('#pageSize').on('change', renderDocs);
  $('#searchKw').on('keyup', function(e){ if(e.key==='Enter') loadDocs(); });
  $('#filterLevel,#filterDept,#incDeleted').on('change', loadDocs);
  $('#btnClearFilter').on('click', ()=>{ $('#searchKw').val(''); $('#filterLevel').val(''); $('#filterDept').val(''); activeTagId=0; renderTagFilter(); loadDocs(); });
  $('#tagFilterBar').on('click','.tag-filter', function(){ activeTagId=parseInt($(this).data('id'))||0; renderTagFilter(); loadDocs(); });
  // 雙擊清空搜尋欄
  $('#searchKw').on('dblclick', function(){ $(this).val(''); loadDocs(); });

  // 標籤選擇器（新增/編輯文件用）
  function renderDocTagPicker(selected){
    selected = selected||[];
    const box = $('#docTagPicker').empty();
    if(META.tags.length===0){ box.append('<span class="text-muted">尚無標籤，請先於「標籤/分類管理」建立</span>'); }
    META.tags.forEach(t=>{
      const on = selected.includes(String(t.id))||selected.includes(t.id);
      box.append(`<span class="tag-chip tag-filter ${on?'active':''}" data-id="${t.id}" style="background:${esc(t.color)};">${esc(t.name)}</span>`);
    });
    syncDocTagIds();
  }
  $('#docTagPicker').on('click','.tag-filter', function(){ $(this).toggleClass('active'); syncDocTagIds(); });
  function syncDocTagIds(){ const ids=[]; $('#docTagPicker .tag-filter.active').each(function(){ ids.push($(this).data('id')); }); $('#doc_tag_ids').val(ids.join(',')); }

  // ── 新增文件 ──
  $('#btnAddDoc').on('click', function(){
    $('#docForm')[0].reset(); $('#doc_id').val(''); $('#doc_tag_ids').val('');
    $('#docModalTitle').text('新增文件'); $('#firstVersionBlock').show();
    $('#doc_file').prop('required',true); $('#doc_version').prop('required',true);
    renderDocTagPicker([]);
    $('#docModal').modal('show');
  });

  // ── 編輯文件 ──
  $('#docTableBody').on('click','.op-edit', function(){
    const id=$(this).data('id');
    $.getJSON(API+'?action=get_document',{id:id}, r=>{
      if(r.status!=='success'){ alert(r.message); return; }
      const d=r.data;
      $('#docForm')[0].reset();
      $('#docModalTitle').text('編輯文件');
      $('#doc_id').val(d.id); $('#doc_no').val(d.doc_no); $('#doc_name').val(d.doc_name);
      $('#doc_type').val(d.doc_type||''); $('#doc_level').val(d.doc_level||''); $('#doc_department_id').val(d.department_id||'');
      $('#firstVersionBlock').hide();
      $('#doc_file').prop('required',false); $('#doc_version').prop('required',false);
      renderDocTagPicker((d.tags||[]).map(t=>t.id));
      $('#docModal').modal('show');
    });
  });

  $('#docForm').on('submit', function(e){
    e.preventDefault();
    const isEdit = $('#doc_id').val()!=='';
    const url = API + '?action=' + (isEdit?'update_document_meta':'create_document');
    const fd = new FormData(this);
    NProgress.start();
    $.ajax({url:url, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
     .done(r=>{ if(r.status==='success'){ $('#docModal').modal('hide'); loadDocs(); } else alert(r.message||'失敗'); })
     .fail(()=>alert('請求失敗')).always(()=>NProgress.done());
  });

  // ── 改版 ──
  $('#docTableBody').on('click','.op-ver', function(){
    $('#versionForm')[0].reset();
    $('#ver_doc_id').val($(this).data('id'));
    $('#ver_doc_name').text($(this).data('name'));
    $('#versionModal').modal('show');
  });
  $('#dlTplBtn').on('click', function(e){ e.preventDefault(); window.location = API+'?action=download_template'; });
  $('#versionForm').on('submit', function(e){
    e.preventDefault();
    const fd = new FormData(this);
    NProgress.start();
    $.ajax({url:API+'?action=add_version', type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
     .done(r=>{ if(r.status==='success'){ $('#versionModal').modal('hide'); loadDocs(); } else alert(r.message||'失敗'); })
     .fail(()=>alert('請求失敗')).always(()=>NProgress.done());
  });

  // ── 歷史版本 ──
  $('#docTableBody').on('click','.op-hist', function(){
    const id=$(this).data('id'); $('#his_doc_name').text($(this).data('name'));
    $.getJSON(API+'?action=get_document',{id:id}, r=>{
      if(r.status!=='success'){ alert(r.message); return; }
      const tb=$('#historyBody').empty();
      (r.data.versions||[]).forEach(v=>{
        const dl = `<a class="btn btn-xs btn-info" href="${API}?action=download&which=file&version_id=${v.id}">下載</a>`;
        const af = v.apply_form_file_name ? `<a class="btn btn-xs btn-default" href="${API}?action=download&which=apply&version_id=${v.id}">申請單</a>` : '<span class="text-muted">無</span>';
        tb.append(`<tr>
          <td><span class="label label-info">${esc(v.version)}</span></td>
          <td>${esc(v.change_status)||'-'}</td>
          <td>${esc(v.doc_level_snapshot)||'-'}</td>
          <td>${esc(v.dept_name_snapshot)||'-'}</td>
          <td>${esc(v.revised_date)||'-'}</td>
          <td>${esc(v.revised_pages)||'-'}</td>
          <td>${esc(v.revised_summary)||'-'}</td>
          <td>${esc(v.uploaded_by)||'-'}</td>
          <td>${dl}</td><td>${af}</td>
        </tr>`);
      });
      if((r.data.versions||[]).length===0) tb.append('<tr><td colspan="10" class="text-center text-muted">無版本</td></tr>');
      $('#historyModal').modal('show');
    });
  });

  // ── 刪除 / 還原 ──
  $('#docTableBody').on('click','.op-del', function(){
    if(!confirm('確定刪除此文件？（舊版與檔案仍會保留，可於「顯示已刪除」中還原）')) return;
    $.post(API+'?action=delete_document',{id:$(this).data('id')}, r=>{ if(r.status==='success') loadDocs(); else alert(r.message); },'json');
  });
  $('#docTableBody').on('click','.op-restore', function(){
    $.post(API+'?action=restore_document',{id:$(this).data('id')}, r=>{ if(r.status==='success') loadDocs(); else alert(r.message); },'json');
  });

  // ── 權限設定 ──
  function permRowHtml(row){
    row = row||{perm_type:'position',can_read:1};
    const depOpts = '<option value="">請選擇部門</option>'+META.departments.map(d=>`<option value="${d.id}" ${row.department_id==d.id?'selected':''}>${esc(d.name)}</option>`).join('');
    const posOpts = '<option value="">請選擇職稱</option>'+META.positions.map(p=>`<option value="${p.id}" ${row.position_id==p.id?'selected':''}>${esc(p.name)}</option>`).join('');
    const lvlOpts = '<option value="">層級以上</option>'+[1,2,3].map(l=>`<option value="${l}" ${row.min_level==l?'selected':''}>${l} 階主管以上</option>`).join('');
    const isLevel = row.perm_type==='level';
    return `<tr class="perm-row">
      <td><select class="form-control input-sm perm-type">
            <option value="position" ${!isLevel?'selected':''}>指定職稱</option>
            <option value="level" ${isLevel?'selected':''}>層級以上</option></select></td>
      <td><select class="form-control input-sm perm-dept">${depOpts}</select></td>
      <td>
        <select class="form-control input-sm perm-pos" style="${isLevel?'display:none;':''}">${posOpts}</select>
        <select class="form-control input-sm perm-lvl" style="${isLevel?'':'display:none;'}">${lvlOpts}</select>
      </td>
      <td class="text-center"><input type="checkbox" class="perm-read" ${row.can_read==1?'checked':''}></td>
      <td class="text-center"><input type="checkbox" class="perm-download" ${row.can_download==1?'checked':''}></td>
      <td class="text-center"><input type="checkbox" class="perm-update" ${row.can_update==1?'checked':''}></td>
      <td class="text-center"><input type="checkbox" class="perm-delete" ${row.can_delete==1?'checked':''}></td>
      <td class="text-center"><button class="btn btn-xs btn-danger perm-remove"><i class="fa fa-trash"></i></button></td>
    </tr>`;
  }
  $('#docTableBody').on('click','.op-perm', function(){
    const id=$(this).data('id'); $('#perm_doc_id').val(id); $('#perm_doc_name').text($(this).data('name'));
    $.getJSON(API+'?action=get_perms',{doc_id:id}, r=>{
      const tb=$('#permBody').empty();
      (r.data||[]).forEach(row=>tb.append(permRowHtml(row)));
      $('#permModal').modal('show');
    });
  });
  $('#permAddRow').on('click', ()=>$('#permBody').append(permRowHtml()));
  $('#permBody').on('click','.perm-remove', function(){ $(this).closest('tr').remove(); });
  $('#permBody').on('change','.perm-type', function(){
    const tr=$(this).closest('tr'); const lvl=$(this).val()==='level';
    tr.find('.perm-pos').toggle(!lvl); tr.find('.perm-lvl').toggle(lvl);
  });
  $('#permSave').on('click', function(){
    const rows=[];
    $('#permBody tr').each(function(){
      const tr=$(this);
      rows.push({
        perm_type: tr.find('.perm-type').val(),
        department_id: tr.find('.perm-dept').val(),
        position_id: tr.find('.perm-pos').val(),
        min_level: tr.find('.perm-lvl').val(),
        can_read: tr.find('.perm-read').is(':checked')?1:0,
        can_download: tr.find('.perm-download').is(':checked')?1:0,
        can_update: tr.find('.perm-update').is(':checked')?1:0,
        can_delete: tr.find('.perm-delete').is(':checked')?1:0
      });
    });
    $.post(API+'?action=save_perms', {doc_id:$('#perm_doc_id').val(), rows:JSON.stringify(rows)}, r=>{
      if(r.status==='success'){ $('#permModal').modal('hide'); } else alert(r.message);
    },'json');
  });

  // ── 標籤管理 ──
  function loadTagList(){
    $.getJSON(API+'?action=list_tags', r=>{
      const tb=$('#tagList').empty();
      (r.data||[]).forEach(t=>{
        tb.append(`<tr>
          <td><span class="tag-chip" style="background:${esc(t.color)};">${esc(t.name)}</span></td>
          <td class="text-muted">排序 ${esc(t.sort_order)}</td>
          <td class="text-right">
            <button class="btn btn-xs btn-info tag-edit" data-id="${t.id}" data-name="${esc(t.name)}" data-color="${esc(t.color)}" data-sort="${esc(t.sort_order)}">編輯</button>
            <button class="btn btn-xs btn-danger tag-del" data-id="${t.id}">刪除</button>
          </td></tr>`);
      });
      META.tags = r.data||[]; renderTagFilter();
    });
  }
  $('#btnTags').on('click', ()=>{ loadTagList(); $('#tag_name').val(''); $('#tagAdd').data('edit',''); $('#tagAdd').text('新增'); $('#tagModal').modal('show'); });
  $('#tagAdd').on('click', function(){
    const name=$('#tag_name').val().trim(); if(!name){ alert('請輸入標籤名稱'); return; }
    const editId=$(this).data('edit');
    const action = editId ? 'update_tag':'add_tag';
    const data={name:name,color:$('#tag_color').val(),sort_order:$('#tag_sort').val()};
    if(editId) data.id=editId;
    $.post(API+'?action='+action, data, r=>{
      if(r.status==='success'){ $('#tag_name').val(''); $('#tagAdd').data('edit','').text('新增'); loadTagList(); } else alert(r.message);
    },'json');
  });
  $('#tagList').on('click','.tag-edit', function(){
    $('#tag_name').val($(this).data('name')); $('#tag_color').val($(this).data('color')); $('#tag_sort').val($(this).data('sort'));
    $('#tagAdd').data('edit',$(this).data('id')).text('更新');
  });
  $('#tagList').on('click','.tag-del', function(){
    if(!confirm('刪除此標籤？（文件上的此標籤關聯也會移除）')) return;
    $.post(API+'?action=delete_tag',{id:$(this).data('id')}, r=>{ if(r.status==='success') loadTagList(); else alert(r.message); },'json');
  });

  // ── 系統設定 ──
  $('#btnSettings').on('click', function(){
    $.getJSON(API+'?action=get_settings', r=>{
      const d=r.data;
      $('#set_nas_dir').val(d.nas_dir); $('#set_owner').val(d.owner_user_id||''); $('#set_deputy').val(d.deputy_user_id||'');
      if(d.apply_form_tpl){ $('#tplStatus').text('已上傳'); $('#tplDownload').show(); } else { $('#tplStatus').text('未上傳'); $('#tplDownload').hide(); }
      $('#settingsModal').modal('show');
    });
  });
  $('#tplDownload').on('click', function(e){ e.preventDefault(); window.location=API+'?action=download_template'; });
  $('#settingsSave').on('click', function(){
    $.post(API+'?action=save_settings', {nas_dir:$('#set_nas_dir').val(),owner_user_id:$('#set_owner').val(),deputy_user_id:$('#set_deputy').val()}, r=>{
      if(r.status==='success'){ alert('已儲存'); $('#settingsModal').modal('hide'); loadDocs(); } else alert(r.message);
    },'json');
  });
  $('#tplUpload').on('click', function(){
    const f=$('#tplFile')[0].files[0]; if(!f){ alert('請選擇檔案'); return; }
    const fd=new FormData(); fd.append('file',f);
    $.ajax({url:API+'?action=upload_template',type:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
     .done(r=>{ if(r.status==='success'){ $('#tplStatus').text('已上傳'); $('#tplDownload').show(); alert('範本已上傳'); } else alert(r.message); });
  });

  $('#rbacHelp').on('click', function(e){ e.preventDefault(); alert('權限代碼：A=全部, C=新增, R=檢閱, U=修改, D=刪除。\n此頁權限於「使用者權限設定」中依角色指派。\n文件的「開啟權限」另於各文件的「權限」按鈕設定部門/職稱可讀取/下載/更新/刪除。'); });

  // init
  loadMeta(loadDocs);
});
</script>
</body>
</html>
