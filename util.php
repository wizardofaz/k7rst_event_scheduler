<?php
// Miscellaneous utility functions 
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logging.php';


/**
 * Make an absolute URL relative when safe, so it inherits the current scheme (HTTPS).
 * - Same host (+ same port or no explicit port) => return relative (root-relative by default)
 * - Same host but different explicit port       => keep absolute
 * - Different host                              => keep absolute
 */
function make_url_relative(string $url, bool $prefer_root_relative = true, bool $upgrade_https_on_same_host = true): string
{
    // Already relative? Return as-is.
    if (!preg_match('~^[a-z][a-z0-9+\-.]*://~i', $url)) {
        return $url;
    }

    $t = parse_url($url);
    if (!$t || empty($t['scheme']) || empty($t['host'])) return $url;

    // Current request origin
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $curScheme = $isHttps ? 'https' : 'http';
    $curHost   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    $curPath   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

    // Normalize ports
    $curPort = (int)($_SERVER['SERVER_PORT'] ?? ($isHttps ? 443 : 80));
    $tPort   = (int)($t['port'] ?? (($t['scheme'] === 'https') ? 443 : 80));

    $sameHost = (strcasecmp($t['host'], $curHost) === 0);

    if ($sameHost) {
        // If ports match (or target had no explicit port), we can safely return a relative URL.
        $targetHadExplicitPort = array_key_exists('port', $t);
        if (!$targetHadExplicitPort || $tPort === $curPort) {
            $path = $t['path'] ?? '/';
            $rel  = $prefer_root_relative ? $path
                                          : _relative_path(dirname($curPath), $path);
            if (!empty($t['query']))    $rel .= '?' . $t['query'];
            if (!empty($t['fragment'])) $rel .= '#' . $t['fragment'];
            return $rel; // inherits current scheme (e.g., HTTPS)
        }

        // Same host but different explicit port — keep absolute.
        if ($upgrade_https_on_same_host && $curScheme === 'https' && $t['scheme'] === 'http' && $tPort === 80) {
            // Upgrade scheme on same host (common case) but preserve explicit non-80 ports by not altering them.
            $t['scheme'] = 'https';
            unset($t['port']); // use default 443
            return build_url($t);
        }
        return $url;
    }

    // Different host → keep absolute
    return $url;
}

// Helper to build a URL from parse_url() parts
function build_url(array $p): string {
    $u  = $p['scheme'] . '://';
    if (!empty($p['user'])) {
        $u .= $p['user'];
        if (!empty($p['pass'])) $u .= ':' . $p['pass'];
        $u .= '@';
    }
    $u .= $p['host'];
    if (!empty($p['port'])) $u .= ':' . $p['port'];
    $u .= $p['path'] ?? '/';
    if (!empty($p['query']))    $u .= '?' . $p['query'];
    if (!empty($p['fragment'])) $u .= '#' . $p['fragment'];
    return $u;
}

function secrets_path() {
    // Replace the 'public_html' segment in the current directory path with 'secrets'
    // and then look for secrets.php in the resulting path.
    // Example:
    //   __DIR__ = /user/xxx/public_html/rst
    //   -> /user/xxx/secrets/rst/secrets.php

    $here = __DIR__;

    // Split path components handling / and \ separators
    $parts = preg_split('#[\\\\/]#', $here);

    // Find the public_html segment
    $idx = array_search('public_html', $parts, true);
    if ($idx === false) {
        die("❌ Could not locate 'public_html' in path: {$here}");
    }

    // Swap it for 'secrets' and rebuild the path
    $parts[$idx] = 'secrets';
    $secrets_dir  = implode(DIRECTORY_SEPARATOR, $parts);
    $secrets_path = $secrets_dir . DIRECTORY_SEPARATOR . 'secrets.php';

    if (!file_exists($secrets_path)) {
        die("❌ secrets.php not found at: {$secrets_path}");
    }

    return $secrets_path;
}

