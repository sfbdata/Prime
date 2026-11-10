"""Servidor de verdade em 127.0.0.1 (porta livre) e cliente HTTP para os testes do protocolo."""

from __future__ import annotations

import http.client
import json
import threading
import time
from contextlib import contextmanager

from validador.ancoras import ConjuntoDeAncoras, RepositorioDeAncoras
from validador.configuracao import Configuracao
from validador.servidor import ServidorDoValidador

TOKEN = 'token-de-teste-com-tamanho-razoavel'


@contextmanager
def servidor_no_ar(configuracao: Configuracao, conjunto: ConjuntoDeAncoras | None = None):
    ancoras = RepositorioDeAncoras(configuracao)  # sem iniciar(): nada é baixado no teste
    if conjunto is not None:
        ancoras.definir(conjunto)
    servidor = ServidorDoValidador(configuracao, ancoras)
    fio = threading.Thread(target=servidor.serve_forever, daemon=True)
    fio.start()
    try:
        yield servidor
    finally:
        servidor.shutdown()
        servidor.server_close()


def pedir(servidor, metodo, caminho, corpo=None, token=TOKEN, cabecalhos=None):
    conexao = http.client.HTTPConnection('127.0.0.1', servidor.server_address[1], timeout=60)
    todos = {'Content-Type': 'application/pdf'}
    if token is not None:
        todos['Authorization'] = f'Bearer {token}'
    todos.update(cabecalhos or {})
    conexao.request(metodo, caminho, body=corpo, headers=todos)
    resposta = conexao.getresponse()
    dados = resposta.read()
    conexao.close()
    return resposta, dados


def esperar_no_log(caplog, *linhas: str, limite: float = 5.0) -> None:
    """O servidor escreve a linha do log depois de mandar a resposta, no fio do pedido: o cliente pode ler a
    resposta antes de a linha sair. Chame ainda dentro do `caplog.at_level(...)`."""
    fim = time.monotonic() + limite
    while not all(linha in caplog.text for linha in linhas) and time.monotonic() < fim:
        time.sleep(0.02)


def pedir_json(servidor, metodo, caminho, corpo=None, **kwargs):
    resposta, dados = pedir(servidor, metodo, caminho, corpo, **kwargs)
    return resposta.status, json.loads(dados), resposta
