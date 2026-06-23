<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager']);

$pdo = backend_db();

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$technician = trim((string)($_GET['technician_id'] ?? ''));
$assetId = trim((string)($_GET['asset_id'] ?? ''));
$sectorId = trim((string)($_GET['sector_id'] ?? ''));

$where = ['1=1'];
$params = [];
if ($dateFrom !== '') { $where[] = 'DATE(COALESCE(m.maint_time, m.scheduled_time)) >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(COALESCE(m.maint_time, m.scheduled_time)) <= ?'; $params[] = $dateTo; }
if ($technician !== '') { $where[] = 'm.technician_id = ?'; $params[] = $technician; }
if ($assetId !== '') { $where[] = 'm.asset_id = ?'; $params[] = $assetId; }
if ($sectorId !== '') { $where[] = 'p.sector_id = ?'; $params[] = $sectorId; }

$sql = 'SELECT m.maint_id, m.asset_id, m.technician_id, e.fullname AS technician_name,
               p.sector_id, s.feeder_area, m.action, m.details, m.status,
               m.maint_time, m.scheduled_time, COALESCE(m.work_hours,0) AS work_hours,
               COALESCE(m.labor_cost,0) AS labor_cost,
               COALESCE(m.material_cost,0) AS material_cost,
               COALESCE(m.total_cost, COALESCE(m.labor_cost,0)+COALESCE(m.material_cost,0)) AS total_cost
        FROM Maintenancelog m
        LEFT JOIN Employees e ON e.id_num = m.technician_id
        LEFT JOIN Powerasset p ON p.asset_id = m.asset_id
        LEFT JOIN Sector s ON s.sector_id = p.sector_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY COALESCE(m.maint_time, m.scheduled_time) DESC, m.maint_id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    backend_audit('維修成本統計與報表', '匯出', 'Maintenancelog', 'CSV', null, ['filters' => $_GET]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="maintenance-report-' . date('YmdHis') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['維修編號','日期','資產','饋線區','維修員','維修項目','狀態','工時','工時費','材料費','總成本']);
    foreach ($records as $row) {
        fputcsv($out, [
            $row['maint_id'],
            $row['maint_time'] ?: $row['scheduled_time'],
            $row['asset_id'],
            $row['sector_id'],
            $row['technician_name'] ?: $row['technician_id'],
            $row['action'],
            $row['status'],
            $row['work_hours'],
            $row['labor_cost'],
            $row['material_cost'],
            $row['total_cost'],
        ]);
    }
    fclose($out);
    exit;
}

$summary = ['count' => count($records), 'labor' => 0.0, 'material' => 0.0, 'total' => 0.0, 'hours' => 0.0];
$monthly = [];
foreach ($records as $row) {
    $summary['labor'] += (float)$row['labor_cost'];
    $summary['material'] += (float)$row['material_cost'];
    $summary['total'] += (float)$row['total_cost'];
    $summary['hours'] += (float)$row['work_hours'];
    $date = $row['maint_time'] ?: $row['scheduled_time'];
    $month = $date ? date('Y-m', strtotime((string)$date)) : '未排程';
    $monthly[$month] = ($monthly[$month] ?? 0) + (float)$row['total_cost'];
}
krsort($monthly);
$maxMonthly = $monthly ? max($monthly) : 0;

$technicians = $pdo->query("SELECT id_num, fullname FROM Employees WHERE role='Technician' ORDER BY fullname")->fetchAll();
$sectors = $pdo->query('SELECT sector_id, feeder_area FROM Sector ORDER BY sector_id')->fetchAll();

backend_render_header('維修成本統計與報表', '依日期、維修員、資產及饋線區篩選，彙整工時與材料成本。');
?>

