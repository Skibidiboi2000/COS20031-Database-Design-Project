<?php
/*
 * dashboard.php — CloudKit-style monitoring dashboard, shared by every role.
 *
 * A dense grid of widgets (big metric + a small chart each), generated from
 * whatever the database actually has. Every query is wrapped so a missing
 * table/column degrades to an empty widget instead of crashing.
 *
 * Role-scoped: each widget declares which roles may see it, and a widget is
 * only rendered when the viewer's role is allowed AND it has data to show.
 */
session_start();
include 'dbconn.php';

$role = $_SESSION['role'] ?? '';
$allowed = ['admin', 'driver', 'mechanic', 'customer', 'user'];
if (!in_array($role, $allowed, true)) {
    header("Location: login.php");
    exit;
}
// Treat 'user' the same as 'customer' for permission checks.
$viewRole = ($role === 'user') ? 'customer' : $role;

/* ============================================================
 * Data helpers — all fail safe to [] / 0.
 * ============================================================ */
function stat_count(mysqli $conn, string $table): int {
    try {
        if (!table_exists($conn, $table)) return 0;
        $r = $conn->query("SELECT COUNT(*) AS n FROM `$table`");
        return $r ? (int)$r->fetch_assoc()['n'] : 0;
    } catch (mysqli_sql_exception $e) { return 0; }
}

/** GROUP BY a column -> [label => count], adapting to the real column name. */
function group_counts(mysqli $conn, string $table, array $candidates, int $limit = 12): array {
    $col = find_column($conn, $table, $candidates);
    if ($col === null) return [];
    try {
        $out = [];
        $res = $conn->query("SELECT `$col` AS k, COUNT(*) AS n FROM `$table` GROUP BY `$col` ORDER BY n DESC LIMIT $limit");
        while ($res && $row = $res->fetch_assoc()) {
            $key = ($row['k'] === null || $row['k'] === '') ? '—' : (string)$row['k'];
            $out[$key] = (int)$row['n'];
        }
        return $out;
    } catch (mysqli_sql_exception $e) { return []; }
}

/** Count rows matching a WHERE fragment on values already cast safe. Returns int. */
function count_where(mysqli $conn, string $table, string $where): int {
    try {
        if (!table_exists($conn, $table)) return 0;
        $r = $conn->query("SELECT COUNT(*) AS n FROM `$table` WHERE $where");
        return $r ? (int)$r->fetch_assoc()['n'] : 0;
    } catch (mysqli_sql_exception $e) { return 0; }
}

