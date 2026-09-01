<?php
session_start();
require '../../config/db.php';

header('Content-Type: application/json');

/* ---------- AUTH ---------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility', 'doctor'])) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized."]);
    exit;
}

// Trust the session, never the client, for facility_id
$facility_id = $_SESSION['user']['facility_id'];

if (!isset($_FILES['file'])) {
    echo json_encode(["status" => "error", "message" => "No file uploaded."]);
    exit;
}

$file = fopen($_FILES['file']['tmp_name'], "r");

if (!$file) {
    echo json_encode(["status" => "error", "message" => "Unable to read file."]);
    exit;
}

/* =========================
   HELPERS
========================= */
function computeAge($birthday) {
    return (new DateTime())->diff(new DateTime($birthday))->y;
}

function generateHIS($conn, $facility_id, &$nextNum) {
    $his = "HIS-" . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    $nextNum++;
    return $his;
}

function isDuplicatePatient($conn, $first_name, $last_name, $birthday, $facility_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM patients
        WHERE first_name = ? AND last_name = ? AND birthday = ? AND facility_id = ?
    ");
    $stmt->execute([$first_name, $last_name, $birthday, $facility_id]);
    return $stmt->fetchColumn() > 0;
}

/* =========================
   ROW VALIDATION
   (mirrors the required-field rules of add/patient.php)
========================= */
function validateRow($r) {

    $errors = [];

    // ---- ALWAYS REQUIRED ----
    $requiredFields = [
        'last_name'        => 'Last Name',
        'first_name'       => 'First Name',
        'gender'           => 'Gender',
        'birthday'         => 'Date of Birth',
        'contact_number'   => 'Contact Number',
        'civil_status'     => 'Civil Status',
        'house_no_street'  => 'House No. / Street',
        'cluster'          => 'Cluster',
        'barangay'         => 'Health Center (Barangay)',
        'member_card_no'   => 'Member Card Number',
        'account_type'     => 'Account Type',
    ];

    foreach ($requiredFields as $key => $label) {
        if (empty($r[$key])) {
            $errors[] = "Missing required field: $label.";
        }
    }

    // Stop early if core identity fields are missing — nothing else can be validated meaningfully
    if (empty($r['first_name']) || empty($r['last_name'])) {
        return $errors;
    }

    // ---- GENDER ----
    if (!empty($r['gender']) && !in_array(strtoupper($r['gender']), ['MALE', 'FEMALE'])) {
        $errors[] = "Invalid gender \"{$r['gender']}\" (must be MALE or FEMALE).";
    }

    // ---- CIVIL STATUS ----
    if (!empty($r['civil_status']) && !in_array(strtoupper($r['civil_status']), ['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED'])) {
        $errors[] = "Invalid civil status \"{$r['civil_status']}\".";
    }

    // ---- BIRTHDAY ----
    if (!empty($r['birthday'])) {
        $d = DateTime::createFromFormat('Y-m-d', $r['birthday']);
        if (!$d || $d->format('Y-m-d') !== $r['birthday']) {
            $errors[] = "Invalid birthday format \"{$r['birthday']}\" (expected YYYY-MM-DD).";
        } elseif ($d > new DateTime()) {
            $errors[] = "Birthday \"{$r['birthday']}\" is in the future.";
        }
    }

    // ---- CONTACT NUMBER ----
    if (!empty($r['contact_number']) && !preg_match('/^09\d{9}$/', $r['contact_number'])) {
        $errors[] = "Invalid contact number \"{$r['contact_number']}\" (expected 11-digit 09XXXXXXXXX).";
    }

    // ---- EMAIL (optional, but must be valid if present) ----
    if (!empty($r['email']) && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format \"{$r['email']}\".";
    }

    // ---- ACCOUNT TYPE ----
    if (!empty($r['account_type']) && !in_array(strtoupper($r['account_type']), ['YC', 'MC'])) {
        $errors[] = "Invalid account type \"{$r['account_type']}\" (must be YC or MC).";
    }

    // ---- MAKATI HEALTH PLUS NO. (optional, but must match format if present) ----
    if (!empty($r['makati_health_plus_no']) && !preg_match('/^2\d{3}-\d{7}$/', $r['makati_health_plus_no'])) {
        $errors[] = "Invalid Makati Health Plus No. \"{$r['makati_health_plus_no']}\" (expected 2XXX-XXXXXXX).";
    }

    // ---- PRIORITY TYPE (optional, but must be valid if present) ----
    if (!empty($r['priority_type']) && !in_array($r['priority_type'], ['None', 'Pregnant', 'PWD', 'Senior Citizen'])) {
        $errors[] = "Invalid priority type \"{$r['priority_type']}\".";
    }

    // ---- MEMBER TYPE (optional, but must be valid if present) ----
    if (!empty($r['member_type']) && !in_array($r['member_type'], ['None', 'Card Holder', 'Dependent'])) {
        $errors[] = "Invalid member type \"{$r['member_type']}\".";
    }

    // ---- MAKATI EMPLOYEE → CONDITIONALLY REQUIRED FIELDS ----
    $isEmployee = strtoupper(trim($r['makati_employee'] ?? 'NO')) === 'YES';

    if ($isEmployee) {
        if (empty($r['department'])) {
            $errors[] = "Department is required when Makati Employee is YES.";
        }
        if (empty($r['employment_type']) || !in_array(strtoupper($r['employment_type']), ['JOB ORDER', 'CASUAL', 'REGULAR', 'CONTRACTUAL'])) {
            $errors[] = "Valid Employment Status is required when Makati Employee is YES.";
        }
    }

    return $errors;
}

