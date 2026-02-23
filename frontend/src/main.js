import { createApp } from 'vue';
import { Quasar } from 'quasar';
import { createPinia } from 'pinia';
import router from './router';
import App from './App.vue';

import '@quasar/extras/material-icons/material-icons.css';
import 'quasar/src/css/index.sass';
import './css/app.css';

const app = createApp(App);

app.use(Quasar, {
  config: {
    dark: 'auto',
  },
});
app.use(createPinia());
app.use(router);

app.mount('#app');
