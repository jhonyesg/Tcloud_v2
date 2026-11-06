## Context

Ver el estado actual en `proposal.md` para el porqué. Lo que condiciona el diseño:

- **Dos árboles con la misma forma.** Origen: `Tcloud/Disco_{A,B,C,E,F,H,I}/.../<canal>/<DDMMYYYY>/*.mp4`. Backup: `Tcloud/Disco_D/backup/<NN_Canal_BK>/<DDMMYYYY>/*.mp4`. `Disco_D` es un export NFS de `192.168.0.131:/media/difusor02/Backup_TV` (13 T, 62 % usado) producido por el sistema de difusor02. Tcloud no lo genera ni lo debe generar.
- **Los nombres de los dos lados no se corresponden.** `09 Redmas` ↔ `05_Redmas`; `05 Institucional` ↔ `22_Institucional_BK`; `03 City Tv` (`CityTv`) ↔ `03_City_BK`. Hay ~28 parejas y ninguna se deriva.
- **El árbol de backup ya está indexado** en `files` bajo el storage 34 (`Disco_D/backup`), que tiene exactamente 28 carpetas raíz — una por canal. `01_Caracol_BK/05112026` es la fila 8619379.
- **El mismo material físico está indexado dos veces.** `files` tiene índice UNIQUE `(storage_provider_id, path)`, así que el mismo archivo puede existir como filas de storages distintos. Concretamente: storage 34 tiene `01_Caracol_BK/24102026…05112026` (8.707 filas en total), y el storage 5 (`00 Discos`, raíz `Tcloud`) tiene 189 filas `Disco_D/backup/01_Caracol_BK/**` con **otro rango de fechas** (18/19/20 oct). Catálogo paralelo, no copia.
- **Ya existe un intento del patrón contrario**: el storage 131 (`35 Red Mas BK`, `Disco_D/backup/05_Redmas`, `parent_storage_id = 34`) es la carpeta de backup de Redmas registrada como storage. Sus 1.657 filas usan rutas relativas a su propio `base_path`, y el storage 34 conserva 74 filas remanentes bajo `05_Redmas%`. El listado BD-first (`FileController::index:176-196`) filtra estricto por `storage_provider_id + parent_id` y **no** implementa la unión cross-storage que el spec `files-mis-archivos-sub-storage-listing` exige; el resultado es que navegar `Disco_D/backup → 05_Redmas` hoy muestra las 74 filas del padre y **no** las 1.657 del hijo.
- **El árbol rota.** El backup conserva ~13 carpetas-día rodantes (`24102026…05112026`); el origen tiene 50. Una solicitud de un día de septiembre no se puede satisfacer.
- **`shares.file_id` es FK `ON DELETE CASCADE`.** Cuando la carpeta-día sale del disco, el prune del sync borra su fila de `files` y el share del cliente muere solo, dejando un enlace 404 sin aviso.
- **Restricciones del proyecto**: sesión siempre vía `session('user_id')`; `files` es tabla soberana de Mis Archivos (solo `StorageSyncService`/`FileRegistry` y acciones de usuario escriben en ella); el listado BD-first corre por defecto (`MIS_ARCHIVOS_FS_PRIMARY=false`); toda ruta registrada debe resolver a un método de controller existente (`harness_route_contract.php`); toda stat sobre NFS debe pasar por `MountGuard` (los mounts son `hard`: un `stat()` colgado bloquea PHP-FPM).

## Goals / Non-Goals

**Goals:**

- Declarar el vínculo origen → backup en un solo lugar, sin registros duplicados y sin mover una sola fila de `files`.
- Reutilizar el mecanismo de compartidos existente (enlace público con token, expiración, log de accesos, vista de carpeta) en vez de inventar un canal de entrega nuevo.
- Que el cliente pueda pedir, ver y revocar sus solicitudes desde Mis Archivos.
- Que ninguna escritura física toque el árbol de producción.

**Non-Goals:**

- No se amplía la retención del backup ni se altera el proceso de difusor02.
- No se implementa la unión cross-storage del spec `files-mis-archivos-sub-storage-listing` (es un gap preexistente, ortogonal a este change).
- No se migra ni se limpia el storage 131 ni las 189 filas duplicadas del storage 5: son deuda previa, y tocarlas en este change mezclaría dos riesgos.
- No se agrega ningún tipo de storage nuevo ni se toca `ShareController::store()`.

