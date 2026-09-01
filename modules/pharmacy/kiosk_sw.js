/*
 * ================= KIOSK SERVICE WORKER =================
 * Deliberately does almost nothing. A service worker is one of the
 * criteria some browsers (mainly Android Chrome) check before offering
 * "Add to Home Screen" as an installable app rather than just a
 * bookmark — so this exists to satisfy that, not to add offline
 * support.
 *
 * IMPORTANT: this intentionally does NOT cache the kiosk page or its
 * data. A queue kiosk showing a cached, stale queue number/state while
 * offline would be actively wrong — worse than the network erroring
 * out visibly, which at least a patient or staff member would notice
 * and could work around. Every request just passes straight through to
 * the network, same as if this file didn't exist.
 */

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    // No-op: let the browser handle every request normally. Present
    // only so installability checks that require a fetch handler pass.
});