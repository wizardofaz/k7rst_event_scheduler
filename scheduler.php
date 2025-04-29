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
$bands = [];
$modes = [];
$start_date = '';
$end_date = '';
$time_slots = [];
$days_of_week = [];
$mine_only = false;
$all_available = false;
$all_scheduled = false;
$result = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op_call_input = strtoupper(trim($_POST['op_call'] ?? ''));
    $op_name_input = trim($_POST['op_name'] ?? '');
    $op_password_input = trim($_POST['op_password'] ?? '');
    $bands = $_POST['bands'] ?? [];
    $modes = $_POST['modes'] ?? [];
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $time_slots = $_POST['time_slots'] ?? [];
    $days_of_week = $_POST['days_of_week'] ?? [];

	// remember the most recent show button so it can be reused after an add/delete
	if (isset($_POST['mine_only'])) {
		$mine_only = true;
		$_SESSION['most_recent_show'] = 'mine_only';
	} elseif (isset($_POST['all_available'])) {
		$all_available = true;
		$_SESSION['most_recent_show'] = 'all_available';
	} elseif (isset($_POST['all_scheduled'])) {
		$all_scheduled = true;
		$_SESSION['most_recent_show'] = 'all_scheduled';
	}

    log_msg(DEBUG_VERBOSE, "🧪 Incoming POST: " . json_encode($_POST));
    log_msg(DEBUG_VERBOSE, "🧪 Session authenticated_users: " . json_encode($_SESSION['authenticated_users'] ?? []));
    log_msg(DEBUG_INFO, "🧪 op_call_input: $op_call_input");
	log_msg(DEBUG_VERBOSE, "🧪 most_recent_show: " . $_SESSION['most_recent_show']);
}

$conn = new mysqli(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

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
	$stored_pw = db_get_operator_password($conn, $op_call_input);

	$db_pw_exists = $stored_pw != '';
	if (!$db_pw_exists) {
		log_msg(DEBUG_INFO, "✅ No non-blank password exists in database for $op_call_input");
	}

	// $_SESSION['login_success'] = true; // triggers showing flash logged in message (see below)
	// When the flash has been displayed, $_SESSION['login_shown'] = true; will be done by html section
	// $_SESSION['logged_in'] = true indicates actual logged in state, with a password
	if ($op_password_input !== '') { // password was entered
        log_msg(DEBUG_INFO, "✅ Input password given for $op_call_input is $op_password_input");
		if($db_pw_exists) { // there is a non-blank entry in the database password table 
			log_msg(DEBUG_INFO, "✅ Password from database for $op_call_input is $stored_pw");
			if($stored_pw === $op_password_input) { // entered password matches database password 
				$_SESSION['authenticated_users'][$op_call_input] = true;
				$_SESSION['login_success'] = true;
				$_SESSION['logged_in'] = true;
				$authorized = true;
				log_msg(DEBUG_INFO, "✅ Login success (good entered pw) for $op_call_input");
			} else { // entered password does not match database password
				$_SESSION['authenticated_users'][$op_call_input] = false;
				$_SESSION['login_success'] = false;
				$_SESSION['logged_in'] = false;
				$authorized = false;
				$password_error = "Incorrect password for $op_call_input.";
				log_msg(DEBUG_INFO, "✅ Password from database $stored_pw does not match entered password $op_password_input");
			}
		} else { // there is no password in the database for this call 
			// a password was entered but there was none in database ==> this is now the Password
            $conn->query("INSERT INTO operator_passwords (op_call, op_password) VALUES ('$op_call_input', '$op_password_input')");
            $_SESSION['authenticated_users'][$op_call_input] = true;
            $_SESSION['login_success'] = true;
			$_SESSION['logged_in'] = true;
            $authorized = true;
            log_msg(DEBUG_INFO, "✅ Login success (new pw) for $op_call_input");
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
			$_SESSION['login_success'] = false;
			$_SESSION['logged_in'] = false;
			$authorized = false;
			$password_error = "Password required for $op_call_input.";
			log_msg(DEBUG_INFO, "✅ Password from database $stored_pw but password was not entered");
		}
	}
		
}

$result = [];

