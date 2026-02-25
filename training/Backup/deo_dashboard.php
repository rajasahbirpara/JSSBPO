<?php
require_once 'config.php';
check_login();
check_role(['deo']);

$user = get_user_info();
$success = "";
$error_msg = "";

// Create notifications table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    message TEXT,
    type VARCHAR(50),
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Handle notification mark as read
if (isset($_GET['mark_read']) && isset($_GET['notif_id'])) {
    $notif_id = (int)$_GET['notif_id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notif_id, $user['id']);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit();
}

// API for fetching notifications
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

$conn->query("CREATE TABLE IF NOT EXISTS work_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deo_id INT,
    record_from VARCHAR(50),
    record_to VARCHAR(50),
    record_count INT,
    log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deo_id) REFERENCES users(id)
)");

$fields_list = [
    'KYC Number', 'Name', 'Guardian Name', 'Gender', 'Marital Status', 
    'DOB', 'Address', 'Landmark', 'City', 'Zip Code', 'City Of Birth', 
    'Nationality', 'Photo Attachment', 'Residential Status', 'Occupation', 
    'Officially Valid Documents', 'Annual Income', 'Broker Name', 
    'Sub Broker Code', 'Bank Serial No', 'Second Applicant Name', 
    'Amount Receive From', 'Amount', 'ARN No', 'Second Address', 
    'Occupation/Profession', 'Remarks'
];

// --- HANDLE PASSWORD CHANGE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    // Verify current password using prepared statement
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $db_pass = $result->fetch_assoc()['password'];
    $stmt->close();

    if ($current_pass !== $db_pass) {
        $error_msg = "❌ Current password incorrect.";
    } elseif ($new_pass !== $confirm_pass) {
        $error_msg = "❌ New passwords do not match.";
    } elseif (strlen($new_pass) < 4) {
        $error_msg = "❌ Password too short (min 4 chars).";
    } else {
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $new_pass, $user['id']);
        $stmt->execute();
        $stmt->close();
        $success = "✅ Password changed successfully!";
    }
}

// Delete Error Handler - reported_by OR assigned DEO dono delete kar sake
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_error'])) {
    $del_id = (int)$_POST['error_id'];
    $rb_chk = $conn->real_escape_string($user['username']);
    $uid    = (int)$user['id'];
    // Allow delete if: DEO ne report kiya OR record DEO ko assigned hai
    $check = $conn->query("
        SELECT rta.id, rta.record_no FROM report_to_admin rta
        WHERE rta.id='$del_id' AND rta.status='open'
          AND (rta.admin_remark IS NULL OR rta.admin_remark = '')
          AND (
              rta.reported_by COLLATE utf8mb4_unicode_ci = '$rb_chk'
              OR EXISTS (
                  SELECT 1 FROM client_records cr
                  WHERE cr.record_no COLLATE utf8mb4_unicode_ci = rta.record_no COLLATE utf8mb4_unicode_ci
                    AND (cr.assigned_to COLLATE utf8mb4_unicode_ci = '$rb_chk' OR cr.assigned_to_id = $uid)
              )
          )
    ");
    if ($check && $check->num_rows > 0) {
        $rta_row = $check->fetch_assoc();
        $del_rn  = $conn->real_escape_string($rta_row['record_no'] ?? '');
        if ($conn->query("DELETE FROM report_to_admin WHERE id='$del_id'")) {
            $open_cnt = (int)$conn->query("SELECT COUNT(*) as c FROM report_to_admin WHERE record_no='$del_rn' AND status='open'")->fetch_assoc()['c'];
            if ($open_cnt == 0) $conn->query("UPDATE client_records SET is_reported=0, report_count=0 WHERE record_no='$del_rn'");
            else $conn->query("UPDATE client_records SET report_count=$open_cnt WHERE record_no='$del_rn'");
            $success = "🗑️ Report deleted successfully.";
        } else {
            $error_msg = "❌ Delete failed: " . $conn->error;
        }
    } else {
        $error_msg = "❌ Cannot delete. Report not found, already reviewed by admin, or no permission.";
    }
}

// Fetch for Edit - reported_by OR assigned DEO dono edit kar sake
$edit_data = null;
if (isset($_GET['edit_error'])) {
    $edit_id = (int)$_GET['edit_error'];
    $rb_chk  = $conn->real_escape_string($user['username']);
    $uid     = (int)$user['id'];
    $res = $conn->query("
        SELECT rta.id, rta.record_no, rta.header_name as error_field,
               rta.issue_details as error_details, rta.image_no, rta.status
        FROM report_to_admin rta
        WHERE rta.id='$edit_id' AND rta.status='open'
          AND (rta.admin_remark IS NULL OR rta.admin_remark = '')
          AND (
              rta.reported_by COLLATE utf8mb4_unicode_ci = '$rb_chk'
              OR EXISTS (
                  SELECT 1 FROM client_records cr
                  WHERE cr.record_no COLLATE utf8mb4_unicode_ci = rta.record_no COLLATE utf8mb4_unicode_ci
                    AND (cr.assigned_to COLLATE utf8mb4_unicode_ci = '$rb_chk' OR cr.assigned_to_id = $uid)
              )
          )
    ");
    if ($res->num_rows > 0) {
        $edit_data = $res->fetch_assoc();
        $full_details = $edit_data['error_details'];
        
        // Extract Image No
        if (preg_match('/\[Image No: (.*?)\]/', $full_details, $matches)) {
            $edit_data['image_no'] = $matches[1];
            $full_details = trim(str_replace($matches[0], '', $full_details));
        } else {
            $edit_data['image_no'] = '';
        }
        
        $edit_data['clean_details'] = $full_details;
    }
}

// --- DELETE SUBMISSION LOG HANDLER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_log'])) {
    $log_id = clean_input($_POST['log_id']);
    
    // 1. Fetch Log Details - Using prepared statement
    $stmt_log = $conn->prepare("SELECT * FROM work_logs WHERE id=? AND deo_id=?");
    $stmt_log->bind_param("ii", $log_id, $user['id']);
    $stmt_log->execute();
    $log_query = $stmt_log->get_result();
    
    if ($log_query->num_rows > 0) {
        $log_data = $log_query->fetch_assoc();
        $rec_from = (int)$log_data['record_from'];
        $rec_to = (int)$log_data['record_to'];
        
        // 2. Delete from work_logs - Using prepared statement
        $stmt_del = $conn->prepare("DELETE FROM work_logs WHERE id=?");
        $stmt_del->bind_param("i", $log_id);
        if ($stmt_del->execute()) {
            // 3. Revert records status to 'pending' - Using prepared statement
            $stmt_update = $conn->prepare("UPDATE client_records 
                          SET row_status='pending', updated_at=NOW() 
                          WHERE assigned_to_id=? 
                          AND CAST(record_no AS UNSIGNED) BETWEEN ? AND ?");
            $stmt_update->bind_param("iii", $user['id'], $rec_from, $rec_to);
            $stmt_update->execute();
            
            $success = "✅ Submission deleted! Records $rec_from - $rec_to reverted to 'pending'.";
        } else {
            $error_msg = "❌ Failed to delete log entry.";
        }
    } else {
        $error_msg = "❌ Log entry not found or permission denied.";
    }
}

// Work Progress Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_progress'])) {
    $record_from = clean_input($_POST['progress_from']);
    $record_to = clean_input($_POST['progress_to']) ?: $record_from;
    
    if (is_numeric($record_from) && is_numeric($record_to)) {
        $is_assigned = false;
        $stmt_assign = $conn->prepare("SELECT * FROM assignments WHERE deo_id = ?");
        $stmt_assign->bind_param("i", $user['id']);
        $stmt_assign->execute();
        $assign_result = $stmt_assign->get_result();
        
        while ($assign = $assign_result->fetch_assoc()) {
            $assigned_start = (int)$assign['record_no_from'];
            $assigned_end = (int)$assign['record_no_to'];
            
            if ((int)$record_from >= $assigned_start && (int)$record_to <= $assigned_end) {
                $is_assigned = true;
                break;
            }
        }

        if (!$is_assigned) {
            $error_msg = "❌ Error: Record range **$record_from - $record_to** is not in your assignments.";
        } else {
            $check_log_dup_sql = "SELECT id FROM work_logs 
                                  WHERE deo_id = {$user['id']} 
                                  AND record_from = '$record_from' 
                                  AND record_to = '$record_to'";
            if ($conn->query($check_log_dup_sql)->num_rows > 0) {
                $error_msg = "⚠️ Duplicate: Range **$record_from - $record_to** already submitted.";
            } else {
                $values = [];
                for ($i = (int)$record_from; $i <= (int)$record_to; $i++) {
                    $safe_rec = $conn->real_escape_string($i);
                    $values[] = "('$safe_rec', '{$user['id']}', 'completed', NOW())";
                }
                
                if (!empty($values)) {
                    $chunks = array_chunk($values, 500);
                    foreach ($chunks as $chunk) {
                        $values_str = implode(", ", $chunk);
                        $sql = "INSERT INTO records (record_no, assigned_to, status, updated_at) 
                                VALUES $values_str 
                                ON DUPLICATE KEY UPDATE 
                                status = VALUES(status), 
                                updated_at = VALUES(updated_at),
                                assigned_to = VALUES(assigned_to)";
                        $conn->query($sql);
                    }
                    
                    $count = (int)$record_to - (int)$record_from + 1;
                    $success = "✅ Success! Range $record_from - $record_to ($count records) completed.";
                    
                    $log_sql = "INSERT INTO work_logs (deo_id, record_from, record_to, record_count) 
                                VALUES ('{$user['id']}', '$record_from', '$record_to', '$count')";
                    $conn->query($log_sql);

                    header("Location: deo_dashboard.php?msg=" . urlencode($success));
                    exit();
                } else {
                    $error_msg = "⚠️ Update failed.";
                }
            }
        }
    } else {
        $error_msg = "❌ Records must be numeric.";
    }
}

