<?php
/**
 * 2026-11-03 ERP 附件搬家的「切換設定值」步驟（檔案複製由同資料夾的 run.ps1 負責）
 *   php switch.php --show       只列出會改哪些東西
 *   php switch.php --switch     改設定值＋資料庫內的完整路徑，並設 erp_nas_moved=1（舊值寫進 rollback json）
 *   php switch.php --rollback   依 rollback json 還原（檔案已複製到新位置的不會刪）
 * 全部包在 transaction；任何一步出錯整批回復，不留半套。
 */
require_once __DIR__ . '/../../../../src/common/DBConnection.php';

$OLDU = '\\\\excellentnas\\生產課\\BOM\\ERP';
$NEW  = '\\\\excellentnas\\AS9100維護\\ERP AS9100文件(勿刪)';
// 設定鍵 => 新值（結尾反斜線與否照原本風格）
$SETTINGS = [
    'bom_erp_scan_dir'        => $NEW . '\\資材(生管and業務)\\BOM\\',
    'imgedit_label_nas_dir'   => $NEW . '\\共用資料\\標籤',
    'notes_nas_dir'           => $NEW . '\\技術',
    'notice_attach_base'      => $NEW . '\\公告通知\\公告通知附件',
    'order_change_attach_dir' => $NEW . '\\業務\\訂單資料\\訂單變更\\',
    'ptask_nas_dir'           => $NEW . '\\個人工作\\',
    'sales_nas_dir'           => $NEW . '\\業務\\',
];
// 資料庫內存「完整路徑」的欄位：把舊前置詞換成新前置詞
$FULLPATH = [
    ['car_attachment', 'file_path'], ['car_attachment', 'preview_path'],
    ['live_event_resp_file', 'file_path'], ['live_event_resp_file', 'preview_path'],
    ['live_event_response', 'reply_folder'],
    ['live_event_file', 'file_path'], ['live_event_file', 'preview_path'],
    ['qa_abnormal_attachments', 'file_path'], ['qa_abnormal_attachments', 'preview_path'],
    ['ir_attachments', 'file_path'],
];
$PREFIXES = [   // 舊前置詞的各種寫法
    '\\\\excellentnas\\生產課\\BOM\\ERP\\', '\\\\Excellentnas\\生產課\\BOM\\ERP\\',
    'Z:\\BOM\\ERP\\', 'Z:/BOM/ERP/',
];
$RB = __DIR__ . '/erp_move_rollback.json';

$mode = $argv[1] ?? '--show';
$pdo  = (new DBConnection())->getPDO();
$pdo->exec("SET NAMES utf8mb4");

function getSet(PDO $p, string $k): ?string {
    $s = $p->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?"); $s->execute([$k]);
    $v = $s->fetchColumn(); return $v === false ? null : (string)$v;
}
function hasCol(PDO $p, string $t, string $c): bool {
    $s = $p->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $c]); return (int)$s->fetchColumn() > 0;
}

if ($mode === '--show') {
    foreach ($SETTINGS as $k => $n) echo "$k\n   現在: ", getSet($pdo, $k) ?? '(無)', "\n   改成: $n\n";
    foreach ($FULLPATH as [$t, $c]) {
        if (!hasCol($pdo, $t, $c)) continue;
        $w = implode(' OR ', array_map(fn($p) => "`$c` LIKE " . $pdo->quote(str_replace('\\', '\\\\', $p) . '%'), $PREFIXES));
        echo "$t.$c : ", $pdo->query("SELECT COUNT(*) FROM `$t` WHERE $w")->fetchColumn(), " 列要換前置詞\n";
    }
    echo "QUOTATION upload_path: ", $pdo->query("SELECT param_value FROM system_parameters WHERE param_group='QUOTATION' AND param_key='upload_path'")->fetchColumn(), "\n";
    echo "car_attach_root_path: ", $pdo->query("SELECT setting_value FROM qa_system_settings WHERE setting_key='car_attach_root_path'")->fetchColumn(), "\n";
    exit(0);
}

