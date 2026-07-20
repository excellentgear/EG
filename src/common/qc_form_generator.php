<?php
/**
 * QC 檢驗紀錄表 .xlsm 產生器（2026-07-20 新增，Phase4）
 *
 * 取代「建立更新_BOM」VBA 巨集裡「複製 2-QA-01-06-檢驗記錄表 V04.xlsm 範本、依製程動態新增工作表、
 * 另存為 {BOM號}.xlsm 到 NAS」這段。改用 ZIP/XML 層操作：複製範本檔（巨集 vbaProject.bin、格式、
 * 表單控制項原封不動），只在 XML 層改儲存格、複製「空白表單」分頁 N 份（每製程一頁）。
 *
 * 不使用 PhpSpreadsheet 寫入（它不支援保留 .xlsm 巨集）。已用 LibreOffice headless 開檔驗證無損、
 * 分頁順序/名稱/儲存格值與舊 VBA 產物一致。
 *
 * 對照規則（比照 VBA）：
 *   進料檢：F3=製令(bom)、F4=料號(d_id)、N4=數量(sqty)、T11=客戶名、T13=交期
 *   包裝：B3/N11 是範本內參照進料檢的公式（不用設，開檔重算）；I4=包裝備註二（有才設）
 *   每製程分頁（複製自「空白表單」）：Y2=製程名(分頁名鏡像)、N3=製程名、D6=備註一/二
 *   排除不建分頁的製程：名稱為 材料 / 包裝 / 客供料 / 空白（同 VBA）
 */

if (!function_exists('qcFormExcludedProcess')) {
    /** 是否為不建製程分頁的製程名（同 VBA：材料/包裝/客供料/空白跳過） */
    function qcFormExcludedProcess($name) {
        $name = trim((string)$name);
        return $name === '' || in_array($name, ['材料', '包裝', '客供料'], true);
    }
}

