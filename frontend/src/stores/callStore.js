/**
 * Call Store — Pinia
 * Manages active call state for real-time dashboards.
 * @module stores/callStore
 */
import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useCallStore = defineStore('calls', () => {
  /** @type {import('vue').Ref<Array>} Active calls list */
  const activeCalls = ref([]);

  /**
   * Add or update a call in the active list.
   * @param {Object} call - Call data from WebSocket event
   */
  function upsertCall(call) {
    const idx = activeCalls.value.findIndex((c) => c.id === call.id);
    if (idx >= 0) {
      activeCalls.value[idx] = { ...activeCalls.value[idx], ...call };
    } else {
      activeCalls.value.push(call);
    }
  }

  /**
   * Remove a call (completed/cancelled).
   * @param {number} callId
   */
  function removeCall(callId) {
    activeCalls.value = activeCalls.value.filter((c) => c.id !== callId);
  }

  /** Clear all calls */
  function clearCalls() {
    activeCalls.value = [];
  }

  return { activeCalls, upsertCall, removeCall, clearCalls };
});
