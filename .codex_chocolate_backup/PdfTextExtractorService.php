<?php

declare(strict_types=1);

namespace App\Service;

use Smalot\PdfParser\Parser;

final class PdfTextExtractorService
{
    public function __construct(
        private readonly array $env
    ) {
    }

    public function extract(string $filePath): ?string
    {
        $commandText = $this->extractWithPdfToText($filePath);
        if ($commandText !== null && trim($commandText) !== '') {
            return $commandText;
        }

        $parserText = $this->extractWithPhpParser($filePath);
        if ($parserText !== null && trim($parserText) !== '') {
            return $parserText;
        }

        return null;
    }

    private function extractWithPdfToText(string $filePath): ?string
    {
        $binary = (string) ($this->env['PDFTOTEXT_PATH'] ?? '');
        if ($binary === '' || !is_file($binary)) {
            return null;
        }

        $command = sprintf('"%s" -layout "%s" -', $binary, $filePath);
        $output = shell_exec($command);

        return is_string($output) ? trim($output) : null;
    }

    private function extractWithPhpParser(string $filePath): ?string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);

        return trim($pdf->getText());
    }
}
