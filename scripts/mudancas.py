#!/usr/bin/env python3
"""Registro de mudanças por PR e montagem automática da próxima versão.

Cada PR traz um ou mais arquivos em `mudancas/` (um por assunto, nome livre e único, ex.:
`mudancas/push-processual-unificado.md`). Arquivos diferentes não conflitam entre sessões, e o
CHANGELOG.md só é escrito por este script, no PR de release.

Formato de um arquivo de mudança:

    ---
    tipo: novidade
    ---
    ## Changelog
    - Texto técnico, como vai para o CHANGELOG.md (uma ou mais linhas "- ").

    ## Para o cliente
    - Texto curto, em linguagem de escritório, para a página Novidades (obrigatório nos tipos
      novidade, alteracao e correcao; descreva o que mudou, mesmo que a tela seja só do Master).

Tipos (e o que cada um faz com a versão):
    remocao    → Removido   → MAJOR   (algo que o usuário usava deixa de existir)
    novidade   → Adicionado → MINOR
    alteracao  → Alterado   → MINOR
    correcao   → Corrigido  → PATCH
    seguranca  → Segurança  → PATCH
    interno    → (fora)     → nada    (CI, testes, documentação: não gera versão nem aparece)

Comandos:
    validar                  confere todos os arquivos de mudança
    proxima                  imprime a próxima versão (vazio se não há o que lançar)
    resumo                   imprime em Markdown o que a próxima versão leva
    aplicar --data AAAA-MM-DD
                             monta a versão: CHANGELOG, VersaoDoSistema, novidades e apaga os
                             arquivos de mudança; imprime a versão aplicada
Uso local: python3 scripts/mudancas.py validar
"""
from __future__ import annotations

import argparse
import datetime
import json
import pathlib
import re
import sys
import unicodedata

RAIZ = pathlib.Path(__file__).resolve().parent.parent
DIR = RAIZ / 'mudancas'
CHANGELOG = RAIZ / 'CHANGELOG.md'
CONSTANTE = RAIZ / 'app/src/Shared/VersaoDoSistema.php'
NOVIDADES = RAIZ / 'app/config/novidades.yaml'
REPOSITORIO = 'https://github.com/sfbdata/Prime'
IGNORADOS = {'README.md'}

TIPOS = {
    'remocao': ('Removido', 3),
    'novidade': ('Adicionado', 2),
    'alteracao': ('Alterado', 2),
    'correcao': ('Corrigido', 1),
    'seguranca': ('Segurança', 1),
    'interno': (None, 0),
}
ORDEM_SECOES = ['Adicionado', 'Alterado', 'Removido', 'Corrigido', 'Segurança']


# Tipos que aparecem na página Novidades: exigem "## Para o cliente".
TIPOS_COM_TEXTO_AO_CLIENTE = ('novidade', 'alteracao', 'correcao')
# Frases que não dizem o que mudou (regra do dono, 31/10/2026): recusadas no texto ao cliente.
FRASES_VAZIAS = ('sem efeito', 'nada muda', 'nenhuma mudanca', 'sem mudanca', 'nao muda', 'nada mudou')


class ErroDeMudanca(Exception):
    pass


def _normalizar(texto: str) -> str:
    sem_acento = unicodedata.normalize('NFKD', texto).encode('ascii', 'ignore').decode()
    return sem_acento.strip().lower()


def _lista(bloco: str, onde: str) -> list[str]:
    """Linhas "- item" (continuação indentada vira parte do item anterior)."""
    itens: list[str] = []
    for linha in bloco.splitlines():
        if not linha.strip():
            continue
        if linha.startswith('- '):
            itens.append(linha[2:].strip())
        elif linha.startswith((' ', '\t')) and itens:
            itens[-1] += ' ' + linha.strip()
        else:
            raise ErroDeMudanca(f'{onde}: cada item começa com "- " (linha: {linha.strip()[:60]!r}).')
    return itens


