<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// ── PowerShell 安裝腳本（寫入 VBScript + 註冊 open-paint:// 協議）──
$ps = <<<'PSEOF'
$ErrorActionPreference = 'Stop'
try {
    $dir = 'C:\EGSystem'
    if (!(Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }

    $vbsPath = "$dir\open_paint.vbs"

    # VBScript handler: 收到 open-paint://HOST/PATH，補回 http:// 後下載並以 mspaint 開啟
    $vbs = @"
Set oShell = CreateObject("WScript.Shell")
Dim arg : arg = WScript.Arguments(0)
' 格式: open-paint://192.168.x.x/nas/file.jpg  -> 補回 http:// 成完整 URL
Dim url : url = "http://" & Mid(arg, Len("open-paint://") + 1)
Dim ext : ext = "jpg"
Dim dotPos : dotPos = InStrRev(url, ".")
If dotPos > 0 Then
    Dim rawExt : rawExt = LCase(Mid(url, dotPos + 1))
    If InStr(rawExt, "?") > 0 Then rawExt = Left(rawExt, InStr(rawExt, "?") - 1)
    If Len(rawExt) <= 5 Then ext = rawExt
End If
Dim tempPath : tempPath = oShell.ExpandEnvironmentStrings("%TEMP%") & "\eg_paint_tmp." & ext
On Error Resume Next
Dim oHTTP : Set oHTTP = CreateObject("MSXML2.ServerXMLHTTP.6.0")
oHTTP.Open "GET", url, False
oHTTP.setOption 2, 13056
oHTTP.Send
If Err.Number <> 0 Then
    MsgBox "Connection failed: " & Err.Description, 48, "Open with Paint"
    WScript.Quit
End If
Dim oStream : Set oStream = CreateObject("ADODB.Stream")
oStream.Open : oStream.Type = 1
oStream.Write oHTTP.responseBody
oStream.SaveToFile tempPath, 2
oStream.Close
Set oHTTP = Nothing : Set oStream = Nothing
If Err.Number = 0 Then
    oShell.Run "mspaint.exe " & Chr(34) & tempPath & Chr(34), 1, False
Else
    MsgBox "Save error: " & Err.Description, 48, "Open with Paint"
End If
"@

    # PS 5.1 的 Set-Content -Encoding UTF8 會寫入 BOM，wscript.exe 不支援 → 改用無 BOM 的 ASCII
    [System.IO.File]::WriteAllText($vbsPath, $vbs, [System.Text.Encoding]::ASCII)

    # 註冊協議（HKCU，不需管理員）
    $reg = 'HKCU:\SOFTWARE\Classes\open-paint'
    New-Item -Path $reg -Force | Out-Null
    Set-ItemProperty -Path $reg -Name '(default)' -Value 'URL:Open with MS Paint'
    New-ItemProperty -Path $reg -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
    New-Item -Path "$reg\DefaultIcon" -Force | Out-Null
    Set-ItemProperty -Path "$reg\DefaultIcon" -Name '(default)' -Value 'C:\Windows\system32\mspaint.exe,1'
    New-Item -Path "$reg\shell\open\command" -Force | Out-Null
    $cmd = 'wscript.exe //NoLogo "' + $vbsPath + '" "%1"'
    Set-ItemProperty -Path "$reg\shell\open\command" -Name '(default)' -Value $cmd

    Write-Host ""
    Write-Host "  安裝完成！" -ForegroundColor Green
    Write-Host "  返回 EGSystem 圖面查閱頁面，點選「用小畫家開啟」即可。" -ForegroundColor Cyan
} catch {
    Write-Host ""
    Write-Host "  安裝失敗: $_" -ForegroundColor Red
}
Write-Host ""
Write-Host "  按任意鍵關閉..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
PSEOF;

// PowerShell -EncodedCommand 需要 UTF-16LE base64
$utf16   = mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8');
$encoded = base64_encode($utf16);

// 生成 .bat 安裝檔（雙擊即可執行）
$bat  = "@echo off\r\n";
$bat .= "chcp 65001 >nul 2>&1\r\n";
$bat .= "echo.\r\n";
$bat .= "echo  =============================================\r\n";
$bat .= "echo   EGSystem - Paint Quick-Open Installer\r\n";
$bat .= "echo  =============================================\r\n";
$bat .= "echo.\r\n";
$bat .= "powershell.exe -NoProfile -ExecutionPolicy Bypass -EncodedCommand {$encoded}\r\n";

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="install_open_paint.bat"');
header('Content-Length: ' . strlen($bat));
header('Cache-Control: no-cache, must-revalidate');
echo $bat;
exit;
?>
