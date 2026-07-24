<?php
/**
 * DataConsole_API.php — 資料急救台後端（模組 data_console）
 *
 * 用途：讓非 IT 管理員在前端查後端資料庫狀態、就地修正（例：BOM 未顯示 QC 已檢驗，
 *       查到底驗了沒並補上旗標）。一律走白名單、transaction、稽核落痕、CSRF。
 *
 * 權限（各頁分開；未指派角色者擋下，無 fallback-to-all）：
 *   - 進入/瀏覽/搜尋/查詢 ： data_console_view
 *   - 新增/修改           ： data_console_edit  且 該表 can_edit=1
 *   - 刪除                ： data_console_delete 且 該表 can_delete=1（另需二次確認）
 *   - 表級設定/關聯地圖    ： 僅管理員
 * 角色 CRUD/指派沿用 Roles_API.php（前端另呼叫）。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/role_features_helper.php';
require_once __DIR__ . '/../common/data_console_lib.php';

function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function deny(){ out(['success'=>false,'message'=>'您沒有執行此操作的權限']); }
function bad($m){ out(['success'=>false,'message'=>$m]); }

if (!isset($_SESSION['id'])) bad('尚未登入');

$db  = new DBConnection();
$pdo = $db->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uid = (int)$_SESSION['id'];

$by = '';
try { $st=$pdo->prepare("SELECT user_cname FROM user WHERE id=? LIMIT 1"); $st->execute([$uid]); $by=trim((string)$st->fetchColumn()); } catch (Throwable $e) {}
if ($by === '') $by = (string)($_SESSION['userName'] ?? ('uid'.$uid));

// ── 權限 ──────────────────────────────────────────────────────────────────
$features   = rf_load_user_features_all($pdo, $uid);
$IS_ADMIN   = rf_has_feature($features, 'all');
$CAN_VIEW   = $IS_ADMIN || rf_has_feature($features, 'data_console_view');
$CAN_EDIT   = $IS_ADMIN || rf_has_feature($features, 'data_console_edit');
$CAN_DELETE = $IS_ADMIN || rf_has_feature($features, 'data_console_delete');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!$CAN_VIEW) deny();

// 表級設定讀取
function dc_cfg(PDO $pdo, string $t): array {
    $st = $pdo->prepare("SELECT can_edit, can_delete, note FROM data_console_table_cfg WHERE table_name=?");
    $st->execute([$t]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['can_edit'=>(int)($r['can_edit']??0), 'can_delete'=>(int)($r['can_delete']??0), 'note'=>$r['note']??''];
}

// 取一列現值（pk 為 [col=>val]）；回傳 [row, whereSql, params]
function dc_fetch_row(PDO $pdo, string $t, array $pk): array {
    $where=[]; $params=[];
    foreach ($pk as $col=>$val) {
        if (!dc_column_exists($pdo,$t,$col)) bad('主鍵欄不存在：'.$col);
        $where[]=dc_q($col).'=?'; $params[]=$val;
    }
    if (!$where) bad('缺少主鍵');
    $sql='SELECT * FROM '.dc_q($t).' WHERE '.implode(' AND ',$where).' LIMIT 1';
    $st=$pdo->prepare($sql); $st->execute($params);
    return [$st->fetch(PDO::FETCH_ASSOC), implode(' AND ',$where), $params];
}

// 篩選運算子 → SQL 片段
function dc_filter_sql(PDO $pdo, string $t, array $filters, array &$params): string {
    $parts=[];
    foreach ($filters as $f) {
        $col=$f['col']??''; $op=$f['op']??'='; $val=$f['val']??'';
        if (!dc_column_exists($pdo,$t,$col)) continue;
        $qc=dc_q($col);
        switch ($op) {
            case 'contains': $parts[]="$qc LIKE ?"; $params[]='%'.$val.'%'; break;
            case '!=':       $parts[]="$qc <> ?";  $params[]=$val; break;
            case '>':        $parts[]="$qc > ?";   $params[]=$val; break;
            case '<':        $parts[]="$qc < ?";   $params[]=$val; break;
            case '>=':       $parts[]="$qc >= ?";  $params[]=$val; break;
            case '<=':       $parts[]="$qc <= ?";  $params[]=$val; break;
            case 'empty':    $parts[]="($qc IS NULL OR $qc='')"; break;
            case 'notempty': $parts[]="($qc IS NOT NULL AND $qc<>'')"; break;
            default:         $parts[]="$qc = ?";   $params[]=$val; break;
        }
    }
    return $parts ? implode(' AND ',$parts) : '';
}

// 解析某表 ref 欄位的顯示值（批次）；回傳 [col => [rawVal => label]]
function dc_resolve_display(PDO $pdo, string $t, array $rows, array $refs): array {
    $map=[];
    foreach ($refs as $col=>$ref) {
        $vals=[];
        foreach ($rows as $r) { $v=$r[$col]??null; if ($v!==null && $v!=='') $vals[(string)$v]=$v; }
        if (!$vals) continue;
        $ph=implode(',',array_fill(0,count($vals),'?'));
        $selCols=array_merge([$ref['pk']],$ref['display']);
        $selCols=array_values(array_unique($selCols));
        $sql='SELECT '.implode(',',array_map('dc_q',$selCols)).' FROM '.dc_q($ref['table']).
             ' WHERE '.dc_q($ref['pk']).' IN ('.$ph.')';
        try {
            $st=$pdo->prepare($sql); $st->execute(array_values($vals));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                $lbl=[]; foreach ($ref['display'] as $dc) if (isset($rr[$dc]) && $rr[$dc]!=='') $lbl[]=$rr[$dc];
                $map[$col][(string)$rr[$ref['pk']]]=implode(' / ',$lbl);
            }
        } catch (Throwable $e) {}
    }
    return $map;
}

switch ($action) {

// ── 進頁初始化：權限旗標 + CSRF + 全表清單（含設定） ────────────────────────
case 'bootstrap': {
    $cfgAll=[];
    foreach ($pdo->query("SELECT table_name,can_edit,can_delete,note FROM data_console_table_cfg")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $cfgAll[$r['table_name']]=$r;
    // 筆數（information_schema 的 table_rows 對 InnoDB 是估算，夠用）
    $rowsEst=[];
    $st=$pdo->prepare("SELECT table_name AS table_name,table_rows AS table_rows FROM information_schema.tables WHERE table_schema=? AND table_type='BASE TABLE'");
    $st->execute([dc_db_name($pdo)]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r=array_change_key_case($r,CASE_LOWER); $rowsEst[$r['table_name']]=(int)$r['table_rows']; }
    $hard=dc_hard_readonly_tables();
    $tables=[];
    foreach (dc_all_tables($pdo) as $t) {
        $c=$cfgAll[$t]??null;
        $tables[]=[
            'name'=>$t,
            'rows'=>$rowsEst[$t]??0,
            'can_edit'=>(int)($c['can_edit']??0),
            'can_delete'=>(int)($c['can_delete']??0),
            'note'=>$c['note']??'',
            'hard_readonly'=>in_array($t,$hard,true),
        ];
    }
    // 角色徽章
    $myRoles=[];
    try { $st=$pdo->prepare("SELECT DISTINCT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND (r.module='data_console' OR r.is_system=1)"); $st->execute([$uid]); $myRoles=$st->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    out(['success'=>true,'csrf'=>dc_csrf_token(),
         'perm'=>['admin'=>$IS_ADMIN,'edit'=>$CAN_EDIT,'delete'=>$CAN_DELETE],
         'operator'=>$by,'roleBadge'=>$IS_ADMIN?'管理員':(empty($myRoles)?'（未指派）':implode('、',$myRoles)),
         'tables'=>$tables]);
}

// ── 某表 schema（欄位＋唯讀旗標＋參照資訊） ─────────────────────────────────
case 'schema': {
    $t=$_GET['table']??$_POST['table']??'';
    if (!dc_table_exists($pdo,$t)) bad('資料表不存在');
    $hard=in_array($t,dc_hard_readonly_tables(),true);
    $cfg=dc_cfg($pdo,$t);
    $refs=dc_table_refs($pdo,$t);
    $cols=[];
    foreach (dc_columns($pdo,$t) as $c) {
        $ro=$hard || dc_col_readonly($c);
        $cols[]=[
            'name'=>$c['column_name'],'type'=>$c['data_type'],'coltype'=>$c['column_type'],
            'nullable'=>$c['is_nullable']==='YES','default'=>$c['column_default'],
            'key'=>$c['column_key'],'extra'=>$c['extra'],'comment'=>$c['column_comment'],
            'readonly'=>$ro,
            'ref'=>isset($refs[$c['column_name']])?$refs[$c['column_name']]:null,
        ];
    }
    out(['success'=>true,'table'=>$t,'pk'=>dc_pk($pdo,$t),'columns'=>$cols,
         'hard_readonly'=>$hard,'can_edit'=>$cfg['can_edit'],'can_delete'=>$cfg['can_delete'],'note'=>$cfg['note']]);
}

// ── 瀏覽/查詢：分頁＋篩選＋排序＋ref 顯示解析 ───────────────────────────────
case 'rows': {
    $t=$_GET['table']??$_POST['table']??'';
    if (!dc_table_exists($pdo,$t)) bad('資料表不存在');
    $page=max(1,(int)($_REQUEST['page']??1));
    $per=(int)($_REQUEST['per']??20); if(!in_array($per,[5,10,20,50],true))$per=20;
    $filters=json_decode($_REQUEST['filters']??'[]',true); if(!is_array($filters))$filters=[];
    $sortCol=$_REQUEST['sort_col']??''; $sortDir=strtoupper($_REQUEST['sort_dir']??'')==='DESC'?'DESC':'ASC';
    $params=[]; $where=dc_filter_sql($pdo,$t,$filters,$params);
    $whereSql=$where?(' WHERE '.$where):'';
    $cst=$pdo->prepare("SELECT COUNT(*) FROM ".dc_q($t).$whereSql); $cst->execute($params); $total=(int)$cst->fetchColumn();
    $orderSql='';
    if ($sortCol && dc_column_exists($pdo,$t,$sortCol)) $orderSql=' ORDER BY '.dc_q($sortCol).' '.$sortDir;
    $off=($page-1)*$per;
    $sql='SELECT * FROM '.dc_q($t).$whereSql.$orderSql.' LIMIT '.$per.' OFFSET '.$off;
    $st=$pdo->prepare($sql); $st->execute($params);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $refs=dc_table_refs($pdo,$t);
    $disp=dc_resolve_display($pdo,$t,$rows,$refs);
    out(['success'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'per'=>$per,
         'pk'=>dc_pk($pdo,$t),'ref_display'=>$disp]);
}

// ── 全域搜尋：一個關鍵字掃遍所有表 ─────────────────────────────────────────
case 'search': {
    $kw=trim((string)($_REQUEST['q']??''));
    if ($kw==='') bad('請輸入搜尋關鍵字');
    $isNum=is_numeric($kw);
    $results=[]; $scanned=0; $capTables=400; $capHitsPerTable=5;
    foreach (dc_all_tables($pdo) as $t) {
        if ($scanned++>$capTables) break;
        $cols=dc_columns($pdo,$t);
        $ors=[]; $params=[];
        foreach ($cols as $c) {
            $dt=$c['data_type'];
            if (in_array($dt,['varchar','char','text','tinytext','mediumtext','longtext'],true)) {
                $ors[]=dc_q($c['column_name']).' LIKE ?'; $params[]='%'.$kw.'%';
            } elseif ($isNum && in_array($dt,['int','bigint','smallint','mediumint','tinyint'],true)) {
                $ors[]=dc_q($c['column_name']).' = ?'; $params[]=$kw;
            }
        }
        if (!$ors) continue;
        $wsql=implode(' OR ',$ors);
        try {
            $cst=$pdo->prepare("SELECT COUNT(*) FROM ".dc_q($t)." WHERE ".$wsql);
            $cst->execute($params); $cnt=(int)$cst->fetchColumn();
            if ($cnt<=0) continue;
            $st=$pdo->prepare("SELECT * FROM ".dc_q($t)." WHERE ".$wsql." LIMIT ".$capHitsPerTable);
            $st->execute($params); $sample=$st->fetchAll(PDO::FETCH_ASSOC);
            $results[]=['table'=>$t,'count'=>$cnt,'pk'=>dc_pk($pdo,$t),'sample'=>$sample];
        } catch (Throwable $e) { /* 某些表可能因欄位型別/字集比對失敗，略過 */ }
    }
    usort($results,fn($a,$b)=>$b['count']<=>$a['count']);
    out(['success'=>true,'keyword'=>$kw,'hits'=>$results,'table_count'=>count($results)]);
}

