<template>
  <q-page padding>
    <!-- Nurse dashboard will be implemented in Phase 7 -->
    <div class="text-h5">{{ $t('nurse.dashboard') }}</div>
    <div class="text-subtitle2">{{ $t('nurse.activeCalls') }}</div>
  </q-page>
</template>

<script setup>
/**
 * NurseDashboard — Real-time call queue.
 * Receives live updates via WebSocket.
 * Sound notification on new call, badge count in tab title.
 */
</script>
