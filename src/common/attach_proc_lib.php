<?php
/**
 * 料號附件「製程標籤」（製程大類／製程小類，可多選、可混合）——全站唯一實作（2026-10-20）。
 *
 * 取代原本「自由文字下拉」的做法：那一欄是從訂單追蹤的手打加工項目來的，同一道製程會出現
 * 「全製」「全製 (齒研)」「代料成品」「代料至成品」…多種寫法，每次打的都可能不同。
 * 現在改成選主檔：
 *   ・製程大類 process_type（約 20 個）＝整個大類
 *   ・製程小類 process_no（個別製程）
 * 兩者可混合複選；沒選＝共用（非必填）。存在 part_attach_proc_map(attach_id, ref_type, ref_id)。
 *
 * 相容：part_attachments.process_tag 文字欄保留，並由本庫在儲存時自動寫入「選擇內容的摘要文字」
 *       （排序固定，同一組選擇永遠產生同一串文字），圖面變更判定（dwg_change_lib）仍比對這串文字，
 *       所以不必改動它；沒有重新選過的舊附件仍保留原本的文字。
 *
 * 篩選語意（料號清單篩選用，見 eg_apc_filter_sql）：
 *   選大類 T ＝ 標了 T、或標了 T 底下任何小類的附件都算；
 *   選小類 S ＝ 標了 S、或標了 S 所屬整個大類的附件都算（整個大類的圖，本來就涵蓋那道製程）。
 */

function eg_apc_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'part_attach_proc_map'")->fetch() && !$pdo->inTransaction()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS part_attach_proc_map (
                attach_id INT NOT NULL,
                ref_type  ENUM('type','no') NOT NULL COMMENT 'type=製程大類 process_type / no=製程小類 process_no',
                ref_id    INT NOT NULL,
                PRIMARY KEY (attach_id, ref_type, ref_id),
                KEY idx_ref (ref_type, ref_id)
            ) COMMENT='料號附件製程標籤（大類/小類可混選）'");
        }
    } catch (Throwable $e) {}
}

