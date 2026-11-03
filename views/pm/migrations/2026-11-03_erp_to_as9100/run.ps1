# ERP 附件搬家：\\excellentnas\生產課\BOM\ERP  →  \\excellentnas\AS9100維護\ERP AS9100文件(勿刪)
# 一律「複製」，舊資料夾完全不動、不刪。流程：複製→驗證→切換設定值→再複製一次補漏→驗證。
#   dry : 只盤點（數量、大小、同名衝突），不複製
#   run : 實際執行（排程 2026-11-03 21:00 呼叫）
param([ValidateSet('dry','run')][string]$Mode = 'dry')
$ErrorActionPreference = 'Stop'
$Old  = '\\excellentnas\生產課\BOM\ERP'
$New  = '\\excellentnas\AS9100維護\ERP AS9100文件(勿刪)'
$Subs = @('業務','資材(生管and業務)','技術','共用資料','品管','公告通知','個人工作','採購')
$Php  = 'C:\MAMP\bin\php\php8.3.1\php.exe'
$Dir  = $PSScriptRoot
$Log  = Join-Path $Dir ('erp_move_{0}.log' -f (Get-Date -Format 'yyyyMMdd'))

function L([string]$m) {
    $l = '[{0}] {1}' -f (Get-Date -Format 'HH:mm:ss'), $m
    Write-Host $l
    Add-Content -LiteralPath $Log -Value $l -Encoding UTF8
}
function Get-Map([string]$root) {
    $h = @{}
    if (-not (Test-Path -LiteralPath $root)) { return $h }
    $n = $root.TrimEnd('\').Length + 1
    Get-ChildItem -LiteralPath $root -Recurse -File -Force -ErrorAction SilentlyContinue |
        ForEach-Object { $h[$_.FullName.Substring($n)] = $_.Length }
    return $h
}
# 回傳 @{ src=; missing=; diff=; extra= }（diff＝目標已有同名檔但大小不同＝不會被覆蓋）
function Compare-Sub([string]$sub) {
    $s = Get-Map (Join-Path $Old $sub)
    $d = Get-Map (Join-Path $New $sub)
    $miss = 0; $diff = 0; $bytes = 0; $examples = @()
    foreach ($k in $s.Keys) {
        $bytes += $s[$k]
        if (-not $d.ContainsKey($k)) { $miss++ }
        elseif ($d[$k] -ne $s[$k]) { $diff++; if ($examples.Count -lt 5) { $examples += $k } }
    }
    return @{ src = $s.Count; bytes = $bytes; dst = $d.Count; missing = $miss; diff = $diff; examples = $examples }
}
function Copy-Sub([string]$sub) {
    $src = Join-Path $Old $sub
    $dst = Join-Path $New $sub
    if (-not (Test-Path -LiteralPath $src)) { L "略過（來源不存在）：$sub"; return 0 }
    # /XC /XN /XO：已存在的檔案不論新舊都不覆蓋；沒有 /MIR /PURGE：絕不刪除任何東西
    $out = & robocopy.exe $src $dst /E /XC /XN /XO /COPY:DAT /DCOPY:DAT /R:2 /W:3 /MT:8 /NP /NFL /NDL /NJH | Out-String
    $code = $LASTEXITCODE
    $tail = ($out -split "`r?`n" | Where-Object { $_.Trim() -ne '' } | Select-Object -Last 8) -join ' | '
    L ("robocopy {0} exit={1}  {2}" -f $sub, $code, $tail)
    return $code
}
function Copy-Loose {
    # ERP 根目錄底下散放的檔案（庫存表備份等）
    $files = Get-ChildItem -LiteralPath $Old -File -Force -ErrorAction SilentlyContinue
    if (-not $files) { return }
    $dst = Join-Path $New 'ERP根目錄舊檔'
    if (-not (Test-Path -LiteralPath $dst)) { New-Item -ItemType Directory -Path $dst | Out-Null }
    foreach ($f in $files) {
        $t = Join-Path $dst $f.Name
        if (-not (Test-Path -LiteralPath $t)) { Copy-Item -LiteralPath $f.FullName -Destination $t; L "複製根目錄檔：$($f.Name)" }
    }
}
function Verify-All([string]$tag) {
    $bad = 0
    foreach ($sub in $Subs) {
        if (-not (Test-Path -LiteralPath (Join-Path $Old $sub))) { continue }
        $c = Compare-Sub $sub
        L ("[{0}] {1}: 來源 {2} 檔 / {3:N1} MB，目標 {4} 檔，缺少 {5}、大小不同 {6}" -f $tag, $sub, $c.src, ($c.bytes/1MB), $c.dst, $c.missing, $c.diff)
        if ($c.diff -gt 0) { L ("    同名但大小不同（未覆蓋）：" + ($c.examples -join ' ; ')) }
        $bad += $c.missing + $c.diff
    }
    return $bad
}
function Sample-Hash {
    $n = 0; $badH = 0
    foreach ($sub in $Subs) {
        $root = Join-Path $Old $sub
        if (-not (Test-Path -LiteralPath $root)) { continue }
        $cut = $Old.Length + 1
        $files = Get-ChildItem -LiteralPath $root -Recurse -File -Force -ErrorAction SilentlyContinue | Get-Random -Count 40 -ErrorAction SilentlyContinue
        foreach ($f in $files) {
            $t = Join-Path $New $f.FullName.Substring($cut)
            if (-not (Test-Path -LiteralPath $t)) { continue }
            $n++
            if ((Get-FileHash -LiteralPath $f.FullName -Algorithm MD5).Hash -ne (Get-FileHash -LiteralPath $t -Algorithm MD5).Hash) { $badH++; L "MD5 不同：$($f.FullName)" }
        }
    }
    L "MD5 抽樣 $n 檔，不同 $badH"
    return $badH
}

L "===== ERP 搬家 mode=$Mode ====="
if (-not (Test-Path -LiteralPath $Old)) { L '來源讀不到，中止'; exit 1 }
if (-not (Test-Path -LiteralPath $New)) { L '目標根資料夾讀不到，中止'; exit 1 }

if ($Mode -eq 'dry') {
    foreach ($sub in $Subs) {
        if (-not (Test-Path -LiteralPath (Join-Path $Old $sub))) { L "$sub : 來源不存在"; continue }
        $c = Compare-Sub $sub
        L ("{0}: 來源 {1} 檔 / {2:N1} MB；目標現有 {3} 檔；尚未複製 {4}；同名衝突 {5} {6}" -f $sub, $c.src, ($c.bytes/1MB), $c.dst, $c.missing, $c.diff, ($c.examples -join ' ; '))
    }
    exit 0
}

# ── 1. 第一輪複製 ──
foreach ($sub in $Subs) {
    $code = Copy-Sub $sub
    if ($code -ge 8) { L "複製失敗（exit=$code），中止，尚未切換任何設定。舊位置完全沒動。"; exit 1 }
}
Copy-Loose
# ── 2. 驗證，未通過就不切換 ──
if ((Verify-All '第一輪') -gt 0) { L '驗證未通過，中止，尚未切換任何設定。'; exit 1 }
# ── 3. 切換設定值與資料庫內的完整路徑 ──
L '開始切換設定值'
& $Php (Join-Path $Dir 'switch.php') --switch 2>&1 | ForEach-Object { L "  switch: $_" }
if ($LASTEXITCODE -ne 0) { L '切換失敗（資料庫未變更或已回復），中止。'; exit 1 }
# ── 4. 補漏：第一輪到切換之間舊位置新寫入的檔案 ──
foreach ($sub in $Subs) { [void](Copy-Sub $sub) }
Copy-Loose
$bad = Verify-All '補漏後'
$h = Sample-Hash
if ($bad -gt 0 -or $h -gt 0) { L "★ 補漏後仍有差異（缺少/不同 $bad、MD5 不同 $h），請檢查。要退回：php switch.php --rollback" }
else { L '全部完成：每個來源檔案都在新位置且大小相同，MD5 抽樣一致。舊資料夾保留未刪。' }
