<?php
/*
 * driver.php — Driver terminal.
 *
 * Tabs: Fleet (read-only vehicle list), Depots (read-only), and My reports.
 * On "My reports" a driver files a safety event, which is stored in
 * Telematics_Events and given a matching Safety_Reviews row with
 * is_completed = 0 (pending). An admin must approve it before it goes live.
 */
session_start();
include 'dbconn.php';

// Only signed-in drivers may view this page.
if (($_SESSION['role'] ?? '') !== 'driver') {
    header("Location: login.php");
    exit;
}

$driver_id = (int)($_SESSION['driver_id'] ?? 0);
$driver_email = $_SESSION['driver_email'] ?? '';
$driver_name = $_SESSION['driver_name'] ?? 'Driver';
$notice = ""; // Status message shown after filing a report.

// The session holds the Accounts.account_id, but events are attributed by
// Drivers.driver_id — resolve the real driver id from the sign-in email so
// filed events and the event log line up with the driver's own records.
// Resolved BEFORE the POST handler because filing an event uses it.
$real_driver_id = 0;
if ($driver_email !== '') {
    try {
        $s = $conn->prepare("SELECT driver_id FROM Drivers WHERE contact_information = ? LIMIT 1");
        $s->bind_param('s', $driver_email);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $real_driver_id = $row ? (int)$row['driver_id'] : 0;
        $s->close();
    } catch (mysqli_sql_exception $e) { $real_driver_id = 0; }
}

// ---- File a safety event (starts as "pending review") ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'file_event') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $notice = "Your session expired. Please refresh and try again.";
    } else try {
        if (!table_exists($conn, 'Telematics_Events')) {
            $notice = "Database not available.";
        } else {
            $ts = date('Y-m-d H:i:s');

            // depot_id is required in every known schema. Everything else is
            // added only if the column actually exists, so this INSERT adapts
            // to whichever version of Telematics_Events the database has.
            $cols  = ['depot_id'];
            $types = 's';
            $vals  = [($_POST['depot_id'] ?? '')];

            // Timestamp column is spelled differently across schemas.
            $tsCol = find_column($conn, 'Telematics_Events', ['times_tamp', 'event_timestamp', 'timestamp', 'created_at']);
            if ($tsCol) { $cols[] = $tsCol; $types .= 's'; $vals[] = $ts; }

            $optional = [
                'driver_id'         => ['i', $real_driver_id ?: $driver_id],
                'vin'               => ['s', $_POST['vin'] ?? ''],
                'event_type'        => ['s', $_POST['event_type'] ?? ''],
                'severity_level'    => ['s', $_POST['severity_level'] ?? ''],
                'snapshot_odometer' => ['d', (float)($_POST['snapshot_odometer'] ?? 0)],
            ];
            foreach ($optional as $col => [$type, $val]) {
                if (find_column($conn, 'Telematics_Events', [$col])) {
                    $cols[]  = $col;
                    $types  .= $type;
                    $vals[]  = $val;
                }
            }

            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $colList = implode(', ', $cols);
            $stmt = $conn->prepare("INSERT INTO Telematics_Events ($colList) VALUES ($placeholders)");
            $stmt->bind_param($types, ...$vals);

            if ($stmt->execute()) {
                $event_id = $stmt->insert_id;
                $stmt->close();
                // Open a pending review row so an admin must approve it before it
                // goes live — but only if the Safety_Reviews table exists AND we
                // got a real event id back (avoid an orphaned review on event 0).
                if ($event_id > 0 && table_exists($conn, 'Safety_Reviews')) {
                    $rev = $conn->prepare(
                        "INSERT INTO Safety_Reviews (event_id, reviewed_by, review_date, comments, recommendations, is_completed)
                         VALUES (?, NULL, NULL, NULL, NULL, 0)"
                    );
                    $rev->bind_param("i", $event_id);
                    $rev->execute();
                    $rev->close();
                }
                // Show the event id only when the DB actually returned one
                // (auto-increment). Otherwise keep the message clean.
                $notice = $event_id > 0
                    ? "Your report has been filed (event #$event_id) and is awaiting admin review."
                    : "Your report has been filed and is awaiting admin review.";
            } else {
                $notice = "Sorry, the report could not be filed. Please try again.";
                $stmt->close();
            }
        }
    } catch (mysqli_sql_exception $e) {
        $notice = "Database not available.";
    }
}

