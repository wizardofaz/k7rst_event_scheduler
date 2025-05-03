<?php
require_once 'config.php';
require_once 'logging.php';
require_once 'db.php';

ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();

log_msg(DEBUG_VERBOSE, "Session start - Current session data: " . json_encode($_SESSION));

if (isset($_GET['logout'])) {
    log_msg(DEBUG_INFO, "🚪 Logging out...");
    session_destroy();
    log_msg(DEBUG_INFO, "🚪 Session destroyed. POST was: " . json_encode($_POST));

    unset($_POST['op_call']);
    unset($_POST['op_name']);
    unset($_POST['op_password']);

    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
	log_msg(DEBUG_INFO, "Session reset - Current session data after logout: " . json_encode($_SESSION));
    exit;
}

$session_timeout_minutes = round(ini_get('session.gc_maxlifetime') / 60);

// Initialize variables
$op_call_input = '';
$op_name_input = '';
$op_password_input = '';
$authorized = false; // edit operation permitted, not necessarily logged in
$start_date = '';
$end_date = '';
$time_slots = ['all']; // default to all times of the day if not a POST
$days_of_week = ['all']; // default to all days of week if not a POST
$mine_only = false;
$complete_calendar = false;
$scheduled_only = false;
$table_rows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op_call_input = strtoupper(trim($_POST['op_call'] ?? ''));
    $op_name_input = trim($_POST['op_name'] ?? '');
    $op_password_input = trim($_POST['op_password'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $time_slots = $_POST['time_slots'] ?? [];
    $days_of_week = $_POST['days_of_week'] ?? [];

	// remember the most recent show button so it can be reused after an add/delete
	// TODO this doesn't work: if (isset($_POST['mine_only']) || isset($_POST['enter_pressed'])) {
	if (isset($_POST['mine_only'])) {
			$mine_only = true;
		$_SESSION['most_recent_show'] = 'mine_only';
	} elseif (isset($_POST['complete_calendar'])) {
		$complete_calendar = true;
		$_SESSION['most_recent_show'] = 'complete_calendar';
	} elseif (isset($_POST['scheduled_only'])) {
		$scheduled_only = true;
		$_SESSION['most_recent_show'] = 'scheduled_only';
	}

    log_msg(DEBUG_DEBUG, "🧪 Incoming POST: " . json_encode($_POST));
    log_msg(DEBUG_VERBOSE, "🧪 Session authenticated_users: " . json_encode($_SESSION['authenticated_users'] ?? []));
    log_msg(DEBUG_INFO, "🧪 op_call_input: $op_call_input");
	log_msg(DEBUG_VERBOSE, "🧪 most_recent_show: " . $_SESSION['most_recent_show']);
}

$db_conn = db_get_connection();

$event_start_date = EVENT_START_DATE;
$event_end_date = EVENT_END_DATE;
$password_error = '';

if (!isset($_SESSION['authenticated_users'])) {
    $_SESSION['authenticated_users'] = [];
}

// 🔐 Authentication check using operator_passwords table
$requires_authentication = isset($_POST['add_selected']) || isset($_POST['delete_selected']);

// Only check password if it's an operation that requires password authentication
// BUT even those operations only require a password if the operator has given one at least once
$_SESSION['logged_in'] = false;

// do the password and login processing if the operation requires it, or if a password is given
if (($requires_authentication || $op_password_input) && $op_call_input) {  
	// If there is an input password 
	//       if there is a database password 
	//           if input matches database good and done
	//           else fail and done
	//       else good, store new password, done
	// Else (no input password was typed)
	// 		If user is already authenticated we're good (password previously entered and checked)
	//      else if there is no database password we're good (password not required if one has never been entered)
	//		else fail (no password entered but there is one in the database)

    log_msg(DEBUG_INFO, "🔍 Password check triggered for $op_call_input");
	
	// first try to look up the Password
	$stored_pw = db_get_operator_password($db_conn, $op_call_input);

	$db_pw_exists = $stored_pw != '';
	if (!$db_pw_exists) {
		log_msg(DEBUG_INFO, "✅ No non-blank password exists in database for $op_call_input");
	}

	// $_SESSION['login_flash'] = true; // triggers showing flash logged in message (see below)
	// When the logged-in flash message has been displayed, $_SESSION['login_shown'] = true; 
	// will be done by html section
	// $_SESSION['logged_in'] = true indicates actual logged in state, with a password
	if ($op_password_input !== '') { // password was entered
        log_msg(DEBUG_INFO, "✅ Input password given for $op_call_input is $op_password_input");
		if($db_pw_exists) { // there is a non-blank entry in the database password table 
			log_msg(DEBUG_INFO, "✅ Password from database for $op_call_input is $stored_pw");
			if($stored_pw === $op_password_input) { // entered password matches database password 
				$_SESSION['authenticated_users'][$op_call_input] = true;
				$_SESSION['login_flash'] = true;
				$_SESSION['logged_in'] = true;
				$authorized = true;
				log_msg(DEBUG_INFO, "✅ Login flash (good entered pw) for $op_call_input");
			} else { // entered password does not match database password
				$_SESSION['authenticated_users'][$op_call_input] = false;
				$_SESSION['login_flash'] = false;
				$_SESSION['logged_in'] = false;
				$authorized = false;
				$password_error = "Incorrect password for $op_call_input.";
				log_msg(DEBUG_INFO, "✅ Password from database $stored_pw does not match entered password $op_password_input");
			}
		} else { // there is no password in the database for this call 
			// a password was entered but there was none in database ==> this is now the Password
			db_add_password($db_conn, $op_call_input, $op_password_input);
            $_SESSION['authenticated_users'][$op_call_input] = true;
            $_SESSION['login_flash'] = true;
			$_SESSION['logged_in'] = true;
            $authorized = true;
            log_msg(DEBUG_INFO, "✅ Login (new pw) for $op_call_input");
		}
	} else { // no password was entered 
	    if (!empty($_SESSION['authenticated_users'][$op_call_input])) {
			log_msg(DEBUG_INFO, "✅ Already authenticated in session: $op_call_input");
			$authorized = true;
		} elseif (!db_pw_exists) {
			log_msg(DEBUG_INFO, "✅ No password required if one has never been entered for $op_call_input");
			$authorized = true;
		} else { // no password entered but db password exists - fail
			$_SESSION['authenticated_users'][$op_call_input] = false;
			$_SESSION['login_flash'] = false;
			$_SESSION['logged_in'] = false;
			$authorized = false;
			$password_error = "Password required for $op_call_input.";
			log_msg(DEBUG_INFO, "✅ Password from database $stored_pw but password was not entered");
		}
	}
		
}

$table_rows = [];

$club_station_conflict = 0;
$band_mode_conflict = 0;

if (($authorized || !$requires_authentication) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_selected']) && isset($_POST['slots'])) {
		log_msg(DEBUG_INFO, "✅ processing schedule add:");
        foreach ($_POST['slots'] as $slot) {
            list($date, $time) = explode('|', $slot);
			$band = $_POST['band'][$slot] ?? null;
			$mode = $_POST['mode'][$slot] ?? null;
            $club_station = $_POST['club_station'][$slot] ?? '';
            $notes = $_POST['notes'][$slot] ?? '';
			log_msg(DEBUG_INFO, "\tadd info: date=" . $date . ", time=" . $time . ", call=" . $op_call_input . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);

            // Check club station conflict: more than one op in given date/time/club_station
            if (!empty($club_station)) {
				$club_station_conflict_check = db_check_club_station_in_use($db_conn, $date, $time, $club_station);
                if ($club_station_conflict_check) {
                    $club_station_conflict += 1;
					log_msg(DEBUG_INFO, "club_station_conflict: date=" . $date . ", time=" . $time . ", call=" . $op_call_input . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
                }
            }

            // Check band/mode conflict: more than one op in given date/time/band/mode
            $band_mode_conflict_check = db_check_band_mode_conflict($db_conn, $date, $time, $band, $mode);
			if ($band_mode_conflict_check) {
            	$band_mode_conflict += 1;
				$_SESSION['band_mode_conflict_flash'] = true;
				log_msg(DEBUG_INFO, "band_mode_conflict: date=" . $date . ", time=" . $time . ", call=" . $op_call_input . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
			}
			
			if (!$band_mode_conflict && !$club_station_conflict) {
				db_add_schedule_line($db_conn, $date, $time, $op_call_input, $op_name_input, $band, $mode, $club_station, $notes);
            }
        }

		// trigger the display of the schedule asif the most recently used show button
		$mine_only = ($_SESSION['most_recent_show'] === 'mine_only');
		$complete_calendar = ($_SESSION['most_recent_show'] === 'complete_calendar');
		$scheduled_only = ($_SESSION['most_recent_show'] === 'scheduled_only');
    }

    if (isset($_POST['delete_selected']) && isset($_POST['delete_slots'])) {
		log_msg(DEBUG_INFO, "✅ processing schedule delete");
        foreach ($_POST['delete_slots'] as $slot) {
            list($date, $time, $band, $mode) = explode('|', $slot);
			db_delete_schedule_line($db_conn, $date, $time, $band, $mode, $op_call_input);
        }

		// trigger the display of the schedule asif the most recently used show button
		$mine_only = ($_SESSION['most_recent_show'] === 'mine_only');
		$complete_calendar = ($_SESSION['most_recent_show'] === 'complete_calendar');
		$scheduled_only = ($_SESSION['most_recent_show'] === 'scheduled_only');
    }
	
	if($mine_only) log_msg(DEBUG_INFO, "✅ printing schedule with mine_only");
	if($complete_calendar) log_msg(DEBUG_INFO, "✅ printing schedule with complete_calendar");
	if($scheduled_only) log_msg(DEBUG_INFO, "✅ printing schedule with scheduled_only");

	// if any "show" button was pressed
    if ($complete_calendar || $mine_only || $scheduled_only) {
        if ($start_date && !$end_date) $end_date = $start_date;
        if (!$start_date && $end_date) $start_date = $end_date;
        if (!$start_date && !$end_date) {
            $start_date = $event_start_date;
            $end_date = $event_end_date;
        }
		
		log_msg(DEBUG_INFO, "✅ start/end dates: " . $start_date . " " . $end_date);

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
            foreach ($times_by_slot as $block) $times = array_merge($times, $block);
        } else {
            foreach ($time_slots as $slot) {
                if (isset($times_by_slot[$slot])) {
                    $times = array_merge($times, $times_by_slot[$slot]);
                }
            }
        }

		log_msg(DEBUG_VERBOSE, "✅ dates[]: " . json_encode($dates));
		log_msg(DEBUG_VERBOSE, "✅ times[]: " . json_encode($times));

        foreach ($dates as $date) {
            foreach ($times as $time) {
				$r = db_get_schedule_for_date_time($db_conn, $date, $time);
				$none_are_me = true;
				while ($r->num_rows > 0 && $row = $r->fetch_assoc()) {
					$op = $row ? strtoupper($row['op_call']) : null;
					if($op === $op_call_input) $none_are_me = false;
					$name = $row ? $row['op_name'] : null;
					$band = $row ? $row['band'] : null;
					$mode = $row ? $row['mode'] : null;
					$club_station = $row['club_station'] ?? '';
					$notes = $row['notes'] ?? '';

					// skip open slots when showing only scheduled
					if ($scheduled_only && !$op) continue;  
					// skip lines for other ops when showing only mine
					if ($mine_only && $op !== $op_call_input) continue;
					// gather what's left to be displayed in the table
					$table_rows[] = compact('date', 'time', 'band', 'mode', 'op', 'name', 'club_station', 'notes');
				}
				// add an unscheduled row if current call is not scheduled in this slot
				// because we can schedule multiple ops in one slot (other mode and/or other special event callsign)
				if ($complete_calendar && $none_are_me) {
					$band = $mode = $op = $name = $club_station = $notes = null;
					$table_rows[] = compact('date', 'time', 'band', 'mode', 'op', 'name', 'club_station', 'notes');
				}
            }
        }
    }
	log_msg(DEBUG_DEBUG, "Formatting page with result: " . json_encode($table_rows));
}

?>


<!DOCTYPE html>
<html>
<head>
    <title><?php echo EVENT_NAME ?> Operator Schedule Signup</title>
    <link rel="icon" href="img/cropped-RST-Logo-1-32x32.jpg">
	<link rel="stylesheet" href="scheduler.css">

</head>
<body>
<img  src="img/RST-header-768x144.jpg" alt="Radio Society of Tucson K7RST" /><br><br>

<?php
// Only log debugging info after the page content is rendered
if (DEBUG_LEVEL > 0) {trigger_error("Remember to turn off logging when finished debugging (" . CODE_VERSION . ")", E_USER_WARNING);}
?>

<h2><?php echo EVENT_NAME ?> Operator Schedule Signup <a href="how-do-i-use-this.php" target="_blank">(How do I use this?)</a></h2>

<?php if ($password_error): ?>
    <p style="color:red; font-weight:bold;"><?= $password_error ?></p>
<?php endif; ?>

<?php log_msg(DEBUG_INFO, 'Logged-in flash status: ' . ($_SESSION['login_flash'] ?? 'NOT SET')); ?>

<?php if (!empty($_SESSION['login_flash'])): ?>
    <div id="login-flash" style="
        background-color: #d4edda;
        color: #155724;
        padding: 10px;
        border: 1px solid #c3e6cb;
        margin-bottom: 1em;
        border-radius: 4px;
        max-width: 600px;">
        ✅ Password accepted. You're now logged in.
        Your session will remain active for up to <?= $session_timeout_minutes ?> minutes of inactivity.
    </div>
<?php $_SESSION['login_shown'] = true; unset($_SESSION['login_flash']); endif; ?>

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

<form method="POST">
    <div class="section">
        <label><strong>Callsign:</strong></label>
        <input type="text" name="op_call" value="<?= htmlspecialchars($op_call_input) ?>" required>
        <label><strong>Name:</strong></label>
        <input type="text" name="op_name" value="<?= htmlspecialchars($op_name_input) ?>" required>

		<?php if ($_SESSION['logged_in']): ?>
 			<a href="?logout=1" class="logout-button">Log Out</a>
		<?php else: ?>
			<!-- Show password input if not logged in -->
			<label><strong>Password:</strong></label>
			<input type="password" name="op_password" title="Optional. Set on first use. Required afterward.">
		<?php endif; ?>
    </div>

	<div class="section">
		<strong>Event Date Range:</strong> Starts <strong><?= htmlspecialchars($event_start_date) ?></strong> &nbsp;&nbsp;Ends <strong><?= htmlspecialchars($event_end_date) ?></strong></p>
		
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
        <strong>What parts of the day would you like to operate?</strong><br>
        <?php
		// $time_opts defined in config.php
        foreach ($time_opts as $val => $label): ?>
			<?php if ($val == 'all'): ?>
            	<label><input type="checkbox" class="select-all" data-group="time_slots" name="time_slots[]" value="<?= $val ?>" <?= in_array($val, $time_slots ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
			<?php else: ?>
				<label><input type="checkbox" name="time_slots[]" value="<?= $val ?>" <?= in_array($val, $time_slots ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
			<?php endif; ?>	
        <?php endforeach; ?>
    </div>

    <div class="section">
        <strong>Which days of the week?</strong><br>
        <?php
		// $day_opts defined in config.php
        foreach ($day_opts as $val => $label): ?>
			<?php if ($val == 'all'): ?>
            	<label><input type="checkbox" class="select-all" data-group="days_of_week" name="days_of_week[]" value="<?= $val ?>" <?= in_array($val, $days_of_week ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
			<?php else: ?>
				<label><input type="checkbox" name="days_of_week[]" value="<?= $val ?>" <?= in_array($val, $days_of_week ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
			<?php endif; ?>	
        <?php endforeach; ?>
    </div>

	<!-- See JavaScript handlers at the bottom for how enter key is handled -->
    <div class="section">
		<strong>Choose what schedule slots to show (filtered by selections above):</strong><br><br>
		<input type="hidden" name="enter_pressed" value="Enter Pressed">
        <input type="submit" name="complete_calendar" value="Show Complete Calendar (scheduled and open)">
        <input type="submit" name="scheduled_only" value="Show Scheduled Slots Only">
        <input type="submit" name="mine_only" value="Show My Schedule Only">
    </div>

<?php if ($table_rows): ?> 

	<table border="1" cellpadding="5">
		<tr>
			<th>Select</th><th>Date</th><th>Time</th><th>Band</th><th>Mode</th>
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
			$op = $r['op'];
			$name = $r['name'];
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
				$status = ($op === $op_call_input) ? 'Booked by you' : "Booked by {$op} {$name}";
			}
		?>
		<tr style="<?= $status === 'Booked by you' ? 'background-color:' . $highlight_booked_by_you : 'background-color:' . $highlight_color ?>">
			<td>
				<?php if ($status === 'Open'): ?>
					<input type="checkbox" name="slots[]" value="<?= $key ?>">
				<?php elseif ($status === "Booked by you"): ?>
					<input type="checkbox" name="delete_slots[]" value="<?= $key ?>"> Delete
				<?php else: ?>
					--
				<?php endif; ?>
			</td>
			<td><?= $formatted_date ?></td>
			<td><?= $r['time'] ?></td>
			<td>
				<?php if ($status === 'Open'): ?>
					<!-- Dropdown for Band -->
					<select name="band[<?= $key ?>]">
						<?php foreach ($bands_list as $band): ?>
							<option value="<?= $band ?>" <?= $band === $r['band'] ? 'selected' : '' ?>><?= $band ?></option>
						<?php endforeach; ?>
					</select>
				<?php else: ?>
					<!-- Display fixed band -->
					<?= htmlspecialchars($r['band']) ?: '--' ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ($status === 'Open'): ?>
					<!-- Dropdown for Mode -->
					<select name="mode[<?= $key ?>]">
						<?php foreach ($modes_list as $mode): ?>
							<option value="<?= $mode ?>" <?= $mode === $r['mode'] ? 'selected' : '' ?>><?= $mode ?></option>
						<?php endforeach; ?>
					</select>
				<?php else: ?>
					<!-- Display fixed mode -->
					<?= htmlspecialchars($r['mode']) ?: '--' ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ($status === 'Open'): ?>
					<!-- Dropdown for Club Station -->
					<select name="club_station[<?= $key ?>]">
						<option value="">(Own Station)</option>
						<?php
						$res = db_check_club_station_for_date_time($db_conn, $r['date'], $r['time']);
						$used = [];
						while ($res && $row2 = $res->fetch_assoc()) {
							if (!empty($row2['club_station'])) $used[] = $row2['club_station'];
						}
						foreach ($club_stations as $station):
							if (!in_array($station, $used)):
						?>
							<option value="<?= $station ?>" <?= $station === $r['club_station'] ? 'selected' : '' ?>><?= $station ?></option>
						<?php endif; endforeach; ?>
					</select>
				<?php else: ?>
					<!-- Display fixed club station -->
					<?= htmlspecialchars($r['club_station']) ?: '--' ?>
				<?php endif; ?>
			</td>

			<td>
				<?php if ($status === 'Open'): ?>
					<!-- Input for Notes -->
					<input type="text" name="notes[<?= $key ?>]" value="<?= htmlspecialchars($r['notes']) ?>" size="20">
				<?php else: ?>
					<!-- Display fixed notes -->
					<?= htmlspecialchars($r['notes']) ?: '--' ?>
				<?php endif; ?>
			</td>
			<td><?= $status ?></td>
		</tr>
		<?php endforeach; ?>
	</table>


	<br>
	<?php if ($authorized): ?>
		<input type="submit" name="add_selected" value="Add Selected Slots">
		<input type="submit" name="delete_selected" value="Delete Selected Slots">
	<?php endif; ?>
<?php endif; ?>

</form>

<?php if ($table_rows): ?> 
	<form method="post" action="export_csv.php">
		<input type="hidden" name="op_call" value="<?= htmlspecialchars($op_call_input) ?>">
		<button type="submit">⬇️ Download CSV</button>
		<button type="button" onclick="window.print()">🖨️ Print Schedule</button>
	</form>
<?php endif; ?>

<!-- TODO: This function not currently in use but leave for now -->
<!-- <script>
function toggleAll(master, groupName) {
    const checkboxes = document.querySelectorAll(`input[name="${groupName}"]`);
    checkboxes.forEach(cb => {
        if (cb !== master) cb.checked = master.checked;
    });
}
</script>
 -->
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
