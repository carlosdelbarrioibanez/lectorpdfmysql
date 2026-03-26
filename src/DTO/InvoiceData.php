<?php

declare(strict_types=1);

namespace App\DTO;

final class InvoiceData
{
    /**
     * @param InvoiceLineData[] $lines
     * @param string[] $warnings
     */
    public function __construct(
        public array $header,
        public array $lines,
        public array $warnings = [],
        public array $meta = []
    ) {
    }
}
