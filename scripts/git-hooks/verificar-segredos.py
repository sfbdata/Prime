#!/usr/bin/env python3
"""Guarda de segredos: recusa credencial nova em arquivo versionado.

Por que existe: de 15/02 a 12/10/2026 uma senha real do Gmail (a conta que envia os e-mails de
PRODUÇÃO) ficou em app/.env, num repositório público, por baixo de uma regra de .gitignore que não
fazia nada (o arquivo já era rastreado). Ignore não protege arquivo rastreado nem `git add -f`;
esta guarda olha o CONTEÚDO que está entrando.

Modos:
  verificar-segredos.py                      linhas adicionadas no stage (o pre-commit usa este)
  verificar-segredos.py --intervalo A..B     linhas adicionadas por cada commit de A..B (o CI usa este)
  verificar-segredos.py --arquivos F [F...]  arquivos inteiros (auditoria da árvore)

Só olha o que ENTRA. O histórico antigo tem um segredo conhecido (commit 3ee0082a, já tratado por
rotação, não por reescrita); o CI varre só o intervalo do PR/push, então esse passado não trava
ninguém — e um segredo novo trava sempre.

Nunca imprime o valor encontrado: só arquivo, linha, regra e o tamanho. Saída 0 = limpo,
1 = achado, 2 = erro interno (o hook trata 2 como recusa: na dúvida, não deixa passar).

Falso positivo: acrescente `segredo:permitido` na própria linha, com o motivo, e o achado daquela
linha é aceito. O revisor do commit vê a marca.
"""

from __future__ import annotations

import argparse
import hashlib
import re
import subprocess
import sys
from dataclasses import dataclass

MARCA_PERMITIDO = ('segredo:permitido', 'gitleaks:allow')

# --- Nomes de arquivo que nunca devem entrar (espelha o .gitignore; ignore não barra `add -f`) ---
NOMES_PROIBIDOS = [
    (re.compile(r'(^|/)\.env$'), 'arquivo .env fora de app/ (só app/.env é versionado)'),
    (re.compile(r'(^|/)[^/]+\.env$'), 'arquivo *.env de credencial'),
    (re.compile(r'(^|/)\.env\.local(\.php)?$'), '.env.local (segredo local)'),
    (re.compile(r'(^|/)\.env\.[^/]+\.local$'), '.env.*.local (segredo local)'),
    (re.compile(r'(^|/)\.env\.prod(\.(?!example$)[^/]+)?$'), '.env.prod (segredo de produção)'),
    (re.compile(r'\.(pem|key|p12|pfx|jks|keystore|ppk)$', re.I), 'chave privada / certificado'),
    (re.compile(r'(^|/)id_(rsa|ecdsa|ed25519)[^/]*$'), 'chave SSH'),
    (re.compile(r'(^|/)(\.pgpass|\.htpasswd)$'), 'arquivo de senhas'),
    (re.compile(r'(^|/)client_secret[^/]*\.json$', re.I), 'credencial OAuth do Google'),
    (re.compile(r'service[-_]account[^/]*\.json$', re.I), 'conta de serviço'),
    (re.compile(r'(^|/)credencial[^/]*\.txt$', re.I), 'credencial em texto'),
    (re.compile(r'\.dump$'), 'dump de banco'),
    (re.compile(r'\.sql\.(gz|zst|bz2|xz)$'), 'dump de banco compactado'),
    (re.compile(r'(^|/)(rclone\.conf|\.netrc)$'), 'configuração com credencial'),
    (re.compile(r'(^|/)app/auth\.json$'), 'auth.json do composer (token do GitHub)'),
]
# app/.env é a exceção declarada: versionado, só com valores de desenvolvimento.
NOMES_LIBERADOS = {'app/.env'}

