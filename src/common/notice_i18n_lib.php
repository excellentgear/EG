<?php
// 公告／通知 翻譯版本（越南文）。原文（live_event）不動，翻譯另存，已簽核／已發布的公告也能補。
// 權限唯一判定 ni_can_translate()：系統管理員、公告者、共同編輯者（與列表編輯權限一致，但不受簽核狀態限制）。

function ni_langs(): array { return ['vi' => 'Tiếng Việt（越南文）']; }

function ni_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $has = $db->query("SHOW TABLES LIKE 'live_event_i18n'")->fetchColumn();
    if ($has) {
        if (!$db->query("SHOW COLUMNS FROM live_event_i18n LIKE 'content_html'")->fetchColumn())
            $db->exec("ALTER TABLE live_event_i18n ADD COLUMN content_html MEDIUMTEXT NULL AFTER content");
        return;
    }
    $db->exec("CREATE TABLE live_event_i18n (
        live_event_id INT NOT NULL,
        lang VARCHAR(5) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        content MEDIUMTEXT NULL,
        content_html MEDIUMTEXT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (live_event_id, lang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告翻譯版本'");
}

function ni_can_translate(PDO $db, int $uid, array $event, array $features): bool
{
    if (in_array('all', $features, true)) return true;
    if ((int)$event['created_by'] === $uid) return true;
    $ds = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id = ?");
    $ds->execute([$uid]);
    $depts = array_map('intval', $ds->fetchAll(PDO::FETCH_COLUMN));
    $st = $db->prepare("SELECT editor_type, editor_id FROM live_event_editor WHERE live_event_id = ?");
    $st->execute([(int)$event['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        if ($e['editor_type'] === 'user' && (int)$e['editor_id'] === $uid) return true;
        if ($e['editor_type'] === 'dept' && in_array((int)$e['editor_id'], $depts, true)) return true;
    }
    return false;
}

/** 回傳 ['vi'=>['title'=>,'content'=>]]，沒有翻譯則為空陣列 */
function ni_get_all(PDO $db, int $eventId): array
{
    try {
        ni_ensure_schema($db);
        $st = $db->prepare("SELECT lang, title, content, content_html FROM live_event_i18n WHERE live_event_id = ?");
        $st->execute([$eventId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (trim($r['title']) === '' && trim((string)$r['content']) === '') continue;
            $out[$r['lang']] = ['title' => $r['title'], 'content' => (string)$r['content'], 'content_html' => (string)($r['content_html'] ?? '')];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}
