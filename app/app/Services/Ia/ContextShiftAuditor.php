<?php

namespace App\Services\Ia;

/**
 * Auditor de context-shift (changes/2026-08-02-corrections-dictionary-atomicity).
 *
 * Salida: detecta muletillas y falsos amigos en correcciones para que el admin
 * las marque como risk=high antes de aprobar (cambia el tono / contexto).
 *
 * Tras los cambios/2026-08-18-corrections-coherence-learn-fix-and-pending-triage,
 * este servicio YA NO corre en batch (se eliminó la vista "Contexto sensible",
 * el comando artisan y los endpoints asociados). El único caller vivo es
 * `CorreccionesController::buildContextWarning()` que llama a `evaluateOne()`
 * como pre-approval safeguard cuando el admin aprueba una corrección puntual.
 *
 * Si más adelante se necesita el batch audit de nuevo, basta extraer
 * evaluateOne() a un cliente y re-empaquetar el chunk loop aquí.
 */
class ContextShiftAuditor
{
    /**
     * Evalúa una sola corrección (o un objeto compatible). Retorna null si no
     * se detecta ningún patrón sensible.
     *
     * Acepta tanto un Correction (modelo Eloquent) como un stdClass con los
     * campos requeridos, para que pueda invocarse también con correcciones
     * que aún no están persistidas (ej: pre-approval safeguard en controller).
     *
     * @param  object  $r  objeto con id, wrong_text, correct_text, risk_level
     * @param  ?array  $config  opcional, usa config('corrections.context_sensitive') por default
     * @return ?array{risk: string, reason: string, matched: ?string, type: string, safe_translations: array}
     */
    public function evaluateOne(object $r, ?array $config = null): ?array
    {
        $config = $config ?? $this->config();
        $wrong = mb_strtolower((string) $r->wrong_text);
        $correct = mb_strtolower((string) $r->correct_text);

        // 1) Falsos amigos primero (más específico, tiene unsafe translations).
        foreach (($config['false_friends'] ?? []) as $ff) {
            if (!preg_match('/\b' . preg_quote($ff['term'], '/') . '\b/i', $wrong)) {
                continue;
            }
            $unsafeHit = null;
            foreach ($ff['unsafe'] ?? [] as $u) {
                if (mb_strpos($correct, $u) !== false) {
                    $unsafeHit = $u;
                    break;
                }
            }
            if ($unsafeHit !== null) {
                $safeList = $ff['safe_translations'] ?? [];
                return [
                    'risk' => $ff['risk'] ?? 'high',
                    'reason' => "false friend: '{$ff['term']}' translated as '{$unsafeHit}' (unsafe); safe: " . implode(', ', $safeList),
                    'type' => 'false_friend',
                    'matched' => $ff['term'],
                    'safe_translations' => $safeList,
                ];
            }
            // Si el término aparece pero la traducción es OK (no unsafe), no sugerimos cambio.
            // (ej: 'realize' → 'darse cuenta' es seguro.)
        }

        // 2) Muletillas / fillers.
        foreach (($config['filler_words'] ?? []) as $f) {
            if (preg_match('/\b' . preg_quote($f['term'], '/') . '\b/i', $wrong)) {
                return [
                    'risk' => $f['risk'] ?? 'medium',
                    'reason' => "contains '{$f['term']}' ({$f['note']})",
                    'type' => 'filler',
                    'matched' => $f['term'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array{filler_words: array, false_friends: array}
     */
    private function config(): array
    {
        return (array) config('corrections.context_sensitive', []);
    }
}