// ── 參照下拉：搜尋即輸入 ───────────────────────────────────────────────────
case 'ref_options': {
    $t=$_REQUEST['table']??''; $col=$_REQUEST['column']??''; $q=trim((string)($_REQUEST['q']??''));
    if (!dc_table_exists($pdo,$t)||!dc_column_exists($pdo,$t,$col)) bad('欄位不存在');
    $ref=dc_resolve_ref($pdo,$t,$col);
    if (!$ref) out(['success'=>true,'options'=>[]]);
    $sel=array_values(array_unique(array_merge([$ref['pk']],$ref['display'])));
    $where=''; $params=[];
    if ($q!=='') {
        $ors=[dc_q($ref['pk']).' = ?']; $params[]=$q;
        foreach ($ref['display'] as $dc){ $ors[]=dc_q($dc).' LIKE ?'; $params[]='%'.$q.'%'; }
        $where=' WHERE '.implode(' OR ',$ors);
    }
    $sql='SELECT '.implode(',',array_map('dc_q',$sel)).' FROM '.dc_q($ref['table']).$where.' LIMIT 30';
    $st=$pdo->prepare($sql); $st->execute($params);
    $opts=[];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lbl=[]; foreach ($ref['display'] as $dc) if(isset($r[$dc])&&$r[$dc]!=='')$lbl[]=$r[$dc];
        $opts[]=['id'=>$r[$ref['pk']],'label'=>implode(' / ',$lbl)?:('#'.$r[$ref['pk']])];
    }
    out(['success'=>true,'options'=>$opts,'ref'=>$ref]);
}

