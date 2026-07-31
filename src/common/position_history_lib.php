<?php
/**
 * 職務調動紀錄共用庫（ai-rules/14 P1：異動紀錄與前後對照）
 *
 * 快照格式（before_json / after_json）：
 *   [{department_id, department_name, position_id, position_name, is_main}, ...]
 *   名稱一起存：部門/職稱日後改名，舊紀錄仍看得懂當時狀況。
 *
 * 「某人在某日期的職務」解析規則（eg_position_snapshot_at）：
 *   1. 取 生效日 <= 該日期 的最後一筆紀錄 → 用其 after_json；
 *   2. 該日期早於最早一筆的生效日 → 用最早那筆的 before_json；
 *   3. 完全沒有紀錄 → 用目前的 user_department_position_map（現況）。
 *   補登愈完整、解析愈準；沒補登的人一律回現況（與既有行為相同）。
 */

/** 目前職務快照（含名稱），主職排最前 */
function eg_position_snapshot_now(PDO $db, int $userId): array {
    $st = $db->prepare(
        "SELECT m.department_id, d.name AS department_name, m.position_id, p.name AS position_name, m.is_main
         FROM user_department_position_map m
         LEFT JOIN department d ON d.id = m.department_id
         LEFT JOIN position p ON p.id = m.position_id
         WHERE m.user_id = ? ORDER BY m.is_main DESC, m.id");
    $st->execute([$userId]);
    return array_map('eg_position_snap_row', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** 全員目前職務快照：user_id => snapshot（批次查詢用，避免逐人查） */
function eg_position_snapshot_now_bulk(PDO $db): array {
    $rows = $db->query(
        "SELECT m.user_id, m.department_id, d.name AS department_name, m.position_id, p.name AS position_name, m.is_main
         FROM user_department_position_map m
         LEFT JOIN department d ON d.id = m.department_id
         LEFT JOIN position p ON p.id = m.position_id
         ORDER BY m.user_id, m.is_main DESC, m.id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['user_id']][] = eg_position_snap_row($r);
    return $out;
}

function eg_position_snap_row(array $r): array {
    return ['department_id' => (int)$r['department_id'], 'department_name' => (string)($r['department_name'] ?? ''),
            'position_id' => (int)$r['position_id'], 'position_name' => (string)($r['position_name'] ?? ''),
            'is_main' => (int)$r['is_main']];
}

/** 某人在某日期(Y-m-d)的職務快照（解析規則見檔頭） */
function eg_position_snapshot_at(PDO $db, int $userId, string $date): array {
    $st = $db->prepare("SELECT effective_date, before_json, after_json FROM user_position_history
                        WHERE user_id = ? ORDER BY effective_date, id");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return eg_position_snapshot_now($db, $userId);
    return eg_position_resolve_from_rows($rows, $date);
}

/** 全員在某日期的職務快照：user_id => snapshot（有紀錄者依紀錄解析，其餘回現況） */
function eg_position_snapshot_at_bulk(PDO $db, string $date): array {
    $out = eg_position_snapshot_now_bulk($db);
    $rows = $db->query("SELECT user_id, effective_date, before_json, after_json FROM user_position_history
                        ORDER BY user_id, effective_date, id")->fetchAll(PDO::FETCH_ASSOC);
    $byUser = [];
    foreach ($rows as $r) $byUser[(int)$r['user_id']][] = $r;
    foreach ($byUser as $uid => $hist) $out[$uid] = eg_position_resolve_from_rows($hist, $date);
    return $out;
}

/** 由該員的歷史紀錄列（已依 effective_date,id 排序）解析出指定日期的快照 */
function eg_position_resolve_from_rows(array $rows, string $date): array {
    $pick = null;
    foreach ($rows as $r) {
        if ($r['effective_date'] <= $date) $pick = $r;   // 生效日 <= 查詢日的最後一筆
        else break;
    }
    if ($pick !== null) return eg_position_snap_decode($pick['after_json']);
    return eg_position_snap_decode($rows[0]['before_json']);   // 早於最早一筆 → 用其異動前快照
}

function eg_position_snap_decode($json): array {
    $a = json_decode((string)$json, true);
    if (!is_array($a)) return [];
    return array_values(array_filter(array_map(function ($r) {
        return is_array($r) ? eg_position_snap_row($r + ['department_id' => 0, 'position_id' => 0, 'is_main' => 0]) : null;
    }, $a)));
}

/** 快照 → 顯示字串：「部門/職稱(主)、部門/職稱」 */
function eg_position_snapshot_label(array $snap): string {
    if (!$snap) return '（無職務）';
    $parts = [];
    foreach ($snap as $s) {
        $parts[] = ($s['department_name'] !== '' ? $s['department_name'] : ('部門' . $s['department_id']))
                 . '/' . ($s['position_name'] !== '' ? $s['position_name'] : ('職稱' . $s['position_id']))
                 . ($s['is_main'] ? '(主)' : '');
    }
    return implode('、', $parts);
}

/** 兩份快照是否有實質差異（比 部門:職稱:主兼 組合，名稱改了不算異動） */
function eg_position_snapshot_changed(array $before, array $after): bool {
    $key = fn($s) => $s['department_id'] . ':' . $s['position_id'] . ':' . $s['is_main'];
    $a = array_map($key, $before); $b = array_map($key, $after);
    sort($a); sort($b);
    return $a !== $b;
}

/** 依前後快照推導異動類型：主職有動＝transfer，否則看兼任增減 */
function eg_position_change_type(array $before, array $after): string {
    $main = function (array $snap) {
        foreach ($snap as $s) if ($s['is_main']) return $s['department_id'] . ':' . $s['position_id'];
        return '';
    };
    if ($main($before) !== $main($after)) return 'transfer';
    $key = fn($s) => $s['department_id'] . ':' . $s['position_id'];
    $b = array_map($key, $before); $a = array_map($key, $after);
    $added = array_diff($a, $b); $removed = array_diff($b, $a);
    if ($added && !$removed) return 'concurrent_add';
    if ($removed && !$added) return 'concurrent_remove';
    return 'transfer';
}

/**
 * 寫入一筆職務調動紀錄＋audit_log（呼叫端負責 transaction，兩邊同交易；ai-rules/14 鐵律1）
 * $source：'auto'=異動當下系統寫入 / 'manual'=人事補登（補登列才可刪）
 */
function eg_position_history_write(PDO $db, int $userId, string $changeType, array $before, array $after,
                                   string $effectiveDate, ?string $reason, string $source,
                                   ?int $opId, ?string $opName): int {
    $db->prepare("INSERT INTO user_position_history
                  (user_id, change_type, before_json, after_json, effective_date, reason, source, operator_id, operator)
                  VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([$userId, $changeType,
                  json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE),
                  $effectiveDate, ($reason === '' ? null : $reason), $source, $opId, $opName ?: null]);
    $histId = (int)$db->lastInsertId();

    $nameSt = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
    $nameSt->execute([$userId]);
    $uname = (string)($nameSt->fetchColumn() ?: $userId);
    $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                  VALUES ('POSITION_CHANGE', 'user', ?, ?, ?, ?, ?, NOW())")
       ->execute([(string)$userId, $uname,
                  json_encode(['change_type' => $changeType, 'effective_date' => $effectiveDate, 'source' => $source,
                               'before' => eg_position_snapshot_label($before), 'after' => eg_position_snapshot_label($after),
                               'reason' => $reason], JSON_UNESCAPED_UNICODE),
                  $opId, $opName ?: '']);
    return $histId;
}
