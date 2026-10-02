<?php
/**
 * 品質異常處理單「類別」改成 IQC／FQC／製程不良／客訴／退貨，並設定每一類一定要綁什麼
 * （2026-10-02 使用者交辦：IQC・FQC・製程不良＝綁製令、客訴＝綁客戶、退貨＝綁客退單＋製令）
 *
 * 用法：
 *   試算：& C:\MAMP\bin\php\php8.3.1\php.exe views\QA\migrations\2026-10-02_qa_abnormal_cat_bind.php
 *   執行：& C:\MAMP\bin\php\php8.3.1\php.exe views\QA\migrations\2026-10-02_qa_abnormal_cat_bind.php --run
 *
 * 做法（可重複執行，第二次跑一律回報「已是最新狀態」）：
 *   ①「製程中」→ 改名「製程不良」：**不是新建一列再刪舊的**——它是 is_pm_auto=1（報工NG自動開立
 *     要歸入的那一類），而且已經有單在用；新建一列會讓既有的單變成「未指定類別」而且完全看不出原因。
 *   ②「客訴」維持同一列（原本就是這個名字），補上 need_client。
 *   ③「其他」→ **停用不刪除**：使用者指定的五個類別裡沒有它。停用之後新單不再出現這個選項，
 *     既有的單（目前 0 張）仍看得到；真的要清掉請在設定頁自己刪（刪除會擋下「已有單在用」的）。
 *   ④ IQC／FQC／退貨＝新增三列。
 *   ⑤ 後綴詞一律**不動**：那是管理員自己設的，而且改後綴會連帶重算既有單的單號
 *     （qab_order_no_sync_suffix），不該在 migration 裡偷偷做。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
$run = in_array('--run', $argv, true);

require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/qa_abnormal_lib.php';
$db = (new DBConnection())->getPDO();
qab_ensure_schema($db);                 // 三個旗標欄位（need_bom/need_ir/need_client）由它建起來

/* 目標狀態：name => [舊名稱（找得到就改名，找不到就新增）, need_bom, need_ir, need_client, sort_order] */
$want = [
    'IQC'      => ['from' => null,     'bom' => 1, 'ir' => 0, 'cli' => 0, 'sort' => 10],
    'FQC'      => ['from' => null,     'bom' => 1, 'ir' => 0, 'cli' => 0, 'sort' => 20],
    '製程不良' => ['from' => '製程中', 'bom' => 1, 'ir' => 0, 'cli' => 0, 'sort' => 30],
    '客訴'     => ['from' => '客訴',   'bom' => 0, 'ir' => 0, 'cli' => 1, 'sort' => 40],
    '退貨'     => ['from' => null,     'bom' => 1, 'ir' => 1, 'cli' => 0, 'sort' => 50],
];
$deactivate = ['其他'];                 // 使用者指定的五類之外的，一律停用不刪除