if (($authorized || !$requires_authentication) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_selected']) && isset($_POST['slots'])) {
		log_msg(DEBUG_INFO, "✅ processing schedule add");
        foreach ($_POST['slots'] as $slot) {
            $club_station = $_POST['club_station'][$slot] ?? '';
            $notes = $_POST['notes'][$slot] ?? '';
            list($date, $time, $band, $mode) = explode('|', $slot);

            // Check for same band/mode slot
            $check = $conn->query("SELECT id FROM schedule WHERE date='$date' AND time='$time' AND band='$band' AND mode='$mode'");
            // Check for exclusive club station
            $conflict = false;
            if (!empty($club_station)) {
                $q = "SELECT id FROM schedule WHERE date='$date' AND time='$time' AND club_station='$club_station'";
                $conflict_check = $conn->query($q);
                if ($conflict_check && $conflict_check->num_rows > 0) {
                    $conflict = true;
                }
            }

            if ($check && $check->num_rows === 0 && !$conflict) {
                $stmt = $conn->prepare("INSERT INTO schedule (date, time, op_call, op_name, band, mode, club_station, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssss", $date, $time, $op_call_input, $op_name_input, $band, $mode, $club_station, $notes);
                $stmt->execute();
                $stmt->close();
            }
        }

		// trigger the display of the schedule asif the most recently used show button
		$mine_only = ($_SESSION['most_recent_show'] === 'mine_only');
		$all_available = ($_SESSION['most_recent_show'] === 'all_available');
		$all_scheduled = ($_SESSION['most_recent_show'] === 'all_scheduled');
    }

    if (isset($_POST['delete_selected']) && isset($_POST['delete_slots'])) {
		log_msg(DEBUG_INFO, "✅ processing schedule delete");
        foreach ($_POST['delete_slots'] as $slot) {
            list($date, $time, $band, $mode) = explode('|', $slot);
            $conn->query("DELETE FROM schedule WHERE date='$date' AND time='$time' AND band='$band' AND mode='$mode' AND op_call='$op_call_input'");
        }

		// trigger the display of the schedule asif the most recently used show button
		$mine_only = ($_SESSION['most_recent_show'] === 'mine_only');
		$all_available = ($_SESSION['most_recent_show'] === 'all_available');
		$all_scheduled = ($_SESSION['most_recent_show'] === 'all_scheduled');
    }
	
	if($mine_only) log_msg(DEBUG_INFO, "✅ printing schedule with mine_only");
	if($all_available) log_msg(DEBUG_INFO, "✅ printing schedule with all_available");
	if($all_scheduled) log_msg(DEBUG_INFO, "✅ printing schedule with all_scheduled");

    if ($all_available || $mine_only || $all_scheduled) {
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

        $times_by_slot = [
            'midnight_to_6' => ['00:00:00','01:00:00','02:00:00','03:00:00','04:00:00','05:00:00'],
            '6_to_noon' => ['06:00:00','07:00:00','08:00:00','09:00:00','10:00:00','11:00:00'],
            'noon_to_6' => ['12:00:00','13:00:00','14:00:00','15:00:00','16:00:00','17:00:00'],
            '6_to_midnight' => ['18:00:00','19:00:00','20:00:00','21:00:00','22:00:00','23:00:00']
        ];

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
		log_msg(DEBUG_VERBOSE, "✅ bands[]: " . json_encode($bands));
		log_msg(DEBUG_VERBOSE, "✅ modes[]: " . json_encode($modes));

        foreach ($dates as $date) {
            foreach ($times as $time) {
                foreach ($bands as $band) {
                    foreach ($modes as $mode) {
                        $r = $conn->query("SELECT op_call, op_name, club_station, notes FROM schedule WHERE date='$date' AND time='$time' AND band='$band' AND mode='$mode'");
                        $row = ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
                        $op = $row ? strtoupper($row['op_call']) : null;
						$name = $row ? $row['op_name'] : null;
                        $club_station = $row['club_station'] ?? '';
                        $notes = $row['notes'] ?? '';

                        if ($all_scheduled && !$op) continue;  // skip open slots when showing only scheduled
                        if ($mine_only && $op !== $op_call_input) continue;
                        if (!$mine_only && !$all_scheduled && $op !== null && $op !== $op_call_input) continue;
                        $result[] = compact('date', 'time', 'band', 'mode', 'op', 'name', 'club_station', 'notes');
                    }
                }
            }
        }
    }
	log_msg(DEBUG_VERBOSE, "Formatting page with result: " . json_encode($result));
}

