<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-type: application/json');

try {
    // Step 1: Test connect2.php
    echo json_encode(['step' => 'loading connect2.php']) . "\n";
    include_once(__DIR__ . '/202-config/connect2.php');
    echo json_encode(['step' => 'connect2.php loaded', 'db_connected' => isset($db)]) . "\n";

    // Step 2: Test the query that record.php does
    $landing_page_id_public = $_GET['lpip'] ?? '212';
    $mysql_lpip = $db->real_escape_string($landing_page_id_public);
    $tracker_sql = "SELECT landing_page_type FROM 202_landing_pages WHERE landing_page_id_public='" . $mysql_lpip . "'";
    $tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);
    echo json_encode(['step' => 'landing page query', 'result' => $tracker_row]) . "\n";

    if (!$tracker_row) {
        echo json_encode(['error' => 'Landing page not found for lpip=' . $landing_page_id_public]);
        die();
    }

    // Step 3: Include DataEngine
    echo json_encode(['step' => 'loading class-dataengine-slim.php']) . "\n";
    include_once(__DIR__ . '/202-config/class-dataengine-slim.php');
    echo json_encode(['step' => 'class-dataengine-slim.php loaded']) . "\n";

    // Step 4: Test classes needed by record_simple
    echo json_encode([
        'step' => 'checking classes',
        'DeviceDetect' => class_exists('DeviceDetect'),
        'PLATFORMS' => class_exists('PLATFORMS'),
        'LookupRepositoryFactory' => class_exists('Prosper202\\Repository\\LookupRepositoryFactory'),
        'ClickRecordBuilder' => class_exists('Prosper202\\Click\\ClickRecordBuilder'),
        'MysqlClickRepository' => class_exists('Prosper202\\Click\\MysqlClickRepository'),
        'DataEngine' => class_exists('DataEngine'),
        'FILTER' => class_exists('FILTER'),
    ]) . "\n";

    // Step 5: Test the tracker query
    $t202id = $_GET['t202id'] ?? '912';
    if ($t202id) {
        $mysql_t202id = $db->real_escape_string($t202id);
        $tracker_sql2 = "SELECT 2tr.text_ad_id, 2tr.ppc_account_id, 2tr.click_cpc, 2tr.click_cloaking,
                          2cv.ppc_variable_ids, 2cv.parameters
                   FROM 202_trackers AS 2tr
                   LEFT JOIN 202_ppc_accounts AS 2ppc USING (ppc_account_id)
                   LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(ppc_variable_id) AS ppc_variable_ids,
                             GROUP_CONCAT(parameter) AS parameters FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)
                   WHERE 2tr.tracker_id_public='" . $mysql_t202id . "'";
        $tracker_row2 = memcache_mysql_fetch_assoc($db, $tracker_sql2);
        echo json_encode(['step' => 'tracker query', 'result' => $tracker_row2]) . "\n";

        if ($tracker_row2) {
            $tracker_row = array_merge($tracker_row, $tracker_row2);
        }
    }

    // Step 6: Test user query
    $mysql_user_id = $db->real_escape_string((string) ($tracker_row['user_id'] ?? '0'));
    $user_sql = "SELECT user_timezone, user_keyword_searched_or_bidded, user_pref_referer_data, user_pref_dynamic_bid, maxmind_isp
                 FROM 202_users LEFT JOIN 202_users_pref USING (user_id) WHERE 202_users.user_id='" . $mysql_user_id . "'";
    $user_row = memcache_mysql_fetch_assoc($db, $user_sql);
    echo json_encode(['step' => 'user query', 'result' => $user_row]) . "\n";

    // Step 7: Test DeviceDetect
    $detect = new DeviceDetect();
    $ua = $_GET['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    echo json_encode(['step' => 'DeviceDetect', 'ua' => $ua]) . "\n";

    $device_id = PLATFORMS::get_device_info($db, $detect, $ua);
    echo json_encode(['step' => 'PLATFORMS::get_device_info', 'result' => $device_id]) . "\n";

    // Step 8: Test ClickRepository
    $conn = \Prosper202\Repository\LookupRepositoryFactory::connection($db);
    $clickRepo = new \Prosper202\Click\MysqlClickRepository($conn);
    echo json_encode(['step' => 'ClickRepository created successfully']) . "\n";

    echo json_encode(['result' => 'ALL CHECKS PASSED']);

} catch (\Throwable $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}
