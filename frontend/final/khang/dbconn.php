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

/** Render a vehicle status as a coloured pill badge (green/blue/amber/red). */
function status_badge(string $status): string {
    $key = strtolower(trim($status));
    // Map each known status to a semantic tone class.
    $tone = 'neutral';
    if (in_array($key, ['available', 'active'], true))              $tone = 'go';
    elseif (in_array($key, ['on road', 'in transit'], true))        $tone = 'road';
    elseif (in_array($key, ['under maintenance', 'in shop', 'awaiting inspection'], true)) $tone = 'hold';
    elseif (in_array($key, ['out of service', 'retired', 'suspended'], true)) $tone = 'stop';
    return "<span class='badge badge-$tone'>" . htmlspecialchars($status) . "</span>";
}

/* ============================================================
 * UI version toggle.
 *
 * Two builds live side by side: the original in php/ and this Apple
 * copy in php/khang/. FF·01 links to the same page in the OTHER build.
 * No memory — the original php/ is always the default entry point.
 * ============================================================ */

// Which build is THIS file part of? The Apple copy lives in /khang/.
define('IS_KHANG', true);

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
