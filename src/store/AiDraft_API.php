<?php
/**
 * AI 草稿 API（本機 Ollama）—— 2026-10-16 建立，共用庫 src/common/ollama_lib.php
 * 只有全站管理員可用；前端擋一次、後端同規則再擋一次（鐵律8）。草稿不自動存檔，只回傳文字。
 *   GET  action=info       → enabled／model／csrf（非管理員一律 enabled=false，不給 token）
 *   POST action=draft      → kind＋ctx（JSON）＋csrf → text
 *   POST action=set_model  → model＋csrf（只能選本機已安裝的）
 *   POST action=demo_seed  → year＋ai＋csrf：產生關聯假資料（demo_seed_lib.php）；demo_clear → 清除【假資料】
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/risk_mgmt_rules_lib.php';   // cm_current_user／rm_suggest_context
include_once $document_root . '/EGsystem/src/common/ollama_lib.php';
include_once $document_root . '/EGsystem/src/common/demo_seed_lib.php';

function jout($a = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE); exit; }
set_exception_handler(function ($e) { jerr('系統錯誤：' . $e->getMessage(), 500); });

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { jerr('DB連線失敗', 500); }

$u = cm_current_user($db);
if (!$u) jerr('未登入', 401);
$isAdmin = eg_ai_is_admin($db, $u);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'info') {
    if (!$isAdmin || eg_ai_setting($db, 'enabled', '1') !== '1') jout(['enabled' => false]);
    if (empty($_SESSION['aidraft_csrf'])) $_SESSION['aidraft_csrf'] = bin2hex(random_bytes(16));
    $models = eg_ai_models($db);
    jout(['enabled' => $models !== null, 'model' => $models === null ? '' : eg_ai_pick_model($db, $models), 'models' => $models ?? [],
          'csrf' => $_SESSION['aidraft_csrf'], 'down' => $models === null]);
}

if (!$isAdmin) jerr('只有全站管理員可以使用 AI 草稿', 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jerr('請用 POST', 405);
if (empty($_SESSION['aidraft_csrf']) || !hash_equals((string)$_SESSION['aidraft_csrf'], (string)($_POST['csrf'] ?? ''))) jerr('連線憑證失效，請重新整理頁面', 403, ['code' => 'CSRF']);

if ($action === 'set_model') {
    $m = trim((string)($_POST['model'] ?? ''));
    $models = eg_ai_models($db) ?? [];
    if (!in_array($m, $models, true)) jerr('本機沒有安裝這個模型');
    eg_ai_setting_save($db, 'model', $m);
    jout(['model' => $m]);
}

if ($action === 'draft') {
    $kind = (string)($_POST['kind'] ?? '');
    $ctx = json_decode((string)($_POST['ctx'] ?? '{}'), true);
    if (!is_array($ctx)) jerr('內容格式錯誤');
    try { $t0 = microtime(true); $text = eg_ai_draft($db, $kind, $ctx); }
    catch (InvalidArgumentException $e) { jerr($e->getMessage()); }
    catch (Throwable $e) { jerr('產生草稿失敗：' . $e->getMessage(), 502); }
    if ($text === '') jerr('模型沒有回傳內容，請再按一次', 502);
    jout(['text' => $text, 'model' => eg_ai_pick_model($db), 'sec' => round(microtime(true) - $t0, 1)]);
}
if ($action === 'demo_seed') {          // 一鍵產生溝通管理／風險與機會／內稽與管審會議的關聯假資料（標【假資料】，可清除）
    $y = (int)($_POST['year'] ?? 0); if ($y < 2000 || $y > 2100) jerr('年度不合法');
    try { $rep = eg_demo_seed($db, ['year' => $y, 'ai' => !empty($_POST['ai']), 'cap' => 24]); }
    catch (RuntimeException $e) { jerr($e->getMessage(), 400); }
    jout(['report' => $rep]);
}
if ($action === 'demo_clear') {
    $y = (int)($_POST['year'] ?? 0);
    jout(['cleared' => eg_demo_clear($db, $y >= 2000 ? $y : null)]);
}
jerr('無效的操作');
