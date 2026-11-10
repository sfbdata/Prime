"""Servidor HTTP do protocolo v1 (seção 2 da spec da etapa 6), só com a biblioteca padrão.

- `GET /saude` (sem token): situação e quantidade de âncoras.
- `POST /v1/conferir?ancora=icp|govbr|qualquer` (com token): corpo = bytes do PDF.
Token Bearer comparado em tempo constante; corpo até 20 MB (413); até 2 operações ao mesmo tempo, fila de 10 s
(503 `ocupado`); 30 s por operação, num processo filho que é morto se passar disso (503 `tempo_esgotado`).
O PDF só existe em memória (e no pipe para o filho): nunca vai para o disco nem para o log. O log tem só rota,
situação, tamanho, tempo e resultado.
"""

from __future__ import annotations

import hmac
import json
import logging
import threading
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

from . import conferencia, execucao
from .ancoras import RepositorioDeAncoras
from .configuracao import Configuracao

log = logging.getLogger('validador.servidor')


class Resposta(Exception):
    """Interrompe o atendimento com uma resposta JSON pronta."""

    def __init__(self, situacao: int, erro: str | None = None, cabecalhos: dict[str, str] | None = None):
        super().__init__(erro)
        self.situacao = situacao
        self.erro = erro
        self.cabecalhos = cabecalhos or {}


class ServidorDoValidador(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, configuracao: Configuracao, ancoras: RepositorioDeAncoras):
        self.configuracao = configuracao
        self.ancoras = ancoras
        self.vagas = threading.BoundedSemaphore(configuracao.simultaneas)
        self.rotas_post = {'/v1/conferir': atender_conferir}
        super().__init__((configuracao.endereco, configuracao.porta), Manipulador)


