<?php

declare(strict_types=1);

namespace App\Utils;

final class Logger
{
    public function __construct(private readonly string $filePath)
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $payload = sprintf(
            "[%s] %s %s %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        file_put_contents($this->filePath, $payload, FILE_APPEND);
    }
}
