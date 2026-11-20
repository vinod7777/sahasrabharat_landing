<?php
session_start();

header('Content-Type: application/json');
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
    header("Access-Control-Allow-Credentials: true");
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST");

require_once 'config.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

$conn = getDbConnection();

function checkAuth() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized"]);
        exit;
    }
}

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'check_session':
        handleCheckSession();
        break;
    case 'get_blogs':
        handleGetBlogs($conn);
        break;
    case 'save_blog':
        checkAuth();
        handleSaveBlog($conn);
        break;
    case 'delete_blog':
        checkAuth();
        handleDeleteBlog($conn);
        break;
    case 'upload_image':
        checkAuth();
        handleUploadImage();
        break;
    case 'get_submissions':
        checkAuth();
        handleGetSubmissions($conn);
        break;
    case 'save_submission':
        handleSaveSubmission($conn);
        break;
    case 'delete_submission':
        checkAuth();
        handleDeleteSubmission($conn);
        break;
    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid action"]);
        break;
}

$conn->close();

// Helper to keep static blog-data.json synchronized with MySQL DB
function syncBlogsJsonFile($conn) {
    $sql = "SELECT * FROM blogs ORDER BY created_at DESC";
    $result = $conn->query($sql);
    
    $blogs = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $blogs[] = [
                "id" => $row['id'],
                "title" => $row['title'],
                "date" => $row['date_str'],
                "excerpt" => $row['excerpt'],
                "category" => $row['category'],
                "content" => $row['content'],
                "color" => $row['color'] ? $row['color'] : 'from-brand-gold/20 to-brand-green/20',
                "image" => $row['image']
            ];
        }
    }
    
    $data = ["blogs" => $blogs];
    file_put_contents(__DIR__ . '/blog-data.json', json_encode($data, JSON_PRETTY_PRINT));
}

// 1. Fetch all blogs
function handleGetBlogs($conn) {
    $sql = "SELECT * FROM blogs ORDER BY created_at DESC";
    $result = $conn->query($sql);
    
    $blogs = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $blogs[] = [
                "id" => $row['id'],
                "title" => $row['title'],
                "date" => $row['date_str'],
                "excerpt" => $row['excerpt'],
                "category" => $row['category'],
                "content" => $row['content'],
                "color" => $row['color'],
                "image" => $row['image']
            ];
        }
    }
    echo json_encode(["blogs" => $blogs]);
}

// 2. Insert or update a blog post
function handleSaveBlog($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        return;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['id']) || !isset($data['title'])) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid blog data"]);
        return;
    }

    $id = $conn->real_escape_string($data['id']);
    $title = $conn->real_escape_string($data['title']);
    $dateStr = $conn->real_escape_string($data['date']);
    $excerpt = $conn->real_escape_string($data['excerpt']);
    $category = $conn->real_escape_string($data['category']);
    $content = isset($data['content']) ? $conn->real_escape_string($data['content']) : '';
    $color = isset($data['color']) ? $conn->real_escape_string($data['color']) : '';
    $image = isset($data['image']) ? $conn->real_escape_string($data['image']) : '';

    // Check if blog already exists
    $checkSql = "SELECT id FROM blogs WHERE id = '$id'";
    $checkResult = $conn->query($checkSql);

    if ($checkResult && $checkResult->num_rows > 0) {
        // Update existing
        $sql = "UPDATE blogs SET 
                title = '$title', 
                date_str = '$dateStr', 
                excerpt = '$excerpt', 
                category = '$category', 
                content = '$content', 
                color = '$color', 
                image = '$image' 
                WHERE id = '$id'";
    } else {
        // Insert new
        $sql = "INSERT INTO blogs (id, title, date_str, excerpt, category, content, color, image) 
                VALUES ('$id', '$title', '$dateStr', '$excerpt', '$category', '$content', '$color', '$image')";
    }

    if ($conn->query($sql) === TRUE) {
        syncBlogsJsonFile($conn);
        echo json_encode(["success" => true]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Database save failed: " . $conn->error]);
    }
}

