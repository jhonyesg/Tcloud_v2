<?php
/**
 * Verificador de cobertura del CSS compilado (change `dashboard-perf-backup-guide`, T3).
 *
 * Por qué existe: al reemplazar el Tailwind Play CDN (JIT en runtime) por un
 * CSS precompilado, una clase que el escáner no ve deja de existir y la UI se
 * degrada en silencio (el botón pierde color, la tabla pierde bordes). Ningún
 * test funcional detecta eso.
 *
 * Qué hace:
 *   1. Extrae los tokens de `class="..."`, de los literales dentro de
 *      `:class="..."` y de `classList.add/remove/toggle(...)`, en todas las
 *      vistas Blade.
 *   2. Descarta lo que el escáner de Tailwind tampoco podría resolver
 *      (interpolación de Blade/Alpine) y lo que no tiene forma de utilidad.
 *   3. Verifica que cada token restante tenga su selector en el CSS compilado.
 *
 * Uso:
 *   cd app && php tests/tailwind_coverage_check.php
 *   exit 0 = todas las clases están en el build, exit 1 = falta alguna
 */

$root = __DIR__ . '/..';
$cssPath = $root . '/public/css/app.css';
$viewsDir = $root . '/resources/views';

if (! is_file($cssPath)) {
    fwrite(STDERR, "Falta public/css/app.css. Correr: npm run build\n");
    exit(1);
}

$css = (string) file_get_contents($cssPath);
$checked = 0;
$skipped = 0;
$missing = [];

/**
 * Prefijos de las utilidades de Tailwind que el proyecto realmente usa.
 *
 * El markup mezcla tres familias de nombres en el atributo `class`:
 *   1. utilidades de Tailwind  → las genera el build (esto es lo que se verifica);
 *   2. iconos de FontAwesome   → los sirve `fontawesome.min.css`;
 *   3. ganchos de JS           → `nav-link`, `row-num`, `canal-card`: existen
 *                                sólo como target de `querySelector`, no
 *                                tienen estilo propio.
 *
 * Verificar (2) y (3) daría un rojo imposible de arreglar. La lista de prefijos
 * es explícita a propósito: si aparece una utilidad de una familia nueva, se
 * agrega acá y el chequeo la empieza a cubrir.
 */
const TAILWIND_PREFIXES = [
    // layout
    'block', 'inline', 'flex', 'grid', 'table', 'hidden', 'contents', 'flow-root',
    'relative', 'absolute', 'fixed', 'sticky', 'static', 'inset', 'top', 'right',
    'bottom', 'left', 'z', 'order', 'col', 'row', 'float', 'clear', 'box', 'isolate',
    'object', 'overflow', 'overscroll', 'static', 'visible', 'invisible', 'collapse',
    'position', 'container', 'columns', 'break', 'aspect', 'basis', 'grow', 'shrink',
    // spacing / sizing
    'p', 'px', 'py', 'pt', 'pb', 'pl', 'pr', 'ps', 'pe',
    'm', 'mx', 'my', 'mt', 'mb', 'ml', 'mr', 'ms', 'me', 'space',
    'w', 'h', 'min', 'max', 'size',
    // tipografía
    'font', 'text', 'leading', 'tracking', 'indent', 'align', 'whitespace',
    'truncate', 'uppercase', 'lowercase', 'capitalize', 'normal-case', 'italic',
    'not-italic', 'underline', 'overline', 'line-through', 'no-underline',
    'antialiased', 'subpixel-antialiased', 'list', 'decoration', 'underline-offset',
    // fondos / bordes
    'bg', 'from', 'via', 'to', 'bg-gradient', 'border', 'divide', 'outline', 'ring',
    'rounded', 'shadow', 'opacity', 'mix-blend', 'bg-blend',
    // efectos / interactividad
    'transition', 'duration', 'ease', 'delay', 'animate', 'transform', 'origin',
    'scale', 'rotate', 'translate', 'skew', 'cursor', 'select', 'resize', 'scroll',
    'snap', 'touch', 'pointer-events', 'appearance', 'placeholder', 'accent', 'caret',
    'fill', 'stroke', 'sr-only', 'not-sr-only', 'group', 'peer', 'hover', 'focus',
    'active', 'disabled', 'checked', 'first', 'last', 'odd', 'even', 'before', 'after',
    'dark', 'print', 'motion-reduce',
];

