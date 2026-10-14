<?php
/**
 * 批圖編輯器輸出圖(egdraw_*.png) 大檔轉 256 色 PNG（只處理 > 8MB 者）
 *
 *   php 2026-10-14_imgedit_png_256.php            試算（不寫入）
 *   php 2026-10-14_imgedit_png_256.php --run      實際轉換
 *   php 2026-10-14_imgedit_png_256.php --restore  由備份資料夾還原原檔
 *
 * 做法（與使用者 2026-10-14 實測確認的版本相同）：
 *   接近白色(fuzz 6%)的紙面底色先還原成純白（去掉掃描雜點，否則量化後會變成成片灰斜紋）
 *   → 量化 256 色、不抖色 → PNG8。檔名不變、DB 不用改（鐵律5：DB 只存檔名）。
 * 保護：解析度必須相同、與原圖 RMSE 必須 < 2%，否則略過並回報；
 *       原檔不刪，移到 料號附件/_大檔轉256色原檔備份/<d_id>/ 供還原。
 * 不處理：img_*.png（批圖工作檔引用的底圖，轉色會影響再編輯）、非 egdraw_ 的 PNG。
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require_once __DIR__ . '/../../../src/common/DBConnection.php';
$run     = in_array('--run', $argv, true);
$restore = in_array('--restore', $argv, true);
$MIN     = 8 * 1024 * 1024;
$MAGICK  = 'C:\\MAMP\\bin\\ImageMagick-7.1.0-Q16\\magick.exe';

$pdo = (new DBConnection())->getPDO();
$base = rtrim((string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='part_attach_nas_dir'")->fetchColumn(), '\\/');
if ($base === '') { exit("part_attach_nas_dir 未設定\n"); }
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
            if (copy($f, $dst)) { unlink($f); $n++; echo "還原 $did/" . basename($f) . "\n"; }
        }
    }
    exit("已還原 $n 個\n");
}

$todo = [];
foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $d) {
    if (!ctype_digit(basename($d))) continue;
    foreach (glob($d . DIRECTORY_SEPARATOR . 'egdraw_*.png') as $f) {
        $sz = filesize($f);
        if ($sz >= $MIN) $todo[] = [basename($d), $f, $sz];
    }
}
printf("待處理 %d 個，合計 %.0f MB（%s）\n", count($todo), array_sum(array_column($todo, 2)) / 1048576, $run ? '實際轉換' : '試算，加 --run 才會寫入');
if (!$run) { exit; }

$ok = 0; $skip = 0; $fail = 0; $saved = 0;
foreach ($todo as [$did, $f, $sz]) {
    $name = basename($f);
    $t0 = microtime(true);
    // 已是調色盤 PNG 就不再處理（可重複執行）
    [, $type] = sh(q($MAGICK) . ' identify -format "%[type]" ' . q($f . '[0]'));
    if (stripos($type, 'Palette') !== false) { echo "略過(已是256色) $did/$name\n"; $skip++; continue; }
    $loc = $tmp . DIRECTORY_SEPARATOR . 'src_' . $name;
    $out = $tmp . DIRECTORY_SEPARATOR . 'out_' . $name;
    if (!copy($f, $loc)) { echo "失敗(複製) $did/$name\n"; $fail++; continue; }
    [$rc, $msg] = sh(q($MAGICK) . ' ' . q($loc) . ' -fuzz 6% -fill white -opaque white -colors 256 -dither None PNG8:' . q($out));
    $why = '';
    if ($rc !== 0 || !is_file($out)) { $why = '轉檔失敗 ' . substr($msg, 0, 120); }
    else {
        $a = @getimagesize($loc); $b = @getimagesize($out);
        if (!$a || !$b || $a[0] !== $b[0] || $a[1] !== $b[1]) $why = '解析度不一致';
        else {
            [, $cmp] = sh(q($MAGICK) . ' compare -metric RMSE ' . q($loc) . ' ' . q($out) . ' null:');
            if (!preg_match('/\(([\d.eE+-]+)\)/', $cmp, $m) || (float)$m[1] >= 0.02) $why = '與原圖差異過大 ' . $cmp;
        }
    }
    if ($why !== '') { echo "略過 {$did}/{$name}：{$why}\n"; $skip++; @unlink($loc); @unlink($out); continue; }
    // 原檔移到備份 → 新檔放回原名
    $bd = $bak . DIRECTORY_SEPARATOR . $did;
    if (!is_dir($bd) && !@mkdir($bd, 0777, true)) { echo "失敗(建備份資料夾) $did\n"; $fail++; @unlink($loc); @unlink($out); continue; }
    if (!copy($f, $bd . DIRECTORY_SEPARATOR . $name) || filesize($bd . DIRECTORY_SEPARATOR . $name) !== $sz) { echo "失敗(備份) $did/$name\n"; $fail++; @unlink($loc); @unlink($out); continue; }
    if (!copy($out, $f)) { copy($bd . DIRECTORY_SEPARATOR . $name, $f); echo "失敗(寫回，已還原) $did/$name\n"; $fail++; @unlink($loc); @unlink($out); continue; }
    $new = filesize($f);
    $saved += $sz - $new; $ok++;
    printf("OK %s/%s %.1f→%.1f MB (%.0fs)\n", $did, $name, $sz / 1048576, $new / 1048576, microtime(true) - $t0);
    @unlink($loc); @unlink($out);
}
printf("完成：成功 %d、略過 %d、失敗 %d，省下 %.0f MB；原檔備份在 %s\n", $ok, $skip, $fail, $saved / 1048576, $bak);
