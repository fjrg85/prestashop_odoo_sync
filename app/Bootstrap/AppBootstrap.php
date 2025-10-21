<?php
namespace App\Bootstrap;

use Dotenv\Dotenv;

class AppBootstrap
{
    private static bool $loaded = false;

    public static function init(): void
    {
        if (self::$loaded) return;

        $root = __DIR__ . '/../../';
        if (file_exists($root . '.env')) {
            $dotenv = Dotenv::createImmutable($root);
            $dotenv->load();
        }

        self::$loaded = true;
    }
}