/* =========================
   CSV COLUMN ORDER
   (must match the downloadable template)
========================= */
$columns = [
    'last_name', 'first_name', 'middle_name', 'email',
    'gender', 'birthday', 'contact_number', 'civil_status',
    'house_no_street', 'cluster', 'barangay',
    'makati_employee', 'department', 'employment_type',
    'member_card_no', 'account_type', 'makati_health_plus_no',
    'priority_type', 'member_type'
];

/* Skip CSV header row */
fgetcsv($file);

$rowNum   = 1;
$inserted = 0;
$errors   = [];

try {

    $conn->beginTransaction();

    /* Lock + compute the next HIS number for this facility once */
    $stmt = $conn->prepare("
        SELECT his_id FROM patients WHERE facility_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([$facility_id]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextHisNum = $last ? (intval(str_replace('HIS-', '', $last['his_id'])) + 1) : 1;

    $insert = $conn->prepare("
        INSERT INTO patients(
            facility_id, his_id,
            last_name, first_name, middle_name, email,
            gender, birthday, age,
            contact_number, civil_status,
            house_no_street, barangay, cluster,
            makati_employee, department, employment_type,
            member_card_no, account_type, makati_health_plus_no, priority_type,
            member_type
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    while (($row = fgetcsv($file, 2000, ",")) !== FALSE) {

        $rowNum++;

        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

        $r = [];
        foreach ($columns as $i => $col) {
            $r[$col] = trim($row[$i] ?? '');
        }

        /* ---------- VALIDATE (same required rules as the single-add form) ---------- */
        $rowErrors = validateRow($r);
        if (!empty($rowErrors)) {
            foreach ($rowErrors as $msg) {
                $errors[] = "Row $rowNum: $msg";
            }
            continue;
        }

        /* ---------- DUPLICATE CHECK ---------- */
        if (isDuplicatePatient($conn, $r['first_name'], $r['last_name'], $r['birthday'], $facility_id)) {
            $errors[] = "Row $rowNum: Patient \"{$r['first_name']} {$r['last_name']}\" already exists.";
            continue;
        }

        /* ---------- INSERT ---------- */
        try {

            $his_id = generateHIS($conn, $facility_id, $nextHisNum);

            $insert->execute([
                $facility_id, $his_id,
                $r['last_name'], $r['first_name'], $r['middle_name'] ?: null, $r['email'] ?: 'n/a',
                strtoupper($r['gender']), $r['birthday'], computeAge($r['birthday']),
                $r['contact_number'], strtoupper($r['civil_status']),
                $r['house_no_street'], $r['barangay'], $r['cluster'],
                strtoupper($r['makati_employee'] ?: 'NO'),
                $r['department'] ?: null, $r['employment_type'] ?: null,
                $r['member_card_no'], strtoupper($r['account_type']),
                $r['makati_health_plus_no'] ?: null, $r['priority_type'] ?: 'None',
                $r['member_type'] ?: 'None'
            ]);

            $inserted++;

        } catch (Throwable $e) {
            $errors[] = "Row $rowNum: Failed to insert (" . $e->getMessage() . ").";
        }
    }

    $conn->commit();
    fclose($file);

    echo json_encode([
        "status"  => $inserted > 0 ? "success" : "error",
        "message" => $inserted > 0
            ? "$inserted patient(s) uploaded successfully." . (count($errors) ? " " . count($errors) . " row(s) skipped." : "")
            : "No patients were uploaded. " . count($errors) . " row(s) had errors.",
        "inserted" => $inserted,
        "skipped"  => count($errors),
        "errors"   => $errors
    ]);

} catch (Throwable $e) {

    $conn->rollBack();
    fclose($file);
    error_log("PATIENT UPLOAD ERROR: " . $e->getMessage());

    echo json_encode([
        "status"  => "error",
        "message" => "Upload failed. Please check your CSV file.",
        "errors"  => []
    ]);
}
