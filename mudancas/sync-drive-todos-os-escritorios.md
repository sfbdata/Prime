---
tipo: alteracao
---
## Changelog
- Agendador: o envio ao Google Drive de hora em hora (`app:sync:reconciliar`) roda sem `--tenant-id` e percorre todos os escritórios com Drive conectado; o escritório que desconecta para de enviar (decisão do dono, 24/10/2026). Sem nenhum conectado, termina com sucesso, sem aviso de falha.

## Para o cliente
- A cópia automática para o Google Drive passa a valer para todo escritório que conectar o próprio Drive e para quando o escritório desconecta.
