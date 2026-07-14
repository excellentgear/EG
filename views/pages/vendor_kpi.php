<?php
// EGsystem/views/pages/vendor_kpi.php
ini_set('display_errors', 0);
error_reporting(0);
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

include_once '../../src/common/DBConnection.php';
$conn   = new DBConnection();
$pdo    = $conn->getPDO();
$userId = intval($_SESSION['id'] ?? 0);

// ── 自動建立設定表 ──────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_vendor_setting (
        setting_id    INT AUTO_INCREMENT PRIMARY KEY,
        setting_key   VARCHAR(60)  NOT NULL UNIQUE,
        setting_value VARCHAR(500) NOT NULL DEFAULT '',
        label         VARCHAR(100) NULL,
        Updated_By    INT NULL,
        Updated_At    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外包廠商KPI基本設定'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_grade_rule (
        rule_id     INT AUTO_INCREMENT PRIMARY KEY,
        grade       VARCHAR(20)      NOT NULL  COMMENT '等級代號，例：A、B、S',
        ontime_gte  DECIMAL(5,2)     NOT NULL DEFAULT 0  COMMENT '準時率下限（%），≥ 此值',
        ng_lte      DECIMAL(5,2)     NOT NULL DEFAULT 100 COMMENT 'NG率上限（%），≤ 此值',
        color       VARCHAR(20)      NOT NULL DEFAULT 'gray' COMMENT '顏色識別',
        sort_order  INT              NOT NULL DEFAULT 0  COMMENT '排序（由上往下優先匹配）',
        Updated_By  INT NULL,
        Updated_At  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外包廠商KPI評級規則'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_excluded_maker (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        maker_id_no VARCHAR(11)  NULL    COMMENT '廠商編號，對應 maker_list.maker_id_no',
        maker_id    VARCHAR(30)  NOT NULL COMMENT '廠商簡稱，對應 maker_list.maker_id',
        reason      VARCHAR(200) NULL    COMMENT '例外原因說明',
        Created_By  INT NULL,
        Created_At  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='KPI計算例外廠商'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_special_maker (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        maker_id_no   VARCHAR(11)  NULL    COMMENT '廠商編號',
        maker_id      VARCHAR(30)  NOT NULL COMMENT '廠商簡稱',
        tolerance_days INT         NOT NULL DEFAULT 7 COMMENT '特殊容忍天數（上班日）',
        reason        VARCHAR(200) NULL    COMMENT '設定原因',
        Created_By    INT NULL,
        Created_At    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Modified_By   INT NULL,
        Modified_At   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_maker (maker_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='KPI特殊廠商容忍天數'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_excluded_process (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        process_no  INT          NULL    COMMENT '製程編號，對應 process_no.ProcessNo',
        process_name VARCHAR(60) NOT NULL COMMENT '製程名稱',
        reason      VARCHAR(200) NULL    COMMENT '例外原因',
        Created_By  INT NULL,
        Created_At  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_proc (process_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='KPI計算例外製程'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kpi_special_process (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        process_no    INT          NULL    COMMENT '製程編號',
        process_name  VARCHAR(60)  NOT NULL COMMENT '製程名稱',
        tolerance_days INT         NOT NULL DEFAULT 7 COMMENT '特殊容忍天數（上班日）',
        reason        VARCHAR(200) NULL,
        Created_By    INT NULL,
        Created_At    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Modified_By   INT NULL,
        Modified_At   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_proc (process_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='KPI特殊製程容忍天數'");

    $ins = $pdo->prepare("INSERT IGNORE INTO kpi_vendor_setting (setting_key,setting_value,label) VALUES (?,?,?)");
    $ins->execute(['ontime_tolerance_days','3','容忍天數（上班日）']);
    $ins->execute(['min_txn_count','5','最低有效樣本筆數']);

    // 評級規則預設值（只在表為空時插入）
    $ruleCount = (int)$pdo->query("SELECT COUNT(*) FROM kpi_grade_rule")->fetchColumn();
    if ($ruleCount === 0) {
        $ri = $pdo->prepare("INSERT INTO kpi_grade_rule (grade,ontime_gte,ng_lte,color,sort_order) VALUES (?,?,?,?,?)");
        $ri->execute(['S', 95, 1,  'purple', 1]);
        $ri->execute(['A', 90, 3,  'green',  2]);
        $ri->execute(['B', 75, 6,  'yellow', 3]);
        $ri->execute(['C',  0, 999,'red',    4]);
    }
} catch(Exception $e) {}

// ── 工作日計算（僅用 evenement + event_category，calendar_workday 已停用）────────
// event_category.day_type：NULL=一般日, s=休假日, m=補班/調班（m 也是上班日）
function loadWorkdayMaps(PDO $pdo, string $from, string $to): array {
    $evMap = []; // date => day_type ('s' or 'm')
    try {
        // 取涵蓋該日期區間的所有休假/補班事件（allday 或跨日事件都考慮）
        $s=$pdo->prepare("
            SELECT DATE(d.d) AS ev_date, ec.day_type
            FROM evenement e
            JOIN event_category ec ON ec.id = e.category_id
            JOIN (
                SELECT DATE_ADD(DATE(e2.start), INTERVAL seq.n DAY) AS d
                FROM evenement e2
                JOIN (
                    SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3
                    UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7
                    UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11
                    UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15
                    UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
                    UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23
                    UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27
                    UNION SELECT 28 UNION SELECT 29 UNION SELECT 30
                ) seq
                WHERE DATE_ADD(DATE(e2.start), INTERVAL seq.n DAY) <= DATE(IFNULL(e2.end, e2.start))
            ) d ON d.d = DATE(e.start) OR (d.d > DATE(e.start) AND d.d <= DATE(IFNULL(e.end, e.start)))
            WHERE ec.day_type IN ('s','m')
              AND d.d BETWEEN ? AND ?
        ");
        $s->execute([$from, $to]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // 同日若有多筆，m(補班)優先於 s(休假)
            if (!isset($evMap[$r['ev_date']]) || $r['day_type'] === 'm') {
                $evMap[$r['ev_date']] = $r['day_type'];
            }
        }
    } catch(Exception $e){}
    return ['ev'=>$evMap];
}
function isWorkday(string $date, array $maps): bool {
    $dow = (int)(new DateTime($date))->format('N'); // 1=Mon..7=Sun
    $dayType = $maps['ev'][$date] ?? null;
    if ($dayType === 'm') return true;   // 補班日 → 上班
    if ($dayType === 's') return false;  // 休假日 → 不上班
    return $dow <= 5;                    // 一般日：週一~五上班
}
// outsource_date + N 上班日 = 截止日
function calcDeadline(string $from, int $n, array $maps): string {
    if($n<=0) return $from;
    $count=0; $cur=new DateTime($from); $cur->modify('+1 day'); $lim=0;
    while($count<$n && $lim<200){
        if(isWorkday($cur->format('Y-m-d'),$maps)) $count++;
        if($count<$n) $cur->modify('+1 day');
        $lim++;
    }
    return $cur->format('Y-m-d');
}
// today - N 上班日 = 容忍截止日（outsource_date 需 <= 此日才計入）
function subtractWorkdays(string $from, int $n, array $maps): string {
    if($n<=0) return $from;
    $count=0; $cur=new DateTime($from); $cur->modify('-1 day'); $lim=0;
    while($count<$n && $lim<200){
        if(isWorkday($cur->format('Y-m-d'),$maps)) $count++;
        if($count<$n) $cur->modify('-1 day');
        $lim++;
    }
    return $cur->format('Y-m-d');
}

// ── AJAX ───────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){
    header('Content-Type: application/json; charset=utf-8');

    // 取得設定
    if($_POST['action']==='get_settings'){
        try{
            $rows=$pdo->query("SELECT setting_key,setting_value,label FROM kpi_vendor_setting ORDER BY setting_id")->fetchAll(PDO::FETCH_ASSOC);
            $out=[];
            foreach($rows as $r) $out[$r['setting_key']]=['value'=>$r['setting_value'],'label'=>$r['label']];
            // 評級規則從獨立資料表取
            $grules=$pdo->query("SELECT rule_id,grade,ontime_gte,ng_lte,color,sort_order FROM kpi_grade_rule ORDER BY sort_order,rule_id")->fetchAll(PDO::FETCH_ASSOC);
            $out['grade_rules']=['value'=>json_encode($grules,JSON_UNESCAPED_UNICODE),'label'=>'評級規則'];
            // 例外廠商清單
            $excl=$pdo->query("SELECT id,maker_id_no,maker_id,reason FROM kpi_excluded_maker ORDER BY maker_id")->fetchAll(PDO::FETCH_ASSOC);
            $out['excluded_makers']=['value'=>json_encode($excl,JSON_UNESCAPED_UNICODE),'label'=>'例外廠商'];
            // 特殊廠商容忍天數
            $spec=$pdo->query("SELECT id,maker_id_no,maker_id,tolerance_days,reason FROM kpi_special_maker ORDER BY maker_id")->fetchAll(PDO::FETCH_ASSOC);
            $out['special_makers']=['value'=>json_encode($spec,JSON_UNESCAPED_UNICODE),'label'=>'特殊廠商容忍天數'];
            // 例外製程
            $exclP=$pdo->query("SELECT id,process_no,process_name,reason FROM kpi_excluded_process ORDER BY process_name")->fetchAll(PDO::FETCH_ASSOC);
            $out['excluded_procs']=['value'=>json_encode($exclP,JSON_UNESCAPED_UNICODE),'label'=>'例外製程'];
            // 特殊製程容忍天數
            $specP=$pdo->query("SELECT id,process_no,process_name,tolerance_days,reason FROM kpi_special_process ORDER BY process_name")->fetchAll(PDO::FETCH_ASSOC);
            $out['special_procs']=['value'=>json_encode($specP,JSON_UNESCAPED_UNICODE),'label'=>'特殊製程容忍天數'];
            echo json_encode(['success'=>true,'data'=>$out]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 儲存設定
    if($_POST['action']==='save_settings'){
        try{
            $tol=intval($_POST['ontime_tolerance_days']??3);
            $min=intval($_POST['min_txn_count']??5);
            $gj=trim($_POST['grade_rules']??'');
            $g=json_decode($gj,true);
            if(!is_array($g)||count($g)<1) throw new Exception('評級規則格式錯誤');
            if($tol<0||$min<1) throw new Exception('數值不合法');

            $pdo->beginTransaction();
            // 基本設定
            $st=$pdo->prepare("UPDATE kpi_vendor_setting SET setting_value=?,Updated_By=? WHERE setting_key=?");
            $st->execute([$tol,$userId,'ontime_tolerance_days']);
            $st->execute([$min,$userId,'min_txn_count']);
            // 評級規則：清除後重新插入
            $pdo->exec("DELETE FROM kpi_grade_rule");
            $ri=$pdo->prepare("INSERT INTO kpi_grade_rule (grade,ontime_gte,ng_lte,color,sort_order,Updated_By) VALUES (?,?,?,?,?,?)");
            foreach($g as $i=>$rule){
                $grade  = trim($rule['grade']??'');
                $gte    = max(0,min(100,floatval($rule['ontime_gte']??0)));
                $lte    = max(0,floatval($rule['ng_lte']??100));
                $color  = in_array($rule['color']??'',['green','yellow','red','purple','gray'])?$rule['color']:'gray';
                $ri->execute([$grade,$gte,$lte,$color,$i+1,$userId]);
            }
            $pdo->commit();
            echo json_encode(['success'=>true]);
        }catch(Exception $e){
            if($pdo->inTransaction())$pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // 新增例外廠商
    if($_POST['action']==='add_excluded'){
        try{
            $mkNo=trim($_POST['maker_id_no']??'');
            $mkNm=trim($_POST['maker_id']??''); if(!$mkNm) throw new Exception('廠商名稱必填');
            $reason=trim($_POST['reason']??'')?:null;
            // 避免重複
            $chk=$pdo->prepare("SELECT COUNT(*) FROM kpi_excluded_maker WHERE maker_id=?");
            $chk->execute([$mkNm]);
            if((int)$chk->fetchColumn()>0) throw new Exception('廠商「'.$mkNm.'」已在例外清單中');
            $pdo->prepare("INSERT INTO kpi_excluded_maker (maker_id_no,maker_id,reason,Created_By) VALUES (?,?,?,?)")
                ->execute([$mkNo?:null,$mkNm,$reason,$userId]);
            echo json_encode(['success'=>true,'id'=>(int)$pdo->lastInsertId()]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 刪除例外廠商
    if($_POST['action']==='delete_excluded'){
        try{
            $id=intval($_POST['id']??0); if(!$id) throw new Exception('未指定');
            $pdo->prepare("DELETE FROM kpi_excluded_maker WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 儲存特殊廠商容忍天數
    if($_POST['action']==='save_special_maker'){
        try{
            $mkNo=trim($_POST['maker_id_no']??'');
            $mkNm=trim($_POST['maker_id']??''); if(!$mkNm) throw new Exception('廠商名稱必填');
            $days=intval($_POST['tolerance_days']??7); if($days<1) throw new Exception('容忍天數至少1天');
            $reason=trim($_POST['reason']??'')?:null;
            // UPSERT
            $pdo->prepare("INSERT INTO kpi_special_maker (maker_id_no,maker_id,tolerance_days,reason,Created_By)
                           VALUES (?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE tolerance_days=VALUES(tolerance_days),reason=VALUES(reason),Modified_By=VALUES(Created_By)")
                ->execute([$mkNo?:null,$mkNm,$days,$reason,$userId]);
            $id=(int)$pdo->lastInsertId()?:(int)$pdo->query("SELECT id FROM kpi_special_maker WHERE maker_id=".
                $pdo->quote($mkNm))->fetchColumn();
            echo json_encode(['success'=>true,'id'=>$id,'tolerance_days'=>$days]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }
    // 刪除特殊廠商
    if($_POST['action']==='delete_special_maker'){
        try{
            $id=intval($_POST['id']??0); if(!$id) throw new Exception('未指定');
            $pdo->prepare("DELETE FROM kpi_special_maker WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 新增例外製程
    if($_POST['action']==='add_excluded_proc'){
        try{
            $pno=intval($_POST['process_no']??0)?:null;
            $pnm=trim($_POST['process_name']??''); if(!$pnm) throw new Exception('製程名稱必填');
            $reason=trim($_POST['reason']??'')?:null;
            $pdo->prepare("INSERT IGNORE INTO kpi_excluded_process (process_no,process_name,reason,Created_By) VALUES (?,?,?,?)")
                ->execute([$pno,$pnm,$reason,$userId]);
            $id=(int)$pdo->lastInsertId()?:(int)$pdo->query("SELECT id FROM kpi_excluded_process WHERE process_name=".$pdo->quote($pnm))->fetchColumn();
            echo json_encode(['success'=>true,'id'=>$id]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }
    // 刪除例外製程
    if($_POST['action']==='delete_excluded_proc'){
        try{
            $id=intval($_POST['id']??0); if(!$id) throw new Exception('未指定');
            $pdo->prepare("DELETE FROM kpi_excluded_process WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }
    // 儲存特殊製程容忍天數
    if($_POST['action']==='save_special_proc'){
        try{
            $pno=intval($_POST['process_no']??0)?:null;
            $pnm=trim($_POST['process_name']??''); if(!$pnm) throw new Exception('製程名稱必填');
            $days=intval($_POST['tolerance_days']??7); if($days<1) throw new Exception('天數至少1天');
            $reason=trim($_POST['reason']??'')?:null;
            $pdo->prepare("INSERT INTO kpi_special_process (process_no,process_name,tolerance_days,reason,Created_By)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE tolerance_days=VALUES(tolerance_days),reason=VALUES(reason),Modified_By=VALUES(Created_By)")
                ->execute([$pno,$pnm,$days,$reason,$userId]);
            $id=(int)$pdo->lastInsertId()?:(int)$pdo->query("SELECT id FROM kpi_special_process WHERE process_name=".$pdo->quote($pnm))->fetchColumn();
            echo json_encode(['success'=>true,'id'=>$id,'tolerance_days'=>$days]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }
    // 刪除特殊製程
    if($_POST['action']==='delete_special_proc'){
        try{
            $id=intval($_POST['id']??0); if(!$id) throw new Exception('未指定');
            $pdo->prepare("DELETE FROM kpi_special_process WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 搜尋廠商（自動完成）
    if($_POST['action']==='search_maker'){
        try{
            $term=trim($_POST['term']??''); if(!$term){echo json_encode(['success'=>true,'data'=>[]]);exit;}
            $st=$pdo->prepare("SELECT maker_id_no,maker_id FROM maker_list WHERE (maker_id LIKE ? OR maker_id_no LIKE ?) AND (status IS NULL OR status<>'X') ORDER BY maker_id LIMIT 15");
            $st->execute(["%$term%","%$term%"]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }
    // 搜尋製程（自動完成）
    if($_POST['action']==='search_process'){
        try{
            $term=trim($_POST['term']??''); if(!$term){echo json_encode(['success'=>true,'data'=>[]]);exit;}
            $st=$pdo->prepare("SELECT ProcessNo AS process_no, ProcessName AS process_name FROM process_no WHERE ProcessName LIKE ? ORDER BY ProcessName LIMIT 15");
            $st->execute(["%$term%"]);
            echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 主KPI資料
    if($_POST['action']==='get_kpi_data'){
        try{
            $mode=trim($_POST['mode']??'month');
            $period=trim($_POST['period']??date('Y-m'));
            $makerF=trim($_POST['maker_filter']??'');
            $procF=trim($_POST['proc_filter']??'');

            $cfg=$pdo->query("SELECT setting_key,setting_value FROM kpi_vendor_setting")->fetchAll(PDO::FETCH_KEY_PAIR);
            $tol=intval($cfg['ontime_tolerance_days']??3);
            $minTxn=intval($cfg['min_txn_count']??5);
            $gradeRules=$pdo->query("SELECT grade,ontime_gte,ng_lte,color FROM kpi_grade_rule ORDER BY sort_order,rule_id")->fetchAll(PDO::FETCH_ASSOC);
            // 特殊廠商容忍天數 map
            $specRows=$pdo->query("SELECT maker_id_no,maker_id,tolerance_days FROM kpi_special_maker")->fetchAll(PDO::FETCH_ASSOC);
            $specTolById=[]; $specTolByName=[];
            foreach($specRows as $sr){
                if($sr['maker_id_no']) $specTolById[$sr['maker_id_no']]=(int)$sr['tolerance_days'];
                $specTolByName[$sr['maker_id']]=(int)$sr['tolerance_days'];
            }
            // 例外製程（排除在外）
            $exclProcRows=$pdo->query("SELECT process_no,process_name FROM kpi_excluded_process")->fetchAll(PDO::FETCH_ASSOC);
            $exclProcNos  =array_values(array_filter(array_column($exclProcRows,'process_no')));
            $exclProcNames=array_values(array_column($exclProcRows,'process_name'));
            // 特殊製程容忍天數 map：ProcessNo → days, ProcessName → days
            $specProcRows=$pdo->query("SELECT process_no,process_name,tolerance_days FROM kpi_special_process")->fetchAll(PDO::FETCH_ASSOC);
            $specProcTolByNo=[]; $specProcTolByName=[];
            foreach($specProcRows as $sp){
                if($sp['process_no']) $specProcTolByNo[(int)$sp['process_no']]=(int)$sp['tolerance_days'];
                $specProcTolByName[$sp['process_name']]=(int)$sp['tolerance_days'];
            }

            if($mode==='year'){
                $yr=intval(substr($period,0,4));
                $ds="$yr-01-01"; $de="$yr-12-31";
            }elseif($mode==='half'){
                $yr=intval(substr($period,0,4));
                $h=strpos($period,'H2')!==false?2:1;
                $ds=$h===1?"$yr-01-01":"$yr-07-01";
                $de=$h===1?"$yr-06-30":"$yr-12-31";
            }else{
                $ds=$period.'-01';
                $de=date('Y-m-t',strtotime($ds));
            }

            $today=date('Y-m-d');
            // 預載工作日（期間+60天緩衝，用於計算 cutoff 和各筆截止日）
            $mapFrom=min($ds,date('Y-m-d',strtotime($today.' -'.max(60,$tol*3).' days')));
            $mapTo=date('Y-m-d',strtotime($de.' +60 days'));
            $maps=loadWorkdayMaps($pdo,$mapFrom,$mapTo);
            $cutoff=subtractWorkdays($today,$tol,$maps);

            // 計算本期間上班日數（供 DEBUG 顯示）
            $wdCount=0;
            $wdCur=new DateTime($ds);
            $wdEnd=new DateTime($de);
            while($wdCur<=$wdEnd){ if(isWorkday($wdCur->format('Y-m-d'),$maps))$wdCount++; $wdCur->modify('+1 day'); }

            // 取得例外廠商清單（排除在外）
            $exclRows=$pdo->query("SELECT maker_id_no,maker_id FROM kpi_excluded_maker")->fetchAll(PDO::FETCH_ASSOC);
            $exclIds  = array_values(array_filter(array_column($exclRows,'maker_id_no')));
            $exclNames= array_values(array_column($exclRows,'maker_id'));

            // 建立 WHERE（全用 ? 位置式，避免 PDO 具名/位置式混用問題）
            $where=["bi.outsource_date IS NOT NULL",
                    "DATE(bi.outsource_date) BETWEEN ? AND ?",
                    "bi.maker_id IS NOT NULL AND bi.maker_id<>''",
                    "DATE(bi.outsource_date) <= ?"];
            $fp=[$ds,$de,$cutoff];

            // 排除例外廠商（同時比對 maker_id_no 與 maker_list.maker_id）
            if(!empty($exclIds)){
                $ph=implode(',',array_fill(0,count($exclIds),'?'));
                $where[]="(bi.maker_id_no IS NULL OR bi.maker_id_no NOT IN ($ph))";
                foreach($exclIds as $v) $fp[]=$v;
            }
            if(!empty($exclNames)){
                $ph2=implode(',',array_fill(0,count($exclNames),'?'));
                $where[]="COALESCE(ml.maker_id, bi.maker_id) NOT IN ($ph2)";
                foreach($exclNames as $v) $fp[]=$v;
            }
            if($makerF!==''){$where[]="COALESCE(ml.maker_id, bi.maker_id) LIKE ?";$fp[]="%$makerF%";}
            if($procF!==''){$where[]="pn.ProcessName LIKE ?";$fp[]="%$procF%";}
            // 排除例外製程
            if(!empty($exclProcNos)){
                $ph3=implode(',',array_fill(0,count($exclProcNos),'?'));
                $where[]="(bi.process_no IS NULL OR bi.process_no NOT IN ($ph3))";
                foreach($exclProcNos as $v) $fp[]=$v;
            }
            if(!empty($exclProcNames)){
                $ph4=implode(',',array_fill(0,count($exclProcNames),'?'));
                $where[]="(pn.ProcessName IS NULL OR pn.ProcessName NOT IN ($ph4))";
                foreach($exclProcNames as $v) $fp[]=$v;
            }
            $wSQL='WHERE '.implode(' AND ',$where);

            $sql="SELECT
                         bi.bom_ing_fid, bi.bom, bi.bom_sn,
                         bi.maker_id_no, bi.process_no,
                         COALESCE(ml.maker_id, bi.maker_id) AS maker_name,
                         ml.m_category AS proc_category,
                         ml.m_process_items AS proc_items,
                         bi.sqty,
                         DATE(bi.outsource_date) AS od,
                         DATE(bi.return_date) AS rd_orig,
                         DATE(
                             (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                              WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn
                                AND tl.maker_from=bi.maker_id_no
                              ORDER BY tl.transfer_date DESC LIMIT 1)
                         ) AS rd_log,
                         DATE(COALESCE(
                             bi.return_date,
                             (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                              WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn
                                AND tl.maker_from=bi.maker_id_no
                              ORDER BY tl.transfer_date DESC LIMIT 1)
                         )) AS rd,
                         b.Delivery_date AS dd,
                         bi.QC_check,
                         pn.ProcessName
                  FROM bom_ing bi
                  LEFT JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no
                  LEFT JOIN bom b ON b.bom=bi.bom
                  LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                  $wSQL ORDER BY COALESCE(ml.maker_id,bi.maker_id), bi.outsource_date DESC";
            $st=$pdo->prepare($sql); $st->execute($fp);
            $allRows=$st->fetchAll(PDO::FETCH_ASSOC);

            $mkMap=[];
            foreach($allRows as $row){
                $mk=$row['maker_id_no']?:$row['maker_name'];
                $mkNo=$row['maker_id_no']??'';
                $mkNm=$row['maker_name']??'';
                $pNo =(int)($row['process_no']??0);
                $pNm =$row['ProcessName']??'';
                // 容忍天數：廠商特殊 > 製程特殊 > 全域
                $rowTol = $specTolById[$mkNo]
                       ?? $specTolByName[$mkNm]
                       ?? ($pNo && isset($specProcTolByNo[$pNo]) ? $specProcTolByNo[$pNo] : null)
                       ?? $specProcTolByName[$pNm]
                       ?? $tol;

                if(!isset($mkMap[$mk])){
                    $mkMap[$mk]=['maker_id_no'=>$row['maker_id_no'],'maker_name'=>$row['maker_name'],
                        'proc_category'=>$row['proc_category'],'proc_items'=>$row['proc_items'],
                        'tolerance'=>$rowTol, // 記錄實際使用的容忍天數
                        'is_special_tol'=>($rowTol !== $tol),
                        'total'=>0,'returned'=>0,'ontime'=>0,'late'=>0,'no_dd'=>0,
                        'ng_count'=>0,'qq_count'=>0,'aod_count'=>0,'ok_count'=>0,
                        'total_days'=>0,'days_count'=>0,'proc_names'=>[]];
                }
                $m=&$mkMap[$mk];
                $m['total']++;
                $rdFromLog = (empty($row['rd_orig']) && !empty($row['rd_log'])); // 回廠日來自 transfer_log
                $deadline=calcDeadline($row['od'],$rowTol,$maps);
                $isRet=!empty($row['rd']);
                if($isRet){
                    $m['returned']++;
                    if($row['rd']<=$deadline) $m['ontime']++;
                    else $m['late']++;
                    // no_dd：無 bom.Delivery_date 才算（不是 rd 來源問題）
                    if(empty($row['dd'])) $m['no_dd']++;
                    $diff=(new DateTime($row['rd']))->diff(new DateTime($row['od']))->days;
                    $m['total_days']+=$diff; $m['days_count']++;
                }else{
                    $m['late']++;
                }
                $qc=strtolower($row['QC_check']??'');
                if($qc==='ng') $m['ng_count']++;
                elseif($qc==='qq') $m['qq_count']++;
                elseif($qc==='aod') $m['aod_count']++;
                elseif($qc==='ok') $m['ok_count']++;
                if(!empty($row['ProcessName'])&&!in_array($row['ProcessName'],$m['proc_names']))
                    $m['proc_names'][]=$row['ProcessName'];
            }
            unset($m);

            $result=[];
            foreach($mkMap as $m){
                $valid=$m['total'];
                $m['valid_count']=$valid;
                $m['ontime_pct']=$valid>=$minTxn?round($m['ontime']/$valid*100,1):null;
                $m['ng_pct']=$m['total']>0?round($m['ng_count']/$m['total']*100,1):0;
                $m['avg_days']=$m['days_count']>0?round($m['total_days']/$m['days_count'],1):null;
                $m['is_enough']=$valid>=$minTxn;
                $m['proc_names']=implode('、',$m['proc_names']);
                $m['grade']='—'; $m['grade_color']='gray';
                if($m['is_enough']&&$m['ontime_pct']!==null){
                    foreach($gradeRules as $rule){
                        if($m['ontime_pct']>=($rule['ontime_gte']??0)&&$m['ng_pct']<=($rule['ng_lte']??999)){
                            $m['grade']=$rule['grade']; $m['grade_color']=$rule['color']; break;
                        }
                    }
                }
                $result[]=$m;
            }

            $tot=array_sum(array_column($result,'total'));
            $ont=array_sum(array_column($result,'ontime'));
            $ng =array_sum(array_column($result,'ng_count'));
            $summary=['vendor_count'=>count($result),'total_count'=>$tot,
                      'ontime_pct'=>$tot>=$minTxn?round($ont/max($tot,1)*100,1):null,
                      'ng_pct'=>$tot>0?round($ng/$tot*100,1):0,
                      'period_start'=>$ds,'period_end'=>$de,'cutoff'=>$cutoff,
                      'workday_count'=>$wdCount];
            echo json_encode(['success'=>true,'data'=>$result,'summary'=>$summary,
                              'settings'=>['tolerance'=>$tol,'min_txn'=>$minTxn,'grade_rules'=>$gradeRules]]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 展開明細
    if($_POST['action']==='get_detail'){
        try{
            $mkNo=trim($_POST['maker_id_no']??'');
            $mkNm=trim($_POST['maker_name']??'');
            $ds=trim($_POST['date_start']??'');
            $de=trim($_POST['date_end']??'');
            $cutoff=trim($_POST['cutoff']??date('Y-m-d'));
            $tol=intval($_POST['tolerance']??3);
            if(!$ds||!$de) throw new Exception('期間未指定');

            $cond=$mkNo?"bi.maker_id_no = :mk":"COALESCE(ml.maker_id, bi.maker_id) LIKE :mk";
            $mkVal=$mkNo?:"%$mkNm%";

            $st=$pdo->prepare("
                SELECT bi.bom_ing_fid, bi.bom, bi.bom_sn, bi.sqty,
                    COALESCE(ml.maker_id, bi.maker_id) AS maker_name,
                    DATE(bi.outsource_date) AS od,
                    DATE(bi.return_date) AS rd_orig,
                    DATE(
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn
                           AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1)
                    ) AS rd_log,
                    DATE(COALESCE(
                        bi.return_date,
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn
                           AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1)
                    )) AS rd,
                    b.Delivery_date AS dd,
                    bi.QC_check, bi.QC_check_date,
                    pn.ProcessName, bi.ps AS remark
                FROM bom_ing bi
                LEFT JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                WHERE $cond AND bi.outsource_date IS NOT NULL
                  AND DATE(bi.outsource_date) BETWEEN :ds AND :de
                  AND DATE(bi.outsource_date) <= :cutoff
                ORDER BY bi.outsource_date DESC");
            $st->execute([':mk'=>$mkVal,':ds'=>$ds,':de'=>$de,':cutoff'=>$cutoff]);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);

            $mapTo=date('Y-m-d',strtotime($de.' +60 days'));
            $maps=loadWorkdayMaps($pdo,$ds,$mapTo);
            foreach($rows as &$row){
                $dl=calcDeadline($row['od'],$tol,$maps);
                $row['ontime_deadline']=$dl;
                $rdFromLog=(!empty($row['rd_log'])&&empty($row['rd_orig']));
                $row['rd_from_log']=$rdFromLog?1:0;
                if(!empty($row['rd'])){
                    $row['status']=$row['rd']<=$dl?'ontime':'late';
                    // 計算發包日到回廠日的實際工作天數
                    $wdCnt=0;
                    $wdC=new DateTime($row['od']); $wdC->modify('+1 day');
                    $wdE=new DateTime($row['rd']);
                    while($wdC<=$wdE){ if(isWorkday($wdC->format('Y-m-d'),$maps))$wdCnt++; $wdC->modify('+1 day'); }
                    $row['workdays']=$wdCnt;
                    $row['natural_days']=(new DateTime($row['rd']))->diff(new DateTime($row['od']))->days;
                } else {
                    $row['status']='not_returned';
                    $row['workdays']=null;
                    $row['natural_days']=null;
                }
            }
            unset($row);
            echo json_encode(['success'=>true,'data'=>$rows]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 趨勢（近N個月）
    if($_POST['action']==='get_trend'){
        try{
            $mkNo=trim($_POST['maker_id_no']??'');
            $mkNm=trim($_POST['maker_name']??'');
            $months=max(3,min(24,intval($_POST['months']??12)));
            $baseYm=trim($_POST['base_ym']??''); // YYYY-MM，期間最後一個月，往前推 months 個月
            $cfg=$pdo->query("SELECT setting_key,setting_value FROM kpi_vendor_setting")->fetchAll(PDO::FETCH_KEY_PAIR);
            $tol=intval($cfg['ontime_tolerance_days']??3);
            $minTxn=intval($cfg['min_txn_count']??5);
            $today=date('Y-m-d');
            $cond=$mkNo?"bi.maker_id_no = :mk":"COALESCE(ml.maker_id, bi.maker_id) LIKE :mk";
            $mkVal=$mkNo?:"%$mkNm%";

            // base_ym 的月末作為起算點（往前推 months-1 個月）
            if($baseYm && preg_match('/^\d{4}-\d{2}$/',$baseYm)){
                $baseDate = $baseYm.'-01';
            } else {
                $baseDate = date('Y-m-01'); // 預設本月
            }

            $trendData=[];
            for($i=$months-1;$i>=0;$i--){
                // 從 baseDate 往前推 $i 個月
                $s=date('Y-m-01',strtotime($baseDate." -$i months"));
                $e=date('Y-m-t',strtotime($s));
                $ym=substr($s,0,7);
                $approxCutoff=date('Y-m-d',strtotime($today.' -'.intval($tol*1.8).' days'));
                // rd_col = bi.return_date OR transfer_log fallback
                $st=$pdo->prepare("SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(bi.return_date,
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1)) IS NOT NULL THEN 1 ELSE 0 END) AS ret,
                    SUM(CASE WHEN COALESCE(bi.return_date,
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1)) IS NOT NULL
                        AND DATEDIFF(
                            DATE(COALESCE(bi.return_date,(SELECT tl2.transfer_date FROM bom_ing_transfer_log tl2
                                WHERE tl2.bom=bi.bom AND tl2.bom_sn=bi.bom_sn AND tl2.maker_from=bi.maker_id_no
                                ORDER BY tl2.transfer_date DESC LIMIT 1))),
                            DATE(bi.outsource_date)
                        ) <= :tol_d
                        THEN 1 ELSE 0 END) AS ontime,
                    SUM(CASE WHEN bi.QC_check='ng' THEN 1 ELSE 0 END) AS ng,
                    AVG(DATEDIFF(
                        DATE(COALESCE(bi.return_date,(SELECT tl3.transfer_date FROM bom_ing_transfer_log tl3
                            WHERE tl3.bom=bi.bom AND tl3.bom_sn=bi.bom_sn AND tl3.maker_from=bi.maker_id_no
                            ORDER BY tl3.transfer_date DESC LIMIT 1))),
                        DATE(bi.outsource_date)
                    )) AS avg_d
                    FROM bom_ing bi
                    LEFT JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no
                    WHERE $cond AND bi.outsource_date IS NOT NULL
                      AND DATE(bi.outsource_date) BETWEEN :ds AND :de
                      AND DATE(bi.outsource_date) <= :cut");
                $st->execute([':mk'=>$mkVal,':ds'=>$s,':de'=>$e,':tol_d'=>$tol*2,':cut'=>$approxCutoff]);
                $r=$st->fetch(PDO::FETCH_ASSOC);
                $tot=intval($r['total']??0); $ret=intval($r['ret']??0); $ont=intval($r['ontime']??0);
                $trendData[]=['ym'=>$ym,'total'=>$tot,'returned'=>$ret,'ontime'=>$ont,
                    'ontime_pct'=>$tot>=$minTxn?round($ont/max($tot,1)*100,1):null,
                    'ng_pct'=>$tot>0?round($r['ng']/$tot*100,1):0,
                    'avg_days'=>$r['avg_d']!==null?round($r['avg_d'],1):null];
            }
            echo json_encode(['success'=>true,'data'=>$trendData]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 歷年趨勢（按年彙整）
    if($_POST['action']==='get_yearly_trend'){
        try{
            $mkNo=trim($_POST['maker_id_no']??'');
            $mkNm=trim($_POST['maker_name']??'');
            $cfg=$pdo->query("SELECT setting_key,setting_value FROM kpi_vendor_setting")->fetchAll(PDO::FETCH_KEY_PAIR);
            $tol=intval($cfg['ontime_tolerance_days']??3);
            $minTxn=intval($cfg['min_txn_count']??5);
            $today=date('Y-m-d');
            $cond=$mkNo?"bi.maker_id_no = :mk":"COALESCE(ml.maker_id, bi.maker_id) LIKE :mk";
            $mkVal=$mkNo?:"%$mkNm%";
            $approxCutoff=date('Y-m-d',strtotime($today.' -'.intval($tol*1.8).' days'));

            // 找此廠商最早的發包年份
            $stY=$pdo->prepare("SELECT MIN(YEAR(bi.outsource_date)) AS min_yr
                FROM bom_ing bi LEFT JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no
                WHERE $cond AND bi.outsource_date IS NOT NULL");
            $stY->execute([':mk'=>$mkVal]);
            $minYr=intval($stY->fetchColumn()?:date('Y')-4);
            $maxYr=(int)date('Y');

            $yearData=[];
            for($yr=$minYr;$yr<=$maxYr;$yr++){
                $ds="$yr-01-01"; $de="$yr-12-31";
                $st=$pdo->prepare("SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(bi.return_date,
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1)) IS NOT NULL THEN 1 ELSE 0 END) AS ret,
                    SUM(CASE WHEN COALESCE(bi.return_date,
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1)) IS NOT NULL
                        AND DATEDIFF(DATE(COALESCE(bi.return_date,(SELECT tl2.transfer_date FROM bom_ing_transfer_log tl2
                            WHERE tl2.bom=bi.bom AND tl2.bom_sn=bi.bom_sn AND tl2.maker_from=bi.maker_id_no
                            ORDER BY tl2.transfer_date DESC LIMIT 1))),DATE(bi.outsource_date)) <= :tol_d
                        THEN 1 ELSE 0 END) AS ontime,
                    SUM(CASE WHEN bi.QC_check='ng' THEN 1 ELSE 0 END) AS ng,
                    AVG(DATEDIFF(DATE(COALESCE(bi.return_date,(SELECT tl3.transfer_date FROM bom_ing_transfer_log tl3
                        WHERE tl3.bom=bi.bom AND tl3.bom_sn=bi.bom_sn AND tl3.maker_from=bi.maker_id_no
                        ORDER BY tl3.transfer_date DESC LIMIT 1))),DATE(bi.outsource_date))) AS avg_d
                    FROM bom_ing bi LEFT JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no
                    WHERE $cond AND bi.outsource_date IS NOT NULL
                      AND DATE(bi.outsource_date) BETWEEN :ds AND :de
                      AND DATE(bi.outsource_date) <= :cut");
                $st->execute([':mk'=>$mkVal,':ds'=>$ds,':de'=>$de,':tol_d'=>$tol*2,':cut'=>$approxCutoff]);
                $r=$st->fetch(PDO::FETCH_ASSOC);
                $tot=intval($r['total']??0); $ret=intval($r['ret']??0); $ont=intval($r['ontime']??0);
                $yearData[]=['yr'=>(string)$yr,'total'=>$tot,'returned'=>$ret,'ontime'=>$ont,
                    'ontime_pct'=>$tot>=$minTxn?round($ont/max($tot,1)*100,1):null,
                    'ng_pct'=>$tot>0?round($r['ng']/$tot*100,1):0,
                    'avg_days'=>$r['avg_d']!==null?round($r['avg_d'],1):null];
            }
            echo json_encode(['success'=>true,'data'=>$yearData]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 相同製程廠商比較
    if($_POST['action']==='get_proc_compare'){
        try{
            $procNames=json_decode(trim($_POST['proc_names']??'[]'),true)?:[];
            $ds=trim($_POST['date_start']??'');
            $de=trim($_POST['date_end']??'');
            $cutoff=trim($_POST['cutoff']??date('Y-m-d'));
            if(!$ds||!$de||empty($procNames)) throw new Exception('參數不足');
            $cfg=$pdo->query("SELECT setting_key,setting_value FROM kpi_vendor_setting")->fetchAll(PDO::FETCH_KEY_PAIR);
            $tol=intval($cfg['ontime_tolerance_days']??3);
            $minTxn=intval($cfg['min_txn_count']??5);

            // 找同製程的所有廠商（取此期間有發包紀錄的）
            $ph=implode(',',array_fill(0,count($procNames),'?'));
            $fp=array_merge([$ds,$de,$cutoff],$procNames);
            $st=$pdo->prepare("SELECT
                    COALESCE(ml.maker_id, bi.maker_id) AS maker_name,
                    bi.maker_id_no,
                    COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(bi.return_date,
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1)) IS NOT NULL THEN 1 ELSE 0 END) AS ret,
                    SUM(CASE WHEN COALESCE(bi.return_date,
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1)) IS NOT NULL
                        AND DATEDIFF(DATE(COALESCE(bi.return_date,(SELECT tl2.transfer_date FROM bom_ing_transfer_log tl2
                            WHERE tl2.bom=bi.bom AND tl2.bom_sn=bi.bom_sn AND tl2.maker_from=bi.maker_id_no
                            ORDER BY tl2.transfer_date DESC LIMIT 1))),DATE(bi.outsource_date)) <= $tol
                        THEN 1 ELSE 0 END) AS ontime,
                    SUM(CASE WHEN bi.QC_check='ng' THEN 1 ELSE 0 END) AS ng_count,
                    AVG(DATEDIFF(DATE(COALESCE(bi.return_date,(SELECT tl3.transfer_date FROM bom_ing_transfer_log tl3
                        WHERE tl3.bom=bi.bom AND tl3.bom_sn=bi.bom_sn AND tl3.maker_from=bi.maker_id_no
                        ORDER BY tl3.transfer_date DESC LIMIT 1))),DATE(bi.outsource_date))) AS avg_d
                FROM bom_ing bi
                LEFT JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no
                LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                WHERE bi.outsource_date IS NOT NULL
                  AND DATE(bi.outsource_date) BETWEEN ? AND ?
                  AND DATE(bi.outsource_date) <= ?
                  AND pn.ProcessName IN ($ph)
                GROUP BY bi.maker_id_no, COALESCE(ml.maker_id, bi.maker_id)
                ORDER BY total DESC");
            $st->execute($fp);
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as &$row){
                $tot=intval($row['total']); $ont=intval($row['ontime']);
                $row['ontime_pct']=$tot>=$minTxn?round($ont/$tot*100,1):null;
                $row['ng_pct']=$tot>0?round($row['ng_count']/$tot*100,1):0;
                $row['avg_days']=$row['avg_d']!==null?round($row['avg_d'],1):null;
                $row['is_enough']=$tot>=$minTxn;
            }
            unset($row);
            echo json_encode(['success'=>true,'data'=>$rows,'proc_names'=>$procNames,'period_start'=>$ds,'period_end'=>$de]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 製程列表
    if($_POST['action']==='get_process_list'){
        try{
            $rows=$pdo->query("SELECT DISTINCT ProcessName FROM process_no WHERE ProcessName IS NOT NULL ORDER BY ProcessName")->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['success'=>true,'data'=>$rows]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 建立廠商資料（從異常偵測快速建立）
    if($_POST['action']==='create_maker'){
        try{
            $mkNo  = trim($_POST['maker_id_no']??'');   if(!$mkNo) throw new Exception('廠商編號必填');
            $mkId  = trim($_POST['maker_id']??'');      if(!$mkId) throw new Exception('廠商簡稱必填');
            $mkAll = trim($_POST['maker_id_all']??'')?:null;
            $tel   = trim($_POST['m_tel']??'')?:null;
            $fax   = trim($_POST['m_fax']??'')?:null;
            $addr  = trim($_POST['invoice_address']??'')?:null;
            // 防重複
            $chk=$pdo->prepare("SELECT COUNT(*) FROM maker_list WHERE maker_id_no=?");
            $chk->execute([$mkNo]);
            if((int)$chk->fetchColumn()>0) throw new Exception('廠商編號 '.$mkNo.' 已存在於廠商資料表');
            $pdo->prepare("INSERT INTO maker_list (maker_id_no,maker_id,maker_id_all,m_tel,m_fax,invoice_address,Created_By) VALUES (?,?,?,?,?,?,?)")
                ->execute([$mkNo,$mkId,$mkAll,$tel,$fax,$addr,$userId]);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 偵測資料異常
    if($_POST['action']==='detect_anomalies'){
        try{
            $anomalies=[];

            // 1. 回廠日早於發包日
            $st=$pdo->query("
                SELECT bi.bom_ing_fid, bi.bom, bi.bom_sn,
                    COALESCE(ml.maker_id, bi.maker_id) AS maker_name,
                    bi.maker_id_no,
                    DATE(bi.outsource_date) AS outsource_d,
                    DATE(COALESCE(bi.return_date,
                        (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                         WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn AND tl.maker_from=bi.maker_id_no
                         ORDER BY tl.transfer_date DESC LIMIT 1))) AS return_d,
                    CASE WHEN bi.return_date IS NULL THEN 1 ELSE 0 END AS rd_from_log,
                    pn.ProcessName
                FROM bom_ing bi
                LEFT JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no
                LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                WHERE bi.outsource_date IS NOT NULL
                  AND COALESCE(bi.return_date,
                      (SELECT tl.transfer_date FROM bom_ing_transfer_log tl
                       WHERE tl.bom=bi.bom AND tl.bom_sn=bi.bom_sn AND tl.maker_from=bi.maker_id_no
                       ORDER BY tl.transfer_date DESC LIMIT 1)) < bi.outsource_date
                ORDER BY bi.outsource_date DESC LIMIT 200
            ");
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
                $r['type']='return_before_send';
                $r['desc']='回廠日（'.$r['return_d'].'）早於發包日（'.$r['outsource_d'].'）'.($r['rd_from_log']?' [來自移轉記錄]':'');
                $anomalies[]=$r;
            }

            // 2. 廠商編號存在於 bom_ing 但不在 maker_list
            $st2=$pdo->query("
                SELECT DISTINCT bi.maker_id_no, bi.maker_id AS maker_id_raw, COUNT(*) AS cnt
                FROM bom_ing bi
                WHERE bi.maker_id_no IS NOT NULL AND bi.maker_id_no<>''
                  AND NOT EXISTS (SELECT 1 FROM maker_list ml WHERE ml.maker_id_no=bi.maker_id_no)
                GROUP BY bi.maker_id_no, bi.maker_id
                ORDER BY cnt DESC LIMIT 50
            ");
            foreach($st2->fetchAll(PDO::FETCH_ASSOC) as $r){
                $r['type']='maker_not_in_list';
                $r['desc']='廠商編號 '.$r['maker_id_no'].'（'.$r['maker_id_raw'].'）有 '.$r['cnt'].' 筆發包紀錄，但不存在於廠商資料表（maker_list）';
                $anomalies[]=$r;
            }

            echo json_encode(['success'=>true,'data'=>$anomalies,'count'=>count($anomalies)]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // 修正單筆異常（更新 bom_ing 的 outsource_date 或 return_date）
    if($_POST['action']==='fix_anomaly'){
        try{
            $fid   = intval($_POST['bom_ing_fid']??0); if(!$fid) throw new Exception('未指定 fid');
            $field = $_POST['field']??''; // outsource_date or return_date
            $val   = trim($_POST['value']??'');
            if(!in_array($field,['outsource_date','return_date'])) throw new Exception('欄位不合法');
            if($val===''){ // 清空
                $pdo->prepare("UPDATE bom_ing SET `$field`=NULL, Modified_By=?, Modified_At=NOW() WHERE bom_ing_fid=?")
                    ->execute([$userId,$fid]);
            } else {
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$val)) throw new Exception('日期格式應為 YYYY-MM-DD');
                $pdo->prepare("UPDATE bom_ing SET `$field`=?, Modified_By=?, Modified_At=NOW() WHERE bom_ing_fid=?")
                    ->execute([$val,$userId,$fid]);
            }
            echo json_encode(['success'=>true]);
        }catch(Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'未知操作']); exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>外包廠商績效 KPI</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--primary:#2A3F54;--accent:#1ABB9C;--warn:#F39C12;--danger:#E74C3C;--info:#3498DB;--purple:#9B59B6;--bg:#F4F7FC;--card:#fff;--border:#E6E9ED;--text:#495057}
body{background:var(--bg);font-family:"Segoe UI",Arial,sans-serif;color:var(--text)}
.right_col{background:var(--bg)!important;overflow-x:hidden!important;box-sizing:border-box}
.pg-header{display:flex;align-items:center;justify-content:space-between;background:var(--card);border-radius:10px;padding:12px 20px;margin-bottom:14px;box-shadow:0 2px 6px rgba(0,0,0,.06);flex-wrap:wrap;gap:8px}
.pg-header h3{margin:0;font-size:17px;font-weight:700;color:var(--primary)}
.mcrow{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px}
.mc{background:var(--card);border-radius:10px;padding:14px 16px;box-shadow:0 2px 6px rgba(0,0,0,.05);border-left:4px solid var(--mc-color,var(--accent))}
.mc-val{font-size:24px;font-weight:700;color:var(--primary)}.mc-lbl{font-size:11px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}.mc-sub{font-size:11px;margin-top:3px;color:#aaa}
.setting-panel{background:var(--card);border-radius:10px;padding:16px 20px;margin-bottom:14px;box-shadow:0 2px 6px rgba(0,0,0,.05);display:none}
.setting-panel.open{display:block}
.s-row{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:14px}
.s-item label{font-size:12px;font-weight:600;color:var(--primary);display:block;margin-bottom:4px}
.s-item input[type=number]{width:90px;height:32px;border:1px solid var(--border);border-radius:6px;padding:0 8px;font-size:13px}
.s-hint{font-size:11px;color:#aaa;margin-top:3px}
.gr-wrap{border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-top:8px}
.gr-row{display:grid;grid-template-columns:80px 120px 120px 100px 36px;gap:5px;align-items:center;padding:6px 10px;border-bottom:1px solid #f0f0f0;font-size:12px}
.gr-row:last-child{border-bottom:none}.gr-hdr{background:#f8f9fa;font-weight:600;font-size:11px;color:#888}
.gr-row input,.gr-row select{height:27px;font-size:12px;border:1px solid var(--border);border-radius:5px;padding:0 6px}
.fbar{background:var(--card);border-radius:10px;padding:9px 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;box-shadow:0 2px 6px rgba(0,0,0,.05);margin-bottom:14px}
.fbar .form-control{height:32px;font-size:13px}
.mode-tabs{display:flex;gap:4px}.mode-tab{padding:5px 14px;font-size:12px;border:1px solid var(--border);border-radius:6px;cursor:pointer;background:#fff;color:#888;white-space:nowrap}
.mode-tab.on{background:var(--primary);color:#fff;border-color:var(--primary)}
.pnav{display:flex;align-items:center;gap:6px}
.pnav button{border:1px solid var(--border);background:#fff;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:13px}
.pnav button:hover{background:#f0f4ff}
.pnav-disp{font-size:13px;font-weight:600;min-width:110px;text-align:center;color:var(--primary)}
.mc-table{background:var(--card);border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,.05);overflow:hidden;margin-bottom:14px}
.mc-table table{width:100%;border-collapse:collapse;font-size:12px}
.mc-table thead th{background:#f8f9fa;color:#555;font-weight:700;padding:9px 10px;font-size:11px;border-bottom:2px solid var(--border);white-space:nowrap;cursor:pointer;user-select:none}
.mc-table thead th:hover{background:#f0f4ff}
.mc-table tbody td{padding:7px 10px;vertical-align:middle;border-bottom:1px solid #f0f2f5}
.mc-table tbody tr.mrow{cursor:pointer}
.mc-table tbody tr.mrow:hover td{background:#fafbff}
.mc-table tbody tr.drow td{padding:0;border-bottom:2px solid var(--accent)}
.d-inner{background:#f0fdf8;overflow-x:auto;display:none}
.d-inner.open{display:block}
.d-tbl{width:100%;border-collapse:collapse;font-size:11px}
.d-tbl th{background:#d4f5ed;color:var(--primary);padding:5px 8px;font-weight:600;white-space:nowrap}
.d-tbl td{padding:5px 8px;border-bottom:1px solid #e0f7f0;white-space:nowrap}
.kpi-bar{display:flex;align-items:center;gap:6px;min-width:120px}
.kpi-track{flex:1;height:8px;border-radius:4px;background:#eee;overflow:hidden}
.kpi-fill{height:100%;border-radius:4px}
.kpi-pct{font-size:11px;min-width:36px;text-align:right;font-weight:600}
.grade{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-size:11px;font-weight:700}
.grade-green{background:#d4f5ed;color:#0e7a5e}.grade-yellow{background:#fef3e2;color:#a06000}
.grade-red{background:#fde8e8;color:#a52020}.grade-purple{background:#f0ebfb;color:#6c3483}
.grade-gray{background:#f0f0f0;color:#999;font-size:10px}
.st-ok{color:#27AE60;font-weight:600}.st-lt{color:#E74C3C;font-weight:600}.st-nr{color:#888}
.pager{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;border-top:1px solid var(--border);font-size:12px;flex-wrap:wrap;gap:4px}
.pager-btns{display:flex;gap:3px}
.pager-btns button{padding:2px 9px;font-size:12px;border-radius:4px;border:1px solid var(--border);background:#fff;cursor:pointer}
.pager-btns button:hover{background:#f0f4ff}
.pager-btns button.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.loading{text-align:center;padding:30px;color:#aaa}
.modal-header{background:var(--primary);color:#fff}
.modal-header .modal-title,.modal-header .close{color:#fff}
.modal-header .close{opacity:1}
.ttab{display:flex;gap:4px;margin-bottom:10px}
.ttab-btn{padding:4px 12px;font-size:12px;border:1px solid var(--border);border-radius:5px;cursor:pointer;background:#fff;color:#888}
.ttab-btn.on{background:var(--primary);color:#fff;border-color:var(--primary)}
.tm-tab{padding:6px 16px;font-size:12px;border:none;background:none;color:#888;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;font-weight:600;}
.tm-tab.on{color:var(--primary);border-bottom-color:var(--primary);}
.tmt{display:none}.tmt.on{display:block}
.toast-wrap{position:fixed;bottom:20px;right:20px;z-index:9999}
.toast-msg{background:#333;color:#fff;padding:8px 16px;border-radius:6px;margin-top:6px;font-size:13px}
.toast-msg.success{background:var(--accent)}.toast-msg.error{background:var(--danger)}
.anomaly-card{border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:8px;background:#fff;}
.anomaly-card.type-return{border-left:4px solid var(--danger);}
.anomaly-card.type-maker{border-left:4px solid var(--warn);}
.anomaly-card .a-desc{font-size:12px;color:#555;margin-bottom:6px;}
.anomaly-card .a-fix{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.anomaly-card .a-fix input{height:28px;font-size:12px;border:1px solid var(--border);border-radius:5px;padding:0 6px;width:130px;}
.anomaly-card .a-badge{font-size:10px;font-weight:600;padding:2px 8px;border-radius:12px;display:inline-block;}
.a-badge-ret{background:#fde8e8;color:#a52020;}.a-badge-mk{background:#fef3e2;color:#a06000;}
.sp-tab.on{color:var(--primary);border-bottom-color:var(--primary)}
.spt{display:none}.spt.on{display:block}
@media(max-width:600px){.mcrow{grid-template-columns:1fr 1fr}.gr-row{grid-template-columns:60px 80px 100px 100px 80px 30px}}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">
<div style="padding:14px 14px 30px">

<!-- 頁頭 -->
<div class="pg-header">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <h3><i class="fa fa-industry" style="color:var(--accent);margin-right:6px;"></i>外包廠商績效 KPI</h3>
    <div class="mode-tabs">
      <button class="mode-tab on" onclick="setMode('month',this)">月</button>
      <button class="mode-tab" onclick="setMode('half',this)">半年</button>
      <button class="mode-tab" onclick="setMode('year',this)">全年</button>
    </div>
    <div class="pnav">
      <button onclick="changePeriod(-1)">&#8249;</button>
      <span class="pnav-disp" id="pnav-disp">—</span>
      <button onclick="changePeriod(1)">&#8250;</button>
    </div>
    <input type="month" id="mpicker" style="height:32px;border:1px solid var(--border);border-radius:6px;padding:0 8px;font-size:13px;" onchange="onPickerChange()">
  </div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-sm btn-default" onclick="toggleSettings()"><i class="fa fa-cog"></i> KPI 設定</button>
    <button class="btn btn-sm" style="background:var(--warn);color:#fff;" onclick="openAnomalyModal()"><i class="fa fa-exclamation-triangle"></i> 異常偵測</button>
    <button class="btn btn-sm btn-default" onclick="exportCsv()"><i class="fa fa-file-excel-o"></i> CSV</button>
    <button class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="exportPdf()"><i class="fa fa-file-pdf-o"></i> PDF報告</button>
  </div>
</div>

<!-- 設定面板 -->
<div class="setting-panel" id="sp">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
    <div style="font-weight:700;font-size:13px;color:var(--primary);"><i class="fa fa-sliders"></i> KPI 標準設定</div>
    <div style="display:flex;gap:6px;">
      <button class="btn btn-sm" style="background:var(--accent);color:#fff;" onclick="saveSettings()"><i class="fa fa-save"></i> 儲存設定</button>
      <button class="btn btn-sm btn-default" onclick="toggleSettings()">關閉</button>
      <span id="smsg" style="font-size:12px;align-self:center;margin-left:4px;"></span>
    </div>
  </div>

  <!-- 設定分頁 tabs -->
  <div style="display:flex;gap:2px;border-bottom:2px solid var(--border);margin-bottom:14px;">
    <button class="sp-tab on" onclick="spTab('basic',this)">基本設定</button>
    <button class="sp-tab" onclick="spTab('grade',this)">評級條件</button>
    <button class="sp-tab" onclick="spTab('maker',this)">廠商設定</button>
    <button class="sp-tab" onclick="spTab('proc',this)">製程設定</button>
  </div>

  <!-- Tab: 基本設定 -->
  <div id="spt-basic" class="spt on">
    <div class="s-row">
      <div class="s-item">
        <label>全域容忍天數（上班日）</label>
        <input type="number" id="s-tol" min="0" max="60">
        <div class="s-hint">發包日後幾個上班日內回廠算「準時」<br>今天前N上班日內發包的尚未到期，不計入</div>
      </div>
      <div class="s-item">
        <label>最低有效樣本筆數</label>
        <input type="number" id="s-min" min="1" max="100">
        <div class="s-hint">不足此筆數不評級（顯示「—」）</div>
      </div>
    </div>
  </div>

  <!-- Tab: 評級條件 -->
  <div id="spt-grade" class="spt">
    <div style="font-size:12px;color:#888;margin-bottom:8px;">由上往下匹配第一個符合的；準時率與NG率同時符合才成立。
      <button class="btn btn-xs btn-default" style="margin-left:8px;" onclick="addGR()"><i class="fa fa-plus"></i> 新增等級</button>
    </div>
    <div class="gr-wrap">
      <div class="gr-row gr-hdr"><div>等級代號</div><div>準時率 ≥(%)</div><div>NG率 ≤(%)</div><div>顏色</div><div></div></div>
      <div id="gr-body"></div>
    </div>
  </div>

  <!-- Tab: 廠商設定 -->
  <div id="spt-maker" class="spt">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <!-- 例外廠商 -->
      <div>
        <div style="font-weight:600;font-size:12px;color:var(--danger);margin-bottom:8px;"><i class="fa fa-ban"></i> 例外廠商 <span style="font-weight:400;color:#aaa;font-size:11px;">不納入KPI計算</span></div>
        <div style="display:flex;gap:5px;margin-bottom:8px;position:relative;">
          <div style="position:relative;flex:1;">
            <input type="text" id="excl-input" class="form-control" placeholder="搜尋廠商名稱/編號" autocomplete="off" style="height:30px;font-size:12px;" oninput="onExclInput()" onkeydown="onExclKey(event)">
            <div id="excl-ac" style="display:none;position:absolute;top:32px;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:9999;max-height:180px;overflow-y:auto;font-size:12px;"></div>
          </div>
          <input type="text" id="excl-reason" class="form-control" placeholder="原因" style="height:30px;font-size:12px;width:90px;">
          <button class="btn btn-xs btn-default" onclick="addExcluded()"><i class="fa fa-plus"></i></button>
        </div>
        <div id="excl-tags" style="display:flex;flex-wrap:wrap;gap:4px;min-height:24px;"></div>
      </div>
      <!-- 特殊廠商容忍天數 -->
      <div>
        <div style="font-weight:600;font-size:12px;color:var(--info);margin-bottom:8px;"><i class="fa fa-clock-o"></i> 特殊廠商容忍天數 <span style="font-weight:400;color:#aaa;font-size:11px;">優先於全域設定</span></div>
        <div style="display:flex;gap:5px;margin-bottom:8px;position:relative;">
          <div style="position:relative;flex:1;">
            <input type="text" id="spec-input" class="form-control" placeholder="搜尋廠商名稱/編號" autocomplete="off" style="height:30px;font-size:12px;" oninput="onSpecInput()" onkeydown="onSpecKey(event)">
            <div id="spec-ac" style="display:none;position:absolute;top:32px;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:9999;max-height:180px;overflow-y:auto;font-size:12px;"></div>
          </div>
          <input type="number" id="spec-days" min="1" max="90" value="7" style="width:50px;height:30px;border:1px solid var(--border);border-radius:6px;padding:0 5px;font-size:12px;">
          <span style="line-height:30px;font-size:11px;color:#888;">天</span>
          <input type="text" id="spec-reason" class="form-control" placeholder="原因" style="height:30px;font-size:12px;width:80px;">
          <button class="btn btn-xs btn-default" onclick="addSpecial()"><i class="fa fa-plus"></i></button>
        </div>
        <div id="spec-tags" style="display:flex;flex-wrap:wrap;gap:4px;min-height:24px;"></div>
      </div>
    </div>
  </div>

  <!-- Tab: 製程設定 -->
  <div id="spt-proc" class="spt">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <!-- 例外製程 -->
      <div>
        <div style="font-weight:600;font-size:12px;color:var(--danger);margin-bottom:8px;"><i class="fa fa-ban"></i> 例外製程 <span style="font-weight:400;color:#aaa;font-size:11px;">此製程的外包紀錄不納入KPI</span></div>
        <div style="display:flex;gap:5px;margin-bottom:8px;position:relative;">
          <div style="position:relative;flex:1;">
            <input type="text" id="exclp-input" class="form-control" placeholder="搜尋製程名稱" autocomplete="off" style="height:30px;font-size:12px;" oninput="onExclPInput()" onkeydown="onExclPKey(event)">
            <div id="exclp-ac" style="display:none;position:absolute;top:32px;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:9999;max-height:180px;overflow-y:auto;font-size:12px;"></div>
          </div>
          <input type="text" id="exclp-reason" class="form-control" placeholder="原因" style="height:30px;font-size:12px;width:90px;">
          <button class="btn btn-xs btn-default" onclick="addExclProc()"><i class="fa fa-plus"></i></button>
        </div>
        <div id="exclp-tags" style="display:flex;flex-wrap:wrap;gap:4px;min-height:24px;"></div>
      </div>
      <!-- 特殊製程容忍天數 -->
      <div>
        <div style="font-weight:600;font-size:12px;color:var(--info);margin-bottom:8px;"><i class="fa fa-clock-o"></i> 特殊製程容忍天數 <span style="font-weight:400;color:#aaa;font-size:11px;">廠商設定優先於此</span></div>
        <div style="display:flex;gap:5px;margin-bottom:8px;position:relative;">
          <div style="position:relative;flex:1;">
            <input type="text" id="specp-input" class="form-control" placeholder="搜尋製程名稱" autocomplete="off" style="height:30px;font-size:12px;" oninput="onSpecPInput()" onkeydown="onSpecPKey(event)">
            <div id="specp-ac" style="display:none;position:absolute;top:32px;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:9999;max-height:180px;overflow-y:auto;font-size:12px;"></div>
          </div>
          <input type="number" id="specp-days" min="1" max="90" value="7" style="width:50px;height:30px;border:1px solid var(--border);border-radius:6px;padding:0 5px;font-size:12px;">
          <span style="line-height:30px;font-size:11px;color:#888;">天</span>
          <input type="text" id="specp-reason" class="form-control" placeholder="原因" style="height:30px;font-size:12px;width:80px;">
          <button class="btn btn-xs btn-default" onclick="addSpecProc()"><i class="fa fa-plus"></i></button>
        </div>
        <div id="specp-tags" style="display:flex;flex-wrap:wrap;gap:4px;min-height:24px;"></div>
      </div>
    </div>
  </div>
</div>

<!-- 統計卡片 -->
<div class="mcrow">
  <div class="mc" style="--mc-color:var(--info)"><div class="mc-val" id="mc-v">—</div><div class="mc-lbl">評估廠商數</div><div class="mc-sub" id="mc-period"></div></div>
  <div class="mc" style="--mc-color:var(--primary)"><div class="mc-val" id="mc-t">—</div><div class="mc-lbl">發包筆數（已到容忍期）</div><div class="mc-sub" id="mc-cut"></div></div>
  <div class="mc" style="--mc-color:var(--accent)"><div class="mc-val" id="mc-op">—</div><div class="mc-lbl">整體準時交貨率</div></div>
  <div class="mc" style="--mc-color:var(--danger)"><div class="mc-val" id="mc-ng">—</div><div class="mc-lbl">整體 NG 率</div></div>
  <div class="mc" style="--mc-color:#888"><div class="mc-val" id="mc-wd">—</div><div class="mc-lbl">本期上班日數</div><div class="mc-sub" style="color:#3498DB;font-size:10px;">DEBUG</div></div>
</div>

<!-- 篩選 -->
<div class="fbar">
  <input type="text" id="f-maker" class="form-control" placeholder="🔍 搜尋廠商" style="width:150px;" oninput="filterTable()">
  <select id="f-proc" class="form-control" style="width:130px;" onchange="filterTable()"><option value="">全部製程</option></select>
  <select id="f-grade" class="form-control" style="width:100px;" onchange="filterTable()"><option value="">全部評級</option></select>
  <label style="margin:0;font-size:13px;display:flex;align-items:center;gap:5px;cursor:pointer;"><input type="checkbox" id="f-enough" onchange="filterTable()"> 僅有效樣本</label>
  <span id="rcnt" style="font-size:12px;color:#aaa;margin-left:auto;"></span>
</div>

<!-- 主表 -->
<div class="mc-table">
  <div style="overflow-x:auto;">
    <table>
      <thead><tr>
        <th onclick="sortBy('grade')" style="width:52px">評級 <i class="fa fa-sort"></i></th>
        <th onclick="sortBy('maker_name')">廠商 <i class="fa fa-sort"></i></th>
        <th>主要製程</th>
        <th onclick="sortBy('total')" style="text-align:center;">發包筆 <i class="fa fa-sort"></i></th>
        <th onclick="sortBy('returned')" style="text-align:center;">已回廠 <i class="fa fa-sort"></i></th>
        <th onclick="sortBy('ontime_pct')" style="min-width:150px;">準時率 <i class="fa fa-sort"></i></th>
        <th onclick="sortBy('ng_pct')">NG率 <i class="fa fa-sort"></i></th>
        <th onclick="sortBy('avg_days')">平均日曆天 <i class="fa fa-sort"></i></th>
        <th>QC分布</th>
        <th style="width:50px;text-align:center;">趨勢</th>
      </tr></thead>
      <tbody id="ktbody"><tr><td colspan="10" class="loading"><i class="fa fa-spinner fa-spin"></i></td></tr></tbody>
    </table>
  </div>
  <div id="tnote" style="padding:8px 14px;font-size:11px;color:#aaa;border-top:1px solid var(--border);"></div>
  <div class="pager" id="main-pager">
    <span id="pager-info" style="color:#888;"></span>
    <div class="pager-btns" id="pager-btns"></div>
  </div>
</div>

</div></div></div></div>
<div class="toast-wrap" id="tw"></div>

<!-- Modal: 建立廠商資料 -->
<div class="modal fade" id="createMakerModal" tabindex="-1" style="z-index:1060;">
<div class="modal-dialog" style="max-width:480px;"><div class="modal-content">
<div class="modal-header" style="background:var(--warn);"><button class="close" data-dismiss="modal" onclick="$('#anomalyModal').modal('show');"><span>&times;</span></button><h4 class="modal-title" style="color:#fff;"><i class="fa fa-plus-circle"></i> 建立廠商資料</h4></div>
<div class="modal-body" style="padding:16px;">
  <div style="font-size:12px;color:#888;margin-bottom:12px;">以下資料將新增至 maker_list 廠商資料表</div>
  <div style="display:grid;grid-template-columns:110px 1fr;gap:8px 10px;align-items:center;font-size:13px;">
    <label style="font-weight:600;color:var(--primary);">廠商編號</label>
    <input type="text" id="cm-no" class="form-control" readonly style="background:#f8f9fa;color:#555;height:32px;font-size:13px;">
    <label style="font-weight:600;color:var(--primary);">廠商簡稱 <span style="color:var(--danger);">*</span></label>
    <input type="text" id="cm-id" class="form-control" placeholder="必填" style="height:32px;font-size:13px;">
    <label style="color:#555;">廠商全稱</label>
    <input type="text" id="cm-all" class="form-control" placeholder="選填（發票用）" style="height:32px;font-size:13px;">
    <label style="color:#555;">電話</label>
    <input type="text" id="cm-tel" class="form-control" placeholder="選填" style="height:32px;font-size:13px;">
    <label style="color:#555;">傳真</label>
    <input type="text" id="cm-fax" class="form-control" placeholder="選填" style="height:32px;font-size:13px;">
    <label style="color:#555;">發票地址</label>
    <input type="text" id="cm-addr" class="form-control" placeholder="選填" style="height:32px;font-size:13px;">
  </div>
  <div id="cm-msg" style="margin-top:10px;font-size:12px;"></div>
</div>
<div class="modal-footer" style="padding:10px 16px;">
  <button class="btn btn-sm" style="background:var(--accent);color:#fff;" onclick="submitCreateMaker()"><i class="fa fa-save"></i> 儲存建立</button>
  <button class="btn btn-sm btn-default" data-dismiss="modal" onclick="$('#anomalyModal').modal('show');">取消</button>
</div>
</div></div></div>

<!-- Modal: 異常偵測 -->
<div class="modal fade" id="anomalyModal" tabindex="-1">
<div class="modal-dialog" style="width:92%;max-width:900px;"><div class="modal-content">
<div class="modal-header"><button class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-exclamation-triangle" style="color:var(--warn);"></i> 資料異常偵測</h4></div>
<div class="modal-body" style="padding:14px;">
  <div id="anomaly-summary" style="margin-bottom:12px;font-size:13px;"></div>
  <div id="anomaly-list"></div>
</div>
<div class="modal-footer" style="padding:10px 14px;">
  <button class="btn btn-sm btn-default" onclick="runAnomalyDetect()" id="anomaly-rerun-btn"><i class="fa fa-refresh"></i> 重新掃描</button>
  <button class="btn btn-sm btn-default" data-dismiss="modal">關閉</button>
</div>
</div></div></div>

<!-- Modal: 趨勢 -->
<div class="modal fade" id="trendModal" tabindex="-1">
<div class="modal-dialog" style="width:90%;max-width:860px;"><div class="modal-content">
<div class="modal-header" style="display:flex;align-items:center;">
  <button class="close" data-dismiss="modal" style="margin-right:8px;"><span>&times;</span></button>
  <h4 class="modal-title" id="ttitle" style="flex:1;">趨勢</h4>
  <button class="btn btn-xs" style="background:var(--danger);color:#fff;margin-right:4px;" onclick="exportTrendPdf()"><i class="fa fa-file-pdf-o"></i> PDF</button>
</div>
<div class="modal-body" style="padding:14px;">
  <!-- 主 Tab -->
  <div style="display:flex;gap:2px;border-bottom:2px solid var(--border);margin-bottom:12px;">
    <button class="tm-tab on" onclick="tmTab('monthly',this)">近12個月趨勢</button>
    <button class="tm-tab" onclick="tmTab('yearly',this)">歷年變化</button>
    <button class="tm-tab" onclick="tmTab('compare',this)">相同製程廠商比較</button>
  </div>

  <!-- Tab: 近12個月 -->
  <div id="tmt-monthly" class="tmt on">
    <div class="ttab">
      <button class="ttab-btn on" onclick="switchTT('ontime',this)">準時率</button>
      <button class="ttab-btn" onclick="switchTT('ng',this)">NG率</button>
      <button class="ttab-btn" onclick="switchTT('days',this)">平均天數</button>
      <button class="ttab-btn" onclick="switchTT('count',this)">筆數</button>
    </div>
    <canvas id="tchart" style="width:100%;display:block;" height="240"></canvas>
    <div id="tdetail" style="margin-top:10px;overflow-x:auto;font-size:11px;"></div>
  </div>

  <!-- Tab: 歷年 -->
  <div id="tmt-yearly" class="tmt">
    <div class="ttab">
      <button class="ttab-btn on" onclick="switchYT('ontime',this)">準時率</button>
      <button class="ttab-btn" onclick="switchYT('ng',this)">NG率</button>
      <button class="ttab-btn" onclick="switchYT('days',this)">平均天數</button>
      <button class="ttab-btn" onclick="switchYT('count',this)">筆數</button>
    </div>
    <canvas id="ychart" style="width:100%;display:block;" height="240"></canvas>
    <div id="ydetail" style="margin-top:10px;overflow-x:auto;font-size:11px;"></div>
  </div>

  <!-- Tab: 相同製程比較 -->
  <div id="tmt-compare" class="tmt">
    <div id="cmp-content"><div class="loading"><i class="fa fa-spinner fa-spin"></i></div></div>
  </div>
</div>
</div></div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
var G={mode:'month',period:'',rawData:[],filteredData:[],sCol:'ontime_pct',sDir:'asc',settings:{tolerance:3,min_txn:5,grade_rules:[]},summary:{},tTab:'ontime',tData:[],
    page:1,pageSize:10};  // 主列表分頁
var GR=[];

$(function(){
    var n=new Date();
    G.period=n.getFullYear()+'-'+String(n.getMonth()+1).padStart(2,'0');
    $('#mpicker').val(G.period);
    updatePD();
    loadSettings(loadData);
    loadProcList();
});

// ── 模式/期間 ──────────────────────────────────────
function setMode(m,el){
    G.mode=m; $('.mode-tab').removeClass('on'); $(el).addClass('on');
    var n=new Date();
    if(m==='month'){G.period=n.getFullYear()+'-'+String(n.getMonth()+1).padStart(2,'0');$('#mpicker').show();}
    else if(m==='half'){G.period=n.getFullYear()+'-H'+(n.getMonth()<6?'1':'2');$('#mpicker').hide();}
    else{G.period=String(n.getFullYear());$('#mpicker').hide();}
    updatePD(); loadData();
}
function changePeriod(d){
    if(G.mode==='month'){var dt=new Date(G.period+'-01');dt.setMonth(dt.getMonth()+d);G.period=dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0');$('#mpicker').val(G.period);}
    else if(G.mode==='half'){var yr=parseInt(G.period);var h=G.period.includes('H2')?2:1;h+=d;if(h<1){h=2;yr--;}if(h>2){h=1;yr++;}G.period=yr+'-H'+h;}
    else{G.period=String(parseInt(G.period)+d);}
    updatePD(); loadData();
}
function onPickerChange(){G.period=$('#mpicker').val();updatePD();loadData();}
function updatePD(){
    var t=G.period;
    if(G.mode==='month'){var p=G.period.split('-');t=p[0]+'年'+parseInt(p[1])+'月';}
    else if(G.mode==='half'){t=G.period.split('-')[0]+'年'+(G.period.includes('H1')?'上半年':'下半年');}
    else t=G.period+'年';
    $('#pnav-disp').text(t);
}

// ── 設定 ────────────────────────────────────────────
var G_excl=[];  var G_spec=[];
var G_exclP=[]; var G_specP=[];
var G_exclSelected={maker_id_no:'',maker_id:''};
var G_specSelected={maker_id_no:'',maker_id:''};
var G_exclPSelected={process_no:0,process_name:''};
var G_specPSelected={process_no:0,process_name:''};
var G_acTimer=null;

function spTab(id,el){
    $('.sp-tab').removeClass('on');$(el).addClass('on');
    $('.spt').removeClass('on');$('#spt-'+id).addClass('on');
}

function loadSettings(cb){
    ajx({action:'get_settings'},function(r){
        if(!r.success)return;
        var d=r.data;
        if(d.ontime_tolerance_days)$('#s-tol').val(d.ontime_tolerance_days.value);
        if(d.min_txn_count)$('#s-min').val(d.min_txn_count.value);
        if(d.grade_rules){try{GR=JSON.parse(d.grade_rules.value);}catch(e){GR=[];}renderGR();buildGF();}
        if(d.excluded_makers){try{G_excl=JSON.parse(d.excluded_makers.value);}catch(e){G_excl=[];}renderExclTags();}
        if(d.special_makers){try{G_spec=JSON.parse(d.special_makers.value);}catch(e){G_spec=[];}renderSpecTags();}
        if(d.excluded_procs){try{G_exclP=JSON.parse(d.excluded_procs.value);}catch(e){G_exclP=[];}renderExclPTags();}
        if(d.special_procs){try{G_specP=JSON.parse(d.special_procs.value);}catch(e){G_specP=[];}renderSpecPTags();}
        if(typeof cb==='function')cb();
    });
}
function toggleSettings(){$('#sp').toggleClass('open');if($('#sp').hasClass('open'))loadSettings();}
function renderGR(){
    var h='';
    GR.forEach(function(r,i){
        var cols=['green','yellow','red','purple','gray'];
        var opts=cols.map(function(c){return'<option value="'+c+'"'+(r.color===c?' selected':'')+'>'+c+'</option>';}).join('');
        h+='<div class="gr-row">'
          +'<input type="text" value="'+esc(r.grade||'')+'" placeholder="如：A" onchange="GR['+i+'].grade=this.value">'
          +'<input type="number" min="0" max="100" value="'+esc(r.ontime_gte||0)+'" onchange="GR['+i+'].ontime_gte=parseFloat(this.value)">'
          +'<input type="number" min="0" max="100" step="0.1" value="'+esc(r.ng_lte||0)+'" onchange="GR['+i+'].ng_lte=parseFloat(this.value)">'
          +'<select onchange="GR['+i+'].color=this.value">'+opts+'</select>'
          +'<button class="btn btn-xs btn-default" onclick="GR.splice('+i+',1);renderGR();" style="color:var(--danger);"><i class="fa fa-times"></i></button>'
          +'</div>';
    });
    $('#gr-body').html(h);
}
function addGR(){GR.push({grade:'',ontime_gte:0,ng_lte:100,color:'gray'});renderGR();}
function buildGF(){
    var h='<option value="">全部評級</option>';
    GR.forEach(function(r){h+='<option value="'+esc(r.grade)+'">'+esc(r.grade)+'</option>';});
    h+='<option value="—">未達樣本</option>';
    $('#f-grade').html(h);
}
function saveSettings(){
    var tol=$('#s-tol').val();var min=$('#s-min').val();
    if(!tol||!min){$('#smsg').html('<span style="color:red">請填寫所有欄位</span>');return;}
    ajx({action:'save_settings',ontime_tolerance_days:tol,min_txn_count:min,grade_rules:JSON.stringify(GR)},function(r){
        if(!r.success){$('#smsg').html('<span style="color:red">'+esc(r.message)+'</span>');return;}
        $('#smsg').html('<span style="color:var(--accent)"><i class="fa fa-check"></i> 已儲存</span>');
        buildGF();
        setTimeout(function(){$('#sp').removeClass('open');loadData();},700);
    });
}

// ── 例外廠商 ──────────────────────────────────────────
function renderExclTags(){
    var h='';
    if(!G_excl.length){ h='<span style="font-size:12px;color:#bbb;">尚未設定例外廠商</span>'; }
    else {
        G_excl.forEach(function(e){
            h+='<span style="display:inline-flex;align-items:center;gap:5px;background:#fde8e8;color:#a52020;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:500;">'
              +'<i class="fa fa-ban" style="font-size:10px;"></i>'+esc(e.maker_id)
              +(e.reason?'<span style="color:#c0392b;font-size:10px;">('+esc(e.reason)+')</span>':'')
              +'<button onclick="delExcluded('+e.id+')" style="background:none;border:none;color:#a52020;cursor:pointer;padding:0;font-size:12px;line-height:1;" title="移除">&times;</button>'
              +'</span>';
        });
    }
    $('#excl-tags').html(h);
}

function onExclInput(){
    var term=$('#excl-input').val().trim();
    G_exclSelected={maker_id_no:'',maker_id:''};
    clearTimeout(G_acTimer);
    if(term.length<1){$('#excl-ac').hide();return;}
    G_acTimer=setTimeout(function(){
        ajx({action:'search_maker',term:term},function(r){
            if(!r.success||!r.data.length){$('#excl-ac').hide();return;}
            var h='';
            r.data.forEach(function(m){
                h+='<div style="padding:7px 12px;cursor:pointer;border-bottom:1px solid #f5f5f5;" '
                  +'onmouseover="this.style.background=\'#f0fdf8\'" onmouseout="this.style.background=\'\'" '
                  +'onclick="selectAC(\''+esc(m.maker_id_no)+'\',\''+esc(m.maker_id)+'\');">'
                  +'<strong>'+esc(m.maker_id)+'</strong>'
                  +(m.maker_id_no?'<span style="color:#aaa;font-size:11px;margin-left:6px;">'+esc(m.maker_id_no)+'</span>':'')
                  +'</div>';
            });
            $('#excl-ac').html(h).show();
        });
    },250);
}
function onExclKey(e){ if(e.key==='Escape'){$('#excl-ac').hide();} }
function selectAC(no,nm){
    G_exclSelected={maker_id_no:no,maker_id:nm};
    $('#excl-input').val(nm);
    $('#excl-ac').hide();
}
function addExcluded(){
    var nm=G_exclSelected.maker_id||$('#excl-input').val().trim();
    if(!nm){toast('請輸入廠商名稱','error');return;}
    var reason=$('#excl-reason').val().trim();
    ajx({action:'add_excluded',maker_id_no:G_exclSelected.maker_id_no,maker_id:nm,reason:reason},function(r){
        if(!r.success){toast(r.message,'error');return;}
        G_excl.push({id:r.id,maker_id_no:G_exclSelected.maker_id_no,maker_id:nm,reason:reason});
        renderExclTags();
        $('#excl-input').val(''); $('#excl-reason').val('');
        G_exclSelected={maker_id_no:'',maker_id:''};
        toast('已新增例外廠商：'+nm,'success');
        loadData(); // 即時重算
    });
}
function delExcluded(id){
    ajx({action:'delete_excluded',id:id},function(r){
        if(!r.success){toast(r.message,'error');return;}
        G_excl=G_excl.filter(function(e){return e.id!==id;});
        renderExclTags();
        toast('已移除','success');
        loadData(); // 即時重算
    });
}

// ── 特殊廠商容忍天數 ──────────────────────────────────
function renderSpecTags(){
    var h='';
    if(!G_spec.length){ h='<span style="font-size:12px;color:#bbb;">尚未設定特殊容忍天數廠商</span>'; }
    else {
        G_spec.forEach(function(e){
            h+='<span style="display:inline-flex;align-items:center;gap:5px;background:#e6f1fb;color:#0C447C;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:500;">'
              +'<i class="fa fa-clock-o" style="font-size:10px;"></i>'+esc(e.maker_id)
              +' <strong>'+e.tolerance_days+'</strong> 天'
              +(e.reason?'<span style="color:#185FA5;font-size:10px;">('+esc(e.reason)+')</span>':'')
              +'<button onclick="delSpecial('+e.id+')" style="background:none;border:none;color:#0C447C;cursor:pointer;padding:0;font-size:12px;line-height:1;" title="移除">&times;</button>'
              +'</span>';
        });
    }
    $('#spec-tags').html(h);
}
var G_specTimer=null;
function onSpecInput(){
    var term=$('#spec-input').val().trim();
    G_specSelected={maker_id_no:'',maker_id:''};
    clearTimeout(G_specTimer);
    if(term.length<1){$('#spec-ac').hide();return;}
    G_specTimer=setTimeout(function(){
        ajx({action:'search_maker',term:term},function(r){
            if(!r.success||!r.data.length){$('#spec-ac').hide();return;}
            var h='';
            r.data.forEach(function(m){
                h+='<div style="padding:7px 12px;cursor:pointer;border-bottom:1px solid #f5f5f5;" '
                  +'onmouseover="this.style.background=\'#e6f1fb\'" onmouseout="this.style.background=\'\'" '
                  +'onclick="selectSpecAC(\''+esc(m.maker_id_no)+'\',\''+esc(m.maker_id)+'\');">'
                  +'<strong>'+esc(m.maker_id)+'</strong>'
                  +(m.maker_id_no?'<span style="color:#aaa;font-size:11px;margin-left:6px;">'+esc(m.maker_id_no)+'</span>':'')
                  +'</div>';
            });
            $('#spec-ac').html(h).show();
        });
    },250);
}
function onSpecKey(e){ if(e.key==='Escape'){$('#spec-ac').hide();} }
function selectSpecAC(no,nm){
    G_specSelected={maker_id_no:no,maker_id:nm};
    $('#spec-input').val(nm);
    $('#spec-ac').hide();
    // 若此廠商已有設定，預填天數
    var existing=G_spec.find(function(s){return s.maker_id===nm;});
    if(existing) $('#spec-days').val(existing.tolerance_days);
}
function addSpecial(){
    var nm=G_specSelected.maker_id||$('#spec-input').val().trim();
    if(!nm){toast('請輸入廠商名稱','error');return;}
    var days=parseInt($('#spec-days').val()||7);
    if(!days||days<1){toast('容忍天數至少1天','error');return;}
    var reason=$('#spec-reason').val().trim();
    ajx({action:'save_special_maker',maker_id_no:G_specSelected.maker_id_no,maker_id:nm,tolerance_days:days,reason:reason},function(r){
        if(!r.success){toast(r.message,'error');return;}
        // 更新本地陣列
        var idx=G_spec.findIndex(function(s){return s.maker_id===nm;});
        var obj={id:r.id,maker_id_no:G_specSelected.maker_id_no,maker_id:nm,tolerance_days:days,reason:reason};
        if(idx>=0) G_spec[idx]=obj; else G_spec.push(obj);
        renderSpecTags();
        $('#spec-input').val('');$('#spec-reason').val('');$('#spec-days').val(7);
        G_specSelected={maker_id_no:'',maker_id:''};
        toast('已設定 '+nm+' 容忍天數：'+days+' 天','success');
        loadData();
    });
}
function delSpecial(id){
    ajx({action:'delete_special_maker',id:id},function(r){
        if(!r.success){toast(r.message,'error');return;}
        G_spec=G_spec.filter(function(s){return s.id!==id;});
        renderSpecTags();
        toast('已移除特殊設定','success');
        loadData();
    });
}

// ── 載入資料 ─────────────────────────────────────────
function loadData(){
    $('#ktbody').html('<tr><td colspan="10" class="loading"><i class="fa fa-spinner fa-spin"></i></td></tr>');
    detailData={}; detailPages={}; expandedSet={};  // 清除快取
    ajx({action:'get_kpi_data',mode:G.mode,period:G.period,maker_filter:'',proc_filter:''},function(r){
        if(!r.success){toast(r.message||'載入失敗','error');$('#ktbody').html('<tr><td colspan="10" style="text-align:center;color:red;padding:20px;">'+esc(r.message)+'</td></tr>');return;}
        G.rawData=r.data||[];G.settings=r.settings||G.settings;G.summary=r.summary||{};
        renderSummary();filterTable();updateNote();
    });
}
function renderSummary(){
    var s=G.summary;
    $('#mc-v').text(s.vendor_count||0);$('#mc-t').text((s.total_count||0).toLocaleString());
    $('#mc-period').text((s.period_start||'')+' ~ '+(s.period_end||''));
    $('#mc-cut').text('容忍截止：'+(s.cutoff||''));
    var op=s.ontime_pct;
    $('#mc-op').html(op!==null?'<span style="color:'+(op>=80?'#27AE60':'var(--danger)')+'">'+op+'%</span>':'<span style="color:#aaa">—</span>');
    var ng=s.ng_pct||0;
    $('#mc-ng').html('<span style="color:'+(ng<=3?'#27AE60':'var(--danger)')+'">'+ng+'%</span>');
    $('#mc-wd').text((s.workday_count||0)+' 天');
}
function updateNote(){
    var t=G.settings.tolerance;
    $('#tnote').text('準時定義：發包日後 '+t+' 個上班日內回廠　|　今日往前 '+t+' 個上班日以內發包的尚未到期不計入　|　未回廠且容忍期已過算逾期　|　「bom無交期」= bom.Delivery_date 為空，不影響準時率計算（準時以發包日+容忍天數為截止）');
}
function loadProcList(){
    ajx({action:'get_process_list'},function(r){
        if(!r.success)return;
        var h='<option value="">全部製程</option>';
        r.data.forEach(function(p){h+='<option value="'+esc(p)+'">'+esc(p)+'</option>';});
        $('#f-proc').html(h);
    });
}

// ── 篩選/排序 ─────────────────────────────────────────
function filterTable(){
    var mk=($('#f-maker').val()||'').toLowerCase();
    var pr=$('#f-proc').val();var gr=$('#f-grade').val();var en=$('#f-enough').prop('checked');
    G.filteredData=G.rawData.filter(function(r){
        if(mk&&!(r.maker_name||'').toLowerCase().includes(mk))return false;
        var ps=(r.proc_names||'')+(r.proc_category||'')+(r.proc_items||'');
        if(pr&&!ps.includes(pr))return false;
        if(gr&&r.grade!==gr)return false;
        if(en&&!r.is_enough)return false;
        return true;
    });
    G.page=1;
    sortAndRender();
    $('#rcnt').text(G.filteredData.length+' / '+G.rawData.length+' 筆廠商');
}
function sortBy(col){
    if(G.sCol===col)G.sDir=G.sDir==='asc'?'desc':'asc';
    else{G.sCol=col;G.sDir='desc';}
    sortAndRender();
}
function sortAndRender(){
    var col=G.sCol,dir=G.sDir;
    var go={};GR.forEach(function(r,i){go[r.grade]=i;});go['—']=9999;
    G.filteredData.sort(function(a,b){
        if(col==='grade'){var va=go[a.grade]??99;var vb=go[b.grade]??99;return dir==='asc'?va-vb:vb-va;}
        if(col==='maker_name')return dir==='asc'?(a.maker_name||'').localeCompare(b.maker_name||''):(b.maker_name||'').localeCompare(a.maker_name||'');
        var va=parseFloat(a[col])||0;var vb=parseFloat(b[col])||0;return dir==='asc'?va-vb:vb-va;
    });
    renderTable();
}

// ── 渲染表格 ─────────────────────────────────────────
function gradeColor(pct,ng,rules){
    for(var i=0;i<rules.length;i++){
        if(pct>=(rules[i].ontime_gte||0)){
            var cm={green:'#27AE60',yellow:'#F39C12',red:'#E74C3C',purple:'#9B59B6',gray:'#aaa'};
            return cm[rules[i].color]||'#aaa';
        }
    }
    return '#aaa';
}
function renderTable(){
    if(!G.filteredData.length){$('#ktbody').html('<tr><td colspan="10" style="text-align:center;padding:30px;color:#aaa;">本期間無資料</td></tr>');$('#pager-info').text('');$('#pager-btns').html('');return;}
    var rules=G.settings.grade_rules||[];
    var total=G.filteredData.length;
    var totalPages=Math.ceil(total/G.pageSize);
    G.page=Math.max(1,Math.min(G.page,totalPages));
    var start=(G.page-1)*G.pageSize;
    var pageData=G.filteredData.slice(start,start+G.pageSize);
    var h='';
    pageData.forEach(function(r,pi){
        // 用廠商唯一 key（不隨排序改變），避免排序後 idx 對應到錯誤的 detailData
        var mk=encodeURIComponent((r.maker_id_no||r.maker_name||pi).toString()).replace(/%/g,'_');
        var op=r.ontime_pct;
        var opc=op===null?'#ccc':(op>=90?'#27AE60':op>=75?'#F39C12':'#E74C3C');
        var opbar=op!==null
            ?'<div class="kpi-bar"><div class="kpi-track"><div class="kpi-fill" style="width:'+Math.min(100,op)+'%;background:'+opc+'"></div></div><span class="kpi-pct" style="color:'+opc+'">'+op+'%</span></div>'
            :'<span style="color:#ccc;font-size:11px;">樣本不足</span>';
        var ng=r.ng_pct;var ngc=ng<=3?'#27AE60':ng<=6?'#F39C12':'#E74C3C';
        var gc='grade-'+(r.grade_color||'gray');
        var gh='<span class="grade '+gc+'" title="'+(r.is_enough?'':'有效樣本不足')+'">'+esc(r.grade)+'</span>';
        var qcp=[];
        if(r.ok_count>0)qcp.push('<span style="color:#27AE60;">OK '+r.ok_count+'</span>');
        if(r.ng_count>0)qcp.push('<span style="color:#E74C3C;">NG '+r.ng_count+'</span>');
        if(r.qq_count>0)qcp.push('<span style="color:#F39C12;">QQ '+r.qq_count+'</span>');
        if(r.aod_count>0)qcp.push('<span style="color:#9B59B6;">AOD '+r.aod_count+'</span>');
        var qch=qcp.length?qcp.join(' '):'<span style="color:#ccc">—</span>';
        var ps=esc(r.proc_names||r.proc_category||'—');
        var nd=''; // 已移除「bom無交期」顯示
        var rj=JSON.stringify(r).replace(/"/g,'&quot;');
        // 特殊容忍天數標記
        var specBadge=r.is_special_tol
            ?'<span style="font-size:10px;background:#e6f1fb;color:#185FA5;border-radius:10px;padding:1px 6px;margin-left:4px;"><i class="fa fa-clock-o"></i> '+r.tolerance+'天</span>'
            :'';
        h+='<tr class="mrow" onclick="toggleD(this,\''+mk+'\','+rj+')">'
          +'<td style="text-align:center;">'+gh+'</td>'
          +'<td><strong>'+esc(r.maker_name||'—')+'</strong>'+specBadge+'<span style="display:block;font-size:10px;color:#aaa;">'+esc(r.maker_id_no||'')+'</span></td>'
          +'<td style="font-size:11px;color:#666;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+ps+'">'+ps+'</td>'
          +'<td style="text-align:center;">'+r.total+'</td>'
          +'<td style="text-align:center;">'+r.returned+nd+'</td>'
          +'<td>'+opbar+'</td>'
          +'<td style="color:'+ngc+';font-weight:600;">'+ng+'%</td>'
          +'<td style="text-align:center;color:#888;">'+(r.avg_days!==null?r.avg_days+'天':'—')+'</td>'
          +'<td style="font-size:11px;">'+qch+'</td>'
          +'<td style="text-align:center;"><button class="btn btn-xs btn-default" onclick="event.stopPropagation();openTrend('+rj+')" title="趨勢"><i class="fa fa-line-chart"></i></button></td>'
          +'</tr>'
          +'<tr class="drow" id="dr-'+mk+'"><td colspan="10"><div class="d-inner" id="di-'+mk+'"></div></td></tr>';
    });
    $('#ktbody').html(h);

    // 分頁控制
    var s=start+1, e=Math.min(start+G.pageSize,total);
    $('#pager-info').text('第 '+s+'～'+e+' 筆，共 '+total+' 筆');
    var pb='';
    if(G.page>1) pb+='<button onclick="goPage(1)">«</button><button onclick="goPage('+(G.page-1)+')">‹</button>';
    var from=Math.max(1,G.page-2), to=Math.min(totalPages,G.page+2);
    for(var p=from;p<=to;p++) pb+='<button class="'+(p===G.page?'active':'')+'" onclick="goPage('+p+')">'+p+'</button>';
    if(G.page<totalPages) pb+='<button onclick="goPage('+(G.page+1)+')">›</button><button onclick="goPage('+totalPages+')">»</button>';
    $('#pager-btns').html(pb);
}
function goPage(p){G.page=p;renderTable();}

// ── 展開明細 ─────────────────────────────────────────
var expandedSet={};
var detailPages={}; // idx -> current page
var detailData={};  // idx -> all rows
var DETAIL_PS=10;

function toggleD(tr,mk,r){
    var di=$('#di-'+mk);
    if(di.hasClass('open')){di.removeClass('open');delete expandedSet[mk];return;}
    di.addClass('open');expandedSet[mk]=true;
    if(detailData[mk]){renderDetail(mk);return;}
    di.html('<div class="loading"><i class="fa fa-spinner fa-spin"></i> 載入明細...</div>');
    ajx({action:'get_detail',maker_id_no:r.maker_id_no||'',maker_name:r.maker_name||'',
         date_start:G.summary.period_start,date_end:G.summary.period_end,
         cutoff:G.summary.cutoff,tolerance:r.tolerance||G.settings.tolerance},function(res){
        if(!res.success){di.html('<div style="color:red;padding:8px;">'+esc(res.message)+'</div>');return;}
        detailData[mk]=res.data||[];
        detailPages[mk]=1;
        renderDetail(mk);
    });
}
function renderDetail(mk){
    var di=$('#di-'+mk);
    var rows=detailData[mk]||[];
    if(!rows.length){di.html('<div style="color:#aaa;padding:8px;">本期無記錄</div>');return;}
    var pg=detailPages[mk]||1;
    var total=rows.length;
    var totalPages=Math.ceil(total/DETAIL_PS);
    pg=Math.max(1,Math.min(pg,totalPages));
    detailPages[mk]=pg;
    var start=(pg-1)*DETAIL_PS;
    var pageRows=rows.slice(start,start+DETAIL_PS);

    var h='<table class="d-tbl"><thead><tr>'
      +'<th>BOM</th><th>製程</th><th>發包數</th><th>發包日</th><th>回廠日</th>'
      +'<th>截止日（+'+G.settings.tolerance+'上班日）</th><th>狀態</th><th>實際工作天</th><th>QC</th><th>備註</th>'
      +'</tr></thead><tbody>';
    pageRows.forEach(function(d){
        var sc=d.status==='ontime'?'st-ok':d.status==='late'?'st-lt':'st-nr';
        var st=d.status==='ontime'?'✔ 準時':d.status==='late'?'✖ 逾期':'? 未回廠';
        var qcc=d.QC_check==='ng'?'color:#E74C3C':d.QC_check==='ok'?'color:#27AE60':d.QC_check==='AOD'?'color:#9B59B6':'';
        // 回廠日來源標記
        var rdFromLog=(d.rd_from_log==1);
        var rdCell=d.rd
            ? esc(d.rd)+(rdFromLog?' <span style="font-size:10px;background:#fff8e1;color:#8a6000;border-radius:3px;padding:1px 4px;" title="回廠日來自外包移轉記錄(bom_ing_transfer_log.transfer_date)">移轉</span>':'')
            : '<span style="color:#aaa;">未回廠</span>';
        // 工作天數顯示
        var wdCell=d.workdays!==null
            ? d.workdays+'天 <span style="color:#aaa;font-size:10px;">('+d.natural_days+'自然日)</span>'
            : '—';
        h+='<tr>'
          +'<td style="font-size:10px;color:#888;">'+esc(d.bom)+'</td>'
          +'<td>'+esc(d.ProcessName||'—')+'</td>'
          +'<td style="text-align:center;">'+esc(d.sqty||'')+'</td>'
          +'<td>'+esc(d.od||'—')+'</td>'
          +'<td>'+rdCell+'</td>'
          +'<td style="color:var(--info);">'+esc(d.ontime_deadline||'—')+'</td>'
          +'<td class="'+sc+'">'+st+'</td>'
          +'<td style="font-size:11px;text-align:center;">'+wdCell+'</td>'
          +'<td style="'+qcc+'">'+esc(d.QC_check||'—')+'</td>'
          +'<td style="font-size:10px;color:#aaa;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(d.remark||'')+'">'+esc(d.remark||'')+'</td>'
          +'</tr>';
    });
    h+='</tbody></table>';

    // 細項分頁
    if(totalPages>1){
        h+='<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 10px;background:#e8f7f3;font-size:11px;">'
          +'<span style="color:#555;">第 '+(start+1)+'～'+Math.min(start+DETAIL_PS,total)+' 筆，共 '+total+' 筆</span>'
          +'<div style="display:flex;gap:3px;">';
        if(pg>1) h+='<button class="btn btn-xs btn-default" onclick="detailGoPage(\''+mk+'\','+(pg-1)+')">‹</button>';
        for(var p=Math.max(1,pg-2);p<=Math.min(totalPages,pg+2);p++)
            h+='<button class="btn btn-xs '+(p===pg?'btn-primary':'btn-default"')+'" onclick="detailGoPage(\''+mk+'\','+p+')">'+p+'</button>';
        if(pg<totalPages) h+='<button class="btn btn-xs btn-default" onclick="detailGoPage(\''+mk+'\','+(pg+1)+')">›</button>';
        h+='</div></div>';
    }
    di.html(h);
}
function detailGoPage(mk,p){detailPages[mk]=p;renderDetail(mk);}

// ── 趨勢 ─────────────────────────────────────────────
function openTrend(r){
    G.tTab='ontime';
    // 計算趨勢基準月：期間最後一個月
    var baseYm=G.summary.period_end?G.summary.period_end.substring(0,7):G.period;
    // 半年/全年模式下取結束月份
    if(G.mode==='half'){
        baseYm=G.period.includes('H1')?(G.period.split('-')[0]+'-06'):(G.period.split('-')[0]+'-12');
    }else if(G.mode==='year'){
        baseYm=G.period+'-12';
    }
    G.tBaseYm=baseYm;
    var titlePeriod='近12個月至 '+baseYm;
    $('#ttitle').text('外包廠商績效 — '+esc(r.maker_name||'')+' '+titlePeriod);
    $('#tdetail').html('<div class="loading"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#trendModal').off('shown.bs.modal').on('shown.bs.modal',function(){ if(G.tData.length) drawTC(G.tTab); });
    $('#trendModal').modal('show');
    ajx({action:'get_trend',maker_id_no:r.maker_id_no||'',maker_name:r.maker_name||'',months:12,base_ym:baseYm},function(res){
        if(!res.success){$('#tdetail').html('<div style="color:red">'+esc(res.message)+'</div>');return;}
        G.tData=res.data||[];drawTC(G.tTab);renderTD();
    });
    G.tCurrentMaker=r;
}
// ── 趨勢 Modal ──────────────────────────────────────
function tmTab(id,el){
    $('.tm-tab').removeClass('on');$(el).addClass('on');
    $('.tmt').removeClass('on');$('#tmt-'+id).addClass('on');
    if(id==='yearly'&&!G.yDataLoaded){loadYearlyTrend();}
    if(id==='compare'&&!G.cmpLoaded){loadProcCompare();}
    if(id==='monthly') drawTC(G.tTab);
}

function openTrend(r){
    G.tTab='ontime';
    G.tCurrentMaker=r;
    G.yDataLoaded=false;
    G.cmpLoaded=false;
    // 計算基準月
    var baseYm=G.summary.period_end?G.summary.period_end.substring(0,7):G.period;
    if(G.mode==='half') baseYm=G.period.includes('H1')?(G.period.split('-')[0]+'-06'):(G.period.split('-')[0]+'-12');
    else if(G.mode==='year') baseYm=G.period+'-12';
    G.tBaseYm=baseYm;
    var titlePeriod='近12個月至 '+baseYm;
    $('#ttitle').text('外包廠商績效 — '+esc(r.maker_name||'')+' '+titlePeriod);
    $('#tdetail').html('<div class="loading"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#ydetail').html('');
    $('#cmp-content').html('<div class="loading"><i class="fa fa-spinner fa-spin"></i></div>');
    // 重置 tabs 到第一個
    $('.tm-tab').removeClass('on');$('.tm-tab').first().addClass('on');
    $('.tmt').removeClass('on');$('#tmt-monthly').addClass('on');
    $('.ttab-btn').removeClass('on');$('.ttab-btn').first().addClass('on');
    G.tTab='ontime';
    $('#trendModal').off('shown.bs.modal').on('shown.bs.modal',function(){ if(G.tData.length) drawTC(G.tTab); });
    $('#trendModal').modal('show');
    // 載入近12個月
    ajx({action:'get_trend',maker_id_no:r.maker_id_no||'',maker_name:r.maker_name||'',months:12,base_ym:baseYm},function(res){
        if(!res.success){$('#tdetail').html('<div style="color:red">'+esc(res.message)+'</div>');return;}
        G.tData=res.data||[];drawTC(G.tTab);renderTD();
    });
}

function loadYearlyTrend(){
    if(!G.tCurrentMaker) return;
    var r=G.tCurrentMaker;
    $('#ydetail').html('<div class="loading"><i class="fa fa-spinner fa-spin"></i></div>');
    ajx({action:'get_yearly_trend',maker_id_no:r.maker_id_no||'',maker_name:r.maker_name||''},function(res){
        G.yDataLoaded=true;
        if(!res.success){$('#ydetail').html('<div style="color:red">'+esc(res.message)+'</div>');return;}
        G.yData=res.data||[];
        drawYC(G.yTab||'ontime');
        renderYD();
    });
}

function loadProcCompare(){
    if(!G.tCurrentMaker) return;
    var r=G.tCurrentMaker;
    var procNames=r.proc_names?(r.proc_names.split('、')):(r.proc_category?[r.proc_category]:[]);
    if(!procNames.length){$('#cmp-content').html('<div style="color:#aaa;padding:12px;">此廠商無主要製程資訊</div>');G.cmpLoaded=true;return;}
    $('#cmp-content').html('<div class="loading"><i class="fa fa-spinner fa-spin"></i></div>');
    ajx({action:'get_proc_compare',proc_names:JSON.stringify(procNames),
         date_start:G.summary.period_start,date_end:G.summary.period_end,cutoff:G.summary.cutoff},function(res){
        G.cmpLoaded=true;
        if(!res.success){$('#cmp-content').html('<div style="color:red">'+esc(res.message)+'</div>');return;}
        G.cmpData=res.data||[];
        G.cmpProcNames=res.proc_names||[];
        renderCmp();
    });
}

// ── 歷年圖表 ──────────────────────────────────────
var G_ychart=null;
function switchYT(tab,el){G.yTab=tab;$('#tmt-yearly .ttab-btn').removeClass('on');$(el).addClass('on');drawYC(tab);}
function drawYC(tab){
    var canvas=document.getElementById('ychart');
    if(!canvas||!G.yData||!G.yData.length) return;
    var ctx=canvas.getContext('2d');
    var dpr=window.devicePixelRatio||1;
    var cssW=canvas.offsetWidth||700; var cssH=240;
    canvas.width=cssW*dpr; canvas.height=cssH*dpr;
    canvas.style.width=cssW+'px'; canvas.style.height=cssH+'px';
    ctx.scale(dpr,dpr); var W=cssW, H=cssH;
    ctx.clearRect(0,0,W,H);
    var data=G.yData.filter(function(d){return d.total>0;});
    if(!data.length) return;
    var labels=data.map(function(d){return d.yr+'年';});
    var vals,color,yMax,targetVal,color2;
    if(tab==='ontime'){vals=data.map(function(d){return d.ontime_pct;});color='#1ABB9C';yMax=100;targetVal=90;color2='#E74C3C';}
    else if(tab==='ng'){vals=data.map(function(d){return d.ng_pct;});color='#E74C3C';yMax=100;targetVal=3;color2='#F39C12';}
    else if(tab==='days'){vals=data.map(function(d){return d.avg_days;});color='#3498DB';yMax=null;targetVal=null;}
    else{vals=data.map(function(d){return d.total;});color='#2A3F54';yMax=null;targetVal=null;}
    _drawBarChart(ctx,W,H,labels,vals,color,yMax,targetVal,color2);
}
function _drawBarChart(ctx,W,H,labels,vals,color,yMax,targetVal,color2){
    var pad={top:28,right:20,bottom:30,left:46};
    var gW=W-pad.left-pad.right, gH=H-pad.top-pad.bottom;
    var allVals=vals.filter(function(v){return v!==null&&v!==undefined;});
    if(targetVal!==null) allVals.push(targetVal);
    var vMax=yMax||Math.max.apply(null,allVals)*1.2||10;
    // grid
    ctx.strokeStyle='#eee'; ctx.lineWidth=1;
    for(var y=0;y<=4;y++){
        var yv=y/4*vMax; var yp=pad.top+gH-gH*y/4;
        ctx.beginPath(); ctx.moveTo(pad.left,yp); ctx.lineTo(pad.left+gW,yp); ctx.stroke();
        ctx.fillStyle='#aaa'; ctx.font='10px sans-serif'; ctx.textAlign='right';
        ctx.fillText(Math.round(yv*10)/10,pad.left-4,yp+4);
    }
    // target line
    if(targetVal!==null){
        var tp=pad.top+gH-gH*(targetVal/vMax);
        ctx.strokeStyle=color2; ctx.lineWidth=1.5; ctx.setLineDash([5,5]);
        ctx.beginPath(); ctx.moveTo(pad.left,tp); ctx.lineTo(pad.left+gW,tp); ctx.stroke();
        ctx.setLineDash([]);
    }
    // bars
    var n=labels.length; var bw=Math.max(4,Math.min(40,(gW/n)*0.6)); var gap=(gW/n);
    labels.forEach(function(lb,i){
        var v=vals[i]; var x=pad.left+i*gap+gap/2;
        ctx.fillStyle='#aaa'; ctx.font='10px sans-serif'; ctx.textAlign='center';
        ctx.fillText(lb,x,H-pad.bottom+14);
        if(v===null||v===undefined) return;
        var bh=gH*(v/vMax); var by=pad.top+gH-bh;
        ctx.fillStyle=color+'cc'; ctx.fillRect(x-bw/2,by,bw,bh);
        ctx.fillStyle='#555'; ctx.font='10px sans-serif'; ctx.textAlign='center';
        ctx.fillText(v+(targetVal!==null?'%':''),x,Math.max(by-3,pad.top+10));
    });
}
function renderYD(){
    var h='<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#f8f9fa;font-size:11px;">'
      +'<th style="padding:5px 8px;">年度</th><th style="text-align:center;padding:5px 8px;">發包</th>'
      +'<th style="text-align:center;padding:5px 8px;">已回</th><th style="text-align:center;padding:5px 8px;">準時率</th>'
      +'<th style="text-align:center;padding:5px 8px;">NG率</th><th style="text-align:center;padding:5px 8px;">平均日曆天</th>'
      +'</tr></thead><tbody>';
    G.yData.forEach(function(d){
        if(!d.total) return;
        var opC=d.ontime_pct===null?'#ccc':d.ontime_pct>=90?'#27AE60':d.ontime_pct>=75?'#F39C12':'#E74C3C';
        var ngC=d.ng_pct<=3?'#27AE60':d.ng_pct<=6?'#F39C12':'#E74C3C';
        h+='<tr><td style="padding:4px 8px;font-size:11px;">'+d.yr+'年</td>'
          +'<td style="text-align:center;font-size:11px;padding:4px 8px;">'+d.total+'</td>'
          +'<td style="text-align:center;font-size:11px;padding:4px 8px;">'+d.returned+'</td>'
          +'<td style="text-align:center;font-size:11px;padding:4px 8px;color:'+opC+';font-weight:600;">'+(d.ontime_pct!==null?d.ontime_pct+'%':'—')+'</td>'
          +'<td style="text-align:center;font-size:11px;padding:4px 8px;color:'+ngC+';font-weight:600;">'+d.ng_pct+'%</td>'
          +'<td style="text-align:center;font-size:11px;padding:4px 8px;">'+(d.avg_days!==null?d.avg_days:'—')+'</td></tr>';
    });
    h+='</tbody></table>';
    $('#ydetail').html(h);
}

// ── 製程比較 ──────────────────────────────────────
function renderCmp(){
    var rows=G.cmpData||[];
    var procStr=(G.cmpProcNames||[]).join('、');
    var h='<div style="font-size:12px;color:#555;margin-bottom:10px;">製程：<strong>'+esc(procStr)+'</strong> 的所有廠商，期間 '+esc(G.summary.period_start)+' ~ '+esc(G.summary.period_end)+'</div>';
    if(!rows.length){$('#cmp-content').html(h+'<div style="color:#aaa;">本期間無其他廠商相同製程資料</div>');return;}
    // 橫條圖 canvas
    h+='<canvas id="cmp-chart" style="width:100%;display:block;" height="'+(rows.length*38+50)+'"></canvas>';
    h+='<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:11px;"><thead><tr style="background:#f8f9fa;">'
      +'<th style="padding:5px 8px;">廠商</th><th style="text-align:center;padding:5px 8px;">發包</th>'
      +'<th style="text-align:center;padding:5px 8px;">準時率</th><th style="text-align:center;padding:5px 8px;">NG率</th>'
      +'<th style="text-align:center;padding:5px 8px;">平均日曆天</th></tr></thead><tbody>';
    var cur=G.tCurrentMaker;
    rows.forEach(function(d){
        var isCur=(d.maker_id_no&&cur.maker_id_no&&d.maker_id_no===cur.maker_id_no)||(d.maker_name===cur.maker_name);
        var opC=d.ontime_pct===null?'#ccc':d.ontime_pct>=90?'#27AE60':d.ontime_pct>=75?'#F39C12':'#E74C3C';
        var ngC=d.ng_pct<=3?'#27AE60':d.ng_pct<=6?'#F39C12':'#E74C3C';
        h+='<tr style="'+(isCur?'background:#e6f1fb;':'')+'font-weight:'+(isCur?700:400)+'">'
          +'<td style="padding:4px 8px;">'+esc(d.maker_name)+(isCur?' ★':'')+'</td>'
          +'<td style="text-align:center;padding:4px 8px;">'+d.total+'</td>'
          +'<td style="text-align:center;padding:4px 8px;color:'+opC+';font-weight:600;">'+(d.ontime_pct!==null?d.ontime_pct+'%':'—')+'</td>'
          +'<td style="text-align:center;padding:4px 8px;color:'+ngC+';font-weight:600;">'+d.ng_pct+'%</td>'
          +'<td style="text-align:center;padding:4px 8px;">'+(d.avg_days!==null?d.avg_days:'—')+'</td></tr>';
    });
    h+='</tbody></table>';
    $('#cmp-content').html(h);
    // 畫橫條圖：準時率
    setTimeout(function(){
        var canvas=document.getElementById('cmp-chart');
        if(!canvas) return;
        var ctx=canvas.getContext('2d');
        var dpr=window.devicePixelRatio||1;
        var cssW=canvas.offsetWidth||700;
        var cssH=rows.length*38+50;
        canvas.width=cssW*dpr; canvas.height=cssH*dpr;
        canvas.style.width=cssW+'px'; canvas.style.height=cssH+'px';
        ctx.scale(dpr,dpr); var W=cssW, H=cssH;
        ctx.clearRect(0,0,W,H);
        var pad={top:24,right:60,bottom:10,left:90};
        var gW=W-pad.left-pad.right, bh=28, gap=38;
        // title
        ctx.fillStyle='#555'; ctx.font='11px sans-serif'; ctx.textAlign='center';
        ctx.fillText('準時交貨率比較（%）',W/2,16);
        // target
        var tp=pad.left+gW*0.9;
        ctx.strokeStyle='#E74C3C'; ctx.lineWidth=1; ctx.setLineDash([4,4]);
        ctx.beginPath(); ctx.moveTo(tp,pad.top); ctx.lineTo(tp,H-pad.bottom); ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle='#E74C3C'; ctx.font='9px sans-serif'; ctx.textAlign='center';
        ctx.fillText('90%',tp,pad.top-4);
        rows.forEach(function(d,i){
            var y=pad.top+i*gap;
            var isCur=(d.maker_id_no&&cur.maker_id_no&&d.maker_id_no===cur.maker_id_no)||(d.maker_name===cur.maker_name);
            var op=d.ontime_pct!==null?d.ontime_pct:0;
            var bw=gW*(op/100);
            var barColor=op>=90?'#1ABB9C':op>=75?'#F39C12':'#E74C3C';
            ctx.fillStyle=barColor+(isCur?'':'99');
            ctx.fillRect(pad.left,y,bw,bh);
            // name
            ctx.fillStyle=isCur?'#0C447C':'#555'; ctx.font=(isCur?'bold ':'')+' 11px sans-serif'; ctx.textAlign='right';
            ctx.fillText(d.maker_name,pad.left-4,y+bh/2+4);
            // value
            ctx.fillStyle='#333'; ctx.textAlign='left';
            ctx.fillText(d.ontime_pct!==null?d.ontime_pct+'%':'—',pad.left+bw+4,y+bh/2+4);
        });
    },100);
}

function switchTT(tab,el){G.tTab=tab;$('#tmt-monthly .ttab-btn').removeClass('on');$(el).addClass('on');drawTC(tab);}

function drawTC(tab){
    var canvas=document.getElementById('tchart');
    if(!canvas) return;
    var ctx=canvas.getContext('2d');
    var dpr=window.devicePixelRatio||1;
    var cssW=canvas.offsetWidth||600;
    var cssH=260;
    // 高解析度修正：實際像素 = CSS尺寸 × dpr
    canvas.width=cssW*dpr;
    canvas.height=cssH*dpr;
    canvas.style.width=cssW+'px';
    canvas.style.height=cssH+'px';
    ctx.scale(dpr,dpr);
    var W=cssW, H=cssH;
    ctx.clearRect(0,0,W,H);

    var data=G.tData;
    if(!data.length) return;
    var labels=data.map(function(d){return d.ym.substring(5)+'月';});
    var series1,series2,color1,color2,yMax,yLabel,targetVal;
    if(tab==='ontime'){
        series1=data.map(function(d){return d.ontime_pct;});
        color1='#1ABB9C'; yMax=100; targetVal=90; color2='#E74C3C';
        yLabel='準時率(%)';
    }else if(tab==='ng'){
        series1=data.map(function(d){return d.ng_pct;});
        color1='#E74C3C'; yMax=100; targetVal=3; color2='#F39C12';
        yLabel='NG率(%)';
    }else if(tab==='days'){
        series1=data.map(function(d){return d.avg_days;});
        color1='#3498DB'; yMax=null; targetVal=null;
        yLabel='平均天數';
    }else{
        series1=data.map(function(d){return d.total;});
        series2=data.map(function(d){return d.returned;});
        color1='#2A3F54'; color2='#1ABB9C'; yMax=null; targetVal=null;
        yLabel='筆數';
    }

    var pad={top:24,right:24,bottom:36,left:46};
    var gW=W-pad.left-pad.right, gH=H-pad.top-pad.bottom;

    // y range
    var allVals=[].concat(series1||[],series2||[],targetVal!==null?[targetVal]:[]).filter(function(v){return v!==null&&v!==undefined;});
    var vMin=0, vMax=yMax||Math.max.apply(null,allVals)*1.2||10;

    function xPos(i){return pad.left+i*(gW/(data.length-1||1));}
    function yPos(v){return pad.top+gH-(v-vMin)/(vMax-vMin)*gH;}

    // grid lines
    ctx.strokeStyle='#eee'; ctx.lineWidth=1;
    for(var y=0;y<=4;y++){
        var yv=vMin+(vMax-vMin)*y/4;
        var yp=yPos(yv);
        ctx.beginPath(); ctx.moveTo(pad.left,yp); ctx.lineTo(pad.left+gW,yp); ctx.stroke();
        ctx.fillStyle='#aaa'; ctx.font='10px sans-serif'; ctx.textAlign='right';
        ctx.fillText(Math.round(yv*10)/10,pad.left-4,yp+4);
    }

    // x labels
    ctx.fillStyle='#aaa'; ctx.font='10px sans-serif'; ctx.textAlign='center';
    labels.forEach(function(lb,i){
        if(i%Math.ceil(data.length/8)===0||i===data.length-1)
            ctx.fillText(lb,xPos(i),H-pad.bottom+14);
    });

    // target line
    if(targetVal!==null){
        var tp=yPos(targetVal);
        ctx.strokeStyle=color2; ctx.lineWidth=1.5; ctx.setLineDash([5,5]);
        ctx.beginPath(); ctx.moveTo(pad.left,tp); ctx.lineTo(pad.left+gW,tp); ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle=color2; ctx.font='10px sans-serif'; ctx.textAlign='left';
        ctx.fillText('目標 '+targetVal,pad.left+gW+2,tp+4);
    }

    function drawLine(vals,color,dash){
        var pts=[];
        vals.forEach(function(v,i){if(v!==null&&v!==undefined)pts.push([xPos(i),yPos(v)]);});
        if(pts.length<2) return;
        ctx.strokeStyle=color; ctx.lineWidth=2; ctx.setLineDash(dash||[]);
        ctx.beginPath(); ctx.moveTo(pts[0][0],pts[0][1]);
        for(var i=1;i<pts.length;i++) ctx.lineTo(pts[i][0],pts[i][1]);
        ctx.stroke(); ctx.setLineDash([]);
        // dots
        pts.forEach(function(pt){
            ctx.beginPath(); ctx.arc(pt[0],pt[1],3.5,0,Math.PI*2);
            ctx.fillStyle=color; ctx.fill();
        });
    }

    drawLine(series1,color1);
    if(series2) drawLine(series2,color2,[4,4]);

    // legend
    ctx.font='11px sans-serif'; ctx.textAlign='left';
    var labels1=tab==='ontime'?'準時率':tab==='ng'?'NG率':tab==='days'?'平均天數':'發包筆數';
    ctx.fillStyle=color1; ctx.fillRect(pad.left,4,12,8); ctx.fillStyle='#555'; ctx.fillText(labels1,pad.left+16,12);
    if(tab==='count'&&series2){ctx.fillStyle=color2; ctx.fillRect(pad.left+80,4,12,8); ctx.fillStyle='#555'; ctx.fillText('已回廠',pad.left+96,12);}
    if(targetVal!==null){
        var lx=pad.left+(tab==='ontime'?80:80);
        ctx.strokeStyle=color2; ctx.lineWidth=1.5; ctx.setLineDash([5,5]);
        ctx.beginPath(); ctx.moveTo(lx,8); ctx.lineTo(lx+12,8); ctx.stroke(); ctx.setLineDash([]);
        ctx.fillStyle='#555'; ctx.fillText('目標',lx+16,12);
    }
}
function renderTD(){
    var h='<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#f8f9fa;font-size:11px;">'
      +'<th style="padding:5px 8px;">月份</th><th style="padding:5px 8px;text-align:center;">發包</th>'
      +'<th style="padding:5px 8px;text-align:center;">已回</th><th style="padding:5px 8px;text-align:center;">準時率</th>'
      +'<th style="padding:5px 8px;text-align:center;">NG率</th><th style="padding:5px 8px;text-align:center;">平均日曆天</th>'
      +'</tr></thead><tbody>';
    G.tData.slice().reverse().forEach(function(d){
        var opc=d.ontime_pct===null?'#ccc':d.ontime_pct>=90?'#27AE60':d.ontime_pct>=75?'#F39C12':'#E74C3C';
        var ngc=d.ng_pct<=3?'#27AE60':d.ng_pct<=6?'#F39C12':'#E74C3C';
        var cur=d.ym===G.period;
        h+='<tr style="'+(cur?'background:#f0fdf8;':'')+'">'
          +'<td style="padding:4px 8px;font-size:11px;font-weight:'+(cur?700:400)+'">'+d.ym+(cur?' ★':'')+'</td>'
          +'<td style="padding:4px 8px;text-align:center;font-size:11px;">'+d.total+'</td>'
          +'<td style="padding:4px 8px;text-align:center;font-size:11px;">'+d.returned+'</td>'
          +'<td style="padding:4px 8px;text-align:center;font-size:11px;color:'+opc+';font-weight:600;">'+(d.ontime_pct!==null?d.ontime_pct+'%':'—')+'</td>'
          +'<td style="padding:4px 8px;text-align:center;font-size:11px;color:'+ngc+';font-weight:600;">'+d.ng_pct+'%</td>'
          +'<td style="padding:4px 8px;text-align:center;font-size:11px;">'+(d.avg_days!==null?d.avg_days:'—')+'</td>'
          +'</tr>';
    });
    h+='</tbody></table>';
    $('#tdetail').html(h);
}

// ── 例外製程 ───────────────────────────────────────
function renderExclPTags(){
    var h=G_exclP.length?'':'<span style="font-size:11px;color:#bbb;">尚未設定</span>';
    G_exclP.forEach(function(e){
        h+='<span style="display:inline-flex;align-items:center;gap:4px;background:#fde8e8;color:#a52020;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:500;">'
          +'<i class="fa fa-ban" style="font-size:9px;"></i>'+esc(e.process_name)
          +(e.reason?'<span style="font-size:10px;color:#c0392b;">('+esc(e.reason)+')</span>':'')
          +'<button onclick="delExclProc('+e.id+')" style="background:none;border:none;color:#a52020;cursor:pointer;padding:0;font-size:11px;line-height:1;">&times;</button></span>';
    });
    $('#exclp-tags').html(h);
}
function renderSpecPTags(){
    var h=G_specP.length?'':'<span style="font-size:11px;color:#bbb;">尚未設定</span>';
    G_specP.forEach(function(e){
        h+='<span style="display:inline-flex;align-items:center;gap:4px;background:#e6f1fb;color:#0C447C;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:500;">'
          +'<i class="fa fa-clock-o" style="font-size:9px;"></i>'+esc(e.process_name)+' <strong>'+e.tolerance_days+'</strong>天'
          +(e.reason?'<span style="font-size:10px;color:#185FA5;">('+esc(e.reason)+')</span>':'')
          +'<button onclick="delSpecProc('+e.id+')" style="background:none;border:none;color:#0C447C;cursor:pointer;padding:0;font-size:11px;line-height:1;">&times;</button></span>';
    });
    $('#specp-tags').html(h);
}

var G_acPTimer=null;
function _searchProc(term,acId,onSelect){
    clearTimeout(G_acPTimer);
    if(term.length<1){$('#'+acId).hide();return;}
    G_acPTimer=setTimeout(function(){
        ajx({action:'search_process',term:term},function(r){
            if(!r.success||!r.data.length){$('#'+acId).hide();return;}
            var h='';
            r.data.forEach(function(p){
                var pno=p.process_no, pnm=p.process_name;
                h+='<div class="proc-ac-item" style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #f5f5f5;" '
                  +'onmouseover="this.style.background=\'#f0fdf8\'" onmouseout="this.style.background=\'\'">'
                  +'<strong>'+esc(pnm)+'</strong>'
                  +'<span style="color:#aaa;font-size:10px;margin-left:5px;">No.'+pno+'</span></div>';
            });
            var $ac=$('#'+acId);
            $ac.html(h).show();
            $ac.find('.proc-ac-item').each(function(i){
                var pno=r.data[i].process_no, pnm=r.data[i].process_name;
                $(this).on('click',function(){onSelect(pno,pnm);$ac.hide();});
            });
        });
    },250);
}
// 例外製程 AC
function onExclPInput(){_searchProc($('#exclp-input').val().trim(),'exclp-ac',function(no,nm){G_exclPSelected={process_no:no,process_name:nm};$('#exclp-input').val(nm);});}
function onExclPKey(e){if(e.key==='Escape')$('#exclp-ac').hide();}
function addExclProc(){
    var nm=G_exclPSelected.process_name||$('#exclp-input').val().trim();
    if(!nm){toast('請選擇製程','error');return;}
    var reason=$('#exclp-reason').val().trim();
    ajx({action:'add_excluded_proc',process_no:G_exclPSelected.process_no,process_name:nm,reason:reason},function(r){
        if(!r.success){toast(r.message,'error');return;}
        G_exclP.push({id:r.id,process_no:G_exclPSelected.process_no,process_name:nm,reason:reason});
        renderExclPTags();
        $('#exclp-input').val('');$('#exclp-reason').val('');
        G_exclPSelected={process_no:0,process_name:''};
        toast('已新增例外製程：'+nm,'success'); loadData();
    });
}
function delExclProc(id){
    ajx({action:'delete_excluded_proc',id:id},function(r){
        if(!r.success){toast(r.message,'error');return;}
        G_exclP=G_exclP.filter(function(e){return e.id!==id;});
        renderExclPTags(); toast('已移除','success'); loadData();
    });
}
// 特殊製程容忍天數 AC
function onSpecPInput(){_searchProc($('#specp-input').val().trim(),'specp-ac',function(no,nm){
    G_specPSelected={process_no:no,process_name:nm};
    $('#specp-input').val(nm);
    var ex=G_specP.find(function(s){return s.process_name===nm;});
    if(ex)$('#specp-days').val(ex.tolerance_days);
});}
function onSpecPKey(e){if(e.key==='Escape')$('#specp-ac').hide();}
function addSpecProc(){
    var nm=G_specPSelected.process_name||$('#specp-input').val().trim();
    if(!nm){toast('請選擇製程','error');return;}
    var days=parseInt($('#specp-days').val()||7);
    if(days<1){toast('天數至少1天','error');return;}
    var reason=$('#specp-reason').val().trim();
    ajx({action:'save_special_proc',process_no:G_specPSelected.process_no,process_name:nm,tolerance_days:days,reason:reason},function(r){
        if(!r.success){toast(r.message,'error');return;}
        var idx=G_specP.findIndex(function(s){return s.process_name===nm;});
        var obj={id:r.id,process_no:G_specPSelected.process_no,process_name:nm,tolerance_days:days,reason:reason};
        if(idx>=0) G_specP[idx]=obj; else G_specP.push(obj);
        renderSpecPTags();
        $('#specp-input').val('');$('#specp-reason').val('');$('#specp-days').val(7);
        G_specPSelected={process_no:0,process_name:''};
        toast('已設定 '+nm+' 容忍天數：'+days+' 天','success'); loadData();
    });
}
function delSpecProc(id){
    ajx({action:'delete_special_proc',id:id},function(r){
        if(!r.success){toast(r.message,'error');return;}
        G_specP=G_specP.filter(function(s){return s.id!==id;});
        renderSpecPTags(); toast('已移除','success'); loadData();
    });
}

// ── 匯出 CSV（含統計區間）─────────────────────────
function exportCsv(){
    if(!G.filteredData.length){toast('無資料','error');return;}
    var s=G.summary;
    var periodStr=s.period_start&&s.period_end?s.period_start+' ~ '+s.period_end:G.period;
    // 說明列
    var r=[
        ['外包廠商績效 KPI 報表'],
        ['統計區間：'+periodStr+'　容忍天數：'+G.settings.tolerance+'個上班日　平均天數：日曆天'],
        ['產生時間：'+new Date().toLocaleString('zh-TW')],
        [],
        ['評級','廠商','廠商編號','主要製程','容忍天(上班日)','發包筆','已回廠','準時筆','準時率(%)','NG率(%)','平均日曆天','OK','NG','QQ','AOD']
    ];
    G.filteredData.forEach(function(d){
        r.push([d.grade,d.maker_name,d.maker_id_no||'',d.proc_names||d.proc_category||'',
                d.tolerance||G.settings.tolerance,
                d.total,d.returned,d.ontime,
                d.ontime_pct!==null?d.ontime_pct:'',d.ng_pct,
                d.avg_days!==null?d.avg_days:'',
                d.ok_count,d.ng_count,d.qq_count,d.aod_count]);
    });
    var csv='\uFEFF'+r.map(function(x){return x.map(function(v){return'"'+(v+'').replace(/"/g,'""')+'"';}).join(',');}).join('\r\n');
    var a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));
    a.download='廠商KPI_'+G.period+'.csv'; a.click();
}

// ── PDF 報告（整體）─────────────────────────────────
function exportPdf(){
    if(!G.filteredData.length){toast('無資料','error');return;}
    var s=G.summary;
    var periodStr=s.period_start&&s.period_end?s.period_start+' ~ '+s.period_end:G.period;
    var tol=G.settings.tolerance;
    var rows=G.filteredData;

    // 評級顏色對應
    var gradeCSS={green:'#27AE60',yellow:'#F39C12',red:'#E74C3C',purple:'#9B59B6',gray:'#888'};

    // 建立 HTML → 轉成 print window
    var trRows=rows.map(function(r){
        var opColor=r.ontime_pct===null?'#999':r.ontime_pct>=90?'#27AE60':r.ontime_pct>=75?'#F39C12':'#E74C3C';
        var ngColor=r.ng_pct<=3?'#27AE60':r.ng_pct<=6?'#F39C12':'#E74C3C';
        var gradeC=gradeCSS[r.grade_color]||'#888';
        return '<tr>'
          +'<td style="text-align:center;"><span style="background:'+gradeC+'22;color:'+gradeC+';border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;">'+esc(r.grade)+'</span></td>'
          +'<td><strong>'+esc(r.maker_name||'—')+'</strong>'+(r.is_special_tol?'<span style="font-size:9px;color:#185FA5;"> ⏱'+r.tolerance+'天</span>':'')+'<br><span style="font-size:10px;color:#999;">'+esc(r.maker_id_no||'')+'</span></td>'
          +'<td style="font-size:10px;">'+esc(r.proc_names||r.proc_category||'—')+'</td>'
          +'<td style="text-align:center;">'+r.total+'</td>'
          +'<td style="text-align:center;">'+r.returned+'</td>'
          +'<td style="text-align:center;color:'+opColor+';font-weight:700;">'+(r.ontime_pct!==null?r.ontime_pct+'%':'—')+'</td>'
          +'<td style="text-align:center;color:'+ngColor+';font-weight:700;">'+r.ng_pct+'%</td>'
          +'<td style="text-align:center;">'+(r.avg_days!==null?r.avg_days:'—')+'</td>'
          +'</tr>';
    }).join('');

    var html='<!DOCTYPE html><html><head><meta charset="UTF-8">'
        +'<title>外包廠商績效KPI_'+G.period+'</title>'
        +'<style>*{margin:0;padding:0;box-sizing:border-box;}body{font-family:"Microsoft JhengHei","Segoe UI",sans-serif;font-size:12px;color:#333;padding:20px;}'
        +'h1{font-size:16px;color:#2A3F54;margin-bottom:6px;}'
        +'.meta{font-size:11px;color:#666;margin-bottom:14px;line-height:1.8;}'
        +'.sumrow{display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;}'
        +'.scard{border:1px solid #ddd;border-radius:6px;padding:8px 14px;min-width:120px;}'
        +'.scard .v{font-size:20px;font-weight:700;color:#2A3F54;}'
        +'.scard .l{font-size:10px;color:#888;margin-top:2px;}'
        +'table{width:100%;border-collapse:collapse;font-size:11px;}'
        +'th{background:#2A3F54;color:#fff;padding:6px 8px;text-align:left;font-size:10px;}'
        +'td{padding:5px 8px;border-bottom:1px solid #eee;vertical-align:middle;}'
        +'tr:nth-child(even) td{background:#f9f9f9;}'
        +'@media print{body{padding:8px;}@page{size:A4 landscape;margin:12mm;}}'
        +'</style></head><body>'
        +'<h1>外包廠商績效 KPI 報告</h1>'
        +'<div class="meta">'
        +'統計區間：<strong>'+periodStr+'</strong>　'
        +'容忍天數：<strong>'+tol+' 個上班日</strong>　'
        +'平均天數：日曆天　'
        +'產生時間：'+new Date().toLocaleString('zh-TW')
        +'</div>'
        +'<div class="sumrow">'
        +'<div class="scard"><div class="v">'+s.vendor_count+'</div><div class="l">評估廠商數</div></div>'
        +'<div class="scard"><div class="v">'+(s.total_count||0)+'</div><div class="l">發包筆數</div></div>'
        +'<div class="scard"><div class="v" style="color:'+(s.ontime_pct>=80?'#27AE60':'#E74C3C')+'">'+(s.ontime_pct!==null?s.ontime_pct+'%':'—')+'</div><div class="l">整體準時率</div></div>'
        +'<div class="scard"><div class="v" style="color:'+(s.ng_pct<=3?'#27AE60':'#E74C3C')+'">'+s.ng_pct+'%</div><div class="l">整體NG率</div></div>'
        +'<div class="scard"><div class="v">'+s.workday_count+'</div><div class="l">本期上班日數</div></div>'
        +'</div>'
        +'<table><thead><tr><th>評級</th><th>廠商</th><th>主要製程</th><th>發包筆</th><th>已回廠</th><th>準時率</th><th>NG率</th><th>平均日曆天</th></tr></thead>'
        +'<tbody>'+trRows+'</tbody></table>'
        +'<div style="margin-top:10px;font-size:10px;color:#999;">準時定義：發包日後 '+tol+' 個上班日內回廠。平均天數為日曆天（含假日）。</div>'
        +'</body></html>';

    var w=window.open('','_blank');
    w.document.write(html);
    w.document.close();
    w.onload=function(){w.print();};
}

function exportTrendPdf(){
    var r=G.tCurrentMaker;
    if(!r){toast('請先開啟廠商趨勢','error');return;}
    if(!G.tData.length){toast('無趨勢資料','error');return;}
    var baseYm=G.tBaseYm||G.period;
    var makerName=esc(r.maker_name||'');
    var tol=G.settings.tolerance;

    // 擷取4種月趨勢圖
    var mImgs={};
    ['ontime','ng','days','count'].forEach(function(tab){drawTC(tab);var c=document.getElementById('tchart');mImgs[tab]=c?c.toDataURL('image/png'):'';});
    drawTC(G.tTab);

    // 擷取歷年圖（若已載入）
    var yImg='';
    if(G.yData&&G.yData.length){
        drawYC('ontime');
        var yc=document.getElementById('ychart');
        yImg=yc?yc.toDataURL('image/png'):'';
        drawYC(G.yTab||'ontime');
    }

    // 擷取製程比較圖（若已載入）
    var cmpImg='';
    setTimeout(function(){
        var cc=document.getElementById('cmp-chart');
        if(cc) cmpImg=cc.toDataURL('image/png');
        _doBuildTrendPdf(makerName,baseYm,tol,mImgs,yImg,cmpImg);
    },200);
}
function _doBuildTrendPdf(makerName,baseYm,tol,mImgs,yImg,cmpImg){
    var s=G.summary;
    var procStr=(G.cmpProcNames||[]).join('、');

    var mTblRows=G.tData.slice().reverse().map(function(d){
        var opC=d.ontime_pct===null?'#999':d.ontime_pct>=90?'#27AE60':d.ontime_pct>=75?'#F39C12':'#E74C3C';
        var ngC=d.ng_pct<=3?'#27AE60':d.ng_pct<=6?'#F39C12':'#E74C3C';
        var cur=d.ym===G.period;
        return '<tr style="'+(cur?'background:#f0fdf8;font-weight:700;':'')+'">'
          +'<td>'+d.ym+(cur?' ★':'')+'</td><td>'+d.total+'</td><td>'+d.returned+'</td>'
          +'<td style="color:'+opC+';font-weight:600;">'+(d.ontime_pct!==null?d.ontime_pct+'%':'—')+'</td>'
          +'<td style="color:'+ngC+';font-weight:600;">'+d.ng_pct+'%</td>'
          +'<td>'+(d.avg_days!==null?d.avg_days+'天':'—')+'</td></tr>';
    }).join('');

    var yTblRows='';
    if(G.yData&&G.yData.length){
        yTblRows=(G.yData||[]).filter(function(d){return d.total>0;}).map(function(d){
            var opC=d.ontime_pct===null?'#999':d.ontime_pct>=90?'#27AE60':d.ontime_pct>=75?'#F39C12':'#E74C3C';
            return '<tr><td>'+d.yr+'年</td><td>'+d.total+'</td><td>'+d.returned+'</td>'
              +'<td style="color:'+opC+';font-weight:600;">'+(d.ontime_pct!==null?d.ontime_pct+'%':'—')+'</td>'
              +'<td style="color:'+(d.ng_pct<=3?'#27AE60':d.ng_pct<=6?'#F39C12':'#E74C3C')+';font-weight:600;">'+d.ng_pct+'%</td>'
              +'<td>'+(d.avg_days!==null?d.avg_days+'天':'—')+'</td></tr>';
        }).join('');
    }

    var cmpTblRows='';
    if(G.cmpData&&G.cmpData.length){
        cmpTblRows=(G.cmpData||[]).map(function(d){
            var isCur=(d.maker_id_no&&G.tCurrentMaker.maker_id_no&&d.maker_id_no===G.tCurrentMaker.maker_id_no)||(d.maker_name===G.tCurrentMaker.maker_name);
            var opC=d.ontime_pct===null?'#999':d.ontime_pct>=90?'#27AE60':d.ontime_pct>=75?'#F39C12':'#E74C3C';
            return '<tr style="'+(isCur?'background:#e6f1fb;font-weight:700;':'')+'">'
              +'<td>'+esc(d.maker_name)+(isCur?' ★':'')+'</td><td>'+d.total+'</td>'
              +'<td style="color:'+opC+';font-weight:600;">'+(d.ontime_pct!==null?d.ontime_pct+'%':'—')+'</td>'
              +'<td style="color:'+(d.ng_pct<=3?'#27AE60':d.ng_pct<=6?'#F39C12':'#E74C3C')+';font-weight:600;">'+d.ng_pct+'%</td>'
              +'<td>'+(d.avg_days!==null?d.avg_days+'天':'—')+'</td></tr>';
        }).join('');
    }

    var css='*{margin:0;padding:0;box-sizing:border-box;}body{font-family:"Microsoft JhengHei","Segoe UI",sans-serif;font-size:11px;color:#333;padding:16px;}'
        +'h1{font-size:14px;color:#2A3F54;margin-bottom:2px;}h2{font-size:12px;color:#2A3F54;margin-bottom:2px;}h3{font-size:11px;color:#2A3F54;margin:10px 0 4px;}'
        +'.meta{font-size:10px;color:#666;margin-bottom:10px;}.charts{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;}'
        +'.chart-box{border:1px solid #eee;border-radius:4px;padding:6px;}.chart-box .ct{font-size:10px;color:#555;font-weight:600;margin-bottom:3px;}'
        +'.chart-box img{width:100%;}.sep{border:none;border-top:1px solid #ddd;margin:10px 0;}'
        +'.section{page-break-before:always;padding-top:8px;}'
        +'.section:first-child{page-break-before:auto;}'
        +'table{width:100%;border-collapse:collapse;}th{background:#2A3F54;color:#fff;padding:4px 6px;text-align:center;font-size:10px;}td{padding:3px 6px;border-bottom:1px solid #eee;text-align:center;}'
        +'@media print{.section{page-break-before:always;}.section:first-child{page-break-before:auto;}@page{size:A4;margin:10mm;}}';

    var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>外包廠商績效_'+makerName+'_'+baseYm+'</title><style>'+css+'</style></head><body>'
        // 第一頁：近12個月
        +'<div class="section">'
        +'<h1>外包廠商績效 — 廠商趨勢報告</h1>'
        +'<h2>'+makerName+'</h2>'
        +'<div class="meta">近12個月至：<strong>'+baseYm+'</strong>　容忍：<strong>'+tol+' 個上班日</strong>　平均天數：日曆天　產生：'+new Date().toLocaleString('zh-TW')+'</div>'
        +'<h3>近12個月趨勢</h3>'
        +'<div class="charts">'
        +'<div class="chart-box"><div class="ct">準時交貨率(%)</div><img src="'+mImgs.ontime+'"></div>'
        +'<div class="chart-box"><div class="ct">NG率(%)</div><img src="'+mImgs.ng+'"></div>'
        +'<div class="chart-box"><div class="ct">平均日曆天數</div><img src="'+mImgs.days+'"></div>'
        +'<div class="chart-box"><div class="ct">發包筆數</div><img src="'+mImgs.count+'"></div>'
        +'</div>'
        +'<table><thead><tr><th>月份</th><th>發包</th><th>已回</th><th>準時率</th><th>NG率</th><th>平均日曆天</th></tr></thead><tbody>'+mTblRows+'</tbody></table>'
        +'<div style="margin-top:6px;font-size:9px;color:#999;">平均天數為日曆天（含假日）。目標線：準時率90%、NG率3%。</div>'
        +'</div>';
    // 歷年（新頁）
    if(yImg||yTblRows){
        html+='<div class="section">'
            +'<h1>外包廠商績效 — 歷年變化</h1><h2>'+makerName+'</h2>'
            +'<div class="meta">至：<strong>'+baseYm+'</strong>　產生：'+new Date().toLocaleString('zh-TW')+'</div>';
        if(yImg) html+='<div style="margin-bottom:8px;"><img src="'+yImg+'" style="width:100%;border:1px solid #eee;border-radius:4px;"></div>';
        if(yTblRows) html+='<table><thead><tr><th>年度</th><th>發包</th><th>已回</th><th>準時率</th><th>NG率</th><th>平均日曆天</th></tr></thead><tbody>'+yTblRows+'</tbody></table>';
        html+='</div>';
    }
    // 製程比較（新頁）
    if(cmpImg||cmpTblRows){
        html+='<div class="section">'
            +'<h1>外包廠商績效 — 相同製程廠商比較</h1><h2>'+makerName+' ★ 　製程：'+esc(procStr)+'</h2>'
            +'<div class="meta">統計期間：<strong>'+esc(G.summary.period_start||'')+'～'+esc(G.summary.period_end||'')+'</strong>　產生：'+new Date().toLocaleString('zh-TW')+'</div>';
        if(cmpImg) html+='<div style="margin-bottom:8px;"><img src="'+cmpImg+'" style="width:100%;border:1px solid #eee;border-radius:4px;"></div>';
        if(cmpTblRows) html+='<table><thead><tr><th>廠商</th><th>發包</th><th>準時率</th><th>NG率</th><th>平均日曆天</th></tr></thead><tbody>'+cmpTblRows+'</tbody></table>';
        html+='<div style="margin-top:6px;font-size:9px;color:#999;">★ 為當前廠商。平均天數為日曆天。</div>';
        html+='</div>';
    }
    html+='</body></html>';

    var w=window.open('','_blank');
    w.document.write(html); w.document.close();
    w.onload=function(){w.print();};
}

// ── 異常偵測 ─────────────────────────────────────────
function openAnomalyModal(){
    $('#anomalyModal').modal('show');
    runAnomalyDetect();
}
function runAnomalyDetect(){
    $('#anomaly-summary').html('<i class="fa fa-spinner fa-spin"></i> 掃描中...');
    $('#anomaly-list').html('');
    $('#anomaly-rerun-btn').prop('disabled',true);
    ajx({action:'detect_anomalies'},function(r){
        $('#anomaly-rerun-btn').prop('disabled',false);
        if(!r.success){$('#anomaly-summary').html('<span style="color:red">'+esc(r.message)+'</span>');return;}
        var data=r.data||[];
        var retItems=data.filter(function(d){return d.type==='return_before_send';});
        var mkItems=data.filter(function(d){return d.type==='maker_not_in_list';});

        // 摘要 badge（可點擊跳轉）
        var sumHtml='<div style="display:flex;gap:10px;flex-wrap:wrap;">';
        sumHtml+='<a href="#anc-ret" onclick="scrollAnomaly(\'anc-ret\')" style="text-decoration:none;">'
            +'<span style="background:'+(retItems.length?'#fde8e8':'#d4f5ed')+';color:'+(retItems.length?'#a52020':'#0e7a5e')+';padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;">'
            +'<i class="fa fa-'+(retItems.length?'exclamation-triangle':'check')+'"></i> 回廠早於發包：'+retItems.length+' 筆</span></a>';
        sumHtml+='<a href="#anc-mk" onclick="scrollAnomaly(\'anc-mk\')" style="text-decoration:none;">'
            +'<span style="background:'+(mkItems.length?'#fef3e2':'#d4f5ed')+';color:'+(mkItems.length?'#a06000':'#0e7a5e')+';padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;">'
            +'<i class="fa fa-'+(mkItems.length?'exclamation-triangle':'check')+'"></i> 廠商未建資料：'+mkItems.length+' 筆</span></a>';
        sumHtml+='</div>';
        if(!data.length) sumHtml+='<div style="color:#27AE60;margin-top:8px;font-size:13px;"><i class="fa fa-check-circle"></i> 未發現異常</div>';
        $('#anomaly-summary').html(sumHtml);

        var listHtml='';

        // ── 回廠早於發包 ──
        if(retItems.length){
            listHtml+='<div id="anc-ret" style="font-weight:600;font-size:12px;color:var(--danger);margin:10px 0 6px;"><i class="fa fa-exclamation-triangle"></i> 回廠日早於發包日（共 '+retItems.length+' 筆）</div>';
            retItems.forEach(function(d){
                var fid=d.bom_ing_fid;
                listHtml+='<div class="anomaly-card type-return" id="acard-'+fid+'">'
                  +'<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap;">'
                  +'<span class="a-badge a-badge-ret">回廠早於發包</span>'
                  +'<strong>'+esc(d.maker_name||'—')+'</strong>'
                  +(d.maker_id_no?'<span style="font-size:10px;color:#aaa;">'+esc(d.maker_id_no)+'</span>':'')
                  +'<span style="font-size:10px;color:#aaa;">BOM: '+esc(d.bom)+'  製程: '+esc(d.ProcessName||'—')+'</span>'
                  +'</div>'
                  +'<div class="a-desc">'+esc(d.desc)+'</div>'
                  +'<div class="a-fix">'
                  +'<label style="font-size:11px;color:#555;">發包日：</label>'
                  +'<input type="date" id="fix-od-'+fid+'" value="'+esc(d.outsource_d||'')+'">'
                  +'<button class="btn btn-xs btn-default" onclick="fixAnomaly('+fid+',\'outsource_date\',\'fix-od-'+fid+'\')">儲存發包日</button>'
                  +'<label style="font-size:11px;color:#555;margin-left:6px;">回廠日：</label>'
                  +'<input type="date" id="fix-rd-'+fid+'" value="'+esc(d.return_d||'')+'">'
                  +'<button class="btn btn-xs btn-default" onclick="fixAnomaly('+fid+',\'return_date\',\'fix-rd-'+fid+'\')">儲存回廠日</button>'
                  +'<button class="btn btn-xs btn-default" onclick="clearReturnDate('+fid+')" style="color:var(--warn);">清除回廠日</button>'
                  +'<span id="fix-msg-'+fid+'" style="font-size:11px;"></span>'
                  +'</div>'
                  +'</div>';
            });
        }

        // ── 廠商未建資料 ──
        if(mkItems.length){
            listHtml+='<div id="anc-mk" style="font-weight:600;font-size:12px;color:var(--warn);margin:14px 0 6px;"><i class="fa fa-exclamation-triangle"></i> 廠商編號未建資料表（共 '+mkItems.length+' 筆）</div>';
            listHtml+='<div style="font-size:11px;color:#888;margin-bottom:8px;">這些廠商編號存在於 bom_ing 但不在 maker_list，點「建立廠商」可直接新增。</div>';
            listHtml+='<table style="width:100%;border-collapse:collapse;font-size:12px;">'
              +'<thead><tr style="background:#fff8e1;">'
              +'<th style="padding:6px 8px;text-align:left;font-weight:600;color:#8a6000;">廠商編號</th>'
              +'<th style="padding:6px 8px;text-align:left;font-weight:600;color:#8a6000;">bom_ing 記錄名稱</th>'
              +'<th style="padding:6px 8px;text-align:center;font-weight:600;color:#8a6000;">發包筆</th>'
              +'<th style="padding:6px 8px;text-align:center;font-weight:600;color:#8a6000;">動作</th>'
              +'</tr></thead><tbody>';
            mkItems.forEach(function(d){
                var rowId='mkrow-'+d.maker_id_no.replace(/[^a-zA-Z0-9]/g,'_');
                listHtml+='<tr id="'+rowId+'" style="border-bottom:1px solid #f5f5f5;">'
                  +'<td style="padding:5px 8px;font-family:monospace;color:#555;">'+esc(d.maker_id_no)+'</td>'
                  +'<td style="padding:5px 8px;">'+esc(d.maker_id_raw||'—')+'</td>'
                  +'<td style="padding:5px 8px;text-align:center;">'+d.cnt+'</td>'
                  +'<td style="padding:5px 8px;text-align:center;">'
                  +'<button class="btn btn-xs" style="background:var(--accent);color:#fff;" '
                  +'onclick="openCreateMaker(\''+esc(d.maker_id_no)+'\',\''+esc(d.maker_id_raw||'')+'\',\''+rowId+'\')"><i class="fa fa-plus"></i> 建立廠商</button>'
                  +'<span id="mk-msg-'+rowId+'" style="font-size:11px;margin-left:6px;"></span>'
                  +'</td>'
                  +'</tr>';
            });
            listHtml+='</tbody></table>';
        }

        $('#anomaly-list').html(listHtml||'<div style="color:#27AE60;padding:12px;text-align:center;"><i class="fa fa-check-circle fa-2x"></i><br>未發現資料異常</div>');
    });
}

function scrollAnomaly(ancId){
    // 確保已渲染後才捲動
    setTimeout(function(){
        var el=document.getElementById(ancId);
        if(el) el.scrollIntoView({behavior:'smooth',block:'start'});
    },100);
}

function fixAnomaly(fid,field,inputId){
    var val=document.getElementById(inputId)?document.getElementById(inputId).value:'';
    if(!val){toast('請輸入日期','error');return;}
    var msgEl=$('#fix-msg-'+fid);
    msgEl.html('<i class="fa fa-spinner fa-spin"></i>');
    ajx({action:'fix_anomaly',bom_ing_fid:fid,field:field,value:val},function(r){
        if(!r.success){msgEl.html('<span style="color:red">'+esc(r.message)+'</span>');return;}
        msgEl.html('<span style="color:var(--accent)"><i class="fa fa-check"></i> 已儲存</span>');
        setTimeout(function(){msgEl.html('');},2500);
    });
}

function clearReturnDate(fid){
    if(!confirm('確定要清除此筆的回廠日期？')) return;
    var msgEl=$('#fix-msg-'+fid);
    msgEl.html('<i class="fa fa-spinner fa-spin"></i>');
    ajx({action:'fix_anomaly',bom_ing_fid:fid,field:'return_date',value:''},function(r){
        if(!r.success){msgEl.html('<span style="color:red">'+esc(r.message)+'</span>');return;}
        // 清空輸入框
        var rdInput=document.getElementById('fix-rd-'+fid);
        if(rdInput) rdInput.value='';
        msgEl.html('<span style="color:var(--accent)"><i class="fa fa-check"></i> 已清除</span>');
        setTimeout(function(){msgEl.html('');},2500);
    });
}

// ── 建立廠商 ─────────────────────────────────────────
var G_createMakerRowId='';
function openCreateMaker(mkNo,mkRaw,rowId){
    G_createMakerRowId=rowId;
    $('#cm-no').val(mkNo);
    $('#cm-id').val(mkRaw||'');
    $('#cm-all').val('');
    $('#cm-tel').val('');
    $('#cm-fax').val('');
    $('#cm-addr').val('');
    $('#cm-msg').html('');
    $('#anomalyModal').modal('hide');
    setTimeout(function(){$('#createMakerModal').modal('show');},400);
}
function submitCreateMaker(){
    var mkId=$('#cm-id').val().trim();
    if(!mkId){$('#cm-msg').html('<span style="color:var(--danger);">廠商簡稱為必填</span>');return;}
    $('#cm-msg').html('<i class="fa fa-spinner fa-spin"></i>');
    ajx({
        action:'create_maker',
        maker_id_no:$('#cm-no').val().trim(),
        maker_id:mkId,
        maker_id_all:$('#cm-all').val().trim(),
        m_tel:$('#cm-tel').val().trim(),
        m_fax:$('#cm-fax').val().trim(),
        invoice_address:$('#cm-addr').val().trim()
    },function(r){
        if(!r.success){$('#cm-msg').html('<span style="color:var(--danger);">'+esc(r.message)+'</span>');return;}
        $('#cm-msg').html('<span style="color:var(--accent)"><i class="fa fa-check"></i> 建立成功！</span>');
        // 更新異常列表中的該列
        if(G_createMakerRowId){
            var row=$('#'+G_createMakerRowId);
            row.css('opacity','0.4');
            row.find('button').prop('disabled',true).text('已建立');
            $('#mk-msg-'+G_createMakerRowId).html('<span style="color:var(--accent)"><i class="fa fa-check"></i> 已建立</span>');
        }
        setTimeout(function(){
            $('#createMakerModal').modal('hide');
            setTimeout(function(){$('#anomalyModal').modal('show');},400);
        },1200);
    });
}

// ── 工具 ─────────────────────────────────────────────
function ajx(d,cb){$.ajax({url:window.location.href,type:'POST',data:d,dataType:'json',success:cb,error:function(){if(cb)cb({success:false,message:'網路錯誤'});}});}
function esc(s){if(s===null||s===undefined)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function toast(msg,type){var el=$('<div class="toast-msg '+(type||'')+'">').text(msg);$('#tw').append(el);setTimeout(function(){el.fadeOut(300,function(){el.remove();});},3000);}
</script>
</body>
</html>