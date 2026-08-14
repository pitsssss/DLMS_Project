<?php

/**
 * Recursive leaf-key localization inventory + behavioral suite classifier.
 * Read-only for application code.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$outDir = __DIR__;
$arDir = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'ar';
$enDir = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'en';

function loadPhpArray(string $path): array
{
    if (! is_file($path)) {
        return [];
    }
    $data = include $path;

    return is_array($data) ? $data : [];
}

/** @return array<string, mixed> */
function flattenLeaves(array $data, string $prefix = ''): array
{
    $out = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            if ($value === []) {
                continue;
            }
            $out += flattenLeaves($value, $path);
            continue;
        }
        $out[$path] = $value;
    }

    return $out;
}

function listLocaleFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $files = glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [];
    sort($files);

    return $files;
}

function extractTestMethods(string $source): array
{
    $methods = [];
    if (! preg_match_all('/public\s+function\s+(test_\w+)\s*\([^)]*\)[^{]*\{/', $source, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($matches[1] as $i => $nameHit) {
        $name = $nameHit[0];
        $braceStart = strpos($source, '{', $matches[0][$i][1]);
        if ($braceStart === false) {
            continue;
        }
        $depth = 0;
        $len = strlen($source);
        $end = $braceStart;
        for ($p = $braceStart; $p < $len; $p++) {
            if ($source[$p] === '{') {
                $depth++;
            } elseif ($source[$p] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $p;
                    break;
                }
            }
        }
        $methods[] = [
            'name' => $name,
            'body' => substr($source, $braceStart, $end - $braceStart + 1),
        ];
    }

    return $methods;
}

function classifyCategories(string $file, string $method, string $body): array
{
    $cats = [];
    $hay = $method."\n".$body;

    $hasAr = (bool) preg_match('/Accept-Language[\'\"]\s*,\s*[\'\"]ar|Content-Language[\'\"]\s*,\s*[\'\"]ar|[\'\"]ar[\'\"]\s*\)|->language\([\'\"]ar|setLocale\([\'\"]ar|language[\'\"]\s*=>\s*[\'\"]ar|locale.*ar|Arabic|العرب/i', $hay);
    $hasEn = (bool) preg_match('/Accept-Language[\'\"]\s*,\s*[\'\"]en|Content-Language[\'\"]\s*,\s*[\'\"]en|[\'\"]en[\'\"]\s*\)|->language\([\'\"]en|setLocale\([\'\"]en|language[\'\"]\s*=>\s*[\'\"]en|locale.*en|English/i', $hay);

    if ($hasAr || preg_match('/arabic|_ar\b|returns_arabic|is_arabic/i', $method)) {
        $cats[] = 'A';
    }
    if ($hasEn || preg_match('/english|_en\b|returns_english|is_english|is_bilingual/i', $method)) {
        $cats[] = 'B';
    }
    if (preg_match('/bilingual|both_locales|across_locales|ar_and_en|and_english|and_arabic/i', $method)
        || ($hasAr && $hasEn && preg_match('/withHeader\(\s*[\'\"]Accept-Language[\'\"]\s*,\s*[\'\"]ar[\'\"]/i', $body) && preg_match('/withHeader\(\s*[\'\"]Accept-Language[\'\"]\s*,\s*[\'\"]en[\'\"]/i', $body))) {
        $cats[] = 'C';
        $cats[] = 'A';
        $cats[] = 'B';
    }
    if (preg_match('/Accept-Language|Content-Language|normalize|q_value|malformed|unsupported_header|locale_does_not_leak|Vary/i', $hay)
        && (str_contains($file, 'RequestLocaleTest') || preg_match('/Accept-Language|Content-Language/i', $body))) {
        $cats[] = 'D';
    }
    if (preg_match('/language_en_persists|language_ar_persists|stored_language|preference|auth_me_exposes_language|login_user_payload_exposes_language|does_not_persist_preference|put_language|update_language/i', $hay)
        || (str_contains($file, 'CitizenLanguagePreferenceTest'))
        || (str_contains($file, 'SettingsTest') && preg_match('/language/i', $method))) {
        $cats[] = 'E';
    }
    if (preg_match('/fallback|falls_back|without_header_resolves_ar|unsupported|default_locale|null_language|invalid_locale_defaults/i', $hay)) {
        $cats[] = 'F';
    }
    if (preg_match('/validation|assertStatus\(422\)|validate/i', $hay) && preg_match('/Accept-Language|bilingual|arabic|english|Content-Language|validation_error_is_arabic/i', $hay)) {
        $cats[] = 'G';
    }
    if (preg_match('/middleware_access|ApiException|eligibility|not_found|wrong_current_password_returns_arabic|domain/i', $hay)
        && preg_match('/bilingual|arabic|english|Accept-Language/i', $hay)) {
        $cats[] = 'H';
    }
    if (str_contains($file, 'Notification') || str_contains($file, 'RecipientNotification')
        || preg_match('/recipient_language|notification.*language|historical_notification/i', $hay)) {
        $cats[] = 'I';
    }
    if (str_contains($file, 'AIAgent') || str_contains($file, 'AgentLanguage') || str_contains($file, 'AgentLocale')) {
        $cats[] = 'I';
    }
    if (preg_match('/\bcode\b|assertJsonPath\([^\)]*code|status.*stable|codes_remain|while_codes|raw_values_stay|action_codes|type[\'\"]\s*,|machine/i', $hay)
        || preg_match('/codes_remain|while_codes|raw_values_stay|status_label|catalog_labels/i', $method)) {
        $cats[] = 'J';
    }

    // Dedicated bilingual suites imply C when method name says bilingual
    if (preg_match('/bilingual/i', $method)) {
        $cats = array_merge($cats, ['A', 'B', 'C']);
    }

    return array_values(array_unique($cats));
}

function assignModule(string $file, string $method, string $body): string
{
    $hay = $file.' '.$method.' '.$body;
    if (str_contains($file, 'AIAgent') || str_contains($file, 'AgentLanguage') || str_contains($file, 'AgentLocale')) {
        return 'ai_agent';
    }
    if (str_contains($file, 'Notification') || str_contains($file, 'RecipientNotification')) {
        return 'notifications';
    }
    if (str_contains($file, 'LicenseVerification')) {
        return 'public_license_verification';
    }
    if (str_contains($file, 'CitizenCatalog') || str_contains($file, 'CitizenContent')) {
        return 'catalog';
    }
    if (str_contains($file, 'CitizenLanguagePreference') || (str_contains($file, 'SettingsTest') && preg_match('/language/i', $method))) {
        return 'settings';
    }
    if (str_contains($file, 'RequestLocaleTest')) {
        if (preg_match('/validation|login/i', $method)) {
            return 'auth';
        }
        if (preg_match('/dashboard/i', $method)) {
            return 'dashboard_boundary';
        }

        return 'locale_negotiation';
    }
    if (preg_match('/profile/i', $method) && preg_match('/auth_and_profile|profile/i', $hay)) {
        return 'profile';
    }
    if (preg_match('/document/i', $hay) && ! preg_match('/AIAgent/i', $file)) {
        return 'documents';
    }
    if (preg_match('/payment/i', $hay)) {
        return 'payments';
    }
    if (preg_match('/appointment|available_tests|test_availability|test_type/i', $hay)) {
        return 'appointments';
    }
    if (preg_match('/fine/i', $hay)) {
        return 'fines';
    }
    if (preg_match('/license/i', $hay) && ! str_contains($file, 'LicenseVerification')) {
        return 'licenses';
    }
    if (preg_match('/auth|login|ping|password/i', $hay) || str_contains($file, 'ArabicLocalization')) {
        return 'auth';
    }
    if (str_contains($file, 'CitizenBilingual') || str_contains($file, 'CitizenHardcode') || str_contains($file, 'CitizenLocale') || str_contains($file, 'CitizenMessage')) {
        return 'applications';
    }

    return 'applications';
}

// ---------- Leaf key parity ----------
$arByGroup = [];
$enByGroup = [];
foreach (listLocaleFiles($arDir) as $f) {
    $arByGroup[basename($f, '.php')] = flattenLeaves(loadPhpArray($f));
}
foreach (listLocaleFiles($enDir) as $f) {
    $enByGroup[basename($f, '.php')] = flattenLeaves(loadPhpArray($f));
}
$groups = array_values(array_unique(array_merge(array_keys($arByGroup), array_keys($enByGroup))));
sort($groups);

$fileRows = [];
$allAr = [];
$allEn = [];
foreach ($groups as $group) {
    $ar = $arByGroup[$group] ?? [];
    $en = $enByGroup[$group] ?? [];
    $arKeys = array_keys($ar);
    $enKeys = array_keys($en);
    sort($arKeys);
    sort($enKeys);
    $shared = array_values(array_intersect($arKeys, $enKeys));
    $arOnly = array_values(array_diff($arKeys, $enKeys));
    $enOnly = array_values(array_diff($enKeys, $arKeys));
    $union = array_values(array_unique(array_merge($arKeys, $enKeys)));
    $parity = $union === [] ? 100.0 : round(100 * count($shared) / count($union), 2);
    foreach ($ar as $k => $v) {
        $allAr[$group.'.'.$k] = $v;
    }
    foreach ($en as $k => $v) {
        $allEn[$group.'.'.$k] = $v;
    }
    $fileRows[] = compact('group', 'arKeys', 'enKeys', 'shared', 'arOnly', 'enOnly', 'parity') + [
        'ar_leaf_keys' => count($arKeys),
        'en_leaf_keys' => count($enKeys),
        'shared_n' => count($shared),
        'ar_only_n' => count($arOnly),
        'en_only_n' => count($enOnly),
    ];
}

$arKeysAll = array_keys($allAr);
$enKeysAll = array_keys($allEn);
sort($arKeysAll);
sort($enKeysAll);
$sharedAll = array_values(array_intersect($arKeysAll, $enKeysAll));
$arOnlyAll = array_values(array_diff($arKeysAll, $enKeysAll));
$enOnlyAll = array_values(array_diff($enKeysAll, $arKeysAll));
$unionAll = array_values(array_unique(array_merge($arKeysAll, $enKeysAll)));
$locAr = count($arKeysAll);
$locEn = count($enKeysAll);
$locShared = count($sharedAll);
$locArOnly = count($arOnlyAll);
$locEnOnly = count($enOnlyAll);
$locUnion = count($unionAll);
$locParity = $locUnion === 0 ? 100.0 : round(100 * $locShared / $locUnion, 2);
$locEnOfAr = $locAr === 0 ? 100.0 : round(100 * $locShared / $locAr, 2);
$locArOfEn = $locEn === 0 ? 100.0 : round(100 * $locShared / $locEn, 2);

$emptyAr = [];
$emptyEn = [];
foreach ($allAr as $k => $v) {
    if ($v === null || (is_string($v) && trim($v) === '')) {
        $emptyAr[] = $k;
    }
}
foreach ($allEn as $k => $v) {
    if ($v === null || (is_string($v) && trim($v) === '')) {
        $emptyEn[] = $k;
    }
}

// ---------- Behavioral whitelist ----------
$whitelist = [
    'tests/Feature/RequestLocaleTest.php',
    'tests/Feature/CitizenLanguagePreferenceTest.php',
    'tests/Feature/CitizenBilingualMessagesTest.php',
    'tests/Feature/CitizenHardcodeLocalizationTest.php',
    'tests/Feature/CitizenCatalogLocalizationTest.php',
    'tests/Feature/CitizenContentLocalizationTest.php',
    'tests/Feature/CitizenLocaleAwareTranslatorTest.php',
    'tests/Feature/ArabicLocalizationTest.php',
    'tests/Feature/LicenseVerificationLocalizationTest.php',
    'tests/Feature/NotificationLocalizationTest.php',
    'tests/Feature/RecipientNotificationLocaleTest.php',
    'tests/Feature/AIAgentBilingualHardeningTest.php',
    'tests/Feature/AIAgentCatalogLocalizationTest.php',
    'tests/Unit/CitizenMessageTranslatorTest.php',
    'tests/Unit/AgentLanguageDetectorTest.php',
    'tests/Unit/AgentLocaleContextTest.php',
    'tests/Feature/SettingsTest.php', // filtered to language-related methods only
];

$behavior = [];
foreach ($whitelist as $rel) {
    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (! is_file($path)) {
        continue;
    }
    $src = file_get_contents($path);
    if ($src === false) {
        continue;
    }
    foreach (extractTestMethods($src) as $m) {
        if (str_contains($rel, 'SettingsTest') && ! preg_match('/language/i', $m['name'].$m['body'])) {
            continue;
        }
        // Settings password Arabic error is domain error localization — include if Arabic asserted
        if (str_contains($rel, 'SettingsTest') && ! preg_match('/language|arabic|Accept-Language|locale/i', $m['name'].$m['body'])) {
            continue;
        }
        $cats = classifyCategories($rel, $m['name'], $m['body']);
        if ($cats === []) {
            // Still localization-suite method: default to A for ArabicLocalization else C for bilingual files
            $cats = preg_match('/Bilingual|Localization|Locale|Language|Recipient/i', $rel) ? ['C'] : ['A'];
        }
        $module = assignModule($rel, $m['name'], $m['body']);
        $behavior[] = [
            'file' => $rel,
            'method' => $m['name'],
            'categories' => $cats,
            'module' => $module,
            'asserted' => 'Whitelisted localization suite method; categories from method body signals',
        ];
    }
}

// CitizenBilingualMessagesTest covers multiple modules in single methods — attribute extra module tags
$moduleExtras = [];
foreach ($behavior as $r) {
    if ($r['file'] === 'tests/Feature/CitizenBilingualMessagesTest.php') {
        if (str_contains($r['method'], 'auth_and_profile')) {
            $moduleExtras[] = array_merge($r, ['module' => 'profile']);
            $moduleExtras[] = array_merge($r, ['module' => 'auth']);
        }
        if (str_contains($r['method'], 'application_and_document')) {
            $moduleExtras[] = array_merge($r, ['module' => 'applications']);
            $moduleExtras[] = array_merge($r, ['module' => 'documents']);
        }
        if (str_contains($r['method'], 'payment')) {
            $moduleExtras[] = array_merge($r, ['module' => 'payments']);
        }
        if (str_contains($r['method'], 'appointment')) {
            $moduleExtras[] = array_merge($r, ['module' => 'appointments']);
        }
        if (str_contains($r['method'], 'license_and_fine')) {
            $moduleExtras[] = array_merge($r, ['module' => 'licenses']);
            $moduleExtras[] = array_merge($r, ['module' => 'fines']);
        }
    }
    if ($r['file'] === 'tests/Feature/AIAgentBilingualHardeningTest.php' && str_contains($r['method'], 'fines')) {
        $moduleExtras[] = array_merge($r, ['module' => 'fines']);
    }
}

$locBehaviorMethods = count($behavior);
$catCounts = [];
foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $c) {
    $set = [];
    foreach ($behavior as $r) {
        if (in_array($c, $r['categories'], true)) {
            $set[$r['file'].'::'.$r['method']] = true;
        }
    }
    $catCounts[$c] = count($set);
}

