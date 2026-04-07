<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Convert ALL PHP warnings/notices to exceptions
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    header('Content-type: application/javascript');
    header('P3P: CP="Prosper202 does not have a P3P policy"');

    // record.php is at tracking202/static/record.php, so __DIR__ there = .../tracking202/static
    // substr(__DIR__, 0, -19) strips "/tracking202/static" to get the webroot
    // We are already at webroot, so just include directly
    include_once(__DIR__ . '/202-config/connect2.php');

    $landing_page_id_public = $_GET['lpip'] ?? '';
    $mysql['landing_page_id_public'] = $db->real_escape_string($landing_page_id_public);
    $tracker_sql = "SELECT  landing_page_type
                    FROM      202_landing_pages
                    WHERE   landing_page_id_public='" . $mysql['landing_page_id_public'] . "'";
    $tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);

    if (!$tracker_row) {
        echo "/* ERROR: landing page not found for lpip=" . htmlspecialchars($landing_page_id_public) . " */";
        die();
    }

    echo "/* landing_page_type = " . $tracker_row['landing_page_type'] . " */\n";

    // Include the actual record script (same as record.php does)
    if ($tracker_row['landing_page_type'] == 0) {
        echo "/* Including record_simple.php... */\n";
        include_once(__DIR__ . '/tracking202/static/record_simple.php');
        die();
    } elseif ($tracker_row['landing_page_type'] == 1) {
        echo "/* Including record_adv.php... */\n";
        include_once(__DIR__ . '/tracking202/static/record_adv.php');
        die();
    }
} catch (\Throwable $e) {
    // Output error as JSON
    header('Content-type: application/json', true, 500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'class' => get_class($e),
        'trace' => array_map(function($t) {
            return [
                'file' => basename($t['file'] ?? ''),
                'line' => $t['line'] ?? 0,
                'function' => ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? ''),
            ];
        }, array_slice($e->getTrace(), 0, 10))
    ], JSON_PRETTY_PRINT);
}
