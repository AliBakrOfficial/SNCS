import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const errorRate  = new Rate('errors');
const assignTime = new Trend('assign_time_ms');

export let options = {
  scenarios: {
    s3_stress: {
      executor: 'ramping-vus',
      startVUs: 100,
      stages: [
        { duration: '2m', target: 500  },
        { duration: '5m', target: 1000 },
        { duration: '3m', target: 1000 },
        { duration: '2m', target: 0    },
      ],
      tags: { scenario: 'S3_Stress' },
    },
    s3_calls: {
      executor: 'constant-arrival-rate',
      rate: 2000,
      timeUnit: '1m',
      duration: '12m',
      preAllocatedVUs: 200,
      tags: { scenario: 'S3_Calls' },
    },
  },
  thresholds: {
    'http_req_duration{scenario:S3_Calls}': ['p(95)<2000'],
    'errors':         ['rate<0.05'],
    'assign_time_ms': ['p(95)<2000'],
  },
};

const BASE = __ENV.BASE_URL || 'http://localhost:8080';

export function setup() {
  const res = http.post(`${BASE}/api/auth/login`, JSON.stringify({
    username: 'test_admin', password: 'test_password'
  }), { headers: { 'Content-Type': 'application/json' } });
  return { token: res.json('token') };
}

export default function (data) {
  const headers = {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${data.token}`,
  };

  const start = Date.now();
  const res   = http.post(`${BASE}/api/calls`, JSON.stringify({
    room_id: Math.floor(Math.random() * 200) + 1,
  }), { headers });

  assignTime.add(Date.now() - start);
  errorRate.add(!check(res, { 'status ok': (r) => [200, 201].includes(r.status) }));
  sleep(0.1);
}
