<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/assigned_call.php';

log_msg(DEBUG_VERBOSE, "Session start - Current session data: " . json_encode($_SESSION));

if (isset($_GET['logout'])) {
	auth_logout();
    exit; // Stop further script execution
}

$session_timeout_minutes = round(ini_get('session.gc_maxlifetime') / 60);

// Who is it, what can they do?
$identify 			= $_SESSION['identify'] 	?? [];
$auth 				= $_SESSION['cactus_auth']	?? [];
$logged_in_call 	= $identify['callsign'] 	?? '';
$logged_in_name 	= $identify['name'] 		?? '';
$edit_authorized 	= (($auth['role'] ?? '') == 'auth');
$browse_authorized 	= (($auth['role'] ?? '') == 'browse');
$admin_authorized 	= ((int)($auth['is_admin'] ?? 0) === 1);

// Initialize variables
$start_date = '';
$end_date = '';
$time_slots = array_keys(TIME_OPTS); // all time slots selected by default
$days_of_week = array_keys(DAY_OPTS); // all days of week selected by default
$bands_list = BANDS_LIST; // all bands selected by default
$modes_list = MODES_LIST; // all modes selected by default

// which button was clicked:
$mine_only = false;
$mine_plus_open =
$complete_calendar = false;
$scheduled_only = false;

$event_start_date = EVENT_START_DATE;
$event_end_date = EVENT_END_DATE;

// collect form data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $time_slots = $_POST['time_slots'] ?? [];
    $days_of_week = $_POST['days_of_week'] ?? [];
    $bands_list = $_POST['bands_list'] ?? [];
    $modes_list = $_POST['modes_list'] ?? [];

	// remember the most recent show button so it can be reused after an add/delete
	// TODO this doesn't work: if (isset($_POST['mine_only']) || isset($_POST['enter_pressed'])) {
	if (isset($_POST['mine_only'])) {
		$mine_only = true;
		$_SESSION['most_recent_show'] = 'mine_only';
	} elseif (isset($_POST['mine_plus_open'])) {
		$mine_plus_open = true;
		$_SESSION['most_recent_show'] = 'mine_plus_open';
	} elseif (isset($_POST['complete_calendar'])) {
		$complete_calendar = true;
		$_SESSION['most_recent_show'] = 'complete_calendar';
	} elseif (isset($_POST['scheduled_only'])) {
		$scheduled_only = true;
		$_SESSION['most_recent_show'] = 'scheduled_only';
	}

    log_msg(DEBUG_VERBOSE, "Incoming POST: " . json_encode($_POST));
	log_msg(DEBUG_INFO, "most_recent_show: " . (isset($_SESSION['most_recent_show']) ? $_SESSION['most_recent_show'] : '(not set)'));
}

$db_conn = get_event_db_connection_from_master(EVENT_NAME);

$table_rows = [];

$club_station_conflict = 0;
$band_mode_conflict = 0;
$required_club_station_missing = 0;

