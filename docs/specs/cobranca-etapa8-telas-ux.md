# Spec — Cobranças Etapa 8: Telas operacionais / UX

> Risco: **MÉDIO/ALTO** (camada HTTP de módulo financeiro-jurídico multi-tenant: permissão, tenant, IDOR, CSRF). Alvo da revisão read-only.
> Base: SPEC §9/§17/§22/§26, PLAN §9 (Etapa 8), EXECUTION_STATUS "Próxima ação". Back-end (Etapas 1–7) pronto e testado; esta etapa é **só a camada HTTP** — controllers finos reusando os UseCases existentes; nenhuma regra de negócio nova no controller/Twig.

## Objetivo

Construir a operação visual da Gestão de Cobranças: menu gated → lista de Carteiras → visão da Carteira → lista de Casos (filtro reutilizável) → **detalhe do Caso** (tela central) → formulários de ação → fluxo visual de importação.

## Decomposição em ondas (etapa grande — entregas verificáveis)

- **Onda 8A (esta sessão): Fundação + espinha de navegação (leitura).**
  Enum `badgeClass()`, métodos de listagem tenant-scoped nos repos, Output DTOs de leitura, UseCases de listagem, item de menu + gate + `pageTitle`, `CarteiraController` (index/show), `CasoController` (index com filtro XHR + show detalhe read-only com abas). Testes funcionais de leitura (permissão/tenant/IDOR/render/XHR). Suíte verde + tenant-safety.
- **Onda 8B (handoff): Formulários/mutações.** Forms + POST reusando os UseCases de escrita (abrir caso, registrar obrigação, reconhecer valor, pagamento/correção, liquidação, acordo/romper/cancelar/cumprir, próxima ação/concluir, tentativa de cobrança, alterar pessoa cobrada, judicializar, gerar/resolver revisão, encerrar; CRUD de carteira/objeto/pessoa/vínculo). CSRF em cada mutação; capacidade `gerenciar`/`movimentacao_financeira`.
- **Onda 8C (handoff): Importação visual + Documentos (file manager).** Upload `.xlsx` → `TopLifeInadimplenciaAdapter::ler` → `ImportarRelatorioCarteiraUseCase::prever` (preview) → `confirmar` (relatório importado/ignorado/rejeitado). Religar `pasta-arquivos.js` por `data-*` para o Caso (documentos).

## Autorização (SPEC §22 — decisão)

O catálogo (`PermissionFixture`) já registrou, com o comentário "gates ligados nas Etapas 3/8":
- `modules.cobrancas.view` — **descoberta/leitura** do módulo.
- `resources.cobranca.gerenciar` — operar casos (obrigações, contatos, ações, acordos).
- `resources.carteira.gerenciar` — carteiras e configurações.
- `resources.cobranca.movimentacao_financeira` — pagamentos/liquidações/correções.

**Regra desta etapa (não é per-item ACL; é capacidade de papel, via `hasPermission`):**
- **Toda** rota de Cobranças exige `canAccessModule(user, tenant, 'cobrancas')` (helper `assertAcesso(): Tenant`). Leitura para com isso.
- Mutações (Onda 8B/8C) exigem **adicionalmente** a capacidade correspondente via `hasPermission(user, tenant, 'resources.cobranca.gerenciar'|'resources.carteira.gerenciar'|'resources.cobranca.movimentacao_financeira')`. Não usa `canAccessResource` (tabela `resource_access` só está wired para cliente/pasta/processo) — usa `hasPermission` direto no code (capacidade de papel).
- Justificativa: honra SPEC §22 (capacidades separadas) e a intenção pré-registrada, sem per-item ACL (fora do escopo). Não há "advogado responsável obrigatório" (SPEC §22 proíbe no MVP).

## Anti-IDOR / Tenant (regra dura)

Toda entidade de rota resolvida por `findOneByIdDoTenant($id, $tenant)` (já pronto nos repos) → `null` = `createNotFoundException`. Nunca `find()` puro. O `TenantFilter` NÃO cobre lookup por PK. Teste cross-tenant obrigatório (404 ao acessar id de outro tenant).

## Rotas (Onda 8A)

Prefixo de classe `#[Route('/cobrancas')]`, nomes `cobranca_*`. `final class ... extends AbstractController`.

| Rota | Nome | Método | Tela |
|---|---|---|---|
| `/cobrancas` | `cobranca_carteira_index` | GET | Lista de Carteiras (filtro reutilizável XHR) |
| `/cobrancas/carteiras/{id}` | `cobranca_carteira_show` | GET | Visão da Carteira (saldo consolidado, objetos, casos, "o que exige atenção") |
| `/cobrancas/casos` | `cobranca_caso_index` | GET | Lista de Casos (filtro reutilizável XHR) |
| `/cobrancas/casos/{id}` | `cobranca_caso_show` | GET | **Detalhe do Caso** (tela central) |

