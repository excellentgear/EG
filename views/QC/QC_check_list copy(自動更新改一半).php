<?php
session_start();

if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    $_SESSION['lastpage'] = "../../views/reply/reply_other.php?BOM=" . $_GET['BOM'] . "&d_id=" . $_GET['d_id'] . "&ProcessNo=" . $_GET['ProcessNo'] . "&sqty=" . $_GET['sqty'] . "&D=" . $_GET['D'] . "&C=" . $_GET['C'] . "&m=" . $_GET['m'];
    header("Location:../../index.php?BOM=" . $_GET['BOM'] . "&d_id=" . $_GET['d_id'] . "&ProcessNo=" . $_GET['ProcessNo'] . "&sqty=" . $_GET['sqty'] . "&D=" . $_GET['D'] . "&C=" . $_GET['C'] . "&m=" . $_GET['m']); //返回登入頁
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$id = $_GET['id'];
@$new = $_GET['new'];

@$userName      = $_SESSION['user_cname'];
@$id            = $_SESSION['id'];
@$user_status   = $_SESSION['status'];

@$BOM           = $_GET['BOM'];
@$ProcessNo     = $_GET['ProcessNo'];
@$sqty          = $_GET['sqty'];
@$Client_Name   = $_GET['C'];
@$MakerId       = $_GET['MakerId'];

@$bom           = $_GET['bom'];
@$outsource_date = $_GET['outsource_date'];
@$ProcessName   = $_GET['pna'];
@$bom_ing_id    = $_GET['bi'];
@$d_id          = $_GET['d'];
@$replydate     = $_GET['rd']; // 更新日期

// 更新報工紀錄
// @$oready_sqty = $_SESSION['oready_sqty'];
// @$ok_sqty     = $_SESSION['ok_sqty'];
// @$ng_sqty     = $_SESSION['ng_sqty'];
// @$ng_id       = $_SESSION['ng_id'];
// @$ng_sqty2    = $_SESSION['ng_sqty2'];
// @$ng_id2      = $_SESSION['ng_id2'];
// @$ng_sqty3    = $_SESSION['ng_sqty3'];
// @$ng_id3      = $_SESSION['ng_id3'];
// @$completed   = $_SESSION['completed'];
// @$Created_By  = $_SESSION['Created_By'];
// @$Created_At  = $_SESSION['Created_At'];


//料號
@$conn = new DBConnection();
//上方左欄使用
// @$ALL = $conn->getAll("SELECT * FROM `QC` ORDER BY QC_Id");

// NG原因
@$conn = new DBConnection();
@$ng_txt_list = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");
@$ng_txt_list2 = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");
@$ng_txt_list3 = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");

// 2=齒研機
@$machine_id_list = $conn->getAll("SELECT `machine_id`,`machine` FROM machine_list WHERE `machine_type_id`=2 ORDER BY `machine_id`");

@$last_sqty = $_SESSION['last_sqty'];

