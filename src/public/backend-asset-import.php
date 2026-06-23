<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager']);

$pdo = backend_db();

function xlsx_column_index(string $reference): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference));
    $index = 0;
    for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function backend_xml_text(string $xml): string
{
    $text = '';
    if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/s', $xml, $matches)) {
        foreach ($matches[1] as $part) {
            $text .= html_entity_decode(strip_tags((string) $part), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    return $text;
}

function read_xlsx_rows(string $source): array
{
    $temp = tempnam(sys_get_temp_dir(), 'xlsx_');
    if ($temp === false) {
        throw new RuntimeException('無法建立暫存檔。');
    }

    $zipPath = $temp . '.zip';
    @unlink($temp);
    if (!copy($source, $zipPath)) {
        throw new RuntimeException('無法讀取 Excel 檔案。');
    }

    try {
        $archive = new PharData($zipPath);
        $shared = [];

        if (isset($archive['xl/sharedStrings.xml'])) {
            $sharedXml = $archive['xl/sharedStrings.xml']->getContent();
            if (preg_match_all('/<si(?:\s[^>]*)?>(.*?)<\/si>/s', $sharedXml, $items)) {
                foreach ($items[1] as $item) {
                    $shared[] = backend_xml_text((string) $item);
                }
            }
        }

        if (!isset($archive['xl/worksheets/sheet1.xml'])) {
            throw new RuntimeException('Excel 找不到第一個工作表。');
        }

        $sheetXml = $archive['xl/worksheets/sheet1.xml']->getContent();
        if (!preg_match_all('/<row(?:\s[^>]*)?>(.*?)<\/row>/s', $sheetXml, $rowMatches)) {
            return [];
        }

        $rows = [];
        foreach ($rowMatches[1] as $rowXml) {
            $values = [];
            if (!preg_match_all('/<c([^>]*)>(.*?)<\/c>/s', (string) $rowXml, $cells, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($cells as $cell) {
                $attrs = (string) $cell[1];
                $body = (string) $cell[2];
                preg_match('/\br="([A-Z]+\d+)"/i', $attrs, $referenceMatch);
                $index = xlsx_column_index($referenceMatch[1] ?? 'A1');
                preg_match('/\bt="([^"]+)"/i', $attrs, $typeMatch);
                $type = $typeMatch[1] ?? '';
                $value = '';

                if ($type === 's' && preg_match('/<v>(.*?)<\/v>/s', $body, $valueMatch)) {
                    $value = $shared[(int) $valueMatch[1]] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = backend_xml_text($body);
                } elseif (preg_match('/<v>(.*?)<\/v>/s', $body, $valueMatch)) {
                    $value = html_entity_decode(trim(strip_tags((string) $valueMatch[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }

                $values[$index] = trim($value);
            }

            if ($values !== []) {
                ksort($values);
                $normalized = [];
                for ($i = 0, $max = max(array_keys($values)); $i <= $max; $i++) {
                    $normalized[] = $values[$i] ?? '';
                }
                $rows[] = $normalized;
            }
        }

        return $rows;
    } catch (UnexpectedValueException $e) {
        throw new RuntimeException('Excel 檔案無法解壓縮，請確認檔案未損壞。', 0, $e);
    } finally {
        @unlink($zipPath);
    }
}

function normalize_import_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
        $serial = (float) $value;
        if ($serial > 0) {
            $seconds = (int) round(($serial - 25569) * 86400);
            return gmdate('Y-m-d', $seconds);
        }
    }

    return $value;
}

function read_import_rows(string $path, string $extension): array
{
    if ($extension === 'xlsx') {
        return read_xlsx_rows($path);
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('無法讀取上傳檔案。');
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = array_map(static fn($value) => trim((string)$value), $row);
    }
    fclose($handle);
    return $rows;
}

if (isset($_GET['download']) && is_string($_GET['download'])) {
    $stmt = $pdo->prepare(
        'SELECT row_number, field_name, error_message, row_data
         FROM Importerror WHERE batch_id = ? ORDER BY row_number, error_id'
    );
    $stmt->execute([$_GET['download']]);
    $errors = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="import-errors-' . preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['download']) . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['row_number', 'field_name', 'error_message', 'row_data']);
    foreach ($errors as $error) {
        fputcsv($output, $error);
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    backend_verify_csrf();

    try {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('請選擇要匯入的 CSV 或 XLSX 檔案。');
        }

        $originalName = (string)$_FILES['import_file']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw new RuntimeException('只接受 CSV 或 XLSX 格式。');
        }

        $rows = read_import_rows((string)$_FILES['import_file']['tmp_name'], $extension);
        if (count($rows) < 2) {
            throw new RuntimeException('檔案沒有可匯入的資料列。');
        }

        $header = array_map(static fn($value) => strtolower(ltrim(trim((string) $value), "\xEF\xBB\xBF")), array_shift($rows));
        $required = ['asset_id', 'sector_id', 'type', 'spec_id', 'install_date', 'current_status'];
        $missing = array_values(array_diff($required, $header));
        if ($missing) {
            throw new RuntimeException('缺少必要欄位：' . implode(', ', $missing));
        }

        $batchId = 'IMP' . date('YmdHis') . random_int(100, 999);
        $insertBatch = $pdo->prepare(
            'INSERT INTO Importbatch
             (batch_id, file_name, uploaded_by, total_count, success_count, fail_count, status, created_at)
             VALUES (?, ?, ?, 0, 0, 0, ?, NOW())'
        );
        $insertBatch->execute([$batchId, $originalName, backend_user()['id'], '驗證中']);

        $existsAsset = $pdo->prepare('SELECT COUNT(*) FROM Powerasset WHERE asset_id = ?');
        $existsSector = $pdo->prepare('SELECT COUNT(*) FROM Sector WHERE sector_id = ?');
        $existsSpec = $pdo->prepare('SELECT COUNT(*) FROM Assetspec WHERE spec_id = ?');
        $insertAsset = $pdo->prepare(
            'INSERT INTO Powerasset (asset_id, sector_id, type, spec_id, gps) VALUES (?, ?, ?, ?, NULL)'
        );
        $insertHealth = $pdo->prepare(
            'INSERT INTO Health
             (asset_id, install_date, expected_lifespan, last_inspection_date, health_level,
              current_status, valid_from, valid_to)
             VALUES (?, ?, ?, NULL, ?, ?, ?, NULL)'
        );
        $insertError = $pdo->prepare(
            'INSERT INTO Importerror
             (error_id, batch_id, row_number, field_name, error_message, row_data)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $success = 0;
        $fail = 0;
        $total = 0;

        foreach ($rows as $offset => $values) {
            if (count(array_filter($values, static fn($value) => trim((string)$value) !== '')) === 0) {
                continue;
            }

            $total++;
            $rowNumber = $offset + 2;
            $row = [];
            foreach ($header as $index => $name) {
                $row[$name] = trim((string) ($values[$index] ?? ''));
            }
            if (isset($row['install_date'])) {
                $row['install_date'] = normalize_import_date($row['install_date']);
            }

            $errors = [];
            foreach ($required as $field) {
                if (($row[$field] ?? '') === '') {
                    $errors[$field] = '必填欄位不可空白';
                }
            }

            if (($row['install_date'] ?? '') !== '' && DateTime::createFromFormat('Y-m-d', $row['install_date']) === false) {
                $errors['install_date'] = '日期格式必須為 YYYY-MM-DD';
            }

            if (!in_array($row['current_status'] ?? '', ['運作中', '維修中', '報廢'], true)) {
                $errors['current_status'] = '狀態只能是運作中、維修中或報廢';
            }

            if (!in_array($row['health_level'] ?? '良', ['優', '良', '待修', '危險'], true)) {
                $errors['health_level'] = '健康度只能是優、良、待修或危險';
            }

            if (!$errors) {
                $existsAsset->execute([$row['asset_id']]);
                if ((int)$existsAsset->fetchColumn() > 0) {
                    $errors['asset_id'] = '資產編號已存在';
                }

                $existsSector->execute([$row['sector_id']]);
                if ((int)$existsSector->fetchColumn() === 0) {
                    $errors['sector_id'] = '饋線區不存在';
                }

                $existsSpec->execute([$row['spec_id']]);
                if ((int)$existsSpec->fetchColumn() === 0) {
                    $errors['spec_id'] = '規格編號不存在';
                }
            }

            if ($errors) {
                $fail++;
                foreach ($errors as $field => $message) {
                    $insertError->execute([
                        'ERR' . date('YmdHis') . random_int(1000, 9999),
                        $batchId,
                        $rowNumber,
                        $field,
                        $message,
                        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
                continue;
            }

            try {
                $pdo->beginTransaction();
                $insertAsset->execute([$row['asset_id'], $row['sector_id'], $row['type'], $row['spec_id']]);
                $insertHealth->execute([
                    $row['asset_id'],
                    $row['install_date'],
                    max(1, (int)($row['expected_lifespan'] ?? 30)),
                    $row['health_level'] ?? '良',
                    $row['current_status'],
                    $row['install_date'] . ' 00:00:00',
                ]);
                $pdo->commit();
                $success++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $fail++;
                $insertError->execute([
                    'ERR' . date('YmdHis') . random_int(1000, 9999),
                    $batchId,
                    $rowNumber,
                    'database',
                    $e->getMessage(),
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        }

        $status = $fail === 0 ? '成功' : ($success > 0 ? '部分失敗' : '失敗');
        $update = $pdo->prepare(
            'UPDATE Importbatch
             SET total_count = ?, success_count = ?, fail_count = ?, status = ?
             WHERE batch_id = ?'
        );
        $update->execute([$total, $success, $fail, $status, $batchId]);

        backend_audit('資料匯入與格式驗證', '匯入', 'Importbatch', $batchId, null, [
            'file_name' => $originalName,
            'total' => $total,
            'success' => $success,
            'fail' => $fail,
        ]);

        backend_flash('success', "匯入完成：成功 {$success} 筆，失敗 {$fail} 筆。");
        backend_redirect('/backend-asset-import.php?batch=' . urlencode($batchId));
    } catch (Throwable $e) {
        backend_flash('error', $e->getMessage());
        backend_redirect('/backend-asset-import.php');
    }
}

$batches = [];
$batchErrors = [];
$selectedBatch = is_string($_GET['batch'] ?? null) ? $_GET['batch'] : '';

try {
    $batches = $pdo->query(
        'SELECT batch_id, file_name, uploaded_by, total_count, success_count, fail_count, status, created_at
         FROM Importbatch ORDER BY created_at DESC LIMIT 20'
    )->fetchAll();

    if ($selectedBatch !== '') {
        $stmt = $pdo->prepare(
            'SELECT row_number, field_name, error_message, row_data
             FROM Importerror WHERE batch_id = ? ORDER BY row_number, error_id'
        );
        $stmt->execute([$selectedBatch]);
        $batchErrors = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    backend_flash('error', $e->getMessage());
}

backend_render_header('資料匯入與格式驗證', '批次匯入資產資料，檢查必填欄位、關聯資料及重複編號。');
?>

<div class="grid grid-2">
    <article class="card">
        <h2>上傳檔案</h2>
        <form method="post" enctype="multipart/form-data">
            <?= backend_csrf_field() ?>
            <label>
                CSV 或 XLSX
                <input type="file" name="import_file" accept=".csv,.xlsx" required>
            </label>
            <p class="text-muted">
                必填欄位：asset_id、sector_id、type、spec_id、install_date、current_status。
                可選欄位：health_level、expected_lifespan。
            </p>
            <button type="submit">驗證並匯入</button>
            <a class="button secondary" href="/backend-sample-assets.csv">下載範例</a>
        </form>
    </article>

    <article class="card">
        <h2>驗證規則</h2>
        <ul>
            <li>資產編號不可重複。</li>
            <li>饋線區與規格編號必須已存在。</li>
            <li>日期必須使用 YYYY-MM-DD。</li>
            <li>通過驗證後，同步建立 Powerasset 與 Health 資料。</li>
        </ul>
    </article>
</div>

<article class="card" style="margin-top:18px">
    <h2>匯入紀錄</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>批次</th><th>檔案</th><th>總筆數</th><th>成功</th><th>失敗</th><th>狀態</th><th>時間</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><?= backend_e($batch['batch_id']) ?></td>
                    <td><?= backend_e($batch['file_name']) ?></td>
                    <td><?= (int)$batch['total_count'] ?></td>
                    <td><?= (int)$batch['success_count'] ?></td>
                    <td><?= (int)$batch['fail_count'] ?></td>
                    <td><?= backend_e($batch['status']) ?></td>
                    <td><?= backend_e($batch['created_at']) ?></td>
                    <td>
                        <?php if ((int)$batch['fail_count'] > 0): ?>
                            <a href="?batch=<?= urlencode($batch['batch_id']) ?>">查看錯誤</a>
                            ｜<a href="?download=<?= urlencode($batch['batch_id']) ?>">下載 CSV</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$batches): ?><tr><td colspan="8" class="empty">尚無匯入紀錄。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php if ($selectedBatch !== ''): ?>
<article class="card" style="margin-top:18px">
    <h2>錯誤清單：<?= backend_e($selectedBatch) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>資料列</th><th>欄位</th><th>原因</th><th>原始資料</th></tr></thead>
            <tbody>
            <?php foreach ($batchErrors as $error): ?>
                <tr>
                    <td><?= (int)$error['row_number'] ?></td>
                    <td><?= backend_e($error['field_name']) ?></td>
                    <td><?= backend_e($error['error_message']) ?></td>
                    <td><code><?= backend_e($error['row_data']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$batchErrors): ?><tr><td colspan="4" class="empty">沒有錯誤資料。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>
<?php endif; ?>

<?php backend_render_footer(); ?>
