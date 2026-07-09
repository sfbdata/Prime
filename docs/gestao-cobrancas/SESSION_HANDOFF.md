# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-09 — **Etapa 3 CONCLUÍDA e validada** (suíte global 1380/1380).

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada; `master` só com DJEN).
- **HEAD:** `c0c72ac` ("Remover TipoLiquidacao::Dinheiro — dinheiro é sempre Pagamento") + este commit de docs. (Etapa 3 fechada em `ad8fa07`; `c0c72ac` = decisão do follow-up #7.)
- **Etapa:** 3 (Pagamentos/Liquidações/Honorários) → **✅ CONCLUÍDA**. Próxima = **Etapa 4** (Acordos).
- **Suíte:** GLOBAL **1380/1380 (3807 assert)**; `tests/Cobranca` 99/99 (inclui cross-tenant DB dos movimentos 7/7).
- **Working tree:** limpo (só untracked `.claude/worktrees/` — worktrees de agente, NÃO commitar).
- **Escritor:** ÚNICO. Sessão autônoma completou o fan-out e integrou tudo. (Se abrir nova sessão, reconfirmar escritor único antes de reabrir escrita.)
- **Migrations (dev+test):** Etapa 1 `Version20260708210509`+`Version20260708220000`; Etapa 2 `Version20260709123952`; **Etapa 3 `Version20260709142845`**.

## O que foi concluído nesta sessão (Etapa 3)
Pipeline autônomo completo `andaime → commit → fan-out (2 worktrees) → revisão read-only → cherry-pick individual → testes → correções → cross-tenant DB → suíte global → tenant-safety`.
1. **Andaime `39daa0b`** (orquestrador, sequencial): 3 entidades (`Pagamento`+`AlocacaoPagamento`, `Liquidacao`; TenantAware+Auditavel, centavos INT), enum `TipoLiquidacao`, 3 repos (queries agregadas do saldo), serviço **`CalculadoraHonorarios`** (4 formas §18 + rateio proporcional que fecha em centavos, aritmética inteira) +teste, **`CalculadoraSaldo` estendida** (subtrai alocações+liquidações) +teste, migration `Version20260709142845` (dev+test), 3 factories, cobertura+seed da purga, spec `docs/specs/cobranca-etapa3-pagamentos-honorarios.md`.
2. **Spec ajustada `16d77e1`**: alocação explícita; FIFO deferido à Etapa 8.
3. **Fan-out** (2 `feature-implementer` em worktree, revisados por `feature-review-agent` SEM BLOQUEANTES):
   - `bce0a21` (A, Pagamentos): `AlocadorPagamento` + `RegistrarPagamento` + `CorrigirPagamento` (SEM estorno) + DTOs + testes.
   - `a316c63` (B, Liquidações): `RegistrarLiquidacao` + DTO + teste.
4. **Integração `ad8fa07`**: correções do review (trim no `motivoCorrecao`; mensagem genérica de `CasoEncerradoException`; docblocks de liquidação) + `MovimentosCobrancaIsolamentoTenantTest` (cross-tenant DB + invariável 12 + saldo derivado + rateio acrescido). tenant-safety LIMPO.

## Decisões de design (Etapa 3)
- **Composição do `Pagamento`**: `valorDivida`+`valorEncargos` = dívida do credor (= Σ alocações); `valorHonorarios` = escritório (≠0 só no `acrescido_divida`). MVP: `valorEncargos` nasce 0.
- **Rateio** (`CalculadoraHonorarios::ratearPagamento`) fecha por construção (`divida = total − hon`); basis points inteiros, sem float.
- **`AlocadorPagamento`** centraliza rateio + validação (mesmo caso — invariável 12, identidade de instância; Σ === parte da dívida). Reusado por Registrar/Corrigir.
- **Correção SEM estorno** (§22): reescreve composição/alocações (`limparAlocacoes`+orphanRemoval), exige `motivoCorrecao`, auditável (Pagamento é `Auditavel`).
- **Liquidação** reduz o saldo pelo `valorReconhecido` (≠ `valorAtribuidoBem`, §11), sem rateio de honorários.
- **`CalculadoraSaldo`**: `saldoExigivel` subtrai Σ alocações + Σ liquidação; `saldoVencido` abate as vencidas + liquidação, piso 0.

## Git
- **HEAD:** `ad8fa07` (+ docs). Ancestralidade Etapa 3 sobre `22d46ae`: `39daa0b`→`16d77e1`→`bce0a21`→`a316c63`→`ad8fa07`.
- **`master`:** NÃO contém Cobranças. Mergear DEPOIS do DJEN, só no fim.
- **Worktrees de agente da Etapa 3** (limpeza do humano): `.claude/worktrees/agent-a3a6acd67246f2c65` (A) e `agent-a4e00b60da03d350b` (B) + branches `worktree-agent-*`.

## Testes (comandos úteis)
- `php bin/phpunit tests/Cobranca` → 99/99.
- `php bin/phpunit --filter "CalculadoraHonorariosTest|CalculadoraSaldoTest"` → calculadoras.
- `php bin/phpunit --filter MovimentosCobrancaIsolamentoTenantTest` → 7/7 (cross-tenant DB + invariável 12).
- `php bin/phpunit` (global) → 1380/1380. **Rodar com `php -d memory_limit=512M`** (cache:clear/warmup estoura o default de 128M).

## Follow-ups (não bloqueiam — detalhe no EXECUTION_STATUS §Follow-ups)
- **#7 ✅ RESOLVIDO (`c0c72ac`):** humano decidiu **remover `TipoLiquidacao::Dinheiro`** — dinheiro entra só por `Pagamento`; `Liquidacao` = só não monetário. Sem decisões de negócio pendentes.
- **#8** FIFO (sugestão de alocação) deferido à Etapa 8 (UI).
- **#9** Dívida consciente: guarda invariável 12 por instância; `AlocadorPagamento` valida contra `valorDivida` (encargos=0); `saldoExigivel` sem piso 0.
- **#10** Endurecer testes de evento (asserir `tipo`+`dados`) — igual ao #1 da Etapa 2.

## Próxima ação exata
> **Etapa 4 — Acordos** (PLAN §8; paralelização BAIXA, ~1 agente). Ordem: (1) confirmar Git + escritor único (follow-up #7 já resolvido); (2) spec ALTO risco em `docs/specs/` + storytelling; (3) andaime committado — entidade `Acordo` (+ join das obrigações substituídas), enum `StatusAcordo`, FKs `Obrigacao.acordoOrigem`/`acordoSubstituto` (migration **ALTERA `cobranca_obrigacao`** — cuidado, não é só tabela nova), migration (aplicar dev+test via `migrations:execute --up`), factory, **cobrir tabela(s) nova(s) na purga** + seed, e **estender `CalculadoraSaldo`** (EXCLUIR do exigível as obrigações com `acordoSubstituto != null`); (4) fan-out/sequencial dos UseCases `CriarAcordo`/`RomperAcordo`/`CancelarAcordo`/`MarcarAcordoCumprido`; (5) testes invariáveis 13/14/15, substituição parcial, parcela vencida não rompe automático (§12.7), acordo pós-judicialização (§12.10), cross-tenant; suíte global + tenant-safety + commit + docs.

## Ordem de retomada
1. Confirmar branch `gestao-cobrancas`, HEAD `c0c72ac` (ou posterior), working tree limpo, escritor único.
2. Follow-up #7 já resolvido (dinheiro removido de `TipoLiquidacao`) — sem decisão de negócio pendente.
3. Ler `PLAN.md` §8 Etapa 4 + `PARALLELIZATION_MAP.md` §1 + specs das Etapas 2/3 (padrões do núcleo e dos movimentos).
4. Andaime da Etapa 4 (contratos + FKs de acordo em `Obrigacao` + migration + estender `CalculadoraSaldo`) → commit → fan-out/sequencial → integração → validação.
5. Atualizar `EXECUTION_STATUS.md` + este arquivo ao fim.
