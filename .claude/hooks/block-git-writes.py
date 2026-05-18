#!/usr/bin/env python3
import sys, json, re

data = json.load(sys.stdin)
cmd = data.get("command", "")

pattern = (
    r'\bgit\s+(?:-C\s+\S+\s+)?'
    r'(add|commit|push|pull|revert|reset|merge|rebase|rm\b'
    r'|checkout|branch\s+-[dD]|tag\s+-d'
    r'|stash\s+(push|pop|drop|clear))\b'
)
if re.search(pattern, cmd):
    print("BLOQUEADO: git write é responsabilidade exclusiva do humano.", file=sys.stderr)
    print(f"Execute manualmente no terminal externo:\n  {cmd}", file=sys.stderr)
    sys.exit(2)
