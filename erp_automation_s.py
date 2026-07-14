# -*- coding: utf-8 -*-

import time
import datetime
import pywinauto
import pyautogui
from pywinauto import Application, Desktop
from pywinauto.keyboard import send_keys
import getpass
import os
import win32com.client
import pyperclip

# ===== 腳本設定 =====
# ERP_PID = 8736  # ERP PID，每次重開可能會變
PRINT_BUTTON_COORDS = (1607, 987)  # 印表按鈕座標
line1_COORDS = (688, 370)  # 條件1 下拉
REQ2_max_COORDS = (1274, 209)  # 加工憑單放大
# ===== 腳本設定 =====
# 一開始先讓使用者輸入 ERP PID
try:
    ERP_PID = int(input("請輸入 ERP 程式的 PID: "))
except ValueError:
    print("輸入錯誤，必須輸入數字！")
    exit(1)

# 設定日期條件
today = datetime.date.today()
roc_year = today.year - 1911
roc_today_str = f"{roc_year}/{today.month:02d}/{today.day:02d}"
offset = 3 if today.weekday() == 0 else 1
from_day = today - datetime.timedelta(days=offset)
roc_from_day_year = from_day.year - 1911
roc_from_day_str = f"{roc_from_day_year}/{from_day.month:02d}/{from_day.day:02d}"
s_today_str = f"SupQuery.xls" #s-mmdd
IS_today_str = f"IS-{today.month:02d}{today.day:02d}" #IS

# === 新增：判斷要輸入的查詢起始日期 ===
if today.day >= 10:
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
print("查詢代號：", s_today_str)

close_s1=(1406,324) #關閉多次加工憑單列印
close_s2=(1908,42) #關閉多次加工憑單
close_user=(1225,298) #關閉已有使用者使用中
open_select=(183,81) #打開選單
select_order_system=(80,128) #選擇 採購
sleep_05 = 0.2
sleep_1 = 0.5
sleep_2 = 2
sleep_w = 30  # 等待資料匯出excel
sleep_order=15 #等待未交明細
sleep_order_wait=60 #等待未交明細轉成excel
sleep_IS=10 #等待銷貨日報表
sleep_IS_wait=120 #等待銷貨日報表轉成excel

