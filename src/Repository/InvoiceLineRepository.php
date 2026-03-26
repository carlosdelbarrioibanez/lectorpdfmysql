<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class InvoiceLineRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insertMany(int $invoiceId, array $lines): void
    {
        $sql = 'INSERT INTO `lineas_facturas_proveedores` (
            `id_factura_proveedor`,
            `descripción`,
            `unidades`,
            `precio_unitario`,
            `porcentaje_descuento`,
            `importe_descuento`,
            `porcentaje_iva`,
            `porcentaje_recargo`,
            `base_imponible_linea`,
            `importe_total_linea`
        ) VALUES (
            :id_factura_proveedor,
            :descripcion,
            :unidades,
            :precio_unitario,
            :porcentaje_descuento,
            :importe_descuento,
            :porcentaje_iva,
            :porcentaje_recargo,
            :base_imponible_linea,
            :importe_total_linea
        )';

        $statement = $this->pdo->prepare($sql);

        foreach ($lines as $line) {
            $statement->execute([
                ':id_factura_proveedor' => $invoiceId,
                ':descripcion' => $line['descripcion'],
                ':unidades' => $line['unidades'],
                ':precio_unitario' => $line['precio_unitario'],
                ':porcentaje_descuento' => $line['porcentaje_descuento'],
                ':importe_descuento' => $line['importe_descuento'],
                ':porcentaje_iva' => $line['porcentaje_iva'],
                ':porcentaje_recargo' => $line['porcentaje_recargo'],
                ':base_imponible_linea' => $line['base_imponible_linea'],
                ':importe_total_linea' => $line['importe_total_linea'],
            ]);
        }
    }
}
