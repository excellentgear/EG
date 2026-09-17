<?php
/**
 * 「設為自動已閱」（公告 / 通知管理頁工具列）
 *
 * 使用者拍板：一顆按鈕做兩件事
 *   1. 把勾選那幾則的通知方式由「已閱（要自己按）」改成「開啟自動已閱」（點開即視為已閱）；
 *   2. 順手把目前還沒讀的人全部標成已閱 —— 鈴鐺當下就少掉，誰都不必再點開。
 * 「回簽 / 回覆 + 回簽」的對象一律不動（那要本人的意思表示，不是有沒有看到），後端再擋一次（鐵律8）。
 *
 * 另含來源層級的全站規則（限系統管理員）：指定來源往後發出的通知一律免點開直接視為已閱，
 * 實際判定與補寫在 src/common/notice_autoread_lib.php（唯一實作）。
 *
 * action=check      ids[]           → 這次會改幾則、幾個對象、幾個人會被標已閱（點開即刷新，不採信畫面快取）
 * action=apply      ids[]           → 實際套用
 * action=src_list                   → 所有來源 + 目前規則（限系統管理員）
 * action=src_save   sources(JSON)   → 儲存規則（限系統管理員）
 */
header('Content-Type: application/json; charset=utf-8');

include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/notice_mode_lib.php';
require_once __DIR__ . '/../common/notice_event_lib.php';     // eg_notice_event_editable / eg_log_event_history
require_once __DIR__ . '/../common/notice_autoread_lib.php';  // 唯一實作
require_once __DIR__ . '/../push/push_send.php';              // eg_push_event_recipients()

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid      = (int)$_SESSION['id'];
$features = rbac_user_features($db, $uid);
$isAdmin  = rbac_has($features, 'all');
$opName   = (string)($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? ('user#' . $uid));
$action   = $_POST['action'] ?? $_GET['action'] ?? 'check';

/** 稽核紀錄（代別人留下已讀證據、或改動全站規則，都一定要留） */
function ear_audit(PDO $db, string $targetType, string $targetId, string $targetName, array $changes, int $uid, string $opName): void
{
    try {
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES ('update',?,?,?,?,?,?,NOW())")
           ->execute([$targetType, $targetId, mb_substr($targetName, 0, 190, 'UTF-8'),
                      json_encode($changes, JSON_UNESCAPED_UNICODE), $uid, $opName]);
    } catch (Throwable $e) { error_log('[autoRead] audit failed: ' . $e->getMessage()); }
}

