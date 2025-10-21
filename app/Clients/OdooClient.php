<?php

namespace App\Clients;

use PhpXmlRpc\Client as XmlRpcClient;
use PhpXmlRpc\Request as XmlRpcRequest;
use PhpXmlRpc\Value;
use Psr\Log\LoggerInterface;

class OdooClient
{
    private string $url;
    private string $db;
    private string $username;
    private string $password;
    private ?int $uid = null;
    private LoggerInterface $logger;
    private string $requestId;

    public function __construct(
        string $url,
        string $db,
        string $username,
        string $password,
        LoggerInterface $logger,
        string $requestId = ''
    ) {
        $this->url = rtrim($url, '/');
        $this->db = $db;
        $this->username = $username;
        $this->password = $password;
        $this->logger = $logger;
        $this->requestId = $requestId ?: uniqid('req_', true);
    }

    public function authenticate(): void
    {
        $this->logger->info("[OdooClient] Authenticating...", [
            'requestId' => $this->requestId,
            'url' => $this->url,
            'db' => $this->db,
            'username' => $this->username
        ]);

        $client = new XmlRpcClient($this->url . '/xmlrpc/2/common');
        $client->setSSLVerifyPeer(false);
        $client->setSSLVerifyHost(0);

        $msg = new XmlRpcRequest('authenticate', [
            new Value($this->db, 'string'),
            new Value($this->username, 'string'),
            new Value($this->password, 'string'),
            new Value([], 'struct')
        ]);

        $response = $client->send($msg);
        
        if ($response->faultCode()) {
            $error = "Authentication failed: " . $response->faultString();
            $this->logger->error("[OdooClient] $error", ['requestId' => $this->requestId]);
            throw new \RuntimeException($error);
        }

        $this->uid = $response->value()->scalarval();
        $this->logger->info("[OdooClient] Authenticated successfully", [
            'requestId' => $this->requestId,
            'uid' => $this->uid
        ]);
    }

