"""Configuração do contêiner, lida das variáveis de ambiente (spec da etapa 6, seção 2, e da etapa 8).

Tudo que muda entre produção e teste passa por aqui: os testes montam a `Configuracao` direto, sem env.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field

# Teto do corpo (413 acima disso), tempo máximo de uma conferência e fila (seção 2 da spec da etapa 6).
TETO_DO_PDF = 20 * 1024 * 1024
TEMPO_MAXIMO = 30.0
SIMULTANEAS = 2
ESPERA_NA_FILA = 10.0

# Fontes oficiais das âncoras (E6-5). O acraiz só responde em HTTP: o certificado TLS do servidor vem sem a
# intermediária, e o pacote é conferido pelo SHA-512 publicado ao lado.
ICP_URL_DO_PACOTE = 'http://acraiz.icpbrasil.gov.br/credenciadas/CertificadosAC-ICP-Brasil/ACcompactado.zip'
ICP_URL_DO_HASH = 'http://acraiz.icpbrasil.gov.br/credenciadas/CertificadosAC-ICP-Brasil/hashsha512.txt'
GOVBR_URL_DA_CADEIA = 'http://repo.iti.br/docs/Cadeia_GovBr-der.p7b'

# Raízes gov.br aceitas: impressão digital SHA-256 (hexadecimal, sem separador) fixada no código (E6-5).
# VAZIA de propósito: a raiz "Autoridade Certificadora Raiz do Governo Federal do Brasil v1" só foi achada por
# HTTP, sem impressão digital publicada em fonte oficial com TLS. Enquanto o Samuel não confirmar a impressão
# digital por fonte oficial, nenhuma assinatura gov.br fica confiável (a env ASSINATURA_VALIDADOR_GOVBR_SHA256
# acrescenta impressões digitais sem mudar o código).
GOVBR_RAIZES_SHA256: tuple[str, ...] = ()

INTERVALO_DE_ATUALIZACAO = 24 * 60 * 60
INTERVALO_DEPOIS_DE_FALHA = 60 * 60


def _normalizar_impressao_digital(valor: str) -> str:
    return ''.join(c for c in valor.lower() if c in '0123456789abcdef')


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
    token: bytes = b''
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
    govbr_raizes_sha256: frozenset[str] = field(default_factory=lambda: frozenset(GOVBR_RAIZES_SHA256))
    atualizar_ancoras: bool = True
    revogacao: Revogacao = field(default_factory=Revogacao)

    @staticmethod
    def do_ambiente(ambiente: dict[str, str] | None = None) -> Configuracao:
        env = os.environ if ambiente is None else ambiente
        extras = {
            _normalizar_impressao_digital(v)
            for v in env.get('ASSINATURA_VALIDADOR_GOVBR_SHA256', '').split(',')
            if _normalizar_impressao_digital(v)
        }
        return Configuracao(
            token=env.get('ASSINATURA_VALIDADOR_TOKEN', '').strip().encode('utf-8'),
            porta=int(env.get('ASSINATURA_VALIDADOR_PORTA', '8080')),
            diretorio_de_dados=env.get('ASSINATURA_VALIDADOR_DADOS', '/dados'),
            icp_url_do_pacote=env.get('ASSINATURA_VALIDADOR_ICP_URL', ICP_URL_DO_PACOTE),
            icp_url_do_hash=env.get('ASSINATURA_VALIDADOR_ICP_HASH_URL', ICP_URL_DO_HASH),
            govbr_url_da_cadeia=env.get('ASSINATURA_VALIDADOR_GOVBR_URL', GOVBR_URL_DA_CADEIA),
            govbr_raizes_sha256=frozenset(
                {_normalizar_impressao_digital(v) for v in GOVBR_RAIZES_SHA256} | extras
            ),
        )
