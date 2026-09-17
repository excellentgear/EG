<?php
/**
 * 公告 / 通知：「免點開自動已閱」的共用實作（唯一實作，禁止各檔各寫一份）
 *
 * 這裡放三件事：
 *   1. 某則通知「每個收件人實際生效的通知方式」（誰命中哪些對象列 → eg_notice_mode_pick）
 *      —— 原本只寫在 _eventQuickRead.php 裡，現在快速已閱與「設為自動已閱」共用同一份判定。
 *   2. 代寫已閱紀錄（live_event_for_user）的唯一寫入點。
 *   3. 來源層級的全站規則：指定的來源往後發出的通知，對象不必點開就視為已閱。
 *
 * 來源規則刻意採「懶惰補寫」：不在通知建立時處理（全站有 31 支程式各自 INSERT live_event_target，
 * 沒有單一收斂點），而是在側邊欄鈴鐺與手機通知頁查詢「之前」補寫『本人』的已閱紀錄。
 * 這樣做的三個好處：
 *   - 只要改兩個消費端，不必動 31 支發通知的程式；
 *   - 已閱紀錄是真的寫進資料表，所以列表的「已讀 / 應讀」、快速已閱面板、統計全部自然一致；
 *   - 使用者永遠看不到那則未讀（鈴鐺渲染前就標好了）＝等同「發出即已閱」。
 *
 * 兩條界線（後端一律再擋一次，鐵律8）：
 *   - 通知方式是「回簽」或「回覆 + 回簽」的對象一律不適用（那要本人的意思表示，不是有沒有看到）。
 *   - 來源規則只對「該來源被加入規則之後才發布」的通知生效（每個來源各自記啟用時間），
 *     避免一勾下去就回頭把幾年份的歷史通知全部標成已閱。既有積壓請用列表的「設為自動已閱」處理。
 */

require_once __DIR__ . '/notice_mode_lib.php';

if (!defined('EG_NOTICE_AUTOREAD_KEY')) {
    define('EG_NOTICE_AUTOREAD_KEY', 'notice_autoread_sources');
}
/** signed_via 的特殊值：0 ＝ 系統依來源規則自動標記（NULL ＝ 本人自己按，>0 ＝ 某人代按／經由共用帳號） */
if (!defined('EG_NOTICE_VIA_SYSTEM')) {
    define('EG_NOTICE_VIA_SYSTEM', 0);
}

/**
 * 某則通知裡，每位收件人實際生效的通知方式
 * @param array $uids 要算的人（通常是 eg_push_event_recipients() 展開的結果）
 * @return array uid => 'autoread'|'read'|'sign'|'reply'（沒命中任何對象列的人不會出現）
 */
