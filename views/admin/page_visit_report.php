<?php
/**
 * page_visit_report.php — 已併入「系統稽核紀錄」第三分頁（頁面使用統計）。
 *
 * 2026-07-24：本頁與 audit_log_report.php 外殼、權限、UI 規範完全相同，故整併為單一入口的分頁式頁面
 * （登入紀錄／權限異動／頁面使用統計）。此檔保留為轉址殼，讓舊書籤與舊連結仍可用；
 * 原完整實作已移入 audit_log_report.php（pv_list / pv_detail / pv_export 動作與 pvr_* 函式）。
 * 完整還原可查 git 歷史。
 */
header('Location: audit_log_report.php?tab=pv', true, 302);
exit;
