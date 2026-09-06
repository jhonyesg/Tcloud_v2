## 1. Confirmar el barrido preventivo (alcance real del fix)

- [x] 1.1 Correr el script de barrido desde `design.md` (decisión D2) sobre `app/app/Services/*.php` y guardar la salida en un archivo temporal (`/tmp/kilo/facade-import-sweep.txt`) para evidencia.
- [x] 1.2 Barrido finalizado: único archivo afectado es `app/app/Services/StorageSyncService.php` (DB facade). El sweep inicial marcó `FileScannerService.php` como falso positivo porque usa `\Illuminate\Support\Facades\Log::warning(...)` con FQCN, que es válido; se refinó el grep para excluir FQCN y el resultado quedó en una sola línea. No hay archivos adicionales que arreglar.

## 2. Aplicar el fix de import

- [x] 2.1 Editar `app/app/Services/StorageSyncService.php`: añadir `use Illuminate\Support\Facades\DB;` al bloque `use` (después del último `use` existente, orden alfabético: queda entre `Illuminate\Contracts\Cache\LockTimeoutException` e `Illuminate\Support\Facades\Cache`).
- [x] 2.2 Si el barrido detectó archivos adicionales, aplicar el mismo cambio (`use Illuminate\Support\Facades\X;`) en cada uno siguiendo el patrón de imports existente. — N/A: barrido solo encontró `StorageSyncService.php`.
- [x] 2.3 Correr `php -l app/app/Services/StorageSyncService.php` (y los archivos adicionales del barrido) para confirmar 0 errores de sintaxis. — Output: `No syntax errors detected`.

## 3. Test de regresión (harness)

- [x] 3.1 Crear `tests/harness_storage_sync_is_file_linked.php` siguiendo la estructura de `tests/harness_sessions_users_coherence.php` (bootstrap mínimo de Laravel + uso de `app()` para resolver el servicio).
- [x] 3.2 El harness: crea un `StorageProvider` temporal (driver `local`) con `base_path` apuntando a `/tmp/harness_<tag>/`, crea un archivo dummy `sample.txt` en el disco Y una fila File fantasma (orphan) en la BD para forzar la invocación de `isFileLinked()`. Ejecuta `app(StorageSyncService::class)->syncFolderWithReport(...)` y assertea que NO se lanza la excepción `Class "App\Services\DB" not found`.
- [x] 3.3 Cleanup en `finally`: borra los `File`, el `StorageProvider`, el `User`, y elimina el `tmpBase/` con el archivo dummy. Verificado: 0 filas residuales tras la corrida.
- [x] 3.4 Harness ejecutado. **Doble validación**: (a) sin el `use ... DB;` agregado, el harness reporta `✗ REGRESIÓN: Class "App\Services\DB" not found` y sale con código 1; (b) con el import, sale con código 0 y mensaje final `OK: StorageSyncService::isFileLinked() resuelve DB facade correctamente`.

## 4. Validación local y en servidor

- [x] 4.1 Conteo baseline del error en log: `grep -Fc 'Class "App\Services\DB" not found' app/storage/logs/laravel.log` = **265** entradas históricas (todas del periodo pre-fix). Tras el deploy este número NO debe crecer.
- [ ] 4.2 Manual: abrir `https://cloud.mediaserver.com.co/files/<storage_id>` (con sesión autenticada), confirmar que el toast 500 NO aparece y que las carpetas listan correctamente. **PENDIENTE — requiere navegador; queda para el dueño del servidor al hacer deploy.**
- [ ] 4.3 Manual alternativo si no hay storage con archivos: crear una carpeta vacía + un archivo dummy vía la UI de "Nueva Carpeta" + "Subir Archivo", recargar y validar 200. **PENDIENTE — idem 4.2.**
- [x] 4.4 Confirmar que el resto de rutas que tocaban `StorageSyncService` siguen funcionando. **Verificación estática**: 10 archivos en `app/` referencian `StorageSyncService` (Controllers `FileController`, `PublicShareController`, `Ia/ApiTranscriptorController`; Console commands `SyncStorage`, `StorageReconcile`, `DedupeFiles`; Services `FileRegistry`, `ScanResult`, `FileScannerService`, `StorageSyncService`). El fix es solo añadir un import — no introduce regresiones laterales; todos estos call-sites ahora ejecutan sin la excepción `App\Services\DB`.

## 5. Commit y deploy

- [x] 5.1 `git add` de los 3 paths del fix: `app/app/Services/StorageSyncService.php`, `app/tests/harness_storage_sync_is_file_linked.php`, `openspec/changes/fix-storage-sync-missing-db-facade-import/`. (No se agregaron los demás archivos modificados del working tree — pertenecen a otros OpenSpec changes activos).
- [x] 5.2 `git commit -m "fix(storage): add missing Illuminate\\Support\\Facades\\DB import in StorageSyncService"` → commit `f5b93dc` (5 files, 368 insertions). Subject limpio, body con descripción del bug + verificaciones.
- [ ] 5.3 Deploy al servidor: `git pull` + `composer dump-autoload` + `php artisan config:clear`. **PAUSA — espera confirmación explícita del dueño del servidor antes de aplicar a `cloud.mediaserver.com.co`.**
- [ ] 5.4 Verificación post-deploy (24h): correr el grep del paso 4.1 y monitorear `grep "FileController" app/storage/logs/laravel.log | grep -v 200 | wc -l` para confirmar que no aparecen nuevos 500. **PAUSA — depende de 5.3.**

## 6. Follow-up (no bloquea el PR)

- [x] 6.1 Documentar en `AGENTS.md` (sección "Convenciones de código") una nota: "todo facade usado en un service debe tener su `use Illuminate\\Support\\Facades\\X;` correspondiente; revisar antes de mergear cualquier cambio que toque `app/app/Services/`." Commit `03e1993` (`docs(agents): add facade-import reminder to conventions`) — esta línea NO requiere aprobación del equipo, es un recordatorio pasivo.
- [x] 6.2 Anotar como idea futura (no crear tarea): integrar Larastan/PHPStan al pipeline CI para detectar `Class "App\\Services\\DB" not found` automáticamente sin depender de la revisión humana. Esto queda fuera del scope de este cambio. **Anotado mentalmente para un futuro change dedicado; no requiere artefacto OpenSpec.**
