---
tipo: correcao
---
## Changelog
- Acesso restrito: a rota `expediente_acervo_geral` sai das `ROTAS_FECHADAS` da `BarreiraDoAcessoRestritoListener`. Todos os painéis da Lista do Expediente carregam por ela, e a consulta já vem escopada às pastas do restrito (`findByFilters` e `countByFilters`). Teste funcional `testPainelDaListaSoComAsDele`.

## Para o cliente
- Quem tem o acesso restrito às pastas sob responsabilidade volta a ver a Lista do Expediente: antes ela mostrava "Erro ao carregar o conteúdo". A lista e o total mostram só as pastas da pessoa e as compartilhadas com ela.
