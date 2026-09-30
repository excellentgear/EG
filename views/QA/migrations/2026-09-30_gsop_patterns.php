<?php
/**
 * 2026-09-30_gsop_patterns.php
 * 幫機種步驟範本的「修砂參數／研磨參數」補上**值樣板**（使用者 2026-09-30 指定）：
 *   修砂參數 固定為 單趟 ? mm/ ? 次/轉速 ? rpm   ← 只有 ? 開放填
 *   研磨參數 固定為 單趟 ? mm/ ? 趟
 *   磨削參數II 並排兩個填入欄位
 *
 * **樣板逐機種不同，所以一律寫進該機種自己的範本**，不是全站共用一份：
 * 實測 KX500 的修砂參數就是「單趟0.03mm/8次/轉速100rpm」，
 * 但 LHG-3040 寫的是「0.05mm/次」（沒有轉速）、KAPP 根本沒有修砂參數這一段。
 * 硬套同一個樣板會把 LHG-3040 的固定文字改成紙上沒有的寫法。
 *
 * 用法：php 2026-09-30_gsop_patterns.php        試算
 *       php 2026-09-30_gsop_patterns.php --run  實際寫入
 */
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';

$RUN = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);

/* 「哪一個機種的哪一個步驟、哪一個參數名稱要套哪一個樣板」——唯一登記處。
   之後要調整格式，改這裡再跑一次就好（管理員也可以直接在設定頁改）。 */
$PAT = [
    'KX500' => [
        '修砂參數' => ['*' => '單趟{}mm/{}次/轉速{}rpm'],
        '研磨參數' => ['*' => '單趟{}mm/{}趟'],
    ],
    'LHG-3040' => [
        // 紙本寫的是「0 mm/次」「0.05mm/次」，沒有轉速，所以樣板跟著紙本走
        '修砂參數' => ['*' => '{}mm/次'],
        '研磨參數' => ['*' => '{}mm/趟', '修砂' => '{}mm/齒'],
    ],
];

/** 這一格要套哪個樣板（先找名稱專屬，再退回 * 全段通用；名稱空白的沿用同列前一格） */
function pick_pat(array $map, string $key, string $prev): string
{
    if ($key !== '' && isset($map[$key])) return $map[$key];
    if ($key === '' && $prev !== '') return $prev;      // 磨削參數II 並排的第二格
    return (string)($map['*'] ?? '');
}

$plan = [];
foreach ($PAT as $model => $sects) {
    foreach (ss_msop_tpl_rows($db, $model, 'soft') as $t) {
        $name = (string)$t['step_name'];
        if (!isset($sects[$name])) continue;
        $map = $sects[$name];
        $kv = $t['kv'];
        $changed = false;
        foreach ($kv as $ri => $row) {
            $prev = '';
            foreach ($row as $ci => $p) {
                $pat = pick_pat($map, (string)$p['k'], $prev);
                $prev = $pat;
                if ($pat === '' || (string)($p['p'] ?? '') === $pat) continue;
                $kv[$ri][$ci]['p'] = $pat;
                /* 範本裡原本那些「單趟0.01mm/1趟」是被當成參數**名稱**匯進來的
                   （紙本上它其實是第二個數值欄），套了樣板之後名稱要清掉，
                   不然畫面上會變成「單趟0.01mm/1趟」當標題、後面再接一個空的輸入框。 */
                if (preg_match('/^單趟|^\d/u', (string)$p['k'])) $kv[$ri][$ci]['k'] = '';
                $kv[$ri][$ci]['v'] = '';        // 預設值留給管理員自己在設定頁填
                $changed = true;
            }
        }
        if ($changed) $plan[] = ['model' => $model, 'sect' => 'soft', 'tpl_id' => (int)$t['tpl_id'],
                                 'name' => $name, 'kv' => $kv];
    }
}

