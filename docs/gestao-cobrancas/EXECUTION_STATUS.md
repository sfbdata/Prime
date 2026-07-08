# EXECUTION_STATUS — Gestão de Cobranças

> Panorama VIVO da implementação. Fonte de verdade do progresso. Atualizar após cada onda, integração relevante, conclusão de etapa, mudança de estratégia ou problema. **Confirmar sempre contra o Git/código — nunca contra memória de chat.**
> Última atualização: 2026-07-08.

---

## Panorama atual

| Campo | Valor real |
|---|---|
| **Etapa atual** | Etapa 1 — Núcleo de cadastro |
| **Onda atual** | Onda 2 (fan-out) — **parcial**: piloto de 2 UseCases concluído; faltam 5 UseCases |
| **Tarefa atual** | Nenhuma em execução (sessão em modo preparação de handoff) |
| **Último checkpoint estável** | `f6362f0` — suíte `tests/Cobranca` verde (4/38) |
| **Branch atual** | `gestao-cobrancas` (dedicada da feature; ver "Branch" abaixo) |
| **HEAD** | último commit de feature `f6362f0`; a branch inclui docs `85a0fde` + correção de referências de branch |
| **Working tree** | limpo |
| **Migration da Etapa 1** | `Version20260708210509` — migrated em **dev** e **test** |

### ✅ Branch (resolvido)
A feature vive na branch dedicada **`gestao-cobrancas`**, criada a partir de `b044c0c` (tip do `master`, que já inclui o módulo DJEN). O `master` **não** contém Cobranças. Os 8 commits da feature (`bc00414`→`85a0fde`) estão todos nesta branch. Resíduo menor: `gestao-cobrancas` carrega 2 commits DJEN-adjacentes (`6ffb820`, `b9de2b7`) ainda fora do master — chegam via `djen-deploy` e não afetam Cobranças. Nenhum rebase/reescrita pendente.

---

## Checklist completo (Etapas 0–9 do PLAN)

Marcadores: ✅ concluído · 🔄 em andamento · ⬜ pendente · ⚠️ atenção · ❌ bloqueado

### ✅ Etapa 0 — Fundação do domínio e do módulo
- ✅ Esqueleto `app/src/Cobranca/` (9 subpastas) · commit `475aeeb`
- ✅ Mapeamento Doctrine `AppCobranca` em `doctrine.yaml`
- ✅ Permissões `modules.cobrancas.view` + `resources.cobranca.gerenciar`, `resources.carteira.gerenciar`, `resources.cobranca.movimentacao_financeira` no `PermissionFixture`
- **Commits:** `475aeeb` (+ `bc00414` spec/plan, `d7f2687` mapa). **Testes:** N/A (sem entidades). **Problemas:** item de menu adiado p/ Etapa 8 (rota inexistente quebraria Twig); permissões em prod exigem migration no deploy (divergência F6).

### 🔄 Etapa 1 — Núcleo de cadastro: Carteira, Objeto, Pessoa, Vínculo
**Onda 1 — Andaime de contratos ✅ (commit `46afae5`)**
- ✅ 3 enums: `ModoCarteira`, `TipoVinculo`, `FormaHonorarios`
- ✅ 4 entidades: `Carteira`, `ObjetoCobranca`, `Pessoa`, `VinculoPessoaObjeto` (PK `int`, `TenantAware`+`Auditavel`)
- ✅ 4 repositórios-stub (`salvar`/`remover`/`findOneByIdDoTenant`)
- ✅ Migration `Version20260708210509` (4 tabelas `cobranca_*`) — aplicada dev+test
- ✅ 5 factories (`ClientePFFactory` + 4 de Cobrança)
- ✅ Revisão read-only sem bloqueantes; `RegraHonorarios` inline (sem embeddable, sancionado pelo PLAN)

**Onda 2 — Fan-out dos UseCases 🔄 (parcial — piloto)**
- ✅ `CriarCarteira` (UseCase + `CriarCarteiraInput` + `ClienteCredorNaoEncontradoException` + teste) · commit `454bbf2` · **2 testes/17 assert** · guard cross-tenant do credor
- ✅ `CriarPessoa` (UseCase + `CriarPessoaInput` + teste) · commit `f6362f0` · **2 testes/21 assert** · CPF/CNPJ opcionais
- ⬜ `EditarConfiguracaoCarteira` (modo, honorários padrão, tolerância, tipoVinculoPreferido, rotuloObjeto)
- ⬜ `CriarObjeto` (Objeto de Cobrança na carteira; `referenciaExterna`)
- ⬜ `SugerirPessoasDuplicadas` (dedup por CPF/CNPJ intra-tenant — advisory) → exige query no `PessoaRepository` + ⚠️ índice `(tenant_id, cpf)`/`(tenant_id, cnpj)` (migration do orquestrador)
- ⬜ `VincularPessoaAObjeto` (⚠️ **guard same-tenant obrigatório** entre pessoa/objeto/tenant — achado I1 da revisão do andaime)
- ⬜ `EncerrarVinculo` (dataFim + motivo, sem apagar; preserva histórico)

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
- ⬜ Índices de dedup (`cpf`/`cnpj`/`referencia_externa`) quando dedup/import exigirem
- ⬜ `tenant-safety-review` antes de mergear
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
7. **Sistema de handoff** (esta sessão): criados `EXECUTION_STATUS.md`, `SESSION_HANDOFF.md`, `AUTONOMOUS_EXECUTION_PROTOCOL.md`, `NEW_CHAT_PROMPT.md`.

### Decisões técnicas registradas
- PK das entidades = **`int`** (código real; skill `criar-entity` prescreve UUID, mas todo o projeto — inclusive Djen — usa int). FKs a Cliente/Pasta são int.
- Honorários = colunas inline (sem embeddable; projeto não usa embeddables).
- `Pessoa` sem `UniqueConstraint` de CPF/CNPJ (dedup é advisory, SPEC §7/§24).
- UseCase retorna a entidade (não Output DTO) — contrato da delegação; revisar política na Etapa 8 (controllers).

---

## Próxima ação exata

> **Retomar a Onda 2 da Etapa 1**, implementando os 5 UseCases restantes via fan-out (protocolo em `AUTONOMOUS_EXECUTION_PROTOCOL.md`). Divisão sugerida (áreas de arquivo disjuntas, cada UseCase = 1 commit autocontido):
> - **Frente Carteira/Objeto:** `EditarConfiguracaoCarteira` + `CriarObjeto`.
> - **Frente Pessoa/Vínculo:** `SugerirPessoasDuplicadas` (add query em `PessoaRepository`), `VincularPessoaAObjeto` (**com guard same-tenant** entre pessoa/objeto/tenant), `EncerrarVinculo`.
>
> Passos concretos: (1) para cada UseCase, criar Input DTO + UseCase + teste unitário seguindo o padrão de `CriarCarteiraUseCase`/`CriarPessoaUseCase`; (2) commit por implementador na worktree; (3) `feature-review-agent` por commit; (4) `git cherry-pick <hash>` individual; (5) `php bin/phpunit --filter <NomeDoTeste>` no container após cada integração; (6) ao fim, escrever o **teste cross-tenant real do vínculo** e rodar `php bin/phpunit tests/Cobranca`; (7) rodar `tenant-safety-review`; (8) apresentar commit final da Etapa 1 ao humano.
>
> **Antes de mexer em índices de dedup:** eu (orquestrador) adiciono uma migration de índice `(tenant_id, cpf)` / `(tenant_id, cnpj)` em `cobranca_pessoa` quando `SugerirPessoasDuplicadas` for implementado — cluster NÃO toca migration.
