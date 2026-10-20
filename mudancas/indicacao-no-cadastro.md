---
tipo: novidade
---
## Changelog
- Indicar o BlueJus: a conta criada pelo "Criar minha conta" por quem chegou pelo link curto `/i/{codigo}` fica ligada a quem indicou (colunas novas `indicacao.indicado_user_id`, uma vez por conta com índice único parcial, e `indicacao.por_cadastro`, que mantém a atribuição fora dos envios mesmo se a conta nova for apagada).
- Ouvinte do evento `ContaCriadaPeloCadastro` no domínio Indicação: lê o código da sessão, tira a chave depois de usar e nunca derruba o cadastro (falha só vai para o log). Código inexistente, de pessoa inativa ou da própria conta nova é ignorado; a pausa não impede, porque atribuir não envia nada.
- A atribuição conta em "Viraram conta" e em "Suas indicações" (cada conta uma vez só), mas não nos limites, no alerta de disparo alto nem nas contagens de envio do painel.

## Para o cliente
- Quem cria a conta pelo seu link de convite agora aparece em "Suas indicações" como conta criada.
