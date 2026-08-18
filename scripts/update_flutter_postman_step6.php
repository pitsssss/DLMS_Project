<?php

declare(strict_types=1);

$path = dirname(__DIR__).'/postman/SYRTAK_Flutter_API.postman_collection.json';
$json = file_get_contents($path);
$data = json_decode($json, true);
if (! is_array($data)) {
    fwrite(STDERR, "Invalid JSON: ".json_last_error_msg().PHP_EOL);
    exit(1);
}

function citizenHeaders(bool $withJsonBody = false): array
{
    $headers = [
        ['key' => 'Accept', 'value' => 'application/json', 'type' => 'text'],
        ['key' => 'Accept-Language', 'value' => '{{app_language}}', 'type' => 'text'],
        ['key' => 'Authorization', 'value' => 'Bearer {{citizen_token}}', 'type' => 'text'],
    ];
    if ($withJsonBody) {
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'];
    }

    return $headers;
}

function baseTests(): array
{
    return [
        'function safeJson() {',
        '  try { return pm.response.json(); } catch (e) { return null; }',
        '}',
        'function setEnv(key, value) {',
        '  if (value === undefined || value === null || value === \'\') return;',
        '  pm.environment.set(key, String(value));',
        '}',
        'function firstArray(data) {',
        '  if (Array.isArray(data)) return data;',
        '  if (data && Array.isArray(data.items)) return data.items;',
        '  if (data && Array.isArray(data.data)) return data.data;',
        '  if (data && Array.isArray(data.tests)) return data.tests;',
        '  return [];',
        '}',
        'function pickByCode(arr, preferredCodes) {',
        '  if (!Array.isArray(arr) || !arr.length) return null;',
        '  if (preferredCodes && preferredCodes.length) {',
        '    for (const code of preferredCodes) {',
        '      const hit = arr.find(x => x && String(x.code) === String(code));',
        '      if (hit) return hit;',
        '    }',
        '  }',
        '  return arr[0];',
        '}',
        'pm.test(\'Status is successful\', function () {',
        '  pm.expect(pm.response.code).to.be.oneOf([200, 201]);',
        '});',
        'pm.test(\'Response is JSON\', function () {',
        '  pm.response.to.be.json;',
        '});',
        'pm.test(\'success === true when present\', function () {',
        '  const j = pm.response.json();',
        '  if (Object.prototype.hasOwnProperty.call(j, \'success\')) {',
        '    pm.expect(j.success).to.eql(true);',
        '  }',
        '});',
        '',
    ];
}

function requestItem(string $name, array $request, array $extraExec = []): array
{
    return [
        'name' => $name,
        'request' => $request,
        'response' => [],
        'event' => [
            [
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => array_merge(baseTests(), $extraExec),
                ],
            ],
        ],
    ];
}

$getMyFinesScript = [
    'const j = safeJson();',
    'if (!j || !j.success) return;',
    'const arr = firstArray(j.data);',
    'const unpaid = arr.find(x => x && String(x.status) === \'unpaid\' && x.is_payable !== false);',
    'const chosen = unpaid || arr[0];',
    'if (chosen) setEnv(\'fine_id\', chosen.id);',
    '',
];

