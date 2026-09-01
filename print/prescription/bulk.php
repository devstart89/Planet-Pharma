<?php
session_start();

/*
|--------------------------------------------------------------------------
| BULK PRESCRIPTION PDF GENERATOR
|--------------------------------------------------------------------------
| Companion to generate_prescription_pdf.php (single prescription). This
| accepts MULTIPLE prescription IDs and combines them into ONE PDF, so a
| filtered set of prescriptions (e.g. by Health Facility) can be printed
| in one batch instead of one-at-a-time.
|
| All the drawing logic (header/footer/patient block/medicine slot,
| including every fix already made to the single-prescription version —
| text wrapping, MMM-DD-YYYY dates, auto +30-day refill, QTY (Doctor) vs
| QTY DISPENSED (Planet) semantics) is REUSED UNCHANGED here, so the two
| files can never visually drift apart from each other. Only the fetch
| step is wrapped in a function so it can run once per selected ID
| instead of once per request.
|
| Usage: generate_prescription_pdf_bulk.php?ids[]=101&ids[]=102&ids[]=103
|    or: generate_prescription_pdf_bulk.php?ids=101,102,103
|
| NOTE ON COPIES: the single-prescription generator prints RX_COPIES (3)
| physical copies per prescription, for handing to a patient at the point
| of care. That default would be wildly excessive here — printing a
| filtered batch of, say, 50 prescriptions would produce 150 pages'
| worth of duplicate copies. Bulk mode defaults to 1 copy per
| prescription; pass ?copies=N to override if you genuinely want more.
*/
if (!isset($_SESSION['user']) ||
    !in_array($_SESSION['user']['role'], ['health_facility', 'doctor', 'pharmacy', 'nurse', 'super_admin'])) {
    die("Unauthorized");
}

ob_start();

include '../../config/db.php';
require('../../assets/vendor/fpdf/fpdf.php');

date_default_timezone_set('Asia/Manila');

/* ---------- COLLECT REQUESTED IDS ---------- */
$rawIds = $_GET['ids'] ?? [];
if (is_string($rawIds)) {
    $rawIds = explode(',', $rawIds);
}
$prescriptionIds = array_values(array_unique(array_filter(array_map('intval', (array) $rawIds))));

if (empty($prescriptionIds)) {
    die("No prescription IDs provided.");
}

// Hard cap so a runaway request can't try to render an unbounded number
// of prescriptions into one PDF in a single request.
$MAX_BULK = 200;
if (count($prescriptionIds) > $MAX_BULK) {
    $prescriptionIds = array_slice($prescriptionIds, 0, $MAX_BULK);
}

$copiesPerPrescription = isset($_GET['copies']) ? max(1, (int) $_GET['copies']) : 1;

/*
|--------------------------------------------------------------------------
| ACCESS SCOPING
|--------------------------------------------------------------------------
| Mirrors the same scoping rules used elsewhere in this app: health_facility
| is scoped to their own facility; pharmacy only sees Signed prescriptions;
| doctor/nurse/super_admin are centralized (already established earlier
| in this project) and can select across facilities. This is enforced
| here server-side — the actual $prescriptionIds list is filtered down to
| only what THIS session is allowed to print, regardless of what IDs were
| requested, so a crafted request can't pull someone else's facility's
| prescriptions into a bulk print.
*/
$role = $_SESSION['user']['role'];
$sessionFacilityId = $_SESSION['user']['facility_id'] ?? null;

$placeholders = implode(',', array_fill(0, count($prescriptionIds), '?'));

