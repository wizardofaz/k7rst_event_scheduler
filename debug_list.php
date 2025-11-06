<?php
// controls which files produce debugging output. Enable any or all.
//define('DEBUG_ONLY_GLOB', ['*']); // to enable all debug logging

define('DEBUG_ONLY_FILES', ['scheduler.php']); // list of specific files to include
//define('DEBUG_ONLY_GLOB', ['*scheduler*.php']); // wildcard specs for files to include
//define('DEBUG_ONLY_REGEX', ['#/(index|scheduler)\.php$#']); // regex specs for files to include
