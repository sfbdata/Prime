"""Conferência com o pyHanko contra a PKI de teste, sem rede (LCR e OCSP entregues ao contexto)."""

from __future__ import annotations

import asyncio
import datetime as dt
import re
from types import SimpleNamespace

import pytest
from cryptography import x509

from validador import conferencia, selo
from validador.ancoras import ConjuntoDeAncoras
from validador.configuracao import Revogacao
from validador.execucao import ErroConhecido

from . import pdfs, pki
from .lcr import LcrLocal


@pytest.fixture(scope='module')
def icp():
    raiz = pki.criar_raiz('AC Raiz Teste ICP')
    ac = raiz.emitir_ac('AC Teste ICP')
    return SimpleNamespace(
        raiz=raiz,
        ac=ac,
        folha=ac.emitir_folha('FULANO DE TAL:12345678909', cpf='12345678909'),
        act=raiz.emitir_act(),
    )


@pytest.fixture(scope='module')
def govbr():
    raiz = pki.criar_raiz('AC Raiz Teste gov.br')
    ac = raiz.emitir_ac('AC Final Teste gov.br')
    return SimpleNamespace(raiz=raiz, ac=ac, folha=ac.emitir_folha('CICLANA GOVBR', cpf='98765432100'))


@pytest.fixture(scope='module')
def ancoras(icp, govbr):
    return ConjuntoDeAncoras(
        icp_raizes=(icp.raiz.der,),
        icp_intermediarias=(icp.ac.der,),
        govbr_raizes=(govbr.raiz.der,),
        govbr_intermediarias=(govbr.ac.der,),
    )


def _conferir(pdf, ancoras, *, ancora='icp', lcrs=(), ocsps=()):
    return conferencia.conferir(pdf, ancora, ancoras, Revogacao(buscar_na_rede=False, lcrs=lcrs, ocsps=ocsps))


def _uma(pdf, ancoras, **kwargs):
    resultado = _conferir(pdf, ancoras, **kwargs)
    assert resultado['v'] == 1
    assert len(resultado['assinaturas']) == 1
    return resultado['assinaturas'][0]


def test_cadeia_boa_confiavel_com_titular_faixa_e_cadeia(icp, ancoras):
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))

    faixa = [int(x) for x in re.search(rb'/ByteRange\s*\[\s*(\d+) (\d+) (\d+) (\d+)', pdf).groups()]
    assert item['campo'] == 'Signature1'
    assert item['faixa'] == faixa
    assert item['faixa'][2] + item['faixa'][3] == len(pdf)
    assert (item['intacta'], item['valida'], item['confiavel']) == (True, True, True)
    assert item['ancora'] == 'icp'
    assert item['titular'] == {'cn': 'FULANO DE TAL:12345678909', 'cpf': '12345678909', 'cnpj': None}
    assert [e['cn'] for e in item['cadeia']] == ['FULANO DE TAL:12345678909', 'AC Teste ICP', 'AC Raiz Teste ICP']
    assert item['cadeia'][-1]['sha256'] == conferencia.sha256_hex(icp.raiz.der)
    assert item['revogacao'] == 'boa'
    assert item['carimbo'] == {'presente': False, 'valido': False, 'hora': None}
    assert item['cobertura'] == 'arquivo_inteiro'
    assert item['nivel_de_modificacao'] == 'nenhuma'
    assert item['docmdp_ok'] is True
    assert item['motivo'] is None
    assert set(item) == {
        'campo', 'faixa', 'intacta', 'valida', 'confiavel', 'ancora', 'titular', 'cadeia', 'revogacao',
        'carimbo', 'cobertura', 'nivel_de_modificacao', 'docmdp_ok', 'motivo',
    }


def test_intermediaria_vem_das_ancoras_quando_o_pdf_nao_traz(icp, ancoras):
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha)  # CMS só com o certificado do signatário

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))

    assert item['confiavel'] is True
    assert len(item['cadeia']) == 3


