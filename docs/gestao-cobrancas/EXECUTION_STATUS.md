# EXECUTION_STATUS — Gestão de Cobranças

> Panorama VIVO da implementação. Fonte de verdade do progresso. **Confirmar sempre contra o Git/código — nunca contra memória de chat.**
> Última atualização: 2026-07-09 (Etapa 2 CONCLUÍDA e validada).

---

## Panorama atual

| Campo | Valor real |
|---|---|
| **Etapa atual** | Etapa 2 — Caso/Obrigações/Saldo → **✅ CONCLUÍDA**; próxima = Etapa 3 |
| **Tarefa atual** | Nenhuma em execução |
| **Último checkpoint estável** | `4a094fe` — **suíte GLOBAL verde 1332/1332 (3634 assert)**; `tests/Cobranca` 51/51 (+ cross-tenant DB 6/6) |
| **Branch** | `gestao-cobrancas` (dedicada; `master` só com DJEN) |
| **HEAD** | `4a094fe` (+ este commit de docs) |
| **Working tree** | limpo (só untracked `.claude/worktrees/`) |
| **Migrations (dev+test)** | `Version20260708210509` (Etapa 1) + `Version20260708220000` (índices dedup) + `Version20260709123952` (Etapa 2: caso/obrigacao/evento) |
| **Escritor** | ÚNICO (o processo concorrente da Etapa 1 foi encerrado; sessão confirmou escritor único) |

### Commits da Etapa 2 (sobre `2719738`)
- `121c173` — andaime: entidades `CasoCobranca`/`Obrigacao`/`EventoHistorico`, enums `StatusCaso`/`TipoEventoHistorico`, 3 repos-stub (+queries tenant-scoped), migration `Version20260709123952` (dev+test), 3 factories, **cobertura da purga** (3 tabelas) + seed no teste da purga, spec `docs/specs/cobranca-etapa2-caso-saldo.md`.
- `9d1aaa4` — serviços `CalculadoraSaldo` (saldo exigível/vencido/consolidado em CENTAVOS int) + `RegistrarEventoHistorico` (+ testes unit).
- `f759976` — contratos das UseCases: 4 exceptions (`CasoNaoEncontrado`, `CasoAtivoJaExiste`, `CasoEncerrado`, `ObrigacaoNaoEncontrada`) + param `flush` no serviço de evento.
- `3657aa6` — fan-out cluster A: `AbrirCaso` + `RegistrarObrigacao` (cherry-pick de worktree `0dd917c`).
- `8f90cfe` — fan-out cluster B: `ReconhecerValorAtualizado` + `RegistrarTentativaCobranca` + `AlterarPessoaCobrada` (cherry-pick de worktree `b266305`).
- `4a094fe` — validação: teste **cross-tenant real (DB)** do caso/obrigação/saldo (`CasoCobrancaIsolamentoTenantTest`) + registro de `EventoHistorico` em `NAO_AUDITAVEIS` (é log de domínio, invariável 26).

### Decisões de design da Etapa 2
- **Dinheiro = `INTEGER` de CENTAVOS** (property `int`; evita o atrito string do `bigint`; teto ~R$21M/obrigação, somas em PHP 64-bit). Migrar p/ bigint é trivial se necessário.
- **`pastaJudicial` ADIADO para a Etapa 5** (judicialização) — mantém a Etapa 2 sem acoplar Pasta e simplifica a purga.
- **`CalculadoraSaldo` incremental**: hoje soma obrigações (valorOriginal+encargos). A subtração de pagamentos/liquidações (E3) e a exclusão por acordo (E4) entram como extensão orquestrador-owned nas próximas etapas. Saldo é sempre derivado (invariável 20), nunca coluna.
- **`EventoHistorico` é o log de DOMÍNIO** (SPEC §13), NÃO Auditavel (invariável 26). `CasoCobranca`/`Obrigacao` (tocam dinheiro) SÃO Auditavel.
- **Fan-out validado**: 2 `feature-implementer` em worktree → 1 `feature-review-agent` (SEM BLOQUEANTES) → cherry-pick individual + teste direcionado. Harness NÃO auto-integrou (escritor único).

---

