<?php

declare(strict_types=1);

namespace App\DTO;

final class InvoiceLineData
{
    public function __construct(
        public ?string $description,
        public ?float $units,
        public ?float $unitPrice,
        public ?float $discountPercent,
        public ?float $discountAmount,
        public ?float $vatPercent,
        public ?float $surchargePercent,
        public ?float $taxableBase,
        public ?float $lineTotal
    ) {
    }

    public function toArray(): array
    {
        return [
            'descripcion' => $this->description,
            'unidades' => $this->units,
            'precio_unitario' => $this->unitPrice,
            'porcentaje_descuento' => $this->discountPercent,
            'importe_descuento' => $this->discountAmount,
            'porcentaje_iva' => $this->vatPercent,
            'porcentaje_recargo' => $this->surchargePercent,
            'base_imponible_linea' => $this->taxableBase,
            'importe_total_linea' => $this->lineTotal,
        ];
    }
}
