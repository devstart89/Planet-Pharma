<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'health_facility') {
    die("Unauthorized");
}

include '../../config/db.php';
require('../../assets/vendor/fpdf/fpdf.php'); // Make sure FPDF installed

$trans_id = intval($_GET['id'] ?? 0);
if(!$trans_id) die("Invalid Transmittal ID");

// Get transmittal info
$stmt = $conn->prepare("SELECT * FROM transmittals WHERE id = :id");
$stmt->execute([':id'=>$trans_id]);
$trans = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$trans) die("Transmittal not found");

/*
 * FIX: added p.remarks to the SELECT. The original query never
 * selected any remarks column at all, yet the table below tried to
 * print $p[''] (an empty-string array key) into the Remarks column —
 * that key never existed, so Remarks has never actually shown real
 * data. Aliased explicitly as `remarks` for clarity in the PHP below.
 */
$stmt2 = $conn->prepare("
    SELECT p.id, p.prescription_number,
           CONCAT(pt.first_name,' ',pt.last_name) AS patient_name,
           pt.house_no_street, p.created_at, p.status, p.remarks
    FROM transmittal_prescriptions td
    JOIN prescriptions p ON p.id = td.prescription_id
    JOIN patients pt ON pt.id = p.patient_id
    WHERE td.transmittal_id = :tid
");
$stmt2->execute([':tid'=>$trans_id]);
$prescriptions = $stmt2->fetchAll(PDO::FETCH_ASSOC);

/*
 * Core FPDF expects Windows-1252 (CP1252) text, not UTF-8 — same
 * encoding gap as generate_prescription_pdf.php. Without this, any
 * name/address containing characters outside plain ASCII (e.g. Ñ,
 * curly quotes copy-pasted from Word/Excel) can silently corrupt or
 * break PDF output. iconv() with TRANSLIT/IGNORE approximates or drops
 * anything CP1252 can't represent instead of failing outright.
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

/*
 * FIX (text wrapping): core FPDF's Cell() never wraps — a value wider
 * than its column either gets clipped or, with no fill/border on the
 * next cell to stop it, silently overflows on top of whatever's next
 * to it. That's exactly what the screenshot shows: a long Address
 * value ("QWERTYUIOASDFGHJKL...") running straight through the Remarks
 * column instead of staying inside its own box.
 *
 * rxCountWrappedLines() estimates (using whatever font is currently set
 * on $pdf, so call it AFTER SetFont()) how many lines a value will need
 * to wrap within a given column width. Used below to compute one
 * consistent row height across all columns in a row, so cells with
 * short values and cells with wrapped values still end up with matching
 * box heights instead of a ragged table.
 */
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

/**
 * Draws one table row with a bordered box per column, sized to a single
 * consistent row height (computed by the caller from the tallest
 * wrapped column). A plain Rect() border is drawn for each column so
 * every cell's box is the exact same height regardless of how many
 * lines its own text actually used, then the (possibly multi-line) text
 * is placed inside via MultiCell with no border of its own — this is
 * what keeps the grid looking like a clean table instead of cells with
 * mismatched heights.
 */
function rxDrawWrappedRow(FPDF $pdf, array $cols, float $y, float $rowHeight, float $lineHeight = 6): void {
    $x = $pdf->GetX();
    $left = $pdf->lMargin ?? 10;
    $curX = $left;

    foreach ($cols as $col) {
        $w = $col['width'];
        $text = $col['text'];
        $align = $col['align'] ?? 'L';

        // Border box for the full row height, regardless of this
        // column's own line count.
        $pdf->Rect($curX, $y, $w, $rowHeight);

        // Text inside, top-aligned, with a small left/right padding.
        $pdf->SetXY($curX + 1, $y + 1);
        $pdf->MultiCell($w - 2, $lineHeight, $text, 0, $align);

        $curX += $w;
    }

    $pdf->SetXY($left, $y + $rowHeight);
}

/*
 * Column widths — unchanged from the original (10 + 45 + 55 + 50 + 30
 * = 190mm, matching FPDF's default ~190mm usable width on an A4 page
 * with its default margins), so the printed table lines up exactly the
 * same as before; only the wrapping/height behavior changed.
 */
define('TRX_COL_NUM', 10);
define('TRX_COL_RXNO', 45);
define('TRX_COL_PATIENT', 55);
define('TRX_COL_ADDRESS', 50);
define('TRX_COL_REMARKS', 30);
define('TRX_LINE_HEIGHT', 6);
define('TRX_PAGE_BOTTOM_MARGIN', 20); // leave room before the page edge before forcing a new page

// Generate PDF
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial','B',14);
// FIX: the 4th Cell() parameter (ln) must be 0, 1, or 2 per FPDF's own
// spec — '3' was never a valid value. Corrected to 1 (move to the next
// line), which was almost certainly the original intent.
$pdf->Cell(0,10,txt('HEALTH FACILITY TRANSMITTAL'),0,1,'C');

$pdf->SetFont('Arial','',12);
$pdf->Cell(0,8,txt('Date Generated: '.$trans['date_generated']),0,1);
$pdf->Cell(0,8,txt('Prescription Date: '.$trans['prescription_date']),0,1);
$pdf->Cell(0,8,txt('Delivery Date: '.$trans['delivery_date']),0,1);
$pdf->Cell(0,8,txt('Health Facility: '.$trans['health_facility']),0,1);
$pdf->Cell(0,8,txt('Pharmacist: '.$trans['pharmacist']),0,1);
$pdf->Ln(5);

function rxDrawTableHeader(FPDF $pdf): void {
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(TRX_COL_NUM,8,'#',1);
    $pdf->Cell(TRX_COL_RXNO,8,'Prescription No',1);
    $pdf->Cell(TRX_COL_PATIENT,8,'Patient Name',1);
    $pdf->Cell(TRX_COL_ADDRESS,8,'Address',1);
    $pdf->Cell(TRX_COL_REMARKS,8,'Remarks',1);
    $pdf->Ln();
}

rxDrawTableHeader($pdf);

$pdf->SetFont('Arial','',11); // slightly smaller than the header so wrapped rows fit more comfortably

foreach($prescriptions as $i=>$p){

    $numText = txt((string)($i+1));
    $rxNoText = txt($p['prescription_number']);
    $patientText = txt($p['patient_name']);
    $addressText = txt($p['house_no_street']);
    $remarksText = txt($p['remarks'] ?? '');

    // Compute how many lines each wrapping column needs, then use the
    // tallest one as this row's consistent height. # and Prescription
    // No rarely need wrapping, but they're included too in case a very
    // long prescription number ever shows up.
    $linesNum = rxCountWrappedLines($pdf, $numText, TRX_COL_NUM - 2);
    $linesRxNo = rxCountWrappedLines($pdf, $rxNoText, TRX_COL_RXNO - 2);
    $linesPatient = rxCountWrappedLines($pdf, $patientText, TRX_COL_PATIENT - 2);
    $linesAddress = rxCountWrappedLines($pdf, $addressText, TRX_COL_ADDRESS - 2);
    $linesRemarks = rxCountWrappedLines($pdf, $remarksText, TRX_COL_REMARKS - 2);

    $maxLines = max($linesNum, $linesRxNo, $linesPatient, $linesAddress, $linesRemarks, 1);
    $rowHeight = TRX_LINE_HEIGHT * $maxLines;

    // Simple pagination: if this row wouldn't fit before the bottom
    // margin, start a new page and redraw the table header so the
    // sheet is still readable without scrolling back to page 1.
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

// Sanitize the filename fragment — date_generated could contain
// characters (slashes, colons) that break a filename on some
// platforms if it ever includes a time component.
$filenameSafeDate = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$trans['date_generated']);
$pdf->Output('I','Transmittal_'.$filenameSafeDate.'.pdf');