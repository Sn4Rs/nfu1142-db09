<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-menu.php';

function backend_original_nav_items(string $role): array
{
    return match (backend_normalize_role($role)) {
        'inspector' => [
            ['href' => 'inspections.php', 'label' => '巡檢首頁'],
            ['href' => 'assets.php', 'label' => '資產總表'],
            ['href' => 'inspec-history.php', 'label' => '巡檢紀錄'],
            ['href' => 'new-inspec.php', 'label' => '巡檢回報'],
        ],
        'technician' => [
            ['href' => 'maintenances.php', 'label' => '首頁'],
            ['href' => 'assets.php', 'label' => '資產清單'],
            ['href' => 'index.php#powerasset-search', 'label' => '饋線區'],
            ['href' => 'maint-history.php', 'label' => '維修紀錄'],
            ['href' => 'maint-todo.php', 'label' => '維修工作'],
            ['href' => 'parts-request.php', 'label' => '零件申請'],
            ['href' => 'schedule-maint.php', 'label' => '維修排程'],
        ],
        default => [
            ['href' => 'backend-management.php', 'label' => '首頁'],
            ['href' => 'assets.php', 'label' => '資產清單'],
            ['href' => 'maint-history.php', 'label' => '維修紀錄'],
            ['href' => 'maint-todo.php', 'label' => '維修工作'],
            ['href' => 'parts-request.php', 'label' => '零件申請'],
            ['href' => 'schedule-maint.php', 'label' => '維修排程'],
        ],
    };
}

function backend_render_header(string $title, string $description = ''): void
{
    backend_require_login();
    $user = backend_user();
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $flashes = backend_consume_flashes();
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= backend_e($title) ?>｜後端管理</title>
    <link rel="stylesheet" href="backend-admin.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <h3 class="sidebar-heading">導覽列</h3>
        <nav class="nav-list">
            <?php foreach (backend_original_nav_items($user['role']) as $item): ?>
                <a class="nav-link" href="<?= backend_e($item['href']) ?>"><?= backend_e($item['label']) ?></a>
            <?php endforeach; ?>

            <div class="nav-section-title">後端管理</div>
            <?php foreach (backend_menu_items() as $item): ?>
                <?php if (in_array($user['role'], $item['roles'], true)): ?>
                    <a class="nav-link<?= $current === $item['file'] ? ' active' : '' ?>"
                       href="<?= backend_e($item['file']) ?>"><?= backend_e($item['label']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <strong><?= backend_e($user['name']) ?></strong>
            <span><?= backend_e(backend_role_label($user['role'])) ?></span>
            <a href="logout.php">登出</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="page-header">
            <div>
                <h1><?= backend_e($title) ?></h1>
                <?php if ($description !== ''): ?><p><?= backend_e($description) ?></p><?php endif; ?>
            </div>
            <div class="identity-card">
                <span>目前身分</span>
                <strong><?= backend_e(backend_role_label($user['role'])) ?></strong>
            </div>
        </header>

        <?php if (!backend_migration_ready()): ?>
            <div class="alert alert-warning">尚未套用後端功能 migration，請先執行 <code>migrations/20260623_user_backend_features.sql</code>。</div>
        <?php endif; ?>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= backend_e($flash['type'] ?? 'info') ?>"><?= backend_e($flash['message'] ?? '') ?></div>
        <?php endforeach; ?>

        <section class="page-content">
    <?php
}

function backend_render_footer(): void
{
    ?>
        </section>
    </main>
</div>
</body>
</html>
    <?php
}
