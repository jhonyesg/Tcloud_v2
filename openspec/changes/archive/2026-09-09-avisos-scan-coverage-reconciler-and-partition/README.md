# avisos-scan-coverage-reconciler-and-partition

Cierra el backlog del change de watermarks: (a) hooks UserKeyword::created/saved y user_storages.transcription_access para mantener cobertura sincronizada sin intervención manual, (b) reconciler periódico con reporte de drift, (c) auditoría de rewind/runFullScan (quién/cuándo/qué par), (d) particionamiento por mes de segment_keyword_hits con DROP PARTITION para retención, (e) mejoras de UX: paginación de coverage, contador de pares por rewind, polling de full scan.
