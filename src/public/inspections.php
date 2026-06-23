<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$recentInspections = [];
$todayInspections = [];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<!--41243214-->

<head>
    <meta charset="UTF-8">
    <title>檢查紀錄表 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .main {
            margin-left: 210px;
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

        .work-table td,
        .work-table th {
            vertical-align: middle;
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
    <?php
    try {
        $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";port=" . $_ENV['DB_PORT'];
        $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $recentSql = "SELECT il.inspec_id, il.asset_id, il.observation, il.risk_score, il.inspec_time, e.fullname AS inspector_name
            FROM Inspectionlog il
            LEFT JOIN Employees e ON il.inspector_id = e.id_num
            ORDER BY il.inspec_time DESC
            LIMIT 4";
        $recentStmt = $pdo->query($recentSql);
        $recentInspections = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        $todaySql = "SELECT il.inspec_id, il.asset_id, il.observation, il.risk_score, il.inspec_time, e.fullname AS inspector_name
            FROM Inspectionlog il
            LEFT JOIN Employees e ON il.inspector_id = e.id_num
            WHERE DATE(il.inspec_time) = CURDATE()
            ORDER BY il.inspec_time ASC
            LIMIT 3";
        $todayStmt = $pdo->query($todaySql);
        $todayInspections = $todayStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
    ?>

        <aside class="sidebar">
            <h3>導覽列</h3>
            <a href="inspections.php">首頁</a>
            <a href="assets.php">資產總表</a>
            <a href="inspec-history.php">巡檢紀錄</a>
            <a href="new-inspec.php">巡檢回報</a>
        <!-- USER_BACKEND_ROLE_MENU_START -->
        <?php require_once __DIR__ . '/backend-menu.php'; backend_render_menu(); ?>
        <!-- USER_BACKEND_ROLE_MENU_END -->
        </aside>

    <div class="main">
        <div class="headerflex">
            <h1>檢查紀錄表</h1>
            <div class="session-box">
                <?php if (!empty($_SESSION['employee_id'])): ?>
                    <div><?= htmlspecialchars($_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? '使用者') ?></div>
                    <div><?= htmlspecialchars($_SESSION['employee_role'] ?? '') ?></div>
                    <div><a href="logout.php">切換帳號</a></div>
                <?php else: ?>
                    <a href="login.php">登入</a>
                <?php endif; ?>
            </div>
        </div>

        <section class="section-card">
            <h2>近期變更</h2>
            <?php if (count($recentInspections) > 0): ?>
                <ul class="recent-list">
                    <?php foreach ($recentInspections as $log): ?>
                        <li>
                            <strong><?= htmlspecialchars($log['asset_id']) ?></strong>
                            <div><?= htmlspecialchars($log['inspector_name'] ?? '未指派檢查員') ?> · <?= htmlspecialchars($log['inspec_time']) ?></div>
                            <div><?= htmlspecialchars(mb_strimwidth($log['observation'] ?? '', 0, 48, '...')) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>目前尚無紀錄。</p>
            <?php endif; ?>
        </section>

        <section class="section-card" id="today-work">
            <div class="headerflex" style="margin-bottom: 12px;">
                <h2 style="margin: 0;">今日巡檢工作</h2>
                <a href="#">查看全部...</a>
            </div>

            <?php if (count($todayInspections) > 0): ?>
                <table class="work-table">
                    <thead>
                        <tr>
                            <th>項目</th>
                            <th>狀態</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todayInspections as $index => $log): ?>
                            <?php $isNewSubmission = date('Y-m-d', strtotime($log['inspec_time'])) === date('Y-m-d'); ?>
                            <tr>
                                <td>項目<?= $index + 1 ?> · <?= htmlspecialchars($log['asset_id']) ?></td>
                                    <td class="action-links">
                                        <?php if ($isNewSubmission): ?>
                                            <span>已填報</span>
                                        <?php else: ?>
                                            <a href="new-inspec.php?asset_id=<?= urlencode($log['asset_id']) ?>">填報</a>
                                        <?php endif; ?>
                                    </td>
                                <td class="action-links"><a href="inspec-details.php?id=<?= urlencode($log['inspec_id']) ?>">檢視</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>今日尚無巡檢工作。</p>
            <?php endif; ?>
            
            <p style="text-align: center; margin-top: 20px;"><a href="new-inspec.php">新增巡檢紀錄</a></p>
        </section>
    </div>
</body>

</html>