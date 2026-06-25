<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager', 'technician']);

$pdo = backend_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    backend_verify_csrf();
    $partId = trim((string)($_POST['part_id'] ?? ''));

    try {
        $stmt = $pdo->prepare('SELECT * FROM Partspecs WHERE part_id = ?');
        $stmt->execute([$partId]);
        $part = $stmt->fetch();
        if (!$part) {
            throw new RuntimeException('找不到零件。');
        }

        $receivers = $pdo->query("SELECT id_num, role FROM Employees WHERE status='active' AND role IN ('assetmanager','deptmanager')")->fetchAll();
        $insert = $pdo->prepare(
            'INSERT INTO Notification
             (notification_id, receiver_id, receiver_role, source_type, source_id, title, content, is_read, created_at, read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NULL)'
        );

        foreach ($receivers as $receiver) {
            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM Notification
                 WHERE receiver_id = ? AND source_type = ? AND source_id = ? AND is_read = 0'
            );
            $check->execute([$receiver['id_num'], '補貨提醒', $partId]);
            if ((int)$check->fetchColumn() > 0) {
                continue;
            }

            $insert->execute([
                'NTF' . date('YmdHis') . random_int(1000, 9999),
                $receiver['id_num'],
                $receiver['role'],
                '補貨提醒',
                $partId,
                '零件庫存不足：' . ($part['part_name'] ?: $partId),
                '現有庫存 ' . (int)$part['stock'] . '，安全庫存 ' . (int)$part['safe_stock'] . '，建議補貨 ' . (int)$part['reorder_qty'] . '。',
            ]);
        }

        backend_audit('零件安全庫存提醒', '新增', 'Partspecs', $partId, null, ['notification' => '補貨提醒']);
        backend_flash('success', '已建立補貨提醒並通知主管與資產管理員。');
    } catch (Throwable $e) {
        backend_flash('error', $e->getMessage());
    }

    backend_redirect('/backend-stock-alert.php');
}

$parts = $pdo->query('SELECT * FROM Partspecs ORDER BY (COALESCE(stock,0)-COALESCE(safe_stock,0)) ASC, part_id')->fetchAll();
$stats = ['low' => 0, 'near' => 0, 'ok' => 0];
foreach ($parts as &$part) {
    $stock = (int)($part['stock'] ?? 0);
    $safe = (int)($part['safe_stock'] ?? 0);
    $reorder = max(1, (int)($part['reorder_qty'] ?? 1));

    if ($stock < $safe) {
        $part['level'] = 'low';
        $stats['low']++;
    } elseif ($stock <= $safe + $reorder) {
        $part['level'] = 'near';
        $stats['near']++;
    } else {
        $part['level'] = 'ok';
        $stats['ok']++;
    }
}
unset($part);

backend_render_header('零件安全庫存提醒', '比對現有庫存與安全量，產生低庫存清單及補貨通知。');
?>

<div class="grid grid-3">
    <article class="card"><div class="metric-label">低於安全量</div><div class="metric"><?= $stats['low'] ?></div></article>
    <article class="card"><div class="metric-label">即將不足</div><div class="metric"><?= $stats['near'] ?></div></article>
    <article class="card"><div class="metric-label">庫存充足</div><div class="metric"><?= $stats['ok'] ?></div></article>
</div>

<article class="card" style="margin-top:18px">
    <h2>庫存清單</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>零件編號</th><th>名稱</th><th>現有庫存</th><th>安全庫存</th><th>建議補貨</th><th>供應商</th><th>狀態</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($parts as $part): ?>
                <?php
                $badge = $part['level'] === 'low' ? 'badge-danger' : ($part['level'] === 'near' ? 'badge-warning' : 'badge-success');
                $label = $part['level'] === 'low' ? '低庫存' : ($part['level'] === 'near' ? '即將不足' : '充足');
                ?>
                <tr>
                    <td><?= backend_e($part['part_id']) ?></td>
                    <td><?= backend_e($part['part_name']) ?></td>
                    <td><?= (int)$part['stock'] ?></td>
                    <td><?= (int)$part['safe_stock'] ?></td>
                    <td><?= (int)$part['reorder_qty'] ?></td>
                    <td><?= backend_e($part['provider']) ?></td>
                    <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                    <td>
                        <?php if ($part['level'] !== 'ok'): ?>
                            <form method="post" class="inline-form">
                                <?= backend_csrf_field() ?>
                                <input type="hidden" name="part_id" value="<?= backend_e($part['part_id']) ?>">
                                <button class="small" type="submit">建立補貨提醒</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$parts): ?><tr><td colspan="8" class="empty">尚無零件資料。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php backend_render_footer(); ?>
