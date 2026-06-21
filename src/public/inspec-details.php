<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// get URL param 'id' and query specific inspection joined with Powerasset, employee, and photos
$inspecId = $_GET['id'] ?? null;
$log = null;
$photos = [];

if ($inspecId) {
    $stmt = $pdo->prepare("SELECT il.*, pa.type AS asset_type, pa.sector_id, e.fullname AS inspector_name
        FROM Inspectionlog il
        JOIN Powerasset pa ON il.asset_id = pa.asset_id
        LEFT JOIN Employees e ON il.inspector_id = e.id_num
        WHERE il.inspec_id = ?");
    $stmt->execute([$inspecId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC); // fetch single log record

    if ($log) {
        $photoStmt = $pdo->prepare("SELECT photo_id, url, filename, uploaded_at
            FROM Photos
            WHERE inspec_id = ? AND is_deleted = 0
            ORDER BY uploaded_at DESC");
        $photoStmt->execute([$inspecId]);
        $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<!--41243214-->

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>檢查詳情 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
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

        .detail-wrap {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            background: #fff;
        }

        .photo-list {
            display: grid;
            gap: 10px;
            margin-top: 10px;
        }

        .photo-item {
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
    </style>
</head>

<body>
        <aside class="sidebar">
            <h3>導覽列</h3>
            <a href="inspections.php">首頁</a>
            <a href="assets.php">資產總表</a>
            <a href="inspec-history.php">巡檢紀錄</a>
            <a href="new-inspec.php">巡檢排程</a>
        </aside>

    <div class="main">
        <div class="headerflex">
            <h1>檢查詳情</h1>
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

        <a href="inspections.php" class="back-link">← 返回檢查紀錄表</a>

        <?php if ($log): ?>
            <div class="detail-wrap detail-card">
                <h2 style="margin-top: 0;">檢查詳情 - <?= htmlspecialchars($log['inspec_id']) ?></h2>
                <p><span class="label">資產編號:</span> <?= htmlspecialchars($log['asset_id']) ?> (<?= htmlspecialchars($log['asset_type']) ?>)</p>
                <p><span class="label">安裝位置:</span> <?= htmlspecialchars($log['sector_id']) ?></p>
                <p><span class="label">檢查員:</span> <?= htmlspecialchars($log['inspector_name'] ?? $log['inspector_id']) ?></p>
                <p><span class="label">風險分數:</span>
                    <span class="<?= $log['risk_score'] >= 50 ? 'text-danger' : 'text-success' ?>">
                        <?= htmlspecialchars($log['risk_score']) ?>
                    </span>
                </p>
                <p><span class="label">檢查時間:</span> <?= htmlspecialchars($log['inspec_time']) ?></p>
                <hr>
                <p><span class="label">觀察回報:</span></p>
                <p><?= nl2br(htmlspecialchars($log['observation'])) ?></p>

                <h3>照片</h3>
                <?php if (count($photos) > 0): ?>
                    <div class="photo-list">
                        <?php foreach ($photos as $photo): ?>
                            <div class="photo-item">
                                <div><strong><?= htmlspecialchars($photo['filename'] ?: $photo['photo_id']) ?></strong></div>
                                <div><img src="<?= htmlspecialchars($photo['url']) ?>"></div>
                                <div style="font-size: 14px; color: #666;">上傳時間：<?= htmlspecialchars($photo['uploaded_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>目前沒有附加照片。</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p>找不到該筆檢查紀錄。</p>
        <?php endif; ?>
    </div>
</body>

</html>