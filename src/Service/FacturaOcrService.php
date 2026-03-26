<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\Logger;
use RuntimeException;

final class FacturaOcrService
{
    public function __construct(
        private readonly Logger $logger,
        private readonly array $env,
        private readonly ?PdfTextExtractorService $pdfTextExtractor = null
    ) {
    }

    public function analyze(string $filePath, string $originalName): array
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            throw new RuntimeException('La prueba con Smalot PDF Parser solo está habilitada para archivos PDF.');
        }

        if ($this->pdfTextExtractor === null) {
            throw new RuntimeException('El extractor Smalot PDF Parser no está disponible.');
        }

        $text = $this->pdfTextExtractor->extract($filePath);
        if ($text === null || trim($text) === '') {
            throw new RuntimeException('Smalot PDF Parser no pudo extraer texto del PDF.');
        }

        $this->logger->info('Texto nativo PDF extraído correctamente', [
            'provider' => 'smalot_pdfparser',
            'file' => $originalName,
        ]);

        return [
            'Provider' => 'smalot_pdfparser',
            'NativePdfText' => $text,
        ];
    }
}