try {
    /* ---------- 來源層級規則（限系統管理員） ---------- */
    if ($action === 'src_list' || $action === 'src_save') {
        if (!$isAdmin) { echo json_encode(['ok' => false, 'msg' => '僅系統管理員可設定來源規則']); exit(); }

        if ($action === 'src_save') {
            $list = json_decode((string)($_POST['sources'] ?? '[]'), true);
            $list = is_array($list) ? $list : [];
            $saved = eg_notice_autoread_sources_save($db, $list, $uid, $opName);
            ear_audit($db, 'notice_autoread_source', '0', '免點開自動已閱（來源規則）',
                      ['sources' => array_keys($saved)], $uid, $opName);
            echo json_encode(['ok' => true, 'rules' => $saved], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $rules = eg_notice_autoread_sources($db, true);
        $rows = [];
        foreach ($db->query("SELECT source, COUNT(*) c, MAX(eventdate) last_date FROM live_event
                             WHERE source IS NOT NULL AND source <> '' GROUP BY source ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'source'    => $r['source'],
                'count'     => (int)$r['c'],
                'last_date' => $r['last_date'],
                'on'        => isset($rules[$r['source']]),
                'since'     => $rules[$r['source']] ?? '',
            ];
        }
        // 規則裡有、但目前沒有任何通知的來源（模組改過名稱等）也要列出來，否則存檔會被洗掉
        foreach ($rules as $src => $since) {
            $found = false;
            foreach ($rows as $r) { if ($r['source'] === $src) { $found = true; break; } }
            if (!$found) $rows[] = ['source' => $src, 'count' => 0, 'last_date' => null, 'on' => true, 'since' => $since];
        }
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        exit();
    }

    /* ---------- 列表上的「設為自動已閱」 ---------- */
    if (!$isAdmin && !rbac_has($features, 'notice_edit')) {
        echo json_encode(['ok' => false, 'msg' => '無修改公告 / 通知的權限']);
        exit();
    }

    $ids = json_decode((string)($_POST['ids'] ?? $_GET['ids'] ?? '[]'), true);
    $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
    if (count($ids) < 1)   { echo json_encode(['ok' => false, 'msg' => '請先勾選要處理的公告 / 通知']); exit(); }
    if (count($ids) > 200) { echo json_encode(['ok' => false, 'msg' => '一次最多處理 200 則']); exit(); }

    $in = implode(',', $ids);
    $events = $db->query("SELECT id, title, source, created_by, ref_type, ref_id, eventdate FROM live_event WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($events as $e) $byId[(int)$e['id']] = $e;

    // 逐則權限（與「批次改對象」同一套規則）
    $blocked = [];
    foreach ($ids as $eid) {
        if (!isset($byId[$eid])) { $blocked[] = ['id' => $eid, 'title' => '（已不存在）', 'why' => '找不到此公告 / 通知']; continue; }
        [$ok, $why] = eg_notice_event_editable($db, $byId[$eid], $uid, $isAdmin, $features);
        if (!$ok) $blocked[] = ['id' => $eid, 'title' => $byId[$eid]['title'], 'why' => $why];
    }
    if ($blocked) {
        echo json_encode(['ok' => false, 'code' => 'BLOCKED', 'msg' => '有 ' . count($blocked) . ' 則不能修改', 'blocked' => $blocked], JSON_UNESCAPED_UNICODE);
        exit();
    }

    /** 這一則：要改成 autoread 的對象列、以及還沒讀又可以代標的人 */
    $plan = function (int $eid) use ($db, $isAdmin) {
        $st = $db->prepare("SELECT COUNT(*) FROM live_event_target WHERE live_event_id = ? AND mode = 'read'");
        $st->execute([$eid]);
        $modeRows = (int)$st->fetchColumn();

        $markUids = [];
        if ($isAdmin) {   // 代別人寫已讀紀錄一律限系統管理員（比照「快速已閱」）
            $recips = eg_push_event_recipients($db, $eid);
            if ($recips) {
                $modeOf = eg_notice_recipient_modes($db, $eid, $recips);
                $readOf = eg_notice_read_map($db, $eid);
                foreach ($modeOf as $ruid => $m) {
                    if ($m === 'sign' || $m === 'reply') continue;   // 要本人表態的不代標
                    if (!empty($readOf[$ruid])) continue;            // 已經讀過了
                    $markUids[] = (int)$ruid;
                }
            }
        }
        return ['mode_rows' => $modeRows, 'mark_uids' => $markUids];
    };

    if ($action === 'check') {
        $modeRows = 0; $markPeople = 0; $titles = [];
        foreach ($ids as $eid) {
            $p = $plan($eid);
            $modeRows += $p['mode_rows'];
            $markPeople += count($p['mark_uids']);
            $titles[] = ['id' => $eid, 'title' => $byId[$eid]['title'], 'eventdate' => $byId[$eid]['eventdate'],
                         'source' => $byId[$eid]['source'] ?: '', 'unread' => count($p['mark_uids'])];
        }
        echo json_encode([
            'ok' => true,
            'count'       => count($ids),
            'mode_rows'   => $modeRows,     // 會由「已閱」改成「開啟自動已閱」的對象列數
            'mark_people' => $markPeople,   // 會被直接標成已閱的人次
            'can_mark'    => $isAdmin,      // 非系統管理員只改通知方式，不代標已閱
            'titles'      => $titles,
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'apply') {
        $db->beginTransaction();
        $updMode = $db->prepare("UPDATE live_event_target SET mode = 'autoread' WHERE live_event_id = ? AND mode = 'read'");
        $modeRows = 0; $marked = 0; $detail = [];
        foreach ($ids as $eid) {
            $p = $plan($eid);                       // 先算再改（算出來的人要含「本來就是 autoread 卻沒讀」的）
            $updMode->execute([$eid]);
            $modeRows += $updMode->rowCount();
            $n = $p['mark_uids'] ? eg_notice_mark_read($db, $eid, $p['mark_uids'], $uid) : 0;
            $marked += $n;
            $detail[] = ['id' => $eid, 'title' => $byId[$eid]['title'], 'modes' => $updMode->rowCount(), 'marked' => $n];

            // 修改歷史（與單筆編輯／批次改對象同一張表，看得出是誰在什麼時候改的）
            try {
                eg_log_event_history($db, $eid, 'autoread', $uid,
                    null, ['設為自動已閱' => ['改為 autoread 的對象列' => $updMode->rowCount(), '代標已閱人數' => $n]]);
            } catch (Throwable $e) { error_log('[autoRead] history failed: ' . $e->getMessage()); }
        }
        if ($marked > 0) {
            ear_audit($db, 'notice_autoread', (string)count($ids), '設為自動已閱（' . count($ids) . ' 則）',
                      ['events' => $detail, 'marked_people' => $marked], $uid, $opName);
        }
        $db->commit();
        echo json_encode(['ok' => true, 'updated' => count($ids), 'mode_rows' => $modeRows, 'marked' => $marked], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode(['ok' => false, 'msg' => '無效的操作']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[autoRead] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
