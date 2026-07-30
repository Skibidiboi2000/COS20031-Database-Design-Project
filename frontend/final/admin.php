<?php
session_start();
include 'dbconn.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}
$admin_name = $_SESSION['admin_name'] ?? 'admin';
$notice = "";

// ==================== WRITE HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_vehicle') {
        $stmt = $conn->prepare(
            "INSERT INTO Vehicles (vin, registration_number, category_id, current_depot_id, manufacturer, model, year_of_manufacture, operational_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $vin = $_POST['vin']; $reg = $_POST['registration_number'];
        $cat = (int)$_POST['category_id']; $dep = (int)$_POST['current_depot_id'];
        $man = $_POST['manufacturer']; $mod = $_POST['model'];
        $yr = (int)$_POST['year_of_manufacture']; $st = $_POST['operational_status'];
        $stmt->bind_param("ssiissis", $vin, $reg, $cat, $dep, $man, $mod, $yr, $st);
        $notice = $stmt->execute() ? "Vehicle $vin added." : "Error: " . $conn->error;
        $stmt->close();

    } elseif ($action === 'add_driver') {
        try {
            // Match the real licence column name (spelling varies between schemas).
            $licCol = null;
            $lc = $conn->query("SHOW COLUMNS FROM Drivers");
            $driverCols = [];
            while ($col = $lc->fetch_assoc()) { $driverCols[] = $col['Field']; }
            foreach (['license_type', 'licence_type', 'license', 'licence', 'license_class'] as $cand) {
                if (in_array($cand, $driverCols, true)) { $licCol = $cand; break; }
            }
            $fn = $_POST['first_name']; $ln = $_POST['last_name'];
            $ci = $_POST['contact_information']; $lt = $_POST['licence_type'];
            $es = $_POST['employment_status']; $dep = (int)$_POST['current_depot_id'];
            if ($licCol) {
                $stmt = $conn->prepare(
                    "INSERT INTO Drivers (first_name, last_name, contact_information, `$licCol`, employment_status, current_depot_id)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sssssi", $fn, $ln, $ci, $lt, $es, $dep);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO Drivers (first_name, last_name, contact_information, employment_status, current_depot_id)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("ssssi", $fn, $ln, $ci, $es, $dep);
            }
            $notice = $stmt->execute() ? "Driver added." : "Error: " . $conn->error;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "No DATABASE";
        }

    } elseif ($action === 'add_depot') {
        // Only insert a location column if the table actually has one.
        $hasLoc = false;
        $dc = $conn->query("SHOW COLUMNS FROM Depots LIKE 'location'");
        if ($dc && $dc->num_rows > 0) { $hasLoc = true; }
        if ($hasLoc) {
            $stmt = $conn->prepare("INSERT INTO Depots (depot_name, location) VALUES (?, ?)");
            $dn = $_POST['depot_name']; $loc = $_POST['location'] ?? '';
            $stmt->bind_param("ss", $dn, $loc);
        } else {
            $stmt = $conn->prepare("INSERT INTO Depots (depot_name) VALUES (?)");
            $dn = $_POST['depot_name'];
            $stmt->bind_param("s", $dn);
        }
        $notice = $stmt->execute() ? "Depot added." : "Error: " . $conn->error;
        $stmt->close();

    } elseif ($action === 'add_mechanic') {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO Mechanics (first_name, last_name, contact_information, depot_id, specialization, hire_date)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $fn = $_POST['first_name']; $ln = $_POST['last_name'];
            $ci = $_POST['contact_information']; $dep = (int)$_POST['depot_id'];
            $sp = $_POST['specialization']; $hd = $_POST['hire_date'];
            $stmt->bind_param("sssiss", $fn, $ln, $ci, $dep, $sp, $hd);
            $notice = $stmt->execute() ? "Mechanic added." : "Error: " . $conn->error;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "No DATABASE";
        }

    } elseif ($action === 'approve_event') {
        // Publish a driver-filed event: complete its safety review.
        try {
            $event_id = (int)$_POST['event_id'];
            $when = date('Y-m-d H:i:s');
            $stmt = $conn->prepare(
                "UPDATE Safety_Reviews SET reviewed_by = ?, review_date = ?, is_completed = 1 WHERE event_id = ?"
            );
            $stmt->bind_param("ssi", $admin_name, $when, $event_id);
            $notice = $stmt->execute() ? "Event #$event_id approved." : "Error: " . $conn->error;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "No DATABASE";
        }

    } elseif ($action === 'delete_event') {
        // Reject: remove the review and the event.
        try {
            $event_id = (int)$_POST['event_id'];
            $conn->query("DELETE FROM Safety_Reviews WHERE event_id = $event_id");
            $stmt = $conn->prepare("DELETE FROM Telematics_Events WHERE event_id = ?");
            $stmt->bind_param("i", $event_id);
            $notice = $stmt->execute() ? "Event #$event_id deleted." : "Error: " . $conn->error;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "No DATABASE";
        }

    } elseif ($action === 'delete_vehicle') {
        $stmt = $conn->prepare("DELETE FROM Vehicles WHERE vin = ?");
        $stmt->bind_param("s", $_POST['vin']);
        $notice = $stmt->execute() ? "Vehicle deleted." : "Error: " . $conn->error;
        $stmt->close();
    }
}

