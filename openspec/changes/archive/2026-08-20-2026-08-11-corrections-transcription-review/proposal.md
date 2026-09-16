# Change: Revisión manual de transcripciones y correcciones sensibles

## Why

El módulo `/ia/correcciones` permite administrar el diccionario, sus reglas aprobadas y las reglas marcadas como `risk_level=medium/high`, pero no ofrece una vista operativa para verificar cómo se comportan esas reglas dentro de transcripciones reales.

El administrador necesita revisar manualmente las últimas transcripciones porque el diccionario puede dejar de producir candidatos nuevos mientras siguen existiendo problemas de calidad en el texto generado por el transcriptor. En particular, las reglas sensibles pueden cambiar el tono, el significado o el registro de una frase aunque la sustitución parezca válida de forma aislada.

Actualmente el sistema ya conserva los datos necesarios:

- `transcriptions.srt_content` contiene el SRT original.
- `transcription_segments.text_raw` contiene el texto original del segmento.
- `transcription_segments.text` contiene el texto después de aplicar correcciones.
- Cada segmento conserva tiempos, índice y relación con su transcripción.
- El detalle de una transcripción existe, pero muestra el SRT completo sin comparación contextual ni flujo de revisión.

La información está repartida entre `/ia/correcciones` y `/ia/api-transcriptor`, por lo que el administrador debe cambiar de módulo y revisar manualmente sin saber qué regla produjo cada diferencia.

## What Changes

### 1. Nueva pestaña "Revisión de transcripciones"

Agregar una pestaña en `/ia/correcciones` para revisar transcripciones terminadas desde el mismo lugar donde se administran las reglas.

La pestaña tendrá dos modos claramente diferenciados:

- **Últimas 10**: las diez transcripciones `done` más recientes, sin exigir que tengan correcciones.
- **Últimas 10 sensibles**: las diez transcripciones `done` más recientes que contengan coincidencias con reglas `risk_level=medium` o `risk_level=high`.

La consulta deberá ordenarse por fecha de finalización descendente y limitarse en backend. No deberá cargar todo el histórico al navegador.

### 2. Revisión por segmentos modificados

Al abrir una transcripción, la interfaz mostrará primero los segmentos donde `text_raw` y `text` sean diferentes. Cada segmento deberá incluir:

- índice y rango de tiempo;
- texto original del transcriptor;
- texto corregido;
- reglas aprobadas que probablemente explican el cambio;
- indicador de riesgo de las reglas involucradas;
- segmentos vecinos opcionales para entender el contexto.

También deberá existir una acción para mostrar el SRT completo cuando el administrador necesite revisar la continuidad general.

### 3. Acciones de revisión humana

El administrador podrá marcar la revisión de una transcripción como:

- `correcta`;
- `necesita revisión`;
- `ignorada`.

La decisión deberá guardar quién la tomó y cuándo. La revisión no deberá modificar automáticamente el diccionario.

Cuando se detecte un problema, la interfaz deberá ofrecer acciones contextuales separadas:

- proponer una nueva corrección;
- cambiar la traducción de una regla existente;
- marcar una regla como sensible;
- convertir un término en exclusión;
- mantener la observación sin cambiar reglas.

La primera versión podrá reutilizar las acciones y modales existentes, pero deberá mantener claro si la acción afecta solo a la revisión o a una regla global.

### 4. Detección de reglas aplicadas

La primera versión no introducirá un registro por cada reemplazo de cada segmento. Deberá reconstruir la explicación comparando `text_raw` y `text` contra las reglas aprobadas actuales, priorizando reglas que coincidan en el texto original.

La respuesta deberá indicar cuando la explicación sea aproximada o no pueda determinarse con certeza.

### 5. Política de reglas `high`

Durante el diseño e implementación se deberá verificar y unificar el comportamiento de las reglas `risk_level=high` en todos los caminos de aplicación automática.

La política esperada es:

- las reglas `high` no se aplican automáticamente a transcripciones nuevas;
- tampoco se aplican en reaplicaciones retroactivas salvo selección explícita del administrador;
- sí pueden aparecer en la revisión para mostrar que fueron detectadas o que requieren confirmación;
- el administrador puede cambiar explícitamente el nivel de riesgo.

Si el flujo actual de `CorrectionService::applyToSegments()` contradice esta política, deberá corregirse dentro de este cambio o documentarse como una decisión de alcance antes de implementar.

## Non-goals

- No se implementará en esta propuesta un sistema de auditoría detallado por cada sustitución histórica.
- No se cambiará automáticamente una regla global porque una transcripción haya sido marcada como incorrecta.
- No se añadirá una nueva pantalla independiente fuera del módulo Correcciones.
- No se revisarán transcripciones en estados `pending`, `queued`, `processing`, `error` o `dead` como parte de la cola principal.
- No se reemplazará el detalle existente de `/ia/api-transcriptor`; se reutilizará o enlazará cuando sea conveniente.

## Success Criteria

- El administrador puede abrir Correcciones y consultar las últimas diez transcripciones terminadas.
- Puede cambiar a una vista de las últimas diez transcripciones con reglas sensibles.
- Puede distinguir claramente texto original y texto corregido.
- Puede revisar el contexto temporal alrededor de cada cambio.
- Puede registrar una decisión humana sin modificar accidentalmente el diccionario.
- Puede navegar desde una coincidencia hacia la acción correspondiente sobre la regla.
- Las reglas `high` respetan la política documentada en nuevas transcripciones y en procesos retroactivos.
