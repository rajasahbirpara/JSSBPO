<?php
ob_start(); // Start output buffering to prevent whitespace issues
require_once 'config.php';
check_login();
check_role(['admin']);

$user = get_user_info();
$success = "";
$error_msg = "";

$conn->query("
    DELETE FROM admin_logs 
    WHERE created_at < NOW() - INTERVAL 7 DAY
");

// Auto-migrate: report_to_admin table mein admin_remark column ensure karo
$conn->query("ALTER TABLE `report_to_admin` ADD COLUMN IF NOT EXISTS `admin_remark` TEXT DEFAULT NULL");
$conn->query("ALTER TABLE `report_to_admin` ADD COLUMN IF NOT EXISTS `image_no` VARCHAR(100) DEFAULT NULL");
$conn->query("ALTER TABLE `report_to_admin` ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME DEFAULT NULL");
// Ensure p2_deo is in reported_from enum
@$conn->query("ALTER TABLE `report_to_admin` MODIFY COLUMN `reported_from` ENUM('first_qc','second_qc','admin','autotyper','p2_deo') DEFAULT 'first_qc'");


// --- HELPER: ADMIN LOGGING ---
function log_admin_activity($conn, $admin_id, $action, $details) {
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action_type, description) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $admin_id, $action, $details);
    $stmt->execute();
    $stmt->close();
}

// --- CLEAN INPUT HELPER ---
function clean_input_secure($data) {
    global $conn;
    return $conn->real_escape_string(stripslashes(trim($data)));
}

// --- OUTPUT ESCAPING HELPER ---
function safe_output_admin($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// --- AJAX: OCR IMAGE UPLOAD ---
if (isset($_FILES['ocr_image_upload'])) {
    ob_end_clean(); // Clear buffer before JSON
    header('Content-Type: application/json');
    $file = $_FILES['ocr_image_upload'];
    $target_dir = "uploads/mapping_images/";
    
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
    
    $filename = basename($file["name"]);
    $target_file = $target_dir . $filename;
    
    // Allow overwriting for updates
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        echo json_encode(['success' => true, 'path' => $target_file]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
    }
    exit();
}

// --- AJAX: DEEP DATA SEARCH ---
if (isset($_GET['deep_search_rec'])) {
    while (ob_get_level()) ob_end_clean(); // Clear all buffer levels before JSON
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    $rec = clean_input_secure($_GET['deep_search_rec']);
    $response = [];

    // Validate record number is numeric
    if (!preg_match('/^\d+$/', $rec)) {
        echo json_encode(['error' => 'Invalid record number format']);
        exit();
    }

    // 1. Find DEO from client_records (P1 compatible)
    $stmt = $conn->prepare("SELECT u.full_name, u.username, cr.assigned_to
                 FROM `client_records` cr 
                 LEFT JOIN `users` u ON cr.assigned_to = u.username 
                 WHERE cr.record_no = ? 
                 LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $rec);
        $stmt->execute();
        $assign_res = $stmt->get_result();
        $assign_data = ($assign_res && $assign_res->num_rows > 0) ? $assign_res->fetch_assoc() : null;
        $response['assignment'] = $assign_data;
        $stmt->close();
    } else {
        $response['assignment'] = null;
    }

    // 2. Find Current Status from Records Table
    $stmt = $conn->prepare("SELECT * FROM `client_records` WHERE record_no = ?");
    if ($stmt) {
        $stmt->bind_param("s", $rec);
        $stmt->execute();
        $rec_res = $stmt->get_result();
        $response['record_status'] = ($rec_res && $rec_res->num_rows > 0) ? $rec_res->fetch_assoc() : null;
        $stmt->close();
    } else {
        $response['record_status'] = null;
    }

    // 3. Find Image Number
    $stmt = $conn->prepare("SELECT image_no, image_path FROM `record_image_map` WHERE record_no = ?");
    if ($stmt) {
        $stmt->bind_param("s", $rec);
        $stmt->execute();
        $img_res = $stmt->get_result();
        $response['image_map'] = ($img_res && $img_res->num_rows > 0) ? $img_res->fetch_assoc() : null;
        $stmt->close();
    } else {
        $response['image_map'] = null;
    }

    // 4. Find Flags
    $stmt = $conn->prepare("SELECT f.*, u.full_name as dqc_name FROM `dqc_flags` f LEFT JOIN `users` u ON f.dqc_id = u.id WHERE f.record_no = ?");
    $response['flags'] = [];
    if ($stmt) {
        $stmt->bind_param("s", $rec);
        $stmt->execute();
        $flag_res = $stmt->get_result();
        if($flag_res) {
            while($r = $flag_res->fetch_assoc()) $response['flags'][] = $r;
        }
        $stmt->close();
    }

    // 5. Find Reports for record (from report_to_admin)
    $stmt = $conn->prepare("SELECT `id`, `record_no`, `header_name` AS error_field, `issue_details` AS error_details, IFNULL(`admin_remark`,'') AS admin_remark, `status`, `created_at`, IFNULL(`reported_by_name`,'') AS reported_by_name, `role` FROM `report_to_admin` WHERE `record_no` = ? AND `status`='open'");
    $response['errors'] = [];
    if ($stmt) {
        $stmt->bind_param("s", $rec);
        $stmt->execute();
        $err_res = $stmt->get_result();
        if($err_res) {
            while($r = $err_res->fetch_assoc()) $response['errors'][] = $r;
        }
        $stmt->close();
    }
    
    // 6. Also check critical_errors table
    $stmt = $conn->prepare("SELECT `id`, `record_no`, `error_field` AS error_field, `error_details` AS error_details, IFNULL(`admin_remark`,'') AS admin_remark, `status`, `created_at`, IFNULL(`reported_by_name`,'') AS reported_by_name, `reporter_role` AS role FROM `critical_errors` WHERE `record_no` = ? AND `status` IN ('pending','admin_reviewed')");
    if ($stmt) {
        $stmt->bind_param("s", $rec);
        $stmt->execute();
        $ce_res = $stmt->get_result();
        if($ce_res) {
            while($r = $ce_res->fetch_assoc()) $response['errors'][] = $r;
        }
        $stmt->close();
    }

    echo json_encode($response);
    exit();
}

// --- AJAX HANDLER FOR OCR IMPORT (DATA SAVE) ---
if (isset($_POST['ajax_import_mapping'])) {
    ob_end_clean(); // Clear buffer before JSON
    header('Content-Type: application/json');
    
    $raw_data = isset($_POST['mapping_data']) ? $_POST['mapping_data'] : '[]';
    $data = json_decode($raw_data, true);
    
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data format']);
        exit();
    }
    
    $inserted = 0;
    $skipped = 0;
    $updated_p1 = 0;
    
    $check_stmt = $conn->prepare("SELECT id FROM record_image_map WHERE record_no = ?");
    $insert_stmt = $conn->prepare("INSERT INTO record_image_map (image_no, record_no, image_path) VALUES (?, ?, ?)");
    $update_stmt = $conn->prepare("UPDATE record_image_map SET image_no = ?, image_path = ? WHERE record_no = ?");
    
    // P1 Sync: Also update client_records table
    $p1_update_stmt = $conn->prepare("UPDATE client_records SET image_filename = ?, image_path = ? WHERE record_no = ?");
    $img_base_path = defined('IMAGE_BASE_PATH') ? IMAGE_BASE_PATH : '/uploads/mapping_images/';
    
    foreach ($data as $row) {
        $img = clean_input_secure($row['imageName']);
        $rec = clean_input_secure($row['recordNo']);
        $img_path = $img_base_path . $img;  // Use config path
        
        if (!empty($rec) && !empty($img)) {
            $check_stmt->bind_param("s", $rec);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows == 0) {
                $insert_stmt->bind_param("sss", $img, $rec, $img_path);
                if ($insert_stmt->execute()) {
                    $inserted++;
                }
            } else {
                // Update existing record
                $update_stmt->bind_param("sss", $img, $img_path, $rec);
                $update_stmt->execute();
                $skipped++;
            }
            
            // P1 Sync: Update client_records with image path
            $p1_update_stmt->bind_param("sss", $img, $img_path, $rec);
            if ($p1_update_stmt->execute() && $p1_update_stmt->affected_rows > 0) {
                $updated_p1++;
            }
        }
    }
    
    log_admin_activity($conn, $user['id'], 'OCR Import', "Imported $inserted records via OCR. Updated $updated_p1 in P1.");

    echo json_encode([
        'success' => true, 
        'message' => "Successfully imported $inserted records. Updated $skipped existing. Synced $updated_p1 to P1."
    ]);
    exit();
}

// Quick Reply Templates
$quick_replies = [
    "Please check the image quality and re-enter the data.",
    "Data entry error detected. Please correct and resubmit.",
    "Missing information. Please complete all fields.",
    "Duplicate entry found. Please verify the record number.",
    "Format issue. Please follow the standard format."
];

// --- 1. SETUP TABLES ---

$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    message TEXT,
    type VARCHAR(50),
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS record_image_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image_no VARCHAR(100),
    record_no VARCHAR(100) DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (record_no)
)");

$conn->query("CREATE TABLE IF NOT EXISTS admin_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT,
    action_type VARCHAR(50),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
)");

if (!file_exists('uploads/mapping_images')) {
    mkdir('uploads/mapping_images', 0777, true);
}

