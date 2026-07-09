# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-09 — **Etapa 4 CONCLUÍDA e validada** (suíte global 1400/1400).

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada; `master` só com DJEN).
- **HEAD:** `269cc6a` ("Integrar Etapa 4: fix bloqueante da re-substituição + cross-tenant DB") + este commit de docs.
- **Etapa:** 4 (Acordos) → **✅ CONCLUÍDA**. Próxima = **Etapa 5** (Estados/Judicialização/Encerramento/ProximaAcao/Revisão/Alertas).
- **Suíte:** GLOBAL **1400/1400 (3927 assert)**; `tests/Cobranca` 119/119.
- **Working tree:** limpo (só untracked `.claude/worktrees/` — worktrees de agente, NÃO commitar).
- **Escritor:** ÚNICO. Sessão autônoma completou Etapa 3, follow-up #7 e Etapa 4. (Se abrir nova sessão, reconfirmar escritor único antes de reabrir escrita.)
- **Migrations (dev+test):** E1 `Version20260708210509`+`Version20260708220000`; E2 `Version20260709123952`; E3 `Version20260709142845`; **E4 `Version20260709154458`** (cobranca_acordo + ALTER cobranca_obrigacao).

## O que foi concluído nesta sessão (Etapa 3 + follow-up #7 + Etapa 4)
Esta sessão fechou **duas etapas** e uma decisão de negócio, sempre pelo pipeline autônomo `andaime→commit→fan-out (worktrees)→review read-only→cherry-pick→testes→correções→cross-tenant DB→suíte global→tenant-safety→docs`.
- **Etapa 3** (Pagamentos/Liquidações/Honorários) — fechada em `ad8fa07`: `Pagamento`+`AlocacaoPagamento`/`Liquidacao`, `CalculadoraHonorarios`, `AlocadorPagamento`, UseCases RegistrarPagamento/CorrigirPagamento (SEM estorno)/RegistrarLiquidacao.
- **Follow-up #7** — `c0c72ac`: humano decidiu **remover `TipoLiquidacao::Dinheiro`** (dinheiro só via Pagamento; Liquidacao = só não monetário).
- **Etapa 4** (Acordos):
  - Andaime `d32cf16`: entidade `Acordo`+enum `StatusAcordo` (+evento `AcordoCumprido`); FKs `Obrigacao.acordoOrigem`/`acordoSubstituto`; `ObrigacaoRepository::doCasoExigiveis` (status-aware); `CalculadoraSaldo` deriva do exigível; `AcordoRepository`+3 exceptions; migration `Version20260709154458` (CREATE acordo + ALTER obrigacao); `AcordoFactory`; purga+seed; spec.
  - Fan-out `52e8f2f` (1 `feature-implementer`): `CriarAcordo`/`RomperAcordo`/`CancelarAcordo`/`MarcarAcordoCumprido` + 5 DTOs + testes.
  - Integração `269cc6a`: fix BLOQUEANTE do review (re-substituir obrigação de acordo rompido/cancelado — guarda usa `StatusAcordo::ehVigente()`) + mensagem genérica de `ObrigacaoDeOutroCasoException` + `AcordoCobrancaIsolamentoTenantTest` (DB real).

## Decisões de design (Etapa 4)
- **Saldo derivado por STATUS do acordo** (invariável 20): obrigação exigível ⟺ (NÃO substituída por acordo vigente `ativo`/`cumprido`) E (NÃO é parcela de acordo `rompido`/`cancelado`). Romper/cancelar restaura os originais e descarta as parcelas SEM reversão imperativa — só muda `Acordo.status`; `doCasoExigiveis` deriva. Se o negócio quiser rompimento que NÃO restaura, trocar só a regra do `doCasoExigiveis`.
- **Substituição = 2 FKs em `Obrigacao`** (sem join table); substituída nunca apagada (invariável 14), só marcada. **Re-substituição** permitida se o acordo substituto anterior não é vigente.
- **`Version20260709154458` ALTERA `cobranca_obrigacao`** — 1ª migration de Cobranças a tocar tabela existente do próprio módulo.

