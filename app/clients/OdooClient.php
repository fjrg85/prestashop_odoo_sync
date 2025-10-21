<?php
namespace App\Clients;

use App\Utils\Config;
use App\Utils\Logger;
use PhpXmlRpc\Client as XmlRpcClient;
use PhpXmlRpc\Request as XmlRpcRequest;
use PhpXmlRpc\Value;

class OdooClient
{
    private string $baseUrl;
    private string $db;
    private string $user;
    private string $pass;
    private ?int $uid = null;
    private int $timeout;
    private ?string $requestId = null;

    public function __construct(
        string $baseUrl = '',
        string $db = '',
        string $user = '',
        string $pass = '',
        int $timeout = 30
    ) {
        $this->baseUrl = rtrim($baseUrl ?: (string)Config::get('ODOO_BASE_URL', ''), '/');
        $this->db      = $db ?: (string)Config::get('ODOO_DB', '');
        $this->user    = $user ?: (string)Config::get('ODOO_USER', '');
        $this->pass    = $pass ?: (string)Config::get('ODOO_PASS', '');
        $this->timeout = (int)Config::get('ODOO_TIMEOUT', $timeout);
    }

    public function setRequestId(string $id): void
    {
        $this->requestId = $id;
    }

    private function makeClient(string $url): XmlRpcClient
    {
        $client = new XmlRpcClient($url);
        if (method_exists($client, 'SetCurlOptions')) {
            $client->SetCurlOptions([
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT        => $this->timeout
            ]);
        }
        if (method_exists($client, 'setDebug')) {
            $client->setDebug(false);
        }
        return $client;
    }

    private function ensureAuth(): void
    {
        if ($this->uid !== null) return;

        $client = $this->makeClient($this->baseUrl . '/xmlrpc/2/common');

        $req = new XmlRpcRequest('authenticate', [
            new Value($this->db, 'string'),
            new Value($this->user, 'string'),
            new Value($this->pass, 'string'),
            new Value([
                'platform' => new Value('PHP', 'string'),
                'version'  => new Value(PHP_VERSION, 'string')
            ], 'struct')
        ]);

        $resp = $client->send($req);

        if ($resp->faultCode()) {
            Logger::error("Odoo auth fault: " . $resp->faultString(), ['flow'=>'odoo','requestId'=>$this->requestId ?? '-']);
            throw new \RuntimeException("Odoo auth fault: " . $resp->faultString());
        }

        $uid = $this->phpize($resp->value());
        if (!is_numeric($uid)) {
            Logger::error("Odoo auth unexpected response", ['flow'=>'odoo','requestId'=>$this->requestId ?? '-']);
            throw new \RuntimeException("Odoo auth unexpected response");
        }

        $this->uid = (int)$uid;
        Logger::info("Odoo authenticated", ['flow'=>'odoo','requestId'=>$this->requestId ?? '-','uid'=>$this->uid]);
    }

    private function execute_kw(string $model, string $method, array $params = [], array $kwargs = [])
    {
        $this->ensureAuth();
        $client = $this->makeClient($this->baseUrl . '/xmlrpc/2/object');

        $callParams = [
            new Value($this->db, 'string'),
            new Value($this->uid, 'int'),
            new Value($this->pass, 'string'),
            new Value($model, 'string'),
            new Value($method, 'string'),
            $this->buildXmlRpcValue($params)
        ];

        if (!empty($kwargs)) {
            $callParams[] = $this->buildXmlRpcValue($kwargs);
        }

        $req = new XmlRpcRequest('execute_kw', $callParams);
        $resp = $client->send($req);

        if ($resp->faultCode()) {
            Logger::error("Odoo execute fault: " . $resp->faultString(), [
                'flow'=>'odoo','requestId'=>$this->requestId ?? '-','model'=>$model,'method'=>$method
            ]);
            throw new \RuntimeException("Odoo execute fault: " . $resp->faultString());
        }

        return $this->phpize($resp->value());
    }

    private function buildXmlRpcValue(mixed $data): Value
    {
        if (is_array($data)) {
            $isAssoc = array_keys($data) !== range(0, count($data) - 1);

            if ($isAssoc) {
                $converted = [];
                foreach ($data as $key => $val) {
                    $converted[$key] = $this->buildXmlRpcValue($val);
                }
                return new Value($converted, 'struct');
            } else {
                $converted = array_map([$this, 'buildXmlRpcValue'], $data);
                return new Value($converted, 'array');
            }
        }

        if (is_bool($data)) return new Value($data, 'boolean');
        if (is_int($data))  return new Value($data, 'int');
        if (is_float($data))return new Value($data, 'double');

        if (is_string($data) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data)) {
            $dt = new \DateTime($data);
            return new Value($dt->format('Ymd\THis'), 'datetime');
        }

