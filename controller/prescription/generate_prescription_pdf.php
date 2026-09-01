<?php
session_start();

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
| Mirrors prescription_details.php: both health_facility and doctor roles
| may view a prescription's PDF (transmittal_pdf.php only allowed
| health_facility, but a single prescription's PDF is also needed by the
| doctor's own Prescription Details page).
*/
if (!isset($_SESSION['user']) ||
    !in_array($_SESSION['user']['role'], ['health_facility', 'doctor', 'pharmacy'])) {
    die("Unauthorized");
}

/*
|--------------------------------------------------------------------------
| OUTPUT BUFFERING SAFETY NET
|--------------------------------------------------------------------------
| FPDF sends its own headers + binary PDF data via Output(). If ANYTHING
| else gets printed first — a stray warning, a deprecation notice, even
| whitespace outside the <?php tags in an included file — FPDF fails with
| "Some data has already been output, can't send PDF file", because from
| its perspective the HTTP response has already started.
|
| Buffering here means any accidental stray output gets swallowed instead
| of reaching the browser before the PDF headers. This is a safety net,
| not a substitute for fixing the actual source of stray output (see the
| iconv() based txt() helper below).
*/
ob_start();

include '../../config/db.php';
require('../../assets/vendor/fpdf/fpdf.php');

date_default_timezone_set('Asia/Manila');

$prescription_id = intval($_GET['id'] ?? 0);
if (!$prescription_id) die("Invalid Prescription ID");

/*
|--------------------------------------------------------------------------
| FETCH PRESCRIPTION + PATIENT
|--------------------------------------------------------------------------
| CONFIRMED (from prescription_details.php): prescriptions (id, patient_id,
| diagnosis, remarks, is_refill, status, signed_at, signature_path,
| created_at, doctor_id, facility_id), patients (first_name, last_name,
| his_id, gender, birthday, house_no_street, barangay, contact_number).
|
| last_refill_date is CONFIRMED to live on patients (not prescriptions),
| so it's pulled in explicitly here via `pat.last_refill_date`.
*/
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
$stmt->execute([$prescription_id]);
$prescription = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prescription) die("Prescription not found");

