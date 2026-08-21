(function () {
    'use strict';

    const cfg = window.agomigratorMigrator;
    if (!cfg) return;

    const t = cfg.i18n || {};

    /*
     * A step is only repeated when the request never reached WordPress: a rate
     * limit in front of the site, or the server refusing connections. Repeating
     * a step that did run would append the same data to the archive twice, so
     * every other failure stops the job and says why.
     */
    const RETRY_STATUSES = [429, 503];
    const MAX_ATTEMPTS = 6;
    const PACE_AFTER_LIMIT = 1100;

    let pace = 0;
    let paceAnnounced = false;

    function wait(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function fill(template, value) {
        return String(template || '').replace('%s', value);
    }

    async function request(endpoint, data) {
        let resp;

        try {
            resp = await fetch(cfg.restUrl + endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.nonce
                },
                body: JSON.stringify(data)
            });
        } catch (e) {
            const offline = new Error(t.networkError);
            offline.retryable = true;
            throw offline;
        }

        const body = await resp.text();
        let json = null;

        if (body) {
            try {
                json = JSON.parse(body);
            } catch (e) {
                json = null;
            }
        }

        if (!resp.ok) {
            const failure = new Error(
                (json && (json.error || json.message)) || fill(t.serverError, resp.status)
            );
            failure.retryable = RETRY_STATUSES.indexOf(resp.status) !== -1;
            failure.retryAfter = parseInt(resp.headers.get('Retry-After'), 10) || 0;
            throw failure;
        }

        if (null === json) {
            throw new Error(t.invalidResponse);
        }

        return json;
    }

    async function postJSON(endpoint, data) {
        let backoff = 2;

        for (let attempt = 1; ; attempt++) {
            if (pace) {
                await wait(pace);
            }

            try {
                return await request(endpoint, data);
            } catch (e) {
                if (!e.retryable || attempt >= MAX_ATTEMPTS) {
                    throw e;
                }

                /*
                 * Once the server has said it accepts fewer requests, the rest
                 * of the job runs at a slower pace instead of hitting the wall
                 * on every remaining step.
                 */
                pace = PACE_AFTER_LIMIT;

                if (!paceAnnounced) {
                    paceAnnounced = true;
                    logLine(t.slowingDown, 'info');
                }

                const delay = e.retryAfter > 0 ? e.retryAfter : backoff;
                logLine(fill(t.retrying, delay), 'info');
                await wait(delay * 1000);
                backoff = Math.min(backoff * 2, 30);
            }
        }
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

    async function startExport() {
        const btn = document.getElementById('ago-export-btn');
        btn.disabled = true;
        btn.textContent = t.exporting;
        showEl('ago-export-progress');

        try {
            logLine(t.startingExport, 'info');
            const { job_id, total_steps } = await postJSON('/export/start', {});
            logLine('Job: ' + job_id + ' | Steps: ' + total_steps, 'info');

            for (let i = 0; i < total_steps; i++) {
                const result = await postJSON('/export/step', { job_id });
                updateProgress('ago-export-bar', 'ago-export-status', result.step, result.total, result.message);
                logLine(result.message, 'ok');

                if (result.done) {
                    logLine(t.exportDone, 'ok');
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
            logLine(t.errorLabel + ': ' + e.message, 'err');
        }

        btn.disabled = false;
        btn.textContent = t.exportBtn;
    }

    let importJobId = null;

    async function handleImportFile(file) {
        if (!file || !file.name.endsWith('.zip')) {
            logLine(t.onlyZip, 'err');
            return;
        }

        importJobId = generateJobId();
        showEl('ago-import-progress');
        logLine(t.uploadingFile + ' ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + ' MB)', 'info');

        try {
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
                updateProgress('ago-import-bar', 'ago-import-status', i + 1, totalChunks, t.uploading);
            }

            logLine(t.uploadComplete, 'info');

            const info = await postJSON('/import/start', { job_id: importJobId });
            const m = info.manifest;

            const manifestDiv = document.getElementById('ago-import-manifest');
            const p = (label, value) => {
                const el = document.createElement('p');
                const strong = document.createElement('strong');
                strong.textContent = label + ' ';
                el.appendChild(strong);
                el.appendChild(document.createTextNode(value));
                return el;
            };
            manifestDiv.textContent = '';
            manifestDiv.appendChild(p(t.origin, m.site_url || 'N/A'));
            manifestDiv.appendChild(p('WordPress:', (m.wp_version || '?') + ' | PHP: ' + (m.php_version || '?')));
            manifestDiv.appendChild(p(t.tables, m.tables ? String(m.tables.length) : '?'));
            manifestDiv.appendChild(p(t.theme, m.active_theme || '?'));
            manifestDiv.appendChild(p(t.backupDate, m.timestamp || '?'));

            showEl('ago-import-info');
            hideEl('ago-import-progress');
            document.getElementById('ago-import-status').textContent = '';

            logLine(t.manifestRead, 'info');

        } catch (e) {
            logLine(t.errorLabel + ': ' + e.message, 'err');
        }
    }

    async function confirmImport() {
        if (!importJobId) return;

        const confirmBtn = document.getElementById('ago-import-confirm-btn');
        confirmBtn.disabled = true;
        confirmBtn.textContent = t.importing;
        hideEl('ago-import-info');
        showEl('ago-import-progress');

        try {
            logLine(t.importing, 'info');

            let done = false;
            while (!done) {
                const result = await postJSON('/import/step', { job_id: importJobId });
                updateProgress('ago-import-bar', 'ago-import-status', result.step, result.total, result.message);
                logLine(result.message, 'ok');
                done = result.done;

                if (done && result.redirect_url) {
                    logLine(t.importDone, 'ok');
                    setTimeout(() => { window.location.href = result.redirect_url; }, 2000);
                }
            }
        } catch (e) {
            logLine(t.errorLabel + ': ' + e.message, 'err');
            confirmBtn.disabled = false;
            confirmBtn.textContent = t.importBtn;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const exportBtn = document.getElementById('ago-export-btn');
        if (exportBtn) exportBtn.addEventListener('click', startExport);

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
