<?php
// This file is loaded via AJAX into a modal.
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/_config.php';

$db_conn_modal = new DBConnection();
$pdo = $db_conn_modal->getPDO();

// Fetch all process types
$process_types = $pdo->query("SELECT * FROM process_type ORDER BY CAST(process_type_id AS UNSIGNED) ASC, process_type_id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all processes
$processes = $pdo->query("SELECT * FROM process_no ORDER BY CAST(ProcessNo AS UNSIGNED) ASC, ProcessNo ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch visible tabs setting
$stmt_tabs = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'PROCESS_SCHEDULE' AND param_key = 'visible_tabs'");
$stmt_tabs->execute();
$visible_tabs_json = $stmt_tabs->fetchColumn();
$visible_tabs = $visible_tabs_json ? json_decode($visible_tabs_json, true) : [];

// Fetch tab font size settings
$stmt_fonts = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'PROCESS_SCHEDULE' AND param_key = 'tab_font_sizes'");
$stmt_fonts->execute();
$font_sizes_json = $stmt_fonts->fetchColumn();
$font_sizes = $font_sizes_json ? json_decode($font_sizes_json, true) : [];


// Fetch UI display settings for reporting fields
$stmt_ui = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'PROCESS_SCHEDULE_SETTINGS' AND param_key = 'ui_display_settings'");
$stmt_ui->execute();
$row_ui = $stmt_ui->fetch(PDO::FETCH_ASSOC);
$ui_settings = ($row_ui && !empty($row_ui['param_value'])) ? json_decode($row_ui['param_value'], true) : ['show_face_options' => [], 'show_material_arrived' => []];
?>
<style>
/* 美化與多欄位排版樣式 */
    .tab-pane {
        padding: 15px;
        background-color: #fff;
        border: 1px solid #ddd;
        border-top: none;
    }

    /* 統一的 Grid 容器 */
    .data-grid-container {
        /* 移除原本的 display: flex; 與 flex-wrap: wrap; */
        column-count: 2;     /* 改用這個：預設切成兩欄，且會由上往下排 */
        column-gap: 10px;    /* 欄位之間的間距 */
        
        /* 下面保留你原本的設定 */
        max-height: 500px;
        overflow-y: auto;
        padding: 5px;
        background: #f9f9f9;
        border: 1px solid #eee;
    }

    /* Grid 項目卡片 */
    .data-grid-item {
        /* 移除原本的 flex: 1 1 48%; */
        break-inside: avoid; /* 新增：避免卡片在換欄時被切成兩半 */
        margin-bottom: 10px; /* 新增：代替原本 flex 的間距 */
        
        /* 下面保留你原本的設定 */
        min-width: 250px; 
        background: #fff;
        border: 1px solid #ddd;
        padding: 8px 12px;
        border-radius: 4px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
    }

    /* 標頭樣式 (偽裝成表格標頭) */
    .grid-header {
        display: flex;
        background: #f5f5f5;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-bottom: none;
        font-weight: bold;
        position: sticky;
        top: 0;
        z-index: 10;
        margin-bottom: 5px;
    }

    /* 提示文字 */
    .grid-hint {
        margin-bottom: 5px;
        font-size: 12px;
        color: #777;
    }

    .item-content {
        flex-grow: 1;
        display: flex;
        align-items: center;
    }

    .item-id {
        width: 60px;
        font-weight: bold;
        color: #555;
    }

    .item-name {
        flex-grow: 1;
        text-align: left;
    }

    .item-actions {
        white-space: nowrap;
    }

    /* 輸入框樣式 */
    .edit-input {
        width: 100%;
        padding: 2px 5px;
        font-size: 13px;
        border: 1px solid #ccc;
        border-radius: 3px;
    }

    /* 報工設定表格 */
    .settings-table {
        width: 100%;
        border-collapse: collapse;
    }

    .settings-table th,
    .settings-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }

    .settings-table th {
        background-color: #f5f5f5;
        position: sticky;
        top: 0;
    }

    .settings-table td:first-child {
        text-align: left;
    }

    /* UI 設定項目的特定樣式 */
    .ui-setting-item-content {
        display: flex;
        align-items: center;
        flex-grow: 1;
    }

    .ui-setting-checkboxes {
        display: flex;
        gap: 10px;
        font-size: 12px;
    }

    .ui-setting-checkboxes label {
        margin-bottom: 0;
        font-weight: normal;
        cursor: pointer;
    }
</style>
<!-- The form will be submitted via AJAX by the script below -->
<form id="processSettingsForm" onsubmit="return false;">
    <!-- Nav tabs -->
    <ul class="nav nav-tabs" role="tablist">
        <li role="presentation" class="active"><a href="#tab-display" aria-controls="tab-display" role="tab" data-toggle="tab">分頁顯示設定</a></li>
        <li role="presentation"><a href="#tab-types" aria-controls="tab-types" role="tab" data-toggle="tab">製程分類管理</a></li>
        <li role="presentation"><a href="#tab-processes" aria-controls="tab-processes" role="tab" data-toggle="tab">製程管理</a></li>
        <li role="presentation"><a href="#tab-ui-settings" aria-controls="tab-ui-settings" role="tab" data-toggle="tab">報工欄位設定</a></li>
    </ul>

    <!-- Tab panes -->
    <div class="tab-content">
        <!-- Tab 1: Display Settings -->
        <div role="tabpanel" class="tab-pane active" id="tab-display">
            <h4>勾選要顯示在「加工排程看板」的製程分類分頁</h4>
            <div class="data-grid-container">
                <?php foreach ($process_types as $type): 
                    $current_font_size = $font_sizes[$type['process_type_id']] ?? '14';
                ?>
                    <div class="data-grid-item" style="justify-content: space-between; align-items: center;">
                        <input type="checkbox" name="visible_process_types[]" value="<?= $type['process_type_id'] ?>" <?= in_array($type['process_type_id'], $visible_tabs) ? 'checked' : '' ?> style="margin-right: 10px; transform: scale(1.2);">
                        <span class="item-content"><span class="item-id">ID: <?= $type['process_type_id'] ?></span><span class="item-name"><?= htmlspecialchars($type['process_type']) ?></span></span>
                        <!-- <div class="form-inline"><label style="font-size:12px; margin-right:5px;">分頁字體:</label><input type="number" class="form-control input-sm tab-font-size-input" data-type-id="<?= $type['process_type_id'] ?>" value="<?= $current_font_size ?>" style="width: 60px; text-align: center;"><span style="font-size:12px;"> px</span></div> -->
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top: 15px; text-align: right;">
                <button type="button" class="btn btn-primary" id="btnSaveTabs">儲存分頁設定</button>
            </div>
        </div>

        <!-- Tab 2: Process Type Management -->
        <div role="tabpanel" class="tab-pane" id="tab-types">
            <div class="edit-form-container">
                <h4 style="margin-top:0;" id="type-form-title">新增分類</h4>
                <div class="row">
                    <div class="col-md-3"><input type="text" id="edit_type_id" class="form-control input-sm" placeholder="ID (必填)"></div>
                    <div class="col-md-5"><input type="text" id="edit_type_name" class="form-control input-sm" placeholder="分類名稱"></div>
                    <div class="col-md-4 text-right">
                        <button type="button" class="btn btn-success btn-sm" id="btn-type-submit">新增</button>
                        <button type="button" class="btn btn-danger btn-sm" id="btn-type-delete" style="display:none;">刪除</button>
                        <button type="button" class="btn btn-default btn-sm" id="btn-type-cancel" style="display:none;">取消</button>
                    </div>
                </div>
            </div>
            <div class="grid-hint"><i class="fa fa-info-circle"></i> 雙擊項目可進行編輯</div>
            <div class="grid-header"><div style="width: 60px;">ID</div><div style="flex-grow: 1;">分類名稱</div></div>
            <div class="data-grid-container">
                <?php foreach ($process_types as $type): ?>
                    <div class="data-grid-item row-view" data-id="<?= $type['process_type_id'] ?>" data-name="<?= htmlspecialchars($type['process_type'], ENT_QUOTES) ?>">
                        <div class="item-content"><span class="item-id"><?= $type['process_type_id'] ?></span><span class="item-name"><?= htmlspecialchars($type['process_type']) ?></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tab 3: Process Management -->
        <div role="tabpanel" class="tab-pane" id="tab-processes">
             <div class="edit-form-container">
                <h4 style="margin-top:0;" id="process-form-title">新增製程</h4>
                <div class="row">
                    <div class="col-md-3"><input type="text" id="edit_proc_no" class="form-control input-sm" placeholder="代號"></div>
                    <div class="col-md-4"><input type="text" id="edit_proc_name" class="form-control input-sm" placeholder="製程名稱"></div>
                    <div class="col-md-3">
                        <select id="edit_proc_type" class="form-control input-sm">
                            <option value="">選擇分類</option>
                            <?php foreach ($process_types as $type): ?>
                                <option value="<?= $type['process_type_id'] ?>"><?= htmlspecialchars($type['process_type']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 text-right">
                        <button type="button" class="btn btn-success btn-sm" id="btn-proc-submit">新增</button>
                        <button type="button" class="btn btn-danger btn-sm" id="btn-proc-delete" style="display:none;">刪除</button>
                        <button type="button" class="btn btn-default btn-sm" id="btn-proc-cancel" style="display:none;">取消</button>
                    </div>
                </div>
            </div>
            <div class="grid-hint"><i class="fa fa-info-circle"></i> 雙擊項目可進行編輯</div>
            <div class="grid-header"><div style="width: 60px;">代號</div><div style="flex-grow: 1;">製程名稱 (分類)</div></div>
            <div class="data-grid-container" style="column-count: 3;">
                <?php foreach ($processes as $proc):
                    $typeName = '';
                    foreach ($process_types as $t) { if ($t['process_type_id'] == $proc['process_type_id']) { $typeName = $t['process_type']; break; } }
                ?>
                    <div class="data-grid-item row-view" data-no="<?= $proc['ProcessNo'] ?>" data-name="<?= htmlspecialchars($proc['ProcessName'], ENT_QUOTES) ?>" data-type-id="<?= $proc['process_type_id'] ?>">
                        <div class="item-content">
                            <span class="item-id"><?= $proc['ProcessNo'] ?></span>
                            <div class="item-name"><?= htmlspecialchars($proc['ProcessName']) ?> <small class="text-muted">(<?= htmlspecialchars($typeName) ?>)</small></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tab 4: UI Settings -->
        <div role="tabpanel" class="tab-pane" id="tab-ui-settings">
            <h4>勾選需要在「現場回報」視窗中顯示特定欄位的製程分類</h4>
            <div class="data-grid-container">
                <?php foreach ($process_types as $type):
                    $tid_str = (string)$type['process_type_id'];
                    $faceChecked = in_array($tid_str, $ui_settings['show_face_options'] ?? []) ? 'checked' : '';
                    $materialChecked = in_array($tid_str, $ui_settings['show_material_arrived'] ?? []) ? 'checked' : '';
                ?>
                    <div class="data-grid-item">
                        <div class="item-content">
                            <span class="item-id">ID: <?= $type['process_type_id'] ?></span>
                            <span class="item-name"><?= htmlspecialchars($type['process_type']) ?></span>
                        </div>
                        <div style="display: flex; gap: 15px; font-size: 12px;">
                            <label><input type="checkbox" class="ui-setting-cb" data-setting="show_face_options" value="<?= $tid_str ?>" <?= $faceChecked ?>> 加工面</label>
                            <label><input type="checkbox" class="ui-setting-cb" data-setting="show_material_arrived" value="<?= $tid_str ?>" <?= $materialChecked ?>> 已到料</label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top: 15px; text-align: right;">
                <button type="button" id="btnSaveUiSettings" class="btn btn-primary">儲存欄位設定</button>
            </div>
        </div>
    </div>
</form>

<script>
// This script will be executed inside the modal
$(function() {
    function submitSetting(data) {
        data.action = 'save_process_settings';
        $.post('process_schedule.php', data, function(res) {
            alert(res.message);
            if (res.success) {
                openSharedModal('製程設定', '../popup/modal_process_setting.php');
            }
        }, 'json').fail(function() {
            alert('請求失敗，請檢查網路或聯繫管理員。');
        });
    }

    // --- Tab 1: Display Settings ---
    $('#btnSaveTabs').click(function() {
        var visible_tabs = [];
        var font_sizes = {};

        $('#tab-display .data-grid-item').each(function() {
            var $checkbox = $(this).find('input[type="checkbox"]');
            var typeId = $checkbox.val();
            var fontSize = $(this).find('.tab-font-size-input').val();

            if ($checkbox.is(':checked')) {
                visible_tabs.push(typeId);
            }
            if (fontSize && !isNaN(fontSize)) {
                font_sizes[typeId] = fontSize;
            }
        });

        submitSetting({ sub_action: 'save_tabs', visible_process_types: visible_tabs, font_sizes: JSON.stringify(font_sizes) });
    });

    // --- Tab 2: Process Type Management ---
    function resetTypeForm() {
        $('#edit_type_id').val('').prop('readonly', false);
        $('#edit_type_name').val('');
        $('#type-form-title').text('新增分類');
        $('#btn-type-submit').text('新增').data('mode', 'add');
        $('#btn-type-delete, #btn-type-cancel').hide();
    }
    $('#tab-types').on('dblclick', '.row-view', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        $('#edit_type_id').val(id).prop('readonly', true);
        $('#edit_type_name').val(name).focus();
        $('#type-form-title').text('編輯分類: ' + name);
        $('#btn-type-submit').text('儲存').data('mode', 'edit');
        $('#btn-type-delete, #btn-type-cancel').show();
    });
    $('#btn-type-cancel').click(resetTypeForm);
    $('#btn-type-submit').click(function() {
        var mode = $(this).data('mode');
        var data = { process_type: $('#edit_type_name').val() };
        if (mode === 'add') {
            data.sub_action = 'add_type';
            data.new_type_id = $('#edit_type_id').val();
        } else {
            data.sub_action = 'edit_type';
            data.process_type_id = $('#edit_type_id').val();
        }
        submitSetting(data);
    });
    $('#btn-type-delete').click(function() {
        if (!confirm('確定刪除此分類嗎？')) return;
        submitSetting({ sub_action: 'delete_type', process_type_id: $('#edit_type_id').val() });
    });

    // --- Tab 3: Process Management ---
    function resetProcessForm() {
        $('#edit_proc_no').val('').prop('readonly', false);
        $('#edit_proc_name').val('');
        $('#edit_proc_type').val('');
        $('#process-form-title').text('新增製程');
        $('#btn-proc-submit').text('新增').data('mode', 'add');
        $('#btn-proc-delete, #btn-proc-cancel').hide();
    }
    $('#tab-processes').on('dblclick', '.row-view', function() {
        var no = $(this).data('no');
        var name = $(this).data('name');
        var typeId = $(this).data('type-id');
        $('#edit_proc_no').val(no).prop('readonly', true);
        $('#edit_proc_name').val(name).focus();
        $('#edit_proc_type').val(typeId);
        $('#process-form-title').text('編輯製程: ' + name);
        $('#btn-proc-submit').text('儲存').data('mode', 'edit');
        $('#btn-proc-delete, #btn-proc-cancel').show();
    });
    $('#btn-proc-cancel').click(resetProcessForm);
    $('#btn-proc-submit').click(function() {
        var mode = $(this).data('mode');
        var data = {
            ProcessName: $('#edit_proc_name').val(),
            process_type_id: $('#edit_proc_type').val()
        };
        if (mode === 'add') {
            data.sub_action = 'add_process';
            data.ProcessNo = $('#edit_proc_no').val();
        } else {
            data.sub_action = 'edit_process';
            data.ProcessNo = $('#edit_proc_no').val();
        }
        submitSetting(data);
    });
    $('#btn-proc-delete').click(function() {
        if (!confirm('確定刪除此製程嗎？')) return;
        submitSetting({ sub_action: 'delete_process', ProcessNo: $('#edit_proc_no').val() });
    });

    // --- Tab 4: UI Settings ---
    $('#btnSaveUiSettings').click(function() {
        var newSettings = { show_face_options: [], show_material_arrived: [] };
        $('.ui-setting-cb:checked').each(function() {
            var settingName = $(this).data('setting');
            newSettings[settingName].push($(this).val());
        });
        $.post('process_schedule.php', {
            action: 'save_process_category_settings',
            settings: JSON.stringify(newSettings)
        }, function(res) {
            alert(res.message);
            if (res.success && window.parent && window.parent.uiSettings) {
                window.parent.uiSettings = newSettings;
            }
        }, 'json');
    });
});
</script>