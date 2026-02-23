<template>
  <q-layout view="hHh lpR fFf">
    <!-- Header -->
    <q-header elevated class="nurse-header">
      <q-toolbar>
        <q-btn flat dense round icon="menu" @click="leftDrawerOpen = !leftDrawerOpen" />
        <q-toolbar-title>{{ $t('nurse.title') }}</q-toolbar-title>

        <q-badge v-if="activeCalls.length" color="negative" floating>
          {{ activeCalls.length }}
        </q-badge>

        <q-btn flat round icon="notifications" @click="showNotifications = true">
          <q-badge v-if="unreadCount" color="red" floating>{{ unreadCount }}</q-badge>
        </q-btn>

        <q-btn flat round :icon="isOnShift ? 'toggle_on' : 'toggle_off'"
               :color="isOnShift ? 'positive' : 'grey'"
               @click="toggleShift">
          <q-tooltip>{{ isOnShift ? $t('nurse.end_shift') : $t('nurse.start_shift') }}</q-tooltip>
        </q-btn>

        <q-btn flat round icon="logout" @click="handleLogout" />
      </q-toolbar>
    </q-header>

    <!-- Side Drawer -->
    <q-drawer v-model="leftDrawerOpen" side="right" bordered>
      <q-list>
        <q-item-label header>{{ nurseProfile?.name }}</q-item-label>
        <q-item-label header class="text-caption">{{ nurseProfile?.dept_name }}</q-item-label>

        <q-separator />

        <q-item>
          <q-item-section avatar><q-icon name="badge" /></q-item-section>
          <q-item-section>
            <q-item-label>{{ $t('nurse.status') }}</q-item-label>
            <q-item-label caption>
              <q-badge :color="statusColor">{{ nurseProfile?.status }}</q-badge>
            </q-item-label>
          </q-item-section>
        </q-item>

        <q-item>
          <q-item-section avatar><q-icon name="access_time" /></q-item-section>
          <q-item-section>
            <q-item-label>{{ $t('nurse.shift_started') }}</q-item-label>
            <q-item-label caption>{{ nurseProfile?.started_at || '—' }}</q-item-label>
          </q-item-section>
        </q-item>

        <q-item>
          <q-item-section avatar><q-icon name="assignment" /></q-item-section>
          <q-item-section>
            <q-item-label>{{ $t('nurse.total_calls') }}</q-item-label>
            <q-item-label caption>{{ nurseProfile?.total_calls || 0 }}</q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
    </q-drawer>

    <!-- Main Content -->
    <q-page-container>
      <q-page class="q-pa-md">
        <!-- Active Calls -->
        <div class="text-h6 q-mb-md">
          <q-icon name="call" class="q-mr-sm" />
          {{ $t('nurse.active_calls') }}
        </div>

        <div v-if="activeCalls.length === 0" class="text-center q-pa-xl">
          <q-icon name="check_circle_outline" size="64px" color="positive" />
          <p class="text-h6 q-mt-md text-grey-6">{{ $t('nurse.no_active_calls') }}</p>
        </div>

        <q-list v-else separator>
          <q-slide-item
            v-for="call in activeCalls"
            :key="call.id"
            @right="(details) => onSlideAction(details, call, 'accept')"
            @left="(details) => onSlideAction(details, call, 'complete')"
          >
            <template v-slot:right>
              <div class="row items-center q-px-md">
                <q-icon name="check" color="positive" size="sm" />
                {{ $t('nurse.accept') }}
              </div>
            </template>
            <template v-slot:left>
              <div class="row items-center q-px-md">
                <q-icon name="done_all" color="info" size="sm" />
                {{ $t('nurse.complete') }}
              </div>
            </template>

            <q-item class="call-item" :class="`priority-${call.priority || 0}`">
              <q-item-section avatar>
                <q-avatar :color="callStatusColor(call.status)" text-color="white" icon="meeting_room" />
              </q-item-section>
              <q-item-section>
                <q-item-label class="text-weight-bold">
                  {{ $t('nurse.room') }} {{ call.room_number }}
                </q-item-label>
                <q-item-label caption>
                  <q-badge :color="callStatusColor(call.status)" outline>{{ call.status }}</q-badge>
                  <span class="q-ml-sm">{{ formatTime(call.initiated_at) }}</span>
                </q-item-label>
              </q-item-section>
              <q-item-section side>
                <div class="text-caption text-grey">{{ elapsedTime(call.initiated_at) }}</div>
                <q-btn
                  v-if="call.status === 'assigned'"
                  flat round size="sm" icon="check" color="positive"
                  @click.stop="acceptCall(call.id)"
                  :loading="actionLoading[call.id]"
                >
                  <q-tooltip>{{ $t('nurse.accept') }}</q-tooltip>
                </q-btn>
                <q-btn
                  v-if="call.status === 'in_progress'"
                  flat round size="sm" icon="done_all" color="info"
                  @click.stop="completeCall(call.id)"
                  :loading="actionLoading[call.id]"
                >
                  <q-tooltip>{{ $t('nurse.complete') }}</q-tooltip>
                </q-btn>
              </q-item-section>
            </q-item>
          </q-slide-item>
        </q-list>

        <!-- Call History (today) -->
        <div class="text-h6 q-mt-xl q-mb-md">
          <q-icon name="history" class="q-mr-sm" />
          {{ $t('nurse.call_history') }}
        </div>

        <q-list separator>
          <q-item v-for="call in callHistory" :key="call.id">
            <q-item-section avatar>
              <q-avatar color="grey-4" text-color="grey-8" icon="meeting_room" />
            </q-item-section>
            <q-item-section>
              <q-item-label>{{ $t('nurse.room') }} {{ call.room_number }}</q-item-label>
              <q-item-label caption>
                {{ call.status }} — {{ call.response_time_ms ? `${Math.round(call.response_time_ms / 1000)}s` : '—' }}
              </q-item-label>
            </q-item-section>
          </q-item>
        </q-list>
      </q-page>
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useCallStore } from 'src/stores/callStore';
import { useAuth } from 'src/composables/useAuth';
import { useCalls } from 'src/composables/useCalls';
import { useWebSocket } from 'src/composables/useWebSocket';

