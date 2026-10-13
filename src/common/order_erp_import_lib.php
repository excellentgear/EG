<?php
/**
 * order_erp_import_lib.php — ERP「客戶訂購單日報表」補訂單資料（唯一實作）
 * 建立：2026-10-13（使用者交辦：OO1130101-0131.xls 這類舊訂購單報表轉進 order_track）
 *
 * 流程：上傳 xls → 解析 → 與 order_track 比對（同單號底下的料號／數量／製程／單價）
 *       → 存入暫存區 order_erp_import_stage（state=pending，不動 order_track）
 *       → 管理員在「補資料」分頁檢視、勾選後「轉成正式訂單」才寫入 order_track。
 * 鐵則：絕不覆蓋、不修改任何既有訂單，只新增；新增列的業務備註(Order_ps)一律寫「補訂單資料」。
 *
 * 品名規格欄＝品名 ＋ 料號主檔 Spec_No ＋ 空白 ＋ 訂單「製程」(Processing_items)，
 * 所以製程＝Spec_No 之後的文字；料號主檔沒有 Spec_No 或對不上時退回「最後一個空白之後」。
 */

const OEI_PS_TEXT = '補訂單資料';

function oei_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $r = $db->query("SHOW TABLES LIKE 'order_erp_import_stage'")->fetchColumn();
    if ($r) return;
    if ($db->inTransaction()) return; // DDL 會隱式 commit，交易中不建
    $db->exec("CREATE TABLE order_erp_import_stage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id VARCHAR(20) NOT NULL COMMENT '同一次上傳',
        line_no INT NOT NULL DEFAULT 0 COMMENT 'Excel 列號',
        order_oo VARCHAR(12) NOT NULL,
        order_date DATE NULL,
        delivery_date DATE NULL,
        client_id VARCHAR(11) NULL,
        client_name VARCHAR(50) NULL,
        part_no VARCHAR(50) NOT NULL COMMENT 'Excel 產品編號',
        d_id_id INT NULL COMMENT '對應 d_setting.d_id',
        spec_text VARCHAR(150) NULL COMMENT 'Excel 品名規格原文',
        process_text VARCHAR(50) NULL COMMENT '解析出的製程',
        qty INT NOT NULL DEFAULT 0,
        unit_price DECIMAL(15,4) NULL,
        amount DECIMAL(15,2) NULL,
        currency VARCHAR(30) NULL,
        exchange_rate DECIMAL(10,6) NULL,
        match_status VARCHAR(20) NOT NULL COMMENT 'new_order/add_to_order/exact/diff/no_part',
        match_note VARCHAR(255) NULL,
        existing_order_id INT NULL COMMENT '比對到的既有訂單(任一筆)',
        state VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending/converted/skipped',
        converted_order_id INT NULL,
        created_by INT NULL, created_at DATETIME NOT NULL,
        converted_by INT NULL, converted_at DATETIME NULL,
        KEY idx_batch (batch_id), KEY idx_state (state), KEY idx_oo (order_oo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ERP訂購單補資料暫存區'");
}

function oei_date($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^(\d+)[\/.\-](\d+)[\/.\-](\d+)$/', $v, $m)) {
        $y = (int)$m[1]; if ($y < 1911) $y += 1911; // 民國年 +1911
        return checkdate((int)$m[2], (int)$m[3], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[3]) : null;
    }
    return null;
}
function oei_num($v): ?float {
    $v = str_replace([',', '，', ' ', "\xC2\xA0"], '', trim((string)$v));
    return ($v !== '' && is_numeric($v)) ? (float)$v : null;
}
/** 製程相同，或任一邊沒記載（舊訂單製程欄多半是空的，空白不算衝突） */
function oei_proc_ok(string $dbProc, string $xlProc): bool {
    $a = oei_norm($dbProc); $b = oei_norm($xlProc);
    return $a === '' || $b === '' || $a === $b;
}
function oei_norm(string $s): string { return preg_replace('/\s+/u', '', trim($s)); }