<article class="card">
    <form method="get" class="form-grid">
        <label>開始日期<input type="date" name="date_from" value="<?= backend_e($dateFrom) ?>"></label>
        <label>結束日期<input type="date" name="date_to" value="<?= backend_e($dateTo) ?>"></label>
        <label>維修員
            <select name="technician_id"><option value="">全部</option>
                <?php foreach ($technicians as $row): ?>
                    <option value="<?= backend_e($row['id_num']) ?>" <?= $technician === $row['id_num'] ? 'selected' : '' ?>><?= backend_e($row['fullname']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>資產編號<input name="asset_id" value="<?= backend_e($assetId) ?>"></label>
        <label>饋線區
            <select name="sector_id"><option value="">全部</option>
                <?php foreach ($sectors as $row): ?>
                    <option value="<?= backend_e($row['sector_id']) ?>" <?= $sectorId === $row['sector_id'] ? 'selected' : '' ?>><?= backend_e($row['sector_id'] . ' ' . ($row['feeder_area'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div><button type="submit">查詢</button> <a class="button secondary" href="backend-maintenance-report.php">清除</a></div>
    </form>
</article>

<div class="grid grid-4" style="margin-top:18px">
    <article class="card"><div class="metric-label">維修次數</div><div class="metric"><?= $summary['count'] ?></div></article>
    <article class="card"><div class="metric-label">總工時</div><div class="metric"><?= number_format($summary['hours'], 1) ?></div></article>
    <article class="card"><div class="metric-label">材料費</div><div class="metric">NT$ <?= number_format($summary['material']) ?></div></article>
    <article class="card"><div class="metric-label">總成本</div><div class="metric">NT$ <?= number_format($summary['total']) ?></div></article>
</div>

<div class="grid grid-2" style="margin-top:18px">
    <article class="card">
        <h2>每月費用趨勢</h2>
        <div class="progress-list">
            <?php foreach (array_slice($monthly, 0, 12, true) as $month => $amount): ?>
                <?php $width = $maxMonthly > 0 ? max(2, ($amount / $maxMonthly) * 100) : 0; ?>
                <div class="progress-row">
                    <span><?= backend_e($month) ?></span>
                    <div class="progress-track"><div class="progress-bar" style="width:<?= number_format($width, 2, '.', '') ?>%"></div></div>
                    <strong><?= number_format($amount) ?></strong>
                </div>
            <?php endforeach; ?>
            <?php if (!$monthly): ?><div class="empty">目前沒有資料。</div><?php endif; ?>
        </div>
    </article>

    <article class="card">
        <h2>報表輸出</h2>
        <p>平均每次成本：<strong>NT$ <?= number_format($summary['count'] > 0 ? $summary['total'] / $summary['count'] : 0) ?></strong></p>
        <p>工時費：<strong>NT$ <?= number_format($summary['labor']) ?></strong></p>
        <a class="button" href="?<?= backend_e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">匯出 Excel 可開啟的 CSV</a>
        <button class="secondary" type="button" onclick="window.print()">列印／另存 PDF</button>
    </article>
</div>

<article class="card" style="margin-top:18px">
    <h2>報表明細</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>日期</th><th>維修編號</th><th>資產</th><th>饋線區</th><th>維修員</th><th>項目</th><th>狀態</th><th>工時</th><th>材料費</th><th>總成本</th></tr></thead>
            <tbody>
            <?php foreach ($records as $row): ?>
                <tr>
                    <td><?= backend_e($row['maint_time'] ?: $row['scheduled_time']) ?></td>
                    <td><?= backend_e($row['maint_id']) ?></td>
                    <td><?= backend_e($row['asset_id']) ?></td>
                    <td><?= backend_e($row['sector_id']) ?></td>
                    <td><?= backend_e($row['technician_name'] ?: $row['technician_id']) ?></td>
                    <td><?= backend_e($row['action']) ?></td>
                    <td><?= backend_e($row['status']) ?></td>
                    <td><?= number_format((float)$row['work_hours'], 1) ?></td>
                    <td>NT$ <?= number_format((float)$row['material_cost']) ?></td>
                    <td>NT$ <?= number_format((float)$row['total_cost']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$records): ?><tr><td colspan="10" class="empty">沒有符合條件的維修紀錄。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php backend_render_footer(); ?>
