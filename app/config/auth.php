<?php

/*
 * Configuración de autenticación.
 *
 *   password_token_ttl → minutos de validez de los tokens emitidos por
 *   PasswordTokenService (setup welcome + password recovery). 1440 = 24h.
 */
return [
    'password_token_ttl' => (int) env('PASSWORD_TOKEN_TTL_MINUTES', 1440),
];
