<?php
require_once 'config.php';
check_login(); 
check_role(['dqc']); 

$user = get_user_info();
$success = ""; 
$error_msg = ""; 
$searched = null;

// --- 1. NOTIFICATIONS TABLE SETUP ---
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    message TEXT,
    type VARCHAR(50),
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Mark Notification as Read
if (isset($_GET['mark_read']) && isset($_GET['notif_id'])) {
    $notif_id = (int)$_GET['notif_id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notif_id, $user['id']);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit();
}

// Fetch Notifications API
if (isset($_GET['fetch_notifications'])) {
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $notifs = $stmt->get_result();
    $result = [];
    while($n = $notifs->fetch_assoc()) {
        $result[] = $n;
    }
    echo json_encode($result);
    exit();
}

// --- 2. BULK FLAG IMPORT (EXCEL DATA) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_flag_data'])) {
    $raw_data = $_POST['bulk_flag_data'];
    $flags = json_decode($raw_data, true);
    
    if (is_array($flags)) {
        $count = 0;
        $errors = 0;
        
        $stmt_check = $conn->prepare("SELECT assigned_to FROM client_records WHERE record_no = ?");
        $stmt_get_img = $conn->prepare("SELECT image_no FROM record_image_map WHERE record_no = ?");
        $stmt_dup = $conn->prepare("SELECT id FROM dqc_flags WHERE record_no = ? AND flagged_fields = ? AND status = 'flagged'");
        $stmt_insert = $conn->prepare("INSERT INTO dqc_flags (record_no, image_no, dqc_id, flagged_fields, remarks) VALUES (?, ?, ?, ?, ?)");
        $stmt_update_status = $conn->prepare("UPDATE client_records SET row_status = 'flagged' WHERE record_no = ?");
        
        foreach ($flags as $row) {
            $rn = isset($row['Record_No']) ? clean_input($row['Record_No']) : '';
            $fld = isset($row['Error_Field']) ? clean_input($row['Error_Field']) : 'General';
            $rem = isset($row['Remarks']) ? clean_input($row['Remarks']) : 'Bulk Flagged';
            // Accept both Image_Name and Image_No for backward compatibility
            $img = isset($row['Image_Name']) ? clean_input($row['Image_Name']) : (isset($row['Image_No']) ? clean_input($row['Image_No']) : '');

            if (!empty($rn)) {
                // Auto-fetch Image Name if missing
                if (empty($img)) {
                    $stmt_get_img->bind_param("s", $rn);
                    $stmt_get_img->execute();
                    $res_img = $stmt_get_img->get_result();
                    if ($res_img->num_rows > 0) {
                        $img_row = $res_img->fetch_assoc();
                        $img = $img_row['image_no'];
                    } else {
                        $img = "N/A";
                    }
                }

                $stmt_check->bind_param("s", $rn);
                $stmt_check->execute();
                $res_check = $stmt_check->get_result();
                
                if ($res_check->num_rows > 0) {
                    $stmt_dup->bind_param("ss", $rn, $fld);
                    $stmt_dup->execute();
                    if ($stmt_dup->get_result()->num_rows == 0) {
                        $stmt_insert->bind_param("ssiss", $rn, $img, $user['id'], $fld, $rem);
                        if ($stmt_insert->execute()) {
                            $stmt_update_status->bind_param("s", $rn);
                            $stmt_update_status->execute();
                            $count++;
                        }
                    }
                } else {
                    $errors++;
                }
            }
        }
        $success = "✅ Bulk Processed: $count records flagged successfully. ($errors records not found).";
    } else {
        $error_msg = "❌ Invalid data format.";
    }
}

// --- 3. DELETE ACTIONS ---
// Delete Admin Report
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_error'])) {
    $del_id = clean_input($_POST['error_id']);
    if ($conn->query("DELETE FROM critical_errors WHERE id='$del_id' AND deo_id={$user['id']} AND status='pending'")) {
        $success = "🗑️ Admin report deleted.";
    }
}

// Delete Flag
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_flag'])) {
    $fid = clean_input($_POST['flag_id']);
    $rec_row = $conn->query("SELECT record_no FROM dqc_flags WHERE id='$fid'")->fetch_assoc();
    
    if ($conn->query("DELETE FROM dqc_flags WHERE id='$fid' AND dqc_id={$user['id']}")) {
        $success = "🗑️ Flag deleted.";
        if ($rec_row) {
            $rno = $rec_row['record_no'];
            $cnt = $conn->query("SELECT COUNT(*) as c FROM dqc_flags WHERE record_no='$rno' AND status='flagged'")->fetch_assoc()['c'];
            if ($cnt == 0) {
                // If no flags left, revert to completed or verify? Here assuming revert to completed for re-check
                $conn->query("UPDATE records SET status='completed' WHERE record_no='$rno'");
            }
        }
    }
}

