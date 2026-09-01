<?php
session_start();
require '../../config/db.php';

if (!isset($_SESSION['user']) ||
   !in_array($_SESSION['user']['role'], ['health_facility', 'doctor'])) {
    header("Location: ../../../index.php");
    exit;
}

$userRole = $_SESSION['user']['role'];

$facility_id = $_SESSION['user']['facility_id'];
$isHealthFacility = $_SESSION['user']['role'] === 'health_facility';
$isDoctor = $_SESSION['user']['role'] === 'doctor';
$showForbiddenModal = !$isHealthFacility && !$isDoctor;

// Nurse accounts log in under the 'health_facility' role (there's no
// separate 'nurse' role), so they get the same medicine management
// privileges (add / edit / delete) as doctors on this page. Doctors
// additionally sign the prescription on submit (handled further below by
// the $userRole === 'doctor' check), but medicine-table permissions are
// shared between the two roles.
$canManageMedicines = $isDoctor || $isHealthFacility;

if ($isHealthFacility) {
    $dashboardUrl = '../health_facility/';
}
if ($isDoctor) {
    $dashboardUrl = '../doctor/';
}

if ($isHealthFacility || $isDoctor) {
    $listUrl = '../list/prescription.php';
}

$patient_id = $_GET['patient_id'] ?? null;
?>

<?php include '../../includes/header.php'; ?>

