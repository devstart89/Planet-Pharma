<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

/*
 * ================= MODULE 2: PUBLIC KIOSK (multi-branch) =================
 * Runs on the drugstore kiosk terminal — intentionally public, no staff
 * login. Two categories per the spec:
 *   - E-Pres Online: patient enters their Prescription Number
 *     (prescriptions.prescription_number, e.g. "RX-20260711-001").
 *   - Walk-in: no prescription lookup, patient picks Regular or Priority.
 * Both paths end in the same place: a row in `queues` and a printable
 * slip with Queue Number, Category, Date/Time, and Rx No. (if applicable).
 *
 * MULTI-PHARMACY: each physical kiosk terminal is bookmarked with its
 * own URL, e.g. kiosk.php?branch=downtown. Every query on this page is
 * scoped to that resolved pharmacy_id — queue numbering, E-Pres lookups,
 * and the inserted queue row all stay inside that one branch.
 */

$branchSlug = $_GET['branch'] ?? $_POST['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    renderBranchPicker($conn, 'kiosk.php');
}
$pharmacyId = (int) $pharmacy['id'];

// Every form/link on this page must keep the branch attached.
function withBranch(string $url, string $slug): string {
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . 'branch=' . urlencode($slug);
}

function nextQueueNumber(PDO $conn, int $pharmacyId, string $category): int {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM queues
        WHERE pharmacy_id = ? AND category = ? AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$pharmacyId, $category]);
    return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) + 1;
}

function queueLabel(string $category, int $number): string {
    $prefix = $category === 'Priority' ? 'P' : 'R';
    return $prefix . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
}

$mode = $_GET['mode'] ?? null;   // 'epres' | 'walkin'
$error = null;
$slip = null; // populated once a queue row is created

/* ---------- E-PRES ONLINE ---------- */
$showCategoryStep = false;
$categoryStepPrescriptionNumber = null;
$categoryStepPatientName = null;

