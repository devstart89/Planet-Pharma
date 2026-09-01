<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';
require '../kiosk_logic.php';

date_default_timezone_set('Asia/Manila');

define('LOGO_PLANET_RED', 'data:image/png;base64,PLACEHOLDER');
define('LOGO_MAKATI_SEAL', 'data:image/png;base64,PLACEHOLDER');
define('LOGO_MHD_SEAL', 'data:image/png;base64,PLACEHOLDER');

$branchSlug = $_GET['branch'] ?? $_POST['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    renderBranchPicker($conn, 'kiosk.php');
}
$pharmacyId = (int) $pharmacy['id'];

/*
 * printer_settings.php now owns the full printer config shape (see that
 * file for the authoritative field list per connection type). This page
 * only needs isConfigured (to decide whether to try direct printing at
 * all) plus the raw config blob, which it passes straight through to the
 * client untouched -- see printerConfig in the script below.
 */
$printer = kioskPrinterConfig($conn, $pharmacyId);
$printerSettingsUrl = 'printer_settings.php';

/*
 * ================= PRINT PAGE SIZE — GUARANTEED VALID =================
 * If printer_settings.php hasn't saved a paperWidth yet, $printer['paperWidth']
 * is empty/null, and `@page { size: <?= ... ?> auto; }` would render as
 * `size:  auto;` — invalid CSS. Browsers silently discard the whole
 * declaration and fall back to a full Letter/A4 page, which is exactly
 * what produced the huge blank space and the mid-ticket page break seen
 * in testing (the ticket content spilled onto a "page 2"). Always
 * fall back to a sane default so this @page rule is never malformed.
 */
$printerPaperWidth = trim((string) ($printer['paperWidth'] ?? ''));
if ($printerPaperWidth === '') $printerPaperWidth = '80mm';

/*
 * ================= @page SIZE MUST BE TWO LENGTHS, NEVER "auto" =================
 * `size: 80mm auto;` looks like the right incantation for continuous
 * thermal roll paper, but it is not valid CSS: the Paged Media spec's
 * `size` property only allows `auto` as a lone keyword (`size: auto;`)
 * -- never mixed with a length. Browsers silently discard the entire
 * malformed declaration, which is why this always fell back to a full
 * Letter/A4 page (confirmed directly: real PDF output came out as
 * 612x792pt Letter, paginated across 2 pages, regardless of what
 * paperWidth held). A fixed height, generous enough that no realistic
 * ticket ever needs a second page, keeps this valid CSS and guarantees
 * single-page output; any unused paper feed at the end is a normal,
 * expected trade-off for thermal receipt printing.
 */
$printerPageSize = $printerPaperWidth . ' 300mm';

/*
 * ================= QR CODE: PUBLIC QUEUING SITE =================
 * Every printed slip and every on-screen ticket carries a QR code that
 * links straight to the Public Queuing Site (queue_display.php) for
 * THIS branch, so a patient can scan it with their own phone instead
 * of walking to a TV screen.
 *
 * This has to be an ABSOLUTE, internet-resolvable URL (scheme + host)
 * because a phone camera is a different device than the kiosk browser
 * -- a relative path (like queue.php's $publicMonitorUrl) would be
 * meaningless once scanned. Built the same way queue.php derives its
 * site root, plus scheme/host from the current request.
 */
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);        // e.g. /modules/pharmacy
$siteRoot  = rtrim(dirname($scriptDir, 2), '/');       // strip "pharmacy" and "modules" -> site root
if ($siteRoot === '') $siteRoot = '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? '';

/*
 * ================= WEBSITE LINE: SKIP OBVIOUS DEV HOSTS =================
 * The printed ticket's "website" line reuses the current request's own
 * host (see PHARMACY_WEBSITE below) since there's no dedicated website
 * field anywhere in this app. That's fine in production, but printing
 * "localhost" or a bare IP on a ticket during local testing looks
 * broken, so it's simply omitted whenever the host looks like a dev
 * environment rather than a real domain.
 */
$hostLooksLikeDev = (bool) preg_match('/^(localhost|127\.0\.0\.1|::1|[\w-]+\.local)(:\d+)?$/i', $host)
    || (bool) preg_match('/^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/', $host);
$printableWebsite = $hostLooksLikeDev ? '' : $host;
$publicMonitorUrl = $scheme . '://' . $host . $siteRoot . '/modules/public/queue_display.php?branch=' . urlencode($branchSlug);

/*
 * ================= FIRST-LOAD STATE ONLY =================
 * Everything below determines what to show on THIS load only — a
 * fresh visit, a bookmarked/QR ?mode=... deep link, or a manual
 * refresh. Every screen transition AFTER this load runs through
 * kiosk_api.php + JS instead (see the script at the bottom), so a real
 * page navigation — which silently drops browser Fullscreen with no
 * way for JS to prevent it — never happens again during a session.
 * PHP's job here is reduced to "figure out which screen to render
 * first, and with what data" — the actual markup for every screen
 * lives once, in the JS render functions below, not duplicated here.
 */
$mode = $_GET['mode'] ?? null;
$initialError = null;
$initialSlipPayload = null;
$initialCategoryStep = null; // ['rxNo' => ..., 'patientName' => ...]

if ($mode === 'epres' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['rx_no'])) {
    $result = kioskValidateRx($conn, $pharmacyId, $_POST['rx_no']);
    if (!$result['ok']) {
        $initialError = $result['error'];
    } else {
        $prescription = $result['prescription'];
        $existing = kioskExistingQueueForPrescription($conn, $pharmacyId, (int) $prescription['id']);
        $categoryChoice = $_POST['category'] ?? null;

        if ($existing) {
            $initialSlipPayload = kioskTicketPayload($pharmacy, $existing, $prescription['prescription_number']);
        } elseif ($categoryChoice === 'Regular' || $categoryChoice === 'Priority') {
            $row = kioskCreateQueueEntry($conn, $pharmacyId, $categoryChoice, 'E-Pres', (int) $prescription['id']);
            $initialSlipPayload = kioskTicketPayload($pharmacy, $row, $prescription['prescription_number']);
        } else {
            $initialCategoryStep = [
                'rxNo' => $prescription['prescription_number'],
                'patientName' => trim(($prescription['first_name'] ?? '') . ' ' . ($prescription['last_name'] ?? '')),
            ];
        }
    }
}

if ($mode === 'walkin' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['category'])) {
    $category = $_POST['category'] === 'Priority' ? 'Priority' : 'Regular';
    $walkInName = trim($_POST['walk_in_name'] ?? '');
    $row = kioskCreateQueueEntry($conn, $pharmacyId, $category, 'Walk-in', null, $walkInName !== '' ? $walkInName : null);
    $initialSlipPayload = kioskTicketPayload($pharmacy, $row);
}

/*
 * kioskTicketPayload() (in kiosk_logic.php) doesn't know about the
 * public monitor URL, so it isn't necessarily on $initialSlipPayload
 * yet. Rather than requiring that file to change, stamp it on here as
 * a fallback -- if kiosk_logic.php is later updated to include its own
 * 'public_url' (e.g. per-queue-number deep link), that value wins;
 * this just guarantees the field always exists.
 */
