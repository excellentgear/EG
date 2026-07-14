## 專案說明
這是一個用 PHP + MySQL 建立的內網ERP網站，透過 MAMP 在本地執行。

## 環境
- 本地網址：http://192.168.2.128/EGsystem
- PHP 版本：8.3.1
- 資料庫：egsystem
資料庫伺服器
伺服器： 127.0.0.1 via TCP/IP
伺服器類型： MySQL
伺服器連線： 未有使用 SSL 說明文件
伺服器版本： 9.4.0 - MySQL Community Server - GPL
協定版本： 10
使用者： root@localhost
伺服器字元集： UTF-8 Unicode (utf8mb4)

網頁伺服器
Apache/2.4.33 (Win64) OpenSSL/1.0.2u mod_fcgid/2.3.9 PHP/8.3.1
資料庫用戶端版本： libmysql - mysqlnd 8.3.1
PHP 擴充套件： mysqli 說明文件 curl 說明文件 mbstring 說明文件
PHP 版本： 8.3.1

phpMyAdmin
版本資訊： 5.2.3 (最新)

## 注意事項
- 有問題都要詢問，不可擅自猜測

## 數字顯示或輸入
- 輸入框不要有上下增減的按鈕
- 小數點後只有0的直接省略小數點與0
- 在輸入欄位按下enter要自動將游標移至下一個輸入欄位
- 輸入欄位有資料時，游標移到輸入欄位後自動全選目前內容，方便使用者輸入時直接取代內容
- 在任何輸入欄位有值的情況下，都要可以連點滑鼠左鍵兩次自動清除內容(若是篩選欄位也需要清除此篩選結果)

## 資料列表
- 預設超過10筆要有翻頁功能(可依照使用者要求修改，務必在修改前與使用者確認)
- 翻頁按鈕預設在列表右上方
- 要有可更改每頁顯示筆數功能(預設可選擇5筆、10筆、20筆、50筆)
- 所有列表都要有PDF匯出與CSV匯出功能
- 頁面都需要設定表頭與標尾功能
- 進頁時只預先載入第 1 頁；換頁、搜尋、篩選時才向後端要該頁資料 → 資料越來越多也不會拖慢進頁。

## 頁面權限
- 頁面角色設定請見 RBAC角色權限機制說明.md
- 每個頁面都要有設定(新增、修改、刪除、檢閱)角色的功能
- 每次新增後請至 EGsystem/views/user/user_permissions.php 仿照報價單方式增加本頁面角色設定功能
- 頁面標頭右側都要顯示目前使用者的角色，並在角色右後方顯示?圖示，點下後出現各角色權限說明跳窗
- 請注意所有頁面的權限角色都是分開設定，唯一只有 管理者 是固定有，並且擁有所有權限

## 參考檔案
- 資料庫字典：MYSQL 資料字典.txt

## 修改紀錄（重要）
- 每次完成程式修改後，必須將本次修改寫入資料表 page_change_log。
- 欄位：page_name(對應頁面相對路徑)、summary(簡易說明，一句話)、
  detail(詳細說明，可分點)、changed_at(修改日期時間)。
- 寫入方式：執行 INSERT，或透過 views/pages/change_log.php 的 add 動作。
- 完成後在回覆中告知已寫入哪一筆。
對應的 INSERT 範例（AI 可直接執行）：


INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
VALUES ('views/pm/xxx.php', '簡易說明', '詳細說明', NOW(), 'Claude');