/**
 * Auth Store — Pinia
 * Manages user authentication state.
 * @module stores/authStore
 */
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useAuthStore = defineStore('auth', () => {
  /** @type {import('vue').Ref<Object|null>} */
  const user = ref(null);

  /** @type {import('vue').Ref<boolean>} */
  const isLoading = ref(false);

  /** Whether the user is authenticated */
  const isAuthenticated = computed(() => !!user.value);

  /** Current user role */
  const role = computed(() => user.value?.role || null);

  /**
   * Set user data after login.
   * @param {Object} userData - User data from API
   */
  function setUser(userData) {
    user.value = userData;
  }

  /** Clear user data on logout */
  function clearUser() {
    user.value = null;
  }

  return { user, isLoading, isAuthenticated, role, setUser, clearUser };
});