// ── 修改一列 ──────────────────────────────────────────────────────────────
case 'update': {
    if (!$CAN_EDIT) deny();
    if (!dc_csrf_ok($_POST['csrf']??'')) bad('CSRF 驗證失敗，請重新整理頁面');
    $t=$_POST['table']??'';
    if (!dc_table_exists($pdo,$t)) bad('資料表不存在');
    if (in_array($t,dc_hard_readonly_tables(),true)) bad('此表為永久唯讀（紀錄/稽核表）');
    $cfg=dc_cfg($pdo,$t); if(!$IS_ADMIN && !$cfg['can_edit']) bad('此表尚未開放編輯（請管理員於設定開啟）');
    $pk=json_decode($_POST['pk']??'[]',true); $fields=json_decode($_POST['fields']??'{}',true);
    $reason=trim((string)($_POST['reason']??''));
    if (!is_array($pk)||!$pk) bad('缺少主鍵');
    if (!is_array($fields)||!$fields) bad('沒有要修改的欄位');
    if ($reason==='') bad('請填寫修改原因');
    // 驗證每個欄位可改
    $colMeta=[]; foreach (dc_columns($pdo,$t) as $c) $colMeta[$c['column_name']]=$c;
    foreach ($fields as $col=>$v) {
        if (!isset($colMeta[$col])) bad('欄位不存在：'.$col);
        if (dc_col_readonly($colMeta[$col])) bad('欄位唯讀不可改：'.$col);
    }
    try {
        $pdo->beginTransaction();
        [$oldRow,$whereSql,$wparams]=dc_fetch_row($pdo,$t,$pk);
        if (!$oldRow){ $pdo->rollBack(); bad('找不到該筆資料（可能已被更動）'); }
        $set=[]; $sp=[]; $changes=[];
        foreach ($fields as $col=>$v) {
            $old=$oldRow[$col]??null;
            if ((string)$old===(string)$v) continue;
            $set[]=dc_q($col).'=?'; $sp[]=($v===''&&$colMeta[$col]['is_nullable']==='YES')?null:$v;
            $changes[$col]=['old'=>$old,'new'=>$v];
        }
        if (!$set){ $pdo->rollBack(); bad('沒有實際變動'); }
        $sql='UPDATE '.dc_q($t).' SET '.implode(',',$set).' WHERE '.$whereSql;
        $st=$pdo->prepare($sql); $st->execute(array_merge($sp,$wparams));
        $pkVal=implode(',',array_values($pk));
        dc_audit($pdo,'UPDATE',$t,$pkVal,null,['reason'=>$reason,'fields'=>$changes],$uid,$by);
        $pdo->commit();
        out(['success'=>true,'message'=>'已更新','changed'=>count($changes)]);
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); bad('更新失敗：'.$e->getMessage()); }
}

