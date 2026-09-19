# Plan de colecciones — `files-mirror-elimination`

> Plan orquestado por Hermes para kilo, en español claro.
> Cada colección es una rama en este repo. Kilo trabaja en orden.

## 📋 Contexto del problema (que ya entendimos)

El cliente ve "carpeta vacía" en `cloud.mediaserver.com.co/files/...` cuando entra a un folder
de fecha. La causa es estructural: el sistema crea **6.459 carpetas espejo** con
`parent_id IS NULL` y `path = name (solo la fecha)`, mientras los archivos reales viven en
otra fila con `parent_id` correcto. La UI navega por la carpeta espejo, que no tiene
los hijos.

## 🗂️ Ramas de colecciones (organización del trabajo)

```
main
└── feat/files-mirror-elimination    (ya tiene secciones 1-3 aplicadas en commit e9323a8)
    ├── collection-apply-canonicalization         ← Sección 4: canonicalizar antes de disco
    ├── collection-view-cross-storage             ← Sección 5: comando de retiro de mirrors
    ├── collection-share-canonical-fk             ← Sección 6: retirada del código de materialización
    └── collection-where-state                    ← Sección 7+8+9: harness, E2E y deploy
```

| # | Rama | Sección change | Qué hace |
|---|---|---|---|
| 1 | `collection-apply-canonicalization` | § 4 | En `download()`, `preview()`, `downloadFolder()`, `downloadMulti()`, `rotate/copy/move`, `PublicShareController::mediaPreview/preview/download`, y `ShareController::store()`. Resolver canónico antes de calcular `$storage`/`$fullPath`. |
| 2 | `collection-view-cross-storage` | § 5 | Crear `files:mirror-retirement` con flags `--dry-run`/`--apply`/`--revert`/`--batch`/`--storage`. Reporta conteos, repunta FKs, repara cross-storage, delega al canónico, retira mirrors con `file_mirror_audit_log`. |
| 3 | `collection-share-canonical-fk` | § 6 | Eliminar `FileMirrorLinker`, `FilesRepairFileMirrorsCommand`, `RepairFolderMirrorsCommand`, scopes `canonical/mirror`, escritura de `canonical_folder_id` en `FileObserver`. Mantener columnas con `@deprecated`. |
| 4 | `collection-where-state` | § 7, 8, 9 | `app/tests/harness_files_mirror_elimination.php`. Spec Playwright `validate-mirror-elimination.spec.mjs` con casos A–E. Dry-run → Apply → clear cache → invariantes → docs → archivar `files-mirror-consolidation` como superseded. |

## 🎯 Cómo trabaja kilo en cada colección

1. `git checkout collection-XX`
2. Implementa esa sección contra `tasks.md`
3. `git commit` con mensaje claro
4. Avanza al siguiente. Las colecciones anteriores tienen su commit independiente.

## 📑 Y mi rol mientras kilo trabaja

| Cuando kilo dice: | Yo hago: |
|---|---|
| "acabo de hacer la sección X" | Valido el commit, lanzo dry-runs, comparo query results |
| "¿puedo aplicar el comando X?" | Lo lanzo desde aquí a la base de datos real (dry-run primero) |
| "necesito screenshot del estado actual" | Ejecuto `tests/e2e/diagnose-storage.mjs` y entrego el path |
| "el harness me falla" | Reviso logs, te entrego raíz y opciones |
| "listo para merge" | Digo OK, orquesto el merge a `feat/files-mirror-elimination` |

## 🛡️ Para que kilo no se pierda

`tasks.md` en `openspec/changes/files-mirror-elimination/` es la fuente única de
verdad. Kilo lee la sección de la rama que le toca y avanza checklist por checklist.

`proposal.md`, `design.md` y `specs/*.md` tienen el porqué. Si algo no está claro,
preguntar antes de implementar (regla de kilo + hermes).

## 🔍 Resumen del estado

- ✅ `feat/files-mirror-elimination` (commit e9323a8): secciones 1-3 foundation
- ⏳ `collection-apply-canonicalization`: vacía, lista
- ⏳ `collection-view-cross-storage`: vacía, lista
- ⏳ `collection-share-canonical-fk`: vacía, lista
- ⏳ `collection-where-state`: vacía, lista
- 🟡 `chore/cleanup-completed-archives`: working tree con 877 deletes (no aplicar)
- 🟢 `main`: bf80f8f (sin tocar)

Jhon, te paso este plan en el chat. Si quieres ajustar los nombres de las
ramas o el orden, dime antes de que kilo arranque. Y si te arrepientes de
los deletes en `chore/cleanup`, también es momento de decirlo.
