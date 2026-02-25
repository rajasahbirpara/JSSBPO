<?php
// --- JSON ERROR FIX: Output Buffering ON ---
ob_start();
define('AUTOTYPER_TOKEN', 'NBM_AUTOTYPER_2025_SECURE');

// Clean output - No warnings/notices in JSON
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Limits
ini_set('memory_limit', '512M');
set_time_limit(300);

// Cache Control
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Content-Type: application/json');

require_once 'config.php';
include 'whatsapp_helper.php';
ob_clean();

define('MASTER_OTP', ''); // No default - must be set in settings

function send_json($data) {
    ob_clean();
    echo json_encode($data);
    exit;
}

if ($conn->connect_error) {
    send_json(['status' => 'error', 'message' => 'DB Connection Failed: ' . $conn->connect_error]);
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

// ==========================================
// SECURITY HELPER FUNCTIONS
// ==========================================

// Get client IP address
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    return trim($ip);
}

// Get security setting from database
function get_security_setting($conn, $key, $default = null) {
    // Check if table exists first
    $check = $conn->query("SHOW TABLES LIKE 'security_settings'");
    if ($check->num_rows == 0) return $default;
    
    $stmt = $conn->prepare("SELECT setting_value FROM security_settings WHERE setting_key = ?");
    if ($stmt) {
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return $row['setting_value'];
        }
    }
    return $default;
}

// Log login attempt
function log_login_attempt($conn, $username, $status, $failure_reason = null) {
    // Check if table exists first
    $check = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($check->num_rows == 0) return;
    
    $ip = get_client_ip();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, user_agent, status, failure_reason) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sssss", $username, $ip, $user_agent, $status, $failure_reason);
        $stmt->execute();
    }
}

// Log user activity
function log_activity($conn, $user_id, $username, $action, $module = null, $record_id = null, $record_no = null, $old_value = null, $new_value = null, $details = null) {
    // Check if table exists first
    $check = $conn->query("SHOW TABLES LIKE 'activity_logs'");
    if ($check->num_rows == 0) return;
    
    $ip = get_client_ip();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, module, record_id, record_no, old_value, new_value, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ississsssss", $user_id, $username, $action, $module, $record_id, $record_no, $old_value, $new_value, $ip, $user_agent, $details);
        $stmt->execute();
    }
}

// Check if user is locked
function is_user_locked($conn, $user_id) {
    $stmt = $conn->prepare("SELECT status, locked_until FROM users WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if (isset($row['locked_until']) && $row['locked_until'] && strtotime($row['locked_until']) > time()) return true;
        if (isset($row['status']) && $row['status'] === 'inactive' && isset($row['locked_until']) && $row['locked_until']) return true;
    }
    return false;
}

// Check IP restriction
function check_ip_allowed($conn, $user_id = null) {
    $ip_restriction = get_security_setting($conn, 'ip_restriction_enabled', '0');
    if ($ip_restriction !== '1') return true; // IP restriction disabled
    
    $client_ip = get_client_ip();
    
    // Check if allowed_ips table exists
    $check = $conn->query("SHOW TABLES LIKE 'allowed_ips'");
    if ($check->num_rows == 0) return true;
    
    // Check global allowed IPs
    $stmt = $conn->prepare("SELECT id FROM allowed_ips WHERE ip_address = ? AND is_active = 1");
    if (!$stmt) return true;
    $stmt->bind_param("s", $client_ip);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) return true;
    
    // Check user-specific allowed IPs
    if ($user_id) {
        $stmt = $conn->prepare("SELECT allowed_ips FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $allowed = $row['allowed_ips'] ?? '';
                if (!empty($allowed)) {
                    $ips = array_map('trim', explode(',', $allowed));
                    if (in_array($client_ip, $ips)) return true;
                }
            }
        }
    }
    
    return false;
}

// Handle failed login - increment counter and lock if needed
function handle_failed_login($conn, $user_id, $username) {
    // Check if failed_attempts column exists
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'failed_attempts'");
    if ($check->num_rows == 0) return -3; // Return default remaining attempts if column doesn't exist
    
    $max_attempts = (int)get_security_setting($conn, 'max_login_attempts', '3');
    $lockout_minutes = (int)get_security_setting($conn, 'lockout_duration', '15');
    
    // Increment failed attempts
    $stmt = $conn->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?");
    if (!$stmt) return -3;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    // Check if should lock
    $stmt = $conn->prepare("SELECT failed_attempts FROM users WHERE id = ?");
    if (!$stmt) return -3;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if ($row['failed_attempts'] >= $max_attempts) {
            $locked_until = date('Y-m-d H:i:s', strtotime("+{$lockout_minutes} minutes"));
            $stmt = $conn->prepare("UPDATE users SET status = 'inactive', locked_until = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $locked_until, $user_id);
                $stmt->execute();
            }
            log_activity($conn, $user_id, $username, 'account_locked', 'security', null, null, null, null, "Locked for {$lockout_minutes} minutes after {$max_attempts} failed attempts");
            return $lockout_minutes;
        }
        return -($max_attempts - $row['failed_attempts']); // Return remaining attempts as negative
    }
    return 0;
}

// Reset failed attempts on successful login
function reset_failed_attempts($conn, $user_id) {
    // Check if columns exist
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'failed_attempts'");
    if ($check->num_rows == 0) return;
    
    $ip = get_client_ip();
    $stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, status = 'active', locked_until = NULL, last_login = NOW(), last_ip = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $ip, $user_id);
        $stmt->execute();
    }
}

// Get session timeout setting
function get_session_timeout($conn) {
    $timeout = (int)get_security_setting($conn, 'session_timeout', '30');
    return $timeout * 60; // Convert to seconds
}

// Check if user is admin or supervisor
function is_admin_or_supervisor() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// supervisor_has_permission removed

// SESSION VALIDITY CHECK (Updated)
function check_session_validity() {
    global $conn;
    if (isset($_SESSION['user_id'])) {
        // Check if user is locked
        if (is_user_locked($conn, $_SESSION['user_id'])) {
            session_unset(); session_destroy(); return false;
        }
        // Admin and Supervisor bypass timeout
        if ($_SESSION['role'] === 'admin') return true;
        // Check timeout
        $timeout = get_session_timeout($conn);
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'session_timeout', 'security');
            session_unset(); session_destroy(); return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}

// Check if QC Dashboard is enabled
function is_qc_enabled($conn) {
    $result = $conn->query("SELECT setting_value FROM qc_settings WHERE setting_key = 'qc_enabled'");
    if ($result && $row = $result->fetch_assoc()) {
        return $row['setting_value'] === '1';
    }
    return false;
}

