<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logging.php';

// constants_dump.php — remove after use (may reveal secrets!)
header('Content-Type: text/html; charset=utf-8');

$all   = isset($_GET['all']) && $_GET['all'] == '1';
$q     = isset($_GET['q']) ? trim($_GET['q']) : '';
$const = get_defined_constants(true); // grouped: 'user', 'Core', etc.
$list  = $all ? array_merge(...array_values($const)) : ($const['user'] ?? []);

ksort($list, SORT_NATURAL | SORT_FLAG_CASE);

// simple filter
if ($q !== '') {
    $list = array_filter($list, fn($v, $k) => stripos($k, $q) !== false, ARRAY_FILTER_USE_BOTH);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Defined Constants</title>
<style>
body { font-family: system-ui, sans-serif; margin: 16px; }
input { padding: 6px 8px; }
table { border-collapse: collapse; width: 100%; margin-top: 12px; }
th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; vertical-align: top; }
th { background: #f7f7f7; }
code { white-space: pre-wrap; }
.controls { margin-bottom: 8px; }
</style>
</head>
<body>
<h2>Defined Constants <?= $all ? '(all groups)' : '(user-defined only)' ?></h2>

<form class="controls" method="get">
  <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Filter by name…">
  <label style="margin-left:8px;">
    <input type="checkbox" name="all" value="1" <?= $all ? 'checked' : '' ?>> include PHP internals
  </label>
  <button type="submit">Apply</button>
</form>

<table>
  <thead><tr><th>Name</th><th>Value</th></tr></thead>
  <tbody>
<?php foreach ($list as $name => $value): ?>
  <tr>
    <td><code><?= htmlspecialchars($name) ?></code></td>
    <td><code><?=
        is_array($value) ? htmlspecialchars(var_export($value, true)) :
        (is_bool($value) ? ($value ? 'true' : 'false') :
        (is_null($value) ? 'null' :
        htmlspecialchars((string)$value)))
    ?></code></td>
  </tr>
<?php endforeach; ?>
  </tbody>
</table>

<p style="color:#666;font-size:90%;">Security note: this page may expose secrets (DB creds, API keys). Delete after debugging.</p>
</body>
</html>
