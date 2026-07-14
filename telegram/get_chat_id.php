<?php
// Telegram 綁定 / 測試工具頁（僅系統管理員可用）。
// 功能：1) 檢查 Token 設定  2) 讀取 bot 收到的訊息取得 chat_id  3) 綁定員工 ↔ chat_id
//       4) 管理既有綁定  5) 發送測試訊息
session_start();

if (!isset($_SESSION['userName']) && !isset($_SESSION['id'])) {
    header("Location:../index.php");
    exit();
}

include '../src/common/DBConnection.php';
require_once '../src/common/rbac.php';
require_once __DIR__ . '/send_message.php';

$conn = new DBConnection();
$db = $conn->getPDO();

// 僅管理員（feature 'all'）可使用本工具頁
$__features = rbac_user_features($db, (int)($_SESSION['id'] ?? 0));
if (!rbac_has($__features, 'all')) {
    header("Location:../views/admin/dashboard.php");
    exit();
}

$msg = ''; $msgType = 'ok';

// 儲存 Bot Token（由本頁寫入 config/telegram_config.php；Token 僅存於該檔，不進資料庫）
if (isset($_POST['save_token'])) {
    $tk = trim($_POST['bot_token'] ?? '');
    if (!preg_match('/^\d+:[A-Za-z0-9_\-]{30,}$/', $tk)) {
        $msg = 'Token 格式不正確，應為「數字:英數字串」格式（如 1234567890:AAExxx...），請確認是否完整複製 BotFather 給的 HTTP API Token'; $msgType = 'err';
    } else {
        $tpl = "<?php\n"
             . "// Telegram Bot 設定檔（可由 telegram/get_chat_id.php 工具頁「① Bot Token」表單更新）。\n"
             . "// ⚠️ Token 等同 bot 的密碼，請勿外流；只放在本檔，其他程式一律 require 本檔取用。\n\n"
             . "if (!defined('TELEGRAM_BOT_TOKEN')) {\n"
             . "    define('TELEGRAM_BOT_TOKEN', " . var_export($tk, true) . ");\n"
             . "}\n";
        if (@file_put_contents(__DIR__ . '/../config/telegram_config.php', $tpl) !== false) {
            header("Location: get_chat_id.php?tokensaved=1"); // PRG：讓下一請求載入新 Token 並驗證
            exit();
        }
        $msg = '寫入 config/telegram_config.php 失敗，請確認 Apache 對該檔有寫入權限'; $msgType = 'err';
    }
}
if (isset($_GET['tokensaved'])) { $msg = 'Token 已儲存，下方顯示 bot 驗證結果'; }

// 綁定員工 ↔ chat_id
if (isset($_POST['bind']) && !empty($_POST['bind_user_id']) && !empty($_POST['bind_chat_id'])) {
    try {
        $uid = (int)$_POST['bind_user_id'];
        $cid = trim($_POST['bind_chat_id']);
        $name = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
        $name->execute([$uid]);
        $cname = $name->fetchColumn();
        if (!$cname) {
            $msg = '找不到該員工'; $msgType = 'err';
        } elseif (!preg_match('/^-?\d+$/', $cid)) {
            $msg = 'chat_id 必須是數字'; $msgType = 'err';
        } else {
            $db->prepare("INSERT INTO telegram_users (user_id, employee_name, chat_id, is_active)
                          VALUES (?,?,?,1)
                          ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), employee_name = VALUES(employee_name), is_active = 1")
               ->execute([$uid, $cname, $cid]);
            $msg = "已綁定：{$cname} ↔ chat_id {$cid}";
        }
    } catch (Throwable $e) {
        $msg = '綁定失敗：' . $e->getMessage(); $msgType = 'err';
    }
}

