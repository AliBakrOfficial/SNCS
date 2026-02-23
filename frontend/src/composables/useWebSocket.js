import { ref, onUnmounted } from 'vue';
import { useCallStore } from 'src/stores/callStore';

/**
 * SNCS — WebSocket Composable
 *
 * Manages a persistent WebSocket connection to the Ratchet server.
 * Features: auto-reconnect with exponential backoff, heartbeat ping/pong,
 * and event routing to the call store.
 */
export function useWebSocket() {
  const connected = ref(false);
  const lastEvent = ref(null);
  const reconnectAttempts = ref(0);

  let ws = null;
  let pingInterval = null;
  let reconnectTimeout = null;

  const MAX_RECONNECT_ATTEMPTS = 10;
  const BASE_DELAY = 1000;

  function getWsUrl() {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const host = window.location.hostname;
    const port = import.meta.env.VITE_WS_PORT || 8080;
    return `${protocol}//${host}:${port}`;
  }

  function connect(userId, sessionId) {
    if (ws && ws.readyState === WebSocket.OPEN) return;

    const url = getWsUrl();
    ws = new WebSocket(url);

    ws.onopen = () => {
      connected.value = true;
      reconnectAttempts.value = 0;
      console.log('[WS] Connected');

      // Authenticate
      send({ type: 'auth', user_id: userId, session_id: sessionId });

      // Start heartbeat
      pingInterval = setInterval(() => {
        send({ type: 'ping' });
      }, 30000);
    };

    ws.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        lastEvent.value = data;
        handleMessage(data);
      } catch (err) {
        console.error('[WS] Invalid message:', err);
      }
    };

    ws.onclose = (event) => {
      connected.value = false;
      clearInterval(pingInterval);
      console.log(`[WS] Closed (code: ${event.code})`);

      // Auto-reconnect unless intentional close
      if (event.code !== 1000 && reconnectAttempts.value < MAX_RECONNECT_ATTEMPTS) {
        const delay = Math.min(BASE_DELAY * Math.pow(2, reconnectAttempts.value), 30000);
        reconnectAttempts.value++;
        console.log(`[WS] Reconnecting in ${delay}ms (attempt ${reconnectAttempts.value})`);
        reconnectTimeout = setTimeout(() => connect(userId, sessionId), delay);
      }
    };

    ws.onerror = (error) => {
      console.error('[WS] Error:', error);
    };
  }

  function handleMessage(data) {
    const callStore = useCallStore();

    switch (data.type) {
      case 'auth_ok':
        console.log('[WS] Authenticated');
        break;

      case 'call_created':
      case 'call_assigned':
      case 'call_accept':
      case 'call_escalated':
        if (data.payload) {
          callStore.upsertCall(data.payload);
        }
        break;

      case 'call_complete':
      case 'call_cancel':
        if (data.payload?.call_id) {
          callStore.removeCall(data.payload.call_id);
        }
        break;

      case 'server_shutdown':
        console.warn('[WS] Server shutting down — will reconnect');
        break;

      case 'pong':
        // Heartbeat acknowledged
        break;

      default:
        console.log('[WS] Unknown message type:', data.type);
    }
  }

  function send(data) {
    if (ws && ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify(data));
    }
  }

  function disconnect() {
    clearInterval(pingInterval);
    clearTimeout(reconnectTimeout);
    if (ws) {
      ws.close(1000, 'User disconnect');
      ws = null;
    }
    connected.value = false;
  }

  onUnmounted(() => {
    disconnect();
  });

  return {
    connected,
    lastEvent,
    reconnectAttempts,
    connect,
    disconnect,
    send,
  };
}
