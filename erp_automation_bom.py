# -*- coding: utf-8 -*-
import os
import pyautogui
import pyperclip
import time
import datetime
from pywinauto.keyboard import send_keys
import xlwings as xw # Moved import to the top

# 設定日期條件
today = datetime.date.today()
roc_year = today.year - 1911
roc_today_str = f"{roc_year}/{today.month:02d}/{today.day:02d}"
offset = 3 if today.weekday() == 0 else 1
from_day = today - datetime.timedelta(days=offset)
roc_from_day_year = from_day.year - 1911
roc_from_day_str = f"{roc_from_day_year}/{from_day.month:02d}/{from_day.day:02d}"
s_today_str = f"S-{today.month:02d}{today.day:02d}" #s-mmdd
IS_today_str = f"IS-{today.month:02d}{today.day:02d}" #s-mmdd
# 要儲存/開啟的檔名（含副檔名）
IS_file_name = f"{IS_today_str}.xlsx"

# （選用）若你要指定完整路徑（例如桌面），可啟用下面變數
import getpass, os
desktop = os.path.join("C:\\Users", getpass.getuser(), "Desktop")
IS_file_fullpath = os.path.join(desktop, IS_file_name)


# === 新增：判斷要輸入的查詢起始日期 ===
if today.day >= 15:
    start_date = today.replace(day=1)
else:
    # 若是 1 月，則要退回去年 12 月
    year = today.year
    month = today.month - 1
    if month == 0:
        month = 12
        year -= 1
    start_date = datetime.date(year, month, 1)

roc_start_year = start_date.year - 1911
roc_start_str = f"{roc_start_year}{start_date.month:02d}{start_date.day:02d}"

print("今天：", roc_today_str)
print("起始日：", roc_start_str)
print("IS查詢代號：", IS_today_str)

sleep_05=0.2
sleep_1=2
sleep_2 = 5
sleep_3 = 10
sleep_w = 20

file_path = r"\\excellentnas\生產課\BOM.xlsm"
password = "24584715"

is_file_path = r"F:\[EXCEL][備份]\轉換出貨單.xlsm"

# 先用系統方式開 bom（非 xlwings）
os.startfile(file_path)
print("等待 Excel 彈出密碼框")

time.sleep(sleep_1)  # 等待 Excel 彈出密碼框

# 自動輸入密碼並按 Enter
pyperclip.copy(password)
pyautogui.hotkey("ctrl", "v")
pyautogui.press("enter")
print("密碼已輸入，等待 Excel 開啟檔案")

time.sleep(sleep_3)  # 等待 Excel 完全開啟檔案

# 再用 xlwings 連線已開啟的 Excel
wb = xw.books.active  # 取得目前活動的 Excel 檔案

try:
    # 執行巨集
    print("開啟巨集 更新_當天移送")
    macro = wb.macro("更新_當天移送")
    macro()
    print("更新_當天移送 執行中")
    time.sleep(sleep_w)
    print("更新_當天移送 執行完成")
except Exception as e:
    print(f"\n'更新_當天移送' 巨集執行錯誤: {e}")

try:
    # 執行巨集 訂單未交START
    print("開啟巨集 訂單未交START")
    macro = wb.macro("訂單未交START")
    macro()
    print("訂單未交START 執行中")
    time.sleep(sleep_w)
    print("訂單未交START 執行完成")

    pyautogui.hotkey('ctrl', 's')  #儲存
    send_keys("%{F4}")  # Alt+F4 關閉
    time.sleep(sleep_1)
except Exception as e:
    print(f"\n'訂單未交START' 巨集執行錯誤: {e}")

# --- This section is now correctly de-indented ---
time.sleep(sleep_1)

# 開啟 轉換出貨單
os.startfile(is_file_path)
print("開啟 轉換出貨單")
time.sleep(sleep_3) # Give it time to open

# 開啟 轉換出貨單
os.startfile(is_file_path)
print("開啟 轉換出貨單")
time.sleep(sleep_3)  # 給它時間開啟

# 明確抓取轉換出貨單
try:
    wb = xw.books["轉換出貨單.xlsm"]  
except Exception as e:
    print(f"找不到 '轉換出貨單.xlsm'，錯誤: {e}")
    raise

try:
    # 執行巨集 ProcessISList
    print("開啟巨集 ProcessISList")
    macro = wb.macro("ProcessISList")
    macro()
    print("ProcessISList 執行中")

    # 等待檔案對話框出現
    time.sleep(sleep_2)

    # 把想要輸入的檔名放到剪貼簿（可改成 IS_file_fullpath）
    pyperclip.copy(IS_file_fullpath)          # 或 pyperclip.copy(IS_file_fullpath) 若要用完整路徑
    pyautogui.hotkey('ctrl', 'v')        # 貼上
    time.sleep(0.2)

    # 確認（Enter）
    pyautogui.press('enter')
    time.sleep(sleep_2)

    # 若有第二次確認（例如覆蓋提示），再按一次 Enter
    pyautogui.press('enter')

    print("ProcessISList 執行完成")
    
    send_keys("%{F4}")  # Alt+F4 關閉
    time.sleep(sleep_1)
    print("程式完整執行完成")
except Exception as e:
    print(f"\n'ProcessISList' 巨集執行錯誤: {e}")


print("\n診斷結束。")
import ctypes
ctypes.windll.user32.MessageBoxW(0, "全部流程已完成！", "完成提示", 0)

