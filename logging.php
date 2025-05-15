<?php
require_once 'config.php';

define('DEBUG_ERROR', 0);
define('DEBUG_WARNING', 1);
define('DEBUG_INFO', 2);
define('DEBUG_VERBOSE', 3);
define('DEBUG_DEBUG', 4);


function log_msg($level,$message) {

    if (defined('DEBUG_LEVEL')) {
        // set debug level from GET if present, else from DEBUG_LEVEL (always present from config.php)
        if (!isset($_SESSION['debug_level'])) $_SESSION['debug_level'] = DEBUG_LEVEL; // from config.php
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['debug'])) $_SESSION['debug_level'] = (int)$_GET['debug']; 

        if (DEBUG_LEVEL > 0 && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_SESSION['debugging_warning_done'])) {
            $_SESSION['debugging_warning_done'] = true;
            trigger_error("Remember to set DEBUG_LEVEL to 0 in config.php when finished debugging (" . CODE_VERSION . ")", E_USER_WARNING);
        }
    }

    if ($_SESSION['debug_level'] >= $level) {
        error_log("LOG".$level.": ".$message);
    }
}

?>
