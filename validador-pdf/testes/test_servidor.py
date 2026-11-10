"""Protocolo HTTP v1 de ponta a ponta: servidor de verdade em 127.0.0.1, conferência no processo filho."""

from __future__ import annotations

import http.client
import json
import logging
import select
import socket
import time
from dataclasses import replace

import pytest

from validador import conferencia, execucao
from validador.ancoras import ConjuntoDeAncoras
from validador.configuracao import TETO_DO_PDF, Configuracao, Revogacao

from . import filho, pdfs, pki
from .cliente import TOKEN, esperar_no_log, pedir, pedir_json, servidor_no_ar


@pytest.fixture(scope='module', autouse=True)
def forkserver():
    execucao.preparar()


@pytest.fixture(scope='module')
def icp():
    raiz = pki.criar_raiz('AC Raiz Teste ICP')
    ac = raiz.emitir_ac('AC Teste ICP')
    folha = ac.emitir_folha('FULANO DE TAL:12345678909', cpf='12345678909')
    return raiz, ac, folha


@pytest.fixture(scope='module')
def configuracao_base(icp, tmp_path_factory):
    _, ac, _ = icp
    return Configuracao(
        token=TOKEN.encode(),
        endereco='127.0.0.1',
        porta=0,
        diretorio_de_dados=str(tmp_path_factory.mktemp('dados')),
        atualizar_ancoras=False,
        revogacao=Revogacao(buscar_na_rede=False, lcrs=(ac.lcr(),)),
    )


@pytest.fixture(scope='module')
def raiz_govbr():
    return pki.criar_raiz('AC Raiz Teste gov.br')


@pytest.fixture(scope='module')
def conjunto(icp, raiz_govbr):
    raiz, ac, _ = icp
    return ConjuntoDeAncoras(
        icp_raizes=(raiz.der,),
        icp_intermediarias=(ac.der,),
        govbr_raizes=(raiz_govbr.der,),
        atualizadas_em='2026-11-09T12:00:00+00:00',
    )


@pytest.fixture(scope='module')
def servidor(configuracao_base, conjunto):
    with servidor_no_ar(configuracao_base, conjunto) as rodando:
        yield rodando


def test_saude_sem_token(servidor):
    situacao, dados, resposta = pedir_json(servidor, 'GET', '/saude', token=None)

    assert situacao == 200
    assert resposta.getheader('Content-Type') == 'application/json'
    assert dados == {'v': 1, 'ok': True, 'ancoras': {'icp': 1, 'govbr': 1, 'atualizadas_em': '2026-11-09T12:00:00+00:00'}}


@pytest.mark.parametrize('carregadas', ['nenhuma', 'so_icp', 'so_govbr'])
def test_saude_com_familia_sem_raiz_e_503(configuracao_base, conjunto, carregadas):
    parcial = {
        'nenhuma': ConjuntoDeAncoras(),
        'so_icp': replace(conjunto, govbr_raizes=()),
        'so_govbr': replace(conjunto, icp_raizes=()),
    }[carregadas]

    with servidor_no_ar(configuracao_base, parcial) as servidor:
        situacao, dados, _ = pedir_json(servidor, 'GET', '/saude', token=None)

    assert situacao == 503
    assert dados['ok'] is False
    assert (dados['ancoras']['icp'], dados['ancoras']['govbr']) == {
        'nenhuma': (0, 0), 'so_icp': (1, 0), 'so_govbr': (0, 1)
    }[carregadas]


@pytest.mark.parametrize(
    ('ancora', 'carregadas'),
    [('icp', 'so_govbr'), ('icp', 'nenhuma'), ('govbr', 'so_icp'), ('qualquer', 'so_icp'), ('qualquer', 'so_govbr')],
)
def test_familia_pedida_sem_raiz_e_503_ancoras_indisponiveis(configuracao_base, conjunto, icp, ancora, carregadas):
    # Volume vazio na primeira subida, volume perdido ou ITI fora do ar: a assinatura legítima sairia
    # "cadeia_nao_confiavel" e o PHP gravaria recusa. O validador responde passageiro, sem ocupar vaga.
    _, ac, folha = icp
    parcial = {
        'nenhuma': ConjuntoDeAncoras(),
        'so_icp': replace(conjunto, govbr_raizes=()),
        'so_govbr': replace(conjunto, icp_raizes=()),
    }[carregadas]
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac])

    with servidor_no_ar(replace(configuracao_base, simultaneas=1, espera_na_fila=0.2), parcial) as servidor:
        servidor.vagas.acquire()  # sem vaga: se a resposta dependesse da fila, seria "ocupado"
        try:
            situacao, dados, resposta = pedir_json(servidor, 'POST', f'/v1/conferir?ancora={ancora}', pdf)
        finally:
            servidor.vagas.release()

    assert (situacao, dados) == (503, {'v': 1, 'erro': 'ancoras_indisponiveis'})
    assert resposta.getheader('Retry-After') == '60'


