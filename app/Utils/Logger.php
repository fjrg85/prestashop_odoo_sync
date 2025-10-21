<?php
namespace App\Utils;

class Logger
{
    private static string $dir;

    private static array $levels = [
        'DEBUG'   => 100,
        'INFO'    => 200,
        'WARNING' => 300,
        'ERROR'   => 400,
    ];

    private static function ensureDir(): void
    {
        if (!isset(self::$dir)) {
            self::$dir = Config::get('LOG_DIR', './logs');
            if (!is_dir(self::$dir)) {
                if (!mkdir(self::$dir, 0755, true) && !is_dir(self::$dir)) {
                    throw new \RuntimeException("Cannot create log directory " . self::$dir);
                }
            }
        }
    }

    private static function getLevelThreshold(): int
    {
        $level = strtoupper((string) Config::get('LOG_LEVEL', 'INFO'));
        return self::$levels[$level] ?? self::$levels['INFO'];
    }

    private static function shouldLog(string $level): bool
    {
        $threshold = self::getLevelThreshold();
        $value     = self::$levels[$level] ?? 999;
        return $value >= $threshold;
    }

    private static function line(string $level, string $message, array $ctx = []): string
    {
        $ts   = date('c'); // ISO8601
        $flow = $ctx['flow'] ?? 'app';
        $rid  = $ctx['requestId'] ?? '-';

        // Siempre incluir flow y requestId
        $ctx['flow']      = $flow;
        $ctx['requestId'] = $rid;

        // Si hay SKU, incluirlo explícitamente
        if (!isset($ctx['sku']) && isset($ctx['item']['sku'])) {
            $ctx['sku'] = $ctx['item']['sku'];
        }

        return sprintf(
            "[%s] [%s] [%s] [%s] %s | %s%s",
            $ts,
            $level,
            $flow,
            $rid,
            $message,
            json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            PHP_EOL
        );
    }

    private static function write(string $flow, string $level, string $message, array $ctx = []): void
    {
        self::ensureDir();
        $line = self::line($level, $message, $ctx);
        $file = self::$dir . "/$flow.log";
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // En entorno development, también a consola
        if (Config::get('APP_ENV') === 'development') {
            echo $line;
        }
    }

    public static function debug(string $message, array $ctx = []): void
    {
        if (Config::get('ENABLE_DEBUG_LOG', false) && self::shouldLog('DEBUG')) {
            self::write($ctx['flow'] ?? 'app', 'DEBUG', $message, $ctx);
        }
    }

    public static function info(string $message, array $ctx = []): void
    {
        if (self::shouldLog('INFO')) {
            self::write($ctx['flow'] ?? 'app', 'INFO', $message, $ctx);
        }
    }

    public static function warning(string $message, array $ctx = []): void
    {
        if (self::shouldLog('WARNING')) {
            self::write($ctx['flow'] ?? 'app', 'WARNING', $message, $ctx);
        }
    }

    public static function error(string $message, array $ctx = []): void
    {
        if (self::shouldLog('ERROR')) {
            self::write($ctx['flow'] ?? 'app', 'ERROR', $message, $ctx);
        }
    }
}