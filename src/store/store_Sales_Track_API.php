<?php
session_start();
header('Content-Type: application/json');

include '../common/DBConnection.php';
include '../common/_config.php';

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$user_id = intval($_SESSION['id'] ?? 0);
session_write_close(); // 立即釋放 session 鎖，避免多個 AJAX 請求互相等待

// ── 自動建立資料表 ──────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_track` (
        `track_id`         INT          AUTO_INCREMENT PRIMARY KEY COMMENT '追蹤主鍵',
        `track_date`       DATE         NOT NULL                   COMMENT '追蹤日期',
        `source_dept_id`   INT          NOT NULL                   COMMENT '來源部門 FK→department.id',
        `source_user_id`   INT          NOT NULL                   COMMENT '來源人員 FK→user.id',
        `assignee_id`      INT          NOT NULL                   COMMENT '負責業務 FK→user.id',
        `customer_id`      CHAR(11)     NOT NULL                   COMMENT '客戶ID FK→customer_list.customer_id',
        `d_setting_id`     INT          NULL                       COMMENT '料號ID FK→d_setting.d_id（與non_std_part擇一）',
        `non_std_part`     VARCHAR(100) NULL                       COMMENT '非超正料號（自由輸入，與d_setting_id擇一）',
        `description`      TEXT         NULL                       COMMENT '說明',
        `status`           ENUM('active','completed') NOT NULL DEFAULT 'active' COMMENT '狀態：active=進行中 completed=完工',
        `completed_at`     DATETIME     NULL                       COMMENT '完工時間',
        `completed_by`     INT          NULL                       COMMENT '完工人 FK→user.id',
        `boss_reviewed`    TINYINT      NOT NULL DEFAULT 0         COMMENT 'BOSS已閱 0=未閱 1=已閱',
        `boss_reviewed_at` DATETIME     NULL                       COMMENT 'BOSS已閱時間',
        `boss_reviewed_by` INT          NULL                       COMMENT 'BOSS已閱人 FK→user.id',
        `created_by`       INT          NOT NULL                   COMMENT '建檔人 FK→user.id',
        `modified_by`      INT          NULL                       COMMENT '最後修改人 FK→user.id',
        `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
        `updated_at`       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP   COMMENT '修改時間',
        INDEX `idx_date`     (`track_date`),
        INDEX `idx_assignee` (`assignee_id`),
        INDEX `idx_customer` (`customer_id`),
        INDEX `idx_status`   (`status`)
    ) COMMENT='業務追蹤主表：記錄業務跟催事項，含客戶/料號/負責人/狀態等資訊'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_track_label` (
        `label_id`   INT          AUTO_INCREMENT PRIMARY KEY COMMENT '標籤主鍵',
        `label_name` VARCHAR(50)  NOT NULL                   COMMENT '標籤名稱',
        `sort_order` INT          NOT NULL DEFAULT 0         COMMENT '排序，由小到大',
        `is_active`  TINYINT      NOT NULL DEFAULT 1         COMMENT '啟用狀態 1=啟用 0=停用',
        `created_by` INT          NULL                       COMMENT '建立人 FK→user.id',
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間'
    ) COMMENT='業務追蹤標籤字典：供追蹤項目多選標籤使用，可在基本設定中管理'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_track_label_map` (
        `map_id`   INT NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '對應主鍵',
        `track_id` INT NOT NULL COMMENT 'FK→sales_track.track_id',
        `label_id` INT NOT NULL COMMENT 'FK→sales_track_label.label_id',
        UNIQUE KEY `uk_track_label` (`track_id`, `label_id`),
        INDEX `idx_track` (`track_id`),
        INDEX `idx_label` (`label_id`)
    ) COMMENT='業務追蹤標籤對應：記錄每筆追蹤項目所選用的標籤'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_track_note` (
        `note_id`          INT          AUTO_INCREMENT PRIMARY KEY COMMENT '備註主鍵',
        `track_id`         INT          NOT NULL                   COMMENT 'FK→sales_track.track_id',
        `note_text`        TEXT         NOT NULL                   COMMENT '備註內容',
        `created_by_id`    INT          NULL                       COMMENT '建立人 FK→user.id',
        `created_by_name`  VARCHAR(100) NULL                       COMMENT '建立人名稱（快照）',
        `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
        `updated_by_id`    INT          NULL                       COMMENT '修改人 FK→user.id',
        `updated_by_name`  VARCHAR(100) NULL                       COMMENT '修改人名稱（快照）',
        `updated_at`       DATETIME     NULL                       COMMENT '修改時間',
        INDEX `idx_track` (`track_id`)
    ) COMMENT='業務追蹤進度說明：記錄每筆追蹤項目的進度更新，每條備註顯示建立人與時間'");
} catch (Exception $e) { /* 欄位已存在時略過 */ }

// ── 欄位升級（舊表補欄）──────────────────────────────────────────────────────
try { $pdo->exec("ALTER TABLE `sales_track` ADD COLUMN `track_code` VARCHAR(15) NULL COMMENT '追蹤編號 SCyyyymmddNNN', ADD UNIQUE KEY `uk_track_code`(`track_code`)"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `sales_track_label` ADD COLUMN `color` VARCHAR(7) NOT NULL DEFAULT '#1ABB9C' COMMENT '標籤顏色(HEX)'"); } catch(Exception $e){}

// ── 圖片儲存表 ───────────────────────────────────────────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_track_images` (
    `img_id`       INT          AUTO_INCREMENT PRIMARY KEY COMMENT '圖片主鍵',
    `target_type`  ENUM('track','note') NOT NULL           COMMENT 'track=追蹤說明 note=進度說明',
    `target_id`    INT          NOT NULL                   COMMENT 'FK→sales_track.track_id 或 sales_track_note.note_id',
    `file_name`    VARCHAR(255) NOT NULL                   COMMENT 'NAS 實際檔名',
    `original_name`VARCHAR(255) NULL                       COMMENT '上傳原始檔名',
    `file_size`    INT          NULL                       COMMENT '檔案大小(bytes)',
    `sort_order`   INT          NOT NULL DEFAULT 0         COMMENT '排序',
    `created_by`   INT          NULL                       COMMENT 'FK→user.id',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
    INDEX `idx_target` (`target_type`, `target_id`)
) COMMENT='業務追蹤圖片：追蹤說明與進度說明的附圖，存NAS僅記錄Metadata'"); } catch(Exception $e){}

// ── 系統設定預設值 ────────────────────────────────────────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_by_id` INT NULL,
    `updated_by`    VARCHAR(100) NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) COMMENT='系統全域設定'"); } catch(Exception $e){}
try { $pdo->exec("INSERT IGNORE INTO system_settings (setting_key,setting_value) VALUES ('sales_nas_dir','Z:/BOM/ERP/業務/'),('sales_url_dir','/nas/ERP/業務/')"); } catch(Exception $e){}

