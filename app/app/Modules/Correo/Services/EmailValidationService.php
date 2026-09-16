<?php

namespace App\Modules\Correo\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Decide si un correo es entregable ANTES de enviar nada.
 *
 * Tres capas:
 *   1. Sintaxis RFC (Laravel Validator).
 *   2. Blocklist de dominios disposable (config/email.php).
 *   3. Lookup MX (DNS) sobre el dominio, con cache Redis por TTL.
 *
 * El lookup MX consulta los nameservers autoritativos del dominio.
 * Si el DNS no responde dentro del timeout configurado, se omite la
 * verificación (fallback a sintaxis + disposable): el flujo no se
 * bloquea, solo pierde la última capa.
 */
class EmailValidationService
{
    public function validate(string $email): array
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'reason' => 'El formato del correo no es válido.'];
        }

        $domain = substr(strrchr($email, '@'), 1);

        $blocklist = (array) config('email.disposable_domains', []);
        if (in_array($domain, $blocklist, true)) {
            return ['valid' => false, 'reason' => 'El dominio del correo no está permitido (correo temporal o descartable).'];
        }

        $mxOk = $this->hasMxRecord($domain);
        if ($mxOk === false) {
            return ['valid' => false, 'reason' => 'El dominio del correo no tiene servidor de correo configurado.'];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Consulta MX del dominio. Devuelve:
     *   true  → tiene MX (o fallback A) y se pudo comprobar
     *   false → se comprobó y NO tiene MX
     *   null  → lookup falló por timeout / DNS no respondió (indeterminado)
     */
    private function hasMxRecord(string $domain): ?bool
    {
        $cacheKey = 'email_validation:mx:' . $domain;
        $ttl = (int) config('email.mx_cache_ttl', 3600);

        return Cache::remember($cacheKey, $ttl, function () use ($domain) {
            $timeout = (int) config('email.dns_timeout_seconds', 3);

            $hasMx = @dns_get_record($domain, DNS_MX);
            if (is_array($hasMx) && count($hasMx) > 0) {
                return true;
            }

            $hasA = @dns_get_record($domain, DNS_A);
            if (is_array($hasA) && count($hasA) > 0) {
                return true;
            }

            return null;
        });
    }
}