# --- Regras de conteúdo ---
REGRAS_FORMATO = [
    ('chave-privada', re.compile(r'-----BEGIN (?:[A-Z]+ )*PRIVATE KEY(?: BLOCK)?-----')),
    ('aws-access-key', re.compile(r'\b(?:AKIA|ASIA)[0-9A-Z]{16}\b')),
    ('github-token', re.compile(r'\b(?:gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{60,})\b')),
    ('google-api-key', re.compile(r'\bAIza[0-9A-Za-z_\-]{35}\b')),
    ('google-oauth-secret', re.compile(r'\bGOCSPX-[A-Za-z0-9_\-]{20,}')),
    ('google-refresh-token', re.compile(r'\b1//0[0-9A-Za-z_\-]{40,}')),
    ('slack-token', re.compile(r'\bxox[abposr]-[A-Za-z0-9-]{10,}')),
    ('stripe-live-key', re.compile(r'\b(?:sk|rk)_live_[0-9A-Za-z]{20,}')),
    ('anthropic-key', re.compile(r'\bsk-ant-[A-Za-z0-9_\-]{20,}')),
    ('openai-key', re.compile(r'\bsk-(?:proj-)?[A-Za-z0-9_\-]{32,}')),
    ('npm-token', re.compile(r'\bnpm_[A-Za-z0-9]{36}\b')),
]

# usuario:senha@ dentro de URL (DSN de SMTP, banco, Redis, AMQP...). O usuário ACEITA `@`: o DSN que
# vazou em 2026 era `smtp://conta@gmail.com:senha@smtp.gmail.com` — e-mail sem %40 —, e uma regex
# que recusava `@` no usuário deixava exatamente essa linha passar.
URL_COM_CREDENCIAL = re.compile(
    r'\b[a-z][a-z0-9+.\-]{1,20}://(?P<usuario>[^\s:/\'"`<>]+):(?P<senha>[^\s@\'"`<>/]+)@',
    re.I,
)

# NOME = valor, só em arquivo de configuração (em código-fonte vira ruído). Aceita a chave entre
# aspas (JSON: "password": "...") e lê o valor entre aspas INTEIRO — a senha de app do Gmail é
# exibida pelo Google com espaços ("abcd efgh ijkl mnop"), e cortar no 1º espaço a deixava passar.
ATRIBUICAO = re.compile(
    r'(?<![A-Za-z0-9_])(?P<chave>[A-Za-z0-9_]+)["\']?\s*[:=]\s*'
    r'(?:"(?P<v1>[^"\n]*)"|\'(?P<v2>[^\'\n]*)\'|(?P<v3>[^\s"\'#,;]+))'
)
# PASS e PWD só como PEDAÇO da chave (DB_PASS, MAILER_PWD), não como substring (BYPASS, PASSO).
CHAVE_SENSIVEL = re.compile(
    r'PASSWORD|PASSWD|SENHA|SECRET|TOKEN|API_?KEY|ACCESS_KEY|PRIVATE_KEY|CRYPTO_KEY|'
    r'(?:^|_)(?:PASS|PWD)(?:_|$)',
    re.I,
)
ARQUIVO_DE_CONFIGURACAO = re.compile(
    r'(^|/)(\.env[^/]*|[^/]*\.env(\.[^/]*)?|[^/]*\.(ini|ya?ml|toml|conf|cfg|properties|json|sh|bash)|Dockerfile[^/]*|docker-compose[^/]*)$',
    re.I,
)

# Só vale como placeholder um valor em caixa ÚNICA — todo minúsculo (troque_esta_senha) ou todo
# maiúsculo (SENHA_DA_LEITURA) —, com separador e com a palavra: "Kx9pQ2mZ7vWchange" é senha real
# com uma palavra dentro, e a 1ª versão o aceitava.
PALAVRAS_DE_PLACEHOLDER = re.compile(
    r'troque|change|exemplo|example|placeholder|dummy|fake|ficticio|sua[_-]|seu[_-]|'
    r'senha|password|segredo|secret|aqui|redacted|omitido|your[_-]'
)
FORMATO_DE_PLACEHOLDER = re.compile(r'[a-z0-9_.\-]*[_\-][a-z0-9_.\-]*|[A-Z0-9_.\-]*[_\-][A-Z0-9_.\-]*')
PLACEHOLDERS_EXATOS = {
    'senha', 'password', 'pass', 'passwd', 'secret', 'segredo', 'p', 'pw', 'guest', 'changeme',
    'test', 'teste', 'null', 'none', 'true', 'false', 'x', 'token', 'key',
    'ci',  # Postgres efêmero do GitHub Actions (.github/workflows/ci.yml)
    '!changeme!',  # placeholder das receitas do Symfony (mysql://app:!ChangeMe!@...)
}