if (!function_exists('eg_notice_recipient_modes')) {
    function eg_notice_recipient_modes(PDO $db, int $eid, array $uids): array
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (empty($uids)) return [];
        $in = implode(',', $uids);

        $st = $db->prepare("SELECT target_type, target_id, mode FROM live_event_target WHERE live_event_id = ?");
        $st->execute([$eid]);
        $targets = $st->fetchAll(PDO::FETCH_ASSOC);
        if (empty($targets)) return [];

        // 職稱（含兼任 status2/status3）與部門（含兼任）
        $statusOf = [];
        foreach ($db->query("SELECT id, user_status, user_status2, user_status3 FROM `user` WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $vals = array_filter([$u['user_status'], $u['user_status2'], $u['user_status3']], function ($v) { return $v !== null && $v !== ''; });
            $statusOf[(int)$u['id']] = array_map('intval', array_values($vals));
        }
        $deptOf = [];
        foreach ($db->query("SELECT user_id, department_id FROM user_department_position_map WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $deptOf[(int)$m['user_id']][] = (int)$m['department_id'];
        }

        $out = [];
        foreach ($uids as $uid) {
            $myStatus = $statusOf[$uid] ?? [];
            $myDept   = $deptOf[$uid] ?? [];
            $modes = [];
            foreach ($targets as $t) {
                $tid = (int)$t['target_id'];
                $hit = ($t['target_type'] === 'all')
                    || ($t['target_type'] === 'status' && in_array($tid, $myStatus, true))
                    || ($t['target_type'] === 'dept'   && in_array($tid, $myDept, true))
                    || ($t['target_type'] === 'user'   && $tid === $uid);
                if ($hit) $modes[] = $t['mode'];
            }
            if ($modes) $out[$uid] = eg_notice_mode_pick($modes);
        }
        return $out;
    }
}

/**
 * 某則通知目前的已閱狀況
 * @return array uid => read_at（live_event_for_user 或 live_event_response 其一有紀錄就算已閱）
 */
if (!function_exists('eg_notice_read_map')) {
    function eg_notice_read_map(PDO $db, int $eid): array
    {
        $out = [];
        $st = $db->prepare("SELECT user_id, read_at FROM live_event_for_user WHERE oready_read = 1 AND live_event_id = ?");
        $st->execute([$eid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['user_id']] = $r['read_at'];
        $st = $db->prepare("SELECT user_id, read_at FROM live_event_response WHERE read_at IS NOT NULL AND live_event_id = ?");
        $st->execute([$eid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($out[(int)$r['user_id']])) $out[(int)$r['user_id']] = $r['read_at'];
        }
        return $out;
    }
}

/**
 * 寫入已閱紀錄（唯一寫入點；資格判定由呼叫端負責，這裡只管寫）
 * @param int|null $byUid 代按者的 user_id；EG_NOTICE_VIA_SYSTEM(0) ＝ 系統依來源規則自動標記
 * @return int 實際處理的人數
 */
if (!function_exists('eg_notice_mark_read')) {
    function eg_notice_mark_read(PDO $db, int $eid, array $uids, $byUid = null): int
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (empty($uids)) return 0;
        $chk = $db->prepare("SELECT id FROM live_event_for_user WHERE user_id = ? AND live_event_id = ? LIMIT 1");
        $ins = $db->prepare("INSERT INTO live_event_for_user (user_id, live_event_id, oready_read, read_at, signed_via) VALUES (?,?,1,NOW(),?)");
        $upd = $db->prepare("UPDATE live_event_for_user SET oready_read = 1, read_at = COALESCE(read_at, NOW()), signed_via = COALESCE(signed_via, ?) WHERE id = ?");
        $n = 0;
        foreach ($uids as $u) {
            $chk->execute([$u, $eid]);
            $exist = $chk->fetchColumn();
            if ($exist) $upd->execute([$byUid, (int)$exist]);
            else        $ins->execute([$u, $eid, $byUid]);
            $n++;
        }
        return $n;
    }
}

/* ===================== 來源層級規則（全站） ===================== */

/**
 * 目前設定的來源規則
 * @return array source => since（'Y-m-d H:i:s'，該來源被加入規則的時間）
 */
if (!function_exists('eg_notice_autoread_sources')) {
    function eg_notice_autoread_sources(PDO $db, bool $fresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$fresh) return $cache;
        $cache = [];
        try {
            $v = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = '" . EG_NOTICE_AUTOREAD_KEY . "' LIMIT 1")->fetchColumn();
            $arr = json_decode((string)$v, true);
            if (is_array($arr)) {
                foreach ($arr as $src => $since) {
                    if (!is_string($src) || $src === '') continue;
                    $cache[$src] = is_string($since) && $since !== '' ? $since : date('Y-m-d H:i:s');
                }
            }
        } catch (Throwable $e) { /* 表不在或欄位不同一律當成沒設定 */ }
        return $cache;
    }
}

