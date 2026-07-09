# EXECUTION_STATUS — Gestão de Cobranças

> Panorama VIVO da implementação. Fonte de verdade do progresso. Atualizar após cada onda, integração relevante, conclusão de etapa, mudança de estratégia ou problema. **Confirmar sempre contra o Git/código — nunca contra memória de chat.**
> Última atualização: 2026-07-08 (Etapa 1 CONCLUÍDA).

---

## Panorama atual

| Campo | Valor real |
|---|---|
| **Etapa atual** | Etapa 1 — Núcleo de cadastro → **✅ CONCLUÍDA**; próxima = Etapa 2 |
| **Onda atual** | — (Etapa 1 fechada: Ondas 1–3 completas) |
| **Tarefa atual** | Nenhuma em execução |
| **Último checkpoint estável** | `7019010` — **suíte GLOBAL verde 1303/1303 (3507 assert)**; `tests/Cobranca` verde |
| **Branch atual** | `gestao-cobrancas` (dedicada da feature) |
| **HEAD** | `7019010` |
| **Working tree** | limpo (só untracked `.claude/worktrees/` — worktrees de agente, não commitar) |
| **Migrations aplicadas (dev+test)** | `Version20260708210509` (4 tabelas) + `Version20260708220000` (índices dedup Pessoa) |

> ⚠️ **Sessão colaborativa (humano + orquestrador na MESMA branch).** Durante esta sessão o humano
> `sfbdata` co-editou a branch em tempo real: criou o commit de índices (`ab996f9`) e depois um commit
> de **revisão/consolidação** de toda a Onda 2 (`7019010`). Como a identidade git da sessão é `sfbdata`,
> os commits do orquestrador e os do humano aparecem com o MESMO autor — distinguir só pela mensagem/escopo.
> O orquestrador PAROU de escrever nos arquivos de Cobrança ao detectar a edição humana concorrente
> (regra "escritor único por arquivo"). Próxima sessão: reconfirmar com o humano antes de reabrir escrita.

### Commits da Etapa 1 (sobre `beef54c`)
- `ab996f9` — **(humano)** índices de dedup `(tenant_id, cpf)`/`(tenant_id, cnpj)` em `cobranca_pessoa` (migration `Version20260708220000` + `#[ORM\Index]` em `Pessoa`). Aplicado em dev+test.
- `aca727c` — Frente A: `EditarConfiguracaoCarteira` + `CriarObjeto` (cherry-pick de `25971de`, worktree do orquestrador).
- `f2f6a42` — Frente B: `SugerirPessoasDuplicadas` + `VincularPessoaAObjeto` + `EncerrarVinculo` (cherry-pick de `8d26d9d`, worktree do orquestrador).
- `6fda56c` — Onda 3 (orquestrador): teste cross-tenant real do vínculo (DB) + hardening tenant no `findBy` da dedup.
- `92c48f3` — transversal (orquestrador): cobrir as 4 tabelas `cobranca_*` na purga de escritório (`PurgarEscritorioUseCase`) + seed de Cobrança no teste da purga.
- `7019010` — **(humano)** "Integrar UseCases revisados da Onda 2 + teste cross-tenant": revisão/limpeza de todo o domínio (21 arqs, −69 linhas) e **reconciliação da dedup** para **match EXATO** (`p.cpf = :cpf`/`p.cnpj = :cnpj` via QueryBuilder), alinhando a query aos índices btree criados em `ab996f9`. Substitui a abordagem por dígitos normalizados (SQL nativo) que o orquestrador tinha integrado. Suíte global verde 1303/1303.

### Decisão de design da dedup (convergida humano + orquestrador)
No MVP a dedup de Pessoa faz **match EXATO** sobre o documento armazenado (CPF/CNPJ), usando os índices `(tenant_id, cpf)`/`(tenant_id, cnpj)`. A normalização por dígitos (casar `123.456.789-01` com `12345678901`) é **follow-up documentado para a Etapa 7 (importação)**, quando o dado real define as regras de dedup (SPEC §21). O isolamento por tenant é garantido em ambas as abordagens (query escopada por `p.tenant`).

