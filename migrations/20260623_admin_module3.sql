USE `csieDBTeam09`;

ALTER TABLE `Auditlog`
  ADD COLUMN IF NOT EXISTS `target_id` varchar(50) NULL AFTER `action_type`,
  ADD COLUMN IF NOT EXISTS `before_data` text NULL AFTER `target_id`,
  ADD COLUMN IF NOT EXISTS `after_data` text NULL AFTER `before_data`,
  ADD COLUMN IF NOT EXISTS `ip_address` varchar(45) NULL AFTER `after_data`,
  ADD COLUMN IF NOT EXISTS `created_at` datetime NOT NULL DEFAULT current_timestamp() AFTER `ip_address`;

ALTER TABLE `Importerror`
  ADD COLUMN IF NOT EXISTS `row_no` int NOT NULL DEFAULT 0 AFTER `batch_id`,
  ADD COLUMN IF NOT EXISTS `field_name` varchar(50) NULL AFTER `row_no`,
  ADD COLUMN IF NOT EXISTS `error_message` text NOT NULL AFTER `field_name`;