// ── 新增一列 ──────────────────────────────────────────────────────────────
case 'insert': {
    if (!$CAN_EDIT) deny();
    if (!dc_csrf_ok($_POST['csrf']??'')) bad('CSRF 驗證失敗，請重新整理頁面');
    $t=$_POST['table']??'';
    if (!dc_table_exists($pdo,$t)) bad('資料表不存在');
    if (in_array($t,dc_hard_readonly_tables(),true)) bad('此表為永久唯讀');
    $cfg=dc_cfg($pdo,$t); if(!$IS_ADMIN && !$cfg['can_edit']) bad('此表尚未開放編輯');
    $fields=json_decode($_POST['fields']??'{}',true); $reason=trim((string)($_POST['reason']??''));
    if (!is_array($fields)||!$fields) bad('沒有要新增的內容');
    if ($reason==='') bad('請填寫新增原因');
    $colMeta=[]; foreach (dc_columns($pdo,$t) as $c) $colMeta[$c['column_name']]=$c;
    $cols=[]; $ph=[]; $vals=[];
    foreach ($fields as $col=>$v) {
        if (!isset($colMeta[$col])) bad('欄位不存在：'.$col);
        if (stripos((string)$colMeta[$col]['extra'],'auto_increment')!==false) continue; // 跳過自增
        $cols[]=dc_q($col); $ph[]='?'; $vals[]=($v===''&&$colMeta[$col]['is_nullable']==='YES')?null:$v;
    }
    if (!$cols) bad('沒有可寫入的欄位');
    try {
        $pdo->beginTransaction();
        $sql='INSERT INTO '.dc_q($t).' ('.implode(',',$cols).') VALUES ('.implode(',',$ph).')';
        $st=$pdo->prepare($sql); $st->execute($vals);
        $newId=$pdo->lastInsertId();
        dc_audit($pdo,'INSERT',$t,(string)$newId,null,['reason'=>$reason,'fields'=>$fields],$uid,$by);
        $pdo->commit();
        out(['success'=>true,'message'=>'已新增','insert_id'=>$newId]);
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); bad('新增失敗：'.$e->getMessage()); }
}