if (isset($_GET['msg'])) {
    $success = clean_input($_GET['msg']);
}

// Multi-Field Report Issue (New Feature)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_multi_error'])) {
    $record_no = clean_input($_POST['error_record_no']);
    $image_no = clean_input($_POST['image_no']);
    $error_fields = $_POST['error_fields'] ?? [];
    $error_details_arr = $_POST['error_details_arr'] ?? [];
    
    // P1 Compatible: Check if record is assigned to this user
    $is_allowed = false;
    $check_record = $conn->query("SELECT record_no FROM client_records WHERE record_no = '$record_no' AND (assigned_to = '{$user['username']}' OR assigned_to_id = {$user['id']})");
    if ($check_record && $check_record->num_rows > 0) {
        $is_allowed = true;
    }

    if (!$is_allowed) {
        $error_msg = "🚫 Access Denied: Record #$record_no is not assigned to you.";
    } else if (empty($error_fields) || count($error_fields) == 0) {
        $error_msg = "⚠️ Please add at least one issue field.";
    } else {
        $success_count = 0;
        $duplicate_count = 0;
        $error_count = 0;
        
        // Process each field
        for ($i = 0; $i < count($error_fields); $i++) {
            $error_field = clean_input($error_fields[$i]);
            $error_details = clean_input($error_details_arr[$i] ?? '');
            
            if (empty($error_field) || empty($error_details)) {
                continue;
            }
            
            $final_details = "[Image No: $image_no] " . $error_details;
            $rbn_esc  = $conn->real_escape_string($user['full_name'] ?: $user['username']);
            $rb_esc   = $conn->real_escape_string($user['username']);
            $img_esc2 = $conn->real_escape_string($image_no);
            
            // Check duplicate in report_to_admin
            $check_dup = $conn->query("SELECT id FROM report_to_admin WHERE record_no='$record_no' AND reported_by='$rb_esc' AND header_name='$error_field' AND status='open'");
            if ($check_dup && $check_dup->num_rows > 0) {
                $duplicate_count++;
            } else {
                $sql = "INSERT INTO report_to_admin (record_no, header_name, issue_details, reported_by, reported_by_name, `role`, reported_from, image_no)
                        VALUES ('$record_no', '$error_field', '$final_details', '$rb_esc', '$rbn_esc', 'deo', 'p2_deo', '$img_esc2')";
                if ($conn->query($sql)) {
                    $success_count++;
                    // Mark client_records as reported
                    $conn->query("UPDATE client_records SET is_reported=1, report_count=IFNULL(report_count,0)+1 WHERE record_no='$record_no'");
                } else {
                    $error_count++;
                }
            }
        }
        
        // Create notification for admin
        if ($success_count > 0) {
            $fields_list_str = implode(', ', array_filter($error_fields));
            $conn->query("INSERT INTO notifications (user_id, title, message, type) VALUES (1, 'Error Report: DEO {$user['username']} flagged Record $record_no - $success_count issues ($fields_list_str)', 'Error Report: DEO {$user['username']} flagged Record $record_no - $success_count issues ($fields_list_str)', 'alert')");
        }
        
        // Generate result message
        $msg_parts = [];
        if ($success_count > 0) $msg_parts[] = "✅ $success_count issues reported";
        if ($duplicate_count > 0) $msg_parts[] = "⚠️ $duplicate_count duplicates skipped";
        if ($error_count > 0) $msg_parts[] = "❌ $error_count failed";
        
        if ($success_count > 0) {
            $success = implode(' | ', $msg_parts);
        } else {
            $error_msg = implode(' | ', $msg_parts);
        }
    }
}

// Single Report Issue (Edit mode)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_error_submit'])) {
    $record_no = clean_input($_POST['error_record_no']);
    $image_no = clean_input($_POST['image_no']);
    $error_field = clean_input($_POST['error_field']);
    $error_details = clean_input($_POST['error_details']);
    
    // P1 Compatible: Check if record is assigned to this user from client_records
    $is_allowed = false;
    $check_record = $conn->query("SELECT record_no FROM client_records WHERE record_no = '$record_no' AND (assigned_to = '{$user['username']}' OR assigned_to_id = {$user['id']})");
    if ($check_record && $check_record->num_rows > 0) {
        $is_allowed = true;
    }

    if (!$is_allowed) {
        $error_msg = "🚫 Access Denied: Record #$record_no is not assigned to you.";
    } else {
        $final_details = "[Image No: $image_no] " . $error_details;
        
        $edit_id = (int)$_POST['edit_id'];
        $rb_chk2 = $conn->real_escape_string($user['username']);
        $uid2    = (int)$user['id'];
        $img_e   = $conn->real_escape_string($image_no);
        // Allow update if: reported_by DEO OR record assigned to DEO
        $sql = "UPDATE report_to_admin SET record_no='$record_no', header_name='$error_field', issue_details='$final_details', image_no='$img_e'
                WHERE id='$edit_id' AND status='open'
                  AND (admin_remark IS NULL OR admin_remark = '')
                  AND (
                      reported_by COLLATE utf8mb4_unicode_ci = '$rb_chk2'
                      OR EXISTS (
                          SELECT 1 FROM client_records cr
                          WHERE cr.record_no COLLATE utf8mb4_unicode_ci = '$record_no'
                            AND (cr.assigned_to COLLATE utf8mb4_unicode_ci = '$rb_chk2' OR cr.assigned_to_id = $uid2)
                      )
                  )";
        if ($conn->query($sql) && $conn->affected_rows > 0) {
            $success = "✅ Report updated successfully.";
            echo "<script>window.location.href='deo_dashboard.php';</script>";
            exit();
        } else {
            $error_msg = "❌ Update failed. Report not found or no permission.";
        }
    }
}

// Handle Correction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_corrected'])) {
    $flag_id = clean_input($_POST['flag_id']);
    $conn->query("UPDATE dqc_flags SET status = 'corrected', corrected_date = NOW() WHERE id = '$flag_id'");
    $get_rec = $conn->query("SELECT record_no FROM dqc_flags WHERE id='$flag_id'")->fetch_assoc();
    if($get_rec){
        $rec_no = $get_rec['record_no'];
        $conn->query("UPDATE records SET status='corrected', updated_at=NOW() WHERE record_no='$rec_no'");
    }
    $success = "✅ Flag marked as fixed.";
    header("Location: deo_dashboard.php?msg=" . urlencode($success) . "#dqc-corrections");
    exit();
}