// ── 通知表 ─────────────────────────────────────────────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_track_notifications` (
    `notif_id`          INT          AUTO_INCREMENT PRIMARY KEY,
    `track_id`          INT          NOT NULL,
    `track_code`        VARCHAR(15)  NULL,
    `action_type`       ENUM('create','update','complete','delete') NOT NULL,
    `message`           VARCHAR(255) NOT NULL,
    `triggered_by`      INT          NULL,
    `triggered_by_name` VARCHAR(100) NULL,
    `target_user_id`    INT          NOT NULL,
    `is_read`           TINYINT      NOT NULL DEFAULT 0,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_target`  (`target_user_id`, `is_read`),
    INDEX `idx_track`   (`track_id`)
) COMMENT='業務追蹤通知'"); } catch(Exception $e){}

$action = $_POST['action'] ?? '';

// ── 工具函式 ──────────────────────────────────────────────────────────────
function getUserName($pdo, $uid) {
    if (!$uid) return '';
    try {
        $s = $pdo->prepare("SELECT user_cname FROM user WHERE id=? LIMIT 1");
        $s->execute([$uid]);
        return $s->fetchColumn() ?: '';
    } catch (Exception $e) { return ''; }
}

function logAudit($pdo, $action, $tid, $tname, $changes, $uid, $op) {
    try {
        $pdo->prepare("INSERT INTO audit_log (action_type,target_type,target_id,target_name,changes,user_id,operator)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$action, 'sales_track', (string)$tid, $tname,
                       is_array($changes) ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                       $uid, $op]);
    } catch (Exception $e) {}
}

// 取得需要被通知的用戶 ID 列表
// $includeCDRU=false → 只取 CR 與 A（用於完工通知）
function getNotifyUsers($pdo, $excludeUserId = 0, $includeCDRU = true) {
    $users = [];
    try {
        // 同一 user_id 可能有多列權限紀錄，需全部合併再判斷
        $mergeRows = function(array $rows): array {
            $acc = [];
            foreach ($rows as $r) {
                $uid = intval($r['user_id']);
                $acc[$uid] = ($acc[$uid] ?? '') . $r['permission'];
            }
            return $acc;
        };

        $permRecords = [];

        $pg = $pdo->query("SELECT page_id, group_id FROM system_module_pages WHERE page_url LIKE '%Sales_Track%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($pg) {
            // 頁面層級（最高優先）
            $s = $pdo->prepare("SELECT user_id, permission FROM user_module_permissions WHERE scope='page' AND module_code=?");
            $s->execute([(string)$pg['page_id']]);
            $permRecords = $mergeRows($s->fetchAll(PDO::FETCH_ASSOC));

            // 群組層級（補足頁面層級未覆蓋的使用者）
            if (!empty($pg['group_id'])) {
                $gm = $pdo->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
                $gm->execute([$pg['group_id']]);
                $gc = $gm->fetchColumn();
                if ($gc) {
                    $s2 = $pdo->prepare("SELECT user_id, permission FROM user_module_permissions WHERE scope='group' AND module_code=?");
                    $s2->execute([$gc]);
                    foreach ($mergeRows($s2->fetchAll(PDO::FETCH_ASSOC)) as $uid => $perm) {
                        if (!isset($permRecords[$uid])) $permRecords[$uid] = $perm;
                    }
                }
            }
        }

        // 備用：直接以 module_code='sales_track' 查詢
        $s3 = $pdo->query("SELECT user_id, permission FROM user_module_permissions WHERE module_code='sales_track'");
        foreach ($mergeRows($s3->fetchAll(PDO::FETCH_ASSOC)) as $uid => $perm) {
            if (!isset($permRecords[$uid])) $permRecords[$uid] = $perm;
        }

        foreach ($permRecords as $uid => $perm) {
            if ($uid == $excludeUserId) continue;
            $chars = array_unique(str_split($perm));
            $isA  = in_array('A', $chars);
            $hasC = in_array('C', $chars);
            $hasD = in_array('D', $chars);
            $hasR = in_array('R', $chars);
            $hasU = in_array('U', $chars);
            if ($isA)                                               { $users[] = $uid; continue; }
            if ($hasC && $hasR && !$hasU && !$hasD)                { $users[] = $uid; continue; } // CR
            if ($includeCDRU && $hasC && $hasD && $hasR && $hasU)    $users[] = $uid;             // CDRU
        }
    } catch (Exception $e) {}
    return array_values(array_unique($users));
}

function createNotifications($pdo, $trackId, $trackCode, $actionType, $message, $triggeredBy, $triggeredByName, array $targetUserIds) {
    if (empty($targetUserIds)) return;
    try {
        $ins = $pdo->prepare("INSERT INTO sales_track_notifications (track_id,track_code,action_type,message,triggered_by,triggered_by_name,target_user_id) VALUES (?,?,?,?,?,?,?)");
        foreach (array_unique($targetUserIds) as $uid) $ins->execute([$trackId, $trackCode, $actionType, $message, $triggeredBy, $triggeredByName, intval($uid)]);
    } catch (Exception $e) {}
}

function generateTrackCode($pdo) {
    $date = date('Ymd');
    $prefix = 'SC' . $date;
    try {
        $s = $pdo->prepare("SELECT MAX(CAST(RIGHT(track_code,3) AS UNSIGNED)) FROM sales_track WHERE track_code LIKE ?");
        $s->execute([$prefix . '%']);
        $max = intval($s->fetchColumn());
        return $prefix . str_pad($max + 1, 3, '0', STR_PAD_LEFT);
    } catch(Exception $e) { return $prefix . '001'; }
}

function getSalesDeptIds($pdo) {
    try {
        $row = $pdo->query("SELECT param_value FROM system_parameters WHERE param_group='SALES_SETTING' AND param_key='sales_unit_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $salesDeptId = 0;
        if ($row && !empty($row['param_value'])) {
            $cfg = json_decode($row['param_value'], true);
            $salesDeptId = isset($cfg['id']) ? intval($cfg['id']) : 0;
        }
        if (!$salesDeptId) return [];
        $all = $pdo->query("SELECT id, parent_id FROM department")->fetchAll(PDO::FETCH_ASSOC);
        $ids = [$salesDeptId];
        $todo = [$salesDeptId];
        while (!empty($todo)) {
            $cur = array_shift($todo);
            foreach ($all as $d) {
                if ($d['parent_id'] == $cur) { $ids[] = $d['id']; $todo[] = $d['id']; }
            }
        }
        return array_unique($ids);
    } catch (Exception $e) { return []; }
}

try {
    switch ($action) {

        // ── 清單 ────────────────────────────────────────────────────────
        case 'get_list': {
            $sql = "SELECT
                        st.track_id, st.track_code, st.track_date, st.status, st.boss_reviewed,
                        st.source_dept_id, st.source_user_id, st.assignee_id,
                        st.customer_id, st.d_setting_id, st.non_std_part, st.description,
                        st.completed_at, st.completed_by,
                        st.boss_reviewed_at, st.boss_reviewed_by,
                        st.created_by, st.modified_by, st.created_at, st.updated_at,
                        sd.name   AS source_dept_name,
                        su.user_cname AS source_user_name,
                        au.user_cname AS assignee_name,
                        ap.name       AS assignee_position,
                        cl.customer         AS customer_name,
                        cl.customer_address AS customer_address,
                        ds.D_Setting_Id AS part_no,
                        ds.Spec_No      AS part_spec,
                        cb.user_cname AS created_by_name,
                        mb.user_cname AS modified_by_name,
                        cpb.user_cname AS completed_by_name,
                        brb.user_cname AS boss_reviewed_by_name
                    FROM sales_track st
                    LEFT JOIN department  sd  ON sd.id       = st.source_dept_id
                    LEFT JOIN user        su  ON su.id       = st.source_user_id
                    LEFT JOIN user        au  ON au.id       = st.assignee_id
                    LEFT JOIN user_department_position_map apm ON apm.user_id = st.assignee_id AND apm.is_main=1
                    LEFT JOIN position    ap  ON ap.id       = apm.position_id
                    LEFT JOIN customer_list cl ON cl.customer_id = st.customer_id
                    LEFT JOIN d_setting   ds  ON ds.d_id     = st.d_setting_id
                    LEFT JOIN user        cb  ON cb.id       = st.created_by
                    LEFT JOIN user        mb  ON mb.id       = st.modified_by
                    LEFT JOIN user        cpb ON cpb.id      = st.completed_by
                    LEFT JOIN user        brb ON brb.id      = st.boss_reviewed_by
                    ORDER BY st.track_date DESC, st.track_id DESC";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            // 附加標籤
            $labelMap = [];
            $lrows = $pdo->query("SELECT m.track_id, l.label_id, l.label_name, l.color
                                  FROM sales_track_label_map m
                                  JOIN sales_track_label l ON l.label_id=m.label_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lrows as $lr) $labelMap[$lr['track_id']][] = $lr;

            // 附加最新一筆備註（含修改者資訊）
            $noteMap = [];
            $nrows = $pdo->query("SELECT n.track_id, n.note_id, n.note_text, n.created_by_name, n.created_at, n.updated_by_name, n.updated_at
                                  FROM sales_track_note n
                                  INNER JOIN (
                                      SELECT track_id, MAX(note_id) AS max_id FROM sales_track_note GROUP BY track_id
                                  ) t ON t.track_id=n.track_id AND t.max_id=n.note_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($nrows as $nr) $noteMap[$nr['track_id']] = $nr;

            // 附加圖片（說明圖 + 最新備註圖）
            $trackImgMap = [];
            $noteImgMap  = [];
            if (!empty($rows)) {
                try {
                    $urlRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='sales_url_dir' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    $url_dir = $urlRow ? rtrim($urlRow['setting_value'],'/') . '/' : '/nas/ERP/業務/';
                } catch(Exception $e) { $url_dir = '/nas/ERP/業務/'; }

                $trackIds = implode(',', array_map('intval', array_column($rows, 'track_id')));
                $tiRows = $pdo->query("SELECT target_id, img_id, file_name, original_name FROM sales_track_images WHERE target_type='track' AND target_id IN ($trackIds) ORDER BY target_id, sort_order, img_id")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($tiRows as $ti) {
                    $trackImgMap[intval($ti['target_id'])][] = array_merge($ti, ['url' => $url_dir . $ti['file_name']]);
                }

                $noteIds = array_filter(array_map(fn($n) => $n['note_id'] ?? null, array_values($noteMap)));
                if (!empty($noteIds)) {
                    $noteInStr = implode(',', array_map('intval', $noteIds));
                    $niRows = $pdo->query("SELECT target_id, img_id, file_name, original_name FROM sales_track_images WHERE target_type='note' AND target_id IN ($noteInStr) ORDER BY target_id, sort_order, img_id")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($niRows as $ni) {
                        $noteImgMap[intval($ni['target_id'])][] = array_merge($ni, ['url' => $url_dir . $ni['file_name']]);
                    }
                }
            }

            foreach ($rows as &$r) {
                $r['labels']       = $labelMap[$r['track_id']] ?? [];
                $r['latest_note']  = $noteMap[$r['track_id']] ?? null;
                $r['track_images'] = $trackImgMap[$r['track_id']] ?? [];
                if ($r['latest_note']) {
                    $r['latest_note']['images'] = $noteImgMap[$r['latest_note']['note_id']] ?? [];
                }
            }
            unset($r);

            echo json_encode(['success' => true, 'data' => $rows]);
            break;
        }

        // ── 新增 / 修改 ────────────────────────────────────────────────
        case 'save_track': {
            $track_id       = intval($_POST['track_id'] ?? 0);
            $track_date     = $_POST['track_date'] ?? '';
            $source_dept_id = intval($_POST['source_dept_id'] ?? 0);
            $source_user_id = intval($_POST['source_user_id'] ?? 0);
            $assignee_id    = intval($_POST['assignee_id'] ?? 0);
            $customer_id    = trim($_POST['customer_id'] ?? '');
            $d_setting_id   = intval($_POST['d_setting_id'] ?? 0) ?: null;
            $non_std_part   = trim($_POST['non_std_part'] ?? '') ?: null;
            $description    = trim($_POST['description'] ?? '') ?: null;
            $label_ids      = json_decode($_POST['label_ids'] ?? '[]', true) ?: [];

            if (!$track_date || !$source_dept_id || !$source_user_id || !$assignee_id || !$customer_id) {
                throw new Exception('請填寫必要欄位');
            }
            if (!$d_setting_id && !$non_std_part) {
                throw new Exception('料號與非超正料號至少擇一填寫');
            }

            $operator = getUserName($pdo, $user_id);
            $pdo->beginTransaction();

            if ($track_id === 0) {
                // 新增
                $track_code = generateTrackCode($pdo);
                $pdo->prepare("INSERT INTO sales_track
                    (track_code,track_date,source_dept_id,source_user_id,assignee_id,customer_id,d_setting_id,non_std_part,description,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$track_code, $track_date, $source_dept_id, $source_user_id, $assignee_id,
                               $customer_id, $d_setting_id, $non_std_part, $description, $user_id]);
                $track_id = $pdo->lastInsertId();
                logAudit($pdo, 'insert', $track_id, "$track_code", null, $user_id, $operator);
            } else {
                // 修改
                $old = $pdo->prepare("SELECT * FROM sales_track WHERE track_id=? LIMIT 1");
                $old->execute([$track_id]);
                $oldRow = $old->fetch(PDO::FETCH_ASSOC);
                if (!$oldRow) throw new Exception('找不到此追蹤項目');

                $pdo->prepare("UPDATE sales_track SET
                    track_date=?, source_dept_id=?, source_user_id=?, assignee_id=?,
                    customer_id=?, d_setting_id=?, non_std_part=?, description=?,
                    modified_by=?
                    WHERE track_id=?")
                    ->execute([$track_date, $source_dept_id, $source_user_id, $assignee_id,
                               $customer_id, $d_setting_id, $non_std_part, $description,
                               $user_id, $track_id]);

                $changes = [];
                $fields  = ['track_date'=>'日期','source_dept_id'=>'來源部門','source_user_id'=>'來源人員',
                            'assignee_id'=>'負責業務','customer_id'=>'客戶','d_setting_id'=>'料號',
                            'non_std_part'=>'非超正料號','description'=>'說明'];
                foreach ($fields as $k => $label) {
                    if ((string)($oldRow[$k] ?? '') !== (string)($_POST[$k] ?? '')) {
                        $changes[] = ['field' => $label, 'old' => $oldRow[$k], 'new' => $_POST[$k] ?? ''];
                    }
                }
                $tc = $oldRow['track_code'] ?? "SC#$track_id";
                logAudit($pdo, 'update', $track_id, $tc, $changes, $user_id, $operator);
            }

            // 同步標籤
            $pdo->prepare("DELETE FROM sales_track_label_map WHERE track_id=?")->execute([$track_id]);
            if (!empty($label_ids)) {
                $ins = $pdo->prepare("INSERT IGNORE INTO sales_track_label_map (track_id, label_id) VALUES (?,?)");
                foreach ($label_ids as $lid) $ins->execute([$track_id, intval($lid)]);
            }

            $pdo->commit();

            // 通知（新建/修改 → CDRU+CR+A，排除自己）
            $isNew = (intval($_POST['track_id'] ?? 0) === 0);
            $notifCode = $isNew ? $track_code : ($oldRow['track_code'] ?? "SC#$track_id");
            $actionLabel = $isNew ? '新增' : '修改';
            $notifyUsers = getNotifyUsers($pdo, $user_id, true);
            createNotifications($pdo, $track_id, $notifCode, ($isNew ? 'create' : 'update'), "$operator {$actionLabel}了追蹤項目 $notifCode", $user_id, $operator, $notifyUsers);

            echo json_encode(['success' => true, 'track_id' => $track_id]);
            break;
        }

        // ── 刪除 ────────────────────────────────────────────────────────
        case 'delete_track': {
            $track_id = intval($_POST['track_id'] ?? 0);
            if (!$track_id) throw new Exception('缺少 track_id');
            $operator = getUserName($pdo, $user_id);
            // 取得建立者與修改者 → 通知用
            $di = $pdo->prepare("SELECT track_code, created_by, modified_by FROM sales_track WHERE track_id=? LIMIT 1");
            $di->execute([$track_id]);
            $drow = $di->fetch(PDO::FETCH_ASSOC);
            $dtc  = $drow['track_code'] ?? "SC#$track_id";
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM sales_track_label_map WHERE track_id=?")->execute([$track_id]);
            $pdo->prepare("DELETE FROM sales_track_note WHERE track_id=?")->execute([$track_id]);
            $pdo->prepare("DELETE FROM sales_track WHERE track_id=?")->execute([$track_id]);
            logAudit($pdo, 'delete', $track_id, $dtc, null, $user_id, $operator);
            $pdo->commit();
            // 通知建立者與最後修改者（排除自己與重複）
            $toNotify = [];
            if (!empty($drow['created_by'])  && $drow['created_by']  != $user_id) $toNotify[] = intval($drow['created_by']);
            if (!empty($drow['modified_by']) && $drow['modified_by'] != $user_id && $drow['modified_by'] != $drow['created_by']) $toNotify[] = intval($drow['modified_by']);
            createNotifications($pdo, $track_id, $dtc, 'delete', "$operator 刪除了追蹤項目 $dtc", $user_id, $operator, $toNotify);
            echo json_encode(['success' => true]);
            break;
        }

        // ── 完工 ────────────────────────────────────────────────────────
        case 'complete_track': {
            $track_id = intval($_POST['track_id'] ?? 0);
            if (!$track_id) throw new Exception('缺少 track_id');
            $operator = getUserName($pdo, $user_id);
            $ctc = $pdo->prepare("SELECT track_code FROM sales_track WHERE track_id=? LIMIT 1");
            $ctc->execute([$track_id]);
            $completeCode = $ctc->fetchColumn() ?: "SC#$track_id";
            $pdo->prepare("UPDATE sales_track SET status='completed', completed_at=NOW(), completed_by=? WHERE track_id=?")
                ->execute([$user_id, $track_id]);
            logAudit($pdo, 'update', $track_id, $completeCode, [['field'=>'狀態','old'=>'進行中','new'=>'完工']], $user_id, $operator);
            // 完工只通知 CR 和 A（不含 CDRU）
            $notifyUsers = getNotifyUsers($pdo, $user_id, false);
            createNotifications($pdo, $track_id, $completeCode, 'complete', "$operator 標記追蹤項目 $completeCode 完工", $user_id, $operator, $notifyUsers);
            echo json_encode(['success' => true]);
            break;
        }

        // ── BOSS已閱 ────────────────────────────────────────────────────
        case 'boss_review': {
            $track_id = intval($_POST['track_id'] ?? 0);
            if (!$track_id) throw new Exception('缺少 track_id');
            $operator = getUserName($pdo, $user_id);
            $brq = $pdo->prepare("SELECT track_code FROM sales_track WHERE track_id=? LIMIT 1");
            $brq->execute([$track_id]);
            $brCode = $brq->fetchColumn() ?: "SC#$track_id";
            $pdo->prepare("UPDATE sales_track SET boss_reviewed=1, boss_reviewed_at=NOW(), boss_reviewed_by=? WHERE track_id=?")
                ->execute([$user_id, $track_id]);
            logAudit($pdo, 'update', $track_id, $brCode, [['field'=>'BOSS已閱','old'=>'0','new'=>'1']], $user_id, $operator);
            echo json_encode(['success' => true]);
            break;
        }

        // ── 進度說明（備註）────────────────────────────────────────────
        case 'get_notes': {
            $track_id = intval($_POST['track_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT note_id, note_text, created_by_name, created_at, updated_by_name, updated_at
                                   FROM sales_track_note WHERE track_id=? ORDER BY note_id DESC");
            $stmt->execute([$track_id]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // 附加圖片 URL
            try {
                $urlRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='sales_url_dir' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $url_dir = $urlRow ? rtrim($urlRow['setting_value'],'/') . '/' : '/nas/ERP/業務/';
            } catch(Exception $e) { $url_dir = '/nas/ERP/業務/'; }
            $imgQ = $pdo->prepare("SELECT img_id, file_name, original_name FROM sales_track_images WHERE target_type='note' AND target_id=? ORDER BY sort_order, img_id");
            foreach ($notes as &$n) {
                $imgQ->execute([$n['note_id']]);
                $n['images'] = array_map(fn($r) => array_merge($r, ['url'=>$url_dir.$r['file_name']]), $imgQ->fetchAll(PDO::FETCH_ASSOC));
            }
            echo json_encode(['success' => true, 'data' => $notes]);
            break;
        }

        case 'save_note': {
            $note_id   = intval($_POST['note_id'] ?? 0);
            $track_id  = intval($_POST['track_id'] ?? 0);
            $note_text = trim($_POST['note_text'] ?? '');
            if (!$track_id || !$note_text) throw new Exception('缺少必要欄位');
            $uname = getUserName($pdo, $user_id);
            $operator = $uname;
            $ntcq = $pdo->prepare("SELECT track_code FROM sales_track WHERE track_id=? LIMIT 1");
            $ntcq->execute([$track_id]);
            $noteTC = $ntcq->fetchColumn() ?: "SC#$track_id";

            if ($note_id === 0) {
                $pdo->prepare("INSERT INTO sales_track_note (track_id, note_text, created_by_id, created_by_name)
                               VALUES (?,?,?,?)")
                    ->execute([$track_id, $note_text, $user_id, $uname]);
                $note_id = $pdo->lastInsertId();
                logAudit($pdo, 'insert', $track_id, "$noteTC 備註#$note_id", null, $user_id, $operator);
            } else {
                $pdo->prepare("UPDATE sales_track_note SET note_text=?, updated_by_id=?, updated_by_name=?, updated_at=NOW()
                               WHERE note_id=? AND track_id=?")
                    ->execute([$note_text, $user_id, $uname, $note_id, $track_id]);
                logAudit($pdo, 'update', $track_id, "$noteTC 備註#$note_id", null, $user_id, $operator);
            }
            echo json_encode(['success' => true, 'note_id' => $note_id]);
            break;
        }

        case 'delete_note': {
            $note_id  = intval($_POST['note_id'] ?? 0);
            $track_id = intval($_POST['track_id'] ?? 0);
            if (!$note_id || !$track_id) throw new Exception('缺少必要欄位');
            $operator = getUserName($pdo, $user_id);
            $pdo->prepare("DELETE FROM sales_track_note WHERE note_id=? AND track_id=?")->execute([$note_id, $track_id]);
            logAudit($pdo, 'delete', $track_id, "追蹤#$track_id 備註#$note_id", null, $user_id, $operator);
            echo json_encode(['success' => true]);
            break;
        }

        // ── 部門 / 人員 ─────────────────────────────────────────────────
        case 'get_all_depts': {
            $rows = $pdo->query("SELECT id, name, parent_id FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;
        }

        case 'get_dept_users': {
            $dept_id = intval($_POST['dept_id'] ?? 0);
            if (!$dept_id) { echo json_encode(['success' => true, 'data' => []]); break; }
            $sql = "SELECT u.id, u.user_cname, p.name AS position_name
                    FROM user u
                    JOIN user_department_position_map m ON m.user_id=u.id AND m.department_id=?
                    LEFT JOIN position p ON p.id=m.position_id
                    WHERE u.state=1
                    ORDER BY p.sort_order, u.user_cname";
            $s = $pdo->prepare($sql);
            $s->execute([$dept_id]);
            echo json_encode(['success' => true, 'data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'get_sales_users': {
            $ids = getSalesDeptIds($pdo);
            if (empty($ids)) { echo json_encode(['success' => true, 'data' => []]); break; }
            $in = implode(',', array_map('intval', $ids));
            $sql = "SELECT u.id, u.user_cname, p.name AS position_name
                    FROM user u
                    JOIN user_department_position_map m ON u.id = m.user_id
                    LEFT JOIN position p ON m.position_id = p.id
                    WHERE u.state = 1 AND m.department_id IN ($in)
                    ORDER BY p.sort_order, u.user_cname";
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ── 客戶搜尋 ─────────────────────────────────────────────────────
        case 'get_customers': {
            $kw = trim($_POST['keyword'] ?? '');
            if ($kw === '') { echo json_encode(['success' => true, 'data' => []]); break; }
            $s = $pdo->prepare("SELECT customer_id, customer, customer_address FROM customer_list WHERE (customer LIKE ? OR customer_id LIKE ?) AND is_inactive=0 LIMIT 30");
            $s->execute(["%$kw%", "%$kw%"]);
            echo json_encode(['success' => true, 'data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ── 料號搜尋 ─────────────────────────────────────────────────────
        case 'get_parts': {
            $kw = trim($_POST['keyword'] ?? '');
            if (strlen($kw) < 2) { echo json_encode(['success' => true, 'data' => []]); break; }
            $s = $pdo->prepare("SELECT d.d_id, d.D_Setting_Id, d.Spec_No, cl.customer AS customer_name
                                FROM d_setting d
                                LEFT JOIN customer_list cl ON cl.customer_id = d.Customer_Id
                                WHERE d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ? LIMIT 30");
            $s->execute(["%$kw%", "%$kw%"]);
            echo json_encode(['success' => true, 'data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ── 標籤管理 ─────────────────────────────────────────────────────
        case 'get_labels': {
            $rows = $pdo->query("SELECT label_id, label_name, color, sort_order FROM sales_track_label WHERE is_active=1 ORDER BY sort_order, label_id")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;
        }

        case 'manage_labels': {
            $op = $_POST['op'] ?? '';
            if ($op === 'list') {
                $rows = $pdo->query("SELECT label_id, label_name, color, sort_order, is_active FROM sales_track_label ORDER BY sort_order, label_id")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $rows]);
            } elseif ($op === 'save') {
                $lid   = intval($_POST['label_id'] ?? 0);
                $name  = trim($_POST['label_name'] ?? '');
                $color = trim($_POST['color'] ?? '#1ABB9C');
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#1ABB9C';
                if (!$name) throw new Exception('標籤名稱不可為空');
                if ($lid) {
                    $pdo->prepare("UPDATE sales_track_label SET label_name=?, color=? WHERE label_id=?")->execute([$name, $color, $lid]);
                } else {
                    $pdo->prepare("INSERT INTO sales_track_label (label_name, color, created_by) VALUES (?,?,?)")->execute([$name, $color, $user_id]);
                    $lid = $pdo->lastInsertId();
                }
                echo json_encode(['success' => true, 'label_id' => $lid]);
            } elseif ($op === 'disable') {
                $lid = intval($_POST['label_id'] ?? 0);
                $pdo->prepare("UPDATE sales_track_label SET is_active=0 WHERE label_id=?")->execute([$lid]);
                echo json_encode(['success' => true]);
            } elseif ($op === 'enable') {
                $lid = intval($_POST['label_id'] ?? 0);
                $pdo->prepare("UPDATE sales_track_label SET is_active=1 WHERE label_id=?")->execute([$lid]);
                echo json_encode(['success' => true]);
            } elseif ($op === 'delete') {
                $lid = intval($_POST['label_id'] ?? 0);
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM sales_track_label_map WHERE label_id=?")->execute([$lid]);
                $pdo->prepare("DELETE FROM sales_track_label WHERE label_id=?")->execute([$lid]);
                $pdo->commit();
                echo json_encode(['success' => true]);
            } elseif ($op === 'sort') {
                $ids = json_decode($_POST['ids'] ?? '[]', true);
                $upd = $pdo->prepare("UPDATE sales_track_label SET sort_order=? WHERE label_id=?");
                foreach ($ids as $i => $id) $upd->execute([$i, intval($id)]);
                echo json_encode(['success' => true]);
            } elseif ($op === 'save_color') {
                $lid   = intval($_POST['label_id'] ?? 0);
                $color = trim($_POST['color'] ?? '');
                if (!$lid) throw new Exception('缺少 label_id');
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) throw new Exception('無效的顏色格式');
                $pdo->prepare("UPDATE sales_track_label SET color=? WHERE label_id=?")->execute([$color, $lid]);
                echo json_encode(['success' => true]);
            } else {
                throw new Exception('未知操作');
            }
            break;
        }

        // ── 通知讀取 ─────────────────────────────────────────────────────────
        case 'get_notifications': {
            $s = $pdo->prepare("SELECT notif_id, track_id, track_code, action_type, message, triggered_by_name, created_at FROM sales_track_notifications WHERE target_user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 30");
            $s->execute([$user_id]);
            echo json_encode(['success' => true, 'notifications' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'mark_notification_read': {
            $nid = intval($_POST['notif_id'] ?? 0);
            if ($nid) $pdo->prepare("UPDATE sales_track_notifications SET is_read=1 WHERE notif_id=? AND target_user_id=?")->execute([$nid, $user_id]);
            else      $pdo->prepare("UPDATE sales_track_notifications SET is_read=1 WHERE target_user_id=?")->execute([$user_id]);
            echo json_encode(['success' => true]);
            break;
        }

        // ── 業務單位資訊 ─────────────────────────────────────────────────
        case 'get_sales_unit_info': {
            $row = $pdo->query("SELECT param_value FROM system_parameters WHERE param_group='SALES_SETTING' AND param_key='sales_unit_id' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $deptId = 0; $deptName = '';
            if ($row && !empty($row['param_value'])) {
                $cfg = json_decode($row['param_value'], true);
                $deptId = isset($cfg['id']) ? intval($cfg['id']) : 0;
            }
            if ($deptId) {
                $dr = $pdo->prepare("SELECT name FROM department WHERE id=? LIMIT 1");
                $dr->execute([$deptId]);
                $deptName = $dr->fetchColumn() ?: '';
            }
            echo json_encode(['success' => true, 'dept_id' => $deptId, 'dept_name' => $deptName]);
            break;
        }

        // ── 歷史紀錄 ─────────────────────────────────────────────────────
        case 'get_history': {
            $track_id         = intval($_POST['track_id']      ?? 0);
            $date_from        = trim($_POST['date_from']        ?? '');
            $date_to          = trim($_POST['date_to']          ?? '');
            $action_types_raw = trim($_POST['action_types']     ?? '');

            $conds  = ["al.target_type='sales_track'"];
            $params = [];
            if ($track_id)  { $conds[] = "al.target_id=?";         $params[] = $track_id; }
            if ($date_from) { $conds[] = "DATE(al.created_at)>=?"; $params[] = $date_from; }
            if ($date_to)   { $conds[] = "DATE(al.created_at)<=?"; $params[] = $date_to; }
            if ($action_types_raw) {
                $valid = ['insert', 'update', 'delete'];
                $types = array_values(array_filter(explode(',', $action_types_raw), fn($t) => in_array(trim($t), $valid)));
                if ($types) {
                    $conds[] = "al.action_type IN (" . implode(',', array_fill(0, count($types), '?')) . ")";
                    foreach ($types as $t) $params[] = trim($t);
                }
            }
            $where = 'WHERE ' . implode(' AND ', $conds);
            $sql = "SELECT al.id, al.action_type, al.target_id, al.target_name, al.changes, al.operator, al.created_at,
                           COALESCE(ds.D_Setting_Id, st.non_std_part) AS part_display
                    FROM audit_log al
                    LEFT JOIN sales_track st ON st.track_id = CAST(al.target_id AS UNSIGNED)
                    LEFT JOIN d_setting ds ON ds.d_id = st.d_setting_id
                    $where ORDER BY al.id DESC LIMIT 500";
            $s = $pdo->prepare($sql);
            $s->execute($params);
            echo json_encode(['success' => true, 'data' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ── 圖片取得 ─────────────────────────────────────────────────────────
        case 'get_track_images': {
            $target_type = in_array($_POST['target_type']??'', ['track','note']) ? $_POST['target_type'] : null;
            $target_id   = intval($_POST['target_id'] ?? 0);
            if (!$target_type || !$target_id) { echo json_encode(['success'=>true,'data'=>[]]); break; }
            try {
                $urlRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='sales_url_dir' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $url_dir = $urlRow ? rtrim($urlRow['setting_value'],'/') . '/' : '/nas/ERP/業務/';
            } catch(Exception $e) { $url_dir = '/nas/ERP/業務/'; }
            $s = $pdo->prepare("SELECT img_id, file_name, original_name FROM sales_track_images WHERE target_type=? AND target_id=? ORDER BY sort_order, img_id");
            $s->execute([$target_type, $target_id]);
            $imgs = array_map(fn($r) => array_merge($r, ['url'=>$url_dir.$r['file_name']]), $s->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['success'=>true, 'data'=>$imgs]);
            break;
        }

        // ── 圖片上傳 ─────────────────────────────────────────────────────────
        case 'upload_track_image': {
            $target_type = in_array($_POST['target_type']??'', ['track','note']) ? $_POST['target_type'] : null;
            $target_id   = intval($_POST['target_id'] ?? 0);
            if (!$target_type || !$target_id) throw new Exception('參數不完整');
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) throw new Exception('上傳失敗');
            $allowed_ext = ['jpg','jpeg','png','gif','webp','bmp'];
            $orig = basename($_FILES['image']['name']);
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext)) throw new Exception('不支援的圖片格式');
            try {
                $nasRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='sales_nas_dir' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $nas_dir = $nasRow ? $nasRow['setting_value'] : 'Z:/BOM/ERP/業務/';
                $urlRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='sales_url_dir' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $url_dir = $urlRow ? rtrim($urlRow['setting_value'],'/') . '/' : '/nas/ERP/業務/';
            } catch(Exception $e) { $nas_dir='Z:/BOM/ERP/業務/'; $url_dir='/nas/ERP/業務/'; }
            if (!is_dir($nas_dir)) { if (!mkdir($nas_dir, 0777, true)) throw new Exception('無法建立目錄'); }
            $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $nas_dir . $fname)) throw new Exception('檔案移動失敗');
            $pdo->prepare("INSERT INTO sales_track_images (target_type,target_id,file_name,original_name,file_size,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$target_type, $target_id, $fname, $orig, intval($_FILES['image']['size']), $user_id]);
            echo json_encode(['success'=>true, 'img_id'=>intval($pdo->lastInsertId()), 'file_name'=>$fname, 'url'=>$url_dir.$fname, 'original_name'=>$orig]);
            break;
        }

        // ── 圖片刪除 ─────────────────────────────────────────────────────────
        case 'delete_track_image': {
            $img_id = intval($_POST['img_id'] ?? 0);
            if (!$img_id) throw new Exception('缺少 img_id');
            $s = $pdo->prepare("SELECT file_name FROM sales_track_images WHERE img_id=?");
            $s->execute([$img_id]);
            $fn = $s->fetchColumn();
            if ($fn) {
                try {
                    $nasRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='sales_nas_dir' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    $nas_dir = $nasRow ? $nasRow['setting_value'] : 'Z:/BOM/ERP/業務/';
                } catch(Exception $e) { $nas_dir='Z:/BOM/ERP/業務/'; }
                $fp = $nas_dir . $fn;
                if (file_exists($fp)) @unlink($fp);
            }
            $pdo->prepare("DELETE FROM sales_track_images WHERE img_id=?")->execute([$img_id]);
            echo json_encode(['success'=>true]);
            break;
        }

        // ── 基本設定讀取 ──────────────────────────────────────────────────────
        case 'get_settings': {
            try {
                $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sales_nas_dir','sales_url_dir','boss_review_user_id','boss_review_user_name','boss_review_dept_id')")->fetchAll(PDO::FETCH_KEY_PAIR);
                echo json_encode(['success'=>true, 'data'=>$rows]);
            } catch(Exception $e) { echo json_encode(['success'=>true,'data'=>[]]); }
            break;
        }

        // ── 基本設定儲存（僅 A 可執行） ───────────────────────────────────────
        case 'save_settings': {
            $key = trim($_POST['setting_key'] ?? '');
            $val = trim($_POST['setting_value'] ?? '');
            if (!in_array($key, ['sales_nas_dir','sales_url_dir'])) throw new Exception('未知的設定項目');
            $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value,updated_by_id,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by_id=VALUES(updated_by_id),updated_by=VALUES(updated_by)")
                ->execute([$key, $val, $user_id, getUserName($pdo,$user_id)]);
            echo json_encode(['success'=>true]);
            break;
        }

        // ── BOSS 已閱使用者設定（A 或 CDRU 可執行） ──────────────────────────
        case 'save_boss_review_user': {
            $boss_uid  = intval($_POST['boss_user_id'] ?? 0);
            $boss_dept = intval($_POST['boss_dept_id'] ?? 0);
            $boss_name = $boss_uid ? getUserName($pdo, $boss_uid) : '';
            $op = getUserName($pdo, $user_id);
            $upsert = $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value,updated_by_id,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by_id=VALUES(updated_by_id),updated_by=VALUES(updated_by)");
            $upsert->execute(['boss_review_user_id',   $boss_uid  ?: '', $user_id, $op]);
            $upsert->execute(['boss_review_user_name', $boss_name,       $user_id, $op]);
            $upsert->execute(['boss_review_dept_id',   $boss_dept ?: '', $user_id, $op]);
            echo json_encode(['success'=>true]);
            break;
        }

        // ── 分頁資料載入 ──────────────────────────────────────────────────────────
        case 'load_page_data': {
            $page          = max(1, intval($_POST['page'] ?? 1));
            $limit         = 8;
            $status_filter = trim($_POST['status_filter'] ?? '');
            $src_uid       = intval($_POST['source_user_id'] ?? 0);
            $asgn_id       = intval($_POST['assignee_id'] ?? 0);
            $cust_id       = trim($_POST['customer_id'] ?? '');
            $label_ids     = json_decode($_POST['label_ids'] ?? '[]', true) ?: [];
            $search_text   = trim($_POST['search_text'] ?? '');

            $conds = []; $params = [];
            if ($status_filter === 'active')        { $conds[] = "st.status='active'"; }
            elseif ($status_filter === 'completed') { $conds[] = "st.status='completed'"; }
            elseif ($status_filter === 'boss_unread') { $conds[] = "st.status='completed' AND st.boss_reviewed=0"; }
            if ($src_uid)  { $conds[] = "st.source_user_id=?"; $params[] = $src_uid; }
            if ($asgn_id)  { $conds[] = "st.assignee_id=?";    $params[] = $asgn_id; }
            if ($cust_id)  { $conds[] = "st.customer_id=?";    $params[] = $cust_id; }
            if (!empty($label_ids)) {
                $lids = implode(',', array_map('intval', $label_ids));
                $lc   = count($label_ids);
                $conds[] = "st.track_id IN (SELECT track_id FROM sales_track_label_map WHERE label_id IN ($lids) GROUP BY track_id HAVING COUNT(DISTINCT label_id)=$lc)";
            }
            if ($search_text !== '') {
                $sl = '%'.$search_text.'%';
                $conds[] = "(st.description LIKE ? OR st.track_code LIKE ? OR st.non_std_part LIKE ? ".
                           "OR st.source_user_id IN (SELECT id FROM `user` WHERE user_cname LIKE ?) ".
                           "OR st.assignee_id IN (SELECT id FROM `user` WHERE user_cname LIKE ?) ".
                           "OR st.customer_id IN (SELECT customer_id FROM customer_list WHERE customer LIKE ?) ".
                           "OR st.d_setting_id IN (SELECT d_id FROM d_setting WHERE D_Setting_Id LIKE ?) ".
                           "OR EXISTS (SELECT 1 FROM sales_track_note sn WHERE sn.track_id=st.track_id AND sn.note_text LIKE ?))";
                for ($i=0; $i<8; $i++) $params[] = $sl;
            }
            $where = $conds ? 'WHERE '.implode(' AND ', $conds) : '';

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sales_track st $where");
            $cntStmt->execute($params);
            $total      = intval($cntStmt->fetchColumn());
            $totalPages = max(1, (int)ceil($total / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            $sRow = $pdo->query("SELECT COUNT(*) all_c, SUM(status='active') act_c, SUM(status='completed') done_c, SUM(status='completed' AND boss_reviewed=0) boss_c FROM sales_track")->fetch(PDO::FETCH_ASSOC);
            $stats = ['all'=>intval($sRow['all_c']),'active'=>intval($sRow['act_c']),'done'=>intval($sRow['done_c']),'boss_unread'=>intval($sRow['boss_c'])];

            $srcOpts  = $pdo->query("SELECT DISTINCT st.source_user_id AS id, u.user_cname AS name FROM sales_track st JOIN user u ON u.id=st.source_user_id ORDER BY u.user_cname")->fetchAll(PDO::FETCH_ASSOC);
            $asnOpts  = $pdo->query("SELECT DISTINCT st.assignee_id AS id, u.user_cname AS name FROM sales_track st JOIN user u ON u.id=st.assignee_id ORDER BY u.user_cname")->fetchAll(PDO::FETCH_ASSOC);
            $custOpts = $pdo->query("SELECT DISTINCT st.customer_id AS id, COALESCE(cl.customer, st.customer_id) AS name FROM sales_track st LEFT JOIN customer_list cl ON cl.customer_id=st.customer_id ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

            $sql = "SELECT
                st.track_id, st.track_code, st.track_date, st.status, st.boss_reviewed,
                st.source_dept_id, st.source_user_id, st.assignee_id,
                st.customer_id, st.d_setting_id, st.non_std_part, st.description,
                st.completed_at, st.completed_by, st.boss_reviewed_at, st.boss_reviewed_by,
                st.created_by, st.modified_by, st.created_at, st.updated_at,
                sd.name AS source_dept_name,
                su.user_cname AS source_user_name,
                au.user_cname AS assignee_name,
                cl.customer AS customer_name,
                cl.customer_address AS customer_address,
                ds.D_Setting_Id AS part_no,
                ds.Spec_No AS part_spec,
                cb.user_cname AS created_by_name,
                mb.user_cname AS modified_by_name,
                cpb.user_cname AS completed_by_name,
                brb.user_cname AS boss_reviewed_by_name
                FROM sales_track st
                LEFT JOIN department sd ON sd.id=st.source_dept_id
                LEFT JOIN user su ON su.id=st.source_user_id
                LEFT JOIN user au ON au.id=st.assignee_id
                LEFT JOIN customer_list cl ON cl.customer_id=st.customer_id
                LEFT JOIN d_setting ds ON ds.d_id=st.d_setting_id
                LEFT JOIN user cb ON cb.id=st.created_by
                LEFT JOIN user mb ON mb.id=st.modified_by
                LEFT JOIN user cpb ON cpb.id=st.completed_by
                LEFT JOIN user brb ON brb.id=st.boss_reviewed_by
                $where ORDER BY st.track_date DESC, st.track_id DESC
                LIMIT $limit OFFSET $offset";
            $stmt2 = $pdo->prepare($sql);
            $stmt2->execute($params);
            $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $tids = implode(',', array_map('intval', array_column($rows, 'track_id')));
                $labelMap2 = [];
                foreach ($pdo->query("SELECT m.track_id, l.label_id, l.label_name, l.color FROM sales_track_label_map m JOIN sales_track_label l ON l.label_id=m.label_id WHERE m.track_id IN ($tids)")->fetchAll(PDO::FETCH_ASSOC) as $lr) $labelMap2[$lr['track_id']][] = $lr;
                $noteMap2 = [];
                foreach ($pdo->query("SELECT n.track_id, n.note_id, n.note_text, n.created_by_name, n.created_at, n.updated_by_name, n.updated_at FROM sales_track_note n INNER JOIN (SELECT track_id, MAX(note_id) max_id FROM sales_track_note WHERE track_id IN ($tids) GROUP BY track_id) t ON t.track_id=n.track_id AND t.max_id=n.note_id")->fetchAll(PDO::FETCH_ASSOC) as $nr) $noteMap2[$nr['track_id']] = $nr;
                try { $urlRow2=$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='sales_url_dir' LIMIT 1")->fetch(PDO::FETCH_ASSOC); $url_dir2=$urlRow2?rtrim($urlRow2['setting_value'],'/').'/' :'/nas/ERP/業務/'; } catch(Exception $e2){ $url_dir2='/nas/ERP/業務/'; }
                $trackImgMap2 = [];
                foreach ($pdo->query("SELECT target_id, img_id, file_name, original_name FROM sales_track_images WHERE target_type='track' AND target_id IN ($tids) ORDER BY target_id, sort_order, img_id")->fetchAll(PDO::FETCH_ASSOC) as $ti) $trackImgMap2[intval($ti['target_id'])][] = array_merge($ti, ['url'=>$url_dir2.$ti['file_name']]);
                $noteImgMap2 = [];
                $noteIds2 = array_filter(array_map(fn($n) => $n['note_id'] ?? null, array_values($noteMap2)));
                if (!empty($noteIds2)) {
                    $nidStr = implode(',', array_map('intval', $noteIds2));
                    foreach ($pdo->query("SELECT target_id, img_id, file_name, original_name FROM sales_track_images WHERE target_type='note' AND target_id IN ($nidStr) ORDER BY target_id, sort_order, img_id")->fetchAll(PDO::FETCH_ASSOC) as $ni) $noteImgMap2[intval($ni['target_id'])][] = array_merge($ni, ['url'=>$url_dir2.$ni['file_name']]);
                }
                foreach ($rows as &$r2) {
                    $r2['labels']       = $labelMap2[$r2['track_id']] ?? [];
                    $r2['latest_note']  = $noteMap2[$r2['track_id']] ?? null;
                    $r2['track_images'] = $trackImgMap2[$r2['track_id']] ?? [];
                    if ($r2['latest_note']) $r2['latest_note']['images'] = $noteImgMap2[$r2['latest_note']['note_id']] ?? [];
                }
                unset($r2);
            }

            echo json_encode([
                'success'          => true,
                'rows'             => $rows,
                'stats'            => $stats,
                'dropdown_options' => ['sources'=>$srcOpts,'assignees'=>$asnOpts,'customers'=>$custOpts],
                'total'            => $total,
                'page'             => $page,
                'total_pages'      => $totalPages,
            ]);
            break;
        }

        // ── 找追蹤項目所在頁碼（無篩選條件） ───────────────────────────────────
        case 'find_track_page': {
            $track_id = intval($_POST['track_id'] ?? 0);
            $limit = 8;
            if (!$track_id) { echo json_encode(['success'=>true,'page'=>1]); break; }
            $s = $pdo->prepare("SELECT COUNT(*) FROM sales_track WHERE track_date > (SELECT track_date FROM sales_track WHERE track_id=?) OR (track_date=(SELECT track_date FROM sales_track WHERE track_id=?) AND track_id>?)");
            $s->execute([$track_id, $track_id, $track_id]);
            $pos  = intval($s->fetchColumn()) + 1;
            $page = max(1, (int)ceil($pos / $limit));
            echo json_encode(['success'=>true,'page'=>$page]);
            break;
        }

        default:
            throw new Exception('未知的操作');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
