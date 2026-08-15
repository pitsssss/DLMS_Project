import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE_URL = 'http://127.0.0.1:8001';

const overviewDuration = new Trend('overview_duration', true);
const overviewFailed = new Rate('overview_failed');
const overviewTransportFailed = new Rate('overview_transport_failed');
const overviewRequests = new Counter('overview_requests');

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
        overview_failed: ['rate==0'],
        overview_transport_failed: ['rate==0'],
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

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export default function (data) {
    const response = http.get(
        `${BASE_URL}/api/dashboard/overview`,
        {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${data.token}`,
            },
            tags: {
                phase: 'workload',
                name: 'GET dashboard overview',
            },
        }
    );

    const transportOk = response.status > 0;

    // Track TCP / transport-level failures separately.
    overviewTransportFailed.add(!transportOk);
    overviewRequests.add(1);

    let success = false;
    let dataIsObject = false;
    let metaIsObject = false;
    let visibilityIsObject = false;
    let kpisIsObject = false;
    let operationalQueuesIsObject = false;
    let chartsIsObject = false;
    let applicationsTrendItemsIsArray = false;
    let applicationStatusDistributionIsArray = false;
    let serviceTypeDistributionIsArray = false;
    let recentApplicationsIsArray = false;
    let upcomingAppointmentsIsArray = false;
    let recentActivitiesIsArray = false;

    // Never parse JSON when the request itself failed.
    if (transportOk && response.status === 200 && response.body) {
        try {
            const body = response.json();
            const payload = body?.data;

            success = body?.success === true;
            dataIsObject = isObject(payload);
            metaIsObject = isObject(payload?.meta);
            visibilityIsObject = isObject(payload?.visibility);
            kpisIsObject = isObject(payload?.kpis);
            operationalQueuesIsObject = isObject(payload?.operational_queues);
            chartsIsObject = isObject(payload?.charts);
            applicationsTrendItemsIsArray = Array.isArray(
                payload?.charts?.applications_trend?.items
            );
            applicationStatusDistributionIsArray = Array.isArray(
                payload?.charts?.application_status_distribution
            );
            serviceTypeDistributionIsArray = Array.isArray(
                payload?.charts?.service_type_distribution
            );
            recentApplicationsIsArray = Array.isArray(payload?.recent_applications);
            upcomingAppointmentsIsArray = Array.isArray(payload?.upcoming_appointments);
            recentActivitiesIsArray = Array.isArray(payload?.recent_activities);
        } catch (_) {
            // Invalid JSON is counted as an overview failure below.
        }
    }

    const ok = check(response, {
        'overview transport succeeded': () => transportOk,

        'overview status is 200': (r) =>
            r.status === 200,

        'overview success is true': () =>
            success,

        'overview data is object': () =>
            dataIsObject,

        'overview meta is object': () =>
            metaIsObject,

        'overview visibility is object': () =>
            visibilityIsObject,

        'overview kpis is object': () =>
            kpisIsObject,

        'overview operational_queues is object': () =>
            operationalQueuesIsObject,

        'overview charts is object': () =>
            chartsIsObject,

        'overview applications_trend.items is array': () =>
            applicationsTrendItemsIsArray,

        'overview application_status_distribution is array': () =>
            applicationStatusDistributionIsArray,

        'overview service_type_distribution is array': () =>
            serviceTypeDistributionIsArray,

        'overview recent_applications is array': () =>
            recentApplicationsIsArray,

        'overview upcoming_appointments is array': () =>
            upcomingAppointmentsIsArray,

        'overview recent_activities is array': () =>
            recentActivitiesIsArray,
    });

    // Only real HTTP responses belong in overview latency statistics.
    // TCP connection failures must not pollute response-time measurements.
    if (transportOk) {
        overviewDuration.add(response.timings.duration);
    }

    overviewFailed.add(!ok);

    // Controlled paced workload: 1s think-time between overview requests.
    sleep(1);
}
