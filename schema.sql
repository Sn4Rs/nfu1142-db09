-- run mysql -u root -p db09 < schema.sql

CREATE DATABASE IF NOT EXISTS `db09`;
USE `db09`;

CREATE TABLE IF NOT EXISTS `PowerAsset` (
  `asset_id` varchar(10) PRIMARY KEY NOT NULL,
  `sector_id` varchar(50) NOT NULL comment '區域id',
  `type` varchar(20) comment '電塔,電線桿',
  `spec_id` varchar(50) NOT NULL comment '規格id',
  `gps` point comment '地理位置'
);

CREATE TABLE IF NOT EXISTS `Health` (
  `asset_id` varchar(10) NOT NULL,
  `install_date` date comment '安裝日期',
  `expected_lifespan` int comment '預計壽命',
  `last_inspection_date` date comment '上次檢查日期',
  `health_level` enum('優', '良', '待修', '危險') comment '健康程度',
  `current_status` enum('運作中', '維修中', '報廢') comment '目前狀態',
  `valid_from` datetime NOT NULL comment 'Record start date',
  `valid_to` datetime comment 'Estimated end of life (valid_from + expected_lifespan)',
  PRIMARY KEY (`asset_id`, `valid_from`)
) COMMENT = 'valid_to is defined as valid_from + expected lifespan';

CREATE TABLE IF NOT EXISTS `Sector` (
  `sector_id` varchar(50) PRIMARY KEY NOT NULL,
  `feeder_area` varchar(10) comment '饋線區域',
  `street` varchar(100) comment '街道',
  `city` varchar(50) comment '城市',
  `postal_code` varchar(10) comment '郵遞區號',
  `country` varchar(50) comment '國家'
);

CREATE TABLE IF NOT EXISTS `Inspector` (
  `inspector_id` varchar(10) PRIMARY KEY NOT NULL,
  `name` varchar(50) comment '檢查員姓名'
);

CREATE TABLE IF NOT EXISTS `InspectionLog` (
  `log_id` varchar(50) PRIMARY KEY NOT NULL,
  `asset_id` varchar(10) NOT NULL,
  `inspector_id` varchar(10) NOT NULL,
  `observation` text comment '觀察結果',
  `risk_score` int comment '風險分數' CHECK (`risk_score` BETWEEN 0 AND 100),
  `photo_url` varchar(255) comment '照片網址',
  `inspec_time` datetime comment '檢查時間'
) COMMENT = 'risk_score range: 0–100';

CREATE TABLE IF NOT EXISTS `AssetSpec` (
  `spec_id` varchar(50) PRIMARY KEY NOT NULL,
  `voltage` int comment '電壓',
  `capacity` int comment '容量',
  `model` varchar(50) comment '型號',
  `manufacturer_id` varchar(30) NOT NULL
);

CREATE TABLE IF NOT EXISTS `Manufacturer` (
  `manufacturer_id` varchar(30) PRIMARY KEY NOT NULL,
  `name` varchar(50) comment '製造商名稱',
  `country` varchar(50) comment '國家'
);

CREATE TABLE IF NOT EXISTS `MaintenanceLog` (
  `maint_id` varchar(50) PRIMARY KEY NOT NULL,
  `asset_id` varchar(10) NOT NULL,
  `action` text comment '維修動作',
  `cost` decimal(10,2) comment '維修成本',
  `details` text comment '維修細節',
  `maint_time` datetime comment '維修時間'
);

CREATE TABLE IF NOT EXISTS `MaintenanceParts` (
  `part_id` varchar(50) NOT NULL,
  `maint_id` varchar(50) NOT NULL,
  `qty_used` int NOT NULL comment '使用數量',
  PRIMARY KEY (`part_id`, `maint_id`)
);

CREATE TABLE IF NOT EXISTS `PartSpecs` (
  `part_id` varchar(50) PRIMARY KEY NOT NULL,
  `part_name` varchar(50) comment '零件名稱',
  `stock` int comment '庫存',
  `unit_cost` decimal(10,2) comment '單價',
  `provider` varchar(50) NOT NULL comment '供應商'
);

CREATE INDEX IF NOT EXISTS `PowerAsset_index_0` ON `PowerAsset` (`spec_id`);

CREATE INDEX IF NOT EXISTS `Health_index_1` ON `Health` (`current_status`, `health_level`);

CREATE INDEX IF NOT EXISTS `InspectionLog_index_2` ON `InspectionLog` (`inspector_id`);

CREATE INDEX IF NOT EXISTS `InspectionLog_index_3` ON `InspectionLog` (`inspec_time`);

CREATE INDEX IF NOT EXISTS `InspectionLog_index_4` ON `InspectionLog` (`asset_id`, `inspec_time`);

CREATE INDEX IF NOT EXISTS `MaintenanceLog_index_5` ON `MaintenanceLog` (`maint_time`);

CREATE INDEX IF NOT EXISTS `MaintenanceLog_index_6` ON `MaintenanceLog` (`asset_id`, `maint_time`);

CREATE INDEX IF NOT EXISTS `PartSpecs_index_7` ON `PartSpecs` (`stock`);

CREATE INDEX IF NOT EXISTS `PartSpecs_index_8` ON `PartSpecs` (`unit_cost`);



ALTER TABLE `PowerAsset` ADD FOREIGN KEY (`spec_id`) REFERENCES `AssetSpec` (`spec_id`);

ALTER TABLE `AssetSpec` ADD FOREIGN KEY (`manufacturer_id`) REFERENCES `Manufacturer` (`manufacturer_id`);

ALTER TABLE `PowerAsset` ADD FOREIGN KEY (`sector_id`) REFERENCES `Sector` (`sector_id`);

ALTER TABLE `Health` ADD FOREIGN KEY (`asset_id`) REFERENCES `PowerAsset` (`asset_id`);

ALTER TABLE `InspectionLog` ADD FOREIGN KEY (`asset_id`) REFERENCES `PowerAsset` (`asset_id`);

ALTER TABLE `InspectionLog` ADD FOREIGN KEY (`inspector_id`) REFERENCES `Inspector` (`inspector_id`);

ALTER TABLE `MaintenanceLog` ADD FOREIGN KEY (`asset_id`) REFERENCES `PowerAsset` (`asset_id`);

ALTER TABLE `MaintenanceParts` ADD FOREIGN KEY (`maint_id`) REFERENCES `MaintenanceLog` (`maint_id`);

ALTER TABLE `MaintenanceParts` ADD FOREIGN KEY (`part_id`) REFERENCES `PartSpecs` (`part_id`);
