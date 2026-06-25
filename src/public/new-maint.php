<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
backend_require_roles(['technician', 'assetmanager', 'deptmanager']);

$pdo = backend_db();
$user = backend_user();
$isManager = in_array($user['role'], ['assetmanager', 'deptmanager'], true);
$message = '';
$messageType = 'error';
$maintId = trim((string) ($_POST['maint_id'] ?? $_GET['record_id'] ?? $_GET['maint_id'] ?? ''));
$record = null;
$existingPhotos = [];

function maint_datetime_local(?string $value): string
{
    if (!$value) {
        return '';
    }
    $time = strtotime($value);
    return $time ? date('Y-m-d\TH:i', $time) : '';
}

function maint_mysql_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $time = strtotime($value);
    return $time ? date('Y-m-d H:i:s', $time) : null;
}

function maint_parse_items(string $action, string $overallStatus): array
{
    $items = [];
    foreach (preg_split('/\R/u', trim($action)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^\[(完成|缺件)\]\s*(.+)$/u', $line, $matches)) {
            $items[] = ['name' => trim($matches[2]), 'status' => $matches[1]];
        } else {
            $items[] = ['name' => $line, 'status' => $overallStatus === '缺件' ? '缺件' : '完成'];
        }
    }
    return $items !== [] ? $items : [['name' => '', 'status' => '完成']];
}

$assets = $pdo->query(
    'SELECT p.asset_id, p.type, p.sector_id, s.feeder_area, s.city, s.street
     FROM Powerasset p LEFT JOIN Sector s ON s.sector_id = p.sector_id
     ORDER BY p.asset_id'
)->fetchAll();
$parts = $pdo->query('SELECT part_id, part_name, stock FROM Partspecs ORDER BY part_id')->fetchAll();

if ($maintId !== '') {
    $stmt = $pdo->prepare('SELECT * FROM Maintenancelog WHERE maint_id = ? LIMIT 1');
    $stmt->execute([$maintId]);
    $record = $stmt->fetch();
    if (!$record) {
        $message = '找不到指定的維修紀錄。';
    } elseif (!$isManager && (string) $record['technician_id'] !== $user['id']) {
        http_response_code(403);
        exit('403 權限不足：只能填寫自己的維修工作。');
    } else {
        $photoStmt = $pdo->prepare(
            'SELECT photo_id, url, filename, uploaded_at FROM Photos
             WHERE maint_id = ? AND is_deleted = 0 ORDER BY uploaded_at DESC'
        );
        $photoStmt->execute([$maintId]);
        $existingPhotos = $photoStmt->fetchAll();
    }
}

