<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized access'
    ]);
    exit;
}

include '../../config/db.php';

$id     = $_POST['id'] ?? null;
$status = $_POST['status'] ?? '';

if (!$id || !in_array($status, ['Signed', 'Denied'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request data'
    ]);
    exit;
}

try {

    // Check if prescription exists
    $check = $conn->prepare("SELECT status FROM prescriptions WHERE id = ?");
    $check->execute([$id]);
    $prescription = $check->fetch(PDO::FETCH_ASSOC);

    if (!$prescription) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Prescription not found'
        ]);
        exit;
    }

    // Prevent re-signing signed prescriptions
    if ($prescription['status'] === 'Signed') {
        echo json_encode([
            'status' => 'error',
            'message' => 'Prescription already signed'
        ]);
        exit;
    }

    // Update status
    $stmt = $conn->prepare("
        UPDATE prescriptions 
        SET status = ?, 
            updated_at = NOW(),
            signed_by = ?,
            signed_at = ?
        WHERE id = ?
    ");

    $signedBy = $_SESSION['user']['name'] ?? '';
    $signedAt = ($status === 'Signed') ? date('Y-m-d H:i:s') : null;

    $stmt->execute([
        $status,
        $signedBy,
        $signedAt,
        $id
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Prescription status updated successfully'
    ]);

} catch (Exception $e) {

    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}