---
tipo: correcao
---
## Changelog
- Ponto: `JornadaTenantController` (`GET` e `POST /tenant/{id}/jornada`) fixa o `TenantFilter` no escritório da URL logo depois do guard de acesso. A rota usa `{id}`, que o `TenantUrlScopeListener` não alcança: o super admin com a sessão em outro escritório via a jornada padrão do escritório da URL como inexistente e, ao salvar, a tela tentava criar uma segunda (índice único, resposta 409).

## Para o cliente
- O suporte do BlueJus volta a conseguir abrir e corrigir a jornada padrão de um escritório quando está trabalhando a partir de outro escritório.
