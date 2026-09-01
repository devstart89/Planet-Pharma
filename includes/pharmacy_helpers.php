<?php
/*
 * ================= PHARMACY / BRANCH HELPERS =================
 * Shared by public-facing pages (kiosk.php, api/queue_status.php,
 * queue_display.php) and authenticated staff pages (queue.php,
 * queue_data.php).
 *
 * Branches live in the existing `pharmacy` table (id, facility_id,
 * pharmacy_name, slug, address, contact_number, status). Every queue
 * row and every pharmacy-role staff account belongs to exactly one
 * pharmacy.id via a pharmacy_id column. Prescriptions are NOT tagged
 * directly — their branch is derived through
 * prescriptions.facility_id -> health_facilities.pharmacy_id, since
 * that mapping already exists in your data.
 *
 * Public pages have no session, so they identify their branch via a
 * ?branch=slug URL param (one kiosk/monitor URL bookmarked per
 * physical terminal). Staff pages get their branch from the logged-in
 * user's account instead.
 */

/**
 * Resolve a pharmacy branch from its slug. Used by public,
 * unauthenticated pages.
 */
function resolvePharmacyBySlug(PDO $conn, ?string $slug): ?array {
    $slug = trim((string) $slug);
    if ($slug === '') return null;

    $stmt = $conn->prepare("SELECT * FROM pharmacy WHERE slug = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Resolve a pharmacy branch from its numeric id. Used by staff pages,
 * where the branch comes from the logged-in user's account.
 */
function resolvePharmacyById(PDO $conn, ?int $id): ?array {
    if (!$id) return null;
    $stmt = $conn->prepare("SELECT * FROM pharmacy WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Renders a simple "pick your branch" page and exits. Used as the
 * fail-safe when a public page is loaded without a valid ?branch= so
 * a missing/mistyped URL never silently falls back to someone else's
 * queue data.
 */
function renderBranchPicker(PDO $conn, string $targetPage): void {
    $stmt = $conn->query("SELECT pharmacy_name, slug FROM pharmacy WHERE status = 'active' ORDER BY pharmacy_name ASC");
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Select Pharmacy</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<style>
        body{font-family:Arial,sans-serif;max-width:420px;margin:4rem auto;padding:0 1rem;color:#1d2939;}
        h1{font-size:1.2rem;}
        a{display:block;padding:.9rem 1rem;margin-bottom:.6rem;border:1px solid #ddd;
          border-radius:.5rem;text-decoration:none;color:#175cd3;font-weight:600;}
        a:hover{border-color:#175cd3;background:#eff8ff;}
        p.empty{color:#667085;}
    </style></head><body>';
    echo '<h1>Select a pharmacy location</h1>';

    if (!$branches) {
        echo '<p class="empty">No active pharmacy locations are configured yet. Run the slug migration first.</p>';
    }
    foreach ($branches as $b) {
        if (empty($b['slug'])) continue; // hasn't been migrated to have a slug yet
        $url = htmlspecialchars($targetPage . '?branch=' . urlencode($b['slug']));
        echo '<a href="' . $url . '">' . htmlspecialchars($b['pharmacy_name']) . '</a>';
    }
    echo '</body></html>';
    exit;
}