<?php
/**
 * order_report_lib.php — 訂單「是否檢附報告」（唯一實作）
 *
 * 新增訂單跳窗最下方一列：必選「否」或「管理員設定的報告項目」（名稱＋對應製程大類 process_type_id，
 * 例：熱處理報告→大類 7）。選「否」＝不檢附報告，其他項目不出現；沒選「否」就至少要勾一個項目。
 *
 * 設計重點：
 *  1) 不動 order_track（現場每天在用的大表），另建兩張新表，完全不影響既有使用者。
 *  2) 【DDL 不可在交易裡跑】orp_ensure_schema() 一律在 beginTransaction() 之前呼叫（先 SHOW TABLES 判斷）。
 *  3) 只有前端送 rpt_form=1 才驗證／寫入（舊版快取頁面、拆批、快速建單等其他入口不受影響）。
 *  4) 編輯舊訂單：該訂單從未選過報告（舊資料）就不強迫補選；已選過才要求維持有選。
 *  5) 選項名稱與大類會在訂單上留一份快照（order_report_sel），日後管理員改名/停用不會改變歷史訂單。
 */

function orp_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        if (!$db->query("SHOW TABLES LIKE 'order_report_opt'")->fetchColumn()) {
            $db->exec("CREATE TABLE IF NOT EXISTS order_report_opt (
                opt_id INT AUTO_INCREMENT PRIMARY KEY,
                opt_name VARCHAR(60) NOT NULL,
                process_type_id INT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NULL,
                created_by VARCHAR(50) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!$db->query("SHOW TABLES LIKE 'order_report_sel'")->fetchColumn()) {
            $db->exec("CREATE TABLE IF NOT EXISTS order_report_sel (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                opt_id INT NOT NULL DEFAULT 0,
                opt_name VARCHAR(60) NULL,
                process_type_id INT NULL,
                set_by INT NULL,
                set_at DATETIME NULL,
                UNIQUE KEY uk_order_opt (order_id, opt_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        // 對應 part_viewer「ERP/資材報告 檔名標籤」的檔名後綴（可空；2026-11-26）
        if (!$db->query("SHOW COLUMNS FROM order_report_opt LIKE 'tag_suffix'")->fetchColumn()) {
            $db->exec("ALTER TABLE order_report_opt ADD COLUMN tag_suffix VARCHAR(30) NULL AFTER process_type_id");
        }
        $done = true;
    } catch (Throwable $e) { error_log('orp_ensure_schema: ' . $e->getMessage()); }
}

/** 設定權限：管理員全開；其餘只看角色功能碼（刻意不 fail-open：沒指派角色的人一律沒有） */
function orp_perms(PDO $db, int $uid): array
{
    $p = ['add' => false, 'edit' => false, 'del' => false];
    if ($uid <= 0) return $p;
    try {
        require_once __DIR__ . '/order_track_perm_lib.php';
        if (ot_is_admin($db, $uid)) return ['add' => true, 'edit' => true, 'del' => true];
        require_once __DIR__ . '/role_features_helper.php';
        $f = rf_load_user_features_all($db, $uid);
        if (empty($f)) return $p;
        $all = rf_has_feature($f, 'all');
        return ['add' => $all || rf_has_feature($f, 'ot_report_opt_add'),
                'edit' => $all || rf_has_feature($f, 'ot_report_opt_edit'),
                'del' => $all || rf_has_feature($f, 'ot_report_opt_delete')];
    } catch (Throwable $e) { return $p; }
}

/** 已被報告項目綁定的檔名後綴（part_viewer 的檔名標籤設定據此反灰，不可改名／刪除） */
function orp_locked_suffixes(PDO $db): array
{
    orp_ensure_schema($db);
    try {
        return array_values(array_filter(array_map('strval',
            $db->query("SELECT DISTINCT tag_suffix FROM order_report_opt WHERE tag_suffix IS NOT NULL AND tag_suffix<>''")->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) { return []; }
}

/** 可綁定的檔名後綴＝part_viewer 檔名標籤設定中的全部標籤（勾了「報告」的排前面，其餘註明未勾） */
function orp_suffix_choices(PDO $db): array
{
    require_once __DIR__ . '/bom_dir_lib.php';
    $out = [];
    foreach (eg_bom_file_tags_all($db) as $t) {
        $out[] = ['suffix' => $t['suffix'], 'label' => $t['label'] . (empty($t['is_report']) ? '（未勾報告）' : ''),
                  'process_type_id' => $t['process_type_id'], 'is_report' => !empty($t['is_report']) ? 1 : 0];
    }
    usort($out, function ($x, $y) { return $y['is_report'] <=> $x['is_report']; });   // 穩定排序：報告標籤在前
    return $out;
}

/** 製程大類清單（供設定下拉） */
function orp_process_types(PDO $db): array
{
    try {
        return $db->query("SELECT process_type_id AS id, process_type AS name FROM process_type WHERE COALESCE(is_active,1)=1 ORDER BY sort_order, process_type_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 設定的報告項目。$keepIds＝這張訂單目前已選的（即使已停用也保留顯示） */
function orp_options(PDO $db, bool $onlyActive = true, array $keepIds = []): array
{
    orp_ensure_schema($db);
    try {
        $sql = "SELECT o.opt_id, o.opt_name, o.process_type_id, o.tag_suffix, o.sort_order, o.is_active, pt.process_type AS ptype_name
                FROM order_report_opt o LEFT JOIN process_type pt ON pt.process_type_id = o.process_type_id";
        $rows = $db->query($sql . " ORDER BY o.sort_order, o.opt_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $keep = array_flip(array_map('intval', $keepIds));
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['opt_id'];
        if ($onlyActive && !(int)$r['is_active'] && !isset($keep[$id])) continue;
        $out[] = [
            'opt_id' => $id, 'opt_name' => (string)$r['opt_name'],
            'process_type_id' => $r['process_type_id'] === null ? null : (int)$r['process_type_id'],
            'ptype_name' => (string)($r['ptype_name'] ?? ''),
            'tag_suffix' => (string)($r['tag_suffix'] ?? ''),
            'is_active' => (int)$r['is_active'],
        ];
    }
    return $out;
}

/** 某張訂單已存的選擇：['none'=>bool,'ids'=>[opt_id…],'saved'=>bool(曾經存過)] */
function orp_get_for_order(PDO $db, int $orderId): array
{
    orp_ensure_schema($db);
    $res = ['none' => false, 'ids' => [], 'saved' => false];
    if ($orderId <= 0) return $res;
    try {
        $st = $db->prepare("SELECT opt_id FROM order_report_sel WHERE order_id=?");
        $st->execute([$orderId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $res['saved'] = true;
            if ((int)$id === 0) $res['none'] = true; else $res['ids'][] = (int)$id;
        }
    } catch (Throwable $e) {}
    return $res;
}

/**
 * 驗證前端送來的選擇（INSERT/UPDATE 之前呼叫）。
 * 回傳 ['sent'=>bool,'ok'=>bool,'msg'=>string,'none'=>bool,'ids'=>[]]
 */
function orp_validate(PDO $db, bool $isNew, int $orderId = 0): array
{
    $r = ['sent' => false, 'ok' => true, 'msg' => '', 'none' => false, 'ids' => []];
    if (empty($_POST['rpt_form'])) return $r;           // 沒送＝舊呼叫端，一律不動
    $r['sent'] = true;
    orp_ensure_schema($db);
    $none = !empty($_POST['rpt_none']) && $_POST['rpt_none'] !== '0';
    $ids = json_decode((string)($_POST['rpt_opts'] ?? '[]'), true);
    $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }))) : [];
    if ($none) { $r['none'] = true; return $r; }         // 「否」優先，其他項目一律忽略

    $prev = $isNew ? ['saved' => false, 'ids' => []] : orp_get_for_order($db, $orderId);
    if (!$ids) {
        // 新增一律必選；編輯時，舊資料（從未選過）不強迫補選
        if ($isNew || $prev['saved']) {
            $r['ok'] = false;
            $r['msg'] = '請選擇「是否檢附報告」：勾選「否」，或勾選需檢附的報告項目。';
        }
        return $r;
    }
    $valid = [];
    foreach (orp_options($db, true, $prev['ids']) as $o) $valid[$o['opt_id']] = true;
    foreach ($ids as $id) {
        if (!isset($valid[$id])) {
            $r['ok'] = false; $r['msg'] = '所選的報告項目已不存在或已停用，請重新整理頁面後再選。'; return $r;
        }
    }
    $r['ids'] = $ids;
    return $r;
}

/** 寫入（取代這張訂單原有的選擇）。訂單建立後呼叫；唯一寫入點。 */
function orp_apply(PDO $db, int $orderId, array $chk, int $uid): void
{
    if (empty($chk['sent']) || $orderId <= 0) return;
    if (!$chk['none'] && !$chk['ids'] && !orp_get_for_order($db, $orderId)['saved']) return;   // 舊訂單沒選＝不寫
    try {
        $db->prepare("DELETE FROM order_report_sel WHERE order_id=?")->execute([$orderId]);
        $ins = $db->prepare("INSERT INTO order_report_sel (order_id,opt_id,opt_name,process_type_id,set_by,set_at) VALUES (?,?,?,?,?,NOW())");
        if ($chk['none']) {
            $ins->execute([$orderId, 0, '否', null, $uid]);
        } else {
            $map = [];
            foreach (orp_options($db, false) as $o) $map[$o['opt_id']] = $o;
            foreach ($chk['ids'] as $id) {
                $o = $map[$id] ?? null;
                if ($o) $ins->execute([$orderId, $id, $o['opt_name'], $o['process_type_id'], $uid]);
            }
        }
    } catch (Throwable $e) { error_log('orp_apply: ' . $e->getMessage()); }
}

/** 儲存設定（依權限）。$rows＝[{opt_id?,opt_name,process_type_id,is_active}]（順序即排序）。
 *  新增需 add、改名/大類/啟用/排序需 edit、從清單移除需 del（且該項目未被任何訂單使用）。 */
function orp_save_defs(PDO $db, array $rows, string $uname, array $perm): array
{
    orp_ensure_schema($db);
    $cur = [];
    foreach ($db->query("SELECT * FROM order_report_opt")->fetchAll(PDO::FETCH_ASSOC) as $o) $cur[(int)$o['opt_id']] = $o;
    $clean = []; $seen = []; $keepIds = [];
    $okSuffix = array_column(orp_suffix_choices($db), 'suffix');
    foreach ($rows as $i => $r) {
        $name = trim((string)($r['opt_name'] ?? ''));
        if ($name === '') return ['ok' => false, 'msg' => '第 ' . ($i + 1) . ' 列：請輸入項目名稱'];
        if (mb_strlen($name) > 30) return ['ok' => false, 'msg' => '第 ' . ($i + 1) . ' 列：名稱不可超過 30 字'];
        if ($name === '否') return ['ok' => false, 'msg' => '「否」是固定選項，不需另外建立'];
        if (isset($seen[$name])) return ['ok' => false, 'msg' => '項目名稱重複：' . $name];
        $seen[$name] = 1;
        $pt = (int)($r['process_type_id'] ?? 0);
        if ($pt > 0) {   // 製程大類可不選（沒有對應大類的項目）
        $chk = $db->prepare("SELECT 1 FROM process_type WHERE process_type_id=?"); $chk->execute([$pt]);
        if (!$chk->fetchColumn()) return ['ok' => false, 'msg' => '第 ' . ($i + 1) . ' 列：製程大類不存在'];
        }
        $suffix = trim((string)($r['tag_suffix'] ?? ''));
        if ($suffix !== '' && !in_array($suffix, $okSuffix, true)) return ['ok' => false, 'msg' => '第 ' . ($i + 1) . ' 列（' . $name . '）：檔名後綴「' . $suffix . '」不在「ERP/資材報告 檔名標籤」的報告標籤內'];
        $id = (int)($r['opt_id'] ?? 0);
        $active = !empty($r['is_active']) ? 1 : 0;
        if ($id > 0) {
            if (!isset($cur[$id])) return ['ok' => false, 'msg' => '項目已不存在，請重新開啟設定'];
            $o = $cur[$id]; $keepIds[] = $id;
            $changed = $o['opt_name'] !== $name || (int)$o['process_type_id'] !== $pt || (int)$o['is_active'] !== $active || (int)$o['sort_order'] !== $i + 1 || (string)($o['tag_suffix'] ?? '') !== $suffix;
            if ($changed && empty($perm['edit'])) return ['ok' => false, 'msg' => '您沒有「修改」報告項目的權限'];
        } elseif (empty($perm['add'])) {
            return ['ok' => false, 'msg' => '您沒有「新增」報告項目的權限'];
        }
        $clean[] = ['id' => $id, 'name' => $name, 'pt' => $pt, 'suffix' => $suffix, 'active' => $active, 'sort' => $i + 1];
    }
    // 要刪除的（原有但這次沒送來）：需 del 權限，且不可已被訂單使用
    foreach ($cur as $id => $o) {
        if (in_array($id, $keepIds, true)) continue;
        if (empty($perm['del'])) return ['ok' => false, 'msg' => '您沒有「刪除」報告項目的權限'];
        $u = $db->prepare("SELECT COUNT(*) FROM order_report_sel WHERE opt_id=?"); $u->execute([$id]);
        if ((int)$u->fetchColumn() > 0) return ['ok' => false, 'msg' => '「' . $o['opt_name'] . '」已被訂單使用，不能刪除（可改為取消「啟用」）'];
    }
    $db->beginTransaction();
    try {
        foreach ($clean as $c) {
            if ($c['id'] > 0) {
                $db->prepare("UPDATE order_report_opt SET opt_name=?,process_type_id=?,tag_suffix=?,is_active=?,sort_order=? WHERE opt_id=?")
                   ->execute([$c['name'], $c['pt'] > 0 ? $c['pt'] : null, $c['suffix'] === '' ? null : $c['suffix'], $c['active'], $c['sort'], $c['id']]);
            } else {
                $db->prepare("INSERT INTO order_report_opt (opt_name,process_type_id,tag_suffix,is_active,sort_order,created_at,created_by) VALUES (?,?,?,?,?,NOW(),?)")
                   ->execute([$c['name'], $c['pt'] > 0 ? $c['pt'] : null, $c['suffix'] === '' ? null : $c['suffix'], $c['active'], $c['sort'], $uname]);
                $keepIds[] = (int)$db->lastInsertId();
            }
        }
        foreach (array_keys($cur) as $id) {
            if (!in_array($id, $keepIds, true)) $db->prepare("DELETE FROM order_report_opt WHERE opt_id=?")->execute([$id]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '儲存失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'msg' => ''];
}
