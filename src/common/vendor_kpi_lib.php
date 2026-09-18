<?php
/**
 * vendor_kpi_lib.php — 外包廠商 KPI 的唯一實作（2026-09-18 從 views/pages/vendor_kpi.php 抽出）
 *
 * 為什麼要抽出來：KPI 關鍵績效指標頁（views/news/KPI.php）的「廠商準時交貨率」原本自己另寫
 * 一套判定（期間用應交日、天數用約定工作天、排除用 kpi_as_excl_rule），
 * 跟這一頁（使用者認定的最終資料來源）算出來的數字對不起來——
 * 2026-08 一邊 81.9%、一邊 70.1%。使用者要求兩邊合併、都吃同一份最新資料，
 * 所以判定與設定一律由這支共用檔負責（鐵律4：禁止兩處各刻一份）。
 *
 * 這裡面的設定來源（兩頁共用、改一邊兩邊都生效）：
 *   kpi_vendor_setting   全域容忍天數 ontime_tolerance_days、最低交易筆數 min_txn_count
 *   kpi_special_maker    廠商特殊容忍天數
 *   kpi_special_process  製程特殊容忍天數
 *   kpi_excluded_maker   例外廠商（排除）
 *   kpi_excluded_process 例外製程（排除）
 *   kpi_grade_rule       評級規則
 * 回廠日：生管登錄的 return_date 與製程移轉憑單（單號日期）取「較早」者。
 */

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

/* ── 實際回廠日的判定（唯一實作，本頁所有查詢一律用這幾支組 SQL，不要各自再寫一份）──
 * 生管很多發包沒有去按「回廠」，只看 bom_ing.return_date 會把已經回來的一律判成未回廠；
 * 而製程移轉憑單（bom_ing_transfer_log，ERP 匯入，見 views/pm/Transfer_Log_Analysis.php）
 * 只要「貨從這個廠商移轉出去」就一定開得出來，是回廠的直接證據。
 *
 * 第三個同樣確定的證據是 **QC 檢驗日**（`bom_ing.QC_check_date`）——驗都驗過了，貨一定已經回廠。
 *
 * 規則（使用者指定 2026-09-18）：
 *   ① 三個來源＝生管登錄回廠日／製程移轉憑單單號日期／QC 檢驗日，**一律取最早的那一天**
 *      （登錄與補按都是事後做的，不會比實際回廠早；最早的那個才接近真的回來的日子）
 *   ② 只有其中一個有值就用那一個；三個都沒有才算真的未回廠
 *   ③ 早於發包日的一律不採用——憑單是上一段製程開的、QC 日是上一批驗的，都不是這一站回廠
 *
 * QC 檢驗日的可信度（拿有登錄回廠日的資料當對照）：與回廠日同一天 1,243 筆、差 1 天 822 筆、
 * 差 2~3 天 485 筆，只有 46 筆 QC 比登錄的回廠日還早（＝回廠補按得太晚），正是要取較早者的那幾筆；
 * 另外有 1,452 筆「沒登錄回廠日也查不到憑單、但驗過了」，靠這個來源才補得回來。
 *
 * 日期一定要「由單號解析」，不可以用 transfer_date 欄位：transfer_date 會為了帳款月份
 * 被人工改過（2026 年 6,369 筆裡有 60 筆與單號日期不同，最多差 49 天）；
 * 單號 J-1150821029 ＝ 字母-＋民國年3碼(115)＋MM(08)＋DD(21)＋序號，開單當下就固定了。
 */