/*
|--------------------------------------------------------------------------
| FETCH MEDICINES
|--------------------------------------------------------------------------
*/
$medStmt = $conn->prepare("
    SELECT medicine_name, dosage, frequency, duration, quantity, notes, dispensed_quantity
    FROM prescription_medicines
    WHERE prescription_id = ?
    ORDER BY id ASC
");
$medStmt->execute([$prescription_id]);
$medicines = $medStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DOCTOR INFO
|--------------------------------------------------------------------------
| CONFIRMED: users.license_number and users.ptr_number (added alongside
| the Account Settings page's License Number / PTR Number fields — see
| the ALTER TABLE that added both columns). Both are nullable, since
| older doctor accounts may not have filled them in yet, so this still
| falls back gracefully if either the columns or the whole lookup fail
| for any reason (e.g. querying against a DB that hasn't been migrated
| yet) rather than fatal-erroring PDF generation.
*/
$doctorName = '';
$doctorLicenseNo = '';
$doctorPtrNo = '';

if (!empty($prescription['doctor_id'])) {
    try {
        $docStmt = $conn->prepare("
            SELECT first_name, last_name, license_number, ptr_number
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $docStmt->execute([$prescription['doctor_id']]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Most likely cause: users.license_number / users.ptr_number
        // don't exist yet on this DB. Fall back to a query without them
        // so the doctor's NAME still shows up — license/PTR just won't,
        // until those columns exist (the footer lines below are simply
        // left blank when $doctorLicenseNo / $doctorPtrNo are empty).
        error_log('Doctor lookup with license_number/ptr_number failed, retrying without them: ' . $e->getMessage());
        try {
            $docStmt = $conn->prepare("
                SELECT first_name, last_name
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $docStmt->execute([$prescription['doctor_id']]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            // Even first_name/last_name don't match — log and move on
            // with a blank doctor name rather than a fatal 500.
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

/*
|--------------------------------------------------------------------------
| ITEM 6.2 — HOSPICE: Delivery Date shown as Prescription Date,
| Refill Date auto-calculated as Delivery Date + 1 month
|--------------------------------------------------------------------------
| A prescription is treated as "Hospice" if the facility it was created
| under (prescriptions.facility_id -> health_facilities) has "hospice"
| in its name — same detection approach used in the Generate Transmittal
| page (item 6.1), since health_facilities has no dedicated type column
| yet (confirmed via SHOW CREATE TABLE: only id, pharmacy_id,
| facility_name, address, contact_number, status exist).
|
| The Delivery Date itself isn't captured on the prescription — it's
| entered later, per-transmittal, when a Hospice health_facility account
| generates a transmittal (item 6.1's Delivery Date field). So this looks
| up the delivery_date from whichever transmittal this prescription was
| included in, via the transmittal_prescriptions bridge table (confirmed
| to exist and structured this way in transmittal_pdf.php).
|
| If this Hospice prescription hasn't been transmitted yet, there's no
| delivery_date to pull, so the PDF falls back to its normal behavior
| (created_at as the printed Date, and the standard +30-day refill rule
| below) until it has been.
*/
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
        ORDER BY t.id DESC
        LIMIT 1
    ");
    $tdStmt->execute([$prescription_id]);
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

/*
|--------------------------------------------------------------------------
| ITEM 8 — DATE DISPLAY FORMAT + AUTOMATIC REFILL DATE
|--------------------------------------------------------------------------
| FIX: dates now print as MMM-DD-YYYY (e.g. "Aug-20-2026") instead of
| Y-m-d, via rxFormatDatePretty() below.
|
| FIX: the Refill Date is now ALWAYS auto-computed as Prescription Date
| + 30 days, rather than pulled from patients.last_refill_date. This
| replaces the previous behavior (reading a separately stored/manual
| refill date) with a rule computed directly from whatever date is
| printed as the Prescription Date on THIS slip — including the Hospice
| override below, which still takes priority for Hospice prescriptions
| specifically (Delivery Date + 1 month, per item 6.2's existing rule,
| unchanged from before).
|
| $prescriptionDateObj is the single source of truth for the printed
| "Date" field; $refillDateObj is always derived from it so the two
| can never drift out of sync with each other.
*/
function rxFormatDatePretty(?DateTime $d): string {
    if (!$d) return '';
    return $d->format('M-d-Y'); // e.g. "Aug-20-2026"
}

$prescriptionDateObj = !empty($prescription['created_at'])
    ? new DateTime($prescription['created_at'])
    : new DateTime('now');

// Item 6.2: Hospice prescriptions with a known transmittal delivery date
// show that Delivery Date as the printed Prescription Date instead of
// created_at.
if ($isHospicePrescription && $deliveryDateObj) {
    $prescriptionDateObj = clone $deliveryDateObj;
}

if ($isHospicePrescription && $deliveryDateObj) {
    // Item 6.2's existing rule: Refill = Delivery Date + 1 month.
    // NOTE: DateTime::modify('+1 month') has a known quirk on month-end
    // dates — e.g. Jan 31 + 1 month rolls over to Mar 3 (since Feb has
    // no 31st), not Feb 28. Flag if your delivery dates commonly land
    // on the 29th-31st and this needs to clamp to the last day of the
    // following month instead.
    $refillDateObj = (clone $deliveryDateObj)->modify('+1 month');
} else {
    // Item 8's new default rule: Refill = Prescription Date + 30 days.
    $refillDateObj = (clone $prescriptionDateObj)->modify('+30 days');
}

$dateDisplay = rxFormatDatePretty($prescriptionDateObj);
$lastRefillDateDisplay = rxFormatDatePretty($refillDateObj);

/*
|--------------------------------------------------------------------------
| SIGNATURE IMAGE RESOLUTION
|--------------------------------------------------------------------------
| Two different signing flows exist in this codebase:
|   - prescription_process.php reads a pre-registered file from
|     users.signature_path when a doctor submits directly.
|   - prescription_details.php captures a LIVE signature via canvas and
|     posts it as a base64 data URI to prescription_approve_process.php
|     (that controller's storage behavior is still unconfirmed).
| This handles BOTH cases: if signature_path is a base64 data URI, decode
| it to a temp file; otherwise treat it as a filesystem path directly.
*/
function resolveSignatureImagePath($signaturePath) {

    if (empty($signaturePath)) return null;

    if (strpos($signaturePath, 'data:image') === 0) {

        // e.g. "data:image/png;base64,iVBORw0KG..."
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

    // Otherwise assume it's a filesystem path already.
    return file_exists($signaturePath) ? $signaturePath : null;
}

$signatureImagePath = $isSigned ? resolveSignatureImagePath($prescription['signature_path']) : null;

/*
|--------------------------------------------------------------------------
| LOGO RESOLUTION
|--------------------------------------------------------------------------
| Real artwork now exists (the MHD seal and the Planet Drugstore logo).
| Both should be dropped into assets/images/. Several candidate filenames
| are checked so this keeps working whichever exact name the files are
| saved under; if neither is found, the header falls back to drawn
| placeholder circles so the layout still matches the pad.
*/
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

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
| Core FPDF (unlike FPDF's UTF-8 forks) expects Windows-1252 (CP1252)
| text, not UTF-8. utf8_decode()/utf8_encode() used to be the standard
| way to bridge that gap, but both are DEPRECATED as of PHP 8.2 and will
| be REMOVED in a future PHP version. Calling them prints a "Deprecated"
| notice on every call — and since those notices are themselves output,
| printed before FPDF sends its headers, that is exactly what caused the
| "Some data has already been output, can't send PDF file" fatal error.
|
| iconv() with the TRANSLIT flag does the same UTF-8 -> CP1252 conversion
| without being deprecated, and TRANSLIT means characters with no CP1252
| equivalent get approximated instead of causing a hard failure. IGNORE
| additionally drops anything iconv still can't represent, so a stray
| unsupported character can't take down PDF generation entirely.
*/
function txt($value) {
    $value = (string) $value;

    if ($value === '') {
        return $value;
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }

    // Fallback if iconv isn't available in this PHP build for some reason.
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
    }

    return $value;
}

/*
|--------------------------------------------------------------------------
| ITEM 6 — TEXT WRAPPING HELPER
|--------------------------------------------------------------------------
| Core FPDF's Cell() never wraps — a value wider than its box either gets
| visually clipped or, with no border/background, silently overflows on
| top of whatever's next to it. That's exactly what happened with long
| addresses, generic names, and remarks (see the screenshot with
| "OVERTYUGASDFGHJKLZXCVBNM.. Bangkal Lying-in Clinic" overflowing past
| its column). MultiCell() wraps automatically, but always reserves a
| FIXED number of lines' worth of height, breaking the pad's fixed-row
| layout unless something adjusts the following content's position to
| match.
|
| rxCountWrappedLines() estimates — using the same font currently set on
| $pdf, so it must be called AFTER SetFont() for that text — how many
| lines MultiCell() will actually need for a given column width, capped
| at $maxLines. Callers use this to shift everything below that field
| down by exactly the right amount, instead of guessing or leaving fixed
| dead space for every entry regardless of whether it actually wrapped.
*/
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
            if ($lines >= $maxLines) {
                break;
            }
        } else {
            $currentLine = $candidate;
        }
    }

    return min($lines, $maxLines);
}

$age = '';
if (!empty($prescription['birthday'])) {
    $bday = new DateTime($prescription['birthday']);
    $today = new DateTime('today');
    $age = $bday->diff($today)->y;
}

$patientName = strtoupper(trim($prescription['last_name'] . ', ' . $prescription['first_name']));
$address = trim(($prescription['house_no_street'] ?? '') . ', ' . ($prescription['barangay'] ?? ''), ', ');

/*
|--------------------------------------------------------------------------
| GENERATE PDF — Makati Health Department / Planet Drugstore Corp pad
|--------------------------------------------------------------------------
| Layout mirrors the physical Rx pad: navy top/bottom bars, twin-logo
| header, big "Rx" mark, Name/Age/Sex + Address/Date rows, category
| checkboxes, up to 5 numbered medicine slots per page (each with its own
| Tablet/Capsule/Syrup/Drops/Others checkboxes and Signa/Note lines), and
| a Refill Date / M.D. / License No. / PTR No. footer.
|
| ITEM 6 (text wrapping): medicine slot heights are no longer fixed —
| rxDrawMedicineSlot() returns however much vertical space it actually
| used (base height, plus extra for a wrapped generic name and/or wrapped
| remarks), and the page-drawing loop below advances by that amount
| rather than a constant. Same idea for the Address field in the patient
| block: rxDrawPatientBlock() returns the Y coordinate where it actually
| finished, and everything below it (Diagnosis, the medicine slots) is
| positioned from that returned value instead of a hardcoded constant.
*/

define('RX_NAVY_R', 40);
define('RX_NAVY_G', 28);
define('RX_NAVY_B', 118);

define('RX_PAGE_W', 210);   // A4 width in mm
define('RX_PAGE_H', 297);   // A4 height in mm
define('RX_MARGIN_L', 12);
define('RX_MARGIN_R', 198); // usable right edge
define('RX_SLOTS_PER_PAGE', 5);

// Item 9: print 3 physical copies automatically. True browser/print-
// dialog copy counts can't be forced from a generated PDF file (no
// browser reliably honors that from a URL parameter or PDF metadata) —
// the robust, cross-viewer way to guarantee 3 copies print is to include
// the same set of pages 3 times in the PDF itself, so printing "all
// pages" naturally produces 3 physical copies of every sheet regardless
// of which PDF viewer or printer dialog is used.
define('RX_COPIES', 3);

/**
 * Approximates a circle using short line segments — core FPDF has no
 * native Ellipse/Circle primitive.
 */
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

/**
 * Solid navy bar spanning the full page width, used for the top/bottom
 * edges of every page (matches the pad's printed border).
 */
function rxDrawBar(FPDF $pdf, float $y, float $height): void {
    $pdf->SetFillColor(RX_NAVY_R, RX_NAVY_G, RX_NAVY_B);
    $pdf->Rect(0, $y, RX_PAGE_W, $height, 'F');
}

/**
 * Draws a logo image centered inside a square bounding box of side
 * $box, scaling it to CONTAIN within the box (preserving its original
 * aspect ratio) rather than stretching it to fill a square. The MHD
 * seal and the Planet Drugstore logo aren't the same shape (one's a
 * round seal, the other's a squarer badge with text top and bottom),
 * so forcing both into an identical width x height box would distort
 * whichever one isn't naturally square.
 *
 * Falls back to the old stretch-to-square behavior only if the image's
 * real dimensions can't be read (getimagesize() fails).
 */
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

/**
 * Header block: top bar, twin logos (real image if present, drawn
 * placeholder circle otherwise), facility title, and separator rule.
 * Repeated on every page since each page represents a separate sheet.
 */
function rxDrawHeader(FPDF $pdf, ?string $mhdLogoPath, ?string $planetLogoPath): void {
    rxDrawBar($pdf, 0, 6);

    $pdf->SetTextColor(RX_NAVY_R, RX_NAVY_G, RX_NAVY_B);
    $pdf->SetDrawColor(RX_NAVY_R, RX_NAVY_G, RX_NAVY_B);
    $pdf->SetLineWidth(0.4);

    // Left logo (Makati Health Department seal)
    $leftCx = 23; $leftCy = 20; $leftR = 11;
    if ($mhdLogoPath) {
        rxDrawLogoImage($pdf, $mhdLogoPath, $leftCx, $leftCy, $leftR * 2);
    } else {
        rxDrawCircle($pdf, $leftCx, $leftCy, $leftR);
        $pdf->SetFont('Arial', '', 6);
        $pdf->SetXY($leftCx - $leftR, $leftCy - 2);
        $pdf->Cell($leftR * 2, 4, txt('MHD SEAL'), 0, 0, 'C');
    }

    // Right logo (Planet Drugstore Corp)
    $rightCx = RX_PAGE_W - 23; $rightCy = 20; $rightR = 11;
    if ($planetLogoPath) {
        rxDrawLogoImage($pdf, $planetLogoPath, $rightCx, $rightCy, $rightR * 2);
    } else {
        rxDrawCircle($pdf, $rightCx, $rightCy, $rightR);
        $pdf->SetFont('Arial', '', 6);
        $pdf->SetXY($rightCx - $rightR, $rightCy - 2);
        $pdf->Cell($rightR * 2, 4, txt('PLANET LOGO'), 0, 0, 'C');
    }

    // Facility title, centered between the two logos
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetXY(38, 12);
    $pdf->Cell(RX_PAGE_W - 76, 7, txt('MAKATI HEALTH DEPARTMENT'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetX(38);
    $pdf->Cell(RX_PAGE_W - 76, 6, txt('PLANET DRUGSTORE CORP.'), 0, 1, 'C');

    // Separator rule under the header block
    $pdf->SetLineWidth(0.5);
    $pdf->Line(RX_MARGIN_L, 34, RX_MARGIN_R, 34);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);
}

/**
 * Big "Rx" mark plus the Name/Age/Sex row, the Address/Date row (with
 * text-wrapped Address — see ITEM 6 note below), and the PHC/YC/SC/PWD
 * checkbox row.
 *
 * FIX (Item 6 — text wrapping): Address now wraps onto a second line
 * when it's too long for its column, instead of silently overflowing
 * past the column edge on top of the Date field (as seen in the
 * screenshot: "...ZXCVBNM.. Bangkal Lying-in Clinic" running past its
 * underline). The Date field itself always stays in its fixed position
 * on the FIRST line — it doesn't get pushed around by a long address —
 * but the category checkboxes row (and everything below it, via the
 * returned Y) shifts down by one line's height if Address needed a
 * second line.
 *
 * Returns the Y coordinate where this block finished, so the caller can
 * position Diagnosis / the medicine slots directly after it rather than
 * using a hardcoded constant that assumes a single-line address.
 *
 * NOTE: PHC/YC/SC/PWD are drawn unchecked — no patient-category column
 * exists in the confirmed schema yet. If that data becomes available
 * (e.g. a `patients.category` field), wire it into $checkedCategories.
 */
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

    // Name / Age / Sex row
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

    // Address / Date row — Address value width unchanged (86mm); Date
    // stays fixed in its usual spot regardless of how long Address is.
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
        // First line only here — the wrapped remainder prints on its
        // own full-width row below, after the Date field is drawn.
        $pdf->MultiCell($addressValueWidth, 6, $addressRenderedText, 'B', 'L');
        // MultiCell moves the cursor to a new line by itself; put it
        // back on the same row so Date still prints beside line 1.
        $pdf->SetXY($addressValueX + $addressValueWidth, $addressRowY);
    }

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(14, 6, txt('Date:'), 0, 0, 'R');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($fieldRight - $pdf->GetX(), 6, txt($dateDisplay), 'B', 1, 'C');

    $nextRowY = $addressRowY + 6;

    if ($addressLines > 1) {
        // Continuation of the address, spanning the wider full-width
        // row now that Date no longer needs to share this line.
        $pdf->SetXY($labelX, $nextRowY);
        $pdf->SetFont('Arial', 'B', 10);
        // Re-measure against the FULL width available on this row
        // (not just the original 86mm column) since nothing else
        // shares it now.
        $fullWidth = $fieldRight - $labelX;
        $words = preg_split('/\s+/', $addressRenderedText, -1, PREG_SPLIT_NO_EMPTY);
        // Reconstruct just the part that didn't fit on line 1 by
        // re-running the same greedy wrap used for measurement, so the
        // two stay consistent with each other.
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

    // Category checkboxes — unchecked, see note above.
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(RX_MARGIN_L, $nextRowY + 4);
    $pdf->Cell(0, 6, txt('[  ] PHC     [  ] YC     [  ] SC     [  ] PWD'), 0, 1, 'C');

    return $nextRowY + 10; // bottom of the checkbox row
}

/**
 * One numbered medicine slot, matching the pad's per-item layout:
 * Generic Name / Dosage line, form checkboxes, Signa/Per-Day-For line,
 * and the quantity Note line, closed with a double rule.
 *
 * $medicine is null for a blank pre-printed slot (unused rows at the
 * bottom of the last page, matching the physical pad's blank rows).
 *
 * FIX (Item 6 — text wrapping): the Generic Name can now wrap onto a
 * second line instead of silently overflowing past its column (this was
 * the "PARACETAMOL 500MG" row overflowing into the Dosage column in the
 * screenshot, for longer names). Per-medicine Notes/remarks can also
 * wrap onto a second line the same way. Every element below the
 * (possibly 2-line) name, and below the (possibly 2-line) remarks, is
 * positioned using the ACTUAL number of lines used — not a fixed
 * assumption — so nothing overlaps regardless of how long the text is.
 *
 * FIX (Item 7 — QTY semantics): the doctor-entered `quantity` value
 * populates "Quantity to consume #" (previously always blank), and
 * `dispensed_quantity` — CONFIRMED against prescription_action.php,
 * which writes it the moment pharmacy completes the dispense for this
 * medicine — populates "Total quantity to be dispensed #". This matches
 * the distinction requested: QTY (Doctor) = what the doctor ordered/how
 * much the patient should consume; QTY DISPENSED (Planet) = what the
 * pharmacy actually dispensed. "Total quantity to be dispensed #" stays
 * blank until dispensed_quantity is set (i.e. the medicine is still
 * Pending at the pharmacy) — same as an unfilled physical pad line.
 *
 * Returns the total vertical height (mm) this slot actually consumed,
 * so the caller can advance to the next slot's Y position by exactly
 * that amount instead of a fixed constant.
 */
function rxDrawMedicineSlot(FPDF $pdf, int $index, ?array $medicine, float $y): float {
    $left = RX_MARGIN_L;
    $right = RX_MARGIN_R;

    // Line 1: Generic Name .......................... Dosage ___ Mg
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
        $pdf->SetFont('Arial', 'B', 10); // same font used to measure AND render, so line counts match
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

    // Line 2: form checkboxes
    $checkboxY = $underlineY + 5;
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($left + 7, $checkboxY);
    $pdf->Cell(0, 5, txt('[  ] Tablet   [  ] Capsule   [  ] Syrup   [  ] Drops   [  ] Others ____________'), 0, 1);

    // Line 3: Signa ................ Per Day For ___ Day(s)
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

    // Line 4: Note — Total quantity to be dispensed # (pharmacy's
    // actual dispensed_quantity, confirmed against prescription_action.php)
    // and Quantity to consume # (doctor's ordered quantity).
    $noteY = $signaY + 5;
    $pdf->SetXY($left + 7, $noteY);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(58, 5, txt('Note:  Total quantity to be dispensed #'), 0, 0);
    $pdf->SetFont('Arial', 'B', 9);
    $qtyEnd = $pdf->GetX() + 16;
    $dispensedQtyText = ($medicine && $medicine['dispensed_quantity'] !== null)
        ? txt($medicine['dispensed_quantity'])
        : ''; // still Pending at the pharmacy — blank, same as an unfilled pad
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

    // Remarks (per-medicine notes) — wraps onto up to 2 lines the same
    // way the Generic Name does.
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

    // Double rule closing the slot — positioned after whatever the
    // actual (possibly wrapped) remarks needed, not a fixed offset.
    $ruleY = $remarksLines > 0
        ? ($remarksY + (4 * $remarksLines) + 1)
        : ($noteY + 9);

    $pdf->SetLineWidth(0.4);
    $pdf->Line($left, $ruleY, $right, $ruleY);
    $pdf->Line($left, $ruleY + 0.8, $right, $ruleY + 0.8);
    $pdf->SetLineWidth(0.2);

    // Small gap before the next slot begins, matching the original
    // fixed-height layout's spacing (rule sat at y+29, next slot began
    // at y+30 — a 1mm gap).
    return ($ruleY + 1) - $y;
}

/**
 * Footer: Refill Date on the left; M.D. / License No. / PTR No. block on
 * the right, with the signature image (if signed) placed above the M.D.
 * line. Followed by the bottom navy bar.
 *
 * License No. / PTR No. now pull from users.license_number and
 * users.ptr_number (see the DOCTOR INFO block above) — each still
 * renders blank if that particular doctor hasn't filled theirs in yet,
 * same as an unfilled physical pad.
 */
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

$pdf = new FPDF();
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0, 0);

$rxNo = $prescription['prescription_number'] ?? $prescription['id'];

// Paginate medicines 5-per-page, matching the pad's 5 fixed slots. If
// there are no medicines at all, still render one page of blank slots
// so the output looks like an unfilled pad rather than an empty error.
$chunks = array_chunk($medicines, RX_SLOTS_PER_PAGE);
if (empty($chunks)) {
    $chunks = [[]];
}

// Item 9: draw the whole page set RX_COPIES times so printing "all
// pages" from any PDF viewer produces that many physical copies.
for ($copy = 1; $copy <= RX_COPIES; $copy++) {

    foreach ($chunks as $chunkIndex => $chunk) {
        $pdf->AddPage();

        rxDrawHeader($pdf, $mhdLogoPath, $planetLogoPath);

        // Item 6.3 — RX No. font size increased (was 9) for readability.
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY(RX_MARGIN_R - 70, 1);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(70, 5, txt('Rx No.: ' . $rxNo), 0, 0, 'R');
        $pdf->SetTextColor(0, 0, 0);

        $patientBlockBottomY = rxDrawPatientBlock($pdf, $patientName, $age, $prescription['gender'], $address, $dateDisplay, $isSigned);

        if (!empty($prescription['diagnosis'])) {
            $diagnosisText = txt($prescription['diagnosis']);
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

        if (!empty($prescription['remarks'])) {
            $remarksText = txt($prescription['remarks']);
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

        rxDrawFooter($pdf, $isSigned, $signatureImagePath, $doctorName, $doctorLicenseNo, $doctorPtrNo, $lastRefillDateDisplay);
    }
}

/*
|--------------------------------------------------------------------------
| OUTPUT
|--------------------------------------------------------------------------
| 'I' = inline in browser (view). Pass ?download=1 to force a download
| instead ('D'). True print-blocking for unsigned prescriptions is
| enforced client-side (prescription_details.php's Print button only
| exists/enables when status === 'Signed') — a PDF file itself can't
| block a browser's native print function.
|
| ob_end_clean() discards anything caught by the ob_start() safety net
| above (should be nothing, if the fix above is doing its job) right
| before FPDF sends its own headers.
*/
if (ob_get_level() > 0) {
    ob_end_clean();
}

$outputMode = (isset($_GET['download']) && $_GET['download'] == '1') ? 'D' : 'I';
$filename = 'Prescription_' . $rxNo . '.pdf';

$pdf->Output($outputMode, $filename);

// Clean up any temp signature file created for this request.
if ($signatureImagePath && strpos($signatureImagePath, sys_get_temp_dir()) === 0) {
    @unlink($signatureImagePath);
}