<?php
/**
 * 2026-08-25_part_attach_to_as9100.php
 * 料號附件搬家：\\excellentnas\生產課\BOM\ERP\業務\料號資料
 *            → \\excellentnas\AS9100維護\ERP AS9100文件(勿刪)\料號附件
 *
 * 使用者 2026-08-25 明確要求（「都換成 \\excellentnas\AS9100維護\ERP AS9100文件(勿刪)
 * 這種路徑」＝鐵律5 的全站統一附件根資料夾）。
 *
 * 為什麼可以這樣搬：料號附件的實體位置**只由設定鍵 `part_attach_nas_dir` 決定**，
 * DB 只存檔名（`part_attachments.d_id` + `filename`），四支消費端（Part_Attachment_API、
 * ExternalDoc_API、image_editor.php、master_data_management.php）都是讀同一個設定值即時組路徑。
 * 所以「檔案複製過去 → 改設定值」就完成搬家，不必動任何一筆資料。
 *
 * 注意：子資料夾裡除了 part_attachments 的附件，還有**批圖編輯器工作檔內引用的底圖**
 * （`img_*.png`，走 image_editor.php?action=part_file 讀取，同樣吃這個設定值），
 * 所以是「整個資料夾原樣複製」，不是只複製 DB 有登記的那些檔。
 *
 * 用法（一律先 dry 看報告）：
 *   php 2026-08-25_part_attach_to_as9100.php            # 只盤點，不動任何東西
 *   php 2026-08-25_part_attach_to_as9100.php --copy     # 複製檔案（可重複執行，已存在且大小相同者跳過）
 *   php 2026-08-25_part_attach_to_as9100.php --verify   # 逐檔比對來源與目標（數量／大小）
 *   php 2026-08-25_part_attach_to_as9100.php --switch   # 驗證通過後才把設定值改成新位置
 *   php 2026-08-25_part_attach_to_as9100.php --rollback # 把設定值改回舊位置
 *
 * ★ 本次搬家**已完成**（2026-08-25 切換設定值；2026-08-26 依使用者指示刪除舊資料夾，
 *   理由是「避免誤認以為還有在使用」）。刪除前做過全部 737 檔的 md5 逐一比對、
 *   確認新位置一模一樣且切換後沒有任何程式再寫進舊位置。**所以現在跑本檔只會停在
 *   「來源資料夾讀不到」**，那是正確行為，不是壞掉；檔案留著是給下一次搬家當範本用的。
 */

require_once __DIR__ . '/../../../src/common/DBConnection.php';

$S   = DIRECTORY_SEPARATOR;
$TRM = '/' . $S;
$OLD = $S . $S . 'excellentnas' . $S . '生產課' . $S . 'BOM' . $S . 'ERP' . $S . '業務' . $S . '料號資料';
$NEW = $S . $S . 'excellentnas' . $S . 'AS9100維護' . $S . 'ERP AS9100文件(勿刪)' . $S . '料號附件';

$mode = $argv[1] ?? '--dry';
$pdo  = (new DBConnection())->getPDO();

function cur_setting(PDO $pdo): string {
    $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='part_attach_nas_dir'")->fetchColumn();
    return $v === false ? '' : trim((string)$v);
}
function set_setting(PDO $pdo, string $val): void {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
                   VALUES ('part_attach_nas_dir', ?, 'Claude(migration)', NOW())
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                     updated_by=VALUES(updated_by), updated_at=NOW()")->execute([$val]);
}
/** 列出 <base>/<子資料夾>/<檔案>，回 ['相對路徑' => 位元組] */
function list_files(string $base): array {
    $S = DIRECTORY_SEPARATOR;
    $out = [];
    foreach (@scandir($base) ?: [] as $d) {
        if ($d === '.' || $d === '..') continue;
        $dp = $base . $S . $d;
        if (!is_dir($dp)) { if (is_file($dp)) $out[$d] = filesize($dp); continue; }
        foreach (@scandir($dp) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $fp = $dp . $S . $f;
            if (is_file($fp)) $out[$d . '/' . $f] = filesize($fp);
        }
    }
    return $out;
}

