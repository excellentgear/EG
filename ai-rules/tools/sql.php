<?php
/**
 * AI 專用 SQL 執行工具（CLI）
 * 用法：
 *   查詢：  & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\sql.php "SELECT * FROM page_change_log ORDER BY id DESC LIMIT 5"
 *   從檔案：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\sql.php --file 路徑\query.sql
 * 規則（工具會強制執行）：
 *   - 一次只准一句 SQL（字串常值裡的分號不算）
 *   - 拒絕 DROP/TRUNCATE/ALTER/GRANT/REVOKE
 *   - 拒絕沒有 WHERE 的 DELETE/UPDATE
 *   - 中文 SQL 一律用 --file（避免 Windows 命令列編碼問題）
 * 輸出：SELECT/SHOW 類回 JSON；寫入類回 affectedRows 與 lastInsertId
 * 注意：帳密與 src/common/_config.php 相同（EG-TS2024）；若該處改密碼，本檔要同步修改。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');

if (isset($argv[1]) && $argv[1] === '--file') {
    if (!isset($argv[2]) || !is_file($argv[2])) { fwrite(STDERR, "ERROR: file not found\n"); exit(1); }
    $sql = file_get_contents($argv[2]);
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql); // 去 BOM（PowerShell 寫檔常帶）
} elseif (isset($argv[1])) {
    $sql = $argv[1];
} else {
    fwrite(STDERR, "ERROR: no SQL given\n"); exit(1);
}

// --- 安全檢查（在送出前於本地做，全部針對「去除字串常值與前導註解」後的版本）---
$stripped = preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", "''", $sql); // 挖空字串常值
$stripped = preg_replace('/^(\s|\/\*.*?\*\/|--[^\n]*\n|#[^\n]*\n)+/s', '', $stripped);      // 剝前導註解
$stmts = array_values(array_filter(array_map('trim', explode(';', $stripped)), 'strlen'));

if (count($stmts) > 1) {
    fwrite(STDERR, "REFUSED: multiple statements. Run one statement at a time.\n"); exit(2);
}
if (count($stmts) === 0) { fwrite(STDERR, "ERROR: empty SQL\n"); exit(1); }
if (preg_match('/^(DROP|TRUNCATE|ALTER|GRANT|REVOKE)\b/i', $stmts[0])) {
    fwrite(STDERR, "REFUSED: destructive DDL not allowed via this tool. Ask the user.\n"); exit(2);
}
if (preg_match('/^(DELETE|UPDATE)\b/i', $stmts[0]) && !preg_match('/\bWHERE\b/i', $stmts[0])) {
    fwrite(STDERR, "REFUSED: DELETE/UPDATE without WHERE. Add a WHERE clause.\n"); exit(2);
}

try {
    $db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
                  "EG-TS2024", "excell30367593",
                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                   PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
    $stmt = $db->query($sql);
    if (preg_match('/^(SELECT|SHOW|DESC|DESCRIBE|EXPLAIN|WITH)\b/i', $stmts[0])) {
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['rowCount' => count($rows), 'rows' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    } else {
        echo json_encode(['affectedRows' => $stmt->rowCount(),
                          'lastInsertId' => $db->lastInsertId()],
            JSON_UNESCAPED_UNICODE), "\n";
    }
} catch (PDOException $e) {
    fwrite(STDERR, "SQL ERROR: " . $e->getMessage() . "\n"); exit(3);
}