echo "═══ 要套樣板的範本項目：" . count($plan) . " 項 ═══\n";
foreach ($plan as $x) {
    echo "  [{$x['model']}] {$x['name']}\n";
    foreach ($x['kv'] as $row) {
        $c = [];
        foreach ($row as $p) $c[] = ($p['k'] !== '' ? $p['k'] : '（無名稱）') . ' → ' . ($p['p'] ?: '（自由文字）');
        echo "      " . implode('　｜　', $c) . "\n";
    }
}
/* ── 既有文件也套上樣板 ──
   匯進來的那 11 份是在樣板出現之前建的，值是一整串純文字（單趟0.03mm/8次/轉速100rpm）。
   用 ss_slot_extract() 把它拆回各個空格，**拆不開的一律原樣不動**
   （硬拆會把現場填的字切爛，這是 ss_slot_extract 的既有約定）。 */
$docPlan = [];
foreach ($db->query("SELECT doc_id, machine_model FROM ss_doc WHERE layout='gsop' AND is_deleted=0")
            ->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $models = ss_models_split((string)$d['machine_model']);
    foreach ($db->query("SELECT ver_id FROM ss_ver WHERE doc_id=" . (int)$d['doc_id'])
                ->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        foreach (ss_step_rows($db, (int)$vid) as $st) {
            if ((string)$st['sect'] !== 'soft') continue;
            $name = (string)$st['step_name'];
            $tplKv = null;
            foreach ($models as $m) {
                foreach (ss_msop_tpl_rows($db, (string)$m, 'soft') as $t) {
                    if ((string)$t['step_name'] === $name) { $tplKv = $t['kv']; break 2; }
                }
            }
            if (!$tplKv) continue;
            $kv = ss_kv_decode($st['kv_json'] ?? '');
            $chg = 0; $fail = 0;
            foreach ($kv as $ri => $row) {
                foreach ($row as $ci => $p) {
                    $pat = (string)($tplKv[$ri][$ci]['p'] ?? '');
                    if ($pat === '' || (string)($p['p'] ?? '') !== '') continue;
                    if (ss_slot_extract($pat, (string)$p['v']) === null) { $fail++; continue; }
                    $kv[$ri][$ci]['p'] = $pat;
                    $chg++;
                }
            }
            if ($chg) $docPlan[] = ['step_id' => (int)$st['step_id'], 'doc' => (int)$d['doc_id'],
                                    'name' => $name, 'kv' => $kv, 'n' => $chg, 'fail' => $fail];
        }
    }
}
echo "\n=== 既有文件要套樣板的步驟：" . count($docPlan) . " 個 ===\n";
$fz = 0;
foreach ($docPlan as $x) {
    echo "  doc{$x['doc']} {$x['name']}：套用 {$x['n']} 格"
       . ($x['fail'] ? "，{$x['fail']} 格對不起來保持原樣" : '') . "\n";
    $fz += $x['fail'];
}
if ($fz) echo "  （對不起來的那幾格維持純文字，畫面上仍然可以自由輸入）\n";
if (!$plan && !$docPlan) { echo "\n（沒有需要變更的項目）\n"; exit; }
if (!$RUN) { echo "\n（試算，加 --run 才寫入）\n"; exit; }

$db->beginTransaction();
try {
    foreach ($docPlan as $x) {
        $db->prepare("UPDATE ss_step SET kv_json=? WHERE step_id=?")
           ->execute([json_encode($x['kv'], JSON_UNESCAPED_UNICODE), $x['step_id']]);
    }
    foreach ($plan as $x) {
        $db->prepare("UPDATE ss_msop_tpl SET kv_json=?, modified_at=NOW() WHERE tpl_id=?")
           ->execute([json_encode($x['kv'], JSON_UNESCAPED_UNICODE), $x['tpl_id']]);
    }
    $db->commit();
    echo "\n[OK] 已寫入：範本 " . count($plan) . " 項、既有文件步驟 " . count($docPlan) . " 個。\n";
    echo "  已建立的文件不受影響（範本只在建立或按「帶入機種範本」時複製過去）。\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "\n!! 失敗，已全部回復：" . $e->getMessage() . "\n";
    exit(1);
}
