# EXECUTION_STATUS — Gestão de Cobranças

> Panorama VIVO da implementação. Fonte de verdade do progresso. **Confirmar sempre contra o Git/código — nunca contra memória de chat.**
> Última atualização: 2026-07-09 (Etapa 3 CONCLUÍDA e validada).

---

## Panorama atual

| Campo | Valor real |
|---|---|
| **Etapa atual** | Etapa 3 — Pagamentos/Liquidações/Honorários → **✅ CONCLUÍDA**; próxima = Etapa 4 (Acordos) |
| **Tarefa atual** | Nenhuma em execução |
| **Último checkpoint estável** | `c0c72ac` — **suíte GLOBAL verde 1380/1380 (3807 assert)**; `tests/Cobranca` 99/99 (+ cross-tenant DB dos movimentos 7/7) |
| **Branch** | `gestao-cobrancas` (dedicada; `master` só com DJEN) |
| **HEAD** | `c0c72ac` (+ este commit de docs) |
| **Working tree** | limpo (só untracked `.claude/worktrees/`) |
| **Migrations (dev+test)** | Etapa 1 `Version20260708210509` + `Version20260708220000` (dedup); Etapa 2 `Version20260709123952`; **Etapa 3 `Version20260709142845`** (pagamento/alocacao_pagamento/liquidacao) |
| **Escritor** | ÚNICO (sessão autônoma; fan-out concluído e integrado) |

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

### Commits da Etapa 3 (sobre `22d46ae`)
- `39daa0b` — **andaime** (orquestrador): entidades `Pagamento`/`AlocacaoPagamento`/`Liquidacao` (TenantAware+Auditavel, centavos), enum `TipoLiquidacao`, 3 repos (queries agregadas do saldo), serviço `CalculadoraHonorarios` (4 formas + rateio, aritmética inteira) +teste, `CalculadoraSaldo` estendida +teste, migration `Version20260709142845` (dev+test), 3 factories, cobertura+seed da purga, spec `docs/specs/cobranca-etapa3-pagamentos-honorarios.md`.
- `16d77e1` — ajuste da spec: alocação explícita; FIFO deferido à Etapa 8.
- `bce0a21` — **fan-out A** (Pagamentos): `AlocadorPagamento` + `RegistrarPagamento` + `CorrigirPagamento` + DTOs + testes (cherry-pick de `14e2cb3`).
- `a316c63` — **fan-out B** (Liquidações): `RegistrarLiquidacao` + DTO + teste (cherry-pick de `b574cda`).
- `ad8fa07` — **integração**: correções do review (trim no `motivoCorrecao`; mensagem genérica de `CasoEncerradoException`; docblocks de liquidação) + `MovimentosCobrancaIsolamentoTenantTest` (cross-tenant DB + invariável 12 + saldo derivado + rateio acrescido).

### Decisões de design da Etapa 3
- **Composição do `Pagamento`**: `valorDivida`+`valorEncargos` abatem o saldo do credor (= Σ alocações); `valorHonorarios` é do escritório (≠0 só no `acrescido_divida`). No MVP `valorEncargos` nasce 0 (não desmembrado por pagamento).
- **Rateio** (`CalculadoraHonorarios::ratearPagamento`) fecha em centavos por construção (`divida = total − hon`); aritmética inteira (basis points), sem float. As 4 formas (§18) cobertas.
- **`AlocadorPagamento`** centraliza rateio + validação (mesmo caso — invariável 12, por identidade de instância; Σ alocações === parte da dívida). Reusado por Registrar/Corrigir.
- **Correção SEM estorno** (SPEC §22): reescreve composição/alocações (`limparAlocacoes`+orphanRemoval), exige `motivoCorrecao`, rastreável pela auditoria (Pagamento é `Auditavel`).
- **Liquidação** reduz o saldo pelo `valorReconhecido` (≠ `valorAtribuidoBem`, §11), diretamente, SEM rateio de honorários (distinta do Pagamento).
- **`CalculadoraSaldo` estendida**: `saldoExigivel` subtrai Σ alocações + Σ liquidação; `saldoVencido` abate alocações às vencidas + liquidação, piso 0.

---

