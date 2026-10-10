'use strict';
// Minimal DOM fixture exercises the real frontend without a browser or live server.
const fs = require('fs'), vm = require('vm'), assert = require('assert');
class Element {
    constructor() { this.children = []; this.handlers = {}; this.dataset = {}; this.disabled = false; this.checked = false; this.textContent = ''; }
    appendChild(el) { this.children.push(el); return el; }
    replaceChildren() { this.children = []; this.textContent = ''; }
    addEventListener(name, fn) { this.handlers[name] = fn; }
    click() { if (!this.disabled && this.handlers.click) return this.handlers.click.call(this); }
    set innerHTML(value) { throw new Error('DNS values must not be inserted as HTML'); }
}
class FormFixture {
    constructor() { this.values = new Map([['_csrf', 'fixture-csrf'], ['domain', 'example.com'], ['scope', 'hosting']]); }
    set(key, value) { this.values.set(key, value); }
    get(key) { return this.values.get(key); }
}
const ids = ['dnsReviewForm', 'dnsReviewRows', 'dnsReviewMessage', 'dnsApply', 'dnsConfirmApply', 'dnsConsent', 'dnsRefresh', 'dnsConfirmModal',
    'dnsReviewWarnings', 'dnsReturn', 'dnsConfirmRecords', 'dnsConfirmWarnings', 'dnsVerify', 'dnsVerifyResult'];
const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
let ready, shown = 0, applied = 0, verified = 0, planFails = false;
const calls = [];
const entry = {managed: true, status: 'sustituir', provider: 'cloudflare', account: '<img onerror=alert(1)>', nameservers: ['ns.cloudflare.com'],
    expected: {type: 'CNAME', name: 'example.com', content: 'origin.example.net', proxied: false}, current: [{type: 'A', name: 'example.com', content: '1.2.3.4'}]};
const context = {
    document: {getElementById: id => elements[id], createElement: () => new Element(), addEventListener: (name, fn) => { ready = fn; }},
    bootstrap: {Modal: class { show() { shown++; } hide() {} }},
    FormData: FormFixture, URL, URLSearchParams,
    location: {search: '?created=1&domain=example.com', href: 'https://panel.test/domains/dns-sync?created=1&domain=example.com'},
    history: {replaceState() {}},
    fetch: async (path, options) => {
        calls.push({path, options});
        if (path.endsWith('/plan')) return {ok: !planFails, json: async () => planFails ? {error: 'fixture read failure'} :
            {ok: true, token: 'review-token', plan: {entries: [applied ? {...entry, status: 'ok', current: [entry.expected]} : entry, {...entry, managed: false}], warnings: ['Review external MX'], return_url: '/accounts/7'}}};
        if (path.endsWith('/apply')) { applied++; return {ok: true, json: async () => ({ok: true, done: ['CNAME example.com'], errors: [], tls: [], message: 'Published', manual_pending: 1})}; }
        if (path.endsWith('/verify')) { verified++; return {ok: true, json: async () => ({ok: true, dns: [{type: 'CNAME', name: 'example.com', aligned: false}], tls: [{host: 'example.com', valid: false}], note: 'Resolver fixture'})}; }
        throw new Error('Unexpected endpoint');
    }
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../public/assets/js/domain-dns-sync.js', 'utf8'), context);
async function settle() { await new Promise(resolve => setImmediate(resolve)); }
(async () => {
    assert.strictEqual(applied, 0);
    ready(); await settle();
    assert.strictEqual(shown, 1, 'new-domain review opens the confirmation modal');
    assert.strictEqual(applied, 0, 'opening review never writes');
    assert.strictEqual(elements.dnsConfirmApply.disabled, true, 'explicit acknowledgement required');
    assert.strictEqual(elements.dnsConfirmRecords.children.length, 1, 'unmanaged records cannot be confirmed for API publication');
    elements.dnsConsent.checked = true; elements.dnsConsent.handlers.change();
    assert.strictEqual(elements.dnsConfirmApply.disabled, false);
    elements.dnsConfirmApply.click(); elements.dnsConfirmApply.click(); await settle();
    assert.strictEqual(applied, 1, 'double click does not submit twice');
    assert.strictEqual(verified, 1, 'publication automatically verifies DNS and HTTPS');
    const sent = calls.find(c => c.path.endsWith('/apply')).options.body;
    assert.strictEqual(sent.get('token'), 'review-token');
    assert.strictEqual(sent.get('confirmed'), '1');
    assert.strictEqual(sent.get('_csrf'), 'fixture-csrf');
    assert.strictEqual(elements.dnsApply.disabled, true, 'refreshed aligned plan cannot be published again');
    assert(elements.dnsReviewRows.children[0].children[4].textContent === 'Alineado', 'table refreshes after publication');
    assert(elements.dnsReviewMessage.textContent.includes('Publicación completada'), 'refresh preserves publication outcome');
    assert(elements.dnsVerifyResult.children.some(el => el.textContent.includes('pendiente')), 'pending state is visible');
    planFails = true; elements.dnsRefresh.click(); await settle();
    assert.strictEqual(elements.dnsApply.disabled, true, 'failed reads keep publication disabled');
    assert.strictEqual(elements.dnsReviewMessage.textContent, 'fixture read failure');
    console.log('UI flow passed: consent, safe text rendering, CSRF, single submission, verification and read failures.');
})().catch(err => { console.error(err); process.exitCode = 1; });
