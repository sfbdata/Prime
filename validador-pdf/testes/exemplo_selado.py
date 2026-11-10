"""Gera PDFs selados de EXEMPLO com o certificado de TESTE do E8-6, para conferir pelo motor PHP (8B).

    docker run --rm --network none -v /pasta/de/saida:/saida bluejus-validador-pdf:teste \
        python -m testes.exemplo_selado /saida

Grava em /saida:
- pdf-selado-teste.pdf: sem ACT (PAdES B-B, o que produção faz enquanto não houver ACT contratada);
- pdf-selado-teste-com-carimbo.pdf: com a ACT de teste (carimbo e DSS num acréscimo depois do selo);
- pdf-selado-teste-original.pdf: o PDF "da etapa 3" antes do selo (o prefixo dos dois acima);
- pdf-selado-teste-raiz.pem: a "AC Raiz Teste BlueJus" (só para conferir a cadeia; não vale fora do teste).
"""

from __future__ import annotations

import sys
from pathlib import Path

from validador import selo
from validador.ancoras import ConjuntoDeAncoras
from validador.configuracao import Revogacao

from . import pdfs, pki
from .act import ActLocal

SENHA = b'senha do selo de teste'


def gerar(destino: Path) -> None:
    raiz = pki.criar_raiz('AC Raiz Teste BlueJus')
    ac = raiz.emitir_ac('AC Teste e-CNPJ')
    folha = ac.emitir_folha(
        'BLUEJUS TECNOLOGIA LTDA:11222333000181',
        cnpj='11222333000181',
        responsavel_cpf='12345678909',
        responsavel_nome='FULANO DE TAL',
    )
    act = raiz.emitir_act('ACT Teste BlueJus')
    conjunto = ConjuntoDeAncoras(icp_raizes=(raiz.der,), icp_intermediarias=(ac.der,))
    revogacao = Revogacao(buscar_na_rede=False, lcrs=(ac.lcr(),))
    pkcs12 = folha.pkcs12(SENHA, [ac])
    original = pdfs.pdf_em_imagem('BJA-TESTE-SELO-0001')

    destino.mkdir(parents=True, exist_ok=True)
    (destino / 'pdf-selado-teste-original.pdf').write_bytes(original)
    (destino / 'pdf-selado-teste.pdf').write_bytes(selo.selar(original, pkcs12, SENHA, '', conjunto, revogacao))
    with ActLocal(act) as servidor_da_act:
        com_carimbo = selo.selar(original, pkcs12, SENHA, servidor_da_act.url, conjunto, revogacao)
    (destino / 'pdf-selado-teste-com-carimbo.pdf').write_bytes(com_carimbo)
    (destino / 'pdf-selado-teste-raiz.pem').write_bytes(raiz.pem)


if __name__ == '__main__':
    gerar(Path(sys.argv[1] if len(sys.argv) > 1 else '/saida'))
