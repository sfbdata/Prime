"""LCR de TESTE servida em 127.0.0.1, para conferir a busca de revogação na rede sem sair da máquina."""

from __future__ import annotations

import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class LcrLocal:
    """Serve sempre a mesma LCR (DER) e conta os pedidos recebidos."""

    def __init__(self, lcr: bytes):
        self.pedidos: list[str] = []
        pedidos = self.pedidos

        class Manipulador(BaseHTTPRequestHandler):
            def do_GET(self):  # noqa: N802
                pedidos.append(self.path)
                self.send_response(200)
                self.send_header('Content-Type', 'application/pkix-crl')
                self.send_header('Content-Length', str(len(lcr)))
                self.end_headers()
                self.wfile.write(lcr)

            def log_message(self, *_):
                return

        self._servidor = ThreadingHTTPServer(('127.0.0.1', 0), Manipulador)
        self.url = f'http://127.0.0.1:{self._servidor.server_address[1]}/ac-teste.crl'

    def __enter__(self) -> LcrLocal:
        threading.Thread(target=self._servidor.serve_forever, daemon=True).start()
        return self

    def __exit__(self, *_):
        self._servidor.shutdown()
        self._servidor.server_close()
