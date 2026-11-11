<?php
/**
 * qa_abnormal_remake_lib.php — 品質異常處理單「報廢後業務回填：補做／不補」（2026-11-05）
 *
 * 流程：異常單最終決策含報廢 → 通知業務人員 → 業務回填「補做 N PCS（含備註）」或「不補」。
 *   · 不補：只留紀錄，BOM總覽／退貨追蹤／訂單追蹤的備註欄顯示「報廢不補」。
 *   · 補做：自動在訂單追蹤建立一張新訂單（單價 0，其餘沿用原訂單）。
 *       原訂單判定：該製令在 bom_order_process_map 只綁定一張訂單 → 沿用其訂單編號與資料；
 *       沒綁定或綁定多張（無法判定）→ 訂單編號 NA，料號／製程／交貨日期由回填的業務人員補齊才建單。
 *   · 自動建立的訂單刪除一律要填原因，刪除後這筆回填改成「報廢不補」（見 qab_remake_on_order_deleted）。
 *   · 通知對象是多人，其中一人回覆後，其他人還掛著的通知立刻收掉（qab_remake_retire_notices）。
 *
 * 只對「新判定報廢」的單生效（hook 在 qab_scrap_no_sync／qab_close_order），不回頭動既有已結案的單。
 * 測試時 define('QAB_SILENT', true) 即不發任何通知。
 */