    /**
     * Fetch products modified since a given date
     * 
     * @param string|null $since Date in ISO format (e.g., "2025-01-15 10:30:00")
     * @return array Array of items with SKU, quantity and price
     */
    public function fetchProducts(?string $since = null): array
    {
        $startTime = microtime(true);
        
        $this->logger->info("[fetchProducts] === INICIO DE EJECUCIÓN ===", [
            'requestId' => $this->requestId,
            'since' => $since,
            'uid' => $this->uid,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        if (!$this->uid) {
            $this->logger->error("[fetchProducts] Not authenticated. Call authenticate() first", [
                'requestId' => $this->requestId
            ]);
            throw new \RuntimeException("Not authenticated. Call authenticate() first.");
        }

        // Build domain filter
        $domain = [];
        if ($since) {
            $this->logger->debug("[fetchProducts] Applying date filter", [
                'requestId' => $this->requestId,
                'since' => $since
            ]);
            $domain[] = ['write_date', '>=', $since];
        }

        $this->logger->debug("[fetchProducts] Domain filter constructed", [
            'requestId' => $this->requestId,
            'domain' => json_encode($domain),
            'domain_count' => count($domain)
        ]);

        // Step 1: Search for product IDs
        $this->logger->info("[fetchProducts] Step 1: Searching for product IDs...", [
            'requestId' => $this->requestId
        ]);

        // Para search, los parámetros deben ir en un solo array
        $searchArgs = [$this->buildXmlRpcValue($domain)];
        
        // Opciones adicionales en un struct
        $searchOptions = $this->buildXmlRpcValue([
            'offset' => 0,
            'limit' => false,
            'order' => false
        ]);

        $this->logger->debug("[fetchProducts] Search params prepared", [
            'requestId' => $this->requestId,
            'domain' => json_encode($domain)
        ]);

        try {
            $ids = $this->executeKw('product.product', 'search', $searchArgs);
            
            $this->logger->info("[fetchProducts] Search completed", [
                'requestId' => $this->requestId,
                'ids_found' => count($ids),
                'ids' => json_encode($ids),
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);

            if (empty($ids)) {
                $this->logger->warning("[fetchProducts] No products found matching criteria", [
                    'requestId' => $this->requestId,
                    'since' => $since,
                    'domain' => json_encode($domain),
                    'suggestion' => 'Verify that products exist in Odoo with write_date >= ' . $since
                ]);
                return [];
            }

        } catch (\Exception $e) {
            $this->logger->error("[fetchProducts] Search failed", [
                'requestId' => $this->requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // Step 2: Read product details
        $this->logger->info("[fetchProducts] Step 2: Reading product details...", [
            'requestId' => $this->requestId,
            'product_count' => count($ids)
        ]);

        $fields = ['default_code', 'qty_available', 'list_price', 'name', 'write_date'];
        
        $this->logger->debug("[fetchProducts] Fields to fetch", [
            'requestId' => $this->requestId,
            'fields' => $fields
        ]);

        $readParams = [
            $this->buildXmlRpcValue($ids),
            $this->buildXmlRpcValue($fields)
        ];

        try {
            $products = $this->executeKw('product.product', 'read', $readParams);
            
            $this->logger->info("[fetchProducts] Products read successfully", [
                'requestId' => $this->requestId,
                'products_read' => count($products),
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);

        } catch (\Exception $e) {
            $this->logger->error("[fetchProducts] Read failed", [
                'requestId' => $this->requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // Step 3: Transform to output format
        $this->logger->info("[fetchProducts] Step 3: Transforming results...", [
            'requestId' => $this->requestId
        ]);

        $items = [];
        $skippedCount = 0;

        foreach ($products as $product) {
            $sku = $product['default_code'] ?? null;
            
            if (empty($sku)) {
                $skippedCount++;
                $this->logger->debug("[fetchProducts] Skipping product without SKU", [
                    'requestId' => $this->requestId,
                    'product_id' => $product['id'] ?? 'unknown',
                    'product_name' => $product['name'] ?? 'unknown'
                ]);
                continue;
            }

            $item = [
                'sku' => $sku,
                'quantity' => (int)($product['qty_available'] ?? 0),
                'price' => (float)($product['list_price'] ?? 0.0),
                'name' => $product['name'] ?? '',
                'write_date' => $product['write_date'] ?? ''
            ];

            $items[] = $item;

            $this->logger->debug("[fetchProducts] Product added", [
                'requestId' => $this->requestId,
                'sku' => $sku,
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ]);
        }

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info("[fetchProducts] === FINALIZACIÓN EXITOSA ===", [
            'requestId' => $this->requestId,
            'total_products' => count($items),
            'skipped_products' => $skippedCount,
            'total_time_ms' => $totalTime,
            'avg_time_per_product_ms' => count($items) > 0 ? round($totalTime / count($items), 2) : 0
        ]);

        return $items;
    }

    /**
     * Execute Odoo model method via XML-RPC
     */
    private function executeKw(string $model, string $method, array $args = []): mixed
    {
        $this->logger->debug("[executeKw] Calling Odoo method", [
            'requestId' => $this->requestId,
            'model' => $model,
            'method' => $method,
            'args_count' => count($args)
        ]);

        $client = new XmlRpcClient($this->url . '/xmlrpc/2/object');
        $client->setSSLVerifyPeer(false);
        $client->setSSLVerifyHost(0);

        $xmlrpcParams = [
            new Value($this->db, 'string'),
            new Value($this->uid, 'int'),
            new Value($this->password, 'string'),
            new Value($model, 'string'),
            new Value($method, 'string'),
            new Value($args, 'array')  // Los args van como UN SOLO array
        ];

        $msg = new XmlRpcRequest('execute_kw', $xmlrpcParams);
        $response = $client->send($msg);

        if ($response->faultCode()) {
            $error = "execute_kw failed: " . $response->faultString();
            $this->logger->error("[executeKw] $error", [
                'requestId' => $this->requestId,
                'model' => $model,
                'method' => $method
            ]);
            throw new \RuntimeException($error);
        }

        $result = $this->phpize($response->value());

        $this->logger->debug("[executeKw] Method executed successfully", [
            'requestId' => $this->requestId,
            'model' => $model,
            'method' => $method,
            'result_type' => gettype($result),
            'result_count' => is_array($result) ? count($result) : 'N/A'
        ]);

        return $result;
    }

    /**
     * Convert PHP values to XML-RPC Value objects
     */
    private function buildXmlRpcValue(mixed $value): Value
    {
        if (is_array($value)) {
            // IMPORTANTE: Arrays vacíos SIEMPRE son arrays, nunca structs
            if (empty($value)) {
                return new Value([], 'array');
            }
            
            if (array_keys($value) === range(0, count($value) - 1)) {
                // Indexed array
                $items = array_map(fn($v) => $this->buildXmlRpcValue($v), $value);
                return new Value($items, 'array');
            } else {
                // Associative array
                $items = [];
                foreach ($value as $k => $v) {
                    $items[$k] = $this->buildXmlRpcValue($v);
                }
                return new Value($items, 'struct');
            }
        } elseif (is_int($value)) {
            return new Value($value, 'int');
        } elseif (is_float($value)) {
            return new Value($value, 'double');
        } elseif (is_bool($value)) {
            return new Value($value, 'boolean');
        } elseif ($value === false || $value === null) {
            return new Value(false, 'boolean');
        } else {
            return new Value((string)$value, 'string');
        }
    }

    /**
     * Convert XML-RPC Value to PHP native types
     */
    private function phpize(Value $value): mixed
    {
        $type = $value->scalartyp();

        if ($type === 'array') {
            $result = [];
            foreach ($value as $item) {
                $result[] = $this->phpize($item);
            }
            return $result;
        } elseif ($type === 'struct') {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->phpize($item);
            }
            return $result;
        } else {
            return $value->scalarval();
        }
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }
}