if ($initialSlipPayload && empty($initialSlipPayload['public_url'])) {
    $initialSlipPayload['public_url'] = $publicMonitorUrl;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pharmacy['pharmacy_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../kiosk_common.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="manifest" href="kiosk_manifest.php?branch=<?= urlencode($branchSlug) ?>">
<meta name="theme-color" content="#ffffff">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($pharmacy['pharmacy_name']) ?>">
<link rel="apple-touch-icon" href="../logo/PLANETLIGHT.png">
<!--
    Lightweight, dependency-free QR generator (no network call needed
    at print time beyond this one script load) used to render the QR
    both on-screen and inside the printable ticket via SVG.
-->
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
<style>
    :root {
        --brand-navy: #1d2939;
        --brand-gray: #667085;
        --brand-red-1: #ff6b63;
        --brand-red-2: #ef4444;
        --brand-blue-bg: #e7f0ff;
        --brand-blue: #175cd3;
        --brand-pink-bg: #fdeeed;
        --brand-pink: #ef4444;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0;
        min-height: 100vh;
        font-family: 'Segoe UI', Arial, sans-serif;
        position: relative;
        overflow-x: hidden;

        /*
         * ================= KIOSK MODE =================
         * Runs on a dedicated kiosk device, not a normal browser tab —
         * no text-selection highlight, no drag-to-select, no long-press
         * callout menu. Inputs re-enable selection below (the Rx number
         * field still needs normal text editing).
         */
        -webkit-user-select: none;
        -moz-user-select: none;
        user-select: none;
        -webkit-touch-callout: none;
        touch-action: manipulation;

        /*
         * ================= BRAND BACKGROUND PHOTO =================
         * The actual brand photo (pill + folded-paper backdrop) as the
         * page background, not a synthetic approximation. Fixed so it
         * doesn't scroll away, cover+center so it fills any screen size
         * while keeping the pill roughly in the same lower-left spot it
         * was shot in.
         */
        background-color: #ffffff;
        background-image: url('../public/image.png');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        background-attachment: fixed;
    }
    input, textarea {
        -webkit-user-select: text;
        -moz-user-select: text;
        user-select: text;
    }
    img { -webkit-user-drag: none; user-drag: none; }

    /*
     * ================= PERSISTENT BRAND HEADER/FOOTER =================
     * Shown on every screen (idle, options, ticket) rather than only on
     * a colored side panel, per the new flat/unified layout.
     */
    .kiosk-header {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: clamp(1rem, 3vw, 1.75rem) clamp(1rem, 4vw, 2.5rem) 0;
    }
    .brand-mark {
        width: clamp(30px, 5vw, 38px); height: clamp(30px, 5vw, 38px);
        border-radius: .6rem;
        display: flex; align-items: center; justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,.08);
    }
    .brand-mark img { width: 100%; height: 100%; object-fit: contain; }
    .brand-name {
        font-weight: 800;
        font-size: clamp(.78rem, 2vw, .9rem);
        letter-spacing: .03em;
        text-transform: uppercase;
        color: var(--brand-navy);
        line-height: 1.1;
    }
    .brand-sub {
        font-size: clamp(.62rem, 1.6vw, .7rem);
        color: var(--brand-gray);
        font-style: italic;
    }
    .kiosk-footer {
        position: relative;
        z-index: 2;
        text-align: right;
        padding: 0 clamp(1rem, 4vw, 2.5rem) clamp(1rem, 3vw, 1.5rem);
        font-size: clamp(.6rem, 1.6vw, .68rem);
        font-weight: 700;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: #98a2b3;
        margin-top: auto;
    }

    .kiosk-shell {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    .kiosk-stage {
        position: relative;
        z-index: 2;
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: clamp(1.5rem, 4vw, 3rem);
    }

    /* ===== Idle / tap screen ===== */
    .idle-card {
        background: #BFBFBF33;
        border-radius: 1.5rem;
        box-shadow: 0 24px 60px rgba(29, 41, 57, 0.79);
        border: 1px solid #eef1f4;
        padding: clamp(2rem, 6vw, 3rem) clamp(1.75rem, 6vw, 3rem);
        text-align: center;
        max-width: 680px;
        width: 100%;
        cursor: pointer;
    }
    .idle-icon {
        width: 64px; height: 64px;
        margin: 0 auto 1.25rem;
        border-radius: 50%;
        background: var(--brand-blue-bg);
        color: var(--brand-blue);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.6rem;
    }
    .idle-title {
        font-weight: 800;
        font-size: clamp(1.05rem, 3vw, 1.3rem);
        color: var(--brand-navy);
        margin-bottom: .5rem;
    }
    .idle-sub {
        color: var(--brand-gray);
        font-size: clamp(.82rem, 2.5vw, .92rem);
        margin-bottom: 1.5rem;
    }
    .idle-pulse-wrap { display: flex; justify-content: center; }
    .idle-pulse {
        width: 12px; height: 12px;
        border-radius: 50%;
        background: var(--brand-red-2);
        animation: pulse 1.6s ease-in-out infinite;
    }
    @keyframes pulse {
        0%   { transform: scale(1);   opacity: 1; }
        70%  { transform: scale(2.4); opacity: 0; }
        100% { transform: scale(2.4); opacity: 0; }
    }

    /* ===== Main option screen: E-Press panel + walk-in panel ===== */
    .option-stage {
        display: grid;
        grid-template-columns: 1.1fr 1fr;
        gap: clamp(1rem, 3vw, 1.75rem);
        width: 100%;
        max-width: 900px;
    }
    @media (max-width: 760px) {
        .option-stage { grid-template-columns: 1fr; }
    }

    /*
     * ================= GLASS CARDS =================
     * Translucent "frosted glass" panels — background color at partial
     * opacity plus a blur of whatever sits behind (the page's diagonal
     * line photo), so the backdrop is visible through the card the way
     * the reference design shows.
     */
     .walkin-card {
        /* background: #BFBFBF33; */
        backdrop-filter: blur(1px);
        -webkit-backdrop-filter: blur(1px);
        border-radius: 1.5rem;
        /* border: 1px solid rgba(255, 255, 255, .6); */
        /* box-shadow: 0 20px 48px rgba(29,41,57,.08); */
        padding: clamp(1.5rem, 4vw, 2rem);
        position: relative;}

    .epres-card {
        background: #BFBFBF33;
        backdrop-filter: blur(1px);
        -webkit-backdrop-filter: blur(1px);
        border-radius: 1.5rem;
        border: 1px solid rgba(255, 255, 255, .6);
        box-shadow: 0 20px 48px rgba(29,41,57,.08);
        padding: clamp(1.5rem, 4vw, 2rem);
        position: relative;
        /*
         * NOT overflow:hidden here. The E-Press card's content height
         * varies a lot across its states (default -> Rx entry -> the
         * category step, which is taller: heading + Rx context + two
         * 112px category cards). Clipping the card would silently cut
         * off the Regular/Priority buttons in that state. The cloud
         * watermark below is positioned to stay within the card in
         * practice; a hair of bleed past the rounded corner is a far
         * smaller cost than clipped, unusable buttons.
         */
    }
    .epres-card {
        cursor: pointer;
        transition: transform .12s ease, box-shadow .12s ease;
    }
    .epres-card:hover { transform: translateY(-2px); box-shadow: 0 24px 56px rgba(29,41,57,.12); }
    .epres-card.is-active { cursor: default; }
    .epres-card.is-active:hover { transform: none; box-shadow: 0 20px 48px rgba(29,41,57,.08); }
    /* Mirrors .cat-btn.is-locked: applied while a walk-in ticket is
       active, so the same walk-up patient can't also start an E-Press
       lookup for a second queue number at the same time. pointer-events
       fully blocks clicks regardless of whatever onclick handler the
       card's current sub-screen has set. */
    .epres-card.is-locked { opacity: .4; pointer-events: none; cursor: not-allowed; }

    /*
     * ================= WATERMARK VECTORS =================
     * Exact brand assets (not an approximation): a walking-figure
     * pictogram (body + head, two separate paths) tinted per category,
     * and a cloud + checkmark pair for the E-Press card. All paths use
     * fill="currentColor" (stripped of their original hardcoded fill)
     * so a single markup works for both the blue Regular and pink
     * Priority tint via ordinary CSS `color`.
     */
    .wm-figure {
        position: absolute;
        width: 196px;
        pointer-events: none;
        z-index: 0;
        opacity: .18;
    }
    .wm-figure .wm-body { display: block; width: 100%; height: auto; overflow: hidden; }
    .wm-figure .wm-head {
        position: absolute;
        width: 34%;
        left: 14%;
        top: -18%;
    }
    .cat-btn-regular .wm-figure  { color: var(--brand-blue); }
    .cat-btn-priority .wm-figure { color: var(--brand-red-2); }

    /* Idle state: figure bleeds off the card's left edge. */
    .wm-figure.wm-pos-idle { left: -40px; top: 46%; transform: translateY(-50%) scale(.85); }
    /* Ticket state: figure sits toward the right, behind PRINT. */
    .wm-figure.wm-pos-ticket { right: -10px; top: 50%; transform: translateY(-50%) scale(.62); }

    .wm-cloud {
        position: absolute;
        width: 300px;
        right: 10px;
        bottom: 10px;
        pointer-events: none;
        z-index: 0;
        opacity: .14;
        color: #98a2b3;
    }
    .wm-cloud .wm-cloud-shape { display: block; width: 100%; height: auto; }
    .wm-cloud .wm-check {
        position: absolute;
        width: 30%;
        left: 32%;
        top: 26%;
    }

    .pill-hero {
        position: relative;
        z-index: 1;
        width: 130px; height: 52px;
        margin: 0 auto 1.1rem;
        border-radius: 26px;
        background: linear-gradient(135deg, var(--brand-red-1), var(--brand-red-2));
        box-shadow: 0 14px 26px rgba(239,68,68,.28), inset 0 2px 5px rgba(255,255,255,.45);
        display: flex; align-items: center; justify-content: center;
    }
    .pill-hero::before {
        content: "";
        position: absolute;
        left: 10px; top: 7px;
        width: 44%; height: 8px;
        background: rgba(255,255,255,.5);
        border-radius: 4px;
        transform: rotate(-8deg);
    }
    .pill-hero span {
        color: #fff;
        font-weight: 800;
        font-size: .62rem;
        letter-spacing: .04em;
        text-align: center;
        line-height: 1.15;
        position: relative;
    }

    .epres-heading {
        position: relative;
        z-index: 1;
        font-weight: 770;
        font-size: clamp(3.1rem, 3vw, 1.4rem);
        color: var(--brand-navy);
        text-align: left;
        margin-bottom: .4rem;
    }
    .epres-heading .accent { color: var(--brand-navy); }
    .epres-sub {
        position: relative;
        z-index: 1;
        color: var(--brand-gray);
        font-weight:600;
        font-size: clamp(.8rem, 2.2vw, .88rem);
        text-align: left;
        margin: 0;
    }

    .walkin-heading {
        font-weight: 700;
        font-size: clamp(1rem, 2.6vw, 1.15rem);
        color: var(--brand-navy);
        margin-bottom: 1.25rem;
        text-align: center;
    }
    .walkin-heading .accent { color: var(--brand-blue); }

    .cat-buttons { display: grid; gap: .85rem; }

    /*
     * Each Regular/Priority slot is a fixed-size glass card. The
     * walking-figure watermark (see .wm-figure above) persists behind
     * every state (idle/ticket/done); only the foreground content and
     * the watermark's position/scale change between states.
     */
    .cat-btn {
        border: none;
        border-radius: 1.1rem;
        padding: clamp(1rem, 3vw, 1.25rem) clamp(1.1rem, 3vw, 1.4rem);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .75rem;
        width: 100%;
        min-height: 112px;
        text-align: center;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,.5);
        transition: transform .1s ease, box-shadow .1s ease;
    }
    /* Touch feedback: brief scale-down on press, per kiosk interaction
       spec — deliberately short so it still feels responsive. */
    .cat-btn:active:not([disabled]) { transform: scale(.98); }
    .cat-btn:focus-visible {
        outline: 3px solid var(--brand-navy);
        outline-offset: 2px;
    }
    .cat-btn-regular  { background: rgba(96, 165, 250, .16); }
    .cat-btn-priority { background: rgba(239, 68, 68, .10); }
    .cat-btn[disabled] { cursor: default; opacity: .85; }
    /* The sibling category once the other has an active ticket this
       session — see lockSiblingCategoryButton(). Dimmer than the
       ordinary [disabled] state so it visibly reads as "not available
       right now" rather than just "processing". */
    .cat-btn.is-locked { opacity: .4; cursor: not-allowed; }

    /* ---- Idle / select state: centered two-line label ---- */
    .cat-btn-label {
        position: relative;
        z-index: 1;
        font-weight: 800;
        font-size: clamp(1rem, 2.8vw, 1.2rem);
        color: var(--brand-navy);
        line-height: 1.35;
    }
    .cat-btn-label small {
        display: block;
        font-weight: 700;
        font-size: .72rem;
        color: var(--brand-gray);
        letter-spacing: .06em;
    }

    /* ---- Ticket state: back-arrow | big number | ... | PRINT ---- */
    .cat-btn.is-ticket, .cat-btn.is-done { cursor: default; }
    .cat-ticket-row {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        width: 100%;
        gap: .75rem;
    }
    .cat-back-btn {
        width: 40px; height: 40px;
        border-radius: 50%;
        border: 1.5px solid rgba(29,41,57,.18);
        background: rgba(255,255,255,.6);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem;
        color: var(--brand-navy);
        flex-shrink: 0;
        cursor: pointer;
    }
    .cat-back-btn:hover { background: rgba(255,255,255,.9); }
    .cat-ticket-number {
        font-weight: 800;
        font-size: clamp(1.9rem, 5.5vw, 2.5rem);
        color: var(--brand-navy);
        letter-spacing: .02em;
        line-height: 1;
        flex: 1;
        text-align: left;
    }
    .cat-print-label {
        position: relative;
        z-index: 1;
        border: none;
        background: none;
        font-size: .78rem;
        font-weight: 800;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: var(--brand-navy);
        cursor: pointer;
        padding: .4rem .2rem;
        flex-shrink: 0;
    }
    .cat-print-label[disabled] { opacity: .5; cursor: default; }

    /* ---- Print-success state: outlined check + message ---- */
    .cat-done-col {
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .5rem;
    }
    .cat-done-icon {
        font-size: 2.1rem;
        color: var(--brand-navy);
    }
    .cat-done-text {
        font-weight: 700;
        font-size: clamp(.78rem, 2.2vw, .88rem);
        color: var(--brand-navy);
        line-height: 1.35;
        max-width: 220px;
    }

    /* ---- Error state: message + retry button ---- */
    .cat-error-col {
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .5rem;
    }
    .cat-error-text {
        font-weight: 700;
        font-size: clamp(.78rem, 2.2vw, .88rem);
        color: var(--brand-navy);
        line-height: 1.35;
    }
    .cat-retry-btn {
        border: 1.5px solid rgba(29,41,57,.25);
        background: rgba(255,255,255,.7);
        border-radius: .5rem;
        padding: .4rem 1rem;
        font-weight: 800;
        font-size: .75rem;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: var(--brand-navy);
        cursor: pointer;
    }
    .cat-retry-btn:active { transform: scale(.98); }

    .epres-alert {
        background: #fef3f2;
        border: 1px solid #fecdca;
        color: #b42318;
        border-radius: .6rem;
        padding: .6rem .85rem;
        font-size: .82rem;
        margin-bottom: 1rem;
    }

    /* ===== E-Press sub-screens (rx entry / category step / ticket) ===== */
    .epres-back {
        border: none;
        background: none;
        color: var(--brand-gray);
        font-size: .82rem;
        padding: 0;
        margin-bottom: .9rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: .3rem;
    }
    .epres-back:hover { color: var(--brand-navy); }
    .epres-form input.form-control {
        border-radius: .7rem;
        text-align: center;
        font-weight: 700;
        letter-spacing: .03em;
    }
    .epres-form .btn-epres-submit {
        background: var(--brand-navy);
        color: #fff;
        border: none;
        border-radius: .7rem;
        font-weight: 700;
        padding: .75rem;
        width: 100%;
        margin-top: .75rem;
    }
    .epres-rx-context {
        text-align: center;
        color: var(--brand-gray);
        font-size: .85rem;
        margin-bottom: 1.1rem;
    }
    .epres-rx-context strong { color: var(--brand-navy); }

    .epres-ticket {
        text-align: center;
    }
    .epres-ticket .category-tag {
        display: inline-block;
        font-weight: 700;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: .3rem .7rem;
        border-radius: .4rem;
        margin-bottom: .75rem;
    }
    .epres-ticket .category-tag.Regular  { background: var(--brand-blue-bg); color: var(--brand-blue); }
    .epres-ticket .category-tag.Priority { background: var(--brand-pink-bg); color: var(--brand-red-2); }
    .epres-ticket .ticket-number {
        font-weight: 800;
        font-size: clamp(2rem, 6vw, 2.75rem);
        color: var(--brand-navy);
        line-height: 1;
        margin-bottom: .35rem;
    }
    .epres-ticket .ticket-meta {
        color: var(--brand-gray);
        font-size: .82rem;
        margin-bottom: .2rem;
    }
    .epres-ticket .btn-print-epres {
        background: var(--brand-navy);
        color: #fff;
        border: none;
        border-radius: .7rem;
        font-weight: 700;
        padding: .7rem 1.5rem;
        margin-top: 1rem;
    }
    .epres-ticket .done-block { margin-top: 1rem; }
    .epres-ticket .done-block .cat-done-icon { font-size: 2rem; display: block; margin: 0 auto .5rem; }

    /*
     * ================= QR CODE =================
     * Rendered inline wherever a ticket is confirmed (walk-in card or
     * E-Press card) and inside the printed slip.
     */
    .ticket-qr { display: flex; flex-direction: column; align-items: center; gap: .3rem; margin: .6rem 0; }
    .ticket-qr svg { width: 108px; height: 108px; display: block; }
    .ticket-qr .ticket-qr-caption {
        font-size: .64rem;
        color: var(--brand-gray);
        text-transform: uppercase;
        letter-spacing: .04em;
        font-weight: 700;
    }

    .printer-setup-overlay {
        position: fixed;
        inset: 0;
        z-index: 50;
        background: rgba(29, 41, 57, 0.55);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.25rem;
    }
    .printer-setup-card {
        background: #fff;
        border-radius: 1rem;
        max-width: 380px;
        width: 100%;
        padding: 1.75rem 1.5rem;
        text-align: center;
        box-shadow: 0 20px 40px rgba(0,0,0,.25);
        position: relative;
    }
    .printer-setup-close {
        position: absolute;
        top: .6rem; right: .75rem;
        border: none;
        background: transparent;
        color: var(--brand-gray);
        font-size: 1.4rem;
        line-height: 1;
        padding: .25rem .5rem;
        cursor: pointer;
    }
    .printer-setup-close:hover { color: var(--brand-navy); }
    .printer-setup-icon {
        width: 56px; height: 56px;
        margin: 0 auto .9rem;
        border-radius: 50%;
        background: var(--brand-blue-bg);
        color: var(--brand-blue);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
    }
    .printer-setup-card h5 { font-weight: 800; color: var(--brand-navy); margin-bottom: .5rem; }
    .printer-setup-card p { color: var(--brand-gray); font-size: .88rem; margin-bottom: 1.5rem; }
    .printer-setup-actions { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
    .printer-setup-actions .btn { border-radius: .65rem; font-weight: 700; font-size: .85rem; }

    #printFallbackNotice { display: none; }

    @media print {
        @page { size: <?= $printerPageSize ?>; margin: 0; }
        html, body { width: 100%; height: auto; }
        body { margin: 0; background: #fff; }
        body * { visibility: hidden; }

        .kiosk-header, .kiosk-footer, .printer-setup-overlay { display: none !important; }
        body { background-image: none !important; }

        /*
         * The whole on-screen kiosk UI (whatever screen happened to be
         * showing when Print fired — idle card, option cards, ticket
         * state, all of it) needs to be OUT of the print layout, not
         * just invisible. `visibility: hidden` (from the `body *` rule
         * above) hides content but still reserves its full box height;
         * with min-height merely zeroed but the element still
         * `display: block`, it was sizing to its CONTENT's natural
         * height instead — which is exactly what produced the large
         * blank gap above the ticket (the on-screen layout's real
         * height, invisible but still occupying space, sitting above
         * #printTicket in the DOM). `display: none` removes it from
         * layout entirely, so it can never reserve any space.
         */
        .kiosk-shell { display: none !important; }

        #printFallbackNotice, #printFallbackNotice * { visibility: hidden; }
        body:not(:has(#printTicket.has-content)) #printFallbackNotice,
        body:not(:has(#printTicket.has-content)) #printFallbackNotice * {
            visibility: visible !important;
        }
        body:not(:has(#printTicket.has-content)) #printFallbackNotice {
            display: block !important;
            position: static !important;
            width: 100%;
            padding: 20px 14px;
            text-align: center;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #000 !important;
        }

        /*
         * ================= PRINTED TICKET =================
         * Redesigned for a cleaner thermal-receipt hierarchy: a
         * blanket color:#000 override on every descendant (not just
         * specific selectors) guarantees nothing ever prints with a
         * stray tint — some print pipelines (PDF virtual printers,
         * embedded WebViews) don't fully respect scoped !important
         * color rules the way a desktop browser's print preview does,
         * which is what let a faint blue show through before.
         */
        #printTicket, #printTicket * {
            visibility: visible;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: #000 !important;
            background: transparent !important;
            box-shadow: none !important;
        }
        #printTicket {
            display: block !important;
            position: static;
            width: 100%;
            max-width: 320px;
            margin: 0 auto;
            padding: 10px 6px 14px;
            text-align: center;
            font-family: Arial, Helvetica, sans-serif;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* ---- Header: brand, tagline, branch/address, website ---- */
        #printTicket .ticket-brand {
            font-size: 13pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .03em;
            margin: 0;
        }
        #printTicket .ticket-tagline {
            font-size: 8pt;
            font-style: italic;
            margin: 1pt 0 6pt;
        }
        #printTicket .ticket-branch-line {
            font-size: 9.5pt;
            font-weight: 700;
            margin: 3pt 0 0;
        }
        #printTicket .ticket-address-line,
        #printTicket .ticket-website-line {
            font-size: 8pt;
            margin: 1pt 0;
        }

        #printTicket .ticket-divider {
            border: none;
            border-top: 1.5pt dashed #000;
            margin: 8pt 0;
        }

        /* ---- Transaction information: Date / Time / Rx / Receipt No. ---- */
        #printTicket .ticket-txn-row {
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            margin: 2pt 4pt;
        }
        #printTicket .ticket-txn-row span:first-child { font-weight: 700; }

        /* ---- Queue section ---- */
        #printTicket .queue-patient-label {
            font-size: 8pt;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            margin: 0 0 2pt;
        }
        #printTicket .queue-number {
            font-size: 30pt;
            font-weight: 800;
            line-height: 1;
            letter-spacing: .02em;
            margin: 0;
        }

        #printTicket .ticket-qr { margin: 2pt 0; }
        #printTicket .ticket-qr svg { width: 90px; height: 90px; }
        #printTicket .ticket-qr .ticket-qr-caption {
            font-size: 7pt;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-top: 2pt;
        }

        /* ---- Footer: thank-you, wait message, branch info ---- */
        #printTicket .ticket-footer-thanks {
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin: 0 0 8pt;
        }
        #printTicket .confirm-wait {
            font-size: 10pt;
            font-weight: 800;
            line-height: 1.3;
            margin: 0 0 8pt;
        }
        #printTicket .ticket-footer-branch {
            font-size: 7.5pt;
            opacity: .8;
            margin: 0;
        }

        #printTicket i, #printTicket .bi { display: none !important; }
    }
