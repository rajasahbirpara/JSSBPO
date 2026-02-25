<?php
/**
 * JSSBPO UNIFIED CONFIG
 * Merged from Project 1 & Project 2
 */

// ============================================================
// IMAGE PATH CONFIGURATION
// ============================================================
// Change this path according to your server structure
// Example: '/uploads/mapping_images/' or '/2/uploads/mapping_images/'
define('IMAGE_BASE_PATH', '/uploads/mapping_images/');
define('IMAGE_DIR_PATH', './uploads/mapping_images/');  // Server file path

// ============================================================
// ERROR HANDLING
// ============================================================
if ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/php_errors.log');
}

// ============================================================
// SESSION CONFIGURATION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// ============================================================
// TIMEZONE
// ============================================================
date_default_timezone_set('Asia/Kolkata');

// ============================================================
// SECURITY HEADERS
// ============================================================
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// ============================================================
// DATABASE CONNECTION
// ============================================================
$servername = getenv('DB_HOST') ?: "localhost";
$username = getenv('DB_USER') ?: "u859246549_JSSBPOUN"; 
$password = getenv('DB_PASS') ?: "Rajendra#2026"; 
$dbname = getenv('DB_NAME') ?: "u859246549_JSSBPODB";
// Error reporting off for mysqli
mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Get Database Connection - Reliable reconnect logic
 */
function getDbConnection() {
    global $servername, $username, $password, $dbname;
    static $conn = null;

    // Connection valid check - ping() avoid karo, thread_id check reliable hai
    if ($conn !== null) {
        // Simple liveness check without ping
        $test = @$conn->query("SELECT 1");
        if ($test === false) {
            // Connection dead - reset karo
            @$conn->close();
            $conn = null;
        }
    }

    if ($conn === null) {
        // Pehle normal connection try karo (persistent se problem hoti hai shared hosting par)
        $conn = new mysqli($servername, $username, $password, $dbname);

        if ($conn->connect_error) {
            error_log("Database Connection Failed: " . $conn->connect_error);
            // Die nahi karo - graceful error page dikhai
            $err = $conn->connect_error;
            $conn = null;
            http_response_code(503);
            // Agar AJAX/JSON request hai to JSON error do
            $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                   || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                   || (isset($_GET['deep_search_rec']) || isset($_GET['get_bulk_template']) || isset($_GET['fetch_notifications']) || isset($_POST['action']));
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'DB unavailable', 'success' => false]);
            } else {
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Connection Error</title>
                <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f1f5f9;}
                .box{background:white;padding:2rem 2.5rem;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center;max-width:400px;}
                h2{color:#ef4444;margin:0 0 .5rem}p{color:#64748b;margin:0 0 1rem}
                button{background:#3b82f6;color:white;border:none;padding:.6rem 1.5rem;border-radius:6px;cursor:pointer;font-size:.9rem;}
                </style></head><body><div class="box">
                <h2>⚠️ Connection Error</h2>
                <p>Database se connection nahi ho pa raha. Server busy ho sakta hai.</p>
                <button onclick="location.reload()">🔄 Dobara Try Karein</button>
                </div></body></html>';
            }
            exit();
        }

        $conn->set_charset("utf8mb4");

        // Performance settings
        $conn->query("SET SESSION wait_timeout=300");
        $conn->query("SET SESSION interactive_timeout=300");
        $conn->query("SET SESSION net_read_timeout=60");
        $conn->query("SET SESSION net_write_timeout=120");
        $conn->query("SET time_zone = '+05:30'");
        $conn->autocommit(true);
    }

    return $conn;
}

// Initialize main connection
$conn = getDbConnection();

// ============================================================
// SCHEMA UPDATES - Add QC role to users table
// ============================================================
// This will silently add 'qc' to the role enum if not present
$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','supervisor','deo','dqc','qc') DEFAULT 'deo'");

// ============================================================
// TRANSACTION HELPER FUNCTIONS
// ============================================================

