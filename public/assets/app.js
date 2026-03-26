const form = document.getElementById('invoice-form');
const previewButton = document.getElementById('preview-button');
const submitButton = document.getElementById('submit-button');
const statusPill = document.getElementById('status-pill');
const summary = document.getElementById('summary');
const output = document.getElementById('json-output');
const headerPreview = document.getElementById('header-preview');
const linesPreview = document.getElementById('lines-preview');
const rawTextOutput = document.getElementById('raw-text-output');
const headerMappingPreview = document.getElementById('header-mapping-preview');
const lineMappingPreview = document.getElementById('line-mapping-preview');
const providerSelect = document.getElementById('id_proveedor_select');
const providerInput = document.getElementById('id_proveedor');
const providerHint = document.getElementById('id_proveedor_hint');
const paymentSelect = document.getElementById('id_forma_pago');
let validProviderIds = new Set();

const endpoint = window.APP_CONFIG?.endpoint ?? '/api/facturas-proveedores/import';
const previewEndpoint = window.APP_CONFIG?.previewEndpoint ?? '/api/facturas-proveedores/preview';
const metadataEndpoint = window.APP_CONFIG?.metadataEndpoint ?? '/api/form-metadata';

const parseResponseJson = async (response) => {
    const raw = await response.text();

    if (!raw || raw.trim() === '') {
        throw new Error('El backend devolvió una respuesta vacía.');
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        throw new Error(`El backend devolvió JSON inválido: ${raw.slice(0, 300)}`);
    }
};

const setStatus = (text, type) => {
    statusPill.textContent = text;
    statusPill.className = `status-pill ${type}`;
};

const setSummary = (title, detail, type) => {
    summary.className = `summary-card ${type}`;
    summary.innerHTML = `<strong>${title}</strong><span>${detail}</span>`;
};

const buildProviderHint = () => {
    if (validProviderIds.size === 0) {
        return 'No se pudo cargar la lista. Puedes escribir el ID manualmente.';
    }

    const ids = Array.from(validProviderIds).sort((left, right) => Number(left) - Number(right));
    return `IDs disponibles: ${ids.join(', ')}. Puedes elegirlo de la lista o escribir uno válido.`;
};

const renderHeaderPreview = (header = {}) => {
    const entries = Object.entries(header);
    if (entries.length === 0) {
        headerPreview.className = 'data-grid empty-state';
        headerPreview.textContent = 'Todavía no hay datos extraídos.';
        return;
    }

    headerPreview.className = 'data-grid';
    headerPreview.innerHTML = entries.map(([key, value]) => `
        <article class="data-item">
            <strong>${key}</strong>
            <span>${value ?? 'NULL'}</span>
        </article>
    `).join('');
};

const renderLinesPreview = (lines = []) => {
    if (!Array.isArray(lines) || lines.length === 0) {
        linesPreview.className = 'table-shell empty-state';
        linesPreview.textContent = 'Todavía no hay líneas detectadas.';
        return;
    }

    const columns = Object.keys(lines[0]);
    const head = columns.map((column) => `<th>${column}</th>`).join('');
    const body = lines.map((line) => `
        <tr>${columns.map((column) => `<td>${line[column] ?? 'NULL'}</td>`).join('')}</tr>
    `).join('');

    linesPreview.className = 'table-shell';
    linesPreview.innerHTML = `<table class="preview-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
};

const renderHeaderMapping = (mappings = []) => {
    if (!Array.isArray(mappings) || mappings.length === 0) {
        headerMappingPreview.className = 'table-shell empty-state';
        headerMappingPreview.textContent = 'Todavía no hay mapeo calculado.';
        return;
    }

    const rows = mappings.map((item) => `
        <tr>
            <td>${item.source ?? ''}</td>
            <td>${item.target ?? ''}</td>
            <td>${item.value ?? 'NULL'}</td>
        </tr>
    `).join('');

    headerMappingPreview.className = 'table-shell';
    headerMappingPreview.innerHTML = `
        <table class="preview-table">
            <thead>
                <tr>
                    <th>Origen</th>
                    <th>Columna destino</th>
                    <th>Valor asignado</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;
};

const renderLineMapping = (groups = []) => {
    if (!Array.isArray(groups) || groups.length === 0) {
        lineMappingPreview.className = 'table-shell empty-state';
        lineMappingPreview.textContent = 'Todavía no hay mapeo de líneas.';
        return;
    }

    lineMappingPreview.className = 'mapping-stack';
    lineMappingPreview.innerHTML = groups.map((group) => {
        const rows = (group.mappings ?? []).map((item) => `
            <tr>
                <td>${item.source ?? ''}</td>
                <td>${item.target ?? ''}</td>
                <td>${item.value ?? 'NULL'}</td>
            </tr>
        `).join('');

        return `
            <section class="mapping-card">
                <h4>Línea ${group.linea ?? ''}</h4>
                <div class="table-shell">
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th>Origen</th>
                                <th>Columna destino</th>
                                <th>Valor asignado</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </section>
        `;
    }).join('');
};

const renderRawText = (text) => {
    rawTextOutput.textContent = text && text.trim() !== '' ? text : 'OCR sin texto bruto disponible.';
};

const setWorkingState = (title) => {
    submitButton.disabled = true;
    previewButton.disabled = true;
    setStatus('Procesando OCR...', 'loading');
    setSummary(title, 'Se está subiendo el archivo y esperando la respuesta del motor OCR.', 'is-warning');
    output.textContent = JSON.stringify({
        success: false,
        message: 'Procesando solicitud...'
    }, null, 2);
    return new FormData(form);
};

const restoreButtons = () => {
    submitButton.disabled = false;
    previewButton.disabled = false;
};

