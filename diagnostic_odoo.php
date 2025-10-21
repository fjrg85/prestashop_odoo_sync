<?php
/**
 * Script de diagnóstico para OdooClient
 * Ejecutar desde la raíz: php diagnostic_odoo.php
 */

require __DIR__ . '/vendor/autoload.php';

use App\Clients\OdooClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

echo "=== DIAGNÓSTICO ODOO CLIENT ===\n\n";

// 1. Verificar que las clases existen
echo "1. Verificando clases...\n";
if (class_exists('App\Clients\OdooClient')) {
    echo "   ✓ OdooClient encontrado\n";
} else {
    echo "   ✗ OdooClient NO encontrado\n";
    echo "   Ejecuta: composer dump-autoload\n";
    echo "   Verifica que composer.json tenga: \"App\\\\\": \"app/\"\n";
    exit(1);
}

// 2. Cargar configuración
echo "\n2. Cargando configuración...\n";
if (!file_exists(__DIR__ . '/app/utils/Config.php')) {
    echo "   ✗ Archivo app/utils/Config.php no encontrado\n";
    exit(1);
}

// Cargar variables de entorno
if (file_exists(__DIR__ . '/.env')) {
    $envFile = file_get_contents(__DIR__ . '/.env');
    $lines = explode("\n", $envFile);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Ignorar comentarios y líneas vacías
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // Parsear KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Eliminar comillas si existen
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            $_ENV[$key] = $value;
        }
    }
    echo "   ✓ Variables de entorno cargadas desde .env\n";
} else {
    echo "   ⚠ Archivo .env no encontrado, usando variables del sistema\n";
}

// Obtener configuración de Odoo (usar nombres del .env real)
$odooUrl = $_ENV['ODOO_BASE_URL'] ?? $_ENV['ODOO_URL'] ?? '';
$odooDb = $_ENV['ODOO_DB'] ?? '';
$odooUser = $_ENV['ODOO_USER'] ?? $_ENV['ODOO_USERNAME'] ?? '';
$odooPass = $_ENV['ODOO_PASS'] ?? $_ENV['ODOO_PASSWORD'] ?? '';

if (empty($odooUrl) || empty($odooDb) || empty($odooUser) || empty($odooPass)) {
    echo "   ✗ Faltan variables de entorno de Odoo\n";
    echo "   Variables encontradas:\n";
    echo "     ODOO_BASE_URL: " . (!empty($odooUrl) ? "✓" : "✗") . "\n";
    echo "     ODOO_DB: " . (!empty($odooDb) ? "✓" : "✗") . "\n";
    echo "     ODOO_USER: " . (!empty($odooUser) ? "✓" : "✗") . "\n";
    echo "     ODOO_PASS: " . (!empty($odooPass) ? "✓" : "✗") . "\n";
    echo "\n   Verifica que .env tenga: ODOO_BASE_URL, ODOO_DB, ODOO_USER, ODOO_PASS\n";
    exit(1);
}

echo "   ✓ Config cargada\n";
echo "   - URL: $odooUrl\n";
echo "   - DB: $odooDb\n";
echo "   - User: $odooUser\n";

// 3. Crear logger
echo "\n3. Inicializando logger...\n";
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logger = new Logger('diagnostic');
$logger->pushHandler(new StreamHandler($logDir . '/odoo.log', Logger::DEBUG));
echo "   ✓ Logger creado\n";
echo "   - Log file: $logDir/odoo.log\n";

// 4. Crear OdooClient
echo "\n4. Creando OdooClient...\n";
$requestId = 'diag_' . time();
try {
    $odooClient = new OdooClient(
        $odooUrl,
        $odooDb,
        $odooUser,
        $odooPass,
        $logger,
        $requestId
    );
    echo "   ✓ OdooClient instanciado\n";
    echo "   - Request ID: $requestId\n";
} catch (\Exception $e) {
    echo "   ✗ Error al crear OdooClient: " . $e->getMessage() . "\n";
    exit(1);
}

// 5. Verificar métodos disponibles
echo "\n5. Verificando métodos de OdooClient...\n";
$methods = get_class_methods($odooClient);
$requiredMethods = ['authenticate', 'fetchProducts', 'getRequestId'];
foreach ($requiredMethods as $method) {
    if (in_array($method, $methods)) {
        echo "   ✓ Método $method disponible\n";
    } else {
        echo "   ✗ Método $method NO disponible\n";
    }
}

// 6. Autenticar
echo "\n6. Autenticando en Odoo...\n";
try {
    $odooClient->authenticate();
    echo "   ✓ Autenticación exitosa\n";
} catch (\Exception $e) {
    echo "   ✗ Error de autenticación: " . $e->getMessage() . "\n";
    echo "\nRevisa logs/odoo.log para más detalles:\n";
    echo "  tail -20 logs/odoo.log\n";
    exit(1);
}

