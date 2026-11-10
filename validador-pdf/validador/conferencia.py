"""Conferência das assinaturas de um PDF com o pyHanko (E6-5, E6-6 e protocolo v1 da spec da etapa 6).

Roda no processo filho (`execucao.py`). Para cada assinatura comum (/Sig) do PDF:
- integridade, cobertura e análise de diferenças do pyHanko (nível de modificação e DocMDP), que pega também a
  página redefinida depois da assinatura e o objeto preparado numa revisão e ativado noutra (sombra);
- cadeia até as âncoras do nível pedido (raízes SÓ do conjunto baixado e conferido; intermediárias em
  `other_certs`; nada do PDF vira raiz), revogação obrigatória para o signatário quando o certificado declara
  LCR ou OCSP (hard-fail) e tolerante para as ACs de cima; signatário que não declara nenhuma das duas sai
  confiável, mas com `revogacao=desconhecida` e `motivo=revogacao_nao_declarada` (o PHP só aceita `boa`);
- LCR e OCSP buscados uma vez por pedido: um backend e um cache para todas as assinaturas e carimbos do PDF;
- carimbo do tempo da assinatura, conferido contra todas as âncoras (ICP e gov.br);
- titular: CN, CPF (otherName 2.16.76.1.3.1) e CNPJ (2.16.76.1.3.3), só o que o certificado declara.
Os campos de saída seguem o JSON v1 da spec; `motivo` é um código, nunca um texto para o usuário.
"""

from __future__ import annotations

import asyncio
import io
import logging
import warnings

from asn1crypto import core
from asn1crypto import x509 as asn1_x509
from cryptography import x509
from cryptography.x509.oid import NameOID
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.diff_analysis import ModificationLevel
from pyhanko.sign.validation import SignatureCoverageLevel, async_validate_pdf_signature
from pyhanko.sign.validation.settings import KeyUsageConstraints
from pyhanko_certvalidator import ValidationContext
from pyhanko_certvalidator.fetchers.aiohttp_fetchers import AIOHttpFetcherBackend
from pyhanko_certvalidator.fetchers.api import Fetchers, FetchLimits
from pyhanko_certvalidator.policy_decl import (
    CertRevTrustPolicy,
    RevocationCheckingPolicy,
    RevocationCheckingRule,
)

from .ancoras import ConjuntoDeAncoras, sha256_hex
from .configuracao import Revogacao
from .execucao import ErroConhecido

ANCORAS_ACEITAS = ('icp', 'govbr', 'qualquer')

# Famílias de âncora que cada valor de `ancora` usa. Família pedida sem raiz carregada = 503
# `ancoras_indisponiveis` (o servidor responde antes de conferir; ver `ConjuntoDeAncoras.familias_sem_raiz`).
FAMILIAS_DA_ANCORA = {'icp': ('icp',), 'govbr': ('govbr',), 'qualquer': ('icp', 'govbr')}

OID_CPF = '2.16.76.1.3.1'
OID_CNPJ = '2.16.76.1.3.3'

# Revogação: hard-fail no signatário (declarou LCR/OCSP e não deu para consultar = não confiável) e
# soft-fail nas ACs intermediárias (uma LCR de AC fora do ar não derruba, uma AC revogada derruba).
POLITICA_DE_REVOGACAO = CertRevTrustPolicy(
    RevocationCheckingPolicy(
        ee_certificate_rule=RevocationCheckingRule.CHECK_IF_DECLARED,
        intermediate_ca_cert_rule=RevocationCheckingRule.CHECK_IF_DECLARED_SOFT,
    )
)

# gov.br e ICP-Brasil usam assinatura digital e/ou não repúdio; basta um dos dois.
USO_DA_CHAVE = KeyUsageConstraints(key_usage={'digital_signature', 'non_repudiation'}, match_all_key_usages=False)

COBERTURA = {
    SignatureCoverageLevel.ENTIRE_FILE: 'arquivo_inteiro',
    SignatureCoverageLevel.ENTIRE_REVISION: 'revisao_inteira',
    SignatureCoverageLevel.CONTIGUOUS_BLOCK_FROM_START: 'parcial',
    SignatureCoverageLevel.UNCLEAR: 'parcial',
}

