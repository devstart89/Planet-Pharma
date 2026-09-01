<?php
session_start();

/*
|--------------------------------------------------------------------------
| BULK TRANSMITTAL PDF GENERATOR
|--------------------------------------------------------------------------
| Companion to transmittal_pdf.php (single transmittal). Accepts MULTIPLE
| transmittal IDs and combines them into ONE PDF, printed back-to-back —
| each transmittal starts on its own new page, complete with its own
| header/detail block and prescription table.
|
| Access is scoped to the CURRENT health_facility session's own
| facility_id, matching this app's existing rule that this page (and its
| listing API) never exposes another facility's transmittals — a crafted
| request with someone else's transmittal ID simply won't match here and
| gets silently skipped, same pattern used in the bulk prescription PDF.
|
| Usage: transmittal_pdf_bulk.php?ids[]=12&ids[]=13&ids[]=14
|    or: transmittal_pdf_bulk.php?ids=12,13,14
*/
if (!isset($_SESSION['user']) ||
    !in_array($_SESSION['user']['role'], ['health_facility', 'pharmacy', 'super_admin'], true)) {
    die("Unauthorized");
}

include '../../config/db.php';
require('../../assets/vendor/fpdf/fpdf.php');

$role = $_SESSION['user']['role'];
$facilityId = $_SESSION['user']['facility_id'] ?? null;
$sessionPharmacyId = $_SESSION['user']['pharmacy_id'] ?? null;

$rawIds = $_GET['ids'] ?? [];
if (is_string($rawIds)) {
    $rawIds = explode(',', $rawIds);
}
$transmittalIds = array_values(array_unique(array_filter(array_map('intval', (array) $rawIds))));

if (empty($transmittalIds)) {
    die("No transmittal IDs provided.");
}

$MAX_BULK = 200;
if (count($transmittalIds) > $MAX_BULK) {
    $transmittalIds = array_slice($transmittalIds, 0, $MAX_BULK);
}

/*
 * FIX: scoping now branches by role instead of assuming health_facility
 * for everyone — this is what actually makes the new centralized
 * (Pharmacy/Facility-filterable) transmittal view usable for bulk
 * printing. health_facility's behavior is completely unchanged from
 * before (still exactly "only my own facility's transmittals").
 *
 *   - health_facility: unchanged — scoped to their own facility_id.
 *   - pharmacy: scoped to every facility under THEIR OWN pharmacy_id
 *     (via health_facilities.pharmacy_id), regardless of which
 *     transmittal IDs were requested.
 *   - super_admin: no additional scoping — matches the centralized
 *     access already established for patients/prescriptions.
 */
$placeholders = implode(',', array_fill(0, count($transmittalIds), '?'));

if ($role === 'health_facility') {
    $scopeStmt = $conn->prepare("
        SELECT id FROM transmittals WHERE id IN ($placeholders) AND facility_id = ?
    ");
    $scopeStmt->execute([...$transmittalIds, $facilityId]);
    $transmittalIds = array_map('intval', $scopeStmt->fetchAll(PDO::FETCH_COLUMN));
} elseif ($role === 'pharmacy') {
    $scopeStmt = $conn->prepare("
        SELECT t.id
        FROM transmittals t
        JOIN health_facilities hf ON t.facility_id = hf.id
        WHERE t.id IN ($placeholders) AND hf.pharmacy_id = ?
    ");
    $scopeStmt->execute([...$transmittalIds, $sessionPharmacyId]);
    $transmittalIds = array_map('intval', $scopeStmt->fetchAll(PDO::FETCH_COLUMN));
}
// super_admin: no additional scoping.

/*
|--------------------------------------------------------------------------
| PRECHECK MODE
|--------------------------------------------------------------------------
| Same purpose as generate_prescription_pdf_bulk.php's precheck: lets the
| calling page verify (via a quick fetch()) that there's actually
| something printable before opening a tab at all, instead of a raw
| die() message appearing in a freshly-opened blank tab. Must run before
| the die() below, or the die() fires first and this is never reached.
*/
if (isset($_GET['precheck'])) {
    header('Content-Type: application/json');
    if (empty($transmittalIds)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'None of the requested transmittals are available to you.'
        ]);
    } else {
        echo json_encode(['status' => 'success', 'count' => count($transmittalIds)]);
    }
    exit;
}

if (empty($transmittalIds)) {
    die("None of the requested transmittals are available to you.");
}

/*
 * Same encoding/wrapping/row-drawing helpers as transmittal_pdf.php,
 * copied unchanged rather than shared via include — see that file's
 * comments for the reasoning behind each fix.
 */
function txt($value) {
    $value = (string) $value;
    if ($value === '') return $value;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $value);
        if ($converted !== false) return $converted;
    }
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
    }
    return $value;
}

function rxCountWrappedLines(FPDF $pdf, string $renderedText, float $width, int $maxLines = 4): int {
    if ($renderedText === '') return 1;
    $words = preg_split('/\s+/', $renderedText, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($words)) return 1;
    $lines = 1;
    $currentLine = '';
    foreach ($words as $word) {
        $candidate = $currentLine === '' ? $word : ($currentLine . ' ' . $word);
        if ($pdf->GetStringWidth($candidate) > $width && $currentLine !== '') {
            $lines++;
            $currentLine = $word;
            if ($lines >= $maxLines) break;
        } else {
            $currentLine = $candidate;
        }
    }
    return min($lines, $maxLines);
}

