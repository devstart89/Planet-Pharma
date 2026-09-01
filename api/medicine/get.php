<?php
session_start();
require '../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$id = $_GET['id'] ?? null;

if (!$id) {
    http_response_code(422);
    echo json_encode(['error' => 'Missing medicine id']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM medicines WHERE id = ?");
$stmt->execute([$id]);
$medicine = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$medicine) {
    http_response_code(404);
    echo json_encode(['error' => 'Medicine not found']);
    exit;
}

// uom comes through automatically here via SELECT * once the
// medicines.uom column exists (see migration_uom.sql) — no code
// change needed in this file beyond the session/id safety above.
echo json_encode($medicine);