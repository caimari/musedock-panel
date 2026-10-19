#!/usr/bin/env python3
"""
Sonda de bloqueos de Cloudflare con varios paneles (URL=a,b): sin red, con las llamadas simuladas.

Caso real (2026-10-18): la sonda informaba solo a mortadelo; tras el relevo el panel que manda era Filemon y
nadie quitaba el proxy de las webs bloqueadas por LaLiga. Ahora informa a todos y solo se queja si ninguno acepta.

Uso: python3 -I tests/edge_probe_test.py
"""
import importlib.util
import io
import os
import sys
import tempfile
from contextlib import redirect_stderr, redirect_stdout

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
passed = failed = 0


def ok(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
        print('  \033[0;32m✓\033[0m ' + name)
    else:
        failed += 1
        print('  \033[0;31m✗\033[0m ' + name + (' — ' + detail if detail else ''))


def load(conf_text):
    d = tempfile.mkdtemp()
    conf = os.path.join(d, 'probe.conf')
    tok = os.path.join(d, 'probe.token')
    open(tok, 'w').write('egp_test\n')
    open(conf, 'w').write(conf_text.replace('@TOKEN@', tok))
    os.environ['EDGE_PROBE_CONF'] = conf
    spec = importlib.util.spec_from_file_location('probe', os.path.join(ROOT, 'bin', 'edge-guard-probe.py'))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    mod.curl = lambda args, timeout=10: ''   # sin red: ni ipify ni la web de control
    return mod


def run(mod, answers):
    """answers: {url_base: (código de /targets, código de /report)}. Devuelve (salida, stdout, stderr, llamadas)."""
    calls = []

    def fake_api(conf, method, path, body=None):
        calls.append((conf['URL'], conf.get('RESOLVE', ''), method, path))
        t, r = answers[conf['URL']]
        return (t, '') if path == '/targets' else (r, 'ok')

    mod.api = fake_api
    out, err = io.StringIO(), io.StringIO()
    with redirect_stdout(out), redirect_stderr(err):
        rc = mod.main()
    return rc, out.getvalue(), err.getvalue(), calls


A, B = 'https://a.example:8444', 'https://b.example:8444'

print('\n\033[1m1. Un solo servidor (como siempre)\033[0m')
m = load('URL=%s\nTOKEN_FILE=@TOKEN@\nAPI_PREFIX=/api/cf-guard\n' % A)
rc, o, e, c = run(m, {A: ('200', '200')})
ok('informa y sale con 0', rc == 0 and len(c) == 2, str(c))
rc, o, e, c = run(m, {A: ('401', '200')})
ok('si falla, lo dice como antes (token incorrecto) y sale con 1', rc == 1 and 'token incorrecto' in e, e)

print('\n\033[1m2. Dos paneles: master y réplica\033[0m')
m = load('URL=%s,%s\nTOKEN_FILE=@TOKEN@\nAPI_PREFIX=/api/cf-guard\nRESOLVE=10.0.0.1,10.0.0.2\n' % (A, B))
rc, o, e, c = run(m, {A: ('200', '200'), B: ('200', '200')})
ok('informa a los dos', rc == 0 and {x[0] for x in c} == {A, B}, str(c))
ok('cada servidor usa su RESOLVE por posición', ('https://a.example:8444', '10.0.0.1') in {(x[0], x[1]) for x in c} and ('https://b.example:8444', '10.0.0.2') in {(x[0], x[1]) for x in c}, str(c))
rc, o, e, c = run(m, {A: ('000', '000'), B: ('200', '200')})
ok('uno caído (000) y el otro acepta: éxito y SIN ruido en stderr (no llena el correo cada minuto)', rc == 0 and e == '', 'rc=%s err=%r' % (rc, e))
rc, o, e, c = run(m, {A: ('401', '000'), B: ('200', '200')})
ok('uno rechaza el token y el otro acepta: éxito y callado', rc == 0 and e == '', 'rc=%s err=%r' % (rc, e))
rc, o, e, c = run(m, {A: ('000', '000'), B: ('000', '000')})
ok('los dos caídos: se queja UNA vez, con los dos motivos', rc == 1 and 'Ningún servidor aceptó' in e and A in e and B in e, e)
rc, o, e, c = run(m, {A: ('200', '429'), B: ('200', '200')})
ok('un 429 (límite de 20 s) en uno no es un fallo si el otro acepta', rc == 0 and e == '', e)

print('\n\033[1m3. Detalles\033[0m')
m = load('URL= %s , %s \nTOKEN_FILE=@TOKEN@\nAPI_PREFIX=/api/cf-guard\n' % (A, B))
rc, o, e, c = run(m, {A: ('200', '200'), B: ('200', '200')})
ok('espacios alrededor de las comas no importan; sin RESOLVE funciona', rc == 0 and len(c) == 4 and all(x[1] == '' for x in c), str(c))
m = load('URL=%s,%s\nTOKEN_FILE=@TOKEN@\nAPI_PREFIX=/api/cf-guard\nRESOLVE=10.0.0.1\n' % (A, B))
rc, o, e, c = run(m, {A: ('200', '200'), B: ('200', '200')})
ok('menos RESOLVE que URL: la segunda va sin forzar IP', {(x[0], x[1]) for x in c} == {(A, '10.0.0.1'), (B, '')}, str(c))

print('\n\033[1m4. Informes grandes: el CMS de filemon rechazaba cuerpos de más de 16 KB (HTTP 400 «JSON no válido»)\033[0m')
import json
m = load('URL=%s\nTOKEN_FILE=@TOKEN@\nAPI_PREFIX=/api/v1/edge-guard\n' % A)


def mk_path(name, n):
    return {'path': name, 'public_ip': '203.0.113.9', 'control_ok': True,
            'results': [{'t': 'cf:198.51.100.%d' % i if i < 40 else 'origin:%032x' % i, 'ok': i % 17 != 0, 'code': '200', 'ms': 12 + i} for i in range(n)]}


def mk_dns(n):
    return [{'t': 'dns:%032x' % i, 'ips': ['198.51.100.%d' % (i % 250), '198.51.100.%d' % ((i + 1) % 250)]} for i in range(n)]


def total(bodies):
    paths = {}
    for b in bodies:
        for p in b['paths']:
            paths.setdefault(p['path'], []).extend(r['t'] for r in p['results'])
    return paths, [d['t'] for b in bodies for d in b['dns']]


small = ([mk_path('ONO', 5), mk_path('Orange', 5)], mk_dns(3))
bs = m.split_report(*small)
ok('un informe pequeño va ENTERO en una sola entrega (como siempre)', len(bs) == 1 and bs[0] == {'paths': small[0], 'dns': small[1]})

real = ([mk_path('ONO', 74), mk_path('Orange', 74)], mk_dns(72))
assert len(json.dumps({'paths': real[0], 'dns': real[1]})) > 16384, 'la prueba debe superar los 16 KB'
bs = m.split_report(*real)
ok('el informe real (~17,7 KB) se reparte en varias partes', len(bs) >= 2, str(len(bs)))
ok('NINGUNA parte pasa de 15.000 bytes (el servidor corta en 16.384)', all(len(json.dumps(b)) <= 15000 for b in bs), str([len(json.dumps(b)) for b in bs]))
tp, td = total(bs)
ok('no se pierde ni se duplica ninguna medición ni ningún nombre DNS', tp == {'ONO': [r['t'] for r in real[0][0]['results']], 'Orange': [r['t'] for r in real[0][1]['results']]} and td == [d['t'] for d in real[1]], '%s %s' % ({k: len(v) for k, v in tp.items()}, len(td)))
ok('cada parte es un informe válido para el servidor (lista «paths» presente)', all(isinstance(b['paths'], list) and isinstance(b['dns'], list) for b in bs))

huge = ([mk_path('ONO', 400)], mk_dns(2))
bs = m.split_report(*huge)
tp, td = total(bs)
ok('una sola ruta enorme (400 objetivos) se parte en trozos con la misma cabecera', len(bs) >= 2 and all(len(json.dumps(b)) <= 15000 for b in bs) and all(p['path'] == 'ONO' and p['public_ip'] == '203.0.113.9' and p['control_ok'] is True for b in bs for p in b['paths']))
ok('y no se pierde ningún objetivo', tp == {'ONO': [r['t'] for r in huge[0][0]['results']]} and td == [d['t'] for d in huge[1]])

dnsbig = ([mk_path('ONO', 3)], mk_dns(600))
bs = m.split_report(*dnsbig)
tp, td = total(bs)
ok('mucho DNS: va en partes propias, sin perder nombres', all(len(json.dumps(b)) <= 15000 for b in bs) and td == [d['t'] for d in dnsbig[1]] and tp == {'ONO': [r['t'] for r in dnsbig[0][0]['results']]})

# Entrega: run_once con las llamadas simuladas
import socket as _socket
orig_gai = _socket.getaddrinfo
_socket.getaddrinfo = lambda *a, **k: [(2, 1, 6, '', ('198.51.100.7', 443))]
m.curl = lambda args, timeout=10: '204' if '-w' in args else '203.0.113.9'
m.check = lambda iface, t: {'t': t[0], 'ok': True, 'code': '200', 'ms': 9}
targets_txt = '\n'.join(['cf:198.51.100.%d 198.51.100.%d x%d.example' % (i, i, i) for i in range(34)]
                        + ['origin:%032x 203.0.113.%d y%d.example' % (i, i, i) for i in range(40)]      # como los reales: ids largos
                        + ['dns:%032x - n%d.example' % (i, i) for i in range(72)])
sleeps, posts = [], []
m.time.sleep = lambda s: sleeps.append(s)
m.PAUSE = 21


def run_with(post_codes):
    posts.clear(); sleeps.clear()
    codes = iter(post_codes)

    def fake_api(conf, method, path, body=None):
        if method == 'GET':
            return '200', targets_txt
        posts.append(body)
        return next(codes, '200'), 'ok'
    m.api = fake_api
    out, err = io.StringIO(), io.StringIO()
    with redirect_stdout(out), redirect_stderr(err):
        c = dict(m.load_conf())
        c['PATHS'] = 'ONO:,Orange:'   # dos salidas, como en b250 (sin interfaz: la prueba no toca la red)
        rc = m.run_once(c)
    return rc, err.getvalue()


m.PATHS_BACKUP = None
rc, e = run_with(['200', '200', '200', '200'])
ok('con el informe grande se entrega en varias partes, esperando 21 s entre ellas', rc[0] == 0 and len(posts) >= 2 and sleeps == [21] * (len(posts) - 1), '%s posts=%d sleeps=%s' % (rc, len(posts), sleeps))
ok('ninguna de las partes enviadas pasa de 15.000 bytes', all(len(json.dumps(b)) <= 15000 for b in posts), str([len(json.dumps(b)) for b in posts]))
rc, e = run_with(['429'])
ok('si la PRIMERA parte se rechaza, es un fallo (como antes)', rc[0] == 1 and 'HTTP 429' in rc[1])
rc, e = run_with(['200', '429', '429'])
ok('si se rechaza una parte POSTERIOR, lo ya entregado vale y no es un fallo', rc[0] == 0 and e == '', '%s %r' % (rc, e))
_socket.getaddrinfo = orig_gai

print('\n\033[1m5. Comprobación ligera: solo el saludo TLS, sin pedir ninguna página (la sonda hacía ~120 portadas de WordPress por minuto)\033[0m')
import ssl, socket, subprocess, threading, shutil
m = load('URL=%s\nTOKEN_FILE=@TOKEN@\nAPI_PREFIX=/api/v1/edge-guard\n' % A)
d = tempfile.mkdtemp()
crt, key = os.path.join(d, 'c.pem'), os.path.join(d, 'k.pem')
have_openssl = shutil.which('openssl') is not None
if have_openssl:
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', crt, '-days', '2', '-subj', '/CN=web.example',
                    '-addext', 'subjectAltName=DNS:web.example'], capture_output=True, check=True)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(crt, key)
    srv = socket.socket(); srv.bind(('127.0.0.1', 0)); srv.listen(5)
    port = srv.getsockname()[1]
    requests_seen = []

    def serve():
        while True:
            try:
                c, _ = srv.accept()
            except OSError:
                return
            try:
                t = ctx.wrap_socket(c, server_side=True)
                c.settimeout(0.3)
                try:
                    data = t.recv(1024)
                    if data:
                        requests_seen.append(data)
                except Exception:
                    pass
                t.close()
            except Exception:
                try: c.close()
                except Exception: pass
    threading.Thread(target=serve, daemon=True).start()

    # el programa conecta al 443: se redirige al puerto de prueba cambiando la tupla de connect
    real_connect = socket.socket.connect
    socket.socket.connect = lambda self, addr: real_connect(self, ('127.0.0.1', port) if addr[1] == 443 else addr)
    m.CA_FILE = crt
    r = m.tls_check('', ('origin:abc', '127.0.0.1', 'web.example'))
    ok('saludo TLS correcto con certificado válido: ok, código «tls»', r['ok'] is True and r['code'] == 'tls', str(r))
    ok('NO manda ninguna petición HTTP (nada llega a PHP ni a la web)', requests_seen == [], str(requests_seen))
    r = m.tls_check('', ('origin:abc', '127.0.0.1', 'otro.example'))
    ok('certificado que no es de ese nombre: fallo «cert» (no se confunde con un bloqueo)', r['ok'] is False and r['code'] == 'cert', str(r))
    socket.socket.connect = real_connect
    srv.close()
