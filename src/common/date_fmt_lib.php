<?php
/**
 * 全站共用日期顯示格式化（2026-08-06 使用者明確要求：西元年日期顯示一律 YYYY.MM.DD，唯一實作）。
 * 只用於「顯示」給人看的文字/列印，SQL 查詢條件與 DB 寫入值不要走這支（那些本來就該用 Y-m-d 給資料庫）。
 * 禁止各頁自己再寫一份格式化邏輯，一律 require 本檔呼叫 eg_fmt_date()。
 * JS 對應實作見 resource/js/eg_date_fmt.js 的 egFmtDate()。
 */
if (!function_exists('eg_fmt_date')) {
function eg_fmt_date($d, bool $withTime = false): string {
    if (!$d) return '';
    $ts = is_numeric($d) ? (int)$d : strtotime((string)$d);
    if ($ts === false || $ts <= 0) return (string)$d;
    return date($withTime ? 'Y.m.d H:i' : 'Y.m.d', $ts);
}
}
