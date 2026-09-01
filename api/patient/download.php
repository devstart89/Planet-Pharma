<?php
session_start();
require '../../config/db.php';

/* ---------- AUTH ----------
   Matches the access rules of add/patient.php and the upload
   endpoint — only logged-in facility/doctor users can pull the
   template (previously this file had no auth check at all).
*/
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility', 'doctor','super_admin'])) {
    http_response_code(403);
    exit('Unauthorized.');
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="patient_template.csv"');
$output = fopen("php://output", "w");

/* =========================================================
   PULL REAL SAMPLE VALUES FROM THE DATABASE
   Previously this file hardcoded placeholder text like
   "SAMPLE CLUSTER" / "SAMPLE BARANGAY" — values that don't
   exist in `hf_description`/`clusters`, so the template's own
   sample rows failed the importer's Health Center validation
   the moment someone re-uploaded it unchanged. Pulling one real
   Health Center (+ its actual Cluster) and one real Department
   guarantees the template always matches what upload_template.php
   will actually accept, even as those tables change over time.
========================================================= */

$sampleHealthCenter = $conn->query("
    SELECT hf.name, c.cluster_name
    FROM hf_description hf
    LEFT JOIN clusters c ON hf.cluster_id = c.id
    WHERE hf.name IS NOT NULL AND hf.name != ''
    ORDER BY hf.id ASC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$sampleDepartment = $conn->query("
    SELECT department_name
    FROM department
    ORDER BY department_id ASC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Fall back to "Others" (always valid, no DB match required) if the
// facility hasn't set up any health centers yet, so the template is
// never broken even on a fresh install.
$hcName    = $sampleHealthCenter['name']         ?? 'Others';
$hcCluster = $sampleHealthCenter['cluster_name'] ?? '';
$deptName  = $sampleDepartment['department_name'] ?? '';

/* =========================================================
   HEADER
   Must match $expectedColumns in upload_template.php EXACTLY
   by name (their order no longer has to match — the importer
   now reads columns by header name, not position — but keeping
   the same order here avoids confusing anyone who opens this
   in Excel side-by-side with the older template).
========================================================= */
/* =========================================================
   EXCEL TEXT-PROTECTION HELPER
   Plain CSV has no way to mark a cell as "text" — so Excel
   auto-detects date-shaped and number-shaped strings and
   silently reformats them the moment the file is opened:
   "1992-05-01" becomes "5/1/1992" (locale date format), and
   "09123436789" loses its leading zero. Wrapping the value as
   ="..." makes Excel/Sheets treat it as a text formula instead
   of a date/number, so it's preserved exactly as-is.
========================================================= */
function excelText($value) {
    return '="' . str_replace('"', '""', $value) . '"';
}

fputcsv($output, [
    'last_name',
    'first_name',
    'middle_name',
    'email',
    'gender',
    'birthday',
    'contact_number',
    'civil_status',
    'house_no_street',
    'cluster',
    'barangay',
    'barangay_other',
    'makati_employee',
    'department',
    'employment_type',
    'member_card_no',
    'account_type',
    'makati_health_plus_no',
    'priority_type',
    'member_type'
]);

/* =========================================================
   SAMPLE ROW 1 — non-employee, minimum required fields only.
   Health Center + Cluster below are real, currently-registered
   values pulled from your database — not placeholder text —
   so this row will pass validation if uploaded as-is.
========================================================= */
fputcsv($output, [
    'Dela Cruz',        // last_name        (required)
    'Marida',           // first_name       (required)
    'Reyes',            // middle_name      (optional)
    '',                 // email            (optional, validated if present)
    'FEMALE',           // gender           (required: MALE / FEMALE)
    excelText('1992-05-01'),  // birthday   (required: YYYY-MM-DD — kept as text so Excel doesn't reformat it)
    excelText('09123436789'), // contact_number (required: 09XXXXXXXXX — kept as text so Excel doesn't drop the leading 0)
    'MARRIED',          // civil_status     (required: SINGLE / MARRIED / WIDOWED / SEPARATED)
    '123 Sample St.',   // house_no_street  (required)
    $hcCluster,         // cluster          (auto-fills from Health Center — leave blank if unsure)
    $hcName,            // barangay         (required — must match a registered Health Center, or "Others")
    '',                 // barangay_other   (required ONLY when barangay = "Others" — the real outside-Makati location)
    'NO',               // makati_employee  (YES / NO)
    '',                 // department       (required only if makati_employee = YES)
    '',                 // employment_type  (required only if makati_employee = YES)
    'MN000002',         // member_card_no   (required)
    'MC',               // account_type     (required: YC / MC)
    '',                 // makati_health_plus_no (optional: 2XXX-XXXXXXX)
    'None',             // priority_type    (optional: None / Pregnant / PWD / Senior Citizen)
    'None'              // member_type      (optional: None / Card Holder / Dependent)
]);

/* =========================================================
   SAMPLE ROW 2 — Makati employee, conditional fields filled.
   Department below is a real, currently-registered value.
========================================================= */
fputcsv($output, [
    'Manygo',
    'Juan',
    'Santos',
    'juan.manyo@example.com',
    'MALE',
    excelText('1990-01-01'),
    excelText('09123456789'),
    'SINGLE',
    '456 Sample Ave.',
    $hcCluster,
    $hcName,
    '',                     // barangay_other   (required ONLY when barangay = "Others")
    'YES',
    $deptName,              // department       (required since makati_employee = YES)
    'REGULAR',              // employment_type  (required: JOB ORDER / CASUAL / REGULAR / CONTRACTUAL)
    'MN000001',
    'YC',
    excelText('2001-1234567'),
    'None',
    'Card Holder'
]);

fclose($output);