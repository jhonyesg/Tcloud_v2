# Feature: Repuntar los storages de Disco_E y Disco_H a su ruta real

## Specs

- **S1 — El vínculo storage → disco se corrige con evidencia, no por suposición.** Los dos exports
  NFS se reorganizaron en el servidor: `Disco_E` (`Tv_regional`) ahora monta `RD4/` + `backup/`
  y `Disco_H` (`radios2`) monta `Emisoras/` + `RD6/` + `Rlocal/`. 27 storages apuntan a rutas
  que ya no existen.
- **S2 — Cada storage se repunta a una ruta verificada.** La ruta destino se comprueba contra el
  disco ANTES de escribir: si no existe, el remapeo no se aplica para ese storage.
- **S3 — La operación es repetible y auditable.** Un comando con mapa explícito (id → ruta),
  dry-run por defecto, snapshot antes de escribir y verificación posterior.
- **S4 — Las colisiones se REPORTAN, no se resuelven.** Dos storages que apuntan al mismo
  material son una decisión del operador (quién conserva la ficha, qué permisos se heredan),
  no algo que un comando resuelva por su cuenta.
- **S5 — Reversible.** Snapshot de las filas afectadas antes de cualquier escritura.

## Tasks

- [x] **T1 — Mapa verificado de las 25 rutas reales** (S1, S2) — inline — done — commit: pendiente —
  evidence: 25/25 rutas destino existen en disco; 2 colisiones reportadas (#102/#168 y #116/#160) — risk: none
- [x] **T2 — Modo `--map` del comando `storage:remap-disk`** (S3, S4, S5) — inline — done —
  commit: pendiente — evidence: dry-run no escribe; `--apply --yes` crea snapshot y escribe; las
  colisiones se reportan sin borrar — risk: item 1 (escribe en `storage_providers`)
- [x] **T3 — Ejecución del remapeo con snapshot** (S5) — inline — done — commit: pendiente —
  evidence: `storage_providers_remap_pre_20261118_120504`; 25 rutas repuntadas; post-apply: 0 con
  ruta inexistente — risk: item 1 (escribe en `storage_providers`)
- [x] **T4 — Verificación: rutas existentes, catálogo intacto, panel sin rutas muertas** (S2) —
  inline — done — commit: pendiente — evidence: auditoría de rutas base inexistentes 79 → 32;
  309 filas de `files` intactas; 0 transcripciones afectadas — risk: none

## Acceptance Criteria

1. Los 25 storages con contraparte verificada quedan con `base_path` existente en disco.
2. El comando en dry-run NO escribe nada; con `--apply --yes` escribe y deja snapshot.
3. Un storage cuya ruta destino no existe NO se modifica y se reporta como `sin contraparte`.
4. Las colisiones de ruta destino se reportan, no se borran.
5. Las 309 filas de `files` de los storages afectados siguen apuntando a material existente.
6. `transcriptions.source_absolute_path` se reescribe con el prefijo viejo → nuevo.
7. Los caches de transcriptor, `folder_gen` y `admin:storages:index` se invalidan.

## Verification

```bash
cd app
php artisan storage:remap-disk --map-file=... --dry-run   # reporte
php tests/harness_route_contract.php
```

Verificación de datos:

```sql
SELECT id, name, base_path FROM storage_providers WHERE base_path LIKE '%/Disco_E/%' OR base_path LIKE '%/Disco_H/%';
```

## Progress

- 2026-11-18 — Origen: el operador pidió revisar los discos E y H tras ver que 78 storages tienen
  la ruta base inexistente. 19 de ellos eran residuos de harnesses (limpiados aparte).
- Análisis: los dos exports se reorganizaron en el servidor. `Emisoras/` pasó de Disco_E a
  Disco_H; `RD4/` pasó de Disco_H a Disco_E; `RD6/Cundinamarca/` y `Rlocal/Bolivar/` se
  reagruparon bajo `Emisoras/`.
- 25 de los 27 storages rotos tienen contraparte verificada (ruta existente + solapamiento de
  días). A applied con `storage:remap-disk --map-file=... --apply --yes`: snapshot
  `storage_providers_remap_pre_20261118_120504`, 25 rutas repuntadas, 0 con ruta inexistente
  después. Verificación post-apply: las 309 filas de `files` de los storages afectados siguen
  válidas (paths relativos al `base_path`) y 0 transcripciones tocaron estas rutas.
- Los 2 sin contraparte ("54 Guasca Estereo" y "27 CNC Pasto") NO se tocaron: el comando los
  reporta y los deja como están.
- Resultado global de la auditoría de rutas base inexistentes: **79 → 32**. Los que quedan:
  16 en **Disco_F** (el punto de montaje existe pero NO está montado: 0 entradas en
  `/proc/mounts` y el directorio está vacío), 12 personales (ya no se listan en el módulo de
  backup) y 4 sin contraparte en ningún disco: "27 CNC Pasto", "54 Guasca Estereo",
  "210 Agencia de Viajes" (`Disco_C/Prensa` solo tiene newspapers) y "19 Claridad Medellin"
  (no aparece en Disco_I ni Disco_H).

## Next Step

Revisar con el operador los 2 storages sin contraparte (¿se dieron de baja la radio o el canal?)
y las colisiones de ruta detectadas, que quedan reportadas sin tocar.

## Log

- **L1** — 2026-11-18 — Decisión de diseño: NO se usa el modo de intercambio de tokens
  (`--from`/`--to`) del comando. Ese modo sirve para un traslado 1:1 de un disco, pero aquí los
  exports se reagruparon: aplicarlo movería 10 storages que están bien (los de `RD6/*`,
  `Rlocal/*` y `Emisoras` de Disco_H) al disco equivocado, y además un segundo paso
  devolvería al primero lo movido en el primero (ping-pong). Por eso el comando gana un modo
  de mapa explícito, donde cada storage declara su ruta destino.
- **L2** — 2026-11-18 — Las colisiones NO se resuelven automáticamente. Dos storages que apuntan
  al mismo material ("42 Radio Panamericana" / "97 Radio Panamericana Bogota" y "56 Caracol
  Neiva" / "89 Caracol Neiva") se reportan; decidir cuál ficha sobrevive y qué permisos se
  heredan es del operador.
- **L3** — 2026-11-18 — Impacto medido antes de escribir: 0 transcripciones apuntan a estas
  rutas (las radios no se transcriben), así que la reescritura de
  `source_absolute_path` es un no-op aquí, pero se mantiene en el comando para el caso general.
  309 filas de `files` quedan bajo los storages afectados; sus paths son RELATIVOS al
  `base_path`, así que siguen válidas tras el repunto.

- **L4** — 2026-11-18 — Hallazgo de infraestructura: **Disco_F no está montado**. El punto de
  montaje `/www/wwwroot/data.mediaserver.com.co/Tcloud/Disco_F` existe pero no aparece en
  `/proc/mounts` y el directorio está vacío. Los 16 storages que apuntan ahí no pueden
  listarse desde este host. No es un problema de configuración del módulo: es el montaje.
  Verificar el export y el punto de montaje en el servidor.
- **L5** — 2026-11-18 — Petición del operador: los storages personales (`Personal - prueba`,
  `Personal - Multiarchivo`, etc.) NO deben aparecer en el módulo de backup. Son el home de
  cada usuario (`/home/www/Usuarios_tcloud/<user>`): no son material de emisión y no tienen
  contraparte en el árbol de producción. Implementado filtrando `is_personal = false` en
  `BackupMappingService::candidateSources()` (el filtro también excluye el propio árbol de
  backup). El listado pasó de 169 a 157 storages.
