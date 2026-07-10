# Spec — Cobranças Etapa 8 / Onda 8B: Formulários e mutações operacionais

> Risco: **MÉDIO/ALTO** (camada HTTP de escrita de módulo financeiro-jurídico multi-tenant: permissão, capacidade, tenant, IDOR, CSRF, dinheiro). Alvo da revisão read-only.
> Base: SPEC §22 (permissões/auditoria) e §23 (invariáveis); `cobranca-etapa8-telas-ux.md` (§Onda 8B); EXECUTION_STATUS "Próxima ação exata"; contratos dos UseCases já prontos (Etapas 1–7). Esta onda é **só a camada HTTP de escrita** — controllers finos que reusam UseCases; nenhuma regra de negócio nova.

## Objetivo

Ligar a interface (botões/modais nas telas da Onda 8A) aos UseCases de escrita já existentes, com autorização em camadas, CSRF, proteção de tenant/IDOR e apresentação clara dos erros de domínio. Sem importação visual (8C), sem file-manager de documentos (8C), sem Dashboard/central de alertas (Etapa 9).

## Modelo de autorização (dura — SPEC §22)

Toda rota de Cobranças (leitura e escrita) exige `canAccessModule(user, tenant, 'cobrancas')`. **Adicionalmente**, cada MUTAÇÃO exige a capacidade de papel correspondente via `hasPermission(user, tenant, <code>)` — capacidade de papel, **não** per-item ACL (`resource_access` não está wired para cobrança). Códigos já no `PermissionFixture` (linhas 42–44):

| Capacidade | Código | Cobre |
|---|---|---|
| Gerenciar carteiras/config | `resources.carteira.gerenciar` | Criar carteira · Editar configuração da carteira |
| Gerenciar cobranças (operar) | `resources.cobranca.gerenciar` | Criar objeto · Criar pessoa · Vincular/Encerrar vínculo · Abrir caso · Alterar pessoa cobrada · Encerrar caso · Judicializar · Registrar obrigação · Reconhecer valor · Criar/Romper/Cancelar/Cumprir acordo · Definir/Concluir próxima ação · Registrar tentativa · Gerar/Resolver revisão |
| Movimentação financeira | `resources.cobranca.movimentacao_financeira` | Registrar pagamento · Corrigir pagamento · Registrar liquidação |

- **Judicializar** exige adicionalmente `canAccessModule(user, tenant, 'pastas')` (para listar/escolher a Pasta). Sem o módulo `pastas`, a ação é ocultada e a rota nega.
- `hasPermission` faz match exato do código; `TenantRole::isSystem()` e `ROLE_SUPER_ADMIN` dão bypass (por design do checker). Sem advogado responsável obrigatório (SPEC §22 proíbe no MVP).

## Anti-IDOR / Tenant / CSRF (regra dura, ordem obrigatória)

Ordem em toda mutação (espelha o padrão testado do Djen):
1. **Gate**: `assertModulo()` → módulo; `assertCapacidade(<code>)` → capacidade. Falha → flash + redirect (não 200).
2. **Resolver entidade** por `findOneByIdDoTenant($id, $tenant)` → `null` = `createNotFoundException` (404). **Antes** do CSRF (leitura sem efeito; cross-tenant vira 404 determinístico).
3. **CSRF**: Form (valida `_token` automático) ou, em ação simples sem campos, `isCsrfTokenValid('<nome_por_id>', _token)` → inválido = flash danger + redirect (sem mutar).
4. **Mutação**: `try { $useCase->executar($input, $tenant, $user) } catch (<ExceçãoDeDomínio>) { addFlash }`. Sucesso → PRG (`redirectToRoute` + flash success).

Controller fino: `Request → Form/DTO → UseCase → (flush interno) → redirect`. Nenhuma regra de negócio no controller/Twig. Todos os UseCases de escrita **fazem flush internamente** — o controller não chama flush.

## Fundação compartilhada (8B-0) — commit antes das fatias

1. **Trait `AutorizacaoCobranca`** (`src/Cobranca/Controller/AutorizacaoCobranca.php`): centraliza o glue de autorização/contexto reusado por todos os controllers de cobrança. Métodos protegidos:
   - `contexto(): array{0: User, 1: ?Tenant}` — usuário logado + tenant atual.
   - `assertModulo(): Tenant` — gate de módulo; lança `AccessDeniedException`? Não: para leitura o padrão 8A é flash+redirect. Para uniformidade, o trait expõe `moduloOuRedirect(): ?Tenant` (retorna Tenant ou null) e `semAcesso(): Response`. Mutações usam `assertCapacidade` que, em falta, retornam via `semAcesso()`.
   - Idempotente com o comportamento atual das rotas 8A (refatoração comportamento-preservada — `CobrancaTelasControllerTest` cobre).
