<?php
/**
 * 審核表單引擎：新增「綁來源訂單」欄位（2026-10-02 使用者交辦—合約訂單審查表）
 *
 *   rf_template.src_bind    1＝這個模板建立表單時必須挑一張訂單（目前只有合約訂單審查表會開）
 *   rf_instance.src_order_id 這張表單對應的訂單 order_track.Order_id（NULL＝沒綁，既有表單全部如此）
 *
 * 可重複執行。DDL 一律不在交易裡跑（MySQL 的 ALTER 會造成隱式 commit，外層 commit 會爆
 * 「There is no active transaction」——本專案 2026-08-03／2026-09-21 各踩過一次）。
 * ADD COLUMN 支援 IF NOT EXISTS，**但 ADD INDEX 不支援**，所以索引要先 SHOW INDEX 查過再決定。
 *
 * 用法： php views/ADM/migrations/2026-10-02_review_form_src_order.php          （試算，不寫入）
 *        php views/ADM/migrations/2026-10-02_review_form_src_order.php --run    （實際執行）
 */

require_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$run = in_array('--run', $argv ?? [], true);
$say = function (string $s) { echo $s . PHP_EOL; };

$say($run ? '=== 實際執行 ===' : '=== 試算（加 --run 才真的寫入）===');

/** 這張表有沒有這個欄位 */
$hasCol = function (PDO $db, string $tbl, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$tbl, $col]);
    return (bool)$st->fetchColumn();
};
/** 這張表有沒有這個索引（ADD INDEX 沒有 IF NOT EXISTS，一定要先查） */
$hasIdx = function (PDO $db, string $tbl, string $idx): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $st->execute([$tbl, $idx]);
    return (bool)$st->fetchColumn();
};

$steps = [];

if ($hasCol($pdo, 'rf_template', 'src_bind')) {
    $say('[略過] rf_template.src_bind 已存在');
} else {
    $steps[] = ["rf_template.src_bind",
        "ALTER TABLE rf_template ADD COLUMN src_bind TINYINT(1) NOT NULL DEFAULT 0
         COMMENT '1=建立表單時必須挑一張訂單（合約訂單審查表）' AFTER has_year_heading"];
}

if ($hasCol($pdo, 'rf_instance', 'src_order_id')) {
    $say('[略過] rf_instance.src_order_id 已存在');
} else {
    $steps[] = ["rf_instance.src_order_id",
        "ALTER TABLE rf_instance ADD COLUMN src_order_id INT DEFAULT NULL
         COMMENT '來源訂單 order_track.Order_id；NULL=沒綁' AFTER year_heading"];
}

if ($hasIdx($pdo, 'rf_instance', 'idx_rfi_src_order')) {
    $say('[略過] rf_instance 索引 idx_rfi_src_order 已存在');
} else {
    // 查「這張訂單審過了沒」是逐頁清單都會問的，一定要有索引（不加索引＝每頁全表掃）
    $steps[] = ["rf_instance 索引 idx_rfi_src_order",
        "ALTER TABLE rf_instance ADD INDEX idx_rfi_src_order (template_id, src_order_id, status)"];
}

if (!$steps) { $say('沒有需要變更的項目，資料結構已是最新。'); exit(0); }

foreach ($steps as [$label, $sql]) {
    if (!$run) { $say("[待執行] {$label}"); continue; }
    try {
        $pdo->exec($sql);
        $say("[完成] {$label}");
    } catch (Throwable $e) {
        $say("[失敗] {$label}：" . $e->getMessage());
        exit(1);
    }
}

if ($run) {
    // 刻意不預設把任何既有模板設成 src_bind=1：那會讓既有 4 張表單（產品安全、仿冒零件防制、
    // 防護、環境審查）突然變成「一定要挑訂單才能建立」，等於改壞現有功能。
    $say('完成。既有模板的 src_bind 全部維持 0，現有 4 張表單的建立流程完全不受影響。');
}
