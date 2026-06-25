<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager']);

backend_render_header('資產全面盤點', '查詢、篩選、新增與修改資產建檔資料。');
?>
<section class="card">
    <form class="toolbar" id="asset-filter">
        <label>
            關鍵字
            <input type="search" name="q" placeholder="資產編號、區域、類別、規格、型號">
        </label>
        <label>
            類別
            <select name="type">
                <option value="">全部類別</option>
                <option value="電塔">電塔</option>
                <option value="電線桿">電線桿</option>
                <option value="電箱">電箱</option>
                <option value="變壓器">變壓器</option>
                <option value="開關箱">開關箱</option>
            </select>
        </label>
        <label>
            每頁筆數
            <select name="per_page">
                <option value="10">10</option>
                <option value="20" selected>20</option>
                <option value="50">50</option>
            </select>
        </label>
        <button type="submit">套用</button>
        <button class="secondary" type="button" id="reset-filter">清除</button>
        <button type="button" id="new-asset">新增資產</button>
        <a class="button secondary" href="backend-asset-import.php">批次匯入</a>
    </form>
    <div id="asset-feedback"></div>
    <div class="table-wrap" id="asset-table"></div>
    <div class="pagination-bar" id="asset-pagination"></div>
</section>

<aside class="drawer" id="asset-drawer" aria-hidden="true">
    <div class="drawer-panel">
        <div class="drawer-header">
            <div>
                <h2 id="drawer-title">新增資產</h2>
                <p>欄位會依 schema 寫入 Powerasset 與最新 Health 紀錄。</p>
            </div>
            <button class="secondary" type="button" id="close-drawer">關閉</button>
        </div>
        <form id="asset-form" class="form-grid">
            <input type="hidden" name="action" value="create">
            <label>資產編號<input name="asset_id" maxlength="10" required placeholder="ASSET001"></label>
            <label>分區代碼<input name="sector_id" required placeholder="S001"></label>
            <label>資產類別
                <select name="type" required>
                    <option value="電塔">電塔</option>
                    <option value="電線桿">電線桿</option>
                    <option value="電箱">電箱</option>
                    <option value="變壓器">變壓器</option>
                    <option value="開關箱">開關箱</option>
                </select>
            </label>
            <label>規格編號<input name="spec_id" required placeholder="SPEC001"></label>
            <label>安裝日期<input name="install_date" type="date" required></label>
            <label>預期壽命<input name="expected_lifespan" type="number" min="1" max="200" required></label>
            <label>健康度
                <select name="health_level" required>
                    <option value="優">優</option>
                    <option value="良">良</option>
                    <option value="待修">待修</option>
                    <option value="危險">危險</option>
                </select>
            </label>
            <label>目前狀態
                <select name="current_status" required>
                    <option value="運作中">運作中</option>
                    <option value="維修中">維修中</option>
                    <option value="報廢">報廢</option>
                </select>
            </label>
            <label>最後巡檢日<input name="last_inspection_date" type="date"></label>
            <label>緯度<input name="gps_lat" type="number" step="0.000001" placeholder="23.707000"></label>
            <label>經度<input name="gps_lng" type="number" step="0.000001" placeholder="120.542000"></label>
            <label>有效起始時間<input name="valid_from" type="datetime-local"></label>
            <label>有效結束時間<input name="valid_to" type="datetime-local"></label>
            <div class="drawer-actions">
                <button type="submit">儲存</button>
                <button class="secondary" type="button" id="cancel-form">取消</button>
            </div>
        </form>
        <div id="form-errors"></div>
    </div>
</aside>

<script>
const filterForm = document.getElementById('asset-filter');
const tableTarget = document.getElementById('asset-table');
const paginationTarget = document.getElementById('asset-pagination');
const feedback = document.getElementById('asset-feedback');
const drawer = document.getElementById('asset-drawer');
const form = document.getElementById('asset-form');
const formErrors = document.getElementById('form-errors');
let currentPage = 1;
let currentRows = [];

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
}

function toDatetimeLocal(value) {
    if (!value) return '';
    return String(value).replace(' ', 'T').slice(0, 16);
}

function fromDatetimeLocal(value) {
    return value ? value.replace('T', ' ') + ':00' : '';
}

function statusClass(level) {
    if (level === '危險' || level === '報廢') return 'badge-danger';
    if (level === '待修' || level === '維修中') return 'badge-warning';
    if (level === '優' || level === '良' || level === '運作中') return 'badge-success';
    return 'badge-muted';
}

function showFeedback(message, type = 'info') {
    feedback.innerHTML = message ? `<div class="alert alert-${type}">${esc(message)}</div>` : '';
}

function renderErrors(payload) {
    if (!payload.errors) {
        formErrors.innerHTML = `<div class="alert alert-error">${esc(payload.error || '儲存失敗')}</div>`;
        return;
    }
    const items = Object.entries(payload.errors).flatMap(([field, messages]) => messages.map(message => `<li>${esc(field)}：${esc(message)}</li>`));
    formErrors.innerHTML = `<div class="alert alert-error"><ul>${items.join('')}</ul></div>`;
}

async function loadAssets(page = 1) {
    currentPage = page;
    const params = new URLSearchParams(new FormData(filterForm));
    params.set('page', String(page));
    const response = await fetch(`api/admin_assets.php?${params.toString()}`, { credentials: 'same-origin' });
    const payload = await response.json();
    if (!payload.ok) throw new Error(payload.error || '讀取資產失敗');
    currentRows = payload.data || [];
    renderTable(currentRows);
    renderPagination(payload.pagination || {page: 1, total_pages: 1, total: currentRows.length});
}

