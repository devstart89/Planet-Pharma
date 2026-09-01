<?php
include '../../config/db.php';
session_start();

header('Content-Type: application/json');

/* ================= AUTH ================= */
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'] ?? null;

/* ================= ROLE CHECK (ADMIN ONLY) ================= */
if ($role !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden: Admin only']);
    exit;
}

/* ================= INPUT ================= */
$id = $_POST['id'] ?? null;

if (empty($id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

try {

    /* ================= CHECK PATIENT ================= */
    $check = $conn->prepare("SELECT id FROM patients WHERE id = ?");
    $check->execute([$id]);

    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Patient not found']);
        exit;
    }

    /* ================= DELETE ================= */
    $stmt = $conn->prepare("DELETE FROM patients WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode([
        'success' => true,
        'message' => 'Patient deleted successfully'
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ]);
}