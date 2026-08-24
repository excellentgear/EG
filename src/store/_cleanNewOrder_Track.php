<?php
 if (!isset($_SESSION)){
    session_start();
    }

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
                unset($_SESSION['ateNote']);
        // 2026-08-24 NewOrder_List.php（未交訂單，讀舊表 order_list、欄位早已不存在故長期 500）已移除，
        // 這裡改導向仍在使用的訂單追蹤頁，避免本檔變成指向 404 的死連結。
        header("Location: ../../views/Sales/NewOrder_Track.php");
        exit;
?>