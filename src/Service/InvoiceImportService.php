<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\InvoiceLineRepository;
use App\Repository\InvoiceRepository;
use App\Utils\DateNormalizer;
use App\Utils\Logger;
use App\Utils\NumberNormalizer;
use App\Validator\InvoiceValidator;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final class InvoiceImportService
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly ?PDO $pdo,
        private readonly FacturaOcrService $ocrService,
        private readonly MindeeInvoiceParser $parser,
        private readonly InvoiceValidator $validator,
        private readonly ?InvoiceRepository $invoiceRepository,
        private readonly ?InvoiceLineRepository $lineRepository,
        private readonly Logger $logger,
        private readonly string $rootPath,
        private readonly array $env
    ) {
    }

    public function handle(array $files, array $post): array
    {
        [$originalName, $storedPath, $invoiceData] = $this->extractInvoiceData($files);

        $header = $this->buildHeader($invoiceData->header, $post);
        $lines = array_map(
            static fn ($line) => $line->toArray(),
            $invoiceData->lines
        );
        $lines = $this->applyManualLineOverrides($lines, $post);

        $validation = $this->validator->validate($header, $lines);
        if ($validation['errors'] !== []) {
            $this->logger->warning('Factura rechazada por validación', [
                'errors' => $validation['errors'],
                'warnings' => array_merge($invoiceData->warnings, $validation['warnings']),
            ]);

            throw new \InvalidArgumentException($this->encodeStructuredPayload([
                'message' => implode(' | ', $validation['errors']),
                'data' => $this->buildResponseData($header, $lines, array_merge($invoiceData->warnings, $validation['warnings']), $invoiceData->meta),
            ]));
        }

        $warnings = array_merge($invoiceData->warnings, $validation['warnings']);

        if ($this->pdo === null || $this->invoiceRepository === null || $this->lineRepository === null) {
            throw new RuntimeException('La importación requiere una conexión activa a base de datos.');
        }

        try {
            $invoiceId = $this->persistInvoiceWithRetry($header, $lines);

            $this->logger->info('Factura importada correctamente', [
                'id_factura_proveedor' => $invoiceId,
                'numero_factura_proveedor' => $header['numero_factura_proveedor'],
                'warnings' => $warnings,
            ]);

            return [
                'success' => true,
                'message' => 'Factura importada correctamente.',
                'data' => array_merge(
                    ['id_factura_proveedor' => $invoiceId],
                    $this->buildResponseData($header, $lines, $warnings, $invoiceData->meta)
                ),
            ];
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->logger->error('Error transaccional al guardar la factura', [
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException($this->encodeStructuredPayload([
                'message' => $exception->getMessage(),
                'data' => $this->buildResponseData($header, $lines, $warnings, $invoiceData->meta),
            ]), 0, $exception);
        }
    }

    public function preview(array $files, array $post): array
    {
        [, , $invoiceData] = $this->extractInvoiceData($files);

        $header = $this->buildHeader($invoiceData->header, $post);
        $lines = array_map(
            static fn ($line) => $line->toArray(),
            $invoiceData->lines
        );
        $lines = $this->applyManualLineOverrides($lines, $post);

        $validation = $this->validator->validate($header, $lines);
        $warnings = array_merge($invoiceData->warnings, $validation['warnings']);

        return [
            'success' => true,
            'message' => 'Vista previa OCR generada correctamente.',
            'data' => array_merge(
                $this->buildResponseData($header, $lines, $warnings, $invoiceData->meta),
                ['errors' => $validation['errors']]
            ),
        ];
    }

    private function buildHeader(array $ocrHeader, array $post): array
    {
        $fechaRecepcion = date('Y-m-d');
        $fechaFacturaManual = DateNormalizer::normalize((string) ($post['fecha_factura_manual'] ?? ''));
        $fechaFactura = $fechaFacturaManual ?? ($ocrHeader['fecha_factura'] ?? null);
        $periodoFactura = $post['periodo_factura'] ?? ($fechaFactura !== null ? date('Ym', strtotime($fechaFactura)) : date('Ym'));
        $periodoFactura = is_string($periodoFactura) ? trim($periodoFactura) : (string) $periodoFactura;
        if ($periodoFactura === '') {
            $periodoFactura = $fechaFactura !== null ? date('Ym', strtotime($fechaFactura)) : date('Ym');
        }
        $periodoFactura = $this->normalizePeriodoFactura($periodoFactura);

        return [
            'periodo_factura' => $periodoFactura,
            'codigo_factura' => $this->resolveCodigoFactura($post, $ocrHeader, $periodoFactura),
            'id_proveedor' => ($post['id_proveedor'] ?? '') !== '' ? (int) $post['id_proveedor'] : null,
            'numero_factura_proveedor' => $ocrHeader['numero_factura_proveedor'] ?? null,
            'fecha_factura' => $fechaFactura,
            'fecha_recepcion' => $fechaRecepcion,
            'total_base_imponible' => $ocrHeader['total_base_imponible'] ?? null,
            'descuento' => $ocrHeader['descuento'] ?? 0.0,
            'suplidos' => $ocrHeader['suplidos'] ?? 0.0,
            'total_iva' => $ocrHeader['total_iva'] ?? null,
            'total_recargo_equivalencia' => $ocrHeader['total_recargo_equivalencia'] ?? 0.0,
            'total_retencion_irpf' => $ocrHeader['total_retencion_irpf'] ?? 0.0,
            'total_factura' => $ocrHeader['total_factura'] ?? null,
            'id_forma_pago' => ($post['id_forma_pago'] ?? $this->env['DEFAULT_FORMA_PAGO'] ?? '') !== ''
                ? (int) ($post['id_forma_pago'] ?? $this->env['DEFAULT_FORMA_PAGO'])
                : null,
            'estado' => ($post['estado'] ?? $this->env['DEFAULT_ESTADO_FACTURA'] ?? 'pendiente') ?: 'pendiente',
        ];
    }

    private function normalizePeriodoFactura(string $periodoFactura): int
    {
        $digits = preg_replace('/\D+/', '', $periodoFactura) ?? '';

        if ($digits === '') {
            return (int) date('ym');
        }

        if (strlen($digits) === 6) {
            return (int) substr($digits, 2, 4);
        }

        if (strlen($digits) === 4) {
            return (int) $digits;
        }

        if (strlen($digits) === 8) {
            return (int) substr($digits, 2, 4);
        }

        return (int) date('ym');
    }

    private function resolveCodigoFactura(array $post, array $ocrHeader, int $periodoFactura): int
    {
        $fromForm = preg_replace('/\D+/', '', trim((string) ($post['codigo_factura'] ?? ''))) ?? '';
        if ($fromForm !== '') {
            return (int) $fromForm;
        }

        $invoiceNumberDigits = preg_replace('/\D+/', '', trim((string) ($ocrHeader['numero_factura_proveedor'] ?? ''))) ?? '';
        if ($invoiceNumberDigits !== '') {
            return (int) substr($invoiceNumberDigits, -9);
        }

        return $this->nextCodigoFactura($periodoFactura);
    }

    private function nextCodigoFactura(int $periodoFactura): int
    {
        if ($this->pdo === null) {
            return (int) date('His');
        }

        $statement = $this->pdo->prepare(
            'SELECT MAX(`codigo_factura`) AS max_codigo
             FROM `facturas_proveedores`
             WHERE `periodo_factura` = :periodo_factura'
        );
        $statement->execute([':periodo_factura' => $periodoFactura]);
        $maxCode = (int) ($statement->fetch(PDO::FETCH_ASSOC)['max_codigo'] ?? 0);

        return $maxCode > 0 ? $maxCode + 1 : 1;
    }

    private function persistInvoiceWithRetry(array &$header, array $lines): int
    {
        if ($this->pdo === null || $this->invoiceRepository === null || $this->lineRepository === null) {
            throw new RuntimeException('La importación requiere una conexión activa a base de datos.');
        }

        $attempts = 0;

        beginning:
        $attempts++;

        try {
            $this->pdo->beginTransaction();

            $invoiceId = $this->invoiceRepository->insert($header);
            $this->lineRepository->insertMany($invoiceId, $lines);

            $this->pdo->commit();

            return $invoiceId;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($this->isInvalidProviderForeignKey($exception)) {
                throw new \InvalidArgumentException($this->encodeStructuredPayload([
                    'message' => 'El ID proveedor seleccionado no existe en la base de datos. Usa uno de los IDs disponibles en la lista.',
                    'data' => $this->buildResponseData($header, $lines, [], []),
                ]), 0, $exception);
            }

            if ($attempts < 4 && $this->isPeriodoCodigoDuplicate($exception)) {
                $header['codigo_factura'] = $this->nextCodigoFactura((int) $header['periodo_factura']);
                goto beginning;
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function isPeriodoCodigoDuplicate(PDOException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry')
            && str_contains($message, 'facturas_proveedores.periodo_factura');
    }

    private function isInvalidProviderForeignKey(PDOException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'facturas_proveedores_ibfk_1')
            || (str_contains($message, 'FOREIGN KEY (`id_proveedor`)') && str_contains($message, 'REFERENCES `proveedores`'));
    }

    private function extractInvoiceData(array $files): array
    {
        if (!isset($files['factura'])) {
            throw new \InvalidArgumentException('Debes enviar el archivo en el campo "factura".');
        }

        $uploadedFile = $files['factura'];
        if ((int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('El archivo no se pudo subir correctamente.');
        }

        $originalName = (string) ($uploadedFile['name'] ?? 'factura');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Formato no permitido. Usa PDF, JPG, JPEG o PNG.');
        }

        $maxSize = (int) ($this->env['UPLOAD_MAX_SIZE'] ?? 15728640);
        if ((int) ($uploadedFile['size'] ?? 0) > $maxSize) {
            throw new \InvalidArgumentException('El archivo supera el tamaño máximo permitido.');
        }

        $uploadDir = $this->rootPath . '/' . ($this->env['UPLOAD_DIR'] ?? 'uploads');
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('No se pudo crear el directorio de subidas.');
        }

        $storedFilename = sprintf('%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(8)), $extension);
        $storedPath = $uploadDir . '/' . $storedFilename;

        if (!move_uploaded_file((string) $uploadedFile['tmp_name'], $storedPath)) {
            throw new RuntimeException('No se pudo mover el archivo subido.');
        }

        $ocrPayload = $this->ocrService->analyze($storedPath, $originalName);
        $invoiceData = $this->parser->parse($ocrPayload);

        return [$originalName, $storedPath, $invoiceData];
    }

    private function buildHeaderMapping(array $header): array
    {
        return [
            [
                'source' => 'numero_factura_detectado',
                'target' => 'facturas_proveedores.numero_factura_proveedor',
                'value' => $header['numero_factura_proveedor'] ?? null,
            ],
            [
                'source' => 'fecha_factura_detectada',
                'target' => 'facturas_proveedores.fecha_factura',
                'value' => $header['fecha_factura'] ?? null,
            ],
            [
                'source' => 'fecha_actual',
                'target' => 'facturas_proveedores.fecha_recepcion',
                'value' => $header['fecha_recepcion'] ?? null,
            ],
            [
                'source' => 'periodo_normalizado',
                'target' => 'facturas_proveedores.periodo_factura',
                'value' => $header['periodo_factura'] ?? null,
            ],
            [
                'source' => 'codigo_factura_formulario_o_generado',
                'target' => 'facturas_proveedores.codigo_factura',
                'value' => $header['codigo_factura'] ?? null,
            ],
            [
                'source' => 'id_proveedor_formulario',
                'target' => 'facturas_proveedores.id_proveedor',
                'value' => $header['id_proveedor'] ?? null,
            ],
            [
                'source' => 'base_imponible_total',
                'target' => 'facturas_proveedores.total_base_imponible',
                'value' => $header['total_base_imponible'] ?? null,
            ],
            [
                'source' => 'descuento_total',
                'target' => 'facturas_proveedores.descuento',
                'value' => $header['descuento'] ?? null,
            ],
            [
                'source' => 'suplidos_total',
                'target' => 'facturas_proveedores.suplidos',
                'value' => $header['suplidos'] ?? null,
            ],
            [
                'source' => 'iva_total',
                'target' => 'facturas_proveedores.total_iva',
                'value' => $header['total_iva'] ?? null,
            ],
            [
                'source' => 'recargo_total',
                'target' => 'facturas_proveedores.total_recargo_equivalencia',
                'value' => $header['total_recargo_equivalencia'] ?? null,
            ],
            [
                'source' => 'retencion_irpf_total',
                'target' => 'facturas_proveedores.total_retencion_irpf',
                'value' => $header['total_retencion_irpf'] ?? null,
            ],
            [
                'source' => 'total_factura_detectado',
                'target' => 'facturas_proveedores.total_factura',
                'value' => $header['total_factura'] ?? null,
            ],
            [
                'source' => 'id_forma_pago_formulario',
                'target' => 'facturas_proveedores.id_forma_pago',
                'value' => $header['id_forma_pago'] ?? null,
            ],
            [
                'source' => 'estado_inicial',
                'target' => 'facturas_proveedores.estado',
                'value' => $header['estado'] ?? null,
            ],
        ];
    }

    private function buildLineMapping(array $lines): array
    {
        $mapped = [];

        foreach ($lines as $index => $line) {
            $mapped[] = [
                'linea' => $index + 1,
                'mappings' => [
                    [
                        'source' => 'descripcion',
                        'target' => 'lineas_facturas_proveedores.descripción',
                        'value' => $line['descripcion'] ?? null,
                    ],
                    [
                        'source' => 'unidades',
                        'target' => 'lineas_facturas_proveedores.unidades',
                        'value' => $line['unidades'] ?? null,
                    ],
                    [
                        'source' => 'precio_unitario',
                        'target' => 'lineas_facturas_proveedores.precio_unitario',
                        'value' => $line['precio_unitario'] ?? null,
                    ],
                    [
                        'source' => 'porcentaje_descuento',
                        'target' => 'lineas_facturas_proveedores.porcentaje_descuento',
                        'value' => $line['porcentaje_descuento'] ?? null,
                    ],
                    [
                        'source' => 'importe_descuento',
                        'target' => 'lineas_facturas_proveedores.importe_descuento',
                        'value' => $line['importe_descuento'] ?? null,
                    ],
                    [
                        'source' => 'porcentaje_iva',
                        'target' => 'lineas_facturas_proveedores.porcentaje_iva',
                        'value' => $line['porcentaje_iva'] ?? null,
                    ],
                    [
                        'source' => 'porcentaje_recargo',
                        'target' => 'lineas_facturas_proveedores.porcentaje_recargo',
                        'value' => $line['porcentaje_recargo'] ?? null,
                    ],
                    [
                        'source' => 'base_imponible_linea',
                        'target' => 'lineas_facturas_proveedores.base_imponible_linea',
                        'value' => $line['base_imponible_linea'] ?? null,
                    ],
                    [
                        'source' => 'importe_total_linea',
                        'target' => 'lineas_facturas_proveedores.importe_total_linea',
                        'value' => $line['importe_total_linea'] ?? null,
                    ],
                ],
            ];
        }

        return $mapped;
    }

    private function applyManualLineOverrides(array $lines, array $post): array
    {
        $defaultVat = NumberNormalizer::normalizeFloat($post['porcentaje_iva_default'] ?? null);
        $resolvedDefaultVat = $defaultVat ?? 0.0;

        foreach ($lines as &$line) {
            $line['porcentaje_descuento'] = $this->sanitizePercent($line['porcentaje_descuento'] ?? null, 0.0);
            $line['importe_descuento'] = NumberNormalizer::normalizeFloat($line['importe_descuento'] ?? 0.0) ?? 0.0;
            $line['porcentaje_recargo'] = $this->sanitizePercent($line['porcentaje_recargo'] ?? null, 0.0);
            $line['porcentaje_iva'] = $this->sanitizePercent($line['porcentaje_iva'] ?? null, $resolvedDefaultVat);

            if (($line['porcentaje_iva'] ?? null) === null) {
                $line['porcentaje_iva'] = $resolvedDefaultVat;
            }

            if (($line['base_imponible_linea'] ?? null) === null && ($line['unidades'] ?? null) !== null && ($line['precio_unitario'] ?? null) !== null) {
                $gross = (float) $line['unidades'] * (float) $line['precio_unitario'];
                $line['base_imponible_linea'] = round($gross - (float) ($line['importe_descuento'] ?? 0.0), 2);
            }

            if (($line['importe_total_linea'] ?? null) === null && ($line['base_imponible_linea'] ?? null) !== null) {
                $vatAmount = round((float) $line['base_imponible_linea'] * ((float) ($line['porcentaje_iva'] ?? 0.0) / 100), 2);
                $surchargeAmount = round((float) $line['base_imponible_linea'] * ((float) ($line['porcentaje_recargo'] ?? 0.0) / 100), 2);
                $line['importe_total_linea'] = round((float) $line['base_imponible_linea'] + $vatAmount + $surchargeAmount, 2);
            }
        }
        unset($line);

        return $lines;
    }

    private function sanitizePercent(mixed $value, ?float $fallback = null): ?float
    {
        $normalized = NumberNormalizer::normalizeFloat($value);

        if ($normalized === null) {
            return $fallback;
        }

        if ($normalized < 0 || $normalized > 100) {
            return $fallback;
        }

        return round($normalized, 2);
    }

    private function buildResponseData(array $header, array $lines, array $warnings, array $meta): array
    {
        return [
            'factura' => $header,
            'lineas' => $lines,
            'header_mapping' => $this->buildHeaderMapping($header),
            'line_mapping' => $this->buildLineMapping($lines),
            'warnings' => $warnings,
            'ocr_provider' => $meta['provider'] ?? null,
            'ocr_raw_text' => $meta['raw_text'] ?? null,
            'ocr_response' => $meta['ocr_response'] ?? null,
            'ocr_mapping' => [
                'numero_factura_detectado -> facturas_proveedores.numero_factura_proveedor' => $header['numero_factura_proveedor'] ?? null,
                'fecha_factura_detectada -> facturas_proveedores.fecha_factura' => $header['fecha_factura'] ?? null,
                'fecha_actual -> facturas_proveedores.fecha_recepcion' => $header['fecha_recepcion'] ?? null,
                'base_imponible_total -> facturas_proveedores.total_base_imponible' => $header['total_base_imponible'] ?? null,
                'descuento_total -> facturas_proveedores.descuento' => $header['descuento'] ?? null,
                'suplidos_total -> facturas_proveedores.suplidos' => $header['suplidos'] ?? null,
                'iva_total -> facturas_proveedores.total_iva' => $header['total_iva'] ?? null,
                'recargo_total -> facturas_proveedores.total_recargo_equivalencia' => $header['total_recargo_equivalencia'] ?? null,
                'retencion_irpf_total -> facturas_proveedores.total_retencion_irpf' => $header['total_retencion_irpf'] ?? null,
                'total_factura_detectado -> facturas_proveedores.total_factura' => $header['total_factura'] ?? null,
                'estado_inicial -> facturas_proveedores.estado' => $header['estado'] ?? null,
            ],
        ];
    }

    private function encodeStructuredPayload(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return json_encode([
                'message' => is_string($payload['message'] ?? null) ? $payload['message'] : 'No se pudo serializar la respuesta del backend.',
                'data' => [
                    'factura' => $payload['data']['factura'] ?? [],
                    'lineas' => $payload['data']['lineas'] ?? [],
                    'header_mapping' => $payload['data']['header_mapping'] ?? [],
                    'line_mapping' => $payload['data']['line_mapping'] ?? [],
                    'warnings' => $payload['data']['warnings'] ?? [],
                    'ocr_provider' => $payload['data']['ocr_provider'] ?? null,
                    'ocr_raw_text' => null,
                    'ocr_response' => null,
                    'ocr_mapping' => $payload['data']['ocr_mapping'] ?? [],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
