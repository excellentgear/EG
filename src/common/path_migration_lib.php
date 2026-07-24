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

// ── 已知設定鍵的完整說明:中文名/使用頁面/存放位置（自動探索到但不在表上的,標「其他」）──
function eg_pm_setting_meta(): array {
    $ss = 'system_settings 資料表';
    return [
        'sales_nas_dir'            => ['label'=>'業務追蹤圖片(NAS實體路徑)', 'store'=>$ss, 'pages'=>['Sales/NewOrder_Track.php 訂單追蹤']],
        'sales_url_dir'            => ['label'=>'業務追蹤圖片(網頁別名)',    'store'=>$ss.'(對應Apache /nas別名)', 'pages'=>['Sales/NewOrder_Track.php 訂單追蹤']],
        'part_attach_nas_dir'      => ['label'=>'料號附件(NAS實體路徑)',     'store'=>$ss, 'pages'=>['pages/master_data_management.php 主檔管理','pm/bom_viewer.php 附件三分頁','Sales/quotation_list_NEW.php 報價附件']],
        'part_attach_url_dir'      => ['label'=>'料號附件(網頁別名)',        'store'=>$ss.'(對應Apache /nas別名)', 'pages'=>['同上']],
        'notes_nas_dir'            => ['label'=>'技術備註圖片(NAS實體路徑)', 'store'=>$ss, 'pages'=>['pages/master_data_management.php 技術備註']],
        'notes_url_dir'            => ['label'=>'技術備註圖片(網頁別名)',    'store'=>$ss.'(對應Apache /nas別名)', 'pages'=>['同上']],
        'order_change_attach_dir'  => ['label'=>'訂單變更單附件',           'store'=>$ss, 'pages'=>['Sales/NewOrder_Track.php 訂單變更']],
        'ptask_nas_dir'            => ['label'=>'個人工作紀錄附圖(NAS實體路徑)','store'=>$ss, 'pages'=>['個人工作紀錄頁']],
        'ptask_url_dir'            => ['label'=>'個人工作紀錄附圖(網頁別名)','store'=>$ss.'(對應Apache /nas別名)', 'pages'=>['同上']],
        'as_doc_nas_dir'           => ['label'=>'AS9100文件庫',             'store'=>$ss, 'pages'=>['ADM/as_document_management.php','ADM/as_document_online.php']],
        'imgedit_label_nas_dir'    => ['label'=>'批圖編輯器標籤庫',          'store'=>$ss, 'pages'=>['Sales/image_editor.php 批圖編輯器']],
        'qc_form_nas_dir'          => ['label'=>'QC線上表單',               'store'=>$ss, 'pages'=>['QC 線上檢驗各頁']],
        'drawingrename_source_dir' => ['label'=>'圖面改檔名-來源資料夾',     'store'=>$ss, 'pages'=>['pm/drawing_rename.php']],
        'drawingrename_output_dir' => ['label'=>'圖面改檔名-輸出資料夾',     'store'=>$ss, 'pages'=>['pm/drawing_rename.php']],
    ];
}
// 相容舊介面
function eg_pm_setting_labels(): array {
    $out = [];
    foreach (eg_pm_setting_meta() as $k => $m) $out[$k] = $m['label'];
    return $out;
}

