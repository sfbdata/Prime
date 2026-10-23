#!/usr/bin/env python3
# Guard de git e gh de escrita (hook GLOBAL — vale p/ orquestrador e subagentes).
# Permitido ao Claude:
#   - git e gh de leitura;
#   - `git add` / `git commit` (locais);
#   - `git push` de BRANCH DE TRABALHO, com remoto e branch explícitos, sem força
#     (o master no GitHub só recebe por PR com os checks verdes);
#   - `gh pr create` (e demais `gh pr` de leitura/edição);
#   - `git cherry-pick` SÓ na forma segura de integração autônoma:
#       um único commit  |  --continue  |  --abort.
# Bloqueado: push no master/main, de tag, forçado, de exclusão ou em massa;
# pull/merge/rebase/reset e demais reescrita local; `gh pr merge` (merge do PR de
# release = deploy em produção: é o botão do dono); disparo de workflow, release,
# segredos, variáveis, configuração do repositório e `gh api` que escreve; commit
# com --no-verify/-n; burla de core.hooksPath; e formas de cherry-pick que
# acumulem/integrem vários commits (-n/--no-commit/--stdin/--skip/range/múltiplos).
import sys, json, re, shlex

def barra_bruto(motivo):
    """Recusa antes de o guard ter contexto para explicar direito."""
    print(f"BLOQUEADO: {motivo}", file=sys.stderr)
    sys.exit(2)


# O guard falha FECHADO: se não deu para ler a entrada, não dá para saber o que o
# comando faz, então recusa. Deixar passar em silêncio seria o modo de falha caro
# (comando de escrita liberado por um erro de parsing que ninguém vê).
try:
    data = json.load(sys.stdin)
    cmd = (data.get("tool_input") or {}).get("command") or data.get("command", "")
except Exception as e:
    barra_bruto(f"nao foi possivel ler a entrada do hook ({e.__class__.__name__}).")

if not isinstance(cmd, str) or not cmd.strip():
    barra_bruto("comando ausente ou em formato inesperado na entrada do hook.")
# Tira mensagens entre aspas p/ não confundir texto de -m com flags/subcomandos.
sem_msg = re.sub(r'"[^"]*"|\'[^\']*\'', '', cmd)


def barra(motivo):
    print(f"BLOQUEADO: {motivo}", file=sys.stderr)
    print(
        "Permitido ao Claude: git add, git commit, push de branch de trabalho "
        "(git push -u origin <branch>), gh pr create e cherry-pick individual. "
        "Merge de PR, deploy, push no master/de tag/forçado, reescrita local e "
        "configuração do GitHub são do humano — execute manualmente no terminal "
        f"externo:\n  {cmd}",
        file=sys.stderr,
    )
    sys.exit(2)


# --- cherry-pick: só a forma segura de integração (um commit por vez) ---
if re.search(r'\bcherry-pick\b', sem_msg):
    # um único cherry-pick por comando — nada de encadear vários numa chamada
    if len(re.findall(r'\bcherry-pick\b', sem_msg)) > 1:
        barra("apenas um cherry-pick por comando (um commit por vez).")
    m = re.search(r'cherry-pick\b(.*?)(?:&&|\|\||;|\||\n|$)', sem_msg, re.DOTALL)
    args = (m.group(1) if m else "").split()

    def cherry_ok(a):
        if a == ["--continue"] or a == ["--abort"]:
            return True
        # qualquer flag torna a forma não-permitida
        # (cobre -n, --no-commit, --stdin, --skip, --edit, -m, ranges com flags…)
        if any(t.startswith("-") for t in a):
            return False
        # exatamente UM commit, sem range (A..B / A...B / A^..B)
        if len(a) != 1 or ".." in a[0]:
            return False
        return True

    if not cherry_ok(args):
        barra("forma de cherry-pick não permitida — use um único commit, ou "
              "--continue/--abort; sem -n/--no-commit/--stdin/--skip/ranges/"
              "múltiplos commits.")

# --- push: só de branch de trabalho, explícito e sem força ---
PROTEGIDAS = {'master', 'main', 'HEAD'}


