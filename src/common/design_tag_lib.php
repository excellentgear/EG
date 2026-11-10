<?php
// design_tag_lib.php — 料號「設計提示標籤」唯一實作（2026-11-10 使用者交辦）
//
// 用途：料號主檔（新建／編輯料號）可替每個料號勾選幾個「設計提示標籤」（例：需齒研、公差嚴、客供料），
// 標籤本身（文字、按鈕顏色、排序）由有權限者在料號視窗標題旁的齒輪設定。訂單追蹤的「設計提醒規則」
// 可用「設計提示標籤」或「客戶＋設計提示標籤」當提示對象（design_alert_lib.php 的 tag／cust_tag）。
//
// 資料表：
//   design_tag(id, name, color_idx, sort_order, is_active)   標籤主檔（顏色只存色盤編號，色盤見 dt_palette()）
//   d_setting_design_tag(d_id, tag_id)                         料號勾了哪些標籤
// 刻意：標籤一律以 id 關聯，改名不會讓料號或規則失聯；已被料號或規則使用的標籤不可直接刪除
//（改用「停用」＝新料號不再出現，已勾的與已設的規則照常運作）。

if (!function_exists('dt_palette')) {

/** 固定暖色系色盤（ai-rules/10）：編號 => [名稱, 底色, 字色]。使用者只選編號，不給任意取色 */
function dt_palette(): array
{
    return [
        0 => ['砂',   '#F7E0BD', '#5A3A12'],
        1 => ['琥珀', '#F0A24B', '#3E2200'],
        2 => ['橘',   '#E8833A', '#3E2200'],
        3 => ['珊瑚', '#DD5138', '#FFFFFF'],
        4 => ['赭',   '#B5651D', '#FFFFFF'],
        5 => ['暖棕', '#8A5A3B', '#FFFFFF'],
        6 => ['淺橘', '#FBD5A5', '#5A3A12'],
        7 => ['磚紅', '#A8412C', '#FFFFFF'],
    ];
}

function dt_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    // DDL 會造成隱式 commit：呼叫端一定要在 beginTransaction() 之前先呼叫本函式
    $db->exec("CREATE TABLE IF NOT EXISTS design_tag (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(30) NOT NULL,
        color_idx TINYINT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        created_by INT NULL, created_at DATETIME NULL, updated_by INT NULL, updated_at DATETIME NULL,
        KEY idx_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='料號設計提示標籤主檔'");
    $db->exec("CREATE TABLE IF NOT EXISTS d_setting_design_tag (
        d_id INT NOT NULL,
        tag_id INT NOT NULL,
        PRIMARY KEY (d_id, tag_id),
        KEY idx_tag (tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='料號勾選的設計提示標籤'");
}

/** 標籤清單（依排序）；$activeOnly=false 時連停用的也回（設定畫面、已勾選的顯示用） */
function dt_list(PDO $db, bool $activeOnly = true): array
{
    dt_ensure_schema($db);
    $sql = "SELECT id, name, color_idx, sort_order, is_active FROM design_tag"
         . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY sort_order, id";
    $pal = dt_palette();
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['color_idx'] = isset($pal[(int)$r['color_idx']]) ? (int)$r['color_idx'] : 0;
        $r['is_active'] = (int)$r['is_active'];
        $c = $pal[$r['color_idx']];
        $r['bg'] = $c[1];
        $r['fg'] = $c[2];
    }
    unset($r);
    return $rows;
}

/** 每個標籤被幾個料號、幾則設計提醒規則使用：[id => ['parts'=>n,'rules'=>n]] */
function dt_usage(PDO $db): array
{
    dt_ensure_schema($db);
    $u = [];
    foreach ($db->query("SELECT tag_id, COUNT(*) n FROM d_setting_design_tag GROUP BY tag_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u[(int)$r['tag_id']]['parts'] = (int)$r['n'];
    }
    try {
        foreach ($db->query("SELECT tag_id, COUNT(*) n FROM order_design_alert_rule WHERE tag_id IS NOT NULL GROUP BY tag_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $u[(int)$r['tag_id']]['rules'] = (int)$r['n'];
        }
    } catch (Throwable $e) { /* 規則表／欄位尚未建立＝沒有規則在用 */ }
    return $u;
}

/**
 * 整批儲存標籤設定。$items＝[{id,name,color_idx,is_active}, ...]，陣列順序＝排序。
 * id=0 新增；DB 有、但清單沒有的標籤＝刪除（被料號或規則使用中就整批拒絕並講明是哪個）。
 * 回傳新清單。不合法丟 InvalidArgumentException（訊息直接給使用者看）。
 */
function dt_save_all(PDO $db, array $items, int $uid): array
{
    dt_ensure_schema($db);
    $pal = dt_palette();
    if (count($items) > 40) throw new InvalidArgumentException('標籤最多 40 組');
    $clean = []; $names = [];
    foreach (array_values($items) as $it) {
        $name = trim((string)($it['name'] ?? ''));
        if ($name === '') continue;   // 空白列直接略過（按了「新增一組」卻沒填就存檔）
        if (mb_strlen($name, 'UTF-8') > 20) throw new InvalidArgumentException("標籤「{$name}」請控制在 20 字以內");
        $k = mb_strtolower($name, 'UTF-8');
        if (isset($names[$k])) throw new InvalidArgumentException("標籤「{$name}」重複了");
        $names[$k] = true;
        $ci = (int)($it['color_idx'] ?? 0);
        if (!isset($pal[$ci])) $ci = 0;
        $clean[] = ['id' => (int)($it['id'] ?? 0), 'name' => $name, 'color_idx' => $ci, 'is_active' => !empty($it['is_active']) ? 1 : 0];
    }
    $have = [];
    foreach ($db->query("SELECT id, name FROM design_tag")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(int)$r['id']] = (string)$r['name'];
    $keep = [];
    foreach ($clean as $c) if ($c['id'] > 0) $keep[$c['id']] = true;
    $usage = dt_usage($db);
    $blocked = [];
    foreach ($have as $id => $nm) {
        if (isset($keep[$id])) continue;
        $p = (int)($usage[$id]['parts'] ?? 0); $r = (int)($usage[$id]['rules'] ?? 0);
        if ($p > 0 || $r > 0) $blocked[] = "「{$nm}」（{$p} 個料號、{$r} 則設計提醒規則在用）";
    }
    if ($blocked) throw new InvalidArgumentException('以下標籤還在使用中，不能刪除（請改按「停用」）：' . implode('、', $blocked));
    $db->beginTransaction();
    try {
        foreach ($have as $id => $nm) {
            if (!isset($keep[$id])) $db->prepare("DELETE FROM design_tag WHERE id=?")->execute([$id]);
        }
        foreach ($clean as $i => $c) {
            if ($c['id'] > 0 && isset($have[$c['id']])) {
                $db->prepare("UPDATE design_tag SET name=?, color_idx=?, sort_order=?, is_active=?, updated_by=?, updated_at=NOW() WHERE id=?")
                   ->execute([$c['name'], $c['color_idx'], $i + 1, $c['is_active'], ($uid ?: null), $c['id']]);
            } else {
                $db->prepare("INSERT INTO design_tag (name, color_idx, sort_order, is_active, created_by, created_at, updated_by, updated_at) VALUES (?,?,?,?,?,NOW(),?,NOW())")
                   ->execute([$c['name'], $c['color_idx'], $i + 1, $c['is_active'], ($uid ?: null), ($uid ?: null)]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return dt_list($db, false);
}

/** 這個料號目前勾選的標籤 id 陣列 */
function dt_part_tag_ids(PDO $db, int $dId): array
{
    dt_ensure_schema($db);
    $st = $db->prepare("SELECT tag_id FROM d_setting_design_tag WHERE d_id=? ORDER BY tag_id");
    $st->execute([$dId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 設定這個料號的標籤（整批取代）。只收主檔裡存在的標籤，其他 id 一律丟掉（直打 API 也塞不進去）。
 * 已停用的標籤：料號原本就勾著的可以保留，但不能新勾。由 save_part 的交易內呼叫（DDL 要先在交易外建好）。
 */
function dt_part_set(PDO $db, int $dId, array $tagIds): void
{
    $want = [];
    foreach ($tagIds as $t) { $t = (int)$t; if ($t > 0) $want[$t] = true; }
    $valid = [];
    foreach ($db->query("SELECT id, is_active FROM design_tag")->fetchAll(PDO::FETCH_ASSOC) as $r) $valid[(int)$r['id']] = (int)$r['is_active'];
    $cur = array_flip(dt_part_tag_ids($db, $dId));
    $final = [];
    foreach (array_keys($want) as $t) {
        if (!isset($valid[$t])) continue;
        if ($valid[$t] === 0 && !isset($cur[$t])) continue;   // 停用的標籤不能新勾
        $final[] = $t;
    }
    $db->prepare("DELETE FROM d_setting_design_tag WHERE d_id=?")->execute([$dId]);
    $ins = $db->prepare("INSERT IGNORE INTO d_setting_design_tag (d_id, tag_id) VALUES (?,?)");
    foreach ($final as $t) $ins->execute([$dId, $t]);
}

}
