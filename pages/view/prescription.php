<?php
session_start();
/* ---------- AUTH ---------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility', 'doctor', 'pharmacy'])) {
    header("Location: ../../index.php");
    exit;
}

$facility_id = $_SESSION['user']['facility_id'] ?? null;
$isHealthFacility = $_SESSION['user']['role'] === 'health_facility';
$isDoctor = $_SESSION['user']['role'] === 'doctor';
$isPharmacy = $_SESSION['user']['role'] === 'pharmacy';
$showForbiddenModal = !$isHealthFacility && !$isDoctor && !$isPharmacy;

if ($isHealthFacility) {
    $dashboardUrl = '../health_facility/';
}
if ($isDoctor) {
    $dashboardUrl = '../doctor/';
}
if ($isPharmacy) {
    $dashboardUrl = '../pharmacy/';
}

require '../../config/db.php';
include '../../includes/header.php';

$prescription_id = $_GET['id'] ?? 0;

if (!$prescription_id) {
    echo "<div class='container mt-5 alert alert-danger'>Invalid prescription ID</div>";
    exit;
}

/* FETCH PRESCRIPTION
 * NOTE / ASSUMPTIONS (please verify against your actual schema and adjust
 * the column/join names below if any of these are wrong — everything
 * downstream just reads from the resulting $prescription array):
 *   - health_facilities.facility_name  -> facility display name
 *   - prescriptions.doctor_id          -> FK to users.id for the signing/attending doctor
 *   - users.first_name / users.last_name -> doctor's name (mirrors patients table convention)
 *   - prescriptions.created_at         -> date the prescription was issued/created
 *   - prescriptions.prescription_number and prescriptions.visit_type already
 *     confirmed to exist per prior work on this project.
 */
$stmt = $conn->prepare("
    SELECT p.*, 
           pat.member_card_no,
           pat.first_name, pat.last_name, pat.his_id, 
           pat.gender, pat.birthday,
           pat.house_no_street, pat.barangay,
           pat.contact_number, pat.last_medical_consult,
           hf.facility_name,
           doc.first_name  AS doctor_first_name,
           doc.last_name   AS doctor_last_name
    FROM prescriptions p
    JOIN patients pat ON p.patient_id = pat.id
    LEFT JOIN health_facilities hf ON p.facility_id = hf.id
    LEFT JOIN users doc ON p.doctor_id = doc.id
    WHERE p.id = ?
");
$stmt->execute([$prescription_id]);
$prescription = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prescription) {
    echo "<div class='container mt-5 alert alert-danger'>Prescription not found</div>";
    exit;
}

/*
 * Pharmacy is not scoped to a single facility (they can view/dispense
 * across all facilities), but health_facility/doctor stay locked to
 * their own facility's records only.
 */
if (!$isPharmacy && $facility_id !== null && (int)$prescription['facility_id'] !== (int)$facility_id) {
    echo "<div class='container mt-5 alert alert-danger'>You are not authorized to view this prescription.</div>";
    exit;
}

/* Pharmacy can only view SIGNED prescriptions — nothing to dispense against otherwise */
if ($isPharmacy && $prescription['status'] !== 'Signed') {
    echo "<div class='container mt-5 alert alert-warning'>This prescription is not yet signed and is not available for pharmacy view.</div>";
    exit;
}

date_default_timezone_set('Asia/Manila');

$lastConsultDisplay = !empty($prescription['last_medical_consult'])
    ? (new DateTime($prescription['last_medical_consult']))->format('M j, Y g:i A')
    : 'No consultation yet';

$dateIssuedDisplay = !empty($prescription['created_at'])
    ? (new DateTime($prescription['created_at']))->format('M j, Y g:i A')
    : '—';

$facilityDisplay = $prescription['facility_name'] ?? '—';

$doctorName = trim(($prescription['doctor_first_name'] ?? '') . ' ' . ($prescription['doctor_last_name'] ?? ''));
$doctorDisplay = $doctorName !== '' ? $doctorName : 'Not yet assigned';

$visitType = $prescription['visit_type'] ?? 'PRESCRIPTION';
$isConsultOnly = strtoupper($visitType) === 'CONSULTATION';

// Whether this prescription (and its medicines) is a refill of a prior
// prescription vs. a new one. The controller (prescriptions_proces.php)
// stores this as uppercase 'YES'/'NO' via strtoupper(), so it must be
// normalized the same way here before comparing — comparing against a
// literal 'No' (title case) would never match and always show as a
// refill regardless of what was actually saved. Falls back to 'YES'
// for older records that predate the for_refill column.
$forRefillNormalized = strtoupper(trim($prescription['for_refill'] ?? 'YES'));
if (!in_array($forRefillNormalized, ['YES', 'NO'])) {
    $forRefillNormalized = 'YES';
}
$isRefillRx = ($forRefillNormalized !== 'NO');

$rxNumberDisplay = $prescription['prescription_number'] ?? '—';

/* FETCH MEDICINES
 * FIX (Qty. Dispensed) — CONFIRMED against prescription_action.php:
 * there is no separate queuing-transactions table. prescription_action.php
 * writes the dispensed amount directly onto
 * prescription_medicines.dispensed_quantity the moment pharmacy staff
 * complete the queue entry (action=complete) — one write per medicine,
 * not an accumulating log of multiple transactions. So this is just a
 * plain column read via SELECT * — no join/subquery/guessed schema
 * needed at all. (Previous version of this file guessed a
 * `queuing_transactions` table that doesn't exist; removed.)
 */