$finesFolder = [
    'name' => '09 - Fines',
    'description' => "Citizen Fine Payment flow (Flutter).\n\nRecommended order:\n1. Get My Fines — pick unpaid Fine (auto-stores fine_id preferring status=unpaid)\n2. Get Fine Detail — confirm amount/currency/status/is_payable\n3. Pay Fine — empty body; backend copies Fine amount/currency. Do NOT send amount/currency.\n4. If PAYMENT_PROVIDER=stripe → open checkout_url in a browser, complete Stripe Checkout, then poll status.\n5. Get Fine Payment Status — backend-authoritative (webhook/reconcile). Never treat browser success page as proof.\n6. Re-run Get Fine Detail — expect status=paid and paid_at set.\n7. Get My Payments (folder 09b) — Fine payment appears in history.\n\nMock-only path:\nPay Fine → Confirm Fine Payment [Mock Only] → Get Fine Payment Status\n\nDo NOT call Mock Confirm when PAYMENT_PROVIDER=stripe / production.",
    'item' => [
        requestItem(
            'Get My Fines',
            [
                'method' => 'GET',
                'header' => citizenHeaders(),
                'url' => [
                    'raw' => '{{base_url}}/api/fines',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'fines'],
                ],
                'description' => "Purpose:\nCitizen views all own fines.\n\nFlutter usage:\nMy Fines screen.\n\nStores automatically:\nfine_id (prefers unpaid / is_payable when present)\n\nNext:\nGet Fine Detail",
            ],
            $getMyFinesScript
        ),
        requestItem(
            'Get Fine Detail',
            [
                'method' => 'GET',
                'header' => citizenHeaders(),
                'url' => [
                    'raw' => '{{base_url}}/api/fines/{{fine_id}}',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'fines', '{{fine_id}}'],
                ],
                'description' => "Purpose:\nReturns one Fine belonging to the authenticated citizen.\nForeign Fine ownership returns 404.\n\nAuthoritative fields:\namount, currency, status, reason, paid_at, is_payable\n\nFlutter usage:\nFine Detail screen. Show Pay only when is_payable / unpaid.\n\nRequires:\nfine_id\n\nNext:\nPay Fine (when payable)",
            ],
            [
                'const j = safeJson();',
                'if (!j || !j.success) return;',
                'const data = j.data || {};',
                'pm.test(\'Fine detail has amount/currency/status\', function () {',
                '  pm.expect(data).to.have.property(\'amount\');',
                '  pm.expect(data).to.have.property(\'currency\');',
                '  pm.expect(data).to.have.property(\'status\');',
                '});',
                'if (data.id) setEnv(\'fine_id\', data.id);',
                '',
            ]
        ),
        requestItem(
            'Pay Fine',
            [
                'method' => 'POST',
                'header' => citizenHeaders(true),
                'url' => [
                    'raw' => '{{base_url}}/api/fines/{{fine_id}}/payments',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'fines', '{{fine_id}}', 'payments'],
                ],
                'description' => "Purpose:\nCreate pending Fine Payment. Amount/currency come from the Fine record — do NOT send them in the body.\n\nBody:\n{} (empty JSON)\n\nStripe (PAYMENT_PROVIDER=stripe):\nResponse shape:\n{\n  payment: { id, amount, currency, status, ... },\n  provider: \"stripe\",\n  checkout_url: \"https://checkout.stripe.com/...\",\n  publishable_key: \"pk_...\"\n}\nOpen checkout_url in a browser and complete Stripe Checkout.\nDo NOT mark payment completed from Postman — webhook/reconciliation is authoritative.\n\nMock (PAYMENT_PROVIDER=mock):\nResponse is the Payment resource directly under data (id, amount, currency, status=pending).\nThen use Confirm Fine Payment [Mock Only].\n\nStores automatically:\npayment_id, checkout_url (when Stripe)\n\nRequires:\nfine_id, citizen_token, profile.approved",
                'body' => [
                    'mode' => 'raw',
                    'raw' => '{}',
                    'options' => ['raw' => ['language' => 'json']],
                ],
            ],
            [
                'const j = safeJson();',
                'if (!j || !j.success) return;',
                'const data = j.data || {};',
                'const payment = data.payment || data;',
                'pm.test(\'Payment initiation has id/amount/currency/status\', function () {',
                '  pm.expect(payment).to.have.property(\'id\');',
                '  pm.expect(payment).to.have.property(\'amount\');',
                '  pm.expect(payment).to.have.property(\'currency\');',
                '  pm.expect(payment).to.have.property(\'status\');',
                '});',
                'setEnv(\'payment_id\', payment.id);',
                'if (data.checkout_url) {',
                '  setEnv(\'checkout_url\', data.checkout_url);',
                '  pm.test(\'Stripe checkout_url present\', function () {',
                '    pm.expect(String(data.checkout_url)).to.include(\'http\');',
                '  });',
                '}',
                '',
            ]
        ),
        requestItem(
            'Get Fine Payment Status',
            [
                'method' => 'GET',
                'header' => citizenHeaders(),
                'url' => [
                    'raw' => '{{base_url}}/api/fines/{{fine_id}}/payments/{{payment_id}}/status',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'fines', '{{fine_id}}', 'payments', '{{payment_id}}', 'status'],
                ],
                'description' => "Purpose:\nPoll backend-authoritative Fine Payment status after Stripe Checkout (or mock confirm).\n\nMachine status values (do not translate):\npending | completed | failed | under_verification\n\nFlutter must use this (or Fine Detail) after returning from the browser — never treat /payment/success as proof of payment.\n\nRequires:\nfine_id, payment_id",
            ],
            [
                'const j = safeJson();',
                'if (!j || !j.success) return;',
                'const data = j.data || {};',
                'const payment = data.payment || data;',
                'const status = payment.status || data.status;',
                'pm.test(\'Payment status is a known machine code\', function () {',
                '  pm.expect([\'pending\', \'completed\', \'failed\', \'under_verification\']).to.include(String(status));',
                '});',
                '',
            ]
        ),
        requestItem(
            'Confirm Fine Payment [Mock Only]',
            [
                'method' => 'POST',
                'header' => citizenHeaders(true),
                'url' => [
                    'raw' => '{{base_url}}/api/fines/{{fine_id}}/payments/{{payment_id}}/confirm',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'fines', '{{fine_id}}', 'payments', '{{payment_id}}', 'confirm'],
                ],
                'description' => "⚠ MOCK / LOCAL TESTING ONLY\n\nUse ONLY when PAYMENT_PROVIDER=mock.\nDo NOT use with Stripe / production.\nStripe completion comes from webhook + reconciliation (+ optional authenticated status poll).\n\nFlutter production MUST NOT call this endpoint.\n\nBody: {} optional\n\nRequires:\nfine_id, payment_id",
                'body' => [
                    'mode' => 'raw',
                    'raw' => '{}',
                    'options' => ['raw' => ['language' => 'json']],
                ],
            ],
            [
                'const j = safeJson();',
                'if (!j || !j.success) return;',
                'const data = j.data || {};',
                'const payment = data.payment || data;',
                'if (payment && payment.id) setEnv(\'payment_id\', payment.id);',
                '',
            ]
        ),
    ],
];

