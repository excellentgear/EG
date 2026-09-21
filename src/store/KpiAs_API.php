<?php
/**
 * AS9100 關鍵績效指標 KPI 頁 API（檢視/重算/填寫/覆寫/佐證附件）
 * 權限：kpi_as_lib.php kpi_as_perms()（roles module='kpi' ∪ 部門×主管階級規則 ∪ 指定人員），fail-closed
 * 舊年度鎖：隔年2/1起重算/覆寫/補填僅管理者(kpi_as_can_modify)
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/kpi_as_lib.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    kpi_as_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = kpi_as_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$perms = kpi_as_perms($db, $u);
if (!$perms['canView']) jerr('無KPI檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$curY = (int)date('Y');
$curM = (int)date('n');

/** 某年某月是否已整月結束 */
function kpi_month_ended(int $y, int $m): bool {
    return strtotime(date('Y-m-t', mktime(0,0,0,$m,1,$y)) . ' 23:59:59') < time();
}
/** 載入某年度全部指標(含年度版本) */
function kpi_load_iy(PDO $db, int $year): array {
    kpi_as_ensure_year($db, $year);
    $st = $db->prepare("SELECT i.indicator_id, i.item_no, i.name, i.clause, i.stat_desc, i.freq, i.value_type,
                               y.iy_id, y.owner_user_id, y.owner_display, y.source_mode, y.calculator_key,
                               y.params_json, y.target_direction, y.target_value, y.target_unit, y.target_text, y.is_active
                        FROM kpi_as_indicator i
                        JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                        WHERE i.is_active=1 AND y.is_active=1
                        ORDER BY i.sort_order, i.item_no");
    $st->execute([$year]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function kpi_load_mv(PDO $db, int $year): array {
    $st = $db->prepare("SELECT * FROM kpi_as_monthly_value WHERE year=?");
    $st->execute([$year]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['indicator_id']][(int)$r['month']] = $r;
    return $map;
}
function kpi_get_iy_row(PDO $db, int $iid, int $year): ?array {
    $st = $db->prepare("SELECT i.indicator_id, i.item_no, i.name, i.freq, i.value_type,
                               y.owner_user_id, y.source_mode, y.calculator_key, y.params_json,
                               y.target_direction, y.target_value
                        FROM kpi_as_indicator i
                        JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                        WHERE i.indicator_id=? AND i.is_active=1 AND y.is_active=1");
    $st->execute([$year, $iid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function kpi_excl_resettle_year(PDO $db, array $iy, int $year, array $u): int {
    $n = 0;
    foreach (kpi_as_months((string)$iy['freq']) as $m) {
        // 只結算「已經結束的月份」：當月本來就是每次開頁面即時試算，
        // 在這裡寫進快照會讓當月多出一筆跟別處規則不一樣的資料
        if (!kpi_month_ended($year, $m)) continue;
        kpi_as_settle($db, $iy, $year, $m, $u);
        $n++;
    }
    return $n;
}

/**
 * 規則改動之後要重算哪些年度。
 * scope=year → 只有這一年；scope=all → 這個指標有資料的每一個年度都要重算，
 * 否則畫面上會出現「規則說是所有年度，別的年度卻還是舊數字」這種看不出原因的落差。
 * 已結案鎖定的年度**只有 KPI 管理者能改**，沒權限就跳過並回報，不可以偷偷改掉封存的數字。
 * 回傳 ['done'=>[年=>格數], 'skipped'=>[年,...]]
 */
function kpi_excl_resettle_scope(PDO $db, int $iid, int $year, string $scope, array $u, array $perms, int $uid): array {
    $years = ($scope === 'all') ? kpi_as_years($db) : [$year];
    $done = []; $skipped = [];
    foreach ($years as $y) {
        $iy = kpi_get_iy_row($db, $iid, (int)$y);
        if (!$iy || $iy['source_mode'] !== 'auto') continue;
        if (!kpi_as_can_modify((int)$y, $perms, ((int)$iy['owner_user_id'] === $uid))) { $skipped[] = (int)$y; continue; }
        $done[(int)$y] = kpi_excl_resettle_year($db, $iy, (int)$y, $u);
    }
    return ['done'=>$done, 'skipped'=>$skipped];
}

switch ($action) {

/* ---------- 基本資訊 ---------- */
case 'meta': {
    $years = kpi_as_years($db);
    // 本人可填/可傳附件的指標：僅擔當者本人、其請假代理人；系統管理者=全部（RBAC鐵律）
    $year = kpi_as_year_pick($db, $_GET['year'] ?? $curY);
    kpi_as_ensure_year($db, $year);
    $st = $db->prepare("SELECT i.indicator_id, i.item_no, i.name, i.freq, y.source_mode, y.year, y.owner_user_id
                        FROM kpi_as_indicator i
                        JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id
                        WHERE i.is_active=1 AND y.is_active=1 AND y.year=? ORDER BY i.item_no");
    $st->execute([$year]);
    $mine = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ownerId = (int)$r['owner_user_id'];
        if (!($perms['isAdmin'] || $ownerId === $uid || kpi_as_is_delegate_of_owner($db, $ownerId, $uid))) continue;
        $r['months'] = kpi_as_valid_months($r);
        unset($r['owner_user_id']);
        $mine[] = $r;
    }
    $asDoc = eg_asdoc_get($db, 'kpi_as');
    jout(['perms'=>$perms, 'uid'=>$uid, 'cname'=>$u['user_cname'], 'years'=>$years,
          'cur_year'=>$curY, 'cur_month'=>$curM,
          'attach_max'=>kpi_as_attach_max($db),
          'year_locked'=>kpi_as_year_locked($year),
          'my_indicators'=>$mine,
          'company'=>eg_company_full_name($db),
          'as_doc'=>$asDoc, 'as_doc_no'=>eg_asdoc_no($asDoc)]);
}

/* ---------- 年度矩陣（含懶惰結算＋當月即時試算） ---------- */
case 'matrix': {
    $year = kpi_as_year_pick($db, $_GET['year'] ?? $curY);
    $iys = kpi_load_iy($db, $year);
    $mvs = kpi_load_mv($db, $year);
    // 附件數
    $st = $db->prepare("SELECT indicator_id, month, COUNT(*) c FROM kpi_as_attachment WHERE year=? GROUP BY indicator_id, month");
    $st->execute([$year]);
    $atts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $atts[(int)$r['indicator_id']][(int)$r['month']] = (int)$r['c'];
    // 去年快照(算去年平均)
    $pmvs = kpi_load_mv($db, $year - 1);

    $rows = [];
    foreach ($iys as $iy) {
        $iid = (int)$iy['indicator_id'];
        $months = kpi_as_valid_months($iy);
        $params = kpi_as_params($iy['params_json']);
        $cells = []; $vals = [];
        // 手動填寫指標：一個期間(季/半年/年)只能擇一月份填寫，先算出各期間目前是哪個月份填了資料，供逐月建格時判斷是否鎖定
        $groupFilledMonth = [];
        if ($iy['source_mode'] === 'manual') {
            foreach ($months as $m) {
                $gkey = kpi_as_period_group($iy['freq'], $m)[0];
                if (!array_key_exists($gkey, $groupFilledMonth)) $groupFilledMonth[$gkey] = null;
                $mv0 = $mvs[$iid][$m] ?? null;
                if ($mv0 && ($mv0['manual_value'] !== null || $mv0['override_value'] !== null)) $groupFilledMonth[$gkey] = $m;
            }
        }
        foreach ($months as $m) {
            $mv = $mvs[$iid][$m] ?? null;
            $src = 'none'; $val = null; $preview = false;
            // 懶惰結算：本年度、auto、月份已結束、尚無自動快照 → 現場結算一次
            if ($year === $curY && $iy['source_mode'] === 'auto' && kpi_month_ended($year, $m)
                && (!$mv || $mv['computed_at'] === null)) {
                kpi_as_settle($db, $iy, $year, $m, $u);
                $st2 = $db->prepare("SELECT * FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month=?");
                $st2->execute([$iid, $year, $m]);
                $mv = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $val = kpi_as_display_value($mv);
            if ($mv) {
                if ($mv['override_value'] !== null) $src = 'override';
                elseif ($mv['manual_value'] !== null) $src = 'manual';
                elseif ($mv['auto_value'] !== null) $src = 'auto';
            }
            // 當月(未結束)：auto 給即時試算值，不入快照
            if ($year === $curY && !kpi_month_ended($year, $m) && $m <= $curM
                && $iy['source_mode'] === 'auto' && $src !== 'override' && $src !== 'manual') {
                $res = kpi_as_compute_iy($db, $iy, $year, $m, $params);
                if ($res !== null) { $val = $res['value']; $src = $val === null ? 'none' : 'preview'; $preview = true; }
            }
            $future = ($year === $curY && $m > $curM) || $year > $curY;
            $lockedMonth = null;
            if ($iy['source_mode'] === 'manual') {
                $gkey = kpi_as_period_group($iy['freq'], $m)[0];
                $fm = $groupFilledMonth[$gkey] ?? null;
                if ($fm !== null && $fm !== $m) $lockedMonth = $fm;
            }
            if ($val !== null && !$preview) $vals[] = $val;
            $cells[$m] = [
                'v' => $val === null ? null : round($val, 2),
                'src' => $src,
                'future' => $future,
                'locked_month' => $lockedMonth,
                'below' => kpi_as_below_target($val, $iy),
                'num' => $mv['numerator'] ?? null,
                'den' => $mv['denominator'] ?? null,
                'attach' => $atts[$iid][$m] ?? 0,
                'filled_by' => $mv['filled_by_name'] ?? null,
                'filled_at' => $mv['filled_at'] ?? null,
                'ov_by' => $mv['override_by_name'] ?? null,
                'ov_at' => $mv['override_at'] ?? null,
                'ov_reason' => $mv['override_reason'] ?? null,
                'auto_v' => isset($mv['auto_value']) && $mv['auto_value'] !== null ? round((float)$mv['auto_value'], 2) : null,
                'computed_at' => $mv['computed_at'] ?? null,
                'note' => $mv['note'] ?? null,
            ];
        }
        // 平均（yesno 由前端顯示 x/y；其餘取已定案值平均）
        $avg = null;
        if ($iy['value_type'] !== 'yesno' && $vals) $avg = round(array_sum($vals) / count($vals), 2);
        // 去年平均
        $pvals = [];
        foreach (kpi_as_valid_months($iy) as $m) {
            $pv = kpi_as_display_value($pmvs[$iid][$m] ?? null);
            if ($pv !== null) $pvals[] = $pv;
        }
        $prevAvg = ($iy['value_type'] !== 'yesno' && $pvals) ? round(array_sum($pvals) / count($pvals), 2) : null;

        // 前端試算參數（fe=1）
        $exposed = [];
        $reg = kpi_as_registry();
        if ($iy['source_mode'] === 'auto' && isset($reg[$iy['calculator_key']])) {
            foreach ($reg[$iy['calculator_key']]['params'] as $pm) {
                $pv = $params[$pm['key']] ?? null;
                $fe = is_array($pv) && !empty($pv['fe']);
                if ($fe && !empty($pm['fe'])) {
                    $exposed[] = ['key'=>$pm['key'], 'label'=>$pm['label'], 'type'=>$pm['type'],
                                  'value'=>is_array($pv) && array_key_exists('v', $pv) ? $pv['v'] : $pv];
                }
            }
        }
        $srcInfo = kpi_as_source_info($db, $iy['source_mode'], $iy['calculator_key'], $params, $uid);
        $rows[] = [
            'indicator_id'=>$iid, 'item_no'=>(int)$iy['item_no'], 'name'=>$iy['name'],
            'clause'=>$iy['clause'], 'stat_desc'=>$iy['stat_desc'], 'freq'=>$iy['freq'],
            'value_type'=>$iy['value_type'], 'owner'=>$iy['owner_display'],
            'owner_user_id'=>(int)$iy['owner_user_id'],
            'is_owner'=>((int)$iy['owner_user_id'] === $uid),
            'source_mode'=>$iy['source_mode'], 'calculator_key'=>$iy['calculator_key'],
            'source_info'=>$srcInfo,
            'target'=>['dir'=>$iy['target_direction'], 'value'=>$iy['target_value'],
                       'unit'=>$iy['target_unit'], 'text'=>$iy['target_text']],
            'months'=>$months, 'cells'=>$cells, 'avg'=>$avg, 'prev_avg'=>$prevAvg,
            'exposed_params'=>$exposed,
            'can_recalc'=>$iy['source_mode'] === 'auto' && kpi_as_can_modify($year, $perms, ((int)$iy['owner_user_id'] === $uid)),
            'can_fill'=>$iy['source_mode'] === 'manual' && kpi_as_can_override($db, $year, $perms, (int)$iy['owner_user_id'], $uid),
            'can_override'=>kpi_as_can_override($db, $year, $perms, (int)$iy['owner_user_id'], $uid),
        ];
    }
    jout(['year'=>$year, 'rows'=>$rows, 'year_locked'=>kpi_as_year_locked($year),
          'can_admin'=>$perms['canAdmin'], 'is_admin'=>!empty($perms['isAdmin']) ? 1 : 0,
          'attach_max'=>kpi_as_attach_max($db)]);
}

/* ---------- 重算（快照）：本年=擔當者/填報/管理者；舊年度僅管理者 ---------- */
case 'recalc': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0); // 0=全年已結束月份
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if ($iy['source_mode'] !== 'auto') jerr('手動填寫指標不提供重算');
    $isOwner = ((int)$iy['owner_user_id'] === $uid);
    if (!kpi_as_can_modify($year, $perms, $isOwner)) jerr('此年度已鎖定，僅管理者可重新計算', 403);
    $months = $month > 0 ? [$month] : kpi_as_months($iy['freq']);
    $done = [];
    foreach ($months as $m) {
        if (!in_array($m, kpi_as_months($iy['freq']), true)) continue;
        if (!kpi_month_ended($year, $m)) continue; // 未結束月份只做即時試算不入快照
        kpi_as_settle($db, array_merge($iy, ['indicator_id'=>$iid]), $year, $m, $u);
        $done[] = $m;
    }
    kpi_as_log($db, $iid, $year, $month ?: null, 'recalc', null, null,
               implode(',', $done), '重新計算月份：' . (implode(',', $done) ?: '無'), $u);
    jout(['recalced'=>$done]);
}

/* ---------- 快照過期掃描（畫面載入後非同步呼叫；使用者要求 2026-09-14） ----------
   自動指標的值是快照，來源資料事後補登不會重算也不會提示（例：4~7月教育訓練場次
   是快照寫完之後才補建的，畫面就一直顯示「?」）。這裡比對「快照 computed_at」與
   「來源資料表最後異動時間」，過期就重算，並回報哪幾格的值真的變了。
   寫入條件比照既有懶惰結算＝系統自動維護，不看個人權限；但**已鎖定年度只標示不寫入**
   （隔年2/1起僅管理者可動，見 kpi_as_year_locked）。 */
case 'stale_scan': {
    $year = kpi_as_year_pick($db, $_POST['year'] ?? $_GET['year'] ?? $curY);
    // 已鎖定年度（隔年2/1起）一律只標示、不自動寫入——管理者也一樣。
    // 那是已經結案的品質紀錄，要不要跟著新資料改，必須由人按「重算」決定。
    $canWrite = !kpi_as_year_locked($year);
    $iys = kpi_load_iy($db, $year);
    // 一次把整年的快照撈回來（原本逐格 SELECT＝每次開頁多兩百次來回）
    $mvAll = [];
    $stAll = $db->prepare("SELECT indicator_id, month, auto_value, override_value, manual_value, computed_at
                           FROM kpi_as_monthly_value WHERE year=?");
    $stAll->execute([$year]);
    foreach ($stAll->fetchAll(PDO::FETCH_ASSOC) as $r) $mvAll[(int)$r['indicator_id']][(int)$r['month']] = $r;
    $cap = 60;                 // 單次最多重算幾格，避免背景掃描把頁面拖慢
    $checked = 0; $done = 0; $truncated = false;
    $updated = [];             // 值真的變了（已重算並寫回）
    $stale   = [];             // 已過期但沒寫回（鎖定年度）
    foreach ($iys as $iy) {
        if ($iy['source_mode'] !== 'auto' || empty($iy['calculator_key'])) continue;
        $iid = (int)$iy['indicator_id'];
        $params = kpi_as_params($iy['params_json']);
        $mtime = kpi_as_tables_mtime($db, kpi_as_source_tables($db, (string)$iy['calculator_key'], $params));
        if ($mtime === null) continue;   // 判不出來源異動時間就不做過期判定（不亂標）
        foreach (kpi_as_months($iy['freq']) as $m) {
            if (!kpi_month_ended($year, $m)) continue;   // 未結束月份本來就即時試算
            $mv = $mvAll[$iid][$m] ?? null;
            if (!$mv || $mv['computed_at'] === null) continue;  // 沒快照的交給既有懶惰結算
            if (!kpi_as_snapshot_stale($mv['computed_at'], $mtime)) continue;
            $checked++;
            if ($done >= $cap) { $truncated = true; continue; }
            $done++;
            $old = $mv['auto_value'] === null ? null : round((float)$mv['auto_value'], 2);
            if ($canWrite) {
                $res = kpi_as_settle($db, $iy, $year, $m, $u);
                $new = ($res && $res['value'] !== null) ? round((float)$res['value'], 2) : null;
            } else {
                $res = kpi_as_compute_iy($db, $iy, $year, $m, $params);
                $new = ($res && $res['value'] !== null) ? round((float)$res['value'], 2) : null;
            }
            if ($new === $old) continue;                 // 只是來源動過但結果一樣＝不吵使用者
            $row = ['indicator_id'=>$iid, 'item_no'=>(int)$iy['item_no'], 'name'=>$iy['name'],
                    'month'=>$m, 'old'=>$old, 'new'=>$new,
                    'num'=>$res['num'] ?? null, 'den'=>$res['den'] ?? null,
                    'old_at'=>$mv['computed_at'], 'src_at'=>$mtime,
                    'shadowed'=>($mv['override_value'] !== null || $mv['manual_value'] !== null) ? 1 : 0];
            if ($canWrite) {
                $updated[] = $row;
                kpi_as_log($db, $iid, $year, $m, 'auto_recalc', 'auto_value', $old, $new,
                           '快照過期自動重算（來源最後異動 ' . $mtime . '，原快照 ' . $mv['computed_at'] . '）', $u);
            } else {
                $stale[] = $row;
            }
        }
    }
    jout(['year'=>$year, 'checked'=>$checked, 'recalced'=>$done, 'truncated'=>$truncated,
          'can_write'=>$canWrite ? 1 : 0, 'updated'=>$updated, 'stale'=>$stale]);
}

/* ---------- 前端試算（不入快照；僅 fe=1 參數可調，管理者不受限） ---------- */
case 'preview': {
    $iid = (int)($_GET['indicator_id'] ?? 0);
    $year = kpi_as_year_pick($db, $_GET['year'] ?? $curY);
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if ($iy['source_mode'] !== 'auto') jerr('手動指標無法試算');
    $params = kpi_as_params($iy['params_json']);
    $ov = json_decode((string)($_GET['params'] ?? '{}'), true);
    if (is_array($ov)) {
        foreach ($ov as $k => $v) {
            $cur = $params[$k] ?? null;
            $fe = is_array($cur) && !empty($cur['fe']);
            if ($fe || $perms['canAdmin']) {
                $params[$k] = ['v'=>$v, 'fe'=>$fe ? 1 : 0];
            }
        }
    }
    $out = [];
    foreach (kpi_as_months($iy['freq']) as $m) {
        if (($year === $curY && $m > $curM) || $year > $curY) { $out[$m] = null; continue; }
        $res = kpi_as_compute_iy($db, $iy, $year, $m, $params);
        $out[$m] = ($res && $res['value'] !== null)
            ? ['v'=>round($res['value'], 2), 'num'=>$res['num'], 'den'=>$res['den']] : null;
    }
    jout(['months'=>$out]);
}

/* ---------- 套用試算：把調整後的開放參數寫回本年度設定（僅管理者） ---------- */
case 'apply_params': {
    if (!$perms['canAdmin']) jerr('僅KPI管理者可套用修改本年度設定', 403);
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if ($iy['source_mode'] !== 'auto') jerr('手動指標無參數可套用');
    $params = kpi_as_params($iy['params_json']);
    $ov = json_decode((string)($_POST['params'] ?? '{}'), true);
    if (!is_array($ov)) jerr('參數格式錯誤');
    $changed = [];
    foreach ($ov as $k => $v) {
        $cur = $params[$k] ?? null;
        $fe = is_array($cur) && !empty($cur['fe']);
        if (!$fe) continue; // 只允許套用「開放前端試算」的參數
        $oldV = is_array($cur) && array_key_exists('v', $cur) ? $cur['v'] : null;
        $params[$k] = ['v'=>$v, 'fe'=>1];
        if (json_encode($oldV) !== json_encode($v)) $changed[$k] = ['old'=>$oldV, 'new'=>$v];
    }
    if (!$changed) jout(['changed'=>0]);
    $st = $db->prepare("UPDATE kpi_as_indicator_year SET params_json=?, Modified_By=?, Modified_At=NOW() WHERE indicator_id=? AND year=?");
    $st->execute([json_encode($params, JSON_UNESCAPED_UNICODE), $u['user_cname'], $iid, $year]);
    kpi_as_log($db, $iid, $year, null, 'apply_params', null, null, json_encode($changed, JSON_UNESCAPED_UNICODE), '主頁試算套用', $u);
    // 重算本年度已結束月份，讓套用即時反映
    for ($m = 1; $m <= 12; $m++) {
        if (!in_array($m, kpi_as_months($iy['freq']), true)) continue;
        if (!kpi_month_ended($year, $m)) continue;
        kpi_as_settle($db, array_merge($iy, ['indicator_id'=>$iid]), $year, $m, $u);
    }
    jout(['changed'=>count($changed)]);
}

/* ---------- 不符合標準的明細（使用者要求 2026-09-15：只列超過規定的，並附修改建議） ----------
   回傳這一格「哪幾筆沒達到標準」＋每一筆該怎麼處理；
   mode=allow → 去來源頁面改真實資料；mode=deny → 只能排除這一筆（不動真實資料）。 */
case 'detail_rows': {
    $iid   = (int)($_GET['indicator_id'] ?? 0);
    $year  = kpi_as_year_pick($db, $_GET['year'] ?? $curY);
    $month = max(1, min(12, (int)($_GET['month'] ?? 1)));
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    $em = kpi_as_edit_mode($db, $iid, $calc);
    if ($iy['source_mode'] !== 'auto' || !kpi_as_detail_supported($calc)) {
        jout(['supported'=>0, 'mode'=>$em['mode'], 'why'=>$em['why'], 'rows'=>[], 'cols'=>[],
              'links'=>kpi_as_source_links($db, $uid, $calc),
              'msg'=>$iy['source_mode'] !== 'auto' ? '手動填寫的指標沒有來源明細。'
                                                   : '這個指標還沒有做「不符合標準的明細」。']);
    }
    $params = kpi_as_params($iy['params_json']);
    $exRules = kpi_as_excl_rules($db, $iid, $year);
    $d = kpi_as_detail($db, $calc, $year, $month, $params, $exRules, $iy);
    // 已排除的仍然列出來（灰底標「已排除」），否則使用者排掉之後就再也看不到、也解不開
    $adj = [];
    foreach (kpi_as_adjust_rows($db, $iid, $year, $month) as $a) $adj[(string)$a['row_key']] = $a;
    $rows = [];
    $cap = 500;
    // 截斷前一定要先把「不符合標準」的列排到最前面：
    // 組成明細型的指標（銷貨額／接單金額）正常資料有好幾百筆，照原順序切 500 筆
    // 會把真正有問題的那幾筆整批切掉，畫面上看起來就像「有 109 筆卻一筆都看不到」。
    $ordered = [];
    foreach (['bad', 'warn', 'info'] as $kk) {
        foreach ($d['rows'] as $r) if ((string)($r['kind'] ?? 'bad') === $kk) $ordered[] = $r;
    }
    foreach ($ordered as $r) {
        if (count($rows) >= $cap) break;
        $k = (string)$r['key'];
        $r['excluded']  = isset($adj[$k]) ? 1 : 0;
        $r['ex_reason'] = isset($adj[$k]) ? (string)$adj[$k]['reason'] : '';
        $r['ex_by']     = isset($adj[$k]) ? (string)$adj[$k]['created_by_name'] : '';
        $r['ex_at']     = isset($adj[$k]) ? (string)$adj[$k]['created_at'] : '';
        $rows[] = $r;
    }
    // 可改真實資料的指標：把可編輯欄位與每一列目前的值一併帶出來（欄位定義來自程式碼白名單）
    $editFields = []; $canEdit = 0;
    $spec = kpi_as_detail_edit_spec($calc);
    if ($em['mode'] === 'allow' && $spec) {
        $canEdit = kpi_as_can_modify($year, $perms, ((int)$iy['owner_user_id'] === $uid)) ? 1 : 0;
        foreach ($spec['fields'] as $f) {
            $opts = [];
            foreach (($f['opts'] ?? []) as $k => $t) $opts[] = ['v'=>(string)$k, 't'=>$t];
            $editFields[] = ['k'=>$f['k'], 't'=>$f['t'], 'type'=>$f['type'],
                             'opts'=>$opts, 'hint'=>$f['hint'] ?? ''];
        }
        if ($rows) {
            $keys = array_map(function ($r) { return $r['key']; }, $rows);
            $cols = array_map(function ($f) { return '`' . $f['k'] . '`'; }, $spec['fields']);
            $in = implode(',', array_fill(0, count($keys), '?'));
            try {
                $q = $db->prepare("SELECT `" . $spec['pk'] . "` AS __k, " . implode(',', $cols)
                                  . " FROM `" . $spec['table'] . "` WHERE `" . $spec['pk'] . "` IN ($in)");
                $q->execute($keys);
                $cur = [];
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                    $k = (string)$cr['__k']; unset($cr['__k']);
                    foreach ($cr as $ck => $cv) {
                        // 日期欄位可能是 datetime，<input type=date> 只吃 YYYY-MM-DD
                        $cur[$k][$ck] = ($cv === null) ? '' : substr((string)$cv, 0, 10);
                        foreach ($spec['fields'] as $f) {
                            if ($f['k'] === $ck && ($f['type'] ?? '') !== 'date') $cur[$k][$ck] = (string)$cv;
                        }
                    }
                }
                foreach ($rows as &$rr) { $rr['edit'] = $cur[(string)$rr['key']] ?? new stdClass(); }
                unset($rr);
            } catch (Throwable $e) { /* 取不到就不給編輯欄位，不影響清單本身 */ }
        }
    }
    jout(['supported'=>1, 'mode'=>$em['mode'], 'setting'=>$em['setting'], 'suggest'=>$em['suggest'],
          'edit_fields'=>$editFields, 'can_edit'=>$canEdit, 'warn'=>$d['warn'] ?? 0,
          'why'=>$em['why'], 'cols'=>$d['cols'], 'rows'=>$rows, 'total'=>$d['total'],
          'listed'=>count($d['rows']), 'rule_ex'=>$d['rule_ex'] ?? 0,
          'truncated'=>count($d['rows']) > $cap ? 1 : 0, 'note'=>$d['note'],
          // note_excl／param_excl 都是「排除」相關：畫面上要看得到（才不會重複設定），列印版一律不印
          'note_excl'=>$d['note_excl'] ?? '', 'param_excl'=>kpi_as_param_excl($calc, $params, $db),
          'note_print'=>$d['note_print'] ?? '',   // 列印版只印這一句（正式清單不寫內部判定過程）
          // 可以用來篩選／建立排除規則的維度：一律取自這一份明細真的有哪些值
          'dims'=>$d['dims'] ?? [], 'dim_labels'=>kpi_as_dim_labels(),
          'rules'=>kpi_as_excl_rule_rows($db, $iid, $year),
          'links'=>kpi_as_source_links($db, $uid, $calc),
          'can_adjust'=>kpi_as_can_modify($year, $perms, ((int)$iy['owner_user_id'] === $uid)) ? 1 : 0,
          'can_set_mode'=>$perms['canAdmin'] ? 1 : 0,
          'target'=>['dir'=>$iy['target_direction'], 'value'=>$iy['target_value'], 'text'=>$iy['target_text']]]);
}

/* ---------- 排除／取消排除某幾筆來源列（不修改真實資料） ---------- */
case 'adjust_add': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = max(1, min(12, (int)($_POST['month'] ?? 0)));
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    if ($iy['source_mode'] !== 'auto' || !kpi_as_detail_supported($calc)) jerr('這個指標不支援排除');
    // 訊息要分清楚是「年度鎖了」還是「你沒權限」——講錯的話使用者會一直去找管理者解鎖
    if (!kpi_as_can_modify($year, $perms, ((int)$iy['owner_user_id'] === $uid))) {
        jerr(kpi_as_year_locked($year) ? '此年度已結案鎖定，僅 KPI 管理者可調整'
                                       : '您沒有調整這個指標的權限（限擔當者本人、KPI 填報或 KPI 管理者）', 403);
    }
    // 原因非必填（使用者要求 2026-09-18）：誰排的、什麼時候排的本來就會留在
    // created_by／created_by_name／created_at，硬要人打字只是拖慢現場。
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (mb_strlen($reason) > 200) $reason = mb_substr($reason, 0, 200);
    $keys = json_decode((string)($_POST['keys'] ?? '[]'), true);
    $keys = is_array($keys) ? array_values(array_unique(array_filter(array_map('strval', $keys), 'strlen'))) : [];
    if (!$keys) jerr('請選擇要排除的項目');

    // 只准排除「這一格真的算得到、而且確實沒達到標準」的那幾筆（前端擋一次，後端同規則再擋＝鐵律8）
    $params = kpi_as_params($iy['params_json']);
    $d = kpi_as_detail($db, $calc, $year, $month, $params, kpi_as_excl_rules($db, $iid, $year), $iy);
    $valid = [];
    // 只有「真的不符合標準、而且沒有被規則排掉」的那幾筆可以逐筆排除
    foreach ($d['rows'] as $r) {
        if (!empty($r['rule_ex'])) continue;
        if ((string)($r['kind'] ?? 'bad') !== 'bad') continue;
        $valid[(string)$r['key']] = $r;
    }
    $ins = $db->prepare("INSERT INTO kpi_as_adjust
        (indicator_id,year,month,calculator_key,row_key,row_label,row_json,reason,created_by,created_by_name)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE reason=VALUES(reason), created_by=VALUES(created_by),
                                created_by_name=VALUES(created_by_name), created_at=NOW()");
    $done = 0; $skip = 0;
    $db->beginTransaction();
    try {
        foreach ($keys as $k) {
            if (!isset($valid[$k])) { $skip++; continue; }
            $r = $valid[$k];
            $ins->execute([$iid, $year, $month, $calc, $k,
                           mb_substr(implode(' ｜ ', $r['vals']), 0, 250),
                           json_encode($r, JSON_UNESCAPED_UNICODE), $reason,
                           (int)$u['id'], (string)$u['user_cname']]);
            $done++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('寫入失敗：' . $e->getMessage(), 500); }
    if (!$done) jerr('沒有可排除的項目（選到的資料已經不在這個月的違規清單內，請重新整理）');
    $res = kpi_as_settle($db, $iy, $year, $month, $u);   // 立刻重算這一格
    kpi_as_log($db, $iid, $year, $month, 'adjust_add', 'exclude', null, implode(',', $keys),
               '排除 ' . $done . ' 筆' . ($reason !== '' ? ('（原因：' . $reason . '）') : ''), $u);
    jout(['added'=>$done, 'skipped'=>$skip,
          'value'=>($res && $res['value'] !== null) ? round($res['value'], 2) : null,
          'num'=>$res['num'] ?? null, 'den'=>$res['den'] ?? null]);
}

case 'adjust_del': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = max(1, min(12, (int)($_POST['month'] ?? 0)));
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    // 訊息要分清楚是「年度鎖了」還是「你沒權限」——講錯的話使用者會一直去找管理者解鎖
    if (!kpi_as_can_modify($year, $perms, ((int)$iy['owner_user_id'] === $uid))) {
        jerr(kpi_as_year_locked($year) ? '此年度已結案鎖定，僅 KPI 管理者可調整'
                                       : '您沒有調整這個指標的權限（限擔當者本人、KPI 填報或 KPI 管理者）', 403);
    }
    $keys = json_decode((string)($_POST['keys'] ?? '[]'), true);
    $keys = is_array($keys) ? array_values(array_filter(array_map('strval', $keys), 'strlen')) : [];
    if (!$keys) jerr('請選擇要取消排除的項目');
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $db->prepare("DELETE FROM kpi_as_adjust WHERE indicator_id=? AND year=? AND month=? AND row_key IN ($in)");
    $st->execute(array_merge([$iid, $year, $month], $keys));
    $n = $st->rowCount();
    if (!$n) jerr('這幾筆本來就沒有被排除（請重新整理）');
    $res = kpi_as_settle($db, $iy, $year, $month, $u);
    kpi_as_log($db, $iid, $year, $month, 'adjust_del', 'exclude', implode(',', $keys), null,
               '取消排除 ' . $n . ' 筆', $u);
    jout(['removed'=>$n,
          'value'=>($res && $res['value'] !== null) ? round($res['value'], 2) : null,
          'num'=>$res['num'] ?? null, 'den'=>$res['den'] ?? null]);
}

/* ---------- 排除規則：整批排除特定客戶／料號／製程／廠商／機台（使用者要求 2026-09-17） ----------
   與逐筆排除（kpi_as_adjust）的差別：規則是「常設」的，這個指標整年度 12 個月一體適用，
   所以**一改就要把該年度所有已結算的月份重算一次**，否則畫面上會出現
   「規則加了、可是別的月份還是舊數字」這種完全看不出原因的落差。
   一樣只影響 KPI 計算，不會修改任何一筆真實資料。 */

/* ---------- 排除規則的候選查詢（使用者要求 2026-09-18：打一部分代號要列出清單讓人挑） ----------
   一律查「主檔」而不是只查這個月的明細：這個月沒出貨的客戶也要挑得到，
   而且回傳的值一律是**正式名稱**——規則比對的是名稱，把代號存成規則值永遠不會命中。 */
case 'excl_dim_search': {
    $iid  = (int)($_GET['indicator_id'] ?? 0);
    $year = kpi_as_year_pick($db, $_GET['year'] ?? $curY);
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    $dim  = trim((string)($_GET['dim'] ?? ''));
    if (!in_array($dim, kpi_as_calc_dims($calc), true)) jerr('這個指標沒有這種排除維度');
    $q = trim((string)($_GET['q'] ?? ''));
    // 沒打關鍵字＝只列「這個年度的資料裡真的有的」（使用者要求 2026-09-18）；
    // 這個年度沒有的要打關鍵字才從主檔搜出來，不然一開啟就是一整份跟本年度無關的主檔。
    if ($q === '') {
        jout(['dim'=>$dim, 'q'=>'', 'src'=>'本年度',
              'rows'=>kpi_as_dim_year_values($db, $calc, $dim, $year, 300)]);
    }
    jout(['dim'=>$dim, 'q'=>$q, 'src'=>'主檔', 'rows'=>kpi_as_dim_lookup($db, $dim, $q, 50)]);
}

case 'excl_rule_add': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    if ($iy['source_mode'] !== 'auto' || !kpi_as_detail_supported($calc)) jerr('這個指標不支援排除');
    if (!kpi_as_can_modify($year, $perms, ((int)$iy['owner_user_id'] === $uid))) {
        jerr(kpi_as_year_locked($year) ? '此年度已結案鎖定，僅 KPI 管理者可調整'
                                       : '您沒有調整這個指標的權限（限擔當者本人、KPI 填報或 KPI 管理者）', 403);
    }
    $scope = ((string)($_POST['scope'] ?? 'year') === 'all') ? 'all' : 'year';
    $rowYear = ($scope === 'all') ? 0 : $year;     // 全年度的那一列 year 一律寫 0
    $dim = trim((string)($_POST['dim'] ?? ''));
    // 維度代號一律取自程式碼白名單，不吃前端亂送的欄位（鐵律8：前端擋一次、後端同規則再擋一次）
    if (!in_array($dim, kpi_as_calc_dims($calc), true)) jerr('這個指標沒有這種排除維度');
    $vals = json_decode((string)($_POST['vals'] ?? '[]'), true);
    $vals = is_array($vals) ? array_values(array_unique(array_filter(array_map(function ($v) {
        return mb_substr(trim((string)$v), 0, 190);
    }, $vals), 'strlen'))) : [];
    if (!$vals) jerr('請選擇要排除的項目');
    if (count($vals) > 200) jerr('一次最多 200 項');
    // 指標設定（params）裡本來就排除掉的值不必再建一條規則：那幾筆資料根本不會進計算，
    // 建了只會在畫面上留一條永遠用不到的規則（使用者回報 2026-09-18）
    $already = [];
    foreach (kpi_as_param_excl($calc, kpi_as_params($iy['params_json']), $db) as $pe)
        if ($pe['dim'] === $dim) $already[$pe['val']] = 1;
    $dupe = array_values(array_filter($vals, function ($v) use ($already) { return isset($already[$v]); }));
    $vals = array_values(array_filter($vals, function ($v) use ($already) { return !isset($already[$v]); }));
    if (!$vals) jerr('這幾項在指標設定裡本來就已經排除了（' . implode('、', $dupe) . '），不必再建規則');
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);   // 非必填

    $ins = $db->prepare("INSERT INTO kpi_as_excl_rule (indicator_id,year,scope,dim,val,reason,created_by,created_by_name)
                         VALUES (?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE scope=VALUES(scope), reason=VALUES(reason),
                                                 created_by=VALUES(created_by),
                                                 created_by_name=VALUES(created_by_name), created_at=NOW()");
    // 建「所有年度」時，把同一個值原本各年度的規則收掉——留著只會在畫面上變成兩條意思一樣的規則
    $delNarrow = $db->prepare("DELETE FROM kpi_as_excl_rule
                               WHERE indicator_id=? AND dim=? AND val=? AND scope='year'");
    $db->beginTransaction();
    try {
        foreach ($vals as $v) {
            if ($scope === 'all') $delNarrow->execute([$iid, $dim, $v]);
            $ins->execute([$iid, $rowYear, $scope, $dim, $v, ($reason !== '' ? $reason : null),
                           (int)$u['id'], (string)$u['user_cname']]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('寫入失敗：' . $e->getMessage(), 500); }
    $rs = kpi_excl_resettle_scope($db, $iid, $year, $scope, $u, $perms, $uid);
    kpi_as_log($db, $iid, ($scope === 'all' ? null : $year), null, 'excl_rule_add', 'excl_' . $dim,
               null, implode(',', $vals),
               '新增排除規則 ' . count($vals) . ' 項（適用：' . ($scope === 'all' ? '所有年度' : ($year . ' 年度')) . '）'
               . ($reason !== '' ? ('（原因：' . $reason . '）') : ''), $u);
    jout(['added'=>count($vals), 'dupe'=>$dupe, 'scope'=>$scope,
          'recalced'=>array_sum($rs['done']), 'years'=>array_keys($rs['done']), 'skipped_years'=>$rs['skipped'],
          'rules'=>kpi_as_excl_rule_rows($db, $iid, $year)]);
}

case 'excl_rule_del': {
    $iid  = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if (!kpi_as_can_modify($year, $perms, ((int)$iy['owner_user_id'] === $uid))) {
        jerr(kpi_as_year_locked($year) ? '此年度已結案鎖定，僅 KPI 管理者可調整'
                                       : '您沒有調整這個指標的權限（限擔當者本人、KPI 填報或 KPI 管理者）', 403);
    }
    $ids = json_decode((string)($_POST['rule_ids'] ?? '[]'), true);
    $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    if (!$ids) jerr('請選擇要取消的規則');
    $in = implode(',', array_fill(0, count($ids), '?'));
    // 「所有年度」那一列的 year 是 0，所以不可以再用 year 過濾（rule_id 本來就唯一，
    // 另外比對 indicator_id 當守門，避免刪到別的指標的規則）
    $q = $db->prepare("SELECT scope FROM kpi_as_excl_rule WHERE indicator_id=? AND rule_id IN ($in)");
    $q->execute(array_merge([$iid], $ids));
    $scopes = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $scope = in_array('all', $scopes, true) ? 'all' : 'year';
    $st = $db->prepare("DELETE FROM kpi_as_excl_rule WHERE indicator_id=? AND rule_id IN ($in)");
    $st->execute(array_merge([$iid], $ids));
    $n = $st->rowCount();
    if (!$n) jerr('這幾條規則本來就不存在（請重新整理）');
    $rs = kpi_excl_resettle_scope($db, $iid, $year, $scope, $u, $perms, $uid);
    kpi_as_log($db, $iid, ($scope === 'all' ? null : $year), null, 'excl_rule_del', 'excl_rule',
               implode(',', $ids), null, '取消排除規則 ' . $n . ' 條', $u);
    jout(['removed'=>$n, 'recalced'=>array_sum($rs['done']), 'years'=>array_keys($rs['done']),
          'skipped_years'=>$rs['skipped'], 'rules'=>kpi_as_excl_rule_rows($db, $iid, $year)]);
}

/* ---------- 補登模式：整張表像 Excel 一樣直接填（使用者要求 2026-09-15） ----------
   補舊年度資料時，一格一格開跳窗、還要每格填覆寫原因，實務上根本填不完。
   這支端點讓**系統管理員**一次送整批值，寫成手動覆寫（override）但**不要求逐格原因**，
   改成整批寫同一句說明（誰在什麼時候補的照樣留在 override_by／override_at 與變更歷史）。
   限制：⑴僅系統管理員 ⑵只能補「已結束的月份」（未來月份沒有意義）
        ⑶值一律照該指標的 value_type 正規化 ⑷空字串＝清掉這一格的覆寫。 */
case 'bulk_override': {
    if (empty($perms['isAdmin'])) jerr('僅系統管理員可使用補登模式', 403);
    $year = (int)($_POST['year'] ?? 0);
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $cells = json_decode((string)($_POST['cells'] ?? '[]'), true);
    if (!is_array($cells) || !$cells) jerr('沒有要寫入的資料');
    if (count($cells) > 500) jerr('一次最多 500 格');
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
    if ($note === '') $note = '補登舊年度資料（補登模式整批填寫）';

    $iys = [];
    foreach (kpi_load_iy($db, $year) as $r) $iys[(int)$r['indicator_id']] = $r;

    $set = $db->prepare("INSERT INTO kpi_as_monthly_value
            (indicator_id,year,month,override_value,override_by,override_by_name,override_at,override_reason)
            VALUES (?,?,?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE override_value=VALUES(override_value), override_by=VALUES(override_by),
                    override_by_name=VALUES(override_by_name), override_at=NOW(), override_reason=VALUES(override_reason)");
    $clr = $db->prepare("UPDATE kpi_as_monthly_value
            SET override_value=NULL, override_by=NULL, override_by_name=NULL, override_at=NULL, override_reason=NULL
            WHERE indicator_id=? AND year=? AND month=?");
    $saved = 0; $cleared = 0; $skipped = [];
    $db->beginTransaction();
    try {
        foreach ($cells as $c) {
            $iid = (int)($c['i'] ?? 0);
            $m   = (int)($c['m'] ?? 0);
            $raw = trim((string)($c['v'] ?? ''));
            $iy  = $iys[$iid] ?? null;
            if (!$iy) { $skipped[] = "指標 $iid 不存在"; continue; }
            if (!in_array($m, kpi_as_valid_months($iy), true)) { $skipped[] = $iy['name'] . " {$m}月 不適用"; continue; }
            if (!kpi_month_ended($year, $m)) { $skipped[] = $iy['name'] . " {$m}月 尚未結束"; continue; }
            if ($raw === '') { $clr->execute([$iid, $year, $m]); $cleared += $clr->rowCount() ? 1 : 0; continue; }
            $val = kpi_as_parse_input((string)$iy['value_type'], $raw);
            if ($val === null) {
                $skipped[] = $iy['name'] . " {$m}月「{$raw}」無法辨識（" . kpi_as_input_hint((string)$iy['value_type']) . '）';
                continue;
            }
            $set->execute([$iid, $year, $m, $val, $uid, $u['user_cname'], $note]);
            $saved++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('寫入失敗：' . $e->getMessage(), 500); }

    kpi_as_log($db, null, $year, null, 'bulk_override', 'override_value', null,
               '寫入 ' . $saved . ' 格／清除 ' . $cleared . ' 格', $note, $u);
    jout(['saved'=>$saved, 'cleared'=>$cleared, 'skipped'=>$skipped]);
}

/* ---------- 直接修改來源資料（只限「可改真實資料」的指標；使用者要求 2026-09-15） ----------
   安全邊界：表名／主鍵／欄位／可選值一律取自程式碼裡的白名單（kpi_as_detail_edit_spec），
   請求端只送 row_key 與欄位代號；而且**一定要先確認那一筆真的出現在這一格的違規清單裡**，
   否則這支端點就會變成「可以改任何一列 bom_ing／order_track」的萬用編輯器（鐵律8）。 */
case 'src_edit': {
    $iid   = (int)($_POST['indicator_id'] ?? 0);
    $year  = (int)($_POST['year'] ?? 0);
    $month = max(1, min(12, (int)($_POST['month'] ?? 0)));
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    $calc = (string)$iy['calculator_key'];
    if ($iy['source_mode'] !== 'auto' || !kpi_as_detail_supported($calc)) jerr('這個指標不支援明細修改');

    $em = kpi_as_edit_mode($db, $iid, $calc);
    if ($em['mode'] !== 'allow') jerr('這個指標的來源資料不開放直接修改（' . $em['why'] . '），請改用「排除」', 403);
    if (!kpi_as_can_modify($year, $perms, ((int)$iy['owner_user_id'] === $uid))) {
        jerr(kpi_as_year_locked($year) ? '此年度已結案鎖定，僅 KPI 管理者可修改'
                                       : '您沒有修改這個指標來源資料的權限（限擔當者本人、KPI 填報或 KPI 管理者）', 403);
    }
    $spec = kpi_as_detail_edit_spec($calc);
    if (!$spec) jerr('這個指標沒有可修改的欄位');

    $rowKey = trim((string)($_POST['row_key'] ?? ''));
    $fieldK = trim((string)($_POST['field'] ?? ''));
    $value  = (string)($_POST['value'] ?? '');
    if ($rowKey === '') jerr('缺少資料列');
    $fd = null;
    foreach ($spec['fields'] as $f) { if ($f['k'] === $fieldK) { $fd = $f; break; } }
    if (!$fd) jerr('這個欄位不開放修改');

    // 這一筆必須真的在「這一格的不符合標準清單」裡
    $params = kpi_as_params($iy['params_json']);
    $d = kpi_as_detail($db, $calc, $year, $month, $params, kpi_as_excl_rules($db, $iid, $year), $iy);
    $hit = null;
    foreach ($d['rows'] as $r) { if ((string)$r['key'] === $rowKey) { $hit = $r; break; } }
    if (!$hit) jerr('這一筆已經不在本月的清單內（可能別人剛改過），請重新整理後再試');

    // 值的驗證：型態與可選值都來自白名單
    $val = $value;
    if (($fd['type'] ?? '') === 'select') {
        if (!isset($fd['opts'][$val])) jerr('選項不正確');
    } elseif (($fd['type'] ?? '') === 'date') {
        if ($val === '') {
            if (empty($fd['nullable'])) jerr('這個欄位不可留空');
            $val = null;
        } elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val, $mm) || !checkdate((int)$mm[2], (int)$mm[3], (int)$mm[1])) {
            jerr('日期格式不正確');
        }
    } else {
        jerr('欄位型態不支援');
    }

    $table = $spec['table']; $pk = $spec['pk'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $pk)
        || !preg_match('/^[A-Za-z0-9_]+$/', $fieldK)) jerr('設定不正確');

    $st = $db->prepare("SELECT `$fieldK` FROM `$table` WHERE `$pk`=?");
    $st->execute([$rowKey]);
    if ($st->rowCount() === 0) jerr('來源資料不存在');
    $oldVal = $st->fetchColumn();
    $oldStr = $oldVal === null ? '' : (string)$oldVal;
    $newStr = $val === null ? '' : (string)$val;
    if (substr($oldStr, 0, 10) === substr($newStr, 0, 10) && strlen($oldStr) && strlen($newStr)) {
        // 同一天（日期欄可能帶時分秒）或完全相同＝沒有變更
        if ($oldStr === $newStr || (($fd['type'] ?? '') === 'date')) jerr('值沒有變更');
    }

    $sets = ["`$fieldK`=?"]; $bind = [$val];
    if (!empty($spec['stamp']['by'])) { $sets[] = "`" . $spec['stamp']['by'] . "`=?"; $bind[] = (string)$u['user_cname']; }
    if (!empty($spec['stamp']['at'])) { $sets[] = "`" . $spec['stamp']['at'] . "`=NOW()"; }
    $bind[] = $rowKey;
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE `$table` SET " . implode(',', $sets) . " WHERE `$pk`=?")->execute($bind);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('寫入失敗：' . $e->getMessage(), 500); }

    kpi_as_log($db, $iid, $year, $month, 'src_edit', $table . '.' . $fieldK, $oldStr, $newStr,
               '由 KPI 明細修改來源資料（' . $spec['pk'] . '=' . $rowKey . '）', $u);
    // 全站稽核紀錄（改的是別的模組的資料，只寫 KPI 自己的 change_log 會查不到）
    try {
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES ('kpi_src_edit', ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$table, (string)$rowKey, mb_substr(implode(' ｜ ', $hit['vals']), 0, 100),
                      json_encode(['field'=>$fieldK, 'old'=>$oldStr, 'new'=>$newStr,
                                   'indicator_id'=>$iid, 'year'=>$year, 'month'=>$month],
                                  JSON_UNESCAPED_UNICODE),
                      (int)$u['id'], (string)$u['user_cname']]);
    } catch (Throwable $e) {}

    // 重算：這一格一定要算；若改的是「決定算在哪個月」的欄位，另一個月也要跟著算
    $res = kpi_as_settle($db, $iy, $year, $month, $u);
    $also = null;
    if (!empty($fd['remonth'])) {
        $t = kpi_as_edit_target_ym($calc, $fieldK, $newStr, $year);
        if ($t && !($t[0] === $year && $t[1] === $month)) {
            $iy2 = ($t[0] === $year) ? $iy : kpi_get_iy_row($db, $iid, $t[0]);
            if ($iy2 && !kpi_as_year_locked($t[0]) && in_array($t[1], kpi_as_months($iy2['freq']), true)) {
                kpi_as_settle($db, $iy2, $t[0], $t[1], $u);
                $also = $t[0] . '年' . $t[1] . '月';
            }
        }
    }
    jout(['old'=>$oldStr, 'new'=>$newStr, 'also_recalced'=>$also,
          'value'=>($res && $res['value'] !== null) ? round($res['value'], 2) : null,
          'num'=>$res['num'] ?? null, 'den'=>$res['den'] ?? null]);
}

/* ---------- 管理員設定：這個指標的來源資料可不可以直接改 ---------- */
case 'edit_mode_save': {
    if (!$perms['canAdmin']) jerr('僅KPI管理者可設定', 403);
    $iid  = (int)($_POST['indicator_id'] ?? 0);
    $mode = (string)($_POST['mode'] ?? '');
    if (!in_array($mode, ['suggest', 'allow', 'deny'], true)) jerr('設定值不正確');
    $st = $db->prepare("SELECT 1 FROM kpi_as_indicator WHERE indicator_id=?");
    $st->execute([$iid]);
    if (!$st->fetchColumn()) jerr('找不到指標');
    $db->prepare("UPDATE kpi_as_indicator SET src_edit_mode=?, Modified_By=?, Modified_At=NOW() WHERE indicator_id=?")
       ->execute([$mode, (string)$u['user_cname'], $iid]);
    kpi_as_log($db, $iid, null, null, 'edit_mode', 'src_edit_mode', null, $mode, '來源資料可否直接修改', $u);
    jout(['mode'=>$mode]);
}

/* ---------- 手動填寫（manual 模式；擔當者本人／其請假代理人；鎖定年僅KPI管理員） ---------- */
case 'fill': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if ($iy['source_mode'] !== 'manual') jerr('此指標為自動計算，如需修正請用覆寫功能');
    if (!kpi_as_can_override($db, $year, $perms, (int)$iy['owner_user_id'], $uid))
        jerr('僅擔當者本人或其請假代理人可填寫（年度鎖定後僅KPI管理員可補填）', 403);
    if (!in_array($month, kpi_as_valid_months($iy), true)) jerr('該指標此月份不適用（頻率：'.$iy['freq'].'）');
    if ($year === $curY && $month > $curM) jerr('不可填寫未來月份');
    // 一個期間(季/半年/年)只能擇一月份填寫，若同期間其他月份已有資料，需先清除才能改填此月份
    $grp = kpi_as_period_group($iy['freq'], $month);
    if (count($grp) > 1) {
        $ph = implode(',', array_fill(0, count($grp), '?'));
        $st = $db->prepare("SELECT month FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month IN ($ph)
                            AND month<>? AND (manual_value IS NOT NULL OR override_value IS NOT NULL)");
        $st->execute(array_merge([$iid, $year], $grp, [$month]));
        $otherMonth = $st->fetchColumn();
        if ($otherMonth !== false) jerr('本期已於 '.$otherMonth.' 月填寫，如需改填此月份請先清除該月份的填寫內容', 409);
    }
    $raw = trim((string)($_POST['value'] ?? ''));
    if ($raw === '') jerr('請輸入數值');
    $val = kpi_as_parse_input((string)$iy['value_type'], $raw);
    if ($val === null) jerr('「' . $raw . '」無法辨識，' . kpi_as_input_hint((string)$iy['value_type']));
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
    $st = $db->prepare("SELECT manual_value FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month=?");
    $st->execute([$iid, $year, $month]);
    $old = $st->fetchColumn();
    $st = $db->prepare("INSERT INTO kpi_as_monthly_value (indicator_id,year,month,manual_value,filled_by,filled_by_name,filled_at,note)
                        VALUES (?,?,?,?,?,?,NOW(),?)
                        ON DUPLICATE KEY UPDATE manual_value=VALUES(manual_value), filled_by=VALUES(filled_by),
                                filled_by_name=VALUES(filled_by_name), filled_at=NOW(), note=VALUES(note)");
    $st->execute([$iid, $year, $month, $val, $uid, $u['user_cname'], $note]);
    kpi_as_log($db, $iid, $year, $month, 'fill', 'manual_value',
               $old === false ? null : $old, $val, $note ?: null, $u);
    jout(['value'=>$val]);
}

/* ---------- 清除填寫（手動指標一個期間只能擇一月份，要改填其他月份需先清除；同fill權限） ---------- */
case 'clear_fill': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if ($iy['source_mode'] !== 'manual') jerr('僅手動填寫指標可清除填寫');
    if (!kpi_as_can_override($db, $year, $perms, (int)$iy['owner_user_id'], $uid))
        jerr('僅擔當者本人或其請假代理人可清除填寫', 403);
    $st = $db->prepare("SELECT manual_value FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month=?");
    $st->execute([$iid, $year, $month]);
    $old = $st->fetchColumn();
    $st = $db->prepare("UPDATE kpi_as_monthly_value
                        SET manual_value=NULL, filled_by=NULL, filled_by_name=NULL, filled_at=NULL, note=NULL
                        WHERE indicator_id=? AND year=? AND month=?");
    $st->execute([$iid, $year, $month]);
    kpi_as_log($db, $iid, $year, $month, 'fill', 'manual_value', $old === false ? null : $old, null, '清除填寫', $u);
    jout([]);
}

/* ---------- 覆寫 / 清除覆寫（擔當者本人／其請假代理人；年度鎖定後僅KPI管理員；原因必填；保留原值可追溯） ---------- */
case 'override': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if (!kpi_as_can_override($db, $year, $perms, (int)$iy['owner_user_id'], $uid)) jerr('僅擔當者本人或其請假代理人可手動覆寫', 403);
    if (!in_array($month, kpi_as_valid_months($iy), true)) jerr('該指標此月份不適用');
    $raw = trim((string)($_POST['value'] ?? ''));
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
    if ($raw === '') jerr('請輸入覆寫值');
    if ($reason === '') jerr('覆寫原因必填（AS9100 可追溯要求）');
    $val = kpi_as_parse_input((string)$iy['value_type'], $raw);
    if ($val === null) jerr('「' . $raw . '」無法辨識，' . kpi_as_input_hint((string)$iy['value_type']));
    $st = $db->prepare("SELECT override_value, auto_value, manual_value FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month=?");
    $st->execute([$iid, $year, $month]);
    $old = $st->fetch(PDO::FETCH_ASSOC);
    $st = $db->prepare("INSERT INTO kpi_as_monthly_value (indicator_id,year,month,override_value,override_by,override_by_name,override_at,override_reason)
                        VALUES (?,?,?,?,?,?,NOW(),?)
                        ON DUPLICATE KEY UPDATE override_value=VALUES(override_value), override_by=VALUES(override_by),
                                override_by_name=VALUES(override_by_name), override_at=NOW(), override_reason=VALUES(override_reason)");
    $st->execute([$iid, $year, $month, $val, $uid, $u['user_cname'], $reason]);
    kpi_as_log($db, $iid, $year, $month, 'override', 'override_value',
               $old ? ($old['override_value'] ?? null) : null, $val, $reason, $u);
    jout(['value'=>$val]);
}

case 'clear_override': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if (!kpi_as_can_override($db, $year, $perms, (int)$iy['owner_user_id'], $uid)) jerr('僅擔當者本人或其請假代理人可清除覆寫', 403);
    $st = $db->prepare("SELECT override_value FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month=?");
    $st->execute([$iid, $year, $month]);
    $old = $st->fetchColumn();
    $st = $db->prepare("UPDATE kpi_as_monthly_value
                        SET override_value=NULL, override_by=NULL, override_by_name=NULL, override_at=NULL, override_reason=NULL
                        WHERE indicator_id=? AND year=? AND month=?");
    $st->execute([$iid, $year, $month]);
    kpi_as_log($db, $iid, $year, $month, 'override', 'override_value', $old === false ? null : $old, null, '清除覆寫', $u);
    jout([]);
}

/* ---------- 佐證附件 ---------- */
case 'attach_list': {
    $iid = (int)($_GET['indicator_id'] ?? 0);
    $year = (int)($_GET['year'] ?? 0);
    $month = (int)($_GET['month'] ?? 0);
    $st = $db->prepare("SELECT attach_id, file_name, original_name, file_size, note, uploaded_by, uploaded_by_name, created_at
                        FROM kpi_as_attachment WHERE indicator_id=? AND year=? AND month=? ORDER BY attach_id");
    $st->execute([$iid, $year, $month]);
    $list = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['can_delete'] = $perms['canAdmin'] || (int)$r['uploaded_by'] === $uid;
        $r['exists'] = kpi_as_attach_path($db, array_merge($r, ['year'=>$year])) !== null;
        unset($r['file_name']);
        $list[] = $r;
    }
    jout(['list'=>$list, 'max'=>kpi_as_attach_max($db)]);
}

case 'attach_upload': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if (!kpi_as_year_ok($db, $year)) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if (!in_array($month, kpi_as_valid_months($iy), true)) jerr('該指標此月份不適用');
    $ownerId = (int)$iy['owner_user_id'];
    if (!($perms['isAdmin'] || $ownerId === $uid || kpi_as_is_delegate_of_owner($db, $ownerId, $uid)))
        jerr('僅該指標擔當者本人或其請假代理人可上傳佐證', 403);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('檔案上傳失敗');
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) jerr('檔案超過 20MB');
    // xlsm＝含巨集的 Excel，現場的統計表多半是這種格式（2026-09-15 使用者要求加入）
    $allowed = ['jpg','jpeg','png','gif','webp','bmp','pdf','xls','xlsx','xlsm','xlsb','doc','docx','docm',
                'ppt','pptx','csv','txt','zip','7z','rar','odt','ods'];
    $orig = basename((string)$_FILES['file']['name']);
    // 檔名不是合法 UTF-8 時（某些用戶端會送 Big5），寫 utf8mb4 欄位會丟 1366 例外，
    // 使用者只會看到「上傳沒反應」的空白回應 → 先轉成 UTF-8，轉不了就用檔案本身的副檔名命名
    if (!mb_check_encoding($orig, 'UTF-8')) {
        // 指定 BIG-5（本地環境唯一會出現的非 UTF-8 檔名）；用偵測清單會被 SJIS 先驗證過關而解成亂碼
        $conv = @mb_convert_encoding($orig, 'UTF-8', 'BIG-5');
        $orig = ($conv !== false && mb_check_encoding($conv, 'UTF-8')) ? $conv : ('附件_' . date('Ymd_His'));
    }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) jerr('不支援的檔案格式：' . $ext);
    $max = kpi_as_attach_max($db);
    $st = $db->prepare("SELECT COUNT(*) FROM kpi_as_attachment WHERE indicator_id=? AND year=? AND month=?");
    $st->execute([$iid, $year, $month]);
    if ((int)$st->fetchColumn() >= $max) jerr("此月份佐證已達上限 {$max} 件（管理者可於設定頁調整）");
    $dir = kpi_as_attach_base($db) . DIRECTORY_SEPARATOR . $year;
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) jerr('無法建立附件目錄，請確認NAS路徑設定：' . $dir);
    $fname = 'kpi' . $iid . '_' . sprintf('%02d', $month) . '_' . date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . DIRECTORY_SEPARATOR . $fname)) jerr('檔案寫入失敗');
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
    $st = $db->prepare("INSERT INTO kpi_as_attachment (indicator_id,year,month,file_name,original_name,file_size,note,uploaded_by,uploaded_by_name)
                        VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([$iid, $year, $month, $fname, $orig, (int)$_FILES['file']['size'], $note, $uid, $u['user_cname']]);
    $newId = (int)$db->lastInsertId();   // 要在寫稽核紀錄「之前」取，否則拿到的是 change_log 的 id
    kpi_as_log($db, $iid, $year, $month, 'attach', 'upload', null, $orig, $note ?: null, $u);
    jout(['attach_id'=>$newId]);
}

case 'attach_delete': {
    $aid = (int)($_POST['attach_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM kpi_as_attachment WHERE attach_id=?");
    $st->execute([$aid]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) jerr('找不到附件');
    if (!($perms['canAdmin'] || (int)$att['uploaded_by'] === $uid)) jerr('僅上傳者或管理者可刪除', 403);
    $p = kpi_as_attach_path($db, $att);
    if ($p) @unlink($p);
    $db->prepare("DELETE FROM kpi_as_attachment WHERE attach_id=?")->execute([$aid]);
    kpi_as_log($db, (int)$att['indicator_id'], (int)$att['year'], (int)$att['month'],
               'attach', 'delete', $att['original_name'], null, null, $u);
    jout([]);
}

case 'attach_open': {
    $aid = (int)($_GET['attach_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM kpi_as_attachment WHERE attach_id=?");
    $st->execute([$aid]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) jerr('找不到附件', 404);
    $p = kpi_as_attach_path($db, $att);
    if (!$p) jerr('檔案不存在（可能NAS路徑已變更或檔案被移除）', 404);
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    $inline = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
               'gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','txt'=>'text/plain; charset=utf-8'];
    header_remove('Content-Type');
    $fnEnc = rawurlencode((string)($att['original_name'] ?: basename($p)));
    if (isset($inline[$ext])) {
        header('Content-Type: ' . $inline[$ext]);
        header("Content-Disposition: inline; filename*=UTF-8''{$fnEnc}");
    } else {
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename*=UTF-8''{$fnEnc}");
    }
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

default:
    jerr('未知動作：' . $action);
}
