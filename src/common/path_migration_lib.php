<?php
/**
 * path_migration_lib.php — NAS/磁碟路徑遷移工具（換 NAS、換機、測試機演練用）
 * 由 DBBackup_API.php 呼叫（僅管理員）。三件事:
 *   1. 盤點:全系統路徑設定值 + 存完整路徑的資料表前綴 + 程式碼寫死路徑的頁面(唯讀提醒)
 *   2. 重對應:改設定值 / 批次替換 DB 內路徑前綴（支援預覽 dry-run）
 *   3. 搬檔:robocopy 背景複製舊位置→新位置（狀態存 db_backup_config）
 * 對應 ai-rules/07-附件路徑儲存規範.md 的合規(存檔名)與未合規(存完整路徑)兩類模組。
 */

require_once __DIR__ . '/db_backup_lib.php'; // 共用 eg_bk_cfg_*、eg_bk_exec、BK_PHP

// ── 已知設定鍵的中文說明（自動探索到但不在表上的,標「其他」）─────────────────
function eg_pm_setting_labels(): array {
    return [
        'sales_nas_dir'            => '業務追蹤圖片(NAS實體路徑)',
        'sales_url_dir'            => '業務追蹤圖片(網頁別名,對應Apache alias)',
        'part_attach_nas_dir'      => '料號附件(NAS實體路徑)',
        'part_attach_url_dir'      => '料號附件(網頁別名)',
        'notes_nas_dir'            => '技術備註圖片(NAS實體路徑)',
        'notes_url_dir'            => '技術備註圖片(網頁別名)',
        'order_change_attach_dir'  => '訂單變更單附件',
        'ptask_nas_dir'            => '個人工作紀錄附圖(NAS實體路徑)',
        'ptask_url_dir'            => '個人工作紀錄附圖(網頁別名)',
        'as_doc_nas_dir'           => 'AS9100文件庫',
        'imgedit_label_nas_dir'    => '批圖編輯器標籤庫',
        'qc_form_nas_dir'          => 'QC線上表單',
        'drawingrename_source_dir' => '圖面改檔名-來源資料夾',
        'drawingrename_output_dir' => '圖面改檔名-輸出資料夾',
    ];
}

// ── 存「完整路徑」的資料表白名單（未合規模組,遷移時要批次改前綴）──────────────
function eg_pm_fullpath_targets(): array {
    return [
        ['table'=>'qa_abnormal_attachments', 'columns'=>['file_path','preview_path'], 'label'=>'QA異常單附件'],
        ['table'=>'car_attachment',          'columns'=>['file_path','preview_path'], 'label'=>'CAR矯正單附件'],
        ['table'=>'live_event_file',         'columns'=>['file_path','preview_path'], 'label'=>'公告附件'],
        ['table'=>'ir_attachments',          'columns'=>['file_path'],                'label'=>'IR退貨附件'],
    ];
}

// ── 程式碼寫死路徑的頁面（唯讀提醒;改法=新環境維持相同 Z: 映射與 excellentnas 名稱）──
function eg_pm_hardcoded_pages(): array {
    return ['views/pm/bom_viewer.php','views/pm/part_viewer.php','views/pm/bom_download.php',
            'views/pm/bom_rename.php','views/pm/drawing_rename.php','views/Sales/NewOrder_Track.php',
            'views/Sales/IR_Track.php','views/Sales/image_editor.php','views/QC/inspection_result_entry.php',
            'views/pages/master_data_management.php','views/liveEvent/createEvent.php',
            'views/pm/ERP_Cost_Analysis.php','views/pm/kpi_main.php','views/pm/Transfer_Log_Analysis.php',
            'views/pm/OreadyReply_ForPm_BaseOfTime_ajax.php','views/pm/OreadyReply_ForPm_BaseOfTime2_ajax.php'];
}

