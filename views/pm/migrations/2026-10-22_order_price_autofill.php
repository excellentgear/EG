<?php
/**
 * 訂單單價「自動補單價」（2026-10-22）— 依使用者訂定規則，只補「單價空白」的訂單列
 * 規則1：待轉入(補資料暫存區)同訂單編號下，(料號,數量)組合與筆數 與 訂單追蹤現有完全相同 → 直接補單價
 * 規則2：不符規則1者，同訂單編號＋同料號＋同數量＋製程內容完全相同(且不可空白) → 補單價
 * 其他一律不動（另給建議清單）。補入者：Order_ps 加註「【自動補單價】」、寫 order_price_autofill_log。
 * 刻意不動 Modified_At／Modified_By（訂單變更比對基準）、不覆蓋任何既有單價。
 * 用法：php 本檔 試算；php 本檔 --run 才寫入（可重複執行）。
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$RUN = in_array('--run', $argv, true);
require_once __DIR__ . '/../../../src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();
$db->exec("CREATE TABLE IF NOT EXISTS order_price_autofill_log (
  id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, order_oo VARCHAR(40) NULL, rule_code VARCHAR(10) NOT NULL,
  old_price DECIMAL(15,4) NULL, new_price DECIMAL(15,4) NOT NULL, stage_id INT NULL, created_at DATETIME NOT NULL,
  KEY idx_order (order_id)) CHARACTER SET utf8mb4 COMMENT='自動補單價紀錄'");
$norm = fn($s)=>preg_replace('/\s+/u','',trim((string)$s));
$pk = fn($d,$p)=> $d ? 'd'.$d : 'p'.$norm($p);
$oos = $db->query("SELECT DISTINCT order_oo FROM order_erp_import_stage WHERE state IN ('pending','skipped')")->fetchAll(PDO::FETCH_COLUMN);
$out = []; $rest = []; $stat=['r1'=>0,'r2'=>0,'rest'=>0,'r1_oo'=>0];
foreach (array_chunk($oos, 500) as $ch) {
  $ph = implode(',', array_fill(0,count($ch),'?'));
  $s = $db->prepare("SELECT id,order_oo,part_no,d_id_id,process_text,qty,unit_price,state FROM order_erp_import_stage WHERE order_oo IN ($ph)"); $s->execute($ch);
  $st = []; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $st[$r['order_oo']][] = $r;
  $o = $db->prepare("SELECT Order_id,Order_oo,d_id,d_id_ID,Qty,Processing_items,unit_price FROM order_track WHERE Order_oo IN ($ph) AND (Order_status IS NULL OR Order_status<>6)"); $o->execute($ch);
  $ot = []; foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) $ot[$r['Order_oo']][] = $r;
  foreach ($ch as $oo) {
    $S = $st[$oo] ?? []; $O = $ot[$oo] ?? [];
    $need = array_filter($O, fn($x)=> !((float)$x['unit_price']>0));
    if (!$need) continue;
    // 規則1：同 OO 下 (料號,數量) 組合與筆數完全相同
    $ks = array_map(fn($r)=>$pk($r['d_id_id'],$r['part_no']).'|'.(int)$r['qty'], $S); sort($ks);
    $ko = array_map(fn($r)=>$pk($r['d_id_ID'],$r['d_id']).'|'.(int)$r['Qty'], $O); sort($ko);
    $rule1 = ($ks === $ko);
    if ($rule1) $stat['r1_oo']++;
    foreach ($need as $x) {
      $kx = $pk($x['d_id_ID'],$x['d_id']).'|'.(int)$x['Qty'];
      $cand = []; $why='';
      if ($rule1) {
        foreach ($S as $r) if ($pk($r['d_id_id'],$r['part_no']).'|'.(int)$r['qty']===$kx && (float)$r['unit_price']>0) $cand[]=$r;
        $rule='規則1';
      } else {
        $px = $norm($x['Processing_items']);
        if ($px==='') { $rest[]=[$x,'訂單製程空白，不符規則2']; $stat['rest']++; continue; }
        foreach ($S as $r) if ($pk($r['d_id_id'],$r['part_no']).'|'.(int)$r['qty']===$kx && $norm($r['process_text'])===$px && (float)$r['unit_price']>0) $cand[]=$r;
        $rule='規則2';
      }
      $prices = array_unique(array_map(fn($r)=>round((float)$r['unit_price'],4), $cand));
      if (count($prices)===1) { $out[]=[$x,$rule,$prices[0],$cand[0]['id']]; $stat[$rule==='規則1'?'r1':'r2']++; }
      else { $rest[]=[$x, count($prices)>1 ? '同料號同數量 ERP 有多個單價' : '待轉入資料找不到對應明細']; $stat['rest']++; }
    }
  }
}

$n=0;
if ($RUN) {
  $db->beginTransaction();
  $up=$db->prepare("UPDATE order_track SET unit_price=?, Order_ps=CONCAT(IFNULL(Order_ps,''), IF(IFNULL(Order_ps,'')='','',' '), '【自動補單價】') WHERE Order_id=? AND (unit_price IS NULL OR unit_price=0)");
  $lg=$db->prepare("INSERT INTO order_price_autofill_log (order_id,order_oo,rule_code,old_price,new_price,stage_id,created_at) VALUES (?,?,?,?,?,?,NOW())");
  foreach ($out as [$x,$rule,$p,$sid]) { $up->execute([$p,$x['Order_id']]); if ($up->rowCount()) { $lg->execute([$x['Order_id'],$x['Order_oo'],$rule,$x['unit_price'],$p,$sid]); $n++; } }
  $db->commit();
}
echo ($RUN?"已補入 $n 筆":"試算（未寫入）")."：規則1={$stat['r1']} 規則2={$stat['r2']} 未補={$stat['rest']}
";
