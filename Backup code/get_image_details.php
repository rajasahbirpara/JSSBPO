<?php
// Ye file DEO aur DQC dashboard se call hoti hai background me
require_once 'config.php';
check_login(); // Security check

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
        $raw_image_no = $row['image_no'];

        // Logic: Agar '_enc' laga hai to usse hata dein
        // Example: SWQRCDIMG_4AQ1_enc ban jayega SWQRCDIMG_4AQ1
        $clean_image_no = str_replace('_enc', '', $raw_image_no);

        echo json_encode(['success' => true, 'found' => true, 'image_no' => $clean_image_no]);
    } else {
        echo json_encode(['success' => true, 'found' => false]);
    }
    $stmt->close();
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>