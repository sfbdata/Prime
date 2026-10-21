---
tipo: alteracao
---
## Changelog
- Registro da pasta ganha os cartões do sistema que faltavam (desenho 02, `autoReg`): "Responsável: alterado de "X" para "Y"." (com "Sem responsável" no lado vazio), "Pró-bono: pasta marcada como pró-bono." / "marcação de pró-bono retirada.", "Dados da pasta: dados editados: …" (número, cliente e ação, de → para) e "Contrato: marcado como assinado." / "reaberto como pendente." a cada mudança pelo selo do Financeiro. Cada cartão só sai quando o valor de fato muda, com o tenant da pasta e o autor da ação.
- Responsável e pró-bono saem do `PastaController` legado para `AlterarResponsavelDaPastaUseCase` e `AlterarProBonoDaPastaUseCase` (guarda do escritório no caso de uso); `EditarPastaUseCase` e `AlterarSituacaoContratoUseCase` passam a escrever o cartão. O selo não duplica o cartão do contrato: o "(fase Contrato concluída no andamento)" continua só quando é o andamento que move o contrato.
- Ícones dos cartões novos no Registro: `bi-person-badge`, `bi-heart`, `bi-folder2-open` e `bi-lock-fill` no cadeado (antes caía no megafone).
- Registros de Detalhes ganham a tag fixa "Observação" (roxo, caixa alta) depois do autor, nos dois moldes do cartão, e o "· Observação" no cabeçalho do menu e na linha da impressão (antes "Atendimento"). A etiqueta variável (Combinado, Ligação, Atendimento) segue pendente (PAS-029).

## Para o cliente
- O Registro da pasta agora conta quando o responsável muda, quando a pasta vira ou deixa de ser pró-bono, quando os dados da pasta são editados e quando o contrato é marcado como assinado ou reaberto.
- Os registros da aba Detalhes mostram a etiqueta "Observação".
