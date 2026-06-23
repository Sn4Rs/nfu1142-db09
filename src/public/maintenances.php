<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$recentChanges = [];
$todayWorks = [];

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$employeeId = $_SESSION['employee_id'] ?? '';
$employeeRole = $_SESSION['employee_role'] ?? '';
//set timezone
date_default_timezone_set('Asia/Taipei');
try {
    $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $maintenanceStmt = $pdo->query("SELECT maint_id, asset_id, status, action, details, maint_time, scheduled_time, approved_at
        FROM Maintenancelog
        ORDER BY COALESCE(approved_at, scheduled_time, maint_time) DESC, maint_id DESC
        LIMIT 5");
    foreach ($maintenanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentChanges[] = [
            'source' => '蝬凋耨蝝??,
            'record_id' => $row['maint_id'],
            'title' => $row['maint_id'] . ' 繚 ' . $row['asset_id'],
            'subtitle' => $row['action'] ?: ($row['details'] ?: '蝬凋耨閮??湔'),
            'time' => $row['approved_at'] ?: ($row['scheduled_time'] ?: $row['maint_time']),
            'status' => $row['status'],
        ];
    }

    $partsStmt = $pdo->query("SELECT request_id, maint_id, status, request_time, approved_at, reject_reason
        FROM Partsrequest
        ORDER BY COALESCE(approved_at, request_time) DESC, request_id DESC
        LIMIT 5");
    foreach ($partsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentChanges[] = [
            'source' => '?嗡辣?唾?',
            'record_id' => $row['request_id'],
            'title' => $row['request_id'] . ' 繚 ' . $row['maint_id'],
            'subtitle' => $row['reject_reason'] ?: '?嗡辣?唾????' . $row['status'],
            'time' => $row['approved_at'] ?: $row['request_time'],
            'status' => $row['status'],
        ];
    }

    if ($employeeId !== '' || $employeeRole !== '') {
        $notifSql = 'SELECT notification_id, title, content, receiver_id, receiver_role, created_at, is_read
            FROM Notification
            WHERE 1 = 0';
        $notifParams = [];

        if ($employeeId !== '') {
            $notifSql .= ' OR receiver_id = :employee_id';
            $notifParams[':employee_id'] = $employeeId;
        }

        if ($employeeRole !== '') {
            $notifSql .= ' OR receiver_role = :employee_role';
            $notifParams[':employee_role'] = $employeeRole;
        }

        $notifSql .= ' ORDER BY created_at DESC LIMIT 5';
        $notifStmt = $pdo->prepare($notifSql);
        $notifStmt->execute($notifParams);

        foreach ($notifStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recentChanges[] = [
                'source' => '?',
                'record_id' => $row['notification_id'],
                'title' => $row['title'],
                'subtitle' => $row['content'],
                'time' => $row['created_at'],
                'status' => $row['is_read'] ? '撌脰?' : '?芾?',
            ];
        }
    }

    usort($recentChanges, function ($left, $right) {
        return strtotime($right['time'] ?? '1970-01-01') <=> strtotime($left['time'] ?? '1970-01-01');
    });

    //get today date
    $startOfDay = date('Y-m-d 00:00:00');
    $endOfDay   = date('Y-m-d 23:59:59');

    // Only show today's jobs assigned to the logged?n employee
    $todayStmt = $pdo->prepare(
        "SELECT 
            m.maint_id,
            m.asset_id,
            m.status,
            m.scheduled_time,
            m.action,
            m.details,
            e.fullname AS technician_name
        FROM Maintenancelog m
        LEFT JOIN Employees e ON m.technician_id = e.id_num
        WHERE m.scheduled_time >= :start
        AND m.scheduled_time < :end
        AND m.technician_id = :employee_id
        ORDER BY m.scheduled_time ASC, m.maint_id ASC"
    );

    $todayStmt->execute([
        ':start' => $startOfDay,
        ':end' => $endOfDay,
        ':employee_id' => $employeeId
    ]);

    $todayWorks = $todayStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die('Database error: ' . $e->getMessage());
    }
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>蝬凋耨蝝??- 頝舫??餃?鞈蝞∠?蝟餌絞</title>
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
            <h1>蝬凋耨蝝??/h1>

            <?php if ($flashMessage): ?>
                <div class="flash <?= htmlspecialchars($flashType) ?>">
                <?= htmlspecialchars($flashMessage) ?>
                </div>
            <?php endif; ?>

            <div class="session-box">
                <?php if (!empty($_SESSION['employee_id'])): ?>
                    <div><?= htmlspecialchars($_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? '雿輻??) ?></div>
                    <div><?= htmlspecialchars($_SESSION['employee_role'] ?? '') ?></div>
                    <div><a href="logout.php">??撣唾?</a></div>
                <?php else: ?>
                    <a href="login.php">?餃</a>
                <?php endif; ?>
            </div>
        </div>

        <section class="section-card">
            <h2>餈?霈</h2>
            <?php if (count($recentChanges) > 0): ?>
                <ul class="recent-list">
                    <?php foreach ($recentChanges as $item): ?>
                        <li>
                            <div class="recent-header">
                                <strong><?= htmlspecialchars($item['title']) ?></strong>
                                <span class="recent-time"><?= htmlspecialchars($item['time']) ?></span>
                            </div>
                            <div class="muted">靘?嚗??= htmlspecialchars($item['source']) ?> 繚 ???<?= htmlspecialchars($item['status']) ?></div>
                            <div class="recent-subtitle"><?= htmlspecialchars(mb_strimwidth($item['subtitle'] ?? '', 0, 80, '...')) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>?桀?撠餈?霈??/p>
            <?php endif; ?>
        </section>

        <section class="section-card" id="today-work">
            <div class="headerflex" style="margin-bottom: 12px;">
                <h2 style="margin: 0;">隞撌乩?</h2>
                <a href="maint-todo.php">?亦??券...</a>
            </div>

            <?php if (count($todayWorks) > 0): ?>
                <table class="work-table">
                    <thead>
                        <tr>
                            <th>?</th>
                            <th>摰???</th>
                            <th>???/th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todayWorks as $index => $work): ?>
                            <tr>
                                <td><?=htmlspecialchars($work['maint_id'])?></td>
                                <td><?= htmlspecialchars($work['scheduled_time']) ?></td>
                                <td>
                                    <span class="status-badge"><?= htmlspecialchars($work['status']) ?></span>
                                    <a href="new-maint.php?record_id=<?= urlencode($work['maint_id']) ?>">憛怠神?勗?</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>隞撠蝬凋耨????/p>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>

