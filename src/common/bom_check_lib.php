<?php
/**
 * BOM 檢核（BOM總覽「檢核異常」標示／回報／異常解決）——唯一實作。
 *
 * - 功能碼 oready_bom_check（角色設定→BOM總覽 勾選）者才能標示問題、解決異常、維護問題種類；
 *   其他人只是在列表上看得到被標示的 BOM（置頂淺黃底），並可「回報」說明處理情形。
 * - 狀態不另存旗標：bom_check.status = 'open'（異常中）／'resolved'（已解決）。
 * - 標示／回報／解決都會出現在「BOM 相關紀錄」（bom_timeline_lib.php 讀 eg_bomchk_events）。
 */
if (!function_exists('eg_bomchk_ensure_schema')) {
    function eg_bomchk_ensure_schema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $has = $db->query("SHOW TABLES LIKE 'bom_check'")->fetchColumn();
            if ($has) return;
            // DDL 會隱式 commit，絕不可在交易中執行
            if ($db->inTransaction()) return;
            $db->exec("CREATE TABLE IF NOT EXISTS bom_check_type (
                type_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(40) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("CREATE TABLE IF NOT EXISTS bom_check (
                check_id INT AUTO_INCREMENT PRIMARY KEY,
                bom VARCHAR(40) NOT NULL,
                type_id INT NULL,
                type_name VARCHAR(40) NOT NULL DEFAULT '',
                note VARCHAR(500) NOT NULL DEFAULT '',
                status VARCHAR(10) NOT NULL DEFAULT 'open',
                created_by INT NULL,
                created_by_name VARCHAR(40) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                resolved_by INT NULL,
                resolved_by_name VARCHAR(40) NOT NULL DEFAULT '',
                resolved_at DATETIME NULL,
                KEY idx_bom (bom),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("CREATE TABLE IF NOT EXISTS bom_check_reply (
                reply_id INT AUTO_INCREMENT PRIMARY KEY,
                check_id INT NOT NULL,
                content VARCHAR(1000) NOT NULL DEFAULT '',
                is_done TINYINT(1) NOT NULL DEFAULT 0,
                by_id INT NULL,
                by_name VARCHAR(40) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                KEY idx_check (check_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $seed = ['製程順序或內容有誤', '發包廠商有誤', '數量有誤', '料號或圖面有誤', '其他'];
            $ins = $db->prepare("INSERT INTO bom_check_type (name, sort_order) VALUES (?, ?)");
            foreach ($seed as $i => $n) $ins->execute([$n, ($i + 1) * 10]);
        } catch (Throwable $e) { error_log('bom_check schema: ' . $e->getMessage()); }
    }
}

if (!function_exists('eg_bomchk_features')) {
    function eg_bomchk_features(PDO $db, int $uid): array {
        require_once __DIR__ . '/role_features_helper.php';
        $f = rf_load_user_features($db, $uid);
        return is_array($f) ? $f : [];
    }
}
// 檢核者：超級管理員，或角色明確勾選 oready_bom_check（刻意不吃萬用碼 all，才不會誤開給現有管理類角色）
if (!function_exists('eg_bomchk_can_check')) {
    function eg_bomchk_can_check(PDO $db, int $uid): bool {
        if ($uid <= 0) return false;
        $f = eg_bomchk_features($db, $uid);
        if (in_array('oready_readonly', $f, true)) return false;
        return $uid === 1 || in_array('oready_bom_check', $f, true);
    }
}
// 回報者：能看到本頁、且不是唯讀角色
if (!function_exists('eg_bomchk_can_report')) {
    function eg_bomchk_can_report(PDO $db, int $uid): bool {
        if ($uid <= 0) return false;
        return !in_array('oready_readonly', eg_bomchk_features($db, $uid), true);
    }
}
if (!function_exists('eg_bomchk_csrf')) {
    function eg_bomchk_csrf(): string {
        if (empty($_SESSION['bom_chk_csrf'])) $_SESSION['bom_chk_csrf'] = bin2hex(random_bytes(16));
        return $_SESSION['bom_chk_csrf'];
    }
    function eg_bomchk_csrf_ok($t): bool {
        return $t !== null && $t !== '' && hash_equals((string)($_SESSION['bom_chk_csrf'] ?? ''), (string)$t);
    }
}
if (!function_exists('eg_bomchk_uname')) {
    function eg_bomchk_uname(PDO $db, int $uid): string {
        $s = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
        $s->execute([$uid]);
        return (string)($s->fetchColumn() ?: '');
    }
}
if (!function_exists('eg_bomchk_types')) {
    function eg_bomchk_types(PDO $db, bool $onlyActive = true): array {
        eg_bomchk_ensure_schema($db);
        try {
            $sql = "SELECT type_id, name, sort_order, active FROM bom_check_type" . ($onlyActive ? " WHERE active=1" : "") . " ORDER BY sort_order, type_id";
            return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
// 異常中的 BOM：[bom => {check_id,type_name,note,created_by_name,created_at,replies:[...]}]
if (!function_exists('eg_bomchk_open_map')) {
    function eg_bomchk_open_map(PDO $db): array {
        eg_bomchk_ensure_schema($db);
        $map = [];
        try {
            $rows = $db->query("SELECT check_id, bom, type_id, type_name, note, created_by_name, created_at FROM bom_check WHERE status='open' ORDER BY check_id")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return [];
            $ids = array_column($rows, 'check_id');
            $ph = implode(',', array_map('intval', $ids));
            $rep = [];
            foreach ($db->query("SELECT reply_id, check_id, content, is_done, by_name, created_at FROM bom_check_reply WHERE check_id IN ($ph) ORDER BY reply_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rep[(int)$r['check_id']][] = ['content' => $r['content'], 'is_done' => (int)$r['is_done'], 'by' => $r['by_name'], 'at' => substr((string)$r['created_at'], 0, 16)];
            }
            foreach ($rows as $r) {
                $map[$r['bom']] = [
                    'check_id' => (int)$r['check_id'], 'type_id' => (int)$r['type_id'], 'type_name' => $r['type_name'], 'note' => $r['note'],
                    'by' => $r['created_by_name'], 'at' => substr((string)$r['created_at'], 0, 16),
                    'replies' => $rep[(int)$r['check_id']] ?? [],
                ];
            }
        } catch (Throwable $e) { error_log('bomchk map: ' . $e->getMessage()); }
        return $map;
    }
}
// 給「BOM 相關紀錄」用的事件（含已解決的歷次檢核）
if (!function_exists('eg_bomchk_events')) {
    function eg_bomchk_events(PDO $db, string $bom): array {
        $ev = [];
        try {
            if (!$db->query("SHOW TABLES LIKE 'bom_check'")->fetchColumn()) return [];
            $st = $db->prepare("SELECT * FROM bom_check WHERE bom = ? ORDER BY check_id");
            $st->execute([$bom]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $ev[] = ['ts' => $c['created_at'], 'label' => '檢核異常', 'text' => '標示問題「' . $c['type_name'] . '」' . ($c['note'] !== '' ? '：' . $c['note'] : ''), 'who' => $c['created_by_name'], 'tone' => 'red'];
                $rs = $db->prepare("SELECT * FROM bom_check_reply WHERE check_id = ? ORDER BY reply_id");
                $rs->execute([$c['check_id']]);
                foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ev[] = ['ts' => $r['created_at'], 'label' => '檢核回報', 'text' => ($r['is_done'] ? '【已處理完成】' : '【尚未完成】') . $r['content'], 'who' => $r['by_name'], 'tone' => $r['is_done'] ? 'green' : 'yellow'];
                }
                if ($c['status'] === 'resolved' && $c['resolved_at']) {
                    $ev[] = ['ts' => $c['resolved_at'], 'label' => '異常解決', 'text' => '解除檢核異常「' . $c['type_name'] . '」', 'who' => $c['resolved_by_name'], 'tone' => 'green'];
                }
            }
        } catch (Throwable $e) {}
        return $ev;
    }
}