$depots_opts = $conn->query("SELECT depot_id, depot_name FROM Depots ORDER BY depot_name");
$cats_opts   = $conn->query("SELECT category_id, category_name FROM Vehicle_Categories ORDER BY category_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Admin Terminal</title>
<link rel="stylesheet" href="style.css?v=5">
</head>
<body>

<div class="header">
  <div class="mark">
    <span class="plate">FF&middot;01</span>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Admin &middot; <?= htmlspecialchars($admin_name) ?></span>
    </div>
  </div>
  <div class="meta"><span class="live">Online</span></div>
  <a href="login.php" class="back">End shift</a>
</div>

<div class="wrap">

<?php if ($notice): ?>
  <div class="manifest"><span class="seg count"><span class="v"><?= htmlspecialchars($notice) ?></span></span></div>
<?php endif; ?>

<input type="radio" name="tab" id="tab1" class="tab-input" checked>
<input type="radio" name="tab" id="tab2" class="tab-input">
<input type="radio" name="tab" id="tab3" class="tab-input">
<input type="radio" name="tab" id="tab4" class="tab-input">
<input type="radio" name="tab" id="tab5" class="tab-input">

<div class="tabs">
  <label for="tab1">Vehicles</label>
  <label for="tab2">Drivers</label>
  <label for="tab3">Depots</label>
  <label for="tab4">Mechanics</label>
  <label for="tab5">Review queue</label>
</div>

<!-- ==================== VEHICLES ==================== -->
<div id="content1" class="tab-content">
  <div class="docket">
    <div class="docket-head"><h2>Register a vehicle</h2></div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="action" value="add_vehicle">
      <div class="form-row">
        <div class="form-group"><label>VIN</label><input type="text" name="vin" required style="width:180px"></div>
        <div class="form-group"><label>Registration</label><input type="text" name="registration_number" required style="width:120px"></div>
        <div class="form-group"><label>Manufacturer</label><input type="text" name="manufacturer" required style="width:140px"></div>
        <div class="form-group"><label>Model</label><input type="text" name="model" required style="width:140px"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Category</label><select name="category_id"><?php $cats_opts->data_seek(0); while($c=$cats_opts->fetch_assoc()): ?><option value="<?= (int)$c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option><?php endwhile; ?></select></div>
        <div class="form-group"><label>Year</label><input type="number" name="year_of_manufacture" value="2024" style="width:90px"></div>
        <div class="form-group"><label>Depot</label><select name="current_depot_id"><?php $depots_opts->data_seek(0); while($d=$depots_opts->fetch_assoc()): ?><option value="<?= (int)$d['depot_id'] ?>"><?= htmlspecialchars($d['depot_name']) ?></option><?php endwhile; ?></select></div>
        <div class="form-group"><label>Status</label><select name="operational_status"><option>Available</option><option>Active</option><option>Under Maintenance</option><option>Out of Service</option><option>Retired</option></select></div>
      </div>
      <div class="form-row"><button type="submit" class="form-btn primary">Add to fleet</button></div>
    </form>
  </div>

  <div class="ledger">
    <div class="ledger-head"><h2>Fleet register</h2></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>VIN</th><th>Registration</th><th>Model</th><th>Category</th><th>Year</th><th>Status</th><th>Depot</th><th>Action</th></tr></thead>
      <tbody>
        <?php
        $res = $conn->query(
          "SELECT v.vin, v.registration_number, v.manufacturer, v.model, MIN(vc.category_name) AS category_name,
                  v.year_of_manufacture, v.operational_status, MIN(d.depot_name) AS depot_name
           FROM Vehicles v
           JOIN Vehicle_Categories vc ON v.category_id = vc.category_id
           JOIN Depots d ON v.current_depot_id = d.depot_id
           GROUP BY v.vin, v.registration_number, v.manufacturer, v.model, v.year_of_manufacture, v.operational_status
           ORDER BY v.vin");
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
            echo "<td><form method='POST' action='admin.php' onsubmit=\"return confirm('Delete this vehicle?')\" style='display:inline'><input type='hidden' name='action' value='delete_vehicle'><input type='hidden' name='vin' value='" . htmlspecialchars($row['vin']) . "'><button class='action-btn del'>Delete</button></form></td>";
            echo "</tr>";
          }
        } else {
          echo "<tr><td colspan='8'>No vehicles found</td></tr>";
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ==================== DRIVERS ==================== -->
<div id="content2" class="tab-content">
  <div class="docket">
    <div class="docket-head"><h2>Add a driver</h2></div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="action" value="add_driver">
      <div class="form-row">
        <div class="form-group"><label>First name</label><input type="text" name="first_name" required style="width:140px"></div>
        <div class="form-group"><label>Last name</label><input type="text" name="last_name" required style="width:140px"></div>
        <div class="form-group"><label>Licence</label><select name="licence_type"><option>Class C</option><option>Class D</option><option>Class E</option></select></div>
        <div class="form-group"><label>Contact</label><input type="text" name="contact_information" style="width:150px"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Depot</label><select name="current_depot_id"><?php $depots_opts->data_seek(0); while($d=$depots_opts->fetch_assoc()): ?><option value="<?= (int)$d['depot_id'] ?>"><?= htmlspecialchars($d['depot_name']) ?></option><?php endwhile; ?></select></div>
        <div class="form-group"><label>Status</label><select name="employment_status"><option>Active</option><option>Suspended</option><option>On Leave</option></select></div>
      </div>
      <div class="form-row"><button type="submit" class="form-btn primary">Add driver</button></div>
    </form>
  </div>

  <div class="ledger">
    <div class="ledger-head"><h2>Driver roster</h2></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>ID</th><th>Name</th><th>Licence</th><th>Contact</th><th>Depot</th><th>Status</th></tr></thead>
      <tbody>
        <?php
        try {
        // Detect the real licence column name (spelling varies between schemas).
        $licCol = null;
        $lc = $conn->query("SHOW COLUMNS FROM Drivers");
        $driverCols = [];
        while ($col = $lc->fetch_assoc()) { $driverCols[] = $col['Field']; }
        foreach (['license_type', 'licence_type', 'license', 'licence', 'license_class'] as $cand) {
            if (in_array($cand, $driverCols, true)) { $licCol = $cand; break; }
        }
        $licSel = $licCol ? "dr.`$licCol` AS license_type" : "'' AS license_type";
        $res = $conn->query(
          "SELECT dr.driver_id, dr.first_name, dr.last_name, $licSel,
                  dr.contact_information, dr.employment_status, d.depot_name
           FROM Drivers dr
           LEFT JOIN Depots d ON dr.current_depot_id = d.depot_id
           ORDER BY dr.driver_id");
        if ($res && $res->num_rows > 0) {
          while ($row = $res->fetch_assoc()) {
            echo "<tr>";
            echo "<td class='mono'>D-" . str_pad($row['driver_id'], 4, '0', STR_PAD_LEFT) . "</td>";
            echo "<td>" . htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
            echo "<td>" . htmlspecialchars($row['license_type'] ?: '—') . "</td>";
            echo "<td class='mono'>" . htmlspecialchars($row['contact_information']) . "</td>";
            echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['employment_status']) . "</td>";
            echo "</tr>";
          }
        } else {
          echo "<tr><td colspan='6'>No drivers found</td></tr>";
        }
        } catch (mysqli_sql_exception $e) {
          echo "<tr><td colspan='6'>No DATABASE</td></tr>";
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ==================== DEPOTS ==================== -->
<div id="content3" class="tab-content">
  <?php
  // Detect the real Depots columns so we don't assume a "location" column exists.
  $depotCols = [];
  $dc = $conn->query("SHOW COLUMNS FROM Depots");
  while ($col = $dc->fetch_assoc()) { $depotCols[] = $col['Field']; }
  $locCol = null;
  foreach (['location', 'address', 'depot_location', 'city'] as $cand) {
      if (in_array($cand, $depotCols, true)) { $locCol = $cand; break; }
  }
  ?>
  <div class="docket">
    <div class="docket-head"><h2>Open a depot</h2></div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="action" value="add_depot">
      <div class="form-row">
        <div class="form-group"><label>Name</label><input type="text" name="depot_name" required style="width:180px"></div>
        <?php if ($locCol): ?>
        <div class="form-group"><label>Location</label><input type="text" name="location" required style="width:260px"></div>
        <?php endif; ?>
      </div>
      <div class="form-row"><button type="submit" class="form-btn primary">Add depot</button></div>
    </form>
  </div>

  <div class="ledger">
    <div class="ledger-head"><h2>Depot network</h2></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>ID</th><th>Name</th><?php if ($locCol): ?><th>Location</th><?php endif; ?></tr></thead>
      <tbody>
        <?php
        $sel = "depot_id, depot_name" . ($locCol ? ", `$locCol` AS location" : "");
        $res = $conn->query("SELECT $sel FROM Depots ORDER BY depot_id");
        if ($res && $res->num_rows > 0) {
          while ($row = $res->fetch_assoc()) {
            echo "<tr><td class='mono'>" . (int)$row['depot_id'] . "</td><td>" . htmlspecialchars($row['depot_name']) . "</td>";
            if ($locCol) { echo "<td>" . htmlspecialchars($row['location']) . "</td>"; }
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

<!-- ==================== MECHANICS ==================== -->
<div id="content4" class="tab-content">
  <div class="docket">
    <div class="docket-head"><h2>Add a mechanic</h2></div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="action" value="add_mechanic">
      <div class="form-row">
        <div class="form-group"><label>First name</label><input type="text" name="first_name" required style="width:140px"></div>
        <div class="form-group"><label>Last name</label><input type="text" name="last_name" required style="width:140px"></div>
        <div class="form-group"><label>Contact</label><input type="text" name="contact_information" style="width:150px"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Depot</label><select name="depot_id"><?php $depots_opts->data_seek(0); while($d=$depots_opts->fetch_assoc()): ?><option value="<?= (int)$d['depot_id'] ?>"><?= htmlspecialchars($d['depot_name']) ?></option><?php endwhile; ?></select></div>
        <div class="form-group"><label>Specialisation</label><input type="text" name="specialization" style="width:160px"></div>
        <div class="form-group"><label>Hire date</label><input type="date" name="hire_date"></div>
      </div>
      <div class="form-row"><button type="submit" class="form-btn primary">Add mechanic</button></div>
    </form>
  </div>

  <div class="ledger">
    <div class="ledger-head"><h2>Workshop roster</h2></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>ID</th><th>Name</th><th>Contact</th><th>Depot</th><th>Specialisation</th><th>Hired</th></tr></thead>
      <tbody>
        <?php
        try {
        $res = $conn->query(
          "SELECT m.mechanic_id, m.first_name, m.last_name, m.contact_information,
                  d.depot_name, m.specialization, m.hire_date
           FROM Mechanics m
           LEFT JOIN Depots d ON m.depot_id = d.depot_id
           ORDER BY m.mechanic_id");
        if ($res && $res->num_rows > 0) {
          while ($row = $res->fetch_assoc()) {
            echo "<tr>";
            echo "<td class='mono'>M-" . str_pad($row['mechanic_id'], 4, '0', STR_PAD_LEFT) . "</td>";
            echo "<td>" . htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
            echo "<td class='mono'>" . htmlspecialchars($row['contact_information']) . "</td>";
            echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['specialization']) . "</td>";
            echo "<td class='mono'>" . htmlspecialchars($row['hire_date']) . "</td>";
            echo "</tr>";
          }
        } else {
          echo "<tr><td colspan='6'>No mechanics found</td></tr>";
        }
        } catch (mysqli_sql_exception $e) {
          echo "<tr><td colspan='6'>No DATABASE</td></tr>";
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ==================== REVIEW QUEUE ==================== -->
<div id="content5" class="tab-content">
  <div class="ledger">
    <div class="ledger-head"><h2>Pending driver reports</h2><span class="no">Approve to publish &middot; Delete to reject</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Event</th><th>Date</th><th>Type</th><th>Vehicle</th><th>Driver</th><th>Depot</th><th>Severity</th><th>Action</th></tr></thead>
      <tbody>
        <?php
        try {
        $res = $conn->query(
          "SELECT te.event_id, te.times_tamp, te.event_type, te.vin, te.severity_level,
                  te.driver_id, dr.first_name, dr.last_name, d.depot_name
           FROM Telematics_Events te
           JOIN Safety_Reviews sr ON sr.event_id = te.event_id AND sr.is_completed = 0
           LEFT JOIN Drivers dr ON te.driver_id = dr.driver_id
           LEFT JOIN Depots d ON te.depot_id = d.depot_id
           ORDER BY te.event_id DESC");
        if ($res && $res->num_rows > 0) {
          while ($row = $res->fetch_assoc()) {
            $eid = (int)$row['event_id'];
            echo "<tr>";
            echo "<td class='mono'>$eid</td>";
            echo "<td class='mono'>" . htmlspecialchars($row['times_tamp']) . "</td>";
            echo "<td>" . htmlspecialchars($row['event_type']) . "</td>";
            echo "<td class='mono'>" . htmlspecialchars($row['vin']) . "</td>";
            echo "<td>" . htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
            echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
            echo "<td><span class='mk fair'>" . htmlspecialchars($row['severity_level']) . "</span></td>";
            echo "<td>"
               . "<form method='POST' action='admin.php' style='display:inline'><input type='hidden' name='action' value='approve_event'><input type='hidden' name='event_id' value='$eid'><button class='action-btn'>Approve</button></form>"
               . "<form method='POST' action='admin.php' onsubmit=\"return confirm('Reject and delete this report?')\" style='display:inline'><input type='hidden' name='action' value='delete_event'><input type='hidden' name='event_id' value='$eid'><button class='action-btn del'>Delete</button></form>"
               . "</td>";
            echo "</tr>";
          }
        } else {
          echo "<tr><td colspan='8'>No reports awaiting review</td></tr>";
        }
        } catch (mysqli_sql_exception $e) {
          echo "<tr><td colspan='8'>No DATABASE</td></tr>";
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="ledger">
    <div class="ledger-head"><h2>Approved reports</h2><span class="no">Live log</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Event</th><th>Date</th><th>Type</th><th>Vehicle</th><th>Driver</th><th>Severity</th><th>Reviewed by</th></tr></thead>
      <tbody>
        <?php
        try {
        $res = $conn->query(
          "SELECT te.event_id, te.times_tamp, te.event_type, te.vin, te.severity_level,
                  dr.first_name, dr.last_name, sr.reviewed_by
           FROM Telematics_Events te
           JOIN Safety_Reviews sr ON sr.event_id = te.event_id AND sr.is_completed = 1
           LEFT JOIN Drivers dr ON te.driver_id = dr.driver_id
           ORDER BY te.event_id DESC");
        if ($res && $res->num_rows > 0) {
          while ($row = $res->fetch_assoc()) {
            echo "<tr>";
            echo "<td class='mono'>" . (int)$row['event_id'] . "</td>";
            echo "<td class='mono'>" . htmlspecialchars($row['times_tamp']) . "</td>";
            echo "<td>" . htmlspecialchars($row['event_type']) . "</td>";
            echo "<td class='mono'>" . htmlspecialchars($row['vin']) . "</td>";
            echo "<td>" . htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
            echo "<td><span class='mk avail'>" . htmlspecialchars($row['severity_level']) . "</span></td>";
            echo "<td class='mono'>" . htmlspecialchars($row['reviewed_by']) . "</td>";
            echo "</tr>";
          }
        } else {
          echo "<tr><td colspan='7'>No approved reports yet</td></tr>";
        }
        } catch (mysqli_sql_exception $e) {
          echo "<tr><td colspan='7'>No DATABASE</td></tr>";
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
