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
    r"n[ãa]o entra no saldo",
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
    r"ENTRAM no", r"AFETAM o saldo", r"DENTRO do valor exigível",
]

# O HOMÔNIMO: INV-E2 nestes arquivos quer dizer "não implementa Auditavel".
HOMONIMOS = {
    "app/src/Cobranca/Entity/RelatorioImportado.php",
    "app/src/Cobranca/Entity/RelatorioLinha.php",
    "app/src/Cobranca/Entity/RelatorioTotalizador.php",
}

# ⛔ OS QUE DEVEM FICAR. Comentário VERDADEIRO sobre código que continua ERRADO: os lugares que somam o
# exigível à mão, sem honorário. Corrigir o texto esconderia o defeito — ver spec §7.1 e handoff §7.2.
HONESTOS = {
    ("app/src/Cobranca/UseCase/EditarObrigacaoUseCase.php", 138):
        "cópia à mão nº1 — decide se dívida liquidada REABRE",
    ("app/src/Cobranca/UseCase/EditarObrigacaoUseCase.php", 198):
        "cópia à mão nº2 — guard ValorAbaixoDoAlocado",
    ("app/src/Cobranca/Repository/ObrigacaoRepository.php", 211):
        "cópia nº3, em DQL — having da régua pagasMasNaoLiquidadas",
}

JANELA = 6


def varrer() -> tuple[list[str], list[str]]:
    falsos: list[str] = []
    honestos_vistos: list[str] = []
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

            if (rel, i) in HONESTOS:
                honestos_vistos.append(f"{rel}:{i} — {HONESTOS[(rel, i)]}")
                continue

            bloco = "\n".join(linhas[max(0, i - 1 - JANELA):i + JANELA])

            if revogacao.search(bloco):
                continue

            falsos.append(f"{rel}:{i} :: {linha.strip()[:110]}")

    return falsos, honestos_vistos


def main() -> int:
    falsos, honestos = varrer()

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

    faltando = len(HONESTOS) - len(honestos)

    if faltando:
        print()
        print(f"  ⚠️  {faltando} honesto(s) esperado(s) NÃO foram encontrados na linha registrada.")
        print("      Ou o defeito foi corrigido (então atualize HONESTOS e a spec §7.1),")
        print("      ou o comentário foi apagado — que é justamente o que este script existe para pegar.")

    return 1 if falsos or faltando else 0


if __name__ == "__main__":
    sys.exit(main())
