"""ACT (TSA) de TESTE: servidor RFC 3161 em 127.0.0.1, assinado pelo DummyTimeStamper do pyHanko."""

from __future__ import annotations

import socket
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from asn1crypto import tsp
from pyhanko.sign.timestamps.dummy_client import DummyTimeStamper

from .pki import Entidade


class ActLocal:
    def __init__(self, act: Entidade):
        carimbador = DummyTimeStamper(tsa_cert=act.asn1, tsa_key=act.chave_asn1)

        class Manipulador(BaseHTTPRequestHandler):
            def do_POST(self):  # noqa: N802
                pedido = tsp.TimeStampReq.load(self.rfile.read(int(self.headers['Content-Length'])))
                corpo = carimbador.request_tsa_response(pedido).dump()
                self.send_response(200)
                self.send_header('Content-Type', 'application/timestamp-reply')
                self.send_header('Content-Length', str(len(corpo)))
                self.end_headers()
                self.wfile.write(corpo)

            def log_message(self, *_):
                return

        self._servidor = ThreadingHTTPServer(('127.0.0.1', 0), Manipulador)
        self.url = f'http://127.0.0.1:{self._servidor.server_address[1]}/'

    def __enter__(self) -> ActLocal:
        threading.Thread(target=self._servidor.serve_forever, daemon=True).start()
        return self

    def __exit__(self, *_):
        self._servidor.shutdown()
        self._servidor.server_close()


def url_que_nao_responde() -> str:
    """Porta local que acabou de ser liberada: a conexão é recusada na hora."""
    with socket.socket() as soquete:
        soquete.bind(('127.0.0.1', 0))
        porta = soquete.getsockname()[1]
    return f'http://127.0.0.1:{porta}/'