def ler(arquivo: pathlib.Path) -> dict:
    texto = arquivo.read_text(encoding='utf-8')
    m = re.match(r'^---\s*\n(.*?)\n---\s*\n(.*)$', texto, re.S)
    if not m:
        raise ErroDeMudanca(f'{arquivo.name}: falta o cabeçalho "---\\ntipo: ...\\n---".')
    cabecalho, corpo = m.groups()
    tipo_m = re.search(r'^tipo:\s*(.+?)\s*$', cabecalho, re.M)
    if not tipo_m:
        raise ErroDeMudanca(f'{arquivo.name}: falta "tipo:" no cabeçalho.')
    tipo = _normalizar(tipo_m.group(1))
    if tipo not in TIPOS:
        raise ErroDeMudanca(f'{arquivo.name}: tipo "{tipo_m.group(1)}" inválido (use: {", ".join(TIPOS)}).')

    secoes = dict.fromkeys(('changelog', 'cliente'), '')
    atual = None
    for linha in corpo.splitlines():
        cab = re.match(r'^##\s+(.+?)\s*$', linha)
        if cab:
            nome = _normalizar(cab.group(1))
            if nome == 'changelog':
                atual = 'changelog'
            elif nome in ('para o cliente', 'cliente', 'novidades'):
                atual = 'cliente'
            else:
                raise ErroDeMudanca(f'{arquivo.name}: seção "{cab.group(1)}" desconhecida '
                                    '(use "## Changelog" e "## Para o cliente").')
            continue
        if atual is None:
            if linha.strip():
                raise ErroDeMudanca(f'{arquivo.name}: texto antes de "## Changelog".')
            continue
        secoes[atual] += linha + '\n'

    changelog = _lista(secoes['changelog'], f'{arquivo.name} (Changelog)')
    cliente = _lista(secoes['cliente'], f'{arquivo.name} (Para o cliente)')
    if tipo != 'interno' and not changelog:
        raise ErroDeMudanca(f'{arquivo.name}: "## Changelog" vazio (só o tipo interno dispensa).')
    if tipo in TIPOS_COM_TEXTO_AO_CLIENTE and not cliente:
        raise ErroDeMudanca(f'{arquivo.name}: falta "## Para o cliente" (obrigatório no tipo {tipo}; '
                            'descreva em linguagem simples o que mudou, mesmo que a tela seja só do Master).')
    for item in cliente:
        if any(f in _normalizar(item) for f in FRASES_VAZIAS):
            raise ErroDeMudanca(f'{arquivo.name}: "Para o cliente" não pode dizer que nada mudou; '
                                f'descreva o que mudou: {item[:60]!r}.')
    for item in changelog + cliente:
        if '—' in item or '–' in item:
            raise ErroDeMudanca(f'{arquivo.name}: sem travessão no texto (regra do dono): {item[:60]!r}.')
    return {'arquivo': arquivo.name, 'tipo': tipo, 'changelog': changelog, 'cliente': cliente}


def arquivos() -> list[pathlib.Path]:
    if not DIR.is_dir():
        return []
    return sorted(p for p in DIR.glob('*.md') if p.name not in IGNORADOS)


def ler_todos() -> list[dict]:
    erros, lidos = [], []
    for a in arquivos():
        try:
            lidos.append(ler(a))
        except ErroDeMudanca as e:
            erros.append(str(e))
    if erros:
        raise ErroDeMudanca('\n'.join(erros))
    return lidos


def versao_atual() -> str:
    m = re.search(r"public const ATUAL = '(\d+\.\d+\.\d+)';", CONSTANTE.read_text(encoding='utf-8'))
    if not m:
        raise ErroDeMudanca('VersaoDoSistema::ATUAL não encontrada.')
    return m.group(1)


def proxima(mudancas: list[dict], atual: str) -> str | None:
    nivel = max((TIPOS[m['tipo']][1] for m in mudancas), default=0)
    if nivel == 0:
        return None
    maior, menor, corr = (int(x) for x in atual.split('.'))
    if nivel == 3:
        return f'{maior + 1}.0.0'
    if nivel == 2:
        return f'{maior}.{menor + 1}.0'
    return f'{maior}.{menor}.{corr + 1}'


def secoes_changelog(mudancas: list[dict]) -> str:
    por_secao: dict[str, list[str]] = {}
    for m in mudancas:
        secao = TIPOS[m['tipo']][0]
        if secao:
            por_secao.setdefault(secao, []).extend(m['changelog'])
    partes = []
    for secao in ORDEM_SECOES:
        if secao in por_secao:
            partes.append(f'### {secao}\n\n' + '\n'.join(f'- {i}' for i in por_secao[secao]))
    return '\n\n'.join(partes)


