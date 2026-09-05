## Context

La vista `app/resources/views/admin/storages.blade.php` define la totalidad de su lógica de UI dentro de un atributo Alpine `x-data="{ ... }"` que se extiende por ~395 líneas (del 6 al 401). Como Blade compila ese atributo a HTML literal y el navegador usa comillas dobles como delimitador, cualquier `"` que aparezca dentro del JS rompe la apertura del tag `<div>`.

El detalle concreto (línea 218):

```js
'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
```

Las dos comillas dobles alrededor de `csrf-token` cierran prematuramente el atributo `x-data` para el parser HTML. El resto del JS (incluido el `x-init` y el body de los métodos `loadStorages`, `createStorage`, etc.) se imprime como texto visible hasta que una `"` posterior lo "reabre" como pseudo-atributo. Resultado: la tabla se renderiza vacía y el usuario ve un muro de JavaScript.

(Ver `proposal.md` — Why para la motivación.)

## Goals / Non-Goals

**Goals:**
- Restaurar el render correcto de `/admin/storages` (tabla poblada, modales funcionales, tour disponible).
- Aplicar el fix mínimo e idempotente, sin alterar comportamiento JS.
- Auditar otras vistas admin que usen el mismo patrón para no dejar la misma bomba a punto de explotar.

**Non-Goals:**
- No refactorizar el `x-data` a `Alpine.data()` ni mover JS a archivos separados.
- No cambiar copy, textos, ni formato del tour guiado.
- No tocar lógica del backend.

## Decisions

### Decisión 1 — Forma del fix del selector CSRF

**Elegido:** reemplazar el selector por uno que no requiera `"` dentro de la cadena JS:

```js
'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
```

**Rationale:** los selectores de atributo CSS admiten valores sin comillas cuando el valor es un identificador válido (`csrf-token` cumple `[a-zA-Z_-]+`). El cambio es de 1 carácter por comilla, no introduce nuevos delimitadores y mantiene intacto el resto del JS.

**Alternativas consideradas:**
- **Template literal** (`document.querySelector(\`meta[name="csrf-token"]\`)`): más legible pero introduce backticks en una zona del archivo que ya tiene `"` que requieren escapar; suma cambio de superficie innecesario para un fix de 4 caracteres.
- **Invertir comillas externas a `"`** y usar `'csrf-token'`: requiere también cambiar el resto del bloque `headers: { 'X-CSRF-TOKEN': ..., 'Accept': ..., 'X-Requested-With': ... }` para mantener coherencia; más invasivo.
- **`document.head.querySelector("meta[name='csrf-token']")`** con `"` afuera: igualmente introduce otro delimitador (`'`) y no resuelve mejor.

### Decisión 2 — Auditoría de otras vistas admin

**Elegido:** ejecutar `grep -rn 'meta\[name="csrf-token"\]' app/resources/views/` como paso de la auditoría.

**Rationale:** el bug es invisible en revisión de código (parece JS válido) y rompe solo en runtime. Vale la pena barrer todas las vistas una vez.

**Salida esperada:** si aparece el mismo patrón en `storage-users.blade.php` u otra vista, aplicar el mismo fix (`meta[name=csrf-token]`) en cada sitio. Si está ausente, documentar en tasks.md.

### Decisión 3 — Sin tests automatizados nuevos

**Rationale:** no hay suite frontend en el repo (es Blade + Alpine.js sin build step). El "test" es manual: cargar `/admin/storages` autenticado como admin y confirmar que la tabla muestra storages y los modales abren. Esto queda registrado en tasks.md como criterio de aceptación.

## Risks / Trade-offs

- **Riesgo: pasar por alto otra `"` en una vista distinta** → Mitigación: la auditoría con `grep` cubre todas las vistas. Si el grep arroja falsos negativos (selectores equivalentes escritos distinto), el task de validación manual incluye revisar todas las vistas admin que carguen datos por fetch.
- **Riesgo: regresión silenciosa en lógica JS** → Mitigación: el cambio es puramente cosmético en el selector; el valor leído (`csrf-token`) y el comportamiento son idénticos. Probable blast radius: cero.
- **Trade-off: el patrón `meta[name=csrf-token]` sin comillas es menos legible** → aceptable: está dentro de una expresión única y se documenta el porqué en el comentario inline (opcional, no obligatorio para el fix).
- **Riesgo: el bug se repita en el futuro si alguien copia-pega el patrón roto** → mitigado por la auditoría; una mitigación más duradera (helper JS compartido o `Alpine.data()`) queda fuera del scope de este change.

## Migration Plan

No hay migración de datos ni pasos de deploy especiales. Es un cambio de un único archivo Blade que se sirve en cada request:

1. Editar `app/resources/views/admin/storages.blade.php` línea 218.
2. Ejecutar `php artisan view:clear` (o confiar en que Laravel recompila automáticamente).
3. Verificar manualmente en navegador autenticado como admin.
4. Si la auditoría encuentra el patrón en otras vistas, aplicar y limpiar caché por cada una.

**Rollback:** un solo `git revert` del commit. Sin efectos colaterales por ser un fix de plantilla.

## Open Questions

Ninguna. La decisión sobre la forma exacta del selector queda registrada en Decisión 1.
