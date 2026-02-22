---
title: "الواجهة الأمامية — Frontend Implementation (Quasar)"
path: docs/frontend.md
version: "1.3"
summary: "توثيق Quasar PWA: الصفحات، المكونات، Composables، إعداد PWA، Web Push، RTL/Dark Mode، UX specs، وAnimation"
tags: [frontend, quasar, vue3, pwa, rtl, dark-mode, composables, web-push, animations]
---

# الواجهة الأمامية — Frontend Implementation (Quasar)

> يُبنى الـ Frontend بـ **Quasar Framework v2 (Vue 3 + Composition API)** كـ SPA/PWA. النظام عربي RTL بالكامل مع Dark Mode وأنيميشن احترافية.

**Related Paths:** `frontend/src/pages/`, `frontend/src/composables/`, `frontend/src/stores/`, `frontend/src-pwa/`

---

## إعداد Quasar للمشروع

| الإعداد | القيمة والتفاصيل |
|---------|----------------|
| Mode | SPA + PWA — ملف واحد + Service Worker |
| Framework Direction | `rtl: true` في `quasar.config.js` — جميع المكونات RTL تلقائياً |
| Dark Mode | `dark: 'auto'` + `q-dark-mode-switcher` مخصص في الـ Header |
| Quasar Plugins | Notify, Dialog, Loading, LocalStorage, AppFullscreen, Meta |
| Fonts | Cairo (Arabic) + Inter (English) — IconSet: material-icons |
| PWA Manifest | `name: 'SNCS نظام نداء التمريض'`, `theme_color: '#1B4F8A'`, `display: 'standalone'` |
| Build Target | `dist/spa/` مع PWA Workbox Service Worker |
| State Management | **Pinia** — stores: useAuthStore, useCallStore, useWebSocketStore, useSettingsStore |
| HTTP Client | Axios instance مع interceptors للـ dbAuth session + Error handling |

---

## خريطة الصفحات الكاملة

### الصفحات الخارجية — Public Pages

| المسار | الملف | المحتوى والمتطلبات |
|--------|-------|------------------|
| `/` | LandingPage.vue | Hero Section بـ Parallax + نداء صوتي رمزي + scroll animations + CTA مزدوج |
| `/hospitals` | HospitalsPage.vue | قائمة المستشفيات + Cards + خريطة تفاعلية |
| `/changelog` | ChangelogPage.vue | سجل الإصدارات مرتب زمنياً |
| `/privacy` | PrivacyPage.vue | سياسة الخصوصية |
| `/terms` | TermsPage.vue | شروط الاستخدام |
| `/login` | LoginPage.vue | تسجيل دخول الكادر + Redirect حسب الدور |

### واجهة المريض — Patient Interface

| المسار | الملف | التفاصيل |
|--------|-------|---------|
| `/patient` | PatientEntry.vue | شاشة الدخول + checkbox قبول الشروط + شرح الخطوات الثلاث |
| `/patient/scan` | PatientScan.vue | مسح QR + إطار متحرك + confetti animation عند النجاح |
| `/patient/call` | PatientCall.vue | زر نداء ضخم (60% ارتفاع الشاشة) + عداد Throttle + مؤشر حالة النداء |

### واجهة الممرض — Nurse Interface

| المسار | الملف | التفاصيل |
|--------|-------|---------|
| `/nurse` | NurseDashboard.vue | لوحة رئيسية — شاشة QR إلزامية إذا لم يُكمل المسح |
| `/nurse/scan` | NurseScanQR.vue | مسح QR الغرفة — تحقق انتماء الغرفة للقسم |
| `/nurse/calls` | NurseCallsView.vue | قائمة النداءات + slide-in animation + تنبيه صوتي |
| `/nurse/profile` | NurseProfile.vue | بيانات الممرض + سجل نداءاته اليوم |

### لوحة تحكم مدير القسم

| المسار | الملف | التفاصيل |
|--------|-------|---------|
| `/dept` | DeptDashboard.vue | Live Tracker + خريطة تفاعلية + بطاقات الممرضين |
| `/dept/calls` | DeptCallsLive.vue | النداءات النشطة + Pulse (Pending) + Flash (Escalated) |
| `/dept/nurses` | DeptNursesPanel.vue | حالة الممرضين + أزرار التعيين/الإقصاء/الإرجاع |
| `/dept/rooms` | DeptRoomsPanel.vue | الغرف + الممرض المُعيَّن + آخر نداء |
| `/dept/assignments` | DeptAssignments.vue | Drag & Drop لنقل الممرضين بين الغرف |
| `/dept/reports` | DeptReports.vue | تقارير الشفت + Fairness Index chart + تصدير PDF |

### لوحة تحكم مدير المستشفى

| المسار | الملف | التفاصيل |
|--------|-------|---------|
| `/hospital` | HospitalDashboard.vue | Charts لـ KPIs + Live counter |
| `/hospital/departments` | HospitalDepts.vue | إدارة الأقسام + نظرة سريعة على نداءات كل قسم |
| `/hospital/rooms` | HospitalRooms.vue | إدارة الغرف + QR Code viewer وطباعة |
| `/hospital/staff` | HospitalStaff.vue | إدارة الكادر + حالة Online/Offline |
| `/hospital/settings` | HospitalSettings.vue | اسم، شعار، إعدادات Throttle مخصصة |
| `/hospital/live` | HospitalLiveDash.vue | Real-Time Dashboard — ألوان الأقسام حسب الضغط |

### لوحة تحكم السوبر أدمن

