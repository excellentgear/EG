<?php
// =============================================================================
// 一次性 migration：角色指派新增「部門＋職稱」這一層
//   position_roles 新增 department_id：
//     0（預設）＝ 該職稱在「所有部門」都適用（＝這張表原本的語意，既有資料自動落在這一層，行為不變）
//     >0        ＝ 只有「該部門的該職稱」適用
//   為什麼一定要帶部門：職稱是跨部門共用的——實測「組員」橫跨 7 個部門 22 人、「組長」橫跨 7 個部門，
//   只綁職稱會讓品管組員拿到業務組員的權限。
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\user\migrations\2026-08-27_dept_position_roles.php
// 可重複執行。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$before = $pdo->query("SELECT COUNT(*) FROM position_roles")->fetchColumn();

if ($pdo->query("SHOW COLUMNS FROM position_roles LIKE 'department_id'")->fetch()) {
    echo "position_roles.department_id 已存在，略過\n";
} else {
    // NOT NULL DEFAULT 0：既有列自動變成 0（全部門通用），語意與加欄位前完全相同
    $pdo->exec("ALTER TABLE position_roles
                ADD COLUMN department_id INT NOT NULL DEFAULT 0
                COMMENT '0=該職稱所有部門通用（原本語意）；>0=僅該部門的該職稱'
                FIRST");
    // 主鍵要納入 department_id，同一職稱才能在不同部門各自設定
    $pdo->exec("ALTER TABLE position_roles DROP PRIMARY KEY, ADD PRIMARY KEY (department_id, position_id, role_id)");
    $pdo->exec("ALTER TABLE position_roles ADD KEY idx_pr_pos (position_id)");
    echo "position_roles.department_id 已新增（既有 {$before} 列全部為 0＝全部門通用）\n";
}

$rows = $pdo->query("SELECT department_id, position_id, role_id FROM position_roles")->fetchAll(PDO::FETCH_ASSOC);
echo "目前內容：" . json_encode($rows) . "\n";
$after = $pdo->query("SELECT COUNT(*) FROM position_roles")->fetchColumn();
echo ($before == $after ? "列數未變（{$after}）\n" : "!! 列數改變 {$before} -> {$after}\n");
echo "done\n";
