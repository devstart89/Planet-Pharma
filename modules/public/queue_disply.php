<?php
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

$branchSlug = $_GET['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    renderBranchPicker($conn, 'queue_display.php');
}
$branchSlugJs = json_encode($pharmacy['slug']);
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
<style>
    * { box-sizing: border-box; }

    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        font-family: 'Segoe UI', Arial, sans-serif;
        color: #f8fafc;
        position: relative;
        overflow: hidden;

        background-color: #0b1a2b;
        background-image:
            radial-gradient(circle at 15% 20%, rgba(59,130,246,0.16), transparent 45%),
            radial-gradient(circle at 85% 80%, rgba(239,68,68,0.14), transparent 45%),
            linear-gradient(160deg, #0b1a2b 0%, #0e2338 55%, #0b1a2b 100%);
    }

    /* Faint repeating medical-cross pattern, purely decorative, sits
       behind everything else via a low z-index. */
    body::before {
        content: "";
        position: fixed;
        inset: 0;
        z-index: 0;
        opacity: 0.05;
        /*background-image: url("../../assets/img/health/showcase-2.webp");*/
        /*background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Cpath d='M36 20h8v16h16v8H44v16h-8V44H20v-8h16z' fill='%23ffffff'/%3E%3C/svg%3E");*/
        /*background-size: 80px 80px;*/
    }

    h1, .branch-name, .board, .updated-at {
        position: relative;
        z-index: 1;
    }

    h1 {
        font-size: 1.5rem;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #94a3b8;
        margin-bottom: .25rem;
    }
    .branch-name {
        color: #cbd5e1;
        font-size: .95rem;
        margin-bottom: 2rem;
        text-align: center;
    }
    .board {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        width: 100%;
        max-width: 1100px;
    }
    .panel {
        background: rgba(0, 0, 0, 0.55);
        backdrop-filter: blur(6px);
        border-radius: 1.25rem;
        padding: 2rem;
        text-align: center;
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-top: 6px solid #334155;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
    }
    .panel.Regular  { border-top-color: #3b82f6; }
    .panel.Priority { border-top-color: #ef4444; }
    .panel-label {
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: #cbd5e1;
        margin-bottom: .25rem;
    }
    .now-serving-label {
        font-size: .95rem;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .08em;
        margin-bottom: .5rem;
    }
    .now-serving-number {
        font-size: 6rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 1rem;
        text-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
    }
    .Regular .now-serving-number  { color: #60a5fa; }
    .Priority .now-serving-number { color: #f87171; }

    /* Pulse + glow animation, played once when a category's "now
       serving" number changes, to draw the eye of anyone waiting. */
    @keyframes numberPulse {
        0%   { transform: scale(1);    text-shadow: 0 4px 24px rgba(0,0,0,0.4); }
        25%  { transform: scale(1.15); text-shadow: 0 0 40px currentColor; }
        50%  { transform: scale(1);    text-shadow: 0 4px 24px rgba(0,0,0,0.4); }
        75%  { transform: scale(1.08); text-shadow: 0 0 25px currentColor; }
        100% { transform: scale(1);    text-shadow: 0 4px 24px rgba(0,0,0,0.4); }
    }
    .now-serving-number.just-called {
        animation: numberPulse 1.8s ease-in-out;
    }

    .waiting-count {
        color: #94a3b8;
        font-size: .9rem;
    }
    .waiting-list {
        margin-top: 1rem;
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
        justify-content: center;
    }
    .waiting-chip {
        background: rgba(51, 65, 85, 0.85);
        color: #e2e8f0;
        padding: .25rem .6rem;
        border-radius: .4rem;
        font-size: .85rem;
        font-weight: 600;
    }
    .updated-at {
        margin-top: 2rem;
        color: #64748b;
        font-size: .8rem;
    }
    .placeholder { color: #475569; font-size: 3rem; font-weight: 800; }

    .track-qr {
        position: fixed;
        bottom: 1.25rem;
        right: 1.25rem;
        z-index: 2;
        background: rgba(255, 255, 255, 0.96);
        border-radius: .9rem;
        padding: .75rem;
        display: flex;
        align-items: center;
        gap: .6rem;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
    }
    .track-qr img { display: block; border-radius: .4rem; }
    .track-qr-label {
        color: #0f172a;
        font-size: .72rem;
        font-weight: 700;
        line-height: 1.25;
        text-transform: uppercase;
        letter-spacing: .02em;
        max-width: 6rem;
    }
</style>
</head>
<body>
<h1>Pharmacy Queue</h1>
<div class="branch-name"><?= htmlspecialchars($pharmacy['pharmacy_name']) ?></div>
<div class="board">
    <div class="panel Regular">
        <div class="panel-label">Regular</div>
        <div class="now-serving-label">Now Serving</div>
        <div class="now-serving-number" id="regularNumber">—</div>
        <div class="waiting-count" id="regularWaitingCount"></div>
        <div class="waiting-list" id="regularWaitingList"></div>
    </div>
    <div class="panel Priority">
        <div class="panel-label">Priority</div>
        <div class="now-serving-label">Now Serving</div>
        <div class="now-serving-number" id="priorityNumber">—</div>
        <div class="waiting-count" id="priorityWaitingCount"></div>
        <div class="waiting-list" id="priorityWaitingList"></div>
    </div>
</div>
<div class="updated-at" id="updatedAt">Connecting…</div>

<div class="track-qr">
    <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=8&color=15-23-42&bgcolor=255-255-255&data=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/track.php?branch=' . $pharmacy['slug']) ?>"
         alt="Scan to track your number on your phone" width="140" height="140">
    <div class="track-qr-label">Scan to get a<br>vibrate alert on<br>your phone</div>
</div>

<script>
// Fixed per-page, set from the resolved ?branch= so this monitor only
// ever streams its own branch's queue.
const BRANCH_SLUG = <?= $branchSlugJs ?>;

/* ================= NOTIFY-ON-CALL (chime + voice + flash) =================
 * This is a shared display, not an individual patient's device, so
 * "notifying the patient" means making the moment their number is
 * called as unmissable as possible on this screen: a chime, a spoken
 * announcement, and a pulse animation on the number itself.
 */
const lastCalled = { Regular: null, Priority: null };
let hasRenderedOnce = false;

function playChime() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const now = ctx.currentTime;
        [880, 1108].forEach((freq, i) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            osc.connect(gain);
            gain.connect(ctx.destination);
            const start = now + i * 0.18;
            gain.gain.setValueAtTime(0, start);
            gain.gain.linearRampToValueAtTime(0.35, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, start + 0.5);
            osc.start(start);
            osc.stop(start + 0.55);
        });
    } catch (err) {
        // Web Audio unavailable/blocked — silently skip, visual flash still happens.
    }
}

function announceNumber(label, category) {
    if (!('speechSynthesis' in window)) return;
    const utter = new SpeechSynthesisUtterance(
        `Now serving number ${label}, ${category}`
    );
    utter.rate = 0.95;
    utter.pitch = 1;
    window.speechSynthesis.speak(utter);
}

function renderQueueData(data) {
    if (data.status !== 'success') {
        document.getElementById('updatedAt').textContent = 'Error: ' + (data.message || 'unknown');
        return;
    }

    for (const cat of ['Regular', 'Priority']) {
        const c = data.categories[cat];
        const numEl = document.getElementById(cat.toLowerCase() + 'Number');
        const countEl = document.getElementById(cat.toLowerCase() + 'WaitingCount');
        const listEl = document.getElementById(cat.toLowerCase() + 'WaitingList');

        const newLabel = c.now_serving ? c.now_serving.label : null;
        // Only alert on a real change after the first render, so page
        // load / reconnects don't trigger a false chime for whoever
        // is already being served.
        const changed = hasRenderedOnce && newLabel !== null && newLabel !== lastCalled[cat];

        numEl.textContent = newLabel ?? '—';
        countEl.textContent = c.waiting_count > 0
            ? `${c.waiting_count} waiting`
            : 'No one waiting';
        listEl.innerHTML = c.waiting.slice(0, 8).map(w => `<span class="waiting-chip">${w.label}</span>`).join('');

        if (changed) {
            numEl.classList.remove('just-called');
            // Force reflow so the animation restarts even on rapid
            // consecutive calls, where the class may not have cleared yet.
            void numEl.offsetWidth;
            numEl.classList.add('just-called');
            playChime();
            announceNumber(newLabel, cat);
        }

        lastCalled[cat] = newLabel;
    }

    hasRenderedOnce = true;

    document.getElementById('updatedAt').textContent =
        'Last updated: ' + new Date(data.updated_at).toLocaleTimeString();
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