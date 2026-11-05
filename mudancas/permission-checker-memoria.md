---
tipo: alteracao
---
## Changelog
- `PermissionChecker` guarda para o request o vínculo da pessoa com o escritório (chave usuário:escritório, só o vínculo encontrado). `isActive()`, o papel e as permissões continuam lidos ao vivo; o vínculo que o EntityManager não gerencia mais é consultado de novo (`UserTenantRepository::continuaGerenciado`). `ResetInterface`: zera entre requests e entre mensagens do worker. Spec `docs/specs/permission-checker-memoria.md`.
- `TenantRolePermission::$permission` com `fetch: 'EAGER'`: as permissões do papel vêm numa consulta, não uma por item. Sem migration.
- Medido em teste: de 30 a 57 consultas a menos por página (colaborador no Meu Perfil: 112 para 55; contador do sino: 39 para 19). O Master não muda.
- Testes: `PermissionCheckerMemoriaTest` (unitário e funcional: equivalência com e sem memória para todas as permissões, invalidação no mesmo request) e `QueriesDoLayoutTest` (teto de consultas do Meu Perfil). `PermissionExtensionMemoriaTest` ajustado à nova contagem.

## Para o cliente
- As páginas do sistema ficam mais leves: cada abertura de página consulta bem menos o banco de dados para montar o menu e conferir as permissões, e o contador de avisos do sino também. Quem pode ver o quê continua exatamente igual.
