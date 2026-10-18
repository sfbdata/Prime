# Snapshot do banco de teste do CI

`schema-de-teste.sql` é o ponto de partida do banco do job "Suíte completa"
(`.github/workflows/suite-completa.yml`). Migrations do zero não montam o banco (a
`Version20260625200952` supõe dados de um dump) e `schema:create` não cria a extensão `unaccent` nem os índices
funcionais de SQL cru das migrations.

Conteúdo: a estrutura do banco local de teste (`pg_dump --schema-only`) e os dados de só duas
tabelas, `doctrine_migration_versions` e `permission` (catálogo semeado por migration). Nenhuma
outra tabela tem dado: o repositório é público.

- **Montar** (CI ou local): `scripts/ci-montar-banco-de-teste.sh <banco_de_teste> <processos>`.
  Carrega o snapshot, roda `doctrine:migrations:migrate` (aplica só as migrations mais novas que
  ele) e clona um banco por processo do ParaTest.
- **Regerar**: `scripts/gerar-schema-de-teste.sh [banco]` (padrão `saas_test`), a partir de um
  banco de teste em que a suíte passa. Não precisa a cada migration; regere quando a cadeia ficar
  muito à frente do snapshot ou quando uma migration nova não rodar em banco sem dado. O script
  recusa o arquivo se houver dado de outra tabela ou algo com cara de e-mail. Revise o diff antes
  de commitar.
