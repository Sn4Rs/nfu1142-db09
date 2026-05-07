<?php

// 載入 Composer 的 Autoload (處理套件與命名空間自動載入)
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// 初始化並載入 .env 檔案中的環境變數
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// 從環境變數中定義資料庫連線相關常數
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);
define('DB_PORT', $_ENV['DB_PORT']);
