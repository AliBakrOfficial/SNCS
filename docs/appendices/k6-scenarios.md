---
title: "Appendix F — k6 Load Test Scenarios"
path: docs/appendices/k6-scenarios.md
version: "1.3"
summary: "ملفات k6 الكاملة للسيناريوهات S1 وS2 وS3 مع Pass Criteria وتعليمات التشغيل"
tags: [appendix, k6, load-testing, performance, s1, s2, s3]
---

# Appendix F — k6 Load Test Scenarios

**Related Paths:** `tests/load/k6/k6-scenario-S1.js`, `tests/load/k6/k6-scenario-S2.js`, `tests/load/k6/k6-scenario-S3.js`

> ملفات k6 الكاملة موثّقة في [load-testing.md](../load-testing.md). هذا الملف يُدرجها كـ Appendix مرجعي.

---

## Scenario S1 — Staging Baseline

<!-- PATH: tests/load/k6/k6-scenario-S1.js -->

```javascript
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
```

---

## Scenario S2 — Production Normal

<!-- PATH: tests/load/k6/k6-scenario-S2.js -->

```javascript
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
```

---

## Scenario S3 — Stress / Peak

<!-- PATH: tests/load/k6/k6-scenario-S3.js -->

```javascript
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
```

---

## تشغيل الاختبارات

```bash
# S1 — Staging
k6 run -e BASE_URL=https://staging.sncs.io tests/load/k6/k6-scenario-S1.js

# S2 — Production
k6 run -e BASE_URL=https://app.sncs.io tests/load/k6/k6-scenario-S2.js --out json=results/s2-$(date +%Y%m%d).json

# S3 — Stress
k6 run -e BASE_URL=https://app.sncs.io tests/load/k6/k6-scenario-S3.js
```
