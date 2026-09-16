# avisos-scan-coverage-observability-and-ux

Cierra tres ángulos detectados durante la implementación del change anterior: (A) compliance/visibilidad del watermark_audit_log vía UI y endpoint + retención por archivo; (B) consistencia de UX (cache invalidation en mutaciones + polling real del full scan); (C) higiene (UserStorage::created hook, fix orphans, deprecate del coverage() legacy, validación de rewind sobre (k,s) huérfanos).
