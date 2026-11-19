---
tipo: alteracao
---
## Changelog
- Cobranças > Carteiras no "Guia visual BlueJus" v30 (pedido A5 de 19/11). Sub-navegação Painel/Carteiras/Alertas/Central (`cobranca/_partials/_subnav.html.twig`, `.cobranca-subnav-item` em `cobrancas.css`): botões de 32px, raio 4px e 13px/600, como o "Nova carteira" ao lado; item ativo no Secundário (#e8f2fa / #0b5f86) e com `aria-current="page"`. Linhas das tabelas de Cobranças (`.cobranca-card .table`): realce #2b7ab0 com texto branco, também no foco do teclado. Barra de filtro comum (`filtro-tabela.css`, 6 telas): busca, filtros, datas e chips de 20px/16px para raio 4px e borda de campo #7b93a2 no tema claro. Teste `CarteirasListaPadraoV30CssTest`.

## Para o cliente
- Em Cobranças, os botões Painel, Carteiras, Alertas e Central ficaram retangulares e do mesmo tamanho do botão "Nova carteira". O item da página atual aparece destacado em azul claro.
- Ao passar o mouse numa carteira da lista, a linha inteira fica azul com o texto branco, como nas outras listas do sistema.
- A busca e os filtros (como "Modo") ficaram retangulares e com o contorno mais visível. A mudança vale para todas as telas que usam essa mesma barra de busca e filtros.
