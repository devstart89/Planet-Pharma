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
   TEXT NORMALIZATION FOR MATCHING
   The Add form guarantees an exact match by having the user pick
   from a <datalist> — it's impossible to mistype. CSV values are
   typed freehand into a spreadsheet, where Excel/Sheets can silently
   introduce differences invisible to the eye: smart quotes, double
   spaces, non-breaking spaces, trailing whitespace. Without
   normalizing both sides the same way, a Health Center name that
   looks 100% correct can still fail an exact match and get the row
   silently skipped. This collapses those differences before compare
   — matching is forgiving, but the value actually stored always
   comes from the clean database record, never the raw typed text.
========================= */
function normalizeForMatch($s) {
    $s = (string)$s;
    // Non-breaking space -> normal space
    $s = str_replace("\xC2\xA0", ' ', $s);
    // Smart quotes -> straight quotes
    $s = str_replace(["\xE2\x80\x98", "\xE2\x80\x99"], "'", $s); // ‘ ’
    $s = str_replace(["\xE2\x80\x9C", "\xE2\x80\x9D"], '"', $s); // “ ”
    // Collapse any run of whitespace to a single space
    $s = preg_replace('/\s+/', ' ', $s);
    return strtolower(trim($s));
}

/* =========================
   LOAD KNOWN HEALTH CENTERS / CLUSTERS
   (same source of truth the manual Add form's <datalist> uses)
   Keyed by normalized name for forgiving-but-safe matching.
========================= */
$healthCenterMap = []; // normalized name => ['name' => canonical DB name, 'cluster' => cluster_name]

$hcStmt = $conn->query("
    SELECT hf.name, c.cluster_name
    FROM hf_description hf
    LEFT JOIN clusters c ON hf.cluster_id = c.id
");

foreach ($hcStmt->fetchAll(PDO::FETCH_ASSOC) as $hc) {
    $healthCenterMap[normalizeForMatch($hc['name'])] = [
        'name'    => $hc['name'],
        'cluster' => $hc['cluster_name'],
    ];
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
   (mirrors the required-field AND health-center-matching rules
   of add/patient.php — a CSV row must pass the same bar as
   manual entry, otherwise mismatched test/placeholder values
   like "SAMPLE BARANGAY" silently get stored as real data)
========================= */
function validateRow($r, $healthCenterMap) {

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
        'barangay'         => 'Health Center',
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

    /* ---- HEALTH CENTER / CLUSTER — must match a real registered
       health center, exactly like the manual Add form requires,
       OR be the literal "Others" (outside Makati City) WITH a real
       free-text location supplied via barangay_other — mirrors the
       $_POST['barangay_other'] requirement in add/patient.php. ---- */
    if (!empty($r['barangay'])) {

        $isOthers = strtolower($r['barangay']) === 'others';

        if ($isOthers) {
            if (empty($r['barangay_other'])) {
                $errors[] = "barangay_other is required when Health Center is \"Others\" — enter the actual barangay/location.";
            }
        } else {
            $match = $healthCenterMap[normalizeForMatch($r['barangay'])] ?? null;

            if ($match === null) {
                $errors[] = "\"{$r['barangay']}\" is not a recognized Health Center. "
                          . "Use an exact name from the system, or \"Others\" for addresses outside Makati City.";
            } elseif (!empty($r['cluster']) && normalizeForMatch($r['cluster']) !== normalizeForMatch($match['cluster'])) {
                $errors[] = "Cluster \"{$r['cluster']}\" does not match the Cluster for Health Center "
                          . "\"{$r['barangay']}\" (expected \"{$match['cluster']}\"). Leave Cluster blank to auto-fill it.";
            }
        }
    }

    return $errors;
}

/* =========================
   CSV COLUMN NAMES
   The set of fields this importer understands. Order here does
   NOT matter for parsing — actual position is read from whatever
   header row is in the uploaded file (see below). This is what
   makes upload robust to a user reordering, adding, or dropping a
   column in Excel: values are matched by header name, never by a
   hardcoded index, so they can never silently land in the wrong
   DB field.
========================= */
$expectedColumns = [
    'last_name', 'first_name', 'middle_name', 'email',
    'gender', 'birthday', 'contact_number', 'civil_status',
    'house_no_street', 'cluster', 'barangay', 'barangay_other',
    'makati_employee', 'department', 'employment_type',
    'member_card_no', 'account_type', 'makati_health_plus_no',
    'priority_type', 'member_type'
];

$requiredColumns = [
    'last_name', 'first_name', 'gender', 'birthday',
    'contact_number', 'civil_status', 'house_no_street',
    'barangay', 'member_card_no', 'account_type',
];

/* ---------- READ HEADER ROW & BUILD NAME -> INDEX MAP ---------- */
$headerRow = fgetcsv($file);

if ($headerRow === false) {
    echo json_encode(["status" => "error", "message" => "The file is empty or unreadable."]);
    fclose($file);
    exit;
}

// Normalize header cells: trim, lowercase, spaces/dashes -> underscore
$normalize = fn($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h)));

