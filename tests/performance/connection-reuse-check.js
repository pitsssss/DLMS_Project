import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const newConnections = new Counter('new_connections');

export const options = {
    vus: 1,
    iterations: 500,

    noConnectionReuse: false,
    noVUConnectionReuse: false,

    thresholds: {
        http_req_failed: ['rate==0'],
        checks: ['rate==1'],
    },
};

export default function () {
    const r = http.get(
        'http://127.0.0.1:8001/api/ping',
        {
            headers: {
                Connection: 'keep-alive',
            },
        }
    );

    if (r.timings.connecting > 0) {
        newConnections.add(1);
    }

    check(r, {
        'status is 200': (res) => res.status === 200,
    });
}
