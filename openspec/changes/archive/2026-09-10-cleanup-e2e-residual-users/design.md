# Design: limpieza de 31 usuarios `e2e_*`

## Identificación de los registros

Query de descubrimiento ejecutada en este discovery (2026-09-10, antes de tocar nada):

```sql
SELECT id, role, username, email, created_at
FROM users
WHERE username LIKE 'e2e_%'
ORDER BY id;
```

Resultado (31 filas):

| id | role | username | email | created_at |
|----|------|----------|-------|------------|
| 250 | admin | e2e_avisos_admin | e2e_avisos_admin@local.test | 2026-09-10 02:49:24.371399 |
| 311 | user | e2e_dummy_01 | e2e_dummy_01@e2e.local | 2026-09-10 02:59:43.649829 |
| 312 | user | e2e_dummy_02 | e2e_dummy_02@e2e.local | 2026-09-10 02:59:43.649829 |
| ... | ... | ... | ... | ... |
| 340 | user | e2e_dummy_30 | e2e_dummy_30@e2e.local | 2026-09-10 02:59:43.649829 |

## SQL exacto a ejecutar

```sql
BEGIN;

-- Pre-flight (verificación)
SELECT COUNT(*) FROM users WHERE username LIKE 'e2e_%';
-- Esperado: 31

-- Snapshot de las FK referencias (debe dar 13 filas, todas en user_sessions)
SELECT 'user_sessions' AS tab, COUNT(*) FROM user_sessions
 WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'e2e_%');

-- Borrado
DELETE FROM users WHERE username LIKE 'e2e_%';
-- Esperado: DELETE 31

-- Post-flight (verificación)
SELECT COUNT(*) FROM users WHERE username LIKE 'e2e_%';
-- Esperado: 0

SELECT COUNT(*) FROM user_sessions WHERE user_id IN (250, 311, 312, ..., 340);
-- Esperado: 0  (CASCADE eliminó las 13)

COMMIT;
```

> El comando se ejecuta con la conexión operativa habitual:
> `PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage`.

## Efectos colaterales explícitos (CASCADE)

- **`user_sessions`** — 13 filas eliminadas (todas de `e2e_avisos_admin`, IPs `186.119.128.186` y `192.168.1.1`, fechas `2026-09-09 22:06:59`–`23:34:54`). Aceptado por el usuario.
- Todas las demás tablas con FK a `users.id` tienen 0 referencias, confirmado por el discovery previo.

## Tablas con FK a `users` que NO se ven afectadas (ya verificado en cero)

`files`, `shares`, `user_storages`, `user_keyword`, `user_keyword_storage`, `user_alerts_inteligentes`, `mentions_exports`, `media_edit_jobs`, `keyword_matches`, `external_site_user`, `alert_deliveries`, `alert_logs`, `corrections`, `canales` (SET NULL), `keyword_categories`, `grabador_usuario`, `password_tokens`, `correo_log`, `correo_config` (SET NULL), `correo_plantillas` (SET NULL), `transcription_reviews` (SET NULL), `watermark_audit_log` (SET NULL), `watermark_audit_log_archive` (SET NULL), `correction_bulk_actions` (RESTRICT — pero 0 filas), `correction_protected_terms` (RESTRICT — pero 0 filas).

## Verificación UI post-borrado

Sin tocar código, la mejora se observa automáticamente:

1. Abrir cualquier storage en `/admin/storages` (ej. `01 Caracol Tv`).
2. Click en el botón de usuarios del storage.
3. En el dropdown **"Asignar usuario"** los primeros resultados ya no son `@e2e_dummy_*`; aparecen los usuarios reales del cliente (ej. `@ACR`, `@Massmedios`, `@Punto`, `@Stakeholders`, `@Multarchivo`).
4. `GET /admin/users/search?q=` debe devolver los usuarios reales ordenados alfabéticamente (sin truncarse en `@e2e_*`).

## Rollback

- **No hay rollback a nivel de código.** La operación es destructiva.
- Reversión solo vía restaurar el backup de BD pre-flight. Los IDs (`250, 311–340`) y los `email` exactos quedan documentados arriba, así como en el log de `psql` con timestamps del comando.
- Decisión del usuario: aceptar el riesgo y proceder sin backup selectivo de las 13 `user_sessions`.

## Notas operativas

- **Servidor**: `cloud.mediaserver.com.co` (nginx + PHP-FPM). PostgreSQL accesible vía `psql` con password desde variable de entorno.
- **Sin downtime**: el borrado es atómico dentro de la transacción; la BD sigue respondiendo normalmente para todas las demás operaciones. La duración esperada es < 1 segundo para 31 filas + 13 en cascada.
- **Cache Redis**: no aplica (no hay claves indexadas por `user.id` que no sean las 13 sessions que también se purgan vía CASCADE).
