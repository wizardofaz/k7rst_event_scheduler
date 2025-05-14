<?php
// visualizer.php

session_start();
require_once 'config.php';
require_once 'db.php';
require_once 'login.php';
require_once 'logging.php';

$conn = db_get_connection();

// Handle login and logout
$authorized = false;
$op_call = strtoupper($_POST['call'] ?? ($_SESSION['logged_in_call'] ?? ''));
$op_name = $_POST['name'] ?? ($_SESSION['logged_in_name'] ?? '');
$op_pw = $_POST['password'] ?? '';
if (isset($_POST['logout'])) {
    unset($_SESSION['authenticated_users']);
    unset($_SESSION['logged_in_call']);
    $op_call = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $op_call) {
    $authorized = login($conn, $op_call, $op_name, $op_pw);
} elseif (isset($_SESSION['authenticated_users'][$op_call]) && $_SESSION['authenticated_users'][$op_call]) {
    $authorized = true;
}

// Handle max score input
$userMaxScore = isset($_GET['max_score']) ? (int)$_GET['max_score'] : 10;
if ($userMaxScore <= 0) $userMaxScore = 10;

// Score function gives a number indicating how well covered a given hour is
function score($date, $time, $entries) {
    $score = 0;
    $hour = (int)substr($time, 0, 2);
    $isDaytime = ($hour >= DAYTIME_START && $hour < DAYTIME_END);
    $weekday = date('w', strtotime($date));
    $isWeekend = ($weekday == 0 || $weekday == 6);

    $bands = [];
    $modes = [];
    foreach ($entries as $e) {
        $band = $e['band'];
        $mode = $e['mode'];
        $bands[$band] = true;
        $modes[$mode] = true;
        if ($isDaytime && $isWeekend)      $score += WEEKEND_DAY_HEAT;
        elseif ($isDaytime && !$isWeekend) $score += WEEKDAY_DAY_HEAT;
        elseif (!$isDaytime && $isWeekend) $score += WEEKEND_NIGHT_HEAT;
        else                               $score += WEEKDAY_NIGHT_HEAT;
        // TODO: BAND_HEAT needs to be modulated by which bands are scheduled
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
echo "<html><head><title>" . EVENT_NAME . " Visualizer</title>
    <link rel=\"icon\" href=\"img/cropped-RST-Logo-1-32x32.jpg\">
    <link rel=\"stylesheet\" href=\"visualizer.css\">
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

echo "<img src=\"img/RST-header-768x144.jpg\" alt=\"Radio Society of Tucson K7RST\" "
	. "title=\"<?= htmlspecialchars(EVENT_NAME . \" scheduler, version \" . APP_VERSION) ?>\" />";

echo "<h2>" . EVENT_NAME . " Schedule Visualizer <a href=\"scheduler.php\">(Switch to tabular view)</a></h2>";

if ($authorized) {
    echo "<form method='post' style='display:inline;'>
    <strong>$op_call</strong> logged in <button name='logout'>Logout</button>
    </form><br><br>";
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
        log_msg(DEBUG_DEBUG, "SCORE: $rowDate $rowTime $s " . json_encode($entries));
        $norm = min(1.0, $s / max(1, $userMaxScore));
        $color = score_to_color($norm);

        $hasUser = false;
        $quick_info = $month . '-' . $day . ' ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';
        if (count($entries) == 0) $quick_info .= ' (open)';
        foreach ($entries as $e) {
            if ($e['op_call'] && $e['op_call'] !== '') {
                $quick_info .= ' / ' . $e['op_call'] . ' ' . $e['band'] . ' ' . $e['mode'] . ' ' . $e['club_station'];
            }
                
            if ($authorized && strtolower($e['op_call']) === strtolower($op_call)) {
                $hasUser = true;
            }
        }
        $highlight = $hasUser ? 'box-shadow: inset 0 0 0 2px orange;' : '';

        echo "<td style='background-color:$color; $highlight' "
            . "title=\"$quick_info\" "
            . "data-date='$rowDate' data-time='$rowTime'></td>";
    }
    echo "</tr>";
}
echo "</table>";

// Popup container
echo "<div id=\"popup\">
  <div id=\"popup-header\">
    Edit Slot
    <span id=\"popup-close\">&times;</span>
  </div>
  <div id=\"popup-content\"></div>
</div>";

echo "</body></html>";
?>
<script>
const popup = document.getElementById("popup");
const header = document.getElementById("popup-header");

document.getElementById('popup-close').addEventListener('click', closePopup);

let offsetX = 0, offsetY = 0, isDragging = false;

header.addEventListener("mousedown", (e) => {
    isDragging = true;
    const rect = popup.getBoundingClientRect();
    offsetX = e.clientX - rect.left;
    offsetY = e.clientY - rect.top;
    document.body.style.userSelect = "none"; // prevent text selection while dragging
});

document.addEventListener("mousemove", (e) => {
    if (isDragging) {
        popup.style.left = e.clientX - offsetX + "px";
        popup.style.top = e.clientY - offsetY + "px";
        popup.style.transform = "none"; // disable centering while dragging
    }
});

document.addEventListener("mouseup", () => {
    isDragging = false;
    document.body.style.userSelect = "auto";
});

function addEntry(form) {
    const data = new FormData(form);
    fetch('add_entry.php', {
        method: 'POST',
        body: data
    }).then(res => res.text()).then(msg => {
        if (msg === 'OK') location.reload();
        else alert(msg);
    });
    return false;
}

function deleteEntry(form) {
    const data = new FormData(form);
    fetch('delete_entry.php', {
        method: 'POST',
        body: data
    }).then(res => res.text()).then(msg => {
        if (msg === 'OK') location.reload();
        else alert(msg);
    });
    return false;
}

function updateEntry(form) {
    const formData = new FormData(form);
    fetch('update_entry.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Re-fetch slot_edit.php with original date & time
            const date = form.querySelector('[name="date"]').value;
            const time = form.querySelector('[name="time"]').value;
            showPopup(date, time);
        } else {
            alert("Update failed");
        }
    })
    .catch(err => {
        alert("Error: " + err.message);
    });
    return false;
}

</script>
