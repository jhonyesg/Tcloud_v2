## MODIFIED Requirements

### Requirement: Disparo manual acotado desde la sub-ventana

La sub-ventana SHALL ofrecer "Escanear ahora" con filtros puntuales: storage específico, keyword específica, ventana temporal mediante presets (8 h, 24 h, 3 días, 7 días, hoy) o rango personalizado con fecha y hora opcional, modo "full scan" (barra todas las keywords atrasadas en su storage), y opción forzar re-escaneo (borra los hits previos de las transcripciones objetivo antes de escanear). Los límites `from`/`to` del rango personalizado SHALL respetar el componente de hora cuando se proporciona; si solo viene fecha, se interpreta como día completo (startOfDay/endOfDay). El estimado mostrado en la confirmación SHALL reflejar los filtros aplicados. La corrida manual SHALL registrar su resumen en `avisos_scan_runs` con origen manual o `full` y SHALL respetar el límite de lote por corrida (el admin puede encadenar corridas). El botón "Escaneo completo" SHALL correr en background con un `runId` y la UI SHALL hacer polling del estado (iteración, pares, hits, tiempo) hasta `done`/`error`. El botón "Activar histórico" SHALL invocar primero un preview sin mutación y mostrar cuántos pares se verán afectados antes del confirm final. Toda mutación SHALL registrar en `watermark_audit_log` con `actor_user_id=session('user_id')`.

#### Scenario: Re-escaneo forzado de un storage del día
- **WHEN** el admin lanza "Escanear ahora" con el storage "Negocios Ditu", rango de hoy y opción forzar
- **THEN** se borran los hits previos de las transcripciones terminadas hoy de ese storage y se re-escanean, registrando la corrida como manual con su resumen

#### Scenario: Full scan masivo requiere confirmación
- **WHEN** el admin solicita un "escaneo completo" cuya estimación de pares pendientes supera el umbral configurable
- **THEN** la sub-ventana muestra la estimación y exige confirmación explícita antes de encolar la corrida

#### Scenario: Full scan reporta progreso en vivo
- **WHEN** el admin lanza un "escaneo completo"
- **THEN** la UI hace polling cada 2s al endpoint del runId y muestra iteración / pares / hits / tiempo hasta `done`/`error`

#### Scenario: Rango con solo fecha mantiene semántica de día completo
- **WHEN** el admin envía un rango personalizado solo con fecha (sin hora) en Desde
- **THEN** el escaneo aplica desde el inicio de ese día, igual que el comportamiento previo

#### Scenario: Rewind con preview antes de confirmar
- **WHEN** el admin hace clic en "Activar histórico" sobre un par
- **THEN** la UI consulta `POST /rewind?preview=true` y muestra "Esto procesará N transcripciones" antes de pedir confirmación; la mutación final registra en `watermark_audit_log`

#### Scenario: Rango masivo requiere confirmación
- **WHEN** el admin solicita un rango cuya estimación de transcripciones supera el límite de lote
- **THEN** la sub-ventana muestra la estimación y exige confirmación explícita antes de encolar la corrida
