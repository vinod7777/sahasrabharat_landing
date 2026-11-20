<?php
// Database Configuration for Sahasra Bharat Website

define('DB_HOST', 'localhost');
define('DB_USER', 'u928821418_blog');
define('DB_PASS', 'Sahasra@bharat@124');
define('DB_NAME', 'u928821418_blog');
define('ADMIN_PASS', 'Sahasra@bharat@124');

// Establish Database Connection
function getDbConnection() {
    // Try connecting directly to the database
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // If connection failed because database doesn't exist, try connecting without db name and creating it
    if ($conn->connect_error) {
        $conn_initial = @new mysqli(DB_HOST, DB_USER, DB_PASS);
        if ($conn_initial->connect_error) {
            http_response_code(500);
            echo json_encode(["error" => "Database connection failed: " . $conn_initial->connect_error]);
            exit;
        }
        
        $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
        if ($conn_initial->query($sql) === TRUE) {
            $conn_initial->select_db(DB_NAME);
            $conn = $conn_initial;
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to connect to database " . DB_NAME . " and could not create it: " . $conn_initial->error]);
            exit;
        }
    }
    
    // Create blogs table if it doesn't exist
    $createBlogsTable = "CREATE TABLE IF NOT EXISTS blogs (
        id VARCHAR(50) PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        date_str VARCHAR(50) NOT NULL,
        excerpt TEXT NOT NULL,
        category VARCHAR(50) NOT NULL,
        content TEXT,
        color VARCHAR(100),
        image VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->query($createBlogsTable);
    
    // Create submissions table if it doesn't exist
    $createSubmissionsTable = "CREATE TABLE IF NOT EXISTS submissions (
        id VARCHAR(50) PRIMARY KEY,
        type VARCHAR(20) NOT NULL DEFAULT 'health',
        full_name VARCHAR(100) NOT NULL,
        whatsapp VARCHAR(30) NOT NULL,
        date_str VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($createSubmissionsTable);
    
    return $conn;
}
?>