2. **`CentavosType`** (`src/Cobranca/Form/CentavosType.php`) + `CentavosParaReaisTransformer` (`src/Cobranca/Form/DataTransformer/`): dinheiro é `int` de CENTAVOS no DTO; a UI digita reais em pt-BR ("1.234,56"). Transformer `?int` (centavos) ↔ `?string` (reais), aritmética inteira, sem float. Unit-testado (ida/volta, vazio→null, separador de milhar, valor inválido → `TransformationFailedException`). Reutilizado por todo form com dinheiro.
3. **Filtro `|centavos`** (já existe, 8A) para exibição. Convenção de modal Bootstrap com `<form method="POST">` + `_token` estabelecida aqui.

## Formas de dinheiro e datas nos Forms

- **Dinheiro** → `CentavosType` (mapeia ao campo `int` centavos do DTO).
- **Datas** (`?\DateTimeImmutable`) → `DateType` com `widget: single_text`, `input: datetime_immutable`, `required` conforme constraint do DTO.
- **Enums** (`ModoCarteira`, `FormaHonorarios`, `TipoVinculo`, `TipoLiquidacao`) → `EnumType` (`class` + `choice_label: fn($e) => $e->label()`). Nullable com `NotNull` (ex.: `RegistrarLiquidacaoInput.tipo`) → `EnumType` `required: true` `placeholder`.
- **Coleções aninhadas** → `CollectionType` com `entry_type` do sub-Form (`AlocacaoPagamentoInput` → `AlocacaoPagamentoType`; `ParcelaAcordoInput` → `ParcelaAcordoType`), `allow_add`/`allow_delete`/`by_reference:false`, `prototype` p/ JS. `CriarAcordoInput.obrigacoesSubstituidasIds` = `int[]` → `ChoiceType` multiselect das obrigações exigíveis do caso.
- Todo Form: `data_class => <Input>::class`; CSRF default do Symfony; classe `final`; validação via `#[Assert]` do DTO (nada de constraint no Form).

## Estado de execução (2026-07-10)

Ordem REAL executada (reordenada por complexidade/independência — mecânicas primeiro, coleção/seleção depois): **8B-0 fundação → 8B-A obrigações/encerrar → 8B-B ação/tentativa/revisão → 8B-C acordo** ✅ (commits `29e7a8e`→`30e4cf4`, revisados sem bloqueante, `tests/Cobranca` 288/288). **Faltam: 8B-D financeiro (pagamento/corrigir/liquidação) e 8B-E cadastro+seleção (carteira/objeto/pessoa/vínculo/abrir/alterar-pessoa/judicializar).** Detalhe operacional e "padrão estabelecido" no `docs/gestao-cobrancas/SESSION_HANDOFF.md`.

## Decomposição em fatias (single-writer sequencial — decisão de paralelização)

**Fan-out foi avaliado e recusado para o cluster do Caso.** O `PARALLELIZATION_MAP` prevê 3–4 agentes na 8B, MAS o pré-requisito é independência real de arquivos. As mutações caso-aninhadas (obrigação, pagamento, liquidação, acordo, ação, tentativa, revisão, alterar pessoa, encerrar, judicializar) **todas editam o template compartilhado `caso/show.html.twig`** (botões + modais) — não são file-independentes. Conforme a instrução da onda ("Use paralelização apenas onde houver grupos de ações realmente isolados. Caso contrário, use um único escritor") e o workflow ("na dúvida sobre independência → sequencial"), o cluster do Caso é **single-writer (orquestrador)**. O cluster de Cadastro é file-independente do Caso, porém pequeno; sequencial evita overhead de fan-out sem perda real. **Portanto: orquestrador único, fatias sequenciais, cada uma committada e testada.** (Se uma fatia futura crescer e ficar isolada por partials, reavaliar.)

Cada fatia entrega: Forms + métodos de controller + modais no template + testes funcionais (permissão/capacidade/tenant/IDOR/CSRF/happy+erro de domínio) → suíte `tests/Cobranca` verde → commit local.

