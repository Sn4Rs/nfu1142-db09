<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$employeeId = $_SESSION['employee_id'] ?? '';
$employeeName = $_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? '雿輻??;
$employeeRole = $_SESSION['employee_role'] ?? '';

$scheduledWorks = [];

try {
    $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare(
        "SELECT m.maint_id, m.asset_id, m.status, m.scheduled_time, m.action, m.details, e.fullname AS technician_name\n"
        . "FROM Maintenancelog m\n"
        . "LEFT JOIN Employees e ON m.technician_id = e.id_num\n"
        . "WHERE m.technician_id = :employee_id AND m.scheduled_time >= CURDATE()\n"
        . "ORDER BY m.scheduled_time ASC, m.maint_id ASC"
    );
    $stmt->execute([':employee_id' => $employeeId]);
    $scheduledWorks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>蝬凋耨?? - 頝舫??餃?鞈蝞∠?蝟餌絞</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .main {
            margin-left: 210px;
            padding: 20px;
        }
        .headerflex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        .session-box {
            text-align: right;
            font-size: 14px;
            line-height: 1.4;
        }
        .section-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            background: #fff;
            margin-bottom: 24px;
        }
        .section-card h2 {
            margin-top: 0;
        }
        .work-table {
            width: 100%;
            border-collapse: collapse;
        }
        .work-table td,
        .work-table th {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
            vertical-align: middle;
        }
        .work-table th {
            background-color: #f8f9fa;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
            background: #333;
        }
        .action-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .action-links a {
            color: #0066cc;
            text-decoration: none;
        }
        .action-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h3>撠汗??/h3>
        <a href="maintenances.php">擐?</a>
        <a href="assets.php">鞈皜</a>
        <a href="index.php#powerasset-search">擖??</a>
        <a href="maint-history.php">蝬凋耨蝝??/a>
        <a href="maint-todo.php">蝬凋耨撌乩?</a>
        <a href="parts-request.php">?嗡辣?唾?</a>
        <a href="schedule-maint.php">蝬凋耨??</a>
    </div>

    <div class="main">
        <div class="headerflex">
            <h1>蝬凋耨撌乩?</h1>
            <div class="session-box">
                <?php if (!empty($_SESSION['employee_id'])): ?>
                    <div><?= htmlspecialchars($employeeName) ?></div>
                    <div><?= htmlspecialchars($employeeRole) ?></div>
                    <div><a href="logout.php">??撣唾?</a></div>
                <?php else: ?>
                    <a href="login.php">?餃</a>
                <?php endif; ?>
            </div>
        </div>

        <section class="section-card">
            <h2>隞??</h2>
            <?php if (count($scheduledWorks) > 0): ?>
                <table class="work-table">
                    <thead>
                        <tr>
                            <th>?</th>
                            <th>摰???</th>
                            <th>???/th>
                            <th>??</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduledWorks as $index => $work): ?>
                            <tr>
                                <td>?<?= $index + 1 ?> 繚 <?= htmlspecialchars($work['maint_id']) ?></td>
                                <td><?= htmlspecialchars($work['scheduled_time']) ?></td>
                                <td><span class="status-badge"><?= htmlspecialchars($work['status']) ?></span></td>
                                <td class="action-links">
                                    <a href="maint-history.php?record_id=<?= urlencode($work['maint_id']) ?>">?亦?閰單?</a>
                                    <a href="schedule-maint.php?asset_id=<?= urlencode($work['asset_id']) ?>">蝺刻摩??</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>隞撠蝬凋耨????/p>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>