        return new Value((string)$data, 'string');
    }
    private function phpize(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'kindOf')) {
            switch ($value->kindOf()) {
                case 'int':
                case 'i4':
                case 'double':
                    return $value->scalarval();
                case 'boolean':
                    return (bool)$value->scalarval();
                case 'string':
                    return (string)$value->scalarval();
                case 'array':
                case 'struct':
                    $out = [];
                    if (isset($value->me['val']) && is_array($value->me['val'])) {
                        foreach ($value->me['val'] as $item) {
                            $child = $this->phpize($item['val']);
                            if (isset($item['name'])) {
                                $out[$item['name']] = $child;
                            } else {
                                $out[] = $child;
                            }
                        }
                    }
                    return $out;
                default:
                    return $value->scalarval();
            }
        }

        if (is_array($value)) {
            return array_map(fn($v) => $this->phpize($v), $value);
        }

        return $value;
    }

    public function fetchProducts(?string $since = null): array
    {
        try {
            Logger::debug("Entrando a fetchProducts", [
                'flow' => 'odoo',
                'requestId' => $this->requestId ?? '-'
            ]);

            $originalSince = $since;

            if ($since) {
                $dt = new \DateTime($since);
                $dt->setTimezone(new \DateTimeZone('UTC'));
                $since = $dt->format('Y-m-d H:i:s');
            }

            $domain = $since ? [['write_date', '>=', $since]] : [];

            Logger::debug("Filtro original: {$originalSince} | Dominio aplicado: " . json_encode($domain), [
                'flow' => 'odoo',
                'requestId' => $this->requestId ?? '-'
            ]);

            $fields = ['id', 'default_code', 'name', 'list_price', 'qty_available', 'write_date'];
            $ids = $this->execute_kw('product.product', 'search', [$domain]);

            Logger::debug("IDs encontrados: " . count($ids), [
                'flow' => 'odoo',
                'requestId' => $this->requestId ?? '-'
            ]);

            if (empty($ids)) return [];

            $products = $this->execute_kw('product.product', 'read', [$ids, $fields]);

            Logger::debug("Productos leídos: " . count($products), [
                'flow' => 'odoo',
                'requestId' => $this->requestId ?? '-'
            ]);

            $validProducts = [];
            foreach ($products as $p) {
                $writeDate = $p['write_date'] ?? '';
                $isValid = true;

                if ($since && $writeDate) {
                    $wd = new \DateTime($writeDate, new \DateTimeZone('UTC'));
                    $cut = new \DateTime($since, new \DateTimeZone('UTC'));
                    $isValid = $wd >= $cut;

                    if (!$isValid) {
                        Logger::debug("Producto fuera de rango | SKU: {$p['default_code']} | write_date: {$writeDate}", [
                            'flow' => 'odoo',
                            'requestId' => $this->requestId ?? '-'
                        ]);
                    }
                }

                if ($isValid) {
                    $validProducts[] = [
                        'id'            => $p['id'] ?? null,
                        'default_code'  => $p['default_code'] ?? '',
                        'name'          => $p['name'] ?? '',
                        'list_price'    => $p['list_price'] ?? 0,
                        'qty_available' => $p['qty_available'] ?? 0,
                        'write_date'    => $writeDate,
                    ];
                }
            }

            Logger::debug("Productos válidos dentro del rango: " . count($validProducts), [
                'flow' => 'odoo',
                'requestId' => $this->requestId ?? '-'
            ]);

            return $validProducts;

        } catch (\Throwable $e) {
            Logger::error("fetchProducts error: " . $e->getMessage(), [
                'flow' => 'odoo',
                'requestId' => $this->requestId ?? '-'
            ]);
            return [];
        }
    }

    public function adjustStock(int $productId, float $newQty): array
    {
        try {
            $domain = [
                ['product_id', '=', $productId],
                ['location_id.usage', '=', 'internal']
            ];
            $quantIds = $this->execute_kw('stock.quant', 'search', [$domain], ['limit' => 1]);

            if (empty($quantIds)) {
                Logger::error("No se encontró quant para producto", [
                    'flow' => 'odoo',
                    'requestId' => $this->requestId ?? '-',
                    'productId' => $productId
                ]);
                return ['ok' => false, 'error' => 'No quant found for product'];
            }

            $quantId = is_array($quantIds) ? $quantIds[0] : $quantIds;

            $this->execute_kw('stock.quant', 'write', [[$quantId], ['inventory_quantity' => $newQty]]);
            $this->execute_kw('stock.quant', 'action_apply_inventory', [[$quantId]]);

            Logger::info("Stock ajustado en Odoo", [
                'flow' => 'odoo',
                'requestId' => $this->requestId ?? '-',
                'productId' => $productId,
                'newQty' => $newQty
            ]);

            return ['ok' => true, 'quant_id' => $quantId, 'newQty' => $newQty];
        } catch (\Throwable $e) {
            Logger::error("adjustStock error: " . $e->getMessage(), [
                'flow' => 'odoo',
                'requestId' => $this->requestId ?? '-',
                'productId' => $productId,
                'newQty' => $newQty
            ]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function wrapParam(mixed $param): Value
    {
        if ($param instanceof Value) return $param;
        return $this->buildXmlRpcValue($param);
    }
}