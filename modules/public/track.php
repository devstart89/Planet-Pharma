<?php
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

$branchSlug = $_GET['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    renderBranchPicker($conn, 'track.php');
}
$branchSlugJs = json_encode($pharmacy['slug']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Track your number — <?= htmlspecialchars($pharmacy['pharmacy_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }

    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        font-family: 'Segoe UI', Arial, sans-serif;
        color: #f8fafc;
        background-color: #0b1a2b;
        background-image: linear-gradient(160deg, #0b1a2b 0%, #0e2338 55%, #0b1a2b 100%);
        transition: background-color .4s ease;
    }
    body.called-state {
        background-color: #7f1d1d;
        background-image: linear-gradient(160deg, #7f1d1d 0%, #991b1b 55%, #7f1d1d 100%);
    }

    .card {
        width: 100%;
        max-width: 420px;
        background: rgba(0, 0, 0, 0.4);
        border-radius: 1.25rem;
        padding: 2rem;
        text-align: center;
        border: 1px solid rgba(255, 255, 255, 0.08);
    }
    .branch-name { color: #cbd5e1; font-size: .95rem; margin-bottom: 1.5rem; }

    /* ---- Setup form (before tracking starts) ---- */
    label {
        display: block;
        text-align: left;
        font-size: .8rem;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #94a3b8;
        margin: 1rem 0 .35rem;
    }
    select, input[type=text] {
        width: 100%;
        padding: .75rem;
        border-radius: .6rem;
        border: 1px solid rgba(255,255,255,0.15);
        background: rgba(255,255,255,0.06);
        color: #f8fafc;
        font-size: 1.1rem;
    }
    button {
        margin-top: 1.5rem;
        width: 100%;
        padding: .9rem;
        border: none;
        border-radius: .6rem;
        background: #3b82f6;
        color: white;
        font-size: 1.05rem;
        font-weight: 700;
        cursor: pointer;
    }
    button:active { background: #2563eb; }
    button.secondary { background: #475569; margin-top: .75rem; }

    /* ---- Tracking state ---- */
    .waiting-panel .status-label {
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #94a3b8;
        font-size: .85rem;
    }
    .waiting-panel .my-number {
        font-size: 3.2rem;
        font-weight: 800;
        color: #60a5fa;
        margin: .5rem 0 1rem;
    }
    .waiting-panel .now-serving-row {
        color: #cbd5e1;
        font-size: 1rem;
        margin-bottom: 1.5rem;
    }
    .waiting-panel .now-serving-row strong { color: #f8fafc; }

    .pulse-dot {
        width: 10px; height: 10px;
        border-radius: 50%;
        background: #22c55e;
        display: inline-block;
        margin-right: .4rem;
        animation: dotPulse 1.6s ease-in-out infinite;
    }
    @keyframes dotPulse {
        0%, 100% { opacity: 1; }
        50% { opacity: .3; }
    }

    /* ---- Called state ---- */
    .called-banner {
        display: none;
        font-size: 1.8rem;
        font-weight: 800;
        margin-bottom: .5rem;
        animation: shake 0.6s ease-in-out infinite;
    }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-4px); }
        75% { transform: translateX(4px); }
    }
    .called-state .called-banner { display: block; }
    .called-state .waiting-only { display: none; }

    .hidden { display: none; }
    .fine-print {
        margin-top: 1.5rem;
        font-size: .72rem;
        color: #64748b;
        line-height: 1.4;
    }
</style>
</head>
<body id="pageBody">
<div class="card">
    <div class="branch-name"><?= htmlspecialchars($pharmacy['pharmacy_name']) ?></div>

    <!-- ===== Step 1: setup form ===== -->
    <div id="setupPanel">
        <label for="categorySelect">Your ticket type</label>
        <select id="categorySelect">
            <option value="Regular">Regular</option>
            <option value="Priority">Priority</option>
        </select>

        <label for="numberInput">Your ticket number</label>
        <input type="text" id="numberInput" placeholder="e.g. R-042" autocomplete="off">

        <button id="startTrackingBtn" type="button">Start tracking</button>
    </div>

    <!-- ===== Step 2: tracking / called states ===== -->
    <div id="trackingPanel" class="hidden">
        <div class="called-banner" id="calledBanner">🔔 It's your turn!</div>

        <div class="waiting-panel">
            <div class="status-label waiting-only"><span class="pulse-dot"></span>Watching for your number</div>
            <div class="my-number" id="myNumberDisplay">—</div>
            <div class="now-serving-row waiting-only">
                Now serving: <strong id="nowServingLabel">—</strong>
            </div>
        </div>

        <button id="acknowledgeBtn" type="button" class="hidden">OK, I'm coming</button>
        <button id="changeNumberBtn" type="button" class="secondary">Not my number / change</button>

        <div class="fine-print">
            Keep this tab open on your phone. Vibration works on most Android
            browsers; iPhone browsers don't support phone vibration, so
            you'll get a loud sound and flashing screen instead.
        </div>
    </div>
</div>

<script>
const BRANCH_SLUG = <?= $branchSlugJs ?>;
const STORAGE_KEY = 'pharmacyTrack_' + BRANCH_SLUG;

// If the kiosk's QR code / "Track on your phone" link sent the category
// and number directly (?category=Regular&number=R-04), skip the manual
// entry form entirely and start tracking immediately.
const PREFILL_CATEGORY = <?= json_encode($_GET['category'] ?? null) ?>;
const PREFILL_NUMBER = <?= json_encode($_GET['number'] ?? null) ?>;

const setupPanel = document.getElementById('setupPanel');
const trackingPanel = document.getElementById('trackingPanel');
const categorySelect = document.getElementById('categorySelect');
const numberInput = document.getElementById('numberInput');
const startBtn = document.getElementById('startTrackingBtn');
const acknowledgeBtn = document.getElementById('acknowledgeBtn');
const changeNumberBtn = document.getElementById('changeNumberBtn');
const myNumberDisplay = document.getElementById('myNumberDisplay');
const nowServingLabel = document.getElementById('nowServingLabel');
const body = document.getElementById('pageBody');

let myCategory = null;
let myNumber = null;
let isCalled = false;
let snoozeTimer = null;

function startTracking(category, number) {
    myCategory = category;
    myNumber = number.trim();
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ category: myCategory, number: myNumber }));

    setupPanel.classList.add('hidden');
    trackingPanel.classList.remove('hidden');
    myNumberDisplay.textContent = myNumber;

    connectStream();
}

function resetToSetup() {
    clearCalledState();
    sessionStorage.removeItem(STORAGE_KEY);
    trackingPanel.classList.add('hidden');
    setupPanel.classList.remove('hidden');
}

startBtn.addEventListener('click', () => {
    const num = numberInput.value.trim();
    if (!num) {
        numberInput.focus();
        return;
    }
    startTracking(categorySelect.value, num);
});
changeNumberBtn.addEventListener('click', resetToSetup);

// A fresh kiosk link (category + number in the URL) always wins over any
// older saved session — it means the patient just got a new ticket.
if (PREFILL_CATEGORY && PREFILL_NUMBER) {
    categorySelect.value = PREFILL_CATEGORY;
    numberInput.value = PREFILL_NUMBER;
    startTracking(PREFILL_CATEGORY, PREFILL_NUMBER);
} else {
    // Otherwise, resume a previous session if the page was reloaded.
    const saved = sessionStorage.getItem(STORAGE_KEY);
    if (saved) {
        try {
            const parsed = JSON.parse(saved);
            if (parsed.category && parsed.number) {
                categorySelect.value = parsed.category;
                numberInput.value = parsed.number;
                startTracking(parsed.category, parsed.number);
            }
        } catch (err) { /* ignore corrupt storage */ }
    }
}

/* ================= ALERTING ================= */

function playAlertSound() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const now = ctx.currentTime;
        [880, 660, 880, 660].forEach((freq, i) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'square';
            osc.frequency.value = freq;
            osc.connect(gain);
            gain.connect(ctx.destination);
            const start = now + i * 0.3;
            gain.gain.setValueAtTime(0, start);
            gain.gain.linearRampToValueAtTime(0.3, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, start + 0.28);
            osc.start(start);
            osc.stop(start + 0.3);
        });
    } catch (err) { /* audio unavailable — visual flash still shows */ }
}

