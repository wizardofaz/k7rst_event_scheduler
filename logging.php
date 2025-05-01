<?php
define('DEBUG_ERROR', 0);
define('DEBUG_WARNING', 1);
define('DEBUG_INFO', 2);
define('DEBUG_VERBOSE', 3);
define('DEBUG_DEBUG', 4);

function log_msg($level,$message) {
    if (DEBUG_LEVEL >= $level) {
        error_log("LOG".$level.": ".$message);
    }
}
?>
