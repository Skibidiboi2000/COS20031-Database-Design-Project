<?php
/*
 * account.php — Account settings, shared by every signed-in role.
 *
 * Kept out of the main dashboards (Apple separates settings from primary
 * navigation). Everyone can change their own password here; admins also get
 * a panel to reset another account's password. The account table + id column
 * are chosen from the session role.
 */
session_start();
include 'dbconn.php';

$role = $_SESSION['role'] ?? '';
$allowed = ['admin', 'driver', 'mechanic', 'customer', 'user'];
if (!in_array($role, $allowed, true)) {
    header("Location: login.php");
    exit;
}

/* Everyone now lives in the Accounts table, keyed by account_id from the
 * session. The email is kept for display. */
$acctId = (int)($_SESSION['account_id'] ?? 0);
$displayEmail = $_SESSION['email'] ?? '';
$notice = "";

/* Handle the two POST actions. Both are guarded by a CSRF token check first,
 * the same way login.php / admin.php protect their writes — changing or
 * resetting a password is sensitive, so it must not be forgeable. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $notice = "Your session expired. Please refresh and try again.";
    } elseif ($action === 'change_password') {
        if ($acctId > 0) {
            [$ok, $notice] = change_password(
                $conn, 'Accounts', 'account_id', $acctId,
                $_POST['current_password'] ?? '', $_POST['new_password'] ?? '', $_POST['confirm_password'] ?? ''
            );
        } else {
            $notice = "This account can't change its password (not found in the database).";
        }
    } elseif ($action === 'admin_reset_password' && $role === 'admin') {
        [$ok, $notice] = admin_reset_password(
            $conn, trim($_POST['reset_email'] ?? ''),
            $_POST['new_password'] ?? '', $_POST['confirm_password'] ?? ''
        );
    }
}

$isErr = $notice !== '' && (
       stripos($notice, 'could not') !== false
    || stripos($notice, 'not available') !== false
    || stripos($notice, 'incorrect') !== false
    || stripos($notice, 'do not match') !== false
    || stripos($notice, 'required') !== false
    || stripos($notice, 'at least') !== false
    || stripos($notice, "can't") !== false
    || (stripos($notice, 'no ') !== false && stripos($notice, 'found') !== false)
    || stripos($notice, 'valid account') !== false
    || stripos($notice, 'try again') !== false
);

$home = [
    'admin' => 'admin.php', 'driver' => 'driver.php', 'mechanic' => 'mechanic.php',
    'customer' => 'customer.php', 'user' => 'customer.php',
][$role] ?? 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Account</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="header">
  <div class="mark">
    <?= version_switch_plate() ?>
    <div class="titlewrap">
      <h1>Smart Fleet</h1>
      <span class="sub">Account &middot; <?= htmlspecialchars($displayEmail ?: ucfirst($role)) ?></span>
    </div>
  </div>
  <div class="meta"><span class="live">Signed in</span></div>
  <a href="dashboard.php" class="back">Dashboard</a>
  <a href="<?= htmlspecialchars($home) ?>" class="back">Back</a>
</div>

<div class="wrap">

  <div class="dash-hero reveal">
    <div class="eyebrow">Settings</div>
    <h2 class="dash-title">Account</h2>
    <p class="dash-sub">Manage your sign-in and password.</p>
  </div>

  <?php if ($notice): ?>
    <div class="flash <?= $isErr ? 'flash-err' : 'flash-ok' ?> reveal"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>

  <div class="reveal" style="transition-delay:.06s"><?= password_form('account.php') ?></div>

  <?php if ($role === 'admin'): ?>
    <div class="reveal" style="transition-delay:.12s"><?= admin_reset_form('account.php') ?></div>
  <?php endif; ?>

</div>
<script src="motion.js"></script>
</body>
</html>
