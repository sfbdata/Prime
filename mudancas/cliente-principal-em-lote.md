---
tipo: alteracao
---
## Changelog
- Lista do expediente (acervo geral, painel de marcador, exportação, Minhas demandas e a busca de pastas do agente e do chat): o cliente principal de cada pasta deixa de ser consultado pasta a pasta. `PastaRepository::findByFilters`, `findPorMarcador` e `findByFiltrosEMarcador` buscam os ids da página (mesmos filtros, GROUP BY, ordem e paginação de antes) e depois as pastas com o cliente principal junto, em lotes de até 1000.
- Medido no `AcervoGeralEmLoteTest`: com 8 pastas cheias, de 41 para 34 consultas; com 1 pasta, 34 antes e depois. O número de consultas não cresce mais com o número de pastas.
- Testes: `AcervoGeralEmLoteTest` passa a exigir o mesmo número de consultas com 1 e com 8 pastas; `PastaRepositoryClientePrincipalEmLoteTest` compara pastas, ordem, páginas e total com a busca de antes, nos filtros e ordenações usados pelas telas, e confere o cliente principal PF e PJ.

## Para o cliente
- A lista de pastas do expediente abre mais rápido, principalmente com muitas pastas por página: o sistema busca o cliente de todas as pastas de uma vez, em vez de uma por uma. A lista mostra as mesmas pastas, na mesma ordem, e quem pode ver cada pasta continua igual.
