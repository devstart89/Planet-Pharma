<?php
/*
 * ================= KIOSK API =================
 * JSON endpoint backing every in-session kiosk transition (category
 * selection, Rx lookup, walk-in creation, cancellation) so kiosk.php
 * never has to do a real page navigation for these — navigations
 * silently drop browser Fullscreen with no way for JS to override
 * that. See kiosk_common.js and kiosk_logic.php for the full
 * explanation.
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

/*
 * Cancels a still-'Waiting' queue entry before it's ever printed —
 * fired from kiosk.php's "Yes, Cancel" confirmation. See
 * kioskCancelQueueEntry() in kiosk_logic.php for exactly what this
 * does (queues.status -> Cancelled, prescriptions.medicine_status ->
 * Cancelled when applicable, queue_status_log audit row) and why it's
 * scoped to 'Waiting' rows only.
 */
if ($action === 'cancel_ticket') {
    // Logged unconditionally, before anything else, so even a request
    // that never makes it into kioskCancelQueueEntry() (bad branch,
    // missing queue_id, etc.) still leaves a trace in the server error
    // log -- checkable from any computer, no kiosk DevTools needed.
    kioskDebugLog('[kiosk cancel] cancel_ticket request received: branch=' . $branchSlug . ' raw_queue_id=' . ($_POST['queue_id'] ?? '(missing)'));

    $queueId = (int) ($_POST['queue_id'] ?? 0);
    if ($queueId <= 0) {
        kioskDebugLog('[kiosk cancel] rejected: queue_id missing or not a positive integer.');
        echo json_encode(['status' => 'error', 'message' => 'Missing queue_id.']);
        exit;
    }

    try {
        $ok = kioskCancelQueueEntry($conn, $pharmacyId, $queueId);
    } catch (\Throwable $e) {
        // Catches anything kioskCancelQueueEntry() itself didn't
        // already catch (e.g. a fatal type error) so this endpoint
        // always returns valid JSON instead of a blank/broken response
        // that the kiosk's fetch().json() call would fail to parse.
        kioskDebugLog('[kiosk cancel] UNCAUGHT exception in kiosk_api.php: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Server error while cancelling.']);
        exit;
    }

    if (!$ok) {
        echo json_encode(['status' => 'error', 'message' => 'Queue entry not found, already printed, or already in progress.']);
        exit;
    }

    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);