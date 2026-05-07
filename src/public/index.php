<?php

// 載入 Composer 自動載入器
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// 載入環境變數設定
// create instance, doesn't overwrite
// looks for .env in the current directory
// loads .env file and inject contents into $_ENV superglobal
// use $_ENV['VAR_NAME'] to access the environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

echo "<h1>Welcome to Team 09 Project</h1>";
echo "<p>Database Host: " . $_ENV['DB_HOST'] . "</p>";

// 嘗試測試資料庫連線
// datasource name (driver:host=HOST_ADDRESS;dbname=DATABASE_NAME;port=PORT_NUMBER) -->what to connect to
// php data object (dsn;username;password) -->actual connection
try {
    $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";port=" . $_ENV['DB_PORT'];
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>Successfully connected to the database!</p>";
} catch (PDOException $e) {
    // 若連線失敗則顯示錯誤訊息
    echo "<p style='color: red;'>Database connection failed: " . $e->getMessage() . "</p>";
}

// 顯示 PHP 系統資訊 (開發階段測試用)
phpinfo();
