<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\Logger;
use GuzzleHttp\Client;
use Mindee\ClientV2;
use Mindee\Input\InferenceParameters;
use Mindee\Input\PathInput;
use RuntimeException;

final class InvoiceOcrService
{
    private Client $client;

    public function __construct(
        private readonly Logger $logger,
        private readonly array $env,
        private readonly ?PdfTextExtractorService $pdfTextExtractor = null
    ) {
        $verifySsl = filter_var($this->env['OCR_VERIFY_SSL'] ?? true, FILTER_VALIDATE_BOOL);

        $this->client = new Client([
            'timeout' => (float) ($this->env['OCR_TIMEOUT'] ?? 60),
            'verify' => $verifySsl,
        ]);
    }

    public function analyze(string $filePath, string $originalName): array
    {
        $provider = strtolower((string) ($this->env['OCR_PROVIDER'] ?? 'mindee'));

        return match ($provider) {
            'mindee' => $this->analyzeWithMindee($filePath, $originalName),
            'ocr_space', 'ocrspace' => $this->analyzeWithOcrSpace($filePath, $originalName),
            'tesseract' => $this->analyzeWithTesseract($filePath, $originalName),
            default => throw new RuntimeException('Proveedor OCR no soportado actualmente: ' . $provider),
        };
    }

    private function analyzeWithTesseract(string $filePath, string $originalName): array
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            $text = $this->pdfTextExtractor?->extract($filePath);
            if ($text !== null && trim($text) !== '') {
                $this->logger->info('Texto nativo PDF extraído correctamente', [
                    'provider' => 'tesseract',
                    'file' => $originalName,
                ]);

                return [
                    'NativePdfText' => $text,
                    'Provider' => 'tesseract_pdf_text',
                ];
            }

            throw new RuntimeException('No se pudo extraer texto del PDF con el extractor local.');
        }

        $binary = (string) ($this->env['TESSERACT_PATH'] ?? '');
        if ($binary === '' || !is_file($binary)) {
            throw new RuntimeException('No se encontró Tesseract. Revisa TESSERACT_PATH en .env');
        }

        $language = (string) ($this->env['TESSERACT_LANGUAGE'] ?? 'eng');
        $tmpBase = tempnam(sys_get_temp_dir(), 'ocr_tess_');
        if ($tmpBase === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para Tesseract.');
        }
        @unlink($tmpBase);

        $command = sprintf(
            '"%s" "%s" "%s" -l %s --psm 6',
            $binary,
            $filePath,
            $tmpBase,
            escapeshellarg($language)
        );

        exec($command, $commandOutput, $exitCode);
        $textFile = $tmpBase . '.txt';
        $text = is_file($textFile) ? trim((string) file_get_contents($textFile)) : '';

        if (is_file($textFile)) {
            @unlink($textFile);
        }

        if ($exitCode !== 0 && $text === '') {
            throw new RuntimeException('Tesseract no pudo procesar el archivo.');
        }

        $this->logger->info('OCR Tesseract ejecutado correctamente', [
            'provider' => 'tesseract',
            'file' => $originalName,
        ]);

        return [
            'TesseractText' => $text,
            'Provider' => 'tesseract',
        ];
    }

    private function analyzeWithMindee(string $filePath, string $originalName): array
    {
        $apiKey = (string) ($this->env['MINDEE_V2_API_KEY'] ?? $this->env['OCR_API_KEY'] ?? '');
        $modelId = (string) ($this->env['MINDEE_MODEL_ID'] ?? $this->env['OCR_MODEL_ID'] ?? '');

        if ($apiKey === '' || $modelId === '') {
            throw new RuntimeException('Falta configurar MINDEE_V2_API_KEY o MINDEE_MODEL_ID en el archivo .env');
        }

        try {
            $mindeeClient = new ClientV2($apiKey);
            $inputSource = new PathInput($filePath);
            $params = new InferenceParameters(
                $modelId,
                rag: false,
                rawText: true,
                polygon: false,
                confidence: true
            );
            $response = $mindeeClient->enqueueAndGetInference($inputSource, $params);
            $payload = json_decode($response->getRawHttp(), true, 512, JSON_THROW_ON_ERROR);
            $payload['Provider'] = 'mindee_v2';
            $payload['ModelId'] = $modelId;

            $this->logger->info('OCR ejecutado correctamente', [
                'provider' => 'mindee_v2',
                'file' => $originalName,
                'model_id' => $modelId,
            ]);

            return $payload;
        } catch (\Throwable $exception) {
            $this->logger->error('Error al invocar el OCR', [
                'provider' => 'mindee',
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('No se pudo completar el análisis OCR.', 0, $exception);
        }
    }

    private function analyzeWithOcrSpace(string $filePath, string $originalName): array
    {
        $apiUrl = (string) ($this->env['OCR_API_URL'] ?? '');
        $apiKey = (string) ($this->env['OCR_API_KEY'] ?? '');
        $language = (string) ($this->env['OCR_LANGUAGE'] ?? 'spa');
        $engine = (string) ($this->env['OCR_ENGINE'] ?? '2');

        if ($apiUrl === '' || $apiKey === '') {
            throw new RuntimeException('Falta configurar OCR_API_URL u OCR_API_KEY en el archivo .env');
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $fileType = strtoupper($extension === 'jpeg' ? 'jpg' : $extension);

        try {
            $response = $this->client->request('POST', $apiUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'multipart' => [
                    [
                        'name' => 'apikey',
                        'contents' => $apiKey,
                    ],
                    [
                        'name' => 'language',
                        'contents' => $language,
                    ],
                    [
                        'name' => 'OCREngine',
                        'contents' => $engine,
                    ],
                    [
                        'name' => 'detectOrientation',
                        'contents' => 'true',
                    ],
                    [
                        'name' => 'scale',
                        'contents' => 'true',
                    ],
                    [
                        'name' => 'isTable',
                        'contents' => 'true',
                    ],
                    [
                        'name' => 'filetype',
                        'contents' => $fileType,
                    ],
                    [
                        'name' => 'file',
                        'contents' => fopen($filePath, 'rb'),
                        'filename' => $originalName,
                    ],
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (($payload['IsErroredOnProcessing'] ?? false) === true) {
                $message = $payload['ErrorMessage'] ?? 'OCR.Space devolvió un error.';
                if (is_array($message)) {
                    $message = implode(' | ', $message);
                }
                throw new RuntimeException((string) $message);
            }

            $this->logger->info('OCR ejecutado correctamente', [
                'provider' => 'ocr_space',
                'file' => $originalName,
            ]);

            return $payload;
        } catch (\Throwable $exception) {
            $this->logger->error('Error al invocar el OCR', [
                'provider' => 'ocr_space',
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('No se pudo completar el análisis OCR.', 0, $exception);
        }
    }
}