NIVEL_DE_MODIFICACAO = {
    ModificationLevel.NONE: 'nenhuma',
    ModificationLevel.LTA_UPDATES: 'ltv',
    ModificationLevel.FORM_FILLING: 'formulario',
    ModificationLevel.ANNOTATIONS: 'anotacoes',
    ModificationLevel.OTHER: 'outra',
}

# Subindicação AdES do pyHanko -> código do `motivo`.
MOTIVO_DA_CONFIANCA = {
    'REVOKED': 'certificado_revogado',
    'REVOKED_NO_POE': 'certificado_revogado',
    'REVOKED_CA_NO_POE': 'certificado_revogado',
    'NO_CERTIFICATE_CHAIN_FOUND': 'cadeia_nao_confiavel',
    'EXPIRED': 'certificado_vencido',
    'OUT_OF_BOUNDS_NO_POE': 'certificado_vencido',
    'OUT_OF_BOUNDS_NOT_REVOKED': 'certificado_vencido',
    'NOT_YET_VALID': 'certificado_ainda_nao_valido',
    'TRY_LATER': 'revogacao_indisponivel',
    'REVOCATION_OUT_OF_BOUNDS_NO_POE': 'revogacao_indisponivel',
    'CRYPTO_CONSTRAINTS_FAILURE': 'algoritmo_recusado',
    'CRYPTO_CONSTRAINTS_FAILURE_NO_POE': 'algoritmo_recusado',
    'SIG_CONSTRAINTS_FAILURE': 'restricao_do_certificado',
    'CHAIN_CONSTRAINTS_FAILURE': 'restricao_do_certificado',
}


class _TiposDeErro(logging.Handler):
    """Guarda só o NOME da classe da exceção que o pyHanko registra (nunca a mensagem, que traz o titular).

    O pyHanko junta falta de LCR/OCSP e assinatura de AC que não confere na mesma subindicação
    (CERTIFICATE_CHAIN_GENERAL_FAILURE); o tipo da exceção separa `revogacao_indisponivel` de cadeia com falha.
    """

    def __init__(self):
        super().__init__(logging.WARNING)
        self.tipos: list[str] = []

    def emit(self, registro: logging.LogRecord) -> None:
        if registro.exc_info and registro.exc_info[1] is not None:
            self.tipos.append(type(registro.exc_info[1]).__name__)


_tipos_de_erro = _TiposDeErro()

ERROS_DE_REVOGACAO = {'InsufficientRevinfoError', 'StaleRevinfoError'}


def silenciar_bibliotecas() -> None:
    """As bibliotecas registram nomes de certificado e trechos do PDF: nada disso vai para o log."""
    for nome in ('pyhanko_certvalidator', 'aiohttp', 'asyncio', 'fontTools'):
        logging.getLogger(nome).setLevel(logging.CRITICAL + 1)
    registro_do_pyhanko = logging.getLogger('pyhanko')
    registro_do_pyhanko.setLevel(logging.WARNING)
    registro_do_pyhanko.propagate = False
    registro_do_pyhanko.handlers = [_tipos_de_erro]
    warnings.simplefilter('ignore')


def conferir(pdf: bytes, ancora: str, conjunto: ConjuntoDeAncoras, revogacao: Revogacao) -> dict:
    """Ponto de entrada do processo filho. Levanta ErroConhecido('pdf_ilegivel') se o PDF não abre."""
    silenciar_bibliotecas()
    if ancora not in ANCORAS_ACEITAS:
        raise ErroConhecido('parametro_invalido')
    return asyncio.run(_conferir(pdf, ancora, conjunto, revogacao))


def _abrir(pdf: bytes) -> tuple[PdfFileReader, list]:
    try:
        leitor = PdfFileReader(io.BytesIO(pdf), strict=True)
        if leitor.encrypted:
            leitor.decrypt(b'')
        assinaturas = list(leitor.embedded_regular_signatures)
    except Exception:  # qualquer falha de leitura do arquivo é "ilegível"; o detalhe não sai do filho
        raise ErroConhecido('pdf_ilegivel') from None
    return leitor, assinaturas


