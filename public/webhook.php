<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\StockController;
use App\Utils\Logger;
use App\Utils\RequestId;
use App\Utils\Config;

header('Content-Type: application/json');

// Validar token
$token = $_SERVER['HTTP_X_HOOK_TOKEN'] ?? '';
if ($token !== Config::get('WEBHOOK_TOKEN')) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Invalid token']);
    exit;
}

// Leer y parsear payload
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$requestId = RequestId::fromContext($body);

// Loguear entrada
Logger::info("Webhook recibido", [
    'flow'      => 'stock',
    'requestId' => $requestId,
    'sku'       => $body['sku'] ?? null,
    'qty'       => $body['qty'] ?? null
]);
Logger::debug("Payload completo recibido", [
    'flow'      => 'stock',
    'requestId' => $requestId,
    'payload'   => $body
]);

// Ejecutar
try {
    $controller = new StockController();
    $result     = $controller->receive($body);

    http_response_code(200);
    echo json_encode([
        'status'     => 'ok',
        'summary'    => $result['summary'] ?? '',
        'count'      => $result['count'] ?? 0,
        'requestId'  => $requestId
    ]);
} catch (\Throwable $e) {
    Logger::error("Error webhook: " . $e->getMessage(), [
        'flow'      => 'stock',
        'requestId' => $requestId
    ]);
    http_response_code(500);
    echo json_encode([
        'status'     => 'error',
        'message'    => $e->getMessage(),
        'requestId'  => $requestId
    ]);
}