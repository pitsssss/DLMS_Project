<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$app = $root.DIRECTORY_SEPARATOR.'app';

function countOccurrences(string $dir, string $needle): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $byFile = [];
    $total = 0;
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $contents = file_get_contents($path);
        $n = substr_count($contents, $needle);
        if ($n > 0) {
            $rel = str_replace('\\', '/', substr($path, strlen(dirname($dir)) + 1));
            $byFile[$rel] = $n;
            $total += $n;
        }
    }
    ksort($byFile);

    return [$total, $byFile];
}

[$locks, $lockFiles] = countOccurrences($app, 'lockForUpdate(');
[$txns, $txnFiles] = countOccurrences($app, 'DB::transaction(');
[$after, $afterFiles] = countOccurrences($app, 'DB::afterCommit(');

echo json_encode([
    'lockForUpdate' => ['total' => $locks, 'files' => $lockFiles],
    'DB::transaction' => ['total' => $txns, 'files' => $txnFiles],
    'DB::afterCommit' => ['total' => $after, 'files' => $afterFiles],
], JSON_PRETTY_PRINT);
