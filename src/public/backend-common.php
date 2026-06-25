<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$projectRoot = dirname(__DIR__, 2);
$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('缺少 vendor/autoload.php，請先執行 composer install。');
}

require_once $autoload;

use Dotenv\Dotenv;

Dotenv::createImmutable($projectRoot)->safeLoad();

// rollback 的 PHP image 未必包含 mbstring；提供只在缺少擴充時啟用的 UTF-8 截字相容函式。
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth(
        string $string,
        int $start,
        int $width,
        string $trimMarker = '',
        ?string $encoding = null
    ): string {
        $characters = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            $characters = str_split($string);
        }
        $slice = array_slice($characters, max(0, $start), max(0, $width));
        $result = implode('', $slice);
        if (count($characters) > max(0, $start) + max(0, $width)) {
            $result .= $trimMarker;
        }
        return $result;
    }
}

function backend_normalize_role(string $role): string
{
    return strtolower(trim($role));
}

function backend_user(): array
{
    return [
        'id' => (string) ($_SESSION['employee_id'] ?? ''),
        'name' => (string) ($_SESSION['employee_name'] ?? $_SESSION['employee_account'] ?? ''),
        'account' => (string) ($_SESSION['employee_account'] ?? ''),
        'role' => backend_normalize_role((string) ($_SESSION['employee_role'] ?? '')),
    ];
}

function backend_role_label(string $role): string
{
    return match (backend_normalize_role($role)) {
        'deptmanager' => '部門主管',
        'assetmanager' => '資產管理員',
        'inspector' => '巡檢人員',
        'technician' => '維修人員',
        default => $role !== '' ? $role : '未登入',
    };
}

function backend_role_home(string $role): string
{
    return match (backend_normalize_role($role)) {
        'inspector' => 'inspections.php',
        'technician' => 'maintenances.php',
        'assetmanager', 'deptmanager' => 'backend-management.php',
        default => 'index.php',
    };
}

function backend_valid_roles(): array
{
    return ['deptmanager', 'assetmanager', 'inspector', 'technician'];
}

function backend_role_allowed(array $roles): bool
{
    $current = backend_user()['role'];
    $normalized = array_map('backend_normalize_role', $roles);
    return in_array($current, $normalized, true);
}

function backend_require_login(): void
{
    if (backend_user()['id'] === '') {
        $returnTo = basename((string) ($_SERVER['REQUEST_URI'] ?? 'backend-management.php'));
        header('Location: login.php?return=' . rawurlencode($returnTo));
        exit;
    }
}

function backend_require_roles(array $roles): void
{
    backend_require_login();

    if (backend_role_allowed($roles)) {
        return;
    }

    http_response_code(403);
    $home = backend_role_home(backend_user()['role']);
    echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>403 權限不足</title></head>';
    echo '<body style="font-family:Microsoft JhengHei,Arial,sans-serif;padding:40px">';
    echo '<h1>403 權限不足</h1><p>你的登入身分無法使用此功能。</p>';
    echo '<p><a href="' . backend_e($home) . '">返回可使用的首頁</a></p></body></html>';
    exit;
}

function backend_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = $_ENV['DB_HOST'] ?? 'csieDBTeam09';
    $name = $_ENV['DB_NAME'] ?? 'csieDBTeam09';
    $port = $_ENV['DB_PORT'] ?? '3306';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';

    $dsn = sprintf('mysql:host=%s;dbname=%s;port=%s;charset=utf8mb4', $host, $name, $port);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function backend_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function backend_csrf_token(): string
{
    if (empty($_SESSION['backend_csrf_token'])) {
        $_SESSION['backend_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['backend_csrf_token'];
}

function backend_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . backend_e(backend_csrf_token()) . '">';
}

function backend_verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(backend_csrf_token(), $token)) {
        http_response_code(419);
        exit('表單驗證失敗，請返回上一頁重新送出。');
    }
}

function backend_flash(string $type, string $message): void
{
    $_SESSION['backend_flash'][] = ['type' => $type, 'message' => $message];
}

function backend_consume_flashes(): array
{
    $items = $_SESSION['backend_flash'] ?? [];
    unset($_SESSION['backend_flash']);
    return is_array($items) ? $items : [];
}

function backend_redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function backend_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function backend_audit(
    string $functionName,
    string $actionType,
    string $targetType = '',
    string $targetId = '',
    mixed $oldData = null,
    mixed $newData = null,
    string $result = '成功'
): void {
    try {
        $stmt = backend_db()->prepare(
            'INSERT INTO Auditlog
             (audit_id, employee_id, function_name, action_type, target_type, target_id,
              old_data, new_data, ip_address, result, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            'AUD' . date('YmdHis') . random_int(1000, 9999),
            backend_user()['id'],
            $functionName,
            $actionType,
            $targetType !== '' ? $targetType : null,
            $targetId !== '' ? $targetId : null,
            $oldData === null ? null : json_encode($oldData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $newData === null ? null : json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            backend_client_ip(),
            $result,
        ]);
    } catch (Throwable) {
        // 稽核功能不可阻斷主要操作。
    }
}

function backend_migration_ready(): bool
{
    try {
        $stmt = backend_db()->query("SHOW COLUMNS FROM Auditlog LIKE 'created_at'");
        return (bool) $stmt->fetch();
    } catch (Throwable) {
        return false;
    }
}
