<?php
// new
/* ---------- AUTH ---------- */
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

require_once '../../config/db.php';
header("Content-Type: application/json");

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents("php://input"), true);

/* ---------- HELPER ---------- */
function respond($status, $message, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode([
        "status"  => $status,
        "message" => $message,
        "data"    => $data
    ]);
    exit;
}

try {

switch ($method) {

/* =====================================================
   GET USERS (WITH FACILITY + PHARMACY)
===================================================== */
case 'GET':

    //  SINGLE USER
    if (!empty($_GET['id'])) {

        $stmt = $conn->prepare("
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                u.middle_name,
                u.email,
                u.username,
                u.role,
                u.facility_id,
                hf.facility_name,
                COALESCE(p_direct.id, p_via_facility.id) AS pharmacy_id,
                COALESCE(p_direct.pharmacy_name, p_via_facility.pharmacy_name) AS pharmacy_name,
                u.status,
                u.created_at
            FROM users u
            LEFT JOIN health_facilities hf ON u.facility_id = hf.id
            LEFT JOIN pharmacy p_via_facility ON hf.pharmacy_id = p_via_facility.id
            LEFT JOIN pharmacy p_direct ON u.pharmacy_id = p_direct.id
            WHERE u.id = ?
        ");
        $stmt->execute([$_GET['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            respond("error", "User not found", null, 404);
        }

        respond("success", "User fetched", $user);
    }

    //  ALL USERS
    $stmt = $conn->query("
        SELECT 
            u.id,
            u.first_name,
            u.last_name,
            u.middle_name,
            u.email,
            u.username,
            u.role,
            u.facility_id,
            hf.facility_name,
            COALESCE(p_direct.id, p_via_facility.id) AS pharmacy_id,
            COALESCE(p_direct.pharmacy_name, p_via_facility.pharmacy_name) AS pharmacy_name,
            u.status,
            u.created_at
        FROM users u
        LEFT JOIN health_facilities hf ON u.facility_id = hf.id
        LEFT JOIN pharmacy p_via_facility ON hf.pharmacy_id = p_via_facility.id
        LEFT JOIN pharmacy p_direct ON u.pharmacy_id = p_direct.id
        ORDER BY u.created_at DESC
    ");

    respond("success", "Users fetched", $stmt->fetchAll());
    break;


/* =====================================================
   CREATE USER
===================================================== */
case 'POST':

    $required = ['first_name', 'last_name', 'username', 'email', 'password', 'role'];

    foreach ($required as $field) {
        if (empty($input[$field])) {
            respond("error", "$field is required", null, 400);
        }
    }

    // Email uniqueness
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$input['email']]);

    if ($check->fetch()) {
        respond("error", "Email already exists", null, 400);
    }
    
    // Username uniqueness
    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$input['username']]);

    if ($check->fetch()) {
        respond("error", "username already exists", null, 400);
    }

    $stmt = $conn->prepare("
        INSERT INTO users 
        (first_name, last_name, middle_name, email, username, password, role, facility_id, pharmacy_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    "); 

    $stmt->execute([
        trim($input['first_name']),
        trim($input['last_name']),
        $input['middle_name'] ?? null,
        trim($input['email']),
        trim($input['username']),   
        password_hash($input['password'], PASSWORD_DEFAULT),
        $input['role'],
        !empty($input['facility_id']) ? $input['facility_id'] : null,
        !empty($input['pharmacy_id']) ? $input['pharmacy_id'] : null,
        $input['status'] ?? 'active'
    ]);

    respond("success", "User created");
    break;


/* =====================================================
   UPDATE USER
===================================================== */
case 'PUT':

    if (empty($_GET['id'])) {
        respond("error", "User ID required", null, 400);
    }

    $id = $_GET['id'];

    // Check existence
    $check = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $check->execute([$id]);

    if (!$check->fetch()) {
        respond("error", "User not found", null, 404);
    }

    // Email uniqueness
    if (!empty($input['email'])) {
        $emailCheck = $conn->prepare("
            SELECT id FROM users WHERE email = ? AND id != ?
        ");
        $emailCheck->execute([$input['email'], $id]);

        if ($emailCheck->fetch()) {
            respond("error", "Email already used", null, 400);
        }
    }
    
    // Username uniqueness
    if (!empty($input['username'])) {
        $usernameCheck = $conn->prepare("
            SELECT id FROM users WHERE username = ? AND id != ?
        ");
        $usernameCheck->execute([$input['username'], $id]);

        if ($usernameCheck->fetch()) {
            respond("error", "Username already used", null, 400);
        }
    }

    $sql = "
        UPDATE users SET
        first_name = ?,
        last_name = ?,
        middle_name = ?,
        email = ?,
        username = ?,
        role = ?,
        facility_id = ?,
        pharmacy_id = ?,
        status = ?
    ";

    $params = [
        trim($input['first_name']),
        trim($input['last_name']),
        $input['middle_name'] ?? null,
        trim($input['email']),
        trim($input['username']),
        $input['role'],
        !empty($input['facility_id']) ? $input['facility_id'] : null,
        !empty($input['pharmacy_id']) ? $input['pharmacy_id'] : null,
        $input['status'] ?? 'active'
    ];

    // Optional password update
    if (!empty($input['password'])) {
        $sql .= ", password = ?";
        $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
    }

    $sql .= " WHERE id = ?";
    $params[] = $id;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    respond("success", "User updated");
    break;


/* =====================================================
   DELETE USER (SOFT DELETE)
===================================================== */
case 'DELETE':

    if (empty($_GET['id'])) {
        respond("error", "User ID required", null, 400);
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$_GET['id']]);

    respond("success", "User Deleted");
    break;


/* =====================================================
   DEFAULT
===================================================== */
default:
    respond("error", "Method not allowed", null, 405);
}

} catch (Exception $e) {
    respond("error", "Server error", null, 500);
}