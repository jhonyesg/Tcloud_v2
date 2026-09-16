# Design — El botón «Actualizar» reconcilia la BD con el disco

## El problema de fondo: una guarda no puede distinguir rotación de avería

`PruneGuard` nació del incidente del 2026-07-27, donde un NFS caído devolvió un escaneo vacío y el sincronizador borró el árbol completo. Su regla de proporción parte de una premisa razonable: *que desaparezca de golpe la mayor parte de una carpeta es más probablemente una avería que un borrado real*.

Esa premisa **es falsa para los storages de prensa**. El disco conserva ~2 días por rotación diaria y la BD lleva meses acumulando:

```
Disco (205 Nación)          BD
20260819/                   118 filas
20260820/                   ↑ 114 de ellas apuntan a carpetas-día
Nacion_20260820.pdf           que la rotación ya borró
Nacion_20260820_Texto.pdf
```

Desde dentro de una sola carpeta, «el 96% desapareció porque así funciona la rotación» y «el 96% desapareció porque el disco no responde» son **indistinguibles**. Ninguna heurística sobre conteos las separa.

Lo que sí las separa es **quién lo pidió**. Un cron que barre 175 storages no tiene contexto; una persona parada en una ruta concreta, mirando la pantalla, sí lo tiene. Por eso la decisión de diseño es no ajustar el umbral —cualquier valor que deje pasar un borrado del 96% desactiva la regla— sino **cablear la señal de intención** que ya existía en la cadena desde julio: `forcePrune`.

## Decisión 1 — `prune=1` separado de `sync=1`

`silentSync()` se dispara en cada navegación entre carpetas y usa `sync=1`. Si el botón hubiera reutilizado ese mismo parámetro, la purga forzada habría pasado a ocurrir **de fondo, sin intención humana** — justo lo contrario de lo que la hace segura.

```
[Actualizar]  → GET /files?sync=1&prune=1  → forcePrune: true   → salta reglas 2 y 3
navegación    → GET /files?sync=1&nb=1     → forcePrune: false  → guardas intactas
cron          → storage:sync --all         → forcePrune: false  → guardas intactas
```

**Alternativa descartada**: una ruta dedicada `POST /files/resync`. Más limpia en teoría, pero obligaba a duplicar la resolución de `breadcrumbs`, permisos y paginación que `index()` ya hace. El parámetro extra cuesta una línea y no crea una segunda forma de listar archivos.

## Decisión 2 — El permiso `full`, no `read`

Hoy cualquier usuario con `read` puede disparar `sync=1`. Eso era inocuo cuando el sync solo creaba filas. Con la purga cableada deja de serlo: `shares.file_id` y `transcriptions.file_id` son `ON DELETE CASCADE` y `files` no tiene soft deletes.

Se reutiliza `User::hasStoragePermission($id, 'full')` (nivel 3 de la escala existente `read=1, write/upload=2, full=3`) en vez de introducir un permiso nuevo. Si el usuario no lo tiene, el sync se ejecuta igual pero sin forzar, y el toast se lo dice.

## Decisión 3 — `syncFolderWithReport()` en vez de estado en el servicio

El servicio necesitaba devolver dos cosas: el listado (que cuatro llamadores ya consumen) y lo que hizo. Tres opciones:

| Opción | Por qué no / sí |
|---|---|
| Propiedad `$lastStats` en el servicio | Funciona sin Octane, pero deja estado compartido en un singleton. Frágil ante un cambio de runtime. |
| Cambiar el retorno de `syncFolder()` | Rompe `PublicShareController`, `ApiTranscriptorController`, `fullSync` y el autoscan. |
| **Método nuevo, el viejo delegando** | Sin estado, sin romper a nadie, y el llamador elige cuánto quiere saber. **Elegida.** |

`status` nombra las siete salidas —`synced`, `locked`, `mount_detached`, `scan_untrusted`, `sync_disabled`, `path_missing`, `unknown_folder`— porque **cinco de ellas devolvían el listado de BD tal cual**, indistinguible de un sync limpio. Ese era el segundo bug, más silencioso que el primero: el toast verde afirmaba algo que no había ocurrido.

## Decisión 4 — El lock espera solo en el camino manual

El lock no bloqueante de julio es correcto y se conserva: N peticiones concurrentes deben producir **un** escaneo y N listados baratos, no N escaneos en cola. Pero con el cron cada 15 minutos y un `silentSync` por navegación, la probabilidad de que el clic caiga sobre un lock tomado no es despreciable — y el usuario está mirando.

`block(3)` solo cuando `$forcePrune` es true. Si vence, `status: 'locked'` y el toast lo dice en ámbar, en vez de fingir éxito.

## Decisión 5 — Declarar los volúmenes locales como montajes esperados

`MountGuard::isExpectedMountMissing()` **solo protege rutas declaradas** en `storage_sync.mounts.expected`, por diseño: sin la declaración no se puede distinguir «montaje caído» de «directorio local normal», y suponerlo sería peor que no comprobar nada.

En julio se declararon los cinco NFS. Pero `Disco_A`, `B`, `C` e `I` son montajes de bloque independientes (`/dev/mapper/… ext4`) y **concentran el grueso de los datos**:

| disco | transcripciones | declarado antes |
|---|---|---|
| Disco_A | 108.396 | no |
| Disco_I | 65.765 | no |
| Disco_B | 35.418 | no |
| Disco_C | 28.242 | no |

Un ext4 que no monta tras un reinicio deja el punto de montaje como directorio vacío y legible — **idéntico a un NFS caído**. Sin esta declaración, cablear la purga forzada al botón habría creado exactamente la vía para repetir el incidente de julio, esta vez a mano y sobre los discos con más que perder.

Por eso este punto es **prerequisito de la decisión 1, no un extra**: es lo que hace que forzar la purga sea defendible.

## Lo que no cambia

`scan_untrusted` sigue siendo inmune a `forced`, en `PruneGuard:38`. Un escaneo parcial —una sola entrada con EIO en la carpeta— basta para que `$scan->ok` sea `false` y no se borre nada, por mucho que el usuario pulse el botón. Lo que no se pudo leer no puede contarse como desaparecido del disco, y esa regla no admite excepción por intención humana: la intención no arregla un disco que no responde.
