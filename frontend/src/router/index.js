import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from 'src/stores/authStore';

/**
 * SNCS — Vue Router Configuration
 *
 * Routes with meta fields:
 *   - requiresAuth: boolean — requires valid session
 *   - roles: string[] — allowed roles (empty = any authenticated)
 *   - isPublic: boolean — accessible without auth
 */
const routes = [
  {
    path: '/',
    name: 'login',
    component: () => import('src/pages/LoginPage.vue'),
    meta: { isPublic: true },
  },
  {
    path: '/patient/:token?',
    name: 'patient',
    component: () => import('src/pages/PatientPage.vue'),
    meta: { isPublic: true },
  },
  {
    path: '/nurse',
    name: 'nurse',
    component: () => import('src/pages/NurseDashboard.vue'),
    meta: { requiresAuth: true, roles: ['nurse', 'dept_manager'] },
  },
  {
    path: '/admin',
    name: 'admin',
    component: () => import('src/pages/AdminPanel.vue'),
    meta: { requiresAuth: true, roles: ['hospital_admin', 'superadmin'] },
  },
  {
    // Catch-all → redirect to login
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

/**
 * Navigation Guard — Auth & Role Check
 */
router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore();

  // Public routes — always allow
  if (to.meta.isPublic) {
    // If already authenticated, redirect away from login
    if (to.name === 'login' && authStore.isAuthenticated) {
      const roleRoutes = {
        nurse: '/nurse',
        dept_manager: '/nurse',
        hospital_admin: '/admin',
        superadmin: '/admin',
      };
      return next(roleRoutes[authStore.role] || '/');
    }
    return next();
  }

  // Auth required — check session
  if (to.meta.requiresAuth) {
    if (!authStore.isAuthenticated) {
      // Try to restore session
      try {
        const { api } = await import('src/boot/axios');
        const { data } = await api.get('/api/auth/session');
        if (data.success && data.data?.user) {
          authStore.user = data.data.user;
          if (data.data.csrf_token) {
            localStorage.setItem('csrf_token', data.data.csrf_token);
          }
        } else {
          return next({ name: 'login', query: { redirect: to.fullPath } });
        }
      } catch {
        return next({ name: 'login', query: { redirect: to.fullPath } });
      }
    }

    // Role check
    if (to.meta.roles && to.meta.roles.length > 0) {
      if (!to.meta.roles.includes(authStore.role)) {
        // Redirect to appropriate dashboard
        const roleRoutes = {
          nurse: '/nurse',
          dept_manager: '/nurse',
          hospital_admin: '/admin',
          superadmin: '/admin',
        };
        return next(roleRoutes[authStore.role] || '/');
      }
    }
  }

  next();
});

export default router;
