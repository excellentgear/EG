<?php
/**
 * 修正「ERP 報價單匯入把 4,000 讀成 4」留下的既有錯誤資料（2026-09-21）
 * ──────────────────────────────────────────────────────────────────────
 * 【根因】_upload_For_List.php 的 parseERPQty_erp() 原本用 ^([\d.]+) 取數字，字元類別不含
 *         逗號，所以 ERP 匯出的文字格式「4,000.0」在第一個逗號就停住，被讀成 4。程式已修，
 *         但既有資料救不回來——「4,000→4」與「本來就是 4」在資料庫裡長得一模一樣。
 *
 * 【做法】使用者拍板「比對更新，不動主鍵」：讀 ERP 原始 Excel，用**匯入程式自己那支解析函式**
 *         重新算一次正確數量，只 UPDATE quantity／amount（並重算該張單的 total_amount）。
 *         item_id 完全不動，所以訂單綁到報價項目的 quote_item_id 不會斷。
 *
 * 【安全設計｜這支工具最重要的一件事】
 *         只修「**確定是這個 bug 造成的**」那幾筆：拿舊版的截斷邏輯重算一次「當初會被存成什麼」，
 *         跟資料庫現值一模一樣才動它。現值若是別的數字，代表有人事後手動改過（或另有原因），
 *         一律只列進報告、**不自動覆蓋**——這種修復工具寧可少修一筆，也不可以蓋掉人工修正過的資料。
 *
 * 【用法】cd 到本檔所在目錄後：
 *   php 2026-09-21_fix_erp_quote_qty.php <ERP報價單日報表.xlsx>            ← 試算（預設，不寫入）
 *   php 2026-09-21_fix_erp_quote_qty.php <ERP報價單日報表.xlsx> --run      ← 實際寫入
 *   php 2026-09-21_fix_erp_quote_qty.php <檔案> --show=50                  ← 明細列出前 50 筆（預設 20）
 *   可重複執行：已經修好的第二次跑就不會再出現在待修清單。
 */

if (PHP_SAPI !== 'cli') { exit("這支工具只能用指令列執行\n"); }

$args = array_slice($argv, 1);
$inputs = []; $doRun = false; $show = 20;
foreach ($args as $a) {
    if ($a === '--run') { $doRun = true; }
    elseif (strpos($a, '--show=') === 0) { $show = max(0, (int)substr($a, 7)); }
    else { $inputs[] = $a; }
}
if (!$inputs) {
    exit("用法：php " . basename(__FILE__) . " <檔案或資料夾> [更多檔案…] [--run] [--show=N]\n"
       . "　　　給資料夾會自動抓底下所有 .xls／.xlsx（依檔名排序逐檔處理）\n");
}

// 檔案清單：可以給單檔、多檔，或一個資料夾（整個年度目錄一次跑完）
$files = [];
foreach ($inputs as $in) {
    if (is_dir($in)) {
        foreach (['xls', 'xlsx'] as $ext) {
            foreach (glob(rtrim($in, "/\\") . '/*.' . $ext) as $f) $files[] = $f;
        }
    } elseif (is_file($in)) {
        $files[] = $in;
    } else {
        exit("找不到檔案或資料夾：$in\n");
    }
}
$files = array_values(array_unique($files));
sort($files);
if (!$files) { exit("指定的位置底下沒有 .xls／.xlsx 檔案\n"); }

// _upload_For_List.php 內是相對路徑 require（vendor/autoload 等），一定要先切到它的目錄
$pmDir = dirname(__DIR__);
chdir($pmDir);

// 匯入程式本身：解析函式（parseQuotationErpRows／parseERPQty_erp）由它提供，
// **刻意不在這裡另外寫一份解析**——兩份解析遲早走鐘，修出來的數字就不是匯入會得到的數字。
// 沒有 but 參數時它不會執行任何匯入分支，只會定義函式。
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include $pmDir . '/_upload_For_List.php';
ob_end_clean();

if (!function_exists('parseQuotationErpRows') || !function_exists('parseERPQty_erp')) {
    exit("載入 _upload_For_List.php 後仍取不到解析函式，請確認該檔未被改動\n");
}
if (!isset($db) || !($db instanceof PDO)) {
    require_once $pmDir . '/../../src/common/DBConnection.php';
    $db = (new DBConnection())->getPDO();
}

/**
 * 舊版（有 bug 的）數量解析：就是把修正前那一行原樣搬過來，用來重現「當初會被存成什麼」。
 * 判斷「這筆是不是被這個 bug 弄壞的」全靠它，所以這裡刻意保留錯誤寫法，不要順手修好。
 */
function legacyParseQty_buggy($value) {
    if ($value === null) return null;
    $v = trim((string)$value);
    if ($v === '') return null;
    if (preg_match('/^([\d.]+)/', $v, $m)) return (float)$m[1];   // ← 不去逗號＝當初的行為
    return null;
}

