<?php
// Miscellaneous utility functions 

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