/**
 * Utilidades de Tailwind de una sola palabra. El resto de los tokens sin guion
 * que aparecen en `:class="..."` son operandos de comparación (`shareViewMode
 * === 'list'`), no clases: sin esta lista blanca el chequeo reporta `list`
 * como clase faltante.
 */
const SINGLE_WORD_UTILITIES = [
    'block', 'inline', 'flex', 'grid', 'table', 'hidden', 'contents', 'flow-root',
    'relative', 'absolute', 'fixed', 'sticky', 'static', 'isolate', 'visible',
    'invisible', 'container', 'truncate', 'italic', 'underline', 'uppercase',
    'lowercase', 'capitalize', 'antialiased', 'border', 'shadow', 'rounded',
    'transition', 'transform', 'group', 'peer', 'grow', 'shrink', 'outline',
    'filter', 'resize', 'appearance', 'collapse', 'sr-only',
];

/**
 * ¿El token es una utilidad de Tailwind (o una variante de una)?
 */
function looksLikeClass(string $token): bool
{
    // Forma: sólo caracteres de clase Tailwind, sin interpolación.
    if (! preg_match('/^-?[a-zA-Z0-9\.\/\[\]%#:_!()\-]+$/', $token)) {
        return false;
    }

    // FontAwesome: los sirve `fontawesome.min.css`, no el build.
    if (preg_match('/^fa[srb]?(-|$)/', $token)) {
        return false;
    }

    // Quitar variantes (`hover:`, `md:`, `dark:`, `group-hover:`, …) y el `!`.
    $base = ltrim($token, '!');
    while (preg_match('/^[a-z-]+:/', $base)) {
        $base = preg_replace('/^[a-z-]+:/', '', $base);
    }

    // Sin guion: sólo si es una utilidad de una palabra conocida.
    if (! str_contains($base, '-')) {
        return in_array($base, SINGLE_WORD_UTILITIES, true);
    }

    foreach (TAILWIND_PREFIXES as $prefix) {
        if ($base === $prefix || str_starts_with($base, $prefix . '-')) {
            return true;
        }
    }

    return false;
}

/**
 * ¿El CSS compilado contiene el selector de esta clase?
 *
 * Tailwind escapa `:` `/` `.` `[` `]` `%` con backslash. Se prueban las dos
 * formas para no dar falsos negativos por el escapado.
 */
function cssHasClass(string $css, string $class): bool
{
    $escaped = preg_replace('/([:\\/\.\[\]%#(),!])/', '\\\\$1', $class);

    foreach ([$escaped, $class] as $needle) {
        foreach (['{', ':', ' ', ',', '>', '+', '~'] as $suffix) {
            if (str_contains($css, '.' . $needle . $suffix)) {
                return true;
            }
        }
    }

    return false;
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($viewsDir, FilesystemIterator::SKIP_DOTS)
);

/**
 * Clases propias definidas en bloques `<style>` de las propias vistas
 * (`.glass-card`, `.orb-1`, `.pg-rel-badge`, …). No son utilidades de Tailwind
 * y no tienen por qué estar en el build; se verifican aparte de que existan.
 */
$localClasses = [];
foreach ($files as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }
    $html = (string) file_get_contents($file->getPathname());
    if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $html, $mm)) {
        foreach ($mm[1] as $styleBlock) {
            // Los selectores CSS llevan escapes (`.bg-white\/8`,
            // `.hover\:bg-white\/8`). Se normalizan para poder compararlos con
            // el token tal como aparece en el atributo `class`.
            if (preg_match_all('/\.((?:[a-zA-Z0-9_-]|\\\\[:.\/%\[\]])+)/', $styleBlock, $cm)) {
                foreach ($cm[1] as $cls) {
                    $localClasses[str_replace('\\', '', $cls)] = true;
                }
            }
        }
    }
}

