<?php
// 修改 BOM「發單數」（bom.sqty）的唯一實作。使用端：views/pm/OreadyReply_ForPm_BaseOfTime_ajax.php
// 規則（使用者 2026-11-03 指定）：
//  - 要有角色功能碼 oready_edit_qty（或本頁管理員）；唯讀角色一律不可
//  - 要先按鎖頭輸入「本人登入密碼」解鎖（逐張 BOM、逐人、10 分鐘）
//  - 改多：一律可以；改少：若已有報工／檢驗／移轉單(J-)／回廠紀錄，只有管理員能改，其他人暫時阻擋
//  - 每次修改都留紀錄（原數量／修改人／時間／新數量），畫面顯示
require_once __DIR__ . '/role_features_helper.php';
require_once __DIR__ . '/confirm_password_lib.php';

const BQE_UNLOCK_TTL = 600;

if (!function_exists('bqe_ensure_schema')) {
    /** 建紀錄表。DDL 會隱式 commit，所以一定要在 beginTransaction() 之前呼叫。 */
    function bqe_ensure_schema(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS bom_qty_change_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bom VARCHAR(30) NOT NULL,
            old_qty INT NULL,
            new_qty INT NOT NULL,
            synced_ing_rows INT NOT NULL DEFAULT 0,
            usage_json TEXT NULL,
            changed_by INT NOT NULL,
            changed_by_name VARCHAR(60) NULL,
            changed_at DATETIME NOT NULL,
            KEY idx_bom (bom, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('bqe_table_exists')) {
    function bqe_table_exists(PDO $db): bool {
        try { return $db->query("SHOW TABLES LIKE 'bom_qty_change_log'")->rowCount() > 0; }
        catch (Throwable $e) { return false; }
    }
}

if (!function_exists('bqe_is_admin')) {
    function bqe_is_admin(PDO $db, int $uid): bool {
        return oready_resolve_is_admin($db, $uid, '/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php');
    }
}

if (!function_exists('bqe_can_edit')) {
    /** 可否使用「修改發單數」：管理員，或持有 oready_edit_qty 且不是唯讀角色。 */
    function bqe_can_edit(PDO $db, int $uid): bool {
        if ($uid <= 0) return false;
        $f = rf_load_user_features($db, $uid);
        // 唯讀要角色明確勾選才算（不可用 rf_has_feature，它把 'all' 當成符合一切）
        if (in_array('oready_readonly', $f, true)) return false;
        if (bqe_is_admin($db, $uid)) return true;
        return rf_has_feature($f, 'oready_edit_qty');
    }
}

if (!function_exists('bqe_usage')) {
    /** 這張 BOM 已經有哪些「依賴發單數」的紀錄；回各項筆數與總計。 */
    function bqe_usage(PDO $db, string $bom): array {
        $one = function (string $sql) use ($db, $bom): int {
            try { $st = $db->prepare($sql); $st->execute([$bom]); return (int)$st->fetchColumn(); }
            catch (Throwable $e) { return 0; }
        };
        $u = [
            'report'   => $one("SELECT COUNT(*) FROM pm_process_daily_report r JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid WHERE bi.bom=?"),
            'qc'       => $one("SELECT COUNT(*) FROM qc_check q JOIN bom_ing bi ON bi.bom_ing_fid=q.bom_ing_fid_ref WHERE bi.bom=?"),
            'transfer' => $one("SELECT COUNT(*) FROM bom_ing_transfer_log WHERE bom=?"),
            'return'   => $one("SELECT COUNT(*) FROM bom_ing_outsource_return r JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid WHERE bi.bom=?"),
        ];
        $u['total'] = array_sum($u);
        return $u;
    }
}

if (!function_exists('bqe_usage_text')) {
    function bqe_usage_text(array $u): string {
        $p = [];
        if (!empty($u['report']))   $p[] = '報工 ' . $u['report'] . ' 筆';
        if (!empty($u['qc']))       $p[] = '檢驗 ' . $u['qc'] . ' 筆';
        if (!empty($u['transfer'])) $p[] = '移轉單(J-) ' . $u['transfer'] . ' 筆';
        if (!empty($u['return']))   $p[] = '回廠 ' . $u['return'] . ' 筆';
        return implode('、', $p);
    }
}

if (!function_exists('bqe_history')) {
    function bqe_history(PDO $db, string $bom, int $limit = 10): array {
        if (!bqe_table_exists($db)) return [];
        $st = $db->prepare("SELECT old_qty,new_qty,changed_by_name,DATE_FORMAT(changed_at,'%Y.%m.%d %H:%i:%s') AS at
                            FROM bom_qty_change_log WHERE bom=? ORDER BY id DESC LIMIT " . (int)$limit);
        $st->execute([$bom]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('bqe_unlock_mark')) {
    function bqe_unlock_mark(int $uid, string $bom): void { $_SESSION['bom_qty_unlock'][$uid . ':' . $bom] = time(); }
}
if (!function_exists('bqe_unlock_valid')) {
    function bqe_unlock_valid(int $uid, string $bom): bool {
        $k = $uid . ':' . $bom;
        $t = (int)($_SESSION['bom_qty_unlock'][$k] ?? 0);
        if (!$t) return false;
        if (time() - $t > BQE_UNLOCK_TTL) { unset($_SESSION['bom_qty_unlock'][$k]); return false; }
        return true;
    }
}
if (!function_exists('bqe_unlock_clear')) {
    function bqe_unlock_clear(int $uid, string $bom): void { unset($_SESSION['bom_qty_unlock'][$uid . ':' . $bom]); }
}