// ── 盤點 ────────────────────────────────────────────────────────────────────
function eg_pm_inventory(PDO $pdo): array {
    $labels = eg_pm_setting_labels();
    $settings = [];
    // system_settings 全部路徑類（已知的 + 自動探索）
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings
                             WHERE setting_key LIKE '%dir%' OR setting_key LIKE '%path%'
                             ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $v = (string)$r['setting_value'];
            $isUrl = (strpos($r['setting_key'], 'url') !== false) || (strlen($v) > 0 && $v[0] === '/');
            $settings[] = [
                'scope'  => 'system_settings',
                'key'    => $r['setting_key'],
                'label'  => $labels[$r['setting_key']] ?? '（其他）',
                'value'  => $v,
                'kind'   => $isUrl ? 'url' : 'fs',
                'exists' => $isUrl ? null : ($v !== '' ? @is_dir($v) : null),
            ];
        }
    } catch (Throwable $e) {}
    // 備份模組自己的 NAS 路徑
    $bk = eg_bk_cfg_get($pdo, 'nas_path', '');
    $settings[] = ['scope'=>'db_backup_config','key'=>'nas_path','label'=>'資料庫備份NAS複本',
                   'value'=>$bk,'kind'=>'fs','exists'=>($bk !== '' ? @is_dir($bk) : null)];

    // 存完整路徑的表:前綴分布
    $tables = [];
    foreach (eg_pm_fullpath_targets() as $t) {
        foreach ($t['columns'] as $col) {
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM `{$t['table']}` LIKE " . $pdo->quote($col))->fetch();
                if (!$chk) continue;
                $rows = $pdo->query("SELECT LEFT(`$col`, 60) AS prefix, COUNT(*) AS cnt
                                     FROM `{$t['table']}` WHERE `$col` IS NOT NULL AND `$col` <> ''
                                     GROUP BY LEFT(`$col`, 60) ORDER BY cnt DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $tables[] = ['table'=>$t['table'],'label'=>$t['label'],'column'=>$col,
                                 'prefix'=>$r['prefix'],'cnt'=>(int)$r['cnt']];
                }
            } catch (Throwable $e) {}
        }
    }
    return ['settings'=>$settings, 'fullpath'=>$tables, 'hardcoded'=>eg_pm_hardcoded_pages()];
}

// ── 改單一設定值 ─────────────────────────────────────────────────────────────
function eg_pm_set_setting(PDO $pdo, string $scope, string $key, string $newValue, string $by): array {
    if ($scope === 'db_backup_config') {
        if ($key !== 'nas_path') return ['ok'=>false,'msg'=>'不允許的設定鍵'];
        eg_bk_cfg_set($pdo, 'nas_path', $newValue, $by);
        return ['ok'=>true,'msg'=>'已更新 db_backup_config.nas_path'];
    }
    if ($scope !== 'system_settings') return ['ok'=>false,'msg'=>'不允許的範圍'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) return ['ok'=>false,'msg'=>'設定鍵不合法'];
    $st = $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?");
    $st->execute([$newValue, $key]);
    if ($st->rowCount() === 0) {
        // 該鍵可能不存在 → 不主動新增（避免打錯鍵名還以為成功）
        $chk = $pdo->prepare("SELECT 1 FROM system_settings WHERE setting_key=?");
        $chk->execute([$key]);
        if (!$chk->fetchColumn()) return ['ok'=>false,'msg'=>'找不到該設定鍵'];
    }
    return ['ok'=>true,'msg'=>"已更新 {$key}"];
}

