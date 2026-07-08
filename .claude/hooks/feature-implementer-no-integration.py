#!/usr/bin/env python3
# Trava específica do subagente feature-implementer (PreToolUse Bash).
# O implementador só pode: git de leitura, `git add` explícito e `git commit` do
# próprio bloco (na própria worktree). Ele NUNCA integra: cherry-pick, merge,
# rebase, reset, push e demais publicação/reescrita são bloqueados aqui —
# integração é exclusiva do orquestrador.
#
# Roda EM ADIÇÃO ao hook global block-git-writes.py; este fecha a brecha do
# cherry-pick (que o global libera na forma segura para o orquestrador).
import sys, json, re

data = json.load(sys.stdin)
cmd = (data.get("tool_input") or {}).get("command") or data.get("command", "")
# Tira mensagens entre aspas p/ não confundir texto de -m com subcomandos.
sem_msg = re.sub(r'"[^"]*"|\'[^\']*\'', '', cmd)


def barra(motivo):
    print(f"BLOQUEADO (feature-implementer): {motivo}", file=sys.stderr)
    print(
        "O implementador só faz git de leitura, `git add` e `git commit` do "
        "próprio bloco na sua worktree. Integração (cherry-pick/merge/rebase/"
        f"reset) e publicação são do orquestrador/humano.\n  {cmd}",
        file=sys.stderr,
    )
    sys.exit(2)


# cherry-pick é PROIBIDO ao implementador em QUALQUER forma (é integração).
if re.search(r'\bcherry-pick\b', sem_msg):
    barra("cherry-pick é integração — reservado ao orquestrador.")

# Integração / publicação remota / reescrita de histórico.
proibidas = (
    r'\bgit\s+(?:-C\s+\S+\s+)?'
    r'(push|pull|revert|reset|merge|rebase|rm\b'
    r'|checkout|branch\s+-[dD]|tag\s+-d'
    r'|stash\s+(push|pop|drop|clear))\b'
)
if re.search(proibidas, sem_msg):
    barra("operação de integração/publicação/reescrita reservada ao orquestrador/humano.")

# Burla de hooks (mesma proteção do hook global).
if re.search(r'\bgit\b', sem_msg) and re.search(r'\bcommit\b', sem_msg) \
        and re.search(r'(--no-verify|(?<![\w-])-\w*n\b)', sem_msg):
    barra("--no-verify/-n não é permitido em commit (não burle os hooks).")
if re.search(r'core\.hooksPath', cmd):
    barra("override de core.hooksPath não é permitido (burla de hook).")

sys.exit(0)
