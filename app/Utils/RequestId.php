<?php
namespace App\Utils;

class RequestId
{
    private static ?string $id = null;

    /**
     * Genera un nuevo ID único.
     * - Si $asUuid = true → UUID v4
     * - Si no → timestamp + random hex
     * - Se puede añadir un prefijo
     */
    public static function generate(bool $asUuid = false, string $prefix = ''): string
    {
        try {
            if ($asUuid) {
                // UUID v4
                $data = random_bytes(16);
                $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
                $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
                $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
                self::$id = $prefix . $uuid;
            } else {
                // Timestamp + random hex (ordenable y trazable)
                $ts = gmdate('Ymd\THis\Z');
                $rand = bin2hex(random_bytes(3));
                self::$id = $prefix . $ts . '-' . $rand;
            }
        } catch (\Throwable) {
            // Fallback robusto
            self::$id = $prefix . uniqid('', true);
        }

        return self::$id;
    }

    /**
     * Devuelve el ID actual, generándolo si aún no existe.
     */
    public static function current(bool $asUuid = false, string $prefix = ''): string
    {
        if (self::$id === null) {
            return self::generate($asUuid, $prefix);
        }
        return self::$id;
    }

    /**
     * Permite forzar un ID específico (ej. recibido desde un webhook).
     */
    public static function set(string $id): void
    {
        self::$id = $id;
    }

    /**
     * Resetea el ID (para pruebas o nuevos flujos).
     */
    public static function reset(): void
    {
        self::$id = null;
    }

    /**
     * Obtiene un ID desde el contexto o genera uno nuevo.
     */
    public static function fromContext(array $context = [], bool $asUuid = false, string $prefix = ''): string
    {
        if (!empty($context['requestId']) && is_string($context['requestId'])) {
            self::$id = $context['requestId'];
            return self::$id;
        }
        return self::current($asUuid, $prefix);
    }
}