// --- AJAX: GET BULK REPLY TEMPLATE DATA ---
if (isset($_GET['get_bulk_template'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    $template_errors = $conn->query("
        SELECT rta.id as Error_ID, rta.record_no as Record_No,
               COALESCE(u.full_name, rta.reported_by_name) as DEO_Name,
               rta.header_name as Error_Field,
               rta.issue_details as Error_Details,
               COALESCE(rta.image_no, rim.image_no, cr.image_filename, '') as Image_Name,
               rta.reported_from as Source
        FROM report_to_admin rta
        LEFT JOIN users u ON u.username COLLATE utf8mb4_unicode_ci = rta.reported_by COLLATE utf8mb4_unicode_ci
        LEFT JOIN record_image_map rim ON rim.record_no COLLATE utf8mb4_unicode_ci = rta.record_no COLLATE utf8mb4_unicode_ci
        LEFT JOIN client_records cr ON cr.record_no COLLATE utf8mb4_unicode_ci = rta.record_no COLLATE utf8mb4_unicode_ci
        WHERE rta.`status` = 'open' AND (rta.admin_remark IS NULL OR rta.admin_remark = '')
        ORDER BY rta.created_at DESC
    ");
    
    if (!$template_errors) {
        echo json_encode(['success' => false, 'error' => $conn->error, 'rows' => []]);
        exit();
    }
    
    $template_rows = [];
    while ($row = $template_errors->fetch_assoc()) {
        $error_details = $row['Error_Details'];
        $image_name = $row['Image_Name'] ?: '';
        
        // Fallback: extract from issue_details string
        if (empty($image_name) && preg_match('/\[Image No:\s*([^\]]+)\]/', $error_details, $matches)) {
            $image_name = trim($matches[1]);
        }
        
        // Clean [Image No: XXX] from display text
        $clean_details = preg_replace('/\[Image No:\s*[^\]]+\]\s*/', '', $error_details);
        $clean_details = trim($clean_details);
        
        $template_rows[] = [
            'Error_ID'     => (int)$row['Error_ID'],
            'Record_No'    => $row['Record_No'],
            'Image_Name'   => $image_name,
            'Source'       => strtoupper($row['Source'] ?? ''),
            'Reported_By'  => $row['DEO_Name'],
            'Error_Field'  => $row['Error_Field'],
            'Error_Details'=> $clean_details,
            'Admin_Reply'  => ''
        ];
    }
    
    echo json_encode(['success' => true, 'rows' => $template_rows, 'count' => count($template_rows)]);
    exit();
}

// --- 2. HANDLE ACTIONS ---

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20; 
$offset = ($page - 1) * $limit;

// Handle notification mark as read
if (isset($_GET['mark_read']) && isset($_GET['notif_id'])) {
    ob_end_clean();
    $notif_id = clean_input_secure($_GET['notif_id']);
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = '$notif_id' AND user_id = {$user['id']}");
    echo json_encode(['success' => true]);
    exit();
}

// API for fetching notifications
if (isset($_GET['fetch_notifications'])) {
    ob_end_clean();
    $notifs = $conn->query("SELECT * FROM notifications WHERE user_id = {$user['id']} AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
    $result = [];
    while($n = $notifs->fetch_assoc()) {
        $result[] = $n;
    }
    echo json_encode($result);
    exit();
}


// --- EXPORT DEO PERFORMANCE REPORT TO EXCEL ---
if (isset($_POST['export_deo_performance'])) {
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $filename = "DEO_Performance_Report_" . date('Y-m-d_H-i') . ".csv";
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header row
    fputcsv($output, ['DEO Name', 'Username', 'Allocated Ranges', 'Total Assigned', 'Work Done (Completed)', 'DQC Flags', 'Progress %']);
    
    // Use same query as dashboard for consistency
    $deo_query = "
        SELECT u.id, u.full_name, u.username,
            (SELECT COUNT(*) FROM client_records cr WHERE cr.assigned_to = u.username OR cr.assigned_to_id = u.id) as total_assigned,
            (SELECT COUNT(DISTINCT cr.record_no) FROM client_records cr WHERE (cr.assigned_to = u.username OR cr.assigned_to_id = u.id) AND cr.row_status = 'Completed') as total_completed,
            (SELECT COUNT(f.id) FROM dqc_flags f JOIN client_records cr ON f.record_no COLLATE utf8mb4_unicode_ci = cr.record_no COLLATE utf8mb4_unicode_ci WHERE cr.assigned_to = u.username OR cr.assigned_to_id = u.id) as pending_flags
        FROM users u 
        WHERE u.`role` = 'deo' 
        ORDER BY u.full_name ASC
    ";
    $deo_result = $conn->query($deo_query);
    
    // Get date-wise ranges - same as dashboard
    $datewise_query = "
        SELECT 
            u.username,
            DATE(COALESCE(cr.created_at, cr.updated_at)) as assign_date,
            MIN(CAST(cr.record_no AS UNSIGNED)) as range_from,
            MAX(CAST(cr.record_no AS UNSIGNED)) as range_to,
            COUNT(*) as record_count
        FROM client_records cr
        JOIN users u ON (cr.assigned_to = u.username OR cr.assigned_to_id = u.id)
        WHERE u.`role` = 'deo' 
        AND cr.record_no REGEXP '^[0-9]+$'
        GROUP BY u.username, DATE(COALESCE(cr.created_at, cr.updated_at))
        ORDER BY assign_date DESC
    ";
    $datewise_result = $conn->query($datewise_query);
    
    $ranges_by_user = [];
    if ($datewise_result && $datewise_result->num_rows > 0) {
        while ($r = $datewise_result->fetch_assoc()) {
            if ($r['assign_date']) {
                $ranges_by_user[$r['username']][] = date('d-M', strtotime($r['assign_date'])) . ': ' . $r['range_from'] . '-' . $r['range_to'] . ' (' . $r['record_count'] . ')';
            } else {
                $ranges_by_user[$r['username']][] = $r['range_from'] . '-' . $r['range_to'] . ' (' . $r['record_count'] . ')';
            }
        }
    }
    
    // Fallback for users with no date-wise ranges
    $fb_query = "
        SELECT u.username, MIN(CAST(cr.record_no AS UNSIGNED)) as range_from, MAX(CAST(cr.record_no AS UNSIGNED)) as range_to, COUNT(*) as record_count
        FROM client_records cr JOIN users u ON (cr.assigned_to = u.username OR cr.assigned_to_id = u.id)
        WHERE u.`role` = 'deo' AND cr.record_no REGEXP '^[0-9]+$' GROUP BY u.username
    ";
    $fb_result = $conn->query($fb_query);
    if ($fb_result && $fb_result->num_rows > 0) {
        while ($r = $fb_result->fetch_assoc()) {
            if (!isset($ranges_by_user[$r['username']]) || empty($ranges_by_user[$r['username']])) {
                $ranges_by_user[$r['username']][] = $r['range_from'] . '-' . $r['range_to'] . ' (' . $r['record_count'] . ')';
            }
        }
    }
    
    if ($deo_result && $deo_result->num_rows > 0) {
        while ($row = $deo_result->fetch_assoc()) {
            $username = $row['username'];
            $total_assigned = intval($row['total_assigned'] ?? 0);
            $total_completed = intval($row['total_completed'] ?? 0);
            $pending_flags = intval($row['pending_flags'] ?? 0);
            
            $percent = ($total_assigned > 0) ? round(($total_completed / $total_assigned) * 100, 2) : 0;
            if ($percent > 100) $percent = 100;
            
            // Get ranges for this user
            $ranges_str = isset($ranges_by_user[$username]) ? implode(' | ', $ranges_by_user[$username]) : 'No ranges';
            
            fputcsv($output, [
                $row['full_name'],
                $username,
                $ranges_str,
                $total_assigned,
                $total_completed,
                $pending_flags,
                $percent . '%'
            ]);
        }
    } else {
        fputcsv($output, ['No DEO users found', '', '', '', '', '', '']);
    }
    
    fclose($output);
    exit();
}

// --- EXPORT QC/DEO/AUTOTYPER REPORTS TO EXCEL ---
if (isset($_POST['export_errors'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $filename = "Reports_to_Admin_" . date('Y-m-d_H-i') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header row - Image_Name column add kiya
    fputcsv($output, ['Sr_No', 'Record_No', 'Image_Name', 'Header_Field', 'Issue_Details', 'Reported_By', 'Source', 'Status', 'Created_At']);

    // Filters
    $exp_deo    = isset($_POST['export_deo'])    ? $conn->real_escape_string(trim($_POST['export_deo']))    : '';
    $exp_date   = isset($_POST['export_date'])   ? $conn->real_escape_string(trim($_POST['export_date']))   : '';
    $exp_source = isset($_POST['export_source']) ? $conn->real_escape_string(trim($_POST['export_source'])) : '';

    // Sirf Pending export karo - Replied kabhi nahi
    $exp_where = ["rta.`status` = 'open'", "(rta.`admin_remark` IS NULL OR TRIM(rta.`admin_remark`) = '')"];
    if (!empty($exp_deo)) {
        $exp_where[] = "EXISTS (SELECT 1 FROM `users` _u WHERE _u.`id`='$exp_deo' AND _u.`username` COLLATE utf8mb4_unicode_ci = rta.`reported_by` COLLATE utf8mb4_unicode_ci)";
    }
    if (!empty($exp_date)) $exp_where[] = "DATE(rta.`created_at`) = '$exp_date'";
    if (!empty($exp_source)) $exp_where[] = "rta.`reported_from` = '$exp_source'";

    $exp_clause = implode(' AND ', $exp_where);

    $exp_query = "SELECT rta.`id`, rta.`record_no`,
                    COALESCE(rta.`image_no`, rim.`image_no`, cr.`image_filename`, '') AS image_name,
                    rta.`header_name`, rta.`issue_details`,
                    COALESCE(u.`full_name`, rta.`reported_by_name`, rta.`reported_by`) AS reporter,
                    COALESCE(rta.`reported_from`, 'first_qc') AS source,
                    rta.`created_at`
                  FROM `report_to_admin` rta
                  LEFT JOIN `users` u ON u.`username` COLLATE utf8mb4_unicode_ci = rta.`reported_by` COLLATE utf8mb4_unicode_ci
                  LEFT JOIN `record_image_map` rim ON rim.`record_no` COLLATE utf8mb4_unicode_ci = rta.`record_no` COLLATE utf8mb4_unicode_ci
                  LEFT JOIN `client_records` cr ON cr.`record_no` COLLATE utf8mb4_unicode_ci = rta.`record_no` COLLATE utf8mb4_unicode_ci
                  WHERE $exp_clause
                  ORDER BY rta.`created_at` DESC";

    $exp_result = $conn->query($exp_query);

    if ($exp_result && $exp_result->num_rows > 0) {
        $sr = 1;
        while ($row = $exp_result->fetch_assoc()) {
            // Image name: [Image No: xxx] legacy format se bhi fetch karo
            $img = $row['image_name'];
            if (empty($img)) {
                if (preg_match('/\[Image No:\s*([^\]]+)\]/', $row['issue_details'], $m)) {
                    $img = trim($m[1]);
                }
            }
            $img = str_replace('_enc', '', trim($img));

            // Issue details se [Image No:] tag hata do
            $details = preg_replace('/\[Image No:\s*[^\]]+\]\s*/', '', $row['issue_details']);
            $details = trim($details);

            $src_map = ['first_qc'=>'First QC','second_qc'=>'Second QC','autotyper'=>'AutoTyper','p2_deo'=>'P2 DEO','admin'=>'Admin'];
            $src_label = $src_map[$row['source']] ?? strtoupper($row['source']);

            fputcsv($output, [
                $sr++,
                $row['record_no'],
                $img,
                $row['header_name'],
                $details,
                $row['reporter'],
                $src_label,
                'Pending',
                $row['created_at']
            ]);
        }
    } else {
        fputcsv($output, ['No pending reports found', '', '', '', '', '', '', '', '']);
    }
    
    fclose($output);
    exit();
}

// User Management Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $new_username = clean_input_secure($_POST['new_username']); 
    $new_password = $_POST['new_password']; 
    $new_role = clean_input_secure($_POST['new_role']); 
    $new_fullname = clean_input_secure($_POST['new_fullname']);
    
    $check_sql = "SELECT id FROM users WHERE username = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("s", $new_username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $error_msg = "⚠️ Username '$new_username' pehle se exist karta hai!";
    } else {
        $ins_stmt = $conn->prepare("INSERT INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)");
        $ins_stmt->bind_param("ssss", $new_username, $new_password, $new_role, $new_fullname);
        
        if ($ins_stmt->execute()) {
            $success = "✅ User created successfully!";
            $new_user_id = $conn->insert_id;
            $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ('$new_user_id', 'Welcome! Your account has been created.', 'success')");
            log_admin_activity($conn, $user['id'], 'User Created', "Created user: $new_username ($new_role)");
        } else {
            $error_msg = "Error: " . $conn->error;
        }
        $ins_stmt->close();
    } 
    $stmt->close();
    $active_tab = 'users'; 
}

// Edit User Logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user_submit'])) {
    $edit_id = clean_input_secure($_POST['edit_user_id']);
    $edit_username = clean_input_secure($_POST['edit_username']);
    $edit_fullname = clean_input_secure($_POST['edit_fullname']);
    $edit_role = clean_input_secure($_POST['edit_role']);
    $edit_password = $_POST['edit_password'];

    // Get OLD username before update (to sync client_records)
    $old_user_row = $conn->query("SELECT username FROM users WHERE id = " . (int)$edit_id);
    $old_username = ($old_user_row && $old_user_row->num_rows > 0) ? $old_user_row->fetch_assoc()['username'] : '';

    // Check if username already exists for another user
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check_stmt->bind_param("si", $edit_username, $edit_id);
    $check_stmt->execute();
    $check_stmt->store_result();
    
    if ($check_stmt->num_rows > 0) {
        $error_msg = "⚠️ Username '$edit_username' already exists! Please choose different username.";
        $check_stmt->close();
    } else {
        $check_stmt->close();
        
        $update_sql = "UPDATE users SET username=?, full_name=?, `role`=?";
        $params = [$edit_username, $edit_fullname, $edit_role];
    $types = "sss";

    if(!empty($edit_password)){
        $update_sql .= ", password=?";
        $params[] = $edit_password;
        $types .= "s";
    }
    $update_sql .= " WHERE id=?";
    $params[] = $edit_id;
    $types .= "i";

    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param($types, ...$params);

    if($stmt->execute()){
        $success = "✅ User updated successfully!";
        
        // Sync client_records.assigned_to when username changes
        if (!empty($old_username) && $old_username !== $edit_username) {
            $old_esc = $conn->real_escape_string($old_username);
            $new_esc = $conn->real_escape_string($edit_username);
            $conn->query("UPDATE client_records SET assigned_to = '$new_esc', updated_at = NOW() WHERE assigned_to = '$old_esc'");
            // Also sync assignments table if deo_id matches
            // Also sync report_to_admin reported_by
            $conn->query("UPDATE report_to_admin SET reported_by = '$new_esc' WHERE reported_by = '$old_esc'");
        }
        
        log_admin_activity($conn, $user['id'], 'User Edited', "Updated user ID: $edit_id ($edit_username)");
    } else {
        $error_msg = "❌ Error updating user: " . $conn->error;
    }
    $stmt->close();
    } // End of else block for duplicate check
    $active_tab = 'users';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {
    $del_user_id = clean_input_secure($_POST['delete_user']);
    if ($del_user_id == $user['id']) {
        $error_msg = "⚠️ Aap khud ko delete nahi kar sakte!";
    } else {
        $u_name = $conn->query("SELECT username FROM users WHERE id='$del_user_id'")->fetch_assoc()['username'];
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $del_user_id);
        
        if ($stmt->execute()) {
            $success = "✅ User deleted successfully.";
            log_admin_activity($conn, $user['id'], 'User Deleted', "Deleted user: $u_name (ID: $del_user_id)");
        } else {
            $error_msg = "❌ Failed to delete user.";
        }
        $stmt->close();
    } 
    $active_tab = 'users';
}

// Assignment Logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_records'])) {
    $deo_id = clean_input_secure($_POST['deo_id']); 
    $record_from = clean_input_secure($_POST['record_from']); 
    $record_to = clean_input_secure($_POST['record_to']) ?: $record_from; 
    
    if (!is_numeric($record_from) || !is_numeric($record_to)) {
        $error_msg = "❌ Record numbers numeric hone chahiye.";
    } else {
        $overlap_query = "SELECT a.*, u.full_name FROM assignments a JOIN users u ON a.deo_id = u.id WHERE CAST(record_no_from AS UNSIGNED) <= $record_to AND CAST(record_no_to AS UNSIGNED) >= $record_from";
        $overlap_result = $conn->query($overlap_query);
        
        if ($overlap_result->num_rows > 0) { 
            $existing = $overlap_result->fetch_assoc(); 
            $error_msg = "❌ Overlap! Range overlap ho rahi hai.\nExisting: " . $existing['record_no_from'] . "-" . $existing['record_no_to'] . " (" . $existing['full_name'] . ")"; 
        } else {
            $stmt = $conn->prepare("INSERT INTO assignments (deo_id, record_no_from, record_no_to) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $deo_id, $record_from, $record_to);
            
            if ($stmt->execute()) {
                $conn->query("UPDATE records SET assigned_to = '$deo_id' WHERE CAST(record_no AS UNSIGNED) BETWEEN $record_from AND $record_to");
                
                $count = (int)$record_to - (int)$record_from + 1;
                $success = "✅ Assignment Successful!";
                $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ('$deo_id', 'New work assigned: $count records', 'info')");
                log_admin_activity($conn, $user['id'], 'Work Assigned', "Assigned $record_from-$record_to to User ID $deo_id");
            } else {
                $error_msg = "Error: " . $conn->error;
            }
            $stmt->close();
        }
    } 
    $active_tab = 'dashboard';
}

// Un-assign Logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_assignment'])) {
    $assign_id = clean_input_secure($_POST['assign_id']);
    $assign_info = $conn->query("SELECT * FROM assignments WHERE id='$assign_id'")->fetch_assoc();
    
    if ($conn->query("DELETE FROM assignments WHERE id='$assign_id'")) {
        $success = "✅ Assignment un-assigned (deleted) successfully.";
        log_admin_activity($conn, $user['id'], 'Work Unassigned', "Deleted assignment ID $assign_id (Range: {$assign_info['record_no_from']}-{$assign_info['record_no_to']})");
    } else {
        $error_msg = "❌ Error deleting assignment.";
    }
    $active_tab = 'work_mgmt';
}

// P1 Compatible: Un-assign all work from a user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['unassign_user_work'])) {
    $unassign_username = clean_input_secure($_POST['unassign_username']);
    
    // Count records before unassigning
    $count_result = $conn->query("SELECT COUNT(*) as cnt FROM client_records WHERE assigned_to = '$unassign_username'")->fetch_assoc();
    $record_count = $count_result['cnt'];
    
    // Unassign all records from this user
    if ($conn->query("UPDATE client_records SET assigned_to = NULL, assigned_to_id = NULL WHERE assigned_to = '$unassign_username'")) {
        $success = "✅ Successfully un-assigned $record_count records from user '$unassign_username'.";
        log_admin_activity($conn, $user['id'], 'Work Unassigned', "Un-assigned $record_count records from user $unassign_username");
    } else {
        $error_msg = "❌ Error un-assigning work.";
    }
    $active_tab = 'work_mgmt';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_remark'])) {
    $error_id = (int)$_POST['error_id']; 
    $admin_remark = clean_input_secure($_POST['admin_remark']);
    
    // Get info from report_to_admin
    $err_res = $conn->query("SELECT rta.reported_by, rta.record_no, rta.reported_from FROM report_to_admin rta WHERE rta.id=$error_id");
    $err_info = ($err_res && $err_res->num_rows > 0) ? $err_res->fetch_assoc() : ['reported_by'=>'','record_no'=>'','reported_from'=>''];
    
    // Find reporter user ID
    $reporter_user_id = 0;
    if (!empty($err_info['reported_by'])) {
        $rb_esc = $conn->real_escape_string($err_info['reported_by']);
        $u_row = $conn->query("SELECT id FROM users WHERE username='$rb_esc' LIMIT 1");
        if ($u_row && $u_row->num_rows > 0) $reporter_user_id = (int)$u_row->fetch_assoc()['id'];
    }
    
    // Find DEO user ID (who owns this record)
    $deo_user_id = 0;
    if (!empty($err_info['record_no'])) {
        $rn_esc2 = $conn->real_escape_string($err_info['record_no']);
        $rec_chk = $conn->query("SELECT u.id as user_id FROM client_records cr JOIN users u ON u.username COLLATE utf8mb4_unicode_ci = cr.assigned_to COLLATE utf8mb4_unicode_ci WHERE cr.record_no COLLATE utf8mb4_unicode_ci = '$rn_esc2' LIMIT 1");
        if ($rec_chk && $rec_chk->num_rows > 0) $deo_user_id = (int)$rec_chk->fetch_assoc()['user_id'];
    }
    
    $update_query = "UPDATE `report_to_admin` SET `admin_remark` = ?, `reviewed_at` = NOW() WHERE `id` = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $admin_remark, $error_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $success = "✅ Remark submitted!";
        $rn_notify = $err_info['record_no'] ?? '';
        $remark_notify = $conn->real_escape_string($admin_remark);
        $reported_from = $err_info['reported_from'] ?? 'first_qc';
        
        // Determine source label for notification
        $source_label = '';
        if (in_array($reported_from, ['first_qc','second_qc','autotyper'])) {
            $src_names = ['first_qc'=>'First QC','second_qc'=>'Second QC','autotyper'=>'Autotyper'];
            $source_label = ' (from ' . ($src_names[$reported_from] ?? $reported_from) . ')';
        }
        
        // 1. Notification for reporter (QC/DEO who submitted the report)
        if ($reporter_user_id > 0) {
            $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ('$reporter_user_id', 'Admin replied to your report on Record #{$rn_notify}', 'warning')");
        }
        
        // 2. Notification for DEO who owns the record (if different from reporter)
        if ($deo_user_id > 0 && $deo_user_id != $reporter_user_id) {
            $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ('$deo_user_id', 'Admin replied on Record #{$rn_notify}{$source_label} — check Admin Reply section', 'warning')");
        }
        
        // 3. Broadcast admin_message for realtime show on all dashboards
        $conn->query("CREATE TABLE IF NOT EXISTS admin_messages (id INT AUTO_INCREMENT PRIMARY KEY, from_user_id INT, to_user_id INT DEFAULT 0, message TEXT, priority VARCHAR(20) DEFAULT 'warning', is_read TINYINT DEFAULT 0, read_at DATETIME DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $msg_text = $conn->real_escape_string("🔔 Admin Reply on Record #{$rn_notify}: {$admin_remark}");
        // Send to DEO (primary target for action)
        $target_for_msg = ($deo_user_id > 0) ? $deo_user_id : $reporter_user_id;
        $conn->query("INSERT INTO admin_messages (from_user_id, to_user_id, message, priority) VALUES ({$user['id']}, $target_for_msg, '$msg_text', 'warning')");
        
        log_admin_activity($conn, $user['id'], 'Error Reply', "Replied to error #$error_id on Record #$rn_notify");
    }
    $stmt->close();
    $active_tab = 'dashboard';
}

// --- BULK REPLY VIA EXCEL ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_reply_data'])) {
    $bulk_data = json_decode($_POST['bulk_reply_data'], true);
    $success_count = 0;
    $error_count = 0;
    $errors_list = [];
    
    if (is_array($bulk_data) && count($bulk_data) > 0) {
        $stmt = $conn->prepare("UPDATE `report_to_admin` SET `admin_remark` = ?, `reviewed_at` = NOW() WHERE `id` = ? AND `status` = 'open'");
        
        foreach ($bulk_data as $row) {
            $error_id = isset($row['Error_ID']) ? (int)$row['Error_ID'] : 0;
            $admin_reply = isset($row['Admin_Reply']) ? trim($row['Admin_Reply']) : '';
            
            if ($error_id > 0 && !empty($admin_reply)) {
                $stmt->bind_param("si", $admin_reply, $error_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $success_count++;
                    
                    // Send notification to reporter
                    $rta_info = $conn->query("SELECT reported_by, record_no FROM report_to_admin WHERE id = $error_id")->fetch_assoc();
                    if ($rta_info) {
                        $rb_n = $conn->real_escape_string($rta_info['reported_by']);
                        $u_row = $conn->query("SELECT id FROM users WHERE username='$rb_n' LIMIT 1")->fetch_assoc();
                        if ($u_row) {
                            $uid_n = (int)$u_row['id'];
                            $notif_msg = "Admin replied to your report for Record #{$rta_info['record_no']}";
                            $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'info')");
                            $stmt_notif->bind_param("is", $uid_n, $notif_msg);
                            $stmt_notif->execute();
                        }
                    }
                } else {
                    $error_count++;
                    $errors_list[] = "ID $error_id: Not found or already replied";
                }
            } else {
                $error_count++;
                if ($error_id <= 0) $errors_list[] = "Invalid Error ID";
                if (empty($admin_reply)) $errors_list[] = "ID $error_id: Empty reply";
            }
        }
        $stmt->close();
        
        if ($success_count > 0) {
            $success = "✅ Bulk Reply: $success_count errors replied successfully!";
            log_admin_activity($conn, $user['id'], 'Bulk Reply', "Replied to $success_count errors via Excel.");
        }
        if ($error_count > 0) {
            $error_msg = "⚠️ $error_count errors skipped. " . implode(', ', array_slice($errors_list, 0, 3));
        }
    } else {
        $error_msg = "❌ Invalid Excel data format.";
    }
    $active_tab = 'dashboard';
}


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_approve'])) {
    $error_ids = isset($_POST['error_ids']) ? $_POST['error_ids'] : [];
    $bulk_remark = clean_input_secure($_POST['bulk_remark']);
    
    if(count($error_ids) > 0) {
        $ids_str = implode(',', array_map('intval', $error_ids));
        $conn->query("UPDATE `report_to_admin` SET `status` = 'solved', `admin_remark` = '$bulk_remark', `solved_at` = NOW(), `solved_by` = '{$user['username']}' WHERE `id` IN ($ids_str)");
        
        // Also resolve linked critical_errors
        $ce_ids_result = $conn->query("SELECT ce_id, record_no FROM report_to_admin WHERE id IN ($ids_str) AND ce_id IS NOT NULL AND ce_id > 0");
        $resolved_records = [];
        if ($ce_ids_result) {
            while ($ce_row = $ce_ids_result->fetch_assoc()) {
                if ((int)$ce_row['ce_id'] > 0) {
                    $conn->query("UPDATE critical_errors SET status='resolved', resolved_at=NOW() WHERE id=" . (int)$ce_row['ce_id']);
                }
                $resolved_records[] = $conn->real_escape_string($ce_row['record_no']);
            }
        }
        
        // Update is_reported for all affected records
        $resolved_records = array_unique($resolved_records);
        foreach ($resolved_records as $rn) {
            $open_cnt = (int)$conn->query("SELECT COUNT(*) as c FROM report_to_admin WHERE record_no='$rn' AND status='open'")->fetch_assoc()['c'];
            $open_ce = (int)$conn->query("SELECT COUNT(*) as c FROM critical_errors WHERE record_no='$rn' AND status IN ('pending','admin_reviewed')")->fetch_assoc()['c'];
            $total_open = max($open_cnt, $open_ce);
            if ($total_open == 0) {
                $conn->query("UPDATE client_records SET is_reported=0, report_count=0, updated_at=NOW() WHERE record_no='$rn'");
            } else {
                $conn->query("UPDATE client_records SET report_count=$total_open, updated_at=NOW() WHERE record_no='$rn'");
            }
        }
        
        $success = "✅ " . count($error_ids) . " reports marked as solved!";
        log_admin_activity($conn, $user['id'], 'Bulk Resolve', "Resolved " . count($error_ids) . " errors.");
    }
    $active_tab = 'dashboard';
}

function get_total_mapped_records() {
    global $conn;
    $res = $conn->query("SELECT COUNT(*) as count FROM record_image_map");
    return $res->fetch_assoc()['count'];
}

// DATA FETCHING
$all_users_result = $conn->query("SELECT * FROM users ORDER BY role ASC, username ASC");
$deo_result = $conn->query("SELECT * FROM users WHERE `role` = 'deo'");

// Total Allocated - Count from client_records where assigned_to is set (P1 compatibility)
$total_allocated = $conn->query("SELECT COUNT(*) as count FROM client_records WHERE assigned_to IS NOT NULL AND assigned_to != ''")->fetch_assoc()['count'];