// --- 4. FETCH DATA FOR EDITING ---
// Edit Admin Report
$edit_report_data = null;
if (isset($_GET['edit_error'])) {
    $id = clean_input($_GET['edit_error']);
    $res = $conn->query("SELECT * FROM critical_errors WHERE id='$id' AND deo_id={$user['id']} AND status='pending'");
    if ($res->num_rows > 0) {
        $edit_report_data = $res->fetch_assoc();
        $full = $edit_report_data['error_details'];
        if (preg_match('/\[Image No: (.*?)\]/', $full, $m)) {
            $edit_report_data['image_no'] = $m[1]; 
            $edit_report_data['clean_details'] = trim(str_replace($m[0], '', $full));
        } else { 
            $edit_report_data['image_no'] = ''; 
            $edit_report_data['clean_details'] = $full; 
        }
    }
}

// Edit Flag
if (isset($_GET['edit_flag'])) {
    $fid = clean_input($_GET['edit_flag']);
    $f_res = $conn->query("SELECT * FROM dqc_flags WHERE id='$fid' AND dqc_id={$user['id']} AND status='flagged'");
    if ($f_res->num_rows > 0) {
        $flag_data = $f_res->fetch_assoc();
        $_POST['search_input'] = $flag_data['record_no'];
        $_POST['search_btn'] = true;
        $prefill_flag = $flag_data;
    }
}

// --- 5. SEARCH & FLAG LOGIC ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search_btn'])) {
    $inp = clean_input($_POST['search_input']);
    $res = $conn->query("SELECT r.*, u.full_name as deo_name FROM records r JOIN users u ON r.assigned_to = u.id WHERE r.record_no = '$inp' AND r.status IN ('completed', 'corrected', 'flagged')");
    if ($res->num_rows > 0) $searched = $res->fetch_assoc(); 
    else $error_msg = "⚠️ Record #$inp not found or not ready for checking.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['flag_record'])) {
    $rn = clean_input($_POST['record_no']); 
    $img = clean_input($_POST['image_no']); 
    $fld = clean_input($_POST['selected_field']); 
    $rem = clean_input($_POST['remarks']);
    $flag_update_id = isset($_POST['flag_update_id']) ? clean_input($_POST['flag_update_id']) : null;

    if (!$fld) {
        $error_msg = "⚠️ Please select an error field.";
        $searched = $conn->query("SELECT r.*, u.full_name as deo_name FROM records r JOIN users u ON r.assigned_to = u.id WHERE r.record_no = '$rn'")->fetch_assoc(); 
        $_POST['search_input'] = $rn;
    } else {
        if ($flag_update_id) {
            $stmt = $conn->prepare("UPDATE dqc_flags SET flagged_fields=?, remarks=?, image_no=? WHERE id=?");
            $stmt->bind_param("sssi", $fld, $rem, $img, $flag_update_id);
            $stmt->execute();
            $success = "✅ Flag updated.";
            header("Location: dqc_dashboard.php"); 
            exit(); 
        } else {
            $dup_check = $conn->query("SELECT id FROM dqc_flags WHERE record_no='$rn' AND flagged_fields='$fld' AND status='flagged'");
            
            if ($dup_check->num_rows > 0) {
                $error_msg = "⚠️ Duplicate: Field '$fld' already flagged for Record #$rn.";
                $searched = $conn->query("SELECT r.*, u.full_name as deo_name FROM records r JOIN users u ON r.assigned_to = u.id WHERE r.record_no = '$rn'")->fetch_assoc(); 
                $_POST['search_input'] = $rn;
            } else {
                $stmt = $conn->prepare("INSERT INTO dqc_flags (record_no, image_no, dqc_id, flagged_fields, remarks) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssiss", $rn, $img, $user['id'], $fld, $rem);
                $stmt->execute();
                
                $conn->query("UPDATE records SET status = 'flagged' WHERE record_no = '$rn'");
                $success = "❌ Record #$rn Flagged ($fld). You can add another error.";
                
                $searched = $conn->query("SELECT r.*, u.full_name as deo_name FROM records r JOIN users u ON r.assigned_to = u.id WHERE r.record_no = '$rn'")->fetch_assoc(); 
                $_POST['search_input'] = $rn;
                unset($_POST['selected_field']); 
                unset($_POST['remarks']); 
                unset($prefill_flag);
            }
        }
    }
}

// Verify Record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_verified'])) {
    $rn = clean_input($_POST['record_no']); 
    $conn->query("UPDATE records SET status='verified', updated_at=NOW() WHERE record_no='$rn'");
    $success = "✅ Record #$rn Verified & Approved."; 
    $searched = null;
}

