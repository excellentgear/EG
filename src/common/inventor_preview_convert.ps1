# inventor_preview_convert.ps1 — IPT/X_T → STEP 轉檔（唯一實作，供 inventor_preview_lib.php 呼叫）
#
# 用法：powershell -NoProfile -ExecutionPolicy Bypass -File inventor_preview_convert.ps1 <來源路徑> <STEP輸出路徑> <Interop.dll路徑>
#
# 原理：Autodesk Inventor 沒有像 AutoCAD accoreconsole.exe 那種無人值守命令列引擎，
# 自動化一律走 COM（Inventor.Application）。IPT（原生零件檔）與 X_T（Parasolid 文字
# 交換檔）兩種來源格式用的是完全相同的程式路徑——Inventor 的 Documents.Open() 會
# 自動依內容判斷格式，兩者都能直接開啟，不需要為 X_T 另外走「匯入到新零件」那套
# 更複雜的 ImportedComponents API（已實測驗證過）。
#
# 關鍵陷阱（已實測排除，供日後維護參考）：
#  1. TranslationContext.Type 預設值是 13057（kUnspecifiedIOMechanism），直覺猜的 1
#     會導致 SaveCopyAs 丟出「災難性的失敗 (0x8000FFFF)」這種毫無資訊的泛用 COM 錯誤
#     ——不管用 PowerShell 或原生 VBScript 都一樣，所以不是語言互通問題，是數值錯誤。
#     正確值是 kFileBrowseIOMechanism=13059，本腳本用 Add-Type 載入官方 .NET Interop
#     組件取得強型別列舉常數，不再用猜的數字。
#  2. 一定要用陣列形式 + bypass_shell 呼叫本腳本（呼叫端 PHP 的責任），否則逾時保護
#     殺不到正確的行程（這點跟 DWG 轉檔踩過的坑完全一樣）。
#
# 安全：$invApp.Quit() 一律在 finally 區塊執行，不管成功或失敗都會嘗試關閉；
# 呼叫端 PHP 另外在發起 COM 自動化前後各做一次 Inventor.exe 行程快照比對，只處理
# 這次呼叫新長出來的行程，絕對不會動到使用者自己已經開著的 Inventor 視窗。

param(
    [Parameter(Mandatory=$true)][string]$InPath,
    [Parameter(Mandatory=$true)][string]$OutPath,
    [Parameter(Mandatory=$true)][string]$InteropDll
)

$ErrorActionPreference = 'Stop'

try {
    Add-Type -Path $InteropDll
} catch {
    Write-Output ("ERR_LOAD_INTEROP: " + $_.Exception.Message)
    exit 1
}

if (Test-Path $OutPath) { Remove-Item $OutPath -Force -ErrorAction SilentlyContinue }

$invApp = $null
try {
    $invAppType = [System.Type]::GetTypeFromProgID("Inventor.Application")
    $invApp = [System.Activator]::CreateInstance($invAppType)
    $invApp.SilentOperation = $true
    $invApp.Visible = $false

    $doc = $invApp.Documents.Open($InPath, $false)

    # ── 組合檔（.iam）：回報它參照了哪些零件、哪幾個沒解析到 ──────────────────
    # 組合檔本身只是一張「參照清單」，零件幾何在各自的 .ipt 裡。被參照的檔案找不到時
    # Inventor **不會報錯**，會安靜地匯出一個只有組合名稱、零幾何的空 STEP（已實測：
    # 2.7KB、0 個面），使用者看到的就是一片空白。所以這裡把參照狀況一併回報給呼叫端，
    # 讓畫面能明白告訴使用者「這個組合還缺哪幾個零件檔」。
    # 輸出刻意用 base64 包住 UTF-8 JSON——零件檔名常含中文，PowerShell 的輸出編碼與
    # PHP 讀檔編碼不見得一致，直接輸出中文會變亂碼。
    try {
        $refs = @()
        $missing = @()
        # 判「這個元件到底有沒有被解析到」不可以用 Test-Path 檢查 FullFileName——實測
        # 未解析的元件，FullFileName 仍指著當初建模時的路徑，那個路徑在這台機器上常常
        # 真的存在（工程人員自己的工作資料夾），於是每個元件都被判成「有找到」，缺件
        # 完全偵測不出來（實測 5 個元件只供了 1 個，卻回報 missing=0）。
        # 正解是用 AllReferencedDocuments——那是 Inventor **真正載入成功**的元件清單。
        $resolved = @{}
        try {
            foreach ($rd in $doc.AllReferencedDocuments) {
                $resolved[([System.IO.Path]::GetFileName($rd.FullFileName)).ToLower()] = $true
            }
        } catch { }
        foreach ($fd in $doc.File.ReferencedFileDescriptors) {
            $nm = [System.IO.Path]::GetFileName($fd.FullFileName)
            $refs += $nm
            $miss = $false
            try { $miss = [bool]$fd.ReferenceMissing } catch { $miss = $false }
            if (-not $miss -and -not $resolved.ContainsKey($nm.ToLower())) { $miss = $true }
            if ($miss) { $missing += $nm }
        }
        $occ = 0
        try { $occ = $doc.ComponentDefinition.Occurrences.Count } catch { }
        $info = [ordered]@{
            doc_type   = [string]$doc.DocumentType
            occurrence = $occ
            refs       = @($refs)
            missing    = @($missing)
        }
        $json = ($info | ConvertTo-Json -Compress -Depth 4)
        Write-Output ("REFS64: " + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($json)))
    } catch { }

    $stepTranslator = $invApp.ApplicationAddIns.ItemById("{90AF7F40-0C01-11D5-8E83-0010B541CD80}")
    $oContext    = $invApp.TransientObjects.CreateTranslationContext()
    $oOptions    = $invApp.TransientObjects.CreateNameValueMap()
    $oDataMedium = $invApp.TransientObjects.CreateDataMedium()
    $oDataMedium.FileName = $OutPath
    $oContext.Type = [Inventor.IOMechanismEnum]::kFileBrowseIOMechanism

    [void]$stepTranslator.HasSaveCopyAsOptions($doc, $oContext, $oOptions)
    $stepTranslator.SaveCopyAs($doc, $oContext, $oOptions, $oDataMedium)

    $doc.Close($false)

    if (Test-Path $OutPath) {
        Write-Output "OK"
    } else {
        Write-Output "ERR_NO_OUTPUT"
    }
}
catch {
    Write-Output ("ERR_CONVERT: " + $_.Exception.Message)
}
finally {
    if ($invApp -ne $null) {
        try { $invApp.Quit() } catch { }
    }
}
