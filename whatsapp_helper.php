<?php
/**
 * WhatsApp Notification Helper - whatsapp_helper.php
 * Security Fixed Version with Database Settings Support
 */

// Default WhatsApp API Configuration (will be overridden by database)
define('WHATSAPP_INSTANCE_ID', getenv('WA_INSTANCE_ID') ?: '69214A1CC1E3D');
define('WHATSAPP_CLIENT_ID', getenv('WA_CLIENT_ID') ?: '6809e944a22d4');
define('WHATSAPP_API_URL', getenv('WA_API_URL') ?: 'https://whatsapp.xpressdigital.co.in/api/send');

/**
 * Get WhatsApp settings from database
 */
function getWhatsAppSettings() {
    global $conn;
    
    $settings = [
        'api_url' => WHATSAPP_API_URL,
        'api_key' => WHATSAPP_CLIENT_ID,
        'instance_id' => WHATSAPP_INSTANCE_ID
    ];
    
    if (!isset($conn) || !$conn) {
        return $settings;
    }
    
    // Check if security_settings table exists
    $check = $conn->query("SHOW TABLES LIKE 'security_settings'");
    if ($check->num_rows == 0) {
        return $settings;
    }
    
    // Get settings from database
    $result = $conn->query("SELECT setting_key, setting_value FROM security_settings WHERE setting_key IN ('whatsapp_api_url', 'whatsapp_api_key', 'whatsapp_instance_id')");
    while ($row = $result->fetch_assoc()) {
        if ($row['setting_key'] === 'whatsapp_api_url' && !empty($row['setting_value'])) {
            $settings['api_url'] = $row['setting_value'];
        }
        if ($row['setting_key'] === 'whatsapp_api_key' && !empty($row['setting_value'])) {
            $settings['api_key'] = $row['setting_value'];
        }
        if ($row['setting_key'] === 'whatsapp_instance_id' && !empty($row['setting_value'])) {
            $settings['instance_id'] = $row['setting_value'];
        }
    }
    
    return $settings;
}

/**
 * Format phone number for WhatsApp (add 91 prefix for India)
 */
function formatWhatsAppNumber($phoneNumber) {
    if (!$phoneNumber) return null;
    
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
    
    // Remove leading zeros
    $phone = ltrim($phone, '0');
    
    // If number doesn't start with 91, add it
    if (!preg_match('/^91/', $phone)) {
        // Check if it's a valid 10-digit Indian mobile number
        if (preg_match('/^[6-9][0-9]{9}$/', $phone)) {
            $phone = '91' . $phone;
        } elseif (strlen($phone) == 10) {
            $phone = '91' . $phone;
        }
    }
    
    // Validate final format (91 + 10 digits)
    if (preg_match('/^91[6-9][0-9]{9}$/', $phone)) {
        return $phone;
    }
    
    // Also allow numbers with country code already
    if (preg_match('/^[0-9]{10,15}$/', $phone)) {
        return $phone;
    }
    
    error_log("Invalid WhatsApp number format: " . $phoneNumber . " -> " . $phone);
    return null;
}

/**
 * Send WhatsApp message
 */
function sendWhatsApp($destNumber, $message) {
    try {
        // Format the number
        $formattedNumber = formatWhatsAppNumber($destNumber);
        if (!$formattedNumber) {
            error_log("WhatsApp: Invalid number format - " . $destNumber);
            return false;
        }
        
        // Get settings from database
        $settings = getWhatsAppSettings();
        
        // Prepare API URL based on API type
        $apiUrl = $settings['api_url'];
        
        // Check if it's using query string or JSON body
        if (strpos($apiUrl, '?') !== false || strpos($apiUrl, 'send') !== false) {
            // Query string based API
            $url = $apiUrl . 
                   (strpos($apiUrl, '?') === false ? '?' : '&') .
                   "number=" . urlencode($formattedNumber) . 
                   "&type=text" . 
                   "&message=" . urlencode($message) . 
                   "&instance_id=" . urlencode($settings['instance_id']) . 
                   "&access_token=" . urlencode($settings['api_key']);
            
            error_log("WhatsApp API URL: " . preg_replace('/access_token=[^&]+/', 'access_token=***', $url));
            
            // Send GET request
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 30,
                    'header' => "User-Agent: BPO-Dashboard-System\r\n"
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
        } else {
            // JSON body based API
            $postData = json_encode([
                'number' => $formattedNumber,
                'message' => $message,
                'type' => 'text',
                'instance_id' => $settings['instance_id']
            ]);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $settings['api_key'],
                        'User-Agent: BPO-Dashboard-System'
                    ],
                    'content' => $postData,
                    'timeout' => 30
                ]
            ]);
            
            error_log("WhatsApp API POST to: " . $apiUrl);
            $response = @file_get_contents($apiUrl, false, $context);
        }
        
        if ($response === false) {
            $error = error_get_last();
            error_log("WhatsApp API: Failed to send request - " . ($error['message'] ?? 'Unknown error'));
            return false;
        }
        
        // Parse response
        $responseData = json_decode($response, true);
        error_log("WhatsApp API Response: " . $response);
        
        if ($responseData) {
            // Check various success indicators
            if (isset($responseData['status']) && ($responseData['status'] === 'success' || $responseData['status'] === true)) {
                return true;
            }
            if (isset($responseData['success']) && $responseData['success']) {
                return true;
            }
            if (isset($responseData['sent']) && $responseData['sent']) {
                return true;
            }
            if (isset($responseData['message_id'])) {
                return true;
            }
        }
        
        // If we got here without error, assume success
        return true; 
        
    } catch (Exception $e) {
        error_log("WhatsApp send error: " . $e->getMessage());
        return false;
    }
}