## Decisions

### D1 — El vínculo se declara en el storage de origen

`storage_providers` gana `backup_storage_id` (FK → `storage_providers.id`, nullable, `ON DELETE SET NULL`) y `backup_folder_name` (varchar, nullable). El par se resuelve con `backup_storage_id = <origen>.backup_storage_id` y la ruta relativa del día = `<backup_folder_name>/<DDMMYYYY>`.

**Alternativa descartada: un storage por carpeta de backup** (el patrón de 131), con `backup_of_storage_id` en el storage de backup. Se descartó por tres razones medidas:

1. Exige mover ~8.700 filas de `files` del storage 34 a 28 storages nuevos, reescribiendo `path` (el índice UNIQUE es `(storage_provider_id, path)`; cambiar `storage_provider_id` sin reescribir `path` no es posible, y reescribirlo invalida la fila).
2. Como el listado BD-first no hace unión cross-storage, la navegación de `Disco_D/backup → 01_Caracol_BK` quedaría vacía salvo que se implemente el gap del spec `files-mis-archivos-sub-storage-listing` (que es exactamente el estado ya roto de Redmas).
3. Reintroduce la duplicación de catálogo que el proyecto eliminó en agosto 2026 (`files.canonical_folder_id`, `merged_into_id` y toda la familia mirror, retirada).

Con D1 no se crea ninguna fila, no se mueve ninguna y la navegación del árbol de backup queda idéntica a hoy.

**Coste que D1 acepta**: el vínculo se declara "hacia adelante" (origen → backup) y no "hacia atrás" (backup → origen). Para el caso de uso — el cliente pide el backup de lo que ya puede leer — el sentido del vínculo no importa.

### D2 — La resolución se ancla en `backup_storage_id`, nunca por nombre

La fila del día se busca por `(storage_provider_id = backup_storage_id, path = "<backup_folder_name>/<DDMMYYYY>")`. Prohibido resolver "la carpeta que se llame X" cruzando storages: hay 189 filas `Disco_D/backup/01_Caracol_BK/**` en el storage 5 con un rango de fechas **distinto** (18/19/20 oct) y el mismo material físico bajo el storage 34 con otro rango (24 oct–5 nov). Una resolución por nombre devolvería la carpeta-día vieja.

**Contención**: la ruta absoluta resuelta debe caer dentro del `base_path` del storage de backup. La comparación es **por cadena**, sin `realpath()`: los mounts son `hard` y un `realpath()` sobre un NFS caído bloquea PHP-FPM. Se usa el mismo criterio de `FilesystemListingService` (`resolveAbsolutePath` + comparación de strings).

### D3 — La resolución no escribe catálogo; el módulo que escribe `files` sigue siendo Mis Archivos

Si la fila del día no existe en el catálogo, la resolución **no la inserta**. Se pide al cliente que use "Actualizar", o el servicio invoca `StorageSyncService::syncFolder($backupStorage, $parentFolderId)` — el camino canónico del módulo — para materializarla. Nunca un `INSERT` directo.

Justificación: el spec `module-independence-boundary` establece que `files` es tabla soberana de Mis Archivos y solo `StorageSyncService` (vía `FileRegistry::ensure()`) y las acciones de usuario escriben en ella. Este change vive **dentro** de Mis Archivos, así que escribir a través del sync es lícito; escribir a través de otro camino no lo sería.

### D4 — El share lo crea un servicio interno, no `ShareController::store()`

`ShareController::store()` autoriza con `read` sobre el `storage_provider_id` del archivo (línea 442-449). El cliente tiene `read` sobre el storage **origen** y ninguno sobre el de backup, así que la ruta normal daría 403. Dos salidas posibles:

- Debilitar `store()` para admitir un caso especial → **descartada**: abre una vía de escalada en el endpoint más usado del sistema.
- Un servicio dedicado que crea el `Share` con su propia autorización → **elegida**.

`BackupRequestService` valida: (a) el usuario tiene `read` sobre el storage de origen; (b) la carpeta es una carpeta-día; (c) el origen tiene backup mapeado; (d) la carpeta-día existe en el árbol de backup. Recién entonces crea el `Share` con `permissions = 'read'`, `expires_at = now + vigencia` y `created_by = usuario`. Se reutiliza la tabla `shares` completa, con su token, su expiración, su `share_access_log` y su vista pública de carpeta — no hay un canal de entrega nuevo que mantener.