/** 解析整張工作表 → 列陣列（單據日期／單號向下延續） */
function oei_parse(array $allRows, array &$err): array {
    $head = '';
    foreach ($allRows as $i => $row) { if ($i >= 12) break; $head .= implode('|', array_map('strval', $row)) . '|'; }
    if (mb_strpos($head, '客戶訂購單日報表') === false) { $err[] = '找不到「客戶訂購單日報表」字樣，請確認是否為 ERP 客戶訂購單日報表'; return []; }
    if (mb_strpos($head, '客戶別') === false) { $err[] = '報表型別需為「客戶別 明細表」（才有客戶編號群組列）'; return []; }
    $out = []; $cid = $cname = null; $date = $oo = null; $skipNoOO = 0;
    foreach ($allRows as $i => $row) {
        $r = array_pad(array_values($row), 10, null);
        $a = trim((string)$r[0]); $b = trim((string)$r[1]); $d = trim((string)$r[3]);
        if ($a === '客戶編號：') { $cid = trim((string)$r[2]) ?: null; $cname = trim((string)$r[3]) ?: null; $date = $oo = null; continue; }
        if ($d === '' || $d === '產品編號' || mb_strpos($d, '合計') !== false) continue;
        $qty = oei_num($r[5]);
        if ($qty === null) continue;
        if ($b !== '') {
            if (!preg_match('/^OO\d+$/', $b)) { $skipNoOO++; $oo = null; continue; }
            $oo = $b; if ($a !== '') $date = oei_date($a);
        }
        if ($oo === null) continue;
        $cur = trim((string)$r[8]);
        $out[] = [
            'line_no' => $i + 1, 'order_oo' => $oo, 'order_date' => $date,
            'delivery_date' => oei_date($r[2]), 'client_id' => $cid, 'client_name' => $cname,
            'part_no' => mb_substr($d, 0, 50), 'spec_text' => mb_substr(trim((string)$r[4]), 0, 150),
            'qty' => (int)round($qty), 'unit_price' => oei_num($r[6]), 'amount' => oei_num($r[7]),
            'currency' => ($cur === '' || $cur === '台幣') ? null : mb_substr($cur, 0, 30),
            'exchange_rate' => oei_num($r[9]),
        ];
    }
    if ($skipNoOO) $err[] = "有 {$skipNoOO} 列單號不是 OO 開頭，已略過";
    return $out;
}

/** 製程＝品名規格中 Spec_No 之後的文字；對不上時取最後一個空白之後 */
function oei_split_process(string $text, string $specNo): string {
    $text = trim($text);
    if ($specNo !== '' && ($p = mb_strpos($text, $specNo)) !== false) {
        return trim(mb_substr($text, $p + mb_strlen($specNo)));
    }
    $p = mb_strrpos($text, ' ');
    if ($p === false) { $p = mb_strrpos($text, "\u{3000}"); }
    return $p === false ? '' : trim(mb_substr($text, $p + 1));
}

