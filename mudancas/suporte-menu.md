---
tipo: novidade
---
## Changelog
- Menu lateral, seção ADMIN: item "Suporte" entre "Solicitações de Acesso" e "Service Desk (TI)", só para o Master e o destinatário do suporte (a mesma regra de `/suporte/mensagens`, agora em `SuporteEntradaController::garantirLeitorDoSuporte` e `PaginaDeEntrada::ehDestinatarioDoSuporte`). A função Twig `suporte_no_menu()` devolve o número de pendentes, ou nulo para quem não lê o suporte.
- `mensagem_suporte.respondida_em` (migration Version20261119140000; nula = pendente). As mensagens que já existem entram como pendentes.
- `/suporte/mensagens`: coluna "Situação". "Responder por e-mail" abre o e-mail e marca como respondida (POST `auth_suporte_resposta`, com CSRF, pelo `js/suporte-mensagens.js`); "Desfazer" volta para pendente.

## Para o cliente
- Quem cuida do suporte do BlueJus ganhou o item "Suporte" no menu, com o número de mensagens ainda sem resposta. Assim a mensagem enviada pelo "Falar com suporte" da tela de entrada é vista e respondida mais rápido.
