<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_common.php';

function get_filter_options(PDO $pdo): array
{
    $sectors = $pdo->query(
        'SELECT DISTINCT feeder_area
         FROM Sector
         WHERE feeder_area IS NOT NULL AND feeder_area != ""
         ORDER BY feeder_area'
    )->fetchAll(PDO::FETCH_COLUMN);

    return [
        'feeder_areas' => $sectors,
        'health_levels' => ['優', '良', '待修', '危險'],
    ];
}

function list_map_assets(PDO $pdo): array
{
    $feederArea = trim((string)($_GET['feeder_area'] ?? ''));
    $healthLevel = trim((string)($_GET['health_level'] ?? ''));
    $keyword = trim((string)($_GET['q'] ?? ''));

    $where = ['p.gps IS NOT NULL'];
    $params = [];

    if ($feederArea !== '') {
        $where[] = 's.feeder_area = :feeder_area';
        $params['feeder_area'] = $feederArea;
    }

    if ($healthLevel !== '') {
        $where[] = 'h.health_level = :health_level';
        $params['health_level'] = $healthLevel;
    }

    if ($keyword !== '') {
        $where[] = '(p.asset_id LIKE :q OR p.type LIKE :q OR p.sector_id LIKE :q OR a.model LIKE :q)';
        $params['q'] = '%' . $keyword . '%';
    }

    $sql = '
        SELECT
            p.asset_id,
            p.sector_id,
            s.feeder_area,
            s.city,
            s.street,
            p.type,
            p.spec_id,
            ST_X(p.gps) AS gps_lat,
            ST_Y(p.gps) AS gps_lng,
            a.model,
            a.voltage,
            a.amperage,
            h.health_level,
            h.current_status,
            h.last_inspection_date,
            i.inspec_id,
            i.observation,
            i.risk_score,
            i.inspec_time
        FROM Powerasset p
        LEFT JOIN Sector s ON s.sector_id = p.sector_id
        LEFT JOIN Assetspec a ON a.spec_id = p.spec_id
        LEFT JOIN Health h ON h.asset_id = p.asset_id
          AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
        LEFT JOIN Inspectionlog i ON i.asset_id = p.asset_id
          AND i.inspec_time = (SELECT MAX(i2.inspec_time) FROM Inspectionlog i2 WHERE i2.asset_id = p.asset_id)
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY
            CASE h.health_level
                WHEN "危險" THEN 1
                WHEN "待修" THEN 2
                WHEN "良" THEN 3
                WHEN "優" THEN 4
                ELSE 5
            END,
            p.asset_id ASC';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();

    return $stmt->fetchAll();
}

function asset_detail(PDO $pdo, string $assetId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            p.asset_id,
            p.sector_id,
            s.feeder_area,
            s.city,
            s.street,
            p.type,
            p.spec_id,
            ST_X(p.gps) AS gps_lat,
            ST_Y(p.gps) AS gps_lng,
            a.model,
            a.voltage,
            a.amperage,
            m.name AS manufacturer_name,
            h.install_date,
            h.expected_lifespan,
            h.last_inspection_date,
            h.health_level,
            h.current_status,
            i.inspec_id,
            i.observation,
            i.risk_score,
            i.inspec_time
         FROM Powerasset p
         LEFT JOIN Sector s ON s.sector_id = p.sector_id
         LEFT JOIN Assetspec a ON a.spec_id = p.spec_id
         LEFT JOIN Manufacturer m ON m.manufacturer_id = a.manufacturer_id
         LEFT JOIN Health h ON h.asset_id = p.asset_id
           AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
         LEFT JOIN Inspectionlog i ON i.asset_id = p.asset_id
           AND i.inspec_time = (SELECT MAX(i2.inspec_time) FROM Inspectionlog i2 WHERE i2.asset_id = p.asset_id)
         WHERE p.asset_id = :asset_id'
    );
    $stmt->execute(['asset_id' => $assetId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

try {
    require_admin_role();
    $pdo = api_db();

    $assetId = trim((string)($_GET['asset_id'] ?? ''));
    if ($assetId !== '') {
        $detail = asset_detail($pdo, $assetId);
        if ($detail === null) {
            json_response(['ok' => false, 'error' => '查無資產'], 404);
        }
        json_response(['ok' => true, 'data' => $detail]);
    }

    json_response([
        'ok' => true,
        'data' => [
            'filters' => get_filter_options($pdo),
            'markers' => list_map_assets($pdo),
        ],
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => '系統錯誤', 'detail' => $e->getMessage()], 500);
}