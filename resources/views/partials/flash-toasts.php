<?php
/**
 * Mensajes del panel (Flash) como toasts flotantes, en todas las páginas
 * (layout principal, login, MFA y setup). Antes eran alertas dentro de la página:
 * empujaban el contenido al aparecer y al irse, y los errores se cerraban solos
 * antes de poder leerlos.
 *
 * Éxito/info: se van solos a los 6 s (se pausa al pasar el ratón).
 * Error/aviso: se quedan hasta cerrarlos, con botón Copiar.
 * Desde JS: window.musedockToast('texto', 'success'|'error'|'warning'|'info').
 */
use MuseDockPanel\Flash;

$__toasts = [];
// Solo los tipos de aviso: otras claves Flash son datos para una página concreta
// (p. ej. db_credentials, con la contraseña recién creada) y no deben salir en un toast.
foreach (['success', 'error', 'warning', 'info'] as $__type) {
    $__msg = Flash::get($__type);
    if (is_string($__msg) && trim($__msg) !== '') {
        $__toasts[] = ['type' => (string)$__type, 'msg' => $__msg];
    }
}
?>
<style>
#md-toasts{position:fixed;top:16px;right:16px;z-index:10800;display:flex;flex-direction:column;gap:10px;width:min(440px,calc(100vw - 32px));pointer-events:none}
.md-toast{pointer-events:auto;display:flex;gap:10px;align-items:flex-start;padding:12px 12px 12px 14px;border-radius:10px;
  background:#1e293b;color:#f1f5f9;border:1px solid #334155;border-left:4px solid var(--md-c);box-shadow:0 10px 30px rgba(0,0,0,.45);
  font-size:.875rem;line-height:1.4;animation:md-toast-in .18s ease-out}
.md-toast.md-out{opacity:0;transform:translateX(16px);transition:opacity .2s,transform .2s}
.md-toast i.md-ic{color:var(--md-c);font-size:1.1rem;line-height:1.2}
.md-toast .md-txt{flex:1;white-space:pre-line;word-break:break-word;user-select:text}
.md-toast .md-act{display:flex;gap:4px;flex-shrink:0}
.md-toast button{background:none;border:0;color:#94a3b8;padding:0 4px;font-size:1rem;line-height:1;cursor:pointer}
.md-toast button:hover{color:#f1f5f9}
@keyframes md-toast-in{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
</style>
<div id="md-toasts" aria-live="polite"></div>
<script>
(function () {
    const C = { success: ['#22c55e', 'bi-check-circle-fill'], error: ['#ef4444', 'bi-x-octagon-fill'],
                warning: ['#fbbf24', 'bi-exclamation-triangle-fill'], info: ['#38bdf8', 'bi-info-circle-fill'] };
    function close(el) { el.classList.add('md-out'); setTimeout(() => el.remove(), 220); }
    window.musedockToast = function (text, type) {
        type = C[type] ? type : (type === 'danger' ? 'error' : 'info');
        const box = document.getElementById('md-toasts');
        if (!box) return;
        const sticky = type === 'error' || type === 'warning';
        const el = document.createElement('div');
        el.className = 'md-toast';
        el.setAttribute('role', sticky ? 'alert' : 'status');
        el.style.setProperty('--md-c', C[type][0]);
        el.innerHTML = '<i class="bi ' + C[type][1] + ' md-ic"></i><div class="md-txt"></div><div class="md-act">'
            + (sticky ? '<button type="button" class="md-copy" title="Copiar"><i class="bi bi-clipboard"></i></button>' : '')
            + '<button type="button" class="md-close" title="Cerrar"><i class="bi bi-x-lg"></i></button></div>';
        el.querySelector('.md-txt').textContent = String(text);
        el.querySelector('.md-close').onclick = () => close(el);
        const cp = el.querySelector('.md-copy');
        if (cp) cp.onclick = () => {
            const ok = () => { cp.innerHTML = '<i class="bi bi-clipboard-check"></i>'; };
            if (navigator.clipboard) { navigator.clipboard.writeText(String(text)).then(ok).catch(() => {}); }
        };
        if (!sticky) {
            let t = setTimeout(() => close(el), 6000);
            el.onmouseenter = () => clearTimeout(t);
            el.onmouseleave = () => { t = setTimeout(() => close(el), 3000); };
        }
        box.appendChild(el);
    };
    const initial = <?= json_encode($__toasts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const show = () => initial.forEach(t => window.musedockToast(t.msg, t.type));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show); else show();
})();
</script>
