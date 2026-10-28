<?php
// _NewOrder_Track.php
session_start();
include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

// 啟用錯誤顯示
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new DBConnection();

// 取得指派設計選項
@$ate_list = $conn->getAll("SELECT `user_cname`,`user_uname`,`id` FROM `user` WHERE `user_status`=63");

// 從 Session 取得表單預設值（新增、複製新增或修改後留存）
@$OrderNo           = isset($_SESSION['OrderNo'])            ? $_SESSION['OrderNo']           : "";
@$orderindate       = isset($_SESSION['orderindate'])        ? $_SESSION['orderindate']       : "";
@$orderDdate        = isset($_SESSION['orderDdate'])         ? $_SESSION['orderDdate']        : "";
@$Client_Name       = isset($_SESSION['Client_Name'])        ? $_SESSION['Client_Name']       : "";
@$Client_OrderNo    = isset($_SESSION['Client_OrderNo'])     ? $_SESSION['Client_OrderNo']    : "";
@$d_id              = isset($_SESSION['d_id'])               ? $_SESSION['d_id']              : "";
@$Process           = isset($_SESSION['Process'])            ? $_SESSION['Process']           : "";
@$Qty               = isset($_SESSION['Qty'])                ? $_SESSION['Qty']               : "";
@$datepicker_ate    = isset($_SESSION['datepicker_ate'])     ? $_SESSION['datepicker_ate']    : "";
@$ate               = isset($_SESSION['ate'])                ? $_SESSION['ate']               : "";
@$drop_zone         = isset($_SESSION['drop_zone'])          ? $_SESSION['drop_zone']         : "";
@$Containers        = isset($_SESSION['Containers'])         ? $_SESSION['Containers']        : "";
@$sample            = isset($_SESSION['sample'])             ? $_SESSION['sample']            : "";
@$jig               = isset($_SESSION['jig'])                ? $_SESSION['jig']               : "";
@$Order_ps          = isset($_SESSION['Order_ps'])           ? $_SESSION['Order_ps']          : "";
// @$ateNote           = isset($_SESSION['ateNote'])            ? $_SESSION['ateNote']           : "";
@$ate= isset($_SESSION['ate']) ? $_SESSION['ate']: "";
@$Order_id          = isset($_SESSION['Order_id'])           ? $_SESSION['Order_id']          : "";

