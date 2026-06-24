<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = strtolower((string)($_SESSION['employee_role'] ?? ''));
if (empty($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}
if (!in_array($role, ['assetmanager', 'deptmanager'], true)) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>資產地圖導覽 - 路邊電力資產管理系統</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <aside class="sidebar">
        <h3>管理後台</h3>
        <a href="admin_dashboard.php">後台總覽</a>
        <a href="asset_map.php">資產地圖導覽</a>
        <a href="admin_sector.php">饋線領地管理</a>
        <a href="risk_warning.php">智慧預警中心</a>
        <a href="stock_review.php">零件庫存審核</a>
        <a href="assets.php">資產總表</a>
        <a href="logout.php">登出</a>
    </aside>

    <main class="main admin-shell">
        <header class="admin-header">
            <div>
                <h1>資產地圖導覽與視覺化看板</h1>
                <p>依供電領地與健康度篩選資產 Marker。</p>
            </div>
            <a class="admin-button" href="admin_dashboard.php">回後台總覽</a>
        </header>

        <form class="admin-filter-bar" id="map-filter">
            <label>供電領地
                <select name="feeder_area" id="feeder-area">
                    <option value="">全部</option>
                </select>
            </label>
            <label>健康度
                <select name="health_level">
                    <option value="">全部</option>
                    <option value="危險">危險</option>
                    <option value="待修">待修</option>
                    <option value="良">良</option>
                    <option value="優">優</option>
                </select>
            </label>
            <label>關鍵字
                <input type="text" name="q" placeholder="資產編號、類別、型號">
            </label>
            <button class="admin-button" type="submit">套用篩選</button>
        </form>

        <section class="asset-map-layout">
            <div class="map-board" id="map-board" aria-label="資產地圖標記區"></div>
            <aside class="asset-detail-panel" id="asset-detail">
                <h2>資產快顯資訊</h2>
                <p class="empty-state">點擊左側 Marker 查看詳細規格與最新巡檢描述。</p>
            </aside>
        </section>

        <section class="admin-panel">
            <h2>地圖資產清單</h2>
            <div id="marker-table"></div>
        </section>
    </main>

    <script>
        const query = new URLSearchParams(window.location.search);

        function healthClass(level) {
            if (level === '危險') return 'danger';
            if (level === '待修') return 'warning';
            return 'good';
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            }[char]));
        }

        async function loadMap() {
            const form = document.getElementById('map-filter');
            const params = new URLSearchParams(new FormData(form));
            if (query.get('asset_id')) {
                params.set('q', query.get('asset_id'));
            }

            const response = await fetch(`api/asset_map.php?${params.toString()}`, { credentials: 'same-origin' });
            const payload = await response.json();
            if (!payload.ok) throw new Error(payload.error || '讀取資產地圖失敗');

            const filters = payload.data.filters;
            const feederSelect = document.getElementById('feeder-area');
            if (feederSelect.options.length === 1) {
                filters.feeder_areas.forEach(area => {
                    const option = document.createElement('option');
                    option.value = area;
                    option.textContent = area;
                    feederSelect.appendChild(option);
                });
            }

            renderMarkers(payload.data.markers || []);
            renderTable(payload.data.markers || []);

            const focusAsset = query.get('asset_id');
            if (focusAsset) {
                showDetail(focusAsset);
            }
        }

        function renderMarkers(markers) {
            const board = document.getElementById('map-board');
            if (!markers.length) {
                board.innerHTML = '<div class="empty-state">目前沒有符合篩選條件且具 GPS 的資產。</div>';
                return;
            }

            const lats = markers.map(m => Number(m.gps_lat)).filter(Number.isFinite);
            const lngs = markers.map(m => Number(m.gps_lng)).filter(Number.isFinite);
            const minLat = Math.min(...lats);
            const maxLat = Math.max(...lats);
            const minLng = Math.min(...lngs);
            const maxLng = Math.max(...lngs);
            const latSpan = Math.max(maxLat - minLat, 0.0001);
            const lngSpan = Math.max(maxLng - minLng, 0.0001);

            board.innerHTML = markers.map(marker => {
                const lat = Number(marker.gps_lat);
                const lng = Number(marker.gps_lng);
                const left = 6 + ((lng - minLng) / lngSpan) * 88;
                const top = 6 + (1 - ((lat - minLat) / latSpan)) * 88;
                const cls = healthClass(marker.health_level);
                return `
                    <button class="map-marker marker-${cls}" style="left:${left}%; top:${top}%;" data-asset-id="${escapeHtml(marker.asset_id)}" title="${escapeHtml(marker.asset_id)}">
                        <span>${escapeHtml(marker.asset_id)}</span>
                    </button>
                `;
            }).join('');

            board.querySelectorAll('.map-marker').forEach(marker => {
                marker.addEventListener('click', () => showDetail(marker.dataset.assetId));
            });
        }

        function renderTable(markers) {
            const target = document.getElementById('marker-table');
            if (!markers.length) {
                target.innerHTML = '<div class="empty-state">無資料。</div>';
                return;
            }

            target.innerHTML = `
                <table>
                    <thead>
                        <tr>
                            <th>資產</th>
                            <th>饋線</th>
                            <th>類別</th>
                            <th>健康度</th>
                            <th>最新風險</th>
                            <th>最新巡檢描述</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${markers.map(marker => `
                            <tr>
                                <td><button class="link-button" data-asset-id="${escapeHtml(marker.asset_id)}">${escapeHtml(marker.asset_id)}</button></td>
                                <td>${escapeHtml(marker.feeder_area || marker.sector_id)}</td>
                                <td>${escapeHtml(marker.type)}</td>
                                <td><span class="status-pill ${healthClass(marker.health_level)}">${escapeHtml(marker.health_level || '未設定')}</span></td>
                                <td>${escapeHtml(marker.risk_score ?? '-')}</td>
                                <td>${escapeHtml(marker.observation || '-')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;

            target.querySelectorAll('.link-button').forEach(button => {
                button.addEventListener('click', () => showDetail(button.dataset.assetId));
            });
        }

        async function showDetail(assetId) {
            const response = await fetch(`api/asset_map.php?asset_id=${encodeURIComponent(assetId)}`, { credentials: 'same-origin' });
            const payload = await response.json();
            if (!payload.ok) throw new Error(payload.error || '讀取資產明細失敗');
            const item = payload.data;
            document.getElementById('asset-detail').innerHTML = `
                <h2>${escapeHtml(item.asset_id)}</h2>
                <dl class="detail-list">
                    <dt>類別</dt><dd>${escapeHtml(item.type)}</dd>
                    <dt>饋線/位置</dt><dd>${escapeHtml(item.feeder_area || item.sector_id)} ${escapeHtml(item.city || '')}${escapeHtml(item.street || '')}</dd>
                    <dt>規格</dt><dd>${escapeHtml(item.model || item.spec_id)} / ${escapeHtml(item.voltage ?? '-')}V / ${escapeHtml(item.amperage ?? '-')}A</dd>
                    <dt>製造商</dt><dd>${escapeHtml(item.manufacturer_name || '-')}</dd>
                    <dt>健康度</dt><dd><span class="status-pill ${healthClass(item.health_level)}">${escapeHtml(item.health_level || '未設定')}</span></dd>
                    <dt>目前狀態</dt><dd>${escapeHtml(item.current_status || '-')}</dd>
                    <dt>最新巡檢</dt><dd>${escapeHtml(item.inspec_time || '-')}</dd>
                    <dt>外觀描述</dt><dd>${escapeHtml(item.observation || '-')}</dd>
                    <dt>風險分數</dt><dd>${escapeHtml(item.risk_score ?? '-')}</dd>
                </dl>
            `;
        }

        document.getElementById('map-filter').addEventListener('submit', event => {
            event.preventDefault();
            loadMap().catch(error => {
                document.getElementById('map-board').innerHTML = `<div class="error-box">${escapeHtml(error.message)}</div>`;
            });
        });

        loadMap().catch(error => {
            document.getElementById('map-board').innerHTML = `<div class="error-box">${escapeHtml(error.message)}</div>`;
        });
    </script>
</body>
</html>