<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>How Do I Use This? – <?= htmlspecialchars(EVENT_DISPLAY_NAME) ?> Operator Scheduler</title>
    <link rel="icon" href="img/cropped-RST-Logo-1-32x32.jpg">
    <style>
        body {
            font-family: sans-serif;
            max-width: 800px;
            margin: 2em auto;
            line-height: 1.6;
        }
        h1 {
            color: #005c33;
        }
        h2 {
            border-bottom: 1px solid #ccc;
            padding-bottom: 4px;
        }
        a {
            color: #005c33;
        }
    </style>
</head>
<body>

<h1>How Do I Use This?</h1>

<p>This page will help you understand how to use the <?= htmlspecialchars(EVENT_DISPLAY_NAME) ?> Operator Scheduling Tool 
to sign up for operating time during the special event.</p>
<p><strong>Tip: </strong>There are pop-up help message boxes activated by hovering your 
mouse over various parts of the screen. Hopefully these are helpful, but if one of them
covers where you're trying to read or type, move the mouse somewhere else on the screen 
to make it go away.</p>

<h2>1. Signing In</h2>
<ul>
    <li>Enter your <strong>callsign</strong> and <strong>name</strong> at the top of the page.</li>
    <li>If you'd like to protect your schedule with a password, enter it. Once used, you’ll need it in the future.</li>
	<li><strong>Note: </strong>The password only protects against others deleting or adding to your schedule. 
		Browsing the schedule is not secured.</li>
</ul>

<h2>2. Finding Available Slots</h2>
<ul>
    <li>Choose a date range, time of day, and which days of the week you're available.</li>
    <li>The defaults will select all hours and days of the entire event calendar</li>
    <li>Choose the bands and modes of interest to you. If you chose only one band and/or one mode, 
        those become the default for scheduling yourself.</li>
    <li>Click <strong>“Show Complete Calendar”</strong> to see the calendar within the date/time/days 
		you selected. You will see open slots and scheduled slots.</li>
	<li>The other two choices can show you everything currently scheduled 
		<strong>(Show Scheduled Slots Only)</strong>, or just your own schedule 
		<strong>(Show My Schedule Only)</strong>. 
		These may be of interest for printing or otherwise saving the schedule.</li>
</ul>

<h2>3. Booking Your Time</h2>
<ul>
    <li>In the list of results, you’ll see each slot’s date and time, and whether it's scheduled or open.</li>
    <li>Click the checkboxes in the left column of open slots you want to book.</li>
    <li>Choose a band and mode you will operate in that slot.</li>
    <li>Optionally choose a <strong>club station</strong> if you'll be using one — only one person can reserve 
        a club station per time slot.</li>
    <li>Add any optional notes you'd like to include with your booking.</li>
    <li>Click <strong>“Add Selected Slots”</strong> just below the bottom of the list to finalize your signup.</li>
</ul>

<h2>4. Viewing or Deleting Your Schedule</h2>
<ul>
    <li>To see only your scheduled slots, click <strong>“Show My Schedule Only.”</strong></li>
    <li>Your slots will appear with a “Delete” option. Check the ones you want to remove and 
        click <strong>“Delete Selected Slots just below the bottom of the list.”</strong></li>
</ul>

<h2>5. Printing or Downloading</h2>
<ul>
    <li>You can print your schedule directly by clicking the <strong>🖨️ Print Schedule</strong> button.</li>
    <li>To export your schedule as a CSV file (spreadsheet-compatible), use the <strong>⬇️ Download CSV</strong> button.</li>
</ul>

<h2>Using a Club Station</h2>

<div style="border: 2px solid #5cb85c; background-color: #e6f9e6; padding: 1em; border-radius: 6px;">
    <p>
        If you plan to operate from one of the shared <strong>club stations</strong>, you can reserve it when booking your slot.
    </p>
    <ul>
        <li>Select a club station from the <strong>“Club Station”</strong> dropdown in the form.</li>
        <li>If left blank, we assume you're using your own personal station.</li>
        <li><strong>Only one operator</strong> can use a specific club station in a given hour — it's exclusive.</li>
        <li>If a time slot is already taken for that club station, you'll need to pick a different time or station.</li>
    </ul>
    <p><strong>Tip:</strong> If you're unsure, leave it blank. You can always update your schedule later (by delete/add).</p>
</div>

<h2>Need Help?</h2>
<p>If you run into trouble or have questions, please contact your <?= htmlspecialchars(EVENT_DISPLAY_NAME) ?> coordinator for assistance.</p>

<p><a href="scheduler.php">← Back to the Scheduler</a></p>

</body>
</html>
