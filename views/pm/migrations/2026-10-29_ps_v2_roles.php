<?php
// 待加工排程 V2.0 預設角色（可重複執行；已存在的角色不動）。CLI：php 2026-10-29_ps_v2_roles.php
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/ps_v2_perm_lib.php';
$pdo = (new DBConnection())->getPDO();
echo '新建角色數：' . ps2_seed_roles($pdo) . "\n";
foreach ($pdo->query("SELECT r.role_id, r.role_name, COUNT(rf.feature_code) n FROM roles r LEFT JOIN role_features rf ON rf.role_id=r.role_id WHERE r.module='ps_v2' GROUP BY r.role_id") as $r) {
    echo $r['role_id'] . ' ' . $r['role_name'] . ' 功能碼 ' . $r['n'] . "\n";
}
