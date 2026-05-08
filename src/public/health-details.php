<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// connect to db
try {
    $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";port=" . $_ENV['DB_PORT'];
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// get asset_id from GET and query all related history logs
$asset_id = $_GET['id'] ?? null;
$history = [];
$inspections = [];
$maintenance = [];

if ($asset_id) {
    // 1. Fetch Health History, fk ref by asset_id
    $stmt = $pdo->prepare("SELECT * FROM Health WHERE asset_id = ? ORDER BY valid_from DESC");
    $stmt->execute([$asset_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC); // fetch health timeline

    // 2. Fetch Inspection Logs, ref by fk log_id in InspectionLog
    $stmt = $pdo->prepare("SELECT il.*, i.name as inspector_name 
                           FROM InspectionLog il 
                           JOIN Inspector i ON il.inspector_id = i.inspector_id 
                           WHERE il.asset_id = ? 
                           ORDER BY il.inspec_time DESC");
    $stmt->execute([$asset_id]);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC); // fetch inspection history

    // 3. Fetch Maintenance Logs linked to asset
    $stmt = $pdo->prepare("SELECT * FROM MaintenanceLog WHERE asset_id = ? ORDER BY maint_time DESC");
    $stmt->execute([$asset_id]);
    $maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC); // fetch maintenance records
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<!--41243214-->

<head>
    <meta charset="UTF-8">
    <title>資產履歷 - <?= htmlspecialchars($asset_id) ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <a href="assets.php" class="back-link">← 返回資產總表</a>

    <h1>資產詳情: <?= htmlspecialchars($asset_id) ?></h1>

    <!-- 1. Health Section -->
    <h2>健康狀態</h2>
    <?php if (count($history) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>檢測日期</th>
                    <th>壽命總長</th>
                    <th>健康度</th>
                    <th>目前狀態</th>
                    <th>預估運作時間</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['last_inspection_date']) ?></td>
                        <td><?= htmlspecialchars($record['expected_lifespan']) ?> 年</td>
                        <td class="status-<?= htmlspecialchars($record['health_level']) ?>">
                            <?= htmlspecialchars($record['health_level']) ?>
                        </td>
                        <td><?= htmlspecialchars($record['current_status']) ?></td>
                        <td>
                            <?= htmlspecialchars($record['valid_from']) ?> ~
                            <?php
                            // calculate estimated end of life: valid_from + expected_lifespan
                            if (!empty($record['valid_to'])) {
                                echo htmlspecialchars($record['valid_to']);
                            } else {
                                $startDate = new DateTime($record['valid_from']);
                                $lifespan = (int) $record['expected_lifespan'];
                                $endDate = $startDate->modify("+$lifespan years");
                                echo htmlspecialchars($endDate->format('Y-m-d'));
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>無健康紀錄。</p>
    <?php endif; ?>

    <!-- 2. Inspection Section -->
    <h2>檢查紀錄</h2>
    <?php if (count($inspections) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>時間</th>
                    <th>檢查員</th>
                    <th>風險分數</th>
                    <th>觀察描述</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inspections as $inspec): ?>
                    <tr>
                        <td><?= htmlspecialchars($inspec['inspec_time']) ?></td>
                        <td><?= htmlspecialchars($inspec['inspector_name']) ?></td>
                        <td>
                            <span
                                class="badge <?= $inspec['risk_score'] >= 70 ? 'bg-danger' : ($inspec['risk_score'] >= 40 ? 'bg-warning' : 'bg-success') ?>">
                                <?= htmlspecialchars($inspec['risk_score']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($inspec['observation']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>無檢查紀錄。</p>
    <?php endif; ?>

    <!-- 3. Maintenance Section -->
    <h2>維修紀錄</h2>
    <?php if (count($maintenance) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>維修時間</th>
                    <th>維修人員</th>
                    <th>描述</th>
                    <th>狀態</th>
                    <th>對應檢查編號</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($maintenance as $maint): ?>
                    <tr>
                        <td><?= htmlspecialchars($maint['maint_time']) ?></td>
                        <td><?= htmlspecialchars($maint['repairman']) ?></td>
                        <td><?= htmlspecialchars($maint['description']) ?></td>
                        <td><?= htmlspecialchars($maint['completion_status']) ?></td>
                        <td><?= htmlspecialchars($maint['log_id'] ?? '無') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>無維修紀錄。</p>
    <?php endif; ?>

</body>

</html>