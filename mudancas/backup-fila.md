---
tipo: novidade
---
## Changelog
- Backup: o pedido agora roda em segundo plano. `PedirBackupUseCase` cria o pedido (um aberto por pessoa e escritório, no máximo 1 a cada 24 h) e despacha `GerarBackup` no transport `async`; `GerarBackupHandler` monta o ZIP, marca `pronto` (validade de 7 dias) e avisa pelo sino, ou marca `falhou` sem derrubar a fila (reentrega em `montando` também vira `falhou`). Nova tarefa agendada `app:backup:vencer` (de hora em hora): vence o backup pronto fora da validade e apaga o ZIP depois do commit; pedido preso em `montando` há mais de 2 h vira `falhou`.

## Para o cliente
- Continuamos preparando o Backup e Exportação de Dados: agora o arquivo é montado em segundo plano, você recebe um aviso no sino quando estiver pronto e ele fica disponível por 7 dias. Você poderá pedir um backup por dia. Esta etapa ainda não aparece na tela.
