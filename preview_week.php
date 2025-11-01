<?php
// preview_week.php — top 3 per hour + brief stats
require_once __DIR__ . '/assigned_call.php';

// Inputs (optional)
$start = isset($_GET['start']) ? $_GET['start']
        : (defined('EVENT_START_DATE') ? substr(EVENT_START_DATE,0,10) : gmdate('Y-m-d'));
$mode  = isset($_GET['mode'])  ? strtoupper(trim($_GET['mode'])) : '';
$band  = isset($_GET['band'])  ? strtolower(trim($_GET['band'])) : '';
$days  = isset($_GET['days'])  ? max(1, (int)$_GET['days']) : 7;

$calls = ac_get_calls_array();
$topK  = min(3, count($calls));
$totalHours = 24 * $days;

// Stats buckets
$firstCounts = array_fill_keys($calls, 0);
$top3Counts  = array_fill_keys($calls, 0);

// Precompute all rows to both render and tally stats
$rows = [];
$startTs = (new DateTimeImmutable($start . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
for ($d = 0; $d < $days; $d++) {
    $dateYmd = gmdate('Y-m-d', $startTs + $d*86400);
    for ($h = 0; $h < 24; $h++) {
        $timeHis = sprintf('%02d:00:00', $h);
        $order = ac_order_for_slot($dateYmd, $timeHis, $mode, $band);
        $top = array_slice($order, 0, $topK);

        // stats
        if (!empty($top)) {
            $firstCounts[$top[0]]++;
            foreach ($top as $c) { $top3Counts[$c]++; }
        }

        $rows[] = [$dateYmd, $h, $top];
    }
}

// Sort stats by #1 count desc, then callsign
uksort($firstCounts, function($a,$b) use($firstCounts) {
    $da = $firstCounts[$a]; $db = $firstCounts[$b];
    if ($db !== $da) return $db <=> $da;
    return strcmp($a,$b);
});
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>CACTUS Callsign Preview</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 16px; }
  table { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
  th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
  thead th { position: sticky; top: 0; background: #f7f7f7; }
  .dim { color: #666; font-size: 90%; }
  .num { text-align: right; }
  .nowrap { white-space: nowrap; }
</style>
</head>
<body>
<h2>CACTUS Callsign Preview (Top <?= $topK ?> per hour)</h2>
<p class="dim">
  Start: <?= htmlspecialchars($start) ?> UTC · Days: <?= $days ?> (<?= $totalHours ?> hours)
  <?php if ($mode) echo " · Mode: " . htmlspecialchars($mode); ?>
  <?php if ($band) echo " · Band: " . htmlspecialchars($band); ?>
</p>

<table>
  <thead>
    <tr><th>Date</th><th>UTC Hour</th><th>Top choices</th></tr>
  </thead>
  <tbody>
<?php foreach ($rows as [$dateYmd, $h, $top]): ?>
  <tr>
    <td class="nowrap"><?= htmlspecialchars($dateYmd) ?></td>
    <td class="nowrap"><?= sprintf('%02d:00Z', $h) ?></td>
    <td><?= htmlspecialchars(implode(', ', $top)) ?></td>
  </tr>
<?php endforeach; ?>
  </tbody>
</table>

<h3>Brief Stats</h3>
<p class="dim">How often each callsign is #1 (and appears in Top-3) across the rendered hours.</p>
<table>
  <thead>
    <tr>
      <th>Callsign</th>
      <th class="num">#1 Count</th>
      <th class="num">#1 %</th>
      <th class="num">Top-3 Count</th>
      <th class="num">Top-3 %</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($firstCounts as $call => $cnt1):
    $cnt3 = $top3Counts[$call] ?? 0;
    $p1   = $totalHours ? ($cnt1 * 100.0 / $totalHours) : 0;
    $p3   = $totalHours ? ($cnt3 * 100.0 / $totalHours) : 0;
?>
    <tr>
      <td><?= htmlspecialchars($call) ?></td>
      <td class="num"><?= $cnt1 ?></td>
      <td class="num"><?= number_format($p1, 1) ?>%</td>
      <td class="num"><?= $cnt3 ?></td>
      <td class="num"><?= number_format($p3, 1) ?>%</td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>

<p class="dim">
  Tip: pass <code>?start=YYYY-MM-DD&amp;days=7&amp;mode=CW&amp;band=10m</code> to explore variations.
</p>
</body>
</html>
