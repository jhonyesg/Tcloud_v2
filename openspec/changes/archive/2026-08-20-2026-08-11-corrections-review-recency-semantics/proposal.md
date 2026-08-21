# Change: Semántica clara de recencia en revisión de transcripciones

## Why

La pestaña **Revisar transcripciones** muestra actualmente las diez transcripciones
`done` ordenadas por `finished_at`. Esto es técnicamente coherente con “las últimas
que terminaron”, pero no coincide con la expectativa operativa de “las últimas diez
generadas o solicitadas”.

En producción se observó que trabajos creados el 8 de agosto terminaron el 11 de
agosto debido al backlog del transcriptor. Como la interfaz solo muestra una fecha y
los nombres de archivo contienen la fecha original, el administrador interpreta que
la pestaña está ignorando las solicitudes recientes del API Transcriptor.

El problema no es únicamente de ordenamiento: una sola etiqueta “Últimas 10” mezcla
dos conceptos diferentes:

```text
created_at  = cuándo se solicitó/generó el trabajo
finished_at = cuándo terminó y quedó disponible para revisión
```

## What Changes

### 1. Modos explícitos de recencia

La revisión tendrá modos con nombres inequívocos:

- **Últimas 10 solicitadas**: transcripciones `done` más recientes por `created_at`.
- **Últimas 10 finalizadas**: transcripciones `done` más recientes por `finished_at`.
- **Últimas 10 sensibles**: transcripciones `done` con coincidencias `medium/high`,
  ordenadas por `finished_at`, porque solo se pueden revisar después de terminar.

El modo actual `latest` deberá dejar de tener una semántica ambigua. El endpoint
podrá mantenerlo como alias de **Últimas 10 solicitadas** para no romper enlaces o
clientes existentes, pero la respuesta deberá incluir el modo canónico.

### 2. Fechas visibles

Cada fila deberá mostrar por separado:

- fecha de solicitud/generación (`created_at`);
- fecha de finalización (`finished_at`);
- estado de revisión humana.

Cuando exista una diferencia importante entre ambas fechas, la interfaz deberá hacer
visible que hubo espera en cola, sin atribuir el retraso al módulo Correcciones.

### 3. Contrato consistente con API Transcriptor

La respuesta de la lista deberá exponer ambas fechas en formato ISO 8601 y mantener
el mismo identificador de transcripción usado por `/ia/api-transcriptor`.

La documentación y los textos de ayuda deberán explicar que:

- las solicitudes `queued` o `processing` no aparecen en la revisión porque aún no
  tienen segmentos finales para comparar;
- una transcripción solicitada días antes puede aparecer en “finalizadas” si terminó
  recientemente;
- el listado de trabajos del API y el listado de revisión responden a propósitos
  diferentes.

### 4. Verificación del retraso de cola

La implementación deberá permitir distinguir, mediante las fechas mostradas, estos
casos:

```text
solicitada hoy + finalizada hoy       -> trabajo reciente normal
solicitada el 8 + finalizada hoy      -> backlog terminado hoy
solicitada hoy + aún no finalizada    -> visible solo en API Transcriptor
```

No se añadirá una consulta masiva del histórico ni se cargarán transcripciones que
no estén terminadas en el detalle de Correcciones.

## Non-goals

- No se cambiará en este cambio el mecanismo de polling ni el dispatch del API
  Transcriptor.
- No se forzará que trabajos `queued` o `processing` aparezcan en el detalle de
  revisión.
- No se modificará `created_at` ni `finished_at` de datos históricos para corregir la
  presentación.
- No se eliminará la vista de transcripciones sensibles.
- No se resolverá el backlog operativo; solo se hará visible y se evitará confundirlo
  con un error de selección.

## Success Criteria

- El administrador puede elegir entre transcripciones solicitadas recientemente y
  transcripciones finalizadas recientemente.
- La vista solicitada muestra registros `done` ordenados por `created_at DESC`.
- La vista finalizada muestra registros `done` ordenados por `finished_at DESC`, con
  fallback determinista a `created_at DESC` e `id DESC`.
- Cada fila muestra solicitud y finalización sin depender del nombre del archivo.
- Una transcripción creada el 8 de agosto y terminada el 11 de agosto se identifica
  claramente como trabajo antiguo finalizado recientemente.
- Las solicitudes recientes que aún no están `done` no se presentan como revisables.
- Los contratos existentes de detalle, estado de revisión y modo sensible continúan
  funcionando.
