<?php
// 回傳某筆公告/通知的修改歷史（含修改人、時間、前後資料），供列表「歷史」按鈕查看
header('Content-Type: application/json; charset=utf-8');

include("../../src/common/_config.php"); // session_start + $db

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
// eventid > 0：單筆公告歷史；未帶或 0：全部公告的歷史
$event_id = isset($_GET['eventid']) ? (int)$_GET['eventid'] : 0;

try {
    // 名稱對應表（解析對象 code → 可讀標籤）
    $deptMap = []; foreach ($db->query("SELECT id,name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $r) $deptMap[$r['id']] = $r['name'];
    $statMap = []; foreach ($db->query("SELECT id,title FROM user_status")->fetchAll(PDO::FETCH_ASSOC) as $r) $statMap[$r['id']] = $r['title'];
    $userMap = []; foreach ($db->query("SELECT id,user_cname FROM user")->fetchAll(PDO::FETCH_ASSOC) as $r) $userMap[$r['id']] = $r['user_cname'];

    $targetLabel = function ($code) use ($deptMap, $statMap, $userMap) {
        if ($code === 'all') return '全體';
        if (strpos($code, 'dept-') === 0)   { $i = (int)substr($code, 5); return '部門:' . ($deptMap[$i] ?? $i); }
        if (strpos($code, 'status-') === 0) { $i = (int)substr($code, 7); return '職稱:' . ($statMap[$i] ?? $i); }
        if (strpos($code, 'user-') === 0)   { $i = (int)substr($code, 5); return '人員:' . ($userMap[$i] ?? $i); }
        return $code;
    };
    $fmt = function ($snap) use ($targetLabel) {
        if (!$snap) return null;
        $targets = isset($snap['targets']) && is_array($snap['targets']) ? array_map($targetLabel, $snap['targets']) : [];
        $editors = isset($snap['editors']) && is_array($snap['editors']) ? array_map($targetLabel, $snap['editors']) : [];
        return [
            '發布日期' => $snap['eventdate'] ?? '',
            '結束日期' => $snap['enddate'] ?? '（無）',
            '標題'     => $snap['title'] ?? '',
            '內容'     => $snap['content'] ?? '',
            '對象'     => $targets ? implode('、', $targets) : '',
            '共同編輯者' => $editors ? implode('、', $editors) : '',
        ];
    };

    // 只列「修改(update)」，不含新增(create)
    if ($event_id > 0) {
        $stmt = $db->prepare(
            "SELECT h.live_event_id, le.title AS event_title, h.action, h.changed_at, h.before_data, h.after_data, u.user_cname AS changed_by_name
             FROM live_event_history h
             LEFT JOIN user u ON u.id = h.changed_by
             LEFT JOIN live_event le ON le.id = h.live_event_id
             WHERE h.live_event_id = :eid AND h.action <> 'create'
             ORDER BY h.id DESC");
        $stmt->bindValue(':eid', $event_id, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        // 全部公告的歷史（上限 300 筆）
        $stmt = $db->query(
            "SELECT h.live_event_id, le.title AS event_title, h.action, h.changed_at, h.before_data, h.after_data, u.user_cname AS changed_by_name
             FROM live_event_history h
             LEFT JOIN user u ON u.id = h.changed_by
             LEFT JOIN live_event le ON le.id = h.live_event_id
             WHERE h.action <> 'create'
             ORDER BY h.id DESC
             LIMIT 300");
    }

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $out[] = [
            'event_id'    => (int)$h['live_event_id'],
            'event_title' => $h['event_title'] ?: ('#' . $h['live_event_id']),
            'action'      => $h['action'],
            'changed_by'  => $h['changed_by_name'] ?: '（系統）',
            'changed_at'  => $h['changed_at'],
            'before'      => $fmt($h['before_data'] ? json_decode($h['before_data'], true) : null),
            'after'       => $fmt($h['after_data']  ? json_decode($h['after_data'],  true) : null),
        ];
    }
    echo json_encode(['ok' => true, 'data' => $out, 'all' => ($event_id <= 0)], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