// ---- Lookups for the form ----
$depots = $conn->query("SELECT depot_id, depot_name FROM Depots ORDER BY depot_name");
$vehicles = $conn->query(
    "SELECT vin, manufacturer, model FROM Vehicles ORDER BY manufacturer, model"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Driver Terminal</title>
<link rel="stylesheet" href="style.css">
</head>
<body data-greet-name="<?= h(($driver_name && $driver_name !== 'Driver') ? $driver_name : (strstr($driver_email, '@', true) ?: 'Driver')) ?>">

<div class="header">
  <div class="mark">
    <?= version_switch_plate('DRIVER') ?>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Driver &middot; <?= h($driver_email ?: $driver_name) ?></span>
    </div>
  </div>
  <div class="meta">
    <span class="live">On duty</span>
  </div>
  <a href="dashboard.php" class="back">Dashboard</a>
  <a href="account.php" class="back">Account</a>
  <a href="logout.php" class="back">End shift</a>
</div>

<div id="greet-line"></div>

<div class="wrap">

<?php if ($notice): ?>
  <?php
    $isErr = (stripos($notice, 'could not') !== false)
          || (stripos($notice, 'not available') !== false)
          || (stripos($notice, 'sorry') !== false)
          || (stripos($notice, 'incorrect') !== false)
          || (stripos($notice, 'do not match') !== false)
          || (stripos($notice, 'required') !== false)
          || (stripos($notice, 'at least') !== false)
          || (stripos($notice, 'try again') !== false);
  ?>
  <div class="flash <?= $isErr ? 'flash-err' : 'flash-ok' ?>"><?= h($notice) ?></div>
<?php endif; ?>

<?php
  // After filing a report the page reloads; keep the "My reports" tab open.
  $openReports = ($notice !== '');
?>
<input type="radio" name="tab" id="tab1" class="tab-input"<?= $openReports ? '' : ' checked' ?>>
<input type="radio" name="tab" id="tab2" class="tab-input">
<input type="radio" name="tab" id="tab3" class="tab-input"<?= $openReports ? ' checked' : '' ?>>

<div class="tabs">
  <label for="tab1"><span class="tab-ico"><?= ui_icon('truck') ?></span>Fleet</label>
  <label for="tab2"><span class="tab-ico"><?= ui_icon('building') ?></span>Depots</label>
  <label for="tab3"><span class="tab-ico"><?= ui_icon('clipboard') ?></span>My reports</label>
</div>

<!-- ==================== VEHICLES (read-only) ==================== -->
<div id="content1" class="tab-content">
  <div class="ledger">
    <div class="ledger-head"><h2>Fleet register</h2><span class="no">View only</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>VIN</th><th>Registration</th><th>Model</th><th>Category</th><th>Year</th><th>Status</th><th>Depot</th><th>Driver</th></tr></thead>
      <tbody>
        <?php
        // List all vehicles with category, depot, and assigned driver name.
        // LEFT JOIN keeps vehicles that have no driver; COALESCE avoids NULLs
        // so the name is a clean "First Last" (or empty) string.
        $sql = "SELECT v.vin, v.registration_number, v.manufacturer, v.model,
                       vc.category_name, v.year_of_manufacture, v.operational_status, d.depot_name,
                       TRIM(CONCAT(COALESCE(dr.first_name,''), ' ', COALESCE(dr.last_name,''))) AS driver_name
                FROM Vehicles v
                JOIN Vehicle_Categories vc ON v.category_id = vc.category_id
                JOIN Depots d ON v.current_depot_id = d.depot_id
                LEFT JOIN Drivers dr ON v.driver_id = dr.driver_id
                ORDER BY v.vin";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $driver = trim($row['driver_name'] ?? '');
                echo "<tr>";
                echo "<td class='mono'>" . h($row['vin']) . "</td>";
                echo "<td class='mono'>" . h($row['registration_number']) . "</td>";
                echo "<td>" . h($row['manufacturer'] . ' ' . $row['model']) . "</td>";
                echo "<td>" . h($row['category_name']) . "</td>";
                echo "<td class='num'>" . (int)$row['year_of_manufacture'] . "</td>";
                echo "<td>" . status_badge((string)($row['operational_status'] ?? '')) . "</td>";
                echo "<td>" . h($row['depot_name']) . "</td>";
                echo "<td>" . ($driver !== '' ? h($driver) : "No driver assigned") . "</td>";
                echo "</tr>";
            }
        } else {
            echo empty_row(8, "No vehicles yet", "Vehicles added by an admin will show here.", "truck");
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ==================== DEPOTS (read-only) ==================== -->
<div id="content2" class="tab-content">
  <div class="ledger">
    <div class="ledger-head"><h2>Depot network</h2><span class="no">View only</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>ID</th><th>Name</th><th>Location</th></tr></thead>
      <tbody>
        <?php
        // Find the real location column (its name varies) and alias it to
        // "location"; show a dash if the table has no such column.
        $locCol = find_column($conn, 'Depots', ['location', 'location_details', 'address', 'depot_location', 'city']);
        $sel = "depot_id, depot_name" . ($locCol ? ", `$locCol` AS location" : "");
        $res = $conn->query("SELECT $sel FROM Depots ORDER BY depot_id");
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                echo "<tr>";
                echo "<td class='mono'>" . (int)$row['depot_id'] . "</td>";
                echo "<td>" . h($row['depot_name']) . "</td>";
                echo "<td>" . h($locCol ? ($row['location'] ?? '—') : '—') . "</td>";
                echo "</tr>";
            }
        } else {
            echo empty_row(3, "No depots yet", "Depots added by an admin will show here.", "building");
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ==================== MY REPORTS ==================== -->
<div id="content3" class="tab-content">

  <div class="docket">
    <div class="docket-head"><h2>Report a safety event</h2><span class="no">Filing as <?= h($driver_email ?: $driver_name) ?></span></div>
    <form method="POST" action="driver.php">
      <input type="hidden" name="action" value="file_event">
      <?= csrf_field() ?>
      <div class="form-row">
        <div class="form-group"><label>Type</label>
          <select name="event_type">
            <option>Harsh Braking</option><option>Speeding</option><option>Collision</option>
            <option>Breakdown</option><option>Idle Violation</option><option>Fatigue Alert</option>
          </select>
        </div>
        <div class="form-group"><label>Severity</label>
          <select name="severity_level"><option>Low</option><option>Medium</option><option>High</option><option>Critical</option></select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Vehicle</label>
          <select name="vin">
            <?php while ($v = $vehicles->fetch_assoc()): ?>
              <option value="<?= h($v['vin']) ?>"><?= h($v['vin'] . ' · ' . $v['manufacturer'] . ' ' . $v['model']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group"><label>Depot</label>
          <select name="depot_id">
            <?php while ($d = $depots->fetch_assoc()): ?>
              <option value="<?= (int)$d['depot_id'] ?>"><?= h($d['depot_name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group"><label>Odometer</label><input type="number" name="snapshot_odometer" value="0" min="0" style="width:120px"></div>
      </div>
      <div class="form-row"><button type="submit" class="form-btn primary">File report</button></div>
    </form>
  </div>

  <div class="ledger">
    <div class="ledger-head"><h2>My event log</h2><span class="no">Pending events await admin review</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>ID</th><th>Date</th><th>Type</th><th>Vehicle</th><th>Severity</th><th>Review status</th></tr></thead>
      <tbody>
        <?php
        try {
          if (!table_exists($conn, 'Telematics_Events')) {
              echo empty_row(6, "Database not available", "The events table could not be reached.", "clipboard");
          } else {
            // Detect the real column names so this works on either schema.
            $tsCol  = find_column($conn, 'Telematics_Events', ['times_tamp', 'event_timestamp', 'timestamp', 'created_at']);
            $hasDriverCol = find_column($conn, 'Telematics_Events', ['driver_id']) !== null;
            $hasVin = find_column($conn, 'Telematics_Events', ['vin']) !== null;
            $hasSev = find_column($conn, 'Telematics_Events', ['severity_level']) !== null;

            // Build the SELECT list from whichever columns exist.
            $tsSel  = $tsCol ? "te.`$tsCol` AS ts" : "'' AS ts";
            $vinSel = $hasVin ? "te.vin AS vin" : "'' AS vin";
            $sevSel = $hasSev ? "te.severity_level AS severity_level" : "'' AS severity_level";

            // Only join Safety_Reviews (for the Approved/Pending badge) if it exists.
            $hasReviews = table_exists($conn, 'Safety_Reviews');
            $reviewSel  = $hasReviews ? "sr.is_completed" : "NULL AS is_completed";
            $reviewJoin = $hasReviews ? "LEFT JOIN Safety_Reviews sr ON sr.event_id = te.event_id" : "";

            // Only filter by this driver if the table records driver_id.
            $filterId = $real_driver_id ?: $driver_id;
            $where = $hasDriverCol && $filterId > 0 ? "WHERE te.driver_id = " . (int)$filterId : "";

            $sql = "SELECT te.event_id, $tsSel, te.event_type, $vinSel, $sevSel, $reviewSel
                    FROM Telematics_Events te
                    $reviewJoin
                    $where
                    ORDER BY te.event_id DESC";
            $res = $conn->query($sql);
            if ($res && $res->num_rows > 0) {
                while ($row = $res->fetch_assoc()) {
                    // is_completed = 1 means an admin approved it; anything else is still pending.
                    $done = !is_null($row['is_completed']) && (int)$row['is_completed'] === 1;
                    $mk = $done ? "avail" : "fair";          // colour class for the status badge
                    $label = $done ? "Approved" : "Pending review";
                    echo "<tr>";
                    echo "<td class='mono'>" . (int)$row['event_id'] . "</td>";
                    echo "<td class='mono'>" . h($row['ts'] ?: '—') . "</td>";
                    echo "<td>" . h($row['event_type']) . "</td>";
                    echo "<td class='mono'>" . h($row['vin'] ?: '—') . "</td>";
                    echo "<td>" . h($row['severity_level'] ?: '—') . "</td>";
                    echo "<td><span class='mk $mk'>$label</span></td>";
                    echo "</tr>";
                }
            } else {
                echo empty_row(6, "No reports filed yet", "File a safety event above to start your log.", "clipboard");
            }
          }
        } catch (mysqli_sql_exception $e) {
            echo empty_row(6, "Database not available", "The events table could not be reached.", "clipboard");
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

</div>
<script src="motion.js"></script>
<script src="greeting.js"></script>
</body>
</html>
