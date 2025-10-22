<?php
namespace App\Utils;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Adapta nuestro Logger personalizado a PSR-3 LoggerInterface
 * para poder usarlo con librerías externas (OdooClient, etc.)
 */
class LoggerAdapter implements LoggerInterface
{
    private array $context;

    public function __construct(array $context = [])
    {
        $this->context = $context;
    }

    public function emergency($message, array $context = []): void
    {
        Logger::error((string)$message, array_merge($this->context, $context));
    }

    public function alert($message, array $context = []): void
    {
        Logger::error((string)$message, array_merge($this->context, $context));
    }

    public function critical($message, array $context = []): void
    {
        Logger::error((string)$message, array_merge($this->context, $context));
    }

    public function error($message, array $context = []): void
    {
        Logger::error((string)$message, array_merge($this->context, $context));
    }

    public function warning($message, array $context = []): void
    {
        Logger::warning((string)$message, array_merge($this->context, $context));
    }

    public function notice($message, array $context = []): void
    {
        Logger::info((string)$message, array_merge($this->context, $context));
    }

    public function info($message, array $context = []): void
    {
        Logger::info((string)$message, array_merge($this->context, $context));
    }

    public function debug($message, array $context = []): void
    {
        Logger::debug((string)$message, array_merge($this->context, $context));
    }

    public function log($level, $message, array $context = []): void
    {
        match($level) {
            LogLevel::DEBUG => Logger::debug((string)$message, array_merge($this->context, $context)),
            LogLevel::INFO, LogLevel::NOTICE => Logger::info((string)$message, array_merge($this->context, $context)),
            LogLevel::WARNING => Logger::warning((string)$message, array_merge($this->context, $context)),
            default => Logger::error((string)$message, array_merge($this->context, $context))
        };
    }
}