<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Dotenv\Dotenv;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

function api_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;port=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'db-db',
        $_ENV['DB_NAME'] ?? 'csieDBTeam09',
        $_ENV['DB_PORT'] ?? '3306'
    );

    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'JSON 格式錯誤'], 400);
    }

    return $data;
}

function current_employee_id(): string
{
    return (string)($_SESSION['employee_id'] ?? '');
}

function current_employee_role(): string
{
    return (string)($_SESSION['employee_role'] ?? '');
}

function require_admin_role(): void
{
    if (current_employee_id() === '') {
        json_response(['ok' => false, 'error' => '尚未登入'], 401);
    }

    $role = strtolower(current_employee_role());
    if (!in_array($role, ['assetmanager', 'deptmanager'], true)) {
        json_response(['ok' => false, 'error' => '權限不足，僅資產管理員或主管可操作後台資產管理 API'], 403);
    }
}

function generate_id(string $prefix): string
{
    return $prefix . date('YmdHis') . bin2hex(random_bytes(3));
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function table_columns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '`');
    return array_column($stmt->fetchAll(), 'Field');
}

function ensure_admin_module3_schema(PDO $pdo): void
{
    $auditColumns = table_columns($pdo, 'Auditlog');
    $requiredAuditColumns = ['target_type', 'target_id', 'old_data', 'new_data', 'before_data', 'after_data', 'ip_address', 'result', 'created_at'];
    $missingAudit = array_values(array_diff($requiredAuditColumns, $auditColumns));

    $errorColumns = table_columns($pdo, 'Importerror');
    $requiredErrorColumns = ['row_no', 'field_name', 'error_message'];
    $missingError = array_values(array_diff($requiredErrorColumns, $errorColumns));

    if ($missingAudit || $missingError) {
        json_response([
            'ok' => false,
            'error' => '資料庫尚未套用管理後台模組三 migration',
            'migration' => 'migrations/20260623_admin_module3.sql',
            'missing' => [
                'Auditlog' => $missingAudit,
                'Importerror' => $missingError,
            ],
        ], 500);
    }
}

function write_audit(
    PDO $pdo,
    string $functionName,
    string $actionType,
    ?string $targetId = null,
    ?array $beforeData = null,
    ?array $afterData = null
): void {
    $beforeJson = $beforeData === null ? null : json_encode($beforeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $afterJson = $afterData === null ? null : json_encode($afterData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $pdo->prepare(
        'INSERT INTO Auditlog
        (audit_id, employee_id, function_name, action_type, target_type, target_id, old_data, new_data, before_data, after_data, ip_address, result, created_at)
        VALUES
        (:audit_id, :employee_id, :function_name, :action_type, :target_type, :target_id, :old_data, :new_data, :before_data, :after_data, :ip_address, :result, NOW())'
    );

    $stmt->execute([
        'audit_id' => generate_id('AUD'),
        'employee_id' => current_employee_id(),
        'function_name' => $functionName,
        'action_type' => $actionType,
        'target_type' => $functionName,
        'target_id' => $targetId,
        'old_data' => $beforeJson,
        'new_data' => $afterJson,
        'before_data' => $beforeJson,
        'after_data' => $afterJson,
        'ip_address' => client_ip(),
        'result' => '成功',
    ]);
}

function require_keys(array $data, array $keys): array
{
    $errors = [];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data) || trim((string)$data[$key]) === '') {
            $errors[$key][] = '必填欄位不可為空';
        }
    }
    return $errors;
}

function is_valid_date(?string $value): bool
{
    if ($value === null || $value === '') {
        return true;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $value;
}

function is_valid_datetime(?string $value): bool
{
    if ($value === null || $value === '') {
        return true;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    return $dt instanceof DateTime && $dt->format('Y-m-d H:i:s') === $value;
}

function nullable_string(array $data, string $key): ?string
{
    if (!array_key_exists($key, $data)) {
        return null;
    }
    $value = trim((string)$data[$key]);
    return $value === '' ? null : $value;
}

function first_employee_by_role(PDO $pdo, string $role): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id_num, fullname, role
         FROM Employees
         WHERE role = :role AND status = "active"
         ORDER BY id_num ASC
         LIMIT 1'
    );
    $stmt->execute(['role' => $role]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_notification(
    PDO $pdo,
    string $receiverId,
    string $receiverRole,
    string $sourceType,
    string $sourceId,
    string $title,
    string $content
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO Notification
        (notification_id, receiver_id, receiver_role, source_type, source_id, title, content, is_read, created_at, read_at)
        VALUES
        (:notification_id, :receiver_id, :receiver_role, :source_type, :source_id, :title, :content, 0, NOW(), NULL)'
    );
    $stmt->execute([
        'notification_id' => generate_id('NOT'),
        'receiver_id' => $receiverId,
        'receiver_role' => $receiverRole,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'title' => $title,
        'content' => $content,
    ]);
}