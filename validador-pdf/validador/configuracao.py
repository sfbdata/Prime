"""Configuração do contêiner, lida das variáveis de ambiente (spec da etapa 6, seção 2, e da etapa 8).

Tudo que muda entre produção e teste passa por aqui: os testes montam a `Configuracao` direto, sem env.
"""

from __future__ import annotations

import logging
import os
from dataclasses import dataclass, field

log = logging.getLogger('validador.configuracao')

# Teto do corpo (413 acima disso), tempo máximo de uma conferência e fila (seção 2 da spec da etapa 6).
TETO_DO_PDF = 20 * 1024 * 1024
TEMPO_MAXIMO = 30.0
SIMULTANEAS = 2
ESPERA_NA_FILA = 10.0

# Fontes oficiais das âncoras (E6-5). O download é por HTTP: o certificado TLS do acraiz vem sem a intermediária
# do Let's Encrypt e o repo.iti.br responde com certificado falso. O SHA-512 publicado ao lado só pega pacote
# corrompido (vem pela mesma conexão); quem protege contra troca no caminho é a lista de raízes fixadas abaixo.
ICP_URL_DO_PACOTE = 'http://acraiz.icpbrasil.gov.br/credenciadas/CertificadosAC-ICP-Brasil/ACcompactado.zip'
ICP_URL_DO_HASH = 'http://acraiz.icpbrasil.gov.br/credenciadas/CertificadosAC-ICP-Brasil/hashsha512.txt'
GOVBR_URL_DA_CADEIA = 'http://repo.iti.br/docs/Cadeia_GovBr-der.p7b'

# Raízes aceitas: impressão digital SHA-256 (hexadecimal minúsculo, sem separador) fixada no código (E6-5).
# Raiz autoassinada que vier no download e não estiver aqui é ignorada; as intermediárias continuam vindo do
# download, porque o pyHanko confere a cadeia até uma destas raízes. As envs ASSINATURA_VALIDADOR_ICP_SHA256 e
# ASSINATURA_VALIDADOR_GOVBR_SHA256 acrescentam impressões digitais sem mudar o código (raiz nova do ITI), mas
# nunca recolocam uma das RAIZES_RECUSADAS_SHA256 abaixo.
#
# ICP-Brasil: as raízes vigentes listadas em https://www.gov.br/iti/pt-br/assuntos/repositorio/repositorio-ac-raiz,
# baixadas em 09/11/2026 dos links dessa página (https://acraiz.icpbrasil.gov.br/credenciadas/RAIZ/ICP-Brasilv*.crt)
# por HTTPS, com a cadeia TLS conferida até a ISRG Root X2 (intermediária YE1 tirada de letsencrypt.org). De fora:
# a primeira, a v1 e a v2 (vencidas), a v3, a v8 e a v9 (revogadas). A v4, a v7 e a v13 não entram no conjunto
# hoje (a v4 e a v13 não estão no pacote e a v7 usa um algoritmo que a biblioteca não lê), mas ficam fixadas para
# quando estiverem.
#
# Confiança mínima: só as hierarquias de assinatura de documento. A v10 (raiz de certificados SSL de servidor) e a
# v11 (raiz de certificados de assinatura de código) estão vigentes, mas ficam FORA: um certificado de site ou de
# código não serve para assinar documento, e aceitar essas raízes deixaria qualquer certificado delas, com uso de
# chave de assinatura, sair "confiável" num PDF. As ACs delas continuam vindo no pacote, mas sem raiz não fecham
# cadeia. Ficam em RAIZES_RECUSADAS_SHA256: a env não as recoloca e o pacote nunca as transforma em raiz.
ICP_RAIZES_SHA256: tuple[str, ...] = (
    'f0c15afd258fb674e7a96e1a50ff873149364b9ec70d4d93c7a9f1eb6060d020',  # Raiz Brasileira v4, até 2035
    'caa53fc6091c6951887c976e378f6ef89aa6377c55d97b6475422b71ed7e9b17',  # Raiz Brasileira v5, até 2029
    '3bdb9b509352f1d3d71c2bf64d9a38a4e6cebda27809d77f7ac476cbde6e314a',  # Raiz Brasileira v6, até 2038
    '5657e70580eb678983f3ed7dfce091d84cae6549389a47fccda8d0e4dc2cf576',  # Raiz Brasileira v7, até 2038
    'd8478e37ce19c690cf657381e68fe600e4e1a042536830f06847e03e554c4b01',  # Raiz Brasileira v12, até 2037
    '2b07d0bc02c4a6e0478ed22d0d99e8f97e1827b269097696a7feb6aad30c3ac8',  # Raiz Brasileira v13, até 2045
)

# gov.br: a página oficial https://www.gov.br/governodigital/pt-br/identidade/assinatura-eletronica/
# saiba-como-importar-os-certificados-do-gov-br-no-adobe-acrobat-reader manda baixar a cadeia de
# https://arquivos.ufsc.br/f/011077a80362419997c3/?dl=1 (Cadeia_GovBr-der.p7b), baixada em 09/11/2026 por HTTPS com
# cadeia TLS válida. A raiz dela é a mesma do arquivo do repo.iti.br (que só responde em HTTP).
GOVBR_RAIZES_SHA256: tuple[str, ...] = (
    '163bd003bc0df2beab88174b6d5b450b6e1dc973a64b2dc2a33218544f49ef4e',  # Raiz do Governo Federal v1, até 2033
)

