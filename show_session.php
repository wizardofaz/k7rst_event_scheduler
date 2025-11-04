<?php
// show_session.php
// This is a page for developer testing only

declare(strict_types=1);
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

csrf_start_session_if_needed();
$ident = auth_get_identity();
$authed = auth_is_authenticated();
$browse = auth_is_browse_only();

if (empty($ident)) {
    header('Location: index.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if (!csrf_validate($_POST['_csrf_key'] ?? null, $_POST['_csrf'] ?? null)) {
        http_response_code(403);
        exit('Invalid logout request.');
    }
    auth_logout();
    header('Location: index.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Session Test</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Helvetica,Arial,sans-serif;margin:20px;}
    .card{max-width:720px;margin:auto;padding:16px;border:1px solid #ddd;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
    pre{background:#f8f8f8;padding:12px;border-radius:6px;overflow:auto;}
    .banner{padding:8px;border-radius:6px;margin-bottom:10px;}
    .ok{background:#e6ffed;border:1px solid #b7ebc6;}
    .ro{background:#fffbe6;border:1px solid #ffe58f;}
  </style>
</head>
<body>
<div class="card">
  <h2>Session OK</h2>
  <?php if ($authed): ?>
    <div class="banner ok">Authenticated as <strong><?= htmlspecialchars($ident['callsign']) ?></strong> for <strong><?= htmlspecialchars($ident['event']) ?></strong>.</div>
  <?php elseif ($browse): ?>
    <div class="banner ro">Browsing only as <strong><?= htmlspecialchars($ident['callsign']) ?></strong> for <strong><?= htmlspecialchars($ident['event']) ?></strong>.</div>
  <?php endif; ?>

  <h3>Session payload</h3>
  <pre><?= htmlspecialchars(print_r($ident, true)) ?></pre>

  <form method="post">
    <?= csrf_input('logout') ?>
    <button type="submit" name="action" value="logout">Logout</button>
  </form>

  <p style="margin-top:10px">
    <a href="index.php">Back to Index</a>
  </p>
</div>
</body>
</html>