### D5 — `backup_requests` es la fuente de verdad; el share es derivado

El share se puede morir solo (`ON DELETE CASCADE` sobre `files.file_id` cuando la carpeta-día rota fuera del árbol). Si el historial se construyera sobre `shares`, el cliente vería un enlace que un día responde 404 sin explicación. Por eso hay una tabla propia:

```
backup_requests
  id · user_id · source_storage_id · source_folder_id · day (DDMMYYYY)
  motivo (text) · status (ready|unavailable|expired|revoked)
  backup_storage_id · backup_path · share_id (FK null)
  link_expires_at · created_at · updated_at
```

El historial se arma desde `backup_requests`. El enlace se muestra solo si `status = ready` **y** el share sigue vivo **y** no expiró. Si la carpeta-día desaparece, unajob de reconciliación (o el propio listado) marca la solicitud como no disponible con la explicación, en vez de servir un 404.

### D6 — Vigencia: 2 días calendario, configurable

`config/backups.php` con `link_validity_days` (default 2) y `min_motivo_length` (default 10). La vigencia se aplica en días calendario desde la creación.

Se descartó el conteo en días hábiles: no existe helper en el repo (ni `Carbon\BusinessDay`), y el calendario de festivos colombianos implica una lista que alguien tiene que mantener año a año. Queda como cambio de una línea si más adelante hace falta.

### D7 — El cliente no recibe permisos sobre el storage de backup

La solicitud **no** crea filas en `user_storages` para el storage de backup. Consecuencia útil: el árbol de backup **no aparece** en el listado de storages del cliente (ese listado sale de `user_storages`), sin necesidad de una bandera de visibilidad nueva. El acceso al material de producción es exclusivamente el enlace temporal.

Corolario: el enlace de backup del cliente **sí** aparece en su módulo de Compartidos (`/shares`), porque `shares.created_by = usuario` y ese listado es owner-scoped. Es un efecto lateral aceptable: el enlace es del cliente y su revocación se hace desde el historial de solicitudes.

### D8 — El historial vive dentro de Mis Archivos, como tercer `viewMode`

`viewMode` pasa de `'storages' | 'files'` a `'storages' | 'files' | 'backups'`, con su propio `x-if` en la cabecera. No es una ruta nueva: el módulo es una sola vista Blade con un único componente Alpine (`fileManager()`), y un tercer modo reusa el shell, el toast, el `apiFetch` y la persistencia de navegación sin duplicar andamiaje.

### D9 — Idempotencia: una solicitud viva por (usuario, storage origen, día)

Re-solicitar el mismo día con una solicitud aún viva devuelve la existente (mismo enlace, mismo vencimiento) en lugar de crear un segundo enlace. Re-solicitar una solicitud expirada o revocada crea una nueva. Evita que un clic repetido multiplique enlaces públicos sobre el master de producción.

### D10 — Disponibilidad: se consulta antes de enviar

El modal pide la ficha resuelta (canal, fecha, ruta del backup, estado, vencimiento) a un endpoint de elegibilidad antes de permitir confirmar. El cliente ve "el backup de este día aún no está disponible" o "este día ya salió de la ventana de retención" **antes** de escribir el motivo, no después.

### D11 — El botón se decide en el servidor

El criterio de elegibilidad (carpeta-día + mapeo existente) se resuelve en un único lugar del servidor. La vista solo lo refleja. No hay dos definiciones de "qué es una carpeta-día" que puedan divergir.

### D12 — Taxonomía de "no disponible"

Dos causas distinguibles, porque el cliente necesita saber si reintentar: `backup_not_ready` (el día aún no está en el árbol de backup — la producción va con retraso) y `backup_expired_window` (el día salió de la ventana de retención rodante). La tercera causa posible, `no_mapping`, impide siquiera mostrar el botón.

## Risks / Trade-offs

**[Escalada de privilegio]** El cliente obtiene, vía enlace público sin autenticación, el master de producción de un canal que en la plataforma solo puede leer en calidad reducida → **Mitigación**: alcance acotado (solo el backup mapeado de ese storage, solo lectura, 2 días, un enlace activo por día y usuario), autorización explícita en el servicio (`read` sobre el origen), auditoría con actor, y verificador independiente antes de cerrar. Riesgo de ítems altos 2 y 3 del protocolo ODD → exige revisión independiente.