// 待驗清單
@$ALL_Sce = $conn->getAll("SELECT 
  b.processing_state AS b_processing_state,
  b.bom,
  b.d_id,
  DATE_FORMAT(bi.outsource_date, '%m/%d') AS outsource_date,
  pn.process_type_id,
  bi.processing_state,
  DATE_FORMAT(bi.return_date, '%m/%d') AS return_date,
  bi.QC_check,
  DATE_FORMAT(bi.QC_check_date, '%m/%d') AS QC_check_date,

  bi.QC_ps        AS BIQC_ps,
  bi.QC_ps2       AS BIQC_ps2,

  qc.QC_check_count,
  qc.QC_ps_qq,
  bi.single_bet_ps,
  bi.QC_ps2 AS QC_ps_ng,
  bi.QC_ps_aod AS QC_ps_aod_remark,
  qc.all_QC_ps_ok AS QC_ps_ok,
  pn.ProcessNo,
  pn.ProcessName,
  pn.process_type_id,
  bi.maker_id,
  bi.sqty,
  b.Client_Name,

  qa.abnormal_order_no,

  qc.QC_QQ_sqty,
  qc.QC_ng_sqty,
  qc.QC_aod_sqty,
  qc.QC_ok_sqty,

  bi.bom_ing_fid,
  bi.ps,
  qc.latest_QQ_date_formatted,
  qc.latest_ok_date_formatted

FROM bom_ing bi

-- ✅ 主條件：每個 bom 取「最新 outsource_date 且 processing_state IN ('Q','P')」中最大 bom_sn
-- ✅ 加上 NOT EXISTS 條件：不能有更晚的 ing/E/P 狀態存在
JOIN (
  SELECT bi1.bom, MAX(bi1.bom_sn) AS max_bom_sn
  FROM bom_ing bi1
  JOIN (
    SELECT bom, MAX(outsource_date) AS max_outsource_date
    FROM bom_ing
    WHERE processing_state IN ('Q', 'P')
    GROUP BY bom
  ) latest_date 
    ON bi1.bom = latest_date.bom 
    AND bi1.outsource_date = latest_date.max_outsource_date
  WHERE bi1.processing_state IN ('Q', 'P')
    AND NOT EXISTS (
      SELECT 1 FROM bom_ing bi2
      WHERE bi2.bom = bi1.bom
        AND bi2.outsource_date > bi1.outsource_date
        AND bi2.processing_state IN ('ing', 'E', 'P')
    )
  GROUP BY bi1.bom
) max_bi ON bi.bom = max_bi.bom AND bi.bom_sn = max_bi.max_bom_sn

JOIN bom b ON bi.bom = b.bom
LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no

-- ✅ QC 資料彙總
LEFT JOIN (
  SELECT
    bom_ing_fid_ref,
    COUNT(*) AS QC_check_count,
    MAX(CASE WHEN QC_check = 'QQ' THEN QC_ps ELSE NULL END) AS QC_ps_qq,
    GROUP_CONCAT(DISTINCT CASE WHEN QC_check = 'ok' THEN QC_ps_ok END SEPARATOR '; ') AS all_QC_ps_ok,
    SUM(CASE WHEN QC_check = 'QQ' THEN QC_QQ_sqty ELSE 0 END) AS QC_QQ_sqty,
    SUM(CASE WHEN QC_check = 'ng' THEN QC_ng_sqty ELSE 0 END) AS QC_ng_sqty,
    SUM(CASE WHEN QC_check = 'AOD' THEN QC_aod_sqty ELSE 0 END) AS QC_aod_sqty,
    SUM(CASE WHEN QC_check = 'ok' THEN QC_ok_sqty ELSE 0 END) AS QC_ok_sqty,
    MAX(qc_check.qc_check_id) AS max_qc_check_id,

    (SELECT DATE_FORMAT(q_inner.QC_check_date, '%m/%d') 
     FROM qc_check q_inner 
     WHERE q_inner.bom_ing_fid_ref = qc_check.bom_ing_fid_ref 
       AND q_inner.QC_check = 'QQ'
       AND q_inner.QC_check_date IS NOT NULL
     ORDER BY q_inner.QC_check_date DESC, q_inner.qc_check_id DESC LIMIT 1) AS latest_QQ_date_formatted,

    (SELECT DATE_FORMAT(q_inner.QC_check_date, '%m/%d') 
     FROM qc_check q_inner 
     WHERE q_inner.bom_ing_fid_ref = qc_check.bom_ing_fid_ref 
       AND q_inner.QC_check = 'ok'
       AND q_inner.QC_check_date IS NOT NULL
     ORDER BY q_inner.QC_check_date DESC, q_inner.qc_check_id DESC LIMIT 1) AS latest_ok_date_formatted

  FROM qc_check
  GROUP BY bom_ing_fid_ref
) qc ON qc.bom_ing_fid_ref = bi.bom_ing_fid

LEFT JOIN qc_abnormal_order qa ON qa.qc_check_id = qc.max_qc_check_id

-- ✅ 主條件
WHERE 
  b.processing_state IS NULL
  AND bi.processing_state IN ('Q', 'P')

ORDER BY bi.outsource_date;

");

// --- Fetch individual qc_check entries for remarks ---
$all_bom_ing_fids = [];
if (!empty($ALL_Sce)) {
    foreach ($ALL_Sce as $item) {
        if (!empty($item['bom_ing_fid'])) {
            $all_bom_ing_fids[] = $item['bom_ing_fid'];
        }
    }
}

$individual_qc_data_map = [];
if (!empty($all_bom_ing_fids)) {
    $unique_fids = array_unique($all_bom_ing_fids);
    if (!empty($unique_fids)) { // Ensure $unique_fids is not empty before creating placeholders
        $placeholders = implode(',', array_fill(0, count($unique_fids), '?'));
        $sql_individual_qc = "
            SELECT
                qc_check_id,
                bom_ing_fid_ref,
                QC_check,
                QC_QQ_sqty,
                QC_ok_sqty,
                QC_ps,      -- Remark for QQ
                QC_ps_ok,   -- Remark for OK
                DATE_FORMAT(QC_check_date, '%m/%d') AS QC_check_date_formatted
            FROM qc_check
            WHERE bom_ing_fid_ref IN ($placeholders)
              AND QC_check IN ('ok', 'QQ')
            ORDER BY bom_ing_fid_ref, QC_check_date DESC, qc_check_id DESC";

        $stmt_individual_qc = $conn->getPDO()->prepare($sql_individual_qc);
        $stmt_individual_qc->execute(array_values($unique_fids)); // Use array_values to ensure numeric keys for execute
        $individual_qc_entries_raw = $stmt_individual_qc->fetchAll(PDO::FETCH_ASSOC);

        foreach ($individual_qc_entries_raw as $entry) {
            $individual_qc_data_map[$entry['bom_ing_fid_ref']][] = $entry;
        }
    }
}

// --- Logic for Dynamic Filter Buttons ---
// 1. Collect unique process_type_id values present in the current $ALL_Sce data
$present_pti_ids = [];
if (!empty($ALL_Sce)) {
    foreach ($ALL_Sce as $item) {
        if (isset($item['process_type_id']) && !is_null($item['process_type_id']) && $item['process_type_id'] !== '') {
            $present_pti_ids[] = (string)$item['process_type_id']; // Ensure string type for comparison
        }
    }
    $present_pti_ids = array_unique($present_pti_ids);

    // Add individual_qc_entries to each item in $ALL_Sce
    foreach ($ALL_Sce as $key => $item) {
        if (!empty($item['bom_ing_fid']) && isset($individual_qc_data_map[$item['bom_ing_fid']])) {
            $ALL_Sce[$key]['individual_qc_entries'] = $individual_qc_data_map[$item['bom_ing_fid']];
        } else {
            $ALL_Sce[$key]['individual_qc_entries'] = []; // Ensure the key exists, even if empty
        }
    }
}

// 2. Define all possible filter buttons: id => label
$all_process_types_map = [
    '138' => '客供料',
    '1'   => '車床',
    '2'   => '銑床',
    '3'   => '拉串、拉(插)栓槽',
    '4'   => '滾(插、切)齒',
    '66'  => '滾(研)栓槽',
    '59'  => '倒圓(尖)角',
    '7'   => '熱處理',
    '8'   => '線割',
    '10'  => '平研',
    '11'  => '外研',
    '911' => '孔外研',
    '9'   => '孔平研',
    '33'  => '研磨',
    '164' => '回客戶',
    '12'  => '齒研',
    '16'  => '雷刻與包裝',
    '189' => '其他製程',
    '202' => '全製'
];

// 3. Define the desired order of buttons and separator points
$button_order = [
    '138',
    '1',
    '2',
    '3',
    '4',
    '66',
    '59',
    '7', // Group 1, separator after '7'
    '8',
    '10',
    '11',
    '911',
    '9',
    '33',
    '164',
    '12',
    '16',        // Group 2, separator after '16'
    '189',
    '202'
];
// --- End of Logic for Dynamic Filter Buttons ---

@$OreadyReply_list = $conn->getAll("SELECT `BOM`,`Client_Name`,`sqty`,`oready_sqty`,`ProcessName`,`ProcessNo`,`MakerId`,
date(`Created_At`) as Created_At_s,`ok_sqty`,`ng_sqty_total`,`ps` FROM vw_oreadyreply_list ORDER BY Created_At DESC");


@$reply_id = $_GET['ri'];


@$ri = $conn->getAll("SELECT vw_oreadyreply_list.reply_id,vw_oreadyreply_list.BOM,vw_oreadyreply_list.oready_sqty,
                        date(vw_oreadyreply_list.Created_At) as Created_date,vw_oreadyreply_list.Created_At as Created_date_ORDER,vw_oreadyreply_list.Created_By,
                        vw_oreadyreply_list.ok_sqty,vw_oreadyreply_list.ng_sqty_total,user.user_cname,vw_oreadyreply_list.ps,
                        vw_oreadyreply_list.m,vw_oreadyreply_list.t,vw_oreadyreply_list.width,vw_oreadyreply_list.mc_id,vw_oreadyreply_list.mc_time,
                        vw_oreadyreply_list.processing_time,machine_list.machine,vw_oreadyreply_list.mc_user,vw_oreadyreply_list.sqty,                     vw_oreadyreply_list.oready_sqty,vw_oreadyreply_list.ProcessName,vw_oreadyreply_list.ProcessNo,vw_oreadyreply_list.MakerId,
                        reply.ng_sqty,reply.ng_id,reply.ng_sqty2,reply.ng_id2,reply.ng_sqty3,reply.ng_id3
                        FROM vw_oreadyreply_list
                        LEFT JOIN user ON user.id=vw_oreadyreply_list.Created_By
                        LEFT JOIN machine_list ON vw_oreadyreply_list.machine_id=machine_list.machine_id
                        LEFT JOIN reply ON reply.reply_id=vw_oreadyreply_list.reply_id
                        WHERE vw_oreadyreply_list.reply_id='$reply_id'
                        ORDER BY Created_date_ORDER DESC");

if ($reply_id != "") {
    foreach ($ri as $ri) {
        @$oready_sqty = $ri['oready_sqty'];
        @$ok_sqty     = $ri['ok_sqty'];
        @$NG          = $ri['ng_sqty'];
        @$ng_txt_id   = $ri['ng_id'];
        @$NG2         = $ri['ng_sqty2'];
        @$ng_txt_id2  = $ri['ng_id2'];
        @$NG3         = $ri['ng_sqty3'];
        @$ng_txt_id3  = $ri['ng_id3'];
        @$ps          = $ri['ps'];
        @$completed   = $ri['completed'];
        @$Created_By  = $ri['Created_By'];
        @$Created_At  = $ri['Created_At'];
    }
};


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        /*=== 只要這兩段就可以水平排列 ===*/
        .qc-flex {
            display: flex;
            align-items: center;
        }

        /* 既有的三種圓形 */
        .circle_red,
        .circle_green,
        .circle_y,
        .circle_gray {
            display: inline-block;
            /* Ensure display is consistent */
            vertical-align: middle;
            width: 20px;
            /* Reverted to original size */
            height: 20px;
            /* Reverted to original size */
            border-radius: 50%;
            margin-right: 6px;
        }

        /* 原本的紅、綠、黃 */
        .circle_red {
            background: radial-gradient(circle, #cd5c5c 30%, #a94442 100%);
        }

        .circle_green {
            background: radial-gradient(circle, MediumSeaGreen 30%, seagreen 100%);
        }

        .circle_y {
            background: radial-gradient(circle, #FFD306 30%, #d1a800 100%);
        }

        .circle_gray {
            background: radial-gradient(circle, #C0C0C0 30%, #A0A0A0 100%);
        }

        /* Changed to Yellow Circle for 'QQ' status to match btn-warning */
        .circle_orange {
            display: inline-block;
            vertical-align: middle;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: radial-gradient(circle, #FFD306 30%, #d1a800 100%);
            /* Yellow gradient (matching btn-warning) */
            margin-right: 6px;
        }

        .circle_gray {
            display: inline-block;
            vertical-align: middle;
            width: 20px;
            /* Reverted to original size */
            height: 20px;
            /* Reverted to original size */
            border-radius: 50%;
            background: radial-gradient(circle, #C0C0C0 30%, #A0A0A0 100%);
            /* Silver/Gray gradient */
            margin-right: 6px;
        }

        .btn-copy {
            margin-right: 5px;
            background-color: #f0ad4e;
            /* Yellow background, matches btn-warning */
            color: white;
            /* White icon/text, matches btn-warning */
            border: none;
            /* As per OreadyReply_ForPm_BaseOfTime.php */
            padding: 1px 2px;
            /* As per OreadyReply_ForPm_BaseOfTime.php */
            vertical-align: middle;
            /* As per OreadyReply_ForPm_BaseOfTime.php */
            cursor: pointer;
            /* As per OreadyReply_ForPm_BaseOfTime.php */
            border-radius: 3px;
            /* Added for button feel, common with btn-xs */
            display: inline-block;
            /* Added for padding to work correctly and button feel */
            font-size: 0.9em;
            /* Consistent with previous icon styling */
            line-height: 1.42857143;
            /* Default Bootstrap line-height for buttons */
            text-align: center;
        }

        .btn-copy:hover {
            background-color: #ec971f;
            /* Darker yellow, matches btn-warning:hover */
            color: white;
        }

        .btn-copy.fa-check {
            /* Style for when it's a checkmark */
            background-color: #f0ad4e;
            /* Bootstrap success background */
            color: white;
            /* border: none; /* border is already none */
        }

        /* --- Filter Buttons Styling for Wrapping --- */
        .title_filters {
            display: flex;
            /* Enable flexbox layout */
            flex-wrap: wrap;
            /* Allow items to wrap to the next line */
            gap: 5px;
            /* Optional: Adds a small space between buttons */
            margin-top: 10px;
            /* Optional: Adds some space above the button group */
            align-items: center;
            /* Vertically align items if they wrap and have different heights */
        }

        .cell-part-number {
            white-space: nowrap;
            /* Prevents text wrapping for long part numbers */
        }

        .cell-auto-width {
            white-space: nowrap;
            /* Prevents text wrapping and allows auto-width */
        }

        /* Ensure abnormal quantity input and textarea have similar initial height */
        .abnormal-entry-row .form-control {
            height: auto;
            /* Allow natural height based on content or rows attribute */
            min-height: 34px;
            /* Bootstrap's default input height */
        }

        /* General rule for all data cells in the table body to prevent wrapping */
        #datatable-buttons tbody td {
            white-space: nowrap;
        }

        /* Specific rule for the "備註" (Remarks) column data cells to allow wrapping */
        /* "備註" is the 9th VISIBLE data column in the tbody, corresponding to data index 9 */
        #datatable-buttons tbody td:nth-child(9) {
            /* Targets the 9th rendered TD in each TBODY row */
            white-space: normal;
            /* Allow text wrapping for this column */
            /* word-break: break-word; */
            /* Optional: if you have long unbreakable strings */
        }


        /* QC Check Color Filter Button Styles (adapted from bomColorFilter) */
        #qcCheckColorFilter {
            position: relative;
            width: 25px;
            /* Increased diameter to 25px */
            height: 25px;
            /* Increased diameter to 25px */
            padding: 0;
            border-radius: 50%;
            /* Circular button */
            display: flex;
            /* For centering content inside */
            justify-content: center;
            align-items: center;
            background-color: #337ab7;
            /* Match other filter buttons background */
            color: #fff;
            /* Match other filter buttons text color */
            cursor: pointer;
            font-size: 10px;
            /* Match OreadyReply_ForPm_BaseOfTime.php #bomColorFilter font-size */
            vertical-align: middle;
            /* Align with other buttons */
            border: 1px solid #2e6da4;
            /* Match other filter buttons border */
            /* margin-left: 5px; Add some space from the previous button */
        }

        #qcCheckColorFilter #qcCheckColorContent {
            line-height: 1;
        }

        /* Ensure text/icon is centered vertically */
    </style>
    <style>
        .qr-modal-label {
            text-align: right;
            padding-top: 7px;
            padding-right: 5px;
            margin-bottom: 0;
            /* Adjusted padding-right */
        }

        /* New CSS for centering the controls row */
        .qr-modal-centered-form-group {
            text-align: center;
            /* This will center the inline-block .row */
        }

        .qr-modal-centered-form-group>.row.qr-modal-controls-row {
            display: inline-block;
            /* Make the row itself behave as an inline element for centering */
        }

        .qr-modal-input-group {
            padding-left: 5px;
            padding-right: 5px;
        }


        .container-cell {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        /* 保留薄藍底與圓角，但移除點擊效果，保持游標預設 */
        .container-btn {
            background-color: #e2f1fb;
            color: #1868ae;
            border: none;
            border-radius: 4px;
            padding: 0.08em 0.7em;
            font-size: 0.95em;
            font-weight: 600;
            display: inline-block;
            margin-right: 4px;
            margin-bottom: 2px;
            cursor: default;
            /* 預設游標，不顯示手型 */
            pointer-events: none;
            /* 禁用點擊事件 */
        }
    </style>
<style>
    #select-bom-ing {
        font-family: 'Courier New', Courier, monospace, "微軟正黑體"; /* 使用等寬字體對齊 */
    }
</style>

    <title>QC 待驗</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">


    <!-- Datatables -->
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/scroller.bootstrap.css" rel="stylesheet">
    <!-- 過長表格變+號 -->
    <!-- <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet"> -->
    <!-- <link href="../../resource/css/responsive.bootstrap.css" rel="stylesheet"> -->
    <!-- 日期選單用 -->
    <link rel="stylesheet" href="http://code.jquery.com/ui/1.11.4/themes/smoothness/jquery-ui.css">

</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">

            <!-- side and top bar include -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>
            <!-- /side and top bar include -->

            <!-- page content -->
            <div class="right_col" role="main">
                <div class="">

                    <div class="page-title">
                        <div class="title_left">
                            <h4>
                                <?php
                                if (!empty($_GET['message'])) {
                                    if ($_GET['message'] == "success") {
                                        echo "<div class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    報工成功
                                    </div>";
                                    } else if ($_GET['message'] == "updatesuccess") {
                                        echo "<div class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    更新成功
                                    </div>";
                                    } else if ($_GET['message'] == "del") {
                                        echo "<div class=\"alert alert-danger fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    已刪除紀錄
                                    </div>";
                                    } else if ($_GET['message'] != "success") {
                                        $var = $_GET['message'];
                                        echo "<div class=\"alert alert-danger fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    $var
                                    </div>";
                                    }
                                }
                                ?>
                            </h4>
                            <!-- <h3>Event <small>Live</small></h3> -->
                        </div>
                        <div class="clearfix"></div>
                    </div>

                    <!-- 料號總覽 -->
                        <div class="row">
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel" id="qc-check-list-panel">
                                    <div class="x_title">
                                        <h2>待驗清單
                                            <button type="button" id="btn-add-custom" class="btn btn-xs btn-primary" data-toggle="modal" data-target="#myModal_reply_custom">
                                                新增(未在列表上)
                                            </button>
                                            <div class="title_filters">
                                                <!-- QC Check Status Filter Button - Styled like bomColorFilter -->
                                                <button type="button" id="qcCheckColorFilter" onclick="toggleQcCheckFilter()">
                                                    <span id="qcCheckColorContent">All</span> <!-- Initial text, will be replaced by JS -->
                                                    <div class="tooltip">
                                                        <div class="tooltip-content">
                                                            <div class="control-label">
                                                                <div><span class="circle_gray" style="width:15px; height:15px; margin-right:3px;"></span><span>待驗 (空值)</span></div>
                                                            </div>
                                                            <!-- <div class="control-label"><div><span class="circle_y" style="width:15px; height:15px; margin-right:3px;"></span><span>特採 (AOD)</span></div></div> -->
                                                            <!-- <div class="control-label"><div><span class="circle_red" style="width:15px; height:15px; margin-right:3px;"></span><span>驗退 (ng)</span></div></div> -->
                                                            <div class="control-label">
                                                                <div><span class="circle_y" style="width:15px; height:15px; margin-right:3px;"></span><span>異常 (QQ)</span></div>
                                                            </div>
                                                            <div class="control-label">
                                                                <div><span class="circle_green" style="width:15px; height:15px; margin-right:3px;"></span><span>允收 (ok)</span></div>
                                                            </div>
                                                        </div>
                                                </button>
                                                <!-- <span style="font-size: 15px; color: #333;">篩選檢驗狀態</span> -->
                                                <?php
                                                $buttons_to_render_count = 0;
                                                foreach ($button_order as $pti_id_in_order) {
                                                    if (in_array($pti_id_in_order, $present_pti_ids) && isset($all_process_types_map[$pti_id_in_order])) {
                                                        $pti_label = $all_process_types_map[$pti_id_in_order];
                                                        echo '<a><input type="button" class="btn btn-xs btn-primary" value="' . htmlspecialchars($pti_label, ENT_QUOTES, 'UTF-8') . '" onclick="filterByPTI(\'' . htmlspecialchars($pti_id_in_order, ENT_QUOTES, 'UTF-8') . '\')"></a>';

                                                        // Separator logic
                                                        if ($pti_id_in_order == '7' || $pti_id_in_order == '16') {
                                                            // Check if there are more visible buttons to render after this one in the defined order
                                                            $is_last_relevant_button_before_potential_next_group = true;
                                                            $current_index_in_button_order = array_search($pti_id_in_order, $button_order);
                                                            if ($current_index_in_button_order !== false) {
                                                                for ($k = $current_index_in_button_order + 1; $k < count($button_order); $k++) {
                                                                    if (in_array($button_order[$k], $present_pti_ids) && isset($all_process_types_map[$button_order[$k]])) {
                                                                        $is_last_relevant_button_before_potential_next_group = false;
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                            if (!$is_last_relevant_button_before_potential_next_group) {
                                                                echo '&nbsp;|&nbsp;';
                                                            }
                                                        }
                                                        $buttons_to_render_count++;
                                                    }
                                                }
                                                ?>
                                                <!-- 新增「全部製程」按鈕，點選後取消PTI篩選 -->
                                                <a><input type="button" class="btn btn-xs btn-primary" value="全部製程" onclick="filterByPTI('')"></a>
                                                <a><input type="button" id="cancelBtn" class="btn btn-xs btn-warning" value="取消篩選" onclick="cancelFilters();"></a>
                                            </div>

                                        </h2>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                            </li>
                                            <li><a class="close-link"><i class="fa fa-close"></i></a>
                                            </li>
                                        </ul>
                                        <div class="clearfix"></div>
                                    </div>
                                    <!-- 呈現料號資料   -->
                                    <table id="datatable-buttons" class="table table-striped table-bordered">
                                        <thead>
                                            <tr>
                                                <th hidden="hidden">id</th>
                                                <th width="1%">狀態 / 檢驗</th> <!-- 設定最小寬度，使其緊貼內容 -->
                                                <th width="1%">客戶</th>
                                                <th width="12%">BOM</th> <!-- 稍微加大欄寬 -->
                                                <th width="12%">料號</th> <!-- 稍微加大欄寬 -->
                                                <th width="1%">回廠</th> <!-- 設定最小寬度，使其緊貼內容 -->
                                                <th width="1%">製程</th> <!-- 設定最小寬度，使其緊貼內容 -->
                                                <th width="1%">廠商</th> <!-- 設定最小寬度，使其緊貼內容 -->
                                                <th width="1%">總數</th> <!-- 設定最小寬度，使其緊貼內容 -->
                                                <th width="1%">容器</th>
                                                <th>備註 / [生管備註]</th>
                                                <th width="10%">選項</th> <!-- 設定選項欄寬度 -->
                                                <th hidden>Process Type ID</th> <!-- 隱藏的表頭 -->
                                                <th hidden class="never">QC Check Raw</th> <!-- 新增：用於儲存原始 QC_check 值的隱藏欄位 -->
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- JavaScript 會將表格內容渲染於此 -->
                                        </tbody>
                                    </table>
                                    <!-- Pagination Controls -->
                                    <div id="pagination-controls" class="text-center"></div>
                                    <!-- Container for Modals (to be populated by JavaScript) -->
                                    <div id="modals-container"></div>
                                    <!-- Custom 報工 Modal: 放在 modals-container 或頁面底部(</body> 之前) -->
                                    <div id="myModal_reply_custom" class="modal fade" role="dialog">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <button type="button" class="close" data-dismiss="modal">×</button>
                                                    <h4 class="modal-title">新增報工（未在列表上）</h4>
                                                </div>
                                                <div class="modal-body">
                                                    <form onsubmit="return false;" class="form-horizontal form-label-center">
                                                        <table class="table table-striped">
                                                            <!-- BOM 查詢 -->
                                                            <tr>
                                                                <td style="width:100px;">BOM查詢 <span class="required">*</span></td>
                                                                <td>
                                                                    <input type="text" name="bom_query" id="input-bom-query" class="form-control input-sm" style="width:150px; display:inline-block; margin-right:5px;" required maxlength="12" pattern="^B-\d{10}$" placeholder="B-1234567890">
                                                                    <button type="button" id="btn-bom-update" class="btn btn-primary btn-sm" style="padding:5px 10px; display:inline-block;">更新</button>
                                                                </td>
                                                            </tr>
                                                            <!-- 客戶 -->
                                                            <tr>
                                                                <td>客戶 <span class="required">*</span></td>
                                                                <td><input name="clientName" type="text" class="form-control input-sm" style="width:120px;" readonly required></td>
                                                            </tr>
                                                            <!-- 料號 -->
                                                            <tr>
                                                                <td>料號 <span class="required">*</span></td>
                                                                <td><input name="dId" type="text" class="form-control" readonly required></td>
                                                            </tr>
                                                            <!-- 製程 -->
                                                            <tr>
                                                                <td>製程 <span class="required">*</span></td>
                                                                <td><select name="bom_ing_fid" id="select-bom-ing" class="form-control input-sm" style="width:auto; display:inline-block;">
                                                                        <option value="">請先更新BOM</option>
                                                                    </select></td>
                                                            </tr>
                                                            <!-- 選項 按鈕 -->
                                                            <tr>
                                                                <td>選項</td>
                                                                <td><button type="button" class="btn btn-warning btn-sm btn-option-abnormal">異常</button> <button type="button" class="btn btn-success btn-sm btn-option-accept">允收</button></td>
                                                            </tr>
                                                        </table>
                                                    </form>
                                                </div>
                                                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                </div>

                <!-- 線圖 -->
                <script src="../../code/highcharts.js"></script>
                <script src="../../code/modules/exporting.js"></script>
                <script src="../../code/modules/export-data.js"></script>
                <script src="../../code/modules/accessibility.js"></script>
                <!-- /page content -->
                <!-- footer content include -->
                <?php include '../partPage/footer.html' ?>
                <!-- /footer content include -->
            </div>
        </div>
    </div>

    <!-- QC Remark Popup -->
    <div id="qcRemarkPopup" style="display:none; 
                                 position:absolute; 
                                 border:1px solid #ccc; 
                                 background-color:white; 
                                 padding: 1em 10px 10px 10px; /* 修改：頂部padding為1em，其他方向10px */
                                 z-index:1050; 
                                 box-shadow: 0 0 10px rgba(0,0,0,0.1); 
                                 min-width: 150px; /* Adjusted: Minimum width */
                                 /* max-width: 300px; Removed: To allow content-driven width */
                                 white-space: pre-wrap; 
                                 word-wrap: break-word;">
        <div id="qcRemarkPopupContent" style="max-height: 150px; /* Adjusted: Max height for content before scroll */
                                           overflow-y: auto;
                                           text-align: left; /* 新增：確保文字靠左對齊 */"></div>
    </div>
    <!-- End QC Remark Popup -->

    <!-- jQuery -->
    <script src="../../resource/js/jquery.min.js"></script>
    <!-- jQuery UI (for Datepicker and other UI widgets) - Moved BEFORE Bootstrap -->
    <script src="http://code.jquery.com/ui/1.11.4/jquery-ui.js"></script>
    <!-- Bootstrap -->
    <script src="../../resource/js/bootstrap.min.js"></script>
    <!-- FastClick -->
    <script src="../../resource/js/fastclick.js"></script>
    <!-- NProgress -->
    <script src="../../resource/js/nprogress.js"></script>
    <!-- iCheck -->
    <script src="../../resource/js/icheck.min.js"></script>
    <!-- Datatables -->
    <script src="../../resource/js/jquery.dataTables.min.js"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js"></script>
    <script src="../../resource/js/dataTables.buttons.min.js"></script>
    <script src="../../resource/js/buttons.bootstrap.min.js"></script>
    <script src="../../resource/js/buttons.flash.min.js"></script>
    <script src="../../resource/js/buttons.html5.min.js"></script>
    <script src="../../resource/js/buttons.print.min.js"></script>
    <script src="../../resource/js/dataTables.fixedHeader.min.js"></script>
    <script src="../../resource/js/dataTables.keyTable.min.js"></script>
    <script src="../../resource/js/dataTables.responsive.min.js"></script>
    <script src="../../resource/js/responsive.bootstrap.js"></script>
    <script src="../../resource/js/dataTables.scroller.min.js"></script>
    <script src="../../resource/js/jszip.min.js"></script>
    <script src="../../resource/js/pdfmake.min.js"></script>
    <script src="../../resource/js/vfs_fonts.js"></script>
    <!-- Custom Theme Scripts -->


    <script>
        console.log('🔧 custom.js 已載入');
    </script>
    <script src="../../resource/js/custom.min.js"></script>

    <!-- Embed PHP data into JavaScript -->
    <script>
        var allRawData = <?php echo json_encode($ALL_Sce ?? []); ?>; // $ALL_Sce from PHP
        var currentUserId = <?php echo json_encode($id ?? null); ?>; // $id from PHP (session user id)
        var currentUserStatus = <?php echo json_encode($user_status ?? null); ?>; // $user_status from PHP

        console.log('[QC_check_list.php] JavaScript loaded and executing.'); // 新增：確認腳本是否載入
        var currentUserStatus = <?php echo json_encode($user_status ?? null); ?>; // $user_status from PHP
        var initialNgTxtList = <?php echo json_encode($ng_txt_list ?? []); ?>; // $ng_txt_list from PHP
    </script>
    <script>
        // Global auto-update control variables
        var autoUpdatePaused = false;
        var autoUpdateIntervalId;
        const AUTO_UPDATE_INTERVAL_MS = 5000; // 5 seconds

        var customBomData = null; // ⭐ 新增：用於暫存「新增」彈窗中查詢的BOM資料
        // Global DataTable instance and column index
        var dataTableInstance;

        /**
         * Checks if any of the specified QC modals are currently open.
         * @returns {boolean} True if a modal is open, false otherwise.
         */
        function isAnyModalOpen() {
            // Check for any modal with an ID starting with myModal_qq_, myModal_ok_, myModal_aod_, myModal_ng_, or myModal_qrcode_
            // that has the 'in' class (Bootstrap 3) or 'show' class (Bootstrap 4/5)
            return $('.modal[id^="myModal_qq_"], .modal[id^="myModal_ok_"], .modal[id^="myModal_aod_"], .modal[id^="myModal_ng_"], .modal[id^="myModal_qrcode_"]').is('.in, .show');
        }

        /**
         * Fetches the latest data from the server and updates the table.
         */
        function fetchAndUpdateData() {
            // Double-check if paused or a modal is open.
            if (autoUpdatePaused || isAnyModalOpen()) {
                return;
            }

            console.log('[Auto-Update] 檢查資料更新...');

            $.ajax({
                url: '../../src/store/_fetch_qc_data.php',
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        // Simple but effective: compare stringified versions of old and new data.
                        if (JSON.stringify(window.allRawData) !== JSON.stringify(response.data)) {
                            console.log('[Auto-Update] 資料已變更，正在更新表格...');

                            // Preserve current state
                            var pageInfo = dataTableInstance.page.info();
                            var currentPage = pageInfo.page;
                            var currentSearch = dataTableInstance.search();
                            var currentOrder = dataTableInstance.order();

                            window.allRawData = response.data; // Update the master data source
                            populateTableWithData(window.allRawData); // This function clears and redraws

                            // Restore state
                            dataTableInstance.search(currentSearch)
                                .order(currentOrder)
                                .page(currentPage)
                                .draw(false); // 'false' to prevent resetting page
                            console.log('[Auto-Update] 表格更新完成，並已恢復先前狀態。');

                        } else {
                            console.log('[Auto-Update] 資料無變更。');
                        }
                    } else {
                        console.error('[Auto-Update] 從後端獲取資料失敗:', response.message || '未知錯誤。');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('[Auto-Update] AJAX 請求失敗:', textStatus, errorThrown);
                }
            });
        }

        const ptiColumnIndex = 12; // Column index for 'process_type_id' (0-based)
        var currentQcCheckFilter = "all"; // 'all', 'gray', 'red', 'yellow', 'green'

        // 彈跳視窗
        function he(str) {
            if (str === null || typeof str === 'undefined') return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function generatePsHtml(item) {
            // --- GEMINI CODE ASSIST: Modify generatePsHtml for remark order ---
            let psHtml = '';
            let qqEntries = [];
            let okEntries = [];

            // 1. Append general bom_ing.ps remark first if it exists
            if (item.ps && item.ps.trim() !== '') {
                psHtml += `<div style="padding: 2px 5px; margin-bottom: 2px;">${he(item.ps.trim())}</div>`;
            }

            if (item.individual_qc_entries && Array.isArray(item.individual_qc_entries) && item.individual_qc_entries.length > 0) {
                // 分離 QQ 和 OK 記錄
                item.individual_qc_entries.forEach(function(entry) {
                    if (entry.QC_check === 'QQ') {
                        qqEntries.push(entry);
                    } else if (entry.QC_check === 'ok') {
                        okEntries.push(entry);
                    }
                });

                // 2. Render QQ (異常) records (already sorted by date DESC from backend)
                // 記錄已經由後端 SQL 依照日期排序 (新的在前)
                qqEntries.forEach(function(check_entry) {
                    var entryDate = he(check_entry.QC_check_date_formatted || check_entry.QC_check_date || '');
                    var entryRemarkText = he(check_entry.QC_ps || ''); // QQ 的備註欄位是 QC_ps
                    var entryQtyValue = parseFloat(check_entry.QC_QQ_sqty || 0);
                    var entryQtyDisplay = he(check_entry.QC_QQ_sqty || '0');
                    var bgColor = '#fff3cd'; // Yellowish for abnormal
                    var textColor = '#856404';

                    if (entryRemarkText.trim() !== '' || entryQtyValue !== 0 || entryQtyDisplay === '0') {
                        let line = '';
                        if (entryDate) {
                            line = entryDate + ' x' + entryQtyDisplay;
                            if (entryRemarkText.trim() !== '') {
                                line += ' ' + entryRemarkText;
                            }
                        } else {
                            if (entryQtyValue !== 0 || entryQtyDisplay === '0') {
                                line = 'x' + entryQtyDisplay;
                                if (entryRemarkText.trim() !== '') {
                                    line += ' ' + entryRemarkText;
                                }
                            } else {
                                line = entryRemarkText;
                            }
                        }
                        if (line.trim()) {
                            psHtml += `<div style="background-color: ${bgColor}; color: ${textColor}; padding: 2px 5px; margin-bottom: 2px; border-radius: 3px;">${line}</div>`;
                        }
                    }
                });

                // 3. Render OK (允收) records (already sorted by date DESC from backend)
                okEntries.forEach(function(check_entry) {
                    var entryDate = he(check_entry.QC_check_date_formatted || check_entry.QC_check_date || '');
                    var entryRemarkText = he(check_entry.QC_ps_ok || '');
                    var entryQtyValue = parseFloat(check_entry.QC_ok_sqty || 0);
                    var entryQtyDisplay = he(check_entry.QC_ok_sqty || '0');
                    var bgColor = '#d4edda';
                    var textColor = '#155724';

                    // ⭐⭐⭐ 新增 chip ⭐⭐⭐
                    let chipStr = '';
                    if (check_entry.QC_ps && check_entry.QC_ps.trim() !== '')
                        chipStr += `<span class="qc-chip qc-chip-blue">${he(check_entry.QC_ps)}</span>`;
                    if (check_entry.QC_ps2 && check_entry.QC_ps2.trim() !== '')
                        chipStr += `<span class="qc-chip qc-chip-blue">${he(check_entry.QC_ps2)}</span>`;

                    let line = '';
                    if (entryDate) {
                        line = entryDate + ' x' + entryQtyDisplay;
                        if (entryRemarkText.trim() !== '') {
                            line += ' ' + entryRemarkText;
                        }
                    } else {
                        if (entryQtyValue !== 0 || entryQtyDisplay === '0') {
                            line = 'x' + entryQtyDisplay;
                            if (entryRemarkText.trim() !== '') {
                                line += ' ' + entryRemarkText;
                            }
                        } else {
                            line = entryRemarkText;
                        }
                    }
                    if (line.trim() || chipStr) {
                        psHtml += `<div style="background-color: ${bgColor}; color: ${textColor}; padding: 2px 5px; margin-bottom: 2px; border-radius: 3px;">
            ${chipStr}${line}
        </div>`;
                    }
                });

            }
            // --- END GEMINI CODE ASSIST: Modify generatePsHtml for remark order ---
            return psHtml;
        }

        function generateStatusHtml(item, primaryResponse) {
            // This function will be defined later, using primaryResponse data
            // For now, the logic inside the success callback handles this directly.
            // It's kept here as a placeholder if you want to centralize it.
            // The current implementation in the success callback is more direct.
            return ""; // Placeholder
        }

        function ShowModal(id) {
            var modal = document.getElementById(id);
            modal.style.display = "block";
        };

        // --- Helper function to update QC_ps button tooltip ---
        // Ensure this function is defined before updateTableRowDOM or globally accessible
        function updateQcPsButton(row, qcPs, qcPs2, qcPsAod) {
            // Target the "料號" cell, which is the 4th visible <td> (index 3)
            var qcNoteCell = row.find('td:eq(3)');
            var qcNoteButton = qcNoteCell.find('button.qc-remark-button'); // Specific class for the button
            var tooltipLines = [];

            var qcPsText = (qcPs && typeof qcPs === 'string') ? qcPs.trim() : '';
            var qcPs2Text = (qcPs2 && typeof qcPs2 === 'string') ? qcPs2.trim() : '';
            var qcPsAodText = (qcPsAod && typeof qcPsAod === 'string') ? qcPsAod.trim() : '';

            if (qcPsAodText !== '') {
                tooltipLines.push("特採：" + he(qcPsAodText));
            }
            if (qcPsText !== '') {
                tooltipLines.push("異常：" + he(qcPsText));
            }
            if (qcPs2Text !== '') {
                tooltipLines.push("驗退：" + he(qcPs2Text));
            }

            var finalTooltipTitle = tooltipLines.join('\n');

            if (finalTooltipTitle !== '') {
                if (qcNoteButton.length === 0) {
                    var $newButton = $('<button type="button" class="btn btn-xs btn-default qc-remark-button" data-toggle="tooltip" data-placement="right"></button>')
                        .attr('title', finalTooltipTitle)
                        .attr('data-remark-content', finalTooltipTitle) // Store for popup
                        .text('QC備註');
                    // Append next to the existing content, typically an <a> tag for d_id
                    qcNoteCell.find('a').first().append(' ').append($newButton);
                    $newButton.tooltip(); // Initialize Bootstrap tooltip
                } else {
                    qcNoteButton.attr('title', finalTooltipTitle).attr('data-remark-content', finalTooltipTitle).tooltip('fixTitle');
                }
            } else {
                if (qcNoteButton.length > 0) {
                    qcNoteButton.tooltip('destroy').remove();
                }
            }
        }

        // --- GEMINI CODE ASSIST: STEP 4 (Revised again for clarity and robustness) ---
        function updateTableRowDOM($targetRow, latestData) {
            if (!$targetRow || $targetRow.length === 0 || !latestData) {
                console.error("updateTableRowDOM: Invalid target row or data.");
                return;
            }

            var bomIngDetails = latestData.bom_ing_details;
            var individualQcEntries = latestData.individual_qc_entries || [];
            var totalQqQty = parseFloat(latestData.total_qq_qty) || 0;
            var totalOkQty = parseFloat(latestData.total_ok_qty) || 0;
            var mainTotalQty = parseFloat(bomIngDetails.sqty) || 0;

            // 1. Update Status Cell (Assume this is the 2nd visible column, index 1 for DataTables)
            var statusHtml = '';
            var qcCheck = bomIngDetails.QC_check ? bomIngDetails.QC_check.trim() : '';
            // Date from bom_ing table, used for 'ng' or 'AOD' states
            var qcCheckDateForOverride = he(bomIngDetails.QC_check_date_formatted || '');

            // Specific latest dates from qc_check table for 'QQ' and 'ok' entries
            var latestQqDateFormatted = he(latestData.latest_QQ_date_formatted || '');
            var latestOkDateFormatted = he(latestData.latest_ok_date_formatted || '');

            var statusParts = [];
            if (qcCheck === "ng") {
                statusHtml = `<div class="qc-flex"><span class="circle_red"></span><small>${qcCheckDateForOverride}</small></div>`;
            } else if (qcCheck === "ok") {
                // ⭐ 新增：當 bom_ing.QC_check 為 'ok' 時，直接顯示綠燈與日期
                statusHtml = `<div class="qc-flex"><span class="circle_green"></span><small>${qcCheckDateForOverride}</small></div>`;
            } else if (qcCheck === "AOD") {
                statusHtml = `<div class="qc-flex"><span class="circle_y"></span><small>${qcCheckDateForOverride}</small></div>`;
            } else {
                var totalCheckedQty = totalQqQty + totalOkQty;
                if (mainTotalQty > 0 && totalCheckedQty >= mainTotalQty) {
                    // Case 1: Fully checked (or over-checked) against a non-zero order quantity
                    if (totalQqQty > 0 && totalOkQty > 0) {
                        statusParts.push(`<span class="circle_y"></span><small>${he(String(totalQqQty))}</small>`);
                        statusParts.push(`<span class="circle_green"></span><small>${he(String(totalOkQty))}</small>`);
                    } else if (totalQqQty > 0) {
                        statusParts.push(`<span class="circle_y"></span><small>${latestQqDateFormatted}</small>`);
                    } else if (totalOkQty > 0) {
                        statusParts.push(`<span class="circle_green"></span><small>${latestOkDateFormatted}</small>`);
                    } else {
                        // Fully checked but no QQ/OK quantities (e.g., mainTotalQty is 0, or data inconsistency)
                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                    }
                } else {
                    // Case 2: Partially checked, OR mainTotalQty is 0, OR no items checked yet
                    if (mainTotalQty > 0 && totalCheckedQty < mainTotalQty && totalCheckedQty > 0) {
                        // Partially checked (some items are checked, but not all of a non-zero order)
                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                    }
                    // Always show QQ and OK quantities if they exist, regardless of partial/full status,
                    // unless it's a fully checked scenario handled above (where dates might be shown instead of qty).
                    if (totalQqQty > 0) {
                        statusParts.push(`<span class="circle_y"></span><small>${he(String(totalQqQty))}</small>`);
                    }
                    if (totalOkQty > 0) {
                        statusParts.push(`<span class="circle_green"></span><small>${he(String(totalOkQty))}</small>`);
                    }
                    // If after all checks, no status parts were added (e.g., 0 order qty, or 0 checked for positive order qty)
                    if (statusParts.length === 0) {
                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                    }
                }
                statusHtml = `<div class="qc-flex">${statusParts.join('&emsp;')}</div>`;
            }
            // Corrected: Status is the 1st visible column (td:eq(0))
            window.dataTableInstance.cell($targetRow.find('td:eq(0)')).data(statusHtml);
            $targetRow.attr('data-qc-check', qcCheck || ''); // Update the raw QC_check status attribute on the TR for filtering

            // 2. 更新容器 Cell (第 10 個可見欄位，DataTables 索引為 9)
            var containerHtml = '<div class="container-cell">';
            // ⭐ 修正點 1: 從 bomIngDetails 中讀取容器資料
            if (bomIngDetails.BIQC_ps && bomIngDetails.BIQC_ps.trim()) {
                containerHtml += `<button type="button" class="container-btn">${he(bomIngDetails.BIQC_ps)}</button>`;
            }
            if (bomIngDetails.BIQC_ps2 && bomIngDetails.BIQC_ps2.trim()) {
                containerHtml += `<button type="button" class="container-btn">${he(bomIngDetails.BIQC_ps2)}</button>`;
            }
            containerHtml += '</div>';
            // ⭐ 修正點 2: 使用正確的索引 9 來更新「容器」欄位
            window.dataTableInstance.cell($targetRow.find('td:eq(8)')).data(containerHtml);

            // 3. 更新備註 Cell (第 11 個可見欄位，DataTables 索引為 10)
            let tempItemForPs = {
                individual_qc_entries: individualQcEntries,
                ps: bomIngDetails.ps // General remark from bom_ing (bom_ing.ps)
            };
            let newPsHtml = generatePsHtml(tempItemForPs);

            // ⭐ 修正點 3: 使用正確的索引 10 來更新「備註」欄位-正確應該是使用欄位9
            window.dataTableInstance.cell($targetRow.find('td:eq(9)')).data(newPsHtml);

            // 4. 更新 QC備註 button tooltip (位於料號 cell)
            if (totalQqQty > 0) {
                $targetRow.attr('data-has-qq', 'true');
            } else {
                $targetRow.removeAttr('data-has-qq');
            }

            if (totalOkQty > 0) {
                $targetRow.attr('data-has-ok', 'true');
            } else {
                $targetRow.removeAttr('data-has-ok');
            }

            $targetRow.removeAttr('data-is-pending'); // Clear first
            if (qcCheck !== 'ng' && qcCheck !== 'AOD') {
                // A row is pending if it's not in a final state and not fully checked, or has 0 total quantity.
                if (mainTotalQty > 0 && totalCheckedQty < mainTotalQty || totalCheckedQty === 0) {
                    $targetRow.attr('data-is-pending', 'true');
                }
            }

            // 3. Update QC備註 button tooltip (located in 料號 cell, 4th visible column, td:eq(3))

            updateQcPsButton($targetRow, bomIngDetails.QC_ps, bomIngDetails.QC_ps2, bomIngDetails.QC_ps_aod);

            // 4. Invalidate DataTables row to re-read from data source and redraw
            window.dataTableInstance.row($targetRow).invalidate('data').draw(false);
        }
        // --- END GEMINI CODE ASSIST: STEP 4 (Revised again for clarity and robustness) ---


        // --- Copy to Clipboard Function ---
        function copyText(text) { // This existing function remains untouched
            if (!navigator.clipboard) {
                // Fallback for older browsers or insecure contexts
                try {
                    var textArea = document.createElement("textarea");
                    textArea.value = text;
                    textArea.style.position = "fixed"; // Prevent scrolling to bottom
                    textArea.style.opacity = 0; // Make it invisible
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    // alert('已複製到剪貼簿 (Fallback): ' + text); // 移除提示
                } catch (err) {
                    console.error('Fallback: Oops, unable to copy', err);
                    alert('複製失敗 (Fallback)!');
                }
                return;
            }
            navigator.clipboard.writeText(text).then(function() {
                // alert('已複製到剪貼簿: ' + text); // 移除提示
            }).catch(function(err) {
                console.error('無法複製文字: ', err);
                alert('複製失敗! 請檢查瀏覽器控制台以獲取更多資訊。\n\n可能是因為頁面不是透過 HTTPS 或 localhost 訪問。');
            });
        }

        // --- New Copy to Clipboard Function with Icon Toggle ---
        function egSystemCopyTextAndToggleIcon(text, buttonElement) {
            if (!buttonElement) {
                console.error("Button element not provided to egSystemCopyTextAndToggleIcon.");
                // Fallback to simple copy if buttonElement is missing, or just copy text
                copyText(text); // Call the original simple copy function
                return;
            }

            var originalIconHtml = buttonElement.innerHTML; // Should be <i class="fa fa-copy"></i>
            buttonElement.innerHTML = '<i class="fa fa-check"></i>'; // Change to checkmark

            var revertIcon = function() {
                if (buttonElement) { // Check again in case button is removed from DOM
                    buttonElement.innerHTML = originalIconHtml;
                }
            };

            if (!navigator.clipboard) {
                try {
                    var textArea = document.createElement("textarea");
                    textArea.value = text;
                    textArea.style.position = "fixed";
                    textArea.style.opacity = 0;
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    setTimeout(revertIcon, 1500); // Revert icon after 1.5 seconds
                } catch (err) {
                    console.error('Fallback: Oops, unable to copy', err);
                    alert('複製失敗 (Fallback)!');
                    revertIcon(); // Revert icon immediately on error
                }
                return;
            }
            navigator.clipboard.writeText(text).then(function() {
                setTimeout(revertIcon, 1500); // Revert icon after 1.5 seconds
            }).catch(function(err) {
                console.error('無法複製文字: ', err);
                alert('複製失敗! 請檢查瀏覽器控制台以獲取更多資訊。\n\n可能是因為頁面不是透過 HTTPS 或 localhost 訪問。');
                revertIcon(); // Revert icon immediately on error
            });
        }



        // --- Filtering Functions ---
        function filterByPTI(ptiValue) {
            // console.log("[filterByPTI] Called. ptiValue: '" + ptiValue + "', Type: " + typeof ptiValue);

            // Ensure DataTable instance is available
            if (!dataTableInstance) {
                if ($.fn.DataTable.isDataTable('#datatable-buttons')) {
                    dataTableInstance = $('#datatable-buttons').DataTable();
                    // console.log("[filterByPTI] DataTable instance acquired.");
                } else {
                    console.error("[filterByPTI] DataTable '#datatable-buttons' not initialized or found. Cannot filter.");
                    // Fallback initialization can be attempted but might not match your site's specific configurations
                    // console.warn("[filterByPTI] Attempting fallback DataTable initialization.");
                    // dataTableInstance = $('#datatable-buttons').DataTable({ responsive: true });
                    // if (!dataTableInstance) {
                    //    console.error("[filterByPTI] Fallback DataTable initialization failed.");
                    //    return;
                    // }
                    return; // It's often better to stop if not pre-initialized as expected.
                }
            }


            var searchTerm = '';
            if (ptiValue === null || ptiValue === undefined || String(ptiValue).trim() === '') {
                searchTerm = ''; // Clear filter for this column if ptiValue is effectively empty
                // console.log("[filterByPTI] Clearing filter for column " + ptiColumnIndex);
            } else {
                // Exact match regex, ensuring ptiValue is treated as a string and trimmed
                var escapedPtiValue = $.fn.dataTable.util.escapeRegex(String(ptiValue).trim());
                searchTerm = '^' + escapedPtiValue + '$';
                console.log("[filterByPTI] Applying regex search term: '" + searchTerm + "' on column " + ptiColumnIndex);
            }

            try {
                dataTableInstance = $('#datatable-buttons').DataTable();
                dataTableInstance.column(ptiColumnIndex)
                    .search(searchTerm, true, false) // true for regex, false for smart search
                    .draw();
                // console.log("[filterByPTI] Table redrawn. Filtered rows count:", dataTableInstance.rows({
                //     search: 'applied'
                // }).count());
            } catch (e) {
                console.error("[filterByPTI] Error applying search to DataTable:", e);
            }
        }

        function toggleQcCheckFilter() {
            const btn = document.getElementById("qcCheckColorFilter");
            const contentSpan = document.getElementById("qcCheckColorContent");
            // Define a specific size for the figure elements inside the button
            const figureStyle = "margin:0;width:25px;height:25px;display:block;"; // Updated figure size

            if (currentQcCheckFilter === "all") {
                currentQcCheckFilter = "gray";
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_gray" style="' + figureStyle + '"></figure>';
            } else if (currentQcCheckFilter === "gray") {
                currentQcCheckFilter = "qq"; // Skip AOD (特採) and RED (驗退), go to QQ (異常)
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_y" style="' + figureStyle + '"></figure>';
            } else if (currentQcCheckFilter === "qq") { // 原本的 yellow 改為 qq
                currentQcCheckFilter = "green";
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_green" style="' + figureStyle + '"></figure>';
            } else { // Was green, now cycles back to all
                currentQcCheckFilter = "all";
                if (contentSpan) contentSpan.innerHTML = 'All'; // Simpler text for "All" state
            }
            if (dataTableInstance) {
                dataTableInstance.draw(); // Trigger DataTables re-draw, which will apply the new filter
            } else {
                console.warn("DataTable instance not found for QC check filter redraw.");
            }
        }


        // Extend DataTables search for QC Check Status
        $.fn.dataTable.ext.search.push(
            function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'datatable-buttons') return true;

                var qcCheckFilterState = window.currentQcCheckFilter;
                if (qcCheckFilterState === "all") {
                    return true;
                }

                var rowNode = settings.aoData[dataIndex].nTr;
                var $rowNode = $(rowNode);

                if (qcCheckFilterState === "gray") {
                    // Show if it has the 'pending' attribute, which means it has a gray circle.
                    return $rowNode.attr('data-is-pending') === 'true';
                } else if (qcCheckFilterState === "qq") {
                    // Show if it has a QQ entry (yellow circle for QQ, not AOD).
                    return $rowNode.attr('data-has-qq') === 'true';
                } else if (qcCheckFilterState === "green") {
                    // Show if it has an OK entry (green circle).
                    return $rowNode.attr('data-has-ok') === 'true';
                }

                return false; // Hide by default if filter is active but no match
            }
        );

        function cancelFilters() {
            // Ensure DataTable instance is available
            if (!dataTableInstance) {
                if ($.fn.DataTable.isDataTable('#datatable-buttons')) {
                    dataTableInstance = $('#datatable-buttons').DataTable();
                } else {
                    console.error("[cancelFilters] DataTable '#datatable-buttons' not initialized or found.");
                    return;
                }
            }

            window.currentQcCheckFilter = "all"; // Reset QC check filter state
            const qcContentSpan = document.getElementById("qcCheckColorContent");
            if (qcContentSpan) qcContentSpan.innerHTML = 'All'; // Reset to "All" text


            if (dataTableInstance) {
                dataTableInstance.column(ptiColumnIndex).search('', true, false); // Clear PTI column filter
                dataTableInstance.search(''); // Clear global search filter
                $('#datatable-buttons_filter input').val(''); // Clear the global search input field's displayed text
                dataTableInstance.draw(); // Redraw the table to apply changes
            } else {
                console.error("DataTable not initialized or not found for cancelling filters.");
            }
        }


        // The old $(document).ready content related to DataTables initialization and specific button handlers
        // will be replaced by the new comprehensive JavaScript logic.

        $(document).ready(function() {
            // =================================================================
            // DEBUG: 確認 ready 事件是否成功觸發
            console.log("Document is ready. Attaching event handlers.");
            // =================================================================
            // General Bootstrap modal cleanup
            $(document).on('hidden.bs.modal', '.modal', function() {
                // Check if there are any other modals currently shown or in the process of showing
                // Bootstrap 3 uses 'in', Bootstrap 4/5 use 'show'
                if ($('.modal.in:visible, .modal.show:visible').length === 0) {
                    $('body').removeClass('modal-open');
                    // Remove any orphaned backdrops if no modals are visible
                    $('.modal-backdrop').remove();
                } else {
                    // If other modals are still open, ensure the body has modal-open.
                    // Bootstrap should handle this, but as a safeguard:
                    if (!$('body').hasClass('modal-open')) {
                        $('body').addClass('modal-open');
                    }
                }

                // Resume auto-update when all modals are hidden
                setTimeout(function() {
                    if (!isAnyModalOpen()) {
                        console.log('[Auto-Update] 所有 Modal 已關閉，恢復自動更新。');
                        autoUpdatePaused = false;
                        // Optional: Immediately run an update check upon resuming
                        fetchAndUpdateData();
                    }
                }, 500); // 500ms delay to ensure modal transitions are complete
            });

            // Datepicker general settings
            $.datepicker.regional["zh-TW"] = {
                closeText: "關閉",
                prevText: "&#x3C;上個月",
                nextText: "下個月&#x3E;",
                currentText: "今天",
                monthNames: ["一月", "二月", "三月", "四月", "五月", "六月", "七月", "八月", "九月", "十月", "十一月", "十二月"],
                monthNamesShort: ["一月", "二月", "三月", "四月", "五月", "六月", "七月", "八月", "九月", "十月", "十一月", "十二月"],
                dayNames: ["星期日", "星期一", "星期二", "星期三", "星期四", "星期五", "星期六"],
                dayNamesShort: ["週日", "週一", "週二", "週三", "週四", "週五", "週六"],
                dayNamesMin: ["日", "一", "二", "三", "四", "五", "六"],
                weekHeader: "週",
                dateFormat: "yy-mm-dd",
                firstDay: 1,
                isRTL: false,
                showMonthAfterYear: true,
                yearSuffix: "年"
            };
            $.datepicker.setDefaults($.datepicker.regional["zh-TW"]);

            $("#datepicker").datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true
            });
            // $(".qc-modal-reply-datepicker") will be initialized after modals are added to DOM
            $("#datepicker_ate").datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true
            });


            // Destroy existing DataTable instance if it exists, then re-initialize
            // This ensures that page-specific column definitions and settings are applied.
            if ($.fn.DataTable.isDataTable('#datatable-buttons')) {
                $('#datatable-buttons').DataTable().destroy();
            }
            dataTableInstance = $('#datatable-buttons').DataTable({
                responsive: false, // Temporarily disable responsive for testing fixed column widths
                data: [], // Will be populated by populateTableWithData
                columns: [{
                        title: "bom_ing_fid",
                        visible: false,
                        data: 0
                    }, // 0
                    {
                        title: "狀態 / 檢驗",
                        width: "9%",
                        data: 1
                    }, // 1 - Status / Inspection, increased width
                    {
                        title: "客戶"
                    }, // 2 - Client
                    {
                        title: "BOM"
                    }, // 3 - BOM
                    {
                        title: "料號"
                    }, // 4 - Part Number
                    {
                        title: "回廠"
                    }, // 5 - Return Date
                    {
                        title: "製程"
                    }, // 6 - Process
                    {
                        title: "廠商"
                    }, // 7 - Manufacturer/Supplier
                    {
                        title: "總數"
                    }, // 8 - Total Quantity
                    {
                        title: "容器"
                    }, // 8 - Total Quantity
                    {
                        title: "備註"
                    }, // 9 - Remarks (bom_ing.ps)
                    {
                        title: "選項"
                    }, // 10 - Options/Actions
                    {
                        title: "Process Type ID",
                        visible: false
                    }, // 11 - Hidden PTI for filtering
                    {
                        title: "QC Check Raw",
                        visible: false,
                        className: 'never'
                    } // 12 - Hidden raw QC_check value for filtering
                ], // Make sure columnDefs are applied after initialization if needed for specific widths
                // Example of columnDefs for widths (if still needed after responsive:false)
                // "columnDefs": [
                //     { "width": "9%", "targets": 1 }, // Status
                //     { "width": "5%", "targets": 2 }, // Client
                //     { "width": "12%", "targets": 3 }, // BOM
                //     { "width": "12%", "targets": 4 }, // Part No
                // Add other column width definitions here
                // ],

                createdRow: function(row, data, dataIndex) {
                    $(row).attr('data-bom-ing-fid', data[0]); // bom_ing_fid
                    $(row).attr('data-qc-check', data[12]); // Raw QC_check value

                    // Add attributes for presence-based filtering
                    var originalItem = allRawData.find(d => d.bom_ing_fid === data[0]);
                    if (originalItem) {
                        var qcCheck = (originalItem.QC_check || '').trim();
                        var qqSqty = parseFloat(originalItem.QC_QQ_sqty) || 0;
                        var okSqty = parseFloat(originalItem.QC_ok_sqty) || 0;
                        var totalOrderQty = parseFloat(originalItem.sqty) || 0;
                        var totalCheckedQty = qqSqty + okSqty;

                        if (qqSqty > 0) $(row).attr('data-has-qq', 'true');
                        if (okSqty > 0) $(row).attr('data-has-ok', 'true');

                        // A row is pending if it's not in a final state and not fully checked, or has 0 total quantity.
                        if (qcCheck !== 'ng' && qcCheck !== 'AOD') {
                            if (totalOrderQty > 0 && totalCheckedQty < totalOrderQty || totalCheckedQty === 0) {
                                $(row).attr('data-is-pending', 'true');
                            }
                        }
                    }
                },
                // Enable DataTables buttons
                dom: 'Bfrtip', // This line is crucial for buttons to appear
                buttons: [
                    'copy', 'csv', 'excel', 'pdf', 'print'
                ]
            });

            populateTableWithData(allRawData);

            // Initialize the QC Check filter button display to "All"
            const qcContentSpan = document.getElementById("qcCheckColorContent");
            if (qcContentSpan) {
                qcContentSpan.innerHTML = 'All'; // Set initial text to "All"
            }


            function generateModalsForItem(item) {
                let itemModalsHtml = '';
                const bomIngFidEsc = he(item.bom_ing_fid);
                const bomEsc = he(item.bom);
                const dIdEsc = he(item.d_id);
                const clientNameEsc = he(item.Client_Name);
                const processNoEsc = he(item.ProcessNo);
                const processNameEsc = he(item.ProcessName);
                const makerIdEsc = he(item.maker_id);
                const sqtyEsc = he(item.sqty);
                const itemPsEsc = he(item.ps); // bom_ing.ps (general remark)

                // Modal for QR Code
                itemModalsHtml += `
<div id="myModal_qrcode_${bomIngFidEsc}" class="modal fade" role="dialog">
    <div class="modal-dialog"> <!-- Removed modal-sm to make it default (larger) size -->
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    ${bomEsc} / ${dIdEsc}<br>
                    <small style="font-weight:normal;">總數：${sqtyEsc}</small>
                </h4>
            </div>
            <div class="modal-body" data-total-qty="${sqtyEsc}" data-bom="${bomEsc}" data-d-id="${dIdEsc}" style="min-height: 180px;"> <!-- Added data-bom, data-d-id and min-height -->
                <div class="form-group qr-modal-centered-form-group"> <!-- Container for new layout - ADDED qr-modal-centered-form-group -->
                    <div class="row qr-modal-controls-row" style="margin-bottom: 10px;"> <!-- ADDED qr-modal-controls-row -->
                        <label class="col-xs-2 control-label qr-modal-label">容器：</label>
                        <div class="col-xs-4 qr-modal-input-group">
                            <select class="form-control packaging-type" id="packaging-type-${bomIngFidEsc}">
                                <option>PP箱</option>
                                <option>蝴蝶籠</option>
                                <option>鐵桶</option>
                                <option>棧板</option>
                            </select>
                        </div>
                        <label class="col-xs-2 control-label qr-modal-label">箱數：</label>
                        <div class="col-xs-4 qr-modal-input-group">
                            <input type="number" class="form-control qty-per-unit" id="qty-per-unit-${bomIngFidEsc}" placeholder="數量" min="1"> <!-- min="1" ensures not negative, not zero -->
                        </div>
                    </div>
                </div>                
                <div class="form-group" style="margin-top: 0;">
                    <div class="row">
                        <div class="col-xs-12 calculation-result" style="padding-top: 7px; font-weight: bold;">共 ? PP箱</div> <!-- Updated initial text -->
                    </div>
                </div>
                <div class="form-group qrcode-display-area" style="text-align: center; margin-top: 15px; display: none;">
                    <!-- This area will no longer be used for preview -->
                    <div id="qrcode_image_container_${bomIngFidEsc}" style="margin-bottom: 10px;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default pull-left clear-button">清除</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                <button type="button" class="btn btn-success direct-print-qrcode-button">列印</button>
            </div>
        </div>
    </div>
</div>`;
                // QC related fields from item (ensure they exist from the corrected SQL)
                const qcPsQqEsc = he(item.QC_ps_qq);
                const qcPsNgEsc = he(item.QC_ps_ng);
                const qcPsAodRemarkEsc = he(item.QC_ps_aod_remark);
                const qcQqSqtyEsc = he(item.QC_QQ_sqty);
                const qcAodSqtyEsc = he(item.QC_aod_sqty);
                const qcNgSqtyEsc = he(item.QC_ng_sqty);
                const qcOkSqtyEsc = he(item.QC_ok_sqty);
                const qcPsOkEsc = he(item.QC_ps_ok);

                // Modal for "報工" (Reply) - No changes to this modal structure
                // itemModalsHtml += `
                // <div id="myModal_reply_${bomIngFidEsc}" class="modal fade" role="dialog">
                //     <div class="modal-dialog">
                //         <div class="modal-content">
                //             <div class="modal-header">
                //                 <button type="button" class="close" data-dismiss="modal">&times;</button>
                //                 <h4 class="modal-title">${bomEsc} / ${dIdEsc} 允收紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}&emsp;檢驗日</small></h4>
                //             </div>
                //             <div class="modal-body">
                //                 <form action="../../src/store/_updateQC_check_list_reply.php?bi=${bomIngFidEsc}&id=${currentUserId}" method="POST" class="form-horizontal form-label-center" novalidate>
                //                     <input name="bom_ing_id" value="${bomIngFidEsc}" type="hidden">
                //                     <table class="table table-striped">
                //                         <tr><td>報工日期 <span class="required">*</span></td><td><input type="text" id="datepicker_QCreply_${bomIngFidEsc}" class="qc-modal-reply-datepicker" required size="8" name="datepicker_QCreply" placeholder="日期"><small>(預設為今日)</small></td></tr>
                //                         <tr><td>客戶 <span class="required">*</span></td><td><input value="${clientNameEsc}" type="text" readonly style="border-style:none"></td></tr>
                //                         <tr><td>料號 <span class="required">*</span></td><td><input value="${dIdEsc}" type="text" readonly style="border-style:none"></td></tr>
                //                         <tr><td>製程 <span class="required">*</span></td><td><input value="[${processNoEsc}] ${processNameEsc} - ${makerIdEsc}" type="text" readonly style="border-style:none"></td></tr>
                //                         <tr><td>發單數 <span class="required">*</span></td><td><input name="sqty" value="${sqtyEsc}" required type="text" size="5"></td></tr>
                //                         <tr><td>本次加工數 <span class="required">*</span></td><td><input name="oready_sqty" required type="text" size="5"> <small>(空白=發單總數)</small></td></tr>
                //                         <tr><td>備註 <span class="required">*</span></td><td><input name="ps" size="30" required type="text"></td></tr>
                //                     </table>
                //                     <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div>
                //                 </form>
                //             </div>
                //         </div>
                //     </div>
                // </div>`;

                // Modal for "異常" (QQ) - No changes to this modal structure
                itemModalsHtml += `
                <div id="myModal_qq_${bomIngFidEsc}" class="modal fade" role="dialog">
                    <div class="modal-dialog"><div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">${bomEsc} / ${dIdEsc} 異常紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}</small></h4>
                        </div>
                        <div class="modal-body">
                            <form method="POST" action="../../src/store/_updateQC_check_list_QQ.php?bi=${bomIngFidEsc}&id=${currentUserId}" data-sqty="${sqtyEsc}" data-initial-abnormal-qty="${he(item.QC_QQ_sqty || 0)}">
                                <div class="abnormal-entries-container">                                   
                                    <div class="abnormal-entry-row abnormal-entry-header" style="display: flex; margin-bottom: 5px; font-weight: bold;">
                                        <div style="flex: 0 0 120px; margin-right: 10px;">異常數</div>
                                        <div style="flex: 1; margin-right: 10px;">QC內部簡易單據(說明)</div>
                                        <div style="flex: 0 0 80px; margin-right: 10px; text-align:center;">日期</div>
                                        <div style="flex: 0 0 80px;">&nbsp;</div>
                                    </div>
                                    <div class="ln_solid" style="margin-top: 0; margin-bottom: 10px;"></div>
                                    <div id="abnormal-rows-wrapper_${bomIngFidEsc}">
                                        <!-- Initial row will be cleared and populated by JavaScript -->
                                        <div class="abnormal-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                            <div style="flex: 0 0 100px; margin-right: 10px;">
                                                <input type="number" class="form-control abnormal-qty-input" name="qq_total_qty[]" value="" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                                <input type="hidden" name="qc_check_id[]" value="">
                                            </div>
                                            <div style="flex: 1; margin-right: 10px;">
                                                <textarea rows="1" class="form-control abnormal-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="請填寫異常原因"></textarea>
                                            </div>
                                            <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px; font-size:0.9em;" class="abnormal-check-date"></div> <!-- Date display -->
                                            <div style="flex: 0 0 80px; text-align: left;" class="abnormal-action-buttons">
                                                <button type="button" class="btn btn-warning btn-xs add-abnormal-row"><i class="fa fa-plus"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Hidden section for QC Supervisor Decision and Abnormal Order No -->
                                <div style="display: none;">
                                    <div class="ln_solid"></div>
                                    <div class="form-group text-left" style="margin-top: 10px; margin-bottom: 15px;">
                                        <span style="font-weight: bold; margin-right: 10px;">品管主管判定:</span>
                                        <button type="button" class="btn btn-success btn-xs" data-toggle="tooltip" title="特採">特採</button>
                                        <button type="button" class="btn btn-warning btn-xs" data-toggle="tooltip" title="退回原加工商">驗退</button>
                                        <button type="button" class="btn btn-secondary btn-xs" data-toggle="tooltip" title="由超正加入其他工序重工">重工</button>
                                        <button type="button" class="btn btn-danger btn-xs" data-toggle="tooltip" title="報廢不補(不重製)">報廢</button>
                                        <button type="button" class="btn btn-info btn-xs" data-toggle="tooltip" title="報廢並重製">重製</button>
                                    </div>
                                </div>
                                <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                                    <div style="display: none;"><input type="text" name="abnormal_order_no" class="form-control" placeholder="異常單號 (選填)" style="width: 150px; display: inline-block;"></div>
                                    <div style="display: flex; align-items: center; margin-left: auto;"><!-- Added margin-left: auto to push buttons to the right -->
                                        <button type="button" class="btn btn-secondary clear-all-qq-entries-btn" style="margin-right: 8px;">清除並儲存</button><button type="button" class="btn btn-default" data-dismiss="modal" style="margin-right: 8px;">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div></div>
                            </form>
                        </div>
                    </div></div>
                </div>`;

                // Modal for "特採" (AOD) - No changes to this modal structure
                itemModalsHtml += `<div id="myModal_aod_${bomIngFidEsc}" class="modal fade" role="dialog"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">${bomEsc} / ${dIdEsc} 特採紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}</small></h4></div><div class="modal-body"><form method="POST" action="../../src/store/_updateQC_check_list_AOD.php?bi=${bomIngFidEsc}&id=${currentUserId}" data-sqty="${sqtyEsc}" data-initial-abnormal-qty="${he(item.QC_QQ_sqty || 0)}"><div class="form-group" style="margin-bottom:10px;"><div style="display:flex;align-items:center;"><label class="control-label" style="margin-right:5px;white-space:nowrap;margin-bottom:0;flex-shrink:0;">特採數量：</label><input type="number" class="form-control" name="aod_total_qty" value="${qcAodSqtyEsc}" style="width:100px;" min="0" max="99999" title="特採數量" data-toggle="tooltip"></div></div><div class="form-group"><textarea rows="5" class="form-control" name="QCmessage">${qcPsAodRemarkEsc}</textarea></div><div class="modal-footer"><button type="button" class="btn btn-warning clear-textarea-btn">清除並儲存</button>&nbsp;<button type="button" class="btn btn-default" data-dismiss="modal">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div></form></div></div></div></div>`;
                // Modal for "驗退" (NG) - No changes to this modal structure
                itemModalsHtml += `<div id="myModal_ng_${bomIngFidEsc}" class="modal fade" role="dialog"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">${bomEsc} / ${dIdEsc} 驗退紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}</small></h4></div><div class="modal-body"><form method="POST" action="../../src/store/_updateQC_check_list_ng.php?bi=${bomIngFidEsc}&id=${currentUserId}" data-sqty="${sqtyEsc}" data-initial-abnormal-qty="${he(item.QC_QQ_sqty || 0)}"><div class="form-group" style="margin-bottom:10px;"><div style="display:flex;align-items:center;"><label class="control-label" style="margin-right:5px;white-space:nowrap;margin-bottom:0;flex-shrink:0;">驗退數量：</label><input type="number" class="form-control" name="ng_total_qty" value="${qcNgSqtyEsc}" style="width:100px;" min="0" max="99999" title="驗退數量" data-toggle="tooltip"></div></div><div class="form-group"><textarea rows="5" class="form-control" name="QCmessage">${qcPsNgEsc}</textarea></div><div class="modal-footer"><button type="button" class="btn btn-warning clear-textarea-btn">清除並儲存</button>&nbsp;<button type="button" class="btn btn-default" data-dismiss="modal">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div></form></div></div></div></div>`;

                // Modal for "允收" (OK) - Modified to be multi-row like "異常"
                itemModalsHtml += `
                <div id="myModal_ok_${bomIngFidEsc}" class="modal fade" role="dialog">
                    <div class="modal-dialog"><div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">${bomEsc} / ${dIdEsc} 允收紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}</small></h4>
                        </div>
                        <div class="modal-body">
                            <form method="POST" action="../../src/store/_updateQC_check_list_ok.php?bi=${bomIngFidEsc}&id=${currentUserId}" data-sqty="${sqtyEsc}">
                                <div class="ok-entries-container">
                                    <div class="ok-entry-row ok-entry-header" style="display: flex; margin-bottom: 5px; font-weight: bold;">
                                        <div style="flex: 0 0 120px; margin-right: 10px;">允收數量</div>
                                        <div style="flex: 1; margin-right: 10px;">允收備註 (選填)</div>
                                        <div style="flex: 0 0 80px; margin-right: 10px;">日期</div>
                                        <div style="flex: 0 0 80px;">&nbsp;</div>
                                    </div>
                                    <div class="ln_solid" style="margin-top: 0; margin-bottom: 10px;"></div>
                                    <div id="ok-rows-wrapper_${bomIngFidEsc}">
                                        <!-- Rows will be dynamically inserted here by JavaScript -->
                                        <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px; text-align:center;">
                                            <div style="flex: 1;">載入中...</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                                    <!-- Left side: New container fields -->
                                    <div>
                                        <div class="form-group" style="display: flex; align-items: center; gap: 5px; margin-bottom: 5px;">
                                            <label style="white-space: nowrap; margin-bottom: 0;">容器:</label>
                                            <select class="form-control" name="container[]" style="width: 100px; height: 30px; padding: 2px 6px;">
                                                <option value="">請選擇</option>
                                                <option value="P">PP箱</option>
                                                <option value="E">蝴蝶籠</option>
                                                <option value="T">鐵桶</option>
                                                <option value="板">棧板</option>
                                            </select>
                                            <label style="white-space: nowrap; margin-left: 10px; margin-bottom: 0;">箱數:</label>
                                            <input type="number" name="quantity[]" class="form-control" min="0" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, '')" style="width: 70px; height: 30px; padding: 2px 6px;"></div>
                                        <div class="form-group" style="display: flex; align-items: center; gap: 5px; margin-bottom: 0;">
                                            <label style="white-space: nowrap; margin-bottom: 0;">容器:</label>
                                            <select class="form-control" name="container[]" style="width: 100px; height: 30px; padding: 2px 6px;">
                                                <option value="">請選擇</option>
                                                <option value="P">PP箱</option>
                                                <option value="E">蝴蝶籠</option>
                                                <option value="T">鐵桶</option>
                                                <option value="板">棧板</option>
                                            </select>
                                            <label style="white-space: nowrap; margin-left: 10px; margin-bottom: 0;">箱數:</label>
                                            <input type="number" name="quantity[]" class="form-control" min="0" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, '')" style="width: 70px; height: 30px; padding: 2px 6px;"></div>
                                    </div>
                                    <span>　</span>
                                    <!-- Right side: Existing buttons -->
                                    <div style="display: flex; align-items: center;">
                                        <button type="button" class="btn btn-secondary clear-and-save-ok-btn" style="margin-right: 8px;">清除並儲存</button><button type="button" class="btn btn-default" data-dismiss="modal" style="margin-right: 8px;">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div></div>
                                </div>
                            </form>
                        </div>
                    </div></div>
                </div>`;

                // ⭐ 新增：Modal for "完成" (Complete)
                itemModalsHtml += `
                <div id="myModal_complete_${bomIngFidEsc}" class="modal fade" role="dialog" data-bom-ing-fid="${bomIngFidEsc}">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title">${bomEsc} / ${dIdEsc}</h4>
                            </div>
                            <div class="modal-body" style="font-size: 1.2em; line-height: 1.6;">
                                <!-- Content will be dynamically generated by JavaScript -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-primary btn-confirm-completion">確認完成</button>
                                <button type="button" class="btn btn-default" data-dismiss="modal" style="margin-left: 10px;">關閉</button>
                            </div>
                        </div>
                    </div>
                </div>`;
                return itemModalsHtml;
            }

            /**
             * Updated populateTableWithData to include "容器" column
             */

            function populateTableWithData(rawData) {
                if (!dataTableInstance) {
                    console.error("DataTable instance is not available for populating data.");
                    return;
                }
                if (!rawData || rawData.length === 0) {
                    console.warn("No data provided to populate the table.");
                    dataTableInstance.clear().draw();
                    return;
                }

                var tableData = [];
                var modalsHtmlBuffer = '';

                rawData.forEach(function(item) {
                    // Ensure defaults
                    item.QC_check = item.QC_check || '';
                    item.Client_Name = item.Client_Name || '';
                    item.bom = item.bom || '';
                    item.d_id = item.d_id || '';
                    item.QC_QQ_sqty = item.QC_QQ_sqty || 0;
                    item.QC_ok_sqty = item.QC_ok_sqty || 0;
                    item.latest_QQ_date_formatted = item.latest_QQ_date_formatted || '';
                    item.latest_ok_date_formatted = item.latest_ok_date_formatted || '';
                    item.return_date = item.return_date || '';
                    item.ProcessNo = item.ProcessNo || '';
                    item.ProcessName = item.ProcessName || '';
                    item.maker_id = item.maker_id || '';
                    item.sqty = item.sqty || 0;
                    item.ps = item.ps || '';
                    item.bom_ing_fid = item.bom_ing_fid || '';
                    item.process_type_id = item.process_type_id || '';

                    // Status HTML (same as original logic)
                    var statusHtml = '';
                    var qcCheck = item.QC_check.trim();
                    var qcCheckDateForOverride = he(item.QC_check_date || '');
                    var totalOrderQty = parseFloat(item.sqty) || 0;
                    var qqSqty = parseFloat(item.QC_QQ_sqty) || 0;
                    var okSqty = parseFloat(item.QC_ok_sqty) || 0;
                    var totalCheckedQty = qqSqty + okSqty;
                    var latestQqDate = he(item.latest_QQ_date_formatted);
                    var latestOkDate = he(item.latest_ok_date_formatted);
                    var statusParts = [];

                    if (qcCheck === 'ng') {
                        statusHtml = `<div class="qc-flex"><span class="circle_red"></span><small>${qcCheckDateForOverride}</small></div>`;
                    } else if (qcCheck === 'AOD') {
                        statusHtml = `<div class="qc-flex"><span class="circle_y"></span><small>${qcCheckDateForOverride}</small></div>`;
                    } else {
                        if (totalOrderQty > 0 && totalCheckedQty >= totalOrderQty) {
                            if (qqSqty > 0 && okSqty > 0) {
                                statusParts.push(`<span class="circle_y"></span><small>${he(String(qqSqty))}</small>`);
                                statusParts.push(`<span class="circle_green"></span><small>${he(String(okSqty))}</small>`);
                            } else if (qqSqty > 0) {
                                statusParts.push(`<span class="circle_y"></span><small>${latestQqDate}</small>`);
                            } else if (okSqty > 0) {
                                statusParts.push(`<span class="circle_green"></span><small>${latestOkDate}</small>`);
                            } else {
                                statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                            }
                        } else {
                            if (qqSqty > 0) statusParts.push(`<span class="circle_y"></span><small>${latestQqDate}</small>`);
                            if (okSqty > 0) statusParts.push(`<span class="circle_green"></span><small>${latestOkDate}</small>`);
                            if (statusParts.length === 0) statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                        }
                        statusHtml = `<div class="qc-flex">${statusParts.join('&emsp;')}</div>`;
                    }

                    // QR code and BOM/button functionality
                    var qrCodeButtonHtml = `
            <button type="button" class="btn btn-xs btn-default qr-code-btn-tooltip" style="margin-right: 3px; padding: 1px 5px; display: inline-flex; align-items: center; justify-content: center;" data-toggle="modal" data-target="#myModal_qrcode_${he(item.bom_ing_fid)}" title="顯示QR Code">
                <i class="fa fa-qrcode" style="font-size: 1.2em;"></i>
            </button>`;
                    var bomHtml = `
            <button type="button" class="btn-copy" onclick="egSystemCopyTextAndToggleIcon('${he(item.bom)}', this)" title="複製BOM" style="margin-right: 3px;">
                <i class="fa fa-copy"></i>
            </button> ${he(item.bom)}`;
                    var dIdHtml = `
            ${qrCodeButtonHtml}
            <button type="button" class="btn-copy" onclick="egSystemCopyTextAndToggleIcon('${he(item.d_id)}', this)" title="複製料號" style="margin-right: 3px;">
                <i class="fa fa-copy"></i>
            </button>
            <a href="../../BOM/${he(item.bom)}.jpg" target="_blank">${he(item.d_id)}</a>`;

                    var returnDateHtml = he(item.return_date);
                    var processHtml = item.ProcessNo ? `[${he(item.ProcessNo)}] ${he(item.ProcessName)}` : '[未設定 BOM]';
                    var makerIdHtml = he(item.maker_id);
                    var sqtyHtml = he(item.sqty);

                    var containerHtml = '<div class="container-cell">';
                    if (item.BIQC_ps && item.BIQC_ps.trim()) {
                        containerHtml += `<button type="button" class="container-btn">${he(item.BIQC_ps)}</button>`;
                    }
                    if (item.BIQC_ps2 && item.BIQC_ps2.trim()) {
                        containerHtml += `<button type="button" class="container-btn">${he(item.BIQC_ps2)}</button>`;
                    }
                    containerHtml += '</div>';


                    // 「備註」欄由 generatePsHtml 處理
                    var psHtml = generatePsHtml(item); // This handles QC remarks

                    // 1. 顯示來自 bom 主表的備註 (bom.bom_ps)
                    var bomMainPs = item.bom_bom_ps || ''; // bom_bom_ps is the alias for bom.bom_ps
                    if (bomMainPs.trim() !== '') {
                        // 將其加在所有內容的最前面
                        psHtml = `<div style="padding: 2px 5px; margin-bottom: 2px; background-color: #f0f0f0; border-radius: 3px;">${he(bomMainPs)}</div>` + psHtml;
                    }

                    // 2. 顯示來自 bom_ing 的生管備註 (bom_ing.single_bet_ps)
                    var singleBetPs = item.single_bet_ps || '';
                    if (singleBetPs.trim() !== '') {
                        psHtml += `<div style="background-color: #fcf8e3; color: #8a6d3b; padding: 2px 5px; margin-top: 3px; border-radius: 3px;">生管： ${he(singleBetPs)}</div>`;
                    }

                    // 操作按鈕
                    var optionsHtml = `
            <button type="button" class="btn btn-warning btn-xs" data-toggle="modal" data-target="#myModal_qq_${he(item.bom_ing_fid)}">異常</button><button type="button" class="btn btn-success btn-xs" data-toggle="modal" data-target="#myModal_ok_${he(item.bom_ing_fid)}">允收</button><button type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#myModal_complete_${he(item.bom_ing_fid)}">完成</button>`;

                    // Push data
                    tableData.push([
                        item.bom_ing_fid,
                        statusHtml,
                        he(item.Client_Name),
                        bomHtml,
                        dIdHtml,
                        returnDateHtml,
                        processHtml,
                        makerIdHtml,
                        sqtyHtml,
                        containerHtml,
                        psHtml,
                        optionsHtml,
                        item.process_type_id,
                        (item.QC_check || '').trim()
                    ]);

                    modalsHtmlBuffer += generateModalsForItem(item);
                });

                dataTableInstance.clear().rows.add(tableData).draw(false);
                $('#modals-container').html(modalsHtmlBuffer);

                // Reinitialize tooltips and datepickers
                $('body').tooltip({
                    selector: '[data-toggle="tooltip"]'
                });
                $(".qc-modal-reply-datepicker").datepicker({
                    changeMonth: true,
                    changeYear: true,
                    showMonthAfterYear: true
                });
            }


            // --- START: New Double-click Functionality ---
            var $globalSearchInput = $('#datatable-buttons_filter input');

            // Double-click on table cells (料號 or 廠商) to search
            $('#datatable-buttons tbody').on('dblclick', 'td', function() {
                if (!dataTableInstance || !allRawData) return; // Ensure DataTable instance and raw data exist

                var cell = dataTableInstance.cell(this);
                // Get the index of the column in the original 'columns' array configuration
                var columnIndexInConfig = cell.index().column;

                var row = dataTableInstance.row($(this).closest('tr'));
                var rowDataArray = row.data(); // This is the array [item.bom_ing_fid, statusHtml, ...]

                if (!rowDataArray) return;

                var bomIngFid = rowDataArray[0]; // bom_ing_fid is at index 0 of the rowDataArray
                var originalItem = allRawData.find(function(item) {
                    return item.bom_ing_fid === bomIngFid;
                });

                if (!originalItem) return;

                var searchText = '';

                if (columnIndexInConfig === 4) { // 料號 column (index 4 in 'columns' array config)
                    searchText = originalItem.d_id;
                } else if (columnIndexInConfig === 7) { // 廠商 column (index 7 in 'columns' array config)
                    searchText = originalItem.maker_id;
                }

                if (searchText && $globalSearchInput.length) {
                    dataTableInstance.search(searchText).draw();
                }
            });

            // Double-click on global search input to clear
            if ($globalSearchInput.length) {
                $globalSearchInput.on('dblclick', function() {
                    if ($(this).val() !== '') {
                        $(this).val(''); // 清除輸入框的顯示內容
                        dataTableInstance.search('').draw();
                    }
                });
            }
            // --- END: New Double-click Functionality ---

            // --- AJAX for direct action buttons (允收, 待驗) ---
            // Use event delegation for dynamically added buttons within the table
            $('#datatable-buttons tbody').on('click', '.qc-action-btn', function() {
                var button = $(this);
                var action = button.data('action');
                var bomIngId = button.data('bi');
                var userId = button.data('id');

                var url;
                var originalButtonText = button.text(); // Store original text

                switch (action) {
                    case 'wait':
                        url = '../../src/store/_updateQC_check_list_wait.php';
                        break;
                }

                button.prop('disabled', true).text('處理中...');

                $.ajax({
                    url: url,
                    type: 'GET', // These scripts expect GET parameters
                    data: {
                        bi: bomIngId,
                        id: userId
                    },
                    dataType: 'json',
                    success: function(response) {
                        var targetRow = button.closest('tr');
                        var statusCell = targetRow.find('td:nth-child(1)'); // Corrected: Status is the 1st visible cell

                        if (response.success) {
                            statusCell.empty(); // Clear previous status
                            var newStatusHtml = '';
                            var newQcCheckValue = '';

                            if (action === 'wait') { // Only 'wait' action remains here
                                newQcCheckValue = ''; // Filter expects "" for gray/待驗
                                newStatusHtml = '<div class="qc-flex">' +
                                    '<span class="circle_gray"></span>' +
                                    '<small>待驗</small>' +
                                    '</div>';
                            }
                            statusCell.html(newStatusHtml);
                            targetRow.attr('data-qc-check', newQcCheckValue); // Update the data-qc-check attribute on the TR

                            // Update QC備註 button based on response
                            updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);

                            if (dataTableInstance) {
                                var dtRow = dataTableInstance.row(targetRow);
                                dtRow.invalidate('dom'); // Invalidate based on current DOM
                                // If responsive is active and might have changed the row structure
                                if (typeof dataTableInstance.responsive === 'object' && dataTableInstance.responsive.hasHidden && dataTableInstance.responsive.hasHidden()) {
                                    dataTableInstance.responsive.recalc();
                                }
                                dataTableInstance.draw(false); // Redraw the table
                            }
                            showTemporaryMessage('更新成功', 'success');
                        } else {
                            showTemporaryMessage('更新失敗: ' + response.message, 'error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                        showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                    },
                    complete: function() {
                        // Always re-enable the button and restore its original text after the AJAX call is complete.
                        button.prop('disabled', false).text(originalButtonText);
                    }
                });
            });

            // Helper function to get relevant quantities for validation
            function getRelevantQuantities(formElement) {
                const $form = $(formElement);
                const actionUrl = $form.attr('action');
                const bomIngId = new URL(actionUrl, window.location.href).searchParams.get("bi");
                const sqty = parseFloat($form.data('sqty') || 0);

                let currentAbnormalQty = 0;
                const abnormalQtyInput = $('#abnormal_total_qty_' + bomIngId);
                if (abnormalQtyInput.length && abnormalQtyInput.val().trim() !== '') {
                    currentAbnormalQty = parseFloat(abnormalQtyInput.val()) || 0;
                } else {
                    currentAbnormalQty = parseFloat($form.data('initial-abnormal-qty') || 0);
                }

                return {
                    bomIngId,
                    sqty,
                    currentAbnormalQty
                };
            }

            // 放在 custom.js 開頭、jQuery ready 之外也行，只要先載入即可
            function qqCallback(res, $form) {
                // res.individual_qc_entries / res.data…
                updateTableRowDOM(res.individual_qc_entries);
                $form.closest('.modal').modal('hide');
            }

            function okCallback(res, $form) {
                // 同樣地
                updateTableRowDOM(res.individual_qc_entries);
                $form.closest('.modal').modal('hide');
            }


            // --- AJAX for modal form submissions (異常, 驗退) ---
            function handleModalFormSubmit(form, successCallback) {
                console.log("handleModalFormSubmit is being attached to a form."); // DEBUG
                form.on('submit', function(e) {
                    // --- START VALIDATION ---
                    const $currentForm = $(this);
                    const actionUrl = $currentForm.attr('action');
                    const quantities = getRelevantQuantities(this); // 'this' is the form element

                    let isValid = true; // Assume valid initially
                    let validationMessage = "";

                    // --- START VALIDATION ---
                    if (actionUrl.includes('_updateQC_check_list_QQ.php')) { // 異常 Modal
                        let totalAbnormalQtySum = 0;
                        let hasIncompleteQQEntry = false;
                        $currentForm.find('.abnormal-entry-row:not(.abnormal-entry-header)').each(function() {
                            const qtyInput = $(this).find('input[name="qq_total_qty[]"]');
                            const remarkInput = $(this).find('textarea[name="QCmessage[]"]');
                            const val = $(this).val();
                            if (qtyInput.val().trim() !== '' || remarkInput.val().trim() !== '' || qtyInput.val() === '0') { // If either field has data or quantity is explicitly 0
                                if (qtyInput.val().trim() === '' || parseFloat(qtyInput.val()) <= 0 || remarkInput.val().trim() === '') {
                                    hasIncompleteQQEntry = true;
                                }
                                totalAbnormalQtySum += parseFloat(qtyInput.val()) || 0;
                            }
                        });
                        if (hasIncompleteQQEntry) isValid = false;
                        validationMessage = "異常記錄的數量和原因皆需填寫，且數量需為正數。";
                        if (isValid && quantities.sqty > 0 && totalAbnormalQtySum > quantities.sqty) { // Only check if total sqty is positive
                            isValid = false;
                            validationMessage = "異常總數 (" + totalAbnormalQtySum + ") 已超過發單總數 (" + quantities.sqty + ")，請確認。";
                        }
                    } else if (actionUrl.includes('_updateQC_check_list_AOD.php')) { // 特採 Modal
                        const aodQtyEntered = parseFloat($currentForm.find('input[name="aod_total_qty"]').val()) || 0;
                        if (quantities.currentAbnormalQty <= 0 && aodQtyEntered > 0) {
                            isValid = false;
                            validationMessage = "請先輸入有效的異常總數，才能輸入特採數量。";
                        } else if (aodQtyEntered > 0 && aodQtyEntered > quantities.currentAbnormalQty) {
                            isValid = false;
                            validationMessage = "特採數量 (" + aodQtyEntered + ") 不可大於異常總數 (" + quantities.currentAbnormalQty + ")，請確認。";
                        }
                    } else if (actionUrl.includes('_updateQC_check_list_ng.php')) { // 驗退 Modal
                        const ngQtyEntered = parseFloat($currentForm.find('input[name="ng_total_qty"]').val()) || 0;
                        if (quantities.currentAbnormalQty <= 0 && ngQtyEntered > 0) {
                            isValid = false;
                            validationMessage = "請先輸入有效的異常總數，才能輸入驗退數量。";
                        } else if (ngQtyEntered > 0 && ngQtyEntered > quantities.currentAbnormalQty) {
                            isValid = false;
                            validationMessage = "驗退數量 (" + ngQtyEntered + ") 不可大於異常總數 (" + quantities.currentAbnormalQty + ")，請確認。";
                        }
                    } else if (actionUrl.includes('_updateQC_check_list_ok.php')) { // 允收 Modal
                        let totalOkQtySum = 0;
                        let hasNegativeOkQty = false;
                        $currentForm.find('input[name="ok_total_qty[]"]').each(function() {
                            const val = $(this).val();
                            if (val.trim() !== '') {
                                const qty = parseFloat(val) || 0;
                                if (qty < 0) hasNegativeOkQty = true;
                                totalOkQtySum += qty;
                            }
                        });
                        if (hasNegativeOkQty) {
                            isValid = false;
                            validationMessage = "允收數量不可為負數。";
                        } else if (isValid && quantities.sqty > 0 && totalOkQtySum > quantities.sqty) { // Only check if total sqty is positive
                            // The confirmation for exceeding total quantity can be handled here or removed if not desired for "OK"
                            if (!confirm("允收總數 (" + totalOkQtySum + ") 已超過發單總數 (" + quantities.sqty + ")。\n您確定要儲存嗎？")) {
                                isValid = false;
                            }
                        }
                    }

                    // --- New Quantity Check Logic (Integrated with existing validation) ---
                    const bomIngId = new URL(actionUrl, window.location.href).searchParams.get("bi");
                    console.log("[QC Check] Extracted bomIngId:", bomIngId); // DEBUG
                    const itemData = window.allRawData.find(item => item.bom_ing_fid === bomIngId);
                    console.log("[QC Check] Found itemData:", JSON.parse(JSON.stringify(itemData || null))); // DEBUG (stringify for deep copy log)

                    if (itemData && isValid) { // Only proceed if itemData is found and current validation is still valid
                        console.log("[QC Check] itemData is valid, proceeding with new quantity check."); // DEBUG
                        const totalOrderQty = parseFloat(itemData.sqty) || 0;
                        console.log("[QC Check] totalOrderQty (from itemData.sqty:", itemData.sqty, "):", totalOrderQty); // DEBUG

                        let sumOfQuantitiesInModal = 0;
                        let existingRelatedQuantity = 0;
                        let quantityType = ""; // For message

                        if (actionUrl.includes('_updateQC_check_list_QQ.php')) { // QQ Modal
                            quantityType = "異常";
                            existingRelatedQuantity = parseFloat(itemData.QC_ok_sqty) || 0; // Existing OK quantity
                            console.log("[QC Check] QQ Modal - existingRelatedQuantity (itemData.QC_ok_sqty:", itemData.QC_ok_sqty, "):", existingRelatedQuantity); // DEBUG
                            $currentForm.find('input[name="qq_total_qty[]"]').each(function() {
                                sumOfQuantitiesInModal += parseFloat($(this).val()) || 0;
                            });
                        } else if (actionUrl.includes('_updateQC_check_list_ok.php')) { // OK Modal
                            quantityType = "允收";
                            existingRelatedQuantity = parseFloat(itemData.QC_QQ_sqty) || 0; // Existing QQ quantity
                            console.log("[QC Check] OK Modal - existingRelatedQuantity (itemData.QC_QQ_sqty:", itemData.QC_QQ_sqty, "):", existingRelatedQuantity); // DEBUG
                            $currentForm.find('input[name="ok_total_qty[]"]').each(function() {
                                sumOfQuantitiesInModal += parseFloat($(this).val()) || 0;
                            });
                        }
                        console.log("[QC Check] sumOfQuantitiesInModal:", sumOfQuantitiesInModal); // DEBUG

                        const totalSum = sumOfQuantitiesInModal + existingRelatedQuantity;
                        console.log("[QC Check] totalSum (sumInModal + existingRelated):", totalSum); // DEBUG

                        if (totalOrderQty > 0 && totalSum > totalOrderQty) {
                            console.log("[QC Check] Condition MET: totalOrderQty > 0 && totalSum > totalOrderQty. Showing confirm."); // DEBUG
                            const confirmationMessage = `輸入${quantityType}數量總和 (${totalSum}) 已超過發單總數 (${totalOrderQty})。\n是否仍要儲存？`;
                            if (!confirm(confirmationMessage)) {
                                console.log("[QC Check] User cancelled confirm."); // DEBUG
                                isValid = false;
                            } else {
                                console.log("[QC Check] User confirmed save."); // DEBUG
                            }
                        } else {
                            console.log("[QC Check] Condition NOT MET for confirm. totalOrderQty:", totalOrderQty, "totalSum:", totalSum, "totalOrderQty > 0:", (totalOrderQty > 0), "totalSum > totalOrderQty:", (totalSum > totalOrderQty)); // DEBUG
                        }
                    } else {
                        console.log("[QC Check] Skipped new quantity check. itemData found:", !!itemData, "isValid:", isValid); // DEBUG
                    }
                    if (!isValid) {
                        e.preventDefault();
                        if (validationMessage) {
                            alert(validationMessage);
                        }
                        return;
                    }


                    // If validation passes, prevent default for AJAX and proceed
                    e.preventDefault();
                    var submitButton = form.find('input[type="submit"]');
                    var originalButtonText = submitButton.val();

                    submitButton.prop('disabled', true).val('儲存中...');

                    $.ajax({
                        // ⭐ 關鍵點 1: form.attr('action') 決定了要將資料送到哪個 PHP 檔案。
                        url: form.attr('action'),
                        type: 'POST',
                        // ⭐ 關鍵點 2: form.serialize() 將表單內所有欄位打包成字串，
                        // 例如 "quantity[]=10&container[]=P&QCmessage[]=some_text"
                        // 並透過 HTTP POST 請求發送到後端。
                        data: form.serialize(), // 這行會收集 container[] 和 quantity[] 的資料
                        dataType: 'json',
                        success: function(response) {
                            // --- GEMINI CODE ASSIST: STEP 1 ---
                            var actionUrl = form.attr('action');
                            var fullUrl = new URL(actionUrl, window.location.href); // Provide base URL
                            var bomIngId = fullUrl.searchParams.get("bi");

                            // --- GEMINI CODE ASSIST: STEP 2 ---
                            // Find the table row using the bom_ing_fid
                            var $targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                            console.log("[AJAX Success] Target row for bom_ing_fid", bomIngId, ":", $targetRow);
                            // --- END GEMINI CODE ASSIST: STEP 2 ---

                            console.log("[AJAX Success] Modal submitted for bom_ing_fid:", bomIngId, "Response:", response);

                            if (response.success) {
                                // --- GEMINI CODE ASSIST: STEP 3 ---
                                if (bomIngId && $targetRow.length > 0) {
                                    console.log("[AJAX Success] Proceeding to fetch latest data for row:", bomIngId);
                                    $.ajax({
                                        url: '../../src/store/_fetch_qc_row_details.php', // New endpoint
                                        type: 'GET',
                                        data: {
                                            bi: bomIngId
                                        },
                                        dataType: 'json',
                                        success: function(latestDataResponse) {
                                            console.log("[Fetch Latest Data Success] bom_ing_fid:", bomIngId, "Latest Data:", latestDataResponse);
                                            if (latestDataResponse.success && latestDataResponse.data) {
                                                // --- GEMINI CODE ASSIST: STEP 4 & 5 Integration (Corrected) ---
                                                // Step 4: Update the DOM for the target row (uses corrected indices now)
                                                updateTableRowDOM($targetRow, latestDataResponse.data);

                                                // Step 5: Update allRawData for consistency
                                                if (window.allRawData && bomIngId) {
                                                    const itemIndex = window.allRawData.findIndex(item => item.bom_ing_fid === bomIngId);
                                                    if (itemIndex > -1) {
                                                        let rawDataItem = window.allRawData[itemIndex];
                                                        let latest = latestDataResponse.data; // Shortcut

                                                        // Map fields from latestData.data to rawDataItem structure
                                                        // This mapping should align with how allRawData is initially populated
                                                        // and what updateTableRowDOM and generatePsHtml consume.
                                                        rawDataItem.QC_check = latest.bom_ing_details.QC_check;
                                                        rawDataItem.QC_check_date = latest.bom_ing_details.QC_check_date_formatted; // Assuming allRawData stores the formatted date
                                                        rawDataItem.processing_state = latest.bom_ing_details.processing_state;
                                                        rawDataItem.ps = latest.bom_ing_details.ps; // bom_ing.ps (general remark for remarks column)
                                                        rawDataItem.sqty = latest.bom_ing_details.sqty; // bom_ing.sqty (total order qty for status calculation)

                                                        // Tooltip remarks from bom_ing
                                                        rawDataItem.QC_ps_qq = latest.bom_ing_details.QC_ps;
                                                        rawDataItem.QC_ps_ng = latest.bom_ing_details.QC_ps2;
                                                        rawDataItem.QC_ps_aod_remark = latest.bom_ing_details.QC_ps_aod;

                                                        // Aggregated quantities from qc_check
                                                        rawDataItem.QC_QQ_sqty = latest.total_qq_qty;
                                                        rawDataItem.QC_ok_sqty = latest.total_ok_qty;

                                                        rawDataItem.latest_QQ_date_formatted = latest.latest_QQ_date_formatted;
                                                        rawDataItem.latest_ok_date_formatted = latest.latest_ok_date_formatted;
                                                        rawDataItem.individual_qc_entries = latest.individual_qc_entries; // For remarks column
                                                        console.log("[allRawData Update] Updated item at index", itemIndex, "for bom_ing_fid", bomIngId);
                                                    } else {
                                                        console.warn("[allRawData Update] bom_ing_fid", bomIngId, "not found in allRawData.");
                                                    }
                                                }
                                                // --- End GEMINI CODE ASSIST: STEP 5 Integration ---

                                                console.log("[Fetch Latest Data Success] Data to update DOM with:", latestDataResponse.data);
                                            } else {
                                                console.error("[Fetch Latest Data Error] Failed to fetch latest data:", latestDataResponse.message);
                                            }
                                        },
                                        error: function(jqXHR, textStatus, errorThrown) {
                                            console.error("[Fetch Latest Data AJAX Error] bom_ing_fid:", bomIngId, "Status:", textStatus, "Error:", errorThrown, jqXHR.responseText);
                                        }
                                    });
                                }
                                // --- END GEMINI CODE ASSIST: STEP 3 ---
                                successCallback(response, form);
                                form.closest('.modal').modal('hide');
                                showTemporaryMessage('紀錄已儲存', 'success');
                            } else {
                                showTemporaryMessage('儲存失敗: ' + response.message, 'error');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error('AJAX Error (Modal):', textStatus, errorThrown, jqXHR.responseText);
                            showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                        },
                        complete: function() {
                            submitButton.prop('disabled', false).val(originalButtonText);
                        }
                    });
                });
            }

            // Attach to "異常" modals
            $('form[action*="_updateQC_check_list_QQ.php"]').each(function() {
                handleModalFormSubmit($(this), function(response, form) {
                    var actionUrl = form.attr('action');
                    var fullUrl = new URL(actionUrl, window.location.href); // 提供基準 URL
                    var bomIngId = fullUrl.searchParams.get("bi");
                    var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]'); // More robust selector using data attribute
                    if (!targetRow.length) { // Fallback if the above doesn't find it (e.g. if data attribute wasn't added)
                        targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                            return $(this).data('bom-ing-fid') === bomIngId;
                        });
                    }

                    if (targetRow.length) {
                        let itemToUpdate = allRawData.find(item => item.bom_ing_fid === bomIngId);
                        if (itemToUpdate) {
                            console.log("[QQ Success] BOM_ING_FID:", bomIngId);
                            console.log("[QQ Success] Response individual_qc_entries:", JSON.parse(JSON.stringify(response.individual_qc_entries || [])));

                            itemToUpdate.individual_qc_entries = response.individual_qc_entries || [];
                            itemToUpdate.QC_check = response.qc_check; // Overall status from bom_ing
                            itemToUpdate.QC_check_date = response.qc_check_date; // Overall date from bom_ing
                            itemToUpdate.QC_QQ_sqty = response.total_qq_qty; // Sum from qc_check
                            itemToUpdate.QC_ok_sqty = response.total_ok_qty; // Sum from qc_check
                            // Ensure these are updated in itemToUpdate as well for consistency
                            itemToUpdate.latest_QQ_date_formatted = response.latest_QQ_date_formatted || '';
                            itemToUpdate.latest_ok_date_formatted = response.latest_ok_date_formatted || '';
                            // item.ps (general bom_ing remark) is not changed by QQ modal.
                            // response.qc_ps, qc_ps2, qc_ps_aod are for the tooltip button.

                            let newStatusDisplayHtml = '';
                            if (response.qc_check === 'QQ') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_y"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else if (response.qc_check === 'ok') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_green"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else {
                                var statusParts = [];
                                var totalOrderQtyForRow = parseFloat(itemToUpdate.sqty) || 0; // bom_ing.sqty
                                var totalCheckedQtyForRow = (parseFloat(response.total_qq_qty) || 0) + (parseFloat(response.total_ok_qty) || 0);
                                var latestQqDateFromResponse = he(response.latest_QQ_date_formatted || '');
                                var latestOkDateFromResponse = he(response.latest_ok_date_formatted || '');

                                if (totalOrderQtyForRow > 0 && totalCheckedQtyForRow >= totalOrderQtyForRow) {
                                    // Fully checked or over-checked
                                    if (parseFloat(response.total_qq_qty) > 0 && parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push(`<span class="circle_y"></span><small>${he(String(response.total_qq_qty))}</small>`);
                                        statusParts.push(`<span class="circle_green"></span><small>${he(String(response.total_ok_qty))}</small>`);
                                    } else if (parseFloat(response.total_qq_qty) > 0) {
                                        statusParts.push(`<span class="circle_y"></span><small>${latestQqDateFromResponse}</small>`);
                                    } else if (parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push(`<span class="circle_green"></span><small>${latestOkDateFromResponse}</small>`);
                                    } else {
                                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                                    }
                                } else {
                                    // Partially checked or totalOrderQty is 0
                                    if (totalOrderQtyForRow > 0 && totalCheckedQtyForRow < totalOrderQtyForRow && totalCheckedQtyForRow > 0) {
                                        statusParts.push('<span class="circle_gray"></span><small>待驗</small>');
                                    }
                                    if (parseFloat(response.total_qq_qty) > 0) {
                                        statusParts.push('<span class="circle_y"></span><small>' + he(String(response.total_qq_qty)) + '</small>');
                                    }
                                    if (parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push('<span class="circle_green"></span><small>' + he(String(response.total_ok_qty)) + '</small>');
                                    }
                                    if (statusParts.length === 0) {
                                        statusParts.push('<span class="circle_gray"></span><small>待驗</small>');
                                    }
                                }
                                newStatusDisplayHtml = '<div class="qc-flex">' + statusParts.join('&emsp;') + '</div>';
                            }


                            let newPsHtml = generatePsHtml(itemToUpdate);
                            let newQcCheckValueForFilter = response.qc_check || '';

                            var dtRow = dataTableInstance.row(targetRow);
                            // var oldPsHtml = dtRow.node() ? dtRow.data()[9] : "N/A (dtRow node not found)"; // Already logged
                            // console.log("[QQ Success] Old HTML for remarks cell:", oldPsHtml);
                            // console.log("[QQ Success] New HTML for remarks cell:", newPsHtml);

                            if (dtRow.node()) {
                                // Update DataTables' internal data store for each affected cell
                                dataTableInstance.cell(dtRow.index(), 1).data(newStatusDisplayHtml); // Status column (data index 1)
                                dataTableInstance.cell(dtRow.index(), 9).data(newPsHtml); // Remarks column (data index 9)
                                dataTableInstance.cell(dtRow.index(), 12).data(newQcCheckValueForFilter); // Hidden QC Check Raw (data index 12)

                                // Redraw the row
                                // dtRow.invalidate('data').draw(false); // Alternative: invalidate based on data source
                                dataTableInstance.row(dtRow.index()).draw(false); // Redraw the specific row by its index

                                // console.log("[QQ Success] Row updated and redrawn for bom_ing_fid " + bomIngId);
                                // The following lines to update attributes and tooltip button remain important


                                targetRow.attr('data-qc-check', newQcCheckValueForFilter);
                                updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);
                            }
                        }
                    }
                });
            });

            // Attach to "驗退" modals
            $('form[action*="_updateQC_check_list_ng.php"]').each(function() {
                handleModalFormSubmit($(this), function(response, form) {
                    var actionUrl = form.attr('action');
                    var fullUrl = new URL(actionUrl, window.location.href); // 提供基準 URL
                    var bomIngId = fullUrl.searchParams.get("bi");
                    var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                    if (!targetRow.length) { // Fallback
                        targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                            return $(this).data('bom-ing-fid') === bomIngId;
                        });
                    }
                    if (targetRow.length) {
                        let itemToUpdate = allRawData.find(item => item.bom_ing_fid === bomIngId);
                        if (itemToUpdate) {
                            // For NG, individual_qc_entries are not directly managed by this modal type in the same way as QQ/OK.
                            // The bom_ing.QC_check becomes 'ng'.
                            // The bom_ing.QC_ps2 is updated with the NG remark.
                            // We still need to refresh individual_qc_entries if they could have been affected or to ensure consistency.
                            itemToUpdate.individual_qc_entries = response.individual_qc_entries || []; // Assuming backend sends this
                            itemToUpdate.QC_check = response.qc_check; // Should be 'ng'
                            itemToUpdate.QC_check_date = response.qc_check_date;
                            itemToUpdate.QC_QQ_sqty = response.total_qq_qty; // Fetch related sums
                            itemToUpdate.QC_ok_sqty = response.total_ok_qty;
                            // itemToUpdate.ps (bom_ing.ps) is not changed by NG modal.
                            // response.qc_ps2 is the NG remark for the tooltip.

                            let newStatusDisplayHtml = '';
                            if (response.qc_check === 'ng') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_red"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else {
                                // Fallback if qc_check is not 'ng' (should not happen for this modal's success)
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_gray"></span><small>待驗</small></div>';
                            }

                            let newPsHtml = generatePsHtml(itemToUpdate); // Regenerate remarks
                            let newQcCheckValueForFilter = 'ng';

                            var dtRow = dataTableInstance.row(targetRow);
                            if (dtRow.node()) {
                                // Update DataTables' internal data store for each affected cell
                                // Column indices: 1 for Status, 9 for Remarks, 12 for hidden QC_check_raw
                                dataTableInstance.cell(dtRow.index(), 1).data(newStatusDisplayHtml);
                                dataTableInstance.cell(dtRow.index(), 9).data(newPsHtml);
                                dataTableInstance.cell(dtRow.index(), 12).data(newQcCheckValueForFilter);

                                // Redraw the row
                                dataTableInstance.row(dtRow.index()).draw(false);

                                // Update attributes and tooltip button
                                targetRow.attr('data-qc-check', newQcCheckValueForFilter);
                                updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);
                            }
                        }
                    }
                });
            });

            // Attach to "允收" modals
            $('form[action*="_updateQC_check_list_ok.php"]').each(function() {
                handleModalFormSubmit($(this), function(response, form) {
                    var actionUrl = form.attr('action');
                    var fullUrl = new URL(actionUrl, window.location.href);
                    var bomIngId = fullUrl.searchParams.get("bi");
                    var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                    if (!targetRow.length) {
                        targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                            return $(this).data('bom-ing-fid') === bomIngId;
                        });
                    }

                    if (targetRow.length) {
                        let itemToUpdate = allRawData.find(item => item.bom_ing_fid === bomIngId);
                        if (itemToUpdate) {
                            itemToUpdate.individual_qc_entries = response.individual_qc_entries || [];
                            itemToUpdate.QC_check = response.qc_check;
                            itemToUpdate.QC_check_date = response.qc_check_date;
                            itemToUpdate.QC_QQ_sqty = response.total_qq_qty; // Sum from qc_check
                            itemToUpdate.QC_ok_sqty = response.total_ok_qty; // Sum from qc_check
                            // Ensure these are updated in itemToUpdate as well
                            itemToUpdate.latest_QQ_date_formatted = response.latest_QQ_date_formatted || '';
                            itemToUpdate.latest_ok_date_formatted = response.latest_ok_date_formatted || '';

                            let newStatusDisplayHtml = '';
                            if (response.qc_check === 'ok') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_green"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else if (response.qc_check === 'QQ') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_y"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else {
                                var statusParts = [];
                                var totalOrderQtyForRow = parseFloat(itemToUpdate.sqty) || 0;
                                var totalCheckedQtyForRow = (parseFloat(response.total_qq_qty) || 0) + (parseFloat(response.total_ok_qty) || 0);
                                var latestQqDateFromResponse = he(response.latest_QQ_date_formatted || '');
                                var latestOkDateFromResponse = he(response.latest_ok_date_formatted || '');

                                if (totalOrderQtyForRow > 0 && totalCheckedQtyForRow >= totalOrderQtyForRow) {
                                    // Fully checked or over-checked
                                    if (parseFloat(response.total_qq_qty) > 0 && parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push(`<span class="circle_y"></span><small>${he(String(response.total_qq_qty))}</small>`);
                                        statusParts.push(`<span class="circle_green"></span><small>${he(String(response.total_ok_qty))}</small>`);
                                    } else if (parseFloat(response.total_qq_qty) > 0) {
                                        statusParts.push(`<span class="circle_y"></span><small>${latestQqDateFromResponse}</small>`);
                                    } else if (parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push(`<span class="circle_green"></span><small>${latestOkDateFromResponse}</small>`);
                                    } else {
                                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                                    }
                                } else {
                                    // Partially checked or totalOrderQty is 0
                                    if (totalOrderQtyForRow > 0 && totalCheckedQtyForRow < totalOrderQtyForRow && totalCheckedQtyForRow > 0) {
                                        statusParts.push('<span class="circle_gray"></span><small>待驗</small>');
                                    }
                                    if (parseFloat(response.total_qq_qty) > 0) {
                                        statusParts.push('<span class="circle_y"></span><small>' + he(String(response.total_qq_qty)) + '</small>');
                                    }
                                    if (parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push('<span class="circle_green"></span><small>' + he(String(response.total_ok_qty)) + '</small>');
                                    }
                                    if (statusParts.length === 0) {
                                        statusParts.push('<span class="circle_gray"></span><small>待驗</small>');
                                    }
                                }
                                newStatusDisplayHtml = '<div class="qc-flex">' + statusParts.join('&emsp;') + '</div>';
                            }

                            let newPsHtml = generatePsHtml(itemToUpdate);
                            let newQcCheckValueForFilter = response.qc_check || '';

                            var dtRow = dataTableInstance.row(targetRow);
                            if (dtRow.node()) {
                                // Update DataTables' internal data store for each affected cell
                                // Column indices: 1 for Status, 9 for Remarks, 12 for hidden QC_check_raw
                                dataTableInstance.cell(dtRow.index(), 1).data(newStatusDisplayHtml);
                                dataTableInstance.cell(dtRow.index(), 9).data(newPsHtml);
                                dataTableInstance.cell(dtRow.index(), 12).data(newQcCheckValueForFilter);

                                // Redraw the row
                                dataTableInstance.row(dtRow.index()).draw(false);

                                // Update attributes and tooltip button
                                targetRow.attr('data-qc-check', newQcCheckValueForFilter);
                                updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);
                            }
                        }
                    }
                });
            });

            // Attach to "特採" modals (New)
            $('form[action*="_updateQC_check_list_AOD.php"]').each(function() {
                handleModalFormSubmit($(this), function(response, form) {
                    var actionUrl = form.attr('action');
                    var fullUrl = new URL(actionUrl, window.location.href);
                    var bomIngId = fullUrl.searchParams.get("bi");
                    var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                    if (!targetRow.length) {
                        targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                            return $(this).data('bom-ing-fid') === bomIngId;
                        });
                    }
                    if (targetRow.length) {
                        let itemToUpdate = allRawData.find(item => item.bom_ing_fid === bomIngId);
                        if (itemToUpdate) {
                            // For AOD, bom_ing.QC_check becomes 'AOD'.
                            // bom_ing.QC_ps_aod is updated.
                            itemToUpdate.individual_qc_entries = response.individual_qc_entries || []; // Assuming backend sends this
                            itemToUpdate.QC_check = response.qc_check; // Should be 'AOD'
                            itemToUpdate.QC_check_date = response.qc_check_date;
                            itemToUpdate.QC_QQ_sqty = response.total_qq_qty;
                            itemToUpdate.QC_ok_sqty = response.total_ok_qty;
                            // itemToUpdate.ps (bom_ing.ps) is not changed by AOD modal.
                            // response.qc_ps_aod is the AOD remark for the tooltip.

                            let newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_y"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            if (response.qc_check !== 'AOD') { // Fallback if status is not AOD after AOD operation (should not happen)
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_gray"></span><small>待驗</small></div>';
                            }

                            let newPsHtml = generatePsHtml(itemToUpdate);
                            let newQcCheckValueForFilter = 'AOD';

                            var dtRow = dataTableInstance.row(targetRow);
                            if (dtRow.node()) {
                                // Update DataTables' internal data store for each affected cell
                                // Column indices: 1 for Status, 9 for Remarks, 12 for hidden QC_check_raw
                                dataTableInstance.cell(dtRow.index(), 1).data(newStatusDisplayHtml);
                                dataTableInstance.cell(dtRow.index(), 9).data(newPsHtml);
                                dataTableInstance.cell(dtRow.index(), 12).data(newQcCheckValueForFilter);

                                // Redraw the row
                                dataTableInstance.row(dtRow.index()).draw(false);

                                // Update attributes and tooltip button
                                targetRow.attr('data-qc-check', newQcCheckValueForFilter);
                                updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);
                            }
                        }
                    }
                });
            });

            function updateQcPsButton(targetRow, qcPs, qcPs2, qcPsAod) {
                var qcNoteCell = targetRow.find('td[name="d_id"]');
                // Make selector more specific to the QC remark button
                var qcNoteButton = qcNoteCell.find('button.qc-remark-button');
                var tooltipLines = [];

                var qcPsText = (qcPs && typeof qcPs === 'string') ? qcPs.trim() : ''; // For "異常"
                var qcPs2Text = (qcPs2 && typeof qcPs2 === 'string') ? qcPs2.trim() : ''; // For "驗退"
                var qcPsAodText = (qcPsAod && typeof qcPsAod === 'string') ? qcPsAod.trim() : ''; // For "特採"

                if (qcPsAodText !== '') {
                    tooltipLines.push("特採：" + qcPsAodText);
                } // Order: 特採
                if (qcPsText !== '') {
                    tooltipLines.push("異常：" + qcPsText);
                } // Then 異常
                if (qcPs2Text !== '') {
                    tooltipLines.push("驗退：" + qcPs2Text);
                } // Then 驗退

                var finalTooltipTitle = '';
                if (tooltipLines.length > 0) {
                    finalTooltipTitle = tooltipLines.join('\n'); // Join with a newline for multi-line tooltips
                }

                if (finalTooltipTitle !== '') { // If there's any content for the tooltip
                    if (qcNoteButton.length === 0) {
                        var $newButton = $('<button type="button" class="btn btn-xs btn-default qc-remark-button" data-toggle="tooltip" data-placement="right"></button>')
                            .attr('title', finalTooltipTitle)
                            .attr('data-remark-content', finalTooltipTitle)
                            .text('QC備註');
                        var $anchor = qcNoteCell.find('a');
                        if ($anchor.length) {
                            $anchor.append(' ').append($newButton); // Append with a leading space
                        } else {
                            // If no anchor, append directly to the cell, but this case might not occur based on your HTML structure
                            qcNoteCell.append(' ').append($newButton);
                        }
                        $newButton.tooltip(); // Initialize Bootstrap tooltip on the newly created button
                    } else {
                        qcNoteButton.attr('title', finalTooltipTitle);
                        qcNoteButton.attr('data-remark-content', finalTooltipTitle); // Add this line
                    }
                } else {
                    // All remarks are empty, remove the button
                    if (qcNoteButton.length > 0) {
                        qcNoteButton.tooltip('destroy'); // Destroy Bootstrap tooltip before removing
                        qcNoteButton.remove();
                    }
                }
            }

            function showTemporaryMessage(message, type) {
                var alertClass = (type === 'success') ? 'alert-success' : 'alert-danger';
                var messageDiv = $('<div class="alert ' + alertClass + ' fade in alert-dismissable" style="position: fixed; top: 20px; right: 20px; z-index: 1051; min-width: 200px;">' + // Increased z-index
                    '<a href="#" class="close" data-dismiss="alert" aria-label="close" title="close">×</a>' +
                    message + '</div>');
                $('body').append(messageDiv);
                messageDiv.fadeIn().delay(3000).fadeOut(function() {
                    $(this).remove();
                });
            }

            // ⭐ 合併：當「允收」或「異常」Modal 顯示時，動態載入其對應的詳細資料
            $('#modals-container').on('shown.bs.modal', function(event) {
                var modal = $(event.target);
                var modalId = modal.attr('id');

                // --- 處理「允收 (OK)」Modal ---
                if (modalId && modalId.startsWith('myModal_ok_')) {
                    var bomIngFid = modal.attr('id').replace('myModal_ok_', '');
                    var $form = modal.find('form');
                    var sqty = parseFloat($form.data('sqty')) || 0; // Get total sqty from form's data attribute
                    var wrapperId = '#ok-rows-wrapper_' + bomIngFid;
                    var $wrapper = $(wrapperId);
                    $wrapper.html('<div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px; text-align:center;"><div style="flex: 1;">載入中...</div></div>');
            
                    $.ajax({
                        url: '../../src/store/_updateQC_check_list_ok.php',
                        type: 'GET',
                        data: {
                            action: 'fetch_ok_details',
                            bi: bomIngFid
                        },
                        dataType: 'json',
                        success: function(response) {
                            $wrapper.empty();
            
                            if (response.success && response.data) {
                                if (response.data.length > 0) {
            
                                    var isPrivilegedUser = (window.currentUserStatus == 9 || window.currentUserStatus == 50 || window.currentUserStatus == 51);
                                    response.data.forEach(function(record, index) { // Removed 'array' as it's not strictly needed here for the new logic
                                        var actionButtonHtml = '';
                                        var readonlyAttribute = ''; // 移除唯讀屬性，讓輸入框始終可編輯
            
                                        // If it's the first record (index 0), it gets both "plus" and "minus" buttons.
                                        // Otherwise, it only gets a "minus" button.
                                        if (index === 0) {
                                            actionButtonHtml =
                                                '<button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button> ' +
                                                '<button type="button" class="btn btn-danger btn-xs remove-ok-row" title="刪除此筆"><i class="fa fa-minus"></i></button>';
                                        } else {
                                            actionButtonHtml =
                                                '<button type="button" class="btn btn-danger btn-xs remove-ok-row" title="刪除此筆"><i class="fa fa-minus"></i></button>';
                                        }
            
                                        var newRowHtml = `
                                            <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                                <div style="width: 100px; margin-right: 10px; flex-shrink: 0;">
                                                    <input type="number" class="form-control ok-qty-input" name="ok_total_qty[]" value="${he(record.QC_ok_sqty || '')}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量" ${readonlyAttribute}>
                                                    <input type="hidden" name="qc_check_id[]" value="${he(record.qc_check_id || '')}">
                                                </div>
                                                <div style="flex-grow: 1; margin-right: 10px;">
                                                    <textarea rows="1" class="form-control ok-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="允收備註" ${readonlyAttribute}>${he(record.QC_ps_ok || '')}</textarea>
                                                </div>
                                                <div style="width: 80px; margin-right: 10px; flex-shrink: 0; padding-top: 7px;" class="ok-check-date">
                                                    ${he(record.QC_check_date_formatted || '')}
                                                </div>
                                                <div style="width: 80px; flex-shrink: 0; text-align: left;" class="ok-action-buttons">${actionButtonHtml}</div>
                                            </div>`;
                                        $wrapper.append(newRowHtml);
                                    });
                                } else { // No existing 'ok' records, add one blank row
                                    var sqty = parseFloat($form.data('sqty')) || 0; // Use total sqty as default for blank row
                                    var newRowHtml = `
                                        <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                            <div style="flex: 0 0 100px; margin-right: 10px;">
                                                <input type="number" class="form-control ok-qty-input" name="ok_total_qty[]" value="${sqty}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                                <!-- No qc_check_id for new rows initially -->
                                                <input type="hidden" name="qc_check_id[]" value="">
                                            </div>
                                            <div style="flex: 1; margin-right: 10px;">
                                                <textarea rows="1" class="form-control ok-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="允收備註"></textarea>                                            </div>
                                            <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px;" class="ok-check-date"></div>
                                            <div style="flex: 0 0 80px; text-align: left;" class="ok-action-buttons">
                                                <button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button>
                                            </div></div>`;
                                    // The initial blank row should not have a date display as it's not yet saved.
                                    $wrapper.append(newRowHtml);
                                }
                            } else {
                                $wrapper.html('<div class="ok-entry-row" style="text-align:center;"><div style="flex:1; color:red;">載入失敗: ' + he(response.message) + '</div></div>');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            $wrapper.html('<div class="ok-entry-row" style="text-align:center;"><div style="flex:1; color:red;">請求失敗: ' + he(textStatus) + '</div></div>');
                            console.error("Error fetching OK details:", textStatus, errorThrown);
                        }
                    });
                }
            
                // --- 處理「異常 (QQ)」Modal ---
                else if (modalId && modalId.startsWith('myModal_qq_')) {
                    var bomIngFid = modal.attr('id').replace('myModal_qq_', '');
                    var $form = modal.find('form');
                    var wrapperId = '#abnormal-rows-wrapper_' + bomIngFid;
                    var $wrapper = $(wrapperId);
            
                    $wrapper.html('<div class="abnormal-entry-row" style="display: flex; align-items: center; margin-bottom: 10px; text-align:center;"><div style="flex: 1;">載入中...</div></div>');
                    modal.find('input[name="abnormal_order_no"]').val('');
            
                    $.ajax({
                        url: '../../src/store/_updateQC_check_list_QQ.php',
                        type: 'GET',
                        data: {
                            action: 'fetch_qq_details',
                            bi: bomIngFid,
                            id: currentUserId
                        },
                        dataType: 'json',
                        success: function(response) {
                            $wrapper.empty();
                            if (response.success && response.data && Array.isArray(response.data)) {
                                var records = response.data;
                                var firstAbnormalOrderNo = null;
            
                                if (records.length > 0) { // If there are existing QQ records
                                    if (records[0].abnormal_order_no) {
                                        firstAbnormalOrderNo = records[0].abnormal_order_no;
                                    }
            
                                    records.forEach(function(record, index) {
                                        var newRowHtml = `
                                            <div class="abnormal-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                                <div style="flex: 0 0 100px; margin-right: 10px;">
                                                    <input type="number" class="form-control abnormal-qty-input" name="qq_total_qty[]" value="${he(record.QC_QQ_sqty || '')}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                                    <input type="hidden" name="qc_check_id[]" value="${he(record.qc_check_id || '')}">
                                                </div>
                                                <div style="flex: 1; margin-right: 10px;">
                                                    <textarea rows="1" class="form-control abnormal-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="請填寫異常原因">${he(record.QC_ps || '')}</textarea>
                                                </div>
                                                <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px; font-size:0.9em;" class="abnormal-check-date">
                                                    ${he(record.QC_check_date_formatted || '')}
                                                </div>
                                                <div style="flex: 0 0 80px; text-align: left;" class="abnormal-action-buttons">
                                                    ${index === 0 ?
                                                        '<button type="button" class="btn btn-warning btn-xs add-abnormal-row" title="新增一筆"><i class="fa fa-plus"></i></button> ' +
                                                        '<button type="button" class="btn btn-danger btn-xs remove-abnormal-row" title="刪除此筆"><i class="fa fa-minus"></i></button>' :
                                                        '<button type="button" class="btn btn-danger btn-xs remove-abnormal-row" title="刪除此筆"><i class="fa fa-minus"></i></button>'}
                                                </div>
                                            </div>`;
                                        $wrapper.append(newRowHtml);
                                    });
                                } else {
                                    var blankRowHtml = `<!-- If no existing QQ records, add one blank row -->
                                        <div class="abnormal-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                            <div style="flex: 0 0 100px; margin-right: 10px;">
                                                <input type="number" class="form-control abnormal-qty-input" name="qq_total_qty[]" value="" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                                <input type="hidden" name="qc_check_id[]" value="">
                                            </div>
                                            <div style="flex: 1; margin-right: 10px;">
                                                <textarea rows="1" class="form-control abnormal-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="請填寫異常原因"></textarea>
                                            </div>
                                            <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px; font-size:0.9em;" class="abnormal-check-date"></div>
                                            <div style="flex: 0 0 80px; text-align: left;" class="abnormal-action-buttons">
                                                <button type="button" class="btn btn-warning btn-xs add-abnormal-row" title="新增一筆"><i class="fa fa-plus"></i></button>
                                                <button type="button" class="btn btn-danger btn-xs remove-abnormal-row" title="刪除此筆"><i class="fa fa-minus"></i></button>
                                            </div><!-- The initial blank row should have both buttons -->
                                        </div>`;
                                    $wrapper.append(blankRowHtml);
                                }
                                modal.find('input[name="abnormal_order_no"]').val(he(firstAbnormalOrderNo || ''));
                            } else {
                                $wrapper.html('<div class="abnormal-entry-row" style="text-align:center;"><div style="flex:1; color:red;">載入失敗: ' + he(response.message || '未知錯誤') + '</div></div>');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            $wrapper.html('<div class="abnormal-entry-row" style="text-align:center;"><div style="flex:1; color:red;">請求失敗: ' + he(textStatus) + '</div></div>');
                            console.error("Error fetching QQ details:", textStatus, errorThrown, jqXHR.responseText);
                        }
                    });
                }
            });

            // --- QC Remark Popup Logic ---
            var $qcRemarkPopup = $('#qcRemarkPopup');
            var $qcRemarkPopupContent = $('#qcRemarkPopupContent');
            var currentQcRemarkButton = null;

            // Handle click on QC Remark buttons
            $(document).on('click', '.qc-remark-button', function(event) {
                event.preventDefault();
                event.stopPropagation();

                var $button = $(this);
                var remarkContent = $button.data('remark-content');

                if ($qcRemarkPopup.is(':visible') && currentQcRemarkButton === this) {
                    $qcRemarkPopup.hide();
                    currentQcRemarkButton = null;
                } else {
                    $qcRemarkPopupContent.html(remarkContent ? remarkContent.replace(/\n/g, '<br>') : '');

                    var buttonOffset = $button.offset();
                    var buttonHeight = $button.outerHeight();

                    $qcRemarkPopup.css({
                        top: buttonOffset.top + buttonHeight + 5,
                        left: buttonOffset.left,
                        display: 'block'
                    });
                    currentQcRemarkButton = this;
                }
            });

            // Close popup when clicking outside
            $(document).on('click', function(event) {
                if ($qcRemarkPopup.is(':visible') && !$(event.target).closest('#qcRemarkPopup').length && event.target !== currentQcRemarkButton && !$(event.target).closest('.qc-remark-button').is(currentQcRemarkButton)) {
                    $qcRemarkPopup.hide();
                    currentQcRemarkButton = null;
                }
            });
            $qcRemarkPopup.on('click', function(event) {
                event.stopPropagation();
            });

            // --- Clear Textarea Button for Modals ---
            // This handler is for AOD and NG modals, where "Clear and Save" means clearing the remark and saving.
            // This handler is now AJAX-based to prevent page reload and update UI selectively.
            $(document).on('click', '.clear-textarea-btn', function() {
                var $button = $(this);
                var $modalContent = $button.closest('.modal-content');
                var $textarea = $modalContent.find('textarea[name="QCmessage"]');
                var $abnormalQtyInput = $modalContent.find('input[name="abnormal_total_qty"]'); // Find abnormal quantity input
                var $form = $modalContent.find('form');
                var originalButtonText = $button.text();

                // Specifically for AOD and NG modals, this button clears the main textarea.
                // It does NOT clear quantity inputs in these modals as they are separate.
                $textarea.val(''); // Clear the textarea

                // For AOD and NG, the quantity inputs are separate and not part of this "clear textarea" action.
                // If $abnormalQtyInput is specific to QQ modal, it shouldn't be here.
                // Let's assume this generic handler is NOT for QQ modal's "clear all entries".
                // $abnormalQtyInput.val(''); // This line might be incorrect for a generic handler.

                $button.prop('disabled', true).text('處理中...');


                var formData = $form.serializeArray(); // Get form data as array
                formData.push({
                    name: "clear_remark_only",
                    value: "1"
                }); // Add flag

                $.ajax({
                    url: $form.attr('action'),
                    type: 'POST',
                    data: $.param(formData), // Serialize the array to query string
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            var actionUrl = $form.attr('action');
                            var fullUrl = new URL(actionUrl, window.location.href);
                            var bomIngId = fullUrl.searchParams.get("bi");
                            var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                            if (!targetRow.length) {
                                // Fallback if data-attribute not found (shouldn't happen with current setup)
                                targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                                    return $(this).data('bom-ing-fid') === bomIngId;
                                });
                            }

                            if (targetRow.length) {
                                // For AOD/NG clear, the main status circle (QC_check) is NOT changed by backend.
                                // Only the specific remark (QC_ps_aod or QC_ps2) is cleared.
                                // So, we only need to update the tooltip button.
                                // The backend response for AOD/NG clear_remark_only=1 returns the updated set of remarks.

                                // Fetch full details to ensure all parts of the row are consistent,
                                // especially if other parts of the system could have changed the row.
                                $.ajax({
                                    url: '../../src/store/_fetch_qc_row_details.php',
                                    type: 'GET',
                                    data: {
                                        bi: bomIngId
                                    },
                                    dataType: 'json',
                                    success: function(fetchResponse) {
                                        if (fetchResponse.success && fetchResponse.data) {
                                            updateTableRowDOM(targetRow, fetchResponse.data);
                                            // Update allRawData (simplified, assumes direct mapping for relevant fields)
                                            var itemIndex = window.allRawData.findIndex(item => item.bom_ing_fid === bomIngId);
                                            if (itemIndex > -1) {
                                                Object.assign(window.allRawData[itemIndex], fetchResponse.data.bom_ing_details, {
                                                    individual_qc_entries: fetchResponse.data.individual_qc_entries,
                                                    total_qq_qty: fetchResponse.data.total_qq_qty,
                                                    total_ok_qty: fetchResponse.data.total_ok_qty,
                                                    latest_QQ_date_formatted: fetchResponse.data.latest_QQ_date_formatted,
                                                    latest_ok_date_formatted: fetchResponse.data.latest_ok_date_formatted
                                                });
                                            }
                                        }
                                    }
                                });
                                // The line below was for the old direct update, now handled by fetch + updateTableRowDOM
                                // updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);

                                // The following lines related to abnormal_total_qty are specific to QQ modal and should not be in this generic handler.
                                // $form.data('initial-abnormal-qty', response.QC_QQ_sqty === null ? 0 : response.QC_QQ_sqty);
                                // $form.find('input[name="abnormal_total_qty"]').val(response.QC_QQ_sqty === null ? '' : response.QC_QQ_sqty);

                                // DO NOT update status circle or data-qc-check attribute here for AOD/NG clear
                                // because QC_check and QC_check_date were not changed by this action.

                                if (dataTableInstance) {
                                    var dtRow = dataTableInstance.row(targetRow);
                                    dtRow.invalidate('dom'); // Invalidate based on current DOM
                                    if (typeof dataTableInstance.responsive === 'object' && dataTableInstance.responsive.hasHidden && dataTableInstance.responsive.hasHidden()) {
                                        dataTableInstance.responsive.recalc();
                                    }
                                    dataTableInstance.draw(false);
                                }
                            }
                            showTemporaryMessage('備註已清除並儲存', 'success');
                        } else {
                            showTemporaryMessage('清除備註失敗: ' + response.message, 'error');
                            // Textarea remains cleared as per user action
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) { // This is for the POST to clear remark
                        console.error('AJAX Error (Clear Remark):', textStatus, errorThrown, jqXHR.responseText);
                        showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                        // Textarea remains cleared
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalButtonText);
                    }
                });
            });

            // --- QR Code Icon Tooltip - Immediate Hide Logic ---
            // Event delegation for QR code button tooltips to show/hide immediately
            $('#datatable-buttons tbody').on('mouseenter', '.qr-code-btn-tooltip', function() {
                $(this).tooltip({ // Initialize on hover with manual trigger
                    trigger: 'manual',
                    container: 'body' // Appends tooltip to body, helps with positioning
                }).tooltip('show');
            }).on('mouseleave', '.qr-code-btn-tooltip', function() {
                // Hide tooltip immediately on mouse leave
                if ($(this).data('bs.tooltip')) { // Check if tooltip was initialized
                    $(this).tooltip('hide');
                }
            });

            // QR Code Modal: Event listeners for dynamic calculation and buttons
            $('#modals-container').on('input change', '.qty-per-unit, .packaging-type', function() {
                const $modal = $(this).closest('.modal');
                if (!$modal.find('.qty-per-unit').length) return; // Only proceed if it's the QR code modal

                const totalQty = parseFloat($modal.find('.modal-body').data('total-qty')) || 0;
                const $qtyPerUnitInput = $modal.find('.qty-per-unit');
                const $packagingTypeSelect = $modal.find('.packaging-type');
                const $calculationResultDiv = $modal.find('.calculation-result');

                let qtyPerUnit = parseFloat($qtyPerUnitInput.val()) || 0;
                const packagingType = $packagingTypeSelect.val();

                if (qtyPerUnit > totalQty && totalQty > 0) {
                    alert("每單位數量 (" + qtyPerUnit + ") 不可超過總數 (" + totalQty + ")。");
                    $qtyPerUnitInput.val(totalQty); // Optionally reset to max allowed or leave as is
                    qtyPerUnit = totalQty; // Re-assign for calculation
                }

                if (qtyPerUnit > 0 && totalQty > 0) {
                    const numPackages = Math.floor(totalQty / qtyPerUnit);
                    // Update the calculation result text
                    $calculationResultDiv.text(`共 ${qtyPerUnit} ${packagingType}`);
                } else {
                    // If qtyPerUnit is not valid, show placeholder
                    $calculationResultDiv.text(`共 ? ${packagingType}`);
                }
            });

            $('#modals-container').on('click', '.clear-button', function() {
                const $modal = $(this).closest('.modal');
                if (!$modal.find('.qty-per-unit').length) return;

                $modal.find('.qty-per-unit').val('');
                $modal.find('.packaging-type').prop('disabled', false).trigger('change');
                $modal.find('.generate-qrcode-button').show();
                $modal.find('.direct-print-qrcode-button').show(); // Ensure print button is visible
                $modal.find('.qrcode-display-area').hide().html('<p>QR Code 預留位置</p>'); // Hide and reset QR display
            });

            // Generate QR Code Button
            $('#modals-container').on('click', '.generate-qrcode-button', function() {
                const $modal = $(this).closest('.modal');
                const $qtyPerUnitInput = $modal.find('.qty-per-unit');
                // const $packagingTypeSelect = $modal.find('.packaging-type'); // No longer directly used for display
                // const $qrCodeDisplayArea = $modal.find('.qrcode-display-area'); // No longer used for preview
                const totalQty = parseFloat($modal.find('.modal-body').data('total-qty')) || 0;
                // const packagingTypeVal = $modal.find('.packaging-type option:selected').text(); // No longer directly used for display
                const qtyPerUnitVal = $modal.find('.qty-per-unit').val() || "未輸入";
                const userInputTotalBoxes = parseFloat($qtyPerUnitInput.val()) || 0; // User's input for total boxes
                const bomForQr = $modal.find('.modal-body').data('bom');

                if (!$modal.find('.qty-per-unit').length) return;

                if (userInputTotalBoxes <= 0 || qtyPerUnitVal === "未輸入") {
                    alert("請先輸入有效的總箱數。"); // Changed alert message
                    return;
                }
                // Removed validation: if (userInputTotalBoxes > totalQty && totalQty > 0)
                // It's okay if total boxes > total items.
                // Make inputs readonly
                // $qtyPerUnitInput.prop('readonly', true); // Readonly state handled by print flow
                // $packagingTypeSelect.prop('disabled', true); // Disabled state handled by print flow

                // Hide the clear button
                // $modal.find('.clear-button').hide(); // Clear button remains visible

                // Button visibility handled by direct-print-qrcode-button logic

                // Construct QR Code URL
                const qrUrl = `http://192.168.2.128/EGsystem/views/pm/schedule_T5.php?b=${encodeURIComponent(bomForQr)}`;

                // Construct URL for the generate_qrcode.php script
                const generateQrCodePhpUrl = `../../views/QC/generate_qrcode.php?text=${encodeURIComponent(qrUrl)}`;

                // Correctly get bom_ing_fid from the modal's ID to form the qrCodeContainerId
                const modalId = $modal.attr('id'); // e.g., "myModal_qrcode_actualBomIngFid"
                const bomIngFidValue = modalId.substring(modalId.lastIndexOf('_') + 1); // Extracts the part after the last underscore
                const qrCodeContainerId = `qrcode_image_container_${bomIngFidValue}`;

                // Preview is skipped. The direct-print-qrcode-button will handle printing.
                // For now, this button might not be needed if direct-print-qrcode-button does everything.
                // If it's still used, it should probably just call the direct print logic.
                // For simplicity, let's assume direct-print-qrcode-button is the main one.
                // This .generate-qrcode-button might be removed or repurposed.
                // If this button is still intended to be the primary print button, its logic needs to be merged
                // with the .direct-print-qrcode-button logic.

                // For now, let's make this button also trigger the direct print.
                $modal.find('.direct-print-qrcode-button').click();
            });

            // Direct Print QR Code Button (replaces the old print and generate logic)
            $('#modals-container').on('click', '.direct-print-qrcode-button', function() {
                const $modal = $(this).closest('.modal');
                const bomForQr = $modal.find('.modal-body').data('bom');
                const dIdFromModal = $modal.find('.modal-body').data('d-id') || 'N/A';
                const totalQty = parseFloat($modal.find('.modal-body').data('total-qty')) || 0;
                const packagingTypeVal = $modal.find('.packaging-type option:selected').text();
                const userInputTotalBoxes = parseFloat($modal.find('.qty-per-unit').val()) || 0; // Total boxes from user input

                // Construct QR Code URL for printing
                const qrUrlForPrint = `http://192.168.2.128/EGsystem/views/pm/schedule_T5.php?b=${encodeURIComponent(bomForQr)}`;
                const generateQrCodePhpUrlForPrint = `../../views/QC/generate_qrcode.php?text=${encodeURIComponent(qrUrlForPrint)}`;
                const qrCodeForPrintHtml = `<img src="${generateQrCodePhpUrlForPrint}" alt="QR Code" class="qr-code-image">`;

                if (userInputTotalBoxes <= 0) {
                    alert("請輸入有效的總箱數才能列印。"); // Changed alert message
                    return;
                }

                const totalPagesToPrint = userInputTotalBoxes; // Use user input directly

                if (totalQty < 0) { // totalQty can be 0, but not negative
                    alert("總數量為0或無效，無法列印。");
                    return;
                }

                const today = new Date();
                const dateString = `${today.getFullYear()}.${String(today.getMonth() + 1).padStart(2, '0')}.${String(today.getDate()).padStart(2, '0')}`;

                let allPagesHtml = ''; // Accumulator for all label HTML
                for (let currentPage = 1; currentPage <= totalPagesToPrint; currentPage++) {
                    // Calculate quantity for the current box
                    // For now, it's not used in the visible label.

                    let printHtml = `
                        <html>
                        <head>
                            <title>列印 - ${he(bomForQr)} - 箱號 ${currentPage}/${totalPagesToPrint}</title>
                            <style>
                                @page {
                                    margin-top: 0mm; /* Align print content to the top of the page */
                                    margin-bottom: 0mm; /* Optional: also remove bottom margin */
                                    size: 70mm 50mm; /* Explicitly set page size */
                                }
                                body { 
                                    font-family: Arial, "微軟正黑體", "Microsoft JhengHei", sans-serif;
                                    margin: 0; /* Remove body margin for precise label control */
                                    font-size: 8pt; /* Reduced base font size for smaller label */
                                }
                                .print-container { 
                                    width: 70mm; /* Target label width */
                                    height: 50mm; /* Target label height */
                                    border: none; /* No border for actual printing */
                                    padding: 2mm; /* Reduced padding for smaller label */
                                    box-sizing: border-box; 
                                    overflow: hidden; /* Prevent content from spilling out */
                                    page-break-after: always; /* Ensure each label is on a new page */
                                }
                                .part-number-row { 
                                    font-size: 12pt; /* Reduced font size */
                                    font-weight: bold;
                                    text-align: left; /* Spans both columns, aligned left */
                                    margin-bottom: 0.5mm; /* Further reduced spacing */
                                    padding-bottom: 0mm; /* Reduced spacing */
                                    /* border-bottom: 1px solid black; Removed */
                                }
                                .content-table { 
                                    width: 100%; 
                                    border-collapse: collapse; 
                                }
                                .content-table td { 
                                    padding: 1mm; 
                                    vertical-align: top; 
                                }
                                .left-col { 
                                    width: 50%; Removed for auto-width
                                    padding-right: 0mm; /* Space between text and dashed line */
                                    /* border-right: 0px dashed #555; Removed */
                                    text-align: left;
                                    font-size: 10pt; 
                                }
                                .right-col { 
                                    width: 50%; Removed for auto-width
                                    padding-left: 0mm; /* Space between dashed line and QR code */
                                    text-align: left; /* 水平靠左 QR Code */
                                    vertical-align: top; /* 垂直靠上 QR Code */
                                    margin: 0;  /* For centering */
                                }
                                .label { font-weight: bold; font-size: 9pt; } /* Increased font size */
                                .info-line { line-height: 1.5; font-size: 9pt; } /* Increased font size */
                                .info-line:not(:last-child) {
                                    margin-bottom: 0.5mm; /* Reduced margin */
                                }
                                .company-footer { 
                                    margin-top: 0mm; /* Removed top margin to stick to content above */
                                    padding-top: 1mm; /* Reduced padding */
                                    font-size: 11pt; 
                                    font-weight: bold; /* Added bold font weight */
                                    text-align: justify; text-justify: inter-word; /* Justified alignment */
                                    border-top: 1px solid black; /* Line for merged footer */
                                }
                                .qr-code-image {
                                    max-width: 90%; /* Maximize width within its column */
                                    height: auto;    /* Maintain aspect ratio */
                                    display: block;  /* For centering */
                                    margin: 0 0;  /* For centering */
                                }
                            </style>
                        </head>
                        <body>
                            <div class="print-container">
                                <div class="part-number-row">料號：${he(dIdFromModal)}</div>
                                <table class="content-table">
                                    <tr>
                                        <td class="left-col">
                                            <div class="info-line"><span class="label">製令：</span>${he(bomForQr)}</div>
                                            <div class="info-line"><span class="label">總數：</span>${totalQty}</div>
                                            <div class="info-line"><span class="label">容器：</span>${he(packagingTypeVal)}</div>
                                            <div class="info-line"><span class="label">箱號：</span>${currentPage} / ${totalPagesToPrint}</div>
                                            <div class="info-line"><span class="label">日期：</span>${dateString}</div>
                                        </td>
                                        <td class="right-col">
                                            ${qrCodeForPrintHtml}
                                        </td>
                                    </tr>
                                </table>
                                <div class="company-footer">
                                    超正齒輪科技有限公司 2-QA-01-02
                                </div>
                            </div>
                    `;
                    allPagesHtml += printHtml; // Append current label's HTML
                }

                // Open one print window with all labels
                if (allPagesHtml) {
                    let printWindow = window.open('', '_blank', 'height=600,width=1000'); // Increased window size
                    printWindow.document.write(`
                        <html>
                        <head>
                            <title>列印預覽 - ${he(bomForQr)}</title>
                            <style>
                                @page {
                                    margin-top: 0mm;
                                    margin-bottom: 0mm;
                                    size: 70mm 50mm;
                                }
                                body { font-family: Arial, "微軟正黑體", "Microsoft JhengHei", sans-serif; margin: 0; font-size: 8pt; }
                                .print-container { width: 70mm; height: 50mm; border: none; padding: 2mm; box-sizing: border-box; overflow: hidden; page-break-after: always; }
                                .part-number-row { font-size: 12pt; font-weight: bold; text-align: left; margin-bottom: 0.5mm; padding-bottom: 0mm; }
                                .content-table { width: 100%; border-collapse: collapse; }
                                .content-table td { padding: 1mm; vertical-align: top; }
                                .left-col { text-align: left; font-size: 10pt; }
                                .right-col { text-align: left; vertical-align: top; margin: 0; }
                                .label { font-weight: bold; font-size: 9pt; }
                                .info-line { line-height: 1.5; font-size: 9pt; }
                                .info-line:not(:last-child) { margin-bottom: 0.5mm; }
                                .company-footer { margin-top: 0mm; padding-top: 1mm; font-size: 11pt; font-weight: bold; text-align: justify; text-justify: inter-word; border-top: 1px solid black; }
                                .qr-code-image { max-width: 90%; height: auto; display: block; margin: 0; }
                            </style>
                        </head>
                        <body>${allPagesHtml}</body></html>`);
                    printWindow.document.close();
                    printWindow.focus();
                    // It's generally better to let the user initiate print from the browser's print dialog
                    // but if auto-print is desired and works in your target browsers:
                    setTimeout(() => {
                        printWindow.print();
                    }, 500);
                }
            });

            // --- QR Code Modal: 箱數 input Enter key press to trigger Generate QR Code button ---
            $('#modals-container').on('keypress', '.qty-per-unit', function(e) {
                if (e.which === 13) { // Enter key pressed
                    e.preventDefault(); // Prevent default form submission or other newline behavior
                    const $modal = $(this).closest('.modal');
                    const $printButton = $modal.find('.direct-print-qrcode-button');
                    const qtyPerUnitVal = $(this).val();

                    if (qtyPerUnitVal && parseFloat(qtyPerUnitVal) > 0 && $printButton.is(':visible')) {
                        $printButton.click();
                    }
                }
            });

            // --- Auto-focus on "箱數" input when QR Code modal is shown ---
            $('#modals-container').on('shown.bs.modal', '.modal[id^="myModal_qrcode_"]', function() {
                // Find the 'qty-per-unit' input within this specific modal and focus on it
                var $qtyInput = $(this).find('.qty-per-unit');
                if ($qtyInput.length) {
                    $qtyInput.focus();
                }
            });

            // --- Add/Remove Abnormal Entry Rows ---
            $(document).on('click', '.add-abnormal-row', function() {
                var $thisButton = $(this);
                var $wrapper = $thisButton.closest('[id^="abnormal-rows-wrapper_"]');
                var currentRowCount = $wrapper.find('.abnormal-entry-row').length;
                var $currentRow = $thisButton.closest('.abnormal-entry-row'); // Get the row containing the clicked button
                if (currentRowCount >= 15) {
                    alert("最多只能新增 15 筆異常紀錄。");
                    return; // Stop adding rows
                }

                var $newRow = $currentRow.clone(true, true); // Clone the current row (the one with the plus button)

                // Clear input values in the new row
                $newRow.find('input[type="number"], textarea').val('');
                $newRow.find('input[name="qc_check_id[]"]').val(''); // Clear hidden qc_check_id
                $newRow.find('.abnormal-check-date').empty(); // Clear the date display

                // Change the plus button to a minus button in the new row
                var $actionButtonsDiv = $newRow.find('.abnormal-action-buttons');
                $actionButtonsDiv.empty(); // Clear existing buttons
                $actionButtonsDiv.append('<button type="button" class="btn btn-danger btn-xs remove-abnormal-row"><i class="fa fa-minus"></i></button>');

                // Append the new row to the end of the wrapper
                $wrapper.append($newRow);
            });

            $(document).on('click', '.remove-abnormal-row', function() {
                var $thisButton = $(this);
                var $currentRow = $thisButton.closest('.abnormal-entry-row');
                var $wrapper = $currentRow.closest('[id^="abnormal-rows-wrapper_"]');
                var wasFirstRow = $currentRow.is(':first-child');
                $currentRow.remove();

                var $remainingRows = $wrapper.find('.abnormal-entry-row');
                if ($remainingRows.length === 0) {
                    // If all rows are removed, add a new blank row with only a '+' button
                    var bomIngFidForBlank = $wrapper.attr('id').replace('abnormal-rows-wrapper_', ''); // Corrected ID replacement
                    var sqtyForBlank = parseFloat($('#myModal_ok_' + bomIngFidForBlank).find('form').data('sqty')) || 0;
                    var blankRowHtml = `
                        <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                            <div style="flex: 0 0 100px; margin-right: 10px;">
                                <input type="number" class="form-control ok-qty-input" name="ok_total_qty[]" value="${sqtyForBlank}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                <input type="hidden" name="qc_check_id[]" value="">
                            </div>
                            <div style="flex: 1; margin-right: 10px;">
                                <textarea rows="1" class="form-control ok-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="允收備註"></textarea>
                            </div>
                            <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px;" class="ok-check-date"></div>
                            <div style="flex: 0 0 80px; text-align: left;" class="ok-action-buttons">
                                <button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button>
                                            </div><!-- Only plus button for the single blank row -->
                        </div>`;
                    $wrapper.append(blankRowHtml);
                } else if (wasFirstRow) {
                    // The new first row needs both + and -
                    var $newFirstRow = $remainingRows.first();
                    var $actionButtonsDiv = $newFirstRow.find('.abnormal-action-buttons');
                    $actionButtonsDiv.html(
                        '<button type="button" class="btn btn-warning btn-xs add-abnormal-row" title="新增一筆"><i class="fa fa-plus"></i></button> ' +
                        '<button type="button" class="btn btn-danger btn-xs remove-abnormal-row" title="刪除此筆"><i class="fa fa-minus"></i></button>');

                }
            });

            // --- Add/Remove OK Entry Rows ---
            $(document).on('click', '.add-ok-row', function() {
                var $thisButton = $(this);
                var $wrapper = $thisButton.closest('[id^="ok-rows-wrapper_"]');
                var currentRowCount = $wrapper.find('.ok-entry-row').length;

                var $currentRow = $thisButton.closest('.ok-entry-row');
                var $newRow = $currentRow.clone(true, true); // Clone the current row

                // Clear input values in the new row
                $newRow.find('input[type="number"].ok-qty-input').val(''); // Clear quantity
                $newRow.find('textarea.ok-remark-input').val(''); // Clear remark
                $newRow.find('input[name="qc_check_id[]"]').val(''); // Clear hidden qc_check_id if it exists
                $newRow.find('.ok-check-date').empty(); // Clear the date display for the new row


                // Change the plus button to a minus button in the new row
                var $actionButtonsDiv = $newRow.find('.ok-action-buttons');
                $actionButtonsDiv.empty(); // Clear existing buttons
                $actionButtonsDiv.append('<button type="button" class="btn btn-danger btn-xs remove-ok-row"><i class="fa fa-minus"></i></button>'); // Use btn-danger for remove

                // Append the new row
                $wrapper.append($newRow);
            });

            $(document).on('click', '.remove-ok-row', function() {
                var $thisButton = $(this);
                var $currentRow = $thisButton.closest('.ok-entry-row');
                var $wrapper = $currentRow.closest('[id^="ok-rows-wrapper_"]');

                var wasFirstRow = $currentRow.is(':first-child');

                $currentRow.remove();

                var $remainingRows = $wrapper.find('.ok-entry-row');
                if ($remainingRows.length === 0) {
                    // If all rows are removed, add a new blank row with both + and -
                    var bomIngFidForBlank = $wrapper.attr('id').replace('ok-rows-wrapper_', ''); // Corrected ID replacement
                    var sqtyForBlank = parseFloat($('#myModal_ok_' + bomIngFidForBlank).find('form').data('sqty')) || 0;
                    var blankRowHtml = `
                        <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                            <div style="width: 100px; margin-right: 10px; flex-shrink: 0;">
                                <input type="number" class="form-control ok-qty-input" name="ok_total_qty[]" value="${sqtyForBlank}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                <input type="hidden" name="qc_check_id[]" value="">
                            </div>
                            <div style="flex: 1; margin-right: 10px;">
                                <textarea rows="1" class="form-control ok-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="允收備註"></textarea>
                            </div>
                            <div style="width: 80px; margin-right: 10px; flex-shrink: 0; padding-top: 7px;" class="ok-check-date"></div>
                            <div style="width: 80px; flex-shrink: 0; text-align: left;" class="ok-action-buttons">
                                <button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button>
                                <button type="button" class="btn btn-danger btn-xs remove-ok-row" title="刪除此筆"><i class="fa fa-minus"></i></button><!-- Both buttons for the single blank row -->
                            </div>
                        </div>`;
                    $wrapper.append(blankRowHtml);
                } else if (wasFirstRow) {
                    var $newFirstRow = $remainingRows.first();
                    var $actionButtonsDiv = $newFirstRow.find('.ok-action-buttons');
                    $actionButtonsDiv.html(
                        '<button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button> ' +
                        '<button type="button" class="btn btn-danger btn-xs remove-ok-row" title="刪除此筆"><i class="fa fa-minus"></i></button>');

                }
            });

            // --- New Handler for QQ Modal's "清除並儲存" button ---
            $('#modals-container').on('click', '.clear-all-qq-entries-btn', function() {
                var $button = $(this);
                var $form = $button.closest('.modal-content').find('form');
                var actionUrl = $form.attr('action');
                var fullUrl = new URL(actionUrl, window.location.href);
                var bomIngId = fullUrl.searchParams.get("bi");
                var originalButtonText = $button.text();

                $button.prop('disabled', true).text('處理中...');

                $.ajax({
                    url: actionUrl, // This should be _updateQC_check_list_QQ.php
                    type: 'POST',
                    data: {
                        clear_remark_only: "1"
                    }, // Key flag for backend
                    dataType: 'json',
                    success: function(writeResponse) {
                        if (writeResponse.success) {
                            // Fetch full details for UI update
                            $.ajax({
                                url: '../../src/store/_fetch_qc_row_details.php',
                                type: 'GET',
                                data: {
                                    bi: bomIngId
                                },
                                dataType: 'json',
                                success: function(fetchResponse) {
                                    if (fetchResponse.success && fetchResponse.data) {
                                        var $targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                                        updateTableRowDOM($targetRow, fetchResponse.data);

                                        var itemIndex = window.allRawData.findIndex(item => item.bom_ing_fid === bomIngId);
                                        if (itemIndex > -1) {
                                            // Simplified update for allRawData; assumes fetchResponse.data structure matches needs
                                            Object.assign(window.allRawData[itemIndex], fetchResponse.data.bom_ing_details, {
                                                individual_qc_entries: fetchResponse.data.individual_qc_entries,
                                                total_qq_qty: fetchResponse.data.total_qq_qty,
                                                total_ok_qty: fetchResponse.data.total_ok_qty,
                                                latest_QQ_date_formatted: fetchResponse.data.latest_QQ_date_formatted,
                                                latest_ok_date_formatted: fetchResponse.data.latest_ok_date_formatted
                                            });
                                        }
                                        $form.closest('.modal').modal('hide');
                                        showTemporaryMessage(writeResponse.message || '異常紀錄已清除', 'success');
                                    } else {
                                        showTemporaryMessage('獲取更新數據失敗: ' + (fetchResponse.message || '未知錯誤'), 'error');
                                    }
                                },
                                error: function(jqXHR, textStatus, errorThrown) {
                                    showTemporaryMessage('獲取更新數據請求失敗: ' + textStatus, 'error');
                                }
                            });
                        } else {
                            showTemporaryMessage('清除失敗: ' + (writeResponse.message || '未知錯誤'), 'error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalButtonText);
                    }
                });
            });


            // --- Modified Handler for OK Modal's "清除並儲存" button ---
            $(document).on('click', '.clear-and-save-ok-btn', function() {
                var $button = $(this);
                var $modalContent = $button.closest('.modal-content');
                var $form = $modalContent.find('form');
                var originalButtonText = $button.text();

                // 清除所有相關的輸入欄位，作為操作的即時 UI 反饋。
                // 後端會處理實際的資料庫清除。
                $form.find('input.ok-qty-input').val('');
                $form.find('textarea.ok-remark-input').val('');
                $form.find('select[name="container[]"]').val(''); // ⭐ 重設容器下拉選單
                $form.find('input[name="quantity[]"]').val(''); // ⭐ 清除箱數輸入框

                $button.prop('disabled', true).text('處理中...');

                var formData = $form.serializeArray();
                formData.push({
                    name: "clear_remark_only",
                    value: "1"
                }); // Add flag

                $.ajax({
                    url: $form.attr('action'), // Action from the form attribute
                    type: 'POST',
                    data: $.param(formData),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            var actionUrl = $form.attr('action'); // Original action URL from the form
                            var fullUrl = new URL(actionUrl, window.location.href);
                            var bomIngId = fullUrl.searchParams.get("bi");

                            // Fetch full details for UI update
                            $.ajax({
                                url: '../../src/store/_fetch_qc_row_details.php',
                                type: 'GET',
                                data: {
                                    bi: bomIngId
                                },
                                dataType: 'json',
                                success: function(fetchResponse) {
                                    if (fetchResponse.success && fetchResponse.data) {
                                        var $targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                                        updateTableRowDOM($targetRow, fetchResponse.data);

                                        var itemIndex = window.allRawData.findIndex(item => item.bom_ing_fid === bomIngId);
                                        if (itemIndex > -1) {
                                            Object.assign(window.allRawData[itemIndex], fetchResponse.data.bom_ing_details, {
                                                individual_qc_entries: fetchResponse.data.individual_qc_entries,
                                                total_qq_qty: fetchResponse.data.total_qq_qty,
                                                total_ok_qty: fetchResponse.data.total_ok_qty,
                                                latest_QQ_date_formatted: fetchResponse.data.latest_QQ_date_formatted,
                                                latest_ok_date_formatted: fetchResponse.data.latest_ok_date_formatted
                                            });
                                        }
                                        $form.closest('.modal').modal('hide');
                                        showTemporaryMessage(response.message || '允收紀錄已清除', 'success'); // Use message from original POST
                                    } else {
                                        showTemporaryMessage('獲取更新數據失敗: ' + (fetchResponse.message || '未知錯誤'), 'error');
                                    }
                                },
                                error: function(jqXHR, textStatus, errorThrown) {
                                    showTemporaryMessage('獲取更新數據請求失敗: ' + textStatus, 'error');
                                }
                            });
                        } else {
                            showTemporaryMessage('清除失敗: ' + (response.message || '未知錯誤'), 'error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalButtonText);
                    }
                });
            });

            // --- Auto-update Logic ---
            // Start the auto-update interval
            autoUpdateIntervalId = setInterval(fetchAndUpdateData, AUTO_UPDATE_INTERVAL_MS);
            console.log('[Auto-Update] 自動更新已啟動，每 ' + (AUTO_UPDATE_INTERVAL_MS / 1000) + ' 秒檢查一次。');

            // Pause auto-update when a modal is shown
            $(document).on('show.bs.modal', '.modal', function() {
                console.log('[Auto-Update] 偵測到 Modal 開啟，暫停自動更新。');
                autoUpdatePaused = true;
            });
            // --- 新增(未在列表上) Modal 相關邏輯 ---

            // 開啟 modal 前清除舊資料
            $(document).on('click', '#btn-add-custom', function() {
                var $modal = $('#myModal_reply_custom');
                $modal.find('#input-bom-query').val('');
                $modal.find('[name=clientName], [name=dId], [name=sqty], [name=ps]').val('');
                $modal.find('#select-bom-ing').html('<option value="">請先更新BOM</option>');
                customBomData = null; // 清除暫存資料
            });

            // 更新 BOM 資料
            $(document).on('click', '#btn-bom-update', function() {
                const bom = $('#input-bom-query').val().trim();
                if (!/^B-\d{10}$/.test(bom)) {
                    alert('請輸入格式正確的 BOM：B-後接10位數字');
                    return;
                }
                $.ajax({
                    url: '../../src/store/_get_bom_data.php',
                    method: 'POST',
                    data: {
                        bom: bom
                    },
                    dataType: 'json'
                }).done(function(data) {
                    if (!data.success) {
                        customBomData = null; // 清除資料
                        alert(data.message || '查無資料');
                        return;
                    }
                    customBomData = data; // ⭐ 暫存BOM資料

                    var $modal = $('#myModal_reply_custom');
                    $modal.find('[name=clientName]').val(data.Client_Name);
                    $modal.find('[name=dId]').val(data.d_id);

                    const $sel = $modal.find('#select-bom-ing').empty();
                    if (data.processes && data.processes.length > 0) {
                    // Helper function to calculate visual width (CJK characters as 2, ASCII as 1)
                        const getVisualLength = (str) => {
                            if (!str) return 0;
                            return str.replace(/[^\x00-\xff]/g, "aa").length;
                        };

                        // 1. Find the maximum length of the process part for alignment
                        let maxProcessLength = 0;
                        data.processes.forEach(item => {
                            const processPart = `[${item.bom_sn}] ${item.process_no} ${item.ProcessName}`;
                            const len = getVisualLength(processPart);
                            if (len > maxProcessLength) {
                                maxProcessLength = len;
                            }
                        });

                        // 2. Build and append options with padding and correct maker info
                        
                        data.processes.forEach(function(item) {
                            const processPart = `[${item.bom_sn}] ${item.process_no} ${item.ProcessName}`;
                            const currentLength = getVisualLength(processPart);
                            const paddingCount = maxProcessLength > currentLength ? maxProcessLength - currentLength : 0;
                            const padding = '&nbsp;'.repeat(paddingCount);

                            // Use maker name (maker_id) if available, otherwise use maker number (maker_id_no)
                            const makerDisplay = (item.maker_id && String(item.maker_id).trim() !== '') 
                                                 ? item.maker_id 
                                                 : (item.maker_id_no || '');

                            let displayText = he(processPart) + padding;
                            if (makerDisplay) displayText += '　' + he(makerDisplay); // Full-width space

                            $sel.append($('<option>').val(he(item.bom_ing_fid)).html(displayText));
                        });
                    } else {
                        $sel.append('<option value="">此BOM無製程資料</option>');
                    }
                }).fail(function() {
                    customBomData = null; // 清除資料
                    alert('伺服器錯誤，無法取得BOM資料。');
                });
            });

            // 在 myModal_reply_custom 中，更新「異常」「允收」按鈕觸發邏輯
            $(document).on('click', '#myModal_reply_custom .btn-option-abnormal, #myModal_reply_custom .btn-option-accept', function() {
                var type = $(this).hasClass('btn-option-abnormal') ? 'qq' : 'ok';
                var selectedFid = $('#select-bom-ing').val();

                if (!selectedFid) {
                    alert('請先選擇製程');
                    return;
                }

                var targetModalId = '#myModal_' + type + '_' + selectedFid;

                // 檢查目標彈窗是否已存在於 DOM 中
                if ($(targetModalId).length > 0) {
                    // 如果存在，直接顯示
                    $(targetModalId).modal('show');
                    $('#myModal_reply_custom').modal('hide');
                } else {
                    // 如果不存在，則動態建立
                    if (!customBomData || !customBomData.success) {
                        alert('無法建立彈窗，因為沒有有效的BOM資料。請先點擊「更新」。');
                        return;
                    }

                    // 從暫存資料中找到選擇的製程
                    // ⭐ 修正點 1: 將兩邊都轉為字串來比對，避免類型問題 (e.g., 123 === "123" -> false)
                    var selectedProcess = customBomData.processes.find(p => String(p.bom_ing_fid) === String(selectedFid));
                    if (!selectedProcess) {
                        alert('找不到所選製程的詳細資料。');
                        return;
                    }

                    // ⭐ 修正點 2: 直接使用從後端獲取的完整 selectedProcess 物件來建立彈窗
                    // 這個物件已經包含了所有需要的 QC 欄位和 individual_qc_entries
                    var newItemData = selectedProcess;

                    var newModalsHtml = generateModalsForItem(newItemData);
                    $('#modals-container').append(newModalsHtml);

                    // ── 在下面這裡插入 A 解法的綁定呼叫 ──
                    var $newForm = $(targetModalId).find('form');
                    if ($newForm.attr('action').includes('_updateQC_check_list_QQ.php')) {
                        handleModalFormSubmit($newForm, qqCallback);
                    } else {
                        handleModalFormSubmit($newForm, okCallback);
                    }

                    $(targetModalId).modal('show');

                    $('body').tooltip({
                        selector: '[data-toggle="tooltip"]'
                    });
                    $('#myModal_reply_custom').modal('hide');
                }
            });

            // ⭐ 新增：當「完成」Modal 顯示時，動態計算並填入內容
            $(document).on('show.bs.modal', '[id^="myModal_complete_"]', function() {
                var modal = $(this);
                var bomIngFid = modal.data('bom-ing-fid');
                var modalBody = modal.find('.modal-body');

                // 從 allRawData 中找到對應的資料
                var itemData = allRawData.find(item => String(item.bom_ing_fid) === String(bomIngFid));

                if (!itemData) {
                    modalBody.html('<p class="text-danger">找不到資料。</p>');
                    return;
                }

                // 獲取數量
                var totalQty = parseFloat(itemData.sqty) || 0;
                var abnormalQty = parseFloat(itemData.QC_QQ_sqty) || 0;
                var acceptedQty = parseFloat(itemData.QC_ok_sqty) || 0;
                var shortage = totalQty - abnormalQty - acceptedQty;

                // 建立要顯示的 HTML 字串
                var contentHtml = `總數 ${totalQty}`;

                if (abnormalQty > 0) {
                    contentHtml += ` - <span style="color: #f0ad4e; font-weight: bold;">異常 x${abnormalQty}</span>`;
                }

                if (acceptedQty > 0) {
                    contentHtml += ` - <span style="color: green; font-weight: bold;">允收 x${acceptedQty}</span>`;
                }

                contentHtml += ' = ';

                if (shortage <= 0) {
                    contentHtml += '<span style="color: green; font-weight: bold;">已全部檢驗</span>';
                } else {
                    contentHtml += `<span style="color: red; font-weight: bold;">短缺 x${shortage}</span>`;
                }

                modalBody.html(`<p>${contentHtml}</p>`);
            });

            // ⭐ 新增：處理「確認完成」按鈕點擊事件
            $(document).on('click', '.btn-confirm-completion', function() {
                var $button = $(this);
                var modal = $button.closest('.modal');
                var bomIngFid = modal.data('bom-ing-fid');

                if (!confirm('您確定要完成此筆檢驗嗎？此操作將更新狀態並從清單中移除。')) {
                    return;
                }

                $button.prop('disabled', true).text('處理中...');

                $.ajax({
                    url: '../../src/store/_update_qc_completion.php',
                    type: 'POST',
                    data: {
                        bom_ing_fid: bomIngFid
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // 1. 先關閉 modal
                            modal.modal('hide');
                            // 2. 監聽 'hidden.bs.modal' 事件，確保 modal 完全關閉後才更新表格
                            modal.one('hidden.bs.modal', function () {
                                alert(response.message);
                                allRawData = allRawData.filter(item => String(item.bom_ing_fid) !== String(bomIngFid));
                                populateTableWithData(allRawData);
                            });
                        } else {
                            alert('更新失敗: ' + response.message);
                            $button.prop('disabled', false).text('確認完成');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert('請求失敗: ' + textStatus);
                        $button.prop('disabled', false).text('確認完成');
                        console.error("Error on completion:", textStatus, errorThrown);
                    }
                });
            });

            // ⭐ 新增：使用事件委派處理所有（包括動態新增的）允收/異常等 modal 表單提交
            // 這個處理程序會監聽整個頁面，捕捉所有符合 'form[action*="_updateQC_check_list_"]' 選擇器的表單提交事件
            // ── 全域委派 submit 事件，攔截所有動態/靜態的 _updateQC_check_list_ 表單
            $(document).on('submit', 'form[action*="_updateQC_check_list_"]', function(e) {
                e.preventDefault(); // 阻止預設跳頁
                var $form = $(this);

                $.ajax({
                        url: $form.attr('action'),
                        type: 'POST',
                        data: $form.serialize(),
                        dataType: 'json'
                    })
                    .done(function(res) {
                        // TODO：把這行換成你自己的更新表格函式
                        updateTableRowDOM(res.data);
                        // 關閉 modal
                        $form.closest('.modal').modal('hide');
                    })
                    .fail(function(xhr) {
                        alert('儲存失敗：' + xhr.responseText);
                    });
            });

        });
    </script>

    <!-- 更新後的腳本，用於防止 "QC設定(QC)" 選單在特定頁面自動展開 -->
    <script>
        $(document).ready(function() {
            // 稍微增加延遲，確保 custom.min.js 中的 init_sidebar() 已完全執行
            setTimeout(function() {
                var currentPath = window.location.pathname;
                var pageSpecificLogic = false;

                // 檢查目前頁面是否為 QC_check_list.php 或 QC_check_list2.php
                if (currentPath.endsWith('/QC_check_list.php') || currentPath.endsWith('/QC_check_list2.php')) {
                    pageSpecificLogic = true;
                }

                if (pageSpecificLogic) {
                    // 尋找 "QC設定(QC)" 的父層 <li> 元素
                    var $qcSettingsLi = $('#sidebar-menu .nav.side-menu > li').filter(function() {
                        var linkText = $(this).children('a').clone().children().remove().end().text().trim();
                        return linkText.includes("QC設定(QC)");
                    });

                    if ($qcSettingsLi.length > 0) {
                        // 移除 active 狀態 class
                        $qcSettingsLi.removeClass('active active-sm');

                        // 明確收起其子選單 (ul.child_menu)
                        var $childMenu = $qcSettingsLi.children('ul.child_menu');
                        if ($childMenu.length > 0 && $childMenu.is(':visible')) {
                            $childMenu.slideUp(0); // 立即收起
                        }
                    }
                }
            }, 200); // 將延遲增加到 200 毫秒，可以根據需要微調
        });

        // 顯示允收內容器與箱數資料
        $(document).on('show.bs.modal', '[id^="myModal_ok_"]', function(e) {
            var modal = $(this);
            var bomIngFid = modal.attr('id').replace('myModal_ok_', '');
            $.getJSON('../../src/store/_get_qcps.php', {
                bi: bomIngFid
            }, function(data) {
                fillOkContainerRow(modal.find('.form-group').eq(0), data.QC_ps);
                fillOkContainerRow(modal.find('.form-group').eq(1), data.QC_ps2);
            });
        });

        function fillOkContainerRow($row, val) {
            if (!val) return;
            // 解析「數量+縮寫」格式，如 "1P"
            var match = val.match(/^(\d+)([\u4e00-\u9fa5A-Za-z]+)/);
            if (!match) return;
            var qtyVal = match[1];
            var abbr = match[2];
            // 直接設定縮寫作為 select value
            $row.find('select[name="container[]"]').val(abbr);
            $row.find('input[name="quantity[]"]').val(qtyVal);
        }


        // 設定自動AJAX，每5秒輪詢一次（正式環境建議60秒）
        setInterval(function() {
            fetchAndUpdateData(); // 只呼叫這個就夠了
        }, 5000);
    </script>
    <!-- === 請貼入 QC_check_list copy(自動更新改一半).php 頁面底部 (放於 </body> 前) === -->

    <!-- 1. 觸發按鈕示例：於表格列或新增按鈕處加入 data-action 與必要參數 -->


    <!-- 2. AJAX 事件委派及 Modal 載入流程 -->
    <script>
        $(function() {
            // 攔截所有 動態新增的 QC 表單 提交，並以 AJAX 送出
            $(document).on('submit', '.qc-modal-form', function(e) {
                e.preventDefault();
                var $form = $(this);
                var actionUrl = $form.attr('action');
                var formData = $form.serialize();

                $.post(actionUrl, formData, function(response) {
                    if (response.success) {
                        // 依照回傳更新對應 table row
                        updateTableRow(response.individual_qc_entries || response.data);
                        // 關閉 Modal
                        $form.closest('.modal').modal('hide');
                    } else {
                        alert(response.message || 'QC 更新失敗');
                    }
                }, 'json').fail(function() {
                    alert('伺服器錯誤，請稍後再試');
                });
            });

            // 點擊按鈕打開 QC Modal 並載入對應後端
            $(document).on('click', '[data-action="open-qc-modal"]', function() {
                var type = $(this).data('type'); // ok 或 ng
                var bi = $(this).data('bi'); // bom_ing_fid
                var uid = $(this).data('uid'); // 使用者 ID
                var url = '<?= $basePath ?>/src/store/_updateQC_check_list_' + type + '.php?bi=' + bi + '&id=' + uid;

                // 顯示載入中指示，並動態替換內容
                $('#qcModal .modal-content').html(
                    '<div class="modal-body text-center p-4">' +
                    '  <div class="spinner-border" role="status"><span class="sr-only">載入中...</span></div>' +
                    '</div>'
                ).load(url, function(response, status) {
                    if (status === 'error') {
                        $('#qcModal .modal-content').html(
                            '<div class="modal-body text-danger p-3">載入失敗，請重試</div>'
                        );
                    } else {
                        // 為動態載入的 <form> 標籤加上 class
                        var $form = $(this).find('form');
                        $form.addClass('qc-modal-form');
                        // 將所有 <button> 設為 submit
                        $form.find('button').attr('type', 'submit');
                    }
                    $('#qcModal').modal('show');
                });
            });
        });

        // 更新表格列輔助函式 (需依照實際欄位做修改)
        function updateTableRow(data) {
            // data 裡通常帶回 qc_check_id, processing_state, QC 數量等
            var $row = $('#row-' + data.qc_check_id);
            if (!$row.length) return;
            $row.find('.qc-status').text(data.processing_state);
            $row.find('.qc-qty').text(data.total_ok_qty || data.total_qq_qty || '');
            // ... 更多欄位更新 ...
        }
    </script>

    <!-- 3. 全站共用 QC Modal 結構 (放於 </body> 前) -->
    <div id="qcModal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <!-- 初始內容：載入中指示器 -->
                <div class="modal-body text-center p-4">
                    <div class="spinner-border" role="status">
                        <span class="sr-only">載入中...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>

</html>