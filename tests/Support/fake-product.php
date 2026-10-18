<?php

/*
 * Router script for `php -S`: a fake Vigil product that records every POST /api/ingest into FAKE_PRODUCT_DIR
 * and answers with the status in FAKE_PRODUCT_DIR/status (default 202).
 */

$directory = (string) getenv('FAKE_PRODUCT_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('Content-Type: application/json');

if ($path !== '/api/ingest' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo '{"message":"Not Found"}';

    return true;
}

$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$raw = (string) file_get_contents('php://input');
$json = ($headers['content-encoding'] ?? '') === 'gzip' ? gzdecode($raw) : $raw;
$records = is_string($json) ? (json_decode($json, true)['records'] ?? null) : null;
$status = is_file($directory.'/status') ? (int) trim((string) file_get_contents($directory.'/status')) : 202;

file_put_contents($directory.'/request-'.hrtime(true).'.json', json_encode([
    'status' => $status,
    'headers' => $headers,
    'gzip' => str_starts_with($raw, "\x1f\x8b"),
    'json' => $json,
    'records' => $records,
]));

http_response_code($status);

echo is_file($directory.'/message') ? json_encode(['message' => trim((string) file_get_contents($directory.'/message'))]) : ($status === 202 ? json_encode(['accepted' => count($records ?? [])]) : '{"message":"fake product answered '.$status.'"}');

return true;
