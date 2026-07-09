# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-09 — **Etapa 2 CONCLUÍDA e validada** (suíte global 1332/1332).

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada; `master` só com DJEN).
- **HEAD:** `4a094fe` ("Adicionar cross-tenant do Caso e cobrir EventoHistorico na auditoria") + este commit de docs.
- **Etapa:** 2 (Caso/Obrigações/Saldo) → **✅ CONCLUÍDA**. Próxima = **Etapa 3** (Pagamentos/Liquidações/Honorários).
- **Suíte:** GLOBAL **1332/1332 (3634 assert)**; `tests/Cobranca` 51/51 (inclui cross-tenant DB 6/6).
- **Working tree:** limpo (só untracked `.claude/worktrees/` — worktrees de agente, NÃO commitar).
- **Escritor:** ÚNICO. O processo autônomo concorrente da Etapa 1 foi encerrado; esta sessão confirmou escritor único. (Se abrir nova sessão, reconfirmar antes de reabrir escrita.)
- **Migrations (dev+test):** `Version20260708210509` + `Version20260708220000` + `Version20260709123952`.

## O que foi concluído nesta sessão
1. **Etapa 1 fechada e reconciliada** (herança de sessão concorrente): código revisado (variante 1) + purga; global verde. Ver histórico no `EXECUTION_STATUS.md`.
2. **Etapa 2 completa** (núcleo do Caso):
   - Andaime `121c173`: 3 entidades (`CasoCobranca`/`Obrigacao`/`EventoHistorico`), enums `StatusCaso`/`TipoEventoHistorico`, 3 repos, migration `Version20260709123952` (dev+test), 3 factories, cobertura da purga + spec `docs/specs/cobranca-etapa2-caso-saldo.md`.
   - Serviços `9d1aaa4`: `CalculadoraSaldo` (centavos) + `RegistrarEventoHistorico`.
   - Contratos `f759976`: 4 exceptions + `flush` no serviço de evento.
   - UseCases (fan-out 2 implementers → 1 review SEM BLOQUEANTES → cherry-pick individual): `3657aa6` (AbrirCaso, RegistrarObrigacao) + `8f90cfe` (ReconhecerValorAtualizado, RegistrarTentativaCobranca, AlterarPessoaCobrada).
   - Validação `4a094fe`: cross-tenant DB (`CasoCobrancaIsolamentoTenantTest`) + fix de cobertura de auditoria.

## Decisões de design (Etapa 2)
- **Dinheiro = `INTEGER` centavos** (não bigint — evita atrito string; teto ~R$21M/obrigação; somas em PHP 64-bit).
- **`pastaJudicial` adiado p/ Etapa 5.**
- **`CalculadoraSaldo` incremental** — Etapa 3 estende (subtrai pagamentos/liquidações), Etapa 4 (exclui obrigação substituída por acordo). Saldo sempre derivado (invariável 20).
- **`EventoHistorico` = log de domínio** (não Auditavel; invariável 26); está em `AuditavelCoberturaTest::NAO_AUDITAVEIS`.
- **Padrão de flush:** UseCase persiste a entidade principal (`salvar(entidade)` sem flush) e chama `RegistrarEventoHistorico::registrar(..., flush: true)` — 1 flush atômico. UseCase que só gera evento passa `flush: true`.
- **Guard same-tenant:** todo UseCase resolve TODAS as entidades por `findOneByIdDoTenant(id, $tenant)` — provado em DB.

## Git
- **HEAD:** `4a094fe` (+ docs). Ancestralidade Etapa 2 sobre `2719738`: `121c173`→`9d1aaa4`→`f759976`→`3657aa6`→`8f90cfe`→`4a094fe`.
- **`master`:** NÃO contém Cobranças. Mergear DEPOIS do DJEN, só no fim.
- **Worktrees ativas:** só `.worktrees/sincronizacao-drive` (outra feature). `.claude/worktrees/agent-*` são sobras de agente (limpeza do humano).

## Testes (comandos úteis)
- `php bin/phpunit tests/Cobranca` → 51/51.
- `php bin/phpunit --filter CasoCobrancaIsolamentoTenantTest` → 6/6 (cross-tenant DB).
- `php bin/phpunit --filter "PurgarEscritorioUseCaseTest|PurgaCoberturaSchemaTest"` → OK.
- `php bin/phpunit` (global) → 1332/1332.

## Follow-ups (não bloqueiam — detalhe no EXECUTION_STATUS)
- **Endurecer 4 testes de evento** (asserir `tipo`+`dados`) — MENOR do review.
- Cobertura de borda: `encargosReconhecidos=0`; fallback de descrição do `RegistrarTentativaCobranca`.
- Dedup por dígitos (Pessoa) → Etapa 7. Permissões `cobrancas` em prod → data-migration no deploy.

## Próxima ação exata
> **Etapa 3 — Pagamentos, Liquidações e Honorários** (PLAN §8; paralelização MÉDIA). Ordem: (1) confirmar Git + escritor único; (2) spec ALTO risco em `docs/specs/` + storytelling; (3) andaime committado — entidades `Pagamento`(+`AlocacaoPagamento`)/`Liquidacao`, enum `TipoLiquidacao`, serviço `CalculadoraHonorarios` (4 formas §18 + rateio proporcional, centavos) como contrato central, migration (aplicar dev+test via `migrations:execute --up`), factories, **cobrir 3 tabelas novas na purga** + seed, e **estender `CalculadoraSaldo`** (subtrair Σ alocações + Σ liquidação reconhecida); (4) fan-out dos UseCases `RegistrarPagamento`/`CorrigirPagamento` (SEM estorno)/`RegistrarLiquidacao`; (5) testes de rateio (fecha com o total, centavos), 4 formas de honorário, pagamento não atravessa casos (invariável 12), cross-tenant; suíte global + tenant-safety + commit + docs.

## Ordem de retomada
1. Confirmar branch `gestao-cobrancas`, HEAD `4a094fe` (ou posterior), working tree limpo, escritor único.
2. Ler `PLAN.md` §8 Etapa 3 + `PARALLELIZATION_MAP.md` §1 + `docs/specs/cobranca-etapa2-caso-saldo.md` (padrões do núcleo).
3. Andaime da Etapa 3 (contratos + `CalculadoraHonorarios` + migration + estender `CalculadoraSaldo`) → commit → fan-out → integração → validação.
4. Atualizar `EXECUTION_STATUS.md` + este arquivo ao fim.
