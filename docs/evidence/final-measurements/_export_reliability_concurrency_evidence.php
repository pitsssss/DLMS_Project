<?php

/**
 * Read-only reliability/concurrency evidence exporter.
 * Regenerates RELIABILITY_CONCURRENCY_EVIDENCE_MATRIX.md + reliability_concurrency_evidence.csv
 * from _reliability_concurrency_inventory.json (source of truth).
 *
 * Also recomputes app/ implementation counts: lockForUpdate, DB::transaction(, DB::afterCommit(
 */

declare(strict_types=1);

$outDir = __DIR__;
$root = dirname(__DIR__, 3); // docs/evidence/final-measurements -> project root
$inventoryPath = $outDir.'/_reliability_concurrency_inventory.json';

$inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
$methods = $inventory['methods'];
$meta = $inventory['meta'];

// ---------------------------------------------------------------------------
// Recompute implementation counts from app/
// ---------------------------------------------------------------------------
function countInApp(string $root, string $pattern): array
{
    $count = 0;
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        $n = preg_match_all($pattern, $contents, $m);
        if ($n > 0) {
            $count += $n;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$rel] = $n;
        }
    }
    ksort($files);

    return [$count, $files];
}

[$lockCount, $lockFiles] = countInApp($root, '/lockForUpdate/');
[$txCount, $txFiles] = countInApp($root, '/DB::transaction\s*\(/');
[$afterCount, $afterFiles] = countInApp($root, '/DB::afterCommit\s*\(/');

// ---------------------------------------------------------------------------
// Metric catalogs
// ---------------------------------------------------------------------------
$metricIds = [
    'REL-IDEMPOTENCY-METHODS',
    'REL-DUPLICATE-SIDE-EFFECT-METHODS',
    'REL-ROLLBACK-METHODS',
    'REL-ATOMICITY-METHODS',
    'REL-AFTERCOMMIT-SAFETY-METHODS',
    'REL-STALE-STATE-REJECTION-METHODS',
    'CONC-OPTIMISTIC-CONFLICT-METHODS',
    'CONC-APPOINTMENT-METHODS',
    'PAY-IDEMPOTENCY-METHODS',
    'PAY-UNIQUENESS-METHODS',
    'PAY-CONCURRENCY-INTEGRITY-METHODS',
    'PAY-WEBHOOK-IDEMPOTENCY-METHODS',
    'PAY-MONEY-PRECISION-METHODS',
    'LIC-ISSUANCE-INTEGRITY-METHODS',
    'LIC-DUPLICATE-PREVENTION-METHODS',
    'LIC-STALE-REVALIDATION-METHODS',
    'NOTIF-IDEMPOTENCY-METHODS',
    'NOTIF-TX-SAFETY-METHODS',
    'PUSH-RETRY-METHODS',
    'PUSH-TERMINAL-NO-RESEND-METHODS',
    'PUSH-RECOVERY-METHODS',
    'REL-SESSION-LIFECYCLE-METHODS',
    'AI-RELIABILITY-METHODS',
    'AI-STALE-GUARD-METHODS',
    'AI-CANCEL-NO-MUTATION-METHODS',
    'REL-RECOVERY-METHODS',
];

$byMetric = [];
foreach ($metricIds as $id) {
    $byMetric[$id] = [];
}
foreach ($methods as $row) {
    foreach ($row['metrics'] as $mid) {
        if (! isset($byMetric[$mid])) {
            $byMetric[$mid] = [];
        }
        $key = $row['file'].'::'.$row['method'];
        $byMetric[$mid][$key] = $row;
    }
}
$metricCounts = [];
foreach ($byMetric as $mid => $rows) {
    $metricCounts[$mid] = count($rows);
}

$optimisticEntities = $inventory['optimistic_entities'];
$locked = $inventory['locked_domains'];
$dbInvariants = $inventory['db_invariants'];
$claims = $inventory['claims'];
$gaps = $inventory['gaps'];

$identifiedN = count($locked['identified']);
$withTestsN = count($locked['with_behavioral_tests']);
$dbTotal = count($dbInvariants);
$dbTested = count(array_filter($dbInvariants, static fn ($d) => $d['tested'] === true));

$uniqueMethodKeys = [];
foreach ($methods as $row) {
    $uniqueMethodKeys[$row['file'].'::'.$row['method']] = true;
}
$uniqueMethodCount = count($uniqueMethodKeys);

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------
$csvRows = [];
$csvPush = static function (array &$csvRows, array $r): void {
    $csvRows[] = $r;
};

foreach ($metricCounts as $mid => $cnt) {
    $csvPush($csvRows, [
        'row_type' => 'summary',
        'metric_id' => $mid,
        'file' => '',
        'method' => '',
        'domain' => '',
        'operation' => '',
        'classification' => 'distinct_method_count',
        'stimulus' => '',
        'outcome' => (string) $cnt,
        'http_status' => '',
        'side_effect' => '',
        'expected_max' => '',
        'notes' => 'Distinct methods tagged with this metric_id',
    ]);
}

