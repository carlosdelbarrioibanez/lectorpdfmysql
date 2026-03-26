<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\Logger;
use RuntimeException;
use Smalot\PdfParser\Parser;

final class PdfTextExtractorService
{
    private Parser $parser;

    public function __construct(private readonly Logger $logger)
    {
        $this->parser = new Parser();
    }

    public function extract(string $filePath): ?string
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('No se encontró el PDF para extraer texto nativo.');
        }

        try {
            $document = $this->parser->parseFile($filePath);
            $text = trim($document->getText());

            if ($text === '') {
                return null;
            }

            return preg_replace("/\R{3,}/", "\n\n", $text);
        } catch (\Throwable $exception) {
            $this->logger->warning('No se pudo extraer texto con Smalot PDF Parser', [
                'file' => $filePath,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
