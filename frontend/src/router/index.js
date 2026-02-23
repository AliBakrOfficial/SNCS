/**
 * Vue Router configuration.
 * Route guards and role-based access control.
 * @module router
 */
import { createRouter, createWebHistory } from 'vue-router';

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('../pages/LoginPage.vue'),
    meta: { public: true },
  },
  {
    path: '/patient',
    name: 'Patient',
    component: () => import('../pages/PatientPage.vue'),
    meta: { public: true },
  },
  {
    path: '/nurse',
    name: 'NurseDashboard',
    component: () => import('../pages/NurseDashboard.vue'),
    meta: { requiresAuth: true, roles: ['nurse'] },
  },
  {
    path: '/admin',
    name: 'AdminPanel',
    component: () => import('../pages/AdminPanel.vue'),
    meta: { requiresAuth: true, roles: ['superadmin', 'hospital_admin', 'dept_manager'] },
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

/**
 * Navigation guard — redirects unauthenticated users to /login.
 */
router.beforeEach((to, from, next) => {
  // Route guard logic will be implemented in Phase 7
  next();
});

export default router;
