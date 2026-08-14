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
| `messages` | 828 | 577 | 577 | 251 | 0 | 69.69 |
| `validation` | 90 | 179 | 90 | 0 | 89 | 50.28 |

Root `lang/en/*` is **not** active `lang_path()` and is excluded.

---

## 3. Global key parity

| Metric ID | Exact value |
|-----------|-------------|
| **LOC-AR-KEYS** | **918** |
| **LOC-EN-KEYS** | **756** |
| **LOC-SHARED-KEYS** | **667** |
| **LOC-AR-ONLY** | **251** |
| **LOC-EN-ONLY** | **89** |
| **LOC-KEY-PARITY** | **66.24%** (`667/1007`) |
| **LOC-EN-COVERAGE-OF-AR** | **72.66%** (`667/918`) |
| **LOC-AR-COVERAGE-OF-EN** | **88.23%** (`667/756`) |

Missing key lists: `_localization_ar_only.json`, `_localization_en_only.json`, CSV `ar_only_key` / `en_only_key`.

---

## 4. Empty / invalid values

| Metric | Exact value |
|--------|-------------|
| **LOC-EMPTY-AR** | **0** |
| **LOC-EMPTY-EN** | **0** |

Linguistic quality not judged.

---

## 5. Behavioral localization test inventory

**Inclusion rule:** every `test_*` method in a fixed whitelist of localization suites (plus Settings methods that mention language). Categories derived from method body signals.

Whitelist files:

- `tests/Feature/RequestLocaleTest.php`
- `tests/Feature/CitizenLanguagePreferenceTest.php`
- `tests/Feature/CitizenBilingualMessagesTest.php`
- `tests/Feature/CitizenHardcodeLocalizationTest.php`
- `tests/Feature/CitizenCatalogLocalizationTest.php`
- `tests/Feature/CitizenContentLocalizationTest.php`
- `tests/Feature/CitizenLocaleAwareTranslatorTest.php`
- `tests/Feature/ArabicLocalizationTest.php`
- `tests/Feature/LicenseVerificationLocalizationTest.php`
- `tests/Feature/NotificationLocalizationTest.php`
- `tests/Feature/RecipientNotificationLocaleTest.php`
- `tests/Feature/AIAgentBilingualHardeningTest.php`
- `tests/Feature/AIAgentCatalogLocalizationTest.php`
- `tests/Unit/CitizenMessageTranslatorTest.php`
- `tests/Unit/AgentLanguageDetectorTest.php`
- `tests/Unit/AgentLocaleContextTest.php`
- `tests/Feature/SettingsTest.php`

| Category | Meaning | Distinct methods |
|----------|---------|-----------------:|
| A | Arabic API/response | 93 |
| B | English API/response | 87 |
| C | Same feature both languages | 28 |
| D | Accept-Language negotiation | 55 |
| E | Stored preference | 12 |
| F | Fallback | 15 |
| G | Validation localization | 7 |
| H | Domain/middleware error localization | 5 |
| I | Notification/AI locale | 55 |
| J | Machine-code stability | 30 |

**LOC-BEHAVIOR-METHODS = 130** (distinct methods; do not sum categories)

CSV: `row_type=behavior_method`.

---

## 6. Module-level behavioral coverage

**Denominator:** 13 citizen-facing capability groups.

| Module | AR | EN | Bilingual | Fallback | Machine codes | Methods |
|--------|:--:|:--:|:---------:|:--------:|:-------------:|--------:|
| Auth | Y | Y | Y | N | N | 13 |
| Profile | Y | Y | Y | N | N | 1 |
| Applications | Y | Y | Y | Y | Y | 8 |
| Documents | Y | Y | Y | N | Y | 1 |
| Payments | Y | Y | Y | N | N | 1 |
| Appointments/tests | Y | Y | Y | N | Y | 5 |
| Licenses | Y | Y | Y | N | Y | 6 |
| Fines | Y | Y | Y | N | Y | 2 |
| Settings | Y | Y | Y | N | N | 9 |
| Notifications | Y | Y | Y | Y | Y | 12 |
| Catalog/content | Y | Y | Y | Y | Y | 14 |
| AI Agent | Y | Y | Y | Y | Y | 43 |
| Public license verification | Y | Y | Y | N | Y | 5 |

