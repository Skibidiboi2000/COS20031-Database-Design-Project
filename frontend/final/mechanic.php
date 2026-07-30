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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Mechanic Terminal</title>
<link rel="stylesheet" href="style.css?v=5">
</head>
<body>

<div class="header">
  <div class="mark">
    <span class="plate">M&middot;01</span>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Mechanic &middot; <?= htmlspecialchars($mechanic_email) ?></span>
    </div>
  </div>
  <div class="meta"><span class="live">On duty</span></div>
  <a href="login.php" class="back">End shift</a>
</div>

<div class="wrap">

<input type="radio" name="tab" id="tab1" class="tab-input" checked>
<input type="radio" name="tab" id="tab2" class="tab-input">
<input type="radio" name="tab" id="tab3" class="tab-input">

<div class="tabs">
  <label for="tab1">Fleet</label>
  <label for="tab2">Workshop</label>
  <label for="tab3">Maintenance</label>
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
            echo "<tr><td colspan='6'>Database not available.</td></tr>";
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
            echo "<tr><td colspan='7'>No maintenance jobs found</td></tr>";
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
