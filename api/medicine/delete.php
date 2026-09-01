<?php
session_start();
require '../../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

$id = $_POST['id'] ?? null;

if(!$id){
    echo json_encode(['success'=>false,'error'=>'Invalid ID']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM medicines WHERE id=?");
$stmt->execute([$id]);

echo json_encode(['success'=>true]);