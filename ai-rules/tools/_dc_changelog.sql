INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
VALUES (
'views/ADM/data_console.php',
'新增「資料急救台」：非IT管理員在前端查後端DB狀態並就地修正',
'用途：前端資料看起來不對時（例BOM未顯示QC已檢驗），管理員直接查後端資料庫實際狀態、確認後就地改正。\n三分頁：1.全域搜尋（一個料號/單號掃遍所有表，依命中筆數排序）2.瀏覽/查詢/修改（選表→點選式篩選建構器→分頁→編輯/新增modal，參照欄自動下拉、日期可選月曆）3.設定（僅管理員：表級開放can_edit/can_delete逐表開、關聯地圖覆寫、角色說明）。\n安全設計：預設全表唯讀逐表開放；主鍵/稽核欄/密碼欄自動唯讀；密碼類欄位(pass/pwd/secret/token/key)一律遮蔽為********且禁止寫入；audit_log/login_log等紀錄表永久唯讀；所有寫入走transaction+CSRF+必填原因+audit_log舊值新值落痕；刪除採二次確認+刪除前影響分析(掃出哪些表哪些欄引用此列、警示孤兒資料)。\n關聯地圖：命名慣例自動偵測(user_id→user/d_id→d_setting等)+種子覆寫+DB覆寫表data_console_refmap(管理員可頁內補)；帶出資料表與欄位的DB註解。\n檔案：views/ADM/data_console.php、resource/js/data_console.js、src/store/DataConsole_API.php、src/common/data_console_lib.php；新表data_console_table_cfg/data_console_refmap。\n權限模組data_console(view/edit/delete分開)，人員角色於views/user/user_permissions.php指派；選單登記system_module_pages page_id=110綁測試功能群組。',
NOW(), 'Claude');
