<?php

/**
 * READ-ONLY security test evidence inventory helper.
 * Does not modify application code. Outputs JSON to stdout.
 */

declare(strict_types=1);

$testsRoot = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'tests';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot));
$testFiles = [];
foreach ($files as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $testFiles[] = $file->getPathname();
    }
}
sort($testFiles);

function extractMethods(string $source): array
{
    $methods = [];
    // Match public function test* or #[Test] annotated public functions
    $pattern = '/(?:#\[(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\]\s*)?public\s+function\s+(test\w*|[\w]+)\s*\([^)]*\)[^{]*\{/';
    if (! preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    foreach ($matches[0] as $i => $m) {
        $name = $matches[1][$i][0];
        // Skip non-test helpers unless named test*
        if (! str_starts_with($name, 'test') && ! str_contains(substr($source, max(0, $m[1] - 120), 120), '#[Test]') && ! str_contains(substr($source, max(0, $m[1] - 120), 120), '#[\\PHPUnit\\Framework\\Attributes\\Test]')) {
            // Allow only test* methods for inventory
            if (! str_starts_with($name, 'test')) {
                continue;
            }
        }
        $braceStart = strpos($source, '{', $m[1]);
        if ($braceStart === false) {
            continue;
        }
        $depth = 0;
        $len = strlen($source);
        $end = $braceStart;
        for ($p = $braceStart; $p < $len; $p++) {
            $ch = $source[$p];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $p;
                    break;
                }
            }
        }
        $body = substr($source, $braceStart, $end - $braceStart + 1);
        $methods[] = [
            'name' => $name,
            'body' => $body,
            'offset' => $m[1],
        ];
    }

    return $methods;
}

function findHttpCalls(string $body): array
{
    $calls = [];
    $re = '/(?:\$this->|)\s*(getJson|postJson|putJson|patchJson|deleteJson|json|get|post|put|patch|delete)\s*\(\s*([\'\"])([^\'\"]+)\2/';
    if (preg_match_all($re, $body, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $full) {
            $calls[] = [
                'method' => strtoupper(preg_replace('/Json$/', '', $m[1][$i][0])),
                'path' => $m[3][$i][0],
                'offset' => $full[1],
            ];
        }
    }

    return $calls;
}

function nearestCall(array $calls, int $assertOffset): ?array
{
    $best = null;
    foreach ($calls as $c) {
        if ($c['offset'] <= $assertOffset) {
            if ($best === null || $c['offset'] > $best['offset']) {
                $best = $c;
            }
        }
    }

    return $best;
}

function findAsserts(string $body, array $statusCodes): array
{
    $out = [];
    $calls = findHttpCalls($body);

    foreach ($statusCodes as $code) {
        $re = '/->assertStatus\(\s*'.$code.'\s*\)/';
        if (preg_match_all($re, $body, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $call = nearestCall($calls, $hit[1]);
                $out[] = [
                    'kind' => 'assertStatus',
                    'status' => $code,
                    'offset' => $hit[1],
                    'endpoint' => $call['path'] ?? null,
                    'http' => $call['method'] ?? null,
                ];
            }
        }
    }

    // Laravel helpers
    $helpers = [
        401 => ['assertUnauthorized'],
        403 => ['assertForbidden'],
    ];
    foreach ($helpers as $code => $names) {
        if (! in_array($code, $statusCodes, true)) {
            continue;
        }
        foreach ($names as $name) {
            $re = '/->'.$name.'\s*\(/';
            if (preg_match_all($re, $body, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    $call = nearestCall($calls, $hit[1]);
                    $out[] = [
                        'kind' => $name,
                        'status' => $code,
                        'offset' => $hit[1],
                        'endpoint' => $call['path'] ?? null,
                        'http' => $call['method'] ?? null,
                    ];
                }
            }
        }
    }

    return $out;
}

function bodySuggestsUnauthenticated(string $body, string $methodName): bool
{
    $hay = strtolower($methodName."\n".$body);
    $positive = [
        'unauthenticated',
        'withouttoken',
        'without_token',
        'no token',
        'guest',
        'not authenticated',
        'sanctum::actingas', // may still be present for other parts
    ];
    // Strong signals for unauth tests
    if (preg_match('/unauth|without.?acting|guest|no.?token|missing.?token|not.?logged|without.?auth/i', $hay)) {
        return true;
    }
    // actingAs absent and asserts 401 strongly suggests unauth if no actingAs before assert
    $hasActing = (bool) preg_match('/actingAs\s*\(/i', $body);
    $has401 = (bool) preg_match('/assertStatus\(\s*401\s*\)|assertUnauthorized\s*\(/', $body);
    if ($has401 && ! $hasActing) {
        return true;
    }
    // Pattern: call without actingAs then 401 — already covered
    // Pattern: actingAs then logout/delete token then 401
    if ($has401 && preg_match('/(logout|currentAccessToken|delete\(\)|tokens\(\)->delete|withoutToken|withHeader\(\s*[\'\"]Authorization[\'\"]\s*,\s*[\'\"]\s*[\'\"])/i', $body)) {
        return true;
    }

    return $has401 && preg_match('/unauthenticated|requires.?auth|must.?be.?auth/i', $hay);
}

