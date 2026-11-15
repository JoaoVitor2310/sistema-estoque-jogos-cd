// Bootstrap
import 'bootstrap/dist/css/bootstrap.min.css';  // Importar CSS do Bootstrap
import 'bootstrap/dist/js/bootstrap.bundle.min.js'; // Importar JavaScript do Bootstrap

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';


// Components
import Layout from './components/core/Layout.vue';

// PrimeVue
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import 'primeicons/primeicons.css';
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';
import { definePreset } from '@primevue/themes';
import ptBr from './locale/pt-br';

const MyPreset = definePreset(Aura, {
    components: {
        datatable: {
            row: {
                selectedBackground: '#000000',
                selectedColor: '#FFFFFF',
            }
        }
    }
});

// O nome que o servidor escreveu no <title> (config('app.name'), do APP_NAME
// do .env). Lido aqui, e não de uma variável VITE_: essas são gravadas no
// JavaScript durante o build, que roda no CI sem .env — a aba mostrava o
// fallback em produção. Assim o nome tem um lugar só e muda sem novo build.
const appName = document.title || 'Carca Deals';

createInertiaApp({
    title: (title) => `${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ).then((page) => {
            // Adiciona o layout padrão, se não houver um layout definido no componente
            page.default.layout = page.default.layout || Layout;
            return page;
        }),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .mixin({ methods: { route } })
            .use(PrimeVue, {
                theme: {
                    preset: MyPreset,
                    options: {
                        darkModeSelector: false,
                    }
                },
                locale: ptBr
            })
            .use(plugin)
            .use(ToastService)
            .use(ConfirmationService)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
