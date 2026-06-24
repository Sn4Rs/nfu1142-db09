<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_common.php';

const CHANGE_REASONS = ['雷擊老化', '車禍外力', '道路拓寬', '計畫性汰換', '其他'];
const CHANGE_STATUSES = ['運作中', '維修中', '報廢'];

function sector_list(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            s.sector_id,
            s.feeder_area,
            s.street,
            s.city,
            s.postal_code,
            s.country,
            COUNT(p.asset_id) AS asset_count,
            SUM(CASE WHEN h.health_level = "危險" THEN 1 ELSE 0 END) AS danger_count,
            SUM(CASE WHEN h.health_level = "待修" THEN 1 ELSE 0 END) AS repair_count
         FROM Sector s
         LEFT JOIN Powerasset p ON p.sector_id = s.sector_id
         LEFT JOIN Health h ON h.asset_id = p.asset_id
           AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
         GROUP BY s.sector_id, s.feeder_area, s.street, s.city, s.postal_code, s.country
         ORDER BY s.feeder_area, s.sector_id'
    );
    return $stmt->fetchAll();
}

function assets_for_sector(PDO $pdo, string $sectorId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            p.asset_id,
            p.type,
            p.spec_id,
            h.health_level,
            h.current_status,
            h.last_inspection_date,
            a.model
         FROM Powerasset p
         LEFT JOIN Assetspec a ON a.spec_id = p.spec_id
         LEFT JOIN Health h ON h.asset_id = p.asset_id
           AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
         WHERE p.sector_id = :sector_id
         ORDER BY p.asset_id'
    );
    $stmt->execute(['sector_id' => $sectorId]);
    return $stmt->fetchAll();
}

function fetch_asset_change_state(PDO $pdo, string $assetId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            p.asset_id,
            p.sector_id,
            p.type,
            p.spec_id,
            h.install_date,
            h.expected_lifespan,
            h.last_inspection_date,
            h.health_level,
            h.current_status,
            h.valid_from,
            h.valid_to
         FROM Powerasset p
         LEFT JOIN Health h ON h.asset_id = p.asset_id
           AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
         WHERE p.asset_id = :asset_id'
    );
    $stmt->execute(['asset_id' => $assetId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function apply_asset_change(PDO $pdo, array $body): void
{
    $assetId = trim((string)($body['asset_id'] ?? ''));
    $newSectorId = trim((string)($body['new_sector_id'] ?? ''));
    $newStatus = trim((string)($body['current_status'] ?? ''));
    $reason = trim((string)($body['reason'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));

    $errors = [];
    if ($assetId === '') $errors['asset_id'][] = '必填欄位不可為空';
    if ($reason === '') $errors['reason'][] = '異動原因必填';
    if ($reason !== '' && !in_array($reason, CHANGE_REASONS, true)) $errors['reason'][] = '異動原因不合法';
    if ($newStatus !== '' && !in_array($newStatus, CHANGE_STATUSES, true)) $errors['current_status'][] = '狀態不合法';
    if ($newSectorId === '' && $newStatus === '') $errors['change'][] = '至少要提供新分區或新狀態';

    if ($errors) json_response(['ok' => false, 'errors' => $errors], 422);

    $before = fetch_asset_change_state($pdo, $assetId);
    if ($before === null) json_response(['ok' => false, 'error' => '查無資產'], 404);

    if ($newSectorId !== '') {
        $check = $pdo->prepare('SELECT COUNT(*) FROM Sector WHERE sector_id = :sector_id');
        $check->execute(['sector_id' => $newSectorId]);
        if ((int)$check->fetchColumn() === 0) {
            json_response(['ok' => false, 'errors' => ['new_sector_id' => ['查無此 Sector.sector_id']]], 422);
        }
    }

    $isRetire = $newStatus === '報廢';
    $isMove = $newSectorId !== '' && $newSectorId !== $before['sector_id'];
    if (($isRetire || $isMove) && $note === '') {
        json_response(['ok' => false, 'errors' => ['note' => ['報廢或遷移分區時必須填寫備註']]], 422);
    }

    $pdo->beginTransaction();
    try {
        if ($isMove) {
            $stmt = $pdo->prepare('UPDATE Powerasset SET sector_id = :sector_id WHERE asset_id = :asset_id');
            $stmt->execute(['sector_id' => $newSectorId, 'asset_id' => $assetId]);
        }

        if ($newStatus !== '') {
            if (!empty($before['valid_from'])) {
                $stmt = $pdo->prepare(
                    'UPDATE Health
                     SET current_status = :current_status
                     WHERE asset_id = :asset_id AND valid_from = :valid_from'
                );
                $stmt->execute([
                    'current_status' => $newStatus,
                    'asset_id' => $assetId,
                    'valid_from' => $before['valid_from'],
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO Health
                    (asset_id, install_date, expected_lifespan, last_inspection_date, health_level, current_status, valid_from, valid_to)
                    VALUES (:asset_id, NULL, 1, NULL, "良", :current_status, NOW(), NULL)'
                );
                $stmt->execute(['asset_id' => $assetId, 'current_status' => $newStatus]);
            }
        }

        $after = fetch_asset_change_state($pdo, $assetId);
        $after['change_reason'] = $reason;
        $after['change_note'] = $note;
        write_audit($pdo, '饋線領地與設備異動管理', '修改', $assetId, $before, $after);
        $pdo->commit();
        json_response(['ok' => true, 'data' => $after]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => '異動資產失敗', 'detail' => $e->getMessage()], 500);
    }
}

try {
    require_admin_role();
    $pdo = api_db();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $sectorId = trim((string)($_GET['sector_id'] ?? ''));
        json_response([
            'ok' => true,
            'data' => [
                'sectors' => sector_list($pdo),
                'assets' => $sectorId === '' ? [] : assets_for_sector($pdo, $sectorId),
            ],
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        apply_asset_change($pdo, read_json_body());
    }

    json_response(['ok' => false, 'error' => '不支援的 HTTP method'], 405);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => '系統錯誤', 'detail' => $e->getMessage()], 500);
}