/** 儲存來源規則（已在清單內的來源保留原啟用時間，新加入的以現在為準） */
if (!function_exists('eg_notice_autoread_sources_save')) {
    function eg_notice_autoread_sources_save(PDO $db, array $sources, int $uid, string $opName): array
    {
        $old = eg_notice_autoread_sources($db);
        $now = $db->query("SELECT NOW()")->fetchColumn();   // 時間一律取 DB 的（PHP 是 UTC，混用會差 8 小時）
        $new = [];
        foreach ($sources as $s) {
            $s = mb_substr(trim((string)$s), 0, 50, 'UTF-8');
            if ($s === '') continue;
            $new[$s] = $old[$s] ?? $now;
            if (count($new) >= 100) break;
        }
        $json = json_encode($new, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                            VALUES (:k, :v, :uid, :name, NOW())
                            ON DUPLICATE KEY UPDATE setting_value = :v2, updated_by_id = :uid2, updated_by = :name2, updated_at = NOW()");
        $st->execute([
            ':k' => EG_NOTICE_AUTOREAD_KEY, ':v' => $json, ':uid' => $uid, ':name' => mb_substr($opName, 0, 100, 'UTF-8'),
            ':v2' => $json, ':uid2' => $uid, ':name2' => mb_substr($opName, 0, 100, 'UTF-8'),
        ]);
        eg_notice_autoread_sources($db, true);   // 同一請求內若再讀一次要拿到新值（刷掉靜態快取）
        return $new;
    }
}

/**
 * 懶惰補寫：把「來源在規則內、對象命中本人、通知方式非回簽/回覆、且本人尚未已閱」的通知直接標成已閱。
 * 呼叫點＝側邊欄鈴鐺與手機通知頁（查詢之前），一般帳號一次只處理自己。
 *
 * @return int 本次補寫的則數
 */
if (!function_exists('eg_notice_autoread_sync')) {
    function eg_notice_autoread_sync(PDO $db, int $uid): int
    {
        if ($uid <= 0) return 0;
        $rules = eg_notice_autoread_sources($db);
        if (empty($rules)) return 0;   // 沒設定＝零成本（最常見的情況，不多跑任何查詢）

        try {
            // 本人身分（職稱含兼任）與部門（含兼任）
            $statusIds = [-1];
            foreach (['status', 'status2', 'status3'] as $k) {
                if (isset($_SESSION[$k]) && $_SESSION[$k] !== '' && $_SESSION[$k] !== null) $statusIds[] = (int)$_SESSION[$k];
            }
            if (count($statusIds) === 1) {   // session 沒有就直接查（例如 API 端）
                $st = $db->prepare("SELECT user_status, user_status2, user_status3 FROM `user` WHERE id = ?");
                $st->execute([$uid]);
                foreach ((array)$st->fetch(PDO::FETCH_ASSOC) as $v) if ($v !== null && $v !== '') $statusIds[] = (int)$v;
            }
            $statusIn = implode(',', array_unique($statusIds));

            $deptIds = [-1];
            $st = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id = ?");
            $st->execute([$uid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d !== null && $d !== '') $deptIds[] = (int)$d;
            $deptIn = implode(',', array_unique($deptIds));

            // 來源 + 啟用時間（只對規則啟用之後發布的通知生效）
            $srcSql = []; $args = [];
            foreach ($rules as $src => $since) {
                $srcSql[] = "(le.source = ? AND COALESCE(le.created_at, le.eventdate) >= ?)";
                $args[] = $src; $args[] = $since;
            }
            $sql = "SELECT le.id, t.mode
                      FROM live_event le
                      JOIN live_event_target t ON t.live_event_id = le.id
                     WHERE (" . implode(' OR ', $srcSql) . ")
                       AND ( t.target_type = 'all'
                          OR (t.target_type = 'status' AND t.target_id IN ($statusIn))
                          OR (t.target_type = 'dept'   AND t.target_id IN ($deptIn))
                          OR (t.target_type = 'user'   AND t.target_id = ?) )
                       AND NOT EXISTS (SELECT 1 FROM live_event_for_user fu
                                        WHERE fu.live_event_id = le.id AND fu.user_id = ? AND fu.oready_read = 1)
                       AND NOT EXISTS (SELECT 1 FROM live_event_response r
                                        WHERE r.live_event_id = le.id AND r.user_id = ? AND r.read_at IS NOT NULL)";
            $args[] = $uid; $args[] = $uid; $args[] = $uid;
            $st = $db->prepare($sql);
            $st->execute($args);

            // 同一則可能命中多列對象（例如部門一列、指名一列）→ 取義務最重的那個（嚴格的贏）
            $modesOf = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $modesOf[(int)$r['id']][] = $r['mode'];
            $n = 0;
            foreach ($modesOf as $eid => $modes) {
                $mode = eg_notice_mode_pick($modes);
                if ($mode === 'sign' || $mode === 'reply') continue;   // 要本人表態的一律不代標
                $n += eg_notice_mark_read($db, (int)$eid, [$uid], EG_NOTICE_VIA_SYSTEM);
            }
            return $n;
        } catch (Throwable $e) {
            error_log('[notice autoread sync] ' . $e->getMessage());
            return 0;   // 補寫失敗不可以影響鈴鐺本身
        }
    }
}