if ($mode === 'epres' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['rx_no'])) {
    // Patients type the prescription_number printed/shown on their
    // e-prescription (e.g. "RX-20260711-001"), not any internal id.
    $rxNo = strtoupper(trim($_POST['rx_no']));
    // Only present on the 2nd submit, after the patient picks a category
    // on the step below — the first submit (just the Rx number) won't
    // have this yet.
    $categoryChoice = $_POST['category'] ?? null;

    if ($rxNo === '') {
        $error = "Please enter a valid Prescription Number.";
    } else {
        // Prescriptions don't carry a pharmacy_id of their own — their
        // branch comes from the health facility that created them
        // (prescriptions.facility_id -> health_facilities.pharmacy_id),
        // which is how routing already works in this system.
        $stmt = $conn->prepare("
            SELECT pr.id, pr.prescription_number, pr.status, pr.transmitted_at, pr.medicine_status,
                   hf.pharmacy_id AS routed_pharmacy_id, pat.first_name, pat.last_name
            FROM prescriptions pr
            LEFT JOIN health_facilities hf ON pr.facility_id = hf.id
            LEFT JOIN patients pat ON pr.patient_id = pat.id
            WHERE pr.prescription_number = ?
        ");
        $stmt->execute([$rxNo]);
        $prescription = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prescription) {
            $error = "No prescription found for No. {$rxNo}.";
        } elseif ((int)($prescription['routed_pharmacy_id'] ?? 0) !== $pharmacyId) {
            // Prevents patients from queueing at the wrong branch for
            // a prescription whose health facility routes elsewhere.
            $error = "This prescription was not transmitted to this pharmacy location. Please check the correct branch.";
        } elseif ($prescription['medicine_status'] === 'Dispensed' || $prescription['status'] === 'Dispensed') {
            // Checked before the "Signed" check below: prescriptions.status
            // can independently reach 'Dispensed' from elsewhere in the app,
            // and that should read as "already done", not "not yet signed".
            $error = "This prescription has already been dispensed.";
        } elseif ($prescription['status'] !== 'Signed' || !$prescription['transmitted_at']) {
            $error = "This prescription is not yet signed and transmitted. Please check with your health facility.";
        } else {
            // Already has an active (not-Completed/Unclaimed) queue entry
            // today at this branch? Reuse it as-is — its category was
            // already decided when it was first created, so there's
            // nothing new to ask.
            $stmt = $conn->prepare("
                SELECT * FROM queues
                WHERE pharmacy_id = ? AND prescription_id = ? AND status NOT IN ('Completed', 'Unclaimed') AND DATE(created_at) = CURDATE()
                LIMIT 1
            ");
            $stmt->execute([$pharmacyId, $prescription['id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $slip = $existing;
                $slip['prescription_number'] = $prescription['prescription_number'];
            } elseif ($categoryChoice === 'Regular' || $categoryChoice === 'Priority') {
                // Step 2 submit — category has been chosen, create the entry.
                $category = $categoryChoice;
                $number = nextQueueNumber($conn, $pharmacyId, $category);
                $stmt = $conn->prepare("
                    INSERT INTO queues (pharmacy_id, prescription_id, source, category, queue_number, status)
                    VALUES (?, ?, 'E-Pres', ?, ?, 'Waiting')
                ");
                $stmt->execute([$pharmacyId, $prescription['id'], $category, $number]);

                $stmt = $conn->prepare("SELECT * FROM queues WHERE id = ?");
                $stmt->execute([$conn->lastInsertId()]);
                $slip = $stmt->fetch(PDO::FETCH_ASSOC);
                $slip['prescription_number'] = $prescription['prescription_number'];
            } else {
                // Step 1 submit — prescription is valid and has no active
                // queue yet, so ask Regular vs Priority before creating
                // anything.
                $showCategoryStep = true;
                $categoryStepPrescriptionNumber = $prescription['prescription_number'];
                $categoryStepPatientName = trim(($prescription['first_name'] ?? '') . ' ' . ($prescription['last_name'] ?? ''));
            }
        }
    }
}

/* ---------- WALK-IN ---------- */
if ($mode === 'walkin' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['category'])) {
    $category = $_POST['category'] === 'Priority' ? 'Priority' : 'Regular';
    $walkInName = trim($_POST['walk_in_name'] ?? '');

    $number = nextQueueNumber($conn, $pharmacyId, $category);
    $stmt = $conn->prepare("
        INSERT INTO queues (pharmacy_id, prescription_id, walk_in_name, source, category, queue_number, status)
        VALUES (?, NULL, ?, 'Walk-in', ?, ?, 'Waiting')
    ");
    $stmt->execute([$pharmacyId, $walkInName ?: null, $category, $number]);

    $stmt = $conn->prepare("SELECT * FROM queues WHERE id = ?");
    $stmt->execute([$conn->lastInsertId()]);
    $slip = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pharmacy['pharmacy_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
    body {
        background: #f2f4f7;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .branch-tag {
        text-align: center;
        color: #667085;
        font-size: .85rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: .75rem;
    }
    .kiosk-card {
        max-width: 480px;
        width: 100%;
        border: none;
        border-radius: 1rem;
        box-shadow: 0 4px 24px rgba(16,24,40,0.08);
    }
    .kiosk-option {
        border: 2px solid #eaecf0;
        border-radius: 0.75rem;
        padding: 1.25rem;
        text-decoration: none;
        display: block;
        color: #1d2939;
        transition: all .15s ease;
    }
    .kiosk-option:hover { border-color: #175cd3; background: #eff8ff; color: #1d2939; }
    .kiosk-option i { font-size: 1.6rem; color: #175cd3; }
    .queue-number { font-size: 4rem; font-weight: 800; letter-spacing: .05em; color: #175cd3; }
    .category-badge {
        display:inline-block; font-weight:700; font-size:.85rem;
        padding:.25rem .6rem; border-radius:.4rem; margin-bottom:.5rem;
    }
    .category-badge.Regular  { background:#eff8ff; color:#175cd3; }
    .category-badge.Priority { background:#fef3f2; color:#b42318; }
    @media print {
        body * { visibility: hidden; }
        #printTicket, #printTicket * { visibility: visible; }
        #printTicket { position: absolute; top: 0; left: 0; width: 100%; }
    }
</style>
</head>
<body>
<div style="width:100%;max-width:480px;">
<div class="branch-tag"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($pharmacy['pharmacy_name']) ?></div>
<div class="card kiosk-card p-4 text-center">

    <?php if ($slip): ?>
        <div id="printTicket">
            <span class="category-badge <?= htmlspecialchars($slip['category']) ?>"><?= htmlspecialchars($slip['category']) ?></span>
            <p class="text-muted mb-1">Your Queue Number</p>
            <div class="queue-number mb-2"><?= htmlspecialchars(queueLabel($slip['category'], (int)$slip['queue_number'])) ?></div>
            <?php if ($slip['prescription_id']): ?>
                <p class="text-muted mb-0">Prescription No. <?= htmlspecialchars($slip['prescription_number']) ?></p>
            <?php endif; ?>
            <p class="text-muted small mb-0"><?= date('M d, Y h:i A') ?></p>
            <p class="text-muted small"><?= htmlspecialchars($pharmacy['pharmacy_name']) ?> — please wait for your number to be called.</p>
        </div>
        <div class="d-grid gap-2 mt-3">
            <button class="btn btn-dark" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Queue Slip
            </button>
            <a href="<?= htmlspecialchars(withBranch('kiosk.php', $branchSlug)) ?>" class="btn btn-outline-secondary">Done</a>
        </div>

    <?php elseif ($showCategoryStep): ?>
        <h4 class="mb-1"><i class="bi bi-prescription2"></i> Prescription Found</h4>
        <p class="text-muted mb-1">
            <strong><?= htmlspecialchars($categoryStepPrescriptionNumber) ?></strong>
            <?= $categoryStepPatientName ? ' — ' . htmlspecialchars($categoryStepPatientName) : '' ?>
        </p>
        <p class="text-muted mb-3">Select your queue category to get your number.</p>
        <form method="post" action="<?= htmlspecialchars(withBranch('kiosk.php?mode=epres', $branchSlug)) ?>" class="d-grid gap-2">
            <input type="hidden" name="rx_no" value="<?= htmlspecialchars($categoryStepPrescriptionNumber) ?>">
            <button type="submit" name="category" value="Regular" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-people"></i> Regular
            </button>
            <button type="submit" name="category" value="Priority" class="btn btn-outline-danger btn-lg">
                <i class="bi bi-star"></i> Priority <span class="small">(Senior / PWD / Pregnant)</span>
            </button>
            <a href="<?= htmlspecialchars(withBranch('kiosk.php?mode=epres', $branchSlug)) ?>" class="btn btn-link btn-sm">Start Over</a>
        </form>

    <?php elseif ($mode === 'epres'): ?>
        <h4 class="mb-3"><i class="bi bi-prescription2"></i> E-Pres Online</h4>
        <p class="text-muted">Enter your Prescription Number to get your queue number.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" action="<?= htmlspecialchars(withBranch('kiosk.php?mode=epres', $branchSlug)) ?>" class="d-grid gap-2">
            <input type="text" name="rx_no" class="form-control form-control-lg text-center text-uppercase"
                   placeholder="e.g. RX-20260711-001" autofocus required>
            <button type="submit" class="btn btn-dark btn-lg">Get Queue Number</button>
            <a href="<?= htmlspecialchars(withBranch('kiosk.php', $branchSlug)) ?>" class="btn btn-link btn-sm">Back</a>
        </form>

    <?php elseif ($mode === 'walkin'): ?>
        <h4 class="mb-3"><i class="bi bi-person-walking"></i> Walk-in</h4>
        <p class="text-muted">Select your queue category.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" action="<?= htmlspecialchars(withBranch('kiosk.php?mode=walkin', $branchSlug)) ?>" class="d-grid gap-2">
            <input type="text" name="walk_in_name" class="form-control text-center mb-2" placeholder="Name (optional)">
            <button type="submit" name="category" value="Regular" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-people"></i> Regular
            </button>
            <button type="submit" name="category" value="Priority" class="btn btn-outline-danger btn-lg">
                <i class="bi bi-star"></i> Priority <span class="small">(Senior / PWD / Pregnant)</span>
            </button>
            <a href="<?= htmlspecialchars(withBranch('kiosk.php', $branchSlug)) ?>" class="btn btn-link btn-sm">Back</a>
        </form>

    <?php else: ?>
        <h4 class="mb-1"><i class="bi bi-prescription2"></i> Pharmacy Kiosk</h4>
        <p class="text-muted mb-4">Please select an option to get your queue number.</p>
        <div class="d-grid gap-3">
            <a href="<?= htmlspecialchars(withBranch('kiosk.php?mode=epres', $branchSlug)) ?>" class="kiosk-option">
                <i class="bi bi-cloud-check d-block mb-2"></i>
                <strong>E-Pres Online</strong>
                <div class="small text-muted">I have a prescription number from the E-Pres app</div>
            </a>
            <a href="<?= htmlspecialchars(withBranch('kiosk.php?mode=walkin', $branchSlug)) ?>" class="kiosk-option">
                <i class="bi bi-person-walking d-block mb-2"></i>
                <strong>Walk-in</strong>
                <div class="small text-muted">My prescription was processed manually</div>
            </a>
        </div>
    <?php endif; ?>

</div>
</div>
</body>
</html>