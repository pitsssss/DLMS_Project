import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 1,
    duration: '60s',

    summaryTrendStats: [
        'avg',
        'min',
        'med',
        'p(95)',
        'p(99)',
        'max',
    ],

    thresholds: {
        http_req_failed: ['rate==0'],
        checks: ['rate==1'],
    },
};

export default function () {
    const response = http.get(
        'http://127.0.0.1:8001/api/ping',
        {
            tags: {
                name: 'GET /api/ping',
            },
        }
    );

    check(response, {
        'ping status is 200': (r) => r.status === 200,
    });
}