function bodySuggestsAuthz403(string $body, string $methodName): bool
{
    $hay = strtolower($methodName."\n".$body);
    if (preg_match('/assertStatus\(\s*403\s*\)|assertForbidden\s*\(/', $body) === 0) {
        return false;
    }
    // Prefer authenticated actor present
    if (preg_match('/actingAs\s*\(/i', $body) || preg_match('/withToken|Bearer|createToken/i', $body)) {
        return true;
    }
    if (preg_match('/forbidden|permission|unauthorized.?role|without.?permission|cannot_|denied|not.?allowed|employee.?cannot|citizen.?cannot|super.?admin|rbac|access.?denied/i', $hay)) {
        return true;
    }

    return true; // 403 asserts in feature tests almost always authz; flagged for review if no actingAs
}

$scenarios401 = [];
$scenarios403 = [];
$allMethods = [];
$throttleDisableFiles = [];
$assert429 = [];

foreach ($testFiles as $path) {
    $rel = str_replace(dirname($testsRoot).DIRECTORY_SEPARATOR, '', $path);
    $rel = str_replace('\\', '/', $rel);
    $src = file_get_contents($path);
    if ($src === false) {
        continue;
    }

    if (str_contains($src, 'ThrottleRequests')) {
        if (preg_match('/withoutMiddleware\s*\(\s*\[\s*ThrottleRequests::class\s*\]\s*\)/', $src)
            || preg_match('/withoutMiddleware\s*\(\s*ThrottleRequests::class\s*\)/', $src)) {
            $throttleDisableFiles[] = $rel;
        }
    }
    if (preg_match('/assertStatus\(\s*429\s*\)|assertTooManyRequests\s*\(/', $src)) {
        $assert429[] = $rel;
    }

    foreach (extractMethods($src) as $method) {
        $allMethods[] = ['file' => $rel, 'method' => $method['name']];
        $a401 = findAsserts($method['body'], [401]);
        foreach ($a401 as $a) {
            $scenarios401[] = [
                'file' => $rel,
                'method' => $method['name'],
                'endpoint' => $a['endpoint'],
                'http' => $a['http'],
                'status' => 401,
                'assert_kind' => $a['kind'],
                'unauth_heuristic' => bodySuggestsUnauthenticated($method['body'], $method['name']),
            ];
        }
        $a403 = findAsserts($method['body'], [403]);
        foreach ($a403 as $a) {
            $scenarios403[] = [
                'file' => $rel,
                'method' => $method['name'],
                'endpoint' => $a['endpoint'],
                'http' => $a['http'],
                'status' => 403,
                'assert_kind' => $a['kind'],
                'authz_heuristic' => bodySuggestsAuthz403($method['body'], $method['name']),
                'has_actingAs' => (bool) preg_match('/actingAs\s*\(/i', $method['body']),
            ];
        }
    }
}

// Deduplicate identical file+method+endpoint+status+http within metric
function dedupeScenarios(array $rows): array
{
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $key = implode('|', [
            $r['file'],
            $r['method'],
            $r['http'] ?? '',
            $r['endpoint'] ?? '',
            (string) $r['status'],
            $r['assert_kind'] ?? '',
        ]);
        // If same endpoint asserted twice with same kind, keep one
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $r;
    }

    return $out;
}

$scenarios401 = dedupeScenarios($scenarios401);
$scenarios403 = dedupeScenarios($scenarios403);

$payload = [
    'test_file_count' => count($testFiles),
    'test_method_count' => count($allMethods),
    'raw_401_scenario_count' => count($scenarios401),
    'raw_403_scenario_count' => count($scenarios403),
    'scenarios_401' => $scenarios401,
    'scenarios_403' => $scenarios403,
    'throttle_disable_files' => array_values(array_unique($throttleDisableFiles)),
    'assert_429_files' => $assert429,
];

$outPath = __DIR__.'/_security_inventory_raw.json';
file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "Wrote {$outPath}\n401=".count($scenarios401)." 403=".count($scenarios403)." methods=".count($allMethods)."\n");
