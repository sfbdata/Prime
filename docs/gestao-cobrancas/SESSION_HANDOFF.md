# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-09 — **Etapa 5 CONCLUÍDA e validada** (suíte global 1441/1441; `tests/Cobranca` 160/160).

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada; `master` só com DJEN).
- **HEAD:** `8cbd937` (código estável) — +1 commit de docs a seguir. Ancestralidade Etapa 5 sobre `fc48708`: `db7e86f`(andaime)→`d9d06bf`(CalculadoraSaldo não-final)→`2dbfa80`(fan-out A)→`52dcb61`(fan-out B)→`faa8530`(fan-out C)→`672d314`(integração)→`8cbd937`(cobertura IDOR).
- **Etapa:** 5 (Estados/Judicialização/Encerramento/ProximaAcao/Revisão/Alertas) → **✅ CONCLUÍDA**. Próxima = **Etapa 6** (Documentos do Caso).
- **Suíte:** GLOBAL **1441/1441**; `tests/Cobranca` 160/160.
- **Working tree:** limpo (só untracked `.claude/worktrees/` — worktrees de agente, NÃO commitar; limpeza é do humano, follow-up #6).
- **Escritor:** ÚNICO (orquestrador) + fan-out de 3 `feature-implementer` em worktrees (concluído/integrado). Se abrir nova sessão, reconfirmar escritor único antes de reabrir escrita.
- **Migrations (dev+test):** E1 `Version20260708210509`+`Version20260708220000`; E2 `Version20260709123952`; E3 `Version20260709142845`; E4 `Version20260709154458`; **E5 `Version20260709191327`** (cobranca_proxima_acao + cobranca_revisao_pessoa_cobrada + ALTER cobranca_caso add pasta_judicial_id).

## O que foi concluído nesta sessão (Etapa 5)
Pipeline autônomo completo: `andaime→commit→fan-out (3 worktrees)→review read-only (3)→cherry-pick individual→teste direcionado→correções→cross-tenant DB→suíte global→tenant-safety→docs`.
- **Andaime** `db7e86f` (+ `d9d06bf`): entidades `ProximaAcao`/`RevisaoPessoaCobrada` + enums `StatusProximaAcao`/`StatusRevisao`; FK `CasoCobranca.pastaJudicial` (unidirecional, SET NULL); repos com queries cross-cluster (`findAtivaDoCaso`, `existePendenteDoCaso`); 7 exceptions; migration; 2 factories; purga (ORDEM_DELECAO + seed); spec `docs/specs/cobranca-etapa5-estados-judicializacao-alertas.md`; `CalculadoraSaldo` não-final.
- **Fan-out A** `2dbfa80` (de `93e81ac`): `JudicializarCaso` (não encerra) + `EncerrarCaso` (manual, saldo 0).
- **Fan-out B** `52dcb61` (de `7229f9e`): `DefinirProximaAcao` (máx. 1 ativa) + `ConcluirAcao`.
- **Fan-out C** `faa8530` (de `bcb414b`): `GerarRevisao`/`ResolverRevisao` + `AlertasCobranca` (5 alertas) + `TipoAlerta`/`AlertaCobranca`.
- **Integração** `672d314`: `JudicializacaoCobrancaIsolamentoTenantTest` (DB real) + fix do review (ConcluirAcao rejeita próxima em caso encerrado, §17).
- **Reforço IDOR** `8cbd937`: cobertura cross-tenant dos demais UseCases (fecha MENOR da tenant-safety).

## Decisões de design (Etapa 5)
- **"Pronto para encerrar" = indicador DERIVADO** (`status !== encerrado E saldoExigivel === 0`), NÃO 4º estado. Vive em `AlertasCobranca` (`TipoAlerta::ProntoParaEncerrar`).
- **Vínculo com Pasta UNIDIRECIONAL** (`Caso → Pasta`, FK `pasta_judicial_id` SET NULL). `PastaController` intocado. Guard same-tenant no UseCase (Pasta resolvida por id+tenant) — provado por DB.
- **Judicialização ≠ encerramento** (16); **encerramento manual + saldo 0** (17), de ativo ou judicializado.
- **Revisão persistida** (§8): `existePendenteDoCaso` filtra por status → alerta cessa após resolver (provado ponta-a-ponta no DB). Demais alertas são derivados por query.
- **Alertas read-only** (28); caso encerrado → `[]` (short-circuit, aceito). **Próxima ação não grava histórico** (§13).
- **Permissão `pastas` do controller → Etapa 8** (não há camada HTTP nesta etapa; só o isolamento de tenant está no UseCase).

## Git
- **HEAD:** `8cbd937` (+ docs). **`master`:** NÃO contém Cobranças. Mergear DEPOIS do DJEN, só no fim.
- **Worktrees de agente (limpeza do humano):** E5 `agent-a953d338bf5fe2f30`(A)/`agent-a2ef463696ee59d2c`(B)/`agent-a55b6609e6d9f8247`(C) + branches `worktree-agent-*`; somam-se às sobras das etapas 2/3/4.

## Testes (comandos úteis) — **sempre `php -d memory_limit=512M`**
- `php bin/phpunit tests/Cobranca` → 160/160.
- `php bin/phpunit --filter "JudicializacaoCobrancaIsolamentoTenantTest"` → cross-tenant DB da Etapa 5 (8 testes).
- `php bin/phpunit --filter "JudicializarCasoUseCaseTest|EncerrarCasoUseCaseTest|DefinirProximaAcaoUseCaseTest|ConcluirAcaoUseCaseTest|GerarRevisaoUseCaseTest|ResolverRevisaoUseCaseTest|AlertasCobrancaTest"` → unit da Etapa 5.
- `php bin/phpunit` (global) → 1441/1441.
- **Se testes de DB falharem com erro de conexão:** confira `docker ps` — o container `jusprime_db_dev` pode ter parado (`docker start jusprime_db_dev`).

## Follow-ups (não bloqueiam — detalhe no EXECUTION_STATUS §Follow-ups)
- **#12 ✅ RESOLVIDO** (alerta de parcela de acordo vencida na Etapa 5). **Sem decisões de negócio pendentes.**
- **#13** Índice único de banco para "máx. 1 ação pendente" (hoje só o check no UseCase; janela TOCTOU) — decisão do humano.
- **#14** Short-circuit de alertas em caso encerrado oculta revisão pendente sobrevivente — coerente com §14, documentado; confirmar intencional (é).
- **#15** Gate `can_access_module('pastas')` na judicialização entra na Etapa 8 (camada HTTP).
- **#10/#11** Endurecer testes de evento (asserir `tipo`+`dados`) — segue aberto das etapas 3/4.

## Próxima ação exata
> **Etapa 6 — Documentos do Caso de Cobrança** (PLAN §8; paralelização Baixa, 1–2 agentes). Ordem: (1) confirmar Git + escritor único; (2) investigar `App\Shared\Service\ArquivoStorageService`/`CompressorArquivo` + file manager `pasta-arquivos.js/.css` (reusar, NÃO duplicar — §15/§24, invariável 25); (3) andaime committado — entidades `CobrancaDocumento`/`CobrancaSecao` (TenantAware+Auditavel, FK `caso` nn), parâmetro `cobrancas_uploads_dir` (test → `var/uploads-test/cobrancas`) + bind, migration (dev+test via `migrations:execute --up`), factories, **purga+seed**; (4) fan-out (documentos × seções, se valer) ou sequencial; UseCases `EnviarDocumento`/`MoverDocumento`/`ExcluirDocumento`/`CriarSecao`/`RenomearSecao`/`ExcluirSecao`; (5) testes: documento existe SEM Pasta (invariável 25); ao judicializar documentos permanecem no Caso (não migram/duplicam); guard IDOR+tenant; isolamento por tenant no disco (subpasta por tenant, padrão M5); suíte global + tenant-safety + commit + docs.

> **⚠️ Atenção da Etapa 6:** é a primeira etapa a tocar **disco/uploads**. Subpasta por tenant (isolamento físico, como M5). Reusar a infra de arquivos existente (contrato `data-*` do front) sem duplicar Pasta/Processo/Documento (§24). NÃO tocar `PastaController`.

## Ordem de retomada
1. Confirmar branch `gestao-cobrancas`, HEAD `8cbd937` (ou posterior), working tree limpo, escritor único.
2. Ler `PLAN.md` §8 Etapa 6 + `PARALLELIZATION_MAP.md` §1 + investigar `ArquivoStorageService`/file manager + como `PastaDocumento`/`PastaSecao` mapeiam.
3. Andaime da Etapa 6 (CobrancaDocumento/CobrancaSecao + `cobrancas_uploads_dir` + migration + factories + purga) → commit → fan-out/sequencial → integração → validação.
4. Atualizar `EXECUTION_STATUS.md` + este arquivo ao fim.
