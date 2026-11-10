"""Conferência com o pyHanko contra a PKI de teste, sem rede (LCR e OCSP entregues ao contexto)."""

from __future__ import annotations

import re
from types import SimpleNamespace

import pytest

from validador import conferencia
from validador.ancoras import ConjuntoDeAncoras
from validador.configuracao import Revogacao
from validador.execucao import ErroConhecido

from . import pdfs, pki


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
    assert item['carimbo']['hora'].startswith(str(pki.AGORA.year))
    assert item['motivo'] is None


def test_carimbo_de_act_desconhecida_e_invalido_sem_derrubar_a_assinatura(icp, ancoras):
    act_estranha = pki.criar_raiz('AC Raiz de ACT Desconhecida').emitir_act()
    pdf = pdfs.assinar(pdfs.pdf_simples(), icp.folha, cadeia=[icp.ac], act=act_estranha)

    item = _uma(pdf, ancoras, lcrs=(icp.ac.lcr(),))

    assert item['carimbo']['presente'] is True
    assert item['carimbo']['valido'] is False
    assert item['confiavel'] is True
    assert item['motivo'] == 'carimbo_invalido'


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


def test_pdf_sem_assinatura(ancoras):
    assert _conferir(pdfs.pdf_simples(), ancoras) == {'v': 1, 'assinaturas': []}


@pytest.mark.parametrize('corpo', [b'', b'%PDF-1.7\nlixo', b'GIF89a' + b'\0' * 100, pdfs.pdf_simples()[:200]])
def test_pdf_ilegivel(ancoras, corpo):
    with pytest.raises(ErroConhecido, match='^pdf_ilegivel$'):
        _conferir(corpo, ancoras)


def test_ancora_fora_da_lista(ancoras):
    with pytest.raises(ErroConhecido, match='^parametro_invalido$'):
        _conferir(pdfs.pdf_simples(), ancoras, ancora='sistema')
