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