if (!function_exists('qcFormGenerate')) {
    /**
     * 產生一份 QC 檢驗紀錄表 .xlsm。
     *
     * @param string $templatePath 範本 .xlsm 絕對路徑
     * @param string $outputPath   輸出 .xlsm 絕對路徑
     * @param array  $data [
     *     'bom'=>製令單號, 'd_id'=>料號, 'sqty'=>數量, 'client'=>客戶名, 'delivery'=>交期(可空),
     *     'pack_ps2'=>包裝備註二(可空),
     *     'processes'=>[ ['name'=>製程中文, 'ps1'=>備註一, 'ps2'=>備註二], ... ]  // 已排除材料/包裝/客供料
     * ]
     * @return int 產生的製程分頁數
     */
    function qcFormGenerate($templatePath, $outputPath, array $data) {
        if (!is_file($templatePath)) throw new Exception("QC範本檔不存在：{$templatePath}");
        if (!copy($templatePath, $outputPath)) throw new Exception("複製QC範本失敗：{$outputPath}");

        $z = new ZipArchive;
        if ($z->open($outputPath) !== true) throw new Exception("開啟輸出檔失敗：{$outputPath}");

        try {
            // 讀取範本關鍵 part（空白表單=sheet2.xml，進料檢=sheet1.xml，已用範本結構確認）
            $contentTypes = $z->getFromName('[Content_Types].xml');
            $workbook     = $z->getFromName('xl/workbook.xml');
            $wbRels       = $z->getFromName('xl/_rels/workbook.xml.rels');
            $tmplSheet    = $z->getFromName('xl/worksheets/sheet2.xml');
            $tmplDrawing  = $z->getFromName('xl/drawings/drawing2.xml');
            $tmplVml      = $z->getFromName('xl/drawings/vmlDrawing2.vml');
            $tmplPrinter  = $z->getFromName('xl/printerSettings/printerSettings2.bin');
            $tmplCtrl3    = $z->getFromName('xl/ctrlProps/ctrlProp3.xml');
            $tmplCtrl4    = $z->getFromName('xl/ctrlProps/ctrlProp4.xml');
            $sheet1       = $z->getFromName('xl/worksheets/sheet1.xml');
            $sheet5       = $z->getFromName('xl/worksheets/sheet5.xml'); // 包裝
            if ($tmplSheet === false || $sheet1 === false) {
                throw new Exception("QC範本結構不符（找不到空白表單/進料檢分頁），請確認範本版本");
            }

            // 現有最大編號
            $maxSheet = 0; $maxDraw = 0; $maxVml = 0; $maxPrn = 0; $maxCtrl = 0; $maxRid = 0;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = $z->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $n, $m)) $maxSheet = max($maxSheet, (int)$m[1]);
                if (preg_match('#^xl/drawings/drawing(\d+)\.xml$#', $n, $m)) $maxDraw = max($maxDraw, (int)$m[1]);
                if (preg_match('#^xl/drawings/vmlDrawing(\d+)\.vml$#', $n, $m)) $maxVml = max($maxVml, (int)$m[1]);
                if (preg_match('#^xl/printerSettings/printerSettings(\d+)\.bin$#', $n, $m)) $maxPrn = max($maxPrn, (int)$m[1]);
                if (preg_match('#^xl/ctrlProps/ctrlProp(\d+)\.xml$#', $n, $m)) $maxCtrl = max($maxCtrl, (int)$m[1]);
            }
            if (preg_match_all('/Id="rId(\d+)"/', $wbRels, $mm)) foreach ($mm[1] as $rid) $maxRid = max($maxRid, (int)$rid);

            // 進料檢：F3/F4/N4/T11/T13
            $sheet1 = qcFormSetCell($sheet1, 'F3', $data['bom'], false);
            $sheet1 = qcFormSetCell($sheet1, 'F4', $data['d_id'], false);
            $sheet1 = qcFormSetCell($sheet1, 'N4', $data['sqty'], true);
            $sheet1 = qcFormSetCell($sheet1, 'T11', $data['client'] ?? '', false);
            if (!empty($data['delivery'])) $sheet1 = qcFormSetCell($sheet1, 'T13', $data['delivery'], false, true);
            $z->addFromString('xl/worksheets/sheet1.xml', $sheet1);

            // 包裝 I4（包裝備註二，有才設；B3/N11 是公式不動）
            if ($sheet5 !== false && !empty($data['pack_ps2'])) {
                $sheet5 = qcFormSetCell($sheet5, 'I4', $data['pack_ps2'], false, true);
                $z->addFromString('xl/worksheets/sheet5.xml', $sheet5);
            }

            // 逐製程複製「空白表單」分頁
            $newSheetEntries = [];
            $ctOverrides = '';
            foreach ($data['processes'] as $proc) {
                $sN = ++$maxSheet; $dN = ++$maxDraw; $vN = ++$maxVml; $pN = ++$maxPrn;
                $c1 = ++$maxCtrl; $c2 = ++$maxCtrl; $rid = ++$maxRid;
                $nm = $proc['name'];
                $note = "備註一：" . ($proc['ps1'] ?? '') . "\n備註二：" . ($proc['ps2'] ?? '') . "\n";

                $sx = $tmplSheet;
                $sx = qcFormSetCell($sx, 'Y2', $nm, false);
                $sx = qcFormSetCell($sx, 'N3', $nm, false);
                $sx = qcFormSetCell($sx, 'D6', $note, false);
                $z->addFromString("xl/worksheets/sheet{$sN}.xml", $sx);

                // 分頁 rels（內部 rId1..rId5 結構不變，只換目標檔名 — rels 為 part-scoped）
                $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/printerSettings" Target="../printerSettings/printerSettings' . $pN . '.bin"/>'
                    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $dN . '.xml"/>'
                    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/vmlDrawing" Target="../drawings/vmlDrawing' . $vN . '.vml"/>'
                    . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/ctrlProp" Target="../ctrlProps/ctrlProp' . $c1 . '.xml"/>'
                    . '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/ctrlProp" Target="../ctrlProps/ctrlProp' . $c2 . '.xml"/>'
                    . '</Relationships>';
                $z->addFromString("xl/worksheets/_rels/sheet{$sN}.xml.rels", $rels);

                // 相依 part 複製（drawing/vml 無自己的 rels，plain copy）
                $z->addFromString("xl/drawings/drawing{$dN}.xml", $tmplDrawing);
                $z->addFromString("xl/drawings/vmlDrawing{$vN}.vml", $tmplVml);
                $z->addFromString("xl/printerSettings/printerSettings{$pN}.bin", $tmplPrinter);
                $z->addFromString("xl/ctrlProps/ctrlProp{$c1}.xml", $tmplCtrl3);
                $z->addFromString("xl/ctrlProps/ctrlProp{$c2}.xml", $tmplCtrl4);

                $ctOverrides .= '<Override PartName="/xl/worksheets/sheet' . $sN . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                    . '<Override PartName="/xl/drawings/drawing' . $dN . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
                    . '<Override PartName="/xl/ctrlProps/ctrlProp' . $c1 . '.xml" ContentType="application/vnd.ms-excel.controlproperties+xml"/>'
                    . '<Override PartName="/xl/ctrlProps/ctrlProp' . $c2 . '.xml" ContentType="application/vnd.ms-excel.controlproperties+xml"/>';

                $newSheetEntries[] = ['name' => $nm, 'rid' => 'rId' . $rid, 'sheetFile' => 'worksheets/sheet' . $sN . '.xml'];
            }

            // [Content_Types].xml：加 override；移除 calcChain 強制重算（包裝公式參照進料檢）
            $contentTypes = str_replace('</Types>', $ctOverrides . '</Types>', $contentTypes);
            if ($z->locateName('xl/calcChain.xml') !== false) {
                $z->deleteName('xl/calcChain.xml');
                $contentTypes = preg_replace('#<Override PartName="/xl/calcChain\.xml"[^>]*/>#', '', $contentTypes);
            }
            $z->addFromString('[Content_Types].xml', $contentTypes);

            // workbook.xml.rels：加新分頁關聯
            $relIns = '';
            foreach ($newSheetEntries as $e) {
                $relIns .= '<Relationship Id="' . $e['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $e['sheetFile'] . '"/>';
            }
            $wbRels = str_replace('</Relationships>', $relIns . '</Relationships>', $wbRels);
            $z->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

            // workbook.xml：在「空白表單」分頁前插入新分頁（同 VBA 位置）；設 fullCalcOnLoad
            $maxSheetId = 0;
            if (preg_match_all('/sheetId="(\d+)"/', $workbook, $sm)) foreach ($sm[1] as $sid) $maxSheetId = max($maxSheetId, (int)$sid);
            $sheetIns = '';
            foreach ($newSheetEntries as $e) {
                $maxSheetId++;
                $sheetIns .= '<sheet name="' . htmlspecialchars($e['name'], ENT_QUOTES) . '" sheetId="' . $maxSheetId . '" r:id="' . $e['rid'] . '"/>';
            }
            $workbook = preg_replace('/(<sheet [^>]*name="空白表單[^"]*"[^>]*\/>)/u', $sheetIns . '$1', $workbook, 1);
            if (strpos($workbook, 'fullCalcOnLoad') === false) {
                if (strpos($workbook, '<calcPr') !== false) {
                    $workbook = preg_replace('/<calcPr /', '<calcPr fullCalcOnLoad="1" ', $workbook, 1);
                } else {
                    $workbook = str_replace('</workbook>', '<calcPr fullCalcOnLoad="1"/></workbook>', $workbook);
                }
            }
            $z->addFromString('xl/workbook.xml', $workbook);

            $z->close();
            return count($newSheetEntries);
        } catch (Exception $e) {
            $z->close();
            if (is_file($outputPath)) @unlink($outputPath); // 失敗不留半成品
            throw $e;
        }
    }

    /** 在 worksheet XML 設定某儲存格值，保留原 style；數字用 <v>，字串用 inlineStr */
    function qcFormSetCell($xml, $ref, $value, $isNumber, $optional = false) {
        $value = (string)$value;
        if (preg_match('#<c r="' . $ref . '"([^>]*?)/>#', $xml, $m)) {
            $attrs = preg_replace('/\s+t="[^"]*"/', '', $m[1]);
            return str_replace($m[0], qcFormCellXml($ref, $attrs, $value, $isNumber), $xml);
        }
        if (preg_match('#<c r="' . $ref . '"([^>]*?)>.*?</c>#s', $xml, $m)) {
            $attrs = preg_replace('/\s+t="[^"]*"/', '', $m[1]);
            return str_replace($m[0], qcFormCellXml($ref, $attrs, $value, $isNumber), $xml);
        }
        if ($optional) return $xml; // 選填格不存在就略過
        throw new Exception("QC範本找不到儲存格 {$ref}");
    }

    function qcFormCellXml($ref, $attrs, $value, $isNumber) {
        if ($isNumber && is_numeric($value)) {
            return '<c r="' . $ref . '"' . $attrs . '><v>' . $value . '</v></c>';
        }
        $esc = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        // 換行以 OOXML 轉義 _x000D_ 儲存（與舊 VBA 產物一致，Excel 會解讀為換行）
        $esc = str_replace(["\r\n", "\n", "\r"], '_x000D_', $esc);
        return '<c r="' . $ref . '"' . $attrs . ' t="inlineStr"><is><t xml:space="preserve">' . $esc . '</t></is></c>';
    }
}
