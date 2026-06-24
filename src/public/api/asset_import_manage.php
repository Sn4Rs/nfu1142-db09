<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_common.php';

const CSV_REQUIRED_FIELDS = [
    'asset_id',
    'sector_id',
    'type',
    'spec_id',
    'install_date',
    'expected_lifespan',
    'health_level',
    'current_status',
];

function normalize_header(string $header): string
{
    return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header)));
}

function insert_import_error(PDO $pdo, string $batchId, int $rowNo, ?string $fieldName, string $message): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO Importerror (error_id, batch_id, row_no, field_name, error_message)
         VALUES (:error_id, :batch_id, :row_no, :field_name, :error_message)'
    );
    $stmt->execute([
        'error_id' => generate_id('ERR'),
        'batch_id' => $batchId,
        'row_no' => $rowNo,
        'field_name' => $fieldName,
        'error_message' => $message,
    ]);
}

function csv_row_to_assoc(array $headers, array $row): array
{
    $data = [];
    foreach ($headers as $index => $field) {
        $data[$field] = trim((string)($row[$index] ?? ''));
    }
    return $data;
}

function validate_import_row(PDO $pdo, array $data, int $rowNo, array $seenAssetIds): array
{
    $errors = [];

    foreach (CSV_REQUIRED_FIELDS as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === '') {
            $errors[] = [$field, '必填欄位不可為空'];
        }
    }

    $assetId = $data['asset_id'] ?? '';
    if ($assetId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,10}$/', $assetId)) {
        $errors[] = ['asset_id', '只能包含英數字、底線或連字號，且最多 10 字元'];
    }
    if ($assetId !== '' && in_array($assetId, $seenAssetIds, true)) {
        $errors[] = ['asset_id', 'CSV 內資產編號重複'];
    }

    if ($assetId !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Powerasset WHERE asset_id = :asset_id');
        $stmt->execute(['asset_id' => $assetId]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = ['asset_id', '資料庫已存在相同資產編號'];
        }
    }

    if (($data['sector_id'] ?? '') !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Sector WHERE sector_id = :sector_id');
        $stmt->execute(['sector_id' => $data['sector_id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = ['sector_id', '查無此 Sector.sector_id'];
        }
    }

    if (($data['spec_id'] ?? '') !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM Assetspec WHERE spec_id = :spec_id');
        $stmt->execute(['spec_id' => $data['spec_id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = ['spec_id', '查無此 Assetspec.spec_id'];
        }
    }

    if (!is_valid_date($data['install_date'] ?? null)) {
        $errors[] = ['install_date', '日期格式必須為 YYYY-MM-DD'];
    }

    if (!is_valid_date(($data['last_inspection_date'] ?? '') ?: null)) {
        $errors[] = ['last_inspection_date', '日期格式必須為 YYYY-MM-DD'];
    }

    if (!is_valid_datetime(($data['valid_from'] ?? '') ?: null)) {
        $errors[] = ['valid_from', '日期時間格式必須為 YYYY-MM-DD HH:MM:SS'];
    }

    if (!is_valid_datetime(($data['valid_to'] ?? '') ?: null)) {
        $errors[] = ['valid_to', '日期時間格式必須為 YYYY-MM-DD HH:MM:SS'];
    }

    $lifespan = filter_var($data['expected_lifespan'] ?? null, FILTER_VALIDATE_INT);
    if ($lifespan === false || $lifespan <= 0 || $lifespan > 200) {
        $errors[] = ['expected_lifespan', '預計壽命必須是 1 到 200 的整數'];
    }

    if (($data['health_level'] ?? '') !== '' && !in_array($data['health_level'], ['優', '良', '待修', '危險'], true)) {
        $errors[] = ['health_level', '健康度必須為：優、良、待修、危險'];
    }

    if (($data['current_status'] ?? '') !== '' && !in_array($data['current_status'], ['運作中', '維修中', '報廢'], true)) {
        $errors[] = ['current_status', '目前狀態必須為：運作中、維修中、報廢'];
    }

    $lat = $data['gps_lat'] ?? '';
    $lng = $data['gps_lng'] ?? '';
    if (($lat === '') !== ($lng === '')) {
        $errors[] = ['gps', 'gps_lat 與 gps_lng 必須同時提供或同時留空'];
    }
    if ($lat !== '' && filter_var($lat, FILTER_VALIDATE_FLOAT) === false) {
        $errors[] = ['gps_lat', '緯度必須是數字'];
    }
    if ($lng !== '' && filter_var($lng, FILTER_VALIDATE_FLOAT) === false) {
        $errors[] = ['gps_lng', '經度必須是數字'];
    }

    return $errors;
}

function insert_import_asset(PDO $pdo, array $data): void
{
    if (($data['gps_lat'] ?? '') !== '' && ($data['gps_lng'] ?? '') !== '') {
        $assetStmt = $pdo->prepare(
            'INSERT INTO Powerasset (asset_id, sector_id, type, spec_id, gps)
             VALUES (:asset_id, :sector_id, :type, :spec_id, POINT(:lat, :lng))'
        );
        $assetStmt->execute([
            'asset_id' => $data['asset_id'],
            'sector_id' => $data['sector_id'],
            'type' => $data['type'],
            'spec_id' => $data['spec_id'],
            'lat' => (float)$data['gps_lat'],
            'lng' => (float)$data['gps_lng'],
        ]);
    } else {
        $assetStmt = $pdo->prepare(
            'INSERT INTO Powerasset (asset_id, sector_id, type, spec_id, gps)
             VALUES (:asset_id, :sector_id, :type, :spec_id, NULL)'
        );
        $assetStmt->execute([
            'asset_id' => $data['asset_id'],
            'sector_id' => $data['sector_id'],
            'type' => $data['type'],
            'spec_id' => $data['spec_id'],
        ]);
    }

    $healthStmt = $pdo->prepare(
        'INSERT INTO Health
        (asset_id, install_date, expected_lifespan, last_inspection_date, health_level, current_status, valid_from, valid_to)
        VALUES
        (:asset_id, :install_date, :expected_lifespan, :last_inspection_date, :health_level, :current_status, :valid_from, :valid_to)'
    );
    $healthStmt->execute([
        'asset_id' => $data['asset_id'],
        'install_date' => $data['install_date'],
        'expected_lifespan' => (int)$data['expected_lifespan'],
        'last_inspection_date' => ($data['last_inspection_date'] ?? '') === '' ? null : $data['last_inspection_date'],
        'health_level' => $data['health_level'],
        'current_status' => $data['current_status'],
        'valid_from' => ($data['valid_from'] ?? '') === '' ? date('Y-m-d H:i:s') : $data['valid_from'],
        'valid_to' => ($data['valid_to'] ?? '') === '' ? null : $data['valid_to'],
    ]);
}

function import_csv(PDO $pdo): void
{
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        json_response(['ok' => false, 'error' => '請上傳 CSV 檔案，欄位名稱為 file'], 400);
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => '檔案上傳失敗', 'upload_error' => $file['error'] ?? null], 400);
    }

    $originalName = (string)($file['name'] ?? 'assets.csv');
    if (!preg_match('/\.csv$/i', $originalName)) {
        json_response(['ok' => false, 'error' => '僅支援 CSV 檔案'], 400);
    }

    $handle = fopen((string)$file['tmp_name'], 'rb');
    if ($handle === false) {
        json_response(['ok' => false, 'error' => '無法讀取上傳檔案'], 400);
    }

    $headers = fgetcsv($handle);
    if ($headers === false) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        json_response(['ok' => false, 'error' => 'CSV 不可為空'], 400);
    }

    $headers = array_map(static fn($h): string => normalize_header((string)$h), $headers);
    $missingHeaders = array_values(array_diff(CSV_REQUIRED_FIELDS, $headers));
    if ($missingHeaders) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        json_response([
            'ok' => false,
            'error' => 'CSV 缺少必要欄位',
            'missing_headers' => $missingHeaders,
            'required_headers' => CSV_REQUIRED_FIELDS,
        ], 422);
    }

    $batchId = generate_id('IMP');
    $pdo->beginTransaction();
    try {
        $batchStmt = $pdo->prepare(
            'INSERT INTO Importbatch
            (batch_id, file_name, uploaded_by, total_count, success_count, fail_count, status, created_at)
            VALUES
            (:batch_id, :file_name, :uploaded_by, 0, 0, 0, "驗證中", NOW())'
        );
        $batchStmt->execute([
            'batch_id' => $batchId,
            'file_name' => $originalName,
            'uploaded_by' => current_employee_id(),
        ]);

        $totalCount = 0;
        $successCount = 0;
        $failCount = 0;
        $seenAssetIds = [];
        $createdAssetIds = [];
        $rowNo = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNo++;
            if (count(array_filter($row, static fn($v): bool => trim((string)$v) !== '')) === 0) {
                continue;
            }

            $totalCount++;
            $data = csv_row_to_assoc($headers, $row);
            $rowErrors = validate_import_row($pdo, $data, $rowNo, $seenAssetIds);

            if ($rowErrors) {
                $failCount++;
                foreach ($rowErrors as [$fieldName, $message]) {
                    insert_import_error($pdo, $batchId, $rowNo, $fieldName, $message);
                }
                continue;
            }

            insert_import_asset($pdo, $data);
            $successCount++;
            $seenAssetIds[] = $data['asset_id'];
            $createdAssetIds[] = $data['asset_id'];
        }
        if (is_resource($handle)) {
            fclose($handle);
        }

        $status = '成功';
        if ($totalCount === 0 || ($failCount > 0 && $successCount === 0)) {
            $status = '失敗';
        } elseif ($failCount > 0) {
            $status = '部分失敗';
        }

        $updateStmt = $pdo->prepare(
            'UPDATE Importbatch
             SET total_count = :total_count,
                 success_count = :success_count,
                 fail_count = :fail_count,
                 status = :status
             WHERE batch_id = :batch_id'
        );
        $updateStmt->execute([
            'total_count' => $totalCount,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'status' => $status,
            'batch_id' => $batchId,
        ]);

        write_audit($pdo, '資產 CSV 批次匯入', '匯入', $batchId, null, [
            'file_name' => $originalName,
            'total_count' => $totalCount,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'status' => $status,
            'created_asset_ids' => $createdAssetIds,
        ]);

        $pdo->commit();

        json_response([
            'ok' => true,
            'batch_id' => $batchId,
            'status' => $status,
            'total_count' => $totalCount,
            'success_count' => $successCount,
            'fail_count' => $failCount,
        ], 201);
    } catch (Throwable $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'CSV 匯入失敗', 'detail' => $e->getMessage()], 500);
    }
}

function get_import_batch(PDO $pdo): void
{
    $batchId = trim((string)($_GET['batch_id'] ?? ''));
    if ($batchId === '') {
        $stmt = $pdo->query('SELECT * FROM Importbatch ORDER BY created_at DESC LIMIT 20');
        json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    $batchStmt = $pdo->prepare('SELECT * FROM Importbatch WHERE batch_id = :batch_id');
    $batchStmt->execute(['batch_id' => $batchId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        json_response(['ok' => false, 'error' => '查無匯入批次'], 404);
    }

    $errorStmt = $pdo->prepare('SELECT row_no, field_name, error_message FROM Importerror WHERE batch_id = :batch_id ORDER BY row_no, field_name');
    $errorStmt->execute(['batch_id' => $batchId]);

    json_response(['ok' => true, 'data' => $batch, 'errors' => $errorStmt->fetchAll()]);
}

try {
    require_admin_role();
    $pdo = api_db();
    ensure_admin_module3_schema($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        import_csv($pdo);
    }
    if ($method === 'GET') {
        get_import_batch($pdo);
    }

    json_response(['ok' => false, 'error' => '不支援的 HTTP method'], 405);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => '系統錯誤', 'detail' => $e->getMessage()], 500);
}
