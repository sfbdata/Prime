---
tipo: alteracao
---
## Changelog
- Master > Armazenamento > "Contas por consumo" (`public/js/master/painel.js`, `renderRanking`): a linha realça ao passar o mouse com o mesmo `.mp-lin` das Contas e da Segurança (#2b7ab0). Nome, % e faixa usam `.mp-hv`; a barra e o botão "Editar limite" ficam fora dele, e o botão vira branco na linha realçada para não sumir.
- Busca no cabeçalho do cartão com a mesma regra da lista de Contas (`casaNaBusca`: nome, primário, nº e código da conta), em estado próprio (`S.rBusca`).
- Ordem: maior consumo (a de antes, padrão), nome A-Z e situação (faixa mais grave primeiro, sem limite no fim). Empate pelo nº da conta.
- Sem tabela, coluna nem migration.

## Para o cliente
- Na Central do Master, em Armazenamento, a lista "Contas por consumo" agora destaca a linha ao passar o mouse, o que facilita ler a conta certa.
- A lista ganhou um campo de busca por nome, primário, número ou código da conta.
- Também dá para escolher a ordem: maior consumo, nome de A a Z ou situação (as contas mais perto do limite primeiro).
