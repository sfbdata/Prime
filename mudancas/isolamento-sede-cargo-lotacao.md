---
tipo: seguranca
---
## Changelog
- Isolamento multi-tenant, fatia 4: `Sede`, `Cargo` e `Lotacao` passam a `TenantAware` (o TenantFilter escopa toda consulta pelo escritório). Saem da lista de pendentes do `TenantAwareCoberturaTest` (teto 13 para 10). Teste novo `CadastrosDoEscritorioIsolamentoTest` cobre a negativa por entidade e as telas de administração pelo escritório da URL.

## Para o cliente
- As sedes, os cargos e as lotações de cada escritório ficam protegidos automaticamente: o sistema só consegue ler os do escritório em que você está. As telas continuam iguais.
