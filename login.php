<?php
/**
 * JSSBPO UNIFIED LOGIN
 * P1 = Data Entry System (with WhatsApp OTP login)
 * P2 = Management System (direct login)
 */

require_once 'config.php';

// Already logged in? Redirect
if (isset($_SESSION['user_id'])) {
    $preferred_project = $_SESSION['selected_project'] ?? 'p1';
    redirect_by_role($_SESSION['role'], $preferred_project);
    exit();
}

$error = "";
$success = "";

// Check for URL error parameter (e.g., QC Dashboard disabled)
if (isset($_GET['error']) && !empty($_GET['error'])) {
    $error = "⚠️ " . htmlspecialchars($_GET['error']);
}

// Handle P2 Direct Login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_p2'])) {
    $username = clean_input($_POST['username']);
    $password = $_POST['password'];
    $ip = $_SERVER['REMOTE_ADDR'];
    
    if (!check_rate_limit($ip)) {
        $error = "⚠️ Too many failed attempts. Please try again after 15 minutes.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, full_name, is_active FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            if ($user['is_active'] == 0) {
                $error = "❌ Your account is deactivated. Please contact admin.";
                increment_rate_limit($ip);
            } elseif ($user['role'] === 'qc') {
                // QC login check - if dashboard disabled
                $qc_check = $conn->query("SELECT setting_value FROM qc_settings WHERE setting_key='qc_enabled'");
                $qc_enabled = ($qc_check && ($qc_row = $qc_check->fetch_assoc())) ? $qc_row['setting_value'] : '1';
                if ($qc_enabled !== '1') {
                    $error = "❌ QC Dashboard is Disable. Contact Raja.";
                    increment_rate_limit($ip);
                } elseif ($password === $user['password']) {
                    goto p2_login_success;
                } else {
                    $error = "❌ Invalid username or password!";
                    increment_rate_limit($ip);
                }
            } elseif (password_verify($password, $user['password']) || $password === $user['password']) {
                p2_login_success:
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['selected_project'] = 'p2';
                
                reset_rate_limit($ip);
                $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . $user['id']);
                
                redirect_by_role($user['role'], 'p2');
                exit();
            } else {
                $error = "❌ Invalid username or password!";
                increment_rate_limit($ip);
            }
        } else {
            $error = "❌ Invalid username or password!";
            increment_rate_limit($ip);
        }
        $stmt->close();
    }
}