$myPaymentsFolder = [
    'name' => '09b - My Payments',
    'description' => "Citizen payment history (مدفوعاتي / My Payments).\n\nIncludes Fine + Application payments owned by the authenticated citizen.\nUses purpose.code / purpose.label and related.type — Flutter must not reconstruct titles from fine_id/fee codes.\n\nFilters (optional query params on Get My Payments):\n?status=completed|pending|failed|under_verification\n?type=fine|application\n?page=1&per_page=15\n\nForeign payment detail → 404 (non-disclosing).",
    'item' => [
        requestItem(
            'Get My Payments',
            [
                'method' => 'GET',
                'header' => citizenHeaders(),
                'url' => [
                    'raw' => '{{base_url}}/api/payments?page=1&per_page=15',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'payments'],
                    'query' => [
                        ['key' => 'page', 'value' => '1'],
                        ['key' => 'per_page', 'value' => '15'],
                        ['key' => 'status', 'value' => 'completed', 'disabled' => true],
                        ['key' => 'type', 'value' => 'fine', 'disabled' => true],
                    ],
                ],
                'description' => "Purpose:\nReturns all valid Fine + Application payments for the authenticated citizen.\n\nResponse:\ndata.items[] with amount, currency, status, status_label, provider, purpose{code,label}, related{type,id,...}, paid_at, created_at\ndata.pagination { current_page, per_page, total, last_page }\n\nOptional filters (enable query params in Postman):\nstatus=completed|pending|failed|under_verification\ntype=fine|application\n\nDo not rely on raw metadata / provider_reference / checkout_url in history.\n\nStores automatically:\npayment_id (first item when present)\n\nFlutter usage:\nSidebar مدفوعاتي / My Payments",
            ],
            [
                'const j = safeJson();',
                'if (!j || !j.success) return;',
                'const data = j.data || {};',
                'pm.test(\'items and pagination exist\', function () {',
                '  pm.expect(data).to.have.property(\'items\');',
                '  pm.expect(data).to.have.property(\'pagination\');',
                '  pm.expect(Array.isArray(data.items)).to.eql(true);',
                '});',
                'if (Array.isArray(data.items) && data.items[0]) {',
                '  const item = data.items[0];',
                '  pm.test(\'list item has purpose/related core fields\', function () {',
                '    pm.expect(item).to.have.property(\'amount\');',
                '    pm.expect(item).to.have.property(\'currency\');',
                '    pm.expect(item).to.have.property(\'status\');',
                '    pm.expect(item.purpose).to.have.property(\'code\');',
                '    pm.expect(item.purpose).to.have.property(\'label\');',
                '    pm.expect(item.related).to.have.property(\'type\');',
                '  });',
                '  setEnv(\'payment_id\', item.id);',
                '}',
                '',
            ]
        ),
        requestItem(
            'Get Payment Detail',
            [
                'method' => 'GET',
                'header' => citizenHeaders(),
                'url' => [
                    'raw' => '{{base_url}}/api/payments/{{payment_id}}',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'payments', '{{payment_id}}'],
                ],
                'description' => "Purpose:\nCitizen-owned Payment detail (same core fields as list + detail block).\nForeign ownership → 404.\n\nrelated.type = fine → detail.fine\nrelated.type = application → detail.application + detail.fee\n\nNot a staff/dashboard endpoint.\n\nRequires:\npayment_id",
            ],
            [
                'const j = safeJson();',
                'if (!j || !j.success) return;',
                'const data = j.data || {};',
                'pm.test(\'detail has purpose and related\', function () {',
                '  pm.expect(data.purpose).to.have.property(\'code\');',
                '  pm.expect(data.related).to.have.property(\'type\');',
                '});',
                'pm.test(\'raw metadata is not exposed\', function () {',
                '  pm.expect(data).to.not.have.property(\'metadata\');',
                '  pm.expect(data).to.not.have.property(\'checkout_url\');',
                '});',
                '',
            ]
        ),
        requestItem(
            'Get Completed Payments',
            [
                'method' => 'GET',
                'header' => citizenHeaders(),
                'url' => [
                    'raw' => '{{base_url}}/api/payments?status=completed&page=1&per_page=15',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'payments'],
                    'query' => [
                        ['key' => 'status', 'value' => 'completed'],
                        ['key' => 'page', 'value' => '1'],
                        ['key' => 'per_page', 'value' => '15'],
                    ],
                ],
                'description' => "Filter helper: status=completed only.",
            ]
        ),
        requestItem(
            'Get Fine Payments',
            [
                'method' => 'GET',
                'header' => citizenHeaders(),
                'url' => [
                    'raw' => '{{base_url}}/api/payments?type=fine&page=1&per_page=15',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'payments'],
                    'query' => [
                        ['key' => 'type', 'value' => 'fine'],
                        ['key' => 'page', 'value' => '1'],
                        ['key' => 'per_page', 'value' => '15'],
                    ],
                ],
                'description' => "Filter helper: type=fine (valid Fine-linked payments only).",
            ]
        ),
        requestItem(
            'Get Application Payments',
            [
                'method' => 'GET',
                'header' => citizenHeaders(),
                'url' => [
                    'raw' => '{{base_url}}/api/payments?type=application&page=1&per_page=15',
                    'host' => ['{{base_url}}'],
                    'path' => ['api', 'payments'],
                    'query' => [
                        ['key' => 'type', 'value' => 'application'],
                        ['key' => 'page', 'value' => '1'],
                        ['key' => 'per_page', 'value' => '15'],
                    ],
                ],
                'description' => "Filter helper: type=application (valid Application-linked payments only).",
            ]
        ),
    ],
];

