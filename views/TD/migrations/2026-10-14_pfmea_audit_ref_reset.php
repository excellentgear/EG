<?php
/**
 * PFMEA 參考資料依「稽核製程」重整（2026-10-14 使用者交辦）
 *
 * 規則：只有訂單追蹤設為「稽核製程」的製程小類才需要 PFMEA 參考資料（pfmea_audit_process_sync()
 * 即時決定，不寫死）。本腳本做三件事：
 *   1) 非稽核製程的參考資料一律「停用」（is_active=0，可還原；被停用的 id 寫進 .undo.json）
 *   2) 稽核製程裡不符合 PFMEA 填寫原則的舊樣板／混用「滾/插齒」的舊資料停用
 *      （原則取自表單評級說明：O或D>7改善後須降到3以下、S>9時O與D須≦2、改善後RPN須降低）
 *   3) 為「插齒」稽核製程（process_no 5／154）依該製程特性建立完整參考資料
 *      （項目→功能→要求→潛在失效模式→後果／原因、整組樣板、評價建議規則、控制預防／偵測／措施清單）
 * 預設只試算（dry-run），加 --run 才寫入；可重複執行（已存在的樣板不重複建立）。
 *
 *   php views/TD/migrations/2026-10-14_pfmea_audit_ref_reset.php          （試算）
 *   php views/TD/migrations/2026-10-14_pfmea_audit_ref_reset.php --run
 *   php views/TD/migrations/2026-10-14_pfmea_audit_ref_reset.php --undo    （依 .undo.json 還原停用）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
chdir(dirname(__DIR__, 3));
require_once 'src/common/DBConnection.php';
require_once 'src/common/pfmea_lib.php';
require_once 'src/common/pfmea_reference_lib.php';
$db = (new DBConnection())->getPDO();
$db->exec("SET NAMES utf8mb4");
$RUN  = in_array('--run', $argv, true);
$UNDO = in_array('--undo', $argv, true);
$undoFile = __DIR__ . '/2026-10-14_pfmea_audit_ref_reset.undo.json';
$UID = 0; $UNAME = '系統(稽核製程參考資料)';

if ($UNDO) {
    $u = json_decode((string)@file_get_contents($undoFile), true);
    if (!is_array($u)) exit("找不到 undo 檔\n");
    foreach ($u as $tbl => $ids) {
        if (!$ids) continue;
        $in = implode(',', array_map('intval', $ids));
        $n = $db->exec("UPDATE $tbl SET is_active=1 WHERE id IN ($in)");
        echo "$tbl 還原 $n 筆\n";
    }
    exit;
}

$sync = pfmea_audit_process_sync($db);
$auditNos = $sync['audit_nos'];
echo "稽核製程小類 process_no：", implode(',', $auditNos), "\n";

$undo = [];
$deact = function (string $tbl, string $where, array $params = []) use ($db, $RUN, &$undo) {
    $st = $db->prepare("SELECT id FROM $tbl WHERE is_active=1 AND ($where)");
    $st->execute($params);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    echo sprintf("  %-28s 停用 %d 筆\n", $tbl, count($ids));
    if (!$ids) return;
    $undo[$tbl] = array_merge($undo[$tbl] ?? [], $ids);
    if ($RUN) $db->exec("UPDATE $tbl SET is_active=0 WHERE id IN (" . implode(',', $ids) . ")");
};

/* ---------- 1) 非稽核製程 ---------- */
$non = array_map('intval', $db->query("SELECT id FROM pfmea_process WHERE is_enabled=0")->fetchAll(PDO::FETCH_COLUMN));
echo "\n[1] 非稽核製程（", count($non), " 個）的參考資料停用\n";
if ($non) {
    $nin = implode(',', $non);
    $items = array_map('intval', $db->query("SELECT id FROM pfmea_item_option WHERE process_id IN ($nin)")->fetchAll(PDO::FETCH_COLUMN));
    $iin = $items ? implode(',', $items) : '0';
    $funcs = array_map('intval', $db->query("SELECT id FROM pfmea_function_option WHERE item_option_id IN ($iin)")->fetchAll(PDO::FETCH_COLUMN));
    $fin = $funcs ? implode(',', $funcs) : '0';
    $fms = array_map('intval', $db->query("SELECT id FROM pfmea_process_failure_mode WHERE process_id IN ($nin)")->fetchAll(PDO::FETCH_COLUMN));
    $fmin = $fms ? implode(',', $fms) : '0';
    $deact('pfmea_item_option', "process_id IN ($nin)");
    $deact('pfmea_function_option', "item_option_id IN ($iin)");
    $deact('pfmea_requirement_option', "process_id IN ($nin) OR item_option_id IN ($iin) OR function_option_id IN ($fin)");
    $deact('pfmea_process_failure_mode', "process_id IN ($nin)");
    $deact('pfmea_field_link', "scope_fm_id IN ($fmin)");
    $deact('pfmea_item_template', "process_id IN ($nin)");
    $deact('pfmea_rating_rule', "process_id IN ($nin)");
    $deact('pfmea_part_binding', "process_id IN ($nin)");
}

