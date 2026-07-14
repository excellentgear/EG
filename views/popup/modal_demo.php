<?php
// 這是一個示範用的子網頁，不需要完整的 HTML 結構

$received_data = $_POST['demo_data'] ?? '無';
?>

<div class="alert alert-info">
    這是一個從 <strong>modal_demo.php</strong> 載入的內容。<br>
    從主頁面收到的資料 (demo_data): <strong><?= htmlspecialchars($received_data) ?></strong>
</div>

<form action="process_schedule.php" method="POST" class="dynamic-modal-form">
    <input type="hidden" name="action" value="demo_action">
    <button type="submit" class="btn btn-primary">送出測試表單</button>
</form>