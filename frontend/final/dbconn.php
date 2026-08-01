<?php
/*
 * dbconn.php — database connection + shared helpers.
 *
 * Every page starts with `include 'dbconn.php';`. It opens one MySQLi
 * connection ($conn) used across the app, then defines the schema-detection
 * helpers, the auth/password functions, and the small HTML render helpers
 * (icons, badges, empty states) so every page produces consistent markup.
 */

// Open the shared connection.
//
// The DB (MariaDB) lives on the same machine as the PHP server, so the fast,
// reliable path is the local loopback 127.0.0.1. We try that first. If it
// isn't reachable (e.g. running the code from a different host that can only
// reach the box over the Tailscale/tunnel address), we fall back to the
// remote IP. This is why "Review queue" worked on the hosted build (local
// connection) but not on localhost before — localhost was being forced out
// over the remote IP instead of using the loopback.
mysqli_report(MYSQLI_REPORT_OFF); // handle connect failure ourselves, don't throw

$DB_USER = 'admin';
$DB_PASS = '1234';
$DB_NAME = 'SmartFleetProject';
$DB_HOSTS = ['127.0.0.1', '100.98.213.61']; // local loopback first, remote fallback

$conn = null;
foreach ($DB_HOSTS as $host) {
    $try = @new mysqli($host, $DB_USER, $DB_PASS, $DB_NAME);
    if (!$try->connect_error) { $conn = $try; break; }
}