| Metric | Value |
|--------|-------|
| **LOC-MODULE-AR-COVERAGE** | **13/13 (100%)** |
| **LOC-MODULE-EN-COVERAGE** | **13/13 (100%)** |
| **LOC-MODULE-BILINGUAL-COVERAGE** | **13/13 (100%)** |

---

## 7. Request locale negotiation — `LOC-NEGOTIATION-SCENARIOS`

**Exact value: 17** (`RequestLocaleTest` methods)

| # | Method |
|---|--------|
| 1 | `test_guest_without_header_resolves_ar` |
| 2 | `test_guest_accept_language_ar_resolves_ar` |
| 3 | `test_guest_accept_language_en_resolves_en` |
| 4 | `test_en_us_normalizes_to_en` |
| 5 | `test_ar_sy_normalizes_to_ar` |
| 6 | `test_q_value_negotiation_prefers_supported_locale` |
| 7 | `test_unsupported_header_without_user_falls_back_to_ar` |
| 8 | `test_authenticated_user_language_en_without_header_resolves_en` |
| 9 | `test_authenticated_user_language_ar_without_header_resolves_ar` |
| 10 | `test_accept_language_overrides_stored_preference_for_request_only` |
| 11 | `test_unsupported_header_does_not_override_stored_user_preference` |
| 12 | `test_malformed_accept_language_falls_back_safely` |
| 13 | `test_existing_vary_header_is_preserved_and_accept_language_appended` |
| 14 | `test_locale_does_not_leak_between_requests` |
| 15 | `test_dashboard_routes_do_not_use_citizen_locale_middleware` |
| 16 | `test_english_validation_uses_en_pack_for_guest_login` |
| 17 | `test_arabic_validation_remains_compatible_for_guest_login` |

Covers: no header default, ar, en, en-US, ar-SY, q-values, unsupported, malformed, stored preference, header override without persist, no leakage, dashboard boundary, validation packs.

---

## 8. Stored language preference — `LOC-PREFERENCE-SCENARIOS`

**Exact value: 12** (category E)

| # | File | Method |
|---|------|--------|
| 1 | `tests/Feature/RequestLocaleTest.php` | `test_accept_language_overrides_stored_preference_for_request_only` |
| 2 | `tests/Feature/RequestLocaleTest.php` | `test_unsupported_header_does_not_override_stored_user_preference` |
| 3 | `tests/Feature/CitizenLanguagePreferenceTest.php` | `test_auth_me_exposes_language` |
| 4 | `tests/Feature/CitizenLanguagePreferenceTest.php` | `test_login_user_payload_exposes_language` |
| 5 | `tests/Feature/CitizenLanguagePreferenceTest.php` | `test_put_language_en_persists_without_rotating_token` |
| 6 | `tests/Feature/CitizenLanguagePreferenceTest.php` | `test_put_language_ar_persists` |
| 7 | `tests/Feature/CitizenLanguagePreferenceTest.php` | `test_invalid_stored_language_remains_impossible_through_settings_api` |
| 8 | `tests/Feature/CitizenLanguagePreferenceTest.php` | `test_accept_language_does_not_persist_preference` |
| 9 | `tests/Feature/CitizenLanguagePreferenceTest.php` | `test_citizen_authorization_unchanged_for_settings` |
| 10 | `tests/Feature/SettingsTest.php` | `test_authenticated_citizen_can_get_settings_with_account_and_preferences` |
| 11 | `tests/Feature/SettingsTest.php` | `test_citizen_can_update_language_and_theme` |
| 12 | `tests/Feature/SettingsTest.php` | `test_invalid_theme_or_language_returns_validation_error` |

---

## 9. Validation + domain error localization

| Metric | Exact value |
|--------|-------------|
| **LOC-VALIDATION-LOCALE-METHODS** | **7** |
| **LOC-DOMAIN-ERROR-LOCALE-METHODS** | **5** |

---

## 10. Machine code stability — `LOC-MACHINE-CODE-STABILITY-METHODS`

**Exact value: 30**

**Supported claim (scoped):** Localization applies at the presentation/message boundary while machine-readable codes remain stable (catalog codes, verify statuses, theme codes, AI action codes — where asserted).

---

## 11. Notification localization

| Metric | Exact value |
|--------|-------------|
| **LOC-NOTIFICATION-LOCALE-METHODS** | **12** |
| **LOC-NOTIFICATION-HISTORY-STABILITY-METHODS** | **2** |

