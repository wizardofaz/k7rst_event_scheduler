<?php
// auth.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/event_db.php';


/**
 * Dependencies:
 * - config.php: defines MASTER_SERVER, MASTER_DB, MASTER_USER, MASTER_PASSWORD
 * - csrf.php: for consistency with session cookie params (not strictly required here)
 * - Master DB: table `events(event_name, db_name, db_host, db_user, db_pass, ...)`
 * - Event DB: table `operator_passwords(id PK AI, op_call UNIQUE, op_password VARCHAR(255), password_hash VARCHAR(255) NULL)`
 */


/**
 * Login or create (dual-mode verification):
 * - If password_hash present → verify with password_verify()
 * - Else → compare op_password (plaintext). If matches, upgrade row by writing password_hash.
 * On create: writes both op_password (for now) and password_hash (so we’re future-ready).
 */
function auth_login_or_create(string $event, string $callsign, string $name, string $password): bool {
    $db = get_event_db_connection_from_master($event);
    $call = auth_norm_call($callsign);
    $name = trim($name);
    $pw   = (string)$password;

    // Lookup
    $stmt = $db->prepare('SELECT id, op_password, password_hash FROM operator_passwords WHERE op_call = ? LIMIT 1');
    $stmt->bind_param('s', $call);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row) {
        $ok = false;
        if (!empty($row['password_hash'])) {
            $ok = password_verify($pw, $row['password_hash']);
        } else {
            // Legacy plaintext compare
            $ok = hash_equals($row['op_password'] ?? '', $pw);
            if ($ok) {
                // Upgrade: set hash now
                $newHash = password_hash($pw, PASSWORD_DEFAULT);
                $up = $db->prepare('UPDATE operator_passwords SET password_hash = ? WHERE id = ?');
                $up->bind_param('si', $newHash, $row['id']);
                $up->execute();
                $up->close();
            }
        }
        if (!$ok) { $db->close(); return false; }

        csrf_start_session_if_needed();
        $_SESSION['cactus_auth'] = [
            'event'    => trim($event),
            'callsign' => $call,
            'name'     => $name,
            'role'     => 'auth',
            'is_admin' => 0,
            'since'    => gmdate('c'),
        ];
        $db->close();
        return true;
    } else {
        // First login creates account (store both for now; we can NULL op_password later)
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO operator_passwords (op_call, op_password, password_hash) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $call, $pw, $hash);
        if (!$stmt->execute()) {
            $stmt->close();
            $db->close();
            http_response_code(500);
            exit('Failed to create operator password.');
        }
        $stmt->close();

        csrf_start_session_if_needed();
        $_SESSION['cactus_auth'] = [
            'event'    => trim($event),
            'callsign' => $call,
            'name'     => $name,
            'role'     => 'auth',
            'is_admin' => 0,
            'since'    => gmdate('c'),
        ];
        $db->close();
        return true;
    }
}

function auth_logout(): void {
    csrf_start_session_if_needed();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_regenerate_id(true);
    session_regenerate_id(true);

    // Build redirect to the current directory (so it hits index.php)
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    $path   = parse_url($uri, PHP_URL_PATH) ?? '/';
    $dir    = rtrim(dirname($path), '/\\');      // directory of the current script
    $dest   = $scheme . '://' . $host . ($dir === '' ? '/' : $dir . '/');

    header('Location: ' . $dest, true, 302);
    exit;

}

function auth_initialize(): void {
    if (isset($_SESSION['cactus_auth'])) return; // DEBUG TEST

    // called by csrf_start_session_if_needed so DON'T call that!! (recursion)
    $_SESSION['cactus_auth'] = [
        'event'    => '',
        'callsign' => '',
        'name'     => '',
        'role'     => '',
        'is_admin' => 0,
        'since'    => gmdate('c'),
    ];
}

function auth_clear_authentication(): void {
    if (isset($_SESSION['cactus_auth'])) {
        $_SESSION['cactus_auth']['role'] = '';
        $_SESSION['cactus_auth']['is_admin'] = 0;
        $_SESSION['cactus_auth']['since'] = gmdate('c');
    }
}

function auth_norm_call(string $call): string {
    return strtoupper(trim($call));
}

/** 'exists' or 'new' */
function auth_status_for_callsign(string $event, string $callsign): string {
    $db = get_event_db_connection_from_master($event);
    $call = auth_norm_call($callsign);
    $stmt = $db->prepare('SELECT id FROM operator_passwords WHERE op_call = ? LIMIT 1');
    $stmt->bind_param('s', $call);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    $db->close();
    return $exists ? 'exists' : 'new';
}

/** Browse-only; sets session */
function auth_set_browse(string $event, string $callsign, string $name): void {
    csrf_start_session_if_needed();
    $_SESSION['cactus_auth'] = [
        'event'    => trim($event),
        'callsign' => auth_norm_call($callsign),
        'name'     => trim($name),
        'role'     => 'browse',
        'is_admin' => 0,
        'since'    => gmdate('c'),
    ];
}

function auth_is_browse_only(): bool {
    csrf_start_session_if_needed();
    return isset($_SESSION['cactus_auth']) && ($_SESSION['cactus_auth']['role'] ?? null) === 'browse';
}

function auth_is_authenticated(): bool {
    csrf_start_session_if_needed();
    return isset($_SESSION['cactus_auth']) && ($_SESSION['cactus_auth']['role'] ?? null) === 'auth';
}

function auth_is_admin(): bool {
    csrf_start_session_if_needed();
    return isset($_SESSION['cactus_auth']) && (($_SESSION['cactus_auth']['is_admin'] ?? 0) === 1);
}

function auth_get_callsign(): string {
    csrf_start_session_if_needed();
    return isset($_SESSION['cactus_auth']) ? ($_SESSION['cactus_auth']['callsign'] ?? null) : null;
}

function auth_get_name(): string {
    csrf_start_session_if_needed();
    return isset($_SESSION['cactus_auth']) ? ($_SESSION['cactus_auth']['name'] ?? null) : null;
}

function auth_get_event(): string {
    csrf_start_session_if_needed();
    return isset($_SESSION['cactus_auth']) ? ($_SESSION['cactus_auth']['event'] ?? null) : null;
}

// changing event nullifies logged in status
function auth_set_event(string $e) {
    csrf_start_session_if_needed();
    if ($e === $_SESSION['cactus_auth']['event']) return;
    auth_clear_authentication();
    $_SESSION['cactus_auth']['event'] = $e;
}

// changing callsign nullifies logged in status
function auth_set_callsign(string $c) {
    csrf_start_session_if_needed();
    if ($c === $_SESSION['cactus_auth']['callsign']) return;
    auth_clear_authentication();
    $_SESSION['cactus_auth']['callsign'] = $c;
}

// but changing name is harmless
function auth_set_name(string $n) {
    csrf_start_session_if_needed();
    $_SESSION['cactus_auth']['name'] = $n;
}

// only used in debugging 
function auth_dump_payload() {
    return $_SESSION['cactus_auth'];
}
