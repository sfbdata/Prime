#!/usr/bin/env python3
# Guard de git de escrita (hook GLOBAL — vale p/ orquestrador e subagentes).
# Permitido ao Claude:
#   - git de leitura;
#   - `git add` / `git commit` (locais);
#   - `git cherry-pick` SÓ na forma segura de integração autônoma:
#       um único commit  |  --continue  |  --abort.
# Bloqueado: push/pull/merge/rebase/reset e demais publicação/reescrita; commit
# com --no-verify/-n; burla de core.hooksPath; e formas de cherry-pick que
# acumulem/integrem vários commits (-n/--no-commit/--stdin/--skip/range/múltiplos).
import sys, json, re

data = json.load(sys.stdin)
cmd = (data.get("tool_input") or {}).get("command") or data.get("command", "")
# Tira mensagens entre aspas p/ não confundir texto de -m com flags/subcomandos.
sem_msg = re.sub(r'"[^"]*"|\'[^\']*\'', '', cmd)


def barra(motivo):
    print(f"BLOQUEADO: {motivo}", file=sys.stderr)
    print(
        "Permitido ao Claude: git add, git commit e cherry-pick individual "
        "(um commit | --continue | --abort). push/merge/rebase/reset e "
        "publicação remota são do humano — execute manualmente no terminal "
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

# --- escrita/publicação/reescrita reservada ao humano (add/commit/cherry-pick fora) ---
proibidas = (
    r'\bgit\s+(?:-C\s+\S+\s+)?'
    r'(push|pull|revert|reset|merge|rebase|rm\b'
    r'|checkout|branch\s+-[dD]|tag\s+-d'
    r'|stash\s+(push|pop|drop|clear))\b'
)
if re.search(proibidas, sem_msg):
    barra("operação git de escrita/publicação/reescrita reservada ao humano.")

# --- burla de hooks: --no-verify (ou -n) em commit não é aceito ---
if re.search(r'\bgit\b', sem_msg) and re.search(r'\bcommit\b', sem_msg) \
        and re.search(r'(--no-verify|(?<![\w-])-\w*n\b)', sem_msg):
    barra("--no-verify/-n não é permitido em commit (não burle os hooks).")

# --- burla via override do caminho de hooks ---
if re.search(r'core\.hooksPath', cmd):
    barra("override de core.hooksPath não é permitido (burla de hook).")

sys.exit(0)
