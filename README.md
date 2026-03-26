# OCR de facturas de proveedores en PHP 8

Endpoint principal:

- `POST /api/facturas-proveedores/import`

Campo de archivo:

- `factura`

Campos opcionales de formulario:

- `id_proveedor`
- `codigo_factura`
- `periodo_factura`
- `id_forma_pago`
- `estado`

Instalación rápida:

1. `composer install`
2. Copiar `.env.example` a `.env`
3. Configurar MySQL y OCR
4. Publicar `public/` en Apache o XAMPP
