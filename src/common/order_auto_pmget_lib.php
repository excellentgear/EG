<?php
// =============================================================================
// 訂單追蹤：「指定特定設計(技術)＝存檔時自動轉生管」唯一實作
//   設定值：system_parameters(param_group='DESIGNER_SETTING', param_key='auto_pmget_ates')
//           JSON 陣列，內容是「指派設計」下拉的 value（＝order_track.ate；2 代表「無(不經設計)」）
//   規則：存檔後這張訂單的 ate 落在名單內  → 自動蓋上轉生管日（order_track.pmGet），並標記 pmGet_auto=1
//         之後改成名單外的設計人員        → 自動把「系統蓋的」那筆退回（pmGet=NULL、pmGet_auto=0）
//   兩件事刻意這樣做：
//     ①「人工按過轉生管鈕」的日期（pmGet_auto=0）一律不覆蓋、也不會因為改設計被洗掉
//     ②「審圖日」in_review 完全不動——它是人工按的，退回時本來就該露出原本的狀態
//   日期取值：使用者拍板＝設計接收日 ateGet（補歷史訂單時日期才對得起來），沒填才退回 DB 當天。
//   ※ PHP date() 是 UTC、MySQL 是本地時間，所以「當天」一律問 DB 要 CURDATE()。
//   禁止各頁自己再寫一份判定。
// =============================================================================

if (!function_exists('ot_auto_pmget_ensure_schema')) {

/** order_track.pmGet_auto 欄位確保存在（migration 已建，這裡只是防呆） */
function ot_auto_pmget_ensure_schema(PDO $db): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool)$db->query("SHOW COLUMNS FROM order_track LIKE 'pmGet_auto'")->fetch();
        if (!$ok) {
            $db->exec("ALTER TABLE order_track ADD COLUMN pmGet_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER pmGet");
            $ok = true;
        }
    } catch (Exception $e) { $ok = false; }
    return $ok;
}

/** 目前設定：會自動轉生管的「指派設計」對象 id 陣列（空陣列＝功能等同關閉） */
function ot_auto_pmget_ids(PDO $db, bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = [];
    try {
        $v = $db->query("SELECT param_value FROM system_parameters
                         WHERE param_group='DESIGNER_SETTING' AND param_key='auto_pmget_ates'")->fetchColumn();
        if ($v) {
            foreach ((json_decode($v, true) ?: []) as $x) {
                $i = intval($x);
                if ($i > 0 && !in_array($i, $cache, true)) $cache[] = $i;
            }
        }
    } catch (Exception $e) { $cache = []; }
    return $cache;
}

/** 設定值寫入（呼叫端自行做權限守門） */
function ot_auto_pmget_save_ids(PDO $db, array $ids, int $uid): array {
    $clean = [];
    foreach ($ids as $x) {
        $i = intval($x);
        if ($i > 0 && !in_array($i, $clean, true)) $clean[] = $i;
    }
    $json = json_encode($clean);
    $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
                  VALUES ('DESIGNER_SETTING','auto_pmget_ates', :v, '指定這些設計對象時，訂單存檔即自動轉生管', :u, NOW())
                  ON DUPLICATE KEY UPDATE param_value=:v2, updated_by=:u2, updated_at=NOW()")
       ->execute([':v'=>$json, ':u'=>$uid, ':v2'=>$json, ':u2'=>$uid]);
    ot_auto_pmget_ids($db, true); // 重新載入靜態快取
    return $clean;
}

/**
 * 對單一訂單套用規則（訂單存檔／建立完成後呼叫，會自己讀該筆最新的 ate 與 ateGet）。
 * @return string 'set'=自動蓋上轉生管日 / 'clear'=自動退回 / 'none'=不需異動
 */
function ot_auto_pmget_apply(PDO $db, int $orderId): string {
    $orderId = intval($orderId);
    if ($orderId <= 0) return 'none';
    if (!ot_auto_pmget_ensure_schema($db)) return 'none';
    try {
        $st = $db->prepare("SELECT ate, ateGet, pmGet, pmGet_auto FROM order_track WHERE Order_id=?");
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'none';

        $ids      = ot_auto_pmget_ids($db);
        $isTarget = in_array(intval($row['ate']), $ids, true);

        if ($isTarget) {
            // 人工按過轉生管的不覆蓋（含日期）
            if ($row['pmGet'] !== null && intval($row['pmGet_auto']) !== 1) return 'none';
            $d = (!empty($row['ateGet'])) ? substr($row['ateGet'], 0, 10) : $db->query("SELECT CURDATE()")->fetchColumn();
            if ($row['pmGet'] !== null && substr($row['pmGet'], 0, 10) === $d) return 'none';
            $db->prepare("UPDATE order_track SET pmGet=?, pmGet_auto=1 WHERE Order_id=?")
               ->execute([$d . ' 00:00:00', $orderId]);
            return 'set';
        }
        // 名單外：只退「系統自動蓋的」那種
        if (intval($row['pmGet_auto']) === 1) {
            $db->prepare("UPDATE order_track SET pmGet=NULL, pmGet_auto=0 WHERE Order_id=?")->execute([$orderId]);
            return 'clear';
        }
        return 'none';
    } catch (Exception $e) {
        return 'none'; // 自動化不可以害正常存檔失敗
    }
}

/**
 * 既有訂單一次回填／退回（設定改完後手動執行）。
 * 只掃未結案(Order_status<>9)的訂單；$dryRun=true 只回報筆數不寫入。
 * @return array ['set'=>n, 'clear'=>n]
 */
function ot_auto_pmget_backfill(PDO $db, bool $dryRun = true): array {
    $out = ['set' => 0, 'clear' => 0];
    if (!ot_auto_pmget_ensure_schema($db)) return $out;
    $ids = ot_auto_pmget_ids($db);

    // ① 名單內、還沒有轉生管日 → 要補
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT Order_id FROM order_track
                            WHERE ate IN ($ph) AND pmGet IS NULL
                              AND (Order_status IS NULL OR Order_status <> 9)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        $out['set'] = count($rows);
        if (!$dryRun) {
            $out['set'] = 0;
            foreach ($rows as $oid) { if (ot_auto_pmget_apply($db, (int)$oid) === 'set') $out['set']++; }
        }
    }

    // ② 系統自動蓋過、但現在已不在名單內 → 要退回
    $sql = "SELECT Order_id FROM order_track WHERE pmGet_auto=1 AND (Order_status IS NULL OR Order_status <> 9)";
    $par = [];
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND (ate IS NULL OR ate NOT IN ($ph))";
        $par = $ids;
    }
    $st = $db->prepare($sql);
    $st->execute($par);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    $out['clear'] = count($rows);
    if (!$dryRun) {
        $out['clear'] = 0;
        foreach ($rows as $oid) { if (ot_auto_pmget_apply($db, (int)$oid) === 'clear') $out['clear']++; }
    }
    return $out;
}

} // function_exists
