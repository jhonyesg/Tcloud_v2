/** @type {import('tailwindcss').Config} */
module.exports = {
    /*
     * Replica EXACTA de la config que hoy se inyecta en runtime en
     * `layouts/app.blade.php` (`tailwind.config = { theme: { extend: { colors: {
     * brand: {...} } } } }`). Si divergen, los colores de marca cambian de
     * golpe: `brand-500` pasaría del índigo corporativo al azul por defecto.
     */
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#e6e9f4',
                    100: '#c4cbe4',
                    200: '#9faad1',
                    300: '#7a89be',
                    400: '#5b6db3',
                    500: '#4654a8',
                    600: '#3d4899',
                    700: '#343c88',
                    800: '#2b3077',
                    900: '#1a1f4d',
                },
            },
        },
    },

    /*
     * Rutas donde aparece cada clase. El escáner busca strings completos, así
     * que incluye controladores (mapas de clases por estado), providers y JS:
     * hay clases que sólo viven en PHP (`'bg-green-100 text-green-700'`) o en
     * `classList.add()` del navegador.
     */
    content: [
        './resources/views/**/*.blade.php',
        './resources/views/**/*.php',
        './app/**/*.php',
        './routes/**/*.php',
        './public/js/**/*.js',
        './config/**/*.php',
    ],

    /*
     * `safelist` está vacío a propósito: si una clase sólo se construye por
     * concatenación (`'bg-' . $color`), se declara acá de forma explícita en
     * vez de depender del escáner.
     */
    safelist: [],

    corePlugins: {
        preflight: true,
    },
};