/** Events grouped by month (last 12), using the real timestamp column. */
function events_by_month(mysqli $conn): array {
    if (!table_exists($conn, 'Telematics_Events')) return [];
    $ts = find_column($conn, 'Telematics_Events', ['times_tamp', 'event_timestamp', 'timestamp', 'created_at']);
    if ($ts === null) return [];
    try {
        $out = [];
        $res = $conn->query(
            "SELECT DATE_FORMAT(`$ts`, '%Y-%m') AS k, COUNT(*) AS n
             FROM Telematics_Events
             WHERE `$ts` IS NOT NULL
             GROUP BY k ORDER BY k ASC LIMIT 12");
        while ($res && $row = $res->fetch_assoc()) {
            if ($row['k'] !== null) $out[(string)$row['k']] = (int)$row['n'];
        }
        return $out;
    } catch (mysqli_sql_exception $e) { return []; }
}

/* ============================================================
 * Colours + chart renderers (pure inline SVG, no libraries).
 * ============================================================ */
function chart_palette(): array {
    // Apple keeps charts to blue + greys rather than a rainbow. These are
    // tints/shades of the accent plus neutral greys for non-semantic mixes.
    return ['#0071E3', '#4B9CEC', '#8FC1F4', '#1D1D1F', '#6E6E73', '#AEAEB2', '#C7C7CC', '#D9E7FB'];
}
function semantic_color(string $label): ?string {
    $k = strtolower(trim($label));
    if (in_array($k, ['available', 'active', 'low'], true))                    return '#1D8036';
    if (in_array($k, ['on road', 'in transit', 'medium'], true))               return '#0071E3';
    if (in_array($k, ['under maintenance', 'awaiting inspection', 'in shop', 'high'], true)) return '#B25000';
    if (in_array($k, ['out of service', 'retired', 'suspended', 'critical'], true)) return '#D22E2E';
    return null;
}
function pick_color(string $label, int $i, bool $semantic): string {
    if ($semantic) { $c = semantic_color($label); if ($c !== null) return $c; }
    $p = chart_palette(); return $p[$i % count($p)];
}

/** Compact donut ring — CloudKit "Requests by X" style. */
function ring_chart(array $data, bool $semantic = false): string {
    $total = array_sum($data);
    if ($total <= 0) return chart_empty();
    $cx = 70; $cy = 70; $r = 54; $inner = 38; $angle = -M_PI / 2;
    $paths = ''; $legend = ''; $i = 0;
    foreach ($data as $label => $value) {
        $frac = $value / $total;
        $end = $angle + $frac * 2 * M_PI;
        $large = ($frac > 0.5) ? 1 : 0;
        $x1 = $cx + $r * cos($angle); $y1 = $cy + $r * sin($angle);
        $x2 = $cx + $r * cos($end);   $y2 = $cy + $r * sin($end);
        $color = pick_color((string)$label, $i, $semantic);
        $tip = htmlspecialchars("$label — " . (int)$value . " (" . round($frac * 100) . "%)");
        $paths .= sprintf(
            "<path d='M %.2f %.2f A %d %d 0 %d 1 %.2f %.2f L %d %d Z' fill='%s' stroke='#fff' stroke-width='1.5' stroke-linejoin='round' data-tip='%s'/>",
            $x1, $y1, $r, $r, $large, $x2, $y2, $cx, $cy, $color, $tip
        );
        $pct = round($frac * 100);
        $legend .= "<div class='lg-row'><span class='lg-dot' style='background:$color'></span>"
                 . "<span class='lg-label'>" . htmlspecialchars($label) . "</span>"
                 . "<span class='lg-val'><b>" . (int)$value . "</b> &middot; {$pct}%</span></div>";
        $angle = $end; $i++;
    }
    return "<div class='w-chart ring-wrap'>"
         . "<svg viewBox='0 0 140 140' class='ring'>$paths<circle cx='$cx' cy='$cy' r='$inner' fill='#fff'/></svg>"
         . "<div class='legend'>$legend</div></div>";
}

/** Horizontal bars — CloudKit "distribution" style. */
function bar_chart(array $data, bool $semantic = false): string {
    if (empty($data)) return chart_empty();
    $max = max($data); $max = $max > 0 ? $max : 1; $rows = ''; $i = 0;
    foreach ($data as $label => $value) {
        $w = round(($value / $max) * 100, 1);
        $color = pick_color((string)$label, $i, $semantic);
        $tip = htmlspecialchars("$label — " . (int)$value);
        $rows .= "<div class='bar-row' data-tip='$tip'><span class='bar-label'>" . htmlspecialchars($label) . "</span>"
               . "<span class='bar-track'><span class='bar-fill' style='width:{$w}%;background:$color'></span></span>"
               . "<span class='bar-val'>" . (int)$value . "</span></div>";
        $i++;
    }
    return "<div class='w-chart bars'>$rows</div>";
}

/** Column bars over time — CloudKit "Requests / Last 30 Days" style. */
function column_chart(array $data): string {
    if (empty($data)) return chart_empty();
    $max = max($data); $max = $max > 0 ? $max : 1;
    $cols = '';
    foreach ($data as $label => $value) {
        $h = round(($value / $max) * 100, 1);
        $short = htmlspecialchars(substr((string)$label, 2)); // "2026-03" -> "26-03"
        $cols .= "<div class='col-item' data-tip='" . htmlspecialchars("$label — $value") . "'>"
               . "<div class='col-bar-wrap'><span class='col-bar' style='height:{$h}%'></span></div>"
               . "<span class='col-x'>$short</span></div>";
    }
    return "<div class='w-chart cols'>$cols</div>";
}

/** A single usage meter — CloudKit "Storage / % Latest" style. */
function usage_meter(int $value, int $total, string $unit = ''): string {
    $pct = $total > 0 ? round(($value / $total) * 100, 1) : 0;
    $unitTxt = $unit !== '' ? " $unit" : '';
    return "<div class='w-chart meter'>"
         . "<div class='meter-track'><span class='meter-fill' style='width:{$pct}%'></span></div>"
         . "<div class='meter-cap'>{$value}{$unitTxt} of {$total} &middot; {$pct}%</div></div>";
}

function chart_empty(): string {
    return "<div class='w-chart chart-empty'><div class='empty-title'>No data</div></div>";
}

/* ============================================================
 * Gather data.
 * ============================================================ */
$stat_vehicles  = stat_count($conn, 'Vehicles');
$stat_drivers   = stat_count($conn, 'Drivers');
$stat_depots    = stat_count($conn, 'Depots');
$stat_mechanics = stat_count($conn, 'Mechanics');
$stat_events    = stat_count($conn, 'Telematics_Events');

$byStatus    = group_counts($conn, 'Vehicles', ['operational_status', 'status']);
$byCategory  = [];
try {
    if (table_exists($conn, 'Vehicles') && table_exists($conn, 'Vehicle_Categories')) {
        $res = $conn->query(
            "SELECT vc.category_name AS k, COUNT(*) AS n
             FROM Vehicles v JOIN Vehicle_Categories vc ON v.category_id = vc.category_id
             GROUP BY vc.category_name ORDER BY n DESC LIMIT 12");
        while ($res && $row = $res->fetch_assoc()) { $byCategory[(string)$row['k']] = (int)$row['n']; }
    }
} catch (mysqli_sql_exception $e) { $byCategory = []; }

$byDepot = [];
try {
    if (table_exists($conn, 'Vehicles') && table_exists($conn, 'Depots')) {
        $res = $conn->query(
            "SELECT d.depot_name AS k, COUNT(*) AS n
             FROM Vehicles v JOIN Depots d ON v.current_depot_id = d.depot_id
             GROUP BY d.depot_name ORDER BY n DESC LIMIT 12");
        while ($res && $row = $res->fetch_assoc()) { $byDepot[(string)$row['k']] = (int)$row['n']; }
    }
} catch (mysqli_sql_exception $e) { $byDepot = []; }
if (empty($byDepot)) $byDepot = group_counts($conn, 'Vehicles', ['current_depot_id']);

$byEventSev  = group_counts($conn, 'Telematics_Events', ['severity_level', 'severity']);
$byEventType = group_counts($conn, 'Telematics_Events', ['event_type', 'type']);
$eventsMonth = events_by_month($conn);

// Fleet utilization: how many vehicles are "in service" (Available/Active).
$activeVehicles = count_where($conn, 'Vehicles', "operational_status IN ('Available','Active')");

// Pending safety reviews awaiting an admin.
$pendingReviews = count_where($conn, 'Safety_Reviews', "is_completed = 0");

/* ============================================================
 * Widget access per role. A widget shows only if the role is listed.
 * ============================================================ */
function can_see(string $role, array $roles): bool { return in_array($role, $roles, true); }
$ALL     = ['admin', 'driver', 'mechanic', 'customer'];
$STAFF   = ['admin', 'driver', 'mechanic'];
$ADMIN   = ['admin'];
$OPS     = ['admin', 'driver'];               // safety events are driver-facing ops
$SHOP    = ['admin', 'mechanic'];             // maintenance-oriented

$home = [
    'admin' => 'admin.php', 'driver' => 'driver.php', 'mechanic' => 'mechanic.php',
    'customer' => 'customer.php', 'user' => 'customer.php',
][$role] ?? 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Dashboard</title>
<link rel="stylesheet" href="style.css?v=khang24">
</head>
<body class="asc role-<?= htmlspecialchars($viewRole) ?>">

<div class="header">
  <div class="mark">
    <?= version_switch_plate() ?>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Monitoring &middot; <?= htmlspecialchars(ucfirst($role)) ?></span>
    </div>
  </div>
  <div class="meta"><span class="live">Live</span></div>
  <a href="<?= htmlspecialchars($home) ?>" class="back">Back</a>
</div>

<div class="wrap dash">

  <div class="dash-hero reveal">
    <div class="eyebrow">Fleet Ops</div>
    <h2 class="dash-title">Monitoring</h2>
    <p class="dash-sub">A live view of the fleet, scoped to what your role can see.</p>
  </div>

  <div class="dash-toolbar reveal">
    <span class="dash-hint">Drag a card by its title to reorder &middot; use ⤢ to resize</span>
    <button type="button" id="resetLayout" class="form-btn">Reset layout</button>
  </div>

  <div class="widgets" id="widgets">

    <?php if (can_see($viewRole, $ALL)): ?>
    <!-- Big number: total vehicles + status ring -->
    <div class="widget reveal" data-widget="vehicles">
      <div class="w-head"><span class="w-title">Vehicles</span><span class="w-tools"><span class="w-tag">Fleet health</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <div class="w-metric"><span class="w-num" data-count="<?= $stat_vehicles ?>">0</span><span class="w-cap">total in fleet</span></div>
      <?= ring_chart($byStatus, true) ?>
    </div>
    <?php endif; ?>

    <?php if (can_see($viewRole, $ALL)): ?>
    <!-- Vehicles by category -->
    <div class="widget reveal" data-widget="by_category">
      <div class="w-head"><span class="w-title">By category</span><span class="w-tools"><span class="w-tag">Mix</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <?= bar_chart($byCategory) ?>
    </div>
    <?php endif; ?>

    <?php if (can_see($viewRole, $ALL)): ?>
    <!-- Vehicles by depot -->
    <div class="widget reveal" data-widget="by_depot">
      <div class="w-head"><span class="w-title">By depot</span><span class="w-tools"><span class="w-tag">Distribution</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <?= bar_chart($byDepot) ?>
    </div>
    <?php endif; ?>

    <?php if (can_see($viewRole, $STAFF)): ?>
    <!-- Fleet utilization meter -->
    <div class="widget reveal" data-widget="utilization">
      <div class="w-head"><span class="w-title">Fleet utilization</span><span class="w-tools"><span class="w-tag">In service</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <div class="w-metric"><span class="w-num" data-count="<?= $activeVehicles ?>">0</span><span class="w-cap">available or active</span></div>
      <?= usage_meter($activeVehicles, $stat_vehicles) ?>
    </div>
    <?php endif; ?>

    <?php if (can_see($viewRole, $OPS)): ?>
    <!-- Events over time -->
    <div class="widget size-wide reveal" data-widget="events_time" data-size="wide">
      <div class="w-head"><span class="w-title">Events over time</span><span class="w-tools"><span class="w-tag">By month</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <div class="w-metric"><span class="w-num" data-count="<?= $stat_events ?>">0</span><span class="w-cap">events recorded</span></div>
      <?= column_chart($eventsMonth) ?>
    </div>
    <?php endif; ?>

    <?php if (can_see($viewRole, $OPS)): ?>
    <!-- Events by severity -->
    <div class="widget reveal" data-widget="events_sev">
      <div class="w-head"><span class="w-title">Events by severity</span><span class="w-tools"><span class="w-tag">Risk</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <?= ring_chart($byEventSev, true) ?>
    </div>
    <?php endif; ?>

    <?php if (can_see($viewRole, $OPS)): ?>
    <!-- Events by type -->
    <div class="widget reveal" data-widget="events_type">
      <div class="w-head"><span class="w-title">Events by type</span><span class="w-tools"><span class="w-tag">Breakdown</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <?= bar_chart($byEventType) ?>
    </div>
    <?php endif; ?>

    <?php if (can_see($viewRole, $ADMIN)): ?>
    <!-- Pending reviews (admin only) -->
    <div class="widget reveal" data-widget="pending">
      <div class="w-head"><span class="w-title">Pending reviews</span><span class="w-tools"><span class="w-tag">Needs action</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <div class="w-metric"><span class="w-num" data-count="<?= $pendingReviews ?>">0</span><span class="w-cap">reports awaiting approval</span></div>
      <?= usage_meter($pendingReviews, max($stat_events, $pendingReviews)) ?>
    </div>
    <?php endif; ?>

    <?php if (can_see($viewRole, $STAFF)): ?>
    <!-- Team counts (staff) -->
    <div class="widget reveal" data-widget="team">
      <div class="w-head"><span class="w-title">Team</span><span class="w-tools"><span class="w-tag">Headcount</span><button type="button" class="w-size" title="Resize">&#10530;</button></span></div>
      <div class="w-metric-row">
        <div class="w-metric"><span class="w-num" data-count="<?= $stat_drivers ?>">0</span><span class="w-cap">drivers</span></div>
        <div class="w-metric"><span class="w-num" data-count="<?= $stat_mechanics ?>">0</span><span class="w-cap">mechanics</span></div>
        <div class="w-metric"><span class="w-num" data-count="<?= $stat_depots ?>">0</span><span class="w-cap">depots</span></div>
      </div>
    </div>
    <?php endif; ?>

  </div>

</div>
<div id="chart-tip" class="chart-tip" hidden></div>
<script src="motion.js?v=khang24"></script>
<script src="dashboard.js?v=khang24"></script>
</body>
</html>