### ✅ Branch (resolvido)
A feature vive na branch dedicada **`gestao-cobrancas`**, criada a partir de `b044c0c` (tip do `master`, que já inclui o módulo DJEN). O `master` **não** contém Cobranças. Resíduo menor: `gestao-cobrancas` carrega 2 commits DJEN-adjacentes (`6ffb820`, `b9de2b7`) ainda fora do master — chegam via `djen-deploy` e não afetam Cobranças. Nenhum rebase/reescrita pendente.

---

## Checklist completo (Etapas 0–9 do PLAN)

Marcadores: ✅ concluído · 🔄 em andamento · ⬜ pendente · ⚠️ atenção · ❌ bloqueado

### ✅ Etapa 0 — Fundação do domínio e do módulo
- ✅ Esqueleto `app/src/Cobranca/` (9 subpastas) · commit `475aeeb`
- ✅ Mapeamento Doctrine `AppCobranca` em `doctrine.yaml`
- ✅ Permissões `modules.cobrancas.view` + `resources.cobranca.gerenciar`, `resources.carteira.gerenciar`, `resources.cobranca.movimentacao_financeira` no `PermissionFixture`
- **Commits:** `475aeeb` (+ `bc00414` spec/plan, `d7f2687` mapa). **Testes:** N/A (sem entidades). **Problemas:** item de menu adiado p/ Etapa 8 (rota inexistente quebraria Twig); permissões em prod exigem migration no deploy (divergência F6).

### ✅ Etapa 1 — Núcleo de cadastro: Carteira, Objeto, Pessoa, Vínculo — CONCLUÍDA
**Onda 1 — Andaime de contratos ✅ (commit `46afae5`)**
- ✅ 3 enums: `ModoCarteira`, `TipoVinculo`, `FormaHonorarios`
- ✅ 4 entidades: `Carteira`, `ObjetoCobranca`, `Pessoa`, `VinculoPessoaObjeto` (PK `int`, `TenantAware`+`Auditavel`)
- ✅ 4 repositórios-stub (`salvar`/`remover`/`findOneByIdDoTenant`)
- ✅ Migration `Version20260708210509` (4 tabelas `cobranca_*`) — aplicada dev+test
- ✅ 5 factories (`ClientePFFactory` + 4 de Cobrança)
- ✅ Revisão read-only sem bloqueantes; `RegraHonorarios` inline (sem embeddable, sancionado pelo PLAN)

**Onda 2 — Fan-out dos 7 UseCases ✅ COMPLETA**
- ✅ `CriarCarteira` (`454bbf2`) — guard cross-tenant do credor
- ✅ `CriarPessoa` (`f6362f0`) — CPF/CNPJ opcionais
- ✅ `EditarConfiguracaoCarteira` (`aca727c`, Frente A) — edita config; credor imutável; `CarteiraNaoEncontradaException`
- ✅ `CriarObjeto` (`aca727c`, Frente A) — objeto herda tenant da carteira validada
- ✅ `SugerirPessoasDuplicadas` (`f2f6a42`→reconciliado em `7019010`) — dedup advisory intra-tenant, **match exato** index-friendly
- ✅ `VincularPessoaAObjeto` (`f2f6a42`, Frente B) — **guard same-tenant** (pessoa+objeto por id+tenant); nasce aberto
- ✅ `EncerrarVinculo` (`f2f6a42`, Frente B) — dataFim+motivo, não apaga; rejeita reencerramento (`VinculoJaEncerradoException`)
- Fan-out: 2 `feature-implementer` em worktree (`25971de` Frente A, `8d26d9d` Frente B) → 2 `feature-review-agent` (APROVADO c/ ressalvas menores) → cherry-pick individual → teste direcionado. Piloto+onda validados.

