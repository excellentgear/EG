<?php
// 申請採購「用途歸屬」驗收測試
// testing_discipline：全程包在 transaction 裡最後 rollback，正式庫零殘留；
// 不呼叫 req_save 的 HTTP 端點，故不會發出真實推播。
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");
require_once 'C:/MAMP/htdocs/EGsystem/src/common/purchase_lib.php';

$pass = 0; $fail = 0;
function ck(string $name, bool $ok, string $extra = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK] $name\n"; }
    else { $fail++; echo "  [FAIL] $name $extra\n"; }
}
function throws(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

echo "=== 1. 欄位存在 ===\n";
foreach ([['purchase_request', ['purpose_type','purpose_order_id','purpose_bom','purpose_d_id','purpose_note','purpose_label','is_urgent']],
          ['purchase_request_item', ['purpose_type','purpose_order_id','purpose_bom','purpose_d_id','purpose_note','purpose_label']]] as [$t, $cols]) {
    foreach ($cols as $c) {
        $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([$t, $c]);
        ck("$t.$c", (bool)$st->fetchColumn());
    }
}

echo "\n=== 2. 用途正規化：綁 ID 成功並由 DB 重建顯示名稱 ===\n";
// 挑一筆真實訂單列
$o = $db->query("SELECT Order_id, Order_oo, d_id FROM order_track ORDER BY Order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$r = purchase_purpose_normalize($db, ['purpose_type' => 'ORDER', 'purpose_order_id' => $o['Order_id'],
                                     'purpose_label' => '前端亂傳的假名稱']);
ck('ORDER 存得到 Order_id', (int)$r['order_id'] === (int)$o['Order_id']);
ck('ORDER 的 label 由 DB 重建（不採信前端）',
   strpos((string)$r['label'], $o['Order_oo']) === 0 && strpos((string)$r['label'], '假名稱') === false,
   '得到：' . $r['label']);
ck('ORDER 不會誤存訂單號字串到別的欄位', $r['bom'] === null && $r['d_id'] === null);

