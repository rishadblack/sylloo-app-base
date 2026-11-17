/*
 * rishadblack/sylloo-app-base: the behaviour of the shared screens, as Alpine components registered on `alpine:init`
 * (no inline scripts, no jQuery). Import it once from the app's resources/js/app.js (php artisan sylloo-app-base:install
 * adds the line):
 *
 *     import '../../vendor/rishadblack/sylloo-app-base/resources/js/sylloo-app-base.js';
 *
 * The grouped code field and the PIN pad are wire-bootstrap's own (`input.code`, `pin-pad`, Alpine `wbCode` /
 * `wbPinPad`): the app loads wire-bootstrap's browser layer (resources/js/wire-bootstrap.js) as well.
 */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // The online mark of the layout.
    Alpine.data('syllooOnlineMark', () => ({
        online: navigator.onLine,

        init() {
            window.addEventListener('online', () => (this.online = true));
            window.addEventListener('offline', () => (this.online = false));
        },
    }));
});
