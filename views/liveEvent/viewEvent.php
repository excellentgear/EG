<?php
// =============================================================================
// views/liveEvent/viewEvent.php — 公告 / 通知 檢視畫面（電腦版，規格 四之四）
//   - 左右分欄：左＝公告完整內容，右＝附件顯示區
//   - 「附件 2 件（含）以下直接顯示」勾選（live_event.show_attach_inline）生效：
//       勾選且符合 → 右欄直接展開附件（使用角落標註快取版，標籤與備注已印在檔上）
//       未勾選或超過 2 件 → 附件清單（檔名＋標籤＋備注摘要），點擊以燈箱開啟、可另開新分頁
//   - 授權：管理者(all)，或與此公告相關者（建立者 / 共同編輯者 / 通知對象），與列表的相關性判定一致
// 進入點：公告/通知管理列表點標題（開新分頁）；URL: viewEvent.php?event={id}
// =============================================================================
session_start();
if (!isset($_SESSION['id'])) {
    header("Location:../../index.php");
    exit();
}
include '../../src/common/DBConnection.php';
require_once '../../src/common/rbac.php';
require_once '../../src/common/attachment_lib.php';

$conn = new DBConnection();
$db = $conn->getPDO();
$uid = (int)$_SESSION['id'];
$eventId = (int)($_GET['event'] ?? 0);

function ve_deny($msg) { http_response_code(403); echo '<meta charset="utf-8">' . htmlspecialchars($msg); exit(); }

if ($eventId <= 0) ve_deny('參數錯誤');
try { eg_att_ensure_schema($db); } catch (Throwable $e) {}

$st = $db->prepare("SELECT le.*, u.user_cname AS creator_name FROM live_event le LEFT JOIN `user` u ON u.id = le.created_by WHERE le.id = ?");
$st->execute([$eventId]);
$ev = $st->fetch(PDO::FETCH_ASSOC);
if (!$ev) ve_deny('找不到此公告');

