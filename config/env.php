<?php


return [

    "APP_ENV" => "production",

    // ================= SECURITY =================
    "HMAC_ALGO" => "sha256",
    "REQUEST_TTL" => 300, // 5 minutes

    // REQUIRE signed requests (MANDATORY in production)
    "REQUIRE_SIGNATURE" => true,

    // ================= RATE LIMIT =================
    // Fallback if DB value is missing
    "RATE_LIMIT" => 60,     // requests
    "RATE_WINDOW" => 60,    // seconds

    // ================= PAGINATION =================
    "MAX_PAGE_LIMIT" => 50,
    "DEFAULT_PAGE_LIMIT" => 10,

    // ================= SECURITY HEADERS =================
    "ENABLE_CORS" => false,
    "ALLOWED_ORIGINS" => [
        // "https://trustedclient.com"
    ],

    // ================= LOGGING =================
    "LOG_API_REQUESTS" => true,

    // ================= FALLBACK KEYS =================
    "GLOBAL_API_KEY" => null,
    "GLOBAL_SECRET"  => null,

];