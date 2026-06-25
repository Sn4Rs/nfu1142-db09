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


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['employee_id'])) {
    $role = strtolower((string) ($_SESSION['employee_role'] ?? ''));

    if (str_contains($role, 'inspector')) {
        header('Location: inspections.php');
        exit;
    }
    if (str_contains($role, 'technician')) {
        header('Location: maintenances.php');
        exit;
    }
    if (str_contains($role, 'assetmanager') || str_contains($role, 'deptmanager')) {
        header('Location: backend-management.php');
        exit;
    }
}

$searchTerm = trim($_GET['q'] ?? '');
$searchSubmitted = isset($_GET['search']);
$powerassetMatches = [];
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
        <h3>導覽列</h3>
        <a href="index.php">首頁</a>
        <a href="assets.php">資產總表</a>
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

        <div class="headerflex" style="display: flex; justify-content: space-between; align-items: center; gap: 16px;">
            <h1>路邊電力資產管理系統</h1>
            <div style="text-align: right;">
                <?php if (!empty($_SESSION['employee_id'])): ?>
                    <div>您好，<?= htmlspecialchars($_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? '使用者') ?></div>
                    <small><?= htmlspecialchars($_SESSION['employee_role'] ?? '') ?></small>
                <?php else: ?>
                    <a href="login.php">登入</a>
                <?php endif; ?>
            </div>
        </div>
        <form class="search-container" method="get" action="index.php">
            <input type="text" name="q" placeholder="搜尋資產" value="<?= htmlspecialchars($searchTerm) ?>">
                <button type="submit" name="search" value="1">搜尋</button>
            <?php if ($searchTerm !== ''): ?>
                <a href="index.php" style="align-self: center;">清除</a>
            <?php endif; ?>
        </form>

        <?php
            if ($searchSubmitted && isset($pdo)):
            try {
                $powerassetSql = "SELECT asset_id, sector_id, type, spec_id FROM Powerasset";
                $powerassetParams = [];
                if ($searchTerm !== '') {
                    $powerassetSql .= " WHERE asset_id LIKE :q OR sector_id LIKE :q OR type LIKE :q OR spec_id LIKE :q";
                    $powerassetParams[':q'] = '%' . $searchTerm . '%';
                }
                $powerassetSql .= " ORDER BY asset_id ASC";
                $powerassetStmt = $pdo->prepare($powerassetSql);
                $powerassetStmt->execute($powerassetParams);
                $powerassetMatches = $powerassetStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $powerassetMatches = [];
            }
        endif;
        ?>

        <h2 id="powerasset-search">資產搜尋結果</h2>
        <?php if (count($powerassetMatches) > 0): ?>
            <ul style="list-style: none; padding: 0; margin-top: 0;">
                <?php foreach ($powerassetMatches as $asset): ?>
                    <li style="border-bottom: 1px solid #eee; padding: 10px 0;">
                        <strong><?= htmlspecialchars($asset['asset_id']) ?></strong>
                        <div>類別：<?= htmlspecialchars($asset['type']) ?></div>
                        <div>區域：<?= htmlspecialchars($asset['sector_id']) ?> · 規格：<?= htmlspecialchars($asset['spec_id']) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif ($searchTerm !== ''): ?>
            <p>找不到符合的資產。</p>
        <?php else: ?>
            <p>輸入關鍵字可搜尋資產編號、區域、類別或規格。</p>
        <?php endif; ?>



        <?php
        // fetch notifications and query assets with risk score >= 50
        if (isset($pdo)):
            // notifications
            try {
                $notifSql = "SELECT notification_id, receiver_id, receiver_role, title, content, is_read, created_at FROM Notification ORDER BY created_at DESC";
                $notifStmt = $pdo->prepare($notifSql);
                $notifStmt->execute();
                $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $notifications = [];
            }

            try {
                $sql = "SELECT pa.asset_id, pa.type, il.inspec_id, il.risk_score, il.observation, il.inspec_time 
                FROM Powerasset pa
                JOIN Inspectionlog il ON pa.asset_id = il.asset_id
                WHERE il.risk_score >= 50";
                $assetParams = [];
                if ($searchTerm !== '') {
                    $sql .= " AND (pa.asset_id LIKE :q OR pa.type LIKE :q OR il.observation LIKE :q)";
                    $assetParams[':q'] = '%' . $searchTerm . '%';
                }
                $sql .= " ORDER BY il.risk_score DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($assetParams);
                $highRiskAssets = $stmt->fetchAll(PDO::FETCH_ASSOC); // fetch high risk results
            } catch (PDOException $e) {
                $highRiskAssets = [];
            }
            ?>

            <h2 id="notifications">最新通知</h2>
            <?php if (count($notifications) > 0): ?>
                <ul style="list-style: none; padding: 0;">
                    <?php foreach ($notifications as $n): ?>
                        <li style="border-bottom: 1px solid #eee; padding: 10px 0;">
                            <strong><?= htmlspecialchars($n['title']) ?></strong>
                            <div style="color: #444; margin: 6px 0;"><?= nl2br(htmlspecialchars($n['content'])) ?></div>
                            <small style="color: #666;">接收者: <?= htmlspecialchars($n['receiver_role'] ?? $n['receiver_id']) ?> · <?= htmlspecialchars($n['created_at']) ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>目前無通知。</p>
            <?php endif; ?>

            <h2 id="high-risk">待修項目</h2>
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
                                    <td><a href="inspec-details.php?id=<?= urlencode($asset['inspec_id']) ?>">檢視細節</a></td>
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