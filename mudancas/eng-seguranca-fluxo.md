---
tipo: interno
---
## Changelog
- O Claude Code passa a poder enviar branch de trabalho e abrir PR; merge de PR (que publica),
  disparo de workflow e configuração do GitHub continuam do dono, com testes do guard no CI.
- `persist-credentials: false` no checkout do workflow de e-mails.
- `scripts/frente-abrir.sh` não morre mais no `cache:clear` do composer.
- Runbook para trocar a deploy key da VPS por uma somente leitura.
