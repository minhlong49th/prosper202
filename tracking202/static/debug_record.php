<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Convert ALL PHP errors to exceptions
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    // This is EXACTLY what record.php does, line by line
    header('Content-type: application/javascript');
    header('P3P: CP="Prosper202 does not have a P3P policy"');
    include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');

    $landing_page_id_public = $_GET['lpip'] ?? '';
    $mysql['landing_page_id_public'] = $db->real_escape_string($landing_page_id_public);
    $tracker_sql = "SELECT  landing_page_type
                    FROM      202_landing_pages
                    WHERE   landing_page_id_public='".$mysql['landing_page_id_public']."'";
    $tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);

    if (!$tracker_row) {
        echo "/* ERROR: No landing page found for lpip=" . htmlspecialchars($landing_page_id_public) . " */";
        die();
    }

    if ($tracker_row['landing_page_type'] == 0) {
        include_once(substr(__DIR__, 0,-19) .'/tracking202/static/record_simple.php');
        die();
    } elseif ($tracker_row['landing_page_type'] == 1){
        include_once(substr(__DIR__, 0,-19) .'/tracking202/static/record_adv.php');
        die();
    }
} catch (\Throwable $e) {
    header('Content-type: application/json', true, 500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'class' => get_class($e),
        'trace' => array_map(function($t) {
            return [
                'file' => $t['file'] ?? '',
                'line' => $t['line'] ?? 0,
                'function' => ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? ''),
            ];
        }, array_slice($e->getTrace(), 0, 15))
    ], JSON_PRETTY_PRINT);
}
