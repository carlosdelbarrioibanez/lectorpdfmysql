<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\InvoiceData;
use App\DTO\InvoiceLineData;
use App\Utils\DateNormalizer;
use App\Utils\Logger;
use App\Utils\NumberNormalizer;
use RuntimeException;

final class MindeeInvoiceParser
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function parse(array $ocrResponse): InvoiceData
    {
        if (isset($ocrResponse['NativePdfText'])) {
            return $this->parsePlainTextResponse(
                (string) $ocrResponse['NativePdfText'],
                (string) ($ocrResponse['Provider'] ?? 'smalot_pdfparser'),
                $ocrResponse
            );
        }

        throw new RuntimeException('No se recibió texto extraído por Smalot PDF Parser.');
    }

    private function parsePlainTextResponse(string $fullText, string $provider, array $ocrResponse): InvoiceData
    {
        $warnings = [];
        $fullText = trim($fullText);

        if ($fullText === '') {
            return new InvoiceData([
                'numero_factura_proveedor' => null,
                'fecha_factura' => null,
                'total_base_imponible' => null,
                'descuento' => null,
                'suplidos' => null,
                'total_iva' => null,
                'total_recargo_equivalencia' => null,
                'total_retencion_irpf' => null,
                'total_factura' => null,
            ], [], ['Smalot PDF Parser no devolvió texto utilizable.'], [
                'provider' => $provider,
                'raw_text' => '',
                'ocr_response' => $ocrResponse,
            ]);
        }

        $header = [
            'numero_factura_proveedor' => $this->extractInvoiceNumberFromText($fullText),
            'fecha_factura' => DateNormalizer::normalize($this->extractValueByPatterns($fullText, [
                '/fecha\s+(?:factura|emisi[oó]n)\s*[:\-]?\s*([0-9]{1,2}[\/\-.][0-9]{1,2}[\/\-.][0-9]{2,4})/iu',
                '/\bfecha\s*[:\-]?\s*([0-9]{1,2}[\/\-.][0-9]{1,2}[\/\-.][0-9]{2,4})/iu',
            ])),
            'total_base_imponible' => $this->extractAmountByPatterns($fullText, [
                '/base\s+imponible(?:\s+total)?\s*[:\-]?\s*([0-9\.\,]+)/iu',
                '/total\s+base\s+imponible\s*[:\-]?\s*([0-9\.\,]+)/iu',
            ]),
            'descuento' => $this->extractAmountByPatterns($fullText, [
                '/descuento(?:\s+total)?\s*[:\-]?\s*([0-9\.\,]+)/iu',
            ]),
            'suplidos' => $this->extractAmountByPatterns($fullText, [
                '/suplidos\s*[:\-]?\s*([0-9\.\,]+)/iu',
            ]),
            'total_iva' => $this->extractAmountByPatterns($fullText, [
                '/(?:total\s+)?iva\s*[:\-]?\s*([0-9\.\,]+)/iu',
            ]),
            'total_recargo_equivalencia' => $this->extractAmountByPatterns($fullText, [
                '/recargo(?:\s+de\s+equivalencia)?\s*[:\-]?\s*([0-9\.\,]+)/iu',
            ]),
            'total_retencion_irpf' => $this->extractAmountByPatterns($fullText, [
                '/retenci[oó]n\s+irpf\s*[:\-]?\s*([0-9\.\,]+)/iu',
                '/irpf\s*[:\-]?\s*([0-9\.\,]+)/iu',
            ]),
            'total_factura' => $this->extractAmountByPatterns($fullText, [
                '/total\s+(?:factura|importe)\s*[:\-]?\s*([0-9\.\,]+)/iu',
                '/importe\s+total\s*[:\-]?\s*([0-9\.\,]+)/iu',
            ]),
        ];

        $lines = $this->extractLinesFromOcrText($fullText);

        if ($header['numero_factura_proveedor'] === null) {
            $warnings[] = 'No se detectó con certeza el número de factura.';
        }

        if ($header['fecha_factura'] === null) {
            $warnings[] = 'No se detectó con certeza la fecha de factura.';
        }

        if ($header['total_base_imponible'] === null && $lines !== []) {
            $header['total_base_imponible'] = round(array_sum(array_map(
                static fn (InvoiceLineData $line): float => $line->taxableBase ?? 0.0,
                $lines
            )), 2);
        }

        if ($header['total_factura'] === null && $lines !== []) {
            $header['total_factura'] = round(array_sum(array_map(
                static fn (InvoiceLineData $line): float => $line->lineTotal ?? 0.0,
                $lines
            )), 2);
        }

        $this->logger->info('Factura parseada desde texto PDF nativo', [
            'provider' => $provider,
            'invoice_number' => $header['numero_factura_proveedor'],
            'line_count' => count($lines),
        ]);

        return new InvoiceData($header, $lines, $warnings, [
            'provider' => $provider,
            'raw_text' => $fullText,
            'ocr_response' => $ocrResponse,
        ]);
    }

    private function extractInvoiceNumberFromText(string $text): ?string
    {
        return $this->extractValueByPatterns($text, [
            '/factura\s*[-:]\s*([A-Z0-9\/\-. ]+)/iu',
            '/n(?:[úu])?m(?:ero)?\s+factura\s*[:\-]?\s*([A-Z0-9\/\-.]+)/iu',
            '/factura\s+n(?:[úu])?m(?:ero)?\s*[:\-]?\s*([A-Z0-9\/\-.]+)/iu',
            '/n[ºo]\s*factura\s*[:\-]?\s*([A-Z0-9\/\-.]+)/iu',
        ]);
    }

    private function extractAmountByPatterns(string $text, array $patterns): ?float
    {
        $value = $this->extractValueByPatterns($text, $patterns);
        return NumberNormalizer::normalizeFloat($value);
    }

    private function extractValueByPatterns(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $value = trim((string) ($matches[1] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return InvoiceLineData[]
     */
    private function extractLinesFromOcrText(string $text): array
    {
        $lines = [];
        $rows = preg_split('/\R+/', $text) ?: [];

        foreach ($rows as $row) {
            $normalizedRow = trim(preg_replace('/\s+/', ' ', $row) ?? '');
            if ($normalizedRow === '' || mb_strlen($normalizedRow) < 6) {
                continue;
            }

            if (preg_match('/\b(base|iva|total|factura|subtotal|descuento|suplidos|recargo|retenci[oó]n)\b/iu', $normalizedRow) === 1) {
                continue;
            }

            preg_match_all('/-?\d[\d\.\,]*/u', $normalizedRow, $matches);
            $numbers = array_values(array_filter(array_map(
                static fn (string $value): ?float => NumberNormalizer::normalizeFloat($value),
                $matches[0] ?? []
            ), static fn (?float $value): bool => $value !== null));

            if (count($numbers) < 2) {
                continue;
            }

            $description = trim((string) preg_replace('/\s+-?\d[\d\.\,]*(?=\s|$).*/u', '', $normalizedRow));
            if ($description === '') {
                continue;
            }

            $units = $numbers[0] ?? null;
            $unitPrice = $numbers[1] ?? null;
            $discountPercent = null;
            $discountAmount = null;
            $vatPercent = null;
            $surchargePercent = null;
            $taxableBase = null;
            $lineTotal = null;

            if (count($numbers) >= 5) {
                $discountPercent = $numbers[2];
                $vatPercent = $numbers[3];
                $lineTotal = $numbers[count($numbers) - 1];
                $taxableBase = $numbers[count($numbers) - 2];
            } elseif (count($numbers) === 4) {
                $vatPercent = $numbers[2];
                $lineTotal = $numbers[3];
                $taxableBase = round(($units ?? 0.0) * ($unitPrice ?? 0.0), 2);
            } else {
                $taxableBase = round(($units ?? 0.0) * ($unitPrice ?? 0.0), 2);
                $lineTotal = $numbers[count($numbers) - 1];
            }

            $lines[] = new InvoiceLineData(
                $description,
                $units,
                $unitPrice,
                $discountPercent,
                $discountAmount,
                $vatPercent,
                $surchargePercent,
                $taxableBase,
                $lineTotal
            );
        }

        return $lines;
    }
}
