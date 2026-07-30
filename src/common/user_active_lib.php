<?php
/**
 * 在職狀態封鎖共用庫（2026-07-30 建立）
 *
 * 為什麼要有這支：
 *   原本只有 Login.php 擋 state=0（離職），但有三個洞——
 *   (1) Login.php 在密碼驗證前就把 $_SESSION 寫好，密碼打錯也能拿到 session，離職封鎖形同虛設；
 *   (2) 已登入者事後被改成離職，session 不會失效；
 *   (3) 離職者身上仍留著 user_module_permissions / 職稱對應，權限解析照樣發功能碼給他。
 *   本庫把「這個人現在還算不算在職」收斂成一處，登入、側欄守門、RBAC 解析三層都問同一個答案。
 *
 * 封鎖規則：user.state ∈ EG_BLOCKED_USER_STATES，或 user.leave_date 已過（預定離職日的隔天起），就是被封鎖。
 *   刻意用「黑名單」而非白名單：日後人事若新增未知狀態碼，預設仍可正常使用，
 *   不會因為沒登記在白名單就讓全公司登不進系統（誤傷成本遠大於收益）。
 *
 * 狀態碼對照（views/ADM/employee_management.php 的在職狀態下拉）：
 *   1=在職  2=留職停薪  3=育嬰留停  0=離職  90=特殊帳號(不列入員工)  99=最高權限帳號
 */

if (!defined('EG_BLOCKED_USER_STATES')) {
    // 使用者 2026-07-30 定調：離職、留職停薪、育嬰留停一律不得登入且無任何權限
    define('EG_BLOCKED_USER_STATES', '0,2,3');
}

if (!function_exists('eg_blocked_state_list')) {
    function eg_blocked_state_list() {
        return array_map('intval', explode(',', EG_BLOCKED_USER_STATES));
    }
}

if (!function_exists('eg_user_state_label')) {
    function eg_user_state_label($state) {
        $map = [0 => '離職', 1 => '在職', 2 => '留職停薪', 3 => '育嬰留停',
                90 => '特殊帳號', 99 => '最高權限帳號'];
        $s = (int)$state;
        return isset($map[$s]) ? $map[$s] : ('狀態' . $s);
    }
}

if (!function_exists('eg_user_blocked_state')) {
    /**
     * 查此人是否被在職狀態封鎖。
     * @return array|null 被封鎖回 ['state'=>0,'label'=>'離職','name'=>'王小明']；在職或查不到回 null
     *   查不到 user 也回 null（交給呼叫端原本的「帳號不存在」邏輯處理，本庫不越權）
     */
    function eg_user_blocked_state($pdo, $user_id) {
        static $cache = [];                       // 同一次請求可能被問很多次，快取避免重複查
        $uid = (int)$user_id;
        if ($uid <= 0) return null;
        if (array_key_exists($uid, $cache)) return $cache[$uid];

        $result = null;
        try {
            $st = $pdo->prepare("SELECT state, user_cname, leave_date FROM `user` WHERE id = ? LIMIT 1");
            $st->execute([$uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['state'] !== null && in_array((int)$row['state'], eg_blocked_state_list(), true)) {
                $result = [
                    'state' => (int)$row['state'],
                    'label' => eg_user_state_label($row['state']),
                    'name'  => (string)$row['user_cname'],
                ];
            } elseif ($row && !empty($row['leave_date']) && $row['leave_date'] < date('Y-m-d')) {
                // 預定離職日已過（離職當天仍可用，方便交接結案；隔天 0 點起封鎖）。
                // 判斷時就生效，不必等順路觸發把 state 改成 0——排程晚跑不影響安全。
                $result = [
                    'state' => 0,
                    'label' => '離職',
                    'name'  => (string)$row['user_cname'],
                    'by_leave_date' => (string)$row['leave_date'],
                ];
            }
        } catch (Exception $e) {
            // 查詢失敗不封鎖（DB 一時異常不該讓全公司登不進來）；錯誤另行記錄
            error_log('[user_active] state query failed: ' . $e->getMessage());
        }
        $cache[$uid] = $result;
        return $result;
    }
}

if (!function_exists('eg_user_is_active')) {
    /** 此人是否可正常使用系統（未被在職狀態封鎖） */
    function eg_user_is_active($pdo, $user_id) {
        return eg_user_blocked_state($pdo, $user_id) === null;
    }
}

if (!function_exists('eg_login_url')) {
    /** 由目前網址推出登入頁絕對路徑（避免 ../../../ 相對路徑在不同層級的頁面跑掉） */
    function eg_login_url() {
        $sn  = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $pos = strpos($sn, '/EGsystem/');
        $root = ($pos !== false) ? substr($sn, 0, $pos) . '/EGsystem/' : '/EGsystem/';
        return $root . 'index.php';
    }
}

if (!function_exists('eg_guard_active_session')) {
    /**
     * 頁面守門：若目前登入者已非在職狀態，立即銷毀 session 並導回登入頁。
     * 掛在全站唯一共同進入點（views/partPage/sideAndTopBarMenu.html），
     * 所以「人事把某人改成離職」之後，該人下一次翻頁就會被踢出，不必等 session 過期。
     */
    function eg_guard_active_session($pdo) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
        $uid = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
        if ($uid <= 0) return true;

        $blocked = eg_user_blocked_state($pdo, $uid);
        if ($blocked === null) return true;

        $msg = '此帳號目前為「' . $blocked['label'] . '」狀態，已停止使用系統';
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        if (!headers_sent()) {
            header('Location: ' . eg_login_url() . '?msg=' . urlencode($msg));
        }
        exit();
    }
}

