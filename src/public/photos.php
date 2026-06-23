<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/audit.php';
require_roles(['主管', '資產管理員', '巡檢員']);

$pdo = db();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload') {
        $logId = trim((string)($_POST['log_id'] ?? ''));
        $caption = trim((string)($_POST['caption'] ?? ''));

        $logStmt = $pdo->prepare('SELECT log_id, asset_id FROM InspectionLog WHERE log_id = :log_id');
        $logStmt->execute(['log_id' => $logId]);
        $log = $logStmt->fetch();

        if (!$log) {
            flash('danger', '找不到指定的巡檢紀錄。');
            header('Location: /photos.php');
            exit;
        }

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            flash('danger', '請選擇照片檔案。');
            header('Location: /photos.php?asset_id=' . urlencode($log['asset_id']));
            exit;
        }

        $file = $_FILES['photo'];
        if ((int)$file['size'] > 5 * 1024 * 1024) {
            flash('danger', '照片不得超過 5MB。');
            header('Location: /photos.php?asset_id=' . urlencode($log['asset_id']));
            exit;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string)$file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($extensions[$mime])) {
            flash('danger', '只接受 JPG、PNG 或 WEBP 圖片。');
            header('Location: /photos.php?asset_id=' . urlencode($log['asset_id']));
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/inspection';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('無法建立照片資料夾。');
        }

        $filename = sprintf(
            '%s-%s.%s',
            preg_replace('/[^A-Za-z0-9_-]/', '', $logId),
            bin2hex(random_bytes(8)),
            $extensions[$mime]
        );
        $target = $uploadDir . '/' . $filename;

        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            flash('danger', '照片儲存失敗，請檢查 uploads 資料夾權限。');
            header('Location: /photos.php?asset_id=' . urlencode($log['asset_id']));
            exit;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO Photos
            (log_id, asset_id, uploaded_by, file_name, mime_type, file_size, url, caption)
            VALUES
            (:log_id, :asset_id, :uploaded_by, :file_name, :mime_type, :file_size, :url, :caption)'
        );
        $stmt->execute([
            'log_id' => $logId,
            'asset_id' => $log['asset_id'],
            'uploaded_by' => $user['id_num'],
            'file_name' => basename((string)$file['name']),
            'mime_type' => $mime,
            'file_size' => (int)$file['size'],
            'url' => '/uploads/inspection/' . $filename,
            'caption' => $caption !== '' ? $caption : null,
        ]);

        $photoId = (string)$pdo->lastInsertId();
        write_audit('新增巡檢照片', 'Photos', $photoId, null, [
            'log_id' => $logId,
            'asset_id' => $log['asset_id'],
            'url' => '/uploads/inspection/' . $filename,
        ]);
        flash('success', '照片已上傳並綁定巡檢紀錄。');
        header('Location: /photos.php?asset_id=' . urlencode($log['asset_id']));
        exit;
    }

    if ($action === 'delete') {
        if (!in_array($user['role'], ['主管', '資產管理員'], true)) {
            http_response_code(403);
            exit('只有主管或資產管理員可刪除附件。');
        }

        $photoId = (int)($_POST['photo_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM Photos WHERE photo_id = :photo_id');
        $stmt->execute(['photo_id' => $photoId]);
        $photo = $stmt->fetch();

        if ($photo) {
            $pdo->prepare('DELETE FROM Photos WHERE photo_id = :photo_id')->execute(['photo_id' => $photoId]);
            $path = __DIR__ . $photo['url'];
            if (is_file($path)) {
                @unlink($path);
            }
            write_audit('刪除巡檢照片', 'Photos', (string)$photoId, $photo, null);
            flash('success', '照片已刪除。');
        }

        header('Location: /photos.php?' . query_string(['photo_id' => null]));
        exit;
    }
}

