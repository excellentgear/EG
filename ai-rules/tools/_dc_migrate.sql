-- data_console 資料急救台：表級設定表（哪些表可編輯／可刪除，預設全關）
CREATE TABLE IF NOT EXISTS data_console_table_cfg (
  table_name  VARCHAR(64)  NOT NULL PRIMARY KEY,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  note        VARCHAR(200) NULL,
  updated_by  VARCHAR(100) NULL,
  updated_at  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 關聯地圖覆寫表（欄位→參照表；自動偵測不準時由管理員覆寫；可為空）
CREATE TABLE IF NOT EXISTS data_console_refmap (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  src_table    VARCHAR(64)  NULL,   -- NULL=不分來源表，只看欄名
  src_column   VARCHAR(64)  NOT NULL,
  ref_table    VARCHAR(64)  NOT NULL,
  ref_pk       VARCHAR(64)  NULL,   -- NULL=自動抓該表主鍵
  display_cols VARCHAR(200) NULL,   -- 逗號分隔；NULL=自動挑名稱欄
  updated_by   VARCHAR(100) NULL,
  updated_at   DATETIME     NULL,
  UNIQUE KEY uq_src (src_table, src_column)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