?>


<!DOCTYPE html>
<html>
<head>
    <title>CACTUS Operator Schedule Signup</title>
    <link rel="icon" href="img/cropped-RST-Logo-1-32x32.jpg">
	<link rel="stylesheet" href="scheduler.css">

</head>
<body>
<img  src="img/RST-header-768x144.jpg" alt="Radio Society of Tucson K7RST" /><br><br>
<?php
// Only log debugging info after the page content is rendered
trigger_error("Remember to turn off logging when finished debugging", E_USER_WARNING);

?>
<h2>CACTUS Operator Schedule Signup <a href="how-do-i-use-this.php" target="_blank">(How do I use this?)</a></h2>

<?php if ($password_error): ?>
    <p style="color:red; font-weight:bold;"><?= $password_error ?></p>
<?php endif; ?>

<?php log_msg(DEBUG_INFO, 'Flash status: ' . ($_SESSION['login_success'] ?? 'NOT SET')); ?>

<?php if (!empty($_SESSION['login_success'])): ?>
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
<?php $_SESSION['login_shown'] = true; unset($_SESSION['login_success']); endif; ?>

<form method="POST">
    <div class="section">
        <label><strong>Callsign:</strong></label>
        <input type="text" name="op_call" value="<?= htmlspecialchars($op_call_input) ?>" required>
        <label><strong>Name:</strong></label>
        <input type="text" name="op_name" value="<?= htmlspecialchars($op_name_input) ?>" required>

		<?php if ($_SESSION['logged_in']): ?>
			<!-- Don't use this nested form, it creates trouble -->
			<!-- Show logout button if logged in -->
 			<!-- form method="get" style="display:inline;" -->
				<!-- button type="submit" name="logout" value="1">🚪 Log Out</button -->
			<!-- /form -->
 			<a href="?logout=1" class="logout-button">Log Out</a>
		<?php else: ?>
			<!-- Show password input if not logged in -->
			<label><strong>Password:</strong></label>
			<input type="password" name="op_password" title="Optional. Set on first use. Required afterward.">
		<?php endif; ?>
    </div>

    <div class="section">
        <strong>Select Bands:</strong><br>
        <label><input type="checkbox" onclick="toggleAll(this, 'bands[]')"> All</label>
        <?php foreach ($bands_list as $index => $b): ?>
			<?php if ($index % 5 == 0): ?>
				<br>
			<?php endif ?>
            <label><input type="checkbox" name="bands[]" value="<?= $b ?>" <?= in_array($b, $bands) ? 'checked' : '' ?>> <?= $b ?></label>
		<?php endforeach; ?>
    </div>

    <div class="section">
        <strong>Select Modes:</strong><br>
        <label><input type="checkbox" onclick="toggleAll(this, 'modes[]')"> All</label>
        <?php foreach ($modes_list as $m): ?>
            <label><input type="checkbox" name="modes[]" value="<?= $m ?>" <?= in_array($m, $modes) ? 'checked' : '' ?>> <?= $m ?></label>
        <?php endforeach; ?>
    </div>

	<div class="section">
		<strong>Event Date Range:</strong> Starts <strong><?= htmlspecialchars($event_start_date) ?></strong> &nbsp;&nbsp;Ends <strong><?= htmlspecialchars($event_end_date) ?></strong></p>
		
		<strong>Select Date Range to view:</strong><br>
		<label>Start:</label>
		<input type="date" name="start_date" value="<?= htmlspecialchars($start_date ?: $event_start_date) ?>"
			   min="<?= htmlspecialchars($event_start_date) ?>" max="<?= htmlspecialchars($event_end_date) ?>" id="start_date" required>
		<label>End:</label>
		<input type="date" name="end_date" value="<?= htmlspecialchars($end_date ?: $start_date ?: $event_start_date) ?>"
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
	
	<div class="section">
        <strong>Time Ranges:</strong><br>
        <?php
        $time_opts = [
            'all' => 'All',
            'midnight_to_6' => 'Midnight–6am',
            '6_to_noon' => '6am–Noon',
            'noon_to_6' => 'Noon–6pm',
            '6_to_midnight' => '6pm–Midnight'
        ];
        foreach ($time_opts as $val => $label): ?>
            <label><input type="checkbox" name="time_slots[]" value="<?= $val ?>" <?= in_array($val, $time_slots) ? 'checked' : '' ?>> <?= $label ?></label>
        <?php endforeach; ?>
    </div>

    <div class="section">
        <strong>Days of Week:</strong><br>
        <?php
        $day_opts = ['all' => 'All', '0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat'];
        foreach ($day_opts as $val => $label): ?>
            <label><input type="checkbox" name="days_of_week[]" value="<?= $val ?>" <?= in_array($val, $days_of_week) ? 'checked' : '' ?>> <?= $label ?></label>
        <?php endforeach; ?>
    </div>

    <div class="section">
		<strong>Choose what schedule slots to show (filtered by selections above):</strong><br><br>
		<input type="hidden" name="enter_pressed" value="Enter Pressed">
        <input type="submit" name="mine_only" value="Show Mine Only">
        <input type="submit" name="all_available" value="Show Mine and Unscheduled">
        <input type="submit" name="all_scheduled" value="Show All Scheduled Slots">
    </div>

	<?php if ($result): ?> 

		<table border="1" cellpadding="5">
			<tr>
				<th>Select</th><th>Date</th><th>Time</th><th>Band</th><th>Mode</th>
				<th>Club Station</th><th>Notes</th><th>Status</th>
			</tr>
			<?php foreach ($result as $r):
				$key = "{$r['date']}|{$r['time']}|{$r['band']}|{$r['mode']}";
				$status = 'Open';
				if ($r['op']) {
					$status = ($r['op'] === $op_call_input) ? 'Booked by you' : "Booked by {$r['op']} {$r['name']}";
				}
			?>
			<tr style="<?= $status === 'Booked by you' ? 'background-color: #d9fdd3;' : '' ?>">
				<td>
					<?php if ($status === 'Open'): ?>
						<input type="checkbox" name="slots[]" value="<?= $key ?>">
					<?php elseif ($status === "Booked by you"): ?>
						<input type="checkbox" name="delete_slots[]" value="<?= $key ?>"> Delete
					<?php else: ?>
						--
					<?php endif; ?>
				</td>
				<td><?= $r['date'] ?></td>
				<td><?= $r['time'] ?></td>
				<td><?= $r['band'] ?></td>
				<td><?= $r['mode'] ?></td>
				<td>
				  <?php if ($status === 'Open'): ?>
					<select name="club_station[<?= $key ?>]">
					  <option value="">(Own Station)</option>
					  <?php
					  $conflict_query = "SELECT club_station FROM schedule WHERE date='{$r['date']}' AND time='{$r['time']}'";
					  $used = [];
					  $res = $conn->query($conflict_query);
					  while ($res && $row2 = $res->fetch_assoc()) {
						  if (!empty($row2['club_station'])) $used[] = $row2['club_station'];
					  }
					  foreach ($club_stations as $station):
						  if (!in_array($station, $used)):
					  ?>
						<option value="<?= $station ?>"><?= $station ?></option>
					  <?php endif; endforeach; ?>
					</select>
				  <?php else: ?>
					<?= htmlspecialchars($r['club_station']) ?: '--' ?>
				  <?php endif; ?>
				</td>

				<td>
				  <?php if ($status === 'Open'): ?>
					<input type="text" name="notes[<?= $key ?>]" size="20">
				  <?php else: ?>
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

<?php if ($result): ?> 
	<form method="post" action="export_csv.php">
		<input type="hidden" name="op_call" value="<?= htmlspecialchars($op_call_input) ?>">
		<button type="submit">⬇️ Download CSV</button>
		<button type="button" onclick="window.print()">🖨️ Print Schedule</button>
	</form>
<?php endif; ?>

<script>
function toggleAll(master, groupName) {
    const checkboxes = document.querySelectorAll(`input[name="${groupName}"]`);
    checkboxes.forEach(cb => {
        if (cb !== master) cb.checked = master.checked;
    });
}
</script>

<script>
setTimeout(() => {
    const flash = document.getElementById('login-flash');
    if (flash) flash.style.display = 'none';
}, 5000); // Hide after 5 seconds
</script>

</body>
</html>
