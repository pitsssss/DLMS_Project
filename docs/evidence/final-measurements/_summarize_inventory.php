<?php
$raw = file_get_contents(__DIR__.'/_security_inventory_raw.json');
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
$j = json_decode($raw, true);
if (! is_array($j)) {
    fwrite(STDERR, "JSON decode failed: ".json_last_error_msg()."\n");
    exit(1);
}
echo "files={$j['test_file_count']}\n";
echo "methods={$j['test_method_count']}\n";
echo "401={$j['raw_401_scenario_count']}\n";
echo "403={$j['raw_403_scenario_count']}\n";
echo 'throttle_files='.count($j['throttle_disable_files'])."\n";
echo '429_files='.count($j['assert_429_files'])."\n";
$u = 0;
$a = 0;
$noAct = 0;
foreach ($j['scenarios_401'] as $s) {
    if ($s['unauth_heuristic']) {
        $u++;
    }
}
foreach ($j['scenarios_403'] as $s) {
    if (! empty($s['has_actingAs'])) {
        $a++;
    } else {
        $noAct++;
    }
}
echo "401_unauth_heuristic=$u\n";
echo "403_with_actingAs=$a\n";
echo "403_without_actingAs=$noAct\n";

// Export compact CSVs for review
$f = fopen(__DIR__.'/_review_401.csv', 'w');
fputcsv($f, ['file', 'method', 'http', 'endpoint', 'status', 'assert_kind', 'unauth_heuristic']);
foreach ($j['scenarios_401'] as $s) {
    fputcsv($f, [$s['file'], $s['method'], $s['http'], $s['endpoint'], $s['status'], $s['assert_kind'], $s['unauth_heuristic'] ? '1' : '0']);
}
fclose($f);

$f = fopen(__DIR__.'/_review_403.csv', 'w');
fputcsv($f, ['file', 'method', 'http', 'endpoint', 'status', 'assert_kind', 'has_actingAs', 'authz_heuristic']);
foreach ($j['scenarios_403'] as $s) {
    fputcsv($f, [$s['file'], $s['method'], $s['http'], $s['endpoint'], $s['status'], $s['assert_kind'], ! empty($s['has_actingAs']) ? '1' : '0', ! empty($s['authz_heuristic']) ? '1' : '0']);
}
fclose($f);
echo "wrote review csvs\n";