def _confianca(ancora: str, conjunto: ConjuntoDeAncoras) -> tuple[tuple[bytes, ...], tuple[bytes, ...]]:
    if ancora == 'icp':
        return conjunto.icp_raizes, conjunto.icp_intermediarias
    if ancora == 'govbr':
        return conjunto.govbr_raizes, conjunto.govbr_intermediarias
    return (
        conjunto.icp_raizes + conjunto.govbr_raizes,
        conjunto.icp_intermediarias + conjunto.govbr_intermediarias,
    )


def criar_busca(
    revogacao: Revogacao, assinaturas: int = 1
) -> tuple[AIOHttpFetcherBackend | None, Fetchers | None]:
    """Backend e buscadores de LCR e OCSP de UM pedido; (None, None) sem busca na rede.

    Os mesmos buscadores vão para todos os contextos do pedido (cada assinatura e cada carimbo do PDF): o cache
    deles (por certificado e por URL) evita baixar de novo a mesma LCR, e o teto de requisições vale para o pedido
    inteiro, `requisicoes_maximas` por assinatura (o que cada uma tinha antes do cache compartilhado).
    Quem chama fecha o backend (`await backend.close()`).
    """
    if not revogacao.buscar_na_rede:
        return None, None
    # Sem buscar certificado de emissor (AIA): as URLs viriam de certificados ainda não confiáveis.
    # LCR e OCSP só são buscados para certificados de um caminho que já chegou a uma âncora.
    backend = AIOHttpFetcherBackend(
        per_request_timeout=revogacao.tempo_por_requisicao,
        limits=FetchLimits(
            max_requests=revogacao.requisicoes_maximas * max(1, assinaturas),
            max_cert_requests=0,
            max_aia_urls_per_object=0,
        ),
    )
    return backend, backend.get_fetchers()


def criar_contexto(
    raizes: tuple[bytes, ...],
    intermediarias: tuple[bytes, ...],
    revogacao: Revogacao,
    busca: Fetchers | None,
    politica: CertRevTrustPolicy = POLITICA_DE_REVOGACAO,
) -> ValidationContext:
    """Contexto do pyHanko: raízes só as âncoras dadas (lista vazia = nenhuma, nunca as do sistema).

    `busca`: os buscadores de `criar_busca` (obrigatórios com busca na rede, para nunca cair no backend padrão).
    """
    if revogacao.buscar_na_rede and busca is None:
        raise ValueError('busca na rede sem os buscadores do pedido (criar_busca)')
    return ValidationContext(
        trust_roots=[asn1_x509.Certificate.load(der) for der in raizes],
        other_certs=[asn1_x509.Certificate.load(der) for der in intermediarias],
        allow_fetching=revogacao.buscar_na_rede,
        crls=list(revogacao.lcrs),
        ocsps=list(revogacao.ocsps),
        revinfo_policy=politica,
        fetchers=busca if revogacao.buscar_na_rede else None,
    )


async def _conferir(pdf: bytes, ancora: str, conjunto: ConjuntoDeAncoras, revogacao: Revogacao) -> dict:
    _, assinaturas = _abrir(pdf)
    raizes, intermediarias = _confianca(ancora, conjunto)
    familia = {sha256_hex(der): 'icp' for der in conjunto.icp_raizes}
    familia.update({sha256_hex(der): 'govbr' for der in conjunto.govbr_raizes})
    todas_as_raizes = conjunto.icp_raizes + conjunto.govbr_raizes
    todas_as_intermediarias = conjunto.icp_intermediarias + conjunto.govbr_intermediarias
    itens = []
    backend, busca = criar_busca(revogacao, len(assinaturas))
    try:
        for assinatura in assinaturas:
            contexto = criar_contexto(raizes, intermediarias, revogacao, busca)
            contexto_do_carimbo = criar_contexto(todas_as_raizes, todas_as_intermediarias, revogacao, busca)
            itens.append(await _conferir_uma(assinatura, contexto, contexto_do_carimbo, familia))
    finally:
        if backend is not None:
            await backend.close()
    return {'v': 1, 'assinaturas': itens}