echo "來源：$OLD\n目標：$NEW\n目前設定值：", cur_setting($pdo), "\n\n";

if (!is_dir($OLD)) {
    fwrite(STDERR, "來源資料夾讀不到，中止。\n"
                 . "（本次搬家已於 2026-08-25 完成，舊資料夾也已在 2026-08-26 依使用者指示刪除，\n"
                 . "  所以看到這行是正常的；目前設定值＝" . cur_setting($pdo) . "）\n");
    exit(1);
}

$src = list_files($OLD);
$dst = is_dir($NEW) ? list_files($NEW) : [];
printf("來源 %d 個檔（%.1f MB）　目標 %d 個檔（%.1f MB）\n",
    count($src), array_sum($src) / 1048576, count($dst), array_sum($dst) / 1048576);

switch ($mode) {
    case '--copy': {
        if (!is_dir($NEW) && !@mkdir($NEW, 0777, true)) { fwrite(STDERR, "建不出目標資料夾\n"); exit(1); }
        $copied = $skipped = $failed = 0; $bytes = 0; $i = 0;
        foreach ($src as $rel => $size) {
            $i++;
            $to = $NEW . $S . str_replace('/', $S, $rel);
            $dir = dirname($to);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            if (is_file($to) && filesize($to) === $size) { $skipped++; continue; }
            if (@copy($OLD . $S . str_replace('/', $S, $rel), $to)) { $copied++; $bytes += $size; }
            else { $failed++; echo "  複製失敗：$rel\n"; }
            if ($i % 50 === 0) printf("  ...%d/%d\n", $i, count($src));
        }
        printf("複製完成：新增 %d、已存在跳過 %d、失敗 %d（搬了 %.1f MB）\n", $copied, $skipped, $failed, $bytes / 1048576);
        break;
    }
    case '--verify': {
        $miss = $diff = 0;
        foreach ($src as $rel => $size) {
            if (!isset($dst[$rel]))      { $miss++; echo "  目標缺少：$rel\n"; }
            elseif ($dst[$rel] !== $size) { $diff++; echo "  大小不同：{$rel}（來源 $size / 目標 {$dst[$rel]}）\n"; }
        }
        $extra = count(array_diff_key($dst, $src));
        echo ($miss || $diff) ? "驗證未通過：缺少 {$miss}、大小不同 $diff\n"
                              : "驗證通過：$OLD 的每一個檔案都在目標且大小相同（目標另有 $extra 個新檔）\n";
        exit(($miss || $diff) ? 1 : 0);
    }
    case '--switch': {
        // 沒驗證過不准切換：切下去所有頁面立刻改讀新位置，少一個檔就是一張圖打不開
        foreach ($src as $rel => $size) {
            if (!isset($dst[$rel]) || $dst[$rel] !== $size) {
                fwrite(STDERR, "尚未複製完整（{$rel}），請先跑 --copy 與 --verify\n"); exit(1);
            }
        }
        set_setting($pdo, $NEW);
        echo "已切換 part_attach_nas_dir → ", cur_setting($pdo), "\n";
        echo "舊資料夾保留不刪，確認一切正常後再自行刪除：$OLD\n";
        break;
    }
    case '--rollback': {
        // 2026-08-26：使用者確認資料都過去之後，舊資料夾已整個刪除（避免有人誤以為還在用），
        // 所以這條退路已經失效——真的要退回去，得先把檔案從新位置複製回舊位置。
        if (!is_dir($OLD)) {
            fwrite(STDERR, "舊資料夾已不存在（2026-08-26 依使用者指示刪除），不能只改設定值退回去。\n"
                         . "要退回請先把 $NEW 的內容複製回 $OLD，再重跑本指令。\n");
            exit(1);
        }
        set_setting($pdo, $OLD);
        echo "已改回 part_attach_nas_dir → ", cur_setting($pdo), "\n";
        break;
    }
    default:
        echo "（盤點模式，未動任何東西）加 --copy / --verify / --switch / --rollback 執行實際動作\n";
}
