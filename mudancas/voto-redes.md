---
tipo: seguranca
---
## Changelog
- BlueJus Voto, condição antes da M3 (docs/specs/voto-redes-do-schema.md): as redes que conferem "todo dado do escritório" passam a olhar todos os schemas da aplicação (todos menos `information_schema` e os `pg_*`), pelo catálogo do PostgreSQL e não pelo `information_schema`. Nome sem prefixo no `public` e qualificado fora dele (`voto.liberacao`). Sem migration.
- Purga do escritório (`PurgarEscritorioUseCase`): a conferência antes de apagar o escritório usa a nova constante `SQL_TABELAS_COM_TENANT_ID` e cita cada parte do nome no `COUNT`. A `voto.liberacao` entra na Fase 3 da ordem de deleção (com `tenant_id = :tenant` explícito); sem ela a conferência travaria a purga de todo escritório liberado. O dry-run passa a contar a `voto.liberacao`.
- Testes da purga (`PurgaCoberturaSchemaTest`, `PurgarEscritorioUseCaseTest`) usam a mesma descoberta; o teste das pontes compara schema e nome nos dois lados. Provas novas: tabela do schema `voto` fora da purga aparece como não coberta, ponte no `voto` aparece como ponte, e tabela do `voto` com linha do escritório aborta a purga com o escritório preservado.
- MCP de leitura: `descrever_esquema` lista e descreve pelo catálogo, aceita `voto.liberacao` (sem ponto, schema `public`) e traz tipo por `format_type` e padrão por `pg_get_expr`; nenhuma permissão nova para a role do MCP. A checagem de escrita da `ConexaoLeitura` olha todos os schemas da aplicação: uma role com escrita só no `voto` passa a ser recusada.
- scripts/testar-paralelo.sh: a impressão digital do banco cobre colunas e índices de todos os schemas, com o schema no texto. Os bancos `_P*` são clonados de novo uma vez.

## Para o cliente
- Quando um escritório é excluído de vez, depois da quarentena, o sistema passa a conferir também a área do BlueJus Voto antes de apagar: nenhuma informação do escritório fica para trás. O BlueJus Voto continua em preparação e ainda não aparece para os escritórios.
