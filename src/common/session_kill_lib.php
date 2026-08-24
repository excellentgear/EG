<?php
/**
 * session_kill_lib.php — 強制登出（切斷登入中的連線）共用庫
 *
 * 為什麼是「刪 session 檔」而不是「資料庫加旗標」：
 *   本站有 342 支檔案自己呼叫 session_start()，其中 84 支沒有載入 _config.php；
 *   src/store 底下 51 支 API 也不會渲染側欄（拿不到 eg_guard_active_session 的保護）。
 *   把「你被登出了」寫成 DB 旗標、再靠共用檔逐一檢查，這些入口就會繞過去。
 *   直接刪掉伺服器端的 session 檔則沒有這個問題——檔案沒了，所有入口一視同仁失效。
 *
 * 與 user_active_lib.php 的分工：
 *   - user_active_lib：判斷「這個人現在還能不能用系統」（離職/留停封鎖），是持續性的狀態；
 *   - 本庫：把「已經連上線的那條連線」立刻切斷，是一次性的動作。
 *   兩者互補：離職自動封鎖負責「之後進不來」，本庫負責「現在就出去」。
 *
 * 提供：
 *   eg_session_dir()                     取得 session 檔實際存放目錄（處理 "N;/path" 分層格式）
 *   eg_session_scan()                    掃描所有 session 檔，解析出各屬於哪個使用者
 *   eg_session_online_map()              [user_id => 最後活動時間戳]，給列表顯示在線狀態
 *   eg_session_kill_user($pdo,$uid,...)  刪掉某人的所有 session 檔（回傳刪除數）
 *   eg_session_kill_all($pdo,...)        一鍵登出所有人（預設保留操作者自己）
 */

if (!function_exists('eg_session_dir')) {
    /**
     * session 檔實際存放目錄。
     * session.save_path 可能是 "/path"、"N;/path" 或 "N;MODE;/path"（N=雜湊分層深度）。
     * @return array|null ['dir'=>目錄, 'depth'=>分層深度]；解析不出來回 null
     */
    function eg_session_dir() {
        $sp = (string)session_save_path();
        if ($sp === '') $sp = (string)ini_get('session.save_path');
        if ($sp === '') return null;

        $depth = 0;
        if (strpos($sp, ';') !== false) {
            $head  = substr($sp, 0, strpos($sp, ';'));
            if (ctype_digit(trim($head))) $depth = (int)trim($head);
            $sp = substr($sp, strrpos($sp, ';') + 1);
        }
        $sp = trim($sp, " \t\n\r\0\x0B\"'");
        $sp = rtrim($sp, "\\/");
        if ($sp === '' || !is_dir($sp)) return null;
        return ['dir' => $sp, 'depth' => $depth];
    }
}

if (!function_exists('eg_session_files')) {
    /** 列出所有 session 檔的絕對路徑（含分層子目錄）。刻意只認 sess_ 開頭，不碰目錄下的其他檔案 */
    function eg_session_files($dir, $depth = 0, $_level = 0) {
        $out = [];
        $dh = @opendir($dir);
        if (!$dh) return $out;
        while (($f = readdir($dh)) !== false) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_dir($p)) {
                // 分層模式下 session 檔散在單字元子目錄裡，往下找但限制深度避免亂爬
                if ($_level < $depth) $out = array_merge($out, eg_session_files($p, $depth, $_level + 1));
                continue;
            }
            if (strncmp($f, 'sess_', 5) === 0) $out[] = $p;
        }
        closedir($dh);
        return $out;
    }
}

if (!function_exists('eg_session_uid_of')) {
    /**
     * 解析 session 檔屬於哪個使用者。
     * PHP 預設 serialize_handler 格式為 `名稱|序列化值` 連續串接，故 id 前面必是字串開頭或前一個值的結尾（; 或 }）。
     * 不可只 strpos('id|i:')——`user_id|i:` 之類的鍵會誤判。
     * 大 session 檔可達 600KB，先讀前段就好，找不到才整檔讀。
     */
    function eg_session_uid_of($file) {
        $head = @file_get_contents($file, false, null, 0, 8192);
        if ($head === false) return 0;
        if (preg_match('/(?:^|[;}])id\|i:(\d+);/', $head, $m)) return (int)$m[1];
        if (filesize($file) > 8192) {
            $all = @file_get_contents($file);
            if ($all !== false && preg_match('/(?:^|[;}])id\|i:(\d+);/', $all, $m)) return (int)$m[1];
        }
        return 0;
    }
}

if (!function_exists('eg_session_scan')) {
    /**
     * 掃描所有 session。
     * @return array [['file'=>路徑,'sid'=>sessionId,'uid'=>使用者id,'mtime'=>最後活動,'size'=>位元組], ...]
     *   uid=0 代表尚未登入（例如只開過登入頁）的 session
     */
    function eg_session_scan() {
        $d = eg_session_dir();
        if (!$d) return [];
        $out = [];
        foreach (eg_session_files($d['dir'], $d['depth']) as $f) {
            $out[] = [
                'file'  => $f,
                'sid'   => substr(basename($f), 5),
                'uid'   => eg_session_uid_of($f),
                'mtime' => (int)@filemtime($f),
                'size'  => (int)@filesize($f),
            ];
        }
        return $out;
    }
}

