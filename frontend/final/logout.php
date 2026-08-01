<?php
session_start();
// End the session properly: clear it, expire the cookie, then send the user
// back to the login page. Without this, "Sign out" was just a link to
// login.php and the old session stayed valid (press Back to re-enter).
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header("Location: login.php");
exit;
