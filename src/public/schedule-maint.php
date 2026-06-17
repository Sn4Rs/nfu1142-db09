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

$employeeRole = $_SESSION['employee_role'] ?? '';
$employeeId = $_SESSION['employee_id'] ?? '';
$isTechnician = stripos($employeeRole, 'technician') !== false;
$isSupervisor = stripos($employeeRole, 'deptmanager') !== false || stripos($employeeRole, 'assetmanager') !== false;

if (!$isTechnician && !$isSupervisor) {
    header('Location: maintenances.php');
    exit;
}

$pdo = null;
$message = '';
$messageType = 'success';

// Form values
$selectedAssetId = trim($_GET['asset_id'] ?? $_POST['asset_id'] ?? '');
$selectedTechnicianId = $isTechnician ? $employeeId : trim($_POST['technician_id'] ?? '');
$action = trim($_POST['action'] ?? '');
$details = trim($_POST['details'] ?? '');
$scheduledTime = trim($_POST['scheduled_time'] ?? '');
$workHours = trim($_POST['work_hours'] ?? '');
$laborCost = trim($_POST['labor_cost'] ?? '');
$materialCost = trim($_POST['material_cost'] ?? '');

$selectedAsset = null;
$selectedTechnician = null;
$assets = [];
$technicians = [];

try {
$dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get all assets
$assetStmt = $pdo->query('SELECT asset_id, sector_id, type, spec_id FROM Powerasset ORDER BY asset_id ASC');
$assets = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all technicians (employees)
$techStmt = $pdo->query('SELECT id_num, fullname, role FROM Employees WHERE role LIKE "%technician%" OR role LIKE "%維修%" ORDER BY fullname ASC');
$technicians = $techStmt->fetchAll(PDO::FETCH_ASSOC);

// Find selected asset
foreach ($assets as $asset) {
if ($asset['asset_id'] === $selectedAssetId) {
$selectedAsset = $asset;
break;
}
}

// Find selected technician
foreach ($technicians as $technician) {
if ($technician['id_num'] === $selectedTechnicianId) {
$selectedTechnician = $technician;
break;
}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$selectedAssetId = trim($_POST['asset_id'] ?? '');
$selectedTechnicianId = trim($_POST['technician_id'] ?? '');
$action = trim($_POST['action'] ?? '');
$details = trim($_POST['details'] ?? '');
$scheduledTime = trim($_POST['scheduled_time'] ?? '');
$workHours = trim($_POST['work_hours'] ?? '');
$laborCost = trim($_POST['labor_cost'] ?? '');
$materialCost = trim($_POST['material_cost'] ?? '');

// Validate form data
$assetStmt = $pdo->prepare('SELECT asset_id, sector_id, type, spec_id FROM Powerasset WHERE asset_id = ? LIMIT 1');
$assetStmt->execute([$selectedAssetId]);
$selectedAsset = $assetStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$techStmt = $pdo->prepare('SELECT id_num, fullname, role FROM Employees WHERE id_num = ? LIMIT 1');
$techStmt->execute([$selectedTechnicianId]);
$selectedTechnician = $techStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($selectedAsset === false || $selectedAsset === null) {
$message = '請先選擇有效的資產項目。';
$messageType = 'error';
} elseif ($selectedTechnician === false || $selectedTechnician === null) {
$message = '請選擇有效的技術員。';
$messageType = 'error';
} elseif ($action === '') {
$message = '請輸入維護動作。';
$messageType = 'error';
} elseif ($scheduledTime === '') {
$message = '請選擇預定時間。';
$messageType = 'error';
} else {
$pdo->beginTransaction();
try {
// Generate maintenance ID
$maintId = 'MT' . date('YmdHis') . random_int(100, 999);
$assignedBy = $_SESSION['employee_id'];
$status = '待處理';

// Insert maintenance record into Maintenancelog
            $insertStmt = $pdo->prepare('INSERT INTO Maintenancelog (maint_id, asset_id, technician_id, action, details, scheduled_time, status, assigned_by, work_hours, labor_cost, material_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insertStmt->execute([
                $maintId,
                $selectedAssetId,
                $selectedTechnicianId,
                $action,
                $details,
                $scheduledTime,
                $status,
                $assignedBy,
                $workHours !== '' ? floatval($workHours) : null,
                $laborCost !== '' ? floatval($laborCost) : null,
                $materialCost !== '' ? floatval($materialCost) : null
]);

$pdo->commit();
$message = '維護排程已建立。';
$messageType = 'success';

// Reset form values
$selectedAssetId = '';
$selectedTechnicianId = '';
$action = '';
$details = '';
$scheduledTime = '';
$workHours = '';
$laborCost = '';
$materialCost = '';
$selectedAsset = null;
$selectedTechnician = null;
} catch (Throwable $e) {
if ($pdo->inTransaction()) {
$pdo->rollBack();
}
$message = '建立失敗，請稍後再試。';
$messageType = 'error';
}
}
}
} catch (PDOException $e) {
$message = '資料庫連線失敗。';
$messageType = 'error';
}

$assetsById = [];
foreach ($assets as $asset) {
$assetsById[$asset['asset_id']] = $asset;
}

