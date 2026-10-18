---
tipo: interno
---
## Changelog
- `scripts/restore.sh` reescrito para restaurar sem perda: confere o backup inteiro (TAR sem `..`, caminho absoluto nem link, gzip, espaço em disco, dump restaurado num banco descartável com pelo menos uma tabela, uploads num volume descartável) antes de tocar no que está no ar, e mostra o banco no ar ao lado do backup antes do `CONFIRMO`.
- Recusa rodar com deploy em andamento (mesma trava do deploy automático), com o worker ligado ou com o php ligado quando há uploads.
- O banco anterior fica preservado como `<banco>_before_restore_<pid>`; a troca de nomes é uma transação só, com conexões bloqueadas e derrubadas; permissões e configurações do banco são replicadas no novo; falha no meio devolve os uploads anteriores; o fim imprime o comando de desfazer já preenchido.
- Aceita o formato atual do `backup.sh` (uploads em `uploads_<TS>.tar` separado), o histórico (uploads embutidos), `--database-only` e o dump do deploy automático (`.dump`).
- Novo teste de integração `scripts/testar-restore.sh` (24 cenários em postgres e volume descartáveis) e runbook `docs/runbooks/restaurar-backup.md`.
