<?php
// EGsystem/views/pm/kpi_shift_setting.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

include_once '../../src/common/DBConnection.php';
$conn   = new DBConnection();
$pdo    = $conn->getPDO();
$userId = intval($_SESSION['id'] ?? 0);

$PAGE_PERM = 'A';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $pc = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='kpi' LIMIT 1");
        $pc->execute([$userId]);
        $pr = $pc->fetch(PDO::FETCH_ASSOC);
        if ($pr && !empty($pr['permission'])) $PAGE_PERM = $pr['permission'];
    } catch(Exception $e) { $PAGE_PERM = 'A'; }
}
$is_admin = (strpos($PAGE_PERM,'A') !== false);

function safe($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// ══ AJAX ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $a = $_POST['action'];

    if ($a === 'get_shift_types') {
        try { echo json_encode(['success'=>true,'data'=>$pdo->query("SELECT * FROM shift_type WHERE is_active=1 ORDER BY sort_order,shift_type_id")->fetchAll(PDO::FETCH_ASSOC)]); }
        catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($a === 'save_shift_type') {
        if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'無權限']); exit; }
        $id=intval($_POST['shift_type_id']??0); $name=trim($_POST['shift_name']??''); $code=trim($_POST['shift_code']??'');
        $start=trim($_POST['start_time']??''); $end=trim($_POST['end_time']??''); $over=intval($_POST['is_overnight']??0);
        $brk=intval($_POST['break_minutes']??0); $color=trim($_POST['color']??''); $desc=trim($_POST['description']??''); $sort=intval($_POST['sort_order']??0);
        if(!$name||!$code||!$start||!$end){ echo json_encode(['success'=>false,'message'=>'必填欄位不得空白']); exit; }
        try {
            if($id){ $pdo->prepare("UPDATE shift_type SET shift_name=?,shift_code=?,start_time=?,end_time=?,is_overnight=?,break_minutes=?,color=?,description=?,sort_order=?,updated_by=? WHERE shift_type_id=?")->execute([$name,$code,$start,$end,$over,$brk,$color,$desc,$sort,$userId,$id]); }
            else { $pdo->prepare("INSERT INTO shift_type (shift_name,shift_code,start_time,end_time,is_overnight,break_minutes,color,description,sort_order,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$name,$code,$start,$end,$over,$brk,$color,$desc,$sort,$userId]); $id=$pdo->lastInsertId(); }
            echo json_encode(['success'=>true,'shift_type_id'=>$id]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($a === 'delete_shift_type') {
        if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'無權限']); exit; }
        try { $pdo->prepare("UPDATE shift_type SET is_active=0 WHERE shift_type_id=?")->execute([intval($_POST['shift_type_id']??0)]); echo json_encode(['success'=>true]); }
        catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($a === 'get_schedules') {
        $uid=intval($_POST['user_id']??0); $did=intval($_POST['dept_id']??0);
        try {
            $sql="SELECT ss.*,u.user_cname,st.shift_name,st.color,
                COALESCE(d.name,'') AS dept_name, COALESCE(pos.name,'') AS pos_name
                FROM shift_schedule ss
                JOIN user u ON u.id=ss.user_id
                JOIN shift_type st ON st.shift_type_id=ss.shift_type_id
                LEFT JOIN user_department_position_map udm ON udm.user_id=u.id AND udm.is_main=1
                LEFT JOIN department d ON d.id=udm.department_id
                LEFT JOIN position pos ON pos.id=udm.position_id
                WHERE 1=1";
            $p=[];
            if($uid){$sql.=" AND ss.user_id=?";$p[]=$uid;}
            if($did){$sql.=" AND udm.department_id=?";$p[]=$did;}
            $sql.=" ORDER BY d.name, u.user_cname, ss.effective_from DESC LIMIT 200";
            $st=$pdo->prepare($sql);$st->execute($p);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($a === 'save_schedule') {
        if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'無權限']); exit; }
        $id=intval($_POST['schedule_id']??0); $uid=intval($_POST['user_id']??0); $stid=intval($_POST['shift_type_id']??0);
        $cycle=trim($_POST['cycle_type']??'weekly'); $wds=trim($_POST['weekdays']??''); $mdays=trim($_POST['month_days']??'');
        $from=trim($_POST['effective_from']??''); $to=trim($_POST['effective_to']??'')?:null; $rem=trim($_POST['remark']??'');
        if(!$uid||!$stid||!$from){echo json_encode(['success'=>false,'message'=>'必填欄位不得空白']);exit;}
        try {
            if($id){ $pdo->prepare("UPDATE shift_schedule SET user_id=?,shift_type_id=?,cycle_type=?,weekdays=?,month_days=?,effective_from=?,effective_to=?,remark=?,updated_by=? WHERE schedule_id=?")->execute([$uid,$stid,$cycle,$wds,$mdays,$from,$to,$rem,$userId,$id]); }
            else { $pdo->prepare("INSERT INTO shift_schedule (user_id,shift_type_id,cycle_type,weekdays,month_days,effective_from,effective_to,remark,created_by) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$uid,$stid,$cycle,$wds,$mdays,$from,$to,$rem,$userId]); $id=$pdo->lastInsertId(); }
            echo json_encode(['success'=>true,'schedule_id'=>$id]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($a === 'delete_schedule') {
        if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'無權限']); exit; }
        try { $pdo->prepare("DELETE FROM shift_schedule WHERE schedule_id=?")->execute([intval($_POST['schedule_id']??0)]); echo json_encode(['success'=>true]); }
        catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($a === 'get_exceptions') {
        $uid=intval($_POST['user_id']??0); $ym=trim($_POST['ym']??date('Y-m'));
        try {
            $from=$ym.'-01'; $to=date('Y-m-t',strtotime($from));
            $sql="SELECT se.*,u.user_cname,st.shift_name,st.color FROM shift_exception se JOIN user u ON u.id=se.user_id LEFT JOIN shift_type st ON st.shift_type_id=se.shift_type_id WHERE se.exception_date BETWEEN ? AND ?";
            $p=[$from,$to]; if($uid){$sql.=" AND se.user_id=?";$p[]=$uid;} $sql.=" ORDER BY se.exception_date,u.user_cname";
            $st=$pdo->prepare($sql);$st->execute($p);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($a === 'save_exception') {
        if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'無權限']); exit; }
        $id=intval($_POST['exception_id']??0); $uid=intval($_POST['user_id']??0); $date=trim($_POST['exception_date']??'');
        $stid=intval($_POST['shift_type_id']??0)?:null; $cs=trim($_POST['custom_start']??'')?:null; $ce=trim($_POST['custom_end']??'')?:null;
        $cb2=trim($_POST['custom_break']??'');$cb2=$cb2!==''?intval($cb2):null; $etype=trim($_POST['exception_type']??'shift_change'); $rem=trim($_POST['remark']??'');
        if(!$uid||!$date){echo json_encode(['success'=>false,'message'=>'員工與日期必填']);exit;}
        try {
            if($id){ $pdo->prepare("UPDATE shift_exception SET user_id=?,exception_date=?,shift_type_id=?,custom_start=?,custom_end=?,custom_break=?,exception_type=?,remark=?,updated_by=? WHERE exception_id=?")->execute([$uid,$date,$stid,$cs,$ce,$cb2,$etype,$rem,$userId,$id]); }
            else { $pdo->prepare("INSERT INTO shift_exception (user_id,exception_date,shift_type_id,custom_start,custom_end,custom_break,exception_type,remark,created_by) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$uid,$date,$stid,$cs,$ce,$cb2,$etype,$rem,$userId]); $id=$pdo->lastInsertId(); }
            echo json_encode(['success'=>true,'exception_id'=>$id]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if ($a === 'delete_exception') {
        if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'無權限']); exit; }
        try { $pdo->prepare("DELETE FROM shift_exception WHERE exception_id=?")->execute([intval($_POST['exception_id']??0)]); echo json_encode(['success'=>true]); }
        catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

$user_list = $pdo->query("
    SELECT u.id, u.user_cname,
        COALESCE(d.name,'') AS dept_name, COALESCE(pos.name,'') AS pos_name,
        COALESCE(dept.id,0) AS dept_id
    FROM user u
    LEFT JOIN user_department_position_map udm ON udm.user_id=u.id AND udm.is_main=1
    LEFT JOIN department d ON d.id=udm.department_id
    LEFT JOIN position pos ON pos.id=udm.position_id
    LEFT JOIN department dept ON dept.id=udm.department_id
    WHERE u.state=1 ORDER BY d.name, u.user_cname
")->fetchAll(PDO::FETCH_ASSOC);
$shift_types = [];
try { $shift_types = $pdo->query("SELECT * FROM shift_type WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
$dept_list = [];
try { $dept_list = $pdo->query("SELECT id,name FROM department WHERE parent_id IS NOT NULL ORDER BY level,sort_order,name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>班別排班設定</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--primary:#2A3F54;--accent:#1ABB9C;--warn:#F39C12;--danger:#E74C3C;--info:#3498DB;--purple:#9B59B6;--bg:#F4F7FC;--card:#fff;--border:#E6E9ED;--text:#495057}
body{background:var(--bg);font-family:"Segoe UI","Roboto",Arial,sans-serif;color:var(--text)}
.right_col{background:var(--bg)!important;overflow-x:hidden!important;max-width:100%;box-sizing:border-box;}
html,body,.main_container,.container.body{overflow-x:hidden!important;}
.pg-header{display:flex;align-items:center;justify-content:space-between;background:var(--card);border-radius:10px;padding:13px 20px;margin-bottom:14px;box-shadow:0 2px 6px rgba(0,0,0,.06);flex-wrap:wrap;gap:8px;}
.pg-header h3{margin:0;font-size:19px;font-weight:700;color:var(--primary)}
.tab-sw{display:flex;gap:4px;background:#eef1f5;border-radius:8px;padding:4px}
.tab-btn{border:none;background:transparent;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;color:#888;cursor:pointer;transition:all .2s}
.tab-btn.active{background:var(--card);color:var(--primary);box-shadow:0 2px 5px rgba(0,0,0,.1)}
.tab-pane{display:none}
.tab-pane.active{display:block}
.fbar{background:var(--card);border-radius:10px;padding:10px 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;box-shadow:0 2px 6px rgba(0,0,0,.05);margin-bottom:14px}
.fbar .form-control,.fbar .btn{height:33px;font-size:13px}
.fbar .fg{display:flex;flex-direction:column;}
.fbar label{font-size:11px;font-weight:700;color:var(--primary);margin-bottom:2px;text-transform:uppercase;}
.setting-card{background:var(--card);border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,.05);padding:16px;margin-bottom:14px}
.setting-card h5{font-weight:700;color:var(--primary);margin-bottom:12px;font-size:14px;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid var(--accent);padding-bottom:8px;}
.mc table{width:100%;border-collapse:collapse;font-size:12px;}
.mc table thead th{background:#f8f9fa;color:#555;font-weight:700;padding:9px 9px;border-bottom:2px solid var(--border);white-space:nowrap;}
.mc table tbody td{padding:7px 9px;border-bottom:1px solid #f0f2f5;vertical-align:middle;}
.mc table tbody tr:hover{background:#FAFBFF!important}
.mc{background:var(--card);border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,.05);overflow:hidden;margin-bottom:14px;}
/* 班別 badge */
.shift-chip{display:inline-block;border-radius:4px;padding:2px 9px;font-size:11px;font-weight:700;color:#fff;}
/* 星期按鈕 */
.wd-btn{display:inline-block;border:1.5px solid var(--border);border-radius:4px;padding:3px 9px;font-size:12px;cursor:pointer;transition:.12s;user-select:none;font-weight:600;color:#555;}
.wd-btn.on{background:var(--primary);color:#fff;border-color:var(--primary);}
/* 例外類型 */
.etype-chip{display:inline-block;border-radius:4px;padding:2px 8px;font-size:10px;font-weight:700;color:#fff;}
/* Modal */
.modal-header{background:var(--primary);color:#fff;border-radius:6px 6px 0 0}
.modal-header .modal-title{font-weight:700}
.modal-header .close{color:#fff;opacity:1}
.modal-content{display:flex!important;flex-direction:column!important;max-height:90vh!important;}
.modal-body{overflow-y:auto!important;flex:1 1 auto!important;}
.modal-footer,.modal-header{flex-shrink:0!important;}
label{font-size:13px;font-weight:600;color:var(--primary);margin-bottom:3px}
.form-control{font-size:13px}
/* Toast */
#toast-wrap{position:fixed;bottom:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px}
.toast-msg{padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.2);color:#fff;animation:toastIn .2s ease}
.toast-msg.success{background:var(--accent)}.toast-msg.error{background:var(--danger)}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:768px){.fbar{flex-direction:column;align-items:stretch}}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

<!-- 頁頭 -->
<div class="pg-header">
  <div style="display:flex;align-items:center;gap:12px;">
    <h3><i class="fa fa-calendar" style="color:var(--accent);margin-right:8px;"></i>班別排班設定</h3>
    <a href="kpi_main.php" class="btn btn-default btn-sm" style="font-weight:600;"><i class="fa fa-bar-chart"></i> 返回 KPI</a>
  </div>
  <div class="tab-sw">
    <button class="tab-btn active" onclick="switchTab('shifts',this)">🕐 班別定義</button>
    <button class="tab-btn" onclick="switchTab('schedule',this)">📅 人員排班</button>
    <button class="tab-btn" onclick="switchTab('exception',this)">⚡ 例外日</button>
  </div>
</div>

<!-- ══ TAB：班別定義 ══════════════════════════════════════════ -->
<div id="tab-shifts" class="tab-pane active">
  <div class="setting-card">
    <h5><i class="fa fa-clock-o" style="color:var(--accent);margin-right:6px;"></i>班別定義
      <button class="btn btn-success btn-sm" onclick="openShiftModal(0)" style="font-weight:600;"><i class="fa fa-plus"></i> 新增班別</button>
    </h5>
    <div style="overflow-x:auto;">
      <table class="mc table">
        <thead><tr><th>班別名稱</th><th>代碼</th><th>開始</th><th>結束</th><th>跨夜</th><th>休息(分)</th><th>有效工時</th><th>說明</th><th width="80"></th></tr></thead>
        <tbody id="shifts-tbody"><tr><td colspan="9" style="text-align:center;padding:30px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ TAB：人員排班 ══════════════════════════════════════════ -->
<div id="tab-schedule" class="tab-pane">
  <div class="fbar">
    <div class="fg"><label>篩選部門</label>
      <select id="sch-dept" class="form-control" style="width:120px;" onchange="loadSchedules()">
        <option value="">全部部門</option>
        <?php foreach($dept_list as $d): ?><option value="<?=safe($d['id'])?>"><?=safe($d['name'])?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label>篩選人員</label>
      <select id="sch-user" class="form-control" style="width:150px;" onchange="loadSchedules()">
        <option value="">全部人員</option>
        <?php foreach($user_list as $u):
          $lbl = $u['user_cname'].($u['dept_name']?' ('.$u['dept_name'].')':'');
        ?><option value="<?=safe($u['id'])?>"><?=safe($lbl)?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="fg" style="justify-content:flex-end;"><label style="opacity:0;">.</label>
      <button class="btn btn-success btn-sm" onclick="openSchModal(0)" style="font-weight:600;"><i class="fa fa-plus"></i> 新增排班</button>
    </div>
  </div>
  <div class="setting-card">
    <h5><i class="fa fa-users" style="color:var(--accent);margin-right:6px;"></i>人員週期排班</h5>
    <div style="overflow-x:auto;">
      <table class="mc table">
        <thead><tr><th>人員</th><th>部門/職稱</th><th>班別</th><th>週期</th><th>設定內容</th><th>生效起</th><th>生效迄</th><th>備註</th><th width="70"></th></tr></thead>
        <tbody id="sch-tbody"><tr><td colspan="9" style="text-align:center;padding:30px;color:#aaa;">請切換此頁載入資料</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ TAB：例外日 ════════════════════════════════════════════ -->
<div id="tab-exception" class="tab-pane">
  <div class="fbar">
    <div class="fg"><label>年月</label><input type="month" id="exc-ym" class="form-control" value="<?=date('Y-m')?>" style="width:145px;" onchange="loadExceptions()"></div>
    <div class="fg"><label>篩選部門</label>
      <select id="exc-dept" class="form-control" style="width:120px;" onchange="loadExceptions()">
        <option value="">全部部門</option>
        <?php foreach($dept_list as $d): ?><option value="<?=safe($d['id'])?>"><?=safe($d['name'])?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label>篩選人員</label>
      <select id="exc-user" class="form-control" style="width:150px;" onchange="loadExceptions()">
        <option value="">全部人員</option>
        <?php foreach($user_list as $u):
          $exc_lbl = $u['user_cname'].($u['dept_name']?' ('.$u['dept_name'].')':'');
        ?><option value="<?=safe($u['id'])?>"><?=safe($exc_lbl)?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="fg" style="justify-content:flex-end;"><label style="opacity:0;">.</label>
      <button class="btn btn-success btn-sm" onclick="openExcModal(0)" style="font-weight:600;"><i class="fa fa-plus"></i> 新增例外日</button>
    </div>
  </div>
  <div class="setting-card">
    <h5><i class="fa fa-exclamation-circle" style="color:var(--warn);margin-right:6px;"></i>班別例外日
      <small class="text-muted" style="font-size:12px;font-weight:400;">覆蓋週期排班（臨時加班、換班、休假等）</small>
    </h5>
    <div style="overflow-x:auto;">
      <table class="mc table">
        <thead><tr><th>日期</th><th>人員</th><th>類型</th><th>班別</th><th>自訂時間</th><th>休息(分)</th><th>備註</th><th width="70"></th></tr></thead>
        <tbody id="exc-tbody"><tr><td colspan="8" style="text-align:center;padding:30px;color:#aaa;">請切換此頁載入資料</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- /right_col -->
</div></div><!-- /main_container /container body -->

<!-- ══ 班別 Modal ══════════════════════════════════════════════ -->
<div class="modal fade" id="shift-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">班別設定</h4></div>
      <div class="modal-body">
        <input type="hidden" id="sm-id">
        <div class="row">
          <div class="col-sm-5"><div class="form-group"><label>班別名稱 <span class="text-danger">*</span></label><input type="text" class="form-control" id="sm-name" placeholder="例：日班"></div></div>
          <div class="col-sm-3"><div class="form-group"><label>代碼 <span class="text-danger">*</span></label><input type="text" class="form-control" id="sm-code" placeholder="例：D" maxlength="10"></div></div>
          <div class="col-sm-4"><div class="form-group"><label>顏色（前端顯示）</label><input type="color" class="form-control" id="sm-color" value="#3498DB" style="height:34px;padding:2px 4px;cursor:pointer;"></div></div>
        </div>
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label>開始時間 <span class="text-danger">*</span></label><input type="time" class="form-control" id="sm-start"></div></div>
          <div class="col-sm-4"><div class="form-group"><label>結束時間 <span class="text-danger">*</span></label><input type="time" class="form-control" id="sm-end"></div></div>
          <div class="col-sm-4"><div class="form-group"><label>跨夜班</label>
            <select class="form-control" id="sm-overnight"><option value="0">否</option><option value="1">是（結束為隔日）</option></select>
          </div></div>
        </div>
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label>休息扣除（分鐘）</label><input type="number" class="form-control" id="sm-break" min="0" value="60"></div></div>
          <div class="col-sm-3"><div class="form-group"><label>排序</label><input type="number" class="form-control" id="sm-sort" min="0" value="0"></div></div>
        </div>
        <div class="form-group"><label>說明</label><input type="text" class="form-control" id="sm-desc"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-success" onclick="saveShift()" style="font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 排班 Modal ══════════════════════════════════════════════ -->
<div class="modal fade" id="sch-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">人員排班設定</h4></div>
      <div class="modal-body">
        <input type="hidden" id="sch-id">
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>人員 <span class="text-danger">*</span></label>
            <select class="form-control" id="sch-uid">
              <option value="">— 請選擇 —</option>
              <?php foreach($user_list as $u):
                $opt_lbl = $u['user_cname'].($u['dept_name']?' ['.$u['dept_name'].($u['pos_name']?' · '.$u['pos_name']:'').']':'');
              ?><option value="<?=safe($u['id'])?>"><?=safe($opt_lbl)?></option><?php endforeach; ?>
            </select>
          </div></div>
          <div class="col-sm-6"><div class="form-group"><label>班別 <span class="text-danger">*</span></label>
            <select class="form-control" id="sch-stid">
              <?php foreach($shift_types as $st): ?><option value="<?=safe($st['shift_type_id'])?>"><?=safe($st['shift_name'])?></option><?php endforeach; ?>
            </select>
          </div></div>
        </div>
        <div class="form-group"><label>週期類型</label>
          <select class="form-control" id="sch-cycle" onchange="onCycleChange()">
            <option value="weekly">每週循環（指定星期幾）</option>
            <option value="monthly">每月固定日期</option>
            <option value="range">指定日期區間（固定）</option>
          </select>
        </div>
        <div id="sch-weekly-div">
          <div class="form-group"><label style="display:block;margin-bottom:6px;">上班星期</label>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <span class="wd-btn on" data-wd="1">一</span><span class="wd-btn on" data-wd="2">二</span>
              <span class="wd-btn on" data-wd="3">三</span><span class="wd-btn on" data-wd="4">四</span>
              <span class="wd-btn on" data-wd="5">五</span><span class="wd-btn" data-wd="6">六</span><span class="wd-btn" data-wd="7">日</span>
            </div>
          </div>
        </div>
        <div id="sch-monthly-div" style="display:none;">
          <div class="form-group"><label>每月第幾天（逗號分隔）</label><input type="text" class="form-control" id="sch-mdays" placeholder="例：1,15"></div>
        </div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>生效起日 <span class="text-danger">*</span></label><input type="date" class="form-control" id="sch-from"></div></div>
          <div class="col-sm-6"><div class="form-group"><label>生效迄日（空白=長期）</label><input type="date" class="form-control" id="sch-to"></div></div>
        </div>
        <div class="form-group"><label>備註</label><input type="text" class="form-control" id="sch-remark"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-success" onclick="saveSchedule()" style="font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 例外日 Modal ════════════════════════════════════════════ -->
<div class="modal fade" id="exc-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">例外日設定</h4></div>
      <div class="modal-body">
        <input type="hidden" id="exc-id">
        <div class="alert alert-info" style="font-size:12px;padding:7px 12px;margin-bottom:12px;border-radius:6px;">
          <i class="fa fa-info-circle"></i> 例外日會覆蓋當天週期排班。班別選「不出勤」代表當日休假/補休，不計入工時。
        </div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>人員 <span class="text-danger">*</span></label>
            <select class="form-control" id="exc-uid">
              <?php foreach($user_list as $u):
                $opt_lbl2 = $u['user_cname'].($u['dept_name']?' ['.$u['dept_name'].($u['pos_name']?' · '.$u['pos_name']:'').']':'');
              ?><option value="<?=safe($u['id'])?>"><?=safe($opt_lbl2)?></option><?php endforeach; ?>
            </select>
          </div></div>
          <div class="col-sm-6"><div class="form-group"><label>例外日期 <span class="text-danger">*</span></label><input type="date" class="form-control" id="exc-date"></div></div>
        </div>
        <div class="row">
          <div class="col-sm-6"><div class="form-group"><label>例外類型</label>
            <select class="form-control" id="exc-type">
              <option value="overtime">臨時加班</option><option value="shift_change">換班</option>
              <option value="short">縮短工時</option><option value="dayoff">休假/補休</option><option value="holiday">假日加班</option>
            </select>
          </div></div>
          <div class="col-sm-6"><div class="form-group"><label>使用班別（空=不出勤）</label>
            <select class="form-control" id="exc-stid">
              <option value="">— 不出勤 —</option>
              <?php foreach($shift_types as $st): ?><option value="<?=safe($st['shift_type_id'])?>"><?=safe($st['shift_name'])?></option><?php endforeach; ?>
            </select>
          </div></div>
        </div>
        <div class="row">
          <div class="col-sm-4"><div class="form-group"><label>自訂開始（空=班別預設）</label><input type="time" class="form-control" id="exc-start"></div></div>
          <div class="col-sm-4"><div class="form-group"><label>自訂結束（空=班別預設）</label><input type="time" class="form-control" id="exc-end"></div></div>
          <div class="col-sm-4"><div class="form-group"><label>休息分鐘（空=班別預設）</label><input type="number" class="form-control" id="exc-break" min="0" placeholder="空=班別預設"></div></div>
        </div>
        <div class="form-group"><label>備註</label><input type="text" class="form-control" id="exc-remark"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">取消</button>
        <button class="btn btn-success" onclick="saveException()" style="font-weight:600;"><i class="fa fa-save"></i> 儲存</button>
      </div>
    </div>
  </div>
</div>

<div id="toast-wrap"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
function showToast(msg,ok){var $t=$('<div class="toast-msg '+(ok===false?'error':'success')+'">'+msg+'</div>');$('#toast-wrap').append($t);setTimeout(function(){$t.fadeOut(300,function(){$t.remove();});},2600);}
function post(data,cb){$.post('kpi_shift_setting.php',data,cb,'json').fail(function(){showToast('連線失敗',false);});}

// ── 分頁切換 ─────────────────────────────────────────────────
var loaded={};
function switchTab(id,btn){
    $('.tab-pane').hide().removeClass('active');$('.tab-btn').removeClass('active');
    $('#tab-'+id).show().addClass('active');$(btn).addClass('active');
    if(id==='shifts'&&!loaded.shifts){loadShifts();loaded.shifts=true;}
    if(id==='schedule')loadSchedules();
    if(id==='exception')loadExceptions();
}

// ══ 班別 ══════════════════════════════════════════════════════
function loadShifts(){
    post({action:'get_shift_types'},function(res){
        if(!res.success)return;
        // 更新人員排班與例外日的班別下拉選單
        var schH = '<option value="">— 請選擇 —</option>', excH = '<option value="">— 不出勤 —</option>';
        res.data.forEach(function(s){ var o = '<option value="'+s.shift_type_id+'">'+s.shift_name+'</option>'; schH += o; excH += o; });
        $('#sch-stid').html(schH); $('#exc-stid').html(excH);

        var h='';
        res.data.forEach(function(s){
            var total=s.total_minutes?Math.round(s.total_minutes/60*10)/10+'h':'—';
            h+='<tr>'
              +'<td><span class="shift-chip" style="background:'+(s.color||'#888')+'">'+s.shift_name+'</span></td>'
              +'<td><code>'+s.shift_code+'</code></td>'
              +'<td>'+s.start_time+'</td><td>'+s.end_time+'</td>'
              +'<td>'+(s.is_overnight?'<span class="text-danger font-weight-bold">是</span>':'否')+'</td>'
              +'<td>'+s.break_minutes+'</td>'
              +'<td><strong>'+total+'</strong></td>'
              +'<td>'+(s.description||'—')+'</td>'
              +'<td>'
              +'<button class="btn btn-xs btn-default" style="margin-right:3px;" onclick=\'openShiftModal('+JSON.stringify(s)+')\' ><i class="fa fa-edit"></i></button>'
              +'<button class="btn btn-xs btn-danger" onclick="deleteShift('+s.shift_type_id+',\''+s.shift_name+'\')"><i class="fa fa-trash"></i></button>'
              +'</td></tr>';
        });
        $('#shifts-tbody').html(h||'<tr><td colspan="9" style="text-align:center;padding:30px;color:#aaa;">尚無班別，請新增</td></tr>');
    });
}
function openShiftModal(s){
    var n=!s||s===0;
    $('#sm-id').val(n?'':s.shift_type_id);$('#sm-name').val(n?'':s.shift_name);$('#sm-code').val(n?'':s.shift_code);
    $('#sm-color').val(n?'#3498DB':s.color||'#3498DB');$('#sm-start').val(n?'':s.start_time);$('#sm-end').val(n?'':s.end_time);
    $('#sm-overnight').val(n?'0':String(s.is_overnight||'0'));$('#sm-break').val(n?60:s.break_minutes||0);$('#sm-sort').val(n?0:s.sort_order||0);$('#sm-desc').val(n?'':s.description||'');
    $('#shift-modal').modal('show');
}
function saveShift(){
    post({action:'save_shift_type',shift_type_id:$('#sm-id').val(),shift_name:$('#sm-name').val(),shift_code:$('#sm-code').val(),start_time:$('#sm-start').val(),end_time:$('#sm-end').val(),is_overnight:$('#sm-overnight').val(),break_minutes:$('#sm-break').val(),color:$('#sm-color').val(),description:$('#sm-desc').val(),sort_order:$('#sm-sort').val()},function(res){
        res.success?(showToast('班別已儲存'),$('#shift-modal').modal('hide'),loadShifts()):showToast(res.message||'儲存失敗',false);
    });
}
function deleteShift(id,name){
    if(!confirm('確定停用班別「'+name+'」？已建立的排班不受影響。'))return;
    post({action:'delete_shift_type',shift_type_id:id},function(res){res.success?(showToast('已停用'),loadShifts()):showToast(res.message||'失敗',false);});
}

// ══ 人員排班 ══════════════════════════════════════════════════
var wdNames=['','一','二','三','四','五','六','日'];
function loadSchedules(){
    post({action:'get_schedules',user_id:$('#sch-user').val(),dept_id:$('#sch-dept').val()},function(res){
        if(!res.success)return;var h='';
        res.data.forEach(function(s){
            var cLabel={'weekly':'每週','monthly':'每月','range':'區間'}[s.cycle_type]||s.cycle_type;
            var detail='';
            if(s.cycle_type==='weekly'&&s.weekdays) detail=s.weekdays.split(',').map(function(d){return wdNames[d]||d;}).join('、');
            else if(s.cycle_type==='monthly'&&s.month_days) detail='每月第 '+s.month_days+' 天';
            h+='<tr>'
              +'<td><strong>'+s.user_cname+'</strong></td>'
              +'<td style="font-size:11px;color:#888;">'+(s.dept_name||'')+(s.pos_name?' · '+s.pos_name:'')+'</td>'
              +'<td><span class="shift-chip" style="background:'+(s.color||'#888')+'">'+s.shift_name+'</span></td>'
              +'<td>'+cLabel+'</td><td style="font-size:11px;">'+detail+'</td>'
              +'<td>'+s.effective_from+'</td><td>'+(s.effective_to||'長期')+'</td>'
              +'<td>'+(s.remark||'—')+'</td>'
              +'<td>'
              +'<button class="btn btn-xs btn-default" style="margin-right:3px;" onclick=\'openSchModal('+JSON.stringify(s)+')\' ><i class="fa fa-edit"></i></button>'
              +'<button class="btn btn-xs btn-danger" onclick="deleteSch('+s.schedule_id+')"><i class="fa fa-trash"></i></button>'
              +'</td></tr>';
        });
        $('#sch-tbody').html(h||'<tr><td colspan="9" style="text-align:center;padding:30px;color:#aaa;">尚無排班設定</td></tr>');
    });
}
function openSchModal(s){
    var n=!s||s===0;
    $('#sch-id').val(n?'':s.schedule_id);$('#sch-uid').val(n?'':s.user_id);$('#sch-stid').val(n?'':s.shift_type_id);
    $('#sch-cycle').val(n?'weekly':s.cycle_type||'weekly');$('#sch-from').val(n?'':s.effective_from);$('#sch-to').val(n?'':s.effective_to||'');$('#sch-remark').val(n?'':s.remark||'');
    var wds=n?['1','2','3','4','5']:(s.weekdays?(s.weekdays.split(',')):[]);
    $('.wd-btn').each(function(){$(this).toggleClass('on',wds.indexOf(String($(this).data('wd')))!==-1);});
    $('#sch-mdays').val(n?'':s.month_days||'');
    onCycleChange();$('#sch-modal').modal('show');
}
function onCycleChange(){var c=$('#sch-cycle').val();$('#sch-weekly-div').toggle(c==='weekly');$('#sch-monthly-div').toggle(c==='monthly');}
$(document).on('click','.wd-btn',function(){$(this).toggleClass('on');});
function saveSchedule(){
    var wds=$('.wd-btn.on').map(function(){return $(this).data('wd');}).get().join(',');
    post({action:'save_schedule',schedule_id:$('#sch-id').val(),user_id:$('#sch-uid').val(),shift_type_id:$('#sch-stid').val(),cycle_type:$('#sch-cycle').val(),weekdays:wds,month_days:$('#sch-mdays').val(),effective_from:$('#sch-from').val(),effective_to:$('#sch-to').val(),remark:$('#sch-remark').val()},function(res){
        res.success?(showToast('排班已儲存'),$('#sch-modal').modal('hide'),loadSchedules()):showToast(res.message||'儲存失敗',false);
    });
}
function deleteSch(id){if(!confirm('確定刪除此排班設定？'))return;post({action:'delete_schedule',schedule_id:id},function(res){res.success?(showToast('已刪除'),loadSchedules()):showToast(res.message||'失敗',false);});}

// ══ 例外日 ════════════════════════════════════════════════════
var etypeLabel={overtime:'臨時加班',shift_change:'換班',short:'縮短工時',dayoff:'休假',holiday:'假日加班'};
var etypeColor={overtime:'#E74C3C',shift_change:'#F39C12',short:'#3498DB',dayoff:'#95a5a6',holiday:'#9B59B6'};
function loadExceptions(){
    post({action:'get_exceptions',user_id:$('#exc-user').val(),ym:$('#exc-ym').val()},function(res){
        if(!res.success)return;var h='';
        res.data.forEach(function(e){
            var ec=etypeColor[e.exception_type]||'#888';
            var ct=(e.custom_start&&e.custom_end)?e.custom_start.substr(0,5)+' ~ '+e.custom_end.substr(0,5):'班別預設';
            h+='<tr>'
              +'<td><strong>'+e.exception_date+'</strong></td>'
              +'<td>'+e.user_cname+'</td>'
              +'<td><span class="etype-chip" style="background:'+ec+'">'+(etypeLabel[e.exception_type]||e.exception_type)+'</span></td>'
              +'<td>'+(e.shift_name?'<span class="shift-chip" style="background:'+(e.color||'#888')+'">'+e.shift_name+'</span>':'<span class="text-muted">不出勤</span>')+'</td>'
              +'<td style="font-size:11px;">'+ct+'</td>'
              +'<td>'+(e.custom_break!==null?e.custom_break:'班別預設')+'</td>'
              +'<td>'+(e.remark||'—')+'</td>'
              +'<td>'
              +'<button class="btn btn-xs btn-default" style="margin-right:3px;" onclick=\'openExcModal('+JSON.stringify(e)+')\' ><i class="fa fa-edit"></i></button>'
              +'<button class="btn btn-xs btn-danger" onclick="deleteExc('+e.exception_id+')"><i class="fa fa-trash"></i></button>'
              +'</td></tr>';
        });
        $('#exc-tbody').html(h||'<tr><td colspan="8" style="text-align:center;padding:30px;color:#aaa;">本月無例外日設定</td></tr>');
    });
}
function openExcModal(e){
    var n=!e||e===0;
    $('#exc-id').val(n?'':e.exception_id);$('#exc-uid').val(n?'':e.user_id);
    $('#exc-date').val(n?new Date().toISOString().slice(0,10):e.exception_date);
    $('#exc-type').val(n?'overtime':e.exception_type||'overtime');$('#exc-stid').val(n?'':e.shift_type_id||'');
    $('#exc-start').val(n?'':(e.custom_start?e.custom_start.substr(0,5):''));$('#exc-end').val(n?'':(e.custom_end?e.custom_end.substr(0,5):''));
    $('#exc-break').val(n?'':(e.custom_break!==null?e.custom_break:''));$('#exc-remark').val(n?'':e.remark||'');
    $('#exc-modal').modal('show');
}
function saveException(){
    post({action:'save_exception',exception_id:$('#exc-id').val(),user_id:$('#exc-uid').val(),exception_date:$('#exc-date').val(),shift_type_id:$('#exc-stid').val(),custom_start:$('#exc-start').val(),custom_end:$('#exc-end').val(),custom_break:$('#exc-break').val(),exception_type:$('#exc-type').val(),remark:$('#exc-remark').val()},function(res){
        res.success?(showToast('例外日已儲存'),$('#exc-modal').modal('hide'),loadExceptions()):showToast(res.message||'儲存失敗',false);
    });
}
function deleteExc(id){if(!confirm('確定刪除此例外日？'))return;post({action:'delete_exception',exception_id:id},function(res){res.success?(showToast('已刪除'),loadExceptions()):showToast(res.message||'失敗',false);});}

// ── 初始化 ────────────────────────────────────────────────────
$(function(){$('.tab-pane').hide();$('#tab-shifts').show().addClass('active');loadShifts();loaded.shifts=true;});
</script>
</body>
</html>