$assetId = trim((string)($_GET['asset_id'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$sql = '
SELECT p.*, i.observation, i.risk_score, i.inspec_time, e.name AS uploader_name
FROM Photos p
INNER JOIN InspectionLog i ON i.log_id = p.log_id
LEFT JOIN Employees e ON e.id_num = p.uploaded_by
WHERE 1=1
';
$params = [];
if ($assetId !== '') {
    $sql .= ' AND p.asset_id LIKE :asset_id';
    $params['asset_id'] = '%' . $assetId . '%';
}
if ($dateFrom !== '') {
    $sql .= ' AND DATE(i.inspec_time) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND DATE(i.inspec_time) <= :date_to';
    $params['date_to'] = $dateTo;
}
$sql .= ' ORDER BY i.inspec_time DESC, p.photo_id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

$logs = $pdo->query(
    'SELECT i.log_id, i.asset_id, i.inspec_time, LEFT(i.observation, 50) AS observation
     FROM InspectionLog i
     ORDER BY i.inspec_time DESC
     LIMIT 100'
)->fetchAll();

render_header('巡檢照片與附件管理');
?>
<div class="grid grid-2">
    <div class="card">
        <h2>查詢照片</h2>
        <form method="get" class="toolbar">
            <div class="field">
                <label>資產編號</label>
                <input name="asset_id" value="<?= e($assetId) ?>" placeholder="ASSET001">
            </div>
            <div class="field">
                <label>起始日期</label>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="field">
                <label>結束日期</label>
                <input type="date" name="date_to" value="<?= e($dateTo) ?>">
            </div>
            <button class="btn btn-primary">查詢</button>
            <a class="btn" href="/photos.php">清除</a>
        </form>
    </div>

    <div class="card">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="form-row">
                <div class="field" style="min-width:260px;">
                    <label>巡檢紀錄</label>
                    <select name="log_id" required>
                        <option value="">請選擇</option>
                        <?php foreach ($logs as $log): ?>
                            <option value="<?= e($log['log_id']) ?>">
                                <?= e($log['log_id']) ?>／<?= e($log['asset_id']) ?>／<?= e($log['inspec_time']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>照片</label>
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
                </div>
            </div>
            <div class="field" style="margin-top:10px;">
                <label>照片說明</label>
                <input name="caption" maxlength="255" placeholder="例如：底座鏽蝕特寫">
            </div>
            <button class="btn btn-success" type="submit" style="margin-top:12px;">上傳照片</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top:18px;">
    <div class="card-header">
        <h2>照片縮圖列表</h2>
        <span class="muted">共 <?= number_format(count($photos)) ?> 張</span>
    </div>
    <?php if (!$photos): ?>
        <div class="empty">沒有符合條件的照片。</div>
    <?php else: ?>
        <div class="photo-grid">
            <?php foreach ($photos as $photo): ?>
                <article class="photo-card">
                    <a href="<?= e($photo['url']) ?>" target="_blank" rel="noopener">
                        <img src="<?= e($photo['url']) ?>" alt="<?= e($photo['caption'] ?? $photo['file_name']) ?>">
                    </a>
                    <div class="photo-body">
                        <h4><?= e($photo['asset_id']) ?>／<?= e($photo['log_id']) ?></h4>
                        <div class="small muted"><?= e($photo['inspec_time']) ?></div>
                        <p><?= e($photo['caption'] ?? $photo['observation'] ?? '無說明') ?></p>
                        <div class="small muted">
                            上傳者：<?= e($photo['uploader_name'] ?? '-') ?><br>
                            <?= e($photo['mime_type']) ?>／<?= number_format((int)$photo['file_size'] / 1024, 1) ?> KB
                        </div>
                        <div class="photo-actions">
                            <a class="btn btn-sm btn-outline" href="<?= e($photo['url']) ?>" target="_blank">預覽</a>
                            <?php if (in_array($user['role'], ['主管', '資產管理員'], true)): ?>
                                <form method="post" onsubmit="return confirm('確定刪除這張照片？');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="photo_id" value="<?= (int)$photo['photo_id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">刪除</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