# Valores conhecidos e NÃO secretos, aceitos por hash (sha256) — o valor não precisa estar aqui.
VALORES_PUBLICOS_SHA256 = {
    # DATAJUD_API_KEY: chave pública divulgada pelo CNJ (datajud-wiki.cnj.jus.br/api-publica/acesso).
    'a2c606ad6489cf5645463fa2c02cddba28d5c7c9b73685950fb68f08687f9896',
}

# Par (arquivo, chave) de valores de DESENVOLVIMENTO/TESTE já versionados de propósito.
CHAVES_DE_DESENVOLVIMENTO = {
    ('app/.env.dev', 'APP_SECRET'),
    ('app/.env.test', 'APP_SECRET'),
    ('app/.env.test', 'SYNC_CRYPTO_KEY'),
}

# Valores FICTÍCIOS de teste já versionados, aceitos só na combinação EXATA (arquivo, chave, sha256 do
# valor). Mudar o valor, a chave ou o arquivo faz a linha voltar a ser avaliada — não é uma exceção
# para a chave nem para o arquivo, é para aquele literal ali.
VALORES_DE_TESTE_DECLARADOS = {
    # .github/workflows/ci.yml: APP_SECRET do job efêmero do GitHub Actions. Só existe dentro do runner
    # do CI (banco postgres:15 descartável), nunca em ambiente real. Conferido em 12/10/2026.
    ('.github/workflows/ci.yml', 'APP_SECRET',
     'c5a5fc5f3ec2c639d6c947ae4994c86624a6b9bc1513f704c50ae2acd9b445c9'),
}

# Banco do container de DEV: usuário e senha são o padrão público do docker-compose.yml.
CREDENCIAIS_DE_DEV = {('symfony', 'symfony')}


@dataclass(frozen=True)
class Achado:
    arquivo: str
    linha: int | None
    regra: str
    tamanho: int | None = None
    commit: str | None = None

    def formatar(self) -> str:
        onde = self.arquivo if self.linha is None else f'{self.arquivo}:{self.linha}'
        if self.commit:
            onde = f'{self.commit[:10]} {onde}'
        extra = f' (valor omitido, {self.tamanho} caracteres)' if self.tamanho else ''
        return f'  {onde}  [{self.regra}]{extra}'


def eh_referencia(v: str) -> bool:
    """Aponta para outro lugar em vez de conter o valor: variável, parâmetro, serviço, Twig.

    `%` sozinho NÃO é referência: senha que começa com símbolo vai codificada no DSN (`%21...`),
    e tratar todo `%` como parâmetro deixava essa senha passar.
    """
    return bool(
        v.startswith(('$', '{{'))
        or v.startswith('%env(')
        or re.fullmatch(r'%[A-Za-z0-9_.]+%', v)
        or re.fullmatch(r'%[-+ #0]*\d*(?:\.\d+)?[sdfuxi]', v)  # printf/sprintf: '%s:%s@'
        or (v.startswith('<') and v.endswith('>'))
        or re.match(r'@[A-Za-z]', v)          # serviço do Symfony: '@App\...'
        or re.match(r'![a-z]+/', v)           # tag YAML: !php/const
    )


def eh_placeholder(valor: str) -> bool:
    v = valor.strip()
    if not v:
        return True
    if eh_referencia(v):
        return True
    if v.lower() in PLACEHOLDERS_EXATOS:
        return True
    if re.fullmatch(r'(.)\1*', v.replace(' ', '')) or re.search(r'(.)\1{7,}', v):
        return True  # xxxxxxxx, ********, GOCSPX-xxxxxxxx… (8+ caracteres iguais seguidos)
    if PALAVRAS_DE_PLACEHOLDER.search(v.lower()) and FORMATO_DE_PLACEHOLDER.fullmatch(v):
        return True
    return hashlib.sha256(v.encode()).hexdigest() in VALORES_PUBLICOS_SHA256


