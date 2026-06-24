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
    <title>管理後台總覽 - 路邊電力資產管理系統</title>
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
                <h1>管理後台總覽</h1>
                <p>目前身分：<?= htmlspecialchars((string)($_SESSION['employee_role'] ?? '')) ?> / <?= htmlspecialchars((string)($_SESSION['employee_name'] ?? '')) ?></p>
            </div>
            <a class="admin-button" href="asset_map.php">查看資產地圖</a>
        </header>

        <section class="kpi-grid" id="kpi-grid" aria-live="polite"></section>

        <section class="admin-two-column">
            <div class="admin-panel">
                <h2>近期事件動態日誌</h2>
                <div id="notification-list" class="stack-list"></div>
            </div>
            <div class="admin-panel">
                <h2>決策者快捷待辦清單</h2>
                <div id="todo-list" class="stack-list"></div>
            </div>
        </section>
    </main>

    <script>
        const kpiLabels = {
            active_assets: '全區資產',
            abnormal_todos: '今日異常待辦',
            high_risk_assets: 'AI預警高風險',
            pending_approvals: '待簽核單據'
        };

        function renderEmpty(target, text) {
            target.innerHTML = `<div class="empty-state">${text}</div>`;
        }

        async function loadDashboard() {
            const response = await fetch('api/admin_dashboard.php', { credentials: 'same-origin' });
            const payload = await response.json();
            if (!payload.ok) {
                throw new Error(payload.error || '讀取後台總覽失敗');
            }

            const data = payload.data;
            const kpiGrid = document.getElementById('kpi-grid');
            kpiGrid.innerHTML = Object.entries(kpiLabels).map(([key, label]) => `
                <article class="kpi-card">
                    <span>${label}</span>
                    <strong>${Number(data.kpis[key] ?? 0).toLocaleString('zh-TW')}</strong>
                </article>
            `).join('');

            const notificationList = document.getElementById('notification-list');
            if (!data.notifications.length) {
                renderEmpty(notificationList, '目前沒有近期事件。');
            } else {
                notificationList.innerHTML = data.notifications.map(item => `
                    <article class="admin-list-item">
                        <strong>${escapeHtml(item.title || '未命名通知')}</strong>
                        <p>${escapeHtml(item.content || '')}</p>
                        <small>${escapeHtml(item.receiver_name || item.receiver_role || item.receiver_id || '-')} · ${escapeHtml(item.created_at || '')}</small>
                    </article>
                `).join('');
            }

            const todoList = document.getElementById('todo-list');
            if (!data.todos.length) {
                renderEmpty(todoList, '目前沒有需要立即處理的待辦。');
            } else {
                todoList.innerHTML = data.todos.map(item => `
                    <article class="admin-list-item priority-${escapeHtml(item.priority)}">
                        <strong>${escapeHtml(item.title)}</strong>
                        <p>${escapeHtml(item.description)}</p>
                        <a href="${escapeAttr(item.link)}">前往處理</a>
                    </article>
                `).join('');
            }
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            }[char]));
        }

        function escapeAttr(value) {
            return escapeHtml(value).replace(/`/g, '&#096;');
        }

        loadDashboard().catch(error => {
            document.getElementById('kpi-grid').innerHTML = `<div class="error-box">${escapeHtml(error.message)}</div>`;
        });
    </script>
</body>
</html>