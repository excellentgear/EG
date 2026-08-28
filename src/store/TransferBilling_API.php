<?php
/**
 * TransferBilling_API.php — 製程移轉一覽表「帳款月份」維護 API
 * ---------------------------------------------------------------------------
 * 2026-08-27 新增。所有計算與驗證邏輯都在 src/common/billing_month_lib.php，
 * 這裡只負責：登入守門 → 權限守門 → CSRF → 參數驗證 → 呼叫 lib → 回 JSON。
 *
 * 鐵律8：前端擋一次之後，這裡一律用同一套規則再擋一次（可直接打 API 繞過前端）。
 *
 * action：
 *   perms         回傳目前使用者的權限與 CSRF token（不改資料）
 *   set_month     批次指定帳款月份（mode=set 指定 YYYYMM／mode=shift 平移 N 個月）
 *   reset_auto    還原為自動（清掉手動註記並重算）
 *   recalc        重算（僅管理員）：只補沒有帳款月份的列，或整批重算
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/billing_month_lib.php';

function bmOut(array $d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function bmErr(string $msg, int $code = 400) { http_response_code($code); bmOut(['ok' => false, 'error' => $msg]); }

if (!isset($_SESSION['userName']) || $_SESSION['userName'] === '') bmErr('尚未登入或連線已逾時，請重新登入', 403);

try {
    $conn = new DBConnection();
    $db   = $conn->getPDO();
} catch (Throwable $e) { bmErr('資料庫連線失敗', 500); }

/* 欄位確認一定要在任何 transaction 之前做完：ALTER TABLE 會隱式 commit，
   放進 transaction 內會讓外層 commit() 爆「There is no active transaction」。
   lib 內部有 static 旗標，之後再呼叫就不會重跑 DDL。 */
eg_bm_ensure_schema($db);

$u  = eg_bm_current_user($db);
$pm = eg_bm_perms($db, $u);
if (!$pm['uid']) bmErr('查不到使用者資料或帳號已停用', 403);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ── 唯讀：權限與 token ─────────────────────────────────── */
if ($action === 'perms') {
    bmOut(['ok' => true, 'perms' => [
        'isAdmin'  => $pm['isAdmin'],
        'canAdmin' => $pm['canAdmin'],
        'canEdit'  => $pm['canEdit'],
        'canPrint' => $pm['canPrint'],
        'canView'  => $pm['canView'],
        'name'     => $pm['name'],
        'label'    => eg_bm_role_label($pm),
    ], 'features' => PROC_TRANSFER_FEATURES, 'csrf' => eg_bm_csrf_token()]);
}

/* ── 以下都會改資料：CSRF ＋ 權限 ───────────────────────── */
if (!eg_bm_csrf_ok($_POST['csrf'] ?? null)) bmErr('連線憑證失效，請重新整理頁面後再試 (CSRF)');
if (!$pm['canEdit']) bmErr('沒有「帳款月份維護」功能權限，請洽管理者在角色設定勾選後指派', 403);

/** POST 進來的 ids：可能是陣列，也可能是逗號字串 */
function bmIds(): array {
    $raw = $_POST['ids'] ?? [];
    if (is_string($raw)) $raw = explode(',', $raw);
    $ids = [];
    foreach ((array)$raw as $v) {
        $v = (int)trim((string)$v);
        if ($v > 0) $ids[] = $v;
    }
    return array_values(array_unique($ids));
}

try {
    if ($action === 'set_month') {
        $ids  = bmIds();
        if (!$ids)              bmErr('沒有選取任何資料列');
        if (count($ids) > 5000) bmErr('一次最多修改 5000 筆，請縮小選取範圍');

        $mode  = $_POST['mode'] ?? 'set';
        if (!in_array($mode, ['set', 'shift'], true)) bmErr('不支援的修改方式');
        $ym    = isset($_POST['ym']) ? trim((string)$_POST['ym']) : null;
        $shift = (int)($_POST['shift'] ?? 0);

        // 後端同規則再驗一次（前端已即時擋）
        if ($mode === 'set' && !eg_bm_ym_valid($ym)) bmErr('帳款月份要是 YYYYMM（例：202608），月份限 01~12');
        if ($mode === 'shift' && ($shift === 0 || $shift < -60 || $shift > 60)) bmErr('平移月數要在 -60 ~ 60 之間，且不可為 0');

        $db->beginTransaction();
        $r = eg_bm_set_manual($db, $ids, $mode, $ym, $shift, (int)$pm['uid']);
        $db->commit();

        bmOut(['ok' => true, 'updated' => $r['updated'], 'skipped' => $r['skipped'],
               'msg' => "已修改 {$r['updated']} 筆帳款月份"
                      . ($r['skipped'] > 0 ? "（{$r['skipped']} 筆因為沒有可用日期而略過）" : '')
                      . "，這些資料列會標記為「手動」。"]);
    }

    if ($action === 'reset_auto') {
        $ids = bmIds();
        if (!$ids)              bmErr('沒有選取任何資料列');
        if (count($ids) > 5000) bmErr('一次最多還原 5000 筆，請縮小選取範圍');

        $db->beginTransaction();
        $r = eg_bm_reset_auto($db, $ids);
        $db->commit();

        bmOut(['ok' => true, 'updated' => $r['updated'],
               'msg' => "已將 {$r['updated']} 筆還原為自動計算"
                      . ($r['no_date'] > 0 ? "（{$r['no_date']} 筆沒有可用日期，帳款月份留空）" : '') . '。']);
    }

    if ($action === 'recalc') {
        if (!$pm['canAdmin']) bmErr('重算需要「模組管理」功能權限', 403);
        $onlyEmpty = !empty($_POST['only_empty']);

        $r = eg_bm_fill($db, $onlyEmpty ? ['only_empty' => true] : []);   // 內含 DDL 檢查，故不包在 transaction 內
        bmOut(['ok' => true, 'stat' => $r,
               'msg' => "重算完成：掃描 {$r['scanned']} 筆、更新 {$r['updated']} 筆"
                      . "，手動指定不動 {$r['skipped_manual']} 筆"
                      . ($r['no_date'] > 0 ? "，{$r['no_date']} 筆無法解析日期" : '') . '。']);
    }

    bmErr('無效的操作');

} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) $db->rollBack();
    bmErr($e->getMessage());
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    bmErr('處理失敗：' . $e->getMessage(), 500);
}
