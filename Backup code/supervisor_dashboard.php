<?php
session_start();
require_once 'config.php';

// Session & Role Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor') {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard</title>
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
        .header-supervisor { background: linear-gradient(135deg, #5a1e72 0%, #6f42c1 100%); }
        .header-left { display: flex; align-items: center; gap: 10px; }
        .header-title { font-size: 14px; font-weight: 600; }
        .header-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
        .badge-user { background: rgba(255,255,255,0.9); color: #5a1e72; }
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
        .btn-message { background: #0d6efd; color: white; }
        .btn-reports { background: #28a745; color: white; }
        
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
        .btn-comp { background: #dc3545; color: white; }
        .btn-done { background: #0d6efd; color: white; }
        .btn-pend { background: #6c757d; color: white; }
        .btn-sync { background: #17a2b8; color: white; }
        .btn-refresh { background: #28a745; color: white; }
        
        /* ========== EXISTING STYLES ========== */
        /* Supervisor Purple Color */
        .bg-purple { background-color: #6f42c1 !important; color: white !important; }
        .btn-purple { background-color: #6f42c1; border-color: #6f42c1; color: white; }
        .btn-purple:hover { background-color: #5a32a3; border-color: #5a32a3; color: white; }
        #mainContainer { display:flex; flex-direction:column; height:100vh; padding:8px; box-sizing:border-box; }
        #dataPanel { flex:1 1 auto; background:var(--card-bg); border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); overflow:hidden; display:flex; flex-direction:column; border:1px solid var(--border-color); }
        .table-container { overflow:auto; flex:1; }
        table { margin-bottom:0; color:var(--text-color); font-size: 11px; }
        th { background-color:#6f42c1!important; color:white; white-space:nowrap; padding:8px 6px; border:1px solid #000!important; position:sticky; top:0; z-index:100; font-weight:600; font-size: 11px; }
        td { white-space:nowrap; cursor:pointer; border:1px solid var(--table-border)!important; padding:4px 6px; vertical-align:middle; transition: background-color 0.2s; font-size: 11px; }
        td.readonly { background-color:var(--readonly-bg); color:var(--readonly-text); font-weight:bold; cursor:default; }
        /* Completed cell - selectable, navigable but not editable */
        td.completed-cell { cursor:text; user-select:text; }
        td.completed-cell:focus { outline:2px solid #dc3545!important; outline-offset:-2px; }
        /* Unsaved cell - edited data highlight (HIGH PRIORITY) */
        td.unsaved-cell { background-color:#fff3cd!important; outline:2px solid #ffc107!important; color:#000!important; z-index:10; position:relative; }
        tr.active-row td.unsaved-cell { background-color:#fff3cd!important; outline:2px solid #ffc107!important; }
        tr.saved-row td.unsaved-cell { background-color:#fff3cd!important; outline:2px solid #ffc107!important; }
        
        tr td.initial-flagged-cell { background-color:#cfe2ff!important; outline:1px solid #0d6efd; font-weight:500; }
        
        /* Row status colors */
        tr.saved-row > td { background-color:#d1e7dd!important; } 
        body.dark-mode tr.saved-row > td { background-color:#143826!important; }
        tr.completed-row > td { background-color:#f8d7da!important; } 
        body.dark-mode tr.completed-row > td { background-color:#4a181d!important; }
        
        /* Active row highlight - highest priority for non-edited cells */
        tr.active-row > td:not(.unsaved-cell) { background-color:#9ec5fe!important; border-top:2px solid #0a58ca!important; border-bottom:2px solid #0a58ca!important; }
        tr.active-row > td.unsaved-cell { border-top:2px solid #0a58ca!important; border-bottom:2px solid #0a58ca!important; }
        body.dark-mode tr.active-row > td:not(.unsaved-cell) { background-color:#0c2d5e!important; }
        tr.saved-row.active-row > td:not(.unsaved-cell) { background-color:#9ec5fe!important; }
        tr.completed-row.active-row > td { background-color:#9ec5fe!important; }
        td.record-cell:focus { outline:3px solid #ffc107!important; outline-offset:-3px; }
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
        <div class="header-bar header-supervisor mb-2">
            <div class="header-left">
                <span class="header-title">👔 Supervisor Dashboard</span>
                <span id="userBadge" class="header-badge badge-user"></span>
                <span id="realTimeClock" class="header-badge badge-clock"></span>
            </div>
            <div class="header-stats">
                <div class="stat-box stat-pending">
                    <span class="stat-number" id="totalPendingCount">0</span>
                    <span class="stat-label">Total Pending</span>
                </div>
                <div class="stat-box stat-done">
                    <span class="stat-number" id="availableDoneCount">0</span>
                    <span class="stat-label">Available Done</span>
                </div>
                <div class="stat-box stat-completed">
                    <span class="stat-number" id="totalCompletedCount">0</span>
                    <span class="stat-label">Total Completed</span>
                </div>
                <div class="stat-box stat-today">
                    <span class="stat-number" id="todayCompletedCount">0</span>
                    <span class="stat-label">Today Completed</span>
                </div>
            </div>
            <div class="header-right">
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
                    <option value="pending">Pending</option>
                    <option value="done">Done</option>
                    <option value="completed">Completed</option>
                </select>
                <select id="adminUserFilter" class="form-select form-select-sm" onchange="loadData();">
                    <option value="">All Users</option>
                </select>
                <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="🔍 Search..." onkeyup="filterData()">
            </div>
            <div class="menu-buttons">
                <!-- Supervisor: Limited Buttons Based on Permissions -->
                <button class="menu-btn btn-message perm-btn" data-perm="send_messages" onclick="$('#messageModal').modal('show'); loadUsersForMessage();" style="display:none;">💬 Message</button>
                <button class="menu-btn btn-reports perm-btn" data-perm="export_data" onclick="exportToExcel()" style="display:none;">📥 Export</button>
            </div>
        </div>

        <!-- Controls Row 2: Action Buttons -->
        <div class="action-row mb-2">
            <div class="action-group">
                <button type="button" class="action-btn btn-sync perm-btn" data-perm="sync_images" onclick="syncImages()" style="display:none;">📷 Sync Images</button>
                <button type="button" class="action-btn btn-comp" onclick="batchStatusUpdate('Completed')">✅ Completed</button>
                <button type="button" class="action-btn btn-done" onclick="batchStatusUpdate('done')">✔️ Done</button>
                <button type="button" class="action-btn btn-pend" onclick="batchStatusUpdate('pending')">⏳ Pending</button>
                <button type="button" class="action-btn btn-refresh" onclick="loadData()">🔄 Refresh</button>
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
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabSupervisorPerms">🔑 Supervisor Permissions</a></li>
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
                    
                    <!-- Supervisor Permissions Tab -->
                    <div class="tab-pane fade" id="tabSupervisorPerms">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Select Supervisor</label>
                                <select id="permSupervisorSelect" class="form-select" onchange="loadSupervisorPermissions()">
                                    <option value="">-- Select Supervisor --</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <div class="alert alert-info mb-2 py-2">
                                    <small>🔑 Select which features this supervisor can access. Admin-only features (Settings, Data, Notify, Reports, Security, Users, Main Upload, Update Upload, Assign) are restricted by default.</small>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="card" id="permissionsCard" style="display:none;">
                                    <div class="card-header bg-primary text-white">
                                        <strong>📋 Permissions for: <span id="permSupervisorName">-</span></strong>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <!-- View Permissions -->
                                            <div class="col-md-4">
                                                <div class="card h-100">
                                                    <div class="card-header bg-success text-white py-2">👁️ View Permissions</div>
                                                    <div class="card-body">
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_view_all_records" checked disabled>
                                                            <label class="form-check-label">View All Records ✓</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_view_images" checked disabled>
                                                            <label class="form-check-label">View Images ✓</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_view_reports">
                                                            <label class="form-check-label">View Reports</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_view_analytics">
                                                            <label class="form-check-label">View Analytics</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Edit Permissions -->
                                            <div class="col-md-4">
                                                <div class="card h-100">
                                                    <div class="card-header bg-warning text-dark py-2">✏️ Edit Permissions</div>
                                                    <div class="card-body">
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_edit_records">
                                                            <label class="form-check-label">Edit Records</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_change_status">
                                                            <label class="form-check-label">Change Record Status</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_batch_status">
                                                            <label class="form-check-label">Batch Status Update</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_upload_images">
                                                            <label class="form-check-label">Upload Images</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_sync_images">
                                                            <label class="form-check-label">Sync Images</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Admin Features -->
                                            <div class="col-md-4">
                                                <div class="card h-100">
                                                    <div class="card-header bg-danger text-white py-2">⚠️ Admin Features</div>
                                                    <div class="card-body">
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_export_data">
                                                            <label class="form-check-label">Export Data</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_send_messages">
                                                            <label class="form-check-label">Send Messages to DEO</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_view_deo_performance">
                                                            <label class="form-check-label">View DEO Performance</label>
                                                        </div>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input perm-check" type="checkbox" id="perm_delete_records">
                                                            <label class="form-check-label text-danger">Delete Records</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <button class="btn btn-success" onclick="saveSupervisorPermissions()">💾 Save Permissions</button>
                                            <button class="btn btn-outline-secondary ms-2" onclick="selectAllPermissions()">Select All</button>
                                            <button class="btn btn-outline-secondary" onclick="deselectAllPermissions()">Deselect All</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
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
                                    <option value="supervisor">Supervisor</option>
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
                        <div style="max-height:350px; overflow-y:auto;">
                            <table class="table table-sm">
                                <thead class="table-info sticky-top">
                                    <tr><th>User</th><th>Last Activity</th><th>IP Address</th><th>Status</th></tr>
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
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabDataStats">📊 Statistics</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabBulkDelete">🗑️ Bulk Delete</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabBackup">💾 Backup/Restore</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabDuplicates">🔍 Duplicates</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabExport">📤 Export</a></li>
                </ul>
                <div class="tab-content mt-3">
                    <!-- Statistics Tab -->
                    <div class="tab-pane fade show active" id="tabDataStats">
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
                                            <select id="setRecordsPerPage" class="form-select">
                                                <option value="25">25</option>
                                                <option value="50" selected>50</option>
                                                <option value="100">100</option>
                                                <option value="200">200</option>
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
                                                <input type="text" id="setMasterOtp" class="form-control" placeholder="123456" maxlength="6">
                                                <button class="btn btn-outline-secondary" type="button" onclick="generateRandomOtp()">🎲 Generate</button>
                                            </div>
                                            <small class="text-muted">This OTP can bypass DEO login when WhatsApp OTP fails</small>
                                        </div>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" id="setMasterOtpEnabled" checked>
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
                                                <option value="d-m-Y">DD-MM-YYYY (31-12-2025)</option>
                                                <option value="m-d-Y">MM-DD-YYYY (12-31-2025)</option>
                                                <option value="Y-m-d">YYYY-MM-DD (2025-12-31)</option>
                                                <option value="d/m/Y">DD/MM/YYYY (31/12/2025)</option>
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

<div id="validationTooltip"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    let currentUser = null, allClientData = [], filteredData = [];
    let currentPage = 1, itemsPerPage = 50;
    let currentScale = 1.4, currentRot = 0, initialW = 0, initialH = 0;
    let ocrWorker = null, ocrData = null, filterB=100, filterC=100, isInverted=false, isMagnifierActive=false;
    let activeRowId = null, activeTimer = null;
    let syncInterval;
    let isImageLocked = false;
    let completedChart = null, timeChart = null; // Chart instances
    
    // --- FIX: PERSISTENT SELECTION SET ---
    let selectedIds = new Set(); 

    $(document).ready(()=>checkSession());
    
    let supervisorPermissions = {};

    function checkSession() {
        if(localStorage.getItem('theme')==='dark') document.body.classList.add('dark-mode');
        $.post('api.php', {action:'check_session'}, function(res){
            if(res.status==='logged_in'){
                // Only allow supervisor role
                if(res.role !== 'supervisor') { 
                    if(res.role === 'admin') {
                        window.location.href = 'p1_admin_dashboard.php'; 
                    } else {
                        window.location.href = 'index.php'; 
                    }
                    return; 
                }
                currentUser = res;
                supervisorPermissions = res.permissions || {};
                
                $('#mainContainer').fadeIn().css('display','flex');
                $('#userBadge').text('👔 ' + res.username.toUpperCase());
                
                // Apply permissions - show/hide elements based on permissions
                applyPermissions();
                
                loadUsersForDropdown();
                loadData();
                startRealtimeSync(); 
            } else window.location.href = 'login.php';
        },'json');
    }
    
    function applyPermissions() {
        console.log('Applying permissions:', supervisorPermissions);
        
        // Show/hide buttons based on permissions
        $('.perm-btn').each(function() {
            let perm = $(this).data('perm');
            if (supervisorPermissions && supervisorPermissions[perm] === true) {
                $(this).show().css('display', '');
            } else {
                $(this).hide();
            }
        });
        
        // Show/hide sections based on permissions
        $('.perm-section').each(function() {
            let perm = $(this).data('perm');
            if (supervisorPermissions && supervisorPermissions[perm] === true) {
                $(this).show().css('display', '');
            } else {
                $(this).hide();
            }
        });
        
        // Edit records permission
        if (!supervisorPermissions || !supervisorPermissions['edit_records']) {
            // Make cells non-editable
            window.canEditRecords = false;
        } else {
            window.canEditRecords = true;
        }
        
        // Delete permission
        if (!supervisorPermissions || !supervisorPermissions['delete_records']) {
            $('.delete-btn').hide();
        }
    }
    
    function logout() { $.post('api.php', {action:'logout'}, ()=>location.reload()); }
    function toggleTheme(){ document.body.classList.toggle('dark-mode'); localStorage.setItem('theme',document.body.classList.contains('dark-mode')?'dark':'light'); }

    // --- HEARTBEAT (Track Online Status) ---
    function sendHeartbeat() {
        $.post('api.php', {action: 'heartbeat'}, function(res) {}, 'json');
    }

    // Track already processed changes
    let processedChanges = new Set();
    
    // --- REALTIME SYNC (SUPERVISOR) ---
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
                        let newChangesCount = 0;
                        
                        res.changes.forEach(changedRecord => {
                            let changeKey = `${changedRecord.id}-${changedRecord.updated_at}`;
                            
                            if(processedChanges.has(changeKey)) return;
                            
                            processedChanges.add(changeKey);
                            newChangesCount++;
                            
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
                        });
                        
                        // Keep set manageable
                        if(processedChanges.size > 1000) processedChanges.clear();
                        
                        // Show notification only for new changes
                        if(newChangesCount > 0) {
                            showToast(`🔄 ${newChangesCount} record(s) updated`, 'info', 2000);
                        }
                    }
                }
            }, 'json');
        }, 3000);
    }
    
    // Update single row display
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
        
        // Flash effect
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
        $.post('api.php', p, function(d){ $('#loader').hide(); allClientData=d; filterData(); updateCounts(); },'json');
    }

    function filterData() {
        let term = $('#searchInput').val().toLowerCase();
        filteredData = allClientData.filter(r => !term || Object.values(r).some(v=>String(v).toLowerCase().includes(term)));
        if(!syncInterval) currentPage = 1; 
        renderTable();
    }

    function updateCounts() {
        let today = new Date().toISOString().split('T')[0];
        
        // Total Pending: status = 'pending'
        let totalPending = allClientData.filter(r => r.row_status === 'pending').length;
        
        // Total Completed: status = 'Completed'
        let totalCompleted = allClientData.filter(r => r.row_status === 'Completed').length;
        
        // Today Completed: status = 'Completed' and updated today
        let todayCompleted = allClientData.filter(r => r.row_status === 'Completed' && r.updated_at && r.updated_at.startsWith(today)).length;
        
        // Available Done: status = 'done' (ready for autotyper, not yet completed)
        let availableDone = allClientData.filter(r => r.row_status === 'done').length;

        $('#totalPendingCount').text(totalPending);
        $('#totalCompletedCount').text(totalCompleted);
        $('#todayCompletedCount').text(todayCompleted);
        $('#availableDoneCount').text(availableDone);
    }

    function renderTable() {
        let start = (currentPage-1)*itemsPerPage, pageData = filteredData.slice(start, start+itemsPerPage);
        let h = '';
        pageData.forEach((r, i) => {
            let rowClass = '';
            let statusBadge = '<span class="badge bg-warning text-dark">Pend</span>';
            let isCompleted = false;
            
            if(r.row_status === 'done') {
                rowClass = 'saved-row';
                statusBadge = '<span class="badge bg-success">Done</span>';
            } else if(r.row_status === 'Completed') {
                rowClass = 'completed-row';
                statusBadge = '<span class="badge bg-danger">Comp</span>';
                isCompleted = true;
            }

            let edited = JSON.parse(r.edited_fields || '[]');
            let seconds = parseInt(r.time_spent)||0;
            let isChecked = selectedIds.has(String(r.id)) ? 'checked' : '';
            
            // DONE button for all rows
            let doneBtn = `<button class="btn btn-primary btn-sm py-0" onclick="saveRow(${r.id})">DONE</button>`;
            
            h += `<tr data-id="${r.id}" class="${rowClass}">
                <td class="text-center"><input type="checkbox" class="row-select" data-id="${r.id}" ${isChecked} onchange="updateSelection('${r.id}', this.checked)"></td>
                <td class="readonly">${start + i + 1}</td>
                <td class="readonly text-primary record-cell" tabindex="0" onclick="handleRecordClick(this, '${r.record_no}')" onkeydown="if(event.key==='Enter') handleRecordClick(this, '${r.record_no}')"><b>${r.record_no}</b></td>
                <td class="text-center">${doneBtn}</td>
                <td class="readonly small">${r.username || '-'}</td>
                <td class="readonly">${statusBadge}</td>`;
            
            // Completed rows: selectable, cursor movable, tab works, but NOT editable
            ['kyc_number','name','guardian_name','gender','marital_status','dob','address','landmark','city','zip_code','city_of_birth','nationality','photo_attachment','residential_status','occupation','officially_valid_documents','annual_income','broker_name','sub_broker_code','bank_serial_no','second_applicant_name','amount_received_from','amount','arn_no','second_address','occupation_profession','remarks'].forEach(c => {
                 let cls = edited.includes(c) ? 'unsaved-cell' : '';
                 if(isCompleted) {
                     // Completed: tabindex for navigation, click/focus works, but no contenteditable
                     h += `<td class="completed-cell" tabindex="0" data-col="${c}" 
                           onfocus="handleFocusCompleted(this)" onclick="handleFocusCompleted(this)">${r[c]||''}</td>`;
                 } else {
                     h += `<td contenteditable="true" data-col="${c}" class="${cls}" 
                           onfocus="handleFocus(this)" onblur="saveCell(this)" oninput="validateField(this)">${r[c]||''}</td>`;
                 }
            });
            
            h += `<td class="timing-cell" data-seconds="${seconds}">${formatTime(seconds)}</td></tr>`;
        });
        $('#tableBody').html(h);
        $('#pageInfo').text(`${currentPage}/${Math.ceil(filteredData.length/itemsPerPage)||1}`);
    }

    function changePage(d) {
        let max = Math.ceil(filteredData.length/itemsPerPage);
        if(currentPage+d>0 && currentPage+d<=max) { currentPage+=d; renderTable(); $('.table-container').scrollTop(0); }
    }
    
    // --- RECORD ACTIONS ---
    function handleRecordClick(el, rec) { openImage(rec); $('tr.active-row').removeClass('active-row'); $(el).closest('tr').addClass('active-row'); }

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
            let supervisorOpts = '<option value="">-- Select Supervisor --</option>';
            d.forEach(u=>{ 
                let statusBadge = u.status === 'active' ? '<span class="badge bg-success">Active</span>' : 
                                  (u.status === 'locked' || u.is_locked ? '<span class="badge bg-danger">Locked</span>' : '<span class="badge bg-secondary">Inactive</span>');
                let lastLogin = u.last_login ? new Date(u.last_login).toLocaleString() : 'Never';
                let roleBadge = u.role === 'admin' ? 'bg-danger' : (u.role === 'supervisor' ? 'bg-purple' : 'bg-primary');
                let roleLabel = u.role === 'supervisor' ? '👔 Supervisor' : u.role;
                h+=`<tr>
                    <td><strong>${u.username}</strong><br><small class="text-muted">${u.full_name || '-'}</small></td>
                    <td><span class="badge ${roleBadge}">${roleLabel}</span></td>
                    <td>${statusBadge}</td>
                    <td>${u.daily_target || 100}</td>
                    <td><small>${lastLogin}</small></td>
                    <td>
                        <button class="btn btn-sm btn-info py-0" onclick="openUserDetails(${u.id})" title="Details">👤</button>
                        <button class="btn btn-sm btn-warning py-0" onclick="editUser(${JSON.stringify(u).replace(/"/g,'&quot;')})" title="Edit">✎</button>
                        ${u.role === 'supervisor' ? `<button class="btn btn-sm btn-purple py-0" onclick="openSupervisorPermissions(${u.id}, '${u.full_name || u.username}')" title="Permissions">🔑</button>` : ''}
                        <button class="btn btn-sm btn-danger py-0" onclick="delUser(${u.id})" title="Delete">×</button>
                    </td>
                </tr>`; 
                
                // Add to supervisor dropdown
                if (u.role === 'supervisor') {
                    supervisorOpts += `<option value="${u.id}">${u.full_name || u.username} (${u.username})</option>`;
                }
            }); 
            $('#userListTable').html(h); 
            $('#permSupervisorSelect').html(supervisorOpts);
        },'json'); 
        loadPerformance();
        loadActiveSessions();
    }
    
    // Supervisor Permission Functions
    function openSupervisorPermissions(userId, name) {
        $('#permSupervisorSelect').val(userId);
        $('#permSupervisorName').text(name);
        loadSupervisorPermissions();
        $('#userTabs a[href="#tabSupervisorPerms"]').tab('show');
    }
    
    function loadSupervisorPermissions() {
        let userId = $('#permSupervisorSelect').val();
        if (!userId) {
            $('#permissionsCard').hide();
            return;
        }
        
        // Get supervisor name
        let supervisorName = $('#permSupervisorSelect option:selected').text();
        $('#permSupervisorName').text(supervisorName);
        $('#permissionsCard').show();
        
        // Reset all checkboxes
        $('.perm-check:not(:disabled)').prop('checked', false);
        
        // Load existing permissions
        $.post('api.php', {action: 'get_supervisor_permissions', user_id: userId}, function(res) {
            if (res.status === 'success' && res.permissions) {
                Object.keys(res.permissions).forEach(key => {
                    if (res.permissions[key]) {
                        $('#perm_' + key).prop('checked', true);
                    }
                });
            }
        }, 'json');
    }
    
    function saveSupervisorPermissions() {
        let userId = $('#permSupervisorSelect').val();
        if (!userId) {
            alert('Please select a supervisor');
            return;
        }
        
        let permissions = {};
        $('.perm-check:not(:disabled)').each(function() {
            let key = $(this).attr('id').replace('perm_', '');
            permissions[key] = $(this).is(':checked');
        });
        
        $.post('api.php', {
            action: 'save_supervisor_permissions',
            user_id: userId,
            permissions: JSON.stringify(permissions)
        }, function(res) {
            alert(res.message || 'Permissions saved!');
        }, 'json');
    }
    
    function selectAllPermissions() {
        $('.perm-check:not(:disabled)').prop('checked', true);
    }
    
    function deselectAllPermissions() {
        $('.perm-check:not(:disabled)').prop('checked', false);
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
                r.data.forEach(s=>{
                    h += `<tr>
                        <td><strong>${s.username}</strong><br><small>${s.full_name || ''}</small></td>
                        <td><small>${s.last_login}</small></td>
                        <td><small>${s.last_ip || '-'}</small></td>
                        <td><span class="badge bg-success">🟢 Online</span></td>
                    </tr>`;
                });
                $('#activeSessionsTable').html(h || '<tr><td colspan="4" class="text-center">No active sessions</td></tr>');
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

    $('#mainExcel').change(e=>{ parseAndUpload(e, 'upload_main_data'); });
    $('#updateExcel').change(e=>{ parseAndUpload(e, 'upload_updated_data'); });
    function parseAndUpload(e, act) { let r=new FileReader(); r.onload=ev=>{ let d=XLSX.utils.sheet_to_json(XLSX.read(new Uint8Array(ev.target.result),{type:'array'}).Sheets[XLSX.read(new Uint8Array(ev.target.result),{type:'array'}).SheetNames[0]]); uploadChunks(d,0,act); }; r.readAsArrayBuffer(e.target.files[0]); }
    function uploadChunks(d,i,act){ let c=d.slice(i,i+50); if(!c.length){alert('Done');loadData();return;} $('#loader').show(); $.post('api.php',{action:act,jsonData:JSON.stringify(c)},()=>uploadChunks(d,i+50,act)); }
    
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
                let img=$('#zoomImage'); let src = 'uploads/'+res.image+'?t='+new Date().getTime();
                img.off('load').on('load',function(){ initialW=this.naturalWidth; initialH=this.naturalHeight; $('#highlightLayer').attr({width:initialW,height:initialH}); applyTrans(); runOCR('uploads/'+res.image, rec); }).attr('src',src); 
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
            res.forEach(u => {
                if(u.role === 'deo') {
                    opts += `<option value="${u.username}">${u.username} - ${u.full_name || ''}</option>`;
                }
            });
            $('#notifyUser, #summaryUser, #lowProdUser').html(opts);
        }, 'json');
        
        // Load notification settings
        $.post('api.php', {action: 'get_notification_settings'}, function(res) {
            if(res.status === 'success') {
                $('#setAutoDailySummary').prop('checked', res.data.auto_daily_summary === '1');
                $('#setAutoTargetNotify').prop('checked', res.data.auto_target_notification === '1');
                $('#setAdminDailySummary').prop('checked', res.data.admin_daily_summary === '1');
                $('#setLowProdThreshold').val(res.data.low_productivity_threshold || 30);
            }
        }, 'json');
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
            low_productivity_threshold: $('#setLowProdThreshold').val()
        };
        
        $.post('api.php', data, function(res) {
            alert(res.status === 'success' ? 'Settings saved!' : 'Failed to save');
        }, 'json');
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
            if(res.status === 'success') {
                let s = res.data;
                $('#setCompanyName').val(s.company_name || 'BPO Dashboard');
                $('#setDefaultTarget').val(s.default_daily_target || 100);
                $('#setRecordsPerPage').val(s.records_per_page || 50);
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
                $('#setMasterOtp').val(s.master_otp || '123456');
                $('#setMasterOtpEnabled').prop('checked', s.master_otp_enabled !== '0');
                
                // WhatsApp API settings
                $('#setWhatsappApiUrl').val(s.whatsapp_api_url || '');
                $('#setWhatsappApiKey').val(s.whatsapp_api_key || '');
                $('#setWhatsappInstanceId').val(s.whatsapp_instance_id || '');
            }
        }, 'json');
        
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
            records_per_page: $('#setRecordsPerPage').val(),
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
        
        $.post('api.php', {action: 'update_system_settings_batch', settings: JSON.stringify(settings)}, function(res) {
            showToast(res.message || 'Settings saved!', res.status === 'success' ? 'success' : 'error');
        }, 'json');
    }
    
    function saveDisplaySettings() {
        let settings = {
            date_format: $('#setDateFormat').val(),
            time_format: $('#setTimeFormat').val(),
            auto_refresh_interval: $('#setAutoRefresh').val(),
            theme_color: $('#setThemeColorPicker').val(),
            data_retention_days: $('#setDataRetention').val()
        };
        
        $.post('api.php', {action: 'update_system_settings_batch', settings: JSON.stringify(settings)}, function(res) {
            showToast(res.message || 'Settings saved!', res.status === 'success' ? 'success' : 'error');
            // Apply theme color
            document.documentElement.style.setProperty('--primary', settings.theme_color);
        }, 'json');
    }
    
    function setThemeColor(color) {
        $('#setThemeColorPicker').val(color);
        document.documentElement.style.setProperty('--primary', color);
        // Update header color
        $('th').css('background-color', color + '!important');
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
</script>
</body>
</html>