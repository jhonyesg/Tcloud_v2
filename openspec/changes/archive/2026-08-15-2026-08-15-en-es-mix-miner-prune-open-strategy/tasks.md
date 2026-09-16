## 1. Limpiar `EnEsMixMiner`

- [x] 1.1 Eliminar de `app/app/Services/Ia/EnEsMixMiner.php` el método público `mineOpen(int $daysBack, int $minFreq, int $sampleSize = 50000): array`
- [x] 1.2 Eliminar el método público `heuristicSpanish(string $phrase): ?string`
- [x] 1.3 Eliminar el método público `guessArticle(string $noun): string`
- [x] 1.4 Eliminar la constante pública `EN_FUNCTIONS`
- [x] 1.5 Eliminar la constante pública `COMMON_ES_NOUNS`
- [x] 1.6 Eliminar la constante privada `PREP_MAP`
- [x] 1.7 En `EnEsMixMiner::mine()` eliminar el parámetro `string $strategy` y el branching; dejar solo la llamada a `mineKnown()`
- [x] 1.8 Actualizar el docblock de cabecera de `EnEsMixMiner` para indicar que la estrategia B (open) fue retirada a favor de `LlmCorrectionSuggester` (long-tail con contexto) y de los mapeos KNOWN (curados manualmente); mencionar el umbral `corrections.min_suggestion_words=3` como razón

## 2. Limpiar `CorrectionService::mineEnEsMix()`

- [x] 2.1 En `app/app/Services/Ia/CorrectionService.php:820` cambiar la firma a `mineEnEsMix(int $daysBack, int $minFreq, User $by): array` (eliminar parámetro `string $strategy`)
- [x] 2.2 Eliminar la línea `$miner->mine($daysBack, $minFreq, $strategy)` y reemplazarla por `$miner->mineKnown($daysBack, $minFreq)` (o el nuevo punto de entrada simplificado)
- [x] 2.3 Buscar y actualizar todos los call sites de `mineEnEsMix` que pasen el tercer argumento `strategy` (grep en `app/`)
- [x] 2.4 Verificar que la key `'rejected_en_es'` en el array de retorno sigue siendo coherente (se mantiene igual: el guard transversal `isTooShortToPropose`/`isEnEsTranslation` no depende de la estrategia)

## 3. Limpiar CLI `corrections:mine-en-es`

- [x] 3.1 En `app/app/Console/Commands/MineEnEsCorrectionsCommand.php` quitar la opción `--strategy=known|open|both` del `$signature`
- [x] 3.2 Quitar de `handle()` la lectura de `$this->option('strategy')`, la validación de la opción y los mensajes que la mencionan
- [x] 3.3 Quitar la variable `$strategy` del mensaje `Mining EN↔ES: days={...} min-freq={...}` y de la llamada a `$service->mineEnEsMix(...)`
- [x] 3.4 Actualizar el docblock del comando para indicar que la única estrategia es KNOWN (curada manualmente) y que el comando está pensado para uso manual, no programado

## 4. Actualizar tests

- [x] 4.1 En `app/tests/Feature/CorreccionesEnEsMixTest.php` eliminar los casos que invocan `--strategy=open` o asertan candidatos con `strategy='open'`
- [x] 4.2 Añadir caso negativo: `php artisan corrections:mine-en-es --days=1 --strategy=open` debe fallar con exit code != 0 (Laravel rechaza opción desconocida)
- [x] 4.3 Añadir caso positivo: `mine()` retorna exclusivamente candidatos con `strategy='known'`
- [x] 4.4 Ejecutar `php artisan test --filter=CorreccionesEnEsMixTest` y verificar que pasa sin warnings ni deprecations
- [x] 4.5 Ejecutar `php artisan test` completo (o al menos las suites relacionadas) para confirmar que no hay regresiones en callers de `mineEnEsMix` o `mineOpen`

## 5. Verificación final

- [x] 5.1 Ejecutar `grep -rn "mineOpen\|EN_FUNCTIONS\|COMMON_ES_NOUNS\|PREP_MAP\|heuristicSpanish\|guessArticle" app/` y confirmar que no quedan referencias huérfanas (excepto comentarios históricos/git history)
- [x] 5.2 Ejecutar `php artisan corrections:mine-en-es --days=1 --dry-run` contra la BD de desarrollo y confirmar que retorna candidatos `strategy='known'` únicamente
- [x] 5.3 Ejecutar `php artisan list` y confirmar que `corrections:mine-en-es` aparece sin la opción `--strategy` en su help
- [x] 5.4 Revisar el comentario en `app/routes/console.php:113-138` (sección "Minería EN->ES: DESPROGRAMADA") y, si menciona `mineOpen`/`strategy=both`, dejarlo intacto porque refleja la decisión histórica; no requiere edición
