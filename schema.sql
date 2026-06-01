-- run mysql -u root -p db09 < schema.sql

CREATE DATABASE IF NOT EXISTS `db09`;
USE `db09`;

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

CREATE TABLE IF NOT EXISTS `Employees`{
  `id_num` varchar(10) PRIMARY KEY NOT NULL comment '員工ID',
  `fullname` varchar(100) NOT NULL comment '姓名',
  `role` enum('Inspector', 'Technician','assetmanager','deptmanager') NOT NULL comment '身分組',
  `userhandle` varchar(50) NOT NULL comment '帳號',
  `userpwd` varchar(255) NOT NULL comment '密碼'
}

CREATE TABLE IF NOT EXISTS `Inspectionlog` (
  `log_id` varchar(50) PRIMARY KEY NOT NULL comment '檢查紀錄ID',
  `asset_id` varchar(10) NOT NULL comment '資產ID',
  `inspector_id` varchar(10) NOT NULL comment '檢查員ID',
  `observation` text comment '觀察結果',
  `risk_score` int comment '風險分數' CHECK (`risk_score` BETWEEN 0 AND 100),
  `photo_url` varchar(255) NOT NULL comment '照片網址',
  `inspec_time` datetime NOT NULL comment '檢查時間'
) COMMENT = 'risk_score range: 0–100';

CREATE TABLE IF NOT EXISTS `Photos`(
  `photo_id` varchar(50) PRIMARY KEY NOT NULL comment '照片ID',
  `log_id` varchar(50) NOT NULL comment '檢查紀錄ID',
  `url` varchar(255) NOT NULL comment '照片網址',
  FOREIGN KEY (`log_id`) REFERENCES `Inspectionlog` (`log_id`)
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
  `maint_id` varchar(50) PRIMARY KEY NOT NULL comment '維修紀錄ID',
  `technician_id` varchar(10) NOT NULL comment '技術員ID',
  `asset_id` varchar(10) NOT NULL comment '資產ID',
  `action` text comment '維修動作',
  `cost` decimal(10,2) comment '維修成本',
  `details` text comment '維修細節',
  `maint_time` datetime comment '維修時間'
);

CREATE TABLE IF NOT EXISTS `Partsrequest` (
  `request_id` varchar(50) PRIMARY KEY NOT NULL comment '零件申請單ID',
  `maint_id` varchar(50) NOT NULL comment '維修紀錄ID',
  `technician_id` varchar(10) NOT NULL comment '技術員ID',
  `status` enum('待審', '批准', '拒絕') NOT NULL comment '零件申請單狀態',
  `request_time` datetime NOT NULL comment '申請時間'
);

CREATE TABLE IF NOT EXISTS `Req_part` (
  `request_id` varchar(50) NOT NULL COMMENT '零件申請單ID',
  `part_id` varchar(50) NOT NULL comment '零件名稱',
  `req_qty` int NOT NULL comment '申請數量',
  `status` enum('待審', '批准', '拒絕')NOT NULL comment '零件申請狀態'
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
  `unit_cost` decimal(10,2) comment '單價',
  `provider` varchar(50) NOT NULL comment '供應商'
);


-- =========================
-- Indexes
-- =========================

-- Powerasset
CREATE INDEX IF NOT EXISTS `idx_powerasset_spec`       ON `Powerasset`      (`spec_id`);

-- Health
CREATE INDEX IF NOT EXISTS `idx_health_status_level`   ON `Health`          (`current_status`, `health_level`);

-- Employees
CREATE INDEX IF NOT EXISTS `idx_employees_userhandle`      ON `Employees`       (`userhandle`,'userpwd');
CREATE INDEX IF NOT EXISTS `idx_employees_role`            ON `Employees`       (`role`);

-- Inspectionlog
CREATE INDEX IF NOT EXISTS `idx_insplog_inspector`     ON `Inspectionlog`   (`inspector_id`);
CREATE INDEX IF NOT EXISTS `idx_insplog_time`          ON `Inspectionlog`   (`inspec_time`);
CREATE INDEX IF NOT EXISTS `idx_insplog_asset_time`    ON `Inspectionlog`   (`asset_id`, `inspec_time`);

-- Maintenancelog
CREATE INDEX IF NOT EXISTS `idx_maintlog_time`         ON `Maintenancelog`  (`maint_time`);
CREATE INDEX IF NOT EXISTS `idx_maintlog_asset_time`   ON `Maintenancelog`  (`asset_id`, `maint_time`);

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
  ADD FOREIGN KEY (`technician_id`) REFERENCES `Employees` (`id_num`);

-- Maintenanceparts
ALTER TABLE `Maintenanceparts`
  ADD FOREIGN KEY (`maint_id`) REFERENCES `Maintenancelog` (`maint_id`),
  ADD FOREIGN KEY (`part_id`)  REFERENCES `Partspecs`      (`part_id`);

-- Partsrequest
ALTER TABLE `Partsrequest`
  ADD FOREIGN KEY (`maint_id`)      REFERENCES `Maintenancelog` (`maint_id`),
  ADD FOREIGN KEY (`technician_id`) REFERENCES `Employees`     (`id_num`);

-- Req_part
ALTER TABLE `Req_part`
  ADD FOREIGN KEY (`request_id`) REFERENCES `Partsrequest` (`request_id`),
  ADD FOREIGN KEY (`part_id`)    REFERENCES `Partspecs`    (`part_id`);