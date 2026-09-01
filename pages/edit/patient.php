<?php
    session_start();
    require '../../config/db.php';
    
    /* ---------- AUTH ---------- */
    if (
        !isset($_SESSION['user']) ||
        !in_array($_SESSION['user']['role'], ['health_facility', 'doctor', 'super_admin'])
    ) {
        header("Location: ../../index.php");
        exit;
    }
    
    $user = $_SESSION['user'];
    $facility_id = $user['facility_id'];
    $isDoctor = $user['role'] === 'doctor';
    
    $dashboardUrl = $isDoctor
        ? '../doctor/'
        : '../health_facility/';
    
    $listUrl = '../list/patient.php';
    $patientId = filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );
    
    if (!$patientId) {
        header("Location: {$listUrl}");
        exit;
    }
    
    /* ==========================================================
       LOAD PATIENT
       NOTE: this intentionally loads a patient regardless of
       facility_id (the restriction is commented out below), so
       the UPDATE query further down must not restrict by
       facility_id either — otherwise it can match 0 rows and
       silently save nothing. See the UPDATE block for details.
    ========================================================== */
    
    $sql = "
        SELECT *
        FROM patients
        WHERE id = ?
    ";
    
    $params = [$patientId];
    
    // if (!$isSuperAdmin) {
    //     $sql .= " AND facility_id = ?";
    //     $params[] = $facility_id;
    // }
    
    $sql .= " LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        die("Patient not found.");
    }
    
    /* ---------- UPDATE ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
        try {
    
            /* ================= MATCH ADD PATIENT INPUT STYLE ================= */
    
            $last_name     = trim($_POST['last_name'] ?? '');
            $first_name    = trim($_POST['first_name'] ?? '');
            $middle_name   = trim($_POST['middle_name'] ?? '');
            $email         = trim($_POST['email'] ?? '');
    
            $gender        = $_POST['gender'] ?? '';
            $birthday      = $_POST['birthday'] ?? '';
    
            /*
             * CONTACT NUMBER
             * No longer required on the form (matches add/patient.php).
             * Normalize blank to NULL instead of throwing.
             */
            $contact       = trim($_POST['contact_number'] ?? '');
            $civil_status  = $_POST['civil_status'] ?? '';
    
            $house_address = trim($_POST['house_no_street'] ?? '');
            $cluster       = $_POST['cluster'] ?? '';
            $barangay      = trim($_POST['barangay'] ?? '');

            /*
             * HEALTH CENTER / BARANGAY RESOLUTION
             * The "Health Center" field still posts into the `barangay` column
             * (existing convention — do not rename without a migration).
             * It's now a free-text autocomplete input (backed by a <datalist>
             * of known health centers), so "Others" is typed/selected the
             * same way any other option would be. When it's "Others", we use
             * the free-text `barangay_other` field instead, and there is no
             * applicable cluster.
             */
            $isOthersBarangay = (strtolower($barangay) === 'others');
            $barangay = $isOthersBarangay
                ? trim($_POST['barangay_other'] ?? '')
                : $barangay;
            $cluster  = $isOthersBarangay ? '' : trim($cluster);

            if ($barangay === '') {
                throw new Exception("Please specify the Barangay.");
            }
    
    
    
            /*
             * MEMBER CARD NUMBER
             * The field is rendered readonly on this form (it's not meant
             * to be edited here), so if it happens to be blank on an
             * existing patient there is no way for the user to fix that
             * from this page. It must NOT be a required field — otherwise
             * the update is rejected with no way for the user to resolve
             * it, which silently blocks saving anything else on the form.
             */
            $member_card_no = trim($_POST['member_card_no'] ?? '');
            $makati_no     = trim($_POST['makati_health_plus_no'] ?? '');
            $priority_type = $_POST['priority_type'] ?? '';

            /*
             * MAKATI HEALTH PLUS NO. FORMAT
             * Must match the SAME format enforced by add/patient.php and
             * the CSV importer (upload_template.php): 2XXX-XXXXXXX — a
             * literal "2", 3 more digits, a dash, then 7 digits. This was
             * previously checked against a 6-digit-after-dash pattern here,
             * which meant a patient added correctly through Add or CSV
             * upload (7 digits) would fail re-validation the moment you
             * tried to save them again through this Edit page — even
             * without touching the field.
             */
            if ($makati_no !== '' && !preg_match('/^2\d{3}-\d{7}$/', $makati_no)) {
                throw new Exception("Invalid Makati Health Plus No. format (expected 2XXX-XXXXXXX).");
            }
    
            /* ================= ACCOUNT TYPE ================= */
    
            $account_type  = $_POST['account_type'] ?? '';
            $member_type  = $_POST['member_type'] ?? '';
            $yellow_card   = trim($_POST['yellow_card_no'] ?? '');
    
            /* ================= EMPLOYEE / DEPARTMENT / EMPLOYMENT =================
             * IMPORTANT: makati_employee was previously missing from the
             * UPDATE statement entirely, so toggling "Makati City
             * Government Employee" from No to Yes (or vice versa) never
             * actually saved — everything else on the form would save,
             * but this flag silently reverted on reload, which in turn
             * hid the Employee Details section again (since its
             * visibility is driven by this same value) and made it look
             * like Department hadn't saved either.
             */
            $makati_employee = ($_POST['makati_employee'] ?? 'NO') === 'YES' ? 'YES' : 'NO';
            $department      = trim($_POST['department'] ?? '');
            $employment_type = $_POST['employment_type'] ?? '';

            if ($makati_employee === 'YES') {
                if ($department === '' || $employment_type === '') {
                    throw new Exception("Department & Employment Type are required for Makati City Government employees.");
                }
            } else {
                // Not an employee — don't carry over stale employee-only values.
                $department = '';
                $employment_type = '';
            }
    
            /* ================= VALIDATION (MATCH ADD PATIENT) ================= */
    
            if (
                !$last_name ||
                !$first_name ||
                !$gender ||
                !$birthday ||
                !$civil_status ||
                !$house_address ||
                !$barangay ||
                !$priority_type
            ) {
                throw new Exception("Please complete all required fields.");
            }
    
            if ($birthday > date('Y-m-d')) {
                throw new Exception("Birthday cannot be in the future.");
            }
    
            /* ================= AGE ================= */
    
            $age = (new DateTime())->diff(new DateTime($birthday))->y;
    
            /* ================= DUPLICATE CHECK (SAME AS ADD) ================= */
    
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM patients
                WHERE first_name = ?
                AND last_name = ?
                AND birthday = ?
                AND id != ?
            ");
    
            $stmt->execute([
                $first_name,
                $last_name,
                $birthday,
                $patientId
            ]);
    
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Patient already exists.");
            }
    
            /* ================= UPDATE (MATCH ADD ORDER STYLE) =================
             * NOTE: the SELECT above deliberately loads a patient regardless
             * of facility_id (see comment there), so this UPDATE must not
             * filter by facility_id either. Previously it did — which meant
             * that whenever the logged-in user's facility_id didn't match
             * the patient's facility_id, the UPDATE matched 0 rows and
             * silently saved nothing, even though the page redirected as
             * if it succeeded.
             */
    
            $stmt = $conn->prepare("
                UPDATE patients SET
    
                    last_name = ?,
                    first_name = ?,
                    middle_name = ?,
                    email = ?,
    
                    gender = ?,
                    birthday = ?,
                    age = ?,
    
                    contact_number = ?,
                    civil_status = ?,
    
                    house_no_street = ?,
                    cluster = ?,
                    barangay = ?,
    
                    makati_employee = ?,
                    department = ?,
                    employment_type = ?,
    
                    member_card_no = ?,
                    makati_health_plus_no = ?,
                    yellow_card = ?,
                    account_type = ?,
                    priority_type = ?,
                    member_type = ?
    
                WHERE id = ?
            ");
    
            $stmt->execute([
                $last_name,
                $first_name,
                $middle_name ?: null,
                $email ?: null,
    
                $gender,
                $birthday,
                $age,
    
                $contact ?: null,
                $civil_status,
    
                $house_address,
                $cluster,
                $barangay,
    
                $makati_employee,
                $department ?: null,
                $employment_type ?: null,
    
                $member_card_no ?: null,
                $makati_no ?: null,
                $yellow_card ?: null,
                $account_type,
                $priority_type,
                $member_type,
    
                $patientId
            ]);
    
            /*
             * Don't treat "0 rows changed" as a failure — resubmitting with
             * identical values legitimately updates 0 rows. Instead confirm
             * the patient row still exists; only fail if it genuinely
             * doesn't.
             */
            $verifyStmt = $conn->prepare("SELECT COUNT(*) FROM patients WHERE id = ?");
            $verifyStmt->execute([$patientId]);
    
            if ($verifyStmt->fetchColumn() == 0) {
                throw new Exception("Patient not found.");
            }
    
            /* ================= RELOAD ================= */
    
            $stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt->execute([$patientId]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
            $_SESSION['success'] = "Patient updated successfully.";
    
            header("Location: ../list/patient.php");
            exit;
    
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
    
    /* ---------- LOAD ADDRESS CLUSTERS ---------- */
    
    $clusters = [];
    
    $stmt = $conn->query("SELECT id, cluster_name FROM clusters ORDER BY cluster_name ASC");
    
    $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ---------- LOAD HEALTH CENTERS (with their cluster, for auto-fill) ----------
     * Health Center drives the form. Its cluster is looked up client-side
     * from data-cluster-name on each <option> inside the <datalist>, so no
     * AJAX round-trip is needed. Mirrors add/patient.php, but as a
     * free-text autocomplete input instead of a <select>.
     */
    $healthCenters = [];

    $hcStmt = $conn->query("
        SELECT hf.id, hf.name, hf.cluster_id, c.cluster_name
        FROM hf_description hf
        LEFT JOIN clusters c ON hf.cluster_id = c.id
        ORDER BY c.cluster_name ASC, hf.name ASC
    ");

    $healthCenters = $hcStmt->fetchAll(PDO::FETCH_ASSOC);

    // If the patient's saved Health Center isn't one of the known ones,
    // treat it as a custom "Others" entry so it isn't lost on the form.
    $knownHealthCenterNames = array_column($healthCenters, 'name');
    $isPatientOthers = !in_array($patient['barangay'], $knownHealthCenterNames, true);

    /* ---------- LOAD DEPARTMENTS ----------
     * Department is a free-text autocomplete input (backed by a
     * <datalist>), sourced from its own `department` table — matches
     * add/patient.php.
     */
    $departments = [];

    $deptStmt = $conn->query("
        SELECT department_id, department_name
        FROM department
        ORDER BY department_name ASC
    ");

    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

    include '../../includes/header.php';
?>
<?php if (!empty($_SESSION['error'])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire('Error', <?= json_encode($_SESSION['error']) ?>, 'error');
});
</script>
<?php unset($_SESSION['error']); endif; ?>
<style>
    /* INVALID (RED) */
    .is-invalid {
        border: 1px solid #e27983 !important;
        background: #fff5f5;
    }

    /* VALID (GREEN) */
    .is-valid {
        border: 1px solid #28a745 !important;
        background: #f0fff4;
    }
</style>
<div class="page-title">
    <nav class="breadcrumbs">
        <div class="container">
            <ol>
                <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
                <li><a href="<?= $listUrl ?>">Patient List</a></li>
                <li class="current">Edit Patient</li>
            </ol>
        </div>
    </nav>
</div>
<section class="section">
    <div class="container p-4">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>Edit Patient</h4>

            <div class="d-flex gap-2 flex-wrap">

                <a href="javascript:history.back()"
                   class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>

            </div>
        </div>

        <form method="POST" id="patientForm">
        
            <!-- =======================================================
                PERSONAL INFORMATION
            ======================================================== -->
        
            <div class="card shadow-sm border-0 mb-4">
        
                <div class="card-header bg-white py-3">
        
                    <div class="d-flex justify-content-between align-items-center">
        
                        <div>
        
                            <h5 class="mb-0 fw-semibold">
                                <i class="bi bi-person-vcard me-2"></i>
                                Personal Information
                            </h5>
        
                            <small class="text-muted">
                                Update the patient's personal details.
                            </small>
        
                        </div>
        
                        <!--<span class="badge text-bg-light border">-->
                        <!--    Member No.-->
                        <!--    <?= htmlspecialchars($patient['member_card_no']) ?>-->
                        <!--</span>-->
        
                    </div>
        
                </div>
        
                <div class="card-body">
        
                    <div class="row g-4">
        
                        <!-- Last Name -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                Last Name
        
                                <span class="text-danger">*</span>
        
                            </label>
        
                            <input
                                type="text"
                                class="form-control"
                                name="last_name"
                                value="<?= htmlspecialchars($patient['last_name']) ?>"
                                required>
        
                        </div>
        
                        <!-- First Name -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                First Name
        
                                <span class="text-danger">*</span>
        
                            </label>
        
                            <input
                                type="text"
                                class="form-control"
                                name="first_name"
                                value="<?= htmlspecialchars($patient['first_name']) ?>"
                                required>
        
                        </div>
        
                        <!-- Middle Name -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                Middle Name
        
                            </label>
        
                            <input
                                type="text"
                                class="form-control"
                                name="middle_name"
                                value="<?= htmlspecialchars($patient['middle_name']) ?>">
        
                        </div>
        
                        <!-- Email -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                Email Address
        
                            </label>
        
                            <input
                                type="email"
                                class="form-control"
                                name="email"
                                value="<?= htmlspecialchars($patient['email']) ?>">
        
                        </div>
        
                        <!-- Gender -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                Gender
        
                                <span class="text-danger">*</span>
        
                            </label>
        
                            <div class="d-flex gap-4 mt-2">
        
                                <div class="form-check">
        
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        id="genderMale"
                                        name="gender"
                                        value="MALE"
                                        <?= $patient['gender']=='MALE' ? 'checked' : '' ?>>
        
                                    <label
                                        class="form-check-label"
                                        for="genderMale">
        
                                        Male
        
                                    </label>
        
                                </div>
        
                                <div class="form-check">
        
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        id="genderFemale"
                                        name="gender"
                                        value="FEMALE"
                                        <?= $patient['gender']=='FEMALE' ? 'checked' : '' ?>>
        
                                    <label
                                        class="form-check-label"
                                        for="genderFemale">
        
                                        Female
        
                                    </label>
        
                                </div>
        
                            </div>
        
                        </div>
        
                        <!-- Birthday -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                Date of Birth
        
                                <span class="text-danger">*</span>
        
                            </label>
        
                            <input
                                type="date"
                                class="form-control"
                                id="birthday"
                                name="birthday"
                                value="<?= htmlspecialchars($patient['birthday']) ?>"
                                required>
        
                        </div>
        
                        <!-- Contact (OPTIONAL — no longer required, matches add/patient.php) -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                Contact Number
        
                            </label>
        
                            <input
                                type="text"
                                class="form-control"
                                id="contact_number"
                                name="contact_number"
                                value="<?= htmlspecialchars($patient['contact_number']) ?>">
        
                        </div>
        
                        <!-- Civil Status -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                Civil Status
        
                                <span class="text-danger">*</span>
        
                            </label>
        
                            <select
                                class="form-select"
                                name="civil_status"
                                required>
        
                                <option value="">
                                    Select Civil Status
                                </option>
        
                                <?php
                                $statuses = [
                                    'SINGLE',
                                    'MARRIED',
                                    'WIDOWED',
                                    'SEPARATED'
                                ];
        
                                foreach ($statuses as $status):
                                ?>
        
                                    <option
                                        value="<?= $status ?>"
                                        <?= $patient['civil_status'] == $status ? 'selected' : '' ?>>
        
                                        <?= ucfirst(strtolower($status)) ?>
        
                                    </option>
        
                                <?php endforeach; ?>
        
                            </select>
        
                        </div>
        
                    </div>
        
                </div>
        
            </div>
            
            <!-- =======================================================
                ADDRESS INFORMATION
            ======================================================= -->
            
            <div class="card shadow-sm border-0 mb-4">
            
                <div class="card-header bg-white py-3">
            
                    <div class="d-flex align-items-center">
            
                        <i class="bi bi-geo-alt me-2"></i>
            
                        <div>
            
                            <h5 class="mb-0 fw-semibold">
                                Address Information
                            </h5>
            
                            <small class="text-muted">
                                Update the patient's residential address and assigned health center.
                            </small>
            
                        </div>
            
                    </div>
            
                </div>
            
                <div class="card-body">
            
                    <div class="row g-4">
            
                        <!-- House No. & Street -->
            
                        <div class="col-lg-12">
            
                            <label class="form-label fw-semibold">
            
                                House No. / Street / Subdivision
            
                                <span class="text-danger">*</span>
            
                            </label>
            
                            <input
                                type="text"
                                class="form-control"
                                name="house_no_street"
                                value="<?= htmlspecialchars($patient['house_no_street']) ?>"
                                required>
            
                        </div>
            
                        <!-- Health Center (autocomplete text input — selecting/typing a known
                             name auto-fills Cluster below; type "Others" for outside Makati) -->

                        <div class="col-lg-6 col-md-6">

                            <label class="form-label fw-semibold">

                                Health Center

                                <span class="text-danger">*</span>

                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="barangay"
                                name="barangay"
                                list="healthCenterList"
                                autocomplete="off"
                                placeholder="Type to search Health Center..."
                                value="<?= $isPatientOthers ? 'Others' : htmlspecialchars($patient['barangay']) ?>"
                                required>

                            <datalist id="healthCenterList">

                                <?php foreach ($healthCenters as $hc): ?>

                                    <option
                                        value="<?= htmlspecialchars($hc['name']) ?>"
                                        data-cluster-name="<?= htmlspecialchars($hc['cluster_name'] ?? '') ?>">

                                <?php endforeach; ?>

                                <option value="Others" data-cluster-name="">

                            </datalist>

                            <small class="text-muted">Start typing to search, or enter "Others" if outside Makati City.</small>

                        </div>

                        <!-- Cluster (auto-filled from the selected Health Center) -->

                        <div class="col-lg-6 col-md-6">

                            <label class="form-label fw-semibold">

                                Cluster

                                <span class="text-danger">*</span>

                            </label>

                            <select
                                id="cluster"
                                class="form-select"
                                disabled>

                                <option value="">
                                    
                                </option>

                                <?php foreach ($clusters as $c): ?>

                                    <option
                                        value="<?= htmlspecialchars($c['cluster_name']) ?>"
                                        data-id="<?= $c['id'] ?>"
                                        <?= (!$isPatientOthers && $patient['cluster'] == $c['cluster_name']) ? 'selected' : '' ?>>

                                        <?= htmlspecialchars($c['cluster_name']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>
                            <input type="hidden" name="cluster" id="clusterHidden" value="<?= $isPatientOthers ? '' : htmlspecialchars($patient['cluster']) ?>">

                            <small class="text-muted">Automatically filled based on the selected Health Center — cannot be edited manually.</small>

                        </div>

                        <!-- Barangay (free text) — only shown when Health Center = "Others" -->

                        <div class="col-lg-12 <?= $isPatientOthers ? '' : 'd-none' ?>" id="barangayOtherWrap">

                            <label class="form-label fw-semibold">

                                Barangay

                                <span class="text-danger">*</span>

                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="barangayOther"
                                name="barangay_other"
                                placeholder="Enter Barangay (outside Makati City)"
                                value="<?= $isPatientOthers ? htmlspecialchars($patient['barangay']) : '' ?>"
                                <?= $isPatientOthers ? 'required' : '' ?>>

                        </div>
            
                    </div>
            
                </div>
            
            </div>
            
            <!-- =======================================================
                EMPLOYMENT INFORMATION
            ======================================================= -->
            
            <div class="card shadow-sm border-0 mb-4">
            
                <div class="card-header bg-white py-3">
            
                    <div class="d-flex align-items-center">
            
                        <i class="bi bi-briefcase me-2"></i>
            
                        <div>
            
                            <h5 class="mb-0 fw-semibold">
                                Employment Information
                            </h5>
            
                            <small class="text-muted">
                                Complete this section only if the patient is a Makati City Government employee.
                            </small>
            
                        </div>
            
                    </div>
            
                </div>
            
                <div class="card-body">
            
                    <!-- Employee Option -->
            
                    <div class="row align-items-center mb-4">
            
                        <div class="col-lg-4">
            
                            <label class="form-label fw-semibold mb-0">
                                Makati City Government Employee
                            </label>
            
                        </div>
            
                        <div class="col-lg-8">
            
                            <div class="d-flex gap-4">
            
                                <div class="form-check">
            
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        id="emp_yes"
                                        name="makati_employee"
                                        value="YES"
                                        <?= $patient['makati_employee'] == 'YES' ? 'checked' : '' ?>>
            
                                    <label
                                        class="form-check-label"
                                        for="emp_yes">
            
                                        Yes
            
                                    </label>
            
                                </div>
            
                                <div class="form-check">
            
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        id="emp_no"
                                        name="makati_employee"
                                        value="NO"
                                        <?= $patient['makati_employee'] != 'YES' ? 'checked' : '' ?>>
            
                                    <label
                                        class="form-check-label"
                                        for="emp_no">
            
                                        No
            
                                    </label>
            
                                </div>
            
                            </div>
            
                        </div>
            
                    </div>
            
                    <!-- Employee Details -->
            
                    <div
                        id="employeeSection"
                        class="border rounded-3 p-4 bg-light <?= $patient['makati_employee'] == 'YES' ? '' : 'd-none' ?>">
            
                        <div class="mb-3">
            
                            <h6 class="fw-semibold mb-1">
            
                                Employee Details
            
                            </h6>
            
                            <small class="text-muted">
            
                                Update the employee information below.
            
                            </small>
            
                        </div>
            
                        <div class="row g-4">
            
                            <!-- Department (searchable — sourced from `department` table, matches add/patient.php) -->
            
                            <div class="col-lg-6">
            
                                <label class="form-label fw-semibold">
            
                                    Department
            
                                    <span class="text-danger">*</span>
            
                                </label>
            
                                <input
                                    type="text"
                                    class="form-control"
                                    id="department"
                                    name="department"
                                    list="departmentList"
                                    autocomplete="off"
                                    placeholder="Type to search Department..."
                                    value="<?= htmlspecialchars($patient['department']) ?>">

                                <datalist id="departmentList">

                                    <?php foreach ($departments as $d): ?>

                                        <option value="<?= htmlspecialchars($d['department_name']) ?>">

                                    <?php endforeach; ?>

                                </datalist>

                                <small class="text-muted">Start typing to search for a Department.</small>
            
                            </div>
            
                            <!-- Employment Type -->
            
                            <div class="col-lg-6">
            
                                <label class="form-label fw-semibold">
            
                                    Status of Appointment
            
                                    <span class="text-danger">*</span>
            
                                </label>
            
                                <select
                                    id="employment_type"
                                    name="employment_type"
                                    class="form-select">
            
                                    <option value="">
                                        Select Status
                                    </option>
            
                                    <option
                                        value="JOB ORDER"
                                        <?= $patient['employment_type'] == 'JOB ORDER' ? 'selected' : '' ?>>
            
                                        JOB ORDER
            
                                    </option>
            
                                    <option
                                        value="CASUAL"
                                        <?= $patient['employment_type'] == 'CASUAL' ? 'selected' : '' ?>>
            
                                        CASUAL
            
                                    </option>
            
                                    <option
                                        value="REGULAR"
                                        <?= $patient['employment_type'] == 'REGULAR' ? 'selected' : '' ?>>
            
                                        REGULAR
            
                                    </option>
            
                                    <option
                                        value="CONTRACTUAL"
                                        <?= $patient['employment_type'] == 'CONTRACTUAL' ? 'selected' : '' ?>>
            
                                        CONTRACTUAL
            
                                    </option>
            
                                </select>
            
                            </div>
            
                        </div>
            
                    </div>
            
                </div>
            
            </div>
            
            <!-- =======================================================
                MEMBERSHIP INFORMATION
            ======================================================= -->
            
            <div class="card shadow-sm border-0 mb-4">
            
                <div class="card-header bg-white py-3">
            
                    <div class="d-flex align-items-center">
            
                        <i class="bi bi-person-badge me-2"></i>
            
                        <div>
            
                            <h5 class="mb-0 fw-semibold">
                                Membership Information
                            </h5>
            
                            <small class="text-muted">
                                Update the patient's membership and healthcare program details.
                            </small>
            
                        </div>
            
                    </div>
            
                </div>
            
                <div class="card-body">
            
                    <div class="row g-4">
            
                        <!-- Member Card (readonly — cannot be edited from this form, and
                             is no longer required, since a blank card number could
                             otherwise block saving every other change) -->
            
                        <div class="col-lg-4 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Member Card Number
            
                            </label>
            
                            <input
                                type="text"
                                class="form-control bg-light"
                                name="member_card_no"
                                value="<?= htmlspecialchars($patient['member_card_no']) ?>"
                                readonly>
            
                        </div>
            
                        <!-- Account Type -->
            
                        <div class="col-lg-2 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Account Type
            
                            </label>
            
                            <select
                                name="account_type"
                                id="account_type"
                                class="form-select">
            
                                <option
                                    value="YC"
                                    <?= $patient['account_type'] == 'YC' ? 'selected' : '' ?>>
            
                                    YC
            
                                </option>
            
                                <option
                                    value="MC"
                                    <?= $patient['account_type'] == 'MC' ? 'selected' : '' ?>>
            
                                    MC
            
                                </option>
            
                            </select>
            
                        </div>
            
                        <!-- Health Plus -->
            
                        <div class="col-lg-3 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Makati Health Plus No.
            
                            </label>
            
                            <input
                                type="text"
                                class="form-control"
                                id="health_no"
                                name="makati_health_plus_no"
                                maxlength="12"
                                value="<?= htmlspecialchars($patient['makati_health_plus_no']) ?>"
                                placeholder="2021-1234567">
            
                        </div>
            
                        <!-- Priority -->
            
                        <div class="col-lg-3 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Priority Type
            
                            </label>
            
                            <select
                                id="priority_type"
                                name="priority_type"
                                class="form-select">
            
                                <option value="None"
                                    <?= $patient['priority_type'] == 'None' ? 'selected' : '' ?>>
                                    None
                                </option>
            
                                <option value="Pregnant"
                                    <?= $patient['priority_type'] == 'Pregnant' ? 'selected' : '' ?>>
                                    Pregnant
                                </option>
            
                                <option value="PWD"
                                    <?= $patient['priority_type'] == 'PWD' ? 'selected' : '' ?>>
                                    PWD
                                </option>
            
                                <option value="Senior Citizen"
                                    <?= $patient['priority_type'] == 'Senior Citizen' ? 'selected' : '' ?>>
                                    Senior Citizen
                                </option>
            
                            </select>
            
                        </div>
            
                        <!-- Member Type -->
            
                        <div class="col-lg-4 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Member Type
            
                            </label>
            
                            <select
                                id="member_type"
                                name="member_type"
                                class="form-select">
            
                                <option
                                    value="Card Holder"
                                    <?= $patient['member_type'] == 'Card Holder' ? 'selected' : '' ?>>
            
                                    Card Holder
            
                                </option>
            
                                <option
                                    value="Dependent"
                                    <?= $patient['member_type'] == 'Dependent' ? 'selected' : '' ?>>
            
                                    Dependent
            
                                </option>
            
                            </select>
            
                        </div>
                        
                        <div class="col-lg-4 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Author
            
                            </label>
            
                            <input
                                type="text"
                                class="form-control bg-light"
                                value="<?= htmlspecialchars($_SESSION['user']['name']) ?>"
                                readonly>
            
                        </div>
            
                    </div>
            
                </div>
            
        </div>
            
            <!-- =======================================================
                ACTIONS
            ======================================================= -->
            
            <div class="card shadow-sm border-0">
            
                <div class="card-body">
            
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
            
                        <small class="text-muted">
            
                            <span class="text-danger">*</span>
                            Required fields must be completed.
            
                        </small>
            
                        <div class="d-flex gap-2">
            
                            <a
                                href="patients.php"
                                class="btn btn-outline-secondary">
            
                                <i class="bi bi-arrow-left me-1"></i>
            
                                Back
            
                            </a>
            
                            <button
                                type="button"
                                id="submitBtn"
                                class="btn btn-primary px-4">
            
                                <i class="bi bi-pencil-square me-1"></i>
            
                                Update Patient
            
                            </button>
            
                        </div>
            
                    </div>
            
                </div>
            
            </div>
        
        </form>
            

    </div>
</section>


<div class="modal fade" id="uploadModal">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">Upload Patient Template</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="file" name="file" accept=".csv" class="form-control" required>
                </form>
            </div>

            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="uploadBtn">Upload</button>
            </div>

        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function () {

    /* ================= HEALTH CENTER (AUTOCOMPLETE) -> CLUSTER AUTO-FILL ================= */
    /*
     * Health Center is now a free-text input backed by a <datalist>
     * (id="healthCenterList"), instead of a <select>. On every keystroke
     * or selection we look for an exact (case-insensitive) match among
     * the known health centers to auto-fill Cluster; typing/selecting
     * "Others" reveals the free-text Barangay field instead.
     */

    const healthCenterOptions = $('#healthCenterList option').map(function () {
        return {
            name: $(this).attr('value'),
            cluster: $(this).data('cluster-name') || ''
        };
    }).get();

    function findHealthCenter(value) {
        const needle = value.trim().toLowerCase();
        return healthCenterOptions.find(function (hc) {
            return hc.name.toLowerCase() === needle;
        });
    }

    function syncHealthCenter() {

        const typed = $('#barangay').val();
        const isOthers = typed.trim().toLowerCase() === 'others';

        // Toggle the free-text Barangay field (outside Makati City)
        $('#barangayOtherWrap').toggleClass('d-none', !isOthers);
        $('#barangayOther').prop('required', isOthers);
        if (!isOthers) {
            $('#barangayOther').val('').removeClass('is-valid is-invalid');
        }

        const cluster = $('#cluster');
        const clusterHidden = $('#clusterHidden');

        if (!typed.trim() || isOthers) {
            cluster.val('').removeClass('is-valid is-invalid');
            clusterHidden.val('');
            return;
        }

        const match = findHealthCenter(typed);

        if (match && match.cluster) {
            cluster.val(match.cluster);
            clusterHidden.val(match.cluster);
            cluster.removeClass('is-invalid').addClass('is-valid');
            $('#barangay').removeClass('is-invalid').addClass('is-valid');
        } else {
            // No exact match yet (still typing, typo, or unmapped center)
            cluster.val('').removeClass('is-valid is-invalid');
            clusterHidden.val('');
        }
    }

    $('#barangay').on('input change', syncHealthCenter);

    /* Sync the Cluster / "Others" UI with the patient's saved value on load */
    syncHealthCenter();


    /* ================= DEPARTMENT (AUTOCOMPLETE) ================= */
    /*
     * Department is a free-text input backed by a <datalist>
     * (id="departmentList"), sourced from the `department` table.
     * Mirrors the Health Center pattern above and add/patient.php.
     */

    const departmentOptions = $('#departmentList option').map(function () {
        return $(this).attr('value');
    }).get();

    function findDepartment(value) {
        const needle = value.trim().toLowerCase();
        return departmentOptions.find(function (d) {
            return d.toLowerCase() === needle;
        });
    }

    $('#department').on('input change', function () {
        const val = $(this).val().trim();

        if (!$(this).prop('required')) {
            $(this).removeClass('is-valid is-invalid');
            return;
        }

        if (val && findDepartment(val)) {
            $(this).removeClass('is-invalid').addClass('is-valid');
        } else {
            $(this).removeClass('is-valid');
        }
    });

    /* =====================================================
       ACCOUNT TYPE TOGGLE
    ===================================================== */
        // Employee toggle
        const empYes = document.getElementById('emp_yes');
        const empNo = document.getElementById('emp_no');
        const section = document.getElementById('employeeSection');
        const dept = document.getElementById('department');
        const empType = document.getElementById('employment_type');

        function toggle(){
            if(empYes.checked){
                section.classList.remove('d-none');
                dept.setAttribute('required', true);
                empType.setAttribute('required', true);
            }else{
                section.classList.add('d-none');
                dept.removeAttribute('required');
                empType.removeAttribute('required');
            }
        }

        toggle();
        empYes.addEventListener('change', toggle);
        empNo.addEventListener('change', toggle);

    /* =====================================================
       DATE OF BIRTH
    ===================================================== */

    const today = new Date()
        .toISOString()
        .split('T')[0];

    $('#birthday').attr('max', today);


    /* =====================================================
       CONTACT FORMAT
    ===================================================== */

    $('#contact_number').on('input', function () {

        let value = $(this).val();

        value = value.replace(/\D/g, '');

        if (value.length > 11) {
            value = value.substring(0, 11);
        }

        $(this).val(value);
    });


    /* =====================================================
       MAKATI HEALTH PLUS NO. FORMAT
       Fixed: was capped at 10 raw digits (4 + 6), which made it
       IMPOSSIBLE to type the 7-digit-after-dash format required
       by add/patient.php and the CSV importer (2XXX-XXXXXXX = 11
       raw digits). Now allows up to 11 raw digits so the format
       actually matches everywhere else in the app.
    ===================================================== */

    $('#health_no').on('input', function () {

        let value = $(this).val().replace(/\D/g, '');
    
        // maximum of 11 digits (2021 + 1234567 = 2021-1234567)
        value = value.substring(0, 11);
    
        if (value.length > 4) {
            value =
                value.substring(0,4) +
                '-' +
                value.substring(4);
        }
    
        $(this).val(value);
    
    });


    /* =====================================================
       LIVE VALIDATION
    ===================================================== */

    $('#patientForm').on(
        'input change',
        'input, select, textarea',
        function () {

            const field = $(this);

            if (!field.prop('required')) {
                return;
            }

            if ($.trim(field.val()) !== '') {

                field
                    .removeClass('is-invalid')
                    .addClass('is-valid');

            } else {

                field
                    .removeClass('is-valid')
                    .addClass('is-invalid');

            }
        }
    );


    /* =====================================================
       VALIDATION
    ===================================================== */

    function validateForm() {

        let valid = true;
        let firstError = null;

        $('.is-invalid')
            .removeClass('is-invalid');

        $('#patientForm [required]').each(function () {

            const field = $(this);

            if ($.trim(field.val()) === '') {

                field.addClass('is-invalid');

                if (!firstError) {
                    firstError = field;
                }

                valid = false;
            }
        });

        if (!valid) {

            $('html, body').animate({
                scrollTop:
                    firstError.offset().top - 120
            }, 400);

            Swal.fire({
                icon: 'error',
                title: 'Missing Information',
                text: 'Please complete all required fields.'
            });

            return false;
        }

        /* Contact (OPTIONAL — only validated if the user filled it in) */

        const contactNo = $('#contact_number').val().trim();

        if (contactNo !== '' && !/^09\d{9}$/.test(contactNo)) {

            $('#contact_number')
                .addClass('is-invalid');

            Swal.fire({
                icon: 'error',
                title: 'Invalid Contact Number',
                text: 'Format must be 09XXXXXXXXX'
            });

            return false;
        }

        /* Birthday */

        const birthday =
            $('#birthday').val();

        if (birthday > today) {

            $('#birthday')
                .addClass('is-invalid');

            Swal.fire({
                icon: 'error',
                title: 'Invalid Birth Date',
                text: 'Future dates are not allowed.'
            });

            return false;
        }

        /* Health Center / Cluster */

        const typedBarangay = $('#barangay').val().trim();
        const isOthersBarangay = typedBarangay.toLowerCase() === 'others';

        if (!isOthersBarangay && !findHealthCenter(typedBarangay)) {

            $('#barangay').addClass('is-invalid');

            Swal.fire({
                icon: 'error',
                title: 'Invalid Health Center',
                text: 'Please choose a Health Center from the suggestions, or type "Others" if outside Makati City.'
            });

            return false;
        }

        if (!isOthersBarangay && !$('#clusterHidden').val()) {

            $('#cluster').addClass('is-invalid');

            Swal.fire({
                icon: 'warning',
                title: 'Missing Cluster',
                text: 'Please select a Health Center so the Cluster can be filled in.'
            });

            return false;
        }

        /* Department / Employment Type — required when employee = YES */

        if (empYes.checked) {

            const typedDept = $('#department').val().trim();

            if (!typedDept || !findDepartment(typedDept)) {

                $('#department').addClass('is-invalid');

                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Department',
                    text: 'Please choose a Department from the suggestions.'
                });

                return false;
            }

            const typedEmpType = $('#employment_type').val();

            if (!typedEmpType) {

                $('#employment_type').addClass('is-invalid');

                Swal.fire({
                    icon: 'error',
                    title: 'Missing Status',
                    text: 'Please select a Status of Appointment.'
                });

                return false;
            }
        }

        /*
         * MAKATI HEALTH PLUS NO. (OPTIONAL)
         * FIXED: previously required exactly 6 digits after the dash
         * (2\d{3}-\d{6}), while add/patient.php and the CSV importer
         * both require 7 digits (2\d{3}-\d{7}). That mismatch meant a
         * patient added correctly through Add or CSV upload would
         * fail this check the moment you tried to save them again
         * here — even without touching the field. Now matches
         * everywhere else in the app.
         */
        const healthNo = $('#health_no').val().trim();

        if (healthNo !== '') {

            if (!/^2\d{3}-\d{7}$/.test(healthNo)) {

                $('#health_no').addClass('is-invalid');

                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Number',
                    text: 'Format must be 2021-1234567'
                });

                return false;
            }

        }

        return true;
    }


    /* =====================================================
       UPDATE PATIENT
    ===================================================== */

    $('#submitBtn').on('click', function () {

        if (!validateForm()) {
            return;
        }

        Swal.fire({
            title: 'Confirm Update?',
            text: 'Patient information will be updated.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Update Patient'
        }).then((result) => {

            if (!result.isConfirmed) {
                return;
            }

            Swal.fire({
                title: 'Updating...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $('#submitBtn')
                .prop('disabled', true);

            $('#patientForm').submit();

        });

    });

});
</script>