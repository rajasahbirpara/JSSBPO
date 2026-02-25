<?php
// Ye file DEO/DQC dashboard se call hogi data laane ke liye
require_once 'config.php';
check_login();

header('Content-Type: application/json');

if (isset($_GET['record_no'])) {
    $record_no = clean_input($_GET['record_no']);
    
    // Validate record number format
    if (!validate_record_no($record_no)) {
        echo json_encode(['success' => false, 'message' => 'Invalid record number format']);
        exit();
    }
    
    // Use prepared statement for security
    $stmt = $conn->prepare("SELECT image_no FROM record_image_map WHERE record_no = ? LIMIT 1");
    $stmt->bind_param("s", $record_no);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode(['success' => true, 'found' => true, 'image_no' => $row['image_no']]);
    } else {
        echo json_encode(['success' => true, 'found' => false]);
    }
    $stmt->close();
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>