</style>
</head>
<body>
<script src="../kiosk_common.js"></script>
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('kiosk_sw.js').catch(function () {});
    }
</script>

<div class="kiosk-shell">
    <div class="kiosk-header">
        <div class=""><img src="../logo/logo.svg" alt="<?= htmlspecialchars($pharmacy['pharmacy_name']) ?> logo"></div>
        <!-- <div>
            <div class="brand-name">Planet Drugstore</div>
            <div class="brand-sub">Caring Beyond Dispensing</div>
        </div> -->
    </div>

    <div class="kiosk-stage" id="kioskStage"></div>

    <div class="kiosk-footer"><?= htmlspecialchars($pharmacy['pharmacy_name']) ?> &middot; <?= htmlspecialchars($pharmacy['address']) ?></div>
</div>

<!--
    Kept as a sibling of .kiosk-shell (not nested inside it) on purpose:
    the print stylesheet sets .kiosk-shell to display:none entirely (see
    the @media print block), and a display:none parent hides every
    descendant unconditionally regardless of that descendant's own
    print rules. This needs to be able to show up independently of
    whatever on-screen state .kiosk-shell was in when Print fired.
-->
<div id="printFallbackNotice">
    Nothing to print from this screen yet.<br>
    Please get a queue number first, then use the
    "Print" button on the confirmation screen.
</div>

<!-- Hidden printable ticket. Its content is (re)populated right before
     each print action (see populatePrintTicketDom below), since more
     than one ticket (Regular + Priority, or a walk-in + an E-Pres one)
     can exist on screen at the same time. -->
<div id="printTicket" style="display:none;"></div>

<div class="printer-setup-overlay" id="printerSetupModal">
    <div class="printer-setup-card">
        <button type="button" class="printer-setup-close" id="printerSetupCloseBtn" aria-label="Close">&times;</button>
        <div class="printer-setup-icon"><i class="bi bi-camera"></i></div>
        <h5>No Printer Available</h5>
        <p>This branch doesn't have a kiosk printer set up yet, so we can't print your slip. Tap Close, then take a photo of your queue number so you don't forget it.</p>
        <div class="printer-setup-actions">
            <button type="button" class="btn btn-outline-secondary" id="printerSetupCloseActionBtn">Close</button>
            <a href="<?= htmlspecialchars($printerSettingsUrl) ?>" target="_blank" class="btn" style="background:var(--brand-navy);color:#fff;" id="printerSetupGoBtn">Set Up</a>
        </div>
    </div>
</div>

<!-- Shown when the back arrow is tapped on a ticket that hasn't been
     printed yet — going back at that point abandons the queue number
     with no physical slip in hand, so it's confirmed first rather than
     silently discarded by a stray tap. -->
