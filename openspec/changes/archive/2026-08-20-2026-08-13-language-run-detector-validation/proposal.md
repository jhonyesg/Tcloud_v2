# Change: Validar el detector de idioma por racha antes de construirlo

> **Documento de traspaso.** Escrito para retomarse en una sesión nueva sin nada
> del contexto en que nació. Incluye lo que funcionó, lo que no, y por qué.

## Why

El corrector es la base sobre la que descansa la calidad del producto final. En
una sola jornada (2026-08-13) se probaron **tres enfoques** para reducir el
espanglish de las transcripciones. Los dos primeros parecían sólidos y **los dos
se cayeron al medirlos contra el corpus real**. El tercero también parece sólido.

Por eso esta change **no construye el detector**: lo valida primero. Si la
separación se sostiene con más días y más canales, se implementa. Si no, se
recalibra antes de escribir código.

### Enfoque 1 — diccionario de find/replace: descartado

Se creía que faltaban reglas. Lo que había era exceso: 3.055 reglas, muchas de
ellas destruyendo texto correcto.

- `the`→`la` (84.011 aplicaciones), `for`→`por`, `Good`→`Bien`, `top`→`cima`.
- Una regla de un solo token **no puede saber en qué idioma está la frase que la
  rodea**. Con el corpus lleno de inglés, hasta un arreglo de tilde impecable
  degrada: `"in the region of Antioquia"` → `"in the región of Antioquia"`.
- **Se borraron las 306 reglas de una palabra** (86.593 aplicaciones históricas)
  y **29 traducciones** pasaron a cuarentena.
- Estado actual: 126 reglas activas (todas de 2+ palabras), 2.534 en cuarentena,
  0 pendientes.

### Enfoque 2 — idioma esperado por canal: descartado el mismo día

Se marcaban canales enteros como no españoles para no corregirlos.

Objeción del admin, confirmada con datos: **Teleislas también emite en español**,
y hay transcripciones en español que el motor devuelve en inglés en cualquier
canal. Excluir por canal deja sin corregir el español del canal excluido y no ve
el defecto en los demás. Se revirtió.

### Enfoque 3 — racha de segmentos: a validar

Criterio del admin:

> *"No puede haber conversaciones que están en español y en un momento u otro hay
> inglés metido en medio, siguiendo la línea de la conversación, porque sería
> incongruente. Es congruente con propagandas, con música, pero no con
> entrevistas."*

Medición preliminar (300 transcripciones de un día):

```
  longitud de racha    % del inglés total
      1 segmento            32,0 %   ─┐
      2 segmentos           27,1 %   ─┘ 59,1 %  → defecto del motor
      3-4 segmentos         24,2 %             → zona ambigua
      5 o más               ~17 %              → canción / cuña legítima
```

Y el contenido separa igual de limpio. Rachas cortas, sin excepción en la
muestra:

```
  "It's a campo de action that the psiquiatria and the salute mental ayudar much"
                                                       └─ "salud mental"
  "This edific was a temple in Alabama"        └─ "edificio"
```

Rachas largas, sin excepción: canciones (Snow Patrol, Elvis).

**El dato que lo cambia todo**: el 59,1 % del inglés está en rachas de 1-2
segmentos, es decir, **es defecto y no contenido**. El agregado por canal decía
lo contrario y por eso llevó a la conclusión equivocada.

## What Changes

Esta change produce **evidencia, no código de producción**:

1. Un comando de análisis, `corrections:analyze-language-runs`, read-only, que
   calcula la distribución de rachas sobre una ventana y un conjunto de canales
   configurables.
2. Un informe con la distribución por día, por canal y por longitud de racha.
3. Una muestra etiquetable a mano para medir la precisión real de la frontera.
4. Una recomendación de umbrales, o la constatación de que la separación no se
   sostiene.

## Non-Goals

- **No** se implementa el detector.
- **No** se re-transcribe nada.
- **No** se toca el diccionario: queda como está, solo frases y aprobación humana.
- **No** se vuelve a excluir por canal.
- **No** se lanza ninguna corrida retroactiva.

## Criterio de éxito

La separación se considera validada si, sobre **≥5 días y ≥20 canales**:

- Las rachas de 1-2 segmentos son defecto en **≥85 %** de una muestra etiquetada
  a mano de 100 casos.
- Las rachas de 5+ son contenido legítimo en **≥85 %** de otra muestra de 100.
- La frontera se mantiene estable entre días (la proporción de rachas cortas no
  varía más de ±10 puntos).

Si no se cumple, la salida esperada es **una frontera distinta o un criterio
distinto**, no forzar el que hay.
