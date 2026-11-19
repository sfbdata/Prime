---
tipo: alteracao
---
## Changelog
- Ponto, Cálculo e Kanban: contraste e bordas do Guia visual v30. Texto de apoio passa a `#5f7684`, bordas de área a `#d6e0e7`, bordas de campo e controles a `#7b93a2` (novo token `--pe-borda-campo` no Ponto). Só CSS e marcação de ícone.
- Ponto: dia desabilitado do calendário sem opacidade extra; no calendário da justificativa, dia selecionado em negrito e sublinhado, hoje em negrito, dia desabilitado no tom de apoio, sem opacidade; texto do dia selecionado escuro também no tema escuro.
- Kanban: prazo vencido em `#b91c1c` com ícone de alerta (`bi-exclamation-circle`); avatar com fundo `#0a6fa0`; cor de marcador selecionada ganha um check (classe `selecionado`, aplicada pelo mesmo JS que já marcava a borda).

## Para o cliente
- Textos de apoio, campos e bordas ficaram mais nítidos no Ponto, no Cálculo e no Kanban, mais fáceis de ler.
- No Kanban, o prazo vencido agora aparece com um ícone de alerta além da cor vermelha, e a cor escolhida para o marcador mostra um sinal de certo.
- No calendário de justificativa do Ponto, os dias escolhidos ficam em negrito e sublinhados, e o dia de hoje também se destaca sem depender só da cor.
