#!/usr/bin/env python3
"""Move para os arquivos por bloco as chaves que a sua branch acrescentou no arquivo único antigo.

Contexto: o catálogo pt_BR deixou de ser app/translations/messages+intl-icu.pt_BR.yaml e virou um
arquivo por bloco (app/translations/<bloco>/messages+intl-icu.pt_BR.yaml). Quem tinha chaves novas no
arquivo único ao receber o master tem um conflito (modify/delete). Este script faz, por bloco, a fusão
de três vias (base do ancestral comum, versão da sua branch, arquivo novo do master).

Uso, com a fusão do master EM ANDAMENTO (antes de commitar):
    git merge --no-edit origin/master        # conflita só no arquivo único antigo
    scripts/traducao-rebaixar.py             # fusão por bloco; marcas <<<<<<< só onde houver conflito de fato
    rm app/translations/messages+intl-icu.pt_BR.yaml
    git add -A app/translations && git commit --no-edit

Bloco novo da sua branch vira pasta nova. Depois confira: scripts/frente-testar.sh <frente> --filter CatalogoPorBloco
"""
import os
import re
import subprocess
import sys
import tempfile

MONOLITO = 'app/translations/messages+intl-icu.pt_BR.yaml'
ARQUIVO = 'messages+intl-icu.pt_BR.yaml'


def git(*args):
    return subprocess.check_output(['git', *args], text=True, encoding='utf-8')


def blocos(texto):
    """Devolve {nome: texto do bloco}. O comentário que antecede a chave pertence ao bloco."""
    linhas = texto.split('\n')
    # o cabeçalho do arquivo (comentários até a primeira linha em branco) não é de bloco
    inicio = linhas.index('') + 1 if '' in linhas else 0
    corpo = linhas[inicio:]
    idx = [i for i, l in enumerate(corpo) if re.match(r'^[A-Za-z_][A-Za-z0-9_]*:\s*$', l)]
    ini = []
    for i in idx:
        j = i
        while j > 0 and (corpo[j - 1] == '' or corpo[j - 1].startswith('#')):
            j -= 1
        ini.append(j)
    fim = ini[1:] + [len(corpo)]
    saida = {}
    for k, (a, b) in enumerate(zip(ini, fim)):
        nome = corpo[idx[k]].rstrip(':').strip()
        bloco = corpo[a:b]
        while bloco and bloco[0] == '':
            bloco.pop(0)
        while bloco and bloco[-1] == '':
            bloco.pop()
        saida[nome] = '\n'.join(bloco) + '\n'
    return saida


def main():
    try:
        outro = git('rev-parse', '-q', '--verify', 'MERGE_HEAD').strip()
    except subprocess.CalledProcessError:
        outro = ''
    if not outro:
        sys.exit('Rode com a fusão do master em andamento: git merge --no-edit origin/master')
    base_rev = git('merge-base', 'HEAD', outro).strip()
    try:
        base = blocos(git('show', f'{base_rev}:{MONOLITO}'))
        deles = blocos(git('show', f'HEAD:{MONOLITO}'))
    except subprocess.CalledProcessError:
        sys.exit('O arquivo único não existe na base ou na sua branch: nada a mover.')

    conflitos = []
    for nome, texto in deles.items():
        if base.get(nome) == texto:
            continue
        destino = f'app/translations/{nome}/{ARQUIVO}'
        if not os.path.exists(destino):
            os.makedirs(os.path.dirname(destino), exist_ok=True)
            open(destino, 'w', encoding='utf-8').write(texto)
            print(f'novo   {destino}')
            continue
        with tempfile.TemporaryDirectory() as tmp:
            b, t = os.path.join(tmp, 'base'), os.path.join(tmp, 'sua')
            open(b, 'w', encoding='utf-8').write(base.get(nome, ''))
            open(t, 'w', encoding='utf-8').write(texto)
            r = subprocess.run(['git', 'merge-file', '-L', 'master', '-L', 'base', '-L', 'sua-branch', destino, b, t])
        if r.returncode:
            conflitos.append(destino)
            print(f'CONFLITO {destino}')
        else:
            print(f'fundido {destino}')
    for nome in base:
        if nome not in deles:
            print(f'aviso: sua branch removeu o bloco "{nome}"; confira à mão', file=sys.stderr)
    if conflitos:
        sys.exit('Resolva as marcas <<<<<<< nos arquivos CONFLITO acima antes do git add.')


if __name__ == '__main__':
    main()
