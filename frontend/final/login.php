<?php
/*
 * login.php — Main sign-in terminal.
 *
 * Three roles sign in here: Customer, Staff (Driver or Mechanic), and Admin.
 * Staff sign in by email — we look the email up in the database: if it is in
 * the Drivers table the user goes to driver.php, if it is in the Mechanics
 * table they go to mechanic.php. The email column name is auto-detected so
 * this works across schema variations.
 *
 * On a failed login we use the Post/Redirect/Get pattern: the error is stored
 * in the session, then we redirect to this same page with GET. That way a
 * browser refresh does not re-submit the form, and the error clears itself
 * once shown.
 */
session_start();
include 'dbconn.php';

/**
 * Look $email up in $table (Drivers or Mechanics). Detects the email column
 * at runtime and returns the matching row, or null if the table/column is
 * missing or no row matches. Uses a prepared statement so input is safe.
 */
function find_staff_by_email(mysqli $conn, string $table, string $email): ?array {
    $emailCol = find_column($conn, $table, ['email', 'email_address', 'contact_email', 'contact_information']);
    if ($emailCol === null) {
        return null; // table or a usable email column doesn't exist
    }
    try {
        $stmt = $conn->prepare("SELECT * FROM `$table` WHERE `$emailCol` = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    } catch (mysqli_sql_exception $e) {
        return null;
    }
}

/**
 * Look an admin up by username in the Admin table (columns: admin_id,
 * username, password_hash). Returns the row or null. Uses a prepared
 * statement; wrapped in try/catch so a missing table can't crash login.
 */
function find_admin_by_username(mysqli $conn, string $username): ?array {
    if (!table_exists($conn, 'Admin')) {
        return null;
    }
    try {
        $stmt = $conn->prepare("SELECT * FROM `Admin` WHERE `username` = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    } catch (mysqli_sql_exception $e) {
        return null;
    }
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Which tab did they use? customer | staff (driver/mechanic) | admin.
    $tab = $_POST['role'] ?? '';
    // Both the email and admin "username" fields hold the account email.
    $email    = trim($_POST['email'] ?? ($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';

    // Roles each tab is allowed to sign in.
    $tabRoles = [
        'customer' => ['customer', 'user'],
        'driver'   => ['driver', 'mechanic'],   // the Staff tab
        'mechanic' => ['driver', 'mechanic'],
        'admin'    => ['admin'],
    ];

    if (!isset($tabRoles[$tab])) {
        $error = "Please choose a role to sign in.";
    } elseif ($email === '' || $password === '') {
        $error = "Please enter your email and password.";
    } else {
        $acct = find_account_by_email($conn, $email);

        if (!$acct) {
            $error = "No account found for that email.";
        } elseif (isset($acct['is_active']) && (int)$acct['is_active'] === 0) {
            $error = "This account has been deactivated.";
        } elseif (!password_verify($password, $acct['password_hash'] ?? '')) {
            $error = "Incorrect email or password.";
        } else {
            $acctRole = strtolower(trim($acct['role'] ?? ''));
            // 'user' is treated as a customer.
            if ($acctRole === 'user') $acctRole = 'customer';

            if (!in_array($acctRole, $tabRoles[$tab], true)) {
                // Right credentials, wrong tab — point them to the correct one.
                $niceTab = ['customer' => 'Customer', 'driver' => 'Staff', 'mechanic' => 'Staff', 'admin' => 'Admin'][$acctRole] ?? ucfirst($acctRole);
                $error = "That's a {$acctRole} account — use the {$niceTab} tab.";
            } else {
                // Success. Store a common identity plus role-specific keys the
                // existing dashboards already read.
                $fullName = trim(($acct['first_name'] ?? '') . ' ' . ($acct['last_name'] ?? ''));
                $acctId   = (int)($acct['account_id'] ?? 0);
                $_SESSION['role']       = $acctRole;
                $_SESSION['account_id'] = $acctId;
                $_SESSION['email']      = $email;

                if ($acctRole === 'admin') {
                    $_SESSION['admin_id']        = $acctId;
                    $_SESSION['admin_name']      = $email;
                    $_SESSION['admin_full_name'] = $fullName;
                    header("Location: admin.php"); exit;
                } elseif ($acctRole === 'driver') {
                    $_SESSION['driver_id']    = $acctId;
                    $_SESSION['driver_email'] = $email;
                    $_SESSION['driver_name']  = $fullName ?: 'Driver';
                    header("Location: driver.php"); exit;
                } elseif ($acctRole === 'mechanic') {
                    $_SESSION['mechanic_id']    = $acctId;
                    $_SESSION['mechanic_email'] = $email;
                    $_SESSION['mechanic_name']  = $fullName ?: 'Mechanic';
                    header("Location: mechanic.php"); exit;
                } else { // customer
                    $_SESSION['customer_email'] = $email;
                    $_SESSION['customer_name']  = $fullName;
                    header("Location: customer.php"); exit;
                }
            }
        }
    }

    // Post/Redirect/Get: keep the error out of the POST so refresh won't repeat it.
    $_SESSION['login_error'] = $error;
    $_SESSION['login_role'] = $tab;
    header("Location: login.php");
    exit;
}

// Read the one-time messages (if any) then clear them, so they disappear on a
// refresh or when switching tabs. $submittedRole reopens the correct tab.
$error = $_SESSION['login_error'] ?? "";
$submittedRole = $_SESSION['login_role'] ?? "";
$notice = $_SESSION['signup_ok'] ?? "";
unset($_SESSION['login_error'], $_SESSION['login_role'], $_SESSION['signup_ok']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Sign In</title>
<link rel="stylesheet" href="style.css?v=khang24">
</head>
<body class="login-body">

<div class="login-topbar">
  <?= version_switch_plate() ?>
  <span class="id">Smart Fleet &middot; Terminal Access</span>
  <span style="margin-left:auto">
    <a href="auth.php" class="back">Terminal signup</a>
  </span>
</div>

<div class="login-wrap">

<div class="login-brand reveal">
  <div class="eyebrow">Fantastic Four &middot; Fleet Ops</div>
  <h1>Smart Fleet<br>Dispatch Terminal</h1>
  <p>Heavy haulage across Hanoi, HCMC,<br>Da Nang &amp; Hai Phong. Sign in to begin your shift.</p>
</div>

<?php if ($notice): ?>
  <div class="flash flash-ok reveal"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="flash flash-err reveal"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php
  // Keep the correct tab open after a failed submit ($submittedRole came from the flash session above).
  $isStaff = ($submittedRole === 'driver' || $submittedRole === 'mechanic');
  $isAdmin = ($submittedRole === 'admin');
  $isCustomer = !$isStaff && !$isAdmin;
?>
<input type="radio" name="role" id="role1" class="tab-input"<?= $isCustomer ? ' checked' : '' ?>>
<input type="radio" name="role" id="role2" class="tab-input"<?= $isStaff ? ' checked' : '' ?>>
<input type="radio" name="role" id="role3" class="tab-input"<?= $isAdmin ? ' checked' : '' ?>>

<div class="role-tabs reveal" style="transition-delay:.08s">
  <label for="role1">Customer</label>
  <label for="role2">Staff</label>
  <label for="role3">Admin</label>
</div>

<!-- Customer: email step -> password step (progressive disclosure). -->
<div id="login1" class="login-box" style="transition-delay:.14s">
  <form method="POST" action="login.php" class="two-step" data-role="customer">
    <input type="hidden" name="role" value="customer">
    <div class="step step-email">
      <span class="stamp step-title">Customer access</span>
      <div class="form-group"><label>Email</label><input type="text" name="email" autocomplete="username" placeholder="you@company.com"></div>
      <button type="button" class="form-btn primary btn-continue">Continue</button>
    </div>
    <div class="step step-pass">
      <div class="form-group"><label>Password</label><input type="password" name="password" autocomplete="current-password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
      <button type="submit" class="form-btn primary">Sign in</button>
      <button type="button" class="link-back">&larr; Use a different email</button>
    </div>
  </form>
  <div class="hint">
    Track shipments<br>
    Review delivery history<br>
    Reach support
  </div>
</div>

<!-- Staff: one email field; the domain decides Driver vs Mechanic on Continue. -->
<div id="login2" class="login-box" style="transition-delay:.14s">
  <form method="POST" action="login.php" class="two-step" data-role="staff">
    <input type="hidden" name="role" value="driver">
    <div class="step step-email">
      <span class="stamp step-title">Staff access</span>
      <div class="form-group"><label>Email</label><input type="text" name="email" autocomplete="username" placeholder="you@company.com"></div>
      <button type="button" class="form-btn primary btn-continue">Continue</button>
    </div>
    <div class="step step-pass">
      <div class="form-group"><label>Password</label><input type="password" name="password" autocomplete="current-password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
      <button type="submit" class="form-btn primary btn-signin">Sign in</button>
      <button type="button" class="link-back">&larr; Use a different email</button>
    </div>
  </form>
  <div class="hint">
    Sign in with your staff email<br>
    We'll take you to the right place automatically
  </div>
</div>

<!-- Admin: email step -> password step. -->
<div id="login3" class="login-box" style="transition-delay:.14s">
  <form method="POST" action="login.php" class="two-step" data-role="admin">
    <input type="hidden" name="role" value="admin">
    <div class="step step-email">
      <span class="stamp step-title">Admin access</span>
      <div class="form-group"><label>Email</label><input type="text" name="email" autocomplete="username" placeholder="you@company.com"></div>
      <button type="button" class="form-btn primary btn-continue">Continue</button>
    </div>
    <div class="step step-pass">
      <div class="form-group"><label>Password</label><input type="password" name="password" autocomplete="current-password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
      <button type="submit" class="form-btn primary">Sign in</button>
      <button type="button" class="link-back">&larr; Use a different email</button>
    </div>
  </form>
  <div class="hint">
    Run fleet operations<br>
    Review &amp; approve driver reports<br>
    Manage users &amp; access
  </div>
</div>

</div>
<script src="motion.js"></script>
<script src="login.js"></script>
</body>
</html>
