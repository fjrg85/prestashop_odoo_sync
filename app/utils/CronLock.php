<?php
namespace App\Utils;

class CronLock
{
    public static function acquire(string $path, int $ttl = 3600): bool
    {
        if (file_exists($path)) {
            $age = time() - filemtime($path);
            if ($age < $ttl) {
                return false; // Ya está bloqueado
            }
        }

        // Crear o actualizar el archivo de lock
        file_put_contents($path, (string)time());
        return true;
    }

    public static function release(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}