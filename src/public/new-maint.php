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
$status = '完成';  // 定義其他必要的變數
$maintId = '';  # $partIds 已經處理了

function convertDateTimeLocal(?string $value): ?string {
    if (!$value) return null;

    // HTML datetime-local => MySQL datetime
    return date('Y-m-d H:i:s', strtotime($value));
}

// ----------------------------
// DB Connection (isolated + correct error handling)
// ----------------------------
try {
    if (!isset($pdo)) {

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;port=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? '',
            $_ENV['DB_NAME'] ?? '',
            $_ENV['DB_PORT'] ?? '3306'
        );

        $pdo = new PDO(
            $dsn,
            $_ENV['DB_USER'] ?? '',
            $_ENV['DB_PASS'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

} catch (PDOException $e) {
    die('資料庫連線失敗：' . $e->getMessage());
}


// ----------------------------
// Pre-fill record (safe block)
// ----------------------------
$selectedAssetId = $action = $details = $scheduledTime = $status = '';
$maintId = '';

if (isset($_GET['record_id'])) {

    $recordId = trim($_GET['record_id']);

    $recordStmt = $pdo->prepare(
        'SELECT asset_id, action, details, scheduled_time, status
         FROM Maintenancelog
         WHERE maint_id = ?'
    );

    $recordStmt->execute([$recordId]);
    $record = $recordStmt->fetch();

    if ($record) {
        $maintId = $recordId;
        $selectedAssetId = $record['asset_id'];
        $action = $record['action'];
        $details = $record['details'];
        $scheduledTime = $record['scheduled_time'];
        $status = $record['status'];
    }
}


// ----------------------------
// Load assets
// ----------------------------
$assetsStmt = $pdo->query(
    'SELECT asset_id, sector_id, type, spec_id
     FROM Powerasset
     ORDER BY asset_id ASC'
);

$assets = $assetsStmt->fetchAll();


// ----------------------------
// POST handler (transaction-safe + correct error handling)
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $selectedAssetId = trim($_POST['asset_id'] ?? '');
    $action = trim($_POST['action'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $scheduledTime = trim($_POST['scheduled_time'] ?? '');
    $status = trim($_POST['status'] ?? '完成');

    $partIds = $_POST['part_id'] ?? [];
    $reqQtys = $_POST['req_qty'] ?? [];

    $errors = [];

    if ($selectedAssetId === '') $errors[] = '請選擇資產';
    if ($action === '') $errors[] = '請填寫維修動作';
    if ($scheduledTime === '') $errors[] = '請填寫排定時間';

    if ($status === '缺件') {

        if (!is_array($partIds)) $partIds = [];
        if (!is_array($reqQtys)) $reqQtys = [];

        if (count($partIds) === 0) {
            $errors[] = '請至少填寫一項零件';
        }

        foreach ($partIds as $idx => $pid) {

            $qty = $reqQtys[$idx] ?? '';

            if (trim($pid) === '') {
                $errors[] = '第 ' . ($idx + 1) . ' 項零件ID不可空白';
            }

            if ($qty === '' || !is_numeric($qty) || (int)$qty <= 0) {
                $errors[] = '第 ' . ($idx + 1) . ' 項數量必須大於 0';
            }
        }
    }

    if (!empty($errors)) {

        $message = implode('；', $errors);
        $messageType = 'error';

    } else {

        try {
            if (empty($_POST['maint_time'])) {
                throw new Exception("請填寫維護時間");
            }
            $scheduledTime = $_POST['scheduled_time'] ?? null;
            $maintTime = $_POST['maint_time'] ?? null;

            $scheduledTime = convertDateTimeLocal($scheduledTime);
            $maintTime = convertDateTimeLocal($maintTime);

            $pdo->beginTransaction();

                $isEdit = !empty($recordId);


                if ($isEdit) {

                    $maintId = $recordId;

                    $pdo->prepare(
                        'UPDATE Maintenancelog
                        SET technician_id = ?,
                            asset_id = ?,
                            action = ?,
                            details = ?,
                            scheduled_time = ?,
                            maint_time = ?,
                            status = ?,
                            assigned_by = ?
                        WHERE maint_id = ?'
                    )->execute([
                        $employeeId,
                        $selectedAssetId,
                        $action,
                        $details,
                        $scheduledTime,
                        $maintTime,
                        $status,
                        $employeeId,
                        $maintId
                    ]);

                } else {

                // INSERT
                $maintId = 'MT' . date('YmdHis') . random_int(100, 999);

                $pdo->prepare(
                    'INSERT INTO Maintenancelog
                    (
                        maint_id,
                        technician_id,
                        asset_id,
                        action,
                        details,
                        maint_time,
                        status,
                        scheduled_time,
                        assigned_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $maintId,
                    $employeeId,
                    $selectedAssetId,
                    $action,
                    $details,
                    $maintTime,
                    $status,
                    $scheduledTime,
                    $employeeId
                ]);
            }
            // ----------------------------
            // Photos
            // ----------------------------
            $uploadDir = __DIR__ . '/uploads/maintenance';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {

                $fileCount = count($_FILES['photos']['name']);

                for ($i = 0; $i < $fileCount; $i++) {

                    if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;

                    $originalName = basename($_FILES['photos']['name'][$i]);
                    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                    $safeName = preg_replace(
                        '/[^A-Za-z0-9_-]/',
                        '_',
                        pathinfo($originalName, PATHINFO_FILENAME)
                    );

                    $storedName = $safeName . '_' . uniqid('', true) . ($extension ? '.' . $extension : '');
                    $storedPath = $uploadDir . '/' . $storedName;

                    move_uploaded_file($_FILES['photos']['tmp_name'][$i], $storedPath);
                    if (empty($maintId)) {
                        throw new Exception("maint_id not initialized");
                    }
                    $photoStmt = $pdo->prepare(
                        'INSERT INTO Photos
                        (
                            photo_id,
                            asset_id,
                            maint_id,
                            url,
                            filename,
                            uploaded_by,
                            uploaded_at,
                            is_deleted
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
                    );

                    $photoStmt->execute([
                        'PH' . date('YmdHis') . random_int(100, 999) . $i,
                        $selectedAssetId,
                        $maintId,
                        'uploads/maintenance/' . $storedName,
                        $originalName,
                        $employeeId,
                        $maintTime
                    ]);
                }
            }

            // ----------------------------
            // Parts request
            // ----------------------------
            if (empty($maintId)) {
                    throw new Exception("maint_id not initialized");
                }
            if ($status === '缺件' && !empty($partIds)) {

                $requestId = 'PR' . date('YmdHis') . random_int(100, 999);
                $requestTime = date('Y-m-d H:i:s');

                $pdo->prepare(
                    'INSERT INTO Partsrequest
                    (request_id, maint_id, technician_id, status, request_time)
                    VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $requestId,
                    $maintId,
                    $employeeId,
                    '待審',
                    $requestTime
                ]);

                $reqStmt = $pdo->prepare(
                    'INSERT INTO Req_part
                    (request_id, part_id, req_qty, status)
                    VALUES (?, ?, ?, ?)'
                );

                foreach ($partIds as $idx => $pid) {
                    $reqStmt->execute([
                        $requestId,
                        trim($pid),
                        (int)$reqQtys[$idx],
                        '待審'
                    ]);
                }

                $pdo->prepare(
                    'UPDATE Maintenancelog SET status = ? WHERE maint_id = ?'
                )->execute(['缺件', $maintId]);
            }

            $pdo->commit();
            // back to homepage
            $_SESSION['flash_message'] = '維修報告已成功提交';
            $_SESSION['flash_type'] = 'success';

header('Location: maintenances.php');
exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = '系統錯誤：' . $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>維修報告 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .page-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 18px;
            background: #fff;
        }

        .report-form {
            display: grid;
            gap: 16px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 12px;
            align-items: start;
        }

        .form-row label {
            font-weight: 700;
            padding-top: 8px;
        }

        .form-row input[type="text"],
        .form-row input[type="datetime-local"],
        .form-row textarea,
        .form-row select {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #bbb;
            border-radius: 4px;
            padding: 8px 10px;
            font-size: 16px;
            background: #fff;
        }

        .form-row textarea {
            min-height: 120px;
        }

        .photo-list {
            display: grid;
            gap: 8px;
            margin-top: 8px;
        }

        .photo-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .photo-row input {
            flex: 1;
        }

        .small-button {
            padding: 4px 8px;
            font-size: 12px;
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
            <h1>填寫維修報告</h1>
        </div>
        <?php if ($message): ?>
        <p class="<?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </p>
        <?php endif; ?>
        <div class="page-card">
            <form class="report-form" method="post" enctype="multipart/form-data">
                <div class="form-row"> <label for="asset_id">資產</label> <select id="asset_id" name="asset_id" required>
                        <option value="">請選擇</option>
                        <?php foreach ($assets as $asset): ?>
                        <option value="<?= htmlspecialchars($asset['asset_id']) ?>"
                            <?=$asset['asset_id']===$selectedAssetId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asset['asset_id']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select> </div> <input type="hidden" name="maint_id" value="<?= htmlspecialchars($maintId) ?>">
                <div class="form-row"> <label for="action">維修動作</label> <textarea id="action" name="action"
                        required><?= htmlspecialchars($action) ?></textarea> </div>
                <div class="form-row"> <label for="details">維修細節</label> <textarea id="details" name="details"
                        required><?= htmlspecialchars($details) ?></textarea> </div>
                <div class="form-row"> <label for="scheduled_time">排定時間</label> <input type="datetime-local"
                        id="scheduled_time" name="scheduled_time" value="<?= htmlspecialchars($scheduledTime) ?>"
                        required> </div>
                <div class="form-row"> <label for="maint_time">維護時間</label> <input type="datetime-local"
                        id="maint_time" name="maint_time" value="<?= htmlspecialchars($maintTime) ?>"
                        required> </div>
                <div class="form-row"> <label for="status">狀態</label> <select id="status" name="status" required>
                        <option value="完成" <?=$status==='完成' ? 'selected' : '' ?>> 完成 </option>
                        <option value="缺件" <?=$status==='缺件' ? 'selected' : '' ?>> 缺件 </option>
                    </select> </div>
                <div class="form-row"> <label>照片上傳</label>
                    <div>
                        <div class="photo-list" id="photo-list">
                            <div class="photo-row"> <input type="file" name="photos[]"> <button type="button"
                                    class="small-button" onclick="this.parentElement.remove();"> 刪除 </button> </div>
                        </div> <button type="button" class="small-button" id="add-photo"> 新增照片 </button>
                    </div>
                </div> <!-- Parts Section -->
                <aside id="parts-request-aside"
                    style="display:none; margin-top:20px; border-left:1px solid #ddd; padding-left:20px;">
                    <h3>零件申請</h3>
                    <div id="parts-container">
                        <div class="form-row part-row"> <label for="part_id_0"> 零件ID <a href="parts-list.php">零件總表</a>
                            </label> <input type="text" id="part_id_0" name="part_id[]"> <label
                                for="req_qty_0">數量</label> <input type="number" id="req_qty_0" name="req_qty[]" min="1">
                        </div>
                    </div>
                    <div class="form-row"> <button type="button" id="add-part-btn" class="secondary-button"> 新增零件
                        </button> </div>
                </aside>
                <div class="form-row"> <button type="submit" class="primary-button"> 提交報告 </button> </div>

        </div>
        <!-- Parts request form will be loaded via AJAX when status is 缺件 -->
        <div id="parts-form-container" style="margin-top:20px;"></div>
    </div>
    <script>
        const addPhotoBtn = document.getElementById('add-photo');
        const photoList = document.getElementById('photo-list');
        addPhotoBtn.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'photo-row';
            row.innerHTML = '<input type="file" name="photos[]"><button type="button" class="small-button" onclick="this.parentElement.remove();">刪除</button>';
            photoList.appendChild(row);
        });

        // Show parts request form when status is 缺件
        const statusSelect = document.getElementById('status');
        const partsRequestAside = document.getElementById('parts-request-aside');
        statusSelect.addEventListener('change', () => {
            if (statusSelect.value === '缺件') {
                partsRequestAside.style.display = 'block';
            } else {
                partsRequestAside.style.display = 'none';
            }
        });
        // Initial state
        if (statusSelect.value !== '缺件') {
            partsRequestAside.style.display = 'none';
        }

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