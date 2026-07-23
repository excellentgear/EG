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
