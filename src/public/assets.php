<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use Dotenv\Dotenv;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<!--41243214-->

<head>
    <meta charset="UTF-8">
    <title>資產總表 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div id="tooltip"></div>

    <div class="sidebar">
        <h3>清單</h3>
        <a href="index.php">首頁</a>
        <a href="assets.php">資產總表</a>
        <!-- USER_BACKEND_ROLE_MENU_START -->
        <?php require_once __DIR__ . '/backend-menu.php'; backend_render_menu(); ?>
        <!-- USER_BACKEND_ROLE_MENU_END -->
    </div>

    <div class="main">
        <?php
        $search = $_GET['q'] ?? '';
        try { //connect to db
            $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";port=" . $_ENV['DB_PORT'];
            $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Base SQL,ST_AsText is a spatial function to convert the GPS coordinates to text format
            $sql = "SELECT pa.*,
                        ST_AsText(pa.gps) as gps_coord,
                        CONCAT(s.city, ' ', s.street, ' (', s.feeder_area, ')') as full_address,
                        CONCAT(
                            m.name, ' - ', asp.model,
                            '\n電壓: ', asp.voltage, 'V / 電流: ', asp.amperage, 'A'
                        ) as spec_detail
                    FROM PowerAsset pa
                    LEFT JOIN Sector s ON pa.sector_id = s.sector_id
                    LEFT JOIN AssetSpec asp ON pa.spec_id = asp.spec_id
                    LEFT JOIN Manufacturer m ON asp.manufacturer_id = m.manufacturer_id";

            // Add search condition if query is present
            if ($search !== '') {
                $sql .= " WHERE pa.asset_id LIKE :q OR pa.type LIKE :q OR pa.sector_id LIKE :q";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['q' => "%$search%"]);
            } else {
                $stmt = $pdo->query($sql); //select all
            }

            $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Database error: " . $e->getMessage());
        }
        ?>

        <h1>資產總表</h1>

        <form action="assets.php" method="GET" class="search-container">
            <input type="text" name="q" placeholder="搜尋編號、類別或區域..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">搜尋</button>
            <?php if ($search !== ''): ?>
                <a href="assets.php" style="align-self: center; font-size: 14px; color: #666;">清除搜尋</a>
            <?php endif; ?>
        </form>

        <?php if (count($assets) > 0): ?>
            <table border="1">
                <thead>
                    <tr>
                        <th>資產編號</th>
                        <th>區域代碼</th>
                        <th>資產類別</th>
                        <th>規格編號</th>
                        <th>GPS(座標)</th>
                        <th>健康狀況</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $asset): // loop through filtered assets and render rows ?>
                        <tr>
                            <td><?= htmlspecialchars($asset['asset_id']) ?></td>
                            <td class="hover-info" data-tip="<?= htmlspecialchars($asset['full_address'] ?? '地址資訊暫缺') ?>">
                                <?= htmlspecialchars($asset['sector_id']) ?>
                            </td>
                            <td><?= htmlspecialchars($asset['type']) ?></td>
                            <td class="hover-info" data-tip="<?= htmlspecialchars($asset['spec_detail'] ?? '規格資訊暫缺') ?>">
                                <?= htmlspecialchars($asset['spec_id']) ?>
                            </td>
                            <td><?= htmlspecialchars($asset['gps_coord']) ?></td>
                            <td>
                                <a href="health-details.php?id=<?= urlencode($asset['asset_id']) ?>">查看</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No assets found.</p>
        <?php endif; ?>
    </div>

    <script>
        // JS hover event for tooltips using data-tip attribute
        const tooltip = document.getElementById('tooltip');
        document.querySelectorAll('.hover-info').forEach(el => {
            el.addEventListener('mouseover', (e) => {
                tooltip.innerText = e.target.getAttribute('data-tip');
                tooltip.style.display = 'block';
            });
            el.addEventListener('mousemove', (e) => {
                tooltip.style.left = (e.pageX + 10) + 'px';
                tooltip.style.top = (e.pageY + 10) + 'px';
            });
            el.addEventListener('mouseout', () => {
                tooltip.style.display = 'none';
            });
        });
    </script>
</body>

</html>