if (!function_exists('eg_require_active_user_api')) {
    /**
     * API 守門（回 JSON 而非導頁）：非在職狀態直接 403 中止。
     * 給 src/store/*_API.php 這類不渲染側欄、拿不到頁面守門保護的端點用。
     */
    function eg_require_active_user_api($pdo) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
        $uid = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
        if ($uid <= 0) return;
        $blocked = eg_user_blocked_state($pdo, $uid);
        if ($blocked === null) return;
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
        }
        echo json_encode([
            'status'  => 'error',
            'success' => false,
            'message' => '此帳號目前為「' . $blocked['label'] . '」狀態，已停止使用系統',
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

/* ------------------------------------------------------------------
 * 以下為「權限資料清除」：判斷時擋是自動的，清資料則由管理員按鈕觸發。
 * 分開的理由：離職常有誤設／回鍋，權限列刪掉就永久還原不了，
 * 所以自動生效的部分只做 fail-closed，真的動資料一律先寫 audit_log 再刪。
 * ------------------------------------------------------------------ */

if (!function_exists('eg_collect_user_permissions')) {
    /** 撈出此人目前所有權限設定（清除前的快照，也可單純用來檢視殘留） */
    function eg_collect_user_permissions($pdo, $user_id) {
        $uid = (int)$user_id;
        $snap = ['user_roles' => [], 'user_permissions' => null,
                 'user_module_permissions' => [], 'page_operator_acl' => [], 'user_delegate' => []];
        try {
            $st = $pdo->prepare("SELECT ur.role_id, r.role_name, r.module
                                   FROM user_roles ur LEFT JOIN roles r ON r.role_id = ur.role_id
                                  WHERE ur.user_id = ?");
            $st->execute([$uid]);
            $snap['user_roles'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $st = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = ?");
            $st->execute([$uid]);
            $snap['user_permissions'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;

            $st = $pdo->prepare("SELECT id, module_code, permission, scope FROM user_module_permissions WHERE user_id = ?");
            $st->execute([$uid]);
            $snap['user_module_permissions'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $st = $pdo->prepare("SELECT id, page_key FROM page_operator_acl WHERE user_id = ?");
            $st->execute([$uid]);
            $snap['page_operator_acl'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $st = $pdo->prepare("SELECT id, user_id, delegate_id, scope_department_id, scope_position_id,
                                        start_date, end_date, priority
                                   FROM user_delegate
                                  WHERE (user_id = ? OR delegate_id = ?) AND active = 1");
            $st->execute([$uid, $uid]);
            $snap['user_delegate'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('[user_active] collect permissions failed: ' . $e->getMessage());
        }
        return $snap;
    }
}

if (!function_exists('eg_user_permission_warnings')) {
    /**
     * 不自動處理、但一定要讓人事看到的連動點（比照 ai-rules/14 連動點檢表）。
     * 這些改了會直接影響別人的簽核鏈，必須由人指定接手者，系統不可自己猜。
     */
    function eg_user_permission_warnings($pdo, $user_id) {
        $uid = (int)$user_id;
        $warn = [];
        try {
            $st = $pdo->prepare("SELECT dp.id, d.name AS dept_name, p.name AS pos_name
                                   FROM department_position dp
                                   LEFT JOIN department d ON d.id = dp.department_id
                                   LEFT JOIN `position` p ON p.id = dp.position_id
                                  WHERE dp.primary_user_id = ?");
            $st->execute([$uid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $warn[] = '仍是「' . $r['dept_name'] . ' / ' . $r['pos_name'] . '」的指定負責人，需人事改派';
            }
        } catch (Exception $e) { /* 表名不同或不存在時略過，不影響清除主流程 */ }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM user_department_position_map WHERE user_id = ?");
            $st->execute([$uid]);
            $n = (int)$st->fetchColumn();
            if ($n > 0) {
                // 職務歸屬不刪：刪了舊單據就查不出「當時他是哪個部門」。
                // 透過 position_roles 帶來的功能碼由 fail-closed 擋掉，不必動這張表。
                $warn[] = '保留 ' . $n . ' 筆職務歸屬（部門/職稱）供歷史單據查詢，其帶來的權限已由在職狀態擋下';
            }
        } catch (Exception $e) {}
        return $warn;
    }
}

if (!function_exists('eg_revoke_user_permissions')) {
    /**
     * 清除此人所有權限設定（transaction；清除前把原設定寫進 audit_log 備查）。
     * @param string $reason   清除原因（例：離職）
     * @return array ['ok'=>bool,'deleted'=>[表名=>筆數],'warnings'=>[],'message'=>'']
     */
    function eg_revoke_user_permissions($pdo, $user_id, $reason = '', $operator_id = null, $operator = '') {
        $uid = (int)$user_id;
        if ($uid <= 0) return ['ok' => false, 'deleted' => [], 'warnings' => [], 'message' => '缺少使用者編號'];

        $snapshot = eg_collect_user_permissions($pdo, $uid);
        $warnings = eg_user_permission_warnings($pdo, $uid);
        $deleted  = [];
        $owns_tx  = !$pdo->inTransaction();

        try {
            if ($owns_tx) $pdo->beginTransaction();

            $st = $pdo->prepare("SELECT user_cname FROM `user` WHERE id = ?");
            $st->execute([$uid]);
            $uname = (string)$st->fetchColumn();

            // 先寫稽核（含完整原設定），再刪——順序反了會有刪掉卻沒紀錄的風險
            $st = $pdo->prepare("INSERT INTO audit_log
                    (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                    VALUES ('PERM_REVOKE', 'user', ?, ?, ?, ?, ?, NOW())");
            // JSON_INVALID_UTF8_SUBSTITUTE：任一欄位若混到非 UTF-8 位元組（本專案 user 表有 latin1 欄位），
            // json_encode 會整包回 false → 稽核紀錄變空白字串，等於沒留紀錄。用替代字元確保一定寫得進去。
            $changes = json_encode(['reason' => $reason, 'before' => $snapshot, 'warnings' => $warnings],
                                   JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($changes === false) $changes = '{"reason":"' . addslashes((string)$reason) . '","before":"編碼失敗，無法序列化"}';

            $st->execute([
                (string)$uid, $uname,
                $changes,
                $operator_id !== null ? (int)$operator_id : null,
                $operator !== '' ? $operator : 'system',
            ]);

            foreach ([
                'user_roles'              => "DELETE FROM user_roles WHERE user_id = ?",
                'user_permissions'        => "DELETE FROM user_permissions WHERE user_id = ?",
                'user_module_permissions' => "DELETE FROM user_module_permissions WHERE user_id = ?",
                'page_operator_acl'       => "DELETE FROM page_operator_acl WHERE user_id = ?",
            ] as $table => $sql) {
                $st = $pdo->prepare($sql);
                $st->execute([$uid]);
                if ($st->rowCount() > 0) $deleted[$table] = $st->rowCount();
            }

            // 代理設定只停用不刪除（保留歷史；停用是必要的——否則簽核會一直派給已離職的人）
            $st = $pdo->prepare("UPDATE user_delegate SET active = 0
                                  WHERE (user_id = ? OR delegate_id = ?) AND active = 1");
            $st->execute([$uid, $uid]);
            if ($st->rowCount() > 0) $deleted['user_delegate(停用)'] = $st->rowCount();

            if ($owns_tx) $pdo->commit();
        } catch (Exception $e) {
            if ($owns_tx && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'deleted' => [], 'warnings' => $warnings,
                    'message' => '清除失敗：' . $e->getMessage()];
        }

        $total = array_sum($deleted);
        return ['ok' => true, 'deleted' => $deleted, 'warnings' => $warnings,
                'message' => $total > 0 ? ('已清除 ' . $total . ' 筆權限設定') : '此帳號沒有殘留的權限設定'];
    }
}
