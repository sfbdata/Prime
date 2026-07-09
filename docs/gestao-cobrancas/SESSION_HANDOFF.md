# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-08 — **Etapa 1 CONCLUÍDA**.

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada da feature; `master` só com DJEN).
- **HEAD:** `7019010` — "Integrar UseCases revisados da Onda 2 + teste cross-tenant" (commit do humano).
- **Etapa:** 1 (Núcleo de cadastro) → **✅ CONCLUÍDA**. Próxima = **Etapa 2** (Caso de Cobrança, Obrigações, Saldo).
- **Suíte:** **GLOBAL verde 1303/1303 (3507 assert)** no HEAD atual. `tests/Cobranca` verde.
- **Working tree:** limpo, exceto docs (este handoff + `EXECUTION_STATUS.md`) e untracked `.claude/worktrees/` (worktrees de agente — NÃO commitar).
- **Migrations (dev+test):** `Version20260708210509` (4 tabelas) + `Version20260708220000` (índices dedup Pessoa).

## ⚠️ IMPORTANTE — sessão colaborativa (humano co-editou a branch)
Durante a última sessão, o humano `sfbdata` **editou e committou na MESMA branch em tempo real**, em paralelo com o orquestrador:
- `ab996f9` — índices de dedup `(tenant_id, cpf)`/`(tenant_id, cnpj)` + `#[ORM\Index]` em `Pessoa`.
- `7019010` — revisão/consolidação de TODO o domínio Cobrança (21 arqs, −69 linhas) e **reconciliação da dedup** para **match EXATO** (`p.cpf = :cpf` via QueryBuilder), alinhando à decisão dos índices. Substituiu a dedup por dígitos normalizados que o orquestrador tinha integrado.

Como a identidade git da sessão é `sfbdata`, **commits do orquestrador e do humano têm o mesmo autor** — distinga por mensagem/escopo. O orquestrador **parou de escrever** nos arquivos de Cobrança ao detectar a co-edição (regra "escritor único por arquivo").

**No início da próxima sessão:** reconfirme com o humano que ele encerrou a co-edição, reconfira `git log`/`git status`, e só então reabra a escrita.

## O que foi concluído nesta sessão (Etapa 1 fechada)
- **Onda 2 completa (7 UseCases):** `CriarCarteira`, `CriarPessoa` (piloto anterior) + `EditarConfiguracaoCarteira`, `CriarObjeto` (Frente A `aca727c`), `SugerirPessoasDuplicadas`, `VincularPessoaAObjeto` (guard same-tenant), `EncerrarVinculo` (Frente B `f2f6a42`). Fan-out real: 2 `feature-implementer` em worktree → 2 `feature-review-agent` (APROVADO) → cherry-pick individual + teste direcionado.
- **Onda 3:** teste cross-tenant REAL do vínculo em DB (`CobrancaIsolamentoTenantTest`, `6fda56c`); `tenant-safety-review` SEM ACHADOS GRAVES; cobertura das 4 tabelas na purga de escritório (`92c48f3`); suíte global verde.
- **Reconciliação do humano** (`ab996f9`+`7019010`): dedup → match exato index-friendly; limpeza do domínio. Suíte reverificada 1303/1303.

## Decisões de design (Etapa 1)
- **Dedup de Pessoa = MATCH EXATO** sobre o documento armazenado, usando os índices `(tenant_id, cpf)`/`(tenant_id, cnpj)`. `SugerirPessoasDuplicadasUseCase` normaliza só trim/null; NÃO faz strip de dígitos. Normalização por dígitos (casar formatado × cru) = **follow-up da Etapa 7** (importação, SPEC §21).
- **Purga de escritório** cobre as 4 tabelas `cobranca_*` (ordem FK-safe vínculo→objeto→carteira→pessoa, antes de `cliente`). Ao criar QUALQUER tabela tenant-scoped nova (Etapa 2+), **adicioná-la à `PurgarEscritorioUseCase::ORDEM_DELECAO`** ou o `PurgaCoberturaSchemaTest` falha (guard anti-drift). O teste da purga semeia dados de Cobrança.
- Guard same-tenant nos UseCases que cruzam entidades: resolver TODAS as entidades por `findOneByIdDoTenant(id, $tenant)` — provado em DB pelo `CobrancaIsolamentoTenantTest`.

