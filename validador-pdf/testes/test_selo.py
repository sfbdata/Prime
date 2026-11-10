"""Selo PAdES (etapa 8) com o certificado de TESTE do E8-6 e ACT de teste, de ponta a ponta pelo HTTP.

"AC Raiz Teste BlueJus" > "AC Teste e-CNPJ" > folha com CNPJ (2.16.76.1.3.3) e responsável (2.16.76.1.3.4).
Ida e volta: o PDF selado passa no próprio /v1/conferir com a raiz de teste.
"""

from __future__ import annotations

import io
import logging
from dataclasses import replace
from types import SimpleNamespace

import pytest
from cryptography import x509
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign import fields

from validador import execucao, selo
from validador.ancoras import ConjuntoDeAncoras
from validador.configuracao import Configuracao, Revogacao

from . import pdfs, pki
from .act import ActLocal, url_que_nao_responde
from .cliente import TOKEN, esperar_no_log, pedir, pedir_json, servidor_no_ar

SENHA = b'senha do selo de teste'
CNPJ = '11222333000181'


@pytest.fixture(scope='module', autouse=True)
def forkserver():
    execucao.preparar()


@pytest.fixture(scope='module')
def ecnpj():
    raiz = pki.criar_raiz('AC Raiz Teste BlueJus')
    ac = raiz.emitir_ac('AC Teste e-CNPJ')
    folha = ac.emitir_folha(
        f'BLUEJUS TECNOLOGIA LTDA:{CNPJ}', cnpj=CNPJ, responsavel_cpf='12345678909', responsavel_nome='FULANO DE TAL'
    )
    return SimpleNamespace(raiz=raiz, ac=ac, folha=folha, act=raiz.emitir_act('ACT Teste BlueJus'))


@pytest.fixture(scope='module')
def conjunto(ecnpj):
    return ConjuntoDeAncoras(icp_raizes=(ecnpj.raiz.der,), icp_intermediarias=(ecnpj.ac.der,))


@pytest.fixture(scope='module')
def configuracao(ecnpj, tmp_path_factory):
    segredos = tmp_path_factory.mktemp('secrets')
    (segredos / 'selo_pkcs12').write_bytes(ecnpj.folha.pkcs12(SENHA, [ecnpj.ac]))
    (segredos / 'selo_pkcs12_senha').write_bytes(SENHA + b'\n')
    return Configuracao(
        token=TOKEN.encode(),
        endereco='127.0.0.1',
        porta=0,
        diretorio_de_dados=str(tmp_path_factory.mktemp('dados')),
        atualizar_ancoras=False,
        revogacao=Revogacao(buscar_na_rede=False, lcrs=(ecnpj.ac.lcr(),)),
        selo_pkcs12=str(segredos / 'selo_pkcs12'),
        selo_senha=str(segredos / 'selo_pkcs12_senha'),
    )


@pytest.fixture(scope='module')
def servidor(configuracao, conjunto):
    with servidor_no_ar(configuracao, conjunto) as rodando:
        yield rodando


def selar(servidor, pdf, **kwargs):
    return pedir(servidor, 'POST', '/v1/selar', pdf, **kwargs)


def test_sela_sem_act_em_pades_bb_e_volta_no_conferir(servidor):
    pdf = pdfs.pdf_em_imagem()

    resposta, selado = selar(servidor, pdf)

    assert resposta.status == 200
    assert resposta.getheader('Content-Type') == 'application/pdf'
    assert selado[: len(pdf)] == pdf  # acréscimo incremental: o PDF da etapa 3 continua byte a byte
    leitor = PdfFileReader(io.BytesIO(selado))
    [assinatura] = leitor.embedded_signatures
    assert assinatura.field_name == 'SeloBlueJus'
    assert [float(x) for x in assinatura.sig_field['/Rect']] == [0, 0, 0, 0]  # invisível
    assert assinatura.sig_object['/SubFilter'] == '/ETSI.CAdES.detached'
    assert '/DSS' not in leitor.root

    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/conferir?ancora=icp', selado)

    assert situacao == 200
    [item] = dados['assinaturas']
    assert item['campo'] == 'SeloBlueJus'
    assert (item['intacta'], item['valida'], item['confiavel'], item['ancora']) == (True, True, True, 'icp')
    assert item['titular'] == {'cn': f'BLUEJUS TECNOLOGIA LTDA:{CNPJ}', 'cpf': None, 'cnpj': CNPJ}
    assert [e['cn'] for e in item['cadeia']][1:] == ['AC Teste e-CNPJ', 'AC Raiz Teste BlueJus']
    assert item['carimbo']['presente'] is False
    assert (item['cobertura'], item['nivel_de_modificacao'], item['motivo']) == ('arquivo_inteiro', 'nenhuma', None)


