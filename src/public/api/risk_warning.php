<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_common.php';

function high_risk_assets(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            p.asset_id,
            p.type,
            p.sector_id,
            s.feeder_area,
            h.install_date,
            h.expected_lifespan,
            h.health_level,
            h.current_status,
            i.risk_score,
            i.observation,
            i.inspec_time,
            TIMESTAMPDIFF(YEAR, h.install_date, CURDATE()) AS asset_age_years,
            CASE
                WHEN h.expected_lifespan IS NULL OR h.expected_lifespan = 0 THEN NULL
                ELSE ROUND(TIMESTAMPDIFF(YEAR, h.install_date, CURDATE()) / h.expected_lifespan * 100, 1)
            END AS lifespan_used_percent
         FROM Powerasset p
         LEFT JOIN Sector s ON s.sector_id = p.sector_id
         LEFT JOIN Health h ON h.asset_id = p.asset_id
           AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
         LEFT JOIN Inspectionlog i ON i.asset_id = p.asset_id
           AND i.inspec_time = (SELECT MAX(i2.inspec_time) FROM Inspectionlog i2 WHERE i2.asset_id = p.asset_id)
         WHERE i.risk_score >= 75 OR h.health_level IN ("危險", "待修")
         ORDER BY COALESCE(i.risk_score, 0) DESC, h.health_level DESC, p.asset_id ASC'
    );
    return $stmt->fetchAll();
}

function risk_trend(PDO $pdo, string $assetId): array
{
    $stmt = $pdo->prepare(
        'SELECT inspec_id, risk_score, observation, inspec_time
         FROM Inspectionlog
         WHERE asset_id = :asset_id
         ORDER BY inspec_time ASC'
    );
    $stmt->execute(['asset_id' => $assetId]);
    return $stmt->fetchAll();
}

function dispatch_maintenance(PDO $pdo, array $body): void
{
    $assetId = trim((string)($body['asset_id'] ?? ''));
    $technicianId = trim((string)($body['technician_id'] ?? ''));
    if ($assetId === '') json_response(['ok' => false, 'errors' => ['asset_id' => ['必填欄位不可為空']]], 422);

    $assetStmt = $pdo->prepare('SELECT asset_id, type FROM Powerasset WHERE asset_id = :asset_id');
    $assetStmt->execute(['asset_id' => $assetId]);
    $asset = $assetStmt->fetch();
    if (!$asset) json_response(['ok' => false, 'error' => '查無資產'], 404);

    if ($technicianId === '') {
        $tech = first_employee_by_role($pdo, 'Technician');
        if (!$tech) json_response(['ok' => false, 'error' => '沒有可指派的維修人員'], 422);
        $technicianId = $tech['id_num'];
    } else {
        $techStmt = $pdo->prepare('SELECT id_num, fullname, role FROM Employees WHERE id_num = :id AND role = "Technician" AND status = "active"');
        $techStmt->execute(['id' => $technicianId]);
        $tech = $techStmt->fetch();
        if (!$tech) json_response(['ok' => false, 'errors' => ['technician_id' => ['查無啟用中的維修人員']]], 422);
    }

    $maintId = generate_id('MT');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Maintenancelog
            (maint_id, technician_id, asset_id, action, details, maint_time, status, scheduled_time, assigned_by, approved_by, approved_at, work_hours, labor_cost, material_cost, total_cost)
            VALUES
            (:maint_id, :technician_id, :asset_id, "AI預警派工", :details, NULL, "待處理", NOW(), :assigned_by, NULL, NULL, NULL, NULL, NULL, NULL)'
        );
        $stmt->execute([
            'maint_id' => $maintId,
            'technician_id' => $technicianId,
            'asset_id' => $assetId,
            'details' => '智慧預警中心自動建立維修工單，請檢查高風險資產。',
            'assigned_by' => current_employee_id(),
        ]);

        create_notification(
            $pdo,
            $technicianId,
            'Technician',
            'AI預警派工',
            $maintId,
            '新維修工單：' . $assetId,
            '系統依高風險預警建立維修工單 ' . $maintId . '，請前往維修待辦處理。'
        );

        write_audit($pdo, '智慧預警與汰換預測中心', '新增', $maintId, null, [
            'maint_id' => $maintId,
            'asset_id' => $assetId,
            'technician_id' => $technicianId,
            'status' => '待處理',
        ]);

        $pdo->commit();
        json_response(['ok' => true, 'data' => ['maint_id' => $maintId, 'asset_id' => $assetId, 'technician_id' => $technicianId]], 201);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => '派發工單失敗', 'detail' => $e->getMessage()], 500);
    }
}

try {
    require_admin_role();
    $pdo = api_db();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $assetId = trim((string)($_GET['asset_id'] ?? ''));
        json_response([
            'ok' => true,
            'data' => [
                'assets' => high_risk_assets($pdo),
                'trend' => $assetId === '' ? [] : risk_trend($pdo, $assetId),
            ],
        ]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        dispatch_maintenance($pdo, read_json_body());
    }
    json_response(['ok' => false, 'error' => '不支援的 HTTP method'], 405);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => '系統錯誤', 'detail' => $e->getMessage()], 500);
}