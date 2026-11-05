#!/usr/bin/env python3
"""Extrai a resolução dos merges de um intervalo para o gitleaks ler (auditoria 4.10).

Uso: extrair-resolucao-merges.py A..B DESTINO

O gitleaks no modo `git` lê `git log -p`, que não mostra o diff de merge: um segredo digitado ao
resolver um conflito nunca chegava às regras dele. Este script roda `git show --remerge-diff` em
cada merge do intervalo e grava só as linhas ADICIONADAS pela resolução em
DESTINO/<commit>/<caminho original>, para o CI rodar `gitleaks dir DESTINO` com o mesmo
.gitleaks.toml. O caminho original é mantido para as regras restritas a arquivo de configuração
(`path = ...`) continuarem valendo; o número da linha no relatório é o do arquivo extraído, não o
do repositório.

Nunca imprime conteúdo: só quantos merges e arquivos foram extraídos. Saída 0 = ok, 2 = erro.
"""

from __future__ import annotations

import importlib.util
import subprocess
import sys
from pathlib import Path, PurePosixPath

_spec = importlib.util.spec_from_file_location(
    'verificar_segredos', Path(__file__).resolve().parent / 'verificar-segredos.py')
guarda = importlib.util.module_from_spec(_spec)
sys.modules['verificar_segredos'] = guarda  # o @dataclass da guarda procura o módulo aqui
_spec.loader.exec_module(guarda)


def caminho_seguro(arquivo: str) -> bool:
    partes = PurePosixPath(arquivo).parts
    return bool(partes) and not PurePosixPath(arquivo).is_absolute() and '..' not in partes


def extrair(intervalo: str, destino: Path) -> tuple[int, int]:
    merges = guarda.git('rev-list', '--merges', intervalo).split()
    total_arquivos = 0
    for commit in merges:
        diff = guarda.git('show', '--remerge-diff', *guarda.SEM_RENOMEACAO, '--format=', commit)
        por_arquivo: dict[str, list[str]] = {}
        for arquivo, _linha, texto in guarda.linhas_adicionadas(diff):
            por_arquivo.setdefault(arquivo, []).append(texto)
        for arquivo, linhas in por_arquivo.items():
            if not caminho_seguro(arquivo):
                raise ValueError('caminho inesperado no diff do merge ' + commit[:10])
            alvo = destino / commit[:12] / arquivo
            alvo.parent.mkdir(parents=True, exist_ok=True)
            alvo.write_text('\n'.join(linhas) + '\n', encoding='utf-8')
            total_arquivos += 1
    return len(merges), total_arquivos


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print('uso: extrair-resolucao-merges.py A..B DESTINO', file=sys.stderr)
        return 2
    destino = Path(argv[1])
    destino.mkdir(parents=True, exist_ok=True)
    try:
        merges, arquivos = extrair(argv[0], destino)
    except (subprocess.CalledProcessError, ValueError) as e:
        motivo = e if isinstance(e, ValueError) else 'falha ao consultar o git'
        print(f'extrair-resolucao-merges: {motivo}. Recusado.', file=sys.stderr)
        return 2
    print(f'Resolução extraída: {merges} merge(s), {arquivos} arquivo(s) com linhas adicionadas.')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
