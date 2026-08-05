<?php
// stamp_lib.php — 圖章管理共用工具（清冊 stamp_register / 掃描章資產 stamp_asset）
// 儲存位置：可於圖章管理頁自訂（system_settings.stamp_attach_base，建議 UNC 路徑）；未設定則用專案內 uploads/stamps。
// 鐵律5：stamp_asset 只存檔名（file_name），完整路徑一律讀取當下用本檔函式現場組出，絕不寫死進 DB。

if (!function_exists('eg_stamp_base')) {
    // 取得掃描章基礎儲存路徑（設定值優先，否則 fallback 專案內 uploads/stamps）
    function eg_stamp_base(PDO $db): string {
        try {
            $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'stamp_attach_base' LIMIT 1");
            $st->execute();
            $v = trim((string)$st->fetchColumn());
            if ($v !== '') return rtrim($v, '\\/');
        } catch (Exception $e) {}
        return realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'stamps';
    }

    // 掃描章實體檔完整路徑（不存在回傳 null）
    function eg_stamp_file_path(PDO $db, array $asset): ?string {
        $fn = basename((string)($asset['file_name'] ?? ''));
        if ($fn === '') return null;
        $p = eg_stamp_base($db) . DIRECTORY_SEPARATOR . $fn;
        return is_file($p) ? $p : null;
    }

    // 取單一使用者的掃描章資產（無則 null）
    function eg_stamp_asset(PDO $db, int $userId): ?array {
        try {
            $st = $db->prepare("SELECT user_id, file_name, band_top, band_bottom FROM stamp_asset WHERE user_id = ?");
            $st->execute([$userId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Exception $e) { return null; }
    }

    // 各模組「簽名圖章樣式」設定所使用的 system_settings key → 說明文字。
    // 新增模組要納入刪除模板前的套用檢查，只要把該模組的設定 key 加進這裡即可，不必動 eg_stamp_template_usages() 本身。
    if (!defined('EG_STAMP_TPL_SETTINGS')) {
        define('EG_STAMP_TPL_SETTINGS', [
            'meeting_stamp_tpl_id'  => '會議紀錄／出席簽到、項目確認簽名',
            'training_stamp_tpl_id' => '教育訓練／簽到表、訓練紀錄',
        ]);
    }

    // 某圖章模板目前被哪些地方套用（清冊登記中 + 各模組簽名圖章樣式設定）；回傳空陣列＝沒有任何套用，可安全刪除。
    function eg_stamp_template_usages(PDO $db, int $templateId): array {
        $out = [];
        if ($templateId <= 0) return $out;
        try {
            $st = $db->prepare("SELECT
                                   CASE WHEN r.position_id IS NOT NULL THEN CONCAT(d.name,'／',p.name)
                                        WHEN r.user_id IS NOT NULL AND r.dept_id IS NOT NULL THEN CONCAT(u.user_cname,'（',d.name,'）')
                                        ELSE COALESCE(u.user_cname, d.name) END AS holder_name
                                 FROM stamp_register r
                                 LEFT JOIN user u ON u.id = r.user_id
                                 LEFT JOIN department d ON d.id = r.dept_id
                                 LEFT JOIN position p ON p.id = r.position_id
                                 WHERE r.template_id = ? AND r.status = 'active'");
            $st->execute([$templateId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $out[] = '清冊登記：' . $name;
            }
        } catch (Exception $e) {}
        try {
            if (EG_STAMP_TPL_SETTINGS) {
                $keys = array_keys(EG_STAMP_TPL_SETTINGS);
                $in = implode(',', array_fill(0, count($keys), '?'));
                $st = $db->prepare("SELECT setting_key FROM system_settings WHERE setting_key IN ($in) AND setting_value = ?");
                $st->execute(array_merge($keys, [(string)$templateId]));
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $key) {
                    $out[] = '模組設定：' . (EG_STAMP_TPL_SETTINGS[$key] ?? $key);
                }
            }
        } catch (Exception $e) {}
        return $out;
    }

    // 全部掃描章資產對照表（給 eg_stamp.js：姓名→資產）。
    // 只回傳檔案實際存在者；t=檔案 mtime 供前端快取破壞。
    function eg_stamp_asset_map(PDO $db): array {
        $out = [];
        try {
            $rows = $db->query("SELECT a.user_id, a.file_name, a.band_top, a.band_bottom, u.user_cname
                                FROM stamp_asset a JOIN user u ON u.id = a.user_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $p = eg_stamp_file_path($db, $r);
                if (!$p) continue;   // 檔案遺失（如換NAS未搬檔）→ 前端自動回退純SVG
                $name = trim((string)$r['user_cname']);
                if ($name === '') continue;
                $out[$name] = [
                    'uid'  => (int)$r['user_id'],
                    'top'  => (float)$r['band_top'],
                    'bot'  => (float)$r['band_bottom'],
                    't'    => @filemtime($p) ?: 0,
                ];
            }
        } catch (Exception $e) {}
        return $out;
    }
}
