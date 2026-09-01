<?php
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

$branchSlug = $_GET['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    renderBranchPicker($conn, 'queue_display.php');
}
$branchSlugJs = json_encode($pharmacy['slug']);

/*
 * ================= QR CODE: PUBLIC QUEUING SITE =================
 * This page IS the Public Queuing Site (the "Queuing Screen"), so the
 * QR shown here just needs to point at itself — an absolute URL a
 * patient's phone camera can resolve, built from the current request
 * the same way kiosk.php derives its own public-monitor link. Scanning
 * it lets a patient watch the queue from their phone instead of
 * standing in front of the TV.
 */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? '';
$selfPath = $_SERVER['REQUEST_URI'] ?? ('queue_display.php?branch=' . urlencode($branchSlug));
$publicQueuingUrl = $scheme . '://' . $host . $selfPath;

/*
 * The brand logo (mark + wordmark + tagline) is a single, very large
 * inline SVG. Kept in its own include so this file stays readable and
 * so the exact same markup can be reused from kiosk.php without
 * duplicating thousands of characters of path data.
 */
$logoSvgPath = 'brand_logo.svg';
$logoSvg = is_file($logoSvgPath) ? file_get_contents($logoSvgPath) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pharmacy['pharmacy_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!--
  Safety-net full reload every 30 min. The SSE stream already recycles
  itself server-side and EventSource auto-reconnects on its own, but a
  screen left running for days/weeks benefits from an occasional hard
  reload too — picks up any deployed code changes, clears any slow
  memory growth in the tab, and gives a known-good fallback if a
  browser's own EventSource implementation ever gets stuck.
-->
<meta http-equiv="refresh" content="1800">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<!--
  Lightweight, dependency-free QR generator — same library kiosk.php
  uses for the printed slip, so both QR placements the requirement
  calls for (printed slip + this screen) stay visually/technically
  consistent.
-->
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
<style>
    :root {
        --brand-navy: #1d2939;
        --brand-gray: #667085;
        --brand-red-1: #ff6b63;
        --brand-red-2: #ef4444;
        --brand-blue-bg: #60A5FA33;
        --brand-blue: #175cd3;
        --brand-pink-bg: #FF636333;
    }
    * { box-sizing: border-box; }

    body {
        margin: 0;
        min-height: 100vh;
        font-family: 'Segoe UI', Arial, sans-serif;
        color: var(--brand-navy);
        position: relative;
        overflow-x: hidden;

        /*
         * ================= BACKGROUND PHOTO =================
         * Use the LINES-ONLY variant here (pill erased with a soft
         * feathered white blend) — not the original hero photo. The
         * original has its own baked-in pill, which combined with the
         * .pill-hero graphic on the Regular card below would render
         * TWO pills on screen. Keep the pill as a single, deliberate
         * accent on the Regular card only.
         */
        background-color: #ffffff;
        background-image: url('image.png');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        background-attachment: fixed;
    }

    .qd-shell { position: relative; z-index: 1; min-height: 100vh; display: flex; flex-direction: column; }

    .qd-header {
        display: flex;
        align-items: center;
        padding: clamp(1.25rem, 3vw, 2rem) clamp(1.5rem, 4vw, 3rem) 0;
    }
    .qd-header svg { height: clamp(32px, 4vw, 40px); width: auto; }

    .qd-board {
        flex: 1;
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: clamp(1.25rem, 3vw, 2rem);
        align-items: start;
        padding: clamp(2rem, 4.5vw, 3rem) clamp(1.5rem, 4vw, 3rem) 0;
        max-width: 1300px;
        margin: 0 auto;
        width: 100%;
    }
    @media (max-width: 900px) {
        .qd-board { grid-template-columns: 1fr; }
    }

    .serving-row {
        margin-top:4rem;
        display: grid;
        grid-template-columns: 1.5fr 1.5fr;
        gap: clamp(1rem, 2.5vw, 1.75rem);
    }
    @media (max-width: 560px) {
        .serving-row { grid-template-columns: 1fr; }
    }

    .serving-card {
        border-radius: 1.75rem;
        padding: clamp(5.75rem, 4vw, 2.25rem) clamp(1.25rem, 3vw, 1.75rem);
        min-height: clamp(190px, 22vw, 230px);
        text-align: center;
        position: relative;
        overflow: visible;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        box-shadow: 0 20px 44px rgba(29, 41, 57, 0.1);
    }
    .serving-card.Regular  { background: var(--brand-blue-bg); }
    .serving-card.Priority { background: var(--brand-pink-bg); }

    .serving-content { position: relative; z-index: 2; }

    /*
     * The real photographic pill cutout (transparent PNG), not a CSS
     * gradient approximation — sits tucked mostly inside the Regular
     * card with a slight bleed past the bottom edge, matching the
     * reference design. Only the Regular card gets this; Priority
     * stays plain.
     */
    .pill-hero {
        position: absolute;
        left: 50%;
        bottom: -8px;
        width: 76%;
        max-width: 210px;
        transform: translateX(-52%) rotate(-10deg);
        z-index: 1;
        pointer-events: none;
        filter: drop-shadow(0 8px 10px rgba(0,0,0,.15));
    }

    .serving-sub {
        position: relative;
        font-weight: 700;
        font-size: clamp(.68rem, 1.6vw, .8rem);
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #7c8a9e;
        margin-bottom: .5rem;
    }
    .serving-number {
        position: relative;
        font-weight: 800;
        font-size: clamp(7.2rem, 6vw, 4rem);
        letter-spacing: .02em;
        line-height: 1;
        color: var(--brand-navy);
        margin-bottom: .35rem;
    }
    .serving-label {
        position: relative;
        font-weight: 800;
        font-size: clamp(.85rem, 2vw, 1.05rem);
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--brand-navy);
    }

    .preparing-col {
        margin-top:4rem;
        display: flex;
        flex-direction: column;
        gap: .9rem;
        padding-top: .25rem;
    }
    .preparing-title {
        font-weight: 700;
        text-align: right;
        font-size: clamp(.72rem, 1.7vw, .8rem);
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #7c8a9e;
        margin: 0 0 1.1rem;
    }
    .preparing-card {
        border-radius: 1.1rem;
        padding: 1.5rem 1.3rem;
        display: flex;
        align-items: baseline;
        gap: 2.7rem;
        max-width: 350px;
        box-shadow: 0 10px 26px rgba(29,41,57,.06);
    }
    .preparing-card.Regular  { background: var(--brand-blue-bg); }
    .preparing-card.Priority { background: var(--brand-pink-bg); }
    .preparing-number {
        font-weight: 800;
        font-size: clamp(2.2rem, 3vw, 2.5rem);
        color: var(--brand-navy);
        line-height: 1;
    }
    .preparing-category {
        font-size: 1.52rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #7c8a9e;
    }

    .updated-at {
        text-align: center;
        color: #b0b7c3;
        font-size: .72rem;
        padding: 1rem 0;
    }

    /*
     * ================= BRANCH / LOCATION LINE =================
     * Bottom-right, matching the reference design.
     */
    .qd-footer {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: .35rem;
        padding: 0 clamp(1.5rem, 4vw, 3rem) clamp(1rem, 3vw, 1.5rem);
        margin-top: auto;
        font-size: clamp(.62rem, 1.5vw, .7rem);
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: var(--brand-red-2);
    }
    .qd-footer i { font-size: .85em; }

    /*
     * ================= QR CODE: PUBLIC QUEUING SITE =================
     * Persistent corner panel, always on screen (this page never
     * navigates away), so patients can scan it any time to pull the
     * same live board up on their own phone. Placed bottom-LEFT so it
     * doesn't collide with the branch/location line at bottom-right.
     */
    .qr-corner {
        position: fixed;
        left: 1.5rem;
        bottom: 1.5rem;
        background: #ffffff;
        border-radius: 1rem;
        padding: 1rem 1rem .75rem;
        box-shadow: 0 12px 32px rgba(29,41,57,.18);
        border: 1px solid #eef1f4;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .35rem;
        z-index: 2;
    }
    .qr-corner svg { width: 110px; height: 110px; display: block; }
    .qr-corner .qr-caption {
        color: var(--brand-navy);
        font-size: .64rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
        text-align: center;
        max-width: 130px;
    }

    @media (max-width: 700px) {
        .qr-corner { position: static; margin: 1.5rem auto 0; }
    }
