<?php

declare(strict_types=1);

namespace App\Controller;

final class HomeController
{
    public function __construct(
        private readonly string $rootPath,
        private readonly array $env
    ) {
    }

    public function index(): void
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
        $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $basePath = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : rtrim($scriptDirectory, '/');
        $appUrl = $scheme . '://' . $host . $basePath;
        $endpoint = $appUrl . '/api/facturas-proveedores/import';
        $assetBase = $basePath;
        $maxSizeMb = round(((int) ($this->env['UPLOAD_MAX_SIZE'] ?? 15728640)) / 1024 / 1024, 2);
        $defaultEstado = (string) ($this->env['DEFAULT_ESTADO_FACTURA'] ?? 'pendiente');

        require $this->rootPath . '/public/views/home.php';
    }
}