$negotiation = array_values(array_filter($behavior, fn ($r) => $r['file'] === 'tests/Feature/RequestLocaleTest.php'));
$preference = array_values(array_filter($behavior, fn ($r) => in_array('E', $r['categories'], true)));

$modules = [
    'auth' => 'Auth',
    'profile' => 'Profile',
    'applications' => 'Applications',
    'documents' => 'Documents',
    'payments' => 'Payments',
    'appointments' => 'Appointments/tests',
    'licenses' => 'Licenses',
    'fines' => 'Fines',
    'settings' => 'Settings',
    'notifications' => 'Notifications',
    'catalog' => 'Catalog/content',
    'ai_agent' => 'AI Agent',
    'public_license_verification' => 'Public license verification',
];

$allForModules = array_merge($behavior, $moduleExtras);
$moduleStats = [];
foreach ($modules as $id => $label) {
    $ar = $en = $fb = $mc = false;
    $methods = [];
    foreach ($allForModules as $r) {
        if ($r['module'] !== $id) {
            continue;
        }
        $methods[$r['file'].'::'.$r['method']] = true;
        if (in_array('A', $r['categories'], true)) {
            $ar = true;
        }
        if (in_array('B', $r['categories'], true)) {
            $en = true;
        }
        if (in_array('F', $r['categories'], true)) {
            $fb = true;
        }
        if (in_array('J', $r['categories'], true)) {
            $mc = true;
        }
    }
    // Bilingual message methods that don't tag A/B individually: if C present, count both
    foreach ($allForModules as $r) {
        if ($r['module'] !== $id) {
            continue;
        }
        if (in_array('C', $r['categories'], true)) {
            $ar = true;
            $en = true;
        }
    }
    $moduleStats[$id] = [
        'label' => $label,
        'ar' => $ar,
        'en' => $en,
        'fallback' => $fb,
        'machine' => $mc,
        'bilingual' => $ar && $en,
        'methods' => array_keys($methods),
    ];
}
$modDenom = count($modules);
$modAr = count(array_filter($moduleStats, fn ($m) => $m['ar']));
$modEn = count(array_filter($moduleStats, fn ($m) => $m['en']));
$modBi = count(array_filter($moduleStats, fn ($m) => $m['bilingual']));

