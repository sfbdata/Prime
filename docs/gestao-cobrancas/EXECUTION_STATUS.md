# EXECUTION_STATUS — Gestão de Cobranças

> Panorama VIVO da implementação. Fonte de verdade do progresso. **Confirmar sempre contra o Git/código — nunca contra memória de chat.**
> Última atualização: 2026-07-10 (**Etapa 9 COMPLETA**: Dashboard + Central de Alertas. HEAD `3cd426a`; `tests/Cobranca` 398/398; GLOBAL 1679/1679. **Etapas 0–9 CONCLUÍDAS** — resta só o preparo de deploy/homologação, não bloqueante).

---

## Panorama atual

| Campo | Valor real |
|---|---|
| **Etapa atual** | **Etapa 9 — Dashboard + Central de Alertas ✅ COMPLETA.** Etapas 0–9 concluídas. Resta só preparo de deploy (não bloqueante) |
| **Tarefa atual** | Nenhuma em execução (handoff controlado) |
| **Último checkpoint estável** | `3cd426a` — **GLOBAL verde 1679/1679**; `tests/Cobranca` 398/398 |
| **Branch** | `gestao-cobrancas` (dedicada; `master` só com DJEN) |
| **HEAD** | `3cd426a` (impl. Etapa 9; spec em `c80b4e5`) — confirmar com `git log` |
| **Working tree** | limpo (só untracked `.claude/worktrees/` — worktrees de agente, NÃO commitar; + os `.xlsx` reais TOPLIFE gitignorados) |
| **Migrations (dev+test)** | E1..E6 (ver histórico); **E7 `Version20260710130000`** (índices funcionais dedup dígitos cpf/cnpj) **+ `Version20260710160000`** (índice PARCIAL ÚNICO de idempotência da importação: obrigacao(caso_id, referencia_externa) WHERE NOT NULL) |
| **Escritor** | ÚNICO (orquestrador). Etapa 7 sem fan-out — fluxo coeso e acoplado |

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
- ✅ **Etapa 7** — Importação em massa: **CONCLUÍDA**. Ponto seguro (dedup dígitos) + adapter real TOPLIFE + importador idempotente. Ver `docs/specs/cobranca-etapa7-importacao.md` e `docs/gestao-cobrancas/MAPEAMENTO_FONTE_TOPLIFE.md`.
- ✅ **Etapa 8** Telas/UX — **8A (LEITURA) ✅**; **8B (mutações/forms) ✅**; **8C (importação visual + file-manager de documentos) ✅ COMPLETA**.
- ✅ **Etapa 9** Dashboard + Central de Alertas — **COMPLETA** (ver seção abaixo).

