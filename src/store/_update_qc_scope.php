<?php
 if (!isset($_SESSION)){
    session_start();
    }
    //2024.03.21 OK

    include("../../src/common/_config.php"); 

    $cmd = $db->prepare("SELECT qc_scope.QC_Tool,qc_scope.QC_List_No,qc_scope.QC_Id,qc_scope.QC_Scope_id,qc_scope.QC_Scope,qc_scope.QC_S_Scope,qc_scope.Upper_Limit,qc_scope.Lower_Limit FROM `qc_scope` LEFT JOIN `qc_tool_list` ON qc_scope.QC_Tool=qc_tool_list.QC_Tool_List_id WHERE `QC_Scope_id`=$_GET[QC_Scope_id] AND qc_scope.QC_Tool=qc_tool_list.QC_Tool_List_id order by QC_List_No");
    $cmd->execute();
    $row = $cmd->fetch();
    $_SESSION['QC_Scope_id']=$row['QC_Scope_id'];

    if(isset($_SESSION['QC_Scope_id'])){
      $_SESSION['QC_List_No']=$row['QC_List_No'];
      $_SESSION['QC_Tool']=$row['QC_Tool'];
      $_SESSION['QC_Scope']=$row['QC_Scope'];
      $_SESSION['QC_S_Scope']=$row['QC_S_Scope'];
      $_SESSION['Upper_Limit']=$row['Upper_Limit'];
      $_SESSION['Lower_Limit']=$row['Lower_Limit'];

    } else {
      $cmd = $db->prepare("SELECT qc_scope.QC_Tool,qc_scope.QC_List_No,qc_scope.QC_Id,qc_scope.QC_Scope_id,qc_scope.QC_Scope,qc_scope.QC_S_Scope,qc_scope.Upper_Limit,qc_scope.Lower_Limit FROM `qc_scope` WHERE `QC_Scope_id`=$_GET[QC_Scope_id] order by QC_List_No");
      $cmd->execute();
      $row = $cmd->fetch();
    };


    $_SESSION['QC_Scope_id']=$row['QC_Scope_id'];
    $_SESSION['QC_List_No']=$row['QC_List_No'];
    $_SESSION['QC_Tool']=$row['QC_Tool'];
    $_SESSION['QC_Scope']=$row['QC_Scope'];
    $_SESSION['QC_S_Scope']=$row['QC_S_Scope'];
    $_SESSION['Upper_Limit']=$row['Upper_Limit'];
    $_SESSION['Lower_Limit']=$row['Lower_Limit'];


   //  $conn = new DBConnection(); 
   //  $QC = $conn->execute("UPDATE qc_scope SET QC_Scope='$QC_Scope',QC_S_Scope=$QC_S_Scope,
   //  Upper_Limit='$Upper_Limit',Lower_Limit='$Lower_Limit',Modified_By='$_SESSION[id]' 
   //  WHERE `QC_Scope_id`=".$_POST['QC_Scope_id']);

   $_SESSION['opne_qc_setting_right']="OK"; //右欄顯示
    header("location:../../views/QC/QC_Setting.php");


   