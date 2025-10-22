<?php
namespace App\Clients;

use App\Utils\Config;
use App\Utils\Logger;

class PrestaClientAdapter
{
    private array $fieldMap = [
        'qty'        => 'quantity',
        'quantity'   => 'quantity',
        'stock'      => 'quantity',
        'price'      => 'price',
        'list_price' => 'price',
    ];

    private ?OdooClient $odoo = null;

    public function __construct(
        private PrestaClient $client,
        private string $requestId,
        private ?SkuResolver $resolver = null,
        ?OdooClient $odoo = null
    ) {
        if ($this->resolver === null) {
            $this->resolver = new SkuResolver(Config::get('PRESTA_URL'), Config::get('PRESTA_KEY'));
        }
        $this->odoo = $odoo;
    }

    /**
     * Obtener producto en PrestaShop por SKU
     */
    public function getProductBySku(string $sku): ?array
    {
        $skuNorm = $this->normalizeSku($sku);
        $prestaId = $this->resolver->getPrestaIdFromSku($skuNorm);

        if ($prestaId === null) {
            Logger::info("Producto no encontrado en PrestaShop para SKU", [
                'flow'=>'product','requestId'=>$this->requestId,'sku'=>$skuNorm
            ]);
            return null;
        }

        try {
            $path = "/products/{$prestaId}";
            $res = $this->client->get($path, 'product');
            if (($res['ok'] ?? false) && isset($res['body'])) {
                return $res['body'];
            }
            return null;
        } catch (\Throwable $e) {
            Logger::error("Error obteniendo producto por SKU: " . $e->getMessage(), [
                'flow'=>'product','requestId'=>$this->requestId,'sku'=>$skuNorm,'presta_id'=>$prestaId
            ]);
            return null;
        }
    }

    /**
     * Actualizar producto en PrestaShop por SKU (con validaciones)
     */
    public function updateProductPartialBySku(string $sku, array $fields, array $context = []): array
    {
        $flow = 'product';
        $skuNorm = $this->normalizeSku($sku);

        Logger::debug("Adapter start updateProductPartialBySku", [
            'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm
        ]);

        if (Config::isDryRun($context)) {
            Logger::info("dryRun active - skip PATCH", [
                'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm,'fields'=>$fields
            ]);
            return ['ok'=>true, 'dryrun'=>true];
        }

        // 1. Consultar producto actual
        $product = $this->getProductBySku($skuNorm);
        if (!$product) {
            return ['ok'=>false, 'reason'=>'not_found'];
        }

        $prestaId = $product['id'] ?? null;
        if (!$prestaId) {
            return ['ok'=>false, 'reason'=>'invalid_id'];
        }

        // 2. Normalizar campos
        $payload = $this->normalizeFields($fields);

        // 3. Validar cantidad negativa
        if (isset($payload['quantity']) && $payload['quantity'] < 0) {
            Logger::warning("Cantidad negativa detectada, se fuerza a 0", [
                'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm,'qty'=>$payload['quantity']
            ]);
            $payload['quantity'] = 0;
        }

        // 4. Comparar antes/después
        $changes = [];
        foreach ($payload as $k => $v) {
            $before = $product[$k] ?? null;
            if ($before != $v) {
                $changes[$k] = ['before'=>$before, 'after'=>$v];
            }
        }

        if (empty($changes)) {
            Logger::info("No hay cambios, se omite actualización", [
                'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm
            ]);
            return ['ok'=>true, 'skipped'=>true];
        }

        // 5. Ejecutar PATCH
        try {
            $path = "/products/{$prestaId}";
            $res = $this->client->patch($path, $payload, 'product');
            Logger::info("Presta PATCH executed", [
                'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm,
                'presta_id'=>$prestaId,'http_code'=>$res['code'],'changes'=>$changes
            ]);
            return ['ok'=>true, 'changes'=>$changes] + $res;
        } catch (\Throwable $e) {
            Logger::error("Error calling Presta PATCH: " . $e->getMessage(), [
                'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm,'presta_id'=>$prestaId
            ]);
            return ['ok'=>false, 'reason'=>'http_error', 'message'=>$e->getMessage()];
        }
    }

    /**
     * Sincronizar venta de PrestaShop hacia Odoo
     */
    public function syncSaleToOdoo(string $sku, int $soldQty): array
    {
        $flow = 'sync_sale';
        $skuNorm = $this->normalizeSku($sku);

        Logger::info("Procesando venta desde PrestaShop", [
            'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm,'soldQty'=>$soldQty
        ]);

        // Validar que odoo esté disponible
        if ($this->odoo === null) {
            Logger::error("OdooClient no disponible para syncSaleToOdoo", [
                'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm
            ]);
            return ['ok'=>false, 'reason'=>'odoo_not_configured'];
        }

        // 1. Buscar producto en Odoo
        $products = $this->odoo->fetchProducts();
        $product = null;
        foreach ($products as $p) {
            if (mb_strtoupper($p['sku'] ?? '') === $skuNorm) {
                $product = $p;
                break;
            }
        }

        if (!$product) {
            Logger::error("Producto no encontrado en Odoo para SKU", [
                'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$skuNorm
            ]);
            return ['ok'=>false, 'reason'=>'not_found'];
        }

        // 2. Calcular nuevo stock
        $currentQty = (float)($product['quantity'] ?? 0);
        $newQty = max(0, $currentQty - $soldQty);

        // 3. Ajustar stock en Odoo
        return $this->odoo->adjustStock((int)$product['id'], $newQty);
    }

    private function normalizeSku(string $sku): string
    {
        return mb_strtoupper(trim($sku));
    }

    private function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $k => $v) {
            $key = $this->fieldMap[$k] ?? $k;
            $normalized[$key] = $v;
        }
        return $normalized;
    }
}