**Onda 3 — Integração/validação ✅ COMPLETA**
- ✅ Teste cross-tenant REAL do vínculo (DB): `app/tests/Cobranca/Functional/CobrancaIsolamentoTenantTest.php` — vínculo same-tenant, rejeição de pessoa/objeto de outro tenant, encerramento tenant-scoped, dedup que não atravessa tenant (`6fda56c`, ajustado em `7019010`).
- ✅ `tenant-safety-review` do domínio → **SEM ACHADOS GRAVES** (todo read-by-id resolve por id+tenant; sem `find()` por PK; guard same-tenant no vínculo; SQL/DQL escopado; associações validadas). Hardening menor aplicado e depois consolidado pelo humano em `7019010`.
- ✅ Cobertura da purga de escritório: 4 tabelas `cobranca_*` na `ORDEM_DELECAO` (FK-safe: vínculo→objeto→carteira→pessoa, antes de `cliente`) + seed no teste da purga (`92c48f3`). `PurgaCoberturaSchemaTest` verde.
- ✅ Suíte GLOBAL verde: **1303/1303 (3507 assert)** contra HEAD `7019010`.
- ⚠️ Resíduo: a dedup por dígitos normalizados foi trocada por match exato (decisão do humano, index-friendly); normalização vira follow-up da Etapa 7 (importação).

**Onda 3 — Integração/validação da Etapa 1 ⬜**
- ⬜ Teste **cross-tenant "de verdade"** do vínculo (fixtures com mesmo tenant nas 3 pontas, depois divergir uma → provar rejeição; NÃO confiar nos defaults das factories)
- ⬜ `tenant-safety-review` do domínio antes de fechar a etapa
- ⬜ Suíte completa verde + commit final da Etapa 1

### ⬜ Etapa 2 — Caso de Cobrança, Obrigações e Saldo derivado
- ⬜ Entidades `CasoCobranca`, `Obrigacao`, `EventoHistorico` + enum `StatusCaso`; serviço `CalculadoraSaldo`; UseCases `AbrirCaso`, `RegistrarObrigacao` (modo A/B), `ReconhecerValorAtualizado`, `RegistrarTentativaCobranca`, `AlterarPessoaCobrada`. Paralelização baixa (núcleo acoplado).

### ⬜ Etapa 3 — Pagamentos, Liquidações e Honorários
- ⬜ `Pagamento`(+`AlocacaoPagamento`), `Liquidacao`, `CalculadoraHonorarios` (4 formas, rateio proporcional); `RegistrarPagamento`, `CorrigirPagamento` (SEM estorno), `RegistrarLiquidacao`.

### ⬜ Etapa 4 — Acordos
- ⬜ `Acordo` + `StatusAcordo`; `CriarAcordo`/`RomperAcordo`/`CancelarAcordo`/`MarcarCumprido`.

### ⬜ Etapa 5 — Estados/Judicialização/Encerramento/Ações/Alertas
- ⬜ Transições `StatusCaso`; `Judicializar` (vincula `Pasta`); `EncerrarCaso`; `ProximaAcao`; `RevisaoPessoaCobrada`; serviço `AlertasCobranca`. Paralelização alta (3 agentes).

### ⬜ Etapa 6 — Documentos do Caso
- ⬜ `CobrancaDocumento`/`CobrancaSecao` + `cobrancas_uploads_dir`; reuso de `ArquivoStorageService` + file manager. Trilha paralela candidata a 3/4/5.

### ⬜ Etapa 7 — Importação em massa
- ⬜ Importador da fonte específica; preview→confirmação→relatório; dedup intra-tenant; reimport idempotente.

### ⬜ Etapa 8 — Telas operacionais + UX
- ⬜ Menu `Cobranças`; lista de carteiras → visão da carteira → lista de casos (filtro reutilizável) → detalhe do caso → formulários; badges/tooltips/tema. Paralelização alta (3–4 agentes).

