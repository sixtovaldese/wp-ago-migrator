(function () {
    'use strict';

    const cfg = window.agoMigrator;
    if (!cfg) return;

    // ── Helpers ─────────────────────────────────────────

    async function postJSON(endpoint, data) {
        const resp = await fetch(cfg.restUrl + endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce
            },
            body: JSON.stringify(data)
        });
        const json = await resp.json();
        if (!resp.ok) throw new Error(json.error || json.message || 'Request failed');
        return json;
    }

    function updateProgress(barId, statusId, current, total, message) {
        const pct = Math.round((current / total) * 100);
        const bar = document.getElementById(barId);
        const status = document.getElementById(statusId);
        if (bar) bar.style.width = pct + '%';
        if (status) status.textContent = pct + '%, ' + message;
    }

    function showEl(id) { document.getElementById(id).style.display = ''; }
    function hideEl(id) { document.getElementById(id).style.display = 'none'; }

    function logLine(msg, type) {
        const log = document.getElementById('ago-log');
        log.style.display = '';
        const line = document.createElement('div');
        line.className = 'log-line' + (type ? ' log-' + type : '');
        line.textContent = msg;
        log.appendChild(line);
        log.scrollTop = log.scrollHeight;
    }

    function generateJobId() {
        return 'job_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    function readAsBase64(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result.split(',')[1]);
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    }

    // ── Export ──────────────────────────────────────────

    async function startExport() {
        const btn = document.getElementById('ago-export-btn');
        btn.disabled = true;
        btn.textContent = 'Exportando...';
        showEl('ago-export-progress');

        try {
            logLine('Iniciando export...', 'info');
            const { job_id, total_steps } = await postJSON('/export/start', {});
            logLine('Job: ' + job_id + ' | Steps: ' + total_steps, 'info');

            for (let i = 0; i < total_steps; i++) {
                const result = await postJSON('/export/step', { job_id });
                updateProgress('ago-export-bar', 'ago-export-status', result.step, result.total, result.message);
                logLine(result.message, 'ok');

                if (result.done) {
                    logLine('Export completado. Descargando...', 'ok');
                    // Trigger download
                    const a = document.createElement('a');
                    a.href = result.download_url + '&_wpnonce=' + cfg.nonce;
                    a.download = '';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    break;
                }
            }
        } catch (e) {
            logLine('ERROR: ' + e.message, 'err');
        }

        btn.disabled = false;
        btn.textContent = 'Exportar Backup';
    }

    // ── Import ─────────────────────────────────────────

    let importJobId = null;

    async function handleImportFile(file) {
        if (!file || !file.name.endsWith('.zip')) {
            logLine('Solo archivos .zip son aceptados', 'err');
            return;
        }

        importJobId = generateJobId();
        showEl('ago-import-progress');
        logLine('Subiendo: ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + ' MB)', 'info');

        try {
            // Chunked upload
            const chunkSize = cfg.maxUploadChunk;
            const totalChunks = Math.ceil(file.size / chunkSize);

            for (let i = 0; i < totalChunks; i++) {
                const slice = file.slice(i * chunkSize, (i + 1) * chunkSize);
                const base64 = await readAsBase64(slice);
                await postJSON('/import/upload', {
                    job_id: importJobId,
                    chunk_index: i,
                    chunk: base64,
                    total_chunks: totalChunks
                });
                updateProgress('ago-import-bar', 'ago-import-status', i + 1, totalChunks, 'Subiendo...');
            }

            logLine('Upload completo. Leyendo manifest...', 'info');

            // Start import (read manifest)
            const info = await postJSON('/import/start', { job_id: importJobId });
            const m = info.manifest;

            // Show manifest info
            const manifestDiv = document.getElementById('ago-import-manifest');
            manifestDiv.innerHTML = [
                '<p><strong>Origen:</strong> ' + (m.site_url || 'N/A') + '</p>',
                '<p><strong>WordPress:</strong> ' + (m.wp_version || '?') + ' | <strong>PHP:</strong> ' + (m.php_version || '?') + '</p>',
                '<p><strong>Tablas:</strong> ' + (m.tables ? m.tables.length : '?') + '</p>',
                '<p><strong>Theme:</strong> ' + (m.active_theme || '?') + '</p>',
                '<p><strong>Fecha backup:</strong> ' + (m.timestamp || '?') + '</p>',
            ].join('');

            showEl('ago-import-info');
            hideEl('ago-import-progress');
            document.getElementById('ago-import-status').textContent = '';

            logLine('Manifest leido. Esperando confirmacion...', 'info');

        } catch (e) {
            logLine('ERROR: ' + e.message, 'err');
        }
    }

    async function confirmImport() {
        if (!importJobId) return;

        const confirmBtn = document.getElementById('ago-import-confirm-btn');
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Importando...';
        hideEl('ago-import-info');
        showEl('ago-import-progress');

        try {
            logLine('Importando...', 'info');

            let done = false;
            while (!done) {
                const result = await postJSON('/import/step', { job_id: importJobId });
                updateProgress('ago-import-bar', 'ago-import-status', result.step, result.total, result.message);
                logLine(result.message, 'ok');
                done = result.done;

                if (done && result.redirect_url) {
                    logLine('Importacion completa. Redirigiendo al login...', 'ok');
                    setTimeout(() => { window.location.href = result.redirect_url; }, 2000);
                }
            }
        } catch (e) {
            logLine('ERROR: ' + e.message, 'err');
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirmar e Importar';
        }
    }

    // ── Event Bindings ─────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        // Export button
        const exportBtn = document.getElementById('ago-export-btn');
        if (exportBtn) exportBtn.addEventListener('click', startExport);

        // Import dropzone
        const dropzone = document.getElementById('ago-import-dropzone');
        const fileInput = document.getElementById('ago-import-file');

        if (dropzone) {
            dropzone.addEventListener('dragover', function (e) {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });
            dropzone.addEventListener('dragleave', function () {
                dropzone.classList.remove('dragover');
            });
            dropzone.addEventListener('drop', function (e) {
                e.preventDefault();
                dropzone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    handleImportFile(e.dataTransfer.files[0]);
                }
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                if (fileInput.files.length) {
                    handleImportFile(fileInput.files[0]);
                }
            });
        }

        // Confirm checkbox
        const confirmCheck = document.getElementById('ago-import-confirm-check');
        const confirmBtn = document.getElementById('ago-import-confirm-btn');

        if (confirmCheck && confirmBtn) {
            confirmCheck.addEventListener('change', function () {
                confirmBtn.disabled = !confirmCheck.checked;
            });
            confirmBtn.addEventListener('click', confirmImport);
        }
    });
})();
