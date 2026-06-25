<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_common.php';

const ASSET_TYPES = ['電塔', '電線桿', '電箱', '變壓器', '開關箱'];
const HEALTH_LEVELS = ['優', '良', '待修', '危險'];
const CURRENT_STATUSES = ['運作中', '維修中', '報廢'];

function validate_asset_payload(PDO $pdo, array $data, bool $isCreate): array
{
    $required = ['asset_id', 'sector_id', 'type', 'spec_id', 'install_date', 'expected_lifespan', 'health_level', 'current_status'];
    $errors = require_keys($data, $required);

    $assetId = trim((string)($data['asset_id'] ?? ''));
    if ($assetId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,10}$/', $assetId)) {
        $errors['asset_id'][] = '只能包含英數字、底線或連字號，且最多 10 字元';
    }

    $sectorId = trim((string)($data['sector_id'] ?? ''));
    if ($sectorId !== '' && strlen($sectorId) > 50) {
        $errors['sector_id'][] = '長度不可超過 50 字元';
    }

    $type = trim((string)($data['type'] ?? ''));
    $typeLength = function_exists('mb_strlen') ? mb_strlen($type, 'UTF-8') : strlen($type);
    if ($type !== '' && $typeLength > 20) {
        $errors['type'][] = '長度不可超過 20 字元';
    }

    $specId = trim((string)($data['spec_id'] ?? ''));
    if ($specId !== '' && strlen($specId) > 50) {
        $errors['spec_id'][] = '長度不可超過 50 字元';
    }

    if (!is_valid_date(nullable_string($data, 'install_date'))) {
        $errors['install_date'][] = '日期格式必須為 YYYY-MM-DD';
    }

    if (!is_valid_date(nullable_string($data, 'last_inspection_date'))) {
        $errors['last_inspection_date'][] = '日期格式必須為 YYYY-MM-DD';
    }

    if (!is_valid_datetime(nullable_string($data, 'valid_from'))) {
        $errors['valid_from'][] = '日期時間格式必須為 YYYY-MM-DD HH:MM:SS';
    }

    if (!is_valid_datetime(nullable_string($data, 'valid_to'))) {
        $errors['valid_to'][] = '日期時間格式必須為 YYYY-MM-DD HH:MM:SS';
    }

    $lifespan = filter_var($data['expected_lifespan'] ?? null, FILTER_VALIDATE_INT);
    if ($lifespan === false || $lifespan <= 0 || $lifespan > 200) {
        $errors['expected_lifespan'][] = '預計壽命必須是 1 到 200 的整數';
    }

    $healthLevel = trim((string)($data['health_level'] ?? ''));
    if ($healthLevel !== '' && !in_array($healthLevel, HEALTH_LEVELS, true)) {
        $errors['health_level'][] = '健康度必須為：優、良、待修、危險';
    }

    $status = trim((string)($data['current_status'] ?? ''));
    if ($status !== '' && !in_array($status, CURRENT_STATUSES, true)) {
        $errors['current_status'][] = '目前狀態必須為：運作中、維修中、報廢';
    }

    $lat = nullable_string($data, 'gps_lat');
    $lng = nullable_string($data, 'gps_lng');
    if (($lat === null) !== ($lng === null)) {
        $errors['gps'][] = 'gps_lat 與 gps_lng 必須同時提供或同時留空';
    }
    if ($lat !== null && filter_var($lat, FILTER_VALIDATE_FLOAT) === false) {
        $errors['gps_lat'][] = '緯度必須是數字';
    }
    if ($lng !== null && filter_var($lng, FILTER_VALIDATE_FLOAT) === false) {
        $errors['gps_lng'][] = '經度必須是數字';
    }

    if ($sectorId !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Sector WHERE sector_id = :sector_id');
        $stmt->execute(['sector_id' => $sectorId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors['sector_id'][] = '查無此 Sector.sector_id';
        }
    }

    if ($specId !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Assetspec WHERE spec_id = :spec_id');
        $stmt->execute(['spec_id' => $specId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors['spec_id'][] = '查無此 Assetspec.spec_id';
        }
    }

    if ($isCreate && $assetId !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Powerasset WHERE asset_id = :asset_id');
        $stmt->execute(['asset_id' => $assetId]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors['asset_id'][] = '資產編號已存在';
        }
    }

    return $errors;
}

function fetch_asset(PDO $pdo, string $assetId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            p.asset_id, p.sector_id, p.type, p.spec_id,
            ST_X(p.gps) AS gps_lat, ST_Y(p.gps) AS gps_lng,
            a.model, a.voltage, a.amperage,
            h.install_date, h.expected_lifespan, h.last_inspection_date,
            h.health_level, h.current_status, h.valid_from, h.valid_to
        FROM Powerasset p
        LEFT JOIN Assetspec a ON a.spec_id = p.spec_id
        LEFT JOIN Health h ON h.asset_id = p.asset_id
          AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
        WHERE p.asset_id = :asset_id'
    );
    $stmt->execute(['asset_id' => $assetId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function list_assets(PDO $pdo): void
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $keyword = trim((string)($_GET['q'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));

    $where = [];
    $params = [];

    if ($keyword !== '') {
        $where[] = '(p.asset_id LIKE :q_asset OR p.sector_id LIKE :q_sector OR p.type LIKE :q_type OR p.spec_id LIKE :q_spec OR a.model LIKE :q_model)';
        $likeKeyword = '%' . $keyword . '%';
        $params['q_asset'] = $likeKeyword;
        $params['q_sector'] = $likeKeyword;
        $params['q_type'] = $likeKeyword;
        $params['q_spec'] = $likeKeyword;
        $params['q_model'] = $likeKeyword;
    }

    if ($type !== '') {
        $where[] = 'p.type = :type';
        $params['type'] = $type;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM Powerasset p
         LEFT JOIN Assetspec a ON a.spec_id = p.spec_id
         $whereSql"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            p.asset_id, p.sector_id, s.feeder_area, p.type, p.spec_id,
            ST_X(p.gps) AS gps_lat, ST_Y(p.gps) AS gps_lng,
            a.model, a.voltage, a.amperage,
            h.install_date, h.expected_lifespan, h.last_inspection_date,
            h.health_level, h.current_status, h.valid_from, h.valid_to
        FROM Powerasset p
        LEFT JOIN Sector s ON s.sector_id = p.sector_id
        LEFT JOIN Assetspec a ON a.spec_id = p.spec_id
        LEFT JOIN Health h ON h.asset_id = p.asset_id
          AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
        $whereSql
        ORDER BY p.asset_id ASC
        LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    json_response([
        'ok' => true,
        'data' => $stmt->fetchAll(),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int)ceil($total / $perPage),
        ],
    ]);
}

function create_asset(PDO $pdo, array $data): void
{
    $errors = validate_asset_payload($pdo, $data, true);
    if ($errors) {
        json_response(['ok' => false, 'errors' => $errors], 422);
    }

    $assetId = trim((string)$data['asset_id']);
    $validFrom = nullable_string($data, 'valid_from') ?? date('Y-m-d H:i:s');
    $lat = nullable_string($data, 'gps_lat');
    $lng = nullable_string($data, 'gps_lng');

    $pdo->beginTransaction();
    try {
        if ($lat !== null && $lng !== null) {
            $stmt = $pdo->prepare(
                'INSERT INTO Powerasset (asset_id, sector_id, type, spec_id, gps)
                 VALUES (:asset_id, :sector_id, :type, :spec_id, POINT(:lat, :lng))'
            );
            $stmt->execute([
                'asset_id' => $assetId,
                'sector_id' => trim((string)$data['sector_id']),
                'type' => trim((string)$data['type']),
                'spec_id' => trim((string)$data['spec_id']),
                'lat' => (float)$lat,
                'lng' => (float)$lng,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO Powerasset (asset_id, sector_id, type, spec_id, gps)
                 VALUES (:asset_id, :sector_id, :type, :spec_id, NULL)'
            );
            $stmt->execute([
                'asset_id' => $assetId,
                'sector_id' => trim((string)$data['sector_id']),
                'type' => trim((string)$data['type']),
                'spec_id' => trim((string)$data['spec_id']),
            ]);
        }

        $healthStmt = $pdo->prepare(
            'INSERT INTO Health
            (asset_id, install_date, expected_lifespan, last_inspection_date, health_level, current_status, valid_from, valid_to)
            VALUES
            (:asset_id, :install_date, :expected_lifespan, :last_inspection_date, :health_level, :current_status, :valid_from, :valid_to)'
        );
        $healthStmt->execute([
            'asset_id' => $assetId,
            'install_date' => nullable_string($data, 'install_date'),
            'expected_lifespan' => (int)$data['expected_lifespan'],
            'last_inspection_date' => nullable_string($data, 'last_inspection_date'),
            'health_level' => trim((string)$data['health_level']),
            'current_status' => trim((string)$data['current_status']),
            'valid_from' => $validFrom,
            'valid_to' => nullable_string($data, 'valid_to'),
        ]);

        $after = fetch_asset($pdo, $assetId);
        write_audit($pdo, '資產全面盤點與建檔管理', '新增', $assetId, null, $after);
        $pdo->commit();

        json_response(['ok' => true, 'data' => $after], 201);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => '新增資產失敗', 'detail' => $e->getMessage()], 500);
    }
}

function update_asset(PDO $pdo, array $data): void
{
    $assetId = trim((string)($data['asset_id'] ?? ''));
    if ($assetId === '') {
        json_response(['ok' => false, 'errors' => ['asset_id' => ['必填欄位不可為空']]], 422);
    }

    $before = fetch_asset($pdo, $assetId);
    if ($before === null) {
        json_response(['ok' => false, 'error' => '查無資產'], 404);
    }

    $merged = array_merge($before, $data);
    $errors = validate_asset_payload($pdo, $merged, false);
    if ($errors) {
        json_response(['ok' => false, 'errors' => $errors], 422);
    }

    $lat = nullable_string($merged, 'gps_lat');
    $lng = nullable_string($merged, 'gps_lng');

    $pdo->beginTransaction();
    try {
        if ($lat !== null && $lng !== null) {
            $stmt = $pdo->prepare(
                'UPDATE Powerasset
                 SET sector_id = :sector_id, type = :type, spec_id = :spec_id, gps = POINT(:lat, :lng)
                 WHERE asset_id = :asset_id'
            );
            $stmt->execute([
                'asset_id' => $assetId,
                'sector_id' => trim((string)$merged['sector_id']),
                'type' => trim((string)$merged['type']),
                'spec_id' => trim((string)$merged['spec_id']),
                'lat' => (float)$lat,
                'lng' => (float)$lng,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE Powerasset
                 SET sector_id = :sector_id, type = :type, spec_id = :spec_id, gps = NULL
                 WHERE asset_id = :asset_id'
            );
            $stmt->execute([
                'asset_id' => $assetId,
                'sector_id' => trim((string)$merged['sector_id']),
                'type' => trim((string)$merged['type']),
                'spec_id' => trim((string)$merged['spec_id']),
            ]);
        }

        if (!empty($before['valid_from'])) {
            $healthStmt = $pdo->prepare(
                'UPDATE Health
                 SET install_date = :install_date,
                     expected_lifespan = :expected_lifespan,
                     last_inspection_date = :last_inspection_date,
                     health_level = :health_level,
                     current_status = :current_status,
                     valid_to = :valid_to
                 WHERE asset_id = :asset_id AND valid_from = :valid_from'
            );
            $healthStmt->execute([
                'asset_id' => $assetId,
                'install_date' => nullable_string($merged, 'install_date'),
                'expected_lifespan' => (int)$merged['expected_lifespan'],
                'last_inspection_date' => nullable_string($merged, 'last_inspection_date'),
                'health_level' => trim((string)$merged['health_level']),
                'current_status' => trim((string)$merged['current_status']),
                'valid_to' => nullable_string($merged, 'valid_to'),
                'valid_from' => $before['valid_from'],
            ]);
        } else {
            $healthStmt = $pdo->prepare(
                'INSERT INTO Health
                (asset_id, install_date, expected_lifespan, last_inspection_date, health_level, current_status, valid_from, valid_to)
                VALUES
                (:asset_id, :install_date, :expected_lifespan, :last_inspection_date, :health_level, :current_status, :valid_from, :valid_to)'
            );
            $healthStmt->execute([
                'asset_id' => $assetId,
                'install_date' => nullable_string($merged, 'install_date'),
                'expected_lifespan' => (int)$merged['expected_lifespan'],
                'last_inspection_date' => nullable_string($merged, 'last_inspection_date'),
                'health_level' => trim((string)$merged['health_level']),
                'current_status' => trim((string)$merged['current_status']),
                'valid_from' => date('Y-m-d H:i:s'),
                'valid_to' => nullable_string($merged, 'valid_to'),
            ]);
        }

        $after = fetch_asset($pdo, $assetId);
        write_audit($pdo, '資產全面盤點與建檔管理', '修改', $assetId, $before, $after);
        $pdo->commit();

        json_response(['ok' => true, 'data' => $after]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => '修改資產失敗', 'detail' => $e->getMessage()], 500);
    }
}

try {
    require_admin_role();
    $pdo = api_db();
    ensure_admin_module3_schema($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        list_assets($pdo);
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $action = (string)($body['action'] ?? 'create');
        if ($action === 'create') {
            create_asset($pdo, $body);
        }
        if ($action === 'update') {
            update_asset($pdo, $body);
        }
        json_response(['ok' => false, 'error' => '不支援的 action'], 400);
    }

    json_response(['ok' => false, 'error' => '不支援的 HTTP method'], 405);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => '系統錯誤', 'detail' => $e->getMessage()], 500);
}
