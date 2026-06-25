-- run mysql -u root -p db09 < schema.sql


USE `csieDBTeam09`;

CREATE TABLE IF NOT EXISTS `Powerasset` (
  `asset_id` varchar(10) PRIMARY KEY NOT NULL comment '資產ID',
  `sector_id` varchar(50) NOT NULL comment '區域id',
  `type` varchar(20)  NOT NULL comment '電塔,電線桿...',
  `spec_id` varchar(50) NOT NULL comment '規格id',
  `gps` point comment '地理位置'
);

CREATE TABLE IF NOT EXISTS `Health` (
  `asset_id` varchar(10) NOT NULL COMMENT '資產ID',
  `install_date` date comment '安裝日期',
  `expected_lifespan` int NOT NULL comment '預計壽命',
  `last_inspection_date` date comment '上次檢查日期',
  `health_level` enum('優', '良', '待修', '危險') comment '健康程度',
  `current_status` enum('運作中', '維修中', '報廢') comment '目前狀態',
  `valid_from` datetime NOT NULL comment '開始運行日',
  `valid_to` datetime comment '汰換日',
  PRIMARY KEY (`asset_id`, `valid_from`)
) COMMENT = 'valid_to is defined as valid_from + expected lifespan';

CREATE TABLE IF NOT EXISTS `Sector` (
  `sector_id` varchar(50) PRIMARY KEY NOT NULL comment '區域ID',
  `feeder_area` varchar(10) comment '饋線區域',
  `street` varchar(100) comment '街道',
  `city` varchar(50) comment '城市',
  `postal_code` varchar(10) comment '郵遞區號',
  `country` varchar(50) comment '國家'
);

CREATE TABLE IF NOT EXISTS `Employees`(
  `id_num` varchar(10) PRIMARY KEY NOT NULL comment '員工ID',
  `fullname` varchar(100) NOT NULL comment '姓名',
  `role` enum('Inspector', 'Technician','assetmanager','deptmanager') NOT NULL comment '身分組',
  `account` varchar(50) NOT NULL comment '帳號',
  `userpwd` varchar(255) NOT NULL comment '密碼',
  `pwdhash` varchar(255) NOT NULL comment '密碼雜湊值',
  `status` enum('active', 'inactive') NOT NULL comment '帳號狀態',
  `email` varchar(100) comment '電子郵件',
  `created_at` datetime NOT NULL comment '帳號建立時間',
  `updated_at` datetime comment '帳號更新時間'
);

CREATE TABLE IF NOT EXISTS `Inspectionlog` (
  `inspec_id` varchar(50) PRIMARY KEY NOT NULL comment '檢查紀錄ID',
  `asset_id` varchar(10) NOT NULL comment '資產ID',
  `inspector_id` varchar(10) NOT NULL comment '檢查員ID',
  `observation` text comment '觀察結果',
  `risk_score` int comment '風險分數' CHECK (`risk_score` BETWEEN 0 AND 100),
  `inspec_time` datetime NOT NULL comment '檢查時間'
) COMMENT = 'risk_score range: 0–100';

CREATE TABLE IF NOT EXISTS `Photos`(
  `photo_id` varchar(50) PRIMARY KEY NOT NULL comment '照片ID',
  `asset_id` varchar(10) NOT NULL comment '資產ID',
  `inspec_id` varchar(50) comment '檢查紀錄ID',
  `maint_id` varchar(50) comment '維修紀錄ID',
  `url` varchar(255) NOT NULL comment '照片網址',
  `filename` varchar(255) comment '原始檔名',
  `uploaded_by` varchar(10) NOT NULL comment '上傳人員',
  `uploaded_at` datetime NOT NULL comment '上傳時間',
  `is_deleted` boolean NOT NULL DEFAULT FALSE comment '是否已刪除'
);

CREATE TABLE IF NOT EXISTS `Assetspec` (
  `spec_id` varchar(50) PRIMARY KEY NOT NULL comment '規格ID',
  `voltage` int comment '額定電壓(V)',
  `amperage` int comment '最大電流(A)',
  `model` varchar(50) NOT NULL comment '型號',
  `manufacturer_id` varchar(30) NOT NULL comment '製造商ID'
);