/** 字典：[{id,name,subs:[{id,name}]}]（只列啟用中的大類；大類排序依 sort_order） */
function eg_apc_dict(PDO $pdo): array {
    $types = [];
    try {
        foreach ($pdo->query("SELECT process_type_id AS id, process_type AS name FROM process_type WHERE is_active=1 ORDER BY sort_order, process_type_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $types[(int)$r['id']] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'subs' => []];
        }
        foreach ($pdo->query("SELECT ProcessNo AS id, ProcessName AS name, process_type_id AS tid FROM process_no ORDER BY ProcessNo")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (int)$r['tid'];
            if (isset($types[$tid])) $types[$tid]['subs'][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
        }
    } catch (Throwable $e) {}
    return array_values($types);
}

/** 把前端送來的選擇（JSON 字串或陣列）整理成 [['t'=>'type'|'no','id'=>int,'name'=>..],…]；不存在的丟掉、小類的大類已選就併掉 */
function eg_apc_parse(PDO $pdo, $raw): array {
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) return [];
    $dict = eg_apc_dict($pdo);
    $typeName = []; $noName = []; $noType = [];
    foreach ($dict as $t) {
        $typeName[$t['id']] = $t['name'];
        foreach ($t['subs'] as $s) { $noName[$s['id']] = $s['name']; $noType[$s['id']] = $t['id']; }
    }
    $types = []; $nos = [];
    foreach ($raw as $x) {
        if (!is_array($x)) continue;
        $id = (int)($x['id'] ?? 0);
        if (($x['t'] ?? '') === 'type' && isset($typeName[$id])) $types[$id] = 1;
        elseif (($x['t'] ?? '') === 'no' && isset($noName[$id])) $nos[$id] = 1;
    }
    $out = [];
    ksort($types);
    foreach (array_keys($types) as $id) $out[] = ['t' => 'type', 'id' => $id, 'name' => $typeName[$id]];
    ksort($nos);
    foreach (array_keys($nos) as $id) {
        if (isset($types[$noType[$id]])) continue;     // 整個大類都選了，小類是重複的
        $out[] = ['t' => 'no', 'id' => $id, 'name' => $noName[$id]];
    }
    return $out;
}

/** 摘要文字（寫進 process_tag 供舊程式比對／顯示）；固定順序、最長 100 字 */
function eg_apc_summary(array $sel): string {
    $s = implode('、', array_map(function ($x) { return $x['name']; }, $sel));
    return mb_substr($s, 0, 100, 'UTF-8');
}

function eg_apc_save(PDO $pdo, int $attId, array $sel): void {
    eg_apc_ensure($pdo);
    $pdo->prepare("DELETE FROM part_attach_proc_map WHERE attach_id=?")->execute([$attId]);
    if (!$sel) return;
    $ins = $pdo->prepare("INSERT IGNORE INTO part_attach_proc_map (attach_id, ref_type, ref_id) VALUES (?,?,?)");
    foreach ($sel as $x) $ins->execute([$attId, $x['t'], $x['id']]);
}

/** 給附件列表加上 process_sel（只處理 source=part 的列） */
function eg_apc_attach_sel(PDO $pdo, array $rows): array {
    $ids = [];
    foreach ($rows as $r) if (($r['source'] ?? '') === 'part') $ids[] = (int)$r['id'];
    $map = [];
    if ($ids) {
        try {
            eg_apc_ensure($pdo);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT m.attach_id, m.ref_type AS t, m.ref_id AS id,
                                        IF(m.ref_type='type', pt.process_type, pn.ProcessName) AS name
                                   FROM part_attach_proc_map m
                                   LEFT JOIN process_type pt ON m.ref_type='type' AND pt.process_type_id=m.ref_id
                                   LEFT JOIN process_no pn ON m.ref_type='no' AND pn.ProcessNo=m.ref_id
                                  WHERE m.attach_id IN ($ph)
                                  ORDER BY m.ref_type, m.ref_id");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[(int)$r['attach_id']][] = ['t' => $r['t'], 'id' => (int)$r['id'], 'name' => (string)$r['name']];
            }
        } catch (Throwable $e) {}
    }
    foreach ($rows as &$r) { if (($r['source'] ?? '') === 'part') $r['process_sel'] = $map[(int)$r['id']] ?? []; }
    unset($r);
    return $rows;
}

/**
 * 料號清單篩選用：回傳 SQL 片段（「d.d_id 底下有沒有符合的附件」的 EXISTS，開頭帶 AND）；沒選就回空字串。
 * @param string $dAlias  料號主檔的別名（預設 d）
 */
function eg_apc_filter_sql(PDO $pdo, $raw, string $dAlias = 'd'): string {
    $sel = eg_apc_parse($pdo, $raw);
    if (!$sel) return '';
    $dict = eg_apc_dict($pdo);
    $noType = []; $typeNos = [];
    foreach ($dict as $t) foreach ($t['subs'] as $s) { $noType[$s['id']] = $t['id']; $typeNos[$t['id']][] = $s['id']; }
    $tIds = []; $nIds = [];
    foreach ($sel as $x) {
        if ($x['t'] === 'type') { $tIds[$x['id']] = 1; foreach ($typeNos[$x['id']] ?? [] as $n) $nIds[$n] = 1; }
        else { $nIds[$x['id']] = 1; if (isset($noType[$x['id']])) $tIds[$noType[$x['id']]] = 1; }
    }
    $conds = [];
    if ($tIds) $conds[] = "(m.ref_type='type' AND m.ref_id IN (" . implode(',', array_map('intval', array_keys($tIds))) . "))";
    if ($nIds) $conds[] = "(m.ref_type='no' AND m.ref_id IN (" . implode(',', array_map('intval', array_keys($nIds))) . "))";
    if (!$conds) return '';
    require_once __DIR__ . '/imgedit_visibility.php';
    return " AND EXISTS (SELECT 1 FROM part_attachments apa JOIN part_attach_proc_map m ON m.attach_id=apa.id"
         . " WHERE apa.d_id={$dAlias}.d_id AND apa.deleted_at IS NULL AND " . imgedit_sql_not_draft('apa')
         . " AND (" . implode(' OR ', $conds) . "))";
}

