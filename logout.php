<?php
// Cache bilkul band karo — browser purani session store na kare
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

// Session start karo agar already nahi hai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Saari session variables hatao
$_SESSION = array();

// Session cookie destroy karo
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Session destroy karo
session_destroy();

// Login page par bhejo — absolute URL use karo loop avoid karne ke liye
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
header("Location: " . $protocol . "://" . $host . "/login.php");
exit();
?>