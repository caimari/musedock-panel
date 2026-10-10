(() => {
    'use strict';
    document.querySelectorAll('[data-mcp-permissions-marker]').forEach(marker => {
        const form = marker.form;
        const read = form.elements.allow_read, write = form.elements.allow_write, dns = form.elements.allow_dns;
        read.addEventListener('change', () => { if (!read.checked) { write.checked = false; dns.checked = false; } });
        write.addEventListener('change', () => { if (write.checked) read.checked = true; else dns.checked = false; });
        dns.addEventListener('change', () => { if (dns.checked) { read.checked = true; write.checked = true; } });
    });
    document.querySelectorAll('[data-mcp-approve]').forEach(button => {
        button.addEventListener('click', event => {
            if (!confirm('¿Confirmas que quieres ejecutar exactamente el plan mostrado?')) event.preventDefault();
        });
    });
    document.querySelectorAll('[data-mcp-copy]').forEach(button => {
        button.addEventListener('click', async () => {
            const input = document.getElementById(button.dataset.mcpCopy);
            if (!input) return;
            try {
                await navigator.clipboard.writeText(input.value);
                const label = button.textContent;
                button.textContent = 'Copiado';
                setTimeout(() => { button.textContent = label; }, 2000);
            } catch (_) {
                input.focus();
                if (input.type !== 'hidden') input.select();
                (window.musedockToast || alert)('No se pudo copiar. Utiliza la selección del campo para copiarlo manualmente.', 'warning');
            }
        });
    });
    document.querySelectorAll('[data-mcp-toggle]').forEach(button => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.mcpToggle);
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
            button.textContent = input.type === 'password' ? 'Mostrar' : 'Ocultar';
            button.setAttribute('aria-pressed', String(input.type === 'text'));
        });
    });
    document.querySelectorAll('[data-mcp-dismiss]').forEach(button => {
        button.addEventListener('click', () => {
            if (!confirm('¿Has guardado el secreto? Al cerrar se retirará de esta página y no podrás recuperarlo.')) return;
            const box = document.getElementById(button.dataset.mcpDismiss);
            if (!box) return;
            box.querySelectorAll('input').forEach(input => { input.value = ''; input.removeAttribute('value'); });
            box.remove();
        });
    });
})();