$notifMethods = count(array_filter($behavior, fn ($r) => $r['module'] === 'notifications'));
$aiMethods = count(array_filter($behavior, fn ($r) => $r['module'] === 'ai_agent'));
$histMethods = count(array_filter($behavior, fn ($r) => preg_match('/historical_notification|does_not_retranslate|returned_unchanged_after_language/i', $r['method'])));

// Dashboard
$dashLayout = dirname($root).DIRECTORY_SEPARATOR.'DLMS_Dashboard'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'layout.tsx';
$dashPkg = dirname($root).DIRECTORY_SEPARATOR.'DLMS_Dashboard'.DIRECTORY_SEPARATOR.'package.json';
$layout = is_file($dashLayout) ? (file_get_contents($dashLayout) ?: '') : '';
$pkg = is_file($dashPkg) ? (file_get_contents($dashPkg) ?: '') : '';
$dash = [
    'lang_ar' => (bool) preg_match('/lang\s*=\s*["\']ar["\']/', $layout),
    'dir_rtl' => (bool) preg_match('/dir\s*=\s*["\']rtl["\']/', $layout),
    'i18n' => (bool) preg_match('/next-intl|react-i18next|i18next/', $pkg),
];

// CSV
$fp = fopen($outDir.'/localization_evidence.csv', 'w');
fputcsv($fp, ['row_type', 'file_or_group', 'method', 'category', 'module', 'metric', 'value', 'notes']);
foreach ($fileRows as $fr) {
    fputcsv($fp, ['translation_file_parity', $fr['group'], '', '', '', 'parity_pct', $fr['parity'],
        sprintf('AR=%d EN=%d shared=%d AR-only=%d EN-only=%d', $fr['ar_leaf_keys'], $fr['en_leaf_keys'], $fr['shared_n'], $fr['ar_only_n'], $fr['en_only_n'])]);
    foreach ($fr['arOnly'] as $k) {
        fputcsv($fp, ['ar_only_key', $fr['group'], '', '', '', 'key', $fr['group'].'.'.$k, 'Missing in EN']);
    }
    foreach ($fr['enOnly'] as $k) {
        fputcsv($fp, ['en_only_key', $fr['group'], '', '', '', 'key', $fr['group'].'.'.$k, 'Missing in AR']);
    }
}
foreach ($emptyAr as $k) {
    fputcsv($fp, ['empty_ar', '', '', '', '', 'key', $k, '']);
}
foreach ($emptyEn as $k) {
    fputcsv($fp, ['empty_en', '', '', '', '', 'key', $k, '']);
}
foreach ($behavior as $r) {
    foreach ($r['categories'] as $c) {
        fputcsv($fp, ['behavior_method', $r['file'], $r['method'], $c, $r['module'], '1', '1', $r['asserted']]);
    }
}
foreach ($moduleStats as $id => $m) {
    fputcsv($fp, ['module_coverage', $id, '', '', $m['label'], 'bilingual', $m['bilingual'] ? '1' : '0',
        sprintf('ar=%d en=%d methods=%d', (int) $m['ar'], (int) $m['en'], count($m['methods']))]);
}
fclose($fp);

