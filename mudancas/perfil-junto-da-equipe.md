---
tipo: alteracao
---
## Changelog
- O perfil do usuário (`User::$profile`, lado inverso de OneToOne, que o Doctrine não troca por proxy) passa a vir na mesma consulta das listas da equipe: `leftJoin('u.profile', 'pf')->addSelect('pf')` em `UserRepository::findColaboradoresAtivosPorTenant`, `UserTenantRepository::findAtivosComPerfilPorTenant` e `UserTenantRepository::findAtivosComCargoELotacaoPorTenant`. Antes, cada colega listado custava uma consulta a mais. Mapeamento, filtros (escritório, vínculo ativo), ordenação e linhas devolvidas não mudam; sem migration.
- Medido em teste (10 colegas): 12 consultas a menos no Dashboard, na Agenda, na Central, na Equipe da Central e no Acervo geral (primário, colaborador e Master), na pasta (12 para primário e Master, 11 para o colaborador) e em Minhas metas (primário e Master). Com 1 colega, 2 ou 3 a menos nas mesmas telas.
- Teste: `QueriesPorColegaTest` exige o mesmo número de consultas com 1 e com 6 colegas em dez combinações de tela e pessoa.

## Para o cliente
- As telas que mostram a equipe do escritório (painel, agenda, Central, acervo e pasta) abrem com menos idas ao banco de dados. A diferença cresce com o tamanho da equipe: antes cada colega somava uma consulta, agora a lista inteira vem de uma vez. O que cada pessoa vê continua igual.
