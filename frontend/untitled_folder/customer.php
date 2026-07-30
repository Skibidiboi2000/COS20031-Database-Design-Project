<?php
session_start();
include 'dbconn.php'
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
    <!-- ==================== VEHICLES SEARCH FORM ==================== -->
    <form method="GET" action="customer.php">
    <!-- Category dropdown -->
    <label for="category">Category:</label>
    <select name="category" id="category">
        <option value="">All Categories</option>
        <option value="Delivery Van">Delivery Van</option>
        <option value="Refrigerated Truck">Refrigerated Truck</option>
        <option value="Electric Van">Electric Van</option>
        <option value="Service Vehicle">Service Vehicle</option>
        <option value="Heavy Transport Truck">Heavy Transport Truck</option>
    </select>

    <!-- Year dropdown -->
    <label for="year">Year:</label>
    <select name="year" id="year">
        <option value="">All Years</option>
        <?php
        for ($y = 2010; $y <= 2026; $y++) {
            echo "<option value='$y'>$y</option>";
        }
        ?>
    </select>

    <!-- Depot dropdown -->
    <label for="depot">Depot:</label>
    <select name="depot" id="depot">
        <option value="">All Depots</option>
        <option value="Ha Noi">Ha Noi</option>
        <option value="Da Nang">Da Nang</option>
        <option value="Ho Chi Minh City">Ho Chi Minh City</option>
        <option value="Can Tho">Can Tho</option>
    </select>

    <!-- Status dropdown -->
    <label for="status">Status:</label>
    <select name="status" id="status">
        <option value="">All Statuses</option>
        <option value="Available">Available</option>
        <option value="Active">Active</option>
        <option value="Under Maintenance">Under Maintenance</option>
        <option value="Retired">Retired</option>
        <option value="Awaiting Inspection">Awaiting Inspection</option>
        <option value="Out of Service">Out of Service</option>
    </select>

    <button type="submit">Search</button>
</form>

 <!-- ==================== VEHICLES SEARCH FORM ==================== -->

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

          $sql = "SELECT vin, registration_number, model, vc.category_name as vehicle_type, year_of_manufacture, operational_status, d.depot_name as depot_name
                  FROM Vehicles v
                  JOIN Vehicle_Categories vc
                  ON v.category_id = vc.category_id  
                  JOIN Depots d
                  ON v.current_depot_id = d.depot_id
                  ";
          if (count($conditions) > 0) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
            }

            $result = $conn->query($sql);

          if ($result && $result->num_rows > 0) {
              while($row = $result->fetch_assoc()) {
                  echo "<tr>";
                  echo "<td>" . $row["vin"] . "</td>";
                  echo "<td>" . $row["registration_number"] . "</td>";
                  echo "<td>" . $row["model"] . "</td>";
                  echo "<td>" . $row["vehicle_type"] . "</td>";
                  echo "<td>" . $row["year_of_manufacture"] . "</td>";
                  echo "<td>" . $row["operational_status"] . "</td>";
                  echo "<td>" . $row["depot_name"] . "</td>";
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

