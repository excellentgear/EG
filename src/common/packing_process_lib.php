<?php
// =============================================================================
// src/common/packing_process_lib.php
// -----------------------------------------------------------------------------
// 「包裝製程」的唯一實作：判斷某個 process_no 有沒有被設定為包裝排程
// （views/pm/packing_schedule.php「包裝製程設定」）認定的包裝製程。
//
// 為什麼要獨立成共用庫：包裝排程頁已經有自己一整套獨立的包裝檢驗流程
// （qc_packing_inspection／qc_packing_inspection_data），凡是被設成「包裝製程」
// 的 bom_ing 站別，一律不該再出現在線上檢驗（views/QC/inspection_entry_v2.php
// 與 QC 待驗清單）可以建立檢驗紀錄表的製程清單裡——但「全製程合併列印」仍要把
// 包裝檢驗的結果一併印出來。判斷「是不是包裝製程」這件事因此會被至少三個模組
// 共用（QC 待驗清單／線上檢驗製程切換／全製程合併列印），不能只留在
// packing_schedule.php 裡（那支檔案本身也只在有人真的開啟包裝排程頁時才會被
// 載入，其他頁面完全看不到它定義的 function）。
//
// pm_packing_process_setting 這張表原本就是 packing_schedule.php 建立的，
// 這裡只是把「讀」這件事收斂成唯一入口；packing_schedule.php 自己的
// get_packing_process_nos() 已改為呼叫這裡（見該檔），不再各自維護一份。
// =============================================================================

if (!function_exists('pk_packing_ensure_schema')) {
    function pk_packing_ensure_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pm_packing_process_setting (
                id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
                process_no INT NOT NULL COMMENT '認定為包裝的製程編號，對應 process_no.ProcessNo',
                created_by VARCHAR(11) NULL COMMENT '建立人員',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
                UNIQUE KEY uk_process_no (process_no)
            ) COMMENT='包裝排程：認定為包裝的製程編號設定（可多選）'");
        } catch (Exception $e) { /* 忽略：無建表權限時原樣沿用既有結構 */ }
    }
}

if (!function_exists('pk_packing_process_nos')) {
    // 目前被設定為「包裝製程」的 process_no 清單（唯一實作，全站共用；一個 request 內只查一次）
    function pk_packing_process_nos(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        pk_packing_ensure_schema($pdo);
        try {
            $rows = $pdo->query("SELECT process_no FROM pm_packing_process_setting ORDER BY process_no")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $rows = [];
        }
        $cache = array_map('intval', $rows);
        return $cache;
    }
}

if (!function_exists('pk_is_packing_process_no')) {
    function pk_is_packing_process_no(PDO $pdo, $processNo): bool
    {
        return in_array((int)$processNo, pk_packing_process_nos($pdo), true);
    }
}

if (!function_exists('pk_packing_rows_for_fid')) {
    // 某個包裝站別（bom_ing_fid）目前所有的包裝檢驗紀錄（可能有好幾批），依建立順序排列。
    // 只讀不寫，供「全製程合併列印」把包裝檢驗結果一併印出來用，不影響 packing_schedule.php
    // 既有的暫存/結案狀態機。
    function pk_packing_rows_for_fid(PDO $pdo, int $fid): array
    {
        try {
            $st = $pdo->prepare("
                SELECT packing_inspection_id, inspection_date, judgement, status,
                       order_qty, bom_total_qty, ok_qty, ng_qty, ship_now_qty, warehouse_qty,
                       packer, inspector, remark, created_at, closed_by, closed_at
                FROM qc_packing_inspection
                WHERE bom_ing_fid = ?
                ORDER BY packing_inspection_id ASC
            ");
            $st->execute([$fid]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}
