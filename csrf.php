<?php
// csrf.php
// security around login and db edits
//

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

/** (Optional) Defense-in-depth: Origin/Referer check for POSTs */
function csrf_check_origin(array $allowedHosts): bool {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return true;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    foreach ([$origin, $referer] as $hdr) {
        if ($hdr) {
            $host = parse_url($hdr, PHP_URL_HOST);
            if ($host && in_array($host, $allowedHosts, true)) return true;
        }
    }
    // If no headers present, you can choose to allow or deny.
    // Returning true here to avoid false negatives on some clients.
    return true;
}
