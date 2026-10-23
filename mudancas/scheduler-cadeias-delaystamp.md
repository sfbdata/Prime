---
tipo: alteracao
---
## Changelog
- As varreduras periódicas (push dos lembretes a cada minuto, Google Agenda a cada 5 minutos, automações do Comercial a cada 15 minutos e expansor de campanhas a cada minuto) passam a ser disparadas pelo Symfony Scheduler, no worker de produção, em vez de se reagendarem sozinhas. Uma volta que falha não interrompe mais a varredura.
- Saem os comandos `app:agenda:push-varredura`, `app:agenda:google-varredura` e `app:comercial:varredura`: não é mais preciso semear nem religar as varreduras.
- Uma campanha confirmada começa a sair na volta do minuto seguinte do expansor.
