<?php
/**
 * 把既有的 Word 程序書匯入成「線上版內容」 ── 唯一實作 ──
 *
 * 使用者的要求（2026-09-22 原話）：「你如果無法幫我自動轉，就轉可以轉的，
 * 其他出現提示檔案內哪些還沒轉（例如圖片、表格…）」。所以這支的原則是
 * **能轉的先轉進去，轉不動的一項一項列出來叫人補**，不硬轉也不安靜地吞掉。
 *
 * ── 實測結論（2026-09-22，拿 2-GM-01 管理責任與經營規劃程序 115KB 的 .doc 實跑）──
 *  ⑴ 全庫 86 份二階版本檔裡 **84 個是 `.doc` 舊二進位格式**，只有 2 個 `.docx`。
 *     PHPWord 的 Reader\MsDoc 只讀得出文字（表格與圖片會掉），所以**一律走 LibreOffice**
 *     轉 HTML（已裝 26.2.4.2，attachment_lib 的 eg_att_soffice_convert 已參數化）。
 *     實測 10 秒轉完，6 張表格／52 列／161 格全部保住，純文字 4,563 字。
 *  ⑵ **輸出是 UTF-8**（實測整份 1,115 行零非法位元組），不必轉碼；但仍保留防禦性檢查。
 *  ⑶ **流程圖是混合體，這是最重要的一點**：
 *       方框＝Word 文字方塊 → `<span style="float:left;width:3.55cm;border:1px solid #000">`
 *             ＝**文字與框線都救得回來**（實測該檔有 69 個）
 *       箭頭與連接線＝Word 繪圖物件 → 匯出成一個一個小 GIF 碎片，
 *             `name="DrawObject13"`、尺寸 43x134 / 238x72 這種（實測 14 個 img 指向 8 個檔）
 *     這些碎片**單獨貼回去不會組成流程圖**（位置靠 Word 的繪圖畫布，HTML 裡沒有那個座標系），
 *     所以刻意**不匯入**，改成在未轉換清單寫明「請用『插入流程圖』重畫」。
 *     `DrawObject` 是 LibreOffice 的固定命名，可以精準判定，不會誤傷真正的照片或圖片。
 */

if (!defined('EG_AS_DOC_IMPORT_LIB')) {
define('EG_AS_DOC_IMPORT_LIB', 1);

require_once __DIR__ . '/as_doc_content_lib.php';
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/attachment_lib.php';

/** 認得的 Word 副檔名 */
define('ADI_WORD_EXT', ['doc', 'docx', 'rtf', 'odt']);
/** 一頁的內容寬（px）：A4 直式 210mm 扣掉左右各 15mm 邊界＝180mm ≒ 680px。
 *  表格比這個寬就一定會溢出紙張，一律改成 100%。 */
define('ADI_PAGE_CONTENT_PX', 680);

/**
 * 匯入某版次自己掛的 Word 檔。
 * @param bool $overwrite 已經有線上內容時要不要蓋掉（預設不蓋，避免把人工編好的內容洗掉）
 * @return array ['ok'=>bool,'msg'=>string,'report'=>array,'content_id'=>int]
 */
function adi_import_version(PDO $db, int $versionId, int $uid, bool $overwrite = false): array
{
    $v = adc_version_info($db, $versionId);
    if (!$v) return ['ok' => false, 'msg' => '找不到這個版次'];

    $exist = adc_content_by_version($db, $versionId);
    if ($exist && trim((string)$exist['content_html']) !== '' && !$overwrite) {
        return ['ok' => false, 'msg' => '這個版次已經有線上內容了。要用 Word 重新匯入請先確認會覆蓋現有內容。'];
    }

    $file = (string)($v['file_name'] ?? '');
    if ($file === '') return ['ok' => false, 'msg' => '這個版次沒有上傳 Word 原始檔，沒有東西可以匯入'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, ADI_WORD_EXT, true)) {
        return ['ok' => false, 'msg' => '原始檔是 .' . $ext . '，不是 Word 檔（只能匯入 ' . implode('／', ADI_WORD_EXT) . '）'];
    }
    $src = eg_asdoc_doc_dir($db, (int)$v['doc_id']) . DIRECTORY_SEPARATOR . $file;
    if (!is_file($src)) return ['ok' => false, 'msg' => '在 NAS 上找不到原始檔：' . $file];

    return adi_import_file($db, $versionId, $src, (string)($v['original_name'] ?: $file), $uid);
}