class Manipulador(BaseHTTPRequestHandler):
    server: ServidorDoValidador
    server_version = 'validador-pdf'
    sys_version = ''
    # Tempo máximo para ler o corpo e para cada operação de socket.
    timeout = 30

    # Respostas ------------------------------------------------------------------------------------------

    def _enviar(self, situacao: int, corpo: bytes, tipo: str, cabecalhos: dict[str, str] | None = None) -> None:
        self.send_response(situacao)
        self.send_header('Content-Type', tipo)
        self.send_header('Content-Length', str(len(corpo)))
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Content-Type-Options', 'nosniff')
        for nome, valor in (cabecalhos or {}).items():
            self.send_header(nome, valor)
        self.end_headers()
        self.wfile.write(corpo)

    def _enviar_json(self, situacao: int, dados: dict, cabecalhos: dict[str, str] | None = None) -> None:
        corpo = json.dumps(dados, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
        self._enviar(situacao, corpo, 'application/json', cabecalhos)

    def _enviar_erro(self, resposta: Resposta) -> None:
        self._enviar_json(resposta.situacao, {'v': 1, 'erro': resposta.erro}, resposta.cabecalhos)

    def log_message(self, formato, *argumentos):  # noqa: D401 (o log padrão traz o caminho; o nosso é outro)
        return

    def _registrar(self, situacao: int, inicio: float, tamanho: int, resultado: str) -> None:
        rota = urlsplit(self.path).path if self.path else '-'
        log.info(
            '%s %s %d bytes=%d ms=%d resultado=%s',
            self.command,
            rota[:64],
            situacao,
            tamanho,
            int((time.monotonic() - inicio) * 1000),
            resultado,
        )

    # Rotas ----------------------------------------------------------------------------------------------

    def do_GET(self):  # noqa: N802 (nome da biblioteca padrão)
        inicio = time.monotonic()
        if urlsplit(self.path).path != '/saude':
            self._enviar_erro(Resposta(HTTPStatus.NOT_FOUND, 'nao_encontrado'))
            self._registrar(404, inicio, 0, 'nao_encontrado')
            return
        conjunto = self.server.ancoras.conjunto
        self._enviar_json(
            HTTPStatus.OK,
            {
                'v': 1,
                'ok': True,
                'ancoras': {
                    'icp': len(conjunto.icp_raizes),
                    'govbr': len(conjunto.govbr_raizes),
                    'atualizadas_em': conjunto.atualizadas_em,
                },
            },
        )

    def do_POST(self):  # noqa: N802
        inicio = time.monotonic()
        partes = urlsplit(self.path)
        rota = self.server.rotas_post.get(partes.path)
        tamanho = 0
        try:
            if rota is None:
                raise Resposta(HTTPStatus.NOT_FOUND, 'nao_encontrado')
            self._autenticar()
            corpo = self._ler_corpo()
            tamanho = len(corpo)
            situacao, resultado = rota(self, corpo, parse_qs(partes.query))
        except Resposta as resposta:
            if resposta.situacao in (HTTPStatus.UNAUTHORIZED, HTTPStatus.REQUEST_ENTITY_TOO_LARGE,
                                     HTTPStatus.LENGTH_REQUIRED, HTTPStatus.NOT_FOUND):
                # O corpo não foi lido: fecha a conexão para não interpretar o resto como outra requisição.
                self.close_connection = True
            self._enviar_erro(resposta)
            self._registrar(resposta.situacao, inicio, tamanho, resposta.erro or '-')
            return
        self._registrar(situacao, inicio, tamanho, resultado)

    def do_PUT(self):  # noqa: N802
        self._metodo_nao_permitido()

    do_DELETE = do_PATCH = do_PUT

    def _metodo_nao_permitido(self):
        self.close_connection = True
        self._enviar_erro(Resposta(HTTPStatus.METHOD_NOT_ALLOWED, 'metodo_nao_permitido'))

    # Etapas comuns --------------------------------------------------------------------------------------

    def _autenticar(self) -> None:
        esperado = self.server.configuracao.token
        enviado = self.headers.get('Authorization', '')
        prefixo = 'Bearer '
        token = enviado[len(prefixo):].strip().encode('utf-8', 'replace') if enviado.startswith(prefixo) else b''
        # compare_digest roda sempre, mesmo com token vazio, para o tempo não denunciar nada.
        confere = hmac.compare_digest(token, esperado)
        if not esperado or not confere:
            raise Resposta(HTTPStatus.UNAUTHORIZED, 'nao_autorizado', {'WWW-Authenticate': 'Bearer'})

    def _ler_corpo(self) -> bytes:
        if self.headers.get('Transfer-Encoding'):
            raise Resposta(HTTPStatus.LENGTH_REQUIRED, 'tamanho_ausente')
        declarado = self.headers.get('Content-Length')
        try:
            tamanho = int(declarado) if declarado is not None else -1
        except ValueError:
            tamanho = -1
        if tamanho < 0:
            raise Resposta(HTTPStatus.LENGTH_REQUIRED, 'tamanho_ausente')
        if tamanho > self.server.configuracao.teto_do_pdf:
            raise Resposta(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, 'pdf_grande_demais')
        corpo = self.rfile.read(tamanho)
        if len(corpo) != tamanho:
            self.close_connection = True
            raise Resposta(HTTPStatus.BAD_REQUEST, 'pdf_ilegivel')
        return corpo

    def executar_na_fila(self, funcao, argumentos: tuple):
        """Espera uma vaga (fila de 10 s) e roda no processo filho com o tempo máximo."""
        configuracao = self.server.configuracao
        if not self.server.vagas.acquire(timeout=configuracao.espera_na_fila):
            raise Resposta(HTTPStatus.SERVICE_UNAVAILABLE, 'ocupado', {'Retry-After': '10'})
        try:
            return execucao.executar(funcao, argumentos, configuracao.tempo_maximo)
        except execucao.TempoEsgotado:
            raise Resposta(HTTPStatus.SERVICE_UNAVAILABLE, 'tempo_esgotado') from None
        except execucao.FalhaNoFilho:
            raise Resposta(HTTPStatus.INTERNAL_SERVER_ERROR, 'erro_interno') from None
        finally:
            self.server.vagas.release()


def atender_conferir(manipulador: Manipulador, corpo: bytes, consulta: dict[str, list[str]]) -> tuple[int, str]:
    ancora = (consulta.get('ancora') or [''])[0]
    if ancora not in conferencia.ANCORAS_ACEITAS:
        raise Resposta(HTTPStatus.BAD_REQUEST, 'parametro_invalido')
    if not corpo:
        raise Resposta(HTTPStatus.BAD_REQUEST, 'pdf_ilegivel')
    configuracao = manipulador.server.configuracao
    try:
        resultado = manipulador.executar_na_fila(
            conferencia.conferir,
            (corpo, ancora, manipulador.server.ancoras.conjunto, configuracao.revogacao),
        )
    except execucao.ErroConhecido as erro:
        codigo = str(erro)
        if codigo == 'memoria_esgotada':
            raise Resposta(HTTPStatus.BAD_REQUEST, 'pdf_ilegivel') from None
        raise Resposta(HTTPStatus.BAD_REQUEST, codigo) from None
    manipulador._enviar_json(HTTPStatus.OK, resultado)
    confiaveis = sum(1 for a in resultado['assinaturas'] if a['confiavel'])
    return HTTPStatus.OK, f'assinaturas={len(resultado["assinaturas"])} confiaveis={confiaveis}'
