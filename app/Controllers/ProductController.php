<?php
namespace App\Controllers;

use App\Services\ProductSyncService;
use App\Utils\RequestId;
use App\Utils\Logger;
use App\Utils\Config;

class ProductController
{
    public function sync(array $context = []): array
    {
        // Reutiliza o genera un requestId único
        $requestId = RequestId::fromContext($context);

        Logger::info("Inicio sync products", [
            'flow'      => 'product',
            'requestId' => $requestId,
            'dryrun'    => Config::isDryRun($context),
            'csvAlways' => Config::csvAlways()
        ]);

        $service = new ProductSyncService($requestId);
        $result  = $service->run($context);

        Logger::info("Fin sync products", [
            'flow'      => 'product',
            'requestId' => $requestId,
            'summary'   => $result['summary'] ?? ''
        ]);

        return $result;
    }
}