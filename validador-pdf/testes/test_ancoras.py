"""Âncoras (E6-5): pacote ICP pelo SHA-512 e pelas raízes fixadas, gov.br pela impressão digital fixada."""

from __future__ import annotations

import hashlib
import io
import logging
import re
import zipfile

import pytest
from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.serialization import Encoding, pkcs7

from validador import conferencia
from validador.ancoras import (
    ENTRADAS_MAXIMAS_DO_ZIP,
    TETO_DE_UM_CERTIFICADO,
    AncoraRecusada,
    ConjuntoDeAncoras,
    RepositorioDeAncoras,
    conferir_cadeia_govbr,
    conferir_pacote_icp,
    sha256_hex,
)
from validador.configuracao import GOVBR_RAIZES_SHA256, ICP_RAIZES_SHA256, Configuracao, Revogacao

from . import pdfs, pki

URL_PACOTE = 'http://icp.teste.invalid/ACcompactado.zip'
URL_HASH = 'http://icp.teste.invalid/hashsha512.txt'
URL_GOVBR = 'http://govbr.teste.invalid/cadeia.p7b'


@pytest.fixture(scope='module')
def cadeias():
    raiz = pki.criar_raiz('AC Raiz Teste ICP')
    raiz_nova = pki.criar_raiz('AC Raiz Teste ICP v2')
    raiz_govbr = pki.criar_raiz('AC Raiz Teste gov.br')
    return {
        'raiz': raiz,
        'raiz_nova': raiz_nova,
        'icp_fixadas': frozenset({sha256_hex(raiz.der), sha256_hex(raiz_nova.der)}),
        'ac': raiz.emitir_ac('AC Teste ICP'),
        'raiz_govbr': raiz_govbr,
        'ac_govbr': raiz_govbr.emitir_ac('AC Final Teste gov.br'),
    }


def zip_de(arquivos: dict[str, bytes]) -> bytes:
    saida = io.BytesIO()
    with zipfile.ZipFile(saida, 'w', zipfile.ZIP_DEFLATED) as arquivo:
        for nome, dados in arquivos.items():
            arquivo.writestr(nome, dados)
    return saida.getvalue()


def hash_de(pacote: bytes) -> bytes:
    return hashlib.sha512(pacote).hexdigest().encode() + b'  ACcompactado.zip\n'


def p7b_de(*entidades) -> bytes:
    return pkcs7.serialize_certificates([e.certificado for e in entidades], Encoding.DER)


class Rede:
    """Baixador falso: devolve o que estiver no dicionário, ou levanta o erro guardado."""

    def __init__(self, respostas: dict):
        self.respostas = respostas
        self.pedidos: list[str] = []

    def __call__(self, url: str, teto: int) -> bytes:
        self.pedidos.append(url)
        resposta = self.respostas[url]
        if isinstance(resposta, Exception):
            raise resposta
        assert len(resposta) <= teto
        return resposta


def configuracao(tmp_path, cadeias=None, fixadas=()) -> Configuracao:
    return Configuracao(
        diretorio_de_dados=str(tmp_path),
        icp_url_do_pacote=URL_PACOTE,
        icp_url_do_hash=URL_HASH,
        govbr_url_da_cadeia=URL_GOVBR,
        icp_raizes_sha256=cadeias['icp_fixadas'] if cadeias else frozenset(),
        govbr_raizes_sha256=frozenset(fixadas),
    )


def pacote_bom(c) -> bytes:
    return zip_de({
        'ICP-Brasilv1.crt': c['raiz'].pem,
        'ICP-Brasilv2.crt': c['raiz_nova'].der,
        'AC-Teste.crt': c['ac'].pem,
        'LEIAME.txt': b'nao e certificado',
        'quebrado.crt': b'-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n',
    })


def test_pacote_icp_conferido_separa_raizes_de_intermediarias(cadeias):
    pacote = pacote_bom(cadeias)

    raizes, intermediarias = conferir_pacote_icp(pacote, hash_de(pacote), cadeias['icp_fixadas'])

    assert {sha256_hex(d) for d in raizes} == {
        sha256_hex(cadeias['raiz'].der), sha256_hex(cadeias['raiz_nova'].der)
    }
    assert [sha256_hex(d) for d in intermediarias] == [sha256_hex(cadeias['ac'].der)]


