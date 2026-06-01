USE `db09`;

-- 1. Manufacturer (10 rows)
INSERT INTO `Manufacturer` (`manufacturer_id`, `name`, `country`) VALUES
('MF001', 'Siemens Energy', 'Germany'),
('MF002', 'General Electric', 'USA'),
('MF003', 'ABB Ltd', 'Switzerland'),
('MF004', 'Toshiba Energy', 'Japan'),
('MF005', 'Hitachi', 'Japan'),
('MF006', 'Schneider Electric', 'France'),
('MF007', 'Eaton', 'USA'),
('MF008', 'Mitsubishi Electric', 'Japan'),
('MF009', 'Hyosung', 'South Korea'),
('MF010', 'Local Supplier A', 'Taiwan');

-- 2. AssetSpec (10 rows)
INSERT INTO `Assetspec` (`spec_id`, `voltage`, `amperage`, `model`, `manufacturer_id`) VALUES
('SPEC-TR-01', 11000, 500, 'Transformer-X1', 'MF001'),
('SPEC-TR-02', 11000, 600, 'Transformer-X2', 'MF002'),
('SPEC-PL-01', 220, 100, 'Steel-Pole-S1', 'MF003'),
('SPEC-PL-02', 220, 150, 'Steel-Pole-S2', 'MF004'),
('SPEC-CB-01', 33000, 1000, 'Underground-C3', 'MF005'),
('SPEC-CB-02', 33000, 1200, 'Underground-C4', 'MF006'),
('SPEC-TR-03', 11000, 450, 'Transformer-X3', 'MF007'),
('SPEC-PL-03', 220, 80, 'Wood-Pole-W1', 'MF008'),
('SPEC-TR-04', 6600, 300, 'Transformer-Y1', 'MF009'),
('SPEC-PL-04', 220, 200, 'Concrete-Pole-C1', 'MF010');

-- 3. Sector (10 rows)
INSERT INTO `Sector` (`sector_id`, `feeder_area`, `street`, `city`, `postal_code`, `country`) VALUES
('SEC-01', 'A1', 'Main St', 'Taipei', '100', 'Taiwan'),
('SEC-02', 'A2', 'First Ave', 'Taipei', '103', 'Taiwan'),
('SEC-03', 'B1', 'Second St', 'New Taipei', '207', 'Taiwan'),
('SEC-04', 'B2', 'Market Rd', 'Taichung', '400', 'Taiwan'),
('SEC-05', 'C1', 'River Rd', 'Kaohsiung', '800', 'Taiwan'),
('SEC-06', 'C2', 'Harbor Blvd', 'Kaohsiung', '805', 'Taiwan'),
('SEC-07', 'D1', 'Station Rd', 'Tainan', '700', 'Taiwan'),
('SEC-08', 'D2', 'Hill St', 'Hsinchu', '300', 'Taiwan'),
('SEC-09', 'E1', 'Garden Ave', 'Taoyuan', '330', 'Taiwan'),
('SEC-10', 'E2', 'Coast Rd', 'Yilan', '260', 'Taiwan');

-- 4. PowerAsset (10 rows)
INSERT INTO `Powerasset` (`asset_id`, `sector_id`, `type`, `spec_id`, `gps`) VALUES
('ASSET001', 'SEC-01', '電塔', 'SPEC-TR-01', POINT(25.0330, 121.5654)),
('ASSET002', 'SEC-01', '電線桿', 'SPEC-PL-01', POINT(25.0400, 121.5700)),
('ASSET003', 'SEC-02', '電塔', 'SPEC-TR-02', POINT(25.0470, 121.5170)),
('ASSET004', 'SEC-03', '電塔', 'SPEC-TR-03', POINT(24.1500, 120.6510)),
('ASSET005', 'SEC-04', '電線桿', 'SPEC-PL-02', POINT(24.1370, 120.6860)),
('ASSET006', 'SEC-05', '電塔', 'SPEC-CB-01', POINT(22.6273, 120.3014)),
('ASSET007', 'SEC-06', '電線桿', 'SPEC-PL-03', POINT(22.6310, 120.2820)),
('ASSET008', 'SEC-07', '電塔', 'SPEC-TR-04', POINT(23.0000, 120.2000)),
('ASSET009', 'SEC-08', '電線桿', 'SPEC-PL-04', POINT(24.8036, 120.9686)),
('ASSET010', 'SEC-09', '電塔', 'SPEC-TR-04', POINT(25.0000, 121.5000));

-- 5. Employees (10 rows) — include inspectors and technicians
-- inspector account/pwd: wangxm/password1, chendw/password2, lixh/password3, zhangml/password4, zhouzy/password7, caiwh/password9
-- technician account/pwd: chendw/password2, zhangml/password4, wups/password8, zhengkx/password10
-- assetmanager account/pwd: liuzq/password5
-- deptmanager account/pwd: heyt/password6
INSERT INTO `Employees` (`id_num`, `fullname`, `role`, `account`, `userpwd`, `pwdhash`, `status`, `email`, `created_at`, `updated_at`) VALUES
('E001', '王小明', 'Inspector', 'wangxm', 'password1', '', 'active', 'wangxm@example.com', '2023-01-01 08:00:00', NULL),
('E002', '陳大文', 'Technician', 'chendw', 'password2', '', 'active', 'chendw@example.com', '2023-02-01 08:00:00', NULL),
('E003', '李小華', 'Inspector', 'lixh', 'password3', '', 'active', 'lixh@example.com', '2023-03-01 08:00:00', NULL),
('E004', '張美麗', 'Technician', 'zhangml', 'password4', '', 'active', 'zhangml@example.com', '2023-04-01 08:00:00', NULL),
('E005', '劉志強', 'assetmanager', 'liuzq', 'password5', '', 'active', 'liuzq@example.com', '2023-05-01 08:00:00', NULL),
('E006', '何雅婷', 'deptmanager', 'heyt', 'password6', '', 'active', 'heyt@example.com', '2023-06-01 08:00:00', NULL),
('E007', '周正義', 'Inspector', 'zhouzy', 'password7', '', 'active', 'zhouzy@example.com', '2023-07-01 08:00:00', NULL),
('E008', '吳佩珊', 'Technician', 'wups', 'password8', '', 'active', 'wups@example.com', '2023-08-01 08:00:00', NULL),
('E009', '蔡文豪', 'Inspector', 'caiwh', 'password9', '', 'active', 'caiwh@example.com', '2023-09-01 08:00:00', NULL),
('E010', '鄭可心', 'Technician', 'zhengkx', 'password10', '', 'active', 'zhengkx@example.com', '2023-10-01 08:00:00', NULL);

