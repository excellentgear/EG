<?php
/**
 * audit_log_lib.php — audit_log 保留/歸檔共用函式
 *
 * audit_log 是全站共用的操作歷史紀錄表（~30 個模組各自寫入），目前完全沒有保留/
 * 歸檔機制、且被 data_console 列為永久唯讀表（不給人工編輯/刪除，避免破壞可追溯性）。
 * 新增主檔管理角色權限與標籤稽核後，寫入量會更快成長，故比照 login_log.php 既有的
 * 「順路觸發、低機率清理」模式，但**歸檔而不刪除**（搬進 audit_log_archive，保留
 * 可追溯性，只是不再佔用常用查詢的熱表）。
 *
 * 硬性要求：完全靜默，歸檔失敗絕不可影響呼叫端原本的業務動作。
 */

if (!function_exists('eg_audit_log_archive_tick')) {
    /**
     * @param PDO $pdo
     * @param int $retainMonths 保留月數（預設24個月＝2年），更舊的搬進 audit_log_archive
     * @param int $batchLimit   每次觸發最多搬移幾筆（避免長時間鎖表）
     */
    function eg_audit_log_archive_tick(PDO $pdo, int $retainMonths = 24, int $batchLimit = 2000): void
    {
        try {
            // 約 2% 機率觸發，比照 login_log.php 既有做法；不依賴排程器
            if (mt_rand(1, 50) !== 1) return;

            $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log_archive (
                id          BIGINT       NOT NULL,
                action_type VARCHAR(20)  NOT NULL,
                target_type VARCHAR(30)  NOT NULL,
                target_id   VARCHAR(200) NOT NULL,
                target_name VARCHAR(200) NULL,
                changes     TEXT         NULL COMMENT 'JSON [{field,old,new}]',
                user_id     INT          NULL,
                operator    VARCHAR(100) NULL,
                created_at  DATETIME     NOT NULL,
                archived_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tt (target_type),
                KEY idx_ti (target_id(50)),
                KEY idx_ca (created_at),
                KEY idx_ui (user_id)
            ) COMMENT='audit_log 歸檔表：保留期限外的舊紀錄搬移至此，不刪除、維持可追溯性'");

            $cutoff = date('Y-m-d H:i:s', strtotime("-{$retainMonths} months"));
            $idStmt = $pdo->prepare("SELECT id FROM audit_log WHERE created_at < ? ORDER BY id ASC LIMIT " . (int)$batchLimit);
            $idStmt->execute([$cutoff]);
            $ids = $idStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) return;
            $inList = implode(',', array_map('intval', $ids));

            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO audit_log_archive (id,action_type,target_type,target_id,target_name,changes,user_id,operator,created_at)
                        SELECT id,action_type,target_type,target_id,target_name,changes,user_id,operator,created_at
                        FROM audit_log WHERE id IN ($inList)
                        ON DUPLICATE KEY UPDATE archived_at=archived_at");
            $pdo->exec("DELETE FROM audit_log WHERE id IN ($inList)");
            $pdo->commit();
        } catch (Throwable $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}
        }
    }
}