## Checklist (Etapas 0–9 do PLAN)
- ✅ **Etapa 0** — Fundação (esqueleto, doctrine.yaml, permissões).
- ✅ **Etapa 1** — Cadastro: Carteira/Objeto/Pessoa/Vínculo (7 UseCases, cross-tenant, purga, tenant-safety íntegro).
- ✅ **Etapa 2** — Caso/Obrigações/Saldo: 3 entidades, 2 serviços, 5 UseCases, cross-tenant DB, purga, global 1332/1332.
- ⬜ **Etapa 3** — Pagamentos/Liquidações/Honorários (`Pagamento`+`AlocacaoPagamento`, `Liquidacao`, `CalculadoraHonorarios` 4 formas + rateio; `RegistrarPagamento`/`CorrigirPagamento` SEM estorno/`RegistrarLiquidacao`). **Estende `CalculadoraSaldo`** para subtrair pagamentos/liquidações.
- ⬜ **Etapa 4** — Acordos (substituição de obrigações; estende `CalculadoraSaldo`).
- ⬜ **Etapa 5** — Estados/Judicialização (`pastaJudicial`)/Encerramento/ProximaAcao/Revisão/Alertas.
- ⬜ **Etapa 6** — Documentos do caso · **7** Importação · **8** Telas/UX · **9** Alertas UI + Dashboard.

### Transversal / deploy (fim)
- ⬜ Data-migration de permissões `cobrancas` p/ **produção** (dev/test já via fixture).
- ⬜ Deploy via `deploy-prod-tls.sh` (rebuild) — só no fim.
- 🔁 **Regra viva:** toda tabela `tenant_id` nova → adicionar à `PurgarEscritorioUseCase::ORDEM_DELECAO` (senão `PurgaCoberturaSchemaTest` falha) + seed no teste da purga.

---

## Follow-ups registrados (não bloqueiam)
1. **Endurecer testes de evento (MENOR, do review da Etapa 2):** 4 dos 5 testes unit de UseCase asseram só `salvar(isInstanceOf(EventoHistorico), true)` — não checam `tipo`+`dados` do evento (o log de domínio §13). Produção está correta (reviewer confirmou); é lacuna de rede de segurança. Arquivos: `AbrirCasoUseCaseTest`, `RegistrarObrigacaoUseCaseTest`, `ReconhecerValorAtualizadoUseCaseTest`, `AlterarPessoaCobradaUseCaseTest`. Fix: capturar o evento no mock e asserir `getTipo()` + chaves de `getDados()`.
2. **Cobertura de borda (MENOR):** `encargosReconhecidos = 0` (zera reconhecimento) e o fallback de `descricao` do `RegistrarTentativaCobranca` (quando `observacao` vazia) sem teste dedicado.
3. **Dedup por dígitos** (Pessoa) → Etapa 7 (importação); precisaria índice funcional.
4. **`CriarCarteiraUseCase`** usar `findOneByIdDoTenant` nomeado (hoje `findOneBy(['id','tenant'])` — seguro, só estilo).
5. **Análise estática** (se CI rodar PHPStan/Psalm estrito): `?CasoCobranca`→`CasoCobranca` em `ReconhecerValorAtualizado` e `?Carteira`→`getModo()` em `AbrirCaso` (seguros em runtime — FKs nn).
6. **Limpeza (humano):** branches-sobra `worktree-agent-*` + dirs `.claude/worktrees/agent-*` (`branch -D`/`worktree remove` são do humano).

---

## Próxima ação exata
> Iniciar a **Etapa 3 — Pagamentos, Liquidações e Honorários** (PLAN §8; paralelização MÉDIA, 2 agentes após o andaime). Passos:
> 1. Confirmar branch `gestao-cobrancas`, HEAD `4a094fe` (ou posterior), working tree limpo, escritor único.
> 2. Spec da etapa (ALTO risco — dinheiro/rateio) em `docs/specs/`; storytelling dos UseCases.
> 3. Andaime committado: entidades `Pagamento`(+`AlocacaoPagamento`), `Liquidacao`; enum `TipoLiquidacao`; serviço `CalculadoraHonorarios` (4 formas §18, rateio proporcional, centavos) como CONTRATO central; migration (`cobranca_pagamento`/`cobranca_alocacao_pagamento`/`cobranca_liquidacao`, aplicar dev+test via `migrations:execute --up`); factories; **cobrir as 3 tabelas novas na purga** + seed. **Estender `CalculadoraSaldo`** para subtrair Σ alocações de pagamento + Σ liquidação reconhecida.
> 4. UseCases `RegistrarPagamento` (alocação explícita + sugestão FIFO; rateio honorários quando `acrescido_divida`), `CorrigirPagamento` (SEM estorno; rastreável pela auditoria), `RegistrarLiquidacao` (valor reconhecido ≠ valor do bem).
> 5. Testes: rateio fecha com o total (centavos), 4 formas de honorário, pagamento não atravessa casos (invariável 12), liquidação reconhecida ≠ valor do bem, cross-tenant. Suíte global + commit + docs.
