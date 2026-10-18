#!/usr/bin/env python3
# Trava do subagente feature-review-agent (PreToolUse Bash): o revisor só lê.
#
# A garantia principal é o agente não ter Edit/Write/NotebookEdit. Esta trava é a rede de
# segurança do Bash: barra git e gh que escrevem, redirecionamento para arquivo, os comandos de
# arquivo mais comuns, comandos Doctrine que gravam no banco e SQL de escrita no psql. Não é
# sandbox: um interpretador (`python3 -c`) ainda conseguiria gravar. Roda EM ADIÇÃO ao hook
# global block-git-writes.py.
import json
import re
import sys

data = json.load(sys.stdin)
cmd = (data.get("tool_input") or {}).get("command") or data.get("command", "")


def barra(motivo):
    print(f"BLOQUEADO (feature-review-agent): {motivo}", file=sys.stderr)
    print(
        "O revisor é somente leitura: aponta, não conserta. Para buscar, use Grep/Glob/Read "
        f"ou git de leitura.\n  {cmd}",
        file=sys.stderr,
    )
    sys.exit(2)


def sem_texto_entre_aspas(texto):
    # Padrão de grep, mensagem e SQL entre aspas não são comando: saem. A exceção é o corpo de
    # `bash -c '...'` / `sh -c "..."`, que É comando (o caso do `docker exec ... bash -c`).
    def troca(m):
        if m.group(1):
            return f"{m.group(1)} {m.group(2) or m.group(3) or ''} "
        return " "
    return re.sub(r"(\b(?:bash|sh)\s+-c\s+)?(?:'([^']*)'|\"([^\"]*)\")", troca, texto)


visivel = sem_texto_entre_aspas(cmd)

# Início de comando: começo, separador, ou logo depois de `bash -c`, `xargs`, `sudo` e
# `docker exec <container>`.
INICIO = (r"(?:^|[;&|`(]|\b(?:bash|sh)\s+-c|\bxargs(?:\s+-\S+)*|\bsudo"
          r"|\bexec(?:\s+-\w+(?:\s+\d+)?)*\s+[\w.-]+)\s*")

GIT_LEITURA = {
    "status", "diff", "log", "show", "blame", "grep", "ls-files", "ls-tree", "rev-parse",
    "rev-list", "merge-base", "cherry", "describe", "shortlog", "cat-file", "show-ref",
    "for-each-ref", "fetch", "range-diff", "help", "version",
}
GIT_OPCOES_GLOBAIS = r"(?:(?:-C|-c)\s+\S+|--no-pager|--[\w-]+=\S+|--(?:paginate|no-optional-locks))"
for sub, resto in re.findall(rf"\bgit\s+(?:{GIT_OPCOES_GLOBAIS}\s+)*([\w-]+)([^;&|]*)", visivel):
    if sub in GIT_LEITURA:
        continue
    if sub == "branch" and re.fullmatch(r"(\s+(--show-current|-a|-r|-v|-vv|--list|--contains|--merged|--no-merged)(\s+[\w./-]+)?)*\s*", resto):
        continue
    if sub in ("worktree", "stash", "remote") and re.match(r"\s+(list|show|-v)\b", resto):
        continue
    barra(f"git {sub} não é leitura.")

GH_LEITURA = r"(pr|issue|release|run|repo|workflow)\s+(view|diff|list|checks|status)|auth\s+status|api(\s+\S+)?"
for sub in re.findall(r"\bgh\s+([\w-]+(?:\s+[\w/.-]+)?)", visivel):
    if re.fullmatch(GH_LEITURA, sub):
        continue
    barra(f"gh {sub} não é leitura.")
if re.search(r"\bgh\s+api\b[^;&|]*(\s-X\s*(?!GET)|--method\s+(?!GET)|\s-[fF]\s|--field|--raw-field|--input)", visivel):
    barra("gh api que escreve.")

# `>` e `>>` para arquivo. Ficam de fora `->` e `=>` (PHP), `>=`, `2>&1` e `/dev/null`.
if re.search(r"(?<![-=<>])>{1,2}(?![>=&])\s*(?!/dev/null)\S", visivel):
    barra("redirecionamento para arquivo.")

if re.search(INICIO + r"(rm|rmdir|mv|cp|touch|mkdir|chmod|chown|ln|truncate|dd|patch|tee|install|wget)\b", visivel):
    barra("comando que cria, altera ou apaga arquivo.")
if re.search(r"\bsed\b[^;&|]*\s(-i|--in-place)", visivel) or re.search(r"\bperl\b[^;&|]*\s-\w*i", visivel):
    barra("edição de arquivo no lugar (sed -i / perl -i).")
if re.search(r"\bcurl\b[^;&|]*\s(-o|-O|--output|--remote-name)\b", visivel):
    barra("curl gravando arquivo.")
if re.search(r"\bfind\b[^;&|]*\s-(delete|exec|execdir|ok|okdir)\b", visivel):
    barra("find com -delete/-exec.")
if re.search(r"\b(doctrine:(schema:(update|drop|create)|database:(drop|create)|migrations:(migrate|execute|rollup|version)"
             r"|fixtures:load)|dbal:run-sql|cache:pool:clear|messenger:consume)\b", visivel):
    barra("comando do console que grava no banco ou no estado da aplicação.")
if re.search(r"\bpsql\b", cmd) and re.search(
        r"\b(insert\s+into|update\s+[\w.\"]+\s+set|delete\s+from|drop|alter|truncate|create|grant|revoke)\b",
        cmd, re.IGNORECASE):
    barra("SQL que escreve.")

sys.exit(0)
