<?php
// =============================================================================
// src/common/form_signer_pdf_lib.php — 表單簽核設計器：合成 PDF 匯出
//
// 目的：案件完成後把「原始文件 + 所有圖章/回覆內容」合成一份定版 PDF 存進 NAS，
//       列表可重複開啟列印與下載，不必每次靠瀏覽器即時疊圖層列印。
//
// 【畫質原則（2026-08-19 使用者明確要求，先前踩過坑）】
//   上傳的 PDF **絕不重新光柵化**。整份原始 PDF 用 FPDI `importPage()` 匯入，
//   來源的內容串流（含掃描影像）是位元組原封不動搬進新檔——實測 12 份真實文件，
//   掃描檔 4 個 stream 100% md5 相同，輸出檔大小僅 +0.3%（差額是新加的圖章與字型）。
//   會讓畫質變糊的兩件事一律不做：①pdf.js 重畫成點陣圖（那是 2026-08-14 被使用者
//   退回「案件只准傳圖片」的真正原因）②經 LibreOffice 重存正規化（會重新編碼影像）。
//   實測 FPDI 2.6.8 免費版連 PDF 1.5/1.7 的壓縮交叉參照表都讀得動，不需要正規化；
//   唯一讀不了的是**加密 PDF**，一律在上傳當下就擋掉並說明原因，不偷偷降級成模糊版本。
//   圖章走前端 6 倍解析度（約 450dpi 等效）去背 PNG；回覆文字交給 TCPDF 用內嵌
//   標楷體畫成向量文字，不做成圖片。
//
// 【版面】比照既有瀏覽器列印版（doPrint()）與 ai-rules/16：A4（依文件寬高比自動直/橫式）、
//   邊界 10mm/8mm、文件等比置中縮放至可印範圍、頁碼左下（多頁才印）、AS 文件編號右下。
//   PDF 內縮放是無損的（向量與內嵌影像只是換算顯示尺寸），不會因為縮到 A4 而變糊。
// =============================================================================

if (defined('FSD_PDF_LIB_LOADED')) return;
define('FSD_PDF_LIB_LOADED', 1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/attachment_lib.php'; // eg_att_pdf_font()：內嵌標楷體，全站唯一實作不另寫

define('FSD_PDF_MARGIN_TB', 10.0); // mm，比照 doPrint()
define('FSD_PDF_MARGIN_LR', 8.0);

/* -------------------------------------------------------------------------
 * 1. 來源 PDF 探測（上傳當下用）
 * ---------------------------------------------------------------------- */
/**
 * 探測上傳的 PDF：能不能被 FPDI 解析、幾頁、每頁尺寸(pt)。
 * 回傳 ['ok'=>bool, 'msg'=>?string, 'pages'=>[['page_no'=>1,'width_pt'=>..,'height_pt'=>..], ...]]
 * 解析失敗（加密/損毀）一律回 ok=false 並附上人看得懂的原因，呼叫端直接擋下，
 * 不可自行降級成轉圖（使用者 2026-08-19 拍板：寧可請使用者換一份檔案，也不要偷偷變糊）。
 */
function fsd_pdf_probe(string $path): array {
    if (!is_file($path)) return ['ok'=>false, 'msg'=>'找不到上傳的 PDF 檔'];
    try {
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $n = $pdf->setSourceFile($path);
        $pages = [];
        for ($i = 1; $i <= $n; $i++) {
            $tpl  = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl); // 單位 mm（文件單位）
            $pages[] = ['page_no'=>$i,
                        'width_pt'  => $size['width']  / 25.4 * 72,
                        'height_pt' => $size['height'] / 25.4 * 72];
        }
        unset($pdf);
        if (!$pages) return ['ok'=>false, 'msg'=>'這份 PDF 讀不到任何頁面'];
        return ['ok'=>true, 'pages'=>$pages];
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (stripos($m, 'encrypted') !== false) {
            return ['ok'=>false, 'msg'=>'這份 PDF 有加密保護，系統無法在不破壞畫質的前提下處理。'
                . '請用檢視器另存/列印成一份沒有密碼保護的 PDF 再上傳，或改上傳圖片檔。'];
        }
        error_log('[fsd_pdf] probe failed: ' . $m);
        return ['ok'=>false, 'msg'=>'這份 PDF 無法解析（' . mb_substr($m, 0, 80) . '）。請改上傳圖片檔，或用檢視器另存一份新的 PDF。'];
    }
}