CREATE TABLE IF NOT EXISTS `Manufacturer` (
  `manufacturer_id` varchar(30) PRIMARY KEY NOT NULL comment '製造商ID',
  `name` varchar(50) comment '製造商名稱',
  `country` varchar(50) comment '註冊國家'
);

CREATE TABLE IF NOT EXISTS `Maintenancelog` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `maint_id` VARCHAR(50) NULL DEFAULT NULL,
  `technician_id` VARCHAR(10) NOT NULL,
  `asset_id` VARCHAR(10) NOT NULL,
  `action` TEXT,
  `details` TEXT,
  `maint_time` DATETIME,
  `status` ENUM('待處理','處理中','缺件','完成','已簽核') NOT NULL,
  `scheduled_time` DATETIME,
  `assigned_by` VARCHAR(10),
  `approved_by` VARCHAR(10),
  `approved_at` DATETIME,
  `work_hours` DECIMAL(5,2),
  `labor_cost` DECIMAL(10,2),
  `material_cost` DECIMAL(10,2),
  `total_cost` DECIMAL(10,2),

  PRIMARY KEY (id),
  UNIQUE KEY uq_maint_id (maint_id)
);

CREATE TABLE IF NOT EXISTS `Partsrequest` (
  `request_id` varchar(50) PRIMARY KEY NOT NULL comment '零件申請單ID',
  `maint_id` varchar(50) NOT NULL comment '維修紀錄ID',
  `technician_id` varchar(10) NOT NULL comment '技術員ID',
  `status` enum('待審', '批准', '駁回') NOT NULL comment '零件申請單狀態',
  `request_time` datetime NOT NULL comment '申請時間',
  `approved_by` varchar(10) comment '批核主管',
  `approved_at` datetime comment '審核時間',
  `reject_reason` text comment '駁回原因'
);

CREATE TABLE IF NOT EXISTS `Req_part` (
  `request_id` varchar(50) NOT NULL COMMENT '零件申請單ID',
  `part_id` varchar(50) NOT NULL comment '零件名稱',
  `req_qty` int NOT NULL comment '申請數量',
  `status` enum('待審', '批准', '駁回')NOT NULL comment '零件申請狀態',
  PRIMARY KEY (`request_id`, `part_id`)
);


CREATE TABLE IF NOT EXISTS `Maintenanceparts` (
  `part_id` varchar(50) NOT NULL COMMENT '零件ID',
  `maint_id` varchar(50) NOT NULL COMMENT '維修紀錄ID',
  `qty_used` int NOT NULL comment '使用數量',
  PRIMARY KEY (`part_id`, `maint_id`)
);

CREATE TABLE IF NOT EXISTS `Partspecs` (
  `part_id` varchar(50) PRIMARY KEY NOT NULL comment '零件ID',
  `part_name` varchar(50) comment '零件名稱',
  `stock` int comment '庫存',
  `safe_stock` int comment '安全庫存',
  `reorder_qty` int comment '建議補貨量',
  `last_check_time` datetime comment '最後盤點時間',
  `unit_cost` decimal(10,2) comment '單價',
  `provider` varchar(50) NOT NULL comment '供應商'
);

CREATE TABLE IF NOT EXISTS `Notification` (
  `notification_id` varchar(50) PRIMARY KEY NOT NULL comment '通知待辦編號',
  `receiver_id` varchar(10) NOT NULL comment '接收人員',
  `receiver_role` enum('Inspector', 'Technician','assetmanager','deptmanager') NOT NULL comment '接收角色',
  `source_type` varchar(50) NOT NULL comment '來源功能，如巡檢、維修、缺件等',
  `source_id` varchar(50) NOT NULL comment '來源資料編號',
  `title` varchar(100) NOT NULL comment '通知標題',
  `content` text comment '通知內容',
  `is_read` boolean NOT NULL DEFAULT FALSE comment '是否已讀',
  `created_at` datetime NOT NULL comment '建立時間',
  `read_at` datetime comment '讀取時間'
);

