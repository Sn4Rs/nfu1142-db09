<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager']);

$pdo = backend_db();

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$employeeId = trim((string)($_GET['employee_id'] ?? ''));
$functionName = trim((string)($_GET['function_name'] ?? ''));

$where = ['1=1'];
$params = [];
if ($dateFrom !== '') { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $dateTo; }
if ($employeeId !== '') { $where[] = 'a.employee_id = ?'; $params[] = $employeeId; }
if ($functionName !== '') { $where[] = 'a.function_name LIKE ?'; $params[] = '%' . $functionName . '%'; }

$logs = [];
$error = '';
try {
    $stmt = $pdo->prepare(
        'SELECT a.*, e.fullname, e.role
         FROM Auditlog a
         LEFT JOIN Employees e ON e.id_num = a.employee_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY a.created_at DESC, a.audit_id DESC LIMIT 500'
    );
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$employees = $pdo->query('SELECT id_num, fullname, role FROM Employees ORDER BY fullname')->fetchAll();

backend_render_header('操作紀錄與權限稽核', '查詢重要新增、修改、刪除、簽核與匯入紀錄，追蹤異動前後內容及 IP。');
?>

<?php if ($error !== ''): ?><div class="alert alert-error">讀取失敗：<?= backend_e($error) ?></div><?php endif; ?>

<article class="card">
    <form method="get" class="form-grid">
        <label>開始日期<input type="date" name="date_from" value="<?= backend_e($dateFrom) ?>"></label>
        <label>結束日期<input type="date" name="date_to" value="<?= backend_e($dateTo) ?>"></label>
        <label>操作人員
            <select name="employee_id"><option value="">全部</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?= backend_e($employee['id_num']) ?>" <?= $employeeId === $employee['id_num'] ? 'selected' : '' ?>>
                        <?= backend_e($employee['fullname'] . '（' . backend_role_label($employee['role']) . '）') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>功能名稱<input name="function_name" value="<?= backend_e($functionName) ?>"></label>
        <div><button type="submit">查詢</button> <a class="button secondary" href="backend-audit-log.php">清除</a></div>
    </form>
</article>

<article class="card" style="margin-top:18px">
    <h2>稽核紀錄</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>時間</th><th>操作人員</th><th>功能</th><th>動作</th><th>目標</th><th>結果</th><th>IP</th><th>異動內容</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= backend_e($log['created_at']) ?></td>
                    <td><?= backend_e(($log['fullname'] ?: $log['employee_id']) . ' / ' . backend_role_label((string)($log['role'] ?? ''))) ?></td>
                    <td><?= backend_e($log['function_name']) ?></td>
                    <td><?= backend_e($log['action_type']) ?></td>
                    <td><?= backend_e(($log['target_type'] ?? '') . ' ' . ($log['target_id'] ?? '')) ?></td>
                    <td><span class="badge <?= ($log['result'] ?? '') === '成功' ? 'badge-success' : 'badge-danger' ?>"><?= backend_e($log['result']) ?></span></td>
                    <td><?= backend_e($log['ip_address']) ?></td>
                    <td>
                        <details>
                            <summary>查看</summary>
                            <strong>異動前</strong><pre><?= backend_e($log['old_data']) ?></pre>
                            <strong>異動後</strong><pre><?= backend_e($log['new_data']) ?></pre>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="8" class="empty">沒有符合條件的稽核紀錄。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php backend_render_footer(); ?>
