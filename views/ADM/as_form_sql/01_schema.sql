-- AS 線上表單設計器 第一期資料模型
-- 五張表：模板主檔 / 發布版本(凍結) / 填寫紀錄 / 簽核區 / 單一表單授權
SET NAMES utf8mb4;

-- 1) 模板主檔（一張表單一列；current_schema 為草稿，發布時凍結進 version 表）
CREATE TABLE IF NOT EXISTS `as_form_template` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `form_doc_id` INT DEFAULT NULL COMMENT '繫結的 as_document.id（四階表單，先建表名才能設計/授權）',
  `name` VARCHAR(200) NOT NULL COMMENT '表單名稱',
  `current_schema` LONGTEXT COMMENT '編輯中的 schema JSON（版面/欄位/勾稽/簽核區）',
  `published_version` INT NOT NULL DEFAULT 0 COMMENT '目前發布版號，0=尚未發布',
  `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_by` VARCHAR(30) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_aft_doc` (`form_doc_id`),
  KEY `idx_aft_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='AS線上表單模板主檔';

-- 2) 發布版本（immutable 快照；instance 依此凍結版填寫，改版不影響舊紀錄）
CREATE TABLE IF NOT EXISTS `as_form_template_version` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `template_id` INT NOT NULL,
  `version` INT NOT NULL COMMENT '發布版號',
  `schema_json` LONGTEXT NOT NULL COMMENT '該版凍結的 schema',
  `published_by` VARCHAR(30) DEFAULT NULL,
  `published_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tpl_ver` (`template_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='AS表單模板發布版本(凍結快照)';

-- 3) 填寫紀錄（一筆填寫一列；data_json 存各欄位值）
CREATE TABLE IF NOT EXISTS `as_form_instance` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `template_id` INT NOT NULL,
  `template_version` INT NOT NULL COMMENT '依據哪個發布版填的(凍結)',
  `form_doc_id` INT DEFAULT NULL COMMENT '冗餘：所屬 as_document.id',
  `serial_no` VARCHAR(60) DEFAULT NULL COMMENT '單號(可選配號)',
  `title` VARCHAR(200) DEFAULT NULL COMMENT '辨識標題(可由欄位帶入)',
  `data_json` LONGTEXT COMMENT '各欄位填值 {field_key:value}',
  `status` ENUM('draft','submitted','in_review','approved','rejected','void') NOT NULL DEFAULT 'draft',
  `created_by` VARCHAR(30) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_afi_tpl` (`template_id`),
  KEY `idx_afi_doc` (`form_doc_id`),
  KEY `idx_afi_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='AS線上表單填寫紀錄';

-- 4) 簽核區狀態（多區結構：每區一列；第一期只用一區）
--    形狀比照 approval_record 但獨立，避免耦合既有簽核消費端
CREATE TABLE IF NOT EXISTS `as_form_approval` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `instance_id` INT NOT NULL,
  `section_key` VARCHAR(40) NOT NULL DEFAULT 'main' COMMENT '簽核區代號',
  `section_label` VARCHAR(100) DEFAULT NULL,
  `step_no` INT NOT NULL DEFAULT 1 COMMENT '依序關卡序(同 step=平行)',
  `approver_rule_json` VARCHAR(500) DEFAULT NULL COMMENT '簽核者規則快照(position/level/dept)',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approver_id` INT DEFAULT NULL,
  `approver_name` VARCHAR(50) DEFAULT NULL,
  `note` TEXT,
  `decided_at` DATETIME DEFAULT NULL,
  `live_event_id` INT DEFAULT NULL COMMENT '對應通知 live_event.id',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_afa_inst` (`instance_id`),
  KEY `idx_afa_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='AS線上表單簽核區狀態(多區)';

-- 5) 單一表單授權（有權限者把某張表單授權給同部門組員設計/編輯）
CREATE TABLE IF NOT EXISTS `as_form_grant` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `template_id` INT NOT NULL COMMENT '被授權的表單模板(須先建立表名)',
  `grantee_id` INT NOT NULL COMMENT '被授權人 user.id',
  `grantee_name` VARCHAR(50) DEFAULT NULL,
  `granted_by` INT DEFAULT NULL COMMENT '授權人 user.id',
  `granted_by_name` VARCHAR(50) DEFAULT NULL,
  `granted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME DEFAULT NULL COMMENT '撤銷時間，NULL=生效中',
  PRIMARY KEY (`id`),
  KEY `idx_afg_tpl` (`template_id`),
  KEY `idx_afg_grantee` (`grantee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='AS線上表單單一表單授權紀錄';
