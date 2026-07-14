<?php
// c:\MAMP\htdocs\EGsystem\views\popup\modal_part_setting.php
include_once '../../src/common/DBConnection.php';
$db_conn_modal = new DBConnection();
$pdo = $db_conn_modal->getPDO();

$part_to_edit = null;
if (!empty($_GET['pk'])) {
    $part_pk = $_GET['pk']; // This is the d_id
    $stmt = $pdo->prepare("SELECT d.*, c.customer as Client_Name FROM d_setting d LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id WHERE d.d_id = ?");
    $stmt->execute([$part_pk]);
    $part_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($part_to_edit && $part_to_edit['Type'] === 'G') {
        $stmt_gear = $pdo->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id = ? ORDER BY gear_id ASC");
        $stmt_gear->execute([$part_pk]);
        $gears_data = $stmt_gear->fetchAll(PDO::FETCH_ASSOC);
        foreach ($gears_data as &$g) {
            if (isset($g['Helix_Angle']) && $g['Helix_Angle'] !== null) $g['Helix_Angle'] = (float)$g['Helix_Angle'];
            if (isset($g['Profile_Shift_X']) && $g['Profile_Shift_X'] !== null) $g['Profile_Shift_X'] = (float)$g['Profile_Shift_X'];
        }
        $part_to_edit['gears'] = $gears_data;
    }
}

$parts = $db_conn_modal->getAll("SELECT d.d_id, d.D_Setting_Id, d.Spec_No, d.Revision, c.customer as Client_Name FROM d_setting d LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id ORDER BY D_Setting_Id ASC");
?>
<!-- The form will be submitted via AJAX by the script below -->
<form id="part-form" class="form-horizontal form-label-left" onsubmit="return false;">
    <input type="hidden" name="action" value="save_part_info">
    <input type="hidden" id="modal-d-id" name="d_id" value="<?= htmlspecialchars($part_to_edit['d_id'] ?? '') ?>">
    <div class="form-group">
        <label class="control-label col-md-3 col-sm-3 col-xs-12">料號 (Part No) <span class="required">*</span></label>
        <div class="col-md-9 col-sm-9 col-xs-12">
            <input type="text" id="modal-part-no" name="part_no" class="form-control" required value="<?= htmlspecialchars($part_to_edit['D_Setting_Id'] ?? '') ?>">
        </div>
    </div>
    <div class="form-group">
        <label class="control-label col-md-3 col-sm-3 col-xs-12">工件種類</label>
        <div class="col-md-9 col-sm-9 col-xs-12">
            <select id="modal-type" name="type" class="form-control">
                <option value="N" <?= (($part_to_edit['Type'] ?? 'N') == 'N') ? 'selected' : '' ?>>一般 (General)</option>
                <option value="G" <?= (($part_to_edit['Type'] ?? '') == 'G') ? 'selected' : '' ?>>齒輪 (Gear)</option>
                <option value="H" <?= (($part_to_edit['Type'] ?? '') == 'H') ? 'selected' : '' ?>>滾刀 (Hob)</option>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label class="control-label col-md-3 col-sm-3 col-xs-12">客戶</label>
        <div class="col-md-9 col-sm-9 col-xs-12">
            <div class="input-group">
                <input type="text" id="modal-client-search" class="form-control" placeholder="輸入代碼或名稱搜尋..." value="<?= htmlspecialchars($part_to_edit['Client_Name'] ?? '') ?>">
                <input type="hidden" id="modal-customer-id" name="customer_id" value="<?= htmlspecialchars($part_to_edit['Customer_Id'] ?? '') ?>">
                <span class="input-group-btn">
                    <button class="btn btn-default" type="button" id="btn-search-customer"><i class="fa fa-search"></i></button>
                </span>
            </div>
            <div id="customer-search-results" style="position:absolute; z-index:1051; background:white; border:1px solid #ccc; width:93%; max-height:150px; overflow-y:auto; display:none;"></div>
        </div>
    </div>
    <div class="form-group">
        <label class="control-label col-md-3 col-sm-3 col-xs-12">版次 (Revision)</label>
        <div class="col-md-9 col-sm-9 col-xs-12">
            <input type="text" id="modal-revision" name="revision" class="form-control" placeholder="例如: 1.0" value="<?= htmlspecialchars($part_to_edit['Revision'] ?? '') ?>">
        </div>
    </div>
    <div class="form-group">
        <label class="control-label col-md-3 col-sm-3 col-xs-12">發行日期</label>
        <div class="col-md-9 col-sm-9 col-xs-12">
            <input type="date" id="modal-issue-date" name="issue_date" class="form-control" value="<?= htmlspecialchars($part_to_edit['Issue_Date'] ?? '') ?>">
        </div>
    </div>
    <div class="form-group">
        <label class="control-label col-md-3 col-sm-3 col-xs-12">備註</label>
        <div class="col-md-9 col-sm-9 col-xs-12">
            <textarea id="modal-remark" name="remark" class="form-control" rows="3"><?= htmlspecialchars($part_to_edit['Remark'] ?? '') ?></textarea>
        </div>
    </div>
    <!-- 齒輪資料區塊 -->
    <div id="gear-section" style="display:none; border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
        <h4 style="margin-left: 10px;">齒輪詳細資料 <button type="button" class="btn btn-xs btn-success" id="btn-add-gear"><i class="fa fa-plus"></i> 新增齒輪</button></h4>
        <div id="gear-rows-container" style="padding: 0 10px;">
            <!-- 動態生成齒輪列 -->
        </div>
    </div>

    <div class="ln_solid"></div>
    <div class="form-group">
        <div class="col-md-9 col-sm-9 col-xs-12 col-md-offset-3">
            <button type="button" class="btn btn-danger" id="btn-delete-part" style="display: <?= $part_to_edit ? 'inline-block' : 'none' ?>;">刪除</button>
            <button type="button" class="btn btn-default" id="btn-clear-part">清空/新增</button>
            <button type="button" class="btn btn-primary" id="btn-save-part">儲存</button>
        </div>
    </div>
