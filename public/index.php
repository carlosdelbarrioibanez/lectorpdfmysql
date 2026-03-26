<?php

declare(strict_types=1);

use App\Controller\HomeController;
use App\Controller\MetadataController;
use App\Controller\InvoiceUploadController;
use App\Database\DatabaseConnection;
use App\Repository\InvoiceLineRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\ProviderRepository;
use App\Service\FacturaOcrService;
use App\Service\InvoiceImportService;
use App\Service\MindeeInvoiceParser;
use App\Service\PdfTextExtractorService;
use App\Utils\JsonResponse;
use App\Utils\Logger;
use App\Validator\InvoiceValidator;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);

if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Madrid');

$logger = new Logger(
    $rootPath . '/' . ($_ENV['LOG_DIR'] ?? 'storage/logs') . '/app-' . date('Y-m-d') . '.log'
);

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $basePath = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '';

    if ($basePath !== '' && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }

    if ($method === 'GET' && ($path === '/' || $path === '')) {
        (new HomeController($rootPath, $_ENV))->index();
        exit;
    }

    if ($method === 'POST' && $path === '/api/facturas-proveedores/import') {
        $pdo = DatabaseConnection::createFromEnv($_ENV);
        $controller = new InvoiceUploadController(
            new InvoiceImportService(
                $pdo,
                new FacturaOcrService($logger, $_ENV, new PdfTextExtractorService($logger)),
                new MindeeInvoiceParser($logger),
                new InvoiceValidator(),
                new InvoiceRepository($pdo),
                new InvoiceLineRepository($pdo),
                $logger,
                $rootPath,
                $_ENV
            ),
            $logger,
            filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        );

        $controller->upload();
        exit;
    }

    if ($method === 'POST' && $path === '/api/facturas-proveedores/preview') {
        $controller = new InvoiceUploadController(
            new InvoiceImportService(
                null,
                new FacturaOcrService($logger, $_ENV, new PdfTextExtractorService($logger)),
                new MindeeInvoiceParser($logger),
                new InvoiceValidator(),
                null,
                null,
                $logger,
                $rootPath,
                $_ENV
            ),
            $logger,
            filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        );

        $controller->preview();
        exit;
    }

    if ($method === 'GET' && $path === '/api/form-metadata') {
        $pdo = DatabaseConnection::createFromEnv($_ENV);
        $controller = new MetadataController(
            new ProviderRepository($pdo),
            new PaymentMethodRepository($pdo),
            $logger
        );
        $controller->index();
        exit;
    }

    JsonResponse::send([
        'success' => false,
        'message' => 'Endpoint no encontrado.',
        'available_endpoints' => [
            'GET /',
            'GET /api/form-metadata',
            'POST /api/facturas-proveedores/preview',
            'POST /api/facturas-proveedores/import',
        ],
    ], 404);
} catch (Throwable $exception) {
    $logger->error('Error de arranque de la aplicación', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);

    JsonResponse::send([
        'success' => false,
        'message' => 'Error interno de la aplicación.',
    ], 500);
}
