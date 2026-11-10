"""Âncoras de confiança (E6-5): ICP-Brasil do pacote oficial do ITI e gov.br pela impressão digital fixada.

- ICP-Brasil: `ACcompactado.zip`, conferido pelo `hashsha512.txt` publicado ao lado ANTES de abrir o zip (isso só
  pega corrupção: o hash vem pela mesma conexão HTTP). Só vira raiz o certificado autoassinado (assinatura própria
  conferida) cuja impressão digital SHA-256 está na lista fixada (`Configuracao.icp_raizes_sha256`) e fora das
  recusadas no código (`RAIZES_RECUSADAS_SHA256`, v10 e v11); autoassinado fora da lista é ignorado com aviso no log.
  Os de emissor diferente são intermediárias (o pyHanko confere a cadeia até uma raiz fixada); emissor igual ao
  titular sem a assinatura própria conferir fica de fora.
- gov.br: a cadeia publicada pelo ITI (PKCS#7). Só vira raiz o certificado autoassinado cuja impressão digital
  SHA-256 está na lista fixada (`Configuracao.govbr_raizes_sha256`); lista vazia = nenhuma raiz gov.br e nenhum
  download.
- Atualização ao subir e a cada 24 h (1 h depois de uma falha). Download que falha ou não confere mantém a última
  boa, guardada no diretório de dados (volume `validador_ancoras`). Nada aqui vem do PDF conferido. Família sem
  nenhuma raiz (primeira subida sem rede, volume perdido): o servidor responde 503 `ancoras_indisponiveis`.
- O zip é lido em memória, com limites de tamanho e de quantidade: nada é extraído para o disco.
"""

from __future__ import annotations

import hashlib
import hmac
import io
import json
import logging
import os
import threading
import urllib.request
import zipfile
from collections.abc import Callable
from dataclasses import dataclass
from datetime import datetime, timezone

from asn1crypto import cms
from asn1crypto import pem as asn1_pem
from cryptography import x509
from cryptography.hazmat.primitives.serialization import Encoding

from .configuracao import (
    INTERVALO_DE_ATUALIZACAO,
    INTERVALO_DEPOIS_DE_FALHA,
    RAIZES_RECUSADAS_SHA256,
    Configuracao,
)

log = logging.getLogger('validador.ancoras')

TETO_DO_PACOTE = 16 * 1024 * 1024
TETO_DO_HASH = 4 * 1024
TETO_DA_CADEIA_GOVBR = 1024 * 1024
ENTRADAS_MAXIMAS_DO_ZIP = 2000
TETO_DE_UM_CERTIFICADO = 256 * 1024
TETO_DESCOMPACTADO = 32 * 1024 * 1024
TEMPO_DO_DOWNLOAD = 60

Baixador = Callable[[str, int], bytes]


class AncoraRecusada(Exception):
    """O material baixado (ou guardado) não passou na conferência; o código vai para o log."""


@dataclass(frozen=True)
class ConjuntoDeAncoras:
    """Certificados em DER. Imutável: o servidor troca o conjunto inteiro de uma vez."""

    icp_raizes: tuple[bytes, ...] = ()
    icp_intermediarias: tuple[bytes, ...] = ()
    govbr_raizes: tuple[bytes, ...] = ()
    govbr_intermediarias: tuple[bytes, ...] = ()
    atualizadas_em: str | None = None

    def raizes_por_familia(self) -> dict[str, int]:
        return {'icp': len(self.icp_raizes), 'govbr': len(self.govbr_raizes)}

    def familias_sem_raiz(self, familias: tuple[str, ...] = ('icp', 'govbr')) -> list[str]:
        """Das famílias dadas, as que estão sem nenhuma raiz carregada (volume vazio, perdido ou ITI fora do ar).

        Conferir com uma família vazia daria `cadeia_nao_confiavel` a uma assinatura legítima; por isso o servidor
        responde 503 `ancoras_indisponiveis` (passageiro) antes de conferir ou selar.
        """
        contagem = self.raizes_por_familia()
        return [familia for familia in familias if not contagem[familia]]


def sha256_hex(der: bytes) -> str:
    return hashlib.sha256(der).hexdigest()


