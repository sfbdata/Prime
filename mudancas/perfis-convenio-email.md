---
tipo: novidade
---
## Changelog
- Ao mudar o convênio OAB de um estado no painel do Master (valor diferenciado, sem convênio ou grátis de novo), o sistema enfileira um e-mail para cada usuário primário ativo dos escritórios daquele estado, com o valor em reais, a data de vigência e, quando houver, o motivo.
- Só avisa quando o convênio realmente mudou: salvar o mesmo estado duas vezes não repete o e-mail. Um e-mail por pessoa, sem expor a lista de destinatários, pela fila de envio e dentro da cota diária: todos entram na fila e o envio adia o que passar da cota, então em estado grande a entrega pode se espalhar por mais de um dia. A mudança e a fila andam juntas: se os avisos não entram na fila, nada é alterado.
- A resposta de POST /master/convenios ganhou o campo `avisados` (quantos e-mails entraram na fila). Sem migration.

## Para o cliente
- Se você é usuário primário de um escritório e o convênio da OAB do seu estado mudar, você recebe um e-mail com o novo valor da mensalidade e a data em que passa a valer.
