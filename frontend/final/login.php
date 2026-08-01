<?php
/*
 * login.php — Main sign-in terminal.
 *
 * Every role signs in here against the single Accounts table (the source of
 * truth): Customer, Staff (Driver or Mechanic), and Admin. The account's own
 * `role` column decides where they land; the tab they used is only checked so
 * a driver can't sign in through the Admin tab and vice-versa.
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
    } elseif (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = "Your session expired. Please refresh and try again.";
    } else {
        $acct = find_account_by_email($conn, $email);

        if (!$acct) {
            $error = "Incorrect email or password.";
        } elseif (isset($acct['is_active']) && (int)$acct['is_active'] === 0) {
            $error = "This account has been deactivated.";
        } elseif (($acct['password_hash'] ?? '') === '' || $acct['password_hash'] === null) {
            // Account exists but has no password set yet (e.g. not finished syncing).
            $error = "Incorrect email or password.";
        } elseif (!password_verify($password, $acct['password_hash'])) {
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
                // Success. Regenerate the session id so a session id fixed by
                // an attacker before login can never carry the new identity.
                session_regenerate_id(true);
                // Store a common identity plus role-specific keys the
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
<link rel="stylesheet" href="style.css">
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
    <?= csrf_field() ?>
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
    <?= csrf_field() ?>
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
    <?= csrf_field() ?>
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
