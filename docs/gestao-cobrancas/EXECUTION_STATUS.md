# EXECUTION_STATUS — Gestão de Cobranças

> Panorama VIVO da implementação. Fonte de verdade do progresso. **Confirmar sempre contra o Git/código — nunca contra memória de chat.**
> Última atualização: 2026-07-09 (Etapa 4 CONCLUÍDA e validada).

---

## Panorama atual

| Campo | Valor real |
|---|---|
| **Etapa atual** | Etapa 4 — Acordos → **✅ CONCLUÍDA**; próxima = Etapa 5 (Estados/Judicialização/Encerramento/ProximaAcao/Revisão/Alertas) |
| **Tarefa atual** | Nenhuma em execução |
| **Último checkpoint estável** | `269cc6a` — **suíte GLOBAL verde 1400/1400 (3927 assert)**; `tests/Cobranca` 119/119 (+ cross-tenant DB acordos/movimentos) |
| **Branch** | `gestao-cobrancas` (dedicada; `master` só com DJEN) |
| **HEAD** | `ccfa2c6` (docs da Etapa 4; código estável em `269cc6a`) — esta verificação de continuidade adiciona +1 commit só de docs |
| **Working tree** | limpo (só untracked `.claude/worktrees/`) |
| **Migrations (dev+test)** | E1 `Version20260708210509`+`Version20260708220000`; E2 `Version20260709123952`; E3 `Version20260709142845`; **E4 `Version20260709154458`** (cobranca_acordo + ALTER cobranca_obrigacao) |
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

### Commits da Etapa 4 (sobre `d32cf16`←`5b05953`)
- `d32cf16` — **andaime**: entidade `Acordo` + enum `StatusAcordo` (+ evento `AcordoCumprido`); FKs `Obrigacao.acordoOrigem`/`acordoSubstituto`; `ObrigacaoRepository::doCasoExigiveis` (status-aware); `CalculadoraSaldo` deriva do exigível (usa `doCasoExigiveis`+`totalAlocadoEmObrigacoes`; `totalAlocadoNoCaso` removido); `AcordoRepository`+3 exceptions; migration `Version20260709154458` (CREATE acordo + ALTER obrigacao) dev+test; `AcordoFactory`; purga coberta+seed; spec.
- `52e8f2f` — **fan-out** (1 `feature-implementer`): `CriarAcordo`/`RomperAcordo`/`CancelarAcordo`/`MarcarAcordoCumprido` + 5 DTOs + testes (cherry-pick de `6be3e5d`).
- `269cc6a` — **integração**: fix BLOQUEANTE do review (re-substituir obrigação de acordo rompido/cancelado — guarda passa a usar `StatusAcordo::ehVigente()`) + mensagem genérica de `ObrigacaoDeOutroCasoException` + `AcordoCobrancaIsolamentoTenantTest` (DB real).

### Decisões de design da Etapa 4
- **Saldo derivado por STATUS do acordo** (invariável 20): obrigação exigível ⟺ (não substituída por acordo vigente `ativo/cumprido`) E (não é parcela de acordo `rompido/cancelado`). Romper/cancelar restaura originais e descarta parcelas SEM reversão imperativa — só muda `Acordo.status`, `doCasoExigiveis` deriva. *(Se o negócio quiser rompimento que NÃO restaura, trocar só a regra do `doCasoExigiveis`.)*
- **Substituição = 2 FKs em `Obrigacao`** (`acordoOrigem`/`acordoSubstituto`), sem join table. Substituída nunca apagada (invariável 14), só marcada.
- **Re-substituição** permitida quando o acordo substituto anterior não é vigente (rompido/cancelado) — corrigido no review (era bloqueado por engano).
- **Migration ALTERA `cobranca_obrigacao`** (2 colunas FK nullable SET NULL) — 1ª vez que uma migration de Cobranças toca tabela existente do próprio módulo.

---

