<?php
// =============================================================================
// order_boss_review_lib.php
//   訂單追蹤：「指定客戶的訂單，轉生管之前要先給 BOSS 審圖」唯一實作
//   （2026-09-18 使用者要求；禁止各頁自己再寫一份判定）
//
// 流程（只影響「客戶在名單內、有按過審圖、而且不是自動轉生管」的那些訂單）：
//   按【轉生管】→ 不直接蓋轉生管日，改成記下「今天送 BOSS 審圖」(boss_review_at)，
//   畫面顯示「BOSS審圖中」並長出【BOSS審核OK】鈕
//   按【BOSS審核OK】→ 記下「今天 BOSS 審核完成」(boss_ok_at)，【轉生管】鈕回來，
//   之後所有動作與原本完全相同。
//
// 三件刻意這樣做的事：
//  1. **原本會自動轉生管的設計對象一律不受影響**（使用者明確要求）。判定時直接排除
//     order_auto_pmget_lib.php 名單內的 ate——那些訂單存檔當下就已經是已轉生管，
//     根本不會按到【轉生管】鈕；不排除的話，萬一有人手動按 X 取消了自動蓋的日期，
//     再按一次轉生管就會莫名其妙卡進 BOSS 審圖流程。
//  2. **名單是空的時候，這整套等於不存在**（ot_boss_required() 一律回 false），
//     所以在使用者真的去設定之前，全站行為與改動前一模一樣。
//  3. **沒按過「審圖」就直接按轉生管的一律不擋**（使用者 2026-09-18 補充）：那種通常本來
//     就已經有圖面，不需要再送 BOSS。所以判定要吃 order_track.in_review。
//  4. 每一次設定的異動都要填原因：畫面送「完整的名單」上來，後端自己 diff 出
//     新增/修改/刪除，同一次儲存共用一個 batch_id 與一個原因（使用者指定：
//     一次設定多組只要填一次）。
// =============================================================================

require_once __DIR__ . '/order_auto_pmget_lib.php';

