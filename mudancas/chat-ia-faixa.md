---
tipo: correcao
---
## Changelog
- Chat I.A. (pedido A8 de 19/11): a barra "I.A. gratuita: x de y peças usadas" (`inteligencia/_barra_de_uso.html.twig`) perde a faixa de fundo e a borda na página do Chat (`.ci-principal > .ias-barra` em `chat-ia.css`); texto, link "Ganhar mais" e trilho passam a usar os tokens do tema (`--ci-apoio`, `--ci-acao`, `--ci-borda`), legíveis no claro e no escuro. Central e gaveta de agentes seguem com a barra como estava.

## Para o cliente
- No Chat I.A. com o tema escuro, a barra que mostra quantas peças da I.A. gratuita você já usou não aparece mais numa faixa branca. Ficam só o texto e a barrinha de progresso, nas cores do tema.
