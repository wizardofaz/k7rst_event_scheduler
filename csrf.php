<?php
// csrf.php
// security around login and db edits
//
require_once __DIR__ . '/auth.php';

// use in place of any session_start
function csrf_start_session_if_needed(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Strengthen the session cookie a bit
        session_set_cookie_params([
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax', // helpful, but NOT a substitute for tokens
        ]);
        session_start();
        auth_initialize();
    }
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = [];
    }
}

/**
 * Create/get a CSRF token for a logical action key (e.g., 'login', 'add_slot').
 * Single-use: validate() will unset it.
 */
function csrf_token(string $key, int $ttl_seconds = 900): string {
    csrf_start_session_if_needed();
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf'][$key] = ['v' => $token, 'ts' => time(), 'ttl' => $ttl_seconds];
    return $token;
}

/** Embed a hidden input in your form */
function csrf_input(string $key): string {
    $token = csrf_token($key);
    return '<input type="hidden" name="_csrf_key" value="'.htmlspecialchars($key, ENT_QUOTES).'">' .
           '<input type="hidden" name="_csrf" value="'.htmlspecialchars($token, ENT_QUOTES).'">';
}

/** Validate and consume the token. Returns true on success. */
function csrf_validate(?string $key, ?string $token): bool {
    csrf_start_session_if_needed();
    if (!$key || !$token) return false;
    if (!isset($_SESSION['csrf'][$key])) return false;
    $rec = $_SESSION['csrf'][$key];
    $ok = hash_equals($rec['v'], $token) && (time() - $rec['ts'] <= $rec['ttl']);
    // single-use: consume regardless to prevent replay attempts
    unset($_SESSION['csrf'][$key]);
    return $ok;
}

// --- Helpers to derive the "effective" scheme/host for this request ---
function cactus_effective_scheme(): string {
    // Respect reverse proxies if present
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    return 'http';
}

function cactus_effective_host(): string {
    // Prefer X-Forwarded-Host, then Host, then SERVER_NAME
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $host = strtolower(trim($host));

    // If Host had a port, strip it for origin compare
    if (strpos($host, ':') !== false) {
        [$hostOnly,] = explode(':', $host, 2);
        $host = $hostOnly;
    }
    // Optional: normalize IDN (needs ext-intl). Safe to skip if not available.
    if (function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii) $host = strtolower($ascii);
    }
    return $host;
}

// --- Parse Origin/Referer and compare to effective origin ---
function csrf_check_same_origin(array $extra_allowed_hosts = []): bool {
    $scheme = cactus_effective_scheme();
    $host   = cactus_effective_host();

    // Build the set of allowed hosts dynamically:
    $allowed = array_unique(array_filter([
        $host,
        // auto-allow the www./non-www. twin
        (str_starts_with($host, 'www.') ? substr($host, 4) : ('www.' . $host)),
        // any extras the caller wants to add (e.g., admin subdomain)
        ...array_map('strtolower', $extra_allowed_hosts),
    ]));

    // Prefer Origin; fall back to Referer; if neither present, allow.
    $hdr = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($hdr === '') {
        // No Origin/Referer (old browser, privacy settings). Token still protects.
        return true;
    }

    $parts = parse_url($hdr);
    if (!$parts) {
        return false;
    }

    $h = strtolower($parts['host'] ?? '');
    // Normalize IDN
    if ($h && function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii($h, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii) $h = strtolower($ascii);
    }

    $sch = strtolower($parts['scheme'] ?? '');
    // If port present, ensure it matches too (optional; usually not needed)
    // $prt = isset($parts['port']) ? (int)$parts['port'] : null;

    // Must be same scheme and host in our allowed set
    if ($sch !== $scheme) {
        return false;
    }
    if (!in_array($h, $allowed, true)) {
        return false;
    }
    return true;
}
