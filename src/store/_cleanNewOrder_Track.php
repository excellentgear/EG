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
        header("Location: ../../views/Sales/NewOrder_List.php");
        exit;
?>