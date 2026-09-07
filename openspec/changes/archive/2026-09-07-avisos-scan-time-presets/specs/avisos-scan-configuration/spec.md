## MODIFIED Requirements

### Requirement: Disparo manual acotado desde la sub-ventana

La sub-ventana SHALL ofrecer "Escanear ahora" con filtros puntuales: storage específico, ventana temporal mediante presets (8 h, 24 h, 3 días, 7 días, hoy) o rango personalizado con fecha y hora opcional, y opción forzar re-escaneo (borra los hits previos de las transcripciones objetivo antes de escanear). Los límites `from`/`to` del rango personalizado SHALL respetar el componente de hora cuando se proporciona; si solo viene fecha, se interpreta como día completo (startOfDay/endOfDay). El estimado mostrado en la confirmación SHALL reflejar los filtros aplicados. La corrida manual SHALL registrar su resumen en `avisos_scan_runs` con origen manual y SHALL respetar el límite de lote por corrida (el admin puede encadenar corridas).

#### Scenario: Re-escaneo forzado de un storage del día
- **WHEN** el admin lanza "Escanear ahora" con el storage "Negocios Ditu", rango de hoy y opción forzar
- **THEN** se borran los hits previos de las transcripciones terminadas hoy de ese storage y se re-escanean, registrando la corrida como manual con su resumen

#### Scenario: Rango masivo requiere confirmación
- **WHEN** el admin solicita un rango cuya estimación de transcripciones supera el límite de lote
- **THEN** la sub-ventana muestra la estimación y exige confirmación explícita antes de encolar la corrida

#### Scenario: Rango con solo fecha mantiene semántica de día completo
- **WHEN** el admin envía un rango personalizado solo con fecha (sin hora) en Desde
- **THEN** el escaneo aplica desde el inicio de ese día, igual que el comportamiento previo