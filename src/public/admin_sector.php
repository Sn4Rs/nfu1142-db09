<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$role = strtolower((string)($_SESSION['employee_role'] ?? ''));
if (empty($_SESSION['employee_id'])) { header('Location: login.php'); exit; }
if (!in_array($role, ['assetmanager', 'deptmanager'], true)) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>饋線領地與設備異動管理</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <aside class="sidebar admin-nav">
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
        <header class="admin-header"><div><h1>饋線領地與設備異動管理</h1><p>管理分區資產，並對報廢或遷移分區寫入稽核紀錄。</p></div></header>
        <section class="asset-map-layout">
            <div class="admin-panel"><h2>供電領地列表</h2><div id="sector-list" class="stack-list"></div></div>
            <div class="admin-panel"><h2>分區資產明細</h2><div id="sector-assets" class="stack-list"><div class="empty-state">請先選擇左側分區。</div></div></div>
        </section>
        <section class="admin-panel">
            <h2>異動與除役稽核</h2>
            <form class="admin-form-grid" id="change-form">
                <label>資產編號<input name="asset_id" required placeholder="ASSET001"></label>
                <label>新分區<select name="new_sector_id" id="change-sector"><option value="">不變更</option></select></label>
                <label>新狀態<select name="current_status"><option value="">不變更</option><option value="運作中">運作中</option><option value="維修中">維修中</option><option value="報廢">報廢</option></select></label>
                <label>異動原因<select name="reason" required><option value="">請選擇</option><option>雷擊老化</option><option>車禍外力</option><option>道路拓寬</option><option>計畫性汰換</option><option>其他</option></select></label>
                <label class="span-2">備註<textarea name="note" rows="3" placeholder="報廢或遷移分區時必填"></textarea></label>
                <button class="admin-button" type="submit">寫入異動稽核</button>
            </form>
            <div id="change-result"></div>
        </section>
    </main>
<script>
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
let sectors=[];
async function loadSectors(sectorId=''){
 const r=await fetch('api/admin_sectors.php'+(sectorId?'?sector_id='+encodeURIComponent(sectorId):''),{credentials:'same-origin'}); const p=await r.json(); if(!p.ok) throw new Error(p.error||'讀取失敗');
 sectors=p.data.sectors; renderSectors(p.data.sectors); renderSectorOptions(p.data.sectors); if(sectorId) renderAssets(p.data.assets);
}
function renderSectorOptions(rows){const s=document.getElementById('change-sector'); if(s.options.length>1)return; rows.forEach(x=>{const o=document.createElement('option'); o.value=x.sector_id; o.textContent=`${x.sector_id} / ${x.feeder_area||''}`; s.appendChild(o);});}
function renderSectors(rows){document.getElementById('sector-list').innerHTML=rows.map(x=>`<article class="admin-list-item"><strong>${esc(x.feeder_area||x.sector_id)}</strong><p>${esc(x.city||'')}${esc(x.street||'')} / 資產 ${esc(x.asset_count)} / 危險 ${esc(x.danger_count||0)} / 待修 ${esc(x.repair_count||0)}</p><button class="admin-button" data-sector="${esc(x.sector_id)}">查看資產</button></article>`).join(''); document.querySelectorAll('[data-sector]').forEach(b=>b.onclick=()=>loadSectors(b.dataset.sector));}
function renderAssets(rows){document.getElementById('sector-assets').innerHTML=rows.length?rows.map(x=>`<article class="admin-list-item"><strong>${esc(x.asset_id)}</strong><p>${esc(x.type)} / ${esc(x.model||x.spec_id)} / ${esc(x.health_level||'未設定')} / ${esc(x.current_status||'未設定')}</p></article>`).join(''):'<div class="empty-state">此分區目前沒有資產。</div>';}
document.getElementById('change-form').addEventListener('submit',async e=>{e.preventDefault(); const body=Object.fromEntries(new FormData(e.target)); const r=await fetch('api/admin_sectors.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(body)}); const p=await r.json(); document.getElementById('change-result').innerHTML=p.ok?'<div class="empty-state">異動已寫入 Auditlog。</div>':`<div class="error-box">${esc(p.error||JSON.stringify(p.errors))}</div>`; if(p.ok) loadSectors();});
loadSectors().catch(e=>document.getElementById('sector-list').innerHTML=`<div class="error-box">${esc(e.message)}</div>`);
</script>
</body>
</html>