# Spec: transcriptor-bogota-time-helper

## Purpose

Encapsular la dependencia de la zona horaria America/Bogota en una única clase helper (`App\Services\Ia\BogotaTime`) para que todos los call sites del proyecto que preguntan "¿qué es hoy en Bogota?" apunten a la misma implementación, y que cambiar la zona en el futuro sea un edit de un solo archivo.

## Requirements

### Requirement: BogotaTime::todayStart() retorna el inicio del día Bogota

El sistema SHALL exponer `App\Services\Ia\BogotaTime::todayStart(): \Carbon\CarbonImmutable` que retorna `CarbonImmutable::today('America/Bogota')`. El método NO SHALL recibir argumentos: la zona es fija en el helper.

#### Scenario: Llamada dentro de zona horaria Bogota (caso normal del operador)
- **WHEN** el operador está en America/Bogota y llama `BogotaTime::todayStart()` a las 23:59 hora local
- **THEN** el valor retornado representa el inicio del día Bogota actual (00:00:00 -05:00)

#### Scenario: Llamada justo después de medianoche Bogota
- **WHEN** el operador está en America/Bogota y llama `BogotaTime::todayStart()` el primer segundo después de las 00:00
- **THEN** el valor retornado avanza al nuevo día Bogota (00:00:00 -05:00 del día siguiente)

#### Scenario: El servidor PHP está en zona UTC
- **WHEN** el servidor tiene `date.timezone = UTC` en `php.ini` o `TZ=UTC` en el entorno
- **THEN** `BogotaTime::todayStart()` retorna el inicio del día Bogota igualmente (no depende de la zona del proceso PHP)

### Requirement: BogotaTime::now() retorna el instante actual en Bogota

El sistema SHALL exponer `App\Services\Ia\BogotaTime::now(): \Carbon\CarbonImmutable` que retorna `CarbonImmutable::now('America/Bogota')`.

#### Scenario: Timestamp legible por el operador
- **WHEN** el operador lee un log con `BogotaTime::now()`
- **THEN** el timestamp está en hora Bogota, no UTC

### Requirement: BogotaTime::todayAsDateString() retorna YYYY-MM-DD del día Bogota

El sistema SHALL exponer `App\Services\Ia\BogotaTime::todayAsDateString(): string` que retorna la fecha Bogota actual en formato `YYYY-MM-DD`. Útil para nombres de archivo y para filtros `WHERE created_at::date = ?`.

#### Scenario: Generar nombre de archivo con fecha Bogota
- **WHEN** un comando Artisan genera un log con nombre `transcription-stage-YYYY-MM-DD.log`
- **THEN** usa `BogotaTime::todayAsDateString()` y la fecha coincide con el día Bogota actual (no UTC)

### Requirement: BogotaTime::TIMEZONE es un constant público

El sistema SHALL exponer `public const TIMEZONE = 'America/Bogota'` en la clase `BogotaTime`. Tests y código defensivo pueden leerlo sin instanciar.

#### Scenario: Test verifica la zona declarada
- **WHEN** un test unitario quiere confirmar que la zona por defecto del helper es Bogota
- **THEN** lee `BogotaTime::TIMEZONE === 'America/Bogota'`

### Requirement: Refactor de 12 call sites al helper

El sistema SHALL reemplazar las llamadas `CarbonImmutable::today()` (sin argumento) en los siguientes archivos por `BogotaTime::todayStart()`:

- `app/app/Services/Ia/TodayPendingService.php` (1 sitio)
- `app/app/Services/Ia/TranscriptionBulkDispatchService.php` (1 sitio)
- `app/app/Services/Ia/TranscriptionSubmitService.php` (1 sitio)
- `app/app/Console/Commands/TranscriptionBackfillLostCommand.php` (1 sitio)
- `app/app/Console/Commands/TranscriptionPurgeStalePendingCommand.php` (1 sitio)
- `app/app/Console/Commands/TranscriptionStageCommand.php` (1 sitio)
- `app/app/Console/Commands/TranscriptionStorageSnapshotCommand.php` (1 sitio)
- `app/app/Console/Commands/TranscriptionTickCommand.php` (3 sitios)
- `app/app/Console/Commands/TranscriptionWorkerCommand.php` (1 sitio)

#### Scenario: Grep post-refactor
- **WHEN** se corre `grep -rn "CarbonImmutable::today()" app/app/`
- **THEN** no aparece ningún call site sin pasar el argumento `BogotaTime::TIMEZONE` o equivalente

#### Scenario: Tests pasan
- **WHEN** se corre `php artisan test --filter=BogotaTimeTest`
- **THEN** los 4 tests del helper pasan (todayStart, now, todayAsDateString, constante)

### Requirement: Test unitario BogotaTimeTest cubre las invariantes

El sistema SHALL incluir `app/tests/Unit/BogotaTimeTest.php` con al menos 4 tests:

- `test_today_start_returns_start_of_bogota_day()`
- `test_now_returns_current_instant_in_bogota_timezone()`
- `test_today_as_date_string_returns_yyyy_mm_dd_format()`
- `test_timezone_constant_is_america_bogota()`

#### Scenario: Helper disponible en el contenedor
- **WHEN** un servicio Laravel hace `use App\Services\Ia\BogotaTime;`
- **THEN** los métodos estáticos están disponibles sin necesidad de `app()` ni binding manual
