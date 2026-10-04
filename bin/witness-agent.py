#!/usr/bin/env python3
"""
MuseDock — agente testigo ("solo ojos").

Se instala en un servidor que NO forma parte del cluster (otro proveedor, otra ciudad).
No tiene datos, ni acceso a los servidores, ni guarda nada: solo mira, cada pocos
segundos, una lista FIJA de comprobaciones (definida en su configuración, nunca por
quien pregunta) y responde cómo las ve a quien traiga la clave.

  GET /v1/status   (cabecera "Authorization: Bearer <clave>")
  → {"witness": "...", "time": ..., "targets": {"<id>": {"ok": true, "latency_ms": 42,
     "loss_pct": 0, "checked_at": ..., "error": ""}, ...}}

Tipos de comprobación:
  https: conecta a "resolve" (IP) o al nombre, con SNI = nombre del URL, verifica el
         certificado, pide la ruta y, si hay "expect", exige ese texto en la respuesta.
  tcp:   abre una conexión a host:port.

Configuración: /etc/musedock-witness/config.json (permisos 600, dueño el usuario del
servicio). Ejemplo:
{
  "listen": "10.10.70.X", "port": 8447, "key": "<48 caracteres>",
  "interval": 15, "timeout": 8, "window": 20,
  "targets": [
    {"id": "filemon-ono",    "type": "https", "url": "https://health-filemon.screenart.es/", "resolve": "213.201.21.154", "expect": "ok-filemon"},
    {"id": "filemon-orange", "type": "https", "url": "https://health-filemon.screenart.es/", "expect": "ok-filemon"},
    {"id": "mortadelo",      "type": "tcp",   "host": "207.180.246.223", "port": 443}
  ]
}
Solo biblioteca estándar de Python 3.
"""
import hmac
import json
import os
import socket
import ssl
import sys
import threading
import time
from collections import deque
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

CONFIG = os.environ.get("MUSEDOCK_WITNESS_CONFIG", "/etc/musedock-witness/config.json")
VERSION = "1"

with open(CONFIG, "r", encoding="utf-8") as f:
    cfg = json.load(f)

KEY = str(cfg.get("key", ""))
if len(KEY) < 32:
    sys.exit("config: 'key' debe tener al menos 32 caracteres")
TARGETS = cfg.get("targets", [])
INTERVAL = max(5, int(cfg.get("interval", 15)))
TIMEOUT = max(2, int(cfg.get("timeout", 8)))
WINDOW = max(5, int(cfg.get("window", 20)))
HOSTNAME = socket.gethostname()

state = {}          # id -> {"hist": deque[(ok, latency_ms)], "last": {...}}
lock = threading.Lock()


def check_https(t):
    u = urlparse(t["url"])
    name = u.hostname
    port = u.port or 443
    addr = t.get("resolve") or name
    ctx = ssl.create_default_context()
    start = time.monotonic()
    with socket.create_connection((addr, port), timeout=TIMEOUT) as raw:
        with ctx.wrap_socket(raw, server_hostname=name) as s:
            path = u.path or "/"
            s.sendall(f"GET {path} HTTP/1.1\r\nHost: {name}\r\nUser-Agent: musedock-witness/{VERSION}\r\nConnection: close\r\n\r\n".encode())
            data = b""
            while len(data) < 65536:
                chunk = s.recv(4096)
                if not chunk:
                    break
                data += chunk
    ms = int((time.monotonic() - start) * 1000)
    head, _, body = data.partition(b"\r\n\r\n")
    status = head.split(b" ", 2)[1:2]
    code = int(status[0]) if status and status[0].isdigit() else 0
    if code < 200 or code >= 400:
        raise RuntimeError(f"HTTP {code}")
    if t.get("expect") and t["expect"].encode() not in body:
        raise RuntimeError("respuesta inesperada")
    return ms


def check_tcp(t):
    start = time.monotonic()
    with socket.create_connection((t["host"], int(t["port"])), timeout=TIMEOUT):
        pass
    return int((time.monotonic() - start) * 1000)


def run_check(t):
    try:
        ms = check_https(t) if t.get("type") == "https" else check_tcp(t)
        return True, ms, ""
    except Exception as e:  # noqa: BLE001 — cualquier fallo cuenta como "no llega"
        return False, None, str(e)[:200]


def loop():
    while True:
        for t in TARGETS:
            ok, ms, err = run_check(t)
            with lock:
                st = state.setdefault(t["id"], {"hist": deque(maxlen=WINDOW), "last": {}})
                st["hist"].append((ok, ms))
                fails = sum(1 for o, _ in st["hist"] if not o)
                lat = [m for o, m in st["hist"] if o and m is not None]
                st["last"] = {
                    "ok": ok,
                    "latency_ms": ms,
                    "latency_avg_ms": int(sum(lat) / len(lat)) if lat else None,
                    "loss_pct": int(100 * fails / len(st["hist"])),
                    "samples": len(st["hist"]),
                    "checked_at": int(time.time()),
                    "error": err,
                }
        time.sleep(INTERVAL)


class Handler(BaseHTTPRequestHandler):
    server_version = "musedock-witness/" + VERSION
    sys_version = ""

    def log_message(self, *args):
        pass  # sin registros: no guarda nada

    def _send(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        auth = self.headers.get("Authorization", "")
        given = auth[7:].strip() if auth.lower().startswith("bearer ") else ""
        if not given or not hmac.compare_digest(given, KEY):
            return self._send(401, {"ok": False})
        if self.path.split("?")[0] != "/v1/status":
            return self._send(404, {"ok": False})
        with lock:
            targets = {k: dict(v["last"]) for k, v in state.items()}
        self._send(200, {"ok": True, "witness": HOSTNAME, "version": VERSION, "time": int(time.time()),
                         "interval": INTERVAL, "targets": targets})


if __name__ == "__main__":
    threading.Thread(target=loop, daemon=True).start()
    ThreadingHTTPServer((cfg.get("listen", "127.0.0.1"), int(cfg.get("port", 8447))), Handler).serve_forever()