def test_ancora_do_nivel_govbr_nao_aceita_assinatura_icp(icp, ancoras):
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])

    item = _uma(pdf, ancoras, ancora='govbr', lcrs=(icp.ac.lcr(),))

    assert item['intacta'] and item['valida']
    assert item['confiavel'] is False
    assert item['ancora'] is None
    assert item['motivo'] == 'cadeia_nao_confiavel'


def test_qualquer_aceita_govbr_e_diz_qual_ancora(govbr, ancoras):
    pdf = pdfs.assinar(pdfs.pdf_simples(), govbr.folha, cadeia=[govbr.ac])

    assert _uma(pdf, ancoras, ancora='qualquer', lcrs=(govbr.ac.lcr(),))['ancora'] == 'govbr'
    assert _uma(pdf, ancoras, ancora='govbr', lcrs=(govbr.ac.lcr(),))['confiavel'] is True
    assert _uma(pdf, ancoras, ancora='icp', lcrs=(govbr.ac.lcr(),))['confiavel'] is False


def test_lista_de_raizes_vazia_nao_cai_nas_raizes_do_sistema(icp):
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])
    sem_govbr = ConjuntoDeAncoras(icp_raizes=(icp.raiz.der,), icp_intermediarias=(icp.ac.der,))

    item = _uma(pdf, sem_govbr, ancora='govbr', lcrs=(icp.ac.lcr(),))

    assert item['confiavel'] is False


def test_raiz_desconhecida(ancoras):
    estranha = pki.criar_raiz('AC Raiz Desconhecida')
    ac = estranha.emitir_ac('AC Desconhecida')
    folha = ac.emitir_folha('INTRUSO:11122233396', cpf='11122233396')
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac, estranha])

    item = _uma(pdf, ancoras, ancora='qualquer', lcrs=(ac.lcr(),))

    assert (item['intacta'], item['valida'], item['confiavel']) == (True, True, False)
    assert item['ancora'] is None
    assert item['motivo'] == 'cadeia_nao_confiavel'
    assert item['revogacao'] == 'desconhecida'
    assert [e['cn'] for e in item['cadeia']] == ['INTRUSO:11122233396']
    # O titular é o que o certificado declara: aparece, mas não vale sem cadeia (o PHP só usa com confiavel).
    assert item['titular']['cpf'] == '11122233396'


def test_raiz_falsa_com_o_mesmo_nome_dentro_do_pdf_nao_vira_ancora(icp, ancoras):
    falsa = pki.criar_raiz('AC Raiz Teste ICP')
    ac_falsa = falsa.emitir_ac('AC Teste ICP')
    folha = ac_falsa.emitir_folha('FULANO DE TAL:12345678909', cpf='12345678909')
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac_falsa, falsa])

    item = _uma(pdf, ancoras, ancora='qualquer', lcrs=(ac_falsa.lcr(), icp.ac.lcr()))

    assert item['confiavel'] is False
    assert item['ancora'] is None
    assert item['motivo'] == 'cadeia_nao_confiavel'


def test_revogado_pela_lcr(icp, ancoras):
    folha = icp.ac.emitir_folha('REVOGADO:12345678909', cpf='12345678909')
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[icp.ac])

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr([folha]),))

    assert (item['intacta'], item['valida'], item['confiavel']) == (True, True, False)
    assert item['revogacao'] == 'revogada'
    assert item['motivo'] == 'certificado_revogado'


def test_ocsp_bom_e_ocsp_revogado(icp, ancoras):
    folha = icp.ac.emitir_folha('OCSP:12345678909', cpf='12345678909', com_lcr=False, com_ocsp=True)
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[icp.ac])

    boa = _uma(pdf, ancoras, ocsps=(icp.ac.ocsp(folha),))
    revogada = _uma(pdf, ancoras, ocsps=(icp.ac.ocsp(folha, revogado=True),))

    assert (boa['confiavel'], boa['revogacao']) == (True, 'boa')
    assert (revogada['confiavel'], revogada['revogacao'], revogada['motivo']) == (
        False, 'revogada', 'certificado_revogado'
    )


