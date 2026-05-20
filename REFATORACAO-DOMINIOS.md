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
| - | (próximo a definir após análise da fila restante) | | | | |

## Concluído

| Data | Frente |
|------|--------|
| 2026-05-19 | Remoção do módulo PreCadastro (código, templates, fixtures, permissão) |
| 2026-05-19 | DROP TABLE `pre_cadastro` (migration irreversível) |
| 2026-05-19 | Migrar `TarefaRepository` para `src/Tarefa/Repository/` |

## Trilha separada (não tocar na fila de migrações pequenas)

Reescritas grandes — projeto próprio cada uma:

- **PastaController** (1.849 linhas) — extrair UseCases, dividir em sub-controllers, criar testes antes de mexer.
- **TenantController** (1.670 linhas) — mesmo tratamento; cuidado redobrado (componente MÉDIO na hierarquia de risco).
- **PontoController** (1.224 linhas) — componente ALTO. Smoke manual obrigatório, dump de banco antes de qualquer schema change.

## Pendências não-migração

- **13 testes quebrados** por `App\Expediente\UseCase\RemoverMarcadorDaPastaUseCase` inexistente. Detectado em 2026-05-19. Não bloqueia migrações da fila. Investigar quando atacar o domínio Expediente.
- **Entidade `Tarefa`** permanece em `src/Entity/Tarefa/` (legado). Migração futura — quando feita, atualizar `use` em 11 arquivos.
- **`TarefaMensagemRepository`** permanece em `src/Repository/` (legado). Migração futura para `src/Tarefa/Repository/`.
- **Skill `criar-repository`** define métodos `salvar()`/`remover()` (português) divergindo do padrão real `save()`/`remove()` (inglês) usado em Cliente, Processo e Tarefa. Corrigir em frente separada.
- **Bug B — redirect pós-login cego**: `UserAuthenticator::onAuthenticationSuccess()` redireciona fixo para `/expediente` quando o usuário tem 1 tenant ativo, sem checar permissão. Qualquer perfil sem `modules.expediente.view` toma 403 ao logar. Detectado em 2026-05-20. Correção desejada: redirecionar para a primeira rota de módulo que o usuário tem permissão (varrer ordem dos módulos, reusar ordem da sidebar); criar landing neutra de fallback ("fale com seu admin") para perfil sem nenhum módulo liberado. Candidato a UseCase/serviço (`ResolverRotaInicial`) injetado no authenticator. Frente própria — risco MÉDIO (toca fluxo de auth).
- **Migrations de smoke test obsoletas**: `Version20260512120000/130000/140000` (e correlatas de smoke) hardcodam `tenant_id=30` e tropeçam com FK violation em todo banco dev restaurado de produção. São puladas em prod (`skipIf APP_ENV=prod`), problema é exclusivo do dev. Limpar do repo. Detectado em 2026-05-20.
- **Registro órfão `modules.precadastros.view`**: existe na tabela `permission` de produção mas o módulo PreCadastro já foi removido do código. Lixo de catálogo. Remover via migration de dados quando atacar a limpeza do domínio de permissões. Detectado em 2026-05-20.
- **Doc de troubleshooting de certificado ausente**: `DEPLOY.md`/`SETUP.md` cobrem emissão e renovação de certs, mas não há seção sobre o cenário "deploy abortou porque o cert de um domínio sumiu/venceu" nem menção a `bluejus.com.br`. A checagem do `deploy-prod-tls.sh` testa só existência do arquivo, não validade/expiração. Documentar o procedimento de recuperação e considerar checagem de expiração. Detectado em 2026-05-20.

## Hierarquia de risco (resumo — ver project instructions para detalhe)

- **ALTO**: Ponto eletrônico (tabelas `registro_ponto`, `justificativa_ponto`, `jornada_colaborador`); `PontoController`; `Entity/Ponto/*`. + `User`, `UserTenant`, `Tenant`. **Nunca dropar.**
- **MÉDIO**: `TenantRole`, `Permission`, `Profile`. Drop só com aviso.
- **BAIXO**: resto. Liberdade para refatorar.

## Convenções de uso deste documento

1. Sessão nova no Claude Code: começa colando este arquivo + tarefa do dia.
2. Após cada commit que conclui frente: mover item de "Fila" para "Concluído".
3. Decisões arquiteturais novas: registrar em "Decisões firmadas" (com referência ao domínio onde foi estabelecido).
4. Este arquivo NÃO duplica `CLAUDE.md` nem `app/src/CLAUDE.md`. Se algo virar regra geral, migra pra um daqueles.
