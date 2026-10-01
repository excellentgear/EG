<?php
/**
 * 2026-10-01_gsop_span_x.php
 * 工件規格：①加「轉位係數」欄 ②把「跨齒厚／跨銷徑」那一格正規化成可切換的結構。
 *
 * 使用者 2026-09-30／10-01 指定：
 *   · 要增加轉位係數欄位（根徑才算得出來）
 *   · 跨齒厚欄位要可以點擊後自動切換 跨齒厚／跨銷徑，另外提供一個較小欄位輸入跨齒數或跨銷
 *   · 切換成跨銷徑時左側自動帶入不可刪除的「Ø」
 *   · 右側輸入框要有「範圍」與「上下限」兩種，一樣點選自動切換
 *
 * 正規化後每一格長這樣：k='跨齒厚'、st='w'|'p'（量測型式）、sn=跨幾齒或銷徑、
 * vm='lim'|'rng'（值的寫法）、v=上限或起、v2=下限或迄。
 *
 * **怎麼判斷原本是上下限還是範圍**：紙本上兩種都有——
 *   132.722-132.684（遞減）＝上限－下限；194.64~194.75（遞增）＝範圍。
 * 所以依大小關係推定，**只有一個數字就當上限、不猜下限**。
 *
 * 用法：php 2026-10-01_gsop_span_x.php        試算
 *       php 2026-10-01_gsop_span_x.php --run  實際寫入
 */
if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';

$RUN = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);

/** 這一格是不是「齒厚量測」那一格（名稱逐份不同，一律歸戶） */
function is_span(string $k): bool
{
    foreach (['跨齒厚', '跨齒', '跨珠徑', '跨珠', '跨銷徑', '跨銷', '跨梢徑', '跨梢', '珠徑'] as $a) {
        if (mb_strpos($k, $a) === 0) return true;
    }
    return false;
}
/** 量測型式：量跨齒＝w，量跨珠／跨銷／跨梢＝p */
function span_type(string $k): string
{
    foreach (['跨珠', '跨銷', '跨梢', '珠徑', '銷徑', '梢徑'] as $a) if (mb_strpos($k, $a) !== false) return 'p';
    return 'w';
}
/** 從名稱裡抓「跨幾齒／銷徑多少」：跨齒厚(10齒)→10、跨珠Ø 7→7、珠徑1.30→1.30 */
function span_n(string $k): string
{
    if (preg_match('/[（(]\s*([0-9.]+)\s*齒?\s*[）)]/u', $k, $m)) return $m[1];
    if (preg_match('/[Øø⌀]\s*([0-9.]+)/u', $k, $m))              return $m[1];
    if (preg_match('/([0-9.]+)\s*$/u', $k, $m))                   return $m[1];
    return '';
}
/** 值 → [上限/起, 下限/迄, 寫法]；單一數字只填第一格 */
function split_val(string $v): array
{
    $v = trim($v);
    if ($v === '') return ['', '', 'lim'];
    if (preg_match('/^\s*(-?[0-9.]+)\s*[~～－—–\-]\s*(-?[0-9.]+)\s*$/u', $v, $m)) {
        $a = (float)$m[1]; $b = (float)$m[2];
        // 遞減＝上限－下限；遞增＝範圍（紙本上兩種寫法都有）
        return [$m[1], $m[2], ($a >= $b) ? 'lim' : 'rng'];
    }
    return [$v, '', 'lim'];
}

$log = [];

/* ── ① 範本：跨齒厚那一格正規化 ＋ 補上轉位係數 ── */
$tplPlan = [];
foreach (ss_msop_models($db) as $m) {
    $model = (string)$m['machine_model'];
    foreach (ss_msop_tpl_rows($db, $model, 'soft') as $t) {
        if ((string)$t['step_name'] !== '工件規格') continue;
        $kv = $t['kv']; $chg = false; $hasX = false; $xRow = -1; $xCol = -1;
        foreach ($kv as $ri => $row) {
            foreach ($row as $ci => $p) {
                $k = (string)$p['k'];
                if (mb_strpos($k, '轉位係') === 0) { $hasX = true; $kv[$ri][$ci]['k'] = '轉位係數'; $chg = true; }
                if (!is_span($k)) continue;
                $st = span_type($k); $sn = span_n($k);
                $kv[$ri][$ci]['k']  = ($st === 'p') ? '跨銷徑' : '跨齒厚';
                $kv[$ri][$ci]['st'] = $st;
                $kv[$ri][$ci]['sn'] = $sn;          // 範本上的預設跨齒數／銷徑
                $kv[$ri][$ci]['vm'] = 'lim';
                $chg = true;
            }
            // 記下「根徑」那一列還有沒有空位，轉位係數就插在它旁邊
            foreach ($row as $ci => $p) if ((string)$p['k'] === '根徑' && count($row) < ss_kv_max_cols()) { $xRow = $ri; $xCol = count($row); }
        }
        if (!$hasX) {
            if ($xRow >= 0) { $kv[$xRow][] = ['k' => '轉位係數', 'v' => '', 'p' => '']; }
            else            { $kv[] = [['k' => '轉位係數', 'v' => '', 'p' => '']]; }
            $chg = true;
        }
        if ($chg) $tplPlan[] = ['tpl_id' => (int)$t['tpl_id'], 'model' => $model, 'kv' => $kv];
    }
}
echo "=== 範本（工件規格）要調整：" . count($tplPlan) . " 筆 ===\n";
foreach ($tplPlan as $p) {
    echo "  [{$p['model']}]\n";
    foreach ($p['kv'] as $row) {
        $c = [];
        foreach ($row as $x) {
            $s = $x['k'] !== '' ? $x['k'] : '（空）';
            if (!empty($x['st'])) $s .= '〔' . ($x['st'] === 'p' ? '跨銷徑' : '跨齒厚')
                                     . ($x['sn'] !== '' ? ' ' . $x['sn'] : '') . '〕';
            $c[] = $s;
        }
        echo "      " . implode(' | ', $c) . "\n";
    }
}