def test_revogacao_declarada_e_indisponivel_recusa_hard_fail(icp, ancoras):
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])

    item = _uma(pdf, ancoras)  # o certificado declara LCR, nenhuma foi entregue e não há rede

    assert item['confiavel'] is False
    assert item['revogacao'] == 'desconhecida'
    assert item['motivo'] == 'revogacao_indisponivel'


def test_signatario_sem_lcr_nem_ocsp_declarado_e_revogacao_nao_declarada(icp, ancoras):
    folha = icp.ac.emitir_folha('SEM REVOGACAO:12345678909', cpf='12345678909', com_lcr=False, com_ocsp=False)
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[icp.ac])

    item = _uma(pdf, ancoras)

    # A cadeia fecha, mas nada foi consultado: o motivo não pode ser nulo (o PHP só aceita revogacao=boa).
    assert (item['intacta'], item['valida'], item['confiavel'], item['ancora']) == (True, True, True, 'icp')
    assert item['revogacao'] == 'desconhecida'
    assert item['motivo'] == 'revogacao_nao_declarada'


def test_certificado_vencido(icp, ancoras):
    folha = icp.ac.emitir_folha(
        'VENCIDO:12345678909', cpf='12345678909', inicio=pki.AGORA - 400 * pki.UM_DIA, fim=pki.AGORA - pki.UM_DIA
    )
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[icp.ac])

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))

    assert (item['intacta'], item['valida'], item['confiavel']) == (True, True, False)
    assert item['motivo'] == 'certificado_vencido'


def test_carimbo_do_tempo_valido(icp, ancoras):
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac], act=icp.act)

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))

    assert item['carimbo']['presente'] is True
    assert item['carimbo']['valido'] is True
    # A ACT de teste carimba com a hora do momento da assinatura (depois de pki.AGORA, antes de agora).
    hora = dt.datetime.fromisoformat(item['carimbo']['hora'])
    assert pki.AGORA - dt.timedelta(minutes=1) <= hora <= dt.datetime.now(dt.timezone.utc) + dt.timedelta(minutes=1)
    assert item['motivo'] is None


def test_carimbo_de_act_desconhecida_e_invalido_sem_derrubar_a_assinatura(icp, ancoras):
    act_estranha = pki.criar_raiz('AC Raiz de ACT Desconhecida').emitir_act()
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac], act=act_estranha)

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))

    assert item['carimbo']['presente'] is True
    assert item['carimbo']['valido'] is False
    assert item['confiavel'] is True
    assert item['motivo'] == 'carimbo_invalido'


def test_byte_trocado_dentro_da_faixa_assinada_e_conteudo_alterado(icp, ancoras):
    assinado = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])
    adulterado = assinado.replace(b'Contrato original', b'Contrato 0riginal')
    assert adulterado != assinado and len(adulterado) == len(assinado)

    item = _uma(adulterado, ancoras, lcrs=(icp.ac.lcr(),))

    assert item['intacta'] is False
    assert item['confiavel'] is False
    assert item['motivo'] == 'conteudo_alterado'


def test_uso_de_chave_sem_assinatura_digital_nem_nao_repudio_e_recusado(icp, ancoras):
    so_cifragem = x509.KeyUsage(
        digital_signature=False, content_commitment=False, key_encipherment=True, data_encipherment=False,
        key_agreement=False, key_cert_sign=False, crl_sign=False, encipher_only=False, decipher_only=False,
    )
    folha = icp.ac.emitir_folha('CIFRAGEM:12345678909', cpf='12345678909', uso_da_chave=so_cifragem)
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[icp.ac])

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))

    assert (item['intacta'], item['valida'], item['confiavel']) == (True, True, False)
    assert item['ancora'] is None
    assert item['motivo'] == 'restricao_do_certificado'


