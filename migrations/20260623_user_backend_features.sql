USE `csieDBTeam09`;

-- 此 migration 只補上「後端管理」所需欄位與索引，不刪除既有資料。
-- 已整合管理後台 API 欄位，讓 backend-* 頁面與 api/* 端點共用同一批資料表。

CREATE TABLE IF NOT EXISTS `Notification` (
  `notification_id` varchar(50) NOT NULL,
  `receiver_id` varchar(10) NOT NULL,
  `receiver_role` enum('Inspector','Technician','assetmanager','deptmanager') NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_id` varchar(50) NOT NULL,
  `title` varchar(100) NOT NULL,
  `content` text NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` datetime NULL,
  PRIMARY KEY (`notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `Auditlog` (
  `audit_id` varchar(50) NOT NULL,
  `employee_id` varchar(10) NOT NULL,
  `function_name` varchar(100) NOT NULL,
  `action_type` enum('新增','修改','刪除','簽核','登入','匯入','匯出') NOT NULL,
  `target_type` varchar(100) NULL,
  `target_id` varchar(100) NULL,
  `old_data` longtext NULL,
  `new_data` longtext NULL,
  `before_data` longtext NULL,
  `after_data` longtext NULL,
  `ip_address` varchar(45) NULL,
  `result` varchar(20) NOT NULL DEFAULT '成功',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `Importbatch` (
  `batch_id` varchar(50) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `uploaded_by` varchar(10) NOT NULL,
  `total_count` int NOT NULL DEFAULT 0,
  `success_count` int NOT NULL DEFAULT 0,
  `fail_count` int NOT NULL DEFAULT 0,
  `status` enum('驗證中','成功','部分失敗','失敗') NOT NULL DEFAULT '驗證中',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `Importerror` (
  `error_id` varchar(50) NOT NULL,
  `batch_id` varchar(50) NOT NULL,
  `row_no` int NOT NULL DEFAULT 0,
  `row_number` int NULL,
  `field_name` varchar(100) NULL,
  `error_message` text NULL,
  `row_data` longtext NULL,
  PRIMARY KEY (`error_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `Auditlog`
  MODIFY COLUMN `action_type` enum('新增','修改','刪除','簽核','登入','匯入','匯出') NOT NULL,
  ADD COLUMN IF NOT EXISTS `target_type` varchar(100) NULL,
  ADD COLUMN IF NOT EXISTS `target_id` varchar(100) NULL,
  ADD COLUMN IF NOT EXISTS `old_data` longtext NULL,
  ADD COLUMN IF NOT EXISTS `new_data` longtext NULL,
  ADD COLUMN IF NOT EXISTS `before_data` longtext NULL,
  ADD COLUMN IF NOT EXISTS `after_data` longtext NULL,
  ADD COLUMN IF NOT EXISTS `ip_address` varchar(45) NULL,
  ADD COLUMN IF NOT EXISTS `result` varchar(20) NOT NULL DEFAULT '成功',
  ADD COLUMN IF NOT EXISTS `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `Importerror`
  ADD COLUMN IF NOT EXISTS `row_no` int NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `row_number` int NULL,
  ADD COLUMN IF NOT EXISTS `field_name` varchar(100) NULL,
  ADD COLUMN IF NOT EXISTS `error_message` text NULL,
  ADD COLUMN IF NOT EXISTS `row_data` longtext NULL;

ALTER TABLE `Notification`
  ADD COLUMN IF NOT EXISTS `source_type` varchar(50) NOT NULL DEFAULT '系統',
  ADD COLUMN IF NOT EXISTS `source_id` varchar(50) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `title` varchar(100) NOT NULL DEFAULT '系統通知',
  ADD COLUMN IF NOT EXISTS `content` text NULL,
  ADD COLUMN IF NOT EXISTS `is_read` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `read_at` datetime NULL;

ALTER TABLE `Maintenancelog`
  ADD COLUMN IF NOT EXISTS `work_hours` decimal(5,2) NULL,
  ADD COLUMN IF NOT EXISTS `labor_cost` decimal(10,2) NULL,
  ADD COLUMN IF NOT EXISTS `material_cost` decimal(10,2) NULL,
  ADD COLUMN IF NOT EXISTS `total_cost` decimal(10,2) NULL;

ALTER TABLE `Photos`
  ADD COLUMN IF NOT EXISTS `filename` varchar(255) NULL,
  ADD COLUMN IF NOT EXISTS `uploaded_by` varchar(10) NULL,
  ADD COLUMN IF NOT EXISTS `uploaded_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `is_deleted` tinyint(1) NOT NULL DEFAULT 0;

ALTER TABLE `Partspecs`
  ADD COLUMN IF NOT EXISTS `safe_stock` int NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reorder_qty` int NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `last_check_time` datetime NULL,
  ADD COLUMN IF NOT EXISTS `unit_cost` decimal(10,2) NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `provider` varchar(50) NULL;