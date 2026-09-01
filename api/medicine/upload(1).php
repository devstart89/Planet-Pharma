<?php
session_start();
require '../../config/db.php';

header('Content-Type: application/json');

/* ================= AUTH ================= */
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

/* ================= FILE CHECK ================= */
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'File upload failed']);
    exit;
}

$file = fopen($_FILES['file']['tmp_name'], 'r');

if (!$file) {
    echo json_encode(['success' => false, 'error' => 'Invalid file']);
    exit;
}

/* ================= READ HEADER ================= */
$header = fgetcsv($file);

$expectedHeader = [
    'generic_name',
    'brand_name',
    'dosage',
    'frequency',
    'duration',
    'description',
    'status'
];

/* OPTIONAL: validate header */
if ($header !== $expectedHeader) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid CSV format. Please use the template file.'
    ]);
    exit;
}

/* ================= INIT ================= */
$inserted = 0;
$skipped  = 0;
$errors   = [];

/* ================= PROCESS ROWS ================= */
while (($row = fgetcsv($file)) !== false) {

    try {

        if (count($row) < 7) {
            $skipped++;
            continue;
        }

        [
            $generic_name,
            $brand_name,
            $dosage,
            $frequency,
            $duration,
            $description,
            $status
        ] = $row;

        /* ================= VALIDATION ================= */
        if (empty($generic_name)) {
            $skipped++;
            $errors[] = "Missing generic name";
            continue;
        }

        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'inactive'])) {
            $status = 'active';
        }

        /* ================= DUPLICATE CHECK ================= */
        $check = $conn->prepare("
            SELECT COUNT(*) FROM medicines
            WHERE generic_name = ? AND brand_name = ?
        ");
        $check->execute([$generic_name, $brand_name]);

        if ($check->fetchColumn() > 0) {
            $skipped++;
            continue;
        }

        /* ================= INSERT ================= */
        $stmt = $conn->prepare("
            INSERT INTO medicines (
                generic_name,
                brand_name,
                dosage,
                frequency,
                duration,
                description,
                status
            ) VALUES (?,?,?,?,?,?,?)
        ");

        $stmt->execute([
            $generic_name,
            $brand_name ?: null,
            $dosage ?: null,
            $frequency ?: null,
            $duration ?: null,
            $description ?: null,
            $status
        ]);

        $inserted++;

    } catch (Throwable $e) {
        $skipped++;
        $errors[] = $e->getMessage();
    }
}

fclose($file);

/* ================= RESPONSE ================= */
echo json_encode([
    'success'  => true,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'message'  => "$inserted inserted, $skipped skipped",
    'errors'   => $errors
]);