async def _conferir_uma(assinatura, contexto, contexto_do_carimbo, familia: dict[str, str]) -> dict:
    faixa = _faixa(assinatura)
    if faixa is None:
        # /ByteRange que não são 4 inteiros: não há o que conferir nem como casar com o motor (o PHP casa pela
        # faixa). Sai ilegível: `faixa` nula, `intacta` falsa e `motivo=assinatura_ilegivel`.
        return _item_ilegivel(assinatura, None)
    _tipos_de_erro.tipos.clear()
    try:
        status = await async_validate_pdf_signature(
            assinatura,
            signer_validation_context=contexto,
            ts_validation_context=contexto_do_carimbo,
            key_usage_settings=USO_DA_CHAVE,
        )
    except Exception:  # assinatura que o pyHanko não consegue ler: nada confere
        return _item_ilegivel(assinatura, faixa)

    caminho = status.validation_path
    certificados = list(caminho.iter_certs(include_root=True)) if caminho is not None else []
    raiz = sha256_hex(certificados[0].dump()) if certificados else None
    confiavel = bool(status.trusted)
    cobertura = COBERTURA.get(status.coverage, 'parcial')
    nivel = NIVEL_DE_MODIFICACAO.get(status.modification_level, 'outra')
    carimbo = _carimbo(assinatura, status)
    revogacao = _revogacao(status, confiavel)
    item = {
        'campo': assinatura.field_name,
        'faixa': faixa,
        'intacta': bool(status.intact),
        'valida': bool(status.valid),
        'confiavel': confiavel,
        'ancora': familia.get(raiz) if confiavel and raiz else None,
        'titular': titular(status.signing_cert),
        # Do titular até a raiz, só quando confiável; senão, só o titular (o caminho candidato engana).
        'cadeia': [_elo(c) for c in reversed(certificados)] if confiavel else [_elo(status.signing_cert)],
        'revogacao': revogacao,
        'carimbo': carimbo,
        'cobertura': cobertura,
        'nivel_de_modificacao': nivel,
        'docmdp_ok': status.docmdp_ok,
        'motivo': None,
    }
    item['motivo'] = _motivo(item, status, list(_tipos_de_erro.tipos))
    return item


def _faixa(assinatura) -> list[int] | None:
    """O /ByteRange como 4 inteiros não negativos; qualquer outra forma (null, texto, nomes, número real, mais ou
    menos de 4 números) vira None. Nunca arredonda: a faixa devolvida é a do arquivo ou nenhuma."""
    try:
        valores = list(assinatura.byte_range)
    except Exception:
        return None
    if len(valores) != 4 or not all(isinstance(v, int) and not isinstance(v, bool) and v >= 0 for v in valores):
        return None
    return [int(v) for v in valores]


def _item_ilegivel(assinatura, faixa) -> dict:
    try:
        dono = titular(assinatura.signer_cert)
        cadeia = [_elo(assinatura.signer_cert)]
    except Exception:
        dono = {'cn': None, 'cpf': None, 'cnpj': None}
        cadeia = []
    return {
        'campo': getattr(assinatura, 'field_name', None),
        'faixa': faixa,
        'intacta': False,
        'valida': False,
        'confiavel': False,
        'ancora': None,
        'titular': dono,
        'cadeia': cadeia,
        'revogacao': 'desconhecida',
        'carimbo': {'presente': False, 'valido': False, 'hora': None},
        'cobertura': 'parcial',
        'nivel_de_modificacao': 'outra',
        'docmdp_ok': None,
        'motivo': 'assinatura_ilegivel',
    }


def _carimbo(assinatura, status) -> dict:
    validade = status.timestamp_validity
    if validade is None:
        # Sem status do carimbo: ou não há carimbo, ou há um que não deu para ler (presente e inválido).
        atributos = assinatura.signer_info['unsigned_attrs']
        presente = atributos.native is not None and any(
            a['type'].native == 'signature_time_stamp_token' for a in atributos
        )
        return {'presente': presente, 'valido': False, 'hora': None}
    valido = bool(validade.intact and validade.valid and validade.trusted)
    hora = validade.timestamp.isoformat() if validade.timestamp is not None else None
    return {'presente': True, 'valido': valido, 'hora': hora}


def _declara_revogacao(certificado: asn1_x509.Certificate) -> bool:
    return bool(certificado.crl_distribution_points or certificado.ocsp_urls)


def _revogacao(status, confiavel: bool) -> str:
    if status.revoked:
        return 'revogada'
    if confiavel and _declara_revogacao(status.signing_cert):
        # Com hard-fail no signatário, confiável e com LCR/OCSP declarado = consultado e não revogado.
        return 'boa'
    return 'desconhecida'


