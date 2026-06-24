<?php
declare(strict_types=1);

require_once __DIR__ . '/backend-common.php';
require_once __DIR__ . '/backend-layout.php';
backend_require_roles(['deptmanager', 'assetmanager']);

backend_render_header('智慧預警中心', '依最新巡檢風險分數與健康度列出高風險資產，並可一鍵派發維修工單。', true);
?>
<section class="admin-panel"><h2>高風險預警資產清單</h2><div id="risk-list"></div></section><section class="admin-panel"><h2>巡檢風險趨勢</h2><div id="trend-list" class="stack-list"><div class="empty-state">點選清單中的資產查看歷次巡檢趨勢。</div></div></section>
<script>
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
async function loadRisks(assetId=''){const r=await fetch('api/risk_warning.php'+(assetId?'?asset_id='+encodeURIComponent(assetId):''),{credentials:'same-origin'}); const p=await r.json(); if(!p.ok) throw new Error(p.error||'讀取失敗'); renderRisks(p.data.assets); if(assetId)renderTrend(p.data.trend);}
function renderRisks(rows){document.getElementById('risk-list').innerHTML=rows.length?`<table><thead><tr><th>資產</th><th>饋線</th><th>健康度</th><th>風險分數</th><th>壽命使用</th><th>決策動作</th></tr></thead><tbody>${rows.map(x=>`<tr><td><button class="link-button" data-trend="${esc(x.asset_id)}">${esc(x.asset_id)}</button></td><td>${esc(x.feeder_area||x.sector_id)}</td><td>${esc(x.health_level)}</td><td>${esc(x.risk_score??'-')}</td><td>${esc(x.lifespan_used_percent??'-')}%</td><td><button class="admin-button" data-dispatch="${esc(x.asset_id)}">一鍵派發工單</button></td></tr>`).join('')}</tbody></table>`:'<div class="empty-state">目前沒有高風險資產。</div>'; document.querySelectorAll('[data-trend]').forEach(b=>b.onclick=()=>loadRisks(b.dataset.trend)); document.querySelectorAll('[data-dispatch]').forEach(b=>b.onclick=()=>dispatchWork(b.dataset.dispatch));}
function renderTrend(rows){document.getElementById('trend-list').innerHTML=rows.length?rows.map(x=>`<article class="admin-list-item"><strong>${esc(x.inspec_time)} / 風險 ${esc(x.risk_score)}</strong><p>${esc(x.observation)}</p></article>`).join(''):'<div class="empty-state">此資產尚無巡檢紀錄。</div>';}
async function dispatchWork(assetId){if(!confirm(`確定要為 ${assetId} 派發維修工單？`))return; const r=await fetch('api/risk_warning.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({asset_id:assetId})}); const p=await r.json(); alert(p.ok?`已建立工單 ${p.data.maint_id}`:(p.error||JSON.stringify(p.errors)));}
loadRisks().catch(e=>document.getElementById('risk-list').innerHTML=`<div class="error-box">${esc(e.message)}</div>`);
</script>
<?php backend_render_footer(); ?>