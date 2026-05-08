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

// get URL param 'id' and query specific log joined with PowerAsset type
$log_id = $_GET['id'] ?? null;
$log = null;

if ($log_id) {
    $stmt = $pdo->prepare("SELECT il.*, pa.type as asset_type FROM InspectionLog il JOIN PowerAsset pa ON il.asset_id = pa.asset_id WHERE il.log_id = ?");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC); // fetch single log record
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<!--41243214-->

<head>
    <meta charset="UTF-8">
    <title>檢查詳情</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <a href="index.php" class="back-link">← 返回首頁</a>

    <?php // if record found, render detailed audit card ?>
    <?php if ($log): ?>
        <h1>檢查詳情 - <?= htmlspecialchars($log['log_id']) ?></h1>
        <div class="detail-card">
            <p><span class="label">資產編號:</span> <?= htmlspecialchars($log['asset_id']) ?>
                (<?= htmlspecialchars($log['asset_type']) ?>)</p>
            <p><span class="label">檢查員 ID:</span> <?= htmlspecialchars($log['inspector_id']) ?></p>
            <p><span class="label">風險分數:</span>
                <span class="<?= $log['risk_score'] >= 50 ? 'text-danger' : 'text-success' ?>">
                    <?= htmlspecialchars($log['risk_score']) ?>
                </span>
            </p>
            <p><span class="label">檢查時間:</span> <?= htmlspecialchars($log['inspec_time']) ?></p>
            <hr>
            <p><span class="label">觀察回報:</span></p>
            <p><?= nl2br(htmlspecialchars($log['observation'])) ?></p>
            <?php if ($log['photo_url']): ?>
                <p><span class="label">照片:</span> <a href="<?= htmlspecialchars($log['photo_url']) ?>" target="_blank">點此查看</a>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p>找不到該筆檢查紀錄。</p>
    <?php endif; ?>
</body>

</html>