// Report to Admin
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['submit_error']) || isset($_POST['update_error_submit']))) {
    $rn = clean_input($_POST['error_record_no']); 
    $img = clean_input($_POST['image_no']); 
    $fld = clean_input($_POST['error_field']); 
    $det = clean_input($_POST['error_details']);
    $final = "[Image No: $img] " . $det;
    
    $conn->query("INSERT INTO records (record_no, assigned_to, status) VALUES ('$rn', {$user['id']}, 'pending') ON DUPLICATE KEY UPDATE record_no=record_no");

    if (isset($_POST['update_error_submit'])) {
        $eid = clean_input($_POST['edit_id']);
        if ($conn->query("UPDATE critical_errors SET record_no='$rn', error_field='$fld', error_details='$final' WHERE id='$eid' AND deo_id={$user['id']}")) {
            $success = "✅ Admin report updated.";
            header("Location: dqc_dashboard.php"); 
            exit();
        }
    } else {
        $check_dup = $conn->query("SELECT id FROM critical_errors WHERE record_no='$rn' AND deo_id={$user['id']} AND error_field='$fld' AND status='pending'");
        if ($check_dup->num_rows > 0) {
             $error_msg = "⚠️ Duplicate: This issue already reported.";
        } else {
            if ($conn->query("INSERT INTO critical_errors (record_no, deo_id, error_field, error_details) VALUES ('$rn', {$user['id']}, '$fld', '$final')")) {
                $success = "✅ Issue reported to Admin for Record #$rn.";
            }
        }
    }
}

// --- 6. GENERAL DATA FETCHING ---
$hist = $conn->query("SELECT f.*, r.name as applicant_name, u.full_name as deo_name FROM dqc_flags f JOIN records r ON f.record_no = r.record_no LEFT JOIN users u ON r.assigned_to = u.id WHERE f.dqc_id = {$user['id']} AND f.status = 'flagged' ORDER BY f.flagged_date DESC LIMIT 10");
$pend_errs = $conn->query("SELECT * FROM critical_errors WHERE deo_id = {$user['id']} AND status = 'pending' ORDER BY created_at DESC");
$tf = $conn->query("SELECT COUNT(*) as count FROM dqc_flags WHERE dqc_id = {$user['id']}")->fetch_assoc()['count'];
$pc = $conn->query("SELECT COUNT(*) as count FROM dqc_flags WHERE dqc_id = {$user['id']} AND status = 'flagged'")->fetch_assoc()['count'];

$pending_checks_res = $conn->query("SELECT r.record_no, u.full_name, r.updated_at FROM records r JOIN users u ON r.assigned_to = u.id WHERE r.status IN ('completed', 'corrected') ORDER BY r.updated_at ASC LIMIT 10");

// --- 7. NEW FEATURE: DATE-WISE DEO FLAG REPORT ---
$filter_date = isset($_GET['filter_date']) ? clean_input($_GET['filter_date']) : date('Y-m-d');

// Query to get stats grouped by DEO for the selected date
$stats_query = "
    SELECT 
        u.full_name as deo_name,
        COUNT(f.id) as total_flags,
        SUM(CASE WHEN f.status = 'corrected' THEN 1 ELSE 0 END) as fixed_count,
        SUM(CASE WHEN f.status = 'flagged' THEN 1 ELSE 0 END) as pending_count
    FROM dqc_flags f
    JOIN records r ON f.record_no = r.record_no
    JOIN users u ON r.assigned_to = u.id
    WHERE f.dqc_id = {$user['id']}
    AND DATE(f.flagged_date) = '$filter_date'
    GROUP BY r.assigned_to
";
$stats_result = $conn->query($stats_query);

$fields = ['KYC Number', 'Name', 'Guardian Name', 'Gender', 'Marital Status', 'DOB', 'Address', 'Landmark', 'City', 'Zip Code', 'City Of Birth', 'Nationality', 'Photo Attachment', 'Residential Status', 'Occupation', 'Officially Valid Documents', 'Annual Income', 'Broker Name', 'Sub Broker Code', 'Bank Serial No', 'Second Applicant Name', 'Amount Receive From', 'Amount', 'ARN No', 'Second Address', 'Occupation/Profession', 'Remarks'];

