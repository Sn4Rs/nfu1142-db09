USE `db09`;

-- 1. Manufacturer
INSERT INTO `Manufacturer` (`manufacturer_id`, `name`, `country`) VALUES
('MF001', 'Siemens Energy', 'Germany'),
('MF002', 'General Electric', 'USA'),
('MF003', 'ABB Ltd', 'Switzerland');

-- 2. AssetSpec
INSERT INTO `Assetspec` (`spec_id`, `voltage`, `amperage`, `model`, `manufacturer_id`) VALUES
('SPEC-TR-01', 11000, 500, 'Transformer-X1', 'MF001'),
('SPEC-PL-01', 220, 100, 'Steel-Pole-S1', 'MF002'),
('SPEC-CB-01', 33000, 1000, 'Underground-C3', 'MF003');

-- 3. Sector
INSERT INTO `Sector` (`sector_id`, `feeder_area`, `street`, `city`, `postal_code`, `country`) VALUES
('SEC-NORTH-01', 'A1', 'Main St', 'Taipei', '100', 'Taiwan'),
('SEC-SOUTH-02', 'B3', 'Station Rd', 'Kaohsiung', '800', 'Taiwan');

-- 4. PowerAsset
INSERT INTO `Powerasset` (`asset_id`, `sector_id`, `type`, `spec_id`, `gps`) VALUES
('ASSET001', 'SEC-NORTH-01', '電塔', 'SPEC-TR-01', POINT(25.0330, 121.5654)),
('ASSET002', 'SEC-NORTH-01', '電線桿', 'SPEC-PL-01', POINT(25.0400, 121.5700)),
('ASSET003', 'SEC-SOUTH-02', '電塔', 'SPEC-CB-01', POINT(22.6273, 120.3014)),
('ASSET004', 'SEC-NORTH-01', '電塔', 'SPEC-TR-01', POINT(24.0000, 121.0000));

-- 5. Inspector
INSERT INTO `Inspector` (`inspector_id`, `name`) VALUES
('INS-01', 'John Doe'),
('INS-02', 'Jane Smith');

-- 6. Health (History)
INSERT INTO `Health` (`asset_id`, `install_date`, `expected_lifespan`, `last_inspection_date`, `health_level`, `current_status`, `valid_from`, `valid_to`) VALUES
('ASSET001', '2020-01-01', 25, '2023-12-01', '優', '運作中', '2020-01-01 00:00:00', NULL),
('ASSET002', '2015-05-20', 15, '2024-01-15', '良', '運作中', '2015-05-20 00:00:00', NULL),
('ASSET003', '2010-10-10', 10, '2024-01-20', '危險', '維修中', '2010-10-10 00:00:00', '2025-01-01'),
('ASSET004', '2023-01-01', 20, '2024-01-01', '優', '運作中', '2023-01-01 00:00:00', NULL);

-- 7. InspectionLog
INSERT INTO `Inspectionlog` (`log_id`, `asset_id`, `inspector_id`, `observation`, `risk_score`, `photo_url`, `inspec_time`) VALUES
('LOG-001', 'ASSET001', 'INS-01', 'Everything looks stable.', 5, 'http://example.com/photo1.jpg', '2023-12-01 10:00:00'),
('LOG-002', 'ASSET002', 'INS-02', 'Minor rust detected on base.', 25, 'http://example.com/photo2.jpg', '2024-01-15 14:30:00');

-- 8. PartSpecs
INSERT INTO `Partspecs` (`part_id`, `part_name`, `stock`, `unit_cost`, `provider`) VALUES
('PART-INS-01', 'Insulator-A', 50, 150.00, 'Local Provider A'),
('PART-BOLT-02', 'Steel Bolt M12', 500, 2.50, 'Hardware Inc');
