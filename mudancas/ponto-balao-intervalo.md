---
tipo: alteracao
---
## Changelog
- Ponto, cartão "Registrar Ponto": o balão azul do intervalo em andamento passa a ter duas linhas. Em destaque "Volte às HH:MM" (início do repouso mais o mínimo da Jornada, arredondado para o minuto seguinte quando a batida tem segundos) e, embaixo, "Em repouso há HH:MM:SS" ao vivo.
- Cumprido o mínimo, o destaque vira "Já pode voltar" (balão verde). Sem o mínimo (validação do repouso ou chave `ponto-alertas` desligada), só "Em repouso há HH:MM:SS".
- A "Saída prevista" sai do balão durante o repouso e continua aparecendo depois do retorno, como antes.
- Saem do catálogo `ponto_intervalo` os textos longos (`aviso_em_andamento`, `aviso_faltam`, `aviso_cumprido`, `saida_prevista`); entram `volte_as`, `ja_pode_voltar` e `em_repouso_ha`. A janela "Antes de registrar" não muda.

## Para o cliente
- No Ponto, durante o intervalo, o aviso do cartão ficou mais curto: mostra em destaque a hora de voltar (por exemplo, "Volte às 14:17") e, embaixo, há quanto tempo você está em repouso. Quando o intervalo mínimo se completa, aparece "Já pode voltar".
- A saída prevista do dia deixa de aparecer nesse aviso durante o intervalo e volta a aparecer depois que você registra o retorno.
