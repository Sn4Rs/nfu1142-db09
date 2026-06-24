<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_common.php';

function stock_rows(PDO $pdo): array
{
    return $pdo->query(
        'SELECT *,
            CASE
                WHEN stock <= safe_stock THEN "低庫存"
                WHEN stock <= safe_stock + reorder_qty THEN "即將不足"
                ELSE "正常"
            END AS stock_state
         FROM Partspecs
         ORDER BY
            CASE
                WHEN stock <= safe_stock THEN 1
                WHEN stock <= safe_stock + reorder_qty THEN 2
                ELSE 3
            END,
            part_id'
    )->fetchAll();
}

function request_rows(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            pr.request_id,
            pr.maint_id,
            pr.technician_id,
            e.fullname AS technician_name,
            pr.status,
            pr.request_time,
            pr.approved_by,
            pr.approved_at,
            pr.reject_reason,
            ml.asset_id,
            ml.status AS maintenance_status,
            GROUP_CONCAT(CONCAT(rp.part_id, ":", rp.req_qty, ":", rp.status) ORDER BY rp.part_id SEPARATOR ", ") AS parts_summary
         FROM Partsrequest pr
         LEFT JOIN Employees e ON e.id_num = pr.technician_id
         LEFT JOIN Maintenancelog ml ON ml.maint_id = pr.maint_id
         LEFT JOIN Req_part rp ON rp.request_id = pr.request_id
         GROUP BY pr.request_id, pr.maint_id, pr.technician_id, e.fullname, pr.status, pr.request_time, pr.approved_by, pr.approved_at, pr.reject_reason, ml.asset_id, ml.status
         ORDER BY FIELD(pr.status, "待審", "批准", "駁回"), pr.request_time DESC'
    );
    return $stmt->fetchAll();
}

function fetch_request_full(PDO $pdo, string $requestId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM Partsrequest WHERE request_id = :request_id');
    $stmt->execute(['request_id' => $requestId]);
    $request = $stmt->fetch();
    if (!$request) return null;

    $partsStmt = $pdo->prepare(
        'SELECT rp.*, p.stock, p.safe_stock, p.part_name
         FROM Req_part rp
         LEFT JOIN Partspecs p ON p.part_id = rp.part_id
         WHERE rp.request_id = :request_id
         ORDER BY rp.part_id'
    );
    $partsStmt->execute(['request_id' => $requestId]);
    $request['parts'] = $partsStmt->fetchAll();

    $maintStmt = $pdo->prepare('SELECT * FROM Maintenancelog WHERE maint_id = :maint_id');
    $maintStmt->execute(['maint_id' => $request['maint_id']]);
    $request['maintenance'] = $maintStmt->fetch() ?: null;
    return $request;
}

function update_stock(PDO $pdo, array $body): void
{
    $partId = trim((string)($body['part_id'] ?? ''));
    if ($partId === '') json_response(['ok' => false, 'errors' => ['part_id' => ['必填欄位不可為空']]], 422);

    $stock = filter_var($body['stock'] ?? null, FILTER_VALIDATE_INT);
    $safeStock = filter_var($body['safe_stock'] ?? null, FILTER_VALIDATE_INT);
    $reorderQty = filter_var($body['reorder_qty'] ?? null, FILTER_VALIDATE_INT);
    if ($stock === false || $stock < 0) json_response(['ok' => false, 'errors' => ['stock' => ['庫存必須是 0 以上整數']]], 422);
    if ($safeStock === false || $safeStock < 0) json_response(['ok' => false, 'errors' => ['safe_stock' => ['安全庫存必須是 0 以上整數']]], 422);
    if ($reorderQty === false || $reorderQty < 0) json_response(['ok' => false, 'errors' => ['reorder_qty' => ['建議補貨量必須是 0 以上整數']]], 422);

    $stmt = $pdo->prepare('SELECT * FROM Partspecs WHERE part_id = :part_id');
    $stmt->execute(['part_id' => $partId]);
    $before = $stmt->fetch();
    if (!$before) json_response(['ok' => false, 'error' => '查無零件'], 404);

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE Partspecs
             SET stock = :stock, safe_stock = :safe_stock, reorder_qty = :reorder_qty, last_check_time = NOW()
             WHERE part_id = :part_id'
        );
        $update->execute(['stock' => $stock, 'safe_stock' => $safeStock, 'reorder_qty' => $reorderQty, 'part_id' => $partId]);
        $afterStmt = $pdo->prepare('SELECT * FROM Partspecs WHERE part_id = :part_id');
        $afterStmt->execute(['part_id' => $partId]);
        $after = $afterStmt->fetch();
        write_audit($pdo, '維護零件庫存與後台主管審核', '修改', $partId, $before, $after);
        $pdo->commit();
        json_response(['ok' => true, 'data' => $after]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => '更新庫存失敗', 'detail' => $e->getMessage()], 500);
    }
}