// 取得現有的訂單資料（依排序條件）
@$order_list = $conn->getAll("SELECT 
            order_list.*,
            CONCAT(DATE_FORMAT(order_list.Order_date, '%y'), 'y/', DATE_FORMAT(order_list.Order_date, '%c/%e')) AS Order_date,
            CONCAT(DATE_FORMAT(order_list.Delivery_date, '%y'), 'y/', DATE_FORMAT(order_list.Delivery_date, '%c/%e')) AS Delivery_date_T,
            DATE_FORMAT(order_list.ateGet, '%c/%e') AS ateGet,
            DATE_FORMAT(order_list.pmGet, '%c/%e') AS pmGet,
            user.user_cname
        FROM order_list
        LEFT JOIN user ON user.id = order_list.ate
        ORDER BY order_list.Order_date DESC, order_list.Client_name ASC;
        ");

try {
    if (isset($_POST['or_new']) || isset($_POST['or_new_copy']) || isset($_POST['or_update'])) {
        if (isset($_POST['or_update'])) {
            // ----- 更新操作 -----
            // 先透過 SELECT 取得目前該筆記錄的交期資訊
            $selectSQL = "SELECT Delivery_date, Delivery_date_2 FROM order_list WHERE Order_id = :Order_id";
            $selectStmt = $db->prepare($selectSQL);
            $selectStmt->bindParam(':Order_id', $_POST['Order_id']);
            $selectStmt->execute();
            $row = $selectStmt->fetch(PDO::FETCH_ASSOC);
            $currentDeliveryDate = $row['Delivery_date'];
            $currentDeliveryDate2 = $row['Delivery_date_2'];
  
            if ($_POST['orderDdate'] !== $currentDeliveryDate) {
                // 若新交期與目前交期不同，
                // 設定新交期、並將原交期存入 Delivery_date_2，
                // 如果原 Delivery_date_2 已有值，則將其移至 Delivery_date_3，否則為 NULL
                $newDeliveryDate = $_POST['orderDdate'];
                $newDeliveryDate2 = $currentDeliveryDate;
                $newDeliveryDate3 = !empty($currentDeliveryDate2) ? $currentDeliveryDate2 : NULL;
  
                $sql = "UPDATE `order_list` SET 
                        `Order_oo` = :OrderNo,
                        `d_id` = :d_id,
                        `Specification` = NULL,
                        `Order_ps` = :Order_ps,
                        `Client_name` = :Client_Name,
                        `Qty` = :Qty,
                        `Order_date` = :Order_date,
                        `Delivery_date` = :newDeliveryDate,
                        `Delivery_date_2` = :newDeliveryDate2,
                        `Delivery_date_3` = :newDeliveryDate3,
                        `C_order` = :Client_OrderNo,
                        `Containers` = :Containers,
                        `Sample` = :Sample,
                        `JIG` = :JIG,
                        `Processing_items` = :Processing_items,
                        `ateGet` = :datepicker_ate,
                        `ate` = :ate,
                        -- `ateNote` = :ateNote,
                        `drop_zone` = :drop_zone,
                        Modified_By = :Modified_By,
                        Modified_At = NOW()
                        WHERE `Order_id` = :Order_id";
                $stmt = $db->prepare($sql);
  
                $stmt->bindParam(':OrderNo', $_POST['OrderNo']);
                $stmt->bindParam(':d_id', $_POST['d_id']);
                $stmt->bindParam(':Order_ps', $_POST['Order_ps']);
                // $stmt->bindParam(':ateNote', $_POST['ateNote']);
                $stmt->bindParam(':Client_Name', $_POST['Client_Name']);
                $stmt->bindParam(':Qty', $_POST['Qty']);
                $stmt->bindParam(':Order_date', $_POST['orderindate']);
                $stmt->bindParam(':newDeliveryDate', $newDeliveryDate);
                $stmt->bindParam(':newDeliveryDate2', $newDeliveryDate2);
                $stmt->bindParam(':newDeliveryDate3', $newDeliveryDate3);
                $stmt->bindParam(':Client_OrderNo', $_POST['Client_OrderNo']);
                $stmt->bindParam(':Containers', $_POST['Containers']);
                $stmt->bindParam(':Sample', $_POST['sample']);
                $stmt->bindParam(':JIG', $_POST['jig']);
                $stmt->bindParam(':Processing_items', $_POST['Process']);
                $stmt->bindParam(':ate', $_POST['ate']);
                $stmt->bindParam(':Order_id', $_POST['Order_id']);
                $stmt->bindParam(':datepicker_ate', $_POST['datepicker_ate']);
                $stmt->bindParam(':Modified_By', $_SESSION['id']);
                $stmt->bindParam(':drop_zone', $_POST['drop_zone']);
  
                $stmt->execute();
  
                // 清除更新後的 SESSION 相關資料
                unset($_SESSION['Order_id']);
                unset($_SESSION['orderindate']);
                unset($_SESSION['OrderNo']);
                unset($_SESSION['orderDdate']);
                unset($_SESSION['Client_Name']);
                unset($_SESSION['Client_OrderNo']);
                unset($_SESSION['d_id']);
                unset($_SESSION['Process']);
                unset($_SESSION['Qty']);
                unset($_SESSION['datepicker_ate']);
                unset($_SESSION['ate']);
                unset($_SESSION['ate']);
                unset($_SESSION['drop_zone']);
                unset($_SESSION['Containers']);
                unset($_SESSION['sample']);
                unset($_SESSION['jig']);
                unset($_SESSION['Order_ps']);
                // unset($_SESSION['ateNote']);
  
                header("Location: ../../views/Sales/NewOrder_List.php?message=success");
                exit;
            } else {
                // 如果新的 orderDdate 與現有 Delivery_date 相同，則不處理交期的變動
                $sql = "UPDATE `order_list` SET 
                        `Order_oo` = :OrderNo,
                        `d_id` = :d_id,
                        `Specification` = NULL,
                        `Order_ps` = :Order_ps,
                        `Client_name` = :Client_Name,
                        `Qty` = :Qty,
                        `Order_date` = :Order_date,
                        `Delivery_date` = :Delivery_date,
                        `Delivery_date_2` = NULL,
                        `Delivery_date_3` = NULL,
                        `C_order` = :Client_OrderNo,
                        `Containers` = :Containers,
                        `Sample` = :Sample,
                        `JIG` = :JIG,
                        `Processing_items` = :Processing_items,
                        `ateGet` = :datepicker_ate,
                        `ate` = :ate,
                        -- `ateNote` = :ateNote,
                        `drop_zone` = :drop_zone,
                        Modified_By = :Modified_By,
                        Modified_At = NOW()
                        WHERE `Order_id` = :Order_id";
                $stmt = $db->prepare($sql);
  
                $stmt->bindParam(':OrderNo', $_POST['OrderNo']);
                $stmt->bindParam(':d_id', $_POST['d_id']);
                $stmt->bindParam(':Order_ps', $_POST['Order_ps']);
                $stmt->bindParam(':Client_Name', $_POST['Client_Name']);
                $stmt->bindParam(':Qty', $_POST['Qty']);
                $stmt->bindParam(':Order_date', $_POST['orderindate']);
                $stmt->bindParam(':Delivery_date', $_POST['orderDdate']);
                $stmt->bindParam(':datepicker_ate', $_POST['datepicker_ate']);
                $stmt->bindParam(':Client_OrderNo', $_POST['Client_OrderNo']);
                $stmt->bindParam(':Containers', $_POST['Containers']);
                $stmt->bindParam(':Sample', $_POST['sample']);
                $stmt->bindParam(':JIG', $_POST['jig']);
                $stmt->bindParam(':Processing_items', $_POST['Process']);
                $stmt->bindParam(':ate', $_POST['ate']);
                // $stmt->bindParam(':ateNote', $_POST['ateNote']);
                $stmt->bindParam(':Order_id', $_POST['Order_id']);
                $stmt->bindParam(':Modified_By', $_SESSION['id']);
                $stmt->bindParam(':drop_zone', $_POST['drop_zone']);
  
                $stmt->execute();
  
                header("Location: ../../views/Sales/NewOrder_List.php?message=success");
                exit;
            }
        } else {
            // ----- 新增 / 複製新增操作 -----
            $sql = "INSERT INTO `order_list` SET
                Order_id = NULL,
                `Order_oo` = :OrderNo,
                `d_id` = :d_id,
                `Specification` = NULL,
                `Order_ps` = :Order_ps,
                `Client_name` = :Client_Name,
                `Qty` = :Qty,
                `Order_date` = :Order_date,
                `Delivery_date` = :Delivery_date,
                `Delivery_date_2` = NULL,
                `Delivery_date_3` = NULL,
                `C_order` = :Client_OrderNo,
                `Containers` = :Containers,
                `Sample` = :Sample,
                `JIG` = :JIG,
                `Processing_items` = :Processing_items,
                `ate` = :ate,
                `ateGet` = :datepicker_ate,
                `drop_zone` = :drop_zone,
                `Order_status` = NULL,
                `Created_At` = NOW(),
                `Created_By` = :Created_By";
  
            $stmt = $db->prepare($sql);
  
            $stmt->bindParam(':OrderNo', $_POST['OrderNo']);
            $stmt->bindParam(':d_id', $_POST['d_id']);
            $stmt->bindParam(':Client_Name', $_POST['Client_Name']);
            $stmt->bindParam(':Qty', $_POST['Qty']);
            $stmt->bindParam(':Order_date', $_POST['orderindate']);
            $stmt->bindParam(':Delivery_date', $_POST['orderDdate']);
            $stmt->bindParam(':datepicker_ate', $_POST['datepicker_ate']);
            $stmt->bindParam(':Client_OrderNo', $_POST['Client_OrderNo']);
            $stmt->bindParam(':Containers', $_POST['Containers']);
            $stmt->bindParam(':Sample', $_POST['sample']);
            $stmt->bindParam(':JIG', $_POST['jig']);
            $stmt->bindParam(':Processing_items', $_POST['Process']);
            $stmt->bindParam(':ate', $_POST['ate']);
            $stmt->bindParam(':drop_zone', $_POST['drop_zone']);
            $stmt->bindParam(':Order_ps', $_POST['Order_ps']);
            $stmt->bindParam(':Created_By', $_SESSION['id']);
  
            $stmt->execute();
  
            if (isset($_POST['or_new_copy'])) {
                $_SESSION['OrderNo']            = $_POST['OrderNo'];
                $_SESSION['orderindate']        = $_POST['orderindate'];
                $_SESSION['orderDdate']         = $_POST['orderDdate'];
                $_SESSION['Client_Name']        = $_POST['Client_Name'];
                $_SESSION['Client_OrderNo']     = $_POST['Client_OrderNo'];
                $_SESSION['d_id']               = $_POST['d_id'];
                $_SESSION['Process']            = $_POST['Process'];
                $_SESSION['Qty']                = $_POST['Qty'];
                $_SESSION['datepicker_ate']     = $_POST['datepicker_ate'];
                $_SESSION['ate'] = $_POST['ate'];
                $_SESSION['ate']                = $_POST['ate'];
                $_SESSION['drop_zone']          = $_POST['drop_zone'];
                $_SESSION['Containers']         = $_POST['Containers'];
                $_SESSION['sample']             = $_POST['sample'];
                $_SESSION['jig']                = $_POST['jig'];
                $_SESSION['Order_ps']           = $_POST['Order_ps'];
                $_SESSION['ateNote']            = $_POST['ateNote'];
  
                header("Location: ../../views/Sales/NewOrder_List.php?message=success&mode=copy");
                exit;
            } else {
                unset($_SESSION['orderindate']);
                unset($_SESSION['OrderNo']);
                unset($_SESSION['orderDdate']);
                unset($_SESSION['Client_Name']);
                unset($_SESSION['Client_OrderNo']);
                unset($_SESSION['d_id']);
                unset($_SESSION['Process']);
                unset($_SESSION['Qty']);
                unset($_SESSION['datepicker_ate']);
                unset($_SESSION['ate']);
                unset($_SESSION['ate']);
                unset($_SESSION['drop_zone']);
                unset($_SESSION['Containers']);
                unset($_SESSION['sample']);
                unset($_SESSION['jig']);
                unset($_SESSION['Order_ps']);
                // unset($_SESSION['ateNote']);
                header("Location: ../../views/Sales/NewOrder_List.php?message=success");
                exit;
            }
        }
    }
  
    if (isset($_POST['resetpSetting'])) {
        unset($_SESSION);
        header("Location: ../../views/Sales/NewOrder_List.php");
        exit;
    }

    if (isset($_POST['del_order_track'])) {
        $del_oreder_track = $conn->execute("DELETE FROM `order_list` WHERE `Order_id`='$_POST[Order_id]'");
        // unset($_SESSION);
        header("Location: ../../views/Sales/NewOrder_List.php?message=刪除完成");
        exit;
    }
} catch (PDOException $e) {
    if (isset($sql)) {
       echo "資料庫錯誤：" . $e->getMessage() . "<br>SQL: " . $sql;
    } else {
       echo "資料庫錯誤：" . $e->getMessage();
    }
    exit;
} catch (Exception $e) {
    echo "一般錯誤：" . $e->getMessage();
    exit;
}


?>
