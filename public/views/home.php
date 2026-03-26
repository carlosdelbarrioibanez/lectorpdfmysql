<?php

declare(strict_types=1);
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importador de facturas PDF con Smalot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>/assets/app.css">
</head>
<body>
    <div class="page-shell">
        <section class="hero-card">
            <div class="hero-layout">
                <div class="hero-copy-block">
                    <div class="eyebrow">Smalot PDF Parser + PHP 8 + MySQL</div>
                    <h1>Importador de facturas con imagen más limpia y profesional</h1>
                    <p class="hero-copy">
                        Gestiona la recepción, revisión y guardado de facturas de proveedor desde una interfaz clara,
                        pensada para transmitir confianza y orden en un entorno real de cliente.
                    </p>
                    <div class="hero-notes">
                        <span>Vista previa antes de guardar</span>
                        <span>Validación de campos críticos</span>
                        <span>Mapeo OCR visible y trazable</span>
                    </div>
                </div>

                <div class="hero-grid">
                    <div class="metric-card">
                        <span class="metric-label">Importación</span>
                        <strong><?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Vista previa</span>
                        <strong><?= htmlspecialchars(str_replace('/import', '/preview', $endpoint), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Tamaño máximo</span>
                        <strong><?= htmlspecialchars((string) $maxSizeMb, ENT_QUOTES, 'UTF-8') ?> MB</strong>
                    </div>
                </div>
            </div>
        </section>

        <main class="workspace">
            <section class="panel panel-form">
                <div class="panel-header">
                    <h2>Subir factura</h2>
                    <p>Prepara la factura, completa la información clave y valida el resultado OCR antes del alta final.</p>
                </div>

                <form id="invoice-form" class="invoice-form">
                    <section class="form-section">
                        <div class="section-head">
                            <h3>Documento</h3>
                            <p>Archivo que se enviará al motor OCR.</p>
                        </div>

                        <label class="field file-drop" for="factura">
                            <span class="field-label">Archivo de factura</span>
                            <input id="factura" name="factura" type="file" accept=".pdf,.jpg,.jpeg,.png" required>
                            <span class="hint">Formatos permitidos: PDF, JPG, JPEG y PNG.</span>
                        </label>
                    </section>

                    <section class="form-section">
                        <div class="section-head">
                            <h3>Datos administrativos</h3>
                            <p>Información que define la factura dentro del sistema.</p>
                        </div>

                        <div class="form-grid">
                            <label class="field">
                                <span class="field-label">Proveedor</span>
                                <div class="compound-field">
                                    <select id="id_proveedor_select" aria-label="Selecciona proveedor">
                                        <option value="">Selecciona proveedor</option>
                                    </select>
                                    <input id="id_proveedor" name="id_proveedor" type="number" min="1" step="1" inputmode="numeric" placeholder="o escribe el ID">
                                </div>
                                <span id="id_proveedor_hint" class="hint">Puedes elegirlo de la lista o escribir el ID manualmente.</span>
                            </label>

                            <label class="field">
                                <span class="field-label">Código factura</span>
                                <input name="codigo_factura" type="text" maxlength="100" placeholder="FP-2026-001">
                            </label>

                            <label class="field">
                                <span class="field-label">Período factura</span>
                                <input name="periodo_factura" type="text" maxlength="20" placeholder="202603">
                            </label>

                            <label class="field">
                                <span class="field-label">Fecha factura manual</span>
                                <input name="fecha_factura_manual" type="date">
                            </label>
                        </div>
                    </section>

                    <section class="form-section">
                        <div class="section-head">
                            <h3>Condiciones de registro</h3>
                            <p>Ajustes adicionales para el guardado y la validación.</p>
                        </div>

                        <div class="form-grid">
                            <label class="field">
                                <span class="field-label">IVA por defecto líneas</span>
                                <input name="porcentaje_iva_default" type="number" min="0" step="0.01" placeholder="21.00">
                            </label>

                            <label class="field">
                                <span class="field-label">Forma de pago</span>
                                <select id="id_forma_pago" name="id_forma_pago">
                                    <option value="">Selecciona forma de pago</option>
                                </select>
                            </label>

                            <label class="field field-full">
                                <span class="field-label">Estado inicial</span>
                                <input name="estado" type="text" maxlength="50" value="<?= htmlspecialchars($defaultEstado, ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                        </div>
                    </section>

                    <div class="actions">
                        <button id="preview-button" type="button" class="secondary-button">Vista previa OCR</button>
                        <button id="submit-button" type="submit">Guardar factura</button>
                        <span id="status-pill" class="status-pill idle">Esperando archivo</span>
                    </div>
                </form>
            </section>

            <section class="panel panel-output">
                <div class="panel-header">
                    <h2>Resultado OCR</h2>
                    <p>Visualiza el resumen operativo, el mapeo de campos y el detalle técnico del documento analizado.</p>
                </div>

                <div id="summary" class="summary-card is-empty">
                    <strong>Aún no hay análisis ejecutado.</strong>
                    <span>Pulsa “Vista previa OCR” para revisar el contenido antes de guardar.</span>
                </div>

                <div class="result-grid">
                    <section class="preview-block preview-block-emphasis">
                        <h3>Cabecera OCR</h3>
                        <div id="header-preview" class="data-grid empty-state">Todavía no hay datos extraídos.</div>
                    </section>

                    <section class="preview-block">
                        <h3>Mapeo cabecera -> facturas_proveedores</h3>
                        <div id="header-mapping-preview" class="table-shell empty-state">Todavía no hay mapeo calculado.</div>
                    </section>

                    <section class="preview-block preview-block-wide">
                        <h3>Líneas OCR</h3>
                        <div id="lines-preview" class="table-shell empty-state">Todavía no hay líneas detectadas.</div>
                    </section>

                    <section class="preview-block preview-block-wide">
                        <h3>Mapeo líneas -> lineas_facturas_proveedores</h3>
                        <div id="line-mapping-preview" class="table-shell empty-state">Todavía no hay mapeo de líneas.</div>
                    </section>
                </div>

                <section class="preview-block preview-block-technical">
                    <h3>Texto OCR bruto</h3>
                    <pre id="raw-text-output" class="raw-text-output">Pendiente de vista previa OCR.</pre>
                </section>

                <section class="preview-block preview-block-technical">
                    <h3>Respuesta JSON</h3>
                    <pre id="json-output" class="json-output">{
  "success": false,
  "message": "Pendiente de envío"
}</pre>
                </section>
            </section>
        </main>
    </div>

    <script>
        window.APP_CONFIG = {
            endpoint: <?= json_encode($endpoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            previewEndpoint: <?= json_encode(str_replace('/import', '/preview', $endpoint), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            metadataEndpoint: <?= json_encode(str_replace('/api/facturas-proveedores/import', '/api/form-metadata', $endpoint), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };
    </script>
    <script src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>/assets/app.js"></script>
</body>
</html>