// ── 路徑變更紀錄 ─────────────────────────────────────────────────────────────
function eg_pm_log_change(PDO $pdo, string $scope, string $target, ?string $old, ?string $new, int $rows, string $by): void {
    try {
        $pdo->prepare("INSERT INTO path_change_log (scope,target,old_value,new_value,affected_rows,changed_by) VALUES (?,?,?,?,?,?)")
            ->execute([$scope, $target, $old, $new, $rows, $by]);
    } catch (Throwable $e) {}
}
function eg_pm_changelog(PDO $pdo, int $limit = 100): array {
    try {
        return $pdo->query("SELECT scope,target,old_value,new_value,affected_rows,changed_by,changed_at
                            FROM path_change_log ORDER BY id DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
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
    $meta = eg_pm_setting_meta();
    $settings = [];
    // system_settings 全部路徑類（已知的 + 自動探索）
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings
                             WHERE setting_key LIKE '%dir%' OR setting_key LIKE '%path%'
                             ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $v = (string)$r['setting_value'];
            $isUrl = (strpos($r['setting_key'], 'url') !== false) || (strlen($v) > 0 && $v[0] === '/');
            $m = $meta[$r['setting_key']] ?? null;
            $settings[] = [
                'scope'  => 'system_settings',
                'key'    => $r['setting_key'],
                'label'  => $m['label'] ?? '（其他）',
                'store'  => $m['store'] ?? 'system_settings 資料表',
                'pages'  => $m['pages'] ?? [],
                'value'  => $v,
                'kind'   => $isUrl ? 'url' : 'fs',
                'exists' => $isUrl ? null : ($v !== '' ? @is_dir($v) : null),
            ];
        }
    } catch (Throwable $e) {}
    // 備份模組自己的 NAS 路徑
    $bk = eg_bk_cfg_get($pdo, 'nas_path', '');
    $settings[] = ['scope'=>'db_backup_config','key'=>'nas_path','label'=>'資料庫備份NAS複本',
                   'store'=>'db_backup_config 資料表','pages'=>['ADM/db_backup.php 資料庫備份管理'],
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

// ── 改單一設定值（含變更紀錄）───────────────────────────────────────────────
function eg_pm_set_setting(PDO $pdo, string $scope, string $key, string $newValue, string $by): array {
    if ($scope === 'db_backup_config') {
        if ($key !== 'nas_path') return ['ok'=>false,'msg'=>'不允許的設定鍵'];
        $old = eg_bk_cfg_get($pdo, 'nas_path', '');
        eg_bk_cfg_set($pdo, 'nas_path', $newValue, $by);
        if ($old !== $newValue) eg_pm_log_change($pdo, 'db_backup_config', 'nas_path', $old, $newValue, 1, $by);
        return ['ok'=>true,'msg'=>'已更新 db_backup_config.nas_path'];
    }
    if ($scope !== 'system_settings') return ['ok'=>false,'msg'=>'不允許的範圍'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) return ['ok'=>false,'msg'=>'設定鍵不合法'];
    $chk = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
    $chk->execute([$key]);
    $old = $chk->fetchColumn();
    if ($old === false) return ['ok'=>false,'msg'=>'找不到該設定鍵'];
    $st = $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?");
    $st->execute([$newValue, $key]);
    if ((string)$old !== $newValue) eg_pm_log_change($pdo, 'system_settings', $key, (string)$old, $newValue, 1, $by);
    return ['ok'=>true,'msg'=>"已更新 {$key}"];
}

// ── 批次前綴替換（dry-run 預覽 / 實際執行;$selected=只套用勾選項,null=全部）────
// 對「所有以 $old 開頭」的:system_settings 路徑值、db_backup_config.nas_path、
// 未合規表的 file_path/preview_path 進行前綴替換。Windows 路徑不分大小寫比對。
// 每個項目有唯一 id（setting:scope:key / table:表:欄）,前端預覽勾選後把 id 陣列傳回執行。
function eg_pm_bulk_prefix(PDO $pdo, string $old, string $new, bool $dryRun, string $by, ?array $selected = null): array {
    if (mb_strlen($old) < 3) return ['ok'=>false,'msg'=>'舊前綴太短(至少3字,避免誤傷)','items'=>[]];
    $items = [];
    $lowOld = mb_strtolower($old);
    $oldLen = mb_strlen($old);
    $pick = function(string $id) use ($selected): bool {
        return $selected === null || in_array($id, $selected, true);
    };

    // 1) 設定值
    $inv = eg_pm_inventory($pdo);
    foreach ($inv['settings'] as $s) {
        if ($s['value'] === '' ) continue;
        if (mb_strtolower(mb_substr($s['value'], 0, $oldLen)) !== $lowOld) continue;
        $id = 'setting:' . $s['scope'] . ':' . $s['key'];
        if (!$pick($id)) continue;
        $newVal = $new . mb_substr($s['value'], $oldLen);
        $items[] = ['id'=>$id,'type'=>'setting','scope'=>$s['scope'],'key'=>$s['key'],'label'=>$s['label'],
                    'from'=>$s['value'],'to'=>$newVal,'cnt'=>1];
        if (!$dryRun) eg_pm_set_setting($pdo, $s['scope'], $s['key'], $newVal, $by); // set_setting 內含變更紀錄
    }

    // 2) 未合規表（UPDATE 用 LEFT()=? 比對,避免 LIKE 反斜線跳脫地雷;collation 不分大小寫）
    foreach (eg_pm_fullpath_targets() as $t) {
        foreach ($t['columns'] as $col) {
            $id = 'table:' . $t['table'] . ':' . $col;
            if (!$pick($id)) continue;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM `{$t['table']}` LIKE " . $pdo->quote($col))->fetch();
                if (!$chk) continue;
                $cntSt = $pdo->prepare("SELECT COUNT(*) FROM `{$t['table']}` WHERE LEFT(`$col`, CHAR_LENGTH(?)) = ?");
                $cntSt->execute([$old, $old]);
                $cnt = (int)$cntSt->fetchColumn();
                if ($cnt === 0) continue;
                $items[] = ['id'=>$id,'type'=>'table','table'=>$t['table'],'label'=>$t['label'],'column'=>$col,
                            'from'=>$old.'…','to'=>$new.'…','cnt'=>$cnt];
                if (!$dryRun) {
                    $up = $pdo->prepare("UPDATE `{$t['table']}`
                                         SET `$col` = CONCAT(?, SUBSTRING(`$col`, CHAR_LENGTH(?)+1))
                                         WHERE LEFT(`$col`, CHAR_LENGTH(?)) = ?");
                    $up->execute([$new, $old, $old, $old]);
                    eg_pm_log_change($pdo, 'table_prefix', $t['table'] . '.' . $col, $old, $new, $cnt, $by);
                }
            } catch (Throwable $e) {}
        }
    }
    $total = array_sum(array_column($items, 'cnt'));
    $msg = $dryRun ? "預覽:共 " . count($items) . " 項、$total 筆會被替換（尚未執行,可勾選要套用的項目）"
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
