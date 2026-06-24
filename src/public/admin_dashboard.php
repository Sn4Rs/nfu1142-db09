<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager']);

backend_render_header('管理後台總覽', '核心 KPI、近期通知與後台待辦。', true);
?>
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
<?php backend_render_footer(); ?>