## Git
- **Branch:** `gestao-cobrancas`. **HEAD:** `7019010`.
- **Commits da Etapa 1 (sobre `beef54c`):** `ab996f9` (índices, humano) · `aca727c` (Frente A) · `f2f6a42` (Frente B) · `6fda56c` (cross-tenant+hardening) · `92c48f3` (purga) · `7019010` (revisão/dedup, humano).
- **Worktrees do fan-out:** removidas pelo runtime; branches-sobra `worktree-agent-*` → limpeza opcional do humano (`branch -D` é bloqueado p/ o Claude).
- **`master`:** NÃO contém Cobranças. Mergear DEPOIS do DJEN, só no fim.

## Testes
- `php bin/phpunit` (global) → **1303 testes, 3507 assert, OK** (HEAD `7019010`).
- `php bin/phpunit tests/Cobranca` → verde.
- Cross-tenant real: `php bin/phpunit --filter CobrancaIsolamentoTenantTest` → 5 testes OK.
- Purga: `php bin/phpunit --filter "PurgaCoberturaSchemaTest|PurgarEscritorioUseCaseTest"` → OK.

## Pendências conhecidas / follow-ups (não bloqueantes)
- Data-migration de permissões `cobrancas` p/ **produção** (dev/test já via fixture) — no deploy.
- Dedup por dígitos normalizados + índice funcional → Etapa 7 (importação).
- Validação cross-field `formaHonorarios × percentualHonorarios` e `dataFim ≥ dataInicio` → Etapa 8 (Forms) / decisão.
- `EditarConfiguracaoCarteira`/`EncerrarVinculo` confiam na validação do DTO (padrão do projeto; guard final no controller da Etapa 8).

## Próxima ação exata
> Iniciar a **Etapa 2 — Caso de Cobrança, Obrigações e Saldo derivado** (PLAN §8; paralelização BAIXA, núcleo acoplado). Passos:
> 1. **Reconfirmar** que o humano encerrou a co-edição da branch e `git status` limpo.
> 2. Storytelling dos UseCases (skill `criar-usecase`) → testes → implementação (fluxo CLAUDE.md).
> 3. Andaime committado ANTES de qualquer fan-out: entidades `CasoCobranca`/`Obrigacao`/`EventoHistorico`, enum `StatusCaso`, repos-stub, migration (`cobranca_caso`/`cobranca_obrigacao`/`cobranca_evento_historico`, aplicar dev+test via `migrations:execute --up`), factories. **Cobrir as 3 tabelas novas na purga** (`ORDEM_DELECAO`) + seed no teste da purga.
> 4. Serviços `CalculadoraSaldo` (saldo exigível/vencido/consolidado — coração financeiro; centavos inteiros) e `RegistrarEventoHistorico`.
> 5. UseCases `AbrirCaso`, `RegistrarObrigacao` (modo A/B), `ReconhecerValorAtualizado`, `RegistrarTentativaCobranca`, `AlterarPessoaCobrada`.
> 6. Testes: cenários de saldo (parcial/encargos/substituição), modo A×B, `EventoHistorico` correto, cross-tenant. Suíte global + `tenant-safety-review`. Atualizar estes docs.

## Ordem de retomada
1. Ler `NEW_CHAT_PROMPT.md` + este handoff.
2. Reconferir `git log`/`git status` — HEAD `7019010` (ou posterior do humano), working tree limpo.
3. Confirmar fim da co-edição humana antes de reabrir escrita.
4. Iniciar Etapa 2 pelo andaime (contratos), committar, e só então fan-out (se aplicável).
5. Atualizar `EXECUTION_STATUS.md` + este arquivo ao fim.
