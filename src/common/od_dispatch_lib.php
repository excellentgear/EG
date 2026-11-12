<?php
/**
 * 批次發單（外發加工單）共用庫 — 2026-11-12 建立
 *
 * 用途：生管一次挑多張 BOM 的多個製程，依廠商合併成「同廠商一張」外發單，發單並列印（點陣式中一刀）。
 * 發單的「寫入動作」＝與 BOM 總覽既有「發單（transfer_process，帶發單數量）」同一種結果：
 *   bom_ing 寫入發包日／廠商、狀態轉加工中(ing)、流水帳 bom_ing_outsource_batch 開一批（batch_no＝本張單號）。
 * 本檔不修改任何既有函式／頁面，BOM 總覽原有單筆發單完全不受影響。
 *
 * 資料表：od_slip（單頭）／od_slip_line（明細）／od_format（列印格式）
 * 角色：module='od_dispatch'：od_view 檢視／od_issue 發單與列印／od_admin 格式與設定（管理者固定全權）
 * 列印紀錄：走全站 print_log（來源 od_slip，ai-rules/23）
 */

require_once __DIR__ . '/bom_outsource_lib.php';

if (!defined('OD_CUSTOMER_MATERIAL_PROCESS')) define('OD_CUSTOMER_MATERIAL_PROCESS', 138); // 客供料：實收數量可多於或少於 BOM，不套用數量上限

