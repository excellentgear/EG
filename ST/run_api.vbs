Set WshShell = CreateObject("WScript.Shell")
' 這裡的路徑請確認是否正確
WshShell.Run "cmd /c python C:\MAMP\htdocs\EGsystem\ST\app.py", 0
Set WshShell = Nothing