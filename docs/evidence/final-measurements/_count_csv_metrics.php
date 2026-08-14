<?php
$f = file(__DIR__.'/security_test_evidence.csv');
echo 'csv_lines='.count($f).PHP_EOL;
$m = [];
foreach (array_slice($f, 1) as $l) {
    if (trim($l) === '') {
        continue;
    }
    $c = str_getcsv($l);
    $m[$c[0]] = ($m[$c[0]] ?? 0) + 1;
}
ksort($m);
foreach ($m as $k => $v) {
    echo "$k=$v\n";
}
