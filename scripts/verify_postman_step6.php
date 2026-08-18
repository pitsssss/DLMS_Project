<?php

$path = dirname(__DIR__).'/postman/SYRTAK_Flutter_API.postman_collection.json';
$data = json_decode(file_get_contents($path), true);

function walk(array $items, string $prefix = ''): void
{
    foreach ($items as $item) {
        $name = $item['name'] ?? '?';
        if (isset($item['item'])) {
            echo $prefix.$name.PHP_EOL;
            if (str_starts_with($name, '09')) {
                foreach ($item['item'] as $child) {
                    echo $prefix.'  - '.($child['name'] ?? '?').PHP_EOL;
                }
            }
        }
    }
}

walk($data['item']);

$blob = file_get_contents($path);
$env = file_get_contents(dirname(__DIR__).'/postman/SYRTAK_Local.postman_environment.json');
$patterns = [
    '/sk_live_[A-Za-z0-9]+/',
    '/sk_test_[A-Za-z0-9]{20,}/',
    '/whsec_[A-Za-z0-9]+/',
    '/Bearer\s+[A-Za-z0-9_\-\.]{40,}/',
];
foreach (['collection' => $blob, 'env' => $env] as $label => $text) {
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) {
            echo "SECRET_HIT {$label} {$p}".PHP_EOL;
            exit(1);
        }
    }
}
echo "secrets_scan=OK".PHP_EOL;
echo "vars=".implode(',', array_column($data['variable'] ?? [], 'key')).PHP_EOL;
