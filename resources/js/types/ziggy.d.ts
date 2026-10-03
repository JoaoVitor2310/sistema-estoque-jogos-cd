import type { route as ziggyRoute } from 'ziggy-js';

// O Ziggy injeta `route()` como global (ZiggyVue + @routes); sem isto o
// type-check acusa uma chamada não declarada em toda página.
declare global {
    const route: typeof ziggyRoute;
}

// ZiggyVue também expõe `route` nos templates (`this.route` / `route(...)`).
declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        route: typeof ziggyRoute;
    }
}