// ── 刪除影響分析（唯讀） ───────────────────────────────────────────────────
case 'delete_impact': {
    $t=$_REQUEST['table']??''; $pk=json_decode($_REQUEST['pk']??'[]',true);
    if (!dc_table_exists($pdo,$t)) bad('資料表不存在');
    if (!is_array($pk)||!$pk) bad('缺少主鍵');
    [$oldRow]=dc_fetch_row($pdo,$t,$pk);
    if (!$oldRow) bad('找不到該筆資料');
    // 目標列的主鍵值（單一主鍵才做外參掃描；複合主鍵僅提示）
    $pkCols=dc_pk($pdo,$t);
    $refsIn=[];
    if (count($pkCols)===1) {
        $pkVal=$oldRow[$pkCols[0]]??null;
        if ($pkVal!==null) {
            foreach (dc_referencing_columns($pdo,$t) as $rc) {
                try {
                    $st=$pdo->prepare("SELECT COUNT(*) FROM ".dc_q($rc['table'])." WHERE ".dc_q($rc['column'])."=?");
                    $st->execute([$pkVal]); $cnt=(int)$st->fetchColumn();
                    if ($cnt>0) $refsIn[]=['table'=>$rc['table'],'column'=>$rc['column'],'count'=>$cnt];
                } catch (Throwable $e) {}
            }
        }
    }
    usort($refsIn,fn($a,$b)=>$b['count']<=>$a['count']);
    $totalRefs=array_sum(array_column($refsIn,'count'));
    out(['success'=>true,'row'=>$oldRow,'pk_cols'=>$pkCols,'composite'=>count($pkCols)>1,
         'referenced_by'=>$refsIn,'total_refs'=>$totalRefs,
         'can_delete'=>($IS_ADMIN || dc_cfg($pdo,$t)['can_delete'])?1:0]);
}

