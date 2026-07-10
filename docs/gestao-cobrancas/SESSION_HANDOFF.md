# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-10 — **Etapa 6 CONCLUÍDA e validada** (suíte global 1472/1472; `tests/Cobranca` 191/191).

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada; `master` só com DJEN).
- **HEAD:** `2ab1783`. Ancestralidade Etapa 6 sobre `102601d` (docs E5): `ea3a21f`(andaime)→`b45c341`(exception faltante)→`c9560d0`(fan-out B Seções)→`09659e7`(fan-out A Documentos)→`2ab1783`(integração cross-tenant DB). +1 commit de docs a seguir.
- **Etapa:** 6 (Documentos do Caso) → **✅ CONCLUÍDA**. Próxima = **Etapa 7** (Importação em massa).
- **Suíte:** GLOBAL **1472/1472**; `tests/Cobranca` 191/191.
- **Working tree:** limpo (só untracked `.claude/worktrees/` — worktrees de agente, NÃO commitar; limpeza é do humano, follow-up #6).
- **Escritor:** ÚNICO (orquestrador) + fan-out de 2 `feature-implementer` em worktrees (concluído/integrado). Se abrir nova sessão, reconfirmar escritor único antes de reabrir escrita.
- **Migrations (dev+test):** E1 `Version20260708210509`+`Version20260708220000`; E2 `Version20260709123952`; E3 `Version20260709142845`; E4 `Version20260709154458`; E5 `Version20260709191327`; **E6 `Version20260709215805`** (cobranca_secao + cobranca_documento; FKs caso_id/secao_id ON DELETE CASCADE; tenant NO ACTION).

## O que foi concluído nesta sessão (Etapa 6)
Pipeline autônomo: `investigação infra de arquivos→spec→andaime→commit→fan-out (2 worktrees)→review read-only (2)→cherry-pick individual→teste direcionado→cross-tenant DB→suíte global→tenant-safety→docs`.
- **Andaime** `ea3a21f` (+ `b45c341` exception que escapou do staging): entidades `CobrancaSecao`/`CobrancaDocumento` (FK `caso` nn, **unidirecional** — não toca `CasoCobranca`; navegação pelos repos), enum `CategoriaDocumentoCobranca`, 2 repos tenant-safe (`findOneByIdDoTenant`/`documentosDoCaso`/`secoesDoCaso`/`proximaOrdem`), 4 exceptions, 2 factories; parâmetro `cobrancas_uploads_dir` (test→`var/uploads-test/cobrancas`) + bind; purga cobre `cobranca_documento`/`cobranca_secao` na ORDEM_DELECAO + remove `cobrancas/<tenantId>/`; migration; spec `docs/specs/cobranca-etapa6-documentos.md`.
- **Fan-out B** `c9560d0`: `CriarSecao`/`RenomearSecao`/`ExcluirSecao` (excluir seção exclui seus documentos, espelha ExcluirPastaSecao; apaga arquivo físico ANTES do remove/cascade).
- **Fan-out A** `09659e7`: `EnviarDocumento` (whitelist MIME + limite tamanho, isolamento físico por tenant, compressão opcional) / `MoverDocumento` / `ExcluirDocumento`.
- **Integração** `2ab1783`: `DocumentosCobrancaIsolamentoTenantTest` (DB+disco reais): INV-25 (doc sem Pasta), judicialização preserva documentos (não migra/duplica), isolamento físico por tenant, IDOR de Enviar/Mover/ExcluirSecao + seção de outro caso.

## Decisões de design (Etapa 6)
- **Documento vive no Caso, NUNCA na Pasta** (invariável 25): FK `caso` obrigatória; caso sem Pasta pode ter documentos. Ao judicializar, documentos **permanecem** (não migram/duplicam, §15/§16) — provado no DB.
- **Entidades próprias** (`CobrancaDocumento`/`CobrancaSecao`) só porque a FK aponta para `CasoCobranca` (§24: não recriar Pasta/Documento). Mecânica de arquivo 100% reusada do `App\Shared\Service` (`ArquivoStorageService`/`CompressorArquivo`). Front `pasta-arquivos.js/.css` será religado por `data-*` na Etapa 8 (sem tocar o JS).
- **FK Caso→Documento/Seção UNIDIRECIONAL** (sem `inversedBy`): `CasoCobranca` (entidade compartilhada da E2) fica **intocada**; navegação pelos repositórios. `Secao→Documento` bidirecional (coleção `documentos`, cascade remove).
- **Isolamento físico por tenant** (padrão M5): arquivo em `cobrancas/<tenantId>/<hash>`; `caminhoArquivo` guarda só o hash; diretório efetivo = `$cobrancasUploadsDir.'/'.$tenant->getId()` (contrato congelado, idêntico no salvar/excluir/purga).
- **ExcluirSecao exclui os documentos** da seção (decisão de negócio; espelha Pasta). Apaga arquivo físico ANTES do `remover(flush:true)` (senão o cascade derruba as linhas e perde o hash) — sem transação disco+DB (aceito, follow-up #16).
- **Categoria = enum** `CategoriaDocumentoCobranca` (TermoAcordo/Boleto/Comprovante/Notificacao/Negociacao/Outro; default Outro).
- **Sem camada HTTP nesta etapa:** controllers/rotas/templates/CSRF + gate `can_access_module('cobrancas')` + wiring do file manager → **Etapa 8**.

## Git
- **HEAD:** `2ab1783` (+ docs). **`master`:** NÃO contém Cobranças. Mergear DEPOIS do DJEN, só no fim.
- **Worktrees de agente (limpeza do humano):** E6 `agent-a883d877c2c86a236`(A)/`agent-a8473a2a7bf7a9edb`(B) + branches `worktree-agent-*`; somam-se às sobras das etapas 2/3/4/5.

## Testes (comandos úteis) — **sempre `php -d memory_limit=512M`**
- `php bin/phpunit tests/Cobranca` → 191/191.
- `php bin/phpunit --filter "DocumentosCobrancaIsolamentoTenantTest"` → cross-tenant DB+disco da Etapa 6 (6 testes).
- `php bin/phpunit --filter "Documento|Secao" tests/Cobranca/Unit` → unit dos 6 UseCases (27 testes).
- `php bin/phpunit --filter "PurgaCoberturaSchemaTest|PurgarEscritorioUseCaseTest"` → cobertura da purga (10 testes).
- `php bin/phpunit` (global) → 1472/1472.
- **Se testes de DB falharem com erro de conexão:** confira `docker ps` — `jusprime_db_dev` pode ter parado (`docker start jusprime_db_dev`).

## Follow-ups (não bloqueiam — detalhe no EXECUTION_STATUS §Follow-ups)
- **#13** Índice único "máx. 1 próxima ação pendente" (janela TOCTOU) — decisão do humano (E5).
- **#14** Short-circuit de alertas em caso encerrado oculta revisão pendente — coerente/documentado (E5).
- **#15** Gate `can_access_module('pastas'/'cobrancas')` na camada HTTP → Etapa 8.
- **#16 (NOVO, E6)** `ExcluirDocumento`/`ExcluirSecao` apagam o arquivo físico ANTES de remover a linha; se o `flush` falhar depois, o arquivo some mas a linha permanece (sem transação disco+DB). Aceito por design (não perder o hash). Endurecer só se virar problema real.
- **#17 (NOVO, E6)** Cobertura de borda: branch `descricao === ''` → null no `EnviarDocumento` sem teste dedicado (mutação passaria). MENOR.
- **#10/#11** Endurecer testes de evento (asserir `tipo`+`dados`) — segue aberto das etapas 3/4.

## Próxima ação exata
> **Etapa 7 — Importação em massa** (PLAN §9; §21 da SPEC). Ler `PLAN.md` §9 + `PARALLELIZATION_MAP.md` + SPEC §21. Escopo: importar carteiras/objetos/pessoas/casos/obrigações em lote (planilha), com dedup por dígitos de CPF/CNPJ **dentro do tenant** (follow-up #3: precisa índice funcional). Riscos: validação de linha, idempotência, mapeamento de colunas, performance. NÃO é importador universal (§24). Ordem: (1) confirmar Git + escritor único; (2) investigar como outras importações existem no projeto (se houver) + o parser de planilha disponível; (3) andaime (entidade de job de importação? ou stateless?) → decidir com storytelling do UseCase; (4) fan-out se útil.

## Ordem de retomada
1. Confirmar branch `gestao-cobrancas`, HEAD `2ab1783` (ou posterior), working tree limpo, escritor único.
2. `git log --oneline -6` (topo esperado: docs E6 a seguir / `2ab1783`).
3. `php bin/phpunit tests/Cobranca` deve dar 191/191 antes de começar a E7.
4. Ler `PLAN.md` §9 (Etapa 7) + SPEC §21 + investigar infra de importação/planilha.
5. Ao fim da E7, atualizar `EXECUTION_STATUS.md` + este arquivo.