def test_acrescimo_que_muda_a_pagina_depois_da_assinatura(icp, ancoras):
    assinado = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])
    alterado = pdfs.acrescentar(assinado, {4: pdfs.fluxo(pdfs.CONTEUDO_TROCADO)})

    item = _uma(alterado, ancoras, lcrs=(icp.ac.lcr(),))

    assert item['intacta'] is True  # os bytes que a assinatura cobre continuam iguais
    assert item['cobertura'] == 'revisao_inteira'
    assert item['nivel_de_modificacao'] == 'outra'
    assert item['docmdp_ok'] is False
    assert item['motivo'] == 'modificado_depois'


def test_sombra_objeto_preparado_antes_e_ativado_depois_da_assinatura(icp, ancoras):
    # Revisão 1: o conteúdo trocado já está no arquivo, sem uso (o signatário não o vê na tela).
    original = pdfs.pdf_simples(escondido=pdfs.CONTEUDO_TROCADO)
    assinado = pdfs.assinar(original, icp.folha, cadeia=[icp.ac])
    # Revisão 3: só a página passa a apontar para o objeto preparado; nenhum fluxo novo.
    pagina_ativada = (
        b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] '
        b'/Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>'
    )
    ativado = pdfs.acrescentar(assinado, {3: pagina_ativada})

    item = _uma(ativado, ancoras, lcrs=(icp.ac.lcr(),))

    assert item['intacta'] is True
    assert item['nivel_de_modificacao'] == 'outra'
    assert item['docmdp_ok'] is False
    assert item['motivo'] == 'modificado_depois'


def test_segunda_assinatura_e_so_formulario_para_a_primeira(icp, ancoras):
    segunda = icp.ac.emitir_folha('BELTRANO:98765432100', cpf='98765432100')
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])
    pdf = pdfs.assinar(pdf, segunda, campo='Signature2', cadeia=[icp.ac])

    primeira, ultima = _conferir(pdf, ancoras, lcrs=(icp.ac.lcr(),))['assinaturas']

    assert (primeira['campo'], primeira['cobertura'], primeira['nivel_de_modificacao']) == (
        'Signature1', 'revisao_inteira', 'formulario'
    )
    assert primeira['docmdp_ok'] is True and primeira['motivo'] is None and primeira['confiavel']
    assert (ultima['campo'], ultima['cobertura'], ultima['nivel_de_modificacao']) == (
        'Signature2', 'arquivo_inteiro', 'nenhuma'
    )
    assert ultima['titular']['cpf'] == '98765432100'


def test_titular_pessoa_juridica_com_cnpj(icp, ancoras):
    folha = icp.ac.emitir_folha(
        'EMPRESA TESTE LTDA:11222333000181',
        cnpj='11222333000181',
        responsavel_cpf='12345678909',
        responsavel_nome='FULANO DE TAL',
    )
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[icp.ac])

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))

    assert item['titular'] == {'cn': 'EMPRESA TESTE LTDA:11222333000181', 'cpf': None, 'cnpj': '11222333000181'}


def test_cpf_so_com_zeros_nao_e_cpf(icp, ancoras):
    folha = icp.ac.emitir_folha('SEM CPF', cpf='00000000000')
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[icp.ac])

    assert _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))['titular']['cpf'] is None


def _com_byte_range(pdf: bytes, novo: bytes) -> bytes:
    """Troca o valor do /ByteRange mantendo o tamanho (as posições do xref continuam certas)."""
    trecho = re.search(rb'/ByteRange\s*(\[[^\]]*\])', pdf)
    tamanho = trecho.end(1) - trecho.start(1)
    assert len(novo) <= tamanho
    return pdf[:trecho.start(1)] + novo.ljust(tamanho) + pdf[trecho.end(1):]


@pytest.mark.parametrize(
    'byte_range', [b'null', b'(abc)', b'[/a /b /c /d]', b'[0 1 2]', b'[-1 1 2 3]', b'[0.5 1 2 3]', b'[]', b'<< >>']
)
def test_byte_range_que_nao_sao_4_inteiros_sai_com_faixa_nula_e_ilegivel(icp, ancoras, byte_range):
    assinado = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])

    item = _uma(_com_byte_range(assinado, byte_range), ancoras, lcrs=(icp.ac.lcr(),))

    assert item['faixa'] is None  # nunca uma faixa arredondada ou parcial: o PHP casa os itens por ela
    assert (item['intacta'], item['valida'], item['confiavel'], item['ancora']) == (False, False, False, None)
    assert item['motivo'] == 'assinatura_ilegivel'


