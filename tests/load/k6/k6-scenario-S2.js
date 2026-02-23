import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const errorRate  = new Rate('errors');
const assignTime = new Trend('assign_time_ms');

export let options = {
  scenarios: {
    s2_nurses: {
      executor: 'constant-vus',
      vus: 300,
      duration: '10m',
      tags: { scenario: 'S2_Nurses' },
    },
    s2_calls: {
      executor: 'constant-arrival-rate',
      rate: 600,
      timeUnit: '1m',
      duration: '10m',
      preAllocatedVUs: 60,
      tags: { scenario: 'S2_Calls' },
    },
  },
  thresholds: {
    'http_req_duration{scenario:S2_Calls}': ['p(95)<800'],
    'errors':         ['rate<0.02'],
    'assign_time_ms': ['p(95)<800'],
  },
};

const BASE = __ENV.BASE_URL || 'http://localhost:8080';

export function setup() {
  const res = http.post(`${BASE}/api/auth/login`, JSON.stringify({
    username: 'test_nurse_s2', password: 'test_password'
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
    room_id: Math.floor(Math.random() * 100) + 1,
  }), { headers });

  assignTime.add(Date.now() - start);
  errorRate.add(!check(res, { 'status ok': (r) => [200, 201].includes(r.status) }));
  sleep(0.3);
}