<style>
    :root {
        --rx-border: #e6e8eb;
        --rx-muted: #667085;
        --rx-muted-light: #98a2b3;
    }

    .rx-header-bar h4 { font-weight: 700; color: #1d2939; margin-bottom: 0; }

    /* Patient / info card (shared language with Prescription Details) */
    .rx-patient-card {
        background: #fff;
        border: 1px solid var(--rx-border);
        border-radius: 0.85rem;
        padding: 1.5rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
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

    /* Last Medical Consult callout */
    .rx-last-consult {
        background: #eff8ff;
        border: 1px solid #b2ddff;
        border-radius: 0.6rem;
        padding: .75rem .9rem;
        display: flex;
        align-items: flex-start;
        gap: .6rem;
    }
    .rx-last-consult i { color: #175cd3; font-size: 1.05rem; margin-top: .1rem; }
    .rx-last-consult .lbl {
        font-weight: 700;
        font-size: 0.78rem;
        color: #175cd3;
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: .15rem;
    }
    .rx-last-consult .date { font-size: 0.87rem; color: #1d2939; font-weight: 600; }
    .rx-last-consult .dx { font-size: 0.8rem; color: #475467; margin-top: .1rem; }
    .rx-no-consult {
        font-size: 0.82rem;
        color: var(--rx-muted-light);
        background: #f9fafb;
        border: 1px dashed #e6e8eb;
        border-radius: 0.6rem;
        padding: .65rem .9rem;
    }

    /* Search patient */
    #patientSuggestions {
        z-index: 20;
        width: 100%;
        max-height: 260px;
        overflow-y: auto;
        border-radius: 0.6rem;
        box-shadow: 0 4px 12px rgba(16, 24, 40, 0.12);
    }
    #patientSuggestions .list-group-item {
        border: none;
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    /* Tabs + Medicines panel */
    .rx-medicines-card {
        background: #fff;
        border: 1px solid var(--rx-border);
        border-radius: 0.85rem;
        padding: 1.5rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .nav-tabs .nav-link {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--rx-muted);
    }
    .nav-tabs .nav-link.active {
        color: #0d6efd;
    }

    /* Medicines table */
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

    /* ===== Modal redesign (shared visual language across the app) ===== */
    .modal-content {
        border: 1px solid var(--rx-border);
        border-radius: 0.9rem;
        box-shadow: 0 8px 24px rgba(16, 24, 40, 0.10);
        overflow: hidden;
    }
    .modal-header {
        border-bottom: 1px solid #eaecf0;
        padding: 1.1rem 1.5rem;
    }
    .modal-header .modal-title {
        font-size: 1rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: .5rem;
        margin: 0;
    }
    .modal-body { padding: 1.5rem; }
    .modal-footer { border-top: 1px solid #eaecf0; padding: 1rem 1.5rem; }
    .form-label-sm {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--rx-muted);
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: .3rem;
        display: block;
    }

    /* ===== jQuery UI autocomplete vs Bootstrap modal z-index fix =====
       Bootstrap modals sit around z-index 1055-1060. jQuery UI's
       autocomplete dropdown defaults to a much lower z-index, so when
       the medicine input lives inside a modal the suggestion list
       renders BEHIND the modal — visually invisible even though it's
       in the DOM. Forcing it above the modal (and its backdrop) fixes
       this without touching the widget's JS. */
    .ui-autocomplete {
        z-index: 2000 !important;
    }

    /* Status pills (Medication History) */
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-weight: 600;
        font-size: 0.78rem;
        padding: .3rem .65rem;
        border-radius: 0.5rem;
        white-space: nowrap;
    }
    .status-pill-success { background:#ecfdf3; color:#027a48; border:1px solid #abefc6; }
    .status-pill-warning { background:#fffaeb; color:#b54708; border:1px solid #fedf89; }
    .status-pill-info     { background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; }
    .status-pill-secondary{ background:#f2f4f7; color:#344054; border:1px solid #eaecf0; }

    #historyTable thead th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: var(--rx-muted);
        font-weight: 700;
        white-space: nowrap;
    }
    #historyTable tbody tr:hover { background-color: #f9fafb; }

    /* Responsive tweaks */
    @media (max-width: 767.98px) {
        .rx-patient-card, .rx-medicines-card { padding: 1.1rem; }
        #medicineTab, #historyTab { height: auto !important; max-height: 420px; }
    }
</style>

    <!-- Page Title -->
    <div class="page-title">
      <nav class="breadcrumbs">
        <div class="container">
          <ol>
            <li><a href="<?= $dashboardUrl ?>" class="rx-exit-link">Dashboard</a></li>
            <li><a href="<?= $listUrl ?>" class="rx-exit-link">Prescription List</a></li>
            <li class="current">Create Prescription</li>
          </ol>
        </div>
      </nav>
    </div><!-- End Page Title -->

    <section class="doctors section" id="doctors">
        <div class="container" data-aos="fade-up" data-aos-delay="100">

            <div class="d-flex justify-content-between align-items-center mb-4 rx-header-bar">
                <h4>Create Prescription</h4>
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm rx-exit-link" data-history-back="1">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <form id="prescriptionForm">

                <input type="hidden" name="patient_id" id="patient_id">
                <input type="hidden" name="signature_data" id="signature_data">

                <!--
                    REFILL TRACKING (see JS section for how these are set)
                    - is_refill: "YES" once medicines were loaded from
                      Prescription History at least once this session,
                      "NO" for a prescription built entirely from scratch.
                    - pure_refill_no_new_meds: "1" only when medicines came
                      from history AND nothing new was added afterward —
                      this is the "just refilling, nothing changed" case.
                    Backend decides what to update based on these PLUS the
                    session role; see prescriptions_process.php.
                -->
                <input type="hidden" name="is_refill" id="is_refill" value="NO">
                <input type="hidden" name="pure_refill_no_new_meds" id="pure_refill_no_new_meds" value="0">

                <div class="row g-3">

                    <!-- LEFT: Patient + Diagnosis -->
                    <div class="col-lg-4" data-aos="fade-right" data-aos-delay="150">
                        <div class="rx-patient-card">

                            <span class="rx-section-label mt-0"><i class="bi bi-search me-1"></i>Search Patient</span>
                            <div class="position-relative mb-3">
                                <input type="text" id="searchInput" class="form-control"
                                    placeholder="Enter Patient Name or Yellow Card No.">
                                <div id="patientSuggestions" class="list-group position-absolute bg-dark"></div>
                            </div>

                            <span class="rx-section-label"><i class="bi bi-person-lines-fill me-1"></i>Demographics</span>
                            <div class="rx-demo-grid">
                                <p><strong>Sex:</strong> <span id="sex"></span></p>
                                <p><strong>Birthday:</strong> <span id="birthday"></span></p>
                                <p><strong>Address:</strong> <span id="address"></span></p>
                                <p><strong>Contact:</strong> <span id="contact"></span></p>
                                <p class="mb-0"><strong>Makati City Gov't Employee:</strong> <span id="makati_employee"></span></p>
                            </div>

                            <!--
                                Last Medical Consult — now a highlighted callout
                                instead of an inline text line, and shows the
                                last diagnosis too if the patient endpoint
                                returns `last_diagnosis`. If your
                                search_patient.php / get_patient.php responses
                                don't include that field yet, this simply
                                shows the date with no diagnosis line — safe
                                either way.
                            -->
                            <span class="rx-section-label"><i class="bi bi-calendar2-check me-1"></i>Last Medical Consult</span>
                            <div id="lastConsultBox" class="rx-last-consult mb-1" style="display:none;">
                                <i class="bi bi-calendar2-check"></i>
                                <div>
                                    <div class="lbl">Last Visit</div>
                                    <div class="date" id="last_medical_consult"></div>
                                    <div class="dx" id="last_consult_diagnosis"></div>
                                </div>
                            </div>
                            <div id="noConsultBox" class="rx-no-consult" style="display:none;">
                                <i class="bi bi-info-circle me-1"></i> No consultation on record yet.
                            </div>


                            <span class="rx-section-label"><i class="bi bi-clipboard2-pulse me-1"></i>Prescription Info</span>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="rx-field-label">Diagnosis</label>
                                    <input name="diagnosis" id="diagnosis" class="form-control form-control-sm">
                                </div>
                                <div class="col-6">
                                    <label class="rx-field-label">For Refill</label>
                                    <div class="d-flex align-items-center gap-3 pt-1">
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="radio" name="for_refill" id="forRefillYes" value="Yes" checked>
                                            <label class="form-check-label" for="forRefillYes">Yes</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="radio" name="for_refill" id="forRefillNo" value="No">
                                            <label class="form-check-label" for="forRefillNo">No</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="rx-field-label">Remarks</label>
                                    <textarea name="remarks" id="remarks" class="form-control form-control-sm" rows="4"></textarea>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- RIGHT: Medicines / History -->
                    <div class="col-lg-8" data-aos="fade-left" data-aos-delay="200">
                        <div class="rx-medicines-card">

                            <ul class="nav nav-tabs mb-3">
                                <li class="nav-item">
                                    <a class="nav-link active" data-bs-toggle="tab" href="#medicineTab">
                                        <i class="bi bi-capsule me-1"></i>Medicines
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-bs-toggle="tab" href="#historyTab">
                                        <i class="bi bi-clock-history me-1"></i>Medication History
                                    </a>
                                </li>
                            </ul>

                            <div class="tab-content">

                                <!-- MEDICINES TAB -->
                                <div class="tab-pane fade show active" id="medicineTab" style="height:420px; overflow:auto;">

                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="rx-section-label mt-0 mb-0">Prescribed Medicines</span>
                                        <?php if ($canManageMedicines): ?>
                                            <button type="button" id="addMedicineBtn" class="btn btn-sm btn-dark">
                                                <i class="bi bi-plus-lg"></i> Add Medicine
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle mb-0" id="medicinesTable">
                                            <thead>
                                                <tr>
                                                    <th>Medicine</th>
                                                    <th>Dosage</th>
                                                    <th>UOM</th>
                                                    <th>Signa</th>
                                                    <th>Duration</th>
                                                    <th>Qty</th>
                                                    <th>Notes</th>
                                                    <?php if ($canManageMedicines): ?><th width="100">Action</th><?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody id="medicinesTableBody">
                                                <tr id="medicinesEmptyRow">
                                                    <td colspan="<?= $canManageMedicines ? 8 : 7 ?>" class="rx-empty-meds">
                                                        <i class="bi bi-capsule"></i>
                                                        No medicines added yet.
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                </div>

                                <!-- HISTORY TAB -->
                                <div class="tab-pane fade" id="historyTab" style="height:420px; overflow:auto;">
                                    <div class="table-responsive">
                                        <table id="historyTable" class="table table-bordered table-striped align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Doctor</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Prescription Type</th>
                                                    <th>Transmittal Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">Select a patient to load history.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- ITEM 10: Submit + PDF actions, lower-right -->
                    <div class="col-12">
                        <div class="rx-footer-actions d-flex justify-content-end flex-wrap gap-2">

                            <!--
                                ITEMS 15 & 17: View/print generated PDF, gated on
                                signed status. Hidden until a successful submit
                                response confirms a PDF exists.
                            -->
                            <!--<div id="pdfActionBar" class="d-flex gap-2" style="display:none;">-->
                            <!--    <a id="viewPdfBtn" href="#" target="_blank" class="btn btn-outline-primary btn-sm">-->
                            <!--        <i class="bi bi-file-earmark-pdf"></i> View Prescription (PDF)-->
                            <!--    </a>-->
                            <!--    <button id="printPdfBtn" type="button" class="btn btn-outline-secondary btn-sm" disabled-->
                            <!--        title="Printing is only available once the doctor has signed the prescription">-->
                            <!--        <i class="bi bi-printer"></i> Print-->
                            <!--    </button>-->
                            <!--</div>-->

                            <button type="button" id="submitBtn" class="btn btn-primary btn-sm px-4">
                                <i class="bi bi-check-circle me-1"></i>
                                <?= $userRole === 'doctor' ? 'Submit & Sign Prescription' : 'Submit Prescription' ?>
                            </button>
                        </div>
                    </div>

                </div>
            </form>

        </div>
    </section>

    <!-- ADD / EDIT MEDICINE MODAL -->
    <div class="modal fade" id="medicineModal">
        <div class="modal-dialog">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="medicineModalTitle"><i class="bi bi-capsule text-primary"></i> Add Medicine</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" id="medModalRowId">
                    <input type="hidden" id="medModalMedicineId">

                    <div class="mb-3">
                        <label class="form-label-sm">Medicine <span class="text-danger">*</span></label>
                        <input type="text" id="medModalName" class="form-control" autocomplete="off" required>
                        <small class="text-danger d-none" id="medModalNameError">Please select a medicine from the list.</small>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <!--
                                FIX (Item 3): Dosage restricted to numeric
                                values only — see the input handler further
                                below. The unit itself now comes entirely
                                from the medicine's UOM (auto-filled to the
                                right), not typed into Dosage.
                            -->
                            <label class="form-label-sm">Dosage <span class="text-danger">*</span></label>
                            <input type="text" id="medModalDosage" class="form-control" inputmode="decimal" required>
                        </div>
                        <div class="col-md-2">
                            <!--
                                FIX (Item 2): Unit of Measure, auto-filled
                                from the selected medicine (see the
                                autocomplete select handler below). Read-only
                                since it's a property of the medicine itself,
                                not something the prescriber chooses per line.
                            -->
                            <label class="form-label-sm">UOM</label>
                            <input type="text" id="medModalUom" class="form-control" readonly tabindex="-1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sm">Signa <span class="text-danger">*</span></label>
                            <input type="text" id="medModalFreq" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <!--
                                FIX (Item 1): Duration was type="number", but a
                                medicine's configured Duration can be a text
                                value (e.g. "5 Days" — see the medicine setup
                                screenshot). Setting a number input's .val() to
                                a non-numeric string is silently rejected by the
                                browser, leaving the field blank — that's why
                                autofill never appeared. Changed to type="text"
                                so any configured Duration value populates
                                correctly, numeric or not.
                            -->
                            <label class="form-label-sm">Duration</label>
                            <input type="text" id="medModalDuration" class="form-control" placeholder="e.g. 5 Days">
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

    <!-- PRESCRIPTION DETAILS MODAL -->
    <div class="modal fade" id="historyViewModal">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-medical text-primary"></i> Prescription Details</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div id="historyDetails" class="rx-demo-grid mb-3"></div>
                    <hr>

                    <!-- ITEM 3: select individual items or select all, then proceed to Medicines tab -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="form-label-sm mb-0">Prescribed Medicines</span>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="selectAllMedicine">
                            <label class="form-check-label" for="selectAllMedicine">Select All</label>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th width="40">Select</th>
                                    <th>Medicine</th>
                                    <th>Dosage</th>
                                    <th>Signa</th>
                                    <th>Duration</th>
                                    <th>Quantity</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="historyMedicines"></tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button id="loadPrescriptionBtn" class="btn btn-success btn-sm">
                        <i class="bi bi-arrow-down-circle"></i> Load Selected Medicines
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>

            </div>
        </div>
    </div>

    <!-- ITEM 4: Medicine Transaction Summary modal (preview before loading into form) -->
    <div class="modal fade" id="summaryModal">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-receipt text-success"></i> Medicine Transaction Summary</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div id="summaryContent"></div>
                </div>

                <div class="modal-footer">
                    <button id="confirmLoadMedicine" class="btn btn-primary btn-sm">
                        <i class="bi bi-arrow-right-circle"></i> Proceed To Medicines
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                </div>

            </div>
        </div>
    </div>

<?php include '../../includes/footer.php'; ?>

<script>

    // Nurse accounts (role: health_facility) share the same medicine
    // add/edit/delete capability as doctors on this page. Doctor-only
    // behavior (signing) is handled elsewhere and is untouched by this flag.
    const canManageMedicines = <?= $canManageMedicines ? 'true' : 'false' ?>;

    let patientsList = [];
    let selectedPatientId = null;
    let prescriptionHistory = [];

    /* =========================================================
       REFILL TRACKING STATE
       - loadedFromHistory: true once "Load Selected Medicines" has
         been used at least once for the current patient/session.
       - newMedicineAddedAfterLoad: true if a brand-new medicine row
         was added via the Add Medicine modal (NOT an edit of an
         existing row). Editing/removing a loaded row does not count
         as "adding" — only a genuinely new row does.
       These two flags decide, on submit, whether this is a "pure
       refill" (nothing new, just re-issuing what history already
       had) vs. a prescription that includes something new.
    ========================================================= */
    let loadedFromHistory = false;
    let newMedicineAddedAfterLoad = false;

    function resetRefillTracking() {
        loadedFromHistory = false;
        newMedicineAddedAfterLoad = false;
    }

    /* =========================================================
       MEDICINE TABLE STATE
       One source of truth (medicineRowsData) keyed by a generated
       rowId. Both the Add/Edit modal AND the "Load Selected
       Medicines" (from history) flow write into this same map
       through the same addOrReplaceRow() function below, so the
       two entry points can't drift out of sync with each other.
    ========================================================= */
    let medicineRowCounter = 0;
    let medicineRowsData = {};

    /* =========================================================
       FIX (Item 5): confirmation prompt when leaving this page with
       unsubmitted medicines still in the table. Standard browser
       beforeunload confirmation — fires for tab close, refresh, typed
       URL navigation, AND clicking any normal link (like the Back
       button or breadcrumb), since all of those trigger a page
       unload. formSubmittedSuccessfully is flipped to true right
       before the page reloads after a successful submit, so the
       prompt never appears in that case.
    ========================================================= */
    let formSubmittedSuccessfully = false;

    window.addEventListener('beforeunload', function (e) {
        const hasPendingMedicines = Object.keys(medicineRowsData).length > 0;
        if (hasPendingMedicines && !formSubmittedSuccessfully) {
            e.preventDefault();
            e.returnValue = ''; // required for the native browser prompt to show
            return '';
        }
    });

    /* =========================================================
       FIX (Item 5, part 2): custom exit-confirmation for IN-APP
       navigation (Back button, breadcrumbs).

       The native beforeunload prompt above only ever shows the
       browser's own generic "leave site?" wording — every modern
       browser deliberately ignores whatever text a site tries to
       set, for security reasons, so it can never say anything
       specific about "pending medicines". That's a hard browser
       restriction, not something fixable in code.

       For links we control (marked with .rx-exit-link), we can
       intercept the click ourselves and show a real, specific
       SweetAlert instead — this is the only way to actually
       satisfy "notify the user of the pending medicines" rather
       than a generic browser warning.
    ========================================================= */
    $(document).on('click', '.rx-exit-link', function (e) {

        const hasPendingMedicines = Object.keys(medicineRowsData).length > 0;

        if (!hasPendingMedicines || formSubmittedSuccessfully) {
            return; // nothing pending — let the link navigate normally
        }

        e.preventDefault();

        const $link = $(this);
        const isHistoryBack = $link.data('history-back') === 1;
        const medicineCount = Object.keys(medicineRowsData).length;

        Swal.fire({
            icon: 'warning',
            title: 'Unsubmitted Medicines',
            text: `You have ${medicineCount} medicine${medicineCount === 1 ? '' : 's'} added to this prescription that ${medicineCount === 1 ? "hasn't" : "haven't"} been submitted yet. Leaving now will discard ${medicineCount === 1 ? 'it' : 'them'}.`,
            showCancelButton: true,
            confirmButtonText: 'Leave Anyway',
            cancelButtonText: 'Stay on This Page',
            confirmButtonColor: '#d33'
        }).then((result) => {
            if (!result.isConfirmed) return;

            // Confirmed leaving on purpose — suppress the native
            // beforeunload prompt too, so there isn't a second,
            // redundant "are you sure?" right after this one.
            formSubmittedSuccessfully = true;

            if (isHistoryBack) {
                history.back();
            } else {
                window.location.href = $link.attr('href');
            }
        });
    });

    const medicineModal = new bootstrap.Modal(document.getElementById('medicineModal'));

    $(document).ready(function () {
        renderEmptyStateIfNeeded();
    });

    function escapeHtml(str) {
        return $('<div>').text(str ?? '').html();
    }

    /* -----------------------------------------------------------
       ITEM 6: DATE/TIME ACCURACY
    ----------------------------------------------------------- */
    function formatDateTime(dateStr) {

        if (!dateStr) return '';

        let normalized = dateStr.replace(' ', 'T');
        let d = new Date(normalized);

        if (isNaN(d.getTime())) {
            return dateStr;
        }

        return d.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }

    /* -----------------------------------------------------------
       LAST MEDICAL CONSULT DISPLAY
       Shows date + (optional) diagnosis in a highlighted box, or
       a muted "no consult yet" state. `p.last_diagnosis` is read
       defensively — if your patient endpoints don't return it yet,
       this just omits that line rather than breaking.
    ----------------------------------------------------------- */
    function updateLastConsult(p) {

        if (p.last_medical_consult) {
            $('#last_medical_consult').text(formatDateTime(p.last_medical_consult));

            let dx = p.last_diagnosis ? ('Diagnosis: ' + p.last_diagnosis) : '';
            $('#last_consult_diagnosis').text(dx);

            $('#lastConsultBox').show();
            $('#noConsultBox').hide();
        } else {
            $('#lastConsultBox').hide();
            $('#noConsultBox').show();
        }
    }

    function populateDemographics(p) {
        $("#sex").text(p.gender);
        $("#birthday").text(p.birthday);
        $("#address").text(p.house_no_street + ", " + p.barangay);
        $("#contact").text(p.contact_number);
        $("#makati_employee").text(p.makati_employee);
        updateLastConsult(p);
    }

    /* -----------------------------------------------------------
       ITEM 5: PATIENT SEARCH (full name + Yellow Card number)
    ----------------------------------------------------------- */
    $("#searchInput").on("input", function () {

        let keyword = $(this).val();

        if (keyword.length < 2) {
            $("#patientSuggestions").hide();
            return;
        }

        $.getJSON("../../api/patient/search_patient.php", { keyword }, function (res) {

            patientsList = res.patients || [];

            let html = "";

            if (patientsList.length === 0) {
                html = `
                    <div class="list-group-item text-center text-muted">
                        No patient found
                        <a href="../add/patient.php?from=prescription" class="btn btn-sm">
                            + Add Patient
                        </a>
                    </div>
                `;
            } else {
                patientsList.forEach((p, i) => {
                    html += `
                        <a href="#" class="list-group-item patient-item" data-index="${i}">
                            <b>${p.last_name}, ${p.first_name}</b>
                            <small> &mdash; Yellow Card #: ${p.makati_health_plus_no ?? 'N/A'}</small>
                        </a>
                    `;
                });
            }

            $("#patientSuggestions").html(html).show();
        });
    });

    /* SELECT PATIENT */
    $(document).on("click", ".patient-item", function (e) {

        e.preventDefault();

        let p = patientsList[$(this).data("index")];

        selectedPatientId = p.id;

        $("#patient_id").val(p.id);
        $("#searchInput").val(p.last_name + ", " + p.first_name);

        populateDemographics(p);

        $("#patientSuggestions").hide();
        $("#pdfActionBar").hide();

        // Starting fresh with a (possibly different) patient — clear any
        // leftover refill-tracking state and medicines from a previous
        // patient/attempt.
        resetRefillTracking();
        medicineRowsData = {};
        $('#medicinesTableBody').empty();
        renderEmptyStateIfNeeded();

        // "For Refill" defaults back to Yes for each fresh patient.
        $('#forRefillYes').prop('checked', true);

        loadMedicationHistory();
    });

    /* AUTO-LOAD PATIENT FROM QUERY STRING (?patient_id=) */
    $(document).ready(function () {

        const patientId = "<?= htmlspecialchars($patient_id ?? '', ENT_QUOTES) ?>";

        if (patientId) {
            $.getJSON("../../api/patient/get_patient.php", { patient_id: patientId }, function (p) {

                if (!p) return;

                selectedPatientId = p.id;

                $("#patient_id").val(p.id);
                $("#searchInput").val(p.last_name + ", " + p.first_name);

                populateDemographics(p);

                resetRefillTracking();

                loadMedicationHistory();
            });
        }
    });

    /* LOAD HISTORY TABLE (redesigned status pills) */
    function loadMedicationHistory() {

        $.getJSON(
            "../../api/patient/patient_medication_history.php",
            { patient_id: selectedPatientId },
            function (data) {

                prescriptionHistory = data;

                let rows = "";

                if (!data || data.length === 0) {
                    rows = `<tr><td colspan="7" class="text-center text-muted py-4">No medication history found.</td></tr>`;
                } else {
                    data.forEach((p, i) => {

                        let badge = p.status == "Signed"
                            ? '<span class="status-pill status-pill-success"><i class="bi bi-check-circle-fill"></i> Signed</span>'
                            : '<span class="status-pill status-pill-warning"><i class="bi bi-hourglass-split"></i> For Signing</span>';

                        // Prescription Type: derived from the "For Refill"
                        // choice stored on the prescription (for_refill:
                        // 'YES'/'NO'). Falls back to is_refill for older
                        // records that predate the for_refill column.
                        let isRefillType = (p.for_refill ?? p.is_refill) === 'YES';
                        let typeBadge = isRefillType
                            ? '<span class="status-pill status-pill-info"><i class="bi bi-arrow-repeat"></i> Refill</span>'
                            : '<span class="status-pill status-pill-secondary"><i class="bi bi-clipboard2-pulse"></i> Medical Consult</span>';

                        // FIX (Item 4): there is no transmittal_status column —
                        // derived from transmitted_at instead (a nullable
                        // timestamp set once the prescription is actually
                        // transmitted), matching how list/prescription.php
                        // already determines this same state.
                        let transmittalBadge = p.transmitted_at
                            ? '<span class="status-pill status-pill-info"><i class="bi bi-truck"></i> Transmitted</span>'
                            : '<span class="status-pill status-pill-secondary"><i class="bi bi-clock"></i> Pending</span>';

                        rows += `
                            <tr>
                                <td>${i + 1}</td>
                                <td>${escapeHtml(p.doctor_name ?? 'Health Facility')}</td>
                                <td>${formatDateTime(p.created_at)}</td>
                                <td>${badge}</td>
                                <td>${typeBadge}</td>
                                <td>${transmittalBadge}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary viewHistory" data-index="${i}">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                }

                $("#historyTable tbody").html(rows);
            }
        );
    }

    /* VIEW MODAL */
    let selectedHistoryPrescription = null;

    $(document).on("click", ".viewHistory", function () {

        let pres = prescriptionHistory[$(this).data("index")];
        selectedHistoryPrescription = pres;

        $("#historyDetails").html(`
            <p><b>Date:</b> ${formatDateTime(pres.created_at)}</p>
            <p><b>Status:</b> ${escapeHtml(pres.status)}</p>
            <p><b>Doctor:</b> ${escapeHtml(pres.doctor_name ?? 'Health Facility')}</p>
            <p class="mb-0"><b>Diagnosis:</b> ${escapeHtml(pres.diagnosis)}</p>
        `);

        let rows = "";

        pres.medicines.forEach((m, i) => {
            rows += `
                <tr>
                    <td><input type="checkbox" class="medicineSelect" data-index="${i}"></td>
                    <td>${escapeHtml(m.medicine_name)}</td>
                    <td>${escapeHtml(m.dosage)}</td>
                    <td>${escapeHtml(m.frequency)}</td>
                    <td>${escapeHtml(m.duration)}</td>
                    <td>${escapeHtml(m.quantity)}</td>
                    <td>${escapeHtml(m.notes ?? '')}</td>
                    <td>${escapeHtml(m.status)}</td>
                </tr>
            `;
        });

        $("#historyMedicines").html(rows);
        $("#selectAllMedicine").prop('checked', false);

        new bootstrap.Modal(document.getElementById('historyViewModal')).show();
    });

    $("#selectAllMedicine").change(function () {
        $(".medicineSelect").prop("checked", $(this).prop("checked"));
    });

    /* ITEM 3 + ITEM 4: Load selected medicines -> preview summary modal -> proceed to Medicines tab */
    $("#loadPrescriptionBtn").click(function () {

        let selected = [];

        $(".medicineSelect:checked").each(function () {
            let index = $(this).data("index");
            selected.push(selectedHistoryPrescription.medicines[index]);
        });

        if (selected.length === 0) {
            Swal.fire("Warning", "Please select at least one medicine", "warning");
            return;
        }

        let html = `
            <div class="table-responsive">
            <table class="table table-bordered">
                <tr>
                    <th>Medicine</th>
                    <th>Signa</th>
                    <th>Qty</th>
                </tr>
        `;

        selected.forEach(m => {
            html += `
                <tr>
                    <td>${escapeHtml(m.medicine_name)}</td>
                    <td>${escapeHtml(m.frequency)}</td>
                    <td>${escapeHtml(m.quantity)}</td>
                </tr>
            `;
        });

        html += "</table></div>";

        $("#summaryContent").html(html);
        $("#summaryModal").data("medicines", selected);

        bootstrap.Modal.getInstance(document.getElementById("historyViewModal")).hide();
        new bootstrap.Modal(document.getElementById("summaryModal")).show();
    });

    /* Loading from history reuses the exact same addOrReplaceRow()
       function as the Add/Edit modal — same validation, same
       rendering, no separate code path to fall out of sync.

       REFILL TRACKING: this is the moment we mark the session as
       "loaded from history". newMedicineAddedAfterLoad resets to
       false here — this fresh load is the new baseline, so nothing
       has been added "after" it yet. */
    $("#confirmLoadMedicine").click(function () {

        let medicines = $("#summaryModal").data("medicines");

        medicineRowsData = {};
        $('#medicinesTableBody').empty();

        loadedFromHistory = true;
        newMedicineAddedAfterLoad = false;

        medicines.forEach(m => {
            addOrReplaceRow({
                medicineId: m.id ?? m.medicine_name,
                name: m.medicine_name,
                dosage: m.dosage,
                uom: m.uom ?? '', // defensive: only populates if patient_medication_history.php's JSON_OBJECT includes a uom key
                freq: m.frequency,
                duration: m.duration,
                qty: m.quantity,
                notes: m.notes ?? ''
            });
        });

        renderEmptyStateIfNeeded();

        $("#diagnosis").val(selectedHistoryPrescription.diagnosis);
        $("#remarks").val(selectedHistoryPrescription.remarks);

        bootstrap.Modal.getInstance(document.getElementById("summaryModal")).hide();

        $('a[href="#medicineTab"]').tab('show');
    });

    /* =========================================================
       MEDICINES TABLE — render / add / edit / delete
    ========================================================= */

    function renderMedicineRow(rowId) {

        let d = medicineRowsData[rowId];

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
                    <input type="hidden" name="medicine_id[]" value="${escapeHtml(d.medicineId)}">
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
                    <input type="hidden" name="duration[]" value="${escapeHtml(d.duration ?? '')}">
                    ${escapeHtml(d.duration ?? '') || '&mdash;'}
                </td>
                <td>
                    <input type="hidden" name="quantity[]" value="${d.qty ?? ''}">
                    ${d.qty ?? '&mdash;'}
                </td>
                <td>
                    <input type="hidden" name="notes[]" value="${escapeHtml(d.notes ?? '')}">
                    ${escapeHtml(d.notes ?? '') || '&mdash;'}
                </td>
                ${actionsHtml}
            </tr>
        `;
    }

    function renderEmptyStateIfNeeded() {
        if ($('#medicinesTableBody .medicineRow').length === 0) {
            $('#medicinesTableBody').html(`
                <tr id="medicinesEmptyRow">
                    <td colspan="${canManageMedicines ? 8 : 7}" class="rx-empty-meds">
                        <i class="bi bi-capsule"></i>
                        No medicines added yet.
                    </td>
                </tr>
            `);
        }
    }

    /* Single entry point used by BOTH the Add/Edit modal save handler
       and the "Load Selected Medicines" history flow. */
    function addOrReplaceRow(data, rowId = null) {

        if (!rowId) {
            rowId = 'row_' + (++medicineRowCounter);
        }

        medicineRowsData[rowId] = data;

        $('#medicinesEmptyRow').remove();

        let existing = $(`#medicinesTableBody tr.medicineRow[data-row-id="${rowId}"]`);

        if (existing.length) {
            existing.replaceWith(renderMedicineRow(rowId));
        } else {
            $('#medicinesTableBody').append(renderMedicineRow(rowId));
        }
    }

    function openAddMedicineModal() {
        $('#medicineModalTitle').html('<i class="bi bi-capsule text-primary"></i> Add Medicine');
        $('#medModalRowId').val('');
        $('#medModalMedicineId').val('');
        $('#medModalName').val('').removeClass('is-invalid');
        $('#medModalNameError').text('Please select a medicine from the list.').addClass('d-none');
        $('#medModalDosage').val('');
        $('#medModalUom').val('');
        $('#medModalFreq').val('');
        $('#medModalDuration').val('');
        $('#medModalQty').val('');
        $('#medModalNotes').val('');
        medicineModal.show();
    }

    function openEditMedicineModal(rowId) {
        let d = medicineRowsData[rowId];
        if (!d) return;

        $('#medicineModalTitle').html('<i class="bi bi-pencil-square text-primary"></i> Edit Medicine');
        $('#medModalRowId').val(rowId);
        $('#medModalMedicineId').val(d.medicineId ?? '');
        $('#medModalName').val(d.name).removeClass('is-invalid');
        $('#medModalNameError').text('Please select a medicine from the list.').addClass('d-none');
        $('#medModalDosage').val(d.dosage);
        $('#medModalUom').val(d.uom ?? '');
        $('#medModalFreq').val(d.freq);
        $('#medModalDuration').val(d.duration ?? '');
        $('#medModalQty').val(d.qty ?? '');
        $('#medModalNotes').val(d.notes ?? '');
        medicineModal.show();
    }

    /* Autocomplete bound ONCE on the modal input (previously this was
       re-bound on every generated row, which was wasteful and made
       editing awkward). ITEM 1: medicine_api.php returns generic name
       only — no frontend change needed for that. */
    /* Guard flag: prevents the "reset ID on input" handler below from
       wiping out a selection that was JUST made. Without this, if the
       widget's internal value-setting during selection also triggers
       an input-style event on the field, our own reset handler would
       fire immediately after select and clear the ID we just set —
       which is exactly the "please select from list" bug even after
       clicking a valid suggestion. */
    let medicineJustSelected = false;

    $('#medModalName').autocomplete({
        minLength: 2,
        delay: 300,
        appendTo: 'body', // avoid jQuery UI misdetecting the modal as a scroll container
        source: function (request, response) {
            $.getJSON("../../api/external/medicine_api.php", { term: request.term }, function (data) {

                /*
                 * FIX (Item 2): Inactive medicines must never be
                 * selectable. This filters them out of the suggestion
                 * list itself — defense-in-depth on top of the select
                 * handler's guard below, and covers the case where
                 * someone pastes/types an exact inactive medicine name.
                 *
                 * This checks a couple of likely field-name shapes
                 * (status: "Active"/"Inactive", or is_active: true/false)
                 * since the exact response shape of medicine_api.php
                 * wasn't provided. If neither field is present at all,
                 * items are treated as active by default so nothing
                 * breaks — but the real fix belongs server-side, in
                 * medicine_api.php's query (WHERE status = 'Active'),
                 * since a client-side filter alone can't stop a direct
                 * API call from ever returning inactive items.
                 */
                // FIX: defends against a malformed/non-array API response
                // (e.g. an error object instead of a results array) ever
                // crashing the page again — treated as "no results" rather
                // than letting .filter() throw a TypeError.
                const filtered = (Array.isArray(data) ? data : []).filter(function (item) {
                    if (item.status !== undefined && item.status !== null) {
                        return String(item.status).toLowerCase() === 'active';
                    }
                    if (item.is_active !== undefined && item.is_active !== null) {
                        return !!item.is_active;
                    }
                    return true; // no status field returned — assume active
                });

                response(filtered);
            });
        },
        select: function (event, ui) {

            medicineJustSelected = true;

            if (ui.item.id === undefined || ui.item.id === null || ui.item.id === '') {
                console.warn('medicine_api.php result is missing an "id" field for this item:', ui.item);
            }

            /*
             * FIX (Item 2, second layer): even though inactive medicines
             * are now filtered out of the suggestion list above, this
             * blocks selection too — in case a stale/cached list is
             * showing, or a future change to medicine_api.php stops
             * filtering server-side. Selection is refused outright
             * rather than silently allowed through.
             */
            const rawStatus = ui.item.status ?? (ui.item.is_active !== undefined ? (ui.item.is_active ? 'Active' : 'Inactive') : null);
            const isInactive = rawStatus !== null && String(rawStatus).toLowerCase() !== 'active';

            if (isInactive) {
                $('#medModalMedicineId').val('');
                $(this).val('').addClass('is-invalid');
                $('#medModalNameError').text('This medicine is Inactive and cannot be prescribed.').removeClass('d-none');

                Swal.fire({
                    icon: 'warning',
                    title: 'Medicine Unavailable',
                    text: `"${ui.item.value}" is currently Inactive and cannot be added to a prescription.`
                });

                setTimeout(function () { medicineJustSelected = false; }, 0);
                return false;
            }

            $('#medModalMedicineId').val(ui.item.id);
            $(this).val(ui.item.value).removeClass('is-invalid');
            $('#medModalNameError').text('Please select a medicine from the list.').addClass('d-none');

            /*
             * Autofill Dosage, UOM, Signa, and Duration from the selected
             * medicine's record.
             *
             * IMPORTANT: these always OVERWRITE the current field
             * values on selection (no "only if empty" guard). This
             * applies whether the modal is in Add or Edit mode:
             * picking a medicine from the list means "load defaults
             * for THIS medicine", so any leftover values from a
             * previous medicine (e.g. when editing a row and swapping
             * to a different medicine) are replaced rather than kept.
             * The user can still hand-edit Dosage/Signa/Duration
             * afterward; UOM is read-only (it's a property of the
             * medicine itself, not a per-line choice) and will simply be
             * overwritten again if they pick a medicine a second time.
             *
             * NOTE: field name from medicine_api.php is "signa", not
             * "frequency" — matching that here is what makes Signa
             * autofill actually work.
             *
             * Duration is now read into a text field (see Item 1 fix
             * above), so a configured value like "5 Days" autofills
             * correctly instead of silently failing.
             */
            $('#medModalDosage').val(ui.item.dosage ?? '');
            $('#medModalUom').val(ui.item.uom ?? '');
            $('#medModalFreq').val(ui.item.signa ?? '');
            $('#medModalDuration').val(ui.item.duration ?? '');

            // Release the guard on the next tick, after any events the
            // widget itself fires while finishing the selection.
            setTimeout(function () { medicineJustSelected = false; }, 0);

            return false;
        }
    });

    /* Typing manually invalidates the previously selected medicine_id —
       same rule as before: a medicine must come from the autocomplete
       list, not be freely typed. Guarded so it can't fire right after
       a selection and undo it (see medicineJustSelected above). */
    $('#medModalName').on('input', function () {
        if (medicineJustSelected) return;
        $('#medModalMedicineId').val('');
        $(this).removeClass('is-invalid');
        $('#medModalNameError').text('Please select a medicine from the list.').addClass('d-none');
    });

    /* ITEM 8 + ITEM 9: block negative quantity */
    $('#medModalQty').on('input', function () {
        if (this.value < 0) this.value = 0;
    });

    /*
     * FIX (Item 3): Dosage restricted to numeric values only — strips
     * anything that isn't a digit or a single decimal point as the user
     * types. Units now belong entirely in the separate, auto-filled UOM
     * field, not typed into Dosage.
     */
    $('#medModalDosage').on('input', function () {
        let v = this.value.replace(/[^0-9.]/g, '');
        const firstDot = v.indexOf('.');
        if (firstDot !== -1) {
            v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, '');
        }
        this.value = v;
    });

    $('#addMedicineBtn').click(openAddMedicineModal);

    $(document).on('click', '.editMedicineBtn', function () {
        openEditMedicineModal($(this).data('row-id'));
    });

    $(document).on('click', '.deleteMedicineBtn', function () {
        let rowId = $(this).data('row-id');

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

    /* SAVE (handles both Add and Edit)
       REFILL TRACKING: only a genuinely NEW row (no rowId, i.e. not
       editing something already on the table) counts as "added". */
    $('#medModalSaveBtn').click(function () {

        let name = $('#medModalName').val().trim();
        let medicineId = $('#medModalMedicineId').val();
        let dosage = $('#medModalDosage').val().trim();
        let uom = $('#medModalUom').val().trim();
        let freq = $('#medModalFreq').val().trim();
        let duration = $('#medModalDuration').val().trim();
        let qty = $('#medModalQty').val();
        let notes = $('#medModalNotes').val().trim();

        if (!name || !medicineId) {
            $('#medModalName').addClass('is-invalid');
            $('#medModalNameError').text('Please select a medicine from the list.').removeClass('d-none');
            return;
        }

        if (!dosage || !freq || qty === '') {
            Swal.fire('Missing Fields', 'Dosage, Signa, and Quantity are required.', 'warning');
            return;
        }

        if (qty < 0) qty = 0;

        let rowId = $('#medModalRowId').val() || null;

        /*
         * FIX (Item 3): warn when the same medicine is already in the
         * prescribed list. Excludes the row currently being edited
         * (rowId), so editing a row without changing its medicine
         * doesn't trigger a false "duplicate" warning against itself.
         */
        let duplicateRowId = Object.keys(medicineRowsData).find(function (id) {
            return id !== rowId && medicineRowsData[id].medicineId === medicineId;
        });

        if (duplicateRowId) {
            Swal.fire({
                icon: 'warning',
                title: 'Already Added',
                text: `"${name}" is already in the prescribed medicines list. Edit the existing entry instead of adding it again.`
            });
            return;
        }

        if (!rowId) {
            newMedicineAddedAfterLoad = true;
        }

        addOrReplaceRow({ medicineId, name, dosage, uom, freq, duration, qty, notes }, rowId);

        medicineModal.hide();
    });

    /* -----------------------------------------------------------
       SUBMIT
       ITEMS 15 & 17: on success, reveal the PDF action bar. "View"
       opens the PDF in a new tab. "Print" stays disabled unless the
       backend reports the prescription as signed.

       NOTE: the previous version called location.reload() immediately
       after the success alert, which wiped out the PDF action bar
       before it could ever be clicked. Reload now only happens if the
       backend didn't return a prescription_id to show a PDF for;
       otherwise the page stays so View/Print remain usable.

       REFILL TRACKING: right before serializing the form, the two
       hidden fields are set from the JS state tracked above:
         - is_refill: "YES" if medicines were loaded from history at
           any point this session, else "NO".
         - pure_refill_no_new_meds: "1" only if loaded from history
           AND nothing new was added afterward. The backend combines
           this with the session role to decide which patient date
           fields to touch (last_prescription_date, last_medical_consult,
           last_refill_date) — see prescriptions_process.php.
    ----------------------------------------------------------- */
    $("#submitBtn").click(function () {

        if (!selectedPatientId) {
            Swal.fire("Error", "Search patient first", "error");
            return;
        }

        if (Object.keys(medicineRowsData).length === 0) {
            Swal.fire("Error", "Please add at least one medicine.", "error");
            return;
        }

        let allValid = Object.values(medicineRowsData).every(d => !!d.medicineId);

        if (!allValid) {
            Swal.fire({
                icon: "error",
                title: "Invalid Medicine",
                text: "Please select all medicines from the medicine list."
            });
            return;
        }

        $('#is_refill').val(loadedFromHistory ? 'YES' : 'NO');
        $('#pure_refill_no_new_meds').val((loadedFromHistory && !newMedicineAddedAfterLoad) ? '1' : '0');

        Swal.fire({
            title: 'Are you sure?',
            text: 'This prescription will be submitted and signed.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Submit & Sign'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post(
                    "../../controller/prescription/prescriptions_proces.php",
                    $("#prescriptionForm").serialize(),
                    function (res) {
                        if (res.status === 'success') {

                            // Allow the page to unload without the "unsubmitted
                            // medicines" prompt firing on the reload below.
                            formSubmittedSuccessfully = true;

                            let successMsg = res.message + (res.rx ? ` (${res.rx})` : '');

                            if (res.prescription_id) {

                                let pdfUrl = "../../controller/prescription/generate_prescription_pdf.php?id="
                                    + encodeURIComponent(res.prescription_id);

                                $("#viewPdfBtn").attr("href", pdfUrl);
                                $("#pdfActionBar").show();

                                if (res.is_signed) {
                                    $("#printPdfBtn").prop("disabled", false)
                                        .attr("title", "Print this prescription");
                                } else {
                                    $("#printPdfBtn").prop("disabled", true)
                                        .attr("title", "Printing is only available once the doctor has signed the prescription");
                                }

                                Swal.fire('Success', successMsg, 'success').then(() => location.reload());

                            } else {
                                // No PDF to show — safe to reload as before.
                                Swal.fire('Success', successMsg, 'success')
                                    .then(() => location.reload());
                            }

                        } else {
                            Swal.fire('Error', res.message, 'error');
                        }
                    },
                    'json'
                );
            }
        });
    });

    $("#printPdfBtn").click(function () {
        if ($(this).prop("disabled")) return;
        let win = window.open($("#viewPdfBtn").attr("href"), "_blank");
        if (win) {
            win.onload = function () { win.print(); };
        }
    });

</script>