const { t } = useI18n();
const router = useRouter();
const callStore = useCallStore();
const { logout } = useAuth();
const { fetchActiveCalls, acceptCall: doAccept, completeCall: doComplete } = useCalls();
const { connect, disconnect, lastEvent } = useWebSocket();

const leftDrawerOpen = ref(false);
const showNotifications = ref(false);
const nurseProfile = ref(null);
const isOnShift = ref(false);
const unreadCount = ref(0);
const callHistory = ref([]);
const actionLoading = ref({});

const activeCalls = computed(() => callStore.activeCalls);

const statusColor = computed(() => {
  const colors = { available: 'positive', busy: 'warning', offline: 'grey' };
  return colors[nurseProfile.value?.status] || 'grey';
});

function callStatusColor(status) {
  const colors = {
    pending: 'warning', assigned: 'info', in_progress: 'primary',
    escalated: 'negative', completed: 'positive', cancelled: 'grey',
  };
  return colors[status] || 'grey';
}

function formatTime(ts) {
  if (!ts) return '';
  return new Date(ts).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
}

function elapsedTime(ts) {
  if (!ts) return '';
  const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
  if (diff < 60) return `${diff}s`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m`;
  return `${Math.floor(diff / 3600)}h`;
}

async function toggleShift() {
  try {
    const endpoint = isOnShift.value ? '/api/nurse/shift/end' : '/api/nurse/shift/start';
    const { api } = await import('src/boot/axios');
    await api.post(endpoint);
    isOnShift.value = !isOnShift.value;

    if (isOnShift.value) {
      connect();
      await fetchActiveCalls();
    } else {
      disconnect();
    }
  } catch (err) {
    const { Notify } = await import('quasar');
    Notify.create({ type: 'negative', message: err.response?.data?.error || 'Failed' });
  }
}

async function acceptCall(callId) {
  actionLoading.value[callId] = true;
  try {
    await doAccept(callId);
  } finally {
    actionLoading.value[callId] = false;
  }
}

async function completeCall(callId) {
  actionLoading.value[callId] = true;
  try {
    await doComplete(callId);
  } finally {
    actionLoading.value[callId] = false;
  }
}

function onSlideAction({ reset }, call, action) {
  if (action === 'accept') acceptCall(call.id);
  else if (action === 'complete') completeCall(call.id);
  reset();
}

async function handleLogout() {
  disconnect();
  await logout();
  router.push('/');
}

let elapsedTimer = null;

onMounted(async () => {
  try {
    const { api } = await import('src/boot/axios');
    const { data } = await api.get('/api/nurse/profile');
    nurseProfile.value = data.data;
    isOnShift.value = !!data.data?.shift_id;

    if (isOnShift.value) {
      connect();
      await fetchActiveCalls();
    }
  } catch (err) {
    console.error('Failed to load nurse profile', err);
  }

  // Update elapsed times every second
  elapsedTimer = setInterval(() => {}, 1000);
});

onUnmounted(() => {
  disconnect();
  if (elapsedTimer) clearInterval(elapsedTimer);
});
</script>

<style scoped>
.nurse-header {
  background: var(--accent);
}

.call-item {
  border-radius: 8px;
  transition: background 0.2s;
}

.call-item:hover {
  background: rgba(27, 74, 138, 0.05);
}

.priority-0 { border-right: 3px solid var(--accent); }
.priority-1 { border-right: 3px solid var(--warning); }
.priority-2 { border-right: 3px solid #e67e22; }
.priority-3 { border-right: 3px solid var(--danger); background: rgba(231, 76, 60, 0.05); }

.body--dark .call-item:hover {
  background: rgba(255, 255, 255, 0.05);
}
</style>