$b = $db->query("SELECT bom, d_id FROM bom ORDER BY bom DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$r = purchase_purpose_normalize($db, ['purpose_type' => 'BOM', 'purpose_bom' => $b['bom']]);
ck('BOM 綁得到 bom 主鍵', $r['bom'] === $b['bom'], '得到：' . var_export($r['bom'], true));

$p = $db->query("SELECT d_id, D_Setting_Id FROM d_setting ORDER BY d_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$r = purchase_purpose_normalize($db, ['purpose_type' => 'PART', 'purpose_d_id' => $p['d_id']]);
ck('PART 綁得到 d_setting.d_id', (int)$r['d_id'] === (int)$p['d_id']);
ck('PART 的 label = 料號字串', $r['label'] === $p['D_Setting_Id']);

$r = purchase_purpose_normalize($db, ['purpose_type' => 'STOCK']);
ck('STOCK 不需綁單據、label 為類別名', $r['type'] === 'STOCK' && $r['order_id'] === null
   && $r['label'] === PURCHASE_PURPOSE_TYPES['STOCK']);
$r = purchase_purpose_normalize($db, ['purpose_type' => 'OTHER', 'purpose_note' => '尾牙抽獎品']);
ck('OTHER 的說明接進 label', strpos((string)$r['label'], '尾牙抽獎品') !== false, '得到：' . $r['label']);

echo "\n=== 3. 擋掉沒綁 ID 與綁到不存在的 ID ===\n";
ck('ORDER 沒指定料號列要擋', throws(fn() => purchase_purpose_normalize($db, ['purpose_type' => 'ORDER'])) !== '');
ck('ORDER 綁不存在的 Order_id 要擋',
   throws(fn() => purchase_purpose_normalize($db, ['purpose_type' => 'ORDER', 'purpose_order_id' => 999999999])) !== '');
ck('BOM 綁不存在的單號要擋',
   throws(fn() => purchase_purpose_normalize($db, ['purpose_type' => 'BOM', 'purpose_bom' => '__NO_SUCH_BOM__'])) !== '');
ck('PART 綁不存在的 d_id 要擋',
   throws(fn() => purchase_purpose_normalize($db, ['purpose_type' => 'PART', 'purpose_d_id' => 999999999])) !== '');
$r = purchase_purpose_normalize($db, ['purpose_type' => '']);
ck('沒選類別＝回 null（品項列代表沿用單頭）', $r['type'] === null);
$r = purchase_purpose_normalize($db, ['purpose_type' => 'NOT_A_TYPE']);
ck('亂傳的類別當成沒選', $r['type'] === null);

echo "\n=== 4. 實際寫入單頭＋品項（最後 rollback） ===\n";
$db->beginTransaction();
try {
    $pp = purchase_purpose_normalize($db, ['purpose_type' => 'ORDER', 'purpose_order_id' => $o['Order_id']]);
    $reqNo = '__TEST__' . substr(md5((string)$o['Order_id']), 0, 8);
    $db->prepare("INSERT INTO purchase_request (req_no, title, requester_id, requester_name, need_date, reason,
                  purpose_type, purpose_order_id, purpose_bom, purpose_d_id, purpose_note, purpose_label, is_urgent,
                  status, Created_By) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'submitted', ?)")
       ->execute([$reqNo, '測試單', 1, '測試', date('Y-m-d'), null,
                  $pp['type'], $pp['order_id'], $pp['bom'], $pp['d_id'], $pp['note'], $pp['label'], 1, 1]);
    $reqId = (int)$db->lastInsertId();

    // 品項一：留白＝沿用單頭；品項二：逐列覆寫成料號
    $ip = purchase_purpose_normalize($db, ['purpose_type' => 'PART', 'purpose_d_id' => $p['d_id']]);
    $ins = $db->prepare("INSERT INTO purchase_request_item (req_id, item_name, qty_requested, is_urgent,
                         purpose_type, purpose_order_id, purpose_bom, purpose_d_id, purpose_note, purpose_label)
                         VALUES (?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$reqId, '白板筆', 3, 1, null, null, null, null, null, null]);
    $ins->execute([$reqId, '鑽頭', 1, 1, $ip['type'], $ip['order_id'], $ip['bom'], $ip['d_id'], $ip['note'], $ip['label']]);

    $req = $db->query("SELECT * FROM purchase_request WHERE req_id=$reqId")->fetch(PDO::FETCH_ASSOC);
    ck('單頭寫入後讀回 purpose_order_id 正確', (int)$req['purpose_order_id'] === (int)$o['Order_id']);
    ck('單頭 is_urgent 寫入正確', (int)$req['is_urgent'] === 1);

    $items = $db->query("SELECT * FROM purchase_request_item WHERE req_id=$reqId ORDER BY pr_item_id")->fetchAll(PDO::FETCH_ASSOC);
    ck('品項數 2 筆', count($items) === 2);
    $e1 = purchase_purpose_effective($items[0], $req);
    ck('品項一沿用單頭（from=req、拿到訂單 ID）',
       $e1['from'] === 'req' && (int)$e1['order_id'] === (int)$o['Order_id']);
    $e2 = purchase_purpose_effective($items[1], $req);
    ck('品項二用自己的（from=item、拿到料號 ID、不吃到單頭訂單）',
       $e2['from'] === 'item' && (int)$e2['d_id'] === (int)$p['d_id'] && $e2['order_id'] === null);

    // 成本歸戶查得回來：用 Order_id 反查這張單
    $st = $db->prepare("SELECT COUNT(*) FROM purchase_request WHERE purpose_order_id=? AND req_id=?");
    $st->execute([$o['Order_id'], $reqId]);
    ck('可用 Order_id 反查到該申請單（成本歸得了戶）', (int)$st->fetchColumn() === 1);
} finally {
    $db->rollBack();
}
$st = $db->prepare("SELECT COUNT(*) FROM purchase_request WHERE req_no LIKE '__TEST__%'");
$st->execute();
ck('rollback 後正式庫零殘留', (int)$st->fetchColumn() === 0);

echo "\n結果：通過 $pass 項，失敗 $fail 項\n";
exit($fail > 0 ? 1 : 0);
