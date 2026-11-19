---
tipo: novidade
---
## Changelog
- Campanha BlueJus Black, aba do topo (`contribua-campanha.css/js`): centralizada (`left:50%` + `translateX(-50%)`) logo abaixo do cabeçalho, no lugar de `right:24px`, que caía sobre o nome e o avatar; some sozinha 5 s depois de abrir a tela (classe `cp-aba--saindo` e remoção).
- Campanha só para escritório criado há até 5 dias (`CampanhaVigente::contaNova`, constante `DIAS_DA_CONTA_NOVA`): `campanha()` null, `/contribua/campanha/janela` 404 e inscrição recusada para escritório mais antigo. O Master continua com a prévia; quem já se inscreveu continua vendo a própria participação. Spec `docs/specs/contribua-campanha.md` (D6b). Sem migration.

## Para o cliente
- A aba da campanha BlueJus Black aparece no centro do topo da tela, sem cobrir o seu nome, e some sozinha depois de alguns segundos.
- A campanha BlueJus Black é para escritórios novos: só quem criou o escritório há até 5 dias vê a campanha e pode se inscrever. Quem já se inscreveu continua acompanhando a própria participação normalmente.