def aplicar(data: str) -> str:
    datetime.date.fromisoformat(data)
    mudancas = ler_todos()
    atual = versao_atual()
    nova = proxima(mudancas, atual)
    if not nova:
        raise ErroDeMudanca('Nada a lançar: não há mudança que gere versão.')

    # CHANGELOG: a seção nova entra logo abaixo de [Não lançado], que fica vazia.
    texto = CHANGELOG.read_text(encoding='utf-8')
    marca = '## [Não lançado]\n'
    inicio = texto.index(marca) + len(marca)
    fim = texto.index('\n## [', inicio) + 1
    if texto[inicio:fim].strip():
        raise ErroDeMudanca('[Não lançado] do CHANGELOG tem texto escrito à mão: mova-o para um '
                            'arquivo em mudancas/ antes da release.')
    secao = f'\n## [{nova}] - {data}\n\n{secoes_changelog(mudancas)}\n\n'
    texto = texto[:inicio] + secao + texto[fim:]
    link_velho = f'[Não lançado]: {REPOSITORIO}/compare/v{atual}...HEAD'
    if link_velho not in texto:
        raise ErroDeMudanca(f'Link de [Não lançado] fora do esperado (procurei {link_velho!r}).')
    texto = texto.replace(link_velho, f'[Não lançado]: {REPOSITORIO}/compare/v{nova}...HEAD\n'
                                      f'[{nova}]: {REPOSITORIO}/compare/v{atual}...v{nova}')
    CHANGELOG.write_text(texto, encoding='utf-8')

    # Fonte única da versão.
    CONSTANTE.write_text(CONSTANTE.read_text(encoding='utf-8').replace(
        f"public const ATUAL = '{atual}';", f"public const ATUAL = '{nova}';"), encoding='utf-8')

    # Novidades para o cliente: a versão nova no topo (só se alguém escreveu texto para ele).
    itens = [i for m in mudancas for i in m['cliente']]
    if itens:
        bloco = (f'- versao: "{nova}"\n  data: "{data}"\n  itens:\n'
                 + ''.join(f'    - {json.dumps(i, ensure_ascii=False)}\n' for i in itens))
        antigo = NOVIDADES.read_text(encoding='utf-8') if NOVIDADES.exists() else ''
        cabecalho, _, lista = antigo.partition('versoes:\n')
        if not _:
            cabecalho = ('# Gerado pelo scripts/mudancas.py no PR de release: o texto vem da seção\n'
                         '# "## Para o cliente" dos arquivos em mudancas/. Lido pela página Novidades.\n')
        NOVIDADES.write_text(cabecalho + 'versoes:\n' + bloco + lista, encoding='utf-8')

    for a in arquivos():
        a.unlink()
    return nova


def resumo() -> str:
    mudancas = ler_todos()
    nova = proxima(mudancas, versao_atual())
    if not nova:
        return 'Nada a lançar.'
    cliente = [i for m in mudancas for i in m['cliente']]
    partes = [f'## v{nova}', secoes_changelog(mudancas)]
    if cliente:
        partes.append('### Para o cliente (página Novidades)\n\n' + '\n'.join(f'- {i}' for i in cliente))
    partes.append('Arquivos: ' + ', '.join(f'`mudancas/{m["arquivo"]}`' for m in mudancas))
    return '\n\n'.join(partes)


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    sub = p.add_subparsers(dest='cmd', required=True)
    sub.add_parser('validar')
    sub.add_parser('proxima')
    sub.add_parser('resumo')
    ap = sub.add_parser('aplicar')
    ap.add_argument('--data', required=True)
    args = p.parse_args()
    try:
        if args.cmd == 'validar':
            n = len(ler_todos())
            print(f'ok: {n} arquivo(s) de mudança válidos')
        elif args.cmd == 'proxima':
            print(proxima(ler_todos(), versao_atual()) or '')
        elif args.cmd == 'resumo':
            print(resumo())
        else:
            print(aplicar(args.data))
    except (ErroDeMudanca, ValueError) as e:
        print(f'::error::{e}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