$techniciansById = [];
foreach ($technicians as $technician) {
$techniciansById[$technician['id_num']] = $technician;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>維護排程 - 路邊電力資產管理系統</title>
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
        .form-row input[type="number"],
        .form-row input[type="datetime-local"],
        .form-row select,
        .form-row textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #bbb;
            border-radius: 4px;
            padding: 8px 10px;
            font-size: 16px;
            background: #fff;
        }

        .form-row textarea {
            min-height: 100px;
            resize: vertical;
        }

        .readonly-field {
            background: #f7f7f7;
        }

        .button-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .primary-button,
        .secondary-button,
        .small-button {
            border: 1px solid #111;
            background: #fff;
            color: #111;
            padding: 10px 18px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .primary-button:hover,
        .secondary-button:hover,
        .small-button:hover {
            background: #f2f2f2;
        }

        .notice {
            padding: 10px 12px;
            border-radius: 4px;
            margin-bottom: 16px;
        }

        .notice.success {
            background: #ecfdf3;
            border: 1px solid #86efac;
        }

        .notice.error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
        }

        .asset-meta,
        .technician-meta {
            display: grid;
            gap: 6px;
            margin-top: 6px;
            font-size: 14px;
            color: #444;
        }

        @media (max-width: 760px) {
            .form-row {
                grid-template-columns: 1fr;
            }
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
            <h1>安排維修工作</h1>
            <div class="session-box">
                <div>
                    <?= htmlspecialchars($_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? '使用者') ?>
                </div>
                <div>
                    <?= htmlspecialchars($_SESSION['employee_role'] ?? '') ?>
                </div>
                <div><a href="login.php">切換帳號</a></div>
            </div>
        </div>
        <?php if ($message !== ''): ?>
        <div class="notice <?= htmlspecialchars($messageType) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        <section class="page-card">
            <form class="report-form" method="post">
                <div class="form-row">
                    <label for="asset_id">資產項目</label>
                    <div>
                        <select id="asset_id" name="asset_id" required>

                            <option value="">請選擇資產</option>
                            <?php foreach ($assets as $asset): ?>
                            <option value="<?= htmlspecialchars($asset['asset_id']) ?>"
                                <?=$selectedAssetId===$asset['asset_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($asset['asset_id']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="asset-meta">
                            <div>資產類型：<span id="asset-type">-</span></div>
                            <div>安裝位置：<span id="asset-sector">-</span></div>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <label for="technician_id">技術員</label>
                    <div>
                        <select id="technician_id" name="technician_id" required>
                            <option value="">請選擇技術員</option>
                            <?php foreach ($technicians as $technician): ?>
                            <option value="<?= htmlspecialchars($technician['id_num']) ?>"
                                <?=$selectedTechnicianId===$technician['id_num'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($technician['fullname']) ?> (
                                <?= htmlspecialchars($technician['id_num']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="technician-meta">
                            <div>職位：<span id="technician-role">-</span></div>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <label for="action">維護動作</label>
                    <div>
                        <input type="text" id="action" name="action" value="<?= htmlspecialchars($action) ?>"
                            placeholder="請輸入維護動作" required>
                    </div>
                </div>
                <div class="form-row">
                    <label for="details">詳細說明</label>
                    <div>
                        <textarea id="details" name="details"
                            placeholder="請輸入詳細說明"><?= htmlspecialchars($details) ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <label for="scheduled_time">預定時間</label>
                    <div>
                        <input type="datetime-local" id="scheduled_time" name="scheduled_time"
                            value="<?= htmlspecialchars($scheduledTime) ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <label for="work_hours">工時(小時)</label>
                    <div>
                        <input type="number" id="work_hours" name="work_hours" min="0" step="0.5"
                            value="<?= htmlspecialchars($workHours) ?>" placeholder="請輸入工時">
                    </div>
                </div>
                <div class="form-row">
                    <label for="labor_cost">人工成本</label>
                    <div>
                        <input type="number" id="labor_cost" name="labor_cost" min="0" step="0.01"
                            value="<?= htmlspecialchars($laborCost) ?>" placeholder="請輸入人工成本">
                    </div>
                </div>
                <div class="form-row">
                    <label for="material_cost">材料成本</label>
                    <div>
                        <input type="number" id="material_cost" name="material_cost" min="0" step="0.01"
                            value="<?= htmlspecialchars($materialCost) ?>" placeholder="請輸入材料成本">
                    </div>
                </div>
                <div class="button-row">
                    <a class="secondary-button" href="maintenances.php">返回</a>
                    <button class="primary-button" type="submit">建立排程</button>
                </div>
            </form>
        </section>
    </div>
    <script>
        const assetsById = <?= json_encode($assetsById, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const techniciansById = <?= json_encode($techniciansById, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const assetSelect = document.getElementById('asset_id');
        const technicianSelect = document.getElementById('technician_id');
        const assetTypeField = document.getElementById('asset-type');
        const assetSectorField = document.getElementById('asset-sector');
        const technicianRoleField = document.getElementById('technician-role');

        function syncAssetFields() {
            const asset = assetsById[assetSelect.value];
            if (!asset) {
                assetTypeField.textContent = '-';
                assetSectorField.textContent = '-';
                return;
            }
            assetTypeField.textContent = asset.type || '-';
            assetSectorField.textContent = asset.sector_id || '-';
        }

        function syncTechnicianFields() {
            const technician = techniciansById[technicianSelect.value];
            if (!technician) {
                technicianRoleField.textContent = '-';
                return;
            }
            technicianRoleField.textContent = technician.role || '-';
        }

        assetSelect.addEventListener('change', syncAssetFields);
        technicianSelect.addEventListener('change', syncTechnicianFields);

        // Initialize on page load
        syncAssetFields();
        syncTechnicianFields();
    </script>
</body>

</html>