$returnPagesFolder = [
    'name' => '09c - Payment Return Pages (Public Display)',
    'description' => "Public browser pages after Stripe Checkout.\nDISPLAY ONLY — never mutate Payment or Fine.\nFlutter does not call these as APIs; document for QA / manual verification.\nSuccess with a real session_id shows success or processing based on local Payment.status.",
    'item' => [
        [
            'name' => 'Payment Processing Page',
            'request' => [
                'method' => 'GET',
                'header' => [],
                'url' => [
                    'raw' => '{{base_url}}/payment/processing?lang={{app_language}}',
                    'host' => ['{{base_url}}'],
                    'path' => ['payment', 'processing'],
                    'query' => [
                        ['key' => 'lang', 'value' => '{{app_language}}'],
                    ],
                ],
                'description' => "Display-only public page. Does not mutate Payment/Fine.",
            ],
            'response' => [],
            'event' => [
                [
                    'listen' => 'test',
                    'script' => [
                        'type' => 'text/javascript',
                        'exec' => [
                            'pm.test(\'Status is 200\', function () { pm.expect(pm.response.code).to.eql(200); });',
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'Payment Cancel Page',
            'request' => [
                'method' => 'GET',
                'header' => [],
                'url' => [
                    'raw' => '{{base_url}}/payment/cancel?lang={{app_language}}',
                    'host' => ['{{base_url}}'],
                    'path' => ['payment', 'cancel'],
                    'query' => [
                        ['key' => 'lang', 'value' => '{{app_language}}'],
                    ],
                ],
                'description' => "Display-only cancel page after leaving Stripe Checkout. Does not mutate Payment/Fine.",
            ],
            'response' => [],
            'event' => [
                [
                    'listen' => 'test',
                    'script' => [
                        'type' => 'text/javascript',
                        'exec' => [
                            'pm.test(\'Status is 200\', function () { pm.expect(pm.response.code).to.eql(200); });',
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'Payment Success Page (needs session_id)',
            'request' => [
                'method' => 'GET',
                'header' => [],
                'url' => [
                    'raw' => '{{base_url}}/payment/success?session_id={{stripe_session_id}}&lang={{app_language}}',
                    'host' => ['{{base_url}}'],
                    'path' => ['payment', 'success'],
                    'query' => [
                        ['key' => 'session_id', 'value' => '{{stripe_session_id}}'],
                        ['key' => 'lang', 'value' => '{{app_language}}'],
                    ],
                ],
                'description' => "Display-only. Reads local Payment by Stripe session id (provider_reference).\nDoes NOT complete payment.\nSet stripe_session_id manually (cs_...) after creating a Fine Stripe Checkout if needed.\nMissing/invalid session → safe inconclusive/processing page (still 200).",
            ],
            'response' => [],
            'event' => [
                [
                    'listen' => 'test',
                    'script' => [
                        'type' => 'text/javascript',
                        'exec' => [
                            'pm.test(\'Status is 200\', function () { pm.expect(pm.response.code).to.eql(200); });',
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$items = $data['item'];
$newItems = [];
$insertedMyPayments = false;
foreach ($items as $folder) {
    if (($folder['name'] ?? '') === '09 - Fines') {
        $newItems[] = $finesFolder;
        $newItems[] = $myPaymentsFolder;
        $newItems[] = $returnPagesFolder;
        $insertedMyPayments = true;
        continue;
    }
    $newItems[] = $folder;
}

if (! $insertedMyPayments) {
    fwrite(STDERR, "09 - Fines folder not found\n");
    exit(1);
}

$data['item'] = $newItems;

// Update collection description with Fine Payment + My Payments pointers (additive).
$extra = "\n\nCitizen Fine Payment (Steps 3–5):\n- 09 - Fines: detail, pay, status, mock confirm\n- 09b - My Payments: history list/detail/filters\n- 09c - Payment Return Pages: public display-only Stripe return URLs\nSee docs/CITIZEN_FINE_PAYMENT_FLUTTER_HANDOFF.md";
if (! str_contains((string) ($data['info']['description'] ?? ''), 'Citizen Fine Payment')) {
    $data['info']['description'] = rtrim((string) $data['info']['description']).$extra;
}

// Optional stripe_session_id collection variable only if missing.
$keys = array_column($data['variable'] ?? [], 'key');
if (! in_array('stripe_session_id', $keys, true)) {
    $data['variable'][] = [
        'key' => 'stripe_session_id',
        'value' => '',
    ];
}

$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, "Encode failed\n");
    exit(1);
}

file_put_contents($path, $encoded.PHP_EOL);
echo "Updated OK\n";
echo "Folders after 08: ";
foreach ($data['item'] as $f) {
    $n = $f['name'] ?? '';
    if (str_starts_with($n, '08') || str_starts_with($n, '09') || str_starts_with($n, '10')) {
        echo $n.' | ';
    }
}
echo PHP_EOL;
