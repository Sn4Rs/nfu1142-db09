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
    $status = '蝻箔辣';
$photoFiles = [];

try {
    $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $message = '鞈?摨恍??憭望???;
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
        $status = trim($_POST['status'] ?? '摰?');

        // Basic validation for parts request
        $maintId = trim($_POST['maint_id'] ?? '');
        $partIds = $_POST['part_id'] ?? [];
        $reqQtys = $_POST['req_qty'] ?? [];
        // Validate each part entry
        $invalidParts = [];
        foreach ($partIds as $idx => $pid) {
            $qty = $reqQtys[$idx] ?? '';
            if ($pid === '' || !is_string($pid)) {
                $invalidParts[] = "?嗡辣甈?蝚?{$idx} 雿蔭敹??箸?摮?;
            }
            if ($qty === '' || !is_numeric($qty) || (int)$qty <= 0) {
                $invalidParts[] = "?賊?甈?蝚?{$idx} 雿蔭敹??箸迤?湔";
            }
        }
        if ($maintId === '' || empty($partIds) || empty($reqQtys) || !empty($invalidParts)) {
            $message = '隢‵撖急???憛急?雿?;
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
                        '敺祟',
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
                                '敺祟',
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

                        // Update status of the associated Maintenancelog to '蝻箔辣'
                        $updateStmt = $pdo->prepare(
                            'UPDATE Maintenancelog SET status = ?, approved_by = ?, approved_at = ? WHERE maint_id = ?'
                        );
                        $updateStmt->execute(['蝻箔辣', $employeeId, date('Y-m-d H:i:s'), $maintId]);

                        $pdo->commit();
                        $message = '?嗡辣?唾?撌脣遣蝡?蝬凋耨蝝?歇璅??箇撩隞塚?隢?敺祟?詻?;
                        $messageType = 'success';

                        // Reset form
                        $selectedAssetId = '';
                        $action = '';
                        $details = '';
                        $scheduledTime = '';
                        $status = '摰?';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        // Append exception message for debugging (remove in prod)
                        $message = '撱箇?憭望?嚗?蝔??岫??' . $e->getMessage();
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
    <title>蝬凋耨?? - 頝舫??餃?鞈蝞∠?蝟餌絞</title>
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
        <div class="headerflex"><h1>?啣?蝻箔辣?唾?</h1></div>
        <?php if ($message): ?>
            <p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
        <div class="page-card">
            <h3>蝬凋耨蝝??閬?/h3>
            <div class="detail-card">
                <div class="detail-row"><strong>鞈:</strong> <?= htmlspecialchars($selectedAssetId) ?></div>
                <div class="detail-row"><strong>??:</strong> <?= htmlspecialchars($action) ?></div>
                <div class="detail-row"><strong>蝝啁?:</strong> <?= nl2br(htmlspecialchars($details)) ?></div>
                <div class="detail-row"><strong>????:</strong> <?= htmlspecialchars($scheduledTime) ?></div>
                <div class="detail-row"><strong>???</strong> <?= htmlspecialchars($status) ?></div>
            </div>
            <h3>?嗡辣?唾?</h3>
            <form class="report-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="maint_id" value="<?= htmlspecialchars($_GET['maint_id'] ?? '') ?>">
                <div id="parts-container">
                    <div class="form-row part-row">
                        <label for="part_id_0">?嗡辣ID<a href="parts-list.php"> ?嗡辣蝮質”</a></label>
                        <input type="text" id="part_id_0" name="part_id[]" required>
                        
                        <label for="req_qty_0">?賊?</label>
                        <input type="number" id="req_qty_0" name="req_qty[]" min="1" required>
                    </div>
                </div>
                <div class="form-row"><button type="button" id="add-part-btn" class="secondary-button">?啣??嗡辣</button></div>
                <input type="hidden" name="part_status" value="敺祟">
                <div class="form-row"><button type="submit" class="primary-button">?漱?嗡辣?唾?</button></div>
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
                <label for="part_id_${partIndex}">?嗡辣</label>
                <input type="text" id="part_id_${partIndex}" name="part_id[]" required>
                <label for="req_qty_${partIndex}">?賊?</label>
                <input type="number" id="req_qty_${partIndex}" name="req_qty[]" min="1" required>
                <button type="button" class="small-button" onclick="this.parentElement.remove();">?芷</button>
            `;
            partsContainer.appendChild(row);
            partIndex++;
        });
    </script>
</body>
</html>