/**
 * Ganchos de JS: nombres que sólo existen como target de `querySelector`,
 * `closest` o `classList.contains` (`row-num`, `canal-card`, `btn-ejecutar`).
 * No tienen estilo propio, así que no están en el CSS ni tienen por qué estar.
 */
$jsHooks = [];
foreach ($files as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }
    $raw = (string) file_get_contents($file->getPathname());
    if (preg_match_all('/(?:querySelector(?:All)?|closest|classList\.(?:contains|add|remove|toggle))\(\s*[\'"]\.?([a-zA-Z][a-zA-Z0-9_-]*)/', $raw, $hm)) {
        foreach ($hm[1] as $hook) {
            $jsHooks[$hook] = true;
        }
    }
}

foreach ($files as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }

    $html = (string) file_get_contents($file->getPathname());
    if ($html === '') {
        continue;
    }

    $candidates = [];

    // 1) class="..." — el atributo literal. El lookbehind evita que el patrón
    //    capture además el payload de `:class="..."`, que es una expresión
    //    Alpine y se procesa aparte.
    if (preg_match_all('/(?<![:\\w-])class="([^"]*)"/', $html, $m)) {
        $candidates = array_merge($candidates, $m[1]);
    }

    // 2) :class="..." — sólo los literales entre comillas simples.
    if (preg_match_all('/:class="([^"]*)"/', $html, $m)) {
        foreach ($m[1] as $expr) {
            if (preg_match_all("/'([^']*)'/", $expr, $mm)) {
                $candidates = array_merge($candidates, $mm[1]);
            }
        }
    }

    // 3) classList.add('a', 'b') / remove / toggle.
    if (preg_match_all('/classList\.(?:add|remove|toggle)\(([^)]*)\)/', $html, $m)) {
        foreach ($m[1] as $expr) {
            if (preg_match_all("/'([^']*)'/", $expr, $mm)) {
                $candidates = array_merge($candidates, $mm[1]);
            }
        }
    }

    foreach ($candidates as $blob) {
        foreach (preg_split('/\s+/', trim($blob)) as $token) {
            $token = trim($token, " \t\n\r\0\x0B,;");

            if ($token === '' || ! looksLikeClass($token)) {
                $skipped++;
                continue;
            }

            // Interpolación de Blade / Alpine: el escáner tampoco la resuelve,
            // así que verificarla daría falsos negativos.
            if (str_contains($token, '{{') || str_contains($token, '$') || str_contains($token, '@')) {
                $skipped++;
                continue;
            }

            if (isset($localClasses[$token]) || isset($jsHooks[$token])) {
                $skipped++;
                continue;
            }

            $checked++;
            if (! cssHasClass($css, $token)) {
                $key = $file->getPathname() . ' :: ' . $token;
                $missing[$key] = true;
            }
        }
    }
}

$missing = array_keys($missing);
sort($missing);

printf("Tailwind coverage: %d clases verificadas, %d tokens descartados\n", $checked, $skipped);
printf("CSS: public/css/app.css (%.1f KB)\n\n", filesize($cssPath) / 1024);

if ($missing === []) {
    echo "✓ Todas las clases estáticas del markup existen en el CSS compilado.\n";
    exit(0);
}

echo '✗ ' . count($missing) . " clase(s) del markup NO están en el CSS compilado:\n";
foreach (array_slice($missing, 0, 40) as $line) {
    echo "  - {$line}\n";
}
if (count($missing) > 40) {
    echo '  ... y ' . (count($missing) - 40) . " más\n";
}
echo "\nCorregir: agregar la clase al markup de forma literal o a `safelist` en tailwind.config.js,\n";
echo "y volver a correr `npm run build`.\n";

exit(1);