`cobranca_carteira_index` é a landing do módulo (menu aponta pra ela). Item de menu ativo por `currentRoute starts with 'cobranca_'`.

## Filtro reutilizável (contrato já existente)

`index` serve página cheia + fragmento `_resultado.html.twig` bifurcando por `isXmlHttpRequest()`. Lê query `busca`, facetas, `ordenar`, `direcao`, `page`; devolve `filtros` (incl. `ordenar`/`direcao`) ao template. Casca com `[data-filtro-root][data-filtro-endpoint]` + `[data-filtro-resultado]`; JS/CSS globais (`filtro-tabela.js/.css`), sem tocá-los.
- **Casos**: facetas `status` (Ativo/Judicializado/Encerrado), `carteira` (select), `vencido` (sim/não), `judicializado` (sim/não); busca por objeto/pessoa cobrada. Ordenar por saldo/atualização.
- **Carteiras**: busca por nome/cliente; faceta `modo` (Único/Múltiplo). Ordenar por nome/atualização.

## Detalhe do Caso (tela central — SPEC §9/§26)

Cabeçalho: **saldo exigível/vencido** em destaque (derivado por `CalculadoraSaldo`), **estado** (badge; "pronto para encerrar" = indicador derivado quando `status != encerrado && saldoExigivel == 0`, NÃO 4º estado do enum), **pessoa cobrada atual**, **próxima ação** proeminente (máx. 1), bloco **alertas** (`AlertasCobranca::alertasDoCaso`, read-only).
Corpo: **timeline do histórico** (`EventoHistoricoRepository::doCaso`) + abas Obrigações / Pagamentos & Liquidações / Acordos / Documentos. Na 8A as abas são read-only; os botões de ação apontam para rotas 8B (ou ficam desabilitados/ocultos até 8B). Documentos: placeholder ligado ao file-manager na 8C.

## Fundação (Onda 8A)

1. **`badgeClass()` nos enums** (mapeamento de apresentação puro, sem acoplar entidade): `StatusCaso`, `StatusAcordo`, `StatusProximaAcao`, `StatusRevisao`, `TipoAlerta`. Padrão `text-bg-*` (secondary/warning/danger/success/info). Exposto via Output DTO — nunca passar entidade Doctrine crua ao Twig (regra `templates/CLAUDE.md`).
2. **Métodos de listagem tenant-scoped** (`findByFilters`/`countByFilters`) em `CarteiraRepository` e `CasoCobrancaRepository` — QueryBuilder com `WHERE tenant = :tenant` explícito + filtros + paginação + ordenação whitelisted. Anti-injeção: `ordenar`/`direcao` validados contra whitelist.
3. **Output DTOs de leitura**: `CarteiraResumoOutput`, `CarteiraDetalheOutput`, `CasoResumoOutput`, `CasoDetalheOutput`, e sub-DTOs conforme necessário (`ObrigacaoOutput`, `PagamentoOutput`, `LiquidacaoOutput`, `AcordoOutput`, `DocumentoOutput`, `EventoHistoricoOutput`, `ProximaAcaoOutput`, `RevisaoOutput`, alertas via `AlertaCobranca` já existente). Dinheiro formatado a partir de centavos int (helper de formatação BRL).
4. **UseCases de listagem** (finos): `ListarCarteirasUseCase`, `ListarCasosUseCase` — recebem `Tenant`, filtros, paginação; devolvem `{itens, total}`.

## UX (SPEC §26 — obrigatório desde o desenho)

Padrão visual real: AdminLTE + Bootstrap 5.3, `base.html.twig`. `.cobrancas-page { --jp-accent }` + variante `html[data-bs-theme="dark"]`. Badges `text-bg-*`; realce de linha para vencido/atrasado/em revisão (padrão `.pasta-row-urgente`). Tooltips explícitos com init local `querySelectorAll('[data-bs-toggle="tooltip"]')` (não há init global; truncamento de tabela já é global). Tema claro/escuro só com vars `--bs-*`. Reduzir cliques: detalhe do caso concentra a operação; a próxima ação provável em destaque.

## Testes (Onda 8A)

Functional por rota em `tests/Cobranca/Functional/`:
- **Permissão**: usuário sem `modules.cobrancas.view` → redirect/403 nas 4 rotas.
- **Tenant/IDOR**: acessar carteira/caso de outro tenant → 404.
- **Render**: 200 + conteúdo esperado (badge de status, saldo) com permissão.
- **XHR parcial**: `X-Requested-With` devolve só o fragmento `_resultado`.
- Sem vazamento entre tenants na listagem (só itens do tenant atual).

## Fora do escopo (não fazer nesta etapa)

Dashboard/central de alertas global (Etapa 9); domínio Financeiro (SPEC §19/§24); importador universal; per-item ACL (`resource_access`) para cobrança; advogado responsável por carteira/caso.