/* ── ② 既有文件：同一套正規化，值拆成上下限／範圍 ── */
$docPlan = [];
$rows = $db->query("SELECT s.step_id, s.kv_json, d.doc_id, d.title, v.status
                    FROM ss_step s
                    JOIN ss_ver v ON v.ver_id = s.ver_id
                    JOIN ss_doc d ON d.doc_id = v.doc_id
                    WHERE d.layout='gsop' AND d.is_deleted=0 AND s.sect='soft' AND s.step_name='工件規格'
                    ORDER BY d.doc_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    if ((string)$r['status'] !== 'draft') { $log[] = "略過 doc{$r['doc_id']}（{$r['status']}，不是草稿）"; continue; }
    $kv = ss_kv_decode($r['kv_json']); $chg = false; $hasX = false; $note = [];
    foreach ($kv as $ri => $row) {
        foreach ($row as $ci => $p) {
            $k = (string)$p['k'];
            if (mb_strpos($k, '轉位係') === 0) { $hasX = true; $kv[$ri][$ci]['k'] = '轉位係數'; $chg = true; }
            if (!is_span($k) || !empty($p['st'])) continue;
            $st = span_type($k); $sn = span_n($k);
            [$v1, $v2, $vm] = split_val((string)$p['v']);
            $kv[$ri][$ci]['k']  = ($st === 'p') ? '跨銷徑' : '跨齒厚';
            $kv[$ri][$ci]['st'] = $st;
            $kv[$ri][$ci]['sn'] = $sn;
            $kv[$ri][$ci]['vm'] = $vm;
            $kv[$ri][$ci]['v']  = $v1;
            if ($v2 !== '') $kv[$ri][$ci]['v2'] = $v2;
            $chg = true;
            $note[] = $k . ' → ' . $kv[$ri][$ci]['k'] . ($sn !== '' ? ($st === 'p' ? ' Ø' . $sn : '(' . $sn . '齒)') : '')
                    . '　' . ($vm === 'rng' ? ('範圍 ' . $v1 . '~' . $v2) : ('上限 ' . $v1 . ($v2 !== '' ? ' / 下限 ' . $v2 : '')));
        }
    }
    if (!$hasX) {
        $put = false;
        foreach ($kv as $ri => $row) {
            foreach ($row as $p) if ((string)$p['k'] === '根徑' && count($row) < ss_kv_max_cols()) {
                $kv[$ri][] = ['k' => '轉位係數', 'v' => '', 'p' => '']; $put = true; break 2;
            }
        }
        if (!$put) $kv[] = [['k' => '轉位係數', 'v' => '', 'p' => '']];
        $chg = true; $note[] = '新增「轉位係數」欄（空白）';
    }
    if ($chg) $docPlan[] = ['step_id' => (int)$r['step_id'], 'doc' => (int)$r['doc_id'],
                            'title' => (string)$r['title'], 'kv' => $kv, 'note' => $note];
}
echo "\n=== 既有文件要調整：" . count($docPlan) . " 個步驟 ===\n";
foreach ($docPlan as $p) {
    echo "  doc{$p['doc']} {$p['title']}\n";
    foreach ($p['note'] as $n) echo "      · $n\n";
}
foreach ($log as $l) echo "$l\n";

if (!$tplPlan && !$docPlan) { echo "\n（沒有要變更的項目）\n"; exit; }
if (!$RUN) { echo "\n（試算，加 --run 才寫入）\n"; exit; }

$db->beginTransaction();
try {
    foreach ($tplPlan as $p) {
        $db->prepare("UPDATE ss_msop_tpl SET kv_json=?, modified_at=NOW() WHERE tpl_id=?")
           ->execute([json_encode(ss_kv_decode($p['kv']), JSON_UNESCAPED_UNICODE), $p['tpl_id']]);
    }
    foreach ($docPlan as $p) {
        $db->prepare("UPDATE ss_step SET kv_json=? WHERE step_id=?")
           ->execute([json_encode(ss_kv_decode($p['kv']), JSON_UNESCAPED_UNICODE), $p['step_id']]);
    }
    $db->commit();
    echo "\n[OK] 範本 " . count($tplPlan) . " 筆、文件步驟 " . count($docPlan) . " 個已更新。\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "\n!! 失敗，已全部回復：" . $e->getMessage() . "\n";
    exit(1);
}
