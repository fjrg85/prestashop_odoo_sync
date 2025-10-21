<?php
namespace App\Clients;

use App\Utils\AuthManager;
use App\Utils\Config;
use App\Utils\Logger;

class PrestaClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private bool $useXml = true
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    private function buildXml(array $payload, string $root = 'product'): string
    {
        $xml = new \SimpleXMLElement("<$root></$root>");
        $this->arrayToXml($payload, $xml);
        return $xml->asXML();
    }

    private function arrayToXml(array $data, \SimpleXMLElement &$xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $node = $xml->addChild($key);
                $this->arrayToXml($value, $node);
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value));
            }
        }
    }

    /**
     * GET request to PrestaShop API
     */
    public function get(string $path, string $resource = 'product'): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $timeout = (int) Config::get('PRESTA_TIMEOUT', 30);

        $headers = AuthManager::getPrestaHeaders();
        $scheme  = strtolower((string)Config::get('PRESTA_AUTH_SCHEME', 'bearer'));
        if ($scheme === 'ws_key') {
            $url = AuthManager::signUrlForKey($url, 'presta');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($resp === false) {
            Logger::error("PrestaClient HTTP GET error: $err", [
                'flow'      => 'presta',
                'requestId' => '-',
                'url'       => $url
            ]);
            throw new \RuntimeException("HTTP error: $err");
        }

        $parsed = $this->parseResponse($resp);

        if (in_array($code, [401,403], true)) {
            $retried = AuthManager::handleAuthError($code, 'presta');
            if ($retried) {
                return $this->get($path, $resource);
            }
        }

        if (Config::get('ENABLE_DEBUG_LOG', false)) {
            Logger::debug("PrestaClient GET response", [
                'flow'      => 'presta',
                'requestId' => '-',
                'url'       => $url,
                'code'      => $code,
                'body'      => $parsed
            ]);
        }

        return ['code' => $code, 'body' => $parsed, 'raw' => $resp];
    }

    /**
     * PATCH request to PrestaShop API
     */
    public function patch(string $path, array $payload, ?string $xmlRoot = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $timeout = (int) Config::get('PRESTA_TIMEOUT', 30);

        if ($this->useXml) {
            $root = $xmlRoot ?? 'product';
            $body = $this->buildXml($payload, $root);
            $contentType = 'application/xml';
        } else {
            $body = json_encode($payload);
            $contentType = 'application/json';
        }

        $headers = AuthManager::getPrestaHeaders();
        $scheme  = strtolower((string)Config::get('PRESTA_AUTH_SCHEME', 'bearer'));
        if ($scheme === 'ws_key') {
            $url = AuthManager::signUrlForKey($url, 'presta');
        }

        $headers[] = "Content-Type: $contentType";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($resp === false) {
            Logger::error("PrestaClient HTTP PATCH error: $err", [
                'flow'      => 'presta',
                'requestId' => '-',
                'url'       => $url
            ]);
            throw new \RuntimeException("HTTP error: $err");
        }

        $parsed = $this->parseResponse($resp);

        if (in_array($code, [401,403], true)) {
            $retried = AuthManager::handleAuthError($code, 'presta');
            if ($retried) {
                return $this->patch($path, $payload, $xmlRoot);
            }
        }

        if (Config::get('ENABLE_DEBUG_LOG', false)) {
            Logger::debug("PrestaClient PATCH response", [
                'flow'      => 'presta',
                'requestId' => '-',
                'url'       => $url,
                'code'      => $code,
                'body'      => $parsed
            ]);
        }

        return ['code' => $code, 'body' => $parsed, 'raw' => $resp];
    }

    /**
     * Parse response (JSON or XML)
     */
    private function parseResponse(string $resp): mixed
    {
        $trim = trim($resp);
        if ($trim === '') {
            return null;
        }
        if (($json = json_decode($resp, true)) !== null) {
            return $json;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($resp, "SimpleXMLElement", LIBXML_NOCDATA);
        return $xml !== false ? json_decode(json_encode($xml), true) : $resp;
    }
}