import http from 'k6/http';
import ws from 'k6/ws';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const errorRate  = new Rate('errors');
const assignTime = new Trend('assign_time_ms');

export let options = {
  scenarios: {
    s1_nurses: {
      executor: 'constant-vus',
      vus: 150,
      duration: '5m',
      tags: { scenario: 'S1_Nurses' },
    },
    s1_calls: {
      executor: 'constant-arrival-rate',
      rate: 200,
      timeUnit: '1m',
      duration: '5m',
      preAllocatedVUs: 30,
      tags: { scenario: 'S1_Calls' },
    },
  },
  thresholds: {
    'http_req_duration{scenario:S1_Calls}': ['p(95)<500'],
    'errors':         ['rate<0.01'],
    'assign_time_ms': ['p(95)<500'],
  },
};

const BASE = __ENV.BASE_URL || 'http://localhost:8080';

export function setup() {
  const res = http.post(`${BASE}/api/auth/login`, JSON.stringify({
    username: 'test_nurse', password: 'test_password'
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
    room_id: Math.floor(Math.random() * 50) + 1,
  }), { headers });

  const dur = Date.now() - start;
  assignTime.add(dur);

  const ok = check(res, {
    'status 200 or 201': (r) => r.status === 200 || r.status === 201,
    'has call_id':       (r) => r.json('call_id') > 0,
  });
  errorRate.add(!ok);

  sleep(0.5);
}
