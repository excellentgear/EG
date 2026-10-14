<?php
/**
 * 批圖編輯器輸出圖(egdraw_*.png) 大檔轉 256 色 PNG（只處理 > 8MB 者）
 *
 *   php 2026-10-14_imgedit_png_256.php                  試算（不寫入）
 *   php 2026-10-14_imgedit_png_256.php --run            實際轉換
 *   php 2026-10-14_imgedit_png_256.php --restore        由備份資料夾還原原檔
 *   選項：--rmse=0.04   放寬「與原圖差異」上限（預設 0.02＝2%）
 *         --only=48     只處理某個料號主檔 d_id 資料夾
 *         --log=路徑    同時把輸出寫進記錄檔（料號分頁的「補轉檔」按鈕用它回報進度）
 *
 * 做法（與使用者 2026-10-14 實測確認的版本相同）：
 *   接近白色(fuzz 6%)的紙面底色先還原成純白（去掉掃描雜點，否則量化後會變成成片灰斜紋）
 *   → 量化 256 色、不抖色 → PNG8。檔名不變、DB 不用改（鐵律5：DB 只存檔名）。
 * 保護：解析度必須相同、與原圖 RMSE 必須小於上限，否則略過並回報；
 *       原檔不刪，移到 料號附件/_大檔轉256色原檔備份/<d_id>/ 供還原。
 * 不處理：img_*.png（批圖工作檔引用的底圖，轉色會影響再編輯）、非 egdraw_ 的 PNG。
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require_once __DIR__ . '/../../../src/common/DBConnection.php';
$run     = in_array('--run', $argv, true);
$restore = in_array('--restore', $argv, true);
$MIN     = 8 * 1024 * 1024;
$MAGICK  = 'C:\\MAMP\\bin\\ImageMagick-7.1.0-Q16\\magick.exe';
$maxRmse = 0.02; $only = ''; $logFile = '';
foreach ($argv as $a) {
    if (preg_match('/^--rmse=([0-9.]+)$/', $a, $m) && (float)$m[1] > 0 && (float)$m[1] <= 0.1) $maxRmse = (float)$m[1];
    if (preg_match('/^--only=(\d+)$/', $a, $m)) $only = $m[1];
    if (strpos($a, '--log=') === 0) $logFile = substr($a, 6);
}
// 有指定 --log（背景執行）時只寫記錄檔、不 echo：背景程序的標準輸出管線在父程序結束後就斷了，echo 會讓它直接中止
function out($s) { global $logFile; if ($logFile !== '') { @file_put_contents($logFile, $s, FILE_APPEND); } else { echo $s; } }

$pdo = (new DBConnection())->getPDO();
$base = rtrim((string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='part_attach_nas_dir'")->fetchColumn(), '\\/');
if ($base === '') { out("part_attach_nas_dir 未設定\n完成：失敗\n"); exit(1); }
$bak = $base . DIRECTORY_SEPARATOR . '_大檔轉256色原檔備份';
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'egpng256';
@mkdir($tmp, 0777, true);

function q($s) { return '"' . $s . '"'; }
function sh($cmd) { $o = []; exec($cmd . ' 2>&1', $o, $rc); return [$rc, implode("\n", $o)]; }

if ($restore) {
    $n = 0;
    foreach (glob($bak . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $d) {
        $did = basename($d);
        foreach (glob($d . DIRECTORY_SEPARATOR . 'egdraw_*.png') as $f) {
            $dst = $base . DIRECTORY_SEPARATOR . $did . DIRECTORY_SEPARATOR . basename($f);
            if (copy($f, $dst)) { unlink($f); $n++; out("還原 $did/" . basename($f) . "\n"); }
        }
    }
    out("完成：已還原 $n 個\n");
    exit;
}

$todo = [];
foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $d) {
    if (!ctype_digit(basename($d))) continue;
    if ($only !== '' && basename($d) !== $only) continue;
    foreach (glob($d . DIRECTORY_SEPARATOR . 'egdraw_*.png') as $f) {
        $sz = filesize($f);
        if ($sz < $MIN) continue;
        [, $type] = sh(q($MAGICK) . ' identify -format "%[type]" ' . q($f . '[0]'));
        if (stripos($type, 'Palette') !== false) continue;          // 已是 256 色，轉了也不會變小
        $todo[] = [basename($d), $f, $sz];
    }
}
out(sprintf("待處理 %d 個，合計 %.0f MB（%s）\n", count($todo), array_sum(array_column($todo, 2)) / 1048576, $run ? '實際轉換' : '試算，加 --run 才會寫入'));
if (!$run) { out("完成：試算\n"); exit; }

$ok = 0; $skip = 0; $fail = 0; $saved = 0;
foreach ($todo as [$did, $f, $sz]) {
    $name = basename($f);
    $t0 = microtime(true);
    $loc = $tmp . DIRECTORY_SEPARATOR . 'src_' . $name;
    $outp = $tmp . DIRECTORY_SEPARATOR . 'out_' . $name;
    if (!copy($f, $loc)) { out("失敗(複製) {$did}/{$name}\n"); $fail++; continue; }
    [$rc, $msg] = sh(q($MAGICK) . ' ' . q($loc) . ' -fuzz 6% -fill white -opaque white -colors 256 -dither None PNG8:' . q($outp));
    $why = '';
    if ($rc !== 0 || !is_file($outp)) { $why = '轉檔失敗 ' . substr($msg, 0, 120); }
    else {
        $a = @getimagesize($loc); $b = @getimagesize($outp);
        if (!$a || !$b || $a[0] !== $b[0] || $a[1] !== $b[1]) $why = '解析度不一致';
        else {
            [, $cmp] = sh(q($MAGICK) . ' compare -metric RMSE ' . q($loc) . ' ' . q($outp) . ' null:');
            if (!preg_match('/\(([\d.eE+-]+)\)/', $cmp, $m) || (float)$m[1] >= $maxRmse) $why = '與原圖差異過大 ' . $cmp . '（上限 ' . $maxRmse . '）';
        }
    }
    if ($why !== '') { out("略過 {$did}/{$name}：{$why}\n"); $skip++; @unlink($loc); @unlink($outp); continue; }
    // 原檔移到備份 → 新檔放回原名
    $bd = $bak . DIRECTORY_SEPARATOR . $did;
    if (!is_dir($bd) && !@mkdir($bd, 0777, true)) { out("失敗(建備份資料夾) {$did}\n"); $fail++; @unlink($loc); @unlink($outp); continue; }
    $bf = $bd . DIRECTORY_SEPARATOR . $name;
    if (is_file($bf)) { $bf = $bd . DIRECTORY_SEPARATOR . date('His_') . $name; }   // 備份資料夾已有同名檔（先前備份過）就不覆蓋
    if (!copy($f, $bf) || filesize($bf) !== $sz) { out("失敗(備份) {$did}/{$name}\n"); $fail++; @unlink($loc); @unlink($outp); continue; }
    if (!copy($outp, $f)) { copy($bf, $f); out("失敗(寫回，已還原) {$did}/{$name}\n"); $fail++; @unlink($loc); @unlink($outp); continue; }
    $new = filesize($f);
    $saved += $sz - $new; $ok++;
    out(sprintf("OK %s/%s %.1f→%.1f MB (%.0fs)\n", $did, $name, $sz / 1048576, $new / 1048576, microtime(true) - $t0));
    @unlink($loc); @unlink($outp);
}
out(sprintf("完成：成功 %d、略過 %d、失敗 %d，省下 %.0f MB；原檔備份在 %s\n", $ok, $skip, $fail, $saved / 1048576, $bak));
