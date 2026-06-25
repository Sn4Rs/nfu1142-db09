<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager', 'inspector', 'technician']);

$pdo = backend_db();
$user = backend_user();

function backend_notification_link(array $row): string
{
    $type = (string)($row['source_type'] ?? '');
    $id = urlencode((string)($row['source_id'] ?? ''));

    return match ($type) {
        '巡檢', '巡檢任務' => '/inspec-history.php?record_id=' . $id,
        '維修', '維修工單' => '/maint-history.php?record_id=' . $id,
        '缺件', '零件申請' => '/parts-request.php',
        '補貨提醒' => '/backend-stock-alert.php',
        default => '/backend-management.php',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    backend_verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'read_one') {
            $id = trim((string)($_POST['notification_id'] ?? ''));
            $stmt = $pdo->prepare(
                'UPDATE Notification SET is_read = 1, read_at = NOW()
                 WHERE notification_id = ? AND (receiver_id = ? OR receiver_role = ?)'
            );
            $stmt->execute([$id, $user['id'], $user['role']]);
            backend_audit('待辦事項與角色通知', '修改', 'Notification', $id, ['is_read' => 0], ['is_read' => 1]);
        } elseif ($action === 'read_all') {
            $stmt = $pdo->prepare(
                'UPDATE Notification SET is_read = 1, read_at = NOW()
                 WHERE is_read = 0 AND (receiver_id = ? OR receiver_role = ?)'
            );
            $stmt->execute([$user['id'], $user['role']]);
            backend_audit('待辦事項與角色通知', '修改', 'Notification', 'ALL', null, ['marked_read' => $stmt->rowCount()]);
            backend_flash('success', '已將所有通知標記為已讀。');
        }
    } catch (Throwable $e) {
        backend_flash('error', $e->getMessage());
    }

    backend_redirect('/backend-notifications.php');
}

$status = (string)($_GET['status'] ?? 'all');
$where = ['(receiver_id = ? OR receiver_role = ?)'];
$params = [$user['id'], $user['role']];
if ($status === 'unread') {
    $where[] = 'is_read = 0';
} elseif ($status === 'read') {
    $where[] = 'is_read = 1';
}

$stmt = $pdo->prepare(
    'SELECT * FROM Notification WHERE ' . implode(' AND ', $where) . '
     ORDER BY is_read ASC, created_at DESC LIMIT 200'
);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$countStmt = $pdo->prepare(
    'SELECT SUM(is_read = 0) AS unread_count, SUM(is_read = 1) AS read_count, COUNT(*) AS total_count
     FROM Notification WHERE receiver_id = ? OR receiver_role = ?'
);
$countStmt->execute([$user['id'], $user['role']]);
$counts = $countStmt->fetch() ?: ['unread_count' => 0, 'read_count' => 0, 'total_count' => 0];

backend_render_header('待辦事項與角色通知', '依登入身分顯示巡檢、維修、缺件、補貨與主管簽核通知。');
?>

<div class="grid grid-3">
    <article class="card"><div class="metric-label">全部通知</div><div class="metric"><?= (int)$counts['total_count'] ?></div></article>
    <article class="card"><div class="metric-label">未讀</div><div class="metric"><?= (int)$counts['unread_count'] ?></div></article>
    <article class="card"><div class="metric-label">已讀</div><div class="metric"><?= (int)$counts['read_count'] ?></div></article>
</div>

<article class="card" style="margin-top:18px">
    <div class="toolbar">
        <a class="button<?= $status === 'all' ? '' : ' secondary' ?>" href="?status=all">全部</a>
        <a class="button<?= $status === 'unread' ? '' : ' secondary' ?>" href="?status=unread">未讀</a>
        <a class="button<?= $status === 'read' ? '' : ' secondary' ?>" href="?status=read">已讀</a>
        <form method="post" style="margin-left:auto">
            <?= backend_csrf_field() ?>
            <input type="hidden" name="action" value="read_all">
            <button class="secondary" type="submit">全部標記已讀</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead><tr><th>狀態</th><th>來源</th><th>標題</th><th>內容</th><th>建立時間</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($notifications as $row): ?>
                <tr>
                    <td>
                        <span class="badge <?= (int)$row['is_read'] === 1 ? 'badge-muted' : 'badge-warning' ?>">
                            <?= (int)$row['is_read'] === 1 ? '已讀' : '未讀' ?>
                        </span>
                    </td>
                    <td><?= backend_e($row['source_type']) ?></td>
                    <td><?= backend_e($row['title']) ?></td>
                    <td><?= backend_e($row['content']) ?></td>
                    <td><?= backend_e($row['created_at']) ?></td>
                    <td>
                        <a href="<?= backend_e(backend_notification_link($row)) ?>">查看詳情</a>
                        <?php if ((int)$row['is_read'] === 0): ?>
                            <form method="post" class="inline-form">
                                <?= backend_csrf_field() ?>
                                <input type="hidden" name="action" value="read_one">
                                <input type="hidden" name="notification_id" value="<?= backend_e($row['notification_id']) ?>">
                                <button class="small secondary" type="submit">標記已讀</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$notifications): ?><tr><td colspan="6" class="empty">目前沒有通知。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php backend_render_footer(); ?>
