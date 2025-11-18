<?php
// don't include config.php here, will cause recursion 
$eventFromUrl   = isset($_GET['event']) ? trim((string)$_GET['event']) : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($eventFromUrl) ?> Operator Scheduling</title>
    <link rel="icon" href="img/cropped-RST-Logo-1-32x32.jpg">
	<link rel="stylesheet" href="scheduler.css">

	<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
<div class="header-wrap">
  <img src="img/RST-header-768x144.jpg" alt="Radio Society of Tucson K7RST"
       title="<?= htmlspecialchars($eventFromUrl) ?>" />
</div>
<h2>Thanks for browsing the <?= htmlspecialchars($eventFromUrl) ?> schedule. You can close this window.</h2>
</body>
</html>
