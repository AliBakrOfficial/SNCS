<template>
  <q-layout view="hHh lpR fFf">
    <q-header elevated class="admin-header">
      <q-toolbar>
        <q-btn flat dense round icon="menu" @click="leftDrawerOpen = !leftDrawerOpen" />
        <q-toolbar-title>{{ $t('admin.title') }}</q-toolbar-title>
        <q-btn flat round icon="logout" @click="handleLogout" />
      </q-toolbar>
    </q-header>

    <!-- Navigation Drawer -->
    <q-drawer v-model="leftDrawerOpen" side="right" bordered class="admin-drawer">
      <q-list>
        <q-item-label header class="text-weight-bold">{{ $t('admin.navigation') }}</q-item-label>

        <q-item clickable v-ripple @click="activeTab = 'departments'" :active="activeTab === 'departments'">
          <q-item-section avatar><q-icon name="domain" /></q-item-section>
          <q-item-section>{{ $t('admin.departments') }}</q-item-section>
        </q-item>

        <q-item clickable v-ripple @click="activeTab = 'rooms'" :active="activeTab === 'rooms'">
          <q-item-section avatar><q-icon name="meeting_room" /></q-item-section>
          <q-item-section>{{ $t('admin.rooms') }}</q-item-section>
        </q-item>

        <q-item clickable v-ripple @click="activeTab = 'staff'" :active="activeTab === 'staff'">
          <q-item-section avatar><q-icon name="group" /></q-item-section>
          <q-item-section>{{ $t('admin.staff') }}</q-item-section>
        </q-item>

        <q-item clickable v-ripple @click="activeTab = 'analytics'" :active="activeTab === 'analytics'">
          <q-item-section avatar><q-icon name="analytics" /></q-item-section>
          <q-item-section>{{ $t('admin.analytics') }}</q-item-section>
        </q-item>

        <q-item clickable v-ripple @click="activeTab = 'audit'" :active="activeTab === 'audit'">
          <q-item-section avatar><q-icon name="policy" /></q-item-section>
          <q-item-section>{{ $t('admin.audit_log') }}</q-item-section>
        </q-item>

        <q-item clickable v-ripple @click="activeTab = 'settings'" :active="activeTab === 'settings'">
          <q-item-section avatar><q-icon name="settings" /></q-item-section>
          <q-item-section>{{ $t('admin.settings') }}</q-item-section>
        </q-item>
      </q-list>
    </q-drawer>

    <q-page-container>
      <q-page class="q-pa-md">
        <!-- Departments Tab -->
        <template v-if="activeTab === 'departments'">
          <div class="row items-center q-mb-md">
            <div class="text-h6"><q-icon name="domain" class="q-mr-sm" />{{ $t('admin.departments') }}</div>
            <q-space />
            <q-btn color="primary" icon="add" :label="$t('common.add')" @click="showDeptDialog = true" unelevated />
          </div>
          <q-table :rows="departments" :columns="deptColumns" row-key="id" flat bordered
                   :loading="loading.departments" :no-data-label="$t('common.no_data')" />
        </template>

        <!-- Rooms Tab -->
        <template v-if="activeTab === 'rooms'">
          <div class="row items-center q-mb-md">
            <div class="text-h6"><q-icon name="meeting_room" class="q-mr-sm" />{{ $t('admin.rooms') }}</div>
            <q-space />
            <q-select v-model="selectedDeptId" :options="deptOptions" :label="$t('admin.department')"
                      emit-value map-options dense outlined class="q-mr-md" style="min-width: 200px"
                      @update:model-value="loadRooms" />
            <q-btn color="primary" icon="add" :label="$t('common.add')" @click="showRoomDialog = true" unelevated />
          </div>
          <q-table :rows="rooms" :columns="roomColumns" row-key="id" flat bordered
                   :loading="loading.rooms" :no-data-label="$t('common.no_data')">
            <template v-slot:body-cell-qr_code="props">
              <q-td :props="props">
                <q-btn flat dense icon="qr_code" @click="generateQr(props.row.id)">
                  <q-tooltip>{{ $t('admin.generate_qr') }}</q-tooltip>
                </q-btn>
              </q-td>
            </template>
          </q-table>
        </template>

        <!-- Staff Tab -->
        <template v-if="activeTab === 'staff'">
          <div class="row items-center q-mb-md">
            <div class="text-h6"><q-icon name="group" class="q-mr-sm" />{{ $t('admin.staff') }}</div>
            <q-space />
            <q-btn color="primary" icon="person_add" :label="$t('admin.add_staff')" @click="showStaffDialog = true" unelevated />
          </div>
          <q-table :rows="staff" :columns="staffColumns" row-key="id" flat bordered
                   :loading="loading.staff" :no-data-label="$t('common.no_data')">
            <template v-slot:body-cell-is_active="props">
              <q-td :props="props">
                <q-badge :color="props.row.is_active ? 'positive' : 'negative'">
                  {{ props.row.is_active ? $t('common.active') : $t('common.inactive') }}
                </q-badge>
              </q-td>
            </template>
          </q-table>
        </template>

        <!-- Analytics Tab -->
        <template v-if="activeTab === 'analytics'">
          <div class="text-h6 q-mb-md"><q-icon name="analytics" class="q-mr-sm" />{{ $t('admin.analytics') }}</div>
          <div class="row q-col-gutter-md">
            <div class="col-12 col-sm-6 col-md-3">
              <q-card class="stat-card">
                <q-card-section class="text-center">
                  <q-icon name="call" size="32px" color="primary" />
                  <div class="text-h4 q-mt-sm">{{ stats.totalCalls || 0 }}</div>
                  <div class="text-caption">{{ $t('admin.total_calls_today') }}</div>
                </q-card-section>
              </q-card>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
              <q-card class="stat-card">
                <q-card-section class="text-center">
                  <q-icon name="speed" size="32px" color="warning" />
                  <div class="text-h4 q-mt-sm">{{ stats.avgResponseTime || '—' }}</div>
                  <div class="text-caption">{{ $t('admin.avg_response_sec') }}</div>
                </q-card-section>
              </q-card>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
              <q-card class="stat-card">
                <q-card-section class="text-center">
                  <q-icon name="person" size="32px" color="positive" />
                  <div class="text-h4 q-mt-sm">{{ stats.activeNurses || 0 }}</div>
                  <div class="text-caption">{{ $t('admin.active_nurses') }}</div>
                </q-card-section>
              </q-card>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
              <q-card class="stat-card">
                <q-card-section class="text-center">
                  <q-icon name="warning" size="32px" color="negative" />
                  <div class="text-h4 q-mt-sm">{{ stats.escalations || 0 }}</div>
                  <div class="text-caption">{{ $t('admin.escalations_today') }}</div>
                </q-card-section>
              </q-card>
            </div>
          </div>
        </template>

        <!-- Audit Log Tab -->
        <template v-if="activeTab === 'audit'">
          <div class="text-h6 q-mb-md"><q-icon name="policy" class="q-mr-sm" />{{ $t('admin.audit_log') }}</div>
          <q-table :rows="auditEntries" :columns="auditColumns" row-key="id" flat bordered
                   :loading="loading.audit" :no-data-label="$t('common.no_data')"
                   :pagination="{ rowsPerPage: 25 }">
            <template v-slot:body-cell-meta_json="props">
              <q-td :props="props">
                <q-btn v-if="props.row.meta_json" flat dense icon="code" @click="showMeta(props.row.meta_json)" />
              </q-td>
            </template>
          </q-table>
        </template>

        <!-- Settings Tab -->
        <template v-if="activeTab === 'settings'">
          <div class="text-h6 q-mb-md"><q-icon name="settings" class="q-mr-sm" />{{ $t('admin.settings') }}</div>
          <q-list separator>
            <q-item v-for="(value, key) in systemSettings" :key="key">
              <q-item-section>
                <q-item-label>{{ key }}</q-item-label>
              </q-item-section>
              <q-item-section side>
                <q-input v-model="systemSettings[key]" dense outlined style="min-width: 200px"
                         @blur="updateSetting(key, systemSettings[key])" />
              </q-item-section>
            </q-item>
          </q-list>
        </template>
      </q-page>
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useAuth } from 'src/composables/useAuth';

