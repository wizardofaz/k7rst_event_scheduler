<?php
// visualizer.php

session_start();
require_once 'config.php';
require_once 'db.php';
require_once 'login.php';

$conn = db_get_connection();

// Handle login
$authorized = false;
$op_call = $_POST['call'] ?? '';
$op_name = $_POST['name'] ?? '';
$op_pw = $_POST['password'] ?? '';
if ($op_call) {
    $authorized = login($conn, $op_call, $op_pw);
}

// Handle max score input
$userMaxScore = isset($_GET['max_score']) ? (int)$_GET['max_score'] : 10;
if ($userMaxScore <= 0) $userMaxScore = 10;

// --- Score function ---
function score($date, $time, $entries) {
    $score = 0;
    $hour = (int)substr($time, 0, 2);
    $isDaytime = ($hour >= DAYTIME_START && $hour < DAYTIME_END);
    $weekday = date('w', strtotime($date));
    $isWeekend = ($weekday == 0 || $weekday == 6);

    if ($isDaytime && $isWeekend)      $score += WEEKEND_DAY_HEAT;
    elseif ($isDaytime && !$isWeekend) $score += WEEKDAY_DAY_HEAT;
    elseif (!$isDaytime && $isWeekend) $score += WEEKEND_NIGHT_HEAT;
    else                               $score += WEEKDAY_NIGHT_HEAT;

    $bands = [];
    $modes = [];
    foreach ($entries as $e) {
        $bands[$e['band']] = true;
        $modes[$e['mode']] = true;
        $score += $isDaytime ? BAND_HEAT_DAY : BAND_HEAT_NIGHT;
    }
    $score += count($bands);
    $score += count($modes);
    return $score;
}

function score_to_color($norm) {
    $start = [224, 247, 255];
    $end = [21, 101, 192];
    $rgb = [];
    for ($i = 0; $i < 3; $i++) {
        $rgb[$i] = (int)round($start[$i] + ($end[$i] - $start[$i]) * $norm);
    }
    return sprintf("rgb(%d,%d,%d)", $rgb[0], $rgb[1], $rgb[2]);
}

// --- HTML output ---
echo "<html><head><title>CACTUS Visualizer</title><style>
    table.coverage-grid { border-collapse: collapse; margin-top: 1em; }
    table.coverage-grid td {
  width: 24px;
  height: 24px;
  cursor: pointer;
  box-sizing: border-box;
  padding: 1px;
  box-shadow: inset 0 0 0 2px #999;
}
table.coverage-grid td.blank-cell {
  box-shadow: none;
  background-color: transparent;
}
    #popup { display: none; position: fixed; top: 10%; left: 10%; width: 80%; background: #fff; border: 1px solid #888; padding: 1em; box-shadow: 0 0 10px rgba(0,0,0,0.5); z-index: 1000; }
    #popup-close { float: right; cursor: pointer; font-weight: bold; }
</style>
<script>
function showPopup(date, time) {
    fetch(`slot_edit.php?date=\${date}&time=\${time}`)
        .then(res => res.text())
        .then(html => {
            document.getElementById('popup-content').innerHTML = html;
            document.getElementById('popup').style.display = 'block';
        });
}

function closePopup() {
    document.getElementById('popup').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('td[data-date]').forEach(td => {
        td.addEventListener('click', (event) => {
            event.stopPropagation(); // prevent immediate outside-close
            const d = td.getAttribute('data-date');
            const t = td.getAttribute('data-time');
            showPopup(d, t);
        });
    });

    document.addEventListener('click', function(event) {
        const popup = document.getElementById('popup');
        if (popup.style.display === 'block' && !popup.contains(event.target)) {
            popup.style.display = 'none';
        }
    });
});
</script>
</head><body>";

echo "<h2>CACTUS Schedule Visualizer</h2>";

if ($authorized) {
    echo "<form method='post'><strong>$op_call</strong> logged in <button name='logout'>Logout</button></form>";
} else {
    echo "<form method='post'>
        Call: <input name='call' value='" . htmlspecialchars($op_call) . "'>
        Name: <input name='name' value='" . htmlspecialchars($op_name) . "'>
        Password: <input name='password' type='password'>
        <button type='submit'>Login</button>
    </form>";
}

echo "<form method='get'>
    Normalize scores to max: <input name='max_score' value='$userMaxScore' size='4'>
    <button type='submit'>Apply</button>
</form>";

$startDate = new DateTime(EVENT_START_DATE);
$endDate = new DateTime(EVENT_END_DATE);
$interval = new DateInterval('P1D');
$period = new DatePeriod($startDate, $interval, $endDate->modify('+1 day'));

echo "<table class='coverage-grid'>";

// Header row with hours
echo "<tr><td class='blank-cell'></td>";
for ($hour = 0; $hour < 24; $hour++) {
    $label = ($hour % 6 === 0) ? sprintf("%02d00", $hour) : '';
    echo "<th style='width: 32px;'>$label</th>";
}
echo "</tr>";

foreach ($period as $dateObj) {
    $rowDate = $dateObj->format('Y-m-d');
    $weekday = date('D', strtotime($rowDate));
    list($year, $month, $day) = explode('-', $rowDate);
echo "<tr><th style='text-align: right; padding-right: 6px; min-width: 60px;'>$weekday<br>$month-$day</th>";
    for ($hour = 0; $hour < 24; $hour++) {
        $rowTime = sprintf("%02d:00:00", $hour);
        $result = db_get_schedule_for_date_time($conn, $rowDate, $rowTime);
        $entries = [];
        if ($result) while ($row = $result->fetch_assoc()) $entries[] = $row;
        $s = score($rowDate, $rowTime, $entries);
        $norm = min(1.0, $s / max(1, $userMaxScore));
        $color = score_to_color($norm);

        $hasUser = false;
        foreach ($entries as $e) {
            if ($authorized && strtolower($e['op_call']) === strtolower($op_call)) {
                $hasUser = true;
                break;
            }
        }
        $highlight = $hasUser ? 'box-shadow: inset 0 0 0 2px red;' : '';

        echo "<td style='background-color:$color; $highlight' title=\"$rowDate $hour:00
Score: $s\" data-date='$rowDate' data-time='$rowTime'></td>";
    }
    echo "</tr>";
}
echo "</table>";

// Popup container
echo "<div id='popup'><div id='popup-close' onclick='closePopup()'>X</div><div id='popup-content'>Loading...</div></div>";

echo "</body></html>";
?>
