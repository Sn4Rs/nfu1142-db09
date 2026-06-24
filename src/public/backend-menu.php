<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';

function backend_menu_items(): array
{
    return [
        ['file' => 'backend-management.php', 'label' => '後端總覽', 'roles' => ['deptmanager', 'assetmanager', 'inspector', 'technician']],
        ['file' => 'backend-asset-import.php', 'label' => '資料匯入', 'roles' => ['deptmanager', 'assetmanager']],
        ['file' => 'backend-photo-manager.php', 'label' => '巡檢附件', 'roles' => ['deptmanager', 'assetmanager', 'inspector']],
        ['file' => 'backend-maintenance-report.php', 'label' => '維修報表', 'roles' => ['deptmanager', 'assetmanager']],
        ['file' => 'backend-stock-alert.php', 'label' => '庫存提醒', 'roles' => ['deptmanager', 'assetmanager', 'technician']],
        ['file' => 'backend-notifications.php', 'label' => '角色通知', 'roles' => ['deptmanager', 'assetmanager', 'inspector', 'technician']],
        ['file' => 'backend-audit-log.php', 'label' => '權限稽核', 'roles' => ['deptmanager', 'assetmanager']],
        ['file' => 'admin_dashboard.php', 'label' => '管理後台總覽', 'roles' => ['deptmanager', 'assetmanager']],
        ['file' => 'asset_map.php', 'label' => '資產地圖導覽', 'roles' => ['deptmanager', 'assetmanager']],
        ['file' => 'admin_sector.php', 'label' => '饋線領地管理', 'roles' => ['deptmanager', 'assetmanager']],
        ['file' => 'risk_warning.php', 'label' => '智慧預警中心', 'roles' => ['deptmanager', 'assetmanager']],
        ['file' => 'stock_review.php', 'label' => '後台主管審核', 'roles' => ['deptmanager', 'assetmanager']],
    ];
}

function backend_render_menu(): void
{
    $user = backend_user();
    if ($user['id'] === '') {
        return;
    }

    $visible = array_values(array_filter(
        backend_menu_items(),
        static fn(array $item): bool => in_array($user['role'], $item['roles'], true)
    ));

    if ($visible === []) {
        return;
    }

    echo '<div class="backend-menu-divider" style="margin:18px 12px 6px;padding-top:14px;border-top:1px solid rgba(255,255,255,.25);font-weight:700;color:#fff;">後端管理</div>';
    foreach ($visible as $item) {
        echo '<a href="' . backend_e($item['file']) . '">' . backend_e($item['label']) . '</a>';
    }
}