/** 解析料號主檔：產品編號＋客戶 → d_setting（同編號多筆時以客戶判定，仍不唯一就不猜） */
function oei_part_lookup(PDO $db, string $partNo, ?string $clientId): array {
    static $cache = [];
    $k = $partNo . '|' . $clientId;
    if (isset($cache[$k])) return $cache[$k];
    $st = $db->prepare("SELECT d_id, Spec_No, Customer_Id FROM d_setting WHERE D_Setting_Id = ?");
    $st->execute([$partNo]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $pick = null; $note = '';
    if (count($rows) === 1) $pick = $rows[0];
    elseif (count($rows) > 1) {
        $m = array_values(array_filter($rows, fn($x) => $clientId !== null && (string)$x['Customer_Id'] === $clientId));
        if (count($m) === 1) $pick = $m[0]; else $note = '料號主檔有 ' . count($rows) . ' 筆同編號且無法由客戶判定';
    } else $note = '料號主檔查無此產品編號';
    return $cache[$k] = ['row' => $pick, 'note' => $note];
}

/**
 * 逐列比對。回傳每列補上 d_id_id／process_text／match_status／match_note／existing_order_id。
 * 同單號底下可能有多筆「料號、數量、製程、單價」都相同的列，所以採「一對一消耗」：
 * 每筆 Excel 列只會對應一筆既有訂單列，且每筆既有列只被對應一次。
 */
function oei_match(PDO $db, array $rows): array {
    $custNames = [];
    foreach (array_unique(array_filter(array_column($rows, 'client_id'))) as $c) {
        $s = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $s->execute([$c]);
        $custNames[$c] = $s->fetchColumn() ?: null;
    }
    foreach ($rows as &$r) {
        $lk = oei_part_lookup($db, $r['part_no'], $r['client_id']);
        $r['d_id_id'] = $lk['row'] ? (int)$lk['row']['d_id'] : null;
        $spec = $lk['row']['Spec_No'] ?? '';
        $r['process_text'] = mb_substr(oei_split_process($r['spec_text'], (string)$spec), 0, 50);
        $r['_cust_ok'] = $r['client_id'] && !empty($custNames[$r['client_id']]);
        if ($r['_cust_ok']) $r['client_name'] = $custNames[$r['client_id']];
        $r['match_status'] = ''; $r['match_note'] = ''; $r['existing_order_id'] = null;
        $r['_lknote'] = $lk['note'];
    }
    unset($r);

    $ooList = array_values(array_unique(array_column($rows, 'order_oo')));
    $exist = [];
    foreach (array_chunk($ooList, 500) as $ch) {
        $ph = implode(',', array_fill(0, count($ch), '?'));
        $s = $db->prepare("SELECT Order_id, Order_oo, d_id, d_id_ID, Qty, Processing_items, unit_price FROM order_track WHERE Order_oo IN ($ph)");
        $s->execute($ch);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) { $e['used'] = false; $exist[$e['Order_oo']][] = $e; }
    }
    $samePart = fn($e, $r) => ($r['d_id_id'] && (int)$e['d_id_ID'] === $r['d_id_id']) || (string)$e['d_id'] === (string)$r['part_no'];
    // 第一輪：完全相同者先消耗
    foreach ($rows as &$r) {
        if (!isset($exist[$r['order_oo']])) continue;
        foreach ($exist[$r['order_oo']] as &$e) {
            if ($e['used'] || !$samePart($e, $r)) continue;
            $priceOk = $e['unit_price'] === null || $r['unit_price'] === null || abs((float)$e['unit_price'] - $r['unit_price']) < 0.005;
            if ((int)$e['Qty'] === $r['qty'] && oei_proc_ok((string)$e['Processing_items'], $r['process_text']) && $priceOk) {
                $e['used'] = true; $r['match_status'] = 'exact'; $r['existing_order_id'] = (int)$e['Order_id'];
                $r['match_note'] = '訂單內已有相同料號／數量／製程／單價，略過'; break;
            }
        }
        unset($e);
    }
    unset($r);
    foreach ($rows as &$r) {
        if ($r['match_status'] !== '') continue;
        if ($r['d_id_id'] === null) { $r['match_status'] = 'no_part'; $r['match_note'] = $r['_lknote'] . '，請先到料號主檔建立'; continue; }
        if (!isset($exist[$r['order_oo']])) { $r['match_status'] = 'new_order'; $r['match_note'] = '單號不存在，將新建此訂單'; continue; }
        $same = array_values(array_filter($exist[$r['order_oo']], fn($e) => $samePart($e, $r)));
        $r['existing_order_id'] = (int)$exist[$r['order_oo']][0]['Order_id'];
        if (!$same) { $r['match_status'] = 'add_to_order'; $r['match_note'] = '單號已存在但無此產品編號，將補進去'; continue; }
        $d = [];
        $e = $same[0];
        if ((int)$e['Qty'] !== $r['qty']) $d[] = "數量 {$e['Qty']}→{$r['qty']}";
        if (!oei_proc_ok((string)$e['Processing_items'], $r['process_text'])) $d[] = '製程「' . $e['Processing_items'] . '」→「' . $r['process_text'] . '」';
        if ($e['unit_price'] !== null && $r['unit_price'] !== null && abs((float)$e['unit_price'] - $r['unit_price']) >= 0.005) $d[] = "單價 " . (float)$e['unit_price'] . "→" . $r['unit_price'];
        $r['match_status'] = 'diff';
        $r['match_note'] = $d ? '同單號已有此產品但 ' . implode('；', $d) . '（不覆蓋，需要才勾選補入）' : '同單號此產品已有 ' . count($same) . ' 筆且都已對應完（相同明細第 N 筆，需要才勾選補入）';
    }
    unset($r);
    foreach ($rows as &$r) { unset($r['_cust_ok'], $r['_lknote']); }
    return $rows;
}