def test_familia_pedida_com_raiz_confere_mesmo_com_a_outra_vazia(configuracao_base, conjunto, icp):
    _, ac, folha = icp
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac])

    with servidor_no_ar(configuracao_base, replace(conjunto, govbr_raizes=())) as servidor:
        situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdf)

    assert situacao == 200
    assert dados['assinaturas'][0]['confiavel'] is True


def test_conferir_devolve_o_json_v1(servidor, icp):
    _, ac, folha = icp
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac])

    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdf)

    assert situacao == 200
    assert dados['v'] == 1
    [item] = dados['assinaturas']
    assert (item['intacta'], item['valida'], item['confiavel'], item['ancora']) == (True, True, True, 'icp')
    assert item['titular']['cpf'] == '12345678909'
    assert item['revogacao'] == 'boa'


@pytest.mark.parametrize('token', [None, '', 'errado', TOKEN + 'x', TOKEN[:-1], 'Basic ' + TOKEN])
def test_token_ausente_ou_errado_e_401(servidor, token):
    cabecalhos = {}
    if token is not None and token.startswith('Basic '):
        cabecalhos, token = {'Authorization': token}, None
    situacao, dados, resposta = pedir_json(
        servidor, 'POST', '/v1/conferir?ancora=icp', b'%PDF', token=token, cabecalhos=cabecalhos
    )

    assert situacao == 401
    assert dados == {'v': 1, 'erro': 'nao_autorizado'}
    assert resposta.getheader('WWW-Authenticate') == 'Bearer'


def test_sem_token_configurado_tudo_e_401(configuracao_base, conjunto):
    with servidor_no_ar(replace(configuracao_base, token=b''), conjunto) as servidor:
        situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', b'%PDF', token='')
        saude, _, _ = pedir_json(servidor, 'GET', '/saude', token=None)

    assert (situacao, dados['erro']) == (401, 'nao_autorizado')
    assert saude == 200


def test_corpo_acima_de_20_mb_e_413_sem_ler_o_corpo(servidor):
    conexao = http.client.HTTPConnection('127.0.0.1', servidor.server_address[1], timeout=30)
    conexao.putrequest('POST', '/v1/conferir?ancora=icp')
    conexao.putheader('Authorization', f'Bearer {TOKEN}')
    conexao.putheader('Content-Type', 'application/pdf')
    conexao.putheader('Content-Length', str(TETO_DO_PDF + 1))
    conexao.endheaders()  # nenhum byte do corpo é enviado: o servidor responde só pelo cabeçalho

    resposta = conexao.getresponse()

    assert TETO_DO_PDF == 20 * 1024 * 1024
    assert resposta.status == 413
    assert json.loads(resposta.read()) == {'v': 1, 'erro': 'pdf_grande_demais'}
    conexao.close()


def test_corpo_de_exatos_20_mb_passa_do_limite_de_tamanho(servidor):
    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', b'0' * TETO_DO_PDF)

    assert (situacao, dados['erro']) == (400, 'pdf_ilegivel')


@pytest.mark.parametrize('corpo', [b'isto nao e um pdf', b'%PDF-1.7\n1 0 obj << /Type /Catalog', b''])
def test_pdf_ilegivel_e_400(servidor, corpo):
    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', corpo)

    assert (situacao, dados) == (400, {'v': 1, 'erro': 'pdf_ilegivel'})


@pytest.mark.parametrize('consulta', ['', '?ancora=', '?ancora=sistema', '?ancora=ICP'])
def test_ancora_invalida_e_400(servidor, consulta):
    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir' + consulta, pdfs.pdf_simples())

    assert (situacao, dados) == (400, {'v': 1, 'erro': 'parametro_invalido'})


def test_rota_desconhecida_e_404(servidor):
    situacao, dados, _ = pedir_json(servidor, 'POST', '/v2/conferir', b'%PDF')
    no_get, dados_do_get, _ = pedir_json(servidor, 'GET', '/v1/conferir', token=None)

    assert (situacao, dados['erro']) == (404, 'nao_encontrado')
    assert (no_get, dados_do_get) == (404, {'v': 1, 'erro': 'nao_encontrado'})


