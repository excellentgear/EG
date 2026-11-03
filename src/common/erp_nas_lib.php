<?php
/**
 * erp_nas_lib.php — 「舊 ERP 資料夾」搬到 AS9100 根資料夾之後，程式裡寫死的舊路徑改走這裡
 *
 * 舊：\\excellentnas\生產課\BOM\ERP\<子資料夾>
 * 新：\\excellentnas\AS9100維護\ERP AS9100文件(勿刪)\<子資料夾>（子資料夾名稱不變）
 *
 * 搬家由 views/pm/migrations/2026-11-03_erp_to_as9100/ 執行，最後一步把
 * system_settings.erp_nas_moved 設成 1。旗標沒設（搬家前）一律回舊位置，所以本檔可以先上線。
 * 能用設定鍵的地方仍以設定鍵為準；這裡只處理「原本寫死在程式裡」的那幾處。
 */
if (!function_exists('eg_erp_moved')) {

if (!defined('EG_ERP_OLD_ROOT')) define('EG_ERP_OLD_ROOT', '\\\\excellentnas\\生產課\\BOM\\ERP');
if (!defined('EG_ERP_NEW_ROOT')) define('EG_ERP_NEW_ROOT', '\\\\excellentnas\\AS9100維護\\ERP AS9100文件(勿刪)');

function eg_erp_moved(?PDO $db = null): bool {
    static $v = null;
    if ($v !== null) return $v;
    try {
        if (!$db) { require_once __DIR__ . '/bom_dir_lib.php'; $db = eg_bom_dir_pdo(); }
        $st = $db ? $db->query("SELECT setting_value FROM system_settings WHERE setting_key='erp_nas_moved'") : null;
        return $v = ($st && trim((string)$st->fetchColumn()) === '1');
    } catch (Throwable $e) { return $v = false; }
}

/** 子資料夾（反斜線分隔，例：'業務\退貨資料'）→ 目前生效的完整路徑（不含結尾分隔符） */
function eg_erp_dir(string $sub, ?PDO $db = null): string {
    return (eg_erp_moved($db) ? EG_ERP_NEW_ROOT : EG_ERP_OLD_ROOT) . '\\' . ltrim($sub, '\\/');
}
}