def baixar_http(url: str, teto: int) -> bytes:
    """Baixa até `teto` bytes; acima disso, recusa (nunca lê sem limite)."""
    if not url.startswith(('http://', 'https://')):
        raise AncoraRecusada('url_invalida')
    requisicao = urllib.request.Request(url, headers={'User-Agent': 'BlueJus-validador-pdf/1'})
    with urllib.request.urlopen(requisicao, timeout=TEMPO_DO_DOWNLOAD) as resposta:  # noqa: S310 (esquema conferido acima)
        dados = resposta.read(teto + 1)
    if len(dados) > teto:
        raise AncoraRecusada('download_grande_demais')
    return dados


def _certificados_do_arquivo(dados: bytes) -> list[x509.Certificate]:
    if b'-----BEGIN CERTIFICATE-----' in dados:
        return x509.load_pem_x509_certificates(dados)
    return [x509.load_der_x509_certificate(dados)]


RAIZ = 'raiz'
INTERMEDIARIA = 'intermediaria'


def _classificar(certificado: x509.Certificate) -> str | None:
    """Raiz = autoassinado com a assinatura própria conferida. Emissor igual ao titular sem a assinatura
    conferir (falsificado, ou algoritmo que a biblioteca não lê, como a raiz v7 da ICP-Brasil em 11/2026)
    não entra de jeito nenhum: nem raiz, nem intermediária."""
    if certificado.subject != certificado.issuer:
        return INTERMEDIARIA
    try:
        certificado.verify_directly_issued_by(certificado)
    except Exception:
        return None
    return RAIZ


def _der(certificado: x509.Certificate) -> bytes:
    return certificado.public_bytes(Encoding.DER)


def conferir_pacote_icp(
    pacote: bytes,
    conteudo_do_hash: bytes,
    fixadas: frozenset[str],
    recusadas: frozenset[str] = RAIZES_RECUSADAS_SHA256,
) -> tuple[tuple[bytes, ...], tuple[bytes, ...]]:
    """Confere o SHA-512 publicado e separa raízes (autoassinadas e fixadas) de intermediárias.

    Devolve (raízes, intermediárias). Raiz autoassinada fora de `fixadas`, ou em `recusadas` (mesmo que esteja em
    `fixadas`), não entra nem como intermediária.
    """
    try:
        esperado = conteudo_do_hash.decode('ascii').split()[0].lower()
    except (UnicodeDecodeError, IndexError):
        raise AncoraRecusada('hash_ilegivel') from None
    if len(esperado) != 128 or not hmac.compare_digest(esperado, hashlib.sha512(pacote).hexdigest()):
        raise AncoraRecusada('hash_nao_confere')

    raizes: dict[str, bytes] = {}
    intermediarias: dict[str, bytes] = {}
    ignoradas: set[str] = set()
    try:
        with zipfile.ZipFile(io.BytesIO(pacote)) as arquivo:
            entradas = [e for e in arquivo.infolist() if not e.is_dir()]
            if len(entradas) > ENTRADAS_MAXIMAS_DO_ZIP:
                raise AncoraRecusada('zip_com_entradas_demais')
            total = 0
            for entrada in entradas:
                if not entrada.filename.lower().endswith(('.crt', '.cer', '.pem', '.der')):
                    continue
                with arquivo.open(entrada) as fluxo:
                    dados = fluxo.read(TETO_DE_UM_CERTIFICADO + 1)
                if len(dados) > TETO_DE_UM_CERTIFICADO:
                    raise AncoraRecusada('certificado_grande_demais')
                total += len(dados)
                if total > TETO_DESCOMPACTADO:
                    raise AncoraRecusada('zip_grande_demais')
                try:
                    certificados = _certificados_do_arquivo(dados)
                except ValueError:
                    continue  # arquivo que não é certificado: ignorado, não derruba o pacote
                for certificado in certificados:
                    classe = _classificar(certificado)
                    if classe is None:
                        continue
                    der = _der(certificado)
                    impressao = sha256_hex(der)
                    if classe == INTERMEDIARIA:
                        intermediarias[impressao] = der
                    elif impressao in recusadas:
                        if impressao not in ignoradas:
                            ignoradas.add(impressao)
                            log.info('raiz icp recusada no codigo ignorada: %s', impressao)
                    elif impressao in fixadas:
                        raizes[impressao] = der
                    elif impressao not in ignoradas:
                        ignoradas.add(impressao)
                        log.warning(
                            'raiz icp fora da lista fixada ignorada: %s %s',
                            impressao, certificado.subject.rfc4514_string()[:120],
                        )
    except zipfile.BadZipFile:
        raise AncoraRecusada('zip_ilegivel') from None
    if not raizes:
        raise AncoraRecusada('pacote_sem_raiz_fixada')
    return tuple(raizes.values()), tuple(intermediarias.values())