/** document-relative path helper */
function _relative_path(string $fromDir, string $toPath): string {
    $from = array_values(array_filter(explode('/', trim($fromDir, '/'))));
    $to   = array_values(array_filter(explode('/', ltrim($toPath, '/'))));
    $i = 0; $max = min(count($from), count($to));
    while ($i < $max && $from[$i] === $to[$i]) $i++;
    $up   = array_fill(0, max(0, count($from) - $i), '..');
    $down = array_slice($to, $i);
    $rel = implode('/', array_merge($up, $down));
    return ($rel === '' ? './' : $rel);
}

/**
 * @param string $date  MySQL DATE in UTC (e.g., "2025-11-20")
 * @param string $time  MySQL TIME in UTC (e.g., "02:00:00" or "02:00")
 * @param string|null $localTz IANA zone for local display (defaults to LOCAL_TIMEZONE or America/Phoenix)
 * @return array{dateUTC:string,timeUTC:string,timeLocal:string}
 */
function format_display_date_time(string $date, string $time, ?string $localTz = null): array
{
    $tzUTC   = new DateTimeZone('UTC');
    $tzLocal = new DateTimeZone($localTz ?: (defined('LOCAL_TIMEZONE') ? LOCAL_TIMEZONE : 'America/Phoenix'));
    $tzLocalShort = defined('LOCAL_TIMEZONE_SHORT') ? LOCAL_TIMEZONE_SHORT : 'AZ';

    // Build a UTC datetime from separate DATE and TIME fields.
    // Accepts "HH:MM" or "HH:MM:SS".
    $time = $time === '' ? '00:00:00' : $time;
    $dtUtc = new DateTimeImmutable(trim("$date $time"), $tzUTC);

    // Format pieces
    $dateUTC  = $dtUtc->format('D m/d/y');   // e.g., "Thu 20-11-25"
    $timeUTC  = $dtUtc->format('Hi\\z');     // e.g., "0200z"

    $dtLocal  = $dtUtc->setTimezone($tzLocal);
    $timeLocal = $dtLocal->format('Hi').$tzLocalShort;     // e.g., "1700AZ"

    // If the local calendar date differs from UTC, append " (Ddd)"
    if ($dtLocal->format('Y-m-d') !== $dtUtc->format('Y-m-d')) {
        $timeLocal .= ' (' . $dtLocal->format('D') . ')';  // e.g., "1700AZ (Wed)"
    }

    return [
        'dateUTC'   => $dateUTC,
        'timeUTC'   => $timeUTC,
        'timeLocal' => $timeLocal,
    ];
}

/**
 * public_log_link.php
 *
 * Builds a public-log URL from a template with {PLACEHOLDER} substitutions.
 * - Controlled by config constants:
 *     EVENT_PUBLIC_LOG_ENABLE (1/0)
 *     EVENT_PUBLIC_LOG_LINK_TEMPLATE (e.g. "https://n7dz.net/wavelog/index.php/visitor/{ASSIGNED_CALL|lower}")
 * - Returns "" if disabled, invalid/missing template, unresolved placeholder, or unsafe URL.
 * - Supports filters: lower, upper, trim, url (rawurlencode), url_qs (urlencode).
 * - Includes a safety check to allow only http/https absolute URLs, or safe relative URLs.
 * - Provides named-args API for PHP 8+ and an array-based wrapper for flexibility.
 */

/* ---------- Core API (named args, PHP 8+) ---------- */
/**
 * Build the public log link using named args (common placeholders as parameters).
 * Any placeholders not listed here can be supplied via build_public_log_link_args().
 */
function build_public_log_link(
    ?string $ASSIGNED_CALL = null,
    ?string $EVENT_KEY     = null,
    ?string $BAND          = null,
    ?string $MODE          = null,
    ?string $DATE_UTC      = null,
    ?string $DATE_LOCAL    = null,
    ?string $LOOKUP_CALL   = null
): string {
    // Collect provided values into a map used for substitutions.
    $values = array_filter([
        'ASSIGNED_CALL' => $ASSIGNED_CALL,
        'EVENT_KEY'     => $EVENT_KEY,
        'BAND'          => $BAND,
        'MODE'          => $MODE,
        'DATE_UTC'      => $DATE_UTC,
        'DATE_LOCAL'    => $DATE_LOCAL,
        'LOOKUP_CALL'   => $LOOKUP_CALL,
    ], static function($v) { return $v !== null; });

    return build_public_log_link_args($values);
}