# Recusadas no código, nas duas famílias: nem a env nem a lista fixada fazem delas uma raiz (E6-5).
RAIZES_RECUSADAS_SHA256: frozenset[str] = frozenset({
    '6e0bff069a26994c15de2c4888cc54af84882e5495b7fbf66be9ccffec7489f6',  # Raiz Brasileira v10 (SSL de servidor)
    '1406710058180fa4081aab3f246f1702429c552a11fa3143b84c88cb3ab8e5e7',  # Raiz Brasileira v11 (assinatura de código)
})

SELO_PKCS12 = '/run/secrets/selo_pkcs12'
SELO_SENHA = '/run/secrets/selo_pkcs12_senha'

INTERVALO_DE_ATUALIZACAO = 24 * 60 * 60
INTERVALO_DEPOIS_DE_FALHA = 60 * 60


def _normalizar_impressao_digital(valor: str) -> str:
    return ''.join(c for c in valor.lower() if c in '0123456789abcdef')


def _impressoes_digitais(fixadas: tuple[str, ...], da_env: str, nome_da_env: str) -> frozenset[str]:
    """As fixadas no código mais as da env (lista separada por vírgula, aceita ':' e maiúsculas), menos as recusadas."""
    extras = {_normalizar_impressao_digital(v) for v in da_env.split(',')} - {''}
    for recusada in sorted(extras & RAIZES_RECUSADAS_SHA256):
        log.warning('%s traz uma raiz recusada no codigo, ignorada: %s', nome_da_env, recusada)
    return frozenset({_normalizar_impressao_digital(v) for v in fixadas} | extras) - RAIZES_RECUSADAS_SHA256


@dataclass(frozen=True)
class Revogacao:
    """Como a revogação é consultada: na rede (produção) ou só com LCR e OCSP entregues (teste)."""

    buscar_na_rede: bool = True
    lcrs: tuple[bytes, ...] = ()
    ocsps: tuple[bytes, ...] = ()
    tempo_por_requisicao: float = 8.0
    requisicoes_maximas: int = 12


@dataclass(frozen=True)
class Configuracao:
    # Fora do repr: a configuração pode aparecer num traceback ou num log de depuração.
    token: bytes = field(default=b'', repr=False)
    endereco: str = '0.0.0.0'
    porta: int = 8080
    teto_do_pdf: int = TETO_DO_PDF
    tempo_maximo: float = TEMPO_MAXIMO
    simultaneas: int = SIMULTANEAS
    espera_na_fila: float = ESPERA_NA_FILA
    diretorio_de_dados: str = '/dados'
    icp_url_do_pacote: str = ICP_URL_DO_PACOTE
    icp_url_do_hash: str = ICP_URL_DO_HASH
    govbr_url_da_cadeia: str = GOVBR_URL_DA_CADEIA
    icp_raizes_sha256: frozenset[str] = field(default_factory=lambda: frozenset(ICP_RAIZES_SHA256))
    govbr_raizes_sha256: frozenset[str] = field(default_factory=lambda: frozenset(GOVBR_RAIZES_SHA256))
    atualizar_ancoras: bool = True
    revogacao: Revogacao = field(default_factory=Revogacao)
    # Selo PAdES (etapa 8, E8-2 e E8-3): secrets do compose e ACT opcional (vazia = PAdES B-B).
    selo_pkcs12: str = SELO_PKCS12
    selo_senha: str = SELO_SENHA
    selo_url_da_act: str = ''

    @staticmethod
    def do_ambiente(ambiente: dict[str, str] | None = None) -> Configuracao:
        env = os.environ if ambiente is None else ambiente
        return Configuracao(
            token=env.get('ASSINATURA_VALIDADOR_TOKEN', '').strip().encode('utf-8'),
            porta=int(env.get('ASSINATURA_VALIDADOR_PORTA', '8080')),
            diretorio_de_dados=env.get('ASSINATURA_VALIDADOR_DADOS', '/dados'),
            icp_url_do_pacote=env.get('ASSINATURA_VALIDADOR_ICP_URL', ICP_URL_DO_PACOTE),
            icp_url_do_hash=env.get('ASSINATURA_VALIDADOR_ICP_HASH_URL', ICP_URL_DO_HASH),
            govbr_url_da_cadeia=env.get('ASSINATURA_VALIDADOR_GOVBR_URL', GOVBR_URL_DA_CADEIA),
            icp_raizes_sha256=_impressoes_digitais(
                ICP_RAIZES_SHA256, env.get('ASSINATURA_VALIDADOR_ICP_SHA256', ''), 'ASSINATURA_VALIDADOR_ICP_SHA256'
            ),
            govbr_raizes_sha256=_impressoes_digitais(
                GOVBR_RAIZES_SHA256, env.get('ASSINATURA_VALIDADOR_GOVBR_SHA256', ''),
                'ASSINATURA_VALIDADOR_GOVBR_SHA256',
            ),
            selo_pkcs12=env.get('ASSINATURA_SELO_PKCS12_CAMINHO', SELO_PKCS12),
            selo_senha=env.get('ASSINATURA_SELO_SENHA_CAMINHO', SELO_SENHA),
            selo_url_da_act=env.get('ASSINATURA_SELO_TSA_URL', '').strip(),
        )
