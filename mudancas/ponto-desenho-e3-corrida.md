---
tipo: correcao
---
## Changelog
- Ponto Eletrônico, corrida da E3: a análise do gestor (abonar, recusar, voltar para análise e "aprovar todos") relê a justificativa com a linha travada (`FOR UPDATE`) na mesma transação da gravação (`AnalisarJustificativaUseCase`). Se o colaborador a excluiu entre a leitura da ficha e a gravação, nada é gravado (nem a batida do esquecimento) e a ficha volta com "Esta justificativa foi excluída pelo colaborador.". No "aprovar todos", a excluída fica de fora e as outras são abonadas; o abono e a batida de cada uma comitam juntos.
- `AnalisarJustificativaUseCase::abonarPorId` é a porta para abonar uma justificativa sem a ficha (aba Equipe), com a regra da própria (P43 a) e as recusas da ficha.
- "Aprovar todos" sem nenhuma em análise: "Nenhuma justificativa em análise encontrada neste lote.".

## Para o cliente
- Quando o colaborador apaga uma justificativa no mesmo instante em que o gestor a analisa, o sistema não registra mais a batida de um pedido que já não existe: o gestor vê o aviso de que a justificativa foi excluída pelo colaborador.