function vkTlogDateSQL(string $tl='tl'): string {
    return "STR_TO_DATE(CONCAT(SUBSTRING($tl.transfer_no,3,3)+1911,SUBSTRING($tl.transfer_no,6,4)),'%Y%m%d')";
}
// 掛在 FROM bom_ing bi ... 的最後面，算出該站憑單日期 vkt.td（不早於發包日的最早一張）
function vkTlogJoin(string $bi='bi'): string {
    $d = vkTlogDateSQL('tl');
    return "LEFT JOIN LATERAL (\n"
         . "            SELECT MIN($d) AS td FROM bom_ing_transfer_log tl\n"
         . "             WHERE tl.bom=$bi.bom AND tl.bom_sn=$bi.bom_sn AND tl.maker_from=$bi.maker_id_no\n"
         . "               AND tl.transfer_no REGEXP " . "'^[A-Za-z]-[0-9]{10}$'" . "\n"
         . "               AND $d >= DATE($bi.outsource_date)\n"
         . "          ) vkt ON TRUE";
}
// QC 檢驗日（驗過了就一定已回廠）；早於發包日的是上一批的，不採用
function vkQcSQL(string $bi='bi'): string {
    return "(CASE WHEN $bi.QC_check_date IS NOT NULL AND DATE($bi.QC_check_date)>=DATE($bi.outsource_date)\n"
         . "                THEN DATE($bi.QC_check_date) END)";
}
/* 實際回廠日＝三個來源取最早的那一天。
 * 用 9999-12-31 當「沒有值」的替身再 NULLIF 掉，是因為 MySQL 的 LEAST 只要有一個 NULL 就整個回 NULL，
 * 三個來源用 COALESCE(LEAST(...),...) 疊起來會變成一長串且很容易少算一種組合。 */
function vkRdSQL(string $bi='bi'): string {
    $qc = vkQcSQL($bi);
    return "NULLIF(LEAST(COALESCE(DATE($bi.return_date),DATE('9999-12-31')),"
         . "COALESCE(vkt.td,DATE('9999-12-31')),"
         . "COALESCE($qc,DATE('9999-12-31'))),DATE('9999-12-31'))";
}
/* 回廠日來源（誰是最早的那一個）：
 *   return=生管登錄的就是最早／transfer·qc=沒登錄，用憑單或 QC 檢驗日補
 *   transfer_earlier·qc_earlier=有登錄，但憑單／QC 日更早，採用較早者
 * 憑單與 QC 同一天時算憑單（移轉憑單是更直接的證據）。 */
function vkRdSrcSQL(string $bi='bi'): string {
    $rd = vkRdSQL($bi);
    $qc = vkQcSQL($bi);
    return "CASE WHEN $rd IS NULL THEN ''\n"
         . "          WHEN DATE($bi.return_date) = $rd THEN 'return'\n"
         . "          WHEN vkt.td = $rd THEN (CASE WHEN $bi.return_date IS NULL THEN 'transfer' ELSE 'transfer_earlier' END)\n"
         . "          WHEN $qc = $rd THEN (CASE WHEN $bi.return_date IS NULL THEN 'qc' ELSE 'qc_earlier' END)\n"
         . "          ELSE '' END";
}

/* ── 本期發包資料（唯一實作）────────────────────────────────────────────────
 * 「發包筆數（已到容忍期）」這個數字底下的每一筆，以及它的容忍天數、截止日、準時與否，
 * 一律由這一支算出來；統計卡片／廠商表（get_kpi_data）與明細清單（get_period_rows）
 * 共用同一份結果，兩邊各寫一次 SQL 遲早會對不起來。
 * 回傳：rows（每筆含 tol/deadline/status）＋期間、容忍截止日、設定值。
 */
