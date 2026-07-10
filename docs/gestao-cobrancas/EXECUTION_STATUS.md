# EXECUTION_STATUS — Gestão de Cobranças

> Panorama VIVO da implementação. Fonte de verdade do progresso. **Confirmar sempre contra o Git/código — nunca contra memória de chat.**
> Última atualização: 2026-07-10 (Etapa 6 CONCLUÍDA e validada).

---

## Panorama atual

| Campo | Valor real |
|---|---|
| **Etapa atual** | Etapa 6 — Documentos do Caso → **✅ CONCLUÍDA**; próxima = Etapa 7 (Importação em massa) |
| **Tarefa atual** | Nenhuma em execução |
| **Último checkpoint estável** | `2ab1783` — **suíte GLOBAL verde 1472/1472**; `tests/Cobranca` 191/191 (+ cross-tenant DB+disco dos documentos: INV-25, judicialização preserva docs, isolamento físico por tenant, IDOR) |
| **Branch** | `gestao-cobrancas` (dedicada; `master` só com DJEN) |
| **HEAD** | `2ab1783` (código estável; +docs a seguir) |
| **Working tree** | limpo (só untracked `.claude/worktrees/` — worktrees de agente, NÃO commitar) |
| **Migrations (dev+test)** | E1 `Version20260708210509`+`Version20260708220000`; E2 `Version20260709123952`; E3 `Version20260709142845`; E4 `Version20260709154458`; E5 `Version20260709191327`; **E6 `Version20260709215805`** (cobranca_secao + cobranca_documento; FKs caso_id/secao_id ON DELETE CASCADE) |
| **Escritor** | ÚNICO (orquestrador) + fan-out de 2 `feature-implementer` em worktrees (concluído e integrado) |

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

### Commits da Etapa 5 (sobre `fc48708`)
- `db7e86f` — **andaime**: entidades `ProximaAcao` (máx. 1 pendente/caso) e `RevisaoPessoaCobrada` (+ enums `StatusProximaAcao`/`StatusRevisao`); FK `CasoCobranca.pastaJudicial` (`ManyToOne Pasta`, nullable, `SET NULL`) + helper `estaJudicializado()`; repos `ProximaAcaoRepository`(+`findAtivaDoCaso`)/`RevisaoPessoaCobradaRepository`(+`existePendenteDoCaso`/`pendentesDoCaso`) — queries cross-cluster no contrato; 7 exceptions; migration `Version20260709191327` (ALTER cobranca_caso + 2 tabelas) dev+test; 2 factories; purga coberta (ORDEM_DELECAO + seed); spec `docs/specs/cobranca-etapa5-estados-judicializacao-alertas.md`.
- `d9d06bf` — `CalculadoraSaldo` **não-final** (mock nos UseCases de encerramento/alertas).
- `2dbfa80` — **fan-out A** (Judicialização/Encerramento): `JudicializarCasoUseCase` (vincula Pasta same-tenant, grava `Judicializacao`+`VinculoPasta`, NÃO encerra) + `EncerrarCasoUseCase` (manual, exige saldo exigível 0, de ativo/judicializado) + 2 DTOs + testes (cherry-pick de `93e81ac`).
- `52dcb61` — **fan-out B** (Próxima ação): `DefinirProximaAcaoUseCase` (máx. 1 ativa) + `ConcluirAcaoUseCase` (resultado + define próxima) + 2 DTOs + testes (cherry-pick de `7229f9e`).
- `faa8530` — **fan-out C** (Revisão/Alertas): `GerarRevisaoUseCase`/`ResolverRevisaoUseCase` + serviço read-only `AlertasCobranca` (5 alertas derivados) + enum `TipoAlerta` + DTO `AlertaCobranca` + 2 DTOs + testes (cherry-pick de `bcb414b`).
- `672d314` — **integração**: `JudicializacaoCobrancaIsolamentoTenantTest` (DB real: isolamento da Pasta, judicialização não encerra, encerramento só com saldo 0, alerta de revisão cessa após resolução — fecha lacuna do review, máx. 1 ação) + fix do review de B (ConcluirAcao rejeita criar próxima em caso encerrado, §17).
- `8cbd937` — cobertura cross-tenant IDOR dos demais UseCases (Encerrar/Definir/Concluir/Gerar/Resolver), fechando o MENOR da tenant-safety.