$medStmt = $conn->prepare("SELECT * FROM prescription_medicines WHERE prescription_id = ?");
$medStmt->execute([$prescription_id]);
$medicines = $medStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- FIELD-LEVEL EDIT PERMISSIONS ---------- */
$isEditable = !in_array($prescription['status'], ['Signed', 'Denied']);
// Diagnosis/Remarks may only be edited by health_facility or doctor while the Rx is still open.
// Pharmacy is always view-only for these fields, regardless of status.
$canEditPrescriptionInfo = ($isHealthFacility || $isDoctor) && $isEditable;
$canManageMedicines = $isDoctor && $isEditable;
$showMedicineStatusColumn = $prescription['status'] === 'Signed';

// Seed data for the JS medicine table (same shape the Create Prescription
// page uses), so both pages share one rendering/edit model.
$medicinesForJs = array_map(function ($med) {
    return [
        'existingId'    => $med['id'],                  // prescription_medicines row PK
        'medicineId'    => '',                           // master medicines.id — unknown for already-saved rows (name isn't re-searchable)
        'name'          => $med['medicine_name'],
        'dosage'        => $med['dosage'],
        // Assumes prescription_medicines has its own `uom` column (this
        // uses SELECT *, so it'll be present automatically if that
        // column exists on your schema — defaults to '' if not).
        'uom'           => $med['uom'] ?? '',
        'freq'          => $med['frequency'],
        'duration'      => $med['duration'],
        'qty'           => $med['quantity'],
        'notes'         => $med['notes'],
        'pharmacyStatus'=> $med['pharmacy_status'] ?? 'Pending',
        // Reason recorded by pharmacy when a medicine is Partial or Not Dispensed.
        'reason'        => $med['pharmacy_reason'] ?? null,
        // Qty. Dispensed — the real column written by prescription_action.php
        // when pharmacy completes the queue entry. null until dispensed
        // (still Pending), in which case the UI shows an em-dash.
        'qtyDispensed'  => $med['dispensed_quantity'] ?? null,
    ];
}, $medicines);

/* ---------- DOCTOR SIGNATURE (pre-filled, read-only signing) ----------
 * ASSUMPTION (unconfirmed — adjust if wrong): signatures are stored in
 * `users.signature`, as either a file path or a base64 string, mirroring
 * the existing signature-resolution logic used for PDF generation.
 * If your actual schema differs (e.g. a separate doctor_signatures table
 * or a different column name), just change the query in this block —
 * everything downstream only cares that $doctorSignatureDataUri ends up
 * as either a full `data:image/...;base64,...` URI or an empty string.
 */
function resolveSignatureDataUri(?string $raw): string {
    if (!$raw) {
        return '';
    }
    // Already a data URI
    if (str_starts_with($raw, 'data:image')) {
        return $raw;
    }
    // Looks like a raw base64 blob (long, base64-alphabet-only string)
    if (strlen($raw) > 100 && preg_match('/^[A-Za-z0-9+\/=]+$/', $raw)) {
        return 'data:image/png;base64,' . $raw;
    }
    // Otherwise treat it as a file path
    $path = $raw;
    if (!is_file($path)) {
        // Common fallback location — adjust to match where signature files actually live
        $candidate = __DIR__ . '/../../uploads/signatures/' . basename($raw);
        if (is_file($candidate)) {
            $path = $candidate;
        }
    }
    if (is_file($path)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/png',
        };
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }
    return '';
}

