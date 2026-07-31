<?php
/*
 * mechanic.php — Mechanic terminal (read-only).
 *
 * Tabs: Fleet (all vehicles), Workshop (the Mechanics roster), and
 * Maintenance (jobs from Maintenance_Jobs). The Workshop and Maintenance
 * queries are wrapped in try/catch because those tables may not exist in
 * every database; they show "Database not available." instead of crashing.
 */
session_start();
include 'dbconn.php';

// Only signed-in mechanics may view this page.
if (($_SESSION['role'] ?? '') !== 'mechanic') {
    header("Location: login.php");
    exit;
}
$mechanic_email = $_SESSION['mechanic_email'] ?? 'Mechanic';
$mechanic_name = $_SESSION['mechanic_name'] ?? 'Mechanic';
$mechanic_id = (int)($_SESSION['mechanic_id'] ?? 0);
// Prefer the real first+last name from the DB; fall back to the email handle.
$greet_name = ($mechanic_name && $mechanic_name !== 'Mechanic')
    ? $mechanic_name
    : (strstr($mechanic_email, '@', true) ?: $mechanic_email);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Mechanic Terminal</title>
<link rel="stylesheet" href="style.css?v=khang24">
</head>
<body data-greet-name="<?= htmlspecialchars($greet_name) ?>">

<div class="header">
  <div class="mark">
    <?= version_switch_plate('M&middot;01') ?>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Mechanic &middot; <?= htmlspecialchars($mechanic_email) ?></span>
    </div>
  </div>
  <div class="meta"><span class="live">On duty</span></div>
  <a href="dashboard.php" class="back">Dashboard</a>
  <a href="account.php" class="back">Account</a>
  <a href="login.php" class="back">End shift</a>
</div>

<div id="greet-line"></div>

<div class="wrap">

<!-- Default to Maintenance — the tab a mechanic needs most (Clarity). -->
<input type="radio" name="tab" id="tab1" class="tab-input">
<input type="radio" name="tab" id="tab2" class="tab-input">
<input type="radio" name="tab" id="tab3" class="tab-input" checked>

<div class="tabs">
  <label for="tab1"><span class="tab-ico"><?= ui_icon('truck') ?></span>Fleet</label>
  <label for="tab2"><span class="tab-ico"><?= ui_icon('wrench') ?></span>Workshop</label>
  <label for="tab3"><span class="tab-ico"><?= ui_icon('clipboard') ?></span>Maintenance</label>
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
        $res = $conn->query(
          "SELECT v.vin, v.registration_number, v.manufacturer, v.model,
                  vc.category_name, v.year_of_manufacture, v.operational_status, d.depot_name
           FROM Vehicles v
           JOIN Vehicle_Categories vc ON v.category_id = vc.category_id
           JOIN Depots d ON v.current_depot_id = d.depot_id
           ORDER BY v.vin");
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                echo "<tr>";
                echo "<td class='mono'>" . htmlspecialchars($row['vin']) . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['registration_number']) . "</td>";
                echo "<td>" . htmlspecialchars($row['manufacturer'] . ' ' . $row['model']) . "</td>";
                echo "<td>" . htmlspecialchars($row['category_name']) . "</td>";
                echo "<td class='num'>" . (int)$row['year_of_manufacture'] . "</td>";
                echo "<td>" . status_badge($row['operational_status']) . "</td>";
                echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
                echo "</tr>";
            }
        } else {
            echo empty_row(7, "No vehicles yet", "Vehicles added by an admin will show here.", "truck");
        }
        ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ==================== WORKSHOP ROSTER ==================== -->
<div id="content2" class="tab-content">
  <div class="ledger">
    <div class="ledger-head"><h2>Workshop roster</h2><span class="no">View only</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>ID</th><th>Name</th><th>Contact</th><th>Depot</th><th>Specialisation</th><th>Hired</th></tr></thead>
      <tbody>
        <?php
        try {
          if (!table_exists($conn, 'Mechanics')) {
              echo empty_row(6, "Database not available", "The Mechanics table could not be reached.", "wrench");
          } else {
            // Select only the optional columns this database actually has.
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
                echo empty_row(6, "No mechanics yet", "Workshop staff added by an admin will show here.", "wrench");
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

<!-- ==================== MAINTENANCE JOBS ==================== -->
<div id="content3" class="tab-content">
  <div class="ledger">
    <div class="ledger-head"><h2>Maintenance jobs</h2><span class="no">View only</span></div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Job</th><th>Vehicle</th><th>Depot</th><th>Opened</th><th>Closed</th><th>Downtime (h)</th><th>Cost (VND)</th></tr></thead>
      <tbody>
        <?php
        try {
        $res = $conn->query(
          "SELECT j.job_id, j.vin, d.depot_name, j.date_opened, j.date_closed,
                  j.downtime_hours, j.total_cost_vnd
           FROM Maintenance_Jobs j
           LEFT JOIN Depots d ON j.depot_id = d.depot_id
           ORDER BY j.job_id DESC");
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                echo "<tr>";
                echo "<td class='mono'>" . (int)$row['job_id'] . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['vin']) . "</td>";
                echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['date_opened']) . "</td>";
                echo "<td class='mono'>" . htmlspecialchars($row['date_closed'] ?? '—') . "</td>";
                echo "<td class='num'>" . (int)$row['downtime_hours'] . "</td>";
                echo "<td class='num'>" . number_format((int)$row['total_cost_vnd']) . "</td>";
                echo "</tr>";
            }
        } else {
            echo empty_row(7, "No maintenance jobs", "Open jobs will appear here as they are logged.", "wrench");
        }
        } catch (mysqli_sql_exception $e) {
            echo empty_row(7, "Database not available", "The Maintenance_Jobs table could not be reached.", "wrench");
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