/* ---------- 2) 稽核製程內不符填寫原則的舊資料 ---------- */
echo "\n[2] 稽核製程內的舊資料停用（不符 PFMEA 填寫原則／混用滾齒）\n";
$pid = function (string $code) use ($db): int {
    $st = $db->prepare("SELECT id FROM pfmea_process WHERE process_code=? LIMIT 1"); $st->execute([$code]);
    return (int)$st->fetchColumn();
};
// 2a 齒研類：O或D>7 改善後未降到3以下的舊樣板（項目名稱是「齒研中心定位不佳」「齒研參數設定錯誤」那幾筆）
$oldItemNames = ['齒研中心定位不佳', '齒研參數設定錯誤'];
$ph = implode(',', array_fill(0, count($oldItemNames), '?'));
$oldItems = array_map('intval', (function () use ($db, $ph, $oldItemNames) { $st = $db->prepare("SELECT id FROM pfmea_item_option WHERE item_name IN ($ph)"); $st->execute($oldItemNames); return $st->fetchAll(PDO::FETCH_COLUMN); })());
$oiin = $oldItems ? implode(',', $oldItems) : '0';
$oldFuncs = array_map('intval', $db->query("SELECT id FROM pfmea_function_option WHERE item_option_id IN ($oiin)")->fetchAll(PDO::FETCH_COLUMN));
$ofin = $oldFuncs ? implode(',', $oldFuncs) : '0';
$deact('pfmea_item_option', "id IN ($oiin)");
$deact('pfmea_function_option', "item_option_id IN ($oiin)");
$deact('pfmea_requirement_option', "item_option_id IN ($oiin) OR function_option_id IN ($ofin)");
$deact('pfmea_process_failure_mode', "item_option_id IN ($oiin) OR failure_mode='中心定位不佳'");
$deact('pfmea_item_template', "item_name IN ('齒研中心定位不佳','齒研參數設定錯誤')");
// 2b 插齒（process_no 5／154）整個重建：舊的是「滾/插齒」混用，滾齒不是稽核製程
foreach (['5', '154'] as $code) {
    $p = $pid($code);
    if (!$p) continue;
    echo "  插齒 process_code=$code (pfmea_process.id=$p)\n";
    $notMine = "COALESCE(created_by_name,'')<>'" . $UNAME . "'";
    $its = array_map('intval', $db->query("SELECT id FROM pfmea_item_option WHERE process_id=$p AND $notMine")->fetchAll(PDO::FETCH_COLUMN));
    $itin = $its ? implode(',', $its) : '0';
    $fns = array_map('intval', $db->query("SELECT id FROM pfmea_function_option WHERE item_option_id IN ($itin)")->fetchAll(PDO::FETCH_COLUMN));
    $fnin = $fns ? implode(',', $fns) : '0';
    $deact('pfmea_item_option', "process_id=$p AND $notMine");
    $deact('pfmea_function_option', "item_option_id IN ($itin)");
    $deact('pfmea_requirement_option', "(process_id=$p OR item_option_id IN ($itin) OR function_option_id IN ($fnin)) AND $notMine");
    $deact('pfmea_process_failure_mode', "process_id=$p AND $notMine");
    $deact('pfmea_item_template', "process_id=$p AND $notMine");
    $deact('pfmea_rating_rule', "process_id=$p AND $notMine");
}
// 2c 失效模式下掛的後果／原因：掛在已停用失效模式上的一律停用
$deact('pfmea_field_link', "scope_fm_id IS NOT NULL AND scope_fm_id IN (SELECT id FROM pfmea_process_failure_mode WHERE is_active=0)");

