<?php
/*
 * admin.php — Administrator dashboard.
 *
 * Shows five tabs (Vehicles, Drivers, Depots, Mechanics, Review queue) and
 * handles all write actions (add / delete / assign / approve) submitted from
 * those tabs. Every write is a POST that carries an "action" field telling
 * this page which operation to run.
 *
 * Note: some tables (Mechanics, Telematics_Events, Safety_Reviews) may not
 * exist in every database, so the queries that use them are wrapped in
 * try/catch and show "Database not available." instead of crashing the page.
 */
session_start();
include 'dbconn.php';

// Only signed-in admins may view this page; everyone else goes back to login.
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}
$admin_name = $_SESSION['admin_name'] ?? 'admin';
$notice = ""; // Short status message shown at the top after an action runs.

// Schema-detection helpers (table_exists, find_column, find_columns) live in dbconn.php.

// ==================== WRITE HANDLERS ====================
// Each branch handles one POST "action". Prepared statements are used for all
// writes so user input can never be injected into the SQL.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_vehicle') {
        // Insert one new vehicle. The bind types "ssiissis" map to each
        // placeholder in order: string, string, int, int, string, string, int, string.
        $stmt = $conn->prepare(
            "INSERT INTO Vehicles (vin, registration_number, category_id, current_depot_id, manufacturer, model, year_of_manufacture, operational_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $vin = $_POST['vin']; $reg = $_POST['registration_number'];
        $cat = (int)$_POST['category_id']; $dep = (int)$_POST['current_depot_id'];
        $man = $_POST['manufacturer']; $mod = $_POST['model'];
        $yr = (int)$_POST['year_of_manufacture']; $st = $_POST['operational_status'];
        $stmt->bind_param("ssiissis", $vin, $reg, $cat, $dep, $man, $mod, $yr, $st);
        $notice = $stmt->execute() ? "Vehicle $vin has been added." : "Could not add the vehicle. Please try again.";
        $stmt->close();

    } elseif ($action === 'add_driver') {
        try {
            // The licence column has different names in different databases,
            // so find the real one before building the INSERT.
            $licCol = find_column($conn, 'Drivers',
                ['license_type', 'licence_type', 'license', 'licence', 'license_class']);

            $fn = $_POST['first_name']; $ln = $_POST['last_name'];
            $ci = $_POST['contact_information']; $lt = $_POST['licence_type'];
            $es = $_POST['employment_status']; $dep = (int)$_POST['current_depot_id'];

            if ($licCol) {
                // Table has a licence column — include it in the INSERT.
                $stmt = $conn->prepare(
                    "INSERT INTO Drivers (first_name, last_name, contact_information, `$licCol`, employment_status, current_depot_id)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sssssi", $fn, $ln, $ci, $lt, $es, $dep);
            } else {
                // No licence column — insert without it.
                $stmt = $conn->prepare(
                    "INSERT INTO Drivers (first_name, last_name, contact_information, employment_status, current_depot_id)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("ssssi", $fn, $ln, $ci, $es, $dep);
            }
            $notice = $stmt->execute() ? "The driver has been added." : "Could not add the driver. Please try again.";
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

    } elseif ($action === 'add_depot') {
        // The location column is named differently across databases (and may be
        // absent). Find the real one and insert into that; skip it if none exist.
        $locCol = find_column($conn, 'Depots', ['location', 'location_details', 'address', 'depot_location', 'city']);
        if ($locCol) {
            $stmt = $conn->prepare("INSERT INTO Depots (depot_name, `$locCol`) VALUES (?, ?)");
            $dn = $_POST['depot_name']; $loc = $_POST['location'] ?? '';
            $stmt->bind_param("ss", $dn, $loc);
        } else {
            $stmt = $conn->prepare("INSERT INTO Depots (depot_name) VALUES (?)");
            $dn = $_POST['depot_name'];
            $stmt->bind_param("s", $dn);
        }
        $notice = $stmt->execute() ? "The depot has been added." : "Could not add the depot. Please try again.";
        $stmt->close();

    } elseif ($action === 'add_mechanic') {
        try {
            if (!table_exists($conn, 'Mechanics')) {
                $notice = "Database not available.";
            } else {
                // Always-present columns.
                $cols = ['first_name', 'last_name', 'depot_id'];
                $types = 'ssi';
                $vals = [$_POST['first_name'], $_POST['last_name'], (int)$_POST['depot_id']];

                // Optional columns: only add them if this database actually has them.
                $optional = [
                    'contact_information' => ['s', $_POST['contact_information'] ?? ''],
                    'specialization'      => ['s', $_POST['specialization'] ?? ''],
                    'hire_date'           => ['s', $_POST['hire_date'] ?? ''],
                ];
                foreach ($optional as $col => [$type, $val]) {
                    if (find_column($conn, 'Mechanics', [$col])) {
                        $cols[]  = $col;
                        $types  .= $type;
                        $vals[]  = $val;
                    }
                }

                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $colList = implode(', ', $cols);
                $stmt = $conn->prepare("INSERT INTO Mechanics ($colList) VALUES ($placeholders)");
                $stmt->bind_param($types, ...$vals);
                $notice = $stmt->execute() ? "The mechanic has been added." : "Could not add the mechanic. Please try again.";
                $stmt->close();
            }
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

    } elseif ($action === 'approve_event') {
        // Approve a driver-filed report: mark its safety review as completed,
        // which is what makes it "go live" for customers to see.
        try {
            $event_id = (int)$_POST['event_id'];
            $when = date('Y-m-d H:i:s');
            $stmt = $conn->prepare(
                "UPDATE Safety_Reviews SET reviewed_by = ?, review_date = ?, is_completed = 1 WHERE event_id = ?"
            );
            $stmt->bind_param("ssi", $admin_name, $when, $event_id);
            $notice = $stmt->execute() ? "Report #$event_id has been approved." : "Could not approve the report. Please try again.";
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

    } elseif ($action === 'delete_event') {
        // Reject a report: delete its review row first, then the event itself
        // (the review references the event, so it must be removed first).
        try {
            $event_id = (int)$_POST['event_id'];
            $conn->query("DELETE FROM Safety_Reviews WHERE event_id = $event_id");
            $stmt = $conn->prepare("DELETE FROM Telematics_Events WHERE event_id = ?");
            $stmt->bind_param("i", $event_id);
            $notice = $stmt->execute() ? "Report #$event_id has been deleted." : "Could not delete the report. Please try again.";
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

    } elseif ($action === 'delete_vehicle') {
        $stmt = $conn->prepare("DELETE FROM Vehicles WHERE vin = ?");
        $stmt->bind_param("s", $_POST['vin']);
        $notice = $stmt->execute() ? "The vehicle has been deleted." : "Could not delete the vehicle. Please try again.";
        $stmt->close();

    } elseif ($action === 'assign_vehicle') {
        // Assign a driver to a vehicle.
        $vin = $_POST['vin'] ?? '';
        $did = (int)($_POST['driver_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE Vehicles SET driver_id = ? WHERE vin = ?");
        $stmt->bind_param("is", $did, $vin);
        $notice = $stmt->execute() ? "Vehicle $vin has been assigned to the selected driver." : "Could not assign the vehicle. Please try again.";
        $stmt->close();

    } elseif ($action === 'unassign_vehicle') {
        // Remove the driver from a vehicle.
        $vin = $_POST['vin'] ?? '';
        $stmt = $conn->prepare("UPDATE Vehicles SET driver_id = NULL WHERE vin = ?");
        $stmt->bind_param("s", $vin);
        $notice = $stmt->execute() ? "Vehicle $vin has been unassigned." : "Could not unassign the vehicle. Please try again.";
        $stmt->close();
    }
}

// Data used to fill the dropdowns in the "add" forms below.
// Drivers is wrapped in try/catch because the table may not exist everywhere.
$depots_opts = $conn->query("SELECT depot_id, depot_name FROM Depots ORDER BY depot_name");
$cats_opts   = $conn->query("SELECT category_id, category_name FROM Vehicle_Categories ORDER BY category_name");
$drivers_opts = [];
try {
    $dr = $conn->query("SELECT driver_id, first_name, last_name FROM Drivers ORDER BY first_name, last_name");
    while ($dr && $row = $dr->fetch_assoc()) { $drivers_opts[] = $row; }
} catch (mysqli_sql_exception $e) { $drivers_opts = []; }
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
      <thead><tr><th>VIN</th><th>Registration</th><th>Model</th><th>Category</th><th>Year</th><th>Status</th><th>Depot</th><th>Driver</th><th>Action</th></tr></thead>
      <tbody>
        <?php
        // List every vehicle with its category, depot, and assigned driver.
        // LEFT JOIN on Drivers so vehicles with no driver still appear.
        // GROUP BY v.vin collapses any accidental duplicate join rows to one per vehicle.
        $res = $conn->query(
          "SELECT v.vin, v.registration_number, v.manufacturer, v.model, MIN(vc.category_name) AS category_name,
                  v.year_of_manufacture, v.operational_status, MIN(d.depot_name) AS depot_name,
                  v.driver_id,
                  MIN(TRIM(CONCAT(COALESCE(dr.first_name,''), ' ', COALESCE(dr.last_name,'')))) AS driver_name
           FROM Vehicles v
           JOIN Vehicle_Categories vc ON v.category_id = vc.category_id
           JOIN Depots d ON v.current_depot_id = d.depot_id
           LEFT JOIN Drivers dr ON v.driver_id = dr.driver_id
           GROUP BY v.vin, v.registration_number, v.manufacturer, v.model, v.year_of_manufacture, v.operational_status, v.driver_id
           ORDER BY v.vin");
        if ($res && $res->num_rows > 0) {
          // Build driver <option> list once, reused per row.
          $driverOptions = "";
          foreach ($drivers_opts as $d) {
            $dname = trim($d['first_name'] . ' ' . $d['last_name']);
            $driverOptions .= "<option value='" . (int)$d['driver_id'] . "'>" . htmlspecialchars($dname) . "</option>";
          }
          while ($row = $res->fetch_assoc()) {
            $driver = trim($row['driver_name'] ?? '');
            $vinEsc = htmlspecialchars($row['vin']);
            $hasDriver = !is_null($row['driver_id']) && $row['driver_id'] !== '';
            echo "<tr>";
            echo "<td class='mono'>" . $vinEsc . "</td>";
            echo "<td class='mono'>" . htmlspecialchars($row['registration_number']) . "</td>";
            echo "<td>" . htmlspecialchars($row['manufacturer'] . ' ' . $row['model']) . "</td>";
            echo "<td>" . htmlspecialchars($row['category_name']) . "</td>";
            echo "<td class='num'>" . (int)$row['year_of_manufacture'] . "</td>";
            echo "<td>" . htmlspecialchars($row['operational_status']) . "</td>";
            echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
            echo "<td>" . ($driver !== '' ? htmlspecialchars($driver) : "No driver assigned") . "</td>";
            echo "<td>";
            // Action cell laid out as two tidy rows in a fixed-width column:
            //   row 1: driver dropdown + Assign
            //   row 2: Unassign (if assigned) + Delete
            echo "<div class='act'>";
            echo "<form method='POST' action='admin.php' class='act-row'>"
               . "<input type='hidden' name='action' value='assign_vehicle'>"
               . "<input type='hidden' name='vin' value='$vinEsc'>"
               . "<select name='driver_id' class='act-select'>$driverOptions</select>"
               . "<button class='action-btn'>Assign</button></form>";
            echo "<div class='act-row'>";
            if ($hasDriver) {
              echo "<form method='POST' action='admin.php' style='display:inline'>"
                 . "<input type='hidden' name='action' value='unassign_vehicle'>"
                 . "<input type='hidden' name='vin' value='$vinEsc'>"
                 . "<button class='action-btn'>Unassign</button></form>";
            }
            echo "<form method='POST' action='admin.php' onsubmit=\"return confirm('Delete this vehicle?')\" style='display:inline'><input type='hidden' name='action' value='delete_vehicle'><input type='hidden' name='vin' value='$vinEsc'><button class='action-btn del'>Delete</button></form>";
            echo "</div>";
            echo "</div>";
            echo "</td>";
            echo "</tr>";
          }
        } else {
          echo "<tr><td colspan='9'>No vehicles found</td></tr>";
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
        // Detect the real licence column, then alias it to "license_type" so the
        // display code below can read one consistent key. If there is no licence
        // column, select an empty string instead.
        $licCol = find_column($conn, 'Drivers',
            ['license_type', 'licence_type', 'license', 'licence', 'license_class']);
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
          echo "<tr><td colspan='6'>Database not available.</td></tr>";
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
  // A depot's location column has different names across databases (or may not
  // exist at all). Find the real one so the form and table can adapt.
  $locCol = find_column($conn, 'Depots', ['location', 'location_details', 'address', 'depot_location', 'city']);
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
          if (!table_exists($conn, 'Mechanics')) {
              echo "<tr><td colspan='6'>Database not available.</td></tr>";
          } else {
            // Only select the optional columns that this database actually has;
            // missing ones are aliased to an empty string so the row still renders.
            $ci = find_column($conn, 'Mechanics', ['contact_information']) ? 'm.contact_information' : "'' AS contact_information";
            $sp = find_column($conn, 'Mechanics', ['specialization', 'specialisation']);
            $spSel = $sp ? "m.`$sp` AS specialization" : "'' AS specialization";
            $hd = find_column($conn, 'Mechanics', ['hire_date']) ? 'm.hire_date' : "'' AS hire_date";

            $res = $conn->query(
              "SELECT m.mechanic_id, m.first_name, m.last_name, $ci,
                      d.depot_name, $spSel, $hd
               FROM Mechanics m
               LEFT JOIN Depots d ON m.depot_id = d.depot_id
               ORDER BY m.mechanic_id");
            if ($res && $res->num_rows > 0) {
              while ($row = $res->fetch_assoc()) {
                echo "<tr>";
                echo "<td class='mono'>M-" . str_pad($row['mechanic_id'], 4, '0', STR_PAD_LEFT) . "</td>";
                echo "<td>" . htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['contact_information'] ?: '—') . "</td>";
                echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['specialization'] ?: '—') . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['hire_date'] ?: '—') . "</td>";
                echo "</tr>";
              }
            } else {
              echo "<tr><td colspan='6'>No mechanics found</td></tr>";
            }
          }
        } catch (mysqli_sql_exception $e) {
          echo "<tr><td colspan='6'>Database not available.</td></tr>";
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
          if (!table_exists($conn, 'Telematics_Events') || !table_exists($conn, 'Safety_Reviews')) {
              echo "<tr><td colspan='8'>Database not available.</td></tr>";
          } else {
            // Adapt to the real timestamp column name.
            $tsCol = find_column($conn, 'Telematics_Events', ['times_tamp', 'event_timestamp', 'timestamp', 'created_at']);
            $tsSel = $tsCol ? "te.`$tsCol` AS ts" : "'' AS ts";
            $res = $conn->query(
              "SELECT te.event_id, $tsSel, te.event_type, te.vin, te.severity_level,
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
                echo "<td class='mono'>" . htmlspecialchars($row['ts'] ?: '—') . "</td>";
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
          }
        } catch (mysqli_sql_exception $e) {
          echo "<tr><td colspan='8'>Database not available.</td></tr>";
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
          if (!table_exists($conn, 'Telematics_Events') || !table_exists($conn, 'Safety_Reviews')) {
              echo "<tr><td colspan='7'>Database not available.</td></tr>";
          } else {
            $tsCol = find_column($conn, 'Telematics_Events', ['times_tamp', 'event_timestamp', 'timestamp', 'created_at']);
            $tsSel = $tsCol ? "te.`$tsCol` AS ts" : "'' AS ts";
            $res = $conn->query(
              "SELECT te.event_id, $tsSel, te.event_type, te.vin, te.severity_level,
                      dr.first_name, dr.last_name, sr.reviewed_by
               FROM Telematics_Events te
               JOIN Safety_Reviews sr ON sr.event_id = te.event_id AND sr.is_completed = 1
               LEFT JOIN Drivers dr ON te.driver_id = dr.driver_id
               ORDER BY te.event_id DESC");
            if ($res && $res->num_rows > 0) {
              while ($row = $res->fetch_assoc()) {
                echo "<tr>";
                echo "<td class='mono'>" . (int)$row['event_id'] . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['ts'] ?: '—') . "</td>";
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
          }
        } catch (mysqli_sql_exception $e) {
          echo "<tr><td colspan='7'>Database not available.</td></tr>";
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
