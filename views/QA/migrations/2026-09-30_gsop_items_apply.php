<?php
/**
 * 2026-09-30_gsop_items_apply.php
 * 把「綁定機台的特定料號 SOP」預設檢驗項目（tpl_kind=gproc）套到 Excel 匯進來的那幾份文件上。
 *
 * 使用者 2026-09-30：「你從 excel 幫我建立的 特定料號有綁定機台的 檢驗項目
 * 要自動幫我設定成 有綁定機台的特定料號預設檢驗項目」。
 *
 * **關鍵：套版型，但把紙本上的實際數值帶過去**。
 * 匯進來的那幾份，跨齒厚是 132.722／132.684 這種真實量測上下限，
 * 直接整組取代就會把它們洗掉——版型要統一，數字不能不見。
 * 對照方式：管理重點去掉括號與 {} 之後比對（跨齒厚({}齒) ↔ 跨齒厚(10齒)），
 * 同名多列（外觀×3）再比品質特性；對得上就把上下限／檢具／備註帶過去，
 * 對不上的一律留白（**不猜**）。
 *
 * **預覽與寫入走同一支 build_rows()**，所以列出來的就是會寫進去的。
 * 已送簽／已核准的版次一律不動。
 *
 * 用法：php 2026-09-30_gsop_items_apply.php        試算
 *       php 2026-09-30_gsop_items_apply.php --run  實際寫入
 */
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';

$RUN = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);

/** 管理重點正規化：去掉括號內容與 {}，只留主體（跨齒厚({}齒)／跨齒厚(10齒) → 跨齒厚） */
function norm_ctrl(string $s): string
{
    $s = preg_replace('/[（(][^）)]*[）)]/u', '', $s);
    $s = trim(str_replace(['{', '}', ' ', '　'], '', $s));
    /* 紙本上這一列的名稱逐份不同——跨齒厚／跨齒／跨珠徑／跨銷徑／跨梢徑講的都是
       「齒厚量測」那一列（有的量跨齒、有的量跨珠），**一律歸成同一個對照鍵**。
       不歸戶的話，C5487 的「跨齒(5齒)」與 DRW 的「跨珠徑1.30」會被判成
       「不在預設項目裡」而刪掉——那兩列上面就是紙本唯一的實際上下限。 */
    foreach (['跨齒厚', '跨齒', '跨珠徑', '跨珠', '跨銷徑', '跨銷', '跨梢徑', '跨梢'] as $alias) {
        if (mb_strpos($s, $alias) === 0) return '跨齒厚';
    }
    return $s;
}

/**
 * 依範本組出這一版要寫入的檢驗項目，並把既有那一份自己的數值帶過去。
 * 回傳 [$rows, $log]；$log 是給畫面看的逐列說明。
 */
function build_rows(array $tpl, array $cur, int $own): array
{
    $bucket = [];
    foreach ($cur as $it) $bucket[norm_ctrl((string)$it['ctrl_point'])][] = $it;

    $out = []; $log = []; $carried = 0;
    foreach ($tpl as $t) {
        $r = $t;
        if ($own > 0) { $r['owner_dept_id'] = $own; $r['owner'] = '生產'; }
        $key = norm_ctrl((string)$t['ctrl_point']);
        $pick = null;
        if (!empty($bucket[$key])) {
            if (count($bucket[$key]) === 1) {
                $pick = array_shift($bucket[$key]);
            } else {
                foreach ($bucket[$key] as $i => $o) {          // 同名多列先比品質特性
                    if (trim((string)$o['q_char']) !== '' && trim((string)$o['q_char']) === trim((string)$t['q_char'])) {
                        $pick = $o; unset($bucket[$key][$i]); $bucket[$key] = array_values($bucket[$key]); break;
                    }
                }
                if (!$pick) $pick = array_shift($bucket[$key]);
            }
        }
        if ($pick) {
            foreach (['up_limit', 'lo_limit', 'note'] as $k) {
                if (trim((string)($pick[$k] ?? '')) !== '') $r[$k] = $pick[$k];
            }
            if ((int)($pick['tool_id'] ?? 0) > 0)              $r['tool_id'] = (int)$pick['tool_id'];
            if (trim((string)($pick['tool_no'] ?? '')) !== '') $r['tool_no'] = $pick['tool_no'];
            // 品質特性：範本有寫死（依圖面）就以範本為準，範本留白才沿用舊的
            if (trim((string)($t['q_char'] ?? '')) === '' && trim((string)($pick['q_char'] ?? '')) !== '') {
                $r['q_char'] = $pick['q_char'];
            }
            /* 管理重點：**樣板在 ctrl_pat，ctrl_point 是已經組好（空格留白）的那一份**，
               所以判斷與組字都要用 ctrl_pat，用 ctrl_point 會抓不到 {} 而永遠組出空格。 */
            $pat = (string)($t['ctrl_pat'] ?? '');
            if ($pat !== '' && ss_slot_has($pat)
                && preg_match('/[（(]\s*([^）)]*?)\s*齒?\s*[）)]/u', (string)$pick['ctrl_point'], $m)
                && trim($m[1]) !== '') {
                $r['ctrl_point']  = ss_slot_compose($pat, [trim($m[1])]);
                /* **一定要連 ctrl_slots 一起送**：ss_items_replace() 會用 ctrl_pat+ctrl_slots
                   重組一次 ctrl_point，只送組好的字會被重組成空格版（跨齒厚(齒)）而且不報錯。 */
                $r['ctrl_slots']  = json_encode([trim($m[1])], JSON_UNESCAPED_UNICODE);
            }
            /* 紙本上這一列本來就叫別的名字（跨珠徑1.30／跨齒(5齒)）時**以紙本為準**：
               量的東西不一樣，硬改成「跨齒厚」會讓現場照著量錯。 */
            if (norm_ctrl((string)$pick['ctrl_point']) === '跨齒厚'
                && mb_strpos((string)$pick['ctrl_point'], '跨齒厚') !== 0) {
                $r['ctrl_point'] = (string)$pick['ctrl_point'];
                $r['ctrl_pat']   = '';
                $r['ctrl_slots'] = '';
                $r['lock_ctrl']  = 0;      // 名稱與範本不同，就不要鎖死
            }
            $carried++;
            $log[] = '      ✔ ' . $r['ctrl_point'] . '  ←  ' . $pick['ctrl_point']
                   . (trim((string)($r['up_limit'] ?? '')) !== ''
                        ? '（上 ' . $r['up_limit'] . ' / 下 ' . ($r['lo_limit'] ?? '') . '）' : '');
        } else {
            $log[] = '      · ' . $t['ctrl_point'] . '  （新增，內容留白）';
        }
        $out[] = $r;
    }
    /* 對不到範本的既有列**一律保留接在後面，不可以刪掉**
       ——那上面往往就是這一份自己的量測資料，刪了就再也找不回來。 */
    $left = 0;
    foreach ($bucket as $b) foreach ($b as $o) {
        $left++;
        if ($own > 0) { $o['owner_dept_id'] = $own; $o['owner'] = '生產'; }
        $out[] = $o;
        $log[] = '      ＋ 保留 ' . $o['ctrl_point'] . '（不在預設項目裡，接在最後面）';
    }
    return [$out, $log, $carried, $left];
}