function od_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    eg_bom_outsource_ensure_schema($db);
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS od_slip (
            slip_id INT AUTO_INCREMENT PRIMARY KEY,
            slip_no VARCHAR(20) NOT NULL COMMENT '單號 D+民國年3碼+MMDD-流水3碼',
            maker_id_no VARCHAR(11) NULL, maker_id VARCHAR(60) NULL COMMENT '廠商簡稱快照',
            maker_addr VARCHAR(255) NULL, maker_tel VARCHAR(50) NULL,
            issue_date DATE NOT NULL COMMENT '發單日（業務日期）',
            format_id INT NULL, two_part TINYINT(1) NOT NULL DEFAULT 0 COMMENT '印成兩聯',
            issued_by INT NULL, issued_by_name VARCHAR(50) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'issued' COMMENT 'issued / void',
            note VARCHAR(200) NULL,
            print_count INT NOT NULL DEFAULT 0, last_printed_at DATETIME NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uk_slip_no (slip_no), KEY idx_maker (maker_id_no), KEY idx_date (issue_date)
        ) COMMENT='外發加工單單頭（同一廠商一張）'");
        $db->exec("CREATE TABLE IF NOT EXISTS od_slip_line (
            line_id INT AUTO_INCREMENT PRIMARY KEY,
            slip_id INT NOT NULL, sort_no INT NOT NULL DEFAULT 0,
            bom VARCHAR(30) NULL, part_no VARCHAR(60) NULL, drawing_no VARCHAR(60) NULL, client_name VARCHAR(60) NULL,
            bom_ing_fid INT NOT NULL, batch_id INT NULL,
            process_no INT NULL, process_name VARCHAR(40) NULL,
            qty DECIMAL(14,3) NOT NULL, due_date DATE NULL, remark VARCHAR(200) NULL,
            KEY idx_slip (slip_id), KEY idx_fid (bom_ing_fid), KEY idx_batch (batch_id)
        ) COMMENT='外發加工單明細（一列＝一個製程一批）'");
        $db->exec("CREATE TABLE IF NOT EXISTS od_format (
            format_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            config_json TEXT NULL COMMENT '紙張／每頁列數／欄位順序／表尾文字／預設兩聯',
            maker_ids_json TEXT NULL COMMENT '指定這些廠商固定使用此格式（廠商代號陣列）',
            created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL
        ) COMMENT='外發單列印格式'");
        $db->exec("CREATE TABLE IF NOT EXISTS od_price (
            price_id INT AUTO_INCREMENT PRIMARY KEY,
            d_id INT NULL COMMENT '料號主檔 d_setting.d_id（同名料號分屬不同客戶，一律以主檔 id 為準）',
            part_no VARCHAR(60) NULL COMMENT '料號文字快照',
            client_name VARCHAR(60) NULL,
            process_no INT NOT NULL,
            maker_id_no VARCHAR(11) NULL COMMENT '空＝不限廠商（老闆核定的一般價）',
            unit_price DECIMAL(12,2) NOT NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'approved' COMMENT 'approved=老闆核定／inquiry=廠商詢價結果（待核定）',
            qty_note VARCHAR(60) NULL COMMENT '數量區間或條件說明',
            valid_from DATE NULL, valid_to DATE NULL,
            note VARCHAR(200) NULL,
            status VARCHAR(8) NOT NULL DEFAULT 'active' COMMENT 'active / void',
            approved_by VARCHAR(50) NULL, approved_at DATETIME NULL,
            created_by INT NULL, created_by_name VARCHAR(50) NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            KEY idx_part_proc (d_id, process_no), KEY idx_proc (process_no), KEY idx_maker (maker_id_no)
        ) COMMENT='外包加工價格表（老闆核定價／廠商詢價結果），發單時自動帶入單價'");
    } catch (Throwable $e) { /* 呼叫端自行處理 */ }
    try {
        if (!$db->query("SHOW COLUMNS FROM od_slip_line LIKE 'unit_price'")->fetch()) {
            $db->exec("ALTER TABLE od_slip_line ADD COLUMN unit_price DECIMAL(12,2) NULL COMMENT '發單單價（沒填＝NULL，不顯示 0；後續對帳再補）' AFTER qty");
        }
    } catch (Throwable $e) {}
    try {
        foreach ([['od_view', '批次發單檢閱'], ['od_issue', '批次發單（發單與列印）'], ['od_admin', '批次發單管理員']] as $r) {
            $db->prepare("INSERT IGNORE INTO roles (role_code, role_name, module) VALUES (?,?, 'od_dispatch')")->execute($r);
        }
    } catch (Throwable $e) {}
    try {
        if ((int)$db->query("SELECT COUNT(*) FROM od_format")->fetchColumn() === 0) {
            $db->prepare("INSERT INTO od_format (name, is_default, config_json, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())")
               ->execute(['外發單 標準', 1, json_encode(od_format_default_config(), JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) {}
}

/** 所有可選欄位（key => 標題）；格式只存 key 的順序，標題由這裡統一取 */
function od_format_columns(): array
{
    return ['no' => '項', 'bom' => 'BOM', 'part_no' => '料號', 'drawing_no' => '圖號', 'client' => '客戶',
            'process' => '製程', 'qty' => '數量', 'price' => '單價', 'amount' => '金額', 'due' => '交期', 'remark' => '備註'];
}

function od_format_default_config(): array
{
    return [
        'paper'         => 'continuous', // continuous=中一刀(9.5"×5.5") / a4p=A4直式 / a5l=A5橫式
        'rows_per_page' => 8,
        'cols'          => ['no', 'bom', 'part_no', 'process', 'qty', 'due', 'remark'],
        'footer_text'   => '',
        'two_part'      => 0,
        'show_total'    => 1,
        'show_sign'     => 1,
    ];
}

function od_format_normalize(array $c): array
{
    $d = od_format_default_config();
    $allowed = array_keys(od_format_columns());
    $cols = [];
    foreach ((array)($c['cols'] ?? $d['cols']) as $k) { if (in_array($k, $allowed, true) && !in_array($k, $cols, true)) $cols[] = $k; }
    if (!$cols) $cols = $d['cols'];
    $paper = in_array(($c['paper'] ?? ''), ['continuous', 'a4p', 'a5l'], true) ? $c['paper'] : $d['paper'];
    return [
        'paper' => $paper,
        'rows_per_page' => max(3, min(40, (int)($c['rows_per_page'] ?? $d['rows_per_page']))),
        'cols' => $cols,
        'footer_text' => mb_substr(trim((string)($c['footer_text'] ?? '')), 0, 300),
        'two_part' => !empty($c['two_part']) ? 1 : 0,
        'show_total' => array_key_exists('show_total', $c) ? (empty($c['show_total']) ? 0 : 1) : 1,
        'show_sign' => array_key_exists('show_sign', $c) ? (empty($c['show_sign']) ? 0 : 1) : 1,
    ];
}

// ───────────────────────────── 權限 ─────────────────────────────
function od_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='od_dispatch' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function od_perms(PDO $db, ?array $u): array
{
    $none = ['isAdmin' => false, 'canAdmin' => false, 'canIssue' => false, 'canView' => false, 'uid' => 0, 'name' => ''];
    if (!$u) return $none;
    $uid = (int)$u['id'];
    if ((int)($u['state'] ?? 0) === 0 || (int)($u['user_status'] ?? 0) === 90) return $none;
    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;
    $canAdmin = $isAdmin || od_has_role($db, $uid, ['od_admin']);
    $canIssue = $canAdmin || od_has_role($db, $uid, ['od_issue']);
    $canView  = $canIssue || od_has_role($db, $uid, ['od_view']);
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canIssue' => $canIssue, 'canView' => $canView,
            'uid' => $uid, 'name' => (string)($u['user_cname'] ?? '')];
}

function od_current_user(PDO $db): ?array
{
    $uid = (int)($_SESSION['id'] ?? 0);
    if ($uid <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, user_cname, state, user_status FROM user WHERE id=?");
        $st->execute([$uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

// ───────────────────────────── 日期 ─────────────────────────────
/** 發單日＋N 個工作日（跳過行事曆休假日、含補班日；N=0 回原日） */
function od_add_workdays(PDO $db, string $from, int $days): string
{
    if (!function_exists('eg_leave_is_workday')) require_once __DIR__ . '/leave_lib.php';
    $t = strtotime($from);
    if (!$t) return $from;
    $n = 0; $guard = 0;
    while ($n < $days && $guard++ < 60) {
        $t = strtotime('+1 day', $t);
        if (eg_leave_is_workday($db, date('Y-m-d', $t))) $n++;
    }
    return date('Y-m-d', $t);
}

// ───────────────────────────── BOM 製程一覽 ─────────────────────────────
/**
 * 篩選出 BOM 並攤開每個製程的「發單／回廠／QC」狀態。
 * $f: bom, part, client, process_type_id, urgent(U/E), qc_mode(any_returned|qc_full|qc_partial|no_qc|none), limit, offset
 * 回傳 ['rows'=>[bom=>…, 'chain'=>[製程…]], 'total'=>N]
 */
function od_chain(PDO $db, array $f): array
{
    od_ensure_schema($db);
    $where = ["(b.processing_state IS NULL OR b.processing_state NOT IN ('1'))"];
    $args = [];
    foreach (['bom' => 'b.bom', 'part' => 'b.d_id', 'client' => 'b.Client_Name'] as $k => $col) {
        $v = trim((string)($f[$k] ?? ''));
        if ($v === '') continue;
        foreach (preg_split('/\s+/u', $v) as $w) { if ($w === '') continue; $where[] = "$col LIKE ?"; $args[] = '%' . $w . '%'; }
    }
    $urgent = trim((string)($f['urgent'] ?? ''));
    if ($urgent === 'U' || $urgent === 'E') { $where[] = 'b.priority_type = ?'; $args[] = $urgent; }
    $ptype = (int)($f['process_type_id'] ?? 0);
    if ($ptype > 0) {
        $where[] = "EXISTS (SELECT 1 FROM bom_ing x JOIN process_no pn ON pn.ProcessNo=x.process_no WHERE x.bom=b.bom AND pn.process_type_id=?)";
        $args[] = $ptype;
    }
    $sqlW = implode(' AND ', $where);
    $cnt = $db->prepare("SELECT COUNT(*) FROM bom b WHERE $sqlW");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();

    $limit = max(1, min(100, (int)($f['limit'] ?? 30)));
    $offset = max(0, (int)($f['offset'] ?? 0));
    $qm = (string)($f['qc_mode'] ?? 'issuable');
    $match = function ($r) use ($qm) {
        if ($qm === '' || $qm === 'all') return true;
        foreach ($r['chain'] as $c) {
            if ($qm === 'issuable' && !empty($c['issuable'])) return true;
            if ($qm === 'any_returned' && $c['ret'] > 0 && $c['state'] !== 'skip') return true;
            if ($qm === 'qc_full' && $c['qc']['mode'] === 'full') return true;
            if ($qm === 'qc_partial' && $c['qc']['mode'] === 'partial') return true;
            if ($qm === 'no_qc' && $c['ret'] > 0 && $c['qc']['mode'] === 'none') return true;
            if ($qm === 'none' && $c['sent'] <= 0 && $c['state'] === 'none') return true;
        }
        return false;
    };
    // 條件要看整條製程鏈才判得出來，所以分批往後掃，湊滿一頁或掃完為止（單次最多掃 600 張 BOM，避免拖慢）
    $rows = []; $scanned = 0; $chunk = 40; $pos = $offset; $more = false;
    while (count($rows) < $limit && $scanned < 600) {
        $st = $db->prepare("SELECT b.bom, b.d_id AS part_no, b.Client_Name AS client, b.sqty, b.priority_type, b.Delivery_date
                              FROM bom b WHERE $sqlW ORDER BY b.bom DESC LIMIT $chunk OFFSET $pos");
        $st->execute($args);
        $boms = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$boms) { $more = false; $pos = $total; break; }
        foreach ($boms as $i => $b) {
            $b['chain'] = od_bom_chain($db, (string)$b['bom']);
            $pos++; $scanned++;
            if ($match($b)) $rows[] = $b;
            if (count($rows) >= $limit) break;
        }
        $more = $pos < $total;
        if (count($boms) < $chunk) break;
    }
    return ['rows' => $rows, 'total' => $total, 'next_offset' => $pos, 'more' => $more && $pos < $total];
}

/** 單張 BOM 的製程鏈（依 bom_sn；同一 bom_sn 有多列＝拆批，彙總成一格） */
function od_bom_chain(PDO $db, string $bom): array
{
    $st = $db->prepare("SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, bi.sqty, bi.processing_state, bi.maker_id_no, bi.maker_id,
                               bi.outsource_date, bi.return_date, bi.qc_completed, bi.ps, bi.PS2, bi.is_schedule_split,
                               pn.ProcessName, pn.process_type_id, pn.is_exclude_qc, pt.process_type AS ptype_name
                          FROM bom_ing bi
                          LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                          LEFT JOIN process_type pt ON pt.process_type_id = pn.process_type_id
                         WHERE bi.bom = ? AND bi.is_schedule_split = 0
                         ORDER BY bi.bom_sn, bi.bom_ing_fid");
    $st->execute([$bom]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $fids = array_map(fn($r) => (int)$r['bom_ing_fid'], $rows);
    $in = implode(',', $fids);

    $batch = [];
    foreach ($db->query("SELECT bom_ing_fid, COUNT(*) n, COALESCE(SUM(send_qty - withdrawn_qty + short_qty),0) sent,
                                COALESCE(SUM(return_qty),0) ret, COALESCE(SUM(scrap_qty),0) scrap
                           FROM bom_ing_outsource_batch WHERE bom_ing_fid IN ($in) GROUP BY bom_ing_fid")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $batch[(int)$r['bom_ing_fid']] = $r;
    }
    $qc = [];
    foreach ($db->query("SELECT bom_ing_fid_ref AS fid, COALESCE(SUM(QC_ok_sqty),0)+COALESCE(SUM(QC_aod_sqty),0) AS pass,
                                COALESCE(SUM(QC_ok_sqty),0)+COALESCE(SUM(QC_aod_sqty),0)+COALESCE(SUM(QC_QQ_sqty),0)+COALESCE(SUM(QC_ng_sqty),0) AS insp
                           FROM QC_check WHERE bom_ing_fid_ref IN ($in) GROUP BY bom_ing_fid_ref")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qc[(int)$r['fid']] = $r;
    }

    $chain = [];
    $lastRetPositive = true; // 第一關視為可發
    $suggested = false;
    $seen = [];
    foreach ($rows as $r) {
        $sn = (int)$r['bom_sn'];
        if (isset($seen[$sn])) continue; // 同 bom_sn 只取第一列為代表
        $seen[$sn] = 1;
        $fid = (int)$r['bom_ing_fid'];
        $state = (string)$r['processing_state'];
        $sqty = (float)$r['sqty'];
        if (isset($batch[$fid])) {
            $sent = (float)$batch[$fid]['sent']; $ret = (float)$batch[$fid]['ret']; $scrap = (float)$batch[$fid]['scrap'];
        } else { // 舊資料沒有流水帳：由狀態推回
            $sent = in_array($state, ['ing', 'Q', 'P', 'E'], true) ? $sqty : 0.0;
            $ret = in_array($state, ['Q', 'P', 'E'], true) ? $sqty : 0.0;
            $scrap = 0.0;
        }
        $pq = $qc[$fid] ?? ['pass' => 0, 'insp' => 0];
        $excl = !empty($r['is_exclude_qc']);
        $qmode = 'none';
        if (!$excl && $ret > 0 && (float)$pq['insp'] > 0) $qmode = ((float)$pq['insp'] >= $ret - 1e-9) ? 'full' : 'partial';
        $disp = 'none';
        if ($state === 'skip') $disp = 'skip';
        elseif ($sent > 0 && $sent >= $sqty - 1e-9 && ($ret + $scrap) >= $sent - 1e-9) $disp = 'done';
        elseif ($sent > 0) $disp = 'partial';
        $chain[] = [
            'bom_ing_fid' => $fid, 'bom_sn' => $sn, 'process_no' => (int)$r['process_no'], 'name' => (string)$r['ProcessName'],
            'ptype_id' => (int)$r['process_type_id'], 'ptype' => (string)$r['ptype_name'],
            'state' => $disp, 'raw_state' => $state, 'sqty' => $sqty, 'sent' => $sent, 'ret' => $ret, 'scrap' => $scrap,
            'qc' => ['mode' => $qmode, 'insp' => (float)$pq['insp'], 'pass' => (float)$pq['pass'], 'ret' => $ret],
            'excl_qc' => $excl, 'maker_no' => (string)$r['maker_id_no'], 'maker' => (string)$r['maker_id'],
            'remark' => trim((string)$r['ps']), 'remark2' => trim((string)$r['PS2']),
            'is_customer_material' => ((int)$r['process_no'] === OD_CUSTOMER_MATERIAL_PROCESS),
            'avail' => 0.0, 'suggest' => false,
        ];
    }
    // 可發量：上一個「非跳過」製程的 已驗合格數（免驗者用回廠良品數），且不超過本站 BOM 總量－已發；
    //        第一關（非客供料）＝本站未發量；客供料（製程 138）實收數量可多於或少於 BOM，不設上限（avail=null）
    $prev = null;
    foreach ($chain as $i => &$c) {
        $c['remaining'] = max(0.0, $c['sqty'] - $c['sent']);
        $c['issuable'] = false;
        if ($c['state'] === 'skip') continue;
        if ($prev === null) {
            $c['avail'] = $c['remaining'];
        } else {
            $p = $prev;
            $passQty = $p['excl_qc'] ? max(0.0, $p['ret'] - $p['scrap']) : $p['qc']['pass'];
            $c['avail'] = max(0.0, min($passQty - $c['sent'], $c['remaining']));
        }
        if ($c['is_customer_material']) $c['avail'] = null;
        if ($c['state'] === 'done') $c['avail'] = 0.0;
        $c['issuable'] = ($c['state'] !== 'done') && $c['remaining'] > 1e-9 && ($c['avail'] === null || $c['avail'] > 1e-9);
        if (!$suggested && $c['state'] === 'none' && $c['issuable']) { $c['suggest'] = true; $suggested = true; }
        $prev = $c;
    }
    unset($c);
    return $chain;
}

// ───────────────────────────── 廠商 ─────────────────────────────
function od_maker_info(PDO $db, string $makerNo): ?array
{
    $st = $db->prepare("SELECT maker_id_no, maker_id, maker_id_all, m_tel, factory_address, invoice_address, internal FROM maker_list WHERE maker_id_no=?");
    $st->execute([$makerNo]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['addr'] = trim((string)($r['factory_address'] ?: $r['invoice_address']));
    return $r;
}

/** 只列「加工廠」與「客戶」大類的廠商（主檔管理的大類；直接欄位或由小類反推皆算）。名稱取自大類主檔，不寫死 id。 */
function od_maker_cat_sql(string $m = 'm'): string
{
    $ids = "(SELECT main_cat_id FROM dict_maker_main_category WHERE main_cat_name IN ('加工廠','客戶'))";
    return "($m.main_category_id IN $ids OR EXISTS (SELECT 1 FROM maker_sub_category_mapping mmp JOIN maker_category_hierarchy mch ON mch.sub_cat_id=mmp.sub_cat_id
             WHERE mmp.maker_id_no=$m.maker_id_no AND mch.main_cat_id IN $ids))";
}

/** 廠商的「加工項目」文字（主檔小類名稱＋自填加工項目說明），顯示在挑廠商清單 */
function od_maker_items_sql(string $m = 'm'): string
{
    return "(SELECT GROUP_CONCAT(sc.sub_cat_name SEPARATOR '、') FROM maker_sub_category_mapping mp JOIN dict_maker_sub_category sc ON sc.sub_cat_id=mp.sub_cat_id WHERE mp.maker_id_no=$m.maker_id_no)";
}

function od_makers_decorate(array $rows): array
{
    foreach ($rows as &$r) {
        $parts = array_filter([trim((string)($r['subs'] ?? '')), trim((string)($r['m_process_items'] ?? ''))]);
        $r['items'] = implode('；', $parts);
        unset($r['subs'], $r['m_process_items']);
    }
    unset($r);
    return $rows;
}

/** 廠商搜尋（編號或名稱）；有傳 process_type_id 時標出「此製程大類歷史上用過」的常用廠商 */
function od_makers(PDO $db, string $q, int $ptype = 0): array
{
    $q = trim($q);
    $args = []; $w = "(m.status IS NULL OR m.status <> 'X') AND " . od_maker_cat_sql('m');
    if ($q !== '') { $w .= " AND (m.maker_id LIKE ? OR m.maker_id_no LIKE ? OR m.maker_id_all LIKE ?)"; array_push($args, "%$q%", "%$q%", "%$q%"); }
    $st = $db->prepare("SELECT m.maker_id_no, m.maker_id, m.m_tel, m.factory_address, m.internal, m.m_process_items, " . od_maker_items_sql('m') . " AS subs
                          FROM maker_list m WHERE $w ORDER BY m.maker_id LIMIT 80");
    $st->execute($args);
    $out = od_makers_decorate($st->fetchAll(PDO::FETCH_ASSOC));
    $used = [];
    if ($ptype > 0) {
        $u = $db->prepare("SELECT bi.maker_id_no, COUNT(*) c FROM bom_ing bi JOIN process_no pn ON pn.ProcessNo=bi.process_no
                            WHERE pn.process_type_id=? AND bi.maker_id_no IS NOT NULL AND bi.maker_id_no<>'' GROUP BY bi.maker_id_no ORDER BY c DESC");
        $u->execute([$ptype]);
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) $used[$r['maker_id_no']] = (int)$r['c'];
    }
    foreach ($out as &$m) { $m['used_for_type'] = $used[$m['maker_id_no']] ?? 0; }
    unset($m);
    return $out;
}

/** 某製程大類歷史上最常用的前 N 家廠商（廠商盤用；同樣只列加工廠／客戶大類） */
function od_common_makers(PDO $db, int $ptype, int $n = 6): array
{
    if ($ptype <= 0) return [];
    $st = $db->prepare("SELECT bi.maker_id_no, MAX(bi.maker_id) maker_id, COUNT(*) c, MAX(m.m_process_items) m_process_items, MAX(" . od_maker_items_sql('m') . ") subs
                          FROM bom_ing bi JOIN process_no pn ON pn.ProcessNo=bi.process_no JOIN maker_list m ON m.maker_id_no=bi.maker_id_no
                         WHERE pn.process_type_id=? AND bi.maker_id_no IS NOT NULL AND bi.maker_id_no<>'' AND (m.status IS NULL OR m.status<>'X') AND " . od_maker_cat_sql('m') . "
                         GROUP BY bi.maker_id_no ORDER BY c DESC LIMIT " . (int)$n);
    $st->execute([$ptype]);
    return od_makers_decorate($st->fetchAll(PDO::FETCH_ASSOC));
}

// ───────────────────────────── 發單 ─────────────────────────────
function od_next_slip_no(PDO $db, string $issueDate): string
{
    $t = strtotime($issueDate);
    $prefix = 'D' . str_pad((string)((int)date('Y', $t) - 1911), 3, '0', STR_PAD_LEFT) . date('md', $t) . '-';
    $st = $db->prepare("SELECT slip_no FROM od_slip WHERE slip_no LIKE ? FOR UPDATE");
    $st->execute([$prefix . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) { $max = max($max, (int)substr((string)$no, strlen($prefix))); } // 取數值最大，不用字串排序（>999 會錯）
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

/**
 * 發單。$items: [{bom_ing_fid, qty, maker_no, due_date?, remark?}]；依 maker_no 分組，一組一張單。
 * 全部驗證通過才寫入（同一個交易）；任一列不合法整批不寫。
 * 回傳 ['ok'=>bool, 'message'=>…, 'slips'=>[{slip_id, slip_no, maker, lines}]]
 */
function od_issue(PDO $db, array $user, array $items, string $issueDate, int $formatId = 0, int $twoPart = 0): array
{
    od_ensure_schema($db);
    if (!$items) return ['ok' => false, 'message' => '發單清單是空的'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) return ['ok' => false, 'message' => '發單日格式不正確'];
    if ($issueDate > date('Y-m-d', strtotime('+1 day'))) return ['ok' => false, 'message' => '發單日不可是未來日期'];
    $uid = (int)$user['id']; $uname = (string)($user['user_cname'] ?? '');

    $groups = [];
    foreach ($items as $i => $it) {
        $mk = trim((string)($it['maker_no'] ?? ''));
        if ($mk === '') return ['ok' => false, 'message' => '第 ' . ($i + 1) . ' 列尚未指定廠商'];
        $groups[$mk][] = $it;
    }
    $makerInfo = [];
    foreach (array_keys($groups) as $mk) {
        $mi = od_maker_info($db, $mk);
        if (!$mi) return ['ok' => false, 'message' => '找不到廠商：' . $mk];
        $makerInfo[$mk] = $mi;
    }
    $slips = [];
    try {
        $db->beginTransaction();
        $seenFid = [];
        foreach ($groups as $mk => $lines) {
            $mi = $makerInfo[$mk];
            $slipNo = od_next_slip_no($db, $issueDate);
            $db->prepare("INSERT INTO od_slip (slip_no, maker_id_no, maker_id, maker_addr, maker_tel, issue_date, format_id, two_part, issued_by, issued_by_name, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([$slipNo, $mk, $mi['maker_id'], $mi['addr'], $mi['m_tel'], $issueDate, $formatId ?: null, $twoPart ? 1 : 0, $uid, $uname]);
            $slipId = (int)$db->lastInsertId();
            $n = 0;
            foreach ($lines as $it) {
                $fid = (int)($it['bom_ing_fid'] ?? 0);
                $qty = (float)($it['qty'] ?? 0);
                if (isset($seenFid[$fid])) throw new Exception('同一個製程不可在同一次發單中重複出現（bom_ing_fid ' . $fid . '）');
                $seenFid[$fid] = 1;
                if ($qty <= 0) throw new Exception('發單量必須大於 0');
                $ls = $db->prepare("SELECT bi.bom, bi.bom_sn, bi.sqty, bi.processing_state, bi.process_no, pn.ProcessName, b.d_id AS part_no, b.Client_Name AS client
                                      FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no LEFT JOIN bom b ON b.bom=bi.bom
                                     WHERE bi.bom_ing_fid=? FOR UPDATE");
                $ls->execute([$fid]);
                $row = $ls->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new Exception('找不到製程（' . $fid . '）');
                $label = $row['bom'] . ' ' . $row['ProcessName'];
                if (!in_array($row['processing_state'], ['N', 'ing'], true)) throw new Exception($label . '：目前狀態（' . $row['processing_state'] . '）不是「待發單／加工中」，無法發單，請重新整理');
                $sentSt = $db->prepare("SELECT COALESCE(SUM(send_qty),0) FROM bom_ing_outsource_batch WHERE bom_ing_fid=?");
                $sentSt->execute([$fid]);
                $sent = (float)$sentSt->fetchColumn();
                if ((int)$row['process_no'] !== OD_CUSTOMER_MATERIAL_PROCESS) {
                    $prev = eg_bom_outsource_prev_station_qty($db, (string)$row['bom'], (int)$row['bom_sn']);
                    if (($prev['source'] ?? 'fallback') !== 'fallback' && $sent + $qty > (float)$prev['qty'] + 1e-9) {
                        throw new Exception($label . '：上一關可發 ' . $prev['qty'] . ' 件，本站已發 ' . $sent . '，這次最多只能再發 ' . max(0, (float)$prev['qty'] - $sent));
                    }
                    if ($sent + $qty > (float)$row['sqty'] + 1e-9) throw new Exception($label . '：BOM 本站總量 ' . $row['sqty'] . '，已發 ' . $sent . '，這次最多只能再發 ' . max(0, (float)$row['sqty'] - $sent) . '（要追加請先修改 BOM 數量）');
                }
                $appending = ($row['processing_state'] === 'ing' && $sent > 0);
                $transferDt = $issueDate . ' 00:00:00';
                if (!$appending) {
                    $db->prepare("UPDATE bom_ing SET processing_state = IF(qc_completed = 1 OR EXISTS(SELECT 1 FROM qc_check WHERE bom_ing_fid_ref = bom_ing.bom_ing_fid LIMIT 1), processing_state, 'ing'),
                                     outsource_date = ?, maker_id_no = ?, maker_id = ?, Modified_At = NOW(), Modified_By = ? WHERE bom_ing_fid = ?")
                       ->execute([$transferDt, $mk, $mi['maker_id'], (string)$uid, $fid]);
                }
                $priceRaw = trim((string)($it['price'] ?? ''));
                $price = null;
                if ($priceRaw !== '') {
                    if (!is_numeric($priceRaw) || (float)$priceRaw < 0) throw new Exception($label . '：單價格式不正確');
                    $price = (float)$priceRaw > 0 ? round((float)$priceRaw, 2) : null; // 0 視為沒填，不顯示 0
                }
                $batchId = eg_bom_outsource_open_batch($db, $fid, $mk, $mi['maker_id'], $qty, $issueDate, $price,
                    '批次發單 ' . $slipNo, $uid, $uname);
                $db->prepare("UPDATE bom_ing_outsource_batch SET batch_no=? WHERE batch_id=?")->execute([$slipNo, $batchId]);
                $due = trim((string)($it['due_date'] ?? ''));
                if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new Exception('交期格式不正確');
                $db->prepare("INSERT INTO od_slip_line (slip_id, sort_no, bom, part_no, client_name, bom_ing_fid, batch_id, process_no, process_name, qty, unit_price, due_date, remark)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$slipId, ++$n, $row['bom'], $row['part_no'], $row['client'], $fid, $batchId, $row['process_no'], $row['ProcessName'], $qty, $price,
                              $due !== '' ? $due : null, mb_substr(trim((string)($it['remark'] ?? '')), 0, 200)]);
            }
            $slips[] = ['slip_id' => $slipId, 'slip_no' => $slipNo, 'maker' => $mi['maker_id'], 'lines' => $n];
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    return ['ok' => true, 'message' => '已發單 ' . count($slips) . ' 張', 'slips' => $slips];
}

// ───────────────────────────── 外包加工價格 ─────────────────────────────
/** 這張 BOM 的料號身分（d_setting.d_id 優先；沒綁的用料號文字＋客戶名稱比對） */
function od_bom_part(PDO $db, string $bom): array
{
    $st = $db->prepare("SELECT d_setting_id, d_id AS part_no, Client_Name FROM bom WHERE bom=?");
    $st->execute([$bom]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $did = (int)($r['d_setting_id'] ?? 0);
    if (!$did && !empty($r['part_no'])) {
        $cn = (string)($r['Client_Name'] ?? '');
        $q = $db->prepare("SELECT d.d_id FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                            WHERE d.D_Setting_Id=? AND (c.customer=? OR ?='') ORDER BY d.d_id DESC LIMIT 1");
        $q->execute([$r['part_no'], $cn, $cn]);
        $did = (int)$q->fetchColumn();
    }
    return ['d_id' => $did, 'part_no' => (string)($r['part_no'] ?? ''), 'client' => (string)($r['Client_Name'] ?? '')];
}

/** 同料號同製程的歷史加工單價（取自製程移轉憑單；修改後單價優先），新到舊 */
function od_price_history(PDO $db, string $bom, int $processNo, int $limit = 10): array
{
    $pt = od_bom_part($db, $bom);
    $w = []; $a = [$processNo];
    if ($pt['d_id']) { $w[] = 'b.d_setting_id = ?'; $a[] = $pt['d_id']; }
    if ($pt['part_no'] !== '') { $w[] = '(b.d_id = ? AND b.Client_Name = ?)'; array_push($a, $pt['part_no'], $pt['client']); }
    if (!$w) return [];
    $st = $db->prepare("SELECT tl.transfer_date AS d, tl.maker_from AS maker_no, COALESCE(ml.maker_id, tl.maker_from) AS maker,
                               COALESCE(NULLIF(tl.modified_unit_price,0), tl.price) AS price, tl.transfer_qty AS qty, tl.bom
                          FROM bom_ing_transfer_log tl
                          JOIN bom b ON b.bom = tl.bom
                          JOIN bom_ing bi ON bi.bom = tl.bom AND bi.bom_sn = tl.bom_sn
                          LEFT JOIN maker_list ml ON ml.maker_id_no = tl.maker_from
                         WHERE bi.process_no = ? AND (" . implode(' OR ', $w) . ") AND COALESCE(NULLIF(tl.modified_unit_price,0), tl.price) > 0
                         GROUP BY tl.transfer_id ORDER BY tl.transfer_date DESC, tl.transfer_id DESC LIMIT " . (int)$limit);
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 價格表中此料號＋製程的有效項目（含詢價），核定價優先、指定廠商優先於不限廠商、新到舊 */
function od_price_table_for(PDO $db, string $bom, int $processNo): array
{
    $pt = od_bom_part($db, $bom);
    if (!$pt['d_id']) return [];
    $st = $db->prepare("SELECT p.*, COALESCE(ml.maker_id,'') AS maker FROM od_price p LEFT JOIN maker_list ml ON ml.maker_id_no=p.maker_id_no
                         WHERE p.d_id=? AND p.process_no=? AND p.status='active'
                           AND (p.valid_from IS NULL OR p.valid_from<=CURDATE()) AND (p.valid_to IS NULL OR p.valid_to>=CURDATE())
                         ORDER BY (p.kind='approved') DESC, (p.maker_id_no IS NOT NULL AND p.maker_id_no<>'') DESC, p.valid_from DESC, p.price_id DESC");
    $st->execute([$pt['d_id'], $processNo]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 發單時自動帶入的單價：只用「老闆核定」價；指定了廠商就先找該廠商的專屬價，沒有再用不限廠商價 */
function od_price_suggest(PDO $db, string $bom, int $processNo, string $makerNo = ''): array
{
    $tbl = od_price_table_for($db, $bom, $processNo);
    $pick = null;
    foreach ($tbl as $r) {
        if ($r['kind'] !== 'approved') continue;
        $mk = (string)$r['maker_id_no'];
        if ($makerNo !== '' && $mk === $makerNo) { $pick = $r; break; }
        if ($mk === '' && !$pick) $pick = $r;
    }
    $hist = od_price_history($db, $bom, $processNo, 6);
    return ['approved' => $pick, 'table' => $tbl, 'history' => $hist, 'last' => $hist[0] ?? null];
}

function od_price_list(PDO $db, array $f): array
{
    od_ensure_schema($db);
    $w = ["p.status='active'"]; $a = [];
    if (!empty($f['void'])) $w = ['1=1'];
    if (!empty($f['process_no'])) { $w[] = 'p.process_no=?'; $a[] = (int)$f['process_no']; }
    if (!empty($f['kind']) && in_array($f['kind'], ['approved', 'inquiry'], true)) { $w[] = 'p.kind=?'; $a[] = $f['kind']; }
    $k = trim((string)($f['kw'] ?? ''));
    if ($k !== '') { $w[] = "(p.part_no LIKE ? OR p.client_name LIKE ? OR ml.maker_id LIKE ? OR p.note LIKE ?)"; array_push($a, "%$k%", "%$k%", "%$k%", "%$k%"); }
    $st = $db->prepare("SELECT p.*, pn.ProcessName, COALESCE(ml.maker_id,'') AS maker
                          FROM od_price p LEFT JOIN process_no pn ON pn.ProcessNo=p.process_no LEFT JOIN maker_list ml ON ml.maker_id_no=p.maker_id_no
                         WHERE " . implode(' AND ', $w) . " ORDER BY p.part_no, p.process_no, (p.kind='approved') DESC, p.valid_from DESC LIMIT 300");
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 新增／修改一筆價格。$in: price_id(0=新增), d_id, process_no, maker_id_no, unit_price, kind, qty_note, valid_from, valid_to, note */
function od_price_save(PDO $db, array $user, array $in): array
{
    od_ensure_schema($db);
    $did = (int)($in['d_id'] ?? 0);
    $pno = (int)($in['process_no'] ?? 0);
    $price = (float)($in['unit_price'] ?? 0);
    $kind = ($in['kind'] ?? '') === 'inquiry' ? 'inquiry' : 'approved';
    $mk = trim((string)($in['maker_id_no'] ?? ''));
    if (!$did) return ['ok' => false, 'message' => '請從料號主檔選擇料號'];
    if (!$pno) return ['ok' => false, 'message' => '請選擇製程'];
    if ($price <= 0) return ['ok' => false, 'message' => '單價必須大於 0'];
    if ($kind === 'inquiry' && $mk === '') return ['ok' => false, 'message' => '廠商詢價結果必須指定是哪一家廠商'];
    foreach (['valid_from', 'valid_to'] as $k) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return ['ok' => false, 'message' => '日期格式不正確'];
    }
    $vf = trim((string)($in['valid_from'] ?? '')) ?: null;
    $vt = trim((string)($in['valid_to'] ?? '')) ?: null;
    if ($vf && $vt && $vt < $vf) return ['ok' => false, 'message' => '有效迄日不可早於起日'];
    if ($mk !== '' && !od_maker_info($db, $mk)) return ['ok' => false, 'message' => '找不到廠商'];
    $ds = $db->prepare("SELECT d.D_Setting_Id, c.customer FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id WHERE d.d_id=?");
    $ds->execute([$did]);
    $d = $ds->fetch(PDO::FETCH_ASSOC);
    if (!$d) return ['ok' => false, 'message' => '找不到料號主檔'];
    $uid = (int)$user['id']; $un = (string)($user['user_cname'] ?? '');
    $id = (int)($in['price_id'] ?? 0);
    $vals = [$did, $d['D_Setting_Id'], (string)$d['customer'], $pno, $mk !== '' ? $mk : null, round($price, 2), $kind,
             mb_substr(trim((string)($in['qty_note'] ?? '')), 0, 60), $vf, $vt, mb_substr(trim((string)($in['note'] ?? '')), 0, 200)];
    if ($id > 0) {
        $db->prepare("UPDATE od_price SET d_id=?, part_no=?, client_name=?, process_no=?, maker_id_no=?, unit_price=?, kind=?, qty_note=?, valid_from=?, valid_to=?, note=?, updated_at=NOW() WHERE price_id=?")
           ->execute(array_merge($vals, [$id]));
    } else {
        $db->prepare("INSERT INTO od_price (d_id, part_no, client_name, process_no, maker_id_no, unit_price, kind, qty_note, valid_from, valid_to, note, created_by, created_by_name, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute(array_merge($vals, [$uid, $un]));
        $id = (int)$db->lastInsertId();
    }
    if ($kind === 'approved') $db->prepare("UPDATE od_price SET approved_by=?, approved_at=IFNULL(approved_at,NOW()) WHERE price_id=?")->execute([$un, $id]);
    return ['ok' => true, 'price_id' => $id];
}

// ───────────────────────────── 單據讀取／列印 ─────────────────────────────
function od_slip_get(PDO $db, int $slipId): ?array
{
    $st = $db->prepare("SELECT * FROM od_slip WHERE slip_id=?");
    $st->execute([$slipId]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h) return null;
    $ls = $db->prepare("SELECT l.*, bi.ps AS bing_ps, bi.PS2 AS bing_ps2 FROM od_slip_line l LEFT JOIN bom_ing bi ON bi.bom_ing_fid=l.bom_ing_fid WHERE l.slip_id=? ORDER BY l.sort_no");
    $ls->execute([$slipId]);
    $h['lines'] = $ls->fetchAll(PDO::FETCH_ASSOC);
    return $h;
}

function od_slips_list(PDO $db, array $f): array
{
    od_ensure_schema($db);
    $w = ['1=1']; $a = [];
    if (!empty($f['from'])) { $w[] = 's.issue_date >= ?'; $a[] = $f['from']; }
    if (!empty($f['to'])) { $w[] = 's.issue_date <= ?'; $a[] = $f['to']; }
    $k = trim((string)($f['kw'] ?? ''));
    if ($k !== '') {
        $w[] = "(s.slip_no LIKE ? OR s.maker_id LIKE ? OR EXISTS (SELECT 1 FROM od_slip_line l WHERE l.slip_id=s.slip_id AND (l.bom LIKE ? OR l.part_no LIKE ?)))";
        array_push($a, "%$k%", "%$k%", "%$k%", "%$k%");
    }
    $limit = max(1, min(200, (int)($f['limit'] ?? 50)));
    $st = $db->prepare("SELECT s.*, (SELECT COUNT(*) FROM od_slip_line l WHERE l.slip_id=s.slip_id) AS line_count,
                               (SELECT COALESCE(SUM(qty),0) FROM od_slip_line l WHERE l.slip_id=s.slip_id) AS total_qty
                          FROM od_slip s WHERE " . implode(' AND ', $w) . " ORDER BY s.slip_id DESC LIMIT $limit");
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 列印版需要的全部資料：公司全名、AS 表單名稱與編號（版次依發單日回推）、格式、單據 */
function od_print_payload(PDO $db, int $slipId): ?array
{
    require_once __DIR__ . '/asdoc_lib.php';
    od_ensure_schema($db);
    $s = od_slip_get($db, $slipId);
    if (!$s) return null;
    $fm = null;
    if ($s['format_id']) {
        $q = $db->prepare("SELECT * FROM od_format WHERE format_id=?"); $q->execute([(int)$s['format_id']]);
        $fm = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$fm) $fm = $db->query("SELECT * FROM od_format WHERE is_default=1 AND is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    $cfg = od_format_normalize($fm ? (json_decode((string)$fm['config_json'], true) ?: []) : []);
    $company = '';
    try { $company = (string)$db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}
    $doc = eg_asdoc_get($db, 'od_slip');
    return [
        'slip' => $s, 'config' => $cfg, 'format_name' => $fm['name'] ?? '',
        'company' => $company,
        'form_name' => $doc['doc_name'] ?? '外發加工單',
        'doc_no' => eg_asdoc_no_asof($db, 'od_slip', $s['issue_date']),
        'columns' => od_format_columns(),
    ];
}

/** 列印紀錄：寫全站 print_log（來源 od_slip）並累計本張單的列印次數 */
function od_print_record(PDO $db, int $slipId): array
{
    require_once __DIR__ . '/print_log_lib.php';
    $s = od_slip_get($db, $slipId);
    if (!$s) return ['ok' => false, 'message' => '找不到單據'];
    $parts = [];
    foreach ($s['lines'] as $l) { if ($l['part_no'] !== null && $l['part_no'] !== '') $parts[$l['part_no']] = 1; }
    eg_print_log_add($db, ['source' => 'od_slip', 'doc_kind' => 'form', 'doc_name' => '外發加工單 ' . $s['slip_no'] . ' ' . $s['maker_id'],
        'ref_table' => 'od_slip', 'ref_id' => (string)$slipId, 'part_no' => implode('、', array_slice(array_keys($parts), 0, 5))]);
    $db->prepare("UPDATE od_slip SET print_count = print_count + 1, last_printed_at = NOW() WHERE slip_id=?")->execute([$slipId]);
    $n = (int)$db->query("SELECT print_count FROM od_slip WHERE slip_id=" . (int)$slipId)->fetchColumn();
    return ['ok' => true, 'print_count' => $n];
}