// ── 刪除一列（二次確認後） ─────────────────────────────────────────────────
case 'delete': {
    if (!$CAN_DELETE) deny();
    if (!dc_csrf_ok($_POST['csrf']??'')) bad('CSRF 驗證失敗，請重新整理頁面');
    $t=$_POST['table']??'';
    if (!dc_table_exists($pdo,$t)) bad('資料表不存在');
    if (in_array($t,dc_hard_readonly_tables(),true)) bad('此表為永久唯讀，不可刪除');
    $cfg=dc_cfg($pdo,$t); if(!$IS_ADMIN && !$cfg['can_delete']) bad('此表尚未開放刪除');
    $pk=json_decode($_POST['pk']??'[]',true); $reason=trim((string)($_POST['reason']??'')); $confirm=trim((string)($_POST['confirm']??''));
    if (!is_array($pk)||!$pk) bad('缺少主鍵');
    if ($reason==='') bad('請填寫刪除原因');
    if ($confirm!=='DELETE') bad('請於確認框輸入 DELETE 以確認刪除');
    try {
        $pdo->beginTransaction();
        [$oldRow,$whereSql,$wparams]=dc_fetch_row($pdo,$t,$pk);
        if (!$oldRow){ $pdo->rollBack(); bad('找不到該筆資料'); }
        $sql='DELETE FROM '.dc_q($t).' WHERE '.$whereSql.' LIMIT 1';
        $st=$pdo->prepare($sql); $st->execute($wparams);
        $pkVal=implode(',',array_values($pk));
        dc_audit($pdo,'DELETE',$t,$pkVal,null,['reason'=>$reason,'deleted_row'=>$oldRow],$uid,$by);
        $pdo->commit();
        out(['success'=>true,'message'=>'已刪除']);
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); bad('刪除失敗（可能有外鍵約束）：'.$e->getMessage()); }
}

// ── 表級設定（僅管理員） ───────────────────────────────────────────────────
case 'save_table_cfg': {
    if (!$IS_ADMIN) deny();
    if (!dc_csrf_ok($_POST['csrf']??'')) bad('CSRF 驗證失敗');
    $t=$_POST['table']??''; $ce=(int)!!($_POST['can_edit']??0); $cd=(int)!!($_POST['can_delete']??0); $note=trim((string)($_POST['note']??''));
    if (!dc_table_exists($pdo,$t)) bad('資料表不存在');
    if (in_array($t,dc_hard_readonly_tables(),true)) bad('此表為永久唯讀，不可開放');
    $st=$pdo->prepare("INSERT INTO data_console_table_cfg (table_name,can_edit,can_delete,note,updated_by,updated_at)
        VALUES (?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE can_edit=VALUES(can_edit),can_delete=VALUES(can_delete),note=VALUES(note),updated_by=VALUES(updated_by),updated_at=NOW()");
    $st->execute([$t,$ce,$cd,$note,$by]);
    out(['success'=>true,'message'=>'設定已儲存']);
}

// ── 關聯地圖覆寫（僅管理員） ───────────────────────────────────────────────
case 'refmap_list': {
    if (!$IS_ADMIN) deny();
    $rows=$pdo->query("SELECT * FROM data_console_refmap ORDER BY src_table,src_column")->fetchAll(PDO::FETCH_ASSOC);
    out(['success'=>true,'rows'=>$rows]);
}
case 'refmap_save': {
    if (!$IS_ADMIN) deny();
    if (!dc_csrf_ok($_POST['csrf']??'')) bad('CSRF 驗證失敗');
    $srcT=trim((string)($_POST['src_table']??'')); $srcC=trim((string)($_POST['src_column']??''));
    $refT=trim((string)($_POST['ref_table']??'')); $refPk=trim((string)($_POST['ref_pk']??''));
    $disp=trim((string)($_POST['display_cols']??''));
    if ($srcC===''||$refT==='') bad('來源欄位與參照表為必填');
    if (!dc_table_exists($pdo,$refT)) bad('參照表不存在');
    if ($srcT!==''&&!dc_table_exists($pdo,$srcT)) bad('來源表不存在');
    $st=$pdo->prepare("INSERT INTO data_console_refmap (src_table,src_column,ref_table,ref_pk,display_cols,updated_by,updated_at)
        VALUES (?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE ref_table=VALUES(ref_table),ref_pk=VALUES(ref_pk),display_cols=VALUES(display_cols),updated_by=VALUES(updated_by),updated_at=NOW()");
    $st->execute([$srcT!==''?$srcT:null,$srcC,$refT,$refPk!==''?$refPk:null,$disp!==''?$disp:null,$by]);
    out(['success'=>true,'message'=>'關聯已儲存']);
}
case 'refmap_del': {
    if (!$IS_ADMIN) deny();
    if (!dc_csrf_ok($_POST['csrf']??'')) bad('CSRF 驗證失敗');
    $id=(int)($_POST['id']??0);
    $pdo->prepare("DELETE FROM data_console_refmap WHERE id=?")->execute([$id]);
    out(['success'=>true,'message'=>'已刪除關聯']);
}

default: bad('未知動作');
}
