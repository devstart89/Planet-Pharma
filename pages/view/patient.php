<?php
    session_start();
    require '../../config/db.php';

    /* ================= AUTH ================= */
    if (!isset($_SESSION['user']) ||
        !in_array($_SESSION['user']['role'], ['health_facility','super_admin', 'doctor'])) {
        header("Location: ../../../index.php");
        exit;
    }

    $user = $_SESSION['user'];
    $isHealthFacility = $user['role'] === 'health_facility';
    $isSuper_Admin = $user['role'] === 'super_admin';
    $isDoctor = $user['role'] === 'doctor';

    $showForbiddenModal = !$isHealthFacility && !$isSuper_Admin;
    if ($isHealthFacility) {
        $dashboardUrl = '../health_facility/';
    }
    if ($isSuper_Admin) {
        $dashboardUrl = '../super_admin/';
    }
    if ($isHealthFacility) {
        $listUrl = '../list/patient.php';
    }
    if ($isSuper_Admin) {
        $listUrl = '../list/patient.php';
    }
    if ($isDoctor) {
        $listUrl = '../list/patient.php';
    }
    /* ================= VALIDATE PATIENT ================= */
    if (empty($_GET['id']) || !ctype_digit($_GET['id'])) {
        exit('Invalid patient ID.');
    }

    $patientId = (int) $_GET['id'];

    /* ================= FETCH PATIENT ================= */
    // BUG FIX: added last_medical_consult (was never selected before,
    // so it couldn't be displayed no matter what).
    $stmt = $conn->prepare("
        SELECT p.*, hf.facility_name
        FROM patients p
        LEFT JOIN health_facilities hf ON hf.id = p.facility_id
        WHERE p.id = ? AND p.is_deleted = 0
    ");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        exit('Patient not found.');
    }

    date_default_timezone_set('Asia/Manila');

    $lastConsultDisplay = !empty($patient['last_medical_consult'])
        ? (new DateTime($patient['last_medical_consult']))->format('M j, Y g:i A')
        : 'No consultation yet';

    // BUG FIX: $patient['house_address'] does not exist in the patients
    // schema (confirmed elsewhere: house_no_street + barangay). The old
    // version of this page rendered a PHP warning and a blank Address.
    $addressDisplay = trim(
        ($patient['house_no_street'] ?? '') . ', ' . ($patient['barangay'] ?? ''),
        ', '
    );
    if ($addressDisplay === '') $addressDisplay = 'N/A';

    /* ================= FETCH PRESCRIPTIONS ================= */
    $presStmt = $conn->prepare("
        SELECT 
            pr.id,
            pr.prescription_number,
            pr.diagnosis,
            pr.status,
            pr.created_at,
            CONCAT(dc.first_name,' ',dc.last_name) AS doctor_name
        FROM prescriptions pr
        LEFT JOIN users dc ON dc.id = pr.doctor_id
        WHERE pr.patient_id = ?
        ORDER BY pr.created_at DESC
    ");
    $presStmt->execute([$patientId]);
    $prescriptions = $presStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================= SPLIT DATA ================= */
    $history = $prescriptions;
    $current = !empty($prescriptions) ? [$prescriptions[0]] : [];

?>

<?php include '../../includes/header.php'; ?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">

<style>
    /* ===== Shared rx-* visual language, consistent with Prescription
       Details / Create Prescription pages ===== */
    :root {
        --rx-border: #e6e8eb;
        --rx-muted: #667085;
        --rx-muted-light: #98a2b3;
    }

    .rx-header-bar h4 { font-weight: 700; color: #1d2939; margin-bottom: 0; }

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
    .nav-tabs .nav-link {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--rx-muted);
    }
    .nav-tabs .nav-link.active {
        color: #0d6efd;
    }
    table.dataTable thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: #667085;
        font-weight: 700;
        white-space: nowrap;
    }
    table.dataTable tbody td {
        vertical-align: middle;
        font-size: 0.9rem;
    }
    table.dataTable tbody tr:hover {
        background-color: #f9fafb;
    }

    /* Pill-style badges, matching the Patient List page */
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-weight: 600;
        font-size: 0.8rem;
        padding: .35rem .7rem;
        border-radius: 0.5rem;
        white-space: nowrap;
    }
    .status-pill-success { background:#ecfdf3; color:#027a48; border:1px solid #abefc6; }
    .status-pill-warning { background:#fffaeb; color:#b54708; border:1px solid #fedf89; }
    .status-pill-danger  { background:#fef3f2; color:#b42318; border:1px solid #fecdca; }
    .status-pill-secondary { background:#f2f4f7; color:#344054; border:1px solid #eaecf0; }
    .date-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: #f9fafb;
        color: #475467;
        border: 1px solid #eaecf0;
        font-weight: 500;
        font-size: 0.8rem;
        padding: .35rem .65rem;
        border-radius: 0.5rem;
        white-space: nowrap;
    }
    .empty-state {
        text-align: center;
        color: #98a2b3;
        padding: 2rem 1rem;
    }
    .empty-state i { font-size: 1.75rem; display: block; margin-bottom: .5rem; }
</style>

<!-- Page Title -->
    <div class="page-title">
      <nav class="breadcrumbs">
        <div class="container">
          <ol>
            <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
            <li><a href="<?= $listUrl ?>">Patient List</a></li>
            <li class="current">Patient Details</li>
          </ol>
        </div>
      </nav>
    </div><!-- End Page Title -->

    <section class="doctors section" id="doctors">
        <div class="container" data-aos="fade-up" data-aos-delay="100">

            <div class="d-flex justify-content-between align-items-center mb-4 rx-header-bar">
                <h4>Patient Details</h4>
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <div class="row g-3">

                <!-- LEFT SIDE -->
                <div class="col-lg-4 col-md-12 col-sm-12" data-aos="fade-right" data-aos-delay="150">
                    <div class="rx-patient-card">

                        <div class="d-flex align-items-center gap-2 mb-1">
                            <i class="bi bi-person-circle fs-4 text-primary"></i>
                            <span class="rx-patient-name">
                                <?= htmlspecialchars($patient['last_name'].', '.$patient['first_name']) ?>
                            </span>
                        </div>

                        <span class="rx-section-label">Demographic Profile</span>
                        <div class="rx-demo-grid">
                            <p><strong>MCN:</strong> <?= htmlspecialchars($patient['member_card_no']) ?></p>
                            <p><strong>Full Name:</strong>
                                <?= htmlspecialchars(
                                    $patient['last_name'].', '.
                                    $patient['first_name'].' '.
                                    $patient['middle_name']
                                ) ?>
                            </p>
                            <p><strong>Gender:</strong> <?= htmlspecialchars($patient['gender']) ?></p>
                            <p><strong>Age:</strong> <?= htmlspecialchars($patient['age']) ?></p>
                            <p><strong>Birthday:</strong> <?= htmlspecialchars($patient['birthday']) ?></p>
                            <p><strong>Contact:</strong> <?= htmlspecialchars($patient['contact_number']) ?></p>
                            <p><strong>Address:</strong> <?= htmlspecialchars($addressDisplay) ?></p>
                            <p><strong>Clinic:</strong> <?= htmlspecialchars($patient['facility_name'] ?? 'N/A') ?></p>
                            <p class="mb-0">
                                <strong>Last Medical Consult:</strong>
                                <span><?= htmlspecialchars($lastConsultDisplay) ?></span>
                            </p>
                        </div>

                    </div>
                </div>

                <!-- RIGHT SIDE -->
                <div class="col-lg-8 col-md-12 col-sm-12" data-aos="fade-left" data-aos-delay="200">
                    <div class="rx-medicines-card">

                        <h5><i class="bi bi-capsule me-2"></i>Medication Records</h5>

                        <ul class="nav nav-tabs mb-3">
                            <li class="nav-item">
                                <button class="nav-link active"
                                        data-bs-toggle="tab"
                                        data-bs-target="#currentTab">
                                    <i class="bi bi-clipboard2-pulse me-1"></i>Current Medication
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link"
                                        data-bs-toggle="tab"
                                        data-bs-target="#historyTab">
                                    <i class="bi bi-clock-history me-1"></i>History
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">

                            <div class="tab-pane fade show active table-responsive" id="currentTab">
                                <?php $tableData = $current; $forceCollapseAllExceptAction = false; include 'prescription_table.php'; ?>
                            </div>

                            <div class="tab-pane fade table-responsive" id="historyTab">
                                <?php $tableData = $history; $forceCollapseAllExceptAction = false; include 'prescription_table.php'; ?>
                            </div>

                        </div>

                    </div>
                </div>

            </div>
        </div>
    </section>

<?php include '../../includes/footer.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
    function initDataTables() {

        $('.datatable').each(function() {

            if (!$.fn.DataTable.isDataTable(this)) {

                $(this).DataTable({
                    responsive: true,
                    pageLength: 5,
                    lengthChange: false,
                    order: [[4, 'desc']],
                    columnDefs: [{ orderable: false, targets: -1 }],
                    language: {
                        emptyTable: '<div class="empty-state"><i class="bi bi-inbox"></i>No prescriptions found.</div>'
                    },
                    drawCallback: function () {
                        $('[data-bs-toggle="tooltip"]').each(function () {
                            let existing = bootstrap.Tooltip.getInstance(this);
                            if (existing) existing.dispose();
                            new bootstrap.Tooltip(this);
                        });
                    }
                });

            }

        });
    }

    $(document).ready(function(){
        initDataTables();
    });

    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
        initDataTables();
    });
</script>