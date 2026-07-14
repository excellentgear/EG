<?php
session_start();
header('Content-Type: application/json');

// 引入設定與資料庫連線
include_once dirname(__DIR__) . '/common/_config.php';

$response = ['success' => false, 'message' => '無效的操作。'];

if (!isset($_SESSION['id'])) {
    $response['message'] = '使用者未登入。';
    echo json_encode($response);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'get_categories':
            // 修正：使用 LEFT JOIN 正確關聯 day_type 資料表，並取得 day_type_id
            $stmt = $db->query("SELECT ec.id, ec.category_name, ec.description, ec.color, ec.day_type_id FROM event_category ec LEFT JOIN day_type dt ON ec.day_type_id = dt.id ORDER BY ec.category_name ASC");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'data' => $categories];
            break;

        case 'add_category':
            $name = trim($_POST['category_name'] ?? '');
            $color = trim($_POST['color'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $day_type_id = trim($_POST['day_type'] ?? ''); // 變數名稱改為 day_type_id 以符合資料庫欄位

            if (empty($name) || empty($color)) {
                $response['message'] = '類別名稱和顏色為必填項。';
                break;
            }

            // 檢查名稱是否重複
            $checkStmt = $db->prepare("SELECT id FROM event_category WHERE category_name = ?");
            $checkStmt->execute([$name]);
            if ($checkStmt->fetch()) {
                $response['message'] = '類別名稱已存在。';
                break;
            }

            // 修正：在 INSERT 語句中加入 day_type_id 欄位
            $sql = "INSERT INTO event_category (category_name, description, color, day_type_id, created_at) VALUES (?, ?, ?, ?, NOW())";
            $stmt = $db->prepare($sql);
            if ($stmt->execute([$name, $description, $color, $day_type_id ?: null])) {
                // 修正：新增成功後，回傳新建立的 ID
                $newId = $db->lastInsertId();
                $response = ['success' => true, 'message' => '類別新增成功。', 'new_id' => $newId];
            } else {
                $response['message'] = '資料庫新增失敗。';
            }
            break;

        case 'update_category':
            $id = $_POST['id'] ?? 0;
            $name = trim($_POST['category_name'] ?? '');
            $color = trim($_POST['color'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $day_type_id = trim($_POST['day_type'] ?? ''); // 新增：接收 day_type 參數

            if (empty($id) || empty($name) || empty($color)) {
                $response['message'] = 'ID、類別名稱和顏色為必填項。';
                break;
            }

            // 檢查名稱是否與其他類別重複
            $checkStmt = $db->prepare("SELECT id FROM event_category WHERE category_name = ? AND id != ?");
            $checkStmt->execute([$name, $id]);
            if ($checkStmt->fetch()) {
                $response['message'] = '類別名稱已被其他類別使用。';
                break;
            }

            // 修正：在 UPDATE 語句中加入 day_type_id 欄位
            $sql = "UPDATE event_category SET category_name = ?, description = ?, color = ?, day_type_id = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            if ($stmt->execute([$name, $description, $color, $day_type_id ?: null, $id])) {
                $response = ['success' => true, 'message' => '類別更新成功。'];
            } else {
                $response['message'] = '資料庫更新失敗。';
            }
            break;

        case 'delete_category':
            $id = $_POST['id'] ?? 0;

            if (empty($id)) {
                $response['message'] = '缺少類別 ID。';
                break;
            }

            $db->beginTransaction();

            // 1. 將使用此類別的事件的 category_id 設為 NULL
            $updateEventsStmt = $db->prepare("UPDATE evenement SET category_id = NULL WHERE category_id = ?");
            $updateEventsStmt->execute([$id]);

            // 2. 刪除類別本身
            $deleteStmt = $db->prepare("DELETE FROM event_category WHERE id = ?");
            
            if ($deleteStmt->execute([$id])) {
                $db->commit();
                $response = ['success' => true, 'message' => '類別刪除成功。'];
            } else {
                $db->rollBack();
                $response['message'] = '資料庫刪除失敗。';
            }
            break;
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $response['message'] = '資料庫錯誤：' . $e->getMessage();
}

echo json_encode($response);
?>

```

### 變更總結

1.  **`calendar.php`**：
    *   在事件類別下拉選單旁新增了一個「+」按鈕。
    *   在頁面底部新增了 ID 為 `categoryModal` 的彈出視窗，用於管理類別。
    *   新增了大量的 jQuery 程式碼，用於：
        *   點擊「+」按鈕時，打開 Modal 並用 AJAX 載入最新的類別列表。
        *   處理 Modal 內的表單提交，可新增或更新類別。
        *   處理列表中的「編輯」與「刪除」按鈕功能。
        *   所有操作成功後，都會自動刷新 Modal 內的列表和主表單的下拉選單，提供無縫的使用體驗。

2.  **`_category_api.php` (新檔案)**：
    *   這是一個全新的後端 API，負責處理所有關於事件類別的請求。
    *   `get_categories`：讀取並回傳所有類別。
    *   `add_category`：新增一個類別，並檢查名稱是否重複。
    *   `update_category`：更新指定類別的資訊。
    *   `delete_category`：刪除一個類別。在刪除前，會先將所有關聯到此類別的行事曆事件的 `category_id` 設為 `NULL`，以避免資料庫關聯出錯。

完成以上檔案的更新與建立後，您的行事曆頁面就具備了即時管理事件類別的功能了。

<!--
[PROMPT_SUGGESTION]如何在行事曆上顯示一個圖例，說明不同顏色代表的事件類別？[/PROMPT_SUGGESTION]
[PROMPT_SUGGESTION]當我刪除一個類別後，如何讓行事曆上使用該類別的事件顏色變回預設？[/PROMPT_SUGGESTION]
-->