<?php
session_start();
require_once 'config.php';

// Session Check - All logged in users can access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First QC Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --bg-color:#f4f6f9; --text-color:#212529; --card-bg:#ffffff; --input-bg:#ffffff; --border-color:#dee2e6; --readonly-bg:#f8f9fa; --readonly-text:#495057; --active-item:#e7f1ff; --label-color:#000; }
        body.dark-mode { --bg-color:#121212; --text-color:#e0e0e0; --card-bg:#1e1e1e; --input-bg:#2d2d2d; --border-color:#444; --readonly-bg:#252525; --readonly-text:#adb5bd; --active-item:#2c3e50; --label-color:#e0e0e0; }
        body, html { height:100%; margin:0; padding:0; background-color:var(--bg-color); color:var(--text-color); font-size:13px; }
        
        /* Disable spell check red underline */
        * { -webkit-spellcheck: false; spellcheck: false; }
        input, textarea, td[contenteditable], [contenteditable="true"] {
            -webkit-spellcheck: false;
            spellcheck: false;
            -moz-spellcheck: false;
        }
        
        #mainContainer { display:flex; flex-direction:column; height:100vh; padding:10px; box-sizing:border-box; }
        
        #dataPanel { flex:1 1 auto; background:var(--card-bg); border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); overflow:hidden; display:flex; min-height:200px; border:1px solid var(--border-color); }
        .record-list-container { width:250px; flex:0 0 250px; border-right:1px solid var(--border-color); display:flex; flex-direction:column; background:var(--readonly-bg); }
        .record-list-header { padding:8px; background:#0d6efd; color:white; font-weight:bold; }
        .record-list { overflow-y:auto; flex:1; padding:0; margin:0; list-style:none; }
        .record-item { padding:8px 10px; border-bottom:1px solid var(--border-color); cursor:pointer; display:flex; justify-content:space-between; align-items:center; transition: background-color 0.3s; color: var(--text-color); }
        .record-item:hover { background-color:rgba(0,0,0,0.05); }
        .record-item.active { background-color:#9ec5fe!important; border-left:4px solid #0d6efd; color:#000!important; }
        
        /* Row status colors - for sidebar items */
        .record-item.completed, .record-item.completed-row { background-color:#f8d7da!important; color:#721c24!important; }
        .record-item.done, .record-item.saved-row { background-color:#ffe5d0!important; color:#8a4500!important; } /* ORANGE - QC Pending */
        .record-item.deo-done { background-color:#d4edda!important; color:#155724!important; } /* GREEN - First QC Done */
        .record-item.qc-done { background-color:#cfe2ff!important; color:#084298!important; } /* BLUE */
        .record-item.pending-qc { background-color:#ffe5d0!important; color:#8a4500!important; } /* ORANGE - QC Pending */
        .record-item.qc-approved { background-color:#cfe2ff!important; color:#084298!important; } /* BLUE */
        .record-item.qc-rejected { background-color:#f8d7da!important; color:#721c24!important; }
        .record-item.reported-record { border-left: 4px solid #fd7e14 !important; background-color: #fff9ec !important; }
        .record-item.reported-record.active { background-color:#9ec5fe!important; border-left:4px solid #fd7e14 !important; }
        
        /* Active always on top of other colors */
        .record-item.completed.active, .record-item.done.active, 
        .record-item.completed-row.active, .record-item.saved-row.active,
        .record-item.deo-done.active, .record-item.qc-done.active,
        .record-item.pending-qc.active, .record-item.qc-approved.active, .record-item.qc-rejected.active { background-color:#9ec5fe!important; color:#000!important; }
        
        .record-status-dot { height:8px; width:8px; border-radius:50%; display:inline-block; }
        .record-no { color:#212529; }
        .record-name { color:#6c757d; }
        
        /* Status Colors */
        .status-done { background-color:#27ae60; } /* GREEN - First QC Done */
        .status-pending { background-color:#ffc107; } /* Yellow - Pending */
        .status-completed { background-color:#dc3545; } /* RED for Completed */
        .status-deo-done { background-color:#27ae60; } /* GREEN - First QC Done */
        .status-qc-done { background-color:#0d6efd; } /* BLUE - QC Done */
        .status-pending-qc { background-color:#fd7e14; } /* ORANGE - Waiting QC */
        .status-qc-approved { background-color:#0d6efd; } /* BLUE - QC Approved */
        .status-qc-rejected { background-color:#dc3545; } /* Red - QC Rejected */

        .data-entry-form { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        .form-header { padding:8px 15px; border-bottom:1px solid var(--border-color); background:var(--card-bg); display:flex; justify-content:space-between; align-items:center; }
        .form-body { flex:1; overflow-y:auto; padding:15px; }
        .form-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:10px; }
        .form-group label { display:block; font-size:11px; margin-bottom:2px; font-weight:bold; color:var(--label-color); }
        .form-group input { width:100%; font-size:13px; padding:4px 8px; border:1px solid var(--border-color); border-radius:4px; background:var(--input-bg); color:var(--text-color); }
        .form-group input:focus { border-color:#0d6efd; outline:none; }
        /* Completed field - selectable, navigable but not editable */
        .form-group input.completed-field { background-color:#f8f9fa; cursor:text; color:#495057; }
        .form-group input.completed-field:focus { border-color:#dc3545; outline:none; box-shadow: 0 0 3px #dc3545; }
        /* Unsaved field - edited data highlight (HIGH PRIORITY) */
        .unsaved-field { border:2px solid #ffc107!important; background-color:#fff3cd!important; color:#000!important; box-shadow: 0 0 5px #ffc107; }
        /* Filled field - saved/done data highlight (GREEN) */
        .filled-field { border:2px solid #28a745!important; background-color:#d4edda!important; color:#155724!important; }
        .completed-field.filled-field { border:2px solid #28a745!important; background-color:#d4edda!important; color:#155724!important; }
        
        /* ========== DARK MODE SPECIFIC STYLES ========== */
        body.dark-mode .record-list-container { background:#1a1a1a; }
        body.dark-mode .record-item { color:#e0e0e0; border-bottom-color:#333; }
        body.dark-mode .record-item:hover { background-color:rgba(255,255,255,0.05); }
        body.dark-mode .record-item .record-no { color:#fff; font-weight:600; }
        body.dark-mode .record-item span { color:#e0e0e0; }
        
        /* Dark mode - status colors with visible text */
        body.dark-mode .record-item.done, body.dark-mode .record-item.saved-row { background-color:#1e4620!important; color:#8eda8e!important; }
        body.dark-mode .record-item.completed, body.dark-mode .record-item.completed-row { background-color:#4a1e1e!important; color:#f5a5a5!important; }
        body.dark-mode .record-item.active { background-color:#1e3a5f!important; color:#9ec5fe!important; }
        body.dark-mode .record-item.done.active, body.dark-mode .record-item.completed.active { background-color:#1e3a5f!important; color:#9ec5fe!important; }
        
        /* Dark mode form inputs */
        body.dark-mode .form-group input { background:#2d2d2d; color:#e0e0e0; border-color:#444; }
        body.dark-mode .form-group input:focus { border-color:#0d6efd; }
        body.dark-mode .form-group input.completed-field { background-color:#252525; color:#adb5bd; }
        
        /* Dark mode filled/unsaved fields - keep bright colors for visibility */
        body.dark-mode .unsaved-field { border:2px solid #ffc107!important; background-color:#3d3200!important; color:#ffd700!important; box-shadow: 0 0 5px #ffc107; }
        body.dark-mode .filled-field { border:2px solid #28a745!important; background-color:#1e4620!important; color:#8eda8e!important; }
        body.dark-mode .completed-field.filled-field { border:2px solid #28a745!important; background-color:#1e4620!important; color:#8eda8e!important; }
        
        /* Dark mode header and stats */
        body.dark-mode .header-bar { background:linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); }
        body.dark-mode .stat-box { color:#fff; }
        .stat-box.stat-active-filter { outline: 3px solid #fff; outline-offset: 2px; transform: scale(1.08); transition: transform 0.15s; }
        body.dark-mode .form-header { background:#1e1e1e; border-color:#333; }
        body.dark-mode .form-header span, body.dark-mode .form-header strong { color:#e0e0e0; }
        body.dark-mode #activeRecordNo { color:#9ec5fe!important; }
        
        /* Dark mode image viewer */
        body.dark-mode #imageViewerContainer { background:#1a1a2e; }
        body.dark-mode .img-controls-bar { background:#16213e; }
        body.dark-mode .viewer-body { background:#111; }
        
        /* Dark mode badges */
        body.dark-mode .badge { font-weight:600; }
        
        /* Dark mode scrollbar */
        body.dark-mode ::-webkit-scrollbar { width:8px; height:8px; }
        body.dark-mode ::-webkit-scrollbar-track { background:#1a1a1a; }
        body.dark-mode ::-webkit-scrollbar-thumb { background:#444; border-radius:4px; }
        body.dark-mode ::-webkit-scrollbar-thumb:hover { background:#555; }

        #imageViewerContainer { display:none; flex:0 0 350px; background:#2c3e50; color:white; padding:5px; border-radius:8px; position:relative; box-shadow:0 -4px 12px rgba(0,0,0,0.3); margin-top:5px; flex-direction:column; overflow:hidden; }
        .viewer-body { flex:1; overflow:auto; background:#222; border:1px solid #555; position:relative; display:block; padding:50px; }
        #imgWrapper { transform-origin:0 0; transition:transform 0.1s; position:relative; cursor:grab; display:inline-block; }
        #imgWrapper:active { cursor:grabbing; }
        #zoomImage { display:block; max-width:none; width:auto; height:auto; transition:filter 0.1s; }
        #highlightLayer { position:absolute; top:0; left:0; pointer-events:none; }

        #divider { height:10px; background:#ccc; cursor:row-resize; display:none; align-items:center; justify-content:center; margin:5px 0; }
        #successMessage { position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:10000; padding:15px 30px; background-color:#28a745; color:white; border-radius:8px; display:none; }
        #validationTooltip { position:absolute; padding:8px 12px; border-radius:4px; z-index:11000; max-width:300px; display:none; box-shadow:0 4px 8px rgba(0,0,0,0.4); font-size:12px; pointer-events:none; border:1px solid #ccc; background:#333; color:white; }
        .magnifier-lens { position:absolute; width:150px; height:150px; border:2px solid #fff; border-radius:50%; cursor:none; display:none; pointer-events:none; z-index:1000; box-shadow:0 0 10px rgba(0,0,0,0.8); background-repeat:no-repeat; background-color:#000; }
        .img-controls-bar { display: flex; flex-wrap: wrap; gap: 5px; padding: 5px; background: #34495e; align-items: center; }
        .filter-group { display: flex; align-items: center; gap: 3px; background: rgba(0,0,0,0.2); padding: 2px 5px; border-radius: 4px; }
        .filter-group label { font-size: 10px; margin: 0; color: #ccc; }
        
        /* UI/UX Improvements - Phase 6 */
        #toastContainer { position:fixed; top:20px; right:20px; z-index:10001; display:flex; flex-direction:column; gap:10px; }
        .toast-notification { padding:12px 20px; border-radius:8px; color:white; font-weight:500; animation: slideIn 0.3s ease; box-shadow:0 4px 12px rgba(0,0,0,0.3); display:flex; align-items:center; gap:10px; max-width:350px; }
        .toast-success { background:linear-gradient(135deg, #28a745, #20c997); }
        .toast-error { background:linear-gradient(135deg, #dc3545, #e74c3c); }
        .toast-warning { background:linear-gradient(135deg, #ffc107, #f39c12); color:#333; }
        .toast-info { background:linear-gradient(135deg, #17a2b8, #3498db); }
        @keyframes slideIn { from { transform:translateX(100%); opacity:0; } to { transform:translateX(0); opacity:1; } }
        @keyframes slideOut { from { transform:translateX(0); opacity:1; } to { transform:translateX(100%); opacity:0; } }
        
        #progressBar { position:fixed; top:0; left:0; width:100%; height:3px; background:#e0e0e0; z-index:10002; display:none; }
        #progressBar .progress-fill { height:100%; background:linear-gradient(90deg, #0d6efd, #20c997); width:0%; transition:width 0.3s; }
        
        .btn { transition: all 0.2s ease; }
        .btn:active { transform:scale(0.95); }
        
        ::-webkit-scrollbar { width:8px; height:8px; }
        ::-webkit-scrollbar-track { background:var(--bg-color); }
        ::-webkit-scrollbar-thumb { background:#888; border-radius:4px; }
        ::-webkit-scrollbar-thumb:hover { background:#666; }
        
        .form-control:focus, .form-select:focus { box-shadow:0 0 0 3px rgba(13,110,253,0.25); }
        
        /* Target Progress Bar */
        #targetProgress { height:8px; background:#e0e0e0; border-radius:4px; overflow:hidden; margin-top:5px; }
        #targetProgress .progress-fill { height:100%; background:linear-gradient(90deg, #28a745, #20c997); transition:width 0.5s; }
        
        /* Real-time Clock */
        #realTimeClock { font-family:monospace; }
        
        /* Stat Boxes - Compact */
        .stat-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 4px 12px;
            border-radius: 6px;
            min-width: 65px;
            text-align: center;
        }
        .stat-num {
            font-size: 16px;
            font-weight: bold;
            line-height: 1.2;
        }
        .stat-lbl {
            font-size: 9px;
            opacity: 0.9;
        }
        
        /* Admin Message Popup */
        .message-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .message-popup {
            background: white;
            border-radius: 15px;
            padding: 0;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 10px 50px rgba(0,0,0,0.5);
            animation: slideIn 0.3s ease;
            overflow: hidden;
        }
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .message-popup.urgent { border: 3px solid #dc3545; }
        .message-popup.warning { border: 3px solid #ffc107; }
        .message-popup.normal { border: 3px solid #0d6efd; }
        
        .message-header {
            padding: 15px 20px;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .message-header.urgent { background: linear-gradient(135deg, #dc3545, #c82333); }
        .message-header.warning { background: linear-gradient(135deg, #ffc107, #e0a800); color: #000; }
        .message-header.normal { background: linear-gradient(135deg, #0d6efd, #0b5ed7); }
        
        .message-header .icon { font-size: 28px; }
        .message-header .title { font-size: 18px; font-weight: bold; }
        
        .message-body {
            padding: 25px;
            font-size: 16px;
            line-height: 1.6;
            color: #333;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .message-footer {
            padding: 15px 25px;
            background: #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #eee;
        }
        .message-footer .time { font-size: 12px; color: #666; }
        .message-footer .btn-ok {
            padding: 10px 40px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 25px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .message-footer .btn-ok.urgent { background: #dc3545; color: white; }
        .message-footer .btn-ok.warning { background: #ffc107; color: #000; }
        .message-footer .btn-ok.normal { background: #0d6efd; color: white; }
        .message-footer .btn-ok:hover { transform: scale(1.05); }
    </style>
</head>
<body>

<?php include 'validation_rules.php'; ?>

<!-- Admin Message Container -->
<div id="adminMessageContainer"></div>

<!-- Progress Bar -->
<div id="progressBar"><div class="progress-fill"></div></div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<div id="loader" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;color:white;"><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;"><h4>Loading...</h4><div class="spinner-border"></div></div></div>
<div id="successMessage">✔️ Saved!</div>

<div id="mainContainer" style="display:none;">
    <div id="topControls">
        <!-- Single Row: All Controls Properly Aligned -->
        <div class="d-flex justify-content-between align-items-center mb-2" style="background:var(--card-bg); padding:10px 15px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            
            <!-- Left: Dashboard Title + User Badge + Timer -->
            <div class="d-flex align-items-center gap-3">
                <h5 class="m-0" style="font-size:16px;">✅ First QC Dashboard <span id="userBadge" class="badge bg-primary fs-6" style="font-size:12px!important;"></span></h5>
                <span id="globalTimer" class="badge bg-danger px-2 py-1" style="font-size:12px;">⏱️ 00:00:00</span>
                <span id="realTimeClock" class="badge bg-secondary px-2 py-1" style="font-size:11px;"></span>
            </div>
            
            <!-- Center: Stats Cards -->
            <div class="d-flex align-items-center gap-2">
                <div class="stat-box bg-warning text-dark">
                    <span class="stat-num" id="totalPendingCount">0</span>
                    <span class="stat-lbl">First QC Pending</span>
                </div>
                <div class="stat-box text-white" id="qcPendingBox" style="display:none; background:#27ae60;">
                    <span class="stat-num" id="qcPendingCount">0</span>
                    <span class="stat-lbl">First QC Done</span>
                </div>
                <div class="stat-box bg-primary text-white" id="qcDoneBox" style="display:none;">
                    <span class="stat-num" id="qcDoneCount">0</span>
                    <span class="stat-lbl">Second QC Done</span>
                </div>
                <div class="stat-box bg-danger text-white">
                    <span class="stat-num" id="totalCompletedCount">0</span>
                    <span class="stat-lbl">Final Completed</span>
                </div>
                <div class="stat-box text-white" style="background:#c0392b;">
                    <span class="stat-num" id="todayCompletedCount">0</span>
                    <span class="stat-lbl">Final Today Completed</span>
                </div>
                <div class="stat-box text-white" style="background:#e67e22; display:none;" id="reportCountBox">
                    <span class="stat-num" id="reportCount">0</span>
                    <span class="stat-lbl">Reported</span>
                </div>
            </div>
            
            <!-- Right: Controls -->
            <div class="d-flex align-items-center gap-2">
                <select id="statusFilter" class="form-select form-select-sm" style="width:130px; font-size:11px;" onchange="filterData()">
                    <option value="">All</option>
                    <option value="pending">First QC Pending</option>
                    <option value="qc_pending">First QC Done</option>
                    <option value="qc_done">Second QC Done</option>
                    <option value="Completed">Final Completed</option>
                    <option value="reported">⚠️ Reported</option>
                </select>
                <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="🔍 Search" onkeyup="filterData()" style="width:120px; font-size:11px;" spellcheck="false" autocomplete="off">
                <div class="form-check form-switch m-0" style="min-height:auto;">
                    <input class="form-check-input" type="checkbox" id="autoScrollCheck" checked style="cursor:pointer;">
                </div>
                <button class="btn btn-success btn-sm px-2 py-1" onclick="loadData()" style="font-size:11px;">🔄</button>
                <button class="btn btn-info btn-sm px-2 py-1" onclick="syncImages()" style="font-size:11px;">📷</button>
                <button class="btn btn-outline-secondary btn-sm px-2 py-1" onclick="toggleTheme()" style="font-size:11px;">🌓</button>
                <a href="logout.php" class="btn btn-dark btn-sm px-2 py-1" style="font-size:11px;">Logout</a>
            </div>
        </div>
    </div>

    <!-- DATA PANEL -->
    <div id="dataPanel">
        <div class="record-list-container">
            <div class="record-list-header">My Records (<span id="totalRecords">0</span>)</div>
            <ul id="sidebarList" class="record-list"></ul>
            <div id="paginationControls" class="p-2 border-top bg-light text-center"><button class="btn btn-sm btn-secondary" onclick="changePage(-1)">Prev</button><span id="pageInfo" class="mx-2">1/1</span><button class="btn btn-sm btn-primary" onclick="changePage(1)">Next</button></div>
        </div>

        <div class="data-entry-form">
            <div class="form-header">
                <div><strong>Rec: </strong><span id="activeRecordNo" class="text-primary fw-bold">---</span><span id="activeStatusBadge" class="badge bg-secondary ms-2">---</span></div>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-warning btn-sm px-2 py-1" id="btnReportAdmin" onclick="openReportModal(activeRecord ? activeRecord.record_no : null)" style="font-size:11px; display:none;">
                        ⚠️ Report to Admin
                    </button>
                    <span class="badge bg-danger fs-6 me-1" id="formTimer">0m 0s</span>
                    <button class="btn btn-primary btn-sm" onclick="saveActiveForm()">SAVE / DONE</button>
                </div>
            </div>
            <div class="form-body"><div id="formGrid" class="form-grid"><div class="text-center text-muted mt-5">Select a record from the list to start working.</div></div></div>
        </div>
    </div>

    <div id="divider"></div>
    <div id="imageViewerContainer">
        <div class="d-flex justify-content-between p-1 bg-dark">
            <small>Image: <span id="imageFileName" class="text-warning"></span> <span id="ocrStatus" class="ms-2 text-info"></span></small>
            <button onclick="$('#imageViewerContainer').hide(); $('#divider').hide();" class="btn btn-sm btn-danger py-0">✖</button>
        </div>
        <div class="viewer-body" id="viewerBody"><div id="magnifierLens" class="magnifier-lens"></div><div id="imgWrapper"><img id="zoomImage" src=""><canvas id="highlightLayer"></canvas></div><div id="imageUploadFallback" style="display:none;position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);flex-direction:column;align-items:center;justify-content:center;"><p>Image Missing. Record: <span id="fallbackRecordNo"></span></p><input type="file" id="singleImageFile" class="form-control w-50 mb-2"><button onclick="uploadSingleImage()" class="btn btn-warning btn-sm">Upload Image</button></div></div>
        <div class="img-controls-bar" style="background:#34495e; padding:5px;">
            <button class="btn btn-sm btn-light" onclick="adjustZoom(0.2)">+</button>
            <button class="btn btn-sm btn-light" onclick="adjustZoom(-0.2)">-</button>
            <button class="btn btn-sm btn-warning" onclick="resetZoom()">Reset</button>
            <button class="btn btn-sm btn-info" onclick="rotateImg()">Rot</button>
            <button class="btn btn-sm btn-secondary" onclick="toggleInvert()">Inv</button>
            <button class="btn btn-sm btn-outline-danger" id="btnLock" onclick="toggleImageLock()" title="Lock Image">🔓</button>
            <button class="btn btn-sm btn-outline-warning" id="btnMag" onclick="toggleMagnifier()">🔍</button>
            <div class="d-flex align-items-center gap-1 ms-2">
                <small class="text-light">☀</small><input type="range" min="50" max="200" value="100" id="brightRange" oninput="applyFilters()" style="width:60px">
                <small class="text-light">◑</small><input type="range" min="50" max="200" value="100" id="contrastRange" oninput="applyFilters()" style="width:60px">
            </div>
        </div>
    </div>
</div>

<div id="validationTooltip"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let currentUser=null, allClientData=[], filteredData=[], currentPage=1, itemsPerPage=50;
    let rowTimes=JSON.parse(sessionStorage.getItem('rowTimes')||'{}'), activeRowId=null, rowStart=0, rowInterval, activeRecord=null;
    let currentScale=1.4, currentRot=0, initialW=0, initialH=0, ocrWorker=null, ocrData=null, filterB=100, filterC=100, isInverted=false, isMagnifierActive=false;
    let syncInterval;
    let isImageLocked = false;

    $(document).ready(()=>checkSession());

    function checkSession() {
        if(localStorage.getItem('theme')==='dark') document.body.classList.add('dark-mode');
        $.post('api.php', {action:'check_session'}, function(res){
            if(res.status==='logged_in'){
                if(res.role === 'admin') { window.location.href = 'p1_admin_dashboard.php'; return; }
                if(res.role === 'supervisor') { window.location.href = 'supervisor_dashboard.php'; return; }
                currentUser = res;
                $('#mainContainer').fadeIn().css('display','flex');
                // Set user badge with uppercase name
                let displayName = (res.full_name || res.username).toUpperCase();
                $('#userBadge').text(displayName);
                loadData(); 
                startRealtimeSync(); 
                initAutoLogout(); startGlobalTimer(); registerUnloadLogout();
            } else if(res.status==='maintenance') {
                // Show maintenance message
                $('body').html(`
                    <div style="display:flex; height:100vh; align-items:center; justify-content:center; background:#f8d7da; flex-direction:column;">
                        <div style="background:white; padding:40px; border-radius:10px; text-align:center; max-width:500px; box-shadow:0 5px 20px rgba(0,0,0,0.2);">
                            <h1 style="color:#dc3545;">🔧 System Maintenance</h1>
                            <p style="font-size:18px; color:#333; margin:20px 0;">${res.message || 'System is under maintenance.'}</p>
                            <p style="color:#666;">Please try again later or contact administrator.</p>
                            <button onclick="window.location.href='login.php'" class="btn btn-primary mt-3">Back to Login</button>
                        </div>
                    </div>
                `);
            } else window.location.href = 'login.php';
        },'json');
    }
    
    function logout() {
        sessionStorage.removeItem('globalTimerStart');
        sessionStorage.removeItem('rowTimes');
        $.post('api.php', {action:'logout'}, ()=>location.reload());
    }

    function registerUnloadLogout() {
        // Tab/browser band hone par server se logout karo
        // sendBeacon use karo - fetch/XHR band hone wali tab mein work nahi karte
        window.addEventListener('beforeunload', function() {
            // sessionStorage clear - timer reset ho jaayega next login par
            sessionStorage.removeItem('globalTimerStart');
            sessionStorage.removeItem('rowTimes');
            // Server se logout - sendBeacon reliable hai page unload par
            let fd = new FormData();
            fd.append('action', 'logout');
            navigator.sendBeacon('api.php', fd);
        });

        // Visibility change: tab switch aur wapas aane par bhi check karo
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                // Wapas aane par session check karo
                $.post('api.php', {action:'check_session'}, function(res) {
                    if (res.status !== 'logged_in') {
                        sessionStorage.removeItem('globalTimerStart');
                        location.href = 'login.php';
                    }
                }, 'json');
            }
        });
    }
    function toggleTheme(){ document.body.classList.toggle('dark-mode'); localStorage.setItem('theme',document.body.classList.contains('dark-mode')?'dark':'light'); }
    function initAutoLogout() { let t; const r=()=>{clearTimeout(t);t=setTimeout(logout,900000);}; window.onload=r;window.onmousemove=r;window.onkeypress=r;window.onclick=r; }
    function startGlobalTimer() {
        // sessionStorage use karo - tab/browser band hone par automatically reset ho jaata hai
        // Naya login = naya sessionStorage = timer 0 se shuru
        let start = parseInt(sessionStorage.getItem('globalTimerStart')) || Date.now();
        sessionStorage.setItem('globalTimerStart', start);
        setInterval(()=>{
            let d=Math.floor((Date.now()-start)/1000),
                h=Math.floor(d/3600).toString().padStart(2,'0'),
                m=Math.floor((d%3600)/60).toString().padStart(2,'0'),
                s=(d%60).toString().padStart(2,'0');
            $('#globalTimer').text(`⏱️ ${h}:${m}:${s}`);
        },1000);
    }
    
    // Heartbeat to track online status
    function sendHeartbeat() { $.post('api.php', {action: 'heartbeat'}); }
    
    // Track already processed changes to avoid duplicates
    let processedChanges = new Set();

    // ─── QC Visibility Control ───────────────────────────────────
    function applyQCVisibility(enabled) {
        if (enabled) {
            $('#qcPendingBox').show();
            $('#qcDoneBox').show();
            $('#statusFilter option[value="qc_pending"]').show();
            $('#statusFilter option[value="qc_done"]').show();
        } else {
            $('#qcPendingBox').hide();
            $('#qcDoneBox').hide();
            $('#statusFilter option[value="qc_pending"]').hide();
            $('#statusFilter option[value="qc_done"]').hide();
            // Reset filter if currently on QC filter
            if ($('#statusFilter').val() === 'qc_pending' || $('#statusFilter').val() === 'qc_done') {
                $('#statusFilter').val(''); filterData();
            }
        }
    }
    // ─────────────────────────────────────────────────────────────
    
    // ─── Admin Reply Notifications: Only in Second QC Dashboard (qc_dashboard.php) ───

    function startRealtimeSync() {
        let lastSyncTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
        
        // Load QC status on startup
        $.post('api.php', {action:'get_qc_status'}, function(r){ if(r.status==='success') applyQCVisibility(r.qc_enabled==='1'); }, 'json');
        
        // Admin reply notifications: not needed on First QC Dashboard
        
        // Send heartbeat immediately and every 30 seconds
        sendHeartbeat();
        setInterval(sendHeartbeat, 30000);
        
        // Fast sync every 3 seconds
        syncInterval = setInterval(() => {
            $.post('api.php', {action: 'sync_changes', last_sync: lastSyncTime}, function(res) {
                if(res.status === 'success') {
                    lastSyncTime = res.server_time;
                    
                    // QC visibility toggle
                    if(res.qc_enabled !== undefined) {
                        applyQCVisibility(res.qc_enabled === '1');
                    }
                    
                    // Reported count — user-specific from allClientData
                    // (after changes applied below, updateCounts will recalculate)

                    if(res.changes && res.changes.length > 0) {
                        let newChangesCount = 0;
                        
                        res.changes.forEach(changedRecord => {
                            let changeKey = `${changedRecord.id}-${changedRecord.updated_at}`;
                            if(processedChanges.has(changeKey)) return;
                            processedChanges.add(changeKey);
                            newChangesCount++;
                            
                            let idx = allClientData.findIndex(r => r.id == changedRecord.id);
                            if(idx >= 0) {
                                allClientData[idx] = changedRecord;
                            } else {
                                allClientData.push(changedRecord);
                            }
                            
                            let $item = $(`.record-item[data-id="${changedRecord.id}"]`);
                            if($item.length) {
                                let dot = $item.find('.record-status-dot');
                                // Remove all status classes
                                dot.removeClass('status-pending status-done status-deo-done status-qc-done status-completed status-qc-rejected');
                                $item.removeClass('done deo-done qc-done pending-qc qc-approved completed qc-rejected');
                                
                                const rs = changedRecord.row_status;
                                if(rs === 'done') {
                                    dot.addClass('status-done'); $item.addClass('done');
                                } else if(rs === 'deo_done' || rs === 'pending_qc') {
                                    dot.addClass('status-deo-done'); $item.addClass('deo-done');
                                } else if(rs === 'qc_done' || rs === 'qc_approved') {
                                    dot.addClass('status-qc-done'); $item.addClass('qc-done');
                                } else if(rs === 'Completed') {
                                    dot.addClass('status-completed'); $item.addClass('completed');
                                } else if(rs === 'qc_rejected') {
                                    dot.addClass('status-qc-rejected'); $item.addClass('qc-rejected');
                                } else {
                                    dot.addClass('status-pending');
                                }
                                
                                // Handle reported indicator realtime
                                let isRep = (parseInt(changedRecord.is_reported)||0) > 0 || (parseInt(changedRecord.report_count)||0) > 0;
                                if(isRep) {
                                    $item.addClass('reported-record');
                                    if(!$item.find('.rep-badge').length) {
                                        $item.find('.record-no').append('<span class="rep-badge" style="color:#fd7e14;font-size:11px;font-weight:bold;"> ⚠️</span>');
                                    }
                                } else {
                                    $item.removeClass('reported-record');
                                    $item.find('.rep-badge').remove();
                                }
                                
                                $item.css('background-color', '#fff3cd');
                                setTimeout(() => { $item.css('background-color', ''); }, 1200);
                            }
                            
                            // Check if this is the active record changed by admin
                            if(activeRecord && changedRecord.id == activeRecord.id) {
                                if(changedRecord.row_status !== activeRecord.row_status) {
                                    activeRecord = changedRecord;
                                    updateActiveRecordBadge();
                                    
                                    if(changedRecord.row_status === 'Completed') {
                                        showToast('⚠️ This record was marked COMPLETED by admin!', 'warning', 3000);
                                    }
                                }
                            }
                        });
                        
                        // Keep set from growing too large
                        if(processedChanges.size > 500) {
                            processedChanges.clear();
                        }
                        // Recalculate counts from allClientData (user-specific)
                        updateCounts();
                    }
                }
            }, 'json');
        }, 3000);
    }
    
    function updateActiveRecordBadge() {
        if(!activeRecord) return;
        let badgeClass = 'bg-warning text-dark';
        let badgeText = 'PENDING';
        if (activeRecord.row_status === 'done') {
            badgeClass = 'bg-success'; badgeText = 'DONE';
        } else if (activeRecord.row_status === 'Completed') {
            badgeClass = 'bg-danger'; badgeText = 'COMPLETED';
        }
        $('#activeStatusBadge').text(badgeText).attr('class', `badge ${badgeClass} ms-2`);
    }

    function refreshSidebarStatuses() {
        $('.record-item').each(function() {
            let id = $(this).data('id');
            let rec = allClientData.find(r => r.id == id);
            if (rec) {
                let dot = $(this).find('.record-status-dot');
                dot.removeClass('status-pending status-done status-completed');
                if (rec.row_status === 'done') dot.addClass('status-done');
                else if (rec.row_status === 'Completed') dot.addClass('status-completed');
                else dot.addClass('status-pending');
            }
        });
    }

    function checkActiveRecordStatus() {
        if (!activeRecord) return;
        let freshRecord = allClientData.find(r => r.id == activeRecord.id);
        if (freshRecord && freshRecord.row_status !== activeRecord.row_status) {
            activeRecord.row_status = freshRecord.row_status;
            let badgeClass = 'bg-warning text-dark';
            let badgeText = 'PENDING';
            if (activeRecord.row_status === 'done') {
                badgeClass = 'bg-success'; badgeText = 'DONE';
            } else if (activeRecord.row_status === 'Completed') {
                badgeClass = 'bg-danger'; badgeText = 'COMPLETED';
            }
            $('#activeStatusBadge').text(badgeText).attr('class', `badge ${badgeClass} ms-2`);
        }
    }

    function loadData() { 
        $('#loader').show(); 
        $.post('api.php', {action:'load_data'}, function(d){ 
            $('#loader').hide(); 
            allClientData=d; 
            // Debug: Check if OVD field exists in data
            if(d.length > 0) {
                console.log('Sample record keys:', Object.keys(d[0]));
                console.log('OVD in first record:', d[0].officially_valid_documents);
            }
            filterData(); 
            updateCounts(); 
        },'json'); 
    }
    
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
    
    function filterData() { 
        let txt = $('#searchInput').val().toLowerCase();
        let status = $('#statusFilter').val();
        
        filteredData = allClientData.filter(r => {
            let matchesText = r.record_no.toLowerCase().includes(txt) || (r.name||'').toLowerCase().includes(txt);
            let matchesStatus = true;
            if(status) {
                if(status === 'pending') matchesStatus = (r.row_status === 'pending');
                else if(status === 'qc_pending') matchesStatus = (r.row_status === 'deo_done' || r.row_status === 'pending_qc' || r.row_status === 'done');
                else if(status === 'qc_done') matchesStatus = (r.row_status === 'qc_done' || r.row_status === 'qc_approved');
                else if(status === 'Completed') matchesStatus = (r.row_status === 'Completed');
            }
            return matchesText && matchesStatus;
        });
        currentPage=1; renderSidebar(); updateCounts();
    }
    
    function filterSidebar(txt) { $('#searchInput').val(txt); filterData(); }
    
    function updateCounts() { 
        let today = new Date().toISOString().split('T')[0];
        let activeFilter = $('#statusFilter').val();
        let searchTxt = ($('#searchInput').val()||'').toLowerCase().trim();
        let hasFilter = activeFilter; // sirf status filter pe X/Y format — search pe nahi
        
        // Always from full user data (allClientData)
        let totalPending   = allClientData.filter(r => r.row_status === 'pending').length;
        let totalCompleted = allClientData.filter(r => r.row_status === 'Completed').length;
        let todayCompleted = allClientData.filter(r => {
            if (r.row_status !== 'Completed' || !r.updated_at) return false;
            return r.updated_at.substring(0,10) === today;
        }).length;
        let qcPending      = allClientData.filter(r => r.row_status === 'deo_done' || r.row_status === 'pending_qc' || r.row_status === 'done').length;
        let qcDone         = allClientData.filter(r => r.row_status === 'qc_done' || r.row_status === 'qc_approved').length;
        let reportedCount  = allClientData.filter(r => r.is_reported == 1 || parseInt(r.report_count||0) > 0).length;
        
        // If filter active — highlight active stat box and show filtered count in parentheses
        if (hasFilter) {
            let fc = filteredData.length;
            
            let pendingActive   = (activeFilter === 'pending');
            let qcPendActive    = (activeFilter === 'qc_pending');
            let qcDoneActive    = (activeFilter === 'qc_done');
            let completedActive = (activeFilter === 'Completed');
            let reportedActive  = (activeFilter === 'reported');
            
            // Only show "X / Y" format when X != Y (filtered count differs from total)
            $('#totalPendingCount').text(pendingActive && fc !== totalPending     ? fc + ' / ' + totalPending   : totalPending);
            $('#qcPendingCount').text(   qcPendActive && fc !== qcPending        ? fc + ' / ' + qcPending      : qcPending);
            $('#qcDoneCount').text(      qcDoneActive && fc !== qcDone           ? fc + ' / ' + qcDone         : qcDone);
            $('#totalCompletedCount').text(completedActive && fc !== totalCompleted ? fc + ' / ' + totalCompleted : totalCompleted);
            
            // Today: show as-is (no filter maps to it)
            $('#todayCompletedCount').text(todayCompleted);
            
            // Active box highlight
            $('.stat-box').removeClass('stat-active-filter');
            if (pendingActive)   $('#totalPendingCount').closest('.stat-box').addClass('stat-active-filter');
            if (qcPendActive)    $('#qcPendingCount').closest('.stat-box').addClass('stat-active-filter');
            if (qcDoneActive)    $('#qcDoneCount').closest('.stat-box').addClass('stat-active-filter');
            if (completedActive) $('#totalCompletedCount').closest('.stat-box').addClass('stat-active-filter');
            if (reportedActive)  $('#reportCount').closest('.stat-box').addClass('stat-active-filter');
        } else {
            $('.stat-box').removeClass('stat-active-filter');
            $('#totalPendingCount').text(totalPending);
            $('#totalCompletedCount').text(totalCompleted);
            $('#todayCompletedCount').text(todayCompleted);
            $('#qcPendingCount').text(qcPending);
            $('#qcDoneCount').text(qcDone);
        }
        
        // Reported box
        if (reportedCount > 0) {
            $('#reportCount').text(reportedCount);
            $('#reportCountBox').show();
        } else {
            $('#reportCountBox').hide();
        }
        
        $('#totalRecords').text(allClientData.length);
    }

    function renderSidebar() {
        let start=(currentPage-1)*itemsPerPage, pageData=filteredData.slice(start, start+itemsPerPage), h='';
        pageData.forEach(r=>{
            let statusClass = 'status-pending';
            let itemClass = '';
            let qcByInfo = '';
            
            if (r.row_status === 'done') { statusClass = 'status-done'; itemClass = 'done'; }
            else if (r.row_status === 'deo_done' || r.row_status === 'pending_qc') { 
                statusClass = 'status-deo-done'; itemClass = 'deo-done'; 
            }
            else if (r.row_status === 'qc_done' || r.row_status === 'qc_approved') { 
                statusClass = 'status-qc-done'; itemClass = 'qc-done';
                if(r.qc_by) qcByInfo = `<br><small style="color:#0d6efd;">✅ QC: ${r.qc_by}</small>`;
            }
            else if (r.row_status === 'Completed') { 
                statusClass = 'status-completed'; itemClass = 'completed';
                if(r.qc_by) qcByInfo = `<br><small style="color:#dc3545;">QC: ${r.qc_by}</small>`;
            }
            else if (r.row_status === 'qc_rejected') { statusClass = 'status-qc-rejected'; itemClass = 'qc-rejected'; }
            
            let activeClass=(activeRecord&&activeRecord.id===r.id)?'active':'';
            let isReported=(r.is_reported==1||parseInt(r.report_count||0)>0);
            let reportedClass=isReported?'reported-record':'';
            let reportedBadge=isReported?'<span style="color:#fd7e14;font-size:11px;font-weight:bold;" title="Report to Admin kiya gaya hai"> ⚠️</span>':'';
            h+=`<li class="record-item ${activeClass} ${itemClass} ${reportedClass}" onclick="selectRecord(${r.id})" data-id="${r.id}">
                <div>
                    <span class="record-status-dot ${statusClass}"></span> 
                    <strong class="record-no">${r.record_no}</strong>${reportedBadge}
                    <br>
                    <small class="record-name">${r.name||'No Name'}</small>
                    ${qcByInfo}
                </div>
            </li>`;
        });
        $('#sidebarList').html(h); $('#pageInfo').text(`${currentPage}/${Math.ceil(filteredData.length/itemsPerPage)||1}`);
    }
    function changePage(d) { let max=Math.ceil(filteredData.length/itemsPerPage); if(currentPage+d>0 && currentPage+d<=max) { currentPage+=d; renderSidebar(); } }

    function selectRecord(id) {
        stopTimer();
        activeRecord = allClientData.find(r => r.id == id);
        if(!activeRecord) return;
        $('.record-item.active').removeClass('active'); $(`.record-item[data-id="${id}"]`).addClass('active');
        $('#activeRecordNo').text(activeRecord.record_no);
        
        let badgeClass = 'bg-warning text-dark';
        let badgeText = 'PENDING';
        if (activeRecord.row_status === 'done') {
            badgeClass = 'bg-success'; badgeText = 'DONE';
        } else if (activeRecord.row_status === 'deo_done' || activeRecord.row_status === 'pending_qc') {
            badgeClass = 'bg-success';           badgeText = '1st QC Done';
        } else if (activeRecord.row_status === 'qc_done' || activeRecord.row_status === 'qc_approved') {
            badgeClass = 'bg-primary'; badgeText = 'QC DONE';
        } else if (activeRecord.row_status === 'Completed') {
            badgeClass = 'bg-danger'; badgeText = 'COMPLETED';
        } else if (activeRecord.row_status === 'qc_rejected') {
            badgeClass = 'bg-danger'; badgeText = 'QC REJECTED';
        }
        
        $('#activeStatusBadge').text(badgeText).attr('class', `badge ${badgeClass} ms-2`);
        
        // Show Report to Admin button
        $('#btnReportAdmin').show();
        
        renderForm(activeRecord); openImage(activeRecord.record_no); startTimer(id);
        let dbTime=parseInt(activeRecord.time_spent)||0, locTime=rowTimes[id]||0; $('#formTimer').text(formatTime(dbTime+locTime));
    }

    function renderForm(rec) {
        let fields = ['kyc_number','name','guardian_name','gender','marital_status','dob','address','landmark','city','zip_code','city_of_birth','nationality','photo_attachment','residential_status','occupation','officially_valid_documents','annual_income','broker_name','sub_broker_code','bank_serial_no','second_applicant_name','amount_received_from','amount','arn_no','second_address','occupation_profession','remarks'];
        
        // Label aliases for better display
        let labelAliases = {
            'officially_valid_documents': 'OVD',
            'second_applicant_name': '2ND APPLICANT NAME',
            'photo_attachment': 'PHOTO',
            'residential_status': 'RES. STATUS',
            'occupation_profession': 'PROFESSION'
        };
        
        // Field name aliases (for different column names in database)
        let fieldAliases = {
            'officially_valid_documents': ['officially_valid_documents', 'ovd', 'OVD', 'Ovd']
        };
        
        let edited = JSON.parse(rec.edited_fields || '[]');
        let isCompleted = rec.row_status === 'Completed';
        let isDone = rec.row_status === 'done';
        let h = '';
        
        // Debug: log record data
        console.log('Rendering record:', rec.id, 'OVD value:', rec.officially_valid_documents, 'All keys:', Object.keys(rec));
        
        fields.forEach(f => {
            let label = labelAliases[f] || f.replace(/_/g, ' ').toUpperCase(); 
            
            // Try to get value from field or its aliases
            let rawVal = '';
            if (rec[f] !== null && rec[f] !== undefined) {
                rawVal = String(rec[f]);
            } else if (fieldAliases[f]) {
                // Try aliases
                for (let alias of fieldAliases[f]) {
                    if (rec[alias] !== null && rec[alias] !== undefined && rec[alias] !== '') {
                        rawVal = String(rec[alias]);
                        break;
                    }
                }
            }
            
            let val = rawVal.replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); 
            
            // Yellow = edited fields (any status)
            let fieldClass = '';
            if (edited.includes(f)) {
                fieldClass = 'unsaved-field'; // Yellow - edited
            }
            
            if(isCompleted) {
                // Completed: readonly input - can select text, tab navigate, but not edit
                h += `<div class="form-group"><label>${label}</label><input type="text" class="completed-field ${fieldClass}" data-field="${f}" value="${val}" readonly spellcheck="false" autocomplete="off" onfocus="handleFieldFocusCompleted(this)"></div>`;
            } else {
                h += `<div class="form-group"><label>${label}</label><input type="text" class="${fieldClass}" data-field="${f}" value="${val}" spellcheck="false" autocomplete="off" oninput="validateField(this)" onfocus="handleFieldFocus(this)" onblur="handleFieldBlur(this)"></div>`;
            }
        });
        $('#formGrid').html(h);
        
        // Show SAVE button for all (completed will show alert if clicked)
        $('#formGrid').append(`<div class="col-12 mt-3 text-center" style="grid-column:1/-1;"><button class="btn btn-primary w-100 py-2" id="btnSaveForm" onclick="saveActiveForm()">SAVE / DONE</button></div>`);
        $('#btnSaveForm').on('keydown', function(e) {
            if(e.key === 'Tab' && !e.shiftKey) {
                e.preventDefault();
                let currentItem = $(`.record-item[data-id="${activeRecord.id}"]`);
                let nextItem = currentItem.next('.record-item');
                if(nextItem.length) { nextItem.click(); setTimeout(() => $('#formGrid input').first().focus(), 50); }
                else { 
                      let max = Math.ceil(filteredData.length/itemsPerPage);
                      if(currentPage < max) { changePage(1); setTimeout(()=>{ $('#sidebarList .record-item').first().click(); setTimeout(()=>$('#formGrid input').first().focus(),50); },100); }
                      else { alert("End of list"); }
                }
            }
        });
    }
    
    // For completed fields - allow focus/highlight but no edit
    function handleFieldFocusCompleted(el) { highlightText($(el).val(), false, false); }

    function handleFieldFocus(el) { highlightText($(el).val(), false, false); validateField(el); }
    function handleFieldBlur(el) { 
        $('#validationTooltip').hide(); 
        let f=$(el).data('field'), v=$(el).val().trim(); 
        if(activeRecord && activeRecord[f]!==v) { 
            $(el).addClass('unsaved-field'); 
            // Update local record immediately
            activeRecord[f] = v;
            // Save to server
            $.post('api.php', {action:'update_cell', id:activeRecord.id, column:f, value:v}, function(res){
                if(res.status !== 'success') {
                    console.error('Failed to save field:', f, res.message);
                }
            }, 'json').fail(function(){
                console.error('Network error saving field:', f);
            }); 
        } 
    }
    
    function saveActiveForm() {
        if(!activeRecord) return;
        // Don't allow saving completed or qc_done records
        if(activeRecord.row_status === 'Completed') {
            alert('This record is Completed and cannot be modified.');
            return;
        }
        if(activeRecord.row_status === 'qc_done' || activeRecord.row_status === 'qc_approved') {
            alert('This record is already QC Done and cannot be modified.');
            return;
        }
        stopTimer();
        let totalTime = (parseInt(activeRecord.time_spent)||0) + (rowTimes[activeRecord.id]||0);
        let data = { action:'update_row', id:activeRecord.id, time_spent:totalTime };
        
        // Get current edited fields
        let currentEdited = [];
        try {
            currentEdited = JSON.parse(activeRecord.edited_fields || '[]');
        } catch(e) {
            currentEdited = [];
        }
        
        // Collect all form field values
        $('#formGrid input[data-field]').each(function(){ 
            let f=$(this).data('field'); 
            if(f) { 
                let v=$(this).val().trim(); 
                data[f]=v; 
                // Track if field was changed from original
                if(activeRecord[f] !== v && !currentEdited.includes(f)) {
                    currentEdited.push(f);
                }
                activeRecord[f]=v; 
            } 
        });
        
        $.post('api.php', data, function(res){
            if(res.status==='success') {
                delete rowTimes[activeRecord.id]; sessionStorage.setItem('rowTimes', JSON.stringify(rowTimes));
                
                // Status depends on QC enabled or not
                let newStatus = res.qc_enabled ? 'deo_done' : 'done';
                activeRecord.row_status = newStatus; 
                activeRecord.time_spent = totalTime; 
                activeRecord.edited_fields = JSON.stringify(currentEdited);
                activeRecord.updated_at = new Date().toISOString().split('T')[0];
                
                renderForm(activeRecord);
                
                // Update badge based on QC enabled
                if(res.qc_enabled) {
                    $('#activeStatusBadge').text('1st QC Done').attr('class','badge bg-success ms-2'); 
                    $(`.record-item[data-id="${activeRecord.id}"]`).addClass('deo-done').removeClass('completed done qc-done');
                    $(`.record-item[data-id="${activeRecord.id}"] .record-status-dot`).removeClass('status-pending status-completed status-done status-qc-done').addClass('status-deo-done');
                } else {
                    $('#activeStatusBadge').text('DONE').attr('class','badge bg-success ms-2'); 
                    $(`.record-item[data-id="${activeRecord.id}"]`).addClass('done').removeClass('completed deo-done');
                    $(`.record-item[data-id="${activeRecord.id}"] .record-status-dot`).removeClass('status-pending status-completed status-deo-done').addClass('status-done');
                }
                
                updateCounts(); 
                $('#successMessage').fadeIn().delay(1000).fadeOut();
            } else {
                alert('Error saving: ' + (res.message || 'Unknown error'));
            }
        },'json').fail(function(xhr, status, error){
            console.error('Save failed:', status, error);
            alert('Network error while saving. Please try again.');
        });
    }

    function startTimer(rid) { if(activeRowId===rid) return; if(activeRowId)stopTimer(); activeRowId=rid; rowStart=Date.now(); rowInterval=setInterval(()=>{ let d=Math.floor((Date.now()-rowStart)/1000); $('#formTimer').text(formatTime((parseInt(activeRecord.time_spent)||0)+(rowTimes[rid]||0)+d)); },1000); }
    function stopTimer() { if(!activeRowId) return; let d=Math.floor((Date.now()-rowStart)/1000); rowTimes[activeRowId]=(rowTimes[activeRowId]||0)+d; sessionStorage.setItem('rowTimes',JSON.stringify(rowTimes)); clearInterval(rowInterval); activeRowId=null; }
    function formatTime(s) { return s?Math.floor(s/60)+'m '+(s%60)+'s':"0m 0s"; }
    
    let isPanning=false, startX, startY, scrollL, scrollT;
    $('#viewerBody').on('mousedown', function(e){ if(isMagnifierActive || $(e.target).closest('#imgWrapper').length===0) return; e.preventDefault(); isPanning=true; $('#imgWrapper, .viewer-body').css('cursor','grabbing'); startX=e.pageX-this.offsetLeft; startY=e.pageY-this.offsetTop; scrollL=this.scrollLeft; scrollT=this.scrollTop; });
    $('#viewerBody').on('mouseleave mouseup', function(){ isPanning=false; if(!isMagnifierActive) $('#imgWrapper, .viewer-body').css('cursor','grab'); });
    $('#viewerBody').on('mousemove', function(e){ if(!isMagnifierActive && isPanning){ e.preventDefault(); let x=e.pageX-this.offsetLeft, y=e.pageY-this.offsetTop; this.scrollLeft = scrollL - (x-startX); this.scrollTop = scrollT - (y-startY); }
    if(isMagnifierActive) { let lens=$('#magnifierLens'), img=$('#zoomImage')[0], rect=this.getBoundingClientRect(), mx=e.clientX-rect.left, my=e.clientY-rect.top; lens.css({left:(mx+this.scrollLeft-75)+'px', top:(my+this.scrollTop-75)+'px'}); let iRect=img.getBoundingClientRect(), rx=e.clientX-iRect.left, ry=e.clientY-iRect.top; if(rx<0||ry<0||rx>iRect.width||ry>iRect.height) lens.css('backgroundImage','none'); else lens.css({backgroundImage:`url('${img.src}')`, backgroundSize:`${iRect.width*2}px ${iRect.height*2}px`, backgroundPosition:`-${rx*2-75}px -${ry*2-75}px`, filter:$('#zoomImage').css('filter')}); } });

    function toggleImageLock() {
        isImageLocked = !isImageLocked;
        let btn = $('#btnLock');
        if(isImageLocked) { btn.text('🔒').removeClass('btn-outline-danger').addClass('btn-danger'); } 
        else { btn.text('🔓').removeClass('btn-danger').addClass('btn-outline-danger'); }
    }

    function openImage(rec, retryCount = 0) { 
        if(isImageLocked) return; 
        $('#imageViewerContainer, #divider').show().css('display','flex'); 
        $('#ocrStatus').text('Searching...').css('color','white');
        $.post('api.php',{action:'get_image', record_no:rec}, res=>{ 
            if(res.status==='success'){ 
                $('#imageUploadFallback').hide(); 
                $('#imageFileName').text(res.image + (retryCount>0?` (Linked -${retryCount})`:"")); 
                
                // Handle both local uploads and Project 2 paths
                let imgPath = res.image;
                let src = imgPath.startsWith('/') ? imgPath : 'uploads/' + imgPath;
                src += '?t=' + new Date().getTime();
                
                let img=$('#zoomImage');
                img.off('load').on('load',function(){ 
                    initialW=this.naturalWidth; initialH=this.naturalHeight; 
                    $('#highlightLayer').attr({width:initialW,height:initialH}); 
                    applyTrans(); 
                    
                    // OCR path handling
                    let ocrPath = imgPath.startsWith('/') ? imgPath : 'uploads/' + imgPath;
                    runOCR(ocrPath, rec); 
                }).attr('src',src); 
            } else { 
                if(retryCount < 5) { let allRecs = filteredData.map(r => r.record_no); let idx = allRecs.indexOf(rec); if(idx > 0) { openImage(allRecs[idx-1], retryCount + 1); return; } }
                $('#imageUploadFallback').show(); $('#fallbackRecordNo').text(rec); $('#zoomImage').attr('src',''); $('#ocrStatus').text('No Image').css('color','red');
            } 
        },'json'); 
    }

    async function runOCR(path, recordNo) { 
        $('#ocrStatus').text('Scanning...').css('color','#ffc107');
        try {
            if(!ocrWorker) { ocrWorker=await Tesseract.createWorker(); await ocrWorker.loadLanguage('eng'); await ocrWorker.initialize('eng'); } 
            const {data}=await ocrWorker.recognize(path); ocrData=data; 
            $('#ocrStatus').text('Ready').css('color','#28a745'); 
            // Auto-scroll only for record_no (true = isRecordNo)
            highlightText(recordNo, $('#autoScrollCheck').is(':checked'), true); 
        } catch(e) {
            $('#ocrStatus').text('OCR Error').css('color','red');
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
    function uploadSingleImage() { let fd=new FormData(); fd.append('image',$('#singleImageFile')[0].files[0]); fd.append('record_no',$('#fallbackRecordNo').text()); fd.append('action','upload_single_image_and_map'); $.ajax({url:'api.php',type:'POST',data:fd,contentType:false,processData:false,success:r=>{ showToast(JSON.parse(r).message, 'success'); openImage($('#fallbackRecordNo').text()); }}); }

    let isDragging=false; $('#divider').on('mousedown',()=>isDragging=true); $(document).on('mousemove',e=>{if(!isDragging)return; let h=document.getElementById('mainContainer').getBoundingClientRect().bottom - e.clientY; if(h>100 && h<window.innerHeight-200) $('#imageViewerContainer').css('flexBasis',h+'px'); }).on('mouseup',()=>isDragging=false);

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
        let time = now.toLocaleTimeString('en-IN', {hour: '2-digit', minute: '2-digit'});
        document.getElementById('realTimeClock').innerHTML = '🕐 ' + time;
    }
    setInterval(updateClock, 1000);
    updateClock();
    
    // Keyboard Shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S - Save
        if(e.ctrlKey && e.key === 's') {
            e.preventDefault();
            saveCurrentRecord();
        }
        
        // Ctrl+D - Mark Done
        if(e.ctrlKey && e.key === 'd') {
            e.preventDefault();
            markDone();
        }
        
        // Ctrl+I - Open Image
        if(e.ctrlKey && e.key === 'i') {
            e.preventDefault();
            if(currentRecordNo) openImage(currentRecordNo);
        }
        
        // Ctrl+T - Toggle Theme
        if(e.ctrlKey && e.key === 't') {
            e.preventDefault();
            toggleTheme();
        }
        
        // Ctrl+Arrow - Navigate records
        if(e.ctrlKey && e.key === 'ArrowDown') {
            e.preventDefault();
            navigateRecord(1);
        }
        if(e.ctrlKey && e.key === 'ArrowUp') {
            e.preventDefault();
            navigateRecord(-1);
        }
    });
    
    // Navigate to next/prev record
    function navigateRecord(direction) {
        let items = document.querySelectorAll('.record-item');
        let currentIndex = -1;
        items.forEach((item, i) => {
            if(item.classList.contains('active')) currentIndex = i;
        });
        let newIndex = currentIndex + direction;
        if(newIndex >= 0 && newIndex < items.length) {
            items[newIndex].click();
        }
    }
    
    // Enhanced Alert Replacement
    const originalAlert = window.alert;
    window.alert = function(message) {
        if(message.toLowerCase().includes('success') || message.toLowerCase().includes('saved') || message.toLowerCase().includes('done')) {
            showToast(message, 'success');
        } else if(message.toLowerCase().includes('error') || message.toLowerCase().includes('fail')) {
            showToast(message, 'error');
        } else if(message.toLowerCase().includes('warning')) {
            showToast(message, 'warning');
        } else {
            showToast(message, 'info');
        }
    };
    
    // Session Activity Tracker
    let lastActivity = Date.now();
    document.addEventListener('mousemove', () => lastActivity = Date.now());
    document.addEventListener('keypress', () => lastActivity = Date.now());
    
    setInterval(() => {
        let inactiveTime = (Date.now() - lastActivity) / 1000 / 60;
        if(inactiveTime >= 25 && inactiveTime < 26) {
            showToast('⚠️ Session will expire in 5 minutes due to inactivity', 'warning', 10000);
        }
    }, 60000);
    

</script>
<!-- ========== REPORT TO ADMIN MODAL ========== -->
<div id="reportToAdminModal" class="modal fade" tabindex="-1" style="z-index:99999;">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#fd7e14,#e67e22);color:white;">
        <h5 class="modal-title">⚠️ Report to Admin</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-3 mb-2 align-items-center">
          <div><small class="text-muted">Record No:</small> <strong id="reportRecordNo" class="text-primary"></strong></div>
          <div id="reportImageBadge" style="display:flex;align-items:center;gap:5px;">
            <small class="text-muted">Image:</small>
            <input type="text" id="reportImageNo" 
                   style="background:#0ea5e9;color:white;padding:2px 8px;border-radius:3px;font-size:12px;border:1px solid #0284c7;width:160px;outline:none;" 
                   placeholder="Auto-fetch..." 
                   title="Image name edit kar sakte ho">
            <span id="reportImgEditHint" style="font-size:11px;color:#6b7280;" title="Edit kar sakte ho">✏️</span>
          </div>
          <div id="reportImgLoader" style="display:none;"><small class="text-muted">Fetching image...</small></div>
        </div>
        
        <!-- Existing Reports -->
        <div id="existingReportsDiv" style="display:none;" class="mb-3">
          <div class="alert alert-warning p-2" style="font-size:12px;">
            <strong>⚠️ Existing Open Reports:</strong>
            <div id="existingReportsList"></div>
          </div>
        </div>
        
        <div class="mb-3">
          <label class="form-label fw-bold">Select Header/Field:</label>
          <select id="reportHeaderName" class="form-select form-select-sm">
            <option value="">-- Select Field --</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Issue Details:</label>
          <textarea id="reportIssueDetails" class="form-control" rows="3" placeholder="Issue describe karo..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning btn-sm" onclick="submitReportToAdmin()">
          <i class="fas fa-exclamation-triangle"></i> Submit Report
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ============================================================
// REPORT TO ADMIN - FULL JS (v3.0)
// ============================================================

const REPORT_HEADERS = [
    'KYC Number','Name','Guardian Name','Gender','Marital Status','DOB',
    'Address','Landmark','City','Zip Code','City Of Birth','Nationality',
    'Photo Attachment','Residential Status','Occupation',
    'Officially Valid Documents','Annual Income','Broker Name',
    'Sub Broker Code','Bank Serial No','Second Applicant Name',
    'Amount Received From','Amount','ARN No','Second Address',
    'Occupation/Profession','Remarks','Image Issue','Data Mismatch','Other'
];

// Populate header dropdown
function populateReportHeaders() {
    let sel = $('#reportHeaderName');
    sel.empty().append('<option value="">-- Field Select Karo --</option>');
    REPORT_HEADERS.forEach(h => sel.append(`<option value="${h}">${h}</option>`));
}

function openReportModal(recordNo) {
    if (!recordNo) {
        showToast('⚠️ Pehle koi record select karo', 'warning');
        return;
    }
    $('#reportRecordNo').text(recordNo);
    $('#reportHeaderName').val('');
    $('#reportIssueDetails').val('');
    $('#existingReportsDiv').hide();
    $('#reportImageBadge').show();
    $('#reportImageNo').val('').css({'background':'#e2e8f0','color':'#333'});
    populateReportHeaders();
    
    // Auto-fetch image for this record
    $('#reportImgLoader').show();
    $.post('api.php', {action:'get_image_for_report', record_no: recordNo}, function(res) {
        $('#reportImgLoader').hide();
        if (res.status === 'success' && res.image_no) {
            $('#reportImageNo').val(res.image_no).css({'background':'#0ea5e9','color':'white'});
        } else {
            $('#reportImageNo').val('').attr('placeholder','Manually type karein...').css({'background':'#fff7ed','color':'#92400e'});
        }
    }, 'json');
    
    // Load existing reports (both report_to_admin + critical_errors)
    $.post('api.php', {action:'get_reports_for_record', record_no: recordNo}, function(res) {
        if (res.status === 'success' && res.count > 0) {
            let html = '';
            res.reports.forEach(r => {
                let statusBadge = r.status === 'open'
                    ? '<span class="badge bg-warning text-dark">Open</span>'
                    : '<span class="badge bg-success">Solved</span>';
                let imgInfo   = r.image_no ? ` | 📷 <b>${r.image_no}</b>` : '';
                let reporter  = r.reported_by_name || r.reported_by || '';
                let role      = r.reporter_role ? ` (${r.reporter_role.toUpperCase()})` : '';
                let adminRply = r.admin_remark
                    ? `<div style="background:#fff3cd;padding:3px 6px;border-radius:3px;margin-top:2px;font-size:11px;">💬 Admin: ${r.admin_remark}</div>`
                    : '';
                let field     = r.header_name || r.field_name || '—';
                let details   = r.issue_details || r.details || '';
                html += `<div style="border-left:3px solid #fd7e14;padding:4px 8px;margin:4px 0;background:#fffbf0;border-radius:0 4px 4px 0;">
                    <div><b>Field:</b> ${field}${imgInfo} ${statusBadge}</div>
                    <div style="font-size:12px;color:#555;">${details}</div>
                    <div style="font-size:11px;color:#888;">By: ${reporter}${role} • ${r.created_at || ''}</div>
                    ${adminRply}
                </div>`;
            });
            $('#existingReportsList').html(html);
            $('#existingReportsDiv').show();
        }
    }, 'json');
    
    let modalEl = document.getElementById('reportToAdminModal');
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function submitReportToAdmin() {
    let recordNo = $('#reportRecordNo').text();
    let headerName = $('#reportHeaderName').val();
    let issueDetails = $('#reportIssueDetails').val().trim();
    
    if (!headerName) { showToast('⚠️ Please select a field/header', 'warning'); return; }
    if (!issueDetails) { showToast('⚠️ Please describe the issue', 'warning'); return; }
    
    let imageNoVal = $('#reportImageNo').val().trim() || '';
    $.post('api.php', {
        action: 'submit_report_to_admin',
        record_no: recordNo,
        header_name: headerName,
        issue_details: issueDetails,
        image_no: imageNoVal,
        reported_from: 'first_qc'
    }, function(res) {
        if (res.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('reportToAdminModal')).hide();
            showToast('✅ ' + res.message, 'success');
            
            // Highlight record yellow in sidebar
            $(`.record-item[data-id="${activeRecord.id}"]`).css('border-left','4px solid #fd7e14');
            $(`.record-item[data-id="${activeRecord.id}"]`).addClass('reported-record');
            
            // Update report count
            let curCount = parseInt($('#reportCount').text()) || 0;
            $('#reportCount').text(curCount + 1);
            $('#reportCountBox').show();
            
            // If F2 report was pending, now auto-save
            if (window._pendingF2AfterReport) {
                window._pendingF2AfterReport = false;
                setTimeout(() => saveAndNext(), 300);
            }
        } else {
            showToast('⚠️ ' + res.message, 'warning');
        }
    }, 'json');
}

// ============================================================
// F2 / NEXT AUTO SUBMIT LOGIC (v3.0)
// ============================================================
window._pendingF2AfterReport = false;

function saveAndNext() {
    if (!activeRecord) return;
    
    // Check if report is filled
    let reportFilled = ($('#reportHeaderName').val() && $('#reportIssueDetails').val().trim());
    
    if (reportFilled) {
        // Submit report first, then save
        window._pendingF2AfterReport = true;
        submitReportToAdmin();
        return;
    }
    
    // Save record
    saveActiveForm();
    
    // Move to next record after save
    setTimeout(() => {
        let currentItem = $(`.record-item[data-id="${activeRecord ? activeRecord.id : ''}"]`);
        let nextItem = currentItem.next('.record-item');
        if (nextItem.length) {
            nextItem.click();
            setTimeout(() => $('#formGrid input').first().focus(), 100);
        } else {
            let max = Math.ceil(filteredData.length/itemsPerPage);
            if (currentPage < max) {
                changePage(1);
                setTimeout(() => { $('#sidebarList .record-item').first().click(); setTimeout(()=>$('#formGrid input').first().focus(),100); }, 150);
            } else {
                showToast('✅ End of list reached!', 'info');
            }
        }
    }, 500);
}

// F2 key handler
document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        saveAndNext();
    }
});

// Override Next button if exists
$('#formGrid').on('click', '#btnSaveForm', function(e) {
    // Ctrl+Click = save and next
    if (e.ctrlKey) {
        e.preventDefault();
        saveAndNext();
    }
});

// ============================================================
// REPORTED RECORDS HIGHLIGHT + FILTER UPDATE (v3.0)
// ============================================================

// Add reported class CSS for yellow highlight
$('<style>#sidebarList .record-item.reported-record { border-left: 4px solid #fd7e14 !important; } .record-item.reported-record .record-no::after { content: " ⚠️"; font-size:10px; }</style>').appendTo('head');

// Override filterData to support 'reported' filter
let _origFilterData = filterData;
filterData = function() {
    let txt = $('#searchInput').val().toLowerCase();
    let status = $('#statusFilter').val();
    
    if (status === 'reported') {
        filteredData = allClientData.filter(r => {
            let matchesText = r.record_no.toLowerCase().includes(txt) || (r.name||'').toLowerCase().includes(txt);
            return matchesText && (r.is_reported == 1 || r.report_count > 0);
        });
        currentPage=1; renderSidebar();
        return;
    }
    _origFilterData();
};

// Load report counts on startup
function loadReportCounts() {
    $.post('api.php', {action:'recalculate_counts'}, function(res) {
        if (res.status === 'success' && res.report_count > 0) {
            $('#reportCount').text(res.report_count);
            $('#reportCountBox').show();
        }
    }, 'json');
}

// Load on page ready
$(document).ready(function() {
    setTimeout(loadReportCounts, 2000);
});
</script>
</body>
</html>