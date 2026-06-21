<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$employeeId = $_SESSION['employee_id'] ?? '';
$employeeName = $_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? '使用者';
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
    <title>維修排程 - 路邊電力資產管理系統</title>
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
        <h3>導覽列</h3>
        <a href="maintenances.php">首頁</a>
        <a href="assets.php">資產清單</a>
        <a href="index.php#powerasset-search">饋線區</a>
        <a href="maint-history.php">維修紀錄</a>
        <a href="maint-todo.php">維修工作</a>
        <a href="parts-request.php">零件申請</a>
        <a href="schedule-maint.php">維修排程</a>
    </div>

    <div class="main">
        <div class="headerflex">
            <h1>維修工作</h1>
            <div class="session-box">
                <?php if (!empty($_SESSION['employee_id'])): ?>
                    <div><?= htmlspecialchars($employeeName) ?></div>
                    <div><?= htmlspecialchars($employeeRole) ?></div>
                    <div><a href="login.php">切換帳號</a></div>
                <?php else: ?>
                    <a href="login.php">登入</a>
                <?php endif; ?>
            </div>
        </div>

        <section class="section-card">
            <h2>今日排程</h2>
            <?php if (count($scheduledWorks) > 0): ?>
                <table class="work-table">
                    <thead>
                        <tr>
                            <th>項目</th>
                            <th>安排時間</th>
                            <th>狀態</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduledWorks as $index => $work): ?>
                            <tr>
                                <td>項目<?= $index + 1 ?> · <?= htmlspecialchars($work['maint_id']) ?></td>
                                <td><?= htmlspecialchars($work['scheduled_time']) ?></td>
                                <td><span class="status-badge"><?= htmlspecialchars($work['status']) ?></span></td>
                                <td class="action-links">
                                    <a href="maint-history.php?record_id=<?= urlencode($work['maint_id']) ?>">查看詳情</a>
                                    <a href="schedule-maint.php?asset_id=<?= urlencode($work['asset_id']) ?>">編輯排程</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>今日尚無維修排程。</p>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>
