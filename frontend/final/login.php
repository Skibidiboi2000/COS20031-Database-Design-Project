<?php
/*
 * login.php — Main sign-in terminal.
 *
 * Three roles sign in here: Customer, Staff (Driver or Mechanic), and Admin.
 * Staff sign in by email — the email domain decides where they go:
 *   @driver.com -> driver.php,  @mech.com -> mechanic.php.
 *
 * On a failed login we use the Post/Redirect/Get pattern: the error is stored
 * in the session, then we redirect to this same page with GET. That way a
 * browser refresh does not re-submit the form, and the error clears itself
 * once shown.
 */
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
    } elseif ($role === 'driver' || $role === 'mechanic') {
        // Staff login by email. The email domain must match the chosen role.
        $email = trim($_POST['email'] ?? '');
        // Take everything after the last "@" and lowercase it, e.g. "a@Driver.com" -> "driver.com".
        $domain = strtolower(substr(strrchr($email, "@") ?: '', 1));

        if ($domain !== 'driver.com' && $domain !== 'mech.com') {
            // Any domain that isn't a recognised staff domain.
            $error = "This email cannot be used to sign in.";
        } elseif ($role === 'driver' && $domain !== 'driver.com') {
            // On the Driver form but this is a Mechanic email.
            $error = "This is a Mechanic email. Please switch to Mechanic sign-in.";
        } elseif ($role === 'mechanic' && $domain !== 'mech.com') {
            // On the Mechanic form but this is a Driver email.
            $error = "This is a Driver email. Please switch to Driver sign-in.";
        } elseif ($domain === 'driver.com') {
            $_SESSION['role'] = 'driver';
            $_SESSION['driver_email'] = $email;
            $_SESSION['driver_name'] = 'Driver';
            $_SESSION['driver_id'] = 0;
            header("Location: driver.php");
            exit;
        } elseif ($domain === 'mech.com') {
            $_SESSION['role'] = 'mechanic';
            $_SESSION['mechanic_email'] = $email;
            $_SESSION['mechanic_name'] = 'Mechanic';
            header("Location: mechanic.php");
            exit;
        }
    } elseif ($role === 'admin') {
        // Demo admin gate. Replace with a real admin credential check.
        $_SESSION['role'] = 'admin';
        $_SESSION['admin_name'] = $_POST['username'] ?: 'admin';
        header("Location: admin.php");
        exit;
    } else {
        $error = "Please choose a role to sign in.";
    }

    // Post/Redirect/Get: keep the error out of the POST so refresh won't repeat it.
    $_SESSION['login_error'] = $error;
    $_SESSION['login_role'] = $role;
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
<link rel="stylesheet" href="style.css?v=5">
</head>
<body class="login-body">

<div class="login-topbar">
  <span class="plate">FF&middot;01</span>
  <span class="id">Smart Fleet &middot; Terminal Access</span>
  <span style="margin-left:auto">
    <a href="auth.php" class="back">Terminal signup</a>
  </span>
</div>

<div class="login-wrap">

<div class="login-brand">
  <div class="eyebrow">Fantastic Four &middot; Fleet Ops</div>
  <h1>Smart Fleet<br>Dispatch Terminal</h1>
  <p>Heavy haulage across Hanoi, HCMC,<br>Da Nang &amp; Hai Phong. Sign in to begin your shift.</p>
</div>

<?php if ($notice): ?>
  <div class="stamp" style="display:block;background:var(--go-wash);color:var(--go);border:1px solid var(--go);padding:8px 12px;margin-bottom:12px;font-family:var(--mono);font-size:11px"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="stamp" style="display:block;background:var(--stop-wash);color:var(--stop);border:1px solid var(--stop);padding:8px 12px;margin-bottom:12px;font-family:var(--mono);font-size:11px"><?= htmlspecialchars($error) ?></div>
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

<div class="role-tabs">
  <label for="role1">Customer</label>
  <label for="role2">Staff</label>
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

  <!-- One toggle button switches between Driver and Mechanic login -->
  <input type="checkbox" id="staffmode" class="tab-input"<?= ($submittedRole === 'mechanic') ? ' checked' : '' ?>>

  <div id="staffbox-driver">
    <label for="staffmode" style="cursor:pointer;display:inline-flex;align-items:center;gap:8px" title="Click to switch to Mechanic">
      <span class="stamp" style="margin-bottom:0">Driver access</span>
      <span style="font-size:16px">&#8646;</span>
    </label>
    <form method="POST" action="login.php" style="margin-top:16px">
      <input type="hidden" name="role" value="driver">
      <div class="form-group"><label>Email</label><input type="text" name="email" placeholder="you@driver.com"></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
      <button type="submit" class="form-btn primary">Sign in as Driver</button>
    </form>
    <div class="hint">
      Use a <b>@driver.com</b> email<br>
      See your assigned unit<br>
      Report safety events
    </div>
  </div>

  <div id="staffbox-mech">
    <label for="staffmode" style="cursor:pointer;display:inline-flex;align-items:center;gap:8px" title="Click to switch to Driver">
      <span class="stamp" style="margin-bottom:0">Mechanic access</span>
      <span style="font-size:16px">&#8646;</span>
    </label>
    <form method="POST" action="login.php" style="margin-top:16px">
      <input type="hidden" name="role" value="mechanic">
      <div class="form-group"><label>Email</label><input type="text" name="email" placeholder="you@mech.com"></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
      <button type="submit" class="form-btn primary">Sign in as Mechanic</button>
    </form>
    <div class="hint">
      Use a <b>@mech.com</b> email<br>
      View workshop roster<br>
      Track maintenance jobs
    </div>
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