def eh_token_ficticio(v: str) -> bool:
    # Só para as regras de FORMATO: token de teste sequencial (ghp_0123456789abcdef...). Em senha de
    # URL ou de variável essa exceção não vale — "Empresa1234567" é senha.
    return eh_placeholder(v) or bool(re.search(r'0123456|1234567|abcdefg|ABCDEFG', v))


def verificar_nome(arquivo: str) -> str | None:
    if arquivo in NOMES_LIBERADOS:
        return None
    for padrao, motivo in NOMES_PROIBIDOS:
        if padrao.search(arquivo):
            return motivo
    return None


def verificar_linha(arquivo: str, texto: str) -> list[tuple[str, int | None]]:
    if any(marca in texto for marca in MARCA_PERMITIDO):
        return []
    achados: list[tuple[str, int | None]] = []

    for regra, padrao in REGRAS_FORMATO:
        m = padrao.search(texto)
        if m and not eh_token_ficticio(m.group(0)):
            achados.append((regra, len(m.group(0))))

    for m in URL_COM_CREDENCIAL.finditer(texto):
        usuario, senha = m.group('usuario'), m.group('senha')
        if (usuario, senha) in CREDENCIAIS_DE_DEV or eh_placeholder(senha):
            continue
        achados.append(('url-com-credencial', len(senha)))

    if ARQUIVO_DE_CONFIGURACAO.search(arquivo) and not URL_COM_CREDENCIAL.search(texto):
        for m in ATRIBUICAO.finditer(texto):
            chave = m.group('chave')
            if not CHAVE_SENSIVEL.search(chave):
                continue
            if (arquivo, chave.upper()) in CHAVES_DE_DESENVOLVIMENTO:
                continue
            entre_aspas = m.group('v3') is None
            valor = m.group('v1') if m.group('v1') is not None else m.group('v2') if entre_aspas else m.group('v3')
            if not entre_aspas and re.search(r'[()\[\]{}]', valor):
                continue  # expressão de código sem aspas (oauth.get(...)), não um literal
            if re.search(r'\$\(|\$\{|`', valor):
                continue  # montado em tempo de execução ($(grep ...), ${VAR}), não um literal
            compacto = valor.replace(' ', '')
            if len(compacto) < 8 or eh_placeholder(valor):
                continue
            if (arquivo, chave.upper(), hashlib.sha256(valor.encode()).hexdigest()) in VALORES_DE_TESTE_DECLARADOS:
                continue
            achados.append(('atribuicao-sensivel', len(compacto)))

    return achados


def git(*args: str) -> str:
    return subprocess.run(
        ['git', '-c', 'core.quotePath=false', *args],
        check=True, capture_output=True, text=True, encoding='utf-8', errors='replace',
    ).stdout


def ler_diff(texto_diff: str, commit: str | None = None) -> list[Achado]:
    """Varre as linhas '+' de um diff unificado com -U0."""
    achados: list[Achado] = []
    arquivo: str | None = None
    linha = 0
    anterior = ''
    for bruta in texto_diff.splitlines():
        # Cabeçalho `+++` só logo depois do `---`: uma linha ADICIONADA cujo conteúdo começa com
        # "++ " também aparece como "+++ " e, sem isso, trocava o arquivo corrente.
        if bruta.startswith('+++ ') and anterior.startswith('--- '):
            anterior = bruta
            alvo = bruta[4:]
            if alvo.startswith('"') and alvo.endswith('"'):
                # caminho com tab/aspas/barra vem entre aspas e com escapes de C
                alvo = alvo[1:-1].replace('\\t', '\t').replace('\\"', '"').replace('\\\\', '\\')
            arquivo = None if alvo == '/dev/null' else alvo[2:] if alvo.startswith('b/') else alvo
            continue
        anterior = bruta
        if bruta.startswith('@@'):
            m = re.search(r'\+(\d+)', bruta)
            linha = int(m.group(1)) if m else 0
            continue
        if arquivo is None or bruta.startswith(('---', 'diff ', 'index ', 'new file', 'deleted file')):
            continue
        if bruta.startswith('+'):
            for regra, tamanho in verificar_linha(arquivo, bruta[1:]):
                achados.append(Achado(arquivo, linha, regra, tamanho, commit))
            linha += 1
    return achados