echo "來源：" . count($files) . " 個檔案\n";
foreach ($files as $f) echo "　・" . basename($f) . "\n";
echo "模式：" . ($doRun ? "★ 實際寫入（--run）" : "試算（不寫入任何資料）") . "\n";
echo str_repeat('─', 78) . "\n";

use PhpOffice\PhpSpreadsheet\IOFactory;

// ── 逐檔比對 ────────────────────────────────────────────────────────────
$selQuote = $db->prepare("SELECT quote_id FROM quotation_list WHERE quote_no = ? LIMIT 1");
$selItems = $db->prepare("SELECT item_id, sort_order, product_id, quantity, unit_price, amount
                            FROM quotation_item WHERE quote_id = ? ORDER BY sort_order, item_id");

$toFix = [];        // 確定是 bug 造成的，可自動修
$manual = [];       // 現值與「當初會存成的值」對不起來＝可能被人改過，不自動動
$noQuote = [];      // 原始檔有、系統查無這張報價單
$mismatch = [];     // 明細對不起來（筆數或料號不符）
$okCount = 0;
$quoteSeen = 0;
$skipFiles = [];

foreach ($files as $fi => $file) {
  $base = basename($file);
  printf("[%d/%d] %-22s ", $fi + 1, count($files), $base);

  try {
      $spreadsheet = IOFactory::load($file);
      $allRows = $spreadsheet->getActiveSheet()->toArray();
  } catch (Exception $e) {
      echo "讀取失敗：" . $e->getMessage() . "\n";
      $skipFiles[] = "$base（讀取失敗）";
      continue;
  }

  $scan = '';
  foreach ($allRows as $i => $r) { if ($i >= 30) break; $scan .= implode(' ', array_map('strval', $r)); }
  if (mb_strpos($scan, '客戶報價單日報表') === false) {
      echo "略過：前 30 行找不到「客戶報價單日報表」字樣，可能不是報價單日報表\n";
      $skipFiles[] = "$base（不是報價單日報表）";
      continue;
  }

  $stats = [];
  $groups = parseQuotationErpRows($allRows, $stats);
  $quoteSeen += count($groups);
  $fOk = 0; $fFix = 0; $fManual = 0; $fNo = 0; $fMis = 0;

  foreach ($groups as $qno => $grp) {
    $selQuote->execute([$qno]);
    $quoteId = $selQuote->fetchColumn();
    if (!$quoteId) { $noQuote[] = $qno; $fNo++; continue; }

    $selItems->execute([$quoteId]);
    $dbItems = $selItems->fetchAll(PDO::FETCH_ASSOC);
    $srcRows = $grp['rows'];

    if (count($dbItems) !== count($srcRows)) {
        // 變數一律用大括號括起來：PHP 的變數名允許 \x80-\xff，"$qno（原始檔" 會把後面的中文
        // 一起吃進變數名（變成未定義變數、單號整個不見），而且只有真的走到這一行才看得出來
        $mismatch[] = "{$qno}（原始檔 " . count($srcRows) . " 筆、系統 " . count($dbItems) . " 筆）";
        $fMis++;
        continue;
    }

    foreach ($srcRows as $idx => $src) {
        $dbi = $dbItems[$idx];
        // 料號必須對得上，否則代表順序已經被動過，不可以照位置更新
        if (trim((string)$dbi['product_id']) !== trim((string)$src['product_id'])) {
            $mismatch[] = "{$qno} 第" . ($idx + 1) . "筆（原始檔料號 {$src['product_id']}、系統 {$dbi['product_id']}）";
            $fMis++;
            continue;
        }

        $correct = (int)$src['quantity'];          // 修正後的解析結果（正確值）
        $nowQty  = (int)$dbi['quantity'];
        if ($nowQty === $correct) { $okCount++; $fOk++; continue; }

        // 這一筆到底是不是「被逗號截斷」造成的？拿**這一列自己的數量原字串**用舊邏輯重算一次。
        // 原始字串由 parseQuotationErpRows() 一起帶回來（quantity_raw）——**絕對不可以**回頭用
        // 料號去整份檔案裡找：同一個料號一年內會出現在幾十張報價單上，抓到的是別張單的數量。
        // （第一版就是這樣寫的，報告印出來同一張單三筆的「當初應存成」都是同一個數字，一看就知道錯了。）
        $rawQty = array_key_exists('quantity_raw', $src) ? $src['quantity_raw'] : null;
        $legacy = ($rawQty !== null && trim((string)$rawQty) !== '') ? (int)legacyParseQty_buggy($rawQty) : null;

        $row = ['file' => $base, 'quote_no' => $qno, 'item_id' => (int)$dbi['item_id'],
                'product_id' => $dbi['product_id'], 'now' => $nowQty, 'correct' => $correct,
                'price' => (float)$dbi['unit_price'], 'legacy' => $legacy,
                'raw' => (string)$rawQty, 'quote_id' => (int)$quoteId];
        if ($legacy !== null && $legacy === $nowQty) { $toFix[] = $row; $fFix++; }   // 確定是 bug
        else { $manual[] = $row; $fManual++; }                                       // 可能被人改過
    }
  }
  printf("報價單%5d張｜已正確%6d｜可修正%5d｜待確認%4d｜查無%4d｜對不起來%3d\n",
      count($groups), $fOk, $fFix, $fManual, $fNo, $fMis);
}

// ── 報告 ────────────────────────────────────────────────────────────────
echo str_repeat('─', 78) . "\n";
printf("掃描報價單總數　　：%6d 張\n", $quoteSeen);
printf("數量已正確　　　　：%6d 筆\n", $okCount);
printf("★ 確定被截斷可修正：%6d 筆\n", count($toFix));
printf("需人工確認　　　　：%6d 筆（現值與「當初會被存成的值」對不起來，可能有人改過）\n", count($manual));
printf("系統查無這張報價單：%6d 張\n", count($noQuote));
printf("明細對不起來　　　：%6d 處\n", count($mismatch));

if ($show > 0 && $toFix) {
    echo "\n【可修正明細（前 $show 筆）】\n";
    printf("  %-16s %-22s %10s → %-10s %s\n", '報價單號', '料號', '現在', '應該是', '金額');
    foreach (array_slice($toFix, 0, $show) as $r) {
        printf("  %-16s %-22s %10s → %-10s %s\n", $r['quote_no'], mb_strimwidth($r['product_id'], 0, 22),
            $r['now'], $r['correct'], number_format($r['correct'] * $r['price'], 2));
    }
    if (count($toFix) > $show) echo "  …其餘 " . (count($toFix) - $show) . " 筆未列出（--show=N 可調）\n";
}
if ($show > 0 && $manual) {
    echo "\n【需人工確認（前 $show 筆）｜這些不會被自動修改】\n";
    echo "  「原始檔」＝ERP 上的數量，「當初應存成」＝依當初的 bug 推算出來的值。\n";
    echo "  現值不等於「當初應存成」，代表這一筆後來被人改過（或另有原因），所以不自動覆蓋。\n";
    foreach (array_slice($manual, 0, $show) as $r) {
        printf("  %-14s %-20s 現值 %-8s 原始檔 %-10s（ERP原字 %-10s）當初應存成 %s\n",
            $r['quote_no'], mb_strimwidth($r['product_id'], 0, 20), $r['now'], $r['correct'],
            mb_strimwidth((string)$r['raw'], 0, 10),
            $r['legacy'] === null ? '（無原始字串）' : $r['legacy']);
    }
    if (count($manual) > $show) echo "  …其餘 " . (count($manual) - $show) . " 筆未列出\n";
}
if ($skipFiles) {
    echo "\n【略過的檔案】\n  " . implode("\n  ", $skipFiles) . "\n";
}
if ($show > 0 && $noQuote) {
    echo "\n【系統查無的報價單（前 $show 張）】\n  " . implode('、', array_slice($noQuote, 0, $show)) . "\n";
}
if ($show > 0 && $mismatch) {
    echo "\n【明細對不起來（前 $show 處）｜整張單都不會被修改】\n  " . implode("\n  ", array_slice($mismatch, 0, $show)) . "\n";
}

if (!$doRun) {
    echo "\n" . str_repeat('─', 78) . "\n";
    echo "以上為試算，一個字都沒有寫入。確認無誤後加 --run 參數實際執行。\n";
    exit(0);
}
if (!$toFix) { echo "\n沒有需要修正的資料。\n"; exit(0); }

// ── 寫入 ────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 78) . "\n開始寫入…\n";
$upItem  = $db->prepare("UPDATE quotation_item SET quantity = ?, amount = ? WHERE item_id = ?");
$upTotal = $db->prepare("UPDATE quotation_list SET total_amount =
                            (SELECT ROUND(SUM(amount),2) FROM quotation_item WHERE quote_id = ?)
                          WHERE quote_id = ?");
$db->beginTransaction();
try {
    $n = 0; $quoteIds = [];
    foreach ($toFix as $r) {
        $upItem->execute([$r['correct'], round($r['correct'] * $r['price'], 2), $r['item_id']]);
        $quoteIds[$r['quote_id']] = 1;
        $n++;
    }
    foreach (array_keys($quoteIds) as $qid) $upTotal->execute([$qid, $qid]);   // 單頭合計一併重算
    $db->commit();
    echo "完成：更新明細 $n 筆、重算 " . count($quoteIds) . " 張報價單的合計金額。\n";
    echo "（item_id 全程未變動，訂單綁到報價項目的 quote_item_id 不受影響）\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "寫入失敗，已全部回滾：" . $e->getMessage() . "\n";
    exit(1);
}