const showPreviewData = (payload) => {
    const data = payload?.data ?? {};
    renderHeaderPreview(data.factura ?? {});
    renderLinesPreview(data.lineas ?? []);
    renderHeaderMapping(data.header_mapping ?? []);
    renderLineMapping(data.line_mapping ?? []);
    renderRawText(data.ocr_raw_text ?? '');
};

const fillSelect = (select, items, placeholder) => {
    select.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    select.appendChild(first);

    items.forEach((item) => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.label;
        select.appendChild(option);
    });
};

const syncProviderFromSelect = () => {
    providerInput.value = providerSelect.value;
};

const syncProviderFromInput = () => {
    const normalizedValue = providerInput.value.trim();

    if (normalizedValue === '') {
        providerSelect.value = '';
        providerInput.setCustomValidity('');
        return;
    }

    const matchingOption = Array.from(providerSelect.options).find((option) => option.value === normalizedValue);
    providerSelect.value = matchingOption ? normalizedValue : '';

    if (validProviderIds.size > 0 && !validProviderIds.has(normalizedValue)) {
        providerInput.setCustomValidity('El ID proveedor no existe en la lista cargada.');
    } else {
        providerInput.setCustomValidity('');
    }
};

const ensureValidProvider = () => {
    const normalizedValue = providerInput.value.trim();

    if (normalizedValue === '') {
        providerInput.setCustomValidity('');
        return true;
    }

    if (validProviderIds.size === 0 || validProviderIds.has(normalizedValue)) {
        providerInput.setCustomValidity('');
        return true;
    }

    providerInput.setCustomValidity('El ID proveedor no existe en la lista cargada.');
    providerInput.reportValidity();
    setStatus('Proveedor no válido', 'error');
    setSummary(
        'Selecciona un proveedor válido',
        buildProviderHint(),
        'is-error'
    );
    return false;
};

const loadMetadata = async () => {
    try {
        const response = await fetch(metadataEndpoint);
        const payload = await parseResponseJson(response);
        if (!response.ok || !payload.success) {
            providerHint.textContent = buildProviderHint();
            return;
        }

        const providers = payload.data?.providers ?? [];
        validProviderIds = new Set(providers.map((item) => String(item.id)));

        fillSelect(providerSelect, providers, 'Selecciona proveedor');
        fillSelect(paymentSelect, payload.data?.payment_methods ?? [], 'Selecciona forma de pago');
        syncProviderFromInput();
        providerHint.textContent = buildProviderHint();
    } catch (error) {
        providerHint.textContent = buildProviderHint();
        console.error(error);
    }
};

providerSelect.addEventListener('change', syncProviderFromSelect);
providerInput.addEventListener('input', syncProviderFromInput);

previewButton.addEventListener('click', async () => {
    if (!ensureValidProvider()) {
        return;
    }

    const formData = setWorkingState('Vista previa OCR en curso');

    try {
        const response = await fetch(previewEndpoint, {
            method: 'POST',
            body: formData
        });

        const data = await parseResponseJson(response);
        output.textContent = JSON.stringify(data, null, 2);
        showPreviewData(data);

        if (response.ok && data.success) {
            const warnings = Array.isArray(data.data?.warnings) ? data.data.warnings.length : 0;
            const errors = Array.isArray(data.data?.errors) ? data.data.errors.length : 0;
            setStatus('Vista previa lista', 'success');
            setSummary(
                'Valores asignados calculados',
                `Ya puedes revisar qué valor va a cada campo. Advertencias: ${warnings}. Errores de validación: ${errors}.`,
                errors > 0 || warnings > 0 ? 'is-warning' : 'is-success'
            );
            return;
        }

        setStatus('Vista previa fallida', 'error');
        setSummary(
            'No se pudo generar la vista previa',
            data.message ?? 'El backend devolvió un error durante la vista previa.',
            'is-error'
        );
    } catch (error) {
        output.textContent = JSON.stringify({
            success: false,
            message: 'No se pudo conectar con el backend.',
            error: error.message
        }, null, 2);

        setStatus('Sin conexión', 'error');
        setSummary('El backend no respondió', 'Revisa que el servidor PHP siga activo.', 'is-error');
    } finally {
        restoreButtons();
    }
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!ensureValidProvider()) {
        return;
    }

    const formData = setWorkingState('Importación en curso');

    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            body: formData
        });

        const data = await parseResponseJson(response);
        output.textContent = JSON.stringify(data, null, 2);
        showPreviewData(data);

        if (response.ok && data.success) {
            const insertedId = data.data?.id_factura_proveedor ?? 'N/D';
            const warnings = Array.isArray(data.data?.warnings) ? data.data.warnings.length : 0;
            setStatus('Importación correcta', 'success');
            setSummary(
                `Factura guardada con ID ${insertedId}`,
                warnings > 0
                    ? `La importación terminó con ${warnings} advertencia(s).`
                    : 'La cabecera y las líneas se insertaron correctamente en la base de datos.',
                warnings > 0 ? 'is-warning' : 'is-success'
            );
            return;
        }

        setStatus('Importación fallida', 'error');
        setSummary(
            'No se pudo importar la factura',
            data.message ?? 'El backend devolvió un error durante la importación.',
            'is-error'
        );
    } catch (error) {
        output.textContent = JSON.stringify({
            success: false,
            message: 'No se pudo conectar con el backend.',
            error: error.message
        }, null, 2);

        setStatus('Sin conexión', 'error');
        setSummary(
            'El backend no respondió',
            'Revisa que el servidor PHP siga activo y que la configuración de base de datos y OCR sea correcta.',
            'is-error'
        );
    } finally {
        restoreButtons();
    }
});

loadMetadata();