/* -------------------------------------------------------------------------
 * 2. 合成
 * ---------------------------------------------------------------------- */
/**
 * 合成定版 PDF。
 *
 * @param array  $case     fsd_case 一列（要有 file_type / file_name / title）
 * @param array  $pages    fsd_case_page（width_pt/height_pt 已是「轉正＋裁切後」的實際顯示尺寸，
 *                         圖章座標的 0~1 分數就是相對這個尺寸，見 form_signer.php fpRotatePage()/confirmCrop()）
 * @param string $srcDir   案件檔案所在目錄（即時組出，不從 DB 讀絕對路徑，鐵律5）
 * @param array  $overlay  ['stamps'=>[['page_no','x','y','w','h','png'=>dataURL], ...],
 *                          'texts' =>[['page_no','x','y','w','h','text'], ...]]
 * @param string $asDocNo  綁定的 AS 文件編號（右下角，可空）
 * @param string $destPath 輸出檔絕對路徑
 * @return array ['ok'=>bool,'msg'=>?string,'mode'=>'vector'|'image']
 */
function fsd_pdf_compose(array $case, array $pages, string $srcDir, array $overlay, string $asDocNo, string $destPath): array {
    if (!$pages) return ['ok'=>false, 'msg'=>'此案件沒有任何頁面資料'];
    $isPdf = (($case['file_type'] ?? 'image') === 'pdf');
    $tmpFiles = [];
    try {
        // 文件直/橫式：比照 doPrint()／isLandscapeDoc()，以第一頁寬高比判斷，整份共用同一種紙張方向
        $lands = (float)$pages[0]['width_pt'] >= (float)$pages[0]['height_pt'];
        $pageW = $lands ? 297.0 : 210.0;
        $pageH = $lands ? 210.0 : 297.0;
        $availW = $pageW - FSD_PDF_MARGIN_LR * 2;
        $availH = $pageH - FSD_PDF_MARGIN_TB * 2;

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'mm', [$pageW, $pageH]);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCreator('EGsystem');
        $pdf->SetTitle((string)($case['title'] ?? ''));
        $pdf->SetCompression(true);

        $srcPdfFile = null;
        if ($isPdf) {
            $srcPdfFile = rtrim($srcDir, '\\/') . DIRECTORY_SEPARATOR . (string)$case['file_name'];
            if (!is_file($srcPdfFile)) return ['ok'=>false, 'msg'=>'找不到案件的原始 PDF 檔'];
            $pdf->setSourceFile($srcPdfFile);
        }

        $stamps = $overlay['stamps'] ?? [];
        $texts  = $overlay['texts']  ?? [];
        $total  = count($pages);

        foreach ($pages as $p) {
            $pageNo = (int)$p['page_no'];
            $docWmm = (float)$p['width_pt']  / 72 * 25.4;
            $docHmm = (float)$p['height_pt'] / 72 * 25.4;
            if ($docWmm <= 0 || $docHmm <= 0) { $docWmm = $availW; $docHmm = $availH; }
            // 等比縮放置中（不放大，比照 doPrint() 的 min(...,1)）
            $scale = min($availW / $docWmm, $availH / $docHmm, 1.0);
            $outW  = $docWmm * $scale;
            $outH  = $docHmm * $scale;
            $offX  = FSD_PDF_MARGIN_LR + ($availW - $outW) / 2;
            $offY  = FSD_PDF_MARGIN_TB + ($availH - $outH) / 2;

            $pdf->AddPage($lands ? 'L' : 'P', [$pageW, $pageH]);

            $rot = ((int)($p['rotation'] ?? 0) % 360 + 360) % 360;
            $cx  = (float)($p['crop_x'] ?? 0);
            $cy  = (float)($p['crop_y'] ?? 0);
            $cw  = (float)($p['crop_w'] ?? 1); if ($cw <= 0) $cw = 1;
            $ch  = (float)($p['crop_h'] ?? 1); if ($ch <= 0) $ch = 1;

            if ($isPdf) {
                fsd_pdf_place_template($pdf, $pageNo, $rot, $cx, $cy, $cw, $ch, $offX, $offY, $outW, $outH);
            } else {
                $imgFile = fsd_pdf_page_image($case, $p, $srcDir, $rot, $cx, $cy, $cw, $ch, $tmpFiles);
                if ($imgFile === null) return ['ok'=>false, 'msg'=>'第 ' . $pageNo . ' 頁的圖片檔讀取失敗'];
                // resize=false：TCPDF 直接內嵌原始影像資料（JPEG 走 DCTDecode 原樣帶過），不重新編碼
                $pdf->Image($imgFile, $offX, $offY, $outW, $outH, '', '', '', false, 300, '', false, false, 0);
            }

            // ---- 疊上圖章（PNG）與回覆內容（向量文字）----
            foreach ($stamps as $s) {
                if ((int)($s['page_no'] ?? 0) !== $pageNo) continue;
                $png = fsd_pdf_dataurl_to_tmp((string)($s['png'] ?? ''), $tmpFiles);
                if ($png === null) continue;
                $pdf->Image($png, $offX + (float)$s['x'] * $outW, $offY + (float)$s['y'] * $outH,
                            (float)$s['w'] * $outW, (float)$s['h'] * $outH,
                            'PNG', '', '', false, 300, '', false, false, 0);
            }
            foreach ($texts as $t) {
                if ((int)($t['page_no'] ?? 0) !== $pageNo) continue;
                $txt = trim((string)($t['text'] ?? ''));
                if ($txt === '') continue;
                $bw = (float)$t['w'] * $outW;
                $bh = (float)$t['h'] * $outH;
                $bx = $offX + (float)$t['x'] * $outW;
                $by = $offY + (float)$t['y'] * $outH;
                // 回覆框外觀比照畫面/列印版的 .fsd-box.reply（半透明白底＋淺棕虛線框），讓人看得出這是系統填的回覆區
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetDrawColor(216, 190, 147);
                $pdf->SetLineStyle(['width'=>0.2, 'dash'=>'2,1.5', 'color'=>[216, 190, 147]]);
                $pdf->Rect($bx, $by, $bw, $bh, 'DF');
                $pdf->SetLineStyle(['dash'=>0]);
                $pdf->SetFont(eg_att_pdf_font(), '', fsd_pdf_fit_font_size($pdf, $txt, $bw - 1.6, $bh - 1.0));
                $pdf->SetTextColor(91, 58, 30);
                $bx += 0.8; $by += 0.5; $bw -= 1.6; $bh -= 1.0; // 內縮＝畫面上的 padding:2px 4px
                $pdf->SetXY($bx, $by);
                // 第2參數是「每一行的高度」不是整框高度：填整框高度會讓多行文字每行都佔滿整框而爆出去，
                // 一律傳 0 讓 TCPDF 依字級自動算行高，框高改用第14參數 $maxh 限制
                $pdf->MultiCell($bw, 0, $txt, 0, 'L', false, 1, '', '', true, 0, false, true, $bh, 'T');
            }

            // ---- 頁尾：頁碼左下（多頁才印）＋ AS 文件編號右下（ai-rules/16 第二、三節）----
            $pdf->SetFont(eg_att_pdf_font(), '', 9);
            $pdf->SetTextColor(51, 51, 51);
            if ($total > 1) {
                $pdf->SetXY(FSD_PDF_MARGIN_LR, $pageH - FSD_PDF_MARGIN_TB + 1.5);
                $pdf->Cell($availW / 2, 5, '第 ' . $pageNo . ' 頁／共 ' . $total . ' 頁', 0, 0, 'L');
            }
            if ($asDocNo !== '') {
                $pdf->SetXY($pageW / 2, $pageH - FSD_PDF_MARGIN_TB + 1.5);
                $pdf->Cell($pageW / 2 - FSD_PDF_MARGIN_LR, 5, $asDocNo, 0, 0, 'R');
            }
        }

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return ['ok'=>false, 'msg'=>'無法建立輸出目錄'];
        // TCPDF Output('F') 對含中文路徑有雷，一律取回字串自己寫檔（比照 attachment_lib 既有做法）
        $bytes = $pdf->Output('', 'S');
        if (@file_put_contents($destPath, $bytes) === false) return ['ok'=>false, 'msg'=>'PDF 寫檔失敗，請確認 NAS 資料夾權限'];
        return ['ok'=>true, 'mode'=>$isPdf ? 'vector' : 'image'];
    } catch (Throwable $e) {
        error_log('[fsd_pdf] compose failed (case ' . ($case['id'] ?? '?') . '): ' . $e->getMessage());
        $m = $e->getMessage();
        if (stripos($m, 'encrypted') !== false) {
            return ['ok'=>false, 'msg'=>'這份 PDF 有加密保護無法合成，請改上傳未加密的 PDF 或圖片檔'];
        }
        return ['ok'=>false, 'msg'=>'PDF 合成失敗：' . mb_substr($m, 0, 100)];
    } finally {
        foreach ($tmpFiles as $f) { if (is_file($f)) @unlink($f); }
    }
}

