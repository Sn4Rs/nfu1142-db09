<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_common.php';

function scalar_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function dashboard_kpis(PDO $pdo): array
{
    $activeAssets = scalar_count(
        $pdo,
        "SELECT COUNT(*)
         FROM Powerasset p
         LEFT JOIN Health h ON h.asset_id = p.asset_id
           AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
         WHERE h.current_status IS NULL OR h.current_status != '報廢'"
    );

    $abnormalTodos = scalar_count(
        $pdo,
        "SELECT COUNT(*)
         FROM Health h
         INNER JOIN (
             SELECT asset_id, MAX(valid_from) AS latest_from
             FROM Health
             GROUP BY asset_id
         ) latest ON latest.asset_id = h.asset_id AND latest.latest_from = h.valid_from
         WHERE h.health_level IN ('待修', '危險')"
    );

    $highRiskAssets = scalar_count(
        $pdo,
        "SELECT COUNT(DISTINCT i.asset_id)
         FROM Inspectionlog i
         INNER JOIN (
             SELECT asset_id, MAX(inspec_time) AS latest_time
             FROM Inspectionlog
             GROUP BY asset_id
         ) latest ON latest.asset_id = i.asset_id AND latest.latest_time = i.inspec_time
         WHERE i.risk_score >= 75"
    );

    $pendingApprovals = scalar_count($pdo, "SELECT COUNT(*) FROM Partsrequest WHERE status = '待審'");

    return [
        'active_assets' => $activeAssets,
        'abnormal_todos' => $abnormalTodos,
        'high_risk_assets' => $highRiskAssets,
        'pending_approvals' => $pendingApprovals,
    ];
}

function recent_notifications(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            n.notification_id,
            n.receiver_id,
            n.receiver_role,
            n.source_type,
            n.source_id,
            n.title,
            n.content,
            n.is_read,
            n.created_at,
            e.fullname AS receiver_name,
            e.role AS employee_role
         FROM Notification n
         LEFT JOIN Employees e ON e.id_num = n.receiver_id
         ORDER BY n.created_at DESC
         LIMIT 12"
    );

    return $stmt->fetchAll();
}

function manager_todos(PDO $pdo, string $role): array
{
    $todos = [];

    if (in_array($role, ['assetmanager', 'deptmanager'], true)) {
        $stmt = $pdo->query(
            "SELECT request_id, maint_id, technician_id, request_time
             FROM Partsrequest
             WHERE status = '待審'
             ORDER BY request_time ASC
             LIMIT 8"
        );
        foreach ($stmt->fetchAll() as $row) {
            $todos[] = [
                'type' => 'parts_approval',
                'priority' => 'high',
                'title' => '審核零件申請 ' . $row['request_id'],
                'description' => '維修單 ' . $row['maint_id'] . ' 需要後台簽核',
                'link' => 'parts-request.php',
                'source_id' => $row['request_id'],
            ];
        }
    }

    if (in_array($role, ['assetmanager', 'deptmanager'], true)) {
        $stmt = $pdo->query(
            "SELECT
                p.asset_id,
                p.type,
                h.health_level,
                i.risk_score,
                i.observation,
                i.inspec_time
             FROM Powerasset p
             LEFT JOIN Health h ON h.asset_id = p.asset_id
               AND h.valid_from = (SELECT MAX(h2.valid_from) FROM Health h2 WHERE h2.asset_id = p.asset_id)
             LEFT JOIN Inspectionlog i ON i.asset_id = p.asset_id
               AND i.inspec_time = (SELECT MAX(i2.inspec_time) FROM Inspectionlog i2 WHERE i2.asset_id = p.asset_id)
             WHERE h.health_level IN ('待修', '危險') OR i.risk_score >= 75
             ORDER BY COALESCE(i.risk_score, 0) DESC, p.asset_id ASC
             LIMIT 8"
        );
        foreach ($stmt->fetchAll() as $row) {
            $todos[] = [
                'type' => 'risk_asset',
                'priority' => ((int)($row['risk_score'] ?? 0) >= 75 || $row['health_level'] === '危險') ? 'high' : 'medium',
                'title' => '檢視高風險資產 ' . $row['asset_id'],
                'description' => ($row['type'] ?? '-') . ' / 健康度 ' . ($row['health_level'] ?? '-') . ' / 風險分數 ' . ($row['risk_score'] ?? '-'),
                'link' => 'asset_map.php?asset_id=' . urlencode((string)$row['asset_id']),
                'source_id' => $row['asset_id'],
            ];
        }
    }

    return $todos;
}

try {
    require_admin_role();
    $pdo = api_db();

    json_response([
        'ok' => true,
        'data' => [
            'kpis' => dashboard_kpis($pdo),
            'notifications' => recent_notifications($pdo),
            'todos' => manager_todos($pdo, strtolower(current_employee_role())),
            'current_user' => [
                'employee_id' => current_employee_id(),
                'role' => current_employee_role(),
                'name' => (string)($_SESSION['employee_name'] ?? ''),
            ],
        ],
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => '系統錯誤', 'detail' => $e->getMessage()], 500);
}