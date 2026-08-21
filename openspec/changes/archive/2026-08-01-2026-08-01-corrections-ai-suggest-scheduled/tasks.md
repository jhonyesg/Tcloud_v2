# Tasks: AI suggest EN↔ES programado cada 2 horas

## 1. Verificar override UI de parámetros

- [ ] En `app/app/Services/Ia/LlmCorrectionSettings.php`: confirmar que `int('days_back')` y `int('sample_size')` leen DB-overrides desde `llm_correction_settings` (no solo env). Si solo leen env, entonces la cron `--days=1 --sample=200` SIEMPRE usa esos valores y el admin no puede subirlos/bajarlos desde AI Settings para corridas programadas → riesgo documentado en proposal.md. Si pasa la verificación: anotar como nota en el spec; si falla: agregar al proposal.md como follow-up del alcance de este change (no se implementa aquí; queda flag para investigación).

## 2. Implementación del schedule

- [ ] En `app/routes/console.php`:
  - Reemplazar el bloque "ON-DEMAND ÚNICAMENTE (sin schedule)" (líneas ~72-80) por el schedule del suggester:
    ```php
    // AI suggester LLM-powered EN↔ES programado cada 2h (corrections-ai-suggest-scheduled).
    // Defaults --days=1 --sample=200 cambios desde AI Settings solo si LlmCorrectionSettings
    // soporta DB-override (verificado en tarea 1). Log persistente para diagnóstico.
    Schedule::command('corrections:ai-suggest --days=1 --sample=200')
        ->everyTwoHours()
        ->withoutOverlapping(10)
        ->name('corrections:ai-suggest-scheduled')
        ->appendOutputTo(storage_path('logs/ai-suggest-scheduled.log'));
    ```
  - Mantener el miner semanal `corrections:mine-en-es` que ya está en el archivo (líneas ~64-70) intacto.
- [ ] `php -l app/routes/console.php`.

## 3. Verificación

- [ ] `php artisan schedule:list` muestra la nueva entrada con la próxima hora de ejecución (~2h después del momento actual, redondeada a la hora).
- [ ] `php artisan schedule:test` (Laravel 10+) o comprobación manual de que la próxima entrada del cron de Laravel disparará `corrections:ai-suggest --days=1 --sample=200`.
- [ ] Crear `storage/logs/ai-suggest-scheduled.log` vacío si no existe (touch) para que `appendOutputTo` no falle por permisos.
- [ ] Si `LlmCorrectionSettings` verifica NO-DB-override (tarea 1): documentar el caveat en `storage/logs/ai-suggest-scheduled.log` cabecera y en la spec del módulo.

## 4. Aplicar delta del spec

- [ ] En `openspec/specs/transcription-corrections/spec.md`:
  - Agregar 1 ADDED Requirement "AI suggest corre cada 2 horas para mantener el diccionario actualizado contra el drift del ASR".

## 5. Archivar

- [ ] Mover el change a `openspec/changes/archive/2026-08-01-2026-08-01-corrections-ai-suggest-scheduled/` (nombre ya date-prefixed: aplicar convención de doble fecha según `corrections.md/openspec_archive_flow`).
