<?php
namespace App\Controllers;

use App\Services\StockSyncService;
use App\Utils\RequestId;
use App\Utils\Logger;
use App\Utils\Config;

class WebhookController
{
    /**
     * Recibe un webhook de PrestaShop para actualizar stock en Odoo.
     * Payload esperado: ['sku' => 'ABC123', 'qty' => 10]
     */
    public function stock(array $payload): array
    {
        $requestId = RequestId::fromContext($payload);

        Logger::info("Webhook recibido para actualizar stock", [
            'flow'      => 'stock',
            'requestId' => $requestId,
            'sku'       => $payload['sku'] ?? null,
            'qty'       => $payload['qty'] ?? null,
            'dryrun'    => Config::isDryRun(),
            'csvAlways' => Config::csvAlways()
        ]);

        // Validación mínima
        if (empty($payload['sku']) || !isset($payload['qty'])) {
            Logger::warning("Payload inválido en webhook stock", [
                'flow'      => 'stock',
                'requestId' => $requestId,
                'sku'       => $payload['sku'] ?? null,
                'qty'       => $payload['qty'] ?? null
            ]);
            Logger::debug("Payload completo inválido", [
                'flow'      => 'stock',
                'requestId' => $requestId,
                'payload'   => $payload
            ]);
            return [
                'status'    => 'error',
                'message'   => 'Invalid payload',
                'requestId' => $requestId
            ];
        }

        // Ejecutar actualización directa de stock
        $service = new StockSyncService($requestId);
        $result  = $service->run(['webhook_payload' => $payload]);

        Logger::info("Webhook procesado", [
            'flow'      => 'stock',
            'requestId' => $requestId,
            'summary'   => $result['summary'] ?? ''
        ]);

        return [
            'status'    => 'ok',
            'summary'   => $result['summary'] ?? '',
            'count'     => $result['count'] ?? 0,
            'requestId' => $requestId
        ];
    }
}