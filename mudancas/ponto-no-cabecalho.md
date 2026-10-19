---
tipo: novidade
---
## Changelog
- Cabeçalho: atalho de bater ponto na sua linha do painel de presença (desenho 03 VS 18.10.26): "Bater ponto", "Em intervalo desde", "Em jornada desde" e "Jornada X às Y". Usa a batida de sempre (`POST /ponto/batida`), com a mesma cerca permissiva, prazos e avisos da tela do ponto; não aparece na própria tela do ponto.
- Ponto: rota de leitura `GET /ponto/estado-do-dia` com o estado do ponto de hoje da própria pessoa (a escolha da folha, tenant explícito).
- Ponto: a lógica pura da batida (posição com prazo, cerca, atraso, envio e leitura da resposta) saiu do script da tela para `public/js/ponto-batida.js`, sem mudar o comportamento; a tela avisa se o módulo não carregar.

## Para o cliente
- Agora dá para bater o ponto de entrada e de saída direto pelo painel de quem está on-line, no avatar do topo.
