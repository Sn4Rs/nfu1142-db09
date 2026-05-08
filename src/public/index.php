<?php

// 載入 Composer 自動載入器
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// 載入環境變數設定
// create instance, doesn't overwrite
// looks for .env in the current directory
// loads .env file and inject contents into $_ENV superglobal
// use $_ENV['VAR_NAME'] to access the environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<!--41243214-->

<head>
    <meta charset="UTF-8">
    <title>路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>

    <div class="sidebar"> <!--main menu-->
        <h3>清單</h3>
        <a href="assets.php">資產總表</a>
        <a href="inspections.php">檢查紀錄表</a>
        <a href="index.php">待修清單</a>
    </div>

    <div class="main">

        <?php
        // connect to db and test connection
        try {
            $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";port=" . $_ENV['DB_PORT'];
            $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            echo "<p style='color: red;'>Database connection failed: " . $e->getMessage() . "</p>";
        }
        ?>

        <h1>路邊電力資產管理系統</h1>
        <p>Database Host: <?= htmlspecialchars($_ENV['DB_HOST']) ?></p>



        <?php
        // query assets with risk score >= 50, join with InspectionLog
        if (isset($pdo)):
            try {
                $sql = "SELECT pa.asset_id, pa.type, il.log_id, il.risk_score, il.observation, il.inspec_time 
                FROM PowerAsset pa
                JOIN InspectionLog il ON pa.asset_id = il.asset_id
                WHERE il.risk_score >= 50
                ORDER BY il.risk_score DESC";
                $stmt = $pdo->query($sql);
                $highRiskAssets = $stmt->fetchAll(PDO::FETCH_ASSOC); // fetch high risk results
            } catch (PDOException $e) {
                $highRiskAssets = [];
            }
            ?>

            <h2 id="high-risk">危急待修</h2>
            <p>風險分數 >= 50降序</p>
            <?php if (count($highRiskAssets) > 0): ?>
                <table border="1">
                    <thead>
                        <tr>
                            <th>資產編號</th>
                            <th>資產類別</th>
                            <th>風險分數</th>
                            <th>觀察回報</th>
                            <th>檢查時間</th>
                            <th>處理狀況</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($highRiskAssets as $asset): ?>
                            <tr style="color: red; font-weight: bold;">
                                <td><?= htmlspecialchars($asset['asset_id']) ?></td>
                                <td><?= htmlspecialchars($asset['type']) ?></td>
                                <td><?= htmlspecialchars($asset['risk_score']) ?></td>
                                <td><?= htmlspecialchars($asset['observation']) ?></td>
                                <td><?= htmlspecialchars($asset['inspec_time']) ?></td>
                                <td><a href="inspec-details.php?id=<?= urlencode($asset['log_id']) ?>">檢視細節</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>目前無高風險資產紀錄。</p>
            <?php endif; ?>


        </div>
    </body>

    </html>
<?php endif; ?>