if ($role === 'health_facility' && $sessionFacilityId) {
    $scopeStmt = $conn->prepare("
        SELECT id FROM prescriptions
        WHERE id IN ($placeholders) AND facility_id = ?
    ");
    $scopeStmt->execute([...$prescriptionIds, $sessionFacilityId]);
    $prescriptionIds = array_map('intval', $scopeStmt->fetchAll(PDO::FETCH_COLUMN));
} elseif ($role === 'pharmacy') {
    $scopeStmt = $conn->prepare("
        SELECT id FROM prescriptions
        WHERE id IN ($placeholders) AND status = 'Signed'
    ");
    $scopeStmt->execute($prescriptionIds);
    $prescriptionIds = array_map('intval', $scopeStmt->fetchAll(PDO::FETCH_COLUMN));
}
// doctor / nurse / super_admin: centralized access, no additional
// scoping — matches the same centralized-access rule already
// established for the Prescription List page.

/*
|--------------------------------------------------------------------------
| PRECHECK MODE
|--------------------------------------------------------------------------
| Pass ?precheck=1 to validate the requested IDs against this session's
| access rules WITHOUT generating the actual PDF, returning a small JSON
| response instead. This lets the calling page check "is there actually
| anything printable here?" via a quick fetch() BEFORE opening a new tab
| — so a genuinely empty/unauthorized selection shows a proper SweetAlert
| on the list page itself, instead of a raw, unstyled die() message
| appearing in a freshly-opened blank tab.
|
| This check MUST run before the die() below — otherwise the die() fires
| first for an empty result and this code is never reached at all.
*/
if (isset($_GET['precheck'])) {
    header('Content-Type: application/json');
    if (empty($prescriptionIds)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'None of the requested prescriptions are available to you.'
        ]);
    } else {
        echo json_encode(['status' => 'success', 'count' => count($prescriptionIds)]);
    }
    exit;
}

if (empty($prescriptionIds)) {
    die("None of the requested prescriptions are available to you.");
}

