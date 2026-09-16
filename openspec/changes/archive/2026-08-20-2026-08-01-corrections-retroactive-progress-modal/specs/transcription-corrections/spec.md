## MODIFIED Requirements

### Requirement: Botón "Ver" del banner de proceso retroactivo abre modal de detalle de progreso

El banner de "Re-aplicar en curso" SHALL exponer un botón **"Ver"** que abre un modal dedicado al detalle del progreso cuando ya existe un run vivo (`runId` set). El modal SHALL mostrar:
- Texto de status (`runStatusText` traducida: "En cola…" / "Procesando…" / "Terminada" / "Falló").
- Barra de progreso con porcentaje (`runProgressPct`) y contador (`runProgress`).
- Aviso ámbar "Sin avances desde las HH:MM" si la corrida está estancada (`runStuck`).
- Botón **"Refrescar estado"** que ejecuta `pollRun()` inmediatamente (sin esperar el intervalo de 2s).
- Botón **"Cerrar"** que cierra el modal sin alterar el estado del run subyacente.

El modal SHALL **NO** mostrar el selector de scope ni el botón "Confirmar y aplicar" — el admin no debe poder lanzar una segunda corrida mientras otra está en curso (esa lógica ya vive en el 409 anti-duplicados del backend).

#### Scenario: Admin recarga la página, ve el banner y hace click "Ver"
- **WHEN** el admin recarga `/ia/correcciones` mientras hay un run retroactivo en curso, ve el banner "Re-aplicar en curso · X%", y hace click en "Ver"
- **THEN** se abre un modal con la barra de progreso, contador de segmentos, status text, y un aviso ámbar si está estancado
- **THEN** el modal **NO** muestra dropdown de scope ni el botón "Confirmar y aplicar"
- **WHEN** el admin hace click en "Refrescar estado"
- **THEN** se ejecuta un poll inmediato a `/ia/correcciones/apply-retroactive/{runId}` y el modal re-renderiza con los datos frescos
- **WHEN** el admin hace click en "Cerrar"
- **THEN** el modal cierra sin resetear `runId`, sin afectar el polling del banner, y el run sigue corriendo en background

#### Scenario: Botón "Re-aplicar" del header sigue abriendo modal de nuevo launch
- **WHEN** el admin clickea el botón "Re-aplicar" del header del módulo (no hay run en curso)
- **THEN** se abre el modal de launch con dropdown de scope y botón "Confirmar y aplicar" (comportamiento existente, no roto por este cambio)

#### Scenario: No se puede ver progreso en modal cuando no hay run vivo
- **WHEN** el admin hace click en "Re-aplicar" sin haber ningún run vivo
- **THEN** el modal muestra el dropdown de scope — la vista de progreso solo aparece cuando hay `runId` (banner minimizado no se muestra sin run, así que el admin no llega a "Ver")

#### Scenario: Modal de progreso se cierra automáticamente al terminar el run
- **WHEN** el poll detecta `status='done'` y el admin tiene el modal abierto
- **THEN** el polling se detiene (no más `/apply-retroactive/{id}`), el banner se oculta, el toast verde de éxito ya se mostró; el modal puede quedar abierto con su último estado, pero el botón "Refrescar" desaparece (no queda nada que refrescar)
