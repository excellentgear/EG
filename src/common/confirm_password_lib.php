<?php
/**
 * 操作確認密碼（簽章密碼）—— 全系統唯一實作
 * 規範文件：（本檔即為唯一實作，無獨立 ai-rules 篇章前先以本檔頂端註解為準）
 *
 * 目的：許多「需要輸入超級管理員密碼才能執行的特例操作」（補資料、取消送出、永久刪除…）過去各模組
 * 各自比對登入密碼（`user`.`user_password`，明碼存放），密碼被重複輸入到各種畫面上，外洩風險高。
 * 本庫改用**獨立的操作確認密碼**（雜湊存放，跟登入密碼分開），且不限超級管理員一人：
 * 超級管理員（id=1）可以「授權」其他管理員也能設定自己的操作確認密碼，該管理員之後就能用
 * 自己的操作確認密碼執行原本只有超級管理員能做的動作，不需要知道超級管理員的登入密碼。
 *
 * 用法：
 *   require_once __DIR__.'/confirm_password_lib.php';
 *   if (!eg_confirm_password_allowed($db, $uid)) jerr('您沒有操作確認密碼的使用權限', 403);
 *   $chk = eg_confirm_password_verify($db, $uid, $_POST['password'] ?? '');
 *   if (!$chk['ok']) jerr($chk['msg']);
 *
 * 2026-08-06 第一階段（使用者明確要求）：授權對象由超級管理員一個一個「指定」（白名單），
 * 尚未做成「依職位批次開放」；日後要做職位批次開放時，在 eg_confirm_password_allowed() 裡
 * 多加一段「職位是否在開放清單」的判斷即可，資料表與其餘函式不用動。
 */