## Checklist (Etapas 0–9 do PLAN)
- ✅ **Etapa 0** — Fundação (esqueleto, doctrine.yaml, permissões).
- ✅ **Etapa 1** — Cadastro: Carteira/Objeto/Pessoa/Vínculo (7 UseCases, cross-tenant, purga, tenant-safety íntegro).
- ✅ **Etapa 2** — Caso/Obrigações/Saldo: 3 entidades, 2 serviços, 5 UseCases, cross-tenant DB, purga, global 1332/1332.
- ✅ **Etapa 3** — Pagamentos/Liquidações/Honorários: 3 entidades (`Pagamento`+`AlocacaoPagamento`, `Liquidacao`), enum `TipoLiquidacao`, serviços `CalculadoraHonorarios` (4 formas + rateio, centavos) e `AlocadorPagamento`, `CalculadoraSaldo` estendida (subtrai alocações+liquidações), UseCases `RegistrarPagamento`/`CorrigirPagamento` (SEM estorno)/`RegistrarLiquidacao`; cross-tenant DB dos movimentos + invariável 12; global 1380/1380.
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
6. **Limpeza (humano):** branches-sobra `worktree-agent-*` + dirs `.claude/worktrees/agent-*` (`branch -D`/`worktree remove` são do humano). Da Etapa 3: `worktree-agent-a3a6acd67246f2c65` (A) e `worktree-agent-a4e00b60da03d350b` (B).
7. **✅ RESOLVIDO (2026-07-09, commit `c0c72ac`):** o humano decidiu **remover `TipoLiquidacao::Dinheiro`**. Regra definitiva: dinheiro do devedor entra EXCLUSIVAMENTE pelo fluxo de `Pagamento` (alocação + rateio de honorários + correção auditável + honorários realizados); `Liquidacao` = só formas NÃO monetárias (`bem_movel`/`bem_imovel`/`outro`). Enum/entidade/testes/seed/spec ajustados; nenhum dado persistido usava o tipo.
8. **FIFO deferido (Etapa 3→8):** `RegistrarPagamento` recebe **alocação explícita**. A sugestão FIFO por vencimento (pré-preenchimento saldo-aware, por obrigação) é de UI → **Etapa 8**.
9. **Dívida consciente do review (Etapa 3, MENOR):** (a) guarda da invariável 12 por identidade de instância — correta no fluxo atual (identity-map), mas frágil a mudança de fluxo; id-based seria mais defensivo. (b) `AlocadorPagamento` valida `Σ === valorDivida` (não `valorRecuperadoDivida`) — fecha só porque `valorEncargos=0` no MVP; documentar se um caminho futuro usar encargos≠0. (c) `saldoExigivel` não tem piso 0 (over-liquidation → negativo) — fiel à spec atual; `saldoVencido` tem piso 0.
10. **Endurecer testes de evento (Etapa 3, MENOR):** os testes unit dos UseCases de pagamento/liquidação verificam `salvar(EventoHistorico, true)` mas não asseram `tipo`+chaves de `dados`. Mesmo padrão do follow-up #1 da Etapa 2.

---

## Próxima ação exata
> Iniciar a **Etapa 4 — Acordos** (PLAN §8; paralelização BAIXA, ~1 agente; pode correr em paralelo à trilha de Documentos/Etapa 6). Passos:
> 1. Confirmar branch `gestao-cobrancas`, HEAD `ad8fa07` (ou posterior), working tree limpo, escritor único.
> 2. Spec da etapa (ALTO risco — saldo/substituição) em `docs/specs/`; storytelling dos UseCases (`CriarAcordo`/`RomperAcordo`/`CancelarAcordo`/`MarcarAcordoCumprido`).
> 3. Andaime committado: entidade `Acordo` (+ join das obrigações substituídas), enum `StatusAcordo`; FKs `Obrigacao.acordoOrigem`/`acordoSubstituto` (migration ALTERA `cobranca_obrigacao` — cuidado); migration (`cobranca_acordo` + colunas), aplicar dev+test via `migrations:execute --up`; factory; **cobrir a(s) tabela(s) nova(s) na purga** + seed. **Estender `CalculadoraSaldo`** para EXCLUIR do exigível as obrigações substituídas por acordo (`acordoSubstituto != null`).
> 4. UseCases: `CriarAcordo` (seleciona obrigações do MESMO caso a substituir; gera parcelas como novas `Obrigacao` com `acordoOrigem`; substituição parcial permitida, §12.5), `RomperAcordo` (manual+motivo), `CancelarAcordo`, `MarcarAcordoCumprido`.
> 5. Testes: invariáveis 13/14/15 (acordo não atravessa casos; obrigações substituídas somem do exigível mas persistem); parcela vencida NÃO rompe automático (§12.7); substituição parcial; acordo continua após judicialização (§12.10); cross-tenant. Suíte global + tenant-safety + commit + docs.

> **Follow-up #7 (dinheiro na `TipoLiquidacao`) ✅ RESOLVIDO** (`c0c72ac`, dinheiro removido). Sem decisões de negócio pendentes — Etapa 4 liberada.
