## ADDED Requirements

### Requirement: Admin puede gestionar exclusiones dinámicas desde UI
El sistema SHALL exponer `/ia/correcciones → IA Suggest → Exclusiones`, una sección dedicada donde el admin puede agregar, archivar y restaurar términos que el AI Suggest NUNCA va a traducir (ej. eventos comerciales como "Black Friday", "San Valentín"; marcas regionales como "Open English"; nombres propios recurrentes en emisiones específicas). Cada exclusión SHALL persistir en la tabla `correction_protected_terms` con metadatos `term`, `category`, `notes`, `created_by`, `created_at`, `archived_at`. El motor `LlmCorrectionSuggester::looksLikeBrandOrProperNoun` SHALL consultar estas exclusiones (concat. con la lista `protected_brands` del config) tanto en el system prompt del LLM como en el post-filtro PHP. Los cambios SHALL aplicar a la próxima corrida en ≤5 minutos por cache TTL. Términos múltiples palabras y caracteres especiales del español (ñ, tildes) SHALL soportarse correctamente.

#### Scenario: Admin agrega "Black Friday" desde UI
- **WHEN** el admin abre `/ia/correcciones → IA Suggest → Exclusiones → Agregar exclusión`, escribe `black friday`, categoría `event`, notas `Black Friday NO se traduce — nombre propio del evento comercial`, y guarda
- **THEN** la fila aparece en la tabla de Exclusiones activas con `category=event`, `notes=<texto>`, `created_by=<admin>`, `created_at=<timestamp>`
- **THEN** una nueva fila en `correction_protected_terms` con `term='black friday'` (lowercase normalizado) y `archived_at=null`
- **THEN** en ≤5 minutos, la siguiente corrida de `corrections:ai-suggest` rechaza cualquier candidato cuyo `wrong` matchee "black friday" (completo o sub-frase) y lo cuenta en `rejected_by_filter`

#### Scenario: Admin archiva "Open English" tras una corrida que ya no aplica
- **WHEN** el admin hace click en "Archivar" junto a la fila "open english"
- **THEN** la fila desaparece del listado activo y aparece en el listado archivadas con `archived_at=<timestamp>`
- **THEN** `LlmCorrectionSuggester::looksLikeBrandOrProperNoun('Open English')` retorna `false` (ya no aplica); cualquier fila en la lista `protected_brands` del config sigue protegiendo marcas conocidas

#### Scenario: Admin restaura una exclusión archivada
- **WHEN** el admin abre "Mostrar archivadas" y hace click en "Restaurar" junto a una fila archivada
- **THEN** la fila vuelve a estar activa (sin duplicar) y se quita de archivadas

#### Scenario: Admin intenta agregar un término duplicado
- **WHEN** el admin intenta agregar "black friday" cuando ya existe activo
- **THEN** el endpoint responde 422 con `errors.term = 'black friday ya existe entre las exclusiones activas'` y la fila no se duplica

#### Scenario: Término multi-palabra con caracteres especiales
- **WHEN** el admin agrega "San Valentín" (con tilde) y luego un SRT contiene "el san valentín más esperado"
- **THEN** el post-filtro `str_contains` lo detecta case-insensitive (`'san valentín' ⊂ 'el san valentín más esperado'`) y rechaza el candidato
- **THEN** el system prompt del LLM incluye "do NOT propose changes on: san valentín" en la lista combinada `protected_brands` ∪ exclusiones dinámicas

#### Scenario: Cache TTL entre corrida y admin
- **WHEN** el admin agrega "Copa América" a las 10:00 y la última corrida AI Suggest fue a las 09:30 con cache de la lista en memoria
- **THEN** la próxima corrida a las 11:00 (≤5min después) ya ve "copa américa" en la lista. Si el admin necesita efecto inmediato en la corrida de las 11:00 sin esperar al TTL, puede correr `php artisan cache:forget correction_protected_terms:active`
