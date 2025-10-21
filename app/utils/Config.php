<?php
namespace App\Utils;

class Config
{
    /**
     * Helper genérico para obtener variables de entorno con normalización
     */
    public static function get(string $key, string|int|null $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        // Normalizar booleanos
        $low = strtolower((string)$value);
        if (in_array($low, ['true','1','yes','on'], true)) return true;
        if (in_array($low, ['false','0','no','off'], true)) return false;

        return $value;
    }

    /**
     * Lanza excepción si falta una variable crítica
     */
    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Config error: missing required key '$key'");
        }
        return $value;
    }

    // -------------------------
    // App / entorno
    // -------------------------
    public static function env(): string
    {
        return (string) self::get('APP_ENV', 'production');
    }

    // -------------------------
    // Logging
    // -------------------------
    public static function logDir(): string
    {
        return (string) self::get('LOG_DIR', './logs');
    }

    public static function logLevel(): string
    {
        return strtoupper((string) self::get('LOG_LEVEL', 'INFO'));
    }

    public static function enableDebug(): bool
    {
        return (bool) self::get('ENABLE_DEBUG_LOG', false);
    }

    // -------------------------
    // Flags de ejecución
    // -------------------------
    public static function isDryRun(array $context = []): bool
    {
        $global = (bool) self::get('DRY_RUN', false);
        if (isset($context['dryrun'])) {
            return (bool) $context['dryrun'];
        }
        return $global;
    }

    public static function csvAlways(): bool
    {
        return (bool) self::get('CSV_ALWAYS', false);
    }

    // -------------------------
    // PrestaShop
    // -------------------------
    public static function prestaUrl(): string
    {
        return rtrim((string) self::get('PRESTA_URL', ''), '/');
    }

    public static function prestaKey(): string
    {
        return (string) self::get('PRESTA_KEY', '');
    }

    public static function usePrestaXml(): bool
    {
        return (bool) self::get('PRESTA_USE_XML', true);
    }

    public static function prestaTimeout(): int
    {
        return (int) self::get('PRESTA_TIMEOUT', 30);
    }

    public static function prestaAuthScheme(): string
    {
        return strtolower((string) self::get('PRESTA_AUTH_SCHEME', 'bearer'));
    }

    public static function prestaSearchPath(): string
    {
        return (string) self::get('PRESTA_SEARCH_PATH', '/products');
    }

    // -------------------------
    // Odoo
    // -------------------------
    public static function odooBaseUrl(): string
    {
        return rtrim((string) self::get('ODOO_BASE_URL', ''), '/');
    }

    public static function odooDb(): string
    {
        return (string) self::get('ODOO_DB', '');
    }

    public static function odooUser(): string
    {
        return (string) self::get('ODOO_USER', '');
    }

    public static function odooPass(): string
    {
        return (string) self::get('ODOO_PASS', '');
    }

    public static function odooApiKey(): string
    {
        return (string) self::get('ODOO_API_KEY', '');
    }

    public static function odooAuthScheme(): string
    {
        return strtolower((string) self::get('ODOO_AUTH_SCHEME', 'header'));
    }

    // -------------------------
    // Webhook
    // -------------------------
    public static function webhookToken(): string
    {
        return (string) self::get('WEBHOOK_TOKEN', '');
    }

    // -------------------------
    // Cache
    // -------------------------
    public static function cacheDir(): string
    {
        return (string) self::get('CACHE_DIR', './app/cache');
    }

    public static function cacheTtl(): int
    {
        return (int) self::get('CACHE_TTL_SECONDS', 3600);
    }

    // -------------------------
    // CSV / Auditoría
    // -------------------------
    public static function dryrunDir(): string
    {
        return (string) self::get('DRYRUN_DIR', './dryrun');
    }
}