### ⬜ Etapa 9 — Alertas na UI + Dashboard
- ⬜ Central de alertas + dashboard (§20).

### Transversal / deploy (fim)
- ⬜ Data-migration de permissões `cobrancas` p/ **produção** (dev/test já feitos)
- ✅ Índices de dedup `(tenant_id, cpf)`/`(tenant_id, cnpj)` (migration `Version20260708220000`, dev+test)
- ⬜ Índice `referencia_externa` quando a importação (Etapa 7) exigir
- ✅ `tenant-safety-review` do domínio (Etapa 1) — SEM ACHADOS GRAVES. Repetir antes de mergear no fim.
- ⬜ Deploy via `deploy-prod-tls.sh` (rebuild) — só no fim
- ✅ Branch dedicada `gestao-cobrancas` (resolvido — ver Panorama)

---

## Histórico da execução (cronológico)

1. **Spec + Plano** (`bc00414`): SPEC final revisada (estorno removido; centavos inteiros; módulo próprio; "pronto para encerrar" derivado) + `PLAN.md` (10 etapas).
2. **Mapa de paralelização** (`d7f2687`, detalhado em `5ba564c`).
3. **Etapa 0** (`475aeeb`): esqueleto, mapeamento Doctrine, permissões. Validado (cache:clear, mapping:info).
4. **Etapa 1 / Onda 1 — andaime** (`46afae5`): enums, entidades, repos, migration (aplicada dev+test via `migrations:execute --up`, evitando as migrations-fantasma do Ponto no dev), factories. `feature-review-agent` read-only → sem bloqueantes; corrigido boilerplate da migration (M2).
5. **Infra de fan-out autônomo** (`228c294`, commit do humano): `.claude/settings.json` `worktree.baseRef=head`; `block-git-writes.py` atualizado (permite add/commit/cherry-pick de 1 commit; bloqueia push/merge/rebase/reset); agente `feature-implementer`; workflow skill.
6. **Piloto de fan-out (subconjunto da Onda 2):**
   - 2× `feature-implementer` em worktrees isoladas, em paralelo → A `CriarCarteira` (worktree `761ffd1`), B `CriarPessoa` (worktree `b42f11b`).
   - 2× `feature-review-agent` read-only → ambos APROVADO (ressalvas menores).
   - Cherry-pick individual, serial: A `761ffd1`→`454bbf2` (teste 2/17 ✅) → B `b42f11b`→`f6362f0` (teste 2/21 ✅).
   - Suíte `tests/Cobranca` 4/38 ✅; diff consolidado 7 arquivos/400 inserções, nenhum contrato congelado alterado.
   - **Falha de infra (resolvida):** hook bloqueou `cherry-pick … 2>&1` (redirecionamento vira 2º arg) → regra: cherry-pick recebe **só o hash**.
   - Worktrees do piloto removidas (branches `worktree-agent-*` remanescentes → limpeza do humano).
   - **Veredito:** pipeline autônomo APROVADO (ver `AUTONOMOUS_EXECUTION_PROTOCOL.md`).
7. **Sistema de handoff** (sessão anterior): criados `EXECUTION_STATUS.md`, `SESSION_HANDOFF.md`, `AUTONOMOUS_EXECUTION_PROTOCOL.md`, `NEW_CHAT_PROMPT.md`.
8. **Fechamento da Etapa 1 (esta sessão, autônoma):**
   - Fan-out dos 5 UseCases restantes: 2 `feature-implementer` (worktree) `25971de` (Frente A) e `8d26d9d` (Frente B) → 2 `feature-review-agent` (APROVADO) → cherry-pick individual `aca727c`/`f2f6a42` → testes direcionados verdes (A 5/35, B 11/54).
   - Onda 3: teste cross-tenant real (DB) + hardening dedup (`6fda56c`); cobertura da purga (`92c48f3`); `tenant-safety-review` SEM ACHADOS GRAVES; suíte global 1306/1306 (na versão do orquestrador).
   - **Co-edição do humano em tempo real:** `ab996f9` (índices) e `7019010` (revisão/consolidação de toda a Onda 2 + reconciliação da dedup para match exato index-friendly). Suíte global reverificada verde **1303/1303** no HEAD `7019010`. Orquestrador parou de escrever em arquivos de Cobrança ao detectar a co-edição.

