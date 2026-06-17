<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

/* ----------------------------
   DB CONNECT
---------------------------- */
try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;port=%s;charset=utf8mb4',
        $_ENV['DB_HOST'],
        $_ENV['DB_NAME'],
        $_ENV['DB_PORT']
    );

    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

/* ----------------------------
   INPUT
---------------------------- */
$selectedRecordId = $_GET['record_id'] ?? null;

/* ----------------------------
   LOAD RECORDS
---------------------------- */
$stmt = $pdo->query("
    SELECT 
        ml.maint_id,
        ml.asset_id,
        ml.maint_time,
        ml.status,
        ml.action,
        ml.details,
        e.fullname AS maintainer_name,
        pa.type AS asset_type,
        pa.sector_id
    FROM Maintenancelog ml
    LEFT JOIN Employees e 
        ON ml.technician_id = e.id_num
    LEFT JOIN Powerasset pa 
        ON ml.asset_id = pa.asset_id
    ORDER BY ml.maint_time DESC
");

$records = $stmt->fetchAll();

/* ----------------------------
   FIND SELECTED RECORD
---------------------------- */
$selectedRecord = null;
$selectedPhotos = [];

if ($selectedRecordId) {
    foreach ($records as $r) {
        if ($r['maint_id'] === $selectedRecordId) {
            $selectedRecord = $r;
            break;
        }
    }

    if ($selectedRecord) {
        $photoStmt = $pdo->prepare("
            SELECT photo_id, url, filename, uploaded_at
            FROM Photos
            WHERE maint_id = ? AND is_deleted = 0
            ORDER BY uploaded_at DESC
        ");
        $photoStmt->execute([$selectedRecordId]);
        $selectedPhotos = $photoStmt->fetchAll();
    }
}

/* ----------------------------
   GROUP BY MONTH
---------------------------- */
$groupedRecords = [];

foreach ($records as $r) {
    if (empty($r['maint_time'])) continue;

    $monthKey = date('Y-m', strtotime($r['maint_time']));
    $groupedRecords[$monthKey][] = $r;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>維修紀錄 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            margin: 0;
            font-family: sans-serif;
            line-height: 1.6;
            background: #fff;
        }

        .page-shell {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 0;
            min-height: 100vh;
            margin-left: 210px;
            transition: grid-template-columns 0.24s ease;
        }

        .page-shell.detail-open {
            grid-template-columns: minmax(0, 1fr) minmax(360px, 420px);
        }

        .sidebar {
            height: 100%;
            width: 200px;
            position: fixed;
            z-index: 1;
            top: 0;
            left: 0;
            background-color: #333;
            padding-top: 20px;
            color: white;
            box-sizing: border-box;
        }

        .sidebar h3 {
            padding: 0 15px;
            margin: 0 0 8px;
        }

        .sidebar a {
            padding: 10px 15px;
            text-decoration: none;
            font-size: 16px;
            color: #ccc;
            display: block;
        }

        .sidebar a:hover {
            background-color: #555;
            color: white;
        }

        .history-main {
            border-right: 4px solid #111;
            padding: 14px 16px 18px;
            overflow-y: auto;
        }

        .detail-panel {
            border-left: 0;
            padding: 18px 18px 22px;
            overflow-y: auto;
            background: #fff;
            transform: translateX(100%);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.24s ease, opacity 0.24s ease;
        }

        .page-shell.detail-open .detail-panel {
            transform: translateX(0);
            opacity: 1;
            pointer-events: auto;
        }

        .headerflex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 12px;
        }

        .session-box {
            text-align: right;
            font-size: 14px;
            line-height: 1.4;
        }

        .history-title {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
        }

        .month-group {
            margin-bottom: 18px;
        }

        .month-label {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 8px;
        }

        .record-item {
            border: 4px solid #111;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 4px 12px;
            margin-bottom: 8px;
            background: #fff;
        }

        .record-title {
            font-size: 17px;
            font-weight: 700;
            color: #111;
            text-decoration: none;
        }

        .record-meta {
            font-size: 17px;
            font-weight: 700;
            white-space: nowrap;
        }

        .record-links {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .record-links a {
            color: #0066cc;
            text-decoration: none;
            font-weight: 700;
        }

        .record-links a:hover {
            text-decoration: underline;
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .detail-header h2 {
            margin: 0;
            font-size: 22px;
        }

        .close-link {
            color: #0066cc;
            text-decoration: none;
            white-space: nowrap;
        }

        .detail-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 14px;
            background: #fff;
        }

        .detail-row {
            margin-bottom: 10px;
        }

        .detail-label {
            display: inline-block;
            min-width: 110px;
            font-weight: 700;
        }

        .photo-list {
            display: grid;
            gap: 10px;
            margin-top: 8px;
        }

        .photo-item {
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .empty-state {
            color: #666;
        }

        @media (max-width: 1100px) {

            .page-shell,
            .page-shell.detail-open {
                grid-template-columns: 1fr;
                margin-left: 0;
            }

            .sidebar {
                position: static;
                width: auto;
                height: auto;
            }

            .detail-panel {
                transform: none;
                opacity: 1;
                pointer-events: auto;
            }
        }
    </style>
</head>

<body>
    <div class="page-shell<?= $selectedRecord ? ' detail-open' : '' ?>" id="page-shell">
        <aside class="sidebar">
            <h3>導覽列</h3>
            <a href="maintenances.php">首頁</a>
            <a href="assets.php">資產清單</a>
            <a href="index.php#powerasset-search">饋線區</a>
            <a href="maint-history.php">維修紀錄</a>
            <a href="maint-todo.php">維修工作</a>
            <a href="parts-request.php">零件申請</a>
            <a href="schedule-maint.php">安排維修</a>
        </aside>
        <main class="history-main">
            <div class="headerflex">
                <h1 class="history-title">維修紀錄</h1>
                <div class="session-box">
                    <?php if (!empty($_SESSION['employee_id'])): ?>
                    <div>
                        <?= htmlspecialchars($_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? '使用者') ?>
                    </div>
                    <div>
                        <?= htmlspecialchars($_SESSION['employee_role'] ?? '') ?>
                    </div>
                    <div><a href="login.php">切換帳號</a></div>
                    <?php else: ?>
                    <a href="login.php">登入</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($records)): ?>
            <?php foreach ($groupedRecords as $monthKey => $monthRecords): ?>
            <section class="month-group">
                <h2 class="month-label">
                    <?= htmlspecialchars(date('Y年 n月', strtotime($monthKey . '-01'))) ?>
                </h2>
                <?php foreach ($monthRecords as $record): ?>
                <div class="record-item">
                    <a class="record-title" href="?record_id=<?= urlencode($record['maint_id']) ?>">
                        <?= htmlspecialchars($record['maint_id']) ?>
                    </a>
                    <div class="record-meta">
                        <?= htmlspecialchars(date('n/j', strtotime($record['maint_time']))) ?>
                    </div>
                    <div class="record-links">
                        <a href="?record_id=<?= urlencode($record['maint_id']) ?>">右側詳情</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endforeach; ?>
            <?php else: ?>
            <p class="empty-state">目前尚無維護紀錄。</p>
            <?php endif; ?>
        </main>
        <aside class="detail-panel" id="detail-panel">
            <div class="detail-header">
                <h2>
                    維護紀錄ID：
                    <?= htmlspecialchars($selectedRecord['maint_id'] ?? '') ?>
                </h2>
                <a class="close-link" href="maint-history.php">關閉</a>
            </div>

            <?php if ($selectedRecord): ?>

            <div class="detail-card">

                <div class="detail-row">
                    <span class="detail-label">日期</span>
                    <?= htmlspecialchars($selectedRecord['maint_time'] ?? '') ?>
                </div>

                <div class="detail-row">
                    <span class="detail-label">項目</span>
                    <?= htmlspecialchars($selectedRecord['asset_id']) ?>
                    <?= !empty($selectedRecord['asset_type'])
                    ? ' (' . htmlspecialchars($selectedRecord['asset_type']) . ')'
                    : '' ?>
                </div>

                <div class="detail-row">
                    <span class="detail-label">安裝位置</span>
                    <?= htmlspecialchars($selectedRecord['sector_id'] ?? '') ?>
                </div>

                <div class="detail-row">
                    <span class="detail-label">維護人員</span>
                    <?= htmlspecialchars($selectedRecord['maintainer_name'] ?? '') ?>
                </div>

                <div class="detail-row">
                    <span class="detail-label">行動</span>
                    <?= htmlspecialchars($selectedRecord['action'] ?? '') ?>
                </div>

                <div class="detail-row">
                    <span class="detail-label">細節</span><br>
                    <?= nl2br(htmlspecialchars($selectedRecord['details'] ?? '')) ?>
                </div>

                <div class="detail-row">
                    <span class="detail-label">狀態</span>
                    <?= htmlspecialchars($selectedRecord['status'] ?? '') ?>
                </div>

                <h3>照片</h3>

                <?php if (!empty($selectedPhotos)): ?>
                <div class="photo-list">
                    <?php foreach ($selectedPhotos as $photo): ?>
                    <div class="photo-item">
                        <div><strong>
                                <?= htmlspecialchars($photo['filename'] ?: $photo['photo_id']) ?>
                            </strong></div>

                        <div>
                            <img src="<?= htmlspecialchars($photo['url']) ?>" style="max-width:100%;">
                        </div>

                        <div style="font-size: 13px; color: #666;">
                            <?= htmlspecialchars($photo['uploaded_at']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="empty-state">目前沒有照片。</p>
                <?php endif; ?>

            </div>

            <?php else: ?>
            <p class="empty-state">請選擇一筆維修紀錄。</p>
            <?php endif; ?>
        </aside>
    </div>
    <script>
        const detailPanel = document.getElementById('detail-panel');
        if (detailPanel) {
            const hasSelection = new URLSearchParams(window.location.search).has('record_id');
            if (hasSelection) {
                detailPanel.style.transform = 'translateX(0)';
                detailPanel.style.opacity = '1';
                detailPanel.style.pointerEvents = 'auto';
            }
        }
    </script>
</body>

</html>