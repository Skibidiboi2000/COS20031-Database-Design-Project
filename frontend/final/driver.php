<?php
session_start();
include 'dbconn.php';

if (($_SESSION['role'] ?? '') !== 'driver') {
    header("Location: login.php");
    exit;
}

$driver_id = (int)$_SESSION['driver_id'];
$driver_name = $_SESSION['driver_name'] ?? 'Driver';
$notice = "";

// ---- File a safety event (lands as pending review) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'file_event') {
    try {
        $depot_id   = (int)($_POST['depot_id'] ?? 0);
        $vin        = $_POST['vin'] ?? '';
        $event_type = $_POST['event_type'] ?? '';
        $severity   = $_POST['severity_level'] ?? '';
        $odometer   = (float)($_POST['snapshot_odometer'] ?? 0);
        $ts         = date('Y-m-d H:i:s');

        $stmt = $conn->prepare(
            "INSERT INTO Telematics_Events (depot_id, driver_id, vin, times_tamp, event_type, severity_level, snapshot_odometer)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iisssd", $depot_id, $driver_id, $vin, $ts, $event_type, $severity, $odometer);
        if ($stmt->execute()) {
            $event_id = $stmt->insert_id;
            $stmt->close();
            // Open a pending review record so an admin must approve before it "goes live".
            $rev = $conn->prepare(
                "INSERT INTO Safety_Reviews (event_id, reviewed_by, review_date, comments, recommendations, is_completed)
                 VALUES (?, NULL, NULL, NULL, NULL, 0)"
            );
            $rev->bind_param("i", $event_id);
            $rev->execute();
            $rev->close();
            $notice = "Event #$event_id filed. Pending admin review.";
        } else {
            $notice = "Could not file event: " . $conn->error;
            $stmt->close();
        }
    } catch (mysqli_sql_exception $e) {
        $notice = "No DATABASE";
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
<link rel="stylesheet" href="style.css?v=5">
</head>
<body>

<div class="header">
  <div class="mark">
    <span class="plate">D&middot;<?= str_pad($driver_id, 4, '0', STR_PAD_LEFT) ?></span>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Driver &middot; <?= htmlspecialchars($driver_name) ?></span>
    </div>
  </div>
  <div class="meta">
    <span class="live">On duty</span>
  </div>
  <a href="login.php" class="back">End shift</a>
</div>

<div class="wrap">

<input type="radio" name="tab" id="tab1" class="tab-input" checked>
<input type="radio" name="tab" id="tab2" class="tab-input">
<input type="radio" name="tab" id="tab3" class="tab-input">

<div class="tabs">
  <label for="tab1">Fleet</label>
  <label for="tab2">Depots</label>
  <label for="tab3">My reports</label>
</div>

<!-- ==================== VEHICLES (read-only) ==================== -->
<div id="content1" class="tab-content">
  <div class="ledger">
    <div class="ledger-head"><h2>Fleet register</h2><span class="no">View only</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>VIN</th><th>Registration</th><th>Model</th><th>Category</th><th>Year</th><th>Status</th><th>Depot</th></tr></thead>
      <tbody>
        <?php
        $sql = "SELECT v.vin, v.registration_number, v.manufacturer, v.model,
                       vc.category_name, v.year_of_manufacture, v.operational_status, d.depot_name
                FROM Vehicles v
                JOIN Vehicle_Categories vc ON v.category_id = vc.category_id
                JOIN Depots d ON v.current_depot_id = d.depot_id
                ORDER BY v.vin";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                echo "<tr>";
                echo "<td class='mono'>" . htmlspecialchars($row['vin']) . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['registration_number']) . "</td>";
                echo "<td>" . htmlspecialchars($row['manufacturer'] . ' ' . $row['model']) . "</td>";
                echo "<td>" . htmlspecialchars($row['category_name']) . "</td>";
                echo "<td class='num'>" . (int)$row['year_of_manufacture'] . "</td>";
                echo "<td>" . htmlspecialchars($row['operational_status']) . "</td>";
                echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='7'>No vehicles found</td></tr>";
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
        $hasLoc = false;
        $dc = $conn->query("SHOW COLUMNS FROM Depots LIKE 'location'");
        if ($dc && $dc->num_rows > 0) { $hasLoc = true; }
        $sel = "depot_id, depot_name" . ($hasLoc ? ", location" : "");
        $res = $conn->query("SELECT $sel FROM Depots ORDER BY depot_id");
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                echo "<tr>";
                echo "<td class='mono'>" . (int)$row['depot_id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
                echo "<td>" . htmlspecialchars($hasLoc ? $row['location'] : '—') . "</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='3'>No depots found</td></tr>";
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ==================== MY REPORTS ==================== -->
<div id="content3" class="tab-content">
  <?php if ($notice): ?>
    <div class="manifest"><span class="seg count"><span class="v"><?= htmlspecialchars($notice) ?></span></span></div>
  <?php endif; ?>

  <div class="docket">
    <div class="docket-head"><h2>Report a safety event</h2><span class="no">Filing as D-<?= str_pad($driver_id, 4, '0', STR_PAD_LEFT) ?></span></div>
    <form method="POST" action="driver.php">
      <input type="hidden" name="action" value="file_event">
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
              <option value="<?= htmlspecialchars($v['vin']) ?>"><?= htmlspecialchars($v['vin'] . ' · ' . $v['manufacturer'] . ' ' . $v['model']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group"><label>Depot</label>
          <select name="depot_id">
            <?php while ($d = $depots->fetch_assoc()): ?>
              <option value="<?= (int)$d['depot_id'] ?>"><?= htmlspecialchars($d['depot_name']) ?></option>
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
        $stmt = $conn->prepare(
            "SELECT te.event_id, te.times_tamp, te.event_type, te.vin, te.severity_level, sr.is_completed
             FROM Telematics_Events te
             LEFT JOIN Safety_Reviews sr ON sr.event_id = te.event_id
             WHERE te.driver_id = ?
             ORDER BY te.event_id DESC"
        );
        $stmt->bind_param("i", $driver_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $done = !is_null($row['is_completed']) && (int)$row['is_completed'] === 1;
                $mk = $done ? "avail" : "fair";
                $label = $done ? "Approved" : "Pending review";
                echo "<tr>";
                echo "<td class='mono'>" . (int)$row['event_id'] . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['times_tamp']) . "</td>";
                echo "<td>" . htmlspecialchars($row['event_type']) . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['vin']) . "</td>";
                echo "<td>" . htmlspecialchars($row['severity_level']) . "</td>";
                echo "<td><span class='mk $mk'>$label</span></td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='6'>No reports filed yet</td></tr>";
        }
        $stmt->close();
        } catch (mysqli_sql_exception $e) {
            echo "<tr><td colspan='6'>No DATABASE</td></tr>";
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

</div>
</body>
</html>
