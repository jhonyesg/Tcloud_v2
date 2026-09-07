# Tasks — Conteo de ocurrencias y navegación por aparición

## 1. Base de datos

- [x] 1.1 Migración: columna `segment_keyword_hits.occurrences SMALLINT NOT NULL DEFAULT 1` + backfill por join (conteo accent-insensitive con translate en Postgres) + función auxiliar tcloud_ascii_lower
- [x] 1.2 Verificar backfill sobre los hits reales (los de abelardo/alvaro uribe deben quedar coherentes con su texto)

## 2. Motor

- [x] 2.1 KeywordMatcher::run(): calcular `occurrences = max(1, substr_count(asciiLower(text), keywordNorm))` al armar cada hit y persistirlo
- [x] 2.2 Prueba aislada: segmento con 5 repeticiones → occurrences=5; 1 repetición → 1; con tildes ("Álvaro") cuenta igual

## 3. Costura y visor

- [x] 3.1 MentionsSearchService::hitRow(): exponer `occurrences`
- [x] 3.2 _table-hits: badge "×N" junto al minuto cuando occurrences > 1
- [x] 3.3 Modal: marcadores por aparición en el segmento (cada resaltado de keyword clicable → seek interpolado por posición relativa del match)
- [x] 3.4 Prueba E2E con un segmento multi-ocurrencia (screenshot documentado)

## 3b. Total de apariciones por grabacion (feedback del usuario)

- [x] 3.5 MentionsSearchService: occurrenceTotals() (SUM agrupado por pagina, chunks de 100) y hitRow exponiendo occurrences_in_media
- [x] 3.6 Tabla: columna "Apariciones" (xtotal grabacion, violeta si >1) + detalle "xN aqui" para multi-ocurrencia del segmento
- [x] 3.7 E2E verificado: grabacion con 3 apariciones en 2 segmentos muestra x3 en ambas filas

## 3c. Atajo de filtro por keyword desde el chip del modal (feedback del usuario)

- [x] 3.8 El chip "mencion: <keyword>" del header es clicable: activa/quita el filtro de la transcripcion por esa keyword (isKeywordFilterActive/toggleKeywordFilter, resaltado visual de chip activo)
- [x] 3.9 E2E verificado: 78 visibles -> chip -> 2 visibles con conteo -> segundo clic restaura

## 4. Cierre

- [x] 4.1 php -l / view:clear; validar change --strict; archivar si el usuario lo pide