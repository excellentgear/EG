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

// ── as_doc 模組角色（職稱為主自動套用、個人指派優先覆蓋）與頁面 ACRUD 合併 ──
include_once '../../src/common/role_features_helper.php';
$asFeatures    = rf_load_user_features_override($conn, $id, 'as_doc');
$asIsRoleAdmin = in_array('all', $asFeatures, true);
$pp = $deptPerm ?: '';
$asCaps = [
    'view'     => $asIsRoleAdmin || strpos($pp,'A')!==false || strpos($pp,'R')!==false || in_array('asdoc_view', $asFeatures, true),
    'create'   => $asIsRoleAdmin || strpos($pp,'A')!==false || strpos($pp,'C')!==false || in_array('asdoc_create', $asFeatures, true),
    'update'   => $asIsRoleAdmin || strpos($pp,'A')!==false || strpos($pp,'U')!==false || in_array('asdoc_update', $asFeatures, true),
    'delete'   => $asIsRoleAdmin || strpos($pp,'A')!==false || strpos($pp,'D')!==false || in_array('asdoc_delete', $asFeatures, true),
    'settings' => $asIsRoleAdmin || strpos($pp,'A')!==false || in_array('asdoc_settings', $asFeatures, true),
    'edit_online' => $asIsRoleAdmin || strpos($pp,'A')!==false || in_array('asdoc_edit_online', $asFeatures, true),
    'download' => $asIsRoleAdmin || strpos($pp,'A')!==false || in_array('asdoc_download', $asFeatures, true),
    // 免附件補登：只認明確功能碼，管理員不自動豁免（維持改版必附申請單的管控）
    'no_attach' => in_array('asdoc_no_attach', $asFeatures, true),
];

