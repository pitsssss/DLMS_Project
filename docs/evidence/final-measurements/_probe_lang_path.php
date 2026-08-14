<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'lang_path='.lang_path().PHP_EOL;
echo 'base_path='.base_path().PHP_EOL;
echo 'has messages.ping ar='.(Illuminate\Support\Facades\Lang::has('messages.ping.running', 'ar') ? 'Y' : 'N').PHP_EOL;
echo 'has messages.ping en='.(Illuminate\Support\Facades\Lang::has('messages.ping.running', 'en') ? 'Y' : 'N').PHP_EOL;
echo 'ar='.Illuminate\Support\Facades\Lang::get('messages.ping.running', [], 'ar').PHP_EOL;
echo 'en='.Illuminate\Support\Facades\Lang::get('messages.ping.running', [], 'en').PHP_EOL;

foreach (['ar', 'en'] as $locale) {
    $dirs = [
        lang_path($locale),
        base_path('resources/lang/'.$locale),
    ];
    foreach ($dirs as $dir) {
        echo ($locale.' dir '.$dir.' exists='.(is_dir($dir) ? 'Y' : 'N')).PHP_EOL;
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.php') ?: [] as $f) {
                echo '  file='.basename($f).PHP_EOL;
            }
        }
    }
}
