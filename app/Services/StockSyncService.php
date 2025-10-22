<?php
namespace App\Services;

use App\Clients\PrestaClient;
use App\Clients\PrestaClientAdapter;
use App\Clients\SkuResolver;
use App\Clients\OdooClient;
use App\Utils\CsvWriter;
use App\Utils\Logger;
use App\Utils\Config;
use App\Utils\LoggerAdapter;

class StockSyncService
{
    public function __construct(private string $requestId) {}

    public function run(array $context = []): array
    {
        $flow = 'stock';
        Logger::debug("Inicio StockSyncService::run", [
            'flow' => $flow,
            'requestId' => $this->requestId
        ]);

        $isDryRun  = Config::isDryRun($context);
        $csvAlways = Config::csvAlways();

        // Odoo
        $odoo = new OdooClient(
            Config::get('ODOO_BASE_URL'),
            Config::get('ODOO_DB'),
            Config::get('ODOO_USER'),
            Config::get('ODOO_PASS'),
            new LoggerAdapter(['flow' => 'stock', 'requestId' => $this->requestId]),
            $this->requestId // ← para trazabilidad
        );

        $odoo->authenticate();
        
        // Construir lista de items
        $items = [];
        if (!empty($context['webhook_payload']) && is_array($context['webhook_payload'])) {
            foreach ((isset($context['webhook_payload'][0]) ? $context['webhook_payload'] : [$context['webhook_payload']]) as $it) {
                if (isset($it['sku'], $it['quantity'])) {
                    $items[] = ['sku' => $it['sku'], 'quantity' => (int)$it['quantity']];
                }
            }
        } else {
            if (!method_exists($odoo, 'fetchProducts')) {
                Logger::error("Método fetchProducts no existe en OdooClient", [
                    'flow' => $flow,
                    'requestId' => $this->requestId
                ]);
                throw new \RuntimeException("fetchProducts no disponible");
            }

            $products = $odoo->fetchProducts($context['since'] ?? null);
            foreach ($products as $p) {
                $items[] = [
                    'sku'      => $p['sku'] ?? '',           // ✅ Cambiar a 'sku'
                    'quantity' => (int)($p['quantity'] ?? 0), // ✅ Cambiar a 'quantity'
                    'price'    => (float)($p['price'] ?? 0)   // ✅ Cambiar a 'price'
                ];
            }

            Logger::debug("Items construidos desde Odoo", [
                'flow' => $flow,
                'requestId' => $this->requestId,
                'count' => count($items),
                'sample' => array_slice($items, 0, 3)
            ]);
        }

        // PrestaShop
        $presta   = new PrestaClient(Config::get('PRESTA_URL'), Config::get('PRESTA_KEY'), Config::usePrestaXml());
        $adapter  = new PrestaClientAdapter($presta, $this->requestId);
        $resolver = new SkuResolver(Config::get('PRESTA_URL'), Config::get('PRESTA_KEY'));

        $csvRows = [];

        foreach ($items as $it) {
            if (empty($it['sku'])) {
                Logger::warning("Item sin SKU, omitido", ['flow' => $flow, 'requestId' => $this->requestId, 'item' => $it]);
                continue;
            }

            try {
                $res = $resolver->resolve($it['sku']);
                if (!$res['ok']) {
                    $csvRows[] = [
                        'sku'          => $it['sku'],
                        'qty_before'   => null,
                        'qty_after'    => $it['quantity'],
                        'price_before' => null,
                        'price_after'  => $it['price'] ?? null,
                        'tipo_cambio'  => 'skipped',
                        'detalle'      => 'not_found',
                        'dryrun'       => $isDryRun,
                        'ts'           => date('c')
                    ];
                    continue;
                }

                $current     = $adapter->getProductBySku($it['sku']);
                $beforeQty   = $current['quantity'] ?? null;
                $beforePrice = $current['price'] ?? null;
                $afterQty    = $it['quantity'];
                $afterPrice  = $it['price'] ?? $beforePrice;

                $estado  = 'sin cambio';
                $motivos = [];

                if ($beforeQty !== null && $beforeQty != $afterQty) {
                    $estado = 'cambio_stock';
                    $motivos[] = "Stock: {$beforeQty} → {$afterQty}";
                }

                if ($beforePrice !== null && $beforePrice != $afterPrice) {
                    $estado = ($estado === 'cambio_stock') ? 'cambio_stock_y_precio' : 'cambio_precio';
                    $motivos[] = "Precio: {$beforePrice} → {$afterPrice}";
                }

                $detalle = implode(' | ', $motivos);

                if ($estado === 'sin cambio') {
                    Logger::debug("Producto sin cambios | SKU: {$it['sku']}", ['flow' => $flow, 'requestId' => $this->requestId]);
                    continue;
                }

                if ($isDryRun) {
                    Logger::info("dryRun: preparado update | SKU: {$it['sku']} | {$detalle}", ['flow' => $flow, 'requestId' => $this->requestId]);
                } else {
                    $update = $adapter->updateProductPartialBySku($it['sku'], [
                        'quantity' => $afterQty,
                        'price'    => $afterPrice
                    ]);
                    $estado = $update['ok'] ? ($update['skipped'] ?? false ? 'skipped' : 'updated') : 'failed';
                }

                $csvRows[] = [
                    'sku'          => $it['sku'],
                    'qty_before'   => $beforeQty,
                    'qty_after'    => $afterQty,
                    'price_before' => $beforePrice,
                    'price_after'  => $afterPrice,
                    'tipo_cambio'  => $estado,
                    'detalle'      => $detalle,
                    'dryrun'       => $isDryRun,
                    'ts'           => date('c')
                ];
            } catch (\Throwable $e) {
                Logger::error("Error procesando producto: ".$e->getMessage(), ['flow' => $flow, 'requestId' => $this->requestId, 'sku' => $it['sku']]);
                $csvRows[] = [
                    'sku'          => $it['sku'],
                    'qty_before'   => null,
                    'qty_after'    => $it['quantity'],
                    'price_before' => null,
                    'price_after'  => $it['price'] ?? null,
                    'tipo_cambio'  => 'error',
                    'detalle'      => $e->getMessage(),
                    'dryrun'       => $isDryRun,
                    'ts'           => date('c')
                ];
            }
        }

        if ($isDryRun || $csvAlways) {
            $filename = "stock_" . ($isDryRun ? "dryrun_" : "real_")
                      . date('Ymd_His') . "_" . substr($this->requestId, 0, 8) . ".csv";
            $headers = ['sku', 'qty_before', 'qty_after', 'price_before', 'price_after', 'tipo_cambio', 'detalle', 'dryrun', 'ts'];
            $path = CsvWriter::writeRows($filename, $csvRows, $headers);
            Logger::info("CSV generado", ['flow' => $flow, 'requestId' => $this->requestId, 'file' => $path]);
        }

        if (empty($csvRows)) {
            Logger::info("No stock items to process", ['flow' => $flow, 'requestId' => $this->requestId]);
            return ['summary' => 'no_items', 'count' => 0];
        }

        return ['summary' => 'done', 'count' => count($csvRows)];
    }
}