/**
 * Start a database transaction
 */
function db_begin_transaction() {
    global $conn;
    $conn->autocommit(false);
    $conn->begin_transaction();
}

/**
 * Commit the current transaction
 */
function db_commit() {
    global $conn;
    $conn->commit();
    $conn->autocommit(true);
}

/**
 * Rollback the current transaction
 */
function db_rollback() {
    global $conn;
    $conn->rollback();
    $conn->autocommit(true);
}

/**
 * Execute query with retry logic for deadlocks
 * @param string $sql SQL query
 * @param int $max_retries Maximum retry attempts
 * @return mysqli_result|bool
 */
function db_query_with_retry($sql, $max_retries = 3) {
    global $conn;
    $retries = 0;
    
    while ($retries < $max_retries) {
        $result = $conn->query($sql);
        
        if ($result !== false) {
            return $result;
        }
        
        // Check if it's a deadlock or lock timeout error
        $errno = $conn->errno;
        if ($errno == 1213 || $errno == 1205) { // Deadlock or Lock wait timeout
            $retries++;
            usleep(100000 * $retries); // Wait 100ms * retry count
            error_log("DB Deadlock detected, retry $retries: $sql");
            continue;
        }
        
        // Other error - don't retry
        error_log("DB Error ($errno): " . $conn->error . " - Query: $sql");
        return false;
    }
    
    return false;
}

/**
 * Safe UPDATE with transaction and retry
 * @param string $table Table name
 * @param array $data Associative array of column => value
 * @param string $where WHERE clause (without 'WHERE')
 * @return bool Success status
 */
function db_safe_update($table, $data, $where) {
    global $conn;
    
    $sets = [];
    $types = '';
    $values = [];
    
    foreach ($data as $col => $val) {
        $sets[] = "`$col` = ?";
        if (is_int($val)) {
            $types .= 'i';
        } elseif (is_float($val)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
        $values[] = $val;
    }
    
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $where";
    
    try {
        db_begin_transaction();
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$values);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        db_commit();
        return true;
        
    } catch (Exception $e) {
        db_rollback();
        error_log("db_safe_update error: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// INPUT/OUTPUT HELPER FUNCTIONS
// ============================================================

function clean_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    return $conn->real_escape_string($data);
}

function safe_output($data) {
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

// ============================================================
// AUTHENTICATION FUNCTIONS
// ============================================================

function check_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
}

function check_role($allowed_roles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
        die("❌ Access Denied! Aapke paas is page ko dekhne ki permission nahi hai.");
    }
}

function get_user_info() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'full_name' => $_SESSION['full_name']
        ];
    }
    return null;
}

// ============================================================
// USER HELPER FUNCTIONS
// ============================================================

function get_user_id_by_username($username) {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['id'];
    }
    return null;
}

function get_username_by_id($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['username'];
    }
    return null;
}

function update_user_stats($user_id, $action = 'completed') {
    global $conn;
    
    $check = $conn->prepare("SELECT id FROM user_stats WHERE user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    
    if ($check->get_result()->num_rows == 0) {
        $insert = $conn->prepare("INSERT INTO user_stats (user_id, total_records, total_completed, last_active) VALUES (?, 0, 0, NOW())");
        $insert->bind_param("i", $user_id);
        $insert->execute();
    }
    
    if ($action == 'completed') {
        $update = $conn->prepare("UPDATE user_stats SET total_completed = total_completed + 1, total_records = total_records + 1, last_active = NOW() WHERE user_id = ?");
    } else {
        $update = $conn->prepare("UPDATE user_stats SET total_records = total_records + 1, last_active = NOW() WHERE user_id = ?");
    }
    $update->bind_param("i", $user_id);
    $update->execute();
}

function log_work($user_id, $record_no, $action, $time_spent = 0) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO work_logs (user_id, record_no, action, time_spent) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("issi", $user_id, $record_no, $action, $time_spent);
    $stmt->execute();
}

// ============================================================
// CSRF PROTECTION
// ============================================================

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

