<?php
/**
 * DBBackup_API.php — 資料庫備份/還原後端（模組 db_backup）
 *
 * 權限（各頁分開、不連動；未指派角色者一律擋下，無 fallback-to-all）：
 *   - 進入/列表/下載 ： db_backup_view
 *   - 立即備份       ： db_backup_run
 *   - 整庫還原       ： 僅管理員 + 整表還原密碼
 *   - 整表還原       ： db_restore_table + 整表還原密碼
 *   - 部分還原(Phase2)： db_restore_partial + 部分還原密碼
 *   - 設定/設密碼    ： 僅管理員
 * 角色 CRUD/指派沿用 Roles_API.php（前端另呼叫），本檔只處理備份專屬動作。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/role_features_helper.php';
require_once __DIR__ . '/../common/db_backup_lib.php';

if (!isset($_SESSION['id'])) { echo json_encode(['success'=>false,'message'=>'尚未登入']); exit; }

$db  = new DBConnection();
$pdo = $db->getPDO();
$uid = (int)$_SESSION['id'];
// 顯示用一律使用者「中文姓名」,不用登入帳號（2026-07-24 使用者要求）
$by = '';
try {
    $st = $pdo->prepare("SELECT user_cname FROM user WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $by = trim((string)$st->fetchColumn());
} catch (Throwable $e) {}
if ($by === '') $by = (string)($_SESSION['userName'] ?? ('uid' . $uid));

// ── 權限 ────────────────────────────────────────────────────────────────
$features            = rf_load_user_features_all($pdo, $uid);
$IS_ADMIN            = rf_has_feature($features, 'all');
$CAN_VIEW            = $IS_ADMIN || rf_has_feature($features, 'db_backup_view');
$CAN_RUN             = $IS_ADMIN || rf_has_feature($features, 'db_backup_run');
$CAN_RESTORE_FULL    = $IS_ADMIN; // 整庫還原：僅管理員
$CAN_RESTORE_TABLE   = $IS_ADMIN || rf_has_feature($features, 'db_restore_table');
$CAN_RESTORE_PARTIAL = $IS_ADMIN || rf_has_feature($features, 'db_restore_partial');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function out($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function deny(){ out(['success'=>false,'message'=>'您沒有執行此操作的權限']); }

// 進入本頁的最低門檻
if (!$CAN_VIEW) deny();

// 驗證還原密碼（$type：'table' | 'partial'）
function verify_restore_pw(PDO $pdo, string $type, string $input): array {
    $key  = ($type === 'partial') ? 'pw_partial_restore' : 'pw_table_restore';
    $hash = eg_bk_cfg_get($pdo, $key, '');
    if ($hash === '') return ['ok'=>false,'msg'=>'管理員尚未設定此還原密碼，請先於設定中設定'];
    if ($input === '' || !password_verify($input, $hash)) return ['ok'=>false,'msg'=>'還原密碼錯誤'];
    return ['ok'=>true,'msg'=>''];
}

switch ($action) {

    // ── 列表 + 設定摘要 + 權限旗標 ──────────────────────────────────────────
    case 'list': {
        $rows = $pdo->query("
            SELECT id, filename, size_bytes, git_commit, trigger_type, status, pushed, note, created_by, created_at, finished_at
            FROM db_backup_log ORDER BY id DESC LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);

        $cfg = eg_bk_cfg_all($pdo);
        out([
            'success' => true,
            'data'    => $rows,
            'config'  => [
                'interval_days' => (int)($cfg['interval_days'] ?? 7),
                'keep_count'    => (int)($cfg['keep_count'] ?? 10),
                'nas_path'      => $cfg['nas_path'] ?? '',
                'auto_push'     => ($cfg['auto_push'] ?? '1') === '1',
                'last_error'    => $cfg['last_error'] ?? '',
                'pw_table_set'  => !empty($cfg['pw_table_restore']),
                'pw_partial_set'=> !empty($cfg['pw_partial_restore']),
                'repo_dir'      => BK_REPO,
            ],
            'perm'    => [
                'is_admin'        => $IS_ADMIN,
                'run'             => $CAN_RUN,
                'restore_full'    => $CAN_RESTORE_FULL,
                'restore_table'   => $CAN_RESTORE_TABLE,
                'restore_partial' => $CAN_RESTORE_PARTIAL,
            ],
        ]);
    }

    // ── 立即備份（背景執行 manual）──────────────────────────────────────────
    case 'run_now': {
        if (!$CAN_RUN) deny();
        $php = BK_PHP;
        $script = realpath(__DIR__ . '/../common/db_backup_run.php');
        if (!is_file($php) || !$script) out(['success'=>false,'message'=>'找不到備份工人程式']);
        // start /B 背景啟動，立即返回。觸發者以 uid 傳遞（中文名經 cmd 命令列會亂碼,由工人自行查中文名）
        $cmd = 'start /B "" "' . $php . '" "' . $script . '" manual "uid' . $uid . '" >NUL 2>&1';
        $h = @popen($cmd, 'r'); if ($h) @pclose($h);
        out(['success'=>true,'message'=>'已開始備份，稍候重新整理列表即可看到結果']);
    }

    // ── 儲存設定（管理員）──────────────────────────────────────────────────
    case 'save_settings': {
        if (!$IS_ADMIN) deny();
        $interval = max(1, min(365, (int)($_POST['interval_days'] ?? 7)));
        $keep     = max(1, min(200, (int)($_POST['keep_count'] ?? 10)));
        $nas      = trim((string)($_POST['nas_path'] ?? ''));
        $push     = (($_POST['auto_push'] ?? '1') === '1') ? '1' : '0';
        // NAS 路徑若有填，用「實際寫入探測檔」驗證（is_writable 在 Windows UNC 網路共享上會誤報，不可信）
        $nasWarn = '';
        if ($nas !== '') {
            if (!@is_dir($nas)) {
                $nasWarn = '（注意：此 NAS 路徑目前不存在，備份時會略過複製）';
            } else {
                $probe = rtrim($nas, "\\/") . DIRECTORY_SEPARATOR . '.egbk_write_test_' . getmypid() . '.tmp';
                if (@file_put_contents($probe, 'test') === false) {
                    $nasWarn = '（注意：此 NAS 路徑實際寫入失敗，備份時會略過複製）';
                } else {
                    @unlink($probe);
                }
            }
        }
        eg_bk_cfg_set($pdo, 'interval_days', (string)$interval, $by);
        eg_bk_cfg_set($pdo, 'keep_count',    (string)$keep, $by);
        eg_bk_cfg_set($pdo, 'nas_path',      $nas, $by);
        eg_bk_cfg_set($pdo, 'auto_push',     $push, $by);
        out(['success'=>true,'message'=>'設定已儲存' . $nasWarn]);
    }

    // ── 設定還原密碼（管理員）──────────────────────────────────────────────
    case 'set_password': {
        if (!$IS_ADMIN) deny();
        $type = ($_POST['type'] ?? '') === 'partial' ? 'partial' : 'table';
        $pw   = (string)($_POST['password'] ?? '');
        $key  = ($type === 'partial') ? 'pw_partial_restore' : 'pw_table_restore';
        if ($pw === '') { // 清空＝停用該還原（還原將被擋）
            eg_bk_cfg_set($pdo, $key, '', $by);
            out(['success'=>true,'message'=>'已清除該還原密碼（該還原功能將被停用直到重新設定）']);
        }
        if (mb_strlen($pw) < 4) out(['success'=>false,'message'=>'密碼至少 4 碼']);
        eg_bk_cfg_set($pdo, $key, password_hash($pw, PASSWORD_DEFAULT), $by);
        out(['success'=>true,'message'=>'密碼已設定']);
    }

    // ── 下載備份檔 ──────────────────────────────────────────────────────────
    case 'download': {
        $id = (int)($_GET['id'] ?? 0);
        $r = eg_bk_resolve_sql($pdo, $id);
        if (!$r['ok']) { http_response_code(404); header('Content-Type:text/plain; charset=utf-8'); echo $r['msg']; exit; }
        $st = $pdo->prepare("SELECT filename FROM db_backup_log WHERE id=?"); $st->execute([$id]);
        $fname = $st->fetchColumn() ?: ('backup_' . $id . '.sql');
        // 加固：清空所有輸出緩衝、關閉壓縮,避免大檔下載被緩衝/壓縮干擾成空檔或截斷
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('zlib.output_compression', '0');
        $fsize = filesize($r['path']);
        if ($fsize === false || $fsize <= 0) { http_response_code(500); header('Content-Type:text/plain; charset=utf-8'); echo '備份檔讀取失敗'; exit; }
        set_time_limit(0);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . $fsize);
        header('X-Content-Type-Options: nosniff');
        $fh = fopen($r['path'], 'rb');
        if ($fh) { while (!feof($fh)) { echo fread($fh, 1048576); flush(); } fclose($fh); }
        if ($r['temp']) @unlink($r['path']);
        exit;
    }

    // ── 列出某備份含哪些資料表（整表還原下拉）───────────────────────────────
    case 'list_tables': {
        if (!$CAN_RESTORE_TABLE) deny();
        $id = (int)($_GET['id'] ?? 0);
        out(['success'=>true,'data'=>eg_bk_list_tables_in_backup($pdo, $id)]);
    }

    // ── 整庫還原（管理員 + 整表還原密碼）────────────────────────────────────
    case 'restore_full': {
        if (!$CAN_RESTORE_FULL) deny();
        $v = verify_restore_pw($pdo, 'table', (string)($_POST['password'] ?? ''));
        if (!$v['ok']) out(['success'=>false,'message'=>$v['msg']]);
        $id = (int)($_POST['id'] ?? 0);
        $r  = eg_bk_resolve_sql($pdo, $id);
        if (!$r['ok']) out(['success'=>false,'message'=>$r['msg']]);
        // 還原前先自動快照現況
        $snap = eg_bk_run($pdo, 'pre-restore', $by);
        $res  = eg_bk_import_file($r['path']);
        if ($r['temp']) @unlink($r['path']);
        $res['message'] = ($res['ok'] ? '整庫還原完成。' : '整庫還原失敗：' . $res['msg'])
                        . '（還原前已自動快照：' . ($snap['ok'] ? $snap['filename'] : ('快照未成功-' . $snap['msg'])) . '）';
        $res['success'] = $res['ok'];
        out($res);
    }

    // ── 整表還原（db_restore_table + 整表還原密碼）──────────────────────────
    case 'restore_table': {
        if (!$CAN_RESTORE_TABLE) deny();
        $v = verify_restore_pw($pdo, 'table', (string)($_POST['password'] ?? ''));
        if (!$v['ok']) out(['success'=>false,'message'=>$v['msg']]);
        $id    = (int)($_POST['id'] ?? 0);
        $table = trim((string)($_POST['table'] ?? ''));
        if ($table === '') out(['success'=>false,'message'=>'請指定資料表']);
        // 還原前先自動快照現況
        $snap = eg_bk_run($pdo, 'pre-restore', $by);
        $res  = eg_bk_restore_table($pdo, $id, $table);
        $res['message'] = ($res['ok'] ? "資料表 {$table} 還原完成。" : '整表還原失敗：' . $res['msg'])
                        . '（還原前已自動快照：' . ($snap['ok'] ? $snap['filename'] : ('快照未成功-' . $snap['msg'])) . '）';
        $res['success'] = $res['ok'];
        out($res);
    }

    // ── 刪除備份（僅管理員；git rm+commit+push,NAS複本不動,歷史殘留需另清）──
    case 'delete_backup': {
        if (!$IS_ADMIN) deny();
        $id = (int)($_POST['id'] ?? 0);
        $res = eg_bk_delete_backup($pdo, $id, $by);
        out(['success'=>$res['ok'],'message'=>$res['msg']]);
    }

    // ── 徹底清除 Git 歷史（僅管理員；歷史壓縮為單一版本+強制推送雲端）──
    case 'purge_history': {
        if (!$IS_ADMIN) deny();
        if (trim((string)($_POST['confirm'] ?? '')) !== '清除歷史') out(['success'=>false,'message'=>'請輸入「清除歷史」四字確認']);
        set_time_limit(600);
        $res = eg_bk_purge_history($pdo, $by);
        out(['success'=>$res['ok'],'message'=>$res['msg']]);
    }

    // ═════════════════ 路徑遷移工具（換NAS/換機;僅管理員）═════════════════
    case 'path_inventory': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/path_migration_lib.php';
        out(['success'=>true,'data'=>eg_pm_inventory($pdo)]);
    }
    case 'path_set_setting': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/path_migration_lib.php';
        $r = eg_pm_set_setting($pdo, (string)($_POST['scope'] ?? ''), (string)($_POST['key'] ?? ''), (string)($_POST['value'] ?? ''), $by);
        out(['success'=>$r['ok'],'message'=>$r['msg']]);
    }
    case 'path_bulk_prefix': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/path_migration_lib.php';
        $dry = (($_POST['dry'] ?? '1') === '1');
        $sel = null;
        if (isset($_POST['selected'])) {
            $selArr = json_decode((string)$_POST['selected'], true);
            if (is_array($selArr)) $sel = array_map('strval', $selArr);
        }
        $r = eg_pm_bulk_prefix($pdo, (string)($_POST['old'] ?? ''), (string)($_POST['new'] ?? ''), $dry, $by, $sel);
        out(['success'=>$r['ok'],'message'=>$r['msg'],'items'=>$r['items']]);
    }
    case 'path_changelog': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/path_migration_lib.php';
        out(['success'=>true,'data'=>eg_pm_changelog($pdo, 100)]);
    }

    // ═════════════════ 移機快速備份（僅管理員）═════════════════
    case 'migbk_get': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/mig_backup_lib.php';
        require_once __DIR__ . '/../common/path_migration_lib.php';
        // 可勾選的 NAS 文件來源=盤點中 kind=fs 且有值的設定
        $opts = [];
        foreach (eg_pm_inventory($pdo)['settings'] as $s) {
            if ($s['kind'] === 'fs' && $s['value'] !== '') {
                $opts[] = ['key'=>$s['key'], 'label'=>$s['label'], 'path'=>$s['value'], 'exists'=>$s['exists']];
            }
        }
        out(['success'=>true, 'settings'=>eg_migbk_settings($pdo), 'status'=>eg_migbk_status($pdo), 'doc_options'=>$opts]);
    }
    case 'migbk_save': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/mig_backup_lib.php';
        $keys = json_decode((string)($_POST['include_keys'] ?? '[]'), true);
        eg_migbk_save_settings($pdo, [
            'dest'            => (string)($_POST['dest'] ?? ''),
            'include_docs'    => (($_POST['include_docs'] ?? '1') === '1'),
            'include_keys'    => is_array($keys) ? $keys : [],
            'include_uploads' => (($_POST['include_uploads'] ?? '1') === '1'),
            'interval_days'   => (int)($_POST['interval_days'] ?? 0),
        ], $by);
        out(['success'=>true,'message'=>'移機備份設定已儲存']);
    }
    case 'migbk_start': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/mig_backup_lib.php';
        $r = eg_migbk_start($pdo, $by, 'manual');
        out(['success'=>$r['ok'],'message'=>$r['msg']]);
    }
    case 'migbk_status': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/mig_backup_lib.php';
        out(['success'=>true,'data'=>eg_migbk_status($pdo)]);
    }
    case 'path_copy_start': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/path_migration_lib.php';
        $r = eg_pm_copy_start($pdo, (string)($_POST['src'] ?? ''), (string)($_POST['dst'] ?? ''), $by);
        out(['success'=>$r['ok'],'message'=>$r['msg']]);
    }
    case 'path_copy_status': {
        if (!$IS_ADMIN) deny();
        require_once __DIR__ . '/../common/path_migration_lib.php';
        out(['success'=>true,'data'=>eg_pm_copy_status($pdo)]);
    }

    // ═════════════════ Phase 2：誤刪救援（部分還原） ═════════════════

    // ── 載入備份到檢視暫存庫（背景，約 20 秒）──
    case 'view_load': {
        if (!$CAN_RESTORE_PARTIAL) deny();
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status FROM db_backup_log WHERE id=?"); $st->execute([$id]);
        if ($st->fetchColumn() !== 'success') out(['success'=>false,'message'=>'請選擇一筆成功的備份']);
        $vs = eg_bk_view_status($pdo);
        if ($vs['status'] === 'loading') out(['success'=>false,'message'=>'另一個載入正在進行中，請稍候']);
        eg_bk_cfg_set($pdo, 'view_status', 'loading', $by);
        eg_bk_cfg_set($pdo, 'view_backup_id', (string)$id, $by);
        $script = realpath(__DIR__ . '/../common/db_backup_view_load.php');
        if (!is_file(BK_PHP) || !$script) out(['success'=>false,'message'=>'找不到載入工人程式']);
        $cmd = 'start /B "" "' . BK_PHP . '" "' . $script . '" ' . $id . ' >NUL 2>&1';
        $h = @popen($cmd, 'r'); if ($h) @pclose($h);
        out(['success'=>true,'message'=>'開始載入檢視庫（約 20 秒），完成後即可搜尋/比對']);
    }

    // ── 檢視庫狀態（輪詢用）──
    case 'view_status': {
        if (!$CAN_RESTORE_PARTIAL) deny();
        $vs = eg_bk_view_status($pdo);
        if ($vs['backup_id']) {
            $st = $pdo->prepare("SELECT filename, created_at FROM db_backup_log WHERE id=?");
            $st->execute([$vs['backup_id']]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) { $vs['filename'] = $row['filename']; $vs['backup_time'] = $row['created_at']; }
        }
        out(['success'=>true,'data'=>$vs]);
    }

    // ── 值搜尋（跨全部表找關鍵字）──
    case 'view_search': {
        if (!$CAN_RESTORE_PARTIAL) deny();
        if (eg_bk_view_status($pdo)['status'] !== 'ready') out(['success'=>false,'message'=>'檢視庫尚未載入']);
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        if (mb_strlen($q) < 2) out(['success'=>false,'message'=>'關鍵字至少 2 個字']);
        set_time_limit(120);
        out(['success'=>true,'data'=>eg_bk_view_search($pdo, $q)]);
    }

    // ── 差異概覽（各表誤刪列數）──
    case 'view_diff_overview': {
        if (!$CAN_RESTORE_PARTIAL) deny();
        if (eg_bk_view_status($pdo)['status'] !== 'ready') out(['success'=>false,'message'=>'檢視庫尚未載入']);
        set_time_limit(300);
        out(['success'=>true,'data'=>eg_bk_view_diff_overview($pdo)]);
    }

    // ── 差異明細（某表被刪的列）──
    case 'view_diff_table': {
        if (!$CAN_RESTORE_PARTIAL) deny();
        if (eg_bk_view_status($pdo)['status'] !== 'ready') out(['success'=>false,'message'=>'檢視庫尚未載入']);
        $t = trim((string)($_GET['table'] ?? ''));
        out(['success'=>true,'data'=>eg_bk_view_diff_table($pdo, $t)]);
    }

    // ── 逐列還原（db_restore_partial + 部分還原密碼 + 還原前自動快照）──
    case 'view_restore_rows': {
        if (!$CAN_RESTORE_PARTIAL) deny();
        $v = verify_restore_pw($pdo, 'partial', (string)($_POST['password'] ?? ''));
        if (!$v['ok']) out(['success'=>false,'message'=>$v['msg']]);
        if (eg_bk_view_status($pdo)['status'] !== 'ready') out(['success'=>false,'message'=>'檢視庫尚未載入']);
        $table  = trim((string)($_POST['table'] ?? ''));
        $pkList = json_decode((string)($_POST['pk_list'] ?? '[]'), true);
        $mode   = (($_POST['mode'] ?? 'insert') === 'replace') ? 'replace' : 'insert';
        if (!is_array($pkList) || !$pkList) out(['success'=>false,'message'=>'未選取任何列']);
        // 還原前自動快照
        $snap = eg_bk_run($pdo, 'pre-restore', $by);
        $res  = eg_bk_view_restore_rows($pdo, $table, $pkList, $mode);
        $res['message'] = $res['msg'] . '（還原前已自動快照：' . ($snap['ok'] ? $snap['filename'] : ('快照未成功-' . $snap['msg'])) . '）';
        $res['success'] = $res['ok'];
        out($res);
    }

    default:
        out(['success'=>false,'message'=>'未知的 action: ' . $action]);
}
