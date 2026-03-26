<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\InvoiceData;
use App\DTO\InvoiceLineData;
use App\Utils\DateNormalizer;
use App\Utils\Logger;
use App\Utils\NumberNormalizer;

final class MindeeInvoiceParser
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function parse(array $ocrResponse): InvoiceData
    {
        if (isset($ocrResponse['inference']['result']['raw_text']['pages'])) {
            return $this->parseMindeeV2Response($ocrResponse);
        }

        if (isset($ocrResponse['NativePdfText'])) {
            return $this->parsePlainTextResponse((string) $ocrResponse['NativePdfText'], (string) ($ocrResponse['Provider'] ?? 'tesseract_pdf_text'), $ocrResponse);
        }

        if (isset($ocrResponse['TesseractText'])) {
            return $this->parsePlainTextResponse((string) $ocrResponse['TesseractText'], (string) ($ocrResponse['Provider'] ?? 'tesseract'), $ocrResponse);
        }

        if (isset($ocrResponse['ParsedResults'])) {
            return $this->parseOcrSpaceResponse($ocrResponse);
        }

        $prediction = $ocrResponse['document']['inference']['prediction'] ?? [];
        $warnings = [];

        $header = [
            'numero_factura_proveedor' => $this->readString($prediction, ['invoice_number', 'reference_numbers.0']),
            'fecha_factura' => DateNormalizer::normalize($this->readString($prediction, ['date', 'invoice_date'])),
            'total_base_imponible' => $this->readAmount($prediction, ['total_net', 'base', 'net_amount']),
            'descuento' => $this->readAmount($prediction, ['total_discount']),
            'suplidos' => $this->readAmount($prediction, ['total_extras']),
            'total_iva' => $this->readAmount($prediction, ['total_tax', 'tax_amount']),
            'total_recargo_equivalencia' => $this->readAmount($prediction, ['total_recargo_equivalencia']),
            'total_retencion_irpf' => $this->readAmount($prediction, ['total_retencion_irpf', 'withholding_tax']),
            'total_factura' => $this->readAmount($prediction, ['total_amount', 'grand_total']),
        ];

        $lines = [];
        foreach (($prediction['line_items'] ?? []) as $item) {
            $description = $item['description'] ?? $item['product_code'] ?? null;
            $units = NumberNormalizer::normalizeFloat($item['quantity'] ?? null);
            $unitPrice = NumberNormalizer::normalizeFloat($item['unit_price'] ?? null);
            $discountPercent = NumberNormalizer::normalizeFloat($item['discount_rate'] ?? null);
            $discountAmount = NumberNormalizer::normalizeFloat($item['discount_amount'] ?? null);
            $vatPercent = NumberNormalizer::normalizeFloat($item['tax_rate'] ?? null);
            $surchargePercent = NumberNormalizer::normalizeFloat($item['porcentaje_recargo'] ?? null);
            $taxableBase = NumberNormalizer::normalizeFloat($item['total_net'] ?? $item['base_imponible_linea'] ?? null);
            $lineTotal = NumberNormalizer::normalizeFloat($item['total_amount'] ?? $item['importe_total_linea'] ?? null);

            if ($description === null && $taxableBase === null && $lineTotal === null) {
                continue;
            }

            if ($taxableBase === null && $units !== null && $unitPrice !== null) {
                $gross = $units * $unitPrice;
                $discountValue = $discountAmount ?? ($discountPercent !== null ? ($gross * $discountPercent / 100) : 0.0);
                $taxableBase = round($gross - $discountValue, 2);
            }

            if ($lineTotal === null && $taxableBase !== null) {
                $vatAmount = $vatPercent !== null ? ($taxableBase * $vatPercent / 100) : 0.0;
                $surchargeAmount = $surchargePercent !== null ? ($taxableBase * $surchargePercent / 100) : 0.0;
                $lineTotal = round($taxableBase + $vatAmount + $surchargeAmount, 2);
            }

            $lines[] = new InvoiceLineData(
                $description !== null ? trim((string) $description) : null,
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

        if ($header['total_base_imponible'] === null && $lines !== []) {
            $header['total_base_imponible'] = round(array_sum(array_map(
                static fn (InvoiceLineData $line): float => $line->taxableBase ?? 0.0,
                $lines
            )), 2);
            $warnings[] = 'Base imponible total calculada desde las líneas.';
        }

        if ($header['total_factura'] === null && $lines !== []) {
            $header['total_factura'] = round(array_sum(array_map(
                static fn (InvoiceLineData $line): float => $line->lineTotal ?? 0.0,
                $lines
            )), 2);
            $warnings[] = 'Total factura calculado desde las líneas.';
        }

        if ($header['numero_factura_proveedor'] === null) {
            $warnings[] = 'No se detectó con certeza el número de factura.';
        }

        if ($header['fecha_factura'] === null) {
            $warnings[] = 'No se detectó con certeza la fecha de factura.';
        }

        $this->logger->info('Factura parseada desde OCR', [
            'invoice_number' => $header['numero_factura_proveedor'],
            'line_count' => count($lines),
            'warnings' => $warnings,
        ]);

        return new InvoiceData($header, $lines, $warnings, [
            'provider' => 'mindee',
            'raw_text' => null,
            'ocr_response' => $ocrResponse,
        ]);
    }

    private function parseMindeeV2Response(array $ocrResponse): InvoiceData
    {
        $pages = $ocrResponse['inference']['result']['raw_text']['pages'] ?? [];
        $fullText = trim(implode("\n\n", array_filter(array_map(
            static fn (array $page): string => trim((string) ($page['content'] ?? '')),
            is_array($pages) ? $pages : []
        ))));

        $parsed = $this->parsePlainTextResponse($fullText, (string) ($ocrResponse['Provider'] ?? 'mindee_v2'), $ocrResponse);
        $fields = $ocrResponse['inference']['result']['fields'] ?? [];
        $documentVatPercent = $this->inferMindeeV2VatPercent($fields);
        $mindeeLines = $this->extractMindeeV2Lines($fields, $documentVatPercent);
        $header = $parsed->header;

        $header['numero_factura_proveedor'] ??= $this->readMindeeV2Scalar($fields, [
            'invoice_number',
            'invoice_id',
            'number',
            'reference_number',
        ]);
        $header['fecha_factura'] ??= DateNormalizer::normalize($this->readMindeeV2Scalar($fields, [
            'invoice_date',
            'date',
            'issue_date',
        ]));
        $header['total_base_imponible'] ??= NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($fields, [
            'total_net',
            'net_amount',
            'base_imponible_total',
            'base_amount',
        ]));
        $header['descuento'] ??= NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($fields, [
            'total_discount',
            'discount_total',
        ]));
        $header['suplidos'] ??= NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($fields, [
            'suplidos',
            'expense_total',
        ]));
        $header['total_iva'] ??= NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($fields, [
            'total_tax',
            'tax_amount',
            'vat_amount',
        ]));
        $header['total_retencion_irpf'] ??= NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($fields, [
            'withholding_tax',
            'retencion_irpf_total',
        ]));
        $header['total_factura'] ??= NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($fields, [
            'total_amount',
            'grand_total',
            'invoice_total',
            'total_amount_due',
        ]));

        $lines = $mindeeLines !== [] ? $mindeeLines : $parsed->lines;

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

        return new InvoiceData($header, $lines, $parsed->warnings, [
            'provider' => 'mindee_v2',
            'raw_text' => $fullText,
            'ocr_response' => $ocrResponse,
        ]);
    }

    private function parseOcrSpaceResponse(array $ocrResponse): InvoiceData
    {
        $warnings = [];
        $parsedResults = $ocrResponse['ParsedResults'] ?? [];
        $fullText = trim(implode("\n", array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['ParsedText'] ?? '')),
            is_array($parsedResults) ? $parsedResults : []
        ))));

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
            ], [], ['OCR.Space no devolvió texto reconocible.'], [
                'provider' => 'ocr_space',
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
            $warnings[] = 'No se detectó con certeza el número de factura en OCR.Space.';
        }

        if ($header['fecha_factura'] === null) {
            $warnings[] = 'No se detectó con certeza la fecha de factura en OCR.Space.';
        }

        if ($lines === []) {
            $warnings[] = 'OCR.Space devolvió texto, pero no se pudieron estructurar líneas con suficiente confianza.';
        }

        if ($header['total_base_imponible'] === null && $lines !== []) {
            $header['total_base_imponible'] = round(array_sum(array_map(
                static fn (InvoiceLineData $line): float => $line->taxableBase ?? 0.0,
                $lines
            )), 2);
            $warnings[] = 'Base imponible total calculada desde líneas OCR.Space.';
        }

        if ($header['total_factura'] === null && $lines !== []) {
            $header['total_factura'] = round(array_sum(array_map(
                static fn (InvoiceLineData $line): float => $line->lineTotal ?? 0.0,
                $lines
            )), 2);
            $warnings[] = 'Total factura calculado desde líneas OCR.Space.';
        }

        $this->logger->info('Factura parseada desde OCR.Space', [
            'invoice_number' => $header['numero_factura_proveedor'],
            'line_count' => count($lines),
            'warnings' => $warnings,
        ]);

        return new InvoiceData($header, $lines, $warnings, [
            'provider' => 'ocr_space',
            'raw_text' => $fullText,
            'ocr_response' => $ocrResponse,
        ]);
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
            ], [], ['No se pudo extraer texto del documento con el motor local.'], [
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

        $this->logger->info('Factura parseada desde motor local', [
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

    private function readString(array $prediction, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->readPath($prediction, $path);

            if (is_array($value) && array_key_exists('value', $value)) {
                $value = $value['value'];
            }

            if (is_scalar($value)) {
                $string = trim((string) $value);
                if ($string !== '') {
                    return $string;
                }
            }
        }

        return null;
    }

    private function readAmount(array $prediction, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = $this->readPath($prediction, $path);

            if (is_array($value)) {
                $value = $value['value'] ?? $value['amount'] ?? null;
            }

            $normalized = NumberNormalizer::normalizeFloat($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function readPath(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (is_array($current) && ctype_digit($segment) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];
                continue;
            }

            return null;
        }

        return $current;
    }

    private function extractInvoiceNumberFromText(string $text): ?string
    {
        return $this->extractValueByPatterns($text, [
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

    private function readMindeeV2Scalar(array $fields, array $keys): string|float|int|null
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $field = $fields[$key];
            if (is_array($field) && array_key_exists('value', $field)) {
                $value = $field['value'];
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return InvoiceLineData[]
     */
    private function extractMindeeV2Lines(array $fields, ?float $defaultVatPercent): array
    {
        $lineItems = $fields['line_items']['items'] ?? null;
        if (!is_array($lineItems)) {
            return [];
        }

        $lines = [];
        foreach ($lineItems as $item) {
            $itemFields = $item['fields'] ?? null;
            if (!is_array($itemFields)) {
                continue;
            }

            $description = $this->readMindeeV2Scalar($itemFields, ['description', 'name', 'label']);
            $quantity = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($itemFields, ['quantity', 'qty']));
            $unitPrice = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($itemFields, ['unit_price', 'price']));
            $discountPercent = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($itemFields, ['discount_rate', 'discount_percent']));
            $discountAmount = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($itemFields, ['discount_amount']));
            $vatPercent = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($itemFields, ['tax_rate', 'vat_rate'])) ?? $defaultVatPercent;
            $surchargePercent = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($itemFields, ['surcharge_rate', 'recargo_rate']));
            $taxableBase = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($itemFields, [
                'base_amount',
                'net_amount',
                'line_subtotal',
            ]));
            $lineTotal = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($itemFields, [
                'line_total',
                'total_amount',
            ]));

            if ($description === null && $quantity === null && $unitPrice === null && $lineTotal === null) {
                continue;
            }

            if ($taxableBase === null && $quantity !== null && $unitPrice !== null) {
                $gross = $quantity * $unitPrice;
                $discountValue = $discountAmount ?? ($discountPercent !== null ? ($gross * $discountPercent / 100) : 0.0);
                $taxableBase = round($gross - $discountValue, 2);
            }

            if ($lineTotal === null && $taxableBase !== null) {
                $vatAmount = $vatPercent !== null ? ($taxableBase * $vatPercent / 100) : 0.0;
                $surchargeAmount = $surchargePercent !== null ? ($taxableBase * $surchargePercent / 100) : 0.0;
                $lineTotal = round($taxableBase + $vatAmount + $surchargeAmount, 2);
            }

            $lines[] = new InvoiceLineData(
                $description !== null ? trim((string) $description) : null,
                $quantity,
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

    private function inferMindeeV2VatPercent(array $fields): ?float
    {
        $taxAmount = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($fields, [
            'tax_amount',
            'vat_amount',
            'total_tax',
        ]));
        $subtotal = NumberNormalizer::normalizeFloat($this->readMindeeV2Scalar($fields, [
            'subtotal',
            'total_net',
            'net_amount',
            'base_imponible_total',
        ]));

        if ($taxAmount === null || $subtotal === null || $subtotal <= 0.0) {
            return null;
        }

        return round(($taxAmount / $subtotal) * 100, 2);
    }
}