$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'CONC-LOCK-CALLS', 'file' => '', 'method' => '',
    'domain' => 'app', 'operation' => 'lockForUpdate', 'classification' => 'implementation_metric_not_outcome',
    'stimulus' => '', 'outcome' => (string) $lockCount, 'http_status' => '', 'side_effect' => '',
    'expected_max' => '', 'notes' => count($lockFiles).' files; IMPLEMENTATION METRIC, NOT OUTCOME METRIC',
]);
$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'IMPL-LOCKFORUPDATE', 'file' => '', 'method' => '',
    'domain' => 'app', 'operation' => 'lockForUpdate', 'classification' => 'impl_count_alias',
    'stimulus' => '', 'outcome' => (string) $lockCount, 'http_status' => '', 'side_effect' => '',
    'expected_max' => '', 'notes' => 'Alias of CONC-LOCK-CALLS; '.count($lockFiles).' files',
]);
$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'IMPL-DB-TRANSACTION', 'file' => '', 'method' => '',
    'domain' => 'app', 'operation' => 'DB::transaction(', 'classification' => 'impl_count',
    'stimulus' => '', 'outcome' => (string) $txCount, 'http_status' => '', 'side_effect' => '',
    'expected_max' => '', 'notes' => count($txFiles).' files',
]);
$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'IMPL-DB-AFTERCOMMIT', 'file' => '', 'method' => '',
    'domain' => 'app', 'operation' => 'DB::afterCommit(', 'classification' => 'impl_count',
    'stimulus' => '', 'outcome' => (string) $afterCount, 'http_status' => '', 'side_effect' => '',
    'expected_max' => '', 'notes' => implode(',', array_keys($afterFiles)),
]);
$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'CONC-OPTIMISTIC-ENTITIES', 'file' => '', 'method' => '',
    'domain' => 'schema', 'operation' => 'version fields', 'classification' => 'entity_count',
    'stimulus' => '', 'outcome' => (string) count($optimisticEntities), 'http_status' => '409',
    'side_effect' => '', 'expected_max' => '', 'notes' => 'AppointmentSlot, Role, Fee',
]);
$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'CONC-LOCKED-DOMAINS', 'file' => '', 'method' => '',
    'domain' => 'architecture', 'operation' => 'lockForUpdate domains', 'classification' => 'ratio',
    'stimulus' => '', 'outcome' => $withTestsN.'/'.$identifiedN, 'http_status' => '',
    'side_effect' => '', 'expected_max' => '', 'notes' => 'WITH-BEHAVIORAL-TESTS / IDENTIFIED',
]);
$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'REL-DB-INVARIANTS', 'file' => '', 'method' => '',
    'domain' => 'schema', 'operation' => 'critical unique constraints', 'classification' => 'invariant_set',
    'stimulus' => '', 'outcome' => (string) $dbTotal, 'http_status' => '',
    'side_effect' => '', 'expected_max' => '', 'notes' => 'Conservative critical set',
]);
$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'REL-DB-INVARIANTS-TESTED', 'file' => '', 'method' => '',
    'domain' => 'schema', 'operation' => 'tested invariants', 'classification' => 'ratio',
    'stimulus' => '', 'outcome' => $dbTested.'/'.$dbTotal, 'http_status' => '',
    'side_effect' => '', 'expected_max' => '', 'notes' => 'Direct QueryException or create-reject evidence',
]);
$csvPush($csvRows, [
    'row_type' => 'summary', 'metric_id' => 'REL-UNIQUE-METHODS', 'file' => '', 'method' => '',
    'domain' => 'inventory', 'operation' => 'unique methods in inventory', 'classification' => 'method_count',
    'stimulus' => '', 'outcome' => (string) $uniqueMethodCount, 'http_status' => '',
    'side_effect' => '', 'expected_max' => '', 'notes' => 'Do not sum metric columns',
]);

foreach ($methods as $row) {
    foreach ($row['metrics'] as $mid) {
        $csvPush($csvRows, [
            'row_type' => 'method',
            'metric_id' => $mid,
            'file' => $row['file'],
            'method' => $row['method'],
            'domain' => $row['domain'],
            'operation' => $row['operation'],
            'classification' => $row['classification'] ?? ($row['idempotency_class'] ?? ''),
            'stimulus' => $row['stimulus'],
            'outcome' => $row['outcome'],
            'http_status' => $row['http_status'] ?? '',
            'side_effect' => $row['side_effect'] ?? '',
            'expected_max' => $row['expected_max'] ?? '',
            'notes' => $row['notes'],
        ]);
    }
}

foreach ($lockFiles as $file => $n) {
    $csvPush($csvRows, [
        'row_type' => 'mechanism', 'metric_id' => 'IMPL-LOCKFORUPDATE', 'file' => $file, 'method' => '',
        'domain' => '', 'operation' => 'lockForUpdate', 'classification' => 'call_sites',
        'stimulus' => '', 'outcome' => (string) $n, 'http_status' => '', 'side_effect' => '',
        'expected_max' => '', 'notes' => 'occurrences in file',
    ]);
}
foreach ($txFiles as $file => $n) {
    $csvPush($csvRows, [
        'row_type' => 'mechanism', 'metric_id' => 'IMPL-DB-TRANSACTION', 'file' => $file, 'method' => '',
        'domain' => '', 'operation' => 'DB::transaction(', 'classification' => 'call_sites',
        'stimulus' => '', 'outcome' => (string) $n, 'http_status' => '', 'side_effect' => '',
        'expected_max' => '', 'notes' => 'occurrences in file',
    ]);
}
foreach ($afterFiles as $file => $n) {
    $csvPush($csvRows, [
        'row_type' => 'mechanism', 'metric_id' => 'IMPL-DB-AFTERCOMMIT', 'file' => $file, 'method' => '',
        'domain' => 'notifications', 'operation' => 'DB::afterCommit(', 'classification' => 'call_sites',
        'stimulus' => '', 'outcome' => (string) $n, 'http_status' => '', 'side_effect' => '',
        'expected_max' => '', 'notes' => 'NotificationService::runAfterCommit',
    ]);
}

