<?php
session_start();
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$recentChanges = [];
$todayWorks = [];

$employeeId = $_SESSION['employee_id'] ?? '';
$employeeRole = $_SESSION['employee_role'] ?? '';

$dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ----------------------------
// Load all parts requests
// ----------------------------
$stmt = $pdo->query(
    "SELECT 
        pr.request_id,
        pr.maint_id,
        pr.technician_id,
        pr.status AS request_status,
        pr.request_time,

        ml.asset_id,
        ml.action,
        ml.status AS maint_status

     FROM Partsrequest pr
     LEFT JOIN Maintenancelog ml 
        ON pr.maint_id = ml.maint_id
     ORDER BY pr.request_time DESC"
);

$requests = $stmt->fetchAll();


// ----------------------------
// Load all request parts (grouped later)
// ----------------------------
$partStmt = $pdo->query(
    "SELECT 
        request_id,
        part_id,
        req_qty,
        status
     FROM Req_part"
);

$partsRaw = $partStmt->fetchAll();


// group by request_id
$partsByRequest = [];
foreach ($partsRaw as $p) {
    $partsByRequest[$p['request_id']][] = $p;
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>?嗡辣?唾??”</title>
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

        .recent-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 10px;
        }

        .recent-list li {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .recent-header {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .recent-time {
            color: #666;
            white-space: nowrap;
        }

        .recent-subtitle {
            color: #444;
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

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
            background: #333;
        }

        .muted {
            color: #666;
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
        <h1>?嗡辣?唾??”</h1>
    </div>

    <?php foreach ($requests as $r): ?>
        <?php
            $reqId = $r['request_id'];
            $parts = $partsByRequest[$reqId] ?? [];
        ?>

        <div class="section-card">

            <div class="recent-header">
                <div>
                    <strong>Request ID:</strong>
                    <?= htmlspecialchars($reqId) ?>
                    <span class="status-badge">
                        <?= htmlspecialchars($r['request_status']) ?>
                    </span>
                </div>

                <div class="recent-time">
                    <?= htmlspecialchars($r['request_time']) ?>
                </div>
            </div>

            <div class="recent-subtitle">
                <div><b>蝬凋耨??</b> <?= htmlspecialchars($r['maint_id']) ?></div>
                <div><b>鞈:</b> <?= htmlspecialchars($r['asset_id']) ?></div>
                <div><b>??:</b> <?= htmlspecialchars($r['action']) ?></div>
                <div><b>?銵:</b> <?= htmlspecialchars($r['technician_id']) ?></div>
                <div><b>蝬凋耨???</b> <?= htmlspecialchars($r['maint_status']) ?></div>
            </div>

            <table class="work-table" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>Part ID</th>
                        <th>?賊?</th>
                        <th>???/th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($parts)): ?>
                        <tr>
                            <td colspan="3" class="muted">?⊿隞嗉???/td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($parts as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['part_id']) ?></td>
                                <td><?= htmlspecialchars($p['req_qty']) ?></td>
                                <td>
                                    <span class="status-badge">
                                        <?= htmlspecialchars($p['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        </div>

    <?php endforeach; ?>

</div>

</body>
</html>