## Checklist (Etapas 0–9 do PLAN)
- ✅ **Etapa 0** — Fundação (esqueleto, doctrine.yaml, permissões).
- ✅ **Etapa 1** — Cadastro: Carteira/Objeto/Pessoa/Vínculo (7 UseCases, cross-tenant, purga, tenant-safety íntegro).
- ✅ **Etapa 2** — Caso/Obrigações/Saldo: 3 entidades, 2 serviços, 5 UseCases, cross-tenant DB, purga, global 1332/1332.
- ✅ **Etapa 3** — Pagamentos/Liquidações/Honorários: 3 entidades (`Pagamento`+`AlocacaoPagamento`, `Liquidacao`), enum `TipoLiquidacao`, serviços `CalculadoraHonorarios` (4 formas + rateio, centavos) e `AlocadorPagamento`, `CalculadoraSaldo` estendida (subtrai alocações+liquidações), UseCases `RegistrarPagamento`/`CorrigirPagamento` (SEM estorno)/`RegistrarLiquidacao`; cross-tenant DB dos movimentos + invariável 12; global 1380/1380.
- ✅ **Etapa 4** — Acordos: entidade `Acordo`+enum `StatusAcordo`, FKs `Obrigacao.acordoOrigem`/`acordoSubstituto`, `doCasoExigiveis` status-aware, `CalculadoraSaldo` deriva por status (rompido/cancelado restaura originais), 4 UseCases (`CriarAcordo`/`RomperAcordo`/`CancelarAcordo`/`MarcarAcordoCumprido`), cross-tenant DB, global 1400/1400.
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
11. **Endurecer testes de evento de acordo (Etapa 4, MENOR):** mesma lacuna nos UseCases de acordo (não asseram `tipo`+`dados`). Além disso, o teste `criaAcordoComSubstituicaoParcialEParcelas` tem asserção "vacuum" sobre a obrigação não-listada (`$obrigMantida` nunca chega ao SUT) — o comportamento é correto por construção (o UseCase só itera os ids do input), mas o teste dá falsa confiança.
12. **Parcela de acordo vencida → ALERTA (Etapa 5):** §12.7 diz que parcela vencida NÃO rompe automático (garantido: nenhum UseCase rompe sozinho). O ALERTA de atraso (derivado, com tolerância da carteira) é da Etapa 5 (`AlertasCobranca`).

---

## Próxima ação exata
> Iniciar a **Etapa 5 — Estados/Judicialização/Encerramento/Próxima ação/Revisões/Alertas** (PLAN §8; paralelização ALTA, ~3 agentes após o andaime; sub-features independentes). Passos:
> 1. Confirmar branch `gestao-cobrancas`, HEAD `269cc6a` (ou posterior), working tree limpo, escritor único.
> 2. Spec ALTO risco em `docs/specs/` + storytelling. Sub-features: transições de `StatusCaso`; `Judicializar` (vincula `Pasta` EXISTENTE — respeita tenant + permissão do módulo `pastas`; grava `EventoHistorico`+`vinculo_pasta`; NÃO duplica pasta/processo/doc, §16); indicador derivado "pronto para encerrar" (saldo exigível 0 e não encerrado — NÃO é 4º estado); `EncerrarCaso` (manual, saldo resolvido; bloqueia novas obrigações; permite novo caso futuro, §17); entidade `ProximaAcao` (máx. 1 ativa/caso, §14) + `DefinirProximaAcao`/`ConcluirAcao`; entidade `RevisaoPessoaCobrada` + `GerarRevisao`/`ResolverRevisao` (§8 — para de alertar após resolvida); serviço `AlertasCobranca` (derivados: vencimento a verificar, parcela de acordo vencida, ação atrasada, saldo zero, revisão pendente).
> 3. Andaime committado: adicionar `CasoCobranca.pastaJudicial` (`ManyToOne Pasta` nullable — migration ALTERA `cobranca_caso`); entidades `ProximaAcao`/`RevisaoPessoaCobrada` (+ enums `StatusProximaAcao`/status de revisão); migration (dev+test via `migrations:execute --up`); factories; **cobrir as tabelas novas na purga** + seed. **Integração com Pasta é UNIDIRECIONAL** (`Caso → Pasta` via FK; NÃO tocar `PastaController`, ~1800 linhas — PLAN §10.4).
> 4. Fan-out (3 agentes, sub-features disjuntas): {Judicialização/Encerramento} × {ProximaAcao} × {RevisaoPessoaCobrada + AlertasCobranca}.
> 5. Testes: invariáveis 16/17; judicialização não encerra; encerramento só manual; "pronto para encerrar" é indicador; máx. 1 próxima ação ativa; alerta de revisão cessa após resolução; **cross-tenant no vínculo com Pasta (não vincular Pasta de outro tenant)**. Suíte global + tenant-safety + commit + docs.

> **Sem decisões de negócio pendentes.** Atenção da Etapa 5: judicialização toca o domínio **Pasta** (outro módulo) — respeitar tenant + permissão `pastas`; ligação unidirecional; investigar `App\Pasta\Entity\Pasta` + `pasta_show` antes do andaime.