if (!$asCaps['view']) {
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
        .tag-chip{display:inline-block;padding:0 8px;border-radius:9px;color:#fff;font-size:11px;line-height:17px;margin:1px 2px;white-space:nowrap;}
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
            <small>(權限：<?php
                $capLabels = ['view'=>'檢閱','create'=>'新增','update'=>'修改','delete'=>'刪除','settings'=>'設定'];
                $capShow = [];
                foreach ($capLabels as $k=>$v) { if ($asCaps[$k]) $capShow[] = $v; }
                echo htmlspecialchars(implode('/', $capShow));
            ?>)</small>
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
                  <?php if ($asCaps['create']): ?>
                  <button class="btn btn-primary btn-sm" id="btnAddDoc"><i class="fa fa-plus"></i> 新增文件</button>
                  <button class="btn btn-info btn-sm" id="btnBatchAdd"><i class="fa fa-files-o"></i> 批次上傳</button>
                  <?php endif; ?>
                  <?php if ($asCaps['settings']): ?>
                  <button class="btn btn-default btn-sm" id="btnTags"><i class="fa fa-tags"></i> 標籤 / 分類管理</button>
                  <button class="btn btn-warning btn-sm" id="btnSettings"><i class="fa fa-cog"></i> 系統設定（負責人 / 路徑）</button>
                  <button class="btn btn-danger btn-sm" id="btnRoles"><i class="fa fa-users"></i> 角色設定</button>
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
                    <option value="三階">三階（指導書/圖面/規範）</option>
                    <option value="四階">四階（表單/紀錄）</option>
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
                      <th>母文件 / 表單</th>
                      <th>目前版本</th><th>修訂日期</th><th>標籤</th><th style="min-width:245px;">操作</th>
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
            <div class="form-group col-md-6"><label>文件編號 *</label>
              <div class="input-group">
                <input type="text" class="form-control" name="doc_no" id="doc_no" required placeholder="可手動輸入，或選好階級/部門/母文件後按自動">
                <span class="input-group-btn"><button type="button" class="btn btn-default" id="btnAutoNo" title="依 階級+部門代碼（或母文件）自動產生下一個編號"><i class="fa fa-magic"></i> 自動</button></span>
              </div>
              <select class="form-control input-sm" id="doc_code_sel" style="display:none;margin-top:4px;" title="此部門有多組代碼，請選擇"></select>
            </div>
            <div class="form-group col-md-6"><label>文件名稱 *</label><input type="text" class="form-control" name="doc_name" id="doc_name" required></div>
          </div>
          <div class="row">
            <div class="form-group col-md-3"><label>文件類別</label>
              <select class="form-control" name="doc_type" id="doc_type">
                <option value="">--</option><option>手冊</option><option>程序</option><option>標準書</option><option>表單</option>
              </select>
            </div>
            <div class="form-group col-md-3"><label>文件階級</label>
              <select class="form-control" name="doc_level" id="doc_level">
                <option value="">--</option><option value="一階">一階（品質手冊）</option><option value="二階">二階（程序書）</option><option value="三階">三階（指導書/圖面/規範）</option><option value="四階">四階（表單/紀錄）</option>
              </select>
            </div>
            <div class="form-group col-md-3"><label>所屬部門</label>
              <select class="form-control" name="department_id" id="doc_department_id"><option value="">跨部門 / 未指定</option></select>
            </div>
            <div class="form-group col-md-3"><label>母文件（表單隸屬的程序書）</label>
              <select class="form-control" name="parent_doc_id" id="doc_parent_id"><option value="">— 無 —</option></select>
            </div>
          </div>
          <p class="text-muted" style="font-size:11px;margin-top:-8px;">表單/紀錄一律屬<strong>四階</strong>；編號首碼是「母文件」的階級（如 2-TD-01-01 為隸屬二階程序書 2-TD-01 的四階表單）。選了母文件，列表可從程序書一鍵展開其所有表單。</p>
          <div class="form-group">
            <label>標籤 / 分類</label>
            <div id="docTagPicker" style="border:1px solid #ddd;border-radius:4px;padding:8px;min-height:40px;"></div>
            <input type="hidden" name="tag_ids" id="doc_tag_ids">
          </div>

          <hr>
          <div id="firstVersionBlock">
            <h4 style="margin-top:0;" id="firstVersionTitle">首版資訊</h4>
            <div class="row">
              <div class="form-group col-md-3"><label>版本號 *</label><input type="text" class="form-control" name="version" id="doc_version" placeholder="如 1.0" required></div>
              <div class="form-group col-md-3"><label>文件狀況</label>
                <select class="form-control" name="change_status" id="doc_change_status">
                  <option>制訂</option><option>修正</option><option>廢止</option><option>增發</option><option>補發</option>
                </select>
              </div>
              <div class="form-group col-md-3"><label>修訂日期 *</label><input type="date" class="form-control" name="revised_date" id="doc_revised_date" max="9999-12-31" required></div>
              <div class="form-group col-md-3"><label>制修訂頁次</label><input type="text" class="form-control" name="revised_pages" id="doc_revised_pages" placeholder="如 全冊 / 1-2">
                <div class="phrase-bar" data-field="pages" data-t="#doc_revised_pages" style="margin-top:3px;"></div></div>
            </div>
            <div class="form-group"><label>制修訂摘要</label><textarea class="form-control" name="revised_summary" id="doc_revised_summary" rows="2"></textarea>
              <div class="phrase-bar" data-field="summary" data-t="#doc_revised_summary" style="margin-top:3px;"></div></div>
            <div class="row" id="firstVersionFiles">
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

<!-- ═════════ 表單填寫紀錄 Modal（品質紀錄：紙本上傳＋電子化模組結果） ═════════ -->
<div class="modal fade" id="recordModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" style="width:94%;max-width:1150px;" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">填寫紀錄 － <span id="rec_doc_name"></span></h4>
      </div>
      <div class="modal-body">
        <input type="hidden" id="rec_doc_id">
        <div id="recLinkedWrap" class="form-inline" style="margin-bottom:10px;display:none;">
          <label style="font-weight:normal;">電子化模組連結：</label>
          <select class="form-control input-sm" id="rec_linked_module">
            <option value="">— 無（純紙本紀錄）—</option>
            <option value="qa_abnormal">品質異常處理單</option>
            <option value="car">異常矯正處理單(CAR)</option>
          </select>
          <button class="btn btn-xs btn-info" id="recLinkedSave">儲存連結</button>
          <span class="text-muted" style="font-size:11px;margin-left:6px;">連結後此表單的電子化開單結果會顯示在下方</span>
        </div>

        <div id="recElectronicBlock" style="display:none;">
          <h4 style="margin-top:0;"><i class="fa fa-bolt" style="color:#f39c12;"></i> 電子化紀錄 <small id="recElecInfo"></small>
            <a href="#" id="recElecLink" target="_blank" class="btn btn-xs btn-primary" style="margin-left:8px;">前往模組頁</a></h4>
          <div class="table-responsive" style="max-height:220px;overflow-y:auto;">
            <table class="table table-condensed table-striped" style="font-size:12px;">
              <thead><tr><th style="width:150px;">單號</th><th style="width:100px;">日期</th><th>內容摘要</th></tr></thead>
              <tbody id="recElecBody"></tbody>
            </table>
          </div>
          <hr>
        </div>

        <h4><i class="fa fa-file-text-o" style="color:#3498db;"></i> 紙本／檔案紀錄 <small id="recPaperInfo"></small></h4>
        <div class="table-responsive">
          <table class="table table-condensed table-striped" style="font-size:12px;">
            <thead><tr><th>標題</th><th style="width:100px;">紀錄日期</th><th>備註</th><th style="width:90px;">上傳者</th><th style="width:150px;">操作</th></tr></thead>
            <tbody id="recPaperBody"></tbody>
          </table>
        </div>
        <div class="text-right"><ul class="pagination pagination-sm" id="recPager" style="margin:0 0 10px;"></ul></div>

        <div id="recUploadBlock" style="border-top:1px solid #eee;padding-top:10px;">
          <h5><i class="fa fa-upload"></i> 批次上傳紀錄（Excel / Word / PDF，可多選）</h5>
          <div class="form-inline" style="margin-bottom:6px;">
            <input type="file" id="rec_files" multiple>
            <label style="font-weight:normal;margin-left:10px;">共同紀錄日期：</label>
            <input type="date" class="form-control input-sm" id="rec_common_date" max="9999-12-31">
          </div>
          <table class="table table-condensed" style="font-size:12px;">
            <tbody id="recUploadRows"></tbody>
          </table>
          <button class="btn btn-sm btn-primary" id="recUploadSubmit" style="display:none;"><i class="fa fa-upload"></i> 開始上傳</button>
          <div id="recUploadResult"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div>
  </div>
</div>

<!-- ═════════ 角色設定 Modal（roles module='as_doc'，寫入需管理員） ═════════ -->
<div class="modal fade" id="rolesModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" style="width:92%;max-width:1100px;" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">AS 文件管理 — 角色設定</h4>
      </div>
      <div class="modal-body">
        <p class="text-muted" style="font-size:12px;">
          權限規則：<strong>職稱為主自動套用</strong>（職稱指派請至「權限設定」頁的 AS 職稱權限區），
          <strong>個人另有指派時以個人為準（覆蓋職稱）</strong>；管理員恆有全部權限。此處管理「角色定義」與「個人指派」。
        </p>
        <h4><i class="fa fa-id-badge"></i> 角色與功能</h4>
        <table class="table table-bordered table-condensed" style="font-size:13px;">
          <thead><tr><th style="width:160px;">角色</th><th>功能</th><th style="width:120px;">操作</th></tr></thead>
          <tbody id="roleDefBody"></tbody>
        </table>
        <div class="input-group input-group-sm" style="width:300px;margin-bottom:15px;">
          <input type="text" class="form-control" id="newRoleName" placeholder="新角色名稱">
          <span class="input-group-btn"><button class="btn btn-success" id="btnAddRole"><i class="fa fa-plus"></i> 新增角色</button></span>
        </div>
        <p class="text-muted" style="font-size:12px;">
          <i class="fa fa-info-circle"></i> 角色「指派」（職稱與個人）請至
          <a href="../user/user_permissions.php" target="_blank">權限設定頁</a> 的「AS9100 文件管理」區塊操作。
        </p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div>
  </div>
</div>

<!-- ═════════ 批次上傳 Modal ═════════ -->
<div class="modal fade" id="batchModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" style="width:96%;max-width:1200px;" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">批次上傳文件</h4>
      </div>
      <div class="modal-body">
        <p class="text-muted" style="font-size:12px;">選好共同設定與多個檔案後，每列可個別修改編號/名稱/摘要，並可逐檔附上申請單。編號未填時依「母文件或 階級+部門代碼」自動遞增。</p>
        <div class="row">
          <div class="form-group col-md-3"><label>母文件（共同）</label><select class="form-control" id="batch_parent"><option value="">— 無 —</option></select></div>
          <div class="form-group col-md-2"><label>文件類別</label>
            <select class="form-control" id="batch_type"><option value="">--</option><option>手冊</option><option>程序</option><option>標準書</option><option selected>表單</option></select>
          </div>
          <div class="form-group col-md-2"><label>文件階級</label>
            <select class="form-control" id="batch_level"><option value="">--</option><option value="一階">一階</option><option value="二階">二階</option><option value="三階">三階</option><option value="四階" selected>四階</option></select>
          </div>
          <div class="form-group col-md-2"><label>所屬部門</label><select class="form-control" id="batch_dept"><option value="">跨部門</option></select></div>
          <div class="form-group col-md-1"><label>版本(預設)</label><input type="text" class="form-control" id="batch_version" value="" placeholder="表單可不填"></div>
          <div class="form-group col-md-2"><label>修訂日期(預設)</label><input type="date" class="form-control" id="batch_date" max="9999-12-31"></div>
        </div>
        <div class="form-group" id="batchCodeWrap" style="display:none;max-width:420px;">
          <label>此部門有多組代碼，請選擇</label>
          <select class="form-control input-sm" id="batch_code_sel"></select>
        </div>
        <div class="form-group">
          <label>選擇多個文件檔</label>
          <input type="file" id="batch_files" multiple>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-condensed" style="font-size:12px;">
            <thead><tr>
              <th style="width:14%;">檔名</th><th style="width:13%;">文件編號</th><th style="width:17%;">文件名稱</th>
              <th style="width:7%;">版本</th><th style="width:11%;">修訂日期</th>
              <th style="width:16%;">制修訂摘要</th><th>申請單（逐檔對應，可不附）</th>
            </tr></thead>
            <tbody id="batchRows"></tbody>
          </table>
        </div>
        <div id="batchResult"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        <button type="button" class="btn btn-primary" id="batchSubmit"><i class="fa fa-upload"></i> 開始上傳</button>
      </div>
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
          <p style="margin-bottom:10px;"><strong>目前版本：</strong><span class="label label-info" id="ver_cur_ver" style="font-size:13px;"></span>
             <span class="text-muted" style="margin-left:8px;">修訂日期：<span id="ver_cur_date"></span></span></p>
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
            <div class="form-group col-md-3"><label>修訂日期 *</label><input type="date" class="form-control" name="revised_date" id="ver_revised_date" max="9999-12-31" required></div>
            <div class="form-group col-md-3"><label>制修訂頁次</label><input type="text" class="form-control" name="revised_pages" id="ver_revised_pages">
              <div class="phrase-bar" data-field="pages" data-t="#ver_revised_pages" style="margin-top:3px;"></div></div>
          </div>
          <div class="form-group"><label>制修訂摘要</label><textarea class="form-control" name="revised_summary" id="ver_revised_summary" rows="2"></textarea>
            <div class="phrase-bar" data-field="summary" data-t="#ver_revised_summary" style="margin-top:3px;"></div></div>
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
          <span class="text-muted" style="font-size:12px;">可含中文/空格。僅存根路徑，實際檔案路徑於讀取時現場組出（不寫死於資料庫）。</span>
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
          <label>部門文件代碼（自動編號用；同部門可多組，如 資材課=PD 廠內／PH 委外）</label>
          <div style="max-height:240px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:6px;">
            <table class="table table-condensed" style="margin-bottom:0;">
              <thead><tr><th style="width:38%;">部門</th><th style="width:22%;">代碼</th><th>用途註記（選填）</th><th style="width:36px;"></th></tr></thead>
              <tbody id="deptCodeList"></tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-success" id="deptCodeAddRow" style="margin-top:6px;"><i class="fa fa-plus"></i> 加一組</button>
          <button type="button" class="btn btn-sm btn-info" id="deptCodeSave" style="margin-top:6px;">儲存部門代碼</button>
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
window.asPerm = <?php echo json_encode($asCaps); ?>;
$(function(){
  const API = '../../src/store/AS_Document_API.php';
  const canC = !!window.asPerm.create;
  const canU = !!window.asPerm.update;
  const canD = !!window.asPerm.delete;
  const canS = !!window.asPerm.settings;
  const canEO = !!window.asPerm.edit_online;
  const canDL = !!window.asPerm.download;
  const canNA = !!window.asPerm.no_attach; // 免附件補登

  // 操作欄 ⚙ 下拉在 .table-responsive 內會被裁切：展開時暫時放開 overflow
  $(document).on('show.bs.dropdown', '#docTableBody .btn-group', function(){
    $(this).closest('.table-responsive').css('overflow','visible');
  });
  $(document).on('hide.bs.dropdown', '#docTableBody .btn-group', function(){
    $(this).closest('.table-responsive').css('overflow','');
  });

  // 線上開檔：仿 BOM 總表模式——建立工作副本後以 ms-office 協定 + HTTP URL 直接開啟 Excel/Word
  $(document).on('click','.op-online', function(e){
    e.preventDefault();
    const $b=$(this);
    $.post(API+'?action=open_online',{version_id:$b.data('ver')}, r=>{
      if(r.status!=='success'){ alert(r.message||'開啟失敗'); return; }
      window.location.href = r.uri; // ms-excel:ofe|u|http://...（同 BOM 總表）
    },'json');
  });
  let META = {departments:[],positions:[],tags:[],users:[]};
  let DOCS = [], FILTERED = [], activeTagId = 0, curPage = 1, activeParentId = 0, activeParentNo = '';

  function esc(t){ if(t===null||t===undefined) return ''; return String(t).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

  // 頂部浮動提示（自動淡出）
  function showToast(msg, ok=true){
    const $t = $('<div></div>').text(msg).css({
      position:'fixed', top:'20px', left:'50%', transform:'translateX(-50%)',
      padding:'10px 25px', borderRadius:'5px', color:'#fff', zIndex:99999,
      background: ok ? '#26B99A' : '#E74C3C', display:'none', fontSize:'14px'
    });
    $('body').append($t);
    $t.fadeIn(300).delay(2500).fadeOut(400, function(){ $(this).remove(); });
  }

  // ══ 輸入欄位通用互動（ai-rules/08 三規則）══
  // 1. Enter 逐欄前進、最後一欄送出（textarea 維持換行）
  $(document).on('keydown', 'input:not([type=file]):not([type=checkbox]):not([type=radio]), select', function(e){
    if(e.key !== 'Enter') return;
    e.preventDefault();
    if(this.id === 'searchKw'){ loadDocs(); return; } // 搜尋欄 Enter＝執行搜尋
    const $scope = $(this).closest('form, .modal-content, .x_content');
    const fields = $scope.find('input:visible:enabled:not([type=file]):not([type=checkbox]):not([type=radio]), select:visible:enabled, textarea:visible:enabled').toArray();
    const idx = fields.indexOf(this);
    if(idx >= 0 && idx < fields.length - 1){
      fields[idx+1].focus();
    } else {
      // 最後一欄：送出表單（存檔）
      const $form = $(this).closest('form');
      if($form.length){ $form.trigger('submit'); }
      else { $scope.find('button.btn-primary:visible').last().trigger('click'); }
    }
  });
  // 2. 雙擊清空（有值才動作；篩選欄雙擊已各自綁定連動重載）
  $(document).on('dblclick', 'input[type=text], input[type=date], input[type=number], textarea', function(){
    if($(this).val() !== ''){ $(this).val('').trigger('input').trigger('change'); }
  });
  // 3. 聚焦自動全選（方便直接覆寫）
  $(document).on('focus', 'input[type=text], input[type=number], input[type=date]', function(){
    const el = this;
    if($(el).val() !== '') setTimeout(()=>{ try{ el.select(); }catch(_e){} }, 0);
  });

  function loadMeta(cb){
    $.getJSON(API+'?action=meta', r=>{
      if(r.status!=='success'){ alert('載入基礎資料失敗'); return; }
      META = r;
      // 部門下拉（列表篩選只列「有文件」的部門；保留目前選取值）
      const withDocs = META.depts_with_docs||[];
      const fVal = $('#filterDept').val();
      const dOpts = '<option value="">全部部門</option>' + META.departments.filter(d=>withDocs.includes(parseInt(d.id))).map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join('');
      $('#filterDept').html(dOpts);
      if(fVal) $('#filterDept').val(fVal);
      $('#doc_department_id').html('<option value="">跨部門 / 未指定</option>' + META.departments.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join(''));
      // 使用者下拉（負責人/代理人）——重建時保留目前選取值，避免設定跳掉
      const uOpts = META.users.map(u=>`<option value="${u.id}">${esc(u.user_cname)}</option>`).join('');
      const ownerVal = $('#set_owner').val(), deputyVal = $('#set_deputy').val();
      $('#set_owner').html('<option value="">-- 未指定 --</option>'+uOpts);
      $('#set_deputy').html('<option value="">-- 無 --</option>'+uOpts);
      if(ownerVal) $('#set_owner').val(ownerVal);
      if(deputyVal) $('#set_deputy').val(deputyVal);
      renderTagFilter();
      renderPhraseBars();
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
      parent_id: activeParentId,
      include_deleted: $('#incDeleted').is(':checked') ? '1':'0'
    };
    $.getJSON(API+'?action=list_documents', p, r=>{
      if(r.status!=='success'){ alert(r.message||'讀取失敗'); return; }
      DOCS = r.data; curPage = 1; renderDocs(); renderParentIndicator();
    });
  }

  // 「檢視某文件底下表單」狀態指示
  function renderParentIndicator(){
    $('#parentFilterBar').remove();
    if(activeParentId>0){
      $('<div id="parentFilterBar" class="alert alert-success" style="padding:5px 10px;margin-bottom:8px;">'
        + '<i class="fa fa-filter"></i> 檢視 <strong>'+activeParentNo+'</strong> 底下的表單　'
        + '<a href="#" id="clearParentFilter" style="text-decoration:underline;">回全部文件</a></div>')
        .insertBefore($('#docTableBody').closest('.table-responsive'));
    }
  }
  $(document).on('click','#clearParentFilter',function(e){ e.preventDefault(); activeParentId=0; activeParentNo=''; loadDocs(); });
  $('#docTableBody').on('click','.rel-children',function(e){
    e.preventDefault();
    // 展開母文件底下的表單＝檢視全部子表單：先清掉其他篩選（階級/部門/關鍵字/標籤），
    // 否則例如「二階」篩選會把四階子表單全部擋掉，變成點進去卻無資料
    $('#searchKw').val(''); $('#filterLevel').val(''); $('#filterDept').val('');
    activeTagId = 0; renderTagFilter();
    activeParentId = parseInt($(this).data('id'))||0;
    activeParentNo = $(this).data('no')||'';
    loadDocs();
  });
  $('#docTableBody').on('click','.rel-parent',function(e){
    e.preventDefault();
    // 跳去看母文件：同樣清掉會擋住母文件的篩選
    $('#filterLevel').val(''); $('#filterDept').val('');
    activeTagId = 0; renderTagFilter();
    activeParentId = 0; activeParentNo = '';
    $('#searchKw').val($(this).data('kw'));
    loadDocs();
  });

  function renderDocs(){
    const size = parseInt($('#pageSize').val())||10;
    const total = DOCS.length;
    const pages = Math.max(1, Math.ceil(total/size));
    if(curPage>pages) curPage=pages;
    const start = (curPage-1)*size;
    const rows = DOCS.slice(start, start+size);
    const tb = $('#docTableBody').empty();
    if(rows.length===0){ tb.append('<tr><td colspan="10" class="text-center text-muted">無資料</td></tr>'); }
    rows.forEach(d=>{
      const tags = (d.tags||[]).map(t=>`<span class="tag-chip" style="background:${esc(t.color)};">${esc(t.name)}</span>`).join(' ');
      let ops = '';
      const curVer = d.current_version_id;
      // 檔案類型（必須在 nameCell 之前宣告，nameCell 會用到）
      const fext = (d.current_file_name||'').split('.').pop().toLowerCase();
      const isOffice = ['xls','xlsx','doc','docx','ppt','pptx'].includes(fext);
      // 母文件 / 子表單欄
      let rel = '';
      if(d.parent_doc_id) rel += `<a href="#" class="rel-parent" data-kw="${esc(d.parent_doc_no)}" title="${esc(d.parent_doc_name)}"><i class="fa fa-level-up"></i> ${esc(d.parent_doc_no)}</a>`;
      if(parseInt(d.children_count)>0) rel += ` <a href="#" class="rel-children label label-success" data-id="${d.id}" data-no="${esc(d.doc_no)}" title="展開此文件底下的表單">表單 ×${d.children_count}</a>`;
      // 文件名稱點擊：有線上開檔權限且為 Office 檔 → 下載工作副本進 Excel/Word 直接打字；
      // 否則 → PDF 線上預覽（後端 download 均另驗權限）
      let nameCell = esc(d.doc_name);
      if(!d.current_file_name && curVer) nameCell += ' <span class="label label-default" title="補登資料，尚未上傳文件檔">無檔</span>';
      if(curVer && d.current_file_name){
        if(canEO && isOffice){
          nameCell = `<a href="#" class="op-online" data-ver="${curVer}" title="下載工作副本，開啟後按「啟用編輯」即可打字/列印（不動正式版本檔）">${esc(d.doc_name)} <i class="fa fa-pencil text-muted" style="font-size:11px;"></i></a>`;
        } else {
          nameCell = `<a href="${API}?action=download&which=file&version_id=${curVer}&inline=1" target="_blank" title="線上預覽最新版（Office 檔自動轉 PDF，第一次需數秒轉檔）">${esc(d.doc_name)}</a>`;
        }
      }
      // 操作欄：固定欄位（每列同寬對齊）＋常用圖示鈕＋管理動作收進 ⚙ 下拉
      const hasFile = !!d.current_file_name;
      const slot = (html, w)=>`<span style="display:inline-block;min-width:${w}px;text-align:center;">${html||''}</span>`;
      const sPrev = (curVer && hasFile)
        ? `<a class="btn btn-xs btn-default" href="${API}?action=download&which=file&version_id=${curVer}&inline=1" target="_blank" title="線上預覽（PDF）"><i class="fa fa-eye"></i></a>` : '';
      const sDl = (curVer && hasFile && canDL)
        ? `<a class="btn btn-xs btn-info" href="${API}?action=download&which=file&version_id=${curVer}" title="下載原檔"><i class="fa fa-download"></i></a>` : '';
      let sRec = '';
      if(d.doc_type==='表單' || parseInt(d.record_count)>0 || d.linked_module){
        const rc = parseInt(d.record_count)||0;
        sRec = `<button class="btn btn-xs ${rc>0||d.linked_module?'btn-warning':'btn-default'} op-record" data-id="${d.id}" data-name="${esc(d.doc_name)}" title="填寫後的表單紀錄（紙本上傳/電子化結果）">紀錄${rc>0?'×'+rc:''}</button>`;
      }
      const sHist = `<button class="btn btn-xs btn-default op-hist" data-id="${d.id}" data-name="${esc(d.doc_name)}" title="歷史版本"><i class="fa fa-history"></i></button>`;
      const sVer = canU ? `<button class="btn btn-xs btn-warning op-ver" data-id="${d.id}" data-name="${esc(d.doc_name)}" title="上傳新版本（附制修申請單）">改版</button>` : '';
      let mgmt = '';
      if(canU) mgmt += `<li><a href="javascript:void(0)" class="op-edit" data-id="${d.id}"><i class="fa fa-pencil-square-o"></i> 編輯資料 / 修正版本資訊</a></li>`;
      if(canS) mgmt += `<li><a href="javascript:void(0)" class="op-perm" data-id="${d.id}" data-name="${esc(d.doc_name)}"><i class="fa fa-lock"></i> 文件開啟權限</a></li>`;
      if(canD){
        if(mgmt) mgmt += '<li class="divider"></li>';
        mgmt += d.is_deleted==1
          ? `<li><a href="javascript:void(0)" class="op-restore" data-id="${d.id}"><i class="fa fa-undo"></i> 還原文件</a></li>`
          : `<li><a href="javascript:void(0)" class="op-del" data-id="${d.id}" style="color:#d9534f;"><i class="fa fa-trash"></i> 刪除文件</a></li>`;
      }
      const sGear = mgmt
        ? `<div class="btn-group"><button class="btn btn-xs btn-default dropdown-toggle" data-toggle="dropdown" title="管理（編輯/權限/刪除）"><i class="fa fa-cog"></i> <span class="caret"></span></button><ul class="dropdown-menu dropdown-menu-right">${mgmt}</ul></div>` : '';
      ops = slot(sPrev,32)+slot(sDl,32)+slot(sHist,32)+slot(sVer,46)+slot(sRec,60)+slot(sGear,44);
      const delMark = d.is_deleted==1 ? ' <span class="label label-default">已刪除</span>' : '';
      tb.append(`<tr>
        <td>${esc(d.doc_no)}${delMark}</td>
        <td>${nameCell}</td>
        <td>${esc(d.doc_type)||'-'}</td>
        <td>${esc(d.doc_level)||'-'}</td>
        <td>${esc(d.dept_name)||'<span class="text-muted">跨部門</span>'}</td>
        <td class="text-nowrap">${rel||'-'}</td>
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
  $('#btnClearFilter').on('click', ()=>{ $('#searchKw').val(''); $('#filterLevel').val(''); $('#filterDept').val(''); activeTagId=0; activeParentId=0; activeParentNo=''; renderTagFilter(); loadDocs(); });

  // 母文件下拉（excludeId=編輯中的自己不可選）
  function fillParentSelect(excludeId, selected){
    const opts = ['<option value="">— 無 —</option>'].concat(
      (META.parents||[]).filter(p=>p.id!=excludeId).map(p=>`<option value="${p.id}" ${selected==p.id?'selected':''}>${esc(p.doc_no)}｜${esc(p.doc_name)}</option>`));
    $('#doc_parent_id').html(opts.join(''));
  }
  $('#tagFilterBar').on('click','.tag-filter', function(){ activeTagId=parseInt($(this).data('id'))||0; renderTagFilter(); loadDocs(); });
  // 雙擊清空搜尋欄
  $('#searchKw').on('dblclick', function(){ $(this).val(''); loadDocs(); });

  // 文件類別 → 自動設定階級並鎖定（一階手冊/二階程序書/三階標準書·指導書/四階表單）；
  // 未選類別時階級開放手動選（歷史資料相容）。disabled 欄位送出時由 JS 手動補值。
  const TYPE_LEVEL_MAP = {'手冊':'一階','程序':'二階','標準書':'三階','表單':'四階'};
  function syncLevelFromType($type, $level){
    const lv = TYPE_LEVEL_MAP[$type.val()];
    if(lv){ $level.val(lv).prop('disabled', true).attr('title','階級由文件類別自動決定'); }
    else { $level.prop('disabled', false).attr('title',''); }
  }
  $('#doc_type').on('change', function(){
    syncLevelFromType($('#doc_type'), $('#doc_level'));
    syncParentByType($('#doc_type'), $('#doc_parent_id'), $('#doc_department_id'));
    // 表單首建可無版本號（改版才給號）；其他類別維持必填
    if($('#doc_id').val()==='') $('#doc_version').prop('required', $(this).val()!=='表單');
    // 新增模式且編號空白時，類別選定（＝階級確定）就自動帶編號
    if($('#doc_id').val()==='' && $('#doc_no').val().trim()===''){
      const hasParent = $('#doc_parent_id').val()!=='';
      if(hasParent || ($('#doc_level').val()!=='' && $('#doc_department_id').val()!=='')) suggestDocNo(true);
    }
  });

  // 自動編號：有母文件→{母編號}-{次號}；無→{階}-{部門代碼}-{次號}（可再手動修改）
  // 一部門多組代碼（如 資材課 PD廠內/PH委外）→ 顯示代碼選擇器
  function suggestDocNo(fill){
    const p = { level: $('#doc_level').val(), department_id: $('#doc_department_id').val(), parent_doc_id: $('#doc_parent_id').val() };
    const selCode = $('#doc_code_sel').is(':visible') ? $('#doc_code_sel').val() : '';
    if(selCode) p.code = selCode;
    $.getJSON(API+'?action=suggest_doc_no', p, r=>{
      if(r.status==='success'){
        if(!selCode) $('#doc_code_sel').hide().empty();
        if(fill) $('#doc_no').val(r.doc_no);
      } else if(r.status==='choose'){
        const sel=$('#doc_code_sel').empty().show();
        (r.options||[]).forEach(o=>sel.append(`<option value="${esc(o.code)}" data-no="${esc(o.doc_no)}">${esc(o.code)}${o.label?'（'+esc(o.label)+'）':''} → ${esc(o.doc_no)}</option>`));
        if(fill) $('#doc_no').val(sel.find('option:first').data('no')||'');
      } else if(fill) alert(r.message||'無法產生編號');
    });
  }
  $('#btnAutoNo').on('click', ()=>suggestDocNo(true));
  $('#doc_code_sel').on('change', function(){ $('#doc_no').val($(this).find('option:selected').data('no')||''); });

  // ══ 制修訂頁次/摘要 常用文字（存 DB） ══
  function renderPhraseBars(){
    $('.phrase-bar').each(function(){
      const f=$(this).data('field'), t=$(this).data('t');
      const list=(META.phrases||[]).filter(p=>p.field===f);
      let html = list.map(p=>
        `<span class="label label-default phrase-chip" data-t="${t}" data-text="${esc(p.phrase)}" title="點選帶入欄位" style="cursor:pointer;margin:0 4px 2px 0;display:inline-block;font-weight:normal;font-size:11px;line-height:16px;">${esc(p.phrase.length>24?p.phrase.substring(0,24)+'…':p.phrase)}${canU?` <a href="javascript:void(0)" class="phrase-del" data-id="${p.id}" title="刪除此常用文字" style="color:#fff;opacity:.7;">×</a>`:''}</span>`
      ).join('');
      if(canU) html += `<a href="javascript:void(0)" class="phrase-add" data-field="${f}" data-t="${t}" style="font-size:11px;white-space:nowrap;" title="把目前欄位的內容存成常用文字"><i class="fa fa-plus-circle"></i> 存常用</a>`;
      $(this).html(html);
    });
  }
  $(document).on('click','.phrase-chip', function(e){
    if($(e.target).hasClass('phrase-del')) return;
    $($(this).data('t')).val($(this).data('text')).trigger('change');
  });
  $(document).on('click','.phrase-del', function(e){
    e.stopPropagation();
    if(!confirm('刪除此常用文字？')) return;
    $.post(API+'?action=phrase_delete',{id:$(this).data('id')}, r=>{
      if(r.status==='success'){ loadMeta(renderPhraseBars); } else alert(r.message);
    },'json');
  });
  $(document).on('click','.phrase-add', function(){
    const v=$($(this).data('t')).val().trim();
    if(!v){ alert('欄位目前是空的，先輸入內容再按「存常用」'); return; }
    $.post(API+'?action=phrase_add',{field:$(this).data('field'), phrase:v}, r=>{
      if(r.status==='success'){ showToast('已存為常用文字'); loadMeta(renderPhraseBars); } else alert(r.message);
    },'json');
  });

  // 類別=手冊/程序書 → 頂層文件，母文件清空並鎖定
  function syncParentByType($type, $parent, $dept){
    if(['手冊','程序'].includes($type.val())){
      $parent.val('').prop('disabled', true).attr('title','手冊/程序書為頂層文件，無母文件');
    } else {
      $parent.prop('disabled', false).attr('title','');
    }
    syncDeptFromParent($parent, $dept); // 母文件被清掉時同步解鎖部門
  }

  // 選母文件 → 自動帶入母文件的所屬部門並鎖定（未選母文件時開放）
  function syncDeptFromParent($parent, $dept){
    const p = (META.parents||[]).find(x=>x.id==$parent.val());
    if(p && p.department_id){ $dept.val(p.department_id).prop('disabled', true).attr('title','部門由母文件自動決定'); }
    else { $dept.prop('disabled', false).attr('title',''); }
  }
  $('#doc_parent_id').on('change', function(){
    syncDeptFromParent($('#doc_parent_id'), $('#doc_department_id'));
  });
  // 手動輸入文件編號 → 依「階數-部門代碼」自動判定階級與所屬部門（如 2-TD-01-01 → 二階/技術部）
  $('#doc_no').on('blur', function(){
    const m = $(this).val().trim().match(/^([1-4])-([A-Za-z]+)-/);
    if(!m) return;
    const levelMap = {'1':'一階','2':'二階','3':'三階','4':'四階'};
    // 階級只在空白時帶入（表單編號首碼=母文件階級，表單本身應為四階，不硬蓋）
    if($('#doc_level').val()==='') $('#doc_level').val(levelMap[m[1]]||'');
    const dc = (META.dept_codes||[]).find(c=>c.code.toUpperCase()===m[2].toUpperCase());
    if(dc) $('#doc_department_id').val(dc.department_id);
  });
  // 新增模式下，選擇變動且編號仍空白時自動帶入
  $('#doc_level,#doc_department_id,#doc_parent_id').on('change', function(){
    if($('#doc_id').val()==='' && $('#doc_no').val().trim()===''){
      const hasParent = $('#doc_parent_id').val()!=='';
      if(hasParent || ($('#doc_level').val()!=='' && $('#doc_department_id').val()!=='')) suggestDocNo(true);
    }
  });

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
    $('#firstVersionTitle').text('首版資訊' + (canNA ? '（你有補登免附件權限，文件檔可不附）' : ''));
    $('#firstVersionFiles').show();
    $('#doc_file').prop('required', !canNA); $('#doc_version').prop('required',true);
    renderDocTagPicker([]);
    fillParentSelect(0, '');
    $('#doc_code_sel').hide().empty();
    syncLevelFromType($('#doc_type'), $('#doc_level'));
    syncParentByType($('#doc_type'), $('#doc_parent_id'), $('#doc_department_id'));
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
      syncLevelFromType($('#doc_type'), $('#doc_level'));
      // 編輯模式：顯示「目前版本資訊」修正區（可改誤植的版本號等，不換檔、不產生新版本）
      const cv = (d.versions||[]).find(v=>v.id==d.current_version_id) || {};
      $('#firstVersionBlock').show();
      $('#firstVersionTitle').text('目前版本資訊（修正誤植用；換檔請用「改版」）');
      $('#firstVersionFiles').hide();
      $('#doc_file').prop('required',false); $('#doc_version').prop('required',false);
      $('#doc_version').val(cv.version||'');
      $('#doc_change_status').val(cv.change_status||'制訂');
      $('#doc_revised_date').val(cv.revised_date||'');
      $('#doc_revised_pages').val(cv.revised_pages||'');
      $('#doc_revised_summary').val(cv.revised_summary||'');
      renderDocTagPicker((d.tags||[]).map(t=>t.id));
      fillParentSelect(d.id, d.parent_doc_id||'');
      syncParentByType($('#doc_type'), $('#doc_parent_id'), $('#doc_department_id'));
      $('#doc_code_sel').hide().empty();
      $('#docModal').modal('show');
    });
  });

  $('#docForm').on('submit', function(e){
    e.preventDefault();
    const isEdit = $('#doc_id').val()!=='';
    const url = API + '?action=' + (isEdit?'update_document_meta':'create_document');
    const fd = new FormData(this);
    // 被鎖定(disabled)的欄位不會進 FormData，手動補值
    fd.set('doc_level', $('#doc_level').val()||'');
    fd.set('department_id', $('#doc_department_id').val()||'');
    fd.set('parent_doc_id', $('#doc_parent_id').val()||'');
    NProgress.start();
    $.ajax({url:url, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
     .done(r=>{ if(r.status==='success'){ $('#docModal').modal('hide'); loadMeta(loadDocs); } else alert(r.message||'失敗'); })
     .fail(()=>alert('請求失敗')).always(()=>NProgress.done());
  });

  // ── 改版 ──
  $('#docTableBody').on('click','.op-ver', function(){
    $('#versionForm')[0].reset();
    const vid = $(this).data('id');
    $('#ver_doc_id').val(vid);
    $('#ver_doc_name').text($(this).data('name'));
    // 顯示目前版本號與修訂日期（取自列表資料）
    const drow = DOCS.find(x=>x.id==vid) || {};
    $('#ver_cur_ver').text(drow.current_version||'-');
    $('#ver_cur_date').text(drow.revised_date||'-');
    // 免附件補登權限：新版文件檔與申請單皆可不附（後端同樣豁免）
    $('#ver_file').prop('required', !canNA);
    $('#ver_apply_form').prop('required', !canNA);
    $('#naHint').remove();
    if(canNA) $('#versionModal .apply-alert').after('<div id="naHint" class="alert alert-info" style="padding:6px 10px;">你有「補登免附件」權限：補舊資料時，新版文件檔與申請單皆可暫不上傳。</div>');
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

  // 操作欄 ⚙ 下拉在 .table-responsive 內會被裁切：展開時暫時放開 overflow
  $(document).on('show.bs.dropdown', '#docTableBody .btn-group', function(){
    $(this).closest('.table-responsive').css('overflow','visible');
  });
  $(document).on('hide.bs.dropdown', '#docTableBody .btn-group', function(){
    $(this).closest('.table-responsive').css('overflow','');
  });

  // ── 歷史版本 ──
  $('#docTableBody').on('click','.op-hist', function(){
    const id=$(this).data('id'); $('#his_doc_name').text($(this).data('name'));
    $.getJSON(API+'?action=get_document',{id:id}, r=>{
      if(r.status!=='success'){ alert(r.message); return; }
      const tb=$('#historyBody').empty();
      (r.data.versions||[]).forEach(v=>{
        let dl = '<span class="text-muted">無檔（補登）</span>';
        if(v.file_name){
          dl = `<a class="btn btn-xs btn-default" href="${API}?action=download&which=file&version_id=${v.id}&inline=1" target="_blank">預覽</a> `;
          if(canDL) dl += `<a class="btn btn-xs btn-info" href="${API}?action=download&which=file&version_id=${v.id}">下載</a>`;
        }
        let af = '<span class="text-muted">無</span>';
        if(v.apply_form_file_name){
          af = `<a class="btn btn-xs btn-default" href="${API}?action=download&which=apply&version_id=${v.id}&inline=1" target="_blank">預覽</a> `;
          if(canDL) af += `<a class="btn btn-xs btn-default" href="${API}?action=download&which=apply&version_id=${v.id}">下載</a>`;
        }
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

  // ── 批次上傳 ──
  $('#btnBatchAdd').on('click', function(){
    $('#batch_parent').html('<option value="">— 無 —</option>'+(META.parents||[]).map(p=>`<option value="${p.id}">${esc(p.doc_no)}｜${esc(p.doc_name)}</option>`).join(''));
    $('#batch_dept').html('<option value="">跨部門</option>'+META.departments.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join(''));
    $('#batch_files').val(''); $('#batchRows').empty(); $('#batchResult').empty(); $('#batchCodeWrap').hide();
    syncLevelFromType($('#batch_type'), $('#batch_level'));
    syncParentByType($('#batch_type'), $('#batch_parent'), $('#batch_dept'));
    $('#batchModal').modal('show');
  });
  $('#batch_type').on('change', function(){
    syncLevelFromType($('#batch_type'), $('#batch_level'));
    syncParentByType($('#batch_type'), $('#batch_parent'), $('#batch_dept'));
    $('#batchCodeWrap').hide();
    if($('#batch_files')[0].files.length) batchSuggest(); // 階級變了，編號建議重算
  });

  // 檔名自動拆解：「2-GM-02-01-專案計劃需求表」→ 編號 2-GM-02-01 + 名稱 專案計劃需求表。
  // 規則：首段=階數(1碼數字)、次段=部門代碼(英文)、後續連續數字段皆屬編號；
  // 第一個非純數字段起=文件名稱。拆不出來回 null（改用順序遞增建議號、名稱=整個檔名）。
  function parseDocFilename(nameNoExt){
    const segs = nameNoExt.split('-');
    if(segs.length < 3) return null;
    if(!/^\d$/.test(segs[0]) || !/^[A-Za-z]+$/.test(segs[1])) return null;
    let i = 2;
    while(i < segs.length && /^\d+$/.test(segs[i])) i++;
    if(i === 2 || i >= segs.length) return null; // 沒有流水號、或整串都是編號沒名稱
    return { doc_no: segs.slice(0,i).join('-'), doc_name: segs.slice(i).join('-') };
  }

  // 選檔後建立逐檔設定列：優先用檔名拆解；拆不出的才用自動遞增建議號。逐列可改 版本/日期。
  function batchGenRows(startNo){
    const files = $('#batch_files')[0].files; const tb = $('#batchRows').empty();
    let base='', num=0, pad=2;
    if(startNo){
      const m = startNo.match(/^(.*-)(\d+)$/);
      if(m){ base=m[1]; num=parseInt(m[2]); pad=m[2].length; }
    }
    const defVer = $('#batch_version').val().trim();
    const defDate = $('#batch_date').val() || '';
    let seq = 0; // 只有「拆不出編號」的檔案才消耗遞增序號
    for(let i=0;i<files.length;i++){
      const fn = files[i].name;
      const nameNoExt = fn.replace(/\.[^.]+$/,'');
      const parsed = parseDocFilename(nameNoExt);
      const sugNo = parsed ? parsed.doc_no : (base ? base+String(num+seq++).padStart(pad,'0') : '');
      const sugName = parsed ? parsed.doc_name : nameNoExt;
      tb.append(`<tr>
        <td style="vertical-align:middle;">${esc(fn)}${parsed?' <i class="fa fa-magic text-success" title="已由檔名自動拆解編號/名稱"></i>':''}</td>
        <td><input type="text" class="form-control input-sm b-no" value="${esc(sugNo)}"></td>
        <td><input type="text" class="form-control input-sm b-name" value="${esc(sugName)}"></td>
        <td><input type="text" class="form-control input-sm b-ver" value="${esc(defVer)}" placeholder="表單可空"></td>
        <td><input type="date" class="form-control input-sm b-date" value="${defDate}" max="9999-12-31"></td>
        <td><input type="text" class="form-control input-sm b-sum" placeholder="如：新訂"></td>
        <td><input type="file" class="b-apply"></td>
      </tr>`);
    }
  }
  // 共同預設值變更 → 套用到所有列
  $('#batch_version').on('change', function(){ $('.b-ver').val($(this).val().trim()); });
  $('#batch_date').on('change', function(){ $('.b-date').val($(this).val()); });
  function batchSuggest(){
    const files = $('#batch_files')[0].files;
    if(!files.length){ $('#batchRows').empty(); return; }
    const p = { level: $('#batch_level').val(), department_id: $('#batch_dept').val(), parent_doc_id: $('#batch_parent').val() };
    const selCode = $('#batchCodeWrap').is(':visible') ? $('#batch_code_sel').val() : '';
    if(selCode) p.code = selCode;
    $.getJSON(API+'?action=suggest_doc_no', p, r=>{
      if(r.status==='success'){
        if(!selCode) $('#batchCodeWrap').hide();
        batchGenRows(r.doc_no);
      } else if(r.status==='choose'){
        const sel=$('#batch_code_sel').empty();
        (r.options||[]).forEach(o=>sel.append(`<option value="${esc(o.code)}" data-no="${esc(o.doc_no)}">${esc(o.code)}${o.label?'（'+esc(o.label)+'）':''} → ${esc(o.doc_no)} 起</option>`));
        $('#batchCodeWrap').show();
        batchGenRows(sel.find('option:first').data('no')||'');
      } else {
        batchGenRows('');
      }
    });
  }
  $('#batch_files').on('change', batchSuggest);
  $('#batch_code_sel').on('change', function(){ batchGenRows($(this).find('option:selected').data('no')||''); });
  // 批次：選母文件 → 自動帶入母文件的所屬部門並鎖定（未選時開放）
  $('#batch_parent').on('change', function(){
    syncDeptFromParent($('#batch_parent'), $('#batch_dept'));
  });
  // 共同設定變動時若已選檔，重新產生編號建議
  $('#batch_parent,#batch_level,#batch_dept').on('change', ()=>{ $('#batchCodeWrap').hide(); if($('#batch_files')[0].files.length) batchSuggest(); });

  $('#batchSubmit').on('click', function(){
    const files = $('#batch_files')[0].files;
    if(!files.length){ alert('請先選擇檔案'); return; }
    const isForm = $('#batch_type').val()==='表單';
    const rows=[]; let bad=0, badVer=0, badDate=0;
    $('#batchRows tr').each(function(i){
      const no=$(this).find('.b-no').val().trim(), nm=$(this).find('.b-name').val().trim();
      const ver=$(this).find('.b-ver').val().trim(), dt=$(this).find('.b-date').val();
      if(!no||!nm) bad++;
      if(!ver && !isForm) badVer++;
      if(!dt) badDate++;
      rows.push({
        doc_no:no, doc_name:nm,
        doc_type:$('#batch_type').val(), doc_level:$('#batch_level').val(),
        department_id:$('#batch_dept').val(), parent_doc_id:$('#batch_parent').val(),
        version:ver, change_status:'制訂',
        revised_date:dt, revised_pages:'', revised_summary:$(this).find('.b-sum').val().trim(),
        tag_ids:[]
      });
    });
    if(bad){ alert(`有 ${bad} 列缺少編號或名稱`); return; }
    if(badVer){ alert(`有 ${badVer} 列缺少版本號（僅表單類別可不填）`); return; }
    if(badDate){ alert(`有 ${badDate} 列缺少修訂日期`); return; }
    const fd = new FormData();
    fd.append('rows', JSON.stringify(rows));
    for(let i=0;i<files.length;i++){
      fd.append('file_'+i, files[i]);
      const af = $('#batchRows tr').eq(i).find('.b-apply')[0].files[0];
      if(af) fd.append('apply_'+i, af);
    }
    const $b=$(this).prop('disabled',true);
    NProgress.start();
    $.ajax({url:API+'?action=create_documents_batch', type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
     .done(r=>{
        if(r.status!=='success'){ alert(r.message||'失敗'); return; }
        const fails = (r.results||[]).filter(x=>!x.success);
        if(fails.length===0){
          // 全部成功：關窗 + 提示 + 自動篩選出剛上傳的文件
          $('#batchModal').modal('hide');
          showToast(`批次上傳完成：成功 ${r.ok} 筆`);
          $('#searchKw').val(''); $('#filterLevel').val(''); $('#filterDept').val('');
          activeTagId = 0; renderTagFilter();
          const pid = $('#batch_parent').val();
          if(pid){
            // 有母文件 → 直接進入該母文件的表單檢視
            activeParentId = parseInt(pid)||0;
            activeParentNo = $('#batch_parent option:selected').text().split('｜')[0]||'';
          } else {
            // 無母文件 → 用這批編號的共同前綴當搜尋條件
            activeParentId = 0; activeParentNo = '';
            const okNos = rows.filter((x,i)=>r.results[i] && r.results[i].success).map(x=>x.doc_no);
            let lcp = okNos[0]||'';
            for(const n of okNos){ while(lcp && !n.startsWith(lcp)) lcp = lcp.slice(0,-1); }
            if(lcp.length >= 4) $('#searchKw').val(lcp);
          }
          loadMeta(loadDocs);
        } else {
          // 部分失敗：留在跳窗顯示明細供補救
          let html = `<div class="alert alert-warning">完成：成功 ${r.ok} / ${r.total} 筆，失敗列請修正後重新上傳</div>`;
          html += '<ul style="color:#a94442;">'+fails.map(f=>`<li>${esc(f.doc_no||('第'+(f.index+1)+'列'))}：${esc(f.message)}</li>`).join('')+'</ul>';
          $('#batchResult').html(html);
          loadMeta(loadDocs);
        }
     })
     .fail(()=>alert('請求失敗')).always(()=>{ NProgress.done(); $b.prop('disabled',false); });
  });

  // ── 表單填寫紀錄（品質紀錄） ──
  let recPage = 1;
  function loadRecords(docId, page){
    recPage = page||1;
    $.getJSON(API+'?action=form_records_list', {doc_id:docId, page:recPage, page_size:10}, r=>{
      if(r.status!=='success'){ alert(r.message||'讀取失敗'); return; }
      $('#rec_doc_name').text(r.doc.doc_no+'｜'+r.doc.doc_name);
      $('#rec_linked_module').val(r.doc.linked_module||'');
      $('#recLinkedWrap').toggle(canS);
      // 電子化區
      if(r.electronic){
        $('#recElectronicBlock').show();
        $('#recElecInfo').text(`${r.electronic.module_name}｜共 ${r.electronic.total} 筆（顯示最新 20 筆）`);
        $('#recElecLink').attr('href', r.electronic.page_url);
        const eb=$('#recElecBody').empty();
        (r.electronic.rows||[]).forEach(x=>eb.append(`<tr><td>${esc(x.no)}</td><td>${esc(x.rec_date)||'-'}</td><td>${esc((x.title||'').substring(0,80))}</td></tr>`));
        if(!(r.electronic.rows||[]).length) eb.append('<tr><td colspan="3" class="text-muted text-center">尚無資料</td></tr>');
      } else { $('#recElectronicBlock').hide(); }
      // 紙本區
      $('#recPaperInfo').text(`共 ${r.total} 筆`);
      const tb=$('#recPaperBody').empty();
      (r.records||[]).forEach(x=>{
        let op = `<a class="btn btn-xs btn-default" href="${API}?action=form_record_download&id=${x.id}&inline=1" target="_blank">預覽</a> `;
        if(canDL) op += `<a class="btn btn-xs btn-info" href="${API}?action=form_record_download&id=${x.id}">下載</a> `;
        if(canD) op += `<button class="btn btn-xs btn-danger rec-del" data-id="${x.id}">刪</button>`;
        tb.append(`<tr><td>${esc(x.title)}</td><td>${esc(x.record_date)||'-'}</td><td>${esc(x.note)||'-'}</td><td>${esc(x.uploaded_by_name)||'-'}</td><td class="text-nowrap">${op}</td></tr>`);
      });
      if(!(r.records||[]).length) tb.append('<tr><td colspan="5" class="text-muted text-center">尚無紙本紀錄</td></tr>');
      // 分頁
      const pages = Math.max(1, Math.ceil(r.total/r.page_size));
      const pg=$('#recPager').empty();
      for(let i=1;i<=pages;i++){
        if(i===1||i===pages||Math.abs(i-recPage)<=2) pg.append(`<li class="${i===recPage?'active':''}"><a href="#" data-p="${i}">${i}</a></li>`);
        else if(Math.abs(i-recPage)===3) pg.append('<li class="disabled"><a>…</a></li>');
      }
      // 上傳區依權限
      $('#recUploadBlock').toggle(canC);
    });
  }
  $('#docTableBody').on('click','.op-record', function(){
    $('#rec_doc_id').val($(this).data('id'));
    $('#rec_files').val(''); $('#recUploadRows').empty(); $('#recUploadSubmit').hide(); $('#recUploadResult').empty();
    loadRecords($(this).data('id'), 1);
    $('#recordModal').modal('show');
  });
  $('#recPager').on('click','a',function(e){ e.preventDefault(); const p=parseInt($(this).data('p')); if(p) loadRecords($('#rec_doc_id').val(), p); });
  $('#recPaperBody').on('click','.rec-del', function(){
    if(!confirm('刪除此筆紀錄？')) return;
    $.post(API+'?action=form_record_delete',{id:$(this).data('id')}, r=>{
      if(r.status==='success'){ loadRecords($('#rec_doc_id').val(), recPage); loadDocs(); } else alert(r.message);
    },'json');
  });
  $('#recLinkedSave').on('click', function(){
    $.post(API+'?action=set_linked_module',{doc_id:$('#rec_doc_id').val(), module:$('#rec_linked_module').val()}, r=>{
      if(r.status==='success'){ loadRecords($('#rec_doc_id').val(), 1); loadDocs(); } else alert(r.message);
    },'json');
  });
  // 批次上傳紀錄：選檔後逐列填標題（預設=檔名）/日期/備註
  $('#rec_files').on('change', function(){
    const tb=$('#recUploadRows').empty();
    const d = $('#rec_common_date').val() || '';
    for(let i=0;i<this.files.length;i++){
      const nameNoExt = this.files[i].name.replace(/\.[^.]+$/,'');
      tb.append(`<tr>
        <td style="width:22%;vertical-align:middle;">${esc(this.files[i].name)}</td>
        <td style="width:28%;"><input type="text" class="form-control input-sm ru-title" value="${esc(nameNoExt)}" placeholder="標題(必填)"></td>
        <td style="width:16%;"><input type="date" class="form-control input-sm ru-date" value="${d}" max="9999-12-31"></td>
        <td><input type="text" class="form-control input-sm ru-note" placeholder="備註(選填)"></td>
      </tr>`);
    }
    $('#recUploadSubmit').toggle(this.files.length>0);
  });
  $('#rec_common_date').on('change', function(){ $('.ru-date').val($(this).val()); });
  $('#recUploadSubmit').on('click', function(){
    const files = $('#rec_files')[0].files;
    if(!files.length) return;
    const rows=[]; let bad=0;
    $('#recUploadRows tr').each(function(){
      const t=$(this).find('.ru-title').val().trim();
      if(!t) bad++;
      rows.push({title:t, record_date:$(this).find('.ru-date').val(), note:$(this).find('.ru-note').val().trim()});
    });
    if(bad){ alert(`有 ${bad} 列缺少標題`); return; }
    const fd=new FormData();
    fd.append('doc_id', $('#rec_doc_id').val());
    fd.append('rows', JSON.stringify(rows));
    for(let i=0;i<files.length;i++) fd.append('file_'+i, files[i]);
    const $b=$(this).prop('disabled',true); NProgress.start();
    $.ajax({url:API+'?action=form_records_upload', type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
     .done(r=>{
        if(r.status!=='success'){ alert(r.message||'失敗'); return; }
        const fails=(r.results||[]).filter(x=>!x.success);
        $('#recUploadResult').html(`<div class="alert ${r.ok===r.total?'alert-success':'alert-warning'}" style="margin-top:8px;">上傳完成：成功 ${r.ok} / ${r.total} 筆</div>`
          + (fails.length? '<ul style="color:#a94442;">'+fails.map(f=>`<li>第${f.index+1}列：${esc(f.message)}</li>`).join('')+'</ul>':''));
        $('#rec_files').val(''); $('#recUploadRows').empty(); $('#recUploadSubmit').hide();
        loadRecords($('#rec_doc_id').val(), 1); loadDocs();
     })
     .fail(()=>alert('請求失敗')).always(()=>{ NProgress.done(); $b.prop('disabled',false); });
  });

  // ── 角色設定（Roles_API module='as_doc'；寫入需系統管理員） ──
  const ROLES_API = '../../src/store/Roles_API.php';
  const AS_FEATURES = [
    {code:'asdoc_view',        label:'檢閱/預覽'},
    {code:'asdoc_create',      label:'新增文件'},
    {code:'asdoc_update',      label:'改版/編輯'},
    {code:'asdoc_download',    label:'下載原檔'},
    {code:'asdoc_delete',      label:'刪除/還原'},
    {code:'asdoc_settings',    label:'文管設定'},
    {code:'asdoc_edit_online', label:'線上開檔'},
    {code:'asdoc_no_attach',   label:'免附件補登'}
  ];
  let AS_ROLES = [];

  function loadRoleDefs(){
    $.getJSON(ROLES_API, {action:'get_roles', module:'as_doc'}, r=>{
      if(!r.success){ alert('讀取角色失敗'); return; }
      AS_ROLES = r.data||[];
      const tb=$('#roleDefBody').empty();
      AS_ROLES.forEach(role=>{
        if(parseInt(role.is_system)===1){
          tb.append(`<tr><td><span class="label label-danger">${esc(role.role_name)}</span></td><td class="text-muted">全部功能（系統角色，不可修改）</td><td></td></tr>`);
          return;
        }
        const cbs = AS_FEATURES.map(f=>`<label class="checkbox-inline" style="font-size:12px;"><input type="checkbox" class="rf-cb" value="${f.code}"> ${f.label}</label>`).join(' ');
        tb.append(`<tr data-role="${role.role_id}">
          <td><input type="text" class="form-control input-sm rf-name" value="${esc(role.role_name)}"></td>
          <td class="rf-feats">${cbs}</td>
          <td class="text-nowrap">
            <button class="btn btn-xs btn-primary rf-save">儲存</button>
            <button class="btn btn-xs btn-danger rf-del">刪除</button>
          </td></tr>`);
        // 載入該角色目前功能
        $.getJSON(ROLES_API, {action:'get_role_features', role_id:role.role_id}, fr=>{
          if(fr.success) (fr.data||[]).forEach(fc=>$(`#roleDefBody tr[data-role="${role.role_id}"] .rf-cb[value="${fc}"]`).prop('checked',true));
        });
      });
    });
  }
  $('#btnAddRole').on('click', function(){
    const name=$('#newRoleName').val().trim(); if(!name){ alert('請輸入角色名稱'); return; }
    $.post(ROLES_API, {action:'save_role', role_name:name, module:'as_doc'}, r=>{
      if(r.success){ $('#newRoleName').val(''); loadRoleDefs(); } else alert(r.message||'新增失敗');
    },'json');
  });
  $('#roleDefBody').on('click','.rf-save', function(){
    const tr=$(this).closest('tr'), rid=tr.data('role');
    const name=tr.find('.rf-name').val().trim();
    const feats=[]; tr.find('.rf-cb:checked').each(function(){ feats.push($(this).val()); });
    $.post(ROLES_API, {action:'save_role', role_id:rid, role_name:name, module:'as_doc'}, r=>{
      if(!r.success){ alert(r.message||'儲存失敗'); return; }
      $.post(ROLES_API, {action:'save_role_features', role_id:rid, features:JSON.stringify(feats)}, r2=>{
        if(r2.success){ alert('已儲存'); loadRoleDefs(); } else alert(r2.message||'功能儲存失敗');
      },'json');
    },'json');
  });
  $('#roleDefBody').on('click','.rf-del', function(){
    if(!confirm('刪除此角色？已指派此角色的使用者/職稱將同時失去對應功能。')) return;
    $.post(ROLES_API, {action:'delete_role', role_id:$(this).closest('tr').data('role')}, r=>{
      if(r.success){ loadRoleDefs(); } else alert(r.message||'刪除失敗');
    },'json');
  });

  $('#btnRoles').on('click', function(){ loadRoleDefs(); $('#rolesModal').modal('show'); });

  // ── 系統設定：部門文件代碼（多列式，一部門可多組） ──
  function deptCodeRowHtml(row){
    row = row||{};
    const dOpts = '<option value="">請選部門</option>'+META.departments.map(d=>`<option value="${d.id}" ${row.department_id==d.id?'selected':''}>${esc(d.name)}</option>`).join('');
    return `<tr class="dc-row">
      <td><select class="form-control input-sm dc-dept">${dOpts}</select></td>
      <td><input type="text" class="form-control input-sm dc-code" value="${esc(row.code||'')}" placeholder="如 TD" maxlength="10" style="text-transform:uppercase;"></td>
      <td><input type="text" class="form-control input-sm dc-label" value="${esc(row.label||'')}" placeholder="如 生管-廠內作業"></td>
      <td class="text-center" style="vertical-align:middle;"><a href="#" class="dc-del text-danger"><i class="fa fa-trash"></i></a></td>
    </tr>`;
  }
  function renderDeptCodes(){
    const tb=$('#deptCodeList').empty();
    (META.dept_codes||[]).forEach(r=>tb.append(deptCodeRowHtml(r)));
    if(!(META.dept_codes||[]).length) tb.append(deptCodeRowHtml());
  }
  $('#deptCodeAddRow').on('click', ()=>$('#deptCodeList').append(deptCodeRowHtml()));
  $('#deptCodeList').on('click','.dc-del', function(e){ e.preventDefault(); $(this).closest('tr').remove(); });
  $('#deptCodeSave').on('click', function(){
    const rows=[];
    $('#deptCodeList .dc-row').each(function(){
      rows.push({ department_id:$(this).find('.dc-dept').val(), code:$(this).find('.dc-code').val().trim(), label:$(this).find('.dc-label').val().trim() });
    });
    $.post(API+'?action=save_dept_codes',{rows:JSON.stringify(rows)}, r=>{
      if(r.status==='success'){ alert('部門代碼已儲存'); loadMeta(renderDeptCodes); } else alert(r.message);
    },'json');
  });
  $('#btnSettings').on('click', function(){
    $.getJSON(API+'?action=get_settings', r=>{
      const d=r.data;
      $('#set_nas_dir').val(d.nas_dir);
      $('#set_owner').val(d.owner_user_id||''); $('#set_deputy').val(d.deputy_user_id||'');
      if(d.apply_form_tpl){ $('#tplStatus').text('已上傳'); $('#tplDownload').show(); } else { $('#tplStatus').text('未上傳'); $('#tplDownload').hide(); }
      renderDeptCodes();
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

  $('#rbacHelp').on('click', function(e){ e.preventDefault(); alert('權限規則（職稱為主、個人優先）：\n1. 預設依「職稱」自動套用角色\n2. 個人另有指派角色時，以個人設定為準（覆蓋職稱）\n3. 職稱與個人的指派都在「權限設定頁 → AS9100 文件管理」區塊操作\n4. 管理員固定擁有全部權限\n\n可勾選的角色功能（本頁「角色設定」定義角色）：\n・檢閱/預覽＝線上預覽（不可下載原檔）\n・新增文件、改版/編輯、下載原檔（各自獨立）\n・刪除/還原\n・文管設定＝標籤/各文件開啟權限/NAS路徑/AS負責人/範本\n・線上開檔＝開工作副本直接打字列印'); });

  // init
  loadMeta(loadDocs);
});
</script>
</body>
</html>
