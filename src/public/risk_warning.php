<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$role = strtolower((string)($_SESSION['employee_role'] ?? ''));
if (empty($_SESSION['employee_id'])) { header('Location: login.php'); exit; }
if (!in_array($role, ['assetmanager', 'deptmanager'], true)) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>智慧預警中心</title><link rel="stylesheet" href="css/style.css"></head>
<body>
<aside class="sidebar admin-nav"><h3>管理後台</h3><a href="admin_dashboard.php">後台總覽</a><a href="asset_map.php">資產地圖導覽</a><a href="admin_sector.php">饋線領地管理</a><a href="risk_warning.php">智慧預警中心</a><a href="stock_review.php">零件庫存審核</a><a href="logout.php">登出</a></aside>
<main class="main admin-shell"><header class="admin-header"><div><h1>智慧預警與汰換預測中心</h1><p>依最新巡檢風險分數與健康度列出高風險資產，並可一鍵派發維修工單。</p></div></header><section class="admin-panel"><h2>高風險預警資產清單</h2><div id="risk-list"></div></section><section class="admin-panel"><h2>巡檢風險趨勢</h2><div id="trend-list" class="stack-list"><div class="empty-state">點選清單中的資產查看歷次巡檢趨勢。</div></div></section></main>
<script>
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
async function loadRisks(assetId=''){const r=await fetch('api/risk_warning.php'+(assetId?'?asset_id='+encodeURIComponent(assetId):''),{credentials:'same-origin'}); const p=await r.json(); if(!p.ok) throw new Error(p.error||'讀取失敗'); renderRisks(p.data.assets); if(assetId)renderTrend(p.data.trend);}
function renderRisks(rows){document.getElementById('risk-list').innerHTML=rows.length?`<table><thead><tr><th>資產</th><th>饋線</th><th>健康度</th><th>風險分數</th><th>壽命使用</th><th>決策動作</th></tr></thead><tbody>${rows.map(x=>`<tr><td><button class="link-button" data-trend="${esc(x.asset_id)}">${esc(x.asset_id)}</button></td><td>${esc(x.feeder_area||x.sector_id)}</td><td>${esc(x.health_level)}</td><td>${esc(x.risk_score??'-')}</td><td>${esc(x.lifespan_used_percent??'-')}%</td><td><button class="admin-button" data-dispatch="${esc(x.asset_id)}">一鍵派發工單</button></td></tr>`).join('')}</tbody></table>`:'<div class="empty-state">目前沒有高風險資產。</div>'; document.querySelectorAll('[data-trend]').forEach(b=>b.onclick=()=>loadRisks(b.dataset.trend)); document.querySelectorAll('[data-dispatch]').forEach(b=>b.onclick=()=>dispatchWork(b.dataset.dispatch));}
function renderTrend(rows){document.getElementById('trend-list').innerHTML=rows.length?rows.map(x=>`<article class="admin-list-item"><strong>${esc(x.inspec_time)} / 風險 ${esc(x.risk_score)}</strong><p>${esc(x.observation)}</p></article>`).join(''):'<div class="empty-state">此資產尚無巡檢紀錄。</div>';}
async function dispatchWork(assetId){if(!confirm(`確定要為 ${assetId} 派發維修工單？`))return; const r=await fetch('api/risk_warning.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({asset_id:assetId})}); const p=await r.json(); alert(p.ok?`已建立工單 ${p.data.maint_id}`:(p.error||JSON.stringify(p.errors)));}
loadRisks().catch(e=>document.getElementById('risk-list').innerHTML=`<div class="error-box">${esc(e.message)}</div>`);
</script>
</body></html>