def _motivo(item: dict, status, erros: list[str]) -> str | None:
    """O primeiro problema, nesta ordem: integridade, assinatura, confiança (com a revogação), modificação depois,
    cobertura, DocMDP e carimbo. Nulo não quer dizer aceito: o PHP aplica a regra E6-6 aos campos."""
    if not item['intacta']:
        return 'conteudo_alterado'
    if not item['valida']:
        return 'assinatura_invalida'
    if not item['confiavel']:
        if status.revoked:
            return 'certificado_revogado'
        indicacao = status.trust_problem_indic
        if indicacao is None:
            return 'cadeia_nao_confiavel'
        if indicacao.name == 'CERTIFICATE_CHAIN_GENERAL_FAILURE':
            # O último erro registrado é o da cadeia do signatário (o carimbo é conferido antes).
            if erros and erros[-1] in ERROS_DE_REVOGACAO:
                return 'revogacao_indisponivel'
            return 'cadeia_nao_confiavel'
        return MOTIVO_DA_CONFIANCA.get(indicacao.name, 'cadeia_nao_confiavel')
    if not _declara_revogacao(status.signing_cert):
        # Cadeia confiável, mas o certificado não declara LCR nem OCSP: a revogação não foi consultada.
        return 'revogacao_nao_declarada'
    if item['nivel_de_modificacao'] == 'outra':
        return 'modificado_depois'
    if item['cobertura'] == 'parcial':
        return 'cobertura_parcial'
    if item['docmdp_ok'] is False:
        return 'docmdp_violado'
    if item['carimbo']['presente'] and not item['carimbo']['valido']:
        return 'carimbo_invalido'
    return None


# Titular e cadeia ----------------------------------------------------------------------------------------


def _cn(nome: x509.Name) -> str | None:
    atributos = nome.get_attributes_for_oid(NameOID.COMMON_NAME)
    if not atributos:
        return None
    valor = atributos[0].value
    return valor if isinstance(valor, str) else valor.decode('utf-8', 'replace')


def _elo(certificado: asn1_x509.Certificate) -> dict:
    der = certificado.dump()
    try:
        cn = _cn(x509.load_der_x509_certificate(der).subject)
    except ValueError:
        cn = None
    return {'cn': cn, 'sha256': sha256_hex(der)}


def _texto_do_other_name(der: bytes) -> str | None:
    """O valor do otherName da ICP-Brasil vem como OCTET STRING ou como cadeia de caracteres."""
    try:
        valor = core.load(der)
    except ValueError:
        return None
    if isinstance(valor, core.OctetString):
        return valor.native.decode('latin-1')
    nativo = valor.native
    if isinstance(nativo, str):
        return nativo
    if isinstance(nativo, bytes):
        return nativo.decode('latin-1')
    return None


def _digitos(texto: str | None, inicio: int, tamanho: int) -> str | None:
    if texto is None or len(texto) < inicio + tamanho:
        return None
    trecho = texto[inicio:inicio + tamanho]
    if not trecho.isascii() or not trecho.isdigit() or set(trecho) == {'0'}:
        return None
    return trecho


def titular(certificado: asn1_x509.Certificate) -> dict:
    """CN, CPF (nascimento ddmmaaaa + CPF no 2.16.76.1.3.1) e CNPJ (2.16.76.1.3.3) declarados no certificado."""
    try:
        cripto = x509.load_der_x509_certificate(certificado.dump())
    except ValueError:
        return {'cn': None, 'cpf': None, 'cnpj': None}
    cpf = cnpj = None
    try:
        nomes_alternativos = cripto.extensions.get_extension_for_class(x509.SubjectAlternativeName).value
        outros = nomes_alternativos.get_values_for_type(x509.OtherName)
    except (x509.ExtensionNotFound, ValueError):
        outros = []
    for outro in outros:
        oid = outro.type_id.dotted_string
        if oid == OID_CPF and cpf is None:
            cpf = _digitos(_texto_do_other_name(outro.value), 8, 11)
        elif oid == OID_CNPJ and cnpj is None:
            cnpj = _digitos(_texto_do_other_name(outro.value), 0, 14)
    return {'cn': _cn(cripto.subject), 'cpf': cpf, 'cnpj': cnpj}
