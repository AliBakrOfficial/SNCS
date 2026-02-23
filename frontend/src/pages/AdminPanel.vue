<template>
  <q-page padding>
    <!-- Admin panel will be implemented in Phase 7 -->
    <div class="text-h5">{{ $t('admin.panel') }}</div>
    <q-tabs v-model="tab" dense>
      <q-tab name="rooms" :label="$t('admin.rooms')" />
      <q-tab name="wards" :label="$t('admin.wards')" />
      <q-tab name="staff" :label="$t('admin.staff')" />
      <q-tab name="audit" :label="$t('admin.auditLog')" />
      <q-tab name="reports" :label="$t('admin.reports')" />
    </q-tabs>
  </q-page>
</template>

<script setup>
/**
 * AdminPanel — System management.
 * Tabs: Rooms, Departments, Staff, Audit Log, Reports.
 * Full CRUD operations for all entities.
 */
import { ref } from 'vue';

const tab = ref('rooms');
</script>
