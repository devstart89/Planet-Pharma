/*
 * ================= KIOSK COMMON (JS) =================
 * Shared hardening for any kiosk-style page in EPscript. Pair with
 * kiosk_common.css. Include once per page:
 *   <script src="../../includes/kiosk_common.js"></script>
 *
 * What this does:
 * 1. Injects a small floating full-screen toggle button (top-right
 *    corner). Tapping it requests Fullscreen; tapping it again (it
 *    re-labels/re-icons itself once active) exits Fullscreen. This is
 *    deliberate — Fullscreen only ever changes because someone tapped
 *    this specific button, never as a side effect of some other tap
 *    elsewhere on the page, and never automatically on page load
 *    (browsers don't allow that without a gesture anyway).
 * 2. Blocks the long-press / right-click context menu everywhere, since
 *    it exposes browser chrome (Inspect, Save As, View Source, etc.)
 *    that has no business being reachable on a public terminal.
 *
 * REAL CAVEAT: iOS Safari has never supported the Fullscreen API for
 * general web content (only for <video>) — this isn't a bug here, it's
 * a long-standing WebKit limitation. The button below detects that and
 * simply doesn't inject itself on iPad rather than show a dead button.
 * On iOS, the PWA "Add to Home Screen" install (see kiosk_manifest.php)
 * is the only way to get a chrome-less kiosk display.
 *
 * REAL CAVEAT #2: browsers deliberately drop Fullscreen on any real page
 * navigation (a link click, a form submit/redirect) — this is enforced
 * at the browser/OS security level, not something JS can override, and
 * kiosk.php's screens ARE real page navigations. What this file does
 * about that: remembers "the toggle button was used to enter
 * Fullscreen" in sessionStorage, and on every subsequent page load
 * (until the toggle button is used to explicitly exit) immediately
 * tries requestFullscreen() again. Some browsers briefly carry over
 * enough "user activation" from the navigation gesture to let this
 * succeed silently; others won't, and it'll just do nothing that time.
 * Either way, only the toggle button's own Exit action ever clears the
 * remembered intent — a failed automatic re-entry attempt does not.
 *
 * This intentionally does NOT touch printing, WebUSB pairing, or any
 * other page-specific logic — each of those still needs and gets its
 * own direct user gesture at the moment it actually happens.
 */
(function () {
    'use strict';

    var WANT_KEY = 'kioskFullscreenWanted';

    function wantsFullscreen() {
        try { return sessionStorage.getItem(WANT_KEY) === '1'; } catch (e) { return false; }
    }
    function setWantsFullscreen(want) {
        try { sessionStorage.setItem(WANT_KEY, want ? '1' : '0'); } catch (e) { /* storage blocked — just skip persisting */ }
    }

    var ICON_EXPAND =
        '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>';
    var ICON_COLLAPSE =
        '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M9 3v3a2 2 0 0 1-2 2H4M15 3v3a2 2 0 0 0 2 2h3M9 21v-3a2 2 0 0 0-2-2H4M15 21v-3a2 2 0 0 1 2-2h3"/></svg>';

    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    function requestFullscreen() {
        var el = document.documentElement;
        var request = el.requestFullscreen || el.webkitRequestFullscreen;
        if (!request) return;
        setWantsFullscreen(true);
        try {
            var result = request.call(el);
            if (result && typeof result.catch === 'function') result.catch(function () {});
        } catch (e) { /* ignore — button just stays in its current state */ }
    }

    function exitFullscreen() {
        var exit = document.exitFullscreen || document.webkitExitFullscreen;
        if (!exit) return;
        setWantsFullscreen(false); // the only place this ever gets cleared
        try {
            var result = exit.call(document);
            if (result && typeof result.catch === 'function') result.catch(function () {});
        } catch (e) { /* ignore */ }
    }

    function initFullscreenToggle() {
        // Neither vendor-prefixed nor standard support at all (iOS
        // Safari) — skip injecting a button that could never do
        // anything, rather than show a dead control.
        var supported = document.fullscreenEnabled || document.webkitFullscreenEnabled;
        if (!supported) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'kioskFullscreenToggle';
        btn.setAttribute('aria-label', 'Enter full screen');
        btn.innerHTML = ICON_EXPAND;

        function updateIcon() {
            if (isFullscreen()) {
                btn.innerHTML = ICON_COLLAPSE;
                btn.setAttribute('aria-label', 'Exit full screen');
            } else {
                btn.innerHTML = ICON_EXPAND;
                btn.setAttribute('aria-label', 'Enter full screen');
            }
        }

        btn.addEventListener('click', function () {
            if (isFullscreen()) exitFullscreen(); else requestFullscreen();
        });

        document.addEventListener('fullscreenchange', updateIcon);
        document.addEventListener('webkitfullscreenchange', updateIcon);

        document.body.appendChild(btn);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFullscreenToggle);
    } else {
        initFullscreenToggle();
    }

    // Best-effort re-entry after a page navigation — see REAL CAVEAT #2
    // above for why this can't be guaranteed to succeed every time.
    if (wantsFullscreen() && !isFullscreen()) {
        requestFullscreen();
    }

    document.addEventListener('contextmenu', function (e) {
        e.preventDefault();
    }, false);

    window.KioskCommon = { requestFullscreen: requestFullscreen, exitFullscreen: exitFullscreen, isFullscreen: isFullscreen };
})();