### Etapa 9 — o que foi entregue (commits `c80b4e5` spec + `3cd426a` impl.)
Camada visual final de LEITURA, visão do ESCRITÓRIO (não per-caso). Spec `docs/specs/cobranca-etapa9-dashboard-alertas.md` (risco MÉDIO). Escritor ÚNICO (agregação de leitura coesa). **Sem migration, sem escrita.**
- **Repo**: `CasoCobrancaRepository::doTenant(Tenant, ?Carteira)` — todos os casos do tenant (opcional por carteira), tenant sempre explícito, inclui encerrados; **NÃO** soma saldo em SQL (derivação continua nos serviços).
- **UseCases** (query, read-only): `MontarDashboardCobrancaUseCase` (financeiro: saldo aberto/vencido, recuperado no período, honorários projetados/realizados por forma §18; operacional: pagamentos-a-verificar=ObrigacaoVencida, ações atrasadas, parcelas vencidas, revisões, judicializados; resultado: total recuperado, em aberto, taxa em basis points, objetos inadimplentes vs casos ativos). `MontarCentralAlertasUseCase` (reusa `AlertasCobranca::alertasDoCaso`, agrupa casos-com-alerta por carteira + resumo global por tipo na ordem do enum).
- **DTOs**: `DashboardCobrancaOutput`, `CentralAlertasOutput`, `CarteiraAlertasOutput`, `CasoComAlertasOutput` (readonly; sem entidade crua no Twig).
- **Controller**: `DashboardController` fino — `GET /cobrancas/painel` (`cobranca_dashboard`) + `GET /cobrancas/alertas` (`cobranca_alertas`); gate `tenantComModulo`; filtro de carteira por `findOneByIdDoTenant`→404 (anti-IDOR); período defensivo (mês corrente default). Sem escrita → sem CSRF.
- **Templates**: `dashboard/index` (3 seções de cards `.caso-metric`/`.dash-card`, barra de taxa, empty state) + `alertas/index` (chips por tipo, grupos por carteira, empty state); `_subnav` ganhou **Painel** e **Alertas**; CSS `cobrancas.css` (tiles/chips tema-aware). Menu lateral inalterado (landing segue `cobranca_carteira_index`).
- **Decisões documentadas na spec §5**: "pagamentos a verificar"=alerta ObrigacaoVencida; honorários realizados por forma (acrescido usa `valorHonorarios` do pagamento; retido/cobrado usa `realizadosSobreRecuperacao`; sem_percentual 0); liquidação recupera sem gerar honorário; encerrado só no recuperado (período + all-time), fora do aberto/operacional; taxa = recuperado/(recuperado+em aberto), aritmética inteira.
- **Testes**: 25 novos — `MontarDashboardCobrancaUseCaseTest` (10, DB real: financeiro/resultado/honorários por forma/encerrado/tenant/carteira/objetos-vs-casos/empty), `MontarCentralAlertasUseCaseTest` (4: agrupamento/resumo/encerrado/tenant/filtro), `DashboardControllerTest` (11: auth/módulo/render/empty/IDOR 404/não-vazamento HTTP central+painel/operador-com-módulo). `tests/Cobranca` 398/398; GLOBAL 1679/1679.
- **Segurança**: revisão adversarial read-only + tenant-safety scan — **SEM BLOQUEANTE** (achados MENOR/NIT tratados: card judicializadas virou link N3; asserção HTTP de não-vazamento do painel N2; notas de perf/ambiguidade na spec M1/N1/M2).

### Etapa 8 Onda 8C — o que foi entregue (commits `b0c73bd` importação + `d0fb161` documentos, sobre `54a0ab1` spec)
Camada visual restante. Spec `docs/specs/cobranca-etapa8c-importacao-documentos.md`. Escritor ÚNICO (front compartilhado força single-writer).
- **8C-A Importação visual** (`b0c73bd`): `ImportacaoController` (fluxo `upload → prever(dry-run) → confirmar`) sobre `ImportarRelatorioCarteiraUseCase` (E7, idempotente). Arquivo temporário ISOLADO POR TENANT (`import-tmp/<tenantId>/<token>.xlsx`, token do servidor → sem path traversal), ponteiro na SESSÃO (por-usuário → sem IDOR). Gate `resources.cobranca.gerenciar` + carteira por `findOneByIdDoTenant`→404 + CSRF no confirmar. `ImportarRelatorioInput`/`ImportarRelatorioType` (`#[Assert\File]` xlsx). Templates `importacao/{upload,preview}` + botão na carteira. **Decisão E7 intocada:** linha só-encargos REJEITADA com motivo. Testes: fluxo (preview não persiste + confirmar persiste 6), gate, IDOR 404, CSRF, arquivo inválido (5).
- **8C-B Documentos file-manager** (`d0fb161`): reusa `public/js/pasta-arquivos.js` SEM editá-lo — partial `caso/_documentos.html.twig` serve o MESMO contrato `data-*`/JSON via `DocumentoCobrancaController` (9 rotas: upload/criar-renomear-excluir seção/mover/excluir/download + reordenar docs/seções). Gate `resources.cobranca.gerenciar` (falha AJAX → JSON 403), `findOneByIdDoTenant`→404 anti-IDOR antes do CSRF, CSRF nomeado por ação (stateful). Reusa UseCases E6 + 2 UseCases NOVOS `Reordenar{Documentos,Secoes}CasoUseCase` (escopados caso+tenant, flush único via `salvar`). Categoria do FM (`DEMAIS`) → `Outro`. Leitor puro (sem `gerenciar`) vê lista read-only com download, sem `#fileManager`. Documento vive no Caso sem Pasta (INV-25). `CasoController::show` monta `secoes`/`arquivosFm` (arrays, sem entidade crua); assets `pasta-arquivos.css`+SortableJS+`pasta-arquivos.js`+helper `enviarArquivoComProgresso`; `id="documentos-tab"`/`id="pastaTabs"` para o restore de aba do JS. Testes: 10 funcionais + 10 unit dos reorder.
- **Segurança:** tenant-safety verificado (gates + `findOneByIdDoTenant` em TODA mutação, sem `find()` puro, guards de tenant nos reorder, CSRF por ação, sem entidade crua no Twig) + 2 revisões adversariais read-only (importação | documentos).
- ⬜ **Etapa 9** Alertas UI + Dashboard.

