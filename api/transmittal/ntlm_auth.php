<?php
/**
 * NTLM authentication check.
 *
 * WHY THIS DOESN'T IMPLEMENT THE NTLM HANDSHAKE IN PHP:
 * NTLM's challenge/response handshake (Type 1 Negotiate -> Type 2 Challenge
 * -> Type 3 Authenticate) is stateful and tied to a single persistent TCP
 * connection between the two round trips. PHP request handling is
 * normally stateless (a fresh process per request under php-fpm, and even
 * under mod_php this breaks behind reverse proxies or load balancers that
 * don't pin connections). Hand-rolling this in a PHP script is unreliable
 * in production and not how real systems do it.
 *
 * THE STANDARD APPROACH:
 * Let the *web server* handle the NTLM negotiation (it owns the TCP
 * connection and can pin state to it), then have PHP simply read the
 * authenticated Windows identity the web server already resolved.
 *
 *  --- Apache on Linux (Samba + winbind) ---
 *  Requires the server to be domain-joined via Samba winbind
 *  (`wbinfo -t` should succeed) and mod_auth_ntlm_winbind installed.
 *  Example config scoped to this endpoint:
 *
 *      <Files "transmitted_details.php">
 *          AuthType ntlm
 *          AuthName "Facility Domain Auth"
 *          AuthNTLMWinbind on
 *          require valid-user
 *      </Files>
 *
 *  Apache then populates $_SERVER['REMOTE_USER'] with DOMAIN\username.
 *
 *  --- IIS on Windows Server ---
 *  In IIS Manager, for this endpoint/folder: disable Anonymous
 *  Authentication, enable Windows Authentication. IIS negotiates
 *  NTLM/Kerberos itself and exposes the identity via
 *  $_SERVER['AUTH_USER'] (or $_SERVER['LOGON_USER']).
 *
 * If neither is present, this means the web server wasn't configured to
 * require NTLM for this endpoint yet — that config lives outside PHP and
 * needs to be set up on whichever server (Apache or IIS) actually serves
 * this file in production.
 */
function ntlm_authenticate_via_webserver(): string {

    $user = $_SERVER['REMOTE_USER']
        ?? $_SERVER['AUTH_USER']
        ?? $_SERVER['LOGON_USER']
        ?? null;

    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'error',
            'message' => 'NTLM authentication required. This endpoint must be served through a web server configured for NTLM / Windows Authentication (see comments in includes/ntlm_auth.php).'
        ]);
        exit;
    }

    return $user;
}
