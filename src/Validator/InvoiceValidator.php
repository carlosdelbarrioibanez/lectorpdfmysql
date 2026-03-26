<?php

declare(strict_types=1);

namespace App\Validator;

final class InvoiceValidator
{
    public function validate(array &$header, array &$lines): array
    {
        $errors = [];
        $warnings = [];

        if (($header['id_proveedor'] ?? null) === null) {
            $errors[] = 'El campo id_proveedor es obligatorio para guardar en facturas_proveedores.';
        }

        if (($header['numero_factura_proveedor'] ?? null) === null) {
            $warnings[] = 'El numero de factura no se detecto con certeza y queda en NULL.';
        }

        if (($header['fecha_factura'] ?? null) === null) {
            $errors[] = 'El campo fecha_factura es obligatorio para guardar en facturas_proveedores.';
        }

        if ($lines === []) {
            $errors[] = 'No se detectaron lineas validas en la factura.';
        }

        $sumBase = 0.0;
        $sumTotal = 0.0;
        $sumDiscount = 0.0;
        $sumVat = 0.0;
        $sumSurcharge = 0.0;

        foreach ($lines as $index => &$line) {
            $line['porcentaje_descuento'] = $line['porcentaje_descuento'] ?? 0.0;
            $line['importe_descuento'] = $line['importe_descuento'] ?? 0.0;
            $line['porcentaje_recargo'] = $line['porcentaje_recargo'] ?? 0.0;

            if (($line['porcentaje_iva'] ?? null) === null) {
                $line['porcentaje_iva'] = 0.0;
                $warnings[] = 'La linea ' . ($index + 1) . ' no traia porcentaje_iva y se ha asignado 0%.';
            }

            if (($line['descripcion'] ?? null) !== null) {
                $line['descripcion'] = trim((string) $line['descripcion']);
            }

            if (($line['base_imponible_linea'] ?? null) === null
                && ($line['unidades'] ?? null) !== null
                && ($line['precio_unitario'] ?? null) !== null) {
                $gross = (float) $line['unidades'] * (float) $line['precio_unitario'];
                $discountAmount = $line['importe_descuento'] ?? null;

                if ($discountAmount === null && ($line['porcentaje_descuento'] ?? null) !== null) {
                    $discountAmount = round($gross * ((float) $line['porcentaje_descuento'] / 100), 2);
                    $line['importe_descuento'] = $discountAmount;
                }

                $line['base_imponible_linea'] = round($gross - (float) ($discountAmount ?? 0.0), 2);
            }

            if (($line['importe_total_linea'] ?? null) === null && ($line['base_imponible_linea'] ?? null) !== null) {
                $vat = round((float) $line['base_imponible_linea'] * ((float) ($line['porcentaje_iva'] ?? 0.0) / 100), 2);
                $surcharge = round((float) $line['base_imponible_linea'] * ((float) ($line['porcentaje_recargo'] ?? 0.0) / 100), 2);
                $line['importe_total_linea'] = round((float) $line['base_imponible_linea'] + $vat + $surcharge, 2);
            }

            if (($line['descripcion'] ?? null) === null || $line['descripcion'] === '') {
                $errors[] = 'La linea ' . ($index + 1) . ' no tiene descripcion.';
            }

            if (($line['unidades'] ?? null) === null) {
                $errors[] = 'La linea ' . ($index + 1) . ' no tiene unidades.';
            }

            if (($line['precio_unitario'] ?? null) === null) {
                $errors[] = 'La linea ' . ($index + 1) . ' no tiene precio_unitario.';
            }

            if (($line['base_imponible_linea'] ?? null) === null) {
                $errors[] = 'La linea ' . ($index + 1) . ' no tiene base_imponible_linea.';
            }

            if (($line['importe_total_linea'] ?? null) === null) {
                $errors[] = 'La linea ' . ($index + 1) . ' no tiene importe_total_linea.';
            }

            $sumBase += (float) ($line['base_imponible_linea'] ?? 0.0);
            $sumTotal += (float) ($line['importe_total_linea'] ?? 0.0);
            $sumDiscount += (float) ($line['importe_descuento'] ?? 0.0);
            $sumVat += round((float) ($line['base_imponible_linea'] ?? 0.0) * ((float) ($line['porcentaje_iva'] ?? 0.0) / 100), 2);
            $sumSurcharge += round((float) ($line['base_imponible_linea'] ?? 0.0) * ((float) ($line['porcentaje_recargo'] ?? 0.0) / 100), 2);
        }
        unset($line);

        if (($header['total_base_imponible'] ?? null) === null) {
            $header['total_base_imponible'] = round($sumBase, 2);
            $warnings[] = 'Total base imponible calculado desde las lineas.';
        } elseif (!$this->sameAmount((float) $header['total_base_imponible'], $sumBase)) {
            $warnings[] = 'La suma de bases de linea no coincide exactamente con el total base imponible.';
        }

        if (($header['descuento'] ?? null) === null) {
            $header['descuento'] = round($sumDiscount, 2);
        }

        if (($header['total_iva'] ?? null) === null) {
            $header['total_iva'] = round($sumVat, 2);
            if ($sumVat > 0) {
                $warnings[] = 'Total IVA calculado desde las lineas.';
            }
        }

        if (($header['total_recargo_equivalencia'] ?? null) === null && $sumSurcharge > 0) {
            $header['total_recargo_equivalencia'] = round($sumSurcharge, 2);
            $warnings[] = 'Total recargo calculado desde las lineas.';
        }

        if (($header['total_factura'] ?? null) === null) {
            $header['total_factura'] = round(
                $sumBase
                - (float) ($header['total_retencion_irpf'] ?? 0.0)
                - (float) ($header['descuento'] ?? 0.0)
                + (float) ($header['suplidos'] ?? 0.0)
                + (float) ($header['total_iva'] ?? 0.0)
                + (float) ($header['total_recargo_equivalencia'] ?? 0.0),
                2
            );
            $warnings[] = 'Total factura calculado con importes disponibles.';
        } elseif (!$this->sameAmount((float) $header['total_factura'], $sumTotal) && $sumTotal > 0) {
            $warnings[] = 'La suma de importes totales de linea no coincide exactamente con el total factura.';
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function sameAmount(float $left, float $right, float $tolerance = 0.05): bool
    {
        return abs($left - $right) <= $tolerance;
    }
}
