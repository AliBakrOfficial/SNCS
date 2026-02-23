<template>
  <q-page class="login-page flex flex-center">
    <q-card class="login-card">
      <q-card-section class="text-center">
        <div class="logo-container">
          <q-icon name="local_hospital" size="56px" color="primary" />
        </div>
        <h1 class="text-h4 text-weight-bold q-mt-md q-mb-none">{{ $t('app.title') }}</h1>
        <p class="text-subtitle1 text-grey-6 q-mt-sm">{{ $t('auth.login_title') }}</p>
      </q-card-section>

      <q-card-section>
        <q-form @submit.prevent="handleLogin" class="q-gutter-md">
          <q-input
            v-model="form.username"
            :label="$t('auth.username')"
            filled
            :rules="[val => !!val || $t('auth.username_required')]"
            autocomplete="username"
            data-testid="input-username"
          >
            <template v-slot:prepend>
              <q-icon name="person" />
            </template>
          </q-input>

          <q-input
            v-model="form.password"
            :label="$t('auth.password')"
            filled
            :type="showPassword ? 'text' : 'password'"
            :rules="[val => !!val || $t('auth.password_required')]"
            autocomplete="current-password"
            data-testid="input-password"
          >
            <template v-slot:prepend>
              <q-icon name="lock" />
            </template>
            <template v-slot:append>
              <q-icon
                :name="showPassword ? 'visibility_off' : 'visibility'"
                class="cursor-pointer"
                @click="showPassword = !showPassword"
              />
            </template>
          </q-input>

          <q-btn
            type="submit"
            :label="$t('auth.login')"
            color="primary"
            class="full-width q-mt-lg"
            size="lg"
            :loading="loading"
            unelevated
            rounded
            data-testid="btn-login"
          />
        </q-form>
      </q-card-section>

      <q-card-section v-if="errorMessage" class="q-pt-none">
        <q-banner rounded class="bg-negative text-white">
          <template v-slot:avatar>
            <q-icon name="error" />
          </template>
          {{ errorMessage }}
        </q-banner>
      </q-card-section>

      <q-separator />

      <q-card-section class="text-center">
        <q-toggle
          v-model="isDark"
          :label="$t('common.dark_mode')"
          icon="dark_mode"
        />
        <q-btn-toggle
          v-model="locale"
          toggle-color="primary"
          :options="[
            { label: 'العربية', value: 'ar' },
            { label: 'English', value: 'en' }
          ]"
          class="q-mt-sm"
          rounded
          unelevated
          size="sm"
        />
      </q-card-section>
    </q-card>
  </q-page>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useQuasar } from 'quasar';
import { useI18n } from 'vue-i18n';
import { useAuth } from 'src/composables/useAuth';

const $q = useQuasar();
const router = useRouter();
const { locale } = useI18n();
const { login } = useAuth();

const form = ref({ username: '', password: '' });
const showPassword = ref(false);
const loading = ref(false);
const errorMessage = ref('');

const isDark = computed({
  get: () => $q.dark.isActive,
  set: (val) => $q.dark.set(val),
});

async function handleLogin() {
  loading.value = true;
  errorMessage.value = '';

  try {
    const result = await login(form.value.username, form.value.password);

    if (result.success) {
      // Route based on role
      const roleRoutes = {
        nurse: '/nurse',
        dept_manager: '/nurse',
        hospital_admin: '/admin',
        superadmin: '/admin',
      };
      const target = roleRoutes[result.user.role] || '/';
      await router.push(target);
    } else {
      errorMessage.value = result.error || 'Login failed';
    }
  } catch (err) {
    errorMessage.value = err.message || 'An unexpected error occurred';
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  background: linear-gradient(135deg, var(--accent) 0%, #0d2b52 100%);
}

.login-card {
  width: 100%;
  max-width: 420px;
  border-radius: 16px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
}

.logo-container {
  width: 80px;
  height: 80px;
  margin: 0 auto;
  border-radius: 50%;
  background: rgba(27, 74, 138, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}

.body--dark .login-page {
  background: linear-gradient(135deg, #1a1a2e 0%, #0a0a1a 100%);
}

.body--dark .login-card {
  background: var(--card-bg);
}
</style>