### Etapa 8 Onda 8B — o que foi entregue (commits `29e7a8e`→`30e4cf4`, sobre 8A `b0d2786`)
Camada HTTP de **ESCRITA** (mutações). Spec `docs/specs/cobranca-etapa8b-mutacoes.md`.
- **8B-0 Fundação** (`29e7a8e`): trait `AutorizacaoCobranca` (gate módulo + capacidade via `hasPermission`), `CentavosType`+`CentavosParaReaisTransformer` (int centavos ↔ reais pt-BR, aritmética inteira, unit-testado), refactor dos controllers 8A p/ o trait, fix de flake latente do teste 8A (HTML escapado).
- **8B-A** (`4973dac`): registrar obrigação · reconhecer valor (modal reutilizável) · encerrar caso. `ObrigacaoController` + `CasoController::encerrar`. Base de teste `CobrancaWebTestCase`.
- **8B-B** (`c190d04`): definir/concluir próxima ação (`AcaoCobrancaController`) · registrar tentativa (`CasoController`) · gerar/resolver revisão (`RevisaoCobrancaController`).
- **8B-C** (`e718a4d`): criar acordo (multiselect de obrigações escopado + coleção de parcelas) · romper/cancelar (modais reutilizáveis) · cumprir (CSRF manual). `AcordoController`. Flag `AcordoOutput.ativo`.
- **Correções da revisão** (`30e4cf4`, SEM bloqueantes): `formulariosDeMutacao` gated por capacidade; matriz de teste de cancelar acordo fechada.
- **Segurança (revisão adversarial: tenant/IDOR/ordem gate→CSRF→mutação/dinheiro VERIFICADOS OK).** Padrão: gate capacidade → `findOneByIdDoTenant`→404 → CSRF → UseCase → PRG. Controllers finos; UseCases flusham. 46 testes funcionais de mutação + 15 unit do transformer.

