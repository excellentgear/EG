#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# 擋下「按程序名稱砍掉全部 chrome.exe」的指令。
#
# 為什麼要有這個檔（2026-08-24 實際事故）：
#   平行的 Claude session 為了清理測試用的無頭 Chrome，執行了
#       taskkill //F //IM chrome.exe
#   這行會把使用者「正在使用的瀏覽器連同所有分頁」一起強制終止。
#   症狀極難查：外部強制終止會繞過例外處理，所以不會產生 crash dump、
#   Windows 事件記錄也是零，看起來就像「Chrome 自己莫名其妙關掉」。
#   實測 8/21 砍了 9 次、8/24 一個上午砍了 4 次。
#
# 正確做法：測試用的 Chrome 一律自帶專屬設定檔啟動
#       chrome.exe --headless --user-data-dir=<暫存目錄> ...
#   然後只砍自己那一個 PID（記下 proc_open / Start-Process 回傳的 PID），
#   或用指令列比對只殺含有該暫存目錄字串的那一個程序。
# ---------------------------------------------------------------------------
input=$(cat)

deny() {
  cat <<'JSON'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"已封鎖：這行指令會按名稱砍掉『全部』的 chrome.exe，包含使用者正在使用的瀏覽器（2026-08-24 已實際造成事故）。測試用的 Chrome 請以專屬 --user-data-dir 啟動，並只終止你自己啟動的那一個 PID。詳見 .claude/hooks/block_chrome_kill.sh 與 CLAUDE.md 鐵律9。"}}
JSON
  exit 0
}

# taskkill /IM chrome.exe（含 Git Bash 的 //IM 寫法）
printf '%s' "$input" | grep -Eiq 'taskkill[^;&|]*/{1,2}IM[^;&|]{0,20}chrome' && deny
# Get-Process chrome | Stop-Process
printf '%s' "$input" | grep -Eiq 'Get-Process[^;&|]*chrome[^;&|]*\|[^;&|]*Stop-Process' && deny
# Stop-Process -Name chrome
printf '%s' "$input" | grep -Eiq 'Stop-Process[^;&|]*-Name[^;&|]{0,20}chrome' && deny
# wmic process where name="chrome.exe" delete
printf '%s' "$input" | grep -Eiq 'wmic[^;&|]*chrome[^;&|]*delete' && deny

exit 0