$unread_notifs = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = {$user['id']} AND is_read = 0")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DQC Dashboard - Enhanced</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #db2777; --light: #fdf2f8; --dark: #1f2937; --white: #fff; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f9fafb; color: var(--dark); padding-bottom: 40px; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
        @keyframes bounce { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .header { background: linear-gradient(135deg, #ec4899, #db2777); color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 50; flex-wrap: wrap; gap: 1rem; }
        .header-left h1 { font-size: 1.25rem; font-weight: 600; margin: 0; }
        .header-left small { opacity: 0.8; font-size: 0.85rem; }
        .header-right { display: flex; align-items: center; gap: 1rem; }
        .notif-bell { position: relative; cursor: pointer; font-size: 1.5rem; padding: 0.5rem; border-radius: 50%; background: rgba(255,255,255,0.2); transition: all 0.3s; }
        .notif-bell:hover { background: rgba(255,255,255,0.3); }
        .notif-badge { position: absolute; top: 0; right: 0; background: var(--danger); color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; font-weight: bold; animation: bounce 2s infinite; }
        .notif-dropdown { position: absolute; top: 70px; right: 20px; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); width: 350px; max-height: 400px; overflow-y: auto; display: none; animation: slideIn 0.3s; z-index: 1000; }
        .notif-dropdown.active { display: block; }
        .notif-header { padding: 1rem; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: var(--primary); }
        .notif-item { padding: 1rem; border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: background 0.3s; }
        .notif-item:hover { background: #f9fafb; }
        .notif-item.unread { background: #eff6ff; }
        .notif-message { font-size: 0.9rem; color: var(--dark); margin-bottom: 0.5rem; }
        .notif-time { font-size: 0.75rem; color: #64748b; }
        .footer-credit { position: fixed; bottom: 10px; right: 15px; font-size: 0.75rem; color: #64748b; background: rgba(255,255,255,0.9); padding: 5px 10px; border-radius: 6px; border: 1px solid #e2e8f0; z-index: 999; }
        .logout-btn { background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 20px; text-decoration: none; color: white; white-space: nowrap; }
        .container { max-width: 1000px; margin: 2rem auto; padding: 0 1rem; animation: fadeIn 0.5s; }
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--white); padding: 1.5rem; border-radius: 12px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-bottom: 4px solid var(--primary); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card h3 { font-size: 2rem; margin-bottom: 0.2rem; }
        .stat-card p { font-size: 0.85rem; color: #64748b; text-transform: uppercase; }
        .card { background: var(--white); padding: 2rem; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 2rem; }
        .card h2 { font-size: 1.25rem; color: #be185d; margin-bottom: 1.5rem; border-bottom: 2px solid #fce7f3; padding-bottom: 0.5rem; }
        .search-box { display: flex; gap: 10px; }
        .search-box input { flex: 1; padding: 1rem; border: 2px solid #e5e7eb; border-radius: 12px; font-size: 0.9rem; }
        .search-box button { padding: 1rem 2rem; background: var(--primary); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; transition: opacity 0.3s; }
        .search-box button:hover { opacity: 0.9; }
        .details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; background: #fdf2f8; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem; }
        select, textarea, input { width: 100%; padding: 0.8rem; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; font-size: 0.9rem; }
        .action-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .btn { padding: 1rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; color: white; }
        .history-item { padding: 1rem; border-bottom: 1px solid #f3f4f6; font-size: 0.9rem; }
        .action-icon { background:none; border:none; cursor:pointer; padding:0; margin:0 5px; font-size:1.1rem; text-decoration:none; }
        .scroll-box { max-height: 400px; overflow-y: auto; padding-right: 5px; }
        .queue-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .queue-table th { text-align: left; color: #64748b; font-size: 0.85rem; padding: 8px; border-bottom: 1px solid #eee; }
        .queue-table td { padding: 8px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        .queue-row:hover { background-color: #fdf2f8; cursor: pointer; }
        .file-upload-box { border: 2px dashed #d1d5db; padding: 20px; text-align: center; border-radius: 8px; background: #f9fafb; transition: 0.3s; cursor: pointer; }
        .file-upload-box:hover { border-color: var(--primary); background: #fdf2f8; }
        
        /* Stats Table Styles */
        .stats-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .stats-table th, .stats-table td { padding: 10px; border-bottom: 1px solid #eee; text-align: center; }
        .stats-table th { background: #fdf2f8; color: #be185d; font-weight: 600; }
        .stats-table td:first-child { text-align: left; font-weight: 500; }
        
        @media (max-width: 768px) { 
            .header { flex-direction: column; text-align: center; gap: 10px; padding: 1rem; } 
            .header-left h1 { font-size: 1.1rem; }
            .notif-dropdown { right: 10px; width: calc(100vw - 20px); }
            .search-box { flex-direction: column; } 
            .search-box button { width: 100%; margin-top: 5px; }
            .action-btns { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: 1fr; }
            .card { padding: 1rem; }
            .details-grid { grid-template-columns: 1fr; }
            .footer-credit { font-size: 0.7rem; padding: 4px 8px; bottom: 5px; right: 5px; }
            .container { grid-template-columns: 1fr; } 
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <h1>Welcome, <?php echo htmlspecialchars($user['full_name']); ?></h1>
            <small>DQC Dashboard</small>
        </div>
        <div class="header-right">
            <div class="notif-bell" onclick="toggleNotifications()">
                🔔
                <?php if($unread_notifs > 0): ?>
                    <span class="notif-badge"><?php echo $unread_notifs; ?></span>
                <?php endif; ?>
            </div>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-header">
            📬 Notifications (<?php echo $unread_notifs; ?> unread)
        </div>
        <div id="notifContainer">
            <div style="padding: 2rem; text-align: center; color: #64748b;">Loading...</div>
        </div>
    </div>
    
    <div class="footer-credit">Website development by - Raja Sah, 7001159731</div>

    <div class="container">
        <?php if ($success) echo "<div style='padding:1rem; background:#dcfce7; border-radius:8px; margin-bottom:1rem; color:#166534;'>$success</div>"; ?>
        <?php if ($error_msg) echo "<div style='padding:1rem; background:#fee2e2; border-radius:8px; margin-bottom:1rem; color:#991b1b;'>$error_msg</div>"; ?>

        <div class="stats-row">
            <div class="stat-card">
                <h3><?php echo $tf; ?></h3>
                <p>Total Flags Raised</p>
            </div>
            <div class="stat-card" style="border-color:#f59e0b;">
                <h3><?php echo $pc; ?></h3>
                <p>Pending Corrections</p>
            </div>
        </div>

        <!-- NEW SECTION: Date-wise DEO Report -->
        <div class="card" style="border-top: 5px solid #8b5cf6;">
            <h2>📊 DEO Flag Report (Date-wise)</h2>
            <form method="GET" style="display:flex; gap:10px; align-items:center; margin-bottom:15px;">
                <label style="margin:0;">Select Date:</label>
                <input type="date" name="filter_date" value="<?php echo $filter_date; ?>" onchange="this.form.submit()" style="width:auto; padding:5px;">
                <button type="submit" style="padding:6px 12px; background:#8b5cf6; border:none; color:white; border-radius:6px; cursor:pointer;">Filter</button>
            </form>

            <div class="table-responsive">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>DEO Name</th>
                            <th>Total Flags</th>
                            <th>Fixed (Corrected)</th>
                            <th>Pending</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($stats_result->num_rows > 0): ?>
                            <?php while ($stat = $stats_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($stat['deo_name']); ?></td>
                                    <td style="font-weight:bold;"><?php echo $stat['total_flags']; ?></td>
                                    <td style="color:#10b981;"><?php echo $stat['fixed_count']; ?></td>
                                    <td style="color:#ef4444; font-weight:bold;"><?php echo $stat['pending_count']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center; color:#999;">No flags found for this date.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="main-layout">
            <!-- Main Work Area (Left) -->
            <div>
                <div class="card">
                    <h2>🔍 Verify Record</h2>
                    <form method="POST">
                        <div class="search-box">
                            <input type="number" name="search_input" id="searchInput" placeholder="Record No (e.g. 1001)" required value="<?php echo isset($_POST['search_input'])?$_POST['search_input']:''; ?>">
                            <button type="submit" name="search_btn">Search</button>
                        </div>
                    </form>
                </div>

                <?php if ($searched): ?>
                <div class="card" style="border-top: 5px solid var(--primary);" id="flag_form">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h2><?php echo isset($prefill_flag) ? "✏️ Edit Flag" : "📋 Check Record"; ?> #<?php echo $searched['record_no']; ?></h2>
                        <span style="background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold;"><?php echo strtoupper($searched['status']); ?></span>
                    </div>
                    
                    <div class="details-grid">
                        <div><small>DEO</small><br><strong><?php echo htmlspecialchars($searched['deo_name']); ?></strong></div>
                        <div><small>Status</small><br><strong style="text-transform:uppercase; color:var(--primary);"><?php echo $searched['status']; ?></strong></div>
                        <div><small>Submitted</small><br><strong><?php echo date('d M, h:i A', strtotime($searched['updated_at'])); ?></strong></div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="record_no" value="<?php echo $searched['record_no']; ?>" id="flag_record_input">
                        <?php if(isset($prefill_flag)): ?><input type="hidden" name="flag_update_id" value="<?php echo $prefill_flag['id']; ?>"><?php endif; ?>
                        
                        <div class="form-group">
                            <label style="color:#be185d;">Image No (Mandatory)</label>
                            <div style="position: relative;">
                                <input type="text" name="image_no" id="flag_image_no" required value="<?php echo isset($prefill_flag) ? htmlspecialchars($prefill_flag['image_no']) : ''; ?>" placeholder="Auto-filled if available">
                                <span id="flagImgLoader" style="display:none; position: absolute; right: 10px; top: 10px; color: #db2777; font-size: 0.8rem;"><i class="fas fa-spinner fa-spin"></i></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Error Field</label>
                            <select name="selected_field">
                                <option value="">-- Select --</option>
                                <?php foreach($fields as $f) { 
                                    $sel=(isset($prefill_flag)&&$prefill_flag['flagged_fields']==$f)?'selected':''; 
                                    echo "<option value='$f' $sel>$f</option>"; 
                                } ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Remarks</label>
                            <textarea name="remarks" rows="3"><?php echo isset($prefill_flag) ? htmlspecialchars($prefill_flag['remarks']) : ''; ?></textarea>
                        </div>
                        <div class="action-btns">
                            <button type="submit" name="flag_record" class="btn" style="background:<?php echo isset($prefill_flag)?'#f59e0b':'#ef4444'; ?>;color:white;"><?php echo isset($prefill_flag)?"Update Flag":"❌ Flag Error"; ?></button>
                            
                            <?php if(isset($prefill_flag)): ?>
                                <a href="dqc_dashboard.php" class="btn" style="background:#6b7280;text-decoration:none;display:flex;align-items:center;justify-content:center;">Cancel</a>
                            <?php else: ?>
                                <button type="submit" name="mark_verified" class="btn" style="background:#10b981;">✅ Verified</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Additional Sections (Below Main Work Area) -->
            <div>
                <!-- 1. Pending Checks Queue -->
                <div class="card">
                    <h2>📥 Pending Checks Queue</h2>
                    <?php if($pending_checks_res->num_rows > 0): ?>
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Rec No</th>
                                    <th>DEO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $pending_checks_res->fetch_assoc()): ?>
                                    <tr class="queue-row" onclick="fillSearch('<?php echo $row['record_no']; ?>')">
                                        <td style="font-weight: 600; color: var(--primary);">#<?php echo $row['record_no']; ?></td>
                                        <td><?php echo explode(' ', $row['full_name'])[0]; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <small style="display: block; margin-top: 10px; text-align: center; color: #aaa;">Click a row to check</small>
                    <?php else: ?>
                        <p style="text-align: center; color: #aaa; padding: 20px;">No records pending verification.</p>
                    <?php endif; ?>
                </div>

                <!-- 2. Report to Admin -->
                <div class="card" id="admin_report_form">
                    <h2>⚠️ Report to Admin</h2>
                    <?php if($edit_report_data): ?>
                        <div style="background:#e0f2fe; padding:0.5rem; margin-bottom:1rem; border-radius:4px; color:#0369a1; display:flex; justify-content:space-between;">
                            <span>Editing Report #<?php echo $edit_report_data['id']; ?></span>
                            <a href="dqc_dashboard.php" style="color:#ef4444; text-decoration:none;">Cancel</a>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <?php if($edit_report_data): ?>
                            <input type="hidden" name="edit_id" value="<?php echo $edit_report_data['id']; ?>">
                            <input type="hidden" name="update_error_submit" value="1">
                        <?php else: ?>
                            <input type="hidden" name="submit_error" value="1">
                        <?php endif; ?>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                            <div>
                                <input type="number" name="error_record_no" id="report_record_no" placeholder="Rec No" required value="<?php echo $edit_report_data ? $edit_report_data['record_no'] : ''; ?>">
                                <span id="reportImgLoader" style="display:none; color: #db2777; font-size: 0.8rem;"><i class="fas fa-spinner fa-spin"></i> Checking...</span>
                            </div>
                            <div style="position:relative;">
                            <input type="text" name="image_no" id="report_image_no" 
                                placeholder="Auto-fetch hoga..." 
                                value="<?php echo $edit_report_data ? htmlspecialchars($edit_report_data['image_no']) : ''; ?>"
                                style="width:100%; padding-right:28px;">
                            <span id="imgManualHint" style="display:none; position:absolute; right:6px; top:50%; transform:translateY(-50%); font-size:0.65rem; color:#db2777; cursor:help;" title="Image auto-fetch nahi hua — manually type karein">✏️</span>
                        </div>
                        </div>
                        <select name="error_field" required style="margin-top:1rem;">
                            <option value="">Select Field</option>
                            <?php foreach($fields as $f) { 
                                $sel=($edit_report_data&&$edit_report_data['error_field']==$f)?'selected':''; 
                                echo "<option value='$f' $sel>$f</option>"; 
                            } ?>
                            <option value="Other" <?php echo ($edit_report_data&&$edit_report_data['error_field']=='Other')?'selected':''; ?>>Other</option>
                        </select>
                        <textarea name="error_details" rows="2" placeholder="Details..." required style="margin-top:1rem;"><?php echo $edit_report_data ? $edit_report_data['clean_details'] : ''; ?></textarea>
                        <button type="submit" class="btn" style="background:#475569; margin-top:1rem;"><?php echo $edit_report_data ? "Update Report" : "Report"; ?></button>
                    </form>
                    <?php if($pend_errs->num_rows>0): ?>
                        <div class="scroll-box" style="margin-top:1rem; max-height:200px;">
                            <?php while($p=$pend_errs->fetch_assoc()): ?>
                                <div style="background:#fffbeb; padding:0.5rem; margin-bottom:0.5rem; border:1px solid #fef3c7; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
                                    <small><strong>#<?php echo $p['record_no']; ?></strong>: <?php echo $p['error_field']; ?></small>
                                    <div>
                                        <a href="?edit_error=<?php echo $p['id']; ?>#admin_report_form" class="action-icon" title="Edit">✏️</a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="error_id" value="<?php echo $p['id']; ?>"><button type="submit" name="delete_error" class="action-icon" style="color:#ef4444;">🗑️</button></form>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Bulk Admin Report Upload Card -->
<div class="card" style="border-top: 3px solid #475569;">
    <h2>📤 Bulk Report to Admin (Excel)</h2>
    <p style="font-size:0.85rem; color:#6b7280; margin-bottom:15px;">
        Upload Excel with headers: <br>
        <code>Record_No</code>, <code>Error_Field</code>, <code>Details</code>, <code>Image_Name</code>(Optional - Auto fetched if empty)
    </p>
    
    <form method="POST" id="bulkAdminReportForm">
        <input type="hidden" name="bulk_admin_report_data" id="bulkAdminReportData">
        <div class="file-upload-box" onclick="document.getElementById('bulkAdminFile').click()" style="border-color: #475569;">
            <i class="fas fa-file-excel" style="font-size: 2rem; color: #475569; margin-bottom: 10px;"></i>
            <p style="margin:0; font-size:0.9rem;">Click to Select Excel File for Admin Report</p>
            <input type="file" id="bulkAdminFile" accept=".xlsx, .xls, .csv" style="display:none" onchange="processBulkAdminFile()">
        </div>
    </form>
    <div id="bulkAdminStatus" style="margin-top:10px; font-size:0.85rem;"></div>
    
    <div style="margin-top:15px; text-align:center;">
        <a href="data:text/csv;charset=utf-8,Record_No,Error_Field,Details,Image_Name%0A1001,Photo Attachment,Photo Missing,%0A1002,Other,Document Not Clear," download="bulk_admin_report_sample.csv" style="color:#475569; font-size:0.85rem; text-decoration:none;">
            <i class="fas fa-download"></i> Download Sample CSV
        </a>
    </div>
</div>


                <!-- 3. Bulk Upload Card -->
                <div class="card">
                    <h2>📊 Bulk Flag Upload (Excel)</h2>
                    <p style="font-size:0.85rem; color:#6b7280; margin-bottom:15px;">
                        Upload Excel with headers: <br>
                        <code>Record_No</code>, <code>Error_Field</code>, <code>Remarks</code>, <code>Image_Name</code>(Optional - Auto fetched if empty)
                    </p>
                    
                    <form method="POST" id="bulkFlagForm">
                        <input type="hidden" name="bulk_flag_data" id="bulkFlagData">
                        <div class="file-upload-box" onclick="document.getElementById('bulkFile').click()">
                            <i class="fas fa-file-excel" style="font-size: 2rem; color: #10b981; margin-bottom: 10px;"></i>
                            <p style="margin:0; font-size:0.9rem;">Click to Select Excel File</p>
                            <input type="file" id="bulkFile" accept=".xlsx, .xls, .csv" style="display:none" onchange="processBulkFile()">
                        </div>
                    </form>
                    <div id="bulkStatus" style="margin-top:10px; font-size:0.85rem;"></div>
                    
                    <!-- Sample Download Link -->
                    <div style="margin-top:15px; text-align:center;">
                        <a href="data:text/csv;charset=utf-8,Record_No,Error_Field,Remarks,Image_Name%0A1001,Name,Spelling Error,%0A1002,DOB,Date Unclear," download="bulk_sample.csv" style="color:var(--primary); font-size:0.85rem; text-decoration:none;">
                            <i class="fas fa-download"></i> Download Sample CSV
                        </a>
                    </div>
                </div>

                <!-- 4. Recent Pending Flags -->
                 <div class="card">
                    <h2>🕒 Recent Pending Flags</h2>
                    <div class="scroll-box">
                        <?php if ($hist->num_rows > 0) while($h=$hist->fetch_assoc()): ?>
                            <div class="history-item">
                                <div>
                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <strong>#<?php echo $h['record_no']; ?></strong>
                                        <?php if($h['image_no']): ?><span style="background:#fff7ed; border:1px solid #fb923c; color:#c2410c; padding:0 5px; border-radius:4px; font-size:0.7rem;">Img: <?php echo htmlspecialchars($h['image_no']); ?></span><?php endif; ?>
                                    </div>
                                    <small>DEO: <?php echo $h['deo_name']; ?></small>
                                    <div style="color:#ef4444; font-size:0.9rem;"><?php echo $h['flagged_fields']; ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <small style="color:#9ca3af;"><?php echo date('d M, h:i A', strtotime($h['flagged_date'])); ?></small><br>
                                    <div style="margin-top:5px;">
                                        <a href="?edit_flag=<?php echo $h['id']; ?>#flag_form" class="action-icon" title="Edit">✏️</a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="flag_id" value="<?php echo $h['id']; ?>"><button type="submit" name="delete_flag" class="action-icon" style="color:#ef4444;">🗑️</button></form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; else echo "<p style='text-align:center; color:#9ca3af;'>No pending flags.</p>"; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Notification System
        let notifOpen = false;

        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            notifOpen = !notifOpen;
            
            if(notifOpen) {
                dropdown.classList.add('active');
                loadNotifications();
            } else {
                dropdown.classList.remove('active');
            }
        }

        function loadNotifications() {
            fetch('?fetch_notifications=1')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('notifContainer');
                    if(data.length === 0) {
                        container.innerHTML = '<div style="padding: 2rem; text-align: center; color: #64748b;">No new notifications 🔭</div>';
                        return;
                    }
                    let html = '';
                    data.forEach(notif => {
                        const icon = notif.type === 'success' ? '✅' : notif.type === 'warning' ? '⚠️' : 'ℹ️';
                        const timeAgo = getTimeAgo(notif.created_at);
                        html += `
                            <div class="notif-item ${notif.is_read == 0 ? 'unread' : ''}" onclick="markAsRead(${notif.id})">
                                <div class="notif-message">${icon} ${notif.message}</div>
                                <div class="notif-time">${timeAgo}</div>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                });
        }

        function markAsRead(notifId) {
            fetch(`?mark_read=1&notif_id=${notifId}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        loadNotifications();
                        location.reload();
                    }
                });
        }

        function getTimeAgo(datetime) {
            const now = new Date();
            const past = new Date(datetime);
            const diffMs = now - past;
            const diffMins = Math.floor(diffMs / 60000);
            if(diffMins < 1) return 'Just now';
            if(diffMins < 60) return `${diffMins} min ago`;
            const diffHours = Math.floor(diffMins / 60);
            if(diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
            const diffDays = Math.floor(diffHours / 24);
            return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notifDropdown');
            const bell = document.querySelector('.notif-bell');
            if(notifOpen && !dropdown.contains(event.target) && !bell.contains(event.target)) {
                dropdown.classList.remove('active');
                notifOpen = false;
            }
        });

        setInterval(() => {
            if(notifOpen) {
                loadNotifications();
            }
        }, 30000);

        // Helper: Fill Search from Queue
        function fillSearch(recNo) {
            document.getElementById('searchInput').value = recNo;
            document.querySelector("button[name='search_btn']").click();
        }

        // --- BULK FLAG JS LOGIC ---
        function processBulkFile() {
            const fileInput = document.getElementById('bulkFile');
            const file = fileInput.files[0];
            const statusDiv = document.getElementById('bulkStatus');

            if(!file) return;

            statusDiv.innerHTML = '<span style="color:var(--primary);"><i class="fas fa-spinner fa-spin"></i> Reading file...</span>';

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, {type: 'array'});
                    const sheetName = workbook.SheetNames[0];
                    const jsonData = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName]);

                    if(jsonData.length > 0) {
                        document.getElementById('bulkFlagData').value = JSON.stringify(jsonData);
                        statusDiv.innerHTML = '<span style="color:var(--primary);"><i class="fas fa-spinner fa-spin"></i> Processing ' + jsonData.length + ' records...</span>';
                        document.getElementById('bulkFlagForm').submit();
                    } else {
                        statusDiv.innerHTML = '<span style="color:var(--danger);">File is empty or invalid format.</span>';
                    }
                } catch(err) {
                    console.error(err);
                    statusDiv.innerHTML = '<span style="color:var(--danger);">Error processing file.</span>';
                }
            };
            reader.readAsArrayBuffer(file);
        }
        
        // --- BULK ADMIN REPORT JS LOGIC ---
function processBulkAdminFile() {
    const fileInput = document.getElementById('bulkAdminFile');
    const file = fileInput.files[0];
    const statusDiv = document.getElementById('bulkAdminStatus');

    if(!file) return;

    statusDiv.innerHTML = '<span style="color:#475569;"><i class="fas fa-spinner fa-spin"></i> Reading file...</span>';

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const sheetName = workbook.SheetNames[0];
            const jsonData = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName]);

            if(jsonData.length > 0) {
                document.getElementById('bulkAdminReportData').value = JSON.stringify(jsonData);
                statusDiv.innerHTML = '<span style="color:#475569;"><i class="fas fa-spinner fa-spin"></i> Processing ' + jsonData.length + ' records...</span>';
                document.getElementById('bulkAdminReportForm').submit();
            } else {
                statusDiv.innerHTML = '<span style="color:var(--danger);">File is empty or invalid format.</span>';
            }
        } catch(err) {
            console.error(err);
            statusDiv.innerHTML = '<span style="color:var(--danger);">Error processing file.</span>';
        }
    };
    reader.readAsArrayBuffer(file);
}


        // --- AUTO FILL LOGIC FOR DQC ---
        
        // 1. For "Report to Admin" Form
        const reportRecInput = document.getElementById('report_record_no');
        const reportImgInput = document.getElementById('report_image_no');
        const reportLoader = document.getElementById('reportImgLoader');

        if(reportRecInput) {
            reportRecInput.addEventListener('blur', function() {
                const val = this.value.trim();
                if(val) {
                    reportLoader.style.display = 'inline-block';
                    fetch(`get_image_details.php?record_no=${encodeURIComponent(val)}`)
                        .then(r => r.json())
                        .then(data => {
                            reportLoader.style.display = 'none';
                            if(data.success && data.found) {
                                reportImgInput.value = data.image_no;
                                reportImgInput.style.backgroundColor = '#e8f8f5';
                            } else {
                                // Auto-fetch nahi hua - manually type kar sakte hain
                                if(reportImgInput.value === '') {
                                    reportImgInput.placeholder = "Manually type karein...";
                                    reportImgInput.style.backgroundColor = '#fff7ed';
                                    reportImgInput.style.borderColor = '#f59e0b';
                                    const hint = document.getElementById('imgManualHint');
                                    if(hint) hint.style.display = 'inline';
                                    reportImgInput.focus();
                                }
                            }
                        })
                        .catch(() => reportLoader.style.display = 'none');
                }
            });
        }

        // 2. For "Flag Record" Form (Triggers immediately if form is loaded)
        const flagRecInput = document.getElementById('flag_record_input');
        const flagImgInput = document.getElementById('flag_image_no');
        const flagLoader = document.getElementById('flagImgLoader');

        if(flagRecInput && flagImgInput && flagImgInput.value === '') {
            const val = flagRecInput.value.trim();
            if(val) {
                flagLoader.style.display = 'inline-block';
                fetch(`get_image_details.php?record_no=${encodeURIComponent(val)}`)
                    .then(r => r.json())
                    .then(data => {
                        flagLoader.style.display = 'none';
                        if(data.success && data.found) {
                            flagImgInput.value = data.image_no;
                            flagImgInput.style.backgroundColor = '#e8f8f5';
                        }
                    })
                    .catch(() => flagLoader.style.display = 'none');
            }
        }
    </script>
</body>
</html>