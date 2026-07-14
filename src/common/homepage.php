<?php
// homepage.php — 登入後首頁導向共用邏輯（部門首頁設定 + 個人覆寫）
// 使用：
//   require_once '.../src/common/homepage.php';
//   $home = hp_resolve_home_page($pdo, $userId);   // 回傳相對路徑或 null
//
// 資料來源優先序：
//   1. user.home_page      （個人指定，最優先）
//   2. department.home_page（使用者主職務部門，is_main=1）
//   3. null                （由呼叫端退回舊的 switch 邏輯）
//
// 欄位若不存在會自動建立（MySQL 無 ADD COLUMN IF NOT EXISTS，改以 information_schema 判斷）。

if (!function_exists('hp_options')) {
    // 可作為首頁的頁面清單（登入導向白名單 + 設定頁下拉共用）
    function hp_options(): array {
        return [
            ['path' => 'views/admin/dashboard.php',        'label' => '一般儀表板 (dashboard)'],
            ['path' => 'views/admin/NN_dashboard.php',     'label' => 'NN 儀表板 (NN_dashboard)'],
            ['path' => 'views/admin/NN_dashboard2.php',    'label' => 'NN 儀表板 2 (NN_dashboard2)'],
            // [2026-07-08 暫時註記：舊校務遺留 dashboard，移出首頁白名單]
            // ['path' => 'views/admin/teacher_dashboard.php','label' => '教師儀表板 (teacher_dashboard)'],
            // ['path' => 'views/admin/pc_dashboard.php',     'label' => 'PC 儀表板 (pc_dashboard)'],
            // ['path' => 'views/admin/stu_dashboard.php',    'label' => '學生儀表板 (stu_dashboard)'],
        ];
    }
}

if (!function_exists('hp_is_valid')) {
    // 白名單檢查：避免存入或導向到未授權的路徑（防開放式轉址）
    function hp_is_valid($path): bool {
        if (!$path) return false;
        foreach (hp_options() as $o) {
            if ($o['path'] === $path) return true;
        }
        return false;
    }
}

if (!function_exists('hp_ensure_columns')) {
    // 確保 department.home_page 與 user.home_page 欄位存在
    function hp_ensure_columns(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'department' AND COLUMN_NAME = 'home_page'")->fetchColumn();
            if ($c === 0) {
                $pdo->exec("ALTER TABLE `department` ADD COLUMN `home_page` VARCHAR(255) NULL COMMENT '該部門登入後預設首頁(相對路徑)'");
            }
            $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'home_page'")->fetchColumn();
            if ($c === 0) {
                $pdo->exec("ALTER TABLE `user` ADD COLUMN `home_page` VARCHAR(255) NULL COMMENT '個人指定首頁(覆寫部門設定)'");
            }
        } catch (Exception $e) {
            // 權限不足或其他錯誤 → 靜默略過，呼叫端會退回舊邏輯
        }
    }
}

if (!function_exists('hp_get_default')) {
    // 取得「全域預設首頁」（未設定部門/個人時套用）；存於 system_settings.default_home_page
    function hp_get_default(PDO $pdo): ?string {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_home_page'");
            $st->execute();
            $v = $st->fetchColumn();
            if (hp_is_valid($v)) return $v;
        } catch (Exception $e) {}
        return null;
    }
}

if (!function_exists('hp_set_default')) {
    // 設定全域預設首頁（$path 為 null/空 代表清除）
    function hp_set_default(PDO $pdo, ?string $path, $byId = null, $byName = null): void {
        $val = ($path === null || $path === '') ? null : $path;
        // 以 upsert 方式寫入（system_settings.setting_key 為主鍵）
        $st = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                             VALUES ('default_home_page', ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by), updated_at = NOW()");
        $st->execute([$val, $byId, $byName]);
    }
}

if (!function_exists('hp_resolve_home_page')) {
    // 解析某使用者登入後應導向的首頁；找不到設定回傳 null
    function hp_resolve_home_page(PDO $pdo, int $userId): ?string {
        if ($userId <= 0) return null;
        hp_ensure_columns($pdo);

        // 1) 個人覆寫
        try {
            $st = $pdo->prepare("SELECT home_page FROM `user` WHERE id = ?");
            $st->execute([$userId]);
            $u = $st->fetchColumn();
            if (hp_is_valid($u)) return $u;
        } catch (Exception $e) {}

        // 2) 主職務部門
        try {
            $st = $pdo->prepare("SELECT d.home_page
                FROM user_department_position_map m
                JOIN department d ON d.id = m.department_id
                WHERE m.user_id = ? AND m.is_main = 1
                LIMIT 1");
            $st->execute([$userId]);
            $d = $st->fetchColumn();
            if (hp_is_valid($d)) return $d;
        } catch (Exception $e) {}

        // 3) 全域預設首頁（未設定部門/個人時套用）
        $def = hp_get_default($pdo);
        if ($def) return $def;

        return null;
    }
}