if ($mode === '--switch') {
    $rb = ['settings' => [], 'fullpath' => [], 'quotation' => null, 'car_root' => null, 'moved_flag' => getSet($pdo, 'erp_nas_moved')];
    try {
        $pdo->beginTransaction();
        foreach ($SETTINGS as $k => $n) {
            $rb['settings'][$k] = getSet($pdo, $k);
            $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value,updated_by,updated_at) VALUES (?,?, 'Claude(erp-move)', NOW())
                           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=NOW()")->execute([$k, $n]);
        }
        foreach ($FULLPATH as [$t, $c]) {
            if (!hasCol($pdo, $t, $c)) continue;
            $pk = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($t) . " AND COLUMN_KEY='PRI' LIMIT 1")->fetchColumn();
            if (!$pk) continue;
            foreach ($PREFIXES as $p) {
                $like = str_replace('\\', '\\\\', $p) . '%';
                $rows = $pdo->prepare("SELECT `$pk`, `$c` FROM `$t` WHERE `$c` LIKE ?"); $rows->execute([$like]);
                foreach ($rows->fetchAll(PDO::FETCH_NUM) as [$id, $val]) {
                    $new = $NEW . '\\' . str_replace('/', '\\', substr($val, strlen($p)));
                    $rb['fullpath'][] = [$t, $c, $pk, $id, $val];
                    $pdo->prepare("UPDATE `$t` SET `$c`=? WHERE `$pk`=?")->execute([$new, $id]);
                }
            }
        }
        // 報價單附件根目錄（system_parameters 存 JSON 字串）
        $q = $pdo->query("SELECT param_value FROM system_parameters WHERE param_group='QUOTATION' AND param_key='upload_path'")->fetchColumn();
        if ($q !== false) {
            $rb['quotation'] = $q;
            $pdo->prepare("UPDATE system_parameters SET param_value=? WHERE param_group='QUOTATION' AND param_key='upload_path'")
                ->execute([json_encode($NEW . '\\業務\\報價資料', JSON_UNESCAPED_UNICODE)]);
        }
        // 異常矯正單附件根目錄
        $cr = $pdo->query("SELECT setting_value FROM qa_system_settings WHERE setting_key='car_attach_root_path'")->fetchColumn();
        if ($cr !== false) {
            $rb['car_root'] = $cr;
            $pdo->prepare("UPDATE qa_system_settings SET setting_value=? WHERE setting_key='car_attach_root_path'")->execute([$NEW . '\\品管\\異常矯正單附件']);
        }
        // 旗標：程式裡「寫死舊路徑」的幾個地方（退貨資料、異常單附件、/nas/ERP 網址解析）看這個決定走新或舊
        $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value,updated_by,updated_at) VALUES ('erp_nas_moved','1','Claude(erp-move)',NOW())
                       ON DUPLICATE KEY UPDATE setting_value='1', updated_at=NOW()")->execute();
        file_put_contents($RB, json_encode($rb, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "切換失敗已回復：" . $e->getMessage() . "\n"); exit(1);
    }
    echo "已切換 ", count($rb['settings']), " 個設定值、", count($rb['fullpath']), " 列完整路徑；rollback 記錄：$RB\n";
    exit(0);
}

if ($mode === '--rollback') {
    if (!is_file($RB)) { fwrite(STDERR, "找不到 rollback 記錄\n"); exit(1); }
    $rb = json_decode(file_get_contents($RB), true);
    try {
        $pdo->beginTransaction();
        foreach ($rb['settings'] as $k => $v) {
            if ($v === null) $pdo->prepare("DELETE FROM system_settings WHERE setting_key=?")->execute([$k]);
            else $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
        }
        foreach ($rb['fullpath'] as [$t, $c, $pk, $id, $val]) $pdo->prepare("UPDATE `$t` SET `$c`=? WHERE `$pk`=?")->execute([$val, $id]);
        if ($rb['quotation'] !== null) $pdo->prepare("UPDATE system_parameters SET param_value=? WHERE param_group='QUOTATION' AND param_key='upload_path'")->execute([$rb['quotation']]);
        if ($rb['car_root'] !== null) $pdo->prepare("UPDATE qa_system_settings SET setting_value=? WHERE setting_key='car_attach_root_path'")->execute([$rb['car_root']]);
        $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='erp_nas_moved'")->execute([$rb['moved_flag'] ?? '0']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fwrite(STDERR, "還原失敗：" . $e->getMessage() . "\n"); exit(1); }
    echo "已還原。新位置已複製的檔案不會刪除；新位置在還原期間新增的檔案需另行複製回舊位置。\n";
    exit(0);
}
echo "用法：--show | --switch | --rollback\n";
