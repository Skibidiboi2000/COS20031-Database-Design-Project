<?php
/*
 * auth.php — Sign-up page (account registration only).
 *
 * Validates the form, checks the email isn't already taken, hashes the
 * password with password_hash(), and inserts a new row into Users. On success
 * it redirects to login.php with a one-time success message. Requires a Users
 * table with columns: email, password_hash, created_at.
 */
session_start();
include 'dbconn.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email   = trim($_POST['email'] ?? '');
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    try {
        // Validate the input before touching the database.
        if ($email === '' || $pass === '') {
            $error = "Email and password are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email.";
        } elseif ($pass !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            // Reject duplicates so each email is used only once.
            $chk = $conn->prepare("SELECT user_id FROM Users WHERE email = ?");
            $chk->bind_param("s", $email);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $error = "That email is already registered.";
            } else {
                // Store only the hash, never the raw password.
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $now = date('Y-m-d H:i:s');
                $ins = $conn->prepare("INSERT INTO Users (email, password_hash, created_at) VALUES (?, ?, ?)");
                $ins->bind_param("sss", $email, $hash, $now);
                $ins->execute();
                $ins->close();
                // Account created — send them to the main login to sign in.
                $_SESSION['signup_ok'] = "Your account has been created. Please sign in.";
                header("Location: login.php");
                exit;
            }
            $chk->close();
        }
    } catch (mysqli_sql_exception $e) {
        $error = "Database not available. Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Smart Fleet Sign Up</title>
<link rel="stylesheet" href="style.css?v=khang1">
</head>
<body class="login-body">

<div class="login-topbar">
  <?= version_switch_plate() ?>
  <span class="id">Smart Fleet &middot; Sign Up</span>
  <span style="margin-left:auto">
    <a href="login.php" class="back">Terminal login</a>
  </span>
</div>

<div class="login-wrap">

<div class="login-brand reveal">
  <div class="eyebrow">Fantastic Four &middot; Fleet Ops</div>
  <h1>Create<br>Account</h1>
  <p>Register a new account, then log in from the terminal.</p>
</div>

<?php if ($error): ?>
  <div class="flash flash-err"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="login-box" style="display:block;animation:boxIn .5s cubic-bezier(.22,.61,.36,1)">
  <span class="stamp">Sign up</span>
  <form method="POST" action="auth.php">
    <div class="form-group"><label>Email</label><input type="text" name="email" placeholder="you@company.com"></div>
    <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
    <div class="form-group"><label>Confirm password</label><input type="password" name="confirm" placeholder="&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;"></div>
    <button type="submit" class="form-btn primary">Create account</button>
  </form>
  <div class="hint">
    Already have an account?<br>
    <a href="login.php" style="color:var(--accent-ink)">Log in from the terminal</a>
  </div>
</div>

</div>
<script src="motion.js"></script>
</body>
</html>
