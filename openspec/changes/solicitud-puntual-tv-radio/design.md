## Context

Ver `proposal.md` para el porqué. Lo que condiciona el diseño es lo siguiente, verificado contra el sistema en producción (2026-11-18):

- **`files` no tiene columna de duración.** Solo tiene `size` y `mime_type`. El único metadato de slot que sobrevive sin tocar disco es el **nombre del archivo**, que sigue el patrón `<slug>_DDMMYYYY_HHMMSS.<ext>` y es parseado por `App\Services\Ia\RecordedAt::fromFilename()`.
- **Coexisten dos formatos de `path` en `files`** para un mismo storage: el relativo al `base_path` (`14112026/archivo.mp4`) y un legacy relativo a la raíz Tcloud (`Disco_B/television/Canal_caracol/28082026/archivo.mp4`). Cualquier ancla por prefijo de `path` deja filas fuera.
- **La grilla de emisión no es uniforme** entre medios: 15 min en TV, 21 min en radio nacional, ~14 min irregular en radio regional, 20 min en el árbol NFS de producción. No hay constante posible.
- **El árbol de origen retiene 48–50 días por canal**; el árbol de producción (NFS `hard`, `192.168.0.131`) solo 13 y es un maestro distinto (otra bitrate y otra grilla). El árbol de origen es disco local y ya está catalogado.
- **Los sub-canonales de Emisoras 01/02/03/04 no son storages.** Viven dentro de un storage agrupado (`RD1/Antioquia/BLU`) y por eso no son direccionables. Sus carpetas-día **sí** existen en `files` como filas `is_folder=true`.
- **El sync de Mis Archivos recorre el árbol físico completo** de cada storage (`StorageSyncService::syncFolder`), sin saltar sub-árboles delegados.
- Ya existe un precedente explícito de rechazar el patrón "crear storages por sub-carpeta": la propuesta `solicitud-backup-mis-archivos` lo descartó porque "duplica filas de `files` y su navegación BD-first quedó vacía".

## Goals / Non-Goals

**Goals:**

- Cero movimiento, copia o duplicación de filas de `files`.
- Cero cambios en el sync de Mis Archivos y en el resto de módulos que consumen `storage_providers`.
- Un solo lugar donde vive la verdad de una entrega: la solicitud, con detalle por archivo.
- Que el módulo cliente sea trivial de entender: un medio, una fecha, un rango, un registro.
- Que el control contable sea una consulta agregada, no una revisión manual.

**Non-Goals (nivel diseño):**

- No se modelan tarifas, precios ni facturación automática.
- No se introduce un sistema de aprobación ni cola de revisión.
- No se extiende la ventana de retención del material.
- No se toca `PublicShareController` ni el contrato de la URL pública `/s/{token}`.

## Decisions

### D1 — El medio es un índice `(storage_id, subpath)`, no un storage

**Decisión.** Tabla nueva `medio` con `storage_id` + `subpath` + `name` + `media_kind` + `region`. El medio no es un `storage_providers`; es una vista direccionable sobre `(storage, ruta interna)`.

**Por qué.** Los sub-canonales de RD1/RD4/RD6 (≈84) viven dentro del `base_path` de un storage contenedor. Crearles un storage propio tiene dos consecuencias duras:

```
  storage 49  (base_path = .../RD1)                 ← contiene al nuevo
  storage 999 (base_path = .../RD1/Antioquia/BLU)    ← el nuevo

  El cron `storage:sync --all` (15 min) recorre el árbol FÍSICO del 49
  y vuelve a crear:  49 → Antioquia/BLU/17112026/x.m4a
  mientras el 999 crea: 999 → 17112026/x.m4a
  ══► DUPLICACIÓN del catálogo + navegación BD-first huérfana
```

Evitarlo exigiría tocar el sync del núcleo (único escritor de `files`), que es exactamente el cambio caro que este repo ya pidió una vez.

**Alternativas descartadas.**
- *Derivar el catálogo por heurística* (`storage hoja` + `mime video/audio`): no cubre los sub-canonales (no son storages), arrastra basura (`00 Discos`, `30 Television Bk`, prensa, los 13 `[obs_*]`) y depende de un `GROUP BY mime_type` sobre 1,36 M filas. Se usa **solo** como semilla del bootstrap, no como fuente.
- *Duplicar el medio en `storage_providers`*: rejection explícito ya documentado en el change de backup.