if (!function_exists('ot_boss_ensure_schema')) {

/** 資料表與 order_track 欄位確保存在（可重複執行；失敗不可害正常頁面掛掉） */
function ot_boss_ensure_schema(PDO $db): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ot_boss_review_client (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(20) NOT NULL COMMENT '客戶ID（customer_list.customer_id）',
            client_name VARCHAR(60) DEFAULT NULL COMMENT '設定當下的客戶名稱，僅供顯示',
            note VARCHAR(255) DEFAULT NULL COMMENT '備註（這家為什麼要給BOSS審圖）',
            created_by INT DEFAULT NULL,
            created_by_name VARCHAR(50) DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            updated_by_name VARCHAR(50) DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE KEY uk_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單追蹤：這些客戶的訂單轉生管前要先給BOSS審圖'");

        $db->exec("CREATE TABLE IF NOT EXISTS ot_boss_review_client_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(32) NOT NULL COMMENT '同一次儲存的多筆異動共用（原因只填一次）',
            action VARCHAR(10) NOT NULL COMMENT 'add／update／delete',
            client_id VARCHAR(20) NOT NULL,
            client_name VARCHAR(60) DEFAULT NULL,
            detail TEXT COMMENT '異動內容（JSON）',
            reason TEXT COMMENT '修改原因（必填）',
            created_by INT DEFAULT NULL,
            created_by_name VARCHAR(50) DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            KEY idx_client (client_id),
            KEY idx_batch (batch_id),
            KEY idx_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單追蹤：BOSS審圖客戶名單的異動歷程（誰、何時、為什麼）'");

        // order_track 四個欄位（一律 nullable，不動既有資料）
        $cols = [
            'boss_review_at' => "ADD COLUMN boss_review_at DATETIME NULL DEFAULT NULL COMMENT '送BOSS審圖日（按轉生管時系統認定當天）' AFTER pmGet_auto",
            'boss_review_by' => "ADD COLUMN boss_review_by INT NULL DEFAULT NULL COMMENT '送BOSS審圖的操作者' AFTER boss_review_at",
            'boss_ok_at'     => "ADD COLUMN boss_ok_at DATETIME NULL DEFAULT NULL COMMENT 'BOSS審核完成日（按BOSS審核OK時系統認定當天）' AFTER boss_review_by",
            'boss_ok_by'     => "ADD COLUMN boss_ok_by INT NULL DEFAULT NULL COMMENT '按下BOSS審核OK的操作者' AFTER boss_ok_at",
        ];
        foreach ($cols as $col => $ddl) {
            $has = $db->query("SHOW COLUMNS FROM order_track LIKE '" . $col . "'")->fetch();
            if (!$has) $db->exec("ALTER TABLE order_track " . $ddl);
        }
        $ok = true;
    } catch (Exception $e) { $ok = false; }
    return $ok;
}

/** 目前名單：client_id => 該列資料（靜態快取，一個請求只查一次） */
function ot_boss_client_map(PDO $db, bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = [];
    try {
        $rows = $db->query("SELECT client_id, client_name, note, created_by_name, created_at,
                                   updated_by_name, updated_at
                            FROM ot_boss_review_client ORDER BY client_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $cache[trim((string)$r['client_id'])] = $r;
    } catch (Exception $e) { $cache = []; }   // 表還沒建 → 視同沒有設定（行為與改動前相同）
    return $cache;
}

/** 這個客戶在不在名單內 */
function ot_boss_is_client(PDO $db, $clientId): bool {
    $c = trim((string)$clientId);
    if ($c === '') return false;
    $map = ot_boss_client_map($db);
    return isset($map[$c]);
}

/**
 * 這張訂單「轉生管前要不要先給 BOSS 審圖」。三個條件都成立才要：
 *   ①客戶在名單內
 *   ②指派設計不在「存檔自動轉生管」名單內（使用者明確要求，那種訂單一存檔就已經是已轉生管）
 *   ③**這張訂單有按過「審圖」**（in_review 有值）——使用者 2026-09-18 補充的條件：
 *     沒按審圖就直接按轉生管的，通常是本來就已經有圖面，不要擋。
 * ※ $inReview 一律由呼叫端傳該筆的 order_track.in_review；三個呼叫端（清單列渲染、
 *   simple_update_pmGet.php、ot_boss_cell_state()）都要傳，不要讓它用預設值。
 */
function ot_boss_required(PDO $db, $clientId, $ate, $inReview = null): bool {
    if (trim((string)$inReview) === '') return false;   // 沒按過審圖＝不擋
    if (!ot_boss_is_client($db, $clientId)) return false;
    $ateI = intval($ate);
    if ($ateI > 0 && in_array($ateI, ot_auto_pmget_ids($db), true)) return false;
    return true;
}

/**
 * 顯示用姓名一律回查 user 表，**不採信 session 送來的值**。
 * 站上的 $_SESSION['userName'] 存的是登入帳號（例 '005'），Login.php 從來沒有寫過 user_cname，
 * 拿它當姓名存下去，畫面上的「設定人」就會變成一串代號（同一個坑：領料單的 issued_by_name）。
 */
function ot_boss_user_name(PDO $db, int $uid, string $fallback = ''): string {
    try {
        $st = $db->prepare("SELECT user_cname FROM `user` WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $n = trim((string)$st->fetchColumn());
        if ($n !== '') return $n;
    } catch (Exception $e) { /* 查不到就用退路 */ }
    return ($fallback !== '') ? $fallback : (string)$uid;
}

/** 是否具備「設定 BOSS 審圖客戶名單」的功能碼（不 fail-open：查不到角色一律 false） */
function ot_boss_can_setting(PDO $db, int $uid): bool {
    if ($uid <= 0) return false;
    require_once __DIR__ . '/role_features_helper.php';
    try {
        $features = rf_load_user_features_all($db, $uid);
        if (empty($features)) return false;
        return rf_has_feature($features, 'ot_boss_review_setting');
    } catch (Exception $e) { return false; }
}

/**
 * 儲存名單（畫面送「完整的名單」上來，後端自己 diff 出新增/修改/刪除）。
 * @param array  $items  [['client_id'=>..,'note'=>..], ...]
 * @param string $reason 本次異動原因（必填，整批共用）
 * @return array ['ok'=>bool,'msg'=>string,'added'=>n,'updated'=>n,'deleted'=>n]
 */
function ot_boss_save(PDO $db, array $items, string $reason, int $uid, string $uname): array {
    $reason = trim($reason);
    if ($reason === '')           return ['ok' => false, 'msg' => '請填寫本次修改的原因'];
    if (mb_strlen($reason) > 500) return ['ok' => false, 'msg' => '修改原因請控制在 500 字以內'];
    if (!ot_boss_ensure_schema($db)) return ['ok' => false, 'msg' => '資料表建立失敗，請聯絡管理員'];
    // 姓名一律以 user 表為準（呼叫端送的只當退路）
    $uname = ot_boss_user_name($db, $uid, $uname);

    // ── 整理送上來的名單，並回查客戶主檔（客戶必須真的存在；名稱一律以主檔為準）──
    $want = [];
    foreach ($items as $it) {
        $cid = trim((string)($it['client_id'] ?? ''));
        if ($cid === '') continue;
        $want[$cid] = ['client_id' => $cid, 'note' => mb_substr(trim((string)($it['note'] ?? '')), 0, 255)];
    }
    if (count($want) > 300) return ['ok' => false, 'msg' => '一次最多綁定 300 家客戶'];
    if (!empty($want)) {
        $ph = implode(',', array_fill(0, count($want), '?'));
        $st = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE customer_id IN ($ph)");
        $st->execute(array_keys($want));
        $found = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $found[trim((string)$r['customer_id'])] = $r['customer'];
        $bad = array_diff(array_keys($want), array_keys($found));
        if (!empty($bad)) {
            return ['ok' => false, 'msg' => '查無這些客戶ID：' . implode('、', $bad) . '（請從搜尋清單點選）'];
        }
        foreach ($want as $cid => $w) $want[$cid]['client_name'] = $found[$cid];
    }

    $cur = ot_boss_client_map($db, true);
    $add = []; $upd = []; $del = [];
    foreach ($want as $cid => $w) {
        if (!isset($cur[$cid])) { $add[$cid] = $w; }
        elseif (trim((string)($cur[$cid]['note'] ?? '')) !== $w['note']) { $upd[$cid] = $w; }
    }
    foreach ($cur as $cid => $c) { if (!isset($want[$cid])) $del[$cid] = $c; }

    if (!$add && !$upd && !$del) {
        return ['ok' => true, 'msg' => '沒有任何異動', 'added' => 0, 'updated' => 0, 'deleted' => 0];
    }

    $batch = bin2hex(random_bytes(8));
    $own   = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $log = $db->prepare("INSERT INTO ot_boss_review_client_log
            (batch_id, action, client_id, client_name, detail, reason, created_by, created_by_name, created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())");

        foreach ($add as $cid => $w) {
            $db->prepare("INSERT INTO ot_boss_review_client
                (client_id, client_name, note, created_by, created_by_name, created_at, updated_by, updated_by_name, updated_at)
                VALUES (?,?,?,?,?,NOW(),?,?,NOW())")
               ->execute([$cid, $w['client_name'], $w['note'], $uid, $uname, $uid, $uname]);
            $log->execute([$batch, 'add', $cid, $w['client_name'],
                json_encode(['note' => $w['note']], JSON_UNESCAPED_UNICODE), $reason, $uid, $uname]);
        }
        foreach ($upd as $cid => $w) {
            $db->prepare("UPDATE ot_boss_review_client
                          SET client_name=?, note=?, updated_by=?, updated_by_name=?, updated_at=NOW()
                          WHERE client_id=?")
               ->execute([$w['client_name'], $w['note'], $uid, $uname, $cid]);
            $log->execute([$batch, 'update', $cid, $w['client_name'],
                json_encode(['note_old' => (string)($cur[$cid]['note'] ?? ''), 'note_new' => $w['note']], JSON_UNESCAPED_UNICODE),
                $reason, $uid, $uname]);
        }
        foreach ($del as $cid => $c) {
            $db->prepare("DELETE FROM ot_boss_review_client WHERE client_id=?")->execute([$cid]);
            $log->execute([$batch, 'delete', $cid, $c['client_name'],
                json_encode(['note' => (string)($c['note'] ?? '')], JSON_UNESCAPED_UNICODE), $reason, $uid, $uname]);
        }
        if ($own) $db->commit();
    } catch (Exception $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '儲存失敗：' . $e->getMessage()];
    }
    ot_boss_client_map($db, true); // 重新載入靜態快取
    return ['ok' => true, 'msg' => '已儲存', 'added' => count($add), 'updated' => count($upd), 'deleted' => count($del)];
}

/**
 * 「轉生管日／BOM開立」那一格目前該顯示什麼——審圖/轉生管/BOSS審圖四支 API 共用的唯一狀態來源，
 * 前端拿到後由 otPmCellHtml() 畫出來（兩邊各寫一套遲早走鐘）。
 * 日期格式一律沿用該欄原本的 '%c/%e'（例 9/18），不要改成別的格式。
 */
function ot_boss_cell_state(PDO $db, int $orderId): array {
    $out = ['in_review_date' => '', 'pmGet_date' => '', 'boss_need' => false,
            'boss_review_date' => '', 'boss_ok_date' => ''];
    try {
        $st = $db->prepare("SELECT Client_name_ID, ate, in_review,
                                   DATE_FORMAT(in_review,'%c/%e')      AS in_review_date,
                                   DATE_FORMAT(pmGet,'%c/%e')          AS pmGet_date,
                                   DATE_FORMAT(boss_review_at,'%c/%e') AS boss_review_date,
                                   DATE_FORMAT(boss_ok_at,'%c/%e')     AS boss_ok_date
                            FROM order_track WHERE Order_id = ?");
        $st->execute([$orderId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $out;
        $out['in_review_date']   = (string)($r['in_review_date'] ?? '');
        $out['pmGet_date']       = (string)($r['pmGet_date'] ?? '');
        $out['boss_review_date'] = (string)($r['boss_review_date'] ?? '');
        $out['boss_ok_date']     = (string)($r['boss_ok_date'] ?? '');
        $out['boss_need']        = ot_boss_required($db, $r['Client_name_ID'] ?? '', $r['ate'] ?? 0, $r['in_review'] ?? null);
    } catch (Exception $e) { /* 欄位還沒建等狀況一律回預設值，畫面退回原本行為 */ }
    return $out;
}

/** 異動歷程（設定跳窗內顯示；由新到舊） */
function ot_boss_logs(PDO $db, int $limit = 50): array {
    if (!ot_boss_ensure_schema($db)) return [];
    try {
        $limit = max(1, min(200, $limit));
        return $db->query("SELECT batch_id, action, client_id, client_name, detail, reason,
                                  created_by_name, created_at
                           FROM ot_boss_review_client_log
                           ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

} // function_exists