function decide_request(PDO $pdo, array $body): void
{
    $role = strtolower(current_employee_role());
    if (!in_array($role, ['assetmanager', 'deptmanager'], true)) {
        json_response(['ok' => false, 'error' => '只有資產管理員或主管可以簽核零件申請'], 403);
    }

    $requestId = trim((string)($body['request_id'] ?? ''));
    $decision = trim((string)($body['decision'] ?? ''));
    $rejectReason = trim((string)($body['reject_reason'] ?? ''));
    if ($requestId === '') json_response(['ok' => false, 'errors' => ['request_id' => ['必填欄位不可為空']]], 422);
    if (!in_array($decision, ['批准', '駁回'], true)) json_response(['ok' => false, 'errors' => ['decision' => ['decision 必須是 批准 或 駁回']]], 422);
    if ($decision === '駁回' && $rejectReason === '') json_response(['ok' => false, 'errors' => ['reject_reason' => ['駁回必須填寫原因']]], 422);

    $before = fetch_request_full($pdo, $requestId);
    if ($before === null) json_response(['ok' => false, 'error' => '查無申請單'], 404);
    if ($before['status'] !== '待審') json_response(['ok' => false, 'error' => '此申請單已完成簽核'], 409);

    $pdo->beginTransaction();
    try {
        if ($decision === '批准') {
            foreach ($before['parts'] as $part) {
                $stmt = $pdo->prepare('UPDATE Partspecs SET stock = stock + :qty, last_check_time = NOW() WHERE part_id = :part_id');
                $stmt->execute(['qty' => (int)$part['req_qty'], 'part_id' => $part['part_id']]);
            }
            $pdo->prepare('UPDATE Req_part SET status = "批准" WHERE request_id = :request_id')->execute(['request_id' => $requestId]);
            $pdo->prepare('UPDATE Maintenancelog SET status = "待處理" WHERE maint_id = :maint_id AND status = "缺件"')->execute(['maint_id' => $before['maint_id']]);
        } else {
            $pdo->prepare('UPDATE Req_part SET status = "駁回" WHERE request_id = :request_id')->execute(['request_id' => $requestId]);
        }

        $stmt = $pdo->prepare(
            'UPDATE Partsrequest
             SET status = :status, approved_by = :approved_by, approved_at = NOW(), reject_reason = :reject_reason
             WHERE request_id = :request_id'
        );
        $stmt->execute([
            'status' => $decision,
            'approved_by' => current_employee_id(),
            'reject_reason' => $decision === '駁回' ? $rejectReason : null,
            'request_id' => $requestId,
        ]);

        create_notification(
            $pdo,
            $before['technician_id'],
            'Technician',
            '零件申請簽核',
            $requestId,
            '零件申請' . $decision . '：' . $requestId,
            $decision === '批准' ? '零件已核准並更新庫存，維修單已重新放回待處理。' : '零件申請遭駁回：' . $rejectReason
        );

        $after = fetch_request_full($pdo, $requestId);
        write_audit($pdo, '維護零件庫存與後台主管審核', '簽核', $requestId, $before, $after);
        $pdo->commit();
        json_response(['ok' => true, 'data' => $after]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => '簽核失敗', 'detail' => $e->getMessage()], 500);
    }
}

try {
    require_admin_role();
    $pdo = api_db();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        json_response(['ok' => true, 'data' => ['parts' => stock_rows($pdo), 'requests' => request_rows($pdo)]]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $body = read_json_body();
        $action = (string)($body['action'] ?? '');
        if ($action === 'update_stock') update_stock($pdo, $body);
        if ($action === 'decide_request') decide_request($pdo, $body);
        json_response(['ok' => false, 'error' => '不支援的 action'], 400);
    }

    json_response(['ok' => false, 'error' => '不支援的 HTTP method'], 405);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => '系統錯誤', 'detail' => $e->getMessage()], 500);
}