<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Simulate a real web request context
$_GET = [
    'lpip' => '212',
    't202id' => '912',
    't202kw' => 'test-keyword',
    't202ref' => '',
    'OVRAW' => '',
    'OVKEY' => '',
    'c1' => '123',
    'c2' => '456',
    'c3' => 'e',
    'c4' => '',
    'gclid' => 'test123',
    'referer' => '',
    'resolution' => '1920x1080',
    'language' => 'en',
    'target_passthrough' => '',
    'keyword' => '',
    'utm_source' => '',
    'utm_medium' => '',
    'utm_term' => '',
    'utm_content' => '',
    'utm_campaign' => '',
    't202b' => '',
];

$_SERVER['SERVER_NAME'] = 'track.productinsight.store';
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 Test';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/tracking202/static/record.php';

try {
    include '/var/www/html/tracking202/static/record.php';
    echo "\n\nSUCCESS: No errors!\n";
} catch (\Throwable $e) {
    echo "\n\nERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
