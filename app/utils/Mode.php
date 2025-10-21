<?php
namespace App\Utils;

class Mode
{
    /**
     * Determina si el script debe ejecutarse en modo dry-run.
     * - Si DRY_RUN=true en .env, siempre es simulación.
     * - Si DRY_RUN=false, solo se activa simulación si se pasa --dryrun=true.
     */
    public static function resolveDryRun(): bool
    {
        if (Config::isDryRun()) {
            return true;
        }

        $options = getopt('', ['dryrun::']);
        return isset($options['dryrun']) && filter_var($options['dryrun'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Interpreta el parámetro --range (ej. 30m, 2h, 1d) y devuelve un timestamp ISO8601.
     * Si no se pasa nada, usa el valor por defecto (ej. '1h').
     */
    public static function resolveTimeRange(string $default = '1h'): string
    {
        $options = getopt('', ['range::']);
        $range = $options['range'] ?? $default;

        $interval = self::parseTimeRange($range);
        return date('c', strtotime($interval));
    }

    /**
     * Convierte un rango como '30m', '2h', '1d' en un intervalo válido para strtotime().
     */
    public static function parseTimeRange(string $range): string
    {
        $range = trim($range);
        if (preg_match('/^(\d+)([hdm])$/', $range, $matches)) {
            $value = (int)$matches[1];
            $unit  = $matches[2];
            switch ($unit) {
                case 'h': return "-$value hours";
                case 'd': return "-$value days";
                case 'm': return "-$value minutes";
            }
        }
        return "-1 hour"; // fallback seguro
    }
}