/**
 * 把來源 PDF 的某一頁（含轉正 rotation 與裁切 crop）鋪到輸出頁的指定矩形上。
 *
 * 座標推導：頁面顯示座標系（disp）＝來源頁順時針轉 $rot 後的樣子；裁切框 crop_* 是
 * disp 上的 0~1 分數；輸出矩形則是「裁切後區域」等比縮放的結果。FPDI 的 useTemplate()
 * 只能指定位置與大小，所以旋轉靠 TCPDF Rotate()（正角度＝逆時針），順時針 r 度＝Rotate(-r)；
 * 而裁切位移發生在旋轉「之後」的 disp 空間，必須用反旋轉換算回旋轉「之前」的擺放座標，
 * 才能加進 useTemplate() 的 x/y。四個方向的推導結果直接寫在下面 switch 內。
 */
function fsd_pdf_place_template(\setasign\Fpdi\Tcpdf\Fpdi $pdf, int $pageNo, int $rot,
                                float $cx, float $cy, float $cw, float $ch,
                                float $offX, float $offY, float $outW, float $outH): void {
    $tpl  = $pdf->importPage($pageNo);
    $size = $pdf->getTemplateSize($tpl);
    $tw   = (float)$size['width'];
    $th   = (float)$size['height'];
    $swap = ($rot === 90 || $rot === 270);

    // 整份頁面（未裁切）縮放後在輸出空間的大小：裁切區域要剛好填滿 outW×outH
    $dispW = $swap ? $th : $tw;   // 轉正後的顯示寬（未縮放）
    $dispH = $swap ? $tw : $th;
    $DW = $outW / $cw;            // 整頁縮放後的寬（disp 軸）
    $DH = $outH / $ch;
    $sx = $dispW > 0 ? $DW / $dispW : 1.0;
    $sy = $dispH > 0 ? $DH / $dispH : 1.0;
    // 樣板在「旋轉前」自己的座標軸上要畫多大。轉 90/270 時樣板的 x 軸會落到 disp 的 y 軸上
    // （反之亦然），所以套用的縮放要跟著對調：樣板寬吃 sy、樣板高吃 sx。
    $TW = $tw * ($swap ? $sy : $sx);
    $TH = $th * ($swap ? $sx : $sy);

    $shiftX = -$cx * $DW; // disp 空間的裁切位移
    $shiftY = -$cy * $DH;

    switch ($rot) {
        case 90:  $x0 =  0.0 + $shiftY;  $y0 = -$TH - $shiftX; break; // 反旋轉：(a,b)→(b,-a)
        case 180: $x0 = -$TW - $shiftX;  $y0 = -$TH - $shiftY; break; //          (a,b)→(-a,-b)
        case 270: $x0 = -$TW - $shiftY;  $y0 =  0.0 + $shiftX; break; //          (a,b)→(-b,a)
        default:  $x0 =  0.0 + $shiftX;  $y0 =  0.0 + $shiftY; break;
    }

    $pdf->StartTransform();
    // 先把原點移到輸出矩形左上角，再套旋轉；超出頁面的部分由 PDF 頁面框自然裁掉
    $pdf->SetXY(0, 0);
    $pdf->Translate($offX, $offY);
    if ($rot) $pdf->Rotate(-$rot, 0, 0);
    $pdf->useTemplate($tpl, $x0, $y0, $TW, $TH, false);
    $pdf->StopTransform();
}