foreach ($optimisticEntities as $e) {
    $csvPush($csvRows, [
        'row_type' => 'optimistic_entity', 'metric_id' => 'CONC-OPTIMISTIC-ENTITIES',
        'file' => '', 'method' => '', 'domain' => $e['entity'], 'operation' => $e['field'],
        'classification' => 'optimistic_version', 'stimulus' => 'stale version',
        'outcome' => 'conflict', 'http_status' => (string) $e['conflict_http'],
        'side_effect' => '', 'expected_max' => '', 'notes' => 'version column',
    ]);
}

foreach ($dbInvariants as $inv) {
    $csvPush($csvRows, [
        'row_type' => 'db_invariant', 'metric_id' => $inv['tested'] ? 'REL-DB-INVARIANTS-TESTED' : 'REL-DB-INVARIANTS',
        'file' => '', 'method' => '', 'domain' => 'schema', 'operation' => $inv['constraint'],
        'classification' => $inv['tested'] ? 'tested' : 'untested',
        'stimulus' => '', 'outcome' => $inv['tested'] ? 'TESTED' : 'UNTESTED',
        'http_status' => '', 'side_effect' => '', 'expected_max' => '', 'notes' => $inv['evidence'],
    ]);
}

foreach ($claims as $c) {
    $csvPush($csvRows, [
        'row_type' => 'claim', 'metric_id' => '', 'file' => '', 'method' => '',
        'domain' => '', 'operation' => '', 'classification' => $c['status'],
        'stimulus' => '', 'outcome' => $c['claim'], 'http_status' => '',
        'side_effect' => '', 'expected_max' => '', 'notes' => ($c['note'] ?? ($c['limitation'] ?? '')),
    ]);
}

foreach ($gaps as $g) {
    $gapText = is_array($g) ? ($g['gap'] ?? '') : $g;
    $csvPush($csvRows, [
        'row_type' => 'gap', 'metric_id' => '', 'file' => '', 'method' => '',
        'domain' => '', 'operation' => '', 'classification' => is_array($g) ? ($g['committee_value'] ?? 'gap') : 'gap',
        'stimulus' => is_array($g) ? ($g['risk'] ?? '') : '', 'outcome' => $gapText, 'http_status' => '',
        'side_effect' => '', 'expected_max' => '', 'notes' => is_array($g) ? ($g['effort'] ?? '') : '',
    ]);
}

$csvPath = $outDir.'/reliability_concurrency_evidence.csv';
$fh = fopen($csvPath, 'w');
$headers = ['row_type', 'metric_id', 'file', 'method', 'domain', 'operation', 'classification', 'stimulus', 'outcome', 'http_status', 'side_effect', 'expected_max', 'notes'];
fputcsv($fh, $headers);
foreach ($csvRows as $r) {
    $line = [];
    foreach ($headers as $h) {
        $line[] = $r[$h] ?? '';
    }
    fputcsv($fh, $line);
}
fclose($fh);

// ---------------------------------------------------------------------------
// Markdown helpers
// ---------------------------------------------------------------------------
$esc = static fn (?string $s): string => str_replace('|', '\\|', (string) $s);

$renderMethodTable = static function (array $rows) use ($esc): string {
    if ($rows === []) {
        return "_None in curated inventory._\n";
    }
    $out = "| # | File | Method | Domain | Stimulus | Outcome | HTTP | Notes |\n";
    $out .= "|---|------|--------|--------|----------|---------|------|-------|\n";
    $i = 1;
    foreach ($rows as $row) {
        $out .= '| '.$i.' | `'.$esc($row['file']).'` | `'.$esc($row['method']).'` | '.$esc($row['domain']).' | '.$esc($row['stimulus']).' | '.$esc($row['outcome']).' | '.($row['http_status'] ?? '—').' | '.$esc($row['notes'])." |\n";
        $i++;
    }

    return $out;
};

