<?php
/**
 * 申請採購 API
 * 權限：purchase_lib.php purchase_perms()（roles module='purchase'），fail-closed
 * 流程：申請 → 採購詢價填實際金額 → 依總額判定簽核層級 → 核准 → 下單 → 到貨(入庫/直接交付/不列管) → 記帳 → 結案
 * 所有金額、統計、匯出一律後端對「全部符合條件的資料」算完才回傳（UI 規範：不可只算前端那一頁）
 * 寫入一律 transaction；附件只存檔名、路徑現場組（鐵律5）
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/purchase_lib.php';

function jout($a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    purchase_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = purchase_current_user($db);
if (!$u) jerr('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = purchase_perms($db, $u);
if (!$perms['canEnter']) jerr('無申請採購權限，請洽管理者指派角色', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ── 小工具 ───────────────────────────────────────── */
function pv($k, $d = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function pnum($k, $d = null) { $v = pv($k, ''); return $v === '' ? $d : (float)$v; }
function pint($k, $d = 0) { $v = pv($k, ''); return $v === '' ? $d : (int)$v; }
function pdate($k) { $v = pv($k, ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
function pjson($k) { $a = json_decode($_POST[$k] ?? '[]', true); return is_array($a) ? $a : []; }

function purchase_load_req(PDO $db, int $reqId): array {
    $st = $db->prepare("SELECT * FROM purchase_request WHERE req_id=? AND is_active=1");
    $st->execute([$reqId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('找不到採購單');
    return $r;
}

/** 依明細到貨狀況重算單據狀態 */
function purchase_refresh_status(PDO $db, int $reqId, int $userId): string {
    // 到貨完成與否比對「採購實際數量」（採購沒改就是申請數量）
    $st = $db->prepare("SELECT COUNT(*) total,
                        SUM(CASE WHEN qty_received >= COALESCE(buy_qty, qty_requested) THEN 1 ELSE 0 END) done,
                        COALESCE(SUM(qty_received),0) recv
                        FROM purchase_request_item WHERE req_id=?");
    $st->execute([$reqId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    $status = ((int)$s['total'] > 0 && (int)$s['done'] >= (int)$s['total']) ? 'received'
            : ((float)$s['recv'] > 0 ? 'partial' : 'ordered');
    $db->prepare("UPDATE purchase_request SET status=?, Modified_By=? WHERE req_id=?")->execute([$status, $userId, $reqId]);
    return $status;
}

try {
switch ($action) {

/* ============================================================
 * 共用基礎資料
 * ============================================================ */
case 'meta': {
    purchase_purge_expired_temp($db);   // 懶惰清除過期暫存附件
    [$l1, $l2] = purchase_thresholds($db);
    $cats  = $db->query("SELECT category_id, category_name, category_code, color FROM stock_item_categories
                         WHERE is_active=1 ORDER BY sort_order, category_id")->fetchAll(PDO::FETCH_ASSOC);
    $units = $db->query("SELECT unit_id, unit_name, unit_symbol FROM stock_units WHERE is_active=1
                         ORDER BY unit_id")->fetchAll(PDO::FETCH_ASSOC);
    $locs  = $db->query("SELECT location_id, location_code FROM stock_locations ORDER BY location_code")->fetchAll(PDO::FETCH_ASSOC);
    $tags  = $db->query("SELECT tag_id, tag_name, color FROM purchase_tag WHERE is_active=1
                         ORDER BY sort_order, tag_id")->fetchAll(PDO::FETCH_ASSOC);
    $resp = [
        'perms' => $perms, 'role_label' => purchase_role_names($db, $uid, $perms),
        'features' => PURCHASE_FEATURES,
        'me' => ['id' => $uid, 'name' => $uname, 'dept_id' => $u['dept_id'], 'dept_name' => $u['dept_name']],
        'today' => date('Y-m-d'),
        'categories' => $cats, 'units' => $units, 'locations' => $locs, 'tags' => $tags,
        'thresholds' => ['l1' => $l1, 'l2' => $l2],
        'tax_types' => PURCHASE_TAX_TYPES, 'receive_modes' => PURCHASE_RECEIVE_MODES,
        'statuses' => PURCHASE_STATUS, 'purpose_types' => PURCHASE_PURPOSE_TYPES,
        'print_header' => purchase_setting($db, 'purchase_print_header', '超正齒輪科技有限公司　採購申請單'),
        'print_footer' => purchase_setting($db, 'purchase_print_footer', ''),
    ];
    if ($perms['canAdmin']) {
        [$nas] = purchase_attach_dirs($db);
        $resp['attach_nas_dir'] = $nas;
        // 設定頁要看得到「現在到底寫到哪、寫不寫得進去」，不然填錯了要到上傳失敗才發現
        $resp['attach_nas_raw']      = purchase_setting($db, 'purchase_attach_nas_dir', '');
        $resp['attach_nas_writable'] = is_dir($nas) ? (bool)is_writable($nas) : false;
        $resp['attach_nas_exists']   = is_dir($nas);
    }
    jout($resp);
}

case 'save_settings': {
    if (!$perms['canAdmin']) jerr('無採購管理員權限', 403);
    $l1 = max(0, (float)pv('l1', '5000'));
    $l2 = max($l1, (float)pv('l2', '30000'));
    purchase_set_setting($db, 'purchase_appr_l1', (string)$l1);
    purchase_set_setting($db, 'purchase_appr_l2', (string)$l2);
    $nasIn = pv('nas_dir');
    if ($nasIn !== '') {
        // 擋掉把網址填進實體路徑欄的情況——存進去要等到有人上傳附件才會發現壞了
        if (preg_match('#^https?://#i', $nasIn)) jerr('「實體存放路徑」要填磁碟或網路資料夾路徑（例 Z:\BOM\ERP\採購 或 \\\\server\\share\\採購），不是網址');
        purchase_set_setting($db, 'purchase_attach_nas_dir', $nasIn);
    }
    if (isset($_POST['print_header'])) purchase_set_setting($db, 'purchase_print_header', pv('print_header'));
    if (isset($_POST['print_footer'])) purchase_set_setting($db, 'purchase_print_footer', pv('print_footer'));
    jout([]);
}

case 'search_vendor': {
    $kw = trim($_GET['kw'] ?? '');
    $st = $db->prepare("SELECT maker_id_no, maker_id, settlement_mode, payment_method, net_days
                        FROM maker_list WHERE status <> 'X' AND (maker_id LIKE ? OR maker_id_no LIKE ?)
                        ORDER BY maker_id LIMIT 30");
    $lk = '%' . $kw . '%';
    $st->execute([$lk, $lk]);
    jout(['vendors' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'search_user': {
    $kw = trim($_GET['kw'] ?? '');
    $st = $db->prepare("SELECT u.id, u.user_cname, COALESCE(d.name,'') dept_name
                        FROM user u
                        LEFT JOIN user_department_position_map m ON m.user_id=u.id AND m.is_main=1
                        LEFT JOIN department d ON d.id=m.department_id
                        WHERE u.state=1 AND u.user_cname LIKE ? ORDER BY u.user_cname LIMIT 30");
    $st->execute(['%' . $kw . '%']);
    jout(['users' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ============================================================
 * 採購品主檔：品牌清單（使用者 2026-07-30 指示）
 *   品牌 ≠ 購買廠商——品牌是「誰做的」，廠商是「跟誰買的」。
 *   **清單由採購維護**，其他模組（例如量測儀器校驗的量具料號對應）只能選、不能建。
 * ============================================================ */
case 'brand_list': {
    jout(['brands' => purchase_brand_list($db, ((int)($_GET['all'] ?? 0) === 1) ? false : true)]);
}
case 'brand_save': {
    if (!$perms['canBuy']) jerr('無維護採購品主檔的權限', 403);
    purchase_ensure_brand_vendor_schema($db);
    $bid  = pint('brand_id');
    $name = pv('brand_name');
    if ($name === '') jerr('請輸入品牌名稱');
    if (mb_strlen($name) > 60) jerr('品牌名稱最長 60 個字');
    $chk = $db->prepare("SELECT brand_id FROM purchase_brand WHERE brand_name=? AND brand_id<>? LIMIT 1");
    $chk->execute([$name, $bid]);
    if ($chk->fetchColumn()) jerr('品牌已存在：' . $name);
    $db->beginTransaction();
    if ($bid > 0) {
        $db->prepare("UPDATE purchase_brand SET brand_name=?, note=?, is_active=?, sort_order=? WHERE brand_id=?")
           ->execute([$name, pv('note') ?: null, pint('is_active', 1), pint('sort_order'), $bid]);
    } else {
        $so = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM purchase_brand")->fetchColumn();
        $db->prepare("INSERT INTO purchase_brand (brand_name, note, sort_order, Created_By) VALUES (?,?,?,?)")
           ->execute([$name, pv('note') ?: null, $so, $uid]);
        $bid = (int)$db->lastInsertId();
    }
    $db->commit();
    jout(['brand_id' => $bid, 'brands' => purchase_brand_list($db, false)]);
}
case 'brand_delete': {
    if (!$perms['canBuy']) jerr('無維護採購品主檔的權限', 403);
    $bid = pint('brand_id');
    $st = $db->prepare("SELECT brand_name FROM purchase_brand WHERE brand_id=?");
    $st->execute([$bid]);
    $name = $st->fetchColumn();
    if ($name === false) jerr('找不到品牌');
    // 已被規格用到就只停用，不真的刪掉（規格存的是名稱字串，刪了會查不到這個品牌是什麼）
    $st = $db->prepare("SELECT COUNT(*) FROM purchase_spec WHERE brand=?");
    $st->execute([$name]);
    $used = (int)$st->fetchColumn();
    if ($used > 0) {
        $db->prepare("UPDATE purchase_brand SET is_active=0 WHERE brand_id=?")->execute([$bid]);
        jout(['disabled' => 1, 'used' => $used, 'brands' => purchase_brand_list($db, false)]);
    }
    $db->prepare("DELETE FROM purchase_brand WHERE brand_id=?")->execute([$bid]);
    jout(['deleted' => 1, 'brands' => purchase_brand_list($db, false)]);
}

/* ---------- 規格的供應商清單（同規格可跟多家買） ---------- */
case 'spec_vendor_list': {
    jout(['vendors' => purchase_spec_vendors($db, (int)($_GET['spec_id'] ?? 0))]);
}
case 'spec_vendor_save': {
    if (!$perms['canBuy']) jerr('無維護採購品主檔的權限', 403);
    $specId = pint('spec_id');
    if ($specId <= 0) jerr('缺少規格');
    $chk = $db->prepare("SELECT COUNT(*) FROM purchase_spec WHERE spec_id=?");
    $chk->execute([$specId]);
    if (!(int)$chk->fetchColumn()) jerr('找不到規格');
    $vendors = pjson('vendors');
    if (!is_array($vendors)) jerr('資料格式錯誤');
    purchase_ensure_brand_vendor_schema($db);   // DDL 會隱含 commit → 一定要在交易外先做完
    $db->beginTransaction();
    $n = purchase_save_spec_vendors($db, $specId, $vendors, $uid);
    $db->commit();
    jout(['saved' => $n, 'vendors' => purchase_spec_vendors($db, $specId)]);
}

/* ============================================================
 * 採購品主檔：標籤 / 規格屬性
 * ============================================================ */
case 'tag_save': {
    if (!$perms['canAdmin']) jerr('無採購管理員權限', 403);
    $tagId = pint('tag_id');
    $name  = pv('tag_name');
    if ($name === '') jerr('請輸入標籤名稱');
    $color = pv('color', '#F7E0BD');
    if ($tagId > 0) {
        $db->prepare("UPDATE purchase_tag SET tag_name=?, color=?, sort_order=?, is_active=? WHERE tag_id=?")
           ->execute([$name, $color, pint('sort_order'), pint('is_active', 1), $tagId]);
    } else {
        $db->prepare("INSERT INTO purchase_tag (tag_name, color, sort_order) VALUES (?,?,?)")
           ->execute([$name, $color, pint('sort_order')]);
        $tagId = (int)$db->lastInsertId();
    }
    jout(['tag_id' => $tagId]);
}

case 'tag_delete': {
    if (!$perms['canAdmin']) jerr('無採購管理員權限', 403);
    $tagId = pint('tag_id');
    $db->beginTransaction();
    $db->prepare("DELETE FROM purchase_item_tag WHERE tag_id=?")->execute([$tagId]);
    $db->prepare("DELETE FROM purchase_tag WHERE tag_id=?")->execute([$tagId]);
    $db->commit();
    jout([]);
}

case 'attr_list': {
    $catId = (int)($_GET['category_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM purchase_attr WHERE category_id=? AND is_active=1 ORDER BY sort_order, attr_id");
    $st->execute([$catId]);
    jout(['attrs' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'attr_save': {
    if (!$perms['canAdmin']) jerr('無採購管理員權限', 403);
    $attrId = pint('attr_id');
    $name   = pv('attr_name');
    $catId  = pint('category_id');
    if ($name === '' || $catId <= 0) jerr('請選擇類別並輸入屬性名稱');
    $type = in_array(pv('attr_type', 'text'), ['text', 'number', 'select'], true) ? pv('attr_type', 'text') : 'text';
    if ($attrId > 0) {
        $db->prepare("UPDATE purchase_attr SET category_id=?, attr_name=?, attr_type=?, attr_options=?, attr_unit=?, sort_order=? WHERE attr_id=?")
           ->execute([$catId, $name, $type, pv('attr_options') ?: null, pv('attr_unit') ?: null, pint('sort_order'), $attrId]);
    } else {
        $db->prepare("INSERT INTO purchase_attr (category_id, attr_name, attr_type, attr_options, attr_unit, sort_order) VALUES (?,?,?,?,?,?)")
           ->execute([$catId, $name, $type, pv('attr_options') ?: null, pv('attr_unit') ?: null, pint('sort_order')]);
        $attrId = (int)$db->lastInsertId();
    }
    jout(['attr_id' => $attrId]);
}

case 'attr_delete': {
    if (!$perms['canAdmin']) jerr('無採購管理員權限', 403);
    $db->prepare("UPDATE purchase_attr SET is_active=0 WHERE attr_id=?")->execute([pint('attr_id')]);
    jout([]);
}

/* ============================================================
 * 採購品主檔：品項 / 規格變體
 * ============================================================ */
case 'item_list': {
    $kw    = trim($_GET['kw'] ?? '');
    $catId = (int)($_GET['category_id'] ?? 0);
    $tagId = (int)($_GET['tag_id'] ?? 0);
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $ps    = in_array((int)($_GET['page_size'] ?? 20), [5, 10, 20, 50], true) ? (int)$_GET['page_size'] : 20;
    $where = ['i.is_active=1']; $bind = [];
    if ($kw !== '') {
        // 打「鑽頭」找得到整個品項；打「鑽頭 5」也能命中規格
        $where[] = "(i.item_name LIKE ? OR i.item_code LIKE ? OR EXISTS
                     (SELECT 1 FROM purchase_spec s2 WHERE s2.item_id=i.item_id
                      AND (s2.spec_text LIKE ? OR s2.spec_code LIKE ?)))";
        $lk = '%' . $kw . '%'; array_push($bind, $lk, $lk, $lk, $lk);
    }
    if ($catId > 0) { $where[] = 'i.category_id=?'; $bind[] = $catId; }
    if ($tagId > 0) { $where[] = 'EXISTS (SELECT 1 FROM purchase_item_tag t WHERE t.item_id=i.item_id AND t.tag_id=?)'; $bind[] = $tagId; }
    $w = implode(' AND ', $where);

    $cst = $db->prepare("SELECT COUNT(*) FROM purchase_item i WHERE $w");
    $cst->execute($bind);
    $total = (int)$cst->fetchColumn();

    $off = ($page - 1) * $ps;
    $st = $db->prepare("SELECT i.*, COALESCE(c.category_name,'') category_name,
                        (SELECT COUNT(*) FROM purchase_spec s WHERE s.item_id=i.item_id AND s.is_active=1) spec_cnt,
                        (SELECT COALESCE(SUM(si.qty),0) FROM stock_items si
                          JOIN purchase_spec s3 ON s3.spec_id=si.purchase_spec_id
                          WHERE s3.item_id=i.item_id AND si.is_active=1) stock_qty
                        FROM purchase_item i
                        LEFT JOIN stock_item_categories c ON c.category_id=i.category_id
                        WHERE $w ORDER BY i.category_id, i.item_name LIMIT $ps OFFSET $off");
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['item_id'], $rows));
        $tg = $db->query("SELECT it.item_id, t.tag_id, t.tag_name, t.color FROM purchase_item_tag it
                          JOIN purchase_tag t ON t.tag_id=it.tag_id WHERE it.item_id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($tg as $t) $map[(int)$t['item_id']][] = $t;
        foreach ($rows as &$r) $r['tags'] = $map[(int)$r['item_id']] ?? [];
        unset($r);
    }
    jout(['rows' => $rows, 'total' => $total, 'pages' => (int)ceil($total / $ps), 'page' => $page]);
}

case 'item_detail': {
    $itemId = (int)($_GET['item_id'] ?? 0);
    $st = $db->prepare("SELECT i.*, COALESCE(c.category_name,'') category_name FROM purchase_item i
                        LEFT JOIN stock_item_categories c ON c.category_id=i.category_id WHERE i.item_id=?");
    $st->execute([$itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) jerr('找不到品項');
    $st = $db->prepare("SELECT s.*, COALESCE(l.location_code,'') location_code,
                        (SELECT COALESCE(SUM(si.qty),0) FROM stock_items si
                          WHERE si.purchase_spec_id=s.spec_id AND si.is_active=1) stock_qty
                        FROM purchase_spec s LEFT JOIN stock_locations l ON l.location_id=s.location_id
                        WHERE s.item_id=? AND s.is_active=1 ORDER BY s.spec_code");
    $st->execute([$itemId]);
    $item['specs'] = $st->fetchAll(PDO::FETCH_ASSOC);
    // 每個規格帶上供應商清單（同規格可跟多家買）；品牌已在 s.* 裡（purchase_spec.brand）
    $vmap = purchase_spec_vendors_map($db, array_map(fn($s) => (int)$s['spec_id'], $item['specs']));
    foreach ($item['specs'] as &$sp) { $sp['vendors'] = $vmap[(int)$sp['spec_id']] ?? []; }
    unset($sp);
    $item['brands'] = purchase_brand_list($db);
    $st = $db->prepare("SELECT tag_id FROM purchase_item_tag WHERE item_id=?");
    $st->execute([$itemId]);
    $item['tag_ids'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $db->prepare("SELECT * FROM purchase_attr WHERE category_id=? AND is_active=1 ORDER BY sort_order, attr_id");
    $st->execute([(int)$item['category_id']]);
    $item['attrs'] = $st->fetchAll(PDO::FETCH_ASSOC);
    jout(['item' => $item]);
}

/** 打字即時防重複：找出相似品項 */
case 'item_check_dup': {
    $name  = trim($_GET['item_name'] ?? '');
    $catId = (int)($_GET['category_id'] ?? 0);
    if (mb_strlen($name) < 1) jout(['similar' => []]);
    $bind = ['%' . $name . '%'];
    $sql  = "SELECT i.item_id, i.item_code, i.item_name, COALESCE(c.category_name,'') category_name,
             (SELECT COUNT(*) FROM purchase_spec s WHERE s.item_id=i.item_id AND s.is_active=1) spec_cnt
             FROM purchase_item i LEFT JOIN stock_item_categories c ON c.category_id=i.category_id
             WHERE i.is_active=1 AND i.item_name LIKE ?";
    if ($catId > 0) { $sql .= " AND i.category_id=?"; $bind[] = $catId; }
    $sql .= " ORDER BY i.item_name LIMIT 8";
    $st = $db->prepare($sql);
    $st->execute($bind);
    jout(['similar' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'item_save': {
    if (!$perms['canBuy']) jerr('無維護採購品主檔的權限', 403);
    $itemId = pint('item_id');
    $name   = pv('item_name');
    $catId  = pint('category_id');
    if ($name === '' || $catId <= 0) jerr('請選擇類別並輸入品項名稱');
    $tagIds = array_map('intval', pjson('tag_ids'));
    $db->beginTransaction();
    if ($itemId > 0) {
        $db->prepare("UPDATE purchase_item SET category_id=?, item_name=?, default_unit_id=?, default_vendor_id=?,
                      default_vendor_name=?, note=?, is_active=?, Modified_By=? WHERE item_id=?")
           ->execute([$catId, $name, pint('default_unit_id') ?: null, pv('default_vendor_id') ?: null,
                      pv('default_vendor_name') ?: null, pv('note') ?: null, pint('is_active', 1), $uid, $itemId]);
    } else {
        $code = purchase_next_item_code($db, $catId);
        $db->prepare("INSERT INTO purchase_item (item_code, category_id, item_name, default_unit_id,
                      default_vendor_id, default_vendor_name, note, Created_By) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$code, $catId, $name, pint('default_unit_id') ?: null, pv('default_vendor_id') ?: null,
                      pv('default_vendor_name') ?: null, pv('note') ?: null, $uid]);
        $itemId = (int)$db->lastInsertId();
    }
    $db->prepare("DELETE FROM purchase_item_tag WHERE item_id=?")->execute([$itemId]);
    if ($tagIds) {
        $ins = $db->prepare("INSERT IGNORE INTO purchase_item_tag (item_id, tag_id) VALUES (?,?)");
        foreach ($tagIds as $t) if ($t > 0) $ins->execute([$itemId, $t]);
    }
    $db->commit();
    $st = $db->prepare("SELECT item_code FROM purchase_item WHERE item_id=?");
    $st->execute([$itemId]);
    jout(['item_id' => $itemId, 'item_code' => $st->fetchColumn()]);
}

case 'item_delete': {
    if (!$perms['canAdmin']) jerr('無採購管理員權限', 403);
    $itemId = pint('item_id');
    $st = $db->prepare("SELECT COALESCE(SUM(si.qty),0) FROM stock_items si
                        JOIN purchase_spec s ON s.spec_id=si.purchase_spec_id
                        WHERE s.item_id=? AND si.is_active=1");
    $st->execute([$itemId]);
    if ((float)$st->fetchColumn() > 0) jerr('此品項尚有庫存，不可停用');
    $db->beginTransaction();
    $db->prepare("UPDATE purchase_item SET is_active=0, Modified_By=? WHERE item_id=?")->execute([$uid, $itemId]);
    $db->prepare("UPDATE purchase_spec SET is_active=0, Modified_By=? WHERE item_id=?")->execute([$uid, $itemId]);
    $db->commit();
    jout([]);
}

case 'spec_save': {
    if (!$perms['canBuy']) jerr('無維護採購品主檔的權限', 403);
    $specId = pint('spec_id');
    $itemId = pint('item_id');
    if ($itemId <= 0) jerr('缺少品項');
    $st = $db->prepare("SELECT category_id FROM purchase_item WHERE item_id=?");
    $st->execute([$itemId]);
    $catId = (int)$st->fetchColumn();
    $attrVals = pjson('attr_vals');
    $specText = pv('spec_text');
    if ($specText === '') $specText = purchase_build_spec_text($db, $catId, $attrVals);
    if ($specText === '') jerr('請至少填一項規格（或直接輸入規格說明）');
    $attrJson = $attrVals ? json_encode($attrVals, JSON_UNESCAPED_UNICODE) : null;
    // 採購料號可自訂（留白＝沿用/自動編號）；spec_code 有 UNIQUE，重複一律擋在前面說清楚是誰用掉了
    $code = pv('spec_code');
    if ($code !== '') {
        if (mb_strlen($code) > 40) jerr('採購料號最長 40 個字');
        $chk = $db->prepare("SELECT s.spec_id, s.spec_text, i.item_name FROM purchase_spec s
                             JOIN purchase_item i ON i.item_id=s.item_id WHERE s.spec_code=? LIMIT 1");
        $chk->execute([$code]);
        $dupC = $chk->fetch(PDO::FETCH_ASSOC);
        if ($dupC && (int)$dupC['spec_id'] !== $specId) {
            jerr('採購料號 ' . $code . ' 已經被「' . $dupC['item_name'] . '／' . $dupC['spec_text'] . '」用掉了，請換一個');
        }
    }
    // 品牌：與購買廠商是兩件事；清單由採購維護，但這裡允許直接打清單外的品牌
    $brand = mb_substr(pv('brand'), 0, 60);
    purchase_ensure_brand_vendor_schema($db);   // DDL 會隱含 commit → 一定要在交易外先做完
    $db->beginTransaction();
    if ($specId > 0) {
        $db->prepare("UPDATE purchase_spec SET spec_code=COALESCE(NULLIF(?,''), spec_code), spec_text=?, attr_json=?,
                      unit_id=?, location_id=?, safety_qty=?, brand=?, is_active=?, Modified_By=? WHERE spec_id=?")
           ->execute([$code, $specText, $attrJson, pint('unit_id') ?: null, pint('location_id') ?: null,
                      pnum('safety_qty'), $brand ?: null, pint('is_active', 1), $uid, $specId]);
    } else {
        // 同品項同規格擋重複（品牌不同就算不同料號，所以一起比）
        $chk = $db->prepare("SELECT spec_code FROM purchase_spec
                             WHERE item_id=? AND spec_text=? AND COALESCE(brand,'')=? AND is_active=1 LIMIT 1");
        $chk->execute([$itemId, $specText, $brand]);
        if ($dup = $chk->fetchColumn()) { $db->rollBack(); jerr('此規格已存在：' . $dup); }
        if ($code === '') $code = purchase_next_spec_code($db, $itemId);
        $db->prepare("INSERT INTO purchase_spec (item_id, spec_code, spec_text, attr_json, unit_id, location_id, safety_qty, brand, Created_By)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$itemId, $code, $specText, $attrJson, pint('unit_id') ?: null,
                      pint('location_id') ?: null, pnum('safety_qty'), $brand ?: null, $uid]);
        $specId = (int)$db->lastInsertId();
    }
    // 供應商清單（有送才處理；沒送就不動既有資料）
    if (isset($_POST['vendors'])) {
        $vs = pjson('vendors');
        if (is_array($vs)) purchase_save_spec_vendors($db, $specId, $vs, $uid);
    }
    $db->commit();
    $st = $db->prepare("SELECT spec_code FROM purchase_spec WHERE spec_id=?");
    $st->execute([$specId]);
    jout(['spec_id' => $specId, 'spec_code' => $st->fetchColumn()]);
}

case 'spec_delete': {
    if (!$perms['canAdmin']) jerr('無採購管理員權限', 403);
    $specId = pint('spec_id');
    $st = $db->prepare("SELECT COALESCE(SUM(qty),0) FROM stock_items WHERE purchase_spec_id=? AND is_active=1");
    $st->execute([$specId]);
    if ((float)$st->fetchColumn() > 0) jerr('此規格尚有庫存，不可停用');
    $db->prepare("UPDATE purchase_spec SET is_active=0, Modified_By=? WHERE spec_id=?")->execute([$uid, $specId]);
    jout([]);
}

/** 申請單挑料號：以規格變體為單位回傳（含目前庫存、最近採購價） */
case 'spec_search': {
    $kw    = trim($_GET['kw'] ?? '');
    $catId = (int)($_GET['category_id'] ?? 0);
    $tagId = (int)($_GET['tag_id'] ?? 0);
    $where = ['s.is_active=1', 'i.is_active=1']; $bind = [];
    if ($kw !== '') {
        // 逐字拆開 AND 比對，讓「鑽頭 5」找得到 鑽頭 Ø5
        foreach (preg_split('/\s+/', $kw) as $w) {
            if ($w === '') continue;
            $where[] = "(i.item_name LIKE ? OR i.item_code LIKE ? OR s.spec_text LIKE ? OR s.spec_code LIKE ?)";
            $lk = '%' . $w . '%'; array_push($bind, $lk, $lk, $lk, $lk);
        }
    }
    if ($catId > 0) { $where[] = 'i.category_id=?'; $bind[] = $catId; }
    if ($tagId > 0) { $where[] = 'EXISTS (SELECT 1 FROM purchase_item_tag t WHERE t.item_id=i.item_id AND t.tag_id=?)'; $bind[] = $tagId; }
    $st = $db->prepare("SELECT s.spec_id, s.spec_code, s.spec_text, s.unit_id, s.location_id, s.safety_qty,
                        s.last_price, s.last_vendor_id, s.last_vendor_name, s.last_buy_date,
                        i.item_id, i.item_name, i.category_id, i.default_unit_id,
                        i.default_vendor_id, i.default_vendor_name,
                        COALESCE(c.category_name,'') category_name,
                        COALESCE(un.unit_symbol, un.unit_name, '') unit_label,
                        (SELECT COALESCE(SUM(si.qty),0) FROM stock_items si
                          WHERE si.purchase_spec_id=s.spec_id AND si.is_active=1) stock_qty
                        FROM purchase_spec s
                        JOIN purchase_item i ON i.item_id=s.item_id
                        LEFT JOIN stock_item_categories c ON c.category_id=i.category_id
                        LEFT JOIN stock_units un ON un.unit_id=COALESCE(s.unit_id, i.default_unit_id)
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY i.item_name, s.spec_code LIMIT 200");
    $st->execute($bind);
    jout(['specs' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/**
 * 依類別找既有品項（給「建立新採購料號」時選「掛在既有品項」用）
 * 一併帶回該品項現有的規格，讓採購看得出「要買的這支是不是已經有料號了」
 */
case 'item_search': {
    $catId = (int)($_GET['category_id'] ?? 0);
    $kw    = trim($_GET['kw'] ?? '');
    $where = ['i.is_active=1']; $bind = [];
    if ($catId > 0) { $where[] = 'i.category_id=?'; $bind[] = $catId; }
    if ($kw !== '') { $where[] = '(i.item_name LIKE ? OR i.item_code LIKE ?)'; $lk = '%' . $kw . '%'; array_push($bind, $lk, $lk); }
    $st = $db->prepare("SELECT i.item_id, i.item_code, i.item_name, i.category_id, i.default_unit_id,
                        COALESCE(c.category_name,'') category_name
                        FROM purchase_item i
                        LEFT JOIN stock_item_categories c ON c.category_id=i.category_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY i.item_name LIMIT 200");
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['item_id'], $rows));
        $sp = $db->query("SELECT item_id, spec_id, spec_code, spec_text FROM purchase_spec
                          WHERE item_id IN ($ids) AND is_active=1 ORDER BY spec_code")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($sp as $s) $map[(int)$s['item_id']][] = $s;
        foreach ($rows as &$r) $r['specs'] = $map[(int)$r['item_id']] ?? [];
        unset($r);
    }
    jout(['items' => $rows]);
}

/**
 * 料號編碼建議值（新增前先讓採購看到會拿到什麼號碼，也可以自己改）
 * 只是建議：真正的唯一性在存檔時才判定（spec_code 有 UNIQUE）
 */
case 'code_preview': {
    $itemId = (int)($_GET['item_id'] ?? 0);
    $catId  = (int)($_GET['category_id'] ?? 0);
    if ($itemId > 0) {
        $st = $db->prepare("SELECT item_code FROM purchase_item WHERE item_id=?");
        $st->execute([$itemId]);
        $itemCode = (string)($st->fetchColumn() ?: '');
        jout(['item_code' => $itemCode, 'spec_code' => purchase_next_spec_code($db, $itemId), 'is_new_item' => 0]);
    }
    if ($catId <= 0) jerr('請選擇類別');
    $itemCode = purchase_next_item_code($db, $catId);
    jout(['item_code' => $itemCode, 'spec_code' => $itemCode . '-01', 'is_new_item' => 1]);
}

/** 目前實際的角色×功能對照（給「角色權限說明」用；角色可改名，寫死的說明會失真） */
case 'role_matrix': {
    $st = $db->query("SELECT r.role_id, r.role_name, r.is_system,
                      GROUP_CONCAT(rf.feature_code) codes
                      FROM roles r
                      LEFT JOIN role_features rf ON rf.role_id=r.role_id
                      WHERE r.module='purchase' OR r.is_system=1
                      GROUP BY r.role_id, r.role_name, r.is_system
                      ORDER BY r.is_system DESC, r.role_id");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'role_id'   => (int)$r['role_id'],
            'role_name' => $r['role_name'],
            'is_system' => (int)$r['is_system'],
            'codes'     => $r['codes'] === null ? [] : array_values(array_unique(explode(',', $r['codes']))),
        ];
    }
    jout(['roles' => $out, 'features' => PURCHASE_FEATURES]);
}

/** 用途歸屬的選擇器：訂單／BOM／料號（一律回主鍵，前端只拿去顯示） */
case 'purpose_search': {
    $type = strtoupper(trim($_GET['type'] ?? ''));
    $kw   = trim($_GET['kw'] ?? '');
    if ($kw === '') jout(['rows' => []]);
    $rows = [];

    if ($type === 'ORDER') {
        // 一個訂單號最多對到 25 列料號，故一律列到「列」，讓使用者指定 Order_id
        $st = $db->prepare("SELECT o.Order_id, o.Order_oo, o.d_id, o.Client_name, o.Qty,
                            o.Order_date, o.Delivery_date
                            FROM order_track o
                            WHERE o.Order_oo LIKE ? OR o.d_id LIKE ? OR o.Client_name LIKE ?
                            ORDER BY o.Order_oo DESC, o.Order_id LIMIT 100");
        $lk = '%' . $kw . '%';
        $st->execute([$lk, $lk, $lk]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'id'    => (int)$r['Order_id'],
                'main'  => $r['Order_oo'],
                'sub'   => $r['d_id'],
                'extra' => trim((string)$r['Client_name']) . '　數量 ' . rtrim(rtrim((string)$r['Qty'], '0'), '.')
                         . ($r['Delivery_date'] ? '　交期 ' . $r['Delivery_date'] : ''),
                'label' => $r['Order_oo'] . ' / ' . $r['d_id']
                         . ($r['Client_name'] !== null && $r['Client_name'] !== '' ? '（' . $r['Client_name'] . '）' : ''),
            ];
        }
    } elseif ($type === 'BOM') {
        $st = $db->prepare("SELECT b.bom, b.d_id, b.Client_Name, b.sqty
                            FROM bom b
                            WHERE b.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ?
                            ORDER BY b.bom DESC LIMIT 100");
        $lk = '%' . $kw . '%';
        $st->execute([$lk, $lk, $lk]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'id'    => $r['bom'],
                'main'  => $r['bom'],
                'sub'   => $r['d_id'],
                'extra' => trim((string)$r['Client_Name']) . ($r['sqty'] !== null ? '　數量 ' . $r['sqty'] : ''),
                'label' => $r['bom'] . ' / ' . $r['d_id']
                         . ($r['Client_Name'] !== null && $r['Client_Name'] !== '' ? '（' . $r['Client_Name'] . '）' : ''),
            ];
        }
    } elseif ($type === 'PART') {
        // 料號字串有重複，一律回 d_setting.d_id 主鍵
        $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Customer_Id
                            FROM d_setting d
                            WHERE d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ?
                            ORDER BY d.D_Setting_Id LIMIT 100");
        $lk = '%' . $kw . '%';
        $st->execute([$lk, $lk]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'id'    => (int)$r['d_id'],
                'main'  => $r['D_Setting_Id'],
                'sub'   => (string)$r['Drawing_No'],
                'extra' => (string)$r['Customer_Id'],
                'label' => (string)$r['D_Setting_Id'],
            ];
        }
    } else {
        jerr('用途類別不需要選擇對象');
    }
    jout(['rows' => $rows]);
}

/* ============================================================
 * 申請單
 * ============================================================ */
case 'req_list':
case 'req_export': {
    purchase_purge_expired_temp($db);
    $isExport = ($action === 'req_export');
    $scope  = $_GET['scope'] ?? 'mine';
    $status = trim($_GET['status'] ?? '');
    $kw     = trim($_GET['kw'] ?? '');
    $df     = trim($_GET['date_from'] ?? '');
    $dt     = trim($_GET['date_to'] ?? '');
    $pay    = trim($_GET['pay_status'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $ps     = in_array((int)($_GET['page_size'] ?? 10), [5, 10, 20, 50], true) ? (int)$_GET['page_size'] : 10;

    $where = ['r.is_active=1']; $bind = [];
    if ($scope === 'mine')       { $where[] = 'r.requester_id=?'; $bind[] = $uid; }
    elseif ($scope === 'buy')    { if (!$perms['canBuy']) jerr('無採購作業權限', 403);
                                   $where[] = "r.status IN ('submitted','approved','ordered','partial')"; }
    elseif ($scope === 'sign')   { $where[] = "r.status='quoted'"; }
    elseif ($scope === 'unpaid') { if (!$perms['canBuy']) jerr('無採購作業權限', 403);
                                   $where[] = "r.pay_status='unpaid' AND r.status IN ('ordered','partial','received','closed')"; }
    elseif ($scope === 'all')    { if (!$perms['canView']) jerr('無「檢閱全部單據」權限', 403); }
    // 認不出來的 scope 一律退回只看自己的（fail-closed，不可變成看全部）
    else                         { $where[] = 'r.requester_id=?'; $bind[] = $uid; }
    if ($status !== '' && isset(PURCHASE_STATUS[$status])) { $where[] = 'r.status=?'; $bind[] = $status; }
    if ($pay !== '')  { $where[] = 'r.pay_status=?'; $bind[] = $pay; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) { $where[] = 'DATE(r.Created_At)>=?'; $bind[] = $df; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) { $where[] = 'DATE(r.Created_At)<=?'; $bind[] = $dt; }
    if ($kw !== '') {
        $where[] = "(r.req_no LIKE ? OR r.title LIKE ? OR r.requester_name LIKE ? OR r.dept_name LIKE ?
                     OR r.vendor_name LIKE ? OR r.invoice_no LIKE ? OR r.purpose_label LIKE ?
                     OR EXISTS (SELECT 1 FROM purchase_request_item pi WHERE pi.req_id=r.req_id
                                AND (pi.item_name LIKE ? OR pi.spec_text LIKE ? OR pi.purpose_label LIKE ?)))";
        $lk = '%' . $kw . '%';
        array_push($bind, $lk, $lk, $lk, $lk, $lk, $lk, $lk, $lk, $lk, $lk);
    }
    $w = implode(' AND ', $where);

    // 全量統計（規範：總計一律後端對全部符合條件的資料算）
    $sst = $db->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(r.grand_total),0) sum_total,
                         COALESCE(SUM(CASE WHEN r.pay_status='unpaid' THEN r.grand_total ELSE 0 END),0) sum_unpaid
                         FROM purchase_request r WHERE $w");
    $sst->execute($bind);
    $stats = $sst->fetch(PDO::FETCH_ASSOC);

    $sql = "SELECT r.*, (SELECT COUNT(*) FROM purchase_request_item pi WHERE pi.req_id=r.req_id) item_cnt,
            (SELECT SUM(pi.is_urgent) FROM purchase_request_item pi WHERE pi.req_id=r.req_id) urgent_cnt
            FROM purchase_request r WHERE $w ORDER BY r.Created_At DESC";

    // 待簽核分頁：簽核關卡要逐單解析，無法寫進 SQL —— 先撈全部、過濾完再自行分頁，
    // 否則「先分頁再過濾」會讓每頁筆數忽多忽少、總計也算錯
    if ($scope === 'sign') {
        $st = $db->prepare($sql);
        $st->execute($bind);
        $all = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC),
                    fn($r) => purchase_can_sign($db, $r, $uid, $perms)));
        $total = count($all);
        $stats = ['cnt' => $total,
                  'sum_total' => array_sum(array_map(fn($r) => (float)$r['grand_total'], $all)),
                  'sum_unpaid' => 0];
        $rows = $isExport ? $all : array_slice($all, ($page - 1) * $ps, $ps);
    } elseif ($isExport) {
        $st = $db->prepare($sql);
        $st->execute($bind);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $total = count($rows);
    } else {
        $cst = $db->prepare("SELECT COUNT(*) FROM purchase_request r WHERE $w");
        $cst->execute($bind);
        $total = (int)$cst->fetchColumn();
        $off = ($page - 1) * $ps;
        $st = $db->prepare($sql . " LIMIT $ps OFFSET $off");
        $st->execute($bind);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    // 可視內容遮蔽：一律後端挖掉，前端隱藏擋不住直接看 API 回應的人
    $rows = array_map(function ($r) use ($db, $perms, $uid) {
        return purchase_mask_row($r, $perms, $uid, purchase_can_sign($db, $r, $uid, $perms));
    }, $rows);
    if (!$perms['canViewAmount']) {
        // 統計卡的金額也要一起蓋掉，否則從總額還是推得出來
        $stats['sum_total'] = null; $stats['sum_unpaid'] = null; $stats['masked_amount'] = 1;
    }
    jout($isExport
        ? ['rows' => $rows, 'stats' => $stats]
        : ['rows' => $rows, 'stats' => $stats, 'total' => $total,
           'pages' => (int)max(1, ceil($total / $ps)), 'page' => $page]);
}

/** 各分頁待辦數（badge） */
case 'req_badges': {
    $out = ['mine' => 0, 'buy' => 0, 'sign' => 0, 'unpaid' => 0];
    $st = $db->prepare("SELECT COUNT(*) FROM purchase_request WHERE is_active=1 AND requester_id=? AND status NOT IN ('closed','rejected','canceled')");
    $st->execute([$uid]);
    $out['mine'] = (int)$st->fetchColumn();
    if ($perms['canBuy']) {
        $out['buy'] = (int)$db->query("SELECT COUNT(*) FROM purchase_request WHERE is_active=1
                                       AND status IN ('submitted','approved','ordered','partial')")->fetchColumn();
        $out['unpaid'] = (int)$db->query("SELECT COUNT(*) FROM purchase_request WHERE is_active=1
                                          AND pay_status='unpaid' AND status IN ('ordered','partial','received','closed')")->fetchColumn();
    }
    $q = $db->query("SELECT * FROM purchase_request WHERE is_active=1 AND status='quoted'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($q as $r) if (purchase_can_sign($db, $r, $uid, $perms)) $out['sign']++;
    jout(['badges' => $out]);
}

case 'req_detail': {
    $reqId = (int)($_GET['req_id'] ?? 0);
    $req = purchase_load_req($db, $reqId);
    $st = $db->prepare("SELECT pi.*, COALESCE(un.unit_symbol, un.unit_name, '') unit_label,
                        COALESCE(bun.unit_symbol, bun.unit_name, '') buy_unit_label,
                        COALESCE(l.location_code,'') location_code,
                        s.spec_code, bs.spec_code buy_spec_code
                        FROM purchase_request_item pi
                        LEFT JOIN stock_units un ON un.unit_id=pi.unit_id
                        LEFT JOIN stock_units bun ON bun.unit_id=pi.buy_unit_id
                        LEFT JOIN stock_locations l ON l.location_id=pi.location_id
                        LEFT JOIN purchase_spec s ON s.spec_id=pi.spec_id
                        LEFT JOIN purchase_spec bs ON bs.spec_id=pi.buy_spec_id
                        WHERE pi.req_id=? ORDER BY pi.sort_order, pi.pr_item_id");
    $st->execute([$reqId]);
    $req['items'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT rc.*, COALESCE(l.location_code,'') location_code, u.user_cname created_name
                        FROM purchase_receipt rc
                        LEFT JOIN stock_locations l ON l.location_id=rc.location_id
                        LEFT JOIN user u ON u.id=rc.Created_By
                        WHERE rc.req_id=? ORDER BY rc.rcpt_id");
    $st->execute([$reqId]);
    $req['receipts'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT att_id, att_type, file_name, original_name, file_size, Created_At
                        FROM purchase_attachment WHERE req_id=? AND status='active' ORDER BY att_id");
    $st->execute([$reqId]);
    $atts = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($atts as &$a) $a['url'] = purchase_att_url($db, $a + ['status' => 'active'], $req['req_no']);
    unset($a);
    $req['attachments'] = $atts;

    $st = $db->prepare("SELECT * FROM approval_record WHERE module='purchase' AND entity_id=? ORDER BY id");
    $st->execute([$reqId]);
    $req['approvals'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $isOwner = ((int)$req['requester_id'] === $uid);
    $req['can'] = [
        // 申請內容只有申請人本人能改（且限尚未進入採購流程）。採購人員一律不得修改申請單，
        // 他們買到什麼寫在 buy_* 那一層（詢價頁）。系統管理者保留更正權限。
        'edit'    => ($req['status'] === 'submitted' && $isOwner) || $perms['isAdmin'],
        'quote'   => $perms['canBuy'] && in_array($req['status'], ['submitted', 'rejected'], true),
        'sign'    => purchase_can_sign($db, $req, $uid, $perms),
        'order'   => $perms['canBuy'] && $req['status'] === 'approved',
        'receive' => $perms['canReceive'] && in_array($req['status'], ['ordered', 'partial'], true),
        'account' => $perms['canBuy'] && in_array($req['status'], ['ordered', 'partial', 'received', 'closed'], true),
        'close'   => $perms['canBuy'] && in_array($req['status'], ['received', 'partial'], true),
        'delete'  => $perms['canAdmin'] || ($isOwner && $req['status'] === 'submitted'),
    ];
    // 只有申請權的人不該看得到別人的單——列表擋住了，這裡也要擋，
    // 否則換個 req_id 就把別人的單撈出來了
    if (!$isOwner && !$perms['canView'] && !$req['can']['sign']) jerr('無權檢視此單據', 403);
    if ($req['status'] === 'quoted') {
        $lv = (int)$req['level_done'] + 1;
        $signers = purchase_level_signers($db, $req, $lv);
        if ($signers) {
            $in = implode(',', array_fill(0, count($signers), '?'));
            $st = $db->prepare("SELECT user_cname FROM user WHERE id IN ($in)");
            $st->execute($signers);
            $req['pending_signers'] = implode('、', $st->fetchAll(PDO::FETCH_COLUMN));
            $req['pending_level']   = $lv;
        }
    }
    // 可視內容遮蔽：沒勾「看得到金額／廠商」就挖掉（自己的單、輪到自己簽的單例外）
    $req = purchase_mask_row($req, $perms, $uid, (bool)$req['can']['sign']);
    jout(['req' => $req]);
}

/** 新增／修改申請單（申請人填，金額可留白） */
case 'req_save': {
    if (!$perms['canApply']) jerr('無申請採購權限', 403);
    $reqId = pint('req_id');
    $items = pjson('items');
    if (!$items) jerr('請至少填一筆品項');
    $tempAtts = array_map('intval', pjson('temp_att_ids'));
    $urgent   = pint('is_urgent') ? 1 : 0;

    // 用途歸屬：單頭必填；ID 與顯示名稱都在後端驗過／重建，不採信前端字串
    $pp = purchase_purpose_normalize($db, $_POST);
    if ($pp['type'] === null) jerr('請選擇這筆採購的用途（成本要靠它歸戶）');

    // 品項的用途留白＝沿用單頭；有填才逐列驗
    $itemPurposes = [];
    foreach ($items as $i => $it) {
        $itemPurposes[$i] = (trim((string)($it['purpose_type'] ?? '')) === '')
            ? null : purchase_purpose_normalize($db, $it);
    }

    // 標題留白時自動組（申請人不必想標題）：〈用途〉- 第一項 等N項
    $title = pv('title');
    if ($title === '') {
        $first = '';
        foreach ($items as $it) {
            $n = trim((string)($it['item_name'] ?? ''));
            if ($n !== '') { $first = $n; break; }
        }
        $cnt   = count(array_filter($items, fn($it) => trim((string)($it['item_name'] ?? '')) !== ''));
        $title = mb_substr((string)$pp['label'], 0, 40) . ' - ' . mb_substr($first, 0, 30)
               . ($cnt > 1 ? ' 等 ' . $cnt . ' 項' : '');
        $title = mb_substr($title, 0, 120);
    }

    $db->beginTransaction();
    if ($reqId > 0) {
        $req = purchase_load_req($db, $reqId);
        $isOwner = ((int)$req['requester_id'] === $uid);
        // 採購人員不得改申請內容（連 canBuy 都不行）；他們的資料寫在 buy_* 那一層
        if (!($perms['isAdmin'] || ($isOwner && $req['status'] === 'submitted'))) {
            $db->rollBack();
            jerr($isOwner ? '此單已進入採購流程，不可再修改申請內容'
                          : '只有申請人本人可以修改申請內容；採購請在「詢價／填入實際金額」頁登錄實際採購內容', 403);
        }
        $db->prepare("UPDATE purchase_request SET title=?, need_date=?, reason=?,
                      purpose_type=?, purpose_order_id=?, purpose_bom=?, purpose_d_id=?, purpose_note=?, purpose_label=?,
                      is_urgent=?, Modified_By=? WHERE req_id=?")
           ->execute([$title, pdate('need_date'), pv('reason') ?: null,
                      $pp['type'], $pp['order_id'], $pp['bom'], $pp['d_id'], $pp['note'], $pp['label'],
                      $urgent, $uid, $reqId]);
        $db->prepare("DELETE FROM purchase_request_item WHERE req_id=? AND qty_received=0")->execute([$reqId]);
        $reqNo = $req['req_no'];
    } else {
        $reqNo = purchase_next_req_no($db);
        $db->prepare("INSERT INTO purchase_request (req_no, title, dept_id, dept_name, requester_id, requester_name,
                      need_date, reason, purpose_type, purpose_order_id, purpose_bom, purpose_d_id, purpose_note,
                      purpose_label, is_urgent, status, Created_By)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'submitted', ?)")
           ->execute([$reqNo, $title, $u['dept_id'], $u['dept_name'], $uid, $uname,
                      pdate('need_date'), pv('reason') ?: null,
                      $pp['type'], $pp['order_id'], $pp['bom'], $pp['d_id'], $pp['note'], $pp['label'],
                      $urgent, $uid]);
        $reqId = (int)$db->lastInsertId();
    }

    $ins = $db->prepare("INSERT INTO purchase_request_item (req_id, spec_id, item_name, spec_text, category_id,
                         unit_id, qty_requested, est_price, receive_mode, location_id, remark, is_urgent, sort_order,
                         purpose_type, purpose_order_id, purpose_bom, purpose_d_id, purpose_note, purpose_label)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $sort = 0;
    foreach ($items as $i => $it) {
        $specId = (int)($it['spec_id'] ?? 0) ?: null;
        $name   = trim((string)($it['item_name'] ?? ''));
        if ($name === '') continue;
        // 到貨處理／儲位是倉管語言，申請人不填，由採購在詢價頁補；此處沿用既有預設
        $mode = (string)($it['receive_mode'] ?? 'stock');
        if (!in_array($mode, array_keys(PURCHASE_RECEIVE_MODES), true)) $mode = 'stock';
        $ip = $itemPurposes[$i] ?? null;   // null = 沿用單頭用途
        $ins->execute([
            $reqId, $specId, $name, trim((string)($it['spec_text'] ?? '')) ?: null,
            (int)($it['category_id'] ?? 0) ?: null, (int)($it['unit_id'] ?? 0) ?: null,
            max(0.0001, (float)($it['qty'] ?? 1)),
            ($it['est_price'] ?? '') === '' ? null : (float)$it['est_price'],
            $mode, (int)($it['location_id'] ?? 0) ?: null,
            trim((string)($it['remark'] ?? '')) ?: null,
            // 急件可以只勾其中幾項；沒送逐列值時（舊版前端）沿用單頭
            isset($it['is_urgent']) ? ((int)$it['is_urgent'] ? 1 : 0) : $urgent, $sort++,
            $ip['type'] ?? null, $ip['order_id'] ?? null, $ip['bom'] ?? null,
            $ip['d_id'] ?? null, $ip['note'] ?? null, $ip['label'] ?? null,
        ]);
    }
    // 單頭急件旗標＝任一項急件（列表、通知都看這個），一律由品項回推才不會兩邊不一致
    $db->prepare("UPDATE purchase_request r SET r.is_urgent =
                  COALESCE((SELECT MAX(pi.is_urgent) FROM purchase_request_item pi WHERE pi.req_id=r.req_id),0)
                  WHERE r.req_id=?")->execute([$reqId]);
    $st = $db->prepare("SELECT is_urgent FROM purchase_request WHERE req_id=?");
    $st->execute([$reqId]);
    $urgent = (int)$st->fetchColumn();

    if ($tempAtts) purchase_commit_temp_atts($db, $tempAtts, $reqId, $reqNo, $uid);
    $db->commit();

    if (($_POST['is_new'] ?? '') === '1') {
        $targets = purchase_role_users($db, 'purchase_buy');
        if (!$targets) $targets = purchase_role_users($db, 'purchase_admin');
        purchase_notify($db, $targets, 'PURCHASE_NEW', $reqId,
            ($urgent ? '【急】' : '') . '新採購申請：' . $reqNo,
            $uname . ' 提出採購申請 ' . $reqNo . '（' . $title . '）'
            . '，用途：' . PURCHASE_PURPOSE_TYPES[$pp['type']] . ' ' . (string)$pp['label']
            . '，請詢價。', 'read', $uid);
    }
    jout(['req_id' => $reqId, 'req_no' => $reqNo]);
}

case 'req_delete': {
    $reqId  = pint('req_id');
    $reason = pv('delete_reason');
    if ($reason === '') jerr('請輸入刪除原因');
    $req = purchase_load_req($db, $reqId);
    $isOwner = ((int)$req['requester_id'] === $uid);
    if (!($perms['canAdmin'] || ($isOwner && $req['status'] === 'submitted'))) jerr('無刪除權限或此單已進入採購流程', 403);
    $st = $db->prepare("SELECT COALESCE(SUM(qty_received),0) FROM purchase_request_item WHERE req_id=?");
    $st->execute([$reqId]);
    if ((float)$st->fetchColumn() > 0) jerr('此單已有到貨紀錄，不可刪除（請改用結案）');
    $db->beginTransaction();
    $db->prepare("UPDATE purchase_request SET is_active=0, deleted_by=?, deleted_at=NOW(), delete_reason=?, Modified_By=? WHERE req_id=?")
       ->execute([$uid, $reason, $uid, $reqId]);
    $db->commit();
    if (!$isOwner && $req['requester_id']) {
        purchase_notify($db, [(int)$req['requester_id']], 'PURCHASE_RESULT', $reqId,
            '採購申請已刪除：' . $req['req_no'], $uname . ' 刪除了 ' . $req['req_no'] . '，原因：' . $reason, 'read', $uid);
    }
    jout([]);
}

/** 採購詢價：填廠商／單價／稅別 → 後端算總額 → 判定簽核層級（免簽直接核准） */
case 'save_quote': {
    if (!$perms['canBuy']) jerr('無採購作業權限', 403);
    $reqId = pint('req_id');
    $req = purchase_load_req($db, $reqId);
    if (!in_array($req['status'], ['submitted', 'rejected'], true)) jerr('此單目前狀態不可填價');
    $taxType = in_array(pv('tax_type', 'taxable'), array_keys(PURCHASE_TAX_TYPES), true) ? pv('tax_type', 'taxable') : 'taxable';
    // 採購側資料：[{pr_item_id, unit_price, receive_mode, location_id,
    //               buy_item_name, buy_spec_text, buy_qty, buy_remark}]
    // 一律只寫採購側欄位，不動申請人填的品名／規格／數量
    $prices  = pjson('prices');

    $db->beginTransaction();
    $upd = $db->prepare("UPDATE purchase_request_item
                         SET unit_price=?, buy_qty=?, buy_item_name=?, buy_spec_text=?, buy_remark=?,
                             amount=ROUND(COALESCE(?, qty_requested) * ?, 2),
                             receive_mode=?, location_id=?
                         WHERE pr_item_id=? AND req_id=?");
    foreach ($prices as $p) {
        $price = ($p['unit_price'] ?? '') === '' ? 0 : (float)$p['unit_price'];
        $mode  = (string)($p['receive_mode'] ?? 'stock');
        if (!in_array($mode, array_keys(PURCHASE_RECEIVE_MODES), true)) $mode = 'stock';
        // 留白＝同申請，存 NULL 而不是 0／空字串，才分得出「沒改」與「改成空的」
        $bq   = ($p['buy_qty'] ?? '') === '' ? null : max(0.0001, (float)$p['buy_qty']);
        $bn   = trim((string)($p['buy_item_name'] ?? '')) ?: null;
        $bs   = trim((string)($p['buy_spec_text'] ?? '')) ?: null;
        $br   = trim((string)($p['buy_remark'] ?? '')) ?: null;
        $upd->execute([$price, $bq, $bn, $bs, $br, $bq, $price,
                       $mode, (int)($p['location_id'] ?? 0) ?: null,
                       (int)($p['pr_item_id'] ?? 0), $reqId]);
    }
    $t = purchase_calc_totals($db, $reqId, $taxType);
    [$l1, $l2] = purchase_thresholds($db);
    $levels = purchase_need_levels($t['grand_total'], $l1, $l2);
    $status = $levels === 0 ? 'approved' : 'quoted';
    $db->prepare("UPDATE purchase_request SET vendor_id=?, vendor_name=?, tax_type=?, subtotal=?, tax_amount=?,
                  grand_total=?, need_levels=?, level_done=0, status=?, buyer_id=?, buyer_name=?, quoted_at=NOW(),
                  expected_date=?, pay_method=?, approved_at=?, reject_reason=NULL, Modified_By=? WHERE req_id=?")
       ->execute([pv('vendor_id') ?: null, pv('vendor_name') ?: null, $taxType,
                  $t['subtotal'], $t['tax_amount'], $t['grand_total'], $levels, $status, $uid, $uname,
                  pdate('expected_date'), pv('pay_method') ?: null,
                  $levels === 0 ? date('Y-m-d H:i:s') : null, $uid, $reqId]);
    // 最近採購價回寫規格主檔（下次申請自動帶）
    $sp = $db->prepare("SELECT COALESCE(buy_spec_id, spec_id) spec_id, unit_price FROM purchase_request_item
                        WHERE req_id=? AND COALESCE(buy_spec_id, spec_id) IS NOT NULL");
    $sp->execute([$reqId]);
    $updSpec = $db->prepare("UPDATE purchase_spec SET last_price=?, last_vendor_id=?, last_vendor_name=?, last_buy_date=CURDATE() WHERE spec_id=?");
    foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if ($s['unit_price'] !== null) $updSpec->execute([$s['unit_price'], pv('vendor_id') ?: null, pv('vendor_name') ?: null, (int)$s['spec_id']]);
    }
    $db->commit();

    $req = purchase_load_req($db, $reqId);
    if ($levels > 0) {
        eg_approval_submit($db, 'purchase', $reqId, 'L1', $uid, $uname);
        $signers = purchase_level_signers($db, $req, 1);
        purchase_notify($db, $signers, 'PURCHASE_APPROVAL', $reqId, '採購單待簽核：' . $req['req_no'],
            $uname . ' 送出採購單 ' . $req['req_no'] . '，含稅總額 ' . number_format($t['grand_total'], 2) . ' 元，請簽核。',
            'sign', $uid);
    } else {
        purchase_notify($db, [(int)$req['requester_id']], 'PURCHASE_RESULT', $reqId,
            '採購申請已核價（免簽核）：' . $req['req_no'],
            '含稅總額 ' . number_format($t['grand_total'], 2) . ' 元，未達簽核門檻，採購可直接下單。', 'read', $uid);
    }
    jout(['status' => $status, 'need_levels' => $levels] + $t);
}

/** 簽核 */
case 'sign': {
    $reqId    = pint('req_id');
    $decision = pv('decision');
    $note     = pv('note');
    if (!in_array($decision, ['approved', 'rejected'], true)) jerr('決策值不正確');
    $req = purchase_load_req($db, $reqId);
    if (!purchase_can_sign($db, $req, $uid, $perms)) jerr('目前不是您的簽核關卡', 403);
    if ($decision === 'rejected' && $note === '') jerr('駁回必須填寫原因');
    $level = (int)$req['level_done'] + 1;

    $db->beginTransaction();
    $ar = eg_approval_latest($db, 'purchase', $reqId, 'L' . $level);
    if ($ar && $ar['status'] === 'pending') {
        eg_approval_decide($db, (int)$ar['id'], $uid, $uname, $decision, $note ?: null);
    } else {
        $db->prepare("INSERT INTO approval_record (module, entity_id, level, status, submitted_by, submitted_by_name,
                      submitted_at, approver_id, approver_name, note, decided_at)
                      VALUES ('purchase',?,?,?,?,?,NOW(),?,?,?,NOW())")
           ->execute([$reqId, 'L' . $level, $decision, (int)$req['requester_id'], (string)$req['requester_name'],
                      $uid, $uname, $note ?: null]);
    }
    if ($decision === 'rejected') {
        $db->prepare("UPDATE purchase_request SET status='rejected', reject_reason=?, Modified_By=? WHERE req_id=?")
           ->execute([$note, $uid, $reqId]);
        $newStatus = 'rejected';
    } elseif ($level >= (int)$req['need_levels']) {
        $db->prepare("UPDATE purchase_request SET status='approved', level_done=?, approved_at=NOW(), Modified_By=? WHERE req_id=?")
           ->execute([$level, $uid, $reqId]);
        $newStatus = 'approved';
    } else {
        $db->prepare("UPDATE purchase_request SET level_done=?, Modified_By=? WHERE req_id=?")->execute([$level, $uid, $reqId]);
        $newStatus = 'quoted';
    }
    $db->commit();

    purchase_close_sign_notice($db, $reqId, $uid);
    if ($newStatus === 'quoted') {
        $req2 = purchase_load_req($db, $reqId);
        $signers = purchase_level_signers($db, $req2, $level + 1);
        purchase_notify($db, $signers, 'PURCHASE_APPROVAL', $reqId, '採購單待簽核(第2關)：' . $req['req_no'],
            $uname . ' 已核准第1關，含稅總額 ' . number_format((float)$req['grand_total'], 2) . ' 元，請簽核。', 'sign', $uid);
    } else {
        $targets = array_merge([(int)$req['requester_id']], $req['buyer_id'] ? [(int)$req['buyer_id']] : []);
        $title = $newStatus === 'approved' ? '採購單已核准：' . $req['req_no'] : '採購單被駁回：' . $req['req_no'];
        $body  = $newStatus === 'approved'
               ? $uname . ' 已核准 ' . $req['req_no'] . '，採購可下單。' . ($note ? '（意見：' . $note . '）' : '')
               : $uname . ' 駁回 ' . $req['req_no'] . '，原因：' . $note;
        purchase_notify($db, $targets, 'PURCHASE_RESULT', $reqId, $title, $body, 'read', $uid);
    }
    jout(['status' => $newStatus]);
}

/** 下單 */
case 'mark_ordered': {
    if (!$perms['canBuy']) jerr('無採購作業權限', 403);
    $reqId = pint('req_id');
    $req = purchase_load_req($db, $reqId);
    if ($req['status'] !== 'approved') jerr('此單尚未核准或已下單');
    $db->beginTransaction();
    $db->prepare("UPDATE purchase_request SET status='ordered', ordered_at=NOW(), expected_date=COALESCE(?,expected_date),
                  Modified_By=? WHERE req_id=?")->execute([pdate('expected_date'), $uid, $reqId]);
    $db->commit();
    purchase_notify($db, [(int)$req['requester_id']], 'PURCHASE_RESULT', $reqId,
        '採購已下單：' . $req['req_no'], $uname . ' 已向 ' . ($req['vendor_name'] ?: '廠商') . ' 下單。', 'read', $uid);
    jout(['status' => 'ordered']);
}

/** 到貨：每筆明細三選一（入庫待領 / 直接交付請購人 / 不列管） */
case 'receive': {
    if (!$perms['canReceive']) jerr('無到貨入庫權限', 403);
    $reqId = pint('req_id');
    $req = purchase_load_req($db, $reqId);
    if (!in_array($req['status'], ['ordered', 'partial'], true)) jerr('此單目前狀態不可收貨');
    $lines = pjson('lines');   // [{pr_item_id, qty, receive_mode, location_id, receiver_id, remark}]
    if (!$lines) jerr('請至少填一筆到貨數量');
    $rcptDate = pdate('rcpt_date') ?: date('Y-m-d');

    $db->beginTransaction();
    $got = $db->prepare("SELECT * FROM purchase_request_item WHERE pr_item_id=? AND req_id=?");
    $insR = $db->prepare("INSERT INTO purchase_receipt (req_id, pr_item_id, rcpt_date, qty, receive_mode, location_id,
                          stock_item_id, txn_in_id, txn_out_id, receiver_id, receiver_name, remark, Created_By)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $done = 0;
    foreach ($lines as $ln) {
        $qty = (float)($ln['qty'] ?? 0);
        if ($qty <= 0) continue;
        $got->execute([(int)($ln['pr_item_id'] ?? 0), $reqId]);
        $item = $got->fetch(PDO::FETCH_ASSOC);
        if (!$item) continue;
        // 未到量以「採購實際數量」為基準（採購沒改就是申請數量）
        $target = ($item['buy_qty'] === null || $item['buy_qty'] === '')
                ? (float)$item['qty_requested'] : (float)$item['buy_qty'];
        $left = $target - (float)$item['qty_received'];
        if ($qty > $left + 0.0001) { $db->rollBack(); jerr($item['item_name'] . ' 到貨量超過未到量（剩 ' . rtrim(rtrim(number_format($left, 4, '.', ''), '0'), '.') . '）'); }

        $mode = (string)($ln['receive_mode'] ?? $item['receive_mode']);
        if (!in_array($mode, array_keys(PURCHASE_RECEIVE_MODES), true)) $mode = (string)$item['receive_mode'];
        $locId = (int)($ln['location_id'] ?? 0) ?: ($item['location_id'] !== null ? (int)$item['location_id'] : null);
        $stockItemId = null; $txnIn = null; $txnOut = null;
        $receiverId = (int)($ln['receiver_id'] ?? 0) ?: null;
        $receiverName = null;

        if ($mode === 'stock' || $mode === 'direct') {
            try {
                $stockItemId = purchase_find_or_create_stock_item($db, $item, $req, $locId, $uid);
            } catch (Throwable $e) { $db->rollBack(); jerr($item['item_name'] . '：' . $e->getMessage()); }
            $locName = '';
            if ($locId) {
                $lq = $db->prepare("SELECT location_code FROM stock_locations WHERE location_id=?");
                $lq->execute([$locId]);
                $locName = (string)($lq->fetchColumn() ?: '');
            }
            $txnIn = purchase_write_txn($db, $stockItemId, 'in', $qty, $locId, [
                'location_name' => $locName, 'unit_cost' => $item['unit_price'], 'txn_date' => $rcptDate,
                'remark' => '採購入庫 ' . $req['req_no'] . ($req['vendor_name'] ? '／' . $req['vendor_name'] : ''),
            ], $uid);
            if ($mode === 'direct') {
                if (!$receiverId) $receiverId = (int)$req['requester_id'];
                $nq = $db->prepare("SELECT user_cname FROM user WHERE id=?");
                $nq->execute([$receiverId]);
                $receiverName = (string)($nq->fetchColumn() ?: '');
                $txnOut = purchase_write_txn($db, $stockItemId, 'out', $qty, $locId, [
                    'location_name' => $locName, 'unit_cost' => $item['unit_price'], 'txn_date' => $rcptDate,
                    'remark' => '採購到貨直接交付 ' . $req['req_no'] . '／' . $receiverName,
                    'out_dept_id' => $req['dept_id'], 'out_user_id' => $receiverId,
                ], $uid);
            }
        }

        $insR->execute([$reqId, (int)$item['pr_item_id'], $rcptDate, $qty, $mode, $locId, $stockItemId,
                        $txnIn, $txnOut, $receiverId, $receiverName, trim((string)($ln['remark'] ?? '')) ?: null, $uid]);
        $db->prepare("UPDATE purchase_request_item SET qty_received=qty_received+?, receive_mode=?,
                      location_id=COALESCE(?,location_id), stock_item_id=COALESCE(?,stock_item_id) WHERE pr_item_id=?")
           ->execute([$qty, $mode, $locId, $stockItemId, (int)$item['pr_item_id']]);
        $done++;
    }
    if (!$done) { $db->rollBack(); jerr('沒有有效的到貨數量'); }
    $status = purchase_refresh_status($db, $reqId, $uid);
    $db->commit();

    purchase_notify($db, [(int)$req['requester_id']], 'PURCHASE_RESULT', $reqId,
        ($status === 'received' ? '採購已全數到貨：' : '採購部分到貨：') . $req['req_no'],
        $uname . ' 登錄了 ' . $req['req_no'] . ' 的到貨。', 'read', $uid);
    jout(['status' => $status]);
}

/** 記帳：發票／付款 */
case 'save_account': {
    if (!$perms['canBuy']) jerr('無採購作業權限', 403);
    $reqId = pint('req_id');
    $req = purchase_load_req($db, $reqId);
    $payStatus = in_array(pv('pay_status', 'unpaid'), ['unpaid', 'paid'], true) ? pv('pay_status', 'unpaid') : 'unpaid';
    $db->beginTransaction();
    $db->prepare("UPDATE purchase_request SET invoice_no=?, invoice_date=?, pay_status=?, pay_date=?, pay_method=?, Modified_By=? WHERE req_id=?")
       ->execute([pv('invoice_no') ?: null, pdate('invoice_date'), $payStatus,
                  $payStatus === 'paid' ? (pdate('pay_date') ?: date('Y-m-d')) : null,
                  pv('pay_method') ?: null, $uid, $reqId]);
    $db->commit();
    jout([]);
}

case 'close_req': {
    if (!$perms['canBuy']) jerr('無採購作業權限', 403);
    $reqId = pint('req_id');
    $db->beginTransaction();
    $db->prepare("UPDATE purchase_request SET status='closed', closed_at=NOW(), Modified_By=? WHERE req_id=? AND is_active=1")
       ->execute([$uid, $reqId]);
    $db->commit();
    jout(['status' => 'closed']);
}

/* ============================================================
 * 附件（新增單據未存檔即可上傳：temp → 存檔時轉正）
 * ============================================================ */
case 'att_upload': {
    if (!$perms['canApply']) jerr('無上傳權限', 403);
    $reqId = pint('req_id');
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('上傳失敗');
    $orig = basename($_FILES['file']['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'xlsx', 'xls', 'docx', 'doc'], true)) {
        jerr('僅支援圖片／PDF／Office 檔');
    }
    if ((int)$_FILES['file']['size'] > 20 * 1024 * 1024) jerr('檔案不可超過 20MB');
    $attType = in_array(pv('att_type', 'other'), ['quote', 'invoice', 'receipt', 'other'], true) ? pv('att_type', 'other') : 'other';

    [$nas] = purchase_attach_dirs($db);
    $reqNo = '';
    if ($reqId > 0) {
        $st = $db->prepare("SELECT req_no FROM purchase_request WHERE req_id=? AND is_active=1");
        $st->execute([$reqId]);
        $reqNo = (string)($st->fetchColumn() ?: '');
        if ($reqNo === '') jerr('找不到採購單');
    }
    $sub = $reqNo !== '' ? $reqNo : '_temp';
    $dir = $nas . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) jerr('無法建立附件目錄，請確認路徑設定：' . $dir);
    $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . DIRECTORY_SEPARATOR . $fname)) jerr('檔案寫入失敗');

    if ($reqId > 0) {
        $db->prepare("INSERT INTO purchase_attachment (req_id, user_id, att_type, file_name, original_name, file_size, status)
                      VALUES (?,?,?,?,?,?, 'active')")
           ->execute([$reqId, $uid, $attType, $fname, $orig, (int)$_FILES['file']['size']]);
    } else {
        $db->prepare("INSERT INTO purchase_attachment (req_id, user_id, att_type, file_name, original_name, file_size, status, expire_at)
                      VALUES (0,?,?,?,?,?, 'temp', DATE_ADD(NOW(), INTERVAL 2 DAY))")
           ->execute([$uid, $attType, $fname, $orig, (int)$_FILES['file']['size']]);
    }
    $attId = (int)$db->lastInsertId();
    jout(['att_id' => $attId, 'file_name' => $fname, 'original_name' => $orig,
          'url' => purchase_att_url($db, ['att_id' => $attId])]);
}

/** 附件下載／預覽：一律經此端點串流，才擋得住未登入直連，也才讀得到 UNC 路徑 */
case 'att_download': {
    $attId = (int)($_GET['att_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM purchase_attachment WHERE att_id=?");
    $st->execute([$attId]);
    $att = $st->fetch(PDO::FETCH_ASSOC);
    if (!$att) jerr('找不到附件', 404);

    // 看得到這張單的人才下載得到：全域檢閱者、單據申請人、或這個 temp 附件的上傳者
    $ok = $perms['canView'];
    if (!$ok && (int)$att['req_id'] > 0) {
        $st = $db->prepare("SELECT requester_id FROM purchase_request WHERE req_id=?");
        $st->execute([(int)$att['req_id']]);
        $ok = ((int)$st->fetchColumn() === $uid);
    }
    if (!$ok) $ok = ((int)$att['user_id'] === $uid);
    if (!$ok) jerr('無權下載此附件', 403);

    $path = purchase_att_path($db, $att);
    $name = (string)($att['original_name'] ?: $att['file_name']);
    purchase_att_stream($path, $name, ($_GET['dl'] ?? '') !== '1');
}

case 'att_delete': {
    $attId = pint('att_id');
    $st = $db->prepare("SELECT a.*, r.req_no, r.requester_id FROM purchase_attachment a
                        LEFT JOIN purchase_request r ON r.req_id=a.req_id WHERE a.att_id=?");
    $st->execute([$attId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    $mine = ((int)$a['user_id'] === $uid);
    $ok = $a['status'] === 'temp' ? $mine : ($perms['canBuy'] || $perms['canAdmin'] || (int)$a['requester_id'] === $uid);
    if (!$ok) jerr('無刪除權限', 403);
    $fp = purchase_att_path($db, $a, (string)($a['req_no'] ?? ''));
    $db->prepare("DELETE FROM purchase_attachment WHERE att_id=?")->execute([$attId]);
    if (is_file($fp)) @unlink($fp);
    jout([]);
}

/**
 * 明細綁定採購料號（採購自己那一層 buy_spec_id；申請人填的 item_name/spec_text/spec_id 一律不動）
 *   mode=existing → 綁既有料號（spec_id）
 *   mode=new      → 建立新料號：類別＋（既有品項 or 新品項）＋規格屬性/說明＋料號（可自訂，留白＝自動編號）
 *   mode=clear    → 解除綁定（綁錯了要能改回來）
 *
 * 刻意「不替採購自動決定」：舊版只問類別就自動建品項＋自動配號，主檔於是長出一堆同名品項、
 * 料號沒人看過也沒人記得，之後根本找不到正確的採購料號（2026-07-30 使用者退回）。
 * 現在同類別同品名／同品項同規格／料號重複一律擋下，回傳既有那筆讓採購改去綁它。
 */
case 'bind_spec': {
    if (!$perms['canBuy']) jerr('無維護採購品主檔的權限', 403);
    $prItemId = pint('pr_item_id');
    $st = $db->prepare("SELECT * FROM purchase_request_item WHERE pr_item_id=?");
    $st->execute([$prItemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) jerr('找不到明細');
    $specId = pint('spec_id');
    $mode   = pv('mode', $specId > 0 ? 'existing' : 'new');

    if ($mode === 'clear') {
        $db->prepare("UPDATE purchase_request_item SET buy_spec_id=NULL WHERE pr_item_id=?")->execute([$prItemId]);
        jout(['cleared' => 1]);
    }

    $created = 0;
    if ($mode === 'new') {
        $itemId = pint('item_id');
        $catId  = pint('category_id');
        $unitId = pint('unit_id') ?: ((int)$item['unit_id'] ?: 0);
        if ($itemId > 0) {
            $iq = $db->prepare("SELECT item_id, category_id, item_name FROM purchase_item WHERE item_id=? AND is_active=1");
            $iq->execute([$itemId]);
            $pit = $iq->fetch(PDO::FETCH_ASSOC);
            if (!$pit) jerr('找不到品項（可能已停用）');
            $catId = (int)$pit['category_id'];
        } else {
            if ($catId <= 0) jerr('請選擇類別');
            $name = pv('item_name');
            if ($name === '') jerr('請輸入品名');
            // 同類別同品名＝就是同一種東西，不可再建一個（尺寸差異請放在規格）
            $chk = $db->prepare("SELECT item_id, item_code FROM purchase_item
                                 WHERE category_id=? AND item_name=? AND is_active=1 LIMIT 1");
            $chk->execute([$catId, $name]);
            if ($dup = $chk->fetch(PDO::FETCH_ASSOC)) {
                jout(['conflict' => 'item', 'item_id' => (int)$dup['item_id'],
                      'msg' => '這個類別已經有品名「' . $name . '」（品項編碼 ' . $dup['item_code'] .
                               '）。請改用「掛在既有品項」把新規格加在它下面，不要再建一個同名品項。']);
            }
        }

        $attrVals = pjson('attr_vals');
        $specText = pv('spec_text');
        if ($specText === '') $specText = purchase_build_spec_text($db, $catId, $attrVals);
        if ($specText === '') jerr('請填規格（沒有屬性欄位就直接寫規格說明，例：Ø5 長100 HSS）');

        // 同品項同規格＝同一個採購料號，直接叫他去綁既有那筆
        if ($itemId > 0) {
            $chk = $db->prepare("SELECT spec_id, spec_code FROM purchase_spec
                                 WHERE item_id=? AND spec_text=? AND is_active=1 LIMIT 1");
            $chk->execute([$itemId, $specText]);
            if ($dup = $chk->fetch(PDO::FETCH_ASSOC)) {
                jout(['conflict' => 'spec', 'spec_id' => (int)$dup['spec_id'], 'spec_code' => $dup['spec_code'],
                      'msg' => '這個品項底下已經有一樣的規格，採購料號 ' . $dup['spec_code'] . '。請直接綁定它。']);
            }
        }

        $code = pv('spec_code');
        if ($code !== '') {
            if (mb_strlen($code) > 40) jerr('採購料號最長 40 個字');
            $chk = $db->prepare("SELECT s.spec_id, s.spec_code, s.spec_text, i.item_name FROM purchase_spec s
                                 JOIN purchase_item i ON i.item_id=s.item_id WHERE s.spec_code=? LIMIT 1");
            $chk->execute([$code]);
            if ($dup = $chk->fetch(PDO::FETCH_ASSOC)) {
                jout(['conflict' => 'code', 'spec_id' => (int)$dup['spec_id'], 'spec_code' => $dup['spec_code'],
                      'msg' => '採購料號 ' . $code . ' 已經被「' . $dup['item_name'] . '／' . $dup['spec_text'] .
                               '」用掉了。請換一個號碼，或直接綁定那一筆。']);
            }
        }

        $db->beginTransaction();
        if ($itemId <= 0) {
            $icode = purchase_next_item_code($db, $catId);
            $db->prepare("INSERT INTO purchase_item (item_code, category_id, item_name, default_unit_id, Created_By)
                          VALUES (?,?,?,?,?)")
               ->execute([$icode, $catId, pv('item_name'), $unitId ?: null, $uid]);
            $itemId = (int)$db->lastInsertId();
        }
        if ($code === '') $code = purchase_next_spec_code($db, $itemId);
        $db->prepare("INSERT INTO purchase_spec (item_id, spec_code, spec_text, attr_json, unit_id, location_id, safety_qty, Created_By)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$itemId, $code, $specText, $attrVals ? json_encode($attrVals, JSON_UNESCAPED_UNICODE) : null,
                      $unitId ?: null, pint('location_id') ?: null, pnum('safety_qty'), $uid]);
        $specId  = (int)$db->lastInsertId();
        $created = 1;
        $db->commit();
    }

    if ($specId <= 0) jerr('請選擇要綁定的採購料號');
    $sq = $db->prepare("SELECT s.spec_id, s.spec_code, s.spec_text, s.unit_id, i.item_name, i.category_id
                        FROM purchase_spec s JOIN purchase_item i ON i.item_id=s.item_id
                        WHERE s.spec_id=? AND s.is_active=1");
    $sq->execute([$specId]);
    $spec = $sq->fetch(PDO::FETCH_ASSOC);
    if (!$spec) jerr('找不到這筆採購料號（可能已停用）');
    // 只寫採購側欄位。申請人填的 item_name / spec_text / spec_id 一律不動
    // ——原本這裡直接覆寫，申請人的原始寫法會被抹掉，之後無從對照買到的是不是他要的東西
    $db->prepare("UPDATE purchase_request_item
                  SET buy_spec_id=?, buy_item_name=?, buy_spec_text=?, buy_unit_id=COALESCE(buy_unit_id,?)
                  WHERE pr_item_id=?")
       ->execute([$specId, $spec['item_name'], $spec['spec_text'],
                  $spec['unit_id'] !== null ? (int)$spec['unit_id'] : null, $prItemId]);
    jout(['spec_id' => $specId, 'spec_code' => $spec['spec_code'], 'created' => $created]);
}

default:
    jerr('未知的 action：' . $action, 404);
}
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    jerr($e->getMessage(), 500);
}
