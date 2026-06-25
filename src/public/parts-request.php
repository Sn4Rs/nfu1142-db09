<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
backend_require_roles(['technician', 'assetmanager', 'deptmanager']);

$pdo = backend_db();
$user = backend_user();
$isManager = in_array($user['role'], ['assetmanager', 'deptmanager'], true);
$message = (string) ($_SESSION['flash_message'] ?? '');
$messageType = (string) ($_SESSION['flash_type'] ?? 'success');
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    backend_verify_csrf();
    if (!$isManager) {
        http_response_code(403);
        exit('403 權限不足');
    }

    $requestId = trim((string) ($_POST['request_id'] ?? ''));
    $decision = (string) ($_POST['decision'] ?? '');
    $rejectReason = trim((string) ($_POST['reject_reason'] ?? ''));

    try {
        $pdo->beginTransaction();
        $requestStmt = $pdo->prepare('SELECT * FROM Partsrequest WHERE request_id = ? FOR UPDATE');
        $requestStmt->execute([$requestId]);
        $request = $requestStmt->fetch();
        if (!$request) {
            throw new RuntimeException('找不到申請單。');
        }
        if ((string) $request['status'] !== '待審') {
            throw new RuntimeException('此申請單已經完成審核。');
        }

        $lineStmt = $pdo->prepare(
            'SELECT r.request_id, r.part_id, r.req_qty, p.part_name, p.stock
             FROM Req_part r
             LEFT JOIN Partspecs p ON p.part_id = r.part_id
             WHERE r.request_id = ? FOR UPDATE'
        );
        $lineStmt->execute([$requestId]);
        $lines = $lineStmt->fetchAll();

        if ($decision === 'approve') {
            if ($lines === []) {
                throw new RuntimeException('申請單沒有零件明細。');
            }
            foreach ($lines as $line) {
                if ($line['stock'] === null) {
                    throw new RuntimeException('找不到零件：' . $line['part_id']);
                }
                if ((int) $line['stock'] < (int) $line['req_qty']) {
                    throw new RuntimeException('庫存不足：' . $line['part_id'] . '，目前庫存 ' . (int) $line['stock']);
                }
            }

            $stockUpdate = $pdo->prepare(
                'UPDATE Partspecs SET stock = stock - ?, last_check_time = NOW() WHERE part_id = ?'
            );
            $usageInsert = $pdo->prepare(
                'INSERT INTO Maintenanceparts (part_id, maint_id, qty_used)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE qty_used = qty_used + VALUES(qty_used)'
            );
            foreach ($lines as $line) {
                $stockUpdate->execute([(int) $line['req_qty'], $line['part_id']]);
                $usageInsert->execute([$line['part_id'], $request['maint_id'], (int) $line['req_qty']]);
            }

            $costStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(mp.qty_used * COALESCE(ps.unit_cost, 0)), 0)
                 FROM Maintenanceparts mp
                 LEFT JOIN Partspecs ps ON ps.part_id = mp.part_id
                 WHERE mp.maint_id = ?'
            );
            $costStmt->execute([$request['maint_id']]);
            $materialCost = (float) $costStmt->fetchColumn();
            $pdo->prepare(
                'UPDATE Maintenancelog
                 SET material_cost = ?, total_cost = COALESCE(labor_cost, 0) + ?
                 WHERE maint_id = ?'
            )->execute([$materialCost, $materialCost, $request['maint_id']]);

            $pdo->prepare(
                'UPDATE Partsrequest SET status = ?, approved_by = ?, approved_at = NOW(), reject_reason = NULL
                 WHERE request_id = ?'
            )->execute(['批准', $user['id'], $requestId]);
            $pdo->prepare('UPDATE Req_part SET status = ? WHERE request_id = ?')
                ->execute(['批准', $requestId]);
            $pdo->prepare('UPDATE Maintenancelog SET status = ? WHERE maint_id = ?')
                ->execute(['待處理', $request['maint_id']]);

            $title = '零件申請已核准';
            $content = '申請單 ' . $requestId . ' 已核准並完成庫存扣帳，維修工作已重新加入待辦。';
            $message = '申請已核准，庫存已更新，維修工作已重新加入待辦。';
        } elseif ($decision === 'reject') {
            if ($rejectReason === '') {
                throw new RuntimeException('駁回時必須填寫原因。');
            }
            $pdo->prepare(
                'UPDATE Partsrequest SET status = ?, approved_by = ?, approved_at = NOW(), reject_reason = ?
                 WHERE request_id = ?'
            )->execute(['駁回', $user['id'], $rejectReason, $requestId]);
            $pdo->prepare('UPDATE Req_part SET status = ? WHERE request_id = ?')
                ->execute(['駁回', $requestId]);

            $title = '零件申請遭駁回';
            $content = '申請單 ' . $requestId . ' 已駁回。原因：' . $rejectReason;
            $message = '申請已駁回。';
        } else {
            throw new RuntimeException('未知的審核動作。');
        }

        $notify = $pdo->prepare(
            'INSERT INTO Notification
             (notification_id, receiver_id, receiver_role, source_type, source_id,
              title, content, is_read, created_at, read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NULL)'
        );
        $notify->execute([
            'NTF' . date('YmdHis') . random_int(1000, 9999),
            $request['technician_id'],
            'Technician',
            '零件申請',
            $requestId,
            $title,
            $content,
        ]);

        $pdo->commit();
        backend_audit('零件申請審核', '簽核', 'Partsrequest', $requestId, ['status' => '待審'], [
            'status' => $decision === 'approve' ? '批准' : '駁回',
            'reason' => $rejectReason,
        ]);
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'success';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: parts-request.php');
    exit;
}

