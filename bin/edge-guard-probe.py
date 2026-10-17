#!/usr/bin/env python3
"""
Sonda de MuseDock CMS para detectar bloqueos de IPs de Cloudflare.
MuseDock CMS probe for detecting blocked Cloudflare IPs.

Cada minuto (cron) pide a tu CMS la lista de IPs que tiene que probar, las prueba por cada salida a
internet (p. ej. dos operadoras) y le entrega los resultados. No guarda ni recibe claves de Cloudflare:
solo su token de sonda, que únicamente sirve para pedir la lista y entregar resultados.
Guía / guide: https://musedock.com/docs/escudo-bloqueos-cloudflare

Configuración / configuration: /etc/musedock-edge-probe.conf (CLAVE=valor)
    URL=https://tu-cms.example               # tu CMS / your CMS
    TOKEN_FILE=/etc/musedock-edge-probe.token
    PATHS=Operadora1:eth0,Operadora2:eth1    # opcional: nombre:interfaz de cada salida; vacío = salida por defecto
    RESOLVE=10.0.0.1                         # opcional: IP por la que llegar al CMS (p. ej. por WireGuard)
    API_PREFIX=/api/v1/edge-guard            # MuseDock CMS (por defecto); MuseDock Panel: /api/cf-guard
    INSECURE=1                               # opcional: no comprobar el certificado (paneles con certificado propio)
    CONTROL=https://www.google.com/generate_204   # opcional: web de control

Prueba a mano / manual test:   python3 -I /usr/local/bin/musedock-edge-probe.py -v
Otra configuración (p. ej. para el panel) / another config:   EDGE_PROBE_CONF=/etc/otra.conf python3 -I …
Cron (root, para poder elegir la interfaz de salida / root, to pick the outgoing interface):
    * * * * * root flock -n /run/musedock-edge-probe.lock python3 -I /usr/local/bin/musedock-edge-probe.py
"""
import json
import socket
import os
import subprocess
import sys
import tempfile
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urlparse

CONF = os.environ.get('EDGE_PROBE_CONF', '/etc/musedock-edge-probe.conf')


def load_conf():
    conf = {'PATHS': '', 'CONTROL': 'https://www.google.com/generate_204', 'RESOLVE': '', 'API_PREFIX': '/api/v1/edge-guard', 'INSECURE': ''}
    with open(CONF) as fh:
        for line in fh:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                k, v = line.split('=', 1)
                conf[k.strip()] = v.strip()
    with open(conf['TOKEN_FILE']) as fh:
        conf['TOKEN'] = fh.read().strip()
    return conf


def curl(args, timeout=10):
    try:
        out = subprocess.run(['curl', '-s'] + args, capture_output=True, text=True, timeout=timeout)
        return out.stdout
    except Exception:
        return ''


def api(conf, method, path, body=None):
    """Llamada al CMS o al panel con el token en un fichero de cabeceras (no aparece en la lista de procesos)."""
    url = conf['URL'].rstrip('/') + conf['API_PREFIX'].rstrip('/') + path
    parsed = urlparse(url)
    host = parsed.hostname
    port = parsed.port or 443
    with tempfile.NamedTemporaryFile('w', delete=False) as hf:
        os.chmod(hf.name, 0o600)
        hf.write('Authorization: Bearer %s\nContent-Type: application/json\n' % conf['TOKEN'])
    try:
        args = ['-X', method, '-H', '@' + hf.name, '--max-time', '20', '-w', '\n%{http_code}']
        if conf.get('RESOLVE'):
            args += ['--resolve', '%s:%d:%s' % (host, port, conf['RESOLVE'])]
        if conf.get('INSECURE') == '1':
            args += ['-k']   # solo para paneles con certificado propio; mejor fijar uno válido
        if body is not None:
            args += ['--data-binary', '@-']
            out = subprocess.run(['curl', '-s'] + args + [url], input=json.dumps(body), capture_output=True, text=True, timeout=30).stdout
        else:
            out = curl(args + [url], timeout=30)
    finally:
        os.unlink(hf.name)
    text, _, code = out.rpartition('\n')
    return code.strip(), text