$md = [];
$L = static function (string $s): string { return $s; };
$md[] = $L('# Reliability / Concurrency Evidence Matrix (Quantitative)');
$md[] = '';
$md[] = '**System:** '.$meta['system'];
$md[] = '**Scope:** '.$meta['scope'];
$md[] = '**Audit type:** Read-only quantitative inventory (method bodies reviewed; names insufficient)';
$md[] = '**Date:** '.$meta['date'];
$md[] = '**Source of truth:** _reliability_concurrency_inventory.json';
$md[] = '';
$md[] = '### Suite context (provided by project; not re-run in this inventory)';
$md[] = '';
$md[] = '| Item | Value |';
$md[] = '|------|-------|';
$md[] = '| Latest full suite | **'.$meta['suite_tests'].' passed** |';
$md[] = '| Assertions | **'.$meta['suite_assertions'].'** |';
$md[] = '| Duration | **'.$meta['suite_duration_seconds'].'s** |';
$md[] = '| Curated unique methods | **'.$uniqueMethodCount.'** |';
$md[] = '';
$md[] = '### Counting discipline';
$md[] = '';
$md[] = '| Rule | Application |';
$md[] = '|------|-------------|';
$md[] = '| Distinct per metric | Each metric_id counts **distinct methods** tagged with it |';
$md[] = '| Cross-metric overlap | Allowed (one method may appear under many metrics) |';
$md[] = '| **Never sum metrics** | Overlapping tags must not be added into a fake total |';
$md[] = '| Conservative inclusion | Body must prove the property; borderline cases excluded |';
$md[] = '| Machine-readable companion | reliability_concurrency_evidence.csv |';
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 1. Architecture mechanisms of reliability';
$md[] = '';
$md[] = 'Do **not** call the whole system fault tolerant. Mechanisms below are inventory + evidence status.';
$md[] = '';
$md[] = '| Mechanism | Where implemented | Failure mode addressed | Automated behavioral evidence |';
$md[] = '|-----------|-------------------|------------------------|-------------------------------|';
$mechanisms = $inventory['mechanisms'] ?? [];
foreach ($mechanisms as $mech) {
    $md[] = '| '.$esc($mech['mechanism']).' | '.$esc($mech['where']).' | '.$esc($mech['failure_mode']).' | '.$esc($mech['behavioral_evidence']).' |';
}
$md[] = '';
$md[] = '### Implementation counts (NOT outcome metrics)';
$md[] = '';
$md[] = '| Metric | Exact count | Notes |';
$md[] = '|--------|-------------|-------|';
$md[] = '| **CONC-LOCK-CALLS** (`lockForUpdate`) | **'.$lockCount.'** across **'.count($lockFiles).'** files | IMPLEMENTATION METRIC — does not prove tested races |';
$md[] = '| DB::transaction( | **'.$txCount.'** | IMPLEMENTATION METRIC — not reliability proof by itself |';
$md[] = '| DB::afterCommit( | **'.$afterCount.'** | NotificationService::runAfterCommit only |';
$md[] = '| CONC-OPTIMISTIC-ENTITIES | **'.count($optimisticEntities).'** | AppointmentSlot, Role, Fee |';
$md[] = '| Locked domains with behavioral tests | **'.$withTestsN.'/'.$identifiedN.'** | fines + test-results lack concurrency behavioral tests |';
$md[] = '| REL-DB-INVARIANTS tested | **'.$dbTested.'/'.$dbTotal.'** | Conservative critical unique set |';
$md[] = '';
$md[] = '### lockForUpdate files (CONC-LOCK-CALLS detail)';
$md[] = '';
$md[] = '| File | Occurrences |';
$md[] = '|------|-------------|';
foreach ($lockFiles as $file => $n) {
    $md[] = '| '.$file.' | '.$n.' |';
}
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 2. Idempotency methods — REL-IDEMPOTENCY-METHODS';
$md[] = '';
$md[] = '**EXACT distinct methods: '.$metricCounts['REL-IDEMPOTENCY-METHODS'].'**';
$md[] = '';
$md[] = $renderMethodTable($byMetric['REL-IDEMPOTENCY-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 3. Duplicate side-effect prevention — REL-DUPLICATE-SIDE-EFFECT-METHODS';
$md[] = '';
$md[] = '**EXACT distinct methods: '.$metricCounts['REL-DUPLICATE-SIDE-EFFECT-METHODS'].'**';
$md[] = '';
$md[] = $renderMethodTable($byMetric['REL-DUPLICATE-SIDE-EFFECT-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 4. Rollback / atomicity / afterCommit safety';
$md[] = '';
$md[] = '| Metric | Exact |';
$md[] = '|--------|-------|';
$md[] = '| REL-ROLLBACK-METHODS | **'.$metricCounts['REL-ROLLBACK-METHODS'].'** |';
$md[] = '| REL-ATOMICITY-METHODS | **'.$metricCounts['REL-ATOMICITY-METHODS'].'** |';
$md[] = '| REL-AFTERCOMMIT-SAFETY-METHODS | **'.$metricCounts['REL-AFTERCOMMIT-SAFETY-METHODS'].'** |';
$md[] = '| NOTIF-TX-SAFETY-METHODS | **'.$metricCounts['NOTIF-TX-SAFETY-METHODS'].'** |';
$md[] = '';
$md[] = '### afterCommit safety (only methods that prove semantics)';
$md[] = '';
$md[] = $renderMethodTable($byMetric['REL-AFTERCOMMIT-SAFETY-METHODS']);
$md[] = '**Gap:** No dedicated positive Feature test that notifications emit only after production DB::afterCommit (PHPUnit path runs callbacks immediately when runningUnitTests()).';
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 5. Stale-state rejection — REL-STALE-STATE-REJECTION-METHODS';
$md[] = '';
$md[] = '**EXACT distinct methods: '.$metricCounts['REL-STALE-STATE-REJECTION-METHODS'].'**';
$md[] = '';
$md[] = 'Classes observed: optimistic_version | workflow_guard | expiring_token | revalidation_before_commit';
$md[] = '';
$md[] = $renderMethodTable($byMetric['REL-STALE-STATE-REJECTION-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 6. Optimistic concurrency and locked domains';
$md[] = '';
$md[] = '### CONC-OPTIMISTIC-ENTITIES = **'.count($optimisticEntities).'**';
$md[] = '';
$md[] = '| Entity | Field | Conflict HTTP |';
$md[] = '|--------|-------|---------------|';
foreach ($optimisticEntities as $e) {
    $md[] = '| '.$e['entity'].' | '.$e['field'].' | '.$e['conflict_http'].' |';
}
$md[] = '';
$md[] = '### CONC-OPTIMISTIC-CONFLICT-METHODS = **'.$metricCounts['CONC-OPTIMISTIC-CONFLICT-METHODS'].'** (409 stale_version only)';
$md[] = '';
$md[] = $renderMethodTable($byMetric['CONC-OPTIMISTIC-CONFLICT-METHODS']);
$md[] = 'Capacity-below-booked asserts **422** and are counted under CONC-APPOINTMENT-METHODS + REL-STALE-STATE-REJECTION-METHODS, **not** optimistic-conflict.';
$md[] = '';
$md[] = '### Locked domains';
$md[] = '';
$md[] = '| Set | Count | Members |';
$md[] = '|-----|-------|---------|';
$md[] = '| IDENTIFIED | '.$identifiedN.' | '.implode(', ', $locked['identified']).' |';
$md[] = '| WITH-BEHAVIORAL-TESTS | '.$withTestsN.' | '.implode(', ', $locked['with_behavioral_tests']).' |';
$md[] = '| locks without concurrency behavioral tests | '.count($locked['implemented_locks_without_concurrency_behavioral_tests']).' | '.implode(', ', $locked['implemented_locks_without_concurrency_behavioral_tests']).' |';
$md[] = '| **Ratio** | **'.$withTestsN.'/'.$identifiedN.'** | |';
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 7. DB invariants — REL-DB-INVARIANTS = **'.$dbTotal.'**; tested **'.$dbTested.'/'.$dbTotal.'**';
$md[] = '';
$md[] = '| # | Constraint | Tested? | Evidence |';
$md[] = '|---|------------|---------|----------|';
foreach ($dbInvariants as $inv) {
    $md[] = '| '.$inv['id'].' | '.$esc($inv['constraint']).' | '.($inv['tested'] ? 'YES' : 'NO').' | '.$esc($inv['evidence']).' |';
}
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 8. Appointment concurrency deep dive — CONC-APPOINTMENT-METHODS';
$md[] = '';
$md[] = '**EXACT distinct methods: '.$metricCounts['CONC-APPOINTMENT-METHODS'].'**';
$md[] = '';
$md[] = '### Concurrent booking (canonical numbers)';
$md[] = '';
$md[] = '| Assertion | Value |';
$md[] = '|-----------|-------|';
$md[] = '| Slot capacity | **1** |';
$md[] = '| Concurrent actors | 2 citizens (A, B) |';
$md[] = '| Success count | **1** |';
$md[] = '| Failure count | **1** |';
$md[] = '| Final booked_count | **1** |';
$md[] = '| Overbook | **0** |';
$md[] = '| Parallelism model | Sequential HTTP loop in PHPUnit (foreach two requests) — **not** OS threads |';
$md[] = '| Test | AppointmentSlotConcurrencyTest::test_concurrent_booking_cannot_overbook_single_capacity_slot |';
$md[] = '';
$md[] = $renderMethodTable($byMetric['CONC-APPOINTMENT-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 9. Payment reliability metrics';
$md[] = '';
$md[] = '| Metric | Exact |';
$md[] = '|--------|-------|';
$md[] = '| PAY-IDEMPOTENCY-METHODS | **'.$metricCounts['PAY-IDEMPOTENCY-METHODS'].'** |';
$md[] = '| PAY-UNIQUENESS-METHODS | **'.$metricCounts['PAY-UNIQUENESS-METHODS'].'** |';
$md[] = '| PAY-CONCURRENCY-INTEGRITY-METHODS | **'.$metricCounts['PAY-CONCURRENCY-INTEGRITY-METHODS'].'** |';
$md[] = '| PAY-WEBHOOK-IDEMPOTENCY-METHODS | **'.$metricCounts['PAY-WEBHOOK-IDEMPOTENCY-METHODS'].'** |';
$md[] = '| PAY-MONEY-PRECISION-METHODS | **'.$metricCounts['PAY-MONEY-PRECISION-METHODS'].'** |';
$md[] = '';
$md[] = '### Payment reconciliation status';
$md[] = '';
$md[] = '| Component | Status |';
$md[] = '|-----------|--------|';
$md[] = '| PaymentReconciliationService | **EXISTS** |';
$md[] = '| ReconcilePendingPaymentsCommand | **EXISTS** |';
$md[] = '| Dedicated Feature reconcile test | **NONE** |';
$md[] = '| Committee claim | **IMPLEMENTED BUT UNMEASURED** — do not invent REL-RECOVERY-METHODS for payment reconcile |';
$md[] = '';
$md[] = '### PAY-IDEMPOTENCY';
$md[] = $renderMethodTable($byMetric['PAY-IDEMPOTENCY-METHODS']);
$md[] = '### PAY-UNIQUENESS';
$md[] = $renderMethodTable($byMetric['PAY-UNIQUENESS-METHODS']);
$md[] = '### PAY-CONCURRENCY-INTEGRITY (includes manual Stripe confirm disabled + duplicate initiation)';
$md[] = $renderMethodTable($byMetric['PAY-CONCURRENCY-INTEGRITY-METHODS']);
$md[] = '### PAY-WEBHOOK-IDEMPOTENCY';
$md[] = $renderMethodTable($byMetric['PAY-WEBHOOK-IDEMPOTENCY-METHODS']);
$md[] = '### PAY-MONEY-PRECISION (includes currency/amount mismatch under-verification integrity)';
$md[] = $renderMethodTable($byMetric['PAY-MONEY-PRECISION-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 10. License issuance integrity';
$md[] = '';
$md[] = '| Metric | Exact |';
$md[] = '|--------|-------|';
$md[] = '| LIC-ISSUANCE-INTEGRITY-METHODS | **'.$metricCounts['LIC-ISSUANCE-INTEGRITY-METHODS'].'** |';
$md[] = '| LIC-DUPLICATE-PREVENTION-METHODS | **'.$metricCounts['LIC-DUPLICATE-PREVENTION-METHODS'].'** |';
$md[] = '| LIC-STALE-REVALIDATION-METHODS | **'.$metricCounts['LIC-STALE-REVALIDATION-METHODS'].'** |';
$md[] = '';
$md[] = '### LIC-ISSUANCE-INTEGRITY';
$md[] = $renderMethodTable($byMetric['LIC-ISSUANCE-INTEGRITY-METHODS']);
$md[] = '### LIC-DUPLICATE-PREVENTION';
$md[] = $renderMethodTable($byMetric['LIC-DUPLICATE-PREVENTION-METHODS']);
$md[] = '### LIC-STALE-REVALIDATION';
$md[] = $renderMethodTable($byMetric['LIC-STALE-REVALIDATION-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 11. Notifications and push';
$md[] = '';
$md[] = '| Metric | Exact |';
$md[] = '|--------|-------|';
$md[] = '| NOTIF-IDEMPOTENCY-METHODS | **'.$metricCounts['NOTIF-IDEMPOTENCY-METHODS'].'** |';
$md[] = '| NOTIF-TX-SAFETY-METHODS | **'.$metricCounts['NOTIF-TX-SAFETY-METHODS'].'** |';
$md[] = '| PUSH-RETRY-METHODS | **'.$metricCounts['PUSH-RETRY-METHODS'].'** |';
$md[] = '| PUSH-TERMINAL-NO-RESEND-METHODS | **'.$metricCounts['PUSH-TERMINAL-NO-RESEND-METHODS'].'** |';
$md[] = '| PUSH-RECOVERY-METHODS | **'.$metricCounts['PUSH-RECOVERY-METHODS'].'** |';
$md[] = '';
$md[] = '### NOTIF-IDEMPOTENCY';
$md[] = $renderMethodTable($byMetric['NOTIF-IDEMPOTENCY-METHODS']);
$md[] = '### NOTIF-TX-SAFETY';
$md[] = $renderMethodTable($byMetric['NOTIF-TX-SAFETY-METHODS']);
$md[] = '### PUSH-RETRY';
$md[] = $renderMethodTable($byMetric['PUSH-RETRY-METHODS']);
$md[] = '### PUSH-TERMINAL-NO-RESEND';
$md[] = $renderMethodTable($byMetric['PUSH-TERMINAL-NO-RESEND-METHODS']);
$md[] = '### PUSH-RECOVERY';
$md[] = $renderMethodTable($byMetric['PUSH-RECOVERY-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 12. Session lifecycle — REL-SESSION-LIFECYCLE-METHODS';
$md[] = '';
$md[] = '**EXACT distinct methods: '.$metricCounts['REL-SESSION-LIFECYCLE-METHODS'].'**';
$md[] = '';
$md[] = $renderMethodTable($byMetric['REL-SESSION-LIFECYCLE-METHODS']);
$md[] = 'Note: EmployeeSessionLifecycleTest::test_repeated_logout_is_idempotent proves ended-reason precedence only — **not** double-logout HTTP idempotency.';
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 13. AI agent reliability';
$md[] = '';
$md[] = '| Metric | Exact |';
$md[] = '|--------|-------|';
$md[] = '| AI-RELIABILITY-METHODS | **'.$metricCounts['AI-RELIABILITY-METHODS'].'** |';
$md[] = '| AI-STALE-GUARD-METHODS | **'.$metricCounts['AI-STALE-GUARD-METHODS'].'** |';
$md[] = '| AI-CANCEL-NO-MUTATION-METHODS | **'.$metricCounts['AI-CANCEL-NO-MUTATION-METHODS'].'** |';
$md[] = '';
$md[] = '### AI-RELIABILITY';
$md[] = $renderMethodTable($byMetric['AI-RELIABILITY-METHODS']);
$md[] = '### AI-STALE-GUARD';
$md[] = $renderMethodTable($byMetric['AI-STALE-GUARD-METHODS']);
$md[] = '### AI-CANCEL-NO-MUTATION';
$md[] = $renderMethodTable($byMetric['AI-CANCEL-NO-MUTATION-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 14. Recovery methods — REL-RECOVERY-METHODS';
$md[] = '';
$md[] = '**EXACT distinct methods: '.$metricCounts['REL-RECOVERY-METHODS'].'**';
$md[] = '';
$md[] = 'Scope (allowed): push recovery + employee session reconcile/prune + license expiry sync + AI retryable resume. **Excludes** unmeasured payment reconcile command.';
$md[] = '';
$md[] = $renderMethodTable($byMetric['REL-RECOVERY-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 15. Rollback methods detail — REL-ROLLBACK-METHODS';
$md[] = '';
$md[] = '**EXACT: '.$metricCounts['REL-ROLLBACK-METHODS'].'**';
$md[] = '';
$md[] = $renderMethodTable($byMetric['REL-ROLLBACK-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 16. Atomicity methods detail — REL-ATOMICITY-METHODS';
$md[] = '';
$md[] = '**EXACT: '.$metricCounts['REL-ATOMICITY-METHODS'].'**';
$md[] = '';
$md[] = $renderMethodTable($byMetric['REL-ATOMICITY-METHODS']);
$md[] = '---';
$md[] = '';
$md[] = '## 17. Final numeric summary (all metrics)';
$md[] = '';
$md[] = '| Metric ID | Exact value | Denominator / method | Interpretation | Limitation |';
$md[] = '|-----------|-------------|----------------------|----------------|------------|';
$md[] = '| **CONC-LOCK-CALLS** | **'.$lockCount.'** | '.count($lockFiles).' files (app/ scan) | IMPLEMENTATION METRIC only | Not outcome proof |';
$md[] = '| IMPL-DB-TRANSACTION | **'.$txCount.'** | app/ scan DB::transaction( | Atomic units | Excludes transactionLevel |';
$md[] = '| IMPL-DB-AFTERCOMMIT | **'.$afterCount.'** | app/ scan | Notify after commit | Unit tests bypass |';
$md[] = '| CONC-OPTIMISTIC-ENTITIES | **'.count($optimisticEntities).'** | schema | version fields | — |';
$md[] = '| CONC-OPTIMISTIC-CONFLICT-METHODS | **'.$metricCounts['CONC-OPTIMISTIC-CONFLICT-METHODS'].'** | curated | 409 stale_version | capacity 422 excluded |';
$md[] = '| CONC-APPOINTMENT-METHODS | **'.$metricCounts['CONC-APPOINTMENT-METHODS'].'** | curated | booking/capacity | sequential loop |';
$md[] = '| CONC-LOCKED-DOMAINS | **'.$withTestsN.'/'.$identifiedN.'** | architecture | behavioral lock coverage | fines/test-results gap |';
$md[] = '| REL-DB-INVARIANTS | **'.$dbTotal.'** | curated set | critical uniques | conservative |';
$md[] = '| REL-DB-INVARIANTS-TESTED | **'.$dbTested.'/'.$dbTotal.'** | curated | direct proof | 5 untested |';
$md[] = '| REL-IDEMPOTENCY-METHODS | **'.$metricCounts['REL-IDEMPOTENCY-METHODS'].'** | curated | idempotent ops | overlap OK |';
$md[] = '| REL-DUPLICATE-SIDE-EFFECT-METHODS | **'.$metricCounts['REL-DUPLICATE-SIDE-EFFECT-METHODS'].'** | curated | no duplicate effects | — |';
$md[] = '| REL-ROLLBACK-METHODS | **'.$metricCounts['REL-ROLLBACK-METHODS'].'** | curated | rollback safety | — |';
$md[] = '| REL-ATOMICITY-METHODS | **'.$metricCounts['REL-ATOMICITY-METHODS'].'** | curated | atomic multi-write | — |';
$md[] = '| REL-AFTERCOMMIT-SAFETY-METHODS | **'.$metricCounts['REL-AFTERCOMMIT-SAFETY-METHODS'].'** | curated | afterCommit semantics | no positive emit test |';
$md[] = '| REL-STALE-STATE-REJECTION-METHODS | **'.$metricCounts['REL-STALE-STATE-REJECTION-METHODS'].'** | curated | stale rejection | — |';
$md[] = '| REL-SESSION-LIFECYCLE-METHODS | **'.$metricCounts['REL-SESSION-LIFECYCLE-METHODS'].'** | curated | session lifecycle | — |';
$md[] = '| REL-RECOVERY-METHODS | **'.$metricCounts['REL-RECOVERY-METHODS'].'** | curated | recovery paths | payment reconcile excluded |';
$md[] = '| PAY-IDEMPOTENCY-METHODS | **'.$metricCounts['PAY-IDEMPOTENCY-METHODS'].'** | curated | payment idempotency | — |';
$md[] = '| PAY-UNIQUENESS-METHODS | **'.$metricCounts['PAY-UNIQUENESS-METHODS'].'** | curated | payment uniques | — |';
$md[] = '| PAY-CONCURRENCY-INTEGRITY-METHODS | **'.$metricCounts['PAY-CONCURRENCY-INTEGRITY-METHODS'].'** | curated | payment integrity | includes manual confirm disabled |';
$md[] = '| PAY-WEBHOOK-IDEMPOTENCY-METHODS | **'.$metricCounts['PAY-WEBHOOK-IDEMPOTENCY-METHODS'].'** | curated | webhook dedupe | — |';
$md[] = '| PAY-MONEY-PRECISION-METHODS | **'.$metricCounts['PAY-MONEY-PRECISION-METHODS'].'** | curated | money correctness | includes mismatch UV |';
$md[] = '| LIC-ISSUANCE-INTEGRITY-METHODS | **'.$metricCounts['LIC-ISSUANCE-INTEGRITY-METHODS'].'** | curated | issuance integrity | — |';
$md[] = '| LIC-DUPLICATE-PREVENTION-METHODS | **'.$metricCounts['LIC-DUPLICATE-PREVENTION-METHODS'].'** | curated | license/app dupes | — |';
$md[] = '| LIC-STALE-REVALIDATION-METHODS | **'.$metricCounts['LIC-STALE-REVALIDATION-METHODS'].'** | curated | stale ready queue | — |';
$md[] = '| NOTIF-IDEMPOTENCY-METHODS | **'.$metricCounts['NOTIF-IDEMPOTENCY-METHODS'].'** | curated | notify dedupe | — |';
$md[] = '| NOTIF-TX-SAFETY-METHODS | **'.$metricCounts['NOTIF-TX-SAFETY-METHODS'].'** | curated | notify TX isolation | — |';
$md[] = '| PUSH-RETRY-METHODS | **'.$metricCounts['PUSH-RETRY-METHODS'].'** | curated | push retry | — |';
$md[] = '| PUSH-TERMINAL-NO-RESEND-METHODS | **'.$metricCounts['PUSH-TERMINAL-NO-RESEND-METHODS'].'** | curated | no terminal resend | — |';
$md[] = '| PUSH-RECOVERY-METHODS | **'.$metricCounts['PUSH-RECOVERY-METHODS'].'** | curated | push recovery | — |';
$md[] = '| AI-RELIABILITY-METHODS | **'.$metricCounts['AI-RELIABILITY-METHODS'].'** | curated | AI reliability | — |';
$md[] = '| AI-STALE-GUARD-METHODS | **'.$metricCounts['AI-STALE-GUARD-METHODS'].'** | curated | AI stale guards | — |';
$md[] = '| AI-CANCEL-NO-MUTATION-METHODS | **'.$metricCounts['AI-CANCEL-NO-MUTATION-METHODS'].'** | curated | cancel no mutation | — |';
$md[] = '| REL-UNIQUE-METHODS (inventory) | **'.$uniqueMethodCount.'** | inventory | unique methods | do not sum metrics |';
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 18. Committee-safe claims';
$md[] = '';
$md[] = '| Claim | Status | Notes |';
$md[] = '|-------|--------|-------|';
foreach ($claims as $c) {
    $md[] = '| '.$esc($c['claim']).' | **'.$esc($c['status']).'** | '.$esc($c['note'] ?? ($c['limitation'] ?? '')).' |';
}
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 19. Gaps';
$md[] = '';
$md[] = '| # | Gap | Committee value | Risk | Effort |';
$md[] = '|---|-----|-----------------|------|--------|';
$i = 1;
foreach ($gaps as $g) {
    if (is_array($g)) {
        $md[] = '| '.$i.' | '.$esc($g['gap']).' | '.$esc($g['committee_value'] ?? '').' | '.$esc($g['risk'] ?? '').' | '.$esc($g['effort'] ?? '').' |';
    } else {
        $md[] = '| '.$i.' | '.$esc($g).' | — | — | — |';
    }
    $i++;
}
$md[] = '';
$md[] = 'Do **not** implement these in this audit.';
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = '## 20. Reproducibility';
$md[] = '';
$md[] = '### Artifacts';
$md[] = '- docs/evidence/final-measurements/_reliability_concurrency_inventory.json (source of truth)';
$md[] = '- docs/evidence/final-measurements/_export_reliability_concurrency_evidence.php (this exporter)';
$md[] = '- docs/evidence/final-measurements/RELIABILITY_CONCURRENCY_EVIDENCE_MATRIX.md';
$md[] = '- docs/evidence/final-measurements/reliability_concurrency_evidence.csv';
$md[] = '';
$md[] = '### Command';
$md[] = '```text';
$md[] = 'php docs/evidence/final-measurements/_export_reliability_concurrency_evidence.php';
$md[] = '```';
$md[] = '';
$md[] = '### Rules';
$md[] = '1. Do not invent methods — inventory is curated from method-body review.';
$md[] = '2. Metric totals are **distinct method counts** per metric_id.';
$md[] = '3. Implementation counts are recomputed on each export from app/.';
$md[] = '4. Suite totals (1043 / 6557 / 217.86s) are project-provided context, not re-run here.';
$md[] = '';
$md[] = sprintf(
    '**Exporter recomputed:** lockForUpdate=%s; DB::transaction=%s; DB::afterCommit=%s; unique_methods=%s',
    $lockCount,
    $txCount,
    $afterCount,
    $uniqueMethodCount
);
$md[] = '';

$mdPath = $outDir.'/RELIABILITY_CONCURRENCY_EVIDENCE_MATRIX.md';
file_put_contents($mdPath, implode("\n", $md));

echo "unique_methods={$uniqueMethodCount}\n";
echo "lockForUpdate={$lockCount} files=".count($lockFiles)."\n";
echo "DB::transaction={$txCount}\n";
echo "DB::afterCommit={$afterCount}\n";
echo "db_invariants_tested={$dbTested}/{$dbTotal}\n";
echo "locked_domains={$withTestsN}/{$identifiedN}\n";
foreach ($metricCounts as $mid => $cnt) {
    echo "{$mid}={$cnt}\n";
}
echo "wrote={$mdPath}\n";
echo "wrote={$csvPath}\n";