$rows = $db->query("SELECT cat_id, name, suffix, is_pm_auto, need_bom, need_ir, need_client, sort_order, is_active
                    FROM qa_abnormal_cat ORDER BY sort_order, cat_id")->fetchAll(PDO::FETCH_ASSOC);
$byName = [];
foreach ($rows as $r) $byName[$r['name']] = $r;

$used = [];                             // 每一類目前有幾張單（停用前要講清楚）
foreach ($db->query("SELECT cat_id, COUNT(*) n FROM qa_abnormal_order GROUP BY cat_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $used[(int)$r['cat_id']] = (int)$r['n'];
}

$plan = [];
foreach ($want as $name => $w) {
    $cur = $byName[$name] ?? (($w['from'] !== null && isset($byName[$w['from']])) ? $byName[$w['from']] : null);
    if (!$cur) {
        $plan[] = ['act' => 'insert', 'name' => $name, 'w' => $w];
        continue;
    }
    $diff = [];
    if ($cur['name'] !== $name)                       $diff[] = '名稱 ' . $cur['name'] . ' → ' . $name;
    if ((int)$cur['need_bom'] !== $w['bom'])          $diff[] = '綁製令 ' . (int)$cur['need_bom'] . ' → ' . $w['bom'];
    if ((int)$cur['need_ir'] !== $w['ir'])            $diff[] = '綁客退單 ' . (int)$cur['need_ir'] . ' → ' . $w['ir'];
    if ((int)$cur['need_client'] !== $w['cli'])       $diff[] = '綁客戶 ' . (int)$cur['need_client'] . ' → ' . $w['cli'];
    if ((int)$cur['sort_order'] !== $w['sort'])       $diff[] = '排序 ' . (int)$cur['sort_order'] . ' → ' . $w['sort'];
    if (!(int)$cur['is_active'])                      $diff[] = '停用 → 啟用';
    if ($diff) $plan[] = ['act' => 'update', 'name' => $name, 'w' => $w, 'cur' => $cur, 'diff' => $diff];
}
foreach ($deactivate as $name) {
    $cur = $byName[$name] ?? null;
    if ($cur && ((int)$cur['is_active'] || (int)$cur['sort_order'] !== 900)) {
        $plan[] = ['act' => 'off', 'name' => $name, 'cur' => $cur, 'used' => $used[(int)$cur['cat_id']] ?? 0];
    }
}

echo "=== 品質異常單類別：目前狀態 ===\n";
foreach ($rows as $r) {
    printf("  #%-3d %-10s 後綴=%-6s 自動=%d 綁[製令%d 客退%d 客戶%d] 排序=%-3d %s　單數=%d\n",
        $r['cat_id'], $r['name'], ($r['suffix'] ?? '') === '' ? '(無)' : $r['suffix'],
        $r['is_pm_auto'], $r['need_bom'], $r['need_ir'], $r['need_client'], $r['sort_order'],
        (int)$r['is_active'] ? '啟用' : '停用', $used[(int)$r['cat_id']] ?? 0);
}
if (isset($used[0]) || array_key_exists('', $used)) { /* cat_id=NULL 會被 GROUP BY 併成 '' */ }
$nullCnt = (int)$db->query("SELECT COUNT(*) FROM qa_abnormal_order WHERE cat_id IS NULL")->fetchColumn();
echo "  （未指定類別的舊單：{$nullCnt} 張——這些單的類別仍是 NULL，不會被本 migration 動到）\n";

echo "\n=== 要做的事 ===\n";
if (!$plan) { echo "  （無，已是最新狀態）\n"; exit(0); }
foreach ($plan as $p) {
    if ($p['act'] === 'insert') {
        printf("  新增 %-10s 綁[製令%d 客退%d 客戶%d] 排序=%d\n", $p['name'], $p['w']['bom'], $p['w']['ir'], $p['w']['cli'], $p['w']['sort']);
    } elseif ($p['act'] === 'update') {
        printf("  修改 #%-3d %-10s：%s\n", $p['cur']['cat_id'], $p['cur']['name'], implode('、', $p['diff']));
    } else {
        printf("  停用 #%-3d %-10s（目前有 %d 張單在用；停用後既有單仍看得到，新單不再出現這個選項）\n",
            $p['cur']['cat_id'], $p['name'], $p['used']);
    }
}
if (!$run) { echo "\n（試算模式，未寫入。要真的執行請加 --run）\n"; exit(0); }

$db->beginTransaction();
try {
    $ins = $db->prepare("INSERT INTO qa_abnormal_cat (name,suffix,is_pm_auto,need_bom,need_ir,need_client,sort_order,is_active)
                         VALUES (?,NULL,0,?,?,?,?,1)");
    $upd = $db->prepare("UPDATE qa_abnormal_cat SET name=?, need_bom=?, need_ir=?, need_client=?, sort_order=?, is_active=1,
                         updated_at=NOW() WHERE cat_id=?");
    // 停用的順便排到最後面（設定頁是依 sort_order 列的，留在五個啟用類別中間看起來像還在用）
    $off = $db->prepare("UPDATE qa_abnormal_cat SET is_active=0, sort_order=900, updated_at=NOW() WHERE cat_id=?");
    foreach ($plan as $p) {
        if ($p['act'] === 'insert')      $ins->execute([$p['name'], $p['w']['bom'], $p['w']['ir'], $p['w']['cli'], $p['w']['sort']]);
        elseif ($p['act'] === 'update')  $upd->execute([$p['name'], $p['w']['bom'], $p['w']['ir'], $p['w']['cli'], $p['w']['sort'], $p['cur']['cat_id']]);
        else                             $off->execute([$p['cur']['cat_id']]);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "失敗（已回滾）：" . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== 完成後狀態 ===\n";
foreach (qab_cats($db, false) as $r) {
    printf("  #%-3d %-10s 後綴=%-6s 自動=%d %s %s\n", $r['cat_id'], $r['name'],
        $r['suffix'] === '' ? '(無)' : $r['suffix'], $r['is_pm_auto'],
        $r['bind_label'], $r['is_active'] ? '啟用' : '停用');
}
/* 綁定規則上線之後，既有的單會不會卡住？逐張確認一次並明確回報——
   例如某一張「退貨」類別的單沒有綁製令，使用者下次開那張單按存檔就會被擋下。
   這裡只回報不自動改資料（要綁哪一張製令是業務判斷，不是 migration 能決定的）。 */
$bad = $db->query("SELECT o.id, o.abnormal_order_no, c.name,
                          c.need_bom, c.need_ir, c.need_client, o.bom_no, o.ir_id, o.client_id
                   FROM qa_abnormal_order o JOIN qa_abnormal_cat c ON c.cat_id=o.cat_id
                   WHERE o.deleted_at IS NULL
                     AND ((c.need_bom=1 AND (o.bom_no IS NULL OR o.bom_no=''))
                       OR (c.need_ir=1 AND (o.ir_id IS NULL OR o.ir_id=0))
                       OR (c.need_client=1 AND (o.client_id IS NULL OR o.client_id='')))")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== 既有單據是否符合新的綁定規則 ===\n";
if (!$bad) {
    echo "  全部符合（不會有單因為新規則而存不回去）\n";
} else {
    echo "  以下 " . count($bad) . " 張單缺了它那一類要求的綁定，下次在處理頁存檔會被擋下，請人工補綁：\n";
    foreach ($bad as $r) {
        $miss = [];
        if ($r['need_bom'] && !$r['bom_no']) $miss[] = '製令';
        if ($r['need_ir'] && !$r['ir_id'])   $miss[] = '客退單';
        if ($r['need_client'] && !$r['client_id']) $miss[] = '客戶';
        printf("  - %s（%s）缺：%s\n", $r['abnormal_order_no'], $r['name'], implode('、', $miss));
    }
}