CREATE TABLE IF NOT EXISTS `Auditlog`(
  `audit_id` varchar(50) PRIMARY KEY NOT NULL comment '稽核紀錄編號',
  `employee_id` varchar(10) NOT NULL comment '操作人員',
  `function_name` varchar(100) NOT NULL comment '操作功能名稱',
  `action_type` enum('新增','修改','刪除','簽核','登入','匯入','匯出') NOT NULL comment '操作類型',
  `target_type` varchar(100) comment '異動資料類型',
  `target_id` varchar(100) comment '異動資料主鍵',
  `old_data` longtext comment '異動前內容(JSON)',
  `new_data` longtext comment '異動後內容(JSON)',
  `before_data` longtext comment '異動前內容(JSON，相容管理後台API)',
  `after_data` longtext comment '異動後內容(JSON，相容管理後台API)',
  `ip_address` varchar(45) comment '操作者IP',
  `result` varchar(20) NOT NULL DEFAULT '成功' comment '操作結果',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() comment '操作時間'
);

CREATE TABLE IF NOT EXISTS `Importbatch`(
  `batch_id` varchar(50) PRIMARY KEY NOT NULL comment '匯入批次編號',
  `file_name` varchar(255) NOT NULL comment '上傳檔名',
  `uploaded_by` varchar(10) NOT NULL comment '上傳人員',
  `total_count` int NOT NULL comment '總筆數',
  `success_count` int NOT NULL comment '成功筆數',
  `fail_count` int NOT NULL comment '失敗筆數',
  `status` enum('驗證中','成功','部分失敗','失敗') NOT NULL comment '匯入處理狀態',
  `created_at` datetime NOT NULL comment '匯入時間'
);

CREATE TABLE IF NOT EXISTS `Importerror`(
  `error_id` varchar(50) PRIMARY KEY NOT NULL comment '匯入錯誤編號',
  `batch_id` varchar(50) NOT NULL comment '所屬匯入批次',
  `row_no` int NOT NULL DEFAULT 0 comment 'CSV原始列號，相容管理後台API',
  `row_number` int comment 'CSV原始列號，相容後端匯入頁',
  `field_name` varchar(100) comment '錯誤欄位',
  `error_message` text comment '錯誤原因',
  `row_data` longtext comment '原始資料列(JSON)'
);


-- =========================
-- Indexes
-- =========================

-- Powerasset
CREATE INDEX IF NOT EXISTS `idx_powerasset_spec`       ON `Powerasset`      (`spec_id`);

-- Health
CREATE INDEX IF NOT EXISTS `idx_health_status_level`   ON `Health`          (`current_status`, `health_level`);

-- Employees
CREATE INDEX IF NOT EXISTS `idx_employees_account`      ON `Employees`       (`account`);
CREATE INDEX IF NOT EXISTS `idx_employees_role`            ON `Employees`       (`role`);

-- Inspectionlog
CREATE INDEX IF NOT EXISTS `idx_insplog_inspector`     ON `Inspectionlog`   (`inspector_id`);
CREATE INDEX IF NOT EXISTS `idx_insplog_time`          ON `Inspectionlog`   (`inspec_time`);
CREATE INDEX IF NOT EXISTS `idx_insplog_asset_time`    ON `Inspectionlog`   (`asset_id`, `inspec_time`);

-- Maintenancelog
CREATE INDEX IF NOT EXISTS `idx_maintlog_time`         ON `Maintenancelog`  (`maint_time`);
CREATE INDEX IF NOT EXISTS `idx_maintlog_asset_time`   ON `Maintenancelog`  (`asset_id`, `maint_time`);

-- Photos
CREATE INDEX IF NOT EXISTS `idx_photos_inspec`            ON `Photos`          (`inspec_id`);
CREATE INDEX IF NOT EXISTS `idx_photos_maint`            ON `Photos`          (`maint_id`);