if (!function_exists('eg_session_online_map')) {
    /**
     * [user_id => 最後活動時間戳]（同一人多個 session 取最新的）。
     * 註：_config.php 每次請求會 touch session 檔，故 mtime≒最後活動時間；
     *     但有 84 支入口沒載入 _config.php，那些請求只有在 session 內容有變時才更新 mtime，
     *     所以這個時間是「不早於」實際活動時間的近似值，不能當出勤紀錄用。
     */
    function eg_session_online_map() {
        $map = [];
        foreach (eg_session_scan() as $s) {
            if ($s['uid'] <= 0) continue;
            if (!isset($map[$s['uid']]) || $s['mtime'] > $map[$s['uid']]) $map[$s['uid']] = $s['mtime'];
        }
        return $map;
    }
}

if (!function_exists('eg_session_audit')) {
    /** 寫稽核（比照 user_leave_tick.php 的欄位用法）。稽核失敗不可讓主動作跟著失敗 */
    function eg_session_audit($pdo, $action, $targetId, $targetName, array $changes, $operatorId, $operatorName) {
        try {
            $st = $pdo->prepare(
                "INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                 VALUES (?, 'user', ?, ?, ?, ?, ?, NOW())");
            $st->execute([
                $action, (string)$targetId, (string)$targetName,
                json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                $operatorId ?: null, (string)$operatorName,
            ]);
        } catch (Throwable $e) {
            error_log('[session_kill] audit failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_session_kill_user')) {
    /**
     * 強制登出某人：刪掉他所有的 session 檔。
     * @param array $opt ['reason'=>原因, 'operator_id'=>操作者id, 'operator'=>操作者姓名,
     *                    'keep_sid'=>不刪這個 sessionId, 'audit'=>是否寫稽核(預設true)]
     * @return array ['killed'=>刪除數, 'name'=>對象姓名]
     */
    function eg_session_kill_user($pdo, $uid, array $opt = []) {
        $uid = (int)$uid;
        if ($uid <= 0) return ['killed' => 0, 'name' => ''];

        $name = '';
        try {
            $st = $pdo->prepare("SELECT user_cname FROM `user` WHERE id=? LIMIT 1");
            $st->execute([$uid]);
            $name = (string)$st->fetchColumn();
        } catch (Throwable $e) {}

        $keep   = isset($opt['keep_sid']) ? (string)$opt['keep_sid'] : '';
        $killed = 0;
        foreach (eg_session_scan() as $s) {
            if ($s['uid'] !== $uid) continue;
            if ($keep !== '' && $s['sid'] === $keep) continue;
            if (@unlink($s['file'])) $killed++;
            else error_log('[session_kill] unlink failed: ' . $s['file']);
        }

        if ($killed > 0 && ($opt['audit'] ?? true)) {
            eg_session_audit($pdo, 'SESSION_KILL', $uid, $name, [
                'killed_sessions' => $killed,
                'reason'          => (string)($opt['reason'] ?? ''),
            ], $opt['operator_id'] ?? null, $opt['operator'] ?? 'system');
        }
        return ['killed' => $killed, 'name' => $name];
    }
}

if (!function_exists('eg_session_kill_all')) {
    /**
     * 一鍵登出所有人（系統維護、還原資料庫前清場用）。
     * 預設保留操作者自己的 session——否則按下去的人自己也被踢出，看不到結果也無法繼續維護。
     * @param array $opt ['reason'=>, 'operator_id'=>, 'operator'=>, 'keep_sid'=>, 'include_self'=>bool]
     * @return array ['killed'=>刪除數, 'users'=>受影響人數]
     */
    function eg_session_kill_all($pdo, array $opt = []) {
        $keep = (!empty($opt['include_self'])) ? '' : (string)($opt['keep_sid'] ?? '');
        $killed = 0; $uids = [];
        foreach (eg_session_scan() as $s) {
            if ($keep !== '' && $s['sid'] === $keep) continue;
            if (@unlink($s['file'])) {
                $killed++;
                if ($s['uid'] > 0) $uids[$s['uid']] = 1;
            } else {
                error_log('[session_kill] unlink failed: ' . $s['file']);
            }
        }
        if ($killed > 0) {
            eg_session_audit($pdo, 'SESSION_KILL_ALL', 0, '（全部使用者）', [
                'killed_sessions' => $killed,
                'affected_users'  => count($uids),
                'kept_self'       => $keep !== '',
                'reason'          => (string)($opt['reason'] ?? ''),
            ], $opt['operator_id'] ?? null, $opt['operator'] ?? 'system');
        }
        return ['killed' => $killed, 'users' => count($uids)];
    }
}
