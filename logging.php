<?php

define('DEBUG_ERROR', 0);
define('DEBUG_WARNING', 1);
define('DEBUG_INFO', 2);
define('DEBUG_VERBOSE', 3);
define('DEBUG_DEBUG', 4);

define('DEBUG_CODEWORD',["ERROR","WARN","INFO","VERBOSE","DEBUG"]);

define('DEBUG_SYMBOL', ['❌','⚠️','ℹ️','📢','🔍']);

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

        $tag = DEBUG_CODEWORD[$level] ?? "UNKNOWN";
        $icon = DEBUG_SYMBOL[$level] ?? "❓";
        
        error_log("LOG$level($tag) [$file:$line $func()]: $icon $message");
    }
}

?>