def conferir_cadeia_govbr(
    cadeia: bytes, fixadas: frozenset[str], recusadas: frozenset[str] = RAIZES_RECUSADAS_SHA256
) -> tuple[tuple[bytes, ...], tuple[bytes, ...]]:
    """Raiz só com impressão digital fixada (e não recusada) e assinatura própria conferida; os de emissor
    diferente viram intermediárias."""
    # O p7b do ITI não é DER estrito (ordem do SET): lido pelo asn1crypto, que aceita BER.
    try:
        if cadeia.lstrip().startswith(b'-----BEGIN'):
            _, _, cadeia = asn1_pem.unarmor(cadeia.strip())
        conteudo = cms.ContentInfo.load(cadeia)
        certificados = [
            x509.load_der_x509_certificate(escolha.chosen.dump())
            for escolha in conteudo['content']['certificates']
            if escolha.name == 'certificate'
        ]
    except Exception:
        raise AncoraRecusada('cadeia_govbr_ilegivel') from None
    raizes: dict[str, bytes] = {}
    intermediarias: dict[str, bytes] = {}
    for certificado in certificados:
        classe = _classificar(certificado)
        der = _der(certificado)
        impressao = sha256_hex(der)
        if classe == RAIZ and impressao in fixadas and impressao not in recusadas:
            raizes[impressao] = der
        elif classe == INTERMEDIARIA:
            intermediarias[impressao] = der
    if not raizes:
        raise AncoraRecusada('cadeia_govbr_sem_raiz_fixada')
    return tuple(raizes.values()), tuple(intermediarias.values())


def _agora_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


