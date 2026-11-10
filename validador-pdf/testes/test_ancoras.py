"""Âncoras (E6-5): pacote ICP conferido pelo SHA-512, gov.br pela impressão digital, última boa no disco."""

from __future__ import annotations

import hashlib
import io
import zipfile

import pytest
from cryptography import x509
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.serialization import Encoding, pkcs7

from validador.ancoras import (
    ENTRADAS_MAXIMAS_DO_ZIP,
    TETO_DE_UM_CERTIFICADO,
    AncoraRecusada,
    RepositorioDeAncoras,
    conferir_cadeia_govbr,
    conferir_pacote_icp,
    sha256_hex,
)
from validador.configuracao import Configuracao

from . import pki

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


def configuracao(tmp_path, fixadas=()) -> Configuracao:
    return Configuracao(
        diretorio_de_dados=str(tmp_path),
        icp_url_do_pacote=URL_PACOTE,
        icp_url_do_hash=URL_HASH,
        govbr_url_da_cadeia=URL_GOVBR,
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

    raizes, intermediarias = conferir_pacote_icp(pacote, hash_de(pacote))

    assert {sha256_hex(d) for d in raizes} == {
        sha256_hex(cadeias['raiz'].der), sha256_hex(cadeias['raiz_nova'].der)
    }
    assert [sha256_hex(d) for d in intermediarias] == [sha256_hex(cadeias['ac'].der)]


def test_pacote_com_hash_que_nao_confere_e_recusado_antes_de_abrir(cadeias):
    pacote = pacote_bom(cadeias)

    with pytest.raises(AncoraRecusada, match='hash_nao_confere'):
        conferir_pacote_icp(pacote, hash_de(pacote + b'x'))
    with pytest.raises(AncoraRecusada, match='hash_ilegivel'):
        conferir_pacote_icp(pacote, b'')


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

    with pytest.raises(AncoraRecusada, match='pacote_sem_raiz'):
        conferir_pacote_icp(pacote, hash_de(pacote))

    # Ao lado de uma raiz boa, o impostor não entra nem como intermediária.
    misto = zip_de({'impostor.crt': certificado.public_bytes(Encoding.PEM), 'boa.crt': cadeias['raiz'].pem})
    raizes, intermediarias = conferir_pacote_icp(misto, hash_de(misto))
    assert [sha256_hex(d) for d in raizes] == [sha256_hex(cadeias['raiz'].der)]
    assert intermediarias == ()


def test_zip_hostil_e_recusado(cadeias):
    muitas = zip_de({f'{i}.crt': b'x' for i in range(ENTRADAS_MAXIMAS_DO_ZIP + 1)})
    grande = zip_de({'grande.crt': b'0' * (TETO_DE_UM_CERTIFICADO + 1)})
    lixo = b'nao e zip'

    for pacote, codigo in ((muitas, 'zip_com_entradas_demais'), (grande, 'certificado_grande_demais'),
                           (lixo, 'zip_ilegivel')):
        with pytest.raises(AncoraRecusada, match=codigo):
            conferir_pacote_icp(pacote, hash_de(pacote))


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
    repositorio = RepositorioDeAncoras(configuracao(tmp_path, {fixada}), baixar=rede)

    assert repositorio.atualizar() is True

    conjunto = repositorio.conjunto
    assert (len(conjunto.icp_raizes), len(conjunto.icp_intermediarias)) == (2, 1)
    assert (len(conjunto.govbr_raizes), len(conjunto.govbr_intermediarias)) == (1, 1)
    assert conjunto.atualizadas_em is not None
    assert (tmp_path / 'icp' / 'ACcompactado.zip').read_bytes() == pacote

    # Sobe de novo sem rede: a última boa vem do volume, conferida outra vez.
    outro = RepositorioDeAncoras(configuracao(tmp_path, {fixada}), baixar=Rede({}))
    outro.carregar_do_disco()
    assert outro.conjunto == conjunto


def test_download_que_falha_ou_nao_confere_mantem_a_ultima_boa(tmp_path, cadeias):
    pacote = pacote_bom(cadeias)
    repositorio = RepositorioDeAncoras(
        configuracao(tmp_path), baixar=Rede({URL_HASH: hash_de(pacote), URL_PACOTE: pacote})
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

    recarregado = RepositorioDeAncoras(configuracao(tmp_path), baixar=Rede({}))
    recarregado.carregar_do_disco()
    assert recarregado.conjunto.icp_raizes == boa.icp_raizes


def test_sem_raiz_govbr_fixada_nao_baixa_a_cadeia_govbr(tmp_path, cadeias):
    pacote = pacote_bom(cadeias)
    rede = Rede({URL_HASH: hash_de(pacote), URL_PACOTE: pacote})
    repositorio = RepositorioDeAncoras(configuracao(tmp_path), baixar=rede)

    assert repositorio.atualizar() is True

    assert URL_GOVBR not in rede.pedidos
    assert repositorio.conjunto.govbr_raizes == ()


def test_sem_nada_no_disco_comeca_vazio(tmp_path):
    repositorio = RepositorioDeAncoras(configuracao(tmp_path), baixar=Rede({}))

    repositorio.carregar_do_disco()

    assert repositorio.conjunto.icp_raizes == ()
    assert repositorio.conjunto.atualizadas_em is None


def test_impressao_digital_govbr_pela_env():
    configuracao_do_ambiente = Configuracao.do_ambiente({
        'ASSINATURA_VALIDADOR_TOKEN': ' abc ',
        'ASSINATURA_VALIDADOR_GOVBR_SHA256': 'AA:BB:' + 'cc' * 30 + ', ,',
    })

    assert configuracao_do_ambiente.token == b'abc'
    assert configuracao_do_ambiente.govbr_raizes_sha256 == frozenset({'aabb' + 'cc' * 30})