</form>
<hr>
<!-- <button type="button" class="btn btn-default btn-sm" onclick="$('#partListWrapper').toggle()">顯示/隱藏列表</button> -->
<div id="partListWrapper" style="display:none; margin-top: 10px;">
    <div style="max-height: 300px; overflow-y: auto;">
        <table id="partListTable" class="table table-striped table-bordered">
            <thead><tr><th>料號</th><th>客戶</th><th>版次</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach($parts as $p): ?>
                <tr style="cursor:pointer;" onclick="reloadPartModal('<?= $p['d_id'] ?>')">
                    <td><?= htmlspecialchars($p['D_Setting_Id']) ?></td>
                    <td><?= htmlspecialchars($p['Client_Name']) ?></td>
                    <td><?= htmlspecialchars($p['Revision']) ?></td>
                    <td><button type="button" class="btn btn-xs btn-info">編輯</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
// This script runs inside the shared modal
$(function() {
    // Helper to escape HTML
    function escapeHtml(text) {
        if (text == null) return '';
        return text.toString().replace(/[&<>"']/g, function(m) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; });
    }

    // Save Part
    $('#btn-save-part').click(function() {
        // Validate that if customer field has text, it has a selected PK
        if ($('#modal-client-search').val().trim() !== '' && $('#modal-customer-id').val().trim() === '') {
            alert('客戶名稱無效，請從建議列表中選擇或將欄位清空。');
            $('#modal-client-search').val('').focus();
            return;
        }

        // 收集齒輪資料
        var gears = [];
        if ($('#modal-type').val() === 'G') {
            $('#gear-rows-container .gear-row').each(function() {
                gears.push({
                    Module: $(this).find('.gear-module').val(),
                    Teeth: $(this).find('.gear-teeth').val(),
                    Face_Width: $(this).find('.gear-face-width').val(),
                    Helix_Angle: $(this).find('.hidden-helix-val').val(),
                    Helix_Angle_Str: $(this).find('.hidden-helix-str').val(),
                    Helix_Direction: $(this).find('.gear-direction').val(),
                    Profile_Shift_X: $(this).find('.gear-shift-x').val(),
                    Pressure_Angle: $(this).find('.gear-pressure-angle').val(),
                    Workpiece_Length: $(this).find('.gear-length').val(),
                    Gear_Type: $(this).find('.gear-type').val(),
                    Remark_Gear: $(this).find('.gear-remark').val()
                });
            });
        }
        var formData = $('#part-form').serialize();
        formData += '&gears=' + encodeURIComponent(JSON.stringify(gears));
        $.post('NewOrder_Track.php', formData, function(res) {
            if (res.success) {
                alert('料號資料儲存成功');
                // Reload modal content
                $('#sharedModalBody').load('../popup/modal_part_setting.php');
            } else {
                alert('儲存失敗: ' + res.message);
            }
        }, 'json');
    });

    // Delete Part
    $('#btn-delete-part').click(function() {
        if (!confirm('確定要刪除此料號嗎？')) return;
        var d_id = $('#modal-d-id').val();
        $.post('NewOrder_Track.php', { action: 'delete_part', d_id: d_id }, function(res) {
            if (res.success) {
                alert('刪除成功');
                $('#sharedModalBody').load('../popup/modal_part_setting.php');
            } else {
                alert('刪除失敗: ' + res.message);
            }
        }, 'json');
    });

    // Clear/Reset form to new mode
    $('#btn-clear-part').click(function() {
        $('#part-form')[0].reset();
        $('#modal-d-id').val('');
        $('#btn-delete-part').hide();
    });

    // Customer Search
    $('#modal-client-search').on('input', function() {
        var kw = $(this).val().trim();
        if (kw.length < 1) { $('#customer-search-results').hide(); return; }
        
        $.post('NewOrder_Track.php', { action: 'search_customers', keyword: kw }, function(res) {
            if (res.success && res.data.length > 0) {
                var html = '';
                res.data.forEach(function(item) {
                    html += `<div class="customer-search-item" data-id="${item.customer_id}" data-name="${escapeHtml(item.customer)}" style="padding:5px; cursor:pointer; border-bottom:1px solid #eee;">
                        <strong>${escapeHtml(item.customer_id)}</strong> ${escapeHtml(item.customer)}
                    </div>`;
                });
                $('#customer-search-results').html(html).show();
            } else {
                $('#customer-search-results').hide();
            }
        }, 'json');
    });

    // If user blurs from customer search without selecting, clear the input
    $('#modal-client-search').on('blur', function() {
        setTimeout(function() {
            if ($('#customer-search-results').is(':visible')) {
                return;
            }
            if ($('#modal-client-search').val().trim() !== '' && $('#modal-customer-id').val().trim() === '') {
                $('#modal-client-search').val('');
                // No toast function here, just clear the field. Save validation will alert the user.
            }
        }, 250);
    });

    $(document).on('click', '.customer-search-item', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        $('#modal-customer-id').val(id);
        $('#modal-client-search').val(name);
        $('#customer-search-results').hide();
    });

    // Hide results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#modal-client-search, #customer-search-results').length) {
            $('#customer-search-results').hide();
        }
    });

    // 工件種類切換
    $('#modal-type').change(function() {
        if ($(this).val() === 'G') {
            $('#gear-section').slideDown();
            if ($('#gear-rows-container').children().length === 0) {
                addGearRow(); // 預設加一列
            }
        } else {
            $('#gear-section').slideUp();
        }
    });

    // 新增齒輪列
    $('#btn-add-gear').click(function() {
        addGearRow();
    });

    // Load existing gear data
    var existingGears = <?= json_encode($part_to_edit['gears'] ?? []) ?>;
    if (existingGears && existingGears.length > 0) {
        existingGears.forEach(function(gearData) {
            addGearRow(gearData);
        });
        $('#modal-type').val('G').trigger('change'); // Ensure gear section is visible
    }

    // Event handler for Gear Type change to hide/show Helix Angle
    $(document).on('change', '.gear-type', function() {
        var $row = $(this).closest('.gear-row');
        var selectedType = $(this).val();
        var $helixGroup = $row.find('.helix-angle-group');
        if (selectedType && selectedType.includes('螺旋')) {
            $helixGroup.slideDown();
        } else {
            $helixGroup.slideUp();
        }
    });

    // Event handler for Module input blur to auto-format
    $(document).on('blur', '.gear-module', function() {
        let val = $(this).val().trim().toUpperCase();
        if (val !== '' && !isNaN(val.charAt(0))) { // If it starts with a number
            $(this).val('M' + val);
        } else {
            $(this).val(val); // Keep as is (e.g., DP10, CP5)
        }
    });

    // 螺旋角模式切換與計算
    $(document).on('click', '.btn-mode-dec', function() {
        var $group = $(this).closest('.helix-angle-group');
        $group.find('.mode-decimal').show();
        $group.find('.mode-dms').hide();
        $(this).addClass('active').siblings().removeClass('active');
    });
    $(document).on('click', '.btn-mode-dms', function() {
        var $group = $(this).closest('.helix-angle-group');
        $group.find('.mode-decimal').hide();
        $group.find('.mode-dms').css('display', 'flex');
        $(this).addClass('active').siblings().removeClass('active');
    });

    // 計算並更新隱藏欄位
    $(document).on('input', '.gear-helix-val', function() {
        var val = $(this).val();
        var $group = $(this).closest('.helix-angle-group');
        $group.find('.hidden-helix-val').val(val);
        $group.find('.hidden-helix-str').val(val);
    });

    $(document).on('input', '.dms-d, .dms-m, .dms-s', function() {
        var $group = $(this).closest('.helix-angle-group');
        var d = parseFloat($group.find('.dms-d').val()) || 0;
        var m = parseFloat($group.find('.dms-m').val()) || 0;
        var s = parseFloat($group.find('.dms-s').val()) || 0;
        
        var decimal = d + (m / 60) + (s / 3600);
        $group.find('.hidden-helix-val').val(decimal.toFixed(6));
        
        var str = d + "°" + m + "'" + s + '"';
        $group.find('.hidden-helix-str').val(str);
    });
});

