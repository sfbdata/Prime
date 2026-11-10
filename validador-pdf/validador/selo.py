"""Selo PAdES do BlueJus no PDF final (spec docs/specs/assinatura-documentos-etapa-8.md, E8-1 a E8-5).

- A chave: PKCS#12 e senha lidos a cada pedido dos `secrets` do compose (`/run/secrets/selo_pkcs12` e
  `/run/secrets/selo_pkcs12_senha`); sem eles, 503 `selo_nao_configurado`. O PHP nunca vê a chave.
- Campo invisível `SeloBlueJus`, PAdES (ETSI.CAdES.detached), acréscimo incremental sobre o PDF da etapa 3.
- Sem `ASSINATURA_SELO_TSA_URL`: PAdES B-B (sem carimbo e sem DSS). Com ACT: carimbo do tempo e a informação de
  validação embutida no DSS (B-LT); ACT que não responde = 502. Nos dois casos o certificado do selo é conferido
  antes contra as âncoras ICP-Brasil (vencido, revogado ou de fora = 503 `selo_nao_configurado`).
- Só sela PDF do próprio BlueJus (`/Producer (BlueJus)`) e sem nenhuma assinatura (nem campo vazio); senão 422.
"""

from __future__ import annotations

import asyncio
import io

from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign import signers
from pyhanko.sign.fields import SigSeedSubFilter, enumerate_sig_fields
from pyhanko.sign.general import SigningError
from pyhanko.sign.timestamps.aiohttp_client import HTTPTimeStamper
from pyhanko.sign.timestamps.common_utils import TimestampRequestError
from pyhanko_certvalidator.errors import PathBuildingError, PathValidationError, ValidationError
from pyhanko_certvalidator.policy_decl import (
    CertRevTrustPolicy,
    RevocationCheckingPolicy,
    RevocationCheckingRule,
)

from .ancoras import ConjuntoDeAncoras
from .conferencia import criar_contexto, silenciar_bibliotecas
from .configuracao import Configuracao, Revogacao
from .execucao import ErroConhecido

CAMPO_DO_SELO = 'SeloBlueJus'
PRODUTOR_DO_BLUEJUS = 'BlueJus'
TETO_DO_PKCS12 = 1024 * 1024
TETO_DA_SENHA = 4096
TEMPO_DA_ACT = 10

# Ao selar, a revogação é tolerante: LCR fora do ar não impede o selo (só não vai para o DSS); certificado
# revogado, vencido ou fora da ICP-Brasil impede (503 selo_nao_configurado).
POLITICA_DO_SELO = CertRevTrustPolicy(
    RevocationCheckingPolicy(
        ee_certificate_rule=RevocationCheckingRule.CHECK_IF_DECLARED_SOFT,
        intermediate_ca_cert_rule=RevocationCheckingRule.CHECK_IF_DECLARED_SOFT,
    )
)


def ler_credencial(configuracao: Configuracao) -> tuple[bytes, bytes] | None:
    """PKCS#12 e senha dos secrets; None se algum falta ou está vazio (selo não configurado)."""
    try:
        with open(configuracao.selo_pkcs12, 'rb') as arquivo:
            pkcs12 = arquivo.read(TETO_DO_PKCS12 + 1)
        with open(configuracao.selo_senha, 'rb') as arquivo:
            senha = arquivo.read(TETO_DA_SENHA + 1)
    except OSError:
        return None
    if not pkcs12 or len(pkcs12) > TETO_DO_PKCS12 or len(senha) > TETO_DA_SENHA:
        return None
    # A senha é o arquivo inteiro, sem a quebra de linha do fim (o editor costuma pôr uma).
    return pkcs12, senha.rstrip(b'\r\n')


def selar(
    pdf: bytes,
    pkcs12: bytes,
    senha: bytes,
    url_da_act: str,
    conjunto: ConjuntoDeAncoras,
    revogacao: Revogacao,
) -> bytes:
    """Ponto de entrada do processo filho. Devolve o PDF selado ou levanta ErroConhecido com o código."""
    silenciar_bibliotecas()
    leitor = _abrir(pdf)
    if not _selavel(leitor):
        raise ErroConhecido('nao_selavel')
    try:
        assinante = signers.SimpleSigner.load_pkcs12_data(pkcs12, other_certs=[], passphrase=senha or None)
    except (ValueError, TypeError):
        raise ErroConhecido('selo_nao_configurado') from None
    return asyncio.run(_selar(pdf, assinante, url_da_act, conjunto, revogacao))


def _abrir(pdf: bytes) -> PdfFileReader:
    try:
        leitor = PdfFileReader(io.BytesIO(pdf), strict=True)
        _ = leitor.root  # força a leitura do catálogo
    except Exception:
        raise ErroConhecido('pdf_ilegivel') from None
    return leitor


def _selavel(leitor: PdfFileReader) -> bool:
    """E8-5: PDF do BlueJus, sem criptografia e sem nenhuma assinatura (nem campo de assinatura vazio)."""
    try:
        if leitor.encrypted:
            return False
        produtor = leitor.trailer['/Info']['/Producer']
        if str(produtor) != PRODUTOR_DO_BLUEJUS:
            return False
        if '/Perms' in leitor.root:
            return False
        return not any(True for _ in enumerate_sig_fields(leitor))
    except Exception:  # sem /Info, /Producer de outro tipo, formulário quebrado...
        return False


async def _selar(pdf, assinante, url_da_act: str, conjunto: ConjuntoDeAncoras, revogacao: Revogacao) -> bytes:
    carimbador = HTTPTimeStamper(url_da_act, timeout=TEMPO_DA_ACT) if url_da_act else None
    # Sempre com contexto: o pyHanko confere o certificado do selo (validade, cadeia até uma âncora ICP-Brasil,
    # revogação tolerante) ANTES de selar, mesmo no B-B. Nunca sela com e-CNPJ vencido, revogado ou de fora.
    contexto, backend = criar_contexto(
        conjunto.icp_raizes,
        conjunto.icp_intermediarias + tuple(c.dump() for c in assinante.cert_registry),
        revogacao,
        politica=POLITICA_DO_SELO,
    )
    metadados = signers.PdfSignatureMetadata(
        field_name=CAMPO_DO_SELO,
        subfilter=SigSeedSubFilter.PADES,
        md_algorithm='sha256',
        embed_validation_info=carimbador is not None,
        validation_context=contexto,
        signer_key_usage={'digital_signature', 'non_repudiation'},
    )
    saida = io.BytesIO()
    try:
        await signers.PdfSigner(metadados, signer=assinante, timestamper=carimbador).async_sign_pdf(
            IncrementalPdfFileWriter(io.BytesIO(pdf), strict=True), output=saida
        )
    except TimestampRequestError:
        raise ErroConhecido('carimbo_indisponivel') from None
    except (SigningError, PathBuildingError, PathValidationError, ValidationError):
        # Certificado do selo vencido, revogado ou que não chega a uma âncora ICP-Brasil.
        raise ErroConhecido('selo_nao_configurado') from None
    finally:
        if backend is not None:
            await backend.close()
    return saida.getvalue()
