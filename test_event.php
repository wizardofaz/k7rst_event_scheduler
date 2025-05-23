<?php
// test_event.php — basic test to verify dynamic config loading

require_once 'config.php';
require_once 'master.php';
require_once 'event_db.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Event Config: <?= htmlspecialchars(EVENT_DISPLAY_NAME) . "(" . htmlspecialchars(EVENT_NAME) . ")"?></title>
    <meta charset="UTF-8">
</head>
<body>
    <h1>Testing Event Config for <?= htmlspecialchars(EVENT_DISPLAY_NAME) ?>(<?= htmlspecialchars(EVENT_NAME) ?>)</h1>

    <h2>Defined Constants</h2>
    <ul>
    <?php
    foreach (get_defined_constants(true)['user'] as $key => $value) {
        if (strpos($key, 'EVENT') === 0 || strpos($key, 'BAND') === 0 || strpos($key, 'MODE') === 0 || strpos($key, 'TIME') === 0 || strpos($key, 'DAY') === 0 || strpos($key, 'CLUB') === 0) {
            echo "<li><strong>$key</strong>: <pre>" . htmlspecialchars(print_r($value, true)) . "</pre></li>";
        }
    }
    ?>
    </ul>
    <h2>Event databases</h2>
    <ul>
    <?php
    foreach (list_events_from_master_with_status() as $event) {
        echo "<li>".htmlspecialchars($event['event_name']) . ": " . get_status_label($event['status'])."</li>";
    }
    ?>
    </ul>
</body>
</html>
