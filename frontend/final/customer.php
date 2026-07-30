<?php
/*
 * customer.php — Customer portal.
 *
 * Two tabs: Vehicles (searchable/filterable list) and Incidents (approved
 * safety reports only). The vehicle search reads optional GET filters
 * (category, year, depot, status) and builds a WHERE clause from whichever
 * are set. The Incidents tab only shows reports whose review is completed.
 */
session_start();
include 'dbconn.php';

// Only signed-in customers (or registered users) may view this page.
$role = $_SESSION['role'] ?? '';
if ($role !== 'customer' && $role !== 'user') {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Customer Portal — Fleet Management</title>
  <link rel="stylesheet" href="style.css?v=5">
</head>
<body>

<div class="header">
  <div class="mark">
    <span class="plate">FF&middot;01</span>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Customer &middot; Shipment Tracking</span>
    </div>
  </div>
  <div class="meta"><span class="live">Online</span></div>
  <a href="login.php" class="back">Sign out</a>
</div>

<div class="wrap">

  <input type="radio" name="tab" id="tab1" class="tab-input" checked>
  <input type="radio" name="tab" id="tab2" class="tab-input">

  <div class="tabs">
    <label for="tab1">Vehicles</label>
    <label for="tab2">Incidents</label>
  </div>

  <!-- ==================== VEHICLES ==================== -->
  <div id="content1" class="tab-content">
    <div class="docket">
      <div class="docket-head"><h2>Search fleet</h2></div>
      <form method="GET" action="customer.php">
      <div class="form-row">
        <div class="form-group"><label for="category">Category</label>
        <select name="category" id="category">
            <option value="">All Categories</option>
            <?php
            $catRes = $conn->query("SELECT category_name FROM Vehicle_Categories ORDER BY category_name");
            while ($c = $catRes->fetch_assoc()) {
                $sel = (($_GET['category'] ?? '') === $c['category_name']) ? 'selected' : '';
                echo "<option value='" . htmlspecialchars($c['category_name']) . "' $sel>" . htmlspecialchars($c['category_name']) . "</option>";
            }
            ?>
        </select>
        </div>

        <div class="form-group"><label for="year">Year</label>
        <select name="year" id="year">
            <option value="">All Years</option>
            <?php
            for ($y = 2010; $y <= 2026; $y++) {
                $sel = (($_GET['year'] ?? '') == $y) ? 'selected' : '';
                echo "<option value='$y' $sel>$y</option>";
            }
            ?>
        </select>
        </div>

        <div class="form-group"><label for="depot">Depot</label>
        <select name="depot" id="depot">
            <option value="">All Depots</option>
            <?php
            $depRes = $conn->query("SELECT depot_name FROM Depots ORDER BY depot_name");
            while ($d = $depRes->fetch_assoc()) {
                $sel = (($_GET['depot'] ?? '') === $d['depot_name']) ? 'selected' : '';
                echo "<option value='" . htmlspecialchars($d['depot_name']) . "' $sel>" . htmlspecialchars($d['depot_name']) . "</option>";
            }
            ?>
        </select>
        </div>

        <div class="form-group"><label for="status">Status</label>
        <select name="status" id="status">
            <option value="">All Statuses</option>
            <?php
            $statuses = ['Available','Active','Under Maintenance','Retired','Awaiting Inspection','Out of Service'];
            foreach ($statuses as $s) {
                $sel = (($_GET['status'] ?? '') === $s) ? 'selected' : '';
                echo "<option value='" . htmlspecialchars($s) . "' $sel>" . htmlspecialchars($s) . "</option>";
            }
            ?>
        </select>
        </div>

        <div class="form-group" style="justify-content:flex-end"><button type="submit" class="form-btn primary">Search</button></div>
      </div>
    </form>
    </div>

    <div class="ledger">
      <div class="ledger-head"><h2>Vehicles</h2><span class="no">Live status</span></div>
      <div class="table-scroll">
      <table>
        <thead>
          <tr><th>VIN</th><th>Registration</th><th>Model</th><th>Category</th><th>Year</th><th>Status</th><th>Depot</th><th>Driver</th></tr>
        </thead>
        <tbody>
          <?php
            // Collect one SQL condition per filter the user actually chose.
            // Text values are escaped and years are cast to int to stay safe.
            $conditions = [];

            if (!empty($_GET['category'])) {
                $category = $conn->real_escape_string($_GET['category']);
                $conditions[] = "vc.category_name = '$category'";
            }
            if (!empty($_GET['year'])) {
                $year = (int)$_GET['year'];
                $conditions[] = "v.year_of_manufacture = $year";
            }
            if (!empty($_GET['depot'])) {
                $depot = $conn->real_escape_string($_GET['depot']);
                $conditions[] = "d.depot_name = '$depot'";
            }
            if (!empty($_GET['status'])) {
                $status = $conn->real_escape_string($_GET['status']);
                $conditions[] = "v.operational_status = '$status'";
            }

          $sql = "SELECT v.vin, v.registration_number, v.manufacturer, v.model,
                         vc.category_name AS vehicle_type, v.year_of_manufacture,
                         v.operational_status, d.depot_name AS depot_name,
                         TRIM(CONCAT(COALESCE(dr.first_name,''), ' ', COALESCE(dr.last_name,''))) AS driver_name
                  FROM Vehicles v
                  JOIN Vehicle_Categories vc ON v.category_id = vc.category_id
                  JOIN Depots d ON v.current_depot_id = d.depot_id
                  LEFT JOIN Drivers dr ON v.driver_id = dr.driver_id";
          // Join the chosen conditions with AND, or show everything if none were picked.
          if (count($conditions) > 0) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
          }
          $sql .= " ORDER BY v.vin";

          $result = $conn->query($sql);
          if ($result && $result->num_rows > 0) {
              while ($row = $result->fetch_assoc()) {
                  $driver = trim($row["driver_name"] ?? '');
                  echo "<tr>";
                  echo "<td class='mono'>" . htmlspecialchars($row["vin"]) . "</td>";
                  echo "<td class='mono'>" . htmlspecialchars($row["registration_number"]) . "</td>";
                  echo "<td>" . htmlspecialchars($row["manufacturer"] . ' ' . $row["model"]) . "</td>";
                  echo "<td>" . htmlspecialchars($row["vehicle_type"]) . "</td>";
                  echo "<td class='num'>" . (int)$row["year_of_manufacture"] . "</td>";
                  echo "<td>" . htmlspecialchars($row["operational_status"]) . "</td>";
                  echo "<td>" . htmlspecialchars($row["depot_name"]) . "</td>";
                  echo "<td>" . ($driver !== '' ? htmlspecialchars($driver) : "No driver assigned") . "</td>";
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

  <!-- ==================== INCIDENTS (approved reports only) ==================== -->
  <div id="content2" class="tab-content">
    <div class="ledger">
      <div class="ledger-head"><h2>Incident log</h2><span class="no">Approved reports only</span></div>
      <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Event</th><th>Date</th><th>Type</th><th>Vehicle</th><th>Driver</th><th>Depot</th><th>Severity</th></tr>
        </thead>
        <tbody>
          <?php
          try {
            if (!table_exists($conn, 'Telematics_Events') || !table_exists($conn, 'Safety_Reviews')) {
                echo "<tr><td colspan='7'>Database not available.</td></tr>";
            } else {
              // Adapt to the real timestamp column name across schemas.
              $tsCol = find_column($conn, 'Telematics_Events', ['times_tamp', 'event_timestamp', 'timestamp', 'created_at']);
              $tsSel = $tsCol ? "te.`$tsCol` AS ts" : "'' AS ts";
              $res = $conn->query(
                "SELECT te.event_id, $tsSel, te.event_type, te.vin, te.severity_level,
                        dr.first_name, dr.last_name, d.depot_name
                 FROM Telematics_Events te
                 JOIN Safety_Reviews sr ON sr.event_id = te.event_id AND sr.is_completed = 1
                 LEFT JOIN Drivers dr ON te.driver_id = dr.driver_id
                 LEFT JOIN Depots d ON te.depot_id = d.depot_id
                 ORDER BY te.event_id DESC");
              if ($res && $res->num_rows > 0) {
                  while ($row = $res->fetch_assoc()) {
                      echo "<tr>";
                      echo "<td class='mono'>" . (int)$row['event_id'] . "</td>";
                      echo "<td class='mono'>" . htmlspecialchars($row['ts'] ?: '—') . "</td>";
                      echo "<td>" . htmlspecialchars($row['event_type']) . "</td>";
                      echo "<td class='mono'>" . htmlspecialchars($row['vin']) . "</td>";
                      echo "<td>" . htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) . "</td>";
                      echo "<td>" . htmlspecialchars($row['depot_name']) . "</td>";
                      echo "<td>" . htmlspecialchars($row['severity_level']) . "</td>";
                      echo "</tr>";
                  }
              } else {
                  echo "<tr><td colspan='7'>No approved incidents to show</td></tr>";
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
