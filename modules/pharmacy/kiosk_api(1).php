<?php
/*
 * ================= KIOSK API =================
 * JSON endpoint backing every in-session kiosk transition (category
 * selection, Rx lookup, walk-in creation) so kiosk.php never has to do
 * a real page navigation for these — navigations silently drop browser
 * Fullscreen with no way for JS to override that. See kiosk_common.js
 * and kiosk_logic.php for the full explanation.
 *
 * The very first page load (and any bookmarked/QR ?mode=... deep link)
 * still goes through kiosk.php's normal server render; everything
 * AFTER that first load runs through here instead, via fetch().
 *
 * Public/no-auth, same as kiosk.php itself — this is the patient-facing
 * kiosk, not a staff page.
 */
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';
require '../kiosk_logic.php';

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

$branchSlug = $_POST['branch'] ?? $_GET['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    echo json_encode(['status' => 'error', 'message' => 'Unknown branch.']);
    exit;
}
$pharmacyId = (int) $pharmacy['id'];

$action = $_POST['action'] ?? '';

if ($action === 'walkin_create') {
    $category = ($_POST['category'] ?? '') === 'Priority' ? 'Priority' : 'Regular';
    $walkInName = trim($_POST['walk_in_name'] ?? '');

    $row = kioskCreateQueueEntry($conn, $pharmacyId, $category, 'Walk-in', null, $walkInName !== '' ? $walkInName : null);
    echo json_encode(['status' => 'ticket', 'ticket' => kioskTicketPayload($pharmacy, $row)]);
    exit;
}

if ($action === 'epres_lookup') {
    $rxNo = $_POST['rx_no'] ?? '';
    $categoryChoice = $_POST['category'] ?? null;

    $result = kioskValidateRx($conn, $pharmacyId, $rxNo);
    if (!$result['ok']) {
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        exit;
    }
    $prescription = $result['prescription'];

    $existing = kioskExistingQueueForPrescription($conn, $pharmacyId, (int) $prescription['id']);
    if ($existing) {
        echo json_encode(['status' => 'ticket', 'ticket' => kioskTicketPayload($pharmacy, $existing, $prescription['prescription_number'])]);
        exit;
    }

    if ($categoryChoice === 'Regular' || $categoryChoice === 'Priority') {
        $row = kioskCreateQueueEntry($conn, $pharmacyId, $categoryChoice, 'E-Pres', (int) $prescription['id']);
        echo json_encode(['status' => 'ticket', 'ticket' => kioskTicketPayload($pharmacy, $row, $prescription['prescription_number'])]);
        exit;
    }

    $patientName = trim(($prescription['first_name'] ?? '') . ' ' . ($prescription['last_name'] ?? ''));
    echo json_encode([
        'status' => 'category_needed',
        'rx_no' => $prescription['prescription_number'],
        'patient_name' => $patientName,
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);