def test_sela_com_act_tem_carimbo_e_informacao_de_validacao(configuracao, conjunto, ecnpj):
    with ActLocal(ecnpj.act) as act, servidor_no_ar(replace(configuracao, selo_url_da_act=act.url), conjunto) as srv:
        resposta, selado = selar(srv, pdfs.pdf_em_imagem())
        situacao, dados, _ = pedir_json(srv, 'POST', '/v1/conferir?ancora=icp', selado)

    assert resposta.status == 200
    assert '/DSS' in PdfFileReader(io.BytesIO(selado)).root  # B-LT: LCR e cadeia no DSS
    [item] = dados['assinaturas']
    assert item['confiavel'] is True
    assert item['carimbo']['presente'] is True and item['carimbo']['valido'] is True
    # O DSS vem num acréscimo depois do selo: só informação de validação.
    assert (item['cobertura'], item['nivel_de_modificacao'], item['docmdp_ok']) == ('revisao_inteira', 'ltv', True)


def test_act_configurada_que_nao_responde_e_502(configuracao, conjunto):
    with servidor_no_ar(replace(configuracao, selo_url_da_act=url_que_nao_responde()), conjunto) as srv:
        situacao, dados, _ = pedir_json(srv, 'POST', '/v1/selar', pdfs.pdf_em_imagem())

    assert (situacao, dados) == (502, {'v': 1, 'erro': 'carimbo_indisponivel'})


def _pdf_com_campo_vazio() -> bytes:
    escritor = IncrementalPdfFileWriter(io.BytesIO(pdfs.pdf_em_imagem()))
    fields.append_signature_field(escritor, fields.SigFieldSpec('Vazio', box=(10, 10, 100, 50)))
    saida = io.BytesIO()
    escritor.write(saida)
    return saida.getvalue()


@pytest.mark.parametrize(
    'nome',
    ['outro_produtor', 'sem_produtor', 'produtor_parecido', 'ja_assinado', 'ja_selado', 'campo_vazio'],
)
def test_so_sela_pdf_do_bluejus_sem_assinatura_e_422(servidor, ecnpj, nome):
    original = pdfs.pdf_em_imagem()
    casos = {
        'outro_produtor': lambda: pdfs.pdf_em_imagem(producer='LibreOffice 24.2'),
        'sem_produtor': lambda: pdfs.pdf_em_imagem(producer=None),
        'produtor_parecido': lambda: pdfs.pdf_em_imagem(producer='BlueJus; outro'),
        'ja_assinado': lambda: pdfs.assinar(original, ecnpj.folha, cadeia=[ecnpj.ac]),
        'ja_selado': lambda: selar(servidor, original)[1],
        'campo_vazio': _pdf_com_campo_vazio,
    }
    pdf = casos[nome]()

    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/selar', pdf)

    assert (situacao, dados) == (422, {'v': 1, 'erro': 'nao_selavel'})


def test_sem_secrets_e_503_selo_nao_configurado(configuracao, conjunto, tmp_path, caplog):
    sem_arquivo = replace(configuracao, selo_pkcs12=str(tmp_path / 'nao-existe'))
    sem_senha = replace(configuracao, selo_senha=str(tmp_path / 'nao-existe'))
    (tmp_path / 'vazio').write_bytes(b'')
    vazio = replace(configuracao, selo_pkcs12=str(tmp_path / 'vazio'))

    with caplog.at_level(logging.INFO, logger='validador.servidor'):
        for cfg in (sem_arquivo, sem_senha, vazio):
            with servidor_no_ar(cfg, conjunto) as srv:
                situacao, dados, _ = pedir_json(srv, 'POST', '/v1/selar', pdfs.pdf_em_imagem())
                esperar_no_log(caplog, 'resultado=selo_nao_configurado')
            assert (situacao, dados) == (503, {'v': 1, 'erro': 'selo_nao_configurado'})

    assert 'selo nao configurado: secrets do selo ausentes ou vazios' in caplog.text
    assert 'certificado invalido' not in caplog.text


def test_sem_secrets_e_sem_ancoras_continua_selo_nao_configurado(configuracao, tmp_path):
    # O PHP trata só este código como "não configurado" (guarda sem selo, sem trilha): ele vem antes das âncoras.
    cfg = replace(configuracao, selo_pkcs12=str(tmp_path / 'nao-existe'))
    with servidor_no_ar(cfg, ConjuntoDeAncoras()) as srv:
        situacao, dados, _ = pedir_json(srv, 'POST', '/v1/selar', pdfs.pdf_em_imagem())

    assert (situacao, dados) == (503, {'v': 1, 'erro': 'selo_nao_configurado'})


def test_ancoras_icp_sem_raiz_e_503_ancoras_indisponiveis(configuracao, ecnpj):
    # Secrets bons, mas o volume das âncoras está vazio: passageiro, não "selo não configurado".
    so_govbr = ConjuntoDeAncoras(govbr_raizes=(ecnpj.raiz.der,))
    for conjunto in (ConjuntoDeAncoras(), so_govbr):
        with servidor_no_ar(configuracao, conjunto) as srv:
            situacao, dados, resposta = pedir_json(srv, 'POST', '/v1/selar', pdfs.pdf_em_imagem())

        assert (situacao, dados) == (503, {'v': 1, 'erro': 'ancoras_indisponiveis'})
        assert resposta.getheader('Retry-After') == '60'


