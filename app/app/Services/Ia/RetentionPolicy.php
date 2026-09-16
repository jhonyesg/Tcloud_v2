<?php

namespace App\Services\Ia;

use App\Models\SystemSetting;

/**
 * RetentionPolicy: política de retención del audit log.
 *
 * Lee SystemSetting('audit_log_retention_days') con default 90.
 * Rango válido: 30 a 3650 días (1 mes a 10 años).
 */
class RetentionPolicy
{
    public const KEY = 'audit_log_retention_days';
    public const DEFAULT_DAYS = 90;
    public const MIN_DAYS = 30;
    public const MAX_DAYS = 3650;

    public static function getDays(): int
    {
        $value = (int) SystemSetting::get(self::KEY, self::DEFAULT_DAYS);
        return max(self::MIN_DAYS, min(self::MAX_DAYS, $value));
    }

    public static function setDays(int $days): int
    {
        $days = max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
        SystemSetting::set(self::KEY, $days);
        return $days;
    }
}
