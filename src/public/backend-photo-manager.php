<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager', 'inspector']);

$pdo = backend_db();
$user = backend_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    backend_verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'delete') {
            $photoId = trim((string)($_POST['photo_id'] ?? ''));
            $stmt = $pdo->prepare('SELECT * FROM Photos WHERE photo_id = ? AND is_deleted = 0');
            $stmt->execute([$photoId]);
            $photo = $stmt->fetch();
            if (!$photo) {
                throw new RuntimeException('找不到照片。');
            }

            $update = $pdo->prepare('UPDATE Photos SET is_deleted = 1 WHERE photo_id = ?');
            $update->execute([$photoId]);
            backend_audit('巡檢照片與附件管理', '刪除', 'Photos', $photoId, $photo, ['is_deleted' => 1]);
            backend_flash('success', '照片已標記刪除。');
        } elseif ($action === 'upload') {
            $assetId = trim((string)($_POST['asset_id'] ?? ''));
            $inspecId = trim((string)($_POST['inspec_id'] ?? ''));

            if ($assetId === '') {
                throw new RuntimeException('請輸入資產編號。');
            }
            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('請選擇照片。');
            }

            $exists = $pdo->prepare('SELECT COUNT(*) FROM Powerasset WHERE asset_id = ?');
            $exists->execute([$assetId]);
            if ((int)$exists->fetchColumn() === 0) {
                throw new RuntimeException('資產編號不存在。');
            }

            if ($inspecId !== '') {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM Inspectionlog WHERE inspec_id = ? AND asset_id = ?');
                $exists->execute([$inspecId, $assetId]);
                if ((int)$exists->fetchColumn() === 0) {
                    throw new RuntimeException('巡檢紀錄與資產不相符。');
                }
            }

            $original = basename((string) $_FILES['photo']['name']);
            $temporaryPath = (string) $_FILES['photo']['tmp_name'];
            $imageInfo = @getimagesize($temporaryPath);
            $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if ($imageInfo === false || !isset($allowedMime[$imageInfo['mime'] ?? ''])) {
                throw new RuntimeException('上傳檔案不是有效的 JPG、PNG 或 WEBP 圖片。');
            }
            $extension = $allowedMime[$imageInfo['mime']];

            $uploadDir = __DIR__ . '/uploads/backend-inspection';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('無法建立上傳資料夾。');
            }

            $safeName = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
            $target = $uploadDir . '/' . $safeName;
            if (!move_uploaded_file($temporaryPath, $target)) {
                throw new RuntimeException('照片儲存失敗。');
            }

            $photoId = 'PH' . date('YmdHis') . random_int(100, 999);
            $url = 'uploads/backend-inspection/' . $safeName;
            $insert = $pdo->prepare(
                'INSERT INTO Photos
                 (photo_id, asset_id, inspec_id, maint_id, url, filename, uploaded_by, uploaded_at, is_deleted)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, NOW(), 0)'
            );
            $insert->execute([$photoId, $assetId, $inspecId !== '' ? $inspecId : null, $url, $original, $user['id']]);

            backend_audit('巡檢照片與附件管理', '新增', 'Photos', $photoId, null, [
                'asset_id' => $assetId,
                'inspec_id' => $inspecId,
                'url' => $url,
            ]);
            backend_flash('success', '照片上傳完成。');
        }
    } catch (Throwable $e) {
        backend_flash('error', $e->getMessage());
    }

    backend_redirect('/backend-photo-manager.php');
}

$assetId = trim((string)($_GET['asset_id'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = ['p.is_deleted = 0'];
$params = [];
if ($assetId !== '') {
    $where[] = 'p.asset_id = ?';
    $params[] = $assetId;
}
if ($dateFrom !== '') {
    $where[] = 'DATE(p.uploaded_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(p.uploaded_at) <= ?';
    $params[] = $dateTo;
}

$stmt = $pdo->prepare(
    'SELECT p.*, i.observation, i.inspec_time, e.fullname AS uploader_name
     FROM Photos p
     LEFT JOIN Inspectionlog i ON i.inspec_id = p.inspec_id
     LEFT JOIN Employees e ON e.id_num = p.uploaded_by
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY p.uploaded_at DESC LIMIT 200'
);
$stmt->execute($params);
$photos = $stmt->fetchAll();

backend_render_header('巡檢照片與附件管理', '查詢、預覽、上傳及刪除綁定巡檢紀錄的照片。');
?>

<div class="grid grid-2">
    <article class="card">
        <h2>查詢照片</h2>
        <form method="get" class="form-grid">
            <label>資產編號<input name="asset_id" value="<?= backend_e($assetId) ?>"></label>
            <label>開始日期<input type="date" name="date_from" value="<?= backend_e($dateFrom) ?>"></label>
            <label>結束日期<input type="date" name="date_to" value="<?= backend_e($dateTo) ?>"></label>
            <div><button type="submit">查詢</button> <a class="button secondary" href="backend-photo-manager.php">清除</a></div>
        </form>
    </article>

    <article class="card">
        <h2>新增附件</h2>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <?= backend_csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <label>資產編號<input name="asset_id" required></label>
            <label>巡檢紀錄編號<input name="inspec_id" placeholder="可留空"></label>
            <label>照片<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
            <div><button type="submit">上傳照片</button></div>
        </form>
    </article>
</div>

<div class="photo-grid" style="margin-top:18px">
    <?php foreach ($photos as $photo): ?>
        <article class="photo-card">
            <a href="/<?= backend_e($photo['url']) ?>" target="_blank" rel="noopener">
                <img src="/<?= backend_e($photo['url']) ?>" alt="<?= backend_e($photo['filename']) ?>">
            </a>
            <div class="photo-card-body">
                <strong><?= backend_e($photo['asset_id']) ?></strong>
                <span>巡檢：<?= backend_e($photo['inspec_id'] ?: '未綁定') ?></span>
                <span>上傳者：<?= backend_e($photo['uploader_name'] ?: $photo['uploaded_by']) ?></span>
                <span>時間：<?= backend_e($photo['uploaded_at']) ?></span>
                <?php if (!empty($photo['observation'])): ?><span><?= backend_e($photo['observation']) ?></span><?php endif; ?>
                <form method="post" onsubmit="return confirm('確定刪除這張照片？')">
                    <?= backend_csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="photo_id" value="<?= backend_e($photo['photo_id']) ?>">
                    <button class="danger small" type="submit">刪除附件</button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php if (!$photos): ?><div class="card empty" style="margin-top:18px">沒有符合條件的照片。</div><?php endif; ?>

<?php backend_render_footer(); ?>
