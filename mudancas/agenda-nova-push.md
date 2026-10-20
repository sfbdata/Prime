---
tipo: novidade
---
## Changelog
- Agenda: lembretes por push com o navegador fechado (Service Worker `sw-lembretes.js`, inscrição em `POST /agenda/push/inscrever`, varredura a cada minuto pelo Messenger sem cron, envio sem repetição); comando `app:agenda:push-varredura`; tabelas `agenda_push_inscricao`, `agenda_push_enviado` e `agenda_push_varredura`; dependência `minishlink/web-push`.

## Para o cliente
- Os lembretes da Agenda agora chegam como notificação mesmo com o BlueJus fechado, desde que você tenha permitido as notificações no navegador.
