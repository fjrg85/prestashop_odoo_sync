<?php
namespace App\Services;

use App\Clients\PrestaClient;
use App\Clients\PrestaClientAdapter;
use App\Clients\SkuResolver;
use App\Clients\OdooClient;
use App\Utils\CsvWriter;
use App\Utils\Logger;
use App\Utils\Config;

class ProductSyncService
{
    public function __construct(private string $requestId) {}

    public function run(array $context = []): array
    {
        $flow = 'product';
        Logger::debug("Inicio ProductSyncService::run", [
            'flow'=>$flow,'requestId'=>$this->requestId
        ]);

        // Flags
        $isDryRun  = (bool)($context['dryrun'] ?? Config::get('DRY_RUN', false));
        $csvAlways = (bool)Config::get('CSV_ALWAYS', false);

        // Odoo
        $odoo = new OdooClient(
            Config::get('ODOO_BASE_URL'),
            Config::get('ODOO_DB'),
            Config::get('ODOO_USER'),
            Config::get('ODOO_PASS')
        );
        $products = $odoo->fetchProducts($context['since'] ?? null);

        if (empty($products)) {
            Logger::info("No products retrieved", ['flow'=>$flow,'requestId'=>$this->requestId]);
            return ['summary'=>'no_products','count'=>0];
        }

        // PrestaShop
        $presta   = new PrestaClient(Config::get('PRESTA_URL'), Config::get('PRESTA_KEY'), Config::usePrestaXml());
        $adapter  = new PrestaClientAdapter($presta, $this->requestId);
        $resolver = new SkuResolver(Config::get('PRESTA_URL'), Config::get('PRESTA_KEY'));

        $csvRows = [];

        foreach ($products as $p) {
            $row = $this->mapToPresta($p);

            if (empty($row['sku'])) {
                Logger::warning("Producto sin SKU, omitido", [
                    'flow'=>$flow,'requestId'=>$this->requestId,'product'=>$row
                ]);
                continue;
            }

            if ($row['quantity'] < 0) {
                Logger::warning("Cantidad negativa detectada, producto omitido", [
                    'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$row['sku'],'qty'=>$row['quantity']
                ]);
                $csvRows[] = [
                    'sku'=>$row['sku'],
                    'price_before'=>null,'price_after'=>$row['price'],
                    'qty_before'=>null,'qty_after'=>$row['quantity'],
                    'action'=>'skipped_negative_qty','reason'=>'negative_qty',
                    'dryrun'=>$isDryRun,'ts'=>date('c')
                ];
                continue;
            }

            try {
                $res = $resolver->resolve($row['sku']);
                if (!$res['ok']) {
                    $csvRows[] = [
                        'sku'=>$row['sku'],
                        'price_before'=>null,'price_after'=>$row['price'],
                        'qty_before'=>null,'qty_after'=>$row['quantity'],
                        'action'=>'skipped','reason'=>'not_found',
                        'dryrun'=>$isDryRun,'ts'=>date('c')
                    ];
                    continue;
                }

                $current = $adapter->getProductBySku($row['sku']);
                $beforePrice = $current['price'] ?? null;
                $beforeQty   = $current['quantity'] ?? null;

                $partial = ['price'=>$row['price'], 'quantity'=>$row['quantity']];

                if ($isDryRun) {
                    Logger::info("dryRun: preparado product partial update", [
                        'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$row['sku'],'fields'=>$partial
                    ]);
                    $csvRows[] = [
                        'sku'=>$row['sku'],
                        'price_before'=>$beforePrice,'price_after'=>$row['price'],
                        'qty_before'=>$beforeQty,'qty_after'=>$row['quantity'],
                        'action'=>'dryrun','reason'=>null,
                        'dryrun'=>true,'ts'=>date('c')
                    ];
                } else {
                    $update = $adapter->updateProductPartialBySku($row['sku'], $partial);
                    $csvRows[] = [
                        'sku'=>$row['sku'],
                        'price_before'=>$beforePrice,'price_after'=>$row['price'],
                        'qty_before'=>$beforeQty,'qty_after'=>$row['quantity'],
                        'action'=>$update['ok'] ? ($update['skipped'] ?? false ? 'skipped':'updated') : 'failed',
                        'reason'=>$update['reason'] ?? null,
                        'dryrun'=>false,'ts'=>date('c')
                    ];
                }
            } catch (\Throwable $e) {
                Logger::error("Error procesando producto: ".$e->getMessage(), [
                    'flow'=>$flow,'requestId'=>$this->requestId,'sku'=>$row['sku']
                ]);
                $csvRows[] = [
                    'sku'=>$row['sku'],
                    'price_before'=>null,'price_after'=>$row['price'],
                    'qty_before'=>null,'qty_after'=>$row['quantity'],
                    'action'=>'error','reason'=>$e->getMessage(),
                    'dryrun'=>$isDryRun,'ts'=>date('c')
                ];
            }
        }

        // CSV
        if ($isDryRun || $csvAlways) {
            $filename = "product_" . ($isDryRun ? "dryrun_" : "real_")
                      . date('Ymd_His') . "_" . substr($this->requestId, 0, 8) . ".csv";
            $headers = ['sku', 'price_before', 'price_after', 'qty_before', 'qty_after', 'action', 'reason', 'dryrun', 'ts'];
            $path = CsvWriter::writeRows($filename, $csvRows, $headers);
            Logger::info("CSV generado", ['flow'=>$flow,'requestId'=>$this->requestId,'file'=>$path]);
        }

        return ['summary'=>'done','count'=>count($csvRows)];
    }

    private function mapToPresta(array $odoo): array
    {
        return [
            'sku'      => $odoo['default_code'] ?? '',
            'name'     => $odoo['name'] ?? '',
            'price'    => (float)($odoo['list_price'] ?? 0),
            'quantity' => (int)($odoo['qty_available'] ?? 0),
        ];
    }
}