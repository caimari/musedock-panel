document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var form = document.getElementById('dnsReviewForm');
    if (!form) return;
    var rows = document.getElementById('dnsReviewRows'), message = document.getElementById('dnsReviewMessage');
    var apply = document.getElementById('dnsApply'), confirm = document.getElementById('dnsConfirmApply');
    var consent = document.getElementById('dnsConsent'), refresh = document.getElementById('dnsRefresh');
    var modalEl = document.getElementById('dnsConfirmModal'), modal = new bootstrap.Modal(modalEl);
    var review = null, changes = [], busy = false;
    var showInitialConfirmation = new URLSearchParams(location.search).get('created') === '1';
    function text(tag, value, cls) { var el = document.createElement(tag); el.textContent = value; if (cls) el.className = cls; return el; }
    function record(r) { return r.type + ' ' + r.name + ' → ' + (r.priority !== undefined && r.type === 'MX' ? r.priority + ' ' : '') + r.content + (r.proxied ? ' (proxy)' : ''); }
    function warningList(target, warnings) { target.replaceChildren(); warnings.forEach(function (w) { target.appendChild(text('div', w, 'alert alert-warning')); }); }
    async function request(path, data) {
        var response = await fetch(path, {method: 'POST', body: data, headers: {'Accept': 'application/json'}});
        var result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || 'No se pudo completar la operación.');
        return result;
    }
    async function load(preserveMessage) {
        var previousMessage = preserveMessage === true ? message.textContent : null;
        if (busy) return;
        busy = true; refresh.disabled = true; apply.disabled = true; review = null;
        message.textContent = 'Consultando registros, cuentas y nameservers…'; rows.replaceChildren();
        try {
            review = await request('/domains/dns-sync/plan', new FormData(form));
            var plan = review.plan;
            changes = plan.entries.filter(function (e) { return e.managed && e.status !== 'ok'; });
            plan.entries.forEach(function (e) {
                var tr = document.createElement('tr');
                tr.appendChild(text('td', e.expected.type + ' ' + e.expected.name));
                tr.appendChild(text('td', e.provider + (e.account ? ' / ' + e.account : '') + '\n' + e.nameservers.join(', ')));
                tr.appendChild(text('td', e.current.map(record).join('\n') || 'Sin registro'));
                tr.appendChild(text('td', record(e.expected)));
                tr.appendChild(text('td', e.status === 'ok' ? 'Alineado' : (e.managed ? e.status : 'Publicar manualmente')));
                rows.appendChild(tr);
            });
            warningList(document.getElementById('dnsReviewWarnings'), plan.warnings);
            document.getElementById('dnsReturn').href = plan.return_url;
            apply.disabled = changes.length === 0 || confirm.dataset.readonly === '1';
            message.textContent = changes.length + ' registro(s) con cambios gestionables. La revisión caduca en 10 minutos.';
        } catch (err) {
            message.textContent = err.message;
            if (previousMessage !== null) previousMessage += ' No se pudo actualizar la tabla: ' + err.message;
        }
        finally {
            if (previousMessage !== null) message.textContent = previousMessage;
            busy = false; refresh.disabled = false;
            if (showInitialConfirmation && review && !apply.disabled) {
                showInitialConfirmation = false;
                var url = new URL(location.href); url.searchParams.delete('created'); history.replaceState(null, '', url);
                apply.click();
            }
        }
    }
    apply.addEventListener('click', function () {
        if (!review || busy) return;
        var body = document.getElementById('dnsConfirmRecords'); body.replaceChildren();
        changes.forEach(function (e) {
            var box = text('div', '', 'border rounded p-2 mb-2');
            box.appendChild(text('div', e.current.map(record).join('\n') || 'Sin registro actual', 'text-warning small'));
            box.appendChild(text('div', record(e.expected), 'small'));
            body.appendChild(box);
        });
        warningList(document.getElementById('dnsConfirmWarnings'), review.plan.warnings);
        consent.checked = false; confirm.disabled = true; modal.show();
    });
    consent.addEventListener('change', function () { confirm.disabled = !consent.checked || busy || confirm.dataset.readonly === '1'; });
    confirm.addEventListener('click', async function () {
        if (!review || busy || !consent.checked) return;
        busy = true; confirm.disabled = true; apply.disabled = true; refresh.disabled = true;
        var data = new FormData(form); data.set('token', review.token); data.set('confirmed', '1');
        modal.hide(); message.textContent = 'Publicando DNS…';
        try {
            var result = await request('/domains/dns-sync/apply', data);
            message.textContent = (result.ok ? 'Publicación completada. ' : 'Publicación parcial. ') + result.done.join(', ') + '. ' + result.errors.join(' ') + ' ' + result.message
                + ' ' + (result.tls || []).join(' ')
                + (result.manual_pending ? ' Quedan ' + result.manual_pending + ' registro(s) para publicar en su proveedor.' : '');
        } catch (err) { message.textContent = err.message; }
        finally { review = null; busy = false; refresh.disabled = false; await load(true); document.getElementById('dnsVerify').click(); }
    });
    document.getElementById('dnsVerify').addEventListener('click', async function () {
        if (busy) return;
        busy = true; this.disabled = true;
        var button = this;
        document.getElementById('dnsVerifyResult').textContent = 'Comprobando DNS y HTTPS del origen…';
        try {
            var result = await request('/domains/dns-sync/verify', new FormData(form));
            var output = document.getElementById('dnsVerifyResult'); output.replaceChildren();
            result.dns.forEach(function (r) { output.appendChild(text('div', r.type + ' ' + r.name + ': ' + (r.aligned ? (r.public_aligned === true && !r.local_aligned ? 'DNS público alineado; caché local pendiente' : 'DNS alineado') : 'DNS pendiente o distinto'))); });
            result.tls.forEach(function (r) { output.appendChild(text('div', r.host + ': ' + (r.valid ? (r.http_status >= 400 ? 'Certificado válido; la web devuelve HTTP ' + r.http_status + ' (revisar acceso al sitio)' : 'HTTPS con certificado válido en este servidor (HTTP ' + r.http_status + ')') : 'Certificado HTTPS pendiente o no válido en este servidor'))); });
            output.appendChild(text('p', result.note, 'text-muted small mt-2'));

        } catch (err) { document.getElementById('dnsVerifyResult').textContent = err.message; }
        finally { busy = false; button.disabled = false; }
    });
    refresh.addEventListener('click', load);
    load();
});