/*
|--------------------------------------------------------------------------
| FETCH + COMPUTE ALL DATA FOR ONE PRESCRIPTION
|--------------------------------------------------------------------------
| Refactored out of the single-prescription file's top-level script logic
| so it can run in a loop here. Every rule (Hospice date override, +30-day
| refill, dispensed-quantity, signature resolution) is IDENTICAL to the
| single-prescription generator — copy/pasted deliberately rather than
| shared via include, so this file has no runtime dependency on the other
| one and can't be broken by unrelated changes to it.
*/
function rxFetchPrescriptionData(PDO $conn, int $prescriptionId): ?array {

    $stmt = $conn->prepare("
        SELECT p.*,
               pat.first_name, pat.last_name, pat.his_id,
               pat.gender, pat.birthday,
               pat.house_no_street, pat.barangay,
               pat.contact_number, pat.last_refill_date
        FROM prescriptions p
        JOIN patients pat ON p.patient_id = pat.id
        WHERE p.id = ?
    ");
    $stmt->execute([$prescriptionId]);
    $prescription = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prescription) return null;

    $medStmt = $conn->prepare("
        SELECT medicine_name, dosage, frequency, duration, quantity, notes, dispensed_quantity
        FROM prescription_medicines
        WHERE prescription_id = ?
        ORDER BY id ASC
    ");
    $medStmt->execute([$prescriptionId]);
    $medicines = $medStmt->fetchAll(PDO::FETCH_ASSOC);

    $doctorName = '';
    $doctorLicenseNo = '';
    $doctorPtrNo = '';

    if (!empty($prescription['doctor_id'])) {
        try {
            $docStmt = $conn->prepare("
                SELECT first_name, last_name, license_number, ptr_number
                FROM users WHERE id = ? LIMIT 1
            ");
            $docStmt->execute([$prescription['doctor_id']]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Doctor lookup with license_number/ptr_number failed, retrying without them: ' . $e->getMessage());
            try {
                $docStmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
                $docStmt->execute([$prescription['doctor_id']]);
                $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e2) {
                error_log('Doctor lookup failed entirely: ' . $e2->getMessage());
                $doc = null;
            }
        }

        if ($doc) {
            $doctorName = trim(($doc['last_name'] ?? '') . ', ' . ($doc['first_name'] ?? ''));
            $doctorLicenseNo = $doc['license_number'] ?? '';
            $doctorPtrNo = $doc['ptr_number'] ?? '';
        }
    }

    $isHospicePrescription = false;
    if (!empty($prescription['facility_id'])) {
        $facStmt = $conn->prepare("SELECT facility_name FROM health_facilities WHERE id = ? LIMIT 1");
        $facStmt->execute([$prescription['facility_id']]);
        $facilityNameForRx = $facStmt->fetchColumn();
        $isHospicePrescription = $facilityNameForRx && stripos($facilityNameForRx, 'hospice') !== false;
    }

    $deliveryDateObj = null;
    if ($isHospicePrescription) {
        $tdStmt = $conn->prepare("
            SELECT t.delivery_date
            FROM transmittal_prescriptions tp
            JOIN transmittals t ON t.id = tp.transmittal_id
            WHERE tp.prescription_id = ?
            ORDER BY t.id DESC LIMIT 1
        ");
        $tdStmt->execute([$prescriptionId]);
        $deliveryDateRaw = trim((string) $tdStmt->fetchColumn());
        $isZeroDeliveryDate = preg_match('/^0000-00-00/', $deliveryDateRaw) === 1;

        if ($deliveryDateRaw !== '' && !$isZeroDeliveryDate) {
            try {
                $deliveryDateObj = new DateTime($deliveryDateRaw);
            } catch (Exception $e) {
                $deliveryDateObj = null;
            }
        }
    }

    $isSigned = ($prescription['status'] === 'Signed');

    $prescriptionDateObj = !empty($prescription['created_at'])
        ? new DateTime($prescription['created_at'])
        : new DateTime('now');

    if ($isHospicePrescription && $deliveryDateObj) {
        $prescriptionDateObj = clone $deliveryDateObj;
    }

    if ($isHospicePrescription && $deliveryDateObj) {
        $refillDateObj = (clone $deliveryDateObj)->modify('+1 month');
    } else {
        $refillDateObj = (clone $prescriptionDateObj)->modify('+30 days');
    }

    $dateDisplay = rxFormatDatePretty($prescriptionDateObj);
    $lastRefillDateDisplay = rxFormatDatePretty($refillDateObj);

    $signatureImagePath = $isSigned ? resolveSignatureImagePath($prescription['signature_path']) : null;

    $age = '';
    if (!empty($prescription['birthday'])) {
        $bday = new DateTime($prescription['birthday']);
        $today = new DateTime('today');
        $age = $bday->diff($today)->y;
    }

    $patientName = strtoupper(trim($prescription['last_name'] . ', ' . $prescription['first_name']));
    $address = trim(($prescription['house_no_street'] ?? '') . ', ' . ($prescription['barangay'] ?? ''), ', ');

    return [
        'prescription' => $prescription,
        'medicines' => $medicines,
        'doctorName' => $doctorName,
        'doctorLicenseNo' => $doctorLicenseNo,
        'doctorPtrNo' => $doctorPtrNo,
        'isSigned' => $isSigned,
        'dateDisplay' => $dateDisplay,
        'lastRefillDateDisplay' => $lastRefillDateDisplay,
        'signatureImagePath' => $signatureImagePath,
        'age' => $age,
        'patientName' => $patientName,
        'address' => $address,
        'rxNo' => $prescription['prescription_number'] ?? $prescription['id'],
    ];
}

/* =========================================================
   Everything below (encoding helper, wrapping helper, all the
   rxDraw* functions, and the constants) is copied UNCHANGED from
   generate_prescription_pdf.php — see that file's comments for the
   detailed reasoning behind each fix. Kept identical on purpose so
   a bulk-printed prescription looks pixel-for-pixel the same as
   one printed individually.
========================================================= */

function rxFormatDatePretty(?DateTime $d): string {
    if (!$d) return '';
    return $d->format('M-d-Y');
}

function resolveSignatureImagePath($signaturePath) {
    if (empty($signaturePath)) return null;

    if (strpos($signaturePath, 'data:image') === 0) {
        if (!preg_match('/^data:image\/(\w+);base64,/', $signaturePath, $matches)) {
            return null;
        }
        $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $base64Data = substr($signaturePath, strpos($signaturePath, ',') + 1);
        $binary = base64_decode($base64Data);
        if ($binary === false) return null;
        $tmpPath = sys_get_temp_dir() . '/rx_sig_' . uniqid() . '.' . $ext;
        file_put_contents($tmpPath, $binary);
        return $tmpPath;
    }

    return file_exists($signaturePath) ? $signaturePath : null;
}

function rxResolveLogoPath(array $candidates): ?string {
    foreach ($candidates as $path) {
        $resolved = realpath($path);
        if ($resolved && file_exists($resolved)) {
            return $resolved;
        }
    }
    return null;
}

$mhdLogoPath = rxResolveLogoPath([
    '../../modules/logo/MHD.png',
    '../../modules/logo/mhd_logo.png',
    '../../assets/images/mhd.png',
]);

$planetLogoPath = rxResolveLogoPath([
    '../../modules/logo/PLANET RED.png',
    '../../modules/logo/planet_drugstore_logo.png',
    '../../assets/images/planet_logo.png',
]);

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

function rxCountWrappedLines(FPDF $pdf, string $renderedText, float $width, int $maxLines = 2): int {
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

define('RX_NAVY_R', 40);
define('RX_NAVY_G', 28);
define('RX_NAVY_B', 118);
define('RX_PAGE_W', 210);
define('RX_PAGE_H', 297);
define('RX_MARGIN_L', 12);
define('RX_MARGIN_R', 198);
define('RX_SLOTS_PER_PAGE', 5);

function rxDrawCircle(FPDF $pdf, float $cx, float $cy, float $r, int $segments = 40): void {
    $prevX = $cx + $r;
    $prevY = $cy;
    for ($i = 1; $i <= $segments; $i++) {
        $angle = 2 * M_PI * $i / $segments;
        $x = $cx + $r * cos($angle);
        $y = $cy + $r * sin($angle);
        $pdf->Line($prevX, $prevY, $x, $y);
        $prevX = $x;
        $prevY = $y;
    }
}

function rxDrawBar(FPDF $pdf, float $y, float $height): void {
    $pdf->SetFillColor(RX_NAVY_R, RX_NAVY_G, RX_NAVY_B);
    $pdf->Rect(0, $y, RX_PAGE_W, $height, 'F');
}

function rxDrawLogoImage(FPDF $pdf, string $path, float $cx, float $cy, float $box): void {
    $size = @getimagesize($path);
    if (!$size || $size[0] <= 0 || $size[1] <= 0) {
        $pdf->Image($path, $cx - $box / 2, $cy - $box / 2, $box, $box);
        return;
    }
    $imgW = $size[0];
    $imgH = $size[1];
    if ($imgW >= $imgH) {
        $drawW = $box;
        $drawH = $box * ($imgH / $imgW);
    } else {
        $drawH = $box;
        $drawW = $box * ($imgW / $imgH);
    }
    $pdf->Image($path, $cx - $drawW / 2, $cy - $drawH / 2, $drawW, $drawH);
}

function rxDrawHeader(FPDF $pdf, ?string $mhdLogoPath, ?string $planetLogoPath): void {
    rxDrawBar($pdf, 0, 6);
    $pdf->SetTextColor(RX_NAVY_R, RX_NAVY_G, RX_NAVY_B);
    $pdf->SetDrawColor(RX_NAVY_R, RX_NAVY_G, RX_NAVY_B);
    $pdf->SetLineWidth(0.4);

    $leftCx = 23; $leftCy = 20; $leftR = 11;
    if ($mhdLogoPath) {
        rxDrawLogoImage($pdf, $mhdLogoPath, $leftCx, $leftCy, $leftR * 2);
    } else {
        rxDrawCircle($pdf, $leftCx, $leftCy, $leftR);
        $pdf->SetFont('Arial', '', 6);
        $pdf->SetXY($leftCx - $leftR, $leftCy - 2);
        $pdf->Cell($leftR * 2, 4, txt('MHD SEAL'), 0, 0, 'C');
    }

    $rightCx = RX_PAGE_W - 23; $rightCy = 20; $rightR = 11;
    if ($planetLogoPath) {
        rxDrawLogoImage($pdf, $planetLogoPath, $rightCx, $rightCy, $rightR * 2);
    } else {
        rxDrawCircle($pdf, $rightCx, $rightCy, $rightR);
        $pdf->SetFont('Arial', '', 6);
        $pdf->SetXY($rightCx - $rightR, $rightCy - 2);
        $pdf->Cell($rightR * 2, 4, txt('PLANET LOGO'), 0, 0, 'C');
    }

    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetXY(38, 12);
    $pdf->Cell(RX_PAGE_W - 76, 7, txt('MAKATI HEALTH DEPARTMENT'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetX(38);
    $pdf->Cell(RX_PAGE_W - 76, 6, txt('PLANET DRUGSTORE CORP.'), 0, 1, 'C');

    $pdf->SetLineWidth(0.5);
    $pdf->Line(RX_MARGIN_L, 34, RX_MARGIN_R, 34);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);
}

function rxDrawPatientBlock(FPDF $pdf, string $patientName, $age, string $gender, string $address, string $dateDisplay, bool $isSigned): float {
    $pdf->SetTextColor(RX_NAVY_R, RX_NAVY_G, RX_NAVY_B);
    $pdf->SetFont('Times', 'BI', 34);
    $pdf->SetXY(RX_MARGIN_L, 40);
    $pdf->Cell(10, 16, 'R', 0, 0);
    $pdf->SetFont('Times', 'BI', 20);
    $pdf->SetXY(RX_MARGIN_L + 9, 48);
    $pdf->Cell(8, 10, 'x', 0, 0);
    $pdf->SetTextColor(0, 0, 0);

    $labelX = 40;
    $fieldRight = RX_MARGIN_R;

    $pdf->SetXY($labelX, 42);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(14, 6, txt('Name:'), 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(95, 6, txt($patientName), 'B', 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(12, 6, txt('Age:'), 0, 0, 'R');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(14, 6, txt((string)$age), 'B', 0, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(11, 6, txt('Sex:'), 0, 0, 'R');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($fieldRight - $pdf->GetX(), 6, txt($gender), 'B', 1, 'C');

    $addressRowY = 48;
    $addressValueWidth = 86;
    $addressRenderedText = txt($address);

    $pdf->SetXY($labelX, $addressRowY);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(20, 6, txt('Address:'), 0, 0);

    $addressValueX = $pdf->GetX();
    $pdf->SetFont('Arial', 'B', 10);
    $addressLines = rxCountWrappedLines($pdf, $addressRenderedText, $addressValueWidth, 2);

    if ($addressLines <= 1) {
        $pdf->Cell($addressValueWidth, 6, $addressRenderedText, 'B', 0);
    } else {
        $pdf->MultiCell($addressValueWidth, 6, $addressRenderedText, 'B', 'L');
        $pdf->SetXY($addressValueX + $addressValueWidth, $addressRowY);
    }

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(14, 6, txt('Date:'), 0, 0, 'R');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($fieldRight - $pdf->GetX(), 6, txt($dateDisplay), 'B', 1, 'C');

    $nextRowY = $addressRowY + 6;

    if ($addressLines > 1) {
        $pdf->SetXY($labelX, $nextRowY);
        $pdf->SetFont('Arial', 'B', 10);
        $fullWidth = $fieldRight - $labelX;
        $words = preg_split('/\s+/', $addressRenderedText, -1, PREG_SPLIT_NO_EMPTY);
        $line1 = '';
        $remainder = '';
        $building = true;
        foreach ($words as $word) {
            if ($building) {
                $candidate = $line1 === '' ? $word : ($line1 . ' ' . $word);
                if ($pdf->GetStringWidth($candidate) > $addressValueWidth && $line1 !== '') {
                    $building = false;
                    $remainder = $word;
                } else {
                    $line1 = $candidate;
                }
            } else {
                $remainder = $remainder === '' ? $word : ($remainder . ' ' . $word);
            }
        }
        $pdf->Cell($fullWidth, 6, txt($remainder), 'B', 1);
        $nextRowY += 6;
    }

    if (!$isSigned) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetXY($labelX, $nextRowY + 2);
        $pdf->Cell(0, 5, txt('DRAFT — NOT YET SIGNED BY ATTENDING DOCTOR'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $nextRowY += 8;
    } else {
        $nextRowY += 2;
    }

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(RX_MARGIN_L, $nextRowY + 4);
    $pdf->Cell(0, 6, txt('[  ] PHC     [  ] YC     [  ] SC     [  ] PWD'), 0, 1, 'C');

    return $nextRowY + 10;
}

function rxDrawMedicineSlot(FPDF $pdf, int $index, ?array $medicine, float $y): float {
    $left = RX_MARGIN_L;
    $right = RX_MARGIN_R;

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetXY($left, $y);
    $pdf->Cell(7, 6, $index . '.', 0, 0);

    $nameLineStart = $left + 7;
    $nameLineEnd = $right - 28;
    $dosageLineStart = $nameLineEnd + 3;
    $dosageLineEnd = $right - 7;
    $nameColWidth = $nameLineEnd - $nameLineStart;

    $nameLines = 1;
    $nameRenderedText = $medicine ? txt($medicine['medicine_name']) : '';

    if ($medicine) {
        $pdf->SetFont('Arial', 'B', 10);
        $nameLines = rxCountWrappedLines($pdf, $nameRenderedText, $nameColWidth, 2);

        if ($nameLines <= 1) {
            $pdf->SetXY($nameLineStart, $y - 1);
            $pdf->Cell($nameColWidth, 6, $nameRenderedText, 0, 0);
        } else {
            $pdf->SetXY($nameLineStart, $y - 1);
            $pdf->MultiCell($nameColWidth, 6, $nameRenderedText, 0, 'L');
        }

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($dosageLineStart, $y - 1);
        $pdf->Cell($dosageLineEnd - $dosageLineStart, 6, txt($medicine['dosage']), 0, 0, 'R');
    }
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetXY($right - 6, $y);
    $pdf->Cell(6, 6, 'Mg', 0, 1);

    $nameRowHeight = 6 * $nameLines;
    $underlineY = $y - 1 + $nameRowHeight;

    $pdf->SetLineWidth(0.2);
    $pdf->Line($nameLineStart, $underlineY, $nameLineEnd, $underlineY);
    $pdf->Line($dosageLineStart, $underlineY, $dosageLineEnd, $underlineY);

    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetXY($nameLineStart, $underlineY + 0.3);
    $pdf->Cell($nameLineEnd - $nameLineStart, 3.5, txt('Generic  Name'), 0, 0, 'C');
    $pdf->SetXY($dosageLineStart, $underlineY + 0.3);
    $pdf->Cell($dosageLineEnd - $dosageLineStart, 3.5, txt('Dosage'), 0, 0, 'C');

    $checkboxY = $underlineY + 5;
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($left + 7, $checkboxY);
    $pdf->Cell(0, 5, txt('[  ] Tablet   [  ] Capsule   [  ] Syrup   [  ] Drops   [  ] Others ____________'), 0, 1);

    $signaY = $checkboxY + 5;
    $pdf->SetXY($left + 7, $signaY);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(14, 5, txt('Signa:'), 0, 0);
    $signaEnd = $right - 55;
    $pdf->SetFont('Arial', 'B', 9);
    if ($medicine) {
        $pdf->Cell($signaEnd - $pdf->GetX(), 5, txt($medicine['frequency']), 'B', 0);
    } else {
        $pdf->Cell($signaEnd - $pdf->GetX(), 5, '', 'B', 0);
    }
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetX($signaEnd + 3);
    $pdf->Cell(24, 5, txt('Per Day For'), 0, 0);
    $pdf->SetFont('Arial', 'B', 9);
    $durationText = $medicine ? txt($medicine['duration']) : '';
    $pdf->Cell(12, 5, $durationText, 'B', 0, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell($right - $pdf->GetX(), 5, txt(' Day(s)'), 0, 1);

    $noteY = $signaY + 5;
    $pdf->SetXY($left + 7, $noteY);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(58, 5, txt('Note:  Total quantity to be dispensed #'), 0, 0);
    $pdf->SetFont('Arial', 'B', 9);
    $qtyEnd = $pdf->GetX() + 16;
    $dispensedQtyText = ($medicine && $medicine['dispensed_quantity'] !== null)
        ? txt($medicine['dispensed_quantity'])
        : '';
    $pdf->Cell(16, 5, $dispensedQtyText, 'B', 0, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetX($qtyEnd + 4);
    $pdf->Cell(38, 5, txt('Quantity to consume #'), 0, 0);
    $pdf->SetFont('Arial', 'B', 9);
    if ($medicine) {
        $pdf->Cell($right - $pdf->GetX(), 5, txt($medicine['quantity']), 'B', 1, 'C');
    } else {
        $pdf->Cell($right - $pdf->GetX(), 5, '', 'B', 1);
    }

    $remarksLines = 0;
    $remarksY = $noteY + 5;

    if ($medicine && !empty($medicine['notes'])) {
        $remarksLabel = txt('Remarks: ');
        $remarksText = txt($medicine['notes']);
        $remarksWidth = $right - ($left + 7);

        $pdf->SetFont('Arial', 'I', 8);
        $labelWidth = $pdf->GetStringWidth($remarksLabel);
        $remarksLines = rxCountWrappedLines($pdf, $remarksText, $remarksWidth - $labelWidth, 2);

        $pdf->SetXY($left + 7, $remarksY);
        $pdf->Cell($labelWidth, 4, $remarksLabel, 0, 0);

        if ($remarksLines <= 1) {
            $pdf->Cell($remarksWidth - $labelWidth, 4, $remarksText, 0, 1);
        } else {
            $pdf->MultiCell($remarksWidth - $labelWidth, 4, $remarksText, 0, 'L');
        }
    }

    $ruleY = $remarksLines > 0
        ? ($remarksY + (4 * $remarksLines) + 1)
        : ($noteY + 9);

    $pdf->SetLineWidth(0.4);
    $pdf->Line($left, $ruleY, $right, $ruleY);
    $pdf->Line($left, $ruleY + 0.8, $right, $ruleY + 0.8);
    $pdf->SetLineWidth(0.2);

    return ($ruleY + 1) - $y;
}

function rxDrawFooter(FPDF $pdf, bool $isSigned, ?string $signatureImagePath, string $doctorName, string $doctorLicenseNo, string $doctorPtrNo, string $lastRefillDateDisplay): void {
    $y = RX_PAGE_H - 34;
    $left = RX_MARGIN_L;
    $right = RX_MARGIN_R;

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($left, $y + 14);
    $pdf->Cell(22, 6, txt('Refill Date:'), 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(50, 6, $lastRefillDateDisplay !== '' ? txt($lastRefillDateDisplay) : '', 'B', 0);

    $blockX = 120;
    if ($isSigned && $signatureImagePath) {
        $pdf->Image($signatureImagePath, $blockX + 20, $y - 8, 35);
    }

    $pdf->SetXY($blockX, $y);
    $pdf->Cell(14, 6, 'M.D.', 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($right - $pdf->GetX(), 6, $isSigned ? txt(strtoupper($doctorName)) : '', 'B', 1);

    $pdf->SetX($blockX);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(24, 6, txt('License No.'), 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($right - $pdf->GetX(), 6, $doctorLicenseNo !== '' ? txt($doctorLicenseNo) : '', 'B', 1);

    $pdf->SetX($blockX);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(18, 6, txt('PTR No.'), 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($right - $pdf->GetX(), 6, $doctorPtrNo !== '' ? txt($doctorPtrNo) : '', 'B', 1);

    rxDrawBar($pdf, RX_PAGE_H - 6, 6);
}

/* =========================================================
   MAIN — loop every selected prescription into ONE shared PDF
========================================================= */

$pdf = new FPDF();
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0, 0);

$tempSignatureFiles = [];
$skippedIds = [];

foreach ($prescriptionIds as $prescriptionId) {

    $data = rxFetchPrescriptionData($conn, $prescriptionId);

    if (!$data) {
        $skippedIds[] = $prescriptionId;
        continue;
    }

    if ($data['signatureImagePath'] && strpos($data['signatureImagePath'], sys_get_temp_dir()) === 0) {
        $tempSignatureFiles[] = $data['signatureImagePath'];
    }

    $medicines = $data['medicines'];
    $chunks = array_chunk($medicines, RX_SLOTS_PER_PAGE);
    if (empty($chunks)) {
        $chunks = [[]];
    }

    for ($copy = 1; $copy <= $copiesPerPrescription; $copy++) {

        foreach ($chunks as $chunkIndex => $chunk) {
            $pdf->AddPage();

            rxDrawHeader($pdf, $mhdLogoPath, $planetLogoPath);

            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY(RX_MARGIN_R - 70, 1);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(70, 5, txt('Rx No.: ' . $data['rxNo']), 0, 0, 'R');
            $pdf->SetTextColor(0, 0, 0);

            $patientBlockBottomY = rxDrawPatientBlock(
                $pdf, $data['patientName'], $data['age'], $data['prescription']['gender'],
                $data['address'], $data['dateDisplay'], $data['isSigned']
            );

            if (!empty($data['prescription']['diagnosis'])) {
                $diagnosisText = txt($data['prescription']['diagnosis']);
                $diagnosisWidth = RX_MARGIN_R - RX_MARGIN_L - 22;

                $pdf->SetXY(RX_MARGIN_L, $patientBlockBottomY);
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->Cell(22, 5, txt('Diagnosis:'), 0, 0);
                $pdf->SetFont('Arial', '', 9);

                $diagnosisLines = rxCountWrappedLines($pdf, $diagnosisText, $diagnosisWidth, 2);
                if ($diagnosisLines <= 1) {
                    $pdf->Cell($diagnosisWidth, 5, $diagnosisText, 0, 1);
                    $slotStartY = $patientBlockBottomY + 8;
                } else {
                    $pdf->MultiCell($diagnosisWidth, 5, $diagnosisText, 0, 'L');
                    $slotStartY = $patientBlockBottomY + 5 + (5 * $diagnosisLines) + 3;
                }
            } else {
                $slotStartY = $patientBlockBottomY + 2;
            }

            $y = $slotStartY;
            foreach (range(0, RX_SLOTS_PER_PAGE - 1) as $i) {
                $medicine = $chunk[$i] ?? null;
                $globalIndex = ($chunkIndex * RX_SLOTS_PER_PAGE) + $i + 1;
                $usedHeight = rxDrawMedicineSlot($pdf, $globalIndex, $medicine, $y);
                $y += $usedHeight;
            }

            if (!empty($data['prescription']['remarks'])) {
                $remarksText = txt($data['prescription']['remarks']);
                $remarksWidth = RX_MARGIN_R - RX_MARGIN_L - 20;

                $pdf->SetXY(RX_MARGIN_L, $y + 2);
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->Cell(20, 5, txt('Remarks:'), 0, 0);
                $pdf->SetFont('Arial', '', 9);

                $pageRemarksLines = rxCountWrappedLines($pdf, $remarksText, $remarksWidth, 2);
                if ($pageRemarksLines <= 1) {
                    $pdf->Cell($remarksWidth, 5, $remarksText, 0, 1);
                } else {
                    $pdf->MultiCell($remarksWidth, 5, $remarksText, 0, 'L');
                }
            }

            rxDrawFooter(
                $pdf, $data['isSigned'], $data['signatureImagePath'],
                $data['doctorName'], $data['doctorLicenseNo'], $data['doctorPtrNo'],
                $data['lastRefillDateDisplay']
            );
        }
    }
}

if ($pdf->PageNo() === 0) {
    die("None of the requested prescriptions could be rendered.");
}

if (ob_get_level() > 0) {
    ob_end_clean();
}

$outputMode = (isset($_GET['download']) && $_GET['download'] == '1') ? 'D' : 'I';
$filename = 'Prescriptions_Bulk_' . date('Ymd_His') . '.pdf';

$pdf->Output($outputMode, $filename);

foreach ($tempSignatureFiles as $tmp) {
    @unlink($tmp);
}