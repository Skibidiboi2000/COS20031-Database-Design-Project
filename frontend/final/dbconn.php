<?php
#$host = "127.0.0.1";
#$user = "admin";
#$pass = "1234";
#$dbname = "SmartFleetProject";

$conn = new mysqli('100.98.213.61', 'admin', '1234', 'SmartFleetProject');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ============================================================
 * Schema-detection helpers.
 *
 * The app must run on the current database (7 tables) AND on the
 * full one (18 tables) without any code changes. These helpers let
 * each page ask the database what it actually has, then adapt the
 * query instead of assuming a fixed schema.
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
 * UI version toggle.
 *
 * Two builds live side by side: this original in php/ and the Apple
 * copy in php/khang/. FF·01 links to the same page in the OTHER build.
 * No memory — the original php/ is always the default entry point.
 * ============================================================ */

// Which build is THIS file part of? This is the original (not /khang/).
define('IS_KHANG', false);

/** The page name currently running, e.g. "admin.php". */
function current_page(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return basename(parse_url($script, PHP_URL_PATH) ?: 'login.php');
}

/** Build a link to $page in the OTHER build (relative to this one). */
function other_build_link(string $page): string {
    return IS_KHANG ? '../' . $page : 'khang/' . $page;
}

/**
 * Render the FF·01 plate as a link that switches to the OTHER build,
 * keeping the same page name. $class keeps each header's plate styling.
 */
function version_switch_plate(string $label = 'FF&middot;01', string $class = 'plate'): string {
    $href = htmlspecialchars(other_build_link(current_page()));
    $tip  = IS_KHANG ? 'Switch to Classic UI' : 'Switch to Apple UI';
    return "<a href=\"$href\" class=\"$class plate-switch\" title=\"$tip\">$label</a>";
}
?>
