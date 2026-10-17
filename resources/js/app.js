import './bootstrap';
import '../css/app.css';
import 'driver.js/dist/driver.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import * as Sentry from '@sentry/vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const vueApp = createApp({ render: () => h(App, props) });

        Sentry.init({
            app: vueApp,
            dsn: import.meta.env.VITE_SENTRY_DSN,
            environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || import.meta.env.MODE,
            release: import.meta.env.VITE_SENTRY_RELEASE || undefined,
            integrations: [Sentry.browserTracingIntegration()],
            tracesSampleRate: import.meta.env.PROD ? 0.1 : 1.0,
            tracePropagationTargets: ['localhost', /^https?:\/\/reconciliations-app\.test/, /^\//],
        });

        vueApp.use(plugin).mount(el);
    },
    progress: {
        color: '#0F766E',
    },
});