/**
 * Message Templates
 */
class WhatsAppTemplates {
    
    public static function otpNotification($otp) {
        return "🔐 *LOGIN OTP*\n\n" .
               "Your One-Time Password is: *{$otp}*\n\n" .
               "Use this to log in to the BPO Dashboard.\n" .
               "This code expires in 5 minutes.\n\n" .
               "_Do not share this code with anyone._";
    }

    public static function punchInNotification($employeeName, $employeeId, $time) {
        return "🟢 *PUNCH IN ALERT*\n\n" .
               "📋 *Employee:* {$employeeName}\n" .
               "🆔 *ID:* {$employeeId}\n" .
               "⏰ *Time:* {$time}\n" .
               "📍 *Status:* Successfully Punched In\n\n" .
               "_BPO Dashboard System_";
    }
    
    public static function punchOutNotification($employeeName, $employeeId, $time, $workingHours = null) {
        $message = "🔴 *PUNCH OUT ALERT*\n\n" .
                   "📋 *Employee:* {$employeeName}\n" .
                   "🆔 *ID:* {$employeeId}\n" .
                   "⏰ *Time:* {$time}\n" .
                   "📍 *Status:* Successfully Punched Out\n";
        
        if ($workingHours) {
            $message .= "⏱️ *Working Hours:* {$workingHours}\n";
        }
        
        $message .= "\n_BPO Dashboard System_";
        
        return $message;
    }
    
    public static function taskCompletedNotification($employeeName, $taskCount, $date) {
        return "✅ *TASK COMPLETION ALERT*\n\n" .
               "📋 *Employee:* {$employeeName}\n" .
               "📊 *Tasks Completed:* {$taskCount}\n" .
               "📅 *Date:* {$date}\n\n" .
               "_BPO Dashboard System_";
    }
    
    // NEW TEMPLATES
    
    public static function dailySummary($username, $completed, $target, $percentage, $avgTime, $date) {
        $status = $percentage >= 100 ? "🎉 TARGET ACHIEVED!" : ($percentage >= 80 ? "👍 Almost there!" : "⚠️ Keep working!");
        return "📊 *DAILY SUMMARY*\n\n" .
               "📅 *Date:* {$date}\n" .
               "👤 *User:* {$username}\n\n" .
               "📈 *Performance:*\n" .
               "• Completed: *{$completed}* records\n" .
               "• Target: *{$target}*\n" .
               "• Progress: *{$percentage}%*\n" .
               "• Avg Time: *{$avgTime}* sec/record\n\n" .
               "{$status}\n\n" .
               "_BPO Dashboard System_";
    }
    
    public static function targetCompleted($username, $target, $time) {
        return "🎯 *TARGET COMPLETED!*\n\n" .
               "🏆 Congratulations *{$username}*!\n\n" .
               "You have completed your daily target of *{$target}* records!\n" .
               "⏰ Time: {$time}\n\n" .
               "Keep up the great work! 💪\n\n" .
               "_BPO Dashboard System_";
    }
    
    public static function lowProductivityAlert($username, $completed, $target, $percentage) {
        return "⚠️ *LOW PRODUCTIVITY ALERT*\n\n" .
               "👤 *User:* {$username}\n" .
               "📊 *Current Progress:*\n" .
               "• Completed: *{$completed}*\n" .
               "• Target: *{$target}*\n" .
               "• Progress: *{$percentage}%*\n\n" .
               "Please check if the user needs assistance.\n\n" .
               "_BPO Dashboard System_";
    }
    
    public static function adminDailySummary($date, $totalCompleted, $totalUsers, $topPerformers) {
        $message = "📈 *ADMIN DAILY REPORT*\n\n" .
                   "📅 *Date:* {$date}\n\n" .
                   "📊 *Overview:*\n" .
                   "• Total Completed: *{$totalCompleted}*\n" .
                   "• Active Users: *{$totalUsers}*\n\n" .
                   "🏆 *Top Performers:*\n";
        
        $rank = 1;
        foreach ($topPerformers as $user) {
            $medal = $rank == 1 ? "🥇" : ($rank == 2 ? "🥈" : "🥉");
            $message .= "{$medal} {$user['username']}: *{$user['completed']}* records\n";
            $rank++;
            if ($rank > 3) break;
        }
        
        $message .= "\n_BPO Dashboard System_";
        return $message;
    }
    
    public static function accountLockedAlert($username, $ip, $attempts) {
        return "🔒 *ACCOUNT LOCKED ALERT*\n\n" .
               "👤 *User:* {$username}\n" .
               "🌐 *IP:* {$ip}\n" .
               "❌ *Failed Attempts:* {$attempts}\n\n" .
               "Account has been locked due to multiple failed login attempts.\n\n" .
               "_BPO Dashboard System_";
    }
    
    public static function customMessage($title, $body) {
        return "📢 *{$title}*\n\n" .
               "{$body}\n\n" .
               "_BPO Dashboard System_";
    }
}
?>