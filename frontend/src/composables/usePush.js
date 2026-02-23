import { ref } from 'vue';
import { api } from 'src/boot/axios';

/**
 * SNCS — Push Notification Composable
 *
 * Manages Web Push subscription lifecycle via VAPID.
 * Subscribes/unsubscribes and sends keys to the backend.
 */
export function usePush() {
  const isSubscribed = ref(false);
  const isSupported = ref('serviceWorker' in navigator && 'PushManager' in window);

  /**
   * Subscribe the current user for push notifications.
   */
  async function subscribe() {
    if (!isSupported.value) {
      console.warn('[Push] Not supported in this browser');
      return;
    }

    try {
      const registration = await navigator.serviceWorker.ready;

      // Get VAPID public key from server
      const { data: vapidData } = await api.get('/api/push/vapid-key');
      const vapidPublicKey = vapidData.data?.public_key;

      if (!vapidPublicKey) {
        console.error('[Push] No VAPID public key');
        return;
      }

      // Convert VAPID key to Uint8Array
      const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);

      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey,
      });

      // Send subscription to backend
      const keys = subscription.toJSON().keys;
      await api.post('/api/push/subscribe', {
        endpoint: subscription.endpoint,
        p256dh: keys.p256dh,
        auth: keys.auth,
      });

      isSubscribed.value = true;
      console.log('[Push] Subscribed successfully');
    } catch (err) {
      console.error('[Push] Subscription failed:', err);
    }
  }

  /**
   * Unsubscribe from push notifications.
   */
  async function unsubscribe() {
    try {
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.getSubscription();

      if (subscription) {
        await api.post('/api/push/unsubscribe', {
          endpoint: subscription.endpoint,
        });
        await subscription.unsubscribe();
      }

      isSubscribed.value = false;
      console.log('[Push] Unsubscribed');
    } catch (err) {
      console.error('[Push] Unsubscribe failed:', err);
    }
  }

  /**
   * Check current subscription status.
   */
  async function checkSubscription() {
    if (!isSupported.value) return;

    try {
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.getSubscription();
      isSubscribed.value = !!subscription;
    } catch {
      isSubscribed.value = false;
    }
  }

  return {
    isSubscribed,
    isSupported,
    subscribe,
    unsubscribe,
    checkSubscription,
  };
}

/**
 * Convert a base64 URL-safe string to a Uint8Array.
 */
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}