const { t } = useI18n();
const router = useRouter();
const { logout } = useAuth();

const leftDrawerOpen = ref(true);
const activeTab = ref('departments');
const loading = reactive({ departments: false, rooms: false, staff: false, audit: false });

// Data
const departments = ref([]);
const rooms = ref([]);
const staff = ref([]);
const auditEntries = ref([]);
const systemSettings = ref({});
const stats = ref({});
const selectedDeptId = ref(null);

// Dialogs
const showDeptDialog = ref(false);
const showRoomDialog = ref(false);
const showStaffDialog = ref(false);

// Table columns
const deptColumns = [
  { name: 'id', field: 'id', label: 'ID', sortable: true, align: 'left' },
  { name: 'name', field: 'name', label: t('admin.name'), sortable: true, align: 'left' },
  { name: 'name_en', field: 'name_en', label: t('admin.name_en'), align: 'left' },
  { name: 'is_active', field: 'is_active', label: t('common.status'), align: 'center' },
];

const roomColumns = [
  { name: 'id', field: 'id', label: 'ID', sortable: true, align: 'left' },
  { name: 'room_number', field: 'room_number', label: t('admin.room_number'), sortable: true, align: 'left' },
  { name: 'qr_code', field: 'qr_code', label: 'QR', align: 'center' },
  { name: 'is_active', field: 'is_active', label: t('common.status'), align: 'center' },
];

