<?php
/**
 * 稽核建議修改：AS文件／內部條文改成可複選（一筆意見常常混著提到好幾份文件、好幾條條文），
 * 原本 as_audit_recommend.as_doc_id / clause_id 各只存一個 id 改成兩張多對多關聯表。
 * 使用者 2026-10-05 回報：「不一定只有一項，可能會有多項混合寫」。
 *
 *  - as_audit_recommend_doc    (rec_id, as_doc_id)   一筆意見可綁多份 AS 文件
 *  - as_audit_recommend_clause (rec_id, clause_id)   一筆意見可綁多條內部條文
 *
 * 既有資料（as_doc_id / clause_id 兩欄若有值）先搬進新表，搬完才刪掉舊欄位——
 * 這兩欄當時已經有使用者實際填入的真實資料（非測試資料），不可以直接丟棄。
 * 可重複執行。
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\ADM\migrations\2026-10-05_audit_rec_multi_bind.php
 */
$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

$db = (new DBConnection())->getPDO();

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}
function tableExists(PDO $db, string $table): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

// ① 建兩張關聯表
if (!tableExists($db, 'as_audit_recommend_doc')) {
    $db->exec("CREATE TABLE as_audit_recommend_doc (
        id INT NOT NULL AUTO_INCREMENT,
        rec_id INT NOT NULL,
        as_doc_id INT NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_rec_doc (rec_id, as_doc_id),
        KEY idx_as_doc (as_doc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='稽核建議修改 ↔ AS文件 多對多'");
    echo "已建立：as_audit_recommend_doc\n";
} else { echo "略過（已存在）：as_audit_recommend_doc\n"; }

if (!tableExists($db, 'as_audit_recommend_clause')) {
    $db->exec("CREATE TABLE as_audit_recommend_clause (
        id INT NOT NULL AUTO_INCREMENT,
        rec_id INT NOT NULL,
        clause_id INT NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_rec_clause (rec_id, clause_id),
        KEY idx_clause (clause_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='稽核建議修改 ↔ 內部條文 多對多'");
    echo "已建立：as_audit_recommend_clause\n";
} else { echo "略過（已存在）：as_audit_recommend_clause\n"; }

// ② 把舊單一欄位的既有資料搬進新表（只在舊欄位還存在時才搬，重複執行不會重複搬：INSERT IGNORE + UNIQUE鍵）
if (colExists($db, 'as_audit_recommend', 'as_doc_id')) {
    $n = $db->exec("INSERT IGNORE INTO as_audit_recommend_doc (rec_id, as_doc_id)
                     SELECT id, as_doc_id FROM as_audit_recommend WHERE as_doc_id IS NOT NULL");
    echo "搬移 as_doc_id 既有資料：$n 筆\n";
}
if (colExists($db, 'as_audit_recommend', 'clause_id')) {
    $n = $db->exec("INSERT IGNORE INTO as_audit_recommend_clause (rec_id, clause_id)
                     SELECT id, clause_id FROM as_audit_recommend WHERE clause_id IS NOT NULL");
    echo "搬移 clause_id 既有資料：$n 筆\n";
}

// ③ 搬完才能刪舊欄位（先驗證新表筆數 >= 舊欄位非空筆數，確認沒有漏搬才刪）
if (colExists($db, 'as_audit_recommend', 'as_doc_id')) {
    $oldCnt = (int)$db->query("SELECT COUNT(*) FROM as_audit_recommend WHERE as_doc_id IS NOT NULL")->fetchColumn();
    $newCnt = (int)$db->query("SELECT COUNT(DISTINCT rec_id) FROM as_audit_recommend_doc")->fetchColumn();
    if ($newCnt >= $oldCnt) {
        $db->exec("ALTER TABLE as_audit_recommend DROP COLUMN as_doc_id");
        echo "已刪除舊欄位：as_audit_recommend.as_doc_id（搬移確認 $oldCnt 筆無誤）\n";
    } else {
        echo "警告：as_doc_id 搬移筆數對不上（舊 $oldCnt／新 $newCnt），暫不刪除舊欄位，請人工確認\n";
    }
}
if (colExists($db, 'as_audit_recommend', 'clause_id')) {
    $oldCnt = (int)$db->query("SELECT COUNT(*) FROM as_audit_recommend WHERE clause_id IS NOT NULL")->fetchColumn();
    $newCnt = (int)$db->query("SELECT COUNT(DISTINCT rec_id) FROM as_audit_recommend_clause")->fetchColumn();
    if ($newCnt >= $oldCnt) {
        $db->exec("ALTER TABLE as_audit_recommend DROP COLUMN clause_id");
        echo "已刪除舊欄位：as_audit_recommend.clause_id（搬移確認 $oldCnt 筆無誤）\n";
    } else {
        echo "警告：clause_id 搬移筆數對不上（舊 $oldCnt／新 $newCnt），暫不刪除舊欄位，請人工確認\n";
    }
}

echo "完成。\n";