- **8B-0 Fundação** — trait `AutorizacaoCobranca`, `CentavosType`+transformer (unit), refactor 8A p/ o trait. *(sem template novo)*
- **8B-1 Cadastro** — Carteira (criar em `/cobrancas`; editar config em `carteira/show`), Objeto (criar em `carteira/show`), Pessoa (criar), Vínculo (vincular/encerrar), Abrir Caso. Templates: `carteira/index`, `carteira/show` (+ seção Objetos). NÃO toca `caso/show`.
- **8B-2 Caso · obrigações + lifecycle** — Registrar obrigação, Reconhecer valor, Alterar pessoa cobrada, Encerrar caso, Judicializar. Template `caso/show` (botões no cabeçalho + aba Obrigações + modais).
- **8B-3 Caso · financeiro** — Registrar pagamento (alocação manual explícita; FIFO = follow-up #8, não bloqueia), Corrigir pagamento, Registrar liquidação. Template `caso/show` (aba Pagamentos & Liquidações + modais).
- **8B-4 Caso · acordo + ação + tentativa + revisão** — Criar/Romper/Cancelar/Cumprir acordo, Definir/Concluir próxima ação, Registrar tentativa, Gerar/Resolver revisão. Template `caso/show` (abas Acordos/Histórico + cabeçalho de ação + modais).

Ordem de execução nesta sessão: 8B-0 → 8B-1 → 8B-2 → 8B-3 → 8B-4, até o limite de contexto; handoff controlado ao aproximar ~90%.

## Rotas (nomes `cobranca_*`, métodos POST, `requirements id => \d+`)

Mutações caso-aninhadas ficam em `CasoController` (prefixo `/cobrancas/casos`). Cadastro fica em `CarteiraController`/novo `PessoaController`. Exemplos:
`POST /cobrancas/carteiras/nova` · `POST /cobrancas/carteiras/{id}/configuracao` · `POST /cobrancas/carteiras/{id}/objetos` · `POST /cobrancas/pessoas` · `POST /cobrancas/objetos/{id}/vinculos` · `POST /cobrancas/vinculos/{id}/encerrar` · `POST /cobrancas/carteiras/{id}/casos` (abrir) · `POST /cobrancas/casos/{id}/pessoa-cobrada` · `POST /cobrancas/casos/{id}/encerrar` · `POST /cobrancas/casos/{id}/judicializar` · `POST /cobrancas/casos/{id}/obrigacoes` · `POST /cobrancas/obrigacoes/{id}/reconhecer-valor` · `POST /cobrancas/casos/{id}/pagamentos` · `POST /cobrancas/pagamentos/{id}/corrigir` · `POST /cobrancas/casos/{id}/liquidacoes` · `POST /cobrancas/casos/{id}/acordos` · `POST /cobrancas/acordos/{id}/romper|cancelar|cumprir` · `POST /cobrancas/casos/{id}/proxima-acao` · `POST /cobrancas/acoes/{id}/concluir` · `POST /cobrancas/casos/{id}/tentativas` · `POST /cobrancas/casos/{id}/revisoes` · `POST /cobrancas/revisoes/{id}/resolver`.

## Testes (por fatia, em `tests/Cobranca/Functional/`)

Molde: `CobrancaTelasControllerTest` (helpers `criarAdminLogado` [role `isSystem` → passa capacidade], `semearGrafo`, factories) + molde do Djen (obter `_token` via crawler `selectButton(...)->form()`; POST direto com `_token` inválido não muta). Por mutação, cobrir:
- **Happy path**: submissão válida persiste + `assertResponseRedirects` + estado no DB.
- **Erro de domínio**: entrada que dispara a Exception do UseCase → flash, sem persistir.
- **Capacidade negada**: usuário com módulo `cobrancas` mas SEM a capacidade (role não-`isSystem`, sem o code) → redirect/negado, sem mutar. *(exige helper novo `criarOperadorSemCapacidade` — role não-system + grant só de `modules.cobrancas.view`.)*
- **IDOR cross-tenant**: id de outro tenant → 404, sem mutar.
- **CSRF inválido**: `_token` forjado → não muta.

## Invariáveis a preservar (SPEC §23 — não reinterpretar)

Nomenclatura Carteira/Objeto/Caso de Cobrança (27); dinheiro derivado, nunca manual (20); pagamentos/acordos não atravessam cobranças (12/13); obrigações substituídas nunca apagadas (14/15); judicialização não encerra (16); encerramento manual só com saldo exigível 0 (17); honorários separados/derivados (18/19); exatamente uma pessoa cobrada por caso (7/8/9). **Decisão da Etapa 7 intacta** (linha só-encargos rejeitada, sem obrigação principal-zero). A camada HTTP só chama os UseCases — as invariáveis já vivem neles; os testes funcionais confirmam que a HTTP não as fura (ex.: encerrar com saldo ≠ 0 → erro apresentado, não 500).

## Fora do escopo (8C / Etapa 9)

Importação visual (upload→prever→confirmar); file-manager de documentos do Caso (religar `pasta-arquivos.js`); Dashboard; central visual de alertas; per-item ACL de cobrança; advogado responsável; sugestão FIFO de alocação de pagamento (follow-up #8 — alocação manual explícita nesta onda).