$doctorSignatureDataUri = '';
if ($isDoctor) {
    $sigStmt = $conn->prepare("SELECT signature_path FROM users WHERE id = ?");
    $sigStmt->execute([$_SESSION['user']['id']]);
    $doctorSignatureDataUri = resolveSignatureDataUri($sigStmt->fetchColumn() ?: null);
}
$doctorHasSignatureOnFile = $doctorSignatureDataUri !== '';
?>
<style>
    :root {
        --rx-border: #e6e8eb;
        --rx-muted: #667085;
        --rx-muted-light: #98a2b3;
        --rx-accent: #2563eb;
        --rx-accent-light: #eff4ff;
    }

    .rx-header-bar h4 { font-weight: 700; color: #1d2939; margin-bottom: 0; }

    /* ===== NEW: Prescription summary banner ===== */
    .rx-summary-banner {
        background: linear-gradient(135deg, var(--rx-accent-light) 0%, #fff 65%);
        border: 1px solid var(--rx-border);
        border-radius: 0.9rem;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.25rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .rx-summary-top {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        margin-bottom: .9rem;
    }
    .rx-summary-number {
        font-size: 1.25rem;
        font-weight: 800;
        color: #1d2939;
        letter-spacing: .01em;
        display: flex;
        align-items: center;
        gap: .5rem;
    }
    .rx-visit-badge {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: .3rem .65rem;
        border-radius: 50rem;
    }
    .rx-visit-badge.consult { background: #e0f2fe; color: #0369a1; }
    .rx-visit-badge.rx { background: #ecfdf5; color: #047857; }
    .rx-visit-badge.refill { background: #eff8ff; color: #175cd3; }
    .rx-visit-badge.new { background: #f2f4f7; color: #344054; }

    .rx-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
    }
    .rx-summary-item {
        display: flex;
        flex-direction: column;
        gap: .2rem;
    }
    .rx-summary-item .label {
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--rx-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .rx-summary-item .value {
        font-size: 0.92rem;
        font-weight: 600;
        color: #1d2939;
        display: flex;
        align-items: center;
        gap: .4rem;
    }
    .rx-summary-item .value i { color: var(--rx-accent); }

    /* Patient / info card */
    .rx-patient-card {
        background: #fff;
        border: 1px solid var(--rx-border);
        border-radius: 0.85rem;
        padding: 1.5rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .rx-patient-name {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1d2939;
    }
    .rx-section-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--rx-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin: 1rem 0 .6rem;
        display: block;
    }
    .rx-demo-grid p {
        margin-bottom: .5rem;
        font-size: 0.88rem;
        color: #344054;
    }
    .rx-demo-grid strong {
        color: #1d2939;
        font-weight: 600;
    }
    .rx-field-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--rx-muted);
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: .3rem;
        display: block;
    }
    .rx-status-row {
        display: flex;
        align-items: center;
        gap: .5rem;
        margin-top: 1rem;
    }
    .rx-actions {
        margin-top: .85rem;
        padding-top: .85rem;
        border-top: 1px solid var(--rx-border);
    }

    /* Medicines panel — now table-based, matching Create Prescription */
    .rx-medicines-card {
        background: #fff;
        border: 1px solid var(--rx-border);
        border-radius: 0.85rem;
        padding: 1.5rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .rx-medicines-card h5 {
        font-weight: 700;
        color: #1d2939;
        margin-bottom: 1rem;
    }
    #medicinesTable thead th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: var(--rx-muted);
        font-weight: 700;
        background: #f9fafb;
        white-space: nowrap;
    }
    #medicinesTable tbody td {
        vertical-align: middle;
        font-size: 0.88rem;
    }
    #medicinesTable tbody tr:hover { background-color: #f9fafb; }

    .rx-empty-meds {
        text-align: center;
        color: var(--rx-muted-light);
        padding: 2.5rem 1rem;
    }
    .rx-empty-meds i { font-size: 1.75rem; display: block; margin-bottom: .5rem; }

    /* Footer actions — pinned bottom-right */
    .rx-footer-actions {
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid var(--rx-border);
        display: flex;
        justify-content: flex-end;
        gap: .5rem;
    }

    /* ===== Modal redesign (shared visual language with Create Prescription) ===== */
    .modal-content {
        border: 1px solid var(--rx-border);
        border-radius: 0.9rem;
        box-shadow: 0 8px 24px rgba(16, 24, 40, 0.10);
        overflow: hidden;
    }
    .modal-header { border-bottom: 1px solid #eaecf0; padding: 1.1rem 1.5rem; }
    .modal-header .modal-title {
        font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: .5rem; margin: 0;
    }
    .modal-body { padding: 1.5rem; }
    .modal-footer { border-top: 1px solid #eaecf0; padding: 1rem 1.5rem; }
    .form-label-sm {
        font-size: 0.75rem; font-weight: 600; color: var(--rx-muted);
        text-transform: uppercase; letter-spacing: .02em; margin-bottom: .3rem; display: block;
    }

    /* jQuery UI autocomplete vs Bootstrap modal z-index fix (same issue/fix as Create Prescription) */
    .ui-autocomplete { z-index: 2000 !important; }

    /* Signature preview (read-only — no more drawing pad) */
    .rx-signature-preview {
        width: 100%;
        max-width: 420px;
        min-height: 160px;
        border: 1px dashed var(--rx-border);
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f9fafb;
        margin: 0 auto;
        padding: 0.75rem;
    }
    .rx-signature-preview img {
        max-width: 100%;
        max-height: 150px;
    }
    .rx-signature-missing {
        color: var(--rx-muted);
        font-size: 0.85rem;
        text-align: center;
        padding: 0 1rem;
    }

    @media (max-width: 767.98px) {
        .rx-patient-card, .rx-medicines-card { padding: 1.1rem; }
        #medicinesContainer { height: auto !important; max-height: 420px; }
        .rx-summary-banner { padding: 1rem; }
        .rx-summary-number { font-size: 1.05rem; }
    }
</style>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<!-- Page Title -->
    <div class="page-title">
      <nav class="breadcrumbs">
        <div class="container">
          <ol>
            <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
            <li><a href="../list/prescription.php">Prescription List</a></li>
            <li class="current">Prescription Details</li>
          </ol>
        </div>
      </nav>
    </div><!-- End Page Title -->
    <section id="doctors" class="doctors section">
        <div class="container" data-aos="fade-up" data-aos-delay="100">

            <!-- ===== NEW: Full prescription details summary banner ===== -->
            <div class="rx-summary-banner" data-aos="fade-up">
                <div class="rx-summary-top">
                    <div class="rx-summary-number">
                        <i class="bi bi-file-earmark-medical"></i>
                        <?= htmlspecialchars($rxNumberDisplay) ?>
                        <span class="rx-visit-badge <?= $isConsultOnly ? 'consult' : 'rx' ?>">
                            <?= $isConsultOnly ? 'Consultation Only' : 'Prescription' ?>
                        </span>
                        <span class="rx-visit-badge <?= $isRefillRx ? 'refill' : 'new' ?>">
                            <i class="bi <?= $isRefillRx ? 'bi-arrow-repeat' : 'bi-clipboard2-pulse' ?> me-1"></i><?= $isRefillRx ? 'Refill' : 'New' ?>
                        </span>
                    </div>

                    <?php if ($prescription['status'] === 'Signed' ): ?>
                        <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Signed</span>
                    <?php elseif ($prescription['status'] === 'Denied'): ?>
                        <span class="badge bg-danger"><i class="bi bi-x-circle-fill me-1"></i>Denied</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i><?= htmlspecialchars($prescription['status']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="rx-summary-grid">
                    <div class="rx-summary-item">
                        <span class="label">Health Facility</span>
                        <span class="value"><i class="bi bi-hospital"></i> <?= htmlspecialchars($facilityDisplay) ?></span>
                    </div>
                    <div class="rx-summary-item">
                        <span class="label">Attending Doctor</span>
                        <span class="value"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($doctorDisplay) ?></span>
                    </div>
                    <div class="rx-summary-item">
                        <span class="label">Date Issued</span>
                        <span class="value"><i class="bi bi-calendar-event"></i> <?= htmlspecialchars($dateIssuedDisplay) ?></span>
                    </div>
                </div>
            </div>

            <form id="prescriptionForm">
                <input type="hidden" name="prescription_id" value="<?= $prescription_id ?>">

                <div class="row g-3">

                    <!-- LEFT: Patient + Diagnosis (unchanged, read-mostly) -->
                    <div class="col-lg-4" data-aos="fade-right" data-aos-delay="150">
                        <div class="rx-patient-card">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-person-circle fs-4 text-primary"></i>
                                <span class="rx-patient-name"><?= htmlspecialchars($prescription['first_name'].' '.$prescription['last_name']) ?></span>
                            </div>

                            <span class="rx-section-label">Demographic Profile</span>
                            <div class="rx-demo-grid">
                                <p><strong>MCN:</strong> <?= htmlspecialchars($prescription['member_card_no']) ?></p>
                                <p><strong>Gender:</strong> <?= htmlspecialchars($prescription['gender']) ?></p>
                                <p><strong>Birthday:</strong> <?= htmlspecialchars($prescription['birthday']) ?></p>
                                <p><strong>Address:</strong>
                                    <?= htmlspecialchars($prescription['house_no_street']) ?>
                                </p>
                                <p><strong>Contact:</strong> <?= htmlspecialchars($prescription['contact_number']) ?></p>
                                <p class="mb-0">
                                    <strong>Last Medical Consult:</strong>
                                    <span id="last_medical_consult"><?= htmlspecialchars($lastConsultDisplay) ?></span>
                                </p>
                            </div>

                            <span class="rx-section-label">Prescription Info</span>
                            <!-- Fields, but always locked (readonly/disabled) — Prescription
                                 Info is display-only on this page, not editable by anyone,
                                 regardless of role or prescription status. -->
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="rx-field-label">Diagnosis</label>
                                    <input
                                        type="text"
                                        name="diagnosis"
                                        class="form-control form-control-sm"
                                        value="<?= htmlspecialchars($prescription['diagnosis']) ?>"
                                        readonly style="background:#e9ecef;cursor:not-allowed;"
                                    >
                                </div>

                                <!-- For Refill — shown as the same Yes/No radio pair used on
                                     Create Prescription, but disabled so it can't be changed
                                     here. A hidden input carries the value along in case the
                                     form is submitted elsewhere (e.g. Sign), since disabled
                                     radio inputs are not included in form submissions.
                                     Uses $isRefillRx (already normalized against the uppercase
                                     YES/NO the controller actually stores) instead of
                                     re-comparing the raw value here. -->
                                <div class="col-6">
                                    <label class="rx-field-label">For Refill</label>
                                    <div class="d-flex align-items-center gap-3 pt-1">
                                        <div class="form-check form-check-inline mb-0">
                                            <input
                                                class="form-check-input"
                                                type="radio"
                                                id="viewForRefillYes"
                                                value="Yes"
                                                disabled
                                                <?= $isRefillRx ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="viewForRefillYes">Yes</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input
                                                class="form-check-input"
                                                type="radio"
                                                id="viewForRefillNo"
                                                value="No"
                                                disabled
                                                <?= !$isRefillRx ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="viewForRefillNo">No</label>
                                        </div>
                                    </div>
                                    <input type="hidden" name="for_refill" value="<?= $isRefillRx ? 'Yes' : 'No' ?>">
                                </div>

                                <!-- Remarks — 4-row textarea (matches Create Prescription's
                                     field size), locked read-only. -->
                                <div class="col-12">
                                    <label class="rx-field-label">Remarks</label>
                                    <textarea
                                        name="remarks"
                                        class="form-control form-control-sm"
                                        rows="4"
                                        readonly style="background:#e9ecef;cursor:not-allowed;"
                                    ><?= htmlspecialchars($prescription['remarks']) ?></textarea>
                                </div>
                            </div>

                           <!-- <div class="rx-status-row">
                                <span class="rx-field-label mb-0">Status:</span>
                                <?php if ($prescription['status'] === 'Signed' ): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i><?= $prescription['status'] ?></span>
                                <?php elseif ($prescription['status'] === 'Denied'): ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle-fill me-1"></i><?= $prescription['status'] ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i><?= $prescription['status'] ?></span>
                                <?php endif; ?>
                            </div>-->

                            <div class="rx-actions d-flex gap-2">
                                <a href="../../controller/prescription/generate_prescription_pdf.php?id=<?= $prescription_id ?>"
                                   target="_blank"
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-file-earmark-pdf"></i> View PDF
                                </a>

                                <?php if ($prescription['status'] === 'Signed'): ?>
                                    <button type="button" id="printPrescriptionBtn" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-printer"></i> Print
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled
                                        title="Printing is only available once the doctor has signed the prescription">
                                        <i class="bi bi-printer"></i> Print
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Medicines — table + modal, same pattern as Create Prescription -->
                    <div class="col-lg-8" data-aos="fade-left" data-aos-delay="200">
                        <div class="rx-medicines-card">

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><i class="bi bi-capsule me-2"></i>Medicines</h5>
                                <?php if ($canManageMedicines): ?>
                                    <button type="button" id="addMedicineBtn" class="btn btn-sm btn-primary">
                                        <i class="bi bi-plus-lg"></i> Add Medicine
                                    </button>
                                <?php endif; ?>
                            </div>

                            <div id="medicinesContainer" style="height:450px; overflow:auto;">
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0" id="medicinesTable">
                                        <thead>
                                            <tr>
                                                <th>Medicine</th>
                                                <th>Dosage</th>
                                                <th>UOM</th>
                                                <th>Signa</th>
                                                <th>Duration</th>
                                                <!--
                                                    FIX: relabeled from plain "Qty" to make clear
                                                    this is the doctor-ordered quantity, distinct
                                                    from the new dispensed-quantity column below.
                                                -->
                                                <th>QTY (Doctor)</th>
                                                <?php if ($showMedicineStatusColumn): ?>
                                                    <!--
                                                        FIX: new column — quantity actually dispensed
                                                        by Planet. Reads prescription_medicines.dispensed_quantity
                                                        directly, written by prescription_action.php the
                                                        moment pharmacy completes the queue entry. Gated
                                                        behind the same "Signed" condition as Medicine
                                                        Status, since dispensing can't happen before that.
                                                    -->
                                                    <th width="130">QTY DISPENSED (Planet)</th>
                                                <?php endif; ?>
                                                <th>Notes</th>
                                                <?php if ($showMedicineStatusColumn): ?><th width="150">Medicine Status</th><?php endif; ?>
                                                <?php if ($canManageMedicines): ?><th width="100">Action</th><?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody id="medicinesTableBody">
                                            <tr id="medicinesEmptyRow">
                                                <td colspan="<?= 7 + ($showMedicineStatusColumn ? 2 : 0) + ($canManageMedicines ? 1 : 0) ?>" class="rx-empty-meds">
                                                    <i class="bi bi-capsule"></i>
                                                    No medicines added yet.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Sign / Deny actions — bottom-right -->
                    <?php if ($isDoctor && $isEditable): ?>
                        <div class="col-12">
                            <div class="rx-footer-actions">
                                <button type="button" class="btn btn-success btn-sm" id="openSignatureModal">
                                    <i class="bi bi-pen"></i> Sign & Submit
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm deny-btn" data-id="<?= $prescription['id'] ?>">
                                    <i class="bi bi-x-circle"></i> Deny
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </form>
        </div>
    </section>

    <!-- ADD / EDIT MEDICINE MODAL (same as Create Prescription) -->
    <div class="modal fade" id="medicineModal">
        <div class="modal-dialog">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="medicineModalTitle"><i class="bi bi-capsule text-primary"></i> Add Medicine</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" id="medModalRowId">
                    <input type="hidden" id="medModalExistingId">
                    <input type="hidden" id="medModalMedicineId">

                    <div class="mb-3">
                        <label class="form-label-sm">Medicine <span class="text-danger">*</span></label>
                        <input type="text" id="medModalName" class="form-control" autocomplete="off" required>
                        <small class="text-danger d-none" id="medModalNameError">Please select a medicine from the list.</small>
                        <small class="text-muted d-none" id="medModalNameLocked">This medicine was already prescribed and can't be renamed — remove it and add a new one instead if needed.</small>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <!-- FIX (Item 3): Dosage restricted to numeric values only — see input handler below -->
                            <label class="form-label-sm">Dosage <span class="text-danger">*</span></label>
                            <input type="text" id="medModalDosage" class="form-control" inputmode="decimal" required>
                        </div>
                        <div class="col-md-2">
                            <!-- FIX (Item 2): Unit of Measure, auto-filled on selection — see autocomplete handler below -->
                            <label class="form-label-sm">UOM</label>
                            <input type="text" id="medModalUom" class="form-control" readonly tabindex="-1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">Signa <span class="text-danger">*</span></label>
                            <input type="text" id="medModalFreq" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">Duration (days)</label>
                            <input type="number" min="1" step="1" id="medModalDuration" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">Quantity <span class="text-danger">*</span></label>
                            <input type="number" min="0" step="1" id="medModalQty" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label-sm">Notes</label>
                            <input type="text" id="medModalNotes" class="form-control">
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="medModalSaveBtn" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg"></i> Save Medicine
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- REASON MODAL — shows the pharmacy-recorded reason for a Partial or Unclaimed medicine -->
    <div class="modal fade" id="reasonModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="reasonModalTitle"><i class="bi bi-info-circle me-2"></i>Reason</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <span class="rx-field-label" id="reasonModalMedicineName">Medicine</span>
                    <p class="mb-0" id="reasonModalText" style="white-space:pre-wrap;"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SIGNATURE CONFIRM MODAL — no more drawing pad. Shows the doctor's
         signature already on file (read-only) and just confirms submission. -->
    <div class="modal fade" id="signatureModal" tabindex="-1">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-pen me-2"></i>Confirm Signature</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <?php if ($doctorHasSignatureOnFile): ?>
                    <p class="text-muted small mb-3">This is your signature on file. Confirm to sign and submit this prescription.</p>
                    <div class="rx-signature-preview">
                        <img src="<?= htmlspecialchars($doctorSignatureDataUri) ?>" alt="Doctor signature on file">
                    </div>
                <?php else: ?>
                    <div class="rx-signature-preview">
                        <p class="rx-signature-missing">
                            <i class="bi bi-exclamation-triangle text-warning d-block mb-1" style="font-size:1.5rem;"></i>
                            No signature on file for your account.<br>
                            Please add one to your profile before signing prescriptions.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button"
                        class="btn btn-success btn-sm"
                        id="submitWithSignature"
                        <?= $doctorHasSignatureOnFile ? '' : 'disabled' ?>>
                    <i class="bi bi-check2-circle"></i> Confirm & Sign
                </button>
            </div>
            </div>
        </div>
    </div>


<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    const isDoctor = <?= $isDoctor ? 'true' : 'false' ?>;
    const isPharmacy = <?= $isPharmacy ? 'true' : 'false' ?>;
    const canManageMedicines = <?= $canManageMedicines ? 'true' : 'false' ?>;
    const showMedicineStatusColumn = <?= $showMedicineStatusColumn ? 'true' : 'false' ?>;
    // Doctor's signature, resolved server-side to a full data URI (or '' if none on file).
    const doctorSignatureDataUri = <?= json_encode($doctorSignatureDataUri) ?>;

    function escapeHtml(str) {
        return $('<div>').text(str ?? '').html();
    }

    /* =========================================================
       MEDICINE TABLE STATE — same model as Create Prescription:
       one source of truth (medicineRowsData) keyed by rowId, one
       render function, one save handler for both Add and Edit.
    ========================================================= */
    let medicineRowCounter = 0;
    let medicineRowsData = {};

    const medicineModal = new bootstrap.Modal(document.getElementById('medicineModal'));

    function renderMedicineRow(rowId) {
        const d = medicineRowsData[rowId];

        // Medicine status is now always a read-only badge on this page.
        // Pharmacy status changes (Pending/Partial/Dispensed/Not Dispensed)
        // happen through the dedicated dispense workflow, not here.
        // For Partial/Not Dispensed, an info icon opens a modal with the
        // reason pharmacy recorded for not fully dispensing this medicine.
        let statusHtml = '';
        // FIX: Qty Dispensed cell — shows prescription_medicines.dispensed_quantity
        // (written by prescription_action.php on Complete), or an em-dash
        // if the medicine hasn't been dispensed yet (still Pending).
        let qtyDispensedHtml = '';
        if (showMedicineStatusColumn) {
            qtyDispensedHtml = `<td class="text-center">${d.qtyDispensed !== null && d.qtyDispensed !== undefined ? escapeHtml(d.qtyDispensed) : '&mdash;'}</td>`;

            const pillClass = d.pharmacyStatus === 'Dispensed' ? 'bg-success'
                : d.pharmacyStatus === 'Not Dispensed' ? 'bg-danger'
                : d.pharmacyStatus === 'Partial' ? 'bg-info text-dark'
                : 'bg-warning text-dark';

            const needsReason = d.pharmacyStatus === 'Partial' || d.pharmacyStatus === 'Not Dispensed';
            const reasonBtn = needsReason
                ? `<button type="button" class="btn btn-sm btn-link p-0 ms-1 viewReasonBtn" data-row-id="${rowId}" title="View reason">
                       <i class="bi bi-info-circle"></i>
                   </button>`
                : '';

            statusHtml = `
                <td>
                    <div class="medicineStatusWrap" data-medicine-id="${d.existingId}">
                        <span class="badge ${pillClass}">${escapeHtml(d.pharmacyStatus)}</span>${reasonBtn}
                    </div>
                </td>
            `;
        }

        let actionsHtml = '';
        if (canManageMedicines) {
            actionsHtml = `
                <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary editMedicineBtn" data-row-id="${rowId}" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger deleteMedicineBtn" data-row-id="${rowId}" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;
        }

        return `
            <tr class="medicineRow" data-row-id="${rowId}">
                <td>
                    <input type="hidden" name="medicine_id[]" value="${escapeHtml(d.existingId)}">
                    <input type="hidden" class="medicine_db_id" name="medicine_db_id[]" value="${escapeHtml(d.medicineId)}">
                    <input type="hidden" name="medicine_name[]" value="${escapeHtml(d.name)}">
                    ${escapeHtml(d.name)}
                </td>
                <td>
                    <input type="hidden" name="dosage[]" value="${escapeHtml(d.dosage)}">
                    ${escapeHtml(d.dosage) || '&mdash;'}
                </td>
                <td>
                    <input type="hidden" name="uom[]" value="${escapeHtml(d.uom ?? '')}">
                    ${escapeHtml(d.uom ?? '') || '&mdash;'}
                </td>
                <td>
                    <input type="hidden" name="frequency[]" value="${escapeHtml(d.freq)}">
                    ${escapeHtml(d.freq) || '&mdash;'}
                </td>
                <td>
                    <input type="hidden" name="duration[]" value="${d.duration ?? ''}">
                    ${d.duration || '&mdash;'}
                </td>
                <td>
                    <input type="hidden" name="quantity[]" value="${d.qty ?? ''}">
                    ${d.qty ?? '&mdash;'}
                </td>
                ${qtyDispensedHtml}
                <td>
                    <input type="hidden" name="notes[]" value="${escapeHtml(d.notes ?? '')}">
                    ${escapeHtml(d.notes ?? '') || '&mdash;'}
                </td>
                ${statusHtml}
                ${actionsHtml}
            </tr>
        `;
    }

    function renderEmptyStateIfNeeded() {
        if ($('#medicinesTableBody .medicineRow').length === 0) {
            const colCount = 7 + (showMedicineStatusColumn ? 2 : 0) + (canManageMedicines ? 1 : 0);
            $('#medicinesTableBody').html(`
                <tr id="medicinesEmptyRow">
                    <td colspan="${colCount}" class="rx-empty-meds">
                        <i class="bi bi-capsule"></i>
                        No medicines added yet.
                    </td>
                </tr>
            `);
        }
    }

    function addOrReplaceRow(data, rowId = null) {
        if (!rowId) {
            rowId = 'row_' + (++medicineRowCounter);
        }
        medicineRowsData[rowId] = data;

        $('#medicinesEmptyRow').remove();

        const existing = $(`#medicinesTableBody tr.medicineRow[data-row-id="${rowId}"]`);
        if (existing.length) {
            existing.replaceWith(renderMedicineRow(rowId));
        } else {
            $('#medicinesTableBody').append(renderMedicineRow(rowId));
        }
    }

    function openAddMedicineModal() {
        $('#medicineModalTitle').html('<i class="bi bi-capsule text-primary"></i> Add Medicine');
        $('#medModalRowId').val('');
        $('#medModalExistingId').val('');
        $('#medModalMedicineId').val('');
        $('#medModalName').val('').prop('disabled', false).removeClass('is-invalid');
        $('#medModalNameError').addClass('d-none');
        $('#medModalNameLocked').addClass('d-none');
        $('#medModalDosage').val('');
        $('#medModalUom').val('');
        $('#medModalFreq').val('');
        $('#medModalDuration').val('');
        $('#medModalQty').val('');
        $('#medModalNotes').val('');
        medicineModal.show();
    }

    function openEditMedicineModal(rowId) {
        const d = medicineRowsData[rowId];
        if (!d) return;

        const isAlreadySaved = !!d.existingId;

        $('#medicineModalTitle').html('<i class="bi bi-pencil-square text-primary"></i> Edit Medicine');
        $('#medModalRowId').val(rowId);
        $('#medModalExistingId').val(d.existingId ?? '');
        $('#medModalMedicineId').val(d.medicineId ?? '');
        $('#medModalName').val(d.name).removeClass('is-invalid');
        $('#medModalNameError').addClass('d-none');

        // A medicine already saved to this prescription can't be renamed via
        // autocomplete — only its dosage/signa/duration/qty/notes are editable.
        // A row that was just added in this same session (not yet saved) can
        // still have its name changed.
        $('#medModalName').prop('disabled', isAlreadySaved);
        $('#medModalNameLocked').toggleClass('d-none', !isAlreadySaved);

        $('#medModalDosage').val(d.dosage);
        $('#medModalUom').val(d.uom ?? '');
        $('#medModalFreq').val(d.freq);
        $('#medModalDuration').val(d.duration ?? '');
        $('#medModalQty').val(d.qty ?? '');
        $('#medModalNotes').val(d.notes ?? '');
        medicineModal.show();
    }

    /* Autocomplete bound ONCE on the modal input, same fix as Create
       Prescription (previously this page rebound it on every appended
       row). Also switched to the real endpoint (api/external/medicine_api.php)
       instead of the mock one, so the same "id"-required fix applies here. */
    let medicineJustSelected = false;

    $('#medModalName').autocomplete({
        minLength: 2,
        delay: 300,
        appendTo: 'body',
        source: function (request, response) {
            $.getJSON("../../api/external/medicine_api.php", { term: request.term }, function (data) {
                // FIX: defends against a malformed/non-array API response
                // ever being handed to jQuery UI's response() callback,
                // which expects a real array.
                response(Array.isArray(data) ? data : []);
            });
        },
        select: function (event, ui) {
            medicineJustSelected = true;

            $('#medModalMedicineId').val(ui.item.id);
            $(this).val(ui.item.value).removeClass('is-invalid');
            $('#medModalNameError').addClass('d-none');

            if (!$('#medModalDosage').val()) $('#medModalDosage').val(ui.item.dosage ?? '');
            // FIX: medicine_api.php's response field is "signa", not
            // "frequency" — this was silently preventing Signa from ever
            // autofilling on this page (create-prescription.php already
            // used the correct key).
            if (!$('#medModalFreq').val()) $('#medModalFreq').val(ui.item.signa ?? '');
            if (!$('#medModalDuration').val()) $('#medModalDuration').val(ui.item.duration ?? '');
            // FIX (Item 2): UOM is a property of the medicine itself, not
            // user-editable (see the readonly field), so it always
            // overwrites on selection rather than the "only if empty"
            // guard used for the hand-editable fields above.
            $('#medModalUom').val(ui.item.uom ?? '');

            setTimeout(function () { medicineJustSelected = false; }, 0);
            return false;
        }
    });

    $('#medModalName').on('input', function () {
        if (medicineJustSelected || $(this).prop('disabled')) return;
        $('#medModalMedicineId').val('');
        $(this).removeClass('is-invalid');
        $('#medModalNameError').addClass('d-none');
    });

    /*
     * FIX (Item 3): Dosage restricted to numeric values only, matching
     * create-prescription.php's Add Medicine modal.
     */
    $('#medModalDosage').on('input', function () {
        if ($(this).prop('disabled')) return;
        let v = this.value.replace(/[^0-9.]/g, '');
        const firstDot = v.indexOf('.');
        if (firstDot !== -1) {
            v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, '');
        }
        this.value = v;
    });

    $('#medModalQty').on('input', function () {
        if (this.value < 0) this.value = 0;
    });

    $('#addMedicineBtn').click(openAddMedicineModal);

    $(document).on('click', '.editMedicineBtn', function () {
        openEditMedicineModal($(this).data('row-id'));
    });

    $(document).on('click', '.deleteMedicineBtn', function () {
        const rowId = $(this).data('row-id');
        Swal.fire({
            title: 'Remove this medicine?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Remove'
        }).then(res => {
            if (res.isConfirmed) {
                delete medicineRowsData[rowId];
                $(`#medicinesTableBody tr.medicineRow[data-row-id="${rowId}"]`).remove();
                renderEmptyStateIfNeeded();
            }
        });
    });

    $('#medModalSaveBtn').click(function () {
        const name = $('#medModalName').val().trim();
        const existingId = $('#medModalExistingId').val();
        const medicineId = $('#medModalMedicineId').val();
        const dosage = $('#medModalDosage').val().trim();
        const uom = $('#medModalUom').val().trim();
        const freq = $('#medModalFreq').val().trim();
        const duration = $('#medModalDuration').val();
        let qty = $('#medModalQty').val();
        const notes = $('#medModalNotes').val().trim();

        // A brand-new medicine must come from the autocomplete list. An
        // already-saved one (name field disabled) is exempt, since its
        // identity was already established when it was first added.
        const nameLocked = $('#medModalName').prop('disabled');
        if (!name || (!medicineId && !nameLocked)) {
            $('#medModalName').addClass('is-invalid');
            $('#medModalNameError').removeClass('d-none');
            return;
        }

        if (!dosage || !freq || qty === '') {
            Swal.fire('Missing Fields', 'Dosage, Signa, and Quantity are required.', 'warning');
            return;
        }

        if (qty < 0) qty = 0;

        const rowId = $('#medModalRowId').val() || null;
        const pharmacyStatus = rowId && medicineRowsData[rowId] ? medicineRowsData[rowId].pharmacyStatus : 'Pending';
        const qtyDispensed = rowId && medicineRowsData[rowId] ? medicineRowsData[rowId].qtyDispensed : null;

        addOrReplaceRow({ existingId, medicineId, name, dosage, uom, freq, duration, qty, notes, pharmacyStatus, qtyDispensed }, rowId);

        medicineModal.hide();
    });

    /* Seed the table with the medicines already saved on this prescription. */
    $(document).ready(function () {
        const initialMedicines = <?= json_encode($medicinesForJs) ?>;
        initialMedicines.forEach(m => addOrReplaceRow(m));
        renderEmptyStateIfNeeded();
    });

    /* =========================================================
       Everything below is unchanged from before except signing:
       deny, print. Pharmacy medicine-status updates are handled
       by the dedicated dispense workflow, not on this page.
    ========================================================= */

    $(function(){

        const signatureModal = new bootstrap.Modal(document.getElementById('signatureModal'));
        const reasonModal = new bootstrap.Modal(document.getElementById('reasonModal'));

        // Reason modal — Partial/Unclaimed medicines only (icon is only
        // rendered for those statuses, see renderMedicineRow).
        $(document).on('click', '.viewReasonBtn', function () {
            const rowId = $(this).data('row-id');
            const d = medicineRowsData[rowId];
            if (!d) return;

            $('#reasonModalTitle').html(
                `<i class="bi bi-info-circle me-2"></i>${escapeHtml(d.pharmacyStatus)} — Reason`
            );
            $('#reasonModalMedicineName').text(d.name);
            $('#reasonModalText').text(d.reason && d.reason.trim() !== '' ? d.reason : 'No reason was recorded.');
            reasonModal.show();
        });

        // SIGN — just opens the confirm modal now, no canvas to initialize.
        $('#openSignatureModal').click(function(){
            signatureModal.show();
        });

        $('#submitWithSignature').click(function(){

            if (!doctorSignatureDataUri) {
                Swal.fire('No Signature', 'No signature is on file for your account.', 'error');
                return;
            }

            let formData = $('#prescriptionForm').serialize();
            formData += '&signature=' + encodeURIComponent(doctorSignatureDataUri);
            formData += '&status=Signed';
            Swal.fire({
                title: 'Are you sure?',
                text: 'This prescription will be submitted and signed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Submit & Sign'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('../../controller/doctor/prescription_approve_process.php', formData, function(res) {
                            if (res.status === 'success') {
                                Swal.fire('Success', res.message, 'success')
                                    .then(() => location.reload());
                            } else {
                                Swal.fire('Error', res.message, 'error');
                            }
                        }, 
                        'json'
                    );
                }
            });
        });

        // Deny button
        $(document).on('click', '.deny-btn', function() {
            let id = $(this).data('id');

            Swal.fire({
                title: 'Are you sure?',
                text: 'This prescription will be marked as denied and it cannot be edited or signed anymore.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Deny'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('../../controller/doctor/prescription_update_status.php', 
                        { id: id, status: 'Denied' }, 
                        function(res) {
                            if (res.status === 'success') {
                                Swal.fire('Success', res.message, 'success')
                                    .then(() => location.reload());
                            } else {
                                Swal.fire('Error', res.message, 'error');
                            }
                        }, 
                        'json'
                    );
                }
            });
        });

        // Print — only wired up when status === 'Signed' (button absent/disabled otherwise)
        $('#printPrescriptionBtn').click(function(){
            let pdfUrl = "../../controller/prescription/generate_prescription_pdf.php?id=<?= $prescription_id ?>";
            let win = window.open(pdfUrl, "_blank");
            if (win) {
                win.onload = function(){ win.print(); };
            }
        });

    });
</script>

<?php include '../../includes/footer.php'; ?>