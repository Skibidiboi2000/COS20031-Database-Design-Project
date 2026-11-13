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
$admin_full_name = $_SESSION['admin_full_name'] ?? '';
// Prefer a first+last name if the Admin table had one; else the email handle.
$greet_name = ($admin_full_name !== '')
    ? $admin_full_name
    : (strstr($admin_name, '@', true) ?: $admin_name);
$notice = ""; // Short status message shown at the top after an action runs.

// Schema-detection helpers (table_exists, find_column, find_columns) live in dbconn.php.

// ==================== WRITE HANDLERS ====================
// Each branch handles one POST "action". Prepared statements are used for all
// writes so user input can never be injected into the SQL.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $notice = "Your session expired. Please refresh and try again.";
    } else if ($action === 'add_vehicle') {
        try {
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
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

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
        try {
        $notice = $stmt->execute() ? "The depot has been added." : "Could not add the depot. Please try again.";
        $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

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
            $admin_id = (int)($_SESSION['admin_id'] ?? 0);
            $stmt = $conn->prepare(
                "UPDATE Safety_Reviews SET reviewed_by = ?, review_date = ?, is_completed = 1 WHERE event_id = ?"
            );
            $stmt->bind_param("isi", $admin_id, $when, $event_id);
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
            $stmt = $conn->prepare("DELETE FROM Safety_Reviews WHERE event_id = ?");
            $stmt->bind_param("i", $event_id);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("DELETE FROM Telematics_Events WHERE event_id = ?");
            $stmt->bind_param("i", $event_id);
            $notice = $stmt->execute() ? "Report #$event_id has been deleted." : "Could not delete the report. Please try again.";
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

    } elseif ($action === 'delete_vehicle') {
        try {
        $stmt = $conn->prepare("DELETE FROM Vehicles WHERE vin = ?");
        $stmt->bind_param("s", $_POST['vin']);
        $notice = $stmt->execute() ? "The vehicle has been deleted." : "Could not delete the vehicle. Please try again.";
        $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

    } elseif ($action === 'assign_vehicle') {
        // Assign a driver to a vehicle.
        try {
        $vin = $_POST['vin'] ?? '';
        $did = (int)($_POST['driver_id'] ?? 0);
        // One driver per vehicle: refuse to assign a driver that already has
        // a vehicle, so the 1:1 relationship stays consistent.
        $chk = $conn->prepare("SELECT vin FROM Vehicles WHERE driver_id = ? AND vin <> ? LIMIT 1");
        $chk->bind_param("is", $did, $vin);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $notice = "That driver already has a vehicle assigned.";
            $chk->close();
        } else {
            $chk->close();
            $stmt = $conn->prepare("UPDATE Vehicles SET driver_id = ? WHERE vin = ?");
            $stmt->bind_param("is", $did, $vin);
            $notice = $stmt->execute() ? "Vehicle $vin has been assigned to the selected driver." : "Could not assign the vehicle. Please try again.";
            $stmt->close();
        }
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }

    } elseif ($action === 'unassign_vehicle') {
        // Remove the driver from a vehicle.
        try {
        $vin = $_POST['vin'] ?? '';
        $stmt = $conn->prepare("UPDATE Vehicles SET driver_id = NULL WHERE vin = ?");
        $stmt->bind_param("s", $vin);
        $notice = $stmt->execute() ? "Vehicle $vin has been unassigned." : "Could not unassign the vehicle. Please try again.";
        $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $notice = "Database not available.";
        }
    }
}

// Data used to fill the dropdowns in the "add" forms below.
$depots_opts = $cats_opts = null;
$drivers_opts = [];
try {
    $depots_opts = $conn->query("SELECT depot_id, depot_name FROM Depots ORDER BY depot_name");
    $cats_opts   = $conn->query("SELECT category_id, category_name FROM Vehicle_Categories ORDER BY category_name");
    $dr = $conn->query("SELECT driver_id, first_name, last_name FROM Drivers ORDER BY first_name, last_name");
    while ($dr && $row = $dr->fetch_assoc()) { $drivers_opts[] = $row; }
} catch (mysqli_sql_exception $e) { $depots_opts = $cats_opts = null; }

/* Headline counts for the dashboard stat cards. Each is wrapped so a missing
 * table simply shows 0 instead of breaking the page. */
function count_rows(mysqli $conn, string $table): int {
    try {
        if (!table_exists($conn, $table)) return 0;
        $r = $conn->query("SELECT COUNT(*) AS n FROM `$table`");
        return $r ? (int)$r->fetch_assoc()['n'] : 0;
    } catch (mysqli_sql_exception $e) { return 0; }
}
$stat_vehicles  = count_rows($conn, 'Vehicles');
$stat_drivers   = count_rows($conn, 'Drivers');
$stat_depots    = count_rows($conn, 'Depots');
$stat_mechanics = count_rows($conn, 'Mechanics');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Admin Terminal</title>
<link rel="stylesheet" href="style.css">
</head>
<body data-greet-name="<?= h($greet_name) ?>">

<div class="header">
  <div class="mark">
    <?= version_switch_plate() ?>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Admin &middot; <?= h($admin_name) ?></span>
    </div>
  </div>
  <div class="meta"><span class="live">Online</span></div>
  <a href="dashboard.php" class="back">Dashboard</a>
  <a href="account.php" class="back">Account</a>
  <a href="logout.php" class="back">End shift</a>
</div>

<div id="greet-line"></div>

<div class="wrap">

<!-- Quiet section label instead of a marketing hero (Deference). -->
<div class="section-label reveal">Fleet Overview</div>

<section class="stats reveal" style="transition-delay:.08s">
  <div class="stat"><span class="stat-num" data-count="<?= $stat_vehicles ?>">0</span><span class="stat-label">Vehicles</span></div>
  <div class="stat"><span class="stat-num" data-count="<?= $stat_drivers ?>">0</span><span class="stat-label">Drivers</span></div>
  <div class="stat"><span class="stat-num" data-count="<?= $stat_depots ?>">0</span><span class="stat-label">Depots</span></div>
  <div class="stat"><span class="stat-num" data-count="<?= $stat_mechanics ?>">0</span><span class="stat-label">Mechanics</span></div>
</section>

<?php if ($notice): ?>
  <?php
    // Colour the banner red for failures, green otherwise.
    $isErr = (stripos($notice, 'could not') !== false)
          || (stripos($notice, 'not available') !== false)
          || (stripos($notice, 'incorrect') !== false)
          || (stripos($notice, 'do not match') !== false)
          || (stripos($notice, 'required') !== false)
          || (stripos($notice, 'at least') !== false)
          || (stripos($notice, 'no ') !== false && stripos($notice, 'found') !== false)
          || (stripos($notice, 'not available for') !== false)
          || (stripos($notice, 'valid account') !== false)
          || (stripos($notice, 'try again') !== false);
  ?>
  <div class="flash <?= $isErr ? 'flash-err' : 'flash-ok' ?>"><?= h($notice) ?></div>
<?php endif; ?>

<?php
  // After an action the page reloads; keep the tab the user was working in
  // open instead of snapping back to Vehicles. Map each action to its tab.
  $submittedAction = $_POST['action'] ?? '';
  $actionTab = [
      'add_vehicle' => 1, 'delete_vehicle' => 1, 'assign_vehicle' => 1, 'unassign_vehicle' => 1,
      'add_driver' => 2,
      'add_depot' => 3,
      'add_mechanic' => 4,
      'approve_event' => 5, 'delete_event' => 5,
  ];
  $openTab = $actionTab[$submittedAction] ?? 1;
?>
<input type="radio" name="tab" id="tab1" class="tab-input"<?= $openTab === 1 ? ' checked' : '' ?>>
<input type="radio" name="tab" id="tab2" class="tab-input"<?= $openTab === 2 ? ' checked' : '' ?>>
<input type="radio" name="tab" id="tab3" class="tab-input"<?= $openTab === 3 ? ' checked' : '' ?>>
<input type="radio" name="tab" id="tab4" class="tab-input"<?= $openTab === 4 ? ' checked' : '' ?>>
<input type="radio" name="tab" id="tab5" class="tab-input"<?= $openTab === 5 ? ' checked' : '' ?>>

<!-- Liquid Glass filter: an SVG displacement map bends the page seen through
     the nav bar (real refraction/lensing, not just blur). Chrome/Edge only;
     Safari/Firefox fall back to the CSS glassmorphism defined in style.css. -->
<svg class="lg-defs" width="0" height="0" aria-hidden="true">
  <defs>
    <!-- Displacement map. Neutral grey (128,128,_) = no shift. Toward the
         edges the R (x) and G (y) channels diverge from 128 so the backdrop
         is pushed outward from centre → the pixels near the rim bend, which
         reads as refraction through curved glass. -->
    <linearGradient id="lg-hx" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0"   stop-color="rgb(40,128,0)"/>
      <stop offset="0.5" stop-color="rgb(128,128,0)"/>
      <stop offset="1"   stop-color="rgb(216,128,0)"/>
    </linearGradient>
    <linearGradient id="lg-vy" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0"   stop-color="rgb(0,40,0)"/>
      <stop offset="0.5" stop-color="rgb(0,0,0)"/>
      <stop offset="1"   stop-color="rgb(0,216,0)"/>
    </linearGradient>
    <filter id="liquid-glass" x="-20%" y="-20%" width="140%" height="140%" color-interpolation-filters="sRGB">
      <feImage href="#lg-map" result="map"/>
      <feDisplacementMap in="SourceGraphic" in2="map" scale="16" xChannelSelector="R" yChannelSelector="G" result="disp"/>
      <!-- Chromatic-aberration fringe at the rim: split R/B a hair apart. -->
      <feColorMatrix in="disp" type="matrix" values="1 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 1 0" result="r"/>
      <feColorMatrix in="disp" type="matrix" values="0 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 1 0" result="gb"/>
      <feOffset in="r" dx="1.4" dy="0" result="ro"/>
      <feBlend in="ro" in2="gb" mode="screen"/>
    </filter>
  </defs>
  <!-- Two overlaid gradients form the lens map: horizontal drives X, vertical
       (screened on) drives Y. -->
  <g id="lg-map">
    <rect width="360" height="46" fill="url(#lg-hx)"/>
    <rect width="360" height="46" fill="url(#lg-vy)" style="mix-blend-mode:screen"/>
  </g>
</svg>

<div class="tabs">
  <label for="tab1"><span class="tab-ico"><?= ui_icon('truck') ?></span>Vehicles</label>  <label for="tab2"><span class="tab-ico"><?= ui_icon('user') ?></span>Drivers</label>
  <label for="tab3"><span class="tab-ico"><?= ui_icon('building') ?></span>Depots</label>
  <label for="tab4"><span class="tab-ico"><?= ui_icon('wrench') ?></span>Mechanics</label>
  <label for="tab5"><span class="tab-ico"><?= ui_icon('clipboard') ?></span>Review queue</label>
</div>

<!-- ==================== VEHICLES ==================== -->
<div id="content1" class="tab-content">
  <div class="docket">
    <div class="docket-head"><h2>Register a vehicle</h2></div>
    <form method="POST" action="admin.php">
      <input type="hidden" name="action" value="add_vehicle">
      <?= csrf_field() ?>
      <div class="form-row">
        <div class="form-group"><label>VIN</label><input type="text" name="vin" required style="width:180px"></div>
        <div class="form-group"><label>Registration</label><input type="text" name="registration_number" required style="width:120px"></div>
        <div class="form-group"><label>Manufacturer</label><input type="text" name="manufacturer" required style="width:140px"></div>
        <div class="form-group"><label>Model</label><input type="text" name="model" required style="width:140px"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Category</label><select name="category_id"><?php if($cats_opts){$cats_opts->data_seek(0); while($c=$cats_opts->fetch_assoc()): ?><option value="<?= (int)$c['category_id'] ?>"><?= h($c['category_name']) ?></option><?php endwhile;} ?></select></div>
        <div class="form-group"><label>Year</label><input type="number" name="year_of_manufacture" value="2024" style="width:90px"></div>
        <div class="form-group"><label>Depot</label><select name="current_depot_id"><?php if($depots_opts){$depots_opts->data_seek(0); while($d=$depots_opts->fetch_assoc()): ?><option value="<?= (int)$d['depot_id'] ?>"><?= h($d['depot_name']) ?></option><?php endwhile;} ?></select></div>
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
          "SELECT v.vin, v.registration_number, v.manufacturer, v.model,
          vc.category_name,
          v.year_of_manufacture, v.operational_status,
          d.depot_name,
          va.driver_id,
          TRIM(CONCAT(COALESCE(dr.first_name,''), ' ', COALESCE(dr.last_name,''))) AS driver_name
          FROM Vehicles v
          JOIN Vehicle_Categories vc ON v.category_id = vc.category_id
          JOIN Depots d ON v.current_depot_id = d.depot_id
          LEFT JOIN Vehicle_Assignments va
          ON v.vin = va.vin AND va.unassigned_at IS NULL
          LEFT JOIN Drivers dr ON va.driver_id = dr.driver_id
          ORDER BY v.vin");
        if ($res && $res->num_rows > 0) {
          // Build driver <option> list once, reused per row.
          $driverOptions = "";
          foreach ($drivers_opts as $d) {
            $dname = trim($d['first_name'] . ' ' . $d['last_name']);
            $driverOptions .= "<option value='" . (int)$d['driver_id'] . "'>" . h($dname) . "</option>";
          }
          while ($row = $res->fetch_assoc()) {
            $driver = trim($row['driver_name'] ?? '');
            $vinEsc = h($row['vin']);
            $hasDriver = !is_null($row['driver_id']) && $row['driver_id'] !== '';
            echo "<tr>";
            echo "<td class='mono'>" . $vinEsc . "</td>";
            echo "<td class='mono'>" . h($row['registration_number']) . "</td>";
            echo "<td>" . h($row['manufacturer'] . ' ' . $row['model']) . "</td>";
            echo "<td>" . h($row['category_name']) . "</td>";
            echo "<td class='num'>" . (int)$row['year_of_manufacture'] . "</td>";
            echo "<td>" . status_badge((string)($row['operational_status'] ?? '')) . "</td>";
            echo "<td>" . h($row['depot_name']) . "</td>";
            echo "<td>" . ($driver !== '' ? h($driver) : "No driver assigned") . "</td>";
            echo "<td>";
            // Action cell laid out as two tidy rows in a fixed-width column:
            //   row 1: driver dropdown + Assign
            //   row 2: Unassign (if assigned) + Delete
            echo "<div class='act'>";
            echo "<form method='POST' action='admin.php' class='act-row'>"
               . csrf_field()
               . "<input type='hidden' name='action' value='assign_vehicle'>"
               . "<input type='hidden' name='vin' value='$vinEsc'>"
               . "<select name='driver_id' class='act-select'>$driverOptions</select>"
               . "<button class='action-btn'>Assign</button></form>";
            echo "<div class='act-row'>";
            if ($hasDriver) {
              echo "<form method='POST' action='admin.php' style='display:inline'>"
                 . csrf_field()
                 . "<input type='hidden' name='action' value='unassign_vehicle'>"
                 . "<input type='hidden' name='vin' value='$vinEsc'>"
                 . "<button class='action-btn'>Unassign</button></form>";
            }
            echo "<form method='POST' action='admin.php' onsubmit=\"return confirm('Delete this vehicle?')\" style='display:inline'>" . csrf_field() . "<input type='hidden' name='action' value='delete_vehicle'><input type='hidden' name='vin' value='$vinEsc'><button class='action-btn del'>Delete</button></form>";
            echo "</div>";
            echo "</div>";
            echo "</td>";
            echo "</tr>";
          }
        } else {
          echo empty_row(9, "No vehicles yet", "Add a vehicle above to get started.", "truck");
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
      <?= csrf_field() ?>
      <div class="form-row">
        <div class="form-group"><label>First name</label><input type="text" name="first_name" required style="width:140px"></div>
        <div class="form-group"><label>Last name</label><input type="text" name="last_name" required style="width:140px"></div>
        <div class="form-group"><label>Licence</label><select name="licence_type"><option>Class C</option><option>Class D</option><option>Class E</option></select></div>
        <div class="form-group"><label>Contact</label><input type="text" name="contact_information" style="width:150px"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Depot</label><select name="current_depot_id"><?php if($depots_opts){$depots_opts->data_seek(0); while($d=$depots_opts->fetch_assoc()): ?><option value="<?= (int)$d['depot_id'] ?>"><?= h($d['depot_name']) ?></option><?php endwhile;} ?></select></div>
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
            echo "<td>" . h(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
            echo "<td>" . h($row['license_type'] ?: '—') . "</td>";
            echo "<td class='mono'>" . h($row['contact_information']) . "</td>";
            echo "<td>" . h($row['depot_name']) . "</td>";
            echo "<td>" . status_badge((string)($row['employment_status'] ?? '')) . "</td>";
            echo "</tr>";
          }
        } else {
          echo empty_row(6, "No drivers yet", "Add a driver above to build your roster.", "user");
        }
        } catch (mysqli_sql_exception $e) {
          echo empty_row(6, "Database not available", "The Drivers table could not be reached.", "user");
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
      <?= csrf_field() ?>
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
            echo "<tr><td class='mono'>" . (int)$row['depot_id'] . "</td><td>" . h($row['depot_name']) . "</td>";
            if ($locCol) { echo "<td>" . h($row['location']) . "</td>"; }
            echo "</tr>";
          }
        } else {
          echo empty_row(3, "No depots yet", "Open a depot above to add locations.", "building");
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
      <?= csrf_field() ?>
      <div class="form-row">
        <div class="form-group"><label>First name</label><input type="text" name="first_name" required style="width:140px"></div>
        <div class="form-group"><label>Last name</label><input type="text" name="last_name" required style="width:140px"></div>
        <div class="form-group"><label>Contact</label><input type="text" name="contact_information" style="width:150px"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Depot</label><select name="depot_id"><?php if($depots_opts){$depots_opts->data_seek(0); while($d=$depots_opts->fetch_assoc()): ?><option value="<?= (int)$d['depot_id'] ?>"><?= h($d['depot_name']) ?></option><?php endwhile;} ?></select></div>
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
              echo empty_row(6, "Database not available", "The Mechanics table could not be reached.", "wrench");
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
                echo "<td>" . h(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
                echo "<td class='mono'>" . h($row['contact_information'] ?: '—') . "</td>";
                echo "<td>" . h($row['depot_name']) . "</td>";
                echo "<td>" . h($row['specialization'] ?: '—') . "</td>";
                echo "<td class='mono'>" . h($row['hire_date'] ?: '—') . "</td>";
                echo "</tr>";
              }
            } else {
              echo empty_row(6, "No mechanics yet", "Add a mechanic above to build the workshop.", "wrench");
            }
          }
        } catch (mysqli_sql_exception $e) {
          echo empty_row(6, "Database not available", "The Mechanics table could not be reached.", "wrench");
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
              echo empty_row(8, "Database not available", "Report tables could not be reached.", "clipboard");
          } else {
            // Adapt to the real timestamp column name.
            $tsCol = find_column($conn, 'Telematics_Events', ['times_tamp', 'event_timestamp', 'timestamp', 'created_at']);
            $tsSel = $tsCol ? "te.`$tsCol` AS ts" : "'' AS ts";
            $res = $conn->query(
              "SELECT te.event_id, $tsSel, te.event_type, te.vin, te.severity_level,
                      te.driver_id, dr.first_name, dr.last_name,
                      COALESCE(te.depot_id, '') AS depot_name
               FROM Telematics_Events te
               JOIN Safety_Reviews sr ON sr.event_id = te.event_id AND sr.is_completed = 0
               LEFT JOIN Drivers dr ON te.driver_id = dr.driver_id
               ORDER BY te.event_id DESC");
            if ($res && $res->num_rows > 0) {
              while ($row = $res->fetch_assoc()) {
                $eid = (int)$row['event_id'];
                echo "<tr>";
                echo "<td class='mono'>$eid</td>";
                echo "<td class='mono'>" . h($row['ts'] ?: '—') . "</td>";
                echo "<td>" . h($row['event_type'] ?? '') . "</td>";
                echo "<td class='mono'>" . h($row['vin'] ?? '') . "</td>";
                echo "<td>" . h(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) . "</td>";
                echo "<td>" . h((string)($row['depot_name'] ?? '')) . "</td>";
                echo "<td><span class='mk fair'>" . h($row['severity_level'] ?? '') . "</span></td>";
                echo "<td>"
                   . "<form method='POST' action='admin.php'>" . csrf_field() . "<input type='hidden' name='action' value='approve_event'><input type='hidden' name='event_id' value='$eid'><button class='action-btn'>Approve</button></form>"
                   . "<form method='POST' action='admin.php' onsubmit=\"return confirm('Reject and delete this report?')\">" . csrf_field() . "<input type='hidden' name='action' value='delete_event'><input type='hidden' name='event_id' value='$eid'><button class='action-btn del'>Delete</button></form>"
                   . "</td>";
                echo "</tr>";
              }
            } else {
              echo empty_row(8, "No reports awaiting review", "New driver reports will appear here.", "clipboard");
            }
          }
        } catch (mysqli_sql_exception $e) {
          echo empty_row(8, "Database not available", "Report tables could not be reached.", "clipboard");
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
              echo empty_row(7, "Database not available", "Report tables could not be reached.", "clipboard");
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
                echo "<td class='mono'>" . h($row['ts'] ?: '—') . "</td>";
                echo "<td>" . h($row['event_type']) . "</td>";
                echo "<td class='mono'>" . h($row['vin']) . "</td>";
                echo "<td>" . h(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
                echo "<td><span class='mk avail'>" . h($row['severity_level']) . "</span></td>";
                echo "<td class='mono'>" . h($row['reviewed_by']) . "</td>";
                echo "</tr>";
              }
            } else {
              echo empty_row(7, "No approved reports yet", "Approved reports will be listed here.", "clipboard");
            }
          }
        } catch (mysqli_sql_exception $e) {
          echo empty_row(7, "Database not available", "Report tables could not be reached.", "clipboard");
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