### Decisões de design da Etapa 5
- **"Pronto para encerrar" é indicador DERIVADO, não 4º estado** (SPEC §17): `status !== encerrado E saldoExigivel === 0`. `StatusCaso` mantém 3 valores. O indicador vive no `AlertasCobranca` (`TipoAlerta::ProntoParaEncerrar`), não em coluna.
- **Vínculo com Pasta é UNIDIRECIONAL** (`Caso → Pasta` via FK `pasta_judicial_id`, SET NULL): `PastaController` intocado. Guard same-tenant no UseCase (resolve Pasta por id+tenant) — provado por DB no cross-tenant. **Permissão `pastas` do controller é da Etapa 8** (não há camada HTTP nesta etapa) — documentado.
- **Judicialização ≠ encerramento** (invariável 16): muda a fase, não encerra. **Encerramento é sempre manual** e exige saldo exigível 0 (invariável 17), de ativo ou judicializado.
- **Revisão persistida** (`RevisaoPessoaCobrada`, pendente/resolvida) porque a SPEC §8 exige que o alerta CESSE após resolução — `existePendenteDoCaso` filtra por status (provado ponta-a-ponta no DB). Alertas puramente factuais (vencimento/parcela/ação atrasada/saldo zero) são **derivados por query**, sem tabela.
- **Alertas read-only** (invariável 28): nenhum muda estado. Caso encerrado → `AlertasCobranca` retorna `[]` (short-circuit; decisão de design documentada — aceita: encerrado = final, sem alertas operacionais).
- **Próxima ação NÃO é evento de histórico** (§13) nem alerta — só persiste; máx. 1 pendente garantido por `findAtivaDoCaso` no UseCase.

### Commits da Etapa 6 (sobre `102601d`)
- `ea3a21f` — **andaime**: entidades `CobrancaSecao`/`CobrancaDocumento` (FK `caso` nn **unidirecional** — `CasoCobranca` intocada; navegação pelos repos; `Secao→Documento` bidirecional c/ cascade remove); enum `CategoriaDocumentoCobranca`; repos tenant-safe (`findOneByIdDoTenant`/`documentosDoCaso`/`secoesDoCaso`/`proximaOrdem`); 4 exceptions; 2 factories; parâmetro `cobrancas_uploads_dir` (test→`var/uploads-test/cobrancas`) + bind; purga cobre `cobranca_documento`/`cobranca_secao` (ORDEM_DELECAO) + remove `cobrancas/<tenantId>/`; migration `Version20260709215805` (dev+test); spec.
- `b45c341` — exception `TipoArquivoNaoPermitidoException` que escapou do staging de `ea3a21f` (referenciada pelo `EnviarDocumento`).
- `c9560d0` — **fan-out B** (Seções): `CriarSecao`/`RenomearSecao`/`ExcluirSecao` + testes (cherry-pick de `a19e62d`). ExcluirSecao apaga arquivos físicos ANTES do remove/cascade.
- `09659e7` — **fan-out A** (Documentos): `EnviarDocumento` (whitelist MIME 19 tipos + limite tamanho + compressão opcional + isolamento físico por tenant) / `MoverDocumento` / `ExcluirDocumento` + testes (cherry-pick de `37e55de`).
- `2ab1783` — **integração**: `DocumentosCobrancaIsolamentoTenantTest` (DB+disco reais): INV-25 (doc sem Pasta), judicialização preserva documentos (não migra/duplica, §15/§16), isolamento físico por tenant, IDOR de Enviar/Mover/ExcluirSecao + seção de outro caso.