def nomes_adicionados(diff_nomes: str, commit: str | None = None) -> list[Achado]:
    achados = []
    for arquivo in filter(None, diff_nomes.split('\x00')):
        motivo = verificar_nome(arquivo)
        if motivo:
            achados.append(Achado(arquivo, None, f'arquivo-proibido: {motivo}', None, commit))
    return achados


# --no-renames: com detecção de renomeação, `git mv x.txt chave.pem` vira R e escapava da checagem
# de nome; sem ela vira A (+ D). T (mudança de tipo, ex.: link virando arquivo) também entra.
SEM_RENOMEACAO = ('--no-renames', '--no-color', '--no-ext-diff', '-U0')


def modo_stage() -> list[Achado]:
    achados = nomes_adicionados(git('diff', '--cached', '--no-renames', '--name-only', '--diff-filter=A', '-z'))
    diff = git('diff', '--cached', *SEM_RENOMEACAO, '--diff-filter=ACMRT')
    return achados + ler_diff(diff)


def modo_intervalo(intervalo: str) -> list[Achado]:
    achados: list[Achado] = []
    for commit in git('rev-list', intervalo).split():
        pais = git('rev-list', '--parents', '-n', '1', commit).split()[1:]
        if len(pais) > 1:
            # Merge: só o que a RESOLUÇÃO introduziu (o resto já vem nos commits de cada lado).
            # Sem isto, um segredo digitado ao resolver conflito passava sem ser visto.
            diff = git('show', '--remerge-diff', *SEM_RENOMEACAO, '--format=', commit)
            achados += ler_diff(diff, commit)
            continue
        nomes = git('diff-tree', '--no-commit-id', '--no-renames', '--name-only', '-r',
                    '--diff-filter=A', '-z', '--root', commit)
        achados += nomes_adicionados(nomes, commit)
        diff = git('show', *SEM_RENOMEACAO, '--diff-filter=ACMRT', '--format=', commit)
        achados += ler_diff(diff, commit)
    return achados


def modo_arquivos(arquivos: list[str]) -> list[Achado]:
    achados: list[Achado] = []
    for arquivo in arquivos:
        motivo = verificar_nome(arquivo)
        if motivo:
            achados.append(Achado(arquivo, None, f'arquivo-proibido: {motivo}'))
        try:
            with open(arquivo, encoding='utf-8') as f:
                for n, texto in enumerate(f, start=1):
                    for regra, tamanho in verificar_linha(arquivo, texto.rstrip('\n')):
                        achados.append(Achado(arquivo, n, regra, tamanho))
        except (UnicodeDecodeError, IsADirectoryError, FileNotFoundError):
            continue  # binário ou ausente: fora do alcance desta guarda
    return achados


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description='Recusa credencial nova em arquivo versionado.')
    grupo = parser.add_mutually_exclusive_group()
    grupo.add_argument('--intervalo', help='intervalo de commits, ex.: origin/master..HEAD')
    grupo.add_argument('--arquivos', nargs='+', help='arquivos inteiros a auditar')
    args = parser.parse_args(argv)

    try:
        if args.intervalo:
            achados = modo_intervalo(args.intervalo)
        elif args.arquivos:
            achados = modo_arquivos(args.arquivos)
        else:
            achados = modo_stage()
    except subprocess.CalledProcessError as e:
        print(f'verificar-segredos: falha ao consultar o git ({e.cmd[3:5]}). Recusado.', file=sys.stderr)
        return 2

    if not achados:
        return 0

    print('verificar-segredos: possível credencial no que está entrando — RECUSADO.\n', file=sys.stderr)
    for achado in achados:
        print(achado.formatar(), file=sys.stderr)
    print(
        '\nSegredo não vai para o git: use app/.env.local (dev) ou o .env.prod da VPS (prod).\n'
        'Se o segredo já foi commitado, apagar não basta: TROQUE a credencial.\n'
        'Falso positivo? Acrescente `segredo:permitido` na própria linha, com o motivo.',
        file=sys.stderr,
    )
    return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