const staffColumns = [
  { name: 'id', field: 'id', label: 'ID', sortable: true, align: 'left' },
  { name: 'full_name', field: 'full_name', label: t('admin.full_name'), sortable: true, align: 'left' },
  { name: 'username', field: 'username', label: t('auth.username'), align: 'left' },
  { name: 'role', field: 'role', label: t('admin.role'), align: 'center' },
  { name: 'is_active', field: 'is_active', label: t('common.status'), align: 'center' },
  { name: 'last_login', field: 'last_login', label: t('admin.last_login'), align: 'left' },
];

const auditColumns = [
  { name: 'id', field: 'id', label: 'ID', sortable: true, align: 'left' },
  { name: 'action', field: 'action', label: t('admin.action'), sortable: true, align: 'left' },
  { name: 'actor', field: 'actor', label: t('admin.actor'), align: 'left' },
  { name: 'reason', field: 'reason', label: t('admin.reason'), align: 'left' },
  { name: 'meta_json', field: 'meta_json', label: t('admin.details'), align: 'center' },
  { name: 'created_at', field: 'created_at', label: t('admin.time'), sortable: true, align: 'left' },
];

const deptOptions = ref([]);

async function loadDepartments() {
  loading.departments = true;
  try {
    const { api } = await import('src/boot/axios');
    const { data } = await api.get('/api/admin/departments');
    departments.value = data.data || [];
    deptOptions.value = departments.value.map(d => ({ label: d.name, value: d.id }));
  } finally {
    loading.departments = false;
  }
}

async function loadRooms() {
  if (!selectedDeptId.value) return;
  loading.rooms = true;
  try {
    const { api } = await import('src/boot/axios');
    const { data } = await api.get(`/api/admin/rooms/${selectedDeptId.value}`);
    rooms.value = data.data || [];
  } finally {
    loading.rooms = false;
  }
}

async function loadStaff() {
  loading.staff = true;
  try {
    const { api } = await import('src/boot/axios');
    const { data } = await api.get('/api/admin/staff');
    staff.value = data.data || [];
  } finally {
    loading.staff = false;
  }
}

async function loadAuditLog() {
  loading.audit = true;
  try {
    const { api } = await import('src/boot/axios');
    const { data } = await api.get('/api/admin/audit');
    auditEntries.value = data.data || [];
  } finally {
    loading.audit = false;
  }
}

async function generateQr(roomId) {
  try {
    const { api } = await import('src/boot/axios');
    const { data } = await api.post(`/api/admin/rooms/${roomId}/qr`);
    const { Dialog } = await import('quasar');
    Dialog.create({
      title: 'QR Code',
      message: `<img src="data:image/png;base64,${data.data.qr_image}" style="width:250px;height:250px" />`,
      html: true,
    });
  } catch (err) {
    console.error('QR generation failed', err);
  }
}

async function updateSetting(key, value) {
  try {
    const { api } = await import('src/boot/axios');
    await api.put('/api/admin/settings', { key, value });
  } catch (err) {
    console.error('Setting update failed', err);
  }
}

function showMeta(metaJson) {
  import('quasar').then(({ Dialog }) => {
    Dialog.create({
      title: 'Metadata',
      message: `<pre>${JSON.stringify(JSON.parse(metaJson), null, 2)}</pre>`,
      html: true,
    });
  });
}

async function handleLogout() {
  await logout();
  router.push('/');
}

onMounted(() => {
  loadDepartments();
  loadStaff();
  loadAuditLog();
});
</script>

<style scoped>
.admin-header {
  background: var(--accent);
}

.admin-drawer {
  background: var(--card-bg);
}

.stat-card {
  border-radius: 12px;
  transition: transform 0.2s;
}

.stat-card:hover {
  transform: translateY(-2px);
}
</style>
