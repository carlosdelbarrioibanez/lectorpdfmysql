<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\InvoiceImportService;
use App\Utils\JsonResponse;
use App\Utils\Logger;
use Throwable;

final class InvoiceUploadController
{
    public function __construct(
        private readonly InvoiceImportService $importService,
        private readonly Logger $logger,
        private readonly bool $debug = false
    ) {
    }

    public function upload(): void
    {
        try {
            $result = $this->importService->handle($_FILES, $_POST);
            JsonResponse::send($result, 201);
        } catch (\InvalidArgumentException $exception) {
            $payload = $this->decodeStructuredException($exception->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => $payload['message'] ?? $exception->getMessage(),
                'data' => $payload['data'] ?? [],
            ], 422);
        } catch (Throwable $exception) {
            $this->logger->error('Error al procesar la subida de factura', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $payload = $this->decodeStructuredException($exception->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo procesar la factura.',
                'error' => $this->debug ? ($payload['message'] ?? $exception->getMessage()) : null,
                'data' => $payload['data'] ?? [],
            ], 500);
        }
    }

    public function preview(): void
    {
        try {
            $result = $this->importService->preview($_FILES, $_POST);
            JsonResponse::send($result, 200);
        } catch (\InvalidArgumentException $exception) {
            JsonResponse::send([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            $this->logger->error('Error al generar la vista previa OCR', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo generar la vista previa OCR.',
                'error' => $this->debug ? $exception->getMessage() : null,
            ], 500);
        }
    }

    private function decodeStructuredException(string $message): array
    {
        $decoded = json_decode($message, true);
        return is_array($decoded) ? $decoded : [];
    }
}
