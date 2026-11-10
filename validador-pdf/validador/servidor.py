"""Servidor HTTP do protocolo v1 (seção 2 da spec da etapa 6), só com a biblioteca padrão.

- `GET /saude` (sem token): situação e quantidade de âncoras; 503 com `ok:false` se uma família está sem raiz.
- `POST /v1/conferir?ancora=icp|govbr|qualquer` (com token): corpo = bytes do PDF.
- `POST /v1/selar` (com token, etapa 8): corpo = PDF final do BlueJus; devolve o PDF selado.
Token Bearer comparado em tempo constante; corpo até 20 MB (413); até 2 operações ao mesmo tempo, fila de 10 s
(503 `ocupado`); 30 s por operação, num processo filho que é morto se passar disso (503 `tempo_esgotado`).
Família de âncora pedida sem raiz carregada: 503 `ancoras_indisponiveis`, antes de ocupar uma vaga.
Até 8 conexões abertas (acima disso a conexão nova é fechada sem resposta) e 15 s para o pedido inteiro chegar
(cabeçalhos e corpo; passou disso, a conexão é fechada sem resposta).
O PDF só existe em memória (e no pipe para o filho): nunca vai para o disco nem para o log. O log tem só rota,
situação, tamanho, tempo e resultado.
"""

from __future__ import annotations

import hmac
import io
import json
import logging
import socket
import threading
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

from . import conferencia, execucao, selo
from .ancoras import ConjuntoDeAncoras, RepositorioDeAncoras
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
        # Teto de fios: cada conexão aberta ocupa um até fechar (e pode guardar até 20 MB de corpo).
        self.conexoes = threading.BoundedSemaphore(configuracao.conexoes_simultaneas)
        self.rotas_post = {'/v1/conferir': atender_conferir, '/v1/selar': atender_selar}
        super().__init__((configuracao.endereco, configuracao.porta), Manipulador)

    def process_request(self, request, client_address):
        # Roda no fio que aceita as conexões: nunca espera. Sem lugar, fecha a conexão nova sem resposta (o
        # cliente vê erro de conexão, passageiro); as que já estão abertas seguem.
        if not self.conexoes.acquire(blocking=False):
            log.warning('conexao recusada: %d conexoes abertas', self.configuracao.conexoes_simultaneas)
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:
            self.conexoes.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.conexoes.release()


class _LeitorComPrazo(io.RawIOBase):
    """Leitura do socket presa ao prazo do pedido inteiro, não só ao de cada leitura.

    O `timeout` do manipulador vale para cada recv: um cliente que manda 1 byte a cada 29 s nunca o estoura. Aqui
    cada recv espera no máximo o que falta do prazo; passou, levanta TimeoutError (a biblioteca padrão fecha a
    conexão). Depois de cada leitura o socket volta ao `timeout` normal, que vale para a escrita da resposta.
    """

    def __init__(self, soquete: socket.socket, manipulador: Manipulador):
        super().__init__()
        self._soquete = soquete
        self._manipulador = manipulador

    def readable(self) -> bool:
        return True

    def readinto(self, destino) -> int:
        restante = self._manipulador.prazo - time.monotonic()
        if restante <= 0:
            raise TimeoutError('prazo do pedido esgotado')
        self._soquete.settimeout(min(restante, self._manipulador.timeout))
        try:
            return self._soquete.recv_into(destino)
        finally:
            self._soquete.settimeout(self._manipulador.timeout)


