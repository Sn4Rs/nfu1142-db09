-- Merge backend manager login into one shared admin account.
-- Reassign historical references from E006, then remove the redundant employee row.
UPDATE `Inspectionlog`
SET `inspector_id` = 'E005'
WHERE `inspector_id` = 'E006';

UPDATE `Maintenancelog`
SET `approved_by` = 'E005'
WHERE `approved_by` = 'E006';

UPDATE `Partsrequest`
SET `approved_by` = 'E005'
WHERE `approved_by` = 'E006';

UPDATE `Notification`
SET `receiver_id` = 'E005'
WHERE `receiver_id` = 'E006';

UPDATE `Auditlog`
SET `employee_id` = 'E005'
WHERE `employee_id` = 'E006';

DELETE FROM `Employees`
WHERE `id_num` = 'E006';