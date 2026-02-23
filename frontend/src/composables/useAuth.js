/**
 * Auth composable — login, logout, session check.
 * @module composables/useAuth
 */
import { useAuthStore } from '../stores/authStore';

/**
 * Provides authentication actions.
 * @returns {{ login: Function, logout: Function, checkSession: Function }}
 */
export function useAuth() {
  const authStore = useAuthStore();

  /** @param {string} username @param {string} password */
  async function login(username, password) {
    // Implementation in Phase 7
  }

  async function logout() {
    // Implementation in Phase 7
  }

  async function checkSession() {
    // Implementation in Phase 7
  }

  return { login, logout, checkSession };
}
