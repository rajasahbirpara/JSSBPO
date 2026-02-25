<?php
require_once 'config.php';
$token = $_GET['token'] ?? '';
// Validate token server-side before rendering page
$tv_token_res = $conn->query("SELECT setting_value FROM security_settings WHERE setting_key='tv_dashboard_token'");
$valid_token = ($tv_token_res && $r = $tv_token_res->fetch_assoc()) ? $r['setting_value'] : 'JSSTV2025';
if (empty($token) || $token !== $valid_token) {
    http_response_code(403);
    die('<h1>Access Denied</h1><p>Invalid or missing TV dashboard token.</p>');
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JSSBPO — Live Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:       #f0f2f7;
  --white:    #ffffff;
  --surface:  #f8f9fc;
  --border:   #e2e6ef;
  --border2:  #d0d6e4;

  --blue:     #2563eb;
  --blue-lt:  #eff4ff;
  --cyan:     #0891b2;
  --cyan-lt:  #ecfeff;
  --green:    #059669;
  --green-lt: #ecfdf5;
  --amber:    #d97706;
  --amber-lt: #fffbeb;
  --red:      #dc2626;
  --red-lt:   #fef2f2;
  --purple:   #7c3aed;
  --purple-lt:#f5f3ff;
  --indigo:   #4f46e5;
  --indigo-lt:#eef2ff;

  --text:     #111827;
  --text2:    #374151;
  --text3:    #6b7280;
  --text4:    #9ca3af;

  --font: 'Inter', sans-serif;
  --mono: 'JetBrains Mono', monospace;
  --shadow: 0 1px 4px rgba(0,0,0,.06), 0 2px 12px rgba(0,0,0,.04);
  --shadow2: 0 2px 8px rgba(0,0,0,.08);
}

html, body {
  height: 100%; background: var(--bg);
  color: var(--text); font-family: var(--font);
  overflow: hidden; font-size: 14px;
}