</style>
</head>
<body>

<div class="qd-shell">
    <div class="qd-header"><?= $logoSvg ?></div>

    <div class="qd-board">
        <section class="serving-row">
            <div class="serving-card Regular">
                <!--<img class="pill-hero" src="pill-cutout.png" alt="">-->
                <div class="serving-content">
                    <div class="serving-sub">Now Serving</div>
                    <div class="serving-number" id="regularNumber">—</div>
                    <div class="serving-label">Regular</div>
                </div>
            </div>
            <div class="serving-card Priority">
                <div class="serving-content">
                    <div class="serving-sub">Now Serving</div>
                    <div class="serving-number" id="priorityNumber">—</div>
                    <div class="serving-label">Priority</div>
                </div>
            </div>
        </section>

        <section class="preparing-col">
            <h3 class="preparing-title">Now Preparing</h3>
            <div class="preparing-card Priority">
                <div class="preparing-number" id="priorityPreparingNumber">—</div>
                <div class="preparing-category">Priority</div>
            </div>
            <div class="preparing-card Regular">
                <div class="preparing-number" id="regularPreparingNumber">—</div>
                <div class="preparing-category">Regular</div>
            </div>
        </section>
    </div>

    <div class="updated-at" id="updatedAt">Connecting…</div>

    <div class="qd-footer">
        <i class="bi bi-geo-alt-fill"></i>
        Planet Drugstore &middot; <?= htmlspecialchars(strtoupper($pharmacy['pharmacy_name'])) ?>
    </div>