def push_ok(args):
    """`git push [-u|--set-upstream|-q] <remoto> <branch>...`, sem força nem refspec."""
    posicionais = []
    for a in args:
        if a in ('-u', '--set-upstream', '-q', '--quiet', '-v', '--verbose'):
            continue
        if a.startswith('-'):
            return False          # --force, -f, --force-with-lease, -d, --delete, --tags, --all...
        posicionais.append(a)
    if len(posicionais) < 2:
        return False              # `git push` / `git push origin`: destino implícito
    for ref in posicionais[1:]:
        if (':' in ref or ref.startswith('+') or ref.startswith('refs/')
                or ref in PROTEGIDAS or re.match(r'^v\d', ref)):
            return False          # refspec, força por +, tag, master/main
    return True


for trecho in re.split(r'&&|\|\||;|\||\n', cmd):
    try:
        toks = shlex.split(trecho)
    except ValueError:
        toks = trecho.split()
    for i, t in enumerate(toks):
        if t != 'git':
            continue
        j = i + 1
        while j < len(toks) and toks[j] in ('-C', '-c'):
            j += 2
        if j < len(toks) and toks[j] == 'push' and not push_ok(toks[j + 1:]):
            barra("push só de branch de trabalho: `git push -u origin <branch>` "
                  "(nunca master/main, tag, refspec, força ou exclusão).")

# --- gh: merge de PR é deploy; disparos e configuração do GitHub são do humano ---
gh_proibidos = (
    r'\bgh\s+(?:'
    r'pr\s+merge|workflow\s+(?:run|enable|disable)|run\s+(?:rerun|cancel|delete)'
    r'|release\s+(?:create|delete|edit|upload|delete-asset)'
    r'|secret\s+(?:set|delete|remove)|variable\s+(?:set|delete|remove)'
    r'|repo\s+(?:edit|delete|rename|archive|unarchive|create|fork)'
    r'|ruleset\s+(?:create|edit|delete)|auth\s+(?:logout|refresh|token)'
    r'|ssh-key\s+(?:add|delete)|gpg-key\s+(?:add|delete)|label\s+(?:create|delete|edit)'
    r')\b'
)
if re.search(gh_proibidos, sem_msg):
    barra("merge de PR, disparo de workflow, release, segredos e configuração do "
          "repositório são do humano.")

for trecho in re.split(r'&&|\|\||;|\||\n', sem_msg):
    if re.search(r'\bgh\s+api\b', trecho):
        metodo = re.search(r'(?:-X|--method)[\s=]+(\w+)', trecho)
        if metodo and metodo.group(1).upper() != 'GET':
            barra("`gh api` que escreve no GitHub é do humano.")
        # Campo (-f/-F/--field/--raw-field) ou corpo (--input) sem -X GET vira POST.
        if not metodo and re.search(r'(?<![\w-])(?:-f|-F|--field|--raw-field|--input)\b', trecho):
            barra("`gh api` com campos ou corpo vira POST: escrita no GitHub é do humano.")

# --- `git merge origin/master` numa BRANCH DE TRABALHO: trazer o master para a frente ---
# Quando outro PR entra no master, a frente que ficou para trás precisa do master novo dentro dela
# (resolver conflito e testar a combinação antes do merge do PR). Só isso é liberado: alvo
# exatamente origin/master, sem estratégia nem squash, e a branch atual NÃO pode ser master/main.
# O diretório vem do `-C` ou do cwd da sessão; um `cd` no comando torna isso incerto e é recusado.
import subprocess

MERGE_FLAGS_OK = {'--no-edit', '-q', '--quiet'}


def branch_em(diretorio):
    if not diretorio:
        return None
    r = subprocess.run(['git', '-C', diretorio, 'symbolic-ref', '--short', '-q', 'HEAD'],
                       capture_output=True, text=True)
    return r.stdout.strip() if r.returncode == 0 else None


def merge_de_trabalho_ok(toks, j, diretorio):
    args = toks[j + 1:]
    if args not in (['--abort'], ['--continue']):
        posicionais = [a for a in args if not a.startswith('-')]
        if posicionais != ['origin/master'] or any(a not in MERGE_FLAGS_OK for a in args if a.startswith('-')):
            return False
    branch = branch_em(diretorio)
    return branch is not None and branch not in ('master', 'main')