// Completed Records - Count from client_records (only Completed, not done)
$completed_records = $conn->query("
    SELECT COUNT(DISTINCT record_no) as count 
    FROM client_records 
    WHERE row_status = 'Completed'
")->fetch_assoc()['count'];

$flagged_records = $conn->query("SELECT COUNT(*) as count FROM dqc_flags")->fetch_assoc()['count'];
$mapped_count = get_total_mapped_records();

// Today's Stats
$today_completed = $conn->query("SELECT COUNT(*) as count FROM client_records WHERE row_status = 'Completed' AND DATE(updated_at) = CURDATE()")->fetch_assoc()['count'];
$today_errors = $conn->query("SELECT COUNT(*) as count FROM report_to_admin WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];
$today_submissions = $conn->query("SELECT COUNT(*) as count FROM work_logs WHERE DATE(log_time) = CURDATE()")->fetch_assoc()['count'];

$unread_notifs = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = {$user['id']} AND is_read = 0")->fetch_assoc()['count'];

// --- DEO PERFORMANCE STATS (P1 compatibility) ---
$deo_stats_query = "
    SELECT u.id, u.full_name, u.username,
        (SELECT COUNT(*) FROM client_records cr WHERE cr.assigned_to = u.username OR cr.assigned_to_id = u.id) as total_assigned,
        (
            SELECT COUNT(DISTINCT cr.record_no) 
            FROM client_records cr 
            WHERE (cr.assigned_to = u.username OR cr.assigned_to_id = u.id)
            AND cr.row_status = 'Completed'
        ) as total_completed,
        (SELECT COUNT(f.id) FROM dqc_flags f 
         JOIN client_records cr ON f.record_no COLLATE utf8mb4_unicode_ci = cr.record_no COLLATE utf8mb4_unicode_ci 
         WHERE cr.assigned_to = u.username OR cr.assigned_to_id = u.id) as pending_flags
    FROM users u 
    WHERE u.`role` = 'deo' 
    ORDER BY u.full_name ASC
";
$deo_stats_result = $conn->query($deo_stats_query);

// Get date-wise ranges for each DEO - Simple MIN-MAX approach
// Filter only pure numeric record_no (exclude alphanumeric like 5S64102, 586O3JJ)
$deo_datewise_ranges = [];

// Source 1: client_records (P1 style - record assigned via assigned_to) — numeric records with valid date
$datewise_query = "
    SELECT 
        u.username,
        DATE(COALESCE(cr.created_at, cr.updated_at)) as assign_date,
        MIN(CAST(cr.record_no AS UNSIGNED)) as range_from,
        MAX(CAST(cr.record_no AS UNSIGNED)) as range_to,
        COUNT(*) as record_count
    FROM client_records cr
    JOIN users u ON (cr.assigned_to = u.username OR cr.assigned_to_id = u.id)
    WHERE u.`role` = 'deo' 
    AND cr.record_no REGEXP '^[0-9]+$'
    AND (cr.created_at IS NOT NULL OR cr.updated_at IS NOT NULL)
    GROUP BY u.username, DATE(COALESCE(cr.created_at, cr.updated_at))
    ORDER BY assign_date DESC
";
$datewise_result = $conn->query($datewise_query);
if ($datewise_result && $datewise_result->num_rows > 0) {
    while ($row = $datewise_result->fetch_assoc()) {
        $deo_datewise_ranges[$row['username']][] = [
            'date' => $row['assign_date'],
            'range' => $row['range_from'] . '-' . $row['range_to'],
            'count' => $row['record_count']
        ];
    }
}

// Source 1b: client_records — numeric records with NULL dates (show overall range)
$null_date_query = "
    SELECT 
        u.username,
        MIN(CAST(cr.record_no AS UNSIGNED)) as range_from,
        MAX(CAST(cr.record_no AS UNSIGNED)) as range_to,
        COUNT(*) as record_count
    FROM client_records cr
    JOIN users u ON (cr.assigned_to = u.username OR cr.assigned_to_id = u.id)
    WHERE u.`role` = 'deo' 
    AND cr.record_no REGEXP '^[0-9]+$'
    AND cr.created_at IS NULL AND cr.updated_at IS NULL
    GROUP BY u.username
";
$null_date_result = $conn->query($null_date_query);
if ($null_date_result && $null_date_result->num_rows > 0) {
    while ($row = $null_date_result->fetch_assoc()) {
        $deo_datewise_ranges[$row['username']][] = [
            'date' => date('Y-m-d'),
            'range' => $row['range_from'] . '-' . $row['range_to'],
            'count' => $row['record_count']
        ];
    }
}

// Source 1c: client_records — non-numeric records (show as count only)
$nonnumeric_query = "
    SELECT 
        u.username,
        DATE(COALESCE(cr.created_at, cr.updated_at, NOW())) as assign_date,
        COUNT(*) as record_count
    FROM client_records cr
    JOIN users u ON (cr.assigned_to = u.username OR cr.assigned_to_id = u.id)
    WHERE u.`role` = 'deo' 
    AND cr.record_no NOT REGEXP '^[0-9]+$'
    GROUP BY u.username, DATE(COALESCE(cr.created_at, cr.updated_at, NOW()))
    ORDER BY assign_date DESC
";
$nonnumeric_result = $conn->query($nonnumeric_query);
if ($nonnumeric_result && $nonnumeric_result->num_rows > 0) {
    while ($row = $nonnumeric_result->fetch_assoc()) {
        $deo_datewise_ranges[$row['username']][] = [
            'date' => $row['assign_date'],
            'range' => 'Mixed/Alpha (' . $row['record_count'] . ')',
            'count' => $row['record_count']
        ];
    }
}

// Source 2: Fallback — any DEO with records assigned but no ranges yet
$fallback_query = "
    SELECT 
        u.username,
        MIN(CAST(cr.record_no AS UNSIGNED)) as range_from,
        MAX(CAST(cr.record_no AS UNSIGNED)) as range_to,
        COUNT(*) as record_count
    FROM client_records cr
    JOIN users u ON (cr.assigned_to = u.username OR cr.assigned_to_id = u.id)
    WHERE u.`role` = 'deo'
    AND cr.record_no REGEXP '^[0-9]+$'
    GROUP BY u.username
";
$fallback_result = $conn->query($fallback_query);
if ($fallback_result && $fallback_result->num_rows > 0) {
    while ($row = $fallback_result->fetch_assoc()) {
        // Only add if this user has NO ranges yet
        if (!isset($deo_datewise_ranges[$row['username']]) || empty($deo_datewise_ranges[$row['username']])) {
            $deo_datewise_ranges[$row['username']][] = [
                'date'  => date('Y-m-d'),
                'range' => $row['range_from'] . '-' . $row['range_to'],
                'count' => $row['record_count']
            ];
        }
    }
}

// Source 3: assignments table (P2 style - range-based assignments)
$assign_range_query = "
    SELECT u.username, a.record_no_from, a.record_no_to,
           (CAST(a.record_no_to AS UNSIGNED) - CAST(a.record_no_from AS UNSIGNED) + 1) as record_count,
           DATE(a.created_at) as assign_date
    FROM assignments a
    JOIN users u ON a.deo_id = u.id
    WHERE u.`role` = 'deo'
    ORDER BY a.created_at DESC
";
$assign_range_result = $conn->query($assign_range_query);
if ($assign_range_result && $assign_range_result->num_rows > 0) {
    while ($row = $assign_range_result->fetch_assoc()) {
        $uname = $row['username'];
        $range_str = $row['record_no_from'] . '-' . $row['record_no_to'];
        // Only add if this range not already covered
        $already = false;
        if (isset($deo_datewise_ranges[$uname])) {
            foreach ($deo_datewise_ranges[$uname] as $existing) {
                if ($existing['range'] === $range_str) { $already = true; break; }
            }
        }
        if (!$already) {
            $deo_datewise_ranges[$uname][] = [
                'date'  => $row['assign_date'] ?? date('Y-m-d'),
                'range' => $range_str,
                'count' => $row['record_count']
            ];
        }
    }
}

// --- NEW TAB: QUALITY REPORT DATA FETCHING ---
$quality_filter_date = isset($_GET['quality_date']) ? clean_input_secure($_GET['quality_date']) : '';
$quality_filter_deo = isset($_GET['quality_deo']) ? clean_input_secure($_GET['quality_deo']) : ''; 

$quality_where = "";
if (!empty($quality_filter_date)) {
    $quality_where .= " AND DATE(f.flagged_date) = '$quality_filter_date'";
}
if (!empty($quality_filter_deo)) { 
    $quality_where .= " AND u.id = '$quality_filter_deo'";
}

// 1. Overall Summary
$flag_stats_query = "
    SELECT 
        u.full_name,
        COUNT(f.id) as total_flags,
        SUM(CASE WHEN f.status = 'flagged' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN f.status = 'corrected' THEN 1 ELSE 0 END) as fixed
    FROM dqc_flags f
    JOIN client_records r ON f.record_no = r.record_no
    JOIN users u ON r.assigned_to = u.username
    WHERE 1=1 $quality_where
    GROUP BY u.id
    ORDER BY total_flags DESC
";
$flag_stats_result = $conn->query($flag_stats_query);
$overall_data = [];
$grand_total_flags = 0;
$grand_total_pending = 0;
$grand_total_fixed = 0;

if ($flag_stats_result->num_rows > 0) {
    while($row = $flag_stats_result->fetch_assoc()) {
        $overall_data[] = $row;
        $grand_total_flags += $row['total_flags'];
        $grand_total_pending += $row['pending'];
        $grand_total_fixed += $row['fixed'];
    }
}

// 2. Global Field Breakdown
$global_field_query = "
    SELECT f.flagged_fields, COUNT(*) as count
    FROM dqc_flags f
    JOIN client_records r ON f.record_no = r.record_no
    JOIN users u ON r.assigned_to = u.username
    WHERE 1=1 $quality_where
    GROUP BY f.flagged_fields
    ORDER BY count DESC
";
$global_field_result = $conn->query($global_field_query);
$global_field_data = [];
$total_field_errors = 0;
while($row = $global_field_result->fetch_assoc()){
    $global_field_data[] = $row;
    $total_field_errors += $row['count'];
}

// 3. Detailed Flag Breakdown per DEO
$flag_breakdown_query = "
    SELECT u.full_name, f.flagged_fields, COUNT(*) as count
    FROM dqc_flags f
    JOIN client_records r ON f.record_no = r.record_no
    JOIN users u ON r.assigned_to = u.username
    WHERE 1=1 $quality_where
    GROUP BY u.id, f.flagged_fields
    ORDER BY u.full_name ASC, count DESC
";
$flag_breakdown_result = $conn->query($flag_breakdown_query);
$breakdown_data = [];
if ($flag_breakdown_result->num_rows > 0) {
    while($row = $flag_breakdown_result->fetch_assoc()){
        $breakdown_data[$row['full_name']][] = $row;
    }
}

// 4. Record Wise Flag Counts
$flag_record_query = "
    SELECT 
        f.record_no,
        u.full_name as deo_name,
        COUNT(f.id) as total_flags,
        SUM(CASE WHEN f.status = 'flagged' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN f.status = 'corrected' THEN 1 ELSE 0 END) as fixed
    FROM dqc_flags f
    JOIN client_records r ON f.record_no = r.record_no
    JOIN users u ON r.assigned_to = u.username
    WHERE 1=1 $quality_where
    GROUP BY f.record_no
    ORDER BY total_flags DESC
";
$flag_record_result = $conn->query($flag_record_query);
$record_data = [];
$rec_total_flags = 0;
$rec_total_pending = 0;
$rec_total_fixed = 0;
$rec_total_records = 0; 

while($row = $flag_record_result->fetch_assoc()) {
    $record_data[] = $row;
    $rec_total_flags    += (int)$row['total_flags'];
    $rec_total_pending += (int)$row['pending'];
    $rec_total_fixed    += (int)$row['fixed'];
    $rec_total_records++; 
}

// --- Error Review Filters ---
$filter_error_deo_id = isset($_GET['filter_error_deo_id']) ? clean_input_secure($_GET['filter_error_deo_id']) : '';
$filter_error_date = isset($_GET['filter_error_date']) ? clean_input_secure($_GET['filter_error_date']) : '';
$filter_status = isset($_GET['filter_status']) ? clean_input_secure($_GET['filter_status']) : '';
$filter_source = isset($_GET['filter_source']) ? clean_input_secure($_GET['filter_source']) : '';

// Get list of all reporters (not just DEOs) for filter dropdown
$reporter_list_result = $conn->query("
    SELECT DISTINCT u.id, u.full_name, u.username, u.role
    FROM report_to_admin rta
    JOIN users u ON u.username = rta.reported_by
    WHERE rta.status = 'open'
    ORDER BY u.full_name ASC
");

// ── All reports from report_to_admin ──
$rta_filter_where = ["rta.`status` = 'open'"];
if (!empty($filter_error_deo_id)) {
    $fid_esc = $conn->real_escape_string($filter_error_deo_id);
    // Get username for this user ID
    $filter_user_row = $conn->query("SELECT username FROM users WHERE id='$fid_esc' LIMIT 1");
    if ($filter_user_row && $filter_user_row->num_rows > 0) {
        $filter_username = $conn->real_escape_string($filter_user_row->fetch_assoc()['username']);
        $rta_filter_where[] = "rta.`reported_by` = '$filter_username'";
    }
}
if (!empty($filter_error_date)) $rta_filter_where[] = "DATE(rta.`created_at`) = '$filter_error_date'";
if (!empty($filter_status)) {
    if ($filter_status == 'pending') $rta_filter_where[] = "(rta.`admin_remark` IS NULL OR rta.`admin_remark` = '')";
    elseif ($filter_status == 'replied') $rta_filter_where[] = "rta.`admin_remark` IS NOT NULL AND rta.`admin_remark` != ''";
}
if (!empty($filter_source)) {
    $fs_esc = $conn->real_escape_string($filter_source);
    $rta_filter_where[] = "rta.`reported_from` = '$fs_esc'";
}
$rta_filter_clause = implode(' AND ', $rta_filter_where);

$errors_query = "SELECT
    rta.`id`,
    rta.`record_no`,
    rta.`header_name`                       AS error_field,
    rta.`issue_details`                     AS error_details,
    IFNULL(rta.`admin_remark`,'')           AS admin_remark,
    rta.`created_at`,
    IF(rta.`admin_remark` IS NOT NULL AND rta.`admin_remark` != '', 'admin_reviewed', 'pending') AS report_status,
    IFNULL(rta.`reported_by_name`,'')       AS reported_by_name,
    IFNULL(rta.`reported_by_name`,'')       AS reporter_name,
    rta.`role`                              AS reporter_role,
    IFNULL(rta.`image_no`,'')              AS auto_image_no,
    IFNULL(rta.`reported_from`,'first_qc') AS reported_from,
    NULL                                    AS reviewed_at
    FROM `report_to_admin` rta
    WHERE {$rta_filter_clause}
    ORDER BY rta.`created_at` DESC";
$errors_result = $conn->query($errors_query);
$errors_query_error = $conn->error; // capture immediately

// Active count = open reports (no admin reply yet)
// Active/Replied counts — same filters as display
$count_where_arr = ["`report_to_admin`.`status` = 'open'"];
if (!empty($filter_error_deo_id)) {
    $fid_e = $conn->real_escape_string($filter_error_deo_id);
    $fu_row = $conn->query("SELECT username FROM users WHERE id='$fid_e' LIMIT 1");
    if ($fu_row && $fu_row->num_rows > 0) {
        $fu_name = $conn->real_escape_string($fu_row->fetch_assoc()['username']);
        $count_where_arr[] = "`report_to_admin`.`reported_by` = '$fu_name'";
    }
}
if (!empty($filter_error_date)) {
    $fdate_e = $conn->real_escape_string($filter_error_date);
    $count_where_arr[] = "DATE(`report_to_admin`.`created_at`) = '$fdate_e'";
}
if (!empty($filter_source)) {
    $fsrc_e = $conn->real_escape_string($filter_source);
    $count_where_arr[] = "`report_to_admin`.`reported_from` = '$fsrc_e'";
}
$count_base_clause = implode(' AND ', $count_where_arr);
$r_active = $conn->query("SELECT COUNT(*) as c FROM `report_to_admin` WHERE $count_base_clause AND (`admin_remark` IS NULL OR `admin_remark`='')");
$active_count  = ($r_active  ? (int)$r_active->fetch_assoc()['c']  : 0);
$r_replied = $conn->query("SELECT COUNT(*) as c FROM `report_to_admin` WHERE $count_base_clause AND `admin_remark` IS NOT NULL AND `admin_remark`!=''");
$replied_count = ($r_replied ? (int)$r_replied->fetch_assoc()['c'] : 0);

// Summary by user
$error_summary_result = $conn->query("
    SELECT COALESCE(u.full_name, rta.reported_by_name, rta.reported_by) as full_name,
           SUM(CASE WHEN rta.admin_remark IS NULL OR rta.admin_remark='' THEN 1 ELSE 0 END) as pending_count,
           SUM(CASE WHEN rta.admin_remark IS NOT NULL AND rta.admin_remark!='' THEN 1 ELSE 0 END) as replied_count
    FROM report_to_admin rta
    LEFT JOIN users u ON u.username = rta.reported_by
    WHERE rta.`status`='open'
    GROUP BY rta.reported_by
");

// --- Submissions Log Logic ---
// MOVED UP: Ensure logic executes for this tab
// --- MISSING IMAGES LOGIC ---
if ($active_tab == 'missing_images') {
    // Ye query assignments range ke saare records ko check karegi jinaka entry map table me nahi hai
    $missing_query = "
        SELECT r.record_no, u.full_name as deo_name
        FROM client_records r
        LEFT JOIN record_image_map rim ON r.record_no = rim.record_no
        LEFT JOIN users u ON r.assigned_to = u.username
        WHERE rim.record_no IS NULL AND r.assigned_to IS NOT NULL
        ORDER BY CAST(r.record_no AS UNSIGNED) ASC
    ";
    $missing_result = $conn->query($missing_query);
    
    // DEO wise missing image count
    $deo_missing_query = "
        SELECT u.full_name as deo_name, u.username, COUNT(r.record_no) as missing_count
        FROM client_records r
        LEFT JOIN record_image_map rim ON r.record_no = rim.record_no
        LEFT JOIN users u ON r.assigned_to = u.username
        WHERE rim.record_no IS NULL AND r.assigned_to IS NOT NULL
        GROUP BY r.assigned_to
        ORDER BY missing_count DESC
    ";
    $deo_missing_result = $conn->query($deo_missing_query);
    
    // Total missing count
    $total_missing = $missing_result ? $missing_result->num_rows : 0;
}
if ($active_tab == 'submissions') {
    if (!isset($_GET['filter_date']) || empty($_GET['filter_date'])) {
        $filter_date = date('Y-m-d');
    } else {
        $filter_date = clean_input_secure($_GET['filter_date']);
    }
    $filter_deo_id_sub = isset($_GET['filter_deo_id_sub']) ? clean_input_secure($_GET['filter_deo_id_sub']) : '';
    $filter_conditions = ["1=1"];
    if (!empty($filter_date)) $filter_conditions[] = "DATE(wl.log_time) = '{$filter_date}'";
    if (!empty($filter_deo_id_sub)) $filter_conditions[] = "wl.deo_id = '{$filter_deo_id_sub}'";
    $where_clause = implode(' AND ', $filter_conditions);

    $all_work_logs_result = $conn->query("SELECT wl.*, u.full_name as deo_name FROM work_logs wl JOIN users u ON wl.deo_id = u.id WHERE $where_clause ORDER BY u.full_name ASC, wl.log_time DESC");
    
    $grouped_submissions = [];
    if ($all_work_logs_result && $all_work_logs_result->num_rows > 0) {
        while ($log = $all_work_logs_result->fetch_assoc()) {
            $grouped_submissions[$log['deo_name']][] = $log;
        }
    }
}


// --- Work Management Logic ---
if ($active_tab == 'work_mgmt') {
    // Get filter date from URL
    $work_filter_date = isset($_GET['work_date']) ? clean_input_secure($_GET['work_date']) : '';
    
    // P1 Compatible: Get assignments from client_records grouped by user and date
    $date_condition = "";
    if (!empty($work_filter_date)) {
        $date_condition = "AND DATE(cr.created_at) = '$work_filter_date'";
    }
    
    // Simple MIN-MAX query for Work Management
    // Filter only pure numeric record_no
    $assignments_query = "
        SELECT 
            u.id as deo_id,
            u.full_name,
            u.username,
            MIN(CAST(cr.record_no AS UNSIGNED)) as record_no_from,
            MAX(CAST(cr.record_no AS UNSIGNED)) as record_no_to,
            COUNT(*) as total_qty,
            SUM(CASE WHEN cr.row_status = 'Completed' THEN 1 ELSE 0 END) as completed_qty,
            DATE(cr.created_at) as assigned_date
        FROM client_records cr
        JOIN users u ON cr.assigned_to = u.username
        WHERE cr.assigned_to IS NOT NULL AND cr.assigned_to != ''
        AND cr.record_no REGEXP '^[0-9]+$'
        $date_condition
        GROUP BY u.id, u.full_name, u.username, DATE(cr.created_at)
        ORDER BY DATE(cr.created_at) DESC, u.full_name ASC
    ";
    $assignments_result = $conn->query($assignments_query);
    
    $grouped_assignments = [];
    if ($assignments_result && $assignments_result->num_rows > 0) {
        while ($assign = $assignments_result->fetch_assoc()) {
            $date_key = date('d M Y', strtotime($assign['assigned_date']));
            $grouped_assignments[$date_key][$assign['full_name']][] = $assign;
        }
    }
    
    // Get available dates for filter dropdown
    $available_dates = $conn->query("SELECT DISTINCT DATE(created_at) as assign_date FROM client_records WHERE assigned_to IS NOT NULL ORDER BY assign_date DESC LIMIT 30");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Complete</title>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/5.0.4/tesseract.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4f46e5; --secondary: #64748b; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --light: #f3f4f6; --white: #ffffff; --dark: #1e293b; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background-color: var(--light); color: var(--dark); line-height: 1.6; padding-bottom: 40px; transition: background-color 0.3s, color 0.3s; }
        body.dark-mode { --light: #1a1a2e; --white: #16213e; --dark: #eee; background-color: #0f0f1e; color: #eee; }
        body.dark-mode .header { background: linear-gradient(135deg, #1a1a2e, #16213e); }
        body.dark-mode .stat-card, body.dark-mode .section, body.dark-mode .notif-dropdown, body.dark-mode .modal-content { background: #16213e; color: #eee; }
        body.dark-mode input, body.dark-mode select { background: #1a1a2e; color: #eee; border-color: #4a4a6a; }
        body.dark-mode th { background: #1a1a2e; color: #aaa; }
        body.dark-mode .upload-zone, body.dark-mode .file-drop-zone { background: #1a1a2e; border-color: #4a4a6a; }
        body.dark-mode .error-item-pending { background: #2a2a1e; }
        body.dark-mode .error-item-reviewed { background: #1a2a1e; }
        .dark-mode-toggle { background: rgba(255,255,255,0.2); border: none; color: white; padding: 0.5rem; border-radius: 50%; cursor: pointer; font-size: 1.2rem; transition: all 0.3s; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; }
        .dark-mode-toggle:hover { background: rgba(255,255,255,0.3); transform: rotate(180deg); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .header { background: linear-gradient(135deg, var(--primary), #4338ca); color: var(--white); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); position: sticky; top: 0; z-index: 100; flex-wrap: wrap; gap: 1rem; }
        .header-left h1 { font-size: 1.25rem; font-weight: 600; margin: 0; }
        .header-left small { opacity: 0.8; font-size: 0.85rem; }
        .header-right { display: flex; align-items: center; gap: 1rem; }
        .notif-bell { position: relative; cursor: pointer; font-size: 1.5rem; padding: 0.5rem; border-radius: 50%; background: rgba(255,255,255,0.2); transition: all 0.3s; }
        .notif-bell:hover { background: rgba(255,255,255,0.3); }
        .notif-badge { position: absolute; top: 0; right: 0; background: var(--danger); color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .notif-dropdown { position: absolute; top: 70px; right: 20px; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); width: 350px; max-height: 400px; overflow-y: auto; display: none; z-index: 1000; }
        .notif-dropdown.active { display: block; }
        .notif-header { padding: 1rem; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: var(--primary); }
        .notif-item { padding: 1rem; border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: background 0.3s; }
        .notif-item:hover { background: #f9fafb; }
        .notif-item.unread { background: #eff6ff; }
        .notif-message { font-size: 0.9rem; color: var(--dark); margin-bottom: 0.5rem; }
        .notif-time { font-size: 0.75rem; color: var(--secondary); }
        .logout-btn { background: rgba(255,255,255,0.2); padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.9rem; transition: all 0.3s; backdrop-filter: blur(5px); color: white; white-space: nowrap; }
        .logout-btn:hover { background: rgba(255,255,255,0.3); }
        .footer-credit { position: fixed; bottom: 10px; right: 15px; font-size: 0.75rem; color: #64748b; background: rgba(255, 255, 255, 0.9); padding: 5px 10px; border-radius: 6px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; z-index: 999; pointer-events: none; }
        
        .container { width: 100%; margin: 2rem auto; padding: 0 2rem; animation: fadeIn 0.5s ease-out; box-sizing: border-box; }
        .nav-tabs { display: flex; gap: 0.5rem; margin-bottom: 2rem; border-bottom: 2px solid #e5e7eb; overflow-x: auto; padding-bottom: 1px; }
        .nav-link { padding: 0.75rem 1rem; text-decoration: none; color: var(--secondary); font-weight: 500; border-bottom: 3px solid transparent; white-space: nowrap; font-size: 0.9rem; }
        .nav-link.active { color: var(--primary); border-bottom-color: var(--primary); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--white); padding: 1.5rem; border-radius: 12px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-top: 4px solid var(--primary); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card h3 { font-size: 2rem; margin-bottom: 0.5rem; }
        .section { background: var(--white); padding: 2rem; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); margin-bottom: 2rem; }
        .section h2 { font-size: 1.25rem; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; gap: 10px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        input, select { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; font-size: 0.9rem; }
        button { background: var(--primary); color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: opacity 0.3s; }
        button:hover { opacity: 0.9; }
        .btn-danger { background: var(--danger); padding: 0.4rem 0.8rem; font-size: 0.85rem; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        th { background: #f8fafc; font-size: 0.85rem; text-transform: uppercase; color: var(--secondary); }
        .badge { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .bg-green { background: #dcfce7; color: #166534; } 
        .bg-red { background: #fee2e2; color: #991b1b; }
        .progress-container { background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden; margin-top: 8px; }
        .progress-bar { height: 100%; background: var(--primary); border-radius: 4px; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .error-item-pending { background: #fffbeb; border-left: 4px solid var(--warning); }
        .error-item-reviewed { background: #d1fae5; border-left: 4px solid var(--success); }
        .toast { position: fixed; top: 100px; right: 20px; background: white; padding: 1rem 1.5rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: none; z-index: 9999; animation: slideInRight 0.3s ease-out; min-width: 250px; }
        .toast.show { display: block; }
        .toast.success { border-left: 4px solid var(--success); }
        .toast.error { border-left: 4px solid var(--danger); }
        .toast.info { border-left: 4px solid var(--primary); }
        @keyframes slideInRight { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .quick-reply-dropdown { position: absolute; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; z-index: 100; display: none; min-width: 300px; }
        .quick-reply-dropdown.active { display: block; }
        .quick-reply-item { padding: 0.75rem 1rem; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 0.9rem; transition: background 0.2s; }
        .quick-reply-item:hover { background: #f9fafb; }
        .quick-reply-item:last-child { border-bottom: none; }
        .search-box { position: relative; margin-bottom: 1.5rem; }
        .search-input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem; transition: border-color 0.3s; }
        .search-input:focus { outline: none; border-color: var(--primary); }
        .search-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--secondary); font-size: 1.1rem; }
        .custom-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary); }
        .bulk-actions-bar { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: var(--primary); color: white; padding: 1rem 2rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); display: none; align-items: center; gap: 1rem; z-index: 999; animation: slideUp 0.3s; }
        .bulk-actions-bar.show { display: flex; }
        @keyframes slideUp { from { transform: translate(-50%, 100px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
        .bulk-btn { background: white; color: var(--primary); padding: 0.5rem 1rem; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .bulk-btn:hover { transform: scale(1.05); }
        .today-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; padding: 1.5rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; color: white; }
        .today-stat-item { text-align: center; }
        .today-stat-item h4 { font-size: 2rem; margin-bottom: 0.25rem; font-weight: 700; }
        .today-stat-item p { font-size: 0.85rem; opacity: 0.9; }
        
        .upload-zone { border: 2px dashed #bdc3c7; border-radius: 8px; padding: 40px; text-align: center; background: #fafafa; transition: 0.3s; cursor: pointer; }
        .upload-zone:hover { border-color: var(--primary); background: #f0f8ff; }
        .file-input { display: none; }
        
        .scroll-box { max-height: 600px; overflow-y: auto; padding-right: 5px; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(5px); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 90%; max-width: 600px; border-radius: 12px; position: relative; animation: slideDown 0.3s; }
        .modal-close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .modal-close:hover { color: black; }
        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; color: var(--primary); border-radius: 4px; }
        .pagination a.active { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination a:hover:not(.active) { background: #f3f4f6; }

        /* OCR Specific */
        .file-drop-zone { border: 2px dashed #bdc3c7; border-radius: 8px; padding: 40px; text-align: center; cursor: pointer; background: #fafafa; }
        .file-drop-zone:hover { border-color: var(--primary); background: #eef2ff; }
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-warning { background: var(--warning); color: black; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-info { background: #3b82f6; color: white; }
        .btn-success { background: var(--success); color: white; }
        .duplicate { background-color: #fffbeb !important; }

        /* --- UPDATED LOG STYLES --- */
        #log {
            height: 300px; /* Fixed height for scroll */
            overflow-y: auto; /* Enable vertical scroll */
            background: #1e293b; /* Terminal dark background */
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #334155;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .log-entry {
            display: flex;
            align-items: flex-start;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            padding-bottom: 2px;
            animation: fadeIn 0.2s ease-in;
        }

        .log-time {
            color: #64748b;
            margin-right: 10px;
            min-width: 85px;
            font-size: 0.75rem;
        }

        .log-msg {
            word-break: break-word;
        }

        .log-type-info { color: #e2e8f0; }    /* White-ish */
        .log-type-success { color: #4ade80; } /* Green */
        .log-type-error { color: #f87171; }   /* Red */
        .log-type-warning { color: #facc15; } /* Yellow */

        /* Custom Scrollbar for Webkit */
        #log::-webkit-scrollbar { width: 8px; }
        #log::-webkit-scrollbar-track { background: #1e293b; }
        #log::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }
        #log::-webkit-scrollbar-thumb:hover { background: #64748b; }

        /* Excel Button Style */
        .btn-small-excel {
            background-color: #10b981;
            color: white;
            padding: 4px 10px;
            font-size: 0.8rem;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-small-excel:hover {
            background-color: #059669;
        }

        /* Deep Search Styles */
        .search-hero { background: linear-gradient(135deg, #4f46e5, #4338ca); padding: 3rem 1rem; text-align: center; border-radius: 12px; color: white; margin-bottom: 20px; }
        .deep-search-input { width: 60%; padding: 15px; border-radius: 30px; border: none; font-size: 1.1rem; outline: none; box-shadow: 0 4px 6px rgba(0,0,0,0.2); }
        .deep-search-btn { padding: 15px 30px; border-radius: 30px; background: #fbbf24; color: #000; font-weight: bold; border: none; cursor: pointer; transition: 0.3s; }
        .deep-search-btn:hover { background: #f59e0b; transform: scale(1.05); }
        
        .result-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; display: none; }
        .info-box { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .info-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 8px 0; font-size: 0.9rem; }
        .info-label { font-weight: 600; color: #64748b; }
        .image-box { text-align: center; position: relative; }
        .zoom-img { max-width: 100%; border-radius: 8px; cursor: zoom-in; transition: 0.3s; max-height: 400px; object-fit: contain; }
        .zoom-img:hover { transform: scale(1.02); }

        @media (max-width: 768px) { 
            .header { flex-direction: column; gap: 10px; text-align: center; padding: 1rem; } 
            .container { max-width: 100%; padding: 0 1rem; }
            .table-responsive { overflow-x: auto; }
            .table-responsive table { min-width: 650px; } 
            .table-responsive td, .table-responsive th { display: table-cell; padding: 0.75rem 0.5rem; }
            .table-responsive td { padding-left: 1rem; }
            .deep-search-input { width: 100%; margin-bottom: 10px; }
            .result-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <h1>Welcome, <?php echo htmlspecialchars($user['full_name']); ?></h1>
            <small>Admin Dashboard</small>
        </div>
        <div class="header-right">
            <button class="dark-mode-toggle" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                <i class="fas fa-moon" id="darkModeIcon"></i>
            </button>
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
            <div style="padding: 2rem; text-align: center; color: var(--secondary);">
                Loading notifications...
            </div>
        </div>
    </div>

    <div class="toast" id="toast">
        <span id="toastMessage"></span>
    </div>

    <div class="bulk-actions-bar" id="bulkActionsBar">
        <span id="selectedCount">0 selected</span>
        <form method="POST" id="bulkApproveForm" style="display: flex; gap: 10px; margin: 0;">
            <input type="text" name="bulk_remark" placeholder="Remark for all..." required style="padding: 0.5rem; border-radius: 6px; border: none;">
            <button type="submit" name="bulk_approve" class="bulk-btn">
                <i class="fas fa-check"></i> Resolve All
            </button>
        </form>
        <button class="bulk-btn" onclick="clearSelection()">
            <i class="fas fa-times"></i> Cancel
        </button>
    </div>

    <div class="footer-credit">
        Website development by - Raja Sah, 7001159731
    </div>

    <div class="container">
        
    <div class="nav-tabs">
    <a href="?tab=dashboard"   class="nav-link <?= $active_tab=='dashboard'?'active':'' ?>">📊 Overview</a>
    <a href="?tab=submissions" class="nav-link <?= $active_tab=='submissions'?'active':'' ?>">🕐 Submissions</a>
    <a href="?tab=work_mgmt"   class="nav-link <?= $active_tab=='work_mgmt'?'active':'' ?>">🔧 Work Mgmt</a>
    <a href="?tab=deep_search" class="nav-link <?= $active_tab=='deep_search'?'active':'' ?>">🔍 Deep Search</a>
    <a href="?tab=ocr_import"  class="nav-link <?= $active_tab=='ocr_import'?'active':'' ?>">📷 OCR Import</a>
    <a href="?tab=quality"     class="nav-link <?= $active_tab=='quality'?'active':'' ?>">📉 Quality Report</a>
    <a href="?tab=missing_images" class="nav-link <?= $active_tab=='missing_images'?'active':'' ?>">🖼️ Missing Images</a>
    <a href="kyc_utility.php"  class="nav-link">🛠️ KYC Utility</a>
    <a href="?tab=users"       class="nav-link <?= $active_tab=='users'?'active':'' ?>">👥 Users</a>
</div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-error"><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <!-- DEEP SEARCH TAB -->
        <?php if ($active_tab == 'deep_search'): ?>
            <div class="search-hero">
                <h2 style="margin-bottom:10px;">🔍 Deep Data Search</h2>
                <p style="margin-bottom:20px; opacity:0.9;">Enter a Record Number to pull complete history, status & image.</p>
                <input type="number" id="deepSearchInput" class="deep-search-input" placeholder="Enter Record No (e.g. 1001)">
                <button onclick="performDeepSearch()" class="deep-search-btn">Search Records</button>
            </div>

            <div id="searchResultArea" class="result-grid">
                <!-- Left: Info -->
                <div class="info-box">
                    <h3 style="border-bottom:2px solid #f3f4f6; padding-bottom:10px; margin-bottom:15px; color:var(--primary);">📋 Record Details</h3>
                    <div id="recordDetailsContent"></div>
                    
                    <h4 style="margin-top:20px; color:#d97706;">🚩 DQC Flags History</h4>
                    <div id="flagHistoryContent" style="font-size:0.85rem; color:#4b5563;"></div>

                    <h4 style="margin-top:20px; color:#dc2626;">⚠️ Admin Reports</h4>
                    <div id="adminErrorContent" style="font-size:0.85rem; color:#4b5563;"></div>
                </div>

                <!-- Right: Image -->
                <div class="info-box image-box">
                    <h3 style="border-bottom:2px solid #f3f4f6; padding-bottom:10px; margin-bottom:15px; color:#0ea5e9;">🖼️ Associated Image</h3>
                    <img id="deepSearchImage" class="zoom-img" src="" alt="No Image Found" onclick="openZoomModal(this.src)" style="cursor: zoom-in;">
                    <p id="imageNameDisplay" style="margin-top:10px; color:#64748b; font-weight:600;"></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab == 'dashboard'): ?>
            <div class="today-stats">
                <div class="today-stat-item">
                    <h4><?php echo $today_completed; ?></h4>
                    <p><i class="fas fa-check-circle"></i> Completed Today</p>
                </div>
                <div class="today-stat-item">
                    <h4><?php echo $today_errors; ?></h4>
                    <p><i class="fas fa-exclamation-triangle"></i> Errors Today</p>
                </div>
                <div class="today-stat-item">
                    <h4><?php echo $today_submissions; ?></h4>
                    <p><i class="fas fa-paper-plane"></i> Submissions Today</p>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card" style="border-color: var(--primary);">
                    <h3><?php echo $total_allocated; ?></h3>
                    <p>Total Allocated</p>
                </div>
                <div class="stat-card" style="border-color: var(--success);">
                    <h3><?php echo $completed_records; ?></h3>
                    <p>Completed</p>
                </div>
                <div class="stat-card" style="border-color: var(--warning);">
                    <h3><?php echo $flagged_records; ?></h3>
                    <p>Issues Flagged</p>
                </div>
                <div class="stat-card" style="border-color: #6366f1;">
                    <h3><?php echo number_format($mapped_count); ?></h3>
                    <p>Mapped Records (Auto-Fill)</p>
                </div>
            </div>

            <!-- Existing DEO Performance Report -->
            <div class="section">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                    <h2 style="margin: 0; border: none; padding: 0;">📊 DEO Performance Report</h2>
                    <form method="POST" style="margin: 0;">
                        <button type="submit" name="export_deo_performance" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-file-excel"></i> Download Excel
                        </button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>DEO Profile</th>
                                <th>Allocated Ranges (Date-wise)</th>
                                <th>Total Count</th>
                                <th>Work Done <small>(Completed+Flagged+Corrected+Verified)</small></th>
                                <th>Flagged ⚠️</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($deo_stats_result->num_rows > 0) while ($stat = $deo_stats_result->fetch_assoc()): 
                                    $percent = ($stat['total_completed'] > 0 && $stat['total_assigned'] > 0) ? round(($stat['total_completed'] / $stat['total_assigned']) * 100) : 0;
                                    if($percent > 100) $percent = 100; 
                                    $flagged_display = ($stat['pending_flags'] > 0) ? '<span class="badge bg-red">Issues: ' . $stat['pending_flags'] . '</span>' : '-';
                                    $username = $stat['username'];
                                    $ranges_data = isset($deo_datewise_ranges[$username]) ? $deo_datewise_ranges[$username] : [];
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($stat['full_name']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--secondary);">@<?php echo htmlspecialchars($stat['username']); ?></div>
                                        </td>
                                        <td style="font-size: 0.8rem; max-width: 300px; white-space: normal;">
                                            <?php if (!empty($ranges_data)): ?>
                                                <div style="max-height: 150px; overflow-y: auto; padding-right: 5px;">
                                                    <?php foreach ($ranges_data as $idx => $range_info): ?>
                                                        <div style="padding: 4px 6px; margin-bottom: 3px; background: <?php echo ($idx % 2 == 0) ? '#f0f9ff' : '#faf5ff'; ?>; border-radius: 4px; border-left: 3px solid <?php echo ($idx % 2 == 0) ? '#0ea5e9' : '#a855f7'; ?>;">
                                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                                <span style="color: #64748b; font-size: 0.75rem;">📅 <?php echo date('d M', strtotime($range_info['date'])); ?></span>
                                                                <span style="color: #10b981; font-size: 0.75rem; font-weight: 600;">(<?php echo $range_info['count']; ?>)</span>
                                                            </div>
                                                            <div style="font-weight: 600; color: #1e293b; font-size: 0.8rem; margin-top: 2px;">
                                                                <?php echo $range_info['range']; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #94a3b8;">No ranges assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight: 600;">Total: <?php echo $stat['total_assigned']; ?></td>
                                        <td><span class="badge bg-green">Attempted: <?php echo $stat['total_completed']; ?></span></td>
                                        <td><?php echo $flagged_display; ?></td>
                                        <td style="min-width: 150px;">
                                            <div class="progress-container">
                                                <div class="progress-bar" style="width: <?php echo $percent; ?>%;"></div>
                                            </div>
                                            <small><?php echo round($percent, 2); ?>%</small>
                                        </td>
                                    </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Allocate New Work section removed - P1 handles assignments -->

            <div class="section">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" id="errorSearch" placeholder="🔍 Search by Record No, DEO Name, or Error Details..." onkeyup="searchErrors()">
                </div>
                <?php
                // Debug info
                $total_in_table = (int)$conn->query("SELECT COUNT(*) as c FROM `report_to_admin`")->fetch_assoc()['c'];
                $open_in_table  = (int)$conn->query("SELECT COUNT(*) as c FROM report_to_admin WHERE `status`='open'")->fetch_assoc()['c'];
                if ($total_in_table == 0):
                ?>
                <div style="background:#fef3c7;border:1px solid #f59e0b;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:0.85rem;">
                    ⚠️ <strong>report_to_admin table empty hai!</strong> Koi bhi report submit nahi hui abhi tak.
                </div>
                <?php elseif ($open_in_table == 0): ?>
                <div style="background:#f0fdf4;border:1px solid #10b981;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:0.85rem;">
                    ✅ Table mein <?php echo $total_in_table; ?> records hain lekin sab solved hain.
                </div>
                <?php else: ?>
                <div style="background:#eff6ff;border:1px solid #3b82f6;padding:0.5rem 1rem;border-radius:8px;margin-bottom:0.75rem;font-size:0.8rem;color:#1e40af;">
                    📊 Total: <?php echo $total_in_table; ?> | Open: <?php echo $open_in_table; ?> | Filtered: <?php echo ($errors_result ? $errors_result->num_rows : 0); ?>
                </div>
                <?php endif; ?>
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 2px solid #fce7f3; padding-bottom: 0.5rem; flex-wrap: wrap; gap: 10px;">
                    <h2 style="margin: 0; border: none; padding: 0; font-size: 1.25rem;">
                        📋 QC, DEO and Autotyper Reports
                        <span style="color: #dc2626;">(Open: <?php echo $active_count; ?>)</span>
                        <span style="color: #10b981;">(Replied: <?php echo $replied_count; ?>)</span>
                    </h2>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="export_deo" value="<?php echo htmlspecialchars($filter_error_deo_id); ?>">
                            <input type="hidden" name="export_date" value="<?php echo htmlspecialchars($filter_error_date); ?>">
                            <input type="hidden" name="export_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                            <input type="hidden" name="export_source" value="<?php echo htmlspecialchars($filter_source); ?>">
                            <button type="submit" name="export_errors" style="background:#10b981; padding:0.5rem 1rem; font-size:0.85rem;">📥 Export Reports</button>
                        </form>
                        <button onclick="document.getElementById('bulkReplySection').style.display = document.getElementById('bulkReplySection').style.display === 'none' ? 'block' : 'none';" style="background:#8b5cf6; color:white; padding:0.5rem 1rem; font-size:0.85rem; border:none; border-radius:6px; cursor:pointer;">
                            📤 Bulk Reply
                        </button>
                    </div>
                </div>

                <!-- Bulk Reply Section -->
                <div id="bulkReplySection" style="display:none; margin-bottom:1.5rem; padding:1rem; background:linear-gradient(135deg, #faf5ff, #f3e8ff); border:2px dashed #a855f7; border-radius:10px;">
                    <h4 style="margin:0 0 0.75rem 0; color:#7c3aed;">📤 Bulk Reply via Excel</h4>
                    <p style="font-size:0.85rem; color:#6b7280; margin-bottom:1rem;">
                        <strong>Step 1:</strong> "📥 Export Reports" button se pending reports export karo.<br>
                        <strong>Step 2:</strong> Exported file mein <strong>Admin_Reply</strong> column mein apna reply likho.<br>
                        <strong>Step 3:</strong> File neeche upload karo → "Process Replies" karo.
                    </p>
                    
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input type="file" id="bulkReplyFile" accept=".xlsx,.xls" style="flex:1; min-width:200px; padding:0.5rem; border:1px solid #d1d5db; border-radius:6px; background:white;">
                        <button onclick="processBulkReply()" style="background:#7c3aed; color:white; padding:0.6rem 1.5rem; border:none; border-radius:6px; cursor:pointer; font-weight:600;">
                            🚀 Process Replies
                        </button>
                        <button onclick="downloadBulkReplyTemplate()" style="background:#f59e0b; color:white; padding:0.6rem 1rem; border:none; border-radius:6px; cursor:pointer; font-size:0.85rem;">
                            📋 Download Template
                        </button>
                    </div>
                    <div id="bulkReplyStatus" style="margin-top:0.75rem; font-size:0.85rem;"></div>
                </div>
                
                <?php if ($error_summary_result && $error_summary_result->num_rows > 0): ?>
                    <div style="margin-bottom: 1.5rem; display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php while($sum = $error_summary_result->fetch_assoc()): ?>
                            <span class="badge" style="font-size:0.85rem; background:#f8fafc; color:#334155; border:1px solid #e2e8f0; padding: 5px 12px; display:inline-flex; align-items:center; gap:8px;">
                                <strong><?php echo htmlspecialchars($sum['full_name']); ?></strong> 
                                <span style="color:#991b1b; background:#fee2e2; padding:0 6px; border-radius:10px; font-size:0.8rem;" title="Pending">P: <?php echo $sum['pending_count']; ?></span>
                                <span style="color:#065f46; background:#d1fae5; padding:0 6px; border-radius:10px; font-size:0.8rem;" title="Replied">R: <?php echo $sum['replied_count']; ?></span>
                            </span>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
                
                <form method="GET" style="margin-bottom: 1.5rem; border: 1px solid #e2e8f0; padding: 1rem; border-radius: 8px;">
                    <input type="hidden" name="tab" value="dashboard">
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Filter by Reporter</label>
                            <select name="filter_error_deo_id">
                                <option value="">-- Show All --</option>
                                <?php 
                                if ($reporter_list_result && $reporter_list_result->num_rows > 0) {
                                    while ($rpt_user = $reporter_list_result->fetch_assoc()): 
                                        $selected = ($filter_error_deo_id == $rpt_user['id']) ? 'selected' : '';
                                        $role_badge = strtoupper($rpt_user['role']);
                                        echo "<option value='{$rpt_user['id']}' $selected>".htmlspecialchars($rpt_user['full_name'])." ({$role_badge})</option>";
                                    endwhile;
                                } else {
                                    // Fallback: show DEO list
                                    $deo_result->data_seek(0);
                                    while ($deo = $deo_result->fetch_assoc()): 
                                        $selected = ($filter_error_deo_id == $deo['id']) ? 'selected' : '';
                                        echo "<option value='{$deo['id']}' $selected>".htmlspecialchars($deo['full_name'])."</option>";
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Filter by Date</label>
                            <input type="date" name="filter_error_date" value="<?php echo htmlspecialchars($filter_error_date); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Filter by Status</label>
                            <select name="filter_status">
                                <option value="">-- All (Pending + Replied) --</option>
                                <option value="pending" <?php echo ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending Action</option>
                                <option value="replied" <?php echo ($filter_status == 'replied') ? 'selected' : ''; ?>>Admin Replied</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Filter by Source</label>
                            <select name="filter_source">
                                <option value="">-- All Sources --</option>
                                <option value="first_qc" <?php echo (($_GET['filter_source']??'')==='first_qc') ? 'selected' : ''; ?>>First QC</option>
                                <option value="second_qc" <?php echo (($_GET['filter_source']??'')==='second_qc') ? 'selected' : ''; ?>>Second QC</option>
                                <option value="p2_deo" <?php echo (($_GET['filter_source']??'')==='p2_deo') ? 'selected' : ''; ?>>P2 DEO</option>
                                <option value="autotyper" <?php echo (($_GET['filter_source']??'')==='autotyper') ? 'selected' : ''; ?>>Autotyper</option>
                                <option value="admin" <?php echo (($_GET['filter_source']??'')==='admin') ? 'selected' : ''; ?>>Admin Bulk</option>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 1rem; flex-wrap: wrap;">
                        <button type="submit" style="flex: 1; background: #0ea5e9; min-width: 150px;">Apply Filters</button>
                        <a href="?tab=dashboard" style="flex: 1; background: #64748b; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-align: center; text-decoration: none; min-width: 150px; display: flex; align-items: center; justify-content: center;">Clear Filters</a>
                    </div>
                </form>

                <?php 
                $errors_count = ($errors_result && $errors_result !== false) ? $errors_result->num_rows : 0;
                if ($errors_count > 0): ?>
                    <div id="errorsList" class="scroll-box" style="max-height: 600px;">
                    <?php while ($error = $errors_result->fetch_assoc()): 
                        $full_details = $error['error_details'];
                        $img_no = "";
                        $clean_details = $full_details;
                        
                        // Extract Image No from details (legacy format)
                        if (preg_match('/\[Image No: (.*?)\]/', $full_details, $matches)) {
                            $img_no = $matches[1];
                            $clean_details = trim(str_replace($matches[0], '', $clean_details));
                        }
                        
                        // Override with auto_image_no from JOIN (more reliable)
                        if (!empty($error['auto_image_no'])) {
                            $img_no = $error['auto_image_no'];
                        }
                        
                        $item_class = ($error['report_status'] == 'admin_reviewed') ? 'error-item-reviewed' : 'error-item-pending';
                    ?>
                        <div class="error-item-container <?php echo $item_class; ?> error-item" 
                             id="ce_item_<?php echo $error['id']; ?>"
                             style="padding: 1rem; border-radius: 8px; margin-bottom: 1rem;" 
                             data-ce-id="<?php echo $error['id']; ?>"
                             data-record="<?php echo htmlspecialchars($error['record_no']); ?>"
                             data-deo="<?php echo htmlspecialchars($error['reporter_name']); ?>"
                             data-details="<?php echo htmlspecialchars($clean_details); ?>">
                            <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 0.5rem; align-items: center;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if ($error['report_status'] == 'pending'): ?>
                                        <input type="checkbox" class="custom-checkbox error-checkbox" value="<?php echo $error['id']; ?>" onchange="updateBulkActions()">
                                    <?php endif; ?>
                                    <div>
                                        <strong>Rec: <?php echo htmlspecialchars($error['record_no']); ?></strong>
                                        
                                        <?php if($img_no): ?>
                                            <span style="color: #cbd5e1; margin: 0 5px;">|</span>
                                            <span style="color: #0ea5e9; font-weight: 600;">Image: <?php echo htmlspecialchars($img_no); ?></span>
                                        <?php endif; ?>

                                        <span style="color: #cbd5e1; margin: 0 5px;">|</span>
                                        <span style="color: var(--primary); font-weight: 500;">Header: <?php echo htmlspecialchars($error['error_field']); ?></span>
                                    </div>
                                </div>
                                <small style="color: var(--secondary);"><?php echo date('d M, h:i A', strtotime($error['created_at'])); ?></small>
                            </div>
                            
                            <div style="font-size: 0.85rem; color: var(--secondary); margin-bottom: 0.5rem;">
                                <?php 
                                    $reporter_role_raw = strtolower($error['reporter_role'] ?? 'deo');
                                    $reported_from_val  = $error['reported_from'] ?? '';
                                    $display_name = !empty($error['reported_by_name']) ? $error['reported_by_name'] : ($error['reporter_name'] ?? '—');
                                    
                                    // Source badge
                                    $src_labels = [
                                        'first_qc'  => ['🔍 First QC',   '#0d6efd'],
                                        'second_qc' => ['🔍 Second QC',  '#6610f2'],
                                        'p2_deo'    => ['📝 P2 DEO',     '#198754'],
                                        'autotyper' => ['🤖 Autotyper',  '#fd7e14'],
                                        'admin'     => ['🛠️ Admin Bulk', '#dc3545'],
                                    ];
                                    $src = $src_labels[$reported_from_val] ?? [strtoupper($reporter_role_raw), '#6c757d'];
                                    echo "<span style='background:{$src[1]};color:white;padding:2px 8px;border-radius:4px;font-size:0.72rem;font-weight:700;margin-right:6px;'>{$src[0]}</span>";
                                    echo "<strong>Reported By:</strong> " . htmlspecialchars($display_name);
                                ?>
                            </div>

                            <div style="background: linear-gradient(135deg, #fef2f2, #fee2e2); padding: 10px 12px; border-radius: 6px; border-left: 4px solid #ef4444; margin-bottom: 0.5rem;">
                                <span style="background:#ef4444; color:white; padding:2px 8px; border-radius:4px; font-size:0.75rem; font-weight:600; margin-right:8px;">🔴 Issue</span>
                                <span style="color: #991b1b; font-size: 0.9rem;"><?php echo htmlspecialchars($clean_details); ?></span>
                            </div>
                            
                            <?php if ($error['report_status'] == 'pending'): ?>
                                <form method="POST" style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap; position: relative;">
                                    <input type="hidden" name="error_id" value="<?php echo $error['id']; ?>">
                                    <div style="flex: 1; min-width: 200px; position: relative;">
                                        <input type="text" name="admin_remark" class="remark-input" placeholder="Type instruction for DEO..." required style="flex: 1; min-width: 200px; padding-right: 40px;" onfocus="showQuickReplies(this)" onblur="hideQuickReplies(this)">
                                        <button type="button" class="quick-reply-btn" onclick="toggleQuickReply(this)" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: var(--primary); cursor: pointer; padding: 0.25rem 0.5rem;">
                                            <i class="fas fa-bolt"></i>
                                        </button>
                                        <div class="quick-reply-dropdown">
                                            <?php foreach($quick_replies as $qr): ?>
                                                <div class="quick-reply-item" onclick="selectQuickReply(this, '<?php echo htmlspecialchars($qr); ?>')">
                                                    <?php echo htmlspecialchars($qr); ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <button type="submit" name="submit_remark" style="background: #f59e0b; color: black; padding: 0.5rem 1rem; width: auto;">
                                        <i class="fas fa-reply"></i> Reply
                                    </button>
                                </form>
                            <?php else: ?>
                                <div style="margin-top: 10px; font-size: 0.9rem; color: var(--success); font-weight: 500;">
                                    ✓ Admin Reply: <strong><?php echo htmlspecialchars($error['admin_remark']); ?></strong>
                                </div>
                                <small style="color: var(--danger); font-size: 0.8rem;">⏳ Awaiting DEO Resolution</small>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <?php if ($errors_result === false): ?>
                        <div class="alert" style="background:#fee2e2;color:#991b1b;padding:1rem;border-radius:8px;">
                            ❌ DB Error: <strong><?php echo htmlspecialchars($errors_query_error); ?></strong><br>
                            <small style="word-break:break-all;">Query: <?php echo htmlspecialchars($errors_query); ?></small>
                        </div>
                    <?php else: ?>
                    <p style="text-align: center; color: var(--success); font-weight: 500;">✨ No pending reports! All clear.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($active_tab == 'submissions'): ?>
            <div class="section">
                <h2 style="margin-bottom: 1rem;">🕐 DEO Submissions Log</h2>
                
                <form method="GET" style="margin-bottom: 1.5rem; border: 1px solid #e2e8f0; padding: 1rem; border-radius: 8px;">
                    <input type="hidden" name="tab" value="submissions">
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Filter by Date</label>
                            <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Filter by DEO</label>
                            <select name="filter_deo_id_sub">
                                <option value="">-- All DEOs --</option>
                                <?php 
                                $deo_result->data_seek(0);
                                while ($deo = $deo_result->fetch_assoc()): 
                                    $selected = ($filter_deo_id_sub == $deo['id']) ? 'selected' : '';
                                    echo "<option value='{$deo['id']}' $selected>".htmlspecialchars($deo['full_name'])."</option>";
                                endwhile; 
                                ?>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 1rem; flex-wrap: wrap;">
                        <button type="submit" style="flex: 1; background: #0ea5e9; min-width: 150px;">Apply Filters</button>
                        <a href="?tab=submissions" style="flex: 1; background: #64748b; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-align: center; text-decoration: none; min-width: 150px;">Clear Filters</a>
                    </div>
                </form>

                <div class="scroll-box">
                    <div class="table-responsive">
                        <?php if (count($grouped_submissions) > 0): ?>
                            <?php foreach ($grouped_submissions as $deo_name => $logs): ?>
                                <div class="work-mgmt-group" style="margin-top: 1.5rem; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
                                    <h4 style="background: #eef2f7; padding: 10px 15px; margin: 0; font-size: 1rem; color: var(--primary);">
                                        <?php echo htmlspecialchars($deo_name); ?> (<?php echo count($logs); ?> Submissions)
                                    </h4>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Submitted Range</th>
                                                <th>Count</th>
                                                <th>Time Submitted</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($logs as $log): ?>
                                                <tr>
                                                    <td><?php echo $log['record_from']; ?> - <?php echo $log['record_to']; ?></td>
                                                    <td><?php echo $log['record_count']; ?></td>
                                                    <td><?php echo date('d M, Y h:i A', strtotime($log['log_time'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: var(--secondary);">No submission history found for current filters.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($active_tab == 'quality'): ?>
            <div class="section" style="border-top: 5px solid #8b5cf6;">
                <h2>📉 DEO Quality Report (Flags)</h2>
                
                <!-- Filter Form -->
                <form method="GET" style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:20px; display:flex; gap:15px; align-items:center; border:1px solid #e2e8f0; flex-wrap: wrap;">
                    <input type="hidden" name="tab" value="quality">
                    <div>
                        <label style="font-weight:600; font-size:0.9rem; color:#64748b;">Filter by Date:</label>
                        <input type="date" name="quality_date" value="<?php echo $quality_filter_date; ?>" style="padding:5px 10px; border:1px solid #cbd5e1; border-radius:4px;">
                    </div>
                    <div>
                        <label style="font-weight:600; font-size:0.9rem; color:#64748b;">Filter by DEO:</label>
                        <select name="quality_deo" style="padding:5px 10px; border:1px solid #cbd5e1; border-radius:4px; width: auto;">
                            <option value="">-- All DEOs --</option>
                            <?php 
                            $deo_result->data_seek(0);
                            while ($deo = $deo_result->fetch_assoc()): 
                                $selected = ($quality_filter_deo == $deo['id']) ? 'selected' : '';
                                echo "<option value='{$deo['id']}' $selected>".htmlspecialchars($deo['full_name'])."</option>";
                            endwhile; 
                            ?>
                        </select>
                    </div>
                    <button type="submit" style="padding:6px 15px; background:#8b5cf6; border:none; color:white; border-radius:6px; cursor:pointer;">Apply Filter</button>
                    <?php if(!empty($quality_filter_date) || !empty($quality_filter_deo)): ?>
                        <a href="?tab=quality" style="color:#ef4444; text-decoration:none; font-size:0.9rem;">Start Over (Clear)</a>
                    <?php endif; ?>
                </form>

                <div style="display:flex; justify-content:space-between; margin-bottom: 20px;">
                    <div>
                        <h4 style="margin-bottom:10px; display:flex; align-items:center; gap:10px;">
                            Overall Summary
                            <button onclick="downloadOverallSummary()" class="btn-small-excel">📥 Excel</button>
                        </h4>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>DEO Name</th>
                                        <th>Total Flags</th>
                                        <th>Pending (Unfixed)</th>
                                        <th>Fixed Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($overall_data) > 0): ?>
                                        <?php foreach ($overall_data as $fs): ?>
                                            <tr>
                                                <td style="font-weight:600;"><?php echo htmlspecialchars($fs['full_name']); ?></td>
                                                <td style="color:#ef4444; font-weight:bold;"><?php echo $fs['total_flags']; ?></td>
                                                <td style="color:#f59e0b;"><?php echo $fs['pending']; ?></td>
                                                <td style="color:#10b981;"><?php echo $fs['fixed']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <!-- Grand Total Row -->
                                        <tr style="background-color: #f3f4f6; font-weight: bold;">
                                            <td style="text-align: right;">GRAND TOTAL:</td>
                                            <td style="color:#ef4444;"><?php echo $grand_total_flags; ?></td>
                                            <td style="color:#f59e0b;"><?php echo $grand_total_pending; ?></td>
                                            <td style="color:#10b981;"><?php echo $grand_total_fixed; ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr><td colspan="4" style="text-align:center; color:#999;">No flags data found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Global Header Breakdown (Sab ka Total Count) -->
                 <h4 style="margin-bottom:10px; margin-top:30px; color:#374151;">Field-wise Grand Total (Header Summary)</h4>
                 <div class="table-responsive" style="margin-bottom: 30px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Error Field (Header)</th>
                                <th>Total Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($global_field_data) > 0): ?>
                                <?php foreach ($global_field_data as $gfd): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($gfd['flagged_fields']); ?></td>
                                        <td style="font-weight:bold;"><?php echo $gfd['count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="background-color: #f3f4f6; font-weight: bold;">
                                    <td style="text-align: right;">TOTAL ERRORS:</td>
                                    <td><?php echo $total_field_errors; ?></td>
                                </tr>
                            <?php else: ?>
                                <tr><td colspan="2" style="text-align:center; color:#999;">No header data found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                 </div>
                
                <!-- NEW SECTION: Record Wise Counts -->
                <h4 style="margin-bottom:15px; margin-top:30px; color:var(--primary); display:flex; align-items:center; gap:10px;">
                    📄 Record Wise Flag Status
                    <button onclick="downloadRecordWise()" class="btn-small-excel">📥 Excel</button>
                </h4>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Record No</th>
                                <th>DEO Name</th>
                                <th>Total Flags</th>
                                <th>Pending</th>
                                <th>Fixed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($record_data) > 0): ?>
                                <?php foreach ($record_data as $fr): ?>
                                    <tr>
                                        <td style="font-weight:600;">#<?php echo htmlspecialchars($fr['record_no']); ?></td>
                                        <td><?php echo htmlspecialchars($fr['deo_name']); ?></td>
                                        <td style="font-weight:bold;"><?php echo $fr['total_flags']; ?></td>
                                        <td style="color:#f59e0b;"><?php echo $fr['pending']; ?></td>
                                        <td style="color:#10b981;"><?php echo $fr['fixed']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                 <!-- Record Table Footer -->
                                <tr style="background-color:#f3f4f6; font-weight:bold; position:sticky; bottom:0;">
                                    <td colspan="2" style="text-align:right;">
                                        TOTAL (<?php echo $rec_total_records; ?> Records)
                                    </td>
                                    <td><?php echo $rec_total_flags; ?></td>
                                    <td><?php echo $rec_total_pending; ?></td>
                                    <td><?php echo $rec_total_fixed; ?></td>
                                </tr>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center; color:#999;">No record-wise data found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h4 style="margin-bottom:15px; margin-top:30px; color:var(--primary); display:flex; align-items:center; gap:10px;">
                    🚩 Detailed Flag Breakdown (By Header & DEO)
                    <button onclick="downloadDetailedBreakdown()" class="btn-small-excel">📥 Excel</button>
                </h4>
                <?php if (count($breakdown_data) > 0): ?>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px;">
                        <?php foreach($breakdown_data as $d_name => $items): ?>
                            <div class="stat-card" style="text-align:left; border-top:4px solid #f59e0b;">
                                <h3 style="font-size:1.1rem; margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;"><?php echo htmlspecialchars($d_name); ?></h3>
                                <ul style="list-style:none; padding:0; margin:0;">
                                    <?php 
                                    $deo_total = 0;
                                    foreach($items as $it): 
                                        $deo_total += $it['count'];
                                    ?>
                                        <li style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #eee; font-size:0.9rem;">
                                            <span><?php echo htmlspecialchars($it['flagged_fields']); ?></span>
                                            <span class="badge bg-red"><?php echo $it['count']; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                    <li style="display:flex; justify-content:space-between; padding:10px 0 0 0; font-weight:bold; font-size:0.9rem; color:var(--primary);">
                                        <span>Total:</span>
                                        <span><?php echo $deo_total; ?></span>
                                    </li>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="text-align:center; color:#999;">No detailed breakdown available.</p>
                <?php endif; ?>
            </div>

        <?php elseif ($active_tab == 'work_mgmt'): ?>
            <div class="section">
                <h2>🔧 Work Management</h2>
                <p style="color: var(--secondary); margin-bottom: 20px;">View all active assignments date-wise. Use filters to see specific date assignments.</p>
                
                <!-- Date Filter -->
                <form method="GET" style="margin-bottom: 1.5rem; padding: 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <input type="hidden" name="tab" value="work_mgmt">
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <div>
                            <label style="font-size: 0.85rem; font-weight: 600; color: #475569;">📅 Filter by Date:</label>
                            <select name="work_date" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; min-width: 180px;">
                                <option value="">-- All Dates --</option>
                                <?php if ($available_dates): while ($dt = $available_dates->fetch_assoc()): ?>
                                    <option value="<?php echo $dt['assign_date']; ?>" <?php echo ($work_filter_date == $dt['assign_date']) ? 'selected' : ''; ?>>
                                        <?php echo date('d M Y', strtotime($dt['assign_date'])); ?>
                                    </option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                        <button type="submit" style="background: var(--primary); color: white; padding: 8px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                            🔍 Filter
                        </button>
                        <?php if (!empty($work_filter_date)): ?>
                            <a href="?tab=work_mgmt" style="color: #ef4444; text-decoration: none; font-size: 0.9rem;">✕ Clear Filter</a>
                        <?php endif; ?>
                    </div>
                </form>
                
                <div class="scroll-box">
                    <div class="table-responsive">
                        <?php if (count($grouped_assignments) > 0): ?>
                            <?php foreach ($grouped_assignments as $date_key => $deo_list): ?>
                                <div style="margin-bottom: 2rem;">
                                    <h3 style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 10px 15px; border-radius: 8px 8px 0 0; margin: 0; font-size: 1rem;">
                                        📅 <?php echo $date_key; ?>
                                    </h3>
                                    
                                    <?php foreach ($deo_list as $deo_name => $assigns): 
                                        $total_deo_records = 0;
                                        foreach($assigns as $asg) {
                                            $total_deo_records += $asg['total_qty'];
                                        }
                                    ?>
                                        <div class="work-mgmt-group" style="border: 1px solid #ddd; border-top: none; overflow: hidden;">
                                            <h4 style="background: #eef2f7; padding: 10px 15px; margin: 0; font-size: 0.95rem; color: var(--primary);">
                                                👤 <?php echo htmlspecialchars($deo_name); ?> 
                                                <span style="font-size:0.85rem; color:#64748b; font-weight:normal;">
                                                    (<strong>Total: <?php echo $total_deo_records; ?> Records</strong>)
                                                </span>
                                            </h4>
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Ranges</th>
                                                        <th>Progress (Records)</th>
                                                        <th>Progress (%)</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($assigns as $assign): 
                                                        $range_count = $assign['total_qty'];
                                                        $completed_in_range = $assign['completed_qty'];
                                                        $percent = ($range_count > 0) ? round(($completed_in_range / $range_count) * 100) : 0;
                                                        if ($percent > 100) $percent = 100;
                                                    ?>
                                                        <tr>
                                                            <td style="font-weight: 600; max-width: 300px; white-space: normal;">
                                                                <?php echo $assign['record_no_from'] . ' - ' . $assign['record_no_to']; ?>
                                                            </td>
                                                            <td><span class="badge" style="background:#eef2ff; color:var(--primary); font-weight:600;"><?php echo $completed_in_range; ?> / <?php echo $range_count; ?></span></td>
                                                            <td style="min-width: 150px;">
                                                                <div style="display:flex; justify-content:space-between; font-size:0.75rem;">
                                                                    <span>Progress</span>
                                                                    <span><?php echo round($percent, 2); ?>%</span>
                                                                </div>
                                                                <div class="progress-container" style="height: 6px;">
                                                                    <div class="progress-bar" style="width: <?php echo $percent; ?>%;"></div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <form method="POST" onsubmit="return confirm('Are you sure you want to Un-assign all work from this user?');">
                                                                    <input type="hidden" name="unassign_username" value="<?php echo $assign['username']; ?>">
                                                                    <button type="submit" name="unassign_user_work" class="btn-danger" style="padding: 5px 10px;">🗑️ Un-assign</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: var(--secondary); padding: 2rem;">
                                <?php echo !empty($work_filter_date) ? '📭 No assignments found for selected date.' : '📭 No active assignments found.'; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        
        <?php elseif ($active_tab == 'missing_images'): ?>
    <div class="section">
        <h2>🖼️ Records without Images</h2>
        <p style="color: var(--secondary); margin-bottom: 20px;">
            Niche wo records hain jinka image mapping missing hai. Aap yahan se manual upload kar sakte hain.
        </p>
        
        <!-- DEO Wise Missing Image Summary -->
        <div style="margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 12px; border: 2px solid #f59e0b;">
            <h3 style="margin: 0 0 15px 0; color: #92400e; font-size: 1rem; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-chart-pie"></i> DEO Wise Missing Image Count
                <span style="background: #dc2626; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; margin-left: auto;">
                    Total: <?php echo $total_missing; ?>
                </span>
            </h3>
            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                <?php if ($deo_missing_result && $deo_missing_result->num_rows > 0): ?>
                    <?php while ($deo_miss = $deo_missing_result->fetch_assoc()): ?>
                        <div style="background: white; padding: 10px 15px; border-radius: 8px; border-left: 4px solid #ef4444; min-width: 180px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <div style="font-weight: 700; color: #1e293b; font-size: 0.9rem;"><?php echo htmlspecialchars($deo_miss['deo_name'] ?? 'Unknown'); ?></div>
                            <div style="font-size: 0.75rem; color: #64748b;">@<?php echo htmlspecialchars($deo_miss['username'] ?? 'N/A'); ?></div>
                            <div style="font-size: 1.25rem; font-weight: 800; color: #dc2626; margin-top: 5px;">
                                <i class="fas fa-image" style="font-size: 0.9rem;"></i> <?php echo $deo_miss['missing_count']; ?> missing
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="color: #10b981; font-weight: 600;">✨ Sabhi DEOs ke paas mapped images hain!</div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="search-box" style="margin-bottom: 15px;">
            <i class="fas fa-search search-icon"></i>
            <input type="text" class="search-input" id="missingSearch" placeholder="Search Record No or DEO Name..." onkeyup="filterMissingTable()">
        </div>

        <div class="scroll-box" style="max-height: 500px; border: 1px solid #e2e8f0; border-radius: 8px;">
            <div class="table-responsive">
                <table id="missingTable">
                    <thead style="position: sticky; top: 0; z-index: 10; background: #f8fafc;">
                        <tr>
                            <th>Record No</th>
                            <th>Assigned DEO</th>
                            <th>Upload & Map Image</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($missing_result && $missing_result->num_rows > 0): ?>
                            <?php while ($row = $missing_result->fetch_assoc()): ?>
                                <tr class="missing-row">
                                    <td style="font-weight: 700;">#<?php echo $row['record_no']; ?></td>
                                    <td><?php echo htmlspecialchars($row['deo_name']); ?></td>
                                    <td>
                                        <input type="file" id="file_<?php echo $row['record_no']; ?>" accept="image/*" style="font-size: 0.8rem;">
                                    </td>
                                    <td>
                                        <button class="btn-success" onclick="uploadSingleImage('<?php echo $row['record_no']; ?>')" style="padding: 5px 12px;">
                                            <i class="fas fa-upload"></i> Assign
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center; padding: 2rem; color:var(--success);">✨ All records have images mapped!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    // Missing Records Filter (Search) - includes DEO name
    function filterMissingTable() {
        const input = document.getElementById('missingSearch');
        const filter = input.value.toLowerCase();
        const rows = document.querySelectorAll('.missing-row');

        rows.forEach(row => {
            const recordNo = row.querySelector('td:first-child').textContent.toLowerCase();
            const deoName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
            const combinedText = recordNo + ' ' + deoName;
            row.style.display = combinedText.includes(filter) ? '' : 'none';
        });
    }

    // Single Image Upload Logic
    function uploadSingleImage(recNo) {
        const fileInput = document.getElementById('file_' + recNo);
        const file = fileInput.files[0];
        const btn = event.currentTarget;
        
        if (!file) {
            showToast("Pehle image select karo!", "error");
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>...';

        const formData = new FormData();
        formData.append('ocr_image_upload', file);

        fetch('p2_admin_dashboard.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const mapForm = new FormData();
                mapForm.append('ajax_import_mapping', '1');
                mapForm.append('mapping_data', JSON.stringify([{ imageName: file.name, recordNo: recNo }]));
                return fetch('p2_admin_dashboard.php', { method: 'POST', body: mapForm });
            } else { throw new Error("Upload Failed"); }
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast("Success! Record #" + recNo + " updated.", "success");
                // Row remove kar dete hain reload ke bina taaki user ka flow na toote
                fileInput.closest('tr').style.backgroundColor = '#dcfce7';
                setTimeout(() => fileInput.closest('tr').remove(), 500);
            }
        })
        .catch(err => {
            showToast("Error ho gaya!", "error");
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-upload"></i> Assign';
        });
    }
    </script>

        <?php elseif ($active_tab == 'upload'): ?>
            <div class="section">
                <h2><i class="fas fa-file-csv" style="color: var(--success);"></i> Upload Image Mapping Data (CSV)</h2>
                <p style="color: var(--secondary); margin-bottom: 20px; font-size: 0.9rem;">
                    Upload a <strong>CSV file</strong> to enable auto-fill. 
                    <strong>Column A:</strong> Image Number (e.g. SWQRCDIMG_4AQ1_enc), 
                    <strong>Column B:</strong> Record Number (e.g. 1001).
                </p>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="upload-zone" onclick="document.getElementById('fileInput').click()">
                        <input type="file" name="csv_file" id="fileInput" class="file-input" accept=".csv" onchange="updateFileName(this)">
                        <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: #bdc3c7; margin-bottom: 1rem;"></i>
                        <h4 style="margin: 0; color: var(--secondary);">Click to Select CSV File</h4>
                        <p id="fileName" style="margin: 5px 0 0; color: #888; font-size: 0.8rem;">No file selected</p>
                    </div>
                    <div style="margin-top: 20px; text-align: right;">
                        <button type="submit" name="upload_mapping">
                            <i class="fas fa-check"></i> Upload & Process
                        </button>
                    </div>
                </form>
            </div>
            
        <?php elseif ($active_tab == 'ocr_import'): ?>
            <div class="section">
                <h2><i class="fas fa-camera-retro" style="color: #db2777;"></i> Advanced OCR Import</h2>
                <p style="color: var(--secondary); margin-bottom: 20px; font-size: 0.9rem;">
                    Upload images to extract Record Numbers automatically using OCR.
                    <br>Extracted data can be imported directly into the system database.
                </p>

                <div class="card">
                    <h3>⚙️ Configuration</h3>
                    
                    <label for="patternSelect">Record Pattern:</label>
                    <select id="patternSelect" onchange="updatePattern()">
                        <option value="custom">Custom Regex</option>
                        <option value="mtk" selected>7-digit before MTK# (Default)</option>
                        <option value="aadhaar">Aadhaar (12-digit)</option>
                        <option value="pan">PAN Card (XXXXX1234X)</option>
                        <option value="invoice">Invoice No. (INV-XXXX)</option>
                        <option value="8digit">8-digit Number</option>
                        <option value="alphanumeric">Alphanumeric (A-Z + digits)</option>
                    </select>

                    <label for="customRegex">Custom Regex Pattern:</label>
                    <input id="customRegex" type="text" placeholder="e.g., \d{7}(?=\s+MTK#)" value="(\d{7})(?=\s+MTK#)"/>

                    <label for="ocrLang">OCR Language:</label>
                    <select id="ocrLang">
                        <option value="eng" selected>English</option>
                        <option value="hin">Hindi</option>
                        <option value="ben">Bengali</option>
                        <option value="tam">Tamil</option>
                        <option value="tel">Telugu</option>
                    </select>

                    <label style="margin-top: 15px;">
                        <input type="checkbox" id="preprocessImage" checked/> 
                        Enable Image Preprocessing (Better OCR)
                    </label>

                    <label>
                        <input type="checkbox" id="showConfidence" checked/> 
                        Show Confidence Scores
                    </label>
                </div>

                <div class="card">
                    <h3>📁 Upload Images</h3>
                    <div class="file-drop-zone" id="dropZone">
                        <p style="font-size: 16px;">🖼️ Drag & Drop images here</p>
                        <p style="margin: 5px 0; color: var(--text-secondary);">or click to browse</p>
                        <input id="images" type="file" accept="image/*" multiple style="display:none"/>
                    </div>
                    <div id="fileList">No files selected</div>

                    <div class="btn-group">
                        <button id="startBtn" class="btn-primary" onclick="startExtraction()">▶️ Start Upload & Extraction</button>
                        <button id="pauseBtn" class="btn-warning" onclick="togglePause()" disabled>⏸️ Pause</button>
                        <button id="cancelBtn" class="btn-danger" onclick="cancelExtraction()" disabled>⏹️ Cancel</button>
                        <button class="btn-info" onclick="showHistory()">📜 History</button>
                    </div>

                    <div class="progress-container" id="progressContainer">
                        <div class="progress-bar" id="progressBar">0%</div>
                    </div>
                </div>

                <div class="card">
                    <h3>📊 Statistics</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value" id="totalImages">0</div>
                            <div class="stat-label">Total Images</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="processedImages">0</div>
                            <div class="stat-label">Processed</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="foundRecords">0</div>
                            <div class="stat-label">Records Found</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="duplicates">0</div>
                            <div class="stat-label">Duplicates</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="successRate">0%</div>
                            <div class="stat-label">Success Rate</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3>📋 Process Log</h3>
                    <button class="btn-secondary" onclick="clearLog()" style="margin-bottom: 10px;">Clear Log</button>
                    <div id="log"></div>
                </div>

                <div class="card">
                    <h3>📑 Results</h3>
                    
                    <div class="btn-group">
                        <button class="btn-success" onclick="downloadExcel()" disabled id="downloadExcelBtn">📥 Download Excel</button>
                        <button class="btn-success" onclick="downloadCSV()" disabled id="downloadCSVBtn">📥 Download CSV</button>
                        <button class="btn-success" onclick="importToDB()" disabled id="importDBBtn">☁️ Import to Database</button>
                        <button class="btn-info" onclick="copyToClipboard()" disabled id="copyBtn">📋 Copy to Clipboard</button>
                        <button class="btn-warning" onclick="showSummary()" disabled id="summaryBtn">📊 Summary Report</button>
                    </div>

                    <div style="margin: 15px 0; display: flex; gap: 10px; flex-wrap: wrap;">
                        <input type="text" id="searchBox" placeholder="🔍 Search records..." onkeyup="filterTable()" style="flex: 1; min-width: 200px;"/>
                        <select id="sortBy" onchange="sortTable()" style="flex: 0 0 200px;">
                            <option value="">Sort by...</option>
                            <option value="name-asc">Image Name (A-Z)</option>
                            <option value="name-desc">Image Name (Z-A)</option>
                            <option value="record-asc">Record No (Ascending)</option>
                            <option value="record-desc">Record No (Descending)</option>
                            <option value="confidence-desc">Confidence (High to Low)</option>
                        </select>
                    </div>

                    <div style="overflow-x: auto;">
                        <table id="resultTable" style="display:none;">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Image Name</th>
                                    <th>Record No.</th>
                                    <th>Confidence</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div id="imageModal" class="modal" onclick="closeModal()">
                <div class="modal-content" onclick="event.stopPropagation()">
                    <span class="modal-close" onclick="closeModal()">&times;</span>
                    <h3 id="modalTitle">Image Preview</h3>
                    <img id="modalImage" class="image-preview" src="" alt="Preview"/>
                    <div id="modalInfo"></div>
                </div>
            </div>

            <div id="historyModal" class="modal" onclick="closeHistoryModal()">
                <div class="modal-content" onclick="event.stopPropagation()">
                    <span class="modal-close" onclick="closeHistoryModal()">&times;</span>
                    <h3>📜 Extraction History</h3>
                    <button class="btn-danger" onclick="clearHistory()" style="margin-bottom: 15px;">Clear All History</button>
                    <div id="historyList"></div>
                </div>
            </div>

            <div id="summaryModal" class="modal" onclick="closeSummaryModal()">
                <div class="modal-content" onclick="event.stopPropagation()">
                    <span class="modal-close" onclick="closeSummaryModal()">&times;</span>
                    <h3>📊 Extraction Summary Report</h3>
                    <div id="summaryContent"></div>
                    <button class="btn-primary" onclick="printSummary()" style="margin-top: 15px;">🖨️ Print Report</button>
                </div>
            </div>

            <script>
                // --- OCR LOGIC ---
                let rows = [];
                let allFiles = [];
                let isPaused = false;
                let isCancelled = false;
                let currentFileIndex = 0;
                let processedCount = 0;
                let imageCache = {};

                const patterns = {
                    mtk: { regex: '(\\d{7})(?=\\s+MTK#)', desc: '7-digit before MTK#' },
                    aadhaar: { regex: '\\b\\d{4}\\s?\\d{4}\\s?\\d{4}\\b', desc: 'Aadhaar 12-digit' },
                    pan: { regex: '\\b[A-Z]{5}\\d{4}[A-Z]\\b', desc: 'PAN Card format' },
                    invoice: { regex: 'INV-\\d{4,}', desc: 'Invoice number' },
                    '8digit': { regex: '\\b\\d{8}\\b', desc: '8-digit number' },
                    alphanumeric: { regex: '\\b[A-Z0-9]{6,12}\\b', desc: 'Alphanumeric 6-12 chars' }
                };

                document.addEventListener('DOMContentLoaded', () => {
                    if(document.getElementById('dropZone')) {
                        setupDropZone();
                        setupKeyboardShortcuts();
                        updatePattern();
                    }
                });

                function updatePattern() {
                    const select = document.getElementById('patternSelect');
                    const input = document.getElementById('customRegex');
                    if (select.value === 'custom') {
                        input.disabled = false;
                    } else {
                        input.disabled = true;
                        input.value = patterns[select.value].regex;
                    }
                }

                function setupDropZone() {
                    const dropZone = document.getElementById('dropZone');
                    const fileInput = document.getElementById('images');

                    dropZone.addEventListener('click', () => fileInput.click());

                    dropZone.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        dropZone.classList.add('drag-over');
                    });

                    dropZone.addEventListener('dragleave', () => {
                        dropZone.classList.remove('drag-over');
                    });

                    dropZone.addEventListener('drop', (e) => {
                        e.preventDefault();
                        dropZone.classList.remove('drag-over');
                        const files = e.dataTransfer.files;
                        fileInput.files = files;
                        updateFileList(files);
                    });

                    fileInput.addEventListener('change', (e) => {
                        updateFileList(e.target.files);
                    });
                }

                function updateFileList(files) {
                    allFiles = Array.from(files);
                    const list = document.getElementById('fileList');
                    if (files.length === 0) {
                        list.innerHTML = 'No files selected';
                        document.getElementById('totalImages').textContent = '0';
                        return;
                    }
                    const fileNames = allFiles.slice(0, 3).map(f => f.name).join(', ');
                    const remaining = allFiles.length > 3 ? ` and ${allFiles.length - 3} more...` : '';
                    list.innerHTML = `✅ ${files.length} file(s) selected: ${fileNames}${remaining}`;
                    document.getElementById('totalImages').textContent = files.length;
                }

                function appendLog(text, type = 'info') {
                    const log = document.getElementById('log');
                    const entryDiv = document.createElement('div');
                    entryDiv.className = `log-entry`;
                    const now = new Date();
                    const timeString = now.toLocaleTimeString([], { hour12: true, hour: '2-digit', minute:'2-digit', second:'2-digit' });
                    let icon = 'ℹ️';
                    if (type === 'success') icon = '✅';
                    if (type === 'error') icon = '❌';
                    if (type === 'warning') icon = '⚠️';
                    entryDiv.innerHTML = `<span class="log-time">[${timeString}]</span><span class="log-msg log-type-${type}">${icon} ${text}</span>`;
                    log.appendChild(entryDiv);
                    log.scrollTop = log.scrollHeight;
                }

                function clearLog() {
                    const log = document.getElementById('log');
                    log.innerHTML = ''; 
                    appendLog('Log cleared and ready.', 'info');
                }

                // New function to upload image to server
                async function uploadImageToServer(file) {
                    const formData = new FormData();
                    formData.append('ocr_image_upload', file);
                    try {
                        const response = await fetch('p2_admin_dashboard.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        return result.success;
                    } catch (error) {
                        console.error("Upload error", error);
                        return false;
                    }
                }

                async function preprocessImage(file) {
                    return new Promise((resolve) => {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            const img = new Image();
                            img.onload = () => {
                                const canvas = document.createElement('canvas');
                                const ctx = canvas.getContext('2d');
                                canvas.width = img.width;
                                canvas.height = img.height;
                                ctx.drawImage(img, 0, 0);
                                const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                                const data = imageData.data;
                                for (let i = 0; i < data.length; i += 4) {
                                    data[i] = Math.min(255, data[i] * 1.2);
                                    data[i + 1] = Math.min(255, data[i + 1] * 1.2);
                                    data[i + 2] = Math.min(255, data[i + 2] * 1.2);
                                    const factor = 1.5;
                                    data[i] = Math.min(255, Math.max(0, ((data[i] - 128) * factor) + 128));
                                    data[i + 1] = Math.min(255, Math.max(0, ((data[i + 1] - 128) * factor) + 128));
                                    data[i + 2] = Math.min(255, Math.max(0, ((data[i + 2] - 128) * factor) + 128));
                                }
                                ctx.putImageData(imageData, 0, 0);
                                canvas.toBlob(resolve, 'image/png');
                            };
                            img.src = e.target.result;
                        };
                        reader.readAsDataURL(file);
                    });
                }

                async function startExtraction() {
                    if (allFiles.length === 0) {
                        alert('⚠️ Please select at least one image!');
                        return;
                    }
                    rows = [];
                    processedCount = 0;
                    currentFileIndex = 0;
                    isPaused = false;
                    isCancelled = false;
                    imageCache = {};

                    document.getElementById('tableBody').innerHTML = '';
                    document.getElementById('resultTable').style.display = 'none';
                    document.getElementById('progressContainer').style.display = 'block';
                    document.getElementById('startBtn').disabled = true;
                    document.getElementById('pauseBtn').disabled = false;
                    document.getElementById('cancelBtn').disabled = false;
                    disableExportButtons();
                    clearLog();
                    appendLog(`🚀 Starting extraction & upload for ${allFiles.length} images...`);

                    const usePreprocess = document.getElementById('preprocessImage').checked;
                    const lang = document.getElementById('ocrLang').value;
                    const regexPattern = document.getElementById('customRegex').value;

                    for (let i = 0; i < allFiles.length; i++) {
                        if (isCancelled) {
                            appendLog('⛔ Extraction cancelled by user', 'error');
                            break;
                        }
                        while (isPaused) {
                            await new Promise(resolve => setTimeout(resolve, 100));
                        }
                        currentFileIndex = i;
                        const file = allFiles[i];
                        updateProgress(i + 1, allFiles.length);
                        appendLog(`Processing: ${file.name}`);

                        const startTime = performance.now();

                        try {
                            // 1. Upload to Server
                            const uploaded = await uploadImageToServer(file);
                            if(uploaded) appendLog(`☁️ Uploaded: ${file.name}`, 'info');
                            else appendLog(`⚠️ Upload failed: ${file.name}`, 'warning');

                            // 2. Read locally for OCR
                            const reader = new FileReader();
                            await new Promise((resolve) => {
                                reader.onload = () => {
                                    imageCache[file.name] = reader.result;
                                    resolve();
                                };
                                reader.readAsDataURL(file);
                            });

                            let processFile = file;
                            if (usePreprocess) {
                                processFile = await preprocessImage(file);
                            }

                            const { data } = await Tesseract.recognize(processFile, lang, {
                                logger: () => {} 
                            });

                            const text = data.text || '';
                            const confidence = Math.round(data.confidence);

                            const endTime = performance.now();
                            const timeTaken = ((endTime - startTime) / 1000).toFixed(2); 

                            const regex = new RegExp(regexPattern, 'g');
                            const found = [];
                            let match;
                            while ((match = regex.exec(text)) !== null) {
                                found.push(match[1] || match[0]);
                            }

                            if (found.length === 0) {
                                appendLog(`No records found in ${file.name} [${timeTaken}s]`, 'warning');
                            } else {
                                appendLog(`Found ${found.length} record(s) in ${timeTaken}s: ${found.join(', ')}`, 'success');
                                const baseName = file.name.replace(/\.[^.]+$/, '');
                                found.forEach(rec => {
                                    rows.push({
                                        imageName: baseName,
                                        recordNo: rec,
                                        confidence: confidence,
                                        fileName: file.name
                                    });
                                });
                            }
                            processedCount++;
                            updateStats();
                        } catch (err) {
                            const endTime = performance.now();
                            const timeTaken = ((endTime - startTime) / 1000).toFixed(2);
                            appendLog(`ERROR in ${file.name} [${timeTaken}s]: ${err.message}`, 'error');
                            console.error(err);
                        }
                    }

                    if (!isCancelled) {
                        appendLog('🎉 Extraction complete!', 'success');
                        detectDuplicates();
                        renderTable();
                        saveToHistory();
                        enableExportButtons();
                    }
                    resetButtons();
                }

                function updateProgress(current, total) {
                    const percent = Math.round((current / total) * 100);
                    const bar = document.getElementById('progressBar');
                    bar.style.width = percent + '%';
                    bar.textContent = `${current}/${total} (${percent}%)`;
                    document.getElementById('processedImages').textContent = current;
                }

                function updateStats() {
                    document.getElementById('foundRecords').textContent = rows.length;
                    const rate = allFiles.length > 0 ? Math.round((processedCount / allFiles.length) * 100) : 0;
                    document.getElementById('successRate').textContent = rate + '%';
                }

                function detectDuplicates() {
                    const seen = {};
                    let dupCount = 0;
                    rows.forEach(row => {
                        if (seen[row.recordNo]) {
                            row.isDuplicate = true;
                            dupCount++;
                        } else {
                            seen[row.recordNo] = true;
                            row.isDuplicate = false;
                        }
                    });
                    document.getElementById('duplicates').textContent = dupCount;
                    if (dupCount > 1) {
                        appendLog(`Found ${dupCount} duplicate records`, 'warning');
                    }
                }

                function togglePause() {
                    isPaused = !isPaused;
                    const btn = document.getElementById('pauseBtn');
                    btn.textContent = isPaused ? '▶️ Resume' : '⏸️ Pause';
                    appendLog(isPaused ? '⏸️ Extraction paused' : '▶️ Extraction resumed');
                }

                function cancelExtraction() {
                    if (confirm('❓ Are you sure you want to cancel the extraction?')) {
                        isCancelled = true;
                        document.getElementById('cancelBtn').disabled = true;
                        appendLog('⛔ Cancellation requested...', 'error');
                    }
                }

                function resetButtons() {
                    document.getElementById('startBtn').disabled = false;
                    document.getElementById('pauseBtn').disabled = true;
                    document.getElementById('cancelBtn').disabled = true;
                    document.getElementById('progressContainer').style.display = 'none';
                }

                function disableExportButtons() {
                    document.getElementById('downloadExcelBtn').disabled = true;
                    document.getElementById('downloadCSVBtn').disabled = true;
                    document.getElementById('importDBBtn').disabled = true;
                    document.getElementById('copyBtn').disabled = true;
                    document.getElementById('summaryBtn').disabled = true;
                }

                function enableExportButtons() {
                    if (rows.length > 0) {
                        document.getElementById('downloadExcelBtn').disabled = false;
                        document.getElementById('downloadCSVBtn').disabled = false;
                        document.getElementById('importDBBtn').disabled = false;
                        document.getElementById('copyBtn').disabled = false;
                        document.getElementById('summaryBtn').disabled = false;
                    }
                }

                function renderTable() {
                    const tbody = document.getElementById('tableBody');
                    tbody.innerHTML = '';
                    if (rows.length === 0) {
                        document.getElementById('resultTable').style.display = 'none';
                        appendLog('ℹ️ No records to display');
                        return;
                    }
                    document.getElementById('resultTable').style.display = '';
                    const showConf = document.getElementById('showConfidence').checked;
                    rows.forEach((row, idx) => {
                        const tr = document.createElement('tr');
                        if (row.isDuplicate) tr.classList.add('duplicate');
                        tr.innerHTML = `
                            <td>${idx + 1}</td>
                            <td>${escapeHtml(row.imageName)}</td>
                            <td class="editable" contenteditable="true" data-idx="${idx}">${escapeHtml(row.recordNo)}</td>
                            <td class="${getConfidenceClass(row.confidence)}">${showConf ? row.confidence + '%' : 'N/A'}</td>
                            <td>${row.isDuplicate ? '<span class="badge danger">Duplicate</span>' : '<span class="badge success">Unique</span>'}</td>
                            <td><button class="btn-info" onclick="viewImage('${escapeHtml(row.fileName)}')" style="padding: 5px 10px; font-size: 12px;">👁️ View</button></td>
                        `;
                        tbody.appendChild(tr);
                    });
                    
                    tbody.querySelectorAll('.editable').forEach(el => {
                        el.addEventListener('blur', function() {
                            const idx = parseInt(this.dataset.idx);
                            const newValue = this.textContent.trim();
                            if (newValue && newValue !== rows[idx].recordNo) {
                                rows[idx].recordNo = newValue;
                                detectDuplicates();
                                renderTable();
                                appendLog(`✏️ Record #${idx + 1} updated manually`);
                            }
                        });
                        el.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
                        });
                    });
                }

                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                function getConfidenceClass(conf) {
                    if (conf >= 80) return 'confidence-high';
                    if (conf >= 60) return 'confidence-medium';
                    return 'confidence-low';
                }

                function filterTable() {
                    const search = document.getElementById('searchBox').value.toLowerCase();
                    const tbody = document.getElementById('tableBody');
                    const tableRows = tbody.getElementsByTagName('tr');
                    for (let row of tableRows) {
                        const text = row.textContent.toLowerCase();
                        if (text.includes(search)) { row.style.display = ''; }
                        else { row.style.display = 'none'; }
                    }
                }

                function sortTable() {
                    const sortBy = document.getElementById('sortBy').value;
                    if (!sortBy) return;
                    const [field, order] = sortBy.split('-');
                    rows.sort((a, b) => {
                        let valA, valB;
                        if (field === 'name') { valA = a.imageName.toLowerCase(); valB = b.imageName.toLowerCase(); }
                        else if (field === 'record') { valA = a.recordNo.toString(); valB = b.recordNo.toString(); }
                        else if (field === 'confidence') { valA = a.confidence; valB = b.confidence; }
                        return (order === 'asc') ? (valA > valB ? 1 : -1) : (valA < valB ? 1 : -1);
                    });
                    renderTable();
                }

                function importToDB() {
                    if (rows.length === 0) return alert('No data to import!');
                    
                    const btn = document.getElementById('importDBBtn');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
                    
                    const formData = new FormData();
                    formData.append('ajax_import_mapping', '1');
                    formData.append('mapping_data', JSON.stringify(rows));
                    
                    fetch('p2_admin_dashboard.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(res => {
                        alert(res.message);
                        if(res.success) appendLog('✅ Database Import: ' + res.message, 'success');
                        else appendLog('❌ Database Import Failed: ' + res.message, 'error');
                    })
                    .catch(e => {
                        alert('Error importing: ' + e);
                        appendLog('❌ Error importing: ' + e, 'error');
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerHTML = '☁️ Import to Database';
                    });
                }

                function downloadExcel() {
                    if (rows.length === 0) return alert('⚠️ No data to export!');
                    const wsData = [['#', 'Image Name', 'Record No.', 'Confidence', 'Status']];
                    rows.forEach((r, i) => wsData.push([i + 1, r.imageName, r.recordNo, r.confidence + '%', r.isDuplicate ? 'Duplicate' : 'Unique']));
                    const ws = XLSX.utils.aoa_to_sheet(wsData);
                    const wb = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(wb, ws, 'Records');
                    XLSX.writeFile(wb, `records_${Date.now()}.xlsx`);
                    appendLog('📥 Excel downloaded', 'success');
                }

                function downloadCSV() {
                    if (rows.length === 0) return alert('⚠️ No data to export!');
                    let csv = 'Image Name,Record No.,Confidence,Status\n';
                    rows.forEach(r => csv += `"${r.imageName}","${r.recordNo}",${r.confidence}%,${r.isDuplicate ? 'Duplicate' : 'Unique'}\n`);
                    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `records_${Date.now()}.csv`;
                    a.click();
                    URL.revokeObjectURL(url);
                    appendLog('📥 CSV downloaded', 'success');
                }

                function copyToClipboard() {
                    if (rows.length === 0) return alert('⚠️ No data!');
                    let text = 'Image Name\tRecord No.\tConfidence\tStatus\n';
                    rows.forEach(r => text += `${r.imageName}\t${r.recordNo}\t${r.confidence}%\t${r.isDuplicate ? 'Duplicate' : 'Unique'}\n`);
                    navigator.clipboard.writeText(text).then(() => alert('✅ Copied!')).catch(e => alert('Failed to copy'));
                }

                function viewImage(fileName) {
                    const modal = document.getElementById('imageModal');
                    document.getElementById('modalImage').src = imageCache[fileName] || '';
                    document.getElementById('modalTitle').textContent = fileName;
                    const record = rows.find(r => r.fileName === fileName);
                    if (record) {
                        document.getElementById('modalInfo').innerHTML = `<p><strong>Record No:</strong> ${escapeHtml(record.recordNo)}</p>`;
                    }
                    modal.style.display = 'block';
                }

                function closeModal() { document.getElementById('imageModal').style.display = 'none'; }
                
                function saveToHistory() {
                    try {
                        const history = JSON.parse(localStorage.getItem('extractionHistory') || '[]');
                        const entry = { timestamp: new Date().toISOString(), totalImages: allFiles.length, recordsFound: rows.length, duplicates: rows.filter(r => r.isDuplicate).length, successRate: Math.round((processedCount / allFiles.length) * 100), data: rows.map(r => ({ imageName: r.imageName, recordNo: r.recordNo, confidence: r.confidence, isDuplicate: r.isDuplicate })) };
                        history.unshift(entry);
                        if (history.length > 10) history.pop();
                        localStorage.setItem('extractionHistory', JSON.stringify(history));
                        appendLog('💾 Saved to history', 'success');
                    } catch(e) { console.error(e); }
                }
                function showHistory() {
                    const history = JSON.parse(localStorage.getItem('extractionHistory') || '[]');
                    const list = document.getElementById('historyList');
                    if (history.length === 0) list.innerHTML = '<p style="text-align:center;color:#666;">No history.</p>';
                    else list.innerHTML = history.map((e, i) => `<div class="history-item" onclick="loadHistoryEntry(${i})"><strong>📅 ${new Date(e.timestamp).toLocaleString()}</strong><br><small>Recs: ${e.recordsFound} | Success: ${e.successRate}%</small></div>`).join('');
                    document.getElementById('historyModal').style.display = 'block';
                }
                function loadHistoryEntry(i) {
                    const history = JSON.parse(localStorage.getItem('extractionHistory') || '[]');
                    if (history[i]) {
                        rows = history[i].data || [];
                        processedCount = history[i].totalImages || 0;
                        updateStats();
                        renderTable();
                        closeHistoryModal();
                        enableExportButtons();
                        appendLog('📂 Loaded history', 'success');
                    }
                }
                function clearHistory() {
                    if(confirm('Clear all history?')) { localStorage.removeItem('extractionHistory'); closeHistoryModal(); appendLog('🗑️ History cleared', 'success'); }
                }
                function closeHistoryModal() { document.getElementById('historyModal').style.display = 'none'; }

                function showSummary() {
                    const unique = rows.filter(r => !r.isDuplicate).length;
                    const avgConf = rows.length > 0 ? Math.round(rows.reduce((s, r) => s + r.confidence, 0) / rows.length) : 0;
                    document.getElementById('summaryContent').innerHTML = `<p>Total: ${rows.length}</p><p>Unique: ${unique}</p><p>Avg Conf: ${avgConf}%</p>`;
                    document.getElementById('summaryModal').style.display = 'block';
                }
                function closeSummaryModal() { document.getElementById('summaryModal').style.display = 'none'; }
                function printSummary() { window.print(); }

                function setupKeyboardShortcuts() {
                    document.addEventListener('keydown', (e) => {
                        if (e.key === 'Escape') { closeModal(); closeHistoryModal(); closeSummaryModal(); }
                    });
                }
            </script>
        
        <?php elseif ($active_tab == 'users'): ?>
            <!-- ... existing users content ... -->
            <div class="section">
                <h2>👥 User Management</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;" class="user-mgmt-grid">
                    <div style="background: #f8fafc; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <h3 style="font-size: 1rem; margin-bottom: 1rem;">Create New User</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" name="new_username" required>
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="text" name="new_password" required>
                            </div>
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="new_fullname" required>
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                            <select name="new_role" required>
                                    <option value="deo">DEO</option>
                                    <option value="dqc">DQC</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <button type="submit" name="add_user" style="width: 100%;">➕ Create User</button>
                        </form>
                    </div>

                    <div style="overflow-x: auto;">
                        <h3 style="font-size: 1rem; margin-bottom: 1rem;">All Users</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>User Details</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($all_users_result->num_rows > 0): ?>
                                    <?php while ($u = $all_users_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                                <div style="font-size: 0.8rem; color: var(--secondary);">@<?php echo htmlspecialchars($u['username']); ?></div>
                                            </td>
                                            <td>
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; background: #e0e7ff; color: #4338ca; text-transform: uppercase; font-weight: 700;">
                                                    <?php echo $u['role']; ?>
                                                </span>
                                            </td>
                                            <td style="display:flex; gap:5px;">
                                                <button class="btn-info" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" 
                                                        onclick='openEditModal(<?php echo json_encode($u); ?>)'>
                                                    ✏️
                                                </button>
                                                <?php if ($u['id'] != $user['id']): ?>
                                                    <form method="POST" onsubmit="return confirm('Delete this user?');">
                                                        <input type="hidden" name="delete_user" value="<?php echo $u['id']; ?>">
                                                        <button type="submit" class="btn-danger">🗑️</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div id="editUserModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeEditModal()">&times;</span>
                    <h3>Edit User</h3>
                    <form method="POST">
                        <input type="hidden" name="edit_user_id" id="edit_user_id">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="edit_username" id="edit_username" required>
                        </div>
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="edit_fullname" id="edit_fullname" required>
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <select name="edit_role" id="edit_role" required>
                                <option value="deo">DEO</option>
                                <option value="dqc">DQC</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>New Password (leave blank to keep current)</label>
                            <input type="text" name="edit_password" placeholder="New Password">
                        </div>
                        <button type="submit" name="edit_user_submit" style="width: 100%;">💾 Save Changes</button>
                    </form>
                </div>
            </div>
            
            <script>
                function openEditModal(user) {
                    document.getElementById('edit_user_id').value = user.id;
                    document.getElementById('edit_username').value = user.username;
                    document.getElementById('edit_fullname').value = user.full_name;
                    document.getElementById('edit_role').value = user.role;
                    document.getElementById('editUserModal').style.display = 'block';
                }
                function closeEditModal() {
                    document.getElementById('editUserModal').style.display = 'none';
                }
            </script>

        <!-- ============= NEW FEATURE TABS ============= -->

        <?php endif; ?>
        <!-- ============= END NEW FEATURE TABS ============= -->

    </div>
    
    

    <!-- Deep Search Modal for Image Zoom with Zoom In/Out Controls -->
    <div id="zoomModal" class="modal" onclick="closeZoomModal()">
        <div class="modal-content" style="max-width:95%; max-height:95vh; text-align:center; background:rgba(0,0,0,0.9); border:none; padding: 10px; position: relative;" onclick="event.stopPropagation()">
            <span class="modal-close" onclick="closeZoomModal()" style="color:white; position:absolute; top:10px; right:20px; font-size:40px; z-index: 1001;">&times;</span>
            
            <!-- Zoom Controls -->
            <div id="zoomControls" style="position: absolute; top: 15px; left: 50%; transform: translateX(-50%); display: flex; gap: 10px; z-index: 1000; background: rgba(255,255,255,0.95); padding: 8px 15px; border-radius: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);">
                <button onclick="zoomOut()" style="background: #ef4444; color: white; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; font-size: 18px; font-weight: bold; display: flex; align-items: center; justify-content: center;" title="Zoom Out">
                    <i class="fas fa-minus"></i>
                </button>
                <span id="zoomLevelDisplay" style="display: flex; align-items: center; font-weight: 700; color: #1e293b; min-width: 60px; justify-content: center;">100%</span>
                <button onclick="zoomIn()" style="background: #10b981; color: white; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; font-size: 18px; font-weight: bold; display: flex; align-items: center; justify-content: center;" title="Zoom In">
                    <i class="fas fa-plus"></i>
                </button>
                <button onclick="resetZoom()" style="background: #6366f1; color: white; border: none; padding: 0 15px; height: 40px; border-radius: 20px; cursor: pointer; font-size: 13px; font-weight: 600;" title="Reset Zoom">
                    <i class="fas fa-undo"></i> Reset
                </button>
                <button onclick="toggleFullImage()" style="background: #f59e0b; color: white; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; font-size: 16px;" title="Fit to Screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
            
            <!-- Image Container with Scroll -->
            <div id="imageScrollContainer" style="overflow: auto; max-height: calc(95vh - 80px); margin-top: 60px; cursor: grab;">
                <img id="zoomImageContent" src="" style="transition: transform 0.2s ease; transform-origin: center center;">
            </div>
        </div>
    </div>
    
    <script>
    // Zoom functionality for Deep Search Image
    let currentZoom = 100;
    const minZoom = 25;
    const maxZoom = 400;
    const zoomStep = 25;
    
    function openZoomModal(src) {
        document.getElementById('zoomModal').style.display = 'flex';
        document.getElementById('zoomImageContent').src = src;
        currentZoom = 100;
        updateZoomDisplay();
        document.body.style.overflow = 'hidden';
    }
    
    function closeZoomModal() {
        document.getElementById('zoomModal').style.display = 'none';
        document.body.style.overflow = 'auto';
        resetZoom();
    }
    
    function zoomIn() {
        if (currentZoom < maxZoom) {
            currentZoom += zoomStep;
            updateZoomDisplay();
        }
    }
    
    function zoomOut() {
        if (currentZoom > minZoom) {
            currentZoom -= zoomStep;
            updateZoomDisplay();
        }
    }
    
    function resetZoom() {
        currentZoom = 100;
        updateZoomDisplay();
    }
    
    function toggleFullImage() {
        const img = document.getElementById('zoomImageContent');
        const container = document.getElementById('imageScrollContainer');
        
        if (currentZoom !== 100) {
            resetZoom();
        } else {
            // Calculate zoom to fit width
            currentZoom = Math.min(200, Math.round((container.clientWidth / img.naturalWidth) * 100));
            updateZoomDisplay();
        }
    }
    
    function updateZoomDisplay() {
        const img = document.getElementById('zoomImageContent');
        img.style.transform = `scale(${currentZoom / 100})`;
        document.getElementById('zoomLevelDisplay').textContent = currentZoom + '%';
        
        // Update button states
        const zoomInBtn = document.querySelector('#zoomControls button:nth-child(3)');
        const zoomOutBtn = document.querySelector('#zoomControls button:nth-child(1)');
        
        if (zoomInBtn) zoomInBtn.style.opacity = currentZoom >= maxZoom ? '0.5' : '1';
        if (zoomOutBtn) zoomOutBtn.style.opacity = currentZoom <= minZoom ? '0.5' : '1';
    }
    
    // Mouse wheel zoom
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('imageScrollContainer');
        if (container) {
            container.addEventListener('wheel', function(e) {
                if (document.getElementById('zoomModal').style.display === 'flex') {
                    e.preventDefault();
                    if (e.deltaY < 0) {
                        zoomIn();
                    } else {
                        zoomOut();
                    }
                }
            }, { passive: false });
        }
    });
    </script>

    <script>
        // ... existing helper functions (updateFileName, toggleDarkMode, etc.) ...
        function updateFileName(input) {
            document.getElementById('fileName').textContent = input.files[0] ? input.files[0].name : 'No file selected';
        }
        function toggleDarkMode() { document.body.classList.toggle('dark-mode'); const icon = document.getElementById('darkModeIcon'); if(document.body.classList.contains('dark-mode')) { icon.className = 'fas fa-sun'; localStorage.setItem('darkMode', 'enabled'); } else { icon.className = 'fas fa-moon'; localStorage.setItem('darkMode', 'disabled'); } }
        if(localStorage.getItem('darkMode') === 'enabled') { document.body.classList.add('dark-mode'); document.getElementById('darkModeIcon').className = 'fas fa-sun'; }
        function showToast(message, type = 'info') { const toast = document.getElementById('toast'); const toastMessage = document.getElementById('toastMessage'); toastMessage.textContent = message; toast.className = 'toast show ' + type; setTimeout(() => { toast.classList.remove('show'); }, 3000); }
        function exportReportsCSV() {
    var deoId    = document.querySelector('select[name="filter_error_deo_id"]') ? document.querySelector('select[name="filter_error_deo_id"]').value : '';
    var dateVal  = document.querySelector('input[name="filter_error_date"]')    ? document.querySelector('input[name="filter_error_date"]').value    : '';
    var statusVal= document.querySelector('select[name="filter_status"]')       ? document.querySelector('select[name="filter_status"]').value       : '';
    var sourceVal= document.querySelector('select[name="filter_source"]')       ? document.querySelector('select[name="filter_source"]').value       : '';

    var body = 'action=export_p2_reports'
             + '&ex_deo='    + encodeURIComponent(deoId)
             + '&ex_date='   + encodeURIComponent(dateVal)
             + '&ex_status=' + encodeURIComponent(statusVal)
             + '&ex_source=' + encodeURIComponent(sourceVal);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        if (xhr.status === 0) { alert('Export failed: Server se connection nahi hua (network error).'); return; }
        if (xhr.status !== 200) { alert('Export failed (HTTP ' + xhr.status + ').\nResponse: ' + xhr.responseText.substring(0,100)); return; }
        try {
            var raw = xhr.responseText.trim();
            if (!raw) { alert('Export: Server ne empty response bheja. Page refresh karke try karo.'); return; }
            // Strip any HTML/whitespace before JSON
            var jsonStart = raw.indexOf('{');
            if (jsonStart === -1) { alert('Export: JSON nahi mila.\nServer response: ' + raw.substring(0,300)); return; }
            if (jsonStart > 0) raw = raw.substring(jsonStart);
            var res = JSON.parse(raw);
            if (res.status !== 'success') { alert('Export Error: ' + (res.message || 'Failed')); return; }
            if (!res.rows || res.rows.length === 0) { alert('Koi data nahi mila. Reports table empty ya filter se koi match nahi.'); return; }
            var hdr = ['Error_ID','Record No','Image No','Source','Header Field','Issue Details','Reporter Name','Role','Status','Admin_Reply','Reported Date'];
            var csv = [hdr].concat(res.rows).map(function(r){
                return r.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
            }).join('\n');
            var blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8;'});
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'Reports_Export_'+new Date().toISOString().slice(0,10)+'.csv';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a);
            setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
        } catch(e) {
            alert('Export parse error: ' + e.message + '\nResponse: ' + xhr.responseText.substring(0,400));
        }
    };
    xhr.send(body);
}
function searchErrors() { const searchTerm = document.getElementById('errorSearch').value.toLowerCase(); const errorItems = document.querySelectorAll('.error-item'); errorItems.forEach(item => { const record = item.dataset.record.toLowerCase(); const deo = item.dataset.deo.toLowerCase(); const details = item.dataset.details.toLowerCase(); if(record.includes(searchTerm) || deo.includes(searchTerm) || details.includes(searchTerm)) { item.style.display = 'block'; } else { item.style.display = 'none'; } }); }
        function toggleQuickReply(btn) { const dropdown = btn.nextElementSibling; dropdown.classList.toggle('active'); }
        function selectQuickReply(item, text) { const input = item.closest('form').querySelector('.remark-input'); input.value = text; item.closest('.quick-reply-dropdown').classList.remove('active'); }
        function hideQuickReplies(input) { setTimeout(() => { const dropdown = input.nextElementSibling.nextElementSibling; if(dropdown) dropdown.classList.remove('active'); }, 200); }
        function updateBulkActions() { const checkboxes = document.querySelectorAll('.error-checkbox:checked'); const bulkBar = document.getElementById('bulkActionsBar'); const selectedCount = document.getElementById('selectedCount'); if(checkboxes.length > 0) { bulkBar.classList.add('show'); selectedCount.textContent = checkboxes.length + ' selected'; const form = document.getElementById('bulkApproveForm'); form.querySelectorAll('input[name="error_ids[]"]').forEach(el => el.remove()); checkboxes.forEach(cb => { const input = document.createElement('input'); input.type = 'hidden'; input.name = 'error_ids[]'; input.value = cb.value; form.appendChild(input); }); } else { bulkBar.classList.remove('show'); } }
        function clearSelection() { document.querySelectorAll('.error-checkbox').forEach(cb => cb.checked = false); updateBulkActions(); }
        
        // --- BULK REPLY VIA EXCEL FUNCTIONS ---
        function processBulkReply() {
            const fileInput = document.getElementById('bulkReplyFile');
            const statusDiv = document.getElementById('bulkReplyStatus');
            
            if (!fileInput.files || fileInput.files.length === 0) {
                statusDiv.innerHTML = '<span style="color:#ef4444;">❌ Please select an Excel file first!</span>';
                return;
            }
            
            // XLSX library check
            if (typeof XLSX === 'undefined') {
                statusDiv.innerHTML = '<span style="color:#ef4444;">❌ Excel library load nahi hui! Page refresh karein aur dobara try karein.</span>';
                return;
            }
            
            const file = fileInput.files[0];
            
            // File extension check
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['xlsx', 'xls'].includes(ext)) {
                statusDiv.innerHTML = '<span style="color:#ef4444;">❌ Sirf .xlsx ya .xls file allowed hai!</span>';
                return;
            }
            
            statusDiv.innerHTML = '<span style="color:#8b5cf6;"><i class="fas fa-spinner fa-spin"></i> Processing file...</span>';
            
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const sheetName = workbook.SheetNames[0];
                    const worksheet = workbook.Sheets[sheetName];
                    const jsonData = XLSX.utils.sheet_to_json(worksheet, { defval: '' });
                    
                    if (jsonData.length === 0) {
                        statusDiv.innerHTML = '<span style="color:#ef4444;">❌ No data found in Excel file!</span>';
                        return;
                    }
                    
                    // Validate columns (case-insensitive)
                    const firstRow = jsonData[0];
                    const keys = Object.keys(firstRow).map(k => k.toLowerCase().trim());
                    if (!keys.includes('error_id') || !keys.includes('admin_reply')) {
                        statusDiv.innerHTML = '<span style="color:#ef4444;">❌ Excel mein "Error_ID" aur "Admin_Reply" columns hone chahiye! Pehle Download Template karein.</span>';
                        return;
                    }
                    
                    // Filter only rows with Admin_Reply filled
                    const validData = jsonData.filter(row => {
                        const id = row['Error_ID'];
                        const reply = row['Admin_Reply'];
                        return id && parseInt(id) > 0 && reply && reply.toString().trim() !== '';
                    });
                    
                    if (validData.length === 0) {
                        statusDiv.innerHTML = '<span style="color:#f59e0b;">⚠️ Koi row nahi mila jisme Admin_Reply bhara ho! Template mein replies fill karein.</span>';
                        return;
                    }
                    
                    statusDiv.innerHTML = `<span style="color:#8b5cf6;"><i class="fas fa-spinner fa-spin"></i> Submitting ${validData.length} replies...</span>`;
                    
                    // Submit via form - action mein ?tab=dashboard taaki wapas sahi tab pe aaye
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.pathname + '?tab=dashboard';
                    form.style.display = 'none';
                    
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'bulk_reply_data';
                    input.value = JSON.stringify(validData);
                    form.appendChild(input);
                    
                    document.body.appendChild(form);
                    form.submit();
                    
                } catch (err) {
                    console.error('Bulk Reply Error:', err);
                    statusDiv.innerHTML = '<span style="color:#ef4444;">❌ Excel file padhne mein error: ' + err.message + '</span>';
                }
            };
            reader.onerror = function() {
                statusDiv.innerHTML = '<span style="color:#ef4444;">❌ File read nahi hui! Dobara try karein.</span>';
            };
            reader.readAsArrayBuffer(file);
        }
        function downloadBulkReplyTemplate() {
            // AJAX se real-time data fetch karo (PHP-baked nahi, taaki har baar fresh data mile)
            const statusDiv = document.getElementById('bulkReplyStatus');
            if (statusDiv) statusDiv.innerHTML = '<span style="color:#f59e0b;"><i class="fas fa-spinner fa-spin"></i> Template data fetch ho raha hai...</span>';
            
            fetch('?get_bulk_template=1')
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        const errMsg = res.error ? `DB Error: ${res.error}` : 'Server se data nahi mila.';
                        if (statusDiv) statusDiv.innerHTML = `<span style="color:#ef4444;">❌ ${errMsg}</span>`;
                        alert('Template fetch failed: ' + errMsg);
                        return;
                    }
                    
                    if (!res.rows || res.rows.length === 0) {
                        if (statusDiv) statusDiv.innerHTML = '<span style="color:#22c55e;">✅ Koi bhi pending error nahi hai abhi!</span>';
                        alert('✅ Abhi koi pending error nahi hai jisme Admin Reply bhari jaaye.');
                        return;
                    }
                    
                    if (statusDiv) statusDiv.innerHTML = `<span style="color:#22c55e;">✅ ${res.rows.length} pending errors mili. Template download ho raha hai...</span>`;
                    
                    const ws = XLSX.utils.json_to_sheet(res.rows);
                    
                    // Column widths set karo
                    ws['!cols'] = [
                        { wch: 10 },  // Error_ID
                        { wch: 12 },  // Record_No
                        { wch: 20 },  // Image_Name
                        { wch: 12 },  // Source
                        { wch: 22 },  // Reported_By
                        { wch: 20 },  // Error_Field
                        { wch: 40 },  // Error_Details
                        { wch: 40 }   // Admin_Reply
                    ];
                    
                    const wb = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(wb, ws, 'Pending_Errors');
                    XLSX.writeFile(wb, 'Bulk_Reply_Template_' + new Date().toISOString().slice(0,10) + '.xlsx');
                })
                .catch(err => {
                    console.error('Template fetch error:', err);
                    if (statusDiv) statusDiv.innerHTML = '<span style="color:#ef4444;">❌ Network error. Dobara try karein.</span>';
                    alert('Network error: ' + err.message);
                });
        }
        
        let notifOpen = false;
        function toggleNotifications() { const dropdown = document.getElementById('notifDropdown'); notifOpen = !notifOpen; if(notifOpen) { dropdown.classList.add('active'); loadNotifications(); } else { dropdown.classList.remove('active'); } }
        function loadNotifications() { fetch('?fetch_notifications=1').then(response => response.json()).then(data => { const container = document.getElementById('notifContainer'); if(data.length === 0) { container.innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--secondary);">No new notifications 🔭</div>'; return; } let html = ''; data.forEach(notif => { const icon = notif.type === 'success' ? '✅' : notif.type === 'warning' ? '⚠️' : 'ℹ️'; const timeAgo = getTimeAgo(notif.created_at); html += `<div class="notif-item ${notif.is_read == 0 ? 'unread' : ''}" onclick="markAsRead(${notif.id})"><div class="notif-message">${icon} ${notif.message}</div><div class="notif-time">${timeAgo}</div></div>`; }); container.innerHTML = html; }).catch(error => { console.error('Error loading notifications:', error); }); }
        function markAsRead(notifId) { fetch(`?mark_read=1&notif_id=${notifId}`).then(response => response.json()).then(data => { if(data.success) { loadNotifications(); showToast('Notification marked as read', 'success'); setTimeout(() => location.reload(), 1000); } }); }
        function getTimeAgo(datetime) { const now = new Date(); const past = new Date(datetime); const diffMs = now - past; const diffMins = Math.floor(diffMs / 60000); if(diffMins < 1) return 'Just now'; if(diffMins < 60) return `${diffMins} min ago`; const diffHours = Math.floor(diffMins / 60); if(diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`; const diffDays = Math.floor(diffHours / 24); return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`; }
        document.addEventListener('click', function(event) { const dropdown = document.getElementById('notifDropdown'); const bell = document.querySelector('.notif-bell'); if(notifOpen && !dropdown.contains(event.target) && !bell.contains(event.target)) { dropdown.classList.remove('active'); notifOpen = false; } });
        setInterval(() => { if(notifOpen) { loadNotifications(); } else { fetch('?fetch_notifications=1').then(response => response.json()).then(data => { if(data.length > 0) { showToast('You have ' + data.length + ' new notification(s)', 'info'); } }); } }, 30000);
        <?php if($success): ?> showToast('<?php echo addslashes($success); ?>', 'success'); <?php endif; ?>
        <?php if($error_msg): ?> showToast('<?php echo addslashes($error_msg); ?>', 'error'); <?php endif; ?>

        // --- DEEP DATA SEARCH LOGIC ---
        function performDeepSearch() {
            const rec = document.getElementById('deepSearchInput').value;
            if(!rec) return alert('Enter a Record Number!');
            
            const btn = document.querySelector('.deep-search-btn');
            const originalText = btn.textContent;
            btn.textContent = 'Searching...';
            btn.disabled = true;

            fetch(`?deep_search_rec=${encodeURIComponent(rec)}&tab=deep_search`)
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then(txt => {
                    let data;
                    try { data = JSON.parse(txt); } 
                    catch(e) { 
                        console.error('Deep search parse error:', txt.substring(0,500));
                        alert('Deep search error. Check console.'); 
                        btn.textContent = originalText; btn.disabled = false;
                        return null;
                    }
                    return data;
                })
                .then(data => {
                    if (!data) return;
                    document.getElementById('searchResultArea').style.display = 'grid';
                    
                    // Populate Details
                    let detailsHtml = '';
                    if(data.assignment && data.assignment.full_name) {
                        detailsHtml += `<div class="info-row"><span class="info-label">Assigned DEO:</span> <span>${data.assignment.full_name} (${data.assignment.username || data.assignment.assigned_to || 'N/A'})</span></div>`;
                    } else if(data.record_status && data.record_status.assigned_to) {
                        detailsHtml += `<div class="info-row"><span class="info-label">Assigned To:</span> <span>${data.record_status.assigned_to}</span></div>`;
                    } else {
                        detailsHtml += `<div class="info-row"><span class="info-label">Assigned DEO:</span> <span style="color:red;">Not Assigned</span></div>`;
                    }

                    if(data.record_status) {
                        // P1 uses row_status, not status
                        let status = data.record_status.row_status || data.record_status.status || 'pending';
                        let statusClass = (status == 'Completed' || status == 'completed') ? 'bg-green' : (status == 'done' ? 'bg-blue' : 'bg-red');
                        detailsHtml += `<div class="info-row"><span class="info-label">Current Status:</span> <span class="badge ${statusClass}">${status.toUpperCase()}</span></div>`;
                        detailsHtml += `<div class="info-row"><span class="info-label">Last Updated:</span> <span>${data.record_status.updated_at || 'N/A'}</span></div>`;
                    } else {
                        detailsHtml += `<div class="info-row"><span class="info-label">Current Status:</span> <span style="color:orange;">Not Found in Database</span></div>`;
                    }
                    document.getElementById('recordDetailsContent').innerHTML = detailsHtml;

                    // Image Logic - Check both P1 and P2 paths
                    const imgBox = document.getElementById('deepSearchImage');
                    const imgNameDisplay = document.getElementById('imageNameDisplay');
                    if(data.image_map && (data.image_map.image_no || data.image_map.image_path)) {
                        let imgPath = data.image_map.image_path || `uploads/mapping_images/${data.image_map.image_no}`;
                        let imgName = data.image_map.image_no || data.image_map.image_path;
                        imgBox.src = imgPath; 
                        imgBox.onerror = function() { 
                            this.src = `uploads/mapping_images/${imgName}.jpg`; 
                            this.onerror = function() { this.src = `uploads/mapping_images/${imgName}.png`; this.onerror=null; };
                        };
                        imgNameDisplay.textContent = imgName;
                        imgBox.style.display = 'block';
                    } else if(data.record_status && data.record_status.image_path) {
                        // P1 stores image_path in client_records
                        imgBox.src = data.record_status.image_path;
                        imgNameDisplay.textContent = data.record_status.image_filename || 'Image';
                        imgBox.style.display = 'block';
                    } else {
                        imgBox.style.display = 'none';
                        imgNameDisplay.textContent = 'No Image Mapped';
                    }

                    // Flags
                    let flagHtml = '';
                    if(data.flags && data.flags.length > 0) {
                        data.flags.forEach(f => {
                            flagHtml += `<div style="background:#fff7ed; padding:5px; margin-bottom:5px; border-left:3px solid #f97316;">
                                <strong>${f.flagged_fields}</strong>: ${f.remarks} <br>
                                <small>By ${f.dqc_name} on ${f.flagged_date}</small>
                            </div>`;
                        });
                    } else { flagHtml = 'No DQC Flags'; }
                    document.getElementById('flagHistoryContent').innerHTML = flagHtml;

                    // Errors
                    let errHtml = '';
                    if(data.errors && data.errors.length > 0) {
                        data.errors.forEach(e => {
                            errHtml += `<div style="background:#fef2f2; padding:5px; margin-bottom:5px; border-left:3px solid #ef4444;">
                                <strong>${e.error_field}</strong>: ${e.error_details} <br>
                                <small>Status: ${e.status}</small>
                            </div>`;
                        });
                    } else { errHtml = 'No Admin Reports'; }
                    document.getElementById('adminErrorContent').innerHTML = errHtml;

                })
                .catch(e => alert('Error: ' + e))
                .finally(() => {
                    btn.textContent = originalText;
                    btn.disabled = false;
                });
        }

        function openZoomModal(src) {
            const modal = document.getElementById('zoomModal');
            const content = modal.querySelector('.modal-content');
            document.getElementById('zoomImageContent').src = src;
            modal.style.display = 'flex';
            modal.style.alignItems = 'center';
            modal.style.justifyContent = 'center';
            // Reset margin for centering
            content.style.margin = '0';
        }
        function closeZoomModal() {
            document.getElementById('zoomModal').style.display = 'none';
        }

        // --- EXCEL DOWNLOAD FUNCTIONS FOR QUALITY REPORT ---
        <?php if($active_tab == 'quality'): ?>
            // ... existing excel JS ...
            const qualityOverallData = <?php echo json_encode($overall_data); ?>;
            const qualityRecordData = <?php echo json_encode($record_data); ?>;
            const qualityBreakdownData = <?php echo json_encode($breakdown_data); ?>;

            function downloadOverallSummary() {
                if(!qualityOverallData || qualityOverallData.length === 0) {
                    alert("No data available to download.");
                    return;
                }
                const wsData = [['DEO Name', 'Total Flags', 'Pending (Unfixed)', 'Fixed Count']];
                qualityOverallData.forEach(row => {
                    wsData.push([row.full_name, row.total_flags, row.pending, row.fixed]);
                });
                const ws = XLSX.utils.aoa_to_sheet(wsData);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Overall Summary");
                XLSX.writeFile(wb, "Quality_Overall_Summary.xlsx");
            }

            function downloadRecordWise() {
                if(!qualityRecordData || qualityRecordData.length === 0) {
                    alert("No data available to download.");
                    return;
                }
                const wsData = [['Record No', 'DEO Name', 'Total Flags', 'Pending', 'Fixed']];
                qualityRecordData.forEach(row => {
                    wsData.push([row.record_no, row.deo_name, row.total_flags, row.pending, row.fixed]);
                });
                const ws = XLSX.utils.aoa_to_sheet(wsData);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Record Wise Status");
                XLSX.writeFile(wb, "Quality_Record_Wise_Status.xlsx");
            }

            function downloadDetailedBreakdown() {
                let flatData = [];
                for (const [deoName, items] of Object.entries(qualityBreakdownData)) {
                    if(Array.isArray(items)) {
                        items.forEach(item => {
                            flatData.push([deoName, item.flagged_fields, item.count]);
                        });
                    }
                }
                if(flatData.length === 0) {
                    alert("No data available to download.");
                    return;
                }
                const wsData = [['DEO Name', 'Error Field (Header)', 'Count']];
                flatData.forEach(row => wsData.push(row));
                const ws = XLSX.utils.aoa_to_sheet(wsData);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Detailed Breakdown");
                XLSX.writeFile(wb, "Quality_Detailed_Breakdown.xlsx");
            }
        <?php endif; ?>
    </script>

<script>
// ============================================================
// P2 ADMIN - REALTIME: Remove resolved errors instantly
// ============================================================
(function() {
    // Only run on dashboard tab with errorsList visible
    if (!document.getElementById('errorsList')) return;
    
    let lastSync = new Date().toISOString().slice(0,19).replace('T',' ');
    
    setInterval(function() {
        fetch('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_resolved_ce_ids&since=' + encodeURIComponent(lastSync)
        })
        .then(r => r.json())
        .then(function(res) {
            if (res.status !== 'success') return;
            lastSync = res.server_time;
            
            if (res.resolved && res.resolved.length > 0) {
                res.resolved.forEach(function(item) {
                    // Remove by ce_id
                    let el = document.getElementById('ce_item_' + item.id);
                    if (el) {
                        el.style.transition = 'opacity 0.4s';
                        el.style.opacity = '0';
                        setTimeout(function() {
                            el.remove();
                            // If errorsList is empty, show success message
                            let list = document.getElementById('errorsList');
                            if (list && list.children.length === 0) {
                                list.innerHTML = '<p style="text-align:center;color:#22c55e;padding:2rem;font-weight:500;">✨ No critical errors pending!</p>';
                            }
                        }, 400);
                    }
                });
            }
        })
        .catch(function() {});
    }, 4000);  // Poll every 4 seconds
})();
</script>

</body>
</html>