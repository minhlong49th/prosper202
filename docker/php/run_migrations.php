<?php
// Mock server variables for CLI execution
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
$_SERVER['PHP_SELF'] = '/run_migrations.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once $_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php';

if (is_installed()) {
    if (upgrade_needed()) {
        echo "Running database upgrades...\n";
        require_once $_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-upgrade.php';
        if (UPGRADE::upgrade_databases(time())) {
            echo "Database successfully upgraded.\n";
        } else {
            echo "Database upgrade failed.\n";
            exit(1);
        }
    } else {
        echo "Database is up to date.\n";
    }
} else {
    echo "Prosper202 not yet installed. Skipping migrations.\n";
}