class RepositorioDeAncoras:
    """Guarda o conjunto em uso e a última versão boa no disco; atualiza em segundo plano."""

    def __init__(self, configuracao: Configuracao, baixar: Baixador = baixar_http):
        self._configuracao = configuracao
        self._baixar = baixar
        self._conjunto = ConjuntoDeAncoras()
        self._trava = threading.Lock()
        self._parar = threading.Event()
        self._fio: threading.Thread | None = None

    @property
    def conjunto(self) -> ConjuntoDeAncoras:
        return self._conjunto

    def definir(self, conjunto: ConjuntoDeAncoras) -> None:
        """Troca o conjunto em uso (usado também pelos testes)."""
        self._conjunto = conjunto

    # Disco -------------------------------------------------------------------------------------------------

    def _caminho(self, *partes: str) -> str:
        return os.path.join(self._configuracao.diretorio_de_dados, *partes)

    def _gravar(self, caminho: str, dados: bytes) -> None:
        os.makedirs(os.path.dirname(caminho), exist_ok=True)
        temporario = caminho + '.novo'
        with open(temporario, 'wb') as arquivo:
            arquivo.write(dados)
            arquivo.flush()
            os.fsync(arquivo.fileno())
        os.replace(temporario, caminho)

    def _ler(self, caminho: str) -> bytes | None:
        try:
            with open(caminho, 'rb') as arquivo:
                return arquivo.read(TETO_DO_PACOTE + 1)
        except FileNotFoundError:
            return None

    def carregar_do_disco(self) -> None:
        """Carrega a última versão boa guardada, conferindo de novo (o volume não é fonte de confiança)."""
        icp_raizes: tuple[bytes, ...] = ()
        icp_intermediarias: tuple[bytes, ...] = ()
        govbr_raizes: tuple[bytes, ...] = ()
        govbr_intermediarias: tuple[bytes, ...] = ()
        atualizadas_em = None
        pacote = self._ler(self._caminho('icp', 'ACcompactado.zip'))
        hash_publicado = self._ler(self._caminho('icp', 'hashsha512.txt'))
        if pacote is not None and hash_publicado is not None:
            try:
                icp_raizes, icp_intermediarias = conferir_pacote_icp(
                    pacote, hash_publicado, self._configuracao.icp_raizes_sha256
                )
            except AncoraRecusada as erro:
                log.warning('ancoras icp guardadas recusadas: %s', erro)
        cadeia = self._ler(self._caminho('govbr', 'cadeia.p7b'))
        if cadeia is not None and self._configuracao.govbr_raizes_sha256:
            try:
                govbr_raizes, govbr_intermediarias = conferir_cadeia_govbr(
                    cadeia, self._configuracao.govbr_raizes_sha256
                )
            except AncoraRecusada as erro:
                log.warning('ancoras govbr guardadas recusadas: %s', erro)
        estado = self._ler(self._caminho('estado.json'))
        if estado is not None:
            try:
                atualizadas_em = json.loads(estado).get('atualizadas_em')
            except (ValueError, AttributeError):
                atualizadas_em = None
        self._conjunto = ConjuntoDeAncoras(
            icp_raizes, icp_intermediarias, govbr_raizes, govbr_intermediarias, atualizadas_em
        )
        self._registrar('carregadas do disco')

    # Download ----------------------------------------------------------------------------------------------

    def atualizar(self) -> bool:
        """Baixa e confere as duas fontes. Devolve True se todas as fontes configuradas deram certo."""
        with self._trava:
            atual = self._conjunto
            icp = (atual.icp_raizes, atual.icp_intermediarias)
            govbr = (atual.govbr_raizes, atual.govbr_intermediarias)
            tudo_certo = True
            try:
                hash_publicado = self._baixar(self._configuracao.icp_url_do_hash, TETO_DO_HASH)
                pacote = self._baixar(self._configuracao.icp_url_do_pacote, TETO_DO_PACOTE)
                icp = conferir_pacote_icp(pacote, hash_publicado, self._configuracao.icp_raizes_sha256)
                self._guardar(('icp', 'ACcompactado.zip', pacote), ('icp', 'hashsha512.txt', hash_publicado))
            except Exception as erro:  # rede, hash, zip: mantém a última boa
                tudo_certo = False
                log.warning('falha ao atualizar ancoras icp: %s', _codigo(erro))
            if self._configuracao.govbr_raizes_sha256:
                try:
                    cadeia = self._baixar(self._configuracao.govbr_url_da_cadeia, TETO_DA_CADEIA_GOVBR)
                    govbr = conferir_cadeia_govbr(cadeia, self._configuracao.govbr_raizes_sha256)
                    self._guardar(('govbr', 'cadeia.p7b', cadeia))
                except Exception as erro:
                    tudo_certo = False
                    log.warning('falha ao atualizar ancoras govbr: %s', _codigo(erro))
            else:
                govbr = ((), ())
            atualizadas_em = atual.atualizadas_em
            if tudo_certo:
                atualizadas_em = _agora_iso()
                self._guardar(('estado.json', None, json.dumps({'atualizadas_em': atualizadas_em}).encode()))
            self._conjunto = ConjuntoDeAncoras(icp[0], icp[1], govbr[0], govbr[1], atualizadas_em)
            self._registrar('atualizadas' if tudo_certo else 'mantidas com falha parcial')
            return tudo_certo

    def _guardar(self, *arquivos: tuple[str, str | None, bytes]) -> None:
        for pasta, nome, dados in arquivos:
            caminho = self._caminho(pasta) if nome is None else self._caminho(pasta, nome)
            try:
                self._gravar(caminho, dados)
            except OSError as erro:  # disco cheio ou só de leitura: segue com a versão em memória
                log.warning('falha ao guardar ancoras: %s', type(erro).__name__)

    def _registrar(self, evento: str) -> None:
        c = self._conjunto
        log.info(
            'ancoras %s: icp=%d raizes/%d intermediarias govbr=%d raizes/%d intermediarias atualizadas_em=%s',
            evento,
            len(c.icp_raizes),
            len(c.icp_intermediarias),
            len(c.govbr_raizes),
            len(c.govbr_intermediarias),
            c.atualizadas_em,
        )

    # Laço em segundo plano ---------------------------------------------------------------------------------

    def iniciar(self) -> None:
        self.carregar_do_disco()
        if not self._configuracao.atualizar_ancoras:
            return
        self._fio = threading.Thread(target=self._laco, name='atualizacao-das-ancoras', daemon=True)
        self._fio.start()

    def parar(self) -> None:
        self._parar.set()

    def _laco(self) -> None:
        while not self._parar.is_set():
            try:
                certo = self.atualizar()
            except Exception as erro:  # nunca derruba o fio de atualização
                certo = False
                log.warning('falha inesperada ao atualizar ancoras: %s', _codigo(erro))
            self._parar.wait(INTERVALO_DE_ATUALIZACAO if certo else INTERVALO_DEPOIS_DE_FALHA)


def _codigo(erro: BaseException) -> str:
    if isinstance(erro, AncoraRecusada):
        return str(erro)
    return type(erro).__name__