// 停用 / 啟用 / 刪除綁定
if (isset($_POST['toggle_id'])) {
    $db->prepare("UPDATE telegram_users SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['toggle_id']]);
    $msg = '已切換啟用狀態';
}
if (isset($_POST['delete_id'])) {
    $db->prepare("DELETE FROM telegram_users WHERE id = ?")->execute([(int)$_POST['delete_id']]);
    $msg = '已刪除綁定';
}

// 發送測試訊息
if (isset($_POST['test_chat_id']) && $_POST['test_chat_id'] !== '') {
    $r = tg_send_text(trim($_POST['test_chat_id']),
        "🔔 <b>EGsystem 測試訊息</b>\n這是一則 Telegram 推播測試，收到代表綁定成功。", $db, null);
    if ($r['ok']) { $msg = '測試訊息已送出，請確認手機是否收到'; }
    else { $msg = '發送失敗，請查看伺服器 error_log（php_error.log）'; $msgType = 'err'; }
}

// 驗證 Token 是否有效（getMe 成功會回 bot 資訊）
$botInfo = null; $botErr = '';
if (tg_is_configured()) {
    $gm = tg_api('getMe');
    if (!empty($gm['ok'])) $botInfo = $gm['result'];
    else $botErr = $gm['description'] ?? '未知錯誤';
}

// 讀取 bot 最近收到的訊息（供查 chat_id）：
// 輪詢機制會把 Telegram 上的訊息收進 telegram_messages，故先收一輪再從紀錄表列出
$updates = [];
$updatesErr = '';
if (tg_is_configured()) {
    require_once __DIR__ . '/poll_core.php';
    $pr = tg_poll_process($db);
    if (empty($pr['ok']) && !empty($pr['msg'])) $updatesErr = '無法取得訊息：' . $pr['msg'];
    foreach ($db->query("SELECT sent_at, chat_id, employee_name, message_text FROM telegram_messages
                         WHERE direction = 'in' ORDER BY id DESC LIMIT 20") as $r) {
        $updates[] = [
            'chat_id' => $r['chat_id'],
            'name'    => $r['employee_name'] ?? '',
            'text'    => $r['message_text'] ?? '',
            'time'    => $r['sent_at'],
        ];
    }
}

// 既有綁定清單
$bindings = $db->query("SELECT t.*, u.user_cname FROM telegram_users t LEFT JOIN user u ON u.id = t.user_id ORDER BY t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
// 在職員工（綁定下拉用）
$users = $db->query("SELECT id, user_cname FROM user WHERE state NOT IN (0,90) ORDER BY user_cname")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telegram 綁定 / 測試工具</title>
    <style>
        body { font-family: "Microsoft JhengHei", sans-serif; background: #f4f7f9; color: #34495e; margin: 0; padding: 24px; }
        .wrap { max-width: 960px; margin: 0 auto; }
        h1 { font-size: 20px; color: #2A3F54; }
        h2 { font-size: 15px; color: #2A3F54; margin: 0 0 10px; }
        .card { background: #fff; border: 1px solid #e6ecf1; border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; }
        .ok  { background: #eefaf6; color: #169a80; border: 1px solid #bfe9dd; border-radius: 8px; padding: 9px 13px; margin-bottom: 14px; }
        .err { background: #fdecea; color: #c0392b; border: 1px solid #f5c6cb; border-radius: 8px; padding: 9px 13px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; color: #8a9bab; font-size: 12px; padding: 7px 9px; border-bottom: 2px solid #e6ecf1; }
        td { padding: 7px 9px; border-bottom: 1px solid #eef2f5; }
        input, select { border: 1px solid #e6ecf1; border-radius: 7px; padding: 7px 10px; font-size: 13px; }
        button { border: none; border-radius: 7px; padding: 7px 15px; font-size: 13px; font-weight: 600; cursor: pointer; background: #1ABB9C; color: #fff; }
        button:hover { background: #169a80; }
        button.ghost { background: #fff; color: #8a9bab; border: 1px solid #e6ecf1; }
        button.danger { background: #fdecea; color: #e74c3c; }
        .muted { color: #8a9bab; font-size: 12.5px; line-height: 1.7; }
        .badge-on  { color: #169a80; font-weight: 700; }
        .badge-off { color: #c0392b; font-weight: 700; }
        code { background: #f0f4f7; border-radius: 4px; padding: 1px 6px; }
        a.back { color: #169a80; text-decoration: none; font-size: 13px; }
    </style>
</head>
<body>
<div class="wrap">
    <p><a class="back" href="../views/liveEvent/createEvent.php">← 回公告 / 通知管理</a></p>
    <h1>📱 Telegram 綁定 / 測試工具</h1>

    <?php if ($msg) : ?><div class="<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="card">
        <h2>① Bot Token</h2>
        <?php if (tg_is_configured() && $botInfo) : ?>
            <p class="badge-on">✅ Token 有效，bot：<?= htmlspecialchars($botInfo['first_name'] ?? '') ?>（@<?= htmlspecialchars($botInfo['username'] ?? '') ?>）</p>
            <p class="muted">員工請在 Telegram 搜尋 <b>@<?= htmlspecialchars($botInfo['username'] ?? '') ?></b> 並傳送 <code>/start</code>，即可在下方第②區看到 chat_id。</p>
        <?php elseif (tg_is_configured()) : ?>
            <p class="badge-off">⚠️ Token 已設定但驗證失敗：<?= htmlspecialchars($botErr) ?>（可能複製不完整或已被 BotFather 重設，請重貼一次）</p>
        <?php else : ?>
            <p class="badge-off">❌ 尚未設定 Token</p>
            <p class="muted">
                取得方式：Telegram 搜尋 <b>@BotFather</b> → 傳 <code>/newbot</code> → 依指示命名 →
                把它回覆的 HTTP API Token（格式 <code>1234567890:AAExxx...</code>）貼到下方儲存即可，不需改任何程式檔。
            </p>
        <?php endif; ?>
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px;">
            <input type="text" name="bot_token" placeholder="貼上 BotFather 給的 HTTP API Token" style="width:380px;" autocomplete="off" required>
            <button type="submit" name="save_token" value="1"><?= tg_is_configured() ? '更換 Token' : '儲存 Token' ?></button>
        </form>
    </div>

    <div class="card">
        <h2>② Bot 最近收到的訊息（取得 chat_id 用）</h2>
        <p class="muted">員工需先在 Telegram 搜尋你的 bot 並傳送任意訊息（如 <code>/start</code>），訊息才會出現在下方。重新整理本頁可更新。</p>
        <?php if (!tg_is_configured()) : ?>
            <p class="muted">（設定 Token 後才能讀取）</p>
        <?php elseif ($updatesErr) : ?>
            <div class="err"><?= htmlspecialchars($updatesErr) ?></div>
        <?php elseif (empty($updates)) : ?>
            <p class="muted">目前沒有訊息。請先私訊 bot 再重新整理。（注意：訊息超過 24 小時可能被 Telegram 清掉）</p>
        <?php else : ?>
            <table>
                <tr><th>時間</th><th>chat_id</th><th>名稱（綁定者顯示員工名）</th><th>訊息</th></tr>
                <?php foreach ($updates as $u) : ?>
                    <tr>
                        <td><?= htmlspecialchars($u['time']) ?></td>
                        <td><code><?= htmlspecialchars($u['chat_id']) ?></code></td>
                        <td><?= htmlspecialchars($u['name']) ?></td>
                        <td><?= htmlspecialchars(mb_substr($u['text'], 0, 60)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>③ 綁定員工 ↔ chat_id</h2>
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select name="bind_user_id" required>
                <option value="">選擇員工</option>
                <?php foreach ($users as $u) : ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['user_cname']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="bind_chat_id" placeholder="chat_id（從上方複製）" required>
            <button type="submit" name="bind" value="1">綁定</button>
        </form>
    </div>

    <div class="card">
        <h2>④ 既有綁定</h2>
        <?php if (empty($bindings)) : ?>
            <p class="muted">尚無綁定。</p>
        <?php else : ?>
            <table>
                <tr><th>員工</th><th>chat_id</th><th>狀態</th><th>建立時間</th><th>操作</th></tr>
                <?php foreach ($bindings as $b) : ?>
                    <tr>
                        <td><?= htmlspecialchars($b['user_cname'] ?: $b['employee_name']) ?></td>
                        <td><code><?= htmlspecialchars($b['chat_id']) ?></code></td>
                        <td><?= $b['is_active'] ? '<span class="badge-on">啟用</span>' : '<span class="badge-off">停用</span>' ?></td>
                        <td class="muted"><?= htmlspecialchars($b['created_at']) ?></td>
                        <td style="white-space:nowrap;">
                            <form method="POST" style="display:inline;"><button class="ghost" name="toggle_id" value="<?= $b['id'] ?>"><?= $b['is_active'] ? '停用' : '啟用' ?></button></form>
                            <form method="POST" style="display:inline;"><button class="ghost" name="test_chat_id" value="<?= htmlspecialchars($b['chat_id']) ?>">發測試訊息</button></form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('確定刪除此綁定？');"><button class="danger" name="delete_id" value="<?= $b['id'] ?>">刪除</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>⑤ 運作方式</h2>
        <p class="muted">
            發布或更新「公告 / 通知」時，系統照常發送 Web Push，並<b>同時</b>對「公告對象中已綁定 Telegram 的員工」發送 bot 私訊；
            未綁定者自動跳過，Token 未設定時整個 Telegram 推播靜默略過，完全不影響現行公告與 Web Push。<br><br>
            <b>已閱 / 回簽 / 回覆：</b>訊息會依通知方式附「✅ 已閱確認」或「✍️ 回簽確認」按鈕，點了直接寫回系統（與網頁操作同一套紀錄）；
            需回覆的通知可<b>長按該則訊息選「回覆」</b>輸入文字，系統會記錄回覆內容並同時完成回簽（附件仍需至系統頁面上傳）。
            回收機制為「順路輪詢」：有人開 ERP 任何頁面時，背景啟動一支駐留 5 分鐘的長輪詢程序——駐留期間按鈕點擊約 1 秒有反應；
            超過 5 分鐘沒人用 ERP 則程序結束，下次有人開頁面再啟動（期間的點擊/回覆不會遺失，啟動後補收）。半夜無人用系統時，回覆會等到隔天有人開頁面才入帳。
        </p>
    </div>
</div>
</body>
</html>