if (!$conn) {
    die("Connection failed: could not reach the database on any known host.");
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // restore exceptions for queries

/* ============================================================
 * Schema-detection helpers.
 *
 * The app is written so it keeps working even if the database schema
 * changes slightly (a column renamed, an optional table missing). Rather
 * than hard-coding table/column names everywhere, each page asks the
 * database what it actually has via these helpers, then builds its query
 * to match. This is what lets the same PHP run against different versions
 * of the SmartFleet database without edits.
 * ============================================================ */

/**
 * Return true if $table exists in the current database.
 * Used to decide whether a whole feature/query can run at all.
 */
function table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

/**
 * Return the first column name from $candidates that actually exists in
 * $table, or null if none do. Handles databases that spell the same field
 * differently (e.g. "location" vs "location_details", "times_tamp" vs
 * "event_timestamp"). Returns null if the table itself is missing.
 */
function find_column(mysqli $conn, string $table, array $candidates): ?string {
    if (!table_exists($conn, $table)) {
        return null;
    }
    $existing = [];
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($result && $col = $result->fetch_assoc()) {
        $existing[] = $col['Field'];
    }
    foreach ($candidates as $name) {
        if (in_array($name, $existing, true)) {
            return $name;
        }
    }
    return null;
}

/**
 * Given a map of [logicalName => [candidate column names...]], return a map of
 * [logicalName => realColumnName] containing only the ones that exist.
 * Lets a page build a query from whichever optional columns are present.
 */
function find_columns(mysqli $conn, string $table, array $map): array {
    $found = [];
    foreach ($map as $logical => $candidates) {
        $real = find_column($conn, $table, $candidates);
        if ($real !== null) {
            $found[$logical] = $real;
        }
    }
    return $found;
}

/* ============================================================
 * CSRF protection. Every state-changing form carries a hidden
 * token; every POST handler verifies it with hash_equals before
 * doing anything.
 * ============================================================ */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function csrf_verify(?string $token): bool {
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Null-safe HTML escaper. Database columns can be NULL (LEFT JOINs, optional
 * fields), and on PHP 8.1+ passing null straight to htmlspecialchars() raises
 * a deprecation notice. h() coerces null/other scalars to a string first, so
 * page code can escape any value without a per-field "?? ''" guard.
 */
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* ============================================================
 * Accounts — the single source of truth for sign-in.
 *
 * Every login (admin, driver, mechanic, customer) lives in one Accounts
 * table: account_id, first_name, last_name, is_active, email, role,
 * password_hash. Look-ups are by email; the row's role decides where the
 * user goes. Column names are auto-detected so minor schema differences
 * still work.
 * ============================================================ */
function find_account_by_email(mysqli $conn, string $email): ?array {
    if (!table_exists($conn, 'Accounts')) return null;
    $emailCol = find_column($conn, 'Accounts', ['email', 'email_address', 'username']);
    if ($emailCol === null) return null;
    try {
        $stmt = $conn->prepare("SELECT * FROM `Accounts` WHERE `$emailCol` = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (mysqli_sql_exception $e) {
        return null;
    }
}

/* ============================================================
 * UI helpers (shared markup) live here so every page renders
 * empty states and icons the same way.
 * ============================================================ */

/** A small set of inline SVG icons (stroke-based, currentColor). */
function ui_icon(string $name): string {
    $paths = [
        'truck'    => '<path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="1.6"/><circle cx="17.5" cy="17" r="1.6"/>',
        'user'     => '<circle cx="12" cy="8" r="3.2"/><path d="M5 20c0-3.6 3.1-5.5 7-5.5s7 1.9 7 5.5"/>',
        'building' => '<rect x="5" y="4" width="14" height="16" rx="1"/><path d="M9 8h2M13 8h2M9 12h2M13 12h2M9 16h2M13 16h2"/>',
        'wrench'   => '<path d="M15 4a4 4 0 0 0-3.5 6L4 17.5 6.5 20l7.5-7.5A4 4 0 1 0 15 4z"/>',
        'clipboard'=> '<rect x="6" y="4" width="12" height="17" rx="1.5"/><path d="M9 4h6v3H9zM9 11h6M9 15h4"/>',
        'inbox'    => '<path d="M4 13l2.5-8h11L20 13v6H4zM4 13h5l1.5 2.5h3L15 13h5"/>',
    ];
    $inner = $paths[$name] ?? $paths['inbox'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}

/** Render a full-width empty-state row for a table body. */
function empty_row(int $colspan, string $title, string $hint = '', string $icon = 'inbox'): string {
    $html  = "<tr><td colspan='$colspan' class='empty'>";
    $html .= "<div class='empty-icon'>" . ui_icon($icon) . "</div>";
    $html .= "<div class='empty-title'>" . htmlspecialchars($title) . "</div>";
    if ($hint !== '') {
        $html .= "<div class='empty-hint'>" . htmlspecialchars($hint) . "</div>";
    }
    $html .= "</td></tr>";
    return $html;
}

/**
 * Render a status as a coloured pill badge. Covers both vehicle statuses
 * (Available / On Road / Under Maintenance / Out of Service …) and staff
 * employment statuses (Active / On Leave / Suspended / Inactive), so driver
 * and mechanic rosters get the same colour treatment as the fleet table:
 *   go   (green)  = healthy / working
 *   road (blue)   = in transit
 *   hold (amber)  = attention / temporarily out (maintenance, on leave)
 *   stop (red)    = out of service / suspended / retired
 *   neutral(grey) = inactive / unknown
 */
function status_badge(string $status): string {
    $key = strtolower(trim($status));
    $tone = 'neutral';
    if (in_array($key, ['available', 'active'], true))                          $tone = 'go';
    elseif (in_array($key, ['on road', 'in transit'], true))                    $tone = 'road';
    elseif (in_array($key, ['under maintenance', 'in shop', 'awaiting inspection', 'on leave'], true)) $tone = 'hold';
    elseif (in_array($key, ['out of service', 'retired', 'suspended'], true))   $tone = 'stop';
    // 'inactive' and anything unrecognised stay neutral grey.
    return "<span class='badge badge-$tone'>" . htmlspecialchars($status) . "</span>";
}

/**
 * Render an event severity as a coloured pill: Low green, Medium blue,
 * High amber, Critical red — rising urgency. Used in the incident logs.
 */
function severity_badge(string $sev): string {
    $key = strtolower(trim($sev));
    $tone = 'neutral';
    if ($key === 'low')          $tone = 'go';
    elseif ($key === 'medium')   $tone = 'road';
    elseif ($key === 'high')     $tone = 'hold';
    elseif ($key === 'critical') $tone = 'stop';
    return "<span class='badge badge-$tone'>" . htmlspecialchars($sev) . "</span>";
}

/* ============================================================
 * Header plate. This app is standalone — the FF·01 plate is just a
 * static badge, not a link to another build.
 * ============================================================ */

/** Render the FF·01 plate as a plain static badge. */
function version_switch_plate(string $label = 'FF·01', string $class = 'plate'): string {
    return "<span class=\"" . htmlspecialchars($class) . "\">" . htmlspecialchars($label) . "</span>";
}

/* ============================================================
 * Change password (requires the current password).
 *
 * Used by the account settings page. Verifies the user's current password
 * against the stored bcrypt hash, then writes a new hash. Generic over the
 * account table + id column so it can serve any table with a password_hash
 * column — in this app that is always Accounts, keyed by account_id.
 * Returns [ok(bool), message(string)] so the caller can show the result.
 * ============================================================ */
function change_password(
    mysqli $conn, string $table, string $idCol, int $idVal,
    string $current, string $new, string $confirm
): array {
    if ($current === '' || $new === '' || $confirm === '') {
        return [false, 'All password fields are required.'];
    }
    if (strlen($new) < 8) {
        return [false, 'New password must be at least 8 characters.'];
    }
    if ($new !== $confirm) {
        return [false, 'New passwords do not match.'];
    }
    if (!table_exists($conn, $table) || !find_column($conn, $table, ['password_hash'])) {
        return [false, 'Password changes are not available for this account.'];
    }
    try {
        // Fetch the current hash and verify the old password first.
        $sel = $conn->prepare("SELECT password_hash FROM `$table` WHERE `$idCol` = ? LIMIT 1");
        $sel->bind_param('i', $idVal);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$row || !password_verify($current, $row['password_hash'] ?? '')) {
            return [false, 'Current password is incorrect.'];
        }
        // Store the new hash.
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE `$table` SET password_hash = ? WHERE `$idCol` = ?");
        $upd->bind_param('si', $hash, $idVal);
        $ok = $upd->execute();
        $upd->close();
        return $ok
            ? [true, 'Your password has been changed.']
            : [false, 'Could not change the password. Please try again.'];
    } catch (mysqli_sql_exception $e) {
        return [false, 'Database not available. Please try again later.'];
    }
}

/** Render the shared "Change password" form (used inside a dashboard tab). */
function password_form(string $action_page): string {
    $p = htmlspecialchars($action_page);
    return <<<HTML
<div class="docket">
  <div class="docket-head"><h2>Change password</h2><span class="no">Requires your current password</span></div>
  <form method="POST" action="$p">
    <input type="hidden" name="action" value="change_password">
    <?= csrf_field() ?>
    <div class="form-row">
      <div class="form-group"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password"></div>
      <div class="form-group"><label>New password</label><input type="password" name="new_password" autocomplete="new-password"></div>
      <div class="form-group"><label>Confirm new password</label><input type="password" name="confirm_password" autocomplete="new-password"></div>
    </div>
    <div class="form-row"><button type="submit" class="form-btn primary">Update password</button></div>
  </form>
</div>
HTML;
}

/* ============================================================
 * Admin reset: set another account's password without the old one.
 * The account is found by email in the Accounts table. Returns [ok, message].
 * ============================================================ */
function admin_reset_password(mysqli $conn, string $email, string $new, string $confirm): array {
    if ($email === '' || $new === '' || $confirm === '') {
        return [false, 'Email and both password fields are required.'];
    }
    if (strlen($new) < 8) {
        return [false, 'New password must be at least 8 characters.'];
    }
    if ($new !== $confirm) {
        return [false, 'New passwords do not match.'];
    }
    if (!table_exists($conn, 'Accounts') || !find_column($conn, 'Accounts', ['password_hash'])) {
        return [false, "Password reset is not available (no Accounts table)."];
    }
    $emailCol = find_column($conn, 'Accounts', ['email', 'email_address', 'username']);
    if ($emailCol === null) {
        return [false, "Cannot look up accounts (no email column)."];
    }
    try {
        // Make sure the account exists first, so we can report "not found".
        $sel = $conn->prepare("SELECT 1 FROM `Accounts` WHERE `$emailCol` = ? LIMIT 1");
        $sel->bind_param('s', $email);
        $sel->execute();
        $found = (bool)$sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$found) {
            return [false, "No account found for that email."];
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE `Accounts` SET password_hash = ? WHERE `$emailCol` = ?");
        $upd->bind_param('ss', $hash, $email);
        $ok = $upd->execute();
        $upd->close();
        return $ok
            ? [true, "Password reset for $email."]
            : [false, 'Could not reset the password. Please try again.'];
    } catch (mysqli_sql_exception $e) {
        return [false, 'Database not available. Please try again later.'];
    }
}

/** Render the admin-only "Reset another account's password" form. */
function admin_reset_form(string $action_page): string {
    $p = htmlspecialchars($action_page);
    return <<<HTML
<div class="docket">
  <div class="docket-head"><h2>Reset a user's password</h2><span class="no">Admin only &middot; no current password needed</span></div>
  <form method="POST" action="$p">
    <input type="hidden" name="action" value="admin_reset_password">
    <?= csrf_field() ?>
    <div class="form-row">
      <div class="form-group"><label>Account email</label><input type="text" name="reset_email" placeholder="person@company.com"></div>
      <div class="form-group"><label>New password</label><input type="password" name="new_password" autocomplete="new-password"></div>
      <div class="form-group"><label>Confirm new password</label><input type="password" name="confirm_password" autocomplete="new-password"></div>
    </div>
    <div class="form-row"><button type="submit" class="form-btn primary">Reset password</button></div>
  </form>
</div>
HTML;
}
?>