if (!function_exists('qab_remake_schema')) {

function qab_remake_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS qab_remake (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL COMMENT 'qa_abnormal_order.id',
        bom_no VARCHAR(64) NULL, ir_id INT NULL,
        scrap_qty INT NULL COMMENT '判定報廢的數量(PCS)',
        status VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending待回覆/remake補做/skip不補/cancelled(決策改回非報廢)',
        remake_qty INT NULL, note VARCHAR(1000) NULL,
        answered_by INT NULL, answered_at DATETIME NULL,
        src_order_id INT NULL COMMENT '判定出的原訂單 order_track.Order_id；NULL=無法判定(NA)',
        src_why VARCHAR(200) NULL,
        new_order_id INT NULL COMMENT '自動建立的訂單',
        notify_uids TEXT NULL COMMENT '被通知可回覆的人員 id(JSON)',
        cancel_reason VARCHAR(500) NULL, cancel_by INT NULL, cancel_at DATETIME NULL COMMENT '補做訂單被刪除時',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        UNIQUE KEY uk_order (order_id), KEY idx_new (new_order_id), KEY idx_src (src_order_id), KEY idx_bom (bom_no), KEY idx_ir (ir_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='異常單報廢後業務回填補做／不補'");
}

function qab_remake_uname(PDO $db, int $uid): string
{
    if ($uid <= 0) return '';
    $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
    $st->execute([$uid]);
    return (string)$st->fetchColumn();
}

function qab_remake_row(PDO $db, int $orderId): ?array
{
    $st = $db->prepare("SELECT * FROM qab_remake WHERE order_id=?");
    $st->execute([$orderId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** 這筆異常單的報廢數量：扣款數量 > 異常總數 > 不良數 */
function qab_remake_scrap_qty(array $o): int
{
    foreach (['deduct_qty', 'abn_total_qty', 'ng_qty'] as $k) {
        $v = (float)($o[$k] ?? 0);
        if ($v > 0) return (int)round($v);
    }
    return 0;
}

/**
 * 判定「原訂單」：製令在 bom_order_process_map 只綁一張訂單才算判定得出來；
 * 沒綁、綁多張、或只有退貨單沒有製令 → 無法判定（訂單編號顯示 NA）。
 * @return array ['row'=>order_track 一列|null, 'why'=>說明]
 */
function qab_remake_src(PDO $db, array $o): array
{
    $bom = trim((string)($o['bom_no'] ?? ''));
    if ($bom === '') {
        $l = (array)($o['bom_list'] ?? []);
        $bom = trim((string)($l[0] ?? ''));
    }
    if ($bom === '') return ['row' => null, 'why' => '這張單沒有綁製令，無法判定原訂單'];
    $st = $db->prepare("SELECT DISTINCT m.order_id FROM bom_order_process_map m
                        JOIN order_track t ON t.Order_id = m.order_id WHERE m.bom = ?");
    $st->execute([$bom]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) === 0) {
        // bom.o_order_id 是 order_track.Order_id（整數主鍵）；舊資料可能有殘值，必須真的對得到訂單才採用
        $st = $db->prepare("SELECT o_order_id FROM bom WHERE bom=?");
        $st->execute([$bom]);
        $oid = (string)$st->fetchColumn();
        if ($oid !== '' && ctype_digit($oid)) {
            $c = $db->prepare("SELECT 1 FROM order_track WHERE Order_id=?");
            $c->execute([(int)$oid]);
            if ($c->fetchColumn()) $ids = [(int)$oid];
        }
    }
    if (count($ids) === 0) return ['row' => null, 'why' => '製令 ' . $bom . ' 沒有綁定訂單'];
    if (count($ids) > 1) return ['row' => null, 'why' => '製令 ' . $bom . ' 綁定了 ' . count($ids) . ' 張訂單，無法判定是哪一張'];
    $st = $db->prepare("SELECT * FROM order_track WHERE Order_id=?");
    $st->execute([$ids[0]]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['row' => $r, 'why' => ''] : ['row' => null, 'why' => '原訂單已不存在'];
}

/** 被通知、可以回覆的業務人員：報廢通知(扣款填寫)名單 ∩ 業務部門；沒有交集就退回整個業務部門；原訂單的打單人員一併納入 */
function qab_remake_responders(PDO $db, ?array $srcRow = null): array
{
    require_once __DIR__ . '/org_role_lib.php';
    require_once __DIR__ . '/people_lib.php';
    $salesDept = array_map('intval', eg_org_dept_ids($db, 'sales_dept'));
    $sales = [];
    if ($salesDept) foreach (eg_people_list($db, ['dept_ids' => $salesDept]) as $p) $sales[(int)$p['id']] = 1;
    $ids = [];
    foreach (qab_deduct_notify_ids($db) as $u) if (isset($sales[(int)$u])) $ids[(int)$u] = 1;
    if (!$ids) $ids = $sales;
    if ($srcRow && (int)($srcRow['Created_By'] ?? 0) > 0) {
        $c = $db->prepare("SELECT 1 FROM `user` WHERE id=? AND state=1");
        $c->execute([(int)$srcRow['Created_By']]);
        if ($c->fetchColumn()) $ids[(int)$srcRow['Created_By']] = 1;
    }
    return array_keys($ids);
}

function qab_remake_notice_title(string $no): string { return '報廢補做待回覆：' . $no; }

/** 一人回覆後，收掉其他人還掛著的待回覆通知（比照 qab_retire_pending_notices：結束日改成昨天，並替回覆者補上已閱） */
function qab_remake_retire_notices(PDO $db, int $orderId, int $actorUid): int
{
    $n = 0;
    try {
        $st = $db->prepare("SELECT id FROM live_event WHERE ref_type='QAB_L1' AND ref_id=? AND title LIKE ?
                            AND (enddate IS NULL OR enddate >= CURDATE())");
        $st->execute([$orderId, '報廢補做待回覆：%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $eid = (int)$eid;
            $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=?")->execute([$eid]);
            $n++;
            if ($actorUid > 0) {
                $rs = $db->prepare("SELECT id FROM live_event_response WHERE live_event_id=? AND user_id=?");
                $rs->execute([$eid, $actorUid]);
                if ($rid = $rs->fetchColumn()) {
                    $db->prepare("UPDATE live_event_response SET read_at=COALESCE(read_at,NOW()), signed_at=COALESCE(signed_at,NOW()) WHERE id=?")->execute([$rid]);
                } else {
                    $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at) VALUES (?,?,NOW(),NOW())")->execute([$eid, $actorUid]);
                }
            }
        }
    } catch (Throwable $e) { error_log('qab_remake_retire_notices: ' . $e->getMessage()); }
    return $n;
}

/**
 * 判定報廢 → 建立「待業務回覆」並通知；決策改回非報廢 → 把還沒回覆的待回覆作廢（已回覆／已建單的不動）。
 * 冪等：同一張單只會有一筆。補資料（舊紙本）的單不觸發。失敗不可影響決策存檔。
 */
function qab_remake_ensure(PDO $db, int $orderId, int $actorUid = 0): void
{
    try {
        qab_remake_schema($db);
        $o = qab_order($db, $orderId);
        if (!$o) return;
        $row = qab_remake_row($db, $orderId);
        $isScrap = !empty($o['final']['is_scrap']) && empty($o['split_children']);
        if (!$isScrap) {
            if ($row && $row['status'] === 'pending') {
                $db->prepare("UPDATE qab_remake SET status='cancelled', updated_at=NOW() WHERE id=? AND status='pending'")->execute([$row['id']]);
                qab_remake_retire_notices($db, $orderId, 0);
            }
            return;
        }
        if (!empty($o['is_backfill'])) return;
        if ($row) {
            if ($row['status'] === 'cancelled') {   // 又改回報廢：重新請業務回覆
                $db->prepare("UPDATE qab_remake SET status='pending', updated_at=NOW() WHERE id=? AND status='cancelled'")->execute([$row['id']]);
                qab_remake_notify($db, $o, qab_remake_row($db, $orderId), $actorUid);
            }
            return;
        }
        $src = qab_remake_src($db, $o);
        $uids = qab_remake_responders($db, $src['row']);
        $bom = trim((string)($o['bom_no'] ?? ''));
        $ins = $db->prepare("INSERT IGNORE INTO qab_remake (order_id, bom_no, ir_id, scrap_qty, status, src_order_id, src_why, notify_uids)
                             VALUES (?,?,?,?, 'pending', ?, ?, ?)");
        $ins->execute([$orderId, $bom !== '' ? $bom : null, (int)($o['ir_id'] ?? 0) ?: null, qab_remake_scrap_qty($o) ?: null,
                       $src['row'] ? (int)$src['row']['Order_id'] : null, $src['why'] !== '' ? $src['why'] : null,
                       $uids ? json_encode($uids) : null]);
        if ($ins->rowCount() < 1) return;   // 並發：別的請求已經建了
        if (qab_remake_auto_skip($db, $o)) return;   // 客供料（第一關 138）的自動開立單：自動判定不補，不必再請業務回覆
        qab_remake_notify($db, $o, qab_remake_row($db, $orderId), $actorUid);
    } catch (Throwable $e) { error_log('qab_remake_ensure: ' . $e->getMessage()); }
}

/** 製令的第一關（最小 bom_sn）製程編號；查不到回 0 */
function qab_remake_first_proc(PDO $db, string $bom): int
{
    if ($bom === '') return 0;
    $st = $db->prepare("SELECT process_no FROM bom_ing WHERE bom=? ORDER BY bom_sn, bom_ing_fid LIMIT 1");
    $st->execute([$bom]);
    return (int)$st->fetchColumn();
}

/** 業務部門裡「今天有上班（沒請假）且沒有公出」的人，隨機挑一位；沒有符合的回 0 */
function qab_remake_pick_sales_on_duty(PDO $db): int
{
    require_once __DIR__ . '/org_role_lib.php';
    require_once __DIR__ . '/people_lib.php';
    require_once __DIR__ . '/person_schedule_lib.php';
    $salesDept = array_map('intval', eg_org_dept_ids($db, 'sales_dept'));
    if (!$salesDept) return 0;
    $ids = [];
    foreach (eg_people_list($db, ['dept_ids' => $salesDept]) as $p) $ids[(int)$p['id']] = 1;
    $ids = array_keys($ids);
    if (!$ids) return 0;
    $today = (string)$db->query("SELECT CURDATE()")->fetchColumn();
    $workday = true;
    if (function_exists('eg_leave_is_workday')) { try { $workday = (bool)eg_leave_is_workday($db, $today); } catch (Throwable $e) {} }
    if (!$workday) return 0;
    $busy = eg_psched_for_users($db, $ids, $today);
    $ok = [];
    foreach ($ids as $u) {
        $hit = false;
        foreach ($busy[$u] ?? [] as $it) if (in_array($it['source'] ?? '', ['leave', 'trip'], true)) { $hit = true; break; }
        if (!$hit) $ok[] = $u;
    }
    return $ok ? $ok[array_rand($ok)] : 0;
}

/**
 * 自動開立的報廢異常單，若製令第一關是 138 客供料：NG 品交回客戶、不用補做 → 自動回填「不補」，
 * 回填人（畫面上的簽章）＝當天有上班且沒公出的任一位業務。找不到人就維持待業務回覆。
 * @return bool 已自動回填
 */
function qab_remake_auto_skip(PDO $db, array $o): bool
{
    try {
        if (empty($o['auto_opened'])) return false;
        $bom = trim((string)($o['bom_no'] ?? ''));
        if ($bom === '' || qab_remake_first_proc($db, $bom) !== 138) return false;
        $uid = qab_remake_pick_sales_on_duty($db);
        if ($uid <= 0) return false;
        qab_remake_respond($db, (int)$o['id'], $uid, 'skip', ['note' => '客供料NG品交回客戶，不用補'], true);
        return true;
    } catch (Throwable $e) { error_log('qab_remake_auto_skip: ' . $e->getMessage()); return false; }
}

function qab_remake_notify(PDO $db, array $o, ?array $row, int $actorUid): void
{
    if (!$row || defined('QAB_SILENT')) return;
    $uids = json_decode((string)$row['notify_uids'], true);
    if (!is_array($uids) || !$uids) return;
    $no = (string)$o['abnormal_order_no'];
    $src = (!empty($o['bom_no']) ? '製令 ' . $o['bom_no'] : '') . (!empty($o['ir_no']) ? ' 退貨單 ' . $o['ir_no'] : '');
    qab_quick_notify($db, (int)$o['id'], $uids, qab_remake_notice_title($no),
        '異常單 ' . $no . ($src !== '' ? '（' . trim($src) . '）' : '') . ' 已判定報廢 ' . (int)$row['scrap_qty']
        . ' PCS，請業務回填「需要補做幾 PCS」或「不補」。多人被通知時，其中一人回覆即可。', $actorUid);
}

/** 顯示用文字 */
function qab_remake_label(array $r): string
{
    if ($r['status'] === 'remake') return '報廢補做 ' . (int)$r['remake_qty'] . ' PCS';
    if ($r['status'] === 'skip') return '報廢不補';
    if ($r['status'] === 'pending') return '報廢待業務回覆';
    return '';
}

/** 給異常單處理頁：這張單的補做回填狀態（含可回覆者判斷所需資訊） */
function qab_remake_view(PDO $db, array $o, int $uid = 0, bool $isAdmin = false): ?array
{
    $row = qab_remake_row($db, (int)$o['id']);
    if (!$row || $row['status'] === 'cancelled') return null;
    $uids = json_decode((string)$row['notify_uids'], true);
    $uids = is_array($uids) ? array_map('intval', $uids) : [];
    $names = [];
    foreach ($uids as $u) { $n = qab_remake_uname($db, $u); if ($n !== '') $names[] = $n; }
    $v = [
        'status' => $row['status'], 'label' => qab_remake_label($row), 'scrap_qty' => (int)$row['scrap_qty'],
        'remake_qty' => $row['remake_qty'] === null ? null : (int)$row['remake_qty'], 'note' => (string)$row['note'],
        'who' => qab_remake_uname($db, (int)$row['answered_by']), 'at' => (string)$row['answered_at'],
        'src_order_id' => $row['src_order_id'] ? (int)$row['src_order_id'] : null, 'src_why' => (string)$row['src_why'],
        'new_order_id' => $row['new_order_id'] ? (int)$row['new_order_id'] : null,
        'cancel_reason' => (string)$row['cancel_reason'], 'cancel_at' => (string)$row['cancel_at'],
        'cancel_who' => qab_remake_uname($db, (int)$row['cancel_by']),
        'notify_names' => $names,
        'can_respond' => $row['status'] === 'pending' && ($isAdmin || in_array($uid, $uids, true)),
        'src_oo' => '', 'new_oo' => '', 'prefill' => null,
    ];
    foreach (['src_order_id' => 'src_oo', 'new_order_id' => 'new_oo'] as $k => $kk) {
        if ($v[$k]) {
            $c = $db->prepare("SELECT Order_oo FROM order_track WHERE Order_id=?");
            $c->execute([$v[$k]]);
            $v[$kk] = (string)$c->fetchColumn();
        }
    }
    if ($v['can_respond'] && !$v['src_order_id']) {
        // 無法判定原訂單：料號先由製令／異常單帶入，讓業務確認或改選，其餘（製程、交貨日）一定要自己填
        $did = (int)($o['part_d_id'] ?? 0);
        if ($did <= 0 && !empty($o['bom_no'])) {
            $c = $db->prepare("SELECT d_setting_id FROM bom WHERE bom=?");
            $c->execute([$o['bom_no']]);
            $did = (int)$c->fetchColumn();
        }
        if ($did > 0) {
            $c = $db->prepare("SELECT d.d_id, d.D_Setting_Id, cl.customer FROM d_setting d LEFT JOIN customer_list cl ON cl.customer_id=d.Customer_Id WHERE d.d_id=?");
            $c->execute([$did]);
            $p = $c->fetch(PDO::FETCH_ASSOC);
            if ($p) $v['prefill'] = ['d_id' => (int)$p['d_id'], 'part_no' => (string)$p['D_Setting_Id'], 'client' => (string)$p['customer']];
        }
    }
    return $v;
}

/**
 * 業務回覆。$mode='remake'|'skip'。$in：qty、note；原訂單無法判定時另需 part_d_id、process、delivery。
 * 補做 → 建立訂單（單價 0）；回傳 ['row'=>…, 'new_order_id'=>?int]。失敗一律丟例外且不改任何資料。
 */
function qab_remake_respond(PDO $db, int $orderId, int $uid, string $mode, array $in, bool $isAdmin = false): array
{
    qab_remake_schema($db);
    $o = qab_order($db, $orderId);
    $row = $o ? qab_remake_row($db, $orderId) : null;
    if (!$row) throw new Exception('這張異常單沒有待回覆的補做事項');
    if ($row['status'] !== 'pending') throw new Exception('這筆已經回覆過了（' . qab_remake_label($row) . '），請重新整理頁面');
    $uids = json_decode((string)$row['notify_uids'], true);
    if (!$isAdmin && !(is_array($uids) && in_array($uid, array_map('intval', $uids), true))) throw new Exception('您不在被通知的業務人員名單內');
    if (!in_array($mode, ['remake', 'skip'], true)) throw new Exception('請選擇「補做」或「不補」');
    $note = trim((string)($in['note'] ?? ''));
    if (mb_strlen($note) > 1000) throw new Exception('備註最多 1000 字');
    $uname = qab_remake_uname($db, $uid);

    $qty = 0; $newFields = null;
    if ($mode === 'remake') {
        $qs = trim((string)($in['qty'] ?? ''));
        if ($qs === '' || !ctype_digit($qs) || (int)$qs <= 0) throw new Exception('補做數量請填大於 0 的整數 (PCS)');
        $qty = (int)$qs;
        $newFields = qab_remake_build_order_fields($db, $o, $row, $in, $qty, $uid, $uname, $note);   // 驗證不過會丟例外，先於任何寫入
    }
    $db->beginTransaction();
    try {
        $claim = $db->prepare("UPDATE qab_remake SET status=?, remake_qty=?, note=?, answered_by=?, answered_at=NOW(), updated_at=NOW()
                               WHERE id=? AND status='pending'");
        $claim->execute([$mode, $mode === 'remake' ? $qty : null, $note !== '' ? $note : null, $uid, $row['id']]);
        if ($claim->rowCount() < 1) throw new Exception('別人剛剛已經回覆了，請重新整理頁面');
        $newId = null;
        if ($mode === 'remake') {
            $cols = array_keys($newFields);
            $sql = "INSERT INTO order_track SET " . implode(', ', array_map(function ($c) { return "`$c` = :$c"; }, $cols))
                 . ", Created_At = NOW()";
            $db->prepare($sql)->execute(array_combine(array_map(function ($c) { return ':' . $c; }, $cols), array_values($newFields)));
            $newId = (int)$db->lastInsertId();
            $db->prepare("UPDATE qab_remake SET new_order_id=? WHERE id=?")->execute([$newId, $row['id']]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    qab_remake_retire_notices($db, $orderId, $uid);   // 多人被通知：一人回覆，其他人的通知收掉
    if (!empty($newId) && function_exists('ot_auto_pmget_apply')) { try { ot_auto_pmget_apply($db, $newId); } catch (Throwable $e) {} }
    return ['row' => qab_remake_row($db, $orderId), 'new_order_id' => $newId ?? null];
}

/** 組出要寫進 order_track 的欄位；原訂單判定得出來就沿用，否則（NA）要求業務補齊料號／製程／交貨日 */
function qab_remake_build_order_fields(PDO $db, array $o, array $row, array $in, int $qty, int $uid, string $uname, string $note): array
{
    $src = null;
    if ((int)$row['src_order_id'] > 0) {
        $st = $db->prepare("SELECT * FROM order_track WHERE Order_id=?");
        $st->execute([(int)$row['src_order_id']]);
        $src = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $delivery = trim((string)($in['delivery'] ?? ''));
    if ($delivery !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery)) throw new Exception('交貨日期格式不正確');
    if ($src) {
        $f = [
            'Order_oo' => $src['Order_oo'], 'd_id' => $src['d_id'], 'd_id_ID' => $src['d_id_ID'],
            'Client_name' => $src['Client_name'], 'Client_name_ID' => $src['Client_name_ID'],
            'Processing_items' => $src['Processing_items'], 'Delivery_date' => $delivery !== '' ? $delivery : $src['Delivery_date'],
            'C_order' => $src['C_order'], 'Containers' => $src['Containers'], 'Sample' => $src['Sample'], 'JIG' => $src['JIG'],
            'ate' => $src['ate'], 'ateGet' => $src['ateGet'], 'drop_zone' => $src['drop_zone'],
            'is_urgent' => (int)($src['is_urgent'] ?? 0), 'need_design_draw' => (int)($src['need_design_draw'] ?? 0),
            'need_sample_draw' => (int)($src['need_sample_draw'] ?? 0), 'bom_filter_tag' => $src['bom_filter_tag'] ?? null,
        ];
    } else {
        // 無法判定原訂單：訂單編號 NA，料號／製程／交貨日期必須由業務填寫完整（但一定要建立訂單）
        $did = (int)($in['part_d_id'] ?? 0);
        if ($did <= 0) throw new Exception('無法判定原訂單，請從清單選擇料號（必填）');
        $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id, d.Customer_Id, cl.customer FROM d_setting d LEFT JOIN customer_list cl ON cl.customer_id=d.Customer_Id WHERE d.d_id=?");
        $st->execute([$did]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new Exception('選擇的料號不存在，請重新選擇');
        $proc = trim((string)($in['process'] ?? ''));
        if ($proc === '') throw new Exception('無法判定原訂單，請填寫製程（必填）');
        if ($delivery === '') throw new Exception('無法判定原訂單，請填寫交貨日期（必填）');
        $f = [
            'Order_oo' => 'NA', 'd_id' => $p['D_Setting_Id'], 'd_id_ID' => (int)$p['d_id'],
            'Client_name' => (string)$p['customer'], 'Client_name_ID' => $p['Customer_Id'],
            'Processing_items' => mb_substr($proc, 0, 255), 'Delivery_date' => $delivery,
        ];
    }
    // 時間一律取資料庫的（PHP 的 date() 是 UTC，混用會差 8 小時甚至差一天）
    $nowRow = $db->query("SELECT CURDATE() AS d, DATE_FORMAT(NOW(), '%Y.%m.%d') AS dot")->fetch(PDO::FETCH_ASSOC);
    // 業務備註（多行，欄位內自動換列）：製令／補做數量／異常單號／業務與日期／備註
    $srcLine = !empty($o['bom_no']) ? (string)$o['bom_no'] : (!empty($o['ir_no']) ? '退貨單 ' . $o['ir_no'] : '');
    $ps = $srcLine . "
報廢補做 " . $qty . " PCS
" . $o['abnormal_order_no'] . "
" . $uname . ' ' . $nowRow['dot']
        . ($note !== '' ? "
PS：" . $note : '');
    $f += ['Order_ps' => $ps, 'Qty' => $qty, 'Order_date' => $nowRow['d'], 'unit_price' => 0,
           'split_seq' => 1, 'Created_By' => $uid];
    return $f;
}

/** 這張訂單是不是異常單自動建立的補做訂單（刪除前檢查用） */
function qab_remake_by_new_order(PDO $db, int $orderId): ?array
{
    qab_remake_schema($db);
    $st = $db->prepare("SELECT r.*, o.abnormal_order_no FROM qab_remake r JOIN qa_abnormal_order o ON o.id=r.order_id WHERE r.new_order_id=? AND r.status='remake'");
    $st->execute([$orderId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** 補做訂單被刪除（已填原因）→ 這筆回填改成「報廢不補」，原因與刪除人留存 */
function qab_remake_on_order_deleted(PDO $db, int $orderId, string $reason, int $uid): void
{
    try {
        $db->prepare("UPDATE qab_remake SET status='skip', remake_qty=NULL, new_order_id=NULL, cancel_reason=?, cancel_by=?, cancel_at=NOW(), updated_at=NOW()
                      WHERE new_order_id=? AND status='remake'")->execute([mb_substr($reason, 0, 500), $uid, $orderId]);
    } catch (Throwable $e) { error_log('qab_remake_on_order_deleted: ' . $e->getMessage()); }
}

/* ───────────── 顯示：BOM總覽 / 退貨追蹤 / 訂單追蹤 ───────────── */

function qab_remake_tag(array $r, string $no, string $uname): array
{
    $st = $r['status'];
    $pal = $st === 'remake' ? ['#F0A24B', '#3b2203'] : ($st === 'skip' ? ['#8C5A2B', '#ffffff'] : ['#DD5138', '#ffffff']);
    $ts = (string)($r['answered_at'] ?: $r['created_at']);
    return [
        'kind' => 'remake', 'name' => qab_remake_label($r) . ($st === 'skip' && $r['cancel_reason'] ? '（補做訂單已刪除）' : ''),
        'md' => $ts !== '' ? date('n/j', strtotime($ts)) : '', 'process' => $no,
        'qty' => $st === 'remake' ? (int)$r['remake_qty'] : (int)$r['scrap_qty'], 'who' => $uname,
        'bg' => $pal[0], 'fg' => $pal[1], 'no' => $no . (trim((string)$r['note']) !== '' ? '｜業務備註：' . $r['note'] : ''), 'id' => (int)$r['order_id'], 'sn' => 9000, 'ts' => $ts,
        'note' => (string)$r['note'],
        // BOM總覽用：主行「報廢 M/D x數量」＋縮排子行「└ 業務回覆補件／補做／不補 異常單號」（舊欄位照舊，其他頁不受影響）
        'main_name' => '報廢', 'main_qty' => (int)$r['scrap_qty'],
        'sub' => ($st === 'remake' ? '補做 ' . (int)$r['remake_qty'] . ' PCS' : ($st === 'skip' ? '不補' . ($r['cancel_reason'] ? '（補做訂單已刪除）' : '') : '待業務確認補件'))
                 . ($uname !== '' && $st !== 'pending' ? ' (' . $uname . ')' : '') . ' ' . $no,
        'sub_text' => ($st === 'remake' ? '補做 ' . (int)$r['remake_qty'] . ' PCS' : ($st === 'skip' ? '不補' . ($r['cancel_reason'] ? '（補做訂單已刪除）' : '') : '待業務確認補件'))
                 . ($uname !== '' && $st !== 'pending' ? ' (' . $uname . ')' : '') . ' ',
        'sub_no' => $no,
    ];
}

/** BOM總覽：[bom_no => [tag,…]]（併進 qab_bom_decision_tags 的輸出） */
function qab_remake_tags_for_boms(PDO $db, array $bomNos): array
{
    $bomNos = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $bomNos)))));
    if (!$bomNos) return [];
    qab_remake_schema($db);
    $in = implode(',', array_fill(0, count($bomNos), '?'));
    $st = $db->prepare("SELECT r.*, o.abnormal_order_no, o.resp_process_no, o.src_outsource_batch_id, u.user_cname,
                               pn.ProcessName AS proc_name, ml.maker_id AS vendor_name
                        FROM qab_remake r
                        JOIN qa_abnormal_order o ON o.id=r.order_id AND o.deleted_at IS NULL
                        LEFT JOIN process_no pn ON pn.ProcessNo = o.resp_process_no
                        LEFT JOIN maker_list ml ON ml.maker_id_no = o.responsible_vendor_id
                        LEFT JOIN `user` u ON u.id=r.answered_by
                        WHERE r.bom_no IN ($in) AND r.status IN ('pending','remake','skip')");
    $st->execute($bomNos);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = qab_remake_tag($r, (string)$r['abnormal_order_no'], (string)$r['user_cname']);
        // BOM總覽發單日欄的「廢」合計用：這筆報廢屬於哪一站、是否已由廠商分批回廠的報廢數計入（避免重複加）
        $t['proc_no'] = (string)($r['resp_process_no'] ?? '');
        $t['from_batch'] = !empty($r['src_outsource_batch_id']) ? 1 : 0;
        $t['proc_name'] = trim((string)($r['proc_name'] ?? '')) . (trim((string)($r['vendor_name'] ?? '')) !== '' ? '(' . trim((string)$r['vendor_name']) . ')' : '');
        $out[(string)$r['bom_no']][] = $t;
    }
    return $out;
}

/** 退貨追蹤：[IR_id => [tag,…]] */
function qab_remake_tags_for_irs(PDO $db, array $irIds): array
{
    $irIds = array_values(array_unique(array_filter(array_map('intval', $irIds))));
    if (!$irIds) return [];
    qab_remake_schema($db);
    $in = implode(',', $irIds);
    $rows = $db->query("SELECT r.*, o.abnormal_order_no, o.ir_id AS o_ir, u.user_cname FROM qab_remake r
                        JOIN qa_abnormal_order o ON o.id=r.order_id AND o.deleted_at IS NULL
                        LEFT JOIN `user` u ON u.id=r.answered_by
                        WHERE o.ir_id IN ($in) AND r.status IN ('pending','remake','skip')")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['o_ir']][] = qab_remake_tag($r, (string)$r['abnormal_order_no'], (string)$r['user_cname']);
    return $out;
}

/** 訂單追蹤：[Order_id => [tag,…]]（原訂單看得到「哪張異常單判定不補／補做」；補做出來的新訂單也標出來源異常單） */
function qab_remake_tags_for_orders(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!$orderIds) return [];
    qab_remake_schema($db);
    $in = implode(',', $orderIds);
    $rows = $db->query("SELECT r.*, o.abnormal_order_no, u.user_cname FROM qab_remake r
                        JOIN qa_abnormal_order o ON o.id=r.order_id AND o.deleted_at IS NULL
                        LEFT JOIN `user` u ON u.id=r.answered_by
                        WHERE r.status IN ('pending','remake','skip') AND (r.src_order_id IN ($in) OR r.new_order_id IN ($in))")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $t = qab_remake_tag($r, (string)$r['abnormal_order_no'], (string)$r['user_cname']);
        $t['is_new'] = 0;
        foreach (['src_order_id' => 0, 'new_order_id' => 1] as $k => $isNew) {
            $oid = (int)$r[$k];
            if ($oid > 0 && in_array($oid, $orderIds, true)) { $tt = $t; $tt['is_new'] = $isNew; $out[$oid][] = $tt; }
        }
    }
    return $out;
}

/** 目前登入者待回覆的補做事項筆數（右下角提醒用） */
function qab_remake_pending_for_user(PDO $db, int $uid): array
{
    qab_remake_schema($db);
    $out = [];
    foreach ($db->query("SELECT r.order_id, r.notify_uids, o.abnormal_order_no FROM qab_remake r
                         JOIN qa_abnormal_order o ON o.id=r.order_id AND o.deleted_at IS NULL WHERE r.status='pending'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = json_decode((string)$r['notify_uids'], true);
        if (is_array($u) && in_array($uid, array_map('intval', $u), true)) $out[] = ['id' => (int)$r['order_id'], 'no' => (string)$r['abnormal_order_no']];
    }
    return $out;
}

}
