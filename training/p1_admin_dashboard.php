<?php
session_start();
require_once 'config.php';

// Session & Role Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --bg-color:#f4f6f9; --text-color:#212529; --card-bg:#ffffff; --input-bg:#ffffff; --border-color:#dee2e6; --readonly-bg:#f8f9fa; --readonly-text:#495057; --table-border:#000; --primary:#0d6efd; --success:#198754; --warning:#ffc107; --danger:#dc3545; }
        body.dark-mode { --bg-color:#121212; --text-color:#e0e0e0; --card-bg:#1e1e1e; --input-bg:#2d2d2d; --border-color:#444; --readonly-bg:#252525; --readonly-text:#adb5bd; --table-border:#555; }
        body, html { height:100%; margin:0; padding:0; background-color:var(--bg-color); color:var(--text-color); font-size:12px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Disable spell check red underline */
        * { -webkit-spellcheck: false; spellcheck: false; }
        input, textarea, td[contenteditable], [contenteditable="true"] {
            -webkit-spellcheck: false;
            spellcheck: false;
            -moz-spellcheck: false;
        }
        
        /* ========== HEADER BAR STYLES ========== */
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            padding: 8px 15px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .header-left { display: flex; align-items: center; gap: 10px; }
        .header-title { font-size: 14px; font-weight: 600; }
        .header-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
        .badge-user { background: rgba(255,255,255,0.9); color: #1e3c72; }
        .badge-clock { background: rgba(0,0,0,0.3); color: white; }
        .header-stats { display: flex; gap: 8px; }
        .stat-box { padding: 5px 12px; border-radius: 6px; text-align: center; min-width: 90px; }
        .stat-number { font-size: 16px; font-weight: 700; display: block; }
        .stat-label { font-size: 10px; opacity: 0.9; }
        .stat-pending { background: #ffc107; color: #000; }
        .stat-done { background: #28a745; color: #fff; }
        .stat-completed { background: #dc3545; color: #fff; }
        .stat-today { background: #17a2b8; color: #fff; }
        .header-right { display: flex; gap: 5px; }
        
        /* ========== CONTROLS ROW STYLES ========== */
        .controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 8px;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .filter-label { font-size: 11px; font-weight: 600; color: var(--text-color); }
        .filter-separator { font-size: 10px; color: #666; }
        .filter-group .form-control, .filter-group .form-select {
            font-size: 11px;
            padding: 4px 8px;
            height: 28px;
        }
        .filter-group input[type="date"] { width: 110px; }
        .filter-group select { width: 100px; }
        .filter-group input[type="text"] { width: 120px; }
        
        .menu-buttons { display: flex; gap: 4px; flex-wrap: wrap; }
        .menu-btn {
            font-size: 11px;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        .menu-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .btn-settings { background: #343a40; color: white; }
        .btn-data { background: #6c757d; color: white; }
        .btn-notify { background: #17a2b8; color: white; }
        .btn-reports { background: #28a745; color: white; }
        .btn-security { background: #dc3545; color: white; }
        .btn-users { background: #ffc107; color: #000; }
        .btn-message { background: #0d6efd; color: white; }
        
        /* ========== ACTION ROW STYLES ========== */
        .action-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }
        .action-group { display: flex; gap: 5px; }
        .action-btn {
            font-size: 11px;
            padding: 5px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        .action-btn:hover { transform: translateY(-1px); }
        .btn-assign { background: #fd7e14; color: white; }
        .btn-comp { background: #dc3545; color: white; }
        .btn-done { background: #0d6efd; color: white; }
        .btn-pend { background: #6c757d; color: white; }
        .btn-sync { background: #17a2b8; color: white; }
        .btn-refresh { background: #28a745; color: white; }
        
        /* ========== EXISTING STYLES ========== */
        .bg-purple { background-color: #6f42c1 !important; color: white !important; }
        #mainContainer { display:flex; flex-direction:column; height:100vh; padding:8px; box-sizing:border-box; }
        #dataPanel { flex:1 1 auto; background:var(--card-bg); border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); overflow:hidden; display:flex; flex-direction:column; border:1px solid var(--border-color); }
        .table-container { overflow:auto; flex:1; }
        table { margin-bottom:0; color:var(--text-color); font-size: 11px; }
        th { background-color:#0d6efd!important; color:white; white-space:nowrap; padding:8px 6px; border:1px solid #000!important; position:sticky; top:0; z-index:100; font-weight:600; font-size: 11px; }
        td { white-space:nowrap; cursor:pointer; border:1px solid var(--table-border)!important; padding:4px 6px; vertical-align:middle; transition: background-color 0.2s; font-size: 11px; }
        td.readonly { background-color:var(--readonly-bg); color:var(--readonly-text); font-weight:bold; cursor:default; }
        /* Completed cell - selectable, navigable but not editable */
        td.completed-cell { cursor:text; user-select:text; }
        td.completed-cell:focus { outline:2px solid #dc3545!important; outline-offset:-2px; }
        /* Unsaved cell - edited data highlight (HIGH PRIORITY - Yellow) */
        td.unsaved-cell { background-color:#fff3cd!important; outline:2px solid #ffc107!important; color:#000!important; z-index:10; position:relative; }
        tr.active-row td.unsaved-cell { background-color:#fff3cd!important; outline:2px solid #ffc107!important; }
        tr.saved-row td.unsaved-cell { background-color:#fff3cd!important; outline:2px solid #ffc107!important; }
        /* Filled cell - saved data highlight (GREEN) */
        td.filled-cell { background-color:#d4edda!important; color:#155724!important; }
        tr.saved-row td.filled-cell { background-color:#c3e6cb!important; color:#155724!important; }
        tr.completed-row td.filled-cell { background-color:#c3e6cb!important; color:#155724!important; }
        body.dark-mode td.filled-cell { background-color:#1e4620!important; color:#8eda8e!important; }
        body.dark-mode tr.saved-row td.filled-cell { background-color:#1e4620!important; color:#8eda8e!important; }
        body.dark-mode tr.completed-row td.filled-cell { background-color:#1e4620!important; color:#8eda8e!important; }
        
        tr td.initial-flagged-cell { background-color:#cfe2ff!important; outline:1px solid #0d6efd; font-weight:500; }
        
        /* Row status colors */
        tr.saved-row > td { background-color:#d4edda!important; } /* GREEN for 1st QC Done */
        body.dark-mode tr.saved-row > td { background-color:#4a2800!important; }
        tr.qc-done-row > td { background-color:#cfe2ff!important; } /* BLUE for QC Done */
        body.dark-mode tr.qc-done-row > td { background-color:#0c2d5e!important; }
        tr.completed-row > td { background-color:#f8d7da!important; } 
        body.dark-mode tr.completed-row > td { background-color:#4a181d!important; }
        
        /* Active row highlight - highest priority for non-edited cells */
        tr.active-row > td:not(.unsaved-cell) { background-color:#9ec5fe!important; border-top:2px solid #0a58ca!important; border-bottom:2px solid #0a58ca!important; }
        tr.active-row > td.unsaved-cell { border-top:2px solid #0a58ca!important; border-bottom:2px solid #0a58ca!important; }
        body.dark-mode tr.active-row > td:not(.unsaved-cell) { background-color:#0c2d5e!important; }
        tr.saved-row.active-row > td:not(.unsaved-cell) { background-color:#9ec5fe!important; }
        tr.qc-done-row.active-row > td:not(.unsaved-cell) { background-color:#9ec5fe!important; }
        tr.completed-row.active-row > td { background-color:#9ec5fe!important; }
        td.record-cell:focus { outline:3px solid #ffc107!important; outline-offset:-3px; }
        
        /* Invalid Record Styles */
        td.invalid-record { background-color: #fff3cd !important; }
        td.invalid-record b { color: #dc3545 !important; }
        body.dark-mode td.invalid-record { background-color: #5c4813 !important; }
        
        /* Record Context Menu */
        .record-context-menu { position:fixed; z-index:10000; background:white; border:1px solid #ddd; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); min-width:180px; padding:5px 0; }
        .record-context-menu .menu-item { padding:8px 15px; cursor:pointer; display:flex; align-items:center; gap:8px; font-size:13px; }
        .record-context-menu .menu-item:hover { background:#f0f0f0; }
        .record-context-menu .menu-divider { height:1px; background:#eee; margin:5px 0; }
        body.dark-mode .record-context-menu { background:#2d2d2d; border-color:#444; }
        body.dark-mode .record-context-menu .menu-item:hover { background:#3d3d3d; }
        
        #imageViewerContainer { display:none; flex:0 0 350px; background:#2c3e50; color:white; padding:5px; border-radius:8px; position:relative; box-shadow:0 -4px 12px rgba(0,0,0,0.3); margin-top:5px; flex-direction:column; overflow:hidden; }
        .viewer-body { flex:1; overflow:auto; background:#222; border:1px solid #555; position:relative; padding:50px; display:block; }
        #imgWrapper { transform-origin:0 0; transition:transform 0.1s; position:relative; cursor:grab; display:inline-block; }
        #imgWrapper:active { cursor:grabbing; }
        #zoomImage { display:block; max-width:none; width:auto; height:auto; transition:filter 0.1s; }
        #highlightLayer { position:absolute; top:0; left:0; pointer-events:none; }
        #loader { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:9999; color:white; }
        .loader-content { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center; }
        #successMessage { position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:10000; padding:15px 30px; background-color:#28a745; color:white; border-radius:8px; display:none; }
        #validationTooltip { position:absolute; padding:8px 12px; border-radius:4px; z-index:11000; max-width:300px; display:none; box-shadow:0 4px 8px rgba(0,0,0,0.4); font-size:12px; pointer-events:none; border:1px solid #ccc; background:#333; color:white; }
        #divider { height:10px; background:#ccc; cursor:row-resize; display:none; align-items:center; justify-content:center; margin:5px 0; }
        .magnifier-lens { position:absolute; width:150px; height:150px; border:2px solid #fff; border-radius:50%; cursor:none; display:none; pointer-events:none; z-index:1000; box-shadow:0 0 10px rgba(0,0,0,0.8); background-repeat:no-repeat; background-color:#000; }
        .img-controls-bar { display: flex; flex-wrap: wrap; gap: 5px; padding: 5px; background: #34495e; align-items: center; }
        .filter-group { display: flex; align-items: center; gap: 3px; background: rgba(0,0,0,0.2); padding: 2px 5px; border-radius: 4px; }
        .filter-group label { font-size: 10px; margin: 0; color: #ccc; }
        
        /* UI/UX Improvements - Phase 6 */
        
        /* Toast Notifications */
        #toastContainer { position:fixed; top:20px; right:20px; z-index:10001; display:flex; flex-direction:column; gap:10px; }
        .toast-notification { padding:12px 20px; border-radius:8px; color:white; font-weight:500; animation: slideIn 0.3s ease; box-shadow:0 4px 12px rgba(0,0,0,0.3); display:flex; align-items:center; gap:10px; max-width:350px; }
        .toast-success { background:linear-gradient(135deg, #28a745, #20c997); }
        .toast-error { background:linear-gradient(135deg, #dc3545, #e74c3c); }
        .toast-warning { background:linear-gradient(135deg, #ffc107, #f39c12); color:#333; }
        .toast-info { background:linear-gradient(135deg, #17a2b8, #3498db); }
        @keyframes slideIn { from { transform:translateX(100%); opacity:0; } to { transform:translateX(0); opacity:1; } }
        @keyframes slideOut { from { transform:translateX(0); opacity:1; } to { transform:translateX(0); opacity:0; } }
        
        /* Progress Indicator */
        #progressBar { position:fixed; top:0; left:0; width:100%; height:3px; background:#e0e0e0; z-index:10002; display:none; }
        #progressBar .progress-fill { height:100%; background:linear-gradient(90deg, #0d6efd, #20c997); width:0%; transition:width 0.3s; }
        
        /* Search Highlight */
        .search-highlight { background-color:#ffeb3b!important; color:#000!important; font-weight:bold; }
        
        /* Row Hover Effect */
        tbody tr:hover > td:not(.unsaved-cell):not(.active-row td) { background-color:rgba(13,110,253,0.1)!important; }
        body.dark-mode tbody tr:hover > td:not(.unsaved-cell):not(.active-row td) { background-color:rgba(13,110,253,0.2)!important; }
        
        /* Button Animations */
        .btn { transition: all 0.2s ease; }
        .btn:active { transform:scale(0.95); }
        
        /* Card Hover Effect */
        .card { transition: box-shadow 0.3s, transform 0.2s; }
        .card:hover { box-shadow:0 8px 25px rgba(0,0,0,0.15); }
        
        /* Badge Pulse Animation */
        .badge-pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity:1; } 50% { opacity:0.6; } }
        
        /* Status Indicator Dots */
        .status-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:5px; }
        .status-dot.online { background:#28a745; box-shadow:0 0 5px #28a745; }
        .status-dot.offline { background:#dc3545; }
        .status-dot.away { background:#ffc107; }
        
        /* Better Scrollbar */
        ::-webkit-scrollbar { width:8px; height:8px; }
        ::-webkit-scrollbar-track { background:var(--bg-color); }
        ::-webkit-scrollbar-thumb { background:#888; border-radius:4px; }
        ::-webkit-scrollbar-thumb:hover { background:#666; }
        
        /* Modal Animation */
        .modal.fade .modal-dialog { transition:transform 0.3s ease-out; }
        .modal.show .modal-dialog { transform:none; }
        
        /* Input Focus Glow */
        .form-control:focus, .form-select:focus { box-shadow:0 0 0 3px rgba(13,110,253,0.25); }
        
        /* Tooltip Enhancement */
        [data-tooltip] { position:relative; }
        [data-tooltip]:hover::after { content:attr(data-tooltip); position:absolute; bottom:100%; left:50%; transform:translateX(-50%); padding:5px 10px; background:#333; color:#fff; border-radius:4px; font-size:11px; white-space:nowrap; z-index:1000; }
        
        /* Loading Spinner */
        .spinner-sm { width:16px; height:16px; border-width:2px; }
        
        /* Empty State */
        .empty-state { text-align:center; padding:40px; color:#888; }
        .empty-state i { font-size:48px; margin-bottom:15px; opacity:0.5; }
        
        /* Gradient Headers */
        .modal-header.bg-primary { background:linear-gradient(135deg, #0d6efd, #0a58ca)!important; }
        .modal-header.bg-success { background:linear-gradient(135deg, #198754, #146c43)!important; }
        .modal-header.bg-danger { background:linear-gradient(135deg, #dc3545, #b02a37)!important; }
        .modal-header.bg-info { background:linear-gradient(135deg, #17a2b8, #138496)!important; }
        .modal-header.bg-warning { background:linear-gradient(135deg, #ffc107, #e0a800)!important; }
        .modal-header.bg-secondary { background:linear-gradient(135deg, #6c757d, #5a6268)!important; }
        
        /* Real-time Clock */
        #realTimeClock { font-family:monospace; font-size:14px; background:rgba(0,0,0,0.1); padding:5px 10px; border-radius:4px; }
        body.dark-mode #realTimeClock { background:rgba(255,255,255,0.1); }
    </style>
</head>
<body>

<?php include 'validation_rules.php'; ?>

<!-- Progress Bar -->
<div id="progressBar"><div class="progress-fill"></div></div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<div id="loader"><div class="loader-content"><h4>Processing...</h4><div class="spinner-border"></div></div></div>
<div id="successMessage">✔️ Action Successful!</div>

<div id="mainContainer" style="display:none;">
    <div id="topControls">
        <!-- Top Header Bar -->
        <div class="header-bar mb-2">
            <div class="header-left">
                <span class="header-title">🛡️ Admin Dashboard</span>
                <span id="userBadge" class="header-badge badge-user"></span>
                <span id="realTimeClock" class="header-badge badge-clock"></span>
            </div>
            <div class="header-stats">
                <div class="stat-box stat-pending">
                    <span class="stat-number" id="totalPendingCount">0</span>
                    <span class="stat-label">First QC Pending</span>
                </div>
                <div class="stat-box" style="background:#27ae60; color:#fff;" id="qcPendingBox">
                    <span class="stat-number" id="qcPendingCount">0</span>
                    <span class="stat-label">First QC Done</span>
                </div>
                <div class="stat-box" style="background:#0d6efd;" id="qcDoneBox">
                    <span class="stat-number" id="qcDoneCount">0</span>
                    <span class="stat-label">Second QC Done</span>
                </div>
                <div class="stat-box stat-completed">
                    <span class="stat-number" id="totalCompletedCount">0</span>
                    <span class="stat-label">Final Completed</span>
                </div>
                <div class="stat-box" style="background:#9b0000; color:#ff9999;">
                    <span class="stat-number" id="todayCompletedCount">0</span>
                    <span class="stat-label">Final Today Completed</span>
                </div>
                <div class="stat-box" style="background:#e67e22; color:#fff; display:none;" id="reportCountAdminBox">
                    <span class="stat-number" id="adminReportCount">0</span>
                    <span class="stat-label">⚠️ Reports Open</span>
                </div>
            </div>
            <div class="header-right">
                <!-- QC Toggle -->
                <div class="form-check form-switch me-2" style="display:flex; align-items:center; gap:5px;">
                    <input class="form-check-input" type="checkbox" id="qcToggle" onchange="toggleQCSystem()" style="cursor:pointer; width:40px; height:20px;">
                    <label class="form-check-label text-white" for="qcToggle" style="font-size:11px;">QC System</label>
                </div>
                <button class="btn btn-sm btn-outline-light" onclick="toggleTheme()" title="Toggle Theme">🌓</button>
                <a href="logout.php" class="btn btn-sm btn-outline-light">Logout</a>
            </div>
        </div>
        
        <!-- Controls Row 1: Filters & Menu Buttons -->
        <div class="controls-row mb-2">
            <div class="filter-group">
                <span class="filter-label">📅 FILTER:</span>
                <input type="date" id="filterStartDate" class="form-control form-control-sm" onchange="loadData()">
                <span class="filter-separator">to</span>
                <input type="date" id="filterEndDate" class="form-control form-control-sm" onchange="loadData()">
                <select id="filterStatus" class="form-select form-select-sm" onchange="loadData()">
                    <option value="">All Status</option>
                    <option value="pending">First QC Pending</option>
                    <option value="deo_done">First QC Done</option>
                    <option value="qc_done">Second QC Done</option>
                    <option value="completed">Final Completed</option>
                    <option value="invalid">⚠️ Invalid Record No</option>
                </select>
                <select id="adminUserFilter" class="form-select form-select-sm" onchange="loadData();">
                    <option value="">All Users</option>
                </select>
                <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="🔍 Search..." onkeyup="filterData()">
                <button class="btn btn-warning btn-sm" onclick="autoMarkInvalidRecords()" title="Auto-detect non-numeric record numbers">⚠️ Scan Invalid</button>
            </div>
            <div class="menu-buttons">
                <button class="menu-btn btn-settings" onclick="$('#settingsModal').modal('show'); loadSystemSettings();">⚙️ Settings</button>
                <button class="menu-btn btn-data" onclick="$('#dataModal').modal('show'); loadDataStats();">📁 Data</button>
                <button class="menu-btn btn-notify" onclick="$('#notificationsModal').modal('show'); loadNotificationSettings();">🔔 Notify</button>
                <button class="menu-btn btn-reports" onclick="$('#reportsModal').modal('show'); loadReportsData();">📊 Reports</button>
                <button class="menu-btn btn-security" onclick="$('#securityModal').modal('show'); loadSecurityData();">🔒 Security</button>
                <button class="menu-btn btn-security" onclick="openDatabaseSettings()">🗄️ Database</button>
                <button class="menu-btn btn-users" onclick="resetUserForm(); $('#adminModal').modal('show'); loadUsersForModal();">👥 Users</button>
                <button class="menu-btn btn-message" onclick="$('#messageModal').modal('show'); loadUsersForMessage();">💬 Message</button>
            </div>
        </div>

        <!-- Controls Row 2: Action Buttons -->
        <div class="action-row mb-2">
            <div class="action-group">
                <button class="action-btn btn-assign" onclick="showAssignmentModal()">📋 Assign</button>
                <button class="action-btn btn-comp" onclick="batchStatusUpdate('Completed')">✅ Completed</button>
                <button class="action-btn btn-done" onclick="batchStatusUpdate('done')">✔️ Done</button>
                <button class="action-btn btn-pend" onclick="batchStatusUpdate('pending')">⏳ Pending</button>
                <button class="action-btn btn-sync" onclick="syncImages()">📷 Sync Images</button>
                <button class="action-btn btn-refresh" onclick="loadData()">🔄 Refresh</button>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="autoScrollCheck" checked>
                    <label class="form-check-label" style="font-size: 11px;">Auto Scroll</label>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Panel -->
    <div id="dataPanel">
        <div class="table-container">
            <table class="table table-bordered table-sm table-striped" id="dataTable">
                <thead>
                    <tr>
                        <th class="text-center"><input type="checkbox" onchange="toggleAll(this)"></th>
                        <th>S.No</th><th>Record No.</th><th>Action</th><th>User</th><th>Status</th>
                        <th>KYC No</th><th>Name</th><th>Guardian</th><th>Gender</th><th>Marital</th><th>DOB</th><th>Address</th><th>Landmark</th><th>City</th><th>Zip</th><th>Birth City</th><th>Nationality</th><th>Photo</th><th>Res. Status</th><th>Occupation</th><th>OVD</th><th>Income</th><th>Broker</th><th>Sub Broker</th><th>Bank Serial</th><th>2nd App</th><th>Amt From</th><th>Amount</th><th>ARN</th><th>2nd Addr</th><th>Profession</th><th>Remarks</th><th>Time</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
        <div id="paginationControls" class="p-2 border-top bg-light d-flex justify-content-between"><span id="pageInfo">Page 1</span><div><button class="btn btn-sm btn-secondary" onclick="changePage(-1)">Prev</button><button class="btn btn-sm btn-primary" onclick="changePage(1)">Next</button></div></div>
    </div>

    <!-- Divider -->
    <div id="divider"></div>

    <!-- Image Viewer -->
    <div id="imageViewerContainer">
        <div class="d-flex justify-content-between p-1 bg-dark">
            <small>Image: <span id="imageFileName" class="text-warning"></span> <span id="ocrStatus" class="ms-2 text-info"></span></small>
            <button onclick="$('#imageViewerContainer').hide(); $('#divider').hide();" class="btn btn-sm btn-danger py-0">✖</button>
        </div>
        <div class="viewer-body" id="viewerBody">
            <div id="magnifierLens" class="magnifier-lens"></div>
            <div id="imgWrapper">
                <img id="zoomImage" src="">
                <canvas id="highlightLayer"></canvas>
            </div>
             <div id="imageUploadFallback" style="display:none; position:absolute; top:0;left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); flex-direction:column; align-items:center; justify-content:center;">
                <p>Image Missing. Record: <span id="fallbackRecordNo"></span></p>
                <input type="file" id="singleImageFile" class="form-control w-75 mb-2">
                <button onclick="uploadSingleImage()" class="btn btn-warning">Upload</button>
            </div>
        </div>
        <div class="img-controls-bar">
            <button class="btn btn-sm btn-light" onclick="adjustZoom(0.2)">+</button>
            <button class="btn btn-sm btn-light" onclick="adjustZoom(-0.2)">-</button>
            <button class="btn btn-sm btn-warning" onclick="resetZoom()">Reset</button>
            <button class="btn btn-sm btn-info" onclick="rotateImg()">Rot</button>
            <button class="btn btn-sm btn-secondary" id="btnInvert" onclick="toggleInvert()">Inv</button>
            <button class="btn btn-sm btn-outline-danger" id="btnLock" onclick="toggleImageLock()" title="Lock Image">🔓</button>
            <button class="btn btn-sm btn-outline-warning" id="btnMag" onclick="toggleMagnifier()">🔍 Mag</button>
            <div class="filter-group"><label>☀</label><input type="range" min="50" max="200" value="100" id="brightRange" oninput="applyFilters()"></div>
            <div class="filter-group"><label>◑</label><input type="range" min="50" max="200" value="100" id="contrastRange" oninput="applyFilters()"></div>
        </div>
    </div>
</div>

<!-- Message Modal -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">💬 Send Message to DEO</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabSendMsg">📤 Send Message</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabMsgHistory">📋 History</a></li>
                </ul>
                
                <div class="tab-content">
                    <!-- Send Message Tab -->
                    <div class="tab-pane fade show active" id="tabSendMsg">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Send To:</label>
                                <select id="msgRecipient" class="form-select">
                                    <option value="0">📢 All DEOs (Broadcast)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Priority:</label>
                                <select id="msgPriority" class="form-select">
                                    <option value="normal">🟢 Normal</option>
                                    <option value="urgent">🔴 Urgent</option>
                                    <option value="warning">🟡 Warning</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Message:</label>
                                <textarea id="msgContent" class="form-control" rows="4" placeholder="Type your message here..."></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary w-100" onclick="sendMessageToDeo()">
                                    📤 Send Message
                                </button>
                            </div>
                        </div>
                        
                        <!-- Quick Messages -->
                        <div class="mt-3">
                            <label class="form-label fw-bold small">Quick Messages:</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-secondary btn-sm" onclick="setQuickMsg('Please complete your pending tasks.')">📋 Complete Tasks</button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="setQuickMsg('Meeting in 10 minutes. Please be ready.')">📅 Meeting Alert</button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="setQuickMsg('Great work today! Keep it up.')">👏 Appreciation</button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="setQuickMsg('Please check your assigned records.')">🔍 Check Records</button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="setQuickMsg('System maintenance in 30 minutes.')">⚠️ Maintenance</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Message History Tab -->
                    <div class="tab-pane fade" id="tabMsgHistory">
                        <button class="btn btn-sm btn-primary mb-2" onclick="loadMessageHistory()">🔄 Refresh</button>
                        <div style="max-height:350px; overflow-y:auto;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>Time</th>
                                        <th>To</th>
                                        <th>Message</th>
                                        <th>Priority</th>
                                        <th>Read</th>
                                    </tr>
                                </thead>
                                <tbody id="msgHistoryTable"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<div class="modal fade" id="adminModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">👥 User Management</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="userTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabUserList">📋 Users</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabAddUser">➕ Add User</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabPerformance">📊 Performance</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabActiveSessions">🟢 Active</a></li>
                </ul>
                
                <div class="tab-content">
                    <!-- User List Tab -->
                    <div class="tab-pane fade show active" id="tabUserList">
                        <div style="max-height:400px; overflow-y:auto;">
                            <table class="table table-sm table-hover">
                                <thead class="table-dark sticky-top">
                                    <tr><th>User</th><th>Role</th><th>Status</th><th>Target</th><th>Last Login</th><th>Actions</th></tr>
                                </thead>
                                <tbody id="userListTable"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Add User Tab -->
                    <div class="tab-pane fade" id="tabAddUser">
                        <input type="hidden" id="editUserId">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" id="newUser" class="form-control" placeholder="Username">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password</label>
                                <input type="password" id="newPass" class="form-control" placeholder="Password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Full Name</label>
                                <input type="text" id="newFullName" class="form-control" placeholder="Full Name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone (WhatsApp)</label>
                                <input type="text" id="newPhone" class="form-control" placeholder="91XXXXXXXXXX">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Role</label>
                                <select id="newRole" class="form-select">
                                    <option value="deo">DEO</option>
                                    <option value="qc">QC (Quality Checker)</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Daily Target</label>
                                <input type="number" id="newTarget" class="form-control" placeholder="100" value="100">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button id="btnAddUser" onclick="addUser()" class="btn btn-primary w-100">Add User</button>
                            </div>
                            <div class="col-12">
                                <button id="btnCancelEdit" onclick="resetUserForm()" class="btn btn-secondary" style="display:none;">Cancel Edit</button>
                            </div>
                        </div>
                        
                        <!-- Fix Username Section -->
                        <hr class="my-3">
                        <div class="card border-warning">
                            <div class="card-header bg-warning text-dark">🔧 Fix Username in Records (Manual)</div>
                            <div class="card-body">
                                <p class="small text-muted mb-2">Use this if you changed username but records still show old username</p>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <input type="text" id="fixOldUsername" class="form-control" placeholder="Old Username">
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" id="fixNewUsername" class="form-control" placeholder="New Username">
                                    </div>
                                    <div class="col-md-4">
                                        <button onclick="fixUsernameInRecords()" class="btn btn-warning w-100">🔧 Fix Records</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Performance Tab -->
                    <div class="tab-pane fade" id="tabPerformance">
                        <div class="d-flex gap-2 mb-3">
                            <select id="perfPeriod" class="form-select" style="width:150px;" onchange="loadPerformance()">
                                <option value="today">Today</option>
                                <option value="week">This Week</option>
                                <option value="month">This Month</option>
                            </select>
                            <button class="btn btn-primary btn-sm" onclick="loadPerformance()">Refresh</button>
                        </div>
                        <div style="max-height:350px; overflow-y:auto;">
                            <table class="table table-sm">
                                <thead class="table-success sticky-top">
                                    <tr><th>#</th><th>User</th><th>Completed</th><th>Target</th><th>Progress</th><th>Avg Time</th></tr>
                                </thead>
                                <tbody id="performanceTable"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Active Sessions Tab -->
                    <div class="tab-pane fade" id="tabActiveSessions">
                        <button class="btn btn-primary btn-sm mb-2" onclick="loadActiveSessions()">🔄 Refresh</button>
                        <div class="alert alert-info py-2 mb-2"><small>🟢 Shows users active in last 5 minutes</small></div>
                        <div style="max-height:350px; overflow-y:auto;">
                            <table class="table table-sm">
                                <thead class="table-success sticky-top">
                                    <tr><th>User</th><th>Role</th><th>Last Activity</th><th>IP Address</th><th>Status</th></tr>
                                </thead>
                                <tbody id="activeSessionsTable"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- User Details Modal -->
<div class="modal fade" id="userDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">👤 User Details: <span id="detailUserName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="detailUserId">
                
                <div class="card mb-3">
                    <div class="card-header">Account Status</div>
                    <div class="card-body">
                        <div class="d-flex gap-2">
                            <button class="btn btn-success btn-sm" onclick="setUserStatus('active')">✅ Active</button>
                            <button class="btn btn-secondary btn-sm" onclick="setUserStatus('inactive')">⏸️ Inactive</button>
                            <button class="btn btn-warning btn-sm" onclick="unlockUserAccount()">🔓 Unlock</button>
                        </div>
                        <div class="mt-2">
                            <small>Current: <span id="detailStatus" class="badge">-</span></small>
                            <small class="ms-2">Failed Attempts: <span id="detailFailedAttempts">0</span></small>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-3">
                    <div class="card-header">Reset Password</div>
                    <div class="card-body">
                        <div class="input-group">
                            <input type="password" id="resetPassInput" class="form-control" placeholder="New Password">
                            <button class="btn btn-danger" onclick="resetUserPassword()">Reset</button>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-3">
                    <div class="card-header">Daily Target</div>
                    <div class="card-body">
                        <div class="input-group">
                            <input type="number" id="detailTarget" class="form-control" placeholder="100">
                            <button class="btn btn-primary" onclick="setUserTarget()">Set</button>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-3">
                    <div class="card-header">Allowed IPs (comma separated)</div>
                    <div class="card-body">
                        <div class="input-group">
                            <input type="text" id="detailAllowedIPs" class="form-control" placeholder="192.168.1.1, 10.0.0.1">
                            <button class="btn btn-primary" onclick="setUserAllowedIPs()">Set</button>
                        </div>
                        <small class="text-muted">Leave empty to allow all IPs (when IP restriction is enabled)</small>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">Login History</div>
                    <div class="card-body p-0" style="max-height:150px; overflow-y:auto;">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Time</th><th>IP</th><th>Status</th></tr></thead>
                            <tbody id="userLoginHistoryTable"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="assignmentModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-body"><h5>Assign Records</h5><input type="text" id="recordNoStart" class="form-control mb-1" placeholder="Start Record"><input type="text" id="recordNoEnd" class="form-control mb-1" placeholder="End Record"><select id="assignmentUserSelect" class="form-select mb-1"></select><button onclick="assignSelectedRows()" class="btn btn-primary w-100">Assign</button></div></div></div></div>

<!-- Security Modal -->
<div class="modal fade" id="securityModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">🔒 Security Center</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="securityTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabSettings">⚙️ Settings</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabLoginHistory">📋 Login History</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabActivityLogs">📝 Activity Logs</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabIPControl">🌐 IP Control</a></li>
                </ul>
                <div class="tab-content mt-3">
                    <!-- Settings Tab -->
                    <div class="tab-pane fade show active" id="tabSettings">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-header bg-primary text-white">Login Security</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Max Login Attempts</label>
                                            <input type="number" class="form-control" id="setMaxAttempts" min="1" max="10">
                                            <small class="text-muted">Account locks after this many failed attempts</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Lockout Duration (minutes)</label>
                                            <input type="number" class="form-control" id="setLockoutDuration" min="1" max="60">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Session Timeout (minutes)</label>
                                            <input type="number" class="form-control" id="setSessionTimeout" min="5" max="120">
                                            <small class="text-muted">Auto logout after inactivity</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-header bg-warning text-dark">IP Restriction</div>
                                    <div class="card-body">
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" id="setIPRestriction">
                                            <label class="form-check-label">Enable IP-based Access Control</label>
                                        </div>
                                        <div class="alert alert-info py-2">
                                            <small>When enabled, only IPs in whitelist can login.<br>Your current IP: <strong id="currentIP">-</strong></small>
                                        </div>
                                    </div>
                                </div>
                                <button class="btn btn-success w-100" onclick="saveSecuritySettings()">💾 Save Settings</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Login History Tab -->
                    <div class="tab-pane fade" id="tabLoginHistory">
                        <div class="d-flex gap-2 mb-2">
                            <select id="loginHistoryUser" class="form-select" style="width:150px;" onchange="loadLoginHistory()">
                                <option value="">All Users</option>
                            </select>
                            <button class="btn btn-primary btn-sm" onclick="loadLoginHistory()">Refresh</button>
                        </div>
                        <div style="max-height:400px; overflow-y:auto;">
                            <table class="table table-sm table-striped">
                                <thead class="table-dark sticky-top"><tr><th>Time</th><th>User</th><th>IP</th><th>Status</th><th>Reason</th></tr></thead>
                                <tbody id="loginHistoryTable"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Activity Logs Tab -->
                    <div class="tab-pane fade" id="tabActivityLogs">
                        <div class="d-flex gap-2 mb-2">
                            <select id="activityLogUser" class="form-select" style="width:150px;">
                                <option value="">All Users</option>
                            </select>
                            <select id="activityLogAction" class="form-select" style="width:150px;">
                                <option value="">All Actions</option>
                                <option value="login">Login</option>
                                <option value="logout">Logout</option>
                                <option value="update_row">Update Row</option>
                                <option value="update_cell">Update Cell</option>
                                <option value="status_change">Status Change</option>
                            </select>
                            <button class="btn btn-primary btn-sm" onclick="loadActivityLogs()">Refresh</button>
                        </div>
                        <div style="max-height:400px; overflow-y:auto;">
                            <table class="table table-sm table-striped">
                                <thead class="table-dark sticky-top"><tr><th>Time</th><th>User</th><th>Action</th><th>Module</th><th>Record</th><th>IP</th></tr></thead>
                                <tbody id="activityLogTable"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- IP Control Tab -->
                    <div class="tab-pane fade" id="tabIPControl">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-success text-white">Add Allowed IP</div>
                                    <div class="card-body">
                                        <div class="input-group mb-2">
                                            <input type="text" class="form-control" id="newIPAddress" placeholder="IP Address">
                                            <button class="btn btn-outline-primary" onclick="$('#newIPAddress').val($('#currentIP').text())">Use My IP</button>
                                        </div>
                                        <input type="text" class="form-control mb-2" id="newIPDesc" placeholder="Description (e.g. Office, Home)">
                                        <button class="btn btn-success w-100" onclick="addAllowedIP()">Add IP</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-info text-dark">Allowed IPs</div>
                                    <div class="card-body" style="max-height:300px; overflow-y:auto;">
                                        <ul id="allowedIPList" class="list-group"></ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reports Modal -->
<div class="modal fade" id="reportsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">📊 Reports & Analytics</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="reportsTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabDailyReport">📅 Daily</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabWeeklyReport">📆 Weekly</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabMonthlyReport">🗓️ Monthly</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabCharts">📈 Charts</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabAvgTime">⏱️ Avg Time</a></li>
                </ul>
                <div class="tab-content mt-3">
                    <!-- Daily Report Tab -->
                    <div class="tab-pane fade show active" id="tabDailyReport">
                        <div class="d-flex gap-2 mb-3 align-items-center">
                            <input type="date" id="dailyReportDate" class="form-control" style="width:180px;" onchange="loadDailyReport()">
                            <button class="btn btn-primary btn-sm" onclick="loadDailyReport()">Load</button>
                            <button class="btn btn-success btn-sm" onclick="exportReport('daily')">📥 Export CSV</button>
                            <span class="ms-auto badge bg-info" id="dailyOverallStats">-</span>
                        </div>
                        <div style="max-height:400px; overflow-y:auto;">
                            <table class="table table-sm table-striped">
                                <thead class="table-primary sticky-top">
                                    <tr><th>#</th><th>User</th><th>Completed</th><th>Target</th><th>Progress</th><th>Time (min)</th><th>Avg (sec)</th></tr>
                                </thead>
                                <tbody id="dailyReportTable"></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Weekly Report Tab -->
                    <div class="tab-pane fade" id="tabWeeklyReport">
                        <div class="d-flex gap-2 mb-3 align-items-center">
                            <input type="date" id="weeklyStartDate" class="form-control" style="width:150px;">
                            <span>to</span>
                            <input type="date" id="weeklyEndDate" class="form-control" style="width:150px;">
                            <button class="btn btn-primary btn-sm" onclick="loadWeeklyReport()">Load</button>
                            <button class="btn btn-success btn-sm" onclick="exportReport('weekly')">📥 Export CSV</button>
                        </div>
                        <div class="row">
                            <div class="col-md-5">
                                <h6>Daily Breakdown</h6>
                                <div style="max-height:300px; overflow-y:auto;">
                                    <table class="table table-sm">
                                        <thead class="table-info"><tr><th>Date</th><th>Completed</th><th>Time (min)</th></tr></thead>
                                        <tbody id="weeklyDailyTable"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <h6>User Performance</h6>
                                <div style="max-height:300px; overflow-y:auto;">
                                    <table class="table table-sm">
                                        <thead class="table-success"><tr><th>#</th><th>User</th><th>Completed</th><th>Time (min)</th><th>Avg (sec)</th></tr></thead>
                                        <tbody id="weeklyUserTable"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Monthly Report Tab -->
                    <div class="tab-pane fade" id="tabMonthlyReport">
                        <div class="d-flex gap-2 mb-3 align-items-center">
                            <input type="month" id="monthlyReportMonth" class="form-control" style="width:180px;">
                            <button class="btn btn-primary btn-sm" onclick="loadMonthlyReport()">Load</button>
                            <button class="btn btn-success btn-sm" onclick="exportReport('monthly')">📥 Export CSV</button>
                            <span class="ms-auto badge bg-warning text-dark" id="monthlyTotalStats">-</span>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <h6>Weekly Breakdown</h6>
                                <div style="max-height:300px; overflow-y:auto;">
                                    <table class="table table-sm">
                                        <thead class="table-warning"><tr><th>Week</th><th>Completed</th></tr></thead>
                                        <tbody id="monthlyWeeklyTable"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <h6>User Performance</h6>
                                <div style="max-height:300px; overflow-y:auto;">
                                    <table class="table table-sm">
                                        <thead class="table-danger"><tr><th>#</th><th>User</th><th>Completed</th><th>Days</th><th>Daily Avg</th><th>Avg Time</th></tr></thead>
                                        <tbody id="monthlyUserTable"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Charts Tab -->
                    <div class="tab-pane fade" id="tabCharts">
                        <div class="d-flex gap-2 mb-3">
                            <select id="chartType" class="form-select" style="width:150px;" onchange="loadChartData()">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                            <select id="chartUser" class="form-select" style="width:150px;" onchange="loadChartData()">
                                <option value="">All Users</option>
                            </select>
                            <select id="chartDays" class="form-select" style="width:120px;" onchange="loadChartData()">
                                <option value="7">Last 7 days</option>
                                <option value="30" selected>Last 30 days</option>
                                <option value="90">Last 90 days</option>
                            </select>
                            <button class="btn btn-primary btn-sm" onclick="loadChartData()">Refresh</button>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-primary text-white py-1">Records Completed</div>
                                    <div class="card-body p-2">
                                        <canvas id="completedChart" height="200"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-info text-white py-1">Time Spent (minutes)</div>
                                    <div class="card-body p-2">
                                        <canvas id="timeChart" height="200"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Avg Time Tab -->
                    <div class="tab-pane fade" id="tabAvgTime">
                        <button class="btn btn-primary btn-sm mb-3" onclick="loadAvgTimeStats()">🔄 Refresh</button>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-header bg-primary text-white">Overall Average</div>
                                    <div class="card-body">
                                        <h1 id="overallAvgTime">-</h1>
                                        <small>seconds per record</small>
                                    </div>
                                </div>
                                <div class="card mt-3">
                                    <div class="card-header bg-success text-white">🏆 Best Performer</div>
                                    <div class="card-body text-center">
                                        <h4 id="bestPerformerName">-</h4>
                                        <p id="bestPerformerStats" class="mb-0">-</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="card">
                                    <div class="card-header bg-secondary text-white">User Ranking (by Avg Time)</div>
                                    <div class="card-body p-0" style="max-height:350px; overflow-y:auto;">
                                        <table class="table table-sm mb-0">
                                            <thead class="sticky-top table-dark"><tr><th>#</th><th>User</th><th>Avg Time (sec)</th><th>Records</th></tr></thead>
                                            <tbody id="avgTimeRankingTable"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Notifications Modal -->
<div class="modal fade" id="notificationsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">🔔 Notifications Center</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="notifyTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabSendNotify">📤 Send</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabDailySummary">📊 Daily Summary</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabNotifySettings">⚙️ Settings</a></li>
                </ul>
                <div class="tab-content mt-3">
                    <!-- Send Notification Tab -->
                    <div class="tab-pane fade show active" id="tabSendNotify">
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">Custom Notification</div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label">Send To</label>
                                        <select id="notifyTarget" class="form-select" onchange="toggleNotifyFields()">
                                            <option value="all">All Users</option>
                                            <option value="user">Specific User</option>
                                            <option value="phone">Phone Number</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4" id="notifyUserField" style="display:none;">
                                        <label class="form-label">Select User</label>
                                        <select id="notifyUser" class="form-select"></select>
                                    </div>
                                    <div class="col-md-4" id="notifyPhoneField" style="display:none;">
                                        <label class="form-label">Phone Number</label>
                                        <input type="text" id="notifyPhone" class="form-control" placeholder="91XXXXXXXXXX">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Title</label>
                                        <input type="text" id="notifyTitle" class="form-control" placeholder="Notification Title">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Message</label>
                                        <textarea id="notifyBody" class="form-control" rows="3" placeholder="Enter your message here..."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-primary w-100" onclick="sendCustomNotification()">📤 Send Notification</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-warning text-dark">Quick Actions</div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <button class="btn btn-outline-success w-100" onclick="sendAdminSummary()">📊 Send Admin Summary</button>
                                    </div>
                                    <div class="col-md-4">
                                        <select id="lowProdUser" class="form-select"></select>
                                    </div>
                                    <div class="col-md-4">
                                        <button class="btn btn-outline-danger w-100" onclick="sendLowProductivityAlert()">⚠️ Low Productivity Alert</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Daily Summary Tab -->
                    <div class="tab-pane fade" id="tabDailySummary">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-success text-white">Send to Single User</div>
                                    <div class="card-body">
                                        <div class="mb-2">
                                            <label class="form-label">Select User</label>
                                            <select id="summaryUser" class="form-select"></select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Date</label>
                                            <input type="date" id="summaryDate" class="form-control">
                                        </div>
                                        <button class="btn btn-success w-100" onclick="sendDailySummary()">📤 Send Summary</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">Send to All Users</div>
                                    <div class="card-body">
                                        <div class="mb-2">
                                            <label class="form-label">Date</label>
                                            <input type="date" id="summaryDateAll" class="form-control">
                                        </div>
                                        <div class="alert alert-warning py-2">
                                            <small>⚠️ This will send WhatsApp messages to ALL users with phone numbers.</small>
                                        </div>
                                        <button class="btn btn-primary w-100" onclick="sendDailySummaryAll()">📤 Send to All Users</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Settings Tab -->
                    <div class="tab-pane fade" id="tabNotifySettings">
                        <div class="card">
                            <div class="card-header bg-secondary text-white">Notification Settings</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="setAutoDailySummary">
                                            <label class="form-check-label">Auto Daily Summary (End of Day)</label>
                                        </div>
                                        <small class="text-muted">Automatically send daily summary to all users at end of day</small>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="setAutoTargetNotify">
                                            <label class="form-check-label">Target Completion Notification</label>
                                        </div>
                                        <small class="text-muted">Notify user when they complete their daily target</small>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="setAdminDailySummary">
                                            <label class="form-check-label">Admin Daily Summary</label>
                                        </div>
                                        <small class="text-muted">Send daily summary report to admin</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Low Productivity Threshold (%)</label>
                                        <input type="number" id="setLowProdThreshold" class="form-control" min="10" max="90" value="30">
                                        <small class="text-muted">Alert admin if user is below this % of target by midday</small>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-success" onclick="saveNotificationSettings()">💾 Save Settings</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Data Management Modal -->
<div class="modal fade" id="dataModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title">📁 Data Management</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="dataTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabUpload">📤 Upload</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabDataStats">📊 Statistics</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabBulkDelete">🗑️ Bulk Delete</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabBackup">💾 Backup/Restore</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabDuplicates">🔍 Duplicates</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabExport">📤 Export</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabImageLookup">🖼️ Image Lookup</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabBulkReport">⚠️ Bulk Report</a></li>
                </ul>
                <div class="tab-content mt-3">
                    <!-- Upload Tab -->
                    <div class="tab-pane fade show active" id="tabUpload">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card border-primary">
    <div class="card-header bg-primary text-white">📥 Main Upload (Add/Merge Data)</div>
    <div class="card-body">
        <p class="text-muted small">ℹ️ New records add honge. Duplicate Record No skip ho jayenge (Purana data safe rahega).</p>
        
        <!-- NEW: Auto-Assignment Feature -->
        <div class="mb-3 p-3" style="background:#fff3cd; border-radius:6px; border:1px solid #ffc107;">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="enableAutoAssign" onchange="toggleAutoAssign()">
                <label class="form-check-label fw-bold" for="enableAutoAssign">
                    ⚡ Enable Auto Equal Distribution to Selected DEOs
                </label>
            </div>
            
            <div id="deoSelectionDiv" style="display:none;" class="mt-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">Select DEOs for automatic distribution:</small>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllDEOs(true)">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllDEOs(false)">Clear</button>
                    </div>
                </div>
                <div id="deoCheckboxList" style="max-height:180px; overflow-y:auto; border:1px solid #ddd; padding:10px; border-radius:4px; background:white;">
                    <p class="text-muted text-center">Loading DEOs...</p>
                </div>
                <div id="assignmentPreview" class="mt-2 alert alert-info" style="display:none; font-size:0.85rem;">
                    <!-- Will show distribution preview -->
                </div>
            </div>
        </div>
        
        <div class="input-group">
            <input type="file" class="form-control" id="mainExcel" accept=".xlsx,.xls,.csv" onchange="previewAssignment()">
            <button class="btn btn-danger" onclick="uploadMainExcel()">📤 Upload</button>
        </div>
        <div class="mt-2">
            <button class="btn btn-outline-danger btn-sm" onclick="clearMainData()">🗑️ Clear All Data</button>
        </div>
    </div>
</div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-info">
                                    <div class="card-header bg-info text-white">📝 Update Upload (Update Pending Only)</div>
                                    <div class="card-body">
                                        <p class="text-muted small">This will UPDATE only pending records. Completed records will not be affected.</p>
                                        <div class="input-group">
                                            <input type="file" class="form-control" id="updateExcel" accept=".xlsx,.xls,.csv">
                                            <button class="btn btn-info" onclick="uploadUpdateExcel()">📤 Upload</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- NEW: Field-wise Import -->
                            <div class="col-md-6">
                                <div class="card border-warning">
                                    <div class="card-header bg-warning text-dark">📋 Import Specific Field Data</div>
                                    <div class="card-body">
                                        <p class="text-muted small">⚠️ Excel mein <b>Record No</b> + koi bhi field column hona chahiye. Record No se match karke selected field update hoga.</p>
                                        
                                        <div class="mb-2">
                                            <input type="file" class="form-control form-control-sm" id="fieldImportExcel" accept=".xlsx,.xls,.csv" onchange="previewFieldImport()">
                                        </div>
                                        
                                        <div id="fieldImportPreview" style="display:none;">
                                            <div class="row g-2 mb-2">
                                                <div class="col-6">
                                                    <label class="form-label small fw-bold">Record No Column:</label>
                                                    <select id="recordNoColumn" class="form-select form-select-sm"></select>
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label small fw-bold">Data Column to Import:</label>
                                                    <select id="dataColumn" class="form-select form-select-sm"></select>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <label class="form-label small fw-bold">Map to Database Field:</label>
                                                <select id="targetDbField" class="form-select form-select-sm">
                                                    <option value="">-- Select Target Field --</option>
                                                    <option value="kyc_number">KYC Number</option>
                                                    <option value="name">Name</option>
                                                    <option value="guardian_name">Guardian Name</option>
                                                    <option value="gender">Gender</option>
                                                    <option value="marital_status">Marital Status</option>
                                                    <option value="dob">DOB</option>
                                                    <option value="address">Address</option>
                                                    <option value="landmark">Landmark</option>
                                                    <option value="city">City</option>
                                                    <option value="zip_code">Zip Code</option>
                                                    <option value="city_of_birth">City of Birth</option>
                                                    <option value="nationality">Nationality</option>
                                                    <option value="photo_attachment">Photo Attachment</option>
                                                    <option value="residential_status">Residential Status</option>
                                                    <option value="occupation">Occupation</option>
                                                    <option value="officially_valid_documents">OVD (Officially Valid Documents)</option>
                                                    <option value="annual_income">Annual Income</option>
                                                    <option value="broker_name">Broker Name</option>
                                                    <option value="sub_broker_code">Sub Broker Code</option>
                                                    <option value="bank_serial_no">Bank Serial No</option>
                                                    <option value="second_applicant_name">2nd Applicant Name</option>
                                                    <option value="amount_received_from">Amount Received From</option>
                                                    <option value="amount">Amount</option>
                                                    <option value="arn_no">ARN No</option>
                                                    <option value="second_address">Second Address</option>
                                                    <option value="occupation_profession">Occupation/Profession</option>
                                                    <option value="remarks">Remarks</option>
                                                </select>
                                            </div>
                                            
                                            <div class="alert alert-info py-1 small" id="fieldImportInfo">
                                                <span id="fieldImportCount">0</span> records found
                                            </div>
                                            
                                            <button class="btn btn-warning w-100" onclick="uploadFieldImport()">📤 Import Selected Field</button>
                                        </div>
                                        
                                        <div id="fieldImportProgress" class="mt-2" style="display:none;">
                                            <div class="progress">
                                                <div class="progress-bar bg-warning" id="fieldProgressBar" style="width:0%">0%</div>
                                            </div>
                                            <small id="fieldProgressText" class="text-muted">Processing...</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="card border-primary">
                                    <div class="card-header bg-primary text-white">🖼️ Image Upload</div>
                                    <div class="card-body">
                                        <p class="text-muted small">Upload images for records. Image filename should match Record No (e.g., REC001.jpg)</p>
                                        <div class="input-group">
                                            <input type="file" class="form-control" id="imageUpload" multiple accept="image/*">
                                            <button class="btn btn-primary" onclick="uploadImages()">📤 Upload Images</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistics Tab -->
                    <div class="tab-pane fade" id="tabDataStats">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="card text-center bg-primary text-white">
                                    <div class="card-body py-3">
                                        <h3 id="statTotalRecords">0</h3>
                                        <small>Total Records</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-warning text-dark">
                                    <div class="card-body py-3">
                                        <h3 id="statPending">0</h3>
                                        <small>Pending</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-success text-white">
                                    <div class="card-body py-3">
                                        <h3 id="statDone">0</h3>
                                        <small>Done</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-danger text-white">
                                    <div class="card-body py-3">
                                        <h3 id="statCompleted">0</h3>
                                        <small>Completed</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">📷 Images</div>
                                    <div class="card-body text-center">
                                        <h4 id="statWithImages">0</h4>
                                        <small>Records with Images</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">💾 Database Size</div>
                                    <div class="card-body text-center">
                                        <h4 id="statDbSize">0 MB</h4>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">👥 Top Users</div>
                                    <div class="card-body p-0" style="max-height:150px; overflow-y:auto;">
                                        <table class="table table-sm mb-0">
                                            <tbody id="statTopUsers"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-primary mt-3" onclick="loadDataStats()">🔄 Refresh Stats</button>
                    </div>
                    
                    <!-- Bulk Delete Tab -->
                    <div class="tab-pane fade" id="tabBulkDelete">
                        <div class="alert alert-danger">⚠️ <strong>Warning:</strong> Deleted data cannot be recovered. Make a backup first!</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-warning text-dark">Delete by Status</div>
                                    <div class="card-body">
                                        <select id="deleteStatus" class="form-select mb-2">
                                            <option value="">Select Status</option>
                                            <option value="pending">Pending</option>
                                            <option value="done">Done</option>
                                            <option value="Completed">Completed</option>
                                        </select>
                                        <button class="btn btn-danger w-100" onclick="bulkDeleteByStatus()">🗑️ Delete by Status</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-danger text-white">Delete by Date Range</div>
                                    <div class="card-body">
                                        <div class="d-flex gap-2 mb-2">
                                            <input type="date" id="deleteStartDate" class="form-control">
                                            <input type="date" id="deleteEndDate" class="form-control">
                                        </div>
                                        <button class="btn btn-danger w-100" onclick="bulkDeleteByDate()">🗑️ Delete by Date</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-header bg-dark text-white">⚠️ Delete Selected Records</div>
                                    <div class="card-body">
                                        <p class="mb-2">Selected records: <strong id="deleteSelectedCount">0</strong></p>
                                        <button class="btn btn-danger w-100" onclick="bulkDeleteSelected()">🗑️ Delete Selected</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-header bg-danger text-white">☢️ DELETE ALL DATA</div>
                                    <div class="card-body">
                                        <p class="text-danger mb-2"><strong>This will delete ALL records permanently!</strong></p>
                                        <button class="btn btn-outline-danger w-100" onclick="bulkDeleteAll()">☢️ DELETE EVERYTHING</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Backup/Restore Tab -->
                    <div class="tab-pane fade" id="tabBackup">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-success text-white">💾 Create Backup</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">What to backup?</label>
                                            <select id="backupTables" class="form-select">
                                                <option value="all">All Tables</option>
                                                <option value="records">Records Only</option>
                                                <option value="users">Users Only</option>
                                            </select>
                                        </div>
                                        <button class="btn btn-success w-100" onclick="createBackup()">💾 Download Backup (SQL)</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-warning text-dark">📥 Restore Backup</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Select SQL File</label>
                                            <input type="file" id="restoreFile" class="form-control" accept=".sql">
                                        </div>
                                        <div class="alert alert-warning py-2">
                                            <small>⚠️ This will overwrite existing data!</small>
                                        </div>
                                        <button class="btn btn-warning w-100" onclick="restoreBackup()">📥 Restore Backup</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Duplicates Tab -->
                    <div class="tab-pane fade" id="tabDuplicates">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">Check Duplicates</div>
                                    <div class="card-body">
                                        <select id="duplicateField" class="form-select mb-2">
                                            <option value="record_no">Record No</option>
                                            <option value="kyc_number">KYC Number</option>
                                            <option value="name">Name</option>
                                        </select>
                                        <button class="btn btn-primary w-100 mb-2" onclick="checkDuplicates()">🔍 Find Duplicates</button>
                                        <button class="btn btn-danger w-100" onclick="deleteDuplicates()">🗑️ Delete Duplicates (Keep First)</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="card">
                                    <div class="card-header">Duplicate Records <span class="badge bg-warning" id="duplicateCount">0</span></div>
                                    <div class="card-body p-0" style="max-height:350px; overflow-y:auto;">
                                        <table class="table table-sm mb-0">
                                            <thead class="sticky-top table-dark"><tr><th>Value</th><th>Count</th><th>IDs</th></tr></thead>
                                            <tbody id="duplicateTable"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Export Tab -->
                    <div class="tab-pane fade" id="tabExport">
                        <div class="card">
                            <div class="card-header bg-info text-white">📤 Export Records to CSV</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select id="exportStatus" class="form-select">
                                            <option value="">All</option>
                                            <option value="pending">Pending</option>
                                            <option value="done">Done</option>
                                            <option value="Completed">Completed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">User</label>
                                        <select id="exportUser" class="form-select"></select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" id="exportStartDate" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">End Date</label>
                                        <input type="date" id="exportEndDate" class="form-control">
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-info w-100" onclick="exportRecords()">📤 Export to CSV</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Image Lookup Tab -->
                    <div class="tab-pane fade" id="tabImageLookup">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white">
                                <i class="fas fa-images me-2"></i>🖼️ Record Number se Image Name Lookup
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <strong>📋 Instructions:</strong><br>
                                    1. Excel file upload karo jisme <strong>Column A</strong> me Record Numbers hon<br>
                                    2. System automatically <strong>Column B</strong> me Image Names fill kar dega<br>
                                    3. Updated Excel download ho jayega
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">📁 Excel File Select Karo (.xlsx, .xls)</label>
                                        <input type="file" id="imageLookupFile" class="form-control" accept=".xlsx,.xls">
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end">
                                        <button class="btn btn-success w-100" onclick="processImageLookup()">
                                            <i class="fas fa-search me-2"></i>🔍 Process & Download
                                        </button>
                                    </div>
                                </div>
                                
                                <div id="imageLookupStatus" class="mt-3" style="display:none;">
                                    <div class="progress" style="height: 25px;">
                                        <div id="imageLookupProgress" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 0%">0%</div>
                                    </div>
                                    <p id="imageLookupMessage" class="mt-2 text-center fw-bold"></p>
                                </div>
                                
                                <div id="imageLookupResult" class="mt-3" style="display:none;">
                                    <div class="alert alert-success">
                                        <h5>✅ Processing Complete!</h5>
                                        <p><strong>Total Records:</strong> <span id="lookupTotal">0</span></p>
                                        <p><strong>Found:</strong> <span id="lookupFound" class="text-success">0</span></p>
                                        <p><strong>Not Found:</strong> <span id="lookupNotFound" class="text-danger">0</span></p>
                                    </div>
                                </div>

                                <hr class="my-4">
                                
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6>📥 Sample Format:</h6>
                                        <table class="table table-bordered table-sm">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Column A (Record No)</th>
                                                    <th>Column B (Image Name) - Auto Fill</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr><td>1001</td><td style="color:green;">→ SWQRCDIMG_4AQ1_enc.jpg</td></tr>
                                                <tr><td>1002</td><td style="color:green;">→ SWQRCDIMG_4AQ2_enc.jpg</td></tr>
                                                <tr><td>1003</td><td style="color:red;">→ NOT FOUND</td></tr>
                                            </tbody>
                                        </table>
                                        <button class="btn btn-outline-primary btn-sm" onclick="downloadSampleLookup()">
                                            📥 Download Sample Excel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bulk Report to Admin Tab -->
                    <div class="tab-pane fade" id="tabBulkReport">
                        <div class="card border-warning">
                            <div class="card-header bg-warning text-dark">
                                <strong>⚠️ Bulk Report to Admin — Excel Upload</strong>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-3">
                                    <strong>📋 Instructions:</strong><br>
                                    1. Sample Excel download karo neeche se<br>
                                    2. Sirf <strong>Record_No</strong>, <strong>Header_Name</strong>, <strong>Issue_Details</strong> fill karo<br>
                                    3. <strong>Reported_By</strong>, <strong>Reporter_Role</strong> aur <strong>Image_No</strong> — teeno auto-fetch honge record number se<br>
                                    4. Excel upload karo → Preview dekho → Submit karo
                                </div>
                                
                                <div class="row g-3 mb-3">
                                    <div class="col-md-5">
                                        <label class="form-label fw-bold">📁 Excel File (.xlsx / .xls)</label>
                                        <input type="file" id="bulkReportFile" class="form-control" accept=".xlsx,.xls">
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end gap-2">
                                        <button class="btn btn-warning w-100" onclick="processBulkReportFile()">
                                            📤 Preview Records
                                        </button>
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <button class="btn btn-outline-secondary w-100" onclick="downloadBulkReportSample()">
                                            📥 Sample Excel
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Preview Table -->
                                <div id="bulkReportPreviewDiv" style="display:none;">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">📋 Preview — <span id="bulkReportCount">0</span> Records</h6>
                                        <button class="btn btn-danger" onclick="submitBulkReport()" id="btnSubmitBulkReport">
                                            ⚠️ Submit All Reports
                                        </button>
                                    </div>
                                    <div style="max-height:350px;overflow-y:auto;">
                                        <table class="table table-sm table-bordered table-hover">
                                            <thead class="table-dark sticky-top">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Record No</th>
                                                    <th>Reported By</th>
                                                    <th>Role</th>
                                                    <th>Image No (Auto)</th>
                                                    <th>Header / Field</th>
                                                    <th>Issue Details</th>
                                                </tr>
                                            </thead>
                                            <tbody id="bulkReportPreviewTbody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- Result -->
                                <div id="bulkReportResultDiv" style="display:none;" class="mt-3"></div>
                                
                                <hr>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6>📋 Excel Format:</h6>
                                        <table class="table table-bordered table-sm">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Record_No ✏️</th>
                                                    <th>Header_Name ✏️</th>
                                                    <th>Issue_Details ✏️</th>
                                                    <th>Reported_By 🤖 Auto</th>
                                                    <th>Role 🤖 Auto</th>
                                                    <th>Image_No 🤖 Auto</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>1001</td>
                                                    <td>Name</td>
                                                    <td>Name galat hai</td>
                                                    <td style="color:green;"><em>→ Rahul Kumar</em></td>
                                                    <td style="color:green;"><em>→ deo</em></td>
                                                    <td style="color:green;"><em>→ IMG_001.jpg</em></td>
                                                </tr>
                                                <tr>
                                                    <td>1002</td>
                                                    <td>DOB</td>
                                                    <td>Date of birth incorrect</td>
                                                    <td style="color:green;"><em>→ Priya QC</em></td>
                                                    <td style="color:green;"><em>→ qc</em></td>
                                                    <td style="color:green;"><em>→ IMG_002.jpg</em></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- System Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">⚙️ System Settings</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="settingsTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabGeneralSettings">🏢 General</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabDisplaySettings">🎨 Display</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabSystemInfo">💻 System Info</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabMaintenance">🔧 Maintenance</a></li>
                </ul>
                <div class="tab-content mt-3">
                    <!-- General Settings Tab -->
                    <div class="tab-pane fade show active" id="tabGeneralSettings">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">Company Settings</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Company Name</label>
                                            <input type="text" id="setCompanyName" class="form-control" placeholder="BPO Dashboard">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Default Daily Target</label>
                                            <input type="number" id="setDefaultTarget" class="form-control" value="100">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Records Per Page</label>
                                            <select id="setRecordsPerPage" class="form-select" onchange="applyRecordsPerPage(this.value)">
                                                <option value="999999" selected>All Records</option>
                                                <option value="25">25</option>
                                                <option value="50">50</option>
                                                <option value="100">100</option>
                                                <option value="200">200</option>
                                                <option value="500">500</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-success text-white">Working Hours</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Start Time</label>
                                            <input type="time" id="setWorkStart" class="form-control" value="09:00">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">End Time</label>
                                            <input type="time" id="setWorkEnd" class="form-control" value="18:00">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Timezone</label>
                                            <select id="setTimezone" class="form-select">
                                                <option value="Asia/Kolkata">Asia/Kolkata (IST)</option>
                                                <option value="UTC">UTC</option>
                                                <option value="America/New_York">America/New_York (EST)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-info text-white">Notifications</div>
                                    <div class="card-body">
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" id="setWhatsappEnabled" checked>
                                            <label class="form-check-label">WhatsApp Notifications</label>
                                        </div>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" id="setEmailEnabled">
                                            <label class="form-check-label">Email Notifications</label>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="setAutoLogout" checked>
                                            <label class="form-check-label">Auto Logout on Inactivity</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-warning text-dark">Features</div>
                                    <div class="card-body">
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" id="setOcrEnabled" checked>
                                            <label class="form-check-label">Enable OCR</label>
                                        </div>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" id="setImageEditing" checked>
                                            <label class="form-check-label">Enable Image Editing</label>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="setShowCompleted" checked>
                                            <label class="form-check-label">Show Completed Records</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Master OTP Section -->
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-header bg-danger text-white">🔐 Master OTP (Bypass Login)</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Master OTP Code</label>
                                            <div class="input-group">
                                                <input type="text" id="setMasterOtp" class="form-control" placeholder="Set 6-digit OTP" maxlength="6">
                                                <button class="btn btn-outline-secondary" type="button" onclick="generateRandomOtp()">🎲 Generate</button>
                                            </div>
                                            <small class="text-muted">This OTP can bypass DEO login when WhatsApp OTP fails</small>
                                        </div>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" id="setMasterOtpEnabled">
                                            <label class="form-check-label">Enable Master OTP</label>
                                        </div>
                                        <button class="btn btn-danger btn-sm" onclick="saveMasterOtp()">💾 Save Master OTP</button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- WhatsApp API Settings -->
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white">📱 WhatsApp API Settings</div>
                                    <div class="card-body">
                                        <div class="mb-2">
                                            <label class="form-label">API URL</label>
                                            <input type="text" id="setWhatsappApiUrl" class="form-control form-control-sm" placeholder="https://api.example.com/send">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">API Key / Token</label>
                                            <input type="text" id="setWhatsappApiKey" class="form-control form-control-sm" placeholder="your-api-key">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Instance ID (if required)</label>
                                            <input type="text" id="setWhatsappInstanceId" class="form-control form-control-sm" placeholder="instance-id">
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-success btn-sm" onclick="saveWhatsappSettings()">💾 Save</button>
                                            <button class="btn btn-info btn-sm" onclick="testWhatsappApi()">🧪 Test API</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <button class="btn btn-primary" onclick="saveGeneralSettings()">💾 Save General Settings</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Display Settings Tab -->
                    <div class="tab-pane fade" id="tabDisplaySettings">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">Theme Color</div>
                                    <div class="card-body">
                                        <div class="d-flex gap-2 mb-3">
                                            <button class="btn btn-primary" onclick="setThemeColor('#0d6efd')">Blue</button>
                                            <button class="btn btn-success" onclick="setThemeColor('#198754')">Green</button>
                                            <button class="btn btn-danger" onclick="setThemeColor('#dc3545')">Red</button>
                                            <button class="btn btn-warning" onclick="setThemeColor('#ffc107')">Yellow</button>
                                            <button class="btn btn-info" onclick="setThemeColor('#0dcaf0')">Cyan</button>
                                            <button class="btn btn-secondary" onclick="setThemeColor('#6c757d')">Gray</button>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Custom Color</label>
                                            <input type="color" id="setThemeColorPicker" class="form-control form-control-color" value="#0d6efd">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">Date & Time Format</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Date Format</label>
                                            <select id="setDateFormat" class="form-select">
                                                <option value="d-m-Y">DD-MM-YYYY (01-01-2026)</option>
                                                <option value="m-d-Y">MM-DD-YYYY (01-01-2026)</option>
                                                <option value="Y-m-d">YYYY-MM-DD (2026-01-01)</option>
                                                <option value="d/m/Y">DD/MM/YYYY (01/01/2026)</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Time Format</label>
                                            <select id="setTimeFormat" class="form-select">
                                                <option value="h:i A">12 Hour (02:30 PM)</option>
                                                <option value="H:i">24 Hour (14:30)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">Auto Refresh</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Auto Refresh Interval (seconds)</label>
                                            <select id="setAutoRefresh" class="form-select">
                                                <option value="0">Disabled</option>
                                                <option value="30">30 seconds</option>
                                                <option value="60" selected>1 minute</option>
                                                <option value="120">2 minutes</option>
                                                <option value="300">5 minutes</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" onclick="saveDisplaySettings()">💾 Save Display Settings</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System Info Tab -->
                    <div class="tab-pane fade" id="tabSystemInfo">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-dark text-white">Server Information</div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr><td>PHP Version</td><td id="infoPhp">-</td></tr>
                                            <tr><td>MySQL Version</td><td id="infoMysql">-</td></tr>
                                            <tr><td>Server Software</td><td id="infoServer">-</td></tr>
                                            <tr><td>Server Time</td><td id="infoTime">-</td></tr>
                                            <tr><td>Timezone</td><td id="infoTimezone">-</td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-info text-white">Resources</div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr><td>Max Upload Size</td><td id="infoUpload">-</td></tr>
                                            <tr><td>Max POST Size</td><td id="infoPost">-</td></tr>
                                            <tr><td>Memory Limit</td><td id="infoMemory">-</td></tr>
                                            <tr><td>Disk Free</td><td id="infoDiskFree">-</td></tr>
                                            <tr><td>Disk Total</td><td id="infoDiskTotal">-</td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h3 id="infoUsers">0</h3>
                                        <small>Total Users</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h3 id="infoRecords">0</h3>
                                        <small>Total Records</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h3 id="infoLogs">0</h3>
                                        <small>Activity Logs</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" onclick="loadSystemInfo()">🔄 Refresh Info</button>
                                <button class="btn btn-info" onclick="checkForUpdates()">🔍 Check for Updates</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Maintenance Tab -->
                    <div class="tab-pane fade" id="tabMaintenance">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-danger text-white">🚧 Maintenance Mode</div>
                                    <div class="card-body">
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" id="setMaintenanceMode">
                                            <label class="form-check-label"><strong>Enable Maintenance Mode</strong></label>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Maintenance Message</label>
                                            <textarea id="setMaintenanceMsg" class="form-control" rows="3">System is under maintenance. Please try again later.</textarea>
                                        </div>
                                        <button class="btn btn-danger w-100" onclick="toggleMaintenanceMode()">Apply Maintenance Mode</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-warning text-dark">🧹 Clean Old Logs</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Delete logs older than (days)</label>
                                            <input type="number" id="cleanLogsDays" class="form-control" value="90" min="7" max="365">
                                        </div>
                                        <button class="btn btn-warning w-100" onclick="cleanOldLogs()">🧹 Clean Old Logs</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-info text-white">📦 Data Retention</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Keep completed records for (days)</label>
                                            <select id="setDataRetention" class="form-select">
                                                <option value="30">30 days</option>
                                                <option value="90">90 days</option>
                                                <option value="180">180 days</option>
                                                <option value="365" selected>1 year</option>
                                                <option value="0">Forever</option>
                                            </select>
                                        </div>
                                        <button class="btn btn-info btn-sm" onclick="saveDataRetention()">💾 Save</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-success text-white">📋 Version Info</div>
                                    <div class="card-body text-center">
                                        <h4>BPO Dashboard</h4>
                                        <p class="mb-1">Version: <strong id="appVersion">2.0.0</strong></p>
                                        <p class="mb-0 text-muted">All Phases Complete</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Database Settings Modal -->
<div class="modal fade" id="dbSettingsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">🗄️ Database Settings & Table Management</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Nav Tabs -->
                <ul class="nav nav-tabs mb-3" id="dbSettingsTabs">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#tab-connection">🔌 Connection</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tab-tables">📋 Tables</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tab-views">👁️ Views & Triggers</a>
                    </li>
                </ul>
                
                <div class="tab-content">
                    <!-- Connection Tab -->
                    <div class="tab-pane fade show active" id="tab-connection">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Warning:</strong> Changing database settings incorrectly can break the application. Only modify if you know what you're doing.
                        </div>
                        
                        <form id="dbSettingsForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Database Host</label>
                                    <input type="text" class="form-control" id="db_host" value="localhost" required>
                                    <small class="text-muted">Usually "localhost" or "127.0.0.1"</small>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Database Name</label>
                                    <input type="text" class="form-control" id="db_name" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Database Username</label>
                                    <input type="text" class="form-control" id="db_user" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Database Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="db_pass">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('db_pass')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Leave empty to keep current password</small>
                                </div>
                                
                                <div class="col-md-12">
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="button" class="btn btn-info" onclick="testDbConnection()">
                                            <i class="fas fa-plug"></i> Test Connection
                                        </button>
                                        <button type="button" class="btn btn-primary" onclick="saveDbSettings()">
                                            <i class="fas fa-save"></i> Save Configuration
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                        
                        <div id="dbConnectionResult" class="mt-3"></div>
                    </div>
                    
                    <!-- Tables Tab -->
                    <div class="tab-pane fade" id="tab-tables">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="fas fa-table"></i> Database Tables (Project 1 + Project 2)</h6>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-success btn-sm" onclick="createAllTables()">
                                    <i class="fas fa-plus-circle"></i> Create Missing Tables
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" onclick="checkTables()">
                                    <i class="fas fa-sync"></i> Refresh Status
                                </button>
                            </div>
                        </div>
                        
                        <div id="dbTablesResult">
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-info-circle"></i> Click "Refresh Status" to check tables
                            </div>
                        </div>
                    </div>
                    
                    <!-- Views & Triggers Tab -->
                    <div class="tab-pane fade" id="tab-views">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-info text-white">
                                        <i class="fas fa-eye"></i> Database Views
                                    </div>
                                    <div class="card-body">
                                        <p class="small text-muted">Views provide simplified access to data for Project 2 compatibility.</p>
                                        <ul class="list-unstyled small">
                                            <li><code>main_data</code> - Client records view for P1</li>
                                            <li><code>records</code> - Records view for P2</li>
                                        </ul>
                                        <button type="button" class="btn btn-info btn-sm" onclick="createViews()">
                                            <i class="fas fa-plus"></i> Create/Update Views
                                        </button>
                                        <div id="viewsResult" class="mt-2"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-warning text-dark">
                                        <i class="fas fa-bolt"></i> Database Triggers
                                    </div>
                                    <div class="card-body">
                                        <p class="small text-muted">Triggers automate actions when records change.</p>
                                        <ul class="list-unstyled small">
                                            <li><code>auto_create_work_log</code> - Auto log completed work</li>
                                            <li><code>sync_assigned_to_id</code> - Sync user ID on update</li>
                                            <li><code>sync_assigned_to_id_insert</code> - Sync on insert</li>
                                        </ul>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="createTriggers()">
                                            <i class="fas fa-plus"></i> Create/Update Triggers
                                        </button>
                                        <div id="triggersResult" class="mt-2"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Note:</strong> Views and Triggers require the <code>client_records</code> and <code>users</code> tables to exist first. Create tables before creating views/triggers.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Database Settings Modal Styles */
#dbSettingsModal .table-status-grid {
    max-height: 400px;
    overflow-y: auto;
}
#dbSettingsModal .table-sm td, #dbSettingsModal .table-sm th {
    padding: 0.4rem;
    font-size: 12px;
}
#dbSettingsModal .badge {
    font-size: 11px;
}
#dbSettingsModal .nav-tabs .nav-link {
    font-size: 13px;
    padding: 8px 16px;
}
#dbSettingsModal .card-header {
    padding: 8px 12px;
    font-size: 13px;
}
</style>

<div id="validationTooltip"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    let currentUser = null, allClientData = [], filteredData = [];
    let currentPage = 1, itemsPerPage = 999999; // Default: All records show
    let currentScale = 1.4, currentRot = 0, initialW = 0, initialH = 0;
    let ocrWorker = null, ocrData = null, filterB=100, filterC=100, isInverted=false, isMagnifierActive=false;
    let activeRowId = null, activeTimer = null;
    let syncInterval;
    let isImageLocked = false;
    let completedChart = null, timeChart = null; // Chart instances
    
    // --- FIX: PERSISTENT SELECTION SET ---
    let selectedIds = new Set(); 

    $(document).ready(()=>checkSession());

    function checkSession() {
        if(localStorage.getItem('theme')==='dark') document.body.classList.add('dark-mode');
        $.post('api.php', {action:'check_session'}, function(res){
            if(res.status==='logged_in'){
                if(res.role !== 'admin') { window.location.href = 'first_qc_dashboard.php'; return; }
                currentUser = res;
                $('#mainContainer').fadeIn().css('display','flex');
                $('#userBadge').text(res.username.toUpperCase());
                loadUsersForDropdown();
                loadData();
                loadQCStatus();
                startRealtimeSync(); 
            } else window.location.href = 'login.php';
        },'json');
    }
    
    function logout() { $.post('api.php', {action:'logout'}, ()=>location.reload()); }
    function toggleTheme(){ document.body.classList.toggle('dark-mode'); localStorage.setItem('theme',document.body.classList.contains('dark-mode')?'dark':'light'); }

    // --- HEARTBEAT (Track Online Status) ---
    function sendHeartbeat() {
        $.post('api.php', {action: 'heartbeat'}, function(res) {
            // Heartbeat sent successfully
        }, 'json');
    }
    
    // Track already notified record IDs to avoid duplicate notifications
    let notifiedChanges = new Set();

    // --- REALTIME SYNC (ADMIN) ---
    function startRealtimeSync() {
        let lastSyncTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
        
        // Send heartbeat immediately and every 30 seconds
        sendHeartbeat();
        setInterval(sendHeartbeat, 30000);
        
        // Fast sync every 3 seconds
        syncInterval = setInterval(() => {
            $.post('api.php', {action: 'sync_changes', last_sync: lastSyncTime}, function(res) {
                if(res.status === 'success') {
                    lastSyncTime = res.server_time;
                    
                    // Update stats immediately
                    if(res.stats) {
                        $('#totalPendingCount').text(res.stats.pending || 0);
                        $('#totalCompletedCount').text(res.stats.completed || 0);
                        $('#todayCompletedCount').text(res.stats.today_completed || 0);
                        $('#availableDoneCount').text(res.stats.done || 0);
                    }
                    
                    // If there are changes, update affected rows
                    if(res.changes && res.changes.length > 0) {
                        let newChanges = [];
                        
                        res.changes.forEach(changedRecord => {
                            // Create unique key for this change
                            let changeKey = `${changedRecord.id}-${changedRecord.updated_at}`;
                            
                            // Only process if we haven't seen this exact change before
                            if(!notifiedChanges.has(changeKey)) {
                                notifiedChanges.add(changeKey);
                                newChanges.push(changedRecord);
                                
                                // Update in allClientData
                                let idx = allClientData.findIndex(r => r.id == changedRecord.id);
                                if(idx >= 0) {
                                    allClientData[idx] = changedRecord;
                                } else {
                                    allClientData.push(changedRecord);
                                }
                                
                                // Update row in table if visible
                                let $row = $(`#dataTable tbody tr[data-id="${changedRecord.id}"]`);
                                if($row.length) {
                                    updateRowDisplay($row, changedRecord);
                                }
                            }
                        });
                        
                        // Keep notifiedChanges set from growing too large
                        if(notifiedChanges.size > 1000) {
                            notifiedChanges.clear();
                        }
                        
                        // Show notification only for NEW changes by others
                        let otherChanges = newChanges.filter(r => r.last_updated_by !== '<?php echo $_SESSION["username"] ?? ""; ?>');
                        if(otherChanges.length > 0) {
                            showToast(`🔄 ${otherChanges.length} record(s) updated by other users`, 'info', 2000);
                        }
                    }
                }
            }, 'json');
        }, 3000);
    }
    
    // Update single row display without re-rendering entire table
    function updateRowDisplay($row, record) {
        let rowClass = '';
        let badge = '<span class="badge bg-warning text-dark">Pend</span>';
        
        if (record.row_status === 'done') { 
            rowClass = 'saved-row'; 
            badge = '<span class="badge bg-success">Done</span>'; 
        } else if (record.row_status === 'Completed') { 
            rowClass = 'completed-row'; 
            badge = '<span class="badge bg-danger">Comp</span>'; 
        }
        
        $row.removeClass('saved-row completed-row').addClass(rowClass);
        $row.find('td:eq(5)').html(badge);
        $row.find('td:eq(4)').text(record.username || '-');
        
        // Flash effect to show update
        $row.css('background-color', '#fff3cd');
        setTimeout(() => $row.css('background-color', ''), 1000);
    }

    function refreshTableStatuses() {
        $('#dataTable tbody tr').each(function() {
            let id = $(this).data('id');
            let rec = allClientData.find(r => r.id == id);
            if (rec) {
                let rowClass = '';
                let badge = '<span class="badge bg-warning text-dark">Pend</span>';
                
                if (rec.row_status === 'done') { rowClass = 'saved-row'; badge = '<span class="badge bg-success">Done</span>'; }
                else if (rec.row_status === 'Completed') { rowClass = 'completed-row'; badge = '<span class="badge bg-danger">Comp</span>'; }
                
                $(this).removeClass('saved-row completed-row').addClass(rowClass);
                $(this).find('td:eq(5)').html(badge); 
            }
        });
    }

    // --- DATA HANDLING ---
    function loadData() {
        $('#loader').show();
        let p = { action:'load_data', filter_user:$('#adminUserFilter').val(), start_date:$('#filterStartDate').val(), end_date:$('#filterEndDate').val(), status_filter:$('#filterStatus').val() };
        $.post('api.php', p, function(d){ 
            $('#loader').hide(); 
            allClientData=d; 
            
            // ✅ FIX: Jab bhi naya data load ho, Page 1 par reset karein
            currentPage = 1;
            
            filterData(); 
            updateCountsLocal(allClientData); 
            // Sync from DB with current user filter
            let selUser = $('#adminUserFilter').val() || '';
            updateCounts(0, selUser);
        },'json');
    }

    function filterData() {
        let term = $('#searchInput').val().toLowerCase();
        filteredData = allClientData.filter(r => !term || Object.values(r).some(v=>String(v).toLowerCase().includes(term)));
        
        // ✅ FIX: Search karte waqt bhi Page 1 par reset karein
        // (Purana code 'if(!syncInterval)' ki wajah se page reset nahi ho raha tha)
        currentPage = 1;
        
        renderTable();
    }

    function updateCounts(deoId = 0, deoUsername = '') {
        // Use central count engine via AJAX
        let payload = {action:'recalculate_counts', deo_id: deoId};
        if (!deoId && deoUsername) payload.deo_username = deoUsername;
        $.post('api.php', payload, function(res) {
            if (res.status === 'success') {
                $('#totalPendingCount').text(res.first_qc_pending);
                $('#qcPendingCount').text(res.second_qc_pending);
                $('#qcDoneCount').text(res.second_qc_done);
                $('#totalCompletedCount').text(res.final_completed);
                $('#todayCompletedCount').text(res.final_today);
                let rc = parseInt(res.report_count) || 0;
                $('#adminReportCount').text(rc);
                rc > 0 ? $('#reportCountAdminBox').show() : $('#reportCountAdminBox').hide();
            }
        }, 'json');
    }
    
    // Also calculate from local data (for real-time updates)
    function updateCountsLocal(data) {
        if (!data) data = allClientData;
        let today = new Date().toISOString().split('T')[0];
        let totalPending   = data.filter(r => r.row_status === 'pending').length;
        let totalCompleted = data.filter(r => r.row_status === 'Completed').length;
        let todayCompleted = data.filter(r => r.row_status === 'Completed' && r.updated_at && r.updated_at.substring(0,10)===today).length;
        let qcPending      = data.filter(r => ['deo_done','pending_qc','done'].includes(r.row_status)).length;
        let qcDone         = data.filter(r => ['qc_done','qc_approved'].includes(r.row_status)).length;
        $('#totalPendingCount').text(totalPending);
        $('#totalCompletedCount').text(totalCompleted);
        $('#todayCompletedCount').text(todayCompleted);
        $('#qcPendingCount').text(qcPending);
        $('#qcDoneCount').text(qcDone);
        // Note: adminReportCount is set by recalculate_counts API (accurate individual report count)
        // Don't override it here with is_reported flag (record-level count is different)
    }
    
    // QC System Toggle
    function toggleQCSystem() {
        let enabled = $('#qcToggle').is(':checked') ? '1' : '0';
        $.post('api.php', {action: 'toggle_qc_system', enabled: enabled}, function(res){
            if(res.status === 'success') {
                showToast(enabled === '1' ? '✅ QC System Enabled' : '⚠️ QC System Disabled', enabled === '1' ? 'success' : 'warning');
            }
        }, 'json');
    }
    
    // Load QC Status on page load
    function loadQCStatus() {
        $.post('api.php', {action: 'get_qc_status'}, function(res){
            if(res.status === 'success') {
                $('#qcToggle').prop('checked', res.qc_enabled === '1');
            }
        }, 'json');
    }

    // ── VIRTUAL SCROLL ENGINE ──────────────────────────────────
    // Sirf visible rows render karta hai — 10,000 rows bhi fast!
    const VS_ROW_H = 32;        // har row ki approximate height (px)
    const VS_BUFFER = 15;       // screen ke upar/neeche extra rows
    let vsScrollBound = false;

    function buildRow(r, absIdx) {
        let rowClass = '', statusBadge = '<span class="badge bg-warning text-dark">1st QC Pend</span>', isCompleted = false;
        if(r.row_status==='done'||r.row_status==='deo_done'||r.row_status==='pending_qc')
            { rowClass='saved-row'; statusBadge='<span class="badge bg-success text-white">1st QC Done</span>'; }
        else if(r.row_status==='qc_done'||r.row_status==='qc_approved')
            { rowClass='qc-done-row'; statusBadge='<span class="badge bg-primary">2nd QC Done</span>'; }
        else if(r.row_status==='Completed')
            { rowClass='completed-row'; statusBadge='<span class="badge bg-danger">Comp</span>'; isCompleted=true; }

        let edited = JSON.parse(r.edited_fields||'[]');
        let seconds = parseInt(r.time_spent)||0;
        let isChecked = selectedIds.has(String(r.id)) ? 'checked' : '';
        let doneBtn = `<button class="btn btn-primary btn-sm py-0" onclick="saveRow(${r.id})">DONE</button>`;
        let isInvalid = !/^[0-9]+$/.test(r.record_no);
        let invalidBadge = isInvalid ? '<span class="badge bg-danger ms-1" title="Invalid record no">⚠️</span>' : '';
        let recClass = isInvalid ? 'record-cell invalid-record' : 'record-cell';
        let editEvt = isInvalid
            ? `ondblclick="openEditRecordModal(${r.id},'${r.record_no}')" oncontextmenu="showRecordContextMenu(event,${r.id},'${r.record_no}',true)" title="Double-click to fix"`
            : `oncontextmenu="showRecordContextMenu(event,${r.id},'${r.record_no}',false)" title="Valid record"`;

        let h = `<tr data-id="${r.id}" data-record-no="${r.record_no}" class="${rowClass}">
            <td class="text-center"><input type="checkbox" class="row-select" data-id="${r.id}" ${isChecked} onchange="updateSelection('${r.id}',this.checked)"></td>
            <td class="readonly">${absIdx+1}</td>
            <td class="readonly text-primary ${recClass}" tabindex="0"
                onclick="handleRecordClick(this,'${r.record_no}')"
                ${editEvt}
                onkeydown="if(event.key==='Enter')handleRecordClick(this,'${r.record_no}')"><b>${r.record_no}</b>${invalidBadge}</td>
            <td class="text-center">${doneBtn}</td>
            <td class="readonly small">${r.username||'-'}</td>
            <td class="readonly">${statusBadge}</td>`;

        ['kyc_number','name','guardian_name','gender','marital_status','dob','address','landmark','city','zip_code','city_of_birth','nationality','photo_attachment','residential_status','occupation','officially_valid_documents','annual_income','broker_name','sub_broker_code','bank_serial_no','second_applicant_name','amount_received_from','amount','arn_no','second_address','occupation_profession','remarks'].forEach(c => {
            let v = r[c]||'', cls = edited.includes(c) ? 'unsaved-cell' : '';
            if(isCompleted)
                h += `<td class="completed-cell ${cls}" tabindex="0" data-col="${c}" onfocus="handleFocusCompleted(this)" onclick="handleFocusCompleted(this)">${v}</td>`;
            else
                h += `<td contenteditable="true" data-col="${c}" class="${cls}" onfocus="handleFocus(this)" onblur="saveCell(this)" oninput="validateField(this)">${v}</td>`;
        });
        h += `<td class="timing-cell" data-seconds="${seconds}">${formatTime(seconds)}</td></tr>`;
        return h;
    }

    function renderTable() {
        let container = document.querySelector('.table-container');
        let total = filteredData.length;

        // Page info update
        $('#pageInfo').text(`${total.toLocaleString()} records`);
        $('#paginationControls').hide();

        if (total === 0) { $('#tableBody').html('<tr><td colspan="35" class="text-center text-muted py-3">No records found</td></tr>'); return; }

        // Virtual scroll: spacer rows + visible rows
        function vsRender() {
            let scrollTop = container.scrollTop;
            let viewH = container.clientHeight;
            let firstVis = Math.max(0, Math.floor(scrollTop / VS_ROW_H) - VS_BUFFER);
            let lastVis  = Math.min(total - 1, Math.ceil((scrollTop + viewH) / VS_ROW_H) + VS_BUFFER);

            let topSpace = firstVis * VS_ROW_H;
            let botSpace = (total - 1 - lastVis) * VS_ROW_H;

            let h = `<tr style="height:${topSpace}px;"><td colspan="35"></td></tr>`;
            for (let i = firstVis; i <= lastVis; i++) h += buildRow(filteredData[i], i);
            h += `<tr style="height:${botSpace}px;"><td colspan="35"></td></tr>`;
            $('#tableBody').html(h);
        }

        vsRender();

        // Scroll listener — ek baar bind karo
        if (!vsScrollBound) {
            vsScrollBound = true;
            let ticking = false;
            container.addEventListener('scroll', () => {
                if (!ticking) {
                    requestAnimationFrame(() => { vsRender(); ticking = false; });
                    ticking = true;
                }
            }, { passive: true });
        }
    }

    function changePage(d) { /* virtual scroll mein pagination nahi */ }

    function applyRecordsPerPage(val) {
        // Virtual scroll mein sab records hamesha show hote hain
        renderTable();
    }
    
    // --- RECORD ACTIONS ---
    function handleRecordClick(el, rec) { openImage(rec); $('tr.active-row').removeClass('active-row'); $(el).closest('tr').addClass('active-row'); }
    
    // Context menu for record number - isInvalid param passed from caller
    function showRecordContextMenu(event, id, recordNo, isInvalid) {
        event.preventDefault();
        
        // Remove any existing context menu
        $('.record-context-menu').remove();
        
        let menu = `
            <div class="record-context-menu" style="left:${event.pageX}px; top:${event.pageY}px;">
                ${isInvalid ? 
                    `<div class="menu-item" onclick="openEditRecordModal(${id}, '${recordNo}')">
                        ✏️ Fix Record Number
                    </div>` : 
                    `<div class="menu-item disabled" style="color:#999; cursor:not-allowed;">
                        ✅ Valid Record Number
                    </div>`
                }
                <div class="menu-item" onclick="openImage('${recordNo}')">
                    🖼️ View Image
                </div>
                <div class="menu-divider"></div>
                <div class="menu-item text-danger" onclick="if(confirm('Delete this record?')) deleteRecord(${id})">
                    🗑️ Delete Record
                </div>
            </div>
        `;
        
        $('body').append(menu);
        
        // Close menu on click outside
        $(document).one('click', function() {
            $('.record-context-menu').remove();
        });
    }
    
    function deleteRecord(id) {
        $.post('api.php', {action: 'delete_record', id: id}, function(res) {
            if (res.status === 'success') {
                alert('✅ Record deleted');
                loadData();
            } else {
                alert('❌ ' + (res.message || 'Error deleting record'));
            }
        }, 'json');
        $('.record-context-menu').remove();
    }

    function handleFocus(el) {
        let tr = $(el).closest('tr'); let id = tr.data('id');
        $('tr.active-row').removeClass('active-row'); tr.addClass('active-row');
        // Always store current value as original when focusing
        $(el).data('orig', $(el).text().trim());
        validateField(el); 
        // Highlight but don't scroll (false = isRecordNo)
        highlightText($(el).text(), false, false);
        startTracking(id);
    }
    
    // For completed cells - allow select, navigate, but no edit
    function handleFocusCompleted(el) {
        let tr = $(el).closest('tr'); let id = tr.data('id');
        $('tr.active-row').removeClass('active-row'); tr.addClass('active-row');
        // Highlight text in image viewer
        highlightText($(el).text(), false, false);
    }

    function startTracking(id) { if(activeRowId === id) return; stopTracking(); activeRowId = id; activeTimer = setInterval(() => { syncTime(id, 5); }, 5000); }
    function stopTracking() { if(activeTimer) clearInterval(activeTimer); activeRowId = null; }
    function syncTime(id, sec) { let tr = $(`tr[data-id="${id}"]`), cell = tr.find('.timing-cell'), ns = (parseInt(cell.data('seconds'))||0) + sec; cell.data('seconds', ns).text(formatTime(ns)); $.post('api.php', {action:'update_time', id:id, seconds:sec}); }
    
    function saveRow(id) {
        stopTracking();
        let tr = $(`tr[data-id="${id}"]`);
        let seconds = parseInt(tr.find('.timing-cell').data('seconds')) || 0;
        let data = { action: 'update_row', id: id, time_spent: seconds };
        tr.find('td[data-col]').each(function(){ data[$(this).data('col')] = $(this).text().trim(); });
        
        $.post('api.php', data, function(res) {
            if(res.status === 'success') {
                tr.removeClass('active-row').addClass('saved-row').find('.unsaved-cell').removeClass('unsaved-cell');
                let idx = allClientData.findIndex(r => r.id == id);
                if(idx > -1) { allClientData[idx].row_status = 'done'; allClientData[idx].updated_at = new Date().toISOString().split('T')[0]; }
                updateCounts(); $('#successMessage').fadeIn().delay(1000).fadeOut();
            } else alert('Save failed: ' + res.message);
        }, 'json');
    }
    
    function saveCell(el) { 
        $('#validationTooltip').hide(); 
        let val = $(el).text().trim();
        let orig = $(el).data('orig');
        let id = $(el).closest('tr').data('id');
        let col = $(el).data('col');
        
        // Only mark as unsaved and save if value actually changed
        if(orig !== undefined && orig !== val) { 
            $(el).addClass('unsaved-cell'); 
            $.post('api.php', {action:'update_cell', id:id, column:col, value:val}); 
        }
    }
    
    // --- FIX: NEW SELECTION LOGIC ---
    function updateSelection(id, isChecked) {
        if(isChecked) selectedIds.add(String(id));
        else selectedIds.delete(String(id));
    }

    function toggleAll(el) { 
        let isChecked = el.checked;
        $('.row-select').prop('checked', isChecked);
        // Sync Set with current view
        $('.row-select').each(function() {
            let id = String($(this).data('id'));
            if(isChecked) selectedIds.add(id);
            else selectedIds.delete(id);
        });
    }

    // --- ADMIN ACTIONS - USER MANAGEMENT (Enhanced) ---
    function loadUsersForDropdown() { $.post('api.php',{action:'get_users'},d=>{ let h='<option value="">All Users</option>'; d.forEach(u=>h+=`<option value="${u.username}">${u.username}</option>`); $('#adminUserFilter').html(h); },'json'); }
    
    function loadUsersForModal() { 
        $.post('api.php',{action:'get_users'},d=>{ 
            let h=''; 
            d.forEach(u=>{ 
                let statusBadge = u.status === 'active' ? '<span class="badge bg-success">Active</span>' : 
                                  (u.status === 'locked' || u.is_locked ? '<span class="badge bg-danger">Locked</span>' : '<span class="badge bg-secondary">Inactive</span>');
                let lastLogin = u.last_login ? new Date(u.last_login).toLocaleString() : 'Never';
                let roleBadge = u.role === 'admin' ? 'bg-danger' : 'bg-primary';
                let roleLabel = u.role;
                h+=`<tr>
                    <td><strong>${u.username}</strong><br><small class="text-muted">${u.full_name || '-'}</small></td>
                    <td><span class="badge ${roleBadge}">${roleLabel}</span></td>
                    <td>${statusBadge}</td>
                    <td>${u.daily_target || 100}</td>
                    <td><small>${lastLogin}</small></td>
                    <td>
                        <button class="btn btn-sm btn-info py-0" onclick="openUserDetails(${u.id})" title="Details">👤</button>
                        <button class="btn btn-sm btn-warning py-0" onclick="editUser(${JSON.stringify(u).replace(/"/g,'&quot;')})" title="Edit">✎</button>
                        <button class="btn btn-sm btn-danger py-0" onclick="delUser(${u.id})" title="Delete">×</button>
                    </td>
                </tr>`; 
                
                
            }); 
            $('#userListTable').html(h); 
        },'json'); 
        loadPerformance();
        loadActiveSessions();
    }
    

    function addUser() { 
        let p={
            action: $('#editUserId').val() ? 'update_user' : 'add_user', 
            user_id: $('#editUserId').val(), 
            new_username: $('#newUser').val(), 
            new_password: $('#newPass').val(), 
            new_full_name: $('#newFullName').val(), 
            new_phone: $('#newPhone').val(), 
            new_role: $('#newRole').val(),
            daily_target: $('#newTarget').val() || 100
        }; 
        if(p.action==='update_user'){
            p.username=p.new_username; p.password=p.new_password; p.full_name=p.new_full_name; 
            p.phone=p.new_phone; p.role=p.new_role;
        } 
        $.post('api.php',p,r=>{ 
            alert(r.message); 
            if(r.status==='success') { 
                resetUserForm(); 
                loadUsersForModal(); 
                // Switch to user list tab
                $('#userTabs a[href="#tabUserList"]').tab('show');
            } 
        },'json'); 
    }
    
    function editUser(u){ 
        $('#editUserId').val(u.id); 
        $('#newUser').val(u.username); 
        $('#newFullName').val(u.full_name); 
        $('#newPhone').val(u.phone); 
        $('#newRole').val(u.role); 
        $('#newTarget').val(u.daily_target || 100);
        $('#btnAddUser').text('Update User'); 
        $('#btnCancelEdit').show(); 
        // Switch to add user tab
        $('#userTabs a[href="#tabAddUser"]').tab('show');
    }
    
    function resetUserForm(){ 
        $('#editUserId').val(''); 
        $('#newUser, #newPass, #newFullName, #newPhone').val(''); 
        $('#newRole').val('deo'); 
        $('#newTarget').val(100);
        $('#btnAddUser').text('Add User'); 
        $('#btnCancelEdit').hide(); 
    }
    
    function delUser(id){ 
        if(confirm('Delete User?')) 
            $.post('api.php',{action:'delete_user',user_id:id},r=>{
                alert(r.message || 'Done');
                loadUsersForModal();
            },'json'); 
    }
    
    // User Details Modal
    function openUserDetails(userId) {
        $.post('api.php',{action:'get_users'},d=>{ 
            let user = d.find(u => u.id == userId);
            if(user) {
                $('#detailUserId').val(user.id);
                $('#detailUserName').text(user.username);
                $('#detailTarget').val(user.daily_target || 100);
                $('#detailAllowedIPs').val(user.allowed_ips || '');
                $('#detailFailedAttempts').text(user.failed_attempts || 0);
                
                let statusClass = user.status === 'active' ? 'bg-success' : (user.is_locked ? 'bg-danger' : 'bg-secondary');
                $('#detailStatus').attr('class', 'badge ' + statusClass).text(user.status);
                
                // Load user login history
                $.post('api.php',{action:'get_user_login_history', username: user.username},res=>{
                    if(res.status==='success') {
                        let h = '';
                        res.data.slice(0,10).forEach(r=>{
                            let icon = r.status === 'success' ? '✅' : '❌';
                            h += `<tr><td><small>${r.created_at}</small></td><td><small>${r.ip_address}</small></td><td>${icon}</td></tr>`;
                        });
                        $('#userLoginHistoryTable').html(h || '<tr><td colspan="3" class="text-center">No records</td></tr>');
                    }
                },'json');
                
                $('#userDetailsModal').modal('show');
            }
        },'json'); 
    }
    
    // Fix username in records manually
    function fixUsernameInRecords() {
        let oldUser = $('#fixOldUsername').val().trim();
        let newUser = $('#fixNewUsername').val().trim();
        
        if(!oldUser || !newUser) {
            alert('Enter both old and new username');
            return;
        }
        
        if(!confirm(`Change all records from "${oldUser}" to "${newUser}"?`)) return;
        
        $.post('api.php', {
            action: 'fix_username_in_records',
            old_username: oldUser,
            new_username: newUser
        }, function(res) {
            alert(res.message);
            if(res.status === 'success') {
                $('#fixOldUsername, #fixNewUsername').val('');
                loadData(); // Refresh main table
            }
        }, 'json');
    }
    
    function setUserStatus(status) {
        let userId = $('#detailUserId').val();
        $.post('api.php',{action:'update_user_status', user_id: userId, status: status},r=>{
            if(r.status==='success') {
                alert('Status updated!');
                $('#userDetailsModal').modal('hide');
                loadUsersForModal();
            } else {
                alert(r.message || 'Failed');
            }
        },'json');
    }
    
    function unlockUserAccount() {
        let userId = $('#detailUserId').val();
        $.post('api.php',{action:'unlock_user', user_id: userId},r=>{
            if(r.status==='success') {
                alert('Account unlocked!');
                $('#userDetailsModal').modal('hide');
                loadUsersForModal();
            } else {
                alert(r.message || 'Failed');
            }
        },'json');
    }
    
    function resetUserPassword() {
        let userId = $('#detailUserId').val();
        let newPass = $('#resetPassInput').val();
        if(!newPass) { alert('Enter new password'); return; }
        if(!confirm('Reset password for this user?')) return;
        
        $.post('api.php',{action:'reset_user_password', user_id: userId, new_password: newPass},r=>{
            if(r.status==='success') {
                alert('Password reset successfully!');
                $('#resetPassInput').val('');
            } else {
                alert(r.message || 'Failed');
            }
        },'json');
    }
    
    function setUserTarget() {
        let userId = $('#detailUserId').val();
        let target = $('#detailTarget').val();
        $.post('api.php',{action:'set_user_target', user_id: userId, daily_target: target},r=>{
            if(r.status==='success') {
                alert('Target updated!');
                loadUsersForModal();
            } else {
                alert(r.message || 'Failed');
            }
        },'json');
    }
    
    function setUserAllowedIPs() {
        let userId = $('#detailUserId').val();
        let ips = $('#detailAllowedIPs').val();
        $.post('api.php',{action:'set_user_allowed_ips', user_id: userId, allowed_ips: ips},r=>{
            if(r.status==='success') {
                alert('Allowed IPs updated!');
            } else {
                alert(r.message || 'Failed');
            }
        },'json');
    }
    
    // ==========================================
    // MESSAGE FUNCTIONS
    // ==========================================
    
    function loadUsersForMessage() {
        $.post('api.php',{action:'get_users'},d=>{
            let opts = '<option value="0">📢 All DEOs (Broadcast)</option>';
            d.forEach(u=>{
                if(u.role !== 'admin') {
                    opts += `<option value="${u.id}">${u.full_name || u.username} (${u.username})</option>`;
                }
            });
            $('#msgRecipient').html(opts);
        },'json');
        loadMessageHistory();
    }
    
    function setQuickMsg(msg) {
        $('#msgContent').val(msg);
    }
    
    function sendMessageToDeo() {
        let recipient = $('#msgRecipient').val();
        let priority = $('#msgPriority').val();
        let message = $('#msgContent').val().trim();
        
        if(!message) {
            alert('Please enter a message');
            return;
        }
        
        $.post('api.php', {
            action: 'send_message_to_deo',
            to_user_id: recipient,
            message: message,
            priority: priority
        }, function(res) {
            if(res.status === 'success') {
                alert('Message sent successfully!');
                $('#msgContent').val('');
                loadMessageHistory();
            } else {
                alert(res.message || 'Failed to send message');
            }
        }, 'json');
    }
    
    function loadMessageHistory() {
        $.post('api.php', {action: 'get_sent_messages'}, function(res) {
            if(res.status === 'success') {
                let h = '';
                res.messages.forEach(m => {
                    let priorityBadge = m.priority === 'urgent' ? '<span class="badge bg-danger">Urgent</span>' :
                                        m.priority === 'warning' ? '<span class="badge bg-warning text-dark">Warning</span>' :
                                        '<span class="badge bg-secondary">Normal</span>';
                    let readBadge = m.is_read ? '<span class="badge bg-success">✓ Read</span>' : '<span class="badge bg-warning text-dark">Pending</span>';
                    let timeAgo = m.created_at;
                    
                    h += `<tr>
                        <td><small>${timeAgo}</small></td>
                        <td>${m.to_name || 'All Users'}</td>
                        <td style="max-width:200px; white-space:normal;">${m.message.substring(0,100)}${m.message.length>100?'...':''}</td>
                        <td>${priorityBadge}</td>
                        <td>${readBadge}</td>
                    </tr>`;
                });
                $('#msgHistoryTable').html(h || '<tr><td colspan="5" class="text-center">No messages sent yet</td></tr>');
            }
        }, 'json');
    }
    
    // Performance Tab
    function loadPerformance() {
        let period = $('#perfPeriod').val() || 'today';
        $.post('api.php',{action:'get_all_users_performance', period: period},r=>{
            if(r.status==='success') {
                let h = '';
                r.data.forEach((u, i)=>{
                    let progressClass = u.target_progress >= 100 ? 'bg-success' : (u.target_progress >= 50 ? 'bg-warning' : 'bg-danger');
                    let avgTimeFormatted = u.avg_time ? Math.floor(u.avg_time/60)+'m '+u.avg_time%60+'s' : '-';
                    h += `<tr>
                        <td>${i+1}</td>
                        <td><strong>${u.username}</strong><br><small class="text-muted">${u.full_name || ''}</small></td>
                        <td><strong>${u.completed || 0}</strong></td>
                        <td>${u.daily_target || 100}</td>
                        <td>
                            <div class="progress" style="height:20px;">
                                <div class="progress-bar ${progressClass}" style="width:${Math.min(u.target_progress,100)}%">${u.target_progress}%</div>
                            </div>
                        </td>
                        <td>${avgTimeFormatted}</td>
                    </tr>`;
                });
                $('#performanceTable').html(h || '<tr><td colspan="6" class="text-center">No data</td></tr>');
            }
        },'json');
    }
    
    // Active Sessions Tab
    function loadActiveSessions() {
        $.post('api.php',{action:'get_active_sessions'},r=>{
            if(r.status==='success') {
                let h = '';
                if(r.data.length === 0) {
                    h = '<tr><td colspan="5" class="text-center text-muted">No active sessions</td></tr>';
                } else {
                    r.data.forEach(s=>{
                        let roleBadge = s.role === 'admin' ? '<span class="badge bg-danger">Admin</span>' : 
                                       '<span class="badge bg-primary">DEO</span>';
                        let lastActivity = s.last_activity ? new Date(s.last_activity).toLocaleString() : '-';
                        h += `<tr>
                            <td><strong>${s.username}</strong><br><small class="text-muted">${s.full_name || ''}</small></td>
                            <td>${roleBadge}</td>
                            <td><small>${lastActivity}</small></td>
                            <td><small>${s.last_ip || '-'}</small></td>
                            <td><span class="badge bg-success">🟢 Online</span></td>
                        </tr>`;
                    });
                }
                $('#activeSessionsTable').html(h);
            }
        },'json');
    }
    
    function batchStatusUpdate(s) { 
        let ids = Array.from(selectedIds);
        if(!ids.length) return alert('Select Rows'); 
        if(confirm(`Mark ${ids.length} rows as ${s}?`)) {
            $.post('api.php', {action:'batch_update_status', ids:ids.join(','), status:s}, r=>{
                alert(r.message);
                selectedIds.clear(); 
                loadData();
            },'json');
        }
    }
    
    function showAssignmentModal(){ 
        let ids = Array.from(selectedIds);
        $.post('api.php',{action:'get_users'},d=>{ let h='<option value="">Select</option><option value="Unassign">Unassign</option>'; d.forEach(u=>h+=`<option value="${u.username}">${u.username}</option>`); $('#assignmentUserSelect').html(h); $('#assignmentModal').modal('show'); },'json'); 
    }
    
    function assignSelectedRows(){ 
        let u=$('#assignmentUserSelect').val(), ids = Array.from(selectedIds);
        let d={action:'assign_rows', target_user:u}; 
        if(ids.length) d.ids=ids.join(','); 
        else { d.record_no_start=$('#recordNoStart').val(); d.record_no_end=$('#recordNoEnd').val(); } 
        
        $.post('api.php',d,r=>{ 
            alert(r.message); 
            $('#assignmentModal').modal('hide'); 
            selectedIds.clear(); 
            loadData(); 
        },'json'); 
    }
    
    function uploadSingleImage() { let fd=new FormData(); fd.append('image',$('#singleImageFile')[0].files[0]); fd.append('record_no',$('#fallbackRecordNo').text()); fd.append('action','upload_single_image_and_map'); $.ajax({url:'api.php',type:'POST',data:fd,contentType:false,processData:false,success:r=>{ alert(JSON.parse(r).message); openImage($('#fallbackRecordNo').text()); }}); }
    function clearMainData(){ if(confirm('DEL DATA?')) $.post('api.php',{action:'clear_main_data'},r=>{alert(r.message);loadData();},'json'); }
    async function uploadImages(){ let f=$('#imageUpload')[0].files; for(let i=0;i<f.length;i+=10){ let fd=new FormData(); for(let j=i;j<i+10&&j<f.length;j++)fd.append('images[]',f[j]); fd.append('action','upload_images_files'); await $.ajax({url:'api.php',type:'POST',data:fd,contentType:false,processData:false}); } alert('Done'); }
    
    function syncImages() {
        if(confirm("Sync images from external database?")) {
            $('#loader').show();
            $.post('api.php', {action: 'sync_external_mapping'}, function(res) {
                $('#loader').hide();
                alert(res.message);
                if(res.status==='success') loadData();
            }, 'json').fail(function() { $('#loader').hide(); alert("Sync Failed"); });
        }
    }
    
    // Upload functions for Data Management modal
    function uploadMainExcel() {
        let file = $('#mainExcel')[0].files[0];
        if(!file) { showToast('Please select a file first', 'warning'); return; }
        if(!confirm('⚠️ This will REPLACE ALL existing data. Are you sure?')) return;
        parseAndUpload({target: {files: [file]}}, 'upload_main_data');
    }
    
    function uploadUpdateExcel() {
        let file = $('#updateExcel')[0].files[0];
        if(!file) { showToast('Please select a file first', 'warning'); return; }
        parseAndUpload({target: {files: [file]}}, 'upload_updated_data');
    }
    
    // ========== FLEXIBLE FIELD IMPORT ==========
    let fieldImportData = [];
    
    function previewFieldImport() {
        let file = $('#fieldImportExcel')[0].files[0];
        if(!file) return;
        
        let reader = new FileReader();
        reader.onload = function(e) {
            let workbook = XLSX.read(new Uint8Array(e.target.result), {type: 'array'});
            let sheet = workbook.Sheets[workbook.SheetNames[0]];
            let data = XLSX.utils.sheet_to_json(sheet);
            
            if(data.length === 0) {
                showToast('No data found in file', 'error');
                return;
            }
            
            fieldImportData = data;
            let columns = Object.keys(data[0]);
            
            // Populate dropdowns
            let recordNoOpts = '<option value="">-- Select --</option>';
            let dataOpts = '<option value="">-- Select --</option>';
            
            columns.forEach(col => {
                let selected = '';
                // Auto-select Record No column
                if(col.toLowerCase().replace(/[^a-z0-9]/g,'').includes('recordno')) {
                    selected = 'selected';
                }
                recordNoOpts += `<option value="${col}" ${selected}>${col}</option>`;
                dataOpts += `<option value="${col}">${col}</option>`;
            });
            
            $('#recordNoColumn').html(recordNoOpts);
            $('#dataColumn').html(dataOpts);
            $('#fieldImportCount').text(data.length);
            $('#fieldImportPreview').show();
            
            // Auto-detect and select target field based on column name
            $('#dataColumn').on('change', function() {
                let selectedCol = $(this).val().toLowerCase().replace(/[^a-z0-9]/g,'');
                let targetField = '';
                
                // Map common column names to database fields
                let mappings = {
                    'kycnumber': 'kyc_number', 'kycno': 'kyc_number',
                    'name': 'name',
                    'guardianname': 'guardian_name',
                    'gender': 'gender',
                    'maritalstatus': 'marital_status',
                    'dob': 'dob', 'dateofbirth': 'dob',
                    'address': 'address',
                    'landmark': 'landmark',
                    'city': 'city',
                    'zipcode': 'zip_code', 'pincode': 'zip_code',
                    'cityofbirth': 'city_of_birth',
                    'nationality': 'nationality',
                    'photoattachment': 'photo_attachment', 'photo': 'photo_attachment',
                    'residentialstatus': 'residential_status', 'resstatus': 'residential_status',
                    'occupation': 'occupation',
                    'ovd': 'officially_valid_documents', 
                    'officiallyvaliddocuments': 'officially_valid_documents',
                    'annualincome': 'annual_income', 'income': 'annual_income',
                    'brokername': 'broker_name', 'broker': 'broker_name',
                    'subbrokercode': 'sub_broker_code',
                    'bankserialno': 'bank_serial_no', 'bankserial': 'bank_serial_no',
                    'secondapplicantname': 'second_applicant_name', '2ndapplicantname': 'second_applicant_name',
                    'amountreceivedfrom': 'amount_received_from',
                    'amount': 'amount',
                    'arnno': 'arn_no', 'arn': 'arn_no',
                    'secondaddress': 'second_address', '2ndaddress': 'second_address',
                    'occupationprofession': 'occupation_profession', 'profession': 'occupation_profession',
                    'remarks': 'remarks'
                };
                
                for(let key in mappings) {
                    if(selectedCol.includes(key)) {
                        targetField = mappings[key];
                        break;
                    }
                }
                
                if(targetField) {
                    $('#targetDbField').val(targetField);
                }
            });
        };
        reader.readAsArrayBuffer(file);
    }
    
    function uploadFieldImport() {
        let recordNoCol = $('#recordNoColumn').val();
        let dataCol = $('#dataColumn').val();
        let targetField = $('#targetDbField').val();
        
        if(!recordNoCol) { showToast('Please select Record No column', 'warning'); return; }
        if(!dataCol) { showToast('Please select Data column', 'warning'); return; }
        if(!targetField) { showToast('Please select Target Database Field', 'warning'); return; }
        
        // Prepare data
        let importData = fieldImportData.map(row => ({
            record_no: String(row[recordNoCol] || '').trim(),
            value: String(row[dataCol] || '').trim()
        })).filter(r => r.record_no);
        
        if(importData.length === 0) {
            showToast('No valid data found', 'error');
            return;
        }
        
        $('#fieldImportProgress').show();
        $('#fieldProgressBar').css('width', '0%').text('0%');
        $('#fieldProgressText').text('Starting import...');
        
        uploadFieldChunks(importData, 0, importData.length, targetField);
    }
    
    function uploadFieldChunks(data, index, total, targetField) {
        let chunkSize = 100;
        let chunk = data.slice(index, index + chunkSize);
        
        if(chunk.length === 0) {
            $('#fieldProgressBar').css('width', '100%').text('100%');
            $('#fieldProgressText').text('✅ Completed!');
            showToast('Data imported successfully!', 'success');
            loadData();
            setTimeout(() => {
                $('#fieldImportProgress').hide();
                $('#fieldImportPreview').hide();
                $('#fieldImportExcel').val('');
            }, 2000);
            return;
        }
        
        let progress = Math.round((index / total) * 100);
        $('#fieldProgressBar').css('width', progress + '%').text(progress + '%');
        $('#fieldProgressText').text(`Processing ${index} / ${total} records...`);
        
        $.post('api.php', {
            action: 'import_field_data',
            target_field: targetField,
            jsonData: JSON.stringify(chunk)
        }, function(res) {
            if(res.status === 'success') {
                uploadFieldChunks(data, index + chunkSize, total, targetField);
            } else {
                showToast('Error: ' + res.message, 'error');
                $('#fieldImportProgress').hide();
            }
        }, 'json').fail(function() {
            showToast('Network error during upload', 'error');
            $('#fieldImportProgress').hide();
        });
    }
    // ========== END FLEXIBLE FIELD IMPORT ==========

    $('#mainExcel').change(e=>{ /* Auto upload disabled - use button */ });
    $('#updateExcel').change(e=>{ /* Auto upload disabled - use button */ });
    function parseAndUpload(e, act) { 
        $('#loader').show();
        let r=new FileReader(); 
        r.onload=ev=>{ 
            let d=XLSX.utils.sheet_to_json(XLSX.read(new Uint8Array(ev.target.result),{type:'array'}).Sheets[XLSX.read(new Uint8Array(ev.target.result),{type:'array'}).SheetNames[0]]); 
            uploadChunks(d,0,act); 
        }; 
        r.readAsArrayBuffer(e.target.files[0]); 
    }
    function uploadChunks(d,i,act){ let c=d.slice(i,i+50); if(!c.length){$('#loader').hide(); showToast('Upload completed successfully!', 'success'); loadData(); return;} $.post('api.php',{action:act,jsonData:JSON.stringify(c)},()=>uploadChunks(d,i+50,act)); }
    
    function toggleImageLock() {
        isImageLocked = !isImageLocked;
        let btn = $('#btnLock');
        if(isImageLocked) { btn.text('🔒').removeClass('btn-outline-danger').addClass('btn-danger'); } 
        else { btn.text('🔓').removeClass('btn-danger').addClass('btn-outline-danger'); }
    }

    function openImage(rec, retryCount = 0) { 
    if(isImageLocked) return; 
    $('#imageViewerContainer, #divider').show().css('display','flex'); 
    $('#ocrStatus').text('Searching...').removeClass().addClass('ms-2 text-info');
    $.post('api.php',{action:'get_image', record_no:rec}, res=>{ 
        if(res.status==='success'){ 
            $('#imageUploadFallback').hide(); 
            $('#imageFileName').text(res.image + (retryCount > 0 ? ` (Linked -${retryCount})` : "")); 
            let img=$('#zoomImage'); 
            // If image path starts with '/', use as-is, otherwise prefix with 'uploads/'
            let imgPath = res.image.startsWith('/') ? res.image : 'uploads/'+res.image;
            let src = imgPath+'?t='+new Date().getTime();
            img.off('load').on('load',function(){ initialW=this.naturalWidth; initialH=this.naturalHeight; $('#highlightLayer').attr({width:initialW,height:initialH}); applyTrans(); runOCR(imgPath, rec); }).attr('src',src); 
        } else { 
            if(retryCount < 5) { let allRecs = filteredData.map(r => r.record_no); let idx = allRecs.indexOf(rec); if(idx > 0) { openImage(allRecs[idx-1], retryCount + 1); return; } }
            $('#imageUploadFallback').show(); $('#fallbackRecordNo').text(rec); $('#zoomImage').attr('src',''); $('#ocrStatus').text('No Image').removeClass().addClass('text-danger');
        } 
    },'json'); 
}

    async function runOCR(path, recordNo) { 
        $('#ocrStatus').text('Scanning...').addClass('text-warning');
        try {
            if(!ocrWorker) { ocrWorker=await Tesseract.createWorker(); await ocrWorker.loadLanguage('eng'); await ocrWorker.initialize('eng'); } 
            const {data}=await ocrWorker.recognize(path); ocrData=data; 
            $('#ocrStatus').text('Ready').removeClass().addClass('ms-2 text-success'); 
            // Auto-scroll only for record_no (true = isRecordNo)
            highlightText(recordNo, $('#autoScrollCheck').is(':checked'), true); 
        } catch(e) {
            $('#ocrStatus').text('OCR Error').removeClass().addClass('ms-2 text-danger');
        }
    }

    // Highlight text but scroll ONLY when isRecordNo is true
    function highlightText(txt, scrollEnabled, isRecordNo) { 
        if(!ocrData||!txt) return; 
        let ctx=document.getElementById('highlightLayer').getContext('2d'); 
        ctx.clearRect(0,0,initialW,initialH); ctx.fillStyle='rgba(255,255,0,0.4)'; 
        let found=null, terms=txt.toLowerCase().split(' ').filter(t=>t.length>2); 
        ocrData.words.forEach(w=>{ if(terms.some(t=>w.text.toLowerCase().includes(t))) { ctx.fillRect(w.bbox.x0, w.bbox.y0, w.bbox.x1-w.bbox.x0, w.bbox.y1-w.bbox.y0); if(!found) found=w.bbox; } }); 
        // Only scroll if scrollEnabled AND isRecordNo is true
        if(found && scrollEnabled && isRecordNo) { let v=$('#viewerBody'), tx=(found.x0*currentScale)-(v.width()/2), ty=(found.y0*currentScale)-50; v.animate({scrollTop:ty, scrollLeft:tx}, 300); } 
    }
    function applyTrans() { let w=initialW*currentScale, h=initialH*currentScale; $('#zoomImage, #highlightLayer').css({width:w, height:h}); $('#imgWrapper').css('transform', `rotate(${currentRot}deg)`); applyFilters(); }
    window.applyFilters = function() { filterB=$('#brightRange').val(); filterC=$('#contrastRange').val(); let f=`brightness(${filterB}%) contrast(${filterC}%) invert(${isInverted?1:0})`; $('#zoomImage, #magnifierLens').css('filter', f); };
    function adjustZoom(d) { currentScale+=d; if(currentScale<0.2)currentScale=0.2; applyTrans(); }
    function rotateImg() { currentRot+=90; applyTrans(); }
    function toggleInvert() { isInverted=!isInverted; applyFilters(); }
    function resetZoom() { currentScale=1.4; currentRot=0; isInverted=false; filterB=100; filterC=100; $('#brightRange').val(100); $('#contrastRange').val(100); applyTrans(); }
    function toggleMagnifier() { isMagnifierActive=!isMagnifierActive; $('#magnifierLens').toggle(isMagnifierActive); $('#viewerBody').css('cursor', isMagnifierActive?'none':'default'); }
    function formatTime(s) { return s? Math.floor(s/60)+'m '+(s%60)+'s' : "0m 0s"; }
    let isDragging=false; $('#divider').on('mousedown',()=>isDragging=true); $(document).on('mousemove',e=>{if(!isDragging)return; let h=document.getElementById('mainContainer').getBoundingClientRect().bottom - e.clientY; if(h>100 && h<window.innerHeight-200) $('#imageViewerContainer').css('flexBasis',h+'px'); }).on('mouseup',()=>isDragging=false);

    // ==========================================
    // SECURITY FUNCTIONS
    // ==========================================
    
    function loadSecurityData() {
        // Load security settings
        $.post('api.php', {action: 'get_security_settings'}, function(res) {
            if(res.status === 'success') {
                $('#setMaxAttempts').val(res.data.max_login_attempts || 3);
                $('#setLockoutDuration').val(res.data.lockout_duration || 15);
                $('#setSessionTimeout').val(res.data.session_timeout || 30);
                $('#setIPRestriction').prop('checked', res.data.ip_restriction_enabled === '1');
            }
        }, 'json');
        
        // Load allowed IPs (also gets current IP)
        loadAllowedIPs();
        
        // Load users for dropdowns
        $.post('api.php', {action: 'get_users'}, function(res) {
            let opts = '<option value="">All Users</option>';
            res.forEach(u => opts += `<option value="${u.username}">${u.username}</option>`);
            $('#loginHistoryUser, #activityLogUser').html(opts);
        }, 'json');
        
        // Load initial data
        loadLoginHistory();
        loadActivityLogs();
    }
    
    function saveSecuritySettings() {
        let settings = [
            {key: 'max_login_attempts', value: $('#setMaxAttempts').val()},
            {key: 'lockout_duration', value: $('#setLockoutDuration').val()},
            {key: 'session_timeout', value: $('#setSessionTimeout').val()},
            {key: 'ip_restriction_enabled', value: $('#setIPRestriction').is(':checked') ? '1' : '0'}
        ];
        
        let completed = 0;
        settings.forEach(s => {
            $.post('api.php', {action: 'update_security_setting', key: s.key, value: s.value}, function() {
                completed++;
                if(completed === settings.length) {
                    alert('Security settings saved!');
                }
            }, 'json');
        });
    }
    
    function loadLoginHistory() {
        let username = $('#loginHistoryUser').val();
        $.post('api.php', {action: 'get_login_history', username: username, limit: 100}, function(res) {
            if(res.status === 'success') {
                let h = '';
                res.data.forEach(r => {
                    let statusClass = r.status === 'success' ? 'text-success' : (r.status === 'blocked' ? 'text-danger' : 'text-warning');
                    let statusIcon = r.status === 'success' ? '✅' : (r.status === 'blocked' ? '🚫' : '❌');
                    h += `<tr>
                        <td><small>${r.created_at}</small></td>
                        <td>${r.username}</td>
                        <td><small>${r.ip_address}</small></td>
                        <td class="${statusClass}">${statusIcon} ${r.status}</td>
                        <td><small>${r.failure_reason || '-'}</small></td>
                    </tr>`;
                });
                $('#loginHistoryTable').html(h || '<tr><td colspan="5" class="text-center">No records</td></tr>');
            }
        }, 'json');
    }
    
    function loadActivityLogs() {
        let username = $('#activityLogUser').val();
        let action_filter = $('#activityLogAction').val();
        $.post('api.php', {action: 'get_activity_logs', username: username, action_filter: action_filter, limit: 100}, function(res) {
            if(res.status === 'success') {
                let h = '';
                res.data.forEach(r => {
                    h += `<tr>
                        <td><small>${r.created_at}</small></td>
                        <td>${r.username || '-'}</td>
                        <td><span class="badge bg-secondary">${r.action}</span></td>
                        <td>${r.module || '-'}</td>
                        <td>${r.record_no || '-'}</td>
                        <td><small>${r.ip_address || '-'}</small></td>
                    </tr>`;
                });
                $('#activityLogTable').html(h || '<tr><td colspan="6" class="text-center">No records</td></tr>');
            }
        }, 'json');
    }
    
    function loadAllowedIPs() {
        $.post('api.php', {action: 'get_allowed_ips'}, function(res) {
            if(res.status === 'success') {
                $('#currentIP').text(res.client_ip);
                let h = '';
                res.data.forEach(ip => {
                    h += `<li class="list-group-item d-flex justify-content-between align-items-center py-1">
                        <div>
                            <strong>${ip.ip_address}</strong>
                            <br><small class="text-muted">${ip.description || 'No description'}</small>
                        </div>
                        <button class="btn btn-sm btn-danger" onclick="removeAllowedIP(${ip.id})">×</button>
                    </li>`;
                });
                $('#allowedIPList').html(h || '<li class="list-group-item text-center text-muted">No IPs added</li>');
            }
        }, 'json');
    }
    
    function addAllowedIP() {
        let ip = $('#newIPAddress').val().trim();
        let desc = $('#newIPDesc').val().trim();
        
        if(!ip) { alert('Please enter IP address'); return; }
        
        $.post('api.php', {action: 'add_allowed_ip', ip_address: ip, description: desc}, function(res) {
            if(res.status === 'success') {
                $('#newIPAddress, #newIPDesc').val('');
                loadAllowedIPs();
                alert('IP added successfully!');
            } else {
                alert(res.message || 'Failed to add IP');
            }
        }, 'json');
    }
    
    function removeAllowedIP(id) {
        if(!confirm('Remove this IP from whitelist?')) return;
        $.post('api.php', {action: 'remove_allowed_ip', id: id}, function(res) {
            if(res.status === 'success') {
                loadAllowedIPs();
            }
        }, 'json');
    }

    // ==========================================
    // REPORTS & ANALYTICS FUNCTIONS
    // ==========================================
    
    function loadReportsData() {
        // Set default dates
        $('#dailyReportDate').val(new Date().toISOString().split('T')[0]);
        let today = new Date();
        let weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
        $('#weeklyStartDate').val(weekAgo.toISOString().split('T')[0]);
        $('#weeklyEndDate').val(today.toISOString().split('T')[0]);
        $('#monthlyReportMonth').val(today.toISOString().slice(0,7));
        
        // Load users for chart dropdown
        $.post('api.php', {action: 'get_users'}, function(res) {
            let opts = '<option value="">All Users</option>';
            res.forEach(u => opts += `<option value="${u.username}">${u.username}</option>`);
            $('#chartUser').html(opts);
        }, 'json');
        
        // Load initial reports
        loadDailyReport();
    }
    
    function loadDailyReport() {
        let date = $('#dailyReportDate').val();
        $.post('api.php', {action: 'get_daily_report', date: date}, function(res) {
            if(res.status === 'success') {
                let h = '';
                res.data.forEach((u, i) => {
                    let progressClass = u.target_progress >= 100 ? 'bg-success' : (u.target_progress >= 50 ? 'bg-warning' : 'bg-danger');
                    h += `<tr>
                        <td>${i+1}</td>
                        <td><strong>${u.username}</strong><br><small>${u.full_name || ''}</small></td>
                        <td><strong>${u.completed}</strong></td>
                        <td>${u.daily_target}</td>
                        <td><div class="progress" style="height:18px;"><div class="progress-bar ${progressClass}" style="width:${Math.min(u.target_progress,100)}%">${u.target_progress}%</div></div></td>
                        <td>${Math.round(u.total_time/60)}</td>
                        <td>${u.avg_time}</td>
                    </tr>`;
                });
                $('#dailyReportTable').html(h || '<tr><td colspan="7" class="text-center">No data</td></tr>');
                $('#dailyOverallStats').html(`Total: ${res.overall.total_completed} | Time: ${Math.round(res.overall.total_time/60)} min | Avg: ${res.overall.avg_time} sec`);
            }
        }, 'json');
    }
    
    function loadWeeklyReport() {
        let start = $('#weeklyStartDate').val();
        let end = $('#weeklyEndDate').val();
        $.post('api.php', {action: 'get_weekly_report', start_date: start, end_date: end}, function(res) {
            if(res.status === 'success') {
                // Daily breakdown
                let h1 = '';
                res.daily_data.forEach(d => {
                    h1 += `<tr><td>${d.date}</td><td><strong>${d.completed}</strong></td><td>${Math.round(d.total_time/60)}</td></tr>`;
                });
                $('#weeklyDailyTable').html(h1 || '<tr><td colspan="3">No data</td></tr>');
                
                // User breakdown
                let h2 = '';
                res.user_data.forEach((u, i) => {
                    h2 += `<tr><td>${i+1}</td><td><strong>${u.username}</strong></td><td>${u.completed}</td><td>${Math.round(u.total_time/60)}</td><td>${u.avg_time}</td></tr>`;
                });
                $('#weeklyUserTable').html(h2 || '<tr><td colspan="5">No data</td></tr>');
            }
        }, 'json');
    }
    
    function loadMonthlyReport() {
        let month = $('#monthlyReportMonth').val();
        $.post('api.php', {action: 'get_monthly_report', month: month}, function(res) {
            if(res.status === 'success') {
                // Weekly breakdown
                let h1 = '';
                res.weekly_data.forEach(w => {
                    h1 += `<tr><td>${w.week_start}</td><td><strong>${w.completed}</strong></td></tr>`;
                });
                $('#monthlyWeeklyTable').html(h1 || '<tr><td colspan="2">No data</td></tr>');
                
                // User breakdown
                let h2 = '';
                res.data.forEach((u, i) => {
                    h2 += `<tr><td>${i+1}</td><td><strong>${u.username}</strong></td><td>${u.completed}</td><td>${u.working_days}</td><td>${u.daily_avg}</td><td>${u.avg_time}s</td></tr>`;
                });
                $('#monthlyUserTable').html(h2 || '<tr><td colspan="6">No data</td></tr>');
                $('#monthlyTotalStats').text(`Total Completed: ${res.total_completed}`);
            }
        }, 'json');
    }
    
    function loadChartData() {
        let type = $('#chartType').val();
        let username = $('#chartUser').val();
        let days = $('#chartDays').val();
        
        $.post('api.php', {action: 'get_chart_data', type: type, username: username, days: days}, function(res) {
            if(res.status === 'success') {
                // Destroy existing charts
                if(completedChart) completedChart.destroy();
                if(timeChart) timeChart.destroy();
                
                // Completed chart
                completedChart = new Chart(document.getElementById('completedChart'), {
                    type: 'bar',
                    data: {
                        labels: res.labels,
                        datasets: [{
                            label: 'Completed',
                            data: res.completed,
                            backgroundColor: 'rgba(54, 162, 235, 0.7)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: { y: { beginAtZero: true } }
                    }
                });
                
                // Time chart
                timeChart = new Chart(document.getElementById('timeChart'), {
                    type: 'line',
                    data: {
                        labels: res.labels,
                        datasets: [{
                            label: 'Time (min)',
                            data: res.time,
                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 2,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
        }, 'json');
    }
    
    function loadAvgTimeStats() {
        $.post('api.php', {action: 'get_avg_time_stats'}, function(res) {
            if(res.status === 'success') {
                $('#overallAvgTime').text(res.overall_avg);
                
                if(res.best_performer) {
                    $('#bestPerformerName').text(res.best_performer.username);
                    $('#bestPerformerStats').html(`Avg: <strong>${res.best_performer.avg_time}s</strong> | Records: ${res.best_performer.total_records}`);
                } else {
                    $('#bestPerformerName').text('-');
                    $('#bestPerformerStats').text('No data yet');
                }
                
                let h = '';
                res.user_avgs.forEach((u, i) => {
                    let medal = i === 0 ? '🥇' : (i === 1 ? '🥈' : (i === 2 ? '🥉' : ''));
                    h += `<tr><td>${medal} ${i+1}</td><td><strong>${u.username}</strong><br><small>${u.full_name || ''}</small></td><td>${u.avg_time}</td><td>${u.total_records}</td></tr>`;
                });
                $('#avgTimeRankingTable').html(h || '<tr><td colspan="4">No data</td></tr>');
            }
        }, 'json');
    }
    
    function exportReport(type) {
        let params = {action: 'export_report_csv', report_type: type};
        
        if(type === 'daily') {
            params.date = $('#dailyReportDate').val();
        } else if(type === 'weekly') {
            params.start_date = $('#weeklyStartDate').val();
            params.end_date = $('#weeklyEndDate').val();
        } else {
            params.month = $('#monthlyReportMonth').val();
        }
        
        $.post('api.php', params, function(res) {
            if(res.status === 'success') {
                // Download CSV
                let csvContent = atob(res.content);
                let blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
                let link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = res.filename;
                link.click();
            } else {
                alert(res.message || 'Export failed');
            }
        }, 'json');
    }

    // ==========================================
    // NOTIFICATIONS FUNCTIONS
    // ==========================================
    
    function loadNotificationSettings() {
        // Set default dates
        let today = new Date().toISOString().split('T')[0];
        $('#summaryDate, #summaryDateAll').val(today);
        
        // Load users for dropdowns
        $.post('api.php', {action: 'get_users'}, function(res) {
            let opts = '';
            if(Array.isArray(res)) {
                res.forEach(u => {
                    if(u.role === 'deo') {
                        opts += `<option value="${u.username}">${u.username} - ${u.full_name || ''}</option>`;
                    }
                });
            }
            $('#notifyUser, #summaryUser, #lowProdUser').html(opts);
        }, 'json').fail(function() {
            console.error('Failed to load users');
        });
        
        // Load notification settings
        $.post('api.php', {action: 'get_notification_settings'}, function(res) {
            console.log('Notification settings loaded:', res);
            if(res.status === 'success' && res.data) {
                $('#setAutoDailySummary').prop('checked', res.data.auto_daily_summary === '1');
                $('#setAutoTargetNotify').prop('checked', res.data.auto_target_notification === '1');
                $('#setAdminDailySummary').prop('checked', res.data.admin_daily_summary === '1');
                $('#setLowProdThreshold').val(res.data.low_productivity_threshold || 30);
            } else {
                console.log('Using default notification settings');
                // Set defaults
                $('#setAutoDailySummary').prop('checked', false);
                $('#setAutoTargetNotify').prop('checked', true);
                $('#setAdminDailySummary').prop('checked', false);
                $('#setLowProdThreshold').val(30);
            }
        }, 'json').fail(function(xhr, status, error) {
            console.error('Failed to load notification settings:', status, error);
            // Set defaults on error
            $('#setAutoDailySummary').prop('checked', false);
            $('#setAutoTargetNotify').prop('checked', true);
            $('#setAdminDailySummary').prop('checked', false);
            $('#setLowProdThreshold').val(30);
        });
    }
    
    function toggleNotifyFields() {
        let target = $('#notifyTarget').val();
        $('#notifyUserField, #notifyPhoneField').hide();
        if(target === 'user') $('#notifyUserField').show();
        if(target === 'phone') $('#notifyPhoneField').show();
    }
    
    function sendCustomNotification() {
        let target = $('#notifyTarget').val();
        let data = {
            action: 'send_custom_notification',
            target: target,
            username: $('#notifyUser').val(),
            phone: $('#notifyPhone').val(),
            title: $('#notifyTitle').val(),
            body: $('#notifyBody').val()
        };
        
        if(!data.body) { alert('Please enter a message'); return; }
        
        $.post('api.php', data, function(res) {
            alert(res.message || (res.status === 'success' ? 'Notification sent!' : 'Failed'));
            if(res.status === 'success') {
                $('#notifyTitle, #notifyBody, #notifyPhone').val('');
            }
        }, 'json');
    }
    
    function sendDailySummary() {
        let username = $('#summaryUser').val();
        let date = $('#summaryDate').val();
        
        if(!username) { alert('Select a user'); return; }
        
        $.post('api.php', {action: 'send_daily_summary', username: username, date: date}, function(res) {
            alert(res.message || (res.status === 'success' ? 'Summary sent!' : 'Failed'));
        }, 'json');
    }
    
    function sendDailySummaryAll() {
        if(!confirm('Send daily summary to ALL users?')) return;
        
        let date = $('#summaryDateAll').val();
        $.post('api.php', {action: 'send_daily_summary_all', date: date}, function(res) {
            alert(res.message);
        }, 'json');
    }
    
    function sendAdminSummary() {
        $.post('api.php', {action: 'send_admin_daily_summary', date: new Date().toISOString().split('T')[0]}, function(res) {
            alert(res.message || (res.status === 'success' ? 'Admin summary sent!' : 'Failed'));
        }, 'json');
    }
    
    function sendLowProductivityAlert() {
        let username = $('#lowProdUser').val();
        if(!username) { alert('Select a user'); return; }
        
        // Get admin phone
        $.post('api.php', {action: 'get_users'}, function(users) {
            let admin = users.find(u => u.role === 'admin');
            if(admin && admin.phone) {
                $.post('api.php', {action: 'send_low_productivity_alert', username: username, admin_phone: admin.phone}, function(res) {
                    alert(res.status === 'success' ? 'Alert sent to admin!' : 'Failed to send');
                }, 'json');
            } else {
                alert('Admin phone number not found');
            }
        }, 'json');
    }
    
    function saveNotificationSettings() {
        let data = {
            action: 'update_notification_settings',
            auto_daily_summary: $('#setAutoDailySummary').is(':checked') ? '1' : '0',
            auto_target_notification: $('#setAutoTargetNotify').is(':checked') ? '1' : '0',
            admin_daily_summary: $('#setAdminDailySummary').is(':checked') ? '1' : '0',
            low_productivity_threshold: $('#setLowProdThreshold').val() || '30'
        };
        
        console.log('Saving notification settings:', data);
        
        $.post('api.php', data, function(res) {
            console.log('Save response:', res);
            if(res.status === 'success') {
                alert('✅ Notification settings saved successfully!');
            } else {
                alert('❌ Failed to save: ' + (res.message || 'Unknown error'));
            }
        }, 'json').fail(function(xhr, status, error) {
            console.error('Save failed:', status, error);
            alert('❌ Network error while saving settings');
        });
    }

    // ==========================================
    // DATA MANAGEMENT FUNCTIONS
    // ==========================================
    
    function loadDataStats() {
        // Update selected count
        $('#deleteSelectedCount').text(selectedIds.size);
        
        // Load users for export dropdown
        $.post('api.php', {action: 'get_users'}, function(res) {
            let opts = '<option value="">All Users</option>';
            res.forEach(u => opts += `<option value="${u.username}">${u.username}</option>`);
            $('#exportUser').html(opts);
        }, 'json');
        
        // Load stats
        $.post('api.php', {action: 'get_data_stats'}, function(res) {
            if(res.status === 'success') {
                let d = res.data;
                $('#statTotalRecords').text(d.total_records || 0);
                $('#statPending').text(d.by_status?.pending || 0);
                $('#statDone').text(d.by_status?.done || 0);
                $('#statCompleted').text(d.by_status?.Completed || 0);
                $('#statWithImages').text(d.with_images || 0);
                $('#statDbSize').text((d.db_size_mb || 0) + ' MB');
                
                // Top users
                let h = '';
                (d.by_user || []).forEach(u => {
                    h += `<tr><td>${u.username}</td><td><strong>${u.count}</strong></td></tr>`;
                });
                $('#statTopUsers').html(h || '<tr><td colspan="2" class="text-center">No data</td></tr>');
            }
        }, 'json');
    }
    
    function bulkDeleteByStatus() {
        let status = $('#deleteStatus').val();
        if(!status) { alert('Select a status'); return; }
        if(!confirm(`Delete ALL records with status "${status}"? This cannot be undone!`)) return;
        
        $.post('api.php', {action: 'bulk_delete_records', delete_type: 'status', status_filter: status}, function(res) {
            alert(res.message);
            loadDataStats();
            loadData();
        }, 'json');
    }
    
    function bulkDeleteByDate() {
        let start = $('#deleteStartDate').val();
        let end = $('#deleteEndDate').val();
        if(!start || !end) { alert('Select date range'); return; }
        if(!confirm(`Delete ALL records from ${start} to ${end}? This cannot be undone!`)) return;
        
        $.post('api.php', {action: 'bulk_delete_records', delete_type: 'date_range', start_date: start, end_date: end}, function(res) {
            alert(res.message);
            loadDataStats();
            loadData();
        }, 'json');
    }
    
    function bulkDeleteSelected() {
        let ids = Array.from(selectedIds);
        if(!ids.length) { alert('No records selected'); return; }
        if(!confirm(`Delete ${ids.length} selected records? This cannot be undone!`)) return;
        
        $.post('api.php', {action: 'bulk_delete_records', delete_type: 'selected', ids: ids.join(',')}, function(res) {
            alert(res.message);
            selectedIds.clear();
            loadDataStats();
            loadData();
        }, 'json');
    }
    
    function bulkDeleteAll() {
        let confirm1 = prompt('Type "DELETE ALL" to confirm deletion of ALL records:');
        if(confirm1 !== 'DELETE ALL') { alert('Cancelled'); return; }
        if(!confirm('FINAL WARNING: This will delete ALL data permanently. Are you absolutely sure?')) return;
        
        $.post('api.php', {action: 'bulk_delete_records', delete_type: 'all'}, function(res) {
            alert(res.message);
            loadDataStats();
            loadData();
        }, 'json');
    }
    
    function createBackup() {
        let tables = $('#backupTables').val();
        $.post('api.php', {action: 'backup_database', tables: tables}, function(res) {
            if(res.status === 'success') {
                let content = atob(res.content);
                let blob = new Blob([content], {type: 'text/plain;charset=utf-8;'});
                let link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = res.filename;
                link.click();
                alert('Backup downloaded: ' + res.filename);
            } else {
                alert(res.message || 'Backup failed');
            }
        }, 'json');
    }
    
    function restoreBackup() {
        let file = $('#restoreFile')[0].files[0];
        if(!file) { alert('Select a SQL file'); return; }
        if(!confirm('This will overwrite existing data. Continue?')) return;
        
        let reader = new FileReader();
        reader.onload = function(e) {
            let content = e.target.result;
            $.post('api.php', {action: 'restore_backup', sql_content: content}, function(res) {
                alert(res.message);
                if(res.errors && res.errors.length > 0) {
                    console.log('Restore errors:', res.errors);
                }
                loadDataStats();
                loadData();
            }, 'json');
        };
        reader.readAsText(file);
    }
    
    function checkDuplicates() {
        let field = $('#duplicateField').val();
        $.post('api.php', {action: 'check_duplicates', field: field}, function(res) {
            if(res.status === 'success') {
                $('#duplicateCount').text(res.total);
                let h = '';
                res.data.forEach(d => {
                    h += `<tr>
                        <td><strong>${d[field]}</strong></td>
                        <td><span class="badge bg-warning">${d.count}</span></td>
                        <td><small>${d.ids}</small></td>
                    </tr>`;
                });
                $('#duplicateTable').html(h || '<tr><td colspan="3" class="text-center text-success">No duplicates found!</td></tr>');
            }
        }, 'json');
    }
    
    function deleteDuplicates() {
        let field = $('#duplicateField').val();
        if(!confirm(`Delete all duplicate records by "${field}"? Only the first record will be kept.`)) return;
        
        $.post('api.php', {action: 'delete_duplicates', field: field}, function(res) {
            alert(res.message);
            checkDuplicates();
            loadDataStats();
            loadData();
        }, 'json');
    }
    
    function exportRecords() {
        let data = {
            action: 'export_records_csv',
            status_filter: $('#exportStatus').val(),
            username_filter: $('#exportUser').val(),
            start_date: $('#exportStartDate').val(),
            end_date: $('#exportEndDate').val()
        };
        
        $.post('api.php', data, function(res) {
            if(res.status === 'success') {
                let content = atob(res.content);
                let blob = new Blob([content], {type: 'text/csv;charset=utf-8;'});
                let link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = res.filename;
                link.click();
                showToast(`Exported ${res.count} records to ${res.filename}`, 'success');
            } else {
                showToast(res.message || 'Export failed', 'error');
            }
        }, 'json');
    }

    // ==========================================
    // UI/UX IMPROVEMENTS - PHASE 6
    // ==========================================
    
    // Toast Notification System
    function showToast(message, type = 'info', duration = 4000) {
        let toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        let icon = type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️';
        toast.innerHTML = `<span>${icon}</span><span>${message}</span>`;
        document.getElementById('toastContainer').appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
    
    // Progress Bar
    function showProgress(percent) {
        let bar = document.getElementById('progressBar');
        let fill = bar.querySelector('.progress-fill');
        bar.style.display = 'block';
        fill.style.width = percent + '%';
        if(percent >= 100) {
            setTimeout(() => {
                bar.style.display = 'none';
                fill.style.width = '0%';
            }, 500);
        }
    }
    
    // Real-time Clock
    function updateClock() {
        let now = new Date();
        let time = now.toLocaleTimeString('en-IN', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
        let date = now.toLocaleDateString('en-IN', {day: '2-digit', month: 'short'});
        document.getElementById('realTimeClock').innerHTML = `📅 ${date} 🕐 ${time}`;
    }
    setInterval(updateClock, 1000);
    updateClock();
    
    // Keyboard Shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S - Save
        if(e.ctrlKey && e.key === 's') {
            e.preventDefault();
            if(activeRowId) {
                // Trigger save for active row
                let saveBtn = document.querySelector(`tr[data-id="${activeRowId}"] .btn-success`);
                if(saveBtn) saveBtn.click();
            }
        }
        
        // Ctrl+D - Mark Done
        if(e.ctrlKey && e.key === 'd') {
            e.preventDefault();
            if(activeRowId) {
                let doneBtn = document.querySelector(`tr[data-id="${activeRowId}"] .btn-warning`);
                if(doneBtn) doneBtn.click();
            }
        }
        
        // Ctrl+I - Open Image
        if(e.ctrlKey && e.key === 'i') {
            e.preventDefault();
            if(activeRowId) {
                let row = allClientData.find(r => r.id == activeRowId);
                if(row) openImage(row.record_no);
            }
        }
        
        // Ctrl+F - Focus Search
        if(e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
        }
        
        // Ctrl+T - Toggle Theme
        if(e.ctrlKey && e.key === 't') {
            e.preventDefault();
            toggleTheme();
        }
    });
    
    // Enhanced Alert Replacement
    const originalAlert = window.alert;
    window.alert = function(message) {
        if(message.toLowerCase().includes('success') || message.toLowerCase().includes('done') || message.toLowerCase().includes('saved')) {
            showToast(message, 'success');
        } else if(message.toLowerCase().includes('error') || message.toLowerCase().includes('fail')) {
            showToast(message, 'error');
        } else if(message.toLowerCase().includes('warning') || message.toLowerCase().includes('caution')) {
            showToast(message, 'warning');
        } else {
            showToast(message, 'info');
        }
    };
    
    // Search Highlight Function
    function highlightSearch(text, search) {
        if(!search) return text;
        let regex = new RegExp(`(${search})`, 'gi');
        return text.replace(regex, '<span class="search-highlight">$1</span>');
    }
    
    // Double-click to copy cell content
    document.addEventListener('dblclick', function(e) {
        if(e.target.tagName === 'TD' && !e.target.isContentEditable) {
            let text = e.target.textContent;
            navigator.clipboard.writeText(text).then(() => {
                showToast('Copied: ' + text.substring(0, 30) + (text.length > 30 ? '...' : ''), 'success', 2000);
            });
        }
    });
    
    // Add loading state to buttons
    function setButtonLoading(btn, loading) {
        if(loading) {
            btn.dataset.originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-sm"></span>';
            btn.disabled = true;
        } else {
            btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
            btn.disabled = false;
        }
    }
    
    // Confirmation Dialog Enhancement
    function confirmAction(message, callback) {
        if(confirm(message)) {
            callback();
        }
    }
    
    // Session Activity Tracker
    let lastActivity = Date.now();
    document.addEventListener('mousemove', () => lastActivity = Date.now());
    document.addEventListener('keypress', () => lastActivity = Date.now());
    
    // Warn before session timeout (25 min warning if session is 30 min)
    setInterval(() => {
        let inactiveTime = (Date.now() - lastActivity) / 1000 / 60; // minutes
        if(inactiveTime >= 25 && inactiveTime < 26) {
            showToast('⚠️ Session will expire in 5 minutes due to inactivity', 'warning', 10000);
        }
    }, 60000);
    
    // Page Visibility API - Refresh on tab focus
    document.addEventListener('visibilitychange', function() {
        if(!document.hidden) {
            // Tab became visible, check if data needs refresh
            let lastRefresh = window.lastDataRefresh || 0;
            if(Date.now() - lastRefresh > 60000) { // More than 1 minute
                loadData();
                showToast('Data refreshed', 'info', 2000);
            }
        }
    });
    
    // Mark last refresh time
    let originalLoadData = window.loadData;
    if(typeof loadData === 'function') {
        window.loadData = function() {
            window.lastDataRefresh = Date.now();
            return originalLoadData.apply(this, arguments);
        };
    }

    // ==========================================
    // SYSTEM SETTINGS FUNCTIONS - PHASE 7
    // ==========================================
    
    function loadSystemSettings() {
        // Load settings
        $.post('api.php', {action: 'get_system_settings'}, function(res) {
            console.log('Loaded settings:', res);
            if(res.status === 'success') {
                let s = res.data;
                $('#setCompanyName').val(s.company_name || 'BPO Dashboard');
                $('#setDefaultTarget').val(s.default_daily_target || 100);
                let rpp = s.records_per_page || 999999;
                if (rpp >= 999999 || rpp == 0) {
                    $('#setRecordsPerPage').val('999999');
                    itemsPerPage = 999999;
                } else {
                    $('#setRecordsPerPage').val(rpp);
                    itemsPerPage = parseInt(rpp);
                }
                $('#setWorkStart').val(s.working_hours_start || '09:00');
                $('#setWorkEnd').val(s.working_hours_end || '18:00');
                $('#setTimezone').val(s.timezone || 'Asia/Kolkata');
                $('#setWhatsappEnabled').prop('checked', s.whatsapp_notifications === '1');
                $('#setEmailEnabled').prop('checked', s.email_notifications === '1');
                $('#setAutoLogout').prop('checked', s.auto_logout_enabled === '1');
                $('#setOcrEnabled').prop('checked', s.enable_ocr === '1');
                $('#setImageEditing').prop('checked', s.enable_image_editing === '1');
                $('#setShowCompleted').prop('checked', s.show_completed_records === '1');
                $('#setDateFormat').val(s.date_format || 'd-m-Y');
                $('#setTimeFormat').val(s.time_format || 'h:i A');
                $('#setAutoRefresh').val(s.auto_refresh_interval || 60);
                $('#setThemeColorPicker').val(s.theme_color || '#0d6efd');
                $('#setMaintenanceMode').prop('checked', s.maintenance_mode === '1');
                $('#setMaintenanceMsg').val(s.maintenance_message || 'System is under maintenance.');
                $('#setDataRetention').val(s.data_retention_days || 365);
                $('#appVersion').text(s.version || '2.0.0');
                
                // Master OTP settings
                $('#setMasterOtp').val(s.master_otp || '');
                $('#setMasterOtpEnabled').prop('checked', s.master_otp_enabled === '1');
                
                // WhatsApp API settings
                $('#setWhatsappApiUrl').val(s.whatsapp_api_url || '');
                $('#setWhatsappApiKey').val(s.whatsapp_api_key || '');
                $('#setWhatsappInstanceId').val(s.whatsapp_instance_id || '');
            } else {
                showToast('Error loading settings: ' + (res.message || 'Unknown error'), 'error');
            }
        }, 'json').fail(function(xhr, status, error) {
            console.error('Failed to load settings:', status, error);
            showToast('Failed to load settings', 'error');
        });
        
        // Load system info
        loadSystemInfo();
    }
    
    function loadSystemInfo() {
        $.post('api.php', {action: 'get_system_info'}, function(res) {
            if(res.status === 'success') {
                let i = res.data;
                $('#infoPhp').text(i.php_version);
                $('#infoMysql').text(i.mysql_version);
                $('#infoServer').text(i.server_software);
                $('#infoTime').text(i.server_time);
                $('#infoTimezone').text(i.timezone);
                $('#infoUpload').text(i.max_upload_size);
                $('#infoPost').text(i.max_post_size);
                $('#infoMemory').text(i.memory_limit);
                $('#infoDiskFree').text(i.disk_free_space);
                $('#infoDiskTotal').text(i.disk_total_space);
                $('#infoUsers').text(i.total_users);
                $('#infoRecords').text(i.total_records);
                $('#infoLogs').text(i.total_logs);
            }
        }, 'json');
    }
    
    // Generate random 6-digit OTP
    function generateRandomOtp() {
        let otp = Math.floor(100000 + Math.random() * 900000);
        $('#setMasterOtp').val(otp);
    }
    
    // Save Master OTP
    function saveMasterOtp() {
        let otp = $('#setMasterOtp').val();
        let enabled = $('#setMasterOtpEnabled').is(':checked') ? '1' : '0';
        
        if(!otp || otp.length !== 6 || isNaN(otp)) {
            showToast('Master OTP must be 6 digits', 'error');
            return;
        }
        
        $.post('api.php', {
            action: 'update_system_settings_batch', 
            settings: JSON.stringify({
                master_otp: otp,
                master_otp_enabled: enabled
            })
        }, function(res) {
            showToast('Master OTP saved: ' + otp, res.status === 'success' ? 'success' : 'error');
        }, 'json');
    }
    
    // Save WhatsApp API Settings
    function saveWhatsappSettings() {
        let settings = {
            whatsapp_api_url: $('#setWhatsappApiUrl').val(),
            whatsapp_api_key: $('#setWhatsappApiKey').val(),
            whatsapp_instance_id: $('#setWhatsappInstanceId').val()
        };
        
        $.post('api.php', {action: 'update_system_settings_batch', settings: JSON.stringify(settings)}, function(res) {
            showToast('WhatsApp settings saved!', res.status === 'success' ? 'success' : 'error');
        }, 'json');
    }
    
    // Test WhatsApp API
    function testWhatsappApi() {
        let phone = prompt('Enter phone number to test (with country code, e.g., 919876543210):');
        if(!phone) return;
        
        $.post('api.php', {action: 'test_whatsapp_api', phone: phone}, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
        }, 'json');
    }
    
    function saveGeneralSettings() {
        let settings = {
            company_name: $('#setCompanyName').val(),
            default_daily_target: $('#setDefaultTarget').val(),
            records_per_page: parseInt($('#setRecordsPerPage').val()) || 999999,
            working_hours_start: $('#setWorkStart').val(),
            working_hours_end: $('#setWorkEnd').val(),
            timezone: $('#setTimezone').val(),
            whatsapp_notifications: $('#setWhatsappEnabled').is(':checked') ? '1' : '0',
            email_notifications: $('#setEmailEnabled').is(':checked') ? '1' : '0',
            auto_logout_enabled: $('#setAutoLogout').is(':checked') ? '1' : '0',
            enable_ocr: $('#setOcrEnabled').is(':checked') ? '1' : '0',
            enable_image_editing: $('#setImageEditing').is(':checked') ? '1' : '0',
            show_completed_records: $('#setShowCompleted').is(':checked') ? '1' : '0'
        };
        
        console.log('Saving settings:', settings);
        
        $.post('api.php', {action: 'update_system_settings_batch', settings: JSON.stringify(settings)}, function(res) {
            console.log('Response:', res);
            if(res.status === 'success') {
                showToast(res.message || 'Settings saved!', 'success');
            } else {
                showToast(res.message || 'Error saving settings!', 'error');
            }
        }, 'json').fail(function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            showToast('Failed to save settings: ' + error, 'error');
        });
    }
    
    function saveDisplaySettings() {
        let settings = {
            date_format: $('#setDateFormat').val(),
            time_format: $('#setTimeFormat').val(),
            auto_refresh_interval: $('#setAutoRefresh').val(),
            theme_color: $('#setThemeColorPicker').val()
        };
        
        $.post('api.php', {action: 'update_system_settings_batch', settings: JSON.stringify(settings)}, function(res) {
            showToast(res.message || 'Display settings saved!', res.status === 'success' ? 'success' : 'error');
            // Apply theme color immediately
            document.documentElement.style.setProperty('--primary', settings.theme_color);
            $('th').css('background-color', settings.theme_color);
        }, 'json');
    }
    
    function setThemeColor(color) {
        $('#setThemeColorPicker').val(color);
        document.documentElement.style.setProperty('--primary', color);
        // Update header color
        $('th').css('background-color', color + '!important');
    }
    
    function saveDataRetention() {
        let settings = {
            data_retention_days: $('#setDataRetention').val()
        };
        
        $.post('api.php', {action: 'update_system_settings_batch', settings: JSON.stringify(settings)}, function(res) {
            showToast('Data retention saved!', res.status === 'success' ? 'success' : 'error');
        }, 'json');
    }
    
    function toggleMaintenanceMode() {
        let enabled = $('#setMaintenanceMode').is(':checked') ? '1' : '0';
        let message = $('#setMaintenanceMsg').val();
        
        if(enabled === '1' && !confirm('Enable maintenance mode? Non-admin users will not be able to access the system.')) {
            $('#setMaintenanceMode').prop('checked', false);
            return;
        }
        
        $.post('api.php', {action: 'toggle_maintenance_mode', enabled: enabled, message: message}, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
        }, 'json');
    }
    
    function cleanOldLogs() {
        let days = $('#cleanLogsDays').val();
        if(!confirm(`Delete all logs older than ${days} days? This cannot be undone.`)) return;
        
        $.post('api.php', {action: 'clean_old_logs', days: days}, function(res) {
            showToast(res.message, res.status === 'success' ? 'success' : 'error');
            loadSystemInfo();
        }, 'json');
    }
    
    function checkForUpdates() {
        $.post('api.php', {action: 'check_updates'}, function(res) {
            if(res.status === 'success') {
                if(res.update_available) {
                    showToast(`Update available! Current: ${res.current_version}, Latest: ${res.latest_version}`, 'warning', 10000);
                } else {
                    showToast(res.message, 'success');
                }
            }
        }, 'json');
    }
    // ========== AUTO ASSIGNMENT FEATURE ==========

let allDEOsList = [];

function toggleAutoAssign() {
    let enabled = $('#enableAutoAssign').is(':checked');
    if (enabled) {
        $('#deoSelectionDiv').slideDown(300);
        loadDEOsForAssignment();
    } else {
        $('#deoSelectionDiv').slideUp(300);
        $('#assignmentPreview').hide();
    }
}

function loadDEOsForAssignment() {
    $('#deoCheckboxList').html('<p class="text-muted text-center">Loading DEOs...</p>');
    
    $.post('api.php', {action: 'get_deo_list'}, function(res) {
        if (res.status === 'success' && res.deos && res.deos.length > 0) {
            allDEOsList = res.deos;
            let html = '';
            res.deos.forEach(deo => {
                html += `
                    <div class="form-check mb-2">
                        <input class="form-check-input deo-checkbox" type="checkbox" 
                               value="${deo.username}" id="deo_${deo.id}" 
                               onchange="previewAssignment()">
                        <label class="form-check-label" for="deo_${deo.id}" style="cursor:pointer;">
                            <strong>${deo.full_name}</strong> <span class="text-muted">(${deo.username})</span>
                        </label>
                    </div>
                `;
            });
            $('#deoCheckboxList').html(html);
        } else {
            $('#deoCheckboxList').html('<p class="text-danger text-center">No DEOs found</p>');
        }
    }, 'json').fail(function() {
        $('#deoCheckboxList').html('<p class="text-danger text-center">Error loading DEOs</p>');
    });
}

function selectAllDEOs(select) {
    $('.deo-checkbox').prop('checked', select);
    previewAssignment();
}

function getSelectedDEOs() {
    let selected = [];
    $('.deo-checkbox:checked').each(function() {
        selected.push($(this).val());
    });
    return selected;
}

function previewAssignment() {
    if (!$('#enableAutoAssign').is(':checked')) return;
    
    let file = $('#mainExcel')[0].files[0];
    let selectedDEOs = getSelectedDEOs();
    
    if (!file || selectedDEOs.length === 0) {
        $('#assignmentPreview').hide();
        return;
    }
    
    // Quick preview - count records in Excel
    let reader = new FileReader();
    reader.onload = function(e) {
        try {
            let workbook = XLSX.read(new Uint8Array(e.target.result), {type: 'array'});
            let data = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]]);
            let totalRecords = data.length;
            let deoCount = selectedDEOs.length;
            let perDEO = Math.ceil(totalRecords / deoCount);
            
            let html = `
                <strong>📊 Distribution Preview:</strong><br>
                Total Records: <strong>${totalRecords}</strong><br>
                Selected DEOs: <strong>${deoCount}</strong><br>
                Records per DEO: <strong>~${perDEO}</strong><br>
                <small class="text-muted">Each DEO will get approximately ${perDEO} records</small>
            `;
            $('#assignmentPreview').html(html).slideDown();
        } catch(e) {
            $('#assignmentPreview').hide();
        }
    };
    reader.readAsArrayBuffer(file);
}

// Modified uploadMainExcel function
function uploadMainExcel() {
    let file = $('#mainExcel')[0].files[0];
    if(!file) { 
        showToast('Please select a file first', 'warning'); 
        return; 
    }
    
    let autoAssign = $('#enableAutoAssign').is(':checked');
    let selectedDEOs = autoAssign ? getSelectedDEOs() : [];
    
    if (autoAssign && selectedDEOs.length === 0) {
        showToast('Please select at least one DEO for auto-assignment', 'warning');
        return;
    }
    
    // ✅ Message Change: Ab user ko darane wala warning nahi dikhega
    let confirmMsg = '🚀 Upload shuru karein? Naya data add hoga, duplicates skip honge.';
    if (autoAssign) {
        confirmMsg += `\n\n✅ Auto-Assignment Enabled\nSelected DEOs: ${selectedDEOs.length}\nRecords will be distributed equally.`;
    }
    
    if(!confirm(confirmMsg)) return;
    
    parseAndUploadWithAssign({target: {files: [file]}}, 'upload_main_data', selectedDEOs);
}

// Function to handle chunked upload
function parseAndUploadWithAssign(e, act, deos) {
    $('#loader').show();
    $('#progressBar').show();
    $('#progressBar .progress-fill').css('width', '0%');

    // ❌ Maine yahan se wo "WARNING: This will replace ALL existing data" wala block hata diya hai.
    // Ab ye seedha upload process shuru karega.

    let r = new FileReader();
    r.onload = ev => {
        try {
            let workbook = XLSX.read(new Uint8Array(ev.target.result), {type: 'array'});
            let sheet = workbook.Sheets[workbook.SheetNames[0]];
            
            // Read data
            let data = XLSX.utils.sheet_to_json(sheet, {defval: '', blankrows: false});
            
            // Handle headerless files fallback
            if (data.length === 0) {
                data = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: '', blankrows: false});
                if (data.length > 0) data.shift(); // Remove header
                data = data.map((row, index) => {
                    let obj = {};
                    row.forEach((val, colIdx) => { obj['column' + (colIdx + 1)] = val; });
                    return obj;
                });
            }

            if (data.length === 0) throw new Error('No data found in Excel');

            // Apply Auto-Assignment logic if DEOs selected
            if (deos && deos.length > 0) {
                data = autoAssignToDEOs(data, deos);
            }
            
            let totalRecords = data.length;
            let totalChunks = Math.ceil(totalRecords / 50);
            let completedChunks = 0;
            let totalInserted = 0;
            
            // Recursive function to process chunks
            function processChunk(i) {
                let c = data.slice(i, i + 50);
                
                // If done
                if (c.length === 0) {
                    $('#loader').hide();
                    $('#progressBar').hide();
                    showToast(`✅ Success! Uploaded ${totalInserted} of ${totalRecords} records.`, 'success');
                    loadData(); // Refresh table
                    return;
                }
                
                // Send Chunk to API
                $.post('api.php', {
                    action: act,
                    jsonData: JSON.stringify(c),
                    is_first_chunk: (i === 0) // Pass TRUE only for the very first chunk
                })
                .done(function(res) {
                    if(res.status === 'success') {
                        completedChunks++;
                        totalInserted += (res.inserted || 0);
                        
                        // Update Progress Bar
                        let percent = Math.round((completedChunks / totalChunks) * 100);
                        $('#progressBar .progress-fill').css('width', percent + '%');
                        
                        // Process Next Chunk
                        processChunk(i + 50);
                    } else {
                        $('#loader').hide();
                        alert('Upload Error at row ' + i + ': ' + res.message);
                    }
                })
                .fail(function(xhr) {
                    $('#loader').hide();
                    alert('Network Error at chunk ' + i);
                });
            }
            
            // Start processing from index 0
            processChunk(0);

        } catch (err) {
            $('#loader').hide();
            $('#progressBar').hide();
            alert('Excel Error: ' + err.message);
        }
    };
    r.readAsArrayBuffer(e.target.files[0]);
}

function autoAssignToDEOs(records, deos) {
    let totalRecords = records.length;
    let deoCount = deos.length;
    let recordsPerDEO = Math.ceil(totalRecords / deoCount);
    
    console.log('=== AUTO ASSIGNMENT ===');
    console.log(`Total Records: ${totalRecords}`);
    console.log(`DEOs: ${deoCount}`);
    console.log(`Records per DEO: ${recordsPerDEO}`);
    
    let assigned = [];
    let deoAssignmentCount = {};
    
    // Initialize counts
    deos.forEach(deo => {
        deoAssignmentCount[deo] = 0;
    });
    
    // Distribute records
    records.forEach((record, index) => {
        let deoIndex = Math.floor(index / recordsPerDEO);
        
        // Make sure we don't exceed DEO count
        if (deoIndex >= deoCount) {
            deoIndex = deoCount - 1; // Last DEO gets remaining records
        }
        
        let assignedDEO = deos[deoIndex];
        record.assigned_to = assignedDEO;
        record.username = assignedDEO; // Also set username for compatibility
        
        deoAssignmentCount[assignedDEO]++;
        assigned.push(record);
    });
    
    // Log assignment summary
    console.log('Assignment Summary:');
    Object.keys(deoAssignmentCount).forEach(deo => {
        console.log(`  ${deo}: ${deoAssignmentCount[deo]} records`);
    });
    
    // Show toast with summary
    let summaryText = `✅ Auto-Assignment Complete!\n\n`;
    Object.keys(deoAssignmentCount).forEach(deo => {
        summaryText += `${deo}: ${deoAssignmentCount[deo]} records\n`;
    });
    console.log(summaryText);
    
    return assigned;
}

// ========== DATABASE SETTINGS FEATURE ==========

function openDatabaseSettings() {
    // Load current configuration
    $.post('api.php', {action: 'get_db_config'}, function(res) {
        if (res.status === 'success') {
            $('#db_host').val(res.config.host || 'localhost');
            $('#db_name').val(res.config.database || '');
            $('#db_user').val(res.config.username || '');
            $('#db_pass').val(''); // Don't show password
        }
    }, 'json');
    
    new bootstrap.Modal($('#dbSettingsModal')).show();
}

function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const icon = input.nextElementSibling.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function testDbConnection() {
    const host = $('#db_host').val();
    const user = $('#db_user').val();
    const pass = $('#db_pass').val();
    const db = $('#db_name').val();
    
    $('#dbConnectionResult').html('<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Testing connection...</div>');
    
    $.post('api.php', {
        action: 'test_db_connection',
        host: host,
        username: user,
        password: pass,
        database: db
    }, function(res) {
        if (res.status === 'success') {
            $('#dbConnectionResult').html(`
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <strong>Connection Successful!</strong><br>
                    <small>
                        <strong>Database:</strong> <code>${res.database}</code><br>
                        <strong>Server:</strong> <code>${res.server}</code><br>
                        <strong>Version:</strong> <code>${res.version}</code>
                    </small>
                </div>
            `);
        } else {
            $('#dbConnectionResult').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i> <strong>Connection Failed!</strong><br>
                    <small>${res.message}</small>
                </div>
            `);
        }
    }, 'json').fail(function() {
        $('#dbConnectionResult').html('<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Request failed. Check server.</div>');
    });
}

function saveDbSettings() {
    const host = $('#db_host').val();
    const user = $('#db_user').val();
    const pass = $('#db_pass').val();
    const db = $('#db_name').val();
    
    if (!host || !user || !db) {
        showToast('Please fill Host, Username and Database name', 'warning');
        return;
    }
    
    if (!confirm('⚠️ Changing database settings will:\n\n1. Test the new connection first\n2. Create a backup of current config\n3. Update db_connect.php\n\nContinue?')) {
        return;
    }
    
    $('#dbConnectionResult').html('<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Saving configuration...</div>');
    
    $.post('api.php', {
        action: 'save_db_config',
        host: host,
        username: user,
        password: pass,
        database: db
    }, function(res) {
        if (res.status === 'success') {
            $('#dbConnectionResult').html(`
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <strong>Configuration Saved!</strong><br>
                    <small>${res.message}</small>
                </div>
            `);
            showToast('Database configuration saved!', 'success');
        } else {
            $('#dbConnectionResult').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i> <strong>Save Failed!</strong><br>
                    <small>${res.message}</small>
                </div>
            `);
            showToast('Failed to save: ' + res.message, 'error');
        }
    }, 'json').fail(function() {
        $('#dbConnectionResult').html('<div class="alert alert-danger">Request failed</div>');
    });
}

function checkTables() {
    $('#dbTablesResult').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Checking tables...</div>');
    
    $.post('api.php', {action: 'check_tables'}, function(res) {
        if (res.status === 'success') {
            let html = `
                <div class="alert alert-${res.summary.missing > 0 ? 'warning' : 'success'} py-2">
                    <strong>Summary:</strong> 
                    <span class="badge bg-success">${res.summary.existing} Exists</span>
                    <span class="badge bg-danger">${res.summary.missing} Missing</span>
                    <span class="badge bg-secondary">${res.summary.total} Total</span>
                </div>
                <div class="table-status-grid">
                    <table class="table table-sm table-bordered table-hover mb-0">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th>Table Name</th>
                                <th style="width:80px;">Status</th>
                                <th style="width:80px;">Rows</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            // Group tables by category
            const categories = {
                'Core': ['users', 'client_records', 'assignments', 'work_logs', 'record_image_map'],
                'Security': ['activity_logs', 'admin_logs', 'audit_trail', 'login_attempts', 'login_history', 'security_settings', 'allowed_ips', 'user_sessions'],
                'DQC': ['dqc_flags', 'critical_errors', 'field_errors'],
                'Communication': ['announcements', 'announcement_reads', 'chat_messages', 'admin_messages', 'broadcast_messages', 'notifications', 'record_comments', 'record_discussions'],
                'User Features': ['user_stats', 'user_badges', 'badges', 'user_preferences', 'daily_targets', 'deo_progress'],
                'System': ['system_settings', 'backup_logs', 'performance_logs', 'saved_filters', 'reply_templates']
            };
            
            for (const [category, tableNames] of Object.entries(categories)) {
                html += `<tr class="table-secondary"><td colspan="3" class="fw-bold">${category}</td></tr>`;
                
                tableNames.forEach(tableName => {
                    const table = res.tables.find(t => t.name === tableName);
                    if (table) {
                        const badge = table.exists 
                            ? '<span class="badge bg-success">✓</span>' 
                            : '<span class="badge bg-danger">✗</span>';
                        const rows = table.exists ? table.rows.toLocaleString() : '-';
                        html += `<tr><td><code>${table.name}</code></td><td class="text-center">${badge}</td><td class="text-end">${rows}</td></tr>`;
                    }
                });
            }
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            $('#dbTablesResult').html(html);
        } else {
            $('#dbTablesResult').html(`<div class="alert alert-danger">Error: ${res.message}</div>`);
        }
    }, 'json').fail(function() {
        $('#dbTablesResult').html('<div class="alert alert-danger">Request failed</div>');
    });
}

function createAllTables() {
    if (!confirm('⚠️ This will create ALL missing tables for both Project 1 and Project 2.\n\nExisting tables will NOT be modified.\n\nThis includes:\n- Core tables (users, client_records, etc.)\n- Security tables (login_attempts, audit_trail, etc.)\n- Communication tables (chat_messages, announcements, etc.)\n- User feature tables (badges, user_stats, etc.)\n\nContinue?')) {
        return;
    }
    
    $('#dbTablesResult').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Creating tables...</div>');
    
    $.post('api.php', {action: 'create_tables'}, function(res) {
        if (res.status === 'success' || res.status === 'partial') {
            let html = `
                <div class="alert alert-${res.status === 'success' ? 'success' : 'warning'}">
                    <h6 class="alert-heading"><i class="fas fa-${res.status === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i> Operation Complete</h6>
            `;
            
            if (res.created && res.created.length > 0) {
                html += `<p class="mb-1"><strong>Created (${res.created.length}):</strong><br><small class="text-success">${res.created.join(', ')}</small></p>`;
            }
            
            if (res.skipped && res.skipped.length > 0) {
                html += `<p class="mb-1"><strong>Already Exist (${res.skipped.length}):</strong><br><small class="text-muted">${res.skipped.join(', ')}</small></p>`;
            }
            
            if (res.errors && res.errors.length > 0) {
                html += `<p class="mb-0"><strong>Errors:</strong><br><small class="text-danger">${res.errors.join('<br>')}</small></p>`;
            }
            
            html += '</div>';
            $('#dbTablesResult').html(html);
            
            showToast(res.message, res.status === 'success' ? 'success' : 'warning');
            
            // Refresh table list after 1 second
            setTimeout(() => checkTables(), 1000);
        } else {
            $('#dbTablesResult').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i> <strong>Error!</strong><br>
                    ${res.message}
                </div>
            `);
            showToast('Error creating tables', 'error');
        }
    }, 'json').fail(function() {
        $('#dbTablesResult').html('<div class="alert alert-danger">Request failed</div>');
    });
}

function createViews() {
    if (!confirm('This will create/update database views:\n\n- main_data (for Project 1)\n- records (for Project 2)\n\nExisting views will be replaced.\n\nContinue?')) {
        return;
    }
    
    $('#viewsResult').html('<small class="text-info"><i class="fas fa-spinner fa-spin"></i> Creating...</small>');
    
    $.post('api.php', {action: 'create_views'}, function(res) {
        if (res.status === 'success') {
            $('#viewsResult').html(`
                <small class="text-success">
                    <i class="fas fa-check"></i> Views created: ${res.created.join(', ')}
                </small>
            `);
            showToast('Views created successfully!', 'success');
        } else {
            let errorMsg = res.errors ? res.errors.join(', ') : res.message;
            $('#viewsResult').html(`<small class="text-danger"><i class="fas fa-times"></i> ${errorMsg}</small>`);
            showToast('Error creating views', 'error');
        }
    }, 'json').fail(function() {
        $('#viewsResult').html('<small class="text-danger">Request failed</small>');
    });
}

function createTriggers() {
    if (!confirm('This will create/update database triggers:\n\n- auto_create_work_log\n- sync_assigned_to_id\n- sync_assigned_to_id_insert\n\nExisting triggers will be replaced.\n\nContinue?')) {
        return;
    }
    
    $('#triggersResult').html('<small class="text-info"><i class="fas fa-spinner fa-spin"></i> Creating...</small>');
    
    $.post('api.php', {action: 'create_triggers'}, function(res) {
        if (res.status === 'success') {
            $('#triggersResult').html(`
                <small class="text-success">
                    <i class="fas fa-check"></i> Triggers created: ${res.created.join(', ')}
                </small>
            `);
            showToast('Triggers created successfully!', 'success');
        } else {
            let errorMsg = res.errors ? res.errors.join(', ') : res.message;
            $('#triggersResult').html(`<small class="text-danger"><i class="fas fa-times"></i> ${errorMsg}</small>`);
            showToast('Error creating triggers', 'error');
        }
    }, 'json').fail(function() {
        $('#triggersResult').html('<small class="text-danger">Request failed</small>');
    });
}

// Legacy function for backward compatibility
function createTables() {
    createAllTables();
}

// ========== END DATABASE SETTINGS ==========

// ========== EDIT RECORD NUMBER FUNCTIONS ==========

function openEditRecordModal(id, recordNo) {
    $('#editRecordId').val(id);
    $('#editOldRecordNo').val(recordNo);
    $('#editOldRecordNoDisplay').val(recordNo);
    $('#editNewRecordNo').val(recordNo);
    
    // Close context menu if open
    $('.record-context-menu').remove();
    
    // Check if record_no is non-numeric
    if (!/^[0-9]+$/.test(recordNo)) {
        $('#recordNoWarning').show();
        // Try to extract numeric part for suggestion
        let numericPart = recordNo.replace(/[^0-9]/g, '');
        if (numericPart) {
            $('#editNewRecordNo').val(numericPart);
        }
    } else {
        $('#recordNoWarning').hide();
    }
    
    $('#editRecordModal').modal('show');
}

function saveRecordNo() {
    let id = $('#editRecordId').val();
    let oldRecordNo = $('#editOldRecordNo').val();
    let newRecordNo = $('#editNewRecordNo').val().trim();
    
    if (!newRecordNo) {
        alert('Record number cannot be empty');
        return;
    }
    
    if (!/^[0-9]+$/.test(newRecordNo)) {
        alert('Record number must contain only digits (0-9)');
        return;
    }
    
    $.post('api.php', {
        action: 'edit_record_no',
        id: id,
        old_record_no: oldRecordNo,
        new_record_no: newRecordNo
    }, function(res) {
        if (res.status === 'success') {
            alert('✅ ' + res.message);
            $('#editRecordModal').modal('hide');
            loadData(); // Reload table
        } else {
            alert('❌ ' + res.message);
        }
    }, 'json');
}

function toggleInvalidRecord(id, markInvalid) {
    $.post('api.php', {
        action: 'toggle_invalid_record',
        id: id,
        mark_invalid: markInvalid
    }, function(res) {
        if (res.status === 'success') {
            alert('✅ ' + res.message);
            loadData();
        } else {
            alert('❌ ' + res.message);
        }
    }, 'json');
}

function autoMarkInvalidRecords() {
    if (!confirm('This will scan all records and mark those with non-numeric record numbers as invalid.\n\nContinue?')) {
        return;
    }
    
    $.post('api.php', {action: 'auto_mark_invalid_records'}, function(res) {
        if (res.status === 'success') {
            alert('✅ ' + res.message);
            loadData();
        } else {
            alert('❌ ' + res.message);
        }
    }, 'json');
}

// ========== END EDIT RECORD NUMBER FUNCTIONS ==========

// ========== IMAGE LOOKUP FUNCTIONS ==========

// Download sample Excel for Image Lookup
function downloadSampleLookup() {
    const sampleData = [
        ['Record_No', 'Image_Name'],
        ['1001', ''],
        ['1002', ''],
        ['1003', '']
    ];
    
    const ws = XLSX.utils.aoa_to_sheet(sampleData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'ImageLookup');
    
    // Set column widths
    ws['!cols'] = [{wch: 15}, {wch: 40}];
    
    XLSX.writeFile(wb, 'Image_Lookup_Sample.xlsx');
}

// Process Image Lookup Excel
function processImageLookup() {
    const fileInput = document.getElementById('imageLookupFile');
    const statusDiv = document.getElementById('imageLookupStatus');
    const resultDiv = document.getElementById('imageLookupResult');
    const progressBar = document.getElementById('imageLookupProgress');
    const messageEl = document.getElementById('imageLookupMessage');
    
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('❌ Please select an Excel file first!');
        return;
    }
    
    const file = fileInput.files[0];
    statusDiv.style.display = 'block';
    resultDiv.style.display = 'none';
    progressBar.style.width = '10%';
    progressBar.textContent = '10%';
    messageEl.textContent = '📖 Reading Excel file...';
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            progressBar.style.width = '30%';
            progressBar.textContent = '30%';
            messageEl.textContent = '📊 Parsing data...';
            
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[sheetName];
            
            // Get all data as array of arrays
            const jsonData = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
            
            if (jsonData.length === 0) {
                alert('❌ Excel file is empty!');
                statusDiv.style.display = 'none';
                return;
            }
            
            // Extract record numbers from Column A (skip header if present)
            const recordNumbers = [];
            const startRow = (jsonData[0] && isNaN(jsonData[0][0])) ? 1 : 0; // Skip header row
            
            for (let i = startRow; i < jsonData.length; i++) {
                if (jsonData[i] && jsonData[i][0] !== undefined && jsonData[i][0] !== '') {
                    recordNumbers.push(String(jsonData[i][0]).trim());
                }
            }
            
            if (recordNumbers.length === 0) {
                alert('❌ No record numbers found in Column A!');
                statusDiv.style.display = 'none';
                return;
            }
            
            progressBar.style.width = '50%';
            progressBar.textContent = '50%';
            messageEl.textContent = '🔍 Looking up ' + recordNumbers.length + ' records...';
            
            // Send to server for lookup
            $.ajax({
                url: 'api.php',
                type: 'POST',
                data: {
                    action: 'bulk_image_lookup',
                    record_numbers: JSON.stringify(recordNumbers)
                },
                dataType: 'json',
                success: function(res) {
                    progressBar.style.width = '80%';
                    progressBar.textContent = '80%';
                    messageEl.textContent = '📝 Creating output file...';
                    
                    if (res.status === 'success') {
                        const imageMap = res.data; // {record_no: image_name}
                        
                        // Create output data
                        const outputData = [['Record_No', 'Image_Name']];
                        let found = 0, notFound = 0;
                        
                        for (let i = startRow; i < jsonData.length; i++) {
                            if (jsonData[i] && jsonData[i][0] !== undefined && jsonData[i][0] !== '') {
                                const recNo = String(jsonData[i][0]).trim();
                                const imgName = imageMap[recNo] || 'NOT FOUND';
                                outputData.push([recNo, imgName]);
                                
                                if (imgName !== 'NOT FOUND') found++;
                                else notFound++;
                            }
                        }
                        
                        // Create and download Excel
                        const ws = XLSX.utils.aoa_to_sheet(outputData);
                        const wb = XLSX.utils.book_new();
                        XLSX.utils.book_append_sheet(wb, ws, 'ImageLookup');
                        
                        // Set column widths
                        ws['!cols'] = [{wch: 15}, {wch: 50}];
                        
                        progressBar.style.width = '100%';
                        progressBar.textContent = '100%';
                        messageEl.textContent = '✅ Complete! Downloading...';
                        
                        // Download file
                        const filename = 'Image_Lookup_Result_' + new Date().toISOString().slice(0,10) + '.xlsx';
                        XLSX.writeFile(wb, filename);
                        
                        // Show results
                        resultDiv.style.display = 'block';
                        document.getElementById('lookupTotal').textContent = (found + notFound);
                        document.getElementById('lookupFound').textContent = found;
                        document.getElementById('lookupNotFound').textContent = notFound;
                        
                        setTimeout(() => {
                            statusDiv.style.display = 'none';
                        }, 2000);
                        
                    } else {
                        alert('❌ Error: ' + res.message);
                        statusDiv.style.display = 'none';
                    }
                },
                error: function() {
                    alert('❌ Server error! Please try again.');
                    statusDiv.style.display = 'none';
                }
            });
            
        } catch (err) {
            console.error(err);
            alert('❌ Error processing file: ' + err.message);
            statusDiv.style.display = 'none';
        }
    };
    
    reader.readAsArrayBuffer(file);
}

// ========== END IMAGE LOOKUP FUNCTIONS ==========

</script>

<!-- Edit Record Number Modal -->
<div class="modal fade" id="editRecordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">✏️ Edit Record Number</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editRecordId">
                <input type="hidden" id="editOldRecordNo">
                
                <div id="recordNoWarning" class="alert alert-warning" style="display:none;">
                    <strong>⚠️ Warning:</strong> Current record number contains non-numeric characters!
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Current Record Number:</label>
                    <input type="text" class="form-control" id="editOldRecordNoDisplay" disabled>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">New Record Number:</label>
                    <input type="text" class="form-control" id="editNewRecordNo" placeholder="Enter numeric record number only">
                    <small class="text-muted">Only digits (0-9) allowed</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveRecordNo()">💾 Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== REPORTS TO ADMIN MODAL (Admin View) ========== -->
<div id="adminReportsModal" class="modal fade" tabindex="-1" style="z-index:99999;">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#fd7e14,#e67e22);color:white;">
        <h5 class="modal-title">⚠️ Reports to Admin — Open Issues</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="p-3 d-flex gap-2 align-items-center border-bottom">
          <select id="reportStatusFilter" class="form-select form-select-sm w-auto" onchange="loadAdminReports()">
            <option value="open">Open Reports</option>
            <option value="solved">Solved</option>
            <option value="all">All</option>
          </select>
          <button class="btn btn-sm btn-outline-secondary" onclick="loadAdminReports()">🔄 Refresh</button>
          <button class="btn btn-sm btn-success" onclick="exportReportsExcel()" title="Export to Excel">📥 Export Excel</button>
          <span id="reportCountBadge" class="badge bg-warning text-dark fs-6">0</span>
        </div>
        <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
          <table class="table table-hover table-sm mb-0" id="adminReportsTable">
            <thead class="table-dark sticky-top">
              <tr>
                <th>#</th><th>Record No</th><th>Image No</th><th>Header</th><th>Issue Details</th>
                <th>Reported By</th><th>Role</th><th>From</th><th>Status</th><th>Created</th><th>Action</th>
              </tr>
            </thead>
            <tbody id="adminReportsTbody">
              <tr><td colspan="11" class="text-center py-4 text-muted">Loading...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// ============================================================
// ADMIN - REPORT TO ADMIN MANAGEMENT (v3.0)
// ============================================================

// Open Reports Modal from report count box
$('#reportCountAdminBox').on('click', function() {
    loadAdminReports();
    new bootstrap.Modal(document.getElementById('adminReportsModal')).show();
}).css('cursor','pointer').attr('title','Click to manage reports');

function loadAdminReports() {
    let status = $('#reportStatusFilter').val() || 'open';
    let deoUser = $('#adminUserFilter').val() || '';
    $('#adminReportsTbody').html('<tr><td colspan="11" class="text-center py-4 text-muted">Loading...</td></tr>');
    $.post('api.php', {action:'get_all_reports', status: status, deo_user: deoUser}, function(res) {
        if (res.status !== 'success') {
            $('#adminReportsTbody').html('<tr><td colspan="11" class="text-center py-4 text-danger">Error: ' + (res.message || 'Failed to load') + '</td></tr>');
            return;
        }
        let html = '';
        res.reports.forEach((r, i) => {
            let badge = r.status === 'open' ? 
                '<span class="badge bg-warning text-dark">Open</span>' : 
                '<span class="badge bg-success">Solved</span>';
            let action = r.status === 'open' ? 
                `<button class="btn btn-success btn-xs py-0 px-2" onclick="markReportSolved(${r.id},'${r.record_no}')">✅ Mark Solved</button>` : 
                '<span class="text-muted" style="font-size:11px;">Solved by '+escHtml(r.solved_by||'')+'</span>';
            let imgBadge = (r.image_no_display||r.image_no) ? `<span style="background:#0ea5e9;color:white;padding:1px 5px;border-radius:3px;font-size:11px;">📷 ${escHtml(r.image_no_display||r.image_no)}</span>` : '<span class="text-muted" style="font-size:11px;">—</span>';
            let fromLabelMap = {
                'first_qc':'1st QC','second_qc':'2nd QC',
                'autotyper':'AutoTyper','p2_deo':'P2 DEO','admin':'Admin'
            };
            let fromLabel = fromLabelMap[r.reported_from] || r.reported_from || '1st QC';
            html += `<tr class="${r.status==='open'?'table-warning':''}">
                <td>${i+1}</td>
                <td><strong>${escHtml(r.record_no)}</strong></td>
                <td>${imgBadge}</td>
                <td>${escHtml(r.header_name)}</td>
                <td style="max-width:200px;font-size:12px;">${escHtml(r.issue_details)}</td>
                <td>${escHtml(r.reported_by_name||r.reported_by)}</td>
                <td><span class="badge bg-secondary">${r.role}</span></td>
                <td><span class="badge bg-info text-dark" style="font-size:10px;">${fromLabel}</span></td>
                <td>${badge}</td>
                <td style="font-size:11px;">${r.created_at}</td>
                <td>${action}</td>
            </tr>`;
        });
        $('#adminReportsTbody').html(html || '<tr><td colspan="9" class="text-center py-3 text-muted">No reports found</td></tr>');
        let openCount = res.counts?.open || 0;
        $('#reportCountBadge').text(openCount + ' open');
        $('#adminReportCount').text(openCount);
        if (openCount > 0) $('#reportCountAdminBox').show(); else $('#reportCountAdminBox').hide();
    }, 'json').fail(function() {
        $('#adminReportsTbody').html('<tr><td colspan="11" class="text-center py-4 text-danger">Connection failed. Please try again.</td></tr>');
    });
}

function markReportSolved(reportId, recordNo) {
    if (!confirm('Mark this report as Solved?')) return;
    $.post('api.php', {action:'mark_report_solved', report_id:reportId, record_no:recordNo}, function(res) {
        if (res.status === 'success') {
            if (typeof showToast === 'function') showToast('✅ ' + res.message, 'success');
            loadAdminReports();
            updateCounts(); // Refresh counts
        } else {
            alert(res.message);
        }
    }, 'json');
}

function escHtml(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ============================================================
// EXPORT REPORTS TO EXCEL (Client-side CSV download)
// ============================================================
let _lastReportsData = [];

function exportReportsExcel() {
    let status = $('#reportStatusFilter').val() || 'open';
    $.post('api.php', {action:'get_all_reports', status: status}, function(res) {
        if (res.status !== 'success' || !res.reports.length) {
            alert('Koi reports nahi hain export karne ke liye.'); return;
        }
        _lastReportsData = res.reports;
        let rows = [['#','Record No','Image No','Header/Field','Issue Details','Reported By','Role','Reported From','Status','Solved By','Created At']];
        res.reports.forEach((r, i) => {
            let imgNo = r.image_no_display || r.image_no || '';
            let fromLabel = {first_qc:'First QC',second_qc:'Second QC',autotyper:'AutoTyper',p2_deo:'P2 DEO',admin:'Admin'}[r.reported_from] || r.reported_from || 'First QC';
            rows.push([
                i+1,
                r.record_no,
                imgNo,
                r.header_name,
                r.issue_details,
                r.reported_by_name || r.reported_by,
                r.role,
                fromLabel,
                r.status,
                r.solved_by || '',
                r.created_at
            ]);
        });
        let csv = rows.map(r => r.map(v => '"' + String(v||'').replace(/"/g,'""') + '"').join(',')).join('\n');
        let blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8;'});
        let url = URL.createObjectURL(blob);
        let a = document.createElement('a');
        a.href = url;
        let dateStr = new Date().toISOString().slice(0,10);
        a.download = 'Reports_to_Admin_' + dateStr + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        if(typeof showToast === 'function') showToast('✅ Excel export ho gaya!', 'success');
    }, 'json');
}

// ============================================================
// REALTIME: Poll report count + qc_enabled every 5s
// ============================================================
let _adminReportPollInterval = null;

function startAdminReportPolling() {
    if (_adminReportPollInterval) return;
    let lastSync = new Date().toISOString().slice(0,19).replace('T',' ');
    _adminReportPollInterval = setInterval(function() {
        $.post('api.php', {action:'sync_changes', last_sync: lastSync}, function(res) {
            if (res.status !== 'success') return;
            lastSync = res.server_time;
            
            // Update report count badge (only if no user filter active)
            if (res.report_count !== undefined && !$('#adminUserFilter').val()) {
                let rc = parseInt(res.report_count) || 0;
                $('#adminReportCount').text(rc);
                $('#reportCountAdminBox')[rc > 0 ? 'show' : 'hide']();
            }
            
            // QC enabled/disabled visibility
            if (res.qc_enabled !== undefined) {
                applyAdminQCVisibility(res.qc_enabled === '1');
            }
            
            // If QC done count updated
            if (res.stats) {
                if (res.stats.second_qc_done !== undefined) {
                    $('#qcDoneCount').text(res.stats.second_qc_done);
                }
                if (res.stats.second_qc_pending !== undefined) {
                    $('#qcPendingCount').text(res.stats.second_qc_pending);
                }
            }
        }, 'json');
    }, 5000);
}

function applyAdminQCVisibility(enabled) {
    if (enabled) {
        $('#qcPendingBox').show();
        $('#qcDoneBox').show();
        $('.qc-only-section').show();
    } else {
        $('#qcPendingBox').hide();
        $('#qcDoneBox').hide();
        $('.qc-only-section').hide();
    }
}

$(document).ready(function() {
    startAdminReportPolling();
});

// ============================================================
// BULK REPORT TO ADMIN — Excel Upload (v3.1)
// ============================================================

let bulkReportData = []; // Parsed rows from Excel

function downloadBulkReportSample() {
    // Sirf 3 columns chahiye - Reported_By/Role/Image auto-fetch hoga
    let csv = 'Record_No,Header_Name,Issue_Details\n';
    csv += '1001,Name,Name galat likha hua hai\n';
    csv += '1002,DOB,Date of Birth incorrect hai\n';
    csv += '1003,Address,Address incomplete hai\n';
    csv += '1004,Gender,Gender mismatch with document\n';
    csv += '1005,Occupation,Occupation field empty hai\n';
    
    let blob = new Blob([csv], {type: 'text/csv'});
    let a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'BulkReport_Sample.csv';
    a.click();
}

function processBulkReportFile() {
    let file = $('#bulkReportFile')[0].files[0];
    if (!file) { alert('Pehle file select karo'); return; }
    
    let ext = file.name.split('.').pop().toLowerCase();
    
    if (ext === 'csv') {
        // CSV parsing
        let reader = new FileReader();
        reader.onload = function(e) {
            let lines = e.target.result.split('\n').filter(l => l.trim());
            let rows = [];
            let headers = lines[0].split(',').map(h => h.trim().toLowerCase().replace(/['"]/g,''));
            
            for (let i = 1; i < lines.length; i++) {
                let cols = lines[i].split(',').map(c => c.trim().replace(/^["']|["']$/g,''));
                let row = {};
                headers.forEach((h, idx) => row[h] = cols[idx] || '');
                if (row['record_no'] || row['record no']) {
                    rows.push({
                        record_no:    row['record_no']    || row['record no']    || '',
                        reported_by_name: row['reported_by'] || row['reported by'] || row['reporter'] || row['name'] || '',
                        reporter_role:row['reporter_role']|| row['role']         || row['reporter role'] || 'deo',
                        header_name:  row['header_name']  || row['header name']  || row['field'] || '',
                        issue_details:row['issue_details']|| row['issue details']|| row['details']|| '',
                        image_no:     row['image_no']     || row['image name']   || ''
                    });
                }
            }
            renderBulkReportPreview(rows);
        };
        reader.readAsText(file);
    } else {
        // XLSX — use SheetJS
        let reader = new FileReader();
        reader.onload = function(e) {
            try {
                let data = new Uint8Array(e.target.result);
                let workbook = XLSX.read(data, {type: 'array'});
                let sheet = workbook.Sheets[workbook.SheetNames[0]];
                let json = XLSX.utils.sheet_to_json(sheet, {defval: ''});
                
                let rows = json.map(r => {
                    let keys = Object.keys(r).map(k => k.toLowerCase().trim());
                    let get = (names) => {
                        for (let n of names) {
                            let k = Object.keys(r).find(k => k.toLowerCase().trim() === n);
                            if (k && r[k]) return String(r[k]).trim();
                        }
                        return '';
                    };
                    return {
                        record_no:       get(['record_no','record no','recordno','record']),
                        reported_by_name:get(['reported_by','reported by','reporter','reporter_name','reporter name','name']),
                        reporter_role:   get(['reporter_role','reporter role','role']) || 'deo',
                        header_name:     get(['header_name','header name','headername','header','field','field name']),
                        issue_details:   get(['issue_details','issue details','issuedetails','issue','details','description']),
                        image_no:        get(['image_no','image_name','image no','imageno','image'])
                    };
                }).filter(r => r.record_no);
                
                renderBulkReportPreview(rows);
            } catch(err) {
                alert('Excel parse error: ' + err.message);
            }
        };
        reader.readAsArrayBuffer(file);
    }
}

function renderBulkReportPreview(rows) {
    if (!rows.length) {
        alert('Koi valid records nahi mile. Check karo Record_No column hai ya nahi.');
        return;
    }
    
    bulkReportData = rows;
    $('#bulkReportCount').text(rows.length);
    
    // Auto-fetch image_no + reported_by_name + reporter_role from server
    let allRecordNos = rows.map(r => r.record_no);
    
    $.post('api.php', {
        action: 'get_record_user_info',
        record_nos: JSON.stringify(allRecordNos)
    }, function(res) {
        if (res.status === 'success' && res.data) {
            bulkReportData.forEach(r => {
                let info = res.data[r.record_no];
                if (info) {
                    r.image_no         = r.image_no || info.image_no || '';
                    r.reported_by_name = info.full_name || info.username || '';
                    r.reported_by      = info.username || '';
                    r.reporter_role    = info.role || 'deo';
                }
            });
        }
        renderPreviewTable();
    }, 'json').fail(function() { renderPreviewTable(); });
}

function renderPreviewTable() {
    let html = '';
    bulkReportData.forEach((r, i) => {
        let imgBadge = r.image_no 
            ? `<span style="color:#0ea5e9;font-size:11px;">📷 ${escHtml(r.image_no)}</span>`
            : '<span class="text-muted" style="font-size:11px;">—</span>';
        let roleColor = (r.reporter_role||'').toLowerCase() === 'qc' ? '#6f42c1' : '#0d6efd';
        let roleBadge = `<span style="background:${roleColor};color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">${escHtml(r.reporter_role||'deo')}</span>`;
        html += `<tr>
            <td>${i+1}</td>
            <td><strong>${escHtml(r.record_no)}</strong></td>
            <td>${escHtml(r.reported_by_name||'—')}</td>
            <td>${roleBadge}</td>
            <td>${imgBadge}</td>
            <td>${escHtml(r.header_name)}</td>
            <td style="font-size:12px;">${escHtml(r.issue_details)}</td>
        </tr>`;
    });
    
    $('#bulkReportPreviewTbody').html(html);
    $('#bulkReportPreviewDiv').show();
    $('#bulkReportResultDiv').hide();
}

function submitBulkReport() {
    if (!bulkReportData.length) { alert('Pehle file upload karo'); return; }
    if (!confirm(`${bulkReportData.length} records ka report submit karna hai?`)) return;
    
    let btn = $('#btnSubmitBulkReport');
    btn.prop('disabled', true).text('⏳ Submitting...');
    
    $.post('api.php', {
        action: 'bulk_submit_report_to_admin',
        reports: JSON.stringify(bulkReportData),
        reported_from: 'first_qc'
    }, function(res) {
        btn.prop('disabled', false).text('⚠️ Submit All Reports');
        
        let cls = res.status === 'success' ? 'success' : 'danger';
        let html = `<div class="alert alert-${cls}">
            <h5>${res.status === 'success' ? '✅' : '❌'} ${escHtml(res.message)}</h5>
            ${res.success_count !== undefined ? `<p>✅ Submitted: <strong>${res.success_count}</strong></p>` : ''}
            ${res.duplicate_count !== undefined && res.duplicate_count > 0 ? `<p>⚠️ Duplicates skipped: <strong>${res.duplicate_count}</strong></p>` : ''}
            ${res.error_count !== undefined && res.error_count > 0 ? `<p>❌ Errors: <strong>${res.error_count}</strong></p>` : ''}
        </div>`;
        
        $('#bulkReportResultDiv').html(html).show();
        
        if (res.status === 'success') {
            bulkReportData = [];
            $('#bulkReportPreviewDiv').hide();
            $('#bulkReportFile').val('');
            updateCounts();
        }
    }, 'json').fail(function() {
        btn.prop('disabled', false).text('⚠️ Submit All Reports');
        $('#bulkReportResultDiv').html('<div class="alert alert-danger">❌ Connection failed. Please try again.</div>').show();
    });
}

</script>

</body>
</html>