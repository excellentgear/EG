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

    echo "\n=== 5. 急件可以只勾其中幾項；單頭旗標由品項回推 ===\n";
    // 先全部設成非急件，單頭應回推為 0
    $db->exec("UPDATE purchase_request_item SET is_urgent=0 WHERE req_id=$reqId");
    $roll = "UPDATE purchase_request r SET r.is_urgent =
             COALESCE((SELECT MAX(pi.is_urgent) FROM purchase_request_item pi WHERE pi.req_id=r.req_id),0)
             WHERE r.req_id=?";
    $db->prepare($roll)->execute([$reqId]);
    $st = $db->prepare("SELECT is_urgent FROM purchase_request WHERE req_id=?"); $st->execute([$reqId]);
    ck('全部非急件→單頭 is_urgent=0', (int)$st->fetchColumn() === 0);

    // 只把第二項設急件
    $db->prepare("UPDATE purchase_request_item SET is_urgent=1 WHERE pr_item_id=?")->execute([$items[1]['pr_item_id']]);
    $db->prepare($roll)->execute([$reqId]);
    $st = $db->prepare("SELECT is_urgent FROM purchase_request WHERE req_id=?"); $st->execute([$reqId]);
    ck('只有一項急件→單頭 is_urgent=1', (int)$st->fetchColumn() === 1);
    $urg = $db->query("SELECT pr_item_id, is_urgent FROM purchase_request_item
                       WHERE req_id=$reqId ORDER BY pr_item_id")->fetchAll(PDO::FETCH_ASSOC);
    ck('逐列急件互不影響（第一項仍為 0、第二項為 1）',
       (int)$urg[0]['is_urgent'] === 0 && (int)$urg[1]['is_urgent'] === 1);

    echo "\n=== 6. 申請單版型角色 purchase_form_full ===\n";
    $rid = $db->query("SELECT role_id FROM roles WHERE role_code='purchase_form_full' AND module='purchase'")->fetchColumn();
    ck('角色 purchase_form_full 已建立', (bool)$rid);
    // 找一個非管理員、且目前沒有任何採購角色的在職者當受測對象
    $tu = $db->query("SELECT u.id, u.user_status FROM user u
                      WHERE u.user_status NOT IN (9,90) AND u.id<>1
                        AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                        WHERE ur.user_id=u.id AND r.module='purchase')
                      ORDER BY u.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$tu) { ck('找得到受測使用者', false, '（全公司都已指派採購角色，略過）'); }
    else {
        $p = purchase_perms($db, ['id' => (int)$tu['id'], 'user_status' => $tu['user_status']]);
        ck('未指派角色者＝精簡版（canFormFull=false）', $p['canFormFull'] === false);
        $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$tu['id'], $rid]);
        $p = purchase_perms($db, ['id' => (int)$tu['id'], 'user_status' => $tu['user_status']]);
        ck('指派後＝採購版（canFormFull=true）', $p['canFormFull'] === true);
    }
    // 採購作業以上自動視為有採購版（不必另外指派 purchase_form_full）
    $bid = $db->query("SELECT role_id FROM roles WHERE role_code='purchase_buy' AND module='purchase'")->fetchColumn();
    $tu2 = $db->query("SELECT u.id, u.user_status FROM user u
                       WHERE u.user_status NOT IN (9,90) AND u.id<>1
                         AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                         WHERE ur.user_id=u.id AND r.module='purchase')
                       ORDER BY u.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($tu2) {
        $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$tu2['id'], $bid]);
        $p = purchase_perms($db, ['id' => (int)$tu2['id'], 'user_status' => $tu2['user_status']]);
        ck('只指派「採購作業」也自動具備採購版', $p['canFormFull'] === true);
    } else {
        echo "  [略] 找不到乾淨的受測使用者，跳過\n";
    }
    echo "\n=== 7. 自訂角色：名稱與功能都由管理員決定 ===\n";
    $tu3 = $db->query("SELECT u.id, u.user_status FROM user u
                       WHERE u.user_status NOT IN (9,90) AND u.id<>1
                         AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                         WHERE ur.user_id=u.id AND r.module='purchase')
                       ORDER BY u.id LIMIT 1 OFFSET 1")->fetch(PDO::FETCH_ASSOC);
    if (!$tu3) { echo "  [略] 找不到第三個乾淨的受測使用者\n"; }
    else {
        // 管理員自建一個名字完全自訂的角色，只勾「登錄到貨」
        $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?,'purchase')")
           ->execute(['role_test_' . $tu3['id'], '倉庫小幫手']);
        $nrid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO role_features (role_id, feature_code) VALUES (?,'purchase_receive')")->execute([$nrid]);
        $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$tu3['id'], $nrid]);
        $p = purchase_perms($db, ['id' => (int)$tu3['id'], 'user_status' => $tu3['user_status']]);
        ck('自訂名稱的角色也算得出權限（勾到貨入庫→canReceive）', $p['canReceive'] === true);
        ck('沒勾的上層權限不會自動給（canBuy=false）', $p['canBuy'] === false);
        ck('包含關係有效（到貨入庫→自動含申請與檢閱）', $p['canApply'] === true && $p['canView'] === true);
        ck('沒勾採購版申請單→用精簡版', $p['canFormFull'] === false);

        // 改名不影響判定（判定看功能碼，不看名稱）
        $db->prepare("UPDATE roles SET role_name=? WHERE role_id=?")->execute(['倉管(改過名字)', $nrid]);
        $p2 = purchase_perms($db, ['id' => (int)$tu3['id'], 'user_status' => $tu3['user_status']]);
        ck('角色改名後權限不變', $p2['canReceive'] === true && $p2['canBuy'] === false);
        ck('頁面標頭顯示的是自訂的角色名稱',
           strpos(purchase_role_names($db, (int)$tu3['id'], $p2), '倉管(改過名字)') !== false,
           '得到：' . purchase_role_names($db, (int)$tu3['id'], $p2));

        // 加勾「看得到金額」後就看得到
        ck('未勾可視金額→canViewAmount=false', $p2['canViewAmount'] === false);
        $db->prepare("INSERT INTO role_features (role_id, feature_code) VALUES (?,'purchase_view_amount')")->execute([$nrid]);
        $p3 = purchase_perms($db, ['id' => (int)$tu3['id'], 'user_status' => $tu3['user_status']]);
        ck('勾了可視金額→canViewAmount=true', $p3['canViewAmount'] === true);
    }

    echo "\n=== 8. 可視內容遮蔽（後端就挖掉，不是只有前端隱藏） ===\n";
    $noSee = ['canViewAmount' => false, 'canViewVendor' => false];
    $sample = ['requester_id' => 999888, 'subtotal' => '100.00', 'tax_amount' => '5.00',
               'grand_total' => '105.00', 'vendor_name' => '某廠商', 'invoice_no' => 'AB123',
               'pay_method' => '匯款',
               'items' => [['unit_price' => '10.0000', 'amount' => '100.00', 'est_price' => '9.0000']]];
    $m = purchase_mask_row($sample, $noSee, 1, false);
    ck('金額被挖掉', $m['grand_total'] === null && $m['subtotal'] === null && $m['tax_amount'] === null);
    ck('品項單價／小計也被挖掉',
       $m['items'][0]['unit_price'] === null && $m['items'][0]['amount'] === null && $m['items'][0]['est_price'] === null);
    ck('廠商／發票／付款方式被挖掉',
       $m['vendor_name'] === null && $m['invoice_no'] === null && $m['pay_method'] === null);
    ck('有標記讓前端顯示遮蔽符號', ($m['masked_amount'] ?? 0) === 1 && ($m['masked_vendor'] ?? 0) === 1);

    // 例外一：自己的單看得到
    $m2 = purchase_mask_row($sample, $noSee, 999888, false);
    ck('自己提的單不遮蔽', $m2['grand_total'] === '105.00' && $m2['vendor_name'] === '某廠商');
    // 例外二：輪到自己簽的單看得到（不然沒辦法簽）
    $m3 = purchase_mask_row($sample, $noSee, 1, true);
    ck('輪到自己簽核的單不遮蔽', $m3['grand_total'] === '105.00');
    // 有權限者當然看得到
    $m4 = purchase_mask_row($sample, ['canViewAmount' => true, 'canViewVendor' => true], 1, false);
    ck('有可視權限者不遮蔽', $m4['grand_total'] === '105.00' && !isset($m4['masked_amount']));
} finally {
    $db->rollBack();
}
$st = $db->prepare("SELECT COUNT(*) FROM purchase_request WHERE req_no LIKE '__TEST__%'");
$st->execute();
ck('rollback 後正式庫零殘留', (int)$st->fetchColumn() === 0);

echo "\n結果：通過 $pass 項，失敗 $fail 項\n";
exit($fail > 0 ? 1 : 0);