### Etapa 8 Onda 8B — 2ª metade (commits `936408a`→`642a9ef`, sobre `ba667b4`) — CONCLUSÃO DA ONDA
- **Guard de caso encerrado no servidor** (`936408a`): `ReconhecerValorAtualizado`/`RegistrarTentativa`/`GerarRevisao` lançam `CasoEncerradoException` num caso encerrado (fecha a assimetria — antes só a UI barrava). Decisão de negócio do humano. Testes unit + `CasoEncerradoBloqueiaMutacaoControllerTest` (provam o não-efeito).
- **8B-D financeiro** (`b9ead49` + correções `e593978`): `PagamentoController` (registrar/corrigir), `LiquidacaoController` (registrar). Capacidade SEPARADA `resources.cobranca.movimentacao_financeira`. `CentavosType` + coleção `AlocacaoPagamentoType`; modais em `_acoes_modais_financeiro.html.twig` (gate próprio). `CapacidadeSeparacaoControllerTest` prova gerenciar×financeiro independentes.
- **Judicializar** (`9a4908a` + `642a9ef`): `CasoController::judicializar`, gate `gerenciar` + módulo `pastas` (no controller), `PastaRepository::opcoesDoTenant`. Teste de negação do gate `pastas`.
- **8B-E cadastro** (`eedbb05` + fix `e4c5c71`, fan-out `feature-implementer`→cherry-pick): `CarteiraController` escrita (criar/configurar/objeto/abrir-caso + leitura objetos/vínculos em arrays), `PessoaController` (criar/vincular/encerrar). 7 forms. `Pessoa/ClienteRepository::opcoesDoTenant`. Templates `carteira/{index,show,_acoes_modais}`.
- **Alterar pessoa cobrada** (`0353470`): `CasoController::alterarPessoaCobrada`, select escopado ao tenant, motivo obrigatório.
- **Segurança:** 2 revisões adversariais read-only (frente CASO e frente CARTEIRA/judicializar/alterar-pessoa) + tenant-safety scan — **SEM bloqueantes**. Multi-tenant/IDOR com defesa dupla (ChoiceType escopado + revalidação por tenant no UseCase). `criarOperadorComCapacidades` prova gates granulares. `tests/Cobranca` 343/343; GLOBAL 1624/1624.
- ⬜ **Onda 8C** Importação visual + file-manager de documentos. ⬜ **Etapa 9** Alertas UI + Dashboard.