function redirect_by_role($role, $project = 'p1') {
    if ($project == 'p1') {
        switch ($role) {
            case 'admin':
                header("Location: p1_admin_dashboard.php");
                break;
            case 'qc':
                header("Location: Second_qc_dashboard.php");
                break;
            default:
                header("Location: first_qc_dashboard.php");
                break;
        }
    } else {
        switch ($role) {
            case 'admin':
                header("Location: p2_admin_dashboard.php");
                break;
            default:
                header("Location: deo_dashboard.php");
                break;
        }
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JSSBPO - Login</title>
    <link rel="manifest" href="manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .login-container {
            background: white;
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 500px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            color: white;
        }
        
        .login-header h1 {
            color: #1e293b;
            font-size: 1.75rem;
            font-weight: 700;
        }
        
        .login-header p {
            color: #64748b;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #374151;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            z-index: 1;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f9fafb;
            font-family: 'Inter', sans-serif;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .btn-whatsapp {
            background: linear-gradient(135deg, #25D366, #128C7E);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        
        .btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-link {
            background: none;
            color: #667eea;
            padding: 0.5rem;
            font-size: 0.85rem;
        }
        
        .btn-link:hover {
            box-shadow: none;
            text-decoration: underline;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        
        .alert-info {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        /* Project Selection */
        .project-selection {
            margin-bottom: 1.5rem;
        }
        
        .project-selection > label {
            display: block;
            color: #374151;
            font-weight: 600;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }
        
        .project-options {
            display: flex;
            gap: 1rem;
        }
        
        .project-option {
            flex: 1;
            position: relative;
        }
        
        .project-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .project-option > label {
            display: flex;
            flex-direction: column;
            padding: 1.25rem 1rem;
            background: #f8fafc;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .project-option > label:hover {
            border-color: #667eea;
            transform: translateY(-2px);
        }
        
        .project-option input[type="radio"]:checked + label {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .project-option label .project-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            text-align: center;
        }
        
        .project-option label .project-name {
            font-size: 1rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 0.25rem;
        }
        
        .project-option label .project-desc {
            font-size: 0.7rem;
            color: #64748b;
            text-align: center;
        }
        
        .project-option input[type="radio"]:checked + label .project-desc {
            color: rgba(255,255,255,0.85);
        }
        
        .project-option label .login-type {
            margin-top: 0.75rem;
            padding-top: 0.5rem;
            border-top: 1px solid #e5e7eb;
            font-size: 0.7rem;
            text-align: center;
            color: #059669;
            font-weight: 600;
        }
        
        .project-option input[type="radio"]:checked + label .login-type {
            color: #a7f3d0;
            border-top-color: rgba(255,255,255,0.3);
        }
        
        .login-divider {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
            color: #9ca3af;
            font-size: 0.85rem;
        }
        
        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }
        
        .login-divider span {
            padding: 0 1rem;
        }
        
        /* OTP Section */
        .otp-section {
            display: none;
        }
        
        .otp-input {
            text-align: center;
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: 8px;
            padding: 1rem !important;
        }
        
        .otp-info {
            text-align: center;
            margin-bottom: 1rem;
            padding: 1rem;
            background: #f0fdf4;
            border-radius: 10px;
            border: 1px solid #bbf7d0;
        }
        
        .otp-info i {
            color: #25D366;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .otp-info p {
            color: #166534;
            font-size: 0.85rem;
            margin: 0;
        }
        
        .otp-info small {
            color: #64748b;
            font-size: 0.75rem;
        }
        
        #loginMsg {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        #loginMsg.text-success {
            color: #16a34a;
        }
        
        #loginMsg.text-error {
            color: #dc2626;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 1.5rem;
            }
            
            .project-options {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="fas fa-database"></i>
            </div>
            <h1>JSSBPO</h1>
            <p>Data Entry Management System</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Project Selection -->
        <div class="project-selection">
            <label><i class="fas fa-folder-open"></i> Select Project</label>
            <div class="project-options">
                <div class="project-option">
                    <input type="radio" name="project_select" id="proj_p1" value="p1" checked>
                    <label for="proj_p1">
                        <div class="project-icon">📝</div>
                        <div class="project-name">P1 - Data Entry</div>
                        <div class="project-desc">Main data entry with images</div>
                        <div class="login-type"><i class="fab fa-whatsapp"></i> WhatsApp OTP Login</div>
                    </label>
                </div>
                <div class="project-option">
                    <input type="radio" name="project_select" id="proj_p2" value="p2">
                    <label for="proj_p2">
                        <div class="project-icon">📊</div>
                        <div class="project-name">P2 - Management</div>
                        <div class="project-desc">DEO/DQC Dashboards</div>
                        <div class="login-type"><i class="fas fa-key"></i> Direct Password Login</div>
                    </label>
                </div>
            </div>
        </div>
        
        <div class="login-divider">
            <span>Enter Credentials</span>
        </div>
        
        <!-- P1 Login Form (OTP Based) -->
        <div id="p1LoginForm">
            <div id="credSection">
                <div class="form-group">
                    <label for="p1_username">Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="p1_username" placeholder="Enter your username" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="p1_password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="p1_password" placeholder="Enter your password" required>
                    </div>
                </div>
                
                <button type="button" id="p1LoginBtn" onclick="p1Login()" class="btn btn-whatsapp">
                    <i class="fab fa-whatsapp"></i>
                    Send OTP & Login
                </button>
            </div>
            
            <div id="otpSection" class="otp-section">
                <div class="otp-info">
                    <i class="fab fa-whatsapp"></i>
                    <p>OTP sent to your WhatsApp!</p>
                    <small>Enter the 6-digit code below</small>
                </div>
                
                <div class="form-group">
                    <input type="text" id="p1_otp" class="otp-input" placeholder="------" maxlength="6">
                </div>
                
                <button type="button" id="otpVerifyBtn" onclick="p1Login()" class="btn btn-success">
                    <i class="fas fa-check-circle"></i>
                    Verify OTP & Login
                </button>
                
                <button type="button" onclick="resendOtp()" class="btn btn-link">
                    <i class="fas fa-redo"></i> Resend OTP
                </button>
            </div>
            
            <div id="loginMsg"></div>
        </div>
        
        <!-- P2 Login Form (Direct) -->
        <form id="p2LoginForm" method="POST" style="display: none;">
            <div class="form-group">
                <label for="p2_username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="p2_username" name="username" placeholder="Enter your username" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="p2_password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="p2_password" name="password" placeholder="Enter your password" required>
                </div>
            </div>
            
            <button type="submit" name="login_p2" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i>
                Login to P2
            </button>
        </form>
    </div>
    
    <script>
        let isOtpStep = false;
        
        // Toggle forms based on project selection
        $('input[name="project_select"]').on('change', function() {
            const project = $(this).val();
            if (project === 'p1') {
                $('#p1LoginForm').show();
                $('#p2LoginForm').hide();
            } else {
                $('#p1LoginForm').hide();
                $('#p2LoginForm').show();
            }
            // Reset OTP section
            isOtpStep = false;
            $('#credSection').show();
            $('#otpSection').hide();
            $('#loginMsg').text('');
        });
        
        // Check if already logged in
        $.post('api.php', {action: 'check_session'}, function(res) {
            if(res.status === 'logged_in') {
                redirectP1User(res.role);
            }
        }, 'json');
        
        // P1 OTP Login
        function p1Login() {
            $('#loginMsg').text('').removeClass('text-success text-error');
            
            let btn = isOtpStep ? $('#otpVerifyBtn') : $('#p1LoginBtn');
            let originalHtml = btn.html();
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
            
            let u = $('#p1_username').val();
            let p = $('#p1_password').val();
            let otp = $('#p1_otp').val();
            let action = isOtpStep ? 'verify_otp' : 'login_init';
            
            $.post('api.php', {
                action: action,
                username: u,
                password: p,
                otp: otp
            }, function(res) {
                btn.prop('disabled', false).html(originalHtml);
                
                if (res.status === 'success') {
                    $('#loginMsg').addClass('text-success').text('✅ Login successful! Redirecting...');
                    $.post('api.php', {action: 'check_session'}, function(sessionRes) {
                        if(sessionRes.status === 'logged_in') {
                            redirectP1User(sessionRes.role);
                        }
                    }, 'json');
                } else if (res.status === 'otp_sent') {
                    isOtpStep = true;
                    $('#credSection').slideUp();
                    $('#otpSection').slideDown();
                    $('#p1_otp').focus();
                    $('#loginMsg').addClass('text-success').text('✅ ' + res.message);
                } else {
                    $('#loginMsg').addClass('text-error').text('❌ ' + res.message);
                }
            }, 'json').fail(function(xhr, status, error) {
                btn.prop('disabled', false).html(originalHtml);
                let errMsg = '❌ Server Error.';
                if(xhr.status === 0) errMsg = '❌ Server se connection nahi ho pa raha. Internet check karein.';
                else if(xhr.status === 404) errMsg = '❌ api.php file nahi mili server par.';
                else if(xhr.status === 500) errMsg = '❌ Server Error (500). Admin se contact karein.';
                else if(xhr.responseText) {
                    try { let r = JSON.parse(xhr.responseText); errMsg = '❌ ' + (r.message || r.error || 'Server Error'); } 
                    catch(e) { errMsg = '❌ Server Error: ' + xhr.responseText.substring(0, 100); }
                }
                $('#loginMsg').addClass('text-error').text(errMsg);
            });
        }
        
        function resendOtp() {
            isOtpStep = false;
            $('#loginMsg').text('').removeClass('text-success text-error');
            $('#p1_otp').val('');
            p1Login();
        }
        
        function redirectP1User(role) {
            if (role === 'admin') {
                window.location.href = 'p1_admin_dashboard.php';
            } else if (role === 'qc') {
                window.location.href = 'Second_qc_dashboard.php';
            } else {
                window.location.href = 'first_qc_dashboard.php';
            }
        }
        
        // Enter key handlers
        $('#p1_username, #p1_password').on('keydown', function(e) {
            if (e.key === 'Enter') p1Login();
        });
        
        $('#p1_otp').on('keydown', function(e) {
            if (e.key === 'Enter') p1Login();
        });
        
        // Service worker for PWA
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(err => console.log('SW Error:', err));
        }
    </script>
</body>
</html>
