<?php
/**
 * 「ERP 直接匯入補建的歷史報價單」判定 —— 唯一實作，禁止各頁自己寫死字串（鐵律4）
 * ---------------------------------------------------------------------------
 * 2026-09-15 使用者要求：補進來的舊報價單要能由管理員補附件。
 *
 * 這種單的特徵是備註以「ERP直接匯入補建歷史資料」開頭（`Quotation_API.php` 的
 * 快速轉移就是這樣寫進去的），**不是** `pending_review`——那一欄只代表「快速轉移頁
 * 還沒確認完」，確認完就變 0，但它仍然是一張補建的歷史單。
 *
 * 全庫實測（2026-09-15）：approval_status='none' 共 12,508 張，其中 12,496 張是這種
 * 匯入補建單；這些單從來沒有走過簽核，所以現行「已核准才出現的補件」按鈕一律看不到。
 *
 * 前後端唯一來源：頁面把 `is_legacy_import` 旗標交給前端用（前端不自己比字串），
 * API 端要擋的時候自己再呼叫一次 `eg_quot_is_legacy_import()`（鐵律8）。
 */

if (!defined('EG_QUOT_LEGACY_NOTE_PREFIX')) {
    define('EG_QUOT_LEGACY_NOTE_PREFIX', 'ERP直接匯入補建歷史資料');
}

if (!function_exists('eg_quot_is_legacy_import')) {
    /** 這張報價單是不是 ERP 匯入補建的歷史單（依備註開頭判定） */
    function eg_quot_is_legacy_import(?string $note): bool
    {
        $note = trim((string)$note);
        if ($note === '') return false;
        return strpos($note, EG_QUOT_LEGACY_NOTE_PREFIX) === 0;
    }

    /** SQL 條件（要在查詢裡篩這種單時用，一樣不要各自寫死字串） */
    function eg_quot_legacy_sql_cond(string $alias = 'ql'): string
    {
        return $alias . ".note LIKE '" . EG_QUOT_LEGACY_NOTE_PREFIX . "%'";
    }
}
