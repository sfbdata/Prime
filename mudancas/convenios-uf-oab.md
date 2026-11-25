---
tipo: correcao
---
## Changelog
- Convênios: a UF do escritório agora é o estado do escritório e, quando ele está vazio, a UF da inscrição OAB válida de quem criou o escritório (`src/Master/Service/UfDoEscritorio.php`, spec `docs/specs/convenios.md` §5.1). A sincronização das 2h10 de 25/11 criou só 1 vínculo em 34 escritórios porque 32 não tinham estado.
- A mesma regra vale no código de convênio de um estado, no rótulo e na contagem das contas por estado do painel do Master e no e-mail aos escritórios quando o Master muda o convênio de uma UF.
- Sem migration: a próxima volta da tarefa diária cria os vínculos que faltam.

## Para o cliente
- Escritórios que ainda não preencheram o estado nos dados do escritório passam a receber o convênio OAB pelo estado da OAB de quem criou a conta. Antes, ficavam sem o convênio até preencher o estado.