/* ---------- 3) 插齒參考資料 ---------- */
echo "\n[3] 建立「插齒」稽核製程參考資料\n";
$ITEM = '插齒';
$SEED = [
  ['func'=>'尺寸控制','req'=>'跨棒距(跨齒厚)符合圖面公差','fm'=>'跨棒距過小 (切削過度)',
   'effect'=>'齒輪嚙合間隙過大，傳動時產生敲擊異音與震動','S'=>7,
   'cause'=>'插齒刀磨耗未補償、徑向切入量設定錯誤、刀具重磨後補償值未更新','O'=>3,
   'prev'=>'依刀具壽命管制換刀、首件確認補償值並記錄、程式參數防呆','det'=>'跨棒距／公法線千分尺量測（首件、中間、末件）','D'=>3,
   'act'=>"1.首件量測後確認補償值並記錄於巡檢表\n2.刀具重磨後必須重新校正切入量",'nS'=>7,'nO'=>2,'nD'=>2],
  ['func'=>'尺寸控制','req'=>'跨棒距(跨齒厚)符合圖面公差','fm'=>'跨棒距過大 (切削不足)',
   'effect'=>'齒輪嚙合時產生干涉、負載過大甚至卡死無法運轉','S'=>8,
   'cause'=>'插齒刀鈍化導致切削不足、切入量設定不足、前製程齒厚預留量不當','O'=>3,
   'prev'=>'依刀具壽命換刀、切入量首件確認、前製程預留量依預留量管理表','det'=>'跨棒距／公法線量測（首件＋巡檢）','D'=>3,
   'act'=>"1.確認前製程齒厚預留量符合預留量管理表\n2.鈍化跡象（切屑顏色、聲音）納入巡檢重點",'nS'=>8,'nO'=>2,'nD'=>2],
  ['func'=>'幾何精度 (Profile)','req'=>'齒形誤差符合圖面精度等級','fm'=>'齒形不對稱或齒底過淺',
   'effect'=>'接觸斑點不良、邊緣負載過重、產生高頻噪音','S'=>7,
   'cause'=>'插齒刀刃形磨損或重磨誤差、刀具安裝偏心','O'=>3,
   'prev'=>'刀具使用次數與重磨次數管控、刀具安裝後以量表確認偏擺','det'=>'齒輪量測儀齒形檢測（首件）','D'=>3,
   'act'=>"1.刀具重磨後送量測確認刃形\n2.刀具安裝後偏擺須符合標準才可加工",'nS'=>7,'nO'=>2,'nD'=>2],
  ['func'=>'幾何精度 (Lead)','req'=>'齒向誤差符合圖面精度等級','fm'=>'齒向(導程)偏斜或誤差大',
   'effect'=>'齒輪軸向接觸不良、嚙合時單邊受力，降低齒輪壽命','S'=>7,
   'cause'=>'機台主軸導向間隙過大、工件夾持端面不垂直、夾持面有異物','O'=>4,
   'prev'=>'夾持前清潔基準面、機台定期精度檢查、夾具定期校正','det'=>'齒輪量測儀齒向檢測（首件）','D'=>3,
   'act'=>"1.架機後以量表確認工件端面跳動\n2.機台導向間隙納入定期保養項目",'nS'=>7,'nO'=>3,'nD'=>2],
  ['func'=>'幾何精度 (Pitch)','req'=>'齒距與累積誤差符合圖面精度等級','fm'=>'單一齒距／累積齒距誤差大',
   'effect'=>'旋轉傳動時產生速度波動，引發低頻震動與異音','S'=>7,
   'cause'=>'分度機構磨耗、夾具心軸與工件不同心、環境溫度變化','O'=>3,
   'prev'=>'分度機構定期保養、夾具心軸定期校正、機台恆溫管理','det'=>'齒距量測（首件、換線後必檢）','D'=>3,
   'act'=>"1.分度機構納入定期精度檢查\n2.換線後首件一律量測齒距",'nS'=>7,'nO'=>2,'nD'=>2],
  ['func'=>'位置精度','req'=>'齒輪對基準孔同心度(跳動量)符合圖面','fm'=>'齒輪偏心量／跳動量過大',
   'effect'=>'運轉時產生振動與噪音，嚙合不均勻導致磨耗加速','S'=>8,
   'cause'=>'夾具心軸磨耗、基準孔尺寸偏差、夾持面異物','O'=>4,
   'prev'=>'夾具心軸定期打表校正、前製程基準孔尺寸管制、夾持前清潔','det'=>'跳動量測（首件＋巡檢）','D'=>3,
   'act'=>"1.夾具心軸每月打表校正並記錄\n2.進料時抽驗基準孔尺寸",'nS'=>8,'nO'=>2,'nD'=>2],
  ['func'=>'表面品質控制','req'=>'齒面無撕裂、刀痕在圖面容許範圍','fm'=>'齒面撕裂或嚴重刀痕',
   'effect'=>'齒面粗糙影響嚙合平順度、加速磨耗與噪音','S'=>6,
   'cause'=>'插齒刀崩刃或鈍化、切削液供給不足、進給量過大','O'=>4,
   'prev'=>'依刀具壽命換刀、切削液流量開機點檢、進給量依SOP設定','det'=>'目視檢查齒面並以粗糙度儀抽檢（首件＋巡檢）','D'=>5,
   'act'=>"1.首件以粗糙度儀量測\n2.切削液流量納入開機點檢",'nS'=>6,'nO'=>2,'nD'=>4],
  ['func'=>'邊緣品質控制','req'=>'齒端毛邊符合圖面要求','fm'=>'齒端產生粗大毛邊',
   'effect'=>'毛邊刮傷後製程或組裝件，影響組裝與安全','S'=>5,
   'cause'=>'插齒刀鈍化、退刀量不足、未執行去毛邊','O'=>3,
   'prev'=>'依刀具壽命換刀、SOP明訂去毛邊作業','det'=>'目視／觸感檢查（全檢）','D'=>5,
   'act'=>"1.SOP增列去毛邊後全檢\n2.毛邊異常時立即檢視刀具",'nS'=>5,'nO'=>2,'nD'=>3],
  ['func'=>'異常操作防範','req'=>'插齒刀不得與工件肩部或夾具干涉','fm'=>'插齒刀撞擊肩部或夾具 (行程過長)',
   'effect'=>'刀具崩裂、工件報廢、機台受損甚至危害人員安全','S'=>9,
   'cause'=>'行程設定錯誤、肩部退刀空間不足、換型後未重新確認行程','O'=>2,
   'prev'=>'加工前以刀具干涉模擬確認退刀空間、機台設定軟限位','det'=>'首件空跑確認行程、機台限位保護','D'=>2,
   'act'=>"1.新料號加工前以干涉模擬確認退刀量並留存\n2.換型後首件必須空跑確認",'nS'=>9,'nO'=>1,'nD'=>2],
];
// PFMEA 填寫原則自我檢查（寫入前先驗，不合格直接中止）
foreach ($SEED as $r) {
    $rpn = $r['S']*$r['O']*$r['D']; $nr = $r['nS']*$r['nO']*$r['nD'];
    $bad = [];
    if ($nr >= $rpn) $bad[] = '改善後RPN未降低';
    if (($r['O'] > 7 || $r['D'] > 7) && ($r['nO'] > 3 || $r['nD'] > 3)) $bad[] = 'O/D>7改善後須≦3';
    if ($r['S'] > 9 && ($r['nO'] > 2 || $r['nD'] > 2)) $bad[] = 'S>9須O,D≦2';
    foreach (['S','O','D','nS','nO','nD'] as $k) if ($r[$k] < 1 || $r[$k] > 10) $bad[] = "$k 超出 1~10";
    if ($bad) exit("種子資料不符填寫原則：{$r['fm']}：" . implode('、', $bad) . "\n");
}
echo "  種子 ", count($SEED), " 筆，皆通過填寫原則檢查\n";