def test_senha_errada_e_503_selo_certificado_invalido(configuracao, conjunto, tmp_path, caplog):
    (tmp_path / 'senha').write_bytes(b'errada\n')

    with caplog.at_level(logging.INFO, logger='validador.servidor'), \
            servidor_no_ar(replace(configuracao, selo_senha=str(tmp_path / 'senha')), conjunto) as srv:
        situacao, dados, _ = pedir_json(srv, 'POST', '/v1/selar', pdfs.pdf_em_imagem())
        esperar_no_log(caplog, 'resultado=selo_certificado_invalido')

    assert (situacao, dados) == (503, {'v': 1, 'erro': 'selo_certificado_invalido'})
    erros = [r for r in caplog.records if r.levelno == logging.ERROR]
    assert [r.getMessage() for r in erros] == [
        'selo com certificado invalido: senha errada, PKCS#12 ilegivel, e-CNPJ vencido, revogado ou fora das '
        'ancoras ICP-Brasil'
    ]
    assert 'selo nao configurado' not in caplog.text


def test_pkcs12_grande_demais_e_selo_certificado_invalido(configuracao, conjunto, tmp_path):
    (tmp_path / 'grande').write_bytes(b'0' * (selo.TETO_DO_PKCS12 + 1))

    with servidor_no_ar(replace(configuracao, selo_pkcs12=str(tmp_path / 'grande')), conjunto) as srv:
        situacao, dados, _ = pedir_json(srv, 'POST', '/v1/selar', pdfs.pdf_em_imagem())

    assert (situacao, dados) == (503, {'v': 1, 'erro': 'selo_certificado_invalido'})


SO_CIFRAGEM = x509.KeyUsage(
    digital_signature=False, content_commitment=False, key_encipherment=True, data_encipherment=False,
    key_agreement=False, key_cert_sign=False, crl_sign=False, encipher_only=False, decipher_only=False,
)


@pytest.mark.parametrize('problema', ['vencido', 'revogado', 'fora_da_icp', 'uso_da_chave'])
def test_nunca_sela_com_certificado_vencido_revogado_ou_fora_das_ancoras(configuracao, ecnpj, problema):
    folha = ecnpj.folha
    lcr = ecnpj.ac.lcr()
    conjunto = ConjuntoDeAncoras(icp_raizes=(ecnpj.raiz.der,), icp_intermediarias=(ecnpj.ac.der,))
    if problema == 'vencido':
        folha = ecnpj.ac.emitir_folha(
            'VENCIDA', cnpj=CNPJ, inicio=pki.AGORA - 400 * pki.UM_DIA, fim=pki.AGORA - pki.UM_DIA
        )
    elif problema == 'revogado':
        lcr = ecnpj.ac.lcr([folha])
    elif problema == 'uso_da_chave':
        folha = ecnpj.ac.emitir_folha('SO CIFRAGEM', cnpj=CNPJ, uso_da_chave=SO_CIFRAGEM)
    else:
        # As âncoras ICP estão carregadas, mas o e-CNPJ é de outra raiz.
        conjunto = ConjuntoDeAncoras(icp_raizes=(pki.criar_raiz('AC Raiz Outra').der,))

    with pytest.raises(execucao.ErroConhecido, match='^selo_certificado_invalido$'):
        selo.selar(
            pdfs.pdf_em_imagem(), folha.pkcs12(SENHA, [ecnpj.ac]), SENHA, '', conjunto,
            Revogacao(buscar_na_rede=False, lcrs=(lcr,)),
        )


@pytest.mark.parametrize('corpo', [b'nao e pdf', b'%PDF-1.7\nquebrado'])
def test_pdf_ilegivel_e_400(servidor, corpo):
    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/selar', corpo)

    assert (situacao, dados) == (400, {'v': 1, 'erro': 'pdf_ilegivel'})


def test_selar_exige_token(servidor):
    situacao, dados, _ = pedir_json(servidor, 'POST', '/v1/selar', pdfs.pdf_em_imagem(), token='errado')

    assert (situacao, dados) == (401, {'v': 1, 'erro': 'nao_autorizado'})


def test_credencial_le_a_senha_sem_a_quebra_de_linha(configuracao):
    pkcs12, senha = selo.ler_credencial(configuracao)

    assert pkcs12.startswith(b'0')  # DER de um PFX
    assert senha == SENHA


def test_selo_nao_aparece_no_log(servidor, caplog):
    with caplog.at_level(logging.DEBUG):
        selar(servidor, pdfs.pdf_em_imagem('BJA-SEGREDO-0000-0000'))
        esperar_no_log(caplog, 'POST /v1/selar 200')

    assert 'POST /v1/selar 200' in caplog.text
    for proibido in ('SEGREDO', CNPJ, SENHA.decode(), 'BLUEJUS TECNOLOGIA'):
        assert proibido not in caplog.text
