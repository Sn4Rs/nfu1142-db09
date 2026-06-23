<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$loginError = '';
function login_home_for_role(string $role): string
{
    $role = strtolower(trim($role));

    if (str_contains($role, 'inspector')) {
        return 'inspections.php';
    }
    if (str_contains($role, 'technician')) {
        return 'maintenances.php';
    }
    if (str_contains($role, 'assetmanager') || str_contains($role, 'deptmanager')) {
        return 'backend-management.php';
    }

    return 'index.php';
}


if (!empty($_SESSION['employee_id'])) {
    header('Location: ' . login_home_for_role((string) ($_SESSION['employee_role'] ?? '')));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$userhandle = trim($_POST['userhandle'] ?? '');
	$password = $_POST['password'] ?? '';

	if ($userhandle === '' || $password === '') {
		$loginError = '請輸入帳號與密碼。';
	} else {
		try {
			$dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';port=' . $_ENV['DB_PORT'];
			$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

			// Query by `account` per schema, prefer `pwdhash` for verification
			$stmt = $pdo->prepare('SELECT id_num, fullname, role, account, userpwd, pwdhash FROM Employees WHERE account = ? LIMIT 1');
			$stmt->execute([$userhandle]);
			$employee = $stmt->fetch(PDO::FETCH_ASSOC);

			$isPasswordValid = false;
			if ($employee) {
				$storedHash = trim((string) ($employee['pwdhash'] ?? ''));
				if ($storedHash !== '') {
					$isPasswordValid = password_verify($password, $storedHash);
				} else {
					// fallback to plain userpwd compare (not recommended)
					$storedPassword = (string) ($employee['userpwd'] ?? '');
					if ($storedPassword !== '') {
						$isPasswordValid = hash_equals($storedPassword, $password);
					}
				}
			}

			if ($employee && $isPasswordValid) {
				$_SESSION['employee_id'] = $employee['id_num'];
				$_SESSION['employee_name'] = $employee['fullname'];
				$_SESSION['employee_role'] = $employee['role'];
				$_SESSION['employee_account'] = $employee['account'];
                header('Location: ' . login_home_for_role((string) $employee['role']));
                exit;
			}

			$loginError = '帳號或密碼錯誤。';
		} catch (PDOException $e) {
			$loginError = '無法連接資料庫，請稍後再試。';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="description" content="路邊電力資產管理系統登入頁面">
	<title>登入 - 路邊電力資產管理系統</title>
	<link rel="stylesheet" href="css/style.css">
	<style>
		body.login-page {
			min-height: 100vh;
			display: grid;
			place-items: center;
			background: linear-gradient(135deg, #1f2937 0%, #111827 45%, #374151 100%);
			color: #111827;
		}

		.login-card {
			width: min(420px, calc(100vw - 32px));
			background: rgba(255, 255, 255, 0.96);
			border-radius: 18px;
			padding: 32px;
			box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
		}

		.login-card h1 {
			margin: 0 0 8px;
			font-size: 28px;
		}

		.login-card p {
			margin: 0 0 20px;
			color: #4b5563;
		}

		.login-form {
			display: grid;
			gap: 14px;
		}

		.login-form label {
			display: grid;
			gap: 6px;
			font-weight: 600;
			color: #1f2937;
		}

		.login-form input {
			width: 100%;
			padding: 12px 14px;
			border: 1px solid #d1d5db;
			border-radius: 10px;
			font-size: 16px;
			box-sizing: border-box;
		}

		.login-form input:focus {
			outline: 2px solid #111827;
			outline-offset: 1px;
		}

		.login-actions {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 12px;
			margin-top: 6px;
		}

		.login-actions a {
			color: #374151;
			text-decoration: none;
		}

		.login-actions a:hover {
			text-decoration: underline;
		}

		.login-button {
			border: 0;
			border-radius: 10px;
			background: #111827;
			color: white;
			padding: 12px 18px;
			font-size: 16px;
			cursor: pointer;
		}

		.login-button:hover {
			background: #1f2937;
		}

		.error-box {
			margin-bottom: 16px;
			padding: 12px 14px;
			border-radius: 10px;
			background: #fef2f2;
			color: #b91c1c;
			border: 1px solid #fecaca;
		}

		.role-hint {
			margin-top: 16px;
			font-size: 14px;
			color: #6b7280;
		}
	</style>
</head>

<body class="login-page">
	<main class="login-card">
		<h1>系統登入</h1>
		<p>使用 Employees 資料表的帳號與密碼登入。</p>

		<?php if ($loginError !== ''): ?>
			<div class="error-box"><?= htmlspecialchars($loginError) ?></div>
		<?php endif; ?>

		<form class="login-form" method="post" action="login.php" autocomplete="on">
			<label>
				帳號
				<input type="text" name="userhandle" autocomplete="username" required value="<?= htmlspecialchars($_POST['userhandle'] ?? '') ?>">
			</label>

			<label>
				密碼
				<input type="password" name="password" autocomplete="current-password" required>
			</label>

			<div class="login-actions">
				<a href="index.php">返回首頁</a>
				<button class="login-button" type="submit">登入</button>
			</div>
		</form>

	</main>
</body>

</html>