Covers AR/EN recipients, request locale isolation, placeholders, null/unsupported fallback, historical non-retranslation (see RecipientNotification / NotificationLocalization suites).

---

## 12. AI Agent localization — `LOC-AI-LOCALE-METHODS`

**Exact value: 43**

Backend locale detection/context/catalog labeling is tested. **Do not claim** Gemini translation correctness.

---

## 13. Dashboard boundary

| Check | Result |
|-------|--------|
| `lang="ar"` | YES |
| `dir="rtl"` | YES |
| i18n library in package.json | NOT FOUND |

| Classification | Status |
|----------------|--------|
| Arabic RTL employee UI | **IMPLEMENTED** |
| English Dashboard UI | **NOT IMPLEMENTED** |

---

## 14. Final numeric summary

| Metric ID | Exact value | Denominator | Method | Interpretation | Limitation |
|-----------|-------------|-------------|--------|----------------|------------|
| LOC-AR-KEYS | **918** | — | Leaf flatten | AR keys | Active path only |
| LOC-EN-KEYS | **756** | — | Leaf flatten | EN keys | — |
| LOC-SHARED-KEYS | **667** | — | Intersection | Shared | — |
| LOC-AR-ONLY | **251** | — | Diff | Missing EN | — |
| LOC-EN-ONLY | **89** | — | Diff | Missing AR | — |
| LOC-KEY-PARITY | **66.24%** | union 1007 | shared/union | File parity | Not linguistic quality |
| LOC-EN-COVERAGE-OF-AR | **72.66%** | AR 918 | shared/AR | EN completeness | — |
| LOC-AR-COVERAGE-OF-EN | **88.23%** | EN 756 | shared/EN | AR completeness | — |
| LOC-EMPTY-AR | **0** | — | Empty scan | — | — |
| LOC-EMPTY-EN | **0** | — | Empty scan | — | — |
| LOC-BEHAVIOR-METHODS | **130** | — | Whitelist suites | Automated locale behavior | Category overlap |
| LOC-MODULE-AR-COVERAGE | **13/13** | 13 | Module map | — | Attribution via suites |
| LOC-MODULE-EN-COVERAGE | **13/13** | 13 | Module map | — | — |
| LOC-MODULE-BILINGUAL-COVERAGE | **13/13** | 13 | AR∧EN | — | — |
| LOC-NEGOTIATION-SCENARIOS | **17** | — | RequestLocaleTest | Negotiation | — |
| LOC-PREFERENCE-SCENARIOS | **12** | — | Category E | Preference | — |
| LOC-VALIDATION-LOCALE-METHODS | **7** | — | Category G | Validation i18n | — |
| LOC-DOMAIN-ERROR-LOCALE-METHODS | **5** | — | Category H | Domain errors | — |
| LOC-MACHINE-CODE-STABILITY-METHODS | **30** | — | Category J | Codes vs labels | Scoped |
| LOC-NOTIFICATION-LOCALE-METHODS | **12** | — | notifications module | Recipient locale | — |
| LOC-NOTIFICATION-HISTORY-STABILITY-METHODS | **2** | — | historical* methods | No retranslate | — |
| LOC-AI-LOCALE-METHODS | **43** | — | ai_agent module | Backend locale | Not LLM quality |

---

## 15. Committee-safe claims

| Claim | Status |
|-------|--------|
| EN covers **72.66%** of AR backend leaf keys; union parity **66.24%** | **VERIFIED** |
| Locale negotiation has **17** automated scenarios | **VERIFIED** |
| Preference API has **12** automated scenarios | **VERIFIED** |
| Machine codes remain stable while labels localize (**30** methods) | **VERIFIED** (scoped) |
| Bilingual behavior across **13/13** citizen capability groups | **PARTIALLY VERIFIED** / **VERIFIED** if 13==13 |
| Entire SYRTAK product is bilingual | **DO NOT CLAIM** (Dashboard Arabic-only) |
| Translation linguistic quality / Gemini correctness | **DO NOT CLAIM** |

---

## 16. Gap-closure recommendations (do not implement)

| Rank | Gap | Action | Effort | Value |
|------|-----|--------|--------|-------|
| 1 | 251 AR-only keys | Add EN citizen-facing leaves | Med | Raises EN-coverage-of-AR |
| 2 | 89 EN-only keys | Add AR leaves or remove dead EN | Low–Med | Raises parity |
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
