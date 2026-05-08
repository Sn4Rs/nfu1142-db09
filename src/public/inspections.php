<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<!--41243214-->

<head>
    <meta charset="UTF-8">
    <title>檢查紀錄表 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>

    <div class="sidebar">
        <h3>清單</h3>
        <a href="assets.php">資產總表</a>
        <a href="inspections.php">檢查紀錄表</a>
        <a href="index.php">待修清單</a>
    </div>

    <div class="main">
        <?php
        // connect to db
        try {
            $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";port=" . $_ENV['DB_PORT'];
            $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // select all inspection logs joined with inspector name
            $sql = "SELECT il.*, i.name as inspector_name 
                FROM InspectionLog il 
                JOIN Inspector i ON il.inspector_id = i.inspector_id 
                ORDER BY il.inspec_time DESC";
            $stmt = $pdo->query($sql);
            $allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC); // fetch all results
        } catch (PDOException $e) {
            die("Database error: " . $e->getMessage());
        }
        ?>

        <h1>檢查紀錄表</h1>
        <?php // render table if records exist ?>
        <?php if (count($allLogs) > 0): ?>
            <table border="1">
                <thead>
                    <tr>
                        <th>紀錄編號</th>
                        <th>資產編號</th>
                        <th>檢查員</th>
                        <th>風險分數</th>
                        <th>檢查時間</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allLogs as $log): // loop through inspection logs and render rows ?>
                        <tr>
                            <td><?= htmlspecialchars($log['log_id']) ?></td>
                            <td><?= htmlspecialchars($log['asset_id']) ?></td>
                            <td><?= htmlspecialchars($log['inspector_name']) ?></td>
                            <td>
                                <span class="badge <?= $log['risk_score'] >= 70 ? 'bg-danger' : ($log['risk_score'] >= 40 ? 'bg-warning' : 'bg-success') ?>">
                                    <?= htmlspecialchars($log['risk_score']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($log['inspec_time']) ?></td>
                            <td>
                                <a href="inspec-details.php?id=<?= urlencode($log['log_id']) ?>">詳細內容</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>目前尚無檢查紀錄。</p>
        <?php endif; ?>
    </div>
</body>

</html>