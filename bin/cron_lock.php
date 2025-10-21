<?php
// Usage: include this and call CronLock::acquire('/tmp/mylockfile.lock', 3600);
namespace App\Utils;

class CronLock
{
    public static function acquire(string $path, int $ttlSeconds = 3600): bool
    {
        if (file_exists($path)) {
            $age = time() - filemtime($path);
            if ($age < $ttlSeconds) return false;
        }
        file_put_contents($path, getmypid());
        return true;
    }

    public static function release(string $path): void
    {
        if (file_exists($path)) @unlink($path);
    }
}