// ============================================================
// RATE LIMITING
// ============================================================

function check_rate_limit($identifier, $max_attempts = 5, $lockout_time = 900) {
    $key = 'login_attempts_' . md5($identifier);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }
    
    $data = $_SESSION[$key];
    
    if (time() - $data['first_attempt'] > $lockout_time) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
        return true;
    }
    
    if ($data['count'] >= $max_attempts) {
        return false;
    }
    
    return true;
}

function increment_rate_limit($identifier) {
    $key = 'login_attempts_' . md5($identifier);
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }
    $_SESSION[$key]['count']++;
}

function reset_rate_limit($identifier) {
    $key = 'login_attempts_' . md5($identifier);
    unset($_SESSION[$key]);
}

// ============================================================
// VALIDATION
// ============================================================

function validate_record_no($record_no) {
    return preg_match('/^\d+$/', $record_no);
}

// ============================================================
// QUERY CACHE (for performance)
// ============================================================

class QueryCache {
    private static $cache = [];
    private static $max_size = 100;
    private static $ttl = 30;
    
    public static function get($key) {
        if (isset(self::$cache[$key])) {
            if (time() - self::$cache[$key]['time'] < self::$ttl) {
                return self::$cache[$key]['data'];
            }
            unset(self::$cache[$key]);
        }
        return null;
    }
    
    public static function set($key, $data) {
        if (count(self::$cache) >= self::$max_size) {
            array_shift(self::$cache);
        }
        self::$cache[$key] = ['data' => $data, 'time' => time()];
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
}

// ============================================================
// BULK ADMIN REPORT HANDLER
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_admin_report_data'])) {
    $bulk_data = json_decode($_POST['bulk_admin_report_data'], true);
    $success_count = 0;
    $error_count = 0;
    
    $stmt_get_img = $conn->prepare("SELECT image_no FROM record_image_map WHERE record_no = ?");
    
    foreach ($bulk_data as $row) {
        if (empty($row['Record_No'])) {
            $error_count++;
            continue;
        }
        
        $record_no = mysqli_real_escape_string($conn, trim($row['Record_No']));
        $error_field = mysqli_real_escape_string($conn, trim($row['Error_Field'] ?? ''));
        $error_details = mysqli_real_escape_string($conn, trim($row['Details'] ?? ''));
        $image_name = isset($row['Image_Name']) ? trim($row['Image_Name']) : (isset($row['Image_No']) ? trim($row['Image_No']) : '');
        $deo_id = $_SESSION['user_id'] ?? 0;
        
        if (empty($image_name)) {
            $stmt_get_img->bind_param("s", $record_no);
            $stmt_get_img->execute();
            $img_result = $stmt_get_img->get_result();
            if ($img_result && $img_result->num_rows > 0) {
                $img_row = $img_result->fetch_assoc();
                $image_name = $img_row['image_no'];
            } else {
                $image_name = 'N/A';
            }
        }
        
        $final_details = "[Image No: $image_name] " . $error_details;
        $final_details = mysqli_real_escape_string($conn, $final_details);
        
        $check = "SELECT * FROM critical_errors WHERE record_no = '$record_no' AND error_field = '$error_field' AND status = 'pending'";
        if (mysqli_num_rows(mysqli_query($conn, $check)) == 0) {
            $insert = "INSERT INTO critical_errors (record_no, deo_id, error_field, error_details, status, created_at) 
                       VALUES ('$record_no', '$deo_id', '$error_field', '$final_details', 'pending', NOW())";
            if (mysqli_query($conn, $insert)) {
                $success_count++;
            } else {
                $error_count++;
            }
        } else {
            $error_count++;
        }
    }
    $stmt_get_img->close();
    echo "<script>alert('Bulk Report: $success_count success, $error_count skipped (duplicates or errors)');</script>";
}

// ============================================================
// INCLUDE ADDITIONAL FUNCTIONS
// ============================================================

if (file_exists(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php';
}
?>