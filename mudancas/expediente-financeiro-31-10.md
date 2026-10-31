---
tipo: novidade
---
## Changelog
- Aba Financeiro, caixa CONTRATOS: o botão Adicionar aceita vários arquivos de uma vez (desenho de 31.10). Cada arquivo sobe em fila e aparece na lista, com abrir, renomear e excluir como antes.
- Contrato assinado digitalmente (arquivo .p7s/.p7m ou PDF com assinatura) cujo nome tem "contrato" passa o selo Contrato para Assinado na hora. Recibo ou guia assinados não mexem no contrato.
- Sem nenhum arquivo em CONTRATOS, o selo Contrato fica Pendente e a dica diz: "Nenhum contrato anexado: fica pendente até anexar o contrato em CONTRATOS."
- Pagamentos, tipo Recorrente (honorários mensais fixos): conferido de ponta a ponta (valor mensal x meses, sem entrada nem juros, "Mensalidade k/n", mês a mês, 1 a 60 meses) e coberto por teste da tela. Sem migration.
- Upload de CONTRATOS aceita também assinatura digital .p7s e .p7m (checagem de extensão e tipo; octet-stream só para essas duas extensões; mesmo limite de 10 MB). Executável renomeado continua recusado.
