<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

if (empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = null;
try {
    $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

$parts = [];
$stmt = $pdo->query('SELECT * FROM Partspecs ORDER BY part_id');
$parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>?嗡辣蝮質”</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        table {width:100%;border-collapse:collapse;}
        th,td{border:1px solid #ddd;padding:8px;text-align:left;}
        th{background:#f2f2f2;}
    </style>
</head>
<body>
    <div class="sidebar">
        <h3>撠汗??/h3>
        <a href="../maintenances.php">擐?</a>
        <a href="../assets.php">鞈皜</a>
        <a href="../index.php#powerasset-search">擖??</a>
        <a href="../maint-history.php">蝬凋耨蝝??/a>
        <a href="../maint-todo.php">蝬凋耨撌乩?</a>
        <a href="../parts-request.php">?嗡辣?唾?</a>
        <a href="../schedule-maint.php">蝬凋耨??</a>
    </div>
    <div class="main">
        <h1>?嗡辣蝮質”</h1>
        <table>
            <thead>
                <tr>
                    <th>Part ID</th>
                    <th>Name</th>
                    <th>Stock</th>
                    <th>Safe Stock</th>
                    <th>Reorder Qty</th>
                    <th>Last Check</th>
                    <th>Unit Cost</th>
                    <th>Provider</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parts as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['part_id']) ?></td>
                    <td><?= htmlspecialchars($p['part_name']) ?></td>
                    <td><?= htmlspecialchars($p['stock']) ?></td>
                    <td><?= htmlspecialchars($p['safe_stock']) ?></td>
                    <td><?= htmlspecialchars($p['reorder_qty']) ?></td>
                    <td><?= htmlspecialchars($p['last_check_time']) ?></td>
                    <td><?= htmlspecialchars($p['unit_cost']) ?></td>
                    <td><?= htmlspecialchars($p['provider']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
