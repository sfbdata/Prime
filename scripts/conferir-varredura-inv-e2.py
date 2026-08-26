#!/usr/bin/env python3
"""Prova da varredura da INV-E2 — spec `docs/specs/cobranca-honorario-no-total.md` §7.1.

Revogar a INV-E2 (o honorário passou a entrar no `valorExigivel()`) deixou dezenas de comentários e
rótulos afirmando o contrário do que o código faz. Este script confere que a varredura está completa.

🔑 Ele imprime DUAS listas, e isso não é enfeite. Uma busca que termine só em "falsos: zero" empurra
para apagar justamente os comentários que devem FICAR: os que descrevem com honestidade um código que
continua errado. Apagá-los deixaria o defeito e removeria a única pista dele.

⚠️ `INV-E2` é HOMÔNIMO: em `RelatorioImportado`, `RelatorioLinha` e `RelatorioTotalizador` significa
"não implementa Auditavel de propósito", nada a ver com honorário. Idem "fora do saldo", que descreve
legitimamente a obrigação excluída por acordo substituto vigente. Os dois casos são ignorados aqui.

Uso:  python3 scripts/conferir-varredura-inv-e2.py
Sai com código 1 se sobrou afirmação falsa, ou se a lista dos honestos mudou sem a spec mudar junto.
"""

from __future__ import annotations

import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent

# Frases que AFIRMAM a regra revogada.
PADROES = [
    r"INV-E2",
    # ⚠️ `\W*` entre as palavras: a 11ª revisão achou um sobrevivente que o padrão simples não pegava —
    # *"honorário **não** entra"*, com os asteriscos do markdown no meio. Negação de dinheiro aparece
    # grifada com frequência em docblock, e a versão sem isto dava FALSO NEGATIVO em silêncio.
    r"n[ãa]o\W*entra no saldo",
    r"honorário\W*\*{0,2}n[ãa]o\*{0,2}\W*entra",
    r"n[ãa]o é dívida do credor",
    r"honorários ficam (de )?FORA",
    r"Honorário fica (de )?FORA",
    r"fora do valor exigível",
]

# O comentário CITA a regra para marcá-la como morta — não a afirma. Procurado numa janela em volta,
# porque o marcador quase sempre cai numa linha diferente da citação (comentário de bloco).
MARCAS_DE_REVOGACAO = [
    r"revogad", r"revogou", r"REVOGAD", r"REVOGOU",
    r"Era verdade", r"era verdade", r"hoje é falso",
    r"Aqui (havia|saíam|estava)", r"aqui (havia|saíam|estava)",
    r"terminava assim", r"justificativa escrita era",
    r"Uma redação anterior", r"uma redação anterior",
    r"ENTRAM no", r"ENTRA no exigível", r"AFETAM o saldo", r"DENTRO do valor exigível",
    # Formas específicas de dizer "revogada" que apareceram na varredura. Mantenha-as ESPECÍFICAS: um
    # marcador largo (só "caiu", por exemplo) silenciaria afirmações falsas por acidente, e o script
    # passaria a dar falso negativo — o defeito que ele existe para não ter.
    r"INV-E2 caiu", r"INV-E2 foi revogada",
]

# O HOMÔNIMO: INV-E2 nestes arquivos quer dizer "não implementa Auditavel".
HOMONIMOS = {
    "app/src/Cobranca/Entity/RelatorioImportado.php",
    "app/src/Cobranca/Entity/RelatorioLinha.php",
    "app/src/Cobranca/Entity/RelatorioTotalizador.php",
}

# ⛔ OS QUE DEVEM FICAR. Comentário VERDADEIRO sobre código que continua ERRADO: os lugares que somam o
# exigível à mão, sem honorário. Corrigir o texto esconderia o defeito — ver spec §7.1 e, no master,
# handoff §7.2 (o handoff é doc de coordenação e vive só no master; de dentro de uma frente ele parece
# desatualizado, e isso é esperado).
#
# 🔑 ÂNCORA POR CONTEÚDO, NUNCA POR NÚMERO DE LINHA. A 1ª versão deste script ancorava por linha, e a 10ª
# revisão provou a armadilha: UMA linha inserida acima desloca as âncoras, e o script passa a acusar os
# honestos como "falsos restantes" — a saída empurra a próxima sessão a apagar exatamente o que ele
# existe para preservar.
HONESTOS = {
    "honorários fora, INV-E2). ⚠️ A frase descreve o código":
        "EditarObrigacaoUseCase — cópia à mão nº2, o guard ValorAbaixoDoAlocado",
    "honorários ficam de fora (INV-E2)":
        "ObrigacaoRepository — cópia nº3, em DQL: having da régua pagasMasNaoLiquidadas",
}

# 📌 A cópia nº1 (`EditarObrigacaoUseCase`, decide se dívida liquidada REABRE) NÃO entra aqui: até a 10ª
# revisão ela não tinha comentário nenhum — a de maior consequência era a única sem pista. O comentário
# que ganhou ali já nomeia a INV-E2 como revogada, então é o caso 3 da spec §7.1 (corrigir E registrar o
# defeito), não o caso 2, e a varredura o trata como texto correto.

JANELA = 6


def varrer() -> tuple[list[str], list[str], set[str]]:
    falsos: list[str] = []
    honestos_vistos: list[str] = []
    vistos: set[str] = set()
    regex = re.compile("|".join(PADROES))
    revogacao = re.compile("|".join(MARCAS_DE_REVOGACAO))

    alvos = sorted(
        p for base in ("app/src", "app/templates")
        for p in (RAIZ / base).rglob("*")
        if p.suffix in {".php", ".twig"}
    )

    for caminho in alvos:
        rel = caminho.relative_to(RAIZ).as_posix()

        if rel in HOMONIMOS:
            continue

        linhas = caminho.read_text(encoding="utf-8").splitlines()

        for i, linha in enumerate(linhas, start=1):
            if not regex.search(linha):
                continue

            ancora = next((a for a in HONESTOS if a in linha), None)

            if ancora is not None:
                honestos_vistos.append(f"{rel}:{i} — {HONESTOS[ancora]}")
                vistos.add(ancora)
                continue

            bloco = "\n".join(linhas[max(0, i - 1 - JANELA):i + JANELA])

            if revogacao.search(bloco):
                continue

            falsos.append(f"{rel}:{i} :: {linha.strip()[:110]}")

    return falsos, honestos_vistos, vistos


def main() -> int:
    falsos, honestos, vistos = varrer()

    print("=" * 78)
    print(f"falsos restantes: {len(falsos) if falsos else 'zero'}")
    print("=" * 78)
    for f in falsos:
        print("  🔴", f)

    print()
    print("=" * 78)
    print(f"honestos sobre defeito aberto: {len(honestos)}, e estes SEGUEM")
    print("=" * 78)
    for h in honestos:
        print("  ⛔", h)

    sumidos = sorted(set(HONESTOS) - vistos)

    if sumidos:
        print()
        print(f"  ⚠️  {len(sumidos)} honesto(s) esperado(s) NÃO foram encontrados:")
        for a in sumidos:
            print(f"        {HONESTOS[a]}")
        print("      Ou o defeito do CÓDIGO foi corrigido (então atualize HONESTOS e a spec §7.1),")
        print("      ou o comentário foi apagado — que é justamente o que este script existe para pegar.")

    return 1 if falsos or sumidos else 0


if __name__ == "__main__":
    sys.exit(main())
