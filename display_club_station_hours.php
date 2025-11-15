<?php
// display_club_station_hours.php

declare(strict_types=1);

// Adjust includes to match your project layout
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logging.php';

// If you have a common header/footer, you could include them instead.
// For now, keep this file standalone and simple.

$is_popup = isset($_GET['popup']) && $_GET['popup'] === '1';

log_msg(DEBUG_DEBUG, "CLUB_STATION_HOURS:\n" . json_encode(CLUB_STATION_HOURS, JSON_PRETTY_PRINT));

$stations = [];
$error_message = '';

// CLUB_STATION_HOURS is already an array by the time it is defined
if (!defined('CLUB_STATION_HOURS') || !is_array(CLUB_STATION_HOURS)) {
    $error_message = 'No club station hours are defined for this event.';
} else {
    $stations = CLUB_STATION_HOURS;
}

// Sort stations by name (key)
if ($stations) {
    ksort($stations, SORT_NATURAL | SORT_FLAG_CASE);
}

// Helper to format one window row
function format_window_row(array $window, ?string $tz_string): array
{
    $date_str = '';
    $time_range = '';

    if (!isset($window['start'], $window['end'])) {
        return [$date_str, $time_range];
    }

    $tz = null;
    if ($tz_string) {
        try {
            $tz = new DateTimeZone($tz_string);
        } catch (Exception $e) {
            $tz = null;
        }
    }

    try {
        $start = $tz
            ? new DateTime($window['start'], $tz)
            : new DateTime($window['start']);
        $end = $tz
            ? new DateTime($window['end'], $tz)
            : new DateTime($window['end']);

        // Example: Fri 2025-11-21
        $date_str = $start->format('D Y-m-d');
        // Example: 14:00–19:00 (24-hour)
        $time_range = $start->format('H:i') . '–' . $end->format('H:i');
    } catch (Exception $e) {
        // Fallback to raw strings if parsing fails
        $date_str = htmlspecialchars((string)$window['start'], ENT_QUOTES, 'UTF-8');
        $time_range = htmlspecialchars((string)$window['end'], ENT_QUOTES, 'UTF-8');
    }

    return [$date_str, $time_range];
}

// For each station, sort windows by start time if possible
foreach ($stations as $name => &$station) {
    if (!isset($station['windows']) || !is_array($station['windows'])) {
        $station['windows'] = [];
        continue;
    }

    usort($station['windows'], function ($a, $b): int {
        $sa = $a['start'] ?? '';
        $sb = $b['start'] ?? '';
        if ($sa === $sb) return 0;
        return $sa < $sb ? -1 : 1;
    });
}
unset($station);

// Event title helpers
$event_title = '';
if (defined('EVENT_DISPLAY_NAME') && EVENT_DISPLAY_NAME !== '') {
    $event_title = EVENT_DISPLAY_NAME;
} elseif (defined('EVENT_NAME') && EVENT_NAME !== '') {
    $event_title = EVENT_NAME;
} else {
    $event_title = 'Club Station Hours';
}

$page_title = $event_title . ' – Club Station Open Hours';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 14px;
            margin: <?= $is_popup ? '10px' : '20px'; ?>;
            background-color: #fdfdfd;
        }

        h1 {
            font-size: 20px;
            margin: 0 0 4px 0;
        }

        .subtitle {
            font-size: 13px;
            color: #555;
            margin-bottom: 12px;
        }

        .station-block {
            margin-bottom: 18px;
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background-color: #ffffff;
        }

        .station-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 6px;
        }

        .station-name {
            font-weight: bold;
            font-size: 15px;
        }

        .station-tz {
            font-size: 12px;
            color: #666;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            max-width: 600px;
            font-size: 13px;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background-color: #f0f0f0;
        }

        .no-windows {
            font-style: italic;
            color: #666;
            font-size: 13px;
        }

        .error-message {
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #c00;
            background-color: #fee;
            color: #800;
            max-width: 600px;
        }

        .footer-note {
            margin-top: 12px;
            font-size: 12px;
            color: #666;
        }

        <?php if ($is_popup): ?>
        .close-btn {
            margin-bottom: 10px;
        }
        <?php endif; ?>
    </style>
</head>
<body class="<?= $is_popup ? 'popup' : 'standalone' ?>">

<?php if ($is_popup): ?>
    <button type="button" class="close-btn" onclick="window.close();">Close</button>
<?php endif; ?>

<h1>Club Station Open Hours</h1>
<div class="subtitle">
    Event: <?= htmlspecialchars($event_title, ENT_QUOTES, 'UTF-8') ?><br>
    All times shown are in each station&#8217;s local time zone.
</div>

<?php if ($error_message !== ''): ?>
    <div class="error-message">
        <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php else: ?>

    <?php foreach ($stations as $name => $station): ?>
        <?php
        $station_name = (string)$name;
        $station_title = isset($station['title']) && is_string($station['title']) ? $station['title'] : ''; 
        $tz_string = isset($station['tz']) && is_string($station['tz']) ? $station['tz'] : '';
        $windows = $station['windows'] ?? [];
        ?>
        <div class="station-block">
            <div class="station-header">
                <div class="station-name">
                    <?= htmlspecialchars($station_name . ': ' . $station_title, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if ($tz_string !== ''): ?>
                    <div class="station-tz">
                        Time zone: <?= htmlspecialchars($tz_string, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($windows)): ?>
                <div class="no-windows">
                    No open windows configured for this station.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Local Time Window</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($windows as $window): ?>
                        <?php
                        [$date_str, $time_range] = format_window_row(
                            is_array($window) ? $window : [],
                            $tz_string
                        );
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($time_range, ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<div class="footer-note">
    Tip: Use this list to see when club stations are available, then return to the scheduler to book your operating time.
</div>

</body>
</html>
