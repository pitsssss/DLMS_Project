<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$default = config('database.default');
$conn = config("database.connections.{$default}");

echo 'APP_ENV='.app()->environment().PHP_EOL;
echo 'DB_CONNECTION='.$default.PHP_EOL;
echo 'DB_DATABASE='.($conn['database'] ?? '?').PHP_EOL;
echo 'DB_HOST='.($conn['host'] ?? '?').PHP_EOL;
