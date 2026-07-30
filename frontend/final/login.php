<?php
session_start();
include 'dbconn.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';

    // Demo authentication only. Replace with real credential checks / a users table.
    if ($role === 'customer') {
        $_SESSION['role'] = 'customer';
        header("Location: customer.php");
        exit;
    } elseif ($role === 'driver') {
        $driver_id = (int)($_POST['driver_id'] ?? 0);
        $_SESSION['role'] = 'driver';
        $_SESSION['driver_id'] = $driver_id;
        $_SESSION['driver_name'] = 'Driver';
        // If the ID matches a real driver, use their name/depot.
        $stmt = $conn->prepare("SELECT first_name, last_name, current_depot_id FROM Drivers WHERE driver_id = ?");
        $stmt->bind_param("i", $driver_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $_SESSION['driver_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
            $_SESSION['driver_depot_id'] = $row['current_depot_id'];
        }
        $stmt->close();
        header("Location: driver.php");
        exit;
    } elseif ($role === 'admin') {
        // Demo admin gate. Replace with a real admin credential check.
        $_SESSION['role'] = 'admin';
        $_SESSION['admin_name'] = $_POST['username'] ?: 'admin';
        header("Location: admin.php");
        exit;
    } else {
        $error = "Please choose a role.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Sign In</title>
<link rel="stylesheet" href="style.css?v=5">
</head>
<body class="login-body">

<div class="login-topbar">
  <span class="plate">FF&middot;01</span>
  <span class="id">Smart Fleet &middot; Terminal Access</span>
</div>

<div class="login-wrap">

<div class="login-brand">
  <div class="eyebrow">Fantastic Four &middot; Fleet Ops</div>
  <h1>Smart Fleet<br>Dispatch Terminal</h1>
  <p>Heavy haulage across Hanoi, HCMC,<br>Da Nang &amp; Hai Phong. Sign in to begin your shift.</p>
</div>

<?php if ($error): ?>
  <div class="stamp" style="display:block;background:var(--stop-wash);color:var(--stop);border:1px solid var(--stop);padding:8px 12px;margin-bottom:12px;font-family:var(--mono);font-size:11px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<input type="radio" name="role" id="role1" class="tab-input" checked>
<input type="radio" name="role" id="role2" class="tab-input">
<input type="radio" name="role" id="role3" class="tab-input">

<div class="role-tabs">
  <label for="role1">Customer</label>
  <label for="role2">Driver</label>
  <label for="role3">Admin</label>
</div>

<div id="login1" class="login-box">
  <span class="stamp">Customer access</span>
  <form method="POST" action="login.php">
    <input type="hidden" name="role" value="customer">
    <div class="form-group"><label>Email</label><input type="text" name="email" placeholder="you@company.com"></div>
    <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
    <button type="submit" class="form-btn primary">Sign in</button>
  </form>
  <div class="hint">
    Track shipments<br>
    Review delivery history<br>
    Reach support
  </div>
</div>

<div id="login2" class="login-box">
  <span class="stamp">Driver access</span>
  <form method="POST" action="login.php">
    <input type="hidden" name="role" value="driver">
    <div class="form-group"><label>Driver ID</label><input type="text" name="driver_id" placeholder="1"></div>
    <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
    <button type="submit" class="form-btn primary">Sign in</button>
  </form>
  <div class="hint">
    See your assigned unit<br>
    Report safety events<br>
    Track your review status
  </div>
</div>

<div id="login3" class="login-box">
  <span class="stamp">Admin access</span>
  <form method="POST" action="login.php">
    <input type="hidden" name="role" value="admin">
    <div class="form-group"><label>Username</label><input type="text" name="username" placeholder="admin"></div>
    <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
    <button type="submit" class="form-btn primary">Sign in</button>
  </form>
  <div class="hint">
    Run fleet operations<br>
    Review &amp; approve driver reports<br>
    Manage users &amp; access
  </div>
</div>

</div>
</body>
</html>
