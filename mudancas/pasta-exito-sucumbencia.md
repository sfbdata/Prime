---
tipo: novidade
---
## Changelog
- Pasta, aba Financeiro: os tipos "Êxito" e "Sucumbência" do "Adicionar pagamento" passam a funcionar como no desenho 02 VS 18.10.26. Pedem só o percentual (padrão 20 e 10) e gravam uma linha prevista, sem vencimento ("Honorários de êxito (N%)" / "Sucumbência estimada (N%)"), com selo azul "Previsto" e "N% sobre o total contratado · condicionado ao resultado".
- O previsto fica fora do contratado, do recebido, da barra, dos vencidos, das pendências e do relatório de pastas paradas; aparece só como "+ R$ X previsto em êxito | sucumbência" abaixo da barra e, na impressão, como "Previsto (êxito/sucumbência)". O valor é calculado na leitura (`BaseDoPercentual`) e congela ao "Marcar como recebido" ou ao "Corrigir valor".
- O card "Honorários contratuais" da faixa vira "Total contratado": soma automática, só leitura, dos lançamentos não previstos (base do percentual). A edição do valor da causa sai desta tela (segue no cartão do Processo).
- Migration `Version20261020151400`: `pasta_pagamento.tipo` (padrão 'contrato'), `percentual` e `vencimento` nulo, com CHECKs de coerência. Sem backfill: lançamentos antigos ficam como contrato.

## Para o cliente
- No Financeiro da pasta, agora dá para lançar honorários de êxito e de sucumbência: basta informar o percentual. Eles aparecem como previstos e só entram no recebido quando forem marcados como recebidos.
- O card do topo passou a mostrar o "Total contratado", somado automaticamente a partir dos pagamentos.