### Decisões de design da Etapa 6
- **Documento vive no Caso, NUNCA na Pasta** (invariável 25). FK `caso` obrigatória; caso sem Pasta pode ter documentos. Ao judicializar, documentos permanecem (não migram/duplicam) — provado ponta-a-ponta no DB.
- **Entidades próprias só pela FK ao Caso** (§24: não recriar Pasta/Documento). Mecânica de arquivo 100% reusada do `App\Shared\Service`. Front `pasta-arquivos.js/.css` religado por `data-*` na Etapa 8 (sem tocar o JS).
- **Isolamento físico por tenant** (padrão M5): `cobrancas/<tenantId>/<hash>`; `caminhoArquivo` = só o hash; diretório efetivo = `$cobrancasUploadsDir.'/'.$tenant->getId()` (contrato congelado, idêntico no salvar/excluir/purga).
- **ExcluirSecao exclui os documentos** (espelha Pasta); arquivo físico apagado ANTES do remove (senão o cascade perde o hash) — sem transação disco+DB (aceito, follow-up #16).
- **Categoria = enum** (default `Outro`). **Sem camada HTTP** (controllers/CSRF/gate/wiring do file manager) → Etapa 8.

---

## Checklist (Etapas 0–9 do PLAN)
- ✅ **Etapa 0** — Fundação (esqueleto, doctrine.yaml, permissões).
- ✅ **Etapa 1** — Cadastro: Carteira/Objeto/Pessoa/Vínculo (7 UseCases, cross-tenant, purga, tenant-safety íntegro).
- ✅ **Etapa 2** — Caso/Obrigações/Saldo: 3 entidades, 2 serviços, 5 UseCases, cross-tenant DB, purga, global 1332/1332.
- ✅ **Etapa 3** — Pagamentos/Liquidações/Honorários: 3 entidades (`Pagamento`+`AlocacaoPagamento`, `Liquidacao`), enum `TipoLiquidacao`, serviços `CalculadoraHonorarios` (4 formas + rateio, centavos) e `AlocadorPagamento`, `CalculadoraSaldo` estendida (subtrai alocações+liquidações), UseCases `RegistrarPagamento`/`CorrigirPagamento` (SEM estorno)/`RegistrarLiquidacao`; cross-tenant DB dos movimentos + invariável 12; global 1380/1380.
- ✅ **Etapa 4** — Acordos: entidade `Acordo`+enum `StatusAcordo`, FKs `Obrigacao.acordoOrigem`/`acordoSubstituto`, `doCasoExigiveis` status-aware, `CalculadoraSaldo` deriva por status (rompido/cancelado restaura originais), 4 UseCases (`CriarAcordo`/`RomperAcordo`/`CancelarAcordo`/`MarcarAcordoCumprido`), cross-tenant DB, global 1400/1400.
- ✅ **Etapa 5** — Estados/Judicialização (`pastaJudicial`)/Encerramento/ProximaAcao/Revisão/Alertas: 2 entidades (`ProximaAcao`/`RevisaoPessoaCobrada`) + 2 enums, FK `CasoCobranca.pastaJudicial` (unidirecional), 6 UseCases (`Judicializar`/`Encerrar`/`Definir`/`Concluir`/`Gerar`/`Resolver`), serviço `AlertasCobranca` (5 alertas derivados), cross-tenant DB (isolamento da Pasta provado), global 1441/1441.
- ✅ **Etapa 6** — Documentos do caso: entidades `CobrancaSecao`/`CobrancaDocumento` (FK caso nn, INV-25: sem Pasta), enum `CategoriaDocumentoCobranca`, 6 UseCases (`Enviar`/`Mover`/`Excluir` documento + `Criar`/`Renomear`/`Excluir` seção) reusando `ArquivoStorageService`, isolamento físico por tenant no disco, purga coberta, cross-tenant DB+disco (judicialização preserva docs), global 1472/1472.
- ⬜ **Etapa 7** — Importação em massa · **8** Telas/UX · **9** Alertas UI + Dashboard.

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
12. **✅ RESOLVIDO na Etapa 5:** parcela de acordo vencida → ALERTA derivado (`AlertasCobranca::ParcelaAcordoVencida`), sem rompimento automático (§12.7).
13. **Índice único de "máx. 1 próxima ação pendente" (MENOR, do review de B):** a garantia é o check `findAtivaDoCaso` no UseCase (fiel à SPEC §14, que nomeia esse mecanismo). Há janela TOCTOU teórica sob concorrência real; um índice parcial único `UNIQUE (caso_id) WHERE status='pendente'` fecharia a invariável no banco. Decisão de aceitar/endurecer é do humano.
14. **Short-circuit de alertas em caso encerrado (MENOR, do review de C):** `AlertasCobranca` retorna `[]` para caso encerrado — oculta uma `RevisaoPessoaCobrada` pendente que sobreviva ao encerramento (o `EncerrarCaso` não força resolver revisões). Coerente com §14 (encerrado = final); documentado no código. Confirmar com o humano se é intencional (é).
15. **Gate de permissão `pastas`/`cobrancas` na camada HTTP (Etapa 8):** os UseCases garantem o isolamento de tenant; o `can_access_module(...)` do controller entra com a camada HTTP na Etapa 8 (não há controllers nas etapas 5/6).
16. **(E6) Exclusão disco-antes-de-DB (MENOR):** `ExcluirDocumento`/`ExcluirSecao` apagam o arquivo físico ANTES de remover a linha; se o `flush` falhar depois, o arquivo some mas a linha permanece (sem transação abarcando disco+DB). Aceito por design (apagar antes preserva o hash; espelha `ExcluirPastaSecao`). Endurecer só se virar problema real.
17. **(E6) Cobertura de borda (MENOR):** branch `descricao === '' → null` no `EnviarDocumento` sem teste dedicado (uma mutação que remova `&& $descricao !== ''` passaria). Entrada vazia real chega via form; MENOR.

---

## Próxima ação exata
> Iniciar a **Etapa 7 — Importação em massa** (PLAN §9; SPEC §21; paralelização a definir). Passos:
> 1. Confirmar branch `gestao-cobrancas`, HEAD `2ab1783` (ou posterior), working tree limpo, escritor único; `tests/Cobranca` 191/191.
> 2. Ler `PLAN.md` §9 + `PARALLELIZATION_MAP.md` + SPEC §21. Investigar se já existe infra de importação/parser de planilha no projeto (reusar, não duplicar — §24: NÃO é importador universal).
> 3. Storytelling do(s) UseCase(s) de importação ANTES de implementar (quem importa, o quê, validação de linha, idempotência, mapeamento de colunas, o que acontece com linha inválida). Decidir se há entidade de "job de importação" persistida ou fluxo stateless.
> 4. Dedup por dígitos de CPF/CNPJ **dentro do tenant** (follow-up #3 — precisa índice funcional; avaliar na migration da etapa).
> 5. Andaime → commit → fan-out se útil → review read-only → cherry-pick individual → cross-tenant DB → suíte global → tenant-safety → docs.

> **Sem decisões de negócio pendentes conhecidas** (confirmar no storytelling da E7). Atenção da Etapa 7: volume/performance de importação em lote e idempotência; manter o escopo do MVP (§24 — sem importador universal de planilhas).