// Resolve Error - Only allow resolving admin_reviewed reports that belong to this DEO
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['resolve_error'])) {
    $error_id = (int)$_POST['error_id'];
    $rb_res   = $conn->real_escape_string($user['username']);
    $uname    = $rb_res;
    
    // Mark report_to_admin as solved (admin_remark present = admin replied)
    // Allow resolve if: reported BY this DEO OR record is ASSIGNED to this DEO
    $rta_chk = $conn->query("
        SELECT rta.id, rta.record_no, rta.ce_id 
        FROM report_to_admin rta
        WHERE rta.id=$error_id 
          AND rta.status='open' 
          AND rta.admin_remark IS NOT NULL AND rta.admin_remark != ''
          AND (
              rta.reported_by COLLATE utf8mb4_unicode_ci = '$rb_res'
              OR EXISTS (
                  SELECT 1 FROM client_records cr 
                  WHERE cr.record_no COLLATE utf8mb4_unicode_ci = rta.record_no COLLATE utf8mb4_unicode_ci
                    AND (cr.assigned_to COLLATE utf8mb4_unicode_ci = '$rb_res' OR cr.assigned_to_id = {$user['id']})
              )
          )
    ");
    if ($rta_chk && $rta_chk->num_rows > 0) {
        $rta = $rta_chk->fetch_assoc();
        $rta_rn = $conn->real_escape_string($rta['record_no']);
        $ce_id_linked = (int)($rta['ce_id'] ?? 0);
        
        $conn->query("UPDATE report_to_admin SET status='solved', solved_by='$uname', solved_at=NOW() WHERE id=$error_id");
        
        // Also resolve linked critical_errors entry (so First QC, Second QC, Autotyper dashboards also clear)
        if ($ce_id_linked > 0) {
            $conn->query("UPDATE critical_errors SET status='resolved', resolved_at=NOW() WHERE id=$ce_id_linked");
        }
        // Fallback: resolve critical_errors by record_no if no ce_id link
        if ($ce_id_linked == 0) {
            $conn->query("UPDATE critical_errors SET status='resolved', resolved_at=NOW() WHERE record_no='$rta_rn' AND status IN ('pending','admin_reviewed')");
        }
        
        // Update is_reported flag + updated_at so sync_changes propagates to all dashboards
        $open_cnt = (int)$conn->query("SELECT COUNT(*) as c FROM report_to_admin WHERE record_no='$rta_rn' AND `status`='open'")->fetch_assoc()['c'];
        $open_ce_cnt = (int)$conn->query("SELECT COUNT(*) as c FROM critical_errors WHERE record_no='$rta_rn' AND status IN ('pending','admin_reviewed')")->fetch_assoc()['c'];
        $total_open = max($open_cnt, $open_ce_cnt);
        
        if ($total_open == 0) $conn->query("UPDATE client_records SET is_reported=0, report_count=0, updated_at=NOW() WHERE record_no='$rta_rn'");
        else $conn->query("UPDATE client_records SET report_count=$total_open, updated_at=NOW() WHERE record_no='$rta_rn'");
        $success = "✅ Marked as resolved.";
    } else {
        $error_msg = "❌ Could not resolve. Report not found or admin reply pending.";
    }
    header("Location: deo_dashboard.php?msg=" . urlencode($success ?: $error_msg) . "#report_form");
    exit();
}

// --- P1 COMPATIBLE: Get work stats from client_records ---

// Get filter date from URL for allocated ranges
$allocated_filter_date = isset($_GET['alloc_date']) ? clean_input($_GET['alloc_date']) : '';

// Work orders - Get assigned records for this DEO grouped by date (Simple MIN-MAX)
$date_condition = "";
if (!empty($allocated_filter_date)) {
    $date_condition = "AND DATE(cr.created_at) = '$allocated_filter_date'";
}

$work_orders_sql = "
    SELECT 
        MIN(CAST(cr.record_no AS UNSIGNED)) as record_no_from,
        MAX(CAST(cr.record_no AS UNSIGNED)) as record_no_to,
        DATE(cr.created_at) as assigned_date,
        COUNT(*) as total_qty,
        SUM(CASE WHEN cr.row_status = 'Completed' THEN 1 ELSE 0 END) as completed_qty
    FROM client_records cr 
    WHERE (cr.assigned_to = '{$user['username']}' OR cr.assigned_to_id = {$user['id']})
    AND cr.record_no REGEXP '^[0-9]+$'
    $date_condition
    GROUP BY DATE(cr.created_at)
    ORDER BY DATE(cr.created_at) DESC";
$work_orders_result = $conn->query($work_orders_sql);

// Get available dates for filter dropdown
$available_alloc_dates = $conn->query("SELECT DISTINCT DATE(created_at) as assign_date FROM client_records WHERE (assigned_to = '{$user['username']}' OR assigned_to_id = {$user['id']}) ORDER BY assign_date DESC LIMIT 30");

// Work logs - Daily grouped submissions
$logs_query = "SELECT 
    DATE(log_time) as submission_date,
    MIN(record_from) as first_record,
    MAX(record_to) as last_record,
    SUM(record_count) as total_records,
    COUNT(*) as submission_count,
    MAX(log_time) as latest_time,
    GROUP_CONCAT(CONCAT(record_from, '-', record_to) ORDER BY log_time SEPARATOR ', ') as all_ranges
FROM work_logs 
WHERE deo_id = {$user['id']} 
GROUP BY DATE(log_time)
ORDER BY submission_date DESC 
LIMIT 30";
$logs_result = $conn->query($logs_query);

// Total Assigned - Count from client_records
$total_assigned_sql = "SELECT COUNT(*) as count FROM client_records WHERE (assigned_to = '{$user['username']}' OR assigned_to_id = {$user['id']})";
$total_assigned = $conn->query($total_assigned_sql)->fetch_assoc()['count'];

// Completed Count - P1 compatible
$completed_count = $conn->query("
    SELECT COUNT(DISTINCT record_no) as count 
    FROM client_records 
    WHERE (assigned_to = '{$user['username']}' OR assigned_to_id = {$user['id']})
    AND row_status = 'Completed'
")->fetch_assoc()['count'];

// Flagged records for this DEO - Show ALL flags (both pending and corrected)
$flagged_query = "SELECT f.*, cr.name, u.full_name as dqc_name 
    FROM dqc_flags f 
    JOIN client_records cr ON CAST(f.record_no AS UNSIGNED) = CAST(cr.record_no AS UNSIGNED)
    LEFT JOIN users u ON f.dqc_id = u.id 
    WHERE (cr.assigned_to = '{$user['username']}' OR cr.assigned_to_id = {$user['id']})
    ORDER BY f.flagged_date DESC";
$flagged_result = $conn->query($flagged_query);

// Total flags count (both flagged and corrected)
$total_flags = $flagged_result ? $flagged_result->num_rows : 0;

// Pending flags count (only flagged, not corrected)
$pending_count_query = $conn->query("SELECT COUNT(*) as cnt FROM dqc_flags f 
    JOIN client_records cr ON CAST(f.record_no AS UNSIGNED) = CAST(cr.record_no AS UNSIGNED)
    WHERE (cr.assigned_to = '{$user['username']}' OR cr.assigned_to_id = {$user['id']}) AND f.status = 'flagged'");
$pending_flags = $pending_count_query ? $pending_count_query->fetch_assoc()['cnt'] : 0;

// For display: Show total flags in Corrections card
$corrections_count = $total_flags;

// Reviewed = report_to_admin with admin_remark (admin has replied, awaiting DEO resolution)
// Show reports that: DEO submitted OR record is assigned to this DEO
$reviewed_result = $conn->query("
    SELECT rta.id, rta.record_no, rta.header_name as error_field,
           rta.issue_details as error_details, rta.created_at,
           rta.admin_remark, IFNULL(rta.image_no,'') as image_no,
           rta.reported_by_name, rta.role as reporter_role_rta, 'report_to_admin' as source,
           rta.reported_from, rta.reviewed_at
    FROM report_to_admin rta
    WHERE rta.status = 'open'
      AND rta.admin_remark IS NOT NULL AND rta.admin_remark != ''
      AND (
          rta.reported_by COLLATE utf8mb4_unicode_ci = '{$user['username']}'
          OR EXISTS (
              SELECT 1 FROM client_records cr 
              WHERE cr.record_no COLLATE utf8mb4_unicode_ci = rta.record_no COLLATE utf8mb4_unicode_ci
                AND (cr.assigned_to COLLATE utf8mb4_unicode_ci = '{$user['username']}' OR cr.assigned_to_id = {$user['id']})
          )
      )
    ORDER BY rta.created_at DESC
");

// Count of admin reviewed reports
$reviewed_count = $reviewed_result ? $reviewed_result->num_rows : 0;

// DEO's pending error reports (status = 'pending' means waiting for admin review)
// Also includes entries from report_to_admin that were NOT linked to critical_errors (fallback)
// Pending = report_to_admin open, no admin reply yet
// Show reports that: DEO submitted OR record is assigned to this DEO
$pending_errors_result = $conn->query("
    SELECT rta.id, rta.record_no,
           rta.header_name as error_field,
           rta.issue_details as error_details,
           rta.created_at, IFNULL(rta.image_no,'') as image_no,
           rta.header_name, rta.reported_by_name, rta.role as reporter_role_rta, 'report_to_admin' as source,
           rta.reported_from
    FROM report_to_admin rta
    WHERE rta.status = 'open'
      AND (rta.admin_remark IS NULL OR rta.admin_remark = '')
      AND (
          rta.reported_by COLLATE utf8mb4_unicode_ci = '{$user['username']}'
          OR EXISTS (
              SELECT 1 FROM client_records cr 
              WHERE cr.record_no COLLATE utf8mb4_unicode_ci = rta.record_no COLLATE utf8mb4_unicode_ci
                AND (cr.assigned_to COLLATE utf8mb4_unicode_ci = '{$user['username']}' OR cr.assigned_to_id = {$user['id']})
          )
      )
    ORDER BY rta.created_at DESC
");

// Count of pending reports for DEO
$pending_reports_count = $pending_errors_result ? $pending_errors_result->num_rows : 0;

// Fetch unread notifications
$unread_notifs = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = {$user['id']} AND is_read = 0")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DEO Workspace</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #0ea5e9; --primary-dark: #0284c7; --success: #22c55e; --warning: #f59e0b; --danger: #ef4444; --light: #f1f5f9; --white: #ffffff; --dark: #0f172a; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background-color: var(--light); color: var(--dark); padding-bottom: 40px; }
        
        @keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes bounce { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        @keyframes slideInRight { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        /* Toast Notifications */
        .toast { position: fixed; top: 80px; right: 20px; background: white; padding: 1rem 1.5rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: none; z-index: 9999; animation: slideInRight 0.3s ease-out; min-width: 250px; border-left: 5px solid; }
        .toast.show { display: block; }
        .toast.success { border-color: var(--success); }
        .toast.error { border-color: var(--danger); }
        .toast-icon { margin-right: 10px; font-size: 1.2rem; }

        .header { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: var(--white); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 50; }
        .header-left h1 { font-size: 1.25rem; font-weight: 600; margin: 0; }
        .header-left small { opacity: 0.8; font-size: 0.85rem; }
        
        .header-right { display: flex; align-items: center; gap: 1.5rem; }
        
        /* Notifications Bell */
        .notif-bell { position: relative; cursor: pointer; font-size: 1.3rem; padding: 0.5rem; border-radius: 50%; background: rgba(255,255,255,0.15); transition: all 0.3s; }
        .notif-bell:hover { background: rgba(255,255,255,0.25); }
        .notif-badge { position: absolute; top: -2px; right: -2px; background: var(--danger); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; font-weight: bold; animation: bounce 2s infinite; }
        
        .notif-dropdown { position: absolute; top: 70px; right: 80px; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); width: 350px; max-height: 400px; overflow-y: auto; display: none; animation: slideIn 0.2s; z-index: 1000; }
        .notif-dropdown.active { display: block; }
        .notif-header { padding: 1rem; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: var(--primary); }
        .notif-item { padding: 1rem; border-bottom: 1px solid #f3f4f6; cursor: pointer; transition: background 0.3s; }
        .notif-item:hover { background: #f9fafb; }
        .notif-item.unread { background: #eff6ff; }
        .notif-message { font-size: 0.9rem; color: var(--dark); margin-bottom: 0.5rem; }
        .notif-time { font-size: 0.75rem; color: var(--secondary); }

        /* Profile Dropdown */
        .profile-menu { position: relative; cursor: pointer; }
        .profile-icon { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.15); padding: 5px 12px; border-radius: 20px; transition: background 0.3s; }
        .profile-icon:hover { background: rgba(255,255,255,0.25); }
        .profile-dropdown { position: absolute; top: 120%; right: 0; background: white; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); width: 180px; display: none; overflow: hidden; z-index: 1000; }
        .profile-dropdown.active { display: block; }
        .profile-dropdown a { display: block; padding: 12px 15px; color: var(--dark); text-decoration: none; font-size: 0.9rem; transition: background 0.2s; border-bottom: 1px solid #f1f5f9; }
        .profile-dropdown a:hover { background: #f8fafc; color: var(--primary); }
        .profile-dropdown a:last-child { border-bottom: none; }

        .footer-credit { position: fixed; bottom: 10px; right: 15px; font-size: 0.75rem; color: #64748b; background: rgba(255, 255, 255, 0.9); padding: 5px 10px; border-radius: 6px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; z-index: 999; pointer-events: none; }

        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; animation: fadeIn 0.5s ease-out; padding-bottom: 40px; }
        
        .stats-row { grid-column: 1 / -1; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1.5rem; margin-bottom: 1rem; }
        .stat-card { background: var(--white); padding: 1.5rem; border-radius: 12px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); transition: transform 0.2s; border-bottom: 4px solid transparent; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card h3 { font-size: 2rem; margin-bottom: 0.2rem; }
        .stat-card p { font-size: 0.85rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }

        .card { background: var(--white); padding: 1.5rem; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; border: 1px solid #e2e8f0; }
        .card h2 { font-size: 1.1rem; color: var(--primary-dark); border-bottom: 2px solid #f1f5f9; padding-bottom: 0.75rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 8px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        input, select, textarea { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; margin-bottom: 0.5rem; font-family: inherit; font-size: 0.9rem; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary); background: #fff; }
        
        button { background: var(--primary); color: white; padding: 0.75rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; transition: opacity 0.3s; }
        button:hover { opacity: 0.9; }
        .btn-warning { background: var(--warning); color: #000; }
        .btn-danger { background: var(--danger); color: white; font-size: 0.8rem; padding: 0.3rem 0.6rem; margin-left: 10px; width: auto; display: inline-block; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; color: #64748b; font-weight: 600; }
        
        .progress-track { background: #e2e8f0; height: 6px; border-radius: 3px; overflow: hidden; margin-top: 5px; }
        .progress-fill { height: 100%; background: var(--success); transition: width 1s ease-in-out; }

        .log-item { background: #f0fdf4; padding: 0.75rem; border-radius: 6px; margin-bottom: 0.5rem; font-size: 0.85rem; border-left: 3px solid var(--success); display: flex; justify-content: space-between; align-items: center; }
        
        .flag-card { background: #fff7ed; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #ffedd5; }
        .flag-header { display: flex; justify-content: space-between; font-size: 0.9rem; color: #9a3412; font-weight: 600; }
        
        .action-icon { background:none; border:none; cursor:pointer; padding:0; margin:0 5px; font-size:1.1rem; text-decoration:none; }
        .scroll-box { max-height: 400px; overflow-y: auto; padding-right: 5px; }

        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(5px); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 90%; max-width: 400px; border-radius: 12px; position: relative; animation: slideIn 0.3s; }
        .modal-close { float: right; font-size: 28px; font-weight: bold; cursor: pointer; color: #aaa; }
        .modal-close:hover { color: black; }
        
        /* Mobile Responsive Card Layout */
        @media (max-width: 768px) { 
            .header { flex-direction: column; gap: 10px; text-align: center; padding: 1rem; }
            .header-left h1 { font-size: 1.1rem; }
            .header-right { width: 100%; justify-content: space-between; padding: 0 10px; }
            .notif-dropdown { right: 10px; width: calc(100vw - 40px); top: 120px; }
            .form-row { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: 1fr; }
            .container { grid-template-columns: 1fr; }
            .card { padding: 1rem; }
            .footer-credit { font-size: 0.7rem; padding: 4px 8px; bottom: 5px; right: 5px; }

            /* Table to Card Transformation */
            table, thead, tbody, th, td, tr { display: block; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            tr { margin-bottom: 1rem; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; background: white; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
            td { border: none; position: relative; padding-left: 40%; text-align: right; padding-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center; }
            td:before { position: absolute; top: 50%; left: 0; transform: translateY(-50%); width: 35%; padding-right: 10px; white-space: nowrap; font-weight: 600; text-align: left; content: attr(data-label); color: #64748b; font-size: 0.85rem; }
            td:last-child { border-bottom: 0; padding-bottom: 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <h1>Welcome, <?php echo htmlspecialchars($user['full_name']); ?></h1>
            <small>DEO Workspace</small>
        </div>
        <div class="header-right">
            <div class="notif-bell" onclick="toggleNotifications()">
                <i class="fas fa-bell"></i>
                <?php if($unread_notifs > 0): ?>
                    <span class="notif-badge"><?php echo $unread_notifs; ?></span>
                <?php endif; ?>
            </div>
            
            <div class="profile-menu">
                <div class="profile-icon" onclick="toggleProfile()">
                    <i class="fas fa-user-circle" style="font-size: 1.5rem;"></i>
                    <i class="fas fa-chevron-down" style="font-size: 0.8rem;"></i>
                </div>
                <div class="profile-dropdown" id="profileDropdown">
                    <a href="#" onclick="openPasswordModal()">🔑 Change Password</a>
                    <a href="logout.php" style="color: #ef4444;">🚪 Logout</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast" id="toast">
        <div style="display: flex; align-items: center;">
            <i class="fas fa-info-circle toast-icon" id="toastIcon"></i>
            <span id="toastMessage">Notification Message</span>
        </div>
    </div>

    <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-header">
            📬 Notifications (<?php echo $unread_notifs; ?> unread)
        </div>
        <div id="notifContainer">
            <div style="padding: 2rem; text-align: center; color: var(--secondary);">Loading...</div>
        </div>
    </div>

    <!-- Password Change Modal -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closePasswordModal()">&times;</span>
            <h3 style="margin-bottom: 1rem; color: var(--primary);">Change Password</h3>
            <form method="POST">
                <input type="hidden" name="change_password" value="1">
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display:block; margin-bottom: 5px; font-size: 0.9rem;">Current Password</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display:block; margin-bottom: 5px; font-size: 0.9rem;">New Password</label>
                    <input type="password" name="new_password" required minlength="4">
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display:block; margin-bottom: 5px; font-size: 0.9rem;">Confirm New Password</label>
                    <input type="password" name="confirm_password" required minlength="4">
                </div>
                <button type="submit" style="width: 100%;">Update Password</button>
            </form>
        </div>
    </div>

    <div class="footer-credit">Website development by - Raja Sah, 7001159731</div>

    <div class="container">
        <!-- Replaced Static Alerts with Toast Logic at bottom -->

        <div class="stats-row">
            <div class="stat-card" style="border-bottom-color: #64748b;">
                <h3><?php echo $total_assigned; ?></h3> <p>Allocated</p>
            </div>
            <div class="stat-card" style="border-bottom-color: var(--success);">
                <h3><?php echo $completed_count; ?></h3> <p>Completed</p>
            </div>
            <div class="stat-card" style="border-bottom-color: var(--warning);">
                <h3><?php echo $corrections_count; ?></h3> <p>Corrections</p>
            </div>
        </div>

        <div class="main-content">
            
            <!-- Update Work Progress section removed - P1 handles work status -->

            <div class="card">
                <h2>📋 My Work Orders (Date-wise)</h2>
                
                <!-- Date Filter -->
                <form method="GET" style="margin-bottom: 1rem; padding: 0.75rem; background: #f0f9ff; border-radius: 8px; border: 1px solid #bae6fd;">
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <label style="font-size: 0.85rem; font-weight: 600; color: #0369a1;">📅 Filter:</label>
                        <select name="alloc_date" style="padding: 6px 10px; border: 1px solid #7dd3fc; border-radius: 6px; min-width: 150px; font-size: 0.85rem;">
                            <option value="">All Dates</option>
                            <?php if ($available_alloc_dates): while ($dt = $available_alloc_dates->fetch_assoc()): ?>
                                <option value="<?php echo $dt['assign_date']; ?>" <?php echo ($allocated_filter_date == $dt['assign_date']) ? 'selected' : ''; ?>>
                                    <?php echo date('d M Y', strtotime($dt['assign_date'])); ?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                        <button type="submit" style="background: #0ea5e9; color: white; padding: 6px 15px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85rem;">
                            🔍 Go
                        </button>
                        <?php if (!empty($allocated_filter_date)): ?>
                            <a href="deo_dashboard.php" style="color: #ef4444; text-decoration: none; font-size: 0.85rem;">✕ Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
                
                <div class="table-responsive">
                    <?php if ($work_orders_result && $work_orders_result->num_rows > 0): ?>
                    <table>
                        <thead><tr><th>📅 Date</th><th>Range</th><th>Progress</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php while ($order = $work_orders_result->fetch_assoc()): 
                                $percent = ($order['total_qty'] > 0) ? round(($order['completed_qty'] / $order['total_qty']) * 100) : 0;
                                if($percent > 100) $percent = 100;
                            ?>
                            <tr>
                                <td data-label="Date" style="color:#0369a1; font-weight: 600;"><?php echo date('d M Y', strtotime($order['assigned_date'])); ?></td>
                                <td data-label="Range" style="font-weight:600;"><?php echo $order['record_no_from'] . ' - ' . $order['record_no_to']; ?></td>
                                <td data-label="Progress" style="min-width:120px;">
                                    <div style="display:flex; justify-content:space-between; font-size:0.75rem; width: 100%;">
                                        <span><?php echo $order['completed_qty']; ?>/<?php echo $order['total_qty']; ?></span>
                                        <span><?php echo $percent; ?>%</span>
                                    </div>
                                    <div class="progress-track" style="width: 100%;"><div class="progress-fill" style="width:<?php echo $percent; ?>%;"></div></div>
                                </td>
                                <td data-label="Status"><?php echo ($percent==100) ? '<span style="color:var(--success); font-weight:bold;">✅ Done</span>' : '<span style="color:var(--warning); font-weight:bold;">⏳ Active</span>'; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <p style="text-align:center; color:#94a3b8; padding: 1rem;">
                            <?php echo !empty($allocated_filter_date) ? '📭 No work allocated for selected date.' : '📭 No work assigned yet.'; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <h2>🕒 Recent Submissions</h2>
                <div class="scroll-box">
                    <?php if ($logs_result->num_rows > 0): ?>
                        <?php while ($log = $logs_result->fetch_assoc()): ?>
                            <div class="log-item" style="border-left: 4px solid #3b82f6; padding-left: 12px;">
                                <div style="flex:1;">
                                    <div style="font-size: 1rem; font-weight: 600; color: #1e40af; margin-bottom: 8px;">
                                        📅 <?php echo date('d M Y (l)', strtotime($log['submission_date'])); ?>
                                    </div>
                                    <div style="background: #f0f9ff; padding: 8px; border-radius: 6px; margin-bottom: 6px;">
                                        <div style="font-size: 0.9rem;">
                                            📊 <strong>Total Records:</strong> <span style="color: #16a34a; font-weight: 700;"><?php echo $log['total_records']; ?></span>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #64748b; margin-top: 4px;">
                                            🔄 Submissions: <?php echo $log['submission_count']; ?> time(s)
                                        </div>
                                    </div>
                                    <div style="font-size: 0.85rem; margin-top: 6px;">
                                        Range: <strong><?php echo $log['first_record']; ?> - <?php echo $log['last_record']; ?></strong>
                                    </div>
                                    <?php if (!empty($log['all_ranges']) && $log['submission_count'] > 1): ?>
                                        <details style="margin-top: 6px; font-size: 0.8rem; color: #6b7280;">
                                            <summary style="cursor: pointer; user-select: none;">📋 View all ranges</summary>
                                            <div style="margin-top: 4px; padding: 6px; background: #f9fafb; border-radius: 4px;">
                                                <?php echo htmlspecialchars($log['all_ranges']); ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                    <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 6px;">
                                        ⏰ Last submission: <?php echo date('h:i A', strtotime($log['latest_time'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?><p style="text-align:center; color:#94a3b8;">No history.</p><?php endif; ?>
                </div>
            </div>

            <!-- ADDED ID FOR ANCHOR SCROLLING -->
            <div class="card" id="dqc-corrections">
                <h2>🚩 DQC Corrections</h2>
                <div class="scroll-box">
                    <?php if ($flagged_result->num_rows > 0): ?>
                        <?php while ($flag = $flagged_result->fetch_assoc()): ?>
                            <div class="flag-card">
                                <div class="flag-header">
                                    <span>Record No. <?php echo $flag['record_no']; ?></span>
                                    <span style="font-size:0.75rem; opacity:0.8;"><?php echo date('d M, h:i A', strtotime($flag['flagged_date'])); ?></span>
                                </div>
                                <?php if(!empty($flag['image_no'])): ?>
                                    <div style="font-size:0.85rem; margin-bottom:5px; font-weight:600; color:#0ea5e9;">
                                        Img No: <?php echo htmlspecialchars($flag['image_no']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div style="font-size:0.85rem; margin-bottom:0.5rem;">
                                    <span style="color:#c2410c; font-weight:500;">Field: <?php echo htmlspecialchars($flag['flagged_fields']); ?></span>
                                    <br>
                                    <span style="color:#4b5563;">"<?php echo htmlspecialchars($flag['remarks']); ?>"</span>
                                    <br>
                                    <small style="color:#94a3b8;">By: <?php echo htmlspecialchars($flag['dqc_name'] ?: 'DQC'); ?></small>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="flag_id" value="<?php echo $flag['id']; ?>">
                                    <button type="submit" name="mark_corrected" class="btn-warning" style="padding:0.5rem;">Mark Fixed</button>
                                </form>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?><p style="text-align:center; color:var(--success);">✅ No corrections pending!</p><?php endif; ?>
                </div>
            </div>

            <?php if ($reviewed_result->num_rows > 0): ?>
            <div class="card" id="adminResponsesCard">
                <h2>📩 Admin Responses <span id="adminResponsesCount" style="background:#22c55e;color:white;padding:1px 8px;border-radius:10px;font-size:0.75rem;margin-left:6px;"><?php echo $reviewed_count; ?></span></h2>
                <?php while ($review = $reviewed_result->fetch_assoc()): 
                    $full_details = $review['error_details'];
                    $img_no = "";
                    $clean_details = $full_details;
                    
                    if (preg_match('/\[Image No: (.*?)\]/', $full_details, $matches)) {
                        $img_no = $matches[1];
                        $clean_details = trim(str_replace($matches[0], '', $full_details));
                    }
                    // Fallback: image_no column directly use karo agar text mein nahi mila
                    if (empty($img_no) && !empty($review['image_no'])) {
                        $img_no = $review['image_no'];
                    }
                ?>
                    <div id="deo_ce_<?php echo $review['id']; ?>" data-ce-id="<?php echo $review['id']; ?>"
                         style="border: 1px solid #e2e8f0; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.5rem;">
                            <div>
                                <strong>Rec No. <?php echo htmlspecialchars($review['record_no']); ?></strong>
                                <?php if($img_no): ?>
                                    <span style="background:#0ea5e9;color:white;padding:1px 7px;border-radius:3px;font-size:0.7rem;margin-left:6px;font-weight:600;">📷 <?php echo htmlspecialchars($img_no); ?></span>
                                <?php endif; ?>
                            </div>
                            <small style="color:#64748b;white-space:nowrap;"><?php echo !empty($review['reviewed_at']) ? date('d M h:i A', strtotime($review['reviewed_at'])) : date('d M', strtotime($review['created_at'])); ?></small>
                        </div>
                        
                        <div style="font-size:0.85rem; color:#d97706; margin-bottom:0.5rem;">
                            <strong>Header:</strong> <?php echo htmlspecialchars($review['error_field']); ?>
                            <?php 
                            // Show source badge if reported from QC/Autotyper
                            $rev_src = $review['reported_from'] ?? '';
                            $rev_src_labels = [
                                'first_qc'  => ['🔍 First QC',  '#0d6efd'],
                                'second_qc' => ['🔍 Second QC', '#6610f2'],
                                'autotyper'  => ['🤖 Autotyper', '#fd7e14'],
                                'p2_deo'    => ['📝 DEO',       '#198754'],
                                'admin'     => ['🛠️ Admin',    '#dc3545'],
                            ];
                            if (!empty($rev_src) && isset($rev_src_labels[$rev_src])): 
                                $src_info = $rev_src_labels[$rev_src];
                            ?>
                                | <span style="background:<?php echo $src_info[1]; ?>;color:white;padding:1px 6px;border-radius:3px;font-size:0.72rem;font-weight:600;">
                                    <?php echo $src_info[0]; ?>
                                </span>
                                <?php if (!empty($review['reported_by_name'])): ?>
                                    <small style="color:#64748b;">(by <?php echo htmlspecialchars($review['reported_by_name']); ?>)</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- NEW SECTION: USER QUERY DISPLAY -->
                        <div style="background:#f8fafc; padding:0.75rem; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:0.5rem;">
                            <div style="font-size:0.8rem; color:#64748b; font-weight:600; margin-bottom:4px;">YOUR QUERY:</div>
                            <p style="font-size:0.9rem; color:#334155; margin:0;"><?php echo htmlspecialchars($clean_details); ?></p>
                        </div>

                        <div style="background:#f0f9ff; padding:0.75rem; border-radius:6px; border-left:3px solid var(--primary);">
                            <div style="font-size:0.8rem; color:var(--primary-dark); font-weight:600;">ADMIN REPLY:</div>
                            <p style="font-size:0.9rem;"><?php echo htmlspecialchars($review['admin_remark']); ?></p>
                        </div>
                        
                        <form method="POST" style="margin-top:0.5rem; text-align:right;">
                            <input type="hidden" name="error_id" value="<?php echo $review['id']; ?>">
                            <button type="submit" name="resolve_error" style="width:auto; padding:0.4rem 1rem; font-size:0.85rem;">Mark Resolved</button>
                        </form>
                    </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="sidebar">
            
            <div class="card" id="report_form">
                <h2 style="display:flex; justify-content:space-between; align-items:center;">
                    <span>⚠️ Report to Admin</span>
                    <?php if($pending_reports_count > 0): ?>
                        <span style="background:#f59e0b; color:white; padding:0.2rem 0.5rem; border-radius:10px; font-size:0.7rem;"><?php echo $pending_reports_count; ?> Pending</span>
                    <?php endif; ?>
                    <?php if($reviewed_count > 0): ?>
                        <span style="background:#22c55e; color:white; padding:0.2rem 0.5rem; border-radius:10px; font-size:0.7rem; margin-left:0.3rem;"><?php echo $reviewed_count; ?> Reviewed</span>
                    <?php endif; ?>
                </h2>
                <?php if($edit_data): ?>
                    <div style="background:#e0f2fe; padding:0.5rem; margin-bottom:1rem; border-radius:4px; color:#0369a1; font-size:0.9rem; display:flex; justify-content:space-between; align-items:center;">
                        <span>Editing Report #<?php echo $edit_data['id']; ?></span>
                        <a href="deo_dashboard.php" style="color:#ef4444; text-decoration:none;">Cancel</a>
                    </div>
                    
                    <!-- Single Edit Form -->
                    <form method="POST">
                        <input type="hidden" name="edit_id" value="<?php echo $edit_data['id']; ?>">
                        <input type="hidden" name="update_error_submit" value="1">

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                            <div>
                                <input type="number" name="error_record_no" id="error_record_no" placeholder="Rec No" required value="<?php echo $edit_data['record_no']; ?>">
                            </div>
                            <input type="text" name="image_no" id="image_no" placeholder="Img No" required value="<?php echo $edit_data['image_no']; ?>">
                        </div>
                        
                        <select name="error_field" required style="margin-top:0.75rem;">
                            <option value="">Issue Field Select Karein</option>
                            <?php foreach($fields_list as $f) { 
                                $sel = ($edit_data['error_field'] == $f) ? 'selected' : '';
                                echo "<option value='$f' $sel>$f</option>";
                            } ?>
                            <option value="Multiple Fields" <?php echo ($edit_data['error_field'] == 'Multiple Fields') ? 'selected' : ''; ?>>Multiple Fields</option>
                            <option value="Other" <?php echo ($edit_data['error_field'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                        <textarea name="error_details" rows="2" placeholder="Issue details..." required style="margin-top:0.75rem;"><?php echo $edit_data['clean_details']; ?></textarea>
                        
                        <button type="submit" style="background:#0ea5e9; margin-top:0.75rem;">Update Report</button>
                    </form>
                <?php else: ?>
                    <!-- Multi-Field Report Form -->
                    <form method="POST" id="multiFieldForm">
                        <input type="hidden" name="submit_multi_error" value="1">

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                            <div>
                                <input type="number" name="error_record_no" id="error_record_no" placeholder="Rec No" required>
                                <span id="imgLoader" style="display:none; color: #0ea5e9; font-size: 0.8rem;"><i class="fas fa-spinner fa-spin"></i> Checking...</span>
                            </div>
                            <input type="text" name="image_no" id="image_no" placeholder="Img No" required>
                        </div>
                        
                        <!-- Dynamic Fields Container -->
                        <div id="fieldsContainer" style="margin-top:1rem;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                                <label style="font-size:0.85rem; color:#475569; font-weight:600;">🔴 Issue Fields:</label>
                                <button type="button" onclick="addFieldRow()" style="background:#10b981; color:white; border:none; padding:0.3rem 0.75rem; border-radius:4px; font-size:0.8rem; cursor:pointer;">
                                    ➕ Add Field
                                </button>
                            </div>
                            
                            <!-- First Field Row (Required) -->
                            <div class="field-row" style="background:#f8fafc; padding:0.75rem; border-radius:6px; margin-bottom:0.5rem; border:1px solid #e2e8f0;">
                                <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;">
                                    <select name="error_fields[]" required style="flex:1; padding:0.5rem; border:1px solid #cbd5e1; border-radius:4px;">
                                        <option value="">Header Select</option>
                                        <?php foreach($fields_list as $f): ?>
                                            <option value="<?php echo $f; ?>"><?php echo $f; ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other">Other</option>
                                    </select>
                                    <span style="color:#94a3b8; font-size:0.8rem;">#1</span>
                                </div>
                                <textarea name="error_details_arr[]" rows="2" placeholder="Issue details likhein..." required style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.85rem;"></textarea>
                            </div>
                        </div>
                        
                        <button type="submit" style="background:#475569; margin-top:0.75rem; width:100%;">
                            📤 Report All Issues
                        </button>
                    </form>
                    
                    <script>
                    let fieldCount = 1;
                    const fieldsList = <?php echo json_encode($fields_list); ?>;
                    
                    function addFieldRow() {
                        fieldCount++;
                        const container = document.getElementById('fieldsContainer');
                        
                        let optionsHtml = '<option value="">Header Select</option>';
                        fieldsList.forEach(f => {
                            optionsHtml += `<option value="${f}">${f}</option>`;
                        });
                        optionsHtml += '<option value="Other">Other</option>';
                        
                        const newRow = document.createElement('div');
                        newRow.className = 'field-row';
                        newRow.style.cssText = 'background:#f8fafc; padding:0.75rem; border-radius:6px; margin-bottom:0.5rem; border:1px solid #e2e8f0;';
                        newRow.innerHTML = `
                            <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;">
                                <select name="error_fields[]" required style="flex:1; padding:0.5rem; border:1px solid #cbd5e1; border-radius:4px;">
                                    ${optionsHtml}
                                </select>
                                <span style="color:#94a3b8; font-size:0.8rem;">#${fieldCount}</span>
                                <button type="button" onclick="removeFieldRow(this)" style="background:#ef4444; color:white; border:none; padding:0.25rem 0.5rem; border-radius:4px; font-size:0.75rem; cursor:pointer;">✕</button>
                            </div>
                            <textarea name="error_details_arr[]" rows="2" placeholder="Issue details likhein..." required style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:4px; font-size:0.85rem;"></textarea>
                        `;
                        container.appendChild(newRow);
                    }
                    
                    function removeFieldRow(btn) {
                        btn.closest('.field-row').remove();
                        // Renumber remaining rows
                        document.querySelectorAll('.field-row').forEach((row, idx) => {
                            row.querySelector('span').textContent = '#' + (idx + 1);
                        });
                        fieldCount = document.querySelectorAll('.field-row').length;
                    }
                    </script>
                <?php endif; ?>
                

                <?php if (!empty($pending_errors_result) && $pending_errors_result->num_rows > 0): ?>
                    <div style="margin-top:1.5rem;">
                        <h4 style="font-size:0.9rem; margin-bottom:0.5rem;">Pending Review</h4>
                        <div class="scroll-box" style="max-height:250px;">
                            <?php while($p=$pending_errors_result->fetch_assoc()): 
                                // Extract clean issue details
                                $p_details = $p['error_details'];
                                $p_img = "";
                                if (preg_match('/\[Image No: (.*?)\]/', $p_details, $p_matches)) {
                                    $p_img = $p_matches[1];
                                    $p_details = trim(str_replace($p_matches[0], '', $p_details));
                                }
                                $p_source = $p['source'] ?? 'critical_errors';
                                $is_qc_report = ($p_source == 'report_to_admin') || !empty($p['reported_by_name']);
                                $qc_reporter  = $p['reported_by_name'] ?? '';
                                $qc_role      = strtoupper($p['reporter_role_rta'] ?? 'QC');
                            ?>
                                <div style="background:<?php echo $is_qc_report ? '#eff6ff' : '#fffbeb'; ?>; padding:0.75rem; margin-bottom:0.5rem; border:1px solid <?php echo $is_qc_report ? '#bfdbfe' : '#fef3c7'; ?>; border-radius:6px;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                        <div style="flex:1;">
                                            <div style="margin-bottom:0.4rem;">
                                                <?php if($is_qc_report): ?>
                                                    <span style="background:#0d6efd;color:white;padding:1px 6px;border-radius:3px;font-size:0.65rem;font-weight:600;margin-right:4px;">🔍 <?php echo $qc_role; ?> REPORT</span>
                                                <?php endif; ?>
                                                <span style="background:#f59e0b; color:white; padding:1px 6px; border-radius:3px; font-size:0.7rem; font-weight:600;">Record No. <?php echo htmlspecialchars($p['record_no']); ?></span>
                                                <?php if($p_img): ?>
                                                    <span style="background:#0ea5e9; color:white; padding:1px 6px; border-radius:3px; font-size:0.7rem; margin-left:4px;">Img: <?php echo htmlspecialchars($p_img); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($is_qc_report && $qc_reporter): ?>
                                                <div style="font-size:0.75rem; color:#1d4ed8; margin-bottom:0.3rem;">
                                                    <strong><?php echo $qc_role; ?> User:</strong> <?php echo htmlspecialchars($qc_reporter); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-size:0.8rem; color:#92400e; margin-bottom:0.3rem;">
                                                <strong>Header:</strong> <?php echo htmlspecialchars($p['error_field']); ?>
                                            </div>
                                            <div style="background:#fef2f2; padding:0.4rem 0.6rem; border-radius:4px; border-left:3px solid #ef4444;">
                                                <span style="color:#991b1b; font-size:0.75rem;"><strong>🔴 Issue:</strong> <?php echo htmlspecialchars($p_details); ?></span>
                                            </div>
                                        </div>
                                        <div style="display:flex; flex-direction:column; gap:4px; margin-left:8px;">
                                            <?php if($p['id'] > 0): ?>
                                                <a href="?edit_error=<?php echo $p['id']; ?>#report_form" 
                                                   class="action-icon" title="Edit Report" 
                                                   style="font-size:0.9rem; text-decoration:none; display:flex; align-items:center; justify-content:center; width:28px; height:28px; background:#e0f2fe; border-radius:4px;">✏️</a>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Is report ko delete karna chahte hain?');">
                                                    <input type="hidden" name="error_id" value="<?php echo $p['id']; ?>">
                                                    <button type="submit" name="delete_error" 
                                                            style="color:#ef4444; font-size:0.9rem; background:#fee2e2; border:none; cursor:pointer; width:28px; height:28px; border-radius:4px; display:flex; align-items:center; justify-content:center;" 
                                                            title="Delete Report">🗑️</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="font-size:0.65rem; color:#6b7280;">Awaiting<br>Admin</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Admin Reviewed Reports - DEO can Mark Resolved -->
                <?php if ($reviewed_count > 0): ?>
                    <div style="margin-top:1.5rem;">
                        <h4 style="font-size:0.9rem; margin-bottom:0.5rem; color:#0ea5e9;">📩 Admin Reviewed (<?php echo $reviewed_count; ?>)</h4>
                        <div class="scroll-box" style="max-height:250px;">
                            <?php 
                            // Reset result pointer for second iteration
                            if ($reviewed_result) $reviewed_result->data_seek(0);
                            while($r=$reviewed_result->fetch_assoc()): 
                                // Extract clean issue details
                                $r_details = $r['error_details'];
                                $r_img = "";
                                if (preg_match('/\[Image No: (.*?)\]/', $r_details, $r_matches)) {
                                    $r_img = $r_matches[1];
                                    $r_details = trim(str_replace($r_matches[0], '', $r_details));
                                }
                            ?>
                                <div style="background:#ecfdf5; padding:0.75rem; margin-bottom:0.5rem; border:1px solid #a7f3d0; border-radius:6px;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                        <div style="flex:1;">
                                            <div style="margin-bottom:0.4rem;">
                                                <span style="background:#22c55e; color:white; padding:1px 6px; border-radius:3px; font-size:0.7rem; font-weight:600;">Record No. <?php echo htmlspecialchars($r['record_no']); ?></span>
                                                <?php 
                                                // Image: pehle image_no column, phir issue_details se extract
                                                $disp_img = !empty($r['image_no']) ? $r['image_no'] : $r_img;
                                                if($disp_img): ?>
                                                    <span style="background:#0ea5e9; color:white; padding:1px 6px; border-radius:3px; font-size:0.7rem; margin-left:4px;">📷 <?php echo htmlspecialchars($disp_img); ?></span>
                                                <?php endif; ?>
                                                <?php 
                                                // Show source badge
                                                $r_src = $r['reported_from'] ?? '';
                                                $r_src_map = ['first_qc'=>['First QC','#0d6efd'],'second_qc'=>['Second QC','#6610f2'],'autotyper'=>['Autotyper','#fd7e14']];
                                                if (!empty($r_src) && isset($r_src_map[$r_src])): ?>
                                                    <span style="background:<?php echo $r_src_map[$r_src][1]; ?>;color:white;padding:1px 6px;border-radius:3px;font-size:0.65rem;margin-left:4px;">
                                                        <?php echo $r_src_map[$r_src][0]; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:0.8rem; color:#065f46; margin-bottom:0.3rem;">
                                                <strong>Header:</strong> <?php echo htmlspecialchars($r['error_field']); ?>
                                            </div>
                                            <div style="background:#fef2f2; padding:0.4rem 0.6rem; border-radius:4px; border-left:3px solid #ef4444; margin-bottom:0.3rem;">
                                                <span style="color:#991b1b; font-size:0.75rem;"><strong>🔴 Issue:</strong> <?php echo htmlspecialchars($r_details); ?></span>
                                            </div>
                                            <?php if (!empty($r['admin_remark'])): ?>
                                                <div style="background:#e0f2fe; padding:0.4rem 0.6rem; border-radius:4px; border-left:3px solid #0ea5e9;">
                                                    <span style="color:#0369a1; font-size:0.75rem;"><strong>💬 Admin Reply:</strong> <?php echo htmlspecialchars($r['admin_remark']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <small style="color:#94a3b8; display:block; margin-top:0.3rem;">Reviewed: <?php echo !empty($r['reviewed_at']) ? date('d M Y, h:i A', strtotime($r['reviewed_at'])) : date('d M Y', strtotime($r['created_at'])); ?></small>
                                        </div>
                                        <form method="POST" style="margin-left:0.5rem;" onsubmit="return confirm('Mark this issue as resolved?');">
                                            <input type="hidden" name="error_id" value="<?php echo $r['id']; ?>">
                                            <button type="submit" name="resolve_error" style="background:#22c55e; color:white; border:none; padding:0.4rem 0.75rem; border-radius:4px; font-size:0.7rem; cursor:pointer; font-weight:500;">
                                                ✅ Mark Resolved
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Notification System
        let notifOpen = false;
        let profileOpen = false;

        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            notifOpen = !notifOpen;
            
            // Close profile if open
            if(profileOpen) {
                document.getElementById('profileDropdown').classList.remove('active');
                profileOpen = false;
            }

            if(notifOpen) {
                dropdown.classList.add('active');
                loadNotifications();
            } else {
                dropdown.classList.remove('active');
            }
        }

        function toggleProfile() {
            const dropdown = document.getElementById('profileDropdown');
            profileOpen = !profileOpen;

            // Close notif if open
            if(notifOpen) {
                document.getElementById('notifDropdown').classList.remove('active');
                notifOpen = false;
            }

            if(profileOpen) {
                dropdown.classList.add('active');
            } else {
                dropdown.classList.remove('active');
            }
        }

        function openPasswordModal() {
            document.getElementById('passwordModal').style.display = 'block';
            document.getElementById('profileDropdown').classList.remove('active');
            profileOpen = false;
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').style.display = 'none';
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            const toastIcon = document.getElementById('toastIcon');
            
            toastMessage.textContent = message;
            toast.className = 'toast show ' + type;
            
            if(type === 'success') {
                toastIcon.className = 'fas fa-check-circle toast-icon';
                toastIcon.style.color = 'var(--success)';
            } else if(type === 'error') {
                toastIcon.className = 'fas fa-exclamation-circle toast-icon';
                toastIcon.style.color = 'var(--danger)';
            }

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        function loadNotifications() {
            fetch('?fetch_notifications=1')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('notifContainer');
                    
                    if(data.length === 0) {
                        container.innerHTML = '<div style="padding: 2rem; text-align: center; color: var(--secondary);">No new notifications 📭</div>';
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
                        // Optional reload if needed
                        // location.reload();
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
            const notifDropdown = document.getElementById('notifDropdown');
            const notifBell = document.querySelector('.notif-bell');
            const profileDropdown = document.getElementById('profileDropdown');
            const profileIcon = document.querySelector('.profile-icon');
            
            // Close notif if clicked outside
            if(notifOpen && !notifDropdown.contains(event.target) && !notifBell.contains(event.target)) {
                notifDropdown.classList.remove('active');
                notifOpen = false;
            }

            // Close profile if clicked outside
            if(profileOpen && !profileDropdown.contains(event.target) && !profileIcon.contains(event.target)) {
                profileDropdown.classList.remove('active');
                profileOpen = false;
            }
        });

        // Trigger Toast from PHP variables
        <?php if($success): ?>
            showToast("<?php echo addslashes($success); ?>", "success");
        <?php endif; ?>
        <?php if($error_msg): ?>
            showToast("<?php echo addslashes($error_msg); ?>", "error");
        <?php endif; ?>

        setInterval(() => {
            if(notifOpen) {
                loadNotifications();
            }
        }, 30000);
        
        // ── Realtime: Remove resolved admin_reviewed errors instantly ──
        (function() {
            let lastCESync = new Date().toISOString().slice(0,19).replace('T',' ');
            setInterval(function() {
                fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=get_resolved_ce_ids&since=' + encodeURIComponent(lastCESync)
                })
                .then(r => r.json())
                .then(function(res) {
                    if (res.status !== 'success') return;
                    lastCESync = res.server_time;
                    if (res.resolved && res.resolved.length > 0) {
                        res.resolved.forEach(function(item) {
                            let el = document.getElementById('deo_ce_' + item.id);
                            if (el) {
                                el.style.transition = 'opacity 0.4s, max-height 0.4s';
                                el.style.opacity = '0';
                                setTimeout(function() {
                                    el.remove();
                                    // Update count badge
                                    let card = document.getElementById('adminResponsesCard');
                                    if (card) {
                                        let remaining = card.querySelectorAll('[data-ce-id]').length;
                                        let badge = document.getElementById('adminResponsesCount');
                                        if (badge) badge.textContent = remaining;
                                        if (remaining === 0) {
                                            card.style.display = 'none';
                                        }
                                    }
                                }, 450);
                            }
                        });
                    }
                })
                .catch(function() {});
            }, 4000);
        })();

        // --- AUTO FILL LOGIC (Integrated from previous version) ---
        const recordInput = document.getElementById('error_record_no');
        const imageInput = document.getElementById('image_no');
        const loader = document.getElementById('imgLoader');

        if(recordInput) {
            recordInput.addEventListener('blur', function() {
                const val = this.value.trim();
                if(val) {
                    loader.style.display = 'inline-block';
                    
                    // Fetch from our backend
                    fetch(`get_image_details.php?record_no=${encodeURIComponent(val)}`)
                        .then(response => response.json())
                        .then(data => {
                            loader.style.display = 'none';
                            if(data.success && data.found) {
                                imageInput.value = data.image_no;
                                // Visual feedback
                                imageInput.style.backgroundColor = '#e8f8f5';
                                imageInput.style.borderColor = '#2ecc71';
                            } else {
                                if(imageInput.value === '') {
                                    imageInput.placeholder = "Not found, type manually...";
                                }
                                imageInput.style.borderColor = '#e2e8f0';
                                imageInput.style.backgroundColor = '#fff';
                            }
                        })
                        .catch(err => {
                            loader.style.display = 'none';
                            console.error('Fetch error:', err);
                        });
                }
            });
        }
    </script>
</body>
</html>