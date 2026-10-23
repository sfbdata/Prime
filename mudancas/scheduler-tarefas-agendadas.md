---
tipo: alteracao
---
## Changelog
- As tarefas agendadas do sistema (purga, DJEN, índices monetários, resumos por e-mail, cobrança do checklist, relatórios mensais, foto do Dashboard e envio ao Drive) saem do crontab do host e passam ao Symfony Scheduler, no contêiner `jusprime_scheduler_prod`, com horários em Brasília, trava e estado no banco (migration `Version20261023150800`).
- Falha de tarefa agendada gera erro no log e, com `AGENDADOR_ALERTA_EMAIL` preenchida, um e-mail de aviso.
- A foto diária do Dashboard passa a ser agendada (23h55), o que liga as tendências de Vencidas e Prazos.
- O deploy automático guarda e devolve também a imagem do agendador na volta.
