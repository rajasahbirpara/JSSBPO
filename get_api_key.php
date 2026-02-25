<?php
/**
 * API Key Provider for KYC Utility
 * Returns API key securely from database settings
 */
require_once 'config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get API key from system_settings table
$api_key = '';
$result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'api_key' LIMIT 1");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $api_key = $row['setting_value'];
}

echo json_encode(['key' => $api_key]);
?>