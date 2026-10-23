---
tipo: alteracao
---
## Changelog
- As tarefas agendadas do sistema que estavam no crontab real da VPS (purga, DJEN, resumo semanal aos clientes, relatórios mensais de pastas e envio ao Drive) saem do crontab do host e passam ao Symfony Scheduler, no contêiner `jusprime_scheduler_prod`, com horários em Brasília, trava e estado no banco (migration `Version20261023150800`).
- O resumo diário, a cobrança do checklist e a importação de índices monetários, que nunca rodaram em produção, ficam desligados até decisão do dono.
- Falha de tarefa agendada gera erro no log e, com `AGENDADOR_ALERTA_EMAIL` preenchida, um e-mail de aviso.
- A foto diária do Dashboard passa a ser agendada (23h55), o que liga as tendências de Vencidas e Prazos.
- O deploy automático guarda e devolve também a imagem do agendador na volta.
