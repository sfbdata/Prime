---
tipo: alteracao
---
## Changelog
- Painel principal (Dashboard) no "Guia visual BlueJus" v30 (pedido A7 de 19/11): os botões de ação passam a ter um só formato, 32px de altura, 0 14px, 13px/600 e raio 4px. Antes iam de 26 a 38px, com fontes de 11,5 a 13,5px e pesos 600/700: `db-pf-btn` (e `--mini`), `db-ia-voltar`, `db-pf-dlg-x` (raio 2px para 4px), `db-meta-definir`, `db-ia-pilula`, `db-ia-btn-resolver/ignorar/tarefa`, `db-ia-retorno-btn` e `db-ia-plano-aplicar`; `db-ia-abrir` de raio 5px para 4px; janela Zerar (`db-zerar-btn`: 36px/700 para 32px/600, Cancelar no Secundário, Zerar no Destrutivo #fdeceb/#a3232b, confirmar no Primário #0f6fc4), PDF (34px para 32px, ícone 14px) e os X de fechar (`db-ia-pasta-x`, `db-rel-fechar`, `db-janela-fechar`) em 32px e raio 4px; busca e lista de pastas da I.A. de raio 2px para 4px. Os controles do cabeçalho (período, pessoa, preferências) seguem em 36px, como campos, e os seletores segmentados ficam como estão.
- Linha da tabela do Dashboard: realce `--db-row-hover` #2b7ab0 com texto branco e faixa #1f6f9c, no claro e no escuro (A2); o troféu do campeão mantém o dourado e o número-link sob o mouse ganha fundo branco translúcido. Teste `DashboardBotoesV30CssTest`.

## Para o cliente
- No painel principal, os botões (como "Definir meta", as perguntas da I.A., "Resolver", "Ignorar", "Criar tarefa" e "Aplicar plano") ficaram todos do mesmo tamanho e formato, retangulares e com cantos levemente arredondados. Vale também para a janela de zerar, o botão de PDF e os botões de fechar das janelas.
- Ao passar o mouse numa linha da tabela do painel, a linha inteira fica azul com o texto branco, como nas outras listas.