/* ---------- Array-based API (works everywhere) ---------- */
/**
 * Build the public log link using an associative array of placeholder => value.
 * Example: build_public_log_link_args(['ASSIGNED_CALL' => 'K7 C', 'LOOKUP_CALL' => 'N7 DZ'])
 */
function build_public_log_link_args(array $vars): string {
    // 1) Check feature toggle
    if (!defined('EVENT_PUBLIC_LOG_ENABLE') || !EVENT_PUBLIC_LOG_ENABLE) {
        log_msg(DEBUG_INFO, 'Public log links disabled (EVENT_PUBLIC_LOG_ENABLE not truthy).');
        return '';
    }

    // 2) Fetch template
    if (!defined('EVENT_PUBLIC_LOG_LINK_TEMPLATE')) {
        log_msg(DEBUG_ERROR, 'Template missing: EVENT_PUBLIC_LOG_LINK_TEMPLATE is not defined.');
        return '';
    }
    $tpl = (string)EVENT_PUBLIC_LOG_LINK_TEMPLATE;
    if (trim($tpl) === '') {
        log_msg(DEBUG_ERROR, 'Template is empty.');
        return '';
    }

    // 3) Perform substitutions
    $result = substitute_template($tpl, $vars, $errors);

    if ($result === null) {
        foreach ($errors as $e) { log_msg(DEBUG_ERROR, "[tmpl-subst] $e"); }
        return '';
    }

    // 4) Safety check for URL
    if (!is_url_safe_for_href($result, $why)) {
        log_msg(DEBUG_ERROR, "Unsafe URL rejected: {$result}. Reason: {$why}");
        return '';
    }

    return $result;
}

/* ---------- Template substitution ---------- */
/**
 * Supported placeholder forms inside the template:
 *   {NAME}
 *   {NAME?default}
 *   {NAME|filter1|filter2}
 *   {NAME?default|filter1|filter2}
 *
 * Filters are applied left-to-right. Supported filters:
 *   lower, upper, trim, url (rawurlencode), url_qs (urlencode)
 *
 * Returns substituted string, or null on any error (missing value, parse error, etc.).
 * $errors (array by ref) is filled with human-readable issues.
 */
function substitute_template(string $tpl, array $vars, ?array &$errors = null): ?string {
    $errors = [];

    $pattern = '/\{([^{}\n]+)\}/'; // one-line placeholders only; avoids runaway matches
    $had_error = false;

    $out = preg_replace_callback($pattern, function ($m) use ($vars, &$errors, &$had_error) {
        $inside = $m[1]; // e.g., "ASSIGNED_CALL?unknown|lower|url"
        $namePart = $inside;
        $filtersPart = '';

        // Split off filters first (by '|'), but keep the default part with the name
        if (strpos($inside, '|') !== false) {
            [$namePart, $filtersPart] = explode('|', $inside, 2);
        }

        // Default handling: NAME?default
        $default = null;
        $name = $namePart;
        if (strpos($namePart, '?') !== false) {
            [$name, $default] = explode('?', $namePart, 2);
        }

        $name = trim($name);
        if ($name === '') {
            $errors[] = 'Empty placeholder name encountered.';
            $had_error = true;
            return '';
        }

        // Value lookup
        $val = null;
        $hasValue = array_key_exists($name, $vars) && $vars[$name] !== null && $vars[$name] !== '';
        if ($hasValue) {
            $val = (string)$vars[$name];
        } elseif ($default !== null) {
            $val = (string)$default;
        } else {
            $errors[] = "Missing value for placeholder '{$name}' and no default provided.";
            $had_error = true;
            return '';
        }

        // Apply filters if any
        $filters = [];
        if ($filtersPart !== '') {
            $filters = array_map('trim', explode('|', $filtersPart));
        }

        foreach ($filters as $f) {
            if ($f === '') { continue; }
            $val = apply_filter($val, $f, $ok, $err);
            if (!$ok) {
                $errors[] = "Filter '{$f}' failed on '{$name}': {$err}";
                $had_error = true;
                return '';
            }
        }

        // Empty result after filters → treat as error (nothing useful to insert)
        if ($val === '') {
            $errors[] = "Placeholder '{$name}' produced empty string after filters.";
            $had_error = true;
            return '';
        }

        return $val;
    }, $tpl);

    if ($out === null) {
        $errors[] = 'preg_replace_callback failed.';
        return null;
    }

    // If any error occurred during replacement, signal failure
    if ($had_error) {
        return null;
    }

    // Sanity check: leftover unmatched braces probably means a template issue
    if (preg_match('/\{[^{}\n]*\}/', $out)) {
        $errors[] = 'Unresolved placeholder(s) remain after substitution.';
        return null;
    }

    return $out;
}

