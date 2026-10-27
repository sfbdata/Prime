---
tipo: interno
---
## Changelog
- Gateway M1: com a integração da Cora conectada e a chave "Cobrança PIX no Trabalho Externo" ligada, a aba Pagamento pede uma cobrança PIX dinâmica (valor fixo, 24 horas) e o banco confirma o pagamento sozinho. Valor menor fica divergente e não paga; o botão manual cancela a cobrança viva (e, com a trava ligada, exige motivo); ajustar o valor cancela a cobrança do valor antigo. Sem a chave, nada muda. Chaves novas `te` e `te_trava` desligadas.

## Para o cliente
- Sem efeito para o cliente enquanto as chaves estiverem desligadas.
