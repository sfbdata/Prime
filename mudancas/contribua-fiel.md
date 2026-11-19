---
tipo: correcao
---
## Changelog
- Contribua, conferência com o desenho "08 - Contribua - VS 03.11.26" (pedido do Farlei, 19/11): o texto de cada pergunta do FAQ vai num `<span>` só dentro do `summary` (flex com `gap:8px`). Preventivo: o HTML do repositório já sai como um texto só e a causa do espaço do print ("Desafio de  R$ 1,00  ?") não se reproduziu na fonte; com o span, nada injetado no meio da frase vira item flex com espaço próprio.
- FAQ: sem o anel de foco do navegador ao clicar (`.ct-faq summary:focus`); pelo teclado, `:focus-visible` com contorno de 2px `#2b7ab0`.
- Janela da campanha: status com os textos do `abrir()` do `bj-campanha.js` (`CampanhaView::statusJanela`): "Começa em <data>", "Campanha encerrada em <data>", "Campanha desligada"; a faixa continua com "Prévia do Master · ...".
- Sugestões: a linha não abre o menu do botão direito (`contextmenu` como no desenho).
- Painel do Master: nome da campanha em branco volta para "BlueJus Black", como a chamada.

## Para o cliente
- Nas perguntas frequentes do Contribua, o texto de cada pergunta fica num bloco só, para não aparecerem espaços sobrando no meio da frase (como em volta do preço do Desafio).
- Ao clicar numa pergunta frequente, não aparece mais a borda colorida em volta dela. Quem navega pelo teclado continua vendo onde está.
- Na janela da campanha BlueJus Black, a situação aparece com textos mais claros, como "Começa em 25/11/2026" ou "Campanha encerrada em 30/11/2026".
- Nas sugestões do Contribua, o clique com o botão direito não abre mais o menu do navegador sobre a linha.
- No painel do Master, se o nome da campanha ficar em branco, ele volta sozinho para "BlueJus Black".
