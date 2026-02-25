<?php
require_once 'config.php';
// Security Check: Sirf logged-in Admins/Processors/Viewers hi access kar sakte hain
check_login();
// Note: Role check ab niche specific buttons pe hoga, page access allowed hai sabko
// check_role(['admin']); 

$user = get_user_info();
// Default role if not set
if(!isset($user['role'])) $user['role'] = 'viewer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KYC Data Utility - Admin</title>
    
    <!-- Excel Library -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --primary: #4f46e5; --secondary: #64748b; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --light: #f3f4f6; --white: #ffffff; --dark: #1e293b; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background-color: var(--light); color: var(--dark); padding: 2rem; transition: background 0.3s, color 0.3s; }
        
        /* Updated container width to 100% for full screen display */
        .container { max-width: 100%; margin: 0 auto; background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: background 0.3s; }
        
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 1rem; }
        .back-btn { text-decoration: none; color: var(--secondary); font-weight: 500; display: flex; align-items: center; gap: 5px; }
        .back-btn:hover { color: var(--primary); }

        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; color: white; transition: 0.3s; }
        .btn-info { background: #3b82f6; }
        .btn-primary { background: linear-gradient(135deg, #8b5cf6, #d946ef); }
        .btn-success { background: var(--success); }
        .btn-danger { background: var(--danger); }
        .btn-warning { background: var(--warning); color: white; }
        
        .upload-zone { border: 2px dashed #bdc3c7; border-radius: 8px; padding: 40px; text-align: center; background: #fafafa; transition: 0.3s; cursor: pointer; margin-top: 20px; }
        .upload-zone:hover { border-color: var(--primary); background: #f0f8ff; }
        .file-input { display: none; }
        
        /* Queue Styles */
        .queue-item { font-size: 13px; padding: 5px; border-bottom: 1px solid #eee; color: #64748b; }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(5px); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 25px; border: 1px solid #888; width: 90%; max-width: 500px; border-radius: 12px; position: relative; }
        .close-modal { float: right; font-size: 24px; cursor: pointer; color: #aaa; }
        .close-modal:hover { color: #000; }

        /* Dark Mode */
        body.dark { background:#0f172a; color:#e5e7eb; }
        body.dark .container { background:#020617; color:#e5e7eb; }
        body.dark .header-top { border-bottom-color: #334155; }
        body.dark .upload-zone { background: #1e293b; border-color: #475569; }
        body.dark .modal-content { background: #1e293b; border-color: #475569; color: #e5e7eb; }
        body.dark input, body.dark select, body.dark textarea { background: #334155; color: white; border-color: #475569; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="container">
    <div class="header-top">
        <h2>🛠️ KYC Data Corrector Utility</h2>
        <a href="p2_admin_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <p style="color: var(--secondary); margin-bottom: 20px;">
        Upload Excel file to automatically format names, addresses, amounts, and highlight issues in yellow.
        <br><small>Shortcuts: Ctrl+U (Upload)</small>
    </p>
    
    <div style="display:flex; gap:10px; margin-bottom:15px; flex-wrap:wrap;">
        <button class="btn btn-info" onclick="toggleDark()">🌙 Dark Mode</button>
    </div>
    
    <!-- Upload Zone (Role Protected) -->
    <?php if(in_array($user['role'], ['admin', 'processor'])): ?>
        <div class="upload-zone" onclick="document.getElementById('bulkFiles').click()">
            <!-- ID changed to bulkFiles and multiple added -->
            <input type="file" id="bulkFiles" class="file-input" accept=".xlsx" multiple onchange="addToQueue(this.files)">
            <i class="fas fa-file-excel" style="font-size: 3rem; color: #10b981; margin-bottom: 1rem;"></i>
            <h4 style="margin: 0; color: var(--secondary);">Click to Process KYC Excel (Bulk Support)</h4>
            <p id="kycFileName" style="margin: 5px 0 0; color: #888; font-size: 0.8rem;">Supports multiple .xlsx files</p>
        </div>
        
        <!-- Queue Display -->
        <div id="queueList" style="margin-top:10px; font-size:13px; color:#64748b;"></div>
    <?php else: ?>
        <div style="margin-top:20px; padding:20px; background:#fef2f2; border:1px solid #fee2e2; border-radius:8px; text-align:center; color:#ef4444;">
            <i class="fas fa-lock"></i> <b>Viewer Mode:</b> You only have permission to view/preview files. Uploading is disabled.
        </div>
    <?php endif; ?>

    <!-- 1️⃣ Progress Bar -->
    <div id="progressBox" style="display:none; margin-top:15px;">
        <div style="background:#e5e7eb; border-radius:8px; overflow:hidden;">
            <div id="progressBar" style="width:0%; background:#4f46e5; color:white; padding:6px; text-align:center; font-size:12px;">
                0%
            </div>
        </div>
    </div>
    
    <!-- Post-Process Actions (Undo, Filtered Downloads) -->
    <div id="postProcessActions" style="display:none; margin-top:15px; text-align:center;">
        <button class="btn btn-danger" onclick="undoLast()">↩️ Undo Last Process</button>
        <button onclick="downloadFiltered('invalid')" class="btn btn-warning">⬇ Invalid Only</button>
        <button onclick="downloadFiltered('missing')" class="btn btn-warning">⬇ Missing Only</button>
        <button class="btn btn-success" onclick="downloadPDF()">📄 Download PDF Report</button>
    </div>

    <!-- Preview Section -->
    <div id="previewBox" style="display:none; margin-top:30px; background:white; padding:15px; border-radius:8px; border:1px solid #e5e7eb;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;">👀 Preview (Original vs Corrected)</h3>
            <select id="previewFilter" onchange="renderPreview()" style="padding:5px; border-radius:4px; border:1px solid #cbd5e1;">
                <option value="all">All</option>
                <option value="invalid">Only Invalid</option>
                <option value="missing">Only Missing</option>
            </select>
        </div>
        <div id="previewTable" style="max-height:300px; overflow:auto; margin-top:10px; border:1px solid #eee;"></div>
    </div>
    
    <!-- Analytics Dashboard -->
    <div style="margin-top:30px;">
        <h3>📊 Analytics Dashboard</h3>
        <button class="btn btn-info" onclick="showAnalytics()">View Analytics</button>
        <button class="btn btn-danger" onclick="clearAnalytics()">🗑️ Clear Analytics</button>
        <!-- Added Export Button -->
        <button class="btn btn-success" onclick="exportAnalyticsExcel()">📊 Export Analytics to Excel</button>
        <div id="analyticsBox" style="margin-top:15px;"></div>
    </div>

    <!-- 2️⃣ Error Legend -->
    <div style="margin-top:20px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e5e7eb;">
        <h4 style="margin-top:0; color:#334155;">🟨 Error Legend</h4>
        <ul style="margin:0; padding-left:18px; font-size:14px; color:#475569;">
            <li><b>Yellow Cell</b> → Value auto-corrected</li>
            <li><b>Invalid</b> → Data format incorrect</li>
            <li><b>Missing</b> → Data not provided / N.A</li>
        </ul>
    </div>
    
    <div id="kycStatus" style="margin-top: 20px; font-weight: bold; text-align:center;"></div>
</div>

<!-- 3️⃣ Summary Modal -->
<div id="summaryModal" class="modal">
  <div class="modal-content">
    <span class="close-modal" onclick="document.getElementById('summaryModal').style.display='none'">&times;</span>
    <h3 style="margin-top:0;">📊 Processing Summary</h3>
    <div id="summaryContent" style="font-size:14px; line-height:1.6;"></div>
  </div>
</div>

<script>
    // --- KYC CONFIGURATION & LOGIC ---
    
    // Global Variables for Undo/Preview
    let LAST_ORIGINAL_DATA = null;
    let LAST_CORRECTED_DATA = null;
    let LAST_HEADERS = null;
    
    // Bulk Queue Variables
    let FILE_QUEUE = [];
    let IS_PROCESSING = false;

    // Field Correction Counter (Global)
    let FIELD_CORRECTION_COUNT = {};
    // Change Log (Global)
    let FIELD_CHANGE_LOG = {};
    
    // Default Config (API Key removed)
    let config = {
        apiKey: "", 
        zipLen: 6,
        currency: "$",
        dateFormat: "MDY",
        keywords: ["n.a", "na", "n/a", "none", "not available", "not applicable", "nil", "-", "--"],
        nameCasing: "proper",
        addressFixAbbr: true,
        addressRemoveHash: true
    };
    
    // Fetch API Key from PHP Endpoint
    async function loadApiKey(){
        try {
            const res = await fetch("get_api_key.php");
            const data = await res.json();
            if(data.key) {
                config.apiKey = data.key;
            }
        } catch(e) { console.error("Could not load API Key", e); }
    }
    loadApiKey();

    // Load Settings on Start
    document.addEventListener("DOMContentLoaded", () => {
        const saved = localStorage.getItem("kycConfig");
        if(saved) {
            try {
                const savedConfig = JSON.parse(saved);
                // Don't overwrite apiKey from localStorage if it's empty, use secure load
                if(savedConfig.apiKey) config.apiKey = savedConfig.apiKey;
                config = { ...config, ...savedConfig };
            } catch(e) { console.error("Config load error", e); }
        }
    });
</script>

<!-- INCLUDE RULES FILE (Logic Separated) -->
<?php include 'kyc_rules_script.php'; ?>

<script>
    // --- QUEUE LOGIC ---
    function addToQueue(files){
        for(let f of files){
            FILE_QUEUE.push(f);
        }
        renderQueue();
        if(!IS_PROCESSING) processQueue();
    }

    function renderQueue(){
        const list = document.getElementById("queueList");
        if(list) {
            list.innerHTML = FILE_QUEUE.length > 0 ? "<b>Queue:</b><br>" + FILE_QUEUE.map((f,i)=>`<div class="queue-item">📄 ${i+1}. ${f.name}</div>`).join("") : "Queue empty";
        }
    }

    async function processQueue(){
        if(FILE_QUEUE.length===0){
            IS_PROCESSING=false;
            return;
        }
        IS_PROCESSING=true;
        const file = FILE_QUEUE.shift();
        renderQueue();

        // Pass as object to mimic input.files structure
        await processKYCExcel({ files:[file] });
        
        // Loop
        processQueue();
    }

    // --- HELPER FUNCTIONS ---
    function updateProgress(percent) {
        document.getElementById("progressBox").style.display = "block";
        const bar = document.getElementById("progressBar");
        bar.style.width = percent + "%";
        bar.innerText = percent + "%";
    }

    function generateFileName(originalName) {
        const d = new Date();
        const base = originalName ? originalName.replace('.xlsx', '') : 'KYC';
        return `${base}_Corrected_${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}_${String(d.getHours()).padStart(2,"0")}.xlsx`;
    }

    function downloadAuditLog(audit) {
        const csv = [
            "Admin,File,Records,Corrected,Invalid,Missing,Date",
            `${audit.admin},${audit.file},${audit.total},${audit.corrected},${audit.invalid},${audit.missing},${audit.date}`
        ].join("\n");

        const csvBlob = new Blob([csv], {type:"text/csv"});
        const b = document.createElement("a");
        b.href = URL.createObjectURL(csvBlob);
        b.download = "audit_log.csv";
        b.click();
    }
    
    // --- 5️⃣ NEW FEATURES HELPERS ---
    
    function calculateQualityScore(total, invalid, missing){
        let score = 100;
        score -= (invalid * 5);
        score -= (missing * 3);
        if(score < 0) score = 0;

        let stars = "⭐".repeat(Math.ceil(score / 20));
        return { score, stars };
    }

    function saveAnalytics(data){
        try {
            let logs = JSON.parse(localStorage.getItem("kycAnalytics") || "[]");
            logs.push(data);
            
            // LIMIT: Keep only last 20 records to avoid quota errors
            if(logs.length > 20) {
                logs = logs.slice(-20);
            }
            
            localStorage.setItem("kycAnalytics", JSON.stringify(logs));
        } catch (e) {
            console.error("Local Storage Quota Exceeded", e);
            // Fallback: Try saving only the current record, removing older ones
            try {
                // If failed, assume storage is full, reset to just current record
                let minimalLogs = [data];
                localStorage.setItem("kycAnalytics", JSON.stringify(minimalLogs));
            } catch (e2) {
                // If even current record is too big (huge changeLog), save without changeLog
                let summaryData = {...data};
                delete summaryData.changeLog;
                localStorage.setItem("kycAnalytics", JSON.stringify([summaryData]));
                alert("⚠️ Storage Full: Saved analytics summary only (Detailed logs discarded).");
            }
        }
    }

    function showAnalytics(){
        let logs = JSON.parse(localStorage.getItem("kycAnalytics") || "[]");
        if(logs.length === 0){
            document.getElementById("analyticsBox").innerHTML = "No data yet";
            return;
        }

        let html = `<table border="1" cellpadding="6" style="border-collapse:collapse;font-size:13px;width:100%;">
        <tr style="background:#f1f5f9;">
            <th>Date</th><th>Total</th><th>Corrected</th><th>Invalid</th><th>Missing</th>
        </tr>`;

        logs.forEach(l=>{
            html += `<tr>
                <td>${new Date(l.date).toLocaleString()}</td>
                <td>${l.total}</td>
                <td>${l.corrected}</td>
                <td>${l.invalid}</td>
                <td>${l.missing}</td>
            </tr>`;
        });

        html += "</table>";

        // ADDED LOGIC FOR FIELD STATS
        let fieldStats = {};
        logs.forEach(l=>{
            if(l.fieldCorrections){
                for(let f in l.fieldCorrections){
                    fieldStats[f] = (fieldStats[f] || 0) + l.fieldCorrections[f];
                }
            }
        });

        html += `<h4 style="margin-top:20px;">📌 Field-wise Correction Count</h4>`;
        html += `<table border="1" cellpadding="6" style="border-collapse:collapse;font-size:13px;">
        <tr><th>Field Name</th><th>Corrections</th></tr>`;
        for(let f in fieldStats){
            html += `<tr>
                <td>${f}</td>
                <td>${fieldStats[f]}</td>
            </tr>`;
        }
        html += `</table>`;

        document.getElementById("analyticsBox").innerHTML = html;
    }
    
    function clearAnalytics(){
        if(confirm("Are you sure you want to clear all analytics data? This cannot be undone.")){
            localStorage.removeItem("kycAnalytics");
            document.getElementById("analyticsBox").innerHTML = "Data cleared.";
        }
    }

    // --- EXPORT ANALYTICS EXCEL ---
    function exportAnalyticsExcel() {
        let logs = JSON.parse(localStorage.getItem("kycAnalytics") || "[]");

        if (logs.length === 0) {
            alert("No analytics data available");
            return;
        }

        /* ---------------- SHEET 1 : SUMMARY ---------------- */
        let summaryData = [
            ["Date", "Total Records", "Corrected", "Invalid", "Missing"]
        ];

        logs.forEach(l => {
            summaryData.push([
                new Date(l.date).toLocaleString(),
                l.total || 0,
                l.corrected || 0,
                l.invalid || 0,
                l.missing || 0
            ]);
        });

        const summarySheet = XLSX.utils.aoa_to_sheet(summaryData);

        /* ---------------- SHEET 2 : FIELD-WISE ---------------- */
        let fieldStats = {};

        logs.forEach(l => {
            if (l.fieldCorrections) {
                for (let f in l.fieldCorrections) {
                    fieldStats[f] = (fieldStats[f] || 0) + l.fieldCorrections[f];
                }
            }
        });

        let fieldData = [["Field Name", "Total Corrections"]];
        for (let f in fieldStats) {
            fieldData.push([f, fieldStats[f]]);
        }

        const fieldSheet = XLSX.utils.aoa_to_sheet(fieldData);

        /* ---------- SHEET 3: CHANGE DETAILS (WITH RECORD NO) ---------- */
        let changeMap = {};

        logs.forEach(l => {
            if (l.changeLog) {
                for (let k in l.changeLog) {
                    const c = l.changeLog[k];
                    const aggKey = `${c.recordNo}|||${c.field}|||${c.before}|||${c.after}`;

                    if (!changeMap[aggKey]) {
                        changeMap[aggKey] = {
                            recordNo: c.recordNo,
                            field: c.field,
                            before: c.before,
                            after: c.after,
                            count: 0
                        };
                    }
                    changeMap[aggKey].count += c.count;
                }
            }
        });

        let changeData = [
            ["Record No", "Field Name", "Old Value", "New Value", "Total Changes"]
        ];

        Object.values(changeMap).forEach(c => {
            changeData.push([
                c.recordNo,
                c.field,
                c.before,
                c.after,
                c.count
            ]);
        });

        const changeSheet = XLSX.utils.aoa_to_sheet(changeData);

        /* ---------------- WORKBOOK ---------------- */
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, summarySheet, "Summary_Analytics");
        XLSX.utils.book_append_sheet(wb, fieldSheet, "Field_Wise_Corrections");
        XLSX.utils.book_append_sheet(wb, changeSheet, "Change_Details");

        /* ---------------- FILE NAME ---------------- */
        const d = new Date();
        const fileName =
            `KYC_Analytics_${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}.xlsx`;

        XLSX.writeFile(wb, fileName);
    }
    
    function downloadPDF(){
        const win = window.open("", "", "width=800,height=600");
        win.document.write(`
            <h2>KYC Processing Report</h2>
            <p><b>Date:</b> ${new Date().toLocaleString()}</p>
            <p>${document.getElementById("summaryContent").innerHTML}</p>
            <p style="margin-top:30px;font-size:12px;">
                Generated by KYC Utility
            </p>
        `);
        win.print();
    }
    
    function toggleDark(){
        document.body.classList.toggle("dark");
    }

    // --- UNDO / FILTER / PREVIEW FUNCTIONS ---
    function undoLast(){
        if(!LAST_ORIGINAL_DATA){
            alert("Nothing to undo");
            return;
        }
        const ws = XLSX.utils.aoa_to_sheet(LAST_ORIGINAL_DATA);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Original");
        XLSX.writeFile(wb, "KYC_Original_Restored.xlsx");
    }

    function downloadFiltered(type){
        if(!LAST_CORRECTED_DATA) return;

        let filtered = [LAST_CORRECTED_DATA[0]];
        // Find Remark column index
        let headers = LAST_CORRECTED_DATA[0].map(h => String(h).toLowerCase());
        let remIdx = headers.indexOf("remarks");
        
        for(let i=1;i<LAST_CORRECTED_DATA.length;i++){
            let row = LAST_CORRECTED_DATA[i];
            let rowText = row.join(" ");
            let remark = remIdx !== -1 ? String(row[remIdx]) : "";
            
            // Check remarks or the row content
            if(type==="invalid" && (rowText.includes("Invalid") || (remark && remark.includes("Invalid")))) filtered.push(row);
            if(type==="missing" && (rowText.includes("Missing") || (remark && remark.includes("Missing")))) filtered.push(row);
        }

        if(filtered.length <= 1) {
            alert("No " + type + " records found.");
            return;
        }

        const ws = XLSX.utils.aoa_to_sheet(filtered);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Filtered");
        XLSX.writeFile(wb, `KYC_${type}_only.xlsx`);
    }

    function renderPreview() {
        if(!LAST_ORIGINAL_DATA || !LAST_CORRECTED_DATA) return;

        const filter = document.getElementById("previewFilter").value;
        let html = `<table border="1" cellpadding="6" style="border-collapse:collapse;font-size:12px;width:100%;">`;
        html += "<tr style='background:#f1f5f9;'><th>Row</th><th>Original Data</th><th>Corrected Data</th></tr>";

        let count = 0;
        for(let i=1; i<LAST_ORIGINAL_DATA.length; i++){
            const o = LAST_ORIGINAL_DATA[i] ? LAST_ORIGINAL_DATA[i].join(" | ") : "";
            const c = LAST_CORRECTED_DATA[i] ? LAST_CORRECTED_DATA[i].join(" | ") : "";

            if(filter==="invalid" && !c.includes("Invalid")) continue;
            if(filter==="missing" && !c.includes("Missing")) continue;

            if(o !== c){
                html += `<tr>
                    <td>${i}</td>
                    <td style="background:#fee2e2; word-break:break-word;">${o}</td>
                    <td style="background:#dcfce7; word-break:break-word;">${c}</td>
                </tr>`;
                count++;
            }
            if(count > 100) {
                html += "<tr><td colspan='3' style='text-align:center;'>...Showing first 100 changes...</td></tr>";
                break;
            }
        }
        if(count === 0) html += "<tr><td colspan='3' style='text-align:center;'>No changes found matching filter.</td></tr>";
        
        html += "</table>";
        document.getElementById("previewTable").innerHTML = html;
        document.getElementById("previewBox").style.display = "block";
    }

    // Modified to handle Input Element OR Custom File Object from Queue
    async function processKYCExcel(inputOrFiles) {
        let file;
        if(inputOrFiles.files && inputOrFiles.files.length > 0) {
            file = inputOrFiles.files[0];
        } else {
            return;
        }
        
        document.getElementById('kycStatus').innerHTML = '<span style="color:var(--primary);"><i class="fas fa-spinner fa-spin"></i> Processing...</span>';
        document.getElementById('kycFileName').textContent = file.name;

        const reader = new FileReader();
        reader.onload = async function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {type: 'array', cellDates: true});
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                let jsonData = XLSX.utils.sheet_to_json(firstSheet, {header: 1, defval: ""});
                
                if(jsonData.length < 2) throw new Error("Empty file");

                // ====== STEP 0: STANDARDIZE HEADERS (Column A to AB) ======
                const STANDARD_HEADERS = [
                    'Record No.',           // A
                    'KYC Number',           // B
                    'Name',                 // C
                    'Guardian Name',        // D
                    'Gender',               // E
                    'Marital Status',       // F
                    'DOB',                  // G
                    'Address',              // H
                    'Landmark',             // I
                    'City',                 // J
                    'Zip Code',             // K
                    'City of Birth',        // L
                    'Nationality',          // M
                    'Photo Attachment',     // N
                    'Residential Status',   // O
                    'Occupation',           // P
                    'Ovd',                  // Q
                    'Annual Income',        // R
                    'Broker Name',          // S
                    'Sub Broker Code',      // T
                    'Bank Serial No.',      // U
                    '2nd Applicant Name',   // V
                    'Amount Received From', // W
                    'Amount',               // X
                    'ARN No.',              // Y
                    '2nd Address',          // Z
                    'Occupation/Profession',// AA
                    'Remarks'               // AB
                ];
                
                // Replace headers with standard headers (up to column AB = 28 columns)
                for(let i = 0; i < STANDARD_HEADERS.length && i < jsonData[0].length; i++) {
                    jsonData[0][i] = STANDARD_HEADERS[i];
                }
                
                // If file has fewer columns, extend headers
                while(jsonData[0].length < STANDARD_HEADERS.length) {
                    jsonData[0].push(STANDARD_HEADERS[jsonData[0].length]);
                }
                
                // Ensure all data rows have same number of columns
                for(let r = 1; r < jsonData.length; r++) {
                    while(jsonData[r].length < STANDARD_HEADERS.length) {
                        jsonData[r].push("");
                    }
                }
                // ====== END HEADER STANDARDIZATION ======

                // Capture Original Data (AFTER header fix)
                LAST_ORIGINAL_DATA = JSON.parse(JSON.stringify(jsonData));

                let headers = jsonData[0].map(h => h ? String(h).toLowerCase().trim() : "");
                
                // STEP-1: Identify Record No Index
                const recordNoIdx = headers.findIndex(
                    h => h.replace(/\./g,"").toLowerCase() === "record no"
                );

                let remarksColIdx = headers.indexOf("remarks");
                if(remarksColIdx === -1) {
                    headers.push("remarks");
                    jsonData[0].push("Remarks");
                    remarksColIdx = headers.length - 1;
                }
                
                // Capture Headers
                LAST_HEADERS = [...headers];
                
                // Reset Field Counter for new file
                FIELD_CORRECTION_COUNT = {};
                // Reset Change Log for new file
                FIELD_CHANGE_LOG = {};

                const yellowStyle = { fill: { fgColor: { rgb: "FFFF00" } } };

                // --- STATS VARIABLES ---
                let totalRecords = jsonData.length - 1;
                let correctedCount = 0;
                let invalidCount = 0;
                let missingCount = 0;

                for(let r = 1; r < jsonData.length; r++) {
                    // Small delay for UI updates
                    await new Promise(resolve => setTimeout(resolve, 0));
                    updateProgress(Math.floor((r / totalRecords) * 100));

                    let row = jsonData[r];
                    let invalids = new Set();
                    let finalValues = {};
                    while(row.length < headers.length) row.push("");

                    headers.forEach((h, colIdx) => {
                        if(h === "remarks" || h === "") return;
                        let oldVal = row[colIdx];
                        // CALL TO EXTERNAL RULES FILE
                        let newVal = processCell(h, oldVal, invalids);
                        
                        finalValues[h] = newVal;
                        row[colIdx] = newVal;
                        if(String(oldVal) !== String(newVal)) {
                            if(!row._changes) row._changes = {};
                            row._changes[colIdx] = true;
                            
                            // Field Count Logic
                            const fieldName = h;
                            if(!FIELD_CORRECTION_COUNT[fieldName]) {
                                FIELD_CORRECTION_COUNT[fieldName] = 0;
                            }
                            FIELD_CORRECTION_COUNT[fieldName]++;

                            // Detailed Change Log
                            const field = h;
                            const before = String(oldVal).trim() || "(blank)";
                            const after = String(newVal).trim() || "(blank)";
                            
                            // Get Record No
                            const recordNo = recordNoIdx !== -1
                                ? String(row[recordNoIdx]).trim()
                                : "UNKNOWN";

                            const key = `${recordNo}|||${field}|||${before}|||${after}`;

                            if(!FIELD_CHANGE_LOG[key]) {
                                FIELD_CHANGE_LOG[key] = { 
                                    recordNo, 
                                    field, 
                                    before, 
                                    after, 
                                    count: 0 
                                };
                            }
                            FIELD_CHANGE_LOG[key].count++;
                        }
                    });

                    // --- TRACK STATS ---
                    if (invalids.size > 0) invalidCount++;
                    if (Object.values(finalValues).includes(DATA_MISSING)) missingCount++;
                    if (row._changes) correctedCount++;

                    let invalidLabels = HEADER_ORDER.filter(k => invalids.has(k)).map(k => FIELD_LABEL[k]);
                    let missingLabels = HEADER_ORDER.filter(k => finalValues[k] === DATA_MISSING).map(k => FIELD_LABEL[k]);
                    let remark = REMARKS_MISSING;
                    let invStr = "", misStr = "";

                    if(invalidLabels.length > 0) invStr = invalidLabels.join(", ") + " " + (invalidLabels.length > 1 ? "Are" : "Is") + " Invalid";
                    if(missingLabels.length > 0) misStr = missingLabels.join(", ") + " " + (missingLabels.length > 1 ? "Are" : "Is") + " Missing";

                    if(invStr && misStr) remark = invStr + " And " + misStr + ".";
                    else if(invStr) remark = invStr + ".";
                    else if(misStr) remark = misStr + ".";

                    let oldRem = row[remarksColIdx];
                    row[remarksColIdx] = remark;
                    if(String(oldRem) !== String(remark)) {
                        if(!row._changes) row._changes = {};
                        row._changes[remarksColIdx] = true;
                    }
                }

                const newSheet = XLSX.utils.aoa_to_sheet(jsonData);
                const range = XLSX.utils.decode_range(newSheet['!ref']);
                for(let R = range.s.r + 1; R <= range.e.r; ++R) { 
                    let rowData = jsonData[R];
                    if(!rowData) continue;
                    if(rowData._changes) {
                        for(let C in rowData._changes) {
                            let cellRef = XLSX.utils.encode_cell({r: R, c: parseInt(C)});
                            if(!newSheet[cellRef]) newSheet[cellRef] = {t:'s', v: rowData[C]}; 
                            newSheet[cellRef].s = yellowStyle;
                        }
                    }
                    delete jsonData[R]._changes; 
                }

                // Add Footer (As requested)
                newSheet["!footer"] = [
                  "",
                  "Processed by KYC Utility | Admin: <?php echo $user['username'] ?? 'User'; ?>",
                  new Date().toLocaleString()
                ];
                
                // For better compatibility, also appending a row at the very end
                XLSX.utils.sheet_add_aoa(newSheet, [[
                    "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", 
                    "Processed by KYC Utility | User: <?php echo $user['username'] ?? 'User'; ?> | " + new Date().toLocaleString()
                ]], {origin: -1});

                // Capture Corrected Data
                LAST_CORRECTED_DATA = JSON.parse(JSON.stringify(jsonData));

                const newWb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(newWb, newSheet, "KYC_Corrected");
                XLSX.writeFile(newWb, generateFileName(file.name));
                
                document.getElementById('kycStatus').innerHTML = '<span style="color:var(--success);">✅ Downloaded ' + file.name + '!</span>';

                // --- SUMMARY & AUDIT ---
                updateProgress(100);
                
                // 5️⃣ Calculate Quality Score & Update Summary
                const quality = calculateQualityScore(totalRecords, invalidCount, missingCount);

                let summaryHtml = `
                <b>File:</b> ${file.name}<br>
                <b>Total Records:</b> ${totalRecords}<br>
                <b>Corrected Records:</b> ${correctedCount}<br>
                <b>Invalid Records:</b> ${invalidCount}<br>
                <b>Missing Records:</b> ${missingCount}
                <hr>
                <b>Quality Score:</b> ${quality.score}% <br>
                <b>Rating:</b> ${quality.stars}
                `;

                document.getElementById("summaryContent").innerHTML = summaryHtml;
                // Only show modal if processing single file or it's the last file in queue
                if(FILE_QUEUE.length === 0) {
                    document.getElementById("summaryModal").style.display = "block";
                    document.getElementById("postProcessActions").style.display = "block";
                    renderPreview();
                    // AUTO EXPORT ANALYTICS WHEN QUEUE FINISHES
                    exportAnalyticsExcel();
                }
                
                // Save Analytics Data (Updated)
                saveAnalytics({
                    date: new Date().toISOString(),
                    total: totalRecords,
                    invalid: invalidCount,
                    missing: missingCount,
                    corrected: correctedCount,
                    fieldCorrections: FIELD_CORRECTION_COUNT,
                    changeLog: FIELD_CHANGE_LOG
                });

            } catch(err) {
                console.error(err);
                document.getElementById('kycStatus').innerHTML = '<span style="color:var(--danger);">❌ Error: ' + err.message + '</span>';
            }
        };
        reader.readAsArrayBuffer(file);
    }
    
    // Keyboard Shortcuts
    document.addEventListener("keydown", e=>{
        if(e.ctrlKey && e.key==="u"){
            e.preventDefault();
            const btn = document.getElementById("bulkFiles");
            if(btn) btn.click();
        }
        if(e.ctrlKey && e.key==="d"){
            e.preventDefault();
            alert("Download triggered after process");
        }
        if(e.ctrlKey && e.key==="e"){
            e.preventDefault();
            openExplainModal();
        }
    });
</script>

</body>
</html>