$own  = ss_gsop_owner_dept($db);
$docs = $db->query("SELECT doc_id, title, process_no FROM ss_doc
                    WHERE layout='gsop' AND is_deleted=0 ORDER BY doc_id")->fetchAll(PDO::FETCH_ASSOC);

$plan = []; $skip = [];
foreach ($docs as $d) {
    $tpl = ss_default_items($db, (int)$d['process_no'], null, 'gsop');
    if (!$tpl) { $skip[] = "doc{$d['doc_id']} {$d['title']}：製程 {$d['process_no']} 沒有 SOP 用的預設項目"; continue; }
    foreach ($db->query("SELECT ver_id, ver_no, status FROM ss_ver WHERE doc_id=" . (int)$d['doc_id'])
                ->fetchAll(PDO::FETCH_ASSOC) as $v) {
        if ((string)$v['status'] !== 'draft') {
            $skip[] = "doc{$d['doc_id']} ver{$v['ver_id']}（{$v['status']}）：不是草稿，不動"; continue;
        }
        [$rows, $log, $carried, $left] = build_rows($tpl, ss_item_rows($db, (int)$v['ver_id']), $own);
        $plan[] = ['ver_id' => (int)$v['ver_id'], 'doc' => (int)$d['doc_id'], 'title' => (string)$d['title'],
                   'log' => $log, 'carried' => $carried, 'left' => $left, 'n' => count($rows)];
    }
}

echo "=== 要套用的版次：" . count($plan) . " 個（擔當者一律「生產」）===\n";
foreach ($plan as $p) {
    echo "doc{$p['doc']} ver{$p['ver_id']} {$p['title']}：寫入 {$p['n']} 列，"
       . "帶過舊數值 {$p['carried']} 列" . ($p['left'] ? "，另保留 {$p['left']} 列" : '') . "\n";
    foreach ($p['log'] as $l) echo $l . "\n";
}
foreach ($skip as $s) echo "略過：$s\n";
if (!$plan) { echo "（沒有要處理的版次）\n"; exit; }
if (!$RUN) { echo "\n（試算，加 --run 才寫入）\n"; exit; }

$db->beginTransaction();
try {
    foreach ($plan as $p) {
        $v   = ss_ver_get($db, $p['ver_id']);
        $d   = ss_doc_get($db, (int)$v['doc_id']);
        $tpl = ss_default_items($db, (int)$d['process_no'], null, 'gsop');
        [$rows] = build_rows($tpl, ss_item_rows($db, $p['ver_id']), $own);
        ss_items_replace($db, $p['ver_id'], $rows);
    }
    $db->commit();
    echo "\n[OK] 已套用 " . count($plan) . " 個版次。\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "\n!! 失敗，已全部回復：" . $e->getMessage() . "\n";
    exit(1);
}