// 7. Probar fetchProducts SIN filtro de fecha
echo "\n7. Probando fetchProducts() sin filtro de fecha...\n";
echo "   (Esto traerá TODOS los productos de Odoo)\n";
try {
    $startTime = microtime(true);
    $products = $odooClient->fetchProducts(null);
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "   ✓ fetchProducts() ejecutado en {$elapsed}ms\n";
    echo "   - Productos retornados: " . count($products) . "\n";
    
    if (count($products) > 0) {
        echo "\n   📦 Primeros 3 productos:\n";
        foreach (array_slice($products, 0, 3) as $idx => $prod) {
            echo "     " . ($idx + 1) . ". SKU: {$prod['sku']} | Qty: {$prod['quantity']} | Price: {$prod['price']}\n";
            echo "        Name: {$prod['name']}\n";
        }
    } else {
        echo "   ⚠ No se retornaron productos\n";
        echo "   - Verifica que haya productos con SKU (default_code) en Odoo\n";
    }
} catch (\Exception $e) {
    echo "   ✗ Error en fetchProducts(): " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    echo "\nRevisa logs/odoo.log:\n";
    echo "  grep '$requestId' logs/odoo.log\n";
    exit(1);
}

// 8. Probar fetchProducts CON filtro de fecha (últimas 24h)
echo "\n8. Probando fetchProducts() con filtro de 24 horas...\n";
$since = date('Y-m-d H:i:s', strtotime('-24 hours'));
echo "   - Fecha desde: $since\n";

try {
    $startTime = microtime(true);
    $products = $odooClient->fetchProducts($since);
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "   ✓ fetchProducts() ejecutado en {$elapsed}ms\n";
    echo "   - Productos retornados: " . count($products) . "\n";
    
    if (count($products) > 0) {
        echo "\n   📦 Productos modificados en últimas 24h:\n";
        foreach (array_slice($products, 0, 5) as $idx => $prod) {
            echo "     " . ($idx + 1) . ". SKU: {$prod['sku']} | Modified: {$prod['write_date']}\n";
        }
    } else {
        echo "   ⚠ No hay productos modificados en las últimas 24h\n";
        echo "\n   Probando con fecha más antigua (7 días)...\n";
        
        $since7d = date('Y-m-d H:i:s', strtotime('-7 days'));
        $products7d = $odooClient->fetchProducts($since7d);
        echo "   - Productos modificados en últimos 7 días: " . count($products7d) . "\n";
        
        if (count($products7d) > 0) {
            echo "   ✓ Hay productos, pero más antiguos\n";
            echo "   - Ajusta el parámetro 'since' en tu cron\n";
        }
    }
} catch (\Exception $e) {
    echo "   ✗ Error en fetchProducts(): " . $e->getMessage() . "\n";
    exit(1);
}

// 9. Verificar logs generados
echo "\n9. Verificando logs generados...\n";
$logFile = $logDir . '/odoo.log';
if (file_exists($logFile)) {
    echo "   ✓ Archivo de log encontrado\n";
    echo "   - Tamaño: " . number_format(filesize($logFile)) . " bytes\n";
    
    // Buscar líneas con nuestro requestId
    $allLines = file($logFile);
    $relevantLines = array_filter($allLines, function($line) use ($requestId) {
        return strpos($line, $requestId) !== false;
    });
    
    echo "   - Líneas con requestId '$requestId': " . count($relevantLines) . "\n";
    
    if (count($relevantLines) > 0) {
        echo "\n   📝 Últimas 10 líneas relevantes:\n";
        $last10 = array_slice($relevantLines, -10);
        foreach ($last10 as $line) {
            // Truncar líneas muy largas
            $truncated = strlen($line) > 150 ? substr($line, 0, 150) . '...' : $line;
            echo "     " . trim($truncated) . "\n";
        }
    }
} else {
    echo "   ✗ Archivo de log NO encontrado: $logFile\n";
}

echo "\n=== DIAGNÓSTICO COMPLETADO ===\n\n";

echo "📋 Comandos útiles para ver logs:\n";
echo "  # Ver todas las líneas de este diagnóstico:\n";
echo "  grep '$requestId' logs/odoo.log\n\n";
echo "  # Ver logs en tiempo real:\n";
echo "  tail -f logs/odoo.log\n\n";
echo "  # Ver logs de fetchProducts:\n";
echo "  grep 'fetchProducts' logs/odoo.log | tail -30\n\n";
echo "  # Ver warnings:\n";
echo "  grep 'WARNING' logs/odoo.log | tail -10\n\n";

echo "✅ Si ves productos aquí pero no en el cron, el problema está en StockSyncService\n";
echo "❌ Si no ves productos aquí, el problema está en la query a Odoo o en los datos\n";