def main():
    try:
        # --- 連接到已執行的 ERP 程式 ---
        print(f"正在使用 PID ({ERP_PID}) 直接連接到已執行的 ERP 程式...")
        app = Application(backend="uia").connect(process=ERP_PID)
        print("已連接到 ERP 程式！")
        main_win = app.top_window()
        main_win.set_focus()
        print(f"成功連接到主視窗 (標題: {main_win.window_text()})")
        time.sleep(sleep_1)

        # === Step 0: 切換 生管 ===
        pyautogui.click(open_select)
        print(f"打開選單")
        time.sleep(sleep_05)
        pyautogui.click(34,168)
        print(f"選擇 生管")
        pyautogui.click(close_user)


        # === Step 1: 使用鍵盤快捷鍵打開目標報表 ===
        print("正在使用鍵盤快捷鍵開啟報表...")
        send_keys('%F')  # Alt+F
        time.sleep(sleep_05)
        send_keys('{DOWN 3}')  # 往下 3 次
        time.sleep(sleep_05)
        send_keys('{RIGHT}')  # 展開子選單
        time.sleep(sleep_05)
        send_keys('{DOWN}')  # 往下 1 次
        time.sleep(sleep_05)
        send_keys('{ENTER}')  # 確認選擇
        time.sleep(sleep_05)

        # === Step 1B: 放大 ===
        pyautogui.click(REQ2_max_COORDS)
        time.sleep(sleep_05)

        # === Step 2: 點擊印表按鈕 ===
        print("正在點擊『印表』按鈕...")
        pyautogui.click(PRINT_BUTTON_COORDS)
        time.sleep(sleep_05)
        send_keys('{ENTER}')
        time.sleep(sleep_2)

        # === Step 3: 開啟條件視窗並設定日期 ===
        doc_win = app.top_window()
        time.sleep(sleep_1)
        doc_win.child_window(title_re="條件.*", control_type="Button").click_input()
        doc_win.child_window(title_re="條件.*", control_type="Button").click_input()

        cond_win = app.window(title_re=".*條件.*")
        cond_win.wait('ready', timeout=15)
        pyautogui.click(line1_COORDS)
        send_keys('{DOWN 16}{ENTER}{TAB 2}{DOWN 2}{TAB 2}' + roc_today_str + '{ENTER}')
        send_keys('{TAB 3}{DOWN 18}{ENTER}{TAB 2}{DOWN 4}{TAB 2}' + roc_from_day_str + '{ENTER}')
        print(f"設定日期條件: 從 {roc_from_day_str} 到 {roc_today_str}")

        # === Step 4: 查詢並轉文字 ===
        doc_win = app.top_window()
        doc_win.child_window(title_re="查詢.*", control_type="Button").click_input()
        time.sleep(sleep_05)
        doc_win.child_window(title_re="轉文字.*", control_type="Button").click_input()
        send_keys('{DOWN 2}{ENTER}')
        time.sleep(sleep_2)

        # === Step 5: 切換到 Excel SupQuery，另存桌面 ===
        print("正在切換到 Excel 視窗 (SupQuery)...")
        time.sleep(3)
        desktop = Desktop(backend="uia")
        excel_win = desktop.window(title_re=".*SupQuery.*- Excel")
        excel_win.set_focus()
        time.sleep(sleep_w)

        username = getpass.getuser()
        desktop_path = os.path.join("C:\\Users", username, "Desktop",f"{s_today_str}.xlsx")
        send_keys('{F12}')
        time.sleep(2)
        send_keys(desktop_path)
        time.sleep(1)
        send_keys('{ENTER}')
        time.sleep(1)

        # 處理是否覆蓋檔案
        try:
            dlg = desktop.window(title_re="Microsoft Excel")
            yes_btn = dlg.child_window(title="是", control_type="Button")
            if yes_btn.exists(timeout=2):
                print("偵測到『是否覆蓋』，自動按『是』")
                yes_btn.click_input()
        except:
            pass

        send_keys("%{F4}")  # Alt+F4 關閉 SupQuery
        time.sleep(2)
        # 若出現「是否儲存」視窗
        try:
            dlg = desktop.window(title_re="Microsoft Excel")
            no_btn = dlg.child_window(title="不要儲存", control_type="Button")
            if no_btn.exists(timeout=2):
                no_btn.click_input()
        except:
            pass

        print(f"已另存 Excel 到 {desktop_path} 並關閉 Excel")

        # === Step 6: 關閉 多次加工憑單==
        pyautogui.click(close_s1)
        time.sleep(sleep_05)
        pyautogui.click(close_s2)
        time.sleep(sleep_05)
        print(f"完成 多次加工憑單 匯出")


        # === Step 7: 切換 採購訂單 ===
        pyautogui.click(open_select)
        print(f"打開選單")
        time.sleep(sleep_05)
        pyautogui.click(select_order_system)
        pyautogui.click(close_user)
        print(f"選擇 採購訂單")

        # === Step 8: 開啟 採購訂單 ===
        print("正在使用鍵盤快捷鍵開啟報表...")
        send_keys('%R')  # Alt+F
        time.sleep(sleep_05)
        send_keys('{DOWN 1}')  # 往下 1 次
        time.sleep(sleep_05)
        send_keys('{RIGHT}')  # 展開子選單
        time.sleep(sleep_05)
        send_keys('{DOWN}')  # 往下 1 次
        time.sleep(sleep_05)
        send_keys('{ENTER}')  # 確認選擇
        time.sleep(sleep_05)

        # === Step 9: 開啟 採購訂單 ===
        pyautogui.click(1163,479) #切換客戶別
        print(f"切換客戶別")
        time.sleep(sleep_05)
        pyautogui.click(1152,575) #取消 顯示小計
        print(f"取消 顯示小計")
        pyautogui.click(975,768) #列印
        print(f"列印")
        time.sleep(sleep_order)

        pyautogui.click(415,127) #轉EXCEL
        print(f"轉EXCEL")
        time.sleep(sleep_2)

        username = getpass.getuser()
        desktop_path = os.path.join("C:\\Users", username, "Desktop","order.xlsx")
        send_keys(desktop_path)
        time.sleep(sleep_2)
        pyautogui.click(1193,540) #存檔
        time.sleep(sleep_order_wait)
        print(f"等待未交明細轉成excel")

        # === Step 10: 切換到 order，另存桌面 ===
        time.sleep(sleep_order_wait)
        print("正在切換到 Excel 視窗 (order)...")
        desktop = Desktop(backend="uia")
        excel_win = desktop.window(title_re=".*order.*- Excel")
        excel_win.set_focus()
        time.sleep(sleep_2)

        
        pyautogui.hotkey('ctrl', 's')  #儲存
        send_keys("%{F4}")  # Alt+F4 關閉 SupQuery
        time.sleep(2)

        print(f"已儲存並關閉 Excel")

        # === Step 11: 關閉 多次加工憑單 ===
        pyautogui.click(750,122)
        print(f"關閉已訂購未出貨明細表")
        time.sleep(sleep_1)
        pyautogui.click(1240,398)
        print(f"完成 已訂購未出貨明細表 匯出")
        
        # === Step 12: 切換 進銷存 ===
        pyautogui.click(open_select)
        print(f"打開選單")
        time.sleep(sleep_05)
        pyautogui.click(50,105)
        pyautogui.click(close_user)
        print(f"選擇 進銷存")

        # === Step 13: 開啟 銷貨單日報表 ===
        print("正在使用鍵盤快捷鍵開啟報表...")
        send_keys('%R')  # Alt+F
        time.sleep(sleep_05)
        send_keys('{DOWN 1}')  # 往下 1 次
        time.sleep(sleep_05)
        send_keys('{RIGHT}')  # 展開子選單
        time.sleep(sleep_05)
        send_keys('{DOWN 4}')  # 往下 4 次
        time.sleep(sleep_05)
        send_keys('{ENTER}')  # 確認選擇
        time.sleep(sleep_1)

        # === Step 14: 開啟 銷貨單日報表轉報表  ===
        pyperclip.copy(roc_start_str)
        pyautogui.hotkey("ctrl", "v")
        print(f"更改起始日期")
        time.sleep(sleep_05)
        pyautogui.click(1216,706) #取消 顯示小計
        print(f"取消 顯示小計")
        pyautogui.click(1054,823) #列印
        print(f"列印")
        time.sleep(sleep_IS)

        pyautogui.click(415,127) #轉EXCEL
        print(f"轉EXCEL")
        time.sleep(sleep_2)

        username = getpass.getuser()
        desktop_path = os.path.join("C:\\Users", username, "Desktop",f"{IS_today_str}.xlsx")
        send_keys(desktop_path)
        time.sleep(sleep_2)
        pyautogui.click(1193,540) #存檔
        print(f"等待出貨明細轉成excel")

        # === Step 15: 切換到 IS，另存桌面 ===
        time.sleep(sleep_IS_wait)
        print("正在切換到 Excel 視窗 (IS)...")
        desktop = Desktop(backend="uia")
        desktop_path = desktop.window(title_re=f".*{IS_today_str}.*- Excel")
        send_keys(desktop_path)
        time.sleep(sleep_2)
        excel_win.set_focus()
        time.sleep(sleep_2)

        
        pyautogui.hotkey('ctrl', 's')  #儲存
        send_keys("%{F4}")  # Alt+F4 關閉 SupQuery
        time.sleep(2)

        print(f"已儲存並關閉 Excel")

        # === Step 16: 關閉 銷貨單日報表 ===
        pyautogui.click(750,125)
        time.sleep(sleep_1)
        pyautogui.click(1348,356)
        time.sleep(sleep_1)
        print(f"完成 銷貨單日報表 匯出")

        
    except Exception as e:
        print(f"\n發生未預期的錯誤: {e}")

    input("\n按 Enter 鍵結束...")


import sys
import subprocess

if __name__ == "__main__":
    main()
    print("第一支程式完成，準備執行第二支...")

    subprocess.run([sys.executable, r"C:\MAMP\htdocs\EGsystem\erp_automation_bom.py"], check=True)