merges_liberados = []
tem_cd = re.search(r'(^|&&|;|\|\||\n)\s*cd\s', sem_msg) is not None
for trecho in re.split(r'&&|\|\||;|\||\n', sem_msg):
    try:
        toks = shlex.split(trecho)
    except ValueError:
        continue
    for i, t in enumerate(toks):
        if t != 'git':
            continue
        j, diretorio = i + 1, None
        while j < len(toks) and toks[j] in ('-C', '-c'):
            if toks[j] == '-C' and j + 1 < len(toks):
                diretorio = toks[j + 1]
            j += 2
        if j < len(toks) and toks[j] == 'merge':
            if diretorio is None:
                diretorio = None if tem_cd else data.get('cwd')
            if not merge_de_trabalho_ok(toks, j, diretorio):
                barra("merge só `git merge [--no-edit] origin/master` (ou --abort/--continue), e só "
                      "numa branch de trabalho (nunca no master); com `cd` no comando, use `git -C <pasta>`.")
            merges_liberados.append(trecho)
for trecho in merges_liberados:
    sem_msg = sem_msg.replace(trecho, ' ')

# --- escrita/publicação/reescrita reservada ao humano (add/commit/cherry-pick fora) ---
proibidas = (
    r'\bgit\s+(?:-C\s+\S+\s+)?'
    # `merge(?!-)`: o hifen ja e fronteira de palavra, entao `merge\b` casava com
    # `git merge-base` / `merge-tree` / `merge-file`, que sao LEITURA pura. Acrescentar
    # \b nao resolve — so o lookahead negativo separa o subcomando das formas hifenizadas.
    r'(pull|revert|reset|merge(?!-)|rebase|rm\b'
    r'|checkout|branch\s+-[dD]|tag\s+-d'
    r'|stash\s+(push|pop|drop|clear))\b'
)
if re.search(proibidas, sem_msg):
    barra("operação git de escrita/publicação/reescrita reservada ao humano.")

# --- burla de hooks: --no-verify (ou -n) em commit não é aceito ---
# Olha só o TRECHO do commit: um `bash -n` ou `grep -n` encadeado no mesmo comando não é
# flag do commit (era o falso positivo mais comum deste guard).
for trecho in re.split(r'&&|\|\||;|\||\n', sem_msg):
    if re.search(r'\bgit\b.*\bcommit\b', trecho) \
            and re.search(r'(--no-verify|(?<![\w-])-\w*n\b)', trecho):
        barra("--no-verify/-n não é permitido em commit (não burle os hooks).")

# --- burla via override do caminho de hooks ---
# Só a ESCRITA de core.hooksPath por git é burla. Consultar (`--get`/`--list`) é livre, e
# mencionar a string em prosa/echo ou tocar num caminho como `.git/config` é inofensivo.
# Por isso cada forma exige contexto de escrita, não a mera presença do texto — foi
# justamente a presença-crua que virou falso positivo (barrava `git config --get` e echos).
hookspath_escrita = (
    # `git -c core.hooksPath=…` — a atribuição inline. Exige o `-c` antes do `=`:
    # `core.hooksPath=` cru aparece em echo/diagnóstico benigno e não é burla.
    re.search(r'-c\s+core\.hooksPath\s*=', cmd)
    # `git config --unset[-all] core.hooksPath`
    or re.search(r'--unset\S*\s+core\.hooksPath', cmd)
    # `git config core.hooksPath <valor>` — exige `git … config`, um valor depois do
    # nome, e que NÃO seja uma consulta. `.git/config` não casa (não há `git ` antes de
    # `config`); `git config --get core.hooksPath` não casa (sem valor após o nome + tem --get).
    or (re.search(r'\bgit\b(?:\s+-\S+)*\s+config\b', cmd)
        and re.search(r'core\.hooksPath\s+(?![&|;<>)])\S', cmd)
        and not re.search(r'--(get|list|get-all|get-regexp)\b', cmd))
)
if hookspath_escrita:
    barra("definir/remover core.hooksPath não é permitido (burla de hook); consultar é livre.")

sys.exit(0)
