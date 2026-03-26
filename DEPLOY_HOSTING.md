# Despliegue en hosting compartido

## Configuración recomendada en `.env`

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://TU-DOMINIO-O-SUBCARPETA
APP_TIMEZONE=Europe/Madrid

DB_HOST=localhost
DB_PORT=3306
DB_NAME=qapl980
DB_CHARSET=utf8mb4
DB_USER=qapl980
DB_PASS=1Dr1s2026zgz@url

OCR_PROVIDER=ocr_space
OCR_TIMEOUT=60
OCR_API_URL=https://api.ocr.space/parse/image
OCR_API_KEY=K87376131188957
OCR_LANGUAGE=spa
OCR_ENGINE=2
OCR_VERIFY_SSL=true

UPLOAD_MAX_SIZE=15728640
UPLOAD_DIR=uploads
LOG_DIR=storage/logs
DEFAULT_ESTADO_FACTURA=pendiente
DEFAULT_FORMA_PAGO=
```

## Qué subir al hosting

Sube todo el proyecto:

- `composer.json`
- `composer.lock`
- `.env`
- `.htaccess`
- `public/`
- `src/`
- `config/`
- `storage/`
- `uploads/`
- `vendor/`

## Si el hosting no apunta a `public/`

Ya está preparado:

- la raíz tiene un `.htaccess`
- las rutas se redirigen a `public/index.php`
- los assets se sirven desde `public/assets`

## Pasos

1. Ejecuta `composer install --no-dev` antes de subir, o sube también `vendor/`.
2. Copia `.env` con los datos de producción.
3. Sube el proyecto a la carpeta del dominio.
4. Asegúrate de que `storage/logs/` y `uploads/` tienen permisos de escritura.
5. Abre la URL pública y prueba primero `Vista previa OCR`.
6. Después prueba `Guardar factura`.

## Prueba rápida

- Vista previa OCR: `POST /api/facturas-proveedores/preview`
- Importación real: `POST /api/facturas-proveedores/import`
