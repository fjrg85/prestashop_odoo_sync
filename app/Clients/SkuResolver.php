<?php
namespace App\Clients;

use App\Utils\Config;
use App\Utils\Logger;
use App\Utils\AuthManager;

/**
 * SkuResolver
 *
 * Resuelve SKU/reference -> presta_product_id con cache file-based.
 *
 * Uso:
 * $resolver = new SkuResolver(Config::get('PRESTA_URL'), Config::get('PRESTA_KEY'));
 * $res = $resolver->resolve('SKU-001');
 * if ($res['ok']) { $id = $res['id']; }
 */
class SkuResolver
{
    private string $cachePath;
    private int $ttl;
    private string $baseUrl;
    private string $apiKey;
    private string $searchPath;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        int $ttlSeconds = 3600,
        ?string $cacheDir = null,
        ?string $searchPath = null
    ) {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->apiKey    = $apiKey;
        $this->ttl       = $ttlSeconds;
        $cacheDir        = $cacheDir ?? Config::cacheDir();
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $this->cachePath = rtrim($cacheDir, '/') . '/sku_to_id.json';
        $this->searchPath = $searchPath ?? Config::get('SKU_RESOLVER_SEARCH_PATH', '/products');
    }

    /**
     * Resolver SKU -> ID con respuesta estandarizada
     */
    public function resolve(string $sku, bool $forceRefresh = false): array
    {
        $skuNorm = $this->normalizeSku($sku);
        $cache   = $this->readCache();

        if (!$forceRefresh && isset($cache[$skuNorm])) {
            $entry = $cache[$skuNorm];
            if (($entry['ts'] ?? 0) + $this->ttl > time()) {
                return ['ok'=>true, 'id'=>(int)$entry['id'], 'sku'=>$skuNorm, 'source'=>'cache'];
            }
        }

        Logger::debug("SkuResolver: buscando en PrestaShop", [
            'flow'=>'product','requestId'=>'-','sku'=>$skuNorm
        ]);

        $prestaId = $this->searchPrestaByReference($skuNorm);

        if ($prestaId !== null) {
            $cache[$skuNorm] = ['id'=>$prestaId, 'ts'=>time()];
            $this->writeCache($cache);
            return ['ok'=>true, 'id'=>(int)$prestaId, 'sku'=>$skuNorm, 'source'=>'api'];
        }

        return ['ok'=>false, 'sku'=>$skuNorm, 'reason'=>'not_found'];
    }

    /**
     * Wrapper legacy: devuelve solo int|null
     */
    public function getPrestaIdFromSku(string $sku, bool $forceRefresh = false): ?int
    {
        $res = $this->resolve($sku, $forceRefresh);
        return $res['ok'] ? $res['id'] : null;
    }

    public function refreshPrestaId(string $sku): ?int
    {
        return $this->getPrestaIdFromSku($sku, true);
    }

    private function normalizeSku(string $sku): string
    {
        return mb_strtoupper(trim($sku));
    }

    private function readCache(): array
    {
        if (!file_exists($this->cachePath)) return [];
        $raw = @file_get_contents($this->cachePath);
        if ($raw === false) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeCache(array $data): void
    {
        $tmp = $this->cachePath . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $this->cachePath);
    }

    private function searchPrestaByReference(string $sku): ?int
    {
        $useXml = Config::usePrestaXml();
        $url1 = $this->baseUrl . $this->searchPath . '?filters[reference]=' . rawurlencode($sku) . '&limit=1';
        $url2 = $this->baseUrl . '/api/products/?filter[reference]=[' . rawurlencode($sku) . ']&display=[id]';

        $res = $this->httpGet($url1);
        if ($res['ok']) {
            $id = $this->parseIdFromSearchResponse($res['body']);
            if ($id !== null) return $id;
        }

        $res2 = $this->httpGet($url2);
        if ($res2['ok']) {
            $id = $this->parseIdFromLegacyResponse($res2['body'], $useXml);
            if ($id !== null) return $id;
        }

        return null;
    }

    private function parseIdFromSearchResponse($body): ?int
    {
        if (empty($body)) return null;
        if (is_array($body) && isset($body['hydra:member'][0]['id'])) {
            return (int)$body['hydra:member'][0]['id'];
        }
        if (is_array($body) && isset($body[0]['id'])) {
            return (int)$body[0]['id'];
        }
        $found = $this->findKeyRecursive($body, 'id');
        return $found !== null ? (int)$found : null;
    }

    private function parseIdFromLegacyResponse($body, bool $useXml): ?int
    {
        if ($useXml && is_string($body)) {
            if (preg_match('~<id>(\d+)</id>~', $body, $m)) return (int)$m[1];
        }
        if (is_array($body)) {
            $found = $this->findKeyRecursive($body, 'id');
            return $found !== null ? (int)$found : null;
        }
        return null;
    }

    private function httpGet(string $url): array
    {
        $headers = AuthManager::getPrestaHeaders();
        $scheme  = strtolower((string)Config::get('PRESTA_AUTH_SCHEME', 'bearer'));
        if ($scheme === 'ws_key') {
            $url = AuthManager::signUrlForKey($url, 'presta');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)Config::get('PRESTA_TIMEOUT', 30),
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $raw === null || $code < 200 || $code >= 300) {
            Logger::debug("SkuResolver HTTP error", [
                'flow'=>'product','requestId'=>'-','url'=>$url,'error'=>$err,'code'=>$code
            ]);
            return ['ok'=>false,'code'=>$code,'body'=>null,'raw'=>$raw];
        }

        $parsed = null;
        if (($json = json_decode($raw, true)) !== null) {
            $parsed = $json;
        } else {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw, "SimpleXMLElement", LIBXML_NOCDATA);
            $parsed = $xml !== false ? json_decode(json_encode($xml), true) : $raw;
        }

        return ['ok'=>true,'code'=>$code,'body'=>$parsed,'raw'=>$raw];
    }

    private function findKeyRecursive($data, string $key)
    {
        if (is_array($data)) {
            if (array_key_exists($key, $data)) return $data[$key];
            foreach ($data as $v) {
                $f = $this->findKeyRecursive($v, $key);
                if ($f !== null) return $f;
            }
            return null;
        }
        if (is_object($data)) {
            if (property_exists($data, $key)) return $data->{$key};
            foreach (get_object_vars($data) as $v) {
                $f = $this->findKeyRecursive($v, $key);
                if ($f !== null) return $f;
            }
            return null;
        }
        return null;
    }
}