@pytest.mark.parametrize('metodo', ['PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE', 'INVENTADO'])
def test_metodo_fora_de_get_e_post_e_405_em_json(servidor, metodo, caplog):
    with caplog.at_level(logging.INFO, logger='validador.servidor'):
        situacao, dados, resposta = pedir_json(servidor, metodo, '/v1/conferir?ancora=icp', b'%PDF')
        esperar_no_log(caplog, f'{metodo} /v1/conferir 405 ')

    assert (situacao, dados) == (405, {'v': 1, 'erro': 'metodo_nao_permitido'})
    assert resposta.getheader('Content-Type') == 'application/json'
    assert resposta.getheader('Allow') == 'GET, POST'
    assert f'{metodo} /v1/conferir 405 bytes=0' in caplog.text


@pytest.mark.parametrize('caminho', ['/saude', '/v1/conferir?ancora=icp'])
def test_head_e_405_sem_corpo(servidor, caminho):
    resposta, corpo = pedir(servidor, 'HEAD', caminho, token=None)

    assert resposta.status == 405  # antes: 501 em HTML da biblioteca padrão
    assert resposta.getheader('Content-Type') == 'application/json'
    assert resposta.getheader('Allow') == 'GET, POST'
    assert int(resposta.getheader('Content-Length')) == len('{"v":1,"erro":"metodo_nao_permitido"}')
    assert corpo == b''


@pytest.mark.parametrize('cabecalho', ['transfer_encoding', 'sem_content_length'])
def test_corpo_sem_tamanho_declarado_e_411(servidor, cabecalho):
    conexao = http.client.HTTPConnection('127.0.0.1', servidor.server_address[1], timeout=30)
    conexao.putrequest('POST', '/v1/conferir?ancora=icp')
    conexao.putheader('Authorization', f'Bearer {TOKEN}')
    conexao.putheader('Content-Type', 'application/pdf')
    if cabecalho == 'transfer_encoding':
        conexao.putheader('Transfer-Encoding', 'chunked')
    conexao.endheaders()  # nenhum Content-Length

    resposta = conexao.getresponse()

    assert resposta.status == 411
    assert json.loads(resposta.read()) == {'v': 1, 'erro': 'tamanho_ausente'}
    conexao.close()


def test_falha_no_filho_e_500_erro_interno(servidor, monkeypatch, caplog):
    # O filho recebe a função por referência: trocar `conferencia.conferir` aqui troca o que ele roda.
    respostas = []
    pdf = pdfs.pdf_simples()
    # Exceção no filho; filho que sai sem responder; filho morto por sinal (como o OOM killer).
    with caplog.at_level(logging.INFO):
        for funcao in (filho.falhar, filho.morrer, filho.morrer_por_sinal):
            monkeypatch.setattr(conferencia, 'conferir', funcao)
            respostas.append(pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdf)[:2])
        # O servidor segue atendendo depois de o filho cair.
        monkeypatch.undo()
        saude, _, _ = pedir_json(servidor, 'GET', '/saude', token=None)
        esperar_no_log(caplog, 'resultado=erro_interno')

    assert respostas == [(500, {'v': 1, 'erro': 'erro_interno'})] * 3
    erros = [r.getMessage() for r in caplog.records if r.name == 'validador.execucao']
    assert erros == [
        'falha no processo filho: RuntimeError',
        'falha no processo filho: sem_resposta saida=3',
        'falha no processo filho: sem_resposta sinal=SIGKILL',
    ]
    assert caplog.text.count('POST /v1/conferir 500 ') == 3
    for proibido in ('Contrato', TOKEN):
        assert proibido not in caplog.text
    assert saude == 200


def test_memoria_esgotada_no_filho_e_400_pdf_ilegivel(servidor, monkeypatch):
    monkeypatch.setattr(conferencia, 'conferir', filho.estourar_memoria)

    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdfs.pdf_simples())

    assert (situacao, dados) == (400, {'v': 1, 'erro': 'pdf_ilegivel'})


def _esperar(condicao, limite: float = 5.0) -> bool:
    fim = time.monotonic() + limite
    while not condicao():
        if time.monotonic() > fim:
            return False
        time.sleep(0.02)
    return True


def _fechada_pelo_servidor(soquete: socket.socket, gota: bytes | None, limite: float) -> float | None:
    """Manda `gota` a cada 0,1 s (ou nada) até o servidor fechar; devolve quanto tempo levou (None = não fechou)."""
    inicio = time.monotonic()
    while time.monotonic() - inicio < limite:
        try:
            if gota is not None:
                soquete.sendall(gota)
            if select.select([soquete], [], [], 0.1)[0] and not soquete.recv(4096):
                return time.monotonic() - inicio
        except OSError:  # conexão desfeita pelo servidor
            return time.monotonic() - inicio
    return None


