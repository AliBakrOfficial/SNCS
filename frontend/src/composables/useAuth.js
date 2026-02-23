import { useAuthStore } from 'src/stores/authStore';
import { api } from 'src/boot/axios';

/**
 * SNCS — Auth Composable
 *
 * Wraps authentication API calls and manages the Pinia auth store.
 * Stores CSRF token in localStorage for axios interceptor attachment.
 */
export function useAuth() {
  const authStore = useAuthStore();

  /**
   * Login with username and password.
   * @param {string} username
   * @param {string} password
   * @returns {Promise<{success: boolean, user?: object, error?: string}>}
   */
  async function login(username, password) {
    authStore.loading = true;
    try {
      const { data } = await api.post('/api/auth/login', { username, password });

      if (data.success) {
        authStore.user = data.data.user;

        // Store CSRF token for subsequent requests
        if (data.data.csrf_token) {
          localStorage.setItem('csrf_token', data.data.csrf_token);
        }

        return { success: true, user: data.data.user };
      }

      return { success: false, error: data.error || 'Login failed' };
    } catch (err) {
      const message = err.response?.data?.error || err.message || 'Login failed';
      return { success: false, error: message };
    } finally {
      authStore.loading = false;
    }
  }

  /**
   * Logout and clear session.
   */
  async function logout() {
    try {
      await api.post('/api/auth/logout');
    } catch {
      // Always clear local state even if server call fails
    }
    authStore.user = null;
    localStorage.removeItem('csrf_token');
  }

  /**
   * Check current session status (e.g., on app boot).
   * @returns {Promise<boolean>} True if authenticated
   */
  async function checkSession() {
    authStore.loading = true;
    try {
      const { data } = await api.get('/api/auth/session');
      if (data.success && data.data?.user) {
        authStore.user = data.data.user;
        if (data.data.csrf_token) {
          localStorage.setItem('csrf_token', data.data.csrf_token);
        }
        return true;
      }
      return false;
    } catch {
      authStore.user = null;
      return false;
    } finally {
      authStore.loading = false;
    }
  }

  return {
    login,
    logout,
    checkSession,
    user: authStore.user,
    isAuthenticated: authStore.isAuthenticated,
    role: authStore.role,
    loading: authStore.loading,
  };
}
