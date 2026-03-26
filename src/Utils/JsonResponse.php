<?php

declare(strict_types=1);

namespace App\Utils;

use JsonException;

final class JsonResponse
{
    public static function send(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        try {
            echo json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            echo json_encode([
                'success' => false,
                'message' => 'No se pudo serializar la respuesta JSON.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
    }
}