/** 轉成正式訂單：重新比對後才寫入，只 INSERT。回傳 [ok, message, order_id] */
function oei_convert_one(PDO $db, int $stageId, int $uid): array {
    $s = $db->prepare("SELECT * FROM order_erp_import_stage WHERE id=? FOR UPDATE");
    $s->execute([$stageId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['state'] !== 'pending') return [false, '此筆已處理過或不存在', null];
    if (!$r['d_id_id']) return [false, '料號主檔未對應，無法轉入', null];
    if (!$r['order_date']) return [false, '單據日期缺漏', null];
    // 轉入當下重新比對（暫存之後訂單可能已被別人建立／補過）
    $m = oei_match($db, [[
        'line_no' => 0, 'order_oo' => $r['order_oo'], 'order_date' => $r['order_date'], 'delivery_date' => $r['delivery_date'],
        'client_id' => $r['client_id'], 'client_name' => $r['client_name'], 'part_no' => $r['part_no'], 'spec_text' => $r['spec_text'],
        'qty' => (int)$r['qty'], 'unit_price' => $r['unit_price'] === null ? null : (float)$r['unit_price'],
        'amount' => null, 'currency' => $r['currency'], 'exchange_rate' => null,
    ]])[0];
    if ($m['match_status'] === 'exact') {
        $db->prepare("UPDATE order_erp_import_stage SET state='skipped', match_status='exact', match_note=? WHERE id=?")
           ->execute(['轉入時發現訂單內已有相同明細，略過', $stageId]);
        return [false, '訂單內已有相同明細，已略過', null];
    }
    $cn = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=?"); $cn->execute([$r['client_id']]);
    $clientName = $cn->fetchColumn();
    $clientId = $clientName ? $r['client_id'] : null;
    $clientName = $clientName ?: ($r['client_name'] ?: '');
    if ($clientName === '') return [false, '查無客戶名稱', null];
    $db->prepare("INSERT INTO order_track SET
        Order_oo=?, d_id=?, Specification=NULL, Order_ps=?, Client_name=?, Qty=?, Order_date=?, Delivery_date=?,
        Processing_items=?, unit_price=?, currency=?, Order_status=NULL, split_seq=1, parent_order_id=NULL,
        Created_At=NOW(), Created_By=?, Client_name_ID=?, d_id_ID=?")
       ->execute([$r['order_oo'], $r['part_no'], OEI_PS_TEXT, $clientName, (int)$r['qty'], $r['order_date'],
                  $r['delivery_date'] ?: $r['order_date'], $r['process_text'] ?: null, $r['unit_price'], $r['currency'],
                  $uid, $clientId, (int)$r['d_id_id']]);
    $oid = (int)$db->lastInsertId();
    $db->prepare("UPDATE order_erp_import_stage SET state='converted', converted_order_id=?, converted_by=?, converted_at=NOW() WHERE id=?")
       ->execute([$oid, $uid, $stageId]);
    return [true, 'ok', $oid];
}
