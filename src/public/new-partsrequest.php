<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
backend_require_roles(['technician', 'assetmanager', 'deptmanager']);

$pdo = backend_db();
$user = backend_user();
$message = '';
$messageType = 'error';
$maintId = trim((string) ($_POST['maint_id'] ?? $_GET['maint_id'] ?? ''));
$maintenance = null;
$parts = [];

try {
    $parts = $pdo->query(
        'SELECT part_id, part_name, stock, safe_stock FROM Partspecs ORDER BY part_id'
    )->fetchAll();

    if ($maintId !== '') {
        $stmt = $pdo->prepare(
            'SELECT m.maint_id, m.asset_id, m.technician_id, m.action, m.details,
                    m.scheduled_time, m.status, p.type AS asset_type, p.sector_id
             FROM Maintenancelog m
             LEFT JOIN Powerasset p ON p.asset_id = m.asset_id
             WHERE m.maint_id = ? LIMIT 1'
        );
        $stmt->execute([$maintId]);
        $maintenance = $stmt->fetch();

        if (!$maintenance) {
            throw new RuntimeException('找不到指定的維修紀錄。');
        }
        if ($user['role'] === 'technician' && (string) $maintenance['technician_id'] !== $user['id']) {
            http_response_code(403);
            exit('403 權限不足：只能替自己的維修工作申請零件。');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        backend_verify_csrf();
        if (!$maintenance) {
            throw new RuntimeException('請先選擇維修紀錄。');
        }

        $partIds = $_POST['part_id'] ?? [];
        $quantities = $_POST['req_qty'] ?? [];
        if (!is_array($partIds) || !is_array($quantities)) {
            throw new RuntimeException('零件資料格式錯誤。');
        }

        $requestLines = [];
        $partCheck = $pdo->prepare('SELECT part_id, part_name FROM Partspecs WHERE part_id = ?');
        foreach ($partIds as $index => $partIdValue) {
            $partId = trim((string) $partIdValue);
            $quantity = (int) ($quantities[$index] ?? 0);
            if ($partId === '' && $quantity === 0) {
                continue;
            }
            if ($partId === '' || $quantity <= 0) {
                throw new RuntimeException('每一項零件都必須填寫零件編號與正整數數量。');
            }
            $partCheck->execute([$partId]);
            $part = $partCheck->fetch();
            if (!$part) {
                throw new RuntimeException('零件編號不存在：' . $partId);
            }
            $requestLines[$partId] = [
                'part_id' => $partId,
                'part_name' => (string) ($part['part_name'] ?? $partId),
                'qty' => ($requestLines[$partId]['qty'] ?? 0) + $quantity,
            ];
        }

        if ($requestLines === []) {
            throw new RuntimeException('請至少新增一項缺少零件。');
        }

        $pendingCheck = $pdo->prepare(
            "SELECT request_id FROM Partsrequest WHERE maint_id = ? AND status = '待審' LIMIT 1"
        );
        $pendingCheck->execute([$maintId]);
        $pendingRequestId = $pendingCheck->fetchColumn();
        if ($pendingRequestId) {
            throw new RuntimeException('此維修工作已有待審中的零件申請：' . $pendingRequestId);
        }

        $pdo->beginTransaction();
        $requestId = 'PR' . date('YmdHis') . random_int(100, 999);
        $stmt = $pdo->prepare(
            'INSERT INTO Partsrequest
             (request_id, maint_id, technician_id, status, request_time)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$requestId, $maintId, (string) $maintenance['technician_id'], '待審']);

        $lineStmt = $pdo->prepare(
            'INSERT INTO Req_part (request_id, part_id, req_qty, status) VALUES (?, ?, ?, ?)'
        );
        foreach ($requestLines as $line) {
            $lineStmt->execute([$requestId, $line['part_id'], $line['qty'], '待審']);
        }

        $pdo->prepare('UPDATE Maintenancelog SET status = ? WHERE maint_id = ?')
            ->execute(['缺件', $maintId]);

        $receivers = $pdo->query(
            "SELECT id_num, role FROM Employees
             WHERE status = 'active' AND role IN ('assetmanager','deptmanager')"
        )->fetchAll();
        $notify = $pdo->prepare(
            'INSERT INTO Notification
             (notification_id, receiver_id, receiver_role, source_type, source_id,
              title, content, is_read, created_at, read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NULL)'
        );
        foreach ($receivers as $receiver) {
            $notify->execute([
                'NTF' . date('YmdHis') . random_int(1000, 9999),
                $receiver['id_num'],
                $receiver['role'],
                '零件申請',
                $requestId,
                '新的維修缺件申請',
                '維修單 ' . $maintId . ' 已送出缺件申請，共 ' . count($requestLines) . ' 種零件。',
            ]);
        }

        $pdo->commit();
        backend_audit('維修缺件申請', '新增', 'Partsrequest', $requestId, null, [
            'maint_id' => $maintId,
            'parts' => array_values($requestLines),
        ]);
        $_SESSION['flash_message'] = '零件申請已送出，維修工作已標記為缺件。';
        $_SESSION['flash_type'] = 'success';
        header('Location: parts-request.php');
        exit;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新增零件申請 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .main{margin-left:210px;padding:20px}.page-card{border:1px solid #ddd;border-radius:8px;padding:18px;background:#fff;max-width:900px}
        .summary{background:#f8f9fa;border-radius:6px;padding:14px;margin-bottom:18px}.summary div{margin:5px 0}
        .part-row{display:grid;grid-template-columns:1fr 160px auto;gap:10px;margin-bottom:10px;align-items:center}
        input,button{padding:9px;font-size:15px}button{cursor:pointer}.error{color:#a40000;background:#ffecec;padding:12px;border-radius:6px}
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
    <h1>新增缺件申請</h1>
    <?php if ($message !== ''): ?><p class="error"><?= backend_e($message) ?></p><?php endif; ?>

    <?php if ($maintenance): ?>
    <div class="page-card">
        <div class="summary">
            <div><strong>維修編號：</strong><?= backend_e($maintenance['maint_id']) ?></div>
            <div><strong>資產：</strong><?= backend_e($maintenance['asset_id'] . ' ' . ($maintenance['asset_type'] ?? '')) ?></div>
            <div><strong>饋線區：</strong><?= backend_e($maintenance['sector_id'] ?? '') ?></div>
            <div><strong>維修項目：</strong><?= nl2br(backend_e($maintenance['action'])) ?></div>
            <div><strong>排定時間：</strong><?= backend_e($maintenance['scheduled_time']) ?></div>
        </div>

        <form method="post">
            <?= backend_csrf_field() ?>
            <input type="hidden" name="maint_id" value="<?= backend_e($maintId) ?>">
            <datalist id="parts-options">
                <?php foreach ($parts as $part): ?>
                    <option value="<?= backend_e($part['part_id']) ?>"><?= backend_e(($part['part_name'] ?? '') . '／庫存 ' . ($part['stock'] ?? 0)) ?></option>
                <?php endforeach; ?>
            </datalist>
            <div id="parts-container">
                <div class="part-row">
                    <input name="part_id[]" list="parts-options" placeholder="零件編號" required>
                    <input name="req_qty[]" type="number" min="1" placeholder="數量" required>
                    <button type="button" onclick="this.parentElement.remove()">刪除</button>
                </div>
            </div>
            <p><button type="button" id="add-part">新增零件</button></p>
            <p><button type="submit">送出申請</button> <a href="maint-todo.php">返回</a></p>
        </form>
    </div>
    <?php else: ?>
        <p>請從維修工作或維修紀錄選擇一筆工作後，再建立缺件申請。</p>
    <?php endif; ?>
</div>
<script>
document.getElementById('add-part')?.addEventListener('click', () => {
    const row = document.createElement('div');
    row.className = 'part-row';
    row.innerHTML = '<input name="part_id[]" list="parts-options" placeholder="零件編號" required>' +
        '<input name="req_qty[]" type="number" min="1" placeholder="數量" required>' +
        '<button type="button" onclick="this.parentElement.remove()">刪除</button>';
    document.getElementById('parts-container').appendChild(row);
});
</script>
</body>
</html>