try {
    // ==========================================
    // 1. AUTHENTICATION (With Security)
    // ==========================================
    
    // Special login for Autotyper - No OTP required
    if ($action == 'autotyper_login') {
        $input_username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($input_username) || empty($password)) {
            send_json(['status' => 'error', 'message' => 'Username and password required']);
        }
        
        // Simple query with error handling
        $escaped_user = $conn->real_escape_string($input_username);
        $sql = "SELECT id, username, password, full_name, `role` FROM users WHERE username = '$escaped_user' AND is_active = 1 LIMIT 1";
        $result = $conn->query($sql);
        
        if (!$result) {
            send_json(['status' => 'error', 'message' => 'Database error']);
        }
        
        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password'])) {
                // Block QC users if QC Dashboard is disabled
                if ($row['role'] === 'qc') {
                    if (!is_qc_enabled($conn)) {
                        send_json(['status' => 'error', 'message' => 'QC Dashboard is Disable. Contact Raja.']);
                    }
                }
                
                // Direct login for autotyper - no OTP
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['full_name'] = $row['full_name'] ?? $row['username'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['is_active'] = 1;
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();
                
                send_json(['status' => 'success', 'role' => $row['role'], 'full_name' => $row['full_name'] ?? $row['username']]);
            } else {
                send_json(['status' => 'error', 'message' => 'Invalid credentials']);
            }
        } else {
            send_json(['status' => 'error', 'message' => 'User not found']);
        }
    }
    
    if ($action == 'login_init') {
        $input_username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($input_username) || empty($password)) {
            log_login_attempt($conn, $input_username, 'failed', 'Empty credentials');
            send_json(['status' => 'error', 'message' => 'Username and password required']);
        }
        
        // Check maintenance mode (allow admin to login)
        $maintenance_mode = get_security_setting($conn, 'maintenance_mode', '0');
        $maintenance_msg = get_security_setting($conn, 'maintenance_message', 'System is under maintenance. Please try again later.');
        
        // Check which columns exist
        $has_security_cols = $conn->query("SHOW COLUMNS FROM users LIKE 'status'")->num_rows > 0;
        
        if ($has_security_cols) {
            $stmt = $conn->prepare("SELECT id, username, password, full_name, `role`, phone, `status`, locked_until, failed_attempts FROM users WHERE LOWER(username) = LOWER(?)");
        } else {
            $stmt = $conn->prepare("SELECT id, username, password, full_name, `role`, phone FROM users WHERE LOWER(username) = LOWER(?)");
        }
        $stmt->bind_param("s", $input_username);
        $stmt->execute();
        $res = $stmt->get_result();
       
        if ($row = $res->fetch_assoc()) {
            // Block non-admin/supervisor users during maintenance
            if ($maintenance_mode === '1' && $row['role'] !== 'admin') {
                send_json(['status' => 'error', 'message' => $maintenance_msg]);
            }
            
            // Check if user is locked (only if columns exist)
            if ($has_security_cols) {
                $status = $row['status'] ?? 'active';
                $locked_until = $row['locked_until'] ?? null;
                
                if ($locked_until && strtotime($locked_until) > time()) {
                    $remaining = ceil((strtotime($locked_until) - time()) / 60);
                    log_login_attempt($conn, $input_username, 'blocked', 'Account locked');
                    send_json(['status' => 'error', 'message' => "Account locked. Try again in {$remaining} minutes."]);
                }
                
                // Check if user is inactive (not temporarily locked)
                if ($status === 'inactive' && (!$locked_until || strtotime($locked_until) <= time())) {
                    log_login_attempt($conn, $input_username, 'failed', 'Account inactive');
                    send_json(['status' => 'error', 'message' => 'Account is inactive. Contact Admin.']);
                }
            }
            
            // Check IP restriction
            if (!check_ip_allowed($conn, $row['id'])) {
                log_login_attempt($conn, $input_username, 'blocked', 'IP not allowed');
                send_json(['status' => 'error', 'message' => 'Access denied from this location.']);
            }
            
            $valid = false;
            if (password_verify($password, $row['password'])) $valid = true;
            elseif ($password === $row['password']) $valid = true;
            
            if ($valid) {
                // Reset failed attempts on successful login
                reset_failed_attempts($conn, $row['id']);
                
                // Block QC users if QC Dashboard is disabled
                if ($row['role'] === 'qc') {
                    if (!is_qc_enabled($conn)) {
                        log_login_attempt($conn, $input_username, 'blocked', 'QC Dashboard disabled');
                        send_json(['status' => 'error', 'message' => 'QC Dashboard is Disable. Contact Raja.']);
                    }
                }
                
                // Admin and Supervisor direct login (no OTP)
                if ($row['role'] === 'admin') {
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['full_name'] = $row['full_name'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['last_activity'] = time();
                    
                    log_login_attempt($conn, $input_username, 'success');
                    log_activity($conn, $row['id'], $row['username'], 'login', 'auth');
                    send_json(['status' => 'success', 'role' => $row['role']]);
                }
               
                // DEO needs OTP
                $phone = $row['phone'];
                if (!empty($phone)) {
                    $otp = rand(100000, 999999);
                    $_SESSION['temp_user'] = ['id' => $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'], 'role' => $row['role']];
                    $_SESSION['otp'] = $otp;
                    $_SESSION['otp_expiry'] = time() + 300;
                    $msg = WhatsAppTemplates::otpNotification($otp);
                    $sent = sendWhatsApp($phone, $msg);
                    if ($sent) {
                        send_json(['status' => 'otp_sent', 'message' => 'OTP sent to your WhatsApp number.']);
                    } else {
                        unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['temp_user']);
                        error_log("WhatsApp OTP failed for user: " . $row['username'] . " phone: " . $phone);
                        send_json(['status' => 'error', 'message' => 'WhatsApp OTP send nahi hua. Admin se contact karein ya thodi der baad try karein.']);
                    }
                } else {
                    send_json(['status' => 'error', 'message' => 'No phone linked. Contact Admin.']);
                }
            } else {
                // Handle failed login
                $lock_result = handle_failed_login($conn, $row['id'], $input_username);
                log_login_attempt($conn, $input_username, 'failed', 'Invalid password');
                
                if ($lock_result > 0) {
                    send_json(['status' => 'error', 'message' => "Account locked for {$lock_result} minutes due to too many failed attempts."]);
                } else {
                    $remaining = abs($lock_result);
                    send_json(['status' => 'error', 'message' => "Invalid credentials. {$remaining} attempts remaining."]);
                }
            }
        } else {
            log_login_attempt($conn, $input_username, 'failed', 'User not found');
            send_json(['status' => 'error', 'message' => 'User not found']);
        }
    }

    if ($action == 'verify_otp') {
        $user_otp = $_POST['otp'] ?? '';
        if (!isset($_SESSION['otp']) || !isset($_SESSION['temp_user'])) send_json(['status' => 'error', 'message' => 'Session Expired.']);
        
        // Check master OTP from database settings only
        $master_otp_enabled = get_security_setting($conn, 'master_otp_enabled', '0');
        $master_otp = get_security_setting($conn, 'master_otp', '');
        
        $otp_valid = false;
        if ((string)$user_otp === (string)$_SESSION['otp']) {
            $otp_valid = true;
        } elseif ($master_otp_enabled === '1' && !empty($master_otp) && $user_otp === $master_otp) {
            // Master OTP only works if enabled AND set in settings
            $otp_valid = true;
        }
        
        // Check OTP expiry
        if (isset($_SESSION['otp_expiry']) && time() > $_SESSION['otp_expiry']) {
            send_json(['status' => 'error', 'message' => 'OTP expired. Please try again.']);
        }
       
        if ($otp_valid) {
            $user = $_SESSION['temp_user'];
            
            // Block QC users if QC Dashboard is disabled
            if ($user['role'] === 'qc') {
                if (!is_qc_enabled($conn)) {
                    unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['temp_user']);
                    log_login_attempt($conn, $user['username'], 'blocked', 'QC Dashboard disabled');
                    send_json(['status' => 'error', 'message' => 'QC Dashboard is Disable. Contact Raja.']);
                }
            }
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['temp_user']);
            reset_failed_attempts($conn, $user['id']);
            log_login_attempt($conn, $user['username'], 'success');
            log_activity($conn, $user['id'], $user['username'], 'login', 'auth');
            send_json(['status' => 'success']);
        } else {
            send_json(['status' => 'error', 'message' => 'Invalid OTP']);
        }
    }

    if ($action == 'check_session') {
        if (check_session_validity()) {
            // Check maintenance mode for non-admin users
            $maintenance_mode = get_security_setting($conn, 'maintenance_mode', '0');
            $maintenance_msg = get_security_setting($conn, 'maintenance_message', 'System is under maintenance.');
            
            if ($maintenance_mode === '1' && $_SESSION['role'] !== 'admin') {
                send_json(['status'=>'maintenance', 'message'=>$maintenance_msg]);
            }
            
            $response = [
                'status'=>'logged_in', 
                'username'=>$_SESSION['username'], 
                'full_name'=>$_SESSION['full_name'], 
                'role'=>$_SESSION['role']
            ];
            
            // Include permissions for supervisor - load fresh from database
            if ($_SESSION['role'] === 'supervisor') {
                // Check if permissions column exists
                $check = $conn->query("SHOW COLUMNS FROM users LIKE 'permissions'");
                if ($check->num_rows > 0) {
                    $perm_stmt = $conn->prepare("SELECT permissions FROM users WHERE id = ?");
                    $perm_stmt->bind_param("i", $_SESSION['user_id']);
                    $perm_stmt->execute();
                    $perm_result = $perm_stmt->get_result();
                    if ($perm_row = $perm_result->fetch_assoc()) {
                        $response['permissions'] = json_decode($perm_row['permissions'] ?? '{}', true) ?: [];
                        $_SESSION['permissions'] = $response['permissions']; // Update session
                    } else {
                        $response['permissions'] = [];
                    }
                    $perm_stmt->close();
                } else {
                    $response['permissions'] = [];
                }
            }
            
            send_json($response);
        } else {
            send_json(['status'=>'logged_out']);
        }
    }

    if ($action == 'logout') {
        if (isset($_SESSION['user_id'])) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'logout', 'auth');
        }
        session_destroy();
        send_json(['status'=>'success']);
    }

    // ==========================================
    // SECURITY MANAGEMENT APIs
    // ==========================================
    
    // Get login history
    if ($action == 'get_login_history') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 100;
        $username_filter = $_POST['username'] ?? '';
        
        if (!empty($username_filter)) {
            $stmt = $conn->prepare("SELECT * FROM login_attempts WHERE username = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->bind_param("si", $username_filter, $limit);
        } else {
            $stmt = $conn->prepare("SELECT * FROM login_attempts ORDER BY created_at DESC LIMIT ?");
            $stmt->bind_param("i", $limit);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        send_json(['status' => 'success', 'data' => $logs]);
    }
    
    // Get activity logs
    if ($action == 'get_activity_logs') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 100;
        $username_filter = $_POST['username'] ?? '';
        $action_filter = $_POST['action_filter'] ?? '';
        
        $sql = "SELECT * FROM activity_logs WHERE 1=1";
        $params = [];
        $types = "";
        
        if (!empty($username_filter)) {
            $sql .= " AND username = ?";
            $params[] = $username_filter;
            $types .= "s";
        }
        if (!empty($action_filter)) {
            $sql .= " AND action = ?";
            $params[] = $action_filter;
            $types .= "s";
        }
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= "i";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        send_json(['status' => 'success', 'data' => $logs]);
    }
    
    // Get security settings
    if ($action == 'get_security_settings') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $result = $conn->query("SELECT * FROM security_settings ORDER BY setting_key");
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        send_json(['status' => 'success', 'data' => $settings]);
    }
    
    // Update security setting
    if ($action == 'update_security_setting') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $key = $_POST['key'] ?? '';
        $value = $_POST['value'] ?? '';
        
        if (empty($key)) {
            send_json(['status' => 'error', 'message' => 'Setting key required']);
        }
        
        $stmt = $conn->prepare("UPDATE security_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->bind_param("ss", $value, $key);
        $stmt->execute();
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'update_security_setting', 'security', null, null, null, $value, "Updated {$key}");
        send_json(['status' => 'success']);
    }
    
    // Unlock user account
    if ($action == 'unlock_user') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $user_id = (int)($_POST['user_id'] ?? 0);
        
        // Check if columns exist
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE users SET status = 'active', failed_attempts = 0, locked_until = NULL WHERE id = ?");
        } else {
            send_json(['status' => 'success', 'message' => 'Security columns not enabled']);
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'unlock_user', 'security', $user_id);
        send_json(['status' => 'success']);
    }
    
    // Get allowed IPs
    if ($action == 'get_allowed_ips') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $result = $conn->query("SELECT * FROM allowed_ips ORDER BY created_at DESC");
        $ips = [];
        while ($row = $result->fetch_assoc()) {
            $ips[] = $row;
        }
        send_json(['status' => 'success', 'data' => $ips, 'client_ip' => get_client_ip()]);
    }
    
    // Add allowed IP
    if ($action == 'add_allowed_ip') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $ip = trim($_POST['ip_address'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        
        if (empty($ip)) {
            send_json(['status' => 'error', 'message' => 'IP address required']);
        }
        
        $stmt = $conn->prepare("INSERT INTO allowed_ips (ip_address, description, created_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE description = ?, is_active = 1");
        $stmt->bind_param("ssis", $ip, $desc, $_SESSION['user_id'], $desc);
        $stmt->execute();
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'add_allowed_ip', 'security', null, null, null, $ip);
        send_json(['status' => 'success']);
    }
    
    // Remove allowed IP
    if ($action == 'remove_allowed_ip') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $id = (int)($_POST['id'] ?? 0);
        
        $stmt = $conn->prepare("DELETE FROM allowed_ips WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'remove_allowed_ip', 'security');
        send_json(['status' => 'success']);
    }

    // ==========================================
    // 2. IMAGE HANDLING
    // ==========================================
    if ($action == 'get_image') {
        $rec = trim($_POST['record_no'] ?? '');
        if (empty($rec)) send_json(['status'=>'error', 'message'=>'Record number required']);
        
        $stmt = $conn->prepare("SELECT image_filename, image_path FROM client_records WHERE record_no = ?");
        $stmt->bind_param("s", $rec);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $img = '';
        $img_path = '';
        if ($r = $res->fetch_assoc()) {
            $img = trim($r['image_filename'] ?? '');
            $img_path = trim($r['image_path'] ?? '');
        }
        
        $dir = './uploads/';
        $p2_dir = defined('IMAGE_DIR_PATH') ? IMAGE_DIR_PATH : './uploads/mapping_images/';
        $img_base = defined('IMAGE_BASE_PATH') ? IMAGE_BASE_PATH : '/uploads/mapping_images/';
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'JPG', 'JPEG', 'PNG'];
        
        // Method 1: P1 local - exact match
        if (!empty($img) && file_exists($dir . $img)) {
            send_json(['status'=>'success', 'image'=>$img]);
        }
        
        // Method 2: P1 local - try extensions
        if (!empty($img)) {
            foreach ($extensions as $ext) {
                if (file_exists($dir . $img . '.' . $ext)) {
                    send_json(['status'=>'success', 'image'=>$img . '.' . $ext]);
                }
            }
        }
        
        // Method 3: P1 local - record_no as filename
        foreach ($extensions as $ext) {
            if (file_exists($dir . $rec . '.' . $ext)) {
                send_json(['status'=>'success', 'image'=>$rec . '.' . $ext]);
            }
        }
        
        // Method 4: mapping_images folder - exact match
        if (!empty($img) && file_exists($p2_dir . $img)) {
            send_json(['status'=>'success', 'image'=>$img_base . $img, 'source'=>'p2']);
        }
        
        // Method 5: mapping_images folder - try extensions on img
        if (!empty($img)) {
            foreach ($extensions as $ext) {
                if (file_exists($p2_dir . $img . '.' . $ext)) {
                    send_json(['status'=>'success', 'image'=>$img_base . $img . '.' . $ext, 'source'=>'p2']);
                }
            }
        }
        
        // Method 6: mapping_images folder - record_no as filename
        foreach ($extensions as $ext) {
            if (file_exists($p2_dir . $rec . '.' . $ext)) {
                send_json(['status'=>'success', 'image'=>$img_base . $rec . '.' . $ext, 'source'=>'p2']);
            }
        }
        
        // Method 7: Check record_image_map table
        $stmt2 = $conn->prepare("SELECT image_no, image_path FROM record_image_map WHERE record_no = ?");
        $stmt2->bind_param("s", $rec);
        $stmt2->execute();
        $map_res = $stmt2->get_result();
        if ($map_row = $map_res->fetch_assoc()) {
            $map_img = trim($map_row['image_no'] ?? '');
            
            // Try exact match
            if (!empty($map_img) && file_exists($p2_dir . $map_img)) {
                send_json(['status'=>'success', 'image'=>$img_base . $map_img, 'source'=>'p2']);
            }
            
            // Try with extensions
            if (!empty($map_img)) {
                foreach ($extensions as $ext) {
                    if (file_exists($p2_dir . $map_img . '.' . $ext)) {
                        send_json(['status'=>'success', 'image'=>$img_base . $map_img . '.' . $ext, 'source'=>'p2']);
                    }
                }
            }
        }
        
        // Method 8: Glob search
        $search = !empty($img) ? $img : $rec;
        $matches = glob($p2_dir . $search . '.*');
        if (!empty($matches)) {
            send_json(['status'=>'success', 'image'=>$img_base . basename($matches[0]), 'source'=>'p2']);
        }
        
        send_json(['status'=>'error', 'message'=>'Image not found']);
    }

    // ==========================================
    // BULK IMAGE LOOKUP (Excel)
    // ==========================================
    if ($action == 'bulk_image_lookup') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        
        $record_numbers = isset($_POST['record_numbers']) ? json_decode($_POST['record_numbers'], true) : [];
        
        if (empty($record_numbers)) {
            send_json(['status'=>'error', 'message'=>'No record numbers provided']);
        }
        
        $result = [];
        
        // Method 1: Check client_records table
        $placeholders = implode(',', array_fill(0, count($record_numbers), '?'));
        $types = str_repeat('s', count($record_numbers));
        
        $stmt = $conn->prepare("SELECT record_no, image_filename FROM client_records WHERE record_no IN ($placeholders)");
        $stmt->bind_param($types, ...$record_numbers);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['image_filename'])) {
                $result[$row['record_no']] = $row['image_filename'];
            }
        }
        $stmt->close();
        
        // Method 2: Check record_image_map table for remaining
        $remaining = array_diff($record_numbers, array_keys($result));
        
        if (!empty($remaining)) {
            $placeholders2 = implode(',', array_fill(0, count($remaining), '?'));
            $types2 = str_repeat('s', count($remaining));
            $remaining_arr = array_values($remaining);
            
            $stmt2 = $conn->prepare("SELECT record_no, image_no FROM record_image_map WHERE record_no IN ($placeholders2)");
            $stmt2->bind_param($types2, ...$remaining_arr);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            
            while ($row = $res2->fetch_assoc()) {
                if (!empty($row['image_no'])) {
                    $result[$row['record_no']] = $row['image_no'];
                }
            }
            $stmt2->close();
        }
        
        send_json(['status'=>'success', 'data'=>$result, 'total'=>count($record_numbers), 'found'=>count($result)]);
    }

    // ==========================================
    // 3. DATA HANDLING
    // ==========================================
    if ($action == 'load_data') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Session timeout']);
       
        $sql = "SELECT id, record_no, username, assigned_to, row_status, is_reported, report_count,
                       kyc_number, name, guardian_name, gender, marital_status, dob,
                       address, landmark, city, zip_code, city_of_birth, nationality,
                       photo_attachment, residential_status, occupation,
                       officially_valid_documents, annual_income, broker_name,
                       sub_broker_code, bank_serial_no, second_applicant_name,
                       amount_received_from, amount, arn_no, second_address,
                       occupation_profession, remarks,
                       edited_fields, time_spent, updated_at,
                       IFNULL(deo_done_at, NULL) as deo_done_at,
                       IFNULL(qc_done_at, NULL) as qc_done_at,
                       IFNULL(completed_at, NULL) as completed_at
                FROM client_records";
        $where = [];
       
        // Admin and Supervisor can see all records, DEO only sees their own
        if ($_SESSION['role'] === 'deo') {
            // Check both assigned_to and username columns for DEO
            $username = $conn->real_escape_string($_SESSION['username']);
            $where[] = "(assigned_to = '$username' OR username = '$username')";
        } else {
            // Admin and Supervisor can filter by user
            if (!empty($_POST['filter_user'])) {
                $filter_user = $conn->real_escape_string($_POST['filter_user']);
                $where[] = "(assigned_to = '$filter_user' OR username = '$filter_user')";
            }
        }
       
        if (!empty($_POST['status_filter'])) {
            if ($_POST['status_filter'] === 'done') $where[] = "row_status = 'done'";
            elseif ($_POST['status_filter'] === 'completed') $where[] = "row_status = 'Completed'";
            elseif ($_POST['status_filter'] === 'pending') $where[] = "row_status = 'pending'";
            elseif ($_POST['status_filter'] === 'deo_done') $where[] = "row_status IN ('deo_done', 'pending_qc', 'done')";
            elseif ($_POST['status_filter'] === 'qc_done') $where[] = "row_status IN ('qc_done', 'qc_approved')";
            elseif ($_POST['status_filter'] === 'invalid') $where[] = "(is_invalid_record = 1 OR record_no NOT REGEXP '^[0-9]+$')";
        }
       
        if (!empty($_POST['start_date'])) $where[] = "DATE(updated_at) >= '" . $conn->real_escape_string($_POST['start_date']) . "'";
        if (!empty($_POST['end_date'])) $where[] = "DATE(updated_at) <= '" . $conn->real_escape_string($_POST['end_date']) . "'";
        if (count($where) > 0) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY id ASC LIMIT 10000";
       
        $res = $conn->query($sql);
        if (!$res) {
            send_json(['status'=>'error', 'message'=>'Query error: ' . $conn->error]);
        }
        
        // Gather all record_nos to check critical_errors dynamically
        $rows = [];
        $rec_nos = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
            $rec_nos[] = $r['record_no'];
        }
        
        // Build reported map from critical_errors (source of truth for P2 reports)
        if (!empty($rec_nos)) {
            $rn_list = implode("','", array_map([$conn, 'real_escape_string'], $rec_nos));
            $ce_res  = $conn->query("SELECT record_no, COUNT(*) as cnt FROM critical_errors WHERE record_no IN ('$rn_list') AND status IN ('pending','admin_reviewed') GROUP BY record_no");
            $ce_map  = [];
            if ($ce_res) while ($cr = $ce_res->fetch_assoc()) $ce_map[$cr['record_no']] = (int)$cr['cnt'];
            
            // Merge into rows
            foreach ($rows as &$row) {
                $rn = $row['record_no'];
                if (isset($ce_map[$rn]) && $ce_map[$rn] > 0) {
                    $row['is_reported']  = 1;
                    $row['report_count'] = $ce_map[$rn];
                }
            }
            unset($row);
        }
        
        send_json($rows);
    }
    
    // Real-time sync - Get only changed records since last sync
    if ($action == 'sync_changes') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Session timeout']);
        
        $last_sync = $_POST['last_sync'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
        
        $sql = "SELECT * FROM client_records WHERE updated_at > ?";
        $where_extra = [];
        
        // DEO only sees their records
        if ($_SESSION['role'] === 'deo') {
            $sql .= " AND assigned_to = ?";
        }
        
        $sql .= " ORDER BY updated_at DESC LIMIT 500";
        
        if ($_SESSION['role'] === 'deo') {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $last_sync, $_SESSION['username']);
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $last_sync);
        }
        
        $stmt->execute();
        $res = $stmt->get_result();
        $changes = [];
        $chg_rec_nos = [];
        while ($r = $res->fetch_assoc()) {
            $changes[] = $r;
            $chg_rec_nos[] = $r['record_no'];
        }
        
        // Overlay is_reported from report_to_admin on changed records
        if (!empty($chg_rec_nos)) {
            $rn_chg = implode("','", array_map([$conn, 'real_escape_string'], $chg_rec_nos));
            $rta_chg = $conn->query("SELECT `record_no`, COUNT(*) as cnt FROM `report_to_admin` WHERE `record_no` IN ('$rn_chg') AND `status`='open' GROUP BY `record_no`");
            $rta_chg_map = [];
            if ($rta_chg) while ($cr = $rta_chg->fetch_assoc()) $rta_chg_map[$cr['record_no']] = (int)$cr['cnt'];
            foreach ($changes as &$chgr) {
                $rn = $chgr['record_no'];
                // Use DB value (is_reported/report_count already updated by resolve)
                // But overlay if report_to_admin still has open entries
                if (isset($rta_chg_map[$rn]) && $rta_chg_map[$rn] > 0) {
                    $chgr['is_reported']  = 1;
                    $chgr['report_count'] = $rta_chg_map[$rn];
                }
                // If report_count=0 in client_records, ensure is_reported=0 too
                if ((int)($chgr['report_count'] ?? 0) === 0) {
                    $chgr['is_reported'] = 0;
                }
            }
            unset($chgr);
        }
        
        // Get current stats
        $stats = [];
        $stats_sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN row_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN row_status = 'done' THEN 1 ELSE 0 END) as done,
            SUM(CASE WHEN row_status = 'Completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN row_status = 'Completed' AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) as today_completed
            FROM client_records";
        
        if ($_SESSION['role'] === 'deo') {
            $stats_sql .= " WHERE username = '" . $conn->real_escape_string($_SESSION['username']) . "'";
        }
        
        $stats_res = $conn->query($stats_sql);
        if ($stats_row = $stats_res->fetch_assoc()) {
            $stats = $stats_row;
        }
        
        // Also return qc_enabled + report count for realtime visibility control
        $qc_en = is_qc_enabled($conn) ? '1' : '0';
        // Count open reports: sirf report_to_admin
        $rpt_open = (int)$conn->query("SELECT COUNT(*) as cnt FROM report_to_admin WHERE status='open'")->fetch_assoc()['cnt'];
        
        // QC-specific counts
        $qc_done_count = (int)$conn->query("SELECT COUNT(*) as cnt FROM client_records WHERE row_status IN ('qc_done','qc_approved')")->fetch_assoc()['cnt'];
        $second_qc_pending = (int)$conn->query("SELECT COUNT(*) as cnt FROM client_records WHERE row_status IN ('done','deo_done','pending_qc')")->fetch_assoc()['cnt'];
        $stats['second_qc_done']    = $qc_done_count;
        $stats['second_qc_pending'] = $second_qc_pending;
        
        send_json([
            'status'       => 'success',
            'changes'      => $changes,
            'stats'        => $stats,
            'qc_enabled'   => $qc_en,
            'report_count' => $rpt_open,
            'server_time'  => date('Y-m-d H:i:s'),
            'change_count' => count($changes)
        ]);
    }
    
    // Get real-time stats only (lightweight)
    if ($action == 'get_live_stats') {
        if (!check_session_validity()) send_json(['status'=>'error']);
        
        $stats_sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN row_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN row_status = 'done' THEN 1 ELSE 0 END) as done,
            SUM(CASE WHEN row_status = 'Completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN row_status = 'Completed' AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) as today_completed
            FROM client_records";
        
        if ($_SESSION['role'] === 'deo') {
            $stats_sql .= " WHERE username = '" . $conn->real_escape_string($_SESSION['username']) . "'";
        }
        
        $res = $conn->query($stats_sql);
        $stats = $res->fetch_assoc();
        
        // Get last update time
        $last_update_sql = "SELECT MAX(updated_at) as last_update FROM client_records";
        if ($_SESSION['role'] === 'deo') {
            $last_update_sql .= " WHERE username = '" . $conn->real_escape_string($_SESSION['username']) . "'";
        }
        $last_res = $conn->query($last_update_sql);
        $last_row = $last_res->fetch_assoc();
        
        send_json([
            'status' => 'success',
            'stats' => $stats,
            'last_update' => $last_row['last_update'] ?? null,
            'server_time' => date('Y-m-d H:i:s')
        ]);
    }

    // ==========================================
    // QC SYSTEM FUNCTIONS
    // ==========================================
    
    // QC Load Data - Get records for QC dashboard (filtered by DEO)
    if ($action == 'qc_load_data') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        if ($_SESSION['role'] !== 'qc') send_json(['status'=>'error', 'message'=>'QC access required']);
        
        $deo_username = isset($_POST['deo_username']) ? $conn->real_escape_string($_POST['deo_username']) : '';
        
        if (empty($deo_username)) {
            send_json([]);
        }
        
        // Show First QC Done (deo_done/pending_qc/done) + Second QC Done + Completed
        // NOTE: 'done' included because old records saved when QC was disabled also use 'done'
        $sql = "SELECT * FROM client_records 
                WHERE (username = '$deo_username' OR assigned_to = '$deo_username')
                AND row_status IN ('deo_done','pending_qc','done','qc_done','qc_approved','Completed','qc_rejected')
                ORDER BY 
                    CASE row_status 
                        WHEN 'deo_done' THEN 1 
                        WHEN 'pending_qc' THEN 1 
                        WHEN 'qc_done' THEN 2 
                        WHEN 'qc_approved' THEN 2 
                        WHEN 'Completed' THEN 3 
                        WHEN 'qc_rejected' THEN 4
                        ELSE 5
                    END, updated_at DESC
                LIMIT 1000";
        
        error_log("QC Load Data SQL: $sql"); // Debug
        
        $result = $conn->query($sql);
        $data = [];
        $rec_nos_qc = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
                $rec_nos_qc[] = $row['record_no'];
            }
        }
        
        // Overlay is_reported from critical_errors
        if (!empty($rec_nos_qc)) {
            $rn_list_qc = implode("','", array_map([$conn, 'real_escape_string'], $rec_nos_qc));
            $ce_res_qc  = $conn->query("SELECT record_no, COUNT(*) as cnt FROM critical_errors WHERE record_no IN ('$rn_list_qc') AND status IN ('pending','admin_reviewed') GROUP BY record_no");
            $ce_map_qc  = [];
            if ($ce_res_qc) while ($cr = $ce_res_qc->fetch_assoc()) $ce_map_qc[$cr['record_no']] = (int)$cr['cnt'];
            foreach ($data as &$row) {
                $rn = $row['record_no'];
                if (isset($ce_map_qc[$rn]) && $ce_map_qc[$rn] > 0) {
                    $row['is_reported']  = 1;
                    $row['report_count'] = $ce_map_qc[$rn];
                }
            }
            unset($row);
        }
        
        send_json($data);
    }
    
    // QC Lock Record - Prevent multiple QC users from editing same record
    if ($action == 'qc_lock_record') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        if ($_SESSION['role'] !== 'qc') send_json(['status'=>'error', 'message'=>'QC access required']);
        
        $record_id = intval($_POST['record_id'] ?? 0);
        $user_id = $_SESSION['user_id'];
        
        // Check if already locked by another user
        $check = $conn->query("SELECT qc_locked_by, u.full_name as locked_by_name 
                              FROM client_records cr 
                              LEFT JOIN users u ON cr.qc_locked_by = u.id 
                              WHERE cr.id = $record_id");
        if ($row = $check->fetch_assoc()) {
            if ($row['qc_locked_by'] && $row['qc_locked_by'] != $user_id) {
                // Check if lock is older than 10 minutes (stale lock)
                $stale_check = $conn->query("SELECT qc_locked_at FROM client_records WHERE id = $record_id AND qc_locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
                if ($stale_check->num_rows == 0) {
                    send_json(['status' => 'locked', 'locked_by' => $row['locked_by_name'] ?: 'Another QC User']);
                }
            }
        }
        
        // Lock the record
        $conn->query("UPDATE client_records SET qc_locked_by = $user_id, qc_locked_at = NOW() WHERE id = $record_id");
        send_json(['status' => 'success']);
    }
    
    // QC Unlock Record
    if ($action == 'qc_unlock_record') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        
        $record_id = intval($_POST['record_id'] ?? 0);
        $conn->query("UPDATE client_records SET qc_locked_by = NULL, qc_locked_at = NULL WHERE id = $record_id");
        send_json(['status' => 'success']);
    }
    
    // QC Save and Done - Mark as qc_done (sends to Autotyper)
    if ($action == 'qc_save_done') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        if ($_SESSION['role'] !== 'qc') send_json(['status'=>'error', 'message'=>'QC access required']);
        
        $id = intval($_POST['id']);
        $user = $_SESSION['username'];
        $user_id = $_SESSION['user_id'];
        $full_name = $_SESSION['full_name'] ?? $user;
        
        // First, ensure the enum has qc_done
        $conn->query("ALTER TABLE client_records MODIFY COLUMN row_status ENUM('pending','done','deo_done','qc_done','Completed','flagged','in_progress','corrected','pending_qc','qc_approved','qc_rejected') DEFAULT 'pending'");
        
        $inputs = ['kyc_number','name','guardian_name','gender','marital_status','dob','address','landmark','city','zip_code','city_of_birth','nationality','photo_attachment','residential_status','occupation','officially_valid_documents','annual_income','broker_name','sub_broker_code','bank_serial_no','second_applicant_name','amount_received_from','amount','arn_no','second_address','occupation_profession','remarks'];
        
        $updates = [];
        foreach ($inputs as $f) {
            $val = isset($_POST[$f]) ? $conn->real_escape_string(trim($_POST[$f])) : '';
            $updates[] = "$f = '$val'";
        }
        
        $time_spent = intval($_POST['time_spent'] ?? 0);
        $full_name_escaped = $conn->real_escape_string($full_name);
        
        $sql = "UPDATE client_records SET " . implode(', ', $updates) . ",
                time_spent = $time_spent,
                row_status = 'qc_done',
                qc_user_id = $user_id,
                qc_by = '$full_name_escaped',
                qc_done_at = NOW(),
                qc_locked_by = NULL,
                qc_locked_at = NULL,
                updated_at = NOW()
                WHERE id = $id";
        
        error_log("QC Save Done SQL: $sql"); // Debug log
        
        if ($conn->query($sql)) {
            if ($conn->affected_rows > 0) {
                // Log QC activity
                $record = $conn->query("SELECT record_no, username FROM client_records WHERE id = $id")->fetch_assoc();
                $conn->query("INSERT INTO qc_logs (qc_id, record_id, record_no, deo_username, action) VALUES ($user_id, $id, '{$record['record_no']}', '{$record['username']}', 'approved')");
                
                send_json(['status' => 'success', 'qc_by' => $full_name, 'new_status' => 'qc_done']);
            } else {
                send_json(['status' => 'error', 'message' => 'No rows updated. Record may not exist.']);
            }
        } else {
            error_log("QC Save Done Error: " . $conn->error);
            send_json(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        }
    }
    
    // QC Update Cell
    if ($action == 'qc_update_cell') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        if ($_SESSION['role'] !== 'qc') send_json(['status'=>'error', 'message'=>'QC access required']);
        
        $id = intval($_POST['id']);
        $column = $conn->real_escape_string($_POST['column']);
        $value = $conn->real_escape_string($_POST['value']);
        
        $allowed = ['kyc_number','name','guardian_name','gender','marital_status','dob','address','landmark','city','zip_code','city_of_birth','nationality','photo_attachment','residential_status','occupation','officially_valid_documents','annual_income','broker_name','sub_broker_code','bank_serial_no','second_applicant_name','amount_received_from','amount','arn_no','second_address','occupation_profession','remarks'];
        
        if (!in_array($column, $allowed)) {
            send_json(['status' => 'error', 'message' => 'Invalid column']);
        }
        
        $sql = "UPDATE client_records SET $column = '$value', updated_at = NOW() WHERE id = $id";
        if ($conn->query($sql)) {
            send_json(['status' => 'success']);
        } else {
            send_json(['status' => 'error', 'message' => $conn->error]);
        }
    }
    
    // Toggle QC System (Admin only)
    if ($action == 'toggle_qc_system') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status'=>'error', 'message'=>'Admin access required']);
        }
        
        $enabled = $_POST['enabled'] === '1' ? '1' : '0';
        $conn->query("CREATE TABLE IF NOT EXISTS qc_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $conn->query("INSERT INTO qc_settings (setting_key, setting_value) VALUES ('qc_enabled', '$enabled') ON DUPLICATE KEY UPDATE setting_value = '$enabled'");
        
        send_json(['status' => 'success', 'qc_enabled' => $enabled]);
    }
    
    // Get QC Status (for Admin dashboard)
    if ($action == 'get_qc_status') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        
        $qc_enabled = is_qc_enabled($conn) ? '1' : '0';
        
        // Get QC stats
        $pending_qc = $conn->query("SELECT COUNT(*) as cnt FROM client_records WHERE row_status IN ('deo_done', 'pending_qc')")->fetch_assoc()['cnt'];
        $qc_done = $conn->query("SELECT COUNT(*) as cnt FROM client_records WHERE row_status IN ('qc_done', 'qc_approved')")->fetch_assoc()['cnt'];
        $today_qc_done = $conn->query("SELECT COUNT(*) as cnt FROM client_records WHERE row_status IN ('qc_done', 'qc_approved') AND DATE(qc_done_at) = CURDATE()")->fetch_assoc()['cnt'];
        
        send_json([
            'status' => 'success',
            'qc_enabled' => $qc_enabled,
            'stats' => [
                'pending_qc' => $pending_qc,
                'qc_done' => $qc_done,
                'today_qc_done' => $today_qc_done
            ]
        ]);
    }

    if ($action == 'update_row') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        // Ensure deo_done_at column exists
        $conn->query("ALTER TABLE client_records ADD COLUMN IF NOT EXISTS deo_done_at DATETIME DEFAULT NULL");
       
        $id = intval($_POST['id']);
        if ($id <= 0) {
            send_json(['status'=>'error', 'message'=>'Invalid record ID']);
        }
        
        $user = $_SESSION['username'];
        $inputs = ['kyc_number','name','guardian_name','gender','marital_status','dob','address','landmark','city','zip_code','city_of_birth','nationality','photo_attachment','residential_status','occupation','officially_valid_documents','annual_income','broker_name','sub_broker_code','bank_serial_no','second_applicant_name','amount_received_from','amount','arn_no','second_address','occupation_profession','remarks'];
        $params = [];
        foreach ($inputs as $f) {
            $params[] = isset($_POST[$f]) ? trim($_POST[$f]) : '';
        }
        $time_spent = intval($_POST['time_spent'] ?? 0);
        $params[] = $time_spent;
        $params[] = $user;
        $params[] = $id;
        
        // Check if QC is enabled - determines target status
        $qc_enabled = is_qc_enabled($conn);
        $target_status = $qc_enabled ? 'deo_done' : 'done';
       
        $sql = "UPDATE client_records SET
                kyc_number=?, name=?, guardian_name=?, gender=?, marital_status=?, dob=?, address=?,
                landmark=?, city=?, zip_code=?, city_of_birth=?, nationality=?, photo_attachment=?,
                residential_status=?, occupation=?, officially_valid_documents=?, annual_income=?,
                broker_name=?, sub_broker_code=?, bank_serial_no=?, second_applicant_name=?,
                amount_received_from=?, amount=?, arn_no=?, second_address=?, occupation_profession=?,
                remarks=?, time_spent=?, last_updated_by=?,
                row_status = IF(row_status = 'Completed', 'Completed', IF(row_status IN ('qc_done', 'qc_approved'), row_status, '$target_status')),
                deo_done_at = IF(deo_done_at IS NULL AND '$target_status' IN ('deo_done','done'), NOW(), deo_done_at),
                updated_at=NOW()
                WHERE id=?";
        
        // Use transaction for reliable save
        try {
            db_begin_transaction();
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare error: ' . $conn->error);
            }
            
            // 27 strings + 1 int (time_spent) + 1 string (user) + 1 int (id) = 30 params
            $types = str_repeat("s", 27) . "isi";
            $stmt->bind_param($types, ...$params);
           
            if (!$stmt->execute()) {
                throw new Exception('Execute error: ' . $stmt->error);
            }
            
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            
            db_commit();
            
            // Log the activity
            log_activity($conn, $_SESSION['user_id'], $user, 'Update Record', 'client_records', $id, null, null, null, 'DEO saved record');
            send_json(['status'=>'success', 'message'=>'Record saved successfully', 'affected'=>$affected_rows, 'qc_enabled' => $qc_enabled]);
            
        } catch (Exception $e) {
            db_rollback();
            error_log("Save record error for ID $id: " . $e->getMessage());
            send_json(['status'=>'error', 'message'=>$e->getMessage()]);
        }
    }

    if ($action == 'update_cell') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        $id = intval($_POST['id']);
        $col = $_POST['column'];
        $val = $_POST['value'];
        $user = $_SESSION['username'];
        
        // Whitelist allowed columns for security
        $allowed_cols = ['kyc_number','name','guardian_name','gender','marital_status','dob','address','landmark','city','zip_code','city_of_birth','nationality','photo_attachment','residential_status','occupation','officially_valid_documents','annual_income','broker_name','sub_broker_code','bank_serial_no','second_applicant_name','amount_received_from','amount','arn_no','second_address','occupation_profession','remarks'];
        
        if (!in_array($col, $allowed_cols)) {
            send_json(['status'=>'error', 'message'=>'Invalid column']);
        }
        
        try {
            db_begin_transaction();
            
            $res = $conn->query("SELECT edited_fields FROM client_records WHERE id=$id FOR UPDATE");
            $row = $res->fetch_assoc();
            $edited = json_decode($row['edited_fields']??'[]', true);
            if (!in_array($col, $edited)) {
                $edited[] = $col;
            }
            
            $stmt = $conn->prepare("UPDATE client_records SET `$col` = ?, last_updated_by = ?, edited_fields = ?, updated_at = NOW() WHERE id = ?");
            $jsonEdited = json_encode($edited);
            $stmt->bind_param("sssi", $val, $user, $jsonEdited, $id);
            
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            
            $stmt->close();
            db_commit();
            send_json(['status'=>'success']);
            
        } catch (Exception $e) {
            db_rollback();
            error_log("update_cell error for ID $id, col $col: " . $e->getMessage());
            send_json(['status'=>'error', 'message'=>$e->getMessage()]);
        }
    }

    // Update time spent on a record
    if ($action == 'update_time') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        $id = intval($_POST['id']);
        $seconds = intval($_POST['seconds'] ?? 0);
        $stmt = $conn->prepare("UPDATE client_records SET time_spent = time_spent + ? WHERE id = ?");
        $stmt->bind_param("ii", $seconds, $id);
        if ($stmt->execute()) send_json(['status'=>'success']);
        else send_json(['status'=>'error', 'message'=>$stmt->error]);
    }

    // ==========================================
    // EDIT RECORD NUMBER
    // ==========================================
    if ($action == 'edit_record_no') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        
        // Ensure column exists
        $id = intval($_POST['id'] ?? 0);
        $old_record_no = trim($_POST['old_record_no'] ?? '');
        $new_record_no = trim($_POST['new_record_no'] ?? '');
        $user = $_SESSION['username'] ?? 'admin';
        
        if (empty($new_record_no)) {
            send_json(['status'=>'error', 'message'=>'New record number cannot be empty']);
        }
        
        // Check if new record_no is purely numeric
        if (!preg_match('/^[0-9]+$/', $new_record_no)) {
            send_json(['status'=>'error', 'message'=>'Record number must be purely numeric (0-9 only)']);
        }
        
        // Check if new record_no already exists
        $check_stmt = $conn->prepare("SELECT id FROM client_records WHERE record_no = ? AND id != ?");
        $check_stmt->bind_param("si", $new_record_no, $id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            send_json(['status'=>'error', 'message'=>'Record number already exists']);
        }
        
        // Update record_no
        $stmt = $conn->prepare("UPDATE client_records SET record_no = ?, is_invalid_record = 0, last_updated_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssi", $new_record_no, $user, $id);
        
        if ($stmt->execute()) {
            // Also update record_image_map if exists
            $update_map = $conn->prepare("UPDATE record_image_map SET record_no = ? WHERE record_no = ?");
            $update_map->bind_param("ss", $new_record_no, $old_record_no);
            $update_map->execute();
            
            // Log activity
            log_activity($conn, $_SESSION['user_id'], $user, 'Edit Record No', 'client_records', $id, $new_record_no, $old_record_no, $new_record_no, "Changed record_no from $old_record_no to $new_record_no");
            
            send_json(['status'=>'success', 'message'=>'Record number updated successfully']);
        } else {
            send_json(['status'=>'error', 'message'=>$stmt->error]);
        }
    }
    
    // ==========================================
    // MARK/UNMARK RECORD AS INVALID
    // ==========================================
    if ($action == 'toggle_invalid_record') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        
        // Ensure column exists
        $id = intval($_POST['id'] ?? 0);
        $mark_invalid = intval($_POST['mark_invalid'] ?? 1);
        $user = $_SESSION['username'] ?? 'admin';
        
        $stmt = $conn->prepare("UPDATE client_records SET is_invalid_record = ?, last_updated_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("isi", $mark_invalid, $user, $id);
        
        if ($stmt->execute()) {
            $action_text = $mark_invalid ? 'Marked as invalid' : 'Unmarked as invalid';
            log_activity($conn, $_SESSION['user_id'], $user, $action_text, 'client_records', $id, null, null, null, null);
            send_json(['status'=>'success', 'message'=>$action_text]);
        } else {
            send_json(['status'=>'error', 'message'=>$stmt->error]);
        }
    }
    
    // ==========================================
    // AUTO-DETECT AND MARK INVALID RECORDS (Non-numeric record_no)
    // ==========================================
    if ($action == 'auto_mark_invalid_records') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        
        $user = $_SESSION['username'] ?? 'admin';
        
        // First ensure column exists
        // Mark records where record_no contains non-numeric characters
        $sql = "UPDATE client_records SET is_invalid_record = 1, last_updated_by = '$user', updated_at = NOW() WHERE record_no NOT REGEXP '^[0-9]+$' AND (is_invalid_record = 0 OR is_invalid_record IS NULL)";
        
        if ($conn->query($sql)) {
            $affected = $conn->affected_rows;
            log_activity($conn, $_SESSION['user_id'], $user, 'Auto-mark invalid records', 'client_records', null, null, null, null, "Marked $affected records as invalid");
            send_json(['status'=>'success', 'message'=>"$affected records marked as invalid"]);
        } else {
            send_json(['status'=>'error', 'message'=>$conn->error]);
        }
    }
    
    // ==========================================
    // GET INVALID RECORDS COUNT
    // ==========================================
    if ($action == 'get_invalid_records_count') {
        // Ensure column exists first
        $result = $conn->query("SELECT COUNT(*) as count FROM client_records WHERE is_invalid_record = 1 OR record_no NOT REGEXP '^[0-9]+$'");
        $count = $result->fetch_assoc()['count'];
        send_json(['status'=>'success', 'count'=>$count]);
    }

    // ==========================================
    // 4. BATCH MARK COMPLETED - MULTI-USER FIX
    // ==========================================
    if ($action == 'batch_mark_completed_by_record_no') {
        // Basic auth: require either session or valid autotyper token
        $autotyper_token = $_POST['token'] ?? $_SERVER['HTTP_X_AUTOTYPER_TOKEN'] ?? '';
        if (!check_session_validity() && empty($autotyper_token)) {
            send_json(['status'=>'error', 'message'=>'Authentication required']);
        }
        $json_records = $_POST['record_nos'] ?? '[]';
        $records = json_decode($json_records, true);
        $user = $_SESSION['username'] ?? 'AutoTyper';

        if (!is_array($records) || empty($records)) {
            send_json(['status'=>'success', 'message'=>'No records to update']);
        }

        $sanitized_recs = [];
        foreach ($records as $r) {
            $sanitized_recs[] = "'" . $conn->real_escape_string(trim($r)) . "'";
        }

        if (!empty($sanitized_recs)) {
            $in_clause = implode(',', $sanitized_recs);
            $sql = "UPDATE client_records SET row_status = 'Completed', completed_at = NOW(), last_updated_by = '$user', updated_at = NOW() WHERE record_no IN ($in_clause)";

            if ($conn->query($sql)) {
                send_json(['status'=>'success', 'message'=>'Batch Updated', 'count' => $conn->affected_rows]);
            } else {
                send_json(['status'=>'error', 'message'=>$conn->error]);
            }
        } else {
            send_json(['status'=>'success', 'message'=>'Nothing to update']);
        }
    }

    // ==========================================
    // 5. AUTOTYPER DATA FETCH
    // ==========================================
        // ── Get User Info (name, role) + Image for multiple Record Nos ──
    if ($action == 'get_record_user_info') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $record_nos = json_decode($_POST['record_nos'] ?? '[]', true);
        if (empty($record_nos) || !is_array($record_nos)) {
            send_json(['status'=>'success','data'=>[]]);
        }
        
        $escaped = array_map([$conn, 'real_escape_string'], array_map('strval', $record_nos));
        $in = "'" . implode("','", $escaped) . "'";
        
        $data = [];
        
        $r = $conn->query("
            SELECT cr.record_no,
                   COALESCE(cr.assigned_to, cr.username) as username,
                   u.full_name,
                   u.role,
                   COALESCE(rim.image_no, cr.image_filename, '') as image_no
            FROM client_records cr
            LEFT JOIN users u ON u.username = COALESCE(cr.assigned_to, cr.username)
            LEFT JOIN record_image_map rim ON rim.record_no = cr.record_no
            WHERE cr.record_no IN ($in)
        ");
        
        if ($r) while ($row = $r->fetch_assoc()) {
            $data[$row['record_no']] = [
                'username'  => $row['username']  ?? '',
                'full_name' => $row['full_name']  ?? $row['username'] ?? '',
                'role'      => $row['role']       ?? 'deo',
                'image_no'  => str_replace('_enc', '', $row['image_no'] ?? ''),
            ];
        }
        
        send_json(['status'=>'success','data'=>$data]);
    }

        // ── Get Image Names for multiple Record Nos (bulk lookup) ──
    if ($action == 'get_images_for_records') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $record_nos = json_decode($_POST['record_nos'] ?? '[]', true);
        if (empty($record_nos) || !is_array($record_nos)) {
            send_json(['status'=>'success','images'=>[]]);
        }
        
        $images = [];
        $escaped = array_map([$conn, 'real_escape_string'], array_map('strval', $record_nos));
        $in = "'" . implode("','", $escaped) . "'";
        
        // Try record_image_map first
        $r = $conn->query("SELECT record_no, image_no FROM record_image_map WHERE record_no IN ($in)");
        if ($r) while ($row = $r->fetch_assoc()) {
            $images[$row['record_no']] = str_replace('_enc', '', $row['image_no']);
        }
        
        // Fallback: client_records.image_filename
        $missing = array_diff($record_nos, array_keys($images));
        if (!empty($missing)) {
            $esc2 = array_map([$conn, 'real_escape_string'], array_map('strval', $missing));
            $in2 = "'" . implode("','", $esc2) . "'";
            $r2 = $conn->query("SELECT record_no, image_filename FROM client_records WHERE record_no IN ($in2) AND image_filename IS NOT NULL AND image_filename != ''");
            if ($r2) while ($row = $r2->fetch_assoc()) {
                $images[$row['record_no']] = $row['image_filename'];
            }
        }
        
        send_json(['status'=>'success','images'=>$images]);
    }

    // ── Bulk Submit Report to Admin ──
    if ($action == 'bulk_submit_report_to_admin') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $reports_raw   = $_POST['reports']   ?? '[]';
        $reported_from = trim($_POST['reported_from'] ?? 'first_qc');
        $reports_arr   = json_decode($reports_raw, true);
        
        if (empty($reports_arr) || !is_array($reports_arr)) {
            send_json(['status'=>'error','message'=>'Koi reports nahi mili']);
        }
        
        $submitted_by      = $_SESSION['username'];       // actual admin/uploader
        $submitted_by_name = $_SESSION['full_name'] ?? $_SESSION['username'];
        
        $success_count   = 0;
        $duplicate_count = 0;
        $error_count     = 0;
        
        foreach ($reports_arr as $rpt) {
            $record_no    = trim($rpt['record_no']        ?? '');
            $header_name  = trim($rpt['header_name']      ?? '');
            $issue_details= trim($rpt['issue_details']    ?? '');
            $image_no     = trim($rpt['image_no']         ?? '');
            
            if (empty($record_no) || empty($header_name) || empty($issue_details)) {
                $error_count++;
                continue;
            }
            
            $rn_esc = $conn->real_escape_string($record_no);
            
            // Auto-fetch reporter from record's assigned user (username/assigned_to)
            $user_row = $conn->query("
                SELECT u.username, u.full_name, u.role
                FROM client_records cr
                JOIN users u ON u.username = COALESCE(cr.assigned_to, cr.username)
                WHERE cr.record_no = '$rn_esc'
                LIMIT 1
            ");
            
            if ($user_row && $user_row->num_rows > 0) {
                $u = $user_row->fetch_assoc();
                $reported_by      = $u['username'];
                $reported_by_name = $u['full_name'] ?: $u['username'];
                $reporter_role    = $u['role'] ?: 'deo';
            } else {
                // Fallback: submitter (admin)
                $reported_by      = $submitted_by;
                $reported_by_name = $submitted_by_name;
                $reporter_role    = 'deo';
            }
            
            $rn_esc  = $conn->real_escape_string($record_no);
            $hd_esc  = $conn->real_escape_string($header_name);
            $id_esc  = $conn->real_escape_string($issue_details);
            $img_esc = $conn->real_escape_string($image_no);
            $rb_esc  = $conn->real_escape_string($reported_by);
            $rbn_esc = $conn->real_escape_string($reported_by_name);
            $role_esc= $conn->real_escape_string($reporter_role);
            $rf_esc  = $conn->real_escape_string($reported_from);
            
            // Auto-fetch image_no if missing
            if (empty($img_esc)) {
                $img_res = $conn->query("SELECT image_no FROM record_image_map WHERE record_no='$rn_esc' LIMIT 1");
                if ($img_res && $img_res->num_rows > 0) {
                    $img_esc = str_replace('_enc', '', $img_res->fetch_assoc()['image_no']);
                } else {
                    $img_res2 = $conn->query("SELECT image_filename FROM client_records WHERE record_no='$rn_esc' LIMIT 1");
                    if ($img_res2 && $img_res2->num_rows > 0) {
                        $img_esc = $conn->real_escape_string($img_res2->fetch_assoc()['image_filename'] ?? '');
                    }
                }
            }
            
            // Check duplicate
            $dup = $conn->query("SELECT id FROM report_to_admin WHERE record_no='$rn_esc' AND header_name='$hd_esc' AND status='open'");
            if ($dup && $dup->num_rows > 0) {
                $duplicate_count++;
                continue;
            }
            
            // Insert into report_to_admin
            $ins = $conn->query("INSERT INTO report_to_admin (record_no, header_name, issue_details, reported_by, reported_by_name, `role`, reported_from, image_no)
                VALUES ('$rn_esc','$hd_esc','$id_esc','$rb_esc','$rbn_esc','$role_esc','$rf_esc','$img_esc')");
            
            if ($ins) {
                $rta_id = $conn->insert_id;
                // Update is_reported flag
                $conn->query("UPDATE client_records SET is_reported=1, report_count=IFNULL(report_count,0)+1 WHERE record_no='$rn_esc'");
                
                    $success_count++;
            } else {
                $error_count++;
            }
        }
        
        $msg = "Bulk Report: $success_count submitted";
        if ($duplicate_count) $msg .= ", $duplicate_count duplicate skipped";
        if ($error_count)     $msg .= ", $error_count errors";
        
        // Notification
        if ($success_count > 0) {
            $notif = $conn->real_escape_string("Bulk Report by $reported_by_name: $success_count records reported");
            $conn->query("INSERT INTO notifications (user_id, title, message, type) VALUES (1, '$notif', '$notif', 'alert')");
        }
        
        send_json([
            'status'          => 'success',
            'message'         => $msg,
            'success_count'   => $success_count,
            'duplicate_count' => $duplicate_count,
            'error_count'     => $error_count
        ]);
    }

        // ── Get Reported Records for Autotyper (any logged-in user, both tables) ──
    if ($action == 'get_reported_records_autotyper') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $reports_map = [];
        
        // 1. From report_to_admin (status = open)
        $r1 = $conn->query("
            SELECT r.record_no, r.header_name, r.issue_details, r.reported_by,
                   r.reported_by_name, r.role as reporter_role, r.status,
                   r.image_no, r.created_at, 'report_to_admin' as source, 0 as ce_id
            FROM report_to_admin r
            WHERE r.status = 'open'
            ORDER BY r.created_at DESC
        ");
        if ($r1) while ($row = $r1->fetch_assoc()) {
            $rno = $row['record_no'];
            if (!isset($reports_map[$rno])) $reports_map[$rno] = [];
            $reports_map[$rno][] = $row;
        }
        
        // 2. From critical_errors (status = pending / admin_reviewed)
        $r2 = $conn->query("
            SELECT ce.record_no, ce.error_field as header_name,
                   ce.error_details as issue_details,
                   COALESCE(ce.reported_by_name, 'DEO') as reported_by,
                   ce.reported_by_name, ce.reporter_role, ce.status,
                   ce.admin_remark, ce.reviewed_at,
                   '' as image_no, ce.created_at,
                   'critical_errors' as source, ce.id as ce_id
            FROM critical_errors ce
            WHERE ce.status IN ('pending', 'admin_reviewed')
            ORDER BY ce.created_at DESC
        ");
        if ($r2) while ($row = $r2->fetch_assoc()) {
            $rno = $row['record_no'];
            if (!isset($reports_map[$rno])) $reports_map[$rno] = [];
            $reports_map[$rno][] = $row;
        }
        
        send_json(['status'=>'success','reports_map'=>$reports_map,'count'=>count($reports_map)]);
    }

    if ($action == 'get_done_records_for_autotyper') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);

        $target_user = $_POST['username'] ?? $_SESSION['username'];
        $cols = ['record_no', 'kyc_number', 'name', 'guardian_name', 'gender', 'marital_status', 'dob', 'address', 'landmark', 'city', 'zip_code', 'city_of_birth', 'nationality', 'photo_attachment', 'residential_status', 'occupation', 'officially_valid_documents', 'annual_income', 'broker_name', 'sub_broker_code', 'bank_serial_no', 'second_applicant_name', 'amount_received_from', 'amount', 'arn_no', 'second_address', 'occupation_profession', 'remarks'];
        $colStr = implode(',', $cols);
       
        // Fetch records ready for autotyper + is_reported info
        $sql = "SELECT $colStr, row_status, is_reported, report_count FROM client_records WHERE row_status IN ('done', 'deo_done', 'pending_qc', 'qc_done', 'qc_approved', 'Completed')";
        if (!empty($target_user)) {
            $escaped_user = $conn->real_escape_string($target_user);
            $sql .= " AND (username = '$escaped_user' OR assigned_to = '$escaped_user')";
        }
        $sql .= " ORDER BY id ASC";
       
        $res = $conn->query($sql);
        $data = [];
        $meta = [];
        $reported_record_nos = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $is_rep  = (int)($row['is_reported'] ?? 0);
                $rep_cnt = (int)($row['report_count'] ?? 0);
                $rno_val = $row['record_no'] ?? '';
                $meta[] = ['status'=>$row['row_status'], 'is_reported'=>$is_rep, 'report_count'=>$rep_cnt, 'record_no'=>$rno_val];
                if ($is_rep || $rep_cnt > 0) $reported_record_nos[] = $conn->real_escape_string($rno_val);
                unset($row['row_status'], $row['is_reported'], $row['report_count']);
                $cleanRow = [];
                foreach ($row as $val) $cleanRow[] = (string)$val;
                $data[] = $cleanRow;
            }
            // Fetch open report details — from BOTH report_to_admin AND critical_errors
            $reports_map = [];
            
            // Collect ALL record_nos (not just those flagged in DB — DB may be stale)
            $all_rec_nos = array_map(fn($m) => $conn->real_escape_string($m['record_no']), $meta);
            $all_rno_in  = "'" . implode("','", array_filter($all_rec_nos)) . "'";
            
            if (!empty($all_rec_nos)) {
                // 1. report_to_admin (open)
                $rep_res = $conn->query("SELECT record_no, header_name, issue_details, reported_by_name, role as reporter_role, status, image_no, created_at, 'report_to_admin' as source FROM report_to_admin WHERE record_no IN ($all_rno_in) AND status='open' ORDER BY created_at ASC");
                if ($rep_res) while ($rr = $rep_res->fetch_assoc()) {
                    $rno = $rr['record_no'];
                    if (!isset($reports_map[$rno])) $reports_map[$rno] = [];
                    $reports_map[$rno][] = $rr;
                }
                
                // 2. critical_errors (pending/admin_reviewed)
                $ce_res = $conn->query("SELECT record_no, error_field as header_name, error_details as issue_details, reported_by_name, reporter_role, status, admin_remark, '' as image_no, created_at, 'critical_errors' as source, id as ce_id FROM critical_errors WHERE record_no IN ($all_rno_in) AND status IN ('pending','admin_reviewed') ORDER BY created_at ASC");
                if ($ce_res) while ($cr = $ce_res->fetch_assoc()) {
                    $rno = $cr['record_no'];
                    if (!isset($reports_map[$rno])) $reports_map[$rno] = [];
                    $reports_map[$rno][] = $cr;
                }
                
                // Update meta is_reported based on combined reports_map
                foreach ($meta as &$m) {
                    if (isset($reports_map[$m['record_no']])) {
                        $m['is_reported']  = 1;
                        $m['report_count'] = count($reports_map[$m['record_no']]);
                    }
                }
                unset($m);
            }
            send_json(['status'=>'success','data'=>$data,'meta'=>$meta,'columns'=>$cols,'reports_map'=>$reports_map]);
        } else {
            send_json(['status' => 'error', 'message' => $conn->error]);
        }
    }

    // ==========================================
    // 6. IMAGE UPLOAD & SYNC
    // ==========================================
    if ($action == 'upload_images_files') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        $allowed_ext = ['jpg','jpeg','png','gif','webp','bmp','tiff','tif'];
        $u = 0;
        if (!is_dir('./uploads')) mkdir('./uploads', 0755, true);
        foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
            $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext)) continue;
            $safe_name = pathinfo($_FILES['images']['name'][$i], PATHINFO_FILENAME);
            $safe_name = preg_replace("/[^a-zA-Z0-9\-\_]/", "", $safe_name) . '.' . $ext;
            if (is_uploaded_file($tmp) && move_uploaded_file($tmp, "./uploads/" . $safe_name)) $u++;
        }
        send_json(['status'=>'success','message'=>"$u Images Uploaded"]);
    }
    if ($action == 'upload_single_image_and_map') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login required']);
        $rec = trim($_POST['record_no'] ?? '');
        if (empty($rec) || !isset($_FILES['image'])) send_json(['status'=>'error']);
        $allowed_ext = ['jpg','jpeg','png','gif','webp','bmp','tiff','tif'];
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) send_json(['status'=>'error', 'message'=>'Only image files allowed']);
        $target_dir = './uploads/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $name = preg_replace("/[^a-zA-Z0-9\-\_]/", "", pathinfo($_FILES['image']['name'], PATHINFO_FILENAME)) . '.' . $ext;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_dir . $name)) {
            $stmt = $conn->prepare("UPDATE client_records SET image_filename = ? WHERE record_no = ?");
            $stmt->bind_param("ss", $name, $rec);
            $stmt->execute();
            send_json(['status'=>'success', 'message'=>'Mapped']);
        }
        send_json(['status'=>'error']);
    }
    if ($action == 'sync_external_mapping') {
        if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Login First']);
        
        // Now syncs from same database (record_image_map table) - No external DB needed
        $result = $conn->query("SELECT record_no, image_no, image_path FROM record_image_map");
        if ($result && $result->num_rows > 0) {
            $updated_count = 0;
            $update_stmt = $conn->prepare("UPDATE client_records SET image_filename = ?, image_path = ? WHERE record_no = ?");
            $conn->begin_transaction();
            $img_base = defined('IMAGE_BASE_PATH') ? IMAGE_BASE_PATH : '/uploads/mapping_images/';
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['record_no']) && !empty($row['image_no'])) {
                    $img_path = !empty($row['image_path']) ? $row['image_path'] : $img_base . $row['image_no'];
                    $update_stmt->bind_param("sss", $row['image_no'], $img_path, $row['record_no']);
                    $update_stmt->execute();
                    if ($update_stmt->affected_rows > 0) $updated_count++;
                }
            }
            $conn->commit();
            send_json(['status'=>'success', 'message'=>"Synced $updated_count records from record_image_map."]);
        } else {
            send_json(['status'=>'error', 'message'=>'No data found in record_image_map']);
        }
    }

    // ==========================================
    // 7. ADMIN ACTIONS - USER MANAGEMENT (Enhanced)
    // ==========================================
    
    // Get all users with extended info
    if ($action == 'get_users') {
        // Admin and Supervisor can get users
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json([]);
        }
        
        // Check which columns exist
        $cols = ['id', 'username', 'full_name', 'role', 'phone'];
        $optional_cols = ['status', 'failed_attempts', 'locked_until', 'last_login', 'last_ip', 'daily_target', 'allowed_ips'];
        
        foreach ($optional_cols as $col) {
            $check = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
            if ($check->num_rows > 0) {
                $cols[] = $col;
            }
        }
        
        $sql = "SELECT " . implode(', ', $cols) . " FROM users ORDER BY id DESC";
        $r = $conn->query($sql);
        $d = [];
        while ($row = $r->fetch_assoc()) {
            // Add defaults for missing columns
            $row['status'] = $row['status'] ?? 'active';
            $row['failed_attempts'] = $row['failed_attempts'] ?? 0;
            $row['locked_until'] = $row['locked_until'] ?? null;
            $row['last_login'] = $row['last_login'] ?? null;
            $row['last_ip'] = $row['last_ip'] ?? null;
            $row['daily_target'] = $row['daily_target'] ?? 100;
            $row['allowed_ips'] = $row['allowed_ips'] ?? '';
            
            // Check if currently locked
            $row['is_locked'] = (($row['locked_until'] && strtotime($row['locked_until']) > time())) ? 1 : 0;
            $d[] = $row;
        }
        send_json($d);
    }
    
    // Get user login history
    if ($action == 'get_user_login_history') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        // Check if table exists
        $check = $conn->query("SHOW TABLES LIKE 'login_attempts'");
        if ($check->num_rows == 0) {
            send_json(['status' => 'success', 'data' => []]);
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        $username = $_POST['username'] ?? '';
        
        $stmt = $conn->prepare("SELECT * FROM login_attempts WHERE username = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        send_json(['status' => 'success', 'data' => $logs]);
    }
    
    // Get active sessions
    if ($action == 'get_active_sessions') {
        if (!check_session_validity() || $_SESSION['role'] !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        // Check if last_activity column exists, if not create it
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
        if ($check->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL");
        }
        
        // Check if last_login column exists
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
        if ($check->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL");
        }
        
        // Check if last_ip column exists
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_ip'");
        if ($check->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN last_ip VARCHAR(50) DEFAULT NULL");
        }
        
        // Get users with recent activity (within last 5 mins = active session)
        $r = $conn->query("SELECT id, username, full_name, role, last_login, last_activity, last_ip 
                          FROM users 
                          WHERE last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
                          ORDER BY last_activity DESC");
        $sessions = [];
        while ($row = $r->fetch_assoc()) {
            $row['status'] = 'online';
            $sessions[] = $row;
        }
        send_json(['status' => 'success', 'data' => $sessions]);
    }
    
    // Update user activity (called by dashboards to track online status)
    if ($action == 'heartbeat') {
        if (!check_session_validity()) {
            send_json(['status' => 'error']);
        }
        
        // Check if last_activity column exists
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
        if ($check->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL");
        }
        
        // Update last activity time
        $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        
        send_json(['status' => 'success', 'time' => date('Y-m-d H:i:s')]);
    }
    
    // Update user status (active/inactive)
    if ($action == 'update_user_status') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $user_id = intval($_POST['user_id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        
        if ($user_id == $_SESSION['user_id']) {
            send_json(['status' => 'error', 'message' => 'Cannot change your own status']);
        }
        
        // Check if column exists
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
        if ($check->num_rows == 0) {
            // Add column if doesn't exist
            $conn->query("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
        }
        
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $user_id);
        if ($stmt->execute()) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'update_user_status', 'user_management', $user_id, null, null, $status);
            send_json(['status' => 'success']);
        } else {
            send_json(['status' => 'error', 'message' => $stmt->error]);
        }
    }
    
    // Reset user password (by admin)
    if ($action == 'reset_user_password') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $user_id = intval($_POST['user_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';
        
        if (empty($new_password)) {
            send_json(['status' => 'error', 'message' => 'Password required']);
        }
        
        // Hash the password
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Check if security columns exist
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'failed_attempts'");
        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE users SET password = ?, failed_attempts = 0, status = 'active', locked_until = NULL WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        }
        $stmt->bind_param("si", $hashed, $user_id);
        if ($stmt->execute()) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'reset_password', 'user_management', $user_id);
            send_json(['status' => 'success', 'message' => 'Password reset successfully']);
        } else {
            send_json(['status' => 'error', 'message' => $stmt->error]);
        }
    }
    
    // ==========================================
    // SUPERVISOR PERMISSION APIs
    // ==========================================
    
    // Supervisor actions removed
    if (in_array($action, ['get_supervisor_permissions','save_supervisor_permissions','check_permission'])) {
        send_json(['status'=>'error','message'=>'Feature removed']);
    }


    // Set user daily target
    if ($action == 'set_user_target') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $user_id = intval($_POST['user_id'] ?? 0);
        $target = intval($_POST['daily_target'] ?? 100);
        
        // Check if column exists
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'daily_target'");
        if ($check->num_rows == 0) {
            // Add column if doesn't exist
            $conn->query("ALTER TABLE users ADD COLUMN daily_target INT DEFAULT 100");
        }
        
        $stmt = $conn->prepare("UPDATE users SET daily_target = ? WHERE id = ?");
        $stmt->bind_param("ii", $target, $user_id);
        if ($stmt->execute()) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'set_target', 'user_management', $user_id, null, null, $target);
            send_json(['status' => 'success']);
        } else {
            send_json(['status' => 'error', 'message' => $stmt->error]);
        }
    }
    
    // Set user allowed IPs
    if ($action == 'set_user_allowed_ips') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $user_id = intval($_POST['user_id'] ?? 0);
        $ips = trim($_POST['allowed_ips'] ?? '');
        
        // Check if column exists
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'allowed_ips'");
        if ($check->num_rows == 0) {
            // Add column if doesn't exist
            $conn->query("ALTER TABLE users ADD COLUMN allowed_ips TEXT NULL");
        }
        
        $stmt = $conn->prepare("UPDATE users SET allowed_ips = ? WHERE id = ?");
        $stmt->bind_param("si", $ips, $user_id);
        if ($stmt->execute()) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'set_user_ips', 'user_management', $user_id, null, null, $ips);
            send_json(['status' => 'success']);
        } else {
            send_json(['status' => 'error', 'message' => $stmt->error]);
        }
    }
    
    // ==========================================
    // ADMIN TO DEO MESSAGING SYSTEM
    // ==========================================
    
    // Send message to DEO (Admin only)
    if ($action == 'send_message_to_deo') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Admin access required']);
        }
        
        $to_user_id = intval($_POST['to_user_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $priority = $_POST['priority'] ?? 'normal'; // normal, urgent, warning
        
        if (empty($message)) {
            send_json(['status' => 'error', 'message' => 'Message is required']);
        }
        
        // Create messages table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS admin_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT NOT NULL,
            to_user_id INT NOT NULL,
            message TEXT NOT NULL,
            priority VARCHAR(20) DEFAULT 'normal',
            is_read TINYINT(1) DEFAULT 0,
            read_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Insert message (to_user_id = 0 means all users)
        $stmt = $conn->prepare("INSERT INTO admin_messages (from_user_id, to_user_id, message, priority) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $_SESSION['user_id'], $to_user_id, $message, $priority);
        
        if ($stmt->execute()) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'send_message', 'messaging', $to_user_id, null, null, null, "Message sent");
            send_json(['status' => 'success', 'message' => 'Message sent successfully']);
        } else {
            send_json(['status' => 'error', 'message' => 'Failed to send message']);
        }
    }
    
    // Check for unread messages (DEO)
    if ($action == 'check_messages') {
        if (!check_session_validity()) {
            send_json(['status' => 'error', 'messages' => []]);
        }
        
        // Check if table exists
        $check = $conn->query("SHOW TABLES LIKE 'admin_messages'");
        if ($check->num_rows == 0) {
            send_json(['status' => 'success', 'messages' => []]);
        }
        
        $user_id = $_SESSION['user_id'];
        
        // Get unread messages for this user OR for all users (to_user_id = 0)
        $stmt = $conn->prepare("SELECT m.*, u.full_name as from_name 
                                FROM admin_messages m 
                                LEFT JOIN users u ON m.from_user_id = u.id 
                                WHERE (m.to_user_id = ? OR m.to_user_id = 0) 
                                AND m.is_read = 0 
                                ORDER BY m.created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        
        send_json(['status' => 'success', 'messages' => $messages]);
    }
    
    // Mark message as read (DEO clicks OK)
    if ($action == 'mark_message_read') {
        if (!check_session_validity()) {
            send_json(['status' => 'error']);
        }
        
        $message_id = intval($_POST['message_id'] ?? 0);
        $user_id = $_SESSION['user_id'];
        
        // Mark as read
        $stmt = $conn->prepare("UPDATE admin_messages SET is_read = 1, read_at = NOW() WHERE id = ? AND (to_user_id = ? OR to_user_id = 0)");
        $stmt->bind_param("ii", $message_id, $user_id);
        $stmt->execute();
        
        send_json(['status' => 'success']);
    }
    
    // Get sent messages history (Admin)
    if ($action == 'get_sent_messages') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Admin access required']);
        }
        
        // Check if table exists
        $check = $conn->query("SHOW TABLES LIKE 'admin_messages'");
        if ($check->num_rows == 0) {
            send_json(['status' => 'success', 'messages' => []]);
        }
        
        $result = $conn->query("SELECT m.*, 
                                u1.full_name as from_name,
                                CASE WHEN m.to_user_id = 0 THEN 'All Users' ELSE u2.full_name END as to_name
                                FROM admin_messages m
                                LEFT JOIN users u1 ON m.from_user_id = u1.id
                                LEFT JOIN users u2 ON m.to_user_id = u2.id
                                ORDER BY m.created_at DESC LIMIT 50");
        
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        
        send_json(['status' => 'success', 'messages' => $messages]);
    }
    
    // Test WhatsApp API
    if ($action == 'test_whatsapp_api') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Admin access required']);
        }
        
        $phone = $_POST['phone'] ?? '';
        if (empty($phone)) {
            send_json(['status' => 'error', 'message' => 'Phone number required']);
        }
        
        $testMessage = "🧪 *TEST MESSAGE*\n\nThis is a test message from BPO Dashboard.\n\nIf you received this, WhatsApp API is working correctly! ✅\n\n_Sent at: " . date('Y-m-d H:i:s') . "_";
        
        $result = sendWhatsApp($phone, $testMessage);
        
        if ($result) {
            send_json(['status' => 'success', 'message' => 'Test message sent to ' . $phone]);
        } else {
            send_json(['status' => 'error', 'message' => 'Failed to send test message. Check API settings and phone number.']);
        }
    }
    
    // Get user performance stats
    if ($action == 'get_user_performance') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $username = $_POST['username'] ?? '';
        $period = $_POST['period'] ?? 'today'; // today, week, month
        
        $date_filter = "DATE(updated_at) = CURDATE()";
        if ($period === 'week') {
            $date_filter = "updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($period === 'month') {
            $date_filter = "updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }
        
        $stats = [];
        
        // Total completed by this user
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM client_records WHERE username = ? AND row_status IN ('done', 'Completed') AND $date_filter");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stats['completed'] = $stmt->get_result()->fetch_assoc()['total'];
        
        // Total time spent
        $stmt = $conn->prepare("SELECT SUM(time_spent) as total_time FROM client_records WHERE username = ? AND $date_filter");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stats['total_time'] = $stmt->get_result()->fetch_assoc()['total_time'] ?? 0;
        
        // Average time per record
        $stats['avg_time'] = $stats['completed'] > 0 ? round($stats['total_time'] / $stats['completed']) : 0;
        
        // Get daily target
        $stmt = $conn->prepare("SELECT daily_target FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stats['daily_target'] = $stmt->get_result()->fetch_assoc()['daily_target'] ?? 100;
        
        // Target progress (for today only)
        if ($period === 'today') {
            $stats['target_progress'] = $stats['daily_target'] > 0 ? round(($stats['completed'] / $stats['daily_target']) * 100) : 0;
        }
        
        send_json(['status' => 'success', 'data' => $stats]);
    }
    
    // Get all users performance summary
    if ($action == 'get_all_users_performance') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $period = $_POST['period'] ?? 'today';
        
        $date_filter = "DATE(cr.updated_at) = CURDATE()";
        if ($period === 'week') {
            $date_filter = "cr.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($period === 'month') {
            $date_filter = "cr.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }
        
        $sql = "SELECT 
                    u.id, u.username, u.full_name, u.daily_target, u.status, u.last_login,
                    COUNT(CASE WHEN cr.row_status IN ('done', 'Completed') AND $date_filter THEN 1 END) as completed,
                    SUM(CASE WHEN $date_filter THEN cr.time_spent ELSE 0 END) as total_time
                FROM users u
                LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id)
                WHERE u.`role` = 'deo'
                GROUP BY u.id
                ORDER BY completed DESC";
        
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $row['avg_time'] = $row['completed'] > 0 ? round($row['total_time'] / $row['completed']) : 0;
            $row['target_progress'] = $row['daily_target'] > 0 ? round(($row['completed'] / $row['daily_target']) * 100) : 0;
            $data[] = $row;
        }
        send_json(['status' => 'success', 'data' => $data]);
    }

    // Add User (Updated with new fields)
    if ($action == 'add_user') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status'=>'error', 'message'=>'Admin access required']);
        }
        
        $username = trim($_POST['new_username'] ?? $_POST['username'] ?? '');
        $password = $_POST['new_password'] ?? $_POST['password'] ?? '';
        $full_name = trim($_POST['new_full_name'] ?? $_POST['full_name'] ?? '');
        $phone = trim($_POST['new_phone'] ?? $_POST['phone'] ?? '');
        $role = $_POST['new_role'] ?? $_POST['role'] ?? 'deo';
        $daily_target = intval($_POST['daily_target'] ?? 100);
        
        if (empty($username) || empty($password)) {
            send_json(['status'=>'error', 'message'=>'Username and password required']);
        }
        
        // Check if exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            send_json(['status'=>'error', 'message'=>'Username already exists']);
        }
        $stmt->close();
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Check which columns exist
        $has_target = $conn->query("SHOW COLUMNS FROM users LIKE 'daily_target'")->num_rows > 0;
        $has_status = $conn->query("SHOW COLUMNS FROM users LIKE 'status'")->num_rows > 0;
        
        if ($has_target && $has_status) {
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, phone, role, daily_target, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param("sssssi", $username, $hashed_password, $full_name, $phone, $role, $daily_target);
        } elseif ($has_target) {
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, phone, role, daily_target) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $username, $hashed_password, $full_name, $phone, $role, $daily_target);
        } elseif ($has_status) {
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, phone, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param("sssss", $username, $hashed_password, $full_name, $phone, $role);
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, phone, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $username, $hashed_password, $full_name, $phone, $role);
        }
        
        if ($stmt->execute()) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'add_user', 'user_management', $stmt->insert_id, null, null, null, "Added user: $username");
            send_json(['status'=>'success', 'message'=>'User added successfully']);
        } else {
            send_json(['status'=>'error', 'message'=>'Failed: ' . $stmt->error]);
        }
    }

    // Update User
    if ($action == 'update_user') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status'=>'error', 'message'=>'Admin access required']);
        }
        
        $id = intval($_POST['user_id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) send_json(['status'=>'error', 'message'=>'Invalid user ID']);
        
        $new_username = trim($_POST['new_username'] ?? $_POST['username'] ?? '');
        $full_name = trim($_POST['new_full_name'] ?? $_POST['full_name'] ?? '');
        $phone = trim($_POST['new_phone'] ?? $_POST['phone'] ?? '');
        $role = $_POST['new_role'] ?? $_POST['role'] ?? 'deo';
        $password = $_POST['new_password'] ?? $_POST['password'] ?? '';
        $daily_target = intval($_POST['daily_target'] ?? 100);
        
        if (empty($new_username)) {
            send_json(['status'=>'error', 'message'=>'Username required']);
        }
        
        // STEP 1: Get old username FIRST (before any update)
        $result = $conn->query("SELECT username FROM users WHERE id = $id");
        if (!$result || $result->num_rows == 0) {
            send_json(['status'=>'error', 'message'=>'User not found']);
        }
        $old_row = $result->fetch_assoc();
        $old_username = $old_row['username'];
        
        // STEP 2: Check if new username already taken by another user
        if (strtolower($new_username) !== strtolower($old_username)) {
            $check = $conn->query("SELECT id FROM users WHERE LOWER(username) = LOWER('" . $conn->real_escape_string($new_username) . "') AND id != $id");
            if ($check && $check->num_rows > 0) {
                send_json(['status'=>'error', 'message'=>'Username already exists']);
            }
        }
        
        // STEP 3: Check which columns exist
        $has_target = $conn->query("SHOW COLUMNS FROM users LIKE 'daily_target'")->num_rows > 0;
        
        // STEP 4: Update users table
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            if ($has_target) {
                $sql = "UPDATE users SET username=?, full_name=?, phone=?, role=?, password=?, daily_target=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssii", $new_username, $full_name, $phone, $role, $hashed_password, $daily_target, $id);
            } else {
                $sql = "UPDATE users SET username=?, full_name=?, phone=?, `role`=?, password=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssi", $new_username, $full_name, $phone, $role, $hashed_password, $id);
            }
        } else {
            if ($has_target) {
                $sql = "UPDATE users SET username=?, full_name=?, phone=?, role=?, daily_target=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssii", $new_username, $full_name, $phone, $role, $daily_target, $id);
            } else {
                $sql = "UPDATE users SET username=?, full_name=?, phone=?, `role`=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssi", $new_username, $full_name, $phone, $role, $id);
            }
        }
        
        if (!$stmt->execute()) {
            send_json(['status'=>'error', 'message'=>'User update failed: ' . $stmt->error]);
        }
        $stmt->close();
        
        // STEP 5: Update client_records if username changed
        $records_updated = 0;
        if (strtolower($new_username) !== strtolower($old_username)) {
            // Direct query to update client_records
            $escaped_new = $conn->real_escape_string($new_username);
            $escaped_old = $conn->real_escape_string($old_username);
            
            $update_sql = "UPDATE client_records SET username = '$escaped_new' WHERE username = '$escaped_old'";
            if ($conn->query($update_sql)) {
                $records_updated = $conn->affected_rows;
            }
            
            // Also update activity_logs if exists
            $check = $conn->query("SHOW TABLES LIKE 'activity_logs'");
            if ($check->num_rows > 0) {
                $conn->query("UPDATE activity_logs SET username = '$escaped_new' WHERE username = '$escaped_old'");
            }
            
            send_json(['status'=>'success', 'message'=>"User updated successfully! Username changed from '$old_username' to '$new_username'. $records_updated records updated."]);
        } else {
            send_json(['status'=>'success', 'message'=>'User updated successfully!']);
        }
    }

    // Manual fix: Change username in records (for fixing orphaned records)
    if ($action == 'fix_username_in_records') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status'=>'error', 'message'=>'Admin access required']);
        }
        
        $old_username = trim($_POST['old_username'] ?? '');
        $new_username = trim($_POST['new_username'] ?? '');
        
        if (empty($old_username) || empty($new_username)) {
            send_json(['status'=>'error', 'message'=>'Both old and new username required']);
        }
        
        // Update client_records
        $escaped_old = $conn->real_escape_string($old_username);
        $escaped_new = $conn->real_escape_string($new_username);
        
        $result = $conn->query("UPDATE client_records SET username = '$escaped_new' WHERE username = '$escaped_old'");
        $records_updated = $conn->affected_rows;
        
        // Also update activity_logs if exists
        $check = $conn->query("SHOW TABLES LIKE 'activity_logs'");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE activity_logs SET username = '$escaped_new' WHERE username = '$escaped_old'");
        }
        
        send_json(['status'=>'success', 'message'=>"$records_updated records updated from '$old_username' to '$new_username'"]);
    }

    // Delete User
    if ($action == 'delete_user') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status'=>'error', 'message'=>'Admin access required']);
        }
        
        $id = intval($_POST['user_id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) send_json(['status'=>'error', 'message'=>'Invalid user ID']);
        if ($id == $_SESSION['user_id']) send_json(['status'=>'error', 'message'=>'Cannot delete yourself']);
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            send_json(['status'=>'success', 'message'=>'User deleted']);
        } else {
            send_json(['status'=>'error', 'message'=>'Failed: ' . $stmt->error]);
        }
    }

    if ($action == 'assign_rows') {
        if ($_SESSION['role'] !== 'admin') send_json(['status'=>'error', 'message'=>'Admin only']);
        $ids = $_POST['ids'] ?? '';
        $target = $_POST['target_user'] === 'Unassign' ? '' : $_POST['target_user'];
        $user = $_SESSION['username'];
        if (!empty($ids)) {
            $idArr = array_map('intval', explode(',', $ids));
            $sql = "UPDATE client_records SET username = ?, row_status='pending', last_updated_by = ? WHERE id IN (" . implode(',', $idArr) . ")";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $target, $user);
        } else {
            $start = $_POST['record_no_start'];
            $end = $_POST['record_no_end'];
            $stmt = $conn->prepare("UPDATE client_records SET username = ?, row_status='pending', last_updated_by = ? WHERE record_no BETWEEN ? AND ?");
            $stmt->bind_param("ssss", $target, $user, $start, $end);
        }
        if ($stmt->execute()) send_json(['status'=>'success', 'message'=>'Assigned']);
        else send_json(['status'=>'error', 'message'=>$stmt->error]);
    }

    if ($action == 'batch_update_status') {
        if ($_SESSION['role'] !== 'admin') send_json(['status'=>'error', 'message'=>'Admin only']);
        $ids = explode(',', $_POST['ids']);
        $status = $_POST['status'];
        $sql = "UPDATE client_records SET row_status = ?, last_updated_by = ?, updated_at = NOW() WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $status, $_SESSION['username']);
        $stmt->execute();
        send_json(['status'=>'success', 'message'=>'Updated']);
    }

    if ($action == 'clear_main_data') {
        if ($_SESSION['role'] != 'admin') send_json(['status'=>'error']);
        $conn->query("DELETE FROM client_records");
        send_json(['status'=>'success', 'message'=>'Data Cleared']);
    }

    // ==========================================
    // ==========================================
    // ==========================================
    // ==========================================
    // 8. EXCEL UPLOAD (MERGE MODE FIXED)
    // ==========================================
    if ($action == 'upload_main_data') {
        if ($_SESSION['role'] !== 'admin') send_json(['status'=>'error', 'message'=>'Admin only']);
        
        // Check if this is the first chunk
        $is_first_chunk = isset($_POST['is_first_chunk']) && $_POST['is_first_chunk'] === 'true';

        // ❌ TRUNCATE HATA DIYA GAYA HAI
        // if ($is_first_chunk) {
        //    $conn->query("TRUNCATE TABLE client_records");
        // }
        
        // Ensure assigned_to column exists (Safety check)
        // Ensure is_invalid_record column exists for invalid record tracking
        // Decode JSON
        $data = json_decode($_POST['jsonData'], true);
        if (!is_array($data) || empty($data)) {
            send_json(['status'=>'success', 'message'=>'No data in this chunk', 'inserted'=>0, 'skipped'=>0]);
        }

        $inserted = 0;
        $skipped = 0;
        $first_error = '';

        // ✅ CHANGE: INSERT IGNORE use kiya hai.
        // Agar record_no match karega, to database usse skip kar dega (Duplicate error nahi aayega).
        $sql = "INSERT IGNORE INTO client_records 
                (record_no, kyc_number, name, guardian_name, gender, marital_status, dob, address, landmark, city, zip_code, city_of_birth, nationality, photo_attachment, residential_status, occupation, officially_valid_documents, annual_income, broker_name, sub_broker_code, bank_serial_no, second_applicant_name, amount_received_from, amount, arn_no, second_address, occupation_profession, remarks, username, assigned_to) 
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            send_json(['status'=>'error', 'message'=>'DB Prepare Error: ' . $conn->error]);
        }

        foreach ($data as $row) {
            // Handle different Key Case (Record No. vs Record No)
            $rn = '';
            if(isset($row['Record No.'])) $rn = trim($row['Record No.']);
            elseif(isset($row['Record No'])) $rn = trim($row['Record No']);
            
            if (empty($rn)) {
                $skipped++;
                continue;
            }

            // Helper to get value safely with alias support
            $get = function($k, $aliases = []) use ($row) { 
                // Check primary key first
                if (isset($row[$k]) && trim($row[$k]) !== '') return trim($row[$k]); 
                // Check aliases
                foreach ($aliases as $alias) {
                    if (isset($row[$alias]) && trim($row[$alias]) !== '') return trim($row[$alias]);
                }
                return ''; 
            };

            // Use the username assigned by JS logic, fallback to session user
            $assigned_user = isset($row['username']) && !empty($row['username']) ? $row['username'] : $_SESSION['username'];

            $params = [
                $rn, 
                $get('KYC Number'), 
                $get('Name'), 
                $get('Guardian Name'), 
                $get('Gender'), 
                $get('Marital Status'), 
                $get('DOB'), 
                $get('Address'), 
                $get('Landmark'), 
                $get('City'), 
                $get('Zip Code'), 
                $get('City of Birth'), 
                $get('Nationality'), 
                $get('Photo Attachment'), 
                $get('Residential Status'), 
                $get('Occupation'), 
                $get('Officially Valid Documents', ['OVD', 'Ovd', 'ovd', 'O.V.D', 'O.V.D.']), 
                $get('Annual Income'), 
                $get('Broker Name'), 
                $get('Sub Broker Code'), 
                $get('Bank Serial No.', ['Bank Serial No']), 
                $get('2nd Applicant Name', ['Second Applicant Name']), 
                $get('Amount Received From'), 
                $get('Amount'), 
                $get('ARN No.', ['ARN No']), 
                $get('2nd Address'), 
                $get('Occupation/Profession'), 
                $get('Remarks'), 
                $assigned_user, 
                $assigned_user 
            ];

            $stmt->bind_param(str_repeat("s", 30), ...$params);

            if ($stmt->execute()) {
                // INSERT IGNORE returns affected_rows = 0 if duplicate found
                if ($stmt->affected_rows > 0) {
                    $inserted++;
                } else {
                    $skipped++; // Duplicate skipped
                }
            } else {
                if(empty($first_error)) $first_error = $stmt->error;
            }
        }
        $stmt->close();

        send_json([
            'status' => 'success', 
            'message' => "Chunk processed", 
            'inserted' => $inserted, 
            'skipped' => $skipped
        ]);
    }

    if ($action == 'upload_updated_data') {
        if ($_SESSION['role'] !== 'admin') send_json(['status'=>'error', 'message'=>'Admin only']);
        $data = json_decode($_POST['jsonData'], true);
        $count = 0;
        $sql = "INSERT INTO client_records (record_no, kyc_number, name, guardian_name, gender, marital_status, dob, address, landmark, city, zip_code, city_of_birth, nationality, photo_attachment, residential_status, occupation, officially_valid_documents, annual_income, broker_name, sub_broker_code, bank_serial_no, second_applicant_name, amount_received_from, amount, arn_no, second_address, occupation_profession, remarks, username) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kyc_number=IF(row_status='done', kyc_number, VALUES(kyc_number)), name=IF(row_status='done', name, VALUES(name)), address=IF(row_status='done', address, VALUES(address))";
        $stmt = $conn->prepare($sql);
        foreach ($data as $raw_row) {
            $r = [];
            foreach ($raw_row as $k => $v) $r[strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $k))] = trim((string)$v);
            $get = function($keys) use ($r) {
                if (!is_array($keys)) $keys = [$keys];
                foreach ($keys as $k) if (isset($r[$k]) && $r[$k] !== '') return $r[$k];
                return '';
            };
            $rn = $get(['recordno','recno','id']);
            if (!$rn) continue;
            $params = [$rn, $get(['kycnumber','kycno']), $get('name'), $get('guardianname'), $get('gender'), $get('maritalstatus'), $get('dob'), $get('address'), $get('landmark'), $get('city'), $get('zipcode'), $get('cityofbirth'), $get('nationality'), $get('photoattachment'), $get('residentialstatus'), $get('occupation'), $get(['officiallyvaliddocuments','ovd']), $get('annualincome'), $get('brokername'), $get('subbrokercode'), $get(['bankserialno','bankserial']), $get(['secondapplicantname','2ndapplicantname']), $get('amountreceivedfrom'), $get('amount'), $get(['arnno','arn']), $get('secondaddress'), $get('occupationprofession'), $get('remarks'), $_SESSION['username']];
            $stmt->bind_param(str_repeat("s", 29), ...$params);
            if ($stmt->execute()) $count++;
        }
        send_json(['status'=>'success', 'message'=>"$count records uploaded and distributed successfully!", 'inserted' => $count]);
    }
    
    // ==========================================
    // IMPORT SPECIFIC FIELD DATA (by Record No)
    // ==========================================
    if ($action == 'import_field_data') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Admin only']);
        }
        
        $target_field = $_POST['target_field'] ?? '';
        $data = json_decode($_POST['jsonData'], true);
        
        // Allowed fields for security
        $allowed_fields = [
            'kyc_number', 'name', 'guardian_name', 'gender', 'marital_status', 'dob',
            'address', 'landmark', 'city', 'zip_code', 'city_of_birth', 'nationality',
            'photo_attachment', 'residential_status', 'occupation', 'officially_valid_documents',
            'annual_income', 'broker_name', 'sub_broker_code', 'bank_serial_no',
            'second_applicant_name', 'amount_received_from', 'amount', 'arn_no',
            'second_address', 'occupation_profession', 'remarks'
        ];
        
        if (!in_array($target_field, $allowed_fields)) {
            send_json(['status' => 'error', 'message' => 'Invalid target field: ' . $target_field]);
        }
        
        if (!is_array($data) || empty($data)) {
            send_json(['status' => 'success', 'message' => 'No data', 'updated' => 0]);
        }
        
        $updated = 0;
        $not_found = 0;
        $user = $_SESSION['username'];
        
        // Use prepared statement with dynamic field name (safe because we validated against whitelist)
        $sql = "UPDATE client_records SET `$target_field` = ?, last_updated_by = ?, updated_at = NOW() WHERE record_no = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            send_json(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
        }
        
        foreach ($data as $row) {
            $record_no = trim($row['record_no'] ?? '');
            $value = trim($row['value'] ?? '');
            
            if (empty($record_no)) continue;
            
            $stmt->bind_param("sss", $value, $user, $record_no);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $updated++;
                } else {
                    $not_found++;
                }
            }
        }
        
        $stmt->close();
        
        log_activity($conn, $_SESSION['user_id'], $user, 'import_field_data', 'client_records', null, null, null, null, "Field: $target_field, Updated: $updated, Not found: $not_found");
        
        send_json([
            'status' => 'success', 
            'message' => "Updated: $updated, Not Found: $not_found",
            'updated' => $updated,
            'not_found' => $not_found
        ]);
    }

    // ==========================================
    // 9. REPORTS & ANALYTICS
    // ==========================================
    
    // Get daily report for a specific date
    if ($action == 'get_daily_report') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $date = $_POST['date'] ?? date('Y-m-d');
        
        // ✅ UPDATED QUERY: 
        // 1. Target: User table se 'daily_target' lega.
        // 2. Count: Sirf 'Completed' status count karega (DEO Dashboard se match karne ke liye). 'done' status count nahi hoga.
        // 3. Date: Strictly updated_at date check karega.
        $sql = "SELECT 
                    u.username, u.full_name, 
                    COALESCE(u.daily_target, 150) as daily_target, 
                    SUM(CASE WHEN cr.row_status = 'Completed' AND DATE(cr.updated_at) = ? THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN DATE(cr.updated_at) = ? THEN cr.time_spent ELSE 0 END) as total_time
                FROM users u
                LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id)
                WHERE u.`role` = 'deo'
                GROUP BY u.id
                ORDER BY completed DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $date, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        $total_completed = 0;
        $total_time = 0;
        
        while ($row = $result->fetch_assoc()) {
            // Stats Calculation
            $row['avg_time'] = $row['completed'] > 0 ? round($row['total_time'] / $row['completed']) : 0;
            // Progress based on User's Daily Target
            $row['target_progress'] = $row['daily_target'] > 0 ? round(($row['completed'] / $row['daily_target']) * 100) : 0;
            
            $total_completed += $row['completed'];
            $total_time += $row['total_time'];
            $data[] = $row;
        }
        
        // Overall stats
        $overall = [
            'date' => $date,
            'total_completed' => $total_completed,
            'total_time' => $total_time,
            'avg_time' => $total_completed > 0 ? round($total_time / $total_completed) : 0,
            'user_count' => count($data)
        ];
        
        send_json(['status' => 'success', 'data' => $data, 'overall' => $overall]);
    }
    
    // Get weekly report
    if ($action == 'get_weekly_report') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $end_date = $_POST['end_date'] ?? date('Y-m-d');
        
        // Daily breakdown - Strict Completed Check
        $sql = "SELECT 
                    DATE(updated_at) as date,
                    SUM(CASE WHEN row_status = 'Completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN row_status = 'Completed' THEN time_spent ELSE 0 END) as total_time
                FROM client_records
                WHERE DATE(updated_at) BETWEEN ? AND ?
                GROUP BY DATE(updated_at)
                ORDER BY date ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $daily_data = [];
        while ($row = $result->fetch_assoc()) {
            $daily_data[] = $row;
        }
        
        // Per-user breakdown - Strict Completed Check
        $sql = "SELECT 
                    u.username, u.full_name,
                    SUM(CASE WHEN cr.row_status = 'Completed' AND DATE(cr.updated_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN cr.row_status = 'Completed' AND DATE(cr.updated_at) BETWEEN ? AND ? THEN cr.time_spent ELSE 0 END) as total_time
                FROM users u
                LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id)
                WHERE u.`role` = 'deo'
                GROUP BY u.id
                ORDER BY completed DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $user_data = [];
        while ($row = $result->fetch_assoc()) {
            $row['avg_time'] = $row['completed'] > 0 ? round($row['total_time'] / $row['completed']) : 0;
            $user_data[] = $row;
        }
        
        send_json(['status' => 'success', 'daily_data' => $daily_data, 'user_data' => $user_data, 'start_date' => $start_date, 'end_date' => $end_date]);
    }
    
    // Get monthly report
    if ($action == 'get_monthly_report') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $month = $_POST['month'] ?? date('Y-m');
        
        $sql = "SELECT 
                    u.username, u.full_name,
                    (SELECT COUNT(*) FROM client_records WHERE assigned_to = u.username OR assigned_to_id = u.id) as daily_target, 
                    SUM(CASE WHEN cr.row_status IN ('done','Completed') AND DATE_FORMAT(cr.updated_at, '%Y-%m') = ? THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN cr.row_status IN ('done','Completed') AND DATE_FORMAT(cr.updated_at, '%Y-%m') = ? THEN cr.time_spent ELSE 0 END) as total_time,
                    COUNT(DISTINCT CASE WHEN cr.row_status IN ('done','Completed') AND DATE_FORMAT(cr.updated_at, '%Y-%m') = ? THEN DATE(cr.updated_at) END) as working_days
                FROM users u
                LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id)
                WHERE u.`role` = 'deo'
                GROUP BY u.id
                ORDER BY completed DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $month, $month, $month);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        $total_completed = 0;
        
        while ($row = $result->fetch_assoc()) {
            $row['avg_time'] = $row['completed'] > 0 ? round($row['total_time'] / $row['completed']) : 0;
            $row['daily_avg'] = $row['working_days'] > 0 ? round($row['completed'] / $row['working_days']) : 0;
            $row['target_progress'] = $row['daily_target'] > 0 ? round(($row['completed'] / $row['daily_target']) * 100) : 0;
            
            $total_completed += $row['completed'];
            $data[] = $row;
        }
        
        // Weekly breakdown
        $sql = "SELECT 
                    WEEK(updated_at) as week_num,
                    MIN(DATE(updated_at)) as week_start,
                    SUM(CASE WHEN row_status IN ('done','Completed') THEN 1 ELSE 0 END) as completed
                FROM client_records
                WHERE DATE_FORMAT(updated_at, '%Y-%m') = ?
                GROUP BY WEEK(updated_at)
                ORDER BY week_num ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $month);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $weekly_data = [];
        while ($row = $result->fetch_assoc()) {
            $weekly_data[] = $row;
        }
        
        send_json(['status' => 'success', 'data' => $data, 'weekly_data' => $weekly_data, 'month' => $month, 'total_completed' => $total_completed]);
    }
    
    
    // Get performance chart data
    if ($action == 'get_chart_data') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $type = $_POST['type'] ?? 'daily'; // daily, weekly, monthly
        $username = $_POST['username'] ?? '';
        $days = intval($_POST['days'] ?? 30);
        
        $user_filter = "";
        if (!empty($username)) {
            $user_filter = " AND username = '" . $conn->real_escape_string($username) . "'";
        }
        
        if ($type === 'daily') {
            $sql = "SELECT 
                        DATE(updated_at) as label,
                        COUNT(CASE WHEN row_status IN ('done', 'Completed') THEN 1 END) as completed,
                        SUM(time_spent) as total_time
                    FROM client_records
                    WHERE updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY) $user_filter
                    GROUP BY DATE(updated_at)
                    ORDER BY label ASC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $days);
        } elseif ($type === 'weekly') {
            $sql = "SELECT 
                        CONCAT(YEAR(updated_at), '-W', LPAD(WEEK(updated_at), 2, '0')) as label,
                        COUNT(CASE WHEN row_status IN ('done', 'Completed') THEN 1 END) as completed,
                        SUM(time_spent) as total_time
                    FROM client_records
                    WHERE updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY) $user_filter
                    GROUP BY YEAR(updated_at), WEEK(updated_at)
                    ORDER BY label ASC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $days);
        } else {
            $sql = "SELECT 
                        DATE_FORMAT(updated_at, '%Y-%m') as label,
                        COUNT(CASE WHEN row_status IN ('done', 'Completed') THEN 1 END) as completed,
                        SUM(time_spent) as total_time
                    FROM client_records
                    WHERE updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY) $user_filter
                    GROUP BY DATE_FORMAT(updated_at, '%Y-%m')
                    ORDER BY label ASC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $days);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $labels = [];
        $completed_data = [];
        $time_data = [];
        
        while ($row = $result->fetch_assoc()) {
            $labels[] = $row['label'];
            $completed_data[] = intval($row['completed']);
            $time_data[] = round(intval($row['total_time']) / 60); // Convert to minutes
        }
        
        send_json(['status' => 'success', 'labels' => $labels, 'completed' => $completed_data, 'time' => $time_data]);
    }
    
    // Export report to CSV
    if ($action == 'export_report_csv') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $report_type = $_POST['report_type'] ?? 'daily';
        $date = $_POST['date'] ?? date('Y-m-d');
        $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $end_date = $_POST['end_date'] ?? date('Y-m-d');
        $month = $_POST['month'] ?? date('Y-m');
        
        $csv_data = [];
        $filename = '';
        
        if ($report_type === 'daily') {
            $filename = "daily_report_{$date}.csv";
            $csv_data[] = ['Username', 'Full Name', 'Completed', 'Target', 'Progress %', 'Total Time (min)', 'Avg Time (sec)'];
            
            $sql = "SELECT u.username, u.full_name, u.daily_target,
                    COUNT(CASE WHEN cr.row_status IN ('done', 'Completed') AND DATE(cr.updated_at) = ? THEN 1 END) as completed,
                    SUM(CASE WHEN DATE(cr.updated_at) = ? THEN cr.time_spent ELSE 0 END) as total_time
                    FROM users u LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id)
                    WHERE u.`role` = 'deo' GROUP BY u.id ORDER BY completed DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $date, $date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $progress = $row['daily_target'] > 0 ? round(($row['completed'] / $row['daily_target']) * 100) : 0;
                $avg = $row['completed'] > 0 ? round($row['total_time'] / $row['completed']) : 0;
                $csv_data[] = [$row['username'], $row['full_name'], $row['completed'], $row['daily_target'], $progress, round($row['total_time']/60), $avg];
            }
        } elseif ($report_type === 'weekly') {
            $filename = "weekly_report_{$start_date}_to_{$end_date}.csv";
            $csv_data[] = ['Username', 'Full Name', 'Completed', 'Total Time (min)', 'Avg Time (sec)'];
            
            $sql = "SELECT u.username, u.full_name,
                    COUNT(CASE WHEN cr.row_status IN ('done', 'Completed') AND DATE(cr.updated_at) BETWEEN ? AND ? THEN 1 END) as completed,
                    SUM(CASE WHEN DATE(cr.updated_at) BETWEEN ? AND ? THEN cr.time_spent ELSE 0 END) as total_time
                    FROM users u LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id)
                    WHERE u.`role` = 'deo' GROUP BY u.id ORDER BY completed DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $avg = $row['completed'] > 0 ? round($row['total_time'] / $row['completed']) : 0;
                $csv_data[] = [$row['username'], $row['full_name'], $row['completed'], round($row['total_time']/60), $avg];
            }
        } else {
            $filename = "monthly_report_{$month}.csv";
            $csv_data[] = ['Username', 'Full Name', 'Completed', 'Working Days', 'Daily Avg', 'Total Time (min)', 'Avg Time (sec)'];
            
            $sql = "SELECT u.username, u.full_name,
                    COUNT(CASE WHEN cr.row_status IN ('done', 'Completed') AND DATE_FORMAT(cr.updated_at, '%Y-%m') = ? THEN 1 END) as completed,
                    SUM(CASE WHEN DATE_FORMAT(cr.updated_at, '%Y-%m') = ? THEN cr.time_spent ELSE 0 END) as total_time,
                    COUNT(DISTINCT CASE WHEN DATE_FORMAT(cr.updated_at, '%Y-%m') = ? THEN DATE(cr.updated_at) END) as working_days
                    FROM users u LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id)
                    WHERE u.`role` = 'deo' GROUP BY u.id ORDER BY completed DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sss", $month, $month, $month);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $avg = $row['completed'] > 0 ? round($row['total_time'] / $row['completed']) : 0;
                $daily_avg = $row['working_days'] > 0 ? round($row['completed'] / $row['working_days']) : 0;
                $csv_data[] = [$row['username'], $row['full_name'], $row['completed'], $row['working_days'], $daily_avg, round($row['total_time']/60), $avg];
            }
        }
        
        // Generate CSV content
        $csv_content = '';
        foreach ($csv_data as $row) {
            $csv_content .= implode(',', array_map(function($v) { return '"' . str_replace('"', '""', $v) . '"'; }, $row)) . "\n";
        }
        
        send_json(['status' => 'success', 'filename' => $filename, 'content' => base64_encode($csv_content)]);
    }
    
    // Get average time per record stats
    if ($action == 'get_avg_time_stats') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        // Overall average
        $result = $conn->query("SELECT AVG(time_spent) as overall_avg FROM client_records WHERE row_status IN ('done', 'Completed') AND time_spent > 0");
        $overall_avg = round($result->fetch_assoc()['overall_avg'] ?? 0);
        
        // Per user average
        $sql = "SELECT u.username, u.full_name, 
                AVG(CASE WHEN cr.time_spent > 0 THEN cr.time_spent END) as avg_time,
                COUNT(CASE WHEN cr.row_status IN ('done', 'Completed') THEN 1 END) as total_records
                FROM users u
                LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id)
                WHERE u.`role` = 'deo'
                GROUP BY u.id
                HAVING total_records > 0
                ORDER BY avg_time ASC";
        
        $result = $conn->query($sql);
        $user_avgs = [];
        while ($row = $result->fetch_assoc()) {
            $row['avg_time'] = round($row['avg_time']);
            $user_avgs[] = $row;
        }
        
        // Best performer (fastest with good volume)
        $best = null;
        foreach ($user_avgs as $u) {
            if ($u['total_records'] >= 10 && (!$best || $u['avg_time'] < $best['avg_time'])) {
                $best = $u;
            }
        }
        
        send_json(['status' => 'success', 'overall_avg' => $overall_avg, 'user_avgs' => $user_avgs, 'best_performer' => $best]);
    }

    // ==========================================
    // 10. NOTIFICATIONS
    // ==========================================
    
    // Send daily summary to a user
    if ($action == 'send_daily_summary') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $username = $_POST['username'] ?? '';
        $date = $_POST['date'] ?? date('Y-m-d');
        
        // Get user details
        $stmt = $conn->prepare("SELECT phone, full_name, daily_target FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user || empty($user['phone'])) {
            send_json(['status' => 'error', 'message' => 'User not found or no phone number']);
        }
        
        // ✅ FIX: Sirf 'Completed' records count karein
        $stmt = $conn->prepare("SELECT COUNT(*) as completed, SUM(time_spent) as total_time FROM client_records WHERE username = ? AND DATE(updated_at) = ? AND row_status = 'Completed'");
        $stmt->bind_param("ss", $username, $date);
        $stmt->execute();
        $perf = $stmt->get_result()->fetch_assoc();
        
        $completed = $perf['completed'];
        $target = $user['daily_target'];
        $percentage = $target > 0 ? round(($completed / $target) * 100) : 0;
        $avgTime = $completed > 0 ? round($perf['total_time'] / $completed) : 0;
        
        $msg = WhatsAppTemplates::dailySummary($username, $completed, $target, $percentage, $avgTime, $date);
        $sent = sendWhatsApp($user['phone'], $msg);
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'send_notification', 'notifications', null, null, null, null, "Daily summary to $username");
        
        send_json(['status' => $sent ? 'success' : 'error', 'message' => $sent ? 'Notification sent' : 'Failed to send']);
    }
    
    // Send daily summary to all users
    if ($action == 'send_daily_summary_all') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $date = $_POST['date'] ?? date('Y-m-d');
        
        $result = $conn->query("SELECT id, username, phone, full_name, daily_target FROM users WHERE `role` = 'deo' AND phone IS NOT NULL AND phone != ''");
        $sent_count = 0;
        $failed_count = 0;
        
        while ($user = $result->fetch_assoc()) {
            // ✅ FIX: Sirf 'Completed' records count karein
            $stmt = $conn->prepare("SELECT COUNT(*) as completed, SUM(time_spent) as total_time FROM client_records WHERE username = ? AND DATE(updated_at) = ? AND row_status = 'Completed'");
            $stmt->bind_param("ss", $user['username'], $date);
            $stmt->execute();
            $perf = $stmt->get_result()->fetch_assoc();
            
            $completed = $perf['completed'];
            $target = $user['daily_target'];
            $percentage = $target > 0 ? round(($completed / $target) * 100) : 0;
            $avgTime = $completed > 0 ? round($perf['total_time'] / $completed) : 0;
            
            $msg = WhatsAppTemplates::dailySummary($user['username'], $completed, $target, $percentage, $avgTime, $date);
            if (sendWhatsApp($user['phone'], $msg)) {
                $sent_count++;
            } else {
                $failed_count++;
            }
            usleep(500000); // 0.5 second delay between messages
        }
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'send_notification_all', 'notifications', null, null, null, null, "Daily summary to all users");
        
        send_json(['status' => 'success', 'message' => "Sent: $sent_count, Failed: $failed_count"]);
    }
    
    // Send admin daily summary
    if ($action == 'send_admin_daily_summary') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $date = $_POST['date'] ?? date('Y-m-d');
        $admin_phone = $_POST['admin_phone'] ?? '';
        
        if (empty($admin_phone)) {
            // Get current admin's phone
            $stmt = $conn->prepare("SELECT phone FROM users WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();
            $admin_phone = $admin['phone'] ?? '';
        }
        
        if (empty($admin_phone)) {
            send_json(['status' => 'error', 'message' => 'No admin phone number']);
        }
        
        // ✅ FIX: Overall Stats - Sirf 'Completed' records
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM client_records WHERE DATE(updated_at) = ? AND row_status = 'Completed'");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $totalCompleted = $stmt->get_result()->fetch_assoc()['total'];
        
        // ✅ FIX: Top Performers - Sirf 'Completed' records
        $sql = "SELECT u.username, COUNT(cr.id) as completed 
                FROM users u 
                JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id) 
                WHERE DATE(cr.updated_at) = ? AND cr.row_status = 'Completed' AND u.`role` = 'deo'
                GROUP BY u.id 
                ORDER BY completed DESC 
                LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $topPerformers = [];
        while ($row = $result->fetch_assoc()) {
            $topPerformers[] = $row;
        }
        
        // ✅ FIX: Active Users - Sirf 'Completed' records wale
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT username) as total FROM client_records WHERE DATE(updated_at) = ? AND row_status = 'Completed'");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $totalUsers = $stmt->get_result()->fetch_assoc()['total'];
        
        $msg = WhatsAppTemplates::adminDailySummary($date, $totalCompleted, $totalUsers, $topPerformers);
        $sent = sendWhatsApp($admin_phone, $msg);
        
        send_json(['status' => $sent ? 'success' : 'error', 'message' => $sent ? 'Admin summary sent' : 'Failed to send']);
    }
    
    // Send target completion notification
    if ($action == 'send_target_notification') {
        if (!check_session_validity()) {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $username = $_POST['username'] ?? $_SESSION['username'];
        
        // Get user phone and target
        $stmt = $conn->prepare("SELECT phone, daily_target FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user || empty($user['phone'])) {
            send_json(['status' => 'error', 'message' => 'No phone number']);
        }
        
        $msg = WhatsAppTemplates::targetCompleted($username, $user['daily_target'], date('h:i A'));
        $sent = sendWhatsApp($user['phone'], $msg);
        
        send_json(['status' => $sent ? 'success' : 'error']);
    }
    
    // Send low productivity alert (to admin)
    if ($action == 'send_low_productivity_alert') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $username = $_POST['username'] ?? '';
        $admin_phone = $_POST['admin_phone'] ?? '';
        
        // Get user performance
        $stmt = $conn->prepare("SELECT u.daily_target, COUNT(cr.id) as completed 
                                FROM users u 
                                LEFT JOIN client_records cr ON (u.username = cr.assigned_to OR u.id = cr.assigned_to_id) AND DATE(cr.updated_at) = CURDATE() AND cr.row_status IN ('done', 'Completed')
                                WHERE u.username = ?
                                GROUP BY u.id");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user) {
            send_json(['status' => 'error', 'message' => 'User not found']);
        }
        
        $percentage = $user['daily_target'] > 0 ? round(($user['completed'] / $user['daily_target']) * 100) : 0;
        
        $msg = WhatsAppTemplates::lowProductivityAlert($username, $user['completed'], $user['daily_target'], $percentage);
        $sent = sendWhatsApp($admin_phone, $msg);
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'low_productivity_alert', 'notifications', null, null, null, null, "Alert for $username");
        
        send_json(['status' => $sent ? 'success' : 'error']);
    }
    
    // Send custom notification
    if ($action == 'send_custom_notification') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $target = $_POST['target'] ?? 'all'; // all, user, phone
        $username = $_POST['username'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $title = $_POST['title'] ?? 'Notification';
        $body = $_POST['body'] ?? '';
        
        if (empty($body)) {
            send_json(['status' => 'error', 'message' => 'Message body required']);
        }
        
        $msg = WhatsAppTemplates::customMessage($title, $body);
        $sent_count = 0;
        $failed_count = 0;
        
        if ($target === 'all') {
            $result = $conn->query("SELECT phone FROM users WHERE phone IS NOT NULL AND phone != ''");
            while ($row = $result->fetch_assoc()) {
                if (sendWhatsApp($row['phone'], $msg)) $sent_count++;
                else $failed_count++;
                usleep(500000);
            }
        } elseif ($target === 'user' && !empty($username)) {
            $stmt = $conn->prepare("SELECT phone FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user && sendWhatsApp($user['phone'], $msg)) $sent_count++;
            else $failed_count++;
        } elseif ($target === 'phone' && !empty($phone)) {
            if (sendWhatsApp($phone, $msg)) $sent_count++;
            else $failed_count++;
        }
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'custom_notification', 'notifications', null, null, null, null, "Custom: $title to $target");
        
        send_json(['status' => 'success', 'message' => "Sent: $sent_count, Failed: $failed_count"]);
    }
    
    // Get notification settings
    if ($action == 'get_notification_settings') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        // ✅ FIX: Ensure table exists before reading
        $conn->query("CREATE TABLE IF NOT EXISTS security_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            description VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        $settings = [
            'auto_daily_summary' => get_security_setting($conn, 'auto_daily_summary', '0'),
            'auto_target_notification' => get_security_setting($conn, 'auto_target_notification', '1'),
            'low_productivity_threshold' => get_security_setting($conn, 'low_productivity_threshold', '30'),
            'admin_daily_summary' => get_security_setting($conn, 'admin_daily_summary', '0')
        ];
        
        send_json(['status' => 'success', 'data' => $settings]);
    }
    
    // Update notification settings
    if ($action == 'update_notification_settings') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        $settings = [
            'auto_daily_summary' => $_POST['auto_daily_summary'] ?? '0',
            'auto_target_notification' => $_POST['auto_target_notification'] ?? '1',
            'low_productivity_threshold' => $_POST['low_productivity_threshold'] ?? '30',
            'admin_daily_summary' => $_POST['admin_daily_summary'] ?? '0'
        ];
        
        // ✅ FIX: Ensure table exists before saving
        $create_result = $conn->query("CREATE TABLE IF NOT EXISTS security_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            description VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        if (!$create_result) {
            send_json(['status' => 'error', 'message' => 'Failed to create settings table: ' . $conn->error]);
        }
        
        $success_count = 0;
        $error_msg = '';
        
        foreach ($settings as $key => $value) {
            // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing settings
            $stmt = $conn->prepare("INSERT INTO security_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            if ($stmt) {
                $stmt->bind_param("sss", $key, $value, $value);
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_msg .= "Failed to save $key: " . $stmt->error . "; ";
                }
                $stmt->close();
            } else {
                $error_msg .= "Failed to prepare statement for $key: " . $conn->error . "; ";
            }
        }
        
        if ($success_count == count($settings)) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'update_notification_settings', 'notifications');
            send_json(['status' => 'success', 'message' => 'All settings saved successfully']);
        } else {
            send_json(['status' => 'partial', 'message' => "Saved $success_count/" . count($settings) . " settings. Errors: $error_msg"]);
        }
    }

    // ==========================================
    // 11. DATA MANAGEMENT
    // ==========================================
    
    // Bulk delete records
    if ($action == 'bulk_delete_records') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $ids = $_POST['ids'] ?? '';
        $delete_type = $_POST['delete_type'] ?? 'selected'; // selected, status, date_range, all
        $status_filter = $_POST['status_filter'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        
        $deleted = 0;
        
        if ($delete_type === 'selected' && !empty($ids)) {
            $id_arr = array_map('intval', explode(',', $ids));
            $placeholders = implode(',', $id_arr);
            $deleted = $conn->query("DELETE FROM client_records WHERE id IN ($placeholders)")->affected_rows;
        } elseif ($delete_type === 'status' && !empty($status_filter)) {
            $stmt = $conn->prepare("DELETE FROM client_records WHERE row_status = ?");
            $stmt->bind_param("s", $status_filter);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
        } elseif ($delete_type === 'date_range' && !empty($start_date) && !empty($end_date)) {
            $stmt = $conn->prepare("DELETE FROM client_records WHERE DATE(created_at) BETWEEN ? AND ?");
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
        } elseif ($delete_type === 'all') {
            $deleted = $conn->query("DELETE FROM client_records")->affected_rows;
        }
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'bulk_delete', 'data_management', null, null, null, null, "Deleted $deleted records ($delete_type)");
        
        send_json(['status' => 'success', 'message' => "$deleted records deleted"]);
    }
    
    // Database backup (export to SQL)
    if ($action == 'backup_database') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $tables = $_POST['tables'] ?? 'all'; // all, records, users
        
        $sql_content = "-- BPO Dashboard Backup\n";
        $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        $table_list = [];
        if ($tables === 'all') {
            $table_list = ['users', 'client_records', 'activity_logs', 'login_attempts'];
        } elseif ($tables === 'records') {
            $table_list = ['client_records'];
        } elseif ($tables === 'users') {
            $table_list = ['users'];
        }
        
        foreach ($table_list as $table) {
            $result = $conn->query("SELECT * FROM $table");
            if ($result && $result->num_rows > 0) {
                $sql_content .= "-- Table: $table\n";
                $sql_content .= "DELETE FROM `$table`;\n";
                
                while ($row = $result->fetch_assoc()) {
                    $values = array_map(function($v) use ($conn) {
                        return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                    }, array_values($row));
                    $cols = implode('`, `', array_keys($row));
                    $vals = implode(', ', $values);
                    $sql_content .= "INSERT INTO `$table` (`$cols`) VALUES ($vals);\n";
                }
                $sql_content .= "\n";
            }
        }
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'backup_database', 'data_management', null, null, null, null, "Backup: $tables");
        
        $filename = "backup_" . date('Y-m-d_H-i-s') . ".sql";
        send_json(['status' => 'success', 'filename' => $filename, 'content' => base64_encode($sql_content)]);
    }
    
    // Export records to CSV
    if ($action == 'export_records_csv') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $status_filter = $_POST['status_filter'] ?? '';
        $username_filter = $_POST['username_filter'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        
        $sql = "SELECT * FROM client_records WHERE 1=1";
        $params = [];
        $types = "";
        
        if (!empty($status_filter)) {
            $sql .= " AND row_status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }
        if (!empty($username_filter)) {
            $sql .= " AND username = ?";
            $params[] = $username_filter;
            $types .= "s";
        }
        if (!empty($start_date) && !empty($end_date)) {
            $sql .= " AND DATE(updated_at) BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
            $types .= "ss";
        }
        $sql .= " ORDER BY id ASC";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $csv_data = [];
        $headers_added = false;
        
        while ($row = $result->fetch_assoc()) {
            if (!$headers_added) {
                $csv_data[] = array_keys($row);
                $headers_added = true;
            }
            $csv_data[] = array_values($row);
        }
        
        // Generate CSV
        $csv_content = '';
        foreach ($csv_data as $row) {
            $csv_content .= implode(',', array_map(function($v) { 
                return '"' . str_replace('"', '""', $v ?? '') . '"'; 
            }, $row)) . "\n";
        }
        
        $filename = "records_export_" . date('Y-m-d_H-i-s') . ".csv";
        send_json(['status' => 'success', 'filename' => $filename, 'content' => base64_encode($csv_content), 'count' => count($csv_data) - 1]);
    }
    
    // Check for duplicate records
    if ($action == 'check_duplicates') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $field = $_POST['field'] ?? 'record_no'; // record_no, kyc_number, name
        
        $sql = "SELECT `$field`, COUNT(*) as count, GROUP_CONCAT(id) as ids 
                FROM client_records 
                WHERE `$field` IS NOT NULL AND `$field` != ''
                GROUP BY `$field` 
                HAVING count > 1 
                ORDER BY count DESC 
                LIMIT 100";
        
        $result = $conn->query($sql);
        $duplicates = [];
        
        while ($row = $result->fetch_assoc()) {
            $duplicates[] = $row;
        }
        
        send_json(['status' => 'success', 'data' => $duplicates, 'total' => count($duplicates)]);
    }
    
    // Delete duplicate records (keep first)
    if ($action == 'delete_duplicates') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $field = $_POST['field'] ?? 'record_no';
        
        // Get duplicates
        $sql = "SELECT `$field`, GROUP_CONCAT(id ORDER BY id ASC) as ids 
                FROM client_records 
                WHERE `$field` IS NOT NULL AND `$field` != ''
                GROUP BY `$field` 
                HAVING COUNT(*) > 1";
        
        $result = $conn->query($sql);
        $deleted = 0;
        
        while ($row = $result->fetch_assoc()) {
            $ids = explode(',', $row['ids']);
            array_shift($ids); // Keep first, delete rest
            if (!empty($ids)) {
                $delete_ids = implode(',', array_map('intval', $ids));
                $conn->query("DELETE FROM client_records WHERE id IN ($delete_ids)");
                $deleted += count($ids);
            }
        }
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'delete_duplicates', 'data_management', null, null, null, null, "Deleted $deleted duplicates by $field");
        
        send_json(['status' => 'success', 'message' => "$deleted duplicate records deleted"]);
    }
    
    // Get record history (changes log)
    if ($action == 'get_record_history') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $record_id = intval($_POST['record_id'] ?? 0);
        $record_no = $_POST['record_no'] ?? '';
        
        $sql = "SELECT * FROM activity_logs WHERE ";
        if ($record_id > 0) {
            $sql .= "record_id = $record_id";
        } else {
            $sql .= "record_no = '" . $conn->real_escape_string($record_no) . "'";
        }
        $sql .= " ORDER BY created_at DESC LIMIT 50";
        
        $result = $conn->query($sql);
        $history = [];
        
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        send_json(['status' => 'success', 'data' => $history]);
    }
    
    // Restore backup (import SQL)
    if ($action == 'restore_backup') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $sql_content = $_POST['sql_content'] ?? '';
        
        if (empty($sql_content)) {
            send_json(['status' => 'error', 'message' => 'No SQL content provided']);
        }
        
        // Decode if base64
        if (base64_encode(base64_decode($sql_content, true)) === $sql_content) {
            $sql_content = base64_decode($sql_content);
        }
        
        // Split into statements
        $statements = array_filter(array_map('trim', explode(';', $sql_content)));
        $executed = 0;
        $errors = [];
        
        foreach ($statements as $stmt) {
            if (empty($stmt) || strpos($stmt, '--') === 0) continue;
            
            if ($conn->query($stmt)) {
                $executed++;
            } else {
                $errors[] = $conn->error;
            }
        }
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'restore_backup', 'data_management', null, null, null, null, "Executed $executed statements");
        
        send_json(['status' => 'success', 'message' => "Executed $executed statements", 'errors' => $errors]);
    }
    
    // Get data statistics
    if ($action == 'get_data_stats') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        $stats = [];
        
        // Total records
        $result = $conn->query("SELECT COUNT(*) as total FROM client_records");
        $stats['total_records'] = $result->fetch_assoc()['total'];
        
        // By status
        $result = $conn->query("SELECT row_status, COUNT(*) as count FROM client_records GROUP BY row_status");
        $stats['by_status'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['by_status'][$row['row_status']] = $row['count'];
        }
        
        // By user
        $result = $conn->query("SELECT username, COUNT(*) as count FROM client_records WHERE username IS NOT NULL GROUP BY username ORDER BY count DESC LIMIT 10");
        $stats['by_user'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['by_user'][] = $row;
        }
        
        // Records with images
        $result = $conn->query("SELECT COUNT(*) as total FROM client_records WHERE image_filename IS NOT NULL AND image_filename != ''");
        $stats['with_images'] = $result->fetch_assoc()['total'];
        
        // Database size (approximate)
        $result = $conn->query("SELECT 
            SUM(data_length + index_length) as size 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()");
        $stats['db_size_bytes'] = $result->fetch_assoc()['size'];
        $stats['db_size_mb'] = round($stats['db_size_bytes'] / (1024 * 1024), 2);
        
        // Records by date (last 7 days)
        $result = $conn->query("SELECT DATE(created_at) as date, COUNT(*) as count FROM client_records WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date");
        $stats['recent_records'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['recent_records'][] = $row;
        }
        
        send_json(['status' => 'success', 'data' => $stats]);
    }
    

    // ==========================================
    // 12. SYSTEM SETTINGS
    // ==========================================
    
    // Get all system settings
    if ($action == 'get_system_settings') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        // Create table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS security_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        $settings = [];
        $result = $conn->query("SELECT setting_key, setting_value FROM security_settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        
        // Add defaults for missing settings
        $defaults = [
            'company_name' => 'BPO Dashboard',
            'company_logo' => '',
            'default_daily_target' => '100',
            'working_hours_start' => '09:00',
            'working_hours_end' => '18:00',
            'auto_logout_enabled' => '1',
            'maintenance_mode' => '0',
            'maintenance_message' => 'System is under maintenance. Please try again later.',
            'allow_registration' => '0',
            'email_notifications' => '0',
            'whatsapp_notifications' => '1',
            'data_retention_days' => '365',
            'max_upload_size_mb' => '10',
            'allowed_file_types' => 'jpg,jpeg,png,pdf',
            'timezone' => 'Asia/Kolkata',
            'date_format' => 'd-m-Y',
            'time_format' => 'h:i A',
            'currency' => 'INR',
            'language' => 'en',
            'theme_color' => '#0d6efd',
            'sidebar_collapsed' => '0',
            'show_completed_records' => '1',
            'auto_refresh_interval' => '60',
            'records_per_page' => '50',
            'enable_ocr' => '1',
            'enable_image_editing' => '1',
            'backup_frequency' => 'daily',
            'last_backup' => '',
            'version' => '2.0.0',
            'master_otp' => '',
            'master_otp_enabled' => '0'
        ];
        
        foreach ($defaults as $key => $value) {
            if (!isset($settings[$key])) {
                $settings[$key] = $value;
            }
        }
        
        send_json(['status' => 'success', 'data' => $settings]);
    }
    
    // Update system setting
    if ($action == 'update_system_setting') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $key = $_POST['key'] ?? '';
        $value = $_POST['value'] ?? '';
        
        if (empty($key)) {
            send_json(['status' => 'error', 'message' => 'Setting key required']);
        }
        
        $stmt = $conn->prepare("INSERT INTO security_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $value, $value);
        
        if ($stmt->execute()) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'update_system_setting', 'settings', null, null, $key, $value);
            send_json(['status' => 'success']);
        } else {
            send_json(['status' => 'error', 'message' => $stmt->error]);
        }
    }
    
    // Update multiple system settings
    if ($action == 'update_system_settings_batch') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $settings = json_decode($_POST['settings'] ?? '{}', true);
        
        if (empty($settings)) {
            send_json(['status' => 'error', 'message' => 'No settings provided']);
        }
        
        // Create table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS security_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        $updated = 0;
        foreach ($settings as $key => $value) {
            $stmt = $conn->prepare("INSERT INTO security_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("sss", $key, $value, $value);
            if ($stmt->execute()) $updated++;
            $stmt->close();
        }
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'update_system_settings', 'settings', null, null, null, null, "Updated $updated settings");
        
        send_json(['status' => 'success', 'message' => "$updated settings saved successfully!"]);
    }
    
    // Toggle Maintenance Mode
    if ($action == 'toggle_maintenance_mode') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        $enabled = $_POST['enabled'] ?? '0';
        $message = $_POST['message'] ?? 'System is under maintenance. Please try again later.';
        
        // Create table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS security_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        // Save maintenance mode setting
        $stmt = $conn->prepare("INSERT INTO security_settings (setting_key, setting_value) VALUES ('maintenance_mode', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("ss", $enabled, $enabled);
        $stmt->execute();
        $stmt->close();
        
        // Save maintenance message
        $stmt = $conn->prepare("INSERT INTO security_settings (setting_key, setting_value) VALUES ('maintenance_message', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("ss", $message, $message);
        $stmt->execute();
        $stmt->close();
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'toggle_maintenance', 'settings', null, null, 'maintenance_mode', $enabled);
        
        $status_text = $enabled === '1' ? 'enabled' : 'disabled';
        send_json(['status' => 'success', 'message' => "Maintenance mode $status_text successfully!"]);
    }
    
    // Clean old logs
    if ($action == 'clean_old_logs') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        $days = intval($_POST['days'] ?? 30);
        $deleted = 0;
        
        // Clean activity_logs if exists
        $check = $conn->query("SHOW TABLES LIKE 'activity_logs'");
        if ($check->num_rows > 0) {
            $conn->query("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
            $deleted += $conn->affected_rows;
        }
        
        // Clean login_attempts if exists
        $check = $conn->query("SHOW TABLES LIKE 'login_attempts'");
        if ($check->num_rows > 0) {
            $conn->query("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
            $deleted += $conn->affected_rows;
        }
        
        // Clean admin_messages if exists
        $check = $conn->query("SHOW TABLES LIKE 'admin_messages'");
        if ($check->num_rows > 0) {
            $conn->query("DELETE FROM admin_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
            $deleted += $conn->affected_rows;
        }
        
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'clean_logs', 'maintenance', null, null, null, null, "Deleted $deleted records older than $days days");
        
        send_json(['status' => 'success', 'message' => "Deleted $deleted log entries older than $days days"]);
    }
    
    // Check for updates (placeholder)
    if ($action == 'check_updates') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        $current_version = '2.0.0';
        $latest_version = '2.0.0'; // In real scenario, fetch from server
        
        send_json([
            'status' => 'success',
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'update_available' => version_compare($latest_version, $current_version, '>'),
            'message' => 'System is up to date!'
        ]);
    }
    
    // Get system info
    if ($action == 'get_system_info') {
        if (!check_session_validity() || $_SESSION['role'] != 'admin') {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        
        $info = [
            'php_version' => phpversion(),
            'mysql_version' => $conn->server_info,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'max_upload_size' => ini_get('upload_max_filesize'),
            'max_post_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'disk_free_space' => round(disk_free_space('/') / (1024 * 1024 * 1024), 2) . ' GB',
            'disk_total_space' => round(disk_total_space('/') / (1024 * 1024 * 1024), 2) . ' GB'
        ];
        
        // Database stats
        $result = $conn->query("SELECT COUNT(*) as users FROM users");
        $info['total_users'] = $result->fetch_assoc()['users'];
        
        $result = $conn->query("SELECT COUNT(*) as records FROM client_records");
        $info['total_records'] = $result->fetch_assoc()['records'];
        
        $result = $conn->query("SELECT COUNT(*) as logs FROM activity_logs");
        $info['total_logs'] = $result->fetch_assoc()['logs'];
        
        send_json(['status' => 'success', 'data' => $info]);
    }
    
    
    // Get dashboard widgets config
    if ($action == 'get_dashboard_config') {
        if (!check_session_validity()) {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $user_id = $_SESSION['user_id'];
        
        $stmt = $conn->prepare("SELECT setting_value FROM security_settings WHERE setting_key = ?");
        $key = "dashboard_config_$user_id";
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            send_json(['status' => 'success', 'data' => json_decode($row['setting_value'], true)]);
        } else {
            // Default config
            send_json(['status' => 'success', 'data' => [
                'show_stats' => true,
                'show_recent' => true,
                'show_chart' => true,
                'compact_mode' => false
            ]]);
        }
    }
    
    // Save dashboard widgets config
    if ($action == 'save_dashboard_config') {
        if (!check_session_validity()) {
            send_json(['status' => 'error', 'message' => 'Unauthorized']);
        }
        $user_id = $_SESSION['user_id'];
        $config = $_POST['config'] ?? '{}';
        
        $key = "dashboard_config_$user_id";
        $stmt = $conn->prepare("INSERT INTO security_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $config, $config);
        
        if ($stmt->execute()) {
            send_json(['status' => 'success']);
        } else {
            send_json(['status' => 'error', 'message' => $stmt->error]);
        }
    }
    
    
    // ==========================================
    // 13. AUTO-ASSIGNMENT & DATABASE SETTINGS (NEW ADDITION)
    // ==========================================

    // ========== AUTO-ASSIGNMENT FEATURE ==========
    // Get DEO list for assignment
if ($action == 'get_deo_list') {
    if (!check_session_validity()) send_json(['status'=>'error', 'message'=>'Session timeout']);
    
    $stmt = $conn->prepare("SELECT id, username, full_name FROM users WHERE `role` = 'deo' AND is_active = 1 ORDER BY full_name ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $deos = [];
    while ($row = $result->fetch_assoc()) {
        $deos[] = $row;
    }
    
    send_json(['status' => 'success', 'deos' => $deos]);
}

    // DATABASE SETTINGS & TABLE MANAGEMENT
// Add this section in api.php (replace existing database settings code from line 3167 onwards)
// ==========================================

    // Get database configuration
    if ($action == 'get_db_config') {
        if (!check_session_validity()) {
            send_json(['status'=>'error', 'message'=>'Session timeout']);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            send_json(['status'=>'error', 'message'=>'Access denied']);
        }
        
        // Read from db_connect.php file
        $config_file = 'db_connect.php';
        if (file_exists($config_file)) {
            $content = file_get_contents($config_file);
            
            // Extract variables - handle both getenv and direct assignment
            preg_match('/\$servername\s*=\s*(?:getenv\([^\)]+\)\s*\?\:\s*)?[\'"]([^\'"]+)[\'"]/', $content, $host_match);
            preg_match('/\$dbname\s*=\s*(?:getenv\([^\)]+\)\s*\?\:\s*)?[\'"]([^\'"]+)[\'"]/', $content, $name_match);
            preg_match('/\$username\s*=\s*(?:getenv\([^\)]+\)\s*\?\:\s*)?[\'"]([^\'"]+)[\'"]/', $content, $user_match);
            
            send_json([
                'status' => 'success',
                'config' => [
                    'host' => $host_match[1] ?? 'localhost',
                    'database' => $name_match[1] ?? '',
                    'username' => $user_match[1] ?? ''
                ]
            ]);
        } else {
            send_json(['status'=>'error', 'message'=>'Config file not found']);
        }
    }

    // Save database configuration
    if ($action == 'save_db_config') {
        if (!check_session_validity()) {
            send_json(['status'=>'error', 'message'=>'Session timeout']);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            send_json(['status'=>'error', 'message'=>'Access denied']);
        }
        
        $new_host = trim($_POST['host'] ?? 'localhost');
        $new_user = trim($_POST['username'] ?? '');
        $new_pass = $_POST['password'] ?? '';
        $new_db = trim($_POST['database'] ?? '');
        
        if (empty($new_user) || empty($new_db)) {
            send_json(['status'=>'error', 'message'=>'Username and Database name required']);
        }
        
        // First test the new connection
        mysqli_report(MYSQLI_REPORT_OFF);
        $test_conn = @new mysqli($new_host, $new_user, $new_pass, $new_db);
        
        if ($test_conn->connect_error) {
            send_json(['status'=>'error', 'message'=>'Connection failed: ' . $test_conn->connect_error]);
        }
        $test_conn->close();
        
        // Create new db_connect.php content
        $new_config = '<?php
/**
 * Database Connection File - Optimized for High Concurrency
 * Supports 50+ concurrent users
 * Last Updated: ' . date('Y-m-d H:i:s') . '
 */

// Database Configuration
$servername = "' . addslashes($new_host) . '";
$username = "' . addslashes($new_user) . '"; 
$password = "' . addslashes($new_pass) . '"; 
$dbname = "' . addslashes($new_db) . '";

// External Database Configuration (if needed)
define(\'EXT_DB_HOST\', $servername);
define(\'EXT_DB_USER\', $username);
define(\'EXT_DB_PASS\', $password);
define(\'EXT_DB_NAME\', $dbname);

// Error reporting off
mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Get Database Connection with Persistent Connection Support
 */
function getDbConnection() {
    global $servername, $username, $password, $dbname;
    static $conn = null;
    
    if ($conn === null || !$conn->ping()) {
        $conn = new mysqli("p:" . $servername, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            header(\'Content-Type: application/json\');
            die(json_encode(["status" => "error", "message" => "DB Connection Failed"]));
        }
        
        $conn->set_charset("utf8mb4");
        $conn->query("SET SESSION wait_timeout=300");
        $conn->query("SET SESSION interactive_timeout=300");
    }
    
    return $conn;
}

// Initialize main connection
$conn = getDbConnection();

/**
 * Get External Database Connection
 */
function getExternalDbConnection() {
    static $ext_conn = null;
    
    if ($ext_conn === null || !$ext_conn->ping()) {
        $ext_conn = new mysqli("p:" . EXT_DB_HOST, EXT_DB_USER, EXT_DB_PASS, EXT_DB_NAME);
        if ($ext_conn->connect_error) {
            return null;
        }
        $ext_conn->set_charset("utf8mb4");
    }
    
    return $ext_conn;
}

/**
 * Simple Query Cache for repeated queries
 */
class QueryCache {
    private static $cache = [];
    private static $max_size = 100;
    private static $ttl = 30;
    
    public static function get($key) {
        if (isset(self::$cache[$key])) {
            if (time() - self::$cache[$key][\'time\'] < self::$ttl) {
                return self::$cache[$key][\'data\'];
            }
            unset(self::$cache[$key]);
        }
        return null;
    }
    
    public static function set($key, $data) {
        if (count(self::$cache) >= self::$max_size) {
            array_shift(self::$cache);
        }
        self::$cache[$key] = [\'data\' => $data, \'time\' => time()];
    }
    
    public static function clear($pattern = null) {
        if ($pattern === null) {
            self::$cache = [];
        } else {
            foreach (self::$cache as $key => $val) {
                if (strpos($key, $pattern) !== false) {
                    unset(self::$cache[$key]);
                }
            }
        }
    }
}';
        
        // Backup old file
        $backup_file = 'db_connect.backup.' . date('Y-m-d_H-i-s') . '.php';
        if (file_exists('db_connect.php')) {
            copy('db_connect.php', $backup_file);
        }
        
        // Write new config
        if (file_put_contents('db_connect.php', $new_config)) {
            log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'db_config_updated', 'database', null, null, null, null, "Database config updated to: $new_host / $new_db");
            send_json(['status'=>'success', 'message'=>'Database configuration saved! Backup created: ' . $backup_file]);
        } else {
            send_json(['status'=>'error', 'message'=>'Failed to write config file. Check file permissions.']);
        }
    }

    // Test database connection with custom credentials
    if ($action == 'test_db_connection') {
        if (!check_session_validity()) {
            send_json(['status'=>'error', 'message'=>'Session timeout']);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            send_json(['status'=>'error', 'message'=>'Access denied']);
        }
        
        // Check if testing with new credentials or current connection
        $test_host = trim($_POST['host'] ?? '');
        $test_user = trim($_POST['username'] ?? '');
        $test_pass = $_POST['password'] ?? '';
        $test_db = trim($_POST['database'] ?? '');
        
        if (!empty($test_host) && !empty($test_user) && !empty($test_db)) {
            // Test with provided credentials
            mysqli_report(MYSQLI_REPORT_OFF);
            $test_conn = @new mysqli($test_host, $test_user, $test_pass, $test_db);
            
            if ($test_conn->connect_error) {
                send_json([
                    'status' => 'error',
                    'message' => 'Connection failed: ' . $test_conn->connect_error
                ]);
            }
            
            $result = $test_conn->query("SELECT DATABASE() as db, VERSION() as version");
            $row = $result->fetch_assoc();
            
            send_json([
                'status' => 'success',
                'database' => $row['db'],
                'version' => $row['version'],
                'server' => $test_conn->host_info
            ]);
            
            $test_conn->close();
        } else {
            // Test current connection
            try {
                $test_query = $conn->query("SELECT DATABASE() as db, VERSION() as version");
                
                if ($test_query) {
                    $row = $test_query->fetch_assoc();
                    send_json([
                        'status' => 'success',
                        'database' => $row['db'],
                        'version' => $row['version'],
                        'server' => $conn->host_info
                    ]);
                } else {
                    send_json([
                        'status' => 'error',
                        'message' => 'Query failed: ' . $conn->error
                    ]);
                }
            } catch (Exception $e) {
                send_json([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
        }
    }

    // Check which tables exist
    if ($action == 'check_tables') {
        if (!check_session_validity()) {
            send_json(['status'=>'error', 'message'=>'Session timeout']);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            send_json(['status'=>'error', 'message'=>'Access denied']);
        }
        
        // All required tables for both Project 1 and Project 2
        $required_tables = [
            // Core Tables
            'users',
            'client_records',
            'assignments',
            'work_logs',
            'record_image_map',
            
            // Security & Logging
            'activity_logs',
            'admin_logs',
            'audit_trail',
            'login_attempts',
            'login_history',
            'security_settings',
            'allowed_ips',
            'user_sessions',
            
            // DQC & Errors
            'dqc_flags',
            'critical_errors',
            'field_errors',
            
            // Communication
            'announcements',
            'announcement_reads',
            'chat_messages',
            'admin_messages',
            'broadcast_messages',
            'notifications',
            'record_comments',
            'record_discussions',
            
            // User Features
            'user_stats',
            'user_badges',
            'badges',
            'user_preferences',
            'daily_targets',
            'deo_progress',
            
            // System
            'system_settings',
            'backup_logs',
            'performance_logs',
            'saved_filters',
            'reply_templates'
        ];
        
        $result = [];
        $existing_count = 0;
        $missing_count = 0;
        
        foreach ($required_tables as $table) {
            $check = $conn->query("SHOW TABLES LIKE '$table'");
            $exists = ($check && $check->num_rows > 0);
            
            if ($exists) {
                // Get row count for existing tables
                $count_result = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
                $row_count = $count_result ? $count_result->fetch_assoc()['cnt'] : 0;
                $existing_count++;
            } else {
                $row_count = 0;
                $missing_count++;
            }
            
            $result[] = [
                'name' => $table,
                'exists' => $exists,
                'rows' => $row_count
            ];
        }
        
        send_json([
            'status' => 'success',
            'tables' => $result,
            'summary' => [
                'total' => count($required_tables),
                'existing' => $existing_count,
                'missing' => $missing_count
            ]
        ]);
    }

    // Create ALL missing tables (Project 1 + Project 2)
    if ($action == 'create_tables') {
        if (!check_session_validity()) {
            send_json(['status'=>'error', 'message'=>'Session timeout']);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            send_json(['status'=>'error', 'message'=>'Access denied']);
        }
        
        $created = [];
        $skipped = [];
        $errors = [];
        
        // Complete table definitions for both projects
        $tables_sql = [
            
            // ========== CORE TABLES ==========
            
            'users' => "
                CREATE TABLE IF NOT EXISTS `users` (
                    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `username` varchar(50) NOT NULL,
                    `password` varchar(255) NOT NULL,
                    `full_name` varchar(255) DEFAULT NULL,
                    `avatar` varchar(255) DEFAULT NULL,
                    `phone` varchar(20) DEFAULT NULL,
                    `mobile_number` varchar(20) DEFAULT NULL,
                    `role` enum('admin','supervisor','deo','dqc','qc') DEFAULT 'deo',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `otp` varchar(10) DEFAULT NULL,
                    `otp_expiry` datetime DEFAULT NULL,
                    `otp_attempts` int(11) DEFAULT 0,
                    `otp_enabled` tinyint(1) DEFAULT 0,
                    `status` enum('active','inactive') DEFAULT 'active',
                    `is_active` tinyint(4) DEFAULT 1,
                    `failed_attempts` int(11) DEFAULT 0,
                    `locked_until` datetime DEFAULT NULL,
                    `last_login` datetime DEFAULT NULL,
                    `last_ip` varchar(45) DEFAULT NULL,
                    `daily_target` int(11) DEFAULT 100,
                    `allowed_ips` text DEFAULT NULL,
                    `permissions` text DEFAULT NULL,
                    `last_activity` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'client_records' => "
                CREATE TABLE IF NOT EXISTS `client_records` (
                    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `record_no` varchar(50) NOT NULL,
                    `kyc_number` varchar(50) DEFAULT NULL,
                    `name` varchar(255) DEFAULT NULL,
                    `guardian_name` varchar(255) DEFAULT NULL,
                    `gender` varchar(20) DEFAULT NULL,
                    `marital_status` varchar(20) DEFAULT NULL,
                    `dob` varchar(50) DEFAULT NULL,
                    `nationality` varchar(50) DEFAULT NULL,
                    `city_of_birth` varchar(100) DEFAULT NULL,
                    `address` text DEFAULT NULL,
                    `landmark` varchar(255) DEFAULT NULL,
                    `city` varchar(100) DEFAULT NULL,
                    `zip_code` varchar(20) DEFAULT NULL,
                    `second_address` text DEFAULT NULL,
                    `residential_status` varchar(50) DEFAULT NULL,
                    `occupation` varchar(100) DEFAULT NULL,
                    `occupation_profession` varchar(100) DEFAULT NULL,
                    `annual_income` varchar(50) DEFAULT NULL,
                    `amount` varchar(50) DEFAULT NULL,
                    `amount_received_from` varchar(255) DEFAULT NULL,
                    `photo_attachment` varchar(100) DEFAULT NULL,
                    `officially_valid_documents` varchar(255) DEFAULT NULL,
                    `image_filename` varchar(255) DEFAULT NULL,
                    `image_path` varchar(255) DEFAULT NULL,
                    `broker_name` varchar(100) DEFAULT NULL,
                    `sub_broker_code` varchar(50) DEFAULT NULL,
                    `arn_no` varchar(50) DEFAULT NULL,
                    `bank_serial_no` varchar(50) DEFAULT NULL,
                    `second_applicant_name` varchar(255) DEFAULT NULL,
                    `row_status` enum('pending','done','deo_done','qc_done','Completed','flagged','in_progress','corrected','pending_qc','qc_approved','qc_rejected') DEFAULT 'pending',
                    `dqc_status` enum('pending','checked','flagged','approved') DEFAULT 'pending',
                    `dqc_checked_by` int(11) DEFAULT NULL,
                    `dqc_checked_at` timestamp NULL DEFAULT NULL,
                    `username` varchar(50) DEFAULT NULL,
                    `assigned_to` varchar(50) DEFAULT NULL,
                    `assigned_to_id` int(11) DEFAULT NULL,
                    `time_spent` int(11) DEFAULT 0,
                    `last_updated_by` varchar(50) DEFAULT NULL,
                    `remarks` text DEFAULT NULL,
                    `edited_fields` longtext DEFAULT NULL,
                    `initial_highlights` longtext DEFAULT NULL,
                    `qc_user_id` int(11) DEFAULT NULL,
                    `qc_by` varchar(100) DEFAULT NULL,
                    `deo_done_at` datetime DEFAULT NULL,
                    `qc_done_at` datetime DEFAULT NULL,
                    `completed_at` datetime DEFAULT NULL,
                    `qc_locked_by` int(11) DEFAULT NULL,
                    `qc_locked_at` datetime DEFAULT NULL,
                    `qc_corrections` text DEFAULT NULL,
                    `qc_at` datetime DEFAULT NULL,
                    `qc_remarks` text DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `record_no` (`record_no`),
                    KEY `assigned_to` (`assigned_to`),
                    KEY `row_status` (`row_status`),
                    KEY `assigned_to_id` (`assigned_to_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'assignments' => "
                CREATE TABLE IF NOT EXISTS `assignments` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `deo_id` int(11) NOT NULL,
                    `record_no_from` varchar(50) NOT NULL,
                    `record_no_to` varchar(50) DEFAULT NULL,
                    `assigned_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_deo_id` (`deo_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'work_logs' => "
                CREATE TABLE IF NOT EXISTS `work_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `deo_id` int(11) DEFAULT NULL,
                    `user_id` int(11) DEFAULT NULL,
                    `record_no` varchar(50) DEFAULT NULL,
                    `record_from` varchar(50) DEFAULT NULL,
                    `record_to` varchar(50) DEFAULT NULL,
                    `record_count` int(11) DEFAULT NULL,
                    `action` varchar(50) DEFAULT NULL,
                    `time_spent` int(11) DEFAULT 0,
                    `log_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `deo_id` (`deo_id`),
                    KEY `log_time` (`log_time`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'record_image_map' => "
                CREATE TABLE IF NOT EXISTS `record_image_map` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `image_no` varchar(100) DEFAULT NULL,
                    `record_no` varchar(100) DEFAULT NULL,
                    `file_path` varchar(255) DEFAULT NULL,
                    `image_path` varchar(255) DEFAULT NULL,
                    `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `record_no` (`record_no`),
                    KEY `image_no` (`image_no`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            // ========== SECURITY & LOGGING ==========
            
            'activity_logs' => "
                CREATE TABLE IF NOT EXISTS `activity_logs` (
                    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) UNSIGNED DEFAULT NULL,
                    `username` varchar(50) DEFAULT NULL,
                    `action` varchar(100) NOT NULL,
                    `module` varchar(50) DEFAULT NULL,
                    `record_id` int(11) DEFAULT NULL,
                    `record_no` varchar(50) DEFAULT NULL,
                    `old_value` text DEFAULT NULL,
                    `new_value` text DEFAULT NULL,
                    `ip_address` varchar(50) DEFAULT NULL,
                    `user_agent` text DEFAULT NULL,
                    `details` text DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_user` (`user_id`),
                    KEY `idx_action` (`action`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'admin_logs' => "
                CREATE TABLE IF NOT EXISTS `admin_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `admin_id` int(11) DEFAULT NULL,
                    `action_type` varchar(50) DEFAULT NULL,
                    `description` text DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_admin_id` (`admin_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'audit_trail' => "
                CREATE TABLE IF NOT EXISTS `audit_trail` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) DEFAULT NULL,
                    `action` varchar(100) NOT NULL,
                    `table_name` varchar(50) DEFAULT NULL,
                    `record_id` varchar(50) DEFAULT NULL,
                    `old_value` text DEFAULT NULL,
                    `new_value` text DEFAULT NULL,
                    `ip_address` varchar(45) DEFAULT NULL,
                    `user_agent` varchar(255) DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_id` (`user_id`),
                    KEY `idx_action` (`action`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'login_attempts' => "
                CREATE TABLE IF NOT EXISTS `login_attempts` (
                    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `username` varchar(50) NOT NULL,
                    `ip_address` varchar(50) NOT NULL,
                    `user_agent` text DEFAULT NULL,
                    `status` enum('success','failed','blocked') NOT NULL,
                    `failure_reason` varchar(100) DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `username` (`username`),
                    KEY `ip_address` (`ip_address`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'login_history' => "
                CREATE TABLE IF NOT EXISTS `login_history` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `ip_address` varchar(45) DEFAULT NULL,
                    `user_agent` varchar(255) DEFAULT NULL,
                    `device_type` varchar(50) DEFAULT NULL,
                    `browser` varchar(50) DEFAULT NULL,
                    `location` varchar(100) DEFAULT NULL,
                    `status` enum('success','failed') DEFAULT 'success',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'security_settings' => "
                CREATE TABLE IF NOT EXISTS `security_settings` (
                    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `setting_key` varchar(50) NOT NULL,
                    `setting_value` varchar(255) NOT NULL,
                    `description` varchar(255) DEFAULT NULL,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `setting_key` (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'allowed_ips' => "
                CREATE TABLE IF NOT EXISTS `allowed_ips` (
                    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `ip_address` varchar(50) NOT NULL,
                    `description` varchar(255) DEFAULT NULL,
                    `is_active` tinyint(1) DEFAULT 1,
                    `created_by` int(11) DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `ip_address` (`ip_address`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'user_sessions' => "
                CREATE TABLE IF NOT EXISTS `user_sessions` (
                    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) UNSIGNED NOT NULL,
                    `session_token` varchar(255) NOT NULL,
                    `ip_address` varchar(50) DEFAULT NULL,
                    `user_agent` text DEFAULT NULL,
                    `last_activity` datetime DEFAULT NULL,
                    `expires_at` datetime DEFAULT NULL,
                    `is_active` tinyint(1) DEFAULT 1,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            // ========== DQC & ERRORS ==========
            
            'dqc_flags' => "
                CREATE TABLE IF NOT EXISTS `dqc_flags` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `record_no` varchar(50) NOT NULL,
                    `image_no` varchar(50) DEFAULT NULL,
                    `dqc_id` int(11) NOT NULL,
                    `flagged_fields` text NOT NULL,
                    `remarks` text DEFAULT NULL,
                    `status` enum('flagged','corrected') DEFAULT 'flagged',
                    `flagged_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `corrected_date` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `record_no` (`record_no`),
                    KEY `dqc_id` (`dqc_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'critical_errors' => "
                CREATE TABLE IF NOT EXISTS `critical_errors` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `record_no` varchar(50) NOT NULL,
                    `deo_id` int(11) NOT NULL,
                    `error_field` varchar(100) NOT NULL,
                    `error_details` text NOT NULL,
                    `admin_remark` text DEFAULT NULL,
                    `status` enum('pending','admin_reviewed','resolved') DEFAULT 'pending',
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `reviewed_at` timestamp NULL DEFAULT NULL,
                    `resolved_at` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `record_no` (`record_no`),
                    KEY `deo_id` (`deo_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'field_errors' => "
                CREATE TABLE IF NOT EXISTS `field_errors` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `record_id` int(11) DEFAULT NULL,
                    `field_name` varchar(50) DEFAULT NULL,
                    `error_type` varchar(50) DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            // ========== COMMUNICATION ==========
            
            'announcements' => "
                CREATE TABLE IF NOT EXISTS `announcements` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `admin_id` int(11) NOT NULL,
                    `title` varchar(255) NOT NULL,
                    `content` text NOT NULL,
                    `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
                    `target_role` enum('all','deo','dqc','admin','supervisor') DEFAULT 'all',
                    `is_active` tinyint(4) DEFAULT 1,
                    `expires_at` timestamp NULL DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_admin_id` (`admin_id`),
                    KEY `idx_priority` (`priority`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'announcement_reads' => "
                CREATE TABLE IF NOT EXISTS `announcement_reads` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `announcement_id` int(11) NOT NULL,
                    `user_id` int(11) NOT NULL,
                    `read_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_read` (`announcement_id`,`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'chat_messages' => "
                CREATE TABLE IF NOT EXISTS `chat_messages` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `sender_id` int(11) NOT NULL,
                    `receiver_id` int(11) NOT NULL,
                    `message` text NOT NULL,
                    `is_read` tinyint(4) DEFAULT 0,
                    `read_at` timestamp NULL DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_sender` (`sender_id`),
                    KEY `idx_receiver` (`receiver_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'admin_messages' => "
                CREATE TABLE IF NOT EXISTS `admin_messages` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `from_user_id` int(11) NOT NULL,
                    `to_user_id` int(11) NOT NULL,
                    `message` text NOT NULL,
                    `priority` varchar(20) DEFAULT 'normal',
                    `is_read` tinyint(1) DEFAULT 0,
                    `read_at` datetime DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'broadcast_messages' => "
                CREATE TABLE IF NOT EXISTS `broadcast_messages` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `message` text DEFAULT NULL,
                    `target_user` varchar(100) DEFAULT NULL,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'notifications' => "
                CREATE TABLE IF NOT EXISTS `notifications` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `type` varchar(50) NOT NULL,
                    `title` varchar(255) NOT NULL,
                    `message` text DEFAULT NULL,
                    `link` varchar(255) DEFAULT NULL,
                    `is_read` tinyint(4) DEFAULT 0,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'record_comments' => "
                CREATE TABLE IF NOT EXISTS `record_comments` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `record_no` varchar(50) NOT NULL,
                    `user_id` int(11) NOT NULL,
                    `comment` text NOT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `record_no` (`record_no`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'record_discussions' => "
                CREATE TABLE IF NOT EXISTS `record_discussions` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `record_no` varchar(50) NOT NULL,
                    `user_id` int(11) NOT NULL,
                    `parent_id` int(11) DEFAULT NULL,
                    `message` text NOT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `record_no` (`record_no`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            // ========== USER FEATURES ==========
            
            'user_stats' => "
                CREATE TABLE IF NOT EXISTS `user_stats` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `total_records` int(11) DEFAULT 0,
                    `total_completed` int(11) DEFAULT 0,
                    `total_errors` int(11) DEFAULT 0,
                    `accuracy_rate` decimal(5,2) DEFAULT 100.00,
                    `current_streak` int(11) DEFAULT 0,
                    `best_streak` int(11) DEFAULT 0,
                    `total_points` int(11) DEFAULT 0,
                    `avg_speed` decimal(10,2) DEFAULT 0.00,
                    `last_active` timestamp NULL DEFAULT NULL,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'badges' => "
                CREATE TABLE IF NOT EXISTS `badges` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(100) NOT NULL,
                    `description` text DEFAULT NULL,
                    `icon` varchar(50) DEFAULT '🏆',
                    `color` varchar(20) DEFAULT '#fbbf24',
                    `criteria_type` enum('records_completed','accuracy','streak','speed','special') NOT NULL,
                    `criteria_value` int(11) DEFAULT 0,
                    `points` int(11) DEFAULT 10,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'user_badges' => "
                CREATE TABLE IF NOT EXISTS `user_badges` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `badge_id` int(11) NOT NULL,
                    `earned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `user_id` (`user_id`),
                    KEY `badge_id` (`badge_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'user_preferences' => "
                CREATE TABLE IF NOT EXISTS `user_preferences` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `theme` varchar(20) DEFAULT 'light',
                    `language` varchar(10) DEFAULT 'en',
                    `notifications_enabled` tinyint(4) DEFAULT 1,
                    `sound_enabled` tinyint(4) DEFAULT 1,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'daily_targets' => "
                CREATE TABLE IF NOT EXISTS `daily_targets` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `target_date` date NOT NULL,
                    `target_records` int(11) DEFAULT 100,
                    `achieved_records` int(11) DEFAULT 0,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `user_id` (`user_id`),
                    KEY `target_date` (`target_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'deo_progress' => "
                CREATE TABLE IF NOT EXISTS `deo_progress` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `deo_id` int(11) NOT NULL,
                    `record_no` varchar(50) NOT NULL,
                    `status` enum('pending','done','completed') DEFAULT 'pending',
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `deo_id` (`deo_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            // ========== SYSTEM ==========
            
            'system_settings' => "
                CREATE TABLE IF NOT EXISTS `system_settings` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `setting_key` varchar(100) NOT NULL,
                    `setting_value` text DEFAULT NULL,
                    `setting_type` enum('text','number','boolean','json') DEFAULT 'text',
                    `description` varchar(255) DEFAULT NULL,
                    `updated_by` int(11) DEFAULT NULL,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `setting_key` (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'backup_logs' => "
                CREATE TABLE IF NOT EXISTS `backup_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `backup_type` enum('auto','manual') DEFAULT 'auto',
                    `file_name` varchar(255) DEFAULT NULL,
                    `file_size` bigint(20) DEFAULT NULL,
                    `status` enum('success','failed') DEFAULT 'success',
                    `error_message` text DEFAULT NULL,
                    `created_by` int(11) DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'performance_logs' => "
                CREATE TABLE IF NOT EXISTS `performance_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) DEFAULT NULL,
                    `record_id` int(11) DEFAULT NULL,
                    `start_time` datetime DEFAULT NULL,
                    `end_time` datetime DEFAULT NULL,
                    `total_seconds` int(11) DEFAULT NULL,
                    `error_count` int(11) DEFAULT 0,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'saved_filters' => "
                CREATE TABLE IF NOT EXISTS `saved_filters` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `filter_name` varchar(100) NOT NULL,
                    `page_name` varchar(50) NOT NULL,
                    `filter_data` longtext DEFAULT NULL,
                    `is_default` tinyint(4) DEFAULT 0,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            
            'reply_templates' => "
                CREATE TABLE IF NOT EXISTS `reply_templates` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) DEFAULT NULL,
                    `category` varchar(50) DEFAULT 'general',
                    `title` varchar(100) NOT NULL,
                    `content` text NOT NULL,
                    `is_global` tinyint(4) DEFAULT 0,
                    `usage_count` int(11) DEFAULT 0,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            "
        ];
        
        foreach ($tables_sql as $table_name => $sql) {
            $check = $conn->query("SHOW TABLES LIKE '$table_name'");
            
            if ($check && $check->num_rows > 0) {
                $skipped[] = $table_name;
            } else {
                if ($conn->query($sql)) {
                    $created[] = $table_name;
                } else {
                    $errors[] = $table_name . ': ' . $conn->error;
                }
            }
        }
        
        // Insert default security settings if table was just created
        if (in_array('security_settings', $created)) {
            $default_settings = [
                ['max_login_attempts', '5', 'Maximum failed login attempts before lockout'],
                ['lockout_duration', '15', 'Account lockout duration in minutes'],
                ['session_timeout', '30', 'Session timeout in minutes'],
                ['ip_restriction_enabled', '0', 'Enable IP-based access restriction'],
                ['maintenance_mode', '0', 'Enable maintenance mode'],
                ['maintenance_message', 'System is under maintenance', 'Message shown during maintenance'],
                ['master_otp_enabled', '0', 'Enable master OTP for testing'],
                ['master_otp', '', 'Master OTP code']
            ];
            
            foreach ($default_settings as $setting) {
                $conn->query("INSERT IGNORE INTO security_settings (setting_key, setting_value, description) VALUES ('{$setting[0]}', '{$setting[1]}', '{$setting[2]}')");
            }
        }
        
        // Insert default badges if table was just created
        if (in_array('badges', $created)) {
            $default_badges = [
                ['First Steps', 'Complete your first record', '🎯', '#22c55e', 'records_completed', 1, 10],
                ['Getting Started', 'Complete 10 records', '🌟', '#3b82f6', 'records_completed', 10, 25],
                ['Productive Worker', 'Complete 50 records', '💪', '#8b5cf6', 'records_completed', 50, 50],
                ['Century Club', 'Complete 100 records', '💯', '#f59e0b', 'records_completed', 100, 100],
                ['Data Master', 'Complete 500 records', '👑', '#ef4444', 'records_completed', 500, 250],
                ['Perfectionist', 'Achieve 100% accuracy', '✨', '#ec4899', 'accuracy', 100, 100],
                ['Streak Starter', '3 day streak', '🔥', '#f97316', 'streak', 3, 30],
                ['Week Warrior', '7 day streak', '⚡', '#eab308', 'streak', 7, 75]
            ];
            
            foreach ($default_badges as $badge) {
                $conn->query("INSERT INTO badges (name, description, icon, color, criteria_type, criteria_value, points) VALUES ('{$badge[0]}', '{$badge[1]}', '{$badge[2]}', '{$badge[3]}', '{$badge[4]}', {$badge[5]}, {$badge[6]})");
            }
        }
        
        // Log activity
        log_activity($conn, $_SESSION['user_id'], $_SESSION['username'], 'tables_created', 'database', null, null, null, null, 'Created: ' . implode(', ', $created));
        
        if (count($errors) > 0) {
            send_json([
                'status' => 'partial',
                'message' => 'Some tables failed to create',
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors
            ]);
        } else {
            send_json([
                'status' => 'success',
                'created' => $created,
                'skipped' => $skipped,
                'message' => count($created) . ' tables created, ' . count($skipped) . ' already existed'
            ]);
        }
    }

    // Create database views
    if ($action == 'create_views') {
        if (!check_session_validity()) {
            send_json(['status'=>'error', 'message'=>'Session timeout']);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            send_json(['status'=>'error', 'message'=>'Access denied']);
        }
        
        $created = [];
        $errors = [];
        
        // Drop existing views first
        $conn->query("DROP VIEW IF EXISTS `main_data`");
        $conn->query("DROP VIEW IF EXISTS `records`");
        
        // Create main_data view
        $main_data_view = "
            CREATE VIEW `main_data` AS 
            SELECT 
                `client_records`.`id`,
                `client_records`.`record_no`,
                `client_records`.`kyc_number`,
                `client_records`.`name`,
                `client_records`.`guardian_name`,
                `client_records`.`gender`,
                `client_records`.`marital_status`,
                `client_records`.`dob`,
                `client_records`.`nationality`,
                `client_records`.`city_of_birth`,
                `client_records`.`address`,
                `client_records`.`landmark`,
                `client_records`.`city`,
                `client_records`.`zip_code`,
                `client_records`.`second_address`,
                `client_records`.`residential_status`,
                `client_records`.`occupation`,
                `client_records`.`occupation_profession`,
                `client_records`.`annual_income`,
                `client_records`.`amount`,
                `client_records`.`amount_received_from`,
                `client_records`.`photo_attachment`,
                `client_records`.`officially_valid_documents`,
                `client_records`.`image_filename`,
                `client_records`.`image_path`,
                `client_records`.`broker_name`,
                `client_records`.`sub_broker_code`,
                `client_records`.`arn_no`,
                `client_records`.`bank_serial_no`,
                `client_records`.`second_applicant_name`,
                `client_records`.`row_status`,
                `client_records`.`username`,
                COALESCE(`client_records`.`assigned_to`, `client_records`.`username`) AS `assigned_to`,
                `client_records`.`time_spent`,
                `client_records`.`last_updated_by`,
                `client_records`.`remarks`,
                `client_records`.`edited_fields`,
                `client_records`.`initial_highlights`,
                `client_records`.`created_at`,
                `client_records`.`updated_at`
            FROM `client_records`
        ";
        
        if ($conn->query($main_data_view)) {
            $created[] = 'main_data';
        } else {
            $errors[] = 'main_data: ' . $conn->error;
        }
        
        // Create records view (for Project 2 compatibility)
        $records_view = "
            CREATE VIEW `records` AS 
            SELECT 
                `cr`.`id`,
                `cr`.`record_no`,
                `cr`.`kyc_number`,
                `cr`.`name`,
                `cr`.`guardian_name`,
                `cr`.`gender`,
                `cr`.`marital_status`,
                `cr`.`dob`,
                `cr`.`address`,
                `cr`.`landmark`,
                `cr`.`city`,
                `cr`.`zip_code` AS `zip`,
                `cr`.`city_of_birth`,
                `cr`.`nationality`,
                `cr`.`photo_attachment`,
                `cr`.`residential_status`,
                `cr`.`occupation`,
                `cr`.`officially_valid_documents`,
                `cr`.`annual_income`,
                `cr`.`broker_name`,
                `cr`.`sub_broker_code`,
                `cr`.`bank_serial_no`,
                `cr`.`second_applicant_name`,
                `cr`.`amount_received_from` AS `amount_receive_from`,
                `cr`.`amount`,
                `cr`.`arn_no`,
                `cr`.`second_address`,
                `cr`.`occupation_profession`,
                `cr`.`remarks`,
                CASE 
                    WHEN `cr`.`row_status` = 'pending' THEN 'pending'
                    WHEN `cr`.`row_status` = 'done' THEN 'in_progress'
                    WHEN `cr`.`row_status` = 'Completed' THEN 'completed'
                    WHEN `cr`.`row_status` = 'flagged' THEN 'flagged'
                    WHEN `cr`.`row_status` = 'corrected' THEN 'corrected'
                    ELSE 'pending'
                END AS `status`,
                `cr`.`assigned_to_id` AS `assigned_to`,
                `cr`.`dqc_status`,
                `cr`.`dqc_checked_by`,
                `cr`.`dqc_checked_at`,
                `cr`.`image_path`,
                `cr`.`image_filename`,
                `cr`.`time_spent`,
                `cr`.`created_at`,
                `cr`.`updated_at`
            FROM `client_records` `cr`
        ";
        
        if ($conn->query($records_view)) {
            $created[] = 'records';
        } else {
            $errors[] = 'records: ' . $conn->error;
        }
        
        if (count($errors) > 0) {
            send_json([
                'status' => 'partial',
                'message' => 'Some views failed',
                'created' => $created,
                'errors' => $errors
            ]);
        } else {
            send_json([
                'status' => 'success',
                'created' => $created,
                'message' => 'All views created successfully'
            ]);
        }
    }

    // Create triggers
    if ($action == 'create_triggers') {
        if (!check_session_validity()) {
            send_json(['status'=>'error', 'message'=>'Session timeout']);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            send_json(['status'=>'error', 'message'=>'Access denied']);
        }
        
        $created = [];
        $errors = [];
        
        // Drop existing triggers first
        $conn->query("DROP TRIGGER IF EXISTS `auto_create_work_log`");
        $conn->query("DROP TRIGGER IF EXISTS `sync_assigned_to_id`");
        $conn->query("DROP TRIGGER IF EXISTS `sync_assigned_to_id_insert`");
        
        // Trigger 1: Auto create work log
        $trigger1 = "
            CREATE TRIGGER `auto_create_work_log` AFTER UPDATE ON `client_records` 
            FOR EACH ROW 
            BEGIN
                IF NEW.row_status = 'Completed' AND OLD.row_status != 'Completed' THEN
                    SET @deo_id = (SELECT id FROM users WHERE username = NEW.assigned_to LIMIT 1);
                    
                    IF @deo_id IS NOT NULL THEN
                        SET @existing_log = (
                            SELECT id FROM work_logs 
                            WHERE deo_id = @deo_id 
                            AND DATE(log_time) = CURDATE()
                            ORDER BY log_time DESC 
                            LIMIT 1
                        );
                        
                        IF @existing_log IS NOT NULL THEN
                            UPDATE work_logs 
                            SET record_to = NEW.record_no,
                                record_count = record_count + 1,
                                log_time = NOW()
                            WHERE id = @existing_log;
                        ELSE
                            INSERT INTO work_logs (deo_id, record_from, record_to, record_count, log_time)
                            VALUES (@deo_id, NEW.record_no, NEW.record_no, 1, NOW());
                        END IF;
                    END IF;
                END IF;
            END
        ";
        
        if ($conn->query($trigger1)) {
            $created[] = 'auto_create_work_log';
        } else {
            $errors[] = 'auto_create_work_log: ' . $conn->error;
        }
        
        // Trigger 2: Sync assigned_to_id on update
        $trigger2 = "
            CREATE TRIGGER `sync_assigned_to_id` BEFORE UPDATE ON `client_records` 
            FOR EACH ROW 
            BEGIN
                IF NEW.assigned_to IS NOT NULL AND NEW.assigned_to != '' THEN
                    SET NEW.assigned_to_id = (SELECT id FROM users WHERE username = NEW.assigned_to LIMIT 1);
                ELSE
                    SET NEW.assigned_to_id = NULL;
                END IF;
            END
        ";
        
        if ($conn->query($trigger2)) {
            $created[] = 'sync_assigned_to_id';
        } else {
            $errors[] = 'sync_assigned_to_id: ' . $conn->error;
        }
        
        // Trigger 3: Sync assigned_to_id on insert
        $trigger3 = "
            CREATE TRIGGER `sync_assigned_to_id_insert` BEFORE INSERT ON `client_records` 
            FOR EACH ROW 
            BEGIN
                IF NEW.assigned_to IS NOT NULL AND NEW.assigned_to != '' THEN
                    SET NEW.assigned_to_id = (SELECT id FROM users WHERE username = NEW.assigned_to LIMIT 1);
                END IF;
            END
        ";
        
        if ($conn->query($trigger3)) {
            $created[] = 'sync_assigned_to_id_insert';
        } else {
            $errors[] = 'sync_assigned_to_id_insert: ' . $conn->error;
        }
        
        if (count($errors) > 0) {
            send_json([
                'status' => 'partial',
                'message' => 'Some triggers failed',
                'created' => $created,
                'errors' => $errors
            ]);
        } else {
            send_json([
                'status' => 'success',
                'created' => $created,
                'message' => 'All triggers created successfully'
            ]);
        }
    }


    // ============================================================
    // REPORT TO ADMIN - FULL WORKFLOW (v3.0)
    // ============================================================

    // Submit Report to Admin
    if ($action == 'submit_report_to_admin') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $record_no    = trim($_POST['record_no'] ?? '');
        $header_name  = trim($_POST['header_name'] ?? '');
        $issue_details= trim($_POST['issue_details'] ?? '');
        $reported_from= trim($_POST['reported_from'] ?? 'first_qc');
        $image_no_rpt = trim($_POST['image_no'] ?? '');
        
        if (empty($record_no) || empty($header_name) || empty($issue_details)) {
            send_json(['status'=>'error','message'=>'Record No, Header, aur Issue details required hain']);
        }
        
        // Ensure image_no column exists in report_to_admin
        // Auto-fetch image_no if not provided
        if (empty($image_no_rpt)) {
            $rn_esc_img = $conn->real_escape_string($record_no);
            $img_res = $conn->query("SELECT image_no FROM record_image_map WHERE record_no='$rn_esc_img' LIMIT 1");
            if ($img_res && $img_res->num_rows > 0) {
                $image_no_rpt = str_replace('_enc', '', $img_res->fetch_assoc()['image_no']);
            } else {
                $img_res2 = $conn->query("SELECT image_filename FROM client_records WHERE record_no='$rn_esc_img' LIMIT 1");
                if ($img_res2 && $img_res2->num_rows > 0) {
                    $image_no_rpt = trim($img_res2->fetch_assoc()['image_filename'] ?? '');
                }
            }
        }
        
        // Check for duplicate open report on same record+header
        $dup_check = $conn->prepare("SELECT id FROM report_to_admin WHERE record_no=? AND header_name=? AND status='open'");
        $dup_check->bind_param("ss", $record_no, $header_name);
        $dup_check->execute();
        if ($dup_check->get_result()->num_rows > 0) {
            send_json(['status'=>'warning','message'=>"Is record ke liye '$header_name' field ka report already open hai."]);
        }
        
        $reported_by      = $_SESSION['username'];
        $reported_by_name = $_SESSION['full_name'] ?? $_SESSION['username'];
        $role             = $_SESSION['role'];
        
        // Add ce_id column to report_to_admin if not exists (links to critical_errors)
        // Add reported_by_name + reporter_role columns to critical_errors if not exists

        $stmt = $conn->prepare("INSERT INTO report_to_admin (record_no, header_name, issue_details, reported_by, reported_by_name, `role`, reported_from, image_no) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssss", $record_no, $header_name, $issue_details, $reported_by, $reported_by_name, $role, $reported_from, $image_no_rpt);
        
        if ($stmt->execute()) {
            $rta_id = $conn->insert_id;
            $rn_esc = mysqli_real_escape_string($conn, $record_no);
            
            // Update client_records highlight flag
            $conn->query("UPDATE client_records SET is_reported=1, report_count=report_count+1 WHERE record_no='$rn_esc'");
            
            // ─── ALSO INSERT INTO critical_errors (P2 Admin + DEO Pending Review) ───
            // Find the assigned DEO's user_id for this record
            $deo_row = $conn->query("SELECT u.id as uid FROM client_records cr JOIN users u ON u.username = cr.assigned_to WHERE cr.record_no='$rn_esc' LIMIT 1");
            $deo_id_for_ce = 0;
            if ($deo_row && $deo_row->num_rows > 0) {
                $deo_id_for_ce = (int)$deo_row->fetch_assoc()['uid'];
            }
            // fallback: if assigned_to is user_id directly
            if ($deo_id_for_ce == 0) {
                $deo_row2 = $conn->query("SELECT assigned_to FROM client_records WHERE record_no='$rn_esc' LIMIT 1");
                if ($deo_row2 && $deo_row2->num_rows > 0) {
                    $at = $deo_row2->fetch_assoc()['assigned_to'];
                    if (is_numeric($at)) $deo_id_for_ce = (int)$at;
                }
            }
            
            if ($deo_id_for_ce > 0) {
                $ce_details     = mysqli_real_escape_string($conn, "[Reported by " . strtoupper($role) . ": $reported_by_name] $issue_details");
                $ce_field       = mysqli_real_escape_string($conn, $header_name);
                $ce_role        = mysqli_real_escape_string($conn, $role);
                $ce_reporter_nm = mysqli_real_escape_string($conn, $reported_by_name);
                
                $ce_ins = $conn->query("INSERT INTO critical_errors (record_no, deo_id, error_field, error_details, reported_by_name, reporter_role) VALUES ('$rn_esc', $deo_id_for_ce, '$ce_field', '$ce_details', '$ce_reporter_nm', '$ce_role')");
                if ($ce_ins) {
                    $ce_id = $conn->insert_id;
                    // Link back
                    $conn->query("UPDATE report_to_admin SET ce_id=$ce_id WHERE id=$rta_id");
                    // Notify admin user (id=1)
                    $notif_msg = mysqli_real_escape_string($conn, strtoupper($role)." Report: $reported_by_name reported Record $record_no - $header_name");
                    $conn->query("INSERT INTO notifications (user_id, title, message, type) VALUES (1, '$notif_msg', '$notif_msg', 'alert')");
                }
            }
            // ──────────────────────────────────────────────────────────────────────
            
            // Log activity
            log_activity($conn, $_SESSION['user_id'], $reported_by, 'report_submitted', 'report_to_admin', null, $record_no, null, $header_name, $issue_details);
            
            send_json(['status'=>'success','message'=>'Report submit ho gaya! Admin ko notify kar diya gaya.']);
        } else {
            send_json(['status'=>'error','message'=>'Report save nahi hua: '.$stmt->error]);
        }
    }

    // Get Reports for a specific record
    if ($action == 'get_reports_for_record') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $record_no = trim($_POST['record_no'] ?? $_GET['record_no'] ?? '');
        if (empty($record_no)) send_json(['status'=>'error','message'=>'Record No required']);
        
        $rn = mysqli_real_escape_string($conn, $record_no);
        $reports = [];
        
        // 1. report_to_admin se fetch karo (P1 QC reports)
        $result = $conn->query("SELECT r.*, r.created_at, r.header_name as field_name, r.issue_details as details, r.reported_by_name, r.role as reporter_role, 'report_to_admin' as source FROM report_to_admin r WHERE r.record_no='$rn' ORDER BY r.created_at DESC");
        if ($result) while ($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
        
        // 2. critical_errors se bhi fetch karo (P2 DEO reports) — jo report_to_admin me nahi hain
        $linked_ce_ids = array_filter(array_column($reports, 'ce_id'));
        $exclude_ce = empty($linked_ce_ids) ? '' : ' AND ce.id NOT IN (' . implode(',', array_map('intval', $linked_ce_ids)) . ')';
        
        $ce_result = $conn->query("
            SELECT 
                ce.id,
                ce.record_no,
                ce.error_field  AS header_name,
                ce.error_field  AS field_name,
                ce.error_details AS issue_details,
                ce.error_details AS details,
                ce.admin_remark,
                ce.status       AS ce_status,
                CASE ce.status 
                    WHEN 'resolved' THEN 'solved'
                    ELSE 'open'
                END AS status,
                ce.reported_by_name,
                ce.reporter_role,
                ce.created_at,
                ce.reviewed_at,
                'critical_errors' AS source
            FROM critical_errors ce
            WHERE ce.record_no = '$rn' $exclude_ce
            ORDER BY ce.created_at DESC
        ");
        if ($ce_result) while ($row = $ce_result->fetch_assoc()) {
            $reports[] = $row;
        }
        
        // Sort by created_at DESC
        usort($reports, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));
        
        send_json(['status'=>'success','reports'=>$reports,'count'=>count($reports)]);
    }

    // Mark Report Solved (P2 DEO / Admin)
    if ($action == 'mark_report_solved') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $report_id = (int)($_POST['report_id'] ?? 0);
        $record_no = trim($_POST['record_no'] ?? '');
        
        if (!$report_id) send_json(['status'=>'error','message'=>'Report ID required']);
        
        $solved_by = $_SESSION['username'];
        
        // Get ce_id before updating
        $ce_row = $conn->query("SELECT ce_id FROM report_to_admin WHERE id=$report_id");
        $ce_id_linked = 0;
        if ($ce_row && $ce_row->num_rows > 0) {
            $ce_id_linked = (int)$ce_row->fetch_assoc()['ce_id'];
        }
        
        $stmt = $conn->prepare("UPDATE report_to_admin SET status='solved', solved_by=?, solved_at=NOW() WHERE id=?");
        $stmt->bind_param("si", $solved_by, $report_id);
        
        if ($stmt->execute()) {
            // Check if any open reports remain for this record
            if (!empty($record_no)) {
                $rn = mysqli_real_escape_string($conn, $record_no);
                $open_check = $conn->query("SELECT COUNT(*) as cnt FROM report_to_admin WHERE record_no='$rn' AND status='open'");
                $open_row = $open_check->fetch_assoc();
                if ($open_row['cnt'] == 0) {
                    // No more open reports - remove highlight
                    $conn->query("UPDATE client_records SET is_reported=0, report_count=0, updated_at=NOW() WHERE record_no='$rn'");
                } else {
                    // Update count
                    $conn->query("UPDATE client_records SET report_count={$open_row['cnt']}, updated_at=NOW() WHERE record_no='$rn'");
                }
            }
            
            // ─── ALSO UPDATE critical_errors → status='resolved' ───
            if ($ce_id_linked > 0) {
                $sv_esc = mysqli_real_escape_string($conn, $solved_by);
                $conn->query("UPDATE critical_errors SET status='resolved', resolved_at=NOW() WHERE id=$ce_id_linked");
            }
            // ───────────────────────────────────────────────────────
            
            log_activity($conn, $_SESSION['user_id'], $solved_by, 'report_solved', 'report_to_admin', null, $record_no, null, null, "Report #$report_id solved");
            send_json(['status'=>'success','message'=>'Report solved mark ho gaya! Highlight remove ho jaayega.']);
        } else {
            send_json(['status'=>'error','message'=>'Update failed: '.$stmt->error]);
        }
    }

    // Get All Open Reports (Admin view)
    // ============================================================
    // P2 ADMIN: Export reports_to_admin as JSON for client-side CSV
    // ============================================================
    if ($action == 'export_p2_reports') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        if ($_SESSION['role'] !== 'admin') send_json(['status'=>'error','message'=>'Unauthorized']);

        $ex_deo    = trim($_POST['ex_deo']    ?? '');
        $ex_date   = trim($_POST['ex_date']   ?? '');
        $ex_status = trim($_POST['ex_status'] ?? '');
        $ex_source = trim($_POST['ex_source'] ?? '');

        // Export mein sirf Pending wale - Replied exclude
        $where = ["`status` = 'open'", "(`admin_remark` IS NULL OR `admin_remark`='')"];
        if (!empty($ex_deo)) {
            $ed = $conn->real_escape_string($ex_deo);
            $where[] = "EXISTS (SELECT 1 FROM `users` _eu WHERE CONVERT(_eu.`username` USING utf8mb4) = CONVERT(`reported_by` USING utf8mb4) AND _eu.`id`='$ed')";
        }
        if (!empty($ex_date)) {
            $dt = $conn->real_escape_string($ex_date);
            $where[] = "DATE(`created_at`) = '$dt'";
        }
        // Status filter ignore - always Pending only
        if (!empty($ex_source)) {
            $es = $conn->real_escape_string($ex_source);
            $where[] = "`reported_from` = '$es'";
        }

        $wc = implode(' AND ', $where);
        $res = $conn->query("SELECT `id`,`record_no`,`header_name`,`issue_details`,
            IFNULL(`reported_by_name`,`reported_by`) AS reporter_name,
            `role`, IFNULL(`reported_from`,'first_qc') AS reported_from,
            `status`, IFNULL(`admin_remark`,'') AS admin_remark,
            IFNULL(`image_no`,'') AS image_no, `created_at`
            FROM `report_to_admin`
            WHERE $wc ORDER BY `created_at` DESC LIMIT 2000");

        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $details = $row['issue_details'];
                $img = $row['image_no'];
                if (empty($img) && preg_match('/\[Image No:\s*([^\]]+)\]/', $details, $m)) {
                    $img = trim($m[1]);
                    $details = trim(str_replace($m[0], '', $details));
                }
                $src_map = ['first_qc'=>'First QC','second_qc'=>'Second QC','autotyper'=>'AutoTyper','p2_deo'=>'P2 DEO','admin'=>'Admin'];
                $rows[] = [
                    $row['id'], $row['record_no'], $img,
                    $src_map[$row['reported_from']] ?? strtoupper($row['reported_from']),
                    $row['header_name'], $details,
                    $row['reporter_name'], strtoupper($row['role']),
                    $row['status']==='open' ? (empty($row['admin_remark']) ? 'Pending' : 'Replied') : 'Solved',
                    $row['admin_remark'], $row['created_at']
                ];
            }
        }
        send_json(['status'=>'success','rows'=>$rows]);
    }

    if ($action == 'get_all_reports') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        if ($_SESSION['role'] !== 'admin') send_json(['status'=>'error','message'=>'Admin access required']);
        
        $status_filter = trim($_POST['status'] ?? 'open');
        $sf = mysqli_real_escape_string($conn, $status_filter);
        
        // Filter by user (optional)
        $deo_user_filter = trim($_POST['deo_user'] ?? '');
        $user_where = '';
        if (!empty($deo_user_filter)) {
            $eu = mysqli_real_escape_string($conn, $deo_user_filter);
            $user_where = " AND CONVERT(r.`reported_by` USING utf8mb4) COLLATE utf8mb4_unicode_ci = '$eu'";
        }
        
        $rta_where = "WHERE r.`status`" . ($sf === 'all' ? " IN ('open','solved')" : "='$sf'") . $user_where;
        
        $result = $conn->query("
            SELECT r.`id`, r.`record_no`, r.`header_name`, r.`issue_details`,
                   r.`reported_by`, IFNULL(r.`reported_by_name`,'') AS reported_by_name,
                   r.`role`, r.`reported_from`, r.`status`,
                   r.`solved_by`, r.`solved_at`, IFNULL(r.`admin_remark`,'') AS admin_remark,
                   IFNULL(r.`image_no`,'') AS image_no,
                   IFNULL(r.`image_no`,'') AS image_no_display,
                   r.`created_at`
            FROM `report_to_admin` r
            $rta_where
            ORDER BY r.`created_at` DESC LIMIT 500");
        
        $reports = [];
        if ($result) while ($row = $result->fetch_assoc()) $reports[] = $row;
        
        // Counts — sirf report_to_admin
        $r_open   = $conn->query("SELECT COUNT(*) as c FROM `report_to_admin` WHERE `status`='open'");
        $r_solved = $conn->query("SELECT COUNT(*) as c FROM `report_to_admin` WHERE `status`='solved'");
        $count_data = [
            'open'   => $r_open   ? (int)$r_open->fetch_assoc()['c']   : 0,
            'solved' => $r_solved ? (int)$r_solved->fetch_assoc()['c'] : 0,
        ];
        
        send_json(['status'=>'success','reports'=>$reports,'counts'=>$count_data]);
    }

    // ============================================================
    // CENTRAL COUNT ENGINE - recalculateCounts (v3.0)
    // ============================================================
    if ($action == 'recalculate_counts') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $deo_id   = (int)($_POST['deo_id'] ?? 0);   // 0 = all DEOs
        $deo_user = trim($_POST['deo_username'] ?? '');
        
        $where_deo = '';
        if ($deo_id > 0) {
            $where_deo = " AND cr.assigned_to_id=$deo_id";
        } elseif (!empty($deo_user)) {
            $eu = mysqli_real_escape_string($conn, $deo_user);
            $where_deo = " AND cr.assigned_to='$eu'";
        }
        
        $today = date('Y-m-d');
        
        // First QC Pending (pending status)
        $r = $conn->query("SELECT COUNT(*) as cnt FROM client_records cr WHERE cr.row_status='pending'$where_deo");
        $first_qc_pending = $r->fetch_assoc()['cnt'] ?? 0;
        
        // Second QC Pending (deo_done / done / pending_qc)
        $r = $conn->query("SELECT COUNT(*) as cnt FROM client_records cr WHERE cr.row_status IN ('deo_done','done','pending_qc')$where_deo");
        $second_qc_pending = $r->fetch_assoc()['cnt'] ?? 0;
        
        // Second QC Done (qc_done / qc_approved)
        $r = $conn->query("SELECT COUNT(*) as cnt FROM client_records cr WHERE cr.row_status IN ('qc_done','qc_approved')$where_deo");
        $second_qc_done = $r->fetch_assoc()['cnt'] ?? 0;
        
        // Final Completed
        $r = $conn->query("SELECT COUNT(*) as cnt FROM client_records cr WHERE cr.row_status='Completed'$where_deo");
        $final_completed = $r->fetch_assoc()['cnt'] ?? 0;
        
        // Final Today Completed
        $r = $conn->query("SELECT COUNT(*) as cnt FROM client_records cr WHERE cr.row_status='Completed' AND DATE(cr.updated_at)='$today'$where_deo");
        $final_today = $r->fetch_assoc()['cnt'] ?? 0;
        
        // Report Count: sirf report_to_admin (open)
        $rta_sub = empty($where_deo) ? "" : " AND `record_no` IN (SELECT record_no FROM client_records cr WHERE 1=1$where_deo)";
        $r1 = $conn->query("SELECT COUNT(*) as cnt FROM `report_to_admin` WHERE `status`='open'$rta_sub");
        $report_count = $r1 ? (int)($r1->fetch_assoc()['cnt'] ?? 0) : 0;
        
        // Total records
        $r = $conn->query("SELECT COUNT(*) as cnt FROM client_records cr WHERE 1=1$where_deo");
        $total_records = $r->fetch_assoc()['cnt'] ?? 0;
        
        send_json([
            'status'           => 'success',
            'first_qc_pending' => $first_qc_pending,
            'second_qc_pending'=> $second_qc_pending,
            'second_qc_done'   => $second_qc_done,
            'final_completed'  => $final_completed,
            'final_today'      => $final_today,
            'report_count'     => $report_count,
            'total_records'    => $total_records,
            // Legacy keys for backward compat
            'pending'          => $first_qc_pending,
            'completed'        => $final_completed,
            'today_completed'  => $final_today,
        ]);
    }

    // Get DEO list for filter dropdown
    if ($action == 'get_deo_list_for_filter') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $result = $conn->query("SELECT id, username, full_name FROM users WHERE `role`='deo' AND is_active=1 ORDER BY full_name ASC");
        $deos = [];
        while ($row = $result->fetch_assoc()) $deos[] = $row;
        send_json(['status'=>'success','deos'=>$deos]);
    }


    // ============================================================
    // REALTIME SYNC ACTION v3.1 - Central sync for all dashboards
    // Returns: qc_enabled, report_count, admin_replies, record changes
    // ============================================================
    if ($action == 'realtime_sync') {
        if (!check_session_validity()) send_json(['status'=>'error']);
        
        $role     = $_SESSION['role'];
        $username = $_SESSION['username'];
        $user_id  = $_SESSION['user_id'];
        $last_sync = $_POST['last_sync'] ?? date('Y-m-d H:i:s', strtotime('-5 seconds'));
        
        // 1. QC Enabled status
        $qc_enabled = is_qc_enabled($conn) ? '1' : '0';
        
        // 2. Open report count (for report badge)
        $rpt_cnt = 0;
        if (in_array($role, ['admin','qc'])) {
            $rpt_row = $conn->query("SELECT COUNT(*) as cnt FROM report_to_admin WHERE status='open'");
            if ($rpt_row) $rpt_cnt = (int)$rpt_row->fetch_assoc()['cnt'];
        }
        
        // 3. Admin replies on critical_errors - pending for this user to see
        //    Roles that need to see replies: deo, qc (as reporter), admin
        $admin_replies = [];
        if (in_array($role, ['deo','qc','admin'])) {
            // Get recently reviewed/replied errors for records assigned to this user
            $rpl_sql = "SELECT ce.id, ce.record_no, ce.error_field, ce.error_details, 
                               ce.admin_remark, ce.reviewed_at, ce.status,
                               ce.reporter_role, ce.reported_by_name,
                               COALESCE(rim.image_no, cr.image_filename, '') as image_no
                        FROM critical_errors ce
                        LEFT JOIN client_records cr ON cr.record_no = ce.record_no
                        LEFT JOIN record_image_map rim ON rim.record_no = ce.record_no
                        WHERE ce.status = 'admin_reviewed' 
                          AND ce.reviewed_at > ?
                          AND (ce.deo_id = ? 
                               OR cr.assigned_to = ?
                               OR ce.reported_by_name = ?)
                        ORDER BY ce.reviewed_at DESC LIMIT 20";
            $rpl_stmt = $conn->prepare($rpl_sql);
            $rpl_stmt->bind_param("siis", $last_sync, $user_id, $username, $username);
            $rpl_stmt->execute();
            $rpl_res = $rpl_stmt->get_result();
            while ($rr = $rpl_res->fetch_assoc()) {
                $admin_replies[] = $rr;
            }
        }
        
        // 4. Recent record changes (same as sync_changes but enriched)
        $changes = [];
        $ch_sql = "SELECT cr.*, COALESCE(rim.image_no, cr.image_filename, '') as image_no_display
                   FROM client_records cr
                   LEFT JOIN record_image_map rim ON rim.record_no = cr.record_no
                   WHERE cr.updated_at > ?";
        if ($role === 'deo') {
            $ch_sql .= " AND cr.assigned_to = ?";
        } elseif ($role === 'qc') {
            // QC sees all unfiltered
        }
        $ch_sql .= " ORDER BY cr.updated_at DESC LIMIT 200";
        
        if ($role === 'deo') {
            $ch_stmt = $conn->prepare($ch_sql);
            $ch_stmt->bind_param("ss", $last_sync, $username);
        } else {
            $ch_stmt = $conn->prepare($ch_sql);
            $ch_stmt->bind_param("s", $last_sync);
        }
        $ch_stmt->execute();
        $ch_res = $ch_stmt->get_result();
        while ($row = $ch_res->fetch_assoc()) {
            $changes[] = $row;
        }
        
        // 5. Counts
        $counts_sql = "SELECT
            SUM(CASE WHEN row_status='pending' THEN 1 ELSE 0 END) as first_qc_pending,
            SUM(CASE WHEN row_status IN ('done','deo_done','pending_qc') THEN 1 ELSE 0 END) as second_qc_pending,
            SUM(CASE WHEN row_status IN ('qc_done','qc_approved') THEN 1 ELSE 0 END) as second_qc_done,
            SUM(CASE WHEN row_status='Completed' THEN 1 ELSE 0 END) as final_completed,
            SUM(CASE WHEN row_status='Completed' AND DATE(updated_at)=CURDATE() THEN 1 ELSE 0 END) as final_today
            FROM client_records";
        if ($role === 'deo') {
            $counts_sql .= " WHERE assigned_to='" . $conn->real_escape_string($username) . "'";
        }
        $counts_row = $conn->query($counts_sql)->fetch_assoc();
        
        // 6. Report changes since last sync (for report badge realtime)
        $new_reports = [];
        $nr_res = $conn->query("SELECT record_no, COUNT(*) as cnt FROM report_to_admin WHERE status='open' AND created_at > '" . $conn->real_escape_string($last_sync) . "' GROUP BY record_no");
        if ($nr_res) while ($nr = $nr_res->fetch_assoc()) $new_reports[$nr['record_no']] = (int)$nr['cnt'];
        
        send_json([
            'status'        => 'success',
            'server_time'   => date('Y-m-d H:i:s'),
            'qc_enabled'    => $qc_enabled,
            'report_count'  => $rpt_cnt,
            'admin_replies' => $admin_replies,
            'changes'       => $changes,
            'counts'        => $counts_row,
            'new_reports'   => $new_reports,
            'change_count'  => count($changes)
        ]);
    }

    // ============================================================
    // AUTO-FETCH IMAGE FOR RECORD (for Report modals)
    // ============================================================
    if ($action == 'get_image_for_report') {
        if (!check_session_validity()) send_json(['status'=>'error']);
        $rn = trim($_POST['record_no'] ?? '');
        if (empty($rn)) send_json(['status'=>'error','image_no'=>'']);
        $rn_esc = $conn->real_escape_string($rn);
        
        // Try record_image_map first
        $res1 = $conn->query("SELECT image_no FROM record_image_map WHERE record_no='$rn_esc' LIMIT 1");
        if ($res1 && $res1->num_rows > 0) {
            $img = str_replace('_enc', '', $res1->fetch_assoc()['image_no']);
            send_json(['status'=>'success','image_no'=>$img,'source'=>'map']);
        }
        // Fallback to client_records
        $res2 = $conn->query("SELECT image_filename FROM client_records WHERE record_no='$rn_esc' LIMIT 1");
        if ($res2 && $res2->num_rows > 0) {
            $img = trim($res2->fetch_assoc()['image_filename'] ?? '');
            send_json(['status'=>'success','image_no'=>$img,'source'=>'client']);
        }
        send_json(['status'=>'success','image_no'=>'','source'=>'none']);
    }

    // ============================================================
    // SUBMIT REPORT - image_no support upgrade
    // ============================================================
    if ($action == 'submit_report_with_image') {
        // Alias - calls submit_report_to_admin but also saves image_no
        // Add image_no to report_to_admin if column exists
    }

    // ============================================================
    // GET ADMIN REPLIES - for Autotyper polling
    // ============================================================
    if ($action == 'get_admin_replies_for_user') {
        if (!check_session_validity()) send_json(['status'=>'error']);
        
        $username = $_SESSION['username'];
        $user_id  = (int)$_SESSION['user_id'];
        $since    = trim($_POST['since'] ?? date('Y-m-d H:i:s', strtotime('-1 hour')));
        
        // Ensure resolved_at column exists
        // Get full_name for this user (reported_by_name stores full name)
        $fn_row = $conn->query("SELECT full_name FROM users WHERE id=$user_id LIMIT 1");
        $full_name_match = $fn_row && $fn_row->num_rows > 0 ? $fn_row->fetch_assoc()['full_name'] : $username;
        
        $rpl_sql = "SELECT ce.id, ce.record_no, ce.error_field, ce.admin_remark, 
                           ce.reviewed_at, ce.status, ce.reporter_role, ce.reported_by_name,
                           COALESCE(rta.image_no, rim.image_no, cr.image_filename, '') as image_no
                    FROM critical_errors ce
                    LEFT JOIN client_records cr ON cr.record_no = ce.record_no
                    LEFT JOIN record_image_map rim ON rim.record_no = ce.record_no
                    LEFT JOIN report_to_admin rta ON rta.ce_id = ce.id
                    WHERE ce.status = 'admin_reviewed'
                      AND (
                        ce.deo_id = ?
                        OR cr.assigned_to = ?
                        OR ce.reported_by_name = ?
                        OR ce.reported_by_name = ?
                      )
                    ORDER BY ce.reviewed_at DESC LIMIT 50";
        $stmt = $conn->prepare($rpl_sql);
        $stmt->bind_param("isss", $user_id, $username, $username, $full_name_match);
        $stmt->execute();
        $res  = $stmt->get_result();
        $replies = [];
        while ($r = $res->fetch_assoc()) $replies[] = $r;
        
        send_json(['status'=>'success','replies'=>$replies]);
    }

    // ============================================================
    // MARK RESOLVE FROM AUTOTYPER / QC Dashboard
    // ============================================================
    if ($action == 'mark_resolved_by_user') {
        if (!check_session_validity()) send_json(['status'=>'error','message'=>'Login required']);
        
        $ce_id     = (int)($_POST['ce_id'] ?? 0);
        $record_no = trim($_POST['record_no'] ?? '');
        $username  = $_SESSION['username'];
        $uname     = $conn->real_escape_string($username);
        
        if ($ce_id == 0 && empty($record_no)) {
            send_json(['status'=>'error','message'=>'ce_id ya record_no required hai']);
        }
        
        $rn_esc = $conn->real_escape_string($record_no);
        
        $errors_list  = [];
        $ce_affected  = 0;
        $rta_affected = 0;
        
        // STEP 1: Resolve by ce_id directly
        if ($ce_id > 0) {
            $r1 = $conn->query("UPDATE critical_errors SET status='resolved' WHERE id=$ce_id");
            $ce_affected += $conn->affected_rows;
            $errors_list[] = "CE by id: rows=" . $conn->affected_rows . " err=" . $conn->error;
        }
        
        // STEP 2: Resolve by record_no — all pending/admin_reviewed CEs
        if (!empty($record_no)) {
            $r2 = $conn->query("UPDATE critical_errors SET status='resolved' WHERE record_no='$rn_esc' AND status IN ('pending','admin_reviewed')");
            $ce_affected += $conn->affected_rows;
            $errors_list[] = "CE by rn exact: rows=" . $conn->affected_rows . " err=" . $conn->error;
            // Numeric cast fallback
            if (is_numeric(trim($record_no))) {
                $rn_int = (int)trim($record_no);
                $r3 = $conn->query("UPDATE critical_errors SET status='resolved' WHERE CAST(record_no AS UNSIGNED)=$rn_int AND status IN ('pending','admin_reviewed')");
                $ce_affected += $conn->affected_rows;
                $errors_list[] = "CE by rn cast: rows=" . $conn->affected_rows . " err=" . $conn->error;
            }
        }
        
        // STEP 3: Solve report_to_admin
        if ($ce_id > 0) {
            $conn->query("UPDATE report_to_admin SET status='solved', solved_by='$uname', solved_at=NOW() WHERE ce_id=$ce_id AND status='open'");
            $rta_affected += $conn->affected_rows;
        }
        if (!empty($record_no)) {
            $conn->query("UPDATE report_to_admin SET status='solved', solved_by='$uname', solved_at=NOW() WHERE record_no='$rn_esc' AND status='open'");
            $rta_affected += $conn->affected_rows;
        }
        
        // STEP 4: Clear client_records flag
        if (!empty($record_no)) {
            $conn->query("UPDATE client_records SET is_reported=0, report_count=0 WHERE record_no='$rn_esc'");
            if (is_numeric(trim($record_no))) {
                $rn_int = (int)trim($record_no);
                $conn->query("UPDATE client_records SET is_reported=0, report_count=0 WHERE CAST(record_no AS UNSIGNED)=$rn_int");
            }
        }
        
        // Debug info (visible in autotyper logs)
        send_json([
            'status'        => 'success',
            'message'       => 'Resolved!',
            'ce_affected'   => $ce_affected,
            'rta_affected'  => $rta_affected,
            'debug_steps'   => $errors_list,
            'ce_id_used'    => $ce_id,
            'rn_used'       => $record_no
        ]);
    }

    // ============================================================
    // GET RESOLVED CE IDs - for realtime removal from dashboards
    // ============================================================
    if ($action == 'get_resolved_ce_ids') {
        if (!check_session_validity()) send_json(['status'=>'error']);
        
        $since = trim($_POST['since'] ?? date('Y-m-d H:i:s', strtotime('-5 seconds')));
        $since_esc = $conn->real_escape_string($since);
        
        // Recently resolved critical_errors
        $res = $conn->query("SELECT id, record_no FROM critical_errors WHERE status='resolved' AND resolved_at > '$since_esc' ORDER BY resolved_at DESC LIMIT 100");
        $resolved = [];
        if ($res) while ($r = $res->fetch_assoc()) {
            $resolved[] = ['id' => (int)$r['id'], 'record_no' => $r['record_no']];
        }
        
        // Also get admin_reviewed ones waiting (for DEO dashboard pending count)
        $pending_res = $conn->query("SELECT COUNT(*) as cnt FROM critical_errors WHERE status='admin_reviewed'");
        $pending_reviewed = $pending_res ? (int)$pending_res->fetch_assoc()['cnt'] : 0;
        
        send_json([
            'status'           => 'success',
            'resolved'         => $resolved,
            'server_time'      => date('Y-m-d H:i:s'),
            'pending_reviewed' => $pending_reviewed
        ]);
    }

    // ============================================================
    // GET CRITICAL ERRORS FOR RECORD (for autotyper fallback)
    // ============================================================
    if ($action == 'get_ce_for_record') {
        if (!check_session_validity()) send_json(['status'=>'error']);
        $rn = trim($_POST['record_no'] ?? '');
        if (empty($rn)) send_json(['status'=>'error','ce'=>[]]);
        $rn_e = $conn->real_escape_string($rn);
        
        $res = $conn->query("
            SELECT ce.id, ce.record_no, ce.error_field, ce.admin_remark,
                   ce.reviewed_at, ce.status, ce.reporter_role, ce.reported_by_name,
                   COALESCE(rta.image_no, rim.image_no, cr.image_filename, '') as image_no
            FROM critical_errors ce
            LEFT JOIN report_to_admin rta ON rta.ce_id = ce.id
            LEFT JOIN record_image_map rim ON rim.record_no = ce.record_no
            LEFT JOIN client_records cr ON cr.record_no = ce.record_no
            WHERE ce.record_no = '$rn_e'
              AND ce.status = 'admin_reviewed'
            ORDER BY ce.reviewed_at DESC
            LIMIT 10
        ");
        $ce_list = [];
        if ($res) while ($row = $res->fetch_assoc()) $ce_list[] = $row;
        send_json(['status'=>'success','ce'=>$ce_list]);
    }

    // ============================================================
    // END NEW ACTIONS v3.1
    // ============================================================

    // ============================================================
    // TV PRODUCTION DASHBOARD - Real-time data for TV screen
    // ============================================================


    // ============================================================
    // TV DASHBOARD - Fast polling (3s), hash-based smart updates
    // ============================================================
    if ($action == 'tv_dashboard_data' || $action == 'tv_sse') {
        $token = $_POST['token'] ?? $_GET['token'] ?? '';
        $tv_token_res = $conn->query("SELECT setting_value FROM security_settings WHERE setting_key='tv_dashboard_token'");
        $valid_token = ($tv_token_res && $r2 = $tv_token_res->fetch_assoc()) ? $r2['setting_value'] : 'JSSTV2025';
        if ($token !== $valid_token) {
            send_json(['status'=>'error','message'=>'Invalid token']);
        }

        $today = date('Y-m-d');
        $total      = (int)$conn->query("SELECT COUNT(*) c FROM client_records")->fetch_assoc()['c'];
        $pending    = (int)$conn->query("SELECT COUNT(*) c FROM client_records WHERE row_status='pending'")->fetch_assoc()['c'];
        $qc1done    = (int)$conn->query("SELECT COUNT(*) c FROM client_records WHERE row_status IN ('deo_done','done','pending_qc')")->fetch_assoc()['c'];
        $qc2done    = (int)$conn->query("SELECT COUNT(*) c FROM client_records WHERE row_status IN ('qc_done','qc_approved')")->fetch_assoc()['c'];
        $completed  = (int)$conn->query("SELECT COUNT(*) c FROM client_records WHERE row_status='Completed'")->fetch_assoc()['c'];
        $today_done = (int)$conn->query("SELECT COUNT(*) c FROM client_records WHERE row_status='Completed' AND DATE(updated_at)='$today'")->fetch_assoc()['c'];
        $progress   = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        // Hash check - agar data nahi badla to sirf hash return karo (fast response)
        $quick_hash = md5("$total:$pending:$qc1done:$qc2done:$completed:$today_done");
        $client_hash = $_POST['last_hash'] ?? '';
        if ($client_hash !== '' && $client_hash === $quick_hash) {
            send_json(['status'=>'no_change','hash'=>$quick_hash]);
        }

        $deo_res = $conn->query("
            SELECT u.id, u.username, u.full_name, COALESCE(u.daily_target,100) as daily_target,
                COUNT(cr.id) as total_assigned,
                SUM(cr.row_status='pending') as pending,
                SUM(cr.row_status IN ('deo_done','done','pending_qc')) as qc1_pending,
                SUM(cr.row_status IN ('qc_done','qc_approved')) as qc2_done,
                SUM(cr.row_status='Completed') as completed,
                SUM(cr.row_status='Completed' AND DATE(cr.updated_at)='$today') as today_done,
                ROUND(SUM(cr.time_spent)/3600,1) as hours_worked
            FROM users u
            LEFT JOIN client_records cr ON (cr.assigned_to=u.username OR cr.assigned_to_id=u.id)
            WHERE u.role='deo' AND u.is_active=1
            GROUP BY u.id ORDER BY today_done DESC, completed DESC
        ");
        $deos = [];
        if ($deo_res) while ($d = $deo_res->fetch_assoc()) {
            $target = max(1,(int)$d['daily_target']);
            $d['target_pct']   = min(100, round(($d['today_done']/$target)*100));
            $d['display_name'] = !empty($d['full_name']) ? $d['full_name'] : $d['username'];
            $deos[] = $d;
        }

        $hourly = [];
        for ($h=7; $h>=0; $h--) {
            $from = date('Y-m-d H:i:s', strtotime("-{$h} hours"));
            $to   = date('Y-m-d H:i:s', strtotime("-".($h-1)." hours"));
            $cnt  = (int)$conn->query("SELECT COUNT(*) c FROM client_records WHERE updated_at>='$from' AND updated_at<'$to' AND row_status='Completed'")->fetch_assoc()['c'];
            $hourly[] = ['label'=>date('h A', strtotime("-{$h} hours")), 'count'=>$cnt];
        }

        $active_time = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $active_res  = $conn->query("SELECT DISTINCT u.username, u.full_name FROM client_records cr JOIN users u ON (u.username=cr.assigned_to OR u.id=cr.assigned_to_id) WHERE cr.updated_at>='$active_time' AND u.role='deo' LIMIT 20");
        $active_users = [];
        if ($active_res) while ($r=$active_res->fetch_assoc())
            $active_users[] = !empty($r['full_name']) ? $r['full_name'] : $r['username'];

        send_json(['status'=>'success','hash'=>$quick_hash,'server_time'=>date('d M Y, h:i:s A'),
            'today'=>date('d M Y'),'total'=>$total,'pending'=>$pending,'qc1_pending'=>$qc1done,
            'qc2_done'=>$qc2done,'completed'=>$completed,'today_done'=>$today_done,'progress'=>$progress,
            'deos'=>$deos,'hourly'=>$hourly,'active_users'=>$active_users]);
    }


} catch (Exception $e) {
    send_json(['status'=>'error', 'message'=>$e->getMessage()]);
}



?>