/**
 * 圖片案件的單頁來源檔：沒有旋轉也沒有裁切時直接回原檔（TCPDF 內嵌原始影像資料，無損）；
 * 有旋轉/裁切才用 GD 重繪一張暫存 PNG（PNG 無損，不會像重存 JPEG 那樣掉畫質）。
 */
function fsd_pdf_page_image(array $case, array $page, string $srcDir, int $rot,
                            float $cx, float $cy, float $cw, float $ch, array &$tmpFiles): ?string {
    $name = ($page['file_name'] ?? '') !== '' ? $page['file_name'] : ($case['file_name'] ?? '');
    if ($name === '' || $name === null) return null;
    $file = rtrim($srcDir, '\\/') . DIRECTORY_SEPARATOR . $name;
    if (!is_file($file)) return null;
    $hasCrop = ($cx > 0 || $cy > 0 || $cw < 1 || $ch < 1);
    if (!$rot && !$hasCrop) return $file;

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $im  = ($ext === 'png') ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
    if (!$im) return $file; // GD 讀不動就退回原檔（寧可方向沒轉，也不要整份匯不出來）
    if ($rot) {
        // GD imagerotate 是逆時針，畫面上的順時針 rotation 要取負值
        $rt = @imagerotate($im, -$rot, 0);
        if ($rt) { imagedestroy($im); $im = $rt; }
    }
    if ($hasCrop) {
        $w = imagesx($im); $h = imagesy($im);
        $rect = ['x'=>(int)round($w * $cx), 'y'=>(int)round($h * $cy),
                 'width'=>max(1, (int)round($w * $cw)), 'height'=>max(1, (int)round($h * $ch))];
        $cr = @imagecrop($im, $rect);
        if ($cr) { imagedestroy($im); $im = $cr; }
    }
    $tmp = fsd_pdf_tmp_path('png');
    imagesavealpha($im, true);
    @imagepng($im, $tmp, 1); // 壓縮等級 1：PNG 一律無損，等級只影響檔案大小與時間
    imagedestroy($im);
    if (!is_file($tmp)) return $file;
    $tmpFiles[] = $tmp;
    return $tmp;
}

