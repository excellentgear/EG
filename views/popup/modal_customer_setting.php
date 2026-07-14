<?php
// c:\MAMP\htdocs\EGsystem\views\popup\modal_customer_setting.php
include_once '../../src/common/DBConnection.php';
$db_conn_modal = new DBConnection();
$pdo = $db_conn_modal->getPDO();

$customer_to_edit = null;
if (!empty($_GET['pk'])) {
    $customer_pk = $_GET['pk']; // This is the customer_id
    $stmt = $pdo->prepare("SELECT * FROM customer_list WHERE customer_id = ?");
    $stmt->execute([$customer_pk]);
    $customer_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$customers = $db_conn_modal->getAll("SELECT * FROM customer_list WHERE is_inactive = 0 ORDER BY customer_id ASC");
?>
<form id="customerForm" class="dynamic-modal-form" action="NewOrder_Track.php" method="POST">
    <input type="hidden" name="action" value="save_customer">
    <input type="hidden" name="customer_id_modal" id="customer_id_modal" value="<?= htmlspecialchars($customer_to_edit['customer_id'] ?? '') ?>">
    <div class="row">
        <div class="col-md-4 form-group">
            <label>客戶代碼</label>
            <input type="text" class="form-control" name="customer_id_new" id="customer_id_new" value="<?= htmlspecialchars($customer_to_edit['customer_id'] ?? '') ?>" <?= $customer_to_edit ? 'readonly' : '' ?> placeholder="新增時必填">
        </div>
        <div class="col-md-8 form-group">
            <label>客戶名稱</label>
            <input type="text" class="form-control" name="customer_name_modal" id="customer_name_modal" value="<?= htmlspecialchars($customer_to_edit['customer'] ?? '') ?>" required>
        </div>
        <div class="col-md-12 form-group">
            <label>地址</label>
            <input type="text" class="form-control" name="customer_address_modal" id="customer_address_modal" value="<?= htmlspecialchars($customer_to_edit['customer_address'] ?? '') ?>">
        </div>
        <div class="col-md-6 form-group">
            <label>電話</label>
            <input type="text" class="form-control" name="customer_tel_modal" id="customer_tel_modal" value="<?= htmlspecialchars($customer_to_edit['customer_tel'] ?? '') ?>">
        </div>
        <div class="col-md-6 form-group">
            <label>傳真</label>
            <input type="text" class="form-control" name="customer_fax_modal" id="customer_fax_modal" value="<?= htmlspecialchars($customer_to_edit['customer_fax'] ?? '') ?>">
        </div>
    </div>
    <button type="submit" class="btn btn-primary">儲存</button>
    <button type="button" class="btn btn-default" onclick="$('#customerForm')[0].reset(); $('#customer_id_new').prop('readonly', false);">清除</button>
</form>
<hr>
<!-- <button type="button" class="btn btn-default btn-sm" onclick="$('#customerListWrapper').toggle()">顯示/隱藏列表</button> -->
<div id="customerListWrapper" style="display:none; margin-top: 10px;">
    <div style="max-height: 300px; overflow-y: auto;">
        <table id="customerListTable" class="table table-striped table-bordered">
            <thead><tr><th>代碼</th><th>名稱</th><th>地址</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach($customers as $c): ?>
                <tr style="cursor:pointer;" onclick="editCustomer('<?= htmlspecialchars($c['customer_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($c['customer'], ENT_QUOTES) ?>', '<?= htmlspecialchars($c['customer_address'], ENT_QUOTES) ?>', '<?= htmlspecialchars($c['customer_tel'], ENT_QUOTES) ?>', '<?= htmlspecialchars($c['customer_fax'], ENT_QUOTES) ?>')">
                    <td><?= htmlspecialchars($c['customer_id']) ?></td>
                    <td><?= htmlspecialchars($c['customer']) ?></td>
                    <td><?= htmlspecialchars($c['customer_address']) ?></td>
                    <td><button type="button" class="btn btn-xs btn-info">編輯</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
function editCustomer(id, name, addr, tel, fax) {
    $('#customer_id_modal').val(id);
    $('#customer_id_new').val(id).prop('readonly', true);
    $('#customer_name_modal').val(name);
    $('#customer_address_modal').val(addr);
    $('#customer_tel_modal').val(tel);
    $('#customer_fax_modal').val(fax);
}

$(function() {
    $('#customerForm').on('submit', function(e) {
        e.preventDefault();
        var formData = $(this).serialize();
        $.post('NewOrder_Track.php', formData, function(res) {
            if (res.success) {
                alert('客戶資料儲存成功');
                // Reload modal content to show updated list and clear form
                $('#sharedModalBody').load('../popup/modal_customer_setting.php');
            } else {
                alert('儲存失敗: ' + (res.message || '未知錯誤'));
            }
        }, 'json').fail(function() {
            alert('請求失敗，請檢查網路連線。');
        });
    });
});
</script>
<script>
// This script is specific to modal_customer_setting.php
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
