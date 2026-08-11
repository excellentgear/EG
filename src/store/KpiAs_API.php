<?php
/**
 * AS9100 關鍵績效指標 KPI 頁 API（檢視/重算/填寫/覆寫/佐證附件）
 * 權限：kpi_as_lib.php kpi_as_perms()（roles module='kpi' ∪ 部門×主管階級規則 ∪ 指定人員），fail-closed
 * 舊年度鎖：隔年2/1起重算/覆寫/補填僅管理者(kpi_as_can_modify)
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
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

switch ($action) {

/* ---------- 基本資訊 ---------- */
case 'meta': {
    $years = range(2025, $curY);
    // 本人可填/可傳附件的指標：僅擔當者本人、其請假代理人；系統管理者=全部（RBAC鐵律）
    $year = max(2025, min($curY, (int)($_GET['year'] ?? $curY)));
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
    $year = max(2025, min($curY, (int)($_GET['year'] ?? $curY)));
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
                $res = kpi_as_compute($db, (string)$iy['calculator_key'], $year, $m, $params);
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
        $srcInfo = kpi_as_source_info($db, $iy['source_mode'], $iy['calculator_key'], $params);
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
          'can_admin'=>$perms['canAdmin'], 'attach_max'=>kpi_as_attach_max($db)]);
}

/* ---------- 重算（快照）：本年=擔當者/填報/管理者；舊年度僅管理者 ---------- */
case 'recalc': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0); // 0=全年已結束月份
    if ($year < 2025 || $year > $curY) jerr('年度不合法');
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

/* ---------- 前端試算（不入快照；僅 fe=1 參數可調，管理者不受限） ---------- */
case 'preview': {
    $iid = (int)($_GET['indicator_id'] ?? 0);
    $year = max(2025, min($curY, (int)($_GET['year'] ?? $curY)));
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
        $res = kpi_as_compute($db, (string)$iy['calculator_key'], $year, $m, $params);
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
    if ($year < 2025 || $year > $curY) jerr('年度不合法');
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

/* ---------- 手動填寫（manual 模式；擔當者本人／其請假代理人；鎖定年僅KPI管理員） ---------- */
case 'fill': {
    $iid = (int)($_POST['indicator_id'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if ($year < 2025 || $year > $curY) jerr('年度不合法');
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
    $val = (float)$raw;
    if ($iy['value_type'] === 'yesno') $val = $val >= 1 ? 1 : 0;
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
    $val = (float)$raw;
    if ($iy['value_type'] === 'yesno') $val = $val >= 1 ? 1 : 0;
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
    if ($year < 2025 || $year > $curY) jerr('年度不合法');
    $iy = kpi_get_iy_row($db, $iid, $year);
    if (!$iy) jerr('找不到指標');
    if (!in_array($month, kpi_as_valid_months($iy), true)) jerr('該指標此月份不適用');
    $ownerId = (int)$iy['owner_user_id'];
    if (!($perms['isAdmin'] || $ownerId === $uid || kpi_as_is_delegate_of_owner($db, $ownerId, $uid)))
        jerr('僅該指標擔當者本人或其請假代理人可上傳佐證', 403);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('檔案上傳失敗');
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) jerr('檔案超過 20MB');
    $allowed = ['jpg','jpeg','png','gif','webp','bmp','pdf','xls','xlsx','doc','docx','ppt','pptx','csv','txt','zip'];
    $orig = basename((string)$_FILES['file']['name']);
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
    kpi_as_log($db, $iid, $year, $month, 'attach', 'upload', null, $orig, $note ?: null, $u);
    jout(['attach_id'=>(int)$db->lastInsertId()]);
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