def test_pacote_com_hash_que_nao_confere_e_recusado_antes_de_abrir(cadeias):
    pacote = pacote_bom(cadeias)

    with pytest.raises(AncoraRecusada, match='hash_nao_confere'):
        conferir_pacote_icp(pacote, hash_de(pacote + b'x'), cadeias['icp_fixadas'])
    with pytest.raises(AncoraRecusada, match='hash_ilegivel'):
        conferir_pacote_icp(pacote, b'', cadeias['icp_fixadas'])


def test_autoassinado_falso_nao_vira_raiz(cadeias):
    # Mesmo nome no emissor e no titular, mas assinado por outra chave: não é autoassinado.
    impostor = pki.criar_raiz('AC Raiz Impostora')
    outra = pki._chave()
    certificado = (
        x509.CertificateBuilder()
        .subject_name(impostor.certificado.subject)
        .issuer_name(impostor.certificado.subject)
        .public_key(impostor.chave.public_key())
        .serial_number(1)
        .not_valid_before(pki.AGORA - pki.UM_DIA)
        .not_valid_after(pki.AGORA + pki.UM_DIA)
        .sign(outra, hashes.SHA256())
    )
    pacote = zip_de({'impostor.crt': certificado.public_bytes(Encoding.PEM)})
    # Mesmo com a impressão digital fixada, a assinatura própria tem de conferir.
    fixadas = cadeias['icp_fixadas'] | {sha256_hex(certificado.public_bytes(Encoding.DER))}

    with pytest.raises(AncoraRecusada, match='pacote_sem_raiz_fixada'):
        conferir_pacote_icp(pacote, hash_de(pacote), fixadas)

    # Ao lado de uma raiz boa, o impostor não entra nem como intermediária.
    misto = zip_de({'impostor.crt': certificado.public_bytes(Encoding.PEM), 'boa.crt': cadeias['raiz'].pem})
    raizes, intermediarias = conferir_pacote_icp(misto, hash_de(misto), fixadas)
    assert [sha256_hex(d) for d in raizes] == [sha256_hex(cadeias['raiz'].der)]
    assert intermediarias == ()


def test_zip_hostil_e_recusado(cadeias):
    muitas = zip_de({f'{i}.crt': b'x' for i in range(ENTRADAS_MAXIMAS_DO_ZIP + 1)})
    grande = zip_de({'grande.crt': b'0' * (TETO_DE_UM_CERTIFICADO + 1)})
    lixo = b'nao e zip'

    for pacote, codigo in ((muitas, 'zip_com_entradas_demais'), (grande, 'certificado_grande_demais'),
                           (lixo, 'zip_ilegivel')):
        with pytest.raises(AncoraRecusada, match=codigo):
            conferir_pacote_icp(pacote, hash_de(pacote), cadeias['icp_fixadas'])


def test_cadeia_govbr_so_aceita_raiz_fixada(cadeias):
    p7b = p7b_de(cadeias['ac_govbr'], cadeias['raiz_govbr'])
    fixada = sha256_hex(cadeias['raiz_govbr'].der)

    raizes, intermediarias = conferir_cadeia_govbr(p7b, frozenset({fixada}))

    assert [sha256_hex(d) for d in raizes] == [fixada]
    assert [sha256_hex(d) for d in intermediarias] == [sha256_hex(cadeias['ac_govbr'].der)]
    with pytest.raises(AncoraRecusada, match='cadeia_govbr_sem_raiz_fixada'):
        conferir_cadeia_govbr(p7b, frozenset({'0' * 64}))


def test_atualizar_guarda_no_disco_e_recarrega_ao_subir(tmp_path, cadeias):
    pacote = pacote_bom(cadeias)
    fixada = sha256_hex(cadeias['raiz_govbr'].der)
    rede = Rede({
        URL_HASH: hash_de(pacote),
        URL_PACOTE: pacote,
        URL_GOVBR: p7b_de(cadeias['ac_govbr'], cadeias['raiz_govbr']),
    })
    repositorio = RepositorioDeAncoras(configuracao(tmp_path, cadeias, {fixada}), baixar=rede)

    assert repositorio.atualizar() is True

    conjunto = repositorio.conjunto
    assert (len(conjunto.icp_raizes), len(conjunto.icp_intermediarias)) == (2, 1)
    assert (len(conjunto.govbr_raizes), len(conjunto.govbr_intermediarias)) == (1, 1)
    assert conjunto.atualizadas_em is not None
    assert (tmp_path / 'icp' / 'ACcompactado.zip').read_bytes() == pacote

    # Sobe de novo sem rede: a última boa vem do volume, conferida outra vez.
    outro = RepositorioDeAncoras(configuracao(tmp_path, cadeias, {fixada}), baixar=Rede({}))
    outro.carregar_do_disco()
    assert outro.conjunto == conjunto