-- Partspecs
CREATE INDEX IF NOT EXISTS `idx_partspecs_stock`       ON `Partspecs`       (`stock`);
CREATE INDEX IF NOT EXISTS `idx_partspecs_unit_cost`   ON `Partspecs`       (`unit_cost`);

-- Partsrequest
CREATE INDEX IF NOT EXISTS `idx_partsreq_maint`        ON `Partsrequest`    (`maint_id`);
CREATE INDEX IF NOT EXISTS `idx_partsreq_technician`   ON `Partsrequest`    (`technician_id`);


-- =========================
-- Foreign Keys
-- =========================

-- Powerasset
ALTER TABLE `Powerasset`
  ADD FOREIGN KEY (`spec_id`)   REFERENCES `Assetspec` (`spec_id`),
  ADD FOREIGN KEY (`sector_id`) REFERENCES `Sector` (`sector_id`);

-- Assetspec
ALTER TABLE `Assetspec`
  ADD FOREIGN KEY (`manufacturer_id`) REFERENCES `Manufacturer` (`manufacturer_id`);

-- Health
ALTER TABLE `Health`
  ADD FOREIGN KEY (`asset_id`) REFERENCES `Powerasset` (`asset_id`);

-- Inspectionlog
ALTER TABLE `Inspectionlog`
  ADD FOREIGN KEY (`asset_id`)     REFERENCES `Powerasset` (`asset_id`),
  ADD FOREIGN KEY (`inspector_id`) REFERENCES `Employees`  (`id_num`);

-- Maintenancelog
ALTER TABLE `Maintenancelog`
  ADD FOREIGN KEY (`asset_id`)      REFERENCES `Powerasset` (`asset_id`),
  ADD FOREIGN KEY (`technician_id`) REFERENCES `Employees` (`id_num`),
  ADD FOREIGN KEY (`assigned_by`)   REFERENCES `Employees` (`id_num`),
  ADD FOREIGN KEY (`approved_by`)   REFERENCES `Employees` (`id_num`);

-- Photos
ALTER TABLE `Photos`
  ADD FOREIGN KEY (`inspec_id`) REFERENCES `Inspectionlog` (`inspec_id`),
  ADD FOREIGN KEY (`maint_id`) REFERENCES `Maintenancelog` (`maint_id`),
  ADD FOREIGN KEY (`asset_id`) REFERENCES `Powerasset` (`asset_id`),
  ADD FOREIGN KEY (`uploaded_by`) REFERENCES `Employees` (`id_num`);

-- Maintenanceparts
ALTER TABLE `Maintenanceparts`
  ADD FOREIGN KEY (`maint_id`) REFERENCES `Maintenancelog` (`maint_id`),
  ADD FOREIGN KEY (`part_id`)  REFERENCES `Partspecs`      (`part_id`);

-- Partsrequest
ALTER TABLE `Partsrequest`
  ADD FOREIGN KEY (`maint_id`)      REFERENCES `Maintenancelog` (`maint_id`),
  ADD FOREIGN KEY (`technician_id`) REFERENCES `Employees`     (`id_num`),
  ADD FOREIGN KEY (`approved_by`) REFERENCES `Employees`     (`id_num`);

-- Req_part
ALTER TABLE `Req_part`
  ADD FOREIGN KEY (`request_id`) REFERENCES `Partsrequest` (`request_id`),
  ADD FOREIGN KEY (`part_id`)    REFERENCES `Partspecs`    (`part_id`);

-- Notification
ALTER TABLE `Notification`
  ADD FOREIGN KEY (`receiver_id`) REFERENCES `Employees` (`id_num`);

-- Auditlog
ALTER TABLE `Auditlog`
  ADD FOREIGN KEY (`employee_id`) REFERENCES `Employees` (`id_num`);

-- Importbatch
ALTER TABLE `Importbatch`
  ADD FOREIGN KEY (`uploaded_by`) REFERENCES `Employees` (`id_num`);

-- Importerror
ALTER TABLE `Importerror`
  ADD FOREIGN KEY (`batch_id`) REFERENCES `Importbatch` (`batch_id`);