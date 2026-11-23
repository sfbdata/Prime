---
tipo: novidade
---
## Changelog
- Cota de e-mail: acima de 2.000 e-mails enviados em 24 h (todos, também os do sistema), o aviso do Master no topo da tela passa ao estado `trocar` ("Hora de contratar outro serviço de e-mail") e a varredura de 15 min manda um e-mail a cada Master ativo, no máximo um a cada 24 h (origem `aviso_troca_servico`, isenta do teto diário). Nada é bloqueado.

## Para o cliente
- O Master passa a ser avisado, na tela e por e-mail (no máximo uma vez por dia), quando o BlueJus manda mais de 2.000 e-mails em 24 horas: é a hora de contratar outro serviço de e-mail. Nenhum e-mail deixa de sair por causa desse aviso.
