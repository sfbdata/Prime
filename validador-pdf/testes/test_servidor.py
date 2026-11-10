"""Protocolo HTTP v1 de ponta a ponta: servidor de verdade em 127.0.0.1, conferência no processo filho."""

from __future__ import annotations

import http.client
import json
import logging
from dataclasses import replace

import pytest

from validador import execucao
from validador.ancoras import ConjuntoDeAncoras
from validador.configuracao import TETO_DO_PDF, Configuracao, Revogacao

from . import pdfs, pki
from .cliente import TOKEN, pedir, pedir_json, servidor_no_ar


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
def servidor(configuracao_base, icp):
    raiz, ac, _ = icp
    conjunto = ConjuntoDeAncoras(
        icp_raizes=(raiz.der,), icp_intermediarias=(ac.der,), atualizadas_em='2026-11-09T12:00:00+00:00'
    )
    with servidor_no_ar(configuracao_base, conjunto) as rodando:
        yield rodando


def test_saude_sem_token(servidor):
    situacao, dados, resposta = pedir_json(servidor, 'GET', '/saude', token=None)

    assert situacao == 200
    assert resposta.getheader('Content-Type') == 'application/json'
    assert dados == {'v': 1, 'ok': True, 'ancoras': {'icp': 1, 'govbr': 0, 'atualizadas_em': '2026-11-09T12:00:00+00:00'}}


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


def test_sem_token_configurado_tudo_e_401(configuracao_base):
    with servidor_no_ar(replace(configuracao_base, token=b'')) as servidor:
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

    assert (situacao, dados['erro']) == (404, 'nao_encontrado')


def test_sem_vaga_depois_da_fila_e_503_ocupado(configuracao_base):
    configuracao = replace(configuracao_base, simultaneas=1, espera_na_fila=0.2)
    with servidor_no_ar(configuracao) as servidor:
        servidor.vagas.acquire()  # a única vaga está tomada por outra conferência
        try:
            situacao, dados, resposta = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdfs.pdf_simples())
        finally:
            servidor.vagas.release()
        depois, _, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdfs.pdf_simples())

    assert (situacao, dados) == (503, {'v': 1, 'erro': 'ocupado'})
    assert resposta.getheader('Retry-After') == '10'
    assert depois == 200


def test_conferencia_que_passa_do_tempo_maximo_e_interrompida(configuracao_base):
    with servidor_no_ar(replace(configuracao_base, tempo_maximo=0.001)) as servidor:
        situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', pdfs.pdf_simples())
        # A vaga foi devolvida: o servidor continua atendendo.
        saude, _, _ = pedir_json(servidor, 'GET', '/saude', token=None)

    assert (situacao, dados) == (503, {'v': 1, 'erro': 'tempo_esgotado'})
    assert saude == 200


def test_log_tem_so_rota_situacao_tamanho_e_tempo(servidor, icp, caplog):
    _, ac, folha = icp
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac])

    with caplog.at_level(logging.DEBUG):
        pedir(servidor, 'POST', '/v1/conferir?ancora=icp', pdf)
        pedir(servidor, 'POST', '/v1/conferir?ancora=icp', b'conteudo secreto do pdf')

    texto = caplog.text
    assert 'POST /v1/conferir 200 bytes=%d' % len(pdf) in texto
    assert 'POST /v1/conferir 400 bytes=23' in texto
    for proibido in ('12345678909', 'FULANO', 'secreto', 'Contrato', TOKEN):
        assert proibido not in texto