def test_assinatura_sem_byte_range_derruba_o_pdf_inteiro(icp, ancoras):
    # O pyHanko não monta a assinatura sem a chave /ByteRange: o PDF todo é ilegível (400), não um item.
    assinado = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac])
    sem_chave = assinado.replace(b'/ByteRange', b'/ByteRangX')

    with pytest.raises(ErroConhecido, match='^pdf_ilegivel$'):
        _conferir(sem_chave, ancoras, lcrs=(icp.ac.lcr(),))


def test_pdf_sem_assinatura(ancoras):
    assert _conferir(pdfs.pdf_simples(), ancoras) == {'v': 1, 'assinaturas': []}


@pytest.mark.parametrize('corpo', [b'', b'%PDF-1.7\nlixo', b'GIF89a' + b'\0' * 100, pdfs.pdf_simples()[:200]])
def test_pdf_ilegivel(ancoras, corpo):
    with pytest.raises(ErroConhecido, match='^pdf_ilegivel$'):
        _conferir(corpo, ancoras)


def test_ancora_fora_da_lista(ancoras):
    with pytest.raises(ErroConhecido, match='^parametro_invalido$'):
        _conferir(pdfs.pdf_simples(), ancoras, ancora='sistema')


def test_lcr_buscada_na_rede_uma_vez_por_pedido_para_todas_as_assinaturas(icp, ancoras):
    # Duas pessoas da mesma AC: a LCR é a mesma URL. Com busca na rede, o cache é do pedido inteiro.
    with LcrLocal(icp.ac.lcr()) as servidor_da_lcr:
        primeira = icp.ac.emitir_folha('PRIMEIRA:12345678909', cpf='12345678909', url_da_lcr=servidor_da_lcr.url)
        segunda = icp.ac.emitir_folha('SEGUNDA:98765432100', cpf='98765432100', url_da_lcr=servidor_da_lcr.url)
        pdf = pdfs.assinar(pdfs.pdf_simples(), primeira, cadeia=[icp.ac])
        pdf = pdfs.assinar(pdf, segunda, campo='Signature2', cadeia=[icp.ac])

        resultado = conferencia.conferir(pdf, 'icp', ancoras, Revogacao(buscar_na_rede=True, tempo_por_requisicao=5))

    itens = resultado['assinaturas']
    assert [(i['campo'], i['confiavel'], i['revogacao'], i['motivo']) for i in itens] == [
        ('Signature1', True, 'boa', None),
        ('Signature2', True, 'boa', None),
    ]
    assert servidor_da_lcr.pedidos == ['/ac-teste.crl']


def test_contexto_com_busca_na_rede_monta_sem_buscar_nada(icp):
    # Só monta (nada é validado, nada sai pela rede): pega troca de assinatura do pyHanko numa atualização.
    revogacao = Revogacao(buscar_na_rede=True, requisicoes_maximas=12)

    async def montar():
        backend, busca = conferencia.criar_busca(revogacao, assinaturas=3)
        try:
            contextos = [
                conferencia.criar_contexto((icp.raiz.der,), (icp.ac.der,), revogacao, busca, politica=politica)
                for politica in (conferencia.POLITICA_DE_REVOGACAO, selo.POLITICA_DO_SELO)
            ]
        finally:
            await backend.close()
        return backend, busca, contextos

    backend, busca, contextos = asyncio.run(montar())

    assert busca is not None
    assert all(c.fetching_allowed for c in contextos)
    assert (backend.limits.max_requests, backend.limits.max_cert_requests, backend.limits.max_aia_urls_per_object) == (
        36, 0, 0
    )
    with pytest.raises(ValueError):
        conferencia.criar_contexto((icp.raiz.der,), (), revogacao, None)
    assert conferencia.criar_busca(Revogacao(buscar_na_rede=False)) == (None, None)
