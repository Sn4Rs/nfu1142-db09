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

$employeeId = $_SESSION['employee_id'];
$employeeRole = $_SESSION['employee_role'] ?? '';
$isTechnician = stripos($employeeRole, 'technician') !== false;
$isSupervisor = stripos($employeeRole, 'deptmanager') !== false || stripos($employeeRole, 'assetmanager') !== false;

    $pdo = null;
    $message = '';
    $messageType = 'success';
    $assets = [];
    $selectedAssetId = '';
    $action = '';
    $details = '';
    $scheduledTime = '';
    $status = '缺件';
$photoFiles = [];

try {
    $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $message = '資料庫連線失敗。';
    $messageType = 'error';
}

    // Load assets for dropdown
    $assetStmt = $pdo->query('SELECT asset_id, sector_id, type, spec_id FROM Powerasset ORDER BY asset_id ASC');
    $assets = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load maint data if maint_id provided (after PDO is ready)
        if (!empty($_GET['maint_id'])) {
            $stmt = $pdo->prepare('SELECT asset_id, action, details, scheduled_time, status FROM Maintenancelog WHERE maint_id = ?');
            $stmt->execute([$_GET['maint_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $selectedAssetId = $row['asset_id'];
                $action = $row['action'];
                $details = $row['details'];
                $scheduledTime = $row['scheduled_time'];
                $status = $row['status'];
            }
        }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // These fields are not used in parts request; keep for compatibility
        $selectedAssetId = trim($_POST['asset_id'] ?? '');
        $action = trim($_POST['action'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $scheduledTime = trim($_POST['scheduled_time'] ?? '');
        $status = trim($_POST['status'] ?? '完成');

        // Basic validation for parts request
        $maintId = trim($_POST['maint_id'] ?? '');
        $partIds = $_POST['part_id'] ?? [];
        $reqQtys = $_POST['req_qty'] ?? [];
        // Validate each part entry
        $invalidParts = [];
        foreach ($partIds as $idx => $pid) {
            $qty = $reqQtys[$idx] ?? '';
            if ($pid === '' || !is_string($pid)) {
                $invalidParts[] = "零件欄位第 {$idx} 位置必須為文字";
            }
            if ($qty === '' || !is_numeric($qty) || (int)$qty <= 0) {
                $invalidParts[] = "數量欄位第 {$idx} 位置必須為正整數";
            }
        }
        if ($maintId === '' || empty($partIds) || empty($reqQtys) || !empty($invalidParts)) {
            $message = '請填寫所有必填欄位。';
        }
            if (!empty($invalidParts)) {
                $message .= ' ' . implode(' ', $invalidParts);
            }
            $messageType = 'error';
        } else {
                $pdo->beginTransaction();
                try {
                    // Insert parts request record
                    $requestId = 'PR' . date('YmdHis') . random_int(100, 999);
                    $requestTime = date('Y-m-d H:i:s');
                    $insertPartsStmt = $pdo->prepare(
                        'INSERT INTO Partsrequest (request_id, maint_id, technician_id, status, request_time) VALUES (?, ?, ?, ?, ?)'
                    );
                    $insertPartsStmt->execute([
                        $requestId,
                        $maintId,
                        $employeeId,
                        '待審',
                        $requestTime,
                    ]);
                        // Insert each part line
                        $insertReqStmt = $pdo->prepare(
                            'INSERT INTO Req_part (request_id, part_id, req_qty, status) VALUES (?, ?, ?, ?)'
                        );
                        foreach ($partIds as $idx => $pid) {
                            $qty = $reqQtys[$idx] ?? 1;
                            $insertReqStmt->execute([
                                $requestId,
                                $pid,
                                $qty,
                                '待審',
                            ]);
                        }

                        // Handle photo uploads
                        $uploadDir = __DIR__ . '/uploads/maintenance';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }

                        if (!empty($_FILES['photos']) && isset($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
                            $fileCount = count($_FILES['photos']['name']);
                            for ($i = 0; $i < $fileCount; $i++) {
                                if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK || $_FILES['photos']['name'][$i] === '') {
                                    continue;
                                }
                                $originalName = basename($_FILES['photos']['name'][$i]);
                                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                                $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                                $storedName = $safeName . '_' . uniqid('', true) . ($extension !== '' ? '.' . $extension : '');
                                $storedPath = $uploadDir . '/' . $storedName;
                                if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $storedPath)) {
                                    $photoId = 'PH' . date('YmdHis') . random_int(100, 999) . $i;
                                    $relativePath = 'uploads/maintenance/' . $storedName;
                                    $photoStmt = $pdo->prepare(
                                        'INSERT INTO Photos (photo_id, asset_id, maint_id, url, filename, uploaded_by, uploaded_at, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
                                    );
                                    $photoStmt->execute([
                                        $photoId,
                                        $selectedAssetId,
                                        $maintId,
                                        $relativePath,
                                        $originalName,
                                        $employeeId,
                                        $maintTime,
                                    ]);
                                }
                            }
                        }

                        // Update status of the associated Maintenancelog to '缺件'
                        $updateStmt = $pdo->prepare(
                            'UPDATE Maintenancelog SET status = ?, approved_by = ?, approved_at = ? WHERE maint_id = ?'
                        );
                        $updateStmt->execute(['缺件', $employeeId, date('Y-m-d H:i:s'), $maintId]);

                        $pdo->commit();
                        $message = '零件申請已建立，維修紀錄已標記為缺件，請等待審核。';
                        $messageType = 'success';

                        // Reset form
                        $selectedAssetId = '';
                        $action = '';
                        $details = '';
                        $scheduledTime = '';
                        $status = '完成';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        // Append exception message for debugging (remove in prod)
                        $message = '建立失敗，請稍後再試。 ' . $e->getMessage();
                        $messageType = 'error';
                    }
    foreach ($assets as $asset) {
        $assetsById[$asset['asset_id']] = $asset;
    }
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
        .page-card {border:1px solid #ddd;border-radius:8px;padding:18px;background:#fff;}
        .report-form{display:grid;gap:16px;}
        .form-row{display:grid;grid-template-columns:120px 1fr;gap:12px;align-items:start;}
        .form-row label{font-weight:700;padding-top:8px;}
        .form-row input[type="text"],.form-row input[type="datetime-local"],.form-row textarea,.form-row select{width:100%;box-sizing:border-box;border:1px solid #bbb;border-radius:4px;padding:8px 10px;font-size:16px;background:#fff;}
        .form-row textarea{min-height:120px;}
        .photo-list{display:grid;gap:8px;margin-top:8px;}
        .photo-row{display:flex;gap:8px;align-items:center;}
        .photo-row input{flex:1;}
        .small-button{padding:4px 8px;font-size:12px;}
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
        <div class="headerflex"><h1>新增缺件申請</h1></div>
        <?php if ($message): ?>
            <p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
        <div class="page-card">
            <h3>維修紀錄摘要</h3>
            <div class="detail-card">
                <div class="detail-row"><strong>資產:</strong> <?= htmlspecialchars($selectedAssetId) ?></div>
                <div class="detail-row"><strong>動作:</strong> <?= htmlspecialchars($action) ?></div>
                <div class="detail-row"><strong>細節:</strong> <?= nl2br(htmlspecialchars($details)) ?></div>
                <div class="detail-row"><strong>排定時間:</strong> <?= htmlspecialchars($scheduledTime) ?></div>
                <div class="detail-row"><strong>狀態:</strong> <?= htmlspecialchars($status) ?></div>
            </div>
            <h3>零件申請</h3>
            <form class="report-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="maint_id" value="<?= htmlspecialchars($_GET['maint_id'] ?? '') ?>">
                <div id="parts-container">
                    <div class="form-row part-row">
                        <label for="part_id_0">零件ID<a href="parts-list.php"> 零件總表</a></label>
                        <input type="text" id="part_id_0" name="part_id[]" required>
                        
                        <label for="req_qty_0">數量</label>
                        <input type="number" id="req_qty_0" name="req_qty[]" min="1" required>
                    </div>
                </div>
                <div class="form-row"><button type="button" id="add-part-btn" class="secondary-button">新增零件</button></div>
                <input type="hidden" name="part_status" value="待審">
                <div class="form-row"><button type="submit" class="primary-button">提交零件申請</button></div>
            </form>
        </div>
    </div>
    <script>
        const addPartBtn = document.getElementById('add-part-btn');
        const partsContainer = document.getElementById('parts-container');
        let partIndex = 1;
        addPartBtn.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'form-row part-row';
            row.innerHTML = `
                <label for="part_id_${partIndex}">零件</label>
                <input type="text" id="part_id_${partIndex}" name="part_id[]" required>
                <label for="req_qty_${partIndex}">數量</label>
                <input type="number" id="req_qty_${partIndex}" name="req_qty[]" min="1" required>
                <button type="button" class="small-button" onclick="this.parentElement.remove();">刪除</button>
            `;
            partsContainer.appendChild(row);
            partIndex++;
        });
    </script>
</body>
</html>