def test_download_que_falha_ou_nao_confere_mantem_a_ultima_boa(tmp_path, cadeias):
    pacote = pacote_bom(cadeias)
    repositorio = RepositorioDeAncoras(
        configuracao(tmp_path, cadeias), baixar=Rede({URL_HASH: hash_de(pacote), URL_PACOTE: pacote})
    )
    assert repositorio.atualizar() is True
    boa = repositorio.conjunto

    adulterado = zip_de({'falsa.crt': pki.criar_raiz('AC Raiz Falsa').pem})
    repositorio._baixar = Rede({URL_HASH: hash_de(pacote), URL_PACOTE: adulterado})
    assert repositorio.atualizar() is False
    assert repositorio.conjunto == boa

    repositorio._baixar = Rede({URL_HASH: OSError('rede fora'), URL_PACOTE: pacote})
    assert repositorio.atualizar() is False
    assert repositorio.conjunto == boa

    recarregado = RepositorioDeAncoras(configuracao(tmp_path, cadeias), baixar=Rede({}))
    recarregado.carregar_do_disco()
    assert recarregado.conjunto.icp_raizes == boa.icp_raizes


def test_sem_raiz_govbr_fixada_nao_baixa_a_cadeia_govbr(tmp_path, cadeias):
    pacote = pacote_bom(cadeias)
    rede = Rede({URL_HASH: hash_de(pacote), URL_PACOTE: pacote})
    repositorio = RepositorioDeAncoras(configuracao(tmp_path, cadeias), baixar=rede)

    assert repositorio.atualizar() is True

    assert URL_GOVBR not in rede.pedidos
    assert repositorio.conjunto.govbr_raizes == ()


def test_sem_nada_no_disco_comeca_vazio(tmp_path):
    repositorio = RepositorioDeAncoras(configuracao(tmp_path), baixar=Rede({}))

    repositorio.carregar_do_disco()

    assert repositorio.conjunto.icp_raizes == ()
    assert repositorio.conjunto.atualizadas_em is None


def test_raiz_do_pacote_fora_da_lista_fixada_nao_vira_ancora(cadeias, caplog):
    # Raiz autoassinada de verdade, com AC, posta no pacote por quem estiver no caminho (o SHA-512 vem pela
    # mesma conexão HTTP e confere).
    intrusa = pki.criar_raiz('AC Raiz Intrusa')
    ac_intrusa = intrusa.emitir_ac('AC Intrusa')
    pacote = zip_de({
        'ICP-Brasilv1.crt': cadeias['raiz'].pem,
        'ICP-Brasilv99.crt': intrusa.pem,
        'AC-Teste.crt': cadeias['ac'].pem,
        'AC-Intrusa.crt': ac_intrusa.pem,
    })
    so_a_boa = frozenset({sha256_hex(cadeias['raiz'].der)})

    with caplog.at_level(logging.WARNING, logger='validador.ancoras'):
        raizes, intermediarias = conferir_pacote_icp(pacote, hash_de(pacote), so_a_boa)

    assert [sha256_hex(d) for d in raizes] == [sha256_hex(cadeias['raiz'].der)]
    assert sha256_hex(intrusa.der) not in {sha256_hex(d) for d in raizes + intermediarias}
    assert 'raiz icp fora da lista fixada ignorada: ' + sha256_hex(intrusa.der) in caplog.text

    # Assinatura sob a raiz intrusa não fica confiável, mesmo com a AC dela vinda do pacote.
    folha = ac_intrusa.emitir_folha('INTRUSO:12345678909', cpf='12345678909')
    pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac_intrusa])
    conjunto = ConjuntoDeAncoras(icp_raizes=raizes, icp_intermediarias=intermediarias)
    resultado = conferencia.conferir(
        pdf, 'icp', conjunto, Revogacao(buscar_na_rede=False, lcrs=(ac_intrusa.lcr(),))
    )
    item = resultado['assinaturas'][0]
    assert (item['intacta'], item['valida'], item['confiavel']) == (True, True, False)
    assert item['motivo'] == 'cadeia_nao_confiavel'

    # Pacote só com raiz fora da lista: recusado inteiro (o repositório mantém a última boa).
    sozinha = zip_de({'ICP-Brasilv99.crt': intrusa.pem})
    with pytest.raises(AncoraRecusada, match='pacote_sem_raiz_fixada'):
        conferir_pacote_icp(sozinha, hash_de(sozinha), so_a_boa)


