---
tipo: novidade
---
## Changelog
- Contribua: dobra automática do espaço. Com a chave "Ampliação de GB automática" ligada e assinatura ativa, o envio que passa do espaço contratado dobra o multiplicador (quantas vezes precisar, sem limite de vezes, como no desenho), muda o valor da assinatura no gateway (vale da próxima cobrança, sem proporcional) e avisa o primário.
- Contribua: gateway recusou o valor novo: nada muda, vai ao log e o Master recebe aviso; o próximo envio acima do limite tenta de novo.
- Contribua: os botões + e − do maior pacote deixam de parar em ×16 (decisão do Samuel, igual ao desenho 08); o único limite é o valor que a coluna guarda (R$ 21.474.836,47 por mês).
- Gateway: novo `alterarValor` no contrato; no Asaas, `PUT /subscriptions/{id}` com `updatePendingPayments`.
- Master: a receita da apuração de custos considera o multiplicador (preço do plano vezes as vezes que o pacote foi dobrado).

## Para o cliente
- Quem ligou a "Ampliação de GB automática" não fica mais sem espaço: quando os arquivos passam do contratado, o espaço dobra na hora.
- O valor novo vale a partir da próxima cobrança, sem cobrança extra no mês, e o escritório recebe um aviso com o espaço e o valor novos.
- Se a ampliação não puder ser feita, nada muda na assinatura e a equipe do BlueJus é avisada para resolver.
