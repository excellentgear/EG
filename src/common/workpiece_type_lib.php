<?php
/**
 * 工件種類字典（dict_workpiece_type）讀取共用庫 — 唯一實作。
 * 凡是料號相關頁面要列出「工件種類」選項（N=一般/G=齒輪/H=滾刀…，管理員可在
 * 主檔管理新增），一律呼叫本函式，不可各頁自己寫死 N/G/H（管理員新增的種類
 * 會在只有本頁讀得到的地方永遠挑不到）。
 */

if (!function_exists('eg_workpiece_types_active')) {
    function eg_workpiece_types_active(PDO $pdo): array {
        return $pdo->query(
            "SELECT * FROM dict_workpiece_type WHERE is_active=1 ORDER BY sort_order, type_code"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
