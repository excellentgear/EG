<?php
/* 舊會議記錄回寫行事曆：以「同一天＋同主題」配對尚未被連結的行事曆會議事件(category_id=2)，
   記下 meeting_record.cal_event_id 後同步時間／主題／出席人員。
   用法：php 2026-11-18_meeting_cal_link.php        試算（不寫入）
         php 2026-11-18_meeting_cal_link.php --run  實際寫入；可重複執行（已連結者不再處理） */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("僅限 CLI 執行\n"); }
$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/meeting_lib.php';
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
meeting_ensure_schema($db);
$RUN = in_array('--run', $argv ?? [], true);

$ms = $db->query("SELECT meeting_id, subject, meeting_date FROM meeting_record WHERE cal_event_id IS NULL ORDER BY meeting_date")->fetchAll(PDO::FETCH_ASSOC);
$used = array_map('intval', $db->query("SELECT cal_event_id FROM meeting_record WHERE cal_event_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
$q = $db->prepare("SELECT id FROM evenement WHERE category_id=2 AND DATE(start)=? AND title=? ORDER BY id");
$n = 0; $skip = 0;
foreach ($ms as $m) {
    $q->execute([$m['meeting_date'], $m['subject']]);
    $cands = array_values(array_diff(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), $used));
    if (count($cands) !== 1) { $skip++; echo "略過 #{$m['meeting_id']} {$m['meeting_date']} {$m['subject']}（候選 " . count($cands) . " 筆）\n"; continue; }
    echo ($RUN ? '連結' : '將連結') . " #{$m['meeting_id']} {$m['meeting_date']} {$m['subject']} → 行事曆事件 {$cands[0]}\n";
    $used[] = $cands[0]; $n++;
    if ($RUN) {
        $db->prepare("UPDATE meeting_record SET cal_event_id=? WHERE meeting_id=?")->execute([$cands[0], $m['meeting_id']]);
        meeting_sync_calendar($db, (int)$m['meeting_id']);
    }
}
echo "完成：" . ($RUN ? '已連結並同步 ' : '可連結 ') . "{$n} 筆，略過 {$skip} 筆\n";
