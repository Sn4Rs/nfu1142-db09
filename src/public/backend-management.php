<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager', 'inspector', 'technician']);

$pdo = backend_db();
$user = backend_user();

$metrics = [
    'assets' => 0,
    'photos' => 0,
    'low_stock' => 0,
    'unread' => 0,
];
$recentImports = [];
$error = '';

try {
    $metrics['assets'] = (int)$pdo->query('SELECT COUNT(*) FROM Powerasset')->fetchColumn();
    $metrics['photos'] = (int)$pdo->query('SELECT COUNT(*) FROM Photos WHERE is_deleted = 0')->fetchColumn();
    $metrics['low_stock'] = (int)$pdo->query('SELECT COUNT(*) FROM Partspecs WHERE COALESCE(stock,0) < COALESCE(safe_stock,0)')->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM Notification
         WHERE is_read = 0 AND (receiver_id = ? OR receiver_role = ?)'
    );
    $stmt->execute([$user['id'], $user['role']]);
    $metrics['unread'] = (int)$stmt->fetchColumn();

    if (backend_role_allowed(['deptmanager', 'assetmanager'])) {
        $recentImports = $pdo->query(
            'SELECT batch_id, file_name, total_count, success_count, fail_count, status, created_at
             FROM Importbatch ORDER BY created_at DESC LIMIT 5'
        )->fetchAll();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

backend_render_header('後端管理總覽', '資產、巡檢、維修、庫存、通知與稽核管理。');
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error">資料讀取失敗：<?= backend_e($error) ?></div>
<?php endif; ?>

<div class="grid grid-4">
    <article class="card"><div class="metric-label">全區資產</div><div class="metric"><?= $metrics['assets'] ?></div></article>
    <article class="card"><div class="metric-label">有效照片</div><div class="metric"><?= $metrics['photos'] ?></div></article>
    <article class="card"><div class="metric-label">低於安全庫存</div><div class="metric"><?= $metrics['low_stock'] ?></div></article>
    <article class="card"><div class="metric-label">未讀通知</div><div class="metric"><?= $metrics['unread'] ?></div></article>
</div>

<div class="grid grid-2" style="margin-top:18px">
    <article class="card">
        <h2>功能入口</h2>
        <div class="grid grid-2">
            <?php foreach (backend_menu_items() as $item): ?>
                <?php if ($item['file'] !== 'backend-management.php' && in_array($user['role'], $item['roles'], true)): ?>
                    <a class="button secondary" href="/<?= backend_e($item['file']) ?>">
                        <?= backend_e($item['label']) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="card">
        <?php if (backend_role_allowed(['deptmanager', 'assetmanager'])): ?>
            <h2>最近匯入紀錄</h2>
            <?php if (!$recentImports): ?>
                <div class="empty">尚無匯入紀錄。</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>檔案</th><th>成功</th><th>失敗</th><th>時間</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentImports as $row): ?>
                            <tr>
                                <td><?= backend_e($row['file_name']) ?></td>
                                <td><?= (int)$row['success_count'] ?></td>
                                <td><?= (int)$row['fail_count'] ?></td>
                                <td><?= backend_e($row['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <h2>權限說明</h2>
            <p>左側只會顯示目前登入身分可以使用的功能；未顯示的功能即無操作權限。</p>
        <?php endif; ?>
    </article>
</div>

<article class="card" style="margin-top:18px">
    <h2>後端管理說明</h2>
    <p>此功能使用原專案的登入 Session 與資料庫。首次使用需套用 migration：</p>
    <p><code class="code-note">Get-Content migrations/20260623_user_backend_features.sql | docker exec -i db09-db mariadb -uroot -pmyPotato</code></p>
    <p>後端入口：<code class="code-note">http://localhost:8080/backend-management.php</code></p>
</article>

<?php backend_render_footer(); ?>
