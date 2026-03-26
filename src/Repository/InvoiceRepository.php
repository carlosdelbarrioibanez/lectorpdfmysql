<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class InvoiceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(array $invoice): int
    {
        $sql = 'INSERT INTO `facturas_proveedores` (
            `periodo_factura`,
            `codigo_factura`,
            `id_proveedor`,
            `numero_factura_proveedor`,
            `fecha_factura`,
            `fecha_recepcion`,
            `total_base_imponible`,
            `descuento`,
            `suplidos`,
            `total_iva`,
            `total_recargo_equivalencia`,
            `total_retencion_irpf`,
            `total_factura`,
            `id_forma_pago`,
            `estado`
        ) VALUES (
            :periodo_factura,
            :codigo_factura,
            :id_proveedor,
            :numero_factura_proveedor,
            :fecha_factura,
            :fecha_recepcion,
            :total_base_imponible,
            :descuento,
            :suplidos,
            :total_iva,
            :total_recargo_equivalencia,
            :total_retencion_irpf,
            :total_factura,
            :id_forma_pago,
            :estado
        )';

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':periodo_factura' => $invoice['periodo_factura'],
            ':codigo_factura' => $invoice['codigo_factura'],
            ':id_proveedor' => $invoice['id_proveedor'],
            ':numero_factura_proveedor' => $invoice['numero_factura_proveedor'],
            ':fecha_factura' => $invoice['fecha_factura'],
            ':fecha_recepcion' => $invoice['fecha_recepcion'],
            ':total_base_imponible' => $invoice['total_base_imponible'],
            ':descuento' => $invoice['descuento'],
            ':suplidos' => $invoice['suplidos'],
            ':total_iva' => $invoice['total_iva'],
            ':total_recargo_equivalencia' => $invoice['total_recargo_equivalencia'],
            ':total_retencion_irpf' => $invoice['total_retencion_irpf'],
            ':total_factura' => $invoice['total_factura'],
            ':id_forma_pago' => $invoice['id_forma_pago'],
            ':estado' => $invoice['estado'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