-- 6. Health (10 rows) — one per asset
INSERT INTO `Health` (`asset_id`, `install_date`, `expected_lifespan`, `last_inspection_date`, `health_level`, `current_status`, `valid_from`, `valid_to`) VALUES
('ASSET001', '2018-01-01', 25, '2026-01-10', '良', '運作中', '2018-01-01 00:00:00', NULL),
('ASSET002', '2016-05-20', 15, '2026-02-15', '良', '運作中', '2016-05-20 00:00:00', NULL),
('ASSET003', '2012-03-10', 20, '2026-01-20', '待修', '維修中', '2012-03-10 00:00:00', NULL),
('ASSET004', '2010-07-01', 15, '2026-01-05', '危險', '維修中', '2010-07-01 00:00:00', '2026-07-01'),
('ASSET005', '2019-11-11', 20, '2026-03-01', '良', '運作中', '2019-11-11 00:00:00', NULL),
('ASSET006', '2009-02-20', 12, '2026-02-28', '危險', '維修中', '2009-02-20 00:00:00', '2021-02-20'),
('ASSET007', '2020-06-15', 25, '2026-03-05', '優', '運作中', '2020-06-15 00:00:00', NULL),
('ASSET008', '2011-09-30', 18, '2026-01-18', '待修', '維修中', '2011-09-30 00:00:00', NULL),
('ASSET009', '2017-04-04', 20, '2026-02-20', '良', '運作中', '2017-04-04 00:00:00', NULL),
('ASSET010', '2015-12-12', 15, '2026-01-12', '待修', '運作中', '2015-12-12 00:00:00', NULL);

-- 7. InspectionLog (10 rows)
INSERT INTO `Inspectionlog` (`inspec_id`, `asset_id`, `inspector_id`, `observation`, `risk_score`, `inspec_time`) VALUES
('IL001', 'ASSET001', 'E001', '外觀良好。', 5, '2026-01-10 09:00:00'),
('IL002', 'ASSET002', 'E002', '基座有鏽蝕，需留意。', 35, '2026-02-15 10:30:00'),
('IL003', 'ASSET003', 'E003', '絕緣子破損，需更換。', 60, '2026-01-20 14:00:00'),
('IL004', 'ASSET004', 'E004', '結構受損，立即維修。', 85, '2026-01-05 08:30:00'),
('IL005', 'ASSET005', 'E005', '木材劣化，建議更換。', 45, '2026-03-01 11:00:00'),
('IL006', 'ASSET006', 'E006', '接地不良，需處理。', 70, '2026-02-28 13:15:00'),
('IL007', 'ASSET007', 'E007', '一切正常。', 10, '2026-03-05 09:45:00'),
('IL008', 'ASSET008', 'E008', '線夾鬆脫，需緊固。', 55, '2026-01-18 15:20:00'),
('IL009', 'ASSET009', 'E009', '基礎沉降，注意監測。', 40, '2026-02-20 16:00:00'),
('IL010', 'ASSET010', 'E010', '例行檢查無異常。', 8, '2026-01-12 10:10:00');

-- 8. PartSpecs (10 rows)
INSERT INTO `Partspecs` (`part_id`, `part_name`, `stock`, `safe_stock`, `reorder_qty`, `last_check_time`, `unit_cost`, `provider`) VALUES
('PART001', 'Insulator-A', 120, 20, 50, '2026-01-01 08:00:00', 120.00, 'Local Supplier A'),
('PART002', 'Steel Bolt M12', 1000, 200, 500, '2026-02-01 08:00:00', 2.50, 'Hardware Inc'),
('PART003', 'Fuse-5A', 500, 50, 200, '2026-02-15 08:00:00', 1.20, 'Electrical Co'),
('PART004', 'Anchor Kit', 60, 10, 30, '2026-01-20 08:00:00', 45.00, 'Fasteners Ltd'),
('PART005', 'Cable-10mm', 300, 50, 150, '2026-03-01 08:00:00', 10.00, 'CableWorks'),
('PART006', 'Clamp-HighVolt', 80, 10, 40, '2026-02-05 08:00:00', 25.00, 'Clamps Co'),
('PART007', 'Transformer-Oil', 40, 5, 20, '2026-01-30 08:00:00', 250.00, 'OilSuppliers'),
('PART008', 'Protective Cover', 150, 30, 80, '2026-02-10 08:00:00', 15.00, 'Protect Inc'),
('PART009', 'Insulation Tape', 800, 200, 400, '2026-03-05 08:00:00', 0.80, 'TapeWorks'),
('PART010', 'Grounding Rod', 90, 10, 40, '2026-01-25 08:00:00', 12.50, 'GroundTech');