<div class="printer-setup-overlay" id="cancelConfirmModal">
    <div class="printer-setup-card">
        <div class="printer-setup-icon" style="background:var(--brand-pink-bg);color:var(--brand-red-2);"><i class="bi bi-exclamation-triangle"></i></div>
        <h5>Cancel this queue number?</h5>
        <p>You haven't printed your slip yet. Tap Print to keep this number, or Cancel to release it.</p>
        <div class="printer-setup-actions">
            <button type="button" class="btn btn-outline-secondary" id="cancelConfirmNoBtn">No, Print It</button>
            <button type="button" class="btn" style="background:var(--brand-red-2);color:#fff;" id="cancelConfirmYesBtn">Yes, Cancel</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var BRANCH_SLUG = <?= json_encode($branchSlug) ?>;
    var PHARMACY_NAME = <?= json_encode($pharmacy['pharmacy_name']) ?>;
    // Address and website live in PHP scope already (address is used by
    // the on-screen kiosk-footer; host is what queue.php/kiosk.php both
    // derive their absolute URLs from) — reused here for the printed
    // slip's header/footer rather than inventing new fields on the
    // ticket payload itself, since neither varies per-ticket.
    var PHARMACY_ADDRESS = <?= json_encode($pharmacy['address'] ?? '') ?>;
    var PHARMACY_WEBSITE = <?= json_encode($printableWebsite) ?>;
    // Absolute URL to the Public Queuing Site for this branch. Used as
    // the QR target whenever a ticket doesn't already carry its own
    // 'public_url' (see the PHP fallback-stamping note above).
    var PUBLIC_MONITOR_URL = <?= json_encode($publicMonitorUrl) ?>;
    // Every field printer_settings.php saves for the branch's chosen
    // connection type flows straight through here untouched. New driver
    // types (see PRINT_DRIVERS below) only need code added in TWO places:
    // printer_settings.php (a config form) and PRINT_DRIVERS (a print
    // function) -- never here.
    var printerConfig = <?= json_encode([
        'type'           => $printer['connectionType'] ?? null,
        'ip'             => $printer['ip'] ?? null,
        'port'           => $printer['port'] ?? null,
        'protocol'       => $printer['protocol'] ?? null,
        'serialBaudRate' => $printer['serialBaudRate'] ?? 9600,
        'usbVendorId'    => $printer['usbVendorId'] ?? null,
        'usbProductId'   => $printer['usbProductId'] ?? null,
        'agentUrl'       => $printer['agentUrl'] ?? null,
        'agentApiKey'    => $printer['agentApiKey'] ?? null,
    ]) ?>;
    var printerConfigured = <?= !empty($printer['isConfigured']) ? 'true' : 'false' ?>;

    var stage = document.getElementById('kioskStage');
    var setupModal = document.getElementById('printerSetupModal');
    var printTicketEl = document.getElementById('printTicket');

    /*
     * ================= CONFIG =================
     * Single source of truth for both reset delays, per the "don't
     * hardcode a delay in multiple places" requirement. RESET_DELAY_MS
     * is the short pause after a successful print before the kiosk
     * returns to idle; ABANDONED_TICKET_TIMEOUT_MS is the longer grace
     * period for a ticket that was generated but never printed (e.g.
     * patient walked away) before that slot also gives up and resets.
     */
    var RESET_DELAY_MS = 3000;
    var ABANDONED_TICKET_TIMEOUT_MS = 90000;
    // Floor on how long the "Printing…" state stays visible, for the
    // same reason MIN_LOADING_MS exists on the cancel confirmation --
    // see the comment at its usage site (printWalkinTicket).
    var MIN_PRINT_LOADING_MS = 900;

    function escapeHtml(str) {
        // NOTE: a DOM round-trip (textContent -> innerHTML) only escapes
        // &, <, > — it leaves " and ' untouched. That's fine for plain
        // text nodes, but this function's output also gets concatenated
        // into double-quoted HTML attributes, so a bare " typed on the
        // kiosk touch keyboard could break out of an attribute and
        // inject markup/JS. Escape all five reserved characters instead.
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Renders a QR code as inline SVG into `container`, encoding `text`.
    // No-ops if the library failed to load, rather than breaking the
    // rest of the ticket.
    function renderQrInto(container, text) {
        if (!container || !text || typeof qrcode === 'undefined') return;
        try {
            var qr = qrcode(0, 'M');
            qr.addData(text);
            qr.make();
            container.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
        } catch (e) {
            console.warn('QR generation failed:', e);
        }
    }

    /*
     * ================= RESET TIMERS =================
     * Three INDEPENDENT layers, not one shared timer:
     *
     *  1. Per walk-in category (slotTimers.Regular / slotTimers.Priority)
     *     — resets ONLY that one card back to its own "select" button.
     *  2. The E-Press flow (epresTimer) — resets ONLY the E-Press card
     *     back to its default "E-Press Online" state.
     *  3. A genuinely global whole-kiosk inactivity timer
     *     (globalIdleTimeoutId) that returns all the way to the outer
     *     tap-to-begin screen, but ONLY after real inactivity anywhere
     *     on the page — armed once in renderMainOptions() and extended
     *     by every click inside the stage.
     *
     * A single shared timer used to drive all of this: finishing or
     * printing ONE ticket would call the equivalent of (3) directly,
     * which nukes the entire stage — including whatever the OTHER
     * category or the E-Press flow was doing at that exact moment.
     * That's what caused two symptoms that looked unrelated but shared
     * one cause: the sibling option appearing to "stop working," and
     * the E-Press Back button appearing unresponsive (the screen had
     * already reset out from under the user before or during their tap).
     */
    var slotTimers = {};   // { Regular: timeoutId, Priority: timeoutId }
    var epresTimer = null;
    var globalIdleTimeoutId = null;
    var GLOBAL_INACTIVITY_MS = 120000; // whole-kiosk "nobody's here" reset

    function scheduleSlotReset(category, ms) {
        clearSlotTimer(category);
        slotTimers[category] = setTimeout(function () { resetWalkinSlot(category); }, ms);
    }
    function clearSlotTimer(category) {
        if (slotTimers[category]) { clearTimeout(slotTimers[category]); slotTimers[category] = null; }
    }
    // Resets ONE category's card back to its own idle "select" button,
    // without touching the sibling card or the E-Press panel at all.
    function resetWalkinSlot(category) {
        var el = document.querySelector('#walkinCatButtons .cat-btn[data-category="' + category + '"]');
        if (!el) return; // user already navigated away from this screen entirely
        el.outerHTML = catButtonHtml(category);
        var fresh = document.querySelector('#walkinCatButtons .cat-btn[data-category="' + category + '"]');
        if (fresh) fresh.addEventListener('click', function () { createWalkinTicket(category, fresh); });
        // This category is back to select state, so its sibling (locked
        // the moment THIS category got a ticket — see
        // lockSiblingCategoryButton) is free to be used again.
        unlockSiblingCategoryButton(category);
    }

    /*
     * Only one active queue number per kiosk session: once a category
     * has a ticket in progress, the OTHER category's button is locked
     * so the same walk-up patient can't also grab a number for the
     * other line. It unlocks again as soon as the chosen category's
     * slot returns to its own idle "select" state — whether that's
     * because its ticket printed and auto-reset, it was abandoned and
     * timed out, or the patient explicitly cancelled via the back
     * arrow (see unlockSiblingCategoryButton, called from all of
     * those paths) — never tied to the whole kiosk resetting.
     *
     * The E-Press card locks alongside the sibling category for the
     * same reason: a walk-in ticket in progress is still "one active
     * queue number this session," so starting an E-Press lookup at the
     * same time would let the same patient end up holding two numbers.
     */
    function lockSiblingCategoryButton(chosenCategory) {
        var otherCategory = chosenCategory === 'Regular' ? 'Priority' : 'Regular';
        var otherBtn = document.querySelector('#walkinCatButtons .cat-btn[data-category="' + otherCategory + '"][data-state="select"]');
        if (otherBtn) {
            otherBtn.disabled = true;
            otherBtn.classList.add('is-locked');
        }
        lockEpresCard();
    }
    function unlockSiblingCategoryButton(justFreedCategory) {
        var otherCategory = justFreedCategory === 'Regular' ? 'Priority' : 'Regular';
        var otherBtn = document.querySelector('#walkinCatButtons .cat-btn[data-category="' + otherCategory + '"][data-state="select"]');
        if (otherBtn) {
            otherBtn.disabled = false;
            otherBtn.classList.remove('is-locked');
        }
        unlockEpresCard();
    }

    function lockEpresCard() {
        var card = document.getElementById('epresCard');
        if (card) card.classList.add('is-locked');
    }
    function unlockEpresCard() {
        var card = document.getElementById('epresCard');
        if (card) card.classList.remove('is-locked');
    }

    // Reverse direction: called when an E-Press ticket becomes active
    // (renderEpresTicket) / resets back to the default screen
    // (renderEpresDefault) — locks or unlocks BOTH walk-in category
    // buttons, mirroring lockSiblingCategoryButton/lockEpresCard above.
    function lockWalkinButtons() {
        document.querySelectorAll('#walkinCatButtons .cat-btn[data-state="select"]').forEach(function (btn) {
            btn.disabled = true;
            btn.classList.add('is-locked');
        });
    }
    function unlockWalkinButtons() {
        document.querySelectorAll('#walkinCatButtons .cat-btn[data-state="select"]').forEach(function (btn) {
            btn.disabled = false;
            btn.classList.remove('is-locked');
        });
    }

    function scheduleEpresReset(ms) {
        clearEpresTimer();
        epresTimer = setTimeout(renderEpresDefault, ms);
    }
    function clearEpresTimer() {
        if (epresTimer) { clearTimeout(epresTimer); epresTimer = null; }
    }

    // Whole-kiosk inactivity timer — the ONLY thing allowed to call
    // renderIdle() (return to the outer tap-to-begin screen) once a
    // session is underway.
    function clearIdleTimer() {
        if (globalIdleTimeoutId) { clearTimeout(globalIdleTimeoutId); globalIdleTimeoutId = null; }
    }
    function armGlobalInactivityTimer() {
        clearIdleTimer();
        globalIdleTimeoutId = setTimeout(renderIdle, GLOBAL_INACTIVITY_MS);
    }

    /* ================= IDLE SCREEN ================= */
    function renderIdle() {
        clearIdleTimer();
        stage.innerHTML =
            '<div class="idle-card" id="idleCard">' +
                // '<div class="idle-icon"><i class="bi bi-capsule-pill"></i></div> ' +
                '<div class="idle-title">Welcome to ' + escapeHtml(PHARMACY_NAME) + '</div>' +
                '<p class="idle-sub">Tap anywhere to get your queue number</p>' +
                '<div class="idle-pulse-wrap"><div class="idle-pulse"></div></div>' +
            '</div>';
        document.getElementById('idleCard').addEventListener('click', function () { renderMainOptions(); });
    }

    /* ================= MAIN OPTION SCREEN ================= */
    function renderMainOptions() {
        clearIdleTimer();
        stage.innerHTML =
            '<div class="option-stage">' +
                '<div class="epres-card" id="epresCard"></div>' +
                '<div class="walkin-card">' +
                    '<h4 class="walkin-heading">Print your <span class="accent">walk-in</span> number</h4>' +
                    '<div id="walkinAlert"></div>' +
                    '<div class="cat-buttons" id="walkinCatButtons">' +
                        catButtonHtml('Regular') +
                        catButtonHtml('Priority') +
                    '</div>' +
                '</div>' +
            '</div>';

        /*
         * Delegated click handling for the E-Press card's "Back" /
         * "Start Over" button, bound ONCE to the stable #epresCard
         * element rather than re-bound via getElementById every time
         * a sub-screen (Rx form, category step) replaces the card's
         * inner content. Previously each sub-screen re-ran
         * `document.getElementById('epresBackBtn').addEventListener(...)`
         * immediately after its own innerHTML assignment — correct in
         * principle, but a single missed/duplicate binding anywhere in
         * that chain (e.g. from an earlier bug) silently breaks the
         * button with no visible error. Delegation removes that whole
         * class of failure: as long as #epresCard itself exists (it
         * does for this entire kiosk session), a click landing on
         * anything with id="epresBackBtn" inside it is caught here,
         * regardless of how many times its content has been swapped.
         */
        document.getElementById('epresCard').addEventListener('click', function (e) {
            if (e.target.closest('#epresBackBtn')) {
                e.stopPropagation();
                renderEpresDefault();
            }
        });

        renderEpresDefault();
        wireSelectButtons(document.getElementById('walkinCatButtons'), function (category, btnEl) {
            createWalkinTicket(category, btnEl);
        });

        // Arm the whole-kiosk inactivity timer, and extend it on any tap
        // anywhere in the stage — this is the ONLY thing that returns
        // all the way to the outer idle screen; it never fires just
        // because one slot finished printing. Bound once ever (stage
        // itself is a stable element reused across every idle<->options
        // cycle for the life of this page), guarded so repeated visits
        // to this screen don't keep stacking duplicate listeners.
        armGlobalInactivityTimer();
        if (!stage.dataset.activityBound) {
            stage.addEventListener('click', armGlobalInactivityTimer);
            stage.dataset.activityBound = '1';
        }
    }

    // Real brand vector — walking-figure pictogram (body + head paths),
    // rendered with fill="currentColor" so one markup can be tinted
    // blue (Regular) or pink (Priority) via ordinary CSS `color`. See
    // .wm-figure / .wm-pos-idle / .wm-pos-ticket for positioning.
    var WM_BODY_SVG =
        '<svg class="wm-body" viewBox="0 0 196 163" fill="none" xmlns="http://www.w3.org/2000/svg">' +
        '<path fill-rule="evenodd" clip-rule="evenodd" d="M184.708 77.825H133.635L145.039 49.7525C147.215 44.4316 147.213 38.4881 145.034 33.1687C142.855 27.8493 138.668 23.5674 133.352 21.2214L96.5698 4.90594C92.8682 3.26326 88.9972 2.01919 85.0241 1.19537L84.8124 1.16064L84.5795 1.11199C80.8927 0.377696 77.141 0.00527422 73.3796 0.000203158H30.6265C23.8509 -0.0231344 17.2255 1.96469 11.6145 5.70435C6.00357 9.44401 1.66756 14.7619 -0.827858 20.9643L-17.7654 62.5797C-18.8772 65.3182 -18.8385 68.3794 -17.658 71.0899C-16.4774 73.8004 -14.2516 75.9381 -11.4703 77.0328C-8.6889 78.1275 -5.5798 78.0895 -2.82691 76.9271C-0.0740356 75.7647 2.09711 73.5732 3.20892 70.8346L20.1111 29.2262C20.9444 27.1535 22.3943 25.3771 24.2707 24.1297C26.1471 22.8823 28.3625 22.2222 30.6265 22.2359H66.407L16.2084 145.804C13.7114 152.005 9.37498 157.322 3.76438 161.061C-1.84622 164.8 -8.47077 166.789 -15.2459 166.768H-63.7083C-66.7031 166.768 -69.5752 167.939 -71.6928 170.024C-73.8104 172.109 -75 174.937 -75 177.885C-75 180.834 -73.8104 183.662 -71.6928 185.747C-69.5752 187.832 -66.7031 189.003 -63.7083 189.003H-15.2459C-3.94759 189.046 7.10152 185.735 16.4593 179.501C25.8172 173.267 33.049 164.401 37.2109 154.059L46.336 131.6L123.31 166.524C128.553 168.881 133.094 172.524 136.498 177.103C139.902 181.683 142.056 187.046 142.753 192.679L150.918 257.1C151.262 259.787 152.59 262.259 154.654 264.05C156.717 265.841 159.374 266.829 162.125 266.828C162.597 266.828 163.068 266.8 163.536 266.745C165.008 266.563 166.429 266.097 167.718 265.374C169.007 264.651 170.139 263.685 171.049 262.531C171.959 261.377 172.628 260.058 173.02 258.649C173.412 257.241 173.518 255.77 173.332 254.32L165.153 189.907C163.993 180.517 160.405 171.578 154.732 163.944C149.059 156.311 141.491 150.239 132.753 146.311L110 135.992L124.82 99.5186C125.928 99.8735 127.085 100.056 128.25 100.061H184.708C187.703 100.061 190.575 98.8893 192.693 96.8043C194.81 94.7193 196 91.8914 196 88.9428C196 85.9942 194.81 83.1663 192.693 81.0813C190.575 78.9963 187.703 77.825 184.708 77.825ZM54.6989 110.928L89.1879 26.0229L124.072 41.4905L89.4349 126.695L54.6989 110.928Z" fill="currentColor"/>' +
        '</svg>';
    var WM_HEAD_SVG =
        '<svg class="wm-head" viewBox="0 0 68 59" fill="none" xmlns="http://www.w3.org/2000/svg">' +
        '<path d="M33.875 58.7069C40.5748 58.7069 47.1242 56.7508 52.6949 53.0859C58.2656 49.421 62.6075 44.2118 65.1714 38.1173C67.7353 32.0228 68.4062 25.3165 67.0991 18.8465C65.792 12.3766 62.5657 6.43358 57.8282 1.76902C53.0907 -2.89555 47.0548 -6.07216 40.4837 -7.35911C33.9126 -8.64606 27.1015 -7.98555 20.9116 -5.4611C14.7218 -2.93666 9.43121 1.33835 5.70898 6.8233C1.98675 12.3082 0 18.7568 0 25.3535C0 34.1994 3.56898 42.683 9.92177 48.9379C16.2746 55.1929 24.8908 58.7069 33.875 58.7069ZM33.875 14.2357C36.1083 14.2357 38.2914 14.8877 40.1483 16.1093C42.0052 17.331 43.4525 19.0674 44.3071 21.0989C45.1618 23.1304 45.3854 25.3658 44.9497 27.5225C44.514 29.6791 43.4386 31.6601 41.8594 33.215C40.2803 34.7698 38.2683 35.8287 36.0779 36.2577C33.8875 36.6867 31.6172 36.4665 29.5539 35.625C27.4906 34.7835 25.7271 33.3585 24.4863 31.5302C23.2456 29.7019 22.5833 27.5524 22.5833 25.3535C22.5833 22.4049 23.773 19.577 25.8906 17.492C28.0082 15.407 30.8803 14.2357 33.875 14.2357Z" fill="currentColor"/>' +
        '</svg>';

    // Watermark wrapper for a cat-btn card. `pos` is 'idle' (bleeds off
    // the left edge, per the reference) or 'ticket' (tucked to the
    // right, behind the PRINT label).
    function catWatermarkHtml(pos) {
        return (
            '<div class="wm-figure wm-pos-' + pos + '" aria-hidden="true">' +
                WM_BODY_SVG + WM_HEAD_SVG +
            '</div>'
        );
    }

    // Real brand vector — cloud + checkmark pair, used once behind the
    // E-Press card's pill graphic.
    var WM_CHECK_SVG =
        '<svg class="wm-check" viewBox="0 0 150 133" fill="none" xmlns="http://www.w3.org/2000/svg">' +
        '<path d="M135.83 1.93415C132.002 -1.01147 126.738 -0.520531 123.388 2.91602L52.5683 85.3934L31.0351 59.8647C27.6855 55.9372 22.4218 55.4462 18.5937 58.8828L3.28124 71.6472C-0.546876 74.5928 -1.02539 80.484 1.8457 84.4115L33.4277 128.105C35.3417 130.56 37.7343 131.541 40.6054 132.032H64.0526C66.9237 132.032 69.7948 130.56 71.2303 128.105L147.314 23.5354C150.185 19.6079 149.707 14.2076 145.879 10.771L135.83 1.93415Z" fill="currentColor"/>' +
        '</svg>';
    var WM_CLOUD_SVG =
        '<svg class="wm-cloud-shape" viewBox="0 0 455 325" fill="none" xmlns="http://www.w3.org/2000/svg">' +
        '<path d="M376.591 108.006C359.843 44.6752 303.378 0 238.3 0C183.75 0 133.506 32.8928 109.58 82.4773C48.33 86.8958 0 139.426 0 203.739C0 270.506 53.1151 325 118.193 325H346.445C405.781 325 454.589 275.415 454.589 214.048C454.589 163.973 422.529 121.261 376.591 108.006ZM346.445 287.198H118.193C73.2128 287.198 36.8456 249.887 36.8456 203.739C36.8456 157.591 73.2128 120.279 118.193 120.279C119.15 120.279 120.107 120.279 121.064 120.279C128.72 120.279 136.377 115.861 138.769 108.006C154.56 66.2764 194.277 37.8021 238.3 37.8021C289.501 37.8021 333.525 75.1133 343.095 126.662C344.531 134.517 350.751 140.899 358.408 141.881C392.861 147.772 417.743 177.719 417.743 213.557C417.743 254.305 385.683 287.198 346.445 287.198Z" fill="currentColor"/>' +
        '</svg>';

    function epresWatermarkHtml() {
        return (
            '<div class="wm-cloud" aria-hidden="true">' +
                WM_CLOUD_SVG + WM_CHECK_SVG +
            '</div>'
        );
    }

    /* ---- Category button component: select (idle) state ---- */
    function catButtonHtml(category) {
        var isPriority = category === 'Priority';
        var word = isPriority ? 'PRIORITY' : 'REGULAR';
        return (
            '<button type="button" class="cat-btn cat-btn-' + category.toLowerCase() + '" data-category="' + category + '" data-state="select">' +
                catWatermarkHtml('idle') +
                '<span class="cat-btn-label">I\'m ' + word + '<small>Patient</small></span>' +
            '</button>'
        );
    }

    function wireSelectButtons(container, onSelect) {
        container.querySelectorAll('.cat-btn[data-state="select"]').forEach(function (btn) {
            btn.addEventListener('click', function () { onSelect(btn.dataset.category, btn); });
        });
    }

    /*
     * generateQueue(category) equivalent — creates a walk-in queue entry
     * via the EXISTING kiosk_api.php walkin_create action (backend stays
     * the source of truth for the number/prefix). `btnEl` is disabled
     * the instant the tap registers so a rapid double-tap can't fire a
     * second request before this one resolves.
     */
    function createWalkinTicket(category, btnEl) {
        if (btnEl.disabled) return; // belt-and-suspenders double-tap guard
        btnEl.disabled = true;
        apiCall('walkin_create', { category: category }).then(function (data) {
            if (data.status === 'ticket') {
                showTicketInButton(btnEl, data.ticket);
            } else {
                showWalkinGenerationError(category, btnEl, data.message);
            }
        }).catch(function () {
            showWalkinGenerationError(category, btnEl, null);
        });
    }

    // Queue-number generation failed — kiosk-friendly error card in
    // place of the button, with a Try Again that just re-arms the
    // normal select button (no technical detail ever surfaces here).
    function showWalkinGenerationError(category, btnEl, technicalMessage) {
        if (technicalMessage) console.warn('Queue generation error:', technicalMessage);
        var wrapper = document.createElement('div');
        wrapper.className = 'cat-btn cat-btn-' + category.toLowerCase();
        wrapper.dataset.category = category;
        wrapper.innerHTML =
            catWatermarkHtml('idle') +
            '<div class="cat-error-col">' +
                '<div class="cat-error-text">Unable to get a queue number.<br>Please try again.</div>' +
                '<button type="button" class="cat-retry-btn">Try Again</button>' +
            '</div>';
        btnEl.replaceWith(wrapper);
        wrapper.querySelector('.cat-retry-btn').addEventListener('click', function () {
            // `wrapper` is already attached to the DOM (it replaced btnEl
            // above), so its own outerHTML setter works correctly — no
            // detached-node creation needed. (A previous version created
            // a fresh, unattached <button> and set outerHTML on THAT,
            // which throws — setting outerHTML on a parentless node is
            // invalid per the DOM spec — silently breaking the button.)
            wrapper.outerHTML = catButtonHtml(category);
            var replaced = document.querySelector('#walkinCatButtons .cat-btn[data-category="' + category + '"]');
            // Wire ONLY this specific button, not the whole container —
            // re-wiring the container would attach a SECOND click
            // listener to whichever sibling button is still in its
            // original select state, since it would also match
            // `.cat-btn[data-state="select"]`. That stacked listener,
            // repeated across every retry/cancel, is exactly the kind
            // of bug that makes a button "sometimes stop responding
            // right" without an outright crash.
            replaced.addEventListener('click', function () { createWalkinTicket(category, replaced); });
        });
    }

    function showWalkinAlert(msg) {
        var holder = document.getElementById('walkinAlert');
        if (!holder) return;
        holder.innerHTML = '<div class="epres-alert">' + escapeHtml(msg) + '</div>';
    }

    /*
     * ---- Morph a select-state button into its ticket (ready-to-print)
     * state: back/cancel | big number | PRINT, matching the reference
     * screenshots. Every element here is scoped to THIS slot only, so
     * Regular and Priority operate as fully independent state machines.
     */
    function showTicketInButton(btnEl, ticket) {
        var wrapper = document.createElement('div');
        wrapper.className = 'cat-btn cat-btn-' + ticket.category.toLowerCase() + ' is-ticket';
        wrapper.dataset.category = ticket.category;
        wrapper.innerHTML =
            catWatermarkHtml('ticket') +
            '<div class="cat-ticket-row">' +
                '<button type="button" class="cat-back-btn" aria-label="Cancel queue selection"><i class="bi bi-chevron-left"></i></button>' +
                '<span class="cat-ticket-number">' + escapeHtml(ticket.label) + '</span>' +
                '<button type="button" class="cat-print-label" aria-label="Print queue number">Print</button>' +
            '</div>';
        btnEl.replaceWith(wrapper);
        lockSiblingCategoryButton(ticket.category);

        /*
         * Cancel BEFORE printing. Confirmed first: going back without
         * printing means walking away with no physical ticket, so a
         * stray tap here shouldn't silently discard an already-
         * generated queue number. Confirmed cancellation calls
         * cancelWalkinQueueEntry() below to actually mark the row
         * Cancelled server-side (via kiosk_api.php's cancel_ticket
         * action) -- this used to be a purely local UI reset with no
         * backend effect, which left an orphaned 'Waiting' row in the
         * database every time a patient backed out instead of it
         * showing up in queue.php's Cancelled tab.
         */
        wrapper.querySelector('.cat-back-btn').addEventListener('click', function () {
            confirmCancelTicket(
                async function () {
                    await cancelWalkinQueueEntry(ticket);
                    // See the note in showWalkinGenerationError: `wrapper` is
                    // already in the DOM, so its outerHTML setter works — no
                    // detached-node creation, which is what silently broke this
                    // button before. And per that same note, wire only THIS
                    // button, not the whole container.
                    wrapper.outerHTML = catButtonHtml(ticket.category);
                    var replaced = document.querySelector('#walkinCatButtons .cat-btn[data-category="' + ticket.category + '"]');
                    replaced.addEventListener('click', function () { createWalkinTicket(ticket.category, replaced); });
                    unlockSiblingCategoryButton(ticket.category);
                },
                function () { printWalkinTicket(ticket, wrapper); }
            );
        });

        wrapper.querySelector('.cat-print-label').addEventListener('click', function () {
            printWalkinTicket(ticket, wrapper);
        });

        scheduleSlotReset(ticket.category, ABANDONED_TICKET_TIMEOUT_MS);
    }

    /*
     * Print flow for a walk-in ticket: printing -> success (checkmark)
     * or failure (retry, same ticket/number — never re-generates).
     */
    var printLocks = {}; // per-category lock, so Regular and Priority never block each other

    async function printWalkinTicket(ticket, wrapperEl) {
        var category = ticket.category;
        if (printLocks[category]) return; // guards against a double-tap on Print
        printLocks[category] = true;

        var printBtn = wrapperEl.querySelector('.cat-print-label');
        var backBtn = wrapperEl.querySelector('.cat-back-btn');
        if (printBtn) { printBtn.disabled = true; printBtn.textContent = 'Printing…'; }
        if (backBtn) backBtn.disabled = true;
        clearSlotTimer(category); // no auto-reset for THIS slot while its print job is in flight

        if (!printerConfigured) {
            printLocks[category] = false;
            if (printBtn) { printBtn.disabled = false; printBtn.textContent = 'Print'; }
            showPrinterSetupModal();
            scheduleSlotReset(category, ABANDONED_TICKET_TIMEOUT_MS);
            return;
        }

        try {
            populatePrintTicketDom(ticket);
            // Same reasoning as MIN_LOADING_MS on the cancel confirmation:
            // a same-server/local print call can resolve in well under
            // 100ms, which reads as a flicker rather than a deliberate
            // "Printing…" state on a kiosk touchscreen. Runs concurrently
            // with the real print attempt (Promise.all), so a genuinely
            // slow print is never held up waiting on this floor.
            await Promise.all([
                attemptDirectPrintOrFallback(ticket),
                new Promise(function (resolve) { setTimeout(resolve, MIN_PRINT_LOADING_MS); }),
            ]);
            showWalkinPrintSuccess(ticket, wrapperEl);
        } catch (e) {
            console.warn('Print failed:', e);
            showWalkinPrintError(ticket, wrapperEl);
        } finally {
            printLocks[category] = false;
        }
    }

    function showWalkinPrintSuccess(ticket, wrapperEl) {
        wrapperEl.classList.remove('is-ticket');
        wrapperEl.classList.add('is-done');
        // No watermark here — the reference design shows a clean card
        // for the print-success confirmation.
        wrapperEl.innerHTML =
            '<div class="cat-done-col">' +
                '<i class="bi bi-check-circle cat-done-icon" aria-hidden="true"></i>' +
                '<span class="cat-done-text">Please wait for your number to be called.</span>' +
            '</div>';
        scheduleSlotReset(ticket.category, RESET_DELAY_MS);
    }

    // Print failed: the SAME ticket/number stays live for a retry — a
    // failed print must never trigger a second queue number.
    function showWalkinPrintError(ticket, wrapperEl) {
        wrapperEl.innerHTML =
            catWatermarkHtml('ticket') +
            '<div class="cat-error-col">' +
                '<div class="cat-error-text">Printing failed.<br>Please try again.</div>' +
                '<button type="button" class="cat-retry-btn">Try Again</button>' +
            '</div>';
        wrapperEl.querySelector('.cat-retry-btn').addEventListener('click', function () {
            showTicketInButtonFromWrapper(wrapperEl, ticket);
        });
        scheduleSlotReset(ticket.category, ABANDONED_TICKET_TIMEOUT_MS);
    }

    // Rebuilds the ticket (back | number | Print) row in-place on an
    // existing wrapper — used when retrying after a print error, where
    // we deliberately reuse the same ticket object rather than calling
    // showTicketInButton (which expects to replace a plain button).
    function showTicketInButtonFromWrapper(wrapperEl, ticket) {
        wrapperEl.className = 'cat-btn cat-btn-' + ticket.category.toLowerCase() + ' is-ticket';
        wrapperEl.innerHTML =
            catWatermarkHtml('ticket') +
            '<div class="cat-ticket-row">' +
                '<button type="button" class="cat-back-btn" aria-label="Cancel queue selection"><i class="bi bi-chevron-left"></i></button>' +
                '<span class="cat-ticket-number">' + escapeHtml(ticket.label) + '</span>' +
                '<button type="button" class="cat-print-label" aria-label="Print queue number">Print</button>' +
            '</div>';
        wrapperEl.querySelector('.cat-back-btn').addEventListener('click', function () {
            confirmCancelTicket(
                async function () {
                    await cancelWalkinQueueEntry(ticket);
                    wrapperEl.outerHTML = catButtonHtml(ticket.category);
                    var replaced = document.querySelector('#walkinCatButtons .cat-btn[data-category="' + ticket.category + '"]');
                    replaced.addEventListener('click', function () { createWalkinTicket(ticket.category, replaced); });
                    unlockSiblingCategoryButton(ticket.category);
                },
                function () { printWalkinTicket(ticket, wrapperEl); }
            );
        });
        wrapperEl.querySelector('.cat-print-label').addEventListener('click', function () {
            printWalkinTicket(ticket, wrapperEl);
        });
        scheduleSlotReset(ticket.category, ABANDONED_TICKET_TIMEOUT_MS);
    }

    /* ================= E-PRESS PANEL ================= */
    function renderEpresDefault() {
        clearEpresTimer(); // navigating away from any in-progress ticket cancels its stale timer
        var card = document.getElementById('epresCard');
        card.classList.remove('is-active');
        card.innerHTML =
            epresWatermarkHtml() +
            // '<div class="pill-hero"><span>PLANET<br>DRUGSTORE</span></div>' +
            '<div class="epres-heading">E-Press <span class="accent">Online</span></div>' +
            '<p class="epres-sub">I have a prescription from the <br> E-press app.</p>';
        card.onclick = function () { renderEpresRxForm(); };
        // No E-Press ticket active anymore (if one ever was) -- release
        // the walk-in side. Safe to call unconditionally: unlocking an
        // already-unlocked button is a no-op.
        unlockWalkinButtons();
    }

    function epresBackButton(label) {
        return '<button type="button" class="epres-back" id="epresBackBtn"><i class="bi bi-arrow-left"></i> ' + escapeHtml(label || 'Back') + '</button>';
    }

    function renderEpresRxForm(errorMsg, prefillRxNo) {
        var card = document.getElementById('epresCard');
        card.onclick = null;
        card.classList.add('is-active');
        card.innerHTML =
            epresBackButton('Back') +
            '<div class="epres-heading" style="font-size:1.05rem;"><i class="bi bi-prescription2"></i> Enter Prescription Number</div>' +
            (errorMsg ? '<div class="epres-alert">' + escapeHtml(errorMsg) + '</div>' : '') +
            '<div class="epres-form">' +
                '<input type="text" id="epresRxNo" class="form-control form-control-lg text-uppercase" placeholder="e.g. RX-20260711-001" value="' + escapeHtml(prefillRxNo || '') + '">' +
                '<button type="button" class="btn-epres-submit" id="epresSubmitBtn">Get Queue Number</button>' +
            '</div>';

        // Back button is handled by the delegated listener on
        // #epresCard set up once in renderMainOptions() — no per-screen
        // binding needed here.
        var input = document.getElementById('epresRxNo');
        input.focus();

        function submit() {
            var rxNo = input.value;
            apiCall('epres_lookup', { rx_no: rxNo }).then(function (data) { handleEpresResponse(data, rxNo); });
        }
        document.getElementById('epresSubmitBtn').addEventListener('click', submit);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
    }

    function handleEpresResponse(data, rxNo) {
        if (data.status === 'error') renderEpresRxForm(data.message, rxNo);
        else if (data.status === 'category_needed') renderEpresCategoryStep(data.rx_no, data.patient_name);
        else if (data.status === 'ticket') renderEpresTicket(data.ticket);
    }

    function renderEpresCategoryStep(rxNo, patientName) {
        var card = document.getElementById('epresCard');
        card.innerHTML =
            epresBackButton('Start Over') +
            '<div class="epres-heading" style="font-size:1.05rem;"><i class="bi bi-prescription2"></i> Prescription Found</div>' +
            '<p class="epres-rx-context"><strong>' + escapeHtml(rxNo) + '</strong>' +
                (patientName ? ' — ' + escapeHtml(patientName) : '') +
                '<br>Select your queue category.</p>' +
            '<div class="cat-buttons" id="epresCatButtons">' +
                catButtonHtml('Regular') +
                catButtonHtml('Priority') +
            '</div>';

        // Back/Start Over is handled by the delegated listener on
        // #epresCard set up once in renderMainOptions().
        wireSelectButtons(document.getElementById('epresCatButtons'), function (category, btnEl) {
            btnEl.disabled = true;
            btnEl.style.opacity = '.6';
            apiCall('epres_lookup', { rx_no: rxNo, category: category }).then(function (data) {
                if (data.status === 'ticket') renderEpresTicket(data.ticket);
                else handleEpresResponse(data, rxNo);
            });
        });
    }

    function renderEpresTicket(ticket) {
        var card = document.getElementById('epresCard');
        card.onclick = null;
        card.classList.add('is-active');
        card.innerHTML =
            '<div class="epres-ticket" id="epresTicketBlock">' +
                '<span class="category-tag ' + escapeHtml(ticket.category) + '">' + escapeHtml(ticket.category) + '</span>' +
                '<p class="ticket-meta mb-0">Your Queue Number</p>' +
                '<div class="ticket-number">' + escapeHtml(ticket.label) + '</div>' +
                (ticket.prescription_id ? '<p class="ticket-meta">Rx No. ' + escapeHtml(ticket.prescription_number) + '</p>' : '') +
                '<p class="ticket-meta">' + escapeHtml(ticket.datetime) + '</p>' +
                '<button type="button" class="btn-print-epres" id="epresPrintBtn" aria-label="Print queue number"><i class="bi bi-printer-fill"></i> Print Queue Slip</button>' +
            '</div>';

        document.getElementById('epresPrintBtn').addEventListener('click', function () {
            printEpresTicket(ticket);
        });

        // An E-Press ticket now exists -- lock the walk-in side so the
        // same patient can't also grab a walk-in number while this one
        // is still active. Mirrors lockSiblingCategoryButton's call to
        // lockEpresCard() in the other direction.
        lockWalkinButtons();

        scheduleEpresReset(ABANDONED_TICKET_TIMEOUT_MS);
    }

    var epresPrintLock = false;

    async function printEpresTicket(ticket) {
        if (epresPrintLock) return; // guards against a double-tap on Print
        epresPrintLock = true;

        var block = document.getElementById('epresTicketBlock');
        var btn = block ? block.querySelector('#epresPrintBtn') : null;
        if (btn) { btn.disabled = true; btn.innerHTML = 'Printing…'; }
        clearEpresTimer(); // no auto-reset for the E-Press flow while its print job is in flight

        if (!printerConfigured) {
            epresPrintLock = false;
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-printer-fill"></i> Print Queue Slip'; }
            showPrinterSetupModal();
            scheduleEpresReset(ABANDONED_TICKET_TIMEOUT_MS);
            return;
        }

        try {
            populatePrintTicketDom(ticket);
            // Same MIN_PRINT_LOADING_MS floor as the walk-in print flow
            // -- see the comment at printWalkinTicket for why.
            await Promise.all([
                attemptDirectPrintOrFallback(ticket),
                new Promise(function (resolve) { setTimeout(resolve, MIN_PRINT_LOADING_MS); }),
            ]);
            block.classList.add('is-done');
            block.innerHTML =
                '<span class="category-tag ' + escapeHtml(ticket.category) + '">' + escapeHtml(ticket.category) + '</span>' +
                '<div class="ticket-number">' + escapeHtml(ticket.label) + '</div>' +
                '<div class="done-block">' +
                    '<i class="bi bi-check-circle cat-done-icon" aria-hidden="true"></i>' +
                    '<p class="ticket-meta mb-0">Please wait for your number to be called.</p>' +
                '</div>';
            scheduleEpresReset(RESET_DELAY_MS);
        } catch (e) {
            console.warn('Print failed:', e);
            // Same ticket/number stays live for a retry — never re-generate.
            block.innerHTML =
                '<span class="category-tag ' + escapeHtml(ticket.category) + '">' + escapeHtml(ticket.category) + '</span>' +
                '<div class="ticket-number">' + escapeHtml(ticket.label) + '</div>' +
                '<div class="cat-error-col">' +
                    '<div class="cat-error-text">Printing failed.<br>Please try again.</div>' +
                    '<button type="button" class="cat-retry-btn">Try Again</button>' +
                '</div>';
            block.querySelector('.cat-retry-btn').addEventListener('click', function () {
                renderEpresTicket(ticket);
            });
            scheduleEpresReset(ABANDONED_TICKET_TIMEOUT_MS);
        } finally {
            epresPrintLock = false;
        }
    }

    /*
     * ================= SHARED PRINT PIPELINE =================
     * attemptDirectPrintOrFallback() (below, unchanged) drives the
     * actual printer drivers; both the walk-in cards and the E-Press
     * panel call it directly and manage their own state transitions,
     * since their card layouts differ enough that a single shared
     * "morph this element" helper stopped paying for itself.
     */

    function showPrinterSetupModal() { clearIdleTimer(); setupModal.style.display = 'flex'; }
    function hidePrinterSetupModal() { setupModal.style.display = 'none'; }

    document.getElementById('printerSetupGoBtn').addEventListener('click', function () { hidePrinterSetupModal(); });
    document.getElementById('printerSetupCloseBtn').addEventListener('click', hidePrinterSetupModal);
    document.getElementById('printerSetupCloseActionBtn').addEventListener('click', hidePrinterSetupModal);
    setupModal.onclick = function (e) { if (e.target === setupModal) hidePrinterSetupModal(); };

    /*
     * Confirmation before cancelling an unprinted ticket. `onConfirmCancel`
     * runs only if the patient taps "Yes, Cancel"; tapping "No, Print
     * It" (or the backdrop) closes the dialog and prints instead of
     * just leaving them stuck on the same screen. No double-binding
     * risk since the Yes/No listeners are removed again as soon as one
     * of them fires.
     */
    var cancelConfirmModal = document.getElementById('cancelConfirmModal');
    function confirmCancelTicket(onConfirmCancel, onKeepAndPrint) {
        clearIdleTimer();
        cancelConfirmModal.style.display = 'flex';
        var yesBtn = document.getElementById('cancelConfirmYesBtn');
        var noBtn = document.getElementById('cancelConfirmNoBtn');
        // Saved once up front so the loading state (below) can restore
        // the exact original label -- this modal's markup is reused
        // across every cancel confirmation this kiosk session, so the
        // button must never be left showing "Cancelling…" the next
        // time it's opened.
        var yesBtnOriginalHtml = yesBtn.innerHTML;

        function cleanup() {
            cancelConfirmModal.style.display = 'none';
            yesBtn.removeEventListener('click', onYes);
            noBtn.removeEventListener('click', onNo);
            cancelConfirmModal.onclick = null;
            // Restore in case this exact modal element is reused for
            // the next ticket's cancel confirmation.
            yesBtn.disabled = false;
            noBtn.disabled = false;
            yesBtn.innerHTML = yesBtnOriginalHtml;
        }

        /*
         * "Yes, Cancel" now stays open with a loading state while the
         * actual database cancellation (onConfirmCancel, which awaits
         * cancelWalkinQueueEntry's network round trip) is in flight,
         * instead of closing the modal immediately and firing the
         * request in the background with no visible feedback. On a
         * public kiosk terminal a bare tap with no response invites a
         * second tap -- this makes the wait state explicit and blocks
         * further taps until the request actually resolves.
         *
         * MIN_LOADING_MS enforces a floor on how long that state stays
         * visible: a same-server request can resolve in under 100ms,
         * which reads as a flicker rather than a deliberate loading
         * state -- easy to miss entirely on a kiosk. Both the real
         * request and this minimum delay run concurrently
         * (Promise.all), so the wait never exceeds whichever one takes
         * longer; a slow request is never held up waiting on the timer.
         */
        var MIN_LOADING_MS = 900;
        async function onYes() {
            yesBtn.disabled = true;
            noBtn.disabled = true;
            yesBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Cancelling…';
            try {
                await Promise.all([
                    onConfirmCancel(),
                    new Promise(function (resolve) { setTimeout(resolve, MIN_LOADING_MS); }),
                ]);
            } finally {
                cleanup();
            }
        }
        function onNo() { cleanup(); if (onKeepAndPrint) onKeepAndPrint(); }
        yesBtn.addEventListener('click', onYes);
        noBtn.addEventListener('click', onNo);
        cancelConfirmModal.onclick = function (e) { if (e.target === cancelConfirmModal) onNo(); };
    }

    /*
     * ================= ACTUALLY CANCEL IN THE DATABASE =================
     * Tapping "Yes, Cancel" needs to do more than reset the on-screen
     * button — the queue row this ticket created (via walkin_create)
     * is still sitting there as 'Waiting' until something marks it
     * otherwise. Calls kiosk_api.php's `cancel_ticket` action (see
     * kioskCancelQueueEntry() in kiosk_logic.php), which flips the row
     * to 'Cancelled', flips medicine_status to 'Cancelled' if a
     * prescription is attached, and logs the change to
     * queue_status_log — the same audit trail queue.php's pharmacy-
     * side Cancel writes to, so the row shows up in queue.php's
     * Cancelled tab exactly like a staff-initiated cancellation would.
     */
    async function cancelWalkinQueueEntry(ticket) {
        var queueId = ticket.queue_id;
        if (!queueId) {
            console.warn('Cannot cancel in the database: this ticket has no queue_id.');
            return;
        }
        try {
            var result = await apiCall('cancel_ticket', { queue_id: queueId });
            if (result.status !== 'success') {
                console.warn('Cancel did not succeed server-side:', result.message || result);
            }
        } catch (e) {
            console.warn('Cancel request failed:', e);
        }
    }

    /* ---- Populates the hidden #printTicket DOM used by window.print()
       (browser/system fallback) and renders its on-page QR. ---- */
    function populatePrintTicketDom(ticket) {
        var t = buildTicketData(ticket);
        printTicketEl.className = 'has-content';
        printTicketEl.innerHTML =
            // ---- Header ----
            '<div class="ticket-header">' +
                '<p class="ticket-brand">Planet Drugstore</p>' +
                '<p class="ticket-tagline">Caring Beyond Dispensing</p>' +
                // (t.branchShort ? '<p class="ticket-branch-line">' + escapeHtml(t.branchShort) + '</p>' : '') +
                // (t.address ? '<p class="ticket-address-line">' + escapeHtml(t.address) + '</p>' : '') +
                // (t.website ? '<p class="ticket-website-line">' + escapeHtml(t.website) + '</p>' : '') +
            '</div>' +
            '<hr class="ticket-divider">' +
            // ---- Transaction information ----
            '<div class="ticket-txn">' +
                '<p class="ticket-txn-row"><span>Date</span><span>' + escapeHtml(t.date) + '</span></p>' +
                '<p class="ticket-txn-row"><span>Time</span><span>' + escapeHtml(t.time) + '</span></p>' +
                // (t.rxNo ? '<p class="ticket-txn-row"><span>Rx No.</span><span>' + escapeHtml(t.rxNo) + '</span></p>' : '') +
                // '<p class="ticket-txn-row"><span>Receipt No.</span><span>' + escapeHtml(t.receiptNo) + '</span></p>' +
            '</div>' +
            '<hr class="ticket-divider">' +
            // ---- Queue section ----
            '<div class="ticket-queue-section">' +
                '<p class="queue-patient-label">' + escapeHtml(t.category.toUpperCase()) + ' PATIENT</p>' +
                '<div class="queue-number">' + escapeHtml(t.label) + '</div>' +
            '</div>' +
            '<hr class="ticket-divider">' +
            // ---- QR ----
            '<div class="ticket-qr" id="ticketQr"><span class="ticket-qr-caption">Scan to track your queue</span></div>' +
            '<hr class="ticket-divider">' +
            // ---- Footer ----
            '<div class="ticket-footer">' +
                // '<p class="ticket-footer-thanks">Thank you for choosing Planet Drugstore</p>' +
                '<p class="confirm-wait">Please wait for your number<br>to be called.</p>' +
                (t.branchShort || t.address ? '<p class="ticket-footer-branch">' + [t.branchShort, t.address].filter(Boolean).map(escapeHtml).join(' &middot; ') + '</p>' : '') +
            '</div>';
        renderQrInto(document.getElementById('ticketQr'), t.publicUrl);
    }

    /*
     * ================= TICKET TEXT FORMATTING =================
     * Single source of truth for everything the printed slip shows,
     * so the browser-print DOM, the Epson ePOS-XML driver, and the
     * raw ESC/POS driver all render identical content instead of each
     * hand-rolling their own copy.
     */

    // Splits a combined "Aug 27, 2026 08:46 PM"-style string into its
    // date and time parts. Looks for a trailing "H:MM AM/PM" pattern
    // rather than assuming a fixed format, so it tolerates whatever
    // date format the backend happens to send.
    function splitDateTime(str) {
        if (!str) return { date: '', time: '' };
        var m = str.match(/^(.*?),?\s*([0-9]{1,2}:[0-9]{2}\s*(?:AM|PM|am|pm))\s*$/);
        if (m) return { date: m[1].trim(), time: m[2].trim() };
        return { date: str, time: '' };
    }

    // The backend doesn't hand back a distinct "receipt number" (only
    // the queue label itself, e.g. "R01"), so this derives a stable,
    // human-readable reference from data already on the ticket: the
    // queue label plus a compact timestamp. It's a print-time display
    // convenience, not a new backend-issued identifier.
    function buildReceiptNo(ticket, dt) {
        var parsed = new Date((dt.date + ' ' + dt.time).trim());
        var stamp;
        if (!isNaN(parsed.getTime())) {
            var pad = function (n) { return String(n).padStart(2, '0'); };
            stamp = '' + parsed.getFullYear() + pad(parsed.getMonth() + 1) + pad(parsed.getDate()) +
                    pad(parsed.getHours()) + pad(parsed.getMinutes());
        } else {
            stamp = (ticket.datetime || '').replace(/[^0-9A-Za-z]/g, '').slice(0, 12);
        }
        return (ticket.label || '') + '-' + stamp;
    }

    // ticket.branch_name is the full pharmacy_name (e.g. "Planet
    // Drugstore - Guadalupe Nuevo Cluster"). The header already prints
    // "Planet Drugstore" as its own brand line, so this strips that
    // redundant prefix for the branch line beneath it.
    function stripBrandPrefix(branchName) {
        return (branchName || '').replace(/^planet\s+drugstore\s*-?\s*/i, '').trim();
    }

    function buildTicketData(ticket) {
        var dt = splitDateTime(ticket.datetime);
        var branchShort = stripBrandPrefix(ticket.branch_name);
        // The branch's stored address sometimes just repeats the branch
        // name itself (seen in testing: address = "Guadalupe Nuevo
        // Cluster", same as the branch line above it) — printing both
        // back-to-back reads as a copy-paste error, so drop the address
        // line whenever it's the same text as the branch line.
        var address = PHARMACY_ADDRESS;
        if (address && address.trim().toLowerCase() === branchShort.trim().toLowerCase()) {
            address = '';
        }
        return {
            branch: ticket.branch_name,
            branchShort: branchShort,
            address: address,
            website: PHARMACY_WEBSITE,
            category: ticket.category,
            label: ticket.label,
            rxNo: ticket.prescription_id ? ticket.prescription_number : null,
            datetime: ticket.datetime,
            date: dt.date,
            time: dt.time,
            receiptNo: buildReceiptNo(ticket, dt),
            publicUrl: ticket.public_url || PUBLIC_MONITOR_URL,
        };
    }

    function eposXmlFor(t) {
        function esc(s) { return (s || '').toString().replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
        var lines = [
            { text: 'PLANET DRUGSTORE', w: '2' },
            { text: 'Caring Beyond Dispensing', w: '1' },
        ];
        if (t.branchShort) lines.push({ text: t.branchShort, w: '1' });
        if (t.address) lines.push({ text: t.address, w: '1' });
        if (t.website) lines.push({ text: t.website, w: '1' });
        lines.push({ text: '--------------------------------', w: '1' });
        lines.push({ text: 'Date: ' + t.date, w: '1' });
        lines.push({ text: 'Time: ' + t.time, w: '1' });
        if (t.rxNo) lines.push({ text: 'Rx No. ' + t.rxNo, w: '1' });
        lines.push({ text: 'Receipt No. ' + t.receiptNo, w: '1' });
        lines.push({ text: '--------------------------------', w: '1' });
        lines.push({ text: t.category.toUpperCase() + ' PATIENT', w: '1' });
        lines.push({ text: t.label, w: '3' });
        lines.push({ text: '--------------------------------', w: '1' });

        var body = lines.map(function (l) {
            return '<text align="center" width="' + l.w + '" height="' + l.w + '">' + esc(l.text) + '&#10;</text>';
        }).join('');

        // Epson ePOS-Print XML has a native <symbol> element for 2D
        // barcodes/QR -- no need to hand-roll module bytes here the
        // way the raw ESC/POS path below has to.
        if (t.publicUrl) {
            body += '<symbol type="qrcode_model_2" level="level_m" width="4" align="center">' + esc(t.publicUrl) + '</symbol>';
        }

        var footerLines = [
            '--------------------------------',
            'Thank you for choosing Planet Drugstore',
            'Please wait for your number',
            'to be called.',
        ];
        if (t.branchShort || t.address) {
            footerLines.push([t.branchShort, t.address].filter(Boolean).join(' - '));
        }
        body += footerLines.map(function (l) {
            return '<text align="center" width="1" height="1">' + esc(l) + '&#10;</text>';
        }).join('');

        return '<?xml version="1.0" encoding="utf-8"?>' +
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' +
            '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">' +
            body + '<cut type="feed"/></epos-print></s:Body></s:Envelope>';
    }

    async function trySilentPrint(t) {
        if (!printerConfig.ip || printerConfig.protocol !== 'epos_xml') {
            return { ok: false, reason: 'not_configured' };
        }
        if (window.location.protocol === 'https:') {
            console.warn('Silent print skipped: page is HTTPS, printer is HTTP (mixed content).');
            return { ok: false, reason: 'mixed_content' };
        }

        var port = printerConfig.port || 80;
        var controller = new AbortController();
        var timeoutId = setTimeout(function () { controller.abort(); }, 8000);

        try {
            var res = await fetch('http://' + printerConfig.ip + ':' + port +
                '/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000', {
                method: 'POST',
                headers: { 'Content-Type': 'text/xml; charset=utf-8', 'SOAPAction': '""' },
                body: eposXmlFor(t),
                signal: controller.signal,
            });
            clearTimeout(timeoutId);
            return { ok: res.ok, reason: res.ok ? 'printed' : 'http_' + res.status };
        } catch (e) {
            clearTimeout(timeoutId);
            return { ok: false, reason: e.name === 'AbortError' ? 'timeout' : 'unreachable' };
        }
    }

    // Native ESC/POS QR command sequence (GS ( k series, "model 2").
    function escposQrBytes(text) {
        var enc = new TextEncoder();
        var data = enc.encode(text);
        var chunks = [];
        function push(part) { chunks.push(typeof part === 'string' ? enc.encode(part) : new Uint8Array(part)); }

        push([0x1B, 0x61, 0x01]);
        push([0x1D, 0x28, 0x6B, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00]);
        push([0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x43, 0x04]);
        push([0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x45, 0x31]);

        var storeLen = data.length + 3;
        var pL = storeLen & 0xFF;
        var pH = (storeLen >> 8) & 0xFF;
        push([0x1D, 0x28, 0x6B, pL, pH, 0x31, 0x50, 0x30]);
        push(data);
        push([0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x51, 0x30]);

        var total = chunks.reduce(function (n, c) { return n + c.length; }, 0);
        var out = new Uint8Array(total);
        var offset = 0;
        chunks.forEach(function (c) { out.set(c, offset); offset += c.length; });
        return out;
    }

    function escposBytesFor(t) {
        var enc = new TextEncoder();
        var chunks = [];
        function push(part) { chunks.push(typeof part === 'string' ? enc.encode(part) : new Uint8Array(part)); }

        push([0x1B, 0x40]);
        push([0x1B, 0x61, 0x01]);

        // ---- Header ----
        push([0x1B, 0x21, 0x10]);
        push('PLANET DRUGSTORE\n');
        push([0x1B, 0x21, 0x00]);
        push('Caring Beyond Dispensing\n');
        if (t.branchShort) push(t.branchShort + '\n');
        if (t.address) push(t.address + '\n');
        if (t.website) push(t.website + '\n');
        push('--------------------------------\n');

        // ---- Transaction information ----
        push('Date: ' + t.date + '\n');
        push('Time: ' + t.time + '\n');
        if (t.rxNo) push('Rx No. ' + t.rxNo + '\n');
        push('Receipt No. ' + t.receiptNo + '\n');
        push('--------------------------------\n');

        // ---- Queue section ----
        push([0x1B, 0x21, 0x10]);
        push(t.category.toUpperCase() + ' PATIENT\n');
        push([0x1D, 0x21, 0x33]);
        push(t.label + '\n');
        push([0x1D, 0x21, 0x00]);
        push([0x1B, 0x21, 0x00]);
        push('--------------------------------\n');

        // ---- QR ----
        if (t.publicUrl) {
            push(escposQrBytes(t.publicUrl));
            push('\nScan to track your queue\n');
        }
        push('--------------------------------\n');

        // ---- Footer ----
        push('Thank you for choosing Planet Drugstore\n');
        push([0x1B, 0x21, 0x08]);
        push('Please wait for your number\nto be called.\n');
        push([0x1B, 0x21, 0x00]);
        if (t.branchShort || t.address) {
            push([t.branchShort, t.address].filter(Boolean).join(' - ') + '\n');
        }
        push('\n\n\n');
        push([0x1D, 0x56, 0x42, 0x00]);

        var total = chunks.reduce(function (n, c) { return n + c.length; }, 0);
        var out = new Uint8Array(total);
        var offset = 0;
        chunks.forEach(function (c) { out.set(c, offset); offset += c.length; });
        return out;
    }

    async function tryUsbPrint(t) {
        if (!('usb' in navigator)) return { ok: false, reason: 'no_webusb' };

        var device = null;
        var claimedInterfaceNumber = null;

        try {
            var devices = await navigator.usb.getDevices();
            device = devices[0];
            if (!device) {
                device = await navigator.usb.requestDevice({ filters: [{ classCode: 7 }] });
            }
            await device.open();
            if (!device.configuration) await device.selectConfiguration(1);

            var iface = null;
            var matchedAlt = null;
            device.configuration.interfaces.forEach(function (candidate) {
                if (matchedAlt) return;
                var alt = candidate.alternates.find(function (a) { return a.interfaceClass === 7; });
                if (alt) { iface = candidate; matchedAlt = alt; }
            });
            if (!iface) {
                iface = device.configuration.interfaces[0];
                matchedAlt = iface.alternates[0];
            }

            await device.claimInterface(iface.interfaceNumber);
            claimedInterfaceNumber = iface.interfaceNumber;

            if (matchedAlt.alternateSetting !== 0) {
                await device.selectAlternateInterface(iface.interfaceNumber, matchedAlt.alternateSetting);
            }

            var outEndpoint = matchedAlt.endpoints.find(function (e) { return e.direction === 'out'; });
            if (!outEndpoint) throw { name: 'NoOutEndpoint' };

            await device.transferOut(outEndpoint.endpointNumber, escposBytesFor(t));
            return { ok: true, reason: 'usb_printed' };
        } catch (e) {
            return { ok: false, reason: 'usb_' + (e && e.name ? e.name : 'error') };
        } finally {
            if (device) {
                try {
                    if (claimedInterfaceNumber !== null) await device.releaseInterface(claimedInterfaceNumber);
                } catch (releaseErr) { /* already released or device gone — ignore */ }
                try {
                    await device.close();
                } catch (closeErr) { /* already closed or device gone — ignore */ }
            }
        }
    }

    async function trySerialPrint(t) {
        if (!('serial' in navigator)) return { ok: false, reason: 'no_webserial' };

        var port = null;
        try {
            var ports = await navigator.serial.getPorts();
            port = ports[0];
            if (!port) {
                var filters = [];
                if (printerConfig.usbVendorId) {
                    var f = { usbVendorId: Number(printerConfig.usbVendorId) };
                    if (printerConfig.usbProductId) f.usbProductId = Number(printerConfig.usbProductId);
                    filters.push(f);
                }
                port = await navigator.serial.requestPort(filters.length ? { filters: filters } : {});
            }

            await port.open({ baudRate: Number(printerConfig.serialBaudRate) || 9600 });

            var writer = port.writable.getWriter();
            try {
                await writer.write(escposBytesFor(t));
            } finally {
                writer.releaseLock();
            }
            return { ok: true, reason: 'serial_printed' };
        } catch (e) {
            return { ok: false, reason: 'serial_' + (e && e.name ? e.name : 'error') };
        } finally {
            if (port) {
                try { await port.close(); } catch (closeErr) { /* already closed/gone — ignore */ }
            }
        }
    }

    function bytesToBase64(bytes) {
        var binary = '';
        for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return window.btoa(binary);
    }

    async function tryAgentPrint(t) {
        if (!printerConfig.agentUrl) return { ok: false, reason: 'not_configured' };

        var controller = new AbortController();
        var timeoutId = setTimeout(function () { controller.abort(); }, 8000);

        try {
            var headers = { 'Content-Type': 'application/json' };
            if (printerConfig.agentApiKey) headers['Authorization'] = 'Bearer ' + printerConfig.agentApiKey;

            var res = await fetch(printerConfig.agentUrl, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({
                    format: 'escpos',
                    dataBase64: bytesToBase64(escposBytesFor(t)),
                    ticket: t,
                }),
                signal: controller.signal,
            });
            clearTimeout(timeoutId);
            return { ok: res.ok, reason: res.ok ? 'agent_printed' : 'agent_http_' + res.status };
        } catch (e) {
            clearTimeout(timeoutId);
            return { ok: false, reason: e.name === 'AbortError' ? 'agent_timeout' : 'agent_unreachable' };
        }
    }

    // ---- Driver dispatch table. Adding a new connection type later
    // means: (1) a print function like the ones above, (2) one line
    // here, (3) a config section in printer_settings.php.
    var PRINT_DRIVERS = {
        wireless: trySilentPrint,
        wired: tryUsbPrint,
        serial: trySerialPrint,
        agent: tryAgentPrint,
        // 'system' is intentionally absent: it means "skip every
        // direct-print attempt and go straight to window.print()",
        // which lets the OS's own print dialog target ANY installed
        // printer -- the one universal fallback every browser supports.
    };

    async function attemptDirectPrint(t) {
        var driver = PRINT_DRIVERS[printerConfig.type];
        if (!driver) return { ok: false, reason: 'not_configured' };
        return await driver(t);
    }

    async function attemptDirectPrintOrFallback(ticket) {
        var t = buildTicketData(ticket);
        var result = await attemptDirectPrint(t);
        console.log('Kiosk print:', result.ok ? 'silent print sent' : 'falling back to window.print() (' + result.reason + ')');
        if (!result.ok) window.print();
        return result;
    }

    async function apiCall(action, params) {
        var body = new URLSearchParams(Object.assign({ action: action, branch: BRANCH_SLUG }, params));
        try {
            var res = await fetch('kiosk_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            return await res.json();
        } catch (e) {
            return { status: 'error', message: 'Connection problem — please try again.' };
        }
    }

    /* ---- First-load state, driven entirely by PHP above. ---- */
    <?php if ($initialSlipPayload): ?>
        renderMainOptions();
        <?php if (!empty($initialSlipPayload['prescription_id'])): ?>
            renderEpresTicket(<?= json_encode($initialSlipPayload) ?>);
        <?php else: ?>
            (function () {
                var ticket = <?= json_encode($initialSlipPayload) ?>;
                var btn = document.querySelector('#walkinCatButtons .cat-btn[data-category="' + ticket.category + '"]');
                // showTicketInButton() already locks the sibling itself,
                // so nothing further is needed here.
                if (btn) showTicketInButton(btn, ticket);
            })();
        <?php endif; ?>
    <?php elseif ($initialCategoryStep): ?>
        renderMainOptions();
        renderEpresCategoryStep(<?= json_encode($initialCategoryStep['rxNo']) ?>, <?= json_encode($initialCategoryStep['patientName']) ?>);
    <?php elseif ($mode === 'walkin'): ?>
        renderMainOptions();
        <?php if ($initialError): ?> showWalkinAlert(<?= json_encode($initialError) ?>); <?php endif; ?>
    <?php elseif ($mode === 'epres'): ?>
        renderMainOptions();
        renderEpresRxForm(<?= json_encode($initialError) ?>, <?= json_encode($_POST['rx_no'] ?? '') ?>);
    <?php else: ?>
        renderIdle();
    <?php endif; ?>
})();
</script>
</body>
</html>