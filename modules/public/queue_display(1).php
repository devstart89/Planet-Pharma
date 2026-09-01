<?php
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

/*
 * MULTI-PHARMACY: each branch's TV/monitor is bookmarked with its own
 * URL, e.g. queue_display.php?branch=downtown. Everything below just
 * needs to forward that branch to api/queue_status.php — the API does
 * the actual scoping.
 */
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
<meta http-equiv="refresh" content="300"> <!-- safety-net full reload every 5 min in case the tab is left open for days -->
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        background: #fff;
        color: #f8fafc;
        font-family: 'Segoe UI', Arial, sans-serif;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }
    h1 {
        font-size: 1.5rem;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #94a3b8;
        margin-bottom: .25rem;
    }
    .branch-name {
        color: #475569;
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
        background: #000;
        border-radius: 1.25rem;
        padding: 2rem;
        text-align: center;
        border-top: 6px solid #334155;
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
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .08em;
        margin-bottom: .5rem;
    }
    .now-serving-number {
        font-size: 6rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 1rem;
    }
    .Regular .now-serving-number  { color: #60a5fa; }
    .Priority .now-serving-number { color: #f87171; }
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
        background: #334155;
        color: #cbd5e1;
        padding: .25rem .6rem;
        border-radius: .4rem;
        font-size: .85rem;
        font-weight: 600;
    }
    .updated-at {
        margin-top: 2rem;
        color: #475569;
        font-size: .8rem;
    }
    .placeholder { color: #475569; font-size: 3rem; font-weight: 800; }
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
<div class="updated-at" id="updatedAt"></div>

<script>
// Fixed per-page, set from the resolved ?branch= so this monitor only
// ever polls its own branch's queue.
const BRANCH_SLUG = <?= $branchSlugJs ?>;

async function refreshQueue() {
    try {
        const res = await fetch('../../api/queue_status.php?branch=' + encodeURIComponent(BRANCH_SLUG), { cache: 'no-store' });
        const data = await res.json();

        if (data.status !== 'success') {
            document.getElementById('updatedAt').textContent = 'Error: ' + (data.message || 'unknown');
            return;
        }

        for (const cat of ['Regular', 'Priority']) {
            const c = data.categories[cat];
            const numEl = document.getElementById(cat.toLowerCase() + 'Number');
            const countEl = document.getElementById(cat.toLowerCase() + 'WaitingCount');
            const listEl = document.getElementById(cat.toLowerCase() + 'WaitingList');

            numEl.textContent = c.now_serving ? c.now_serving.label : '—';
            countEl.textContent = c.waiting_count > 0
                ? `${c.waiting_count} waiting`
                : 'No one waiting';
            listEl.innerHTML = c.waiting.slice(0, 8).map(w => `<span class="waiting-chip">${w.label}</span>`).join('');
        }

        document.getElementById('updatedAt').textContent =
            'Last updated: ' + new Date(data.updated_at).toLocaleTimeString();
    } catch (e) {
        document.getElementById('updatedAt').textContent = 'Connection lost — retrying...';
    }
}

refreshQueue();
setInterval(refreshQueue, 4000); // poll every 4s for near real-time updates
</script>
</body>
</html>