# Raízes ICP-Brasil vigentes que ficam fora por confiança mínima: v10 (SSL) e v11 (assinatura de código).
RAIZES_RETIRADAS = {
    '6e0bff069a26994c15de2c4888cc54af84882e5495b7fbf66be9ccffec7489f6',
    '1406710058180fa4081aab3f246f1702429c552a11fa3143b84c88cb3ab8e5e7',
}


def test_cadeia_sob_raiz_retirada_sai_nao_confiavel(cadeias):
    # Simula o pacote real: a raiz de documento fixada e uma raiz vigente retirada da lista (como a v10 de SSL),
    # cada uma com a sua AC. As duas ACs chegam como intermediárias; só a cadeia de documento fecha.
    raiz_ssl = pki.criar_raiz('AC Raiz Teste SSL')
    ac_ssl = raiz_ssl.emitir_ac('AC Teste SSL')
    pacote = zip_de({
        'ICP-Brasilv5.crt': cadeias['raiz'].pem,
        'ICP-Brasilv10.crt': raiz_ssl.pem,
        'AC-Teste.crt': cadeias['ac'].pem,
        'AC-SSL.crt': ac_ssl.pem,
    })
    raizes, intermediarias = conferir_pacote_icp(pacote, hash_de(pacote), frozenset({sha256_hex(cadeias['raiz'].der)}))
    conjunto = ConjuntoDeAncoras(icp_raizes=raizes, icp_intermediarias=intermediarias)
    assert sha256_hex(ac_ssl.der) in {sha256_hex(d) for d in intermediarias}

    def conferir(ac):
        folha = ac.emitir_folha('FULANO DE TAL:12345678909', cpf='12345678909')
        pdf = pdfs.assinar(pdfs.pdf_simples(), folha, cadeia=[ac])
        revogacao = Revogacao(buscar_na_rede=False, lcrs=(ac.lcr(),))
        return conferencia.conferir(pdf, 'qualquer', conjunto, revogacao)['assinaturas'][0]

    documento, ssl = conferir(cadeias['ac']), conferir(ac_ssl)

    assert (documento['valida'], documento['confiavel'], documento['ancora']) == (True, True, 'icp')
    assert (ssl['intacta'], ssl['valida'], ssl['confiavel']) == (True, True, False)
    assert ssl['ancora'] is None
    assert ssl['motivo'] == 'cadeia_nao_confiavel'


def test_raizes_fixadas_no_codigo_sao_sha256_e_valem_por_padrao():
    for impressao in ICP_RAIZES_SHA256 + GOVBR_RAIZES_SHA256:
        assert re.fullmatch(r'[0-9a-f]{64}', impressao)
    assert len(set(ICP_RAIZES_SHA256)) == len(ICP_RAIZES_SHA256) == 6
    assert not RAIZES_RETIRADAS & set(ICP_RAIZES_SHA256)
    assert len(GOVBR_RAIZES_SHA256) == 1
    padrao = Configuracao()
    assert padrao.icp_raizes_sha256 == frozenset(ICP_RAIZES_SHA256)
    assert padrao.govbr_raizes_sha256 == frozenset(GOVBR_RAIZES_SHA256)
    assert Configuracao.do_ambiente({}).icp_raizes_sha256 == frozenset(ICP_RAIZES_SHA256)


def test_impressoes_digitais_extras_pela_env():
    configuracao_do_ambiente = Configuracao.do_ambiente({
        'ASSINATURA_VALIDADOR_TOKEN': ' abc ',
        'ASSINATURA_VALIDADOR_GOVBR_SHA256': 'AA:BB:' + 'cc' * 30 + ', ,',
        'ASSINATURA_VALIDADOR_ICP_SHA256': 'DD' * 32,
    })

    assert configuracao_do_ambiente.token == b'abc'
    assert configuracao_do_ambiente.govbr_raizes_sha256 == frozenset(GOVBR_RAIZES_SHA256) | {'aabb' + 'cc' * 30}
    assert configuracao_do_ambiente.icp_raizes_sha256 == frozenset(ICP_RAIZES_SHA256) | {'dd' * 32}