function vkPeriodRows(PDO $pdo, string $mode, string $period, string $makerF='', string $procF=''): array {
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

    /* KPI 關鍵績效指標頁（views/news/KPI.php）「不符合標準的明細」裡建立的排除規則也要一起吃，
       兩邊的排除設定必須合併，否則同一個月兩頁的數字還是對不起來（使用者要求 2026-09-18）。
       這裡直接讀表不呼叫 kpi_as_lib，避免兩個共用檔互相 require。 */
    $exclClients=[]; $exclParts=[];
    try{
        $yr=intval(substr($period,0,4)) ?: intval(date('Y'));
        $q=$pdo->prepare("SELECT r.dim, r.val FROM kpi_as_excl_rule r
                          JOIN kpi_as_indicator_year y ON y.indicator_id=r.indicator_id
                          WHERE y.calculator_key='vendor_ontime' AND (r.year=? OR r.scope='all')");
        $q->execute([$yr]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $v=trim((string)$r['val']); if($v==='') continue;
            if($r['dim']==='maker'  && !in_array($v,$exclNames,true))   $exclNames[]=$v;
            elseif($r['dim']==='proc'  && !in_array($v,$exclProcNames,true)) $exclProcNames[]=$v;
            elseif($r['dim']==='client'&& !in_array($v,$exclClients,true))   $exclClients[]=$v;
            elseif($r['dim']==='part'  && !in_array($v,$exclParts,true))     $exclParts[]=$v;
        }
    }catch(Exception $e){}

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
    // KPI 頁建立的客戶／料號排除規則
    if(!empty($exclClients)){
        $ph5=implode(',',array_fill(0,count($exclClients),'?'));
        $where[]="(b.Client_Name IS NULL OR b.Client_Name NOT IN ($ph5))";
        foreach($exclClients as $v) $fp[]=$v;
    }
    if(!empty($exclParts)){
        $ph6=implode(',',array_fill(0,count($exclParts),'?'));
        $where[]="(b.d_id IS NULL OR b.d_id NOT IN ($ph6))";
        foreach($exclParts as $v) $fp[]=$v;
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
                 vkt.td AS rd_log,
                 ".vkQcSQL()." AS rd_qc,
                 ".vkRdSQL()." AS rd,
                 ".vkRdSrcSQL()." AS rd_src,
                 b.Delivery_date AS dd,
                 b.Client_Name AS client_name,
                 b.d_id AS part_no,
                 bi.QC_check,
                 pn.ProcessName
          FROM bom_ing bi
          LEFT JOIN maker_list ml ON ml.maker_id_no=bi.maker_id_no
          LEFT JOIN bom b ON b.bom=bi.bom
          LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
          ".vkTlogJoin()."
          $wSQL ORDER BY COALESCE(ml.maker_id,bi.maker_id), bi.outsource_date DESC";
    $st=$pdo->prepare($sql); $st->execute($fp);
    $allRows=$st->fetchAll(PDO::FETCH_ASSOC);

    // 逐筆決定容忍天數與截止日、準時與否（統計與明細一律吃這裡算出來的值）
    $dlCache=[];
    foreach($allRows as &$row){
        $mkNo=$row['maker_id_no']??''; $mkNm=$row['maker_name']??'';
        $pNo =(int)($row['process_no']??0); $pNm=$row['ProcessName']??'';
        // 容忍天數：廠商特殊 > 製程特殊 > 全域
        $rowTol = $specTolById[$mkNo]
               ?? $specTolByName[$mkNm]
               ?? ($pNo && isset($specProcTolByNo[$pNo]) ? $specProcTolByNo[$pNo] : null)
               ?? $specProcTolByName[$pNm]
               ?? $tol;
        $row['tol']=$rowTol;
        $ck=$row['od'].'|'.$rowTol;
        if(!isset($dlCache[$ck])) $dlCache[$ck]=calcDeadline($row['od'],$rowTol,$maps);
        $row['deadline']=$dlCache[$ck];
        if(!empty($row['rd'])){
            $row['status']=($row['rd']<=$row['deadline'])?'ontime':'late';
            $row['days']=(new DateTime($row['rd']))->diff(new DateTime($row['od']))->days;
        }else{
            $row['status']='not_returned';   // 未回廠且容忍期已過 → 併入逾期計算
            $row['days']=null;
        }
    }
    unset($row);

    return ['rows'=>$allRows,'ds'=>$ds,'de'=>$de,'cutoff'=>$cutoff,'tol'=>$tol,
            'min_txn'=>$minTxn,'grade_rules'=>$gradeRules,'workday_count'=>$wdCount,'maps'=>$maps];
}