$where = [];
$params = [];
if (!$isManager) {
    $where[] = 'pr.technician_id = ?';
    $params[] = $user['id'];
}

$sql = 'SELECT pr.request_id, pr.maint_id, pr.technician_id, pr.status AS request_status,
               pr.request_time, pr.approved_by, pr.approved_at, pr.reject_reason,
               ml.asset_id, ml.action, ml.status AS maint_status,
               tech.fullname AS technician_name, approver.fullname AS approver_name
        FROM Partsrequest pr
        LEFT JOIN Maintenancelog ml ON pr.maint_id = ml.maint_id
        LEFT JOIN Employees tech ON tech.id_num = pr.technician_id
        LEFT JOIN Employees approver ON approver.id_num = pr.approved_by';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY pr.request_time DESC, pr.request_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$partsByRequest = [];
if ($requests !== []) {
    $ids = array_column($requests, 'request_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT r.request_id, r.part_id, r.req_qty, r.status, p.part_name, p.stock
         FROM Req_part r LEFT JOIN Partspecs p ON p.part_id = r.part_id
         WHERE r.request_id IN ($placeholders) ORDER BY r.request_id, r.part_id"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $line) {
        $partsByRequest[$line['request_id']][] = $line;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>零件申請列表</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .main{margin-left:210px;padding:20px}.section-card{border:1px solid #ddd;border-radius:8px;padding:16px;background:#fff;margin-bottom:18px}
        .header-row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}.status-badge{display:inline-block;padding:3px 9px;border-radius:999px;background:#333;color:#fff;font-size:13px}
        .work-table{width:100%;border-collapse:collapse;margin-top:12px}.work-table th,.work-table td{border:1px solid #ddd;padding:10px;text-align:left}
        .flash{padding:12px;border-radius:6px;margin-bottom:16px}.flash.success{background:#e7f7ec;color:#155724}.flash.error{background:#fdeaea;color:#8b1a1a}
        .review{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.review input{min-width:260px;padding:8px}.review button{padding:8px 12px}
    </style>
</head>
<body>
<div class="sidebar">
    <h3>導覽列</h3>
    <a href="maintenances.php">首頁</a>
    <a href="assets.php">資產清單</a>
    <a href="index.php#powerasset-search">饋線區</a>
    <a href="maint-history.php">維修紀錄</a>
    <a href="maint-todo.php">維修工作</a>
    <a href="parts-request.php">零件申請</a>
    <a href="schedule-maint.php">維修排程</a>
    <?php require_once __DIR__ . '/backend-menu.php'; backend_render_menu(); ?>
</div>
<div class="main">
    <div class="header-row"><h1>零件申請列表</h1><div><?= backend_e($user['name']) ?>／<?= backend_e(backend_role_label($user['role'])) ?></div></div>
    <?php if ($message !== ''): ?><div class="flash <?= backend_e($messageType) ?>"><?= backend_e($message) ?></div><?php endif; ?>

    <?php foreach ($requests as $request): ?>
        <?php $lines = $partsByRequest[$request['request_id']] ?? []; ?>
        <section class="section-card">
            <div class="header-row">
                <div><strong><?= backend_e($request['request_id']) ?></strong> <span class="status-badge"><?= backend_e($request['request_status']) ?></span></div>
                <div><?= backend_e($request['request_time']) ?></div>
            </div>
            <p>維修單：<?= backend_e($request['maint_id']) ?>｜資產：<?= backend_e($request['asset_id']) ?>｜技術員：<?= backend_e($request['technician_name'] ?: $request['technician_id']) ?></p>
            <p>維修項目：<?= nl2br(backend_e($request['action'])) ?></p>
            <?php if (!empty($request['reject_reason'])): ?><p>駁回原因：<?= backend_e($request['reject_reason']) ?></p><?php endif; ?>
            <table class="work-table">
                <thead><tr><th>零件</th><th>申請數量</th><th>目前庫存</th><th>狀態</th></tr></thead>
                <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr><td><?= backend_e($line['part_id'] . ' ' . ($line['part_name'] ?? '')) ?></td><td><?= (int) $line['req_qty'] ?></td><td><?= (int) ($line['stock'] ?? 0) ?></td><td><?= backend_e($line['status']) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($lines === []): ?><tr><td colspan="4">無零件資料</td></tr><?php endif; ?>
                </tbody>
            </table>

            <?php if ($isManager && $request['request_status'] === '待審'): ?>
                <form method="post" class="review">
                    <?= backend_csrf_field() ?>
                    <input type="hidden" name="request_id" value="<?= backend_e($request['request_id']) ?>">
                    <button name="decision" value="approve" type="submit">核准並扣除庫存</button>
                    <input name="reject_reason" placeholder="駁回原因">
                    <button name="decision" value="reject" type="submit">駁回</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <?php if ($requests === []): ?><p>目前沒有零件申請。</p><?php endif; ?>
</div>
</body>
</html>
