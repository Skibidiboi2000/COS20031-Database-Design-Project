<?php
session_start();
include 'dbc<?php
session_start();
include 'dbconn.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Customer Portal — Fleet Management</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="header">
  <h1>Customer Portal</h1>
  <span style="margin-left:auto">
    <a href="login.php" class="back" style="color:#f8d7da">Log out</a>
  </span>
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
    <div class="table-section">
      <h2>Vehicles</h2>
      <table>
        <thead>
          <tr>
            <th>VIN</th>
            <th>Registration</th>
            <th>Model</th>
            <th>Category</th>
            <th>Year</th>
            <th>Status</th>
            <th>Depot</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $sql = "SELECT vin, registration_number, model, category_id, year_of_manufacture, operational_status, current_depot_id 
                  FROM Vehicles";
          $result = $conn->query($sql);

          if ($result && $result->num_rows > 0) {
              while($row = $result->fetch_assoc()) {
                  echo "<tr>";
                  echo "<td>" . $row["vin"] . "</td>";
                  echo "<td>" . $row["registration_number"] . "</td>";
                  echo "<td>" . $row["model"] . "</td>";
                  echo "<td>" . $row["category_id"] . "</td>";
                  echo "<td>" . $row["year_of_manufacture"] . "</td>";
                  echo "<td>" . $row["operational_status"] . "</td>";
                  echo "<td>" . $row["current_depot_id"] . "</td>";
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

  <!-- ==================== INCIDENTS ==================== -->
  <div id="content2" class="tab-content">
    <div class="table-section">
      <h2>Incident Reports</h2>
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Date</th><th>Type</th><th>Vehicle</th>
            <th>Driver</th><th>Depot</th><th>Severity</th>
            <th>Status</th><th>Cost (VND)</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>1</td><td>2026-07-15</td><td>Collision</td><td>2FZHAZCVX5AB12345</td><td>Nguyen Van An</td><td>Hanoi Main</td><td>Major</td><td>Under Investigation</td><td style="text-align:right">15,000,000</td></tr>
          <tr><td>2</td><td>2026-07-18</td><td>Breakdown</td><td>CDE345FGH67890123</td><td>Bui Van Huy</td><td>Hanoi Main</td><td>Minor</td><td>Closed</td><td style="text-align:right">3,500,000</td></tr>
          <!-- … keep the rest of your rows here … -->
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
onn.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Customer Portal — Fleet Management</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="header">
  <h1>Customer Portal</h1>
  <span style="margin-left:auto">
    <a href="login.php" class="back" style="color:#f8d7da">Log out</a>
  </span>
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
    <div class="table-section">
      <h2>Vehicles</h2>
      <table>
        <thead>
          <tr>
            <th>VIN</th>
            <th>Registration</th>
            <th>Model</th>
            <th>Category</th>
            <th>Year</th>
            <th>Status</th>
            <th>Depot</th>
          </tr>
        </thead>
        <tbody>
          <!-- Static rows now, later replace with PHP loop -->
          <tr><td>1HGBH41JXMN109186</td><td>71C-12345</td><td>Kenworth T680</td><td>Heavy Truck</td><td>2021</td><td><span class="badge avail">Available</span></td><td>Hanoi Main</td></tr>
          <tr><td>2FZHAZCVX5AB12345</td><td>51F-67890</td><td>Volvo FH16</td><td>Heavy Truck</td><td>2022</td><td><span class="badge road">On Road</span></td><td>Hanoi Main</td></tr>
          <tr><td>3AKJGBDC5SCLA1234</td><td>30A-11111</td><td>Scania R500</td><td>Heavy Truck</td><td>2020</td><td><span class="badge road">On Road</span></td><td>Da Nang</td></tr>
          <!-- … keep the rest of your rows here … -->
        </tbody>
      </table>
    </div>
  </div>

  <!-- ==================== INCIDENTS ==================== -->
  <div id="content2" class="tab-content">
    <div class="table-section">
      <h2>Incident Reports</h2>
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Date</th><th>Type</th><th>Vehicle</th>
            <th>Driver</th><th>Depot</th><th>Severity</th>
            <th>Status</th><th>Cost (VND)</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>1</td><td>2026-07-15</td><td>Collision</td><td>2FZHAZCVX5AB12345</td><td>Nguyen Van An</td><td>Hanoi Main</td><td>Major</td><td>Under Investigation</td><td style="text-align:right">15,000,000</td></tr>
          <tr><td>2</td><td>2026-07-18</td><td>Breakdown</td><td>CDE345FGH67890123</td><td>Bui Van Huy</td><td>Hanoi Main</td><td>Minor</td><td>Closed</td><td style="text-align:right">3,500,000</td></tr>
          <!-- … keep the rest of your rows here … -->
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