class Manipulador(BaseHTTPRequestHandler):
    server: ServidorDoValidador
    server_version = 'validador-pdf'
    sys_version = ''
    # Tempo máximo de cada operação de socket; o pedido inteiro tem ainda o prazo `prazo_do_pedido`.
    timeout = 30
    prazo = 0.0

    def setup(self):
        super().setup()
        # Troca o leitor da biblioteca padrão por um que respeita o prazo do pedido (cabeçalhos e corpo).
        self.rfile.close()
        self.rfile = io.BufferedReader(_LeitorComPrazo(self.connection, self))

    def handle_one_request(self):
        # Cada pedido tem o seu prazo, contado desde a espera pela linha do pedido.
        self.prazo = time.monotonic() + self.server.configuracao.prazo_do_pedido
        super().handle_one_request()

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
        # Família sem raiz: 503 (o HEALTHCHECK fica não saudável) até a próxima atualização trazer as âncoras.
        ok = not conjunto.familias_sem_raiz()
        self._enviar_json(
            HTTPStatus.OK if ok else HTTPStatus.SERVICE_UNAVAILABLE,
            {
                'v': 1,
                'ok': ok,
                'ancoras': {**conjunto.raizes_por_familia(), 'atualizadas_em': conjunto.atualizadas_em},
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
        try:
            corpo = self.rfile.read(tamanho)
        except TimeoutError:
            # Corpo lento demais: fecha sem resposta (a biblioteca padrão trata o TimeoutError).
            self.close_connection = True
            log.warning('POST %s conexao fechada: corpo nao chegou no prazo bytes_declarados=%d',
                        urlsplit(self.path).path[:64], tamanho)
            raise
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


def exigir_ancoras(conjunto: ConjuntoDeAncoras, familias: tuple[str, ...]) -> None:
    """503 `ancoras_indisponiveis` (passageiro) se alguma família pedida está sem raiz carregada."""
    vazias = conjunto.familias_sem_raiz(familias)
    if vazias:
        log.warning('ancoras indisponiveis: familia sem raiz carregada: %s', ','.join(vazias))
        raise Resposta(HTTPStatus.SERVICE_UNAVAILABLE, 'ancoras_indisponiveis', {'Retry-After': '60'})


def atender_conferir(manipulador: Manipulador, corpo: bytes, consulta: dict[str, list[str]]) -> tuple[int, str]:
    ancora = (consulta.get('ancora') or [''])[0]
    if ancora not in conferencia.ANCORAS_ACEITAS:
        raise Resposta(HTTPStatus.BAD_REQUEST, 'parametro_invalido')
    if not corpo:
        raise Resposta(HTTPStatus.BAD_REQUEST, 'pdf_ilegivel')
    configuracao = manipulador.server.configuracao
    # O mesmo conjunto que foi conferido aqui vai para o filho (a atualização troca o conjunto inteiro).
    conjunto = manipulador.server.ancoras.conjunto
    exigir_ancoras(conjunto, conferencia.FAMILIAS_DA_ANCORA[ancora])
    try:
        resultado = manipulador.executar_na_fila(
            conferencia.conferir,
            (corpo, ancora, conjunto, configuracao.revogacao),
        )
    except execucao.ErroConhecido as erro:
        codigo = str(erro)
        if codigo == 'memoria_esgotada':
            raise Resposta(HTTPStatus.BAD_REQUEST, 'pdf_ilegivel') from None
        raise Resposta(HTTPStatus.BAD_REQUEST, codigo) from None
    manipulador._enviar_json(HTTPStatus.OK, resultado)
    confiaveis = sum(1 for a in resultado['assinaturas'] if a['confiavel'])
    return HTTPStatus.OK, f'assinaturas={len(resultado["assinaturas"])} confiaveis={confiaveis}'


# Situação HTTP de cada código que o selo devolve (protocolo da seção 2 da spec da etapa 8).
SITUACAO_DO_SELO = {
    'nao_selavel': HTTPStatus.UNPROCESSABLE_ENTITY,
    'selo_nao_configurado': HTTPStatus.SERVICE_UNAVAILABLE,
    'selo_certificado_invalido': HTTPStatus.SERVICE_UNAVAILABLE,
    'carimbo_indisponivel': HTTPStatus.BAD_GATEWAY,
    'pdf_ilegivel': HTTPStatus.BAD_REQUEST,
}


def _erro_do_selo(codigo: str) -> Resposta:
    # Os dois 503 do selo pedem ações diferentes: o log separa um do outro (E8-4).
    if codigo == 'selo_certificado_invalido':
        log.error('selo com certificado invalido: senha errada, PKCS#12 ilegivel, e-CNPJ vencido, revogado ou fora '
                  'das ancoras ICP-Brasil')
    return Resposta(SITUACAO_DO_SELO.get(codigo, HTTPStatus.INTERNAL_SERVER_ERROR), codigo)


def atender_selar(manipulador: Manipulador, corpo: bytes, consulta: dict[str, list[str]]) -> tuple[int, str]:
    configuracao = manipulador.server.configuracao
    try:
        credencial = selo.ler_credencial(configuracao)
    except execucao.ErroConhecido as erro:
        raise _erro_do_selo(str(erro)) from None
    if credencial is None:
        log.warning('selo nao configurado: secrets do selo ausentes ou vazios')
        raise Resposta(HTTPStatus.SERVICE_UNAVAILABLE, 'selo_nao_configurado')
    if not corpo:
        raise Resposta(HTTPStatus.BAD_REQUEST, 'pdf_ilegivel')
    conjunto = manipulador.server.ancoras.conjunto
    exigir_ancoras(conjunto, ('icp',))
    pkcs12, senha = credencial
    try:
        selado = manipulador.executar_na_fila(
            selo.selar,
            (corpo, pkcs12, senha, configuracao.selo_url_da_act, conjunto, configuracao.revogacao),
        )
    except execucao.ErroConhecido as erro:
        codigo = str(erro)
        if codigo == 'memoria_esgotada':
            codigo = 'pdf_ilegivel'
        raise _erro_do_selo(codigo) from None
    manipulador._enviar(HTTPStatus.OK, selado, 'application/pdf')
    return HTTPStatus.OK, f'selado saida={len(selado)}'