**Consecuencia.** `files` sigue siendo la tabla soberana y Mis Archivos su único escritor. El medio no duplica contenido: es un índice.

### D2 — El ancla del material es el nombre del archivo, no el `path`

**Decisión.** Localizar los archivos de un medio en una fecha por el storage + el nombre (`name ~ '_{DDMMYYYY}_[0-9]{6}\.'`) filtrando por sufijo de ruta (`path LIKE '%/{subpath}/{DDMMYYYY}/'`).

**Por qué.** El nombre lleva la fecha y la hora de inicio, funciona igual en los tres layouts físicos (plano, agrupado por subcarpeta, agrupado por slot) y sobrevive a los dos formatos de `path` coexistentes. El `path` falla en los dos casos.

**Costo.** El filtro por sufijo no usa índice. Mitigación: la consulta está acotada por `storage_provider_id` (índice `idx_files_storage_id`); los storages que participan como medios tienen entre 2 k y 45 k filas, y los contenedores (`00 Discos`, 212 k filas) nunca son medios. La verificación en disco posterior se hace solo sobre los archivos del rango pedido (≤ 20 archivos).

### D3 — El paso de la grilla se deriva del medio, no se configura

**Decisión.** Para un medio y una fecha, el paso `S` es la **mediana de los deltas** entre inicios de captura consecutivos de ese día. El slot nominal de un archivo es `floor(inicio_real / S) * S`.

**Por qué.** Medido en producción:

```
  01 Caracol Tv           15 min    96 slots/día
  Caracol Radio Bogotá    21 min    96 slots/día
  Antioquia/BLU (reg.)  ~14 min    irregular (13:59 / 14:01 / 14:00)
  producción NFS          20 min    72 slots/día
```

Una constante global (15 min, el valor de TV) produce una cobertura falsa en los tres otros casos. La mediana es robusta a los huecos puntuales.

**Por qué slot nominal y no el inicio real.** El grabador arranca el archivo siguiente ~13 s después de que termina el anterior: los archivos se solapan y el inicio real nunca cae exactamente en la rejilla. El inicio real es *cuándo se capturó*; el slot nominal es *cuándo se emitió*. Un pedido de "12:00 a 13:00" significa la rejilla de emisión, no el instante físico del primer byte.

**Validación de cobertura.** Se listan los slots nominales whose slot cae en `[r0, r1)`. Los slots sin archivo se reportan como faltantes. La cobertura es `completa` si no falta ninguno, `parcial` si falta alguno. El intervalo de captura real de cada archivo se toma de `file_modified_at` (fin de escritura), ya corregido de la deriva de zona horaria por `App\Support\FilesystemTime`.

### D4 — La disponibilidad se comprueba en disco, nunca solo en el catálogo

**Decisión.** Tras resolver por catálogo, cada archivo candidato del rango se verifica contra el sistema de archivos antes de incluirlo. Si el catálogo tiene la fila pero el archivo no existe, se trata como no disponible.

**Por qué.** El catálogo arrastra registros fantasma (el restore de agosto 2026 dejó filas de carpetas que ya no existen) y el árbol rota: una franja de hace 40 días puede tener filas pero no material.

### D5 — La entrega por franja genera N enlaces; la entrega por día genera 1

**Decisión.**

```
  MODO FRANJA                              MODO DÍA
  ──────────────────────────────────       ─────────────────────────────────
  1 enlace por archivo exacto              1 enlace sobre la carpeta del día
  N tokens, N vencimientos                 1 token, 1 vencimiento
  N revocaciones                           1 revocación
```

**Por qué no un enlace de carpeta para la franja.** La carpeta del día contiene las 96 capturas (≈18 GB en TV). Servirla para un pedido de una hora entrega material que el cliente no pidió y que no pidió pagar.

**Por qué un enlace por archivo y no un enlace "playlist".** Un enlace playlist exigiría una ruta pública nueva, un controller y una vista, con el riesgo de rutas huérfanas que ya mordió dos veces al repo. El cliente recibe una lista de enlaces en el historial, que es lo que necesita para descargar. La optimización (un solo enlace) queda fuera de alcance y es un follow-up natural.

