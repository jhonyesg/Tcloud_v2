## Purpose

Define la vista cliente "Mis Avisos" (`/mis-avisos`): cómo el cliente gestiona sus keywords, consulta su historial de alertas y visualiza coincidencias, respetando el acceso por storage concedido por el admin.

## Requirements

### Requirement: "Mis Avisos" aparece en sidebar solo si el módulo está activo
El sistema SHALL renderizar la entrada "Mis Avisos" dentro del bloque Multimedia del sidebar (`layouts/app.blade.php`) únicamente cuando existe una fila en `user_alerts_inteligentes` para el usuario autenticado con `enabled=true`.

#### Scenario: Usuario con módulo activo ve la entrada
- **WHEN** el usuario "prueba" tiene fila en `user_alerts_inteligentes(enabled=true)`
- **THEN** ve la entrada "Mis Avisos" en su sidebar dentro del bloque Multimedia, junto a "Medios Puntuales"

#### Scenario: Usuario sin módulo no ve la entrada
- **WHEN** el usuario "otro" no tiene fila en `user_alerts_inteligentes` (o la tiene con `enabled=false`)
- **THEN** la entrada "Mis Avisos" no aparece en su sidebar

#### Scenario: Admin no ve "Mis Avisos" salvo que se lo asigne a sí mismo
- **WHEN** el admin no tiene fila activa en `user_alerts_inteligentes`
- **THEN** la entrada no aparece (consistente con el patrón de Sites Externos en línea 339 de `app.blade.php`)

---

### Requirement: Cliente ve la lista de sus keywords activas
El sistema SHALL listar las keywords del usuario autenticado en `/mis-avisos` con un campo para agregar nuevas y un botón para eliminar cada una.

#### Scenario: Vista inicial del cliente
- **WHEN** el cliente abre `/mis-avisos`
- **THEN** ve una sección "Mis palabras clave" con la lista de sus keywords, contadores "X / cupo" y un formulario para agregar

#### Scenario: Cliente excede el cupo
- **WHEN** el cliente intenta agregar una keyword y ya tiene `keywords_quota` registradas
- **THEN** el formulario muestra error y el botón "Agregar" está deshabilitado con tooltip "Cupo alcanzado"

---

### Requirement: Cliente puede agregar keywords dentro del cupo
El sistema SHALL permitir al cliente autenticado agregar keywords en `/mis-avisos` siempre que no exceda `keywords_quota`.

#### Scenario: Keyword agregada exitosamente
- **WHEN** el cliente escribe "paro nacional", hace submit, y tiene cupo disponible
- **THEN** se crea (o reutiliza) la fila en `keywords` y la fila en `user_keyword` para este usuario; aparece en su lista

#### Scenario: Keyword duplicada ignorada
- **WHEN** el cliente agrega "paro nacional" cuando ya la tiene
- **THEN** el sistema responde 200 sin crear duplicado (mismo upsert que en admin)

---

### Requirement: Cliente puede eliminar sus propias keywords
El sistema SHALL permitir al cliente eliminar cualquier keyword propia desde `/mis-avisos` (no puede eliminar keywords de otros clientes).

#### Scenario: Eliminación propia
- **WHEN** el cliente hace click en "Eliminar" junto a "paro nacional"
- **THEN** se borra la fila en `user_keyword` para este usuario; si la keyword queda sin usuarios, la fila en `keywords` se conserva (no rompe a otros clientes)

---

### Requirement: Cliente ve historial de sus alertas recibidas
El sistema SHALL listar las `KeywordMatch` del usuario en `/mis-avisos` ordenadas por `matched_at DESC` con link al `File` original (que abre preview/download) y link al SRT completo si quiere profundizar.

#### Scenario: Historial de alertas
- **WHEN** el cliente abre `/mis-avisos` y tiene matches previos
- **THEN** ve una tabla con: fecha, grabación (filename + canal/storage), minuto, keyword, snippet, estado del email

#### Scenario: Click en el filename
- **WHEN** el cliente hace click en el filename de un match
- **THEN** se abre el reproductor/preview del File original (patrón ya existente en otros módulos de TCloud)

---

### Requirement: Cliente NO puede modificar el cupo ni los correos de aviso
El sistema SHALL exponer la UI de `/mis-avisos` solo para gestionar keywords. Los campos de cupo y correos quedan exclusivamente bajo control del admin en M2.

#### Scenario: La UI no muestra cupo editable ni gestión de correos
- **WHEN** el cliente abre `/mis-avisos`
- **THEN** solo ve: gestión de keywords + historial de alertas. No hay formulario de correos ni selector de cupo (queda documented en `proposal.md` Non-goals)

---

### Requirement: Acceso denegado a `/mis-avisos` si el módulo no está activo
El sistema SHALL retornar 403 al cliente que intenta acceder a `/mis-avisos` sin tener el módulo activo.

#### Scenario: Cliente sin módulo intenta la URL directa
- **WHEN** el cliente "otro" (sin fila en `user_alerts_inteligentes` con `enabled=true`) hace GET a `/mis-avisos`
- **THEN** el sistema responde 403 con mensaje "No tienes acceso al módulo de avisos"

---

### Requirement: Listado de matches del cliente respeta `transcription_access`

El sistema SHALL limitar la lista de `KeywordMatch` que el cliente ve en `/mis-avisos` y `/mis-avisos/corrections/mine` a aquellos cuyas transcripciones pertenecen a storages en los que el usuario autenticado tiene `transcription_access = true`. Matches históricos de storages sin acceso SHALL permanecer visibles (filtro prospectivo, no retroactivo).

#### Scenario: Cliente con acceso al storage ve el match
- **WHEN** el cliente "prueba" abre `/mis-avisos` y existe un `KeywordMatch` suyo cuya transcripción es del storage 11
- **AND** `user_storages(prueba, 11).transcription_access = true`
- **THEN** el match aparece en su listado

#### Scenario: Cliente sin acceso al storage no ve el match nuevo
- **WHEN** el cliente "prueba" abre `/mis-avisos` y llega un `KeywordMatch` suyo del storage 11
- **AND** `user_storages(prueba, 11).transcription_access = false`
- **THEN** el match NO aparece en el listado (porque el KeywordMatcher upstream ya lo bloqueó)

#### Scenario: Match histórico de storage sin acceso sigue visible
- **WHEN** el cliente "prueba" tiene un match del storage 11 del 2026-08-15 y al 2026-08-21 el admin le revocó el acceso a 11
- **THEN** ese match histórico sigue apareciendo en su listado

#### Scenario: Cliente sin acceso a ningún storage ve listado vacío
- **WHEN** el cliente no tiene `user_storages.transcription_access = true` para ningún storage
- **THEN** `/mis-avisos` muestra el estado vacío "Aún no se han detectado coincidencias" aunque el `KeywordMatcher` haya generado matches históricos
