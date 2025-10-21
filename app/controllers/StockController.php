<?php
namespace App\Controllers;

use App\Services\StockSyncService;
use App\Utils\RequestId;
use App\Utils\Logger;
use App\Utils\Config;

class StockController
{
    public function sync(array $context = []): array
    {
        $requestId = RequestId::fromContext($context);

        Logger::info("Inicio sync stock", [
            'flow'      => 'stock',
            'requestId' => $requestId,
            'dryrun'    => Config::isDryRun($context),
            'csvAlways' => Config::csvAlways()
        ]);

        $service = new StockSyncService($requestId);
        $result  = $service->run($context);

        Logger::info("Fin sync stock", [
            'flow'      => 'stock',
            'requestId' => $requestId,
            'summary'   => $result['summary'] ?? '',
            'count'     => $result['count'] ?? null
        ]);

        return $result;
    }

    public function receive(array $payload): array
    {
        $requestId = RequestId::fromContext($payload);

        Logger::info("Inicio receive stock webhook", [
            'flow'      => 'stock',
            'requestId' => $requestId,
            'sku'       => $payload['sku'] ?? null,
            'qty'       => $payload['qty'] ?? null
        ]);

        $context = [
            'webhook_payload' => $payload,
            'requestId'       => $requestId,
            'dryrun'          => Config::isDryRun() // opcional si quieres que los webhooks respeten DRY_RUN
        ];

        $service = new StockSyncService($requestId);
        $result  = $service->run($context);

        Logger::info("Fin receive stock webhook", [
            'flow'      => 'stock',
            'requestId' => $requestId,
            'summary'   => $result['summary'] ?? '',
            'count'     => $result['count'] ?? null
        ]);

        return $result;
    }
}