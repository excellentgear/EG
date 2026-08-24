<?php
/**
 * 2026-08-24 使用者要求（建議 D）：刀具規格從「料號標籤」改成專屬表 d_setting_tool
 * （比照齒輪規格 d_setting_gear 的作法，之後要能用來篩選）
 *
 * 來源標籤：滾齒刀規格(41)／插齒刀規格(35)／模數(32)／材質(刀具)(33)／塗層(31)
 * 一個料號 → 一列 d_setting_tool。可重複執行（先刪掉該料號既有的那一列再重建）。
 *
 * 用法： php migrate_tool_spec_table.php [--dry]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
$DRY = in_array('--dry', $argv, true);
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306","EG-TS2024","excell30367593",
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET NAMES utf8mb4");
$say = fn($s) => print(($DRY ? '[DRY] ' : '[RUN] ') . $s . PHP_EOL);
$num = function($v){ return ($v===null||trim((string)$v)==='' || !is_numeric(trim((string)$v))) ? null : (float)trim($v); };

const L_HOB=41, L_SHAPER=35, L_MODULE=32, L_MATERIAL=33, L_COATING=31;

// 該料號所有「來源標籤 → 子標籤名稱 => 值」
$q = $db->prepare("SELECT m.label_id, s.sub_name, sm.input_value
                   FROM item_label_map m
                   JOIN item_sub_label_map sm ON sm.parent_map_id=m.map_id
                   JOIN dict_label_sub s ON s.sub_id=sm.sub_id
                   WHERE m.d_id=? AND m.label_id IN (".L_HOB.",".L_SHAPER.",".L_MODULE.",".L_MATERIAL.",".L_COATING.")");

$parts = $db->query("SELECT DISTINCT d_id FROM item_label_map
                     WHERE label_id IN (".L_HOB.",".L_SHAPER.",".L_MODULE.",".L_MATERIAL.",".L_COATING.")")->fetchAll();
$say("來源料號 ".count($parts)." 筆");

$db->beginTransaction();
try {
$made=0; $stat=['hob'=>0,'shaper'=>0,''=>0];
foreach ($parts as $P) {
    $did=(int)$P['d_id'];
    $q->execute([$did]);
    $rows=$q->fetchAll();
    $has=[]; foreach($rows as $r) $has[(int)$r['label_id']]=true;
    $row=['d_setting_id'=>$did,'tool_kind'=>isset($has[L_HOB])?'hob':(isset($has[L_SHAPER])?'shaper':null)];
    foreach ($rows as $r) {
        $lid=(int)$r['label_id']; $nm=$r['sub_name']; $v=$r['input_value'];
        if ($lid===L_MODULE) {
            $row['module_input_type']=$nm;
            $row['module_value']=$num($v);
            $row['module_display']=in_array($nm,['M','CP','DP'],true) ? $nm.trim((string)$v) : ($nm==='8YU'?'8YU':trim((string)$v));
        } elseif ($lid===L_MATERIAL) { $row['material']=$nm;
        } elseif ($lid===L_COATING)  { $row['coating']=$nm;
        } elseif ($lid===L_HOB) {
            switch ($nm) {
                case '壓力角':    $row['pressure_angle']=trim((string)$v); break;
                case '牙口數-RH': $row['starts_rh']=$num($v); break;
                case '牙口數-LH': $row['starts_lh']=$num($v); break;
                case '外徑-長度': $row['od_length']=trim((string)$v); break;
                case 'D+F':       $row['d_plus_f']=$num($v); break;
                case '內徑':      $row['bore_dia']=$num($v); break;
                case '六栓槽':    $row['has_six_spline']=1; break;
                case '類型':      $row['tool_type']=trim((string)$v); break;
            }
        } elseif ($lid===L_SHAPER) {
            switch ($nm) {
                case '齒數':   $row['teeth']=$num($v); break;
                case '壓力角': $row['pressure_angle']=trim((string)$v); break;
                case '⌀':      $row['outer_dia']=$num($v); break;
                case '標籤':   $row['shaper_tag']=trim((string)$v); break;
            }
        }
    }
    $stat[$row['tool_kind'] ?? '']++;
    $made++;
    if (!$DRY) {
        $db->prepare("DELETE FROM d_setting_tool WHERE d_setting_id=?")->execute([$did]);   // 可重跑
        $cols=array_keys($row); $ph=implode(',',array_fill(0,count($cols),'?'));
        $db->prepare("INSERT INTO d_setting_tool (".implode(',',$cols).",Created_By,Created_At) VALUES ({$ph},'migrate',NOW())")
           ->execute(array_values($row));
    }
}
$say("建立 {$made} 列刀具規格：滾齒刀 {$stat['hob']}、插齒刀 {$stat['shaper']}、其他 {$stat['']}");
if ($DRY) { $db->rollBack(); echo "\n（--dry 模式，未寫入）\n"; }
else      { $db->commit();  echo "\n完成。原標籤資料先保留不動，確認無誤後再停用來源標籤。\n"; }
} catch (Throwable $e) { $db->rollBack(); fwrite(STDERR,"FAILED: ".$e->getMessage()."\n"); exit(1); }
