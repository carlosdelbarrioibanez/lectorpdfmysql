INSERT INTO `facturas_proveedores` (
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
    '202603',
    'FP-2026-001',
    15,
    'A-4587',
    '2026-03-20',
    '2026-03-25',
    100.00,
    0.00,
    0.00,
    21.00,
    0.00,
    0.00,
    121.00,
    2,
    'pendiente'
);

INSERT INTO `lineas_facturas_proveedores` (
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
    1,
    'Servicio mensual',
    1.00,
    100.00,
    0.00,
    0.00,
    21.00,
    0.00,
    100.00,
    121.00
);
