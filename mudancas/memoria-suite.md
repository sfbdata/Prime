---
tipo: interno
---
## Changelog
- Suíte de testes: `scripts/frente-testar.sh` e `scripts/testar-paralelo.sh` rodam o PHP com o OPcache ligado na CLI (receita única em `scripts/lib/opcache-de-teste.sh`; no ParaTest, também nos processos filhos via `--passthru-php`). Sem ele, o bytecode das quase 7 mil classes carregadas ficava na memória do processo: o pico da suíte serial cai de cerca de 400 MB para cerca de 210 MB e o tempo, de cerca de 7 para cerca de 5 minutos. A receita para rodar `php bin/phpunit` à mão está em `app/tests/CLAUDE.md`.
- `scripts/frente-testar.sh` acha o repositório principal também quando chamado de dentro de uma worktree (antes o caminho saía dobrado).
- Logs de teste (`test.log`, `audit_test.log`, `mcp_test.log`) voltam a começar vazios quando passam de 64 MB; antes cresciam sem limite entre rodadas (800 MB e 1,5 GB). O processo principal do ParaTest não limpa, para não apagar o log de uma rodada serial.