### Decisões técnicas registradas
- PK das entidades = **`int`** (código real; skill `criar-entity` prescreve UUID, mas todo o projeto — inclusive Djen — usa int). FKs a Cliente/Pasta são int.
- Honorários = colunas inline (sem embeddable; projeto não usa embeddables).
- `Pessoa` sem `UniqueConstraint` de CPF/CNPJ (dedup é advisory, SPEC §7/§24).
- UseCase retorna a entidade (não Output DTO) — contrato da delegação; revisar política na Etapa 8 (controllers).
- **Dedup = match EXATO** sobre o documento armazenado (decisão do humano em `7019010`, alinhada aos índices `ab996f9`). Normalização por dígitos = follow-up da Etapa 7 (importação, SPEC §21).
- **Purga de escritório** cobre as 4 tabelas `cobranca_*` (ordem FK-safe vínculo→objeto→carteira→pessoa, antes de `cliente`) — `PurgarEscritorioUseCase::ORDEM_DELECAO`.

---

## Próxima ação exata

> **Etapa 1 está CONCLUÍDA e verde (HEAD `7019010`, suíte global 1303/1303).** A próxima etapa é a **Etapa 2 — Caso de Cobrança, Obrigações e Saldo derivado** (PLAN §8).
>
> **⚠️ ANTES de reabrir escrita:** confirmar com o humano `sfbdata` que ele encerrou a co-edição da branch (ele committou `ab996f9` e `7019010` durante a última sessão). Não iniciar fan-out enquanto houver edição humana concorrente na mesma branch (regra "escritor único por arquivo"). Reconfirmar o HEAD e `git status` limpo no início da próxima sessão.
>
> **Escopo da Etapa 2** (paralelização BAIXA — 1–2 agentes; núcleo acoplado): entidades `CasoCobranca` (status, pessoa cobrada atual, snapshot de honorários, `pastaJudicial` nullable), `Obrigacao`, `EventoHistorico`; enum `StatusCaso`; serviço `RegistrarEventoHistorico`; serviço `CalculadoraSaldo` (saldo exigível/vencido/consolidado — dependência de quase tudo adiante). UseCases: `AbrirCaso` (define pessoa cobrada; aplica modo A/B da carteira), `RegistrarObrigacao` (modo A→caso ativo; modo B→escolhe caso), `ReconhecerValorAtualizado` (encargos, sem nova obrigação), `RegistrarTentativaCobranca` (boleto/novo prazo → só `EventoHistorico`), `AlterarPessoaCobrada` (motivo+histórico, sem efeito em dívida/pagamentos/acordos).
>
> **Sequência (fluxo do CLAUDE.md):** storytelling de cada UseCase (skill `criar-usecase`) → teste → implementação. Andaime committado ANTES de qualquer fan-out. Migration nova cria `cobranca_caso`, `cobranca_obrigacao`, `cobranca_evento_historico` (aplicar dev+test via `migrations:execute --up`, nunca `fixtures:load`). Ao adicionar as novas tabelas: **lembrar de cobri-las na purga** (`PurgarEscritorioUseCase::ORDEM_DELECAO`) — senão `PurgaCoberturaSchemaTest` falha (guard anti-drift). Testes: cenários de saldo (parcial, encargos, substituição), modo A vs B, `EventoHistorico` nos eventos certos, cross-tenant.
>
> Ao terminar a etapa: suíte global verde + `tenant-safety-review` + atualizar estes docs.