/**
 * 把一個 Word 檔轉成線上內容。
 * 流程：LibreOffice 轉 HTML → 前置整理（字型、欄寬、圖片）→ doc profile 清洗 → 存檔
 */
function adi_import_file(PDO $db, int $versionId, string $src, string $showName, int $uid): array
{
    $content = adc_content_ensure($db, $versionId, $uid);
    if (!$content) return ['ok' => false, 'msg' => '找不到這個版次'];
    $contentId = (int)$content['id'];

    // 轉檔工作區：用乾淨的 ASCII 檔名，中文檔名在 LibreOffice 的 CLI 上不可靠
    $work = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'adi_' . bin2hex(random_bytes(6));
    if (!@mkdir($work, 0777, true)) return ['ok' => false, 'msg' => '無法建立轉檔暫存資料夾'];
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $tmpSrc = $work . DIRECTORY_SEPARATOR . 'src.' . $ext;
    if (!@copy($src, $tmpSrc)) { adi_rrmdir($work); return ['ok' => false, 'msg' => '無法複製原始檔到暫存區'];}

    $t0 = microtime(true);
    $htmlFile = eg_att_soffice_convert($tmpSrc, $work, 240, 'html:HTML (StarWriter)');
    $secs = round(microtime(true) - $t0, 1);
    if (!$htmlFile || !is_file($htmlFile)) {
        adi_rrmdir($work);
        return ['ok' => false, 'msg' => 'LibreOffice 轉檔失敗（可能是檔案損毀或有密碼保護）。請改用「上傳 Word」維持原本的做法，或另存成 .docx 再試。'];
    }

    $raw = (string)file_get_contents($htmlFile);
    // 防禦性轉碼：LibreOffice 實測輸出 UTF-8，但萬一哪台機器的系統編碼不同，
    // 不轉的話整份中文會變亂碼而且完全不報錯
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $cs = 'BIG-5';
        if (preg_match('/charset=([\w-]+)/i', substr($raw, 0, 2000), $m)) $cs = strtoupper($m[1]);
        $conv = @mb_convert_encoding($raw, 'UTF-8', $cs);
        if ($conv !== false && mb_check_encoding($conv, 'UTF-8')) $raw = $conv;
    }

    $res = adi_transform($db, $raw, $work, $contentId, $uid);
    adi_rrmdir($work);

    $clean = eg_richtext_sanitize($res['html'], ADC_MAX_LEN, 'doc');
    if (trim($clean) === '') {
        return ['ok' => false, 'msg' => '轉出來是空白的——這份 Word 可能整份都是圖片或繪圖物件，沒有可以轉入的文字。'];
    }

    $report = adi_build_report($res, $showName, $secs, $clean);
    try {
        $st = $db->prepare("UPDATE as_doc_content
            SET content_html=?, import_src=?, import_report_json=?, imported_at=NOW(),
                updated_by=?, updated_at=NOW()
            WHERE id=?");
        $st->execute([$clean, $showName, json_encode($report, JSON_UNESCAPED_UNICODE), $uid ?: null, $contentId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => '寫入失敗：' . $e->getMessage()];
    }
    // 匯入時建立的資產若最後沒被留在內容裡（例如清洗階段被剝掉），一併回收
    $used = [];
    if (preg_match_all('/data-asset="(\d+)"/', $clean, $m)) foreach ($m[1] as $x) $used[(int)$x] = true;
    adc_asset_gc($db, $contentId, array_keys($used));

    return ['ok' => true, 'msg' => '已匯入', 'report' => $report, 'content_id' => $contentId];
}

/**
 * HTML 前置整理：把 LibreOffice 的輸出改寫成 doc profile 留得住的形狀，
 * 並把圖片收成資產。**一定要在清洗之前做**，否則 <font> 與 width 屬性會先被脫殼丟掉。
 */
function adi_transform(PDO $db, string $raw, string $workDir, int $contentId, int $uid): array
{
    $stat = ['img_in' => 0, 'img_ok' => 0, 'draw' => 0, 'draw_px' => [], 'img_fail' => 0,
             'tables' => 0, 'boxes' => 0, 'objects' => 0, 'pagebreaks' => 0, 'tbl_shrunk' => 0,
             'borders_dropped' => 0];

    // 只取 <body> 內容；<style>/<head> 整段丟掉（清洗器也會擋，但先丟掉省得白做工）
    if (preg_match('#<body[^>]*>(.*)</body>#is', $raw, $m)) $raw = $m[1];
    $raw = (string)preg_replace('#<(script|style|head|title|meta|link)\b.*?</\1>#is', '', $raw);
    $raw = (string)preg_replace('#<(meta|link)\b[^>]*>#i', '', $raw);
    $raw = (string)preg_replace('#<!--.*?-->#s', '', $raw);

    $stat['tables']     = preg_match_all('#<table\b#i', $raw);
    $stat['boxes']      = preg_match_all('#border:\s*1px solid#i', $raw);
    $stat['objects']    = preg_match_all('#<(object|embed|svg|applet)\b#i', $raw);
    $stat['pagebreaks'] = preg_match_all('#page-break-before\s*:\s*always#i', $raw);

    // ① <font face size style> → <span style="font-family:…;font-size:…">
    //    <font> 不在白名單，直接清洗會脫殼＝字型字級全部不見
    $raw = (string)preg_replace_callback('#<font\b([^>]*)>#i', function ($mm) {
        $at = $mm[1];
        $css = [];
        if (preg_match('/face="([^"]*)"/i', $at, $f)) {
            $fam = trim($f[1]);
            // 只收字母/中文/逗號/引號/空白/連字號——有括號的一律不要（url() 之類）
            if ($fam !== '' && preg_match('/^[\p{Han}\w\s,\'"\-]{1,110}$/u', $fam)) {
                $css[] = 'font-family:' . $fam;
            }
        }
        if (preg_match('/font-size:\s*([\d.]+)pt/i', $at, $s)) {
            $pt = (float)$s[1];
            if ($pt >= 1 && $pt < 100) $css[] = 'font-size:' . rtrim(rtrim(number_format($pt, 1, '.', ''), '0'), '.') . 'pt';
        }
        return $css ? '<span style="' . implode(';', $css) . '">' : '<span>';
    }, $raw);
    $raw = (string)preg_replace('#</font>#i', '</span>', $raw);

    // ②-0 Word 的分頁符：LibreOffice 匯出成 page-break-before:always。
    //     線上版的頁界是 <hr style="page-break-after:always">（見 eg_richtext.js 的分頁說明），
    //     所以在那個區塊「之前」補一個頁界，匯入進來就已經照 Word 的分頁切好。
    $raw = (string)preg_replace(
        '#<(p|div|table|h[1-6])\b([^>]*style="[^"]*page-break-before\s*:\s*always[^"]*")#i',
        '<hr style="page-break-after:always"><\1\2', $raw);

    /* ②-1 把「非表格元素」上的框線拿掉（使用者 2026-09-22：匯入 WORD 時就不需匯入外框線）。
       Word 的頁面外框與那些當版面用的文字方塊，LibreOffice 會轉成
       `<span style="...;border:1px solid #000">`／`<div style="border:…">`；
       線上版的頁框（頁首頁尾與外框）本來就由系統自己畫，再匯一份進來就是**兩層框**。
       **表格的框線一律保留**——那是內容，不是版面。 */
    $raw = (string)preg_replace_callback('#<(span|div|p)\b([^>]*)>#i', function ($mm) use (&$stat) {
        $tag = $mm[1]; $at = $mm[2];
        if (stripos($at, 'border') === false) return $mm[0];
        if (!preg_match('#style="([^"]*)"#i', $at, $st)) return $mm[0];
        $keep = [];
        $dropped = false;
        foreach (explode(';', $st[1]) as $decl) {
            if (trim($decl) === '') continue;
            // border / border-top / border-left … 一律丟掉；border-radius 之類也一起（沒有意義）
            if (preg_match('/^\s*border(-[a-z]+)*\s*:/i', $decl)) { $dropped = true; continue; }
            $keep[] = trim($decl);
        }
        if (!$dropped) return $mm[0];
        $stat['borders_dropped']++;
        $newStyle = implode(';', $keep);
        $at = $newStyle === ''
            ? str_replace($st[0], '', $at)
            : str_replace($st[0], 'style="' . $newStyle . '"', $at);
        return '<' . $tag . $at . '>';
    }, $raw);

    // ② 表格的 width="112" 屬性 → style width:112px（屬性不在白名單，欄寬會全丟）
    //    ⚠ 超過「一頁的內容寬」的一律改成 100%：Word 的絕對像素寬加上儲存格內距與框線之後
    //      常常比紙張內容區還寬，直接照搬就會溢出紙張（A4 直式內容寬 180mm≒680px）。
    $raw = (string)preg_replace_callback('#<(table|td|th|col)\b([^>]*)>#i', function ($mm) use (&$stat) {
        $tag = strtolower($mm[1]); $at = $mm[2];
        if (!preg_match('/\bwidth="(\d{1,4})"/i', $at, $w)) return $mm[0];
        $px = (int)$w[1];
        if ($px <= 0 || $px > 2000) return $mm[0];
        if ($tag === 'table' && $px > ADI_PAGE_CONTENT_PX) {
            $stat['tbl_shrunk']++;
            $at = preg_replace('/\bwidth="\d{1,4}"/i', '', $at);
            if (preg_match('/style="([^"]*)"/i', $at, $s2)) {
                $at = str_replace($s2[0], 'style="' . rtrim($s2[1], '; ') . ';width:100%"', $at);
            } else { $at .= ' style="width:100%"'; }
            return '<' . $tag . $at . '>';
        }
        $at = preg_replace('/\bwidth="\d{1,4}"/i', '', $at);
        if (preg_match('/style="([^"]*)"/i', $at, $s)) {
            $at = str_replace($s[0], 'style="' . rtrim($s[1], '; ') . ';width:' . $px . 'px"', $at);
        } else {
            $at .= ' style="width:' . $px . 'px"';
        }
        return '<' . $tag . $at . '>';
    }, $raw);

    // ③ 圖片：繪圖物件碎片不匯入（見檔頭說明），真正的圖片收成資產
    $raw = (string)preg_replace_callback('#<img\b([^>]*)>#i', function ($mm) use (&$stat, $db, $workDir, $contentId, $uid) {
        $at = $mm[1];
        $stat['img_in']++;
        $name = preg_match('/\bname="([^"]*)"/i', $at, $n) ? $n[1] : '';
        $src  = preg_match('/\bsrc="([^"]*)"/i', $at, $s) ? $s[1] : '';

        if (stripos($name, 'DrawObject') === 0) {
            $stat['draw']++;
            $wpx = preg_match('/\bwidth="(\d+)"/i', $at, $w)  ? (int)$w[1] : 0;
            $hpx = preg_match('/\bheight="(\d+)"/i', $at, $h) ? (int)$h[1] : 0;
            // 只有其中一邊有值是常態（純橫線只寫 height），印成「0x34」看起來像壞掉
            if ($wpx && $hpx)      $stat['draw_px'][] = $wpx . '×' . $hpx;
            elseif ($wpx || $hpx)  $stat['draw_px'][] = ($wpx ? '寬' . $wpx : '高' . $hpx);
            return '';   // 不匯入，改由未轉換清單提醒重畫
        }
        if ($src === '' || preg_match('#^(https?:)?//#i', $src)) { $stat['img_fail']++; return ''; }

        // 只收轉檔暫存區裡的檔（LibreOffice 會把圖片吐在 HTML 旁邊），
        // 檔名一律 basename——擋掉 ../ 路徑穿越
        $p = $workDir . DIRECTORY_SEPARATOR . basename(rawurldecode($src));
        if (!is_file($p)) { $stat['img_fail']++; return ''; }
        $bytes = (string)@file_get_contents($p);
        $ex = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $r = adc_asset_store($db, $contentId, 'image', $bytes, $ex, basename($p), null, $uid);
        if (empty($r['ok'])) { $stat['img_fail']++; return ''; }
        $stat['img_ok']++;

        $style = '';
        if (preg_match('/\bwidth="(\d+)"/i', $at, $w) && (int)$w[1] > 0 && (int)$w[1] <= 2000) {
            $style = ' style="width:' . (int)$w[1] . 'px"';
        }
        return '<img data-asset="' . (int)$r['id'] . '"' . $style . '>';
    }, $raw);

    return ['html' => $raw, 'stat' => $stat];
}

/** 組出「轉了什麼／還沒轉什麼」清單，直接顯示在編輯器上當待補提示 */
function adi_build_report(array $res, string $showName, float $secs, string $cleanHtml): array
{
    $s = $res['stat'];
    $done = []; $todo = [];

    $chars = mb_strlen(eg_richtext_to_text($cleanHtml), 'UTF-8');
    $done[] = ['type' => 'text',  'n' => $chars,       'note' => '文字已轉入約 ' . number_format($chars) . ' 字'];
    if ($s['tables'])     $done[] = ['type' => 'table', 'n' => $s['tables'], 'note' => '表格 ' . $s['tables'] . ' 張已轉入（含框線與合併儲存格）'];
    if ($s['img_ok'])     $done[] = ['type' => 'image', 'n' => $s['img_ok'], 'note' => '圖片 ' . $s['img_ok'] . ' 張已轉入'];
    if ($s['boxes'])      $done[] = ['type' => 'box',   'n' => $s['boxes'],  'note' => '帶框線的文字方塊 ' . $s['boxes'] . ' 個的文字已轉入'];
    if (!empty($s['borders_dropped'])) $done[] = ['type' => 'box', 'n' => $s['borders_dropped'],
        'note' => 'Word 版面用的外框線 ' . $s['borders_dropped'] . ' 處沒有匯入（頁框與頁首頁尾由系統自己畫，再匯一份進來會變成兩層框）；表格本身的框線都有保留'];
    if ($s['pagebreaks']) $done[] = ['type' => 'pagebreak', 'n' => $s['pagebreaks'],
        'note' => 'Word 的分頁 ' . $s['pagebreaks'] . ' 處已轉成線上版的分頁（已經幫你切成一頁一頁）'];
    if (!empty($s['tbl_shrunk'])) $done[] = ['type' => 'table', 'n' => $s['tbl_shrunk'],
        'note' => '有 ' . $s['tbl_shrunk'] . ' 張表格原本的固定寬度比一頁還寬，已改成「滿版寬度」避免超出紙張'];

    if ($s['draw']) {
        $sizes = array_slice(array_unique($s['draw_px']), 0, 6);
        $todo[] = ['type' => 'flow', 'n' => $s['draw'], 'level' => 'must',
            'note' => 'Word 繪圖物件 ' . $s['draw'] . ' 個沒有轉入（流程圖的方框連接線與箭頭'
                    . ($sizes ? '，尺寸如 ' . implode('、', $sizes) : '') . '）。'
                    . '這些在 Word 裡是靠繪圖畫布定位的，單獨拆出來不會組回原來的流程圖，'
                    . '所以請用工具列的「插入流程圖」重畫一次；方框裡的文字大多已經轉進來了，可以照著打。'];
    }
    if ($s['img_fail']) {
        $todo[] = ['type' => 'image', 'n' => $s['img_fail'], 'level' => 'must',
            'note' => '有 ' . $s['img_fail'] . ' 張圖片轉不進來（檔案抓不到或不是有效圖片），請自行用「插入圖片」補上。'];
    }
    if ($s['objects']) {
        $todo[] = ['type' => 'object', 'n' => $s['objects'], 'level' => 'must',
            'note' => '內嵌物件（OLE／方程式／SmartArt 之類）' . $s['objects'] . ' 個沒有轉入，需要人工重做。'];
    }
    $todo[] = ['type' => 'headfoot', 'n' => 0, 'level' => 'info',
        'note' => 'Word 的頁首頁尾沒有轉入——這是刻意的：線上版列印時的表頭（表單名稱）與'
                . '頁尾（AS 文件編號＋版次、頁碼）由系統依這份文件的綁定自動產生，不需要也不應該寫在內容裡。'];
    if (empty($s['pagebreaks'])) {
        $todo[] = ['type' => 'pagebreak', 'n' => 0, 'level' => 'info',
            'note' => '這份 Word 裡沒有「明確的分頁符號」（它是靠內容長度自然換頁的），'
                    . '所以匯入進來會先是一整頁。請按工具列的「自動分頁」，'
                    . '系統會量測之後把超出的內容往後推，變成一頁一頁。'];
    }
    $todo[] = ['type' => 'check', 'n' => 0, 'level' => 'info',
        'note' => '匯入的內容一律先當草稿，請逐段核對（尤其表格欄寬與段落順序）並補完流程圖，'
                . '確認無誤後再按「設為此版次的正本」，之後檢視與列印才會走線上版。'];

    return ['src' => $showName, 'secs' => $secs, 'at' => date('Y-m-d H:i:s'),
            'done' => $done, 'todo' => $todo, 'stat' => $s];
}

/** 遞迴刪暫存資料夾（attachment_lib 有同名的 eg_att_rrmdir，這裡不依賴它的載入順序） */
function adi_rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? adi_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

} // EG_AS_DOC_IMPORT_LIB
