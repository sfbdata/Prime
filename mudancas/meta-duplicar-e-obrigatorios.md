---
tipo: novidade
---
## Changelog
- Pasta, aba Metas: "Duplicar" no menu ⋮ da meta (desenho 02, PMC-032), entre "Editar nome" e "Encaminhar via Chat I.A". A cópia nasce na hora na mesma pasta, aberta, com descrição, prazo (mesmo vencido), prioridade exata (Normal fica Normal, sem herdar a da pasta) e tipo de demanda da original e o título com " (cópia)" (até 120 caracteres); não notifica ninguém. Dos responsáveis, só vão os que a Nova meta aceitaria (vínculo ativo); se nenhum sobrar, a duplicação é recusada com "Nenhum responsável desta meta pode receber metas agora. Crie pela Nova meta." Sem XHR, recusa e sucesso respondem com aviso e volta para a aba Metas. O aviso "Meta duplicada" traz "Desfazer", que exclui a cópia. Rota nova `tarefa_duplicar` (POST, CSRF por id, mesmas guardas da criação de meta; recusada na pasta excluída). `tarefa_excluir` passa a responder JSON por XHR.
- Pasta, modal "Nova meta" (D-META): prazo e ao menos um responsável passam a ser obrigatórios, com o "*" nos rótulos e o "Criar meta" desabilitado com "Preencha: título, descrição, prazo, responsável" (só os que faltam). Aberto pelo botão "Nova meta", o prazo já vem em hoje + 7 dias. O servidor responde 422 sem prazo válido ou sem responsável do escritório. Sem migration.

## Para o cliente
- Agora dá para duplicar uma meta pelo menu da própria meta, e desfazer logo em seguida se foi sem querer.
- Toda meta nova precisa de prazo e de pelo menos um responsável; o prazo já vem sugerido para daqui a 7 dias.
