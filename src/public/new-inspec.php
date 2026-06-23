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

if (stripos((string) ($_SESSION['employee_role'] ?? ''), 'inspector') === false) {
	header('Location: inspections.php');
	exit;
}

$pdo = null;
$message = '';
$messageType = 'success';
$selectedAssetId = trim($_GET['asset_id'] ?? $_POST['asset_id'] ?? '');
$selectedAsset = null;
$assets = [];

try {
	$dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
	$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$assetStmt = $pdo->query('SELECT asset_id, sector_id, type, spec_id FROM Powerasset ORDER BY asset_id ASC');
	$assets = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

	foreach ($assets as $asset) {
		if ($asset['asset_id'] === $selectedAssetId) {
			$selectedAsset = $asset;
			break;
		}
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$selectedAssetId = trim($_POST['asset_id'] ?? '');
		$observation = trim($_POST['observation'] ?? '');
		$riskScore = (int) ($_POST['risk_score'] ?? 0);

		$assetStmt = $pdo->prepare('SELECT asset_id, sector_id, type, spec_id FROM Powerasset WHERE asset_id = ? LIMIT 1');
		$assetStmt->execute([$selectedAssetId]);
		$selectedAsset = $assetStmt->fetch(PDO::FETCH_ASSOC) ?: null;

		if ($selectedAsset === false || $selectedAsset === null) {
			$message = '請先選擇有效的資產項目。';
			$messageType = 'error';
		} elseif ($observation === '') {
			$message = '請輸入檢查敘述。';
			$messageType = 'error';
		} elseif ($riskScore < 0 || $riskScore > 100) {
			$message = '健康度評定請輸入 0 到 100 的數值。';
			$messageType = 'error';
		} else {
			$pdo->beginTransaction();

			try {
				$inspecId = 'IN' . date('YmdHis') . random_int(100, 999);
				$inspecTime = date('Y-m-d H:i:s');
				$inspectorId = $_SESSION['employee_id'];

				$insertStmt = $pdo->prepare('INSERT INTO Inspectionlog (inspec_id, asset_id, inspector_id, observation, risk_score, inspec_time) VALUES (?, ?, ?, ?, ?, ?)');
				$insertStmt->execute([$inspecId, $selectedAssetId, $inspectorId, $observation, $riskScore, $inspecTime]);

				$uploadDir = __DIR__ . '/uploads/inspection';
				if (!is_dir($uploadDir)) {
					mkdir($uploadDir, 0777, true);
				}

				$uploadedFiles = [];
				if (!empty($_FILES['photos']) && isset($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
					$fileCount = count($_FILES['photos']['name']);
					for ($index = 0; $index < $fileCount; $index++) {
						if ($_FILES['photos']['error'][$index] !== UPLOAD_ERR_OK || $_FILES['photos']['name'][$index] === '') {
							continue;
						}

						$originalName = basename($_FILES['photos']['name'][$index]);
						$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
						$safeName = pathinfo($originalName, PATHINFO_FILENAME);
						$safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $safeName);
						$storedName = $safeName . '_' . uniqid('', true) . ($extension !== '' ? '.' . $extension : '');
						$storedPath = $uploadDir . '/' . $storedName;

						if (move_uploaded_file($_FILES['photos']['tmp_name'][$index], $storedPath)) {
							$photoId = 'PH' . date('YmdHis') . random_int(100, 999) . $index;
							$relativePath = 'uploads/inspection/' . $storedName;

							$photoStmt = $pdo->prepare('INSERT INTO Photos (photo_id, asset_id, inspec_id, maint_id, url, filename, uploaded_by, uploaded_at, is_deleted) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 0)');
							$photoStmt->execute([
								$photoId,
								$selectedAssetId,
								$inspecId,
								$relativePath,
								$originalName,
								$_SESSION['employee_id'],
								$inspecTime,
							]);

							$uploadedFiles[] = $originalName;
						}
					}
				}

				$pdo->commit();
				$message = '巡檢紀錄已送出。' . (!empty($uploadedFiles) ? ' 已上傳：' . implode('、', $uploadedFiles) : '');
				$messageType = 'success';
				$selectedAssetId = '';
				$selectedAsset = null;
			} catch (Throwable $e) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}
				$message = '送出失敗，請稍後再試。';
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
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>巡檢紀錄回報 - 路邊電力資產管理系統</title>
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
			min-height: 160px;
			resize: vertical;
		}

		.readonly-field {
			background: #f7f7f7;
		}

		.photo-list {
			display: grid;
			gap: 10px;
		}

		.photo-row {
			display: flex;
			gap: 10px;
			align-items: center;
			flex-wrap: wrap;
		}

		.photo-row input[type="file"] {
			border: 1px solid #bbb;
			padding: 6px;
			border-radius: 4px;
			background: #fff;
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

		.asset-meta {
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
        <aside class="sidebar">
    <h3>巡檢管理</h3>

    <a href="inspections.php">巡檢首頁</a>
    <a href="new-inspec.php">新增巡檢紀錄</a>
    <a href="inspec-history.php">巡檢歷史紀錄</a>
    <a href="photos.php">巡檢照片管理</a>
</aside>

	<div class="main">
		<div class="headerflex">
			<h1>巡檢紀錄回報</h1>
			<div class="session-box">
				<div><?= htmlspecialchars($_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? '使用者') ?></div>
				<div><?= htmlspecialchars($_SESSION['employee_role'] ?? '') ?></div>
				<div><a href="logout.php">切換帳號</a></div>
			</div>
		</div>

		<?php if ($message !== ''): ?>
			<div class="notice <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
		<?php endif; ?>

		<section class="page-card">
			<form class="report-form" method="post" enctype="multipart/form-data">
				<div class="form-row">
					<label for="asset_id">項目</label>
					<div>
						<select id="asset_id" name="asset_id" required>
							<option value="">請選擇資產</option>
							<?php foreach ($assets as $asset): ?>
								<option value="<?= htmlspecialchars($asset['asset_id']) ?>" <?= $selectedAssetId === $asset['asset_id'] ? 'selected' : '' ?>>
									<?= htmlspecialchars($asset['asset_id']) ?>
								</option>
							<?php endforeach; ?>
						</select>
						<div class="asset-meta">
							<div>資產類型：<span id="asset-type">-</span></div>
						</div>
					</div>
				</div>

				<div class="form-row">
					<label for="sector_id">安裝位置</label>
					<div>
						<input class="readonly-field" id="sector_id" type="text" name="sector_id" value="<?= htmlspecialchars($selectedAsset['sector_id'] ?? '') ?>" readonly>
					</div>
				</div>

				<div class="form-row">
					<label for="observation">檢查敘述</label>
					<div>
						<textarea id="observation" name="observation" placeholder="請輸入檢查敘述" required><?= htmlspecialchars($_POST['observation'] ?? '') ?></textarea>
					</div>
				</div>

				<div class="form-row">
					<label for="risk_score">健康度評定</label>
					<div>
						<input id="risk_score" type="number" name="risk_score" min="0" max="100" value="<?= htmlspecialchars($_POST['risk_score'] ?? '62') ?>" required>
					</div>
				</div>

				<div class="form-row">
					<label>上傳照片</label>
					<div>
						<div id="photo-list" class="photo-list">
							<div class="photo-row">
								<input type="file" name="photos[]" accept="image/*">
							</div>
						</div>
						<div class="button-row" style="margin-top: 10px;">
							<button class="small-button" type="button" id="add-photo">新增</button>
						</div>
					</div>
				</div>

				<div class="button-row">
					<a class="secondary-button" href="inspections.php">返回</a>
					<button class="primary-button" type="submit">送出</button>
				</div>
			</form>
		</section>
	</div>

	<script>
		const assetsById = <?= json_encode($assetsById, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
		const assetSelect = document.getElementById('asset_id');
		const sectorField = document.getElementById('sector_id');
		const assetTypeField = document.getElementById('asset-type');
		const photoList = document.getElementById('photo-list');
		const addPhotoButton = document.getElementById('add-photo');

		function syncAssetFields() {
			const asset = assetsById[assetSelect.value];
			if (!asset) {
				sectorField.value = '';
				assetTypeField.textContent = '-';
				return;
			}

			sectorField.value = asset.sector_id || '';
			assetTypeField.textContent = asset.type || '-';
		}

		function createPhotoRow() {
			const row = document.createElement('div');
			row.className = 'photo-row';

			const input = document.createElement('input');
			input.type = 'file';
			input.name = 'photos[]';
			input.accept = 'image/*';

			const removeButton = document.createElement('button');
			removeButton.type = 'button';
			removeButton.className = 'small-button';
			removeButton.textContent = '刪除';
			removeButton.addEventListener('click', () => {
				if (photoList.querySelectorAll('.photo-row').length > 1) {
					row.remove();
				} else {
					input.value = '';
				}
			});

			row.appendChild(input);
			row.appendChild(removeButton);
			return row;
		}

		assetSelect.addEventListener('change', syncAssetFields);
		addPhotoButton.addEventListener('click', () => {
			photoList.appendChild(createPhotoRow());
		});

		syncAssetFields();
	</script>
</body>

</html>
