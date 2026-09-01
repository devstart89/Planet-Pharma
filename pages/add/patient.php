<?php
session_start();
require '../../config/db.php';

/* ---------- AUTH ---------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility','doctor'])) {
    header("Location: ../../index.php");
    exit;
}

$user = $_SESSION['user'];
$facility_id = $user['facility_id'];
$isDoctor = $user['role'] === 'doctor';

$dashboardUrl = $isDoctor ? '../doctor/' : '../health_facility/';
$listUrl = '../list/patient.php';
$fromPrescription = ($_GET['from'] ?? '') === 'prescription';

/* ---------- GENERATE HIS ---------- */
function generateHIS($conn, $facility_id){
    $stmt = $conn->prepare("SELECT his_id FROM patients WHERE facility_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$facility_id]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);

    $num = $last ? intval(str_replace('HIS-','',$last['his_id'])) + 1 : 1;
    return "HIS-" . str_pad($num,6,'0',STR_PAD_LEFT);
}

/* ---------- SAVE PATIENT ---------- */
// require '../../assets/phpqrcode/qrlib.php';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    try{

        // DUPLICATE CHECK
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM patients 
            WHERE first_name=? AND last_name=? AND birthday=? AND facility_id=?
        ");
        $stmt->execute([
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['birthday'],
            $facility_id
        ]);

        if($stmt->fetchColumn() > 0){
            throw new Exception("Patient already exists.");
        }

        // EMPLOYEE VALIDATION
        if(($_POST['makati_employee'] ?? 'NO') === 'YES'){
            if(empty($_POST['department']) || empty($_POST['employment_type'])){
                throw new Exception("Department & Employment Type required.");
            }
        }

        /*
         * HEALTH CENTER / BARANGAY RESOLUTION
         * The "Health Center" field still posts into the `barangay` column
         * (existing convention — do not rename without a migration).
         * It's a free-text autocomplete input (backed by a <datalist>) of
         * known health centers), so "Others" is typed/selected the same
         * way any other option would be. When the person picks/types
         * "Others" (address outside Makati City), we use the free-text
         * `barangay_other` field instead, and there is no applicable cluster.
         */
        $barangayInput = trim($_POST['barangay'] ?? '');
        $isOthersBarangay = (strtolower($barangayInput) === 'others');
        $barangayValue = $isOthersBarangay
            ? trim($_POST['barangay_other'] ?? '')
            : $barangayInput;
        $clusterValue = $isOthersBarangay ? '' : trim($_POST['cluster'] ?? '');

        if ($barangayValue === '') {
            throw new Exception("Barangay is required.");
        }

        $his_id = generateHIS($conn,$facility_id);
        $age = (new DateTime())->diff(new DateTime($_POST['birthday']))->y;

        /*
         * MEMBER CARD NUMBER
         * Field is disabled/optional on the form — it may not be present
         * in $_POST at all, or may arrive empty. Normalize to NULL so the
         * DB accepts it instead of throwing on blank/absent.
         */
        $memberCardNo = (!empty($_POST['member_card_no']) ? trim($_POST['member_card_no']) : null);

        /*
         * CONTACT NUMBER
         * No longer required on the form. Normalize blank to NULL.
         */
        $contactNumber = (!empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null);

        /*
         * ITEM 4.2 FIX: last_prescription_date / last_refill_date
         * These columns (like last_medical_consult) must NOT have any
         * value on a brand-new patient — a fresh registration has no
         * prescription or refill history yet. Previously these two
         * columns were simply left out of the INSERT entirely, and if
         * the DB schema gives them a DEFAULT CURRENT_TIMESTAMP, MySQL
         * silently stamped "now" into them on insert — which is exactly
         * why the Last Prescription field showed a value immediately
         * after registering a patient with no prescription ever created.
         * Explicitly passing null here (same pattern already used for
         * last_medical_consult below) guarantees they start empty
         * regardless of what the column's default is.
         */
        $lastPrescriptionDate = null;
        $lastRefillDate = null;

        // INSERT
        $stmt = $conn->prepare("
            INSERT INTO patients(
                facility_id, his_id,
                last_name, first_name, middle_name, email,
                gender, birthday, age,
                contact_number, civil_status,
                house_no_street, barangay, cluster,
                makati_employee, department, employment_type,
                member_card_no, account_type, makati_health_plus_no, priority_type,
                member_type, last_medical_consult, last_prescription_date, last_refill_date
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->execute([
            $facility_id,$his_id,
            $_POST['last_name'],$_POST['first_name'],$_POST['middle_name'],$_POST['email'] ?? 'n/a',
            $_POST['gender'],$_POST['birthday'],$age,
            $contactNumber,$_POST['civil_status'],
            $_POST['house_no_street'],$barangayValue,$clusterValue,
            $_POST['makati_employee'],
            $_POST['department'] ?? null,$_POST['employment_type'] ?? null,
            $memberCardNo, $_POST['account_type'], $_POST['makati_health_plus_no'] ?? null,  $_POST['priority_type'], 
            $_POST['member_type'], null, $lastPrescriptionDate, $lastRefillDate
        ]);

        $patient_id = $conn->lastInsertId();

        header("Location: " . ($fromPrescription 
            ? "../add/prescription.php?patient_id={$patient_id}" 
            : $listUrl));
        exit;

    }catch(Exception $e){
        echo "<script>
            document.addEventListener('DOMContentLoaded',function(){
                Swal.fire('Error','{$e->getMessage()}','error');
            });
        </script>";
    }
}

/* ---------- LOAD ADDRESS CLUSTERS ---------- */

$clusters = [];

$stmt = $conn->query("
    SELECT id, cluster_name
    FROM clusters
    ORDER BY cluster_name ASC
");

$clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- LOAD HEALTH CENTERS (with their cluster, for auto-fill) ----------
 * Health Center drives the Address section. It's a free-text autocomplete
 * input (backed by a <datalist>), and its cluster is looked up client-side
 * from data-cluster-name on each <option>, so no AJAX round-trip is needed.
 */
$healthCenters = [];

$hcStmt = $conn->query("
    SELECT hf.id, hf.name, hf.cluster_id, c.cluster_name
    FROM hf_description hf
    LEFT JOIN clusters c ON hf.cluster_id = c.id
    ORDER BY c.cluster_name ASC, hf.name ASC
");

$healthCenters = $hcStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- LOAD DEPARTMENTS (for the Employment Information section) ----------
 * Department is now a free-text autocomplete input (backed by a
 * <datalist>), sourced from its own `department` table, mirroring the
 * Health Center pattern above.
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

    /* ===== Modal redesign (shared visual language with Users page) ===== */
    .modal-content {
        border: 1px solid #e6e8eb;
        border-radius: 0.9rem;
        box-shadow: 0 8px 24px rgba(16, 24, 40, 0.10);
        overflow: hidden;
    }
    .modal-header {
        background: #fff;
        border-bottom: 1px solid #eaecf0;
        padding: 1.1rem 1.5rem;
    }
    .modal-header .modal-title {
        font-size: 1rem;
        font-weight: 700;
        color: #101828;
        display: flex;
        align-items: center;
        gap: .5rem;
    }
    .modal-header .modal-title i { color: #0d6efd; font-size: 1.1rem; }
    .modal-body { padding: 1.5rem; }
    .modal-footer { border-top: 1px solid #eaecf0; padding: 1rem 1.5rem; }
    .form-label-sm {
        font-size: 0.75rem;
        font-weight: 600;
        color: #475467;
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: .3rem;
        display: block;
    }
    .upload-dropzone {
        border: 1.5px dashed #d0d5dd;
        border-radius: 0.65rem;
        padding: 1.25rem;
        text-align: center;
        background: #f9fafb;
        transition: border-color .15s;
    }
    .upload-dropzone:hover { border-color: #0d6efd; }
</style>
<div class="page-title">
    <nav class="breadcrumbs">
        <div class="container">
            <ol>
                <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
                <li><a href="<?= $listUrl ?>">Patient List</a></li>
                <li class="current">Add Patient</li>
            </ol>
        </div>
    </nav>
</div>
<section class="section">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>Add New Patient</h4>
            <div class="d-flex gap-2 flex-wrap">

                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>

                <!--<a href="../../api/patient/download.php" class="btn btn-outline-success btn-sm">-->
                <!--    <i class="bi bi-download"></i> Template-->
                <!--</a>-->

                <!--<button class="btn btn-outline-primary btn-sm" id="uploadTrigger">-->
                <!--    <i class="bi bi-upload"></i> Upload-->
                <!--</button>-->

            </div>
        </div>
        
        <form method="POST" id="patientForm">
        
            <!-- =======================================================
                PERSONAL INFORMATION
            ======================================================== -->
        
            <div class="card shadow-sm border-0 mb-4">
        
                <div class="card-header bg-white py-3">
        
                    <div class="d-flex align-items-center">
        
                        <i class="bi bi-person-vcard fs-5 me-2"></i>
        
                        <div>
        
                            <h5 class="mb-0 fw-semibold">
                                Personal Information
                            </h5>
        
                            <small class="text-muted">
                                Enter the patient's basic personal information.
                            </small>
        
                        </div>
        
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
                                autocomplete="family-name"
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
                                autocomplete="given-name"
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
                                name="middle_name">
        
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
                                autocomplete="email"
                                placeholder="example@email.com">
        
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
                                        name="gender"
                                        id="genderMale"
                                        value="MALE">
        
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
                                        name="gender"
                                        id="genderFemale"
                                        value="FEMALE">
        
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
                                name="birthday"
                                id="birthday"
                                required>
        
                        </div>
        
                        <!-- Contact (OPTIONAL — no longer required) -->
        
                        <div class="col-lg-3 col-md-6">
        
                            <label class="form-label fw-semibold">
        
                                Contact Number
        
                            </label>
        
                            <input
                                type="number"
                                class="form-control"
                                id="contact_number"
                                name="contact_number"
                                placeholder="09XXXXXXXXX">
        
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
        
                                <option>SINGLE</option>
                                <option>MARRIED</option>
                                <option>WIDOWED</option>
                                <option>SEPARATED</option>
        
                            </select>
        
                        </div>
        
                    </div>
        
                </div>
        
            </div>
            
            <!-- =======================================================
                ADDRESS INFORMATION
            ======================================================== -->
        
            <div class="card shadow-sm border-0 mb-4">
            
                <div class="card-header bg-white py-3">
            
                    <div class="d-flex align-items-center">
            
                        <i class="bi bi-geo-alt fs-5 me-2"></i>
            
                        <div>
            
                            <h5 class="mb-0 fw-semibold">
                                Address Information
                            </h5>
            
                            <small class="text-muted">
                                Enter the patient's residential address and assigned health center.
                            </small>
            
                        </div>
            
                    </div>
            
                </div>
            
                <div class="card-body">
            
                    <div class="row g-4">
            
                        <!-- Address -->
            
                        <div class="col-lg-12">
            
                            <label class="form-label fw-semibold">
            
                                House No. / Street / Subdivision
            
                                <span class="text-danger">*</span>
            
                            </label>
            
                            <input
                                type="text"
                                class="form-control"
                                name="house_no_street"
                                required>
            
                        </div>
            
                        <!-- Health Center (autocomplete text input — typing/selecting a
                             known name auto-fills Cluster below; type "Others" for
                             addresses outside Makati City) -->
            
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
                                required>

                            <datalist id="healthCenterList">

                                <?php foreach($healthCenters as $hc): ?>

                                    <option
                                        value="<?= htmlspecialchars($hc['name']) ?>"
                                        data-cluster-id="<?= htmlspecialchars($hc['cluster_id'] ?? '') ?>"
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
                                    Select Health Center First
                                </option>
            
                                <?php foreach($clusters as $c): ?>
            
                                    <option
                                        value="<?= htmlspecialchars($c['cluster_name']) ?>"
                                        data-id="<?= $c['id'] ?>">
            
                                        <?= htmlspecialchars($c['cluster_name']) ?>
            
                                    </option>
            
                                <?php endforeach; ?>
            
                            </select>
                            <input type="hidden" name="cluster" id="clusterHidden" value="">
            
                            <small class="text-muted">Automatically filled based on the selected Health Center — cannot be edited manually.</small>
            
                        </div>
            
                        <!-- Barangay (free text) — only shown when Health Center = "Others" -->
            
                        <div class="col-lg-12 d-none" id="barangayOtherWrap">
            
                            <label class="form-label fw-semibold">
            
                                Barangay
            
                                <span class="text-danger">*</span>
            
                            </label>
            
                            <input
                                type="text"
                                class="form-control"
                                id="barangayOther"
                                name="barangay_other"
                                placeholder="Enter Barangay (outside Makati City)">
            
                        </div>
            
                    </div>
            
                </div>
            
            </div>
                
            <!-- =======================================================
                EMPLOYMENT INFORMATION
            ======================================================== -->
            
            <div class="card shadow-sm border-0 mb-4">
            
                <div class="card-header bg-white py-3">
            
                    <div class="d-flex align-items-center">
            
                        <i class="bi bi-briefcase fs-5 me-2"></i>
            
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
            
                    <!-- Makati Employee -->
            
                    <div class="row mb-4">
            
                        <div class="col-md-5">
            
                            <label class="form-label fw-semibold">
                                Makati City Government Employee
                            </label>
            
                        </div>
            
                        <div class="col-md-7">
            
                            <div class="d-flex gap-4 mt-1">
            
                                <div class="form-check">
            
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="makati_employee"
                                        id="emp_yes"
                                        value="YES">
            
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
                                        name="makati_employee"
                                        id="emp_no"
                                        value="NO"
                                        checked>
            
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
                        class="border rounded-3 p-4 bg-light d-none">
            
                        <div class="mb-3">
            
                            <h6 class="fw-semibold mb-1">
            
                                Employee Details
            
                            </h6>
            
                            <small class="text-muted">
            
                                Complete the information below.
            
                            </small>
            
                        </div>
            
                        <div class="row g-4">
            
                            <!-- Department (searchable — sourced from `department` table) -->
            
                            <div class="col-md-6">
            
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
                                    placeholder="Type to search Department...">

                                <datalist id="departmentList">

                                    <?php foreach($departments as $d): ?>

                                        <option value="<?= htmlspecialchars($d['department_name']) ?>">

                                    <?php endforeach; ?>

                                </datalist>

                                <small class="text-muted">Start typing to search for a Department.</small>
            
                            </div>
            
                            <!-- Status -->
            
                            <div class="col-md-6">
            
                                <label class="form-label fw-semibold">
            
                                    Status of Appointment
            
                                    <span class="text-danger">*</span>
            
                                </label>
            
                                <select
                                    class="form-select"
                                    name="employment_type"
                                    id="employment_type">
            
                                    <option value="">
                                        Select Status
                                    </option>
            
                                    <option>
                                        JOB ORDER
                                    </option>
            
                                    <option>
                                        CASUAL
                                    </option>
            
                                    <option>
                                        REGULAR
                                    </option>
            
                                    <option>
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
            ======================================================== -->
            
            <div class="card shadow-sm border-0 mb-4">
            
                <div class="card-header bg-white py-3">
            
                    <div class="d-flex align-items-center">
            
                        <i class="bi bi-person-badge fs-5 me-2"></i>
            
                        <div>
            
                            <h5 class="mb-0 fw-semibold">
                                Membership Information
                            </h5>
            
                            <small class="text-muted">
                                Membership and healthcare program information.
                            </small>
            
                        </div>
            
                    </div>
            
                </div>
            
                <div class="card-body">
            
                    <div class="row g-4">
            
                        <!-- Member Card (optional — disabled, accepts null/0) -->
            
                        <div class="col-lg-4 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Member Card Number
            
                            </label>
            
                            <input
                                type="text"
                                class="form-control"
                                name="member_card_no"
                                id="member_card_no"
                                placeholder="MN000XXX"
                                disabled>
            
                        </div>
            
                        <!-- Account Type -->
            
                        <div class="col-lg-2 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Account Type
            
                                <span class="text-danger">*</span>
            
                            </label>
            
                            <select
                                class="form-select"
                                name="account_type"
                                id="account_type"
                                required>
            
                                <option value="YC" selected>
                                    YC
                                </option>
            
                                <option value="MC">
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
                                id="makati_health_plus_no"
                                name="makati_health_plus_no"
                                placeholder="2021-123XXX">
            
                        </div>
            
                        <!-- Priority -->
            
                        <div class="col-lg-3 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Priority Type
            
                            </label>
            
                            <select
                                class="form-select"
                                name="priority_type"
                                id="priority_type">
            
                                <option value="None">
                                    None
                                </option>
            
                                <option value="Pregnant">
                                    Pregnant
                                </option>
            
                                <option value="PWD">
                                    PWD
                                </option>
            
                                <option value="Senior Citizen">
                                    Senior Citizen
                                </option>
            
                            </select>
            
                        </div>
            
                        <!-- Member Type -->
            
                        <div class="col-lg-3 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Member Type
            
                            </label>
            
                            <select
                                class="form-select"
                                name="member_type"
                                id="member_type">
            
                                <option value="Card Holder">
                                    Card Holder
                                </option>
            
                                <option value="Dependent">
                                    Dependent
                                </option>
            
                            </select>
            
                        </div>
            
                        <!-- Created By -->
            
                        <div class="col-lg-5 col-md-6">
            
                            <label class="form-label fw-semibold">
            
                                Author 
            
                            </label>
            
                            <input
                                type="text"
                                class="form-control bg-light"
                                name="created_by_username"
                                value="<?= htmlspecialchars($_SESSION['user']['name']) ?>"
                                readonly>
            
                        </div>
            
                    </div>
            
                </div>
            
            </div>
            
            <!-- =======================================================
                ACTIONS
            ======================================================== -->
            
            <div class="card shadow-sm border-0">
            
                <div class="card-body">
            
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
            
                        <div>
            
                            <small class="text-muted">
            
                                <span class="text-danger">*</span>
                                Required fields must be completed before submitting.
            
                            </small>
            
                        </div>
            
                        <div class="d-flex gap-2">
            
                            <button
                                type="reset"
                                class="btn btn-outline-secondary">
            
                                <i class="bi bi-arrow-counterclockwise me-1"></i>
            
                                Reset
            
                            </button>
            
                            <button
                                type="button"
                                id="submitBtn"
                                class="btn <?= $fromPrescription ? 'btn-success' : 'btn-primary' ?> px-4">
            
                                <i class="bi bi-check-circle me-1"></i>
            
                                <?= $fromPrescription
                                    ? 'Submit & Create Prescription'
                                    : 'Submit Patient' ?>
            
                            </button>
            
                        </div>
            
                    </div>
            
                </div>
            
            </div>
        
        </form>
        
    </div>
</section>

<!-- ================= UPLOAD PATIENTS MODAL (redesigned) ================= -->
<div class="modal fade" id="uploadModal">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Patients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="form-label-sm mb-0">CSV File</span>
                    <a href="../../api/patient/download.php" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-download"></i> Template
                    </a>
                </div>

                <form id="uploadForm" enctype="multipart/form-data">
                    <div class="upload-dropzone mb-3">
                        <i class="bi bi-file-earmark-arrow-up" style="font-size:1.5rem;color:#98a2b3;"></i>
                        <input type="file" name="file" accept=".csv" class="form-control mt-2" required>
                    </div>
                </form>

                <div class="d-flex align-items-start gap-2 p-2" style="background:#f9fafb;border-radius:.5rem;">
                    <i class="bi bi-info-circle text-primary mt-1"></i>
                    <small class="text-muted">
                        Required columns: last_name, first_name, gender, birthday (YYYY-MM-DD), contact_number,
                        civil_status, house_no_street, cluster, barangay, member_card_no, account_type.<br>
                        Department &amp; Employment Status are required only when Makati Employee is YES.
                    </small>
                </div>

            </div>

            <div class="modal-footer">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary px-4" id="uploadBtn">
                    <i class="bi bi-upload"></i> Upload
                </button>
            </div>

        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


<script>
    $(function(){

        /* ================= HEALTH CENTER (AUTOCOMPLETE) -> CLUSTER AUTO-FILL ================= */
        /*
         * Health Center is a free-text input backed by a <datalist>
         * (id="healthCenterList"), instead of a <select>. On every
         * keystroke or selection we look for an exact (case-insensitive)
         * match among the known health centers to auto-fill Cluster;
         * typing/selecting "Others" reveals the free-text Barangay field.
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


        /* ================= DEPARTMENT (AUTOCOMPLETE) ================= */
        /*
         * Department is a free-text input backed by a <datalist>
         * (id="departmentList"), sourced from the `department` table.
         * Mirrors the Health Center pattern above.
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


        /* ---------------- TOGGLE EMPLOYEE ---------------- */
        function toggleEmployee(){
            let yes = $('#emp_yes').is(':checked');

            $('#employeeSection').toggleClass('d-none', !yes);

            $('#department, #employment_type')
                .prop('required', yes);

            if(!yes){
                $('#department, #employment_type')
                    .val('')
                    .removeClass('is-valid is-invalid');
            }
        }
        toggleEmployee();
        $('#emp_yes,#emp_no').change(toggleEmployee);


        /* ---------------- LIVE INPUT COLOR (GREEN/RED) ---------------- */
        $('#patientForm input, #patientForm select').on('input change', function(){

            let el = $(this);

            if(el.prop('required')){
                if(el.is(':radio')){
                    let name = el.attr('name');
                    if($('input[name="'+name+'"]:checked').length){
                        $('input[name="'+name+'"]').removeClass('is-invalid').addClass('is-valid');
                    }
                } else {
                    if(el.val()){
                        el.removeClass('is-invalid').addClass('is-valid');
                    } else {
                        el.removeClass('is-valid');
                    }
                }
            }

        });


        /* ---------------- INPUT FORMAT ---------------- */
        $('#contact_number').on('input', function(){
            let v = this.value.replace(/\D/g,'');
            if (v === '') { this.value = ''; return; }
            if(!v.startsWith('09')) v='09';
            this.value = v.substring(0,11);
        });

        $('#makati_health_plus_no').on('input', function () {
        
            let v = this.value.replace(/\D/g, '');
        
            if (v === '') {
                this.value = '';
                return;
            }
        
            if (!v.startsWith('2')) {
                v = '2' + v.substring(1);
            }
        
            if (v.length > 4) {
                v = v.substring(0, 4) + '-' + v.substring(4, 11);
            }
        
            this.value = v;
        });


        /* ---------------- DATE LIMITS ---------------- */
        $('#expiration_date').attr('min', new Date().toISOString().split('T')[0]);
        $('#birthday').attr('max', new Date().toISOString().split('T')[0]);


        /* ---------------- VALIDATION ---------------- */
        function validate(){

            let valid = true;
            let firstError = null;

            $('#patientForm input,select').removeClass('is-invalid');

            $('#patientForm [required]').each(function(){

                if($(this).is(':radio')){
                    let name = $(this).attr('name');

                    if(!$('input[name="'+name+'"]:checked').length){
                        valid = false;
                        $('input[name="'+name+'"]').addClass('is-invalid');

                        if(!firstError) firstError = $('input[name="'+name+'"]').last();
                    }

                } else {

                    if(!$(this).val()){
                        valid = false;
                        $(this).addClass('is-invalid');

                        if(!firstError) firstError = $(this);
                    }

                }

            });

            /* SCROLL TO FIRST ERROR */
            if(!valid){
                $('html,body').animate({
                    scrollTop: firstError.offset().top - 120
                }, 500);

                Swal.fire('Missing Fields','Please fill all required fields','error');
                return false;
            }

            /* DEPARTMENT — must be a known name when employee = YES */
            if ($('#emp_yes').is(':checked')) {
                const typedDept = $('#department').val().trim();

                if (!typedDept || !findDepartment(typedDept)) {
                    $('#department').addClass('is-invalid');
                    Swal.fire('Invalid Department', 'Please choose a Department from the suggestions.', 'error');
                    return false;
                }
            }

            /* HEALTH CENTER — must be a known name or "Others" */
            const typedBarangay = $('#barangay').val().trim();
            const isOthersBarangay = typedBarangay.toLowerCase() === 'others';

            if(!isOthersBarangay && !findHealthCenter(typedBarangay)){
                $('#barangay').addClass('is-invalid');
                Swal.fire('Invalid Health Center', 'Please choose a Health Center from the suggestions, or type "Others" if outside Makati City.', 'error');
                return false;
            }

            /* CLUSTER (auto-filled, hidden input) — required unless "Others" was picked */
            if(!isOthersBarangay && !$('#clusterHidden').val()){
                $('#cluster').addClass('is-invalid');
                Swal.fire('Missing Cluster', 'Please select a Health Center so the Cluster can be filled in.', 'warning');
                return false;
            }

            /* PHONE FORMAT (OPTIONAL — only validated if the user filled it in) */
            const contactNo = $('#contact_number').val().trim();

            if (contactNo !== '' && !/^09\d{9}$/.test(contactNo)) {
                $('#contact_number').addClass('is-invalid');
                Swal.fire('Invalid','Phone must be 09XXXXXXXXX','error');
                return false;
            }

            /* MAKATI HEALTH PLUS NO. (OPTIONAL) */
            const healthNo = $('#makati_health_plus_no').val().trim();
            
            if (healthNo !== '') {
                if (!/^2\d{3}-\d{7}$/.test(healthNo)) {
                    $('#makati_health_plus_no').addClass('is-invalid');
            
                    Swal.fire(
                        'Invalid',
                        'Invalid Makati Health Plus No. format',
                        'error'
                    );
            
                    return false;
                }
            }

            return true;
        }


        /* ---------------- SUBMIT ---------------- */
        $('#submitBtn').click(function(){

            if(!validate()) return;

            Swal.fire({
                title:'Confirm',
                text: <?= $fromPrescription ? "'Save and proceed to prescription?'" : "'Save this patient?'" ?>,
                icon:'question',
                showCancelButton:true
            }).then(res=>{
                if(res.isConfirmed){
                    $('#patientForm').submit();
                }
            });

        });

        /* ================= UPLOAD CSV ================= */
        $('#uploadBtn').click(function(){

            const fileInput = $('#uploadForm input[type="file"]')[0];

            if (!fileInput.files.length) {
                Swal.fire('Missing File', 'Please choose a CSV file first.', 'warning');
                return;
            }

            let formData = new FormData($('#uploadForm')[0]);

            Swal.fire({
                title: 'Upload Patients?',
                text: 'Bulk upload will insert multiple patients.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Upload'
            }).then(res => {

                if(!res.isConfirmed) return;

                $.ajax({
                    url: '../../api/patient/upload_template.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',

                    beforeSend: () => {
                        Swal.fire({
                            title: 'Uploading...',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });
                    },

                    success: function(res){

                        try { res = JSON.parse(res); } catch {}

                        const hasErrors = Array.isArray(res.errors) && res.errors.length > 0;

                        const errorListHtml = hasErrors
                            ? `<div class="text-start mt-2" style="max-height:250px;overflow-y:auto;">
                                 <ul class="mb-0 ps-3">
                                   ${res.errors.map(e => `<li>${$('<div>').text(e).html()}</li>`).join('')}
                                 </ul>
                               </div>`
                            : '';

                        if(res.status === "success"){
                            Swal.fire({
                                icon: hasErrors ? 'warning' : 'success',
                                title: hasErrors ? 'Uploaded with some issues' : 'Success',
                                html: `<p class="mb-0">${res.message}</p>${errorListHtml}`
                            }).then(() => location.reload());
                        }else{
                            Swal.fire({
                                icon: 'error',
                                title: 'Upload Failed',
                                html: `<p class="mb-0">${res.message}</p>${errorListHtml}`
                            });
                        }

                    },

                    error: () => {
                        Swal.fire('Error','Upload failed','error');
                    }
                });

            });

        });
        $('#uploadTrigger').click(function(){
            $('#uploadForm')[0].reset();
            let modal = new bootstrap.Modal(document.getElementById('uploadModal'));
            modal.show();
        });

    });
</script>