// Function to reload the modal with a specific part's data
function reloadPartModal(part_pk) {
    $('#sharedModalBody').load('../popup/modal_part_setting.php?pk=' + part_pk);
}

function addGearRow(data = {}) {
    const gearType = data.Gear_Type || '';
    const module = data.Module || '';
    const teeth = data.Teeth || '';
    const pa = data.Pressure_Angle || '';
    const width = data.Face_Width || '';
    const length = data.Workpiece_Length || '';
    const remark = data.Remark_Gear || '';
    const helix_angle = (data.Helix_Angle !== undefined && data.Helix_Angle !== null && data.Helix_Angle !== '') ? parseFloat(data.Helix_Angle) : ''; 
    const helix_str = data.Helix_Angle_Str || ''; 
    const direction = data.Helix_Direction || ''; 
    const shift_x = data.Profile_Shift_X !== null ? parseFloat(data.Profile_Shift_X) : ''; 
    const showHelix = String(gearType).includes('螺旋');

    const html = `
        <div class="gear-row" style="padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 10px; background-color: #f9f9f9;">
            <div class="row">
                <div class="col-md-3 form-group"><label>齒輪類型</label><select class="form-control input-sm gear-type"><option value="" ${gearType === '' ? 'selected' : ''}>請選擇</option><option value="直齒" ${gearType === '直齒' ? 'selected' : ''}>直齒</option><option value="螺旋" ${gearType === '螺旋' ? 'selected' : ''}>螺旋</option><option value="傘齒" ${gearType === '傘齒' ? 'selected' : ''}>傘齒</option><option value="蝸桿" ${gearType === '蝸桿' ? 'selected' : ''}>蝸桿</option><option value="蝸輪" ${gearType === '蝸輪' ? 'selected' : ''}>蝸輪</option></select></div>
                <div class="col-md-3 form-group"><label>模數 (Module)</label><input type="text" class="form-control input-sm gear-module" value="${escapeHtml(module)}"></div>
                <div class="col-md-3 form-group"><label>齒數 (Teeth)</label><input type="number" class="form-control input-sm gear-teeth" value="${escapeHtml(teeth)}"></div>
                <div class="col-md-3 form-group helix-angle-group" style="display: ${showHelix ? 'block' : 'none'}; background-color: #e9ecef; padding: 5px; border-radius: 4px;"><label>螺旋角</label><div style="display:flex; gap:5px; margin-bottom:5px;"><select class="form-control input-sm gear-direction" style="width:70px;"><option value="" ${direction === '' ? 'selected' : ''}>旋向</option><option value="RH" ${direction === 'RH' ? 'selected' : ''}>RH(右)</option><option value="LH" ${direction === 'LH' ? 'selected' : ''}>LH(左)</option></select><div class="btn-group btn-group-xs" data-toggle="buttons"><label class="btn btn-default active btn-mode-dec"><input type="radio" name="options_${Date.now()}" autocomplete="off" checked> 十進位</label><label class="btn btn-default btn-mode-dms"><input type="radio" name="options_${Date.now()}" autocomplete="off"> 度分秒</label></div></div><div class="mode-decimal"><input type="number" step="any" class="form-control input-sm gear-helix-val" value="${helix_angle}" placeholder="例如 15.5"></div><div class="mode-dms" style="display:none; align-items:center; gap:2px;"><input type="number" class="form-control input-sm dms-d" placeholder="度" style="width:45px;">°<input type="number" class="form-control input-sm dms-m" placeholder="分" style="width:45px;">'<input type="number" class="form-control input-sm dms-s" placeholder="秒" style="width:45px;">"</div><input type="hidden" class="hidden-helix-val" value="${helix_angle}"><input type="hidden" class="hidden-helix-str" value="${helix_str}"></div>
            </div>
            <div class="row">
                <div class="col-md-3 form-group"><label>壓力角 (PA)</label><input type="text" class="form-control input-sm gear-pressure-angle" placeholder="例如: 20" value="${escapeHtml(pa)}"></div>
                <div class="col-md-3 form-group"><label>齒寬 (W)</label><input type="number" step="0.01" class="form-control input-sm gear-face-width" placeholder="單位 mm" value="${escapeHtml(width)}"></div>
                <div class="col-md-3 form-group"><label>工件總長 (L)</label><input type="number" step="0.01" class="form-control input-sm gear-length" placeholder="單位 mm" value="${escapeHtml(length)}"></div>
                <div class="col-md-3 form-group"><label>轉位係數 X</label><input type="number" class="form-control input-sm gear-shift-x" step="any" value="${shift_x}" placeholder="如 0.315"></div>
            </div>
            <div class="row">
                <div class="col-md-9 form-group"><label>備註</label><input type="text" class="form-control input-sm gear-remark" value="${escapeHtml(remark)}"></div>
                <div class="col-md-3 form-group" style="text-align:right; padding-top:25px;"><button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest('.gear-row').remove()"><i class="fa fa-trash"></i> 刪除此齒輪</button></div>
            </div>
        </div>
    `;
    $('#gear-rows-container').append(html);

    if (helix_str && (helix_str.includes('°') || helix_str.includes("'"))) {
        const $lastRow = $('#gear-rows-container .gear-row').last();
        $lastRow.find('.btn-mode-dms').trigger('click');
        const d = helix_str.split('°')[0];
        const m = helix_str.split('°')[1] ? helix_str.split('°')[1].split("'")[0] : '';
        const s = helix_str.split("'")[1] ? helix_str.split("'")[1].split('"')[0] : '';
        $lastRow.find('.dms-d').val(d);
        $lastRow.find('.dms-m').val(m);
        $lastRow.find('.dms-s').val(s);
    }
}
</script>
<script>
// This script is specific to modal_part_setting.php
$(function() {
    // 根據需求，隱藏父層 modal (#sharedDynamicModal) 的通用 footer，因為此彈窗有自己的按鈕。
    $('#sharedDynamicModal .modal-footer').hide();

    // 使用 .one() 綁定一次性事件。當此 modal 關閉時，將 footer 顯示回來，
    // 以免影響其他使用 #sharedDynamicModal 的功能。
    $('#sharedDynamicModal').one('hidden.bs.modal', function () {
        $(this).find('.modal-footer').show();
    });
});
</script>