| المسار | الملف | التفاصيل |
|--------|-------|---------|
| `/admin` | AdminDashboard.vue | خريطة جغرافية + مؤشرات النداءات + KPIs كلية |
| `/admin/hospitals` | AdminHospitals.vue | CRUD المستشفيات + تفعيل/إيقاف |
| `/admin/users` | AdminUsers.vue | إدارة جميع المستخدمين + فلتر بالمستشفى/الدور |
| `/admin/settings` | AdminSettings.vue | الإعدادات العامة: Throttle, Verification, Escalation |
| `/admin/logs` | AdminLogs.vue | سجل أحداث النظام + فلاتر + تصدير |
| `/admin/live` | AdminLiveSystem.vue | Live view لكل النداءات — WebSocket superadmin |

---

## منظومة Composables

| Composable | المسؤولية |
|-----------|----------|
| `useWebSocket.js` | اتصال WSS واحد لكل جلسة. `{ isConnected, lastEvent, send() }`. Reconnect مع exponential backoff. توزيع الأحداث على Stores. |
| `useCallStore (Pinia)` | حالة النداءات النشطة. يستمع لأحداث WS. يُطلِق الأصوات لكل حدث. |
| `useQRScanner.js` | كاميرا الجهاز عبر jsQR. معالجة نتيجة المسح. إدارة الأذونات. |
| `useCallThrottle.js` | يقرأ `throttle_duration_ms` من Local Storage. `{ canCall, timeRemaining, countdown }`. |
| `usePresenceVerification.js` | يتتبع `expires_at`. تنبيه قبل 5 دقائق. إبطال الجلسة عند الانتهاء. |
| `useSound.js` | تحميل مسبق للأصوات. `play(name), mute(), unmute()`. يحترم إعداد المستخدم. |
| `usePushNotification.js` | طلب إذن الإشعارات. تسجيل Service Worker. إرسال `push_subscription` للـ API. |

---

## Landing Page — المواصفات الإبداعية

| القسم | المواصفات |
|-------|----------|
| Hero Section | gradient متحرك + typewriter animation + أيقونة جرس تنبض + `ping.mp3` (volume: 0.15) + CTA bounce |
| قسم كيف يعمل | 3 خطوات بـ scroll-triggered animation + أيقونات SVG animated |
| شهادات المستشفيات | Carousel auto-scroll + شعارات + اسم + عدد الأقسام |
| CTA المريض | بطاقة خضراء + Checkbox + زر `/patient` |
| CTA الكادر | بطاقة زرقاء + زر `/login` |
| Footer | روابط ثانوية + شعار + نسخة + ping endpoint لحالة الخوادم |
| التأثيرات الصوتية | `ping.mp3` (دخول), `success.mp3` (QR نجاح), `alert.mp3` (نداء جديد, 0.5), `escalation.mp3` (تصعيد, 0.8) |

---

## Animations — Live Tracker

| الحالة | CSS Animation | التأثير الصوتي |
|--------|-------------|--------------|
| Pending | `@keyframes pulse` — border تنبض كل ثانية | `alert.mp3` — مرة واحدة |
| Accepted | `@keyframes slideInRight` + شريط تقدم | `success.mp3` |
| Escalated | `@keyframes flash` + `shake` | `escalation.mp3` — متكرر حتى تدخل المدير |
| Completed | `@keyframes fadeOutDown` | `complete.mp3` |
| Nurse Online | `@keyframes bounceIn` — لون أخضر | لا صوت |
| WS مفقود | `slideDown` Banner برتقالي | `disconnected.mp3` |

---

## Dark Mode

- مفتاح في أعلى يمين الـ Header — يظهر في كل الصفحات الداخلية.
- الحالة تُخزَّن في Local Storage وتُحدَّث فوراً.
- CSS Variables: `--bg-primary`, `--text-main`, `--card-bg`, `--border-color`.
- الجداول والبطاقات والـ sidebar تدعم Dark Mode عبر `q-dark` class.
- الأيقونة: شمس/قمر تتحول بـ rotate animation.

---

## Routing وحماية الصفحات

| المسار | الشرط | إذا فشل |
|--------|-------|---------|
| `/admin/**` | `role === 'superadmin'` | → `/403` |
| `/hospital/**` | `role === 'hospital_admin'` أو أعلى | → `/403` |
| `/dept/**` | `role === 'dept_manager'` أو أعلى | → `/403` |
| `/nurse/**` | `role === 'nurse'` + nurse_shift نشط | → `/nurse/scan` إذا لم يُكمل QR |
| `/patient/**` | patient_session صالحة في Local Storage | → `/patient` لإعادة المسح |
| أي صفحة مُقيَّدة | المستخدم مُسجَّل الدخول | → `/login` مع redirect |

---

## Local Storage Schema (واجهة المريض)

| المفتاح | النوع | الوصف |
|---------|-------|-------|
| `sncs_room_id` | String | معرف الغرفة الحالية |
| `sncs_session_token` | String (UUID) | رمز الجلسة من الخادم |
| `sncs_verified_at` | Timestamp (ms) | وقت آخر مسح QR ناجح |
| `sncs_session_expires_at` | Timestamp (ms) | وقت انتهاء الجلسة |
| `sncs_last_call_at` | Timestamp (ms) | وقت آخر نداء — أساس منطق Throttling |
| `sncs_throttle_duration_ms` | Number (ms) | مدة تعطيل الزر |

---

## Related Paths

```
frontend/src/pages/
frontend/src/components/
frontend/src/stores/
frontend/src/composables/useWebSocket.js
frontend/src/composables/useCallThrottle.js
frontend/src/composables/usePresenceVerification.js
frontend/src/composables/useQRScanner.js
frontend/src/composables/useSound.js
frontend/src/composables/usePushNotification.js
frontend/src/assets/sounds/
frontend/src-pwa/manifest.json
frontend/src-pwa/service-worker.js
```