function rxDrawWrappedRow(FPDF $pdf, array $cols, float $y, float $rowHeight, float $lineHeight = 6): void {
    $left = $pdf->lMargin ?? 10;
    $curX = $left;
    foreach ($cols as $col) {
        $w = $col['width'];
        $text = $col['text'];
        $align = $col['align'] ?? 'L';
        $pdf->Rect($curX, $y, $w, $rowHeight);
        $pdf->SetXY($curX + 1, $y + 1);
        $pdf->MultiCell($w - 2, $lineHeight, $text, 0, $align);
        $curX += $w;
    }
    $pdf->SetXY($left, $y + $rowHeight);
}

define('TRX_COL_NUM', 10);
define('TRX_COL_RXNO', 45);
define('TRX_COL_PATIENT', 55);
define('TRX_COL_ADDRESS', 50);
define('TRX_COL_REMARKS', 30);
define('TRX_LINE_HEIGHT', 6);
define('TRX_PAGE_BOTTOM_MARGIN', 20);

function rxDrawTableHeader(FPDF $pdf): void {
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(TRX_COL_NUM,8,'#',1);
    $pdf->Cell(TRX_COL_RXNO,8,'Prescription No',1);
    $pdf->Cell(TRX_COL_PATIENT,8,'Patient Name',1);
    $pdf->Cell(TRX_COL_ADDRESS,8,'Address',1);
    $pdf->Cell(TRX_COL_REMARKS,8,'Remarks',1);
    $pdf->Ln();
}

$pdf = new FPDF();

foreach ($transmittalIds as $transId) {

    $stmt = $conn->prepare("SELECT * FROM transmittals WHERE id = :id");
    $stmt->execute([':id' => $transId]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trans) continue; // skip silently — already scoped/validated above

    $stmt2 = $conn->prepare("
        SELECT p.id, p.prescription_number,
               CONCAT(pt.first_name,' ',pt.last_name) AS patient_name,
               pt.house_no_street, p.created_at, p.status, p.remarks
        FROM transmittal_prescriptions td
        JOIN prescriptions p ON p.id = td.prescription_id
        JOIN patients pt ON pt.id = p.patient_id
        WHERE td.transmittal_id = :tid
    ");
    $stmt2->execute([':tid' => $transId]);
    $prescriptions = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Each transmittal starts on its own fresh page.
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(0,10,txt('HEALTH FACILITY TRANSMITTAL'),0,1,'C');

    $pdf->SetFont('Arial','',12);
    $pdf->Cell(0,8,txt('Date Generated: '.$trans['date_generated']),0,1);
    $pdf->Cell(0,8,txt('Prescription Date: '.$trans['prescription_date']),0,1);
    $pdf->Cell(0,8,txt('Delivery Date: '.$trans['delivery_date']),0,1);
    $pdf->Cell(0,8,txt('Health Facility: '.$trans['health_facility']),0,1);
    $pdf->Cell(0,8,txt('Pharmacist: '.$trans['pharmacist']),0,1);
    $pdf->Ln(5);

    rxDrawTableHeader($pdf);
    $pdf->SetFont('Arial','',11);

    foreach ($prescriptions as $i => $p) {

        $numText = txt((string)($i+1));
        $rxNoText = txt($p['prescription_number']);
        $patientText = txt($p['patient_name']);
        $addressText = txt($p['house_no_street']);
        $remarksText = txt($p['remarks'] ?? '');

        $linesNum = rxCountWrappedLines($pdf, $numText, TRX_COL_NUM - 2);
        $linesRxNo = rxCountWrappedLines($pdf, $rxNoText, TRX_COL_RXNO - 2);
        $linesPatient = rxCountWrappedLines($pdf, $patientText, TRX_COL_PATIENT - 2);
        $linesAddress = rxCountWrappedLines($pdf, $addressText, TRX_COL_ADDRESS - 2);
        $linesRemarks = rxCountWrappedLines($pdf, $remarksText, TRX_COL_REMARKS - 2);

        $maxLines = max($linesNum, $linesRxNo, $linesPatient, $linesAddress, $linesRemarks, 1);
        $rowHeight = TRX_LINE_HEIGHT * $maxLines;

        if ($pdf->GetY() + $rowHeight > (297 - TRX_PAGE_BOTTOM_MARGIN)) {
            $pdf->AddPage();
            rxDrawTableHeader($pdf);
            $pdf->SetFont('Arial','',11);
        }

        $y = $pdf->GetY();

        rxDrawWrappedRow($pdf, [
            ['text' => $numText,      'width' => TRX_COL_NUM,     'align' => 'C'],
            ['text' => $rxNoText,     'width' => TRX_COL_RXNO,    'align' => 'L'],
            ['text' => $patientText,  'width' => TRX_COL_PATIENT, 'align' => 'L'],
            ['text' => $addressText,  'width' => TRX_COL_ADDRESS, 'align' => 'L'],
            ['text' => $remarksText,  'width' => TRX_COL_REMARKS, 'align' => 'L'],
        ], $y, $rowHeight, TRX_LINE_HEIGHT);
    }
}

if ($pdf->PageNo() === 0) {
    die("None of the requested transmittals could be rendered.");
}

$outputMode = (isset($_GET['download']) && $_GET['download'] == '1') ? 'D' : 'I';
$pdf->Output($outputMode, 'Transmittals_Bulk_' . date('Ymd_His') . '.pdf');