$ctlWanted = ['prevention' => [], 'detection' => [], 'action' => []];
foreach ($SEED as $r) {
    $ctlWanted['prevention'][] = $r['prev'];
    $ctlWanted['detection'][]  = $r['det'];
    foreach (preg_split('/\n/', $r['act']) as $a) $ctlWanted['action'][] = trim($a);
}

if ($RUN) {
    $db->beginTransaction();
    try {
        foreach (['5', '154'] as $code) {
            $p = $pid($code);
            if (!$p) { echo "  找不到 process_code=$code，略過\n"; continue; }
            $itemId = pfmea_ref_item_option_get_or_add($db, $p, $ITEM, $UID, $UNAME);
            $funcIds = [];
            foreach ($SEED as $r) {
                if (!isset($funcIds[$r['func']])) {
                    $funcIds[$r['func']] = pfmea_ref_function_option_get_or_add($db, $itemId, $r['func'], $UID, $UNAME);
                    pfmea_ref_requirement_option_add($db, $funcIds[$r['func']], 0, '', $r['req'], $UID, $UNAME, $p, $itemId);
                }
                $fid = $funcIds[$r['func']];
                // 失效模式＋後果＋原因（專屬於這一筆失效模式）
                $fmId = pfmea_ref_failure_mode_add($db, $p, $r['fm'], $UID, $UNAME, $itemId, $fid);
                pfmea_field_link_add($db, 'failure_mode', $r['fm'], 'failure_effect', $r['effect'], $UID, $UNAME, $fmId);
                pfmea_field_link_add($db, 'failure_mode', $r['fm'], 'failure_cause', $r['cause'], $UID, $UNAME, $fmId);
                // 整組樣板
                $data = ['item_name'=>$ITEM, 'function_desc'=>$r['func'], 'requirement'=>$r['req'], 'failure_mode'=>$r['fm'],
                         'failure_effect'=>$r['effect'], 'severity'=>$r['S'], 'failure_cause'=>$r['cause'], 'occurrence'=>$r['O'],
                         'prevention_controls'=>$r['prev'], 'detection_controls'=>$r['det'], 'detection'=>$r['D'],
                         'recommended_actions'=>$r['act'], 'new_severity'=>$r['nS'], 'new_occurrence'=>$r['nO'], 'new_detection'=>$r['nD']];
                $ex = $db->prepare("SELECT id FROM pfmea_item_template WHERE process_id=? AND item_name=? AND failure_mode=? AND is_active=1 LIMIT 1");
                $ex->execute([$p, $ITEM, $r['fm']]);
                if (!$ex->fetchColumn()) {
                    $tid = pfmea_ref_item_template_save($db, 0, $p, $data, $UID, $UNAME);
                    $db->prepare("UPDATE pfmea_item_template SET source_tag='AI' WHERE id=?")->execute([$tid]);
                }
                // 評價S/O/D建議規則（新增分析列時自動帶評價值）
                pfmea_rating_rule_add($db, $p, $itemId, $fid, $r['fm'], $r['effect'], $r['S'], $r['cause'], $r['nS'], $r['nO'], $r['nD'], $UID, $UNAME);
            }
        }
        // 控制預防／控制偵測／建議措施 下拉清單
        foreach ($ctlWanted as $type => $texts) {
            foreach (array_unique($texts) as $t) if ($t !== '') {
                $cid = pfmea_ref_control_option_add($db, $type, $t, $UID, $UNAME);
                $db->prepare("UPDATE pfmea_control_option SET is_active=1 WHERE id=?")->execute([$cid]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        exit("寫入失敗已回復：" . $e->getMessage() . "\n");
    }
    echo "  已建立（插齒 process_no 5／154 各 ", count($SEED), " 組樣板）\n";
} else {
    echo "  （試算，未寫入；樣板 ", count($SEED), " 組 × 插齒 2 個製程小類）\n";
}

/* ---------- 4) 全站共用清單的收尾 ---------- */
echo "\n[4] 全站共用清單收尾\n";
// 4a 分類樣板：分類改由系統依嚴重度／發生率自動判定，不再人工挑選
$deact('pfmea_control_option', "option_type='classification'");
// 4b 控制預防／偵測／措施清單：只留「稽核製程樣板或既有分析表有用到」的字串
$used = [];
foreach ($db->query("SELECT t.prevention_controls p, t.detection_controls d, t.recommended_actions a
                       FROM pfmea_item_template t JOIN pfmea_process pr ON pr.id=t.process_id
                      WHERE t.is_active=1 AND pr.is_enabled=1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    foreach ($r as $v) $used[] = (string)$v;
}
foreach ($db->query("SELECT prevention_controls p, detection_controls d, recommended_actions a FROM pfmea_item")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    foreach ($r as $v) $used[] = (string)$v;
}
$blob = "\n" . implode("\n", $used) . "\n";
$dropIds = [];
foreach ($db->query("SELECT id, option_type, option_text FROM pfmea_control_option WHERE is_active=1 AND option_type IN ('prevention','detection','action')")->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $txt = trim((string)$o['option_text']);
    $kept = $txt !== '' && mb_strpos($blob, $txt) !== false;
    if (!$kept && !$RUN && in_array($txt, $ctlWanted[$o['option_type']] ?? [], true)) $kept = true;   // 試算時還沒寫入的新種子視為保留
    if (!$kept) $dropIds[] = (int)$o['id'];
}
echo sprintf("  %-28s 停用 %d 筆（沒有被稽核製程樣板或既有分析表使用）\n", 'pfmea_control_option(其餘)', count($dropIds));
if ($dropIds) {
    $undo['pfmea_control_option'] = array_merge($undo['pfmea_control_option'] ?? [], $dropIds);
    if ($RUN) $db->exec("UPDATE pfmea_control_option SET is_active=0 WHERE id IN (" . implode(',', $dropIds) . ")");
}
// 4c 全站共用的舊文字對應（失效模式→後果/原因，scope_fm_id IS NULL）：失效模式文字已不在任何啟用中的稽核製程失效模式裡就停用
$keepTexts = $db->query("SELECT DISTINCT f.failure_mode FROM pfmea_process_failure_mode f JOIN pfmea_process p ON p.id=f.process_id WHERE f.is_active=1 AND p.is_enabled=1")->fetchAll(PDO::FETCH_COLUMN);
$kph = $keepTexts ? implode(',', array_fill(0, count($keepTexts), '?')) : "''";
$deact('pfmea_field_link', "scope_fm_id IS NULL AND source_field='failure_mode' AND source_value NOT IN ($kph)", $keepTexts ?: []);

if ($RUN) {
    // 合併既有 undo（可重複執行時保留先前紀錄）
    $old = json_decode((string)@file_get_contents($undoFile), true);
    if (is_array($old)) foreach ($old as $t => $ids) $undo[$t] = array_values(array_unique(array_merge($ids, $undo[$t] ?? [])));
    file_put_contents($undoFile, json_encode($undo));
    echo "\n已寫入。還原停用：php views/TD/migrations/2026-10-14_pfmea_audit_ref_reset.php --undo\n";
} else {
    echo "\n（試算結束，加 --run 才會寫入）\n";
}
