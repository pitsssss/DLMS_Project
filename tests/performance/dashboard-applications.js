import http from 'k6/http';
import { check, fail } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE_URL = 'http://127.0.0.1:8001';

const applicationsDuration = new Trend('applications_duration', true);
const applicationsFailed = new Rate('applications_failed');
const applicationsTransportFailed = new Rate('applications_transport_failed');
const applicationsRequests = new Counter('applications_requests');

export const options = {
    vus: 1,
    duration: '60s',

    // Explicit connection reuse for reproducible local benchmarking.
    noConnectionReuse: false,
    noVUConnectionReuse: false,

    summaryTrendStats: [
        'avg',
        'min',
        'med',
        'p(95)',
        'p(99)',
        'max',
    ],

    thresholds: {
        applications_failed: ['rate==0'],
        applications_transport_failed: ['rate==0'],
        checks: ['rate==1'],
    },
};

export function setup() {
    const response = http.post(
        `${BASE_URL}/api/dashboard/auth/login`,
        JSON.stringify({
            email: 'benchmark.admin@syrtak.local',
            password: 'Benchmark!2026',
        }),
        {
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            tags: {
                phase: 'setup',
                name: 'POST dashboard login',
            },
        }
    );

    const ok = check(response, {
        'setup login status is 200': (r) => r.status === 200,

        'setup login returns token': (r) => {
            if (r.status !== 200 || !r.body) {
                return false;
            }

            try {
                return !!r.json('data.token');
            } catch (_) {
                return false;
            }
        },
    });

    if (!ok) {
        fail(`Benchmark login failed: HTTP ${response.status}`);
    }

    return {
        token: response.json('data.token'),
    };
}

export default function (data) {
    const response = http.get(
        `${BASE_URL}/api/dashboard/applications`,
        {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${data.token}`,
            },
            tags: {
                phase: 'workload',
                name: 'GET dashboard applications',
            },
        }
    );

    const transportOk = response.status > 0;

    // Track TCP / transport-level failures separately.
    applicationsTransportFailed.add(!transportOk);
    applicationsRequests.add(1);

    let success = false;
    let hasItems = false;
    let hasPagination = false;

    // Never parse JSON when the request itself failed.
    if (transportOk && response.status === 200 && response.body) {
        try {
            const body = response.json();

            success = body?.success === true;
            hasItems = Array.isArray(body?.data?.items);

            hasPagination =
                body?.data?.pagination !== null &&
                typeof body?.data?.pagination === 'object';
        } catch (_) {
            // Invalid JSON is counted as an application failure below.
        }
    }

    const ok = check(response, {
        'applications transport succeeded': () => transportOk,

        'applications status is 200': (r) =>
            r.status === 200,

        'applications success is true': () =>
            success,

        'applications has items': () =>
            hasItems,

        'applications has pagination': () =>
            hasPagination,
    });

    // Only real HTTP responses belong in application latency statistics.
    // TCP connection failures must not pollute response-time measurements.
    if (transportOk) {
        applicationsDuration.add(response.timings.duration);
    }

    applicationsFailed.add(!ok);
}