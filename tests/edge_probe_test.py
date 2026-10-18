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

print('\n' + ('\033[0;32mTodo correcto: %d comprobaciones\033[0m' % passed if failed == 0 else '\033[0;31m%d fallo(s) de %d\033[0m' % (failed, passed + failed)))
sys.exit(0 if failed == 0 else 1)