</div>

<div class="qr-corner" id="qrCorner">
    <div id="qrCode"></div>
    <div class="qr-caption">Scan to view on your phone</div>
</div>

<script>
// Fixed per-page, set from the resolved ?branch= so this monitor only
// ever streams its own branch's queue.
const BRANCH_SLUG = <?= $branchSlugJs ?>;
const PUBLIC_QUEUING_URL = <?= json_encode($publicQueuingUrl) ?>;

// Renders once on load — this screen's own URL never changes while it's
// running, so there's nothing to re-render on queue updates.
(function renderQrCorner() {
    var container = document.getElementById('qrCode');
    if (!container || typeof qrcode === 'undefined') return;
    try {
        var qr = qrcode(0, 'M'); // type 0 = auto-sized, ECC level M
        qr.addData(PUBLIC_QUEUING_URL);
        qr.make();
        container.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
    } catch (e) {
        console.warn('QR generation failed:', e);
    }
})();

function renderQueueData(data) {
    if (data.status !== 'success') {
        document.getElementById('updatedAt').textContent = 'Error: ' + (data.message || 'unknown');
        return;
    }

    for (const cat of ['Regular', 'Priority']) {
        const c = data.categories[cat];
        const numEl = document.getElementById(cat.toLowerCase() + 'Number');
        const preparingEl = document.getElementById(cat.toLowerCase() + 'PreparingNumber');

        numEl.textContent = c.now_serving ? c.now_serving.label : '—';

        const nextUp = c.waiting && c.waiting.length > 0 ? c.waiting[0] : null;
        preparingEl.textContent = nextUp ? nextUp.label : '—';
    }

    document.getElementById('updatedAt').textContent =
        'Updated ' + new Date(data.updated_at).toLocaleTimeString();
}

/*
 * ================= SSE CONNECTION =================
 * One held-open connection for the lifetime of this screen. The server
 * only sends a message when the queue actually changes, so between
 * patients this monitor does nothing at all — no request, no DB hit.
 * EventSource reconnects automatically on drops (network blip, the
 * stream's own periodic server-side recycle, etc.) — no manual retry
 * loop needed.
 */
let streamConnected = false;

function connectQueueStream() {
    const stream = new EventSource('../../api/queue_stream_public.php?branch=' + encodeURIComponent(BRANCH_SLUG));

    stream.onopen = () => {
        streamConnected = true;
    };

    stream.onmessage = (e) => {
        streamConnected = true;
        let payload;
        try {
            payload = JSON.parse(e.data);
        } catch (err) {
            return; // malformed message — ignore, next one will arrive
        }
        renderQueueData(payload);
    };

    stream.onerror = () => {
        streamConnected = false;
        document.getElementById('updatedAt').textContent = 'Connection lost — retrying…';
        // Native EventSource auto-reconnect handles retrying on its own.
    };

    return stream;
}

let eventSource = connectQueueStream();

// If the browser throttled/suspended the connection while the tab or
// screen power-saving kicked in, force a clean reconnect once it's
// active again rather than waiting on the browser's own retry timing.
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && !streamConnected) {
        eventSource.close();
        eventSource = connectQueueStream();
    }
});
</script>
</body>
</html>