<?php

define('DEBUG_ERROR', 0);
define('DEBUG_WARNING', 1);
define('DEBUG_INFO', 2);
define('DEBUG_VERBOSE', 3);
define('DEBUG_DEBUG', 4);

define('DEBUG_CODEWORD',["ERROR","WARN","INFO","VERBOSE","DEBUG"]);

define('DEBUG_SYMBOL', ['❌','⚠️','ℹ️','📢','🔍']);

/**
 * Return true if the given file is allowed to emit logs.
 * Controlled by optional constants (defined elsewhere, e.g. debug_list.php):
 *   - DEBUG_ONLY_FILES: string[] of exact basenames
 *   - DEBUG_ONLY_GLOB:  string[] of fnmatch() wildcard patterns (match on basename)
 *   - DEBUG_ONLY_REGEX: string[] of PCRE patterns (match on full path)
 *
 * If none of the above are defined, all files are allowed.
 */
function __debug_file_allowed(string $fullpath): bool {
    $anyFilter =
        defined('DEBUG_ONLY_FILES') ||
        defined('DEBUG_ONLY_GLOB')  ||
        defined('DEBUG_ONLY_REGEX');
    if (!$anyFilter) return true; // no filters configured

    $base = basename($fullpath);

    if (defined('DEBUG_ONLY_FILES') && is_array(DEBUG_ONLY_FILES)) {
        if (in_array($base, DEBUG_ONLY_FILES, true)) return true;
    }

    if (defined('DEBUG_ONLY_GLOB') && is_array(DEBUG_ONLY_GLOB)) {
        foreach (DEBUG_ONLY_GLOB as $pat) {
            if (is_string($pat) && fnmatch($pat, $base)) return true;
        }
    }

    if (defined('DEBUG_ONLY_REGEX') && is_array(DEBUG_ONLY_REGEX)) {
        foreach (DEBUG_ONLY_REGEX as $re) {
            if (is_string($re) && @preg_match($re, $fullpath)) return true;
        }
    }

    return false;
}

function log_msg($level,$message) {

    if (!isset($_SESSION['debug_level'])) {
        $_SESSION['debug_level'] = defined('DEBUG_LEVEL') ? DEBUG_LEVEL : DEBUG_ERROR;
    }

    if (defined('DEBUG_LEVEL')) {
        // set debug level from GET if present, else from DEBUG_LEVEL 
        if (!isset($_SESSION['debug_level'])) $_SESSION['debug_level'] = DEBUG_LEVEL; // maybe from config.php
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['debug'])) $_SESSION['debug_level'] = (int)$_GET['debug']; 

        if (DEBUG_LEVEL > DEBUG_ERROR && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_SESSION['debugging_warning_done'])) {
            $_SESSION['debugging_warning_done'] = true;
            trigger_error("⚠️ Debug level is greater than 0. Remember to set DEBUG_LEVEL to 0 in config.php when finished debugging (" . CODE_VERSION . ")", E_USER_WARNING);
        }
    }

    if ($_SESSION['debug_level'] >= $level) {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        //$caller = $bt[1] ?? $bt[0]; // [1] = caller of log_msg, [0] = log_msg itself
        $caller = $bt[0]; // [1] = caller of log_msg, [0] = log_msg itself

        $file = basename($caller['file'] ?? 'unknown');
        $line = $caller['line'] ?? '?';
        $func = $caller['function'] ?? 'global';

        // Respect optional debug file filters if configured.
        $fullpath = $caller['file'] ?? '';
        if ($fullpath !== '' && !__debug_file_allowed($fullpath)) {
            return;
        }

        $tag = DEBUG_CODEWORD[$level] ?? "UNKNOWN";
        $icon = DEBUG_SYMBOL[$level] ?? "❓";
        
        error_log("LOG$level($tag) [$file:$line $func()]: $icon $message");
    }
}

?>