// build the table to be displayed, optionally with add/delete buttons (if authorized)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($edit_authorized && isset($_POST['add_selected']) && isset($_POST['slots'])) {
		log_msg(DEBUG_INFO, "processing schedule add:");
        foreach ($_POST['slots'] as $slot) {
			$assigned_call = null;
            list($date, $time) = explode('|', $slot);
			// $assigned_call = $_POST['assigned_call'][$slot] ?? ''; // assigned call is not an input field
			$band = $_POST['band'][$slot] ?? null;
			$mode = $_POST['mode'][$slot] ?? null;
            $club_station = $_POST['club_station'][$slot] ?? '';
            $notes = $_POST['notes'][$slot] ?? '';
			log_msg(DEBUG_INFO, "\tadd info: date=" . $date . ", time=" . $time . ", call=" . $logged_in_call . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
            // Check club station conflict: more than one op in given date/time/club_station
            if (!empty($club_station)) {
				$club_station_conflict_check = db_check_club_station_in_use($db_conn, $date, $time, $club_station);
                if ($club_station_conflict_check) {
                    $club_station_conflict += 1;
					$_SESSION['club_station_conflict_flash'] = true;
					log_msg(DEBUG_INFO, "club_station_conflict: date=" . $date . ", time=" . $time . ", call=" . $logged_in_call . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
                }
            }
			// For some events (e.g. Field Day) "club_station" selection is required
			if (empty($club_station) && CLUB_STATION_REQUIRED) {
				$_SESSION['required_club_station_missing_flash'] = true;
				log_msg(DEBUG_INFO, "required_club_station_missing: date=" . $date . ", time=" . $time . ", call=" . $logged_in_call . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
				$required_club_station_missing += 1;
			}

            // Check band/mode conflict: more than one op in given date/time/band/mode
            $band_mode_conflict_check = db_check_band_mode_conflict($db_conn, $date, $time, $band, $mode);
			if ($band_mode_conflict_check) {
            	$band_mode_conflict += 1;
				$_SESSION['band_mode_conflict_flash'] = true;
				log_msg(DEBUG_INFO, "band_mode_conflict: date=" . $date . ", time=" . $time . ", call=" . $logged_in_call . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
			}

			if(EVENT_CALLSIGNS_REQUIRED) {
				$assigned_call = choose_assigned_call($date, $time, $logged_in_call, $band, $mode);
			    if ($assigned_call === null) {
					// all calls are in use in this slot, must reject
					$_SESSION['slot_full_flash'] = true;
					log_msg(DEBUG_INFO, "No callsign available for {$date} {$time} (slot full).");
				} else {
					log_msg(DEBUG_VERBOSE, "assigned_call is {$assigned_call} for {$date} {$time} {$logged_in_call}.");
				}
			}

			//TODO unclear why this is based on conflict count rather than just a flag for this row
			if ((!EVENT_CALLSIGNS_REQUIRED || $assigned_call) && !$band_mode_conflict && !$club_station_conflict && !$required_club_station_missing) {
				db_add_schedule_line($db_conn, $date, $time, $logged_in_call, $logged_in_name, $band, $mode, $assigned_call, $club_station, $notes);
			}
        }

		// trigger the display of the schedule asif the most recently used show button
		$mine_only = ($_SESSION['most_recent_show'] === 'mine_only');
		$mine_plus_open = ($_SESSION['most_recent_show'] === 'mine_plus_open');
		$complete_calendar = ($_SESSION['most_recent_show'] === 'complete_calendar');
		$scheduled_only = ($_SESSION['most_recent_show'] === 'scheduled_only');
    }

    if ($edit_authorized && isset($_POST['delete_selected']) && isset($_POST['delete_slots'])) {
		log_msg(DEBUG_INFO, "processing schedule delete of " . json_encode($_POST['delete_slots']));
        foreach ($_POST['delete_slots'] as $slot) {
            list($date, $time, $band, $mode) = explode('|', $slot);
			log_msg(DEBUG_DEBUG, "processing delete: $date $time $band $mode $logged_in_call");
			$deleted = db_delete_schedule_line($db_conn, $date, $time, $band, $mode, $logged_in_call);
			log_msg(DEBUG_DEBUG, "delete result: " . $deleted);
        }

		// trigger the display of the schedule asif the most recently used show button
		$mine_only = ($_SESSION['most_recent_show'] === 'mine_only');
		$mine_plus_open = ($_SESSION['most_recent_show'] === 'mine_plus_open');
		$complete_calendar = ($_SESSION['most_recent_show'] === 'complete_calendar');
		$scheduled_only = ($_SESSION['most_recent_show'] === 'scheduled_only');
    }
	
	if($mine_only) log_msg(DEBUG_INFO, "display schedule with mine_only");
	if($mine_plus_open) log_msg(DEBUG_INFO, "display schedule with mine_plus_open");
	if($complete_calendar) log_msg(DEBUG_INFO, "display schedule with complete_calendar");
	if($scheduled_only) log_msg(DEBUG_INFO, "display schedule with scheduled_only");

	// if any "show" button was pressed
    if ($complete_calendar || $mine_only || $mine_plus_open || $scheduled_only) {
        if ($start_date && !$end_date) $end_date = $start_date;
        if (!$start_date && $end_date) $start_date = $end_date;
        if (!$start_date && !$end_date) {
            $start_date = $event_start_date;
            $end_date = $event_end_date;
        }
		
		log_msg(DEBUG_INFO, "start/end dates: " . $start_date . " " . $end_date);

        $dates = [];
        $cur = strtotime($start_date);
        $end = strtotime($end_date);
        while ($cur <= $end) {
            $day = date('w', $cur);
            if (in_array('all', $days_of_week) || in_array((string)$day, $days_of_week)) {
                $dates[] = date('Y-m-d', $cur);
            }
            $cur = strtotime('+1 day', $cur);
        }

        $times = [];
        if (in_array('all', $time_slots)) {
            foreach (TIMES_BY_SLOT as $block) $times = array_merge($times, $block);
        } else {
            foreach ($time_slots as $slot) {
                if (isset(TIMES_BY_SLOT[$slot])) {
                    $times = array_merge($times, TIMES_BY_SLOT[$slot]);
                }
            }
        }

		log_msg(DEBUG_VERBOSE, "dates[]: " . json_encode($dates));
		log_msg(DEBUG_VERBOSE, "times[]: " . json_encode($times));
		log_msg(DEBUG_VERBOSE, "bands[]: " . json_encode($bands_list));
		log_msg(DEBUG_VERBOSE, "modes[]: " . json_encode($modes_list));

        foreach ($dates as $date) {
            foreach ($times as $time) {
				$r = db_get_schedule_for_date_time($db_conn, $date, $time);
				$none_are_me = true;
				$no_schedule_for_slot = ($r->num_rows === 0);
				while ($r->num_rows > 0 && $row = $r->fetch_assoc()) {
					$op = $row ? strtoupper($row['op_call']) : null;
					$this_is_me = ($op === $logged_in_call);
					if ($this_is_me) $none_are_me = false;
					$name = $row ? $row['op_name'] : null;
					$band = $row ? $row['band'] : null;
					$mode = $row ? $row['mode'] : null;
					log_msg(DEBUG_DEBUG, "Testing schedule line on filters: $date $time $band $mode");
					$assigned_call = $row['assigned_call'] ?? '';
					$club_station = $row['club_station'] ?? '';
					$notes = $row['notes'] ?? '';
					// apply band and mode filters
					if (!in_array($band, $bands_list) || !in_array($mode, $modes_list)) continue;
					// skip open slots when showing only scheduled
					if ($scheduled_only && !$op) continue;  
					// skip lines for other ops when showing only mine
					if (($mine_only || $mine_plus_open) && !$this_is_me) continue;
					// gather what's left to be displayed in the table
					log_msg(DEBUG_DEBUG, "Schedule line was not filtered");
					$table_rows[] = compact('date', 'time', 'band', 'mode', 'assigned_call', 'op', 'name', 'club_station', 'notes');
				}
				// add an unscheduled row if current call is not scheduled in this slot, for the possibility of adding "me",
				// because we can schedule multiple ops in one slot (other mode and/or other special event callsign).
				// Or add an open line for otherwise empty slots when "complete_schedule" is requested.
				if (($mine_plus_open && $none_are_me) || ($complete_calendar && $no_schedule_for_slot)) {
					$assigned_call = $band = $mode = $op = $name = $club_station = $notes = null;
					$table_rows[] = compact('date', 'time', 'band', 'mode', 'assigned_call', 'op', 'name', 'club_station', 'notes');
				}
            }
        }
    }
	log_msg(DEBUG_DEBUG, "Formatting page with result: " . json_encode($table_rows));
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars(EVENT_DISPLAY_NAME) ?> Operator Scheduling</title>
    <link rel="icon" href="img/cropped-RST-Logo-1-32x32.jpg">
	<link rel="stylesheet" href="scheduler.css">

	<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
<div class="header-wrap">
  <img src="img/RST-header-768x144.jpg" alt="Radio Society of Tucson K7RST"
       title="<?= htmlspecialchars(EVENT_DISPLAY_NAME . ' scheduler, version ' . APP_VERSION) ?>" />
  <div class="vertical-label">VER<?= APP_VERSION ?></div>
</div>

<?php
?>

<h2><?php echo htmlspecialchars(EVENT_DISPLAY_NAME) ?> Operator Scheduling 
<a href="how-do-i-use-this.php" target="_blank">(How do I use this?)</a>
<a href="visualizer.php">(Switch to "Visualizer" grid)</a>
</h2>

<?php if (!empty($_SESSION['band_mode_conflict_flash'])): ?>
    <div id="band-mode-conflict-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Band/mode conflict: only one op per date/time/band/mode.
    </div>
<?php unset($_SESSION['band_mode_conflict_flash']); endif; ?>

<?php if (!empty($_SESSION['club_station_conflict_flash'])): ?>
    <div id="club-station-conflict-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Club station conflict: only one op can use a given club station at a time.
    </div>
<?php unset($_SESSION['club_station_conflict_flash']); endif; ?>

<?php if (!empty($_SESSION['required_club_station_missing_flash'])): ?>
    <div id="required-club-station-missing-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Required club station missing: club station selection is required on schedule additions.
    </div>
<?php unset($_SESSION['required_club_station_missing_flash']); endif; ?>

<?php if (!empty($_SESSION['slot_full_flash'])): ?>
    <div id="slot-full-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Slot full: all event callsigns are in use for this time slot.
    </div>
<?php unset($_SESSION['slot_full_flash']); endif; ?>

<form method="POST">
    <div class="section">
		<?php if ($edit_authorized): ?>
			<strong><?= htmlspecialchars($logged_in_call) ?></strong> is logged in.
		<?php else: ?>
			Logged in for browsing only.
		<?php endif; ?>
		<a href="?logout=1" class="logout-button">Log Out</a>
    </div>

	<div class="filter-section">
		<div class="info-icon">
    		&#9432; <!-- Unicode info symbol -->
    		<span class="tooltiptext">
      			Make selections in this box to limit the displayed schedule
				to times, days, bands, and modes of interest. Leave everything
				as defaulted to see the entire schedule.
    		</span>
  		</div>
		<div class="section">
			<strong>Select Date Range to view:</strong><br>
			<label>Start:</label>
			<input type="date" name="start_date" value="<?= htmlspecialchars($start_date ?: $event_start_date) ?>"
				min="<?= htmlspecialchars($event_start_date) ?>" max="<?= htmlspecialchars($event_end_date) ?>" id="start_date" required>
			<label>End:</label>
			<input type="date" name="end_date" value="<?= htmlspecialchars($end_date ?: $start_date ?: $event_end_date) ?>"
				min="<?= htmlspecialchars($event_start_date) ?>" max="<?= htmlspecialchars($event_end_date) ?>" id="end_date" required>
			&nbsp; &nbsp;(Event starts on: <strong><?= htmlspecialchars($event_start_date) ?></strong> Event ends on: <strong><?= htmlspecialchars($event_end_date) ?></strong>)<br>

			<!-- Error message div -->
			<div id="date_error" style="color: red; display: none;">End date cannot be earlier than the start date.</div>
		</div>

		<script>
		document.getElementById("start_date").addEventListener("change", validateDates);
		document.getElementById("end_date").addEventListener("change", validateDates);

		// Automatically default end date to start date if not set
		window.onload = function() {
			var startDate = document.getElementById("start_date").value;
			var endDate = document.getElementById("end_date").value;
			if (!endDate) {
				document.getElementById("end_date").value = startDate;  // Set end date to start date if not specified
			}
			validateDates(); // Run validation after setting default
		};

		function validateDates() {
			// Get the start and end dates
			var startDate = document.getElementById("start_date").value;
			var endDate = document.getElementById("end_date").value;

			// If end date is earlier than start date, show error
			if (new Date(endDate) < new Date(startDate)) {
				document.getElementById("date_error").style.display = "block";
			} else {
				document.getElementById("date_error").style.display = "none";
			}
		}
		</script>
		
		<!-- See JavaScript handlers at the bottom for how dynamic checkbox behavior is handled in these two <div> sections -->
		<div class="section">
			<strong>What parts of the day would you like to view?</strong><br>
			<?php
			foreach (TIME_OPTS as $val => $label): ?>
				<?php if (strtoupper($val) === 'ALL'): ?>
					<label><input type="checkbox" class="select-all" data-group="time_slots" name="time_slots[]" value="<?= $val ?>" <?= in_array($val, $time_slots ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
				<?php else: ?>
					<label><input type="checkbox" name="time_slots[]" value="<?= $val ?>" <?= in_array($val, $time_slots ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
				<?php endif; ?>	
			<?php endforeach; ?>
		</div>

		<div class="section">
		<strong>Which days of the week?</strong><br>
			<?php
			foreach (DAY_OPTS as $val => $label): ?>
				<?php if (strtoupper(strval($val)) === 'ALL'): ?>
					<label><input type="checkbox" class="select-all" data-group="days_of_week" name="days_of_week[]" value="<?= $val ?>" <?= in_array($val, $days_of_week ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
				<?php else: ?>
					<label><input type="checkbox" name="days_of_week[]" value="<?= $val ?>" <?= in_array($val, $days_of_week ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
				<?php endif; ?>	
		<?php endforeach; ?>
		</div>

		<div class="section">
			<strong>Select bands of interest:</strong><br>
			<?php
			foreach (BANDS_LIST as $band): ?>
				<?php if (strtoupper($band) === 'ALL'): ?>
					<label><input type="checkbox" class="select-all" data-group="bands_list" name="bands_list[]" value="<?= $band ?>" <?= in_array($band, $bands_list ?? []) ? 'checked' : '' ?>> <?= $band ?></label>
				<?php else: ?>
					<label><input type="checkbox" name="bands_list[]" value="<?= $band ?>" <?= in_array($band, $bands_list ?? []) ? 'checked' : '' ?>> <?= $band ?></label>
				<?php endif; ?>	
			<?php endforeach; ?>
		</div>

		<div class="section">
			<strong>Select modes of interest:</strong><br>
			<?php
			foreach (MODES_LIST as $mode): ?>
				<?php if (strtoupper($mode) === 'ALL'): ?>
					<label><input type="checkbox" class="select-all" data-group="modes_list" name="modes_list[]" value="<?= $mode ?>" <?= in_array($mode, $modes_list ?? []) ? 'checked' : '' ?>> <?= $mode ?></label>
				<?php else: ?>
					<label><input type="checkbox" name="modes_list[]" value="<?= $mode ?>" <?= in_array($mode, $modes_list ?? []) ? 'checked' : '' ?>> <?= $mode ?></label>
				<?php endif; ?>	
			<?php endforeach; ?>
		</div>
	</div>
	<br><br>

	<!-- See JavaScript handlers at the bottom for how enter key is handled -->
	<div class="section">
    	<strong>Use one of these buttons to choose what to show, filtered by selections above. Then click the checkbox on each row you want to add to or delete from your schedule, and scroll to the bottom to click what action to be taken on selected rows.</strong><br><br>
    	<input type="hidden" name="enter_pressed" value="Enter Pressed">
    	<div class="button-container">
        	<input type="submit" name="complete_calendar" value="Show Complete Calendar (scheduled and open)">
        	<input type="submit" name="scheduled_only" value="Show Scheduled Slots Only">
			<?php if ($edit_authorized): ?>
	        	<input type="submit" name="mine_plus_open" value="Show My Schedule and Open Slots">
    	    	<input type="submit" name="mine_only" value="Show My Schedule Only">
			<?php endif; ?>
    	</div>
	</div>


<?php if ($table_rows): ?> 

	<table border="1" cellpadding="5">
		<tr>
			<th>Select</th><th>Date</th><th>Time</th><th>Assigned Call</th><th>Band</th><th>Mode</th>
			<th>Club Station</th><th>Notes</th><th>Status</th>
		</tr>
		<?php 
		$prev_date_time = ''; 
		$highlight_colors = ['#fff3cd','#cce5ff']; 
		$highlight_color = ''; 
		$highlight_booked_by_you = '#d9fdd3'; 
		$highlight_index = 1; 
		foreach ($table_rows as $r):
			$date = $r['date'];
			$time = $r['time'];
			$assigned_call = $r['assigned_call'];
			$op = $r['op'];
			$name = $r['name'];
			$band = $r['band'];
			$mode = $r['mode'];
			$key = "{$date}|{$time}|{$band}|{$mode}";
			$date_object = new DateTime($date);
			$day_of_week = $date_object->format('D');  // 'D' returns a 3-letter abbreviation for the day
			// Combine the day of the week with the original date
			$formatted_date = $day_of_week . ' ' . $date;
			$date_time = $date . '-' . $time;
			if ($date_time != $prev_date_time) {
				$highlight_index = ($highlight_index + 1) % 2;
				$highlight_color = $highlight_colors[$highlight_index];
				$prev_date_time = $date_time;
			}
			$status = 'Open';
			if ($op) {
				$status = ($op === $logged_in_call) ? 'Booked by you' : "Booked by {$op} {$name}";
			}
		?>
		<tr style="<?= $status === 'Booked by you' ? 'background-color:' . $highlight_booked_by_you : 'background-color:' . $highlight_color ?>">
			<td>
				<?php if ($status === 'Open' && $edit_authorized): ?>
					<input type="checkbox" name="slots[]" value="<?= $key ?>">
				<?php elseif ($status === "Booked by you" && $edit_authorized): ?>
					<input type="checkbox" name="delete_slots[]" value="<?= $key ?>"> Delete
				<?php else: ?>
					--
				<?php endif; ?>
			</td>
			<td><?= $formatted_date ?></td>
			<td><?= $time ?></td>
			<td><?= $assigned_call ?></td>
			<td>
				<?php if ($edit_authorized && $status === 'Open'): ?>
					<!-- Dropdown for Band -->
					<select name="band[<?= $key ?>]">
						<?php foreach ($bands_list as $band): ?>
							<?php if (strtoupper($band) != "ALL"): ?>
								<option value="<?= $band ?>" <?= $band === $r['band'] ? 'selected' : '' ?>><?= $band ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				<?php else: ?>
					<!-- Display fixed band -->
					<?= htmlspecialchars($r['band'] ?? '') ?: '--' ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ($edit_authorized && $status === 'Open'): ?>
					<!-- Dropdown for Mode -->
					<select name="mode[<?= $key ?>]">
						<?php foreach ($modes_list as $mode): ?>
							<?php if (strtoupper($mode) != "ALL"): ?>
								<option value="<?= $mode ?>" <?= $mode === $r['mode'] ? 'selected' : '' ?>><?= $mode ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				<?php else: ?>
					<!-- Display fixed mode -->
					<?= htmlspecialchars($r['mode'] ?? '') ?: '--' ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ($edit_authorized && $status === 'Open'): ?>
					<!-- Dropdown for Club Station -->
					<select name="club_station[<?= $key ?>]">
						<option value="">(Own Station)</option>
						<?php
						$res = db_check_club_station_for_date_time($db_conn, $r['date'], $r['time']);
						$used = [];
						while ($res && $row2 = $res->fetch_assoc()) {
							if (!empty($row2['club_station'])) $used[] = $row2['club_station'];
						}
						foreach (CLUB_STATIONS as $station):
							if (!in_array($station, $used)):
						?>
							<option value="<?= $station ?>" <?= $station === $r['club_station'] ? 'selected' : '' ?>><?= $station ?></option>
						<?php endif; endforeach; ?>
					</select>
				<?php else: ?>
					<!-- Display fixed club station -->
					<?= htmlspecialchars($r['club_station'] ?? '') ?: '--' ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ($edit_authorized && $status === 'Open'): ?>
					<!-- Input for Notes -->
					<input type="text" name="notes[<?= $key ?>]" value="<?= htmlspecialchars($r['notes'] ?? '') ?>" size="20">
				<?php else: ?>
					<!-- Display fixed notes -->
					<?= htmlspecialchars($r['notes'] ?? '') ?: '--' ?>
				<?php endif; ?>
			</td>
			<td><?= $status ?></td>
		</tr>
		<?php endforeach; ?>
	</table>


	<br>
	<?php if ($edit_authorized): ?>
		<input type="submit" name="add_selected" value="Add Selected Slots">
		<input type="submit" name="delete_selected" value="Delete Selected Slots">
	<?php endif; ?>
<?php endif; ?>

</form>

<?php if ($table_rows): ?> 
	<form method="post" action="export_csv.php">
		<input type="hidden" name="op_call" value="<?= htmlspecialchars($logged_in_call) ?>">
		<input type="hidden" name="table_data" value="<?= htmlspecialchars(json_encode($table_rows)) ?>">
		<button type="submit">⬇️ Download CSV</button>
		<button type="button" onclick="window.print()">🖨️ Print Schedule</button>
	</form>
	<form method="post" action="export_ical.php">
	    <input type="hidden" name="table_data" value="<?= htmlspecialchars(json_encode($table_rows)) ?>">
    	<button type="submit">📅 Export iCal</button>
	</form>

<?php endif; ?>

<script>
setTimeout(() => {
    // Hide any/all "-flash" messages after 5 seconds
    const flashElements = document.querySelectorAll('[id$="-flash"]');
    
    // Iterate over the flash elements and hide them after 5 seconds
    flashElements.forEach(flash => {
        flash.style.display = 'none';
    });
}, 5000); // Hide after 5 seconds
</script>

<script>
// dynamic behavior for checkbox lists that include an "all" choice
document.addEventListener('DOMContentLoaded', function() {
	// Apply the behavior to all groups (time_slots, days_of_week, etc.)
	document.querySelectorAll('.select-all').forEach((selectAllCheckbox) => {
		selectAllCheckbox.addEventListener('change', function() {
			const groupName = this.getAttribute('data-group');
			const checkboxes = document.querySelectorAll(`input[name="${groupName}[]"]`);

			// If the 'All' checkbox is checked, check all others
			checkboxes.forEach(cb => {
				cb.checked = this.checked;
			});
		});
	});

	// When any other checkbox is clicked, uncheck 'All'
	document.querySelectorAll('input[type="checkbox"]:not(.select-all)').forEach((checkbox) => {
		checkbox.addEventListener('change', function() {
			const groupName = this.getAttribute('name').replace('[]', ''); // e.g., time_slots or days_of_week
			const allCheckbox = document.querySelector(`input[data-group="${groupName}"].select-all`);

			// If any other checkbox is unchecked, uncheck 'All'
			if (this.checked === false) {
				allCheckbox.checked = false;
			}
		});
	});

 	// Listen for the Enter key press
	document.querySelector('form').addEventListener('keydown', function(event) {
        if (event.key === 'Enter') {
            // Prevent form submission by default
            //event.preventDefault();

            // Set the hidden input field to indicate Enter was pressed
            document.getElementById('enter_pressed').value = 'Enter Pressed';

	        // Optionally, you can trigger a specific submit button based on your logic
            // document.querySelector('input[name="mine_only"]').click(); 
        }
    }); 
});
</script>

</body>
</html>