### Etapa 8 Onda 8A — o que foi entregue (commit `3e20b3e`, sobre `d2101a7`)
Camada HTTP de **LEITURA** (só GET; mutação = 8B). Spec `docs/specs/cobranca-etapa8-telas-ux.md`.
- **Fundação:** `badgeClass()` nos enums de estado (+`icone()` em `TipoAlerta`); filtro Twig `|centavos` (`CobrancaExtension`); métodos de listagem tenant-scoped (`findByFilters`/`countByFilters`/`opcoesFacetaDoTenant`/`daCarteira`/`contarDaCarteira`/`doCaso`); 11 Output DTOs de leitura; 4 UseCases de leitura (`ListarCarteiras`/`ListarCasos`/`MontarVisaoCarteira`/`MontarDetalheCaso`).
- **4 rotas `cobranca_*`:** `carteira_index` (landing, filtro XHR) · `carteira_show` (visão da carteira: config + agregados + saldo consolidado + casos) · `caso_index` (filtro XHR) · `caso_show` (**detalhe central**: cabeçalho saldo/estado/pessoa/próxima ação/alertas + abas Obrigações/Pagamentos&Liquidações/Acordos/Documentos[placeholder 8C]/Histórico[timeline]).
- **Menu** gated `can_access_module('cobrancas')` + `pageTitle`; **UX** `cobrancas.css` tema-aware, badges, realce vencido, tooltips, sub-nav, empty states, cards mobile.
- **Segurança:** gate módulo nas 4 rotas; `findOneByIdDoTenant`→404 (IDOR); toda listagem com `WHERE tenant` explícito; GET-only (CSRF em 8B). Tenant-safety LIMPO.
- **Testes:** `CobrancaTelasControllerTest` (14) — auth/módulo, render, XHR fragmento, IDOR 404, não-vazamento, facetas/ordenação/paginação. Revisão adversarial SEM bloqueante (#2 tratado; #1/#3 non-issue verificados).
- **Autorização (decisão SPEC §22):** módulo em tudo; capacidades `resources.cobranca.*` (pré-registradas) entram nas mutações da 8B via `hasPermission` (capacidade de papel, não per-item ACL).

### Etapa 7 — o que foi entregue
**Parte 1 — ponto seguro (independe da fonte, invariável §23.24):** dedup de Pessoa por **dígitos** de CPF/CNPJ (`NormalizadorDocumento`, `SugerirPessoasDuplicadas`, `PessoaRepository::buscarPossiveisDuplicadas` com `regexp_replace`, índices funcionais `Version20260710130000`). Fecha o núcleo do follow-up #3.

**Parte 2 — importador real TOPLIFE (fonte real fornecida pelo humano):**
- **Fonte:** relatórios "Inadimplência detalhada" da contábil L.G (condomínios TOPLIFE I/II). `.xlsx` reais têm PII → **gitignorados** (`docs/gestao-cobrancas/*.xlsx`). Análise + mapeamento fonte→domínio + decisões A–E do humano em `MAPEAMENTO_FONTE_TOPLIFE.md`.
- **Adapter** `TopLifeInadimplenciaAdapter` (fonte-específico, §24 sem universal): lê o layout, agrupa por **(Objeto, NN)** em `BoletoImportavel` (principal Taxa+Energia−Descontos; encargos Juros+Multa; honorários informados sem dupla contagem — só preview); classifica ignorados (rodapé) e rejeitados com motivo. VOs `BoletoImportavel`/`LinhaRejeitada`/`ResultadoLeitura`/`ResultadoImportacao`. Fixture anonimizada `app/tests/Fixtures/Cobranca/importacao/toplife_amostra.xlsx`.
- **UseCase** `ImportarRelatorioCarteiraUseCase` (`prever` dry-run honesto + `confirmar` transacional): dentro de UMA Carteira; reusa `CriarObjeto`/`CriarPessoa`/`Vincular`/`AbrirCaso`/`RegistrarObrigacao`; **idempotente** (dedup Objeto por identificação, Obrigação por refExterna=NN, Pessoa por nome normalizado no Objeto); reimport atualiza SÓ encargos (preserva `valorOriginal`, invariável 20); honorários NÃO persistidos (derivados, §18/§19); acordo só como observação (decisão E); NUNCA cruza tenant.
- **Migration** `Version20260710160000`: índice PARCIAL ÚNICO de idempotência (aviso de drift embutido).
- **Testes:** adapter (7), NormalizadorNome (5), funcional DB (7: import, reimport idempotente, **reimport de sacado divergente sem acúmulo**, preview honesto sem persistir, cross-tenant, 2 tenants isolados, índice único barra duplicata). Global **1501/1501**.
- **Revisão adversarial:** 1 bloqueante (B1: reimport divergente duplicava Pessoa) **CORRIGIDO** + NB1 (agrupar por Objeto+NN), NB2 (preview honesto), NB4 (teste do índice), NB5 (reimport só encargos) tratados; NB3/NB6/NB7/NB8 avaliados e aceitos/diferidos (NB7 gate HTTP = Etapa 8).

### Transversal / deploy (fim)
- ⬜ Data-migration de permissões `cobrancas` p/ **produção** (dev/test já via fixture).
- ⬜ Deploy via `deploy-prod-tls.sh` (rebuild) — só no fim.
- 🔁 **Regra viva:** toda tabela `tenant_id` nova → adicionar à `PurgarEscritorioUseCase::ORDEM_DELECAO` (senão `PurgaCoberturaSchemaTest` falha) + seed no teste da purga.

---

## Follow-ups registrados (não bloqueiam)
1. **Endurecer testes de evento (MENOR, do review da Etapa 2):** 4 dos 5 testes unit de UseCase asseram só `salvar(isInstanceOf(EventoHistorico), true)` — não checam `tipo`+`dados` do evento (o log de domínio §13). Produção está correta (reviewer confirmou); é lacuna de rede de segurança. Arquivos: `AbrirCasoUseCaseTest`, `RegistrarObrigacaoUseCaseTest`, `ReconhecerValorAtualizadoUseCaseTest`, `AlterarPessoaCobradaUseCaseTest`. Fix: capturar o evento no mock e asserir `getTipo()` + chaves de `getDados()`.
2. **Cobertura de borda (MENOR):** `encargosReconhecidos = 0` (zera reconhecimento) e o fallback de `descricao` do `RegistrarTentativaCobranca` (quando `observacao` vazia) sem teste dedicado.
3. **✅ RESOLVIDO (Etapa 7):** dedup de Pessoa por **dígitos** de CPF/CNPJ, intra-tenant, advisory — `NormalizadorDocumento` + `buscarPossiveisDuplicadas` por `regexp_replace` + índices funcionais (`Version20260710130000`). Resíduo cosmético: índices planos `idx_cobranca_pessoa_tenant_cpf`/`_cnpj` ficaram redundantes (não removidos p/ evitar drift reverso).
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
> **Etapas 0–9 CONCLUÍDAS. A implementação da feature está COMPLETA.** HEAD `3cd426a`; `tests/Cobranca` 398/398, GLOBAL 1679/1679; tenant-safety + revisão adversarial SEM bloqueante em todas as etapas.
> **Checklist final de merge/deploy: `docs/gestao-cobrancas/RELEASE_CHECKLIST.md`** (bloqueador nº1 = data-migration de permissões p/ prod).
> **✅ PERF CONCLUÍDA (2026-07-11): `docs/gestao-cobrancas/PLANO_OTIMIZACAO_QUERIES.md`** — N+1 de saldo/hidratação eliminado em TODAS as telas de Cobrança. Fases P0–P4 commitadas (`091315c`/`153fc24`/`991f7ac`/`7909d96`/`51b23c2`), revisadas SEM bloqueante. Medições (dev, tenant 1): Central de Alertas **1592→44**, Visão da Carteira **876→221†**, Lista de Casos **199→41**, Lista de Carteiras 40→38, Detalhe 96→92; Dashboard 42→44 (bounded). `tests/Cobranca` 409/409; global 1690/1690. **† Único resíduo = N+1 de AUTORIZAÇÃO `user_tenant`** (`PermissionChecker`/`TenantContext` re-consultam `UserTenant` por chamada), PRÉ-EXISTENTE/transversal/MÉDIO risco — follow-up SEPARADO no PLANO §1.1 (decisão do humano; fora do escopo de Cobrança).
> **Resta apenas o PREPARO DE DEPLOY/HOMOLOGAÇÃO (não bloqueia a feature; decisão do humano):**
> 1. **Data-migration de permissões `cobrancas`/`resources.cobranca.*` para PRODUÇÃO** (dev/test já via fixture). É o único item que falta para prod poder usar o módulo.
> 2. **Semear um grafo realista no dev** e validar manualmente no navegador: drag/upload XHR de documentos (8C-B), fluxo visual de importação (8C-A), file-manager, e as novas telas Painel/Alertas (9). Dev não tem dados de Cobrança (módulo novo, ausente do dump de prod). Os testes funcionais já renderizam os templates reais no container (smoke de renderização OK).
> 3. **Deploy** via `deploy-prod-tls.sh` (rebuild) — só no fim; **nenhuma migration nova nas Etapas 8–9** (só a data-migration de permissões do item 1).
> 4. **Integração da branch**: `gestao-cobrancas` carrega o DJEN `b044c0c` na base (inofensivo) + caronas (metas/Datajud). Mergear no master DEPOIS do DJEN — decisão do humano.
>
> **Follow-ups técnicos restantes** (não bloqueiam; ver SESSION_HANDOFF §Follow-ups): perf O(casos) do dashboard (constante ~6–8 queries/caso — agregado materializado se escalar); coletor de temporários órfãos de importação; FIFO de alocação (#8); NITs teóricos aceitos.
>
> Atenção permanente: §24 — nunca importador universal; §21 — importação dentro de uma Carteira explícita; dedup só intra-tenant; honorários derivados (§18/§19); **decisão da E7 sobre linhas só-encargos permanece intacta**; saldo/alertas SEMPRE derivados por serviço, nunca coluna/SQL.