**El detalle por archivo se registra igual en modo día.** La carpeta del día se sirve al cliente, pero la solicitud registra el inventario de esa carpeta (nombre, ruta, bytes) para que el control contable no dependa de que las filas sobrevivan.

### D6 — La solicitud es la fuente de verdad; los enlaces son derivados

**Decisión.** `solicitud_puntual` + `solicitud_puntual_item` (con snapshot de `name`, `path`, `size`) son la verdad. Los `shares` son artefactos que se pueden recrear, igual que en `backup_requests`.

**Por qué.** `shares.file_id` es FK `ON DELETE CASCADE`: cuando el prune borra la fila de `files`, el enlace muere solo. El control contable debe sobrevivir a eso, y la vigencia/revocación se administran desde la solicitud.

### D7 — El guard de compartidos se generaliza en vez de duplicarse

**Decisión.** `ShareController::isBackupManaged()` pasa a `isRequestManaged()`: una relación polimórfica opcional — `backupRequest` o `solicitudPuntualItem` — resuelve ambos casos. La purga masiva usa la misma consulta de exclusión (`managedIds()`) y reporta `protected_count` / `protected_ids`.

**Por qué.** El módulo de backup ya resolvió el problema de la escalada (`PUT {"expires_at": null}` convertía el enlace de 2 días en permanente). Reimplementar el guard para el flujo puntual dejaría dos caminos con reglas distintas.

**Corrección obligatoria mientras se está ahí.** `BackupRequestService` usa `catch (QueryException $e)` **sin** el `use Illuminate\Database\QueryException`. En PHP eso resuelve a `App\Services\MisArchivos\QueryException`, que no existe: una violación real de `23505` no entra al catch y sube como 500 en lugar de devolver la solicitud ganadora. Se corrige como parte de este change (mismo archivo, mismo patrón de idempotencia).

### D8 — La idempotencia la garantiza un índice único parcial

**Decisión.** Índice único parcial `(user_id, medio_id, fecha, hora_inicio, hora_fin) WHERE status IN ('ready','partial')`, con captura del `23505` para devolver la solicitud ganadora.

**Por qué.** Dos POST simultáneos para el mismo rango no pueden generar dos juegos de enlaces. Mismo patrón que `idx_backup_requests_unique_live`.

### D9 — El módulo vive bajo "Compartidos" y el control contable en Administración

**Decisión.** `/solicitud-puntual` entra en el grupo cliente del sidebar, inmediatamente después de "Compartidos", con el nombre "Solicitud Puntual". `/admin/medios` entra en el grupo "Administración".

**Por qué.** La salida del módulo es un enlace → es familia de "Compartidos". Su entrada es un catálogo segmentado por storages → familia de "Mis Archivos". El grupo "Multimedia" tiene hoy otro eje (procesar y analizar medios: Medios Puntuales, Mis Avisos); meterle un flujo de entrega y cobro mezcla dos ejes.

**Por qué no "Solicitudes" a secas.** El backup ya introduce el concepto "solicitud" dentro de Mis Archivos (tercera pestaña). "Solicitud Puntual" los distingue sin ambigüedad.

**Nombres.** El nombre del medio se propone como `<canal> · <región>` a partir de la carpeta regional (`RD1/Antioquia/BLU` → "BLU · Antioquia"), y es editable por el administrador antes de confirmar el remapeo. La región es lo que desambigua los homónimos (`Antioquia/Caracol` vs `Radios/Bogota/CARACOL`).

### D10 — La opción de día se ofrece por umbral configurable, no por regla fija

**Decisión.** `config/puntual.php` → `umbral_dia_minutos` (120 por defecto). Cuando la franja supera el umbral, la interfaz muestra la comparación (nº de enlaces, nº de archivos, material de cada alternativa) y señala el día, sin bloquear la franja.

**Por qué un umbral y no el punto de equilibrio de una tarifa.** No hay tarifas en el sistema y no se introducen. El umbral captura la misma intuición —"por encima de dos horas el día sale más barato" — en términos operativos (nº de enlaces que hay que emitir, vencer y revocar) y queda en configuración para poder ajustarlo sin desplegar.

## Risks / Trade-offs

