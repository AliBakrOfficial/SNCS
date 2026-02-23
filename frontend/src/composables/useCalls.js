import { useCallStore } from 'src/stores/callStore';
import { api } from 'src/boot/axios';

/**
 * SNCS — Calls Composable
 *
 * Wraps call-related API calls and syncs with the Pinia call store.
 */
export function useCalls() {
  const callStore = useCallStore();

  /**
   * Fetch active calls for the current nurse/department.
   * @param {object} [filters] Optional filters (dept_id, nurse_id, status)
   */
  async function fetchActiveCalls(filters = {}) {
    try {
      const params = new URLSearchParams(filters).toString();
      const url = `/api/calls/active${params ? '?' + params : ''}`;
      const { data } = await api.get(url);

      if (data.success) {
        callStore.clearCalls();
        (data.data || []).forEach(call => callStore.upsertCall(call));
      }
    } catch (err) {
      console.error('[useCalls] Failed to fetch active calls:', err);
    }
  }

  /**
   * Accept an assigned call.
   * @param {number} callId
   */
  async function acceptCall(callId) {
    try {
      const { data } = await api.post(`/api/calls/${callId}/accept`);
      if (data.success) {
        callStore.upsertCall({ id: callId, ...data.data });
      }
    } catch (err) {
      console.error('[useCalls] Failed to accept call:', err);
      throw err;
    }
  }

  /**
   * Complete an in-progress call.
   * @param {number} callId
   * @param {string} [notes] Completion notes
   */
  async function completeCall(callId, notes = '') {
    try {
      const { data } = await api.post(`/api/calls/${callId}/complete`, { notes });
      if (data.success) {
        callStore.removeCall(callId);
      }
    } catch (err) {
      console.error('[useCalls] Failed to complete call:', err);
      throw err;
    }
  }

  /**
   * Cancel a pending/assigned call.
   * @param {number} callId
   */
  async function cancelCall(callId) {
    try {
      const { data } = await api.post(`/api/calls/${callId}/cancel`);
      if (data.success) {
        callStore.removeCall(callId);
      }
    } catch (err) {
      console.error('[useCalls] Failed to cancel call:', err);
      throw err;
    }
  }

  return {
    fetchActiveCalls,
    acceptCall,
    completeCall,
    cancelCall,
    activeCalls: callStore.activeCalls,
  };
}