def test_teto_de_conexoes_fecha_a_nova_sem_derrubar_as_abertas(configuracao_base, conjunto, caplog):
    configuracao = replace(configuracao_base, conexoes_simultaneas=2)
    with caplog.at_level(logging.WARNING, logger='validador.servidor'), \
            servidor_no_ar(configuracao, conjunto) as servidor:
        porta = servidor.server_address[1]
        abertas = [socket.create_connection(('127.0.0.1', porta), timeout=5) for _ in range(2)]
        try:
            assert _esperar(lambda: servidor.conexoes._value == 0)  # as duas já têm fio
            excedente = socket.create_connection(('127.0.0.1', porta), timeout=5)
            with excedente:
                assert _fechada_pelo_servidor(excedente, None, 3.0) is not None
        finally:
            for aberta in abertas:
                aberta.close()
        assert _esperar(lambda: servidor.conexoes._value == 2)  # os fios devolvem o lugar ao terminar
        depois, _, _ = pedir_json(servidor, 'GET', '/saude', token=None)
        esperar_no_log(caplog, 'conexao recusada: 2 conexoes abertas')

    assert depois == 200
    assert 'conexao recusada: 2 conexoes abertas' in caplog.text


def test_prazo_do_pedido_fecha_quem_manda_cabecalho_aos_poucos(configuracao_base, conjunto):
    # Cada byte chega bem antes do tempo de 30 s do socket; só o prazo do pedido inteiro fecha a conexão.
    with servidor_no_ar(replace(configuracao_base, prazo_do_pedido=0.5), conjunto) as servidor:
        with socket.create_connection(('127.0.0.1', servidor.server_address[1]), timeout=5) as lento:
            lento.sendall(b'POST /v1/conferir?ancora=icp HTTP/1.1\r\nX-Lento: ')
            levou = _fechada_pelo_servidor(lento, b'a', 5.0)

    assert levou is not None and levou < 3.0


def test_prazo_do_pedido_fecha_quem_manda_o_corpo_aos_poucos(configuracao_base, conjunto, caplog):
    with caplog.at_level(logging.WARNING, logger='validador.servidor'), \
            servidor_no_ar(replace(configuracao_base, prazo_do_pedido=0.5), conjunto) as servidor:
        with socket.create_connection(('127.0.0.1', servidor.server_address[1]), timeout=5) as lento:
            lento.sendall(
                b'POST /v1/conferir?ancora=icp HTTP/1.1\r\nHost: validador\r\n'
                b'Authorization: Bearer ' + TOKEN.encode() + b'\r\nContent-Length: 1000\r\n\r\n%PDF'
            )
            levou = _fechada_pelo_servidor(lento, b'a', 5.0)
        esperar_no_log(caplog, 'corpo nao chegou no prazo')

    assert levou is not None and levou < 3.0
    assert 'POST /v1/conferir conexao fechada: corpo nao chegou no prazo bytes_declarados=1000' in caplog.text


def test_sem_vaga_depois_da_fila_e_503_ocupado(configuracao_base, conjunto):
    configuracao = replace(configuracao_base, simultaneas=1, espera_na_fila=0.2)
    with servidor_no_ar(configuracao, conjunto) as servidor:
        servidor.vagas.acquire()  # a única vaga está tomada por outra conferência
        try:
            situacao, dados, resposta = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdfs.pdf_simples())
        finally:
            servidor.vagas.release()
        depois, _, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdfs.pdf_simples())

    assert (situacao, dados) == (503, {'v': 1, 'erro': 'ocupado'})
    assert resposta.getheader('Retry-After') == '10'
    assert depois == 200


def test_conferencia_que_passa_do_tempo_maximo_e_interrompida(configuracao_base, conjunto):
    with servidor_no_ar(replace(configuracao_base, tempo_maximo=0.001), conjunto) as servidor:
        situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdfs.pdf_simples())
        # A vaga foi devolvida: o servidor continua atendendo.
        saude, _, _ = pedir_json(servidor, 'GET', '/saude', token=None)

    assert (situacao, dados) == (503, {'v': 1, 'erro': 'tempo_esgotado'})
    assert saude == 200


def test_log_tem_so_rota_situacao_tamanho_e_tempo(servidor, icp, caplog):
    _, ac, folha = icp
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac])

    esperadas = ('POST /v1/conferir 200 bytes=%d' % len(pdf), 'POST /v1/conferir 400 bytes=23')
    with caplog.at_level(logging.DEBUG):
        pedir(servidor, 'POST', '/v1/conferir?ancora=icp', pdf)
        pedir(servidor, 'POST', '/v1/conferir?ancora=icp', b'conteudo secreto do pdf')
        esperar_no_log(caplog, *esperadas)

    texto = caplog.text
    assert esperadas[0] in texto
    assert esperadas[1] in texto
    for proibido in ('12345678909', 'FULANO', 'secreto', 'Contrato', TOKEN):
        assert proibido not in texto
