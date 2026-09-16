# Spec: transcriptor-timezone-session-default

## Purpose

Garantizar que toda sesión PostgreSQL nacida de PHP-FPM arranque con `timezone = 'America/Bogota'` declarado en la conexión (no dependiendo de `.env`), y que las sesiones interactivas del operador (vía `psql`) también lo hagan por default vía `/root/.psqlrc`.

## Requirements

### Requirement: database.php fija timezone America/Bogota sin env()

El sistema SHALL declarar la conexión `pgsql` en `app/config/database.php` con `'timezone' => 'America/Bogota'` como string literal, NO SHALL usar `env('DB_TIMEZONE', 'America/Bogota')`.

#### Scenario: Conexión PHP-FPM usa Bogota
- **WHEN** PHP-FPM establece una conexión nueva a PostgreSQL
- **THEN** la sesión resultante tiene `SHOW timezone;` → `America/Bogota`

#### Scenario: Cambio accidental de .env no afecta la conexión
- **WHEN** alguien agrega `DB_TIMEZONE=UTC` en `.env` por error
- **THEN** la conexión PHP-FPM sigue arrancando en `America/Bogota` (el env se ignora porque el config ya no lo lee)

#### Scenario: php artisan config:cache fija la zona
- **WHEN** se corre `php artisan config:cache`
- **THEN** el cache de config contiene `'timezone' => 'America/Bogota'` para la conexión pgsql

### Requirement: /root/.psqlrc arranca psql en Bogota

El sistema SHALL crear `/root/.psqlrc` con `SET timezone = 'America/Bogota';` como primera línea.

#### Scenario: Operador abre psql directo
- **WHEN** el operador corre `psql -h 127.0.0.1 -U cloud -d tcloudstorage` sin argumentos
- **THEN** `SHOW timezone;` retorna `America/Bogota` sin que el operador haya ejecutado `SET`

#### Scenario: Query de diagnóstico devuelve timestamps Bogota
- **WHEN** el operador corre `SELECT now();` desde psql
- **THEN** el resultado muestra la hora actual de Bogota (ej. `2026-09-15 22:00:00-05`) no UTC

### Requirement: psqlrc también define alias today_bogota

El sistema SHALL incluir en `/root/.psqlrc` la variable psql `:today_bogota` que se expande a la fecha Bogota actual en formato `YYYY-MM-DD`. Útil para queries `WHERE created_at::date = :'today_bogota'`.

#### Scenario: Operador usa el alias
- **WHEN** el operador corre `\echo :today_bogota` en psql
- **THEN** psql expande la variable al día Bogota actual (formato `YYYY-MM-DD`), que se puede usar en queries tipo `WHERE created_at::date = :'today_bogota'`

### Requirement: psqlrc global (opcional)

El sistema SHALL documentar en `app/AGENTS.md` que para escalar el psqlrc a otros usuarios del sistema operativo se puede mover a `/etc/psqlrc` (o crear un `/etc/profile.d/psql.sh` que lo copie).

#### Scenario: Dev abre psql desde usuario no-root
- **WHEN** un dev no-root abre `psql`
- **THEN** la query de zona depende del `.psqlrc` del usuario; documentado en AGENTS.md que `/etc/psqlrc` es la alternativa global

### Requirement: Database.php pre-connection SET opcional

El sistema NO SHALL agregar un `DB::statement('SET timezone TO ...')` global en `boot()` ni en un service provider. La razón: PostgreSQL acepta la cláusula `timezone` en la cadena de conexión / DSN, y Laravel ya la pasa via PDO. Agregar un SET post-connect genera un round-trip extra y no aporta garantías.

#### Scenario: SET timezone post-connect innecesario
- **WHEN** un test verifica que la conexión tiene la zona correcta
- **THEN** basta con `SHOW timezone;` después de la primera query — el valor viene del handshake PDO