function renderTable(rows) {
    if (!rows.length) {
        tableTarget.innerHTML = '<div class="empty">沒有符合條件的資產。</div>';
        return;
    }
    tableTarget.innerHTML = `
        <table>
            <thead>
                <tr>
                    <th>資產編號</th><th>饋線/分區</th><th>類別</th><th>規格</th><th>健康度</th><th>狀態</th><th>安裝日</th><th>GPS</th><th>操作</th>
                </tr>
            </thead>
            <tbody>
                ${rows.map(row => `
                    <tr>
                        <td><strong>${esc(row.asset_id)}</strong></td>
                        <td>${esc(row.feeder_area || row.sector_id)}</td>
                        <td>${esc(row.type)}</td>
                        <td>${esc(row.model || row.spec_id)}<div class="text-muted">${esc(row.voltage ?? '-')}V / ${esc(row.amperage ?? '-')}A</div></td>
                        <td><span class="badge ${statusClass(row.health_level)}">${esc(row.health_level || '未設定')}</span></td>
                        <td><span class="badge ${statusClass(row.current_status)}">${esc(row.current_status || '未設定')}</span></td>
                        <td>${esc(row.install_date || '-')}</td>
                        <td>${row.gps_lat && row.gps_lng ? `${esc(row.gps_lat)}, ${esc(row.gps_lng)}` : '-'}</td>
                        <td><button class="button small secondary" type="button" data-edit="${esc(row.asset_id)}">修改</button></td>
                    </tr>
                `).join('')}
            </tbody>
        </table>`;
    tableTarget.querySelectorAll('[data-edit]').forEach(button => {
        button.addEventListener('click', () => openEdit(button.dataset.edit));
    });
}

function renderPagination(pageInfo) {
    const totalPages = Math.max(1, Number(pageInfo.total_pages || 1));
    const page = Math.max(1, Number(pageInfo.page || 1));
    paginationTarget.innerHTML = `
        <span>共 ${esc(pageInfo.total || 0)} 筆，第 ${page} / ${totalPages} 頁</span>
        <div>
            <button class="secondary" type="button" id="prev-page" ${page <= 1 ? 'disabled' : ''}>上一頁</button>
            <button class="secondary" type="button" id="next-page" ${page >= totalPages ? 'disabled' : ''}>下一頁</button>
        </div>`;
    document.getElementById('prev-page').addEventListener('click', () => loadAssets(page - 1).catch(handleLoadError));
    document.getElementById('next-page').addEventListener('click', () => loadAssets(page + 1).catch(handleLoadError));
}

function resetForm() {
    form.reset();
    form.action.value = 'create';
    form.asset_id.readOnly = false;
    form.valid_from.value = '';
    form.valid_to.value = '';
    formErrors.innerHTML = '';
}

function openDrawer(title) {
    document.getElementById('drawer-title').textContent = title;
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
}

function closeDrawer() {
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
}

function openCreate() {
    resetForm();
    form.install_date.value = new Date().toISOString().slice(0, 10);
    form.expected_lifespan.value = '30';
    openDrawer('新增資產');
}

function openEdit(assetId) {
    const row = currentRows.find(item => item.asset_id === assetId);
    if (!row) return;
    resetForm();
    form.action.value = 'update';
    form.asset_id.value = row.asset_id || '';
    form.asset_id.readOnly = true;
    form.sector_id.value = row.sector_id || '';
    form.type.value = row.type || '電線桿';
    form.spec_id.value = row.spec_id || '';
    form.install_date.value = row.install_date || '';
    form.expected_lifespan.value = row.expected_lifespan || '30';
    form.health_level.value = row.health_level || '良';
    form.current_status.value = row.current_status || '運作中';
    form.last_inspection_date.value = row.last_inspection_date || '';
    form.gps_lat.value = row.gps_lat || '';
    form.gps_lng.value = row.gps_lng || '';
    form.valid_from.value = toDatetimeLocal(row.valid_from);
    form.valid_to.value = toDatetimeLocal(row.valid_to);
    openDrawer(`修改資產 ${row.asset_id}`);
}

function collectFormData() {
    const data = Object.fromEntries(new FormData(form));
    data.valid_from = fromDatetimeLocal(data.valid_from);
    data.valid_to = fromDatetimeLocal(data.valid_to);
    Object.keys(data).forEach(key => {
        if (data[key] === '') data[key] = '';
    });
    return data;
}

async function saveAsset(event) {
    event.preventDefault();
    formErrors.innerHTML = '';
    const response = await fetch('api/admin_assets.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify(collectFormData())
    });
    const payload = await response.json();
    if (!payload.ok) {
        renderErrors(payload);
        return;
    }
    closeDrawer();
    showFeedback(form.action.value === 'create' ? '資產已新增。' : '資產已更新。', 'success');
    await loadAssets(currentPage);
}

function handleLoadError(error) {
    tableTarget.innerHTML = `<div class="alert alert-error">${esc(error.message)}</div>`;
}

filterForm.addEventListener('submit', event => {
    event.preventDefault();
    loadAssets(1).catch(handleLoadError);
});
document.getElementById('reset-filter').addEventListener('click', () => {
    filterForm.reset();
    loadAssets(1).catch(handleLoadError);
});
document.getElementById('new-asset').addEventListener('click', openCreate);
document.getElementById('close-drawer').addEventListener('click', closeDrawer);
document.getElementById('cancel-form').addEventListener('click', closeDrawer);
drawer.addEventListener('click', event => {
    if (event.target === drawer) closeDrawer();
});
form.addEventListener('submit', saveAsset);
loadAssets().catch(handleLoadError);
</script>
<?php backend_render_footer(); ?>