/** data:image/png;base64,... → 暫存檔路徑（TCPDF 吃檔案路徑最穩）。非 PNG data URL 一律回 null。 */
function fsd_pdf_dataurl_to_tmp(string $dataUrl, array &$tmpFiles): ?string {
    if (!preg_match('#^data:image/png;base64,#i', $dataUrl)) return null;
    $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($raw === false || strlen($raw) < 8) return null;
    $tmp = fsd_pdf_tmp_path('png');
    if (@file_put_contents($tmp, $raw) === false) return null;
    $tmpFiles[] = $tmp;
    return $tmp;
}

function fsd_pdf_tmp_path(string $ext): string {
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'fsd_' . bin2hex(random_bytes(8)) . '.' . $ext;
}

/**
 * 回覆文字自動縮字級：起點 8.25pt＝畫面/列印版 .fsd-box.reply 的 font-size:11px 換算值（11/96*72），
 * 塞不下才逐級縮小。畫面上是 overflow:hidden 直接切掉，這裡改成先縮字，只有縮到最小仍塞不下才會被
 * MultiCell 的 $maxh 切掉——所以任何情況下都不會比列印版看到的更少。
 */
function fsd_pdf_fit_font_size(\TCPDF $pdf, string $txt, float $w, float $h): float {
    $len = max(1, mb_strlen($txt, 'UTF-8'));
    for ($size = 8.25; $size > 4.0; $size -= 0.25) {
        $charW = $size * 0.35;                       // 中文字寬約等於字級，mm 換算後的保守估計
        $perLine = max(1, floor($w / $charW));
        $lines = ceil($len / $perLine);
        if ($lines * ($size * 0.42) <= $h) return $size;
    }
    return 4.5;
}
