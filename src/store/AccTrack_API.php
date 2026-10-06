<?php
/**
 * AccTrack_API.php — 對帳進度追蹤（views/ACC/recon_track.php）唯一資料端點
 * 建立：2026-10-06（使用者交辦）。計算一律呼叫 src/common/acc_track_lib.php，不在這裡重算。
 * 權限：roles module='acc_recon_track'（art_admin/art_view_all/art_pm/art_sales/art_acc），
 *       經 act_perms() 解析，前端擋一次、後端同規則再擋一次（鐵律8）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/acc_track_lib.php';
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';

set_exception_handler(function ($e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

function actOut(array $a = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE);
    exit;
}
function actErr(string $msg, int $code = 400, array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
act_ensure_schema($db);

$uid = (int)($_SESSION['id'] ?? 0);
if (!$uid) actErr('未登入或連線已逾時，請重新登入', 401);

$uname = '';
try { $st = $db->prepare("SELECT user_cname FROM user WHERE id=? LIMIT 1"); $st->execute([$uid]); $uname = (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) {}

$perms = act_perms($db, $uid);
$perms['uname'] = $uname;
if (!$perms['canView'] && !$perms['canAdmin']) actErr('您沒有對帳進度追蹤的使用權限，請洽管理員指派角色', 403);

if (empty($_SESSION['act_csrf'])) $_SESSION['act_csrf'] = bin2hex(random_bytes(16));
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$WRITE = ['set_status', 'settle_quick_edit', 'settle_revert', 'role_matrix_save', 'workday_groups_save',
          'settle_ex_save', 'settle_ex_delete'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['act_csrf'], $tok)) {
        actErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403, ['code' => 'CSRF']);
    }
}

function actSide($v) { return ($v === 'ap') ? 'ap' : 'ar'; }

switch ($action) {

    case 'bootstrap': {
        actOut([
            'csrf'    => $_SESSION['act_csrf'],
            'perms'   => $perms,
            'statuses'=> act_statuses(),
            'anchors' => act_anchors(),
            'years'   => act_years($db),
            'matrix'  => act_role_matrix($db),
            'groups'  => act_workday_groups($db),
        ]);
    }

    /* ── 進度清單（單一結帳月份）────────────────────────────────── */
    case 'list': {
        $side = actSide($_GET['side'] ?? 'ar');
        $bm   = (string)($_GET['bm'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) actErr('不合法的結帳月份');
        $rows = ($side === 'ar') ? act_ar_rows($db, $bm) : act_ap_rows($db, $bm);

        $status = (string)($_GET['status'] ?? '');
        if ($status !== '' && array_key_exists($status, act_statuses())) {
            $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $status));
        }
        $kw = trim((string)($_GET['kw'] ?? ''));
        if ($kw !== '') {
            $rows = array_values(array_filter($rows, fn($r) =>
                mb_stripos((string)$r['party_name'], $kw) !== false ||
                mb_stripos((string)($r['party_short'] ?? ''), $kw) !== false ||
                mb_stripos((string)($r['party_id'] ?? ''), $kw) !== false));
        }
        $counts = array_fill_keys(array_keys(act_statuses()), 0);
        foreach (($side === 'ar') ? act_ar_rows($db, $bm) : act_ap_rows($db, $bm) as $r) $counts[$r['status']]++;

        actOut(['rows' => $rows, 'total' => count($rows), 'counts' => $counts, 'bm' => $bm, 'side' => $side]);
    }

    case 'set_status': {
        $trackId = (int)($_POST['track_id'] ?? 0);
        $to      = (string)($_POST['to_status'] ?? '');
        $note    = trim((string)($_POST['note'] ?? ''));
        if ($trackId <= 0) actErr('缺少追蹤資料');
        $r = act_set_status($db, $trackId, $to, $perms, $note !== '' ? $note : null);
        if (!$r['success']) actErr($r['message']);
        actOut(['message' => $r['message']]);
    }

    /* ── 結帳日快速修改（連動 customer_list/maker_list）────────────── */
    case 'settle_quick_edit': {
        if (!$perms['canAdmin']) actErr('沒有修改結帳日的權限（僅本頁管理員）', 403);
        $type = (string)($_POST['target_type'] ?? '');
        $id   = trim((string)($_POST['target_id'] ?? ''));
        if ($id === '') actErr('缺少對象');
        $fields = [
            'settlement_mode' => (string)($_POST['settlement_mode'] ?? 'FIXED'),
            'settlement_day'  => (string)($_POST['settlement_day'] ?? ''),
        ];
        $r = act_settle_quick_edit($db, $type, $id, $fields, $perms);
        if (!$r['success']) actErr($r['message']);
        actOut(['message' => $r['message'], 'changed' => $r['changed'] ?? []]);
    }

    case 'settle_revert': {
        if (!$perms['canAdmin']) actErr('沒有復原的權限（僅本頁管理員）', 403);
        $logId = (int)($_POST['log_id'] ?? 0);
        if ($logId <= 0) actErr('缺少紀錄');
        $r = act_settle_revert($db, $logId, $perms);
        if (!$r['success']) actErr($r['message']);
        actOut(['message' => $r['message']]);
    }

    case 'change_log': {
        $type = (string)($_GET['target_type'] ?? '');
        $id   = trim((string)($_GET['target_id'] ?? ''));
        if ($id === '') actErr('缺少對象');
        actOut(['rows' => act_change_log_list($db, $type, $id, 30)]);
    }

    /* ── 角色矩陣設定 ───────────────────────────────────────────────── */
    case 'role_matrix_get': {
        actOut(['matrix' => act_role_matrix($db)]);
    }
    case 'role_matrix_save': {
        if (!$perms['canAdmin']) actErr('沒有設定權限（僅本頁管理員）', 403);
        $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
        if (!is_array($rows)) actErr('格式錯誤');
        act_role_matrix_save($db, $rows);
        actOut(['matrix' => act_role_matrix($db)]);
    }

    /* ── 工作天數統計分組設定 ──────────────────────────────────────── */
    case 'workday_groups_get': {
        actOut(['groups' => act_workday_groups($db)]);
    }
    case 'workday_groups_save': {
        if (!$perms['canAdmin']) actErr('沒有設定權限（僅本頁管理員）', 403);
        $groups = json_decode((string)($_POST['groups'] ?? '[]'), true);
        if (!is_array($groups)) actErr('格式錯誤');
        $clean = act_workday_groups_save($db, $groups, $perms['uname'] ?? '');
        actOut(['groups' => $clean]);
    }

    /* ── 統計分析（月/季/半年/年）＋自動分析 ────────────────────────── */
    case 'stats': {
        $side  = actSide($_GET['side'] ?? 'ar');
        $gran  = (string)($_GET['gran'] ?? 'month');
        if (!isset(oa_grans()[$gran])) actErr('不合法的期間粒度');
        $year  = (int)($_GET['year'] ?? date('Y'));
        $idx   = (int)($_GET['idx']  ?? 1);
        $period     = oa_period_pick($year, $gran, $idx);
        $prevPeriod = oa_compare_period($year, $gran, $idx, 'prev');
        $groups = act_workday_groups($db);

        $stat    = act_workday_stats($db, $side, $period, $groups);
        $counts  = act_period_status_counts($db, $side, $period);
        $insights= act_insights($db, $side, $period, $prevPeriod, $groups);

        // 趨勢：該年度每個子期間各算一次（給折線圖）
        $trend = [];
        foreach (oa_period_buckets($year, $gran) as $b) {
            $s = act_workday_stats($db, $side, $b, $groups);
            $trend[] = ['label' => $b['label'], 'groups' => $s['groups']];
        }

        actOut([
            'period' => $period, 'prev_period' => $prevPeriod,
            'stat' => $stat, 'counts' => $counts, 'insights' => $insights, 'trend' => $trend,
            'grans' => oa_grans(),
        ]);
    }

    /* ── 臨時結帳調整（AR 專用，直接沿用 acc_lib.php 既有的唯一實作）──── */
    case 'settle_ex_list': {
        $cid = trim((string)($_GET['customer_id'] ?? ''));
        if ($cid === '') actErr('缺少客戶');
        actOut(['rows' => acc_settle_ex_list($db, $cid, 24)]);
    }
    case 'settle_ex_save': {
        if (!($perms['canAdmin'] || $perms['isSales'])) actErr('沒有設定臨時結帳調整的權限', 403);
        $r = acc_settle_ex_save($db,
            trim((string)($_POST['customer_id'] ?? '')),
            trim((string)($_POST['ym'] ?? '')),
            trim((string)($_POST['adjusted'] ?? '')),
            trim((string)($_POST['reason'] ?? '')),
            ['id' => $uid, 'name' => $uname]);
        if (!$r['success']) actErr($r['message']);
        actOut(['message' => '已儲存']);
    }
    case 'settle_ex_delete': {
        if (!($perms['canAdmin'] || $perms['isSales'])) actErr('沒有設定臨時結帳調整的權限', 403);
        $id = (int)($_POST['exception_id'] ?? 0);
        if ($id <= 0) actErr('缺少資料');
        $r = acc_settle_ex_delete($db, $id, ['id' => $uid, 'name' => $uname]);
        if (!$r['success']) actErr($r['message']);
        actOut(['message' => '已刪除']);
    }

    default:
        actErr('未知的操作');
}
