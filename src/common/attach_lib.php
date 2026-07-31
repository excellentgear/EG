<?php
/**
 * 附件根路徑共用庫（CLAUDE.md 鐵律5／ai-rules/07 的唯一實作點）
 *
 * 全站規則（2026-07-31 使用者明確要求）：
 *   所有模組的附件一律放在同一個 AS9100 根資料夾底下，各模組再以「頁面名稱」為子資料夾自動建立。
 *   根路徑預設 \\excellentnas\AS9100維護\ERP AS9100文件(勿刪)，可用設定鍵 eg_attach_root 全站一次改掉。
 *   DB 一律只存檔名，完整路徑在讀取當下用「目前設定值＋頁面資料夾」現場組出。
 *
 * 用法（新模組照抄兩行就好）：
 *   include_once __DIR__.'/attach_lib.php';
 *   $dir = eg_attach_dir($db, 'xxx_nas_dir', '教育訓練');   // 個別設定優先，沒設定就用「根路徑\教育訓練\」
 *   eg_attach_ensure_dir($dir);                             // 不存在就建（含多層）
 */

/** 全站附件根資料夾預設值（UNC 比磁碟代號穩定，見 ai-rules/07） */
const EG_ATTACH_ROOT_DEFAULT = '\\\\excellentnas\\AS9100維護\\ERP AS9100文件(勿刪)';

/** 目前生效的附件根資料夾（設定鍵 eg_attach_root；結尾不含斜線） */
function eg_attach_root(PDO $db): string {
    $root = EG_ATTACH_ROOT_DEFAULT;
    try {
        $v = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='eg_attach_root'")->fetchColumn();
        if ($v !== false && trim((string)$v) !== '') $root = trim((string)$v);
    } catch (Throwable $e) {}
    return rtrim($root, "\\/");
}

/**
 * 模組附件目錄（保證以斜線結尾）。
 * $settingKey 有值＝該模組自行指定的完整目錄（優先）；沒有＝根路徑 \ 頁面資料夾。
 */
function eg_attach_dir(PDO $db, string $settingKey, string $pageFolder): string {
    $dir = '';
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
        $st->execute([$settingKey]);
        $v = $st->fetchColumn();
        if ($v !== false && trim((string)$v) !== '') $dir = trim((string)$v);
    } catch (Throwable $e) {}
    if ($dir === '') $dir = eg_attach_root($db) . '\\' . $pageFolder;
    if (!preg_match('#[/\\\\]$#', $dir)) $dir .= (strpos($dir, '\\') !== false ? '\\' : '/');
    return $dir;
}

/** 確保目錄存在（UNC 亦適用；已存在或建立成功回 true） */
function eg_attach_ensure_dir(string $dir): bool {
    if (is_dir($dir)) return true;
    @mkdir($dir, 0777, true);
    return is_dir($dir);
}