// 3. Delete a blog post
function handleDeleteBlog($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        return;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing blog ID"]);
        return;
    }

    $id = $conn->real_escape_string($data['id']);
    $sql = "DELETE FROM blogs WHERE id = '$id'";

    if ($conn->query($sql) === TRUE) {
        syncBlogsJsonFile($conn);
        echo json_encode(["success" => true]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Failed to delete blog: " . $conn->error]);
    }
}

// 4. File uploads handler
function handleUploadImage() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        return;
    }

    $uploadsDir = __DIR__ . '/uploads';
    if (!file_exists($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    // Support standard multipart form file upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['image'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!$ext) $ext = 'jpg';
        $filename = 'upload_' . time() . '.' . $ext;
        $targetFile = $uploadsDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            echo json_encode(["imageUrl" => "uploads/" . $filename]);
            return;
        }
    }
    
    // Support raw body upload (fallback matching server.js client side)
    $mimeType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : 'image/png';
    $parts = explode('/', $mimeType);
    $ext = isset($parts[1]) ? $parts[1] : 'png';
    $filename = 'upload_' . time() . '.' . $ext;
    $targetFile = $uploadsDir . '/' . $filename;

    $input = fopen("php://input", "r");
    $output = fopen($targetFile, "w");

    if ($input && $output) {
        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        echo json_encode(["imageUrl" => "uploads/" . $filename]);
        return;
    }

    http_response_code(500);
    echo json_encode(["error" => "Image upload failed"]);
}

// 5. Get Form Submissions
function handleGetSubmissions($conn) {
    $sql = "SELECT * FROM submissions ORDER BY created_at DESC";
    $result = $conn->query($sql);
    
    $health = [];
    $farm = []; // reserved for compatibility
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $health[] = [
                "id" => $row['id'],
                "fullName" => $row['full_name'],
                "whatsapp" => $row['whatsapp'],
                "date" => $row['date_str']
            ];
        }
    }
    echo json_encode([
        "submissions" => [
            "health" => $health,
            "farm" => $farm
        ]
    ]);
}

// 6. Save a walk submission
function handleSaveSubmission($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        return;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['fullName']) || !isset($data['whatsapp'])) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid submission data"]);
        return;
    }

    $id = isset($data['id']) ? $conn->real_escape_string($data['id']) : time() . rand(100, 999);
    $type = isset($data['type']) ? $conn->real_escape_string($data['type']) : 'health';
    $fullName = $conn->real_escape_string($data['fullName']);
    $whatsapp = $conn->real_escape_string($data['whatsapp']);
    $dateStr = isset($data['date']) ? $conn->real_escape_string($data['date']) : date('d/m/Y, h:i:s A');

    $sql = "INSERT INTO submissions (id, type, full_name, whatsapp, date_str) 
            VALUES ('$id', '$type', '$fullName', '$whatsapp', '$dateStr')";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["success" => true]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Database save failed: " . $conn->error]);
    }
}

// 7. Delete a submission
function handleDeleteSubmission($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        return;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing submission ID"]);
        return;
    }

    $id = $conn->real_escape_string($data['id']);
    $sql = "DELETE FROM submissions WHERE id = '$id'";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["success" => true]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Failed to delete submission: " . $conn->error]);
    }
}

// 8. Admin login verification
function handleLogin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        return;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['password'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing password"]);
        return;
    }

    // Compare with ADMIN_PASS constant
    if ($data['password'] === ADMIN_PASS) {
        $_SESSION['admin_logged_in'] = true;
        echo json_encode(["success" => true]);
    } else {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Invalid password"]);
    }
}

// 9. Admin logout
function handleLogout() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    echo json_encode(["success" => true]);
}

// 10. Check session status
function handleCheckSession() {
    $loggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    echo json_encode(["logged_in" => $loggedIn]);
}
?>