// ── 授權：管理者 / 建立者 / 共同編輯者 / 通知對象（全體、身分、部門、個人）──
$features = rbac_user_features($db, $uid);
$allowed = rbac_has($features, 'all') || (int)$ev['created_by'] === $uid;
if (!$allowed) {
    $statusIds = [-1];
    $urow = $db->query("SELECT user_status, user_status2, user_status3 FROM `user` WHERE id = $uid")->fetch(PDO::FETCH_ASSOC);
    foreach ((array)$urow as $v) { if ($v !== null && $v !== '') $statusIds[] = (int)$v; }
    $deptIds = [-1];
    foreach ($db->query("SELECT department_id FROM user_department_position_map WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN) as $d) $deptIds[] = (int)$d;
    $stIn = implode(',', array_unique($statusIds));
    $dpIn = implode(',', array_unique($deptIds));
    $chk = $db->prepare("SELECT 1 FROM live_event_target t WHERE t.live_event_id = ? AND (
                            t.target_type = 'all'
                            OR (t.target_type = 'status' AND t.target_id IN ($stIn))
                            OR (t.target_type = 'dept'   AND t.target_id IN ($dpIn))
                            OR (t.target_type = 'user'   AND t.target_id = $uid)) LIMIT 1");
    $chk->execute([$eventId]);
    $allowed = (bool)$chk->fetchColumn();
    if (!$allowed) {
        $chk = $db->prepare("SELECT 1 FROM live_event_editor WHERE live_event_id = ? AND ((editor_type='user' AND editor_id = $uid) OR (editor_type='dept' AND editor_id IN ($dpIn))) LIMIT 1");
        $chk->execute([$eventId]);
        $allowed = (bool)$chk->fetchColumn();
    }
}
if (!$allowed) ve_deny('您不是此公告的對象，無法檢視');

// ── 附件（含標籤名稱與備注）──
$files = [];
try {
    $st = $db->prepare("SELECT f.*, t.name AS tag_name FROM live_event_file f
                        LEFT JOIN attachment_tags t ON t.id = f.tag_id
                        WHERE f.live_event_id = ? ORDER BY f.id");
    $st->execute([$eventId]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
foreach ($files as &$f) {
    $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
    $f['ext'] = $ext;
    $f['viewable'] = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf'], true);
    $f['is_img'] = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
}
unset($f);

// 直接顯示條件：勾選 + 附件 1~2 件 + 皆可預覽
$inline = (int)($ev['show_attach_inline'] ?? 0) === 1 && count($files) > 0 && count($files) <= 2
       && !array_filter($files, function ($f) { return !$f['viewable']; });

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($ev['title']) ?> - 公告檢視</title>
    <link href="../../resource/css/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <style>
        :root { --dark:#2A3F54; --line:#e3e9ef; --muted:#8a97a5; --accent:#1ABB9C; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:"Microsoft JhengHei","Helvetica Neue",sans-serif; background:#eef2f6; color:#333; }
        .ve-top { background:var(--dark); color:#fff; padding:12px 22px; display:flex; justify-content:space-between; align-items:center; }
        .ve-top h1 { margin:0; font-size:17px; font-weight:700; }
        .ve-top .no { color:rgba(255,255,255,.75); font-size:13px; margin-left:10px; }
        .ve-wrap { display:flex; gap:16px; padding:16px 22px; align-items:flex-start; }
        .ve-left { flex:1 1 46%; min-width:340px; background:#fff; border:1px solid var(--line); border-radius:8px; padding:18px 22px; }
        .ve-right { flex:1 1 54%; min-width:340px; }
        .ve-meta { display:flex; flex-wrap:wrap; gap:6px 22px; font-size:13px; color:var(--muted); border-bottom:1px solid var(--line); padding-bottom:10px; margin-bottom:14px; }
        .ve-meta b { color:#4a5a68; font-weight:600; }
        .ve-title { font-size:20px; font-weight:700; color:var(--dark); margin:0 0 10px; }
        .ve-content { white-space:pre-line; line-height:1.75; font-size:14.5px; word-break:break-word; }
        .ve-card { background:#fff; border:1px solid var(--line); border-radius:8px; margin-bottom:14px; overflow:hidden; }
        .ve-card-head { padding:10px 16px; font-size:14px; font-weight:700; color:var(--dark); background:#f5f8fb; border-bottom:1px solid var(--line); }
        .ve-att-frame { width:100%; height:70vh; border:0; display:block; background:#525659; }
        .ve-att-img { display:block; max-width:100%; margin:0 auto; cursor:zoom-in; }
        .ve-att-bar { display:flex; justify-content:space-between; align-items:center; padding:7px 14px; font-size:12.5px; color:var(--muted); background:#fbfcfe; border-top:1px solid var(--line); }
        .ve-list { list-style:none; margin:0; padding:0; }
        .ve-list li { display:flex; align-items:center; gap:10px; padding:11px 16px; border-bottom:1px solid var(--line); }
        .ve-list li:last-child { border-bottom:0; }
        .ve-list .nm { flex:1; min-width:0; }
        .ve-list .nm a { color:#1f5e94; text-decoration:none; font-weight:600; word-break:break-all; cursor:pointer; }
        .ve-list .nm a:hover { text-decoration:underline; }
        .ve-list .desc { display:block; font-size:12px; color:var(--muted); margin-top:2px; word-break:break-all; }
        .ve-tag { background:#e2eefe; color:#1e508c; border:1px solid #a0c3eb; border-radius:10px; padding:1px 9px; font-size:11.5px; white-space:nowrap; }
        .ve-open { font-size:12px; color:var(--muted); text-decoration:none; white-space:nowrap; }
        .ve-open:hover { color:var(--accent); }
        .ve-empty { padding:34px; text-align:center; color:var(--muted); }
        .ve-btn { background:rgba(255,255,255,.14); color:#fff; border:1px solid rgba(255,255,255,.35); border-radius:6px; padding:5px 14px; font-size:13px; cursor:pointer; text-decoration:none; }
        .ve-btn:hover { background:rgba(255,255,255,.25); }
        /* 燈箱 */
        #veLb { position:fixed; inset:0; background:rgba(20,30,42,.88); z-index:1000; display:none; align-items:center; justify-content:center; padding:26px; }
        #veLb.on { display:flex; }
        #veLb img { max-width:94vw; max-height:90vh; box-shadow:0 8px 40px rgba(0,0,0,.6); }
        #veLb iframe { width:92vw; height:90vh; border:0; background:#fff; }
        #veLbClose { position:fixed; top:14px; right:20px; color:#fff; font-size:30px; cursor:pointer; z-index:1001; text-decoration:none; }
        @media (max-width: 900px) { .ve-wrap { flex-direction:column; } }
    </style>
</head>
<body>
    <div class="ve-top">
        <h1><i class="fa fa-bullhorn"></i> 公告 / 通知檢視
            <?php if (!empty($ev['event_no'])) : ?><span class="no"><?= $h($ev['event_no']) ?></span><?php endif; ?>
        </h1>
        <a href="javascript:window.close();" class="ve-btn" onclick="if(history.length>1&&window.opener==null){history.back();return false;}"><i class="fa fa-times"></i> 關閉</a>
    </div>
    <div class="ve-wrap">
        <!-- 左：公告內容 -->
        <div class="ve-left">
            <div class="ve-title"><?= $h($ev['title']) ?></div>
            <div class="ve-meta">
                <span><b>來源</b>　<?= $h($ev['source'] ?: '—') ?></span>
                <span><b>公告者</b>　<?= $h($ev['creator_name'] ?: '—') ?></span>
                <span><b>發布</b>　<?= $h($ev['eventdate']) ?><?= !empty($ev['created_at']) ? ' ' . $h(substr($ev['created_at'], 11, 5)) : '' ?></span>
                <?php if (!empty($ev['enddate'])) : ?><span><b>結束</b>　<?= $h($ev['enddate']) ?></span><?php endif; ?>
                <?php if (!empty($ev['reply_deadline'])) : ?><span><b>回覆/回簽期限</b>　<?= $h($ev['reply_deadline']) ?></span><?php endif; ?>
            </div>
            <div class="ve-content"><?= $h($ev['content']) ?></div>
        </div>

        <!-- 右：附件顯示區 -->
        <div class="ve-right">
            <?php if (empty($files)) : ?>
                <div class="ve-card"><div class="ve-card-head"><i class="fa fa-paperclip"></i> 附件</div><div class="ve-empty"><i class="fa fa-inbox"></i> 此公告沒有附件</div></div>
            <?php elseif ($inline) : ?>
                <?php foreach ($files as $f) : $src = '../../src/store/_eventFile.php?t=p&id=' . (int)$f['id']; ?>
                    <!-- 直接顯示：使用角落標註快取版（標籤與備注已印在附件上，不另外顯示文字說明） -->
                    <div class="ve-card">
                        <?php if ($f['is_img']) : ?>
                            <img class="ve-att-img ve-lb" src="<?= $src ?>" data-kind="img" data-src="<?= $src ?>" alt="<?= $h($f['file_name']) ?>" title="點擊放大">
                        <?php else : ?>
                            <iframe class="ve-att-frame" src="<?= $src ?>"></iframe>
                        <?php endif; ?>
                        <div class="ve-att-bar">
                            <span><i class="fa fa-paperclip"></i> <?= $h($f['file_name']) ?></span>
                            <a class="ve-open" href="<?= $src ?>" target="_blank" title="以新分頁開啟"><i class="fa fa-external-link"></i> 新分頁</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="ve-card">
                    <div class="ve-card-head"><i class="fa fa-paperclip"></i> 附件（<?= count($files) ?>）</div>
                    <ul class="ve-list">
                        <?php foreach ($files as $f) : $src = '../../src/store/_eventFile.php?t=p&id=' . (int)$f['id']; ?>
                            <li>
                                <span class="ve-tag"><?= $h($f['tag_name'] ?: '未分類') ?></span>
                                <span class="nm">
                                    <?php if ($f['viewable']) : ?>
                                        <a class="ve-lb" data-kind="<?= $f['is_img'] ? 'img' : 'pdf' ?>" data-src="<?= $src ?>"><?= $h($f['file_name']) ?></a>
                                    <?php else : ?>
                                        <a href="../../src/store/_eventFile.php?t=e&id=<?= (int)$f['id'] ?>" target="_blank"><?= $h($f['file_name']) ?></a>
                                    <?php endif; ?>
                                    <?php if (!empty($f['description'])) : ?><span class="desc"><?= $h(mb_substr($f['description'], 0, 60, 'UTF-8')) ?><?= mb_strlen($f['description'], 'UTF-8') > 60 ? '…' : '' ?></span><?php endif; ?>
                                </span>
                                <a class="ve-open" href="<?= $f['viewable'] ? $src : ('../../src/store/_eventFile.php?t=e&id=' . (int)$f['id'] . '&dl=1') ?>" target="_blank" title="以新分頁開啟 / 下載"><i class="fa fa-external-link"></i></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 燈箱（lightbox） -->
    <div id="veLb"><a id="veLbClose" href="javascript:;">&times;</a><div id="veLbBody"></div></div>
    <script>
        (function () {
            var lb = document.getElementById('veLb');
            var body = document.getElementById('veLbBody');
            function open(kind, src) {
                body.innerHTML = kind === 'img'
                    ? '<img src="' + src + '" alt="">'
                    : '<iframe src="' + src + '"></iframe>';
                lb.classList.add('on');
            }
            function close() { lb.classList.remove('on'); body.innerHTML = ''; }
            document.addEventListener('click', function (e) {
                var t = e.target.closest ? e.target.closest('.ve-lb') : null;
                if (t) { e.preventDefault(); open(t.getAttribute('data-kind') || 'img', t.getAttribute('data-src')); return; }
                if (e.target === lb || e.target.id === 'veLbClose') close();
            });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        })();
    </script>
</body>
</html>
