<?php
/**
 * process_type_lib.php — 製程大類（process_type）共用查詢，唯一實作。
 *
 * `process_no`（紙本上的「工程名稱」，如 168／169 都叫「包裝」）與 `process_type`
 * （機種主檔管理用的分類，如 process_type_id=16「雷刻與包裝」把 16/168/169/181
 * 歸在一起）是兩個不同粒度的軸：同一類製程常常有好幾個代號（本站 2026-10-05
 * 查證：process_type_id=12「齒研」底下就有 12/156/165/167/205/209 六個代號）。
 *
 * 任何地方只要是「這個製程代號查不到專屬設定，改退回同一大類底下別的代號」
 * 這種需求，一律呼叫這支，不要各自再寫一次 `SELECT process_type_id FROM
 * process_no WHERE ProcessNo=?`——目前已知的兩個使用端是 control_plan_lib.php
 * 的通用 SIP 退回（cp_sip_general_doc_by_type）與 sopsip_lib.php 的檢驗項目
 * 預設值範本退回（ss_tpl_rows_by_type），往後要再長第三個用法也共用同一份。
 */

/** 查某個製程代號屬於哪個製程大類；請求內快取。查不到回 null。 */
function eg_process_type_id(PDO $db, int $processNo): ?int
{
    static $cache = [];
    if (array_key_exists($processNo, $cache)) return $cache[$processNo];
    $v = null;
    try {
        $st = $db->prepare("SELECT process_type_id FROM process_no WHERE ProcessNo=?");
        $st->execute([$processNo]);
        $r = $st->fetchColumn();
        if ($r !== false && $r !== null) $v = (int)$r;
    } catch (Throwable $e) {}
    return $cache[$processNo] = $v;
}