/* ── LOADER ── */
#loader {
  position:fixed; inset:0; z-index:9999;
  background: #fff;
  display:flex; flex-direction:column;
  align-items:center; justify-content:center; gap:16px;
  transition: opacity .6s;
}
.loader-logo { font-size:32px; font-weight:900; letter-spacing:4px; color:var(--blue); }
.loader-track{ width:260px; height:4px; background:#e5e7eb; border-radius:2px; overflow:hidden; }
.loader-fill { height:100%; background:linear-gradient(90deg,var(--blue),var(--cyan)); border-radius:2px; animation:fillUp 1.4s ease-out forwards; }
@keyframes fillUp{from{width:0}to{width:100%}}
.loader-sub  { font-size:11px; font-family:var(--mono); color:var(--text4); letter-spacing:2px; }

/* ── WRAPPER ── */
.wrapper{ height:100vh; display:grid; grid-template-rows:60px 1fr; }

/* ── HEADER ── */
header{
  background:var(--white); border-bottom:2px solid var(--border);
  display:flex; align-items:center; justify-content:space-between;
  padding:0 22px; box-shadow:var(--shadow2); z-index:10;
}
.hd-left { display:flex; align-items:center; gap:12px; }
.hd-logo {
  width:38px; height:38px; border-radius:9px;
  background:linear-gradient(135deg,var(--blue),var(--indigo));
  display:flex; align-items:center; justify-content:center;
  font-size:15px; font-weight:900; color:#fff; letter-spacing:0;
}
.hd-title { font-size:20px; font-weight:900; color:var(--text); letter-spacing:.5px; }
.hd-sub   { font-size:10px; color:var(--text4); letter-spacing:2px; font-family:var(--mono); margin-top:1px; }

.hd-center{ display:flex; align-items:center; gap:10px; }
.live-pill{
  display:flex; align-items:center; gap:6px;
  background:var(--green-lt); border:1.5px solid #a7f3d0;
  border-radius:20px; padding:5px 14px;
  font-size:12px; font-weight:800; color:var(--green); letter-spacing:1.5px;
}
.live-dot{ width:7px; height:7px; border-radius:50%; background:var(--green); animation:blink 1.4s ease-in-out infinite; }
@keyframes blink{0%,100%{opacity:1}50%{opacity:.25}}
.poll-ind{ display:flex; align-items:center; gap:5px; font-size:11px; font-family:var(--mono); color:var(--text4); }
.poll-dot{ width:7px; height:7px; border-radius:50%; background:var(--green); transition:background .3s; }

.hd-right { text-align:right; }
.hd-clock { font-size:30px; font-weight:900; font-family:var(--mono); color:var(--blue); letter-spacing:1px; line-height:1; }
.hd-date  { font-size:10px; color:var(--text4); font-family:var(--mono); letter-spacing:.5px; margin-top:2px; }

/* ── MAIN GRID ── */
.main{
  display:grid;
  grid-template-columns: 1fr 320px;
  grid-template-rows: 120px 1fr;
  gap:12px; padding:12px;
  height:calc(100vh - 60px);
  overflow:hidden;
}

/* ── STAT CARDS ── */
.stats-row{
  grid-column:1/-1;
  display:grid;
  grid-template-columns:repeat(6,1fr);
  gap:10px;
}
.stat-card{
  background:var(--white); border-radius:12px;
  border:1.5px solid var(--border);
  padding:14px 16px; position:relative;
  overflow:hidden; box-shadow:var(--shadow);
}
.stat-card::after{
  content:''; position:absolute;
  bottom:0; left:0; right:0; height:3px;
  border-radius:0 0 12px 12px;
}
.sc-blue::after   { background:var(--blue); }
.sc-amber::after  { background:var(--amber); }
.sc-cyan::after   { background:var(--cyan); }
.sc-purple::after { background:var(--purple); }
.sc-green::after  { background:var(--green); }
.sc-indigo::after { background:var(--indigo); }

.stat-icon{ font-size:20px; margin-bottom:6px; }
.stat-label{ font-size:10px; font-weight:700; color:var(--text3); letter-spacing:.5px; text-transform:uppercase; }
.stat-val  { font-size:38px; font-weight:900; line-height:1.1; font-family:var(--mono); }
.sc-blue .stat-val   { color:var(--blue); }
.sc-amber .stat-val  { color:var(--amber); }
.sc-cyan .stat-val   { color:var(--cyan); }
.sc-purple .stat-val { color:var(--purple); }
.sc-green .stat-val  { color:var(--green); }
.sc-indigo .stat-val { color:var(--indigo); }
.stat-sub  { font-size:10px; color:var(--text4); margin-top:4px; }
.stat-bar  { height:3px; background:var(--border); border-radius:2px; margin-top:8px; overflow:hidden; }
.stat-bar-fill{ height:100%; border-radius:2px; transition:width .9s ease; }

@keyframes cardFlash{0%{box-shadow:0 0 0 3px rgba(37,99,235,.25)}100%{box-shadow:var(--shadow)}}
.flash{ animation:cardFlash .7s ease-out; }

/* ── LEFT PANEL ── */
.left-panel{
  display:grid; grid-template-rows:1fr 1fr;
  gap:10px; overflow:hidden; min-height:0;
}

/* ── PANEL ── */
.panel{
  background:var(--white); border-radius:12px;
  border:1.5px solid var(--border); box-shadow:var(--shadow);
  display:flex; flex-direction:column; overflow:hidden; min-height:0;
}
.panel-head{
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 16px; border-bottom:1.5px solid var(--border);
  background:var(--surface); flex-shrink:0;
}
.panel-title{ font-size:13px; font-weight:800; color:var(--text); letter-spacing:.3px; display:flex; align-items:center; gap:7px; }
.panel-badge{
  font-size:10px; font-family:var(--mono); font-weight:700; letter-spacing:1px;
  background:var(--blue-lt); border:1px solid #bfdbfe;
  padding:2px 10px; border-radius:20px; color:var(--blue);
}
.panel-body{ flex:1; overflow:hidden; min-height:0; }

/* ── DEO TABLE ── */
.deo-table{ width:100%; border-collapse:collapse; }
.deo-table thead th{
  font-size:10px; font-family:var(--mono); font-weight:700;
  color:var(--text4); text-transform:uppercase; letter-spacing:1px;
  padding:8px 12px; border-bottom:1.5px solid var(--border);
  background:var(--surface); text-align:left; position:sticky; top:0;
}
.deo-table thead th:nth-child(3){ text-align:center; }
.deo-table thead th:last-child  { text-align:right; }
.deo-row{ border-bottom:1px solid var(--border); }
.deo-row:last-child{ border-bottom:none; }
.deo-row:hover td{ background:var(--surface); }
.deo-table td{ padding:9px 12px; vertical-align:middle; }
.deo-scroll{ overflow-y:auto; flex:1; }

.rank-badge{
  width:28px; height:28px; border-radius:7px;
  display:inline-flex; align-items:center; justify-content:center;
  font-size:13px; font-weight:900; font-family:var(--mono);
}
.r1{ background:#fef9c3; color:#92400e; border:1.5px solid #fde68a; }
.r2{ background:#f3f4f6; color:#374151; border:1.5px solid #d1d5db; }
.r3{ background:#fff7ed; color:#9a3412; border:1.5px solid #fed7aa; }
.rn{ background:var(--surface); color:var(--text4); border:1.5px solid var(--border); }

.deo-name-cell{ font-size:15px; font-weight:700; color:var(--text); }
.today-count  { font-size:24px; font-weight:900; font-family:var(--mono); text-align:center; }

.prog-wrap  { min-width:110px; }
.prog-track { height:8px; background:var(--border); border-radius:4px; overflow:hidden; margin-bottom:3px; }
.prog-fill  { height:100%; border-radius:4px; transition:width .9s ease; }
.prog-pct   { font-size:10px; font-family:var(--mono); color:var(--text4); font-weight:600; }
.total-cell { font-size:13px; font-family:var(--mono); color:var(--text3); font-weight:700; text-align:right; }

@keyframes rowFlash{0%{background:#d1fae5}100%{background:transparent}}
.row-flash td{ animation:rowFlash 1.5s ease-out; }

/* ── RIGHT PANEL ── */
.right-panel{
  display:grid; grid-template-rows:auto 1fr auto;
  gap:10px; overflow:hidden; min-height:0;
}

/* Progress ring */
.ring-panel .panel-body{
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:10px; padding:14px;
}
.ring-wrap{ position:relative; width:110px; height:110px; }
.ring-svg { transform:rotate(-90deg); }
.ring-track{ fill:none; stroke:#e5e7eb; stroke-width:9; }
.ring-fill {
  fill:none; stroke-width:9; stroke-linecap:round;
  stroke:url(#ringGrad);
  stroke-dasharray:288.99; stroke-dashoffset:288.99;
  transition:stroke-dashoffset 1s ease;
}
.ring-inner{
  position:absolute; inset:0;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
}
.ring-pct   { font-size:24px; font-weight:900; font-family:var(--mono); color:var(--text); }
.ring-lbl   { font-size:9px;  font-family:var(--mono); color:var(--text4); letter-spacing:2px; }
.ring-stats { display:grid; grid-template-columns:1fr 1fr; gap:8px; width:100%; }
.rstat      { text-align:center; background:var(--surface); border-radius:8px; padding:8px 4px; border:1px solid var(--border); }
.rstat-n    { font-size:20px; font-weight:900; font-family:var(--mono); }
.rstat-l    { font-size:9px;  font-family:var(--mono); color:var(--text4); letter-spacing:.5px; }

/* Pipeline */
.pipe-panel .panel-body{ padding:12px 16px; }
.pipe-list  { display:flex; flex-direction:column; gap:11px; }
.pipe-item  { }
.pipe-row   { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:4px; }
.pipe-lbl   { font-size:12px; font-weight:600; color:var(--text2); }
.pipe-val   { font-size:16px; font-weight:900; font-family:var(--mono); }
.pipe-track { height:8px; background:var(--border); border-radius:4px; overflow:hidden; }
.pipe-fill  { height:100%; border-radius:4px; transition:width .9s ease; }

/* Active users */
.active-panel .panel-body{
  padding:10px 14px; overflow-y:auto;
  display:flex; flex-wrap:wrap; gap:6px; align-content:flex-start;
}
.user-chip{
  display:inline-flex; align-items:center; gap:5px;
  background:var(--green-lt); border:1px solid #a7f3d0;
  border-radius:20px; padding:4px 12px;
  font-size:12px; font-weight:700; color:var(--green);
}
.user-dot{ width:6px; height:6px; border-radius:50%; background:var(--green); animation:blink 1.4s ease-in-out infinite; }

/* Chart */
.chart-panel .panel-body{ padding:10px 14px 12px; }

/* Fullscreen btn */
.fs-btn{
  position:fixed; bottom:14px; right:14px; z-index:100;
  background:var(--white); border:1.5px solid var(--border2);
  color:var(--text3); padding:6px 14px; border-radius:8px;
  font-family:var(--mono); font-size:11px; cursor:pointer;
  box-shadow:var(--shadow); transition:.2s;
}
.fs-btn:hover{ border-color:var(--blue); color:var(--blue); }
</style>
</head>
<body>

<!-- Loader -->
<div id="loader">
  <div class="loader-logo">JSSBPO</div>
  <div class="loader-track"><div class="loader-fill"></div></div>
  <div class="loader-sub">LOADING PRODUCTION DASHBOARD...</div>
</div>

<div class="wrapper">

<!-- HEADER -->
<header>
  <div class="hd-left">
    <div class="hd-logo">JS</div>
    <div>
      <div class="hd-title">JSSBPO</div>
      <div class="hd-sub">PRODUCTION DASHBOARD</div>
    </div>
  </div>
  <div class="hd-center">
    <div class="live-pill"><div class="live-dot"></div>LIVE</div>
    <div class="poll-ind"><div class="poll-dot" id="pollDot"></div><span>3s</span></div>
  </div>
  <div class="hd-right">
    <div class="hd-clock" id="clockDisplay">--:--:--</div>
    <div class="hd-date"  id="dateDisplay">--- -- ----</div>
  </div>
</header>

<!-- MAIN -->
<div class="main">

  <!-- STATS ROW -->
  <div class="stats-row">
    <div class="stat-card sc-blue">
      <div class="stat-label">Total Records</div>
      <div class="stat-val" id="statTotal">—</div>
      <div class="stat-sub">All assigned</div>
    </div>
    <div class="stat-card sc-amber">
      <div class="stat-label">1st QC Pending</div>
      <div class="stat-val" id="statPending">—</div>
      <div class="stat-sub">Awaiting entry</div>
    </div>
    <div class="stat-card sc-cyan">
      <div class="stat-label">2nd QC Queue</div>
      <div class="stat-val" id="statQC1">—</div>
      <div class="stat-sub">Entry done</div>
    </div>
    <div class="stat-card sc-purple">
      <div class="stat-label">2nd QC Done</div>
      <div class="stat-val" id="statQC2">—</div>
      <div class="stat-sub">QC approved</div>
    </div>
    <div class="stat-card sc-green">
      <div class="stat-label">Completed</div>
      <div class="stat-val" id="statCompleted">—</div>
      <div class="stat-sub">Fully done</div>
    </div>
    <div class="stat-card sc-indigo">
      <div class="stat-label">Today's Output</div>
      <div class="stat-val" id="statToday">—</div>
      <div class="stat-sub" id="statTodaySub">Records today</div>
      <div class="stat-bar"><div class="stat-bar-fill" id="overallProgressBar" style="width:0%;background:var(--indigo)"></div></div>
    </div>
  </div>

  <!-- LEFT -->
  <div class="left-panel">

    <!-- DEO Leaderboard -->
    <div class="panel">
      <div class="panel-head">
        <div class="panel-title">🏆 DEO Leaderboard</div>
        <div class="panel-badge" id="deoCountBadge">0 USERS</div>
      </div>
      <div class="deo-scroll">
        <table class="deo-table">
          <thead><tr>
            <th style="width:44px">#</th>
            <th>Name</th>
            <th style="width:70px">Today</th>
            <th style="width:140px">Target</th>
            <th style="width:64px">Total</th>
          </tr></thead>
          <tbody id="deoTableBody">
            <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text4);font-family:var(--mono);font-size:12px;">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Hourly Chart -->
    <div class="panel chart-panel">
      <div class="panel-head">
        <div class="panel-title">📊 Hourly Output</div>
        <div class="panel-badge">Last 8 Hours</div>
      </div>
      <div class="panel-body">
        <canvas id="hourlyChart"></canvas>
      </div>
    </div>

  </div>

  <!-- RIGHT -->
  <div class="right-panel">

    <!-- Ring -->
    <div class="panel ring-panel">
      <div class="panel-head">
        <div class="panel-title">Overall Progress</div>
        <div class="panel-badge" id="progressBadge">0% DONE</div>
      </div>
      <div class="panel-body">
        <div class="ring-wrap">
          <svg class="ring-svg" viewBox="0 0 110 110" width="110" height="110">
            <defs>
              <linearGradient id="ringGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%"   stop-color="#2563eb"/>
                <stop offset="100%" stop-color="#059669"/>
              </linearGradient>
            </defs>
            <circle class="ring-track" cx="55" cy="55" r="46"/>
            <circle class="ring-fill"  cx="55" cy="55" r="46" id="ringCircle"/>
          </svg>
          <div class="ring-inner">
            <div class="ring-pct" id="ringPct">0%</div>
            <div class="ring-lbl">DONE</div>
          </div>
        </div>
        <div class="ring-stats">
          <div class="rstat"><div class="rstat-n" style="color:var(--green)" id="rsCompleted">0</div><div class="rstat-l">COMPLETED</div></div>
          <div class="rstat"><div class="rstat-n" style="color:var(--amber)" id="rsPending">0</div><div class="rstat-l">PENDING</div></div>
        </div>
      </div>
    </div>

    <!-- Pipeline -->
    <div class="panel pipe-panel">
      <div class="panel-head">
        <div class="panel-title">🔄 Pipeline</div>
      </div>
      <div class="panel-body">
        <div class="pipe-list">
          <div class="pipe-item">
            <div class="pipe-row"><span class="pipe-lbl">1st QC Pending</span><span class="pipe-val" id="pipeVal1" style="color:var(--amber)">—</span></div>
            <div class="pipe-track"><div class="pipe-fill" id="pipeBar1" style="width:0%;background:var(--amber)"></div></div>
          </div>
          <div class="pipe-item">
            <div class="pipe-row"><span class="pipe-lbl">2nd QC Queue</span><span class="pipe-val" id="pipeVal2" style="color:var(--cyan)">—</span></div>
            <div class="pipe-track"><div class="pipe-fill" id="pipeBar2" style="width:0%;background:var(--cyan)"></div></div>
          </div>
          <div class="pipe-item">
            <div class="pipe-row"><span class="pipe-lbl">2nd QC Done</span><span class="pipe-val" id="pipeVal3" style="color:var(--purple)">—</span></div>
            <div class="pipe-track"><div class="pipe-fill" id="pipeBar3" style="width:0%;background:var(--purple)"></div></div>
          </div>
          <div class="pipe-item">
            <div class="pipe-row"><span class="pipe-lbl">Completed</span><span class="pipe-val" id="pipeVal4" style="color:var(--green)">—</span></div>
            <div class="pipe-track"><div class="pipe-fill" id="pipeBar4" style="width:0%;background:var(--green)"></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Active Users -->
    <div class="panel active-panel">
      <div class="panel-head">
        <div class="panel-title">👤 Active Now</div>
        <div class="panel-badge" id="activeCountBadge">0 ONLINE</div>
      </div>
      <div class="panel-body">
        <div id="activeUsersWrap" style="display:flex;flex-wrap:wrap;gap:6px">
          <span style="color:var(--text4);font-size:12px;font-family:var(--mono)">Loading...</span>
        </div>
      </div>
    </div>

  </div>
</div>
</div>

<button class="fs-btn" onclick="toggleFullscreen()">⛶ FULLSCREEN</button>

<script>
const TOKEN = '<?php echo htmlspecialchars($token); ?>';
let chartInst = null, lastData = null, prevTodayDone = {};

// ── CLOCK ──────────────────────────────────────────────────
function updateClock() {
    const n = new Date();
    const pad = x => String(x).padStart(2,'0');
    document.getElementById('clockDisplay').textContent = `${pad(n.getHours())}:${pad(n.getMinutes())}:${pad(n.getSeconds())}`;
    const days   = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
    const months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    document.getElementById('dateDisplay').textContent = `${days[n.getDay()]}  ${pad(n.getDate())} ${months[n.getMonth()]} ${n.getFullYear()}`;
}
setInterval(updateClock, 1000); updateClock();

// ── CHART ──────────────────────────────────────────────────
function initChart(labels, data) {
    const ctx = document.getElementById('hourlyChart').getContext('2d');
    if (chartInst) chartInst.destroy();
    chartInst = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: data.map((v,i) => i === data.length-1 ? 'rgba(5,150,105,.75)' : 'rgba(37,99,235,.2)'),
                borderColor:     data.map((v,i) => i === data.length-1 ? '#059669' : '#2563eb'),
                borderWidth: 1, borderRadius: 4,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: { duration: 500 },
            plugins: { legend: { display: false }, tooltip: {
                backgroundColor: '#ffffff', borderColor: '#e2e6ef', borderWidth: 1,
                titleColor: '#6b7280', bodyColor: '#111827',
                callbacks: { label: c => `  ${c.raw} records` }
            }},
            scales: {
                x: { grid: { color: 'rgba(229,231,235,.8)' }, ticks: { color: '#9ca3af', font: { family: 'JetBrains Mono', size: 10 } } },
                y: { grid: { color: 'rgba(229,231,235,.8)' }, ticks: { color: '#9ca3af', font: { family: 'JetBrains Mono', size: 10 }, stepSize: 1 }, beginAtZero: true }
            }
        }
    });
}

// ── RENDER DATA ────────────────────────────────────────────
function renderData(d) {
    animNum('statTotal',     d.total);
    animNum('statPending',   d.pending);
    animNum('statQC1',       d.qc1_pending);
    animNum('statQC2',       d.qc2_done);
    animNum('statCompleted', d.completed);
    animNum('statToday',     d.today_done);
    animNum('rsCompleted',   d.completed);
    animNum('rsPending',     d.pending);

    document.getElementById('overallProgressBar').style.width = d.progress + '%';

    // Ring — r=46 → circumference = 2π×46 ≈ 288.99
    const pct = parseFloat(d.progress) || 0;
    const circ = 288.99;
    document.getElementById('ringCircle').style.strokeDashoffset = circ - (pct / 100) * circ;
    document.getElementById('ringPct').textContent = pct + '%';
    document.getElementById('progressBadge').textContent = pct + '% DONE';

    // Pipeline
    const mx = Math.max(1, d.total);
    setPipeBar('pipeBar1','pipeVal1', d.pending,     mx);
    setPipeBar('pipeBar2','pipeVal2', d.qc1_pending, mx);
    setPipeBar('pipeBar3','pipeVal3', d.qc2_done,    mx);
    setPipeBar('pipeBar4','pipeVal4', d.completed,   mx);

    renderDEOs(d.deos);

    // Chart
    if (d.hourly?.length) {
        const labels = d.hourly.map(h => h.label);
        const vals   = d.hourly.map(h => parseInt(h.count));
        if (!chartInst) initChart(labels, vals);
        else {
            chartInst.data.labels = labels;
            chartInst.data.datasets[0].data = vals;
            chartInst.data.datasets[0].backgroundColor = vals.map((v,i)=>i===vals.length-1?'rgba(16,185,129,.7)':'rgba(59,130,246,.35)');
            chartInst.data.datasets[0].borderColor = vals.map((v,i)=>i===vals.length-1?'#10b981':'#3b82f6');
            chartInst.update('active');
        }
    }

    // Active users
    const wrap = document.getElementById('activeUsersWrap');
    document.getElementById('activeCountBadge').textContent = (d.active_users?.length || 0) + ' ONLINE';
    wrap.innerHTML = d.active_users?.length
        ? d.active_users.map(u => `<div class="user-chip"><div class="user-dot"></div>${u}</div>`).join('')
        : '<span style="color:var(--text4);font-size:11px;font-family:var(--mono)">No activity in last 10 min</span>';

    // Flash stat cards
    document.querySelectorAll('.stat-card').forEach(el => {
        el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash');
    });
}

function setPipeBar(barId, valId, count, max) {
    document.getElementById(barId).style.width = Math.min(100, Math.round(count/max*100)) + '%';
    document.getElementById(valId).textContent = count;
}

function renderDEOs(deos) {
    if (!deos?.length) {
        document.getElementById('deoTableBody').innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text3);font-family:var(--mono);font-size:11px;">NO DEO DATA</td></tr>';
        return;
    }
    document.getElementById('deoCountBadge').textContent = deos.length + ' USERS';
    const rankClass = i => ['r1','r2','r3'][i] || 'rn';
    let html = '';
    deos.forEach((d, i) => {
        const pct = parseInt(d.target_pct) || 0;
        const barColor = pct >= 100 ? 'var(--green)' : pct >= 70 ? 'var(--cyan)' : pct >= 40 ? 'var(--amber)' : 'var(--red)';
        const numColor = pct >= 100 ? 'var(--green)' : pct >= 70 ? 'var(--cyan)' : pct >= 40 ? 'var(--amber)' : 'var(--text)';
        const prev = prevTodayDone[d.username] || 0;
        const flash = parseInt(d.today_done) > prev ? 'row-flash' : '';
        prevTodayDone[d.username] = parseInt(d.today_done);
        html += `<tr class="deo-row ${flash}">
            <td><div class="rank-badge ${rankClass(i)}">${i+1}</div></td>
            <td class="deo-name-cell">${d.display_name}</td>
            <td><div class="today-count" style="color:${numColor}">${d.today_done}</div></td>
            <td class="progress-col">
                <div class="prog-track"><div class="prog-fill" style="width:${pct}%;background:${barColor}"></div></div>
                <div class="prog-pct">${pct}% of target</div>
            </td>
            <td class="total-count">${d.completed}</td>
        </tr>`;
    });
    document.getElementById('deoTableBody').innerHTML = html;
}

function animNum(id, newVal) {
    const el = document.getElementById(id);
    if (!el) return;
    const cur = parseInt(el.textContent.replace(/\D/g,'')) || 0;
    const tgt = parseInt(newVal) || 0;
    if (cur === tgt) return;
    const steps = 18, step = (tgt - cur) / steps;
    let cnt = 0;
    const t = setInterval(() => {
        el.textContent = Math.round(cur + step * ++cnt);
        if (cnt >= steps) { el.textContent = tgt; clearInterval(t); }
    }, 28);
}

// ── POLLING ────────────────────────────────────────────────
let lastHash = '', pollTimer = null, pollFailCount = 0;

function hideLoader() {
    const l = document.getElementById('loader');
    if (l && l.style.display !== 'none') { l.style.opacity = '0'; setTimeout(() => l.style.display = 'none', 700); }
}

function poll() {
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=tv_dashboard_data&token=${encodeURIComponent(TOKEN)}&last_hash=${encodeURIComponent(lastHash)}`
    })
    .then(r => { if (!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(data => {
        pollFailCount = 0;
        document.getElementById('pollDot').style.background = 'var(--green)';
        if (data.status === 'success') { lastHash = data.hash || ''; renderData(data); hideLoader(); }
    })
    .catch(() => {
        pollFailCount++;
        document.getElementById('pollDot').style.background = 'var(--red)';
    })
    .finally(() => {
        pollTimer = setTimeout(poll, pollFailCount > 3 ? 10000 : 3000);
    });
}

function toggleFullscreen() {
    !document.fullscreenElement ? document.documentElement.requestFullscreen().catch(()=>{}) : document.exitFullscreen();
}

window.addEventListener('DOMContentLoaded', () => poll());
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') { clearTimeout(pollTimer); lastHash = ''; poll(); }
});

</script>
</body>
</html>