if (!function_exists('eg_confirm_password_ensure_schema')) {
    function eg_confirm_password_ensure_schema(PDO $db): void {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS confirm_password_grant (
                user_id INT NOT NULL PRIMARY KEY COMMENT '被授權可設定/使用操作確認密碼的人；id=1超級管理員永遠視為已授權，不需要在此表建列',
                password_hash VARCHAR(255) NULL COMMENT '尚未設定過時為NULL',
                granted_by VARCHAR(50) NULL,
                granted_at DATETIME NULL,
                password_updated_at DATETIME NULL
            ) DEFAULT CHARSET=utf8mb4 COMMENT='操作確認密碼授權名單與雜湊(與登入密碼分開存放)'");
            // 2026-08-13 使用者明確要求（起因：教育訓練刪除已完成場次要多一道密碼防護，並要能擋暴力猜密碼）：
            // 按「用途」(action_key) 分開計數鎖定，不是整組操作確認密碼一起鎖——猜錯某一項功能的密碼
            // 不該連帶讓這個人在其他也走操作確認密碼的功能一起被鎖住。同一支表供全站其他模組比照使用，
            // 不要各自另外刻一份鎖定邏輯。
            $db->exec("CREATE TABLE IF NOT EXISTS confirm_password_lockout (
                user_id INT NOT NULL,
                action_key VARCHAR(50) NOT NULL COMMENT '用途代碼，例如 training_delete_session',
                fail_count INT NOT NULL DEFAULT 0,
                locked_until DATETIME NULL COMMENT '鎖定到期時間，NULL=未鎖定；過了此時間視同未鎖定',
                last_fail_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (user_id, action_key)
            ) DEFAULT CHARSET=utf8mb4 COMMENT='操作確認密碼錯誤次數與鎖定狀態(按用途分開計)'");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('eg_confirm_password_allowed')) {
    /** 此人目前是否可以設定/使用操作確認密碼：id=1超級管理員永遠可以；其餘要在授權名單裡。 */
    function eg_confirm_password_allowed(PDO $db, int $uid): bool {
        if ($uid === 1) return true;
        try {
            eg_confirm_password_ensure_schema($db);
            $st = $db->prepare("SELECT 1 FROM confirm_password_grant WHERE user_id=?");
            $st->execute([$uid]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('eg_confirm_password_grant_list')) {
    /** 授權名單(不含id=1，id=1永遠隱含授權不用列在名單裡)，供管理畫面顯示：[['user_id','user_cname','has_password'=>bool,'granted_by','granted_at'],...] */
    function eg_confirm_password_grant_list(PDO $db): array {
        try {
            eg_confirm_password_ensure_schema($db);
            $st = $db->query("SELECT g.user_id, u.user_cname, g.password_hash, g.granted_by, g.granted_at
                              FROM confirm_password_grant g JOIN user u ON u.id=g.user_id
                              ORDER BY g.granted_at DESC");
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['user_id'=>(int)$r['user_id'], 'user_cname'=>$r['user_cname'],
                          'has_password'=>$r['password_hash'] !== null, 'granted_by'=>$r['granted_by'], 'granted_at'=>$r['granted_at']];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_confirm_password_grant')) {
    /** 超級管理員授權某人可設定操作確認密碼(該人自己還要再去設一次密碼才能真的用) */
    function eg_confirm_password_grant(PDO $db, int $targetUid, string $byName): void {
        eg_confirm_password_ensure_schema($db);
        $db->prepare("INSERT INTO confirm_password_grant (user_id, granted_by, granted_at) VALUES (?,?,NOW())
                      ON DUPLICATE KEY UPDATE granted_by=VALUES(granted_by), granted_at=NOW()")
           ->execute([$targetUid, $byName]);
    }
    function eg_confirm_password_revoke(PDO $db, int $targetUid): void {
        eg_confirm_password_ensure_schema($db);
        $db->prepare("DELETE FROM confirm_password_grant WHERE user_id=?")->execute([$targetUid]);
    }
}

if (!function_exists('eg_confirm_password_set')) {
    /** 本人設定/變更自己的操作確認密碼；需先具備 eg_confirm_password_allowed() 資格。 */
    function eg_confirm_password_set(PDO $db, int $uid, string $newPassword, string $byName): array {
        if (!eg_confirm_password_allowed($db, $uid)) return ['ok'=>false, 'msg'=>'您目前沒有設定操作確認密碼的權限，請洽超級管理員授權'];
        if (mb_strlen($newPassword) < 6) return ['ok'=>false, 'msg'=>'密碼至少需 6 碼'];
        eg_confirm_password_ensure_schema($db);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($uid === 1) {
            // id=1 不強制要有 grant 列(永遠隱含授權)，用 INSERT..ON DUPLICATE 讓第一次設定時也能存
            $db->prepare("INSERT INTO confirm_password_grant (user_id, password_hash, granted_by, granted_at, password_updated_at)
                          VALUES (1,?,?,NOW(),NOW())
                          ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), password_updated_at=NOW()")
               ->execute([$hash, $byName]);
        } else {
            $db->prepare("UPDATE confirm_password_grant SET password_hash=?, password_updated_at=NOW() WHERE user_id=?")
               ->execute([$hash, $uid]);
        }
        return ['ok'=>true, 'msg'=>''];
    }
}

if (!function_exists('eg_confirm_password_verify')) {
    /**
     * 驗證操作確認密碼。
     * id=1 超級管理員尚未設定過操作確認密碼時，退回比對登入密碼(過渡期相容，維持現行行為不強迫先設定)；
     * 其餘被授權的人**必須先設定過操作確認密碼才能使用**，未設定一律擋下並提示去設定
     * (不退回比對登入密碼——這些人本來就沒有在其他地方輸入過登入密碼確認操作的舊行為要相容)。
     */
    function eg_confirm_password_verify(PDO $db, int $uid, string $password): array {
        if ($password === '') return ['ok'=>false, 'msg'=>'請輸入操作確認密碼'];
        if (!eg_confirm_password_allowed($db, $uid)) return ['ok'=>false, 'msg'=>'您沒有操作確認密碼的使用權限'];
        try {
            eg_confirm_password_ensure_schema($db);
            $st = $db->prepare("SELECT password_hash FROM confirm_password_grant WHERE user_id=?");
            $st->execute([$uid]);
            $hash = $st->fetchColumn();
            if ($hash) {
                return password_verify($password, $hash) ? ['ok'=>true, 'msg'=>''] : ['ok'=>false, 'msg'=>'密碼錯誤'];
            }
            if ($uid === 1) {
                // 過渡期：超級管理員還沒設定操作確認密碼時，退回比對登入密碼
                $st = $db->prepare("SELECT user_password FROM `user` WHERE id=1 LIMIT 1");
                $st->execute();
                $real = $st->fetchColumn();
                if ($real === false) return ['ok'=>false, 'msg'=>'查無超級管理員帳號'];
                return hash_equals((string)$real, $password) ? ['ok'=>true, 'msg'=>''] : ['ok'=>false, 'msg'=>'密碼錯誤'];
            }
            return ['ok'=>false, 'msg'=>'您尚未設定操作確認密碼，請先到「修改個人密碼」頁設定'];
        } catch (Throwable $e) { return ['ok'=>false, 'msg'=>'密碼驗證失敗']; }
    }
}

if (!defined('EG_CONFIRM_PW_MAX_FAIL')) define('EG_CONFIRM_PW_MAX_FAIL', 3);       // 連續錯幾次才鎖定
if (!defined('EG_CONFIRM_PW_LOCK_DAYS')) define('EG_CONFIRM_PW_LOCK_DAYS', 7);     // 鎖定天數（到期自動解除）

if (!function_exists('eg_confirm_password_lockout_status')) {
    /** 這個人這項用途目前是否被鎖定：['locked'=>bool, 'until'=>?string, 'fail_count'=>int]（鎖定時間已過會自動視為未鎖定，不必額外清列） */
    function eg_confirm_password_lockout_status(PDO $db, int $uid, string $actionKey): array {
        try {
            eg_confirm_password_ensure_schema($db);
            $st = $db->prepare("SELECT fail_count, locked_until FROM confirm_password_lockout WHERE user_id=? AND action_key=?");
            $st->execute([$uid, $actionKey]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return ['locked'=>false, 'until'=>null, 'fail_count'=>0];
            $locked = $r['locked_until'] !== null && $r['locked_until'] > date('Y-m-d H:i:s');
            return ['locked'=>$locked, 'until'=>$locked ? $r['locked_until'] : null, 'fail_count'=>(int)$r['fail_count']];
        } catch (Throwable $e) { return ['locked'=>false, 'until'=>null, 'fail_count'=>0]; }
    }
}

if (!function_exists('eg_confirm_password_verify_scoped')) {
    /**
     * 帶「錯N次鎖定」防暴力猜密碼的操作確認密碼驗證，按 $actionKey 分開計數與鎖定
     * （見 ai-rules 教育訓練模組刪除已完成場次的用法）。
     * 鎖定期間直接拒絕（不消耗嘗試次數、也不再呼叫 eg_confirm_password_verify()）；
     * 驗證失敗才計入次數，達 EG_CONFIRM_PW_MAX_FAIL 次鎖定 EG_CONFIRM_PW_LOCK_DAYS 天；成功則歸零。
     * 回傳在 eg_confirm_password_verify() 原本的 ['ok','msg'] 之外，失敗時多帶 'locked'=>bool 供前端判斷要不要顯示解鎖提示。
     */
    function eg_confirm_password_verify_scoped(PDO $db, int $uid, string $password, string $actionKey): array {
        eg_confirm_password_ensure_schema($db);
        $lock = eg_confirm_password_lockout_status($db, $uid, $actionKey);
        if ($lock['locked']) {
            return ['ok'=>false, 'locked'=>true,
                     'msg'=>'密碼已連續錯誤達上限，此功能已鎖定至 '.substr((string)$lock['until'],0,16).'（可洽超級管理員協助提前解鎖）'];
        }
        $r = eg_confirm_password_verify($db, $uid, $password);
        if ($r['ok']) {
            $db->prepare("DELETE FROM confirm_password_lockout WHERE user_id=? AND action_key=?")->execute([$uid, $actionKey]);
            return $r + ['locked'=>false];
        }
        // 「您沒有使用權限」「尚未設定密碼」這類非密碼錯誤，不算一次錯誤嘗試（本來就不該用猜的方式解決）
        if ($r['msg'] === '密碼錯誤') {
            $fail = $lock['fail_count'] + 1;
            $lockedUntil = $fail >= EG_CONFIRM_PW_MAX_FAIL ? date('Y-m-d H:i:s', time() + EG_CONFIRM_PW_LOCK_DAYS*86400) : null;
            $db->prepare("INSERT INTO confirm_password_lockout (user_id, action_key, fail_count, locked_until, last_fail_at, updated_at)
                          VALUES (?,?,?,?,NOW(),NOW())
                          ON DUPLICATE KEY UPDATE fail_count=VALUES(fail_count), locked_until=VALUES(locked_until),
                              last_fail_at=NOW(), updated_at=NOW()")
               ->execute([$uid, $actionKey, $fail, $lockedUntil]);
            if ($lockedUntil) {
                return ['ok'=>false, 'locked'=>true,
                         'msg'=>'密碼已連續錯誤 '.$fail.' 次，此功能已鎖定至 '.substr($lockedUntil,0,16).'（可洽超級管理員協助提前解鎖）'];
            }
            return ['ok'=>false, 'locked'=>false, 'msg'=>'密碼錯誤（還可嘗試 '.(EG_CONFIRM_PW_MAX_FAIL-$fail).' 次，錯誤達 '.EG_CONFIRM_PW_MAX_FAIL.' 次將鎖定 '.EG_CONFIRM_PW_LOCK_DAYS.' 天）'];
        }
        return $r + ['locked'=>false];
    }
}

if (!function_exists('eg_confirm_password_lockout_clear')) {
    /** 超級管理員手動提前解鎖（僅限被鎖定當事人所屬的呼叫端自行判斷是否具超級管理員身分再呼叫本函式，本函式不重複驗證權限） */
    function eg_confirm_password_lockout_clear(PDO $db, int $uid, string $actionKey): void {
        eg_confirm_password_ensure_schema($db);
        $db->prepare("DELETE FROM confirm_password_lockout WHERE user_id=? AND action_key=?")->execute([$uid, $actionKey]);
    }
}

if (!function_exists('eg_confirm_password_lockout_list')) {
    /** 目前仍鎖定中的名單（供管理畫面顯示＋解鎖用），已過期的不列入 */
    function eg_confirm_password_lockout_list(PDO $db): array {
        try {
            eg_confirm_password_ensure_schema($db);
            $st = $db->query("SELECT l.user_id, u.user_cname, l.action_key, l.fail_count, l.locked_until
                              FROM confirm_password_lockout l JOIN user u ON u.id=l.user_id
                              WHERE l.locked_until IS NOT NULL AND l.locked_until > NOW()
                              ORDER BY l.locked_until DESC");
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