else:
    print('  (sin openssl: se omiten las pruebas con servidor TLS local)')

calls = []
m.tls_check = lambda iface, t: calls.append((iface, t)) or {'t': t[0], 'ok': True, 'code': 'tls', 'ms': 3}
m.CACHE_DIR = os.path.join(d, 'cache')
m.time.sleep = lambda s: None
t1 = ('cf:1.2.3.4', '1.2.3.4', 'a.example')
m.check('ONO', t1); m.check('ONO', t1)
ok('dos sondas (panel y CMS) midiendo lo mismo en 45 s: se mide UNA vez', len(calls) == 1, str(len(calls)))
m.check('Orange', t1)
ok('otro operador no comparte la medición (cada línea se mide por separado)', len(calls) == 2)
m.check('ONO', ('cf:1.2.3.5', '1.2.3.5', 'a.example'))
ok('otra IP se mide aparte', len(calls) == 3)
os.utime(os.path.join(m.CACHE_DIR, os.listdir(m.CACHE_DIR)[0]), (0, 0))
for f in os.listdir(m.CACHE_DIR):
    os.utime(os.path.join(m.CACHE_DIR, f), (0, 0))
m.check('ONO', t1)
ok('pasados 45 s se vuelve a medir', len(calls) == 4)
src = open(os.path.join(ROOT, 'bin', 'edge-guard-probe.py')).read()
ok('el modo por defecto es TLS y se puede volver al antiguo con CHECK=http', "CHECK_MODE = 'tls'" in src and "CHECK=http" in src)
shutil.rmtree(d, ignore_errors=True)

print('\n' + ('\033[0;32mTodo correcto: %d comprobaciones\033[0m' % passed if failed == 0 else '\033[0;31m%d fallo(s) de %d\033[0m' % (failed, passed + failed)))
sys.exit(0 if failed == 0 else 1)