// ── 批次前綴替換（dry-run 預覽 / 實際執行）──────────────────────────────────
// 對「所有以 $old 開頭」的:system_settings 路徑值、db_backup_config.nas_path、
// 未合規表的 file_path/preview_path 進行前綴替換。Windows 路徑不分大小寫比對。
function eg_pm_bulk_prefix(PDO $pdo, string $old, string $new, bool $dryRun, string $by): array {
    if (mb_strlen($old) < 3) return ['ok'=>false,'msg'=>'舊前綴太短(至少3字,避免誤傷)','items'=>[]];
    $items = [];
    $lowOld = mb_strtolower($old);
    $oldLen = mb_strlen($old);

    // 1) 設定值
    $inv = eg_pm_inventory($pdo);
    foreach ($inv['settings'] as $s) {
        if ($s['value'] === '' ) continue;
        if (mb_strtolower(mb_substr($s['value'], 0, $oldLen)) !== $lowOld) continue;
        $newVal = $new . mb_substr($s['value'], $oldLen);
        $items[] = ['type'=>'setting','scope'=>$s['scope'],'key'=>$s['key'],'label'=>$s['label'],
                    'from'=>$s['value'],'to'=>$newVal,'cnt'=>1];
        if (!$dryRun) eg_pm_set_setting($pdo, $s['scope'], $s['key'], $newVal, $by);
    }

    // 2) 未合規表（UPDATE 用 LEFT()=? 比對,避免 LIKE 反斜線跳脫地雷;collation 不分大小寫）
    foreach (eg_pm_fullpath_targets() as $t) {
        foreach ($t['columns'] as $col) {
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM `{$t['table']}` LIKE " . $pdo->quote($col))->fetch();
                if (!$chk) continue;
                $cntSt = $pdo->prepare("SELECT COUNT(*) FROM `{$t['table']}` WHERE LEFT(`$col`, CHAR_LENGTH(?)) = ?");
                $cntSt->execute([$old, $old]);
                $cnt = (int)$cntSt->fetchColumn();
                if ($cnt === 0) continue;
                $items[] = ['type'=>'table','table'=>$t['table'],'label'=>$t['label'],'column'=>$col,
                            'from'=>$old.'…','to'=>$new.'…','cnt'=>$cnt];
                if (!$dryRun) {
                    $up = $pdo->prepare("UPDATE `{$t['table']}`
                                         SET `$col` = CONCAT(?, SUBSTRING(`$col`, CHAR_LENGTH(?)+1))
                                         WHERE LEFT(`$col`, CHAR_LENGTH(?)) = ?");
                    $up->execute([$new, $old, $old, $old]);
                }
            } catch (Throwable $e) {}
        }
    }
    $total = array_sum(array_column($items, 'cnt'));
    $msg = $dryRun ? "預覽:共 " . count($items) . " 項、$total 筆會被替換（尚未執行）"
                   : "已替換 " . count($items) . " 項、共 $total 筆";
    return ['ok'=>true,'msg'=>$msg,'items'=>$items];
}

// ── 檔案搬運（robocopy 背景工人）────────────────────────────────────────────
function eg_pm_copy_status(PDO $pdo): array {
    return [
        'status' => eg_bk_cfg_get($pdo, 'pathcopy_status', 'idle'),   // idle|running|done|fail
        'src'    => eg_bk_cfg_get($pdo, 'pathcopy_src', ''),
        'dst'    => eg_bk_cfg_get($pdo, 'pathcopy_dst', ''),
        'log'    => eg_bk_cfg_get($pdo, 'pathcopy_log', ''),
    ];
}

function eg_pm_copy_start(PDO $pdo, string $src, string $dst, string $by): array {
    if ($src === '' || $dst === '') return ['ok'=>false,'msg'=>'來源與目的地都要填'];
    if (!@is_dir($src)) return ['ok'=>false,'msg'=>'來源資料夾不存在或不可讀:' . $src];
    if (eg_pm_copy_status($pdo)['status'] === 'running') return ['ok'=>false,'msg'=>'已有一個複製工作進行中'];
    eg_bk_cfg_set($pdo, 'pathcopy_status', 'running', $by);
    eg_bk_cfg_set($pdo, 'pathcopy_src', $src, $by);
    eg_bk_cfg_set($pdo, 'pathcopy_dst', $dst, $by);
    eg_bk_cfg_set($pdo, 'pathcopy_log', '', $by);
    $script = realpath(__DIR__ . '/path_copy_run.php');
    if (!is_file(BK_PHP) || !$script) return ['ok'=>false,'msg'=>'找不到複製工人程式'];
    $cmd = 'start /B "" "' . BK_PHP . '" "' . $script . '" "' . str_replace('"', '', $src) . '" "' . str_replace('"', '', $dst) . '" >NUL 2>&1';
    $h = @popen($cmd, 'r'); if ($h) @pclose($h);
    return ['ok'=>true,'msg'=>'已開始背景複製(robocopy 鏡像新增,不刪目的地既有檔),可輪詢狀態'];
}