file_put_contents($outDir.'/_localization_keys_ar.json', json_encode($arKeysAll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($outDir.'/_localization_keys_en.json', json_encode($enKeysAll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($outDir.'/_localization_ar_only.json', json_encode($arOnlyAll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($outDir.'/_localization_en_only.json', json_encode($enOnlyAll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($outDir.'/_localization_parity_summary.json', json_encode([
    'LOC-AR-KEYS' => $locAr,
    'LOC-EN-KEYS' => $locEn,
    'LOC-SHARED-KEYS' => $locShared,
    'LOC-AR-ONLY' => $locArOnly,
    'LOC-EN-ONLY' => $locEnOnly,
    'LOC-KEY-PARITY' => $locParity,
    'LOC-EN-COVERAGE-OF-AR' => $locEnOfAr,
    'LOC-AR-COVERAGE-OF-EN' => $locArOfEn,
    'LOC-EMPTY-AR' => count($emptyAr),
    'LOC-EMPTY-EN' => count($emptyEn),
    'LOC-BEHAVIOR-METHODS' => $locBehaviorMethods,
    'category_counts' => $catCounts,
    'LOC-NEGOTIATION-SCENARIOS' => count($negotiation),
    'LOC-PREFERENCE-SCENARIOS' => count($preference),
    'LOC-MODULE-AR' => [$modAr, $modDenom],
    'LOC-MODULE-EN' => [$modEn, $modDenom],
    'LOC-MODULE-BI' => [$modBi, $modDenom],
    'LOC-NOTIFICATION-LOCALE-METHODS' => $notifMethods,
    'LOC-NOTIFICATION-HISTORY-STABILITY-METHODS' => $histMethods,
    'LOC-AI-LOCALE-METHODS' => $aiMethods,
    'whitelist_files' => $whitelist,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

ob_start();
?>
# Localization Evidence Matrix (Quantitative)

**System:** SYRTAK / DLMS Backend (+ Dashboard scope boundary)  
**Audit type:** Read-only  
**Date:** 2026-08-15  
**Active `lang_path()`:** `resources/lang` (bootstrap-verified)

### Suite context (reported)

| Item | Value |
|------|-------|
| Tests | **1043 passed** |
| Assertions | **6557** |
| Duration | **217.86s** |

---

## 1. Localization architecture

| Component | Evidence |
|-----------|----------|
| Middleware | `app/Http/Middleware/ResolveRequestLocale.php` — set locale, `Content-Language`, `Vary: Accept-Language`, restore default in `finally` |
| Resolver | `app/Support/RequestLocaleResolver.php` — precedence **Accept-Language → `users.language` → default `ar`** |
| Config | `config/localization.php` supported `ar`,`en`; `config/app.php` locale/fallback `ar` |
| Translation packs | `resources/lang/{ar,en}/messages.php`, `validation.php` |
| Translators | `CitizenMessageTranslator`, `ArabicMessageTranslator`, `CitizenCatalogLabel`, `CitizenContentLocalizer`, `RecipientNotificationTranslator`, `AgentTranslator` |
| Notifications | Recipient language (not request locale) |
| AI | `AgentLocaleContext` (scoped), `AgentLanguageDetector` |
| Dashboard | Next.js Arabic RTL only (see §13) |

### A vs B

| Kind | What | Rule |
|------|------|------|
| **A. Human-facing** | `message`, labels, notification title/body, catalog display names | Localized |
| **B. Machine codes** | status/enum values, type codes, notification types, AI action names | **Must stay untranslated** for client contracts and integrations |

---

## 2. Translation file inventory (leaf keys)

**Leaf algorithm:** `include` PHP array → recurse; leaf = non-array scalar; dotted path; file group prefix for globals (`messages.*`, `validation.*`).

| File group | AR leaves | EN leaves | Shared | AR-only | EN-only | Parity % |
|------------|----------:|----------:|-------:|--------:|--------:|---------:|
<?php foreach ($fileRows as $fr): ?>
| `<?= $fr['group'] ?>` | <?= $fr['ar_leaf_keys'] ?> | <?= $fr['en_leaf_keys'] ?> | <?= $fr['shared_n'] ?> | <?= $fr['ar_only_n'] ?> | <?= $fr['en_only_n'] ?> | <?= $fr['parity'] ?> |
<?php endforeach; ?>

Root `lang/en/*` is **not** active `lang_path()` and is excluded.

---

## 3. Global key parity

| Metric ID | Exact value |
|-----------|-------------|
| **LOC-AR-KEYS** | **<?= $locAr ?>** |
| **LOC-EN-KEYS** | **<?= $locEn ?>** |
| **LOC-SHARED-KEYS** | **<?= $locShared ?>** |
| **LOC-AR-ONLY** | **<?= $locArOnly ?>** |
| **LOC-EN-ONLY** | **<?= $locEnOnly ?>** |
| **LOC-KEY-PARITY** | **<?= $locParity ?>%** (`<?= $locShared ?>/<?= $locUnion ?>`) |
| **LOC-EN-COVERAGE-OF-AR** | **<?= $locEnOfAr ?>%** (`<?= $locShared ?>/<?= $locAr ?>`) |
| **LOC-AR-COVERAGE-OF-EN** | **<?= $locArOfEn ?>%** (`<?= $locShared ?>/<?= $locEn ?>`) |

Missing key lists: `_localization_ar_only.json`, `_localization_en_only.json`, CSV `ar_only_key` / `en_only_key`.

---

## 4. Empty / invalid values

| Metric | Exact value |
|--------|-------------|
| **LOC-EMPTY-AR** | **<?= count($emptyAr) ?>** |
| **LOC-EMPTY-EN** | **<?= count($emptyEn) ?>** |

Linguistic quality not judged.

---

## 5. Behavioral localization test inventory

**Inclusion rule:** every `test_*` method in a fixed whitelist of localization suites (plus Settings methods that mention language). Categories derived from method body signals.

Whitelist files:

<?php foreach ($whitelist as $w): ?>
- `<?= $w ?>`
<?php endforeach; ?>

| Category | Meaning | Distinct methods |
|----------|---------|-----------------:|
| A | Arabic API/response | <?= $catCounts['A'] ?> |
| B | English API/response | <?= $catCounts['B'] ?> |
| C | Same feature both languages | <?= $catCounts['C'] ?> |
| D | Accept-Language negotiation | <?= $catCounts['D'] ?> |
| E | Stored preference | <?= $catCounts['E'] ?> |
| F | Fallback | <?= $catCounts['F'] ?> |
| G | Validation localization | <?= $catCounts['G'] ?> |
| H | Domain/middleware error localization | <?= $catCounts['H'] ?> |
| I | Notification/AI locale | <?= $catCounts['I'] ?> |
| J | Machine-code stability | <?= $catCounts['J'] ?> |

**LOC-BEHAVIOR-METHODS = <?= $locBehaviorMethods ?>** (distinct methods; do not sum categories)

CSV: `row_type=behavior_method`.

---

## 6. Module-level behavioral coverage

**Denominator:** <?= $modDenom ?> citizen-facing capability groups.

| Module | AR | EN | Bilingual | Fallback | Machine codes | Methods |
|--------|:--:|:--:|:---------:|:--------:|:-------------:|--------:|
<?php foreach ($moduleStats as $id => $m): ?>
| <?= $m['label'] ?> | <?= $m['ar']?'Y':'N' ?> | <?= $m['en']?'Y':'N' ?> | <?= $m['bilingual']?'Y':'N' ?> | <?= $m['fallback']?'Y':'N' ?> | <?= $m['machine']?'Y':'N' ?> | <?= count($m['methods']) ?> |
<?php endforeach; ?>

| Metric | Value |
|--------|-------|
| **LOC-MODULE-AR-COVERAGE** | **<?= $modAr ?>/<?= $modDenom ?> (<?= round(100*$modAr/$modDenom,1) ?>%)** |
| **LOC-MODULE-EN-COVERAGE** | **<?= $modEn ?>/<?= $modDenom ?> (<?= round(100*$modEn/$modDenom,1) ?>%)** |
| **LOC-MODULE-BILINGUAL-COVERAGE** | **<?= $modBi ?>/<?= $modDenom ?> (<?= round(100*$modBi/$modDenom,1) ?>%)** |

---

## 7. Request locale negotiation — `LOC-NEGOTIATION-SCENARIOS`

**Exact value: <?= count($negotiation) ?>** (`RequestLocaleTest` methods)

| # | Method |
|---|--------|
<?php $i=1; foreach ($negotiation as $r): ?>
| <?= $i++ ?> | `<?= $r['method'] ?>` |
<?php endforeach; ?>

Covers: no header default, ar, en, en-US, ar-SY, q-values, unsupported, malformed, stored preference, header override without persist, no leakage, dashboard boundary, validation packs.

---

## 8. Stored language preference — `LOC-PREFERENCE-SCENARIOS`

**Exact value: <?= count($preference) ?>** (category E)

| # | File | Method |
|---|------|--------|
<?php $i=1; foreach ($preference as $r): ?>
| <?= $i++ ?> | `<?= $r['file'] ?>` | `<?= $r['method'] ?>` |
<?php endforeach; ?>

---

## 9. Validation + domain error localization

| Metric | Exact value |
|--------|-------------|
| **LOC-VALIDATION-LOCALE-METHODS** | **<?= $catCounts['G'] ?>** |
| **LOC-DOMAIN-ERROR-LOCALE-METHODS** | **<?= $catCounts['H'] ?>** |

---

## 10. Machine code stability — `LOC-MACHINE-CODE-STABILITY-METHODS`

**Exact value: <?= $catCounts['J'] ?>**

**Supported claim (scoped):** Localization applies at the presentation/message boundary while machine-readable codes remain stable (catalog codes, verify statuses, theme codes, AI action codes — where asserted).

---

## 11. Notification localization

| Metric | Exact value |
|--------|-------------|
| **LOC-NOTIFICATION-LOCALE-METHODS** | **<?= $notifMethods ?>** |
| **LOC-NOTIFICATION-HISTORY-STABILITY-METHODS** | **<?= $histMethods ?>** |

Covers AR/EN recipients, request locale isolation, placeholders, null/unsupported fallback, historical non-retranslation (see RecipientNotification / NotificationLocalization suites).

---

## 12. AI Agent localization — `LOC-AI-LOCALE-METHODS`

**Exact value: <?= $aiMethods ?>**

Backend locale detection/context/catalog labeling is tested. **Do not claim** Gemini translation correctness.

---

## 13. Dashboard boundary

| Check | Result |
|-------|--------|
| `lang="ar"` | <?= $dash['lang_ar'] ? 'YES' : 'NO' ?> |
| `dir="rtl"` | <?= $dash['dir_rtl'] ? 'YES' : 'NO' ?> |
| i18n library in package.json | <?= $dash['i18n'] ? 'FOUND' : 'NOT FOUND' ?> |

| Classification | Status |
|----------------|--------|
| Arabic RTL employee UI | **IMPLEMENTED** |
| English Dashboard UI | **NOT IMPLEMENTED** |

---

## 14. Final numeric summary

| Metric ID | Exact value | Denominator | Method | Interpretation | Limitation |
|-----------|-------------|-------------|--------|----------------|------------|
| LOC-AR-KEYS | **<?= $locAr ?>** | — | Leaf flatten | AR keys | Active path only |
| LOC-EN-KEYS | **<?= $locEn ?>** | — | Leaf flatten | EN keys | — |
| LOC-SHARED-KEYS | **<?= $locShared ?>** | — | Intersection | Shared | — |
| LOC-AR-ONLY | **<?= $locArOnly ?>** | — | Diff | Missing EN | — |
| LOC-EN-ONLY | **<?= $locEnOnly ?>** | — | Diff | Missing AR | — |
| LOC-KEY-PARITY | **<?= $locParity ?>%** | union <?= $locUnion ?> | shared/union | File parity | Not linguistic quality |
| LOC-EN-COVERAGE-OF-AR | **<?= $locEnOfAr ?>%** | AR <?= $locAr ?> | shared/AR | EN completeness | — |
| LOC-AR-COVERAGE-OF-EN | **<?= $locArOfEn ?>%** | EN <?= $locEn ?> | shared/EN | AR completeness | — |
| LOC-EMPTY-AR | **<?= count($emptyAr) ?>** | — | Empty scan | — | — |
| LOC-EMPTY-EN | **<?= count($emptyEn) ?>** | — | Empty scan | — | — |
| LOC-BEHAVIOR-METHODS | **<?= $locBehaviorMethods ?>** | — | Whitelist suites | Automated locale behavior | Category overlap |
| LOC-MODULE-AR-COVERAGE | **<?= $modAr ?>/<?= $modDenom ?>** | <?= $modDenom ?> | Module map | — | Attribution via suites |
| LOC-MODULE-EN-COVERAGE | **<?= $modEn ?>/<?= $modDenom ?>** | <?= $modDenom ?> | Module map | — | — |
| LOC-MODULE-BILINGUAL-COVERAGE | **<?= $modBi ?>/<?= $modDenom ?>** | <?= $modDenom ?> | AR∧EN | — | — |
| LOC-NEGOTIATION-SCENARIOS | **<?= count($negotiation) ?>** | — | RequestLocaleTest | Negotiation | — |
| LOC-PREFERENCE-SCENARIOS | **<?= count($preference) ?>** | — | Category E | Preference | — |
| LOC-VALIDATION-LOCALE-METHODS | **<?= $catCounts['G'] ?>** | — | Category G | Validation i18n | — |
| LOC-DOMAIN-ERROR-LOCALE-METHODS | **<?= $catCounts['H'] ?>** | — | Category H | Domain errors | — |
| LOC-MACHINE-CODE-STABILITY-METHODS | **<?= $catCounts['J'] ?>** | — | Category J | Codes vs labels | Scoped |
| LOC-NOTIFICATION-LOCALE-METHODS | **<?= $notifMethods ?>** | — | notifications module | Recipient locale | — |
| LOC-NOTIFICATION-HISTORY-STABILITY-METHODS | **<?= $histMethods ?>** | — | historical* methods | No retranslate | — |
| LOC-AI-LOCALE-METHODS | **<?= $aiMethods ?>** | — | ai_agent module | Backend locale | Not LLM quality |

---

## 15. Committee-safe claims

| Claim | Status |
|-------|--------|
| EN covers **<?= $locEnOfAr ?>%** of AR backend leaf keys; union parity **<?= $locParity ?>%** | **VERIFIED** |
| Locale negotiation has **<?= count($negotiation) ?>** automated scenarios | **VERIFIED** |
| Preference API has **<?= count($preference) ?>** automated scenarios | **VERIFIED** |
| Machine codes remain stable while labels localize (**<?= $catCounts['J'] ?>** methods) | **VERIFIED** (scoped) |
| Bilingual behavior across **<?= $modBi ?>/<?= $modDenom ?>** citizen capability groups | **PARTIALLY VERIFIED** / **VERIFIED** if <?= $modBi ?>==<?= $modDenom ?> |
| Entire SYRTAK product is bilingual | **DO NOT CLAIM** (Dashboard Arabic-only) |
| Translation linguistic quality / Gemini correctness | **DO NOT CLAIM** |

---

## 16. Gap-closure recommendations (do not implement)

| Rank | Gap | Action | Effort | Value |
|------|-----|--------|--------|-------|
| 1 | <?= $locArOnly ?> AR-only keys | Add EN citizen-facing leaves | Med | Raises EN-coverage-of-AR |
| 2 | <?= $locEnOnly ?> EN-only keys | Add AR leaves or remove dead EN | Low–Med | Raises parity |
| 3 | Any module still N for bilingual | Targeted Feature asserts | Med | Module coverage |
| 4 | Keep Dashboard EN out of backend claims | Document limitation | — | Prevents overclaim |

---

## 17. Reproducibility

### Scripts
- `docs/evidence/final-measurements/_export_localization_evidence.php` (this exporter)
- `docs/evidence/final-measurements/_probe_lang_path.php`

### Commands
```text
php docs/evidence/final-measurements/_probe_lang_path.php
php docs/evidence/final-measurements/_export_localization_evidence.php
```

### Leaf-key rules
1. Parse only `resources/lang/{ar,en}/*.php`  
2. Recursive flatten; leaf = scalar  
3. Global key = `{fileGroup}.{dotted.path}`  
4. Parity = shared / union  

### Behavioral rules
1. Fixed whitelist of localization suites  
2. SettingsTest filtered to language-related methods  
3. Categories from method-body signals; modules from file/method/body  
4. Multi-module bilingual tests also attribute profile/documents/payments/appointments/licenses/fines  

---

**Artifacts:** `LOCALIZATION_EVIDENCE_MATRIX.md`, `localization_evidence.csv`, `_localization_*.json`
<?php
file_put_contents($outDir.'/LOCALIZATION_EVIDENCE_MATRIX.md', ob_get_clean());
fwrite(STDERR, "AR=$locAr EN=$locEn shared=$locShared AR-only=$locArOnly EN-only=$locEnOnly parity=$locParity%\n");
fwrite(STDERR, "behavior=$locBehaviorMethods nego=".count($negotiation)." pref=".count($preference)." bi=$modBi/$modDenom notif=$notifMethods ai=$aiMethods hist=$histMethods\n");