$headerMap = []; // column name => index in each data row
foreach ($headerRow as $i => $h) {
    $headerMap[$normalize($h)] = $i;
}

// Fail fast (and clearly) if the file is missing a column we can't do without,
// instead of silently importing shifted/blank data.
$missingRequired = array_diff($requiredColumns, array_keys($headerMap));
if (!empty($missingRequired)) {
    echo json_encode([
        "status"  => "error",
        "message" => "The uploaded file is missing required column(s): " . implode(', ', $missingRequired)
                   . ". Please use the provided template without renaming, removing, or reordering columns.",
        "errors"  => []
    ]);
    fclose($file);
    exit;
}

// Warn about (but don't fail on) any recognized-template columns that are
// simply absent — they'll just be treated as blank/optional below.
$unknownHeaders = array_diff(array_keys($headerMap), $expectedColumns);

$rowNum   = 1;
$inserted = 0;
$errors   = [];

if (!empty($unknownHeaders)) {
    $errors[] = "Note: unrecognized column(s) in file were ignored: " . implode(', ', $unknownHeaders) . ".";
}

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
        foreach ($expectedColumns as $col) {
            $idx = $headerMap[$col] ?? null;
            $r[$col] = ($idx !== null) ? trim($row[$idx] ?? '') : '';
        }

        /* ---------- VALIDATE (same required + health-center rules as the single-add form) ---------- */
        $rowErrors = validateRow($r, $healthCenterMap);
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

        /* ---------- RESOLVE BARANGAY + CLUSTER ----------
           Mirrors add/patient.php exactly: when Health Center is
           "Others", the real stored barangay is the free-text
           barangay_other value (not the literal word "Others"),
           and there is no cluster. Otherwise, cluster is auto-filled
           from the matched Health Center rather than trusting
           whatever string the CSV happened to contain. */
        $isOthers = strtolower($r['barangay']) === 'others';
        $hcMatch  = $isOthers ? null : ($healthCenterMap[normalizeForMatch($r['barangay'])] ?? null);

        $resolvedBarangay = $isOthers
            ? trim($r['barangay_other'])
            : ($hcMatch['name'] ?? $r['barangay']);

        $resolvedCluster = $isOthers
            ? ''
            : ($hcMatch['cluster'] ?? $r['cluster']);

        /* ---------- INSERT ---------- */
        try {

            $his_id = generateHIS($conn, $facility_id, $nextHisNum);

            $insert->execute([
                $facility_id, $his_id,
                $r['last_name'], $r['first_name'], $r['middle_name'] ?: null, $r['email'] ?: 'n/a',
                strtoupper($r['gender']), $r['birthday'], computeAge($r['birthday']),
                $r['contact_number'], strtoupper($r['civil_status']),
                $r['house_no_street'], $resolvedBarangay, $resolvedCluster,
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