- **[El enlace de un medio no asignado expone material que el cliente no puede leer en la plataforma]** → El acceso es por URL pública con vigencia y sin credenciales, lo que es un escalón por encima de la lectura directa. Mitigación: el enlace expira en 3 días, es revocable, el guard impide volverlo permanente, y se registra quién pidió qué y cuándo. **Riesgo alto: exige verificador independiente antes de cerrar.**
- **[La cobertura parcial se entrega sin el hueco]** → El sistema cuantifica los slots faltantes y los muestra en el registro. Si el cliente necesita exactitud, la franja no se sirve. Mitigación: mostrar el hueco en el modal antes de confirmar.
- **[La consulta de cobertura usa un filtro por sufijo de `path` y no aprovecha índice]** → Está acotada por `storage_provider_id` y los storages participantes son de 2 k–45 k filas. Mitigación: medir en el harness; si el tiempo lo justifica, agregar un índice funcional sobre `path` reversed.
- **[El.bootstrap de medios crea ~190 filas que hay que mantener]** → El botón de remapeo cubre el alta; la baja y la edición se hacen en la misma pantalla. Mitigación: los medios inactivos no aparecen en el listado del cliente.
- **[Los sub-canonales adicionales cambian el conteo de `files` por storage si alguien los indexa como storage]** → El botón de remapeo solo crea filas en `medio`; no toca `storage_providers` ni `files`. Mitigación: el harness verifica que el conteo de `files` no cambia.
- **[El tree de `00 Discos` (212 k filas) hace que un `LIKE '%/…'` accidental sea lento]** → Los contenedores no son medios y la consulta siempre filtra por `storage_provider_id` primero. Mitigación: verificar tiempo en el harness con el storage más grande.
- **[El `share_id` por slot multiplica las filas de `shares` (96 por solicitud en modo día)** → En modo día solo hay 1 enlace (el de la carpeta); los items no llevan `share_id`. Las filas de `solicitud_puntual_item` sí son 96, y eso es deliberado: es el registro contable.
- **[El historial del cliente se mezcla con sus enlaces en "Compartidos"** → Los enlaces de entrega aparecen en `/shares` porque `created_by` es el solicitante. Decisión: se mantienen visibles (el cliente puede reenviarlos) y el guard los blinda. Si molesta, un campo `managed_by` en `shares` permitiría filtrarlos; queda como follow-up.

## Migration Plan

1. **Migración de esquema** (aditiva, reversible): `medio`, `solicitud_puntual`, `solicitud_puntual_item` + índices + FKs `ON DELETE SET NULL` hacia `files` y `shares`, `CASCADE` hacia `storage_providers` y `users`. Índice único parcial de idempotencia.
2. **Seed de medios**: comando `medios:bootstrap` que siembra el catálogo a partir de los storages hoja con media (39 TV + 67 Radio) usando la heurística leaf + mime, **solo** como punto de partida. No crea storages ni toca `files`.
3. **Remapeo de contenedores**: `medios:remapear --storage=49 --dry-run` y luego `--apply --yes`, uno por contenedor (47, 49, 53, 55). El storage 55 es NFS: se aplica con la guarda de montaje activa.
4. **Generalización del guard** en `ShareController` (`isBackupManaged()` → `isRequestManaged()`), más la corrección del `use` faltante en `BackupRequestService`.
5. **Config**: `config/puntual.php` (vigencia 3 días, umbral 120 min, longitud de motivo).
6. **Despliegue**: los módulos son nuevos; no hay migración de datos de `files` ni de `storage_providers`. El rollback es `git revert` + `php artisan migrate:rollback --step=N` (las tres tablas son aditivas y no tienen dependencias de datos externos). Los enlaces ya emitidos sobreviven al revert como filas de `shares` huérfanas de gestión: se revoican desde el flujo de backup o se acepta su vencimiento natural de 3 días.

## Open Questions

- Si el material de un canal debe llegar **desde el árbol de origen** (48–50 días, local) o **desde el árbol de producción** (13 días, NFS, grilla de 20 min, maestro distinto). La decisión actual es origen; si el operador cambiara de opinión, cambia la especificación de disponibilidad y el guard de montaje, no el resto de la arquitectura.
- Si el umbral de recomendación del día debe derivarse de una tarifa cuando exista, o seguir siendo un valor de configuración. Hoy es configuración; no bloquea ninguna tarea.