def check(iface, target):
    """¿Se llega por esta salida?
    cf: la IP de Cloudflare responde si se completa la conexión cifrada (TLS), aunque la web tarde o dé error:
        un bloqueo de la operadora impide conectar; una web lenta o caída conecta y luego no contesta.
    origin: certificado válido y respuesta sin error 5xx."""
    tid, ip, host = target
    args = ['-o', '/dev/null', '-w', '%{http_code} %{time_total} %{time_appconnect}', '--max-time', '8', '--connect-timeout', '5',
            '--resolve', '%s:443:%s' % (host, ip), 'https://%s/' % host]
    if iface:
        args = ['--interface', iface] + args
    out = curl(args, timeout=12).split()
    code = out[0] if out else '000'
    ms = int(float(out[1]) * 1000) if len(out) > 1 else 0
    tls = float(out[2]) > 0 if len(out) > 2 else False
    n = int(code) if code.isdigit() else 0
    ok = (200 <= n < 500) if tid.startswith('origin:') else (n > 0 or tls)
    if not tid.startswith('origin:') and n == 0 and tls:
        code = 'tls'   # conectó con Cloudflare, pero la web no contestó a tiempo
    return {'t': tid, 'ok': ok, 'code': code, 'ms': ms}


def main():
    conf = load_conf()
    code, text = api(conf, 'GET', '/targets')
    if code != '200':
        hint = {'401': ' — token incorrecto o revocado', '000': ' — no se llega al servidor: revisa URL, API_PREFIX y RESOLVE'}.get(code, '')
        print('No se pudo pedir la lista de objetivos (HTTP %s)%s' % (code, hint), file=sys.stderr)
        return 1
    targets = []
    dns_names = []
    for line in text.splitlines():
        parts = line.split()
        if len(parts) == 3:
            if parts[0].startswith('dns:'):
                dns_names.append((parts[0], parts[2]))   # resolver desde aquí, no probar
            else:
                targets.append(tuple(parts))

    # IPs que da el DNS en este país (Cloudflare responde distinto según desde dónde se pregunte)
    def resolve(item):
        tid, host = item
        try:
            ips = sorted({ai[4][0] for ai in socket.getaddrinfo(host, 443, socket.AF_INET, socket.SOCK_STREAM)})
        except Exception:
            ips = []
        return {'t': tid, 'ips': ips}

    dns = []
    if dns_names:
        with ThreadPoolExecutor(max_workers=16) as pool:
            dns = [d for d in pool.map(resolve, dns_names) if d['ips']]

    paths = []
    for item in [p for p in conf['PATHS'].split(',') if p.strip()] or ['default:']:
        label, _, iface = item.partition(':')
        iface = iface.strip()
        pre = ['--interface', iface] if iface else []
        public_ip = curl(pre + ['--max-time', '6', 'https://api.ipify.org']).strip()[:64]
        ctl = curl(pre + ['-o', '/dev/null', '-w', '%{http_code}', '--max-time', '6', conf['CONTROL']]).strip()
        control_ok = ctl.isdigit() and 200 <= int(ctl) < 400
        results = []
        if control_ok and targets:
            with ThreadPoolExecutor(max_workers=8) as pool:
                results = list(pool.map(lambda t: check(iface, t), targets))
        paths.append({'path': label.strip(), 'public_ip': public_ip, 'control_ok': control_ok, 'results': results})
        if VERBOSE:
            fails = [r for r in results if not r['ok']]
            print('[%s] interfaz=%s ip_publica=%s control=%s objetivos=%d fallan=%d'
                  % (label.strip(), iface or '(por defecto)', public_ip or '?', 'ok' if control_ok else 'FALLA (sin internet por esta salida)', len(results), len(fails)))
            for r in fails:
                print('    no responde: %s (código %s)' % (r['t'], r['code']))

    if VERBOSE:
        print('DNS desde aquí: %d nombres resueltos' % len(dns))
    code, text = api(conf, 'POST', '/report', {'paths': paths, 'dns': dns})
    if code != '200':
        hint = ' — normal si el cron acaba de entregar otro (máximo uno cada 20 s)' if code == '429' else ''
        print('El servidor no aceptó el informe (HTTP %s)%s: %s' % (code, hint, text[:200]), file=sys.stderr)
        return 1
    if VERBOSE:
        print('Informe entregado: %s' % text.strip()[:200])
    return 0


VERBOSE = '-v' in sys.argv or '--verbose' in sys.argv


if __name__ == '__main__':
    sys.exit(main())