function apply_filter(string $val, string $filter, ?bool &$ok = null, ?string &$err = null): string {
    $ok = true; $err = '';
    switch (strtolower($filter)) {
        case 'lower':   return mb_strtolower($val, 'UTF-8');
        case 'upper':   return mb_strtoupper($val, 'UTF-8');
        case 'trim':    return trim($val);
        case 'url':     return rawurlencode($val);   // RFC 3986 (spaces -> %20)
        case 'url_qs':  return urlencode($val);      // Query-string style (spaces -> +)
        default:
            $ok = false; $err = "unknown filter '{$filter}'";
            return '';
    }
}

/* ---------- URL safety check ---------- */
/**
 * Accept:
 *  - Absolute URLs with scheme http/https only.
 *  - Relative URLs:
 *      * starting with '/', './', '../', or plain relative paths like 'visitor/k7c'
 *      * must NOT start with '//' (protocol-relative)
 * Reject:
 *  - empty
 *  - schemes like 'javascript:', 'data:', 'vbscript:', etc.
 */
function is_url_safe_for_href(string $url, ?string &$why = null): bool {
    $why = '';
    $u = trim($url);
    if ($u === '') { $why = 'empty'; return false; }

    // Disallow protocol-relative to avoid bypasses like //evil.com
    if (strpos($u, '//') === 0) { $why = 'protocol-relative not allowed'; return false; }

    // Disallow known-dangerous schemes
    if (preg_match('/^\s*(javascript|data|vbscript|file):/i', $u)) {
        $why = 'disallowed scheme';
        return false;
    }

    // Absolute URL?
    if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $u)) {
        $parts = @parse_url($u);
        if ($parts === false || empty($parts['scheme'])) {
            $why = 'unparseable absolute URL';
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            $why = "scheme '{$scheme}' not allowed";
            return false;
        }
        // Basic host presence check for absolute URLs
        if (empty($parts['host'])) {
            $why = 'absolute URL missing host';
            return false;
        }
        return true;
    }

    // Relative URL: allow safe forms
    if (
        $u[0] === '/' ||
        str_starts_with($u, './') ||
        str_starts_with($u, '../') ||
        preg_match('/^[A-Za-z0-9._~\-\/?&=#%+,:;@]+$/', $u) // conservative set
    ) {
        return true;
    }

    $why = 'relative URL contains disallowed characters';
    return false;
}

/* ---------- Optional: self-test when run directly ---------- */
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // Example constants for a quick CLI test:
    if (!defined('EVENT_PUBLIC_LOG_ENABLE'))        define('EVENT_PUBLIC_LOG_ENABLE', 1);
    if (!defined('EVENT_PUBLIC_LOG_LINK_TEMPLATE')) define('EVENT_PUBLIC_LOG_LINK_TEMPLATE', '/visitor/{ASSIGNED_CALL|url}?lookup={LOOKUP_CALL|url_qs}');

    $ok  = build_public_log_link(ASSIGNED_CALL: 'K7 C', LOOKUP_CALL: 'N7 DZ');
    echo "OK  : {$ok}\n"; // Expect: /visitor/K7%20C?lookup=N7+DZ

    $bad = build_public_log_link_args(['ASSIGNED_CALL' => 'K7C']); // missing LOOKUP_CALL for this template
    echo "BAD : " . var_export($bad, true) . "\n"; // Expect: ""
}
