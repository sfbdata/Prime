# SESSION_HANDOFF — Gestão de Cobranças

> Memória para o PRÓXIMO chat. **Reescrito ao fim de cada sessão.** Vale mais que qualquer resumo de conversa. Sempre reconferir contra o Git antes de agir.
> Sessão encerrada em: 2026-07-10 — **Etapa 7: ponto seguro entregue; importador REAL bloqueado por falta de fonte**.

---

## Estado atual
- **Branch:** `gestao-cobrancas` (dedicada; `master` só com DJEN).
- **HEAD no início da sessão:** `93445b0`. Etapa 7 (ponto seguro) = **2 commits** a seguir: implementação + docs vivos.
- **Etapa:** 7 (Importação em massa) → **PARCIAL**. Importador real **BLOQUEADO**; **ponto seguro CONCLUÍDO** (dedup de Pessoa por dígitos).
- **Suíte:** GLOBAL **1482/1482**; `tests/Cobranca` **201/201**.
- **Working tree:** limpo (só untracked `.claude/worktrees/` — worktrees de agente, NÃO commitar; limpeza é do humano, follow-up #6).
- **Escritor:** ÚNICO (orquestrador). Etapa 7 (ponto seguro) NÃO teve fan-out — mudança coesa e pequena.
- **Migrations (dev+test):** E1–E6 (ver EXECUTION_STATUS) + **E7 `Version20260710130000`** (índices FUNCIONAIS de dedup por dígitos de cpf/cnpj; SEM coluna nova). Já aplicada em dev e test via `doctrine:migrations:execute --up`.

## ⚠️ BLOQUEIO da Etapa 7 (o ponto central desta sessão)
O importador REAL **não pôde ser implementado**: **não existe fonte real de importação (relatórios da contabilidade) no repositório**. Verificado: nenhum `.xlsx/.csv/.xls/.ods` de cobrança versionado ou no working tree; as únicas planilhas são os CSVs de acervo/pastas em `tmp/acervo/` (colunas `nup;cliente;parte_contraria;acao` — domínio Pasta/Drive, gitignored, PII, **sem relação**). A SPEC §21 e o PLAN §9 **exigem** o relatório real (anonimizado) para definir adapter, mapeamento de colunas, chave de idempotência (`referenciaExterna`) e regras finas de dedup/reimportação. A tarefa proíbe **inventar** colunas/regras. Logo: importador **diferido** até a fonte chegar. Detalhe completo em `docs/specs/cobranca-etapa7-importacao.md`.

## O que foi concluído nesta sessão (Etapa 7 — ponto seguro, independe da fonte)
Único trabalho seguro e mandado por invariável (§23.24: CPF/CNPJ ajudam a evitar duplicidades **só intra-tenant**): fechar o núcleo do follow-up #3 (dedup por **dígitos**). Serve ao cadastro manual E ao futuro importador.
- **`App\Cobranca\Service\NormalizadorDocumento::apenasDigitos()`** — utilitário puro, ponto único de normalização (null/vazio→null).
- **`SugerirPessoasDuplicadasUseCase`** — normaliza o parâmetro CPF/CNPJ para dígitos (curto-circuito quando ambos ausentes).
- **`PessoaRepository::buscarPossiveisDuplicadas`** — **fronteira auto-defensiva**: normaliza o param E compara por dígitos via `regexp_replace(coalesce(cpf,''),'\D','','g')` em **SQL nativo** (RSM hidrata `Pessoa`), sempre `tenant_id = :tenant`. Assim `123.456.789-01` casa `12345678901`.
- **Migration `Version20260710130000`** — índices funcionais `(tenant_id, regexp_replace(...))` p/ cpf e cnpj. **Traz aviso de drift**: o próximo `migrations:diff` vai emitir `DROP INDEX` deles (não são mapeáveis por `#[ORM\Index]`) → remover o DROP à mão.
- **Testes:** `NormalizadorDocumentoTest` (8, DataProvider); unit da sugestão atualizado (param→dígitos + doc sem dígito); funcional `CobrancaIsolamentoTenantTest::testDedupCasaPorDigitosSemAtravessarTenant` (formatado×cru + cross-tenant).
- **NÃO tocado:** caminho de escrita (`CriarPessoaUseCase`), entidades do núcleo, `Pessoa.php` (nada de `referenciaExterna`, nada de importador universal §24).

## Revisão (feature-review-agent, read-only, adversarial)
**0 bloqueantes.** Achados tratados nesta sessão: **#1** drift de schema (aviso na migration + spec) e **#2** normalização na fronteira do repo (extraído `NormalizadorDocumento`, repo agora auto-defensivo). Aceitos/documentados: **#3** índices planos `idx_cobranca_pessoa_tenant_cpf`/`_cnpj` ficaram redundantes (não removidos p/ não criar drift reverso — cosmético); **#4** `\D` PCRE×Postgres só diverge p/ dígitos Unicode não-ASCII (irrelevante p/ CPF/CNPJ).

## Git
- **`master`:** NÃO contém Cobranças. Mergear DEPOIS do DJEN, só no fim.
- **Worktrees de agente (limpeza do humano):** dirs `.claude/worktrees/agent-*` + branches `worktree-agent-*` das etapas anteriores (follow-up #6). Nenhuma worktree nova nesta sessão.

## Testes (comandos úteis) — **sempre `php -d memory_limit=512M`**
- `php bin/phpunit tests/Cobranca` → 201/201.
- `php bin/phpunit --filter "NormalizadorDocumento|SugerirPessoasDuplicadas|CobrancaIsolamentoTenantTest"` → dedup por dígitos + cross-tenant.
- `php bin/phpunit` (global) → 1482/1482.
- **Se testes de DB falharem com erro de conexão:** `docker ps` — `jusprime_db_dev` pode ter parado (`docker start jusprime_db_dev`).

## Follow-ups (não bloqueiam — detalhe no EXECUTION_STATUS §Follow-ups)
- **#3** ✅ RESOLVIDO nesta sessão (dedup por dígitos). Resíduo cosmético: índices planos redundantes.
- **#15** Gate `can_access_module('cobrancas')` na camada HTTP → Etapa 8.
- **#16/#17** (E6) exclusão disco-antes-de-DB / cobertura de borda `descricao` — aceitos.
- **#10/#11** Endurecer testes de evento (asserir `tipo`+`dados`) — abertos das etapas 3/4.
- **Drift E7:** ao gerar a próxima migration por diff, remover à mão o `DROP INDEX idx_cobranca_pessoa_tenant_c*_digitos`.

## Próxima ação exata — DECISÃO DO HUMANO
A Etapa 7 (importador real) está BLOQUEADA. Duas frentes (ver EXECUTION_STATUS §Próxima ação):
- **Opção A — desbloquear E7:** humano fornece 1 relatório real **anonimizado** (`.xlsx`/`.csv`) em `app/tests/Fixtures/Cobranca/importacao/`. Só então construir o adapter da fonte (storytelling → colunas→conceitos → upload/parse/preview/confirmação/relatório → idempotência → cross-tenant).
- **Opção B (RECOMENDADA) — seguir para a Etapa 8 (Telas/UX), NÃO bloqueada:** menu gated, lista de carteiras → carteira → casos (filtro reutilizável) → detalhe do caso → formulários; controllers finos com guard permissão/tenant/IDOR/CSRF; religar file manager por `data-*`. A E7 volta quando a fonte real chegar.

## Ordem de retomada
1. Confirmar branch `gestao-cobrancas`, HEAD = os 2 commits da E7 (ou posterior), working tree limpo, escritor único.
2. `php bin/phpunit tests/Cobranca` deve dar 201/201.
3. Ler este handoff + EXECUTION_STATUS §"Próxima ação". **Perguntar ao humano: Opção A ou B?** (não escolher sozinho — é decisão de produto/negócio).
4. Seguir o AUTONOMOUS_EXECUTION_PROTOCOL para a frente escolhida.
