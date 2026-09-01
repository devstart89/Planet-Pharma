<?php

session_start();
require '../../config/db.php';

header('Content-Type: application/json');

/* ============================================================
 | RESPONSE
 * ============================================================ */

function jsonResponse(array $response, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($response);
    exit;
}

/* ============================================================
 | AUTHENTICATION
 * ============================================================ */

function requireFacilityAuth(): array
{
    if (
        !isset($_SESSION['user']) ||
        $_SESSION['user']['role'] !== 'health_facility'
    ) {
        jsonResponse([
            'status'  => 'error',
            'message' => 'Unauthorized access.'
        ], 401);
    }

    return $_SESSION['user'];
}

/* ============================================================
 | HELPERS
 * ============================================================ */

function getFacilityName(PDO $conn, int $facilityId): string
{
    $stmt = $conn->prepare("
        SELECT facility_name
        FROM health_facilities
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$facilityId]);

    return $stmt->fetchColumn() ?: '';
}

function getGeneratedBy(PDO $conn, int $userId): string
{
    $stmt = $conn->prepare("
        SELECT CONCAT(first_name,' ',last_name)
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    return $stmt->fetchColumn() ?: 'Unknown User';
}

/* ============================================================
 | LIST TRANSMITTALS
 * ============================================================ */

function listTransmittals(PDO $conn, int $facilityId): void
{
    $stmt = $conn->prepare("
        SELECT
            id,
            date_generated,
            prescription_date,
            delivery_date,
            num_patients,
            status,
            pharmacist,
            generated_by,
            transmitted
        FROM transmittals
        WHERE facility_id = ?
        ORDER BY id DESC
    ");

    $stmt->execute([$facilityId]);

    jsonResponse([
        'status' => 'success',
        'data'   => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

/* ============================================================
 | GET PRESCRIPTIONS
 * ============================================================ */

function getPrescriptions(PDO $conn, int $facilityId): void
{
    $prescriptionDate = trim($_GET['pres_date'] ?? '');
    $status           = trim($_GET['status'] ?? 'Signed');

    if (empty($prescriptionDate)) {
        jsonResponse([
            'status'  => 'error',
            'message' => 'Prescription date is required.'
        ], 422);
    }

    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.prescription_number,
            p.diagnosis,
            CONCAT(pt.first_name,' ',pt.last_name) AS patient_name
        FROM prescriptions p
        INNER JOIN patients pt
            ON pt.id = p.patient_id
        WHERE
            p.facility_id = ?
            AND pt.facility_id = ?
            AND DATE(p.created_at) = ?
            AND p.status = ?
            AND p.transmittal_id IS NULL
        ORDER BY p.id DESC
    ");

    $stmt->execute([
        $facilityId,
        $facilityId,
        $prescriptionDate,
        $status
    ]);

    jsonResponse([
        'status' => 'success',
        'data'   => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

/* ============================================================
 | GENERATE TRANSMITTAL
 * ============================================================ */

function generateTransmittal(
    PDO $conn,
    int $facilityId,
    string $generatedBy
): void {

    $transmittalDate = trim($_POST['trans_date'] ?? '');
    $prescriptionDate = trim($_POST['pres_date'] ?? '');
    $deliveryDate = !empty($_POST['delivery_date'])
        ? $_POST['delivery_date']
        : null;

    $pharmacy = trim($_POST['pharmacy'] ?? '');
    $prescriptions = $_POST['prescriptions'] ?? [];

    if (
        empty($transmittalDate) ||
        empty($prescriptionDate) ||
        empty($pharmacy) ||
        empty($prescriptions)
    ) {
        jsonResponse([
            'status'  => 'error',
            'message' => 'Please complete all required fields.'
        ], 422);
    }

    try {

        $conn->beginTransaction();

        /* ---------- INSERT TRANSMITTAL ---------- */

        $stmt = $conn->prepare("
            INSERT INTO transmittals
            (
                date_generated,
                prescription_date,
                delivery_date,
                health_facility,
                pharmacist,
                num_patients,
                generated_by,
                facility_id,
                status
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $transmittalDate,
            $prescriptionDate,
            $deliveryDate,
            getFacilityName($conn, $facilityId),
            $pharmacy,
            count($prescriptions),
            $generatedBy,
            $facilityId,
            'Signed'
        ]);

        $transmittalId = $conn->lastInsertId();

        /* ---------- PREPARE STATEMENTS ---------- */

        $insertPrescription = $conn->prepare("
            INSERT INTO transmittal_prescriptions
            (
                transmittal_id,
                prescription_id
            )
            VALUES (?, ?)
        ");

        $updatePrescription = $conn->prepare("
            UPDATE prescriptions
            SET
                transmittal_id = ?,
                transmitted_at = NOW()
            WHERE id = ?
        ");

        foreach ($prescriptions as $prescriptionId) {

            $insertPrescription->execute([
                $transmittalId,
                $prescriptionId
            ]);

            $updatePrescription->execute([
                $transmittalId,
                $prescriptionId
            ]);
        }

        $conn->commit();

        jsonResponse([
            'status'          => 'success',
            'message'         => 'Transmittal generated successfully.',
            'transmittal_id'  => $transmittalId
        ]);

    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        jsonResponse([
            'status'  => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
}

/* ============================================================
 | INITIALIZE
 * ============================================================ */

$user = requireFacilityAuth();

$facilityId = (int) $user['facility_id'];

$generatedBy = getGeneratedBy(
    $conn,
    (int) $user['id']
);

$action = $_REQUEST['action'] ?? '';

/* ============================================================
 | ROUTER
 * ============================================================ */

switch ($action) {

    case 'list':
        listTransmittals($conn, $facilityId);
        break;

    case 'get_prescriptions':
        getPrescriptions($conn, $facilityId);
        break;

    case 'generate':
        generateTransmittal(
            $conn,
            $facilityId,
            $generatedBy
        );
        break;

    default:
        jsonResponse([
            'status'  => 'error',
            'message' => 'Invalid action.'
        ], 400);
}