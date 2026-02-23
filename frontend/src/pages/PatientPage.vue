<template>
  <q-page class="patient-page">
    <!-- Verification Screen -->
    <div v-if="!verified" class="flex flex-center" style="min-height: 100vh">
      <q-card class="patient-card text-center">
        <q-card-section>
          <q-icon name="qr_code_scanner" size="64px" color="primary" />
          <h2 class="text-h5 q-mt-md">{{ $t('patient.welcome') }}</h2>
          <p class="text-subtitle1 text-grey-6">{{ $t('patient.scan_qr') }}</p>
        </q-card-section>

        <q-card-section>
          <q-input
            v-model="qrToken"
            filled
            :label="$t('patient.enter_code')"
            :placeholder="$t('patient.code_placeholder')"
            :rules="[val => !!val || $t('patient.code_required')]"
            data-testid="input-qr-token"
          >
            <template v-slot:prepend>
              <q-icon name="vpn_key" />
            </template>
          </q-input>
        </q-card-section>

        <q-card-actions align="center">
          <q-btn
            :label="$t('patient.verify')"
            color="primary"
            size="lg"
            unelevated
            rounded
            :loading="verifying"
            @click="verifyToken"
            data-testid="btn-verify"
          />
        </q-card-actions>
      </q-card>
    </div>

    <!-- Call Screen -->
    <div v-else class="flex flex-center" style="min-height: 100vh">
      <div class="call-container text-center">
        <q-card class="room-info-card q-mb-xl">
          <q-card-section>
            <q-icon name="meeting_room" size="32px" color="primary" />
            <div class="text-h6 q-mt-sm">{{ roomInfo.room_number }}</div>
            <div class="text-subtitle2 text-grey-6">{{ roomInfo.dept_name }}</div>
          </q-card-section>
        </q-card>

        <!-- Big Call Button -->
        <q-btn
          v-if="!callSent"
          class="call-button pulse-animation"
          round
          size="80px"
          color="negative"
          icon="notifications_active"
          @click="initiateCall"
          :loading="calling"
          data-testid="btn-call-nurse"
        />

        <!-- Call Sent Confirmation -->
        <div v-else class="call-sent">
          <q-icon name="check_circle" size="100px" color="positive" class="q-mb-md" />
          <h2 class="text-h4 text-weight-bold text-positive">{{ $t('patient.call_sent') }}</h2>
          <p class="text-subtitle1 q-mt-sm">{{ $t('patient.nurse_coming') }}</p>

          <!-- Countdown -->
          <q-circular-progress
            :value="cooldownProgress"
            size="80px"
            :thickness="0.15"
            color="primary"
            track-color="grey-3"
            class="q-mt-lg"
          >
            <span class="text-h6">{{ cooldownSeconds }}s</span>
          </q-circular-progress>
          <p class="text-caption text-grey-6 q-mt-sm">{{ $t('patient.can_call_again') }}</p>
        </div>

        <!-- Status Updates via WebSocket -->
        <q-card v-if="callStatus" class="status-card q-mt-xl">
          <q-card-section>
            <q-icon
              :name="statusIcon"
              :color="statusColor"
              size="32px"
            />
            <div class="text-subtitle1 q-mt-sm">{{ statusMessage }}</div>
          </q-card-section>
        </q-card>
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from 'src/boot/axios';

const { t } = useI18n();

const verified = ref(false);
const qrToken = ref('');
const verifying = ref(false);
const roomInfo = ref({});
const callSent = ref(false);
const calling = ref(false);
const callStatus = ref(null);
const cooldownSeconds = ref(300);
const cooldownProgress = ref(0);

let cooldownTimer = null;

async function verifyToken() {
  verifying.value = true;
  try {
    const { data } = await api.post('/api/patient/verify', { qr_token: qrToken.value });
    if (data.success) {
      roomInfo.value = data.data;
      verified.value = true;
    }
  } catch (err) {
    const msg = err.response?.data?.error || t('patient.invalid_code');
    // Show error notification
    import('quasar').then(({ Notify }) => {
      Notify.create({ type: 'negative', message: msg });
    });
  } finally {
    verifying.value = false;
  }
}

async function initiateCall() {
  calling.value = true;
  try {
    // Create patient session + initiate call
    const { data: session } = await api.post('/api/patient/session', {
      room_id: roomInfo.value.room_id,
    });

    if (session.success) {
      const { data: call } = await api.post('/api/patient/call', {
        session_token: session.data.session_token,
        nonce: session.data.nonce,
      });

      if (call.success) {
        callSent.value = true;
        startCooldown();
      }
    }
  } catch (err) {
    const msg = err.response?.data?.error || t('patient.call_failed');
    import('quasar').then(({ Notify }) => {
      Notify.create({ type: 'negative', message: msg });
    });
  } finally {
    calling.value = false;
  }
}

function startCooldown() {
  cooldownSeconds.value = 300;
  cooldownProgress.value = 0;

  cooldownTimer = setInterval(() => {
    cooldownSeconds.value--;
    cooldownProgress.value = ((300 - cooldownSeconds.value) / 300) * 100;

    if (cooldownSeconds.value <= 0) {
      clearInterval(cooldownTimer);
      callSent.value = false;
    }
  }, 1000);
}

const statusIcon = computed(() => {
  const icons = {
    assigned: 'person',
    in_progress: 'directions_walk',
    completed: 'check_circle',
  };
  return icons[callStatus.value] || 'hourglass_empty';
});

const statusColor = computed(() => {
  const colors = {
    assigned: 'info',
    in_progress: 'warning',
    completed: 'positive',
  };
  return colors[callStatus.value] || 'grey';
});

const statusMessage = computed(() => {
  const messages = {
    assigned: t('patient.nurse_assigned'),
    in_progress: t('patient.nurse_on_way'),
    completed: t('patient.call_completed'),
  };
  return messages[callStatus.value] || t('patient.waiting');
});

onUnmounted(() => {
  if (cooldownTimer) clearInterval(cooldownTimer);
});
</script>

<style scoped>
.patient-page {
  background: linear-gradient(180deg, #f8f9fa 0%, #e8f4f8 100%);
  min-height: 100vh;
}

.patient-card {
  width: 100%;
  max-width: 400px;
  border-radius: 16px;
}

.call-container {
  max-width: 400px;
  padding: 24px;
}

.room-info-card {
  border-radius: 12px;
}

.call-button {
  box-shadow: 0 8px 32px rgba(231, 76, 60, 0.4);
  transition: transform 0.2s ease;
}

.call-button:active {
  transform: scale(0.95);
}

.pulse-animation {
  animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
  0%   { box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.5); }
  70%  { box-shadow: 0 0 0 30px rgba(231, 76, 60, 0); }
  100% { box-shadow: 0 0 0 0 rgba(231, 76, 60, 0); }
}

.status-card {
  border-radius: 12px;
}

.body--dark .patient-page {
  background: linear-gradient(180deg, #121212 0%, #1a1a2e 100%);
}
</style>
