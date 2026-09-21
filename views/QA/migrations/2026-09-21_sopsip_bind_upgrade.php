<?php
/**
 * 2026-09-21_sopsip_bind_upgrade.php — 把既有的 SOP／SIP 文件接上新的綁定結構
 * （使用者 2026-09-21 二次交辦那一批：製程改綁主檔、設備 SOP 改綁機台型號、客戶由料號帶入）
 *
 * 用法（CLI）：
 *   php 2026-09-21_sopsip_bind_upgrade.php          試算，只印出會改什麼，不寫入
 *   php 2026-09-21_sopsip_bind_upgrade.php --run    實際寫入
 *
 * 可重複執行：已經有值的欄位不會被蓋掉，只補空的。
 *
 * 做四件事
 *   ① ss_doc.machine_model ← 該機台的 machine_list.machine_model（設備 SOP 從此綁型號）
 *   ② ss_doc_machine       ← 原本綁的那一台先掛上去（同型號其他台由使用者自己在畫面上勾）
 *      **刻意不自動把同型號全部掛上去**：那 11 份是照紙本一台一份匯進來的，
 *      自動合併會讓「EG-016 的 SOP」突然變成也涵蓋 EG-017、EG-025，那是使用者的決定不是我的。
 *   ③ ss_doc.process_no    ← 用原本的 proc_name 文字對回 process_no 主檔（完全相同才算數）
 *   ④ ss_doc.customer_id/name ← 綁料號者由料號主檔帶出（同 ss_customer_of_part）
 */

$ROOT = realpath(__DIR__ . '/../../..');
require_once $ROOT . '/src/common/_config.php';
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';

$RUN = in_array('--run', $argv, true);
$db  = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);

function say(string $s): void { echo $s . PHP_EOL; }

say($RUN ? '=== 實際寫入 ===' : '=== 試算（沒有 --run，不會寫入）===');

/* 製程名稱 → 編號（完全相同才對，猜錯比沒對到更糟） */
$procMap = [];
foreach ($db->query("SELECT ProcessNo, ProcessName FROM process_no")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $nm = trim((string)$r['ProcessName']);
    if ($nm === '') continue;
    if (isset($procMap[$nm])) { $procMap[$nm] = 0; continue; }   // 同名多筆＝分不出來，一律不對
    $procMap[$nm] = (int)$r['ProcessNo'];
}

$docs = $db->query("SELECT * FROM ss_doc WHERE is_deleted=0 ORDER BY doc_id")->fetchAll(PDO::FETCH_ASSOC);
$n = ['model' => 0, 'dm' => 0, 'proc' => 0, 'cust' => 0, 'skip_proc' => []];

foreach ($docs as $d) {
    $docId = (int)$d['doc_id'];
    $sets = []; $par = [];

    // ① 機台型號
    if ((string)$d['scope'] === 'machine' && trim((string)($d['machine_model'] ?? '')) === '') {
        $m = ss_machine_row($db, (int)$d['machine_id']);
        $model = trim((string)($m['machine_model'] ?? ''));
        if ($model !== '') {
            $sets[] = 'machine_model=?'; $par[] = $model; $n['model']++;
            say("  #$docId {$d['title']} → 型號 $model");
        }
    }

    // ③ 製程
    if ((int)($d['process_no'] ?? 0) <= 0) {
        $nm = trim((string)($d['proc_name'] ?? ''));
        if ($nm !== '') {
            $no = $procMap[$nm] ?? 0;
            if ($no > 0) {
                $sets[] = 'process_no=?'; $par[] = $no; $n['proc']++;
                say("  #{$docId} 製程「{$nm}」→ 編號 {$no}");
            } else {
                $n['skip_proc'][$nm] = ($n['skip_proc'][$nm] ?? 0) + 1;
            }
        }
    }

    // ④ 客戶
    if ((string)$d['scope'] === 'part' && trim((string)($d['customer_id'] ?? '')) === '') {
        $c = ss_customer_of_part($db, (int)$d['part_d_id']);
        if ($c['id'] !== '') {
            $sets[] = 'customer_id=?'; $par[] = $c['id'];
            $sets[] = 'customer_name=?'; $par[] = $c['name'];
            $n['cust']++;
            say("  #$docId {$d['part_no_text']} → 客戶 {$c['id']} {$c['name']}");
        }
    }

    if ($sets && $RUN) {
        $par[] = $docId;
        $db->prepare("UPDATE ss_doc SET " . implode(',', $sets) . " WHERE doc_id=?")->execute($par);
    }

    // ② 機器編號明細（原本綁的那一台）
    if ((string)$d['scope'] === 'machine' && (int)$d['machine_id'] > 0) {
        $st = $db->prepare("SELECT COUNT(*) FROM ss_doc_machine WHERE doc_id=?");
        $st->execute([$docId]);
        if ((int)$st->fetchColumn() === 0) {
            $n['dm']++;
            if ($RUN) $db->prepare("INSERT IGNORE INTO ss_doc_machine (doc_id, machine_id) VALUES (?,?)")
                         ->execute([$docId, (int)$d['machine_id']]);
        }
    }
}

say('');
say("補上機台型號：{$n['model']} 份");
say("建立機器編號明細：{$n['dm']} 份");
say("對上製程編號：{$n['proc']} 份");
say("補上客戶：{$n['cust']} 份");
if ($n['skip_proc']) {
    say('');
    say('下列製程文字在 process_no 主檔對不到（維持原樣，要用製程功能請到畫面上重挑）：');
    // 中文標點在 PHP 8 算識別字的一部分，"$nm」" 會被當成變數名——一律用 {$nm} 包起來
    foreach ($n['skip_proc'] as $nm => $c) say("  「{$nm}」× {$c}");
}
say($RUN ? '完成。' : '以上只是試算，加 --run 才會寫入。');