function vibratePhone() {
    // No-op (silently) on browsers/OSes that don't support it — e.g. iOS Safari.
    if ('vibrate' in navigator) {
        navigator.vibrate([400, 150, 400, 150, 400]);
    }
}

function triggerCalledAlert() {
    isCalled = true;
    body.classList.add('called-state');
    trackingPanel.classList.add('called-state');
    acknowledgeBtn.classList.remove('hidden');

    vibratePhone();
    playAlertSound();

    // "Snooze" — if they haven't tapped "OK, I'm coming" within one
    // minute, alert again, repeatedly, until acknowledged.
    clearTimeout(snoozeTimer);
    snoozeTimer = setTimeout(function reAlert() {
        if (!isCalled) return;
        vibratePhone();
        playAlertSound();
        snoozeTimer = setTimeout(reAlert, 60000);
    }, 60000);
}

function clearCalledState() {
    isCalled = false;
    clearTimeout(snoozeTimer);
    body.classList.remove('called-state');
    trackingPanel.classList.remove('called-state');
    acknowledgeBtn.classList.add('hidden');
}

acknowledgeBtn.addEventListener('click', () => {
    clearCalledState();
    resetToSetup();
});

/* ================= SSE CONNECTION (reuses the same public stream) ================= */

function connectStream() {
    const stream = new EventSource('../../api/queue_stream_public.php?branch=' + encodeURIComponent(BRANCH_SLUG));

    stream.onmessage = (e) => {
        let data;
        try {
            data = JSON.parse(e.data);
        } catch (err) {
            return;
        }
        if (data.status !== 'success' || !myCategory) return;

        const c = data.categories[myCategory];
        const currentLabel = c && c.now_serving ? c.now_serving.label : null;
        nowServingLabel.textContent = currentLabel ?? '—';

        if (!isCalled && currentLabel === myNumber) {
            triggerCalledAlert();
        }
    };
}
</script>
</body>
</html>