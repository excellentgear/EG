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