/* ═══ 製程選擇「組合」（2026-10-22）：常用的 大類/小類 混合選擇存成一組，之後一鍵套用 ═══
 * 全公司共用一份（現場常用的組合大家都一樣）；任何人可新增，刪除限建立者與系統管理員(id=1)。
 * 只存 [{t,id}]，名稱一律即時對字典取，主檔改名組合跟著變。 */
function eg_apc_combo_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'part_attach_proc_combo'")->fetch() && !$pdo->inTransaction()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS part_attach_proc_combo (
                combo_id INT AUTO_INCREMENT PRIMARY KEY,
                combo_name VARCHAR(60) NOT NULL,
                items_json TEXT NOT NULL COMMENT '[{t:type|no,id}]',
                created_by INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_name (combo_name)
            ) COMMENT='製程選擇組合（大類/小類混合）'");
        }
    } catch (Throwable $e) {}
}

function eg_apc_combo_list(PDO $pdo): array {
    eg_apc_combo_ensure($pdo);
    $out = [];
    try {
        foreach ($pdo->query("SELECT combo_id, combo_name, items_json, created_by FROM part_attach_proc_combo ORDER BY combo_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items = eg_apc_parse($pdo, $r['items_json']);   // 已不存在的製程自動濾掉
            $out[] = ['id' => (int)$r['combo_id'], 'name' => $r['combo_name'], 'items' => $items, 'created_by' => (int)$r['created_by']];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 新增；同名則覆寫內容（只有建立者／管理員可覆寫）。回 [ok, message] */
function eg_apc_combo_save(PDO $pdo, string $name, $items, int $uid): array {
    eg_apc_combo_ensure($pdo);
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_strlen($name) > 60) return [false, '組合名稱必填，且不可超過 60 字'];
    $sel = eg_apc_parse($pdo, $items);
    if (count($sel) < 2) return [false, '組合至少要選 2 項（只選 1 項直接勾選就好）'];
    $json = json_encode(array_map(function ($x) { return ['t' => $x['t'], 'id' => $x['id']]; }, $sel));
    $st = $pdo->prepare("SELECT combo_id, created_by FROM part_attach_proc_combo WHERE combo_name=?");
    $st->execute([$name]);
    $ex = $st->fetch(PDO::FETCH_ASSOC);
    if ($ex) {
        if ((int)$ex['created_by'] !== $uid && $uid !== 1) return [false, '已有同名組合，且不是你建立的，請換個名稱'];
        $pdo->prepare("UPDATE part_attach_proc_combo SET items_json=? WHERE combo_id=?")->execute([$json, $ex['combo_id']]);
        return [true, '已更新組合「' . $name . '」'];
    }
    $pdo->prepare("INSERT INTO part_attach_proc_combo (combo_name, items_json, created_by) VALUES (?,?,?)")->execute([$name, $json, $uid]);
    return [true, '已建立組合「' . $name . '」'];
}

function eg_apc_combo_del(PDO $pdo, int $id, int $uid): array {
    eg_apc_combo_ensure($pdo);
    $st = $pdo->prepare("SELECT created_by FROM part_attach_proc_combo WHERE combo_id=?");
    $st->execute([$id]);
    $by = $st->fetchColumn();
    if ($by === false) return [false, '組合不存在（可能已被刪除）'];
    if ((int)$by !== $uid && $uid !== 1) return [false, '只有建立者或系統管理員可以刪除'];
    $pdo->prepare("DELETE FROM part_attach_proc_combo WHERE combo_id=?")->execute([$id]);
    return [true, '已刪除'];
}
