<?php
session_start();
require '../../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$id = $_POST['id'] ?? null;

$genericName = trim($_POST['generic_name'] ?? '');

if ($genericName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Generic Name is required']);
    exit;
}

/*
 * FIX: brand_name was previously read unconditionally
 * ($_POST['brand_name'], no default) even though the Add/Edit Medicine
 * modal (medicine_list.php) has no brand_name field at all. That meant
 * every single save triggered an "Undefined array key" warning — and
 * since PHP prints warnings as stray output before the JSON response,
 * this had the same failure mode as the FPDF "some data has already
 * been output" issue elsewhere in this app: a warning printed ahead of
 * echo json_encode(...) can corrupt the JSON the frontend is expecting,
 * causing $.ajax's dataType:'json' parsing to silently fail.
 *
 * Using ?? null everywhere below means a field simply missing from the
 * submitted form (brand_name today, potentially others in the future)
 * can never trigger this class of bug again.
 */
$data = [
    $genericName,
    $_POST['brand_name'] ?? null,
    $_POST['dosage'] ?? null,
    // FIX: new — Unit of Measure. Requires medicines.uom to exist (see
    // migration_uom.sql); until that migration runs, this will fail on
    // INSERT/UPDATE with an "unknown column" error, caught below and
    // returned as a clear JSON error instead of a broken response.
    $_POST['uom'] ?? null,
    $_POST['frequency'] ?? null,
    $_POST['duration'] ?? null,
    $_POST['description'] ?? null,
    $_POST['status'] ?? 'active',
];

try {

    if ($id) {
        $data[] = $id;
        $stmt = $conn->prepare("
            UPDATE medicines SET
            generic_name=?, brand_name=?, dosage=?, uom=?,
            frequency=?, duration=?, description=?, status=?
            WHERE id=?
        ");
        $stmt->execute($data);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO medicines
            (generic_name, brand_name, dosage, uom, frequency, duration, description, status)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->execute($data);
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // FIX: previously nothing caught a DB failure here at all — an
    // "unknown column" error (e.g. before the uom migration has run)
    // would throw a raw, unhandled exception, which either shows a PHP
    // error page instead of JSON or (depending on error display
    // settings) prints warning text ahead of any response — either way
    // breaking the frontend's dataType:'json' parsing. Now it always
    // returns valid, parseable JSON describing what went wrong.
    error_log('medicine save.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to save medicine. Please try again, or contact an administrator if this continues.'
    ]);
}