#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap\AppBootstrap;
AppBootstrap::init();

use App\Controllers\StockController;
use App\Utils\RequestId;
use App\Utils\Logger;
use App\Utils\CronLock;
use App\Utils\Mode;

// 🆘 Mostrar ayuda si se solicita
if (in_array('--help', $argv)) {
    echo "\n🛠️ Ayuda para tareas cron de sincronización\n";
    echo "-------------------------------------------\n";
    echo "Uso:\n";
    echo "  php cron_stock_sync.php [--dryrun=true|false] [--range=30m|1h|2d] [--force=true]\n\n";

    echo "Parámetros disponibles:\n";
    echo "  --dryrun     Ejecuta en modo simulación. Tiene efecto solo si DRY_RUN=false en .env\n";
    echo "  --range      Define el rango de corte (ej. 30m = 30 minutos, 1h = 1 hora, 2d = 2 días)\n";
    echo "  --force      Ignora el timestamp persistente y usa el rango directamente\n";
    echo "  --help       Muestra esta ayuda\n\n";

    echo "Prioridad de ejecución:\n";
    echo "  - Si DRY_RUN=true en .env → siempre se ejecuta en modo simulación\n";
    echo "  - Si DRY_RUN=false → se ejecuta en modo real, salvo que se pase --dryrun=true\n\n";

    echo "Ejemplos:\n";
    echo "  php cron_stock_sync.php --range=30m --dryrun=true\n";
    echo "  php cron_stock_sync.php --force=true\n";
    echo "  php cron_stock_sync.php --dryrun=false\n\n";

    exit(0);
}

// 📁 Ruta del archivo de sincronización persistente
$syncFile = __DIR__ . '/../logs/.stock_sync_timestamp';

// 🕓 Guardar nueva fecha de sincronización
function saveSyncTimestamp(string $path, string $timestamp): void {
    file_put_contents($path, $timestamp);
}

// 🔐 Lock para evitar concurrencia
$lock = __DIR__ . '/stock_sync.lock';
if (!CronLock::acquire($lock, 3600)) {
    echo "⏳ Otra ejecución en curso\n";
    exit(0);
}

// 🧩 Leer parámetros
$options = getopt('', ['force::']);
$force   = isset($options['force']) ? filter_var($options['force'], FILTER_VALIDATE_BOOLEAN) : false;

$requestId = RequestId::current();
$dryrun    = Mode::resolveDryRun();
$range     = Mode::resolveTimeRange('1h');

// 🕒 Determinar fecha de corte
$desde = $force || !file_exists($syncFile)
    ? $range
    : date('c', strtotime(file_get_contents($syncFile)));

Logger::info("CRON: inicio sync stock", [
    'flow'      => 'stock',
    'requestId' => $requestId,
    'range'     => $range,
    'desde'     => $desde,
    'dryrun'    => $dryrun,
    'force'     => $force
]);

try {
    $context = [
        'dryrun' => $dryrun,
        'since'  => $desde,
        'requestId' => $requestId
    ];

    $ctrl = new StockController();
    $res  = $ctrl->sync($context);

    Logger::info("CRON: fin sync stock", [
        'flow'      => 'stock',
        'requestId' => $requestId,
        'summary'   => $res['summary'] ?? '',
        'count'     => $res['count'] ?? 0
    ]);

    if (!$dryrun) {
        saveSyncTimestamp($syncFile, date('c'));
    }

    echo "✅ Stock sincronizado: {$res['count']} procesados\n";
    CronLock::release($lock);
    exit(0);
} catch (\Throwable $e) {
    Logger::error("Cron fallo: ".$e->getMessage(), [
        'flow'      => 'stock',
        'requestId' => $requestId
    ]);
    CronLock::release($lock);
    echo "❌ ERROR\n";
    exit(1);
}