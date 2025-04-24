<?php

require_once 'config.php';
require_once 'logging.php';

ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();

$conn = new mysqli(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$event_start_date = EVENT_START_DATE;
$event_end_date = EVENT_END_DATE;

// First stab at a heat function:

// day heat:
// 4 points for daytime weekends (Sat/Sun 9AM to 8PM)
// 3 points for night weekends (Fri/Sat/Sun after 8, Sat/Sun before 9)
// 2 points for daytime weekdays 
// 1 point for night weekdays
function calculate_coverage($day_of_week, $hour_of_day, $band, $mode) {
	log_msg(DEBUG_INFO, "calculate heat: day=" . $day_of_week . " hour=" . $hour_of_day . " band=" . $band . " mode=" . $mode); 
	log_msg(DEBUG_WARNING, "need to externalize the constants here, think about timezone issues");

    // Daytime check
    $is_daytime = ($hour_of_day >= DAYTIME_START && $hour_of_day <= DAYTIME_END);

    // Day heat calculation
    if ($is_daytime) {
        if ($day_of_week === 0 || $day_of_week === 6) $day_heat = WEEKEND_DAY_HEAT; // Sat/Sun daytime
        elseif ($day_of_week >= 1 && $day_of_week <= 5) $day_heat = WEEKDAY_DAY_HEAT; // weekdays daytime
    } else {
        if (($day_of_week === 5 && $hour_of_day > 20) || $day_of_week === 6 || $day_of_week === 7) $day_heat = WEEKEND_NIGHT_HEAT; // weekend night
        else $day_heat = WEEKDAY_NIGHT_HEAT; // weekday night
    }

    // Band heat calculation
    if ($is_daytime && in_array($band, ["20m", "15m", "10m"])) $band_heat = BAND_HEAT_DAY;
    elseif (!$is_daytime && in_array($band, ["160m", "80m", "40m"])) $band_heat = BAND_HEAT_NIGHT;
    else $band_heat = 0;

    // Mode heat calculation
    if ($mode === "SSB") $mode_heat = 2;
    else $mode_heat = 1;

    // Calculate total coverage score
    return $day_heat + $band_heat + $mode_heat;
}

// Function to check if an operator is scheduled for a particular timeslot (band, mode, time)
function check_operator_scheduled($date, $hour, $band, $mode) {
    global $conn;

    // Query to check if an operator is scheduled for this timeslot and band/mode combination
    $query = "SELECT op_call FROM schedule WHERE date='$date' AND time='$hour' AND band='$band' AND mode='$mode'";
    $result = $conn->query($query);

    // Return the number of operators scheduled in this band/mode for the given timeslot
    return $result ? $result->num_rows : 0; // Return the count of operators scheduled
}

// Initialize matrix and color mapping
$days_matrix = [];
$color_mapping = [];

// Loop through each day in the event and calculate coverage
$event_start_timestamp = strtotime($event_start_date, 0) /* + TIMEZONE_OFFSET_SECONDS */;
$event_end_timestamp = strtotime($event_end_date, 0) /* + TIMEZONE_OFFSET_SECONDS */;
for ($timestamp = $event_start_timestamp; $timestamp <= $event_end_timestamp; $timestamp = strtotime("+1 day", $timestamp)) {
	log_msg(DEBUG_INFO, "calculating heat for " . date('m/d/Y', $timestamp));
    $day_of_week = date('w', $timestamp); // Get day of the week (0-6)
    $total_coverage = 0;

    // Loop through each hour of the day (assuming hourly slots)
    for ($hour = 0; $hour < 24; $hour++) {
        // Loop through each band and mode
        foreach ($bands_list as $band) {
            foreach ($modes_list as $mode) {
                // Check how many operators are scheduled for this band/mode/slot
                $formatted_date = date('Y-m-d', $timestamp);  // Format the date
                $num_operators = check_operator_scheduled($formatted_date, $hour, $band, $mode);

                // If there are operators scheduled for this band/mode combination, calculate the coverage for each one
                if ($num_operators > 0) {
                    // Sum the coverage for each operator scheduled for this timeslot
                    for ($i = 0; $i < $num_operators; $i++) {
                        $coverage = calculate_coverage($day_of_week, $hour, $band, $mode);
						log_msg(DEBUG_INFO, "heat is " . $coverage);
                        $total_coverage += $coverage;
                    }
                } else {
					// log_msg(DEBUG_VERBOSE, "no operators for " . $hour . " " . $band . " " . $mode);
				}
            }
        }
    }

    // Store the total coverage for the day
    $days_matrix[date('Y-m-d', $timestamp)] = $total_coverage;

    // Map the total coverage score to a color (red for low, green for high)
    $color_mapping[date('Y-m-d', $timestamp)] = map_coverage_to_color($total_coverage);
}

// Function to map coverage score to a color
function map_coverage_to_color($coverage) {
    // Simple color mapping based on coverage score
    if ($coverage < 5) {
        return '#FF0000'; // Red
    } elseif ($coverage < 10) {
        return '#FFFF00'; // Yellow
    } else {
        return '#00FF00'; // Green
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Coverage Visualization</title>
    <style>
        .schedule-matrix {
            display: flex;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .day {
            width: 60px;
            height: 60px;
            margin: 5px;
            text-align: center;
            color: white;
            padding-top: 15px;
            font-size: 12px;
            cursor: pointer;
        }
        .day-link {
            color: white;
            text-decoration: none;
        }
        .day:hover {
            opacity: 0.8;
        }
    </style>
</head>
<body>

<h2>Schedule Coverage Visualization</h2>

<!-- Display the matrix of days -->
<div class="schedule-matrix">
    <?php foreach ($days_matrix as $date => $coverage): ?>
        <div class="day" style="background-color: <?= $color_mapping[$date] ?>;">
            <a href="expand_day.php?date=<?= urlencode($date) ?>" class="day-link">
                <?= date('M d', strtotime($date)) ?>
            </a>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>