## Git
- **HEAD:** `269cc6a` (+ docs). Ancestralidade Etapa 4 sobre `5b05953`(docs #7)←`c0c72ac`: `d32cf16`→`52e8f2f`→`269cc6a`.
- **`master`:** NÃO contém Cobranças. Mergear DEPOIS do DJEN, só no fim.
- **Worktrees de agente (limpeza do humano):** E3 `agent-a3a6acd67246f2c65`/`agent-a4e00b60da03d350b`; E4 `agent-a76d50e23988e7107` + branches `worktree-agent-*`.

## Testes (comandos úteis) — **sempre `php -d memory_limit=512M`** (warmup/phpunit estoura o default 128M)
- `php bin/phpunit tests/Cobranca` → 119/119.
- `php bin/phpunit --filter "CriarAcordoUseCaseTest|AcordoCobrancaIsolamentoTenantTest"` → acordos.
- `php bin/phpunit --filter "CalculadoraSaldoTest|CalculadoraHonorariosTest"` → calculadoras.
- `php bin/phpunit` (global) → 1400/1400.

## Follow-ups (não bloqueiam — detalhe no EXECUTION_STATUS §Follow-ups)
- **#7 ✅ RESOLVIDO** (`c0c72ac`, dinheiro removido). **Sem decisões de negócio pendentes.**
- **#8** FIFO (sugestão de alocação) → Etapa 8 (UI).
- **#9** Dívida consciente E3 (guarda invariável 12 por instância; `AlocadorPagamento` valida contra `valorDivida`/encargos=0; `saldoExigivel` sem piso 0).
- **#10/#11** Endurecer testes de evento (E3+E4: asserir `tipo`+`dados`); teste "vacuum" da substituição parcial no `CriarAcordoUseCaseTest`.
- **#12** Parcela de acordo vencida → ALERTA derivado é da Etapa 5 (não rompe automático — já garantido).

## Próxima ação exata
> **Etapa 5 — Estados/Judicialização/Encerramento/Próxima ação/Revisões/Alertas** (PLAN §8; paralelização ALTA, ~3 agentes após o andaime). Ordem: (1) confirmar Git + escritor único; (2) spec ALTO risco + storytelling; (3) andaime committado — `CasoCobranca.pastaJudicial`(`ManyToOne Pasta` nullable → migration **ALTERA `cobranca_caso`**), entidades `ProximaAcao`(máx. 1 ativa/caso, §14)/`RevisaoPessoaCobrada`(§8) + enums, migration (dev+test via `migrations:execute --up`), factories, **purga+seed** das tabelas novas; (4) fan-out 3 sub-features disjuntas: {Judicializar+EncerrarCaso+indicador "pronto p/ encerrar"} × {ProximaAcao: Definir/Concluir} × {RevisaoPessoaCobrada: Gerar/Resolver + serviço `AlertasCobranca` derivado}; (5) testes invariáveis 16/17, judicialização não encerra, encerramento só manual, "pronto p/ encerrar" é indicador (não 4º estado), máx. 1 próxima ação ativa, alerta de revisão cessa após resolução, **cross-tenant no vínculo com Pasta**; suíte global + tenant-safety + commit + docs.

> **⚠️ Atenção da Etapa 5:** judicialização integra o domínio **Pasta** (outro módulo). Ligação **UNIDIRECIONAL** `Caso → Pasta` (FK + link `pasta_show`); **NÃO tocar `PastaController`** (~1800 linhas, PLAN §10.4); respeitar tenant + permissão do módulo `pastas` ao vincular. Investigar `App\Pasta\Entity\Pasta` antes do andaime.

## Ordem de retomada
1. Confirmar branch `gestao-cobrancas`, HEAD `269cc6a` (ou posterior), working tree limpo, escritor único.
2. Ler `PLAN.md` §8 Etapa 5 + `PARALLELIZATION_MAP.md` §1 + specs das Etapas 2/3/4 (padrões do núcleo, movimentos e acordos) + investigar o domínio `Pasta`.
3. Andaime da Etapa 5 (pastaJudicial + ProximaAcao + RevisaoPessoaCobrada + migration + factories + purga) → commit → fan-out (3 sub-features) → integração → validação.
4. Atualizar `EXECUTION_STATUS.md` + este arquivo ao fim.
