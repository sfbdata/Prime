# Refatoração de Domínios — Mapa Vivo

Documento de contexto para sessões do Claude Code. Atualizado a cada commit
que conclui uma frente. Para regras gerais do projeto, ver `CLAUDE.md` raiz
e `app/src/CLAUDE.md`.

## Decisões arquiteturais firmadas

Padrões observados no domínio `src/Tenant/` (referência de implementação real):

- **UseCase**: método público `executar()` (não `__invoke()`).
- **DTO**: classe `final readonly` com property promotion no construtor; sem métodos.
- **Dependências no UseCase**: `EntityManagerInterface` injetado diretamente; Repositories também injetados quando necessário.
- **Flush**: único, no final do `executar()`.
- **Queries de junção sem entidade mapeada**: `$conn->executeStatement(...)` é aceitável.

Padrões herdados de `app/src/CLAUDE.md`:

- Fluxo: `Request → Controller → Form/DTO → UseCase → Entity → Repository → flush()`.
- Multi-tenancy: toda entidade tem `tenant`; toda query filtra por tenant; UseCase valida posse.
- Permissões: `PermissionChecker` ou `#[IsGranted('modules.<modulo>.view')]`. Nunca `in_array` de role.

## Fila de migração

Ordem definida por critério: domínio destino existe + arquivo pequeno + tem teste + risco BAIXO.

| # | Arquivo legado | Linhas | Destino | Tem teste? | Status |
|---|----------------|-------:|---------|:----------:|--------|
| 1 | `src/Repository/TarefaRepository.php` | 56 | `src/Tarefa/Repository/` | Sim | **Próximo** |
| - | (próximos a definir após primeira migração concluída) | | | | |

## Concluído

| Data | Commit | Frente |
|------|--------|--------|
| 2026-05-19 | `e790cf8` | Remoção do módulo PreCadastro (código, templates, fixtures, permissão) |
| 2026-05-19 | `ff86399` | DROP TABLE `pre_cadastro` (migration irreversível) |

## Trilha separada (não tocar na fila de migrações pequenas)

Reescritas grandes — projeto próprio cada uma:

- **PastaController** (1.849 linhas) — extrair UseCases, dividir em sub-controllers, criar testes antes de mexer.
- **TenantController** (1.670 linhas) — mesmo tratamento; cuidado redobrado (componente MÉDIO na hierarquia de risco).
- **PontoController** (1.224 linhas) — componente ALTO. Smoke manual obrigatório, dump de banco antes de qualquer schema change.

## Pendências não-migração

- **13 testes quebrados** por `App\Expediente\UseCase\RemoverMarcadorDaPastaUseCase` inexistente. Detectado em 2026-05-19. Não bloqueia migrações da fila. Investigar quando atacar o domínio Expediente.

## Hierarquia de risco (resumo — ver project instructions para detalhe)

- **ALTO**: Ponto eletrônico (tabelas `registro_ponto`, `justificativa_ponto`, `jornada_colaborador`); `PontoController`; `Entity/Ponto/*`. + `User`, `UserTenant`, `Tenant`. **Nunca dropar.**
- **MÉDIO**: `TenantRole`, `Permission`, `Profile`. Drop só com aviso.
- **BAIXO**: resto. Liberdade para refatorar.

## Convenções de uso deste documento

1. Sessão nova no Claude Code: começa colando este arquivo + tarefa do dia.
2. Após cada commit que conclui frente: mover item de "Fila" para "Concluído".
3. Decisões arquiteturais novas: registrar em "Decisões firmadas" (com referência ao domínio onde foi estabelecido).
4. Este arquivo NÃO duplica `CLAUDE.md` nem `app/src/CLAUDE.md`. Se algo virar regra geral, migra pra um daqueles.
