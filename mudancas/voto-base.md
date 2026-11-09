---
tipo: novidade
---
## Changelog
- BlueJus Voto, fase A1 (docs/specs/bluejus-voto.md): tela `/voto` (rota `voto_index`, domínio `App\Voto`) dentro da moldura do sistema, com título, faixa de ambiente demonstrativo e as quatro abas do desenho (Votar, Resultados, Gestão eleitoral, Acompanhar); Votar e Acompanhar mostram o estado vazio, Resultados e Gestão eleitoral chegam nos PRs seguintes.
- Item "Voto" no menu lateral, chave `voto` no catálogo de Telas e recursos do Master e permissão `modules.voto.view` (rótulo "Voto (eleições e votações)" no painel de Perfis).
- Migration Version20261109120000: o recurso `voto` nasce desligado (só o Master vê) e a permissão é criada sem ser concedida a nenhum perfil.

## Para o cliente
- Começamos a preparar o BlueJus Voto, para eleições e votações do escritório. Por enquanto ele fica desligado e só aparece para a equipe do BlueJus; avisaremos quando estiver liberado.