$selectedAssetId = (string) ($_POST['asset_id'] ?? $record['asset_id'] ?? '');
$scheduledTime = (string) ($_POST['scheduled_time'] ?? maint_datetime_local($record['scheduled_time'] ?? null));
$maintTime = (string) ($_POST['maint_time'] ?? maint_datetime_local($record['maint_time'] ?? null));
$details = (string) ($_POST['details'] ?? $record['details'] ?? '');
$itemRows = isset($_POST['item_name']) && is_array($_POST['item_name'])
    ? array_map(
        static fn($index, $name): array => [
            'name' => trim((string) $name),
            'status' => (string) ($_POST['item_status'][$index] ?? '完成'),
        ],
        array_keys($_POST['item_name']),
        $_POST['item_name']
    )
    : maint_parse_items((string) ($record['action'] ?? ''), (string) ($record['status'] ?? '完成'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    backend_verify_csrf();

    try {
        if ($selectedAssetId === '') {
            throw new RuntimeException('請選擇資產。');
        }
        $assetExists = $pdo->prepare('SELECT COUNT(*) FROM Powerasset WHERE asset_id = ?');
        $assetExists->execute([$selectedAssetId]);
        if ((int) $assetExists->fetchColumn() === 0) {
            throw new RuntimeException('資產編號不存在。');
        }

        $normalizedItems = [];
        $hasMissingItem = false;
        foreach ($itemRows as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $status = (string) ($item['status'] ?? '完成');
            if ($name === '') {
                continue;
            }
            if (!in_array($status, ['完成', '缺件'], true)) {
                throw new RuntimeException('維修項目狀態錯誤。');
            }
            $hasMissingItem = $hasMissingItem || $status === '缺件';
            $normalizedItems[] = ['name' => $name, 'status' => $status];
        }
        if ($normalizedItems === []) {
            throw new RuntimeException('請至少填寫一項維修項目。');
        }

        $scheduledMysql = maint_mysql_datetime($scheduledTime);
        $maintMysql = maint_mysql_datetime($maintTime);
        if ($scheduledMysql === null) {
            throw new RuntimeException('請填寫排定時間。');
        }
        if ($maintMysql === null) {
            throw new RuntimeException('請填寫實際維護時間。');
        }
        if (trim($details) === '') {
            throw new RuntimeException('請填寫維修動作敘述。');
        }

        $requestLines = [];
        $partIds = $_POST['part_id'] ?? [];
        $quantities = $_POST['req_qty'] ?? [];
        if ($hasMissingItem) {
            if (!is_array($partIds) || !is_array($quantities)) {
                throw new RuntimeException('缺件資料格式錯誤。');
            }
            $partCheck = $pdo->prepare('SELECT part_id FROM Partspecs WHERE part_id = ?');
            foreach ($partIds as $index => $partValue) {
                $partId = trim((string) $partValue);
                $quantity = (int) ($quantities[$index] ?? 0);
                if ($partId === '' && $quantity === 0) {
                    continue;
                }
                if ($partId === '' || $quantity <= 0) {
                    throw new RuntimeException('缺件時，每一項零件都必須填寫零件編號及正整數數量。');
                }
                $partCheck->execute([$partId]);
                if (!$partCheck->fetch()) {
                    throw new RuntimeException('零件編號不存在：' . $partId);
                }
                $requestLines[$partId] = ($requestLines[$partId] ?? 0) + $quantity;
            }
            if ($requestLines === []) {
                throw new RuntimeException('有維修項目標記缺件時，必須填寫缺少零件。');
            }
        }

        $actionText = implode("\n", array_map(
            static fn(array $item): string => '[' . $item['status'] . '] ' . $item['name'],
            $normalizedItems
        ));
        $overallStatus = $hasMissingItem ? '缺件' : '完成';

        $pdo->beginTransaction();
        if ($record) {
            $stmt = $pdo->prepare(
                'UPDATE Maintenancelog
                 SET asset_id = ?, action = ?, details = ?, scheduled_time = ?, maint_time = ?, status = ?
                 WHERE maint_id = ?'
            );
            $stmt->execute([
                $selectedAssetId,
                $actionText,
                trim($details),
                $scheduledMysql,
                $maintMysql,
                $overallStatus,
                $maintId,
            ]);
        } else {
            $maintId = 'MT' . date('YmdHis') . random_int(100, 999);
            $stmt = $pdo->prepare(
                'INSERT INTO Maintenancelog
                 (maint_id, technician_id, asset_id, action, details, maint_time, status,
                  scheduled_time, assigned_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $maintId,
                $user['id'],
                $selectedAssetId,
                $actionText,
                trim($details),
                $maintMysql,
                $overallStatus,
                $scheduledMysql,
                $user['id'],
            ]);
        }

        $deleteIds = $_POST['delete_photo_ids'] ?? [];
        if (is_array($deleteIds) && $deleteIds !== []) {
            $deletePhoto = $pdo->prepare('UPDATE Photos SET is_deleted = 1 WHERE photo_id = ? AND maint_id = ?');
            foreach ($deleteIds as $photoId) {
                $deletePhoto->execute([trim((string) $photoId), $maintId]);
            }
        }

        if (isset($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
            $uploadDir = __DIR__ . '/uploads/maintenance';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('無法建立照片資料夾。');
            }
            $photoInsert = $pdo->prepare(
                'INSERT INTO Photos
                 (photo_id, asset_id, inspec_id, maint_id, url, filename, uploaded_by, uploaded_at, is_deleted)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, NOW(), 0)'
            );
            foreach ($_FILES['photos']['name'] as $index => $originalValue) {
                if (($_FILES['photos']['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if (($_FILES['photos']['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('照片上傳失敗。');
                }
                $tmp = (string) $_FILES['photos']['tmp_name'][$index];
                if (@getimagesize($tmp) === false) {
                    throw new RuntimeException('上傳檔案不是有效圖片。');
                }
                $original = basename((string) $originalValue);
                $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    throw new RuntimeException('照片只接受 JPG、PNG 或 WEBP。');
                }
                $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
                if (!move_uploaded_file($tmp, $uploadDir . '/' . $storedName)) {
                    throw new RuntimeException('照片寫入失敗。');
                }
                $photoInsert->execute([
                    'PH' . date('YmdHis') . random_int(1000, 9999),
                    $selectedAssetId,
                    $maintId,
                    'uploads/maintenance/' . $storedName,
                    $original,
                    $user['id'],
                ]);
            }
        }

        if ($hasMissingItem) {
            $requestTechnicianId = (string) ($record['technician_id'] ?? $user['id']);
            $pendingStmt = $pdo->prepare(
                "SELECT request_id FROM Partsrequest WHERE maint_id = ? AND status = '待審' LIMIT 1"
            );
            $pendingStmt->execute([$maintId]);
            $requestId = (string) ($pendingStmt->fetchColumn() ?: '');

            if ($requestId === '') {
                $requestId = 'PR' . date('YmdHis') . random_int(100, 999);
                $pdo->prepare(
                    'INSERT INTO Partsrequest
                     (request_id, maint_id, technician_id, status, request_time)
                     VALUES (?, ?, ?, ?, NOW())'
                )->execute([$requestId, $maintId, $requestTechnicianId, '待審']);
            } else {
                $pdo->prepare('DELETE FROM Req_part WHERE request_id = ?')->execute([$requestId]);
                $pdo->prepare(
                    'UPDATE Partsrequest SET technician_id = ?, request_time = NOW(), reject_reason = NULL
                     WHERE request_id = ?'
                )->execute([$requestTechnicianId, $requestId]);
            }

            $lineInsert = $pdo->prepare(
                'INSERT INTO Req_part (request_id, part_id, req_qty, status) VALUES (?, ?, ?, ?)'
            );
            foreach ($requestLines as $partId => $quantity) {
                $lineInsert->execute([$requestId, $partId, $quantity, '待審']);
            }

            $receivers = $pdo->query(
                "SELECT id_num, role FROM Employees
                 WHERE status = 'active' AND role IN ('assetmanager','deptmanager')"
            )->fetchAll();
            $notify = $pdo->prepare(
                'INSERT INTO Notification
                 (notification_id, receiver_id, receiver_role, source_type, source_id,
                  title, content, is_read, created_at, read_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NULL)'
            );
            foreach ($receivers as $receiver) {
                $notify->execute([
                    'NTF' . date('YmdHis') . random_int(1000, 9999),
                    $receiver['id_num'],
                    $receiver['role'],
                    '零件申請',
                    $requestId,
                    '維修工作缺件待審核',
                    '維修單 ' . $maintId . ' 已送出缺件申請。',
                ]);
            }
        }

        $pdo->commit();
        backend_audit('維修紀錄填報', $record ? '修改' : '新增', 'Maintenancelog', $maintId, $record, [
            'asset_id' => $selectedAssetId,
            'action' => $actionText,
            'status' => $overallStatus,
        ]);
        $_SESSION['flash_message'] = '維修紀錄已儲存。';
        $_SESSION['flash_type'] = 'success';
        header('Location: maint-history.php?record_id=' . rawurlencode($maintId));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>維修紀錄填報 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .main{margin-left:210px;padding:20px}.page-card{border:1px solid #ddd;border-radius:8px;padding:18px;background:#fff;max-width:1050px}
        .form-grid{display:grid;gap:15px}.form-row{display:grid;grid-template-columns:140px 1fr;gap:12px;align-items:start}.form-row>label{font-weight:700;padding-top:8px}
        input,select,textarea,button{font:inherit;padding:9px;box-sizing:border-box}input,select,textarea{width:100%}textarea{min-height:110px}
        .item-row,.part-row{display:grid;grid-template-columns:1fr 140px auto;gap:9px;margin-bottom:9px}.photo-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
        .photo-card{border:1px solid #ddd;padding:8px;border-radius:6px}.photo-card img{width:100%;height:120px;object-fit:cover}.error{background:#fdeaea;color:#8b1a1a;padding:12px;border-radius:6px}
        .hint{color:#666;font-size:14px}.location-box{padding:10px;background:#f7f7f7;border-radius:6px}.parts-panel{display:none;border-left:4px solid #d99a00;padding:12px;background:#fff9e8}
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
    <?php require_once __DIR__ . '/backend-menu.php'; backend_render_menu(); ?>
</div>
<div class="main">
    <h1><?= $record ? '編輯維修紀錄' : '新增維修紀錄' ?></h1>
    <?php if ($message !== ''): ?><p class="error"><?= backend_e($message) ?></p><?php endif; ?>
    <div class="page-card">
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <?= backend_csrf_field() ?>
            <input type="hidden" name="maint_id" value="<?= backend_e($maintId) ?>">

            <div class="form-row"><label for="asset_id">項目／資產</label><div>
                <select id="asset_id" name="asset_id" required>
                    <option value="">請選擇</option>
                    <?php foreach ($assets as $asset): ?>
                        <option value="<?= backend_e($asset['asset_id']) ?>"
                            data-location="<?= backend_e(($asset['sector_id'] ?? '') . ' ' . ($asset['feeder_area'] ?? '') . ' ' . ($asset['city'] ?? '') . ' ' . ($asset['street'] ?? '')) ?>"
                            data-type="<?= backend_e($asset['type']) ?>"
                            <?= $selectedAssetId === $asset['asset_id'] ? 'selected' : '' ?>>
                            <?= backend_e($asset['asset_id'] . '／' . $asset['type']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="asset-location" class="location-box">安裝位置會依資產自動顯示。</div>
            </div></div>

            <div class="form-row"><label>維修項目</label><div>
                <div id="items-container">
                    <?php foreach ($itemRows as $item): ?>
                        <div class="item-row">
                            <input name="item_name[]" value="<?= backend_e($item['name']) ?>" placeholder="例如：清潔礙子" required>
                            <select name="item_status[]"><option value="完成" <?= $item['status'] === '完成' ? 'selected' : '' ?>>完成</option><option value="缺件" <?= $item['status'] === '缺件' ? 'selected' : '' ?>>缺件</option></select>
                            <button type="button" onclick="removeRow(this)">刪除</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="add-item">新增額外維修項目</button>
            </div></div>

            <div class="form-row"><label for="details">完成動作敘述</label><textarea id="details" name="details" required><?= backend_e($details) ?></textarea></div>
            <div class="form-row"><label for="scheduled_time">排定時間</label><input id="scheduled_time" type="datetime-local" name="scheduled_time" value="<?= backend_e($scheduledTime) ?>" required></div>
            <div class="form-row"><label for="maint_time">實際維護時間</label><input id="maint_time" type="datetime-local" name="maint_time" value="<?= backend_e($maintTime) ?>" required></div>

            <?php if ($existingPhotos !== []): ?>
            <div class="form-row"><label>既有照片</label><div class="photo-list">
                <?php foreach ($existingPhotos as $photo): ?>
                    <label class="photo-card"><img src="<?= backend_e($photo['url']) ?>" alt=""><span><?= backend_e($photo['filename']) ?></span><br><input type="checkbox" name="delete_photo_ids[]" value="<?= backend_e($photo['photo_id']) ?>"> 刪除照片</label>
                <?php endforeach; ?>
            </div></div>
            <?php endif; ?>

            <div class="form-row"><label>新增現場照片</label><div><div id="photo-container"><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp"></div><button type="button" id="add-photo">新增照片欄位</button></div></div>

            <div id="parts-panel" class="parts-panel">
                <h3>缺件申請</h3>
                <p class="hint">只要任一維修項目選擇「缺件」，就必須填寫缺少零件與數量。</p>
                <datalist id="parts-options">
                    <?php foreach ($parts as $part): ?><option value="<?= backend_e($part['part_id']) ?>"><?= backend_e(($part['part_name'] ?? '') . '／庫存 ' . ($part['stock'] ?? 0)) ?></option><?php endforeach; ?>
                </datalist>
                <div id="parts-container"><div class="part-row"><input name="part_id[]" list="parts-options" placeholder="零件編號"><input type="number" min="1" name="req_qty[]" placeholder="數量"><button type="button" onclick="removeRow(this)">刪除</button></div></div>
                <button type="button" id="add-part">新增零件</button>
            </div>

            <div><button type="submit">儲存維修紀錄</button> <a href="maint-todo.php">返回</a></div>
        </form>
    </div>
</div>
<script>
function removeRow(button){ if(button.parentElement.parentElement.children.length > 1){ button.parentElement.remove(); } }
const itemContainer = document.getElementById('items-container');
document.getElementById('add-item').addEventListener('click',()=>{
    const row=document.createElement('div');row.className='item-row';row.innerHTML='<input name="item_name[]" placeholder="新增維修項目" required><select name="item_status[]"><option value="完成">完成</option><option value="缺件">缺件</option></select><button type="button" onclick="removeRow(this)">刪除</button>';itemContainer.appendChild(row);refreshParts();
});
document.getElementById('add-part').addEventListener('click',()=>{const row=document.createElement('div');row.className='part-row';row.innerHTML='<input name="part_id[]" list="parts-options" placeholder="零件編號"><input type="number" min="1" name="req_qty[]" placeholder="數量"><button type="button" onclick="removeRow(this)">刪除</button>';document.getElementById('parts-container').appendChild(row);});
document.getElementById('add-photo').addEventListener('click',()=>{const input=document.createElement('input');input.type='file';input.name='photos[]';input.accept='image/jpeg,image/png,image/webp';document.getElementById('photo-container').appendChild(input);});
function refreshParts(){const missing=[...document.querySelectorAll('select[name="item_status[]"]')].some(s=>s.value==='缺件');document.getElementById('parts-panel').style.display=missing?'block':'none';}
itemContainer.addEventListener('change',refreshParts);refreshParts();
function refreshLocation(){const select=document.getElementById('asset_id');const option=select.options[select.selectedIndex];document.getElementById('asset-location').textContent=option && option.value ? '類別：'+option.dataset.type+'｜安裝位置：'+option.dataset.location : '安裝位置會依資產自動顯示。';}
document.getElementById('asset_id').addEventListener('change',refreshLocation);refreshLocation();
</script>
</body>
</html>