**[Rutas públicas sin throttle]** `/s/{token}` no tiene middleware de rate limiting. Un enlace de backup añade una ruta pública que sirve el master → **Mitigación**: `read` en el share (no `write`/`upload`), que es lo que impide subir o borrar por el enlace público; el log de accesos (`share_access_log`) queda auditable; la expiración acota la ventana.

**[El share puede morir con el material]** La rotación de 13 días borra la fila `files` y, en cascada, el share → **Mitigación**: `backup_requests` sobrevive (D5) y el historial reporta la caída en lugar de servir un 404.

**[`files` con catálogo paralelo]** La resolución ancla en `backup_storage_id` (D2), pero un humano que busque "la carpeta 05112026" puede encontrar la fila del storage 5 (rango viejo) → **Mitigación**: la resolución nunca busca por nombre; la UI nunca muestra rutas del storage 5 para este flujo.

**[`shares` duplicados visibles en /shares]** El enlace de backup aparece en el módulo de Compartidos del cliente, junto a sus shares normales → **Mitigación**: el payload ya incluye el path del archivo; se acepta como comportamiento coherente (es un enlace suyo). Si molesta, se puede marcar con una columna y filtrar, pero eso tocaría el spec `share-management` y queda fuera.

**[El backup va con retraso]** La carpeta-día en curso existe en el backup a medida que el encoder escribe; el material del día no está completo hasta el día siguiente → **Mitigación**: estado `unavailable` + `backup_not_ready` con la explicación en el modal, y el enlace no se crea hasta que la carpeta existe.

**[Motivo vacío o de lush]** El motivo es texto libre del cliente → **Mitigación**: longitud mínima, y el motivo se escapa por Blade (escándalo XSS) y no se interpola en HTML ni en correos.

**[La ruta del backup es un NFS]** `stat()` sobre un mount caído con política `hard` cuelga PHP-FPM → **Mitigación**: toda verificación de existencia pasa por `MountGuard` (`detachedAncestor`) antes de tocar el path; si el mount está caído, la solicitud se marca no disponible en vez de colgar.

## Migration Plan

1. **Migración de esquema** (aditiva, reversible):
   - `storage_providers`: `backup_storage_id` (bigint nullable, FK `ON DELETE SET NULL`, índice parcial) + `backup_folder_name` (varchar(120) nullable).
   - Tabla `backup_requests` con sus FKs e índices por `(user_id, created_at DESC)` y `(user_id, status)`.
2. **Backfill del mapeo**: poblar `backup_storage_id` / `backup_folder_name` para los ~28 storages de origen con equivalencia confirmada en el árbol de backup. Es **datos**, no código: se hace con un comando artisan idempotente en dry-run por defecto, que lista los 28 pares candidatos (origen ↔ carpeta de backup) y solo escribe si el par existe en disco. La lista se valida contra el árbol real antes de aplicar; los pares dudosos se dejan sin mapear (y el botón simplemente no aparece).
3. **Deploy**: migración + código. La feature queda inerte hasta que haya mapeos configurados.
4. **Rollback**: `git revert` + `migrate:rollback --step=2`. Las dos columnas y la tabla son aditivas; no hay rollback de datos que deshacer más allá de volver a quitar el mapeo. Las solicitudes ya creadas quedan sin su share asociado si se borra la tabla → el enlace público sobrevive hasta expirar por su cuenta, porque `shares` es una tabla distinta.
5. **Guardarraíles**: `tests/harness_backup_requests.php` (contrato del flujo) y `tests/harness_route_contract.php` (toda ruta nueva debe resolver a un método real).

## Open Questions

- **Quién es el destinatario del motivo.** Hoy el motivo queda en la base y en el log. ¿Debe llegar al operador por correo (`NotificationService`) o a un panel de bandeja? Afecta solo al aviso, no al contrato de la solicitud.
- **Longitud mínima del motivo.** Propuesta de 10 caracteres; se ajusta con la realimentación de los primeros usuarios.
- **Si el backup de un canal no tiene equivalencia y el cliente insiste.** Hoy el botón no aparece. Si el operador quiere habilitarlo "a pedido" (mapeo manual caso por caso), eso es una fila más en la misma tabla, no un cambio de diseño.
