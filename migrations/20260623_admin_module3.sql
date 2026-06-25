USE `csieDBTeam09`;

ALTER TABLE `Auditlog`
  MODIFY COLUMN `action_type` enum('新增','修改','刪除','簽核','登入','匯入','匯出') NOT NULL,
  ADD COLUMN IF NOT EXISTS `target_type` varchar(100) NULL AFTER `action_type`,
  ADD COLUMN IF NOT EXISTS `target_id` varchar(100) NULL AFTER `target_type`,
  ADD COLUMN IF NOT EXISTS `old_data` longtext NULL AFTER `target_id`,
  ADD COLUMN IF NOT EXISTS `new_data` longtext NULL AFTER `old_data`,
  ADD COLUMN IF NOT EXISTS `before_data` longtext NULL AFTER `new_data`,
  ADD COLUMN IF NOT EXISTS `after_data` longtext NULL AFTER `before_data`,
  ADD COLUMN IF NOT EXISTS `ip_address` varchar(45) NULL AFTER `after_data`,
  ADD COLUMN IF NOT EXISTS `result` varchar(20) NOT NULL DEFAULT '成功' AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `created_at` datetime NOT NULL DEFAULT current_timestamp() AFTER `result`;

ALTER TABLE `Importerror`
  ADD COLUMN IF NOT EXISTS `row_no` int NOT NULL DEFAULT 0 AFTER `batch_id`,
  ADD COLUMN IF NOT EXISTS `row_number` int NULL AFTER `row_no`,
  ADD COLUMN IF NOT EXISTS `field_name` varchar(100) NULL AFTER `row_number`,
  ADD COLUMN IF NOT EXISTS `error_message` text NULL AFTER `field_name`,
  ADD COLUMN